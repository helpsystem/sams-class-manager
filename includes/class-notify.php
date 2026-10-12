<?php
/**
 * Notification center.
 *
 * One feed per person (bell + pop-up toasts + optional browser alerts) shown on the dashboard, in the
 * in-car app and in wp-admin. Anything that changes for someone lands here:
 *  - driving lessons booked, moved, re-assigned to another instructor, car changed, cancelled, completed
 *    (worked out by comparing DriveFlow's lesson table with the last snapshot, so every way of changing
 *    a lesson is covered — calendar drag, edit form, self-booking, REST);
 *  - vehicle problems and failed evaluations reported from the in-car app (office);
 *  - new registrations and payments (office);
 *  - new chat messages (see SDSC_Chat).
 *
 * The page asks /wp-json/sdsc/v1/pulse every ~20 s while visible (one tiny query), so nothing is pushed
 * through a third party.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Notify {
	const DBV  = '1';
	const SNAP = 'sdsc_df_snap';

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_notify'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 22 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		// DriveFlow lesson changes.
		add_action( 'update_option_driveflow_last_calendar_change', array( __CLASS__, 'flag' ) );
		add_action( 'add_option_driveflow_last_calendar_change', array( __CLASS__, 'flag' ) );
		add_action( 'shutdown', array( __CLASS__, 'maybe_diff' ) );
		add_action( 'sdsc_schedule_tick', array( __CLASS__, 'diff' ) );
		add_action( 'sdsc_schedule_tick', array( __CLASS__, 'prune' ) );
		// Office events.
		add_action( 'sdsc_registered', array( __CLASS__, 'on_registered' ), 30, 2 );
		add_action( 'sdsc_paid', array( __CLASS__, 'on_paid' ), 30, 3 );
	}

	public static function install() {
		if ( get_option( 'sdsc_notify_dbv' ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			kind varchar(30) NOT NULL DEFAULT '',
			icon varchar(16) NOT NULL DEFAULT '',
			title varchar(190) NOT NULL DEFAULT '',
			body text NULL,
			url varchar(255) NOT NULL DEFAULT '',
			ref varchar(60) NOT NULL DEFAULT '',
			tone varchar(12) NOT NULL DEFAULT '',
			created datetime NOT NULL,
			seen tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_seen (user_id, seen, id)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'sdsc_notify_dbv', self::DBV, false );
	}

	/* ------------------------------------------------------------ who is who */

	/** Admins + Saghar (managers). Front desk is added for student-facing topics. */
	public static function office( $with_frontdesk = false ) {
		$roles = array( 'administrator' );
		if ( class_exists( 'SDSC_Access' ) ) {
			$roles[] = SDSC_Access::MANAGER;
			if ( $with_frontdesk ) { $roles[] = SDSC_Access::FRONTDESK; }
		}
		$ids = get_users( array( 'role__in' => $roles, 'fields' => 'ID' ) );
		return array_values( array_filter( array_map( 'intval', $ids ), function ( $id ) { return ! get_user_meta( $id, 'sdsc_preview', true ); } ) );
	}

	public static function is_office( $uid = 0 ) {
		$u = $uid ? get_userdata( $uid ) : wp_get_current_user();
		if ( ! $u || ! $u->ID ) { return false; }
		if ( user_can( $u, 'manage_options' ) ) { return true; }
		$r = (array) $u->roles;
		return class_exists( 'SDSC_Access' ) && ( in_array( SDSC_Access::MANAGER, $r, true ) || in_array( SDSC_Access::FRONTDESK, $r, true ) );
	}

	/** Site user of a DriveFlow instructor (by link, else e-mail). */
	public static function instructor_user( $name = '', $id = 0 ) {
		global $wpdb;
		$t = $wpdb->prefix . 'driveflow_instructors';
		$row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT user_id, email FROM $t WHERE id = %d", $id ), ARRAY_A ) : null; // phpcs:ignore
		if ( ! $row && '' !== $name ) { $row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, email FROM $t WHERE name = %s LIMIT 1", $name ), ARRAY_A ); } // phpcs:ignore
		if ( ! $row ) { return 0; }
		if ( (int) $row['user_id'] && get_userdata( (int) $row['user_id'] ) ) { return (int) $row['user_id']; }
		$u = $row['email'] ? get_user_by( 'email', $row['email'] ) : null;
		return $u ? (int) $u->ID : 0;
	}

	/** Site user of a DriveFlow student (by e-mail). */
	public static function student_user( $id = 0, $name = '' ) {
		global $wpdb;
		$t   = $wpdb->prefix . 'driveflow_students';
		$em  = $id ? $wpdb->get_var( $wpdb->prepare( "SELECT email FROM $t WHERE id = %d", $id ) ) : ''; // phpcs:ignore
		if ( ! $em && '' !== $name ) { $em = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM $t WHERE name = %s LIMIT 1", $name ) ); } // phpcs:ignore
		$u = $em ? get_user_by( 'email', $em ) : null;
		return $u ? (int) $u->ID : 0;
	}

	/* ------------------------------------------------------------ write */

	/**
	 * Add a note for one or more people. A note with the same $ref that is still unseen is refreshed
	 * instead of piling up (chat threads, repeated reminders).
	 */
	public static function add( $users, $title, $body = '', $args = array() ) {
		global $wpdb;
		$a = wp_parse_args( $args, array( 'kind' => 'info', 'icon' => '🔔', 'url' => '', 'ref' => '', 'tone' => '', 'skip_me' => true ) );
		$me = get_current_user_id();
		$n  = 0;
		foreach ( array_unique( array_filter( array_map( 'intval', (array) $users ) ) ) as $uid ) {
			if ( $a['skip_me'] && $uid === $me ) { continue; }
			$row = array(
				'user_id' => $uid, 'kind' => substr( $a['kind'], 0, 30 ), 'icon' => $a['icon'], 'title' => wp_html_excerpt( $title, 190, '…' ),
				'body' => wp_html_excerpt( $body, 600, '…' ), 'url' => esc_url_raw( $a['url'] ), 'ref' => substr( (string) $a['ref'], 0, 60 ),
				'tone' => $a['tone'], 'created' => current_time( 'mysql' ), 'seen' => 0,
			);
			$old = '' !== $row['ref'] ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE user_id = %d AND ref = %s AND seen = 0 ORDER BY id DESC LIMIT 1', $uid, $row['ref'] ) ) : 0; // phpcs:ignore
			if ( $old ) {
				// Re-insert so the poller (which asks "after id X") sees it as new.
				$wpdb->delete( self::table(), array( 'id' => $old ) );
			}
			$wpdb->insert( self::table(), $row );
			$n++;
		}
		return $n;
	}

	public static function prune() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------ read (REST) */

	public static function rest() {
		$auth = function () { return is_user_logged_in(); };
		register_rest_route( 'sdsc/v1', '/pulse', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'pulse' ) ) );
		register_rest_route( 'sdsc/v1', '/notes', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'list_notes' ) ) );
		register_rest_route( 'sdsc/v1', '/notes/seen', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'seen' ) ) );
	}

	protected static function shape( $r ) {
		$ts = strtotime( $r['created'] );
		return array(
			'id' => (int) $r['id'], 'kind' => $r['kind'], 'icon' => $r['icon'], 'title' => $r['title'], 'body' => $r['body'], 'url' => $r['url'],
			'tone' => $r['tone'], 'seen' => (int) $r['seen'], 'ts' => $ts, 'when' => human_time_diff( $ts, current_time( 'timestamp' ) ) . ' ago', // phpcs:ignore
			'ref' => $r['ref'],
		);
	}

	/** What changed since the last poll: new notes + unread counts (notes and chat). */
	public static function pulse( $req ) {
		global $wpdb;
		nocache_headers();
		$uid   = get_current_user_id();
		$after = absint( $req['after'] );
		$rows  = $after
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND id > %d ORDER BY id ASC LIMIT 20', $uid, $after ), ARRAY_A ) // phpcs:ignore
			: array();
		$last  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . self::table() . ' WHERE user_id = %d', $uid ) ); // phpcs:ignore
		$out   = array(
			'last'   => $last,
			'unread' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d AND seen = 0', $uid ) ), // phpcs:ignore
			'new'    => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'chat'   => class_exists( 'SDSC_Chat' ) ? SDSC_Chat::unread( $uid ) : 0,
			'cal'    => (int) get_option( 'driveflow_last_calendar_change', 0 ),
		);
		return rest_ensure_response( $out );
	}

	public static function list_notes( $req ) {
		global $wpdb;
		nocache_headers();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 40', get_current_user_id() ), ARRAY_A ); // phpcs:ignore
		return rest_ensure_response( array_map( array( __CLASS__, 'shape' ), (array) $rows ) );
	}

	public static function seen( $req ) {
		global $wpdb;
		$uid = get_current_user_id();
		$ids = array_filter( array_map( 'absint', (array) $req['ids'] ) );
		if ( $ids ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET seen = 1 WHERE user_id = %d AND id IN (' . implode( ',', $ids ) . ')', $uid ) ); // phpcs:ignore
		} else {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET seen = 1 WHERE user_id = %d', $uid ) ); // phpcs:ignore
		}
		return rest_ensure_response( array( 'ok' => 1 ) );
	}

	/* ------------------------------------------------------------ UI */

	/** Settings handed to dock.js. */
	public static function config() {
		$u = wp_get_current_user();
		return array(
			'rest'    => esc_url_raw( rest_url( 'sdsc/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'me'      => (int) $u->ID,
			'name'    => $u->display_name,
			'office'  => self::is_office() ? 1 : 0,
			'msgs'    => class_exists( 'SDSC_Chat' ) ? SDSC_Chat::page_url() : '',
			'chat'    => class_exists( 'SDSC_Chat' ) ? 1 : 0,
		);
	}

	public static function enqueue() {
		wp_enqueue_style( 'sdsc-dock', SDSC_URL . 'assets/dock.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-dock', SDSC_URL . 'assets/dock.js', array(), SDSC_VERSION, true );
		wp_add_inline_script( 'sdsc-dock', 'window.SDSC_DOCK=' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/** Raw tags for pages that do not run wp_head (DriveFlow's stand-alone in-car app). */
	public static function tags() {
		if ( ! is_user_logged_in() ) { return ''; }
		return '<link rel="stylesheet" href="' . esc_url( SDSC_URL . 'assets/dock.css?ver=' . SDSC_VERSION ) . '">'
			. '<script>window.SDSC_DOCK=' . wp_json_encode( self::config() ) . ';</script>'
			. '<script src="' . esc_url( SDSC_URL . 'assets/dock.js?ver=' . SDSC_VERSION ) . '" defer></script>';
	}

	public static function front_assets() {
		if ( ! is_user_logged_in() ) { return; }
		$id = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) : 0;
		if ( $id && is_page( $id ) ) { self::enqueue(); }
	}

	public static function admin_assets() {
		if ( ! is_user_logged_in() || isset( $_GET['sdsc_embed'] ) ) { return; } // phpcs:ignore -- display only
		self::enqueue();
	}

	public static function admin_bar( $bar ) {
		if ( ! is_user_logged_in() || ! is_admin() || isset( $_GET['sdsc_embed'] ) ) { return; } // phpcs:ignore
		$bar->add_node( array( 'id' => 'sdsc-bell', 'parent' => 'top-secondary', 'title' => '<span class="ab-icon dashicons dashicons-bell" aria-hidden="true"></span><span class="sdsc-ab-count" data-sdsc-count="notes"></span><span class="screen-reader-text">Notifications</span>', 'href' => '#', 'meta' => array( 'class' => 'sdsc-ab-bell' ) ) );
	}

	/* ------------------------------------------------------------ DriveFlow lesson watcher */

	protected static $dirty = false;

	public static function flag() { self::$dirty = true; }

	public static function maybe_diff() { if ( self::$dirty ) { self::diff(); } }

	protected static function when( $dt ) {
		$t = strtotime( $dt );
		return $t ? date_i18n( 'D, M j \a\t g:i A', $t ) : '';
	}

	/** Current lessons worth watching: from 2 days ago onwards. */
	protected static function current() {
		global $wpdb;
		$t = $wpdb->prefix . 'driveflow_sessions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return null; } // phpcs:ignore
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, student_id, student_name, instructor_id, instructor_name, plate_number, session_number, scheduled_start, scheduled_end, status, created_by FROM $t WHERE scheduled_start >= %s", gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = array( 's' => (int) $r['student_id'], 'sn' => $r['student_name'], 'i' => (int) $r['instructor_id'], 'in' => $r['instructor_name'], 'p' => $r['plate_number'], 'n' => (int) $r['session_number'], 'a' => $r['scheduled_start'], 'b' => $r['scheduled_end'], 'st' => $r['status'], 'by' => (int) $r['created_by'] );
		}
		return $out;
	}

	public static function diff() {
		$now = self::current();
		if ( null === $now ) { return; }
		$old = get_option( self::SNAP, null );
		update_option( self::SNAP, $now, false );
		if ( ! is_array( $old ) ) { return; } // first run: remember only.
		$cut = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS );
		foreach ( $now as $id => $s ) {
			if ( ! isset( $old[ $id ] ) ) { self::lesson_new( $id, $s ); continue; }
			self::lesson_changed( $id, $old[ $id ], $s );
		}
		foreach ( $old as $id => $s ) {
			if ( isset( $now[ $id ] ) || $s['a'] < $cut ) { continue; }
			if ( in_array( $s['st'], array( 'upcoming', 'active' ), true ) ) { self::lesson_gone( $id, $s ); }
		}
	}

	protected static function lessons_url() {
		return class_exists( 'SDSC_Lessons' ) && SDSC_Lessons::my_url() ? SDSC_Lessons::my_url() : home_url( '/dashboard/' );
	}

	protected static function app_url() {
		$st = get_option( 'driveflow_pro_settings', array() );
		$id = is_array( $st ) ? absint( $st['instructor_page_id'] ?? 0 ) : 0;
		return $id ? get_permalink( $id ) : home_url( '/instructor-session/' );
	}

	protected static function lesson_new( $id, $s ) {
		if ( ! in_array( $s['st'], array( 'upcoming', 'active' ), true ) ) { return; }
		$w   = self::when( $s['a'] );
		$ref = 'lesson-' . $id;
		self::add( self::instructor_user( $s['in'], $s['i'] ), 'New lesson: ' . $s['sn'], 'Lesson #' . $s['n'] . ' · ' . $w . ( $s['p'] ? ' · Car ' . $s['p'] : '' ), array( 'kind' => 'lesson', 'icon' => '🚗', 'url' => self::app_url(), 'ref' => $ref, 'tone' => 'good' ) );
		self::add( self::student_user( $s['s'], $s['sn'] ), 'Lesson #' . $s['n'] . ' booked', $w . ( $s['in'] ? ' with ' . $s['in'] : '' ), array( 'kind' => 'lesson', 'icon' => '🚗', 'url' => self::lessons_url(), 'ref' => $ref, 'tone' => 'good' ) );
		// Booked by the student themself (or by nobody in the office) → tell the office.
		if ( ! self::is_office( get_current_user_id() ) ) {
			self::add( self::office(), 'New booking: ' . $s['sn'], 'Lesson #' . $s['n'] . ' · ' . $w . ( $s['in'] ? ' · ' . $s['in'] : '' ), array( 'kind' => 'lesson', 'icon' => '📅', 'url' => class_exists( 'SDSC_DriveFlow' ) ? SDSC_DriveFlow::calendar_url() : admin_url(), 'ref' => $ref ) );
		}
	}

	protected static function lesson_changed( $id, $o, $s ) {
		$ref  = 'lesson-' . $id;
		$iu   = self::instructor_user( $s['in'], $s['i'] );
		$su   = self::student_user( $s['s'], $s['sn'] );
		$app  = self::app_url();
		$mine = self::lessons_url();
		// Completed (report submitted).
		if ( 'completed' === $s['st'] && 'completed' !== $o['st'] ) {
			self::add( $su, 'Lesson #' . $s['n'] . ' report is ready', 'Your instructor ' . $s['in'] . ' finished your lesson. See your progress and notes.', array( 'kind' => 'report', 'icon' => '📋', 'url' => $mine, 'ref' => $ref, 'tone' => 'good' ) );
			return;
		}
		if ( in_array( $s['st'], array( 'cancelled', 'canceled', 'no_show', 'no-show' ), true ) && $s['st'] !== $o['st'] ) {
			$label = 0 === strpos( $s['st'], 'no' ) ? 'marked as no-show' : 'cancelled';
			self::add( $iu, 'Lesson ' . $label . ': ' . $s['sn'], self::when( $s['a'] ), array( 'kind' => 'lesson', 'icon' => '✖', 'url' => $app, 'ref' => $ref, 'tone' => 'bad' ) );
			self::add( $su, 'Lesson #' . $s['n'] . ' ' . $label, self::when( $s['a'] ), array( 'kind' => 'lesson', 'icon' => '✖', 'url' => $mine, 'ref' => $ref, 'tone' => 'bad' ) );
			return;
		}
		if ( ! in_array( $s['st'], array( 'upcoming', 'active' ), true ) ) { return; }
		// Moved to another instructor.
		if ( strcasecmp( (string) $o['in'], (string) $s['in'] ) ) {
			self::add( self::instructor_user( $o['in'], $o['i'] ), 'Lesson moved to another instructor', $s['sn'] . ' · ' . self::when( $s['a'] ) . ' is now with ' . ( $s['in'] ? $s['in'] : 'the office' ), array( 'kind' => 'lesson', 'icon' => '↪', 'url' => $app, 'ref' => $ref, 'tone' => 'warn' ) );
			self::add( $iu, 'New lesson: ' . $s['sn'], 'Lesson #' . $s['n'] . ' · ' . self::when( $s['a'] ) . ( $o['in'] ? ' (before: ' . $o['in'] . ')' : '' ), array( 'kind' => 'lesson', 'icon' => '🚗', 'url' => $app, 'ref' => $ref, 'tone' => 'good' ) );
			self::add( $su, 'New instructor for lesson #' . $s['n'], self::when( $s['a'] ) . ' with ' . $s['in'], array( 'kind' => 'lesson', 'icon' => '👤', 'url' => $mine, 'ref' => $ref, 'tone' => 'warn' ) );
			return;
		}
		$moved = $o['a'] !== $s['a'] || $o['b'] !== $s['b'];
		$car   = strcasecmp( (string) $o['p'], (string) $s['p'] );
		if ( $moved ) {
			$txt = 'Was ' . self::when( $o['a'] ) . ' → now ' . self::when( $s['a'] );
			self::add( $iu, 'Lesson time changed: ' . $s['sn'], $txt, array( 'kind' => 'lesson', 'icon' => '🕑', 'url' => $app, 'ref' => $ref, 'tone' => 'warn' ) );
			self::add( $su, 'Lesson #' . $s['n'] . ' time changed', $txt, array( 'kind' => 'lesson', 'icon' => '🕑', 'url' => $mine, 'ref' => $ref, 'tone' => 'warn' ) );
			if ( ! self::is_office( get_current_user_id() ) ) {
				self::add( self::office(), 'Lesson moved: ' . $s['sn'], $txt . ( $s['in'] ? ' · ' . $s['in'] : '' ), array( 'kind' => 'lesson', 'icon' => '🕑', 'url' => class_exists( 'SDSC_DriveFlow' ) ? SDSC_DriveFlow::calendar_url() : admin_url(), 'ref' => $ref ) );
			}
		}
		if ( $car && $s['p'] ) {
			self::add( $iu, 'Car changed: ' . $s['sn'], self::when( $s['a'] ) . ' · now car ' . $s['p'] . ( $o['p'] ? ' (was ' . $o['p'] . ')' : '' ), array( 'kind' => 'lesson', 'icon' => '🚙', 'url' => $app, 'ref' => $ref . '-car', 'tone' => 'warn' ) );
		}
	}

	protected static function lesson_gone( $id, $s ) {
		$ref = 'lesson-' . $id;
		self::add( self::instructor_user( $s['in'], $s['i'] ), 'Lesson removed: ' . $s['sn'], self::when( $s['a'] ), array( 'kind' => 'lesson', 'icon' => '✖', 'url' => self::app_url(), 'ref' => $ref, 'tone' => 'bad' ) );
		self::add( self::student_user( $s['s'], $s['sn'] ), 'Lesson #' . $s['n'] . ' cancelled', self::when( $s['a'] ), array( 'kind' => 'lesson', 'icon' => '✖', 'url' => self::lessons_url(), 'ref' => $ref, 'tone' => 'bad' ) );
	}

	/* ------------------------------------------------------------ office events */

	public static function on_registered( $reg_id, $d ) {
		$name = trim( ( $d['first_name'] ?? '' ) . ' ' . ( $d['last_name'] ?? '' ) );
		self::add( self::office( true ), 'New registration: ' . $name, isset( $d['email'] ) ? $d['email'] : '', array( 'kind' => 'office', 'icon' => '📝', 'url' => admin_url( 'admin.php?page=sdsc-registrations' ), 'ref' => 'reg-' . $reg_id, 'skip_me' => false ) );
	}

	public static function on_paid( $reg_id, $class_id, $row ) {
		$amt = isset( $row['amount'] ) ? '$' . number_format( (int) $row['amount'] / 100, 2 ) : '';
		self::add( self::office(), 'Payment received', trim( $amt . ' · ' . html_entity_decode( (string) get_post_field( 'post_title', $class_id ), ENT_QUOTES, 'UTF-8' ), ' ·' ), array( 'kind' => 'office', 'icon' => '💳', 'url' => admin_url( 'admin.php?page=sdsc-registrations' ), 'ref' => 'paid-' . $reg_id, 'tone' => 'good', 'skip_me' => false ) );
	}

	/** Called by the in-car app after an evaluation was saved. */
	public static function on_evaluation( $session_id ) {
		global $wpdb;
		$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}driveflow_sessions WHERE id = %d", $session_id ), ARRAY_A ); // phpcs:ignore
		if ( ! $s ) { return; }
		$f   = json_decode( (string) $s['form_data'], true );
		$f   = is_array( $f ) ? $f : array();
		$inc = $f['incident'] ?? array();
		$off = self::office();
		if ( ! empty( $inc['reported'] ) ) {
			$urgent = 'urgent_ground' === ( $inc['urgency'] ?? '' );
			self::add( $off, ( $urgent ? 'URGENT — car grounded: ' : 'Car problem reported: ' ) . $s['plate_number'], $s['instructor_name'] . ': ' . ( $inc['description'] ?? '' ), array( 'kind' => 'vehicle', 'icon' => $urgent ? '🛑' : '🔧', 'url' => admin_url( 'admin.php?page=driveflow-pro-vehicles' ), 'ref' => 'veh-' . $session_id, 'tone' => $urgent ? 'bad' : 'warn', 'skip_me' => false ) );
		} elseif ( isset( $f['fuel']['level_pct'] ) && (int) $f['fuel']['level_pct'] <= 25 && $s['plate_number'] ) {
			self::add( $off, 'Low fuel: ' . $s['plate_number'], (int) $f['fuel']['level_pct'] . '% after ' . $s['instructor_name'] . '’s lesson', array( 'kind' => 'vehicle', 'icon' => '⛽', 'url' => admin_url( 'admin.php?page=driveflow-pro-vehicles' ), 'ref' => 'fuel-' . $s['plate_number'], 'tone' => 'warn', 'skip_me' => false ) );
		}
		if ( 'Fail' === ( $f['final_evaluation'] ?? '' ) ) {
			self::add( $off, 'Lesson not passed: ' . $s['student_name'], 'Lesson #' . $s['session_number'] . ' with ' . $s['instructor_name'], array( 'kind' => 'report', 'icon' => '⚠', 'url' => admin_url( 'admin.php?page=driveflow-pro-records' ), 'ref' => 'fail-' . $session_id, 'tone' => 'warn', 'skip_me' => false ) );
		}
		self::diff();
	}
}
