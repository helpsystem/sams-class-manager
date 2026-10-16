<?php
/**
 * SDSC_Hold — one master switch: while it is ON, nothing is e-mailed or texted to students or parents
 * (this covers Sam's Class Manager, DriveFlow lessons and every other wp_mail on the site).
 * Mail to the school's own addresses and to administrators still goes out. What was held is listed so nothing is lost.
 */
defined( 'ABSPATH' ) || exit;

class SDSC_Hold {
	const OPT = 'sdsc_hold_notices';
	const LOG = 'sdsc_hold_log';

	public static function init() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'gate_mail' ), 1, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 41 );
		add_action( 'admin_post_sdsc_hold', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'banner' ) );
	}

	/** ON unless someone switched it off on purpose. */
	public static function on() { return 'off' !== get_option( self::OPT, 'on' ); }

	protected static function internal( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( preg_match( '/<([^>]+)>/', $email, $m ) ) { $email = trim( $m[1] ); }
		if ( '' === $email ) { return true; }
		if ( '@samsdrivingschool.org' === substr( $email, -22 ) || strtolower( (string) get_option( 'admin_email' ) ) === $email ) { return true; }
		$u = get_user_by( 'email', $email );
		return $u && user_can( $u, 'manage_options' );
	}

	protected static function note( $kind, $to, $what ) {
		$l = get_option( self::LOG, array() );
		$l = is_array( $l ) ? $l : array();
		array_unshift( $l, array( 't' => current_time( 'mysql' ), 'k' => $kind, 'to' => $to, 'w' => substr( (string) $what, 0, 120 ) ) );
		update_option( self::LOG, array_slice( $l, 0, 300 ), false );
	}

	/** Drop every outside recipient; if none is left the mail is not sent. */
	public static function gate_mail( $pre, $a ) {
		if ( null !== $pre || ! self::on() || ! is_array( $a ) ) { return $pre; }
		// Sign-in codes and password resets are asked for by the student themself — never held, or nobody could log in.
		if ( preg_match( '/login verification|verification code|password reset|reset your password|set your password|sign-?in code/i', (string) ( $a['subject'] ?? '' ) ) ) { return $pre; }
		$to = $a['to'] ?? array();
		$to = is_array( $to ) ? $to : array_filter( array_map( 'trim', explode( ',', (string) $to ) ) );
		foreach ( $to as $t ) { if ( ! self::internal( $t ) ) { self::note( 'email', $t, $a['subject'] ?? '' ); } }
		$keep = array_values( array_filter( $to, array( __CLASS__, 'internal' ) ) );
		if ( count( $keep ) === count( $to ) ) { return $pre; }
		if ( ! $keep ) { return false; }
		$a['to'] = $keep;
		// send the reduced list without re-entering this gate
		remove_filter( 'pre_wp_mail', array( __CLASS__, 'gate_mail' ), 1 );
		$ok = wp_mail( $a['to'], $a['subject'] ?? '', $a['message'] ?? '', $a['headers'] ?? '', $a['attachments'] ?? array() );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'gate_mail' ), 1, 2 );
		return (bool) $ok;
	}

	/** Used by the text-message code: true = do not send / queue. */
	public static function block_sms( $phone, $body = '' ) {
		if ( ! self::on() ) { return false; }
		self::note( 'text', $phone, $body );
		return true;
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Notifications hold', 'Notifications hold', 'manage_options', 'sdsc-hold', array( __CLASS__, 'render' ) );
	}

	public static function banner() {
		if ( ! self::on() || ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="notice notice-warning"><p><strong>Notifications are on hold.</strong> No e-mail or text goes to students or parents. <a href="' . esc_url( admin_url( 'admin.php?page=sdsc-hold' ) ) . '">Open the switch</a></p></div>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed' ); }
		check_admin_referer( 'sdsc_hold' );
		if ( isset( $_POST['clear'] ) ) { delete_option( self::LOG ); }
		else { update_option( self::OPT, 'off' === ( $_POST['state'] ?? 'on' ) ? 'off' : 'on' ); }
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-hold&done=1' ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$on = self::on();
		$l  = get_option( self::LOG, array() );
		echo '<div class="wrap"><h1>Notifications hold</h1>';
		echo '<p>While this is <strong>ON</strong>, no e-mail or text message is sent to students or parents — from classes, Zoom, payments, DriveFlow lessons or anything else. Messages to the school’s own addresses and administrators still go out.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_hold">';
		wp_nonce_field( 'sdsc_hold' );
		echo '<p style="font-size:16px">Status: <strong style="color:' . ( $on ? '#b32d2e' : '#1a7f37' ) . '">' . ( $on ? 'ON — nothing is sent to students or parents' : 'OFF — notifications are sending normally' ) . '</strong></p>';
		echo '<input type="hidden" name="state" value="' . ( $on ? 'off' : 'on' ) . '">';
		echo '<p><button class="button button-primary" onclick="return confirm(\'' . ( $on ? 'Turn notifications back on for students and parents?' : 'Hold all notifications?' ) . '\')">' . ( $on ? 'Turn notifications back ON for students' : 'Put notifications on hold' ) . '</button></p></form>';
		echo '<h2>Held so far (' . count( (array) $l ) . ')</h2>';
		if ( $l ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>When</th><th>Type</th><th>To</th><th>What</th></tr></thead><tbody>';
			foreach ( array_slice( $l, 0, 100 ) as $r ) { echo '<tr><td>' . esc_html( $r['t'] ) . '</td><td>' . esc_html( $r['k'] ) . '</td><td>' . esc_html( $r['to'] ) . '</td><td>' . esc_html( $r['w'] ) . '</td></tr>'; }
			echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px"><input type="hidden" name="action" value="sdsc_hold">';
			wp_nonce_field( 'sdsc_hold' );
			echo '<button class="button" name="clear" value="1">Clear this list</button></form>';
		} else { echo '<p>Nothing has been held yet.</p>'; }
		echo '</div>';
	}
}
