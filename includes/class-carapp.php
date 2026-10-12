<?php
/**
 * In-car lesson app (the instructor's phone / tablet screen at /instructor-session/).
 *
 * DriveFlow still owns the data and the "save evaluation" handler; this class draws a new, touch-first
 * screen on top of it and adds what was missing:
 *  - the instructor's lessons: needs a report / today / upcoming, refreshed live when the office
 *    books, moves or re-assigns a lesson;
 *  - the student's whole record across instructors (a student can move between instructors): earlier
 *    lessons' ratings, who taught them, the last instructor's note, lessons left;
 *  - one lesson column to fill in, big 4-3-2-1 buttons, car & fuel check, two signatures, draft saved
 *    on the device so a weak signal never loses a report;
 *  - notifications and messages (SDSC_Notify / SDSC_Chat).
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_CarApp {

	public static function skills() {
		return array( 'Pre-entry checks', 'Pre-start and starting', 'Moving forward', 'Moving backward', 'Entering traffic', 'Right turns', 'Left turns', 'Negotiating intersections', 'Changing lanes', 'Slowing and stopping', 'Parking and securing', 'Parking on grades', 'Angle parking', 'Parallel parking', 'Turnabouts', 'Assessing highway conditions', 'Space management', 'Response to traffic controls', 'Response to other users', 'Passing', 'Commentary driving', 'Speed' );
	}

	public static function conditions() { return array( 'Adverse weather', 'Night driving' ); }

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
	}

	/* ------------------------------------------------------------ who */

	protected static function t( $x ) { global $wpdb; return $wpdb->prefix . 'driveflow_' . $x; }

	/** The DriveFlow instructor row of the signed-in user (null for office users without one). */
	public static function me() {
		global $wpdb;
		$u = wp_get_current_user();
		if ( ! $u->ID ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, license_number, phone, email, status FROM ' . self::t( 'instructors' ) . ' WHERE user_id = %d LIMIT 1', $u->ID ), ARRAY_A ); // phpcs:ignore
		if ( ! $row && $u->user_email ) { $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, license_number, phone, email, status FROM ' . self::t( 'instructors' ) . ' WHERE email = %s LIMIT 1', $u->user_email ), ARRAY_A ); } // phpcs:ignore
		return $row ? $row : null;
	}

	public static function is_office() { return current_user_can( 'manage_options' ) || ( class_exists( 'SDSC_Notify' ) && SDSC_Notify::is_office() ); }

	public static function allowed() {
		if ( ! is_user_logged_in() ) { return false; }
		if ( self::is_office() ) { return true; }
		$me = self::me();
		return $me && 'active' === $me['status'];
	}

	/** Instructor name whose lessons to show (office may pick one; '' = everyone). */
	protected static function who( $req ) {
		if ( self::is_office() ) {
			$id = absint( $req['ins'] );
			if ( ! $id ) { $me = self::me(); return $me ? $me['name'] : ''; }
			global $wpdb;
			return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . self::t( 'instructors' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
		}
		$me = self::me();
		return $me ? $me['name'] : '__none__';
	}

	/** May the current user see this student's record? Office: yes. Instructor: when they teach / taught them. */
	protected static function can_student( $sid, $sname ) {
		if ( self::is_office() ) { return true; }
		$me = self::me();
		if ( ! $me ) { return false; }
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'sessions' ) . ' WHERE (student_id = %d OR student_name = %s) AND instructor_name = %s LIMIT 1', $sid, $sname, $me['name'] ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------ REST */

	public static function rest() {
		$ok = function () { return self::allowed(); };
		register_rest_route( 'sdsc/v1', '/car/agenda', array( 'methods' => 'GET', 'permission_callback' => $ok, 'callback' => array( __CLASS__, 'agenda' ) ) );
		register_rest_route( 'sdsc/v1', '/car/student', array( 'methods' => 'GET', 'permission_callback' => $ok, 'callback' => array( __CLASS__, 'student' ) ) );
		register_rest_route( 'sdsc/v1', '/car/saved', array( 'methods' => 'POST', 'permission_callback' => $ok, 'callback' => array( __CLASS__, 'saved' ) ) );
	}

	protected static function day_label( $ts, $now ) {
		$d = gmdate( 'Y-m-d', $ts );
		if ( gmdate( 'Y-m-d', $now ) === $d ) { return 'Today'; }
		if ( gmdate( 'Y-m-d', $now + DAY_IN_SECONDS ) === $d ) { return 'Tomorrow'; }
		if ( gmdate( 'Y-m-d', $now - DAY_IN_SECONDS ) === $d ) { return 'Yesterday'; }
		return date_i18n( 'D, M j', $ts );
	}

	public static function agenda( $req ) {
		global $wpdb;
		nocache_headers();
		$name  = self::who( $req );
		$now   = current_time( 'timestamp' ); // phpcs:ignore
		$today = gmdate( 'Y-m-d', $now );
		$from  = gmdate( 'Y-m-d 00:00:00', $now - 30 * DAY_IN_SECONDS );
		$to    = gmdate( 'Y-m-d 23:59:59', $now + 21 * DAY_IN_SECONDS );
		$where = $wpdb->prepare( 's.scheduled_start BETWEEN %s AND %s', $from, $to );
		if ( '' !== $name ) { $where .= $wpdb->prepare( ' AND s.instructor_name = %s', $name ); }
		if ( ! get_user_meta( get_current_user_id(), 'sdsc_preview', true ) ) { $where .= " AND s.lesson_topic NOT LIKE '%(demo)'"; } // preview demo lessons only in previews
		$rows = $wpdb->get_results( 'SELECT s.id, s.student_id, s.student_name, s.instructor_name, s.session_number, s.lesson_topic, s.scheduled_start, s.scheduled_end, s.status, s.plate_number, d.phone, d.email, d.license_number, d.total_sessions, v.model, v.color FROM ' . self::t( 'sessions' ) . ' s LEFT JOIN ' . self::t( 'students' ) . ' d ON d.id = s.student_id LEFT JOIN ' . self::t( 'vehicles' ) . " v ON v.plate_number = s.plate_number WHERE $where ORDER BY s.scheduled_start ASC", ARRAY_A ); // phpcs:ignore

		$due = $day = $next = array();
		$prev_cache = array();
		foreach ( (array) $rows as $r ) {
			$st  = $r['status'];
			$a   = strtotime( $r['scheduled_start'] );
			$b   = strtotime( $r['scheduled_end'] );
			$sid = (int) $r['student_id'];
			$key = $sid ? 'i' . $sid : 'n' . $r['student_name'];
			if ( ! isset( $prev_cache[ $key ] ) ) {
				// Who taught this student before, and how many lessons are done.
				$done = $wpdb->get_results( $wpdb->prepare( 'SELECT instructor_name, session_number, scheduled_start FROM ' . self::t( 'sessions' ) . " WHERE (student_id = %d OR student_name = %s) AND status = 'completed' ORDER BY scheduled_start ASC", $sid, $r['student_name'] ), ARRAY_A ); // phpcs:ignore
				$prev_cache[ $key ] = (array) $done;
			}
			$done   = $prev_cache[ $key ];
			$before = array_values( array_filter( $done, function ( $x ) use ( $r ) { return $x['scheduled_start'] < $r['scheduled_start']; } ) );
			$lastI  = $before ? end( $before )['instructor_name'] : '';
			$u      = $r['email'] ? get_user_by( 'email', $r['email'] ) : null;
			$item   = array(
				'id' => (int) $r['id'], 'start' => $r['scheduled_start'], 'end' => $r['scheduled_end'], 'ts' => $a,
				'day' => self::day_label( $a, $now ), 'time' => date_i18n( 'g:i', $a ) . '–' . date_i18n( 'g:i A', $b ), 'mins' => max( 0, (int) round( ( $b - $a ) / 60 ) ),
				'student' => array( 'id' => $sid, 'name' => $r['student_name'], 'phone' => (string) $r['phone'], 'permit' => (string) $r['license_number'], 'uid' => $u ? (int) $u->ID : 0 ),
				'instructor' => $r['instructor_name'], 'number' => (int) $r['session_number'], 'total' => (int) $r['total_sessions'], 'done' => count( $done ),
				'topic' => $r['lesson_topic'], 'plate' => $r['plate_number'], 'car' => trim( $r['color'] . ' ' . $r['model'] ), 'status' => $st,
				'prev' => ( $lastI && strcasecmp( $lastI, $r['instructor_name'] ) ) ? $lastI : '', 'first' => ! $before,
			);
			$open = in_array( $st, array( 'upcoming', 'active' ), true );
			$is_today = gmdate( 'Y-m-d', $a ) === $today;
			if ( $open && $b < $now ) { $item['due'] = 1; $due[] = $item; }
			if ( $is_today ) { $day[] = $item; }
			elseif ( $open && $a > $now ) { $next[] = $item; }
		}
		$out = array( 'due' => array_reverse( $due ), 'today' => $day, 'next' => $next, 'who' => $name, 'now' => $now, 'cal' => (int) get_option( 'driveflow_last_calendar_change', 0 ) );
		if ( self::is_office() ) {
			$out['instructors'] = $wpdb->get_results( 'SELECT id, name FROM ' . self::t( 'instructors' ) . " WHERE status = 'active' ORDER BY name", ARRAY_A ); // phpcs:ignore
		}
		return rest_ensure_response( $out );
	}

	public static function student( $req ) {
		global $wpdb;
		nocache_headers();
		$sid   = absint( $req['id'] );
		$name  = sanitize_text_field( (string) $req['name'] );
		$s     = $sid ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'students' ) . ' WHERE id = %d', $sid ), ARRAY_A ) : null; // phpcs:ignore
		if ( ! $s && '' !== $name ) { $s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'students' ) . ' WHERE name = %s LIMIT 1', $name ), ARRAY_A ); } // phpcs:ignore
		$sid  = $s ? (int) $s['id'] : 0;
		$name = $s ? $s['name'] : $name;
		if ( '' === $name ) { return new WP_Error( 'sdsc_car', 'Student not found.', array( 'status' => 404 ) ); }
		// Walk-in with a brand-new name: nothing to show yet, but nothing to hide either.
		if ( $s && ! self::can_student( $sid, $name ) ) { return new WP_Error( 'sdsc_car', 'This student is not on your schedule.', array( 'status' => 403 ) ); }
		$me   = self::me();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, session_number, scheduled_start, status, instructor_name, plate_number, form_data FROM ' . self::t( 'sessions' ) . ' WHERE (student_id = %d OR student_name = %s) ORDER BY scheduled_start ASC', $sid, $name ), ARRAY_A ); // phpcs:ignore

		$grid = array( 'dates' => array(), 'skills' => array(), 'conds' => array(), 'cols' => array() );
		$hist = array();
		$note = null;
		$done = 0;
		foreach ( (array) $rows as $r ) {
			$f  = json_decode( (string) $r['form_data'], true );
			$f  = is_array( $f ) ? $f : array();
			$ts = strtotime( $r['scheduled_start'] );
			$c  = 'completed' === $r['status'];
			if ( $c ) { $done++; }
			$hist[] = array(
				'id' => (int) $r['id'], 'number' => (int) $r['session_number'], 'date' => date_i18n( 'M j, Y', $ts ), 'day' => date_i18n( 'D, M j', $ts ), 'instructor' => $r['instructor_name'],
				'status' => $r['status'], 'result' => $f['final_evaluation'] ?? '', 'mine' => $me && 0 === strcasecmp( $me['name'], $r['instructor_name'] ),
				'issue' => ! empty( $f['incident']['reported'] ),
			);
			if ( ! $c || empty( $f['skills_eval'] ) ) { continue; }
			// Each saved record carries the whole sheet; later records win.
			foreach ( (array) ( $f['dates'] ?? array() ) as $k => $v ) { if ( '' !== (string) $v ) { $grid['dates'][ $k ] = $v; } }
			foreach ( (array) $f['skills_eval'] as $i => $cols ) { foreach ( (array) $cols as $k => $v ) { if ( '' !== (string) $v ) { $grid['skills'][ $i ][ $k ] = $v; } } }
			foreach ( (array) ( $f['conds_eval'] ?? array() ) as $i => $cols ) { foreach ( (array) $cols as $k => $v ) { if ( '' !== (string) $v ) { $grid['conds'][ $i ][ $k ] = $v; } } }
			$col = 'l' . max( 1, min( 6, (int) ( $f['column'] ?? $r['session_number'] ) ) );
			$grid['cols'][ $col ] = array( 'instructor' => $r['instructor_name'], 'date' => date_i18n( 'M j', $ts ), 'number' => (int) $r['session_number'] );
			if ( ! empty( $f['instructor_notes'] ) ) {
				$note = array( 'text' => $f['instructor_notes'], 'by' => $r['instructor_name'], 'date' => date_i18n( 'M j', $ts ), 'number' => (int) $r['session_number'] );
			}
		}
		$total = $s ? (int) $s['total_sessions'] : 0;
		$u     = $s && $s['email'] ? get_user_by( 'email', $s['email'] ) : null;
		return rest_ensure_response( array(
			'id' => $sid, 'name' => $name, 'permit' => $s ? $s['license_number'] : '', 'phone' => $s ? $s['phone'] : '', 'package' => $s ? $s['package_name'] : '',
			'total' => $total, 'done' => $done, 'left' => $total ? max( 0, $total - $done ) : null, 'uid' => $u ? (int) $u->ID : 0,
			'history' => $hist, 'grid' => $grid, 'note' => $note, 'next' => $done + 1,
		) );
	}

	/** After DriveFlow saved an evaluation: office alerts (car problem, low fuel, not passed) + live refresh for everyone. */
	public static function saved( $req ) {
		global $wpdb;
		$id = absint( $req['session_id'] );
		$s  = $wpdb->get_row( $wpdb->prepare( 'SELECT instructor_name, status FROM ' . self::t( 'sessions' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		$me = self::me();
		if ( ! $s || ( ! self::is_office() && ( ! $me || strcasecmp( $me['name'], $s['instructor_name'] ) ) ) ) { return new WP_Error( 'sdsc_car', 'Not allowed.', array( 'status' => 403 ) ); }
		$col = absint( $req['column'] );
		if ( $col >= 1 && $col <= 6 ) {
			$f = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT form_data FROM ' . self::t( 'sessions' ) . ' WHERE id = %d', $id ) ), true ); // phpcs:ignore
			if ( is_array( $f ) ) {
				$f['column']        = $col;
				$f['lesson_minutes'] = absint( $req['minutes'] );
				$wpdb->update( self::t( 'sessions' ), array( 'form_data' => wp_json_encode( $f ) ), array( 'id' => $id ) );
			}
		}
		if ( class_exists( 'SDSC_Notify' ) ) { SDSC_Notify::on_evaluation( $id ); }
		return rest_ensure_response( array( 'ok' => 1 ) );
	}

	/* ------------------------------------------------------------ screen */

	/**
	 * Full app markup. Called from DriveFlow's templates/instructor-form.php with its variables
	 * ($df_nonce, $vehicles, $students, $current_instructor, $locked, $brand).
	 */
	public static function render( $v ) {
		$brand  = isset( $v['brand'] ) && is_array( $v['brand'] ) ? $v['brand'] : array();
		$school = ! empty( $brand['name'] ) ? $brand['name'] : 'Sam’s Driving School';
		$logo   = ! empty( $brand['logo'] ) ? $brand['logo'] : '';
		$me     = ! empty( $v['current_instructor'] ) ? $v['current_instructor'] : null;
		$user   = wp_get_current_user();
		$cfg    = array(
			'rest'      => esc_url_raw( rest_url( 'sdsc/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'dfNonce'   => (string) ( $v['df_nonce'] ?? '' ),
			'office'    => self::is_office() ? 1 : 0,
			'me'        => $me ? array( 'id' => (int) $me['id'], 'name' => $me['name'], 'license' => (string) ( $me['license_number'] ?? '' ) ) : null,
			'user'      => $user->display_name,
			'skills'    => self::skills(),
			'conds'     => self::conditions(),
			'vehicles'  => array_values( array_map( function ( $x ) { return array( 'plate' => $x['plate_number'], 'model' => $x['model'] ); }, (array) ( $v['vehicles'] ?? array() ) ) ),
			'students'  => array_values( array_map( function ( $x ) { return array( 'id' => (int) $x['id'], 'name' => $x['name'] ); }, (array) ( $v['students'] ?? array() ) ) ),
			'today'     => current_time( 'Y-m-d' ),
			'dashboard' => function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' ),
			'logout'    => wp_logout_url( home_url( '/' ) ),
		);
		$ver = defined( 'SDSC_VERSION' ) ? SDSC_VERSION : '1';
		ob_start();
		?>
<link rel="stylesheet" href="<?php echo esc_url( SDSC_URL . 'assets/carapp.css?ver=' . $ver ); ?>">
<?php echo class_exists( 'SDSC_Notify' ) ? SDSC_Notify::tags() : ''; // phpcs:ignore -- escaped inside ?>
<div id="sca" class="sca" data-state="loading">
	<header class="sca-bar">
		<div class="sca-brand">
			<?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" class="sca-logo"><?php else : ?><span class="sca-mark" aria-hidden="true">S</span><?php endif; ?>
			<span class="sca-bt"><b><?php echo esc_html( $school ); ?></b><small>In-car lesson app</small></span>
		</div>
		<div class="sca-bar-r">
			<span class="sca-sync" title="Live — updates by itself"><i></i><span>Live</span></span>
			<div id="sdsc-dock-mount"></div>
			<details class="sca-me">
				<summary aria-label="Account"><span class="sca-av"><?php echo esc_html( strtoupper( mb_substr( $me ? $me['name'] : $user->display_name, 0, 1 ) ) ); ?></span></summary>
				<div class="sca-menu">
					<p><b><?php echo esc_html( $me ? $me['name'] : $user->display_name ); ?></b><?php if ( $me && ! empty( $me['license_number'] ) ) : ?><small>Instructor license <?php echo esc_html( $me['license_number'] ); ?></small><?php endif; ?></p>
					<a href="<?php echo esc_url( $cfg['dashboard'] ); ?>">My dashboard</a>
					<a href="<?php echo esc_url( $cfg['logout'] ); ?>">Sign out</a>
				</div>
			</details>
		</div>
	</header>
	<main class="sca-main" id="sca-main" tabindex="-1">
		<div class="sca-skel" aria-hidden="true"><i></i><i></i><i></i></div>
	</main>
	<div class="sca-foot" id="sca-foot" hidden></div>
	<noscript><p class="sca-nojs">Please turn on JavaScript to use the in-car app.</p></noscript>
</div>
<script>window.SCA=<?php echo wp_json_encode( $cfg ); ?>;</script>
<script src="<?php echo esc_url( SDSC_URL . 'assets/carapp.js?ver=' . $ver ); ?>" defer></script>
		<?php
		return ob_get_clean();
	}
}
