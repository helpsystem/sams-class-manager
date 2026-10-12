<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Percentage discounts on the advertised prices (Class Manager → Discounts).
 *
 * A discount only shows on the site after the admin confirms that checkout already
 * charges the discounted price (an updated Stripe link or price). That keeps the
 * advertised price and the charged price the same — showing "5% off" while Stripe
 * still charges full price would be a misleading advertisement.
 */
class SDSC_Discounts {

	const OPT = 'sdsc_discounts';

	public static function services() {
		return array(
			'rental' => 'Road test car rental',
			'online' => 'Online 3-hour roadway safety course',
			'btw'    => 'Behind-the-wheel practice packages (all)',
			'course' => "Driver's Education Program (36-hour PM class)",
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_sdsc_save_discounts', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Discounts', 'Discounts', 'sdsc_access_discounts', 'sdsc-discounts', array( __CLASS__, 'page' ) );
	}

	protected static function all() {
		$v = get_option( self::OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	/** Live discount for a service, or null. */
	public static function get( $key ) {
		$try = self::preview();
		if ( isset( $try[ $key ] ) ) { return array( 'pct' => $try[ $key ], 'url' => '', 'ends' => '', 'preview' => true ); }
		$a = self::all();
		if ( empty( $a[ $key ] ) ) { return null; }
		$d   = $a[ $key ];
		$pct = (float) ( $d['pct'] ?? 0 );
		if ( $pct <= 0 || $pct >= 90 || empty( $d['confirmed'] ) ) { return null; }
		if ( ! empty( $d['ends'] ) && $d['ends'] < current_time( 'Y-m-d' ) ) { return null; }
		return array( 'pct' => $pct, 'url' => (string) ( $d['url'] ?? '' ), 'ends' => (string) ( $d['ends'] ?? '' ) );
	}

	/**
	 * Admin-only preview: ?sdsc_try_discount=rental:10,online:5 shows those discounts to the logged-in
	 * admin who opened the link. Visitors never see it, and nothing is saved.
	 */
	public static function preview() {
		static $p = null;
		if ( null !== $p ) { return $p; }
		$p = array();
		if ( is_admin() || empty( $_GET['sdsc_try_discount'] ) || ! is_user_logged_in() || ! SDSC_Access::can( 'discounts' ) ) { return $p; }
		foreach ( explode( ',', sanitize_text_field( wp_unslash( $_GET['sdsc_try_discount'] ) ) ) as $pair ) {
			$b = explode( ':', $pair );
			$k = sanitize_key( $b[0] ?? '' );
			$v = (float) ( $b[1] ?? 10 );
			if ( isset( self::services()[ $k ] ) && $v > 0 && $v < 90 ) { $p[ $k ] = $v; }
		}
		if ( $p ) {
			nocache_headers();
			add_action( 'wp_footer', array( __CLASS__, 'preview_bar' ) );
		}
		return $p;
	}

	public static function preview_bar() {
		$parts = array();
		foreach ( self::preview() as $k => $v ) { $parts[] = $v . '% off ' . ( self::services()[ $k ] ?? $k ); }
		echo '<div style="position:fixed;left:12px;right:12px;bottom:12px;z-index:99999;margin:auto;max-width:640px;padding:10px 14px;border-radius:12px;background:#15191d;color:#fff;font:600 14px/1.4 system-ui,sans-serif;box-shadow:0 12px 30px rgba(0,0,0,.4)">'
			. '<b style="color:#ffc20e">Discount preview — only you can see this.</b> ' . esc_html( implode( ', ', $parts ) ) . '. Visitors still see the normal price. '
			. '<a style="color:#ffc20e" href="' . esc_url( remove_query_arg( 'sdsc_try_discount' ) ) . '">Close preview</a></div>';
	}

	protected static function money( $n ) {
		return '$' . ( abs( $n - round( $n ) ) < 0.005 ? number_format( round( $n ) ) : number_format( $n, 2 ) );
	}

	/**
	 * Apply a service's discount to a price like "$140".
	 * Returns array( old, new, pct, url, ends ) or null when there is no live discount.
	 */
	public static function apply( $key, $price, $url = '' ) {
		$d = self::get( $key );
		if ( ! $d ) { return null; }
		$n = (float) str_replace( ',', '', preg_replace( '/[^\d.,]/', '', (string) $price ) );
		if ( $n <= 0 ) { return null; }
		$new = $n * ( 1 - $d['pct'] / 100 );
		return array(
			'old'  => self::money( $n ),
			'new'  => self::money( $new ),
			'pct'  => rtrim( rtrim( number_format( $d['pct'], 1 ), '0' ), '.' ),
			'url'  => ( $d['url'] && 'btw' !== $key ) ? $d['url'] : $url,
			'ends' => $d['ends'] ? date_i18n( 'M j', strtotime( $d['ends'] ) ) : '',
		);
	}

	public static function save() {
		if ( ! SDSC_Access::can( 'discounts' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_discounts' );
		$in  = isset( $_POST['d'] ) && is_array( $_POST['d'] ) ? wp_unslash( $_POST['d'] ) : array();
		$out = array();
		foreach ( array_keys( self::services() ) as $k ) {
			$r       = $in[ $k ] ?? array();
			$pct     = max( 0, min( 89, (float) ( $r['pct'] ?? 0 ) ) );
			$url     = esc_url_raw( trim( (string) ( $r['url'] ?? '' ) ) );
			$ends    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $r['ends'] ?? '' ) ) ? $r['ends'] : '';
			$out[ $k ] = array( 'pct' => $pct, 'url' => $url, 'ends' => $ends, 'confirmed' => ! empty( $r['confirmed'] ) && $pct > 0 );
		}
		update_option( self::OPT, $out, false );
		self::purge();
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-discounts&saved=1' ) );
		exit;
	}

	/** Clear the page cache (Varnish in front of the site) for the pages that show prices. */
	public static function purge() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( '/', '/services/', '/car-rentals/', '/services-preview/' ) as $p ) {
			wp_remote_request( 'http://127.0.0.1:6081' . $p, array( 'method' => 'PURGE', 'headers' => array( 'Host' => $host ), 'timeout' => 3, 'blocking' => false ) );
		}
	}

	public static function notice() {
		if ( ! SDSC_Access::can( 'discounts' ) ) { return; }
		foreach ( self::all() as $k => $d ) {
			if ( ! empty( $d['pct'] ) && empty( $d['confirmed'] ) ) {
				echo '<div class="notice notice-warning"><p><strong>Sam\'s Class Manager:</strong> a ' . esc_html( $d['pct'] ) . '% discount for “' . esc_html( self::services()[ $k ] ?? $k ) . '” is saved but <em>not shown</em> on the site, because checkout has not been confirmed to charge the discounted price. <a href="' . esc_url( admin_url( 'admin.php?page=sdsc-discounts' ) ) . '">Review discounts</a></p></div>';
				return;
			}
		}
	}

	public static function page() {
		$a = self::all();
		?>
		<div class="wrap">
			<h1>Discounts &amp; offers</h1>
			<?php echo class_exists( 'SDSC_Promo' ) ? SDSC_Promo::tabs( 'sale' ) : ''; // phpcs:ignore ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
			<p style="max-width:760px">Set a percentage off for a service. The site then shows the old price crossed out, the new price and an “X% OFF” badge.
			<strong>Before you tick “Checkout charges this price”, make sure the customer is really charged the discounted amount</strong> — either change the price in Stripe, or create a new Stripe payment link with the new price and paste it below.
			Advertising a discount that checkout does not give is not allowed under consumer-protection law.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sdsc_save_discounts" />
				<?php wp_nonce_field( 'sdsc_discounts' ); ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th>Service</th><th style="width:90px">% off</th><th>New Stripe payment link (optional)</th><th style="width:150px">Offer ends (optional)</th><th style="width:210px">Checkout charges this price</th><th style="width:120px">See it first</th></tr></thead>
					<tbody>
					<?php foreach ( self::services() as $k => $label ) : $r = $a[ $k ] ?? array(); ?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td><input type="number" name="d[<?php echo esc_attr( $k ); ?>][pct]" min="0" max="89" step="0.5" value="<?php echo esc_attr( $r['pct'] ?? '' ); ?>" style="width:80px" /></td>
							<td><input type="url" name="d[<?php echo esc_attr( $k ); ?>][url]" value="<?php echo esc_attr( $r['url'] ?? '' ); ?>" placeholder="https://buy.stripe.com/…" style="width:100%" /><?php if ( 'btw' === $k ) : ?><br><small>Packages keep their own links; change each package's price in Stripe.</small><?php endif; ?></td>
							<td><input type="date" name="d[<?php echo esc_attr( $k ); ?>][ends]" value="<?php echo esc_attr( $r['ends'] ?? '' ); ?>" /></td>
							<td><label><input type="checkbox" name="d[<?php echo esc_attr( $k ); ?>][confirmed]" value="1" <?php checked( ! empty( $r['confirmed'] ) ); ?> /> Yes, confirmed in Stripe</label></td>
							<td><a class="button" target="_blank" href="<?php echo esc_url( add_query_arg( 'sdsc_try_discount', $k . ':' . ( ! empty( $r['pct'] ) ? (float) $r['pct'] : 10 ), home_url( 'rental' === $k ? '/car-rentals/' : '/services/' ) ) ); ?>">Preview <?php echo esc_html( ! empty( $r['pct'] ) ? (float) $r['pct'] : 10 ); ?>%</a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">“Preview” opens the page with that discount shown only to you (default 10% when the box is empty). Nothing is saved and visitors don't see it.</p>
				<?php submit_button( 'Save discounts' ); ?>
			</form>
		</div>
		<?php
	}
}
