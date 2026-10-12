<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Sign-up blocklist: stops known spam addresses and throwaway domains from creating accounts
 * through every registration form on the site (WordPress, Tutor LMS, the login/sign-up popup, WooCommerce).
 *
 * - Exact emails are compared Gmail-aware: dots and "+tags" are ignored, so k.uv.e.x.e@gmail.com
 *   and kuvexe+1@gmail.com count as the same address.
 * - Gmail addresses written with 4 or more dots (a common bot trick) are refused.
 * - Admin page: Class Manager → Blocked sign-ups (edit the list, see refused attempts).
 */
class SDSC_Guard {

	const OPT = 'sdsc_blocklist';
	const LOG = 'sdsc_blocklist_log';
	const MSG = 'Registration with this email address isn’t possible. Please call us at (301) 726-3030 and we’ll set you up.';

	public static function init() {
		add_filter( 'registration_errors', array( __CLASS__, 'wp_errors' ), 20, 3 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'wc_errors' ), 20, 4 );
		add_filter( 'xoo_el_process_registration_errors', array( __CLASS__, 'wc_errors' ), 20, 4 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_sdsc_blocklist_save', array( __CLASS__, 'save' ) );
		// Honeypot ("bot trap"): hidden fields real visitors never see.
		add_action( 'init', array( __CLASS__, 'trap_check' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'trap_page' ), 0 );
		foreach ( array( 'register_form', 'woocommerce_register_form', 'tutor_student_reg_form_end', 'tutor_instructor_reg_form_end', 'xoo_el_register_form_end', 'xoo_el_form_end', 'comment_form_logged_in_after', 'comment_form_after_fields' ) as $h ) {
			add_action( $h, array( __CLASS__, 'trap_fields' ) );
		}
		add_action( 'wp_footer', array( __CLASS__, 'trap_js' ), 99 );
	}

	/* ------------------------------------------------------------------ *
	 * Bot trap (honeypot)
	 *
	 * Every sign-up form gets an invisible "I'm not a robot" checkbox and an invisible text box.
	 * They are moved off-screen, hidden from screen readers (aria-hidden) and skipped by the keyboard
	 * (tabindex -1), so a real person never meets them. Bots fill in every field they find: when
	 * either field comes back filled, the sign-up is refused, the email is added to the blocklist,
	 * and the bot is sent to an empty dead-end page with no links or forms.
	 * ------------------------------------------------------------------ */

	const HP_BOX  = 'sdsc_hp_human';
	const HP_TEXT = 'sdsc_hp_url';

	public static function trap_fields() {
		static $done = 0;
		if ( $done++ > 3 ) { return; }
		echo '<div class="sdsc-hpot" aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
			. '<label>I\'m not a robot <input type="checkbox" name="' . self::HP_BOX . '" value="1" tabindex="-1" autocomplete="off"></label>'
			. '<label>Your website <input type="text" name="' . self::HP_TEXT . '" value="" tabindex="-1" autocomplete="off"></label></div>';
	}

	/** Adds the same hidden fields to sign-up forms built by JavaScript (pop-ups) and to any form with a password box. */
	public static function trap_js() {
		if ( is_user_logged_in() ) { return; }
		?>
		<script>(function(){var B=<?php echo wp_json_encode( self::HP_BOX ); ?>,T=<?php echo wp_json_encode( self::HP_TEXT ); ?>;function add(f){if(f.querySelector('[name="'+B+'"]'))return;var d=document.createElement('div');d.className='sdsc-hpot';d.setAttribute('aria-hidden','true');d.style.cssText='position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;';d.innerHTML='<label>I\'m not a robot <input type="checkbox" name="'+B+'" value="1" tabindex="-1" autocomplete="off"></label><label>Your website <input type="text" name="'+T+'" value="" tabindex="-1" autocomplete="off"></label>';f.appendChild(d);}function scan(){[].forEach.call(document.querySelectorAll('form'),function(f){if(f.querySelector('input[type=password],input[name*=email i],input[type=email]'))add(f);});}scan();if(window.MutationObserver){new MutationObserver(function(){clearTimeout(scan.t);scan.t=setTimeout(scan,300);}).observe(document.body,{childList:true,subtree:true});}})();</script>
		<?php
	}

	public static function trap_check() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || is_user_logged_in() ) { return; } // phpcs:ignore
		$hit = ! empty( $_POST[ self::HP_BOX ] ) || ( isset( $_POST[ self::HP_TEXT ] ) && '' !== trim( (string) wp_unslash( $_POST[ self::HP_TEXT ] ) ) ) // phpcs:ignore
			|| ( isset( $_POST['sdsc_website'] ) && '' !== trim( (string) wp_unslash( $_POST['sdsc_website'] ) ) ); // phpcs:ignore
		if ( ! $hit ) { return; }
		$email = '';
		foreach ( array( 'user_email', 'email', 'xoo_el_reg_email', 'billing_email', 'sdsc_email' ) as $k ) {
			if ( ! empty( $_POST[ $k ] ) ) { $email = sanitize_email( wp_unslash( $_POST[ $k ] ) ); break; } // phpcs:ignore
		}
		$ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
		self::log( $email ? $email : '(no email)', 'bot trap' . ( $ip ? ' · IP ' . $ip : '' ) );
		if ( $email ) { self::add( array( $email ), array() ); } // block this exact address (Gmail look-alikes included)
		$url = add_query_arg( 'sdsc-hold', wp_generate_password( 10, false, false ), home_url( '/' ) );
		if ( wp_doing_ajax() || ( isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT'], 'json' ) ) ) { // phpcs:ignore
			wp_send_json( array( 'error' => 1, 'redirect' => $url, 'notice' => '' ), 200 );
		}
		wp_redirect( $url, 303 );
		exit;
	}

	/** The dead-end page: nothing to click, nothing to submit, not indexed. */
	public static function trap_page() {
		if ( empty( $_GET['sdsc-hold'] ) ) { return; } // phpcs:ignore
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		status_header( 200 );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Please wait</title>'
			. '<style>body{margin:0;display:grid;place-items:center;height:100vh;font:16px system-ui,sans-serif;color:#555;background:#f4f4f4}i{display:block;width:34px;height:34px;margin:0 auto 14px;border:3px solid #ccc;border-top-color:#777;border-radius:50%;animation:r 1s linear infinite}@keyframes r{to{transform:rotate(360deg)}}</style>'
			. '</head><body><div><i></i>Processing your request…</div></body></html>';
		exit;
	}

	public static function lists() {
		$o = get_option( self::OPT, array() );
		$o = is_array( $o ) ? $o : array();
		return array(
			'emails'  => array_values( array_filter( (array) ( $o['emails'] ?? array() ) ) ),
			'domains' => array_values( array_filter( (array) ( $o['domains'] ?? array() ) ) ),
		);
	}

	/** Lower-case; for Gmail drop dots and +tags so look-alike addresses match. */
	public static function norm( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( false === strpos( $email, '@' ) ) { return $email; }
		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( 'googlemail.com' === $domain ) { $domain = 'gmail.com'; }
		if ( 'gmail.com' === $domain ) {
			$local = str_replace( '.', '', preg_replace( '/\+.*/', '', $local ) );
		}
		return $local . '@' . $domain;
	}

	/** Reason the address is refused, or '' when it is fine. */
	public static function reason( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || false === strpos( $email, '@' ) ) { return ''; }
		list( $local, $domain ) = explode( '@', $email, 2 );
		$l = self::lists();
		foreach ( $l['domains'] as $d ) {
			$d = ltrim( strtolower( trim( $d ) ), '@.' );
			if ( $d && ( $domain === $d || substr( $domain, -strlen( '.' . $d ) ) === '.' . $d ) ) { return 'domain ' . $d; }
		}
		$n = self::norm( $email );
		foreach ( $l['emails'] as $e ) {
			if ( self::norm( $e ) === $n ) { return 'blocked address'; }
		}
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) && substr_count( preg_replace( '/\+.*/', '', $local ), '.' ) >= 4 ) {
			return 'gmail dot trick';
		}
		return '';
	}

	protected static function refuse( $err, $email ) {
		$why = self::reason( $email );
		if ( ! $why ) { return $err; }
		self::log( $email, $why );
		if ( is_wp_error( $err ) ) { $err->add( 'sdsc_blocked', self::MSG ); }
		return $err;
	}

	public static function wp_errors( $err, $login = '', $email = '' ) {
		// Tutor LMS calls this filter with empty values; its form posts the address as "email".
		if ( '' === (string) $email && isset( $_POST['email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$email = sanitize_email( wp_unslash( $_POST['email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( '' === (string) $email && isset( $_POST['user_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$email = sanitize_email( wp_unslash( $_POST['user_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return self::refuse( $err, $email );
	}

	public static function wc_errors( $err, $username = '', $password = '', $email = '' ) {
		return self::refuse( $err, $email );
	}

	protected static function log( $email, $why ) {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, array( 't' => current_time( 'mysql' ), 'email' => sanitize_text_field( $email ), 'why' => $why ) );
		update_option( self::LOG, array_slice( $log, 0, 100 ), false );
	}

	/** Adds addresses/domains to the list (used when deleting spam accounts). */
	public static function add( $emails = array(), $domains = array() ) {
		$l = self::lists();
		$l['emails']  = array_values( array_unique( array_merge( $l['emails'], array_map( 'strtolower', array_map( 'trim', $emails ) ) ) ) );
		$l['domains'] = array_values( array_unique( array_merge( $l['domains'], array_map( 'strtolower', array_map( 'trim', $domains ) ) ) ) );
		update_option( self::OPT, $l, false );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Blocked sign-ups', 'Blocked sign-ups', 'sdsc_access_blocklist', 'sdsc-blocklist', array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! SDSC_Access::can( 'blocklist' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_blocklist' );
		$split = function ( $k ) {
			$v = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
			return array_values( array_unique( array_filter( array_map( function ( $x ) { return strtolower( trim( $x ) ); }, preg_split( '/[\s,]+/', $v ) ) ) ) );
		};
		update_option( self::OPT, array( 'emails' => $split( 'emails' ), 'domains' => $split( 'domains' ) ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-blocklist&saved=1' ) );
		exit;
	}

	public static function page() {
		if ( ! SDSC_Access::can( 'blocklist' ) ) { return; }
		$l   = self::lists();
		$log = get_option( self::LOG, array() );
		?>
		<div class="wrap">
			<h1>Blocked sign-ups</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?><div class="notice notice-success"><p>Saved.</p></div><?php endif; ?>
			<p>Addresses and domains here cannot create an account on any form of the website (account sign-up, online course, sign-up pop-up). Gmail look-alikes (extra dots or “+” tags) are caught too, and Gmail addresses with 4 or more dots are refused automatically. Anyone refused is asked to call the school.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sdsc_blocklist_save" />
				<?php wp_nonce_field( 'sdsc_blocklist' ); ?>
				<table class="form-table">
					<tr><th><label for="sdsc-bl-e">Email addresses</label><br><small><?php echo count( $l['emails'] ); ?> blocked</small></th><td><textarea id="sdsc-bl-e" name="emails" rows="10" class="large-text code"><?php echo esc_textarea( implode( "\n", $l['emails'] ) ); ?></textarea><p class="description">One per line.</p></td></tr>
					<tr><th><label for="sdsc-bl-d">Domains</label><br><small><?php echo count( $l['domains'] ); ?> blocked</small></th><td><textarea id="sdsc-bl-d" name="domains" rows="6" class="large-text code"><?php echo esc_textarea( implode( "\n", $l['domains'] ) ); ?></textarea><p class="description">One per line, e.g. <code>ak6lay.shop</code>. Never add gmail.com, yahoo.com or similar — real students use them.</p></td></tr>
				</table>
				<?php submit_button( 'Save list' ); ?>
			</form>
			<h2>Refused attempts</h2>
			<?php if ( ! $log ) : ?>
				<p>None yet.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:800px"><thead><tr><th>When</th><th>Email</th><th>Reason</th></tr></thead><tbody>
				<?php foreach ( $log as $r ) : ?>
					<tr><td><?php echo esc_html( $r['t'] ); ?></td><td><?php echo esc_html( $r['email'] ); ?></td><td><?php echo esc_html( $r['why'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
