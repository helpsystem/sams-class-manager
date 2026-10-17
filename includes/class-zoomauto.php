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
		add_filter( 'pre_wp_mail', array( __CLASS__, 'gate_auto_mail' ), 5, 2 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'auto_mail_failed' ) );
		add_action( 'admin_post_sdsc_zoom_one', array( __CLASS__, 'h_one' ) );
		add_action( 'admin_post_sdsc_zoom_text', array( __CLASS__, 'h_text' ) );
		add_action( 'template_redirect', array( __CLASS__, 'front' ), 0 );
		add_action( 'sdsc_refunded', array( __CLASS__, 'on_refund' ), 10, 2 ); // a fully refunded student loses the personal Zoom link
	}

	/* ------------------------------------------------------------ who got the Zoom details (per address) */

	const MAILLOG = '_sdsc_zoom_mail'; // address or "sms:+1…" => [ at, ok, how (manual|auto|text), by ]

	public static function mail_log( $cid ) { $l = get_post_meta( $cid, self::MAILLOG, true ); return is_array( $l ) ? $l : array(); }

	public static function log_mail( $cid, $key, $ok, $how ) {
		$l = self::mail_log( $cid );
		$l[ strtolower( trim( (string) $key ) ) ] = array( 'at' => current_time( 'mysql' ), 'ok' => $ok ? 1 : 0, 'how' => $how, 'by' => is_user_logged_in() ? wp_get_current_user()->display_name : 'automatic' );
		update_post_meta( $cid, self::MAILLOG, $l );
	}

	/** A bounce ("undeliverable", "delivery failed") for this address that arrived in the school inbox after $since. */
	public static function bounced( $email, $since ) {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Inbox' ) || ! is_email( $email ) ) { return ''; }
		$t = SDSC_Inbox::table();
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { return ''; }
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT sent_at FROM $t WHERE kind = 'in' AND sent_at >= %s AND subject REGEXP %s AND (body_text LIKE %s OR body_html LIKE %s) ORDER BY id DESC LIMIT 1", $since, 'undeliver|delivery (status|failure)|mail delivery failed|returned mail|could not be delivered|delivery has failed', '%' . $wpdb->esc_like( $email ) . '%', '%' . $wpdb->esc_like( $email ) . '%' ), ARRAY_A ); // phpcs:ignore
		return $r ? $r['sent_at'] : '';
	}

	/** Per-class switch for the automatic send 24 h before the first session (default on; the office can turn it off). */
	public static function auto_mail_on( $cid ) { return 'off' !== (string) get_post_meta( $cid, '_sdsc_zoom_auto_mail', true ); }

	/** The old automatic e-mail (class-email.php) is skipped for classes where the office turned it off. */
	public static function gate_auto_mail( $pre, $a ) {
		if ( null !== $pre || ! is_array( $a ) || 0 !== strpos( (string) ( $a['subject'] ?? '' ), 'Your Zoom class link — ' ) ) { return $pre; }
		if ( ! preg_match( '/\(class ([^)]+)\):/', (string) ( $a['message'] ?? '' ), $m ) ) { return $pre; }
		$cid = (int) get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sdsc_course_code', 'meta_value' => trim( $m[1] ) ) )[0] ?? 0;
		return ( $cid && ! self::auto_mail_on( $cid ) ) ? false : $pre;
	}

	public static function auto_mail_failed( $e ) {
		$d = is_wp_error( $e ) ? (array) $e->get_error_data() : array();
		if ( 0 !== strpos( (string) ( $d['subject'] ?? '' ), 'Welcome to your Driver Education class — ' ) ) { return; }
		$cid = (int) ( $GLOBALS['sdsc_zoom_mail_cid'] ?? 0 );
		if ( ! $cid ) { return; }
		foreach ( (array) ( $d['to'] ?? array() ) as $to ) { self::log_mail( $cid, $to, false, 'auto' ); }
	}

	/* ------------------------------------------------------------ the student's own page with the Zoom details (short link, for texts) */

	protected static function b64( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }
	protected static function sig( $s ) { return substr( hash_hmac( 'sha256', $s, wp_salt( 'nonce' ) . 'sdsc-zoom' ), 0, 16 ); }

	public static function page_url( $cid, $email ) {
		$email = strtolower( trim( (string) $email ) );
		$u = add_query_arg( 'sdsc_zoom', self::b64( (int) $cid . '|' . $email ) . '.' . self::sig( 'zoom|' . (int) $cid . '|' . $email ), home_url( '/' ) );
		return class_exists( 'SDSC_Short' ) ? SDSC_Short::make( $u ) : $u;
	}

	public static function front( $return = false ) {
		if ( ! isset( $_GET['sdsc_zoom'] ) ) { return ''; } // phpcs:ignore
		$tok = sanitize_text_field( wp_unslash( $_GET['sdsc_zoom'] ) ); // phpcs:ignore
		$cid = 0; $email = '';
		if ( preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{16})$/', $tok, $m ) ) {
			$raw = (string) base64_decode( strtr( $m[1], '-_', '+/' ) );
			if ( preg_match( '/^(\d+)\|(.+)$/', $raw, $mm ) && hash_equals( self::sig( 'zoom|' . (int) $mm[1] . '|' . strtolower( $mm[2] ) ), $m[2] ) ) { $cid = (int) $mm[1]; $email = strtolower( $mm[2] ); }
		}
		if ( ! $return ) { nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow' ); }
		$GLOBALS['sdsc_zoom_live'] = 1; // the page itself: no "open live page" line
		$ok   = $cid && 'sdsc_class' === get_post_type( $cid ) && isset( self::recipients( $cid )[ $email ] );
		$name = $ok ? self::recipients( $cid )[ $email ] : '';
		$body = $ok ? self::welcome_html( $cid, $name, $email ) : '<p style="font-family:Arial,Helvetica,sans-serif;font-size:16px">This link is not valid any more. Please use the link from your latest text or e-mail, or call (301) 726-3030.</p>';
		$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $ok ? 'Your class details — Sam’s Driving School' : 'Link not valid' ) . '</title>'
			. '<style>body{margin:0;padding:20px 14px;background:#f6f4ee;color:#15191d}.c{max-width:640px;margin:0 auto;background:#fff;border-radius:16px;padding:22px 20px;box-shadow:0 2px 12px rgba(0,0,0,.06)}.c img{max-width:100%}.c table{max-width:100%}h2{font-family:Arial,Helvetica,sans-serif;font-size:17px;margin:0 0 14px;color:#00703c}</style></head><body><div class="c"><h2>Sam’s Driving School — your class details</h2>' . $body . '</div></body></html>';
		if ( $return ) { return $html; }
		echo $html; // phpcs:ignore -- built from escaped pieces
		exit;
	}

	/** Registrations of the class with both addresses and phones, for the "who got it" list. */
	public static function people( $cid ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, first_name, last_name, email, parent_email, phone, parent_phone, parent_name FROM ' . SDSC_Registration::table() . " WHERE class_id = %d AND source <> 'demo' ORDER BY last_name, first_name", $cid ), ARRAY_A ); // phpcs:ignore
	}

	/** The list: every address and phone of the class, what was sent when, bounces, and buttons to e-mail or text one person. */
	public static function who_html( $cid ) {
		if ( ! self::can() ) { return ''; }
		$rows = self::people( $cid );
		if ( ! $rows ) { return '<p class="description">No students in this class yet.</p>'; }
		$log  = self::mail_log( $cid );
		$sms  = class_exists( 'SDSC_SMS' );
		$st   = function ( $key, $email = '' ) use ( $log ) {
			$e = $log[ strtolower( $key ) ] ?? null;
			if ( ! $e ) { return '<span style="color:#8c8f94">not sent</span>'; }
			$b = $email ? self::bounced( $email, $e['at'] ) : '';
			if ( $b ) { return '<span style="color:#b32d2e;font-weight:700">bounced</span> <small>' . esc_html( mysql2date( 'M j g:i a', $b ) ) . '</small>'; }
			return ( $e['ok'] ? '<span style="color:#00703c;font-weight:700">✓ sent</span>' : '<span style="color:#b32d2e;font-weight:700">failed</span>' ) . ' <small>' . esc_html( mysql2date( 'M j g:i a', $e['at'] ) . ( 'auto' === $e['how'] ? ' (automatic)' : ( 'text' === $e['how'] ? ' (text)' : ' by ' . $e['by'] ) ) ) . '</small>';
		};
		$act  = function ( $a, $args, $label, $title ) use ( $cid ) { return '<a class="button button-small" title="' . esc_attr( $title ) . '" href="' . esc_url( wp_nonce_url( add_query_arg( $args + array( 'action' => $a, 'c' => $cid ), admin_url( 'admin-post.php' ) ), $a . '_' . $cid ) ) . '">' . $label . '</a>'; };
		$h = '<table class="widefat striped" style="font-size:12px"><thead><tr><th>Student</th><th>Address / phone</th><th>Zoom details</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$cells = array();
			foreach ( array( array( $r['email'], 'student' ), array( $r['parent_email'], 'parent' ) ) as $x ) {
				$e = strtolower( trim( (string) $x[0] ) );
				if ( ! is_email( $e ) ) { continue; }
				$cells[] = array( '<span style="color:#50575e">' . $x[1] . '</span> ' . esc_html( $e ), $st( $e, $e ), $act( 'sdsc_zoom_one', array( 'e' => $e ), '✉ E-mail', 'E-mail the Zoom details to this address now' ) );
			}
			foreach ( array( array( $r['phone'], 'student' ), array( $r['parent_phone'], 'parent' ) ) as $x ) {
				$ph = preg_replace( '/\D/', '', (string) $x[0] );
				if ( strlen( $ph ) < 10 ) { continue; }
				$cells[] = array( '<span style="color:#50575e">' . $x[1] . '</span> ' . esc_html( $x[0] ), $st( 'sms:' . $ph ), $sms ? $act( 'sdsc_zoom_text', array( 'p' => $ph, 'r' => (int) $r['id'] ), '💬 Text link', 'Text this phone a short link to a page with the class and Zoom details' ) : '' );
			}
			if ( ! $cells ) { $cells[] = array( '<span style="color:#b32d2e">no e-mail or phone on the record</span>', '', '' ); }
			$first = true;
			foreach ( $cells as $c ) {
				$h .= '<tr>' . ( $first ? '<td rowspan="' . count( $cells ) . '"><b>' . esc_html( $r['first_name'] . ' ' . $r['last_name'] ) . '</b><br><a href="' . esc_url( admin_url( 'admin.php?page=sdsc-registrations&view=' . (int) $r['id'] ) ) . '">record</a></td>' : '' ) . '<td>' . $c[0] . '</td><td>' . $c[1] . '</td><td>' . $c[2] . '</td></tr>';
				$first = false;
			}
		}
		$h .= '</tbody></table><p class="description" style="margin:6px 0 0">“Sent” means our mail server accepted the message; “bounced” means the other side returned it (seen in School e-mails). A student who sees nothing should check Spam / Junk. The text link opens a page with the same details.</p>';
		return $h;
	}

	/** E-mail the Zoom details to one address (button in the list). */
	public static function send_one( $cid, $email ) {
		$email = strtolower( trim( (string) $email ) );
		$to    = self::recipients( $cid );
		if ( ! get_post_meta( $cid, '_sdsc_zoom_link', true ) ) { return new WP_Error( 'zoom', 'This class has no Zoom link yet.' ); }
		if ( ! isset( $to[ $email ] ) ) { return new WP_Error( 'zoom', 'That address is not on a registration of this class.' ); }
		$ok = SDSC_Mailer::send( $email, self::subject( $cid ), self::welcome_html( $cid, $to[ $email ], $email ), 'info' );
		self::log_mail( $cid, $email, $ok, 'manual' );
		return $ok ? true : new WP_Error( 'zoom', 'The mail server did not accept the message. See Sam’s School → Email → log.' );
	}

	public static function h_one() {
		$cid = absint( $_GET['c'] ?? 0 ); // phpcs:ignore
		if ( ! self::can() ) { wp_die( 'Only the administrator and the school manager can do this.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_zoom_one_' . $cid );
		$e = sanitize_email( wp_unslash( $_GET['e'] ?? '' ) ); // phpcs:ignore
		$r = self::send_one( $cid, $e );
		self::back( $cid, is_wp_error( $r ) ? $r->get_error_message() : 'Zoom details e-mailed to ' . $e . '.', ! is_wp_error( $r ) );
	}

	public static function h_text() {
		$cid = absint( $_GET['c'] ?? 0 ); // phpcs:ignore
		if ( ! self::can() ) { wp_die( 'Only the administrator and the school manager can do this.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_zoom_text_' . $cid );
		if ( ! class_exists( 'SDSC_SMS' ) ) { self::back( $cid, 'Texts are not set up.', false ); }
		$ph  = preg_replace( '/\D/', '', (string) ( $_GET['p'] ?? '' ) ); // phpcs:ignore
		$rid = absint( $_GET['r'] ?? 0 ); // phpcs:ignore
		$reg = null;
		foreach ( self::people( $cid ) as $x ) { if ( (int) $x['id'] === $rid ) { $reg = $x; } }
		if ( ! $reg || ! in_array( $ph, array( preg_replace( '/\D/', '', (string) $reg['phone'] ), preg_replace( '/\D/', '', (string) $reg['parent_phone'] ) ), true ) ) { self::back( $cid, 'That phone is not on a registration of this class.', false ); }
		$email = is_email( (string) $reg['email'] ) ? strtolower( trim( $reg['email'] ) ) : strtolower( trim( (string) $reg['parent_email'] ) );
		if ( ! is_email( $email ) ) { self::back( $cid, 'This registration has no e-mail address, so there is no personal page to link to. Add an e-mail to the record first.', false ); }
		$url  = self::page_url( $cid, $email );
		$body = "Sam's Driving School: " . $reg['first_name'] . ", your Driver Education class details (dates, Zoom link, forms) are here: " . $url . " — also e-mailed to " . $email . " (check Spam/Junk). Questions? Call or text (301) 726-3030.";
		$res  = SDSC_SMS::deliver( $ph, $body, 'zoom:' . $cid, $reg['first_name'] . ' ' . $reg['last_name'], 'Zoom details' );
		$sent = false !== stripos( (string) $res, 'sent' ) || false !== stripos( (string) $res, 'added' );
		self::log_mail( $cid, 'sms:' . $ph, $sent, 'text' );
		self::back( $cid, 'Text: ' . $res, $sent );
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

	public static function token( $n ) {
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
		$wd = array();
		foreach ( $list as $x ) { $wd[ 1 + (int) gmdate( 'w', strtotime( $x['start'] . ' UTC' ) ) ] = 1; } // Zoom: 1 = Sunday … 7 = Saturday
		$wd = array_keys( $wd ); sort( $wd );
		$code  = (string) get_post_meta( $cid, '_sdsc_course_code', true );
		$name  = html_entity_decode( (string) get_the_title( $cid ), ENT_QUOTES, 'UTF-8' );
		$body  = array(
			'topic'      => mb_substr( "Sam's Driving School — " . $name . ( $code && false === strpos( $name, $code ) ? ' (' . $code . ')' : '' ), 0, 190 ),
			'type'       => 8,              // recurring with a fixed time: Zoom only allows personal (registrant) links on this kind, one link for every session
			'start_time' => gmdate( 'Y-m-d\TH:i:s', strtotime( $first['start'] . ' UTC' ) ), // wall-clock time of the first session, read in 'timezone'
			'recurrence' => array( 'type' => 2, 'repeat_interval' => 1, 'weekly_days' => implode( ',', $wd ), 'end_times' => min( 50, max( 1, count( $list ) ) ) ),
			'duration'   => $mins,
			'timezone'   => wp_timezone_string(),
			'agenda'     => 'Driver education class. ' . count( $list ) . ' sessions from ' . wp_date( 'M j', strtotime( $first['start'] ) ) . '. Camera and microphone on for the whole class.',
			'settings'   => array(
				'host_video'        => true,
				'participant_video' => true,
				'join_before_host'  => false,
				'waiting_room'      => true,
				'mute_upon_entry'   => true,
				'approval_type'     => 0,            // registration required, approved automatically
				'registration_type' => 1,            // register once, attend every session
				'registrants_email_notification'   => false, // Zoom itself never e-mails students (the school's own e-mail does)
				'registrants_confirmation_email'   => false,
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
		update_post_meta( $cid, '_sdsc_zoom_reg', 1 ); // every student gets a personal join link (see personal_link)
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
			update_post_meta( $cid, '_sdsc_zoom_auto_mail', empty( $_POST['sdsc_zoom_auto_mail'] ) ? 'off' : '' ); // phpcs:ignore
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

	/* ------------------------------------------------------------ personal join links (Zoom registrants) */

	const REGMAP = '_sdsc_zoom_regmap'; // class meta: email => [ registrant_id, join_url ]

	/** True when the class meeting asks for registration, so each student can have a link of their own. */
	public static function personal( $cid ) { return (bool) get_post_meta( $cid, '_sdsc_zoom_reg', true ) && get_post_meta( $cid, '_sdsc_zoom_meeting_id', true ); }

	protected static function reg_name( $email ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT first_name, last_name FROM ' . SDSC_Registration::table() . " WHERE (email = %s OR parent_email = %s) AND source <> 'demo' ORDER BY id DESC LIMIT 1", $email, $email ), ARRAY_A ); // phpcs:ignore
		return array( trim( (string) ( $r['first_name'] ?? '' ) ) ?: 'Student', trim( (string) ( $r['last_name'] ?? '' ) ) ?: '.' );
	}

	/** The student's own Zoom join link (made on first use, then remembered). Empty string when it cannot be made — callers fall back to the class link. */
	public static function personal_link( $cid, $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! $email || ! self::personal( $cid ) ) { return ''; }
		$map = get_post_meta( $cid, self::REGMAP, true ); $map = is_array( $map ) ? $map : array();
		if ( ! empty( $map[ $email ]['url'] ) ) { return (string) $map[ $email ]['url']; }
		$n = (int) get_post_meta( $cid, '_sdsc_zoom_acct_n', true );
		if ( ! $n ) { return ''; }
		$tok = self::token( $n );
		if ( is_wp_error( $tok ) ) { return ''; }
		list( $fn, $ln ) = self::reg_name( $email );
		$r = wp_remote_post( 'https://api.zoom.us/v2/meetings/' . rawurlencode( (string) get_post_meta( $cid, '_sdsc_zoom_meeting_id', true ) ) . '/registrants', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'email' => $email, 'first_name' => $fn, 'last_name' => $ln ) ),
		) );
		if ( is_wp_error( $r ) ) { return ''; }
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( empty( $j['join_url'] ) ) { return ''; }
		$map[ $email ] = array( 'id' => (string) ( $j['registrant_id'] ?? '' ), 'url' => esc_url_raw( $j['join_url'] ) );
		update_post_meta( $cid, self::REGMAP, $map );
		return $map[ $email ]['url'];
	}

	/** Take a student out of the class meeting (cancelled registration, refund). Their personal link stops working. */
	public static function unregister( $cid, $email ) {
		$email = strtolower( trim( (string) $email ) );
		$map = get_post_meta( $cid, self::REGMAP, true );
		if ( ! is_array( $map ) || empty( $map[ $email ]['id'] ) ) { return false; }
		$n = (int) get_post_meta( $cid, '_sdsc_zoom_acct_n', true );
		$tok = $n ? self::token( $n ) : '';
		if ( ! $tok || is_wp_error( $tok ) ) { return false; }
		$r = wp_remote_request( 'https://api.zoom.us/v2/meetings/' . rawurlencode( (string) get_post_meta( $cid, '_sdsc_zoom_meeting_id', true ) ) . '/registrants/status', array(
			'method'  => 'PUT',
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'action' => 'cancel', 'registrants' => array( array( 'id' => $map[ $email ]['id'], 'email' => $email ) ) ) ),
		) );
		if ( is_wp_error( $r ) || 204 !== (int) wp_remote_retrieve_response_code( $r ) ) { return false; }
		unset( $map[ $email ] );
		update_post_meta( $cid, self::REGMAP, $map );
		return true;
	}

	public static function on_refund( $reg_id, $cid ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT email, parent_email FROM ' . SDSC_Registration::table() . ' WHERE id = %d', (int) $reg_id ), ARRAY_A ); // phpcs:ignore
		if ( ! $r || ! $cid ) { return; }
		foreach ( array( $r['email'], $r['parent_email'] ) as $e ) { if ( $e ) { self::unregister( (int) $cid, $e ); } }
	}

	/** Who really joined, from Zoom's own report: [ lower-case e-mail => minutes in the meeting ] across all sessions of the class meeting. */
	public static function joined( $cid ) {
		$n = (int) get_post_meta( $cid, '_sdsc_zoom_acct_n', true );
		$id = (string) get_post_meta( $cid, '_sdsc_zoom_meeting_id', true );
		$tok = $n ? self::token( $n ) : '';
		if ( ! $id || ! $tok || is_wp_error( $tok ) ) { return array(); }
		$h = array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $tok ) );
		$ins = json_decode( wp_remote_retrieve_body( wp_remote_get( 'https://api.zoom.us/v2/past_meetings/' . rawurlencode( $id ) . '/instances', $h ) ), true );
		$out = array();
		foreach ( (array) ( $ins['meetings'] ?? array() ) as $m ) {
			$u = (string) ( $m['uuid'] ?? '' );
			if ( ! $u ) { continue; }
			$u = ( 0 === strpos( $u, '/' ) || false !== strpos( $u, '//' ) ) ? rawurlencode( rawurlencode( $u ) ) : rawurlencode( $u );
			$p = json_decode( wp_remote_retrieve_body( wp_remote_get( 'https://api.zoom.us/v2/past_meetings/' . $u . '/participants?page_size=300', $h ) ), true );
			foreach ( (array) ( $p['participants'] ?? array() ) as $x ) {
				$e = strtolower( trim( (string) ( $x['user_email'] ?? '' ) ) );
				if ( $e ) { $out[ $e ] = ( $out[ $e ] ?? 0 ) + (int) round( (int) ( $x['duration'] ?? 0 ) / 60 ); }
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ e-mail to students */

	public static function recipients( $cid ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT first_name, email, parent_email FROM ' . SDSC_Registration::table() . " WHERE class_id = %d AND source <> 'demo'", $cid ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			if ( class_exists( 'SDSC_Transfer' ) && ! SDSC_Transfer::classroom( $cid, (string) $r['email'] ) ) { continue; } // behind-the-wheel-only: no Zoom
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

	/** The e-mail address attendance is recorded under: the student's own, even when the mail goes to a parent. */
	protected static function att_email( $email ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( ! $email ) { return ''; }
		$r = $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . SDSC_Registration::table() . " WHERE (email = %s OR parent_email = %s) AND source <> 'demo' ORDER BY id DESC LIMIT 1", $email, $email ) ); // phpcs:ignore
		return $r ? strtolower( trim( (string) $r ) ) : $email;
	}

	/**
	 * The session table of the e-mail / details page. Upcoming sessions are bold, past ones are faded with what the
	 * instructor recorded (Present / Late / Left early / Absent) and the instructor's short note. The details page
	 * builds this at every visit, so it is always current; the e-mail shows the state at the moment it was sent.
	 */
	protected static function schedule_rows( $cid, $email, $list, $ts, $f ) {
		if ( ! $list ) { return ''; }
		$rows  = ( $email && class_exists( 'SDSC_ZoomRoll' ) ) ? ( SDSC_ZoomRoll::rows( $cid )[ self::att_email( $email ) ] ?? array() ) : array();
		$today = current_time( 'Y-m-d' );
		$lab   = array( 'present' => array( 'Present', '#00703c' ), 'late' => array( 'Late', '#996800' ), 'left' => array( 'Left early', '#996800' ), 'absent' => array( 'Absent', '#b32d2e' ) );
		$isp   = function ( $x ) use ( $today, $rows, $lab ) { return $x['date'] < $today || isset( $lab[ (string) ( $rows[ $x['date'] ]['actual'] ?? '' ) ] ); }; // over: earlier day, or today's sheet already filled in
		$next  = ''; foreach ( $list as $x ) { if ( ! $isp( $x ) ) { $next = $x['date']; break; } }
		$h = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;border-collapse:collapse;width:100%">';
		$byd    = array(); foreach ( $list as $x ) { $byd[ $x['date'] ] = $x; }
		$closed = class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::closed_from( $list[0]['date'] ) : array();
		$day    = new DateTimeImmutable( $list[0]['date'], wp_timezone() );
		$lastd2 = end( $list )['date'];
		for ( $i = 0; $i < 400 && $day->format( 'Y-m-d' ) <= $lastd2; $i++, $day = $day->modify( '+1 day' ) ) {
			$ymd = $day->format( 'Y-m-d' );
			if ( ! isset( $byd[ $ymd ] ) ) { // a day between the first and last session with no class
				$h .= '<tr><td style="' . $f . 'padding:2px 14px 2px 0;font-size:13px;color:#c3c8cd;white-space:nowrap">' . esc_html( $day->format( 'D, F j, Y' ) ) . '</td><td></td><td style="' . $f . 'padding:2px 0;font-size:13px;color:#c3c8cd">No class' . ( isset( $closed[ $ymd ] ) ? ' — school closed' : '' ) . '</td></tr>';
				continue;
			}
			$x    = $byd[ $ymd ];
			$past = $isp( $x );
			$a    = (string) ( $rows[ $x['date'] ]['actual'] ?? '' );
			$note = trim( (string) ( $rows[ $x['date'] ]['staff_note'] ?? '' ) );
			$sty  = $f . 'padding:4px 14px 4px 0;font-size:14px;' . ( $past ? 'color:#9aa1a8;' : 'color:#15191d;font-weight:700;' );
			if ( $past && isset( $lab[ $a ] ) ) { $tag = '<span style="color:' . $lab[ $a ][1] . ';font-weight:700">' . $lab[ $a ][0] . '</span>' . ( $note ? ' <span style="color:#9aa1a8">— ' . esc_html( $note ) . '</span>' : '' ); }
			elseif ( $past ) { $tag = '<span style="color:#9aa1a8">Not recorded yet</span>'; }
			else { $tag = $x['date'] === $next ? '<span style="color:#00703c;font-weight:700">Next session</span>' : '<span style="color:#5b646d;font-weight:400">Upcoming</span>'; }
			$h .= '<tr><td style="' . $sty . 'white-space:nowrap">' . esc_html( wp_date( 'D, F j, Y g:i A', $ts( $x['start'] ) ) ) . '</td><td style="' . $f . 'padding:4px 14px 4px 0;font-size:13px;' . ( $past ? 'color:#9aa1a8' : 'color:#5b646d' ) . '">Unit ' . (int) $x['n'] . '</td><td style="' . $f . 'padding:4px 0;font-size:13px">' . $tag . '</td></tr>';
		}
		$h .= '</table>';
		if ( $email && empty( $GLOBALS['sdsc_zoom_live'] ) ) {
			$h .= '<p style="' . $f . 'margin:0 0 14px;font-size:13px;color:#5b646d">This list is as of today. <a href="' . esc_url( self::page_url( $cid, $email ) ) . '" style="color:#00703c;font-weight:700">Open your live schedule and attendance</a> any time for the latest.</p>';
		}
		return $h;
	}

	/** The welcome / reminder e-mail (HTML) for one student of a class: every unit, Zoom details, the forms reminder. */
	public static function welcome_html( $cid, $firstn = '', $email = '' ) {
		$link  = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		$mine  = $email ? self::personal_link( $cid, $email ) : '';
		if ( $mine ) { $link = $mine; } // this student's own link
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
		$units = self::schedule_rows( $cid, $email, $list, $ts, $f );
		$zoom  = $link ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;border-collapse:collapse;border-radius:12px;background:#f7f9fa;width:100%">'
			. '<tr><td style="' . $f . 'padding:10px 14px;font-size:14px;color:#5b646d;width:120px">Join Zoom</td><td style="' . $f . 'padding:10px 14px;font-size:14px"><a href="' . esc_url( $link ) . '" style="color:#00703c;font-weight:700;word-break:break-all">' . esc_html( $link ) . '</a></td></tr>'
			. ( $id ? '<tr><td style="' . $f . 'padding:6px 14px;font-size:14px;color:#5b646d">Meeting ID</td><td style="' . $f . 'padding:6px 14px;font-size:14px"><b>' . esc_html( self::fmt_id( $id ) ) . '</b></td></tr>' : '' )
			. ( $pass ? '<tr><td style="' . $f . 'padding:6px 14px 10px;font-size:14px;color:#5b646d">Passcode</td><td style="' . $f . 'padding:6px 14px 10px;font-size:14px"><b>' . esc_html( $pass ) . '</b></td></tr>' : '' )
			. '</table>' : $p( 'The Zoom link will be e-mailed to you before the first session.', 'font-size:14px' );
		return $p( 'Dear ' . esc_html( $firstn ? $firstn : 'Student' ) . ',' )
			. $p( 'We want to welcome you to Sam’s Driving School!' )
			. $p( 'This e-mail is a friendly reminder that you are enrolled in the <b>Driver Education Course</b>' . ( $first ? ' with a class beginning <b>' . esc_html( wp_date( 'F j, Y', $first ) ) . '</b> from <b>' . esc_html( $tfrom ) . '</b> to <b>' . esc_html( $tto ) . '</b>' : '' ) . '.' )
			. $p( '<b>Topic:</b> Driver Education' . ( $first ? '<br><b>Time:</b> From ' . esc_html( wp_date( 'F j, Y g:i A', $first ) ) . ' Eastern Time (US and Canada) to ' . esc_html( wp_date( 'F j, Y', $lastd ) ) . ', ' . count( $list ) . ' meetings' . ( $dl ? ' (' . esc_html( implode( ', ', $dl ) ) . ')' : '' ) : '' ) )
			. $units
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
		$to_list = (array) $a['to'];
		$a['subject'] = self::subject( $cid );
		$a['message'] = self::welcome_html( $cid, $first, (string) reset( $to_list ) );
		$GLOBALS['sdsc_zoom_mail_cid'] = $cid;
		foreach ( (array) $a['to'] as $to ) { self::log_mail( $cid, $to, true, 'auto' ); }
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
			$ok = SDSC_Mailer::send( $email, self::subject( $cid ), self::welcome_html( $cid, $firstn, $email ), 'info' );
			self::log_mail( $cid, $email, $ok, 'manual' );
			if ( $ok ) { $sent++; }
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
		echo '<p><label><input type="checkbox" name="sdsc_zoom_auto_mail" value="1"' . checked( self::auto_mail_on( $cid ), true, false ) . '> E-mail the details automatically 24 h before the first session (paid students)</label><br><span class="description">Untick to send only by hand with the button below. Payment receipts never contain the Zoom link.</span></p>';
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
			echo '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:600">Who got the details</summary><div style="margin-top:6px;overflow:auto">' . self::who_html( $cid ) . '</div></details>'; // phpcs:ignore -- escaped inside
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
			. ( is_array( $s ) ? '<br><small>Sent ' . esc_html( mysql2date( 'M j', $s['at'] ) ) . ' to ' . (int) $s['n'] . '</small>' : '' ) . '</p>'
			. '<details class="sdb-who"><summary>Who got the Zoom details</summary><div class="sdb-who-in">' . self::who_html( $cid ) . '</div></details>';
	}
}
