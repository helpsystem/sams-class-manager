<?php
/**
 * SDSC_TwoFactor — instructors sign in with their password AND a 6-digit code e-mailed to their account address.
 * After the password is accepted, the login stops at a "Enter your code" screen (wp-login.php?action=sdsc2fa).
 * Codes: 6 digits, valid 10 minutes, 5 wrong tries then the sign-in must start again, a new code at most every 60 s (3 per sign-in).
 * The e-mail subject says "login verification code", so the Notifications hold never blocks it.
 * Office screen: Class Manager → Sign-in codes (switch, which roles, per-person skip, log).
 */
defined( 'ABSPATH' ) || exit;

class SDSC_TwoFactor {
	const OPT = 'sdsc_2fa';
	const LOG = 'sdsc_2fa_log';
	const TTL = 600;

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'gate' ), 60, 3 );
		add_action( 'login_form_sdsc2fa', array( __CLASS__, 'page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 43 );
		add_action( 'admin_post_sdsc_2fa', array( __CLASS__, 'save' ) );
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), array(
			'on'    => 1,
			'roles' => array( 'tutor_instructor', 'driveflow_instructor', 'sdsc_instructor' ),
			'skip'  => array(),
		) );
	}

	public static function covered( $user ) {
		$s = self::settings();
		if ( ! $s['on'] || ! ( $user instanceof WP_User ) ) { return false; }
		if ( in_array( (int) $user->ID, array_map( 'intval', (array) $s['skip'] ), true ) ) { return false; }
		return (bool) array_intersect( (array) $user->roles, (array) $s['roles'] );
	}

	protected static function log( $user, $what ) {
		$l = get_option( self::LOG, array() );
		$l = is_array( $l ) ? $l : array();
		array_unshift( $l, array( 't' => current_time( 'mysql' ), 'u' => $user ? $user->user_login : '', 'e' => $what ) );
		update_option( self::LOG, array_slice( $l, 0, 100 ), false );
	}

	protected static function mask( $email ) {
		$p = explode( '@', (string) $email, 2 );
		return ( isset( $p[1] ) ? substr( $p[0], 0, 1 ) . '***@' . $p[1] : '***' );
	}

	protected static function mail( $user, $code ) {
		$f   = 'font-family:Arial,Helvetica,sans-serif;';
		$h   = '<p style="' . $f . 'margin:0 0 12px;font-size:15px;line-height:1.6;color:#15191d">Hi ' . esc_html( $user->first_name ?: $user->display_name ) . ', here is your sign-in code for Sam’s Driving School:</p>'
			. '<p style="' . $f . 'margin:0 0 16px;font-size:34px;letter-spacing:8px;font-weight:700;color:#0b5d2e;text-align:center">' . esc_html( $code ) . '</p>'
			. '<p style="' . $f . 'margin:0 0 10px;font-size:14px;line-height:1.6;color:#15191d">It works for 10 minutes and only once. <b>Never share it</b> — the school will never ask you for it.</p>'
			. '<p style="' . $f . 'margin:0;font-size:13px;line-height:1.6;color:#555">Did not try to sign in? Please change your password and call the office at (301) 726-3030.</p>';
		$sub = 'Your login verification code — Sam’s Driving School';
		$hdr = array( 'Content-Type: text/html; charset=UTF-8' );
		return class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::send( $user->user_email, $sub, $h, 'info', $hdr ) : wp_mail( $user->user_email, $sub, $h, $hdr );
	}

	/** Start a sign-in: make and e-mail a code. Returns the key for the code screen, or WP_Error. */
	public static function begin( $user, $remember = false, $redirect = '' ) {
		$rk = 'sdsc2fa_rate_' . (int) $user->ID;
		$n  = (int) get_transient( $rk );
		if ( $n >= 6 ) { return new WP_Error( 'sdsc_2fa', 'Too many codes were requested. Please wait 15 minutes, or call the office at (301) 726-3030.' ); }
		set_transient( $rk, $n + 1, 15 * MINUTE_IN_SECONDS );
		$key  = wp_generate_password( 32, false );
		$code = sprintf( '%06d', random_int( 0, 999999 ) );
		if ( ! self::mail( $user, $code ) ) {
			self::log( $user, 'e-mail failed' );
			return new WP_Error( 'sdsc_2fa', 'We could not send your sign-in code. Please call the office at (301) 726-3030.' );
		}
		set_transient( 'sdsc2fa_' . $key, array( 'uid' => (int) $user->ID, 'h' => wp_hash( $code . '|' . $key ), 'tries' => 0, 'sends' => 1, 'at' => time(), 'rem' => (bool) $remember, 'to' => (string) $redirect ), self::TTL );
		self::log( $user, 'code sent' );
		return $key;
	}

	/** Check a code. Returns array( uid, remember, redirect ) or WP_Error. */
	public static function verify( $key, $code ) {
		$p = get_transient( 'sdsc2fa_' . $key );
		if ( ! is_array( $p ) ) { return new WP_Error( 'sdsc_2fa', 'This code has expired. Please sign in again.' ); }
		$user = get_userdata( $p['uid'] );
		$code = preg_replace( '/\D+/', '', (string) $code );
		if ( 6 === strlen( $code ) && hash_equals( $p['h'], wp_hash( $code . '|' . $key ) ) ) {
			delete_transient( 'sdsc2fa_' . $key );
			self::log( $user, 'signed in' );
			return array( 'uid' => (int) $p['uid'], 'remember' => $p['rem'], 'redirect' => $p['to'] );
		}
		$p['tries']++;
		self::log( $user, 'wrong code' );
		if ( $p['tries'] >= 5 ) {
			delete_transient( 'sdsc2fa_' . $key );
			self::log( $user, 'locked after 5 wrong codes' );
			return new WP_Error( 'sdsc_2fa', 'Too many wrong codes. Please sign in again.' );
		}
		set_transient( 'sdsc2fa_' . $key, $p, max( 60, self::TTL - ( time() - $p['at'] ) ) );
		return new WP_Error( 'sdsc_2fa', 'That code is not right. ' . ( 5 - $p['tries'] ) . ' tries left.' );
	}

	public static function resend( $key ) {
		$p = get_transient( 'sdsc2fa_' . $key );
		if ( ! is_array( $p ) ) { return new WP_Error( 'sdsc_2fa', 'This code has expired. Please sign in again.' ); }
		if ( $p['sends'] >= 3 ) { return new WP_Error( 'sdsc_2fa', 'No more codes for this sign-in. Please sign in again, or call the office at (301) 726-3030.' ); }
		if ( time() - $p['at'] < 60 ) { return new WP_Error( 'sdsc_2fa', 'Please wait a minute before asking for a new code.' ); }
		$user = get_userdata( $p['uid'] );
		$code = sprintf( '%06d', random_int( 0, 999999 ) );
		if ( ! $user || ! self::mail( $user, $code ) ) { return new WP_Error( 'sdsc_2fa', 'We could not send your sign-in code. Please call the office at (301) 726-3030.' ); }
		$p['h'] = wp_hash( $code . '|' . $key ); $p['sends']++; $p['at'] = time(); $p['tries'] = 0;
		set_transient( 'sdsc2fa_' . $key, $p, self::TTL );
		self::log( $user, 'new code sent' );
		return true;
	}

	/** Password was right — for covered people, stop here and ask for the code. */
	public static function gate( $user, $login = '', $pw = '' ) {
		if ( ! self::covered( $user ) ) { return $user; }
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || wp_doing_ajax() ) {
			return new WP_Error( 'sdsc_2fa', 'For your security, please sign in on the main login page: ' . wp_login_url() );
		}
		$rem = ! empty( $_POST['rememberme'] ); // phpcs:ignore
		$to  = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_REQUEST['redirect_to'] ), '' ) : ''; // phpcs:ignore
		$to  = $to ? $to : (string) apply_filters( 'login_redirect', admin_url(), '', $user );
		$key = self::begin( $user, $rem, $to );
		if ( is_wp_error( $key ) ) { return $key; }
		setcookie( 'sdsc2fa_k', wp_hash( $key ), time() + self::TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		wp_safe_redirect( add_query_arg( array( 'action' => 'sdsc2fa', 'k' => $key ), wp_login_url() ) );
		exit;
	}

	/** The "Enter your code" screen. */
	public static function page() {
		nocache_headers();
		$key = isset( $_REQUEST['k'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_REQUEST['k'] ) ) : ''; // phpcs:ignore
		$p   = $key ? get_transient( 'sdsc2fa_' . $key ) : false;
		$ck  = isset( $_COOKIE['sdsc2fa_k'] ) ? (string) wp_unslash( $_COOKIE['sdsc2fa_k'] ) : '';
		$err = new WP_Error();
		$msg = '';
		if ( ! is_array( $p ) || ! hash_equals( wp_hash( $key ), $ck ) ) {
			$err->add( 'x', 'This sign-in has expired. Please sign in again.' );
			login_header( 'Sign-in code', '', $err );
			echo '<p style="text-align:center"><a href="' . esc_url( wp_login_url() ) . '">Back to login</a></p>';
			login_footer();
			exit;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore
			if ( ! empty( $_POST['resend'] ) ) { // phpcs:ignore
				$r = self::resend( $key );
				if ( is_wp_error( $r ) ) { $err = $r; } else { $msg = 'A new code is on its way.'; }
			} else {
				$r = self::verify( $key, wp_unslash( $_POST['code'] ?? '' ) ); // phpcs:ignore
				if ( is_wp_error( $r ) ) {
					$err = $r;
					if ( ! get_transient( 'sdsc2fa_' . $key ) ) { login_header( 'Sign-in code', '', $err ); echo '<p style="text-align:center"><a href="' . esc_url( wp_login_url() ) . '">Back to login</a></p>'; login_footer(); exit; }
				} else {
					$u = get_userdata( $r['uid'] );
					wp_set_auth_cookie( $u->ID, $r['remember'] );
					wp_set_current_user( $u->ID );
					setcookie( 'sdsc2fa_k', '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
					do_action( 'wp_login', $u->user_login, $u );
					wp_safe_redirect( $r['redirect'] ? $r['redirect'] : admin_url() );
					exit;
				}
			}
		}
		$u = get_userdata( $p['uid'] );
		login_header( 'Sign-in code', '<p class="message" style="margin-bottom:12px">We e-mailed a 6-digit code to <b>' . esc_html( self::mask( $u->user_email ) ) . '</b>. Enter it below. It works for 10 minutes.' . ( $msg ? '<br><b>' . esc_html( $msg ) . '</b>' : '' ) . '</p>', $err );
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( array( 'action' => 'sdsc2fa', 'k' => $key ), wp_login_url() ) ); ?>" autocomplete="off">
			<p><label for="sdsc-code">Sign-in code</label>
			<input type="text" id="sdsc-code" name="code" class="input" inputmode="numeric" pattern="[0-9 ]*" maxlength="9" autocomplete="one-time-code" autofocus required style="font-size:26px;letter-spacing:6px;text-align:center"></p>
			<p class="submit"><input type="submit" class="button button-primary button-large" value="Verify and sign in"></p>
		</form>
		<form method="post" action="<?php echo esc_url( add_query_arg( array( 'action' => 'sdsc2fa', 'k' => $key ), wp_login_url() ) ); ?>" style="margin-top:10px">
			<input type="hidden" name="resend" value="1"><button class="button" type="submit">Send me a new code</button>
		</form>
		<p style="text-align:center;margin-top:14px"><a href="<?php echo esc_url( wp_login_url() ); ?>">← Back to login</a></p>
		<?php
		login_footer();
		exit;
	}

	/* ------------------------------------------------------------ office screen */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Sign-in codes', 'Sign-in codes', 'manage_options', 'sdsc-2fa', array( __CLASS__, 'screen' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No access.' ); }
		check_admin_referer( 'sdsc_2fa' );
		$p     = wp_unslash( $_POST ); // phpcs:ignore
		$roles = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['roles'] ?? array() ) ), array_keys( wp_roles()->roles ) ) );
		update_option( self::OPT, array( 'on' => empty( $p['on'] ) ? 0 : 1, 'roles' => $roles, 'skip' => array_values( array_map( 'intval', (array) ( $p['skip'] ?? array() ) ) ) ), false );
		wp_safe_redirect( add_query_arg( 'saved', 1, admin_url( 'admin.php?page=sdsc-2fa' ) ) );
		exit;
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = self::settings();
		$people = get_users( array( 'role__in' => ( $s['roles'] ? $s['roles'] : array( 'none' ) ), 'orderby' => 'display_name' ) );
		echo '<div class="wrap"><h1>Sign-in codes</h1>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Saved.</p></div>'; } // phpcs:ignore
		echo '<p>People in the chosen roles enter their password <b>and</b> a 6-digit code e-mailed to their account address. The code works for 10 minutes; 5 wrong tries stop the sign-in. These e-mails are never held by the Notifications hold.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_2fa">';
		wp_nonce_field( 'sdsc_2fa' );
		echo '<p><label><input type="checkbox" name="on" value="1"' . checked( $s['on'], 1, false ) . '> <b>Require a sign-in code</b></label></p><p><b>Who must use a code (by role):</b><br>';
		foreach ( wp_roles()->roles as $k => $r ) { echo '<label style="margin-right:14px"><input type="checkbox" name="roles[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, (array) $s['roles'], true ), true, false ) . '> ' . esc_html( translate_user_role( $r['name'] ) ) . '</label>'; }
		echo '</p><h2>People covered</h2>';
		if ( ! $people ) { echo '<p>Nobody has these roles yet.</p>'; } else {
			echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Name</th><th>Sign-in e-mail (masked)</th><th>Skip the code for this person</th></tr></thead><tbody>';
			foreach ( $people as $u ) { echo '<tr><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( self::mask( $u->user_email ) ) . '</td><td><input type="checkbox" name="skip[]" value="' . (int) $u->ID . '"' . checked( in_array( (int) $u->ID, array_map( 'intval', (array) $s['skip'] ), true ), true, false ) . '></td></tr>'; }
			echo '</tbody></table><p class="description">Use “skip” only when someone cannot receive e-mail; change their address in their profile first if you can.</p>';
		}
		echo '<p><button class="button button-primary">Save</button></p></form><h2>Recent activity</h2>';
		$l = (array) get_option( self::LOG, array() );
		if ( ! $l ) { echo '<p>Nothing yet.</p>'; } else {
			echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>When</th><th>User</th><th>What</th></tr></thead><tbody>';
			foreach ( array_slice( $l, 0, 40 ) as $x ) { echo '<tr><td>' . esc_html( $x['t'] ) . '</td><td>' . esc_html( $x['u'] ) . '</td><td>' . esc_html( $x['e'] ) . '</td></tr>'; }
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
