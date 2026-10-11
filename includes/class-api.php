<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * API connections (Class Manager → API connections): Stripe, the two Zoom accounts and Twilio (texts).
 *
 * Security:
 *  - Keys are typed in by the site owner on this page only — never in code, never in chat.
 *  - Every secret is encrypted at rest with libsodium (XSalsa20-Poly1305). The encryption key comes from
 *    SDSC_SECRET_KEY when it is defined in wp-config.php, otherwise from the WordPress auth salts, which
 *    also live in wp-config.php. A copy of the database alone therefore cannot reveal a key.
 *  - A saved secret is never printed back into the page; only its last 4 characters are shown.
 *  - Saving requires the administrator capability, HTTPS, a nonce and the user's own WordPress password.
 *  - Every change and connection test is written to a log (who, when, which field — never the value).
 */
class SDSC_Api {

	const OPT = 'sdsc_api';
	const LOG = 'sdsc_api_log';
	const CAP = 'sdsc_manage_api';

	/** field => array( group, label, format check ) */
	public static function fields() {
		$f = array(
			'stripe_test_key'   => array( 'stripe', 'Test restricted key', '/^(rk|sk)_test_[A-Za-z0-9]{20,}$/' ),
			'stripe_test_whsec' => array( 'stripe', 'Test webhook signing secret', '/^whsec_[A-Za-z0-9+\/=]{20,}$/' ),
			'stripe_live_key'   => array( 'stripe', 'Live restricted key', '/^(rk|sk)_live_[A-Za-z0-9]{20,}$/' ),
			'stripe_live_whsec' => array( 'stripe', 'Live webhook signing secret', '/^whsec_[A-Za-z0-9+\/=]{20,}$/' ),
		);
		foreach ( array( 1, 2 ) as $n ) {
			$f[ "zoom{$n}_account" ] = array( "zoom{$n}", 'Account ID', '/^[A-Za-z0-9_\-]{8,64}$/' );
			$f[ "zoom{$n}_client" ]  = array( "zoom{$n}", 'Client ID', '/^[A-Za-z0-9_\-]{8,64}$/' );
			$f[ "zoom{$n}_secret" ]  = array( "zoom{$n}", 'Client secret', '/^[A-Za-z0-9_\-]{8,128}$/' );
		}
		$f['twilio_sid']   = array( 'twilio', 'Account SID', '/^AC[a-f0-9]{32}$/' );
		$f['twilio_token'] = array( 'twilio', 'Auth Token', '/^[A-Za-z0-9]{32}$/' );
		$f['twilio_from']  = array( 'twilio', 'Sender (Twilio number like +13015551234, or Messaging Service SID)', '/^(\+[1-9]\d{9,14}|MG[a-f0-9]{32})$/' );
		return $f;
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'grant_caps' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_sdsc_api_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sdsc_api_test', array( __CLASS__, 'test' ) );
	}

	/** Administrators get the capability; it can be removed from a user with any role editor. */
	public static function grant_caps() {
		$r = get_role( 'administrator' );
		if ( $r && ! $r->has_cap( self::CAP ) && ! get_option( 'sdsc_api_caps_done' ) ) {
			$r->add_cap( self::CAP );
			update_option( 'sdsc_api_caps_done', 1 );
		}
	}

	public static function can() {
		return current_user_can( self::CAP );
	}

	/* ------------------------------ storage ------------------------------ */

	protected static function data() {
		$d = get_option( self::OPT, array() );
		$d = is_array( $d ) ? $d : array();
		return wp_parse_args( $d, array( 'mode' => 'test', 's' => array(), 'tests' => array(), 'zoom_labels' => array() ) );
	}

	protected static function put( $d ) {
		update_option( self::OPT, $d, false ); // never autoloaded
	}

	protected static function key() {
		$seed = ( defined( 'SDSC_SECRET_KEY' ) && SDSC_SECRET_KEY ) ? SDSC_SECRET_KEY : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( 'sdsc-api|' . $seed, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	protected static function enc( $plain ) {
		$n = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $n . sodium_crypto_secretbox( (string) $plain, $n, self::key() ) );
	}

	protected static function dec( $blob ) {
		if ( ! is_string( $blob ) || 0 !== strpos( $blob, 'v1:' ) ) { return ''; }
		$raw = base64_decode( substr( $blob, 3 ), true );
		$nb  = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( false === $raw || strlen( $raw ) <= $nb + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) { return ''; }
		$p = sodium_crypto_secretbox_open( substr( $raw, $nb ), substr( $raw, 0, $nb ), self::key() );
		return false === $p ? '' : $p;
	}

	/* ------------------------------ public getters (for the Stripe / Zoom modules) ------------------------------ */

	public static function mode() {
		return 'live' === self::data()['mode'] ? 'live' : 'test';
	}

	public static function get( $field ) {
		$s = self::data()['s'];
		return isset( $s[ $field ]['v'] ) ? self::dec( $s[ $field ]['v'] ) : '';
	}

	public static function stripe_key() {
		return self::get( 'stripe_' . self::mode() . '_key' );
	}

	public static function stripe_webhook_secret() {
		return self::get( 'stripe_' . self::mode() . '_whsec' );
	}

	/** Zoom account $n (1 or 2): account id, client id, secret, label. Empty array when not complete. */
	public static function zoom( $n ) {
		$a = array( 'account' => self::get( "zoom{$n}_account" ), 'client' => self::get( "zoom{$n}_client" ), 'secret' => self::get( "zoom{$n}_secret" ) );
		if ( in_array( '', $a, true ) ) { return array(); }
		$a['label'] = self::zoom_label( $n );
		return $a;
	}

	public static function zoom_label( $n ) {
		$l = class_exists( 'SDSC_Settings' ) ? SDSC_Settings::get_zoom_accounts() : array();
		return isset( $l[ 'account_' . $n ] ) ? $l[ 'account_' . $n ] : 'Zoom account ' . $n;
	}

	/** Used once to move a key that was saved by the old settings page into encrypted storage. */
	public static function import( $field, $value ) {
		if ( ! isset( self::fields()[ $field ] ) || '' === trim( (string) $value ) ) { return; }
		$d = self::data();
		if ( ! empty( $d['s'][ $field ]['v'] ) ) { return; }
		$d['s'][ $field ] = array( 'v' => self::enc( trim( $value ) ), 'last4' => substr( trim( $value ), -4 ), 'by' => 0, 'at' => time() );
		self::put( $d );
		self::log( 'Moved ' . self::fields()[ $field ][1] . ' (' . $field . ') from the old settings page into encrypted storage' );
	}

	/* ------------------------------ log ------------------------------ */

	protected static function log( $msg ) {
		$l = get_option( self::LOG, array() );
		$l = is_array( $l ) ? $l : array();
		$u = wp_get_current_user();
		array_unshift( $l, array( 't' => time(), 'u' => $u && $u->ID ? $u->display_name : 'system', 'm' => $msg ) );
		update_option( self::LOG, array_slice( $l, 0, 40 ), false );
	}

	protected static function flash( $type, $msg ) {
		set_transient( 'sdsc_api_msg_' . get_current_user_id(), array( $type, $msg ), 120 );
	}

	/* ------------------------------ save ------------------------------ */

	public static function save() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_api_save' );
		$back = admin_url( 'admin.php?page=sdsc-api' );
		if ( ! is_ssl() ) { self::flash( 'error', 'Keys can only be saved over a secure (https) connection.' ); wp_safe_redirect( $back ); exit; }
		$u  = wp_get_current_user();
		$pw = isset( $_POST['sdsc_pw'] ) ? (string) wp_unslash( $_POST['sdsc_pw'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $pw || ! wp_check_password( $pw, $u->user_pass, $u->ID ) ) {
			self::log( 'Save refused: wrong WordPress password' );
			self::flash( 'error', 'Nothing was saved: your WordPress password was not correct.' );
			wp_safe_redirect( $back ); exit;
		}
		$d       = self::data();
		$in      = isset( $_POST['k'] ) && is_array( $_POST['k'] ) ? wp_unslash( $_POST['k'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$del     = isset( $_POST['del'] ) && is_array( $_POST['del'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['del'] ) ) ) : array();
		$changed = array();
		$bad     = array();
		foreach ( self::fields() as $f => $meta ) {
			if ( in_array( $f, $del, true ) && isset( $d['s'][ $f ] ) ) {
				unset( $d['s'][ $f ] );
				$changed[] = 'removed ' . $meta[1] . ' (' . $meta[0] . ')';
				continue;
			}
			$v = isset( $in[ $f ] ) ? trim( (string) $in[ $f ] ) : '';
			if ( '' === $v ) { continue; }
			if ( ! preg_match( $meta[2], $v ) ) { $bad[] = ucfirst( $meta[0] ) . ' — ' . $meta[1]; continue; }
			$d['s'][ $f ] = array( 'v' => self::enc( $v ), 'last4' => substr( $v, -4 ), 'by' => $u->ID, 'at' => time() );
			$changed[] = 'set ' . $meta[1] . ' (' . $meta[0] . ', ending ' . substr( $v, -4 ) . ')';
		}
		$mode = isset( $_POST['mode'] ) && 'live' === $_POST['mode'] ? 'live' : 'test';
		if ( $mode !== $d['mode'] ) {
			if ( 'live' === $mode && empty( $d['s']['stripe_live_key'] ) ) {
				$bad[] = 'Stripe live mode needs a live key first';
			} else {
				$d['mode'] = $mode;
				$changed[] = 'Stripe mode → ' . $mode;
			}
		}
		self::put( $d );
		foreach ( $changed as $c ) { self::log( ucfirst( $c ) ); }
		if ( $bad ) {
			self::flash( 'error', 'Not saved (the value does not look right, please copy it again): ' . implode( '; ', $bad ) . ( $changed ? '. Everything else was saved.' : '.' ) );
		} else {
			self::flash( 'success', $changed ? 'Saved: ' . implode( '; ', $changed ) . '.' : 'Nothing changed.' );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ------------------------------ connection tests ------------------------------ */

	public static function test() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_api_test' );
		$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : '';
		$r     = 'stripe' === $which ? self::test_stripe() : ( in_array( $which, array( 'zoom1', 'zoom2' ), true ) ? self::test_zoom( (int) substr( $which, -1 ) ) : ( 'twilio' === $which && class_exists( 'SDSC_SMS' ) ? SDSC_SMS::test() : array( false, 'Unknown test.' ) ) );
		$d     = self::data();
		$d['tests'][ $which ] = array( 'ok' => $r[0], 'msg' => $r[1], 'at' => time() );
		self::put( $d );
		self::log( 'Tested ' . $which . ': ' . ( $r[0] ? 'OK' : 'failed' ) );
		self::flash( $r[0] ? 'success' : 'error', $r[1] );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-api' ) );
		exit;
	}

	public static function test_stripe() {
		$key = self::stripe_key();
		if ( '' === $key ) { return array( false, 'Stripe (' . self::mode() . ' mode): no key saved yet.' ); }
		$res = wp_remote_get( 'https://api.stripe.com/v1/payment_links?limit=1', array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $key ) ) );
		if ( is_wp_error( $res ) ) { return array( false, 'Could not reach Stripe: ' . $res->get_error_message() ); }
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) { return array( true, 'Stripe connected in ' . self::mode() . ' mode.' ); }
		if ( 401 === $code ) { return array( false, 'Stripe refused the key (401). Copy it again from the Stripe dashboard.' ); }
		if ( 403 === $code ) { return array( false, 'The key works but is missing a permission (403). Give it Write access to Products, Prices, Payment Links and Checkout Sessions.' ); }
		return array( false, 'Stripe answered with an error (' . $code . ').' );
	}

	public static function test_zoom( $n ) {
		$z = self::zoom( $n );
		if ( ! $z ) { return array( false, self::zoom_label( $n ) . ': Account ID, Client ID and Client secret are all needed.' ); }
		$tok = wp_remote_post( 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode( $z['account'] ), array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $z['client'] . ':' . $z['secret'] ) ),
		) );
		if ( is_wp_error( $tok ) ) { return array( false, 'Could not reach Zoom: ' . $tok->get_error_message() ); }
		$body = json_decode( wp_remote_retrieve_body( $tok ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $tok ) || empty( $body['access_token'] ) ) {
			$why = trim( (string) ( $body['reason'] ?? '' ) . ( ! empty( $body['error'] ) ? ' (' . $body['error'] . ')' : '' ) );
			return array( false, self::zoom_label( $n ) . ': Zoom did not accept these credentials' . ( $why ? ' — Zoom says: ' . $why : '' ) . '. Check that the app is a Server-to-Server OAuth app, that it has scopes and is activated, and that all three values come from the same app.' );
		}
		$me = wp_remote_get( 'https://api.zoom.us/v2/users/me', array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ) ) );
		$mj = is_wp_error( $me ) ? array() : json_decode( wp_remote_retrieve_body( $me ), true );
		if ( ! empty( $mj['email'] ) ) { return array( true, self::zoom_label( $n ) . ' connected (' . $mj['email'] . ').' ); }
		return array( true, self::zoom_label( $n ) . ' connected. (Add the user:read scope to see the account email here.)' );
	}

	/* ------------------------------ page ------------------------------ */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'API connections', 'API connections', self::CAP, 'sdsc-api', array( __CLASS__, 'page' ) );
	}

	protected static function status( $d, $f ) {
		if ( empty( $d['s'][ $f ]['v'] ) ) { return '<span style="color:#8c8f94">Not set</span>'; }
		$s   = $d['s'][ $f ];
		$ok  = '' !== self::dec( $s['v'] );
		$who = ! empty( $s['by'] ) && ( $usr = get_userdata( $s['by'] ) ) ? $usr->display_name : '—';
		if ( ! $ok ) { return '<span style="color:#b32d2e">Saved but cannot be read any more (the site keys changed). Please enter it again.</span>'; }
		return '<span style="color:#00653a;font-weight:600">&#9679; Saved</span> <code>••••' . esc_html( $s['last4'] ) . '</code> <span style="color:#646970">by ' . esc_html( $who ) . ', ' . esc_html( date_i18n( 'M j, Y g:i a', $s['at'] ) ) . '</span>';
	}

	protected static function row( $d, $f ) {
		$m = self::fields()[ $f ];
		echo '<tr><th scope="row"><label for="sdsc-' . esc_attr( $f ) . '">' . esc_html( $m[1] ) . '</label></th><td>';
		echo '<p style="margin:0 0 6px">' . self::status( $d, $f ) . '</p>'; // phpcs:ignore -- built from escaped parts
		echo '<input type="password" id="sdsc-' . esc_attr( $f ) . '" name="k[' . esc_attr( $f ) . ']" value="" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="' . ( empty( $d['s'][ $f ] ) ? 'Paste the value' : 'Paste a new value to replace it' ) . '" />';
		if ( ! empty( $d['s'][ $f ] ) ) { echo ' <label style="margin-left:8px"><input type="checkbox" name="del[' . esc_attr( $f ) . ']" value="1" /> Remove</label>'; }
		echo '</td></tr>';
	}

	protected static function test_button( $which, $label, $d ) {
		$t = $d['tests'][ $which ] ?? null;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 10px 0 0">
			<?php wp_nonce_field( 'sdsc_api_test' ); ?>
			<input type="hidden" name="action" value="sdsc_api_test" /><input type="hidden" name="which" value="<?php echo esc_attr( $which ); ?>" />
			<button class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php if ( $t ) : ?><span style="color:<?php echo $t['ok'] ? '#00653a' : '#b32d2e'; ?>"><?php echo esc_html( ( $t['ok'] ? '✓ ' : '✗ ' ) . $t['msg'] . ' (' . human_time_diff( $t['at'] ) . ' ago)' ); ?></span><?php endif;
	}

	public static function page() {
		if ( ! self::can() ) { return; }
		$d   = self::data();
		$msg = get_transient( 'sdsc_api_msg_' . get_current_user_id() );
		if ( $msg ) { delete_transient( 'sdsc_api_msg_' . get_current_user_id() ); }
		$log = get_option( self::LOG, array() );
		?>
		<div class="wrap">
			<h1>API connections</h1>
			<?php if ( $msg ) : ?><div class="notice notice-<?php echo 'success' === $msg[0] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div><?php endif; ?>
			<?php if ( ! is_ssl() ) : ?><div class="notice notice-error"><p>This page is not on a secure (https) connection, so keys cannot be saved here.</p></div><?php endif; ?>
			<div style="max-width:860px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #00653a;padding:10px 14px;margin:14px 0">
				<p style="margin:.3em 0"><strong>How your keys are kept safe.</strong> Each key is encrypted before it is stored, and it is never shown again on this page — only its last 4 characters. Saving needs your WordPress password. Only administrators can open this page, and every change is recorded in the log at the bottom (without the key itself).</p>
				<p style="margin:.3em 0;color:#50575e">Encryption key: <?php echo ( defined( 'SDSC_SECRET_KEY' ) && SDSC_SECRET_KEY ) ? 'the dedicated <code>SDSC_SECRET_KEY</code> in wp-config.php.' : 'the WordPress security keys in wp-config.php. If those keys are ever changed, the saved API keys must be entered again.'; ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<?php wp_nonce_field( 'sdsc_api_save' ); ?>
				<input type="hidden" name="action" value="sdsc_api_save" />

				<h2>Stripe</h2>
				<p style="max-width:860px">Create a <strong>restricted key</strong> in Stripe (Developers → API keys → Create restricted key) with <em>Write</em> access to Products, Prices, Payment Links, Checkout Sessions and Refunds, and <em>Read</em> access to Charges and PaymentIntents (needed to find a student&rsquo;s payment and refund it). Use the test-mode key while we build and test; switch to live only when everything works.</p>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Mode</th><td>
						<label><input type="radio" name="mode" value="test" <?php checked( 'test', $d['mode'] ); ?> /> Test — no real money moves</label><br>
						<label><input type="radio" name="mode" value="live" <?php checked( 'live', $d['mode'] ); ?> /> Live — real payments</label>
					</td></tr>
					<?php foreach ( array( 'stripe_test_key', 'stripe_test_whsec', 'stripe_live_key', 'stripe_live_whsec' ) as $f ) { self::row( $d, $f ); } ?>
					<tr><th scope="row">Webhook address</th><td><code><?php echo esc_html( rest_url( 'sdsc/v1/stripe' ) ); ?></code><p class="description">Used in the next step, when payments are confirmed automatically. You will add this address in Stripe → Developers → Webhooks and paste its signing secret above.</p></td></tr>
				</table>

				<?php foreach ( array( 1, 2 ) as $n ) : ?>
					<h2><?php echo esc_html( self::zoom_label( $n ) ); ?> <span style="font-weight:400;color:#646970">(Zoom account <?php echo (int) $n; ?>)</span></h2>
					<?php if ( 1 === $n ) : ?><p style="max-width:860px">In the Zoom App Marketplace, create a <strong>Server-to-Server OAuth</strong> app on each Zoom account (an account admin is needed), give it the scopes to create, update and delete meetings and to read users, activate it, and copy its three values here.</p><?php endif; ?>
					<table class="form-table" role="presentation">
						<?php foreach ( array( 'account', 'client', 'secret' ) as $p ) { self::row( $d, "zoom{$n}_{$p}" ); } ?>
					</table>
				<?php endforeach; ?>

				<h2>Twilio <span style="font-weight:400;color:#646970">(text messages)</span></h2>
				<p style="max-width:860px">In the Twilio Console, copy the <strong>Account SID</strong> and <strong>Auth Token</strong> from the account dashboard, and the sending number (+1…) or a Messaging Service SID (MG…). Texts to US numbers from a regular 10-digit number need the A2P 10DLC brand and campaign registration in Twilio to be approved first, otherwise carriers block them.</p>
				<table class="form-table" role="presentation">
					<?php foreach ( array( 'twilio_sid', 'twilio_token', 'twilio_from' ) as $f ) { self::row( $d, $f ); } ?>
					<tr><th scope="row">Status address</th><td><code><?php echo esc_html( rest_url( 'sdsc/v1/twilio' ) ); ?></code><p class="description">Sent with every text automatically, so Twilio reports back whether it was delivered. Nothing to set up.</p></td></tr>
				</table>

				<h2>Confirm</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="sdsc-pw">Your WordPress password</label></th><td><input type="password" id="sdsc-pw" name="sdsc_pw" class="regular-text" autocomplete="current-password" required /><p class="description">Needed every time keys are saved, so nobody can change them from an unattended computer.</p></td></tr>
				</table>
				<?php submit_button( 'Save API settings' ); ?>
			</form>

			<h2>Test the connections</h2>
			<p><?php self::test_button( 'stripe', 'Test Stripe (' . self::mode() . ' mode)', $d ); ?></p>
			<p><?php self::test_button( 'zoom1', 'Test ' . self::zoom_label( 1 ), $d ); ?></p>
			<p><?php self::test_button( 'zoom2', 'Test ' . self::zoom_label( 2 ), $d ); ?></p>
			<p><?php self::test_button( 'twilio', 'Test Twilio (sends nothing)', $d ); ?></p>

			<h2>Change log</h2>
			<?php if ( ! $log ) : ?><p>No changes yet.</p><?php else : ?>
				<table class="widefat striped" style="max-width:860px"><thead><tr><th style="width:170px">When</th><th style="width:140px">Who</th><th>What</th></tr></thead><tbody>
				<?php foreach ( array_slice( $log, 0, 20 ) as $e ) : ?><tr><td><?php echo esc_html( date_i18n( 'M j, Y g:i a', $e['t'] ) ); ?></td><td><?php echo esc_html( $e['u'] ); ?></td><td><?php echo esc_html( $e['m'] ); ?></td></tr><?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
