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

	public static function send( $cid ) {
		$link = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		if ( ! $link ) { return new WP_Error( 'zoom', 'This class has no Zoom link yet.' ); }
		$to = self::recipients( $cid );
		if ( ! $to ) { return new WP_Error( 'zoom', 'No students are registered in this class yet.' ); }
		$id    = (string) get_post_meta( $cid, '_sdsc_zoom_meeting_id', true );
		$pass  = (string) get_post_meta( $cid, '_sdsc_zoom_passcode', true );
		$list  = SDSC_Schedule::sessions( $cid );
		$name  = SDSC_Schedule::class_name( $cid );
		$days  = (string) get_post_meta( $cid, '_sdsc_days_time', true );
		$f     = 'font-family:Arial,Helvetica,sans-serif;';
		$rows  = array(
			array( 'Class', esc_html( $name ) ),
			$list ? array( 'Dates', esc_html( wp_date( 'l, F j', strtotime( $list[0]['start'] ) ) . ' – ' . wp_date( 'l, F j, Y', strtotime( end( $list )['start'] ) ) . ' (' . count( $list ) . ' sessions)' ) ) : null,
			$days ? array( 'Days &amp; time', esc_html( $days ) ) : null,
			array( 'Zoom link', '<a href="' . esc_url( $link ) . '" style="color:#00703c;font-weight:700">' . esc_html( $link ) . '</a>' ),
			$id ? array( 'Meeting ID', esc_html( trim( chunk_split( $id, 4, ' ' ) ) ) ) : null,
			$pass ? array( 'Passcode', esc_html( $pass ) ) : null,
		);
		$table = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;border-collapse:collapse;border-radius:12px;background:#f7f9fa">';
		foreach ( array_filter( $rows ) as $r ) {
			$table .= '<tr><td style="' . $f . 'padding:8px 14px;font-size:13px;color:#5b646d;width:120px;vertical-align:top">' . $r[0] . '</td><td style="' . $f . 'padding:8px 14px;font-size:14px;color:#15191d">' . $r[1] . '</td></tr>';
		}
		$table .= '</table>';
		$sent = 0;
		foreach ( $to as $email => $first ) {
			$body = '<p style="' . $f . 'margin:0 0 14px;font-size:15px;line-height:1.6">Hi ' . esc_html( $first ? $first : 'there' ) . ',</p>'
				. '<p style="' . $f . 'margin:0 0 14px;font-size:15px;line-height:1.6">Here is the Zoom information for your driver education class. The same link works for every session.</p>'
				. $table
				. '<p style="' . $f . 'margin:0 0 10px;font-size:14px;line-height:1.6"><b>No Zoom account is needed.</b> On a computer, click the link: it opens the free Zoom app (or choose “Join from your browser”). On a phone or tablet, install the free <b>Zoom Workplace</b> app first, then tap the link.</p>'
				. '<p style="' . $f . 'margin:0 0 10px;font-size:14px;line-height:1.6"><b>Before class:</b> join from a computer or tablet with your camera and microphone on, sitting at a desk, and use your full name so we can let you in from the waiting room.</p>'
				. '<p style="' . $f . 'margin:0 0 10px;font-size:14px;line-height:1.6"><b>The first session is mandatory</b> (MVA rule). You will also get a reminder the day before and one hour before each session.</p>'
				. '<p style="' . $f . 'margin:0;font-size:14px">Questions? Call or text (301) 726-3030.</p>';
			if ( SDSC_Mailer::send( $email, 'Your Zoom class link — ' . $name, $body, 'info' ) ) { $sent++; }
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
			if ( $id ) { echo '<p><b>Meeting ID:</b> ' . esc_html( trim( chunk_split( $id, 4, ' ' ) ) ) . ( $pass ? '<br><b>Passcode:</b> ' . esc_html( $pass ) : '' ) . ( $n ? '<br><b>Account:</b> ' . esc_html( SDSC_Api::zoom_label( $n ) ) : '' ) . '</p>'; }
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
		return $now >= SDSC_Lessons::ts( $x['start'] ) - HOUR_IN_SECONDS && $now <= SDSC_Lessons::ts( $x['end'] ) + 30 * MINUTE_IN_SECONDS;
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
		if ( ! self::can_host( $cid, $date ) ) { wp_die( 'This button works for the instructor of this session, from one hour before the class until it ends.', '', array( 'response' => 403 ) ); }
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
