<?php
/**
 * Only the site administrator sees WordPress.
 *
 * Everyone else — Saghar, front desk, instructors, students — works in the school dashboard:
 *  - no WordPress toolbar on the website;
 *  - opening /wp-admin/ directly sends them to the matching screen of the dashboard (the workspace shows
 *    the same screen inside the dashboard, without WordPress menus, notices or footer);
 *  - the password-reset / login screens of WordPress show the school logo instead of WordPress';
 *  - page titles and footers do not say "WordPress".
 * Background requests (admin-ajax, admin-post, uploads) are never redirected.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Private {

	public static function init() {
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'gate' ), 1 );
		add_filter( 'admin_title', array( __CLASS__, 'title' ), 99 );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer' ), 99 );
		add_filter( 'update_footer', array( __CLASS__, 'footer' ), 99 );
		add_action( 'admin_head', array( __CLASS__, 'quiet' ), 0 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_look' ) );
		add_filter( 'login_headerurl', function () { return home_url( '/' ); } );
		add_filter( 'login_headertext', function () { return 'Sam’s Driving School'; } );
		add_filter( 'admin_body_class', array( __CLASS__, 'skin_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'skin' ), 30 );
	}

	/** School screens (page=sdsc-…, classes) get one look, whoever opens them. */
	protected static function school_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $page, 'sdsc-' ) ) { return true; }
		$scr = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $scr && 'sdsc_class' === $scr->post_type;
	}

	public static function skin_class( $c ) { return self::school_screen() ? $c . ' sdsc-skin' : $c; }

	public static function skin() {
		if ( self::school_screen() ) { wp_enqueue_style( 'sdsc-staff-skin', SDSC_URL . 'assets/staff-skin.css', array(), SDSC_VERSION ); }
	}

	public static function is_admin_user() { return current_user_can( 'manage_options' ); }

	/** Is this wp-admin request shown inside the dashboard (workspace iframe)? */
	public static function embedded() {
		if ( ! empty( $_GET['sdsc_embed'] ) || ! empty( $_POST['sdsc_embed'] ) ) { return true; } // phpcs:ignore -- display decision only
		if ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === $_SERVER['HTTP_SEC_FETCH_DEST'] ) { return true; }
		return false !== strpos( (string) wp_get_referer(), 'sdsc_embed=1' );
	}

	public static function admin_bar( $show ) {
		return is_user_logged_in() && self::is_admin_user() ? $show : false;
	}

	/** Direct visits to wp-admin by anyone but the administrator go to the dashboard. */
	public static function gate() {
		if ( self::is_admin_user() || ! is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		$file = basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ); // phpcs:ignore
		if ( in_array( $file, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'load-scripts.php', 'load-styles.php', 'media-upload.php' ), true ) ) { return; }
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || self::embedded() ) { return; } // phpcs:ignore
		wp_safe_redirect( self::target() );
		exit;
	}

	/** The dashboard screen that matches the wp-admin page that was asked for. */
	protected static function target() {
		$dash = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' );
		if ( ! class_exists( 'SDSC_Workspace' ) || ! SDSC_Workspace::is_staff() ) { return $dash; }
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore
		foreach ( SDSC_Workspace::screens() as $key => $s ) {
			if ( empty( $s[3] ) ) { continue; }
			parse_str( (string) wp_parse_url( $s[2], PHP_URL_QUERY ), $q );
			if ( ( $page && ( $q['page'] ?? '' ) === $page ) || ( $type && ( $q['post_type'] ?? '' ) === $type ) ) { return SDSC_Workspace::url( $key ); }
		}
		return $dash;
	}

	public static function title( $t ) {
		return self::is_admin_user() ? $t : trim( preg_replace( '/\s*[‹—–-]\s*WordPress\s*$/u', '', (string) $t ) );
	}

	public static function footer( $t ) { return self::is_admin_user() ? $t : ''; }

	/** Inside wp-admin for staff (embedded screens): no WordPress help tabs, update notes or other plugins' banners. */
	public static function quiet() {
		if ( self::is_admin_user() || ! is_user_logged_in() ) { return; }
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		// Our own notices that staff need.
		if ( class_exists( 'SDSC_Promo' ) ) { add_action( 'admin_notices', array( 'SDSC_Promo', 'missing_codes' ) ); }
		if ( class_exists( 'SDSC_Discounts' ) ) { add_action( 'admin_notices', array( 'SDSC_Discounts', 'notice' ) ); }
		$scr = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $scr ) { $scr->remove_help_tabs(); }
		add_filter( 'screen_options_show_screen', '__return_false' );
		echo '<style id="sdsc-private">#wpadminbar,#adminmenumain,#adminmenuback,#adminmenuwrap,#wpfooter,#screen-meta-links,.update-nag,#contextual-help-link-wrap{display:none!important}html.wp-toolbar{padding-top:0!important}#wpcontent,.auto-fold #wpcontent{margin-left:0!important}.driveflow-admin-wrap a.button[href*="demo"],.driveflow-admin-wrap a.button[href*="seed"]{display:none!important}</style>';
	}

	public static function login_look() {
		$logo = class_exists( 'SDSC_Brand' ) && method_exists( 'SDSC_Brand', 'img' ) ? SDSC_Brand::img( 'sams-logo-stacked.webp' ) : '';
		echo '<style>body.login{background:#f3f7f5}body.login #login h1 a{' . ( $logo ? 'background-image:url(' . esc_url( $logo ) . ')!important;' : '' ) . 'background-size:contain!important;width:140px!important;height:140px!important}'
			. '.login form{border:0!important;border-radius:18px!important;box-shadow:0 18px 50px rgba(11,61,36,.12)!important}.login .button-primary{background:#00703c!important;border-color:#00703c!important;border-radius:999px!important;min-height:44px!important;padding:0 22px!important}'
			. '.login input[type=text],.login input[type=password],.login input[type=email]{border-radius:12px!important}#backtoblog a,#nav a{color:#00703c!important}.language-switcher{display:none}</style>';
	}
}
