<?php
/**
 * Zoom meetings for classes, made automatically.
 *
 * Every open class (upcoming or active) with a timetable and no Zoom link gets its own Zoom
 * meeting on one of the school's two Zoom accounts — the account that is free at the class
 * times. One link works for every session of the class (a recurring meeting with no fixed
 * time), with the waiting room on and nobody joining before the host.
 *
 * Automatic is the default (Settings: sdsc_zoom_auto; per class: _sdsc_zoom_auto = 'off').
 * The administrator or the school manager e-mails the link and the meeting details to the
 * students of a class with one button (class screen or class board); the usual reminders
 * the day before and one hour before each session keep going out as before.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_ZoomAuto {
	const OPT_ON = 'sdsc_zoom_auto';
	const CRON   = 'sdsc_zoom_auto_daily';

	public static function init() {
		add_action( 'save_post_sdsc_class', array( __CLASS__, 'on_save' ), 50, 2 );
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'admin_post_sdsc_zoom_create', array( __CLASS__, 'h_create' ) );
		add_action( 'admin_post_sdsc_zoom_send', array( __CLASS__, 'h_send' ) );
		add_action( 'admin_post_sdsc_zoom_start', array( __CLASS__, 'h_start' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'wp_mail', array( __CLASS__, 'upgrade_auto_mail' ), 5 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + 300, 'twicedaily', self::CRON ); }
	}

	public static function on() { return '0' !== (string) get_option( self::OPT_ON, '1' ); }

	public static function can() {
		if ( class_exists( 'SDSC_Board' ) ) { return SDSC_Board::can(); }
		return current_user_can( 'manage_options' );
	}

	/** Account number (1 or 2) from the class's "Zoom account" setting, or 0 when none is chosen. */
	protected static function chosen( $cid ) {
		$v = (string) get_post_meta( $cid, '_sdsc_zoom_account', true );
		return preg_match( '/(\d)$/', $v, $m ) ? (int) $m[1] : 0;
	}

	protected static function open( $cid ) {
		return 'sdsc_class' === get_post_type( $cid ) && 'publish' === get_post_status( $cid )
			&& in_array( (string) ( get_post_meta( $cid, '_sdsc_status', true ) ?: 'upcoming' ), array( 'upcoming', 'active' ), true );
	}

	/** Sessions as [start, end] timestamps. */
	protected static function spans( $cid ) {
		$o = array();
		foreach ( SDSC_Schedule::sessions( $cid ) as $x ) {
			$o[] = array( strtotime( get_gmt_from_date( $x['start'] ) . ' UTC' ), strtotime( get_gmt_from_date( $x['end'] ) . ' UTC' ) );
		}
		return $o;
	}

	/** The Zoom account that is free at this class's times (the chosen one first). 0 = both busy. */
	public static function pick_account( $cid ) {
		$mine  = self::spans( $cid );
		$order = array_unique( array_filter( array( self::chosen( $cid ), 1, 2 ) ) );
		$others = get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'publish', 'numberposts' => 60, 'fields' => 'ids', 'exclude' => array( $cid ) ) );
		foreach ( $order as $n ) {
			if ( ! SDSC_Api::zoom( $n ) ) { continue; }
			$busy = false;
			foreach ( $others as $o ) {
				$on = (int) get_post_meta( $o, '_sdsc_zoom_acct_n', true ) ?: self::chosen( $o );
				if ( ! self::open( $o ) || $on !== $n ) { continue; }
				foreach ( self::spans( $o ) as $b ) {
					foreach ( $mine as $a ) { if ( $a[0] < $b[1] && $b[0] < $a[1] ) { $busy = true; break 3; } }
				}
			}
			if ( ! $busy ) { return $n; }
		}
		return 0;
	}

	protected static function token( $n ) {
		$k = 'sdsc_zoom_tok_' . $n;
		$t = get_transient( $k );
		if ( $t ) { return $t; }
		$z = SDSC_Api::zoom( $n );
		if ( ! $z ) { return new WP_Error( 'zoom', 'Zoom account ' . $n . ' is not connected (API connections).' ); }
		$r = wp_remote_post( 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode( $z['account'] ), array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $z['client'] . ':' . $z['secret'] ) ),
		) );
		if ( is_wp_error( $r ) ) { return $r; }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( empty( $b['access_token'] ) ) { return new WP_Error( 'zoom', 'Zoom did not accept the keys of account ' . $n . '.' ); }
		set_transient( $k, $b['access_token'], max( 60, (int) ( $b['expires_in'] ?? 3600 ) - 120 ) );
		return $b['access_token'];
	}

	/** Make the Zoom meeting for a class. Returns true or WP_Error. */
	public static function create( $cid ) {
		if ( get_post_meta( $cid, '_sdsc_zoom_link', true ) ) { return true; }
		$list = SDSC_Schedule::sessions( $cid );
		if ( ! $list ) { return new WP_Error( 'zoom', 'Fill in the class timetable first — the meeting needs the class days and times.' ); }
		$n = self::pick_account( $cid );
		if ( ! $n ) { return new WP_Error( 'zoom', 'Both Zoom accounts already have a class at these times. Change the class time or free an account.' ); }
		$tok = self::token( $n );
		if ( is_wp_error( $tok ) ) { return $tok; }
		$first = $list[0];
		$mins  = max( 30, (int) round( ( strtotime( $first['end'] ) - strtotime( $first['start'] ) ) / 60 ) );
		$code  = (string) get_post_meta( $cid, '_sdsc_course_code', true );
		$name  = html_entity_decode( (string) get_the_title( $cid ), ENT_QUOTES, 'UTF-8' );
		$body  = array(
			'topic'      => mb_substr( "Sam's Driving School — " . $name . ( $code && false === strpos( $name, $code ) ? ' (' . $code . ')' : '' ), 0, 190 ),
			'type'       => 3,              // recurring, no fixed time: one link for every session
			'duration'   => $mins,
			'timezone'   => wp_timezone_string(),
			'agenda'     => 'Driver education class. ' . count( $list ) . ' sessions from ' . wp_date( 'M j', strtotime( $first['start'] ) ) . '. Camera and microphone on for the whole class.',
			'settings'   => array(
				'host_video'        => true,
				'participant_video' => true,
				'join_before_host'  => false,
				'waiting_room'      => true,
				'mute_upon_entry'   => true,
				'approval_type'     => 2,
				'meeting_authentication' => false,
				'auto_recording'    => 'none',
			),
		);
		$r = wp_remote_post( 'https://api.zoom.us/v2/users/me/meetings', array(
			'timeout' => 25,
			'headers' => array( 'Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $r ) ) { return $r; }
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 201 !== (int) wp_remote_retrieve_response_code( $r ) || empty( $j['join_url'] ) ) {
			return new WP_Error( 'zoom', 'Zoom did not make the meeting' . ( ! empty( $j['message'] ) ? ': ' . $j['message'] : '.' ) );
		}
		update_post_meta( $cid, '_sdsc_zoom_link', esc_url_raw( $j['join_url'] ) );
		update_post_meta( $cid, '_sdsc_zoom_meeting_id', (string) $j['id'] );
		update_post_meta( $cid, '_sdsc_zoom_passcode', (string) ( $j['password'] ?? '' ) );
		update_post_meta( $cid, '_sdsc_zoom_acct_n', $n );
		if ( ! get_post_meta( $cid, '_sdsc_zoom_account', true ) ) { update_post_meta( $cid, '_sdsc_zoom_account', 'account_' . $n ); }
		update_post_meta( $cid, '_sdsc_zoom_made', array( 'at' => current_time( 'mysql' ), 'by' => is_user_logged_in() ? wp_get_current_user()->display_name : 'automatic' ) );
		return true;
	}

	protected static function wanted( $cid ) {
		return self::on() && 'off' !== get_post_meta( $cid, '_sdsc_zoom_auto', true ) && self::open( $cid ) && ! get_post_meta( $cid, '_sdsc_zoom_link', true );
	}

	public static function on_save( $cid, $post ) {
		if ( wp_is_post_revision( $cid ) || 'publish' !== $post->post_status ) { return; }
		if ( isset( $_POST['sdsc_zoom_auto_box'] ) ) { // phpcs:ignore -- saved with the class form (its own nonce is checked by the class screen)
			update_post_meta( $cid, '_sdsc_zoom_auto', empty( $_POST['sdsc_zoom_auto'] ) ? 'off' : '' ); // phpcs:ignore
		}
		if ( self::wanted( $cid ) ) {
			$r = self::create( $cid );
			if ( is_wp_error( $r ) ) { set_transient( 'sdsc_zoom_err_' . get_current_user_id(), $r->get_error_message(), 120 ); }
		}
	}

	public static function run() {
		foreach ( get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'publish', 'numberposts' => 60, 'fields' => 'ids' ) ) as $cid ) {
			if ( self::wanted( $cid ) ) { self::create( $cid ); }
		}
	}

	/* ------------------------------------------------------------ e-mail to students */

	public static function recipients( $cid ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT first_name, email, parent_email FROM ' . SDSC_Registration::table() . " WHERE class_id = %d AND source <> 'demo'", $cid ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			foreach ( array( $r['email'], $r['parent_email'] ) as $e ) {
				$e = strtolower( trim( (string) $e ) );
				if ( is_email( $e ) && ! isset( $out[ $e ] ) ) { $out[ $e ] = trim( (string) $r['first_name'] ); }
			}
		}
		return $out;
	}

	/** Meeting ID the way Zoom prints it: 920 6636 7353 (11 digits) or 123 456 7890 (10). */
	public static function fmt_id( $id ) {
		$d = preg_replace( '/\D/', '', (string) $id );
		if ( 11 === strlen( $d ) ) { return substr( $d, 0, 3 ) . ' ' . substr( $d, 3, 4 ) . ' ' . substr( $d, 7 ); }
		if ( 10 === strlen( $d ) ) { return substr( $d, 0, 3 ) . ' ' . substr( $d, 3, 3 ) . ' ' . substr( $d, 6 ); }
		return trim( chunk_split( $d, 4, ' ' ) );
	}

	/** The welcome / reminder e-mail (HTML) for one student of a class: every unit, Zoom details, the forms reminder. */
	public static function welcome_html( $cid, $firstn = '' ) {
		$link  = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		$id    = (string) get_post_meta( $cid, '_sdsc_zoom_meeting_id', true );
		$pass  = (string) get_post_meta( $cid, '_sdsc_zoom_passcode', true );
		$list  = SDSC_Schedule::sessions( $cid );
		$sch   = SDSC_Schedule::sched( $cid );
		$f     = 'font-family:Arial,Helvetica,sans-serif;';
		$ts    = function ( $x ) { return class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::ts( $x ) : strtotime( $x ); };
		$first = $list ? $ts( $list[0]['start'] ) : 0;
		$lastd = $list ? $ts( end( $list )['start'] ) : 0;
		$tfrom = $first ? wp_date( 'g:i A', $first ) : '';
		$tto   = $list ? wp_date( 'g:i A', $ts( $list[0]['end'] ) ) : '';
		$dn    = array( 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' );
		$dl    = array(); foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $w ) { if ( in_array( $w, array_map( 'intval', (array) $sch['days'] ), true ) ) { $dl[] = $dn[ $w ]; } }
		$forms = get_page_by_path( 'registration-forms-new' ) ?: get_page_by_path( 'registration-forms' ) ?: get_page_by_path( 'register-course' );
		$furl  = $forms ? get_permalink( $forms ) : home_url( '/register-course/' );
		$p     = function ( $html, $extra = '' ) use ( $f ) { return '<p style="' . $f . 'margin:0 0 12px;font-size:15px;line-height:1.6;' . $extra . '">' . $html . '</p>'; };
		$units = '';
		foreach ( $list as $x ) { $units .= '<tr><td style="' . $f . 'padding:3px 14px 3px 0;font-size:14px;white-space:nowrap">' . esc_html( wp_date( 'F j, Y g:i A', $ts( $x['start'] ) ) ) . '</td><td style="' . $f . 'padding:3px 0;font-size:14px;color:#5b646d">(unit ' . (int) $x['n'] . ')</td></tr>'; }
		$zoom  = $link ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;border-collapse:collapse;border-radius:12px;background:#f7f9fa;width:100%">'
			. '<tr><td style="' . $f . 'padding:10px 14px;font-size:14px;color:#5b646d;width:120px">Join Zoom</td><td style="' . $f . 'padding:10px 14px;font-size:14px"><a href="' . esc_url( $link ) . '" style="color:#00703c;font-weight:700;word-break:break-all">' . esc_html( $link ) . '</a></td></tr>'
			. ( $id ? '<tr><td style="' . $f . 'padding:6px 14px;font-size:14px;color:#5b646d">Meeting ID</td><td style="' . $f . 'padding:6px 14px;font-size:14px"><b>' . esc_html( self::fmt_id( $id ) ) . '</b></td></tr>' : '' )
			. ( $pass ? '<tr><td style="' . $f . 'padding:6px 14px 10px;font-size:14px;color:#5b646d">Passcode</td><td style="' . $f . 'padding:6px 14px 10px;font-size:14px"><b>' . esc_html( $pass ) . '</b></td></tr>' : '' )
			. '</table>' : $p( 'The Zoom link will be e-mailed to you before the first session.', 'font-size:14px' );
		return $p( 'Dear ' . esc_html( $firstn ? $firstn : 'Student' ) . ',' )
			. $p( 'We want to welcome you to Sam’s Driving School!' )
			. $p( 'This e-mail is a friendly reminder that you are enrolled in the <b>Driver Education Course</b>' . ( $first ? ' with a class beginning <b>' . esc_html( wp_date( 'F j, Y', $first ) ) . '</b> from <b>' . esc_html( $tfrom ) . '</b> to <b>' . esc_html( $tto ) . '</b>' : '' ) . '.' )
			. $p( '<b>Topic:</b> Driver Education' . ( $first ? '<br><b>Time:</b> From ' . esc_html( wp_date( 'F j, Y g:i A', $first ) ) . ' Eastern Time (US and Canada) to ' . esc_html( wp_date( 'F j, Y', $lastd ) ) . ', ' . count( $list ) . ' meetings' . ( $dl ? ' (' . esc_html( implode( ', ', $dl ) ) . ')' : '' ) : '' ) )
			. ( $units ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;border-collapse:collapse">' . $units . '</table>' : '' )
			. $zoom
			. '<p style="' . $f . 'margin:0 0 6px;font-size:14px;font-weight:800;color:#b32d2e;letter-spacing:.5px">IMPORTANT</p>'
			. $p( 'Please click the link below and fill out the forms with the correct information. Make sure to select the course you registered for — morning or evening. If you are under the age of 18, a parent or guardian’s signature is required. Please sign and submit the forms before the class starts' . ( $first ? ' on <b>' . esc_html( wp_date( 'F j, Y', $first ) ) . '</b>' : '' ) . '.', 'font-size:14px' )
			. $p( '<a href="' . esc_url( $furl ) . '" style="color:#00703c;font-weight:700">' . esc_html( $furl ) . '</a>', 'font-size:14px' )
			. $p( '<b>No Zoom account is needed.</b> On a computer, click the link (or choose “Join from your browser”). On a phone or tablet, install the free Zoom app first. Join with your camera and microphone on, sitting at a desk, and use your full name so we can let you in from the waiting room. <b>The first session is mandatory</b> (MVA rule). You will also get a reminder the day before and one hour before each session.', 'font-size:14px' )
			. $p( 'Questions? Call or text (301) 726-3030.', 'font-size:14px' )
			. $p( 'Thanks,<br>Best regards,<br>Sam’s Driving School', 'font-size:14px' );
	}

	public static function subject( $cid ) { return 'Welcome to your Driver Education class — ' . SDSC_Schedule::class_name( $cid ); }

	/**
	 * The automatic send (24 h before the class, paid students only — class-email.php) builds a short plain-text
	 * e-mail. Replace its body and subject with the welcome e-mail above, so both routes send the same thing.
	 */
	public static function upgrade_auto_mail( $a ) {
		if ( ! is_array( $a ) || 0 !== strpos( (string) ( $a['subject'] ?? '' ), 'Your Zoom class link — ' ) || false === strpos( (string) ( $a['message'] ?? '' ), 'Here is everything for your class' ) ) { return $a; }
		if ( ! preg_match( '/\(class ([^)]+)\):/', (string) $a['message'], $m ) ) { return $a; }
		$cid = (int) get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sdsc_course_code', 'meta_value' => trim( $m[1] ) ) )[0] ?? 0;
		if ( ! $cid ) { return $a; }
		$first = preg_match( '/^Hi ([^,\n]*),/', (string) $a['message'], $n ) ? trim( preg_replace( '/\s.*$/', '', trim( $n[1] ) ) ) : '';
		$a['subject'] = self::subject( $cid );
		$a['message'] = self::welcome_html( $cid, $first );
		$h = is_array( $a['headers'] ?? null ) ? $a['headers'] : array_filter( explode( "\n", (string) ( $a['headers'] ?? '' ) ) );
		$h[] = 'Content-Type: text/html; charset=UTF-8';
		$h[] = 'X-SDSC-From: info';
		$a['headers'] = $h;
		return $a;
	}

	public static function send( $cid ) {
		$link = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		if ( ! $link ) { return new WP_Error( 'zoom', 'This class has no Zoom link yet.' ); }
		$to = self::recipients( $cid );
		if ( ! $to ) { return new WP_Error( 'zoom', 'No students are registered in this class yet.' ); }
		$sent = 0;
		foreach ( $to as $email => $firstn ) {
			if ( SDSC_Mailer::send( $email, self::subject( $cid ), self::welcome_html( $cid, $firstn ), 'info' ) ) { $sent++; }
		}
		update_post_meta( $cid, '_sdsc_zoom_sent', array( 'at' => current_time( 'mysql' ), 'by' => wp_get_current_user()->display_name, 'n' => $sent ) );
		if ( class_exists( 'SDSC_Board' ) ) { SDSC_Board::note( 'Zoom details e-mailed to ' . $sent . ' address(es) — ' . $name ); }
		return $sent;
	}

	/* ------------------------------------------------------------ screens */

	public static function metabox() {
		add_meta_box( 'sdsc-zoomauto', 'Zoom meeting', array( __CLASS__, 'box' ), 'sdsc_class', 'side', 'high' );
	}

	public static function box( $post ) {
		$cid  = (int) $post->ID;
		$link = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		$id   = (string) get_post_meta( $cid, '_sdsc_zoom_meeting_id', true );
		$pass = (string) get_post_meta( $cid, '_sdsc_zoom_passcode', true );
		$n    = (int) get_post_meta( $cid, '_sdsc_zoom_acct_n', true );
		$sent = get_post_meta( $cid, '_sdsc_zoom_sent', true );
		echo '<input type="hidden" name="sdsc_zoom_auto_box" value="1">';
		echo '<p><label><input type="checkbox" name="sdsc_zoom_auto" value="1"' . checked( 'off' !== get_post_meta( $cid, '_sdsc_zoom_auto', true ), true, false ) . '> Make the Zoom meeting automatically</label></p>';
		if ( $link ) {
			echo '<p><b>Link:</b> <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $link ) . '</a></p>';
			if ( $id ) { echo '<p><b>Meeting ID:</b> ' . esc_html( self::fmt_id( $id ) ) . ( $pass ? '<br><b>Passcode:</b> ' . esc_html( $pass ) : '' ) . ( $n ? '<br><b>Account:</b> ' . esc_html( SDSC_Api::zoom_label( $n ) ) : '' ) . '</p>'; }
		} else {
			echo '<p>No Zoom link yet. ' . ( SDSC_Schedule::sessions( $cid ) ? 'It is made when the class is saved.' : 'Fill in the timetable, then save.' ) . '</p>';
		}
		if ( ! self::can() ) { return; }
		if ( ! $link ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_zoom_create&c=' . $cid ), 'sdsc_zoom_create_' . $cid ) ) . '">Make the Zoom meeting now</a></p>';
		} else {
			$c = count( self::recipients( $cid ) );
			echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_zoom_send&c=' . $cid ), 'sdsc_zoom_send_' . $cid ) ) . '" onclick="return confirm(\'E-mail the Zoom link and details to ' . (int) $c . ' student and parent address(es)?\')">E-mail Zoom details to students (' . (int) $c . ')</a></p>';
			if ( is_array( $sent ) ) { echo '<p class="description">Last sent ' . esc_html( mysql2date( 'M j, g:i a', $sent['at'] ) . ' by ' . $sent['by'] . ' to ' . (int) $sent['n'] ) . '.</p>'; }
		}
	}

	protected static function back( $cid, $msg, $ok ) {
		set_transient( 'sdsc_zoom_' . ( $ok ? 'ok_' : 'err_' ) . get_current_user_id(), $msg, 120 );
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : admin_url( 'post.php?post=' . (int) $cid . '&action=edit' ) );
		exit;
	}

	public static function h_create() {
		$cid = absint( $_GET['c'] ?? 0 ); // phpcs:ignore
		if ( ! self::can() ) { wp_die( 'Only the administrator and the school manager can do this.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_zoom_create_' . $cid );
		$r = self::create( $cid );
		self::back( $cid, is_wp_error( $r ) ? $r->get_error_message() : 'Zoom meeting made: ' . get_post_meta( $cid, '_sdsc_zoom_link', true ), ! is_wp_error( $r ) );
	}

	public static function h_send() {
		$cid = absint( $_GET['c'] ?? 0 ); // phpcs:ignore
		if ( ! self::can() ) { wp_die( 'Only the administrator and the school manager can do this.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_zoom_send_' . $cid );
		$r = self::send( $cid );
		self::back( $cid, is_wp_error( $r ) ? $r->get_error_message() : 'Zoom details e-mailed to ' . (int) $r . ' address(es).', ! is_wp_error( $r ) );
	}

	public static function notice() {
		foreach ( array( 'ok' => 'success', 'err' => 'error' ) as $k => $cls ) {
			$m = get_transient( 'sdsc_zoom_' . $k . '_' . get_current_user_id() );
			if ( $m ) { delete_transient( 'sdsc_zoom_' . $k . '_' . get_current_user_id() ); echo '<div class="notice notice-' . esc_attr( $cls ) . ' is-dismissible"><p><b>Zoom:</b> ' . esc_html( $m ) . '</p></div>'; }
		}
	}

	/* ------------------------------------------------------------ the instructor starts the class as host */

	/** The session (by date) or null. */
	protected static function session( $cid, $date ) {
		foreach ( SDSC_Schedule::sessions( $cid ) as $x ) { if ( $x['date'] === $date ) { return $x; } }
		return null;
	}

	/**
	 * Who may start the class as host: the instructor of that session from one hour before it starts until it
	 * ends, and the administrator or school manager at any time. Nobody needs the Zoom account's password: the
	 * website asks Zoom for a fresh host link (valid about two hours) at the moment of the click and never stores
	 * or e-mails it.
	 */
	public static function can_host( $cid, $date ) {
		if ( ! is_user_logged_in() || ! get_post_meta( $cid, '_sdsc_zoom_meeting_id', true ) ) { return false; }
		if ( self::can() ) { return true; }
		$x = self::session( $cid, $date );
		if ( ! $x || ! class_exists( 'SDSC_ZoomRoll' ) || SDSC_ZoomRoll::instructor_for( $cid, $date ) !== get_current_user_id() ) { return false; }
		$now = time();
		$st = SDSC_Lessons::ts( $x['start'] );
		$until = max( SDSC_Lessons::ts( $x['end'] ) + 30 * MINUTE_IN_SECONDS, $st + 3 * HOUR_IN_SECONDS ); // host access lasts at least 3 hours from the start
		return $now >= $st - HOUR_IN_SECONDS && $now <= $until;
	}

	public static function host_button( $cid, $date ) {
		if ( ! get_post_meta( $cid, '_sdsc_zoom_meeting_id', true ) ) { return ''; }
		$x = self::session( $cid, $date );
		if ( ! $x || ! class_exists( 'SDSC_ZoomRoll' ) ) { return ''; }
		$mine = SDSC_ZoomRoll::instructor_for( $cid, $date ) === get_current_user_id();
		if ( ! $mine && ! self::can() ) { return ''; }
		if ( ! self::can_host( $cid, $date ) ) {
			return '<span class="szr-m" style="display:block">The <b>Start class</b> button opens here one hour before the class.</span>';
		}
		$u = wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_zoom_start&c=' . (int) $cid . '&s=' . rawurlencode( $date ) ), 'sdsc_zoom_start_' . $cid . '_' . $date );
		return '<a class="szr-btn" href="' . esc_url( $u ) . '" target="_blank" rel="noopener">▶ Start class (host)</a>';
	}

	public static function h_start() {
		$cid  = absint( $_GET['c'] ?? 0 ); // phpcs:ignore
		$date = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore
		check_admin_referer( 'sdsc_zoom_start_' . $cid . '_' . $date );
		if ( ! self::can_host( $cid, $date ) ) { wp_die( 'This button works for the instructor of this session, from one hour before the class until three hours after it starts (or 30 minutes after it ends, if later).', '', array( 'response' => 403 ) ); }
		$n   = (int) get_post_meta( $cid, '_sdsc_zoom_acct_n', true ) ?: ( self::chosen( $cid ) ?: 1 );
		$tok = self::token( $n );
		$err = is_wp_error( $tok ) ? $tok->get_error_message() : '';
		$url = '';
		if ( ! $err ) {
			$r = wp_remote_get( 'https://api.zoom.us/v2/meetings/' . rawurlencode( (string) get_post_meta( $cid, '_sdsc_zoom_meeting_id', true ) ), array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $tok ) ) );
			$j = is_wp_error( $r ) ? array() : json_decode( wp_remote_retrieve_body( $r ), true );
			$url = (string) ( $j['start_url'] ?? '' );
			if ( ! $url ) { $err = is_wp_error( $r ) ? $r->get_error_message() : ( $j['message'] ?? 'Zoom did not give a host link.' ); }
		}
		if ( $err || 0 !== strpos( $url, 'https://' ) ) { wp_die( 'Could not start the Zoom class: ' . esc_html( $err ) . ' Please call the office.', '', array( 'response' => 502 ) ); }
		$log   = (array) get_post_meta( $cid, '_sdsc_zoom_starts', true );
		$log[] = array( 'date' => $date, 'by' => get_current_user_id(), 'at' => current_time( 'mysql' ) );
		update_post_meta( $cid, '_sdsc_zoom_starts', array_slice( $log, -60 ) );
		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore -- Zoom's own host link, fetched just now
		exit;
	}

	/** Buttons for the class board column. */
	public static function board_html( $cid ) {
		if ( ! self::can() ) { return ''; }
		$link = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		if ( ! $link ) {
			return '<p class="sdb-zoom"><a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_zoom_create&c=' . $cid ), 'sdsc_zoom_create_' . $cid ) ) . '">Make Zoom meeting</a></p>';
		}
		$c = count( self::recipients( $cid ) );
		$s = get_post_meta( $cid, '_sdsc_zoom_sent', true );
		return '<p class="sdb-zoom"><a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_zoom_send&c=' . $cid ), 'sdsc_zoom_send_' . $cid ) ) . '" onclick="return confirm(\'E-mail the Zoom link and details to ' . (int) $c . ' address(es)?\')">✉ E-mail Zoom details</a>'
			. ' <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener" class="sdb-zlink">Zoom link</a>'
			. ( is_array( $s ) ? '<br><small>Sent ' . esc_html( mysql2date( 'M j', $s['at'] ) ) . ' to ' . (int) $s['n'] . '</small>' : '' ) . '</p>';
	}
}
