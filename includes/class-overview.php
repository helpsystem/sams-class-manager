<?php
/**
 * Dashboard home ("Today at Sam's") — shown at the top of the Tutor dashboard home for every role.
 *
 *  - Administrator / School manager / Front desk: today's lessons and Zoom sessions, attendance sheets
 *    to submit, new registrations, payments, fees due, open classes, and shortcuts to the office screens.
 *  - Instructor: today's Zoom classes (take attendance), sheets to submit, behind-the-wheel lessons.
 *  - Student: each class with its next session, attendance and payment, upcoming driving lessons,
 *    fees due, and shortcuts (calendar, policies, call the school).
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Overview {
	protected static $done = false;

	public static function init() {
		add_filter( 'should_tutor_load_template', array( __CLASS__, 'inject' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_shortcode( 'sdsc_overview', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_sdsc_office_link_text', array( __CLASS__, 'h_office_link_text' ) );
	}

	public static function assets() {
		if ( ! is_user_logged_in() ) { return; }
		wp_register_style( 'sdsc-overview', SDSC_URL . 'assets/overview.css', array(), SDSC_VERSION . '.' . (int) @filemtime( SDSC_DIR . 'assets/overview.css' ) );
		wp_register_style( 'sdsc-lessons-front', SDSC_URL . 'assets/lessons.css', array(), SDSC_VERSION );
		$id = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) : 0;
		if ( $id && is_page( $id ) ) { wp_enqueue_style( 'sdsc-overview' ); wp_enqueue_style( 'sdsc-lessons-front' ); }
	}

	/**
	 * Print our panel right before Tutor's own dashboard home. For the office (administrator, school manager,
	 * front desk) Tutor's course-selling numbers (earnings, courses, ratings, completion) are left out: they
	 * are always empty here, and the Price watch card takes their place.
	 */
	public static function inject( $load, $template ) {
		if ( ! in_array( $template, array( 'dashboard.dashboard', 'dashboard.student.dashboard' ), true ) ) { return $load; }
		if ( ! self::$done ) {
			self::$done = true;
			echo self::render(); // phpcs:ignore -- built from escaped parts
		}
		return in_array( self::kind(), array( 'admin', 'manager', 'frontdesk' ), true ) ? false : $load;
	}

	/* ------------------------------------------------------------ who */

	public static function kind() {
		if ( current_user_can( 'manage_options' ) ) { return 'admin'; }
		$r = (array) wp_get_current_user()->roles;
		if ( class_exists( 'SDSC_Access' ) ) {
			if ( in_array( SDSC_Access::MANAGER, $r, true ) ) { return 'manager'; }
			if ( in_array( SDSC_Access::FRONTDESK, $r, true ) ) { return 'frontdesk'; }
		}
		if ( array_intersect( $r, array( 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' ) ) ) { return 'instructor'; }
		return 'student';
	}

	/* ------------------------------------------------------------ small helpers */

	protected static function money( $n ) { return '$' . number_format( (float) $n, ( floor( (float) $n ) == (float) $n ) ? 0 : 2 ); }

	protected static function tile( $num, $label, $tone = '', $url = '' ) {
		$in = '<b>' . esc_html( $num ) . '</b><span>' . esc_html( $label ) . '</span>';
		return $url ? '<a class="sov-tile ' . esc_attr( $tone ) . '" href="' . esc_url( $url ) . '">' . $in . '</a>' : '<div class="sov-tile ' . esc_attr( $tone ) . '">' . $in . '</div>';
	}

	protected static function card( $title, $body, $more = '', $more_url = '' ) {
		return '<section class="sov-card"><header><h4>' . esc_html( $title ) . '</h4>' . ( $more && $more_url ? '<a href="' . esc_url( $more_url ) . '">' . esc_html( $more ) . ' →</a>' : '' ) . '</header>' . $body . '</section>';
	}

	protected static function empty_note( $t ) { return '<p class="sov-empty">' . esc_html( $t ) . '</p>'; }

	protected static function links( $items ) {
		$h = '<nav class="sov-links">';
		foreach ( $items as $i ) { if ( $i ) { $h .= '<a href="' . esc_url( $i[1] ) . '"' . ( ! empty( $i[2] ) ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $i[0] ) . '</a>'; } }
		return $h . '</nav>';
	}

	protected static function admin( $page ) { return admin_url( 'admin.php?page=' . $page ); }

	protected static function t( $suffix ) { global $wpdb; return $wpdb->prefix . $suffix; }

	protected static function exists( $table ) {
		global $wpdb;
		static $c = array();
		if ( ! isset( $c[ $table ] ) ) { $c[ $table ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); }
		return $c[ $table ];
	}

	/** Zoom sessions happening today: [ [class id, session], ... ] for the given classes. */
	protected static function zoom_today( $classes ) {
		$today = current_time( 'Y-m-d' );
		$out   = array();
		foreach ( $classes as $c ) {
			foreach ( SDSC_Schedule::sessions( is_object( $c ) ? $c->ID : (int) $c ) as $x ) {
				if ( $x['date'] === $today ) { $out[] = array( is_object( $c ) ? $c->ID : (int) $c, $x ); }
			}
		}
		return $out;
	}

	protected static function lesson_rows( $rows, $who = 'student' ) {
		if ( ! $rows ) { return ''; }
		$h = '<ul class="sov-list">';
		foreach ( $rows as $l ) {
			$st = (string) $l['status'];
			$h .= '<li><span class="sov-when">' . esc_html( wp_date( 'g:i A', SDSC_Lessons::ts( $l['starts_at'] ) ) ) . '<small>' . esc_html( wp_date( 'D M j', SDSC_Lessons::ts( $l['starts_at'] ) ) ) . '</small></span>'
				. '<span class="sov-what"><b>' . esc_html( 'student' === $who ? trim( $l['first_name'] . ' ' . $l['last_name'] ) : ( $l['instructor'] ? 'with ' . $l['instructor'] : 'Driving lesson' ) ) . '</b>'
				. '<small>' . esc_html( trim( ( 'student' === $who && $l['instructor'] ? $l['instructor'] . ' · ' : '' ) . ( $l['lesson_no'] ? 'Lesson ' . $l['lesson_no'] : '' ) . ( $l['place'] ? ' · ' . $l['place'] : '' ), ' ·' ) ) . '</small></span>'
				. '<span class="sov-chip sov-chip--' . esc_attr( $st ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $st ) ) ) . '</span></li>';
		}
		return $h . '</ul>';
	}

	/* ------------------------------------------------------------ render */

	public static function render() {
		if ( ! is_user_logged_in() ) { return ''; }
		wp_enqueue_style( 'sdsc-overview' );
		wp_enqueue_style( 'sdsc-lessons-front' );
		$k    = self::kind();
		$u    = wp_get_current_user();
		$hour = (int) current_time( 'G' );
		$hi   = ( $hour < 12 ? 'Good morning' : ( $hour < 17 ? 'Good afternoon' : 'Good evening' ) ) . ', ' . ( $u->first_name ? $u->first_name : $u->display_name );
		$role = array( 'admin' => 'Administrator', 'manager' => 'School manager', 'frontdesk' => 'Front desk', 'instructor' => 'Instructor', 'student' => 'Student' )[ $k ];
		$h    = '<div class="sov"><div class="sov-hero"><div><p class="sov-kicker">' . esc_html( $role . ' · ' . wp_date( 'l, F j' ) ) . '</p><h3>' . esc_html( $hi ) . '</h3></div></div>';
		if ( class_exists( 'SDSC_Pins' ) ) { $h .= SDSC_Pins::assets() . SDSC_Pins::strip(); }
		if ( in_array( $k, array( 'admin', 'manager', 'frontdesk' ), true ) ) { $h .= self::office( $k ); }
		elseif ( 'instructor' === $k ) { $h .= self::instructor( $u ); }
		else { $h .= self::student( $u ); }
		return $h . '</div>';
	}

	/* ------------------------------------------------------------ tools, grouped */

	/** Every screen this person may open, grouped like a toolbox. */
	public static function tools() {
		if ( class_exists( 'SDSC_Workspace' ) ) {
			$out = array();
			foreach ( SDSC_Workspace::menu() as $g => $items ) {
				$out[ $g ] = array( SDSC_Workspace::groups()[ $g ], array_map( function ( $i ) { return array( $i[0], $i[1], $i[3], $i[2] ); }, $items ) );
			}
			return $out;
		}
		$groups = array(
			'students' => array( 'Students & classes', array() ),
			'lessons'  => array( 'Lessons & attendance', array() ),
			'sales'    => array( 'Money', array() ),
			'reports'  => array( 'Reports', array() ),
			'website'  => array( 'Website', array() ),
			'security' => array( 'Security', array() ),
			'setup'    => array( 'Setup', array() ),
			'help'     => array( 'Help', array() ),
		);
		$icons = array( 'registrations' => '📝', 'reports' => '🗂️', 'outreach' => '🎉', 'lessons' => '🚗', 'driveflow' => '📅', 'texts' => '💬', 'attendance' => '🗓️', 'sms' => '📲', 'refunds' => '↩️', 'notices' => '📣', 'feed' => '🌐', 'mva' => '📰', 'prices' => '🏷️', 'discounts' => '％', 'blocklist' => '🛡️', 'api' => '🔑', 'settings' => '⚙️', 'email' => '✉️', 'team' => '👥', 'updates' => '✨', 'tours' => '🧭' );
		$move  = array( 'lessons' => 'lessons', 'driveflow' => 'lessons', 'attendance' => 'lessons', 'texts' => 'lessons', 'sms' => 'lessons', 'reports' => 'reports' );
		if ( class_exists( 'SDSC_Access' ) ) {
			foreach ( SDSC_Access::features() as $k => $f ) {
				if ( ! SDSC_Access::can( $k ) ) { continue; }
				$g   = $move[ $k ] ?? ( isset( $groups[ $f[1] ] ) ? $f[1] : 'help' );
				$url = false !== strpos( $f[3], '.php' ) ? admin_url( $f[3] ) : admin_url( 'admin.php?page=' . $f[3] );
				if ( 'attendance' === $k && class_exists( 'SDSC_ZoomRoll' ) ) { $url = SDSC_ZoomRoll::url(); }
				$groups[ $g ][1][] = array( $icons[ $k ] ?? '•', 'reports' === $k ? 'Student records (print)' : $f[0], $f[4], $url );
			}
		}
		if ( class_exists( 'SDSC_Reports' ) && SDSC_Reports::mine() ) {
			array_unshift( $groups['reports'][1], array( '📊', 'Reports & printing', 'Rosters, attendance, registrations, payments, lessons and fees — print or download.', SDSC_Reports::url() ) );
		}
		if ( class_exists( 'SDSC_Access' ) && SDSC_Access::may_edit_classes() ) {
			$groups['students'][1][] = array( '🏫', 'Classes', 'Add or edit a class: dates, price, Zoom link, timetable and instructor.', admin_url( 'edit.php?post_type=sdsc_class' ) );
		}
		return array_filter( $groups, function ( $g ) { return (bool) $g[1]; } );
	}

	protected static function tools_html() {
		$groups = self::tools();
		if ( ! $groups ) { return ''; }
		$h = '<section class="sov-tools"><h4 class="sov-tools-h">Your tools</h4><div class="sov-tools-grid">';
		foreach ( $groups as $g ) {
			$h .= '<div class="sov-group"><p class="sov-group-h">' . esc_html( $g[0] ) . '</p>';
			foreach ( $g[1] as $t ) {
				$h .= '<div class="sov-tool-w"><a class="sov-tool" href="' . esc_url( $t[3] ) . '"><span class="sov-tool-ic" aria-hidden="true">' . esc_html( $t[0] ) . '</span><span><b>' . esc_html( $t[1] ) . '</b><small>' . esc_html( $t[2] ) . '</small></span></a>' . ( class_exists( 'SDSC_Pins' ) ? SDSC_Pins::star( $t[3] ) : '' ) . '</div>';
			}
			$h .= '</div>';
		}
		return $h . '</div></section>';
	}

	/* ------------------------------------------------------------ office */

	protected static function office( $k ) {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$money = in_array( $k, array( 'admin', 'manager' ), true );

		$lessons = array();
		if ( class_exists( 'SDSC_Lessons' ) && self::exists( SDSC_Lessons::t_lessons() ) ) {
			$lessons = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . " WHERE starts_at >= %s AND starts_at < %s AND status IN ('booked','done')" . ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview() ? '' : " AND email NOT LIKE '%%@preview.samsdrivingschool.invalid'" ) . ' ORDER BY starts_at', $today . ' 00:00:00', $today . ' 23:59:59' ), ARRAY_A ); // phpcs:ignore
		}
		$classes = class_exists( 'SDSC_ZoomRoll' ) ? SDSC_ZoomRoll::classes() : array();
		$zoom    = $classes ? self::zoom_today( $classes ) : array();
		$missing = array();
		foreach ( $classes as $c ) { foreach ( SDSC_ZoomRoll::missing( $c->ID ) as $x ) { $missing[] = array( $c->ID, $x ); } }

		$reg_t  = class_exists( 'SDSC_Registration' ) ? SDSC_Registration::table() : '';
		$recent = $reg_t ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, class_id, first_name, last_name, email, created_at FROM $reg_t WHERE created_at >= %s AND source <> 'demo' ORDER BY id DESC LIMIT 6", wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), ARRAY_A ) : array(); // phpcs:ignore
		$new7   = $reg_t ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $reg_t WHERE created_at >= %s AND source <> 'demo'", wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ) : 0; // phpcs:ignore

		$paid30 = 0; $mode = '';
		if ( class_exists( 'SDSC_Pay' ) && self::exists( SDSC_Pay::table() ) ) {
			$mode   = class_exists( 'SDSC_Api' ) ? SDSC_Api::mode() : 'live';
			$paid30 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . SDSC_Pay::table() . " WHERE status = 'paid' AND mode = %s AND paid_at >= %s", $mode, wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		}
		$fees = ( class_exists( 'SDSC_Lessons' ) && self::exists( SDSC_Lessons::t_fees() ) ) ? $wpdb->get_row( 'SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM ' . SDSC_Lessons::t_fees() . " WHERE status = 'due'", ARRAY_A ) : array( 'n' => 0, 's' => 0 ); // phpcs:ignore

		$h  = '<div class="sov-tiles">';
		$h .= self::tile( count( $lessons ), 'Driving lessons today', '', class_exists( 'SDSC_DriveFlow' ) && SDSC_DriveFlow::on() ? SDSC_DriveFlow::calendar_url() : self::admin( 'sdsc-lessons' ) );
		$h .= self::tile( count( $zoom ), 'Zoom sessions today', '', class_exists( 'SDSC_ZoomRoll' ) ? SDSC_ZoomRoll::url() : '' );
		$h .= self::tile( count( $missing ), 'Attendance sheets to submit', $missing ? 'is-warn' : 'is-ok', class_exists( 'SDSC_ZoomRoll' ) ? SDSC_ZoomRoll::url() : '' );
		$h .= self::tile( $new7, 'New registrations (7 days)', '', self::admin( 'sdsc-registrations' ) );
		if ( $money ) { $h .= self::tile( self::money( $paid30 / 100 ), 'Paid online (30 days)' . ( 'test' === $mode ? ' · test' : '' ), 'is-ok', self::admin( 'sdsc-refunds' ) ); }
		$rv = ( class_exists( 'SDSC_Lessons' ) && self::exists( SDSC_Lessons::t_fees() ) ) ? SDSC_Lessons::count_fees( 'review' ) : 0;
		if ( $rv ) { $h .= self::tile( $rv, 'Lesson fees to decide', 'is-warn', add_query_arg( array( 'page' => 'sdsc-lessons', 'tab' => 'fees', 'st' => 'review' ), admin_url( 'admin.php' ) ) ); }
		$h .= self::tile( (int) $fees['n'], 'Fees due' . ( $fees['n'] ? ' · ' . self::money( $fees['s'] ) : '' ), $fees['n'] ? 'is-warn' : '', self::admin( 'sdsc-lessons' ) );
		$h .= '</div>';
		if ( class_exists( 'SDSC_Onboard' ) && in_array( $k, array( 'admin', 'manager' ), true ) ) { $h .= SDSC_Onboard::card(); }
		$h .= '<div class="sov-grid">';

		// Today: lessons + Zoom.
		$t = self::lesson_rows( $lessons, 'student' );
		foreach ( $zoom as $z ) {
			$t .= '<ul class="sov-list"><li><span class="sov-when">' . esc_html( wp_date( 'g:i A', SDSC_Lessons::ts( $z[1]['start'] ) ) ) . '<small>Zoom</small></span><span class="sov-what"><b>' . esc_html( SDSC_Schedule::class_name( $z[0], true ) ) . '</b><small>' . esc_html( 'Session ' . $z[1]['n'] . ' · ' . ( class_exists( 'SDSC_ZoomRoll' ) && SDSC_ZoomRoll::instructor_of( $z[0] ) ? get_the_author_meta( 'display_name', SDSC_ZoomRoll::instructor_of( $z[0] ) ) : 'no instructor assigned' ) ) . '</small></span><a class="sov-btn" href="' . esc_url( SDSC_ZoomRoll::url( array( 'zc' => $z[0], 'zs' => $z[1]['date'] ) ) ) . '">Attendance</a></li></ul>';
		}
		$h .= self::card( 'Today', $t ? $t : self::empty_note( 'Nothing scheduled for today.' ) );

		// Sheets to submit.
		if ( $missing ) {
			$m = '<ul class="sov-list">';
			foreach ( array_slice( $missing, 0, 6 ) as $x ) {
				$m .= '<li><span class="sov-what"><b>' . esc_html( SDSC_Schedule::class_name( $x[0], true ) ) . '</b><small>' . esc_html( 'Session ' . $x[1]['n'] . ' · ' . wp_date( 'D M j', SDSC_Lessons::ts( $x[1]['start'] ) ) ) . '</small></span><a class="sov-btn sov-btn--ghost" href="' . esc_url( SDSC_ZoomRoll::url( array( 'zc' => $x[0], 'zs' => $x[1]['date'] ) ) ) . '">Open</a></li>';
			}
			$h .= self::card( 'Attendance sheets to submit', $m . '</ul>', 'All', SDSC_ZoomRoll::url() );
		}

		// Registrations.
		$r = '';
		if ( $recent ) {
			$r = '<ul class="sov-list">';
			foreach ( $recent as $x ) {
				$paid = class_exists( 'SDSC_Pay' ) && SDSC_Pay::paid_row( (int) $x['id'] );
				$refd = ! $paid && class_exists( 'SDSC_Pay' ) && method_exists( 'SDSC_Pay', 'refunded_row' ) && SDSC_Pay::refunded_row( (int) $x['id'] );
				$r   .= '<li><span class="sov-what"><b>' . esc_html( trim( $x['first_name'] . ' ' . $x['last_name'] ) ) . '</b><small>' . esc_html( ( $x['class_id'] ? SDSC_Schedule::class_name( (int) $x['class_id'], true ) . ' · ' : '' ) . human_time_diff( strtotime( get_gmt_from_date( $x['created_at'] ) . ' UTC' ) ) . ' ago' ) . '</small></span><span class="sov-chip ' . ( $paid ? 'sov-chip--done' : 'sov-chip--due' ) . '">' . ( $paid ? 'Paid' : ( $refd ? 'Refunded' : 'Not paid yet' ) ) . '</span></li>';
			}
			$r .= '</ul>';
		}
		$h .= self::card( 'New registrations', $r ? $r : self::empty_note( 'No registrations in the last 7 days.' ), 'All', self::admin( 'sdsc-registrations' ) );

		// Open classes.
		$o = '';
		if ( class_exists( 'SDSC_Registration' ) ) {
			foreach ( array_slice( SDSC_Registration::open_classes(), 0, 6 ) as $c ) {
				$cap   = (int) get_post_meta( $c->ID, '_sdsc_capacity', true );
				$taken = class_exists( 'SDSC_Class_Feed' ) ? (int) SDSC_Class_Feed::seats_taken( $c->ID ) : 0;
				$st    = substr( (string) get_post_meta( $c->ID, '_sdsc_start', true ), 0, 10 );
				$pct   = $cap > 0 ? min( 100, (int) round( $taken / $cap * 100 ) ) : 0;
				$o    .= '<li><span class="sov-what"><b>' . esc_html( SDSC_Schedule::class_name( $c->ID, true ) ) . '</b><small>' . esc_html( ( $st ? 'Starts ' . wp_date( 'M j', strtotime( $st . ' 12:00' ) ) : 'No start date' ) . ' · ' . ( $cap ? $taken . ' of ' . $cap . ' seats' : $taken . ' registered' ) ) . '</small>' . ( $cap ? '<i class="sov-bar"><i style="width:' . $pct . '%"></i></i>' : '' ) . '</span>' . ( SDSC_Schedule::sessions( $c->ID ) ? '' : '<span class="sov-chip sov-chip--due" title="Fill in Timetable &amp; calendar on the class">No timetable</span>' ) . '</li>';
			}
		}
		$h .= self::card( 'Open classes', $o ? '<ul class="sov-list">' . $o . '</ul>' : self::empty_note( 'No classes are open for registration.' ), SDSC_Access::may_edit_classes() ? 'Edit classes' : '', SDSC_Access::may_edit_classes() ? admin_url( 'edit.php?post_type=sdsc_class' ) : '' );
		if ( class_exists( 'SDSC_Registration' ) && method_exists( 'SDSC_Registration', 'office_link' ) ) { $h .= self::office_link_card(); }
		$h .= '</div>';

		$h .= self::tools_html();
		if ( class_exists( 'SDSC_PriceWatch' ) && SDSC_PriceWatch::can() ) { $h .= SDSC_PriceWatch::dash_card(); }
		return $h;
	}

	/**
	 * Office registration link (cash / check / Zelle): the student scans the QR code or gets a text, fills in the
	 * registration form on their own phone, and the office records the payment on the Class board.
	 */
	protected static function office_link_card() {
		$links = array( '' => SDSC_Registration::office_link( 0 ) );
		foreach ( array_slice( SDSC_Registration::open_classes(), 0, 8 ) as $c ) { $links[ (string) $c->ID ] = SDSC_Registration::office_link( $c->ID ); }
		$sel = '<select id="sov-ol-c" class="sov-ol-sel"><option value="">Any open class (the student picks)</option>';
		foreach ( SDSC_Registration::open_classes() as $c ) { if ( isset( $links[ (string) $c->ID ] ) ) { $sel .= '<option value="' . (int) $c->ID . '">' . esc_html( SDSC_Schedule::class_name( $c->ID, true ) ) . '</option>'; } }
		$sel .= '</select>';
		$msg  = isset( $_GET['sov_ol'] ) ? sanitize_text_field( wp_unslash( $_GET['sov_ol'] ) ) : ''; // phpcs:ignore
		$b    = '<div class="sov-ol"><p class="sov-ol-lead">For students who pay <b>cash, check or Zelle</b>: they scan the code or open the link, fill in and sign the registration form on their own phone, and skip online payment. Then record the payment on the Class board.</p>'
			. '<div class="sov-ol-row"><div class="sov-ol-qr" id="sov-ol-qr" aria-label="QR code of the registration link"></div><div class="sov-ol-side">'
			. '<label class="sov-ol-l">Class ' . $sel . '</label>'
			. '<label class="sov-ol-l">Link <span class="sov-ol-link"><input type="text" id="sov-ol-url" readonly value="' . esc_attr( $links[''] ) . '"> <button type="button" class="sov-ol-copy" id="sov-ol-copy">Copy</button></span></label>'
			. '<p class="sov-ol-small"><a id="sov-ol-open" href="' . esc_url( $links[''] ) . '" target="_blank" rel="noopener">Open the form</a> · <a id="sov-ol-print" href="#" >Print the QR code</a></p>'
			. '</div></div>'
			. ( $msg ? '<p class="sov-ol-msg" role="status">' . esc_html( $msg ) . '</p>' : '' )
			. '<form class="sov-ol-text" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'sdsc_office_link_text', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_office_link_text"><input type="hidden" name="c" id="sov-ol-cid" value="">'
			. '<label class="sov-ol-l">Text the link to <input type="tel" name="phone" placeholder="Student or parent mobile" required inputmode="tel"></label> <input type="text" name="who" maxlength="60" placeholder="Student’s first name (optional)"> <button class="sov-ol-send">💬 Send text</button></form>'
			. '</div>'
			. '<script src="' . esc_url( SDSC_URL . 'assets/vendor/qrcode.min.js?ver=1.4.4' ) . '"></script>'
			. '<script>(function(){var L=' . wp_json_encode( $links ) . ',s=document.getElementById("sov-ol-c"),u=document.getElementById("sov-ol-url"),o=document.getElementById("sov-ol-open"),q=document.getElementById("sov-ol-qr"),cid=document.getElementById("sov-ol-cid");'
			. 'function draw(){var v=L[s.value]||L[""];u.value=v;o.href=v;cid.value=s.value;try{var c=qrcode(0,"M");c.addData(v);c.make();q.innerHTML=c.createImgTag(5,8);}catch(e){q.textContent=v;}}'
			. 's.addEventListener("change",draw);draw();'
			. 'document.getElementById("sov-ol-copy").addEventListener("click",function(){u.select();try{navigator.clipboard.writeText(u.value);}catch(e){document.execCommand("copy");}this.textContent="Copied";var b=this;setTimeout(function(){b.textContent="Copy";},1500);});'
			. 'document.getElementById("sov-ol-print").addEventListener("click",function(e){e.preventDefault();var w=window.open("","_blank");if(!w)return;w.document.write("<!doctype html><title>Registration — Sam\u2019s Driving School</title><body style=\"font-family:Arial,sans-serif;text-align:center;padding:40px\"><h1 style=\"color:#00703c\">Register for Driver Education</h1><p style=\"font-size:18px\">Scan with your phone camera, fill in the form and sign.<br>Pay at the office — cash, check or Zelle.</p><div style=\"margin:24px auto;transform:scale(1.8);transform-origin:top center;height:260px\">"+q.innerHTML+"</div><p style=\"font-size:16px;word-break:break-all\">"+u.value+"</p><p style=\"color:#5b646d\">"+(s.options[s.selectedIndex].text)+"</p></body>");w.document.close();setTimeout(function(){w.print();},300);});'
			. '})();</script>';
		return self::card( 'Register at the office (cash / check)', $b );
	}

	/** Text the office registration link to a student or parent (button on the dashboard card). */
	public static function h_office_link_text() {
		if ( ! in_array( self::kind(), array( 'admin', 'manager', 'frontdesk' ), true ) ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_office_link_text' );
		$cid   = absint( $_POST['c'] ?? 0 ); // phpcs:ignore
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ); // phpcs:ignore
		$who   = sanitize_text_field( wp_unslash( $_POST['who'] ?? '' ) ); // phpcs:ignore
		$back  = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$back  = remove_query_arg( 'sov_ol', $back );
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 10 ) { wp_safe_redirect( add_query_arg( 'sov_ol', rawurlencode( 'Please type a valid mobile number.' ), $back ) ); exit; }
		$link = SDSC_Registration::office_link( $cid );
		$cls  = $cid ? SDSC_Schedule::class_name( $cid ) : 'Driver Education';
		$body = "Sam's Driving School: " . ( $who ? $who . ', ' : '' ) . 'here is the registration form for ' . $cls . ': ' . $link . ' — fill it in and sign on your phone; payment is at the office (cash, check or Zelle). Questions? Call or text (301) 726-3030.';
		$res  = class_exists( 'SDSC_SMS' ) ? SDSC_SMS::deliver( $phone, $body, 'office-link' . ( $cid ? ':' . $cid : '' ), $who, 'Registration link' ) : 'Texts are not set up.';
		wp_safe_redirect( add_query_arg( 'sov_ol', rawurlencode( 'Text: ' . $res ), $back ) );
		exit;
	}

	/* ------------------------------------------------------------ instructor */

	/** The name lessons use for this instructor: the DriveFlow profile linked by user id or e-mail, else the display name. */
	public static function instructor_name( $u ) {
		global $wpdb;
		$dft = $wpdb->prefix . 'driveflow_instructors';
		if ( self::exists( $dft ) ) {
			$n = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $dft WHERE user_id = %d OR email = %s ORDER BY user_id = %d DESC LIMIT 1", $u->ID, $u->user_email, $u->ID ) ); // phpcs:ignore
			if ( $n ) { return $n; }
		}
		return $u->display_name;
	}

	protected static function instructor( $u ) {
		global $wpdb;
		$classes = class_exists( 'SDSC_ZoomRoll' ) ? SDSC_ZoomRoll::classes() : array();
		$zoom    = $classes ? self::zoom_today( $classes ) : array();
		$missing = array();
		foreach ( $classes as $c ) { foreach ( SDSC_ZoomRoll::missing( $c->ID ) as $x ) { $missing[] = array( $c->ID, $x ); } }

		$name = self::instructor_name( $u );
		$lessons = array();
		if ( class_exists( 'SDSC_Lessons' ) && self::exists( SDSC_Lessons::t_lessons() ) ) {
			$lessons = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . " WHERE instructor = %s AND status = 'booked' AND starts_at >= %s AND starts_at < %s ORDER BY starts_at LIMIT 12", $name, current_time( 'Y-m-d' ) . ' 00:00:00', wp_date( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) . ' 23:59:59' ), ARRAY_A ); // phpcs:ignore
		}
		$today_l = array_filter( $lessons, function ( $l ) { return 0 === strpos( $l['starts_at'], current_time( 'Y-m-d' ) ); } );

		$h  = '<div class="sov-tiles">';
		$h .= self::tile( count( $zoom ), 'Zoom sessions today', '', SDSC_ZoomRoll::url() );
		$h .= self::tile( count( $missing ), 'Attendance sheets to submit', $missing ? 'is-warn' : 'is-ok', SDSC_ZoomRoll::url() );
		$h .= self::tile( count( $today_l ), 'Driving lessons today' );
		$h .= self::tile( count( $classes ), 'My Zoom classes' );
		$h .= '</div><div class="sov-grid">';

		$z = '';
		foreach ( $zoom as $x ) {
			$link = (string) get_post_meta( $x[0], '_sdsc_zoom_link', true );
			$z   .= '<li><span class="sov-when">' . esc_html( wp_date( 'g:i A', SDSC_Lessons::ts( $x[1]['start'] ) ) ) . '<small>Zoom</small></span><span class="sov-what"><b>' . esc_html( SDSC_Schedule::class_name( $x[0], true ) ) . '</b><small>' . esc_html( 'Session ' . $x[1]['n'] . ' of ' . count( SDSC_Schedule::sessions( $x[0] ) ) . ' · ' . count( SDSC_Schedule::roster( $x[0] ) ) . ' students' ) . '</small></span>'
				. ( $link ? '<a class="sov-btn sov-btn--ghost" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">Open Zoom</a>' : '' )
				. '<a class="sov-btn" href="' . esc_url( SDSC_ZoomRoll::url( array( 'zc' => $x[0], 'zs' => $x[1]['date'] ) ) ) . '">Take attendance</a></li>';
		}
		$h .= self::card( 'My Zoom classes today', $z ? '<ul class="sov-list">' . $z . '</ul>' : self::empty_note( $classes ? 'No Zoom session today.' : 'No Zoom class is assigned to you yet.' ), 'Attendance', SDSC_ZoomRoll::url() );

		if ( $missing ) {
			$m = '<ul class="sov-list">';
			foreach ( array_slice( $missing, 0, 6 ) as $x ) {
				$m .= '<li><span class="sov-what"><b>' . esc_html( SDSC_Schedule::class_name( $x[0], true ) ) . '</b><small>' . esc_html( 'Session ' . $x[1]['n'] . ' · ' . wp_date( 'D M j', SDSC_Lessons::ts( $x[1]['start'] ) ) ) . '</small></span><a class="sov-btn sov-btn--ghost" href="' . esc_url( SDSC_ZoomRoll::url( array( 'zc' => $x[0], 'zs' => $x[1]['date'] ) ) ) . '">Submit</a></li>';
			}
			$h .= self::card( 'Attendance sheets to submit', $m . '</ul>' );
		}
		$h .= self::card( 'My driving lessons (next 7 days)', $lessons ? self::lesson_rows( $lessons, 'student' ) : self::empty_note( 'No driving lessons booked with you this week.' ) );
		$h .= '</div>';

		$h  .= self::tools_html();
		$app = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => '[driveflow_instructor_form', 'numberposts' => 1 ) );
		$h  .= self::links( array(
			array( 'Zoom attendance', SDSC_ZoomRoll::url() ),
			$app ? array( 'In-car lesson app', get_permalink( $app[0] ) ) : null,
			array( 'Call the office', 'tel:+13017263030' ),
		) );
		return $h;
	}

	/* ------------------------------------------------------------ student */

	protected static function student( $u ) {
		global $wpdb;
		$email = strtolower( $u->user_email );
		$h     = '';
		$cards = '';

		// Classes.
		$classes = class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::classes_of( $email ) : array();
		foreach ( $classes as $cid ) {
			$list = SDSC_Schedule::sessions( $cid );
			$next = null;
			foreach ( $list as $x ) { if ( SDSC_Lessons::ts( $x['end'] ) > time() ) { $next = $x; break; } }
			$reg  = class_exists( 'SDSC_Registration' ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . SDSC_Registration::table() . ' WHERE class_id = %d AND email = %s ORDER BY id DESC LIMIT 1', $cid, $email ), ARRAY_A ) : null; // phpcs:ignore
			$paid = $reg && class_exists( 'SDSC_Pay' ) ? SDSC_Pay::paid_row( (int) $reg['id'] ) : null;
			$pay  = $reg && ! $paid && class_exists( 'SDSC_Pay' ) ? SDSC_Pay::url( (int) $reg['id'], $cid ) : '';
			$zoom = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
			$b    = '<p class="sov-line"><b>' . esc_html( SDSC_Schedule::class_name( $cid, true ) ) . '</b> '
				. ( $paid ? '<span class="sov-chip sov-chip--done">Paid ' . esc_html( '$' . number_format( $paid['amount'] / 100, 2 ) ) . '</span>' : ( $pay ? '<span class="sov-chip sov-chip--due">Payment due</span>' : '' ) ) . '</p>'
				. '<p class="sov-sub">' . ( $next ? esc_html( 'Next: session ' . $next['n'] . ' — ' . SDSC_Lessons::when( $next['start'], 'en' ) ) : ( $list ? 'All sessions are done.' : 'The class schedule will appear here soon.' ) ) . '</p>'
				. ( $list && class_exists( 'SDSC_ZoomRoll' ) ? SDSC_ZoomRoll::student_strip( $cid, $email, 'en' ) : '' );
			$act  = array();
			if ( $pay ) { $act[] = '<a class="sov-btn" href="' . esc_url( $pay ) . '">Pay now</a>'; }
			if ( $next && $zoom ) { $act[] = '<a class="sov-btn sov-btn--ghost" href="' . esc_url( $zoom ) . '" target="_blank" rel="noopener">Zoom link</a>'; }
			if ( $next ) {
				$a     = SDSC_Schedule::att_url( 'zoom', $cid, $next['date'], $email );
				$act[] = '<a class="sov-btn sov-btn--ghost" href="' . esc_url( add_query_arg( 'a', 'yes', $a ) ) . '">I’ll be there</a>';
				$act[] = '<a class="sov-btn sov-btn--ghost" href="' . esc_url( add_query_arg( 'a', 'no', $a ) ) . '">I can’t make it</a>';
			}
			$cards .= self::card( 'My class', $b . ( $act ? '<p class="sov-actions">' . implode( '', $act ) . '</p>' : '' ) );
		}

		// Driving lessons + fees.
		$lessons = array();
		$fees    = array( 'n' => 0, 's' => 0 );
		if ( class_exists( 'SDSC_Lessons' ) && self::exists( SDSC_Lessons::t_lessons() ) ) {
			$lessons = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . " WHERE email = %s AND status = 'booked' AND starts_at >= %s ORDER BY starts_at LIMIT 3", $email, current_time( 'mysql' ) ), ARRAY_A ); // phpcs:ignore
			$fees    = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM ' . SDSC_Lessons::t_fees() . " WHERE email = %s AND status = 'due'", $email ), ARRAY_A ); // phpcs:ignore
		}
		$my = class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::my_url() : '';
		$cards .= self::card( 'My driving lessons', $lessons ? self::lesson_rows( $lessons, 'mine' ) : self::empty_note( 'No upcoming driving lessons. Call (301) 726-3030 to book one.' ), 'All lessons', $my );

		$h .= '<div class="sov-tiles">';
		$h .= self::tile( count( $classes ), 'My classes' );
		$h .= self::tile( count( $lessons ) ? wp_date( 'M j', SDSC_Lessons::ts( $lessons[0]['starts_at'] ) ) : '—', 'Next driving lesson', '', $my );
		$h .= self::tile( (int) $fees['n'] ? self::money( $fees['s'] ) : '$0', 'Fees due', (int) $fees['n'] ? 'is-warn' : 'is-ok', $my );
		$h .= '</div>';
		if ( ! $classes && ! $lessons ) {
			$cards = self::card( 'Welcome to Sam’s Driving School', '<p class="sov-sub">You are not registered for a class yet.</p><p class="sov-actions"><a class="sov-btn" href="' . esc_url( home_url( '/register-course/' ) ) . '">Register for a class</a><a class="sov-btn sov-btn--ghost" href="' . esc_url( home_url( '/services/' ) ) . '">See all services</a></p>' ) . $cards;
		}
		$h .= '<div class="sov-grid">' . $cards . '</div>';
		$h .= self::links( array(
			$my ? array( 'My driving lessons', $my ) : null,
			array( 'Add my classes to my calendar', class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::cal_page( $email ) : '' ),
			array( 'School policies', home_url( '/policy/' ) ),
			array( 'Call (301) 726-3030', 'tel:+13017263030' ),
		) );
		return $h;
	}
}
