<?php
/**
 * Lobby TV — the screen in the school lobby, and its remote control for the front desk, Saghar and admin.
 *
 * The TV reads today's driving lessons from DriveFlow and works out each lesson's state from the clock,
 * so it is always right even when nobody presses a button:
 *   Awaiting class start → Ready to start class (15 min before) → On the road → Lesson finished,
 *   or Cancelled / No-show.
 * Student names are shortened ("Sara K.") because the lobby is public. Cars show their last reported
 * fuel and go red when grounded. Messages for the lobby (broadcast + ticker), the view and a remote
 * reload are set from Class Manager → Lobby TV (or the dashboard workspace).
 *
 * Uses DriveFlow's own TV options, so DriveFlow's admin hub controls keep working too.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_TV {
	const OPT = 'sdsc_tv';
	const CAP = 'sdsc_access_tv';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 33 );
		add_action( 'admin_post_sdsc_tv_save', array( __CLASS__, 'save' ) );
	}

	public static function opts() {
		$o = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array( 'names' => 'initial', 'finished' => 1, 'rotate' => 20 ) );
	}

	public static function can() {
		return current_user_can( 'manage_options' ) || current_user_can( self::CAP ) || ( class_exists( 'SDSC_Access' ) && SDSC_Access::can( 'tv' ) );
	}

	public static function url() {
		$s  = get_option( 'driveflow_pro_settings', array() );
		$id = is_array( $s ) ? absint( $s['tv_page_id'] ?? 0 ) : 0;
		return $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : add_query_arg( 'driveflow_tv', '1', home_url( '/' ) );
	}

	/* ------------------------------------------------------------ data */

	protected static function short( $name, $mode ) {
		$p = preg_split( '/\s+/', trim( (string) $name ) );
		if ( ! $p || '' === $p[0] ) { return 'Student'; }
		if ( 'full' === $mode ) { return trim( $name ); }
		if ( 'first' === $mode || count( $p ) < 2 ) { return $p[0]; }
		return $p[0] . ' ' . mb_strtoupper( mb_substr( end( $p ), 0, 1 ) ) . '.';
	}

	/** Lesson state from the clock. */
	public static function state( $status, $a, $b, $now ) {
		$st = strtolower( (string) $status );
		if ( in_array( $st, array( 'cancelled', 'canceled' ), true ) ) { return array( 'cancelled', 'Cancelled', 'red' ); }
		if ( in_array( $st, array( 'no_show', 'no-show', 'noshow' ), true ) ) { return array( 'no_show', 'No-show', 'red' ); }
		if ( 'completed' === $st || $now >= $b ) { return array( 'finished', 'Lesson finished', 'done' ); }
		if ( $now >= $a ) { return array( 'on_road', 'On the road', 'green' ); }
		if ( $now >= $a - 15 * MINUTE_IN_SECONDS ) { return array( 'ready', 'Ready to start class', 'yellow' ); }
		return array( 'waiting', 'Awaiting class start', 'blue' );
	}

	public static function data( $demo = false ) {
		global $wpdb;
		$p   = $wpdb->prefix . 'driveflow_';
		$o   = self::opts();
		$now = current_time( 'timestamp' ); // phpcs:ignore
		$day = current_time( 'Y-m-d' );
		$out = array( 'now' => $now, 'day' => date_i18n( 'l, F j', $now ), 'lessons' => array(), 'cars' => array(), 'instructors' => array() );
		$t   = $p . 'sessions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return $out; } // phpcs:ignore
		$demo_sql = $demo ? '' : " AND s.lesson_topic NOT LIKE '%(demo)'";
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.student_id, s.student_name, s.instructor_name, s.session_number, s.scheduled_start, s.scheduled_end, s.status, s.plate_number, d.total_sessions FROM $t s LEFT JOIN {$p}students d ON d.id = s.student_id WHERE DATE(s.scheduled_start) = %s $demo_sql ORDER BY s.scheduled_start ASC", $day ), ARRAY_A ); // phpcs:ignore
		$cars     = array();
		foreach ( (array) $wpdb->get_results( "SELECT plate_number, model, color, status, current_fuel_level, last_fuel_report, maintenance_status FROM {$p}vehicles ORDER BY plate_number", ARRAY_A ) as $v ) { // phpcs:ignore
			if ( ! $demo && 'Demo car' === $v['model'] ) { continue; }
			$fresh = $v['last_fuel_report'] && strtotime( $v['last_fuel_report'] ) > $now - 7 * DAY_IN_SECONDS;
			$cars[ strtoupper( $v['plate_number'] ) ] = array(
				'plate' => strtoupper( $v['plate_number'] ), 'car' => trim( $v['color'] . ' ' . $v['model'] ),
				'fuel' => $fresh ? (int) $v['current_fuel_level'] : null,
				'down' => 'grounded' === $v['maintenance_status'] || 'maintenance' === $v['status'],
				'check' => in_array( $v['maintenance_status'], array( 'needs_inspection' ), true ),
				'active' => 'active' === $v['status'] || 'maintenance' === $v['status'],
			);
		}
		foreach ( (array) $rows as $r ) {
			$a = strtotime( $r['scheduled_start'] );
			$b = strtotime( $r['scheduled_end'] );
			list( $state, $label, $tone ) = self::state( $r['status'], $a, $b, $now );
			if ( 'finished' === $state && ! $o['finished'] && $now > $b + HOUR_IN_SECONDS ) { continue; }
			$pl = strtoupper( (string) $r['plate_number'] );
			$out['lessons'][] = array(
				'id' => (int) $r['id'], 'a' => $a, 'b' => $b, 'status' => $r['status'],
				'time' => date_i18n( 'g:i', $a ) . ' – ' . date_i18n( 'g:i A', $b ), 'start' => date_i18n( 'g:i A', $a ),
				'student' => self::short( $r['student_name'], $o['names'] ), 'instructor' => (string) $r['instructor_name'],
				'n' => (int) $r['session_number'], 'total' => (int) $r['total_sessions'],
				'plate' => $pl, 'car' => $cars[ $pl ]['car'] ?? '', 'fuel' => $cars[ $pl ]['fuel'] ?? null, 'down' => ! empty( $cars[ $pl ]['down'] ),
				'state' => $state, 'label' => $label, 'tone' => $tone,
			);
		}
		// Cars: who has each car now / next.
		foreach ( $cars as $pl => $c ) {
			if ( ! $c['active'] ) { continue; }
			$mine = array_values( array_filter( $out['lessons'], function ( $l ) use ( $pl ) { return $l['plate'] === $pl && ! in_array( $l['state'], array( 'cancelled', 'no_show' ), true ); } ) );
			$cur  = null; $next = null;
			foreach ( $mine as $l ) {
				if ( 'on_road' === $l['state'] ) { $cur = $l; }
				if ( in_array( $l['state'], array( 'waiting', 'ready' ), true ) && ! $next ) { $next = $l; }
			}
			$out['cars'][] = array_merge( $c, array( 'now' => $cur, 'next' => $next, 'count' => count( $mine ) ) );
		}
		// Instructors with lessons today.
		$ins = array();
		foreach ( $out['lessons'] as $l ) {
			if ( '' === $l['instructor'] || in_array( $l['state'], array( 'cancelled', 'no_show' ), true ) ) { continue; }
			$k = $l['instructor'];
			if ( ! isset( $ins[ $k ] ) ) { $ins[ $k ] = array( 'name' => $k, 'now' => null, 'next' => null, 'done' => 0, 'total' => 0 ); }
			$ins[ $k ]['total']++;
			if ( 'finished' === $l['state'] ) { $ins[ $k ]['done']++; }
			if ( 'on_road' === $l['state'] ) { $ins[ $k ]['now'] = $l; }
			if ( in_array( $l['state'], array( 'waiting', 'ready' ), true ) && ! $ins[ $k ]['next'] ) { $ins[ $k ]['next'] = $l; }
		}
		$out['instructors'] = array_values( $ins );
		return $out;
	}

	public static function board() {
		$alert = get_option( 'driveflow_tv_broadcast_alert', null );
		$tick  = get_option( 'driveflow_tv_ticker_items', array() );
		if ( empty( $tick ) ) { $tick = array( 'Welcome to Sam’s Driving School', 'Please have your Maryland learner’s permit ready', 'English · Español · فارسی — male and female instructors' ); }
		$mode = (string) get_option( 'driveflow_tv_view_mode', 'slots' );
		return array(
			'alert'  => is_array( $alert ) && ! empty( $alert['text'] ) ? array( 'text' => $alert['text'], 'level' => $alert['level'] ?? 'info' ) : null,
			'ticker' => array_values( array_filter( array_map( 'strval', (array) $tick ) ) ),
			'mode'   => in_array( $mode, array( 'slots', 'vehicles', 'instructors', 'rotate' ), true ) ? $mode : 'slots',
			'reload' => (int) get_option( 'driveflow_tv_reload_ts', 0 ),
			'rotate' => max( 10, (int) self::opts()['rotate'] ),
		);
	}

	public static function rest() {
		register_rest_route( 'sdsc/v1', '/tv', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true', // lobby screen: no login; names are shortened
			'callback'            => function ( $req ) {
				nocache_headers();
				$demo = ! empty( $req['demo'] ) && is_user_logged_in() && ( get_user_meta( get_current_user_id(), 'sdsc_preview', true ) || current_user_can( 'manage_options' ) );
				return rest_ensure_response( array_merge( self::data( $demo ), self::board() ) );
			},
		) );
	}

	/* ------------------------------------------------------------ TV screen (inside DriveFlow's kiosk page) */

	public static function render() {
		$s    = get_option( 'driveflow_pro_settings', array() );
		$logo = is_array( $s ) && ! empty( $s['logo_url'] ) ? $s['logo_url'] : '';
		$demo = is_user_logged_in() && get_user_meta( get_current_user_id(), 'sdsc_preview', true );
		$cfg  = array( 'api' => esc_url_raw( add_query_arg( $demo ? array( 'demo' => 1 ) : array(), rest_url( 'sdsc/v1/tv' ) ) ), 'first' => array_merge( self::data( (bool) $demo ), self::board() ) );
		$ver  = defined( 'SDSC_VERSION' ) ? SDSC_VERSION : '1';
		ob_start();
		?>
<link rel="stylesheet" href="<?php echo esc_url( SDSC_URL . 'assets/tv.css?ver=' . $ver ); ?>">
<div id="stv" class="stv" data-view="slots">
	<canvas class="stv-stars" aria-hidden="true"></canvas>
	<div class="stv-alert" hidden role="alert"><span class="stv-alert-i" aria-hidden="true">📢</span><p></p></div>
	<header class="stv-top">
		<div class="stv-brand"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php else : ?><span class="stv-mark">S</span><?php endif; ?><div><b>Sam’s Driving School</b><small>Today’s driving lessons</small></div></div>
		<div class="stv-mid"><span class="stv-live"><i></i>LIVE</span><span class="stv-viewname">Lessons</span></div>
		<div class="stv-clock"><b class="stv-time">--:--</b><small class="stv-date"></small></div>
	</header>
	<section class="stv-stats" aria-label="Today"></section>
	<main class="stv-body"></main>
	<footer class="stv-ticker" aria-label="Announcements"><div class="stv-track"></div></footer>
	<button type="button" class="stv-fs" aria-label="Full screen">⛶</button>
</div>
<script>window.STV=<?php echo wp_json_encode( $cfg ); ?>;</script>
<script src="<?php echo esc_url( SDSC_URL . 'assets/tv.js?ver=' . $ver ); ?>"></script>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------ remote control (office) */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Lobby TV', 'Lobby TV', self::CAP, 'sdsc-tv', array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_tv' );
		$do   = sanitize_key( $_POST['do'] ?? '' );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=sdsc-tv' );
		$msg  = 'Saved.';
		if ( 'alert' === $do ) {
			$text = sanitize_textarea_field( wp_unslash( $_POST['alert_text'] ?? '' ) );
			$lvl  = in_array( $_POST['alert_level'] ?? '', array( 'info', 'warning', 'urgent' ), true ) ? sanitize_key( $_POST['alert_level'] ) : 'info';
			if ( '' === trim( $text ) ) { delete_option( 'driveflow_tv_broadcast_alert' ); $msg = 'Message removed from the TV.'; }
			else { update_option( 'driveflow_tv_broadcast_alert', array( 'text' => $text, 'level' => $lvl, 'timestamp' => current_time( 'timestamp' ), 'active' => true ) ); $msg = 'Message is on the TV.'; } // phpcs:ignore
		} elseif ( 'clear' === $do ) {
			delete_option( 'driveflow_tv_broadcast_alert' );
			$msg = 'Message removed from the TV.';
		} elseif ( 'ticker' === $do ) {
			$lines = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( "\n", (string) wp_unslash( $_POST['ticker'] ?? '' ) ) ) ) ) );
			update_option( 'driveflow_tv_ticker_items', $lines );
			$msg = 'Ticker updated.';
		} elseif ( 'display' === $do ) {
			$mode = in_array( $_POST['mode'] ?? '', array( 'slots', 'vehicles', 'instructors', 'rotate' ), true ) ? sanitize_key( $_POST['mode'] ) : 'slots';
			update_option( 'driveflow_tv_view_mode', $mode );
			update_option( self::OPT, array(
				'names'    => in_array( $_POST['names'] ?? '', array( 'initial', 'first', 'full' ), true ) ? sanitize_key( $_POST['names'] ) : 'initial',
				'finished' => empty( $_POST['finished'] ) ? 0 : 1,
				'rotate'   => max( 10, min( 120, absint( $_POST['rotate'] ?? 20 ) ) ),
			), false );
			$msg = 'Display settings saved.';
		} elseif ( 'reload' === $do ) {
			update_option( 'driveflow_tv_reload_ts', time() );
			$msg = 'All lobby TVs will reload within 15 seconds.';
		}
		wp_safe_redirect( add_query_arg( 'tvmsg', rawurlencode( $msg ), $back ) );
		exit;
	}

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		$b   = self::board();
		$o   = self::opts();
		$a   = get_option( 'driveflow_tv_broadcast_alert', array() );
		$url = self::url();
		$f   = function ( $do ) { return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_tv_save"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">' . wp_nonce_field( 'sdsc_tv', '_wpnonce', true, false ); };
		echo '<div class="wrap sdsc-tvc"><h1>Lobby TV</h1>';
		if ( isset( $_GET['tvmsg'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['tvmsg'] ) ) ) . '</p></div>'; } // phpcs:ignore
		echo '<style>.sdsc-tvc .g{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:20px;align-items:start;max-width:1300px}.sdsc-tvc .c{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px 18px;margin:0 0 16px}.sdsc-tvc h2{margin:0 0 10px;font-size:16px}.sdsc-tvc .pv{position:relative;width:100%;aspect-ratio:16/9;border-radius:10px;overflow:hidden;background:#020810}.sdsc-tvc .pv iframe{position:absolute;left:0;top:0;width:1920px;height:1080px;border:0;transform-origin:0 0}.sdsc-tvc textarea,.sdsc-tvc input[type=text]{width:100%}.sdsc-tvc .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px}@media(max-width:1100px){.sdsc-tvc .g{grid-template-columns:1fr}}</style>';
		echo '<p>Open this address on the lobby TV (full screen). It updates by itself; nothing else is needed. <code>' . esc_html( $url ) . '</code> <a class="button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Open the TV</a></p>';
		echo '<div class="g"><div><div class="c"><h2>What the TV shows right now</h2><div class="pv"><iframe src="' . esc_url( $url ) . '" title="Lobby TV preview" loading="lazy" tabindex="-1"></iframe></div>';
		echo $f( 'reload' ) . '<div class="row"><button class="button">Reload all lobby TVs</button><span class="description">Use after changing the TV page or if a screen looks stuck.</span></div></form></div></div><div>'; // phpcs:ignore
		echo '<div class="c"><h2>Message on the TV</h2>' . $f( 'alert' ) . '<textarea name="alert_text" rows="3" placeholder="e.g. The 3 PM lesson with Sara is delayed 15 minutes.">' . esc_textarea( $a['text'] ?? '' ) . '</textarea><div class="row"><select name="alert_level"><option value="info"' . selected( $a['level'] ?? '', 'info', false ) . '>Information (blue)</option><option value="warning"' . selected( $a['level'] ?? '', 'warning', false ) . '>Notice (yellow)</option><option value="urgent"' . selected( $a['level'] ?? '', 'urgent', false ) . '>Urgent (red)</option></select><button class="button button-primary">Show on the TV</button></div></form>'; // phpcs:ignore
		if ( $b['alert'] ) { echo $f( 'clear' ) . '<div class="row"><button class="button">Remove the message</button></div></form>'; } // phpcs:ignore
		echo '</div>';
		echo '<div class="c"><h2>Running ticker (one line each)</h2>' . $f( 'ticker' ) . '<textarea name="ticker" rows="5">' . esc_textarea( implode( "\n", $b['ticker'] ) ) . '</textarea><div class="row"><button class="button">Save ticker</button></div></form></div>'; // phpcs:ignore
		echo '<div class="c"><h2>Display</h2>' . $f( 'display' ) // phpcs:ignore
			. '<p><label>View <select name="mode"><option value="slots"' . selected( $b['mode'], 'slots', false ) . '>Lessons</option><option value="vehicles"' . selected( $b['mode'], 'vehicles', false ) . '>Cars</option><option value="instructors"' . selected( $b['mode'], 'instructors', false ) . '>Instructors</option><option value="rotate"' . selected( $b['mode'], 'rotate', false ) . '>Rotate all three</option></select></label> &nbsp; <label>every <input type="number" name="rotate" min="10" max="120" value="' . (int) $o['rotate'] . '" style="width:70px"> seconds</label></p>'
			. '<p><label>Student names <select name="names"><option value="initial"' . selected( $o['names'], 'initial', false ) . '>First name + initial (Sara K.)</option><option value="first"' . selected( $o['names'], 'first', false ) . '>First name only</option><option value="full"' . selected( $o['names'], 'full', false ) . '>Full name (not recommended — the lobby is public)</option></select></label></p>'
			. '<p><label><input type="checkbox" name="finished" value="1"' . checked( ! empty( $o['finished'] ), true, false ) . '> Keep finished lessons on the screen (otherwise they leave an hour after they end)</label></p>'
			. '<div class="row"><button class="button button-primary">Save display</button></div></form></div>';
		echo '</div></div></div>';
		echo '<script>(function(){var w=document.querySelector(".sdsc-tvc .pv"),f=w&&w.querySelector("iframe");function s(){if(f)f.style.transform="scale("+(w.clientWidth/1920)+")";}s();window.addEventListener("resize",s);})();</script>';
	}
}
