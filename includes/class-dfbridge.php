<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * DriveFlow Pro ↔ behind-the-wheel lessons.
 *
 * DriveFlow Pro is where lessons are booked, moved and given an instructor and a car (calendar, student
 * booking links, instructor app, lobby TV). This bridge copies every DriveFlow session into the BTW lesson
 * list so the school's policy tools keep working on it:
 *  - the student gets the policy confirmation (EN/ES, free-cancel deadline, $60 fee, "I'll be there" buttons,
 *    calendar link) and the reminders; the confirmation text waits in Texts to send;
 *  - a move in DriveFlow sends "your lesson has changed"; a cancellation in DriveFlow is checked against the
 *    48-hour rule (in time = cancellation notice, late = fee due, which can be waived);
 *  - "Completed" in DriveFlow (the instructor's evaluation form) closes the lesson;
 *  - cancelling from the BTW screen also cancels the DriveFlow session.
 * DriveFlow's own student booking and cancellation e-mails are switched off once, so students never get two.
 * Sync runs right after any calendar change, every 5 minutes, and when the BTW screen opens.
 */
class SDSC_DriveFlow {

	const OPT  = 'sdsc_df_sync';
	const LOCK = 'sdsc_df_lock';

	protected static $queued = false;

	public static function on() { return class_exists( 'DriveFlow_Pro' ); }

	public static function t() { global $wpdb; return $wpdb->prefix . 'driveflow_sessions'; }

	public static function calendar_url() { return admin_url( 'admin.php?page=driveflow-pro-calendar' ); }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 25 );
		add_action( 'driveflow_calendar_changed', array( __CLASS__, 'queue' ) );
		add_action( 'sdsc_schedule_tick', array( __CLASS__, 'sync' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'template_redirect', array( __CLASS__, 'link_me' ), 5 );
		add_action( 'set_user_role', array( __CLASS__, 'on_role' ), 10, 2 );
		add_action( 'add_user_role', array( __CLASS__, 'on_role' ), 10, 2 );
	}

	/* ------------------------------------------------------------ instructor accounts ↔ DriveFlow profiles */

	public static function instructor_roles() { return array( 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' ); }

	/**
	 * Make sure an instructor's site account has a DriveFlow instructor profile, so the in-car app and the
	 * lesson calendar know them: link an existing profile with the same e-mail, or create one.
	 * Returns the DriveFlow instructor id, or 0.
	 */
	public static function ensure_instructor( $user ) {
		$u = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $u || ! self::on() || ! array_intersect( (array) $u->roles, self::instructor_roles() ) ) { return 0; }
		global $wpdb;
		$t = $wpdb->prefix . 'driveflow_instructors';
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE user_id = %d LIMIT 1", $u->ID ) ); // phpcs:ignore
		if ( $id ) { return $id; }
		if ( $u->user_email ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE email = %s AND user_id = 0 LIMIT 1", $u->user_email ) ); // phpcs:ignore
			if ( $id ) { $wpdb->update( $t, array( 'user_id' => $u->ID, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ); return $id; }
		}
		$now  = current_time( 'mysql' );
		$name = trim( $u->first_name . ' ' . $u->last_name );
		$wpdb->insert( $t, array(
			'user_id'    => $u->ID,
			'name'       => $name ? $name : $u->display_name,
			'email'      => $u->user_email,
			'phone'      => (string) get_user_meta( $u->ID, 'billing_phone', true ),
			'status'     => 'active',
			'created_at' => $now,
			'updated_at' => $now,
		) );
		if ( $wpdb->insert_id && class_exists( 'DriveFlow_Pro' ) && method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
		return (int) $wpdb->insert_id;
	}

	/** When an instructor opens any page, link their profile (checked at most once a day). */
	public static function link_me() {
		if ( ! is_user_logged_in() ) { return; }
		$k = 'sdsc_dfi_' . get_current_user_id();
		if ( get_transient( $k ) ) { return; }
		self::ensure_instructor( wp_get_current_user() );
		set_transient( $k, 1, DAY_IN_SECONDS );
	}

	public static function on_role( $user_id, $role ) {
		if ( in_array( $role, self::instructor_roles(), true ) ) { delete_transient( 'sdsc_dfi_' . $user_id ); self::ensure_instructor( $user_id ); }
	}

	/** "Lesson calendar" in the Sam's School menu opens DriveFlow's calendar. */
	public static function menu() {
		if ( self::on() ) {
			add_submenu_page( 'sdsc-dashboard', 'Lesson calendar', 'Lesson calendar', 'driveflow_manage', 'admin.php?page=driveflow-pro-calendar' );
		}
	}

	public static function install() {
		if ( ! self::on() ) { return; }
		global $wpdb;
		$t = SDSC_Lessons::t_lessons();
		if ( get_option( 'sdsc_df_col' ) !== '1' ) {
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `{$t}` LIKE 'df_id'" ) ) { // phpcs:ignore
				$wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN `df_id` bigint(20) unsigned NOT NULL DEFAULT 0, ADD KEY `df_id` (`df_id`)" ); // phpcs:ignore
			}
			update_option( 'sdsc_df_col', '1', false );
		}
		// Students get the policy confirmation and cancellation from us, not twice.
		if ( get_option( 'sdsc_df_emails' ) !== '1' ) {
			$s = (array) get_option( 'driveflow_pro_settings', array() );
			$s['email_student_booking'] = '0';
			$s['email_cancellation']    = '0';
			update_option( 'driveflow_pro_settings', $s );
			update_option( 'sdsc_df_emails', '1', false );
		}
	}

	public static function queue() {
		if ( self::$queued ) { return; }
		self::$queued = true;
		add_action( 'shutdown', array( __CLASS__, 'sync' ) );
	}

	protected static function by_df( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . ' WHERE df_id = %d ORDER BY id DESC LIMIT 1', $id ), ARRAY_A ); // phpcs:ignore
	}

	/** Copy new and changed DriveFlow sessions. */
	public static function sync() {
		if ( ! self::on() || get_transient( self::LOCK ) ) { return; }
		set_transient( self::LOCK, 1, 60 );
		global $wpdb;
		$t     = self::t();
		$last  = (string) get_option( self::OPT, '' );
		$first = '' === $last;
		$since = $first ? wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) : wp_date( 'Y-m-d H:i:s', SDSC_Lessons::ts( $last ) - 120 );
		$col   = $first ? 'scheduled_start' : 'updated_at';
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE {$col} >= %s ORDER BY updated_at, id LIMIT 500", $since ), ARRAY_A ); // phpcs:ignore
		$max   = $last;
		foreach ( $rows as $s ) {
			self::apply( $s, ! $first );
			if ( $s['updated_at'] > $max ) { $max = $s['updated_at']; }
		}
		// Sessions deleted in DriveFlow.
		$gone = (array) $wpdb->get_results( 'SELECT l.* FROM ' . SDSC_Lessons::t_lessons() . " l LEFT JOIN `{$t}` s ON s.id = l.df_id WHERE l.df_id > 0 AND l.status = 'booked' AND s.id IS NULL", ARRAY_A ); // phpcs:ignore
		foreach ( $gone as $l ) {
			$wpdb->update( SDSC_Lessons::t_lessons(), array( 'status' => 'cancelled', 'notice_at' => current_time( 'mysql' ) ), array( 'id' => $l['id'] ) );
			SDSC_Lessons::add_log( $l['id'], 'The session was deleted in DriveFlow — marked cancelled (no message sent).' );
		}
		update_option( self::OPT, $max ?: current_time( 'mysql' ), false );
		delete_transient( self::LOCK );
	}

	/** Student details from DriveFlow. */
	protected static function student( $s ) {
		global $wpdb;
		$st = $s['student_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT name, email, phone FROM {$wpdb->prefix}driveflow_students WHERE id = %d", $s['student_id'] ), ARRAY_A ) : null; // phpcs:ignore
		$name  = trim( (string) ( $st['name'] ?? $s['student_name'] ) );
		$parts = preg_split( '/\s+/', $name, 2 );
		$email = strtolower( sanitize_email( (string) ( $st['email'] ?? '' ) ) );
		if ( preg_match( '/@example\.(com|org|net)$/', $email ) ) { $email = ''; }   // DriveFlow demo data
		return array(
			'first_name' => $parts[0] ?? '',
			'last_name'  => $parts[1] ?? '',
			'email'      => $email,
			'phone'      => (string) ( $st['phone'] ?? '' ),
		);
	}

	protected static function fields( $s ) {
		$a = SDSC_Lessons::ts( $s['scheduled_start'] );
		$b = SDSC_Lessons::ts( $s['scheduled_end'] );
		return array_merge( self::student( $s ), array(
			'lesson_no'  => min( 250, max( 0, (int) $s['session_number'] ) ),
			'starts_at'  => $s['scheduled_start'],
			'minutes'    => max( 30, min( 480, (int) round( ( $b - $a ) / 60 ) ) ),
			'instructor' => (string) $s['instructor_name'],
		) );
	}

	protected static function apply( $s, $mail ) {
		global $wpdb;
		$T      = SDSC_Lessons::t_lessons();
		$l      = self::by_df( $s['id'] );
		$st     = (string) $s['status'];
		$d      = self::fields( $s );
		$future = SDSC_Lessons::ts( $d['starts_at'] ) > time();

		if ( ! $l ) {
			if ( 'cancelled' === $st ) { return; }
			$u = $d['email'] ? get_user_by( 'email', $d['email'] ) : null;
			$wpdb->insert( $T, array_merge( $d, array(
				'df_id'      => (int) $s['id'],
				'ckey'       => SDSC_Outreach::key( $d['email'] ?: $d['phone'], $d['first_name'] ),
				'user_id'    => $u ? $u->ID : 0,
				'lang'       => class_exists( 'SDSC_Schedule' ) && $d['email'] ? SDSC_Schedule::lang( $d['email'] ) : 'en',
				'place'      => SDSC_Lessons::settings()['place'],
				'status'     => 'completed' === $st ? 'done' : 'booked',
				'created_by' => (int) $s['created_by'],
				'created_at' => current_time( 'mysql' ),
			) ) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { return; }
			SDSC_Lessons::add_log( $id, 'Booked in DriveFlow for ' . SDSC_Lessons::when( $d['starts_at'], 'en' ) . ( $d['instructor'] ? ' with ' . $d['instructor'] : '' ) );
			if ( $mail && $future && 'completed' !== $st && $d['email'] && SDSC_Lessons::mail( 'confirm', SDSC_Lessons::lesson( $id ) ) ) {
				$wpdb->update( $T, array( 'conf_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			}
			return;
		}

		// Outcomes recorded here (no-show, no permit, late cancel, done) are final.
		if ( 'booked' !== $l['status'] ) { return; }

		if ( 'cancelled' === $st ) {
			$notice = (string) $s['updated_at'];
			$late   = SDSC_Lessons::ts( $notice ) > SDSC_Lessons::ts( SDSC_Lessons::deadline( $l ) );
			$wpdb->update( $T, array( 'status' => $late ? 'late_cancel' : 'cancelled', 'notice_at' => $notice ), array( 'id' => $l['id'] ) );
			SDSC_Lessons::add_log( $l['id'], 'Cancelled in DriveFlow ' . SDSC_Lessons::when( $notice, 'en' ) . ( $late ? ' — less than ' . (int) SDSC_Lessons::settings()['hours'] . ' hours before: fee added (waive it if the school cancelled)' : ' — in time, no fee' ) );
			if ( $late ) {
				SDSC_Lessons::make_fee( SDSC_Lessons::lesson( $l['id'] ), 'late_cancel' );
			} elseif ( $mail && $future ) {
				SDSC_Lessons::mail( 'cancelled', SDSC_Lessons::lesson( $l['id'] ) );
			}
			return;
		}
		if ( 'completed' === $st ) {
			$wpdb->update( $T, array( 'status' => 'done' ), array( 'id' => $l['id'] ) );
			SDSC_Lessons::add_log( $l['id'], 'Completed — evaluation filed in DriveFlow' );
			return;
		}

		$moved = $d['starts_at'] !== $l['starts_at'] || (int) $d['minutes'] !== (int) $l['minutes'];
		$ins   = $d['instructor'] !== $l['instructor'];
		$who   = $d['first_name'] !== $l['first_name'] || $d['last_name'] !== $l['last_name'] || $d['email'] !== $l['email'] || $d['phone'] !== $l['phone'];
		if ( ! $moved && ! $ins && ! $who ) { return; }
		$up = $d;
		if ( $moved ) { $up['remind_at'] = null; }
		$up['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $T, $up, array( 'id' => $l['id'] ) );
		$bits = array();
		if ( $moved ) { $bits[] = 'moved from ' . SDSC_Lessons::when( $l['starts_at'], 'en' ) . ' to ' . SDSC_Lessons::when( $d['starts_at'], 'en' ); }
		if ( $ins ) { $bits[] = 'instructor: ' . ( $d['instructor'] ?: 'none' ); }
		if ( $who ) { $bits[] = 'student details updated'; }
		SDSC_Lessons::add_log( $l['id'], 'In DriveFlow: ' . implode( '; ', $bits ) );
		if ( $moved && class_exists( 'SDSC_Schedule' ) && SDSC_Schedule::row( 'btw', $l['id'], '', $l['email'] ) ) {
			SDSC_Schedule::put( 'btw', $l['id'], '', $l['email'], array( 'rsvp' => '', 'rsvp_at' => null ) );
		}
		if ( $mail && $future && ( $moved || $ins ) && $d['email'] && SDSC_Lessons::mail( 'update', SDSC_Lessons::lesson( $l['id'] ) ) ) {
			$wpdb->update( $T, array( 'conf_at' => current_time( 'mysql' ) ), array( 'id' => $l['id'] ) );
		}
	}

	/** Cancelling on the BTW screen cancels the DriveFlow session too (no DriveFlow e-mail). */
	public static function cancel_session( $df_id ) {
		global $wpdb;
		if ( ! self::on() || ! $df_id ) { return; }
		$wpdb->update( self::t(), array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $df_id ) );
		if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
	}
}
