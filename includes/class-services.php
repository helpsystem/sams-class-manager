<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Services showcase: [sdsc_services]
 * Each service is drawn as a real road-sign shape standing on a road; the signs rise
 * one after another (like dominoes) as the section scrolls into view, and the school
 * car drives along the road. Data is filterable via 'sdsc_services'.
 */
class SDSC_Services {

	public static function init() {
		add_shortcode( 'sdsc_services', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'sdsc-fonts', 'https://fonts.googleapis.com/css2?family=Overpass:wght@500;700;800;900&display=swap', array(), null );
		wp_register_style( 'sdsc-services', SDSC_URL . 'assets/services.css', array( 'sdsc-fonts', 'sdsc-brand' ), SDSC_VERSION );
		wp_register_script( 'sdsc-services', SDSC_URL . 'assets/services.js', array(), SDSC_VERSION, true );
	}

	/** Next upcoming class start (for the 36-hour sign caption). */
	protected static function next_class_label() {
		if ( ! class_exists( 'SDSC_Registration' ) ) { return ''; }
		$open = SDSC_Registration::open_classes();
		foreach ( $open as $c ) {
			$start = get_post_meta( $c->ID, '_sdsc_start', true );
			if ( $start ) { return 'Next class starts ' . date_i18n( 'M j', strtotime( $start ) ); }
		}
		return '';
	}

	public static function services() {
		$reg = home_url( '/register-course/' );
		$s   = array(
			array(
				'shape' => 'guide', 'tab' => 'Required by MVA',
				'l1' => "Driver's Ed", 'big' => '36', 'l2' => 'hours',
				'title' => "36-hour driver's education",
				'desc'  => '10 evenings on Zoom plus 6 hours behind the wheel. For teens and adults getting their first license.',
				'price' => '$378', 'cta' => 'Register', 'url' => $reg, 'note' => self::next_class_label(),
			),
			array(
				'shape' => 'limit',
				'l1' => 'Practice', 'big' => '$60', 'l2' => 'per hour',
				'title' => 'Behind-the-wheel lessons',
				'desc'  => 'Two-hour lessons in a dual-control car. Free pickup from Rockville Metro. Packages from 1 to 30 lessons.',
				'price' => 'from $120', 'cta' => 'Book a lesson', 'url' => 'https://buy.stripe.com/9B628r4XAdm54Qh0mzaMU1g',
			),
			array(
				'shape' => 'diamond', 'icon' => 'car',
				'l1' => 'Road test', 'big' => '', 'l2' => 'car rental',
				'title' => 'Car for your road test',
				'desc'  => 'Take your MVA road test in our school car.',
				'price' => '$140', 'cta' => 'Reserve the car', 'url' => 'https://buy.stripe.com/6oU9ATblYgyhdmNglxaMU1r',
			),
			array(
				'shape' => 'school',
				'l1' => 'Online', 'big' => '3', 'l2' => 'hours',
				'title' => '3-hour roadway safety program',
				'desc'  => 'The 3-hour MVA roadway safety program, taken fully online.',
				'price' => '$90', 'cta' => 'Book the course', 'url' => 'https://buy.stripe.com/eVq5kD2PsbdX96xd9laMU1A',
			),
			array(
				'shape' => 'stop',
				'l1' => 'Alcohol', 'big' => '&amp;', 'l2' => 'drug',
				'title' => '3-hour alcohol and drug program',
				'desc'  => 'The 3-hour alcohol and drug education program, for applicants the MVA requires it of.',
				'price' => 'Call for dates', 'cta' => 'Call us', 'url' => 'tel:+13017263030',
			),
			array(
				'shape' => 'yield',
				'l1' => '', 'big' => 'DIP', 'l2' => '',
				'title' => 'Driver improvement program',
				'desc'  => 'For drivers assigned the program by the MVA or a court.',
				'price' => 'Call for dates', 'cta' => 'Call us', 'url' => 'tel:+13017263030',
			),
			array(
				'shape' => 'info',
				'l1' => 'Nervous', 'big' => '', 'l2' => 'drivers', 'icon' => 'heart',
				'title' => 'Lessons for nervous drivers',
				'desc'  => 'Patient instructors, quiet streets first, and a pace set by you. Male and female instructors who speak English, Spanish and Farsi (Persian).',
				'price' => 'Call for details', 'cta' => 'Call us', 'url' => 'tel:+13017263030',
			),
		);
		return apply_filters( 'sdsc_services', $s );
	}

	protected static function icon( $name ) {
		if ( 'car' === $name ) {
			return '<svg class="sds-sign-icon" viewBox="0 0 64 32" aria-hidden="true"><path d="M6 22c0-3 2-5 5-5l7-1 7-6c2-1 4-2 6-2h10c2 0 4 1 6 2l6 6 5 1c2 0 4 2 4 5v3c0 1-1 2-2 2H8c-1 0-2-1-2-2z" fill="currentColor"/><circle cx="18" cy="26" r="5" fill="currentColor" stroke="#ffc20e" stroke-width="2.5"/><circle cx="48" cy="26" r="5" fill="currentColor" stroke="#ffc20e" stroke-width="2.5"/><path d="M27 15l5-4h7l5 4z" fill="#ffc20e"/></svg>';
		}
		if ( 'heart' === $name ) {
			return '<svg class="sds-sign-icon" viewBox="0 0 32 28" aria-hidden="true"><path d="M16 27S2 18.5 2 9.5A7 7 0 0 1 16 6a7 7 0 0 1 14 3.5C30 18.5 16 27 16 27z" fill="currentColor"/></svg>';
		}
		return '';
	}

	public static function shortcode( $atts ) {
		// The site-wide 3D sign set replaces the flat version of this showcase.
		if ( class_exists( 'SDSC_Signs' ) ) { return SDSC_Signs::render_overview( array() ); }
		wp_enqueue_style( 'sdsc-services' );
		wp_enqueue_script( 'sdsc-services' );
		ob_start();
		?>
		<section class="sds-road" aria-labelledby="sds-road-title">
			<div class="sds-road-head">
				<h2 id="sds-road-title">Every road to your license</h2>
				<p>Choose a sign to see what's included and book.</p>
			</div>
			<div class="sds-road-stage">
				<ul class="sds-signs">
					<?php foreach ( self::services() as $i => $s ) : ?>
						<li class="sds-sign-slot" style="--i:<?php echo (int) $i; ?>">
							<div class="sds-sign-stand">
								<div class="sds-sign sds-sign--<?php echo esc_attr( $s['shape'] ); ?>" aria-hidden="true">
									<?php if ( ! empty( $s['tab'] ) ) : ?><span class="sds-sign-tab"><?php echo esc_html( $s['tab'] ); ?></span><?php endif; ?>
									<span class="sds-sign-face">
										<?php if ( '' !== $s['l1'] ) : ?><span class="sds-sign-l1"><?php echo esc_html( $s['l1'] ); ?></span><?php endif; ?>
										<?php if ( ! empty( $s['icon'] ) ) { echo self::icon( $s['icon'] ); } // phpcs:ignore -- static svg ?>
										<?php if ( '' !== $s['big'] ) : ?><span class="sds-sign-big"><?php echo wp_kses( $s['big'], array() ); ?></span><?php endif; ?>
										<?php if ( '' !== $s['l2'] ) : ?><span class="sds-sign-l2"><?php echo esc_html( $s['l2'] ); ?></span><?php endif; ?>
									</span>
								</div>
								<span class="sds-sign-post" aria-hidden="true"></span>
							</div>
							<div class="sds-sign-card">
								<h3><?php echo esc_html( $s['title'] ); ?></h3>
								<p><?php echo esc_html( $s['desc'] ); ?></p>
								<?php if ( ! empty( $s['note'] ) ) : ?><p class="sds-sign-note"><?php echo esc_html( $s['note'] ); ?></p><?php endif; ?>
								<div class="sds-sign-foot">
									<span class="sds-sign-price<?php echo 0 === strpos( $s['price'], '$' ) || 0 === strpos( $s['price'], 'from $' ) ? '' : ' is-text'; ?>"><?php echo esc_html( $s['price'] ); ?></span>
									<a class="sds-sign-cta" href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['cta'] ); ?></a>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="sds-asphalt" aria-hidden="true">
					<span class="sds-lane"></span>
					<?php echo SDSC_Brand::car( 'left', 'sds-road-car' ); // phpcs:ignore -- escaped in helper ?>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}
}
