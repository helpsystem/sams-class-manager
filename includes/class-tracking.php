<?php
/**
 * Google Ads conversion tracking: fires only on a finished registration or a confirmed payment.
 * The office pastes the Ads ID and the two conversion labels on the "Ad tracking" screen.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Tracking {
	const OPT = 'sdsc_tracking';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_sdsc_tracking_save', array( __CLASS__, 'save' ) );
	}

	public static function settings() {
		$o = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array( 'aw_id' => '', 'label_reg' => '', 'label_pay' => '' ) );
	}

	public static function ready() {
		$s = self::settings();
		return (bool) preg_match( '/^AW-\d{6,}$/', $s['aw_id'] );
	}

	/** Staff and admins are never counted. */
	protected static function skip() {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	public static function head() {
		if ( is_admin() || ! self::ready() || self::skip() ) { return; }
		$s  = self::settings();
		$id = $s['aw_id'];
		$lr = preg_match( '/^[\w-]{6,}$/', $s['label_reg'] ) ? $s['label_reg'] : '';
		$lp = preg_match( '/^[\w-]{6,}$/', $s['label_pay'] ) ? $s['label_pay'] : '';
		?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $id ); ?>"></script>
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('js',new Date());gtag('config','<?php echo esc_js( $id ); ?>');
window.sdscTrack=function(kind,value,tid){
	try{var k='sdsc_cv_'+kind+'_'+(tid||'');if(sessionStorage.getItem(k)){return;}sessionStorage.setItem(k,'1');}catch(e){}
	var labels={reg:'<?php echo esc_js( $lr ); ?>',pay:'<?php echo esc_js( $lp ); ?>'};
	if(!labels[kind]){return;}
	var p={send_to:'<?php echo esc_js( $id ); ?>/'+labels[kind]};
	if(kind==='pay'){p.value=Number(value)||0;p.currency='USD';}
	if(tid){p.transaction_id=String(tid);}
	gtag('event','conversion',p);
};
</script>
		<?php
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Ad tracking', 'Ad tracking', 'manage_options', 'sdsc-tracking', array( __CLASS__, 'screen' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No access.' ); }
		check_admin_referer( 'sdsc_tracking_save' );
		$p = wp_unslash( $_POST );
		$id = isset( $p['aw_id'] ) ? strtoupper( trim( $p['aw_id'] ) ) : '';
		update_option( self::OPT, array(
			'aw_id'     => preg_match( '/^AW-\d{6,}$/', $id ) ? $id : '',
			'label_reg' => preg_match( '/^[\w-]{6,}$/', isset( $p['label_reg'] ) ? trim( $p['label_reg'] ) : '' ) ? trim( $p['label_reg'] ) : '',
			'label_pay' => preg_match( '/^[\w-]{6,}$/', isset( $p['label_pay'] ) ? trim( $p['label_pay'] ) : '' ) ? trim( $p['label_pay'] ) : '',
		), false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-tracking&saved=1' ) );
		exit;
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = self::settings();
		?>
		<div class="wrap"><h1>Ad tracking (Google Ads)</h1>
		<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore ?><div class="notice notice-success"><p>Saved.</p></div><?php endif; ?>
		<p>A conversion is counted only when a student <strong>finishes registration</strong> or <strong>payment is confirmed</strong>. Clicks on "Enroll" are not counted. Staff who are signed in are never counted.</p>
		<p>In Google Ads create two conversion actions (Website), then paste the values from the tag setup page (they look like <code>AW-1234567890</code> and <code>AbCdEf123_xYz</code>).</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sdsc_tracking_save">
			<?php wp_nonce_field( 'sdsc_tracking_save' ); ?>
			<table class="form-table">
				<tr><th>Google Ads ID</th><td><input name="aw_id" value="<?php echo esc_attr( $s['aw_id'] ); ?>" class="regular-text" placeholder="AW-1234567890"></td></tr>
				<tr><th>Label: registration finished</th><td><input name="label_reg" value="<?php echo esc_attr( $s['label_reg'] ); ?>" class="regular-text"></td></tr>
				<tr><th>Label: payment confirmed</th><td><input name="label_pay" value="<?php echo esc_attr( $s['label_pay'] ); ?>" class="regular-text"></td></tr>
			</table>
			<p>Status: <strong><?php echo self::ready() ? 'Tracking is ON' : 'Tracking is OFF (no Ads ID yet)'; ?></strong></p>
			<?php submit_button( 'Save' ); ?>
		</form></div>
		<?php
	}
}
