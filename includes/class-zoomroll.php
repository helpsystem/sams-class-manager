<?php
/**
 * Zoom class roll call (instructor attendance checklist).
 *
 * One checklist per Zoom session (10 by default) on the Tutor dashboard:
 *  - Instructor: sees only the classes assigned to them, ticks Present / Late / Left early / Absent for
 *    every student after (or during) the session, adds a short note, and submits the sheet.
 *  - School manager and front desk (and the administrator): every class, a "sheets still to submit" list,
 *    and the full 10-session grid per class.
 *  - Student: a 10-dot strip per class on "My driving lessons" (see SDSC_Schedule::student_html).
 *
 * Rows live in the existing attendance table (kind 'zoom', ref = class id, sess = Y-m-d, email), so the
 * wp-admin sheet, the absence count and the RSVP links keep working on the same data.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_ZoomRoll {
	const TAB    = 'sdsc-zoom-attendance';  // Tutor dashboard page key
	const INSTR  = '_sdsc_zoom_instr';      // class meta: instructor user id
	const DONE   = '_sdsc_zr_done';         // class meta: date => [ user id, local time ]
	const STATES = array( 'present', 'late', 'left', 'absent' );

	public static function init() {
		add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'tutor_nav' ) );
		add_filter( 'tutor_instructor_dashboard_nav', array( __CLASS__, 'tutor_nav' ) );
		add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'tutor_template' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_sdsc_zr_save', array( __CLASS__, 'h_save' ) );
		add_shortcode( 'sdsc_zoom_attendance', array( __CLASS__, 'render' ) );
		add_action( 'sdsc_class_timetable_fields', array( __CLASS__, 'metabox_field' ) );
		add_action( 'save_post_sdsc_class', array( __CLASS__, 'save_meta' ), 21 );
	}

	public static function label( $s ) {
		return array( 'present' => 'Present', 'late' => 'Late', 'left' => 'Left early', 'absent' => 'Absent' )[ $s ] ?? '';
	}

	/* ------------------------------------------------------------ who sees what */

	/** Manager, front desk and administrator see every class; an instructor sees only their own. */
	public static function sees_all() {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		if ( ! class_exists( 'SDSC_Access' ) ) { return false; }
		$roles = (array) wp_get_current_user()->roles;
		return (bool) array_intersect( $roles, array( SDSC_Access::MANAGER, SDSC_Access::FRONTDESK ) );
	}

	public static function allowed() {
		return is_user_logged_in() && class_exists( 'SDSC_Schedule' ) && SDSC_Schedule::can();
	}

	public static function instructor_of( $cid ) { return (int) get_post_meta( $cid, self::INSTR, true ); }

	public static function can_class( $cid ) {
		if ( ! self::allowed() || 'sdsc_class' !== get_post_type( $cid ) ) { return false; }
		return self::sees_all() || self::instructor_of( $cid ) === get_current_user_id();
	}

	/** Classes with a timetable that the current user may take attendance for, newest first. */
	public static function classes() {
		$args = array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'numberposts' => 80, 'orderby' => 'meta_value', 'meta_key' => '_sdsc_start', 'order' => 'DESC' ); // phpcs:ignore
		if ( ! self::sees_all() ) {
			$args['meta_query'] = array( array( 'key' => self::INSTR, 'value' => get_current_user_id(), 'type' => 'NUMERIC' ) ); // phpcs:ignore
		}
		$pv = class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview();
		// The private demo class is only for the "View as" preview accounts.
		return array_values( array_filter( get_posts( $args ), function ( $p ) use ( $pv ) { return ( $pv || ! get_post_meta( $p->ID, '_sdsc_demo_class', true ) ) && SDSC_Schedule::sessions( $p->ID ); } ) );
	}

	/* ------------------------------------------------------------ class edit screen: instructor */

	public static function instructors() {
		$roles = array( SDSC_Access::instructor_role(), SDSC_Access::MANAGER, 'administrator' );
		return get_users( array( 'role__in' => $roles, 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) );
	}

	public static function metabox_field( $post ) {
		$cur = self::instructor_of( $post->ID );
		echo '<p><label><b>Zoom instructor</b> (takes attendance on the dashboard) <select name="sdsc_zoom_instr"><option value="0">— not assigned —</option>';
		foreach ( self::instructors() as $u ) {
			echo '<option value="' . (int) $u->ID . '"' . selected( $cur, (int) $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select></label></p>';
	}

	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['sdsc_sched_nonce'], $_POST['sdsc_zoom_instr'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sdsc_sched_nonce'] ), 'sdsc_sched' ) ) { return; }
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$uid = (int) $_POST['sdsc_zoom_instr'];
		$ok  = wp_list_pluck( self::instructors(), 'ID' );
		if ( $uid && ! in_array( $uid, array_map( 'intval', $ok ), true ) ) { return; }
		update_post_meta( $post_id, self::INSTR, $uid );
	}

	/* ------------------------------------------------------------ Tutor dashboard */

	public static function url( $args = array() ) {
		$base = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( self::TAB ) : home_url( '/dashboard/' . self::TAB . '/' );
		return add_query_arg( $args, $base );
	}

	public static function tutor_nav( $items ) {
		if ( ! is_array( $items ) ) { return $items; }
		// Always listed (Tutor builds its URL rules from this list); auth_cap hides it from people without access.
		$items[ self::TAB ] = array( 'title' => 'Zoom attendance', 'icon' => 'check-square', 'active_icon' => 'check-square-filled', 'auth_cap' => 'sdsc_access_attendance' );
		return $items;
	}

	public static function tutor_template( $tpl ) {
		global $wp_query;
		if ( self::TAB === ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' ) ) {
			return dirname( __DIR__ ) . '/templates/tutor-zoom-attendance.php';
		}
		return $tpl;
	}

	public static function assets() {
		if ( ! is_user_logged_in() ) { return; }
		wp_register_style( 'sdsc-zoomroll', SDSC_URL . 'assets/zoomroll.css', array(), SDSC_VERSION );
		wp_register_script( 'sdsc-zoomroll', SDSC_URL . 'assets/zoomroll.js', array(), SDSC_VERSION, true );
		global $wp_query;
		if ( self::TAB === ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' ) ) {
			wp_enqueue_style( 'sdsc-zoomroll' );
			wp_enqueue_script( 'sdsc-zoomroll' );
		}
	}

	/* ------------------------------------------------------------ data */

	/** All rows of a class: [ email => [ date => row ] ]. */
	public static function rows( $cid ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Schedule::table() . " WHERE kind = 'zoom' AND ref = %d", $cid ), ARRAY_A ) as $r ) { // phpcs:ignore
			$out[ $r['email'] ][ $r['sess'] ] = $r;
		}
		return $out;
	}

	public static function done( $cid ) {
		$d = get_post_meta( $cid, self::DONE, true );
		return is_array( $d ) ? $d : array();
	}

	/** Past or today's sessions whose sheet has not been submitted. */
	public static function missing( $cid ) {
		$today = current_time( 'Y-m-d' );
		$done  = self::done( $cid );
		return array_values( array_filter( SDSC_Schedule::sessions( $cid ), function ( $x ) use ( $today, $done ) { return $x['date'] <= $today && empty( $done[ $x['date'] ] ); } ) );
	}

	protected static function who( $uid ) {
		$u = $uid ? get_userdata( (int) $uid ) : null;
		return $u ? $u->display_name : '—';
	}

	/* ------------------------------------------------------------ screen */

	public static function render() {
		if ( ! self::allowed() ) { return '<p>' . esc_html__( 'This page is for the school team.', 'sdsc' ) . '</p>'; }
		wp_enqueue_style( 'sdsc-zoomroll' );
		wp_enqueue_script( 'sdsc-zoomroll' );
		$classes = self::classes();
		$h       = '<div class="szr"><h3>Zoom attendance</h3>';
		if ( isset( $_GET['szr_msg'] ) ) { // phpcs:ignore
			$h .= '<p class="szr-ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['szr_msg'] ) ) ) . '</p>'; // phpcs:ignore
		}
		if ( ! $classes ) {
			return $h . '<p class="szr-m">' . ( self::sees_all()
				? 'No class has a timetable yet. Open a class in wp-admin and fill in “Timetable &amp; calendar”.'
				: 'No Zoom class is assigned to you yet. The school office assigns the instructor on each class.' ) . '</p></div>';
		}
		$ids = wp_list_pluck( $classes, 'ID' );
		$cid = isset( $_GET['zc'] ) ? (int) $_GET['zc'] : 0; // phpcs:ignore
		if ( ! in_array( $cid, $ids, true ) ) { $cid = $ids[0]; }

		if ( self::sees_all() ) { $h .= self::todo_html( $classes ); }

		$h .= '<form method="get" class="szr-pick"><label>Class <select name="zc" onchange="this.form.submit()">';
		foreach ( $classes as $c ) {
			$h .= '<option value="' . (int) $c->ID . '"' . selected( $cid, $c->ID, false ) . '>' . esc_html( SDSC_Schedule::class_name( $c->ID ) . ' · ' . get_post_meta( $c->ID, '_sdsc_start', true ) ) . '</option>';
		}
		$h .= '</select></label><noscript><button>Show</button></noscript></form>';

		$list  = SDSC_Schedule::sessions( $cid );
		$today = current_time( 'Y-m-d' );
		$dates = wp_list_pluck( $list, 'date' );
		$sess  = isset( $_GET['zs'] ) ? sanitize_text_field( wp_unslash( $_GET['zs'] ) ) : ''; // phpcs:ignore
		if ( ! in_array( $sess, $dates, true ) ) {
			$sess = $dates[0];
			foreach ( $list as $x ) { if ( $x['date'] <= $today ) { $sess = $x['date']; } }
		}
		$done = self::done( $cid );

		$h .= '<nav class="szr-tabs" aria-label="Sessions">';
		foreach ( $list as $x ) {
			$cls = 'szr-tab' . ( $x['date'] === $sess ? ' is-on' : '' ) . ( ! empty( $done[ $x['date'] ] ) ? ' is-done' : ( $x['date'] <= $today ? ' is-due' : '' ) );
			$h  .= '<a class="' . $cls . '" href="' . esc_url( self::url( array( 'zc' => $cid, 'zs' => $x['date'] ) ) ) . '"><b>' . (int) $x['n'] . '</b><span>' . esc_html( wp_date( 'M j', SDSC_Lessons::ts( $x['start'] ) ) ) . '</span></a>';
		}
		$h .= '</nav>';

		$h .= self::sheet_html( $cid, $list, $sess, $today, $done );
		$h .= self::grid_html( $cid, $list, $today, $done );
		return $h . '</div>';
	}

	/** Manager / front desk: sheets that should have been submitted. */
	protected static function todo_html( $classes ) {
		$items = array();
		foreach ( $classes as $c ) {
			foreach ( self::missing( $c->ID ) as $x ) {
				$items[] = '<li><a href="' . esc_url( self::url( array( 'zc' => $c->ID, 'zs' => $x['date'] ) ) ) . '">' . esc_html( SDSC_Schedule::class_name( $c->ID ) . ' — session ' . $x['n'] . ', ' . wp_date( 'D M j', SDSC_Lessons::ts( $x['start'] ) ) ) . '</a> <span class="szr-m">' . esc_html( 'Instructor: ' . self::who( self::instructor_of( $c->ID ) ) ) . '</span></li>';
			}
		}
		if ( ! $items ) { return '<p class="szr-ok">Every attendance sheet up to today has been submitted.</p>'; }
		return '<details class="szr-todo" open><summary>Sheets still to submit (' . count( $items ) . ')</summary><ul>' . implode( '', array_slice( $items, 0, 40 ) ) . '</ul></details>';
	}

	protected static function sheet_html( $cid, $list, $sess, $today, $done ) {
		$x = null;
		foreach ( $list as $y ) { if ( $y['date'] === $sess ) { $x = $y; } }
		$people = SDSC_Schedule::roster( $cid );
		$rows   = self::rows( $cid );
		$zoom   = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		$h      = '<section class="szr-card"><div class="szr-head"><div><b>Session ' . (int) $x['n'] . ' of ' . count( $list ) . '</b> · ' . esc_html( SDSC_Lessons::when( $x['start'], 'en' ) ) . ( 1 === (int) $x['n'] ? ' <span class="szr-tag">Mandatory (MVA)</span>' : '' ) . '</div>';
		if ( $zoom ) { $h .= '<a class="szr-btn szr-btn--ghost" href="' . esc_url( $zoom ) . '" target="_blank" rel="noopener">Open Zoom</a>'; }
		$h .= '</div>';
		if ( ! empty( $done[ $sess ] ) ) {
			$h .= '<p class="szr-m">Submitted by ' . esc_html( self::who( $done[ $sess ][0] ) ) . ' · ' . esc_html( SDSC_Lessons::when( $done[ $sess ][1], 'en' ) ) . '. You can still correct it.</p>';
		}
		if ( ! $people ) { return $h . '<p class="szr-m">No students are registered for this class yet.</p></section>'; }
		if ( $sess > $today ) { return $h . '<p class="szr-m">This session has not happened yet. The checklist opens on the day of the class.</p></section>'; }

		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="szr-form">'
			. wp_nonce_field( 'sdsc_zr_' . $cid . '_' . $sess, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="sdsc_zr_save"><input type="hidden" name="class" value="' . (int) $cid . '"><input type="hidden" name="s" value="' . esc_attr( $sess ) . '">'
			. '<p class="szr-tools"><button type="button" class="szr-btn szr-btn--ghost" data-szr-all="present">Mark everyone present</button> <span class="szr-m">then change the few who were late, left early or absent.</span></p><ul class="szr-list">';
		foreach ( $people as $p ) {
			$r   = $rows[ $p['email'] ][ $sess ] ?? null;
			$cur = $r['actual'] ?? '';
			$k   = md5( $p['email'] );
			list( $used, $miss1 ) = SDSC_Schedule::absences( $cid, $p['email'] );
			$rs  = $r && 'yes' === $r['rsvp'] ? '<span class="szr-rsvp is-yes" title="Confirmed by the student">✓ said yes</span>' : ( $r && 'no' === $r['rsvp'] ? '<span class="szr-rsvp is-no" title="' . esc_attr( $r['note'] ) . '">✗ said they can’t</span>' : '' );
			$h  .= '<li class="szr-row"><div class="szr-who"><b>' . esc_html( trim( $p['first'] . ' ' . $p['last'] ) ) . '</b> ' . $rs
				. '<span class="szr-m">Absences ' . (int) $used . '/' . (int) SDSC_Schedule::ALLOWED . ( $miss1 ? ' · missed session 1' : '' ) . '</span></div>'
				. '<input type="hidden" name="e[' . esc_attr( $k ) . ']" value="' . esc_attr( $p['email'] ) . '"><div class="szr-seg" role="radiogroup" aria-label="' . esc_attr( trim( $p['first'] . ' ' . $p['last'] ) ) . '">';
			foreach ( self::STATES as $st ) {
				$h .= '<label class="szr-o szr-o--' . $st . '"><input type="radio" name="m[' . esc_attr( $k ) . ']" value="' . $st . '"' . checked( $cur, $st, false ) . '><span>' . esc_html( self::label( $st ) ) . '</span></label>';
			}
			$h .= '</div><input class="szr-note" type="text" maxlength="200" name="n[' . esc_attr( $k ) . ']" value="' . esc_attr( $r['staff_note'] ?? '' ) . '" placeholder="Note (optional): camera off, joined at 6:20…"></li>';
		}
		return $h . '</ul><p><button class="szr-btn">Submit attendance for session ' . (int) $x['n'] . '</button></p></form></section>';
	}

	/** Every student × every session, read-only. */
	protected static function grid_html( $cid, $list, $today, $done ) {
		$people = SDSC_Schedule::roster( $cid );
		if ( ! $people ) { return ''; }
		$rows = self::rows( $cid );
		$sym  = array( 'present' => '✓', 'late' => 'L', 'left' => 'E', 'absent' => '✗' );
		$h    = '<section class="szr-card"><b>All ' . count( $list ) . ' sessions</b> <span class="szr-m">✓ present · L late · E left early · ✗ absent · ? not marked</span><div class="szr-scroll"><table class="szr-grid"><thead><tr><th>Student</th>';
		foreach ( $list as $x ) { $h .= '<th title="' . esc_attr( $x['date'] ) . '">' . (int) $x['n'] . '</th>'; }
		$h .= '<th>Attended</th></tr></thead><tbody>';
		foreach ( $people as $p ) {
			$att = 0;
			$h  .= '<tr><td>' . esc_html( trim( $p['first'] . ' ' . $p['last'] ) ) . '</td>';
			foreach ( $list as $x ) {
				$a = $rows[ $p['email'] ][ $x['date'] ]['actual'] ?? '';
				if ( in_array( $a, array( 'present', 'late', 'left' ), true ) ) { $att++; }
				$t  = $a ? self::label( $a ) : ( $x['date'] <= $today ? 'Not marked' : 'Upcoming' );
				$h .= '<td class="szr-c szr-c--' . ( $a ?: ( $x['date'] <= $today ? 'none' : 'up' ) ) . '" title="' . esc_attr( $t ) . '">' . ( $a ? $sym[ $a ] : ( $x['date'] <= $today ? '?' : '·' ) ) . '</td>';
			}
			$h .= '<td><b>' . (int) $att . '/' . count( $list ) . '</b></td></tr>';
		}
		return $h . '</tbody></table></div></section>';
	}

	/* ------------------------------------------------------------ save */

	public static function h_save() {
		$cid  = (int) ( $_POST['class'] ?? 0 );
		$sess = sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) );
		if ( ! self::can_class( $cid ) ) { wp_die( 'Not allowed.', 403 ); }
		check_admin_referer( 'sdsc_zr_' . $cid . '_' . $sess );
		if ( ! in_array( $sess, wp_list_pluck( SDSC_Schedule::sessions( $cid ), 'date' ), true ) || $sess > current_time( 'Y-m-d' ) ) { wp_die( 'This session cannot be marked yet.' ); }

		$roster = SDSC_Schedule::roster( $cid );
		$emails = (array) wp_unslash( $_POST['e'] ?? array() ); // phpcs:ignore -- checked against the roster
		$marks  = (array) wp_unslash( $_POST['m'] ?? array() ); // phpcs:ignore -- whitelisted
		$notes  = (array) wp_unslash( $_POST['n'] ?? array() ); // phpcs:ignore -- sanitised
		$now    = current_time( 'mysql' );
		$n      = 0;
		foreach ( $emails as $k => $email ) {
			$email = strtolower( sanitize_email( $email ) );
			if ( ! isset( $roster[ $email ] ) || md5( $email ) !== $k ) { continue; }
			$st   = (string) ( $marks[ $k ] ?? '' );
			$note = mb_substr( sanitize_text_field( (string) ( $notes[ $k ] ?? '' ) ), 0, 200 );
			if ( ! in_array( $st, self::STATES, true ) ) { $st = ''; }
			$r = SDSC_Schedule::row( 'zoom', $cid, $sess, $email );
			if ( ! $r && '' === $st && '' === $note ) { continue; }
			if ( $r && $r['actual'] === $st && (string) ( $r['staff_note'] ?? '' ) === $note ) { if ( $st ) { $n++; } continue; }
			SDSC_Schedule::put( 'zoom', $cid, $sess, $email, array( 'actual' => $st, 'actual_by' => get_current_user_id(), 'actual_at' => $now, 'staff_note' => $note ) );
			if ( $st ) { $n++; }
		}
		$done          = self::done( $cid );
		$done[ $sess ] = array( get_current_user_id(), $now );
		update_post_meta( $cid, self::DONE, $done );
		$left = count( $roster ) - $n;
		$msg  = 'Attendance saved: ' . $n . ' of ' . count( $roster ) . ' students marked' . ( $left > 0 ? ' (' . $left . ' still without a mark).' : '.' );
		$back = wp_get_referer();
		$back = $back && false === strpos( $back, '/wp-admin/' ) ? $back : self::url( array( 'zc' => $cid, 'zs' => $sess ) );
		wp_safe_redirect( add_query_arg( 'szr_msg', rawurlencode( $msg ), remove_query_arg( 'szr_msg', $back ) ) );
		exit;
	}

	/* ------------------------------------------------------------ student strip */

	/** 10-dot strip for one class on the student's dashboard. */
	public static function student_strip( $cid, $email, $lang ) {
		$es    = 'es' === $lang;
		$list  = SDSC_Schedule::sessions( $cid );
		$rows  = self::rows( $cid )[ strtolower( $email ) ] ?? array();
		$today = current_time( 'Y-m-d' );
		$lab   = $es
			? array( 'present' => 'Presente', 'late' => 'Llegó tarde', 'left' => 'Salió temprano', 'absent' => 'Ausente' )
			: array( 'present' => 'Present', 'late' => 'Late', 'left' => 'Left early', 'absent' => 'Absent' );
		$sym   = array( 'present' => '✓', 'late' => 'L', 'left' => 'E', 'absent' => '✗' );
		$att   = 0;
		$h     = '<ol class="szr-dots">';
		foreach ( $list as $x ) {
			$a = $rows[ $x['date'] ]['actual'] ?? '';
			if ( in_array( $a, array( 'present', 'late', 'left' ), true ) ) { $att++; }
			$t  = $a ? $lab[ $a ] : ( $x['date'] <= $today ? ( $es ? 'Pendiente' : 'Not recorded yet' ) : ( $es ? 'Próxima' : 'Upcoming' ) );
			$h .= '<li class="szr-c szr-c--' . ( $a ?: ( $x['date'] <= $today ? 'none' : 'up' ) ) . '" title="' . esc_attr( $x['n'] . ' · ' . $x['date'] . ' · ' . $t ) . '"><b>' . (int) $x['n'] . '</b><span>' . ( $a ? $sym[ $a ] : ( $x['date'] <= $today ? '?' : '·' ) ) . '</span></li>';
		}
		$h .= '</ol><p class="sdl-m">' . esc_html( ( $es ? 'Clases asistidas: ' : 'Sessions attended: ' ) . $att . ' / ' . count( $list ) ) . '</p>';
		return $h;
	}
}
