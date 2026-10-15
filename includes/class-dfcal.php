<?php
/**
 * Lesson calendar (DriveFlow → "Visual Calendar" page, also inside the dashboard).
 *
 * Replaces DriveFlow's fixed 2-hour grid, which hid every lesson that did not start on an even hour or
 * ran before 8 AM / after 8 PM, drew 1-hour lessons as 2 hours, used the computer's time zone instead of
 * the school's, showed made-up cars and instructors when the list was empty, and only worked for the
 * administrator. This calendar:
 *
 *  - Day, Week, Month and List views, plus a day view by instructor or by car; 7 AM – 9 PM in 30-minute
 *    rows; every lesson at its real time and length; lessons at the same time side by side.
 *  - School time (America/New_York) everywhere; "now" line from the server clock.
 *  - Lessons are 2 hours. Drag a lesson to move it (the length is changed in the details panel) — every change goes through the
 *    same checks as booking (instructor / car / student busy, blocked time, opening hours, the MVA
 *    2-hours-a-day rule); warnings must be confirmed; the student is told only when the office ticks it.
 *  - Click a lesson: details, call / text / e-mail, move, change instructor or car, cancel (late
 *    cancellation fee goes to the manager to decide), no-show / no permit / completed after it started.
 *  - Click or drag over empty time: book a lesson there (the Schedule Lesson form) or block the time.
 *  - Filters by instructor, car, status and student; remembered per person; prints the visible view.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_DFCal {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 5 );
		foreach ( array( 'events', 'move', 'status', 'block', 'unblock' ) as $a ) {
			add_action( 'wp_ajax_sdsc_cal_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function can() { return class_exists( 'SDSC_DFBook' ) && SDSC_DFBook::can(); }

	protected static function on_page() {
		return 'driveflow-pro-calendar' === ( $_GET['page'] ?? '' ) && class_exists( 'SDSC_DriveFlow' ) && SDSC_DriveFlow::on() && self::can(); // phpcs:ignore
	}

	protected static function ts( $local ) { return SDSC_Lessons::ts( $local ); }

	/** Real people only: no dashboard-preview or DriveFlow sample records. */
	protected static function real( $col ) { return " AND {$col} NOT LIKE '%@preview.samsdrivingschool.invalid' AND {$col} NOT LIKE '%@example.com' AND {$col} NOT LIKE '%@example.org'"; }

	public static function instructors() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT id, name, phone, status FROM {$wpdb->prefix}driveflow_instructors WHERE status = 'active'" . self::real( 'email' ) . " AND name NOT LIKE 'Demo %' AND name NOT LIKE 'Preview %' ORDER BY name", ARRAY_A ); // phpcs:ignore
		$pal  = array( '#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#dc2626', '#4f46e5', '#0d9488', '#b45309', '#9333ea' );
		$out  = array();
		foreach ( $rows as $i => $r ) { $out[] = array( 'id' => (int) $r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'color' => $pal[ $i % count( $pal ) ] ); }
		return $out;
	}

	public static function cars() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT id, plate_number, model, color, transmission, maintenance_status FROM {$wpdb->prefix}driveflow_vehicles WHERE status = 'active' ORDER BY plate_number", ARRAY_A ); // phpcs:ignore
		return array_map( function ( $r ) { return array( 'id' => (int) $r['id'], 'plate' => $r['plate_number'], 'model' => trim( $r['model'] . ( $r['color'] ? ' · ' . $r['color'] : '' ) ), 'down' => 'operational' !== $r['maintenance_status'] ? $r['maintenance_status'] : '' ); }, $rows );
	}

	public static function assets( $hook ) {
		if ( ! self::on_page() ) { return; }
		$v = SDSC_VERSION . '.' . filemtime( SDSC_DIR . 'assets/dfcal.js' ) . '.' . filemtime( SDSC_DIR . 'assets/dfcal.css' );
		wp_enqueue_style( 'sdsc-dfcal', SDSC_URL . 'assets/dfcal.css', array(), $v );
		// Enqueued before DriveFlow (priority 5), so it is printed before DriveFlow's admin.js and takes its place.
		wp_enqueue_script( 'sdsc-dfcal', SDSC_URL . 'assets/dfcal.js', array(), $v, true );
		$s = SDSC_Lessons::settings();
		wp_localize_script( 'sdsc-dfcal', 'SDSC_CAL', array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sdsc_cal' ),
			'now'     => current_time( 'Y-m-d H:i:s' ),
			'tz'      => wp_timezone_string(),
			'tzShort' => wp_date( 'T' ),
			'open'    => SDSC_DFBook::OPENS,
			'close'   => SDSC_DFBook::CLOSES,
			'day'     => SDSC_DFBook::DAY,
			'slots'   => SDSC_DFBook::slots(),
			'early'   => SDSC_DFBook::early_ok(),
			'hours'   => (int) $s['hours'],
			'ins'     => self::instructors(),
			'cars'    => self::cars(),
			'types'   => array_map( function ( $t ) { return $t[0]; }, SDSC_DFBook::types() ),
			'records' => admin_url( 'admin.php?page=driveflow-pro-records' ),
			'lesson'  => admin_url( 'admin.php?page=sdsc-lessons&tab=schedule&id=' ),
			'boss'    => current_user_can( 'manage_options' ) || ( class_exists( 'SDSC_Board' ) && SDSC_Board::can() ),
			'user'    => get_current_user_id(),
		) );
	}

	protected static function guard() {
		if ( ! self::can() || ! check_ajax_referer( 'sdsc_cal', '_n', false ) ) { wp_send_json_error( array( 'msg' => 'Your session has expired or you are not allowed. Reload the page.' ), 403 ); }
	}

	protected static function day( $v, $def ) { return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? $v : $def; }

	/** A session row → what the calendar draws. */
	protected static function shape( $s, $lessons, $students ) {
		$fd  = json_decode( (string) $s['form_data'], true );
		$fd  = is_array( $fd ) ? $fd : array();
		$l   = $lessons[ (int) $s['id'] ] ?? null;
		$st  = $students[ (int) $s['student_id'] ] ?? null;
		$out = $l && 'booked' !== $l['status'] ? $l['status'] : '';
		if ( 'cancelled' === $s['status'] && ! empty( $fd['outcome'] ) ) { $out = $fd['outcome']; }
		return array(
			'id'     => (int) $s['id'],
			'start'  => $s['scheduled_start'],
			'end'    => $s['scheduled_end'],
			'status' => $s['status'],
			'out'    => $out,
			'name'   => $s['student_name'],
			'email'  => $l['email'] ?? ( $fd['email'] ?? ( $st['email'] ?? '' ) ),
			'phone'  => $l['phone'] ?? ( $fd['phone'] ?? ( $st['phone'] ?? '' ) ),
			'ins'    => $s['instructor_name'],
			'insId'  => (int) $s['instructor_id'],
			'plate'  => $s['plate_number'],
			'carId'  => (int) $s['vehicle_id'],
			'no'     => (int) $s['session_number'],
			'topic'  => $s['lesson_topic'],
			'type'   => (string) ( $fd['type'] ?? '' ),
			'place'  => $l['place'] ?? ( $fd['place'] ?? '' ),
			'note'   => (string) ( $fd['note'] ?? '' ),
			'permit' => (string) ( $fd['permit'] ?? '' ),
			'lid'    => $l ? (int) $l['id'] : 0,
			'fee'    => $l ? (int) $l['fee'] : 0,
		);
	}

	public static function ajax_events() {
		self::guard();
		global $wpdb;
		$from = self::day( $_POST['from'] ?? '', current_time( 'Y-m-d' ) ); // phpcs:ignore
		$to   = self::day( $_POST['to'] ?? '', $from ); // phpcs:ignore
		if ( $to < $from ) { $to = $from; }
		$T    = SDSC_DriveFlow::t();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM `{$T}` s WHERE s.scheduled_start < %s AND s.scheduled_end > %s AND s.student_name NOT LIKE 'Preview %%' AND s.student_name NOT LIKE 'Demo %%' ORDER BY s.scheduled_start, s.id", $to . ' 23:59:59', $from . ' 00:00:00' ), ARRAY_A ); // phpcs:ignore
		$ids  = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$sids = array_values( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'student_id' ) ) ) );
		$less = array();
		if ( $ids ) {
			$LT = SDSC_Lessons::t_lessons();
			$FT = SDSC_Lessons::t_fees();
			foreach ( (array) $wpdb->get_results( "SELECT l.id, l.df_id, l.status, l.email, l.phone, l.place, (SELECT COUNT(*) FROM `{$FT}` f WHERE f.lesson_id = l.id AND f.status IN ('review','due')) fee FROM `{$LT}` l WHERE l.df_id IN (" . implode( ',', $ids ) . ') ORDER BY l.id', ARRAY_A ) as $l ) { $less[ (int) $l['df_id'] ] = $l; } // phpcs:ignore
		}
		$studs = array();
		if ( $sids ) {
			foreach ( (array) $wpdb->get_results( "SELECT id, email, phone FROM {$wpdb->prefix}driveflow_students WHERE id IN (" . implode( ',', array_unique( $sids ) ) . ')', ARRAY_A ) as $st ) { $studs[ (int) $st['id'] ] = $st; } // phpcs:ignore
		}
		$ev = array();
		foreach ( $rows as $s ) { $ev[] = self::shape( $s, $less, $studs ); }
		$bt  = $wpdb->prefix . 'driveflow_blocked_slots';
		$blk = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$bt}` WHERE start_time < %s AND end_time > %s ORDER BY start_time", $to . ' 23:59:59', $from . ' 00:00:00' ), ARRAY_A ) as $b ) { // phpcs:ignore
				$blk[] = array( 'id' => (int) $b['id'], 'type' => $b['target_type'], 'target' => $b['target_identifier'], 'start' => $b['start_time'], 'end' => $b['end_time'], 'reason' => $b['reason'] );
			}
		}
		wp_send_json_success( array( 'events' => $ev, 'blocked' => $blk, 'now' => current_time( 'Y-m-d H:i:s' ) ) );
	}

	protected static function session( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDSC_DriveFlow::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	}

	/** After a change: the BTW lesson list (student told only when asked), Wappointment, TV board. */
	protected static function after( $id, $tell ) {
		$row = self::session( $id );
		if ( $row ) { SDSC_DriveFlow::apply( $row, (bool) $tell ); }
		if ( class_exists( 'DriveFlow_Wappointment_Sync' ) ) {
			if ( $row && 'cancelled' === $row['status'] && method_exists( 'DriveFlow_Wappointment_Sync', 'cancel_session_in_wapp' ) ) { DriveFlow_Wappointment_Sync::cancel_session_in_wapp( $id ); }
			elseif ( method_exists( 'DriveFlow_Wappointment_Sync', 'push_session_to_wapp' ) ) { DriveFlow_Wappointment_Sync::push_session_to_wapp( $id ); }
		}
		if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
	}

	/** Move / resize / change instructor or car — with the booking checks. */
	public static function ajax_move() {
		self::guard();
		global $wpdb;
		$p   = wp_unslash( $_POST ); // phpcs:ignore -- read field by field below
		$id  = absint( $p['id'] ?? 0 );
		$s   = self::session( $id );
		if ( ! $s ) { wp_send_json_error( array( 'msg' => 'This lesson is no longer on the calendar.' ) ); }
		if ( ! in_array( $s['status'], array( 'upcoming', 'active' ), true ) ) { wp_send_json_error( array( 'msg' => 'Only upcoming lessons can be moved.' ) ); }
		$start = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) ( $p['start'] ?? '' ) ) ? $p['start'] . ':00' : $s['scheduled_start'];
		$min   = (int) ( $p['minutes'] ?? 0 );
		$min   = $min >= 30 && $min <= 240 && 0 === $min % 30 ? $min : max( 30, (int) round( ( self::ts( $s['scheduled_end'] ) - self::ts( $s['scheduled_start'] ) ) / 60 ) );
		$ins   = isset( $p['ins'] ) && '' !== $p['ins'] ? absint( $p['ins'] ) : (int) $s['instructor_id'];
		$car   = isset( $p['car'] ) && '' !== $p['car'] ? absint( $p['car'] ) : (int) $s['vehicle_id'];
		if ( ! $ins && $s['instructor_name'] ) { $ins = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}driveflow_instructors WHERE name = %s ORDER BY status = 'active' DESC LIMIT 1", $s['instructor_name'] ) ); } // phpcs:ignore
		if ( ! $car && $s['plate_number'] ) { $car = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}driveflow_vehicles WHERE plate_number = %s LIMIT 1", $s['plate_number'] ) ); } // phpcs:ignore
		$fd  = json_decode( (string) $s['form_data'], true );
		$fd  = is_array( $fd ) ? $fd : array();
		$st  = $s['student_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT email, phone FROM {$wpdb->prefix}driveflow_students WHERE id = %d", $s['student_id'] ), ARRAY_A ) : null; // phpcs:ignore
		$d   = array(
			'start' => $start, 'minutes' => $min, 'type' => (string) ( $fd['type'] ?? 'extra' ), 'ins' => $ins, 'ins_new' => '', 'car' => $car,
			'name' => $s['student_name'], 'email' => (string) ( $fd['email'] ?? ( $st['email'] ?? '' ) ), 'phone' => (string) ( $fd['phone'] ?? ( $st['phone'] ?? '' ) ),
			'permit' => 'yes', 'exclude' => $id,
		);
		list( $err, $warn ) = SDSC_DFBook::check( $d );
		if ( $err ) { wp_send_json_error( array( 'msg' => 'Not moved: ' . implode( ' ', $err ), 'errors' => $err ) ); }
		$warn = array_values( array_filter( $warn, function ( $w ) { return false === strpos( $w, 'learner’s permit' ) && false === strpos( $w, "learner's permit" ); } ) );
		if ( $warn && empty( $p['force'] ) ) { wp_send_json_success( array( 'confirm' => 1, 'warnings' => $warn ) ); }
		$i   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name FROM {$wpdb->prefix}driveflow_instructors WHERE id = %d", $ins ), ARRAY_A ); // phpcs:ignore
		$c   = $wpdb->get_row( $wpdb->prepare( "SELECT id, plate_number FROM {$wpdb->prefix}driveflow_vehicles WHERE id = %d", $car ), ARRAY_A ); // phpcs:ignore
		$end = wp_date( 'Y-m-d H:i:s', self::ts( $start ) + $min * 60 );
		$wpdb->update( SDSC_DriveFlow::t(), array(
			'scheduled_start' => $start, 'scheduled_end' => $end,
			'instructor_id' => (int) $i['id'], 'instructor_name' => $i['name'],
			'vehicle_id' => (int) $c['id'], 'plate_number' => $c['plate_number'],
			'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $id ) );
		self::after( $id, ! empty( $p['tell'] ) );
		$what = array();
		if ( $start !== $s['scheduled_start'] || $end !== $s['scheduled_end'] ) { $what[] = wp_date( 'D M j, g:i', self::ts( $start ) ) . '–' . wp_date( 'g:i A', self::ts( $end ) ); }
		if ( $i['name'] !== $s['instructor_name'] ) { $what[] = 'with ' . $i['name']; }
		if ( $c['plate_number'] !== $s['plate_number'] ) { $what[] = 'car ' . $c['plate_number']; }
		wp_send_json_success( array( 'msg' => $s['student_name'] . ': ' . ( $what ? implode( ', ', $what ) : 'no change' ) . '.' . ( ! empty( $p['tell'] ) && $what ? ' The student was told.' : '' ) ) );
	}

	/** Cancel, no-show, no permit, completed, or back to upcoming. */
	public static function ajax_status() {
		self::guard();
		global $wpdb;
		$id   = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
		$to   = sanitize_key( $_POST['to'] ?? '' ); // phpcs:ignore
		$tell = ! empty( $_POST['tell'] ); // phpcs:ignore
		$s    = self::session( $id );
		if ( ! $s ) { wp_send_json_error( array( 'msg' => 'This lesson is no longer on the calendar.' ) ); }
		$now     = current_time( 'mysql' );
		$started = self::ts( $s['scheduled_start'] ) <= time();
		$fd      = json_decode( (string) $s['form_data'], true );
		$fd      = is_array( $fd ) ? $fd : array();
		$l       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . ' WHERE df_id = %d ORDER BY id DESC LIMIT 1', $id ), ARRAY_A ); // phpcs:ignore
		if ( 'cancel' === $to ) {
			if ( ! in_array( $s['status'], array( 'upcoming', 'active' ), true ) ) { wp_send_json_error( array( 'msg' => 'Only upcoming lessons can be cancelled.' ) ); }
			$fd['cancelled_by'] = get_current_user_id();
			$wpdb->update( SDSC_DriveFlow::t(), array( 'status' => 'cancelled', 'form_data' => wp_json_encode( $fd ), 'updated_at' => $now ), array( 'id' => $id ) );
			self::after( $id, $tell );
			$l2  = $l ? SDSC_Lessons::lesson( (int) $l['id'] ) : null;
			$msg = $s['student_name'] . ': lesson cancelled.';
			if ( $l2 && 'late_cancel' === $l2['status'] ) { $msg .= ' Less than ' . (int) SDSC_Lessons::settings()['hours'] . ' hours before — a late-cancellation fee waits for the manager to decide (nothing charged).'; }
			elseif ( $tell ) { $msg .= ' The student was told.'; }
			wp_send_json_success( array( 'msg' => $msg ) );
		}
		if ( in_array( $to, array( 'no_show', 'no_permit', 'done' ), true ) ) {
			if ( ! $started ) { wp_send_json_error( array( 'msg' => 'This lesson has not started yet.' ) ); }
			if ( 'completed' === $s['status'] ) { wp_send_json_error( array( 'msg' => 'This lesson is already completed.' ) ); }
			if ( 'done' === $to ) {
				$wpdb->update( SDSC_DriveFlow::t(), array( 'status' => 'completed', 'updated_at' => $now ), array( 'id' => $id ) );
				if ( $s['student_id'] && class_exists( 'DriveFlow_Credit_Manager' ) && method_exists( 'DriveFlow_Credit_Manager', 'deduct_session_credit' ) ) { DriveFlow_Credit_Manager::deduct_session_credit( (int) $s['student_id'], $id ); }
				self::after( $id, false );
				wp_send_json_success( array( 'msg' => $s['student_name'] . ': marked completed. The instructor’s evaluation can still be added in Evaluations & records.' ) );
			}
			$fd['outcome'] = $to;
			$wpdb->update( SDSC_DriveFlow::t(), array( 'status' => 'cancelled', 'form_data' => wp_json_encode( $fd ), 'updated_at' => $now ), array( 'id' => $id ) );
			if ( $l && 'booked' === $l['status'] ) {
				$wpdb->update( SDSC_Lessons::t_lessons(), array( 'status' => $to ), array( 'id' => $l['id'] ) );
				SDSC_Lessons::add_log( $l['id'], 'Marked on the lesson calendar: ' . SDSC_Lessons::statuses()[ $to ][0] );
				SDSC_Lessons::make_fee( SDSC_Lessons::lesson( (int) $l['id'] ), $to );
			}
			if ( class_exists( 'DriveFlow_Wappointment_Sync' ) && method_exists( 'DriveFlow_Wappointment_Sync', 'cancel_session_in_wapp' ) ) { DriveFlow_Wappointment_Sync::cancel_session_in_wapp( $id ); }
			if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
			wp_send_json_success( array( 'msg' => $s['student_name'] . ': ' . ( 'no_show' === $to ? 'no-show' : 'no learner’s permit' ) . ' recorded. ' . ( $l ? 'The fee waits for the manager to decide — nothing charged or sent.' : '' ) ) );
		}
		wp_send_json_error( array( 'msg' => 'Unknown action.' ) );
	}

	public static function ajax_block() {
		self::guard();
		$p    = wp_unslash( $_POST ); // phpcs:ignore
		$type = in_array( $p['type'] ?? '', array( 'all', 'instructor', 'vehicle' ), true ) ? $p['type'] : 'all';
		$a    = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) ( $p['start'] ?? '' ) ) ? $p['start'] . ':00' : '';
		$b    = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) ( $p['end'] ?? '' ) ) ? $p['end'] . ':00' : '';
		if ( ! $a || ! $b || $b <= $a ) { wp_send_json_error( array( 'msg' => 'Choose a start and an end after it.' ) ); }
		$target = '';
		global $wpdb;
		if ( 'instructor' === $type ) { $target = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}driveflow_instructors WHERE id = %d", absint( $p['target'] ?? 0 ) ) ); } // phpcs:ignore
		if ( 'vehicle' === $type ) { $target = (string) $wpdb->get_var( $wpdb->prepare( "SELECT plate_number FROM {$wpdb->prefix}driveflow_vehicles WHERE id = %d", absint( $p['target'] ?? 0 ) ) ); } // phpcs:ignore
		if ( 'all' !== $type && '' === $target ) { wp_send_json_error( array( 'msg' => 'Choose who or which car.' ) ); }
		$why = sanitize_text_field( mb_substr( (string) ( $p['reason'] ?? '' ), 0, 200 ) );
		// Lessons already booked in that time are listed so the office can move them.
		$hit = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT student_name, instructor_name, plate_number, scheduled_start FROM ' . SDSC_DriveFlow::t() . " WHERE status IN ('upcoming','active') AND scheduled_start < %s AND scheduled_end > %s" . ( 'instructor' === $type ? ' AND instructor_name = %s' : ( 'vehicle' === $type ? ' AND plate_number = %s' : ' AND %s = %s' ) ) . ' ORDER BY scheduled_start', array_merge( array( $b, $a ), 'all' === $type ? array( '1', '1' ) : array( $target ) ) ), ARRAY_A ); // phpcs:ignore
		$id = class_exists( 'DriveFlow_Availability_Engine' ) ? DriveFlow_Availability_Engine::add_blocked_slot( $type, $target, $a, $b, $why ) : 0;
		if ( ! $id ) {
			$wpdb->insert( $wpdb->prefix . 'driveflow_blocked_slots', array( 'target_type' => $type, 'target_identifier' => $target, 'start_time' => $a, 'end_time' => $b, 'reason' => $why, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) );
			$id = (int) $wpdb->insert_id;
		}
		if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
		$msg = 'Time blocked' . ( $target ? ' for ' . $target : ' for the whole school' ) . '.';
		if ( $hit ) { $msg .= ' Already booked in that time: ' . implode( '; ', array_map( function ( $h ) { return $h['student_name'] . ' ' . wp_date( 'D g:i A', SDSC_Lessons::ts( $h['scheduled_start'] ) ); }, $hit ) ) . ' — move or cancel these.'; }
		wp_send_json_success( array( 'msg' => $msg, 'id' => $id ) );
	}

	public static function ajax_unblock() {
		self::guard();
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
		if ( class_exists( 'DriveFlow_Availability_Engine' ) && method_exists( 'DriveFlow_Availability_Engine', 'delete_blocked_slot' ) ) { DriveFlow_Availability_Engine::delete_blocked_slot( $id ); }
		else { $wpdb->delete( $wpdb->prefix . 'driveflow_blocked_slots', array( 'id' => $id ) ); }
		if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
		wp_send_json_success( array( 'msg' => 'Blocked time removed.' ) );
	}
}
