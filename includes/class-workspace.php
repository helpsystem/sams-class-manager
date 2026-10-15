<?php
/**
 * Staff workspace on the Tutor dashboard.
 *
 * Every management screen the person is allowed to use — DriveFlow (lesson calendar, students and lesson
 * credits, instructors and payouts, cars, evaluations, lesson reports and accounting) and Sam's Class
 * Manager (registrations, BTW lessons and fees, texts, refunds, prices, banners, …) — opens inside the
 * dashboard, so staff never have to go to wp-admin.
 *
 *  - A "Workspace" drop-down at the top of every dashboard page (staff only) with all screens, grouped,
 *    plus quick actions.
 *  - A staff sidebar: the management screens replace the student items (courses, notes, …) for the
 *    school manager and the front desk; instructors and the administrator keep their Tutor course items.
 *  - Students never see any of this; each screen re-checks the same permission the screen itself uses.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Workspace {
	const TAB = 'sdsc-work';

	public static function init() {
		add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'register_tab' ) );
		add_filter( 'tutor_instructor_dashboard_nav', array( __CLASS__, 'register_tab' ) );
		add_filter( 'tutor_dashboard/nav_ui_items', array( __CLASS__, 'staff_nav' ), 50 );
		add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'template' ) );
		add_action( 'tutor_load_dashboard_template_before', array( __CLASS__, 'bar' ) );
		add_filter( 'should_tutor_load_template', array( __CLASS__, 'bar_on_home' ), 5, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_head', array( __CLASS__, 'embedded_admin_css' ) );
	}

	/* ------------------------------------------------------------ who */

	public static function kind() { return class_exists( 'SDSC_Overview' ) ? SDSC_Overview::kind() : ( current_user_can( 'manage_options' ) ? 'admin' : 'student' ); }

	public static function is_staff() { return is_user_logged_in() && 'student' !== self::kind(); }

	protected static function df_cap() {
		return current_user_can( 'manage_options' ) || current_user_can( 'driveflow_manage' );
	}

	/* ------------------------------------------------------------ screens */

	/** key => [ label, group, wp-admin path, allowed?, icon (emoji), one-line description ] */
	public static function screens() {
		static $cache = array();
		$uid = get_current_user_id();
		if ( isset( $cache[ $uid ] ) ) { return $cache[ $uid ]; }
		$df  = class_exists( 'DriveFlow_Pro' ) && self::df_cap();
		$adm = current_user_can( 'manage_options' );
		$can = function ( $k ) { return class_exists( 'SDSC_Access' ) && SDSC_Access::can( $k ); };
		$s   = array(
			// DriveFlow — lessons, people, cars, money.
			'df-hub'         => array( 'Lessons overview', 'lessons', 'admin.php?page=driveflow-pro-hub', $df, '🧭', 'Today’s lessons, live sessions and quick numbers.' ),
			'df-calendar'    => array( 'Lesson calendar', 'lessons', 'admin.php?page=driveflow-pro-calendar', $df, '📅', 'Book, move and cancel driving lessons; see every instructor and car.' ),
			'df-records'     => array( 'Evaluations & records', 'lessons', 'admin.php?page=driveflow-pro-records', $df, '📋', 'Completed lessons, in-car evaluations, signatures and notes.' ),
			'df-students'    => array( 'Students & lesson credits', 'students', 'admin.php?page=driveflow-pro-students', $df, '🎓', 'Student profiles, packages, remaining lessons and booking links.' ),
			'df-instructors' => array( 'Instructors & payouts', 'team', 'admin.php?page=driveflow-pro-instructors', $df, '🧑‍🏫', 'Instructor profiles, onboarding links, pay rates and payouts.' ),
			'df-vehicles'    => array( 'Cars', 'team', 'admin.php?page=driveflow-pro-vehicles', $df, '🚘', 'School cars, plates and availability.' ),
			'df-reports'     => array( 'Lesson accounting', 'money', 'admin.php?page=driveflow-pro-reports', $df, '📈', 'Lessons taught, hours, instructor earnings and payouts by period.' ),
			'df-settings'    => array( 'DriveFlow settings', 'setup', 'admin.php?page=driveflow-pro-settings', $df && $adm, '⚙️', 'Booking rules, e-mails, TV board and pages.' ),
			'offers'         => array( 'Lesson offers', 'lessons', 'admin.php?page=sdsc-lesson-offers', class_exists( 'SDSC_DFOffer' ) && class_exists( 'SDSC_DFBook' ) && SDSC_DFBook::can(), '🔗', 'Times offered to a student by link: who picked, copy, resend, withdraw.' ),
			'instructors'    => array( 'Add & assign instructors', 'team', 'admin.php?page=sdsc-instructors', class_exists( 'SDSC_Instructors' ) && SDSC_Instructors::can(), '🪪', 'Add an instructor by hand, paper agreement, switch on or off.' ),
			'applicants'     => array( 'Instructor applications', 'team', 'admin.php?page=sdsc-applicants', class_exists( 'SDSC_Areas' ) && $can( 'registrations' ), '📨', 'People who applied to teach.' ),
			'board'          => array( 'Class board', 'students', 'admin.php?page=sdsc-board', class_exists( 'SDSC_Board' ) && SDSC_Board::can(), '🧩', 'Seats, instructors and cars for every class.' ),
			'pricewatch'     => array( 'Price watch', 'money', 'admin.php?page=sdsc-price-watch', class_exists( 'SDSC_PriceWatch' ) && SDSC_PriceWatch::can(), '📉', 'Other Maryland schools’ prices next to ours: changes, sales, charts.' ),
			// Sam's Class Manager.
			'classes'        => array( 'Classes', 'students', 'edit.php?post_type=sdsc_class', current_user_can( 'edit_posts' ) && class_exists( 'SDSC_Access' ) && SDSC_Access::is_staff(), '🏫', 'Add or edit a class: dates, price, Zoom link, timetable and instructor.' ),
			'registrations'  => array( 'Registrations', 'students', 'admin.php?page=sdsc-registrations', $can( 'registrations' ), '📝', 'Sign-ups, contracts and signatures.' ),
			'records'        => array( 'Student records (print)', 'students', 'admin.php?page=sdsc-reports', $can( 'reports' ), '🗂️', 'Full student records to print and file.' ),
			'btw'            => array( 'BTW lessons & fees', 'lessons', 'admin.php?page=sdsc-lessons', $can( 'lessons' ), '🚗', 'Lesson confirmations, late-cancellation and no-show fees, waivers.' ),
			'attendance'     => array( 'Class attendance (sheet)', 'lessons', 'admin.php?page=sdsc-attendance', $can( 'attendance' ) && in_array( self::kind(), array( 'admin', 'manager', 'frontdesk' ), true ), '🗓️', 'The whole attendance sheet for a class.' ),
			'texts'          => array( 'Texts to send', 'talk', 'admin.php?page=sdsc-texts', $can( 'texts' ), '💬', 'Reminders and confirmations to text, one tap each.' ),
			'sms'            => array( 'Text messages log', 'talk', 'admin.php?page=sdsc-sms', $can( 'sms' ), '📲', 'Texts sent through Twilio and their delivery.' ),
			'outreach'       => array( 'Celebrations & campaigns', 'talk', 'admin.php?page=sdsc-outreach', $can( 'outreach' ), '🎉', 'Birthday wishes, anniversaries, group e-mails and texts.' ),
			'refunds'        => array( 'Refunds', 'money', 'admin.php?page=sdsc-refunds', $can( 'refunds' ), '↩️', 'Full or prorated refunds by the refund policy.' ),
			'prices'         => array( 'Prices', 'money', 'admin.php?page=sdsc-prices', $can( 'prices' ), '🏷️', 'Course and service prices used at checkout.' ),
			'discounts'      => array( 'Site-wide sale', 'money', 'admin.php?page=sdsc-discounts', $can( 'discounts' ), '％', 'A percentage off a service for everyone, with a preview.' ),
			'promo'          => array( 'Discounts & offers', 'money', 'admin.php?page=sdsc-promo', $can( 'promo' ), '🎟️', 'Codes, early bird, referral codes and results.' ),
			'tv'             => array( 'Lobby TV', 'website', 'admin.php?page=sdsc-tv', $can( 'tv' ), '📺', 'What the lobby TV shows: messages, ticker, view.' ),
			'notices'        => array( 'Banners & alerts', 'website', 'admin.php?page=sdsc-notices', $can( 'notices' ), '📣', 'Site banners, pop-ups, holidays and weather alerts.' ),
			'feed'           => array( 'Website listing', 'website', 'admin.php?page=sdsc-feed', $can( 'feed' ), '🌐', 'Which classes show on the website and how.' ),
			'mva'            => array( 'MVA headlines', 'website', 'admin.php?page=sdsc-mva', $can( 'mva' ), '📰', 'Daily MVA news; turn one into an article.' ),
			'blocklist'      => array( 'Blocked sign-ups', 'setup', 'admin.php?page=sdsc-blocklist', $can( 'blocklist' ), '🛡️', 'Spam e-mails and domains that may not register.' ),
			'api'            => array( 'API connections', 'setup', 'admin.php?page=sdsc-api', $can( 'api' ), '🔑', 'Stripe, Zoom and Twilio keys.' ),
			'email'          => array( 'E-mail', 'setup', 'admin.php?page=sdsc-email', $adm, '✉️', 'Mailboxes, delivery checks and the send log.' ),
			'team'           => array( 'Team access', 'setup', 'admin.php?page=sdsc-team', $adm, '👥', 'Who can open which screen.' ),
			'settings'       => array( 'Settings', 'setup', 'admin.php?page=sdsc-settings', $adm, '⚙️', 'General Class Manager settings.' ),
			'updates'        => array( 'What’s new', 'help', 'admin.php?page=sdsc-updates', $can( 'updates' ), '✨', 'Explanations of every change.' ),
			'tours'          => array( 'Guided tours', 'help', 'admin.php?page=sdsc-tours', $can( 'tours' ), '🧭', 'Step-by-step walkthroughs of every screen.' ),
		);
		$cache[ $uid ] = $s;
		return $s;
	}

	public static function groups() {
		return array(
			'lessons'  => 'Lessons & attendance',
			'students' => 'Students & classes',
			'team'     => 'Instructors & cars',
			'money'    => 'Money & accounting',
			'talk'     => 'Messages',
			'reports'  => 'Reports',
			'website'  => 'Website',
			'setup'    => 'Setup',
			'help'     => 'Help',
		);
	}

	/** Allowed screens + dashboard pages, grouped: group => [ [icon, label, url, desc], … ] */
	public static function menu() {
		$out = array();
		foreach ( self::groups() as $g => $l ) { $out[ $g ] = array(); }
		if ( class_exists( 'SDSC_ZoomRoll' ) && SDSC_ZoomRoll::allowed() ) {
			$out['lessons'][] = array( '✅', 'Zoom attendance', SDSC_ZoomRoll::url(), 'Take attendance for today’s Zoom class.' );
		}
		if ( class_exists( 'SDSC_Docs' ) && SDSC_Docs::is_office() ) {
			$n = SDSC_Docs::pending();
			$out['students'][] = array( '📎', 'Student documents' . ( $n ? ' (' . $n . ' to check)' : '' ), SDSC_Docs::url(), 'ID cards, permits, practice logs: scan, photograph, check.' );
		}
		if ( 'instructor' === self::kind() ) {
			$app = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => '[driveflow_instructor_form', 'numberposts' => 1 ) );
			if ( $app ) { $out['lessons'][] = array( '📱', 'In-car lesson app', get_permalink( $app[0] ), 'Start a lesson, evaluate, sign.' ); }
		}
		foreach ( self::screens() as $k => $s ) {
			if ( ! $s[3] ) { continue; }
			if ( 'records' === $k && class_exists( 'SDSC_Student_File' ) && class_exists( 'SDSC_Reports' ) && SDSC_Reports::can( 'file' ) ) {
				$out[ $s[1] ][] = array( '🗂️', 'Student files (print)', SDSC_Reports::url( array( 'r' => 'file' ) ), 'Registration form, test answer sheet and attendance for each student.' );
				continue;
			}
			$out[ $s[1] ][] = array( $s[4], $s[0], self::url( $k ), $s[5] );
		}
		if ( class_exists( 'SDSC_Reports' ) && SDSC_Reports::mine() ) {
			array_unshift( $out['reports'], array( '📊', 'Reports & printing', SDSC_Reports::url(), 'Rosters, attendance, registrations, payments, lessons, fees.' ) );
		}
		return array_filter( $out );
	}

	public static function url( $key ) {
		$base = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( self::TAB ) : home_url( '/dashboard/' . self::TAB . '/' );
		return add_query_arg( 'screen', $key, $base );
	}

	/* ------------------------------------------------------------ Tutor wiring */

	/** Always register the tab (Tutor builds its URL rules from this list); access is checked on the page. */
	public static function register_tab( $items ) {
		if ( ! is_array( $items ) ) { return $items; }
		$items[ self::TAB ] = array( 'title' => 'Workspace', 'icon' => 'menu', 'show_ui' => false );
		return $items;
	}

	/** Staff sidebar: management first; school manager and front desk lose the student items. */
	public static function staff_nav( $items ) {
		if ( ! is_array( $items ) || ! self::is_staff() ) { return $items; }
		$k     = self::kind();
		$s     = self::screens();
		$front = array();
		$front['index'] = $items['index'] ?? array( 'title' => 'Home', 'icon' => 'home', 'active_icon' => 'home-fill' );
		$pick  = array(
			'df-calendar' => array( 'Lesson calendar', 'calendar-2' ),
			'df-students' => array( 'Students & credits', 'user' ),
			'registrations' => array( 'Registrations', 'notes' ),
			'df-instructors' => array( 'Instructors & payouts', 'user-circle' ),
			'df-reports'  => array( 'Lesson accounting', 'wallet' ),
		);
		foreach ( $pick as $key => $p ) {
			if ( ! empty( $s[ $key ][3] ) ) { $front[ 'sdsc-w-' . $key ] = array( 'title' => $p[0], 'icon' => $p[1], 'url' => self::url( $key ) ); }
		}
		foreach ( array( 'sdsc-documents', 'sdsc-messages', 'sdsc-zoom-attendance', 'sdsc-reports' ) as $key ) { if ( isset( $items[ $key ] ) ) { $front[ $key ] = $items[ $key ]; } }
		$student_only = array( 'courses', 'enrolled-courses', 'notes', 'calendar', 'sdsc-lessons', 'wishlist', 'reviews', 'my-quiz-attempts', 'purchase_history', 'order-history' );
		foreach ( $items as $key => $v ) {
			if ( isset( $front[ $key ] ) || 'index' === $key ) { continue; }
			if ( in_array( $k, array( 'manager', 'frontdesk' ), true ) && in_array( $key, $student_only, true ) ) { continue; }
			$front[ $key ] = $v;
		}
		return $front;
	}

	public static function template( $tpl ) {
		global $wp_query;
		if ( self::TAB === ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' ) ) { return dirname( __DIR__ ) . '/templates/tutor-workspace.php'; }
		return $tpl;
	}

	public static function assets() {
		if ( ! self::is_staff() ) { return; }
		$id = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) : 0;
		if ( $id && is_page( $id ) ) {
			wp_enqueue_style( 'sdsc-workspace', SDSC_URL . 'assets/workspace.css', array(), SDSC_VERSION . '.' . (int) @filemtime( SDSC_DIR . 'assets/workspace.css' ) );
			wp_enqueue_script( 'sdsc-workspace', SDSC_URL . 'assets/workspace.js', array(), SDSC_VERSION, true );
		}
	}

	/* ------------------------------------------------------------ the drop-down bar */

	protected static $bar = false;

	public static function bar_on_home( $load, $template ) {
		if ( in_array( $template, array( 'dashboard.dashboard', 'dashboard.student.dashboard' ), true ) ) { self::bar(); }
		return $load;
	}

	public static function bar() {
		if ( self::$bar || ! self::is_staff() ) { return; }
		self::$bar = true;
		$menu   = self::menu();
		$groups = self::groups();
		$s      = self::screens();
		echo '<div class="sws-bar"><details class="sws-menu"><summary><span class="sws-burger" aria-hidden="true"></span>Workspace<span class="sws-caret" aria-hidden="true">▾</span></summary><div class="sws-panel" role="menu">';
		foreach ( $menu as $g => $items ) {
			echo '<div class="sws-col"><p class="sws-h">' . esc_html( $groups[ $g ] ) . '</p>';
			foreach ( $items as $i ) {
				echo '<a role="menuitem" href="' . esc_url( $i[2] ) . '"><span class="sws-ic" aria-hidden="true">' . esc_html( $i[0] ) . '</span><span><b>' . esc_html( $i[1] ) . '</b><small>' . esc_html( $i[3] ) . '</small></span></a>';
			}
			echo '</div>';
		}
		echo '</div></details>';
		if ( class_exists( 'SDSC_Pins' ) ) {
			$pq = SDSC_Pins::quick();
			echo SDSC_Pins::assets() . '<nav class="sws-quick sws-pins" aria-label="Pinned screens"' . ( $pq ? '' : ' style="display:none"' ) . '><span class="sws-pin-h" aria-hidden="true">📌</span>' . $pq . '</nav>'; // phpcs:ignore -- escaped inside
		}
		echo '<nav class="sws-quick" aria-label="Quick actions">';
		if ( ! empty( $s['df-calendar'][3] ) ) { echo '<a href="' . esc_url( self::url( 'df-calendar' ) ) . '">＋ Book a lesson</a>'; }
		if ( ! empty( $s['classes'][3] ) ) { echo '<a href="' . esc_url( add_query_arg( 'new', 1, self::url( 'classes' ) ) ) . '">＋ New class</a>'; }
		if ( ! empty( $s['df-students'][3] ) ) { echo '<a href="' . esc_url( self::url( 'df-students' ) ) . '">＋ Student</a>'; }
		if ( class_exists( 'SDSC_ZoomRoll' ) && SDSC_ZoomRoll::allowed() ) { echo '<a href="' . esc_url( SDSC_ZoomRoll::url() ) . '">✅ Attendance</a>'; }
		if ( class_exists( 'SDSC_Reports' ) && SDSC_Reports::mine() ) { echo '<a href="' . esc_url( SDSC_Reports::url() ) . '">📊 Reports</a>'; }
		echo '</nav><span class="sws-role">' . esc_html( array( 'admin' => 'Administrator', 'manager' => 'School manager', 'frontdesk' => 'Front desk', 'instructor' => 'Instructor' )[ self::kind() ] ?? '' ) . ' workspace</span></div>';
	}

	/* ------------------------------------------------------------ a screen inside the dashboard */

	public static function render() {
		if ( ! self::is_staff() ) { return '<p>This page is for the school team.</p>'; }
		$key = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : ''; // phpcs:ignore
		$s   = self::screens()[ $key ] ?? null;
		if ( ! $s ) {
			$h = '<div class="sws-home"><h3>Workspace</h3>';
			foreach ( self::menu() as $g => $items ) {
				$h .= '<p class="sws-h">' . esc_html( self::groups()[ $g ] ) . '</p><div class="sws-cards">';
				foreach ( $items as $i ) { $h .= '<a class="sws-card" href="' . esc_url( $i[2] ) . '"><span class="sws-ic" aria-hidden="true">' . esc_html( $i[0] ) . '</span><span><b>' . esc_html( $i[1] ) . '</b><small>' . esc_html( $i[3] ) . '</small></span></a>'; }
				$h .= '</div>';
			}
			return $h . '</div>';
		}
		if ( ! $s[3] ) { return '<div class="sws-deny"><h3>' . esc_html( $s[0] ) . '</h3><p>You do not have access to this screen. The administrator or the school manager can give it to you under Team access.</p></div>'; }
		$path = $s[2];
		if ( 'classes' === $key && ! empty( $_GET['new'] ) ) { $path = 'post-new.php?post_type=sdsc_class'; } // phpcs:ignore
		$src = add_query_arg( 'sdsc_embed', '1', admin_url( $path ) );
		return '<div class="sws-screen"><div class="sws-screen-head"><div><p class="sws-crumb">' . esc_html( self::groups()[ $s[1] ] ) . '</p><h3><span aria-hidden="true">' . esc_html( $s[4] ) . '</span> ' . esc_html( $s[0] ) . '</h3></div>'
			. '<a class="sws-full" href="' . esc_url( current_user_can( 'manage_options' ) ? admin_url( $path ) : $src ) . '" target="_blank" rel="noopener">Open full screen ↗</a></div>'
			. '<iframe class="sws-frame" title="' . esc_attr( $s[0] ) . '" src="' . esc_url( $src ) . '" loading="eager"></iframe></div>';
	}

	/** Inside the dashboard frame: hide the wp-admin chrome so only the screen shows. */
	public static function embedded_admin_css() {
		if ( class_exists( 'SDSC_Private' ) ? ! SDSC_Private::embedded() : ( empty( $_GET['sdsc_embed'] ) && false === strpos( (string) wp_get_referer(), 'sdsc_embed=1' ) ) ) { return; } // phpcs:ignore
		// Other plugins' banners (reviews, upgrades, promos) do not belong in the dashboard.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		echo '<style id="sdsc-embed">html.wp-toolbar{padding-top:0!important}#wpadminbar,#adminmenumain,#adminmenuback,#adminmenuwrap,#wpfooter,#screen-meta-links,.update-nag,.notice.is-dismissible.hide-in-embed{display:none!important}#wpcontent,#wpfooter{margin-left:0!important}#wpbody-content{padding-bottom:20px!important}.auto-fold #wpcontent{margin-left:0!important}body{background:#fff!important}</style>'
			. '<script>document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[href]");if(!a||a.target==="_blank")return;try{var u=new URL(a.href,location.href);if(u.origin===location.origin&&u.pathname.indexOf("/wp-admin/")!==-1&&!u.searchParams.has("sdsc_embed")){u.searchParams.set("sdsc_embed","1");a.href=u.toString();}}catch(x){}},true);'
			. 'document.addEventListener("submit",function(e){var f=e.target;if(!f.querySelector("input[name=sdsc_embed]")){var i=document.createElement("input");i.type="hidden";i.name="sdsc_embed";i.value="1";f.appendChild(i);}},true);</script>';
	}
}
