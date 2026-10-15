<?php
/**
 * Reports on the dashboard — preview on screen, print / save as PDF with proper pages, or download CSV.
 *
 * Administrator and School manager: every report, and they decide which reports the front desk and the
 * instructors may open ("Who can open reports"). Instructors only ever see their own classes and lessons.
 *
 * Print pages: US Letter, repeating table header, rows never split across pages, and a footer with the
 * report name and "Page X of Y" (browsers that support CSS page-margin boxes; Chrome / Edge / Safari).
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Reports {
	const TAB    = 'sdsc-reports';
	const ACCESS = 'sdsc_report_access';

	public static function reports() {
		return array(
			'roster'        => array( 'Class roster', 'Students registered in a class, with contact details and payment.', 'class', false, '👥' ),
			'attendance'    => array( 'Class attendance', 'All sessions of a class: present, late, left early, absent.', 'class', true, '🗓️' ),
			'registrations' => array( 'Registrations', 'Every registration in a date range, with class and payment.', 'range', false, '📝' ),
			'exams'         => array( 'Exam results', 'Online unit quizzes and the final exam: best score, tries, date and passed / not passed.', 'people', false, '🎓' ),
			'file'          => array( 'Student files', 'The whole student file in one printout: registration form with policies and signatures, test answer sheet, attendance.', 'people', false, '🗂️' ),
			'forms'         => array( 'Registration forms', 'Each student’s full signed registration form, one page each — for a class, a date range or one student.', 'forms', false, '🖊️' ),
			'payments'      => array( 'Online payments', 'Card payments received in a date range, with totals.', 'range', false, '💳' ),
			'lessons'       => array( 'Driving lessons', 'Behind-the-wheel lessons in a date range, by instructor.', 'range', false, '🚗' ),
			'refunds'       => array( 'Refunds', 'Refunds asked for and paid in a date range: amount, kind and status.', 'range', false, '↩️' ),
			'documents'     => array( 'Student documents', 'IDs, permits, practice logs and certificates on file, with their check status.', 'range', false, '📎' ),
			'offers'        => array( 'Lesson offers', 'Times offered to students by link: who picked, what was booked, what expired.', 'range', false, '🔗' ),
			'fees'          => array( 'Lesson fees', 'Late-cancellation and no-show fees: due, paid and waived.', 'range', false, '🧾' ),
		);
	}

	public static function defaults() {
		return array( 'frontdesk' => array( 'roster', 'attendance', 'registrations', 'forms', 'exams', 'file', 'lessons', 'fees' ), 'instructor' => array( 'roster', 'attendance', 'lessons' ) );
	}

	public static function init() {
		add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'nav' ) );
		add_filter( 'tutor_instructor_dashboard_nav', array( __CLASS__, 'nav' ) );
		add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'template' ) );
		add_action( 'template_redirect', array( __CLASS__, 'output' ), 1 );
		add_action( 'admin_post_sdsc_report_access', array( __CLASS__, 'save_access' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_shortcode( 'sdsc_reports', array( __CLASS__, 'render' ) );
	}

	/* ------------------------------------------------------------ who */

	public static function kind() { return class_exists( 'SDSC_Overview' ) ? SDSC_Overview::kind() : ( current_user_can( 'manage_options' ) ? 'admin' : 'student' ); }

	public static function is_boss() { return in_array( self::kind(), array( 'admin', 'manager' ), true ); }

	public static function access() {
		$a = get_option( self::ACCESS, null );
		return is_array( $a ) ? wp_parse_args( $a, array( 'frontdesk' => array(), 'instructor' => array() ) ) : self::defaults();
	}

	/** Report keys the current user may open. */
	public static function mine() {
		if ( ! is_user_logged_in() ) { return array(); }
		$k = self::kind();
		if ( in_array( $k, array( 'admin', 'manager' ), true ) ) { return array_keys( self::reports() ); }
		if ( in_array( $k, array( 'frontdesk', 'instructor' ), true ) ) { return array_values( array_intersect( array_keys( self::reports() ), (array) self::access()[ $k ] ) ); }
		return array();
	}

	public static function can( $key ) { return in_array( $key, self::mine(), true ); }

	/* ------------------------------------------------------------ Tutor tab */

	public static function nav( $items ) {
		if ( ! is_array( $items ) ) { return $items; }
		$items[ self::TAB ] = array( 'title' => 'Reports', 'icon' => 'analytics', 'active_icon' => 'analytics-fill', 'auth_cap' => 'read' );
		if ( did_action( 'init' ) && is_user_logged_in() && ! self::mine() && ! current_user_can( 'manage_options' ) ) { unset( $items[ self::TAB ] ); }
		return $items;
	}

	public static function template( $tpl ) {
		global $wp_query;
		if ( self::TAB === ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' ) ) { return dirname( __DIR__ ) . '/templates/tutor-reports.php'; }
		return $tpl;
	}

	public static function url( $args = array() ) {
		$base = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( self::TAB ) : home_url( '/dashboard/' . self::TAB . '/' );
		return add_query_arg( $args, $base );
	}

	public static function assets() {
		if ( ! is_user_logged_in() ) { return; }
		wp_register_style( 'sdsc-reports', SDSC_URL . 'assets/reports.css', array(), SDSC_VERSION . '.' . (int) @filemtime( SDSC_DIR . 'assets/reports.css' ) );
		global $wp_query;
		if ( self::TAB === ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' ) ) { wp_enqueue_style( 'sdsc-reports' ); }
	}

	/* ------------------------------------------------------------ inputs */

	protected static function classes() {
		if ( 'instructor' === self::kind() ) { return class_exists( 'SDSC_ZoomRoll' ) ? SDSC_ZoomRoll::classes() : array(); }
		$posts = get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'numberposts' => 120, 'orderby' => 'meta_value', 'meta_key' => '_sdsc_start', 'order' => 'DESC' ) ); // phpcs:ignore
		return array_values( array_filter( $posts, function ( $p ) { return ! get_post_meta( $p->ID, '_sdsc_demo_class', true ) || ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview() ); } ) );
	}

	/** Filters from the request, cleaned. */
	protected static function args() {
		$g    = function ( $k, $d = '' ) { return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : $d; }; // phpcs:ignore
		$date = function ( $v, $d ) { return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $d; };
		$a    = array(
			'r'      => sanitize_key( $g( 'r' ) ),
			'class'  => absint( $g( 'class', '0' ) ),
			'from'   => $date( $g( 'from' ), wp_date( 'Y-m-01' ) ),
			'to'     => $date( $g( 'to' ), wp_date( 'Y-m-d' ) ),
			'who'    => mb_substr( $g( 'who' ), 0, 120 ),
			'status' => sanitize_key( $g( 'status' ) ),
			'one'    => absint( $g( 'one', '0' ) ),
			'course' => absint( $g( 'course', '0' ) ),
			'rid'    => absint( $g( 'rid', '0' ) ),
			'uid'    => absint( $g( 'uid', '0' ) ),
			'parts'  => array_values( array_intersect( array( 'form', 'sheet', 'attendance', 'idcopy', 'de003' ), array_map( 'sanitize_key', (array) ( $_GET['parts'] ?? array( 'form', 'sheet', 'attendance' ) ) ) ) ), // phpcs:ignore
		);
		if ( in_array( $a['r'], array( 'exams', 'file' ), true ) && empty( $_GET['from'] ) ) { $a['from'] = wp_date( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ); } // phpcs:ignore
		if ( ! $a['parts'] ) { $a['parts'] = array( 'form', 'sheet', 'attendance' ); }
		if ( $a['from'] > $a['to'] ) { list( $a['from'], $a['to'] ) = array( $a['to'], $a['from'] ); }
		if ( 'instructor' === self::kind() ) { $a['who'] = class_exists( 'SDSC_Overview' ) ? SDSC_Overview::instructor_name( wp_get_current_user() ) : wp_get_current_user()->display_name; }
		return $a;
	}

	protected static function class_ok( $cid ) {
		return $cid && in_array( $cid, wp_list_pluck( self::classes(), 'ID' ), true );
	}

	/* ------------------------------------------------------------ data */

	protected static function d( $local, $f = 'M j, Y' ) { return $local ? wp_date( $f, strtotime( get_gmt_from_date( $local ) . ' UTC' ) ) : ''; }

	protected static function money( $n ) { return '$' . number_format( (float) $n, 2 ); }

	protected static function not_demo( $col ) { return ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview() ) ? '' : " AND $col NOT LIKE '%@preview.samsdrivingschool.invalid'"; }

	/** Build a report: [ title, subtitle, cols, rows, foot, landscape ] or WP_Error. */
	public static function build( $a ) {
		global $wpdb;
		$def = self::reports()[ $a['r'] ] ?? null;
		if ( ! $def || ! self::can( $a['r'] ) ) { return new WP_Error( 'no', 'You do not have access to this report.' ); }
		$range = self::d( $a['from'] . ' 12:00:00' ) . ' – ' . self::d( $a['to'] . ' 12:00:00' );
		$staff = 'instructor' !== self::kind();

		if ( 'class' === $def[2] ) {
			if ( ! self::class_ok( $a['class'] ) ) { return new WP_Error( 'class', 'Choose a class.' ); }
			$cid    = $a['class'];
			$name   = SDSC_Schedule::class_name( $cid, true );
			$people = SDSC_Schedule::roster( $cid );
			$sub    = trim( ( get_post_meta( $cid, '_sdsc_start', true ) ? 'Starts ' . self::d( substr( (string) get_post_meta( $cid, '_sdsc_start', true ), 0, 10 ) . ' 12:00:00' ) : '' ) . ( get_post_meta( $cid, '_sdsc_days_time', true ) ? ' · ' . get_post_meta( $cid, '_sdsc_days_time', true ) : '' ) . ' · ' . count( $people ) . ' students', ' ·' );
			if ( 'roster' === $a['r'] ) {
				$regs = array();
				if ( class_exists( 'SDSC_Registration' ) ) {
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, dob, age, parent_name, parent_phone, created_at FROM ' . SDSC_Registration::table() . ' WHERE class_id = %d ORDER BY id DESC', $cid ), ARRAY_A ) as $r ) { // phpcs:ignore
						$e = strtolower( $r['email'] );
						if ( ! isset( $regs[ $e ] ) ) { $regs[ $e ] = $r; }
					}
				}
				$cols = $staff ? array( '#', 'Student', 'Phone', 'E-mail', 'Age', 'Parent / guardian', 'Registered', 'Payment' ) : array( '#', 'Student', 'Phone', 'E-mail' );
				$rows = array();
				$i    = 0;
				foreach ( $people as $p ) {
					$r   = $regs[ $p['email'] ] ?? array();
					$row = array( ++$i, trim( $p['first'] . ' ' . $p['last'] ), $p['phone'], $p['email'] );
					if ( $staff ) {
						$paid  = $r && class_exists( 'SDSC_Pay' ) ? SDSC_Pay::paid_row( (int) $r['id'] ) : null;
						$row[] = $r && $r['age'] ? (int) $r['age'] : '';
						$row[] = $r ? trim( $r['parent_name'] . ( $r['parent_phone'] ? ' · ' . $r['parent_phone'] : '' ) ) : '';
						$row[] = $r ? self::d( $r['created_at'] ) : '';
						$row[] = $paid ? 'Paid ' . self::money( $paid['amount'] / 100 ) : 'Not paid online';
					}
					$rows[] = $row;
				}
				return array( 'Class roster — ' . $name, $sub, $cols, $rows, array(), false );
			}
			// Attendance grid.
			$list = SDSC_Schedule::sessions( $cid );
			if ( ! $list ) { return new WP_Error( 'tt', 'This class has no timetable yet, so there are no sessions to report.' ); }
			$data = class_exists( 'SDSC_ZoomRoll' ) ? SDSC_ZoomRoll::rows( $cid ) : array();
			$sym  = array( 'present' => '✓', 'late' => 'L', 'left' => 'E', 'absent' => '✗' );
			$cols = array( 'Student' );
			foreach ( $list as $x ) { $cols[] = $x['n'] . "\n" . wp_date( 'M j', SDSC_Lessons::ts( $x['start'] ) ); }
			$cols[] = 'Attended';
			$cols[] = 'Absences';
			$rows   = array();
			$today  = current_time( 'Y-m-d' );
			foreach ( $people as $p ) {
				$row = array( trim( $p['first'] . ' ' . $p['last'] ) );
				$att = 0;
				foreach ( $list as $x ) {
					$v     = $data[ $p['email'] ][ $x['date'] ]['actual'] ?? '';
					$att  += in_array( $v, array( 'present', 'late', 'left' ), true ) ? 1 : 0;
					$row[] = $v ? $sym[ $v ] : ( $x['date'] <= $today ? '?' : '' );
				}
				list( $used, $miss1 ) = SDSC_Schedule::absences( $cid, $p['email'] );
				$row[]  = $att . '/' . count( $list );
				$row[]  = $used . '/' . SDSC_Schedule::ALLOWED . ( $miss1 ? ' · missed 1' : '' );
				$rows[] = $row;
			}
			return array( 'Class attendance — ' . $name, $sub . ' · ✓ present · L late · E left early · ✗ absent · ? not marked', $cols, $rows, array(), true );
		}

		$from = $a['from'] . ' 00:00:00';
		$to   = $a['to'] . ' 23:59:59';
		if ( in_array( $a['r'], array( 'exams', 'file' ), true ) ) {
			if ( ! class_exists( 'SDSC_Student_File' ) || 'instructor' === self::kind() ) { return new WP_Error( 'x', 'This report is not available.' ); }
			if ( $a['class'] && ! self::class_ok( $a['class'] ) ) { return new WP_Error( 'class', 'Choose a class.' ); }
			$course = $a['course'] ? $a['course'] : SDSC_Student_File::default_course();
			$sub    = ( $a['class'] ? SDSC_Schedule::class_name( $a['class'], true ) : $range ) . ( $course ? ' · ' . get_the_title( $course ) : '' ) . ( '' !== $a['who'] ? ' · “' . $a['who'] . '”' : '' );
			if ( 'exams' === $a['r'] ) {
				list( $rows, $tot ) = SDSC_Student_File::exam_rows( array_merge( $a, array( 'course' => $course ) ) );
				return array( 'Exam results', $sub, array( 'File no.', 'Student', 'E-mail', 'Class', 'Unit quizzes', 'Final exam', 'Tries', 'Exam date', 'Result' ), $rows, array( 'Students' => count( $rows ), 'Passed' => $tot['pass'], 'Not passed' => $tot['fail'], 'Final not taken' => $tot['none'] ), true );
			}
			$people = SDSC_Student_File::people( array_merge( $a, array( 'course' => $course ) ), 400 );
			$res    = SDSC_Student_File::results( $course, wp_list_pluck( $people, 'user_id' ) );
			$final  = array_values( array_filter( SDSC_Student_File::quizzes( $course ), function ( $q ) { return $q[3]; } ) );
			$links  = get_option( SDSC_Student_File::LINKS, array() );
			$rows   = array();
			$miss   = 0;
			foreach ( $people as $p ) {
				$f      = $final && $p['user_id'] ? ( $res[ $p['user_id'] ][ $final[0][0] ] ?? null ) : null;
				$miss  += $p['reg'] ? 0 : 1;
				$rows[] = array( SDSC_Student_File::file_no( $p ), $p['name'], $p['email'], $p['class'] ? SDSC_Schedule::class_name( $p['class'], true ) : '—', $p['reg'] ? 'On file' . ( isset( $links[ $p['email'] ] ) ? ' (linked by hand)' : '' ) . ( $p['reg']['student_sig'] ? ' · signed' : ' · no signature' ) : 'Missing', $p['user_id'] ? ( $f ? $f['pct'] . '%' : 'Not taken' ) : 'No online account' );
			}
			return array( 'Student files', $sub, array( 'File no.', 'Student', 'E-mail', 'Class', 'Registration form', 'Final exam' ), $rows, array( 'Students' => count( $rows ), 'Without a form' => $miss ), false, array_values( $people ) );
		}
		if ( 'forms' === $a['r'] ) {
			if ( ! class_exists( 'SDSC_Registration' ) || 'instructor' === self::kind() ) { return new WP_Error( 'x', 'Registration forms are not available.' ); }
			$ids = self::form_ids( $a );
			$res = $ids ? (array) $wpdb->get_results( 'SELECT id, source, source_entry_id, class_id, first_name, last_name, dob, age, email, phone, signed_date, created_at, (student_sig IS NOT NULL) has_s, (parent_sig IS NOT NULL) has_p FROM ' . SDSC_Registration::table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY created_at', ARRAY_A ) : array(); // phpcs:ignore
			$rows = array();
			$sig  = 0;
			foreach ( $res as $r ) {
				$sig   += $r['has_s'] ? 1 : 0;
				$rows[] = array( SDSC_Registration::file_no( $r ), self::d( $r['created_at'] ), trim( $r['first_name'] . ' ' . $r['last_name'] ), $r['class_id'] ? SDSC_Schedule::class_name( (int) $r['class_id'], true ) : '—', $r['dob'] ? wp_date( 'm/d/Y', strtotime( $r['dob'] . ' 12:00:00' ) ) . ( $r['age'] ? ' (' . (int) $r['age'] . ')' : '' ) : '', $r['phone'], $r['has_s'] ? 'Signed' . ( $r['has_p'] ? ' + parent' : '' ) : 'No signature' );
			}
			$sub = $a['class'] ? SDSC_Schedule::class_name( $a['class'], true ) : $range;
			if ( '' !== $a['who'] ) { $sub .= ' · “' . $a['who'] . '”'; }
			return array( 'Registration forms', $sub, array( 'File no.', 'Registered', 'Student', 'Class', 'Date of birth', 'Phone', 'Signature' ), $rows, array( 'Forms' => count( $rows ), 'Signed' => $sig ), false, wp_list_pluck( $res, 'id' ) );
		}
		if ( 'registrations' === $a['r'] ) {
			if ( ! class_exists( 'SDSC_Registration' ) ) { return new WP_Error( 'x', 'Registrations are not available.' ); }
			$res  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, source_entry_id, class_id, first_name, last_name, email, phone, created_at FROM ' . SDSC_Registration::table() . " WHERE created_at BETWEEN %s AND %s AND source <> 'demo'" . self::not_demo( 'email' ) . ' ORDER BY created_at', $from, $to ), ARRAY_A ); // phpcs:ignore
			$rows = array();
			$paid = 0;
			foreach ( $res as $r ) {
				$p      = class_exists( 'SDSC_Pay' ) ? SDSC_Pay::paid_row( (int) $r['id'] ) : null;
				$paid  += $p ? 1 : 0;
				$rows[] = array( SDSC_Registration::file_no( $r ), self::d( $r['created_at'] ), trim( $r['first_name'] . ' ' . $r['last_name'] ), $r['class_id'] ? SDSC_Schedule::class_name( (int) $r['class_id'], true ) : '—', $r['phone'], $r['email'], $p ? 'Paid ' . self::money( $p['amount'] / 100 ) : 'Not paid online' );
			}
			return array( 'Registrations', $range, array( 'File no.', 'Date', 'Student', 'Class', 'Phone', 'E-mail', 'Payment' ), $rows, array( 'Registrations' => count( $rows ), 'Paid online' => $paid ), false );
		}
		if ( 'payments' === $a['r'] ) {
			if ( ! class_exists( 'SDSC_Pay' ) ) { return new WP_Error( 'x', 'Online payments are not available.' ); }
			$mode = class_exists( 'SDSC_Api' ) ? SDSC_Api::mode() : 'live';
			$res  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Pay::table() . " WHERE status = 'paid' AND mode = %s AND paid_at BETWEEN %s AND %s" . self::not_demo( 'email' ) . ' ORDER BY paid_at', $mode, $from, $to ), ARRAY_A ); // phpcs:ignore
			$rows = array();
			$sum  = 0;
			foreach ( $res as $r ) {
				$reg    = $wpdb->get_row( $wpdb->prepare( 'SELECT first_name, last_name FROM ' . SDSC_Registration::table() . ' WHERE id = %d', $r['reg_id'] ), ARRAY_A ); // phpcs:ignore
				$sum   += (int) $r['amount'];
				$rows[] = array( SDSC_Pay::receipt_no( $r ), self::d( $r['paid_at'], 'M j, Y g:i A' ), $reg ? trim( $reg['first_name'] . ' ' . $reg['last_name'] ) : $r['email'], SDSC_Schedule::class_name( (int) $r['class_id'], true ), $r['card'] ? $r['card'] : 'Card', self::money( $r['amount'] / 100 ) );
			}
			return array( 'Online payments' . ( 'test' === $mode ? ' (TEST MODE)' : '' ), $range, array( 'Receipt', 'Paid', 'Student', 'Class', 'Method', 'Amount' ), $rows, array( 'Payments' => count( $rows ), 'Total received' => self::money( $sum / 100 ) ), false );
		}
		if ( 'lessons' === $a['r'] ) {
			if ( ! class_exists( 'SDSC_Lessons' ) ) { return new WP_Error( 'x', 'Lessons are not available.' ); }
			$sql  = 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . ' WHERE starts_at BETWEEN %s AND %s' . self::not_demo( 'email' );
			$args = array( $from, $to );
			if ( '' !== $a['who'] ) { $sql .= ' AND instructor = %s'; $args[] = $a['who']; }
			if ( in_array( $a['status'], array( 'booked', 'done', 'cancelled', 'late_cancel', 'no_show', 'no_permit' ), true ) ) { $sql .= ' AND status = %s'; $args[] = $a['status']; }
			$res  = (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY starts_at', $args ), ARRAY_A ); // phpcs:ignore
			$rows = array();
			$by   = array();
			foreach ( $res as $l ) {
				$st     = ucwords( str_replace( '_', ' ', $l['status'] ) );
				$by[ $st ] = ( $by[ $st ] ?? 0 ) + 1;
				$rows[] = array( wp_date( 'D M j, Y', SDSC_Lessons::ts( $l['starts_at'] ) ), wp_date( 'g:i A', SDSC_Lessons::ts( $l['starts_at'] ) ), trim( $l['first_name'] . ' ' . $l['last_name'] ), $l['instructor'] ? $l['instructor'] : '—', $l['lesson_no'] ? $l['lesson_no'] : '', $l['place'], $st );
			}
			return array( 'Driving lessons' . ( $a['who'] ? ' — ' . $a['who'] : '' ), $range, array( 'Date', 'Time', 'Student', 'Instructor', 'Lesson', 'Place', 'Status' ), $rows, array( 'Lessons' => count( $rows ) ) + $by, false );
		}
		if ( 'refunds' === $a['r'] ) {
			if ( ! class_exists( 'SDSC_Refunds' ) ) { return new WP_Error( 'x', 'Refunds are not available.' ); }
			$res  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Refunds::table() . ' WHERE created_at BETWEEN %s AND %s' . self::not_demo( 'email' ) . ' ORDER BY created_at', $from, $to ), ARRAY_A ); // phpcs:ignore
			$rows = array();
			$sum  = 0;
			foreach ( $res as $r ) {
				$sum   += in_array( $r['status'], array( 'refunded', 'done', 'paid' ), true ) ? (float) $r['amount'] : 0;
				$rows[] = array( self::d( $r['created_at'] ), $r['name'] ? $r['name'] : $r['email'], $r['email'], self::money( $r['paid'] ), self::money( $r['amount'] ), ucfirst( $r['kind'] ), ucfirst( str_replace( '_', ' ', $r['status'] ) ), $r['note'] );
			}
			return array( 'Refunds', $range, array( 'Date', 'Student', 'E-mail', 'Paid', 'Refund', 'Kind', 'Status', 'Note' ), $rows, array( 'Refunds' => count( $rows ), 'Refunded' => self::money( $sum ) ), true );
		}
		if ( 'documents' === $a['r'] ) {
			if ( ! class_exists( 'SDSC_Docs' ) ) { return new WP_Error( 'x', 'Student documents are not available.' ); }
			$types = SDSC_Docs::types();
			$res   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Docs::t_docs() . " WHERE created BETWEEN %s AND %s AND status <> 'deleted'" . self::not_demo( 'email' ) . ' ORDER BY name, created', $from, $to ), ARRAY_A ); // phpcs:ignore
			$rows  = array();
			$by    = array();
			foreach ( $res as $r ) {
				$st        = ucfirst( $r['status'] );
				$by[ $st ] = ( $by[ $st ] ?? 0 ) + 1;
				$rows[]    = array( $r['name'] ? $r['name'] : $r['email'], $r['email'], $types[ $r['type'] ] ?? ucfirst( $r['type'] ), $r['label'], self::d( $r['created'] ), 'student' === $r['source'] ? 'Student' : 'Office', $st );
			}
			return array( 'Student documents', $range, array( 'Student', 'E-mail', 'Document', 'Label', 'Added', 'Added by', 'Status' ), $rows, array( 'Documents' => count( $rows ) ) + $by, true );
		}
		if ( 'offers' === $a['r'] ) {
			if ( ! class_exists( 'SDSC_DFOffer' ) ) { return new WP_Error( 'x', 'Lesson offers are not available.' ); }
			$res  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_DFOffer::t() . ' WHERE created_at BETWEEN %s AND %s ORDER BY created_at', $from, $to ), ARRAY_A ); // phpcs:ignore
			$rows = array();
			$by   = array();
			foreach ( $res as $r ) {
				$st        = json_decode( (string) $r['student'], true );
				$sl        = json_decode( (string) $r['slots'], true );
				$bk        = json_decode( (string) $r['booked'], true );
				$stt       = 'open' === $r['status'] && $r['expires_at'] && $r['expires_at'] < current_time( 'mysql' ) ? 'expired' : $r['status'];
				$by[ ucfirst( $stt ) ] = ( $by[ ucfirst( $stt ) ] ?? 0 ) + 1;
				$u         = $r['created_by'] ? get_userdata( (int) $r['created_by'] ) : null;
				$rows[]    = array( self::d( $r['created_at'], 'M j, Y g:i A' ), trim( ( $st['first'] ?? '' ) . ' ' . ( $st['last'] ?? '' ) ), is_array( $sl ) ? count( $sl ) : 0, is_array( $bk ) ? count( $bk ) : 0, ucfirst( $stt ), $r['expires_at'] ? self::d( $r['expires_at'], 'M j, g:i A' ) : '', $u ? $u->display_name : '' );
			}
			return array( 'Lesson offers', $range, array( 'Sent', 'Student', 'Times offered', 'Booked', 'Status', 'Link works until', 'Sent by' ), $rows, array( 'Offers' => count( $rows ) ) + $by, false );
		}
		if ( 'fees' === $a['r'] ) {
			if ( ! class_exists( 'SDSC_Lessons' ) ) { return new WP_Error( 'x', 'Fees are not available.' ); }
			$sql  = 'SELECT f.*, l.first_name, l.last_name, l.starts_at FROM ' . SDSC_Lessons::t_fees() . ' f LEFT JOIN ' . SDSC_Lessons::t_lessons() . ' l ON l.id = f.lesson_id WHERE f.created_at BETWEEN %s AND %s' . self::not_demo( 'f.email' );
			$args = array( $from, $to );
			if ( in_array( $a['status'], array( 'review', 'due', 'paid', 'waived' ), true ) ) { $sql .= ' AND f.status = %s'; $args[] = $a['status']; }
			$res  = (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY f.created_at', $args ), ARRAY_A ); // phpcs:ignore
			$rows = array();
			$tot  = array( 'due' => 0, 'paid' => 0, 'waived' => 0 );
			foreach ( $res as $f ) {
				$tot[ $f['status'] ] = ( $tot[ $f['status'] ] ?? 0 ) + (float) $f['amount'];
				$rows[] = array( self::d( $f['created_at'] ), trim( $f['first_name'] . ' ' . $f['last_name'] ) ?: $f['email'], $f['starts_at'] ? wp_date( 'M j, g:i A', SDSC_Lessons::ts( $f['starts_at'] ) ) : '', ucwords( str_replace( '_', ' ', $f['reason'] ) ), self::money( $f['amount'] ), ucfirst( $f['status'] ) );
			}
			return array( 'Lesson fees', $range, array( 'Date', 'Student', 'Lesson', 'Reason', 'Amount', 'Status' ), $rows, array( 'Fees' => count( $rows ), 'Due' => self::money( $tot['due'] ), 'Paid' => self::money( $tot['paid'] ), 'Waived' => self::money( $tot['waived'] ) ), false );
		}
		return new WP_Error( 'x', 'Unknown report.' );
	}

	/** Registration ids for the "Registration forms" report: one class (any date), else a date range; optional name, e-mail or phone. */
	protected static function form_ids( $a ) {
		global $wpdb;
		$t    = SDSC_Registration::table();
		$sql  = "SELECT id FROM $t WHERE source <> 'demo'" . self::not_demo( 'email' );
		$args = array();
		if ( ! empty( $a['one'] ) ) {
			$sql   .= ' AND id = %d';
			$args[] = (int) $a['one'];
		} elseif ( $a['class'] ) {
			if ( ! self::class_ok( $a['class'] ) ) { return array(); }
			$sql   .= ' AND class_id = %d';
			$args[] = $a['class'];
		} else {
			$sql   .= ' AND created_at BETWEEN %s AND %s';
			$args[] = $a['from'] . ' 00:00:00';
			$args[] = $a['to'] . ' 23:59:59';
		}
		if ( '' !== $a['who'] && empty( $a['one'] ) ) {
			$like   = '%' . $wpdb->esc_like( $a['who'] ) . '%';
			$sql   .= " AND (CONCAT(first_name, ' ', last_name) LIKE %s OR email LIKE %s OR phone LIKE %s)";
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql . ' ORDER BY created_at, id LIMIT 400', $args ) ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------ dashboard screen */

	protected static function table( $cols, $rows, $limit = 0 ) {
		$h = '<table class="srp-table"><thead><tr>';
		foreach ( $cols as $c ) { $h .= '<th>' . nl2br( esc_html( $c ) ) . '</th>'; }
		$h .= '</tr></thead><tbody>';
		foreach ( $limit ? array_slice( $rows, 0, $limit ) : $rows as $r ) {
			$h .= '<tr>';
			foreach ( $r as $v ) { $h .= '<td>' . esc_html( (string) $v ) . '</td>'; }
			$h .= '</tr>';
		}
		if ( ! $rows ) { $h .= '<tr><td colspan="' . count( $cols ) . '" class="srp-none">Nothing to show for these filters.</td></tr>'; }
		return $h . '</tbody></table>';
	}

	public static function render() {
		if ( ! is_user_logged_in() ) { return ''; }
		wp_enqueue_style( 'sdsc-reports' );
		$mine = self::mine();
		$h    = '<div class="srp"><h3>Reports</h3>';
		if ( isset( $_GET['srp_msg'] ) ) { $h .= '<p class="srp-ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['srp_msg'] ) ) ) . '</p>'; } // phpcs:ignore
		if ( ! $mine ) { return $h . '<p class="srp-m">No reports have been shared with you yet. The school office can give you access.</p></div>'; }
		$a = self::args();
		if ( ! in_array( $a['r'], $mine, true ) ) { $a['r'] = ''; }

		$h .= '<div class="srp-cards">';
		foreach ( self::reports() as $k => $d ) {
			if ( ! in_array( $k, $mine, true ) ) { continue; }
			$h .= '<a class="srp-card' . ( $k === $a['r'] ? ' is-on' : '' ) . '" href="' . esc_url( self::url( array( 'r' => $k ) ) ) . '"><span class="srp-ic" aria-hidden="true">' . $d[4] . '</span><b>' . esc_html( $d[0] ) . '</b><small>' . esc_html( $d[1] ) . '</small></a>';
		}
		$h .= '</div>';

		if ( $a['r'] ) {
			$def = self::reports()[ $a['r'] ];
			$h  .= '<form method="get" class="srp-filters" action="' . esc_url( self::url() ) . '"><input type="hidden" name="r" value="' . esc_attr( $a['r'] ) . '">';
			if ( 'people' === $def[2] ) {
				$courses = class_exists( 'SDSC_Student_File' ) ? SDSC_Student_File::courses() : array();
				$h .= '<label>Class <select name="class"><option value="">Any class (use the dates)</option>';
				foreach ( self::classes() as $c ) { $h .= '<option value="' . (int) $c->ID . '"' . selected( $a['class'], $c->ID, false ) . '>' . esc_html( SDSC_Schedule::class_name( $c->ID, true ) . ( substr( (string) get_post_meta( $c->ID, '_sdsc_start', true ), 0, 10 ) ? ' · ' . substr( (string) get_post_meta( $c->ID, '_sdsc_start', true ), 0, 10 ) : '' ) ) . '</option>'; }
				$h .= '</select></label>';
				if ( count( $courses ) > 1 ) {
					$h .= '<label>Online course <select name="course">';
					foreach ( $courses as $cid => $ct ) { $h .= '<option value="' . (int) $cid . '"' . selected( $a['course'], $cid, false ) . '>' . esc_html( $ct ) . '</option>'; }
					$h .= '</select></label>';
				}
				$h .= '<label>From <input type="date" name="from" value="' . esc_attr( $a['from'] ) . '"></label><label>To <input type="date" name="to" value="' . esc_attr( $a['to'] ) . '"></label>'
					. '<label>Student <input type="search" name="who" value="' . esc_attr( $a['who'] ) . '" placeholder="Name, e-mail or phone"></label>';
				if ( 'file' === $a['r'] ) {
					$desc = array(
						'form'       => array( '🖊️', 'Personal details, the full Responsibility Agreement and refund policies, signatures.' ),
						'sheet'      => array( '📝', 'Unit quizzes 1–9 and the final exam: every answer, the correct one and the score.' ),
						'attendance' => array( '🗓️', 'Every Zoom session: present, late, left early or absent.' ),
						'idcopy'     => array( '🪪', 'The ID, learner’s permit or license scans from Student documents.' ),
						'de003'      => array( '🏛️', 'The official MVA form, filled in: classroom sessions and scores, behind-the-wheel lessons.' ),
					);
					$h .= '<fieldset class="srp-parts"><legend>Forms to print <button type="button" class="srp-link-btn" data-all="1">All</button> <button type="button" class="srp-link-btn" data-all="0">None</button></legend>';
					foreach ( SDSC_Student_File::parts() as $k => $l ) {
						$h .= '<label class="srp-part"><input type="checkbox" name="parts[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $a['parts'], true ), true, false ) . '><span class="srp-part-ic" aria-hidden="true">' . $desc[ $k ][0] . '</span><span><b>' . esc_html( $l ) . '</b><small>' . esc_html( $desc[ $k ][1] ) . '</small></span></label>';
					}
					$h .= '</fieldset><script>document.querySelectorAll(".srp-parts [data-all]").forEach(function(b){b.addEventListener("click",function(){var on=b.getAttribute("data-all")==="1";b.closest("fieldset").querySelectorAll("input[type=checkbox]").forEach(function(c){c.checked=on;});});});</script>';
				}
			} elseif ( 'forms' === $def[2] ) {
				$h .= '<label>Class <select name="class"><option value="">Any class (use the dates)</option>';
				foreach ( self::classes() as $c ) { $h .= '<option value="' . (int) $c->ID . '"' . selected( $a['class'], $c->ID, false ) . '>' . esc_html( SDSC_Schedule::class_name( $c->ID, true ) . ( substr( (string) get_post_meta( $c->ID, '_sdsc_start', true ), 0, 10 ) ? ' · ' . substr( (string) get_post_meta( $c->ID, '_sdsc_start', true ), 0, 10 ) : '' ) ) . '</option>'; }
				$h .= '</select></label><label>From <input type="date" name="from" value="' . esc_attr( $a['from'] ) . '"></label><label>To <input type="date" name="to" value="' . esc_attr( $a['to'] ) . '"></label>'
					. '<label>Student <input type="search" name="who" value="' . esc_attr( $a['who'] ) . '" placeholder="Name, e-mail or phone"></label>';
			} elseif ( 'class' === $def[2] ) {
				$h .= '<label>Class <select name="class" required><option value="">Choose a class…</option>';
				foreach ( self::classes() as $c ) { $h .= '<option value="' . (int) $c->ID . '"' . selected( $a['class'], $c->ID, false ) . '>' . esc_html( SDSC_Schedule::class_name( $c->ID, true ) . ( substr( (string) get_post_meta( $c->ID, '_sdsc_start', true ), 0, 10 ) ? ' · ' . substr( (string) get_post_meta( $c->ID, '_sdsc_start', true ), 0, 10 ) : '' ) ) . '</option>'; }
				$h .= '</select></label>';
			} else {
				$h .= '<label>From <input type="date" name="from" value="' . esc_attr( $a['from'] ) . '"></label><label>To <input type="date" name="to" value="' . esc_attr( $a['to'] ) . '"></label>';
				if ( 'lessons' === $a['r'] && 'instructor' !== self::kind() ) {
					global $wpdb;
					$names = class_exists( 'SDSC_Lessons' ) ? (array) $wpdb->get_col( 'SELECT DISTINCT instructor FROM ' . SDSC_Lessons::t_lessons() . " WHERE instructor <> ''" . self::not_demo( 'email' ) . ' ORDER BY instructor' ) : array(); // phpcs:ignore
					$h    .= '<label>Instructor <select name="who"><option value="">All instructors</option>';
					foreach ( $names as $n ) { $h .= '<option' . selected( $a['who'], $n, false ) . '>' . esc_html( $n ) . '</option>'; }
					$h .= '</select></label>';
				}
				if ( in_array( $a['r'], array( 'lessons', 'fees' ), true ) ) {
					$opts = 'fees' === $a['r'] ? array( 'review' => 'To decide', 'due' => 'Due', 'paid' => 'Paid', 'waived' => 'Waived' ) : array( 'booked' => 'Booked', 'done' => 'Done', 'cancelled' => 'Cancelled', 'late_cancel' => 'Late cancel', 'no_show' => 'No-show' );
					$h   .= '<label>Status <select name="status"><option value="">All</option>';
					foreach ( $opts as $k => $l ) { $h .= '<option value="' . esc_attr( $k ) . '"' . selected( $a['status'], $k, false ) . '>' . esc_html( $l ) . '</option>'; }
					$h .= '</select></label>';
				}
			}
			$h .= '<button class="srp-btn">Show</button></form>';

			$ready = 'class' !== $def[2] || $a['class'];
			if ( $ready ) {
				$rep = self::build( $a );
				if ( is_wp_error( $rep ) ) {
					$h .= '<p class="srp-warn">' . esc_html( $rep->get_error_message() ) . '</p>';
				} else {
					$q   = array_filter( array( 'sdsc_report' => $a['r'], 'class' => $a['class'] ?: null, 'from' => 'class' === $def[2] ? null : $a['from'], 'to' => 'class' === $def[2] ? null : $a['to'], 'who' => $a['who'] ?: null, 'status' => $a['status'] ?: null, 'course' => $a['course'] ?: null ) );
					if ( 'file' === $a['r'] ) { $q['parts'] = $a['parts']; }
					$q['_wpnonce'] = wp_create_nonce( 'sdsc_report' );
					$h  .= '<div class="srp-head"><div><b>' . esc_html( $rep[0] ) . '</b><small>' . esc_html( $rep[1] ) . '</small></div><div class="srp-actions">'
						. '<a class="srp-btn" target="_blank" rel="noopener" href="' . esc_url( add_query_arg( $q + array( 'out' => 'print' ), home_url( '/' ) ) ) . '">🖨 ' . ( 'forms' === $a['r'] ? 'Print all forms' : ( 'file' === $a['r'] ? 'Print the chosen forms' : 'Print / Save PDF' ) ) . '</a>'
						. '<a class="srp-btn srp-btn--ghost" href="' . esc_url( add_query_arg( $q + array( 'out' => 'csv' ), home_url( '/' ) ) ) . '">⬇ Download CSV</a></div></div>';
					if ( $rep[4] ) {
						$h .= '<div class="srp-totals">';
						foreach ( $rep[4] as $k => $v ) { $h .= '<span><b>' . esc_html( (string) $v ) . '</b>' . esc_html( $k ) . '</span>'; }
						$h .= '</div>';
					}
					$h .= '<div class="srp-scroll">' . self::table( $rep[2], $rep[3], 300 ) . '</div>';
					if ( 'file' === $a['r'] && ! empty( $rep[6] ) ) {
						$h .= '<details class="srp-access" open><summary>Print one student’s file</summary><div class="srp-one">';
						foreach ( array_slice( $rep[6], 0, 400 ) as $p ) {
							$h .= '<a class="srp-btn srp-btn--ghost" target="_blank" rel="noopener" href="' . esc_url( add_query_arg( $q + array( 'out' => 'print', 'rid' => $p['reg'] ? (int) $p['reg']['id'] : null, 'uid' => $p['reg'] ? null : (int) $p['user_id'] ), home_url( '/' ) ) ) . '">🖨 ' . esc_html( $p['name'] ) . '</a>';
						}
						$h .= '</div></details>';
						$nof = array_filter( $rep[6], function ( $p ) { return ! $p['reg']; } );
						if ( $nof ) {
							$h .= '<details class="srp-access"><summary>Students without a registration form (' . count( $nof ) . ') — link a form</summary><p class="srp-m">A form is found by the student’s e-mail. When a student registered with another e-mail, or the form was entered by the office, pick the right registration here. Only the link is saved; nothing in the form changes.</p><div class="srp-links">';
							foreach ( array_slice( $nof, 0, 120 ) as $p ) {
								$sug = SDSC_Student_File::suggestions( $p );
								$h  .= '<form class="srp-link" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'sdsc_reg_link', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_reg_link"><input type="hidden" name="email" value="' . esc_attr( $p['email'] ) . '">'
									. '<b>' . esc_html( $p['name'] ) . '</b><small>' . esc_html( $p['email'] ) . '</small><select name="reg"><option value="">Choose a registration…</option>';
								foreach ( $sug as $g ) { $h .= '<option value="' . (int) $g['id'] . '">#' . (int) $g['id'] . ' · ' . esc_html( trim( $g['first_name'] . ' ' . $g['last_name'] ) . ' · ' . $g['email'] . ' · ' . self::d( $g['created_at'], 'm/d/Y' ) ) . '</option>'; }
								$h .= '</select><input type="number" name="reg2" min="1" placeholder="or Registration #" aria-label="Registration number"><button class="srp-btn">Link</button></form>';
							}
							$h .= '</div></details>';
						}
					}
					if ( 'forms' === $a['r'] && ! empty( $rep[6] ) ) {
						$h .= '<details class="srp-access" open><summary>Print one student’s form</summary><div class="srp-one">';
						foreach ( array_slice( $rep[6], 0, 300 ) as $i => $rid ) {
							$h .= '<a class="srp-btn srp-btn--ghost" target="_blank" rel="noopener" href="' . esc_url( add_query_arg( array( 'sdsc_report' => 'forms', 'one' => (int) $rid, 'out' => 'print', '_wpnonce' => wp_create_nonce( 'sdsc_report' ) ), home_url( '/' ) ) ) . '">🖨 ' . esc_html( $rep[3][ $i ][0] . ' · ' . $rep[3][ $i ][2] ) . '</a>';
						}
						$h .= '</div></details>';
					}
					if ( count( $rep[3] ) > 300 ) { $h .= '<p class="srp-m">Showing the first 300 rows. Print or download for all ' . count( $rep[3] ) . '.</p>'; }
				}
			}
		}

		if ( self::is_boss() ) { $h .= ( class_exists( 'SDSC_DE003' ) ? SDSC_DE003::settings_form() : '' ) . self::access_form(); }
		return $h . '</div>';
	}

	protected static function access_form() {
		$acc = self::access();
		$h   = '<details class="srp-access"><summary>Who can open reports</summary><p class="srp-m">The administrator and the school manager can open every report. Choose what the front desk and the instructors may open. Instructors only ever see their own classes and their own lessons, without dates of birth, parents or payments.</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'sdsc_report_access', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_report_access">'
			. '<table class="srp-table srp-matrix"><thead><tr><th>Report</th><th>Front desk</th><th>Instructors</th></tr></thead><tbody>';
		foreach ( self::reports() as $k => $d ) {
			$h .= '<tr><td>' . esc_html( $d[0] ) . '</td>';
			foreach ( array( 'frontdesk', 'instructor' ) as $role ) {
				$off = 'instructor' === $role && in_array( $k, array( 'registrations', 'forms', 'exams', 'file', 'payments', 'fees', 'refunds', 'documents', 'offers' ), true );
				$h  .= '<td>' . ( $off ? '<span class="srp-m" title="Contains other students’ data">—</span>' : '<input type="checkbox" name="acc[' . $role . '][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, (array) $acc[ $role ], true ), true, false ) . ' aria-label="' . esc_attr( $d[0] . ' for ' . $role ) . '">' ) . '</td>';
			}
			$h .= '</tr>';
		}
		return $h . '</tbody></table><p><button class="srp-btn">Save access</button></p></form></details>';
	}

	public static function save_access() {
		if ( ! self::is_boss() ) { wp_die( 'Not allowed.', 403 ); }
		check_admin_referer( 'sdsc_report_access' );
		$in  = (array) wp_unslash( $_POST['acc'] ?? array() ); // phpcs:ignore -- whitelisted
		$all = array_keys( self::reports() );
		$out = array();
		foreach ( array( 'frontdesk', 'instructor' ) as $role ) {
			$keys = array_values( array_intersect( $all, array_map( 'sanitize_key', (array) ( $in[ $role ] ?? array() ) ) ) );
			if ( 'instructor' === $role ) { $keys = array_values( array_diff( $keys, array( 'registrations', 'forms', 'exams', 'file', 'payments', 'fees', 'refunds', 'documents', 'offers' ) ) ); }
			$out[ $role ] = $keys;
		}
		update_option( self::ACCESS, $out, false );
		wp_safe_redirect( self::url( array( 'srp_msg' => rawurlencode( 'Report access saved.' ) ) ) );
		exit;
	}

	/* ------------------------------------------------------------ print and CSV */

	public static function output() {
		if ( empty( $_GET['sdsc_report'] ) ) { return; } // phpcs:ignore
		if ( ! is_user_logged_in() ) { auth_redirect(); }
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'sdsc_report' ) ) { wp_die( 'This link has expired. Open the report again from the dashboard.', 'Report', array( 'response' => 403 ) ); } // phpcs:ignore
		$a      = self::args();
		$a['r'] = sanitize_key( wp_unslash( $_GET['sdsc_report'] ) ); // phpcs:ignore
		$rep    = self::build( $a );
		if ( is_wp_error( $rep ) ) { wp_die( esc_html( $rep->get_error_message() ), 'Report', array( 'response' => 403 ) ); }
		nocache_headers();
		if ( 'csv' !== ( $_GET['out'] ?? '' ) && class_exists( 'SDSC_Student_File' ) ) { // phpcs:ignore
			if ( 'file' === $a['r'] ) {
				$people = (array) ( $rep[6] ?? array() );
				if ( $a['rid'] || $a['uid'] ) { $people = array_values( array_filter( $people, function ( $p ) use ( $a ) { return ( $a['rid'] && $p['reg'] && (int) $p['reg']['id'] === $a['rid'] ) || ( $a['uid'] && ! $p['reg'] && $p['user_id'] === $a['uid'] ); } ) ); }
				SDSC_Student_File::print_page( $people, $a['parts'], $a['course'] ? $a['course'] : SDSC_Student_File::default_course(), 1 === count( $people ) ? 'Student file — ' . $people[0]['name'] : 'Student files' );
			}
			if ( 'forms' === $a['r'] ) {
				SDSC_Student_File::print_page( SDSC_Student_File::from_regs( (array) ( $rep[6] ?? array() ) ), array( 'form' ), 0, 'Registration forms' );
			}
		}
		$u = wp_get_current_user();
		if ( 'csv' === ( $_GET['out'] ?? '' ) ) { // phpcs:ignore
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $rep[0] ) ) . '-' . wp_date( 'Y-m-d' ) ) . '.csv"' );
			$fh = fopen( 'php://output', 'w' ); // phpcs:ignore
			fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore -- Excel UTF-8
			$safe = function ( $v ) { $v = str_replace( "\n", ' ', (string) $v ); return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; }; // no spreadsheet formulas
			fputcsv( $fh, array_map( $safe, $rep[2] ) );
			foreach ( $rep[3] as $r ) { fputcsv( $fh, array_map( $safe, $r ) ); }
			fclose( $fh ); // phpcs:ignore
			exit;
		}
		$title = $rep[0];
		$land  = $rep[5];
		$foot  = '';
		foreach ( $rep[4] as $k => $v ) { $foot .= '<span><b>' . esc_html( (string) $v ) . '</b> ' . esc_html( $k ) . '</span>'; }
		$logo = class_exists( 'SDSC_Brand' ) ? SDSC_Brand::logo( 'wide', 'rp-logo', 40 ) : '<b>Sam’s Driving School</b>';
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title . ' — Sam’s Driving School' ); ?></title>
<style>
@page { size: letter <?php echo $land ? 'landscape' : 'portrait'; ?>; margin: 0.8in 0.5in 0.7in;
	@top-left { content: "Sam’s Driving School LLC · <?php echo esc_html( str_replace( '"', '', $title ) ); ?>"; font: 700 8.5pt Arial, sans-serif; color: #00703c; vertical-align: bottom; padding-bottom: 6pt; }
	@top-right { content: "Printed <?php echo esc_html( wp_date( 'm/d/Y g:i A' ) ); ?>"; font: 8.5pt Arial, sans-serif; color: #5b646d; vertical-align: bottom; padding-bottom: 6pt; }
	@bottom-left { content: "751 Rockville Pike, Unit 9B, Rockville, MD 20852 · (301) 726-3030 · Confidential — school use only"; font: 8pt Arial, sans-serif; color: #5b646d; }
	@bottom-right { content: "Page " counter(page) " of " counter(pages); font: 700 8.5pt Arial, sans-serif; color: #15191d; } }
* { box-sizing: border-box; }
body { margin: 0; font: 11pt/1.4 Arial, Helvetica, sans-serif; color: #15191d; background: #eef1f3; }
.sheet { max-width: <?php echo $land ? '11in' : '8.5in'; ?>; margin: 18px auto; padding: 22px 26px; background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.08); }
.bar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #15191d; }
.bar button, .bar a { padding: 9px 16px; border: 0; border-radius: 999px; background: #00703c; color: #fff; font: 700 13px Arial, sans-serif; text-decoration: none; cursor: pointer; }
.bar a { background: #3c434a; }
header.rh { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; padding-bottom: 10px; border-bottom: 3px solid #00703c; margin-bottom: 4px; }
.rh .rp-logo { height: 0.5in; width: auto; }
.rh .org { text-align: right; font-size: 9pt; color: #5b646d; line-height: 1.4; }
.road { height: 4px; margin: 0 0 12px; background: repeating-linear-gradient(90deg, #ffc20e 0 22px, transparent 22px 38px); }
h1 { margin: 0 0 2pt; font-size: 16pt; }
.sub { margin: 0 0 3pt; font-size: 11pt; color: #5b646d; }
.meta { margin: 0 0 10pt; color: #6b737b; font-size: 9pt; }
table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
tr { break-inside: avoid; page-break-inside: avoid; }
th { padding: 5pt 5pt; background: #e7f5ec; color: #0b3d24; text-align: left; font-size: 9pt; letter-spacing: .4px; text-transform: uppercase; border-bottom: 1px solid #b8dcc6; white-space: pre-line; vertical-align: bottom; }
td { padding: 4pt 5pt; border-bottom: 0.5pt solid #d5dbe0; vertical-align: top; font-size: 10pt; }
tbody tr:nth-child(even) td { background: #fafbfb; }
<?php if ( $land ) : ?>td:not(:first-child), th:not(:first-child) { text-align: center; } td { font-size: 9.5pt; }<?php endif; ?>
.totals { display: flex; flex-wrap: wrap; gap: 8px 22px; margin: 12px 0 0; padding: 10px 12px; border-radius: 6px; background: #f6f7f7; break-inside: avoid; }
.totals { font-size: 10pt; } .totals b { font-size: 12pt; }
.sign { display: flex; gap: 40px; margin-top: 34px; break-inside: avoid; }
.sign div { flex: 1; border-top: 0.75pt solid #15191d; padding-top: 3pt; font-size: 9pt; color: #5b646d; }
.none { padding: 14px; text-align: center; color: #5b646d; }
@media print { body { background: #fff; } .bar { display: none; } .sheet { max-width: none; margin: 0; padding: 0; box-shadow: none; } }
</style></head><body>
<div class="bar"><button onclick="window.print()">🖨 Print / Save as PDF</button><a href="javascript:window.close()">Close</a></div>
<div class="sheet">
<header class="rh"><div><?php echo $logo; // phpcs:ignore ?></div><div class="org">Sam’s Driving School LLC<br>751 Rockville Pike, Unit 9B, Rockville, MD 20852<br>(301) 726-3030 · info@samsdrivingschool.org</div></header>
<div class="road"></div>
<h1><?php echo esc_html( $title ); ?></h1>
<p class="sub"><?php echo esc_html( $rep[1] ); ?></p>
<p class="meta">Printed <?php echo esc_html( wp_date( 'F j, Y \a\t g:i A' ) ); ?> by <?php echo esc_html( $u->display_name ); ?> · <?php echo (int) count( $rep[3] ); ?> rows · Confidential — school use only</p>
<table><thead><tr><?php foreach ( $rep[2] as $c ) { echo '<th>' . esc_html( $c ) . '</th>'; } ?></tr></thead><tbody>
<?php
if ( ! $rep[3] ) { echo '<tr><td colspan="' . count( $rep[2] ) . '" class="none">Nothing to show for these filters.</td></tr>'; }
foreach ( $rep[3] as $r ) { echo '<tr>'; foreach ( $r as $v ) { echo '<td>' . esc_html( (string) $v ) . '</td>'; } echo '</tr>'; }
?>
</tbody></table>
<?php if ( $foot ) : ?><div class="totals"><?php echo $foot; // phpcs:ignore ?></div><?php endif; ?>
<?php if ( 'attendance' === $a['r'] ) : ?><div class="sign"><div>Instructor signature</div><div>Date</div><div>School manager</div></div><?php endif; ?>
</div>
<script>if (location.hash === '#print') { window.addEventListener('load', function(){ window.print(); }); }</script>
</body></html>
		<?php
		exit;
	}
}
