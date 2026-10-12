<?php
/**
 * Instructor onboarding follow-up.
 *
 * An instructor who has not finished the onboarding form (agreement signed, license number, phone,
 * payment details) shows up on the administrator's and the school manager's dashboard with what is
 * missing and a "Send link" button. The button creates a fresh 7-day onboarding link and sends it to the
 * instructor by e-mail and text. The school manager and the administrator also get a reminder by e-mail
 * (and by text when Twilio is connected and their mobile number is on their profile) when the list
 * changes, and again every three days while someone is still missing.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Onboard {
	const SENT   = 'sdsc_onboard_sent';   // instructor id => [ time, user id ]
	const NOTICE = 'sdsc_onboard_notice'; // [ hash, time ]

	public static function init() {
		add_action( 'admin_post_sdsc_onboard_send', array( __CLASS__, 'h_send' ) );
		add_action( 'sdsc_schedule_tick', array( __CLASS__, 'remind_office' ) );
	}

	public static function on() {
		global $wpdb;
		return class_exists( 'DriveFlow_Magic_Links' ) && (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'driveflow_instructors' ) );
	}

	public static function can() {
		return current_user_can( 'manage_options' ) || current_user_can( 'driveflow_manage' );
	}

	protected static function agreement_required() {
		$s = get_option( 'driveflow_pro_settings', array() );
		return '1' === (string) ( $s['instructor_agreement_enabled'] ?? '1' );
	}

	/** What is still missing for one instructor row. */
	public static function missing( $r ) {
		$m = array();
		if ( self::agreement_required() && empty( $r['agreement_signed'] ) ) { $m[] = 'agreement not signed'; }
		if ( '' === trim( (string) $r['license_number'] ) ) { $m[] = 'instructor license #'; }
		if ( '' === trim( (string) $r['phone'] ) ) { $m[] = 'phone'; }
		if ( '' === trim( (string) $r['payment_details'] ) ) { $m[] = 'payment details'; }
		if ( empty( $r['onboarding_completed'] ) && ! $m ) { $m[] = 'onboarding form not submitted'; }
		return $m;
	}

	/** Active instructors who still have something missing. */
	public static function pending() {
		if ( ! self::on() ) { return array(); }
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT id, user_id, name, email, phone, license_number, payment_details, agreement_signed, onboarding_completed FROM {$wpdb->prefix}driveflow_instructors WHERE status = 'active' AND email NOT LIKE '%@preview.samsdrivingschool.invalid' ORDER BY name", ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$m = self::missing( $r );
			if ( $m ) { $r['missing'] = $m; $out[] = $r; }
		}
		return $out;
	}

	public static function sent() { $s = get_option( self::SENT, array() ); return is_array( $s ) ? $s : array(); }

	/* ------------------------------------------------------------ sending the link */

	/** Create a fresh onboarding link and send it. Returns a short result sentence. */
	public static function send( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}driveflow_instructors WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $r ) { return 'Instructor not found.'; }
		$tok = DriveFlow_Magic_Links::create_token( 'instructor_onboarding', (int) $r['id'], 7 * 24, true );
		if ( empty( $tok['short_code'] ) ) { return 'Could not create the link. Please try again.'; }
		$url   = DriveFlow_Magic_Links::get_short_url( $tok['short_code'] );
		$first = trim( strtok( (string) $r['name'], ' ' ) );
		$done  = array();

		if ( is_email( $r['email'] ) ) {
			$f = 'font-family:Arial,Helvetica,sans-serif;';
			$h = '<p style="' . $f . 'margin:0 0 14px;font-size:15px;line-height:1.6;color:#15191d">Hi ' . esc_html( $first ) . ',</p>'
				. '<p style="' . $f . 'margin:0 0 14px;font-size:15px;line-height:1.6;color:#15191d">Please finish your instructor profile at Sam’s Driving School. It takes about five minutes: your contact details, your instructor license number, how you would like to be paid, and the instructor agreement to read and sign.</p>'
				. ( self::missing( $r ) ? '<p style="' . $f . 'margin:0 0 14px;font-size:14px;color:#5b646d">Still missing: ' . esc_html( implode( ', ', self::missing( $r ) ) ) . '.</p>' : '' )
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:18px auto 18px"><tr><td style="border-radius:999px;background:#00703c"><a href="' . esc_url( $url ) . '" style="' . $f . 'display:inline-block;padding:14px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px">Complete my profile →</a></td></tr></table>'
				. '<p style="' . $f . 'margin:0 0 6px;font-size:13px;color:#5b646d;text-align:center">This personal link works for 7 days and only once. If it expires, ask the office for a new one.</p>'
				. '<p style="' . $f . 'margin:18px 0 0;font-size:14px;color:#15191d">Questions? Call (301) 726-3030.</p>';
			$ok = class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::send( $r['email'], 'Please complete your instructor profile — Sam’s Driving School', $h, 'info', array( 'Content-Type: text/html; charset=UTF-8' ) ) : wp_mail( $r['email'], 'Please complete your instructor profile', $h, array( 'Content-Type: text/html; charset=UTF-8' ) );
			$done[] = $ok ? 'e-mailed' : 'e-mail failed';
		}
		if ( '' !== trim( (string) $r['phone'] ) && class_exists( 'SDSC_SMS' ) ) {
			$done[] = 'text ' . SDSC_SMS::deliver( $r['phone'], 'Hi ' . $first . ', please complete your Sam’s Driving School instructor profile and sign the agreement: ' . $url . ' (link works for 7 days) – Sam’s Driving School', 'onboard:' . $r['id'] . ':' . gmdate( 'Ymd' ), (string) $r['name'], '🧑‍🏫 Instructor onboarding link' );
		}
		$s                   = self::sent();
		$s[ (int) $r['id'] ] = array( time(), get_current_user_id() );
		update_option( self::SENT, $s, false );
		return $done ? 'Link sent to ' . $r['name'] . ': ' . implode( ', ', $done ) . '.' : 'Link created for ' . $r['name'] . ', but there is no e-mail or phone to send it to: ' . $url;
	}

	public static function h_send() {
		if ( ! self::can() ) { wp_die( 'Not allowed.', 403 ); }
		check_admin_referer( 'sdsc_onboard_send' );
		$msg  = self::on() ? self::send( absint( $_POST['id'] ?? 0 ) ) : 'DriveFlow is not active.';
		$back = wp_get_referer() ? wp_get_referer() : home_url( '/dashboard/' );
		wp_safe_redirect( add_query_arg( 'sob_msg', rawurlencode( $msg ), remove_query_arg( 'sob_msg', $back ) ) );
		exit;
	}

	/* ------------------------------------------------------------ dashboard card */

	public static function card() {
		if ( ! self::can() ) { return ''; }
		$list = self::pending();
		$msg  = isset( $_GET['sob_msg'] ) ? '<p class="sov-flash">' . esc_html( sanitize_text_field( wp_unslash( $_GET['sob_msg'] ) ) ) . '</p>' : ''; // phpcs:ignore
		if ( ! $list ) { return $msg; }
		$sent = self::sent();
		$h    = $msg . '<section class="sov-card sov-card--alert"><header><h4>🧑‍🏫 ' . esc_html( count( $list ) . ' instructor' . ( 1 === count( $list ) ? ' has' : 's have' ) . ' not finished onboarding' ) . '</h4></header><ul class="sov-list">';
		foreach ( $list as $r ) {
			$last = $sent[ (int) $r['id'] ] ?? null;
			$h   .= '<li><span class="sov-what"><b>' . esc_html( $r['name'] ) . '</b><small>Missing: ' . esc_html( implode( ', ', $r['missing'] ) ) . ( $last ? ' · link sent ' . esc_html( human_time_diff( $last[0] ) ) . ' ago' : ' · no link sent yet' ) . '</small></span>'
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'sdsc_onboard_send', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_onboard_send"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
				. '<button class="sov-btn" type="submit">' . ( $last ? 'Send again' : 'Send link' ) . ' (e-mail + text)</button></form></li>';
		}
		return $h . '</ul></section>';
	}

	/* ------------------------------------------------------------ reminder to the office */

	public static function remind_office() {
		if ( ! self::on() ) { return; }
		$list = self::pending();
		$prev = get_option( self::NOTICE, array( '', 0 ) );
		if ( ! $list ) { if ( $prev[0] ) { update_option( self::NOTICE, array( '', time() ), false ); } return; }
		$hash = md5( wp_json_encode( wp_list_pluck( $list, 'id' ) ) );
		if ( $hash === $prev[0] && time() - (int) $prev[1] < 3 * DAY_IN_SECONDS ) { return; }
		update_option( self::NOTICE, array( $hash, time() ), false );

		$dash  = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' );
		$dash  = class_exists( 'SDSC_Short' ) ? SDSC_Short::make( $dash ) : $dash;
		$names = wp_list_pluck( $list, 'name' );
		$f     = 'font-family:Arial,Helvetica,sans-serif;';
		$li    = '';
		foreach ( $list as $r ) { $li .= '<li style="margin:0 0 6px"><b>' . esc_html( $r['name'] ) . '</b> — ' . esc_html( implode( ', ', $r['missing'] ) ) . '</li>'; }
		$h = '<p style="' . $f . 'margin:0 0 12px;font-size:15px;line-height:1.6;color:#15191d">These instructors have not finished their onboarding (profile and agreement) yet:</p><ul style="' . $f . 'font-size:14px;line-height:1.6;color:#15191d">' . $li . '</ul>'
			. '<p style="' . $f . 'margin:12px 0 0;font-size:15px;color:#15191d">Open the dashboard and press <b>Send link</b> next to each name — they get a new link by e-mail and text.</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:18px auto"><tr><td style="border-radius:999px;background:#00703c"><a href="' . esc_url( $dash ) . '" style="' . $f . 'display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px">Open the dashboard →</a></td></tr></table>';

		$to = array();
		foreach ( get_users( array( 'role__in' => array( class_exists( 'SDSC_Access' ) ? SDSC_Access::MANAGER : 'editor', 'administrator' ), 'fields' => array( 'ID', 'user_email' ) ) ) as $u ) {
			if ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview( $u->ID ) ) { continue; }
			$to[ $u->ID ] = $u->user_email;
		}
		foreach ( $to as $uid => $email ) {
			if ( ! is_email( $email ) ) { continue; }
			if ( class_exists( 'SDSC_Mailer' ) ) { SDSC_Mailer::send( $email, 'Instructor onboarding: ' . count( $list ) . ' still to finish', $h, 'noreply', array( 'Content-Type: text/html; charset=UTF-8' ) ); }
			else { wp_mail( $email, 'Instructor onboarding: ' . count( $list ) . ' still to finish', $h, array( 'Content-Type: text/html; charset=UTF-8' ) ); }
			// A text too, only through Twilio (never into the staff's own "Texts to send" list).
			$phone = (string) get_user_meta( $uid, 'billing_phone', true );
			if ( $phone && class_exists( 'SDSC_SMS' ) && SDSC_SMS::ready() ) {
				SDSC_SMS::send( $phone, 'Sam’s Driving School: ' . count( $list ) . ' instructor(s) still need to finish onboarding (' . implode( ', ', array_slice( $names, 0, 3 ) ) . ( count( $names ) > 3 ? '…' : '' ) . '). Resend their link from the dashboard: ' . $dash, 'onboard-office:' . $uid . ':' . gmdate( 'Ymd' ) );
			}
		}
	}
}
