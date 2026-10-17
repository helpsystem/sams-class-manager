<?php
/**
 * SDSC_Account — e-mail checks at registration and the student's website login.
 *
 * - The e-mail domain is checked when the form is sent (a mistyped domain such as "gmial.com" or a domain that
 *   cannot receive mail is sent back to the student for correction; if the check itself cannot run, nothing is blocked).
 * - The website login (needed for the online course and quizzes) is created automatically from the registration
 *   e-mail, with an unknown random password. The student only has to set a password — with the "Create your password"
 *   e-mail or with "Lost your password?" on the login page. Nothing is shown on screen that would let someone take over
 *   an account: the link only ever goes to the mailbox itself.
 * - The "Create your password" e-mail follows the Notifications hold like every other e-mail to students.
 *
 * @package SamsClassManager
 */
defined( 'ABSPATH' ) || exit;

class SDSC_Account {
	const PHONE = '(301) 726-3030';

	/** Common mistyped domains → what the student most likely meant. */
	protected static function typos() {
		return array(
			'gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.cm' => 'gmail.com',
			'yahooo.com' => 'yahoo.com', 'yaho.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yahoo.co' => 'yahoo.com',
			'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com',
			'outlok.com' => 'outlook.com', 'outlook.con' => 'outlook.com', 'iclod.com' => 'icloud.com', 'icloud.con' => 'icloud.com',
		);
	}

	/** DNS says the domain can receive mail? true / false, or null when the check cannot be trusted right now. */
	public static function domain_ok( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( false === strpos( $email, '@' ) ) { return false; }
		$domain = substr( strrchr( $email, '@' ), 1 );
		if ( '' === $domain ) { return false; }
		if ( isset( self::typos()[ $domain ] ) ) { return false; }
		if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x00-\x7F]/', $domain ) ) {
			$a = idn_to_ascii( $domain, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
			if ( $a ) { $domain = $a; }
		}
		if ( ! function_exists( 'checkdnsrr' ) ) { return null; }
		$key = 'sdsc_dom_' . md5( $domain );
		$c   = get_transient( $key );
		if ( 'ok' === $c ) { return true; }
		if ( ! self::dns_works() ) { return null; }
		for ( $try = 0; $try < 2; $try++ ) {
			if ( @checkdnsrr( $domain . '.', 'MX' ) || @checkdnsrr( $domain . '.', 'A' ) || @checkdnsrr( $domain . '.', 'AAAA' ) ) { // phpcs:ignore
				set_transient( $key, 'ok', 7 * DAY_IN_SECONDS );
				return true;
			}
		}
		return false;
	}

	/** A well-known domain must resolve, otherwise this server's DNS is not usable and nobody is blocked. */
	protected static function dns_works() {
		$c = get_transient( 'sdsc_dns_ctl' );
		if ( null !== $c && false !== $c ) { return 'y' === $c; }
		$ok = (bool) @checkdnsrr( 'gmail.com.', 'MX' ); // phpcs:ignore
		set_transient( 'sdsc_dns_ctl', $ok ? 'y' : 'n', $ok ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return $ok;
	}

	/** Message for a bad e-mail, or '' when it is fine. $who: 'your' | 'the parent / guardian'. */
	public static function problem( $email, $who = 'your' ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) { return ''; }
		$domain = false !== strpos( $email, '@' ) ? substr( strrchr( $email, '@' ), 1 ) : '';
		$ty = self::typos();
		if ( isset( $ty[ $domain ] ) ) {
			return 'Please check ' . $who . ' e-mail: did you mean ' . substr( $email, 0, strrpos( $email, '@' ) ) . '@' . $ty[ $domain ] . '? An e-mail with a mistake never arrives. If it is correct as typed, call ' . self::PHONE . ' and tell the office the exact e-mail.';
		}
		if ( false === self::domain_ok( $email ) ) {
			return 'Please check ' . $who . ' e-mail: “' . $domain . '” does not look like a working e-mail domain. An e-mail with a mistake never arrives. If it is correct as typed, call ' . self::PHONE . ' and tell the office the exact e-mail.';
		}
		return '';
	}

	/* ------------------------------------------------------------ the student's website login */

	public static function init() {
		add_action( 'sdsc_registered', array( __CLASS__, 'on_registered' ), 20, 2 );
	}

	/** Create the login from the registration e-mail when there is none yet. Returns the user id (0 when not created). */
	public static function ensure( $email, $first = '', $last = '' ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) { return 0; }
		if ( get_user_by( 'email', $email ) ) { return 0; }
		$login = $email;
		if ( username_exists( $login ) ) { return 0; }
		$id = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32 ),
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ) ?: $email,
			'role'         => get_option( 'default_role', 'subscriber' ),
		) );
		if ( is_wp_error( $id ) ) { return 0; }
		update_user_meta( $id, '_is_tutor_student', time() );
		update_user_meta( $id, 'sdsc_auto_account', time() );
		return (int) $id;
	}

	public static function on_registered( $reg_id, $d ) {
		$email = (string) ( $d['email'] ?? '' );
		$id    = self::ensure( $email, (string) ( $d['first_name'] ?? '' ), (string) ( $d['last_name'] ?? '' ) );
		if ( ! $id ) { return; }
		self::send_setup( $id, (string) ( $d['first_name'] ?? '' ) );
	}

	/** The "Create your password" e-mail (goes only to the account's own mailbox; held while the Notifications hold is on). */
	public static function send_setup( $user_id, $first = '' ) {
		$u = get_userdata( $user_id );
		if ( ! $u ) { return false; }
		$exp = function () { return 7 * DAY_IN_SECONDS; };
		add_filter( 'password_reset_expiration', $exp, 99 );
		$key = get_password_reset_key( $u );
		remove_filter( 'password_reset_expiration', $exp, 99 );
		if ( is_wp_error( $key ) ) { return false; }
		$url = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $u->user_login ), 'login' );
		$f   = 'font-family:Arial,Helvetica,sans-serif;';
		$h   = '<p style="' . $f . 'margin:0 0 14px;font-size:15px;line-height:1.6;color:#15191d">Hi ' . esc_html( $first ?: $u->first_name ) . ', your Sam’s Driving School website login is ready — you only need to choose a password. You will use it for the online course and quizzes.</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:14px auto 22px"><tr><td style="border-radius:999px;background:#00703c"><a href="' . esc_url( $url ) . '" style="' . $f . 'display:inline-block;padding:14px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px">Create your password →</a></td></tr></table>'
			. '<p style="' . $f . 'margin:0 0 10px;font-size:14px;line-height:1.6;color:#15191d">Your login name is your e-mail: <b>' . esc_html( $u->user_email ) . '</b>. The link works for 7 days. After that, use “Lost your password?” on the login page.</p>'
			. self::email_block();
		$hdr = array( 'Content-Type: text/html; charset=UTF-8' );
		$sub = 'Create your password — Sam’s Driving School';
		return class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::send( $u->user_email, $sub, $h, 'info', $hdr ) : wp_mail( $u->user_email, $sub, $h, $hdr );
	}

	/* ------------------------------------------------------------ wording shared by the page and the e-mails */

	/** How to log in, and what to do when an e-mail address is wrong (HTML, e-mail safe). */
	public static function email_block() {
		$f = 'font-family:Arial,Helvetica,sans-serif;';
		return '<div style="margin:16px 0 0;padding:14px 16px;border-radius:12px;background:#f7f9fa;' . $f . 'font-size:14px;line-height:1.6;color:#15191d">'
			. '<b>Your website login</b> (online course and quizzes): it is created for you from the e-mail on your registration. Go to the login page, choose <b>Lost your password?</b>, enter that e-mail and set your password.'
			. '<br><br><b>Wrong e-mail?</b> If the student’s or the parent’s / guardian’s e-mail was typed wrongly, please tell the office: call or text <b>' . self::PHONE . '</b> and give the <b>correct e-mail exactly</b> (spell it out) so we can check and fix it.</div>';
	}

	/** The same wording for the web pages. */
	public static function page_block() {
		return '<div class="sdsc-login-note" style="margin:16px auto 0;max-width:560px;text-align:left;padding:14px 16px;border-radius:12px;background:#f7f9fa;font-size:14px;line-height:1.6">'
			. '<p style="margin:0 0 8px"><b>Your website login</b> (online course and quizzes) is created for you from the e-mail on your registration. You only need to set a password: on the <a href="' . esc_url( wp_login_url() ) . '">login page</a> choose <b>Lost your password?</b>, enter that e-mail and follow the link.</p>'
			. '<p style="margin:0"><b>Wrong e-mail?</b> If the student’s or the parent’s / guardian’s e-mail was typed wrongly, call or text the office at <b>' . esc_html( self::PHONE ) . '</b> and give the <b>correct e-mail exactly</b> (spell it out) so we can check and fix it.</p></div>';
	}
}
