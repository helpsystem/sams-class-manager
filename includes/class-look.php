<?php
/**
 * School look for signing in and signing up, and the account menu in the site header.
 *
 *  - Sign-in (/dashboard/ when logged out, /tutor-login/) and the student / instructor sign-up pages get a
 *    two-panel layout: a school panel (what the account is for, help by phone) and the form.
 *  - The header shows "Sign in" for visitors, and for signed-in people an account button with their
 *    name: My dashboard, the pages that matter for their role, Messages (with unread count) and Log out.
 *    The old login / dashboard / logout menu items of the pop-up login plugin are taken out of the menu.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Look {
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'tutor/template/login/before/wrap', array( __CLASS__, 'login_open' ) );
		add_action( 'tutor/template/login/after/wrap', array( __CLASS__, 'login_close' ) );
		add_action( 'tutor_before_student_reg_form', array( __CLASS__, 'student_side' ) );
		add_action( 'tutor_before_instructor_reg_form', array( __CLASS__, 'instructor_side' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'menu_items' ), 20, 2 );
	}

	public static function assets() {
		if ( is_admin() ) { return; }
		wp_enqueue_style( 'sdsc-account', SDSC_URL . 'assets/account.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-account', SDSC_URL . 'assets/account.js', array(), SDSC_VERSION, true );
		if ( is_user_logged_in() ) { return; }
		wp_enqueue_style( 'sdsc-tutor-forms', SDSC_URL . 'assets/tutor-forms.css', array(), SDSC_VERSION );
		$logo = class_exists( 'SDSC_Brand' ) && method_exists( 'SDSC_Brand', 'img' ) ? SDSC_Brand::img( 'sams-logo-stacked.webp' ) : '';
		if ( $logo ) { wp_add_inline_style( 'sdsc-tutor-forms', ':root{--sdsc-form-logo:url("' . esc_url_raw( $logo ) . '")}' ); }
	}

	/* ------------------------------------------------------------ sign-in / sign-up panels */

	protected static function phone() { return '(301) 726-3030'; }

	protected static function icon( $n ) {
		$p = array(
			'car'   => '<path d="M5 11l1.6-4.2A2 2 0 0 1 8.5 5.5h7a2 2 0 0 1 1.9 1.3L19 11m-14 0h14m-14 0a2 2 0 0 0-2 2v3h2m14-5a2 2 0 0 1 2 2v3h-2M5 16v1.5M19 16v1.5M7.5 14h.01M16.5 14h.01" />',
			'cal'   => '<path d="M7 3v3m10-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z" />',
			'card'  => '<path d="M3 7h18M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zm0 4h18M7 15h3" />',
			'chat'  => '<path d="M5 5h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-6l-4 3v-3H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z" />',
			'bell'  => '<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15zM10 20a2 2 0 0 0 4 0" />',
			'star'  => '<path d="M12 4l2.4 5 5.5.6-4.1 3.7 1.2 5.4L12 16l-5 2.7 1.2-5.4L4.1 9.6l5.5-.6z" />',
			'phone' => '<path d="M6.6 10.8a15 15 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.6 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.4 11.4 0 0 0 .6 3.6 1 1 0 0 1-.25 1z" />',
			'shield'=> '<path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z" /><path d="M9 12l2 2 4-4" />',
		);
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $p[ $n ] ?? '' ) . '</svg>';
	}

	protected static function side( $kicker, $title, $lead, $points, $foot = '' ) {
		$logo = class_exists( 'SDSC_Brand' ) && method_exists( 'SDSC_Brand', 'img' ) ? SDSC_Brand::img( 'sams-logo-stacked.webp' ) : '';
		$h  = '<aside class="sdsc-auth-side">';
		$h .= '<div class="sdsc-auth-road" aria-hidden="true"></div>';
		if ( $logo ) { $h .= '<a class="sdsc-auth-logo" href="' . esc_url( home_url( '/' ) ) . '"><img src="' . esc_url( $logo ) . '" alt="Sam’s Driving School" width="120" height="120"></a>'; }
		$h .= '<p class="sdsc-auth-kicker">' . esc_html( $kicker ) . '</p><h2 class="sdsc-auth-title">' . esc_html( $title ) . '</h2><p class="sdsc-auth-lead">' . esc_html( $lead ) . '</p><ul class="sdsc-auth-points">';
		foreach ( $points as $pt ) { $h .= '<li><span class="sdsc-auth-ic">' . self::icon( $pt[0] ) . '</span><span><b>' . esc_html( $pt[1] ) . '</b><small>' . esc_html( $pt[2] ) . '</small></span></li>'; }
		$h .= '</ul>' . $foot;
		$h .= '<a class="sdsc-auth-help" href="tel:+13017263030"><span class="sdsc-auth-ic">' . self::icon( 'phone' ) . '</span><span><small>Need help signing in?</small><b>' . esc_html( self::phone() ) . '</b></span></a>';
		return $h . '</aside>';
	}

	public static function login_open() {
		echo '<div class="sdsc-auth sdsc-auth--login">' . self::side( // phpcs:ignore -- escaped in side()
			'Student & staff portal',
			'Welcome back',
			'Sign in to see your classes, driving lessons and messages in one place.',
			array(
				array( 'cal', 'Classes & Zoom links', 'Your schedule, attendance and reminders.' ),
				array( 'car', 'Driving lessons', 'Upcoming lessons, your progress and instructor notes.' ),
				array( 'chat', 'Messages', 'Talk to the school without phone tag.' ),
			)
		) . '<div class="sdsc-auth-main">';
	}

	public static function login_close() {
		$reg = function_exists( 'tutor_utils' ) ? tutor_utils()->student_register_url() : wp_registration_url();
		echo '<p class="sdsc-auth-alt">New here? <a href="' . esc_url( $reg ) . '">Create a student account</a> · Want to join a class? <a href="' . esc_url( home_url( '/register-course/' ) ) . '">Register for a course</a></p></div></div>';
	}

	public static function student_side() {
		echo self::side( // phpcs:ignore -- escaped in side()
			'Free student account',
			'Create your account',
			'One account for your classes, lessons, payments and receipts.',
			array(
				array( 'card', 'Pay online, safely', 'Card payments through Stripe — receipts in your inbox.' ),
				array( 'bell', 'Reminders', 'E-mail and text reminders before every class and lesson.' ),
				array( 'shield', 'Private', 'Your information stays with the school.' ),
			),
			'<p class="sdsc-auth-note">Signing up for a course? Use the <a href="' . esc_url( home_url( '/register-course/' ) ) . '">course registration form</a> — it creates your seat and your contract.</p>'
		);
	}

	public static function instructor_side() {
		echo self::side( // phpcs:ignore -- escaped in side()
			'Instructors',
			'Join the team',
			'Your lessons, the in-car evaluation app and your pay records in one place.',
			array(
				array( 'car', 'In-car app', 'Today’s lessons, student history and signatures.' ),
				array( 'cal', 'Your schedule', 'Bookings and changes appear right away.' ),
				array( 'chat', 'Office messages', 'Reach Saghar and the office from your phone.' ),
			)
		);
	}

	/* ------------------------------------------------------------ header account menu */

	/** Drop the pop-up login plugin's Login / Dashboard / Logout items from the main menu (the header has its own). */
	public static function menu_items( $items, $args ) {
		if ( empty( $args->theme_location ) || 'primary' !== $args->theme_location || ( class_exists( 'SDSC_Chrome' ) && ! SDSC_Chrome::on() ) ) { return $items; }
		return array_values( array_filter( $items, function ( $i ) {
			foreach ( (array) $i->classes as $c ) { if ( 0 === strpos( (string) $c, 'xoo-el-' ) ) { return false; } }
			return false === strpos( (string) $i->url, 'action=logout' );
		} ) );
	}

	protected static function role() {
		if ( current_user_can( 'manage_options' ) ) { return array( 'admin', 'Administrator' ); }
		$r = (array) wp_get_current_user()->roles;
		if ( class_exists( 'SDSC_Access' ) && in_array( SDSC_Access::MANAGER, $r, true ) ) { return array( 'manager', 'School manager' ); }
		if ( class_exists( 'SDSC_Access' ) && in_array( SDSC_Access::FRONTDESK, $r, true ) ) { return array( 'frontdesk', 'Front desk' ); }
		if ( array_intersect( $r, array( 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' ) ) ) { return array( 'instructor', 'Instructor' ); }
		return array( 'student', 'Student' );
	}

	protected static function dash( $tab = '' ) {
		return function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( $tab ) : home_url( '/dashboard/' . ( $tab ? $tab . '/' : '' ) );
	}

	/** Header account area. $where: 'bar' (top bar, compact) or 'drawer' (mobile menu, full). */
	public static function account( $where = 'bar' ) {
		if ( ! is_user_logged_in() ) {
			$login = add_query_arg( 'redirect_to', rawurlencode( home_url( add_query_arg( array() ) ) ), self::dash() );
			if ( 'drawer' === $where ) {
				return '<div class="sdh-acct-out"><a class="sdh-btn sdh-btn--ghost" href="' . esc_url( $login ) . '">' . self::user_svg() . 'Sign in</a></div>';
			}
			return '<a class="sdh-signin" href="' . esc_url( $login ) . '">' . self::user_svg() . '<span>Sign in</span></a>';
		}
		$u     = wp_get_current_user();
		$first = $u->first_name ? $u->first_name : $u->display_name;
		$full  = trim( $u->first_name . ' ' . $u->last_name );
		$full  = $full ? $full : $u->display_name;
		$parts = preg_split( '/\s+/', $full );
		$ini   = mb_strtoupper( mb_substr( $parts[0], 0, 1 ) . ( count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '' ) );
		list( $rk, $rl ) = self::role();
		$unread = ( class_exists( 'SDSC_Chat' ) ? SDSC_Chat::unread( $u->ID ) : 0 );
		$links  = array( array( 'home', 'My dashboard', self::dash() ) );
		if ( 'student' === $rk ) {
			if ( class_exists( 'SDSC_Lessons' ) && SDSC_Lessons::my_url() ) { $links[] = array( 'car', 'My driving lessons', SDSC_Lessons::my_url() ); }
			$links[] = array( 'cal', 'My classes', self::dash( 'enrolled-courses' ) );
		} elseif ( 'instructor' === $rk ) {
			$st = get_option( 'driveflow_pro_settings', array() );
			$id = is_array( $st ) ? absint( $st['instructor_page_id'] ?? 0 ) : 0;
			$links[] = array( 'car', 'In-car lesson app', $id ? get_permalink( $id ) : home_url( '/instructor-session/' ) );
			$links[] = array( 'cal', 'Zoom attendance', self::dash( 'sdsc-zoom-attendance' ) );
		} else {
			$links[] = array( 'cal', 'Lesson calendar', class_exists( 'SDSC_Workspace' ) ? SDSC_Workspace::url( 'df-calendar' ) : admin_url( 'admin.php?page=driveflow-pro-calendar' ) );
			$links[] = array( 'card', 'Registrations', class_exists( 'SDSC_Workspace' ) ? SDSC_Workspace::url( 'registrations' ) : admin_url( 'admin.php?page=sdsc-registrations' ) );
		}
		if ( class_exists( 'SDSC_Chat' ) ) { $links[] = array( 'chat', 'Messages', SDSC_Chat::page_url(), $unread ); }
		if ( 'admin' === $rk ) { $links[] = array( 'shield', 'Site admin', admin_url() ); }
		$out = wp_logout_url( home_url( '/' ) );

		$items = '';
		foreach ( $links as $l ) {
			$items .= '<a role="menuitem" href="' . esc_url( $l[2] ) . '"><span class="sdh-me-ic">' . ( 'home' === $l[0] ? self::home_svg() : self::icon( $l[0] ) ) . '</span><span>' . esc_html( $l[1] ) . '</span>' . ( ! empty( $l[3] ) ? '<em class="sdh-me-badge">' . (int) $l[3] . '</em>' : '' ) . '</a>';
		}
		$head = '<div class="sdh-me-head"><span class="sdh-me-av sdh-me-av--lg">' . esc_html( $ini ) . '</span><span><b>' . esc_html( $full ) . '</b><small>' . esc_html( $rl ) . ' · ' . esc_html( $u->user_email ) . '</small></span></div>';
		$logout = '<a class="sdh-me-out" role="menuitem" href="' . esc_url( $out ) . '"><span class="sdh-me-ic">' . self::out_svg() . '</span><span>Log out</span></a>';

		if ( 'drawer' === $where ) {
			return '<div class="sdh-acct-card">' . $head . '<div class="sdh-acct-btns"><a class="sdh-btn sdh-btn--go" href="' . esc_url( self::dash() ) . '">' . self::home_svg() . 'My dashboard</a><a class="sdh-btn sdh-btn--out" href="' . esc_url( $out ) . '">' . self::out_svg() . 'Log out</a></div></div>';
		}
		return '<div class="sdh-me" data-sdh-me>'
			. '<button type="button" class="sdh-me-btn" aria-haspopup="menu" aria-expanded="false" aria-label="Account menu for ' . esc_attr( $full ) . '"><span class="sdh-me-av">' . esc_html( $ini ) . ( $unread ? '<i class="sdh-me-dot" aria-hidden="true"></i>' : '' ) . '</span><span class="sdh-me-name">' . esc_html( $first ) . '</span><svg class="sdh-me-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
			. '<div class="sdh-me-menu" role="menu" hidden>' . $head . '<nav class="sdh-me-links">' . $items . '</nav><div class="sdh-me-sep"></div>' . $logout . '</div>'
			. '</div>';
	}

	protected static function user_svg() { return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.5" r="3.8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4.5 20c1.4-3.6 4.2-5.4 7.5-5.4s6.1 1.8 7.5 5.4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'; }
	protected static function home_svg() { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11l8-6 8 6v8a1 1 0 0 1-1 1h-4v-6h-6v6H5a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>'; }
	protected static function out_svg() { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>'; }
}
