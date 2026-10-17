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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_page' ), 1 );
		add_action( 'after_password_reset', function ( $user ) { if ( $user && ! empty( $user->ID ) ) { update_user_meta( $user->ID, 'sdsc_pw_set', time() ); } } );
	}

	/* ------------------------------------------------------------ "Create your password" page */

	public static function pw_url( $rid ) {
		return add_query_arg( array( 'sdsc_pw' => 1, 'r' => (int) $rid, 't' => SDSC_Pay::token( (int) $rid ) ), home_url( '/' ) );
	}

	protected static function st_key( $rid ) { return 'sdsc_pwst_' . (int) $rid; }

	protected static function reg( $rid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, class_id, first_name, last_name, email, notes, registrant_id FROM ' . SDSC_Registration::table() . ' WHERE id = %d', $rid ), ARRAY_A );
	}

	/** A login this page may set a password for: made automatically by registration, password never chosen yet, no staff rights. */
	protected static function claimable( $user ) {
		if ( ! $user ) { return true; } // none yet: it will be created
		if ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ) ) { return false; }
		return (bool) get_user_meta( $user->ID, 'sdsc_auto_account', true ) && ! get_user_meta( $user->ID, 'sdsc_pw_set', true );
	}

	public static function maybe_page() {
		if ( ! isset( $_GET['sdsc_pw'] ) || ! class_exists( 'SDSC_Pay' ) || ! class_exists( 'SDSC_Registration' ) ) { return; } // phpcs:ignore
		$rid = absint( $_GET['r'] ?? 0 ); // phpcs:ignore
		$tok = sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) ); // phpcs:ignore
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		$reg = ( $rid && hash_equals( SDSC_Pay::token( $rid ), $tok ) ) ? self::reg( $rid ) : null;
		if ( ! $reg ) { self::shell( '<h1>This link is not valid</h1><p>Please call or text the office at <b>' . esc_html( self::PHONE ) . '</b> and we will help you.</p>' ); }
		$st = get_transient( self::st_key( $rid ) );
		$st = is_array( $st ) ? $st : array( 'tries' => 0, 'all' => 0, 'ok' => '' );
		$msg = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore
			$P = wp_unslash( $_POST ); // phpcs:ignore
			$step = sanitize_key( $P['step'] ?? '' );
			if ( ! empty( $P['website'] ) ) { exit; } // honeypot
			if ( (int) $st['all'] >= 15 ) {
				$msg = 'Too many attempts. Please call or text the office at ' . self::PHONE . '.';
			} elseif ( 'check' === $step ) {
				$st['all']++;
				$typed = strtolower( trim( sanitize_email( $P['typed'] ?? '' ) ) );
				if ( '' !== $typed && $typed === strtolower( $reg['email'] ) ) { $st['ok'] = $reg['email']; $st['tries'] = 0; }
				else { $st['tries']++; $msg = 'That is not the e-mail on the registration (' . (int) $st['tries'] . ' of 3).'; }
			} elseif ( 'edit' === $step && (int) $st['tries'] >= 3 ) {
				$st['all']++;
				$n1 = strtolower( trim( sanitize_email( $P['new1'] ?? '' ) ) ); $n2 = strtolower( trim( sanitize_email( $P['new2'] ?? '' ) ) );
				$other = $n1 ? get_user_by( 'email', $n1 ) : false;
				if ( ! is_email( $n1 ) || $n1 !== $n2 ) { $msg = 'Please type the correct e-mail twice, exactly the same.'; }
				elseif ( '' !== ( $pm = self::problem( $n1, 'your' ) ) ) { $msg = $pm; }
				elseif ( $other && ! self::claimable( $other ) ) { $msg = 'That e-mail already has a login. Use “Lost your password?” on the login page, or call ' . self::PHONE . '.'; }
				else { $st['ok'] = $n1; }
			} elseif ( 'pw' === $step && '' !== $st['ok'] ) {
				$st['all']++;
				$p1 = (string) ( $P['pw1'] ?? '' ); $p2 = (string) ( $P['pw2'] ?? '' );
				if ( strlen( $p1 ) < 10 ) { $msg = 'Please choose a password with at least 10 characters.'; }
				elseif ( $p1 !== $p2 ) { $msg = 'The two passwords are not the same.'; }
				elseif ( 0 === strcasecmp( $p1, $st['ok'] ) ) { $msg = 'The password must not be your e-mail.'; }
				else {
					$res = self::commit( $reg, $st['ok'], $p1 );
					if ( true === $res ) { delete_transient( self::st_key( $rid ) ); self::shell( '<h1>Your password is set ✓</h1><p>You can now log in with your e-mail and your password.</p><p><a class="b" href="' . esc_url( wp_login_url() ) . '">Go to the login page →</a></p>' ); }
					$msg = $res;
				}
			}
			set_transient( self::st_key( $rid ), $st, HOUR_IN_SECONDS );
		}
		self::shell( self::form( $reg, $st, $msg ) );
	}

	/** Replace the registration e-mail when it was corrected, make sure the login exists, set the password. true or an error text. */
	protected static function commit( $reg, $new, $pw ) {
		global $wpdb;
		$old = $reg['email'];
		$changed = strtolower( $old ) !== strtolower( $new );
		$ou = get_user_by( 'email', $old );
		$nu = get_user_by( 'email', $new );
		if ( $changed && $nu && ! self::claimable( $nu ) ) { return 'That e-mail already has a login. Use “Lost your password?” on the login page, or call ' . self::PHONE . '.'; }
		$target = $changed ? ( $nu ? $nu : $ou ) : $ou;
		if ( $target && ! self::claimable( $target ) ) { return 'This e-mail already has a login. Please use “Lost your password?” on the login page — the link goes to your mailbox. Or call ' . self::PHONE . '.'; }
		if ( $changed ) {
			$line = trim( ( $reg['notes'] ? $reg['notes'] . "\n" : '' ) . 'E-mail corrected by the student on ' . current_time( 'Y-m-d H:i' ) . ': ' . $old . ' → ' . $new . ' (Zoom invitation, if any, may need the new e-mail).' );
			$wpdb->update( SDSC_Registration::table(), array( 'email' => $new, 'notes' => $line ), array( 'id' => (int) $reg['id'] ) );
			if ( ! empty( $reg['registrant_id'] ) ) { update_post_meta( (int) $reg['registrant_id'], '_sdsc_email', $new ); }
			if ( $ou && ! $nu && $ou->user_login === $old ) {
				add_filter( 'send_email_change_email', '__return_false', 99 ); // no notice to the student
				wp_update_user( array( 'ID' => $ou->ID, 'user_email' => $new ) );
				remove_filter( 'send_email_change_email', '__return_false', 99 );
				$wpdb->update( $wpdb->users, array( 'user_login' => $new, 'user_nicename' => sanitize_title( $new ) ), array( 'ID' => $ou->ID ) );
				clean_user_cache( $ou->ID );
				$target = get_user_by( 'id', $ou->ID );
			}
		}
		if ( ! $target ) {
			$uid = self::ensure( $new, (string) $reg['first_name'], (string) $reg['last_name'] );
			$target = $uid ? get_user_by( 'id', $uid ) : null;
		}
		if ( ! $target ) { return 'Sorry, the login could not be created. Please call or text ' . self::PHONE . '.'; }
		wp_set_password( $pw, $target->ID );
		update_user_meta( $target->ID, 'sdsc_pw_set', time() );
		return true;
	}

	protected static function form( $reg, $st, $msg ) {
		$h = '<h1>Create your password</h1>';
		if ( '' !== $msg ) { $h .= '<p class="e" role="alert">' . esc_html( $msg ) . '</p>'; }
		if ( '' === $st['ok'] ) {
			$h .= '<p>This is the e-mail on your registration. To keep your login safe, <b>type it yourself</b> — it cannot be copied or pasted.</p>'
				. '<div class="lock" oncopy="return false" oncut="return false" oncontextmenu="return false" ondragstart="return false" onselectstart="return false">' . esc_html( $reg['email'] ) . '</div>';
			if ( (int) $st['tries'] < 3 ) {
				$h .= '<form method="post"><input type="hidden" name="step" value="check"><input class="hp" name="website" tabindex="-1" autocomplete="off">'
					. '<label>Type your e-mail<input type="email" name="typed" required autocomplete="off" autocapitalize="off" spellcheck="false" onpaste="return false" ondrop="return false"></label>'
					. '<button class="b">Continue</button></form>';
			} else {
				$h .= '<p class="e">Three tries did not match. If the e-mail above is <b>wrong</b>, enter the correct one here — it will replace the e-mail on your registration.</p>'
					. '<form method="post"><input type="hidden" name="step" value="edit"><input class="hp" name="website" tabindex="-1" autocomplete="off">'
					. '<label>Correct e-mail<input type="email" name="new1" required autocomplete="off" autocapitalize="off" spellcheck="false" onpaste="return false" ondrop="return false"></label>'
					. '<label>Type it again<input type="email" name="new2" required autocomplete="off" autocapitalize="off" spellcheck="false" onpaste="return false" ondrop="return false"></label>'
					. '<button class="b">Use this e-mail</button></form>'
					. '<p class="s">Not sure? Call or text the office at <b>' . esc_html( self::PHONE ) . '</b> and tell us the correct e-mail exactly.</p>';
			}
		} else {
			$h .= '<p>E-mail: <b>' . esc_html( $st['ok'] ) . '</b>' . ( strtolower( $st['ok'] ) !== strtolower( $reg['email'] ) ? ' <span class="s">(this will replace the e-mail on your registration)</span>' : '' ) . '</p>'
				. '<form method="post"><input type="hidden" name="step" value="pw"><input class="hp" name="website" tabindex="-1" autocomplete="off">'
				. '<label>Choose a password (at least 10 characters)<input type="password" name="pw1" required minlength="10" autocomplete="new-password"></label>'
				. '<label>Type the password again<input type="password" name="pw2" required minlength="10" autocomplete="new-password"></label>'
				. '<button class="b">Set my password</button></form>';
		}
		return $h;
	}

	protected static function shell( $body ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Create your password</title><style>'
			. 'body{margin:0;background:#f3f6f4;font:16px/1.55 system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#15191d}.w{max-width:460px;margin:8vh auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 4px 24px rgba(0,0,0,.08)}'
			. 'h1{font-size:22px;margin:0 0 12px;color:#0b5d2e}label{display:block;margin:12px 0;font-weight:600}input{display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:11px;border:1px solid #cfd6db;border-radius:8px;font-size:16px}'
			. '.b{display:inline-block;border:0;border-radius:999px;background:#0b5d2e;color:#fff;font-weight:700;padding:12px 24px;font-size:16px;cursor:pointer;text-decoration:none;margin-top:8px}.e{background:#fdeeee;color:#8a1f1f;padding:10px 12px;border-radius:8px}.s{font-size:13px;color:#5b646d}'
			. '.lock{user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;background:#f3f6f4;border:1px dashed #9fb3a7;border-radius:8px;padding:12px;font-weight:700;text-align:center;word-break:break-all}.hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}'
			. '</style></head><body><div class="w">' . $body . '</div></body></html>';
		exit;
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
		self::send_setup( $id, (string) ( $d['first_name'] ?? '' ), (int) $reg_id );
	}

	/** The "Create your password" e-mail (goes only to the account's own mailbox; held while the Notifications hold is on). */
	public static function send_setup( $user_id, $first = '', $rid = 0 ) {
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
			. self::email_block( $rid );
		$hdr = array( 'Content-Type: text/html; charset=UTF-8' );
		$sub = 'Create your password — Sam’s Driving School';
		return class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::send( $u->user_email, $sub, $h, 'info', $hdr ) : wp_mail( $u->user_email, $sub, $h, $hdr );
	}

	/* ------------------------------------------------------------ wording shared by the page and the e-mails */

	/** How to log in, and what to do when an e-mail address is wrong (HTML, e-mail safe). */
	public static function email_block( $rid = 0 ) {
		$f = 'font-family:Arial,Helvetica,sans-serif;';
		return '<div style="margin:16px 0 0;padding:14px 16px;border-radius:12px;background:#f7f9fa;' . $f . 'font-size:14px;line-height:1.6;color:#15191d">'
			. '<b>Your website login</b> (online course and quizzes) is created for you from the e-mail on your registration — you only choose a password.' . ( $rid ? ' <a href="' . esc_url( self::pw_url( $rid ) ) . '" style="color:#00703c;font-weight:700">Create your password →</a> (you will confirm your e-mail once, then choose a password).' : ' On the login page choose <b>Lost your password?</b>, enter that e-mail and follow the link.' )
			. '<br><br><b>Wrong e-mail?</b> If the student’s or the parent’s / guardian’s e-mail was typed wrongly, please tell the office: call or text <b>' . self::PHONE . '</b> and give the <b>correct e-mail exactly</b> (spell it out) so we can check and fix it.</div>';
	}

	/** The same wording for the web pages. */
	public static function page_block( $rid = 0 ) {
		return '<div class="sdsc-login-note" style="margin:16px auto 0;max-width:560px;text-align:left;padding:14px 16px;border-radius:12px;background:#f7f9fa;font-size:14px;line-height:1.6">'
			. '<p style="margin:0 0 8px"><b>Your website login</b> (online course and quizzes) is created for you from the e-mail on your registration. You only need to choose a password.</p>'
			. ( $rid ? '<p style="margin:0 0 10px"><a class="sdsc-btn" href="' . esc_url( self::pw_url( $rid ) ) . '">Create your password &rarr;</a><br><small>You will confirm your e-mail once (type it yourself), then choose a password. Later, if you forget it, use “Lost your password?” on the <a href="' . esc_url( wp_login_url() ) . '">login page</a>.</small></p>' : '<p style="margin:0 0 8px">On the <a href="' . esc_url( wp_login_url() ) . '">login page</a> choose <b>Lost your password?</b>, enter that e-mail and follow the link.</p>' )
			. '<p style="margin:0"><b>Wrong e-mail?</b> If the student’s or the parent’s / guardian’s e-mail was typed wrongly, call or text the office at <b>' . esc_html( self::PHONE ) . '</b> and give the <b>correct e-mail exactly</b> (spell it out) so we can check and fix it.</p></div>';
	}
}
