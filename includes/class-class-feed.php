<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Website listing of classes.
 * When switched on, the "Driver's Education Program" section on the homepage and Services page is
 * built from the classes in Class Manager (upcoming / active, not finished) instead of the text typed
 * into the theme. Each card links to the registration form with that class preselected, shows the
 * seats left, and the section carries Course schema for Google.
 * Option: sdsc_feed = array( on, reg_page, low_seats, limit ).
 */
class SDSC_Class_Feed {

	const OPT = 'sdsc_feed';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_sdsc_feed_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sdsc_sign_save', array( __CLASS__, 'save_sign' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'save_post_sdsc_class', array( __CLASS__, 'purge' ), 50 );
		add_action( 'save_post_sdsc_registrant', array( __CLASS__, 'purge' ), 50 );
		add_action( 'trashed_post', array( __CLASS__, 'purge' ) );
	}

	public static function opts() {
		$o = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array( 'on' => 0, 'reg_page' => 0, 'low_seats' => 5, 'limit' => 6 ) );
	}

	public static function on() {
		$o = self::opts();
		return ! empty( $o['on'] ) && class_exists( 'SDSC_Registration' ) && class_exists( 'SDSC_CPT_Classes' );
	}

	public static function reg_url() {
		$o = self::opts();
		if ( $o['reg_page'] && get_post_status( $o['reg_page'] ) ) { return get_permalink( $o['reg_page'] ); }
		$p = get_page_by_path( 'register-course' );
		return $p ? get_permalink( $p ) : home_url( '/register-course/' );
	}

	public static function seats_taken( $class_id ) {
		$q = new WP_Query( array(
			'post_type'      => 'sdsc_registrant',
			'post_status'    => array( 'publish', 'private', 'pending', 'draft' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_sdsc_class_id', 'value' => (int) $class_id ) ),
		) );
		return (int) $q->found_posts;
	}

	protected static function fmt_dates( $start, $end ) {
		$s = $start ? strtotime( substr( $start, 0, 10 ) ) : 0;
		$e = $end ? strtotime( substr( $end, 0, 10 ) ) : 0;
		if ( ! $s ) { return array( '', '' ); }
		$long  = date_i18n( 'F j', $s ) . ( $e ? ' – ' . date_i18n( 'F j', $e ) : '' );
		$short = strtoupper( date_i18n( 'M j', $s ) . ( $e ? ' – ' . date_i18n( 'M j', $e ) : '' ) );
		return array( $long, $short );
	}

	/**
	 * Cards for the courses section, in the same shape the theme parser produces.
	 * $fallback_desc: the school's own description from the theme, used when a class has no excerpt.
	 */
	public static function items( $fallback_desc = '' ) {
		$o     = self::opts();
		$reg   = self::reg_url();
		$items = array();
		foreach ( array_slice( SDSC_Registration::open_classes(), 0, max( 1, (int) $o['limit'] ) ) as $p ) {
			$id    = $p->ID;
			$code  = (string) get_post_meta( $id, '_sdsc_course_code', true );
			$days  = (string) get_post_meta( $id, '_sdsc_days_time', true );
			$price = (string) get_post_meta( $id, '_sdsc_price', true );
			$cap   = (int) get_post_meta( $id, '_sdsc_capacity', true );
			$start = (string) get_post_meta( $id, '_sdsc_start', true );
			$end   = (string) get_post_meta( $id, '_sdsc_end', true );
			list( $long, $short ) = self::fmt_dates( $start, $end );
			$left  = $cap > 0 ? max( 0, $cap - self::seats_taken( $id ) ) : -1;
			$name  = trim( preg_replace( '/\s*' . preg_quote( $code, '/' ) . '\s*$/', '', html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ) );
			$desc  = has_excerpt( $id ) ? get_the_excerpt( $p ) : $fallback_desc;
			$items[] = array(
				'id'    => $id,
				'code'  => $code,
				'when'  => trim( $long . ( $days ? ' · ' . $days : '' ) ),
				'dates' => $short,
				'time'  => strtoupper( str_replace( ',', ' · ', str_replace( ' ', '', $days ) ) ),
				'name'  => $name ? $name : html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'desc'  => wp_strip_all_tags( $desc ),
				'price' => is_numeric( $price ) ? '$' . number_format_i18n( (float) $price, floor( (float) $price ) == (float) $price ? 0 : 2 ) : $price,
				'raw'   => is_numeric( $price ) ? (float) $price : null,
				'url'   => 0 === $left ? '' : add_query_arg( 'course', rawurlencode( $code ), $reg ) . '#sdsc',
				'cta'   => 'Enroll Now',
				'left'  => $left,
				'low'   => $left > 0 && $left <= (int) $o['low_seats'],
				'start' => $start,
				'end'   => $end,
			);
		}
		return $items;
	}

	/** Course + CourseInstance structured data built only from the class records. */
	public static function schema( $items, $desc ) {
		if ( ! $items ) { return ''; }
		$instances = array();
		foreach ( $items as $c ) {
			if ( ! $c['start'] ) { continue; }
			$inst = array(
				'@type'      => 'CourseInstance',
				'courseMode' => 'Blended',
				'startDate'  => substr( $c['start'], 0, 10 ),
				'location'   => array( '@type' => 'Place', 'name' => 'Sam’s Driving School', 'address' => '751 Rockville Pike, Unit 9B, Rockville, MD 20852' ),
			);
			if ( $c['end'] ) { $inst['endDate'] = substr( $c['end'], 0, 10 ); }
			if ( null !== $c['raw'] ) {
				$inst['offers'] = array( '@type' => 'Offer', 'price' => $c['raw'], 'priceCurrency' => 'USD', 'url' => $c['url'] ? $c['url'] : self::reg_url(), 'availability' => 0 === $c['left'] ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock' );
			}
			$instances[] = $inst;
		}
		$data = array(
			'@context'          => 'https://schema.org',
			'@type'             => 'Course',
			'name'              => 'Maryland Driver Education Program',
			'description'       => $desc ? $desc : 'Maryland MVA driver education course.',
			'provider'          => array( '@type' => 'Organization', 'name' => 'Sam’s Driving School', 'url' => home_url( '/' ) ),
			'hasCourseInstance' => $instances,
		);
		return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	public static function purge() {
		if ( class_exists( 'SDSC_Discounts' ) ) { SDSC_Discounts::purge(); }
	}

	/* ------------------------------ admin ------------------------------ */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Website listing', 'Website listing', 'sdsc_access_feed', 'sdsc-feed', array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! SDSC_Access::can( 'feed' ) || ! check_admin_referer( 'sdsc_feed' ) ) { wp_die( 'Not allowed' ); }
		update_option( self::OPT, array(
			'on'        => empty( $_POST['on'] ) ? 0 : 1,
			'reg_page'  => absint( $_POST['reg_page'] ?? 0 ),
			'low_seats' => max( 0, min( 50, absint( $_POST['low_seats'] ?? 5 ) ) ),
			'limit'     => max( 1, min( 12, absint( $_POST['limit'] ?? 6 ) ) ),
		) );
		self::purge();
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-feed&saved=1' ) );
		exit;
	}

	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'sdsc-feed' ) ) { return; }
		wp_enqueue_style( 'sdsc-fonts', 'https://fonts.googleapis.com/css2?family=Overpass:wght@500;700;800;900&display=swap', array(), null );
		wp_enqueue_style( 'sdsc-brand', SDSC_URL . 'assets/brand.css', array(), SDSC_VERSION );
		wp_enqueue_style( 'sdsc-signs', SDSC_URL . 'assets/signs3d.css', array( 'sdsc-fonts', 'sdsc-brand' ), SDSC_VERSION );
	}

	public static function save_sign() {
		if ( ! SDSC_Access::can( 'feed' ) || ! check_admin_referer( 'sdsc_sign' ) ) { wp_die( 'Not allowed' ); }
		$k = sanitize_key( wp_unslash( $_POST['sign'] ?? '' ) );
		if ( class_exists( 'SDSC_Signs' ) && isset( SDSC_Signs::SIGN_STYLES[ $k ] ) ) { update_option( 'sdsc_class_sign', $k ); }
		self::purge();
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-feed&saved=sign#sdsc-sign' ) );
		exit;
	}

	/** Template picker: every sign style drawn with a real (or sample) class, Saghar picks one. */
	protected static function sign_picker() {
		if ( ! class_exists( 'SDSC_Signs' ) ) { return; }
		$items = class_exists( 'SDSC_Registration' ) ? self::items() : array();
		$c     = $items ? $items[0] : array( 'code' => 'C-68', 'name' => 'Driver Education PM Class', 'price' => '$250', 'now' => '$250', 'd' => null, 'left' => 12, 'low' => false, 'dates' => 'OCT 15 – NOV 15', 'time' => 'MON–THU · 5:30–8:30PM' );
		$c['d'] = null; $c['now'] = $c['price'];
		$cur   = SDSC_Signs::sign_style();
		?>
		<h2 id="sdsc-sign" style="margin-top:34px">Class sign style</h2>
		<p style="max-width:760px">Pick how every class is drawn on the homepage and the Services page. All of them are road signs and all show the same facts &mdash; dates, days and time, price, and a traffic light for seats (green: open, yellow: few seats left, red: full). The previews below use <?php echo $items ? 'your next class' : 'a sample class'; ?>.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sdsc_sign' ); ?>
			<input type="hidden" name="action" value="sdsc_sign_save" />
			<div class="sdsc-pick">
				<?php foreach ( SDSC_Signs::SIGN_STYLES as $k => $info ) : ?>
					<label class="<?php echo $k === $cur ? 'is-on' : ''; ?>">
						<input type="radio" name="sign" value="<?php echo esc_attr( $k ); ?>" <?php checked( $k, $cur ); ?> />
						<div class="sdsc-pick-prev"><div class="sdsc3d-item"><?php echo SDSC_Signs::class_sign( $c, $c['dates'] ?? '', $c['time'] ?? '', $k ); // phpcs:ignore -- escaped in the sign builders ?></div></div>
						<p class="sdsc-pick-name"><?php echo esc_html( $info[0] ); ?> <em>In use</em></p>
						<p class="sdsc-pick-desc"><?php echo esc_html( $info[1] ); ?> <a href="<?php echo esc_url( add_query_arg( 'sdsc_sign', $k, home_url( '/' ) ) ); ?>" target="_blank">See it on the site</a></p>
					</label>
				<?php endforeach; ?>
			</div>
			<?php submit_button( 'Use the selected sign' ); ?>
		</form>
		<script>document.querySelectorAll('.sdsc-pick input').forEach(function(r){r.addEventListener('change',function(){document.querySelectorAll('.sdsc-pick label').forEach(function(l){l.classList.toggle('is-on',l.contains(r));});});});</script>
		<?php
	}

	public static function page() {
		$o = self::opts();
		$items = class_exists( 'SDSC_Registration' ) ? self::items() : array();
		?>
		<div class="wrap">
			<h1>Website listing</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo ( isset( $_GET['saved'] ) && 'sign' === $_GET['saved'] ) ? 'Sign style saved. The homepage and Services page now use it.' : 'Saved. The homepage and Services page now show the change.'; // phpcs:ignore ?></p></div><?php endif; ?>
			<p style="max-width:760px">When this is on, the <strong>Driver&rsquo;s Education Program</strong> section on the homepage and the Services page lists the classes you add in Class Manager &mdash; dates, days and time, price and seats left &mdash; and each <em>Enroll Now</em> button opens the registration form with that class already chosen. A class disappears from the site by itself when it is finished or cancelled, and shows &ldquo;Class full&rdquo; when every seat is taken. When it is off, the site shows the text typed in the theme, as before.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sdsc_feed' ); ?>
				<input type="hidden" name="action" value="sdsc_feed_save" />
				<table class="form-table" role="presentation">
					<tr><th scope="row">List classes from Class Manager</th><td><label><input type="checkbox" name="on" value="1" <?php checked( $o['on'], 1 ); ?> /> On</label></td></tr>
					<tr><th scope="row">Registration page</th><td><?php wp_dropdown_pages( array( 'name' => 'reg_page', 'selected' => (int) $o['reg_page'], 'show_option_none' => 'Automatic (/register-course/)', 'option_none_value' => 0 ) ); ?></td></tr>
					<tr><th scope="row">Show &ldquo;only N seats left&rdquo; at</th><td><input type="number" min="0" max="50" name="low_seats" value="<?php echo esc_attr( $o['low_seats'] ); ?>" class="small-text" /> seats or fewer</td></tr>
					<tr><th scope="row">Classes to show</th><td><input type="number" min="1" max="12" name="limit" value="<?php echo esc_attr( $o['limit'] ); ?>" class="small-text" /></td></tr>
				</table>
				<?php submit_button( 'Save' ); ?>
			</form>
			<?php self::sign_picker(); ?>
			<h2>What the site will show</h2>
			<?php if ( ! $items ) : ?>
				<p>No upcoming classes. Add one under <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=sdsc_class' ) ); ?>">Classes</a> (status &ldquo;Upcoming&rdquo;).</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px"><thead><tr><th>Class</th><th>When</th><th>Price</th><th>Seats left</th><th>Enroll link</th></tr></thead><tbody>
				<?php foreach ( $items as $c ) : ?>
					<tr><td><a href="<?php echo esc_url( get_edit_post_link( $c['id'] ) ); ?>"><?php echo esc_html( $c['name'] . ( $c['code'] ? ' (' . $c['code'] . ')' : '' ) ); ?></a></td>
					<td><?php echo esc_html( $c['when'] ); ?></td><td><?php echo esc_html( $c['price'] ); ?></td>
					<td><?php echo $c['left'] < 0 ? '&mdash;' : esc_html( $c['left'] ); ?></td>
					<td><?php echo $c['url'] ? '<a href="' . esc_url( $c['url'] ) . '" target="_blank">open</a>' : 'Full'; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="description">Payment: after registering, the student is sent to the class&rsquo;s own Stripe payment link (set on each class). Please check every class has the right link and price before turning this on.</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
