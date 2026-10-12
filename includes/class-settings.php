<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * صفحه تنظیمات — فقط برچسب (اسم) اکانت‌های زوم.
 * کلیدهای API زوم و Stripe دیگر اینجا نیستند: در صفحه «API connections» رمزنگاری‌شده نگه داشته می‌شوند.
 * اگر از نسخه قبلی کلیدی در این تنظیمات مانده باشد، یک بار به حافظه رمزنگاری‌شده منتقل و از اینجا پاک می‌شود.
 */
class SDSC_Settings {

	const OPTION = 'sdsc_settings';
	const LEGACY = array(
		'zoom_account_1_id'     => 'zoom1_client',
		'zoom_account_1_secret' => 'zoom1_secret',
		'zoom_account_2_id'     => 'zoom2_client',
		'zoom_account_2_secret' => 'zoom2_secret',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'migrate_secrets' ) );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Settings', 'Settings', 'manage_options', 'sdsc-settings', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting( self::OPTION, self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/** Only the two labels are kept; anything else posted to this option is dropped. */
	public static function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'zoom_account_1_label' => sanitize_text_field( $in['zoom_account_1_label'] ?? '' ),
			'zoom_account_2_label' => sanitize_text_field( $in['zoom_account_2_label'] ?? '' ),
		);
	}

	/** Moves keys saved by the old version of this page into encrypted storage, then removes them here. */
	public static function migrate_secrets() {
		$o = get_option( self::OPTION, array() );
		if ( ! is_array( $o ) ) { return; }
		$touched = false;
		foreach ( self::LEGACY as $old => $new ) {
			if ( ! empty( $o[ $old ] ) ) {
				if ( class_exists( 'SDSC_Api' ) ) { SDSC_Api::import( $new, $o[ $old ] ); }
				unset( $o[ $old ] );
				$touched = true;
			}
		}
		if ( ! empty( $o['stripe_restricted_key'] ) ) {
			$k = trim( $o['stripe_restricted_key'] );
			if ( class_exists( 'SDSC_Api' ) ) { SDSC_Api::import( false !== strpos( $k, '_live_' ) ? 'stripe_live_key' : 'stripe_test_key', $k ); }
			unset( $o['stripe_restricted_key'] );
			$touched = true;
		}
		if ( $touched ) { update_option( self::OPTION, $o ); }
	}

	public static function get_options() {
		$defaults = array(
			'zoom_account_1_label' => 'Zoom account 1',
			'zoom_account_2_label' => 'Zoom account 2',
		);
		$o = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $o ) ? array_filter( $o, 'strlen' ) : array(), $defaults );
	}

	public static function get_zoom_accounts() {
		$o        = self::get_options();
		$accounts = array();
		if ( ! empty( $o['zoom_account_1_label'] ) ) { $accounts['account_1'] = $o['zoom_account_1_label']; }
		if ( ! empty( $o['zoom_account_2_label'] ) ) { $accounts['account_2'] = $o['zoom_account_2_label']; }
		return $accounts;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$o = self::get_options();
		?>
		<div class="wrap">
			<h1>Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION ); ?>
				<h2>Zoom account names</h2>
				<p class="description">These names show on the class form (Zoom account) and on API connections.</p>
				<table class="form-table">
					<tr><th>Account 1</th><td><input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[zoom_account_1_label]" value="<?php echo esc_attr( $o['zoom_account_1_label'] ); ?>" class="regular-text" /></td></tr>
					<tr><th>Account 2</th><td><input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[zoom_account_2_label]" value="<?php echo esc_attr( $o['zoom_account_2_label'] ); ?>" class="regular-text" /></td></tr>
				</table>
				<?php submit_button( 'Save' ); ?>
			</form>
			<?php if ( class_exists( 'SDSC_Api' ) && SDSC_Api::can() ) : ?>
				<p>Stripe and Zoom keys are kept, encrypted, on <a href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-api' ) ); ?>">API connections</a>.</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
