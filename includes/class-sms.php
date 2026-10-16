<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Text messages through Twilio.
 *
 *  - The Account SID, Auth Token and sender (a Twilio number or a Messaging Service) are typed in on
 *    Class Manager → API connections and stored encrypted (SDSC_Api).
 *  - SDSC_SMS::send( $to, $body, $ctx ) sends one text and writes it to the log {prefix}sdsc_sms.
 *  - Twilio calls /wp-json/sdsc/v1/twilio back with the delivery status; the call is checked with
 *    Twilio's signature (X-Twilio-Signature) before anything is stored.
 *  - "STOP", "HELP" and "START" replies are handled by Twilio itself (Advanced Opt-Out); a number that
 *    replied STOP is refused by Twilio (error 21610) and the log shows it.
 *  - Nothing is texted until "Send texts automatically" is switched on in the Text messages screen.
 */
class SDSC_SMS {
	const DBV  = '1';
	const DBO  = 'sdsc_sms_db';
	const SET  = 'sdsc_sms_set';
	const PAGE = 'sdsc-sms';

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_sms'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 21 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 33 );
		add_action( 'admin_post_sdsc_sms_set', array( __CLASS__, 'h_set' ) );
		add_action( 'admin_post_sdsc_sms_test', array( __CLASS__, 'h_test' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			to_num varchar(20) NOT NULL DEFAULT '',
			body text NULL,
			ctx varchar(60) NOT NULL DEFAULT '',
			sid varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			err varchar(255) NOT NULL DEFAULT '',
			by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY sid (sid),
			KEY to_num (to_num)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::SET, array() ), array(
			'auto'    => 0,   // send the automatic texts (lesson confirmations, reminders, fees)
			'lessons' => 1,
		) );
	}

	/* ------------------------------------------------------------ credentials */

	public static function creds() {
		if ( ! class_exists( 'SDSC_Api' ) ) { return array(); }
		$c = array( 'sid' => SDSC_Api::get( 'twilio_sid' ), 'token' => SDSC_Api::get( 'twilio_token' ), 'from' => SDSC_Api::get( 'twilio_from' ) );
		return in_array( '', $c, true ) ? array() : $c;
	}

	/** Twilio is connected (keys saved). */
	public static function connected() { return (bool) self::creds(); }

	/** Automatic texts may go out. */
	public static function ready() { return self::connected() && self::settings()['auto']; }

	/* ------------------------------------------------------------ sending */

	/** "+13015551234" from anything that looks like a US/Canada number (or an international one with +). */
	public static function e164( $p ) {
		if ( class_exists( 'SDSC_Outreach' ) ) { return SDSC_Outreach::phone_e164( $p ); }
		$d = preg_replace( '/\D+/', '', (string) $p );
		if ( 10 === strlen( $d ) ) { return '+1' . $d; }
		if ( 11 === strlen( $d ) && '1' === $d[0] ) { return '+' . $d; }
		return '';
	}

	/**
	 * Send one text. Returns array( ok, message ).
	 * $ctx is a short label for the log, e.g. "lesson:12:confirm".
	 */
	public static function send( $to, $body, $ctx = '' ) {
		if ( class_exists( 'SDSC_Hold' ) && SDSC_Hold::block_sms( $to, $body ) ) { return array( false, 'Held — notifications are on hold.' ); }
		global $wpdb;
		$c  = self::creds();
		$to = self::e164( $to );
		if ( ! $c ) { return array( false, 'Twilio is not connected.' ); }
		if ( ! $to ) { return array( false, 'Not a valid mobile number.' ); }
		$body = trim( wp_strip_all_tags( (string) $body ) );
		if ( '' === $body ) { return array( false, 'Empty message.' ); }
		$args = array( 'To' => $to, 'Body' => $body, 'StatusCallback' => rest_url( 'sdsc/v1/twilio' ) );
		if ( 0 === strpos( $c['from'], 'MG' ) ) { $args['MessagingServiceSid'] = $c['from']; } else { $args['From'] = $c['from']; }
		$wpdb->insert( self::table(), array( 'created_at' => current_time( 'mysql' ), 'to_num' => $to, 'body' => $body, 'ctx' => substr( $ctx, 0, 60 ), 'status' => 'sending', 'by_user' => get_current_user_id() ) );
		$row = (int) $wpdb->insert_id;
		$res = wp_remote_post( 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $c['sid'] ) . '/Messages.json', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $c['sid'] . ':' . $c['token'] ) ),
			'body'    => $args,
		) );
		if ( is_wp_error( $res ) ) {
			$wpdb->update( self::table(), array( 'status' => 'failed', 'err' => substr( $res->get_error_message(), 0, 255 ) ), array( 'id' => $row ) );
			return array( false, 'Could not reach Twilio: ' . $res->get_error_message() );
		}
		$j    = json_decode( wp_remote_retrieve_body( $res ), true );
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 && ! empty( $j['sid'] ) ) {
			$wpdb->update( self::table(), array( 'sid' => $j['sid'], 'status' => $j['status'] ?? 'queued' ), array( 'id' => $row ) );
			return array( true, 'Text sent to ' . $to . '.' );
		}
		$err = trim( ( isset( $j['code'] ) ? $j['code'] . ': ' : '' ) . ( $j['message'] ?? 'HTTP ' . $code ) );
		$wpdb->update( self::table(), array( 'status' => 'failed', 'err' => substr( $err, 0, 255 ) ), array( 'id' => $row ) );
		return array( false, 'Twilio refused the text — ' . $err );
	}

	/**
	 * Send a text the best way available: through Twilio when it is connected and switched on,
	 * otherwise into "Texts to send" for the staff to send from the school phone (free).
	 */
	public static function deliver( $phone, $body, $ctx, $name = '', $label = '', $expires = null ) {
		if ( class_exists( 'SDSC_Hold' ) && SDSC_Hold::block_sms( $phone, $body ) ) { return 'held — notifications are on hold (not sent)'; }
		if ( self::ready() ) {
			$r = self::send( $phone, $body, $ctx );
			return $r[1];
		}
		if ( class_exists( 'SDSC_TextQ' ) && SDSC_TextQ::add( $phone, $body, $ctx, $name, $label, $expires ) ) {
			return 'added to “Texts to send”';
		}
		return 'not texted (no valid mobile number, or already in the list)';
	}

	/** Connection check for the API connections page: reads the account and checks the sender. Sends nothing. */
	public static function test() {
		$c = self::creds();
		if ( ! $c ) { return array( false, 'Twilio: Account SID, Auth Token and the sender are all needed.' ); }
		$auth = array( 'Authorization' => 'Basic ' . base64_encode( $c['sid'] . ':' . $c['token'] ) );
		$res  = wp_remote_get( 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $c['sid'] ) . '.json', array( 'timeout' => 15, 'headers' => $auth ) );
		if ( is_wp_error( $res ) ) { return array( false, 'Could not reach Twilio: ' . $res->get_error_message() ); }
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array( false, 'Twilio did not accept the Account SID / Auth Token' . ( ! empty( $j['message'] ) ? ' — ' . $j['message'] : '' ) . '.' );
		}
		$acct = ( $j['friendly_name'] ?? 'account' ) . ' (' . ( $j['status'] ?? '?' ) . ( 'Trial' === ( $j['type'] ?? '' ) ? ', trial — texts only reach verified numbers' : '' ) . ')';
		if ( 0 === strpos( $c['from'], 'MG' ) ) {
			$r = wp_remote_get( 'https://messaging.twilio.com/v1/Services/' . rawurlencode( $c['from'] ), array( 'timeout' => 15, 'headers' => $auth ) );
			$ok = ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
			return $ok ? array( true, 'Twilio connected: ' . $acct . ', Messaging Service found.' ) : array( false, 'Twilio account works, but the Messaging Service SID was not found in it.' );
		}
		$r  = wp_remote_get( add_query_arg( 'PhoneNumber', rawurlencode( $c['from'] ), 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $c['sid'] ) . '/IncomingPhoneNumbers.json' ), array( 'timeout' => 15, 'headers' => $auth ) );
		$jj = is_wp_error( $r ) ? array() : json_decode( wp_remote_retrieve_body( $r ), true );
		if ( empty( $jj['incoming_phone_numbers'] ) ) { return array( false, 'Twilio account works, but ' . $c['from'] . ' is not a number in this account.' ); }
		return array( true, 'Twilio connected: ' . $acct . ', sending from ' . $c['from'] . '.' );
	}

	/* ------------------------------------------------------------ delivery status */

	public static function routes() {
		register_rest_route( 'sdsc/v1', '/twilio', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'status_cb' ),
		) );
	}

	/** Twilio's request signature: base64(HMAC-SHA1(url + sorted key/value pairs, auth token)). */
	protected static function signed( WP_REST_Request $r ) {
		$c   = self::creds();
		$sig = (string) $r->get_header( 'x_twilio_signature' );
		if ( ! $c || '' === $sig ) { return false; }
		$p = $r->get_body_params();
		ksort( $p );
		$s = rest_url( 'sdsc/v1/twilio' );
		foreach ( $p as $k => $v ) { $s .= $k . $v; }
		return hash_equals( base64_encode( hash_hmac( 'sha1', $s, $c['token'], true ) ), $sig );
	}

	public static function status_cb( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! self::signed( $r ) ) { return new WP_REST_Response( array( 'ok' => false ), 403 ); }
		$sid = sanitize_text_field( (string) $r->get_param( 'MessageSid' ) );
		$st  = sanitize_key( (string) $r->get_param( 'MessageStatus' ) );
		$ec  = sanitize_text_field( (string) $r->get_param( 'ErrorCode' ) );
		if ( $sid && $st ) {
			$data = array( 'status' => $st, 'updated_at' => current_time( 'mysql' ) );
			if ( $ec ) { $data['err'] = 'Error ' . $ec . ( '21610' === $ec ? ' — the student replied STOP' : '' ); }
			$wpdb->update( self::table(), $data, array( 'sid' => $sid ) );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ------------------------------------------------------------ screen */

	public static function can() {
		return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'sms' ) : current_user_can( 'manage_options' );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Text messages', 'Text messages', 'sdsc_access_sms', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function page() {
		global $wpdb;
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		wp_enqueue_style( 'sdsc-outreach', SDSC_URL . 'assets/outreach.css', array(), SDSC_VERSION );
		$s    = self::settings();
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 100', ARRAY_A ); // phpcs:ignore
		?>
		<div class="wrap sdo">
			<h1 class="sdo-title"><span class="sdo-title-ic" aria-hidden="true">💬</span> Text messages</h1>
			<?php if ( isset( $_GET['msg'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['err'] ) ) : // phpcs:ignore ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<?php if ( ! self::connected() ) : ?>
				<div class="sdo-hold"><b>Twilio is not connected yet.</b> The administrator enters the Account SID, Auth Token and the sending number on <?php echo current_user_can( 'sdsc_manage_api' ) ? '<a href="' . esc_url( admin_url( 'admin.php?page=sdsc-api' ) ) . '">API connections</a>' : 'API connections'; ?>. Until then the &ldquo;Text&rdquo; buttons keep opening your phone&rsquo;s Messages app.</div>
			<?php endif; ?>
			<div class="sdo-two">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdo-panel sdo-settings">
					<?php wp_nonce_field( 'sdsc_sms' ); ?><input type="hidden" name="action" value="sdsc_sms_set">
					<h2>Automatic texts</h2>
					<label class="sdo-check"><input type="checkbox" name="auto" value="1"<?php checked( $s['auto'] ); ?><?php disabled( ! self::connected() ); ?>> Send texts automatically through Twilio</label>
					<label class="sdo-check"><input type="checkbox" name="lessons" value="1"<?php checked( $s['lessons'] ); ?>> Behind-the-wheel lessons: confirmation, reminder, fee and waiver texts</label>
					<p class="sdo-note">Students can reply STOP at any time; Twilio then stops texting that number by itself.</p>
					<p><button class="sdo-btn">Save</button></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdo-panel sdo-settings">
					<?php wp_nonce_field( 'sdsc_sms' ); ?><input type="hidden" name="action" value="sdsc_sms_test">
					<h2>Send a test text</h2>
					<label class="sdo-f"><span>Your own mobile number</span><input type="tel" name="to" required placeholder="(301) 555-0123"></label>
					<label class="sdo-f sdo-f--wide"><span>Message</span><input type="text" name="body" value="Test from Sam’s Driving School website." maxlength="300"></label>
					<p><button class="sdo-btn sdo-btn--ghost"<?php disabled( ! self::connected() ); ?>>Send test</button></p>
				</form>
			</div>
			<h2>Last 100 texts</h2>
			<?php if ( ! $rows ) : ?><p class="sdo-empty">Nothing sent yet.</p><?php else : ?>
				<table class="widefat striped">
					<thead><tr><th style="width:140px">When</th><th style="width:130px">To</th><th>Message</th><th style="width:120px">Status</th><th style="width:120px">About</th></tr></thead>
					<tbody><?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', strtotime( get_gmt_from_date( $r['created_at'] ) ) ) ); ?></td>
							<td><?php echo esc_html( class_exists( 'SDSC_Outreach' ) ? SDSC_Outreach::phone_nice( $r['to_num'] ) : $r['to_num'] ); ?></td>
							<td><?php echo esc_html( $r['body'] ); ?><?php if ( $r['err'] ) : ?><br><span class="sdo-warn"><?php echo esc_html( $r['err'] ); ?></span><?php endif; ?></td>
							<td><?php echo esc_html( ucfirst( $r['status'] ) ); ?></td>
							<td class="sdo-muted"><?php echo esc_html( $r['ctx'] ); ?></td>
						</tr>
					<?php endforeach; ?></tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function h_set() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_sms' );
		update_option( self::SET, array( 'auto' => empty( $_POST['auto'] ) ? 0 : 1, 'lessons' => empty( $_POST['lessons'] ) ? 0 : 1 ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&msg=' . rawurlencode( 'Saved.' ) ) );
		exit;
	}

	public static function h_test() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_sms' );
		$r = self::send( sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['body'] ?? '' ) ), 'test' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&' . ( $r[0] ? 'msg' : 'err' ) . '=' . rawurlencode( $r[1] ) ) );
		exit;
	}
}
