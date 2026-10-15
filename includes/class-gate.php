<?php
/**
 * Course access by payment.
 *
 * A logged-in student can start the online course quizzes only when one of these is true:
 *  - a registration with the same e-mail is paid (online payment or marked paid by the office), or
 *  - the office (front desk / manager) or an administrator opened access for that e-mail by hand, or
 *  - the student was already enrolled in the course before this rule was switched on (existing students keep access).
 * Staff and instructors are never locked. Without a matching rule the course page shows a "locked" card with
 * a payment button, and quiz / lesson pages send the student back to that card.
 */
defined( 'ABSPATH' ) || exit;

class SDSC_Gate {

	const OPT_ON    = 'sdsc_course_gate';        // '1' = on (default), '0' = off
	const OPT_SINCE = 'sdsc_course_gate_since';  // enrollments before this keep access
	const OPT_OPEN  = 'sdsc_course_open_emails'; // e-mails the office opened by hand => array( email => array( by, at ) )

	public static function init() {
		if ( ! get_option( self::OPT_SINCE ) ) { update_option( self::OPT_SINCE, current_time( 'mysql' ), false ); }
		add_action( 'template_redirect', array( __CLASS__, 'guard_pages' ), 5 );
		add_action( 'tutor_before_enroll', array( __CLASS__, 'guard_enroll' ), 1 );
		add_action( 'tutor_quiz/start/before', array( __CLASS__, 'guard_quiz' ), 1 );
		add_action( 'wp_body_open', array( __CLASS__, 'locked_card' ) );
		add_action( 'admin_post_sdsc_course_access', array( __CLASS__, 'toggle' ) );
		add_filter( 'user_row_actions', array( __CLASS__, 'user_action' ), 10, 2 );
	}

	public static function on() { return '0' !== (string) get_option( self::OPT_ON, '1' ); }

	protected static function norm( $e ) { return strtolower( trim( (string) $e ) ); }

	/** Staff, instructors and administrators are never locked. */
	protected static function is_staff( $u ) {
		if ( user_can( $u, 'manage_options' ) || user_can( $u, 'edit_others_posts' ) ) { return true; }
		$staff = array( 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' );
		if ( class_exists( 'SDSC_Access' ) ) { $staff[] = SDSC_Access::MANAGER; $staff[] = SDSC_Access::FRONTDESK; }
		return (bool) array_intersect( (array) $u->roles, $staff );
	}

	public static function opened( $email ) {
		$o = (array) get_option( self::OPT_OPEN, array() );
		return isset( $o[ self::norm( $email ) ] );
	}

	/** Paid registration for this e-mail (online payment or marked paid). */
	public static function paid_email( $email ) {
		$email = self::norm( $email );
		if ( '' === $email || ! class_exists( 'SDSC_Registration' ) || ! class_exists( 'SDSC_Followup' ) ) { return false; }
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . SDSC_Registration::table() . ' WHERE LOWER(email) = %s OR LOWER(parent_email) = %s ORDER BY id DESC LIMIT 20', $email, $email ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			$r = (object) array( 'id' => (int) $id, 'registrant_id' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT registrant_id FROM ' . SDSC_Registration::table() . ' WHERE id = %d', $id ) ) ); // phpcs:ignore
			if ( SDSC_Followup::paid( $r ) ) { return true; }
		}
		return false;
	}

	/** Enrolled in any course before the rule was switched on. */
	protected static function grandfathered( $uid ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_author = %d AND post_date < %s LIMIT 1", $uid, get_option( self::OPT_SINCE ) ) );
	}

	/** Why the user may use the course: 'staff', 'paid', 'opened', 'existing' — or '' when locked. */
	public static function reason( $uid = 0 ) {
		$uid = $uid ? (int) $uid : get_current_user_id();
		if ( ! $uid ) { return ''; }
		$u = get_userdata( $uid );
		if ( ! $u ) { return ''; }
		if ( self::is_staff( $u ) ) { return 'staff'; }
		if ( self::opened( $u->user_email ) ) { return 'opened'; }
		if ( self::paid_email( $u->user_email ) ) { return 'paid'; }
		if ( self::grandfathered( $uid ) ) { return 'existing'; }
		return '';
	}

	public static function allowed( $uid = 0 ) {
		if ( ! self::on() ) { return true; }
		static $memo = array();
		$uid = $uid ? (int) $uid : get_current_user_id();
		if ( ! $uid ) { return true; } // logged out: Tutor asks to log in
		if ( ! isset( $memo[ $uid ] ) ) { $memo[ $uid ] = '' !== self::reason( $uid ); }
		return $memo[ $uid ];
	}

	/* ------------------------------------------------------------ front end */

	protected static function course_of( $post ) {
		if ( ! $post ) { return 0; }
		if ( 'courses' === $post->post_type ) { return (int) $post->ID; }
		return function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_course_id_by_content( $post ) : 0;
	}

	protected static function locked_url( $course_id ) {
		return add_query_arg( 'sdsc_locked', '1', get_permalink( $course_id ) ) . '#sdsc-locked';
	}

	/** Quiz, lesson and assignment pages: back to the course page when locked. */
	public static function guard_pages() {
		if ( ! is_user_logged_in() || self::allowed() ) { return; }
		if ( ! is_singular( array( 'tutor_quiz', 'lesson', 'tutor_assignments' ) ) ) { return; }
		$cid = self::course_of( get_queried_object() );
		wp_safe_redirect( $cid ? self::locked_url( $cid ) : home_url( '/' ) );
		exit;
	}

	public static function guard_enroll( $course_id ) {
		if ( self::allowed() ) { return; }
		if ( wp_doing_ajax() ) { wp_send_json_error( array( 'message' => self::message() ) ); }
		wp_safe_redirect( self::locked_url( $course_id ) );
		exit;
	}

	public static function guard_quiz( $quiz_id ) {
		if ( self::allowed() ) { return; }
		$cid = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_course_id_by_content( get_post( $quiz_id ) ) : 0;
		if ( wp_doing_ajax() ) { wp_send_json_error( array( 'message' => self::message() ) ); }
		wp_safe_redirect( $cid ? self::locked_url( $cid ) : home_url( '/' ) );
		exit;
	}

	protected static function message() {
		return 'Your online course opens after your payment is received. If you already paid, please call the school at (301) 726-3030.';
	}

	/** The newest unpaid registration for this e-mail, for the payment button. */
	protected static function pay_url( $email ) {
		if ( ! class_exists( 'SDSC_Pay' ) || ! class_exists( 'SDSC_Registration' ) ) { return ''; }
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, class_id FROM ' . SDSC_Registration::table() . ' WHERE LOWER(email) = %s ORDER BY id DESC LIMIT 1', self::norm( $email ) ) ); // phpcs:ignore
		return $r ? SDSC_Pay::url( (int) $r->id, (int) $r->class_id ) : '';
	}

	/** Locked card on the course page, and the course's start / quiz buttons are hidden. */
	public static function locked_card() {
		if ( ! is_user_logged_in() || self::allowed() || ! is_singular( 'courses' ) ) { return; }
		$u   = wp_get_current_user();
		$pay = self::pay_url( $u->user_email );
		$reg = get_page_by_path( 'register-course' ) ? home_url( '/register-course/' ) : home_url( '/#driver-ed' );
		?>
		<style>
		.sdgate{position:relative;z-index:50;max-width:1100px;margin:18px auto;padding:20px 22px;border-radius:18px;background:#fff8e1;box-shadow:inset 0 0 0 2px #ffc20e,0 20px 40px -30px rgba(0,0,0,.5);font-family:Overpass,system-ui,sans-serif;color:#15191d;display:grid;grid-template-columns:auto 1fr;gap:6px 16px;align-items:center}
		.sdgate-ic{grid-row:span 3;display:grid;place-items:center;width:56px;height:56px;border-radius:16px;background:#15191d;color:#ffc20e}
		.sdgate h2{margin:0!important;font-size:1.3rem!important;font-weight:900!important;color:#15191d!important}
		.sdgate p{margin:0!important;color:#4a4f55}
		.sdgate-btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
		.sdgate-btns a{display:inline-flex;align-items:center;min-height:44px;padding:0 18px;border-radius:999px;font-weight:800;text-decoration:none!important}
		.sdgate-pay{background:#00703c;color:#fff!important}
		.sdgate-call{color:#00703c!important;box-shadow:inset 0 0 0 2px #00703c}
		body.sdgate-locked .tutor-course-details-actions a,body.sdgate-locked .tutor-course-details-actions button,body.sdgate-locked .tutor-enroll-course-button,body.sdgate-locked form.tutor-enrol-course-form,body.sdgate-locked .tutor-course-sidebar-card a.tutor-btn,body.sdgate-locked .tutor-course-sidebar-card button.tutor-btn,body.sdgate-locked .tutor-course-content-list a{pointer-events:none!important;opacity:.45!important}
		@media (max-width:600px){.sdgate{grid-template-columns:1fr}.sdgate-ic{display:none}}
		</style>
		<script>document.body.classList.add('sdgate-locked');</script>
		<section class="sdgate" id="sdsc-locked" role="status">
			<span class="sdgate-ic" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
			<h2>Your course opens after payment</h2>
			<p>We could not find a payment for <b><?php echo esc_html( $u->user_email ); ?></b> yet. If you already paid, or you paid with a different e-mail, please call the school and we will open your course.</p>
			<div class="sdgate-btns">
				<?php if ( $pay ) : ?><a class="sdgate-pay" href="<?php echo esc_url( $pay ); ?>">Pay now</a><?php else : ?><a class="sdgate-pay" href="<?php echo esc_url( $reg ); ?>">Register for a class</a><?php endif; ?>
				<a class="sdgate-call" href="tel:+13017263030">Call (301) 726-3030</a>
			</div>
		</section>
		<?php
	}

	/* ------------------------------------------------------------ office */

	protected static function can_manage() {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		return class_exists( 'SDSC_Docs' ) && SDSC_Docs::is_front_office();
	}

	public static function toggle_url( $email, $open ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'sdsc_course_access', 'e' => rawurlencode( self::norm( $email ) ), 'open' => $open ? 1 : 0 ), admin_url( 'admin-post.php' ) ), 'sdsc_course_access_' . self::norm( $email ) );
	}

	public static function toggle() {
		$email = self::norm( rawurldecode( sanitize_text_field( wp_unslash( $_GET['e'] ?? '' ) ) ) ); // phpcs:ignore
		if ( ! self::can_manage() || ! is_email( $email ) ) { wp_die( 'You do not have permission to do this.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_course_access_' . $email );
		$o = (array) get_option( self::OPT_OPEN, array() );
		if ( ! empty( $_GET['open'] ) ) { // phpcs:ignore
			$o[ $email ] = array( 'by' => wp_get_current_user()->display_name, 'at' => current_time( 'mysql' ) );
		} else {
			unset( $o[ $email ] );
		}
		update_option( self::OPT_OPEN, $o, false );
		wp_safe_redirect( add_query_arg( 'sdsc_access', ! empty( $_GET['open'] ) ? 'opened' : 'closed', wp_get_referer() ? wp_get_referer() : admin_url() ) ); // phpcs:ignore
		exit;
	}

	/** Box in the registration detail view. */
	public static function panel( $email ) {
		if ( ! self::can_manage() || ! is_email( $email ) ) { return ''; }
		$email  = self::norm( $email );
		$o      = (array) get_option( self::OPT_OPEN, array() );
		$paid   = self::paid_email( $email );
		$user   = get_user_by( 'email', $email );
		$reason = $user ? self::reason( $user->ID ) : ( $paid ? 'paid' : ( isset( $o[ $email ] ) ? 'opened' : '' ) );
		$labels = array( 'staff' => 'Staff account', 'paid' => 'Open — payment received', 'opened' => 'Open — opened by the office', 'existing' => 'Open — enrolled before payment check started' );
		$ok     = '' !== $reason;
		$h  = '<div style="margin:14px 0;padding:14px 16px;border-radius:12px;background:' . ( $ok ? '#eaf6ef' : '#fff8e1' ) . ';border:1px solid ' . ( $ok ? '#bfe3cc' : '#ffd766' ) . '"><h3 style="margin:0 0 6px">Online course access</h3>';
		$h .= '<p style="margin:0 0 8px;font-weight:700">' . ( $ok ? '✓ ' . esc_html( $labels[ $reason ] ) : '🔒 Locked — no payment yet' ) . '</p>';
		if ( isset( $o[ $email ] ) ) { $h .= '<p style="margin:0 0 8px;color:#555">Opened by ' . esc_html( $o[ $email ]['by'] ) . ' on ' . esc_html( mysql2date( 'M j, Y g:i a', $o[ $email ]['at'] ) ) . '</p>'; }
		if ( ! $user ) { $h .= '<p style="margin:0 0 8px;color:#555">No website account with this e-mail yet. Access follows the e-mail, so it will apply when the student creates the account.</p>'; }
		if ( isset( $o[ $email ] ) ) {
			$h .= '<a class="button" href="' . esc_url( self::toggle_url( $email, false ) ) . '">Remove manual access</a>';
		} elseif ( ! $paid ) {
			$h .= '<a class="button button-primary" href="' . esc_url( self::toggle_url( $email, true ) ) . '">Open course access</a>';
		}
		return $h . '</div>';
	}

	/** Users screen: "Open course access" / "Remove course access" link under each student. */
	public static function user_action( $actions, $user ) {
		if ( ! self::on() || ! self::can_manage() || self::is_staff( $user ) ) { return $actions; }
		if ( self::opened( $user->user_email ) ) {
			$actions['sdsc_access'] = '<a href="' . esc_url( self::toggle_url( $user->user_email, false ) ) . '">Remove course access</a>';
		} elseif ( '' === self::reason( $user->ID ) ) {
			$actions['sdsc_access'] = '<a href="' . esc_url( self::toggle_url( $user->user_email, true ) ) . '">Open course access</a>';
		}
		return $actions;
	}
}
