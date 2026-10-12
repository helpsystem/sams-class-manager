<?php
/**
 * Discount codes (Class Manager → Discount codes) and staff-only classes.
 *
 * Saghar or the admin creates a code (e.g. TEST5 = 5% off, or $20 off), limits it to one or more classes,
 * dates and a number of uses. The student types it on the registration form; the price is checked on the
 * server, locked to the registration, charged at Stripe Checkout and shown on the receipt. A code never
 * stacks with the site-wide course discount — the student gets whichever is better.
 *
 * Staff-only (hidden) classes: a class marked "Hide from website lists" never shows in the course list,
 * homepage or services page; it can only be registered through its direct link (?course=CODE). Used for
 * the $1 payment test class.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Promo {
	const OPT    = 'sdsc_promo_codes';
	const DBV    = '1';
	const HIDDEN = '_sdsc_hidden';
	const MIN    = 50; // Stripe's smallest card charge in US cents.

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_promo_regs'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 22 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_post_sdsc_promo_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sdsc_promo_toggle', array( __CLASS__, 'toggle' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'sdsc_paid', array( __CLASS__, 'on_paid' ), 5, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'save_post_sdsc_class', array( __CLASS__, 'save_box' ) );
	}

	public static function install() {
		if ( get_option( 'sdsc_promo_dbv' ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			reg_id bigint(20) unsigned NOT NULL,
			code varchar(30) NOT NULL DEFAULT '',
			label varchar(60) NOT NULL DEFAULT '',
			list_cents int(11) unsigned NOT NULL DEFAULT 0,
			cents int(11) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			PRIMARY KEY  (reg_id),
			KEY code (code)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'sdsc_promo_dbv', self::DBV, false );
	}

	/* ------------------------------------------------------------ hidden classes */

	public static function hidden( $class_id ) { return '1' === (string) get_post_meta( $class_id, self::HIDDEN, true ); }

	public static function metabox() {
		add_meta_box( 'sdsc-visibility', 'Who can see this class', array( __CLASS__, 'box' ), 'sdsc_class', 'side', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'sdsc_vis', 'sdsc_vis_nonce' );
		$code = (string) get_post_meta( $post->ID, '_sdsc_course_code', true );
		echo '<label><input type="checkbox" name="sdsc_hidden" value="1" ' . checked( self::hidden( $post->ID ), true, false ) . '> Hide from the website lists (staff tests, private groups)</label>';
		if ( $code && class_exists( 'SDSC_Class_Feed' ) && method_exists( 'SDSC_Class_Feed', 'reg_url' ) ) {
			echo '<p class="description">Direct registration link:<br><code style="word-break:break-all">' . esc_html( add_query_arg( 'course', rawurlencode( $code ), SDSC_Class_Feed::reg_url() ) ) . '</code></p>';
		}
	}

	public static function save_box( $post_id ) {
		if ( ! isset( $_POST['sdsc_vis_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sdsc_vis_nonce'] ) ), 'sdsc_vis' ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! empty( $_POST['sdsc_hidden'] ) ) { update_post_meta( $post_id, self::HIDDEN, '1' ); } else { delete_post_meta( $post_id, self::HIDDEN ); }
	}

	/* ------------------------------------------------------------ codes */

	public static function all() {
		$v = get_option( self::OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function clean( $code ) { return strtoupper( substr( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $code ), 0, 30 ) ); }

	public static function list_cents( $class_id ) {
		$p = (string) get_post_meta( $class_id, '_sdsc_price', true );
		return is_numeric( $p ) && (float) $p > 0 ? (int) round( (float) $p * 100 ) : 0;
	}

	public static function label( $c ) {
		return 'pct' === $c['type'] ? rtrim( rtrim( number_format( (float) $c['value'], 2 ), '0' ), '.' ) . '% off' : '$' . number_format( (float) $c['value'], 2 ) . ' off';
	}

	/** Paid uses of a code (real money only; test-mode payments count separately). */
	public static function uses( $code, $mode = '' ) {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Pay' ) ) { return 0; }
		$mode = $mode ? $mode : ( class_exists( 'SDSC_Api' ) ? SDSC_Api::mode() : 'test' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT p.reg_id) FROM ' . SDSC_Pay::table() . ' p JOIN ' . self::table() . " r ON r.reg_id = p.reg_id WHERE r.code = %s AND p.status = 'paid' AND p.mode = %s", $code, $mode ) ); // phpcs:ignore
	}

	/**
	 * Check a code for a class. Returns array( ok(bool), message, price_cents, list_cents, label ).
	 */
	public static function check( $code, $class_id ) {
		$code = self::clean( $code );
		$all  = self::all();
		$c    = $all[ $code ] ?? null;
		$list = self::list_cents( $class_id );
		$no   = function ( $m ) use ( $list ) { return array( false, $m, $list, $list, '' ); };
		if ( '' === $code ) { return $no( 'Type the discount code.' ); }
		if ( ! $c || empty( $c['active'] ) ) { return $no( 'This code is not valid.' ); }
		$today = current_time( 'Y-m-d' );
		if ( ! empty( $c['starts'] ) && $today < $c['starts'] ) { return $no( 'This code is not active yet.' ); }
		if ( ! empty( $c['ends'] ) && $today > $c['ends'] ) { return $no( 'This code has expired.' ); }
		if ( ! empty( $c['classes'] ) && ! in_array( (int) $class_id, array_map( 'intval', (array) $c['classes'] ), true ) ) { return $no( 'This code does not apply to the course you chose.' ); }
		if ( ! empty( $c['max'] ) && self::uses( $code ) >= (int) $c['max'] ) { return $no( 'This code has been used up.' ); }
		if ( ! $list ) { return $no( 'This course has no online price.' ); }
		$price = 'pct' === $c['type'] ? (int) round( $list * ( 1 - (float) $c['value'] / 100 ) ) : $list - (int) round( (float) $c['value'] * 100 );
		if ( $price < self::MIN ) { return $no( 'This code would make the price too low to pay by card. Please call the school.' ); }
		$site = class_exists( 'SDSC_Pay' ) ? SDSC_Pay::amount( $class_id ) : $list;
		if ( $site && $site <= $price ) { return array( false, 'The current website discount is already better than this code — you get that one.', $site, $list, '' ); }
		return array( true, self::label( $c ) . ' applied.', $price, $list, self::label( $c ) );
	}

	/** Lock a code to a registration (called right after the registration is saved). */
	public static function attach( $reg_id, $code, $class_id ) {
		list( $ok, , $price, $list, $label ) = self::check( $code, $class_id );
		if ( ! $ok ) { return false; }
		global $wpdb;
		$wpdb->replace( self::table(), array( 'reg_id' => $reg_id, 'code' => self::clean( $code ), 'label' => $label, 'list_cents' => $list, 'cents' => $price, 'created' => current_time( 'mysql' ) ) );
		return true;
	}

	public static function for_reg( $reg_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE reg_id = %d', $reg_id ), ARRAY_A ); // phpcs:ignore
	}

	/** Price (cents) for a registration: the locked code price (30 days), else the normal price. */
	public static function amount_for( $reg_id, $class_id ) {
		$base = class_exists( 'SDSC_Pay' ) ? SDSC_Pay::amount( $class_id ) : 0;
		$r    = self::for_reg( $reg_id );
		if ( ! $r || ! $base || strtotime( $r['created'] ) < current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) { return $base; } // phpcs:ignore
		return max( self::MIN, min( $base, (int) $r['cents'] ) );
	}

	public static function on_paid( $reg_id ) {
		$r = self::for_reg( $reg_id );
		if ( $r ) {
			$all = self::all();
			if ( isset( $all[ $r['code'] ] ) ) { $all[ $r['code'] ]['last_used'] = current_time( 'mysql' ); update_option( self::OPT, $all, false ); }
		}
	}

	/* ------------------------------------------------------------ REST (registration form "Apply") */

	public static function rest() {
		register_rest_route( 'sdsc/v1', '/promo', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( $req ) {
				nocache_headers();
				// Gentle brute-force guard: 30 checks per IP per 10 minutes.
				$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
				$key = 'sdsc_promo_rl_' . md5( $ip );
				$n   = (int) get_transient( $key );
				if ( $n > 30 ) { return new WP_Error( 'sdsc_promo', 'Too many tries. Please wait a few minutes.', array( 'status' => 429 ) ); }
				set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
				$cid = absint( $req['class'] );
				if ( ! $cid || 'sdsc_class' !== get_post_type( $cid ) ) { return new WP_Error( 'sdsc_promo', 'Choose a course first.', array( 'status' => 400 ) ); }
				list( $ok, $msg, $price, $list ) = self::check( (string) $req['code'], $cid );
				return rest_ensure_response( array( 'ok' => $ok, 'msg' => $msg, 'price' => '$' . number_format( $price / 100, 2 ), 'list' => '$' . number_format( $list / 100, 2 ), 'save' => $ok ? '$' . number_format( ( $list - $price ) / 100, 2 ) : '' ) );
			},
		) );
	}

	/* ------------------------------------------------------------ admin page */

	public static function can() { return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'discounts' ) : current_user_can( 'manage_options' ); }

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Discount codes', 'Discount codes', 'sdsc_access_discounts', 'sdsc-promo', array( __CLASS__, 'page' ) );
	}

	public static function classes() {
		return get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => array( 'publish', 'private' ), 'numberposts' => 60, 'meta_key' => '_sdsc_start', 'orderby' => 'meta_value', 'order' => 'DESC' ) ); // phpcs:ignore
	}

	public static function save() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_promo' );
		$code = self::clean( wp_unslash( $_POST['code'] ?? '' ) );
		$back = admin_url( 'admin.php?page=sdsc-promo' );
		if ( strlen( $code ) < 3 ) { wp_safe_redirect( add_query_arg( 'err', rawurlencode( 'The code needs at least 3 letters or numbers.' ), $back ) ); exit; }
		$type  = 'amt' === ( $_POST['type'] ?? '' ) ? 'amt' : 'pct';
		$value = max( 0, (float) ( $_POST['value'] ?? 0 ) );
		if ( $value <= 0 || ( 'pct' === $type && $value >= 100 ) ) { wp_safe_redirect( add_query_arg( 'err', rawurlencode( 'Enter a discount between 0 and 100%, or a dollar amount.' ), $back ) ); exit; }
		$date    = function ( $k ) { $v = sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ); return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : ''; };
		$classes = array_values( array_filter( array_map( 'absint', (array) ( $_POST['classes'] ?? array() ) ) ) );
		$all     = self::all();
		$old     = $all[ $code ] ?? array();
		$all[ $code ] = array(
			'type' => $type, 'value' => $value, 'classes' => $classes, 'starts' => $date( 'starts' ), 'ends' => $date( 'ends' ),
			'max' => absint( $_POST['max'] ?? 0 ), 'active' => ! empty( $_POST['active'] ), 'note' => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
			'created' => $old['created'] ?? current_time( 'mysql' ), 'by' => $old['by'] ?? get_current_user_id(), 'last_used' => $old['last_used'] ?? '',
		);
		update_option( self::OPT, $all, false );
		wp_safe_redirect( add_query_arg( 'saved', rawurlencode( $code ), $back ) );
		exit;
	}

	public static function toggle() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_promo_toggle' );
		$code = self::clean( wp_unslash( $_GET['code'] ?? '' ) );
		$all  = self::all();
		if ( isset( $all[ $code ] ) ) {
			if ( 'delete' === ( $_GET['do'] ?? '' ) && ! self::uses( $code, 'live' ) && ! self::uses( $code, 'test' ) ) { unset( $all[ $code ] ); } else { $all[ $code ]['active'] = empty( $all[ $code ]['active'] ); }
			update_option( self::OPT, $all, false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-promo' ) );
		exit;
	}

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		$all   = self::all();
		$edit  = self::clean( wp_unslash( $_GET['edit'] ?? '' ) ); // phpcs:ignore
		$e     = $all[ $edit ] ?? array( 'type' => 'pct', 'value' => '', 'classes' => array(), 'starts' => '', 'ends' => '', 'max' => '', 'active' => true, 'note' => '' );
		$cls   = self::classes();
		$names = array();
		foreach ( $cls as $c ) { $names[ $c->ID ] = html_entity_decode( get_the_title( $c ), ENT_QUOTES, 'UTF-8' ) . ( self::hidden( $c->ID ) ? ' (hidden)' : '' ); }
		$mode  = class_exists( 'SDSC_Api' ) ? SDSC_Api::mode() : 'test';
		echo '<div class="wrap"><h1>Discount codes</h1><p>Students type the code on the registration form. The discount is checked by the website, charged at Stripe and printed on the receipt. A code never adds on top of the website discount — the student gets whichever is better. Card payments must stay at least $0.50.</p>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Saved code <b>' . esc_html( self::clean( wp_unslash( $_GET['saved'] ) ) ) . '</b>.</p></div>'; } // phpcs:ignore
		if ( isset( $_GET['err'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ) . '</p></div>'; } // phpcs:ignore
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Code</th><th>Discount</th><th>Courses</th><th>Dates</th><th>Used (' . esc_html( $mode ) . ')</th><th>Status</th><th></th></tr></thead><tbody>';
		if ( ! $all ) { echo '<tr><td colspan="7">No codes yet.</td></tr>'; }
		foreach ( $all as $code => $c ) {
			$used = self::uses( $code );
			$tog  = wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_promo_toggle&code=' . rawurlencode( $code ) ), 'sdsc_promo_toggle' );
			$cl   = $c['classes'] ? implode( ', ', array_map( function ( $id ) use ( $names ) { return $names[ $id ] ?? '#' . $id; }, $c['classes'] ) ) : 'All courses';
			echo '<tr><td><b style="font-family:monospace;font-size:14px">' . esc_html( $code ) . '</b>' . ( $c['note'] ? '<br><small>' . esc_html( $c['note'] ) . '</small>' : '' ) . '</td><td>' . esc_html( self::label( $c ) ) . '</td><td>' . esc_html( $cl ) . '</td><td>' . esc_html( ( $c['starts'] ? $c['starts'] : 'now' ) . ' → ' . ( $c['ends'] ? $c['ends'] : 'no end' ) ) . '</td><td>' . (int) $used . ( $c['max'] ? ' / ' . (int) $c['max'] : '' ) . '</td><td>' . ( ! empty( $c['active'] ) ? '<span style="color:#00703c;font-weight:600">On</span>' : 'Off' ) . '</td>'
				. '<td><a href="' . esc_url( admin_url( 'admin.php?page=sdsc-promo&edit=' . rawurlencode( $code ) ) ) . '">Edit</a> · <a href="' . esc_url( $tog ) . '">' . ( ! empty( $c['active'] ) ? 'Turn off' : 'Turn on' ) . '</a>' . ( $used ? '' : ' · <a href="' . esc_url( add_query_arg( 'do', 'delete', $tog ) ) . '" style="color:#b32d2e">Delete</a>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2 style="margin-top:28px">' . ( $edit && isset( $all[ $edit ] ) ? 'Edit ' . esc_html( $edit ) : 'New code' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_promo_save">';
		wp_nonce_field( 'sdsc_promo' );
		echo '<table class="form-table" role="presentation">'
			. '<tr><th>Code</th><td><input name="code" required maxlength="30" value="' . esc_attr( $edit ) . '" style="text-transform:uppercase;font-family:monospace" placeholder="e.g. SPRING10"' . ( $edit && isset( $all[ $edit ] ) ? ' readonly' : '' ) . '><p class="description">Letters, numbers and dashes. Not case-sensitive for students.</p></td></tr>'
			. '<tr><th>Discount</th><td><input type="number" name="value" step="0.01" min="0.01" required value="' . esc_attr( $e['value'] ) . '" style="width:110px"> <select name="type"><option value="pct"' . selected( $e['type'], 'pct', false ) . '>% off</option><option value="amt"' . selected( $e['type'], 'amt', false ) . '>$ off</option></select></td></tr>'
			. '<tr><th>Courses</th><td><select name="classes[]" multiple size="8" style="min-width:460px">';
		foreach ( $names as $id => $n ) { echo '<option value="' . (int) $id . '"' . ( in_array( $id, array_map( 'intval', (array) $e['classes'] ), true ) ? ' selected' : '' ) . '>' . esc_html( $n . ' — ' . get_post_meta( $id, '_sdsc_start', true ) ) . '</option>'; }
		echo '</select><p class="description">Pick none for all courses. Hold Ctrl (Cmd on Mac) to pick several.</p></td></tr>'
			. '<tr><th>Valid</th><td>from <input type="date" name="starts" value="' . esc_attr( $e['starts'] ) . '"> until <input type="date" name="ends" value="' . esc_attr( $e['ends'] ) . '"> <span class="description">(empty = no limit)</span></td></tr>'
			. '<tr><th>Number of uses</th><td><input type="number" name="max" min="0" value="' . esc_attr( $e['max'] ) . '" style="width:90px"> <span class="description">paid registrations; 0 or empty = unlimited</span></td></tr>'
			. '<tr><th>Note for staff</th><td><input name="note" value="' . esc_attr( $e['note'] ) . '" class="regular-text" placeholder="e.g. $1 payment test, Instagram promo"></td></tr>'
			. '<tr><th>Active</th><td><label><input type="checkbox" name="active" value="1"' . checked( ! empty( $e['active'] ), true, false ) . '> Students can use it</label></td></tr>'
			. '</table>';
		submit_button( $edit && isset( $all[ $edit ] ) ? 'Save code' : 'Create code' );
		echo '</form></div>';
	}
}
