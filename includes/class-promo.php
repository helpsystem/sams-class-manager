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
		add_action( 'admin_post_sdsc_promo_eb', array( __CLASS__, 'save_eb' ) );
		add_action( 'admin_post_sdsc_promo_ref', array( __CLASS__, 'make_referral' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'sdsc_paid', array( __CLASS__, 'on_paid' ), 5, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'save_post_sdsc_class', array( __CLASS__, 'save_box' ), 99 );
		add_action( 'admin_notices', array( __CLASS__, 'missing_codes' ) );
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
		$seq  = self::seq();
		echo '<p style="margin-top:0"><b>Course code</b><br>';
		if ( '' !== $code ) {
			echo '<span style="font:600 15px monospace">' . esc_html( $code ) . '</span></p>';
		} elseif ( $seq['last'] > 0 ) {
			echo '<label><input type="checkbox" name="sdsc_assign_code" value="1"> Give this class the next code: <b>' . esc_html( $seq['prefix'] . ( $seq['last'] + 1 ) ) . '</b></label></p>';
		} else {
			echo '<span class="description">No code yet. Enter the <b>last course number the school used</b> (from the student folders) once — after that each new class gets the next number with one tick.</span><br>'
				. '<input name="sdsc_seq_prefix" value="' . esc_attr( $seq['prefix'] ) . '" style="width:50px"> <input type="number" name="sdsc_seq_last" min="1" style="width:80px" placeholder="e.g. 67"></p>';
		}
		echo '<p><label><input type="checkbox" name="sdsc_hidden" value="1" ' . checked( self::hidden( $post->ID ), true, false ) . '> Hide from the website lists (staff tests, private groups)</label></p>';
		$eb = self::eb_opts();
		if ( ! empty( $eb['on'] ) ) {
			echo '<p><label><input type="checkbox" name="sdsc_no_eb" value="1" ' . checked( (bool) get_post_meta( $post->ID, '_sdsc_no_earlybird', true ), true, false ) . '> No early-bird price for this class</label></p>';
		}
		if ( class_exists( 'SDSC_Class_Feed' ) && method_exists( 'SDSC_Class_Feed', 'reg_url' ) && 'auto-draft' !== $post->post_status ) {
			$pc = SDSC_Registration::public_code( $post->ID );
			if ( '' !== $pc ) { echo '<p><b>Students see</b><br><span style="font:600 15px monospace">' . esc_html( $pc ) . '</span><br><span class="description">The code on the website, the registration form, links, receipts and e-mails (time of day + start date). The course number above stays inside the school.</span></p>'; }
			echo '<p class="description">Direct registration link:<br><code style="word-break:break-all">' . esc_html( add_query_arg( 'course', rawurlencode( SDSC_Registration::course_param( $post->ID ) ), SDSC_Class_Feed::reg_url() ) ) . '</code></p>';
		}
	}

	/** The school's own course numbering: prefix + last number used. */
	public static function seq() {
		$s = get_option( 'sdsc_course_seq', array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array( 'prefix' => 'C-', 'last' => 0 ) );
	}

	public static function save_box( $post_id ) {
		if ( ! isset( $_POST['sdsc_vis_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sdsc_vis_nonce'] ) ), 'sdsc_vis' ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! empty( $_POST['sdsc_hidden'] ) ) { update_post_meta( $post_id, self::HIDDEN, '1' ); } else { delete_post_meta( $post_id, self::HIDDEN ); }
		if ( isset( $_POST['sdsc_vis_nonce'] ) && self::eb_opts()['on'] ) {
			if ( ! empty( $_POST['sdsc_no_eb'] ) ) { update_post_meta( $post_id, '_sdsc_no_earlybird', '1' ); } else { delete_post_meta( $post_id, '_sdsc_no_earlybird' ); }
		}
		$seq = self::seq();
		if ( ! empty( $_POST['sdsc_seq_last'] ) ) {
			$seq = array( 'prefix' => substr( preg_replace( '/[^A-Za-z\-]/', '', wp_unslash( $_POST['sdsc_seq_prefix'] ?? 'C-' ) ), 0, 6 ), 'last' => absint( $_POST['sdsc_seq_last'] ) );
			update_option( 'sdsc_course_seq', $seq, false );
		}
		$code = (string) get_post_meta( $post_id, '_sdsc_course_code', true );
		if ( '' === $code && ! empty( $_POST['sdsc_assign_code'] ) && $seq['last'] > 0 ) {
			$code = $seq['prefix'] . ( $seq['last'] + 1 );
			update_post_meta( $post_id, '_sdsc_course_code', $code );
		}
		// Keep the counter in step with codes typed by hand.
		if ( $code && preg_match( '/^' . preg_quote( $seq['prefix'], '/' ) . '(\d+)$/', $code, $m ) && (int) $m[1] > (int) $seq['last'] ) {
			$seq['last'] = (int) $m[1];
			update_option( 'sdsc_course_seq', $seq, false );
		}
	}

	/** Real course codes come from the school's own numbering — remind staff when a class has none. */
	public static function missing_codes() {
		$scr = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $scr || ( 'sdsc_class' !== $scr->post_type && false === strpos( (string) $scr->id, 'sdsc' ) ) || ! current_user_can( 'edit_posts' ) ) { return; }
		$miss = array();
		foreach ( get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'publish', 'numberposts' => 30, 'meta_key' => '_sdsc_start', 'orderby' => 'meta_value', 'order' => 'ASC' ) ) as $p ) { // phpcs:ignore
			if ( '' === trim( (string) get_post_meta( $p->ID, '_sdsc_course_code', true ) ) && (string) get_post_meta( $p->ID, '_sdsc_end', true ) >= current_time( 'Y-m-d' ) ) {
				$miss[] = '<a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) . ' (' . get_post_meta( $p->ID, '_sdsc_start', true ) . ')' ) . '</a>';
			}
		}
		if ( $miss ) {
			echo '<div class="notice notice-warning"><p><strong>Course code missing:</strong> ' . implode( ', ', $miss ) . ' — enter the school’s real course code (the number used in the student folders) in each class. Until then the class is shown without a code.</p></div>'; // phpcs:ignore
		}
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
		$price = self::price( $c, $list );
		if ( $price < self::MIN ) { return $no( 'This code would make the price too low to pay by card. Please call the school.' ); }
		$site = class_exists( 'SDSC_Pay' ) ? SDSC_Pay::amount( $class_id ) : $list;
		if ( $site && $site <= $price ) { return array( false, ( self::earlybird( $class_id ) ? 'The early-bird price' : 'The current website price' ) . ' is already better than this code — you get that one.', $site, $list, '' ); }
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
		$start = substr( (string) get_post_meta( $class_id, '_sdsc_start', true ), 0, 10 );
		if ( ! $r || ! $base || strtotime( $r['created'] ) < current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS || ( $start && current_time( 'Y-m-d' ) >= $start ) ) { return $base; } // phpcs:ignore
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

	/* ------------------------------------------------------------ early bird (automatic, shown on the site) */

	const EB = 'sdsc_earlybird';

	public static function eb_opts() {
		$o = get_option( self::EB, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array( 'on' => 0, 'type' => 'amt', 'value' => 25, 'days' => 7 ) );
	}

	public static function price( $c, $list ) {
		return 'pct' === $c['type'] ? (int) round( $list * ( 1 - (float) $c['value'] / 100 ) ) : $list - (int) round( (float) $c['value'] * 100 );
	}

	public static function off( $c ) {
		$v = rtrim( rtrim( number_format( (float) $c['value'], 2 ), '0' ), '.' );
		return 'pct' === $c['type'] ? $v . '% OFF' : '$' . $v . ' OFF';
	}

	/** The early-bird price of a class right now, or null. */
	public static function earlybird( $class_id ) {
		$o = self::eb_opts();
		if ( empty( $o['on'] ) || (float) $o['value'] <= 0 || self::hidden( $class_id ) || get_post_meta( $class_id, '_sdsc_no_earlybird', true ) ) { return null; }
		$start = substr( (string) get_post_meta( $class_id, '_sdsc_start', true ), 0, 10 ); // form saves "Y-m-d\TH:i"
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) { return null; }
		$until = gmdate( 'Y-m-d', strtotime( $start . ' -' . max( 0, (int) $o['days'] ) . ' days' ) );
		if ( current_time( 'Y-m-d' ) > $until ) { return null; }
		$list = self::list_cents( $class_id );
		if ( ! $list ) { return null; }
		$c     = array( 'type' => $o['type'], 'value' => $o['value'] );
		$price = self::price( $c, $list );
		if ( $price < self::MIN ) { return null; }
		return array( 'cents' => $price, 'list' => $list, 'until' => $until, 'label' => 'Early-bird ' . self::label( $c ), 'off' => self::off( $c ) );
	}

	/** Lock the automatic (early-bird) price to a registration that has no code, so paying tomorrow still gets it. */
	public static function lock_auto( $reg_id, $class_id ) {
		$eb = self::earlybird( $class_id );
		if ( ! $eb || ! class_exists( 'SDSC_Pay' ) || SDSC_Pay::amount( $class_id ) !== $eb['cents'] ) { return false; }
		global $wpdb;
		$wpdb->replace( self::table(), array( 'reg_id' => $reg_id, 'code' => 'EARLY-BIRD', 'label' => $eb['label'], 'list_cents' => $eb['list'], 'cents' => $eb['cents'], 'created' => current_time( 'mysql' ) ) );
		return true;
	}

	/** For the class signs on the homepage / services page: crossed-out price + badge + "until" date. */
	public static function sign_offer( $item ) {
		if ( empty( $item['id'] ) ) { return $item; }
		$eb = self::earlybird( (int) $item['id'] );
		if ( ! $eb ) { return $item; }
		$shown = ! empty( $item['d'] ) ? (int) round( (float) preg_replace( '/[^\d.]/', '', (string) $item['now'] ) * 100 ) : $eb['list'];
		if ( $eb['cents'] >= $shown ) { return $item; }
		$m = function ( $c ) { return '$' . ( 0 === $c % 100 ? number_format( $c / 100 ) : number_format( $c / 100, 2 ) ); };
		$item['d']   = array( 'old' => $m( $eb['list'] ), 'new' => $m( $eb['cents'] ), 'pct' => (string) round( 100 - $eb['cents'] / $eb['list'] * 100 ), 'off' => 'EARLY BIRD ' . $eb['off'], 'url' => '', 'ends' => date_i18n( 'M j', strtotime( $eb['until'] ) ) );
		$item['now'] = $m( $eb['cents'] );
		return $item;
	}

	/* ------------------------------------------------------------ admin page */

	public static function can() { return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'discounts' ) : current_user_can( 'manage_options' ); }

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Discounts & offers', 'Discount codes', 'sdsc_access_discounts', 'sdsc-promo', array( __CLASS__, 'page' ) );
	}

	public static function channels() {
		return array( '' => 'Not set', 'google' => 'Google Ads', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'community' => 'Church / community', 'school' => 'High school / partner', 'referral' => 'Student referral', 'flyer' => 'Flyer / print', 'staff' => 'Staff / test', 'other' => 'Other' );
	}

	/** Shared tab bar for all discount screens (also printed on the site-wide sale page). */
	public static function tabs( $cur ) {
		$t = array(
			'codes'  => array( 'Discount codes', admin_url( 'admin.php?page=sdsc-promo' ) ),
			'early'  => array( 'Early bird', admin_url( 'admin.php?page=sdsc-promo&tab=early' ) ),
			'sale'   => array( 'Site-wide sale', admin_url( 'admin.php?page=sdsc-discounts' ) ),
			'report' => array( 'Results', admin_url( 'admin.php?page=sdsc-promo&tab=report' ) ),
			'guide'  => array( 'How to use discounts', admin_url( 'admin.php?page=sdsc-promo&tab=guide' ) ),
		);
		$h = '<nav class="nav-tab-wrapper" style="margin:10px 0 18px">';
		foreach ( $t as $k => $v ) { $h .= '<a class="nav-tab' . ( $k === $cur ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $v[1] ) . '">' . esc_html( $v[0] ) . '</a>'; }
		return $h . '</nav>';
	}

	public static function classes() {
		return get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => array( 'publish', 'private' ), 'numberposts' => 60, 'meta_key' => '_sdsc_start', 'orderby' => 'meta_value', 'order' => 'DESC' ) ); // phpcs:ignore
	}

	protected static function back( $args = array(), $tab = '' ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=sdsc-promo' . ( $tab ? '&tab=' . $tab : '' ) ) );
	}

	public static function save() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_promo' );
		$code = self::clean( wp_unslash( $_POST['code'] ?? '' ) );
		if ( strlen( $code ) < 3 || 'EARLY-BIRD' === $code ) { wp_safe_redirect( self::back( array( 'err' => rawurlencode( 'The code needs at least 3 letters or numbers (and EARLY-BIRD is reserved).' ) ) ) ); exit; }
		$type  = 'amt' === ( $_POST['type'] ?? '' ) ? 'amt' : 'pct';
		$value = max( 0, (float) ( $_POST['value'] ?? 0 ) );
		if ( $value <= 0 || ( 'pct' === $type && $value >= 100 ) ) { wp_safe_redirect( self::back( array( 'err' => rawurlencode( 'Enter a discount between 0 and 100%, or a dollar amount.' ) ) ) ); exit; }
		$date    = function ( $k ) { $v = sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ); return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : ''; };
		$classes = array_values( array_filter( array_map( 'absint', (array) ( $_POST['classes'] ?? array() ) ) ) );
		$ch      = sanitize_key( $_POST['channel'] ?? '' );
		$all     = self::all();
		$old     = $all[ $code ] ?? array();
		$all[ $code ] = array(
			'type' => $type, 'value' => $value, 'classes' => $classes, 'starts' => $date( 'starts' ), 'ends' => $date( 'ends' ),
			'max' => absint( $_POST['max'] ?? 0 ), 'active' => ! empty( $_POST['active'] ), 'note' => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
			'channel' => isset( self::channels()[ $ch ] ) ? $ch : '',
			'created' => $old['created'] ?? current_time( 'mysql' ), 'by' => $old['by'] ?? get_current_user_id(), 'last_used' => $old['last_used'] ?? '',
		);
		update_option( self::OPT, $all, false );
		wp_safe_redirect( self::back( array( 'saved' => rawurlencode( $code ) ) ) );
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
		wp_safe_redirect( self::back() );
		exit;
	}

	public static function save_eb() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_promo_eb' );
		$type  = 'pct' === ( $_POST['type'] ?? '' ) ? 'pct' : 'amt';
		$value = max( 0, (float) ( $_POST['value'] ?? 0 ) );
		if ( 'pct' === $type ) { $value = min( 50, $value ); }
		update_option( self::EB, array( 'on' => empty( $_POST['on'] ) ? 0 : 1, 'type' => $type, 'value' => $value, 'days' => max( 0, min( 60, absint( $_POST['days'] ?? 7 ) ) ) ), false );
		if ( class_exists( 'SDSC_Discounts' ) ) { SDSC_Discounts::purge(); }
		wp_safe_redirect( self::back( array( 'saved' => 'eb' ), 'early' ) );
		exit;
	}

	/** One-click referral code for a past student: FIRSTNAME + 2 digits, all courses. */
	public static function make_referral() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_promo_ref' );
		$name  = sanitize_text_field( wp_unslash( $_POST['who'] ?? '' ) );
		$first = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) strtok( $name, ' ' ) ), 0, 8 ) );
		if ( strlen( $first ) < 2 ) { wp_safe_redirect( self::back( array( 'err' => rawurlencode( 'Type the student’s name (English letters).' ) ) ) ); exit; }
		$all  = self::all();
		$code = '';
		for ( $i = 0; $i < 50 && ( '' === $code || isset( $all[ $code ] ) ); $i++ ) { $code = $first . wp_rand( 10, 99 ); }
		$type  = 'pct' === ( $_POST['type'] ?? '' ) ? 'pct' : 'amt';
		$value = max( 1, (float) ( $_POST['value'] ?? 25 ) );
		$all[ $code ] = array( 'type' => $type, 'value' => $value, 'classes' => array(), 'starts' => '', 'ends' => gmdate( 'Y-m-d', current_time( 'timestamp' ) + 365 * DAY_IN_SECONDS ), 'max' => absint( $_POST['max'] ?? 10 ), 'active' => true, 'note' => 'Referral from ' . $name, 'channel' => 'referral', 'created' => current_time( 'mysql' ), 'by' => get_current_user_id(), 'last_used' => '' ); // phpcs:ignore
		update_option( self::OPT, $all, false );
		wp_safe_redirect( self::back( array( 'saved' => rawurlencode( $code ), 'ref' => 1 ) ) );
		exit;
	}

	public static function share_url( $code, $c = array() ) {
		$base = class_exists( 'SDSC_Class_Feed' ) ? SDSC_Class_Feed::reg_url() : home_url( '/register-course/' );
		$args = array( 'code' => $code );
		if ( ! empty( $c['classes'] ) && 1 === count( $c['classes'] ) ) {
			$args['course'] = SDSC_Registration::course_param( (int) $c['classes'][0] );
		}
		return add_query_arg( $args, $base ) . '#sdsc';
	}

	/** Paid registrations, revenue and discount per code (current payment mode). */
	public static function results() {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Pay' ) ) { return array(); }
		$mode = class_exists( 'SDSC_Api' ) ? SDSC_Api::mode() : 'test';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT r.code, COUNT(DISTINCT p.reg_id) n, SUM(p.amount) revenue, SUM(GREATEST(r.list_cents - p.amount, 0)) given, MAX(p.paid_at) last FROM ' . self::table() . ' r JOIN ' . SDSC_Pay::table() . " p ON p.reg_id = r.reg_id AND p.status = 'paid' AND p.mode = %s GROUP BY r.code ORDER BY n DESC", $mode ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) { $out[ $r['code'] ] = $r; }
		$all = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(DISTINCT reg_id) n, SUM(amount) revenue FROM ' . SDSC_Pay::table() . " WHERE status = 'paid' AND mode = %s", $mode ), ARRAY_A ); // phpcs:ignore
		return array( 'codes' => $out, 'all' => $all, 'mode' => $mode );
	}

	protected static function money( $c ) { return '$' . number_format( (float) $c / 100, 2 ); }

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		$tab = sanitize_key( $_GET['tab'] ?? 'codes' ); // phpcs:ignore
		echo '<div class="wrap"><h1>Discounts &amp; offers</h1>' . self::tabs( $tab ); // phpcs:ignore
		if ( isset( $_GET['err'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ) . '</p></div>'; } // phpcs:ignore
		if ( 'early' === $tab ) { self::page_early(); }
		elseif ( 'report' === $tab ) { self::page_report(); }
		elseif ( 'guide' === $tab ) { self::page_guide(); }
		else { self::page_codes(); }
		echo '</div>';
	}

	protected static function page_codes() {
		$all   = self::all();
		$edit  = self::clean( wp_unslash( $_GET['edit'] ?? '' ) ); // phpcs:ignore
		$e     = wp_parse_args( $all[ $edit ] ?? array(), array( 'type' => 'amt', 'value' => '', 'classes' => array(), 'starts' => '', 'ends' => '', 'max' => '', 'active' => true, 'note' => '', 'channel' => '' ) );
		$names = array();
		foreach ( self::classes() as $c ) { $names[ $c->ID ] = html_entity_decode( get_the_title( $c ), ENT_QUOTES, 'UTF-8' ) . ( get_post_meta( $c->ID, '_sdsc_course_code', true ) ? ' ' . get_post_meta( $c->ID, '_sdsc_course_code', true ) : '' ) . ( self::hidden( $c->ID ) ? ' (hidden)' : '' ); }
		$res   = self::results();
		$ch    = self::channels();
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			$sc = self::clean( wp_unslash( $_GET['saved'] ) ); // phpcs:ignore
			echo '<div class="notice notice-success"><p>Saved code <b>' . esc_html( $sc ) . '</b>.' . ( isset( $all[ $sc ] ) ? ' Link that fills it in for the student: <code>' . esc_html( self::share_url( $sc, $all[ $sc ] ) ) . '</code>' : '' ) . '</p></div>';
		}
		echo '<p style="max-width:900px">Students type a code on the registration form (or open a link that fills it in). The website checks it, Stripe charges the discounted price and the receipt shows the code. A code never adds on top of the early-bird or site-wide price — the student gets whichever is better.</p>';
		echo '<table class="widefat striped" id="sdsc-promo-list" style="max-width:1250px"><thead><tr><th>Code</th><th>Discount</th><th>Where it is used</th><th>Courses</th><th>Valid</th><th>Paid uses (' . esc_html( $res['mode'] ?? '' ) . ')</th><th>Revenue</th><th>Status</th><th></th></tr></thead><tbody>';
		if ( ! $all ) { echo '<tr><td colspan="9">No codes yet — create one below.</td></tr>'; }
		foreach ( $all as $code => $c ) {
			$c    = wp_parse_args( $c, array( 'channel' => '', 'note' => '', 'classes' => array(), 'starts' => '', 'ends' => '', 'max' => 0 ) );
			$r    = $res['codes'][ $code ] ?? array( 'n' => 0, 'revenue' => 0 );
			$tog  = wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_promo_toggle&code=' . rawurlencode( $code ) ), 'sdsc_promo_toggle' );
			$cl   = $c['classes'] ? implode( ', ', array_map( function ( $id ) use ( $names ) { return $names[ $id ] ?? '#' . $id; }, $c['classes'] ) ) : 'All courses';
			$exp  = $c['ends'] && $c['ends'] < current_time( 'Y-m-d' );
			$st   = empty( $c['active'] ) ? 'Off' : ( $exp ? '<span style="color:#996800">Expired</span>' : ( $c['max'] && (int) $r['n'] >= (int) $c['max'] ? '<span style="color:#996800">Used up</span>' : '<span style="color:#00703c;font-weight:600">On</span>' ) );
			echo '<tr><td><b style="font-family:monospace;font-size:14px">' . esc_html( $code ) . '</b>' . ( $c['note'] ? '<br><small>' . esc_html( $c['note'] ) . '</small>' : '' ) . '<br><a href="#" class="sdsc-copy" data-copy="' . esc_attr( self::share_url( $code, $c ) ) . '">Copy link</a></td>'
				. '<td>' . esc_html( self::label( $c ) ) . '</td><td>' . esc_html( $ch[ $c['channel'] ] ?? '' ) . '</td><td>' . esc_html( $cl ) . '</td>'
				. '<td>' . esc_html( ( $c['starts'] ? $c['starts'] : 'now' ) . ' → ' . ( $c['ends'] ? $c['ends'] : 'no end' ) ) . '</td><td>' . (int) $r['n'] . ( $c['max'] ? ' / ' . (int) $c['max'] : '' ) . '</td><td>' . esc_html( self::money( $r['revenue'] ) ) . '</td><td>' . $st . '</td>' // phpcs:ignore
				. '<td><a href="' . esc_url( self::back( array( 'edit' => rawurlencode( $code ) ) ) ) . '#sdsc-form">Edit</a> · <a href="' . esc_url( $tog ) . '">' . ( ! empty( $c['active'] ) ? 'Turn off' : 'Turn on' ) . '</a>' . ( (int) $r['n'] || self::uses( $code, 'test' ) || self::uses( $code, 'live' ) ? '' : ' · <a href="' . esc_url( add_query_arg( 'do', 'delete', $tog ) ) . '" style="color:#b32d2e">Delete</a>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<script>document.addEventListener("click",function(e){var a=e.target.closest(".sdsc-copy");if(!a)return;e.preventDefault();(navigator.clipboard?navigator.clipboard.writeText(a.dataset.copy):Promise.reject()).then(function(){a.textContent="Copied ✓";setTimeout(function(){a.textContent="Copy link";},1500);}).catch(function(){window.prompt("Copy this link",a.dataset.copy);});});</script>';

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:24px;max-width:1250px;margin-top:24px;align-items:start">';
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:4px 18px 10px" id="sdsc-form"><h2>' . ( $edit && isset( $all[ $edit ] ) ? 'Edit ' . esc_html( $edit ) : 'New code' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_promo_save">';
		wp_nonce_field( 'sdsc_promo' );
		echo '<table class="form-table" role="presentation">'
			. '<tr><th>Code</th><td><input name="code" required maxlength="30" value="' . esc_attr( $edit ) . '" style="text-transform:uppercase;font-family:monospace" placeholder="e.g. GOOGLE25"' . ( $edit && isset( $all[ $edit ] ) ? ' readonly' : '' ) . '><p class="description">Letters, numbers and dashes. Students can type it in small letters too.</p></td></tr>'
			. '<tr><th>Discount</th><td><input type="number" name="value" step="0.01" min="0.01" required value="' . esc_attr( $e['value'] ) . '" style="width:110px"> <select name="type"><option value="amt"' . selected( $e['type'], 'amt', false ) . '>$ off</option><option value="pct"' . selected( $e['type'], 'pct', false ) . '>% off</option></select><p class="description">Tip: “$25 off” reads stronger than “5% off” (which is only $18.90 on $378).</p></td></tr>'
			. '<tr><th>Where it is used</th><td><select name="channel">';
		foreach ( $ch as $k => $v ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $e['channel'], $k, false ) . '>' . esc_html( $v ) . '</option>'; }
		echo '</select><p class="description">One code per ad or partner shows which one brings paying students (see Results).</p></td></tr>'
			. '<tr><th>Courses</th><td><select name="classes[]" multiple size="7" style="min-width:420px;max-width:100%">';
		foreach ( $names as $id => $n ) { echo '<option value="' . (int) $id . '"' . ( in_array( $id, array_map( 'intval', (array) $e['classes'] ), true ) ? ' selected' : '' ) . '>' . esc_html( $n . ' — ' . get_post_meta( $id, '_sdsc_start', true ) ) . '</option>'; }
		echo '</select><p class="description">Pick none for all courses. Ctrl / Cmd to pick several.</p></td></tr>'
			. '<tr><th>Valid</th><td>from <input type="date" name="starts" value="' . esc_attr( $e['starts'] ) . '"> until <input type="date" name="ends" value="' . esc_attr( $e['ends'] ) . '"><p class="description">Always set an end date, so a code shared in a group does not live forever.</p></td></tr>'
			. '<tr><th>Number of uses</th><td><input type="number" name="max" min="0" value="' . esc_attr( $e['max'] ) . '" style="width:90px"> <span class="description">paid registrations (0 = unlimited)</span></td></tr>'
			. '<tr><th>Note for staff</th><td><input name="note" value="' . esc_attr( $e['note'] ) . '" class="regular-text" placeholder="e.g. Fall Google Ads campaign"></td></tr>'
			. '<tr><th>Active</th><td><label><input type="checkbox" name="active" value="1"' . checked( ! empty( $e['active'] ), true, false ) . '> Students can use it</label></td></tr>'
			. '</table>';
		submit_button( $edit && isset( $all[ $edit ] ) ? 'Save code' : 'Create code' );
		echo '</form></div>';
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:4px 18px 14px" id="sdsc-promo-ref"><h2>Referral code for a student</h2><p>A happy student shares their own code with friends; you see how many friends registered. Creates <b>FIRSTNAME + 2 digits</b>, all courses, valid for a year.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_promo_ref">';
		wp_nonce_field( 'sdsc_promo_ref' );
		echo '<p><label>Student’s name<br><input name="who" required class="regular-text" placeholder="e.g. Sara Karimi"></label></p><p><label>Friend gets <input type="number" name="value" value="25" min="1" step="1" style="width:80px"></label> <select name="type"><option value="amt">$ off</option><option value="pct">% off</option></select> &nbsp; <label>up to <input type="number" name="max" value="10" min="0" style="width:70px"> friends</label></p>';
		submit_button( 'Create referral code', 'secondary', 'submit', false );
		echo '</form></div></div>';
	}

	protected static function page_early() {
		$o = self::eb_opts();
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Early bird saved. The homepage and registration form show it right away.</p></div>'; } // phpcs:ignore
		echo '<p style="max-width:900px">Early bird is a lower price for everyone who registers early. It is <b>automatic</b>: every Driver’s Education class shows the early-bird price on the website until the deadline, then the normal price. Students who registered before the deadline keep the early-bird price when they pay. Turn it off for one class with the tick box in that class.</p>';
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:4px 18px 10px;max-width:760px"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_promo_eb">';
		wp_nonce_field( 'sdsc_promo_eb' );
		echo '<table class="form-table" role="presentation"><tr><th>Early bird</th><td><label><input type="checkbox" name="on" value="1"' . checked( ! empty( $o['on'] ), true, false ) . '> On</label></td></tr>'
			. '<tr><th>Discount</th><td><input type="number" name="value" min="0" step="0.01" value="' . esc_attr( $o['value'] ) . '" style="width:100px"> <select name="type"><option value="amt"' . selected( $o['type'], 'amt', false ) . '>$ off</option><option value="pct"' . selected( $o['type'], 'pct', false ) . '>% off</option></select></td></tr>'
			. '<tr><th>Deadline</th><td><input type="number" name="days" min="0" max="60" value="' . (int) $o['days'] . '" style="width:70px"> days before the class starts</td></tr></table>';
		submit_button( 'Save early bird' );
		echo '</form></div><h2 style="margin-top:26px">What each open class shows now</h2><table class="widefat striped" id="sdsc-promo-now" style="max-width:900px"><thead><tr><th>Class</th><th>Starts</th><th>Normal price</th><th>Price today</th><th>Early bird until</th></tr></thead><tbody>';
		$any = false;
		foreach ( class_exists( 'SDSC_Registration' ) ? SDSC_Registration::open_classes() : array() as $c ) {
			$any  = true;
			$eb   = self::earlybird( $c->ID );
			$list = self::list_cents( $c->ID );
			$now  = class_exists( 'SDSC_Pay' ) ? SDSC_Pay::amount( $c->ID ) : $list;
			echo '<tr><td>' . esc_html( html_entity_decode( get_the_title( $c ), ENT_QUOTES, 'UTF-8' ) . ' ' . get_post_meta( $c->ID, '_sdsc_course_code', true ) ) . '</td><td>' . esc_html( get_post_meta( $c->ID, '_sdsc_start', true ) ) . '</td><td>' . esc_html( self::money( $list ) ) . '</td><td><b>' . esc_html( self::money( $now ) ) . '</b></td><td>' . ( $eb ? esc_html( date_i18n( 'D, M j', strtotime( $eb['until'] ) ) ) : ( get_post_meta( $c->ID, '_sdsc_no_earlybird', true ) ? 'Off for this class' : '—' ) ) . '</td></tr>';
		}
		if ( ! $any ) { echo '<tr><td colspan="5">No open classes.</td></tr>'; }
		echo '</tbody></table>';
	}

	protected static function page_report() {
		$res = self::results();
		$all = self::all();
		$ch  = self::channels();
		echo '<p>Paid registrations in <b>' . esc_html( $res['mode'] ?? '' ) . '</b> mode. Use this to see which ads and partners bring paying students, and how much the discounts cost.</p>';
		$tot = $res['all'] ?? array( 'n' => 0, 'revenue' => 0 );
		$wd  = 0; $given = 0; $byc = array();
		foreach ( $res['codes'] ?? array() as $code => $r ) {
			$wd += (int) $r['n']; $given += (int) $r['given'];
			$k = 'EARLY-BIRD' === $code ? 'Early bird' : ( $ch[ $all[ $code ]['channel'] ?? '' ] ?? 'Not set' );
			if ( ! isset( $byc[ $k ] ) ) { $byc[ $k ] = array( 'n' => 0, 'revenue' => 0, 'given' => 0 ); }
			$byc[ $k ]['n'] += (int) $r['n']; $byc[ $k ]['revenue'] += (int) $r['revenue']; $byc[ $k ]['given'] += (int) $r['given'];
		}
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0 20px">';
		foreach ( array( array( (int) $tot['n'], 'paid registrations' ), array( self::money( $tot['revenue'] ), 'paid online' ), array( $wd, 'with a discount' ), array( self::money( $given ), 'given as discounts' ) ) as $b ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 18px;min-width:150px"><div style="font-size:22px;font-weight:700">' . esc_html( $b[0] ) . '</div><div style="color:#646970">' . esc_html( $b[1] ) . '</div></div>';
		}
		echo '</div><h2>By source</h2><table class="widefat striped" id="sdsc-promo-src" style="max-width:800px"><thead><tr><th>Source</th><th>Paid registrations</th><th>Revenue</th><th>Discount given</th></tr></thead><tbody>';
		if ( ! $byc ) { echo '<tr><td colspan="4">No discounted payments yet.</td></tr>'; }
		foreach ( $byc as $k => $r ) { echo '<tr><td>' . esc_html( $k ) . '</td><td>' . (int) $r['n'] . '</td><td>' . esc_html( self::money( $r['revenue'] ) ) . '</td><td>' . esc_html( self::money( $r['given'] ) ) . '</td></tr>'; }
		echo '</tbody></table><h2 style="margin-top:22px">By code</h2><table class="widefat striped" style="max-width:800px"><thead><tr><th>Code</th><th>Paid registrations</th><th>Revenue</th><th>Discount given</th><th>Last paid</th></tr></thead><tbody>';
		if ( empty( $res['codes'] ) ) { echo '<tr><td colspan="5">No discounted payments yet.</td></tr>'; }
		foreach ( $res['codes'] ?? array() as $code => $r ) { echo '<tr><td><b style="font-family:monospace">' . esc_html( $code ) . '</b></td><td>' . (int) $r['n'] . '</td><td>' . esc_html( self::money( $r['revenue'] ) ) . '</td><td>' . esc_html( self::money( $r['given'] ) ) . '</td><td>' . esc_html( $r['last'] ) . '</td></tr>'; }
		echo '</tbody></table>';
	}

	protected static function page_guide() {
		$tips = array(
			array( 'Keep $378 as the normal price', 'Give discounts with codes and deadlines instead of lowering the price. When the price goes down often, people learn to wait for a sale.' ),
			array( 'Early bird fills classes sooner', 'Turn on Early bird (e.g. $25 off until 7 days before the start). It is automatic for every class and shows on the website with its deadline.' ),
			array( 'Dollars read better than percent', '“$25 off” feels bigger than “5% off” ($18.90). Use $ for most offers.' ),
			array( 'One code per ad or partner', 'GOOGLE25 for Google Ads, INSTA25 for Instagram, a code per church or high school. The Results tab shows which one brings paying students.' ),
			array( 'Referral codes', 'Give a happy student their own code (Referral code box). Friends save money and you see who sends new students.' ),
			array( 'Always an end date and a limit', 'Codes get shared in group chats. An end date and a number of uses keep a promo from living forever.' ),
			array( 'Site-wide sale only for short events', 'Black Friday, start of summer, a holiday weekend — the Site-wide sale tab shows the crossed-out price to everyone for a few days.' ),
			array( 'Discounts never stack', 'A student gets the single best price: early bird, site-wide sale or their code — never two together.' ),
			array( 'Course codes are the school’s own', 'Enter the last course number used once (in any class without a code). After that each new class gets the next number with one tick — no guessing.' ),
		);
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;max-width:1250px">';
		foreach ( $tips as $i => $t ) { echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px"><b style="font-size:15px">' . ( $i + 1 ) . '. ' . esc_html( $t[0] ) . '</b><p style="margin:6px 0 0;color:#3c434a">' . esc_html( $t[1] ) . '</p></div>'; }
		echo '</div>';
	}
}
