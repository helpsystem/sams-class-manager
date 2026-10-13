<?php
/**
 * "View as" — lets the administrator see the dashboard exactly as each role sees it.
 *
 * The administrator switches into a dedicated preview account for a role (never into a real person's
 * account), looks around, and switches back with one click. Preview accounts cannot sign in with a
 * password, and the way back is bound to a one-time server-side token plus a cookie.
 *
 * A private demo class ("DEMO — Preview class") gives the previews something to look at: the preview
 * instructor teaches it and the preview student is registered in it. It is never shown on the website.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Preview {
	const META   = 'sdsc_preview';      // user meta on preview accounts: role key
	const BACK   = 'sdsc_preview_back'; // user meta on the preview account: [ admin id, token hash, expiry ]
	const COOKIE = 'sdsc_pv_back';
	const DEMO   = '_sdsc_demo_class';  // post meta on the demo class

	public static function roles() {
		return array(
			'manager'    => array( 'School manager (Saghar)', class_exists( 'SDSC_Access' ) ? SDSC_Access::MANAGER : 'editor' ),
			'frontdesk'  => array( 'Front desk', class_exists( 'SDSC_Access' ) ? SDSC_Access::FRONTDESK : 'subscriber' ),
			'instructor' => array( 'Instructor', class_exists( 'SDSC_Access' ) ? SDSC_Access::instructor_role() : 'author' ),
			'student'    => array( 'Student', 'subscriber' ),
		);
	}

	public static function init() {
		add_action( 'admin_post_sdsc_preview_as', array( __CLASS__, 'switch_to' ) );
		add_action( 'admin_post_sdsc_preview_back', array( __CLASS__, 'switch_back' ) );
		add_filter( 'authenticate', array( __CLASS__, 'no_password_login' ), 99, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'bar' ) );
		add_action( 'admin_footer', array( __CLASS__, 'bar' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_filter( 'send_email_change_email', array( __CLASS__, 'quiet' ), 10, 2 );
		add_filter( 'send_password_change_email', array( __CLASS__, 'quiet' ), 10, 2 );
	}

	public static function is_preview( $uid = 0 ) {
		$uid = $uid ? $uid : get_current_user_id();
		return $uid && '' !== (string) get_user_meta( $uid, self::META, true );
	}

	public static function quiet( $send, $user ) {
		return self::is_preview( (int) ( $user['ID'] ?? 0 ) ) ? false : $send;
	}

	/** Preview accounts never sign in with a password. */
	public static function no_password_login( $user, $username, $password ) {
		if ( $user instanceof WP_User && self::is_preview( $user->ID ) ) {
			return new WP_Error( 'sdsc_preview', 'This is a preview account and cannot sign in.' );
		}
		return $user;
	}

	/* ------------------------------------------------------------ accounts and demo data */

	protected static function account( $key ) {
		$r     = self::roles()[ $key ];
		$login = 'preview-' . $key;
		$u     = get_user_by( 'login', $login );
		if ( ! $u ) {
			// The site's spam snippet deletes new accounts whose e-mail domain is not on its allowlist; preview accounts are ours.
			$spam = has_action( 'user_register', 'sds_remove_spam_domain_user' );
			if ( false !== $spam ) { remove_action( 'user_register', 'sds_remove_spam_domain_user', $spam ); }
			$id = wp_insert_user( array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 40, true, true ),
				'user_email'   => $login . '@preview.samsdrivingschool.invalid',
				'display_name' => 'Preview · ' . $r[0],
				'first_name'   => 'Preview',
				'last_name'    => $r[0],
				'role'         => $r[1],
			) );
			if ( false !== $spam ) { add_action( 'user_register', 'sds_remove_spam_domain_user', $spam, 1 ); }
			if ( is_wp_error( $id ) || ! get_userdata( $id ) ) { return 0; }
			update_user_meta( $id, self::META, $key );
			update_user_meta( $id, 'show_admin_bar_front', 'false' );
			$u = get_user_by( 'id', $id );
		}
		if ( ! in_array( $r[1], (array) $u->roles, true ) ) { $u->set_role( $r[1] ); }
		update_user_meta( $u->ID, self::META, $key );
		return (int) $u->ID;
	}

	/** Private demo class with a 10-session timetable, the preview instructor and three demo students. */
	public static function demo() {
		$found = get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => self::DEMO, 'meta_value' => '1' ) ); // phpcs:ignore
		if ( $found ) { $cid = $found[0]->ID; } else {
			$cid = wp_insert_post( array( 'post_type' => 'sdsc_class', 'post_status' => 'private', 'post_title' => 'DEMO — Preview class (not on the website)' ) );
			if ( ! $cid || is_wp_error( $cid ) ) { return 0; }
			update_post_meta( $cid, self::DEMO, '1' );
			update_post_meta( $cid, '_sdsc_course_code', 'DEMO' );
			update_post_meta( $cid, '_sdsc_days_time', 'Mon–Thu, 5:30–8:30 PM' );
			update_post_meta( $cid, '_sdsc_zoom_link', 'https://zoom.us/' );
		}
		// Keep the demo class "in progress": started a week ago, 10 sessions on weekdays.
		$start = wp_date( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
		update_post_meta( $cid, '_sdsc_start', $start );
		update_post_meta( $cid, '_sdsc_end', wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) );
		update_post_meta( $cid, '_sdsc_sched', array( 'days' => array( 1, 2, 3, 4 ), 'from' => '17:30', 'to' => '20:30', 'count' => 10, 'remind' => 0 ) );
		if ( class_exists( 'SDSC_ZoomRoll' ) ) { update_post_meta( $cid, SDSC_ZoomRoll::INSTR, self::account( 'instructor' ) ); }
		if ( class_exists( 'SDSC_DriveFlow' ) ) { SDSC_DriveFlow::ensure_instructor( self::account( 'instructor' ) ); }

		global $wpdb;
		if ( class_exists( 'SDSC_Registration' ) ) {
			$t        = SDSC_Registration::table();
			$students = array(
				array( 'Preview', 'Student', 'preview-student@preview.samsdrivingschool.invalid' ),
				array( 'Demo', 'Rivera', 'demo-rivera@preview.samsdrivingschool.invalid' ),
				array( 'Demo', 'Karimi', 'demo-karimi@preview.samsdrivingschool.invalid' ),
			);
			foreach ( $students as $s ) {
				$has = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE class_id = %d AND email = %s", $cid, $s[2] ) ); // phpcs:ignore
				if ( ! $has ) {
					$wpdb->insert( $t, array( 'created_at' => current_time( 'mysql' ), 'source' => 'demo', 'class_id' => $cid, 'first_name' => $s[0], 'last_name' => $s[1], 'email' => $s[2], 'phone' => '(301) 555-0199', 'consent' => 1 ) );
				}
			}
		}
		// Driving lessons in DriveFlow (the in-car app, the calendar and "My lessons" all read them):
		// lesson 1 done last week with another instructor, lesson 2 today and 3 later with the preview
		// instructor, plus a second student whose report is still missing.
		if ( class_exists( 'SDSC_DriveFlow' ) && SDSC_DriveFlow::on() && class_exists( 'SDSC_Lessons' ) ) {
			self::demo_df();
			$lt = SDSC_Lessons::t_lessons();
			$wpdb->query( $wpdb->prepare( "DELETE FROM $lt WHERE df_id = 0 AND email = %s AND log LIKE %s", 'preview-student@preview.samsdrivingschool.invalid', 'Demo lesson for the dashboard preview.%' ) ); // phpcs:ignore -- our own demo rows
		} elseif ( class_exists( 'SDSC_Lessons' ) ) {
			$lt   = SDSC_Lessons::t_lessons();
			$name = 'Preview Instructor'; // same name as the preview instructor's DriveFlow profile
			$wpdb->query( $wpdb->prepare( "UPDATE $lt SET instructor = %s WHERE email = %s", $name, 'preview-student@preview.samsdrivingschool.invalid' ) ); // phpcs:ignore
			foreach ( array( 0, 3 ) as $d ) {
				$at  = wp_date( 'Y-m-d', time() + $d * DAY_IN_SECONDS ) . ' 15:00:00';
				$has = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $lt WHERE email = %s AND starts_at = %s", 'preview-student@preview.samsdrivingschool.invalid', $at ) ); // phpcs:ignore
				if ( ! $has ) {
					$wpdb->insert( $lt, array( 'first_name' => 'Preview', 'last_name' => 'Student', 'email' => 'preview-student@preview.samsdrivingschool.invalid', 'phone' => '(301) 555-0199', 'lesson_no' => $d ? 2 : 1, 'starts_at' => $at, 'minutes' => 120, 'place' => '751 Rockville Pike', 'instructor' => $name, 'status' => 'booked', 'created_at' => current_time( 'mysql' ), 'log' => 'Demo lesson for the dashboard preview.' ) );
				}
			}
		}
		return (int) $cid;
	}

	/** Demo DriveFlow data for the previews (all marked "(demo)", e-mails on the .invalid domain). */
	protected static function demo_df() {
		global $wpdb;
		$p   = $wpdb->prefix . 'driveflow_';
		$now = current_time( 'mysql' );
		$dom = '@preview.samsdrivingschool.invalid';
		// A second instructor, so the student's record shows a change of instructor.
		$b = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}instructors WHERE email = %s", 'demo-instructor-b' . $dom ) ); // phpcs:ignore
		if ( ! $b ) {
			$wpdb->insert( $p . 'instructors', array( 'name' => 'Demo Instructor B', 'email' => 'demo-instructor-b' . $dom, 'license_number' => 'DEMO-B', 'status' => 'active', 'agreement_signed' => 1, 'onboarding_completed' => 1, 'phone' => '(301) 555-0198', 'payment_details' => 'demo', 'created_at' => $now, 'updated_at' => $now ) );
		}
		$plate = (string) $wpdb->get_var( "SELECT plate_number FROM {$p}vehicles WHERE status = 'active' ORDER BY id LIMIT 1" ); // phpcs:ignore
		if ( '' === $plate ) {
			$plate = 'DEMO01';
			$wpdb->insert( $p . 'vehicles', array( 'plate_number' => $plate, 'model' => 'Demo car', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		}
		$stu = function ( $name, $email ) use ( $wpdb, $p, $now ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}students WHERE email = %s", $email ) ); // phpcs:ignore
			if ( ! $id ) {
				$wpdb->insert( $p . 'students', array( 'name' => $name, 'email' => $email, 'phone' => '(301) 555-0199', 'license_number' => 'DEMO-PERMIT', 'package_name' => 'Demo 6-lesson package', 'total_sessions' => 6, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
				$id = (int) $wpdb->insert_id;
			}
			return $id;
		};
		$s1 = $stu( 'Preview Student', 'preview-student' . $dom );
		$s2 = $stu( 'Demo Rivera', 'demo-rivera' . $dom );
		$day = function ( $d, $h ) { return wp_date( 'Y-m-d', time() + $d * DAY_IN_SECONDS ) . ' ' . $h; };
		$grid = array();
		foreach ( range( 0, 21 ) as $i ) { $grid[ $i ] = array( 'l1' => (string) ( 4 - ( $i % 3 ) ) ); }
		$form = array( 'source' => 'in_car_eval_record', 'dates' => array( 'l1' => wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) ), 'skills_eval' => $grid, 'conds_eval' => array(), 'final_evaluation' => 'Pass', 'column' => 1, 'instructor_notes' => 'Good mirror checks and steering. Practice smooth braking before stop signs.', 'fuel' => array( 'level_pct' => 75 ), 'incident' => array( 'reported' => 0 ) );
		$rows = array(
			array( $s1, 'Preview Student', 'Demo Instructor B', 1, $day( -6, '15:00:00' ), $day( -6, '17:00:00' ), 'completed', wp_json_encode( $form ) ),
			array( $s1, 'Preview Student', 'Preview Instructor', 2, $day( 0, '15:00:00' ), $day( 0, '17:00:00' ), 'upcoming', null ),
			array( $s1, 'Preview Student', 'Preview Instructor', 3, $day( 3, '15:00:00' ), $day( 3, '17:00:00' ), 'upcoming', null ),
			array( $s2, 'Demo Rivera', 'Preview Instructor', 1, $day( -1, '10:00:00' ), $day( -1, '12:00:00' ), 'upcoming', null ),
		);
		foreach ( $rows as $r ) {
			$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}sessions WHERE student_id = %d AND session_number = %d AND lesson_topic LIKE %s", $r[0], $r[3], '%(demo)' ) ); // phpcs:ignore
			if ( $has ) { continue; }
			$wpdb->insert( $p . 'sessions', array( 'student_id' => $r[0], 'student_name' => $r[1], 'instructor_name' => $r[2], 'session_number' => $r[3], 'lesson_topic' => 'Behind-the-wheel lesson (demo)', 'scheduled_start' => $r[4], 'scheduled_end' => $r[5], 'status' => $r[6], 'form_data' => $r[7], 'plate_number' => $plate, 'created_at' => $now, 'updated_at' => $now ) );
		}
	}

	/* ------------------------------------------------------------ switching */

	public static function switch_to() {
		if ( ! current_user_can( 'manage_options' ) || self::is_preview() ) { wp_die( 'Not allowed.', 403 ); }
		check_admin_referer( 'sdsc_preview_as' );
		$key = sanitize_key( $_GET['role'] ?? '' ); // phpcs:ignore
		if ( ! isset( self::roles()[ $key ] ) ) { wp_die( 'Unknown role.' ); }
		$admin = get_current_user_id();
		self::demo();
		$uid   = self::account( $key );
		if ( ! $uid ) { wp_die( 'Could not prepare the preview account.' ); }
		$token = wp_generate_password( 40, false, false );
		update_user_meta( $uid, self::BACK, array( $admin, wp_hash_password( $token ), time() + 12 * HOUR_IN_SECONDS ) );
		setcookie( self::COOKIE, $uid . '|' . $token, array( 'expires' => time() + 12 * HOUR_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		wp_clear_auth_cookie();
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, false, is_ssl() );
		wp_safe_redirect( self::dash() );
		exit;
	}

	public static function switch_back() {
		$uid = get_current_user_id();
		$c   = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		list( $cu, $tok ) = array_pad( explode( '|', $c, 2 ), 2, '' );
		$b = get_user_meta( $uid, self::BACK, true );
		if ( ! self::is_preview( $uid ) || (int) $cu !== $uid || ! is_array( $b ) || $b[2] < time() || ! wp_check_password( $tok, $b[1] ) || ! user_can( (int) $b[0], 'manage_options' ) ) {
			// The way back is no longer valid (expired, or the cookie is gone). Never leave the person stuck in
			// the preview account: sign the preview out and send them to the sign-in page for their own account.
			if ( self::is_preview( $uid ) ) {
				delete_user_meta( $uid, self::BACK );
				setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
				wp_logout();
				wp_safe_redirect( wp_login_url( self::dash() ) . '&sdsc_preview_ended=1' );
				exit;
			}
			wp_die( 'The preview has expired. Please sign in again.', 403 );
		}
		check_admin_referer( 'sdsc_preview_back' );
		delete_user_meta( $uid, self::BACK );
		setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		wp_clear_auth_cookie();
		wp_set_current_user( (int) $b[0] );
		wp_set_auth_cookie( (int) $b[0], false, is_ssl() );
		wp_safe_redirect( self::dash() );
		exit;
	}

	protected static function on_dashboard() {
		if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_tutor_frontend_dashboard' ) ) { return (bool) tutor_utils()->is_tutor_frontend_dashboard(); }
		$id = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) : 0;
		return $id && is_page( $id );
	}

	/** "View as" in the admin bar, everywhere, for the administrator. */
	public static function admin_bar( $bar ) {
		if ( self::is_preview() || ! current_user_can( 'manage_options' ) ) { return; }
		$bar->add_node( array( 'id' => 'sdsc-view-as', 'title' => '👁 View as', 'href' => self::dash() ) );
		foreach ( self::roles() as $k => $r ) {
			$bar->add_node( array( 'parent' => 'sdsc-view-as', 'id' => 'sdsc-view-as-' . $k, 'title' => esc_html( $r[0] ), 'href' => self::link( $k ) ) );
		}
	}

	protected static function dash() {
		return function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' );
	}

	protected static function link( $key ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_preview_as&role=' . $key ), 'sdsc_preview_as' );
	}

	/* ------------------------------------------------------------ the bar */

	public static function bar() {
		if ( ! is_user_logged_in() || ! empty( $_GET['sdsc_embed'] ) || false !== strpos( (string) wp_get_referer(), 'sdsc_embed=1' ) ) { return; } // phpcs:ignore -- not inside dashboard screens
		$pv = self::is_preview();
		if ( ! $pv && ( ! current_user_can( 'manage_options' ) || is_admin() || ! self::on_dashboard() ) ) { return; }
		$css = 'position:fixed;left:50%;bottom:14px;transform:translateX(-50%);z-index:100000;display:flex;flex-wrap:wrap;gap:6px;align-items:center;max-width:calc(100% - 24px);padding:8px 10px;border-radius:999px;background:#15191d;color:#fff;font:600 13px/1.2 system-ui,-apple-system,Segoe UI,Arial,sans-serif;box-shadow:0 12px 30px rgba(0,0,0,.35)';
		$btn = 'display:inline-block;padding:7px 12px;border-radius:999px;text-decoration:none;font-weight:700;';
		echo '<div id="sdsc-preview-bar" style="' . esc_attr( $css ) . '">';
		if ( $pv ) {
			$key = (string) get_user_meta( get_current_user_id(), self::META, true );
			$lab = self::roles()[ $key ][0] ?? $key;
			echo '<span style="padding:0 6px">👁 Viewing as <b style="color:#ffc20e">' . esc_html( $lab ) . '</b> (preview account)</span>';
			echo '<a style="' . esc_attr( $btn . 'background:#ffc20e;color:#15191d' ) . '" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_preview_back' ), 'sdsc_preview_back' ) ) . '">Back to my account</a>';
		} else {
			echo '<span style="padding:0 6px">👁 View dashboard as</span>';
			echo '<a style="' . esc_attr( $btn . 'background:#00703c;color:#fff' ) . '" href="' . esc_url( self::dash() ) . '">Admin (me)</a>';
			foreach ( self::roles() as $k => $r ) {
				echo '<a style="' . esc_attr( $btn . 'background:#2c3338;color:#fff' ) . '" href="' . esc_url( self::link( $k ) ) . '">' . esc_html( $r[0] ) . '</a>';
			}
			echo '<button type="button" onclick="this.parentNode.remove()" style="border:0;background:none;color:#8c8f94;font-size:16px;cursor:pointer;padding:0 6px" aria-label="Hide">×</button>';
		}
		echo '</div>';
	}
}
