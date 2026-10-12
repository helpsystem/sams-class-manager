<?php
/**
 * Messages (chat) inside the school website.
 *
 * Conversations:
 *  - school : a student ↔ the school office (Saghar, front desk, admin all see it)
 *  - office : an instructor ↔ the office (Saghar and admin)
 *  - lesson : a student ↔ their driving instructor (only when they share a lesson; the office can read it)
 *
 * Shown as a "Messages" page in the dashboard, a slide-in panel from the 💬 button (dashboard, in-car
 * app, wp-admin) and as notifications. Someone who has not read a message within 10 minutes gets one
 * e-mail for it.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Chat {
	const DBV = '1';
	const TAB = 'sdsc-messages';

	public static function t_threads() { global $wpdb; return $wpdb->prefix . 'sdsc_chat_threads'; }
	public static function t_msgs() { global $wpdb; return $wpdb->prefix . 'sdsc_chat_msgs'; }
	public static function t_reads() { global $wpdb; return $wpdb->prefix . 'sdsc_chat_reads'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 22 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'tutor_nav' ) );
		add_filter( 'tutor_instructor_dashboard_nav', array( __CLASS__, 'tutor_nav' ) );
		add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'tutor_template' ) );
		add_action( 'sdsc_schedule_tick', array( __CLASS__, 'nudge' ) );
	}

	public static function install() {
		if ( get_option( 'sdsc_chat_dbv' ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t_threads() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(12) NOT NULL DEFAULT 'school',
			student_uid bigint(20) unsigned NOT NULL DEFAULT 0,
			instructor_uid bigint(20) unsigned NOT NULL DEFAULT 0,
			last_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_uid bigint(20) unsigned NOT NULL DEFAULT 0,
			last_at datetime NULL DEFAULT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pair (kind, student_uid, instructor_uid),
			KEY last_at (last_at)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t_msgs() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			body text NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY thread (thread_id, id)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t_reads() . " (
			thread_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			last_read bigint(20) unsigned NOT NULL DEFAULT 0,
			mailed bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (thread_id, user_id)
		) $c;" );
		update_option( 'sdsc_chat_dbv', self::DBV, false );
	}

	/* ------------------------------------------------------------ roles */

	protected static $roles = array();

	public static function role( $uid ) {
		$uid = (int) $uid;
		if ( isset( self::$roles[ $uid ] ) ) { return self::$roles[ $uid ]; }
		$u = get_userdata( $uid );
		if ( ! $u ) { return ''; }
		$r    = (array) $u->roles;
		$role = 'student';
		if ( user_can( $u, 'manage_options' ) ) { $role = 'admin'; }
		elseif ( class_exists( 'SDSC_Access' ) && in_array( SDSC_Access::MANAGER, $r, true ) ) { $role = 'manager'; }
		elseif ( class_exists( 'SDSC_Access' ) && in_array( SDSC_Access::FRONTDESK, $r, true ) ) { $role = 'frontdesk'; }
		elseif ( array_intersect( $r, array( 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' ) ) || self::df_instructor( $uid ) ) { $role = 'instructor'; }
		return self::$roles[ $uid ] = $role;
	}

	protected static function df_instructor( $uid ) {
		global $wpdb;
		$t = $wpdb->prefix . 'driveflow_instructors';
		$u = get_userdata( $uid );
		if ( ! $u || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return null; } // phpcs:ignore
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, name FROM $t WHERE user_id = %d OR (email <> '' AND email = %s) ORDER BY user_id = %d DESC LIMIT 1", $uid, $u->user_email, $uid ), ARRAY_A ); // phpcs:ignore
	}

	protected static function is_staff_role( $role ) { return in_array( $role, array( 'admin', 'manager', 'frontdesk' ), true ); }

	/** May $uid read thread $t? */
	public static function can_see( $t, $uid ) {
		$role = self::role( $uid );
		switch ( $t['kind'] ) {
			case 'school': return (int) $t['student_uid'] === $uid || self::is_staff_role( $role );
			case 'office': return (int) $t['instructor_uid'] === $uid || in_array( $role, array( 'admin', 'manager' ), true );
			case 'lesson': return ( self::lesson_on() && ( (int) $t['student_uid'] === $uid || (int) $t['instructor_uid'] === $uid ) ) || in_array( $role, array( 'admin', 'manager' ), true );
		}
		return false;
	}

	/** May $uid write in thread $t? (The office only reads student ↔ instructor talks.) */
	public static function can_write( $t, $uid ) {
		if ( 'lesson' === $t['kind'] ) { return self::lesson_on() && ( (int) $t['student_uid'] === $uid || (int) $t['instructor_uid'] === $uid ); }
		return self::can_see( $t, $uid );
	}

	/** Everyone who should hear about a new message in $t (sender excluded). */
	public static function audience( $t, $sender ) {
		$ids = array();
		if ( 'school' === $t['kind'] ) { $ids = array_merge( array( (int) $t['student_uid'] ), SDSC_Notify::office( true ) ); }
		if ( 'office' === $t['kind'] ) { $ids = array_merge( array( (int) $t['instructor_uid'] ), SDSC_Notify::office() ); }
		if ( 'lesson' === $t['kind'] ) { $ids = array( (int) $t['student_uid'], (int) $t['instructor_uid'] ); }
		return array_values( array_diff( array_unique( array_filter( $ids ) ), array( (int) $sender ) ) );
	}

	/* ------------------------------------------------------------ who can talk to whom */

	/** DriveFlow lessons (±90 days) of a person, for pairing students with instructors. */
	protected static function partners( $uid ) {
		global $wpdb;
		$st  = $wpdb->prefix . 'driveflow_sessions';
		$stu = $wpdb->prefix . 'driveflow_students';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $st ) ) !== $st ) { return array(); } // phpcs:ignore
		$from = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 90 * DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d', current_time( 'timestamp' ) + 90 * DAY_IN_SECONDS );
		$out  = array();
		$role = self::role( $uid );
		if ( 'instructor' === $role ) {
			$me = self::df_instructor( $uid );
			if ( ! $me ) { return array(); }
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT s.student_id, s.student_name, d.email FROM $st s LEFT JOIN $stu d ON d.id = s.student_id WHERE s.instructor_name = %s AND s.scheduled_start BETWEEN %s AND %s", $me['name'], $from, $to ), ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				$u = $r['email'] ? get_user_by( 'email', $r['email'] ) : null;
				if ( $u ) { $out[ (int) $u->ID ] = $r['student_name'] ? $r['student_name'] : $u->display_name; }
			}
		} elseif ( 'student' === $role ) {
			$u   = get_userdata( $uid );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $stu WHERE email = %s", $u->user_email ) ); // phpcs:ignore
			if ( ! $ids ) { return array(); }
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT instructor_name FROM $st WHERE student_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ") AND instructor_name <> '' AND scheduled_start BETWEEN %s AND %s", $from, $to ) ); // phpcs:ignore
			foreach ( (array) $names as $n ) {
				$iu = SDSC_Notify::instructor_user( $n );
				if ( $iu ) { $out[ $iu ] = $n; }
			}
		}
		return $out;
	}

	/** Find or start a conversation. $to: 'office' or a user id. */
	public static function open( $uid, $to ) {
		global $wpdb;
		$role = self::role( $uid );
		$kind = ''; $s = 0; $i = 0;
		if ( 'office' === $to ) {
			if ( 'instructor' === $role ) { $kind = 'office'; $i = $uid; }
			elseif ( 'student' === $role ) { $kind = 'school'; $s = $uid; }
		} else {
			$to    = absint( $to );
			$trole = self::role( $to );
			if ( self::is_staff_role( $role ) ) {
				if ( 'instructor' === $trole ) { $kind = 'office'; $i = $to; }
				elseif ( 'student' === $trole ) { $kind = 'school'; $s = $to; }
			} elseif ( self::lesson_on() && isset( self::partners( $uid )[ $to ] ) ) {
				$kind = 'lesson';
				if ( 'instructor' === $role ) { $i = $uid; $s = $to; } else { $s = $uid; $i = $to; }
			}
		}
		if ( ! $kind ) { return 0; }
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t_threads() . ' WHERE kind = %s AND student_uid = %d AND instructor_uid = %d', $kind, $s, $i ) ); // phpcs:ignore
		if ( $id ) { return $id; }
		$wpdb->insert( self::t_threads(), array( 'kind' => $kind, 'student_uid' => $s, 'instructor_uid' => $i, 'created' => current_time( 'mysql' ) ) );
		return (int) $wpdb->insert_id;
	}

	public static function thread( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_threads() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	}

	protected static function name( $uid ) {
		$u = get_userdata( $uid );
		if ( ! $u ) { return 'Former user'; }
		$n = trim( $u->first_name . ' ' . $u->last_name );
		return $n ? $n : $u->display_name;
	}

	/** Title of a thread as seen by $uid. */
	protected static function title( $t, $uid ) {
		$me_student = (int) $t['student_uid'] === $uid;
		$me_instr   = (int) $t['instructor_uid'] === $uid;
		switch ( $t['kind'] ) {
			case 'school': return $me_student ? array( 'Sam’s Driving School', 'Office · front desk' ) : array( self::name( $t['student_uid'] ), 'Student' );
			case 'office': return $me_instr ? array( 'School office', 'Saghar & the office' ) : array( self::name( $t['instructor_uid'] ), 'Instructor' );
			case 'lesson':
				if ( $me_student ) { return array( self::name( $t['instructor_uid'] ), 'Your driving instructor' ); }
				if ( $me_instr ) { return array( self::name( $t['student_uid'] ), 'Your student' ); }
				return array( self::name( $t['student_uid'] ) . ' ↔ ' . self::name( $t['instructor_uid'] ), 'Student & instructor (read only)' );
		}
		return array( 'Conversation', '' );
	}

	/* ------------------------------------------------------------ lists */

	protected static function visible_sql( $uid ) {
		$role = self::role( $uid );
		if ( 'admin' === $role || 'manager' === $role ) { return '1=1'; }
		if ( 'frontdesk' === $role ) { return $GLOBALS['wpdb']->prepare( "(kind = 'school' OR student_uid = %d OR instructor_uid = %d)", $uid, $uid ); }
		return $GLOBALS['wpdb']->prepare( '(student_uid = %d OR instructor_uid = %d)', $uid, $uid ) . ( self::lesson_on() ? '' : " AND kind <> 'lesson'" );
	}

	/** Student ↔ instructor messages are off until Saghar (or the admin) turns them on. */
	public static function lesson_on() { return '1' === (string) get_option( 'sdsc_chat_lesson', '0' ); }

	public static function can_toggle() { return in_array( self::role( get_current_user_id() ), array( 'admin', 'manager' ), true ); }

	public static function threads( $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT t.*, r.last_read FROM ' . self::t_threads() . ' t LEFT JOIN ' . self::t_reads() . ' r ON r.thread_id = t.id AND r.user_id = ' . (int) $uid . ' WHERE ' . self::visible_sql( $uid ) . ' AND (t.last_id > 0 OR t.student_uid = ' . (int) $uid . ' OR t.instructor_uid = ' . (int) $uid . ') ORDER BY COALESCE(t.last_at, t.created) DESC LIMIT 200', ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $t ) {
			$last  = $t['last_id'] ? $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM ' . self::t_msgs() . ' WHERE id = %d', $t['last_id'] ) ) : ''; // phpcs:ignore
			list( $title, $sub ) = self::title( $t, $uid );
			$out[] = array(
				'id' => (int) $t['id'], 'kind' => $t['kind'], 'title' => $title, 'sub' => $sub,
				'last' => wp_html_excerpt( (string) $last, 90, '…' ), 'mine' => (int) $t['last_uid'] === $uid,
				'at' => $t['last_at'] ? self::ago( $t['last_at'] ) : '', 'ts' => $t['last_at'] ? strtotime( $t['last_at'] ) : 0,
				'unread' => ( (int) $t['last_id'] > (int) $t['last_read'] && (int) $t['last_uid'] !== $uid && self::can_write( $t, $uid ) ) ? 1 : 0,
				'write' => self::can_write( $t, $uid ) ? 1 : 0,
			);
		}
		return $out;
	}

	public static function unread( $uid ) {
		global $wpdb;
		if ( ! $uid ) { return 0; }
		$u = (int) $uid;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_threads() . ' t LEFT JOIN ' . self::t_reads() . " r ON r.thread_id = t.id AND r.user_id = $u WHERE " . self::visible_sql( $u ) . " AND t.last_id > COALESCE(r.last_read, 0) AND t.last_uid <> $u AND (t.kind <> 'lesson' OR t.student_uid = $u OR t.instructor_uid = $u)" ); // phpcs:ignore
	}

	protected static function ago( $mysql ) {
		$ts  = strtotime( $mysql );
		$now = current_time( 'timestamp' ); // phpcs:ignore
		if ( $now - $ts < 60 ) { return 'now'; }
		if ( gmdate( 'Y-m-d', $ts ) === gmdate( 'Y-m-d', $now ) ) { return date_i18n( 'g:i A', $ts ); }
		if ( $now - $ts < 6 * DAY_IN_SECONDS ) { return date_i18n( 'D', $ts ); }
		return date_i18n( 'M j', $ts );
	}

	/** People $uid can start a conversation with. */
	public static function contacts( $uid ) {
		$role = self::role( $uid );
		$out  = array();
		if ( 'student' === $role ) { $out[] = array( 'to' => 'office', 'name' => 'Sam’s Driving School', 'sub' => 'Office · questions, payments, scheduling' ); }
		if ( 'instructor' === $role ) { $out[] = array( 'to' => 'office', 'name' => 'School office', 'sub' => 'Saghar & the office' ); }
		if ( self::is_staff_role( $role ) ) {
			global $wpdb;
			$it = $wpdb->prefix . 'driveflow_instructors';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $it ) ) === $it ) { // phpcs:ignore
				foreach ( (array) $wpdb->get_results( "SELECT id, name FROM $it WHERE status = 'active' ORDER BY name", ARRAY_A ) as $r ) { // phpcs:ignore
					$iu = SDSC_Notify::instructor_user( $r['name'], $r['id'] );
					if ( $iu && $iu !== $uid && ! get_user_meta( $iu, 'sdsc_preview', true ) ) { $out[] = array( 'to' => $iu, 'name' => $r['name'], 'sub' => 'Instructor' ); }
				}
			}
			return $out; // Students are found with search (there are many).
		}
		foreach ( self::lesson_on() ? self::partners( $uid ) : array() as $id => $n ) {
			$out[] = array( 'to' => $id, 'name' => $n, 'sub' => 'instructor' === $role ? 'Your student' : 'Your driving instructor' );
		}
		return $out;
	}

	/* ------------------------------------------------------------ REST */

	public static function rest() {
		$auth = function () { return is_user_logged_in(); };
		register_rest_route( 'sdsc/v1', '/chat/threads', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => function () { nocache_headers(); return rest_ensure_response( array( 'threads' => self::threads( get_current_user_id() ), 'contacts' => self::contacts( get_current_user_id() ), 'office' => self::is_staff_role( self::role( get_current_user_id() ) ) ? 1 : 0, 'lesson' => self::lesson_on() ? 1 : 0, 'toggle' => self::can_toggle() ? 1 : 0 ) ); } ) );
		register_rest_route( 'sdsc/v1', '/chat/settings', array( 'methods' => 'POST', 'permission_callback' => function () { return is_user_logged_in() && self::can_toggle(); }, 'callback' => function ( $req ) {
			update_option( 'sdsc_chat_lesson', empty( $req['lesson'] ) ? '0' : '1', false );
			return rest_ensure_response( array( 'lesson' => self::lesson_on() ? 1 : 0 ) );
		} ) );
		register_rest_route( 'sdsc/v1', '/chat/find', array( 'methods' => 'GET', 'permission_callback' => function () { return is_user_logged_in() && SDSC_Notify::is_office(); }, 'callback' => array( __CLASS__, 'r_find' ) ) );
		register_rest_route( 'sdsc/v1', '/chat/open', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'r_open' ) ) );
		register_rest_route( 'sdsc/v1', '/chat/thread/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'r_get' ) ),
			array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => array( __CLASS__, 'r_send' ) ),
		) );
	}

	public static function r_find( $req ) {
		$q = sanitize_text_field( (string) $req['q'] );
		if ( strlen( $q ) < 2 ) { return rest_ensure_response( array() ); }
		$users = get_users( array( 'search' => '*' . $q . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name' ), 'number' => 15, 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
		$out   = array();
		foreach ( $users as $u ) {
			$r = self::role( (int) $u->ID );
			if ( ! in_array( $r, array( 'student', 'instructor' ), true ) ) { continue; }
			if ( get_user_meta( $u->ID, 'sdsc_preview', true ) && ! current_user_can( 'manage_options' ) && ! get_user_meta( get_current_user_id(), 'sdsc_preview', true ) ) { continue; }
			$out[] = array( 'to' => (int) $u->ID, 'name' => self::name( (int) $u->ID ), 'sub' => ucfirst( $r ) . ' · ' . $u->user_email );
		}
		return rest_ensure_response( $out );
	}

	public static function r_open( $req ) {
		$to = $req['to'];
		$id = self::open( get_current_user_id(), 'office' === $to ? 'office' : absint( $to ) );
		return $id ? rest_ensure_response( array( 'id' => $id ) ) : new WP_Error( 'sdsc_chat', 'You can’t start this conversation.', array( 'status' => 403 ) );
	}

	protected static function msg( $m, $uid, $t ) {
		$sender = (int) $m['user_id'];
		$role   = self::role( $sender );
		$label  = self::name( $sender );
		if ( self::is_staff_role( $role ) && (int) $t['student_uid'] !== $sender && (int) $t['instructor_uid'] !== $sender ) { $label .= ' · Office'; }
		$ts = strtotime( $m['created'] );
		return array( 'id' => (int) $m['id'], 'mine' => $sender === $uid ? 1 : 0, 'who' => $label, 'body' => $m['body'], 'ts' => $ts, 'time' => date_i18n( 'g:i A', $ts ), 'day' => date_i18n( 'l, M j', $ts ) );
	}

	public static function r_get( $req ) {
		global $wpdb;
		nocache_headers();
		$uid = get_current_user_id();
		$t   = self::thread( absint( $req['id'] ) );
		if ( ! $t || ! self::can_see( $t, $uid ) ) { return new WP_Error( 'sdsc_chat', 'Not found.', array( 'status' => 404 ) ); }
		$after = absint( $req['after'] );
		$rows  = $after
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_msgs() . ' WHERE thread_id = %d AND id > %d ORDER BY id ASC', $t['id'], $after ), ARRAY_A ) // phpcs:ignore
			: array_reverse( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_msgs() . ' WHERE thread_id = %d ORDER BY id DESC LIMIT 100', $t['id'] ), ARRAY_A ) ); // phpcs:ignore
		self::mark_read( $t, $uid );
		list( $title, $sub ) = self::title( $t, $uid );
		// Who has read my last message (for the ✓✓ mark).
		$seen = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(last_read) FROM ' . self::t_reads() . ' WHERE thread_id = %d AND user_id <> %d', $t['id'], $uid ) ); // phpcs:ignore
		$this_msgs = array();
		foreach ( (array) $rows as $m ) { $this_msgs[] = self::msg( $m, $uid, $t ); }
		return rest_ensure_response( array( 'id' => (int) $t['id'], 'title' => $title, 'sub' => $sub, 'write' => self::can_write( $t, $uid ) ? 1 : 0, 'msgs' => $this_msgs, 'seen' => $seen ) );
	}

	public static function mark_read( $t, $uid ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::t_reads() . ' (thread_id, user_id, last_read, mailed) VALUES (%d, %d, %d, %d) ON DUPLICATE KEY UPDATE last_read = GREATEST(last_read, VALUES(last_read)), mailed = GREATEST(mailed, VALUES(mailed))', $t['id'], $uid, $t['last_id'], $t['last_id'] ) ); // phpcs:ignore
		if ( class_exists( 'SDSC_Notify' ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . SDSC_Notify::table() . ' SET seen = 1 WHERE user_id = %d AND ref = %s', $uid, 'chat-' . $t['id'] ) ); // phpcs:ignore
		}
	}

	public static function r_send( $req ) {
		global $wpdb;
		$uid = get_current_user_id();
		$t   = self::thread( absint( $req['id'] ) );
		if ( ! $t || ! self::can_write( $t, $uid ) ) { return new WP_Error( 'sdsc_chat', 'You can’t write here.', array( 'status' => 403 ) ); }
		$body = trim( sanitize_textarea_field( (string) $req['body'] ) );
		if ( '' === $body ) { return new WP_Error( 'sdsc_chat', 'Empty message.', array( 'status' => 400 ) ); }
		$body = mb_substr( $body, 0, 2000 );
		// Simple flood guard: 20 messages a minute.
		$k = 'sdsc_chat_rl_' . $uid;
		$n = (int) get_transient( $k );
		if ( $n > 20 ) { return new WP_Error( 'sdsc_chat', 'Please slow down a little.', array( 'status' => 429 ) ); }
		set_transient( $k, $n + 1, MINUTE_IN_SECONDS );
		$now = current_time( 'mysql' );
		$wpdb->insert( self::t_msgs(), array( 'thread_id' => $t['id'], 'user_id' => $uid, 'body' => $body, 'created' => $now ) );
		$mid = (int) $wpdb->insert_id;
		$wpdb->update( self::t_threads(), array( 'last_id' => $mid, 'last_uid' => $uid, 'last_at' => $now ), array( 'id' => $t['id'] ) );
		$t['last_id'] = $mid;
		self::mark_read( $t, $uid );
		// Notify the others.
		$from = self::name( $uid );
		foreach ( self::audience( $t, $uid ) as $to ) {
			SDSC_Notify::add( $to, 'Message from ' . $from, wp_html_excerpt( $body, 140, '…' ), array( 'kind' => 'chat', 'icon' => '💬', 'url' => add_query_arg( 't', $t['id'], self::page_url() ), 'ref' => 'chat-' . $t['id'] ) );
		}
		return rest_ensure_response( array( 'msg' => self::msg( array( 'id' => $mid, 'user_id' => $uid, 'body' => $body, 'created' => $now ), $uid, $t ) ) );
	}

	/* ------------------------------------------------------------ e-mail for unread messages */

	public static function nudge() {
		global $wpdb;
		$now  = current_time( 'timestamp' ); // phpcs:ignore
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_threads() . ' WHERE last_at BETWEEN %s AND %s', gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now - 10 * MINUTE_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $rows as $t ) {
			foreach ( self::audience( $t, (int) $t['last_uid'] ) as $to ) {
				$r = $wpdb->get_row( $wpdb->prepare( 'SELECT last_read, mailed FROM ' . self::t_reads() . ' WHERE thread_id = %d AND user_id = %d', $t['id'], $to ), ARRAY_A ); // phpcs:ignore
				if ( $r && ( (int) $r['last_read'] >= (int) $t['last_id'] || (int) $r['mailed'] >= (int) $t['last_id'] ) ) { continue; }
				// The office: one e-mail per conversation is enough — skip if a colleague already read it.
				$role = self::role( $to );
				if ( self::is_staff_role( $role ) && 'lesson' !== $t['kind'] ) {
					$read_by_office = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_reads() . ' WHERE thread_id = %d AND user_id NOT IN (%d, %d) AND last_read >= %d', $t['id'], (int) $t['student_uid'], (int) $t['instructor_uid'], $t['last_id'] ) ); // phpcs:ignore
					if ( $read_by_office ) { continue; }
				}
				self::mail( $t, $to );
				$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::t_reads() . ' (thread_id, user_id, last_read, mailed) VALUES (%d, %d, 0, %d) ON DUPLICATE KEY UPDATE mailed = VALUES(mailed)', $t['id'], $to, $t['last_id'] ) ); // phpcs:ignore
			}
		}
	}

	protected static function mail( $t, $to ) {
		global $wpdb;
		$u = get_userdata( $to );
		if ( ! $u || ! is_email( $u->user_email ) || get_user_meta( $to, 'sdsc_preview', true ) ) { return; }
		$msgs = array_reverse( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_msgs() . ' WHERE thread_id = %d AND user_id <> %d ORDER BY id DESC LIMIT 3', $t['id'], $to ), ARRAY_A ) ); // phpcs:ignore
		if ( ! $msgs ) { return; }
		$f    = 'font-family:Arial,Helvetica,sans-serif;';
		$h    = '<p style="' . $f . 'margin:0 0 14px;font-size:15px;color:#15191d">Hi ' . esc_html( $u->first_name ? $u->first_name : $u->display_name ) . ', you have a new message on Sam’s Driving School:</p>';
		foreach ( $msgs as $m ) {
			$h .= '<div style="margin:0 0 10px;padding:12px 14px;border-radius:12px;background:#f3f6f8"><div style="' . $f . 'font-size:12px;font-weight:700;color:#5b646d;margin:0 0 4px">' . esc_html( self::name( (int) $m['user_id'] ) ) . ' · ' . esc_html( date_i18n( 'M j, g:i A', strtotime( $m['created'] ) ) ) . '</div><div style="' . $f . 'font-size:14px;line-height:1.55;color:#15191d">' . nl2br( esc_html( $m['body'] ) ) . '</div></div>';
		}
		$url = class_exists( 'SDSC_Short' ) ? SDSC_Short::make( add_query_arg( 't', $t['id'], self::page_url() ) ) : add_query_arg( 't', $t['id'], self::page_url() );
		$h  .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:16px auto 6px"><tr><td style="border-radius:999px;background:#00703c"><a href="' . esc_url( $url ) . '" style="' . $f . 'display:inline-block;padding:13px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px">Open and reply →</a></td></tr></table>';
		$sub = 'New message from ' . self::name( (int) end( $msgs )['user_id'] );
		$hdr = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( class_exists( 'SDSC_Mailer' ) ) { SDSC_Mailer::send( $u->user_email, $sub, $h, 'info', $hdr ); } else { wp_mail( $u->user_email, $sub, $h, $hdr ); }
	}

	/* ------------------------------------------------------------ dashboard page */

	public static function page_url() {
		return function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( self::TAB ) : home_url( '/dashboard/' . self::TAB . '/' );
	}

	public static function tutor_nav( $items ) {
		if ( ! is_array( $items ) ) { return $items; }
		$items[ self::TAB ] = array( 'title' => 'Messages', 'icon' => 'notes', 'active_icon' => 'notes' );
		return $items;
	}

	public static function tutor_template( $tpl ) {
		global $wp_query;
		if ( self::TAB === ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' ) ) { return dirname( __DIR__ ) . '/templates/tutor-messages.php'; }
		return $tpl;
	}
}
