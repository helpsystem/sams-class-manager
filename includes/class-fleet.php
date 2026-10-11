<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Instructors & cars — the behind-the-wheel board.
 *
 *  - Instructors and cars are added and removed by hand (Sam's School → Instructors & cars).
 *    Each instructor has weekly working hours, a colour, languages and a usual car; each car has a plate.
 *    Time off (instructor) and out of service (car) block the board.
 *    "Remove" deletes an instructor or car that was never used; one with lessons on record is archived
 *    (hidden from the board, history kept, can be restored).
 *  - Day board: every instructor and every car side by side, hour by hour. Booked lessons are blocks;
 *    grey is outside working hours or time off; white is free. Click a free spot to book it there,
 *    drag a lesson to another time / instructor / car.
 *  - Week board: one row per instructor and car, one column per day: who they are with and how many free hours.
 *  - Free times: the next open slots for a lesson length, turned into a text to offer the student.
 *  - Students: which student is with which instructor (set on the first booking, changeable by hand).
 *  - Booking twice into the same instructor or car, or into time off, is stopped (with "book anyway").
 */
class SDSC_Fleet {
	const DBV  = '1';
	const DBO  = 'sdsc_fleet_db';
	const PAGE = 'sdsc-board';
	const STEP = 30;   // minutes between offered start times

	protected static $cache = null;

	public static function t_res() { global $wpdb; return $wpdb->prefix . 'sdsc_res'; }
	public static function t_off() { global $wpdb; return $wpdb->prefix . 'sdsc_res_off'; }
	public static function t_asg() { global $wpdb; return $wpdb->prefix . 'sdsc_assign'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 22 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 33 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'res_save', 'res_remove', 'res_restore', 'off_add', 'off_del', 'asg_save', 'asg_del' ) as $a ) {
			add_action( 'admin_post_sdsc_fl_' . $a, array( __CLASS__, 'h_' . $a ) );
		}
		add_action( 'wp_ajax_sdsc_fl_move', array( __CLASS__, 'ajax_move' ) );
	}

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t_res() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(12) NOT NULL DEFAULT 'instructor',
			name varchar(120) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			langs varchar(40) NOT NULL DEFAULT '',
			color varchar(9) NOT NULL DEFAULT '',
			plate varchar(20) NOT NULL DEFAULT '',
			model varchar(80) NOT NULL DEFAULT '',
			def_car bigint(20) unsigned NOT NULL DEFAULT 0,
			hours text NULL,
			note varchar(255) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 1,
			sort int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY kind (kind)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t_off() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			res_id bigint(20) unsigned NOT NULL DEFAULT 0,
			starts_at datetime NOT NULL,
			ends_at datetime NOT NULL,
			note varchar(190) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY res_id (res_id),
			KEY starts_at (starts_at)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t_asg() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			skey varchar(190) NOT NULL DEFAULT '',
			name varchar(160) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			instructor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note varchar(190) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY skey (skey),
			KEY instructor_id (instructor_id)
		) $c;" );
		// The old "Instructors (one per line)" setting becomes real instructors once.
		if ( class_exists( 'SDSC_Lessons' ) && ! $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_res() ) ) { // phpcs:ignore
			$names = array_filter( array_map( 'trim', explode( "\n", (string) ( get_option( SDSC_Lessons::SET, array() )['instructors'] ?? '' ) ) ) );
			foreach ( array_values( $names ) as $i => $n ) {
				$wpdb->insert( self::t_res(), array( 'kind' => 'instructor', 'name' => sanitize_text_field( $n ), 'color' => self::palette()[ $i % 12 ], 'hours' => wp_json_encode( self::default_hours() ), 'sort' => $i, 'created_at' => current_time( 'mysql' ) ) );
			}
		}
		update_option( self::DBO, self::DBV, false );
	}

	/* ================================================================ data */

	public static function palette() {
		return array( '#2563eb', '#16a34a', '#d97706', '#db2777', '#7c3aed', '#0891b2', '#dc2626', '#4d7c0f', '#9333ea', '#0f766e', '#b45309', '#be123c' );
	}

	/** weekday (0 = Sunday) => "HH:MM-HH:MM" or "" (off). */
	public static function default_hours() {
		return array( '', '08:00-19:00', '08:00-19:00', '08:00-19:00', '08:00-19:00', '08:00-19:00', '08:00-17:00' );
	}

	/** All instructors and cars (archived too), keyed by id. */
	public static function all() {
		if ( null !== self::$cache ) { return self::$cache; }
		global $wpdb;
		self::$cache = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::t_res() . ' ORDER BY kind, sort, name', ARRAY_A ) as $r ) { // phpcs:ignore
			$h = json_decode( (string) $r['hours'], true );
			$r['hours'] = is_array( $h ) && 7 === count( $h ) ? array_values( $h ) : self::default_hours();
			$r['langs'] = array_filter( explode( ',', (string) $r['langs'] ) );
			self::$cache[ (int) $r['id'] ] = $r;
		}
		return self::$cache;
	}

	public static function res( $id ) { return self::all()[ (int) $id ] ?? null; }

	public static function list_of( $kind, $active = true ) {
		return array_filter( self::all(), function ( $r ) use ( $kind, $active ) {
			return $r['kind'] === $kind && ( ! $active || (int) $r['active'] );
		} );
	}

	public static function instructors() { return self::list_of( 'instructor' ); }
	public static function cars() { return self::list_of( 'car' ); }

	public static function label( $r ) {
		if ( ! $r ) { return ''; }
		if ( 'car' === $r['kind'] ) { return trim( $r['name'] . ( $r['plate'] ? ' · ' . $r['plate'] : '' ) ); }
		return $r['name'];
	}

	public static function lang_names() { return array( 'en' => 'English', 'es' => 'Spanish', 'fa' => 'Farsi' ); }

	/** "HH:MM" -> minutes. */
	protected static function m( $hm ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', (string) $hm, $x ) ) { return null; }
		return (int) $x[1] * 60 + (int) $x[2];
	}

	/** minutes -> "1:30p" style. */
	public static function hm( $min, $long = false ) {
		$h = intdiv( (int) $min, 60 ) % 24;
		$i = (int) $min % 60;
		$ap = $h >= 12 ? ( $long ? ' PM' : 'p' ) : ( $long ? ' AM' : 'a' );
		$h12 = $h % 12 ?: 12;
		return $h12 . ( $i || $long ? ':' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) : '' ) . $ap;
	}

	/** Working window of a resource on a date: [from, to] in minutes, or null when off. */
	public static function window( $r, $date ) {
		$w = (int) gmdate( 'w', strtotime( $date . ' 12:00:00 UTC' ) );
		$v = (string) ( $r['hours'][ $w ] ?? '' );
		if ( ! preg_match( '/^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', $v, $x ) ) { return null; }
		$a = self::m( $x[1] );
		$b = self::m( $x[2] );
		return $b > $a ? array( $a, $b ) : null;
	}

	/** Lessons that hold a time slot between two dates (Y-m-d, inclusive). */
	public static function lessons( $from, $to ) {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Lessons' ) ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . " WHERE status IN ('booked','done','no_show','no_permit') AND starts_at >= %s AND starts_at < %s ORDER BY starts_at", $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' 12:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00' ), ARRAY_A ); // phpcs:ignore
		// Older lessons only have the instructor's name.
		$by = array();
		foreach ( self::list_of( 'instructor', false ) as $r ) { $by[ strtolower( $r['name'] ) ] = (int) $r['id']; }
		foreach ( $rows as &$l ) {
			$l['instructor_id'] = (int) ( $l['instructor_id'] ?? 0 );
			$l['car_id']        = (int) ( $l['car_id'] ?? 0 );
			if ( ! $l['instructor_id'] && $l['instructor'] && isset( $by[ strtolower( $l['instructor'] ) ] ) ) { $l['instructor_id'] = $by[ strtolower( $l['instructor'] ) ]; }
			$l['_d'] = substr( $l['starts_at'], 0, 10 );
			$l['_a'] = self::m( substr( $l['starts_at'], 11, 5 ) );
			$l['_b'] = $l['_a'] + (int) $l['minutes'];
		}
		return $rows;
	}

	/** Time off / out of service touching the dates. */
	public static function offs( $from, $to, $res_id = 0 ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::t_off() . ' WHERE starts_at < %s AND ends_at > %s' . ( $res_id ? ' AND res_id = %d' : '' ) . ' ORDER BY starts_at';
		$a   = array( gmdate( 'Y-m-d', strtotime( $to . ' 12:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00', $from . ' 00:00:00' );
		if ( $res_id ) { $a[] = (int) $res_id; }
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $a ), ARRAY_A ); // phpcs:ignore
	}

	/** The part of an off-period that falls on one date, in minutes [a, b], or null. */
	protected static function clip( $o, $date ) {
		$d0 = $date . ' 00:00:00';
		$d1 = gmdate( 'Y-m-d', strtotime( $date . ' 12:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00';
		if ( $o['ends_at'] <= $d0 || $o['starts_at'] >= $d1 ) { return null; }
		$a = $o['starts_at'] <= $d0 ? 0 : self::m( substr( $o['starts_at'], 11, 5 ) );
		$b = $o['ends_at'] >= $d1 ? 1440 : self::m( substr( $o['ends_at'], 11, 5 ) );
		return $b > $a ? array( $a, $b ) : null;
	}

	/** Intervals list minus another list. */
	protected static function minus( $base, $cut ) {
		foreach ( $cut as $c ) {
			$out = array();
			foreach ( $base as $b ) {
				if ( $c[1] <= $b[0] || $c[0] >= $b[1] ) { $out[] = $b; continue; }
				if ( $c[0] > $b[0] ) { $out[] = array( $b[0], $c[0] ); }
				if ( $c[1] < $b[1] ) { $out[] = array( $c[1], $b[1] ); }
			}
			$base = $out;
		}
		return $base;
	}

	/** Busy intervals of one resource on a date (lessons + off). */
	protected static function busy( $r, $date, $lessons, $offs ) {
		$k   = 'car' === $r['kind'] ? 'car_id' : 'instructor_id';
		$out = array();
		foreach ( $lessons as $l ) { if ( $l['_d'] === $date && (int) $l[ $k ] === (int) $r['id'] ) { $out[] = array( $l['_a'], $l['_b'] ); } }
		foreach ( $offs as $o ) { if ( (int) $o['res_id'] === (int) $r['id'] && ( $c = self::clip( $o, $date ) ) ) { $out[] = $c; } }
		return $out;
	}

	/** Free intervals of one resource on a date. Cars have no hours: the school's open hours are used (7a–9p). */
	public static function free( $r, $date, $lessons, $offs ) {
		$w = 'car' === $r['kind'] ? array( 7 * 60, 21 * 60 ) : self::window( $r, $date );
		if ( ! $w ) { return array(); }
		return self::minus( array( $w ), self::busy( $r, $date, $lessons, $offs ) );
	}

	protected static function sum( $iv ) { $n = 0; foreach ( $iv as $i ) { $n += $i[1] - $i[0]; } return $n; }

	public static function hours_txt( $min ) {
		$h = round( $min / 60, 1 );
		return ( floor( $h ) == $h ? (int) $h : $h ) . 'h';
	}

	/**
	 * Problems with putting a lesson at this time with this instructor and car.
	 * $start = 'Y-m-d H:i:s' (site time). Returns a list of sentences (empty = fine).
	 */
	public static function conflicts( $start, $minutes, $ins_id, $car_id, $exclude = 0 ) {
		$date = substr( $start, 0, 10 );
		$a    = self::m( substr( $start, 11, 5 ) );
		$b    = $a + (int) $minutes;
		$ls   = array_filter( self::lessons( $date, $date ), function ( $l ) use ( $exclude ) { return (int) $l['id'] !== (int) $exclude && 'booked' === $l['status']; } );
		$offs = self::offs( $date, $date );
		$out  = array();
		foreach ( array( 'instructor_id' => $ins_id, 'car_id' => $car_id ) as $k => $rid ) {
			$r = $rid ? self::res( $rid ) : null;
			if ( ! $r ) { continue; }
			$who = self::label( $r );
			foreach ( $ls as $l ) {
				if ( (int) $l[ $k ] === (int) $rid && $l['_a'] < $b && $l['_b'] > $a ) {
					$out[] = $who . ' already has ' . trim( $l['first_name'] . ' ' . $l['last_name'] ) . ' from ' . self::hm( $l['_a'] ) . ' to ' . self::hm( $l['_b'] ) . '.';
				}
			}
			foreach ( $offs as $o ) {
				if ( (int) $o['res_id'] !== (int) $rid ) { continue; }
				$c = self::clip( $o, $date );
				if ( $c && $c[0] < $b && $c[1] > $a ) {
					$out[] = $who . ( 'car' === $r['kind'] ? ' is out of service' : ' is off' ) . ( $o['note'] ? ' (' . $o['note'] . ')' : '' ) . '.';
				}
			}
			if ( 'instructor' === $r['kind'] ) {
				$w = self::window( $r, $date );
				if ( ! $w ) { $out[] = $who . ' does not work on ' . wp_date( 'l', strtotime( $date . ' 12:00:00' ) ) . 's.'; }
				elseif ( $a < $w[0] || $b > $w[1] ) { $out[] = 'This is outside ' . $who . '’s hours (' . self::hm( $w[0] ) . '–' . self::hm( $w[1] ) . ').'; }
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ---------------- students ↔ instructors */

	public static function skey( $email, $phone ) {
		$e = strtolower( trim( (string) $email ) );
		if ( $e ) { return $e; }
		$d = preg_replace( '/\D/', '', (string) $phone );
		return $d ? 'tel:' . substr( $d, -10 ) : '';
	}

	public static function assignment( $email, $phone ) {
		global $wpdb;
		$k = self::skey( $email, $phone );
		return $k ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_asg() . ' WHERE skey = %s', $k ), ARRAY_A ) : null; // phpcs:ignore
	}

	/** Set (or change) the student's instructor. $only_new: keep an existing choice. */
	public static function assign( $email, $phone, $name, $ins_id, $only_new = false ) {
		global $wpdb;
		$k = self::skey( $email, $phone );
		if ( ! $k || ! $ins_id ) { return; }
		$row = self::assignment( $email, $phone );
		if ( $row && $only_new && (int) $row['instructor_id'] ) { return; }
		$d = array( 'name' => $name, 'email' => strtolower( (string) $email ), 'phone' => (string) $phone, 'instructor_id' => (int) $ins_id, 'created_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql' ) );
		if ( $row ) { $wpdb->update( self::t_asg(), $d, array( 'id' => $row['id'] ) ); }
		else { $wpdb->insert( self::t_asg(), array_merge( $d, array( 'skey' => $k ) ) ); }
	}

	/** Instructor of every student we know, for the booking form ({skey: id}). */
	public static function assignment_map() {
		global $wpdb;
		$m = array();
		foreach ( (array) $wpdb->get_results( 'SELECT skey, instructor_id FROM ' . self::t_asg(), ARRAY_A ) as $r ) { $m[ $r['skey'] ] = (int) $r['instructor_id']; } // phpcs:ignore
		return $m;
	}

	/* ---------------- form pieces used by the booking form */

	public static function select_html( $kind, $current, $name ) {
		$list = self::list_of( $kind );
		$cur  = self::res( $current );
		if ( $cur && ! (int) $cur['active'] ) { $list[ (int) $cur['id'] ] = $cur; }
		$h = '<select name="' . esc_attr( $name ) . '" class="sdl-' . esc_attr( $kind ) . '"><option value="0">' . ( 'car' === $kind ? '— no car yet —' : '— not assigned yet —' ) . '</option>';
		foreach ( $list as $r ) {
			$extra = 'instructor' === $kind && $r['langs'] ? ' (' . implode( ', ', array_map( function ( $x ) { return self::lang_names()[ $x ] ?? $x; }, $r['langs'] ) ) . ')' : '';
			$h .= '<option value="' . (int) $r['id'] . '" data-car="' . (int) $r['def_car'] . '"' . selected( (int) $current, (int) $r['id'], false ) . '>' . esc_html( self::label( $r ) . $extra ) . '</option>';
		}
		return $h . '</select>';
	}

	/* ================================================================ admin */

	public static function can() {
		return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'board' ) : current_user_can( 'manage_options' );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Instructors & cars', 'Instructors & cars', 'sdsc_access_board', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) { return; }
		wp_enqueue_style( 'sdsc-outreach', SDSC_URL . 'assets/outreach.css', array(), SDSC_VERSION );
		wp_enqueue_style( 'sdsc-lessons', SDSC_URL . 'assets/lessons.css', array( 'sdsc-outreach' ), SDSC_VERSION );
		wp_enqueue_style( 'sdsc-board', SDSC_URL . 'assets/board.css', array( 'sdsc-lessons' ), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-board', SDSC_URL . 'assets/board.js', array(), SDSC_VERSION, true );
		$people = array();
		if ( class_exists( 'SDSC_Outreach' ) ) {
			foreach ( SDSC_Outreach::contacts() as $c ) { $people[] = array( trim( $c['first'] . ' ' . $c['last'] ), $c['email'], $c['phone'] ); }
		}
		wp_localize_script( 'sdsc-board', 'SDSC_BD', array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'sdsc_fl_move' ),
			'book'   => admin_url( 'admin.php?page=sdsc-lessons&tab=book' ),
			'people' => $people,
			'phone'  => class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::phone() : '',
		) );
	}

	protected static function url( $tab = 'day', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	protected static function form_open( $action, $class = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">'
			. wp_nonce_field( 'sdsc_fl', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_fl_' . esc_attr( $action ) . '">';
	}

	protected static function date_arg() {
		$d = isset( $_GET['d'] ) ? sanitize_text_field( wp_unslash( $_GET['d'] ) ) : ''; // phpcs:ignore
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : current_time( 'Y-m-d' );
	}

	protected static function add_days( $date, $n ) { return gmdate( 'Y-m-d', strtotime( $date . ' 12:00:00 UTC' ) + $n * DAY_IN_SECONDS ); }

	protected static function book_url( $args ) { return add_query_arg( $args, admin_url( 'admin.php?page=sdsc-lessons&tab=book' ) ); }

	protected static function lesson_url( $id ) { return admin_url( 'admin.php?page=sdsc-lessons&tab=schedule&id=' . (int) $id ); }

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		$tabs = array( 'day' => 'Day', 'week' => 'Week', 'times' => 'Free times', 'students' => 'Students', 'instructors' => 'Instructors', 'cars' => 'Cars' );
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'day'; // phpcs:ignore
		?>
		<div class="wrap sdo sdl sdb">
			<h1 class="sdo-title"><span class="sdo-title-ic" aria-hidden="true">🧑‍🏫</span> Instructors &amp; cars</h1>
			<?php if ( isset( $_GET['msg'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['err'] ) ) : // phpcs:ignore ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<nav class="sdo-tabs" aria-label="Sections">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a class="sdo-tab<?php echo $k === $tab ? ' is-on' : ''; ?>" href="<?php echo esc_url( self::url( $k, in_array( $k, array( 'day', 'week' ), true ) && isset( $_GET['d'] ) ? array( 'd' => self::date_arg() ) : array() ) ); // phpcs:ignore ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<a class="sdo-tab sdb-tab-book" href="<?php echo esc_url( self::book_url( array() ) ); ?>">+ Book a lesson</a>
			</nav>
			<?php
			if ( ! self::instructors() && in_array( $tab, array( 'day', 'week', 'times' ), true ) ) {
				echo '<div class="sdo-hold"><b>Add your instructors first.</b> Open the <a href="' . esc_url( self::url( 'instructors' ) ) . '">Instructors</a> tab, add each one with their working hours, then add the cars on the <a href="' . esc_url( self::url( 'cars' ) ) . '">Cars</a> tab.</div>';
			}
			call_user_func( array( __CLASS__, 'tab_' . $tab ) );
			?>
			<div class="sdb-modal" hidden role="dialog" aria-modal="true" aria-labelledby="sdb-m-h">
				<div class="sdb-modal-box">
					<h2 id="sdb-m-h">Move this lesson?</h2>
					<p class="sdb-m-txt"></p>
					<ul class="sdb-m-warn" hidden></ul>
					<label class="sdo-check"><input type="checkbox" class="sdb-m-mail" checked> E-mail the new details to the student (the new confirmation text also appears in <i>Texts to send</i>)</label>
					<p class="sdo-note">Use this when the school moves a lesson. If the <b>student</b> asked for the change inside the free-cancellation window, open the lesson and use <b>Change</b> instead so the fee rule is applied.</p>
					<div class="sdo-actions"><button type="button" class="sdo-btn sdb-m-ok">Move</button><button type="button" class="sdo-btn sdo-btn--ghost sdb-m-no">Cancel</button></div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------- Day board */

	protected static function nav( $tab, $date, $step ) {
		$prev = self::add_days( $date, -$step );
		$next = self::add_days( $date, $step );
		$show = isset( $_GET['show'] ) ? sanitize_key( $_GET['show'] ) : 'all'; // phpcs:ignore
		?>
		<div class="sdb-bar">
			<div class="sdb-nav">
				<a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::url( $tab, array( 'd' => $prev, 'show' => $show ) ) ); ?>" aria-label="Previous">&lsaquo;</a>
				<a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::url( $tab, array( 'show' => $show ) ) ); ?>">Today</a>
				<a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::url( $tab, array( 'd' => $next, 'show' => $show ) ) ); ?>" aria-label="Next">&rsaquo;</a>
				<form method="get" class="sdb-jump">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>"><input type="hidden" name="show" value="<?php echo esc_attr( $show ); ?>">
					<input type="date" name="d" value="<?php echo esc_attr( $date ); ?>" onchange="this.form.submit()" aria-label="Go to date">
				</form>
			</div>
			<div class="sdb-seg" role="group" aria-label="Show">
				<?php foreach ( array( 'all' => 'Instructors + cars', 'ins' => 'Instructors', 'car' => 'Cars' ) as $k => $v ) : ?>
					<a class="<?php echo $k === $show ? 'is-on' : ''; ?>" href="<?php echo esc_url( self::url( $tab, array( 'd' => $date, 'show' => $k ) ) ); ?>"><?php echo esc_html( $v ); ?></a>
				<?php endforeach; ?>
			</div>
			<?php if ( 'day' === $tab ) : ?><label class="sdb-compact"><input type="checkbox" class="sdb-compact-t"> Compact (fit more columns)</label><?php endif; ?>
		</div>
		<?php
		return $show;
	}

	protected static function columns( $show ) {
		$cols = array();
		if ( 'car' !== $show ) { $cols = array_merge( $cols, array_values( self::instructors() ) ); }
		if ( 'ins' !== $show ) { $cols = array_merge( $cols, array_values( self::cars() ) ); }
		return $cols;
	}

	protected static function tab_day() {
		$date  = self::date_arg();
		$show  = self::nav( 'day', $date, 1 );
		$ls    = self::lessons( $date, $date );
		$offs  = self::offs( $date, $date );
		$cols  = self::columns( $show );
		// Lessons nobody is assigned to yet get their own column, so they are never hidden.
		$orph_i = array_values( array_filter( $ls, function ( $l ) { return ! $l['instructor_id']; } ) );
		$orph_c = array_values( array_filter( $ls, function ( $l ) { return ! $l['car_id'] && 'booked' === $l['status']; } ) );
		if ( 'car' !== $show && $orph_i ) { array_unshift( $cols, array( 'id' => 0, 'kind' => 'instructor', 'name' => 'No instructor yet', 'color' => '#8c8f94', 'langs' => array(), 'hours' => array( '', '', '', '', '', '', '' ), '_orphan' => true ) ); }
		if ( 'ins' !== $show && $orph_c && self::cars() ) { $cols[] = array( 'id' => 0, 'kind' => 'car', 'name' => 'No car yet', 'plate' => '', 'color' => '#8c8f94', '_orphan' => true ); }
		// Visible hours: 7a–9p, widened to cover any working hours or lesson on this day.
		$g0 = 7 * 60; $g1 = 21 * 60;
		foreach ( self::instructors() as $r ) { if ( $w = self::window( $r, $date ) ) { $g0 = min( $g0, $w[0] ); $g1 = max( $g1, $w[1] ); } }
		foreach ( $ls as $l ) { $g0 = min( $g0, $l['_a'] ); $g1 = max( $g1, $l['_b'] ); }
		$g0 = (int) floor( $g0 / 60 ) * 60; $g1 = (int) ceil( $g1 / 60 ) * 60;
		$today = current_time( 'Y-m-d' ) === $date;
		$nowm  = (int) current_time( 'G' ) * 60 + (int) current_time( 'i' );
		$ins_n = count( self::instructors() );
		$free_now = 0; $tot_free = 0; $tot_booked = 0;
		foreach ( self::instructors() as $r ) {
			$fr = self::free( $r, $date, $ls, $offs );
			$tot_free += self::sum( $fr );
			foreach ( $fr as $f ) { if ( $today && $f[0] <= $nowm && $f[1] > $nowm ) { $free_now++; } }
		}
		foreach ( $ls as $l ) { if ( 'booked' === $l['status'] || 'done' === $l['status'] ) { $tot_booked++; } }
		?>
		<div class="sdl-sum sdb-sum">
			<div class="sdl-sum-i"><b><?php echo esc_html( wp_date( 'D, M j', strtotime( $date . ' 12:00:00' ) ) ); ?></b><span><?php echo $today ? 'Today' : esc_html( wp_date( 'Y', strtotime( $date . ' 12:00:00' ) ) ); ?></span></div>
			<div class="sdl-sum-i"><b><?php echo (int) $tot_booked; ?></b><span>lessons</span></div>
			<div class="sdl-sum-i"><b><?php echo esc_html( self::hours_txt( $tot_free ) ); ?></b><span>free instructor time</span></div>
			<?php if ( $today ) : ?><div class="sdl-sum-i"><b><?php echo (int) $free_now; ?> / <?php echo (int) $ins_n; ?></b><span>instructors free right now</span></div><?php endif; ?>
			<?php if ( $orph_i ) : ?><div class="sdl-sum-i sdl-sum-i--due"><b><?php echo count( $orph_i ); ?></b><span>lessons without an instructor</span></div><?php endif; ?>
		</div>
		<p class="sdo-note sdb-legend"><span class="sdb-key sdb-key--free"></span> free &nbsp; <span class="sdb-key sdb-key--off"></span> not working / time off / out of service &nbsp; <span class="sdb-key sdb-key--ev"></span> lesson — click a free spot to book it there; drag a lesson to move it.</p>
		<?php if ( ! $cols ) { echo '<p class="sdo-empty">Nothing to show yet.</p>'; return; } ?>
		<div class="sdb-scroll">
			<div class="sdb-grid" style="--n:<?php echo count( $cols ); ?>;--h:<?php echo (int) ( $g1 - $g0 ); ?>px" data-date="<?php echo esc_attr( $date ); ?>" data-g0="<?php echo (int) $g0; ?>">
				<div class="sdb-corner"></div>
				<?php foreach ( $cols as $r ) :
					$isc = 'car' === $r['kind'];
					$fr  = empty( $r['_orphan'] ) ? self::free( $r, $date, $ls, $offs ) : array();
					$k   = $isc ? 'car_id' : 'instructor_id';
					$n   = count( array_filter( $ls, function ( $l ) use ( $r, $k ) { return (int) $l[ $k ] === (int) $r['id'] && ( empty( $r['_orphan'] ) || 'car_id' !== $k || 'booked' === $l['status'] ); } ) );
					?>
					<div class="sdb-head<?php echo $isc ? ' is-car' : ''; ?>" style="--c:<?php echo esc_attr( $r['color'] ?: '#8c8f94' ); ?>">
						<b><?php echo $isc ? '🚗 ' : ''; ?><?php echo esc_html( $r['name'] ); ?></b>
						<span><?php
						if ( ! empty( $r['_orphan'] ) ) { echo 'assign these'; }
						elseif ( $isc ) { echo esc_html( $r['plate'] ?: ( $r['model'] ?: 'car' ) ); }
						else {
							$w = self::window( $r, $date );
							echo esc_html( ( $w ? self::hm( $w[0] ) . '–' . self::hm( $w[1] ) : 'Day off' ) . ( $r['langs'] ? ' · ' . strtoupper( implode( '/', $r['langs'] ) ) : '' ) );
						}
						?></span>
						<?php if ( empty( $r['_orphan'] ) ) : ?><em><?php echo (int) $n; ?> lesson<?php echo 1 === $n ? '' : 's'; ?> · <?php echo esc_html( self::hours_txt( self::sum( $fr ) ) ); ?> free</em><?php endif; ?>
					</div>
				<?php endforeach; ?>

				<div class="sdb-times" aria-hidden="true">
					<?php for ( $t = $g0; $t < $g1; $t += 60 ) : ?><span style="top:<?php echo (int) ( $t - $g0 ); ?>px"><?php echo esc_html( self::hm( $t ) ); ?></span><?php endfor; ?>
				</div>
				<?php foreach ( $cols as $r ) :
					$isc = 'car' === $r['kind'];
					$k   = $isc ? 'car_id' : 'instructor_id';
					$orphan = ! empty( $r['_orphan'] );
					// Grey = everything that is not free.
					$grey = $orphan ? array() : self::minus( array( array( $g0, $g1 ) ), self::free( $r, $date, $ls, $offs ) );
					?>
					<div class="sdb-body<?php echo $orphan ? ' is-orphan' : ''; ?>" data-kind="<?php echo $isc ? 'car' : 'ins'; ?>" data-res="<?php echo (int) $r['id']; ?>" data-name="<?php echo esc_attr( self::label( $r ) ); ?>" style="--c:<?php echo esc_attr( $r['color'] ?: '#8c8f94' ); ?>">
						<?php foreach ( $grey as $g ) :
							$why = '';
							foreach ( $offs as $o ) { if ( (int) $o['res_id'] === (int) $r['id'] && ( $c = self::clip( $o, $date ) ) && $c[0] < $g[1] && $c[1] > $g[0] ) { $why = ( $isc ? 'Out of service' : 'Off' ) . ( $o['note'] ? ': ' . $o['note'] : '' ); } }
							// Lessons are drawn on top; grey under them is not labelled.
							?>
							<div class="sdb-off" style="top:<?php echo (int) ( max( $g[0], $g0 ) - $g0 ); ?>px;height:<?php echo (int) ( min( $g[1], $g1 ) - max( $g[0], $g0 ) ); ?>px"><?php echo $why ? '<span>' . esc_html( $why ) . '</span>' : ''; ?></div>
						<?php endforeach; ?>
						<?php foreach ( $ls as $l ) :
							if ( (int) $l[ $k ] !== (int) $r['id'] ) { continue; }
							if ( $orphan && $isc && 'booked' !== $l['status'] ) { continue; }
							$ins  = self::res( $l['instructor_id'] );
							$car  = self::res( $l['car_id'] );
							$rv   = class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::row( 'btw', $l['id'], '', $l['email'] ) : null;
							$fut  = 'booked' === $l['status'] && SDSC_Lessons::ts( $l['starts_at'] ) > time();
							$col  = $isc ? ( $ins['color'] ?? '#8c8f94' ) : ( $r['color'] ?: '#8c8f94' );
							$name = trim( $l['first_name'] . ' ' . $l['last_name'] );
							?>
							<a class="sdb-ev sdb-ev--<?php echo esc_attr( $l['status'] ); ?>" href="<?php echo esc_url( self::lesson_url( $l['id'] ) ); ?>" style="top:<?php echo (int) ( $l['_a'] - $g0 ); ?>px;height:<?php echo (int) ( $l['_b'] - $l['_a'] ); ?>px;--c:<?php echo esc_attr( $col ); ?>"
								<?php if ( $fut ) : ?>draggable="true" data-id="<?php echo (int) $l['id']; ?>" data-min="<?php echo (int) $l['minutes']; ?>" data-a="<?php echo (int) $l['_a']; ?>" data-who="<?php echo esc_attr( $name ); ?>" data-ins="<?php echo (int) $l['instructor_id']; ?>" data-car="<?php echo (int) $l['car_id']; ?>"<?php endif; ?>
								title="<?php echo esc_attr( $name . ' · ' . self::hm( $l['_a'] ) . '–' . self::hm( $l['_b'] ) ); ?>">
								<b><?php echo esc_html( self::hm( $l['_a'] ) . '–' . self::hm( $l['_b'] ) ); ?></b>
								<span class="sdb-ev-n"><?php echo esc_html( $name ); ?></span>
								<span class="sdb-ev-m"><?php
									$bits = array( (int) $l['lesson_no'] ? 'L' . (int) $l['lesson_no'] . '/3' : 'Extra' );
									if ( $isc ) { $bits[] = $ins ? '👤 ' . $ins['name'] : '👤 —'; } else { $bits[] = $car ? '🚗 ' . ( $car['plate'] ?: $car['name'] ) : '🚗 —'; }
									echo esc_html( implode( ' · ', $bits ) );
								?></span>
								<span class="sdb-ev-t"><?php
									if ( 'done' === $l['status'] ) { echo '✓ done'; }
									elseif ( 'no_show' === $l['status'] ) { echo 'No-show'; }
									elseif ( 'no_permit' === $l['status'] ) { echo 'No permit'; }
									elseif ( $rv && 'yes' === $rv['rsvp'] ) { echo '✓ confirmed'; }
									elseif ( $rv && 'no' === $rv['rsvp'] ) { echo '⚠ can’t come'; }
									if ( isset( $l['permit'] ) && 'no' === $l['permit'] ) { echo ' ⚠ no permit yet'; }
								?></span>
							</a>
						<?php endforeach; ?>
						<?php if ( $today && $nowm >= $g0 && $nowm <= $g1 ) : ?><i class="sdb-now" style="top:<?php echo (int) ( $nowm - $g0 ); ?>px"></i><?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* ---------------- Week board */

	protected static function tab_week() {
		$date  = self::date_arg();
		$sow   = (int) get_option( 'start_of_week', 0 );
		$w     = (int) gmdate( 'w', strtotime( $date . ' 12:00:00 UTC' ) );
		$start = self::add_days( $date, -( ( $w - $sow + 7 ) % 7 ) );
		$days  = array();
		for ( $i = 0; $i < 7; $i++ ) { $days[] = self::add_days( $start, $i ); }
		$show  = self::nav( 'week', $start, 7 );
		$ls    = self::lessons( $days[0], $days[6] );
		$offs  = self::offs( $days[0], $days[6] );
		$cols  = self::columns( $show );
		$today = current_time( 'Y-m-d' );
		$orph  = array_filter( $ls, function ( $l ) { return ! $l['instructor_id']; } );
		?>
		<?php if ( $orph && 'car' !== $show ) : ?><div class="sdo-hold"><b><?php echo count( $orph ); ?> lesson<?php echo 1 === count( $orph ) ? '' : 's'; ?> this week without an instructor.</b> They are listed in the first row — open each one and choose the instructor.</div><?php endif; ?>
		<div class="sdb-wscroll">
			<table class="sdb-week">
				<thead><tr><th scope="col">Week of <?php echo esc_html( wp_date( 'M j', strtotime( $start . ' 12:00:00' ) ) ); ?></th>
					<?php foreach ( $days as $d ) : ?><th scope="col" class="<?php echo $d === $today ? 'is-today' : ''; ?>"><a href="<?php echo esc_url( self::url( 'day', array( 'd' => $d, 'show' => $show ) ) ); ?>"><?php echo esc_html( wp_date( 'D', strtotime( $d . ' 12:00:00' ) ) ); ?><b><?php echo esc_html( wp_date( 'M j', strtotime( $d . ' 12:00:00' ) ) ); ?></b></a></th><?php endforeach; ?>
					<th scope="col">Week</th></tr></thead>
				<tbody>
				<?php
				$rows = $cols;
				if ( $orph && 'car' !== $show ) { array_unshift( $rows, array( 'id' => 0, 'kind' => 'instructor', 'name' => 'No instructor yet', 'color' => '#8c8f94', 'langs' => array(), '_orphan' => true ) ); }
				foreach ( $rows as $r ) :
					$isc = 'car' === $r['kind'];
					$k   = $isc ? 'car_id' : 'instructor_id';
					$wk_b = 0; $wk_f = 0; $wk_n = 0;
					?>
					<tr style="--c:<?php echo esc_attr( $r['color'] ?: '#8c8f94' ); ?>">
						<th scope="row"><b><?php echo $isc ? '🚗 ' : ''; ?><?php echo esc_html( $r['name'] ); ?></b><span><?php echo esc_html( $isc ? $r['plate'] : ( $r['langs'] ? strtoupper( implode( '/', $r['langs'] ) ) : '' ) ); ?></span></th>
						<?php foreach ( $days as $d ) :
							$mine = array_filter( $ls, function ( $l ) use ( $r, $k, $d ) { return $l['_d'] === $d && (int) $l[ $k ] === (int) $r['id']; } );
							$fr   = empty( $r['_orphan'] ) ? self::free( $r, $d, $ls, $offs ) : array();
							$win  = $isc || ! empty( $r['_orphan'] ) ? array( 1 ) : self::window( $r, $d );
							// Off for the whole working day (instructor) or the whole school day (car)?
							$span = $isc ? array( 7 * 60, 21 * 60 ) : ( is_array( $win ) && isset( $win[1] ) ? $win : null );
							$offd = false;
							if ( $span && empty( $r['_orphan'] ) ) {
								foreach ( $offs as $o ) {
									$c = (int) $o['res_id'] === (int) $r['id'] ? self::clip( $o, $d ) : null;
									if ( $c && $c[0] <= $span[0] && $c[1] >= $span[1] ) { $offd = $o; }
								}
							}
							$bm = 0; foreach ( $mine as $l ) { $bm += $l['_b'] - $l['_a']; }
							$fm = self::sum( $fr );
							$wk_b += $bm; $wk_f += $fm; $wk_n += count( $mine );
							$pct = $bm + $fm ? round( 100 * $bm / ( $bm + $fm ) ) : 0;
							?>
							<td class="<?php echo ( ! $win || $offd ) && ! $mine ? 'is-off' : ''; ?><?php echo $d === $today ? ' is-today' : ''; ?>">
								<?php if ( ! $win && ! $mine ) : ?><span class="sdb-wk-off">Day off</span>
								<?php elseif ( $offd && ! $mine ) : ?><span class="sdb-wk-off"><?php echo esc_html( ( $isc ? 'Out of service' : 'Off' ) . ( $offd['note'] ? ' · ' . $offd['note'] : '' ) ); ?></span>
								<?php else : ?>
									<?php foreach ( $mine as $l ) :
										$other = $isc ? self::res( $l['instructor_id'] ) : self::res( $l['car_id'] );
										?>
										<a class="sdb-wk-ev sdb-ev--<?php echo esc_attr( $l['status'] ); ?>" href="<?php echo esc_url( self::lesson_url( $l['id'] ) ); ?>" title="<?php echo esc_attr( trim( $l['first_name'] . ' ' . $l['last_name'] ) . ( $other ? ' · ' . self::label( $other ) : '' ) ); ?>"><b><?php echo esc_html( self::hm( $l['_a'] ) ); ?></b> <?php echo esc_html( $l['first_name'] . ( $l['last_name'] ? ' ' . mb_substr( $l['last_name'], 0, 1 ) . '.' : '' ) ); ?><?php echo (int) $l['lesson_no'] ? ' <i>L' . (int) $l['lesson_no'] . '</i>' : ''; ?></a>
									<?php endforeach; ?>
									<?php if ( empty( $r['_orphan'] ) ) : ?>
										<span class="sdb-wk-bar" title="<?php echo esc_attr( self::hours_txt( $bm ) . ' booked, ' . self::hours_txt( $fm ) . ' free' ); ?>"><i style="width:<?php echo (int) $pct; ?>%"></i></span>
										<span class="sdb-wk-free<?php echo $fm ? '' : ' is-full'; ?>"><?php echo $fm ? esc_html( self::hours_txt( $fm ) . ' free' ) : 'Full'; ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
						<td class="sdb-wk-tot"><b><?php echo (int) $wk_n; ?></b> lesson<?php echo 1 === $wk_n ? '' : 's'; ?><?php if ( empty( $r['_orphan'] ) ) : ?><span><?php echo esc_html( self::hours_txt( $wk_b ) ); ?> booked</span><span><?php echo esc_html( self::hours_txt( $wk_f ) ); ?> free</span><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="sdo-note">Click a day to open that day&rsquo;s board. The bar shows how much of the working time is booked.</p>
		<?php
	}

	/* ---------------- Free times (to offer the student by text) */

	/**
	 * Start times between two dates where an instructor (or a given one) is free for $len minutes
	 * and, when the school has cars on file, a car is free too.
	 * Returns [ date => [ minute => [instructor ids] ] ].
	 */
	public static function slots( $from, $days, $len, $ins_id = 0, $part = '' ) {
		$to   = self::add_days( $from, $days - 1 );
		$ls   = array_filter( self::lessons( $from, $to ), function ( $l ) { return 'booked' === $l['status'] || 'done' === $l['status']; } );
		$offs = self::offs( $from, $to );
		$ins  = $ins_id ? array_filter( array( self::res( $ins_id ) ) ) : self::instructors();
		$cars = self::cars();
		$out  = array();
		$now  = current_time( 'timestamp' ); // phpcs:ignore -- site-local stamp is what we compare to here
		$lead = $now + 2 * HOUR_IN_SECONDS;
		for ( $i = 0; $i < $days; $i++ ) {
			$d  = self::add_days( $from, $i );
			$cf = array();
			foreach ( $cars as $c ) { $cf[] = self::free( $c, $d, $ls, $offs ); }
			foreach ( $ins as $r ) {
				foreach ( self::free( $r, $d, $ls, $offs ) as $f ) {
					for ( $t = (int) ceil( $f[0] / self::STEP ) * self::STEP; $t + $len <= $f[1]; $t += self::STEP ) {
						if ( strtotime( $d . ' 00:00:00 UTC' ) + $t * 60 < $lead ) { continue; }
						if ( 'am' === $part && $t >= 12 * 60 ) { continue; }
						if ( 'pm' === $part && ( $t < 12 * 60 || $t >= 17 * 60 ) ) { continue; }
						if ( 'eve' === $part && $t < 17 * 60 ) { continue; }
						if ( $cars ) {
							$ok = false;
							foreach ( $cf as $civ ) { foreach ( $civ as $c ) { if ( $c[0] <= $t && $c[1] >= $t + $len ) { $ok = true; break 2; } } }
							if ( ! $ok ) { continue; }
						}
						$out[ $d ][ $t ][] = (int) $r['id'];
					}
				}
			}
			if ( isset( $out[ $d ] ) ) { ksort( $out[ $d ] ); }
		}
		return $out;
	}

	protected static function tab_times() {
		$g = function ( $k, $def ) { return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : $def; }; // phpcs:ignore
		$from = $g( 'from', current_time( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) { $from = current_time( 'Y-m-d' ); }
		$len  = in_array( (int) $g( 'len', 120 ), array( 60, 90, 120, 180 ), true ) ? (int) $g( 'len', 120 ) : 120;
		$ins  = (int) $g( 'ins', 0 );
		$part = in_array( $g( 'part', '' ), array( 'am', 'pm', 'eve' ), true ) ? $g( 'part', '' ) : '';
		$sl   = self::slots( $from, 14, $len, $ins, $part );
		?>
		<p class="sdo-lead">When a student texts to schedule, pick the length (and the instructor if it matters), tick two or three times below and send the ready text. When they answer, press <b>Book</b> next to the time they chose — the form opens already filled in.</p>
		<div class="sdo-two sdl-two sdb-times-two">
			<section class="sdo-panel">
				<form method="get" class="sdb-filter">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><input type="hidden" name="tab" value="times">
					<label class="sdo-f"><span>From</span><input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
					<label class="sdo-f"><span>Length</span><select name="len"><?php foreach ( array( 60, 90, 120, 180 ) as $m ) : ?><option value="<?php echo (int) $m; ?>"<?php selected( $len, $m ); ?>><?php echo esc_html( ( $m / 60 ) . ' hours' ); ?></option><?php endforeach; ?></select></label>
					<label class="sdo-f"><span>Instructor</span><select name="ins"><option value="0">Any instructor</option><?php foreach ( self::instructors() as $r ) : ?><option value="<?php echo (int) $r['id']; ?>"<?php selected( $ins, (int) $r['id'] ); ?>><?php echo esc_html( $r['name'] . ( $r['langs'] ? ' (' . strtoupper( implode( '/', $r['langs'] ) ) . ')' : '' ) ); ?></option><?php endforeach; ?></select></label>
					<label class="sdo-f"><span>Time of day</span><select name="part"><option value="">Any time</option><option value="am"<?php selected( $part, 'am' ); ?>>Morning</option><option value="pm"<?php selected( $part, 'pm' ); ?>>Afternoon (12–5)</option><option value="eve"<?php selected( $part, 'eve' ); ?>>Evening (5+)</option></select></label>
					<button class="sdo-btn sdo-btn--ghost">Show</button>
				</form>
				<?php if ( ! $sl ) : ?>
					<p class="sdo-empty">No free <?php echo esc_html( ( $len / 60 ) . '-hour' ); ?> times in the next two weeks with these choices.</p>
				<?php else : ?>
					<div class="sdb-slots">
						<?php foreach ( $sl as $d => $times ) : ?>
							<div class="sdb-slot-day">
								<h3><?php echo esc_html( wp_date( 'l, M j', strtotime( $d . ' 12:00:00' ) ) ); ?></h3>
								<div class="sdb-slot-row">
									<?php foreach ( $times as $t => $who ) :
										$names = array_map( function ( $id ) { return self::res( $id )['name'] ?? ''; }, $who );
										$label = wp_date( 'D M j', strtotime( $d . ' 12:00:00' ) ) . ', ' . self::hm( $t, true );
										?>
										<span class="sdb-slot">
											<label title="<?php echo esc_attr( 'Free: ' . implode( ', ', $names ) ); ?>"><input type="checkbox" class="sdb-pick" value="<?php echo esc_attr( $label ); ?>" data-start="<?php echo esc_attr( $d . 'T' . str_pad( (string) intdiv( $t, 60 ), 2, '0', STR_PAD_LEFT ) . ':' . str_pad( (string) ( $t % 60 ), 2, '0', STR_PAD_LEFT ) ); ?>" data-ins="<?php echo (int) ( 1 === count( $who ) || $ins ? $who[0] : 0 ); ?>"> <?php echo esc_html( self::hm( $t, true ) ); ?> <i><?php echo count( $who ) > 1 ? count( $who ) . ' free' : esc_html( $names[0] ); ?></i></label>
										</span>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
			<section class="sdo-panel sdb-offer">
				<h2>Text to the student</h2>
				<div class="sdl-grid sdl-form">
					<label class="sdo-f"><span>Student name</span><input type="text" class="sdb-o-name" list="sdb-people" placeholder="Full name" autocomplete="off"><datalist id="sdb-people"></datalist></label>
					<label class="sdo-f"><span>Mobile</span><input type="tel" class="sdb-o-phone" placeholder="(301) 555-0123"></label>
					<label class="sdo-f"><span>Language</span><select class="sdb-o-lang"><option value="en">English</option><option value="es">Español</option></select></label>
				</div>
				<p class="sdo-bubble sdb-o-text">Tick two or three times on the left.</p>
				<div class="sdo-actions">
					<a class="sdo-btn sdo-btn--text sdb-o-send" href="#" aria-disabled="true">Text these times</a>
					<button type="button" class="sdo-btn sdo-btn--ghost sdb-o-copy">Copy text</button>
				</div>
				<h3 class="sdb-o-h">They chose a time? Book it:</h3>
				<ul class="sdb-o-book"><li class="sdo-muted">The times you tick appear here with a Book button.</li></ul>
				<p class="sdo-note">Ask them the questions you always ask before booking: full name, learner&rsquo;s permit, practice hours. The booking form has a place for each answer.</p>
			</section>
		</div>
		<?php
	}

	/* ---------------- Students ↔ instructors */

	protected static function tab_students() {
		global $wpdb;
		$f    = isset( $_GET['ins'] ) ? (int) $_GET['ins'] : -1; // phpcs:ignore
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::t_asg() . ' ORDER BY name', ARRAY_A ); // phpcs:ignore
		$ls   = class_exists( 'SDSC_Lessons' ) ? (array) $wpdb->get_results( 'SELECT id, email, phone, status, starts_at, lesson_no FROM ' . SDSC_Lessons::t_lessons() . " WHERE status IN ('booked','done') ORDER BY starts_at", ARRAY_A ) : array(); // phpcs:ignore
		$by   = array();
		foreach ( $ls as $l ) { $by[ self::skey( $l['email'], $l['phone'] ) ][] = $l; }
		$count = array();
		foreach ( $rows as $r ) { $count[ (int) $r['instructor_id'] ] = ( $count[ (int) $r['instructor_id'] ] ?? 0 ) + 1; }
		?>
		<p class="sdo-lead">Which student drives with which instructor. A student is assigned automatically the first time you book them with an instructor; change or remove it here at any time. The booking form then picks that instructor by itself.</p>
		<div class="sdl-sum">
			<a class="sdl-sum-i<?php echo -1 === $f ? ' is-on' : ''; ?>" href="<?php echo esc_url( self::url( 'students' ) ); ?>"><b><?php echo count( $rows ); ?></b><span>all students</span></a>
			<?php foreach ( self::instructors() as $r ) : ?>
				<a class="sdl-sum-i<?php echo (int) $r['id'] === $f ? ' is-on' : ''; ?>" style="box-shadow: inset 4px 0 0 <?php echo esc_attr( $r['color'] ); ?>, 0 0 0 1px #dcdcde" href="<?php echo esc_url( self::url( 'students', array( 'ins' => $r['id'] ) ) ); ?>"><b><?php echo (int) ( $count[ (int) $r['id'] ] ?? 0 ); ?></b><span><?php echo esc_html( $r['name'] ); ?></span></a>
			<?php endforeach; ?>
		</div>
		<section class="sdo-panel">
			<h2>Assign a student</h2>
			<?php echo self::form_open( 'asg_save', 'sdl-form' ); // phpcs:ignore ?>
				<div class="sdl-grid">
					<label class="sdo-f"><span>Student name</span><input type="text" name="name" required class="sdb-a-name" list="sdb-people" autocomplete="off"><datalist id="sdb-people"></datalist></label>
					<label class="sdo-f"><span>E-mail</span><input type="email" name="email" class="sdb-a-email"></label>
					<label class="sdo-f"><span>Mobile</span><input type="tel" name="phone" class="sdb-a-phone"></label>
					<label class="sdo-f"><span>Instructor</span><?php echo self::select_html( 'instructor', 0, 'ins' ); // phpcs:ignore ?></label>
				</div>
				<p class="sdo-note">An e-mail or a mobile number is needed so the student can be recognised on the next booking.</p>
				<button class="sdo-btn">Save</button>
			</form>
		</section>
		<table class="widefat striped sdb-asg">
			<thead><tr><th>Student</th><th>Instructor</th><th>Lessons</th><th>Next lesson</th><th></th></tr></thead>
			<tbody>
			<?php $shown = 0; foreach ( $rows as $a ) :
				if ( $f >= 0 && (int) $a['instructor_id'] !== $f ) { continue; }
				$shown++;
				$mine = $by[ $a['skey'] ] ?? array();
				$done = count( array_filter( $mine, function ( $l ) { return 'done' === $l['status']; } ) );
				$next = null;
				foreach ( $mine as $l ) { if ( 'booked' === $l['status'] && $l['starts_at'] >= current_time( 'mysql' ) ) { $next = $l; break; } }
				$ir = self::res( $a['instructor_id'] );
				?>
				<tr>
					<td><b><?php echo esc_html( $a['name'] ); ?></b><br><span class="sdo-muted"><?php echo esc_html( trim( $a['email'] . ' ' . $a['phone'] ) ); ?></span></td>
					<td>
						<?php echo self::form_open( 'asg_save', 'sdl-inline' ); // phpcs:ignore ?>
							<input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
							<span class="sdb-dot" style="background:<?php echo esc_attr( $ir['color'] ?? '#8c8f94' ); ?>"></span>
							<?php echo self::select_html( 'instructor', (int) $a['instructor_id'], 'ins' ); // phpcs:ignore ?>
							<button class="sdo-btn sdo-btn--ghost">Change</button>
						</form>
					</td>
					<td><?php echo (int) $done; ?> done · <?php echo count( $mine ) - $done; ?> booked</td>
					<td><?php echo $next ? '<a href="' . esc_url( self::lesson_url( $next['id'] ) ) . '">' . esc_html( wp_date( 'D M j, g:i a', SDSC_Lessons::ts( $next['starts_at'] ) ) ) . '</a>' : '<span class="sdo-muted">—</span>'; ?></td>
					<td><?php echo self::form_open( 'asg_del', 'sdl-inline' ); // phpcs:ignore ?><input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>"><button class="sdo-btn sdo-btn--danger" onclick="return confirm('Remove this student from the instructor? Their lessons stay as they are.')">Unassign</button></form></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $shown ) : ?><tr><td colspan="5" class="sdo-muted">No students here yet.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------- Instructors / Cars */

	protected static function tab_instructors() { self::people_tab( 'instructor' ); }
	protected static function tab_cars() { self::people_tab( 'car' ); }

	protected static function people_tab( $kind ) {
		$isc  = 'car' === $kind;
		$edit = isset( $_GET['id'] ) ? self::res( (int) $_GET['id'] ) : null; // phpcs:ignore
		if ( $edit && $edit['kind'] !== $kind ) { $edit = null; }
		$act  = self::list_of( $kind );
		$arch = array_filter( self::list_of( $kind, false ), function ( $r ) { return ! (int) $r['active']; } );
		$up   = self::offs( current_time( 'Y-m-d' ), self::add_days( current_time( 'Y-m-d' ), 365 ) );
		?>
		<div class="sdo-two sdl-two">
			<section>
				<?php if ( ! $act ) : ?><p class="sdo-empty"><?php echo $isc ? 'No cars yet. Add the first one on the right.' : 'No instructors yet. Add the first one on the right.'; ?></p><?php endif; ?>
				<div class="sdb-people">
					<?php foreach ( $act as $r ) :
						$mine = array_filter( $up, function ( $o ) use ( $r ) { return (int) $o['res_id'] === (int) $r['id']; } );
						$dc   = $isc ? null : self::res( $r['def_car'] );
						?>
						<article class="sdb-person" style="--c:<?php echo esc_attr( $r['color'] ?: '#8c8f94' ); ?>">
							<header>
								<span class="sdb-av"><?php echo $isc ? '🚗' : esc_html( mb_strtoupper( mb_substr( $r['name'], 0, 1 ) ) ); ?></span>
								<div><b><?php echo esc_html( $r['name'] ); ?></b>
									<span class="sdo-muted"><?php
									if ( $isc ) { echo esc_html( trim( $r['plate'] . ( $r['model'] ? ' · ' . $r['model'] : '' ) ) ); }
									else { echo esc_html( implode( ', ', array_filter( array( $r['langs'] ? implode( '/', array_map( function ( $x ) { return self::lang_names()[ $x ] ?? $x; }, $r['langs'] ) ) : '', $r['phone'], $dc ? '🚗 ' . self::label( $dc ) : '' ) ) ) ); }
									?></span></div>
								<a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::url( $isc ? 'cars' : 'instructors', array( 'id' => $r['id'] ) ) ); ?>">Edit</a>
							</header>
							<?php if ( ! $isc ) : ?>
								<div class="sdb-hours"><?php
								$wd = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
								$sow = (int) get_option( 'start_of_week', 0 );
								for ( $i = 0; $i < 7; $i++ ) {
									$x = ( $sow + $i ) % 7;
									$v = $r['hours'][ $x ];
									echo '<span class="' . ( $v ? '' : 'is-off' ) . '"><b>' . esc_html( $wd[ $x ] ) . '</b>' . esc_html( $v ? self::hm( self::m( explode( '-', $v )[0] ) ) . '–' . self::hm( self::m( explode( '-', $v )[1] ) ) : 'off' ) . '</span>';
								}
								?></div>
							<?php endif; ?>
							<?php if ( $r['note'] ) : ?><p class="sdo-muted"><?php echo esc_html( $r['note'] ); ?></p><?php endif; ?>
							<details class="sdl-det">
								<summary><?php echo $isc ? 'Out of service' : 'Time off'; ?><?php echo $mine ? ' (' . count( $mine ) . ')' : ''; ?></summary>
								<ul class="sdb-offs">
									<?php foreach ( $mine as $o ) : ?>
										<li><?php echo esc_html( wp_date( 'D M j, g:i a', strtotime( $o['starts_at'] ) ) . ' → ' . wp_date( 'D M j, g:i a', strtotime( $o['ends_at'] ) ) . ( $o['note'] ? ' · ' . $o['note'] : '' ) ); ?>
											<?php echo self::form_open( 'off_del', 'sdl-inline' ); // phpcs:ignore ?><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>"><input type="hidden" name="back" value="<?php echo esc_attr( $kind ); ?>"><button class="button-link">Remove</button></form></li>
									<?php endforeach; ?>
								</ul>
								<?php echo self::form_open( 'off_add', 'sdb-off-form' ); // phpcs:ignore ?>
									<input type="hidden" name="res" value="<?php echo (int) $r['id']; ?>">
									<label class="sdo-f"><span>From</span><input type="datetime-local" name="from" required step="900"></label>
									<label class="sdo-f"><span>Until</span><input type="datetime-local" name="to" required step="900"></label>
									<label class="sdo-f"><span>Note</span><input type="text" name="note" placeholder="<?php echo $isc ? 'Oil change, inspection…' : 'Vacation, doctor…'; ?>"></label>
									<button class="sdo-btn sdo-btn--ghost">Add</button>
								</form>
							</details>
						</article>
					<?php endforeach; ?>
				</div>
				<?php if ( $arch ) : ?>
					<h3 class="sdl-day">Archived (removed, history kept)</h3>
					<div class="sdl-list">
						<?php foreach ( $arch as $r ) : ?>
							<div class="sdl-row sdb-arch"><div></div><div class="sdl-who"><b><?php echo esc_html( self::label( $r ) ); ?></b></div>
								<div class="sdl-act"><?php echo self::form_open( 'res_restore', 'sdl-inline' ); // phpcs:ignore ?><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>"><button class="sdo-btn sdo-btn--ghost">Restore</button></form></div></div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
			<section class="sdo-panel">
				<h2><?php echo $edit ? 'Edit ' . esc_html( $edit['name'] ) : ( $isc ? 'Add a car' : 'Add an instructor' ); ?></h2>
				<?php echo self::form_open( 'res_save', 'sdl-form' ); // phpcs:ignore ?>
					<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
					<?php if ( $edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>"><?php endif; ?>
					<div class="sdl-grid">
						<label class="sdo-f"><span><?php echo $isc ? 'Name (e.g. White Corolla)' : 'Name'; ?></span><input type="text" name="name" required value="<?php echo esc_attr( $edit['name'] ?? '' ); ?>"></label>
						<?php if ( $isc ) : ?>
							<label class="sdo-f"><span>Plate</span><input type="text" name="plate" value="<?php echo esc_attr( $edit['plate'] ?? '' ); ?>"></label>
							<label class="sdo-f"><span>Make / model / year</span><input type="text" name="model" value="<?php echo esc_attr( $edit['model'] ?? '' ); ?>"></label>
						<?php else : ?>
							<label class="sdo-f"><span>Mobile</span><input type="tel" name="phone" value="<?php echo esc_attr( $edit['phone'] ?? '' ); ?>"></label>
							<label class="sdo-f"><span>E-mail</span><input type="email" name="email" value="<?php echo esc_attr( $edit['email'] ?? '' ); ?>"></label>
							<label class="sdo-f"><span>Usual car</span><?php echo self::select_html( 'car', (int) ( $edit['def_car'] ?? 0 ), 'def_car' ); // phpcs:ignore ?></label>
						<?php endif; ?>
						<label class="sdo-f"><span>Colour on the board</span><input type="color" name="color" value="<?php echo esc_attr( $edit['color'] ?? self::palette()[ count( self::list_of( $kind, false ) ) % 12 ] ); ?>"></label>
					</div>
					<?php if ( ! $isc ) : ?>
						<fieldset class="sdb-langs"><legend>Languages</legend>
							<?php foreach ( self::lang_names() as $k => $v ) : ?><label class="sdo-check"><input type="checkbox" name="langs[]" value="<?php echo esc_attr( $k ); ?>"<?php checked( in_array( $k, $edit['langs'] ?? array( 'en' ), true ) ); ?>> <?php echo esc_html( $v ); ?></label><?php endforeach; ?>
						</fieldset>
						<fieldset class="sdb-hours-f"><legend>Working hours</legend>
							<?php
							$wd  = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
							$hrs = $edit['hours'] ?? self::default_hours();
							$sow = (int) get_option( 'start_of_week', 0 );
							for ( $i = 0; $i < 7; $i++ ) :
								$x = ( $sow + $i ) % 7;
								$p = $hrs[ $x ] ? explode( '-', $hrs[ $x ] ) : array( '', '' );
								?>
								<div class="sdb-hrow">
									<b><?php echo esc_html( $wd[ $x ] ); ?></b>
									<label class="sdo-check"><input type="checkbox" class="sdb-on" name="on[<?php echo (int) $x; ?>]" value="1"<?php checked( (bool) $hrs[ $x ] ); ?>> works</label>
									<input type="time" name="from[<?php echo (int) $x; ?>]" value="<?php echo esc_attr( $p[0] ?: '08:00' ); ?>" step="900" aria-label="<?php echo esc_attr( $wd[ $x ] . ' from' ); ?>">
									<span>to</span>
									<input type="time" name="to[<?php echo (int) $x; ?>]" value="<?php echo esc_attr( $p[1] ?: '19:00' ); ?>" step="900" aria-label="<?php echo esc_attr( $wd[ $x ] . ' until' ); ?>">
								</div>
							<?php endfor; ?>
						</fieldset>
					<?php endif; ?>
					<label class="sdo-f sdo-f--wide"><span>Note (team only)</span><input type="text" name="note" value="<?php echo esc_attr( $edit['note'] ?? '' ); ?>"></label>
					<p class="sdo-actions"><button class="sdo-btn"><?php echo $edit ? 'Save' : 'Add'; ?></button>
						<?php if ( $edit ) : ?><a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::url( $isc ? 'cars' : 'instructors' ) ); ?>">Cancel</a><?php endif; ?></p>
				</form>
				<?php if ( $edit ) : ?>
					<?php echo self::form_open( 'res_remove', 'sdb-remove' ); // phpcs:ignore ?>
						<input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
						<button class="sdo-btn sdo-btn--danger" onclick="return confirm('Remove <?php echo esc_js( $edit['name'] ); ?>? If they have lessons on record they are archived instead, so the history stays.')">Remove <?php echo esc_html( $edit['name'] ); ?></button>
					</form>
					<p class="sdo-note">Upcoming lessons with <?php echo esc_html( $edit['name'] ); ?> are not moved automatically — they show under &ldquo;<?php echo $isc ? 'No car yet' : 'No instructor yet'; ?>&rdquo; on the board so you can reassign them.</p>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/* ================================================================ handlers */

	protected static function guard() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_fl' );
	}

	protected static function back( $tab, $args = array() ) {
		wp_safe_redirect( self::url( $tab, array_map( 'rawurlencode', $args ) ) );
		exit;
	}

	protected static function used( $id ) {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Lessons' ) ) { return false; }
		$r = self::res( $id );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Lessons::t_lessons() . ' WHERE ' . ( 'car' === $r['kind'] ? 'car_id = %d' : '( instructor_id = %d OR instructor = %s )' ), 'car' === $r['kind'] ? array( $id ) : array( $id, $r['name'] ) ) ); // phpcs:ignore
	}

	public static function h_res_save() {
		global $wpdb;
		self::guard();
		$p    = wp_unslash( $_POST ); // phpcs:ignore
		$kind = 'car' === ( $p['kind'] ?? '' ) ? 'car' : 'instructor';
		$tab  = 'car' === $kind ? 'cars' : 'instructors';
		$name = sanitize_text_field( $p['name'] ?? '' );
		if ( ! $name ) { self::back( $tab, array( 'err' => 'Please enter a name.' ) ); }
		$hours = array();
		for ( $x = 0; $x < 7; $x++ ) {
			$a = sanitize_text_field( $p['from'][ $x ] ?? '' );
			$b = sanitize_text_field( $p['to'][ $x ] ?? '' );
			$hours[ $x ] = ! empty( $p['on'][ $x ] ) && self::m( $a ) !== null && self::m( $b ) > self::m( $a ) ? substr( $a, 0, 5 ) . '-' . substr( $b, 0, 5 ) : '';
		}
		$color = sanitize_hex_color( $p['color'] ?? '' ) ?: self::palette()[0];
		$d = array(
			'kind'    => $kind,
			'name'    => $name,
			'phone'   => sanitize_text_field( $p['phone'] ?? '' ),
			'email'   => sanitize_email( $p['email'] ?? '' ),
			'langs'   => implode( ',', array_intersect( array_keys( self::lang_names() ), (array) ( $p['langs'] ?? array() ) ) ),
			'color'   => $color,
			'plate'   => strtoupper( sanitize_text_field( $p['plate'] ?? '' ) ),
			'model'   => sanitize_text_field( $p['model'] ?? '' ),
			'def_car' => (int) ( $p['def_car'] ?? 0 ),
			'hours'   => wp_json_encode( 'car' === $kind ? self::default_hours() : $hours ),
			'note'    => sanitize_text_field( $p['note'] ?? '' ),
		);
		$id = (int) ( $p['id'] ?? 0 );
		if ( $id && self::res( $id ) ) {
			$old = self::res( $id );
			$wpdb->update( self::t_res(), $d, array( 'id' => $id ) );
			// Keep the name shown on lessons in step with a renamed instructor.
			if ( 'instructor' === $kind && $old['name'] !== $name && class_exists( 'SDSC_Lessons' ) ) {
				$wpdb->update( SDSC_Lessons::t_lessons(), array( 'instructor' => $name ), array( 'instructor_id' => $id ) );
			}
			self::back( $tab, array( 'msg' => $name . ' saved.' ) );
		}
		$wpdb->insert( self::t_res(), array_merge( $d, array( 'active' => 1, 'sort' => count( self::list_of( $kind, false ) ), 'created_at' => current_time( 'mysql' ) ) ) );
		self::back( $tab, array( 'msg' => $name . ' added.' ) );
	}

	public static function h_res_remove() {
		global $wpdb;
		self::guard();
		$id = (int) ( $_POST['id'] ?? 0 );
		$r  = self::res( $id );
		if ( ! $r ) { self::back( 'instructors' ); }
		$tab = 'car' === $r['kind'] ? 'cars' : 'instructors';
		if ( self::used( $id ) ) {
			$wpdb->update( self::t_res(), array( 'active' => 0 ), array( 'id' => $id ) );
			// Upcoming lessons lose this instructor/car so they show up as "not assigned" and get a new one.
			if ( class_exists( 'SDSC_Lessons' ) ) {
				$col = 'car' === $r['kind'] ? 'car_id' : 'instructor_id';
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . SDSC_Lessons::t_lessons() . " SET $col = 0" . ( 'car' === $r['kind'] ? '' : ", instructor = ''" ) . " WHERE $col = %d AND status = 'booked' AND starts_at > %s", $id, current_time( 'mysql' ) ) ); // phpcs:ignore
			}
			if ( 'instructor' === $r['kind'] ) { $wpdb->update( self::t_asg(), array( 'instructor_id' => 0 ), array( 'instructor_id' => $id ) ); }
			self::back( $tab, array( 'msg' => $r['name'] . ' removed from the board. Past lessons keep the record; upcoming ones now show as not assigned.' ) );
		}
		$wpdb->delete( self::t_res(), array( 'id' => $id ) );
		$wpdb->delete( self::t_off(), array( 'res_id' => $id ) );
		if ( 'instructor' === $r['kind'] ) { $wpdb->update( self::t_asg(), array( 'instructor_id' => 0 ), array( 'instructor_id' => $id ) ); }
		else { $wpdb->update( self::t_res(), array( 'def_car' => 0 ), array( 'def_car' => $id ) ); }
		self::back( $tab, array( 'msg' => $r['name'] . ' removed.' ) );
	}

	public static function h_res_restore() {
		global $wpdb;
		self::guard();
		$r = self::res( (int) ( $_POST['id'] ?? 0 ) );
		if ( $r ) { $wpdb->update( self::t_res(), array( 'active' => 1 ), array( 'id' => $r['id'] ) ); }
		self::back( $r && 'car' === $r['kind'] ? 'cars' : 'instructors', array( 'msg' => $r ? $r['name'] . ' is back on the board.' : '' ) );
	}

	protected static function dt( $v ) {
		$v = sanitize_text_field( wp_unslash( (string) $v ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v ) ? str_replace( 'T', ' ', $v ) . ':00' : '';
	}

	public static function h_off_add() {
		global $wpdb;
		self::guard();
		$r    = self::res( (int) ( $_POST['res'] ?? 0 ) );
		$tab  = $r && 'car' === $r['kind'] ? 'cars' : 'instructors';
		$from = self::dt( $_POST['from'] ?? '' ); // phpcs:ignore
		$to   = self::dt( $_POST['to'] ?? '' ); // phpcs:ignore
		if ( ! $r || ! $from || ! $to || $to <= $from ) { self::back( $tab, array( 'err' => 'Please choose a start and an end after it.' ) ); }
		$wpdb->insert( self::t_off(), array( 'res_id' => $r['id'], 'starts_at' => $from, 'ends_at' => $to, 'note' => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ), 'created_by' => get_current_user_id() ) );
		// Warn about lessons already booked inside it.
		$hit = 0;
		if ( class_exists( 'SDSC_Lessons' ) ) {
			$col = 'car' === $r['kind'] ? 'car_id' : 'instructor_id';
			$hit = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Lessons::t_lessons() . " WHERE $col = %d AND status = 'booked' AND starts_at < %s AND DATE_ADD(starts_at, INTERVAL minutes MINUTE) > %s", $r['id'], $to, $from ) ); // phpcs:ignore
		}
		self::back( $tab, $hit ? array( 'err' => 'Saved — but ' . $hit . ' booked lesson' . ( 1 === $hit ? ' falls' : 's fall' ) . ' inside this time. Move ' . ( 1 === $hit ? 'it' : 'them' ) . ' on the Day board.' ) : array( 'msg' => 'Saved.' ) );
	}

	public static function h_off_del() {
		global $wpdb;
		self::guard();
		$wpdb->delete( self::t_off(), array( 'id' => (int) ( $_POST['id'] ?? 0 ) ) );
		self::back( 'car' === ( $_POST['back'] ?? '' ) ? 'cars' : 'instructors', array( 'msg' => 'Removed.' ) ); // phpcs:ignore
	}

	public static function h_asg_save() {
		global $wpdb;
		self::guard();
		$p   = wp_unslash( $_POST ); // phpcs:ignore
		$ins = (int) ( $p['ins'] ?? 0 );
		$id  = (int) ( $p['id'] ?? 0 );
		if ( $id ) {
			$wpdb->update( self::t_asg(), array( 'instructor_id' => $ins, 'updated_at' => current_time( 'mysql' ), 'created_by' => get_current_user_id() ), array( 'id' => $id ) );
			self::back( 'students', array( 'msg' => 'Saved.' ) );
		}
		$email = sanitize_email( $p['email'] ?? '' );
		$phone = sanitize_text_field( $p['phone'] ?? '' );
		$name  = sanitize_text_field( $p['name'] ?? '' );
		if ( ! $name || ! self::skey( $email, $phone ) || ! $ins ) { self::back( 'students', array( 'err' => 'Please enter the name, an e-mail or mobile, and the instructor.' ) ); }
		self::assign( $email, $phone, $name, $ins );
		self::back( 'students', array( 'msg' => $name . ' is now with ' . ( self::res( $ins )['name'] ?? '' ) . '.' ) );
	}

	public static function h_asg_del() {
		global $wpdb;
		self::guard();
		$wpdb->delete( self::t_asg(), array( 'id' => (int) ( $_POST['id'] ?? 0 ) ) );
		self::back( 'students', array( 'msg' => 'Unassigned.' ) );
	}

	/** Drag-and-drop on the day board. */
	public static function ajax_move() {
		global $wpdb;
		if ( ! self::can() || ! check_ajax_referer( 'sdsc_fl_move', 'nonce', false ) ) { wp_send_json_error( array( 'msg' => 'Not allowed.' ), 403 ); }
		$id    = (int) ( $_POST['id'] ?? 0 );
		$l     = class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::lesson( $id ) : null;
		$start = self::dt( $_POST['start'] ?? '' ); // phpcs:ignore
		$kind  = 'car' === ( $_POST['kind'] ?? '' ) ? 'car' : 'ins';
		$res   = (int) ( $_POST['res'] ?? 0 );
		if ( ! $l || 'booked' !== $l['status'] || ! $start ) { wp_send_json_error( array( 'msg' => 'This lesson can no longer be moved.' ) ); }
		if ( SDSC_Lessons::ts( $start ) < time() ) { wp_send_json_error( array( 'msg' => 'That time has already passed.' ) ); }
		$ins = (int) ( $l['instructor_id'] ?? 0 );
		$car = (int) ( $l['car_id'] ?? 0 );
		if ( 'ins' === $kind ) { $ins = $res; } else { $car = $res; }
		if ( $res && ! self::res( $res ) ) { wp_send_json_error( array( 'msg' => 'Unknown instructor or car.' ) ); }
		if ( empty( $_POST['force'] ) ) {
			$c = self::conflicts( $start, (int) $l['minutes'], $ins, $car, $id );
			if ( $c ) { wp_send_json_success( array( 'conflicts' => $c ) ); }
		}
		$ir   = self::res( $ins );
		$data = array( 'starts_at' => $start, 'instructor_id' => $ins, 'instructor' => $ir ? $ir['name'] : '', 'car_id' => $car, 'updated_at' => current_time( 'mysql' ) );
		$moved = $start !== $l['starts_at'];
		if ( $moved ) { $data['remind_at'] = null; }
		$wpdb->update( SDSC_Lessons::t_lessons(), $data, array( 'id' => $id ) );
		$bits = array();
		if ( $moved ) { $bits[] = 'moved from ' . SDSC_Lessons::when( $l['starts_at'], 'en' ) . ' to ' . SDSC_Lessons::when( $start, 'en' ); }
		if ( (int) ( $l['instructor_id'] ?? 0 ) !== $ins ) { $bits[] = 'instructor: ' . ( $ir ? $ir['name'] : 'none' ); }
		if ( (int) ( $l['car_id'] ?? 0 ) !== $car ) { $cr = self::res( $car ); $bits[] = 'car: ' . ( $cr ? self::label( $cr ) : 'none' ); }
		SDSC_Lessons::add_log( $id, 'On the board: ' . implode( '; ', $bits ) );
		if ( $moved && class_exists( 'SDSC_Schedule' ) ) {
			// The old "I'll be there" was for the old time.
			if ( SDSC_Schedule::row( 'btw', $id, '', $l['email'] ) ) { SDSC_Schedule::put( 'btw', $id, '', $l['email'], array( 'rsvp' => '', 'rsvp_at' => null ) ); }
		}
		if ( 'ins' === $kind && $ins ) { self::assign( $l['email'], $l['phone'], trim( $l['first_name'] . ' ' . $l['last_name'] ), $ins ); }
		$mailed = false;
		if ( ! empty( $_POST['mail'] ) && ( $moved || 'ins' === $kind ) ) {
			$mailed = SDSC_Lessons::mail( 'update', SDSC_Lessons::lesson( $id ) );
			if ( $mailed ) { $wpdb->update( SDSC_Lessons::t_lessons(), array( 'conf_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ); }
		}
		wp_send_json_success( array( 'ok' => 1, 'mailed' => $mailed ) );
	}
}
