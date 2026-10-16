<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * "Schedule a lesson" for the DriveFlow calendar and lesson records, done properly.
 *
 * DriveFlow's own form took a typed name, two free date boxes and an optional car, and its clash warning
 * never showed. This replaces it (same button, same "click a free slot" pre-fill) with one that:
 *  - finds the student among registrations and DriveFlow students (or adds a new one with e-mail/mobile);
 *  - asks for the kind of lesson, the date, a start time on the half hour and the length (end is computed);
 *  - lists every instructor — also those still finishing onboarding — and every car, with their status;
 *  - checks, before saving and again on the server: time in the past or outside 7 AM–9 PM, instructor or
 *    car already busy or blocked, the student already in a lesson, and the MVA limit of 2 hours of
 *    behind-the-wheel per day for Driver's Ed students (COMAR 11.23.02.34J);
 *  - keeps the meeting place, permit, language and a note for the team, and suggests the lesson number;
 *  - shows the free-cancellation deadline the student will be told, then books the session in DriveFlow,
 *    copies it to the BTW lesson list at once and sends the confirmation (and tells the instructor).
 */
class SDSC_DFBook {

	const OPENS  = 7;   // earliest start hour
	const CLOSES = 21;  // a lesson must end by this hour
	const DAY    = 9;   // instructors start at 9 AM; earlier (from 7) only when the school manager books it
	/** The school's time sheet: 2-hour lesson blocks (start hours). 7–9 is the early block (manager only). */
	const SLOTS  = array( 7, 9, 11, 13, 15, 17 );

	/** Slot starts in minutes after midnight. */
	public static function slots() { return array_map( function ( $h ) { return $h * 60; }, self::SLOTS ); }

	/** "9–11" style label of the block that starts at $min. */
	public static function slot_label( $min ) {
		$f = function ( $m ) { $h = intdiv( $m, 60 ); return ( ( $h + 11 ) % 12 + 1 ) . ( $m % 60 ? ':' . sprintf( '%02d', $m % 60 ) : '' ); };
		return $f( $min ) . '–' . $f( $min + 120 );
	}

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'modal' ) );
		add_action( 'wp_ajax_sdsc_dfb_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_sdsc_dfb_grid', array( __CLASS__, 'ajax_grid' ) );
		add_action( 'admin_post_sdsc_dfb_book', array( __CLASS__, 'h_book' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** Early lessons (before 9 AM) are booked by the administrator or the school manager only. */
	public static function early_ok() {
		return current_user_can( 'manage_options' ) || ( class_exists( 'SDSC_Board' ) && SDSC_Board::can() );
	}

	public static function can() {
		return current_user_can( 'manage_options' ) || current_user_can( 'driveflow_manage' ) || ( class_exists( 'SDSC_Lessons' ) && SDSC_Lessons::can() );
	}

	protected static function on_page() {
		$p = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		return in_array( $p, array( 'driveflow-pro-calendar', 'driveflow-pro-records' ), true ) && class_exists( 'SDSC_DriveFlow' ) && SDSC_DriveFlow::on() && self::can();
	}

	public static function types() {
		return array(
			'de'      => array( "Driver's Ed — behind the wheel (36-hour course)", 120, array( 60, 120 ) ),
			'extra'   => array( 'Extra practice lesson', 120, array( 60, 90, 120, 180 ) ),
			'test'    => array( 'Road test — car and warm-up', 90, array( 60, 90, 120 ) ),
			'nervous' => array( 'Nervous-driver lesson', 120, array( 60, 90, 120 ) ),
			'eval'    => array( 'First lesson / driving assessment', 60, array( 60, 90, 120 ) ),
		);
	}

	protected static function places() {
		$base = class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::settings()['place'] : '';
		return array_values( array_filter( array( $base, 'Rockville Metro Station (free pickup)', 'MVA Gaithersburg — road test', 'MVA Beltsville — road test', 'MVA Glen Burnie — road test' ) ) );
	}

	/** Everyone who can be booked: registrations first, then DriveFlow students not already in the list. */
	protected static function people() {
		global $wpdb;
		$out  = array();
		$seen = array();
		$df   = (array) $wpdb->get_results( "SELECT id, name, email, phone, license_number FROM {$wpdb->prefix}driveflow_students WHERE status = 'active' ORDER BY name", ARRAY_A ); // phpcs:ignore
		$dfby = array();
		foreach ( $df as $r ) {
			$e = strtolower( trim( (string) $r['email'] ) );
			if ( $e && ! preg_match( '/@example\.(com|org|net)$/', $e ) ) { $dfby[ $e ] = $r; }
		}
		$counts = array();
		if ( class_exists( 'SDSC_Lessons' ) ) {
			foreach ( (array) $wpdb->get_results( 'SELECT email, SUM(status = \'done\') d, SUM(status = \'booked\') b, MAX(lesson_no) n FROM ' . SDSC_Lessons::t_lessons() . " WHERE email <> '' GROUP BY email", ARRAY_A ) as $c ) { // phpcs:ignore
				$counts[ strtolower( $c['email'] ) ] = $c;
			}
		}
		$code = function ( $cid ) {
			if ( ! $cid ) { return ''; }
			$c = (string) get_post_meta( $cid, '_sdsc_course_code', true );
			if ( $c ) { return $c; }
			$st = (string) get_post_meta( $cid, '_sdsc_start', true );
			return $st ? 'class of ' . wp_date( 'M j, Y', strtotime( $st . ' 12:00:00' ) ) : '';
		};
		if ( class_exists( 'SDSC_Outreach' ) ) {
			foreach ( SDSC_Outreach::contacts() as $c ) {
				$e = $c['email'];
				if ( isset( $seen[ $e ] ) ) { continue; }
				$seen[ $e ] = 1;
				$x     = $counts[ $e ] ?? array();
				$out[] = array( 'f' => $c['first'], 'l' => $c['last'], 'e' => $e, 'p' => $c['phone'], 'g' => $c['lang'], 'c' => $code( $c['class'] ), 'd' => (int) ( $x['d'] ?? 0 ), 'b' => (int) ( $x['b'] ?? 0 ), 'n' => (int) ( $x['n'] ?? 0 ), 'df' => (int) ( $dfby[ $e ]['id'] ?? 0 ), 'pm' => (string) ( $dfby[ $e ]['license_number'] ?? '' ) );
			}
		}
		foreach ( $df as $r ) {
			$e = strtolower( trim( (string) $r['email'] ) );
			if ( $e && isset( $seen[ $e ] ) ) { continue; }
			if ( preg_match( '/@example\.(com|org|net)$/', $e ) ) { continue; }
			$parts = preg_split( '/\s+/', trim( (string) $r['name'] ), 2 );
			$x     = $counts[ $e ] ?? array();
			$out[] = array( 'f' => $parts[0] ?? '', 'l' => $parts[1] ?? '', 'e' => $e, 'p' => (string) $r['phone'], 'g' => 'en', 'c' => '', 'd' => (int) ( $x['d'] ?? 0 ), 'b' => (int) ( $x['b'] ?? 0 ), 'n' => (int) ( $x['n'] ?? 0 ), 'df' => (int) $r['id'], 'pm' => (string) $r['license_number'] );
		}
		return $out;
	}

	public static function instructors() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT id, user_id, name, phone, email, license_number, payment_details, agreement_signed, onboarding_completed, default_vehicle_id, status FROM {$wpdb->prefix}driveflow_instructors WHERE status = 'active' AND email NOT LIKE '%@preview.samsdrivingschool.invalid' AND email NOT LIKE '%@example.com' AND email NOT LIKE '%@example.org' AND name NOT LIKE 'Demo %' AND name NOT LIKE 'Preview %' ORDER BY name", ARRAY_A ); // phpcs:ignore
		$meta = class_exists( 'SDSC_Instructors' ) ? SDSC_Instructors::meta_all() : array();
		$out  = array();
		foreach ( $rows as $r ) {
			$miss  = class_exists( 'SDSC_Onboard' ) ? SDSC_Onboard::missing( $r ) : array();
			$out[] = array( 'id' => (int) $r['id'], 'name' => $r['name'], 'car' => (int) $r['default_vehicle_id'], 'pending' => $miss ? implode( ', ', $miss ) : '', 'langs' => implode( '/', (array) ( $meta[ $r['id'] ]['langs'] ?? array() ) ) );
		}
		return $out;
	}

	protected static function cars() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT id, plate_number, model, transmission, maintenance_status FROM {$wpdb->prefix}driveflow_vehicles WHERE status = 'active' AND plate_number NOT LIKE 'DEMO%' ORDER BY plate_number", ARRAY_A ); // phpcs:ignore -- the sample car is left out
		return array_map( function ( $r ) { return array( 'id' => (int) $r['id'], 'plate' => $r['plate_number'], 'model' => trim( $r['model'] . ( $r['transmission'] && 'Automatic' !== $r['transmission'] ? ' · ' . $r['transmission'] : '' ) ), 'down' => 'operational' !== $r['maintenance_status'] ? $r['maintenance_status'] : '' ); }, $rows );
	}

	public static function assets() {
		if ( ! self::on_page() ) { return; }
		wp_enqueue_style( 'sdsc-dfbook', SDSC_URL . 'assets/dfbook.css', array(), SDSC_VERSION . '.' . filemtime( SDSC_DIR . 'assets/dfbook.css' ) );
		wp_enqueue_script( 'sdsc-dfbook', SDSC_URL . 'assets/dfbook.js', array(), SDSC_VERSION . '.' . filemtime( SDSC_DIR . 'assets/dfbook.js' ), true );
		$s = class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::settings() : array( 'hours' => 48, 'fee' => 60 );
		wp_localize_script( 'sdsc-dfbook', 'SDSC_DFB', array(
			'people' => self::people(),
			'ins'    => self::instructors(),
			'cars'   => self::cars(),
			'types'  => self::types(),
			'places' => self::places(),
			'hours'  => (int) $s['hours'],
			'fee'    => (float) $s['fee'],
			'open'   => self::OPENS,
			'close'  => self::CLOSES,
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'sdsc_dfb' ),
			'addIns' => class_exists( 'SDSC_Instructors' ) ? SDSC_Instructors::url() : '',
		) );
	}

	/* ------------------------------------------------------------------ checks */

	protected static function ts( $local ) { return class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::ts( $local ) : strtotime( $local ); }

	/**
	 * Everything that stops or questions a booking. $d: start (Y-m-d H:i:s), minutes, ins (DriveFlow id), car (id),
	 * email, phone, name, type, permit. Returns [errors[], warnings[], info[]].
	 */
	public static function check( $d ) {
		global $wpdb;
		$err  = array();
		$warn = array();
		$info = array();
		$T    = $wpdb->prefix . 'driveflow_sessions';
		$a    = self::ts( $d['start'] );
		$min  = (int) $d['minutes'];
		$b    = $a + $min * 60;
		$end  = wp_date( 'Y-m-d H:i:s', $b );
		if ( ! $a ) { return array( array( 'Choose a date and a start time.' ), array(), array() ); }
		if ( $a < time() - 300 ) { $err[] = 'This time is in the past.'; }
		$h1 = (int) wp_date( 'G', $a ) + (int) wp_date( 'i', $a ) / 60;
		$h2 = $h1 + $min / 60;
		if ( $h1 < self::OPENS || $h2 > self::CLOSES ) { $err[] = 'Lessons run between ' . self::OPENS . ':00 AM and ' . ( self::CLOSES - 12 ) . ':00 PM.'; }
		elseif ( $h1 < self::DAY ) {
			if ( ! empty( $d['early_ok'] ) || self::early_ok() ) { $warn[] = 'Starts before ' . self::DAY . ':00 AM — instructors normally start at ' . self::DAY . '. Make sure the instructor agreed to the early start.'; }
			else { $err[] = 'Lessons start at ' . self::DAY . ':00 AM. Only the school manager can book earlier (from ' . self::OPENS . ':00 AM).'; }
		}

		$ins = $d['ins'] ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}driveflow_instructors WHERE id = %d", $d['ins'] ), ARRAY_A ) : null; // phpcs:ignore
		if ( ! $ins && '' !== $d['ins_new'] ) {
			// Typed by hand: an instructor with that name already on file is used; otherwise a temporary profile is made on booking.
			$ins = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}driveflow_instructors WHERE LOWER(name) = %s ORDER BY status = 'active' DESC LIMIT 1", strtolower( $d['ins_new'] ) ), ARRAY_A ); // phpcs:ignore
			if ( $ins ) { $warn[] = $d['ins_new'] . ' is already on file — that profile will be used.'; }
			else {
				$ins = array( 'id' => 0, 'name' => $d['ins_new'], 'status' => 'active', 'new' => 1 );
				$warn[] = 'A temporary instructor profile will be made for ' . $d['ins_new'] . ' (no site account). Complete it later on the Instructors screen.';
			}
		}
		$car = $d['car'] ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}driveflow_vehicles WHERE id = %d", $d['car'] ), ARRAY_A ) : null; // phpcs:ignore
		if ( ! $ins ) { $err[] = 'new' === ( $_POST['ins'] ?? '' ) ? 'Type the instructor’s name.' : 'Choose the instructor.'; } // phpcs:ignore -- only a message
		if ( ! $car ) { $err[] = 'Choose the car.'; }
		if ( $ins && 'active' !== $ins['status'] ) { $err[] = $ins['name'] . ' is not active.'; }
		if ( $car && 'active' !== $car['status'] ) { $err[] = 'Car ' . $car['plate_number'] . ' is not active.'; }
		if ( $car && 'operational' !== $car['maintenance_status'] ) { $warn[] = 'Car ' . $car['plate_number'] . ' is marked "' . $car['maintenance_status'] . '".'; }
		if ( $ins && empty( $ins['new'] ) && class_exists( 'SDSC_Onboard' ) && ( $m = SDSC_Onboard::missing( $ins ) ) ) { $warn[] = $ins['name'] . ' has not finished onboarding yet (' . implode( ', ', $m ) . '). You can still assign them.'; }

		$over = function ( $where, $args ) use ( $wpdb, $T, $d, $end ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, student_name, instructor_name, plate_number, scheduled_start, scheduled_end FROM `{$T}` WHERE status IN ('upcoming','active') AND scheduled_start < %s AND scheduled_end > %s AND id <> %d AND {$where}", array_merge( array( $end, $d['start'], (int) ( $d['exclude'] ?? 0 ) ), $args ) ), ARRAY_A ); // phpcs:ignore
		};
		$fmt = function ( $r ) { return wp_date( 'g:i', self::ts( $r['scheduled_start'] ) ) . '–' . wp_date( 'g:i A', self::ts( $r['scheduled_end'] ) ); };
		if ( $ins ) {
			$iw = (int) $ins['id'] ? array( '(instructor_id = %d OR instructor_name = %s)', array( (int) $ins['id'], $ins['name'] ) ) : array( 'instructor_name = %s', array( $ins['name'] ) );
			foreach ( $over( $iw[0], $iw[1] ) as $r ) { $err[] = $ins['name'] . ' already teaches ' . $r['student_name'] . ' at ' . $fmt( $r ) . '.'; }
		}
		if ( $car ) {
			foreach ( $over( '(vehicle_id = %d OR plate_number = %s)', array( (int) $car['id'], $car['plate_number'] ) ) as $r ) { $err[] = 'Car ' . $car['plate_number'] . ' is out with ' . $r['student_name'] . ' at ' . $fmt( $r ) . '.'; }
		}
		// Blocked time (school closed, instructor off, car in the shop).
		$bt = $wpdb->prefix . 'driveflow_blocked_slots';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$bt}` WHERE start_time < %s AND end_time > %s", $end, $d['start'] ), ARRAY_A ) as $bs ) { // phpcs:ignore
				$why = $bs['reason'] ? ' (' . $bs['reason'] . ')' : '';
				if ( 'all' === $bs['target_type'] ) { $err[] = 'The school has blocked this time' . $why . '.'; }
				if ( 'instructor' === $bs['target_type'] && $ins && 0 === strcasecmp( trim( $bs['target_identifier'] ), $ins['name'] ) ) { $err[] = $ins['name'] . ' is off at this time' . $why . '.'; }
				if ( 'vehicle' === $bs['target_type'] && $car && 0 === strcasecmp( trim( $bs['target_identifier'] ), $car['plate_number'] ) ) { $err[] = 'Car ' . $car['plate_number'] . ' is blocked' . $why . '.'; }
			}
		}
		// The student: same time, and the 2-hour daily limit for Driver's Ed.
		$sid = self::find_student( $d['email'], $d['phone'] );
		if ( $sid || $d['name'] ) {
			$who = $sid ? array( 'student_id = %d', array( $sid ) ) : array( 'student_name = %s', array( $d['name'] ) );
			foreach ( $over( $who[0], $who[1] ) as $r ) { $err[] = 'The student already has a lesson at ' . $fmt( $r ) . '.'; }
			$day = substr( $d['start'], 0, 10 );
			$mins = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, scheduled_start, scheduled_end)),0) FROM `{$T}` WHERE status IN ('upcoming','active','completed') AND DATE(scheduled_start) = %s AND id <> %d AND " . $who[0], array_merge( array( $day, (int) ( $d['exclude'] ?? 0 ) ), $who[1] ) ) ); // phpcs:ignore
			if ( 'de' === $d['type'] && $mins + $min > 120 ) { $err[] = "Driver's Ed students may drive at most 2 hours a day (MVA rule, COMAR 11.23.02.34J); this student already has " . round( $mins / 60, 1 ) . ' h that day.'; }
			elseif ( $mins + $min > 180 ) { $warn[] = 'The student would drive ' . round( ( $mins + $min ) / 60, 1 ) . ' hours that day.'; }
		}
		if ( 'de' === $d['type'] && 'yes' !== $d['permit'] ) { $warn[] = "Driver's Ed lessons need a valid learner's permit — confirm the student has it."; }
		if ( 'de' === $d['type'] && $min > 120 ) { $err[] = "A Driver's Ed lesson is at most 2 hours."; }
		if ( class_exists( 'SDSC_Lessons' ) && $d['email'] ) {
			$owe = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Lessons::t_fees() . " WHERE email = %s AND status IN ('due','review')", strtolower( $d['email'] ) ) ); // phpcs:ignore
			if ( $owe ) { $warn[] = 'This student has ' . $owe . ' open lesson fee' . ( $owe > 1 ? 's' : '' ) . '.'; }
		}
		// Instructor's previous lesson ends right before somewhere else: a short travel gap.
		if ( $ins && ! $err ) {
			$prev = $wpdb->get_row( $wpdb->prepare( "SELECT scheduled_end FROM `{$T}` WHERE status IN ('upcoming','active') AND (instructor_id = %d OR instructor_name = %s) AND scheduled_end <= %s AND scheduled_end > %s ORDER BY scheduled_end DESC LIMIT 1", (int) $ins['id'] ? (int) $ins['id'] : -1, $ins['name'], $d['start'], wp_date( 'Y-m-d H:i:s', $a - 15 * 60 ) ), ARRAY_A ); // phpcs:ignore
			if ( $prev ) { $warn[] = $ins['name'] . "'s previous lesson ends less than 15 minutes before — check the travel time."; }
		}
		if ( class_exists( 'SDSC_DFOffer' ) && ( $ins || $car ) && ( $who2 = SDSC_DFOffer::overlaps( $d['start'], $end, $ins ? (int) $ins['id'] : 0, $car ? (int) $car['id'] : 0 ) ) ) { $warn[] = 'This time is also in an open offer to ' . implode( ', ', $who2 ) . ' (not chosen yet).'; }
		$hours       = class_exists( 'SDSC_Lessons' ) ? (int) SDSC_Lessons::settings()['hours'] : 48;
		if ( $a > time() && $a - $hours * HOUR_IN_SECONDS < time() ) { $warn[] = 'This lesson is less than ' . $hours . ' hours away, so the free-cancellation window has already closed — tell the student when you confirm.'; }
		$info['end'] = wp_date( 'g:i A', $b );
		$info['day'] = wp_date( 'l, F j, Y', $a );
		$info['dl']  = wp_date( 'l, F j \a\t g:i A', $a - $hours * HOUR_IN_SECONDS );
		return array( array_values( array_unique( $err ) ), array_values( array_unique( $warn ) ), $info );
	}

	protected static function find_student( $email, $phone ) {
		global $wpdb;
		$t = $wpdb->prefix . 'driveflow_students';
		if ( $email ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE LOWER(email) = %s ORDER BY id DESC LIMIT 1", strtolower( $email ) ) ); // phpcs:ignore
			if ( $id ) { return $id; }
		}
		$digits = preg_replace( '/\D/', '', (string) $phone );
		if ( strlen( $digits ) >= 10 ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'-',''),' ',''),'(',''),')',''),'+','') LIKE %s ORDER BY id DESC LIMIT 1", '%' . substr( $digits, -10 ) ) ); // phpcs:ignore
			if ( $id ) { return $id; }
		}
		return 0;
	}

	protected static function input() {
		$p     = wp_unslash( $_POST ); // phpcs:ignore -- sanitised field by field
		$date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['date'] ?? '' ) ) ? $p['date'] : '';
		$time  = preg_match( '/^\d{2}:\d{2}$/', (string) ( $p['time'] ?? '' ) ) ? $p['time'] : '';
		$type  = isset( self::types()[ $p['type'] ?? '' ] ) ? $p['type'] : 'extra';
		$min   = (int) ( $p['minutes'] ?? 120 );
		$first = sanitize_text_field( $p['first'] ?? '' );
		$last  = sanitize_text_field( $p['last'] ?? '' );
		return array(
			'start'   => $date && $time ? $date . ' ' . $time . ':00' : '',
			'minutes' => in_array( $min, array( 60, 90, 120, 180 ), true ) ? $min : 120,
			'type'    => $type,
			'ins'     => absint( $p['ins'] ?? 0 ),
			'ins_new' => 'new' === ( $p['ins'] ?? '' ) ? sanitize_text_field( mb_substr( (string) ( $p['ins_name'] ?? '' ), 0, 120 ) ) : '',
			'ins_ph'  => sanitize_text_field( mb_substr( (string) ( $p['ins_phone'] ?? '' ), 0, 40 ) ),
			'car'     => absint( $p['car'] ?? 0 ),
			'first'   => $first,
			'last'    => $last,
			'name'    => trim( $first . ' ' . $last ),
			'email'   => strtolower( sanitize_email( $p['email'] ?? '' ) ),
			'phone'   => sanitize_text_field( $p['phone'] ?? '' ),
			'permit'  => in_array( $p['permit'] ?? '', array( 'yes', 'no' ), true ) ? $p['permit'] : '',
			'permitno' => sanitize_text_field( mb_substr( (string) ( $p['permitno'] ?? '' ), 0, 40 ) ),
			'lang'    => 'es' === ( $p['lang'] ?? '' ) ? 'es' : 'en',
			'place'   => sanitize_text_field( mb_substr( (string) ( $p['place'] ?? '' ), 0, 250 ) ),
			'no'      => min( 99, absint( $p['no'] ?? 0 ) ),
			'topic'   => sanitize_text_field( mb_substr( (string) ( $p['topic'] ?? '' ), 0, 150 ) ),
			'note'    => sanitize_text_field( mb_substr( (string) ( $p['note'] ?? '' ), 0, 500 ) ),
			'mail'    => ! empty( $p['mail'] ),
			'tell'    => ! empty( $p['tell'] ),
			'exclude' => 0,
		);
	}

	public static function ajax_check() {
		if ( ! self::can() || ! check_ajax_referer( 'sdsc_dfb', '_n', false ) ) { wp_send_json_error( 'Not allowed', 403 ); }
		$d = self::input();
		list( $e, $w, $i ) = self::check( $d );
		wp_send_json_success( array( 'errors' => $e, 'warnings' => $w, 'info' => $i ) );
	}

	/**
	 * Offer builder: every start time of one week (30-minute steps) checked with the same rules as a booking,
	 * for the chosen instructor, car, student, kind and length. 0 = free, 1 = free with a note (early start),
	 * 2 = not possible (the reason is sent along).
	 */
	public static function ajax_grid() {
		if ( ! self::can() || ! check_ajax_referer( 'sdsc_dfb', '_n', false ) ) { wp_send_json_error( 'Not allowed', 403 ); }
		$d    = self::input();
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_POST['week'] ?? '' ) ) ? $_POST['week'] : wp_date( 'Y-m-d' ); // phpcs:ignore
		$days = array();
		$now  = time();
		for ( $i = 0; $i < 7; $i++ ) {
			$day  = wp_date( 'Y-m-d', strtotime( $from . ' 12:00:00 +' . $i . ' days' ) );
			$row  = array( 'date' => $day, 'label' => wp_date( 'D', strtotime( $day . ' 12:00:00' ) ), 'num' => wp_date( 'M j', strtotime( $day . ' 12:00:00' ) ), 'cells' => array() );
			foreach ( self::slots() as $m ) { // the time sheet's 2-hour blocks
				if ( $m + $d['minutes'] > self::CLOSES * 60 ) { continue; }
				$t = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
				$x = array_merge( $d, array( 'start' => $day . ' ' . $t . ':00' ) );
				if ( self::ts( $x['start'] ) < $now + HOUR_IN_SECONDS ) { $row['cells'][ $t ] = array( 2, 'Past' ); continue; }
				list( $e, $w ) = self::check( $x );
				// Messages about the person (name / instructor missing) are not about the time.
				$e = array_values( array_filter( $e, function ( $z ) { return 0 !== strpos( $z, 'Choose the' ) && 0 !== strpos( $z, 'Type the' ); } ) );
				if ( $e ) { $row['cells'][ $t ] = array( 2, $e[0] ); continue; }
				$early = array_filter( $w, function ( $z ) { return false !== strpos( $z, 'Starts before' ); } );
				$row['cells'][ $t ] = $early ? array( 1, 'Early start — manager only' ) : array( 0, '' );
			}
			$days[] = $row;
		}
		wp_send_json_success( array( 'days' => $days ) );
	}

	/* ------------------------------------------------------------------ book */

	public static function h_book() {
		if ( ! self::can() ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_dfb_book' );
		global $wpdb;
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=driveflow-pro-calendar' );
		$back = remove_query_arg( array( 'sdsc_booked', 'sdsc_berr' ), $back );
		$d    = self::input();
		if ( ! $d['first'] || ( ! is_email( $d['email'] ) && strlen( preg_replace( '/\D/', '', $d['phone'] ) ) < 10 ) ) {
			wp_safe_redirect( add_query_arg( 'sdsc_berr', rawurlencode( 'Enter the student’s name and an e-mail or a mobile number.' ), $back ) ); exit;
		}
		if ( ! $d['place'] ) { $d['place'] = self::places()[0] ?? ''; }
		// Double click / two tabs: one booking at a time for the same student and start (an option row is an atomic lock).
		$lock = 'sdsc_lock_' . md5( strtolower( $d['email'] . '|' . preg_replace( '/\D/', '', $d['phone'] ) . '|' . $d['start'] ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $lock, time() - 60 ) );
		$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')", $lock, time() ) );
		if ( 1 !== (int) $got ) { wp_safe_redirect( add_query_arg( 'sdsc_berr', rawurlencode( 'This lesson is already being booked (double click). Check the calendar.' ), $back ) ); exit; }
		list( $err ) = self::check( $d );
		if ( $err ) { $wpdb->delete( $wpdb->options, array( 'option_name' => $lock ) ); wp_safe_redirect( add_query_arg( 'sdsc_berr', rawurlencode( implode( ' ', $err ) ), $back ) ); exit; }

		$r = self::book( $d );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $lock ) );
		if ( is_wp_error( $r ) ) { wp_safe_redirect( add_query_arg( 'sdsc_berr', rawurlencode( $r->get_error_message() ), $back ) ); exit; }
		$ins = array( 'name' => $r['ins'] );
		$car = array( 'plate_number' => $r['plate'] );
		$msg = $d['name'] . ': ' . wp_date( 'D, M j, g:i A', self::ts( $d['start'] ) ) . ' with ' . $ins['name'] . ' in ' . $car['plate_number'] . ' — booked.' . ( $d['mail'] ? ( is_email( $d['email'] ) ? ' Confirmation e-mailed.' : ' The confirmation text is in Texts to send.' ) : '' );
		wp_safe_redirect( add_query_arg( 'sdsc_booked', rawurlencode( $msg ), $back ) );
		exit;
	}

	/** Books one lesson from checked input (staff form or a student's pick). Returns [id, instructor, plate] or WP_Error. */
	public static function book( $d ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$sid = self::find_student( $d['email'], $d['phone'] );
		$ST  = $wpdb->prefix . 'driveflow_students';
		if ( ! $sid ) {
			$wpdb->insert( $ST, array( 'name' => $d['name'], 'phone' => $d['phone'], 'email' => $d['email'], 'license_number' => $d['permitno'], 'package_name' => 'de' === $d['type'] ? "Driver's Ed — 6 hours behind the wheel" : 'Behind-the-wheel lessons', 'total_sessions' => 'de' === $d['type'] ? 3 : 1, 'completed_sessions' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
			$sid = (int) $wpdb->insert_id;
		} else {
			$up = array( 'updated_at' => $now );
			if ( $d['permitno'] ) { $up['license_number'] = $d['permitno']; }
			if ( $d['phone'] ) { $up['phone'] = $d['phone']; }
			if ( $d['email'] ) { $up['email'] = $d['email']; }
			$wpdb->update( $ST, $up, array( 'id' => $sid ) );
		}
		if ( ! $d['ins'] && '' !== $d['ins_new'] ) { $d['ins'] = self::ensure_typed_instructor( $d['ins_new'], $d['ins_ph'], $d['car'] ); }
		$ins   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name FROM {$wpdb->prefix}driveflow_instructors WHERE id = %d", $d['ins'] ), ARRAY_A ); // phpcs:ignore
		$car   = $wpdb->get_row( $wpdb->prepare( "SELECT id, plate_number FROM {$wpdb->prefix}driveflow_vehicles WHERE id = %d", $d['car'] ), ARRAY_A ); // phpcs:ignore
		$types = self::types();
		$end   = wp_date( 'Y-m-d H:i:s', self::ts( $d['start'] ) + $d['minutes'] * 60 );
		$topic = $types[ $d['type'] ][0] . ( $d['topic'] ? ' — ' . $d['topic'] : '' );
		$wpdb->insert( SDSC_DriveFlow::t(), array(
			'student_name'    => $d['name'],
			'student_id'      => $sid,
			'instructor_name' => $ins['name'],
			'instructor_id'   => (int) $ins['id'],
			'vehicle_id'      => (int) $car['id'],
			'plate_number'    => $car['plate_number'],
			'session_number'  => max( 1, $d['no'] ),
			'lesson_topic'    => mb_substr( $topic, 0, 190 ),
			'scheduled_start' => $d['start'],
			'scheduled_end'   => $end,
			'status'          => 'upcoming',
			'form_data'       => wp_json_encode( array( 'source' => 'sdsc_book', 'offer' => (int) ( $d['offer'] ?? 0 ), 'type' => $d['type'], 'place' => $d['place'], 'permit' => $d['permit'], 'lang' => $d['lang'], 'note' => $d['note'], 'practice' => $types[ $d['type'] ][0], 'first' => $d['first'], 'last' => $d['last'], 'email' => $d['email'], 'phone' => $d['phone'], 'by' => get_current_user_id() ) ),
			'signature'       => '',
			'selfie'          => '',
			'created_by'      => get_current_user_id(),
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return new WP_Error( 'save', 'The lesson could not be saved. Please try again.' ); }
		// Into the BTW lesson list now (confirmation e-mail with the cancellation deadline when asked).
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDSC_DriveFlow::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		SDSC_DriveFlow::apply( $row, $d['mail'] );
		if ( $d['tell'] && class_exists( 'DriveFlow_Email_Manager' ) && method_exists( 'DriveFlow_Email_Manager', 'send_instructor_booking' ) ) { DriveFlow_Email_Manager::send_instructor_booking( $id ); }
		if ( class_exists( 'DriveFlow_Wappointment_Sync' ) && method_exists( 'DriveFlow_Wappointment_Sync', 'push_session_to_wapp' ) ) { DriveFlow_Wappointment_Sync::push_session_to_wapp( $id ); }
		if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
		return array( 'id' => $id, 'ins' => $ins['name'], 'plate' => $car['plate_number'] );
	}

	/** A typed instructor name → a temporary DriveFlow profile (or the one already on file). */
	public static function ensure_typed_instructor( $name, $phone, $car ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}driveflow_instructors WHERE LOWER(name) = %s ORDER BY status = 'active' DESC LIMIT 1", strtolower( $name ) ) ); // phpcs:ignore
		if ( $id ) { return $id; }
		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'driveflow_instructors', array( 'user_id' => 0, 'name' => $name, 'phone' => $phone, 'email' => '', 'default_vehicle_id' => (int) $car, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		$id = (int) $wpdb->insert_id;
		if ( $id && class_exists( 'SDSC_Instructors' ) ) { SDSC_Instructors::mark_temp( $id ); }
		return $id;
	}

	public static function notice() {
		if ( ! self::on_page() ) { return; }
		if ( ! empty( $_GET['sdsc_booked'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['sdsc_booked'] ) ) ) . '</p></div>'; } // phpcs:ignore
		if ( ! empty( $_GET['sdsc_berr'] ) ) { echo '<div class="notice notice-error is-dismissible"><p><b>Not booked:</b> ' . esc_html( sanitize_text_field( wp_unslash( $_GET['sdsc_berr'] ) ) ) . '</p></div>'; } // phpcs:ignore
	}

	/* ------------------------------------------------------------------ the form */

	public static function modal() {
		if ( ! self::on_page() ) { return; }
		$times = array();
		$other = array();
		foreach ( self::slots() as $m ) { $times[ sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 ) ] = self::slot_label( $m ) . ( $m < self::DAY * 60 ? ' (early)' : '' ); }
		for ( $m = self::OPENS * 60; $m <= ( self::CLOSES - 1 ) * 60; $m += 30 ) { $k = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 ); if ( ! isset( $times[ $k ] ) ) { $other[ $k ] = gmdate( 'g:i A', $m * 60 ); } }
		?>
		<div class="df-modal-backdrop sdfb" id="sdfb-modal" role="dialog" aria-modal="true" aria-labelledby="sdfb-h">
			<div class="df-modal-dialog sdfb-dialog">
				<div class="df-modal-header"><h3 id="sdfb-h">Schedule a driving lesson</h3><button type="button" class="df-modal-close" data-df-close-modal aria-label="Close">&times;</button></div>
				<div class="df-modal-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sdfb-form" autocomplete="off">
						<input type="hidden" name="action" value="sdsc_dfb_book"><?php wp_nonce_field( 'sdsc_dfb_book' ); ?>
						<!-- DriveFlow's "click a free slot" fills these -->
						<input type="hidden" id="df-modal-start-time"><input type="hidden" id="df-modal-end-time"><input type="hidden" id="df-modal-plate"><input type="hidden" id="df-modal-instructor">

						<fieldset class="sdfb-sec"><legend>1 · Student</legend>
							<div class="sdfb-find"><input type="search" id="sdfb-q" placeholder="Search by name, e-mail, phone or class (e.g. C-70)" aria-label="Find the student"><ul id="sdfb-hits" class="sdfb-hits" role="listbox"></ul></div>
							<div id="sdfb-card" class="sdfb-card" hidden></div>
							<div class="sdfb-grid">
								<label>First name<input name="first" id="sdfb-first" required></label>
								<label>Last name<input name="last" id="sdfb-last"></label>
								<label>E-mail<input type="email" name="email" id="sdfb-email"></label>
								<label>Mobile<input type="tel" name="phone" id="sdfb-phone" placeholder="(301) 555-0123"></label>
								<label>Learner&rsquo;s permit<select name="permit" id="sdfb-permit"><option value="">Not asked yet</option><option value="yes">Has a valid permit</option><option value="no">No permit yet</option></select></label>
								<label>Permit / license # <small>(optional)</small><input name="permitno" id="sdfb-permitno" maxlength="40"></label>
								<label>Messages in<select name="lang" id="sdfb-lang"><option value="en">English</option><option value="es">Español</option></select></label>
							</div>
						</fieldset>

						<fieldset class="sdfb-sec"><legend>2 · Lesson</legend>
							<div class="sdfb-mode" role="radiogroup" aria-label="How to book">
								<label><input type="radio" name="mode" value="one" checked> Book one time</label>
								<label><input type="radio" name="mode" value="offer"> Offer several times — the student chooses</label>
							</div>
							<div class="sdfb-grid">
								<label class="sdfb-wide">Kind of lesson<select name="type" id="sdfb-type"><?php foreach ( self::types() as $k => $t ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $t[0] ); ?></option><?php endforeach; ?></select></label>
								<label class="sdfb-one">Date<input type="date" name="date" id="sdfb-date" required min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></label>
								<label class="sdfb-one">Start<select name="time" id="sdfb-time" required><option value="">—</option><optgroup label="Time sheet blocks"><?php foreach ( $times as $v => $l ) : ?><option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></optgroup><optgroup label="Other start (off the time sheet)"><?php foreach ( $other as $v => $l ) : ?><option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></optgroup></select></label>
								<label>Length<select name="minutes" id="sdfb-min"><option value="60">1 hour</option><option value="90">1½ hours</option><option value="120" selected>2 hours</option><option value="180">3 hours</option></select></label>
								<label>Lesson number<input type="number" name="no" id="sdfb-no" min="1" max="99" value="1"></label>
								<label class="sdfb-wide">Focus <small>(optional)</small><input name="topic" id="sdfb-topic" list="sdfb-topics" maxlength="150" placeholder="e.g. lane changes and merging"><datalist id="sdfb-topics"><option value="Basic control, mirrors, turns"><option value="Residential streets and intersections"><option value="Lane changes and merging"><option value="Highway driving"><option value="Parallel parking and backing"><option value="MVA test route practice"><option value="Night driving"></datalist></label>
							</div>
						</fieldset>

						<fieldset class="sdfb-sec"><legend>3 · Instructor, car and place</legend>
							<div class="sdfb-grid">
								<label>Instructor<select name="ins" id="sdfb-ins" required><option value="">Choose…</option><option value="new">+ Type a name (no account yet)</option></select></label>
								<label class="sdfb-newins" hidden>Instructor&rsquo;s name<input name="ins_name" id="sdfb-insname" maxlength="120" placeholder="First and last name"></label>
								<label class="sdfb-newins" hidden>Instructor&rsquo;s mobile <small>(optional)</small><input type="tel" name="ins_phone" maxlength="40"></label>
								<label>Car<select name="car" id="sdfb-car" required><option value="">Choose…</option></select></label>
								<label class="sdfb-wide">Meeting place<input name="place" id="sdfb-place" list="sdfb-places" required maxlength="250"><datalist id="sdfb-places"></datalist></label>
								<label class="sdfb-wide">Note for the team <small>(not sent to the student)</small><input name="note" id="sdfb-note" maxlength="500"></label>
							</div>
							<p class="sdfb-mini" id="sdfb-addins"></p>
						</fieldset>

						<div id="sdfb-check" class="sdfb-check" aria-live="polite"></div>

						<fieldset class="sdfb-sec sdfb-offer" id="sdfb-offer" hidden><legend>4 · Times to offer</legend>
							<p class="sdfb-mini">Tap the times to offer — as many days and times as you like. Grey times are not possible (the reason shows when you point at them); they follow the instructor, car, kind of lesson and length chosen above.</p>
							<div class="sdfb-wk"><button type="button" class="df-btn df-btn-secondary" id="sdfb-wprev" aria-label="Previous week">‹</button><b id="sdfb-wlab"></b><button type="button" class="df-btn df-btn-secondary" id="sdfb-wnext" aria-label="Next week">›</button><span id="sdfb-wst" class="sdfb-mini"></span></div>
							<div id="sdfb-grid" class="sdfb-tgrid" role="grid" aria-label="Times to offer"></div>
							<button type="button" class="df-btn df-btn-secondary" id="sdfb-add" hidden disabled>+ Add this time</button>
							<p class="sdfb-mini"><b>Chosen times</b> <span id="sdfb-cnt"></span></p>
							<ol id="sdfb-list" class="sdfb-list"></ol>
							<input type="hidden" name="slots" id="sdfb-slots" value="[]">
							<div class="sdfb-grid">
								<label>The student chooses<select name="pick" id="sdfb-pick"><option value="1">1 time</option></select></label>
								<label>The link works for<select name="days"><option value="1">1 day</option><option value="2">2 days</option><option value="3" selected>3 days</option><option value="7">7 days</option></select></label>
							</div>
							<label class="sdfb-tick"><input type="checkbox" name="send" value="1" checked> Send the link to the student now (e-mail, and a text — it waits in <i>Texts to send</i> if texting is not connected)</label>
						</fieldset>

						<div class="sdfb-foot">
							<label class="sdfb-tick"><input type="checkbox" name="mail" value="1" checked> Send the student the confirmation (with the free-cancellation deadline)</label>
							<label class="sdfb-tick"><input type="checkbox" name="tell" value="1" checked> Tell the instructor</label>
							<div class="sdfb-btns"><button type="button" class="df-btn df-btn-secondary" data-df-close-modal>Cancel</button><button type="submit" class="df-btn df-btn-primary" id="sdfb-go" disabled>Book the lesson</button></div>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
