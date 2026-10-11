<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Site chrome: header + menu, the Maryland hero, and the light-beam wordmark footer.
 *
 * The theme calls these from header.php, template-parts/home-banner.php and footer.php
 * (each with its original markup as the fallback), so turning this off restores the old look:
 *   wp option update sdsc_chrome 0
 */
class SDSC_Chrome {

	const PHONE_MAIN = '(301) 726-3030';
	const PHONE_ALT  = '(202) 600-0889';
	const ADDRESS    = '751 Rockville Pike, Unit 9B, Rockville, MD 20852';
	const MAPS       = 'https://www.google.com/maps/dir/?api=1&destination=Sam%27s+Driving+School%2C+751+Rockville+Pike%2C+Unit+9B%2C+Rockville%2C+MD+20852&destination_place_id=ChIJ_4Zgdy7Nt4kRwf6CVIqIVvM';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 7 );
	}

	public static function on() {
		return '0' !== get_option( 'sdsc_chrome', '1' );
	}

	public static function assets() {
		if ( ! self::on() ) { return; }
		wp_enqueue_style( 'sdsc-chrome-fonts', 'https://fonts.googleapis.com/css2?family=Overpass:wght@400;600;700;800;900&display=swap', array(), null );
		wp_enqueue_style( 'sdsc-chrome', SDSC_URL . 'assets/chrome.css', array( 'sdsc-chrome-fonts', 'sdsc-brand' ), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-chrome', SDSC_URL . 'assets/chrome.js', array(), SDSC_VERSION, true );
		if ( is_front_page() ) {
			wp_enqueue_style( 'sdsc-hero-sky', SDSC_URL . 'assets/hero-sky.css', array( 'sdsc-chrome' ), SDSC_VERSION );
			wp_enqueue_script( 'sdsc-hero-sky', SDSC_URL . 'assets/hero-sky.js', array(), SDSC_VERSION, true );
			wp_localize_script( 'sdsc-hero-sky', 'SDSC_SKY', array( 'wx' => esc_url_raw( rest_url( 'sdsc/v1/weather' ) ) ) );
		}
	}

	protected static function tel( $p ) {
		return 'tel:+1' . preg_replace( '/\D/', '', $p );
	}

	protected static function phones() {
		$a = get_theme_mod( 'ruzbahani_phone_2', self::PHONE_MAIN );
		$b = get_theme_mod( 'ruzbahani_phone_1', self::PHONE_ALT );
		$fmt = function ( $p ) {
			$d = preg_replace( '/\D/', '', $p );
			return 10 === strlen( $d ) ? '(' . substr( $d, 0, 3 ) . ') ' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 ) : $p;
		};
		return array_values( array_filter( array( $a ? $fmt( $a ) : '', $b ? $fmt( $b ) : '' ) ) );
	}

	protected static function icon( $name ) {
		$i = array(
			'phone' => '<path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z" fill="currentColor"/>',
			'pin'   => '<path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="currentColor"/>',
			'fb'    => '<path d="M14 8h3V4h-3c-2.8 0-4.5 1.9-4.5 4.6V11H7v4h2.5v7h4v-7h3l.5-4h-3.5V8.8c0-.5.3-.8.5-.8z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
			'ig'    => '<g fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="3.9"/><circle cx="17.1" cy="6.9" r=".6" fill="currentColor" stroke="none"/></g>',
			'arrow' => '<path d="M2.5 9.5l7-7M4 2.5h5.5V8" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>',
		);
		$vb = 'arrow' === $name ? '0 0 12 12' : '0 0 24 24';
		return '<svg viewBox="' . $vb . '" aria-hidden="true" focusable="false">' . $i[ $name ] . '</svg>';
	}

	/** Phone number drawn in the logo's style: glossy SAM-green plate with a little traffic light. */
	public static function phone_chip( $p, $size = '' ) {
		return '<a class="sdx-ph' . ( $size ? ' sdx-ph--' . esc_attr( $size ) : '' ) . '" href="' . esc_attr( self::tel( $p ) ) . '" aria-label="Call ' . esc_attr( $p ) . '">'
			. '<span class="sdx-ph-tl" aria-hidden="true"><i></i><i></i><i></i></span><span class="sdx-ph-n">' . esc_html( $p ) . '</span></a>';
	}

	/** The SAM's wordmark traced from the logo (vector), used where the full logo would be too wide. */
	public static function wordmark( $class = '', $h = 24 ) {
		return '<img class="' . esc_attr( trim( 'sdx-wm ' . $class ) ) . '" src="' . esc_url( SDSC_URL . 'assets/img/sams-wordmark.svg?v=' . SDSC_VERSION ) . '" width="' . (int) round( $h * 3.05 ) . '" height="' . (int) $h . '" alt="" decoding="async" />';
	}

	/**
	 * The full logo with its traffic light switched on: three lamps are laid exactly over the lamps
	 * printed in the logo image and cycle red → green → yellow, like the wordmark and the phone plates.
	 */
	public static function logo_full( $img, $class = '' ) {
		return '<span class="' . esc_attr( trim( 'sdx-logo ' . $class ) ) . '">' . $img . '<span class="sdx-logo-tl" aria-hidden="true"><i></i><i></i><i></i></span></span>';
	}

	protected static function logo_img() {
		$id = (int) get_theme_mod( 'custom_logo' );
		if ( $id ) {
			$img = wp_get_attachment_image( $id, 'full', false, array( 'class' => 'sdh-logo-img', 'alt' => "Sam's Driving School", 'loading' => 'eager' ) );
			if ( $img ) { return $img; }
		}
		return SDSC_Brand::logo( 'wide', 'sdh-logo-img', 52 );
	}

	/* ================================================================ *
	 * Header
	 * ================================================================ */

	public static function header() {
		$phones = self::phones();
		$enroll = home_url( '/services/' );
		?>
		<header class="sdh" data-sdh>
			<a class="sdh-skip" href="#sdsc-main">Skip to content</a>
			<div class="sdh-top">
				<div class="sdh-wrap">
					<div class="sdh-top-l">
						<?php foreach ( $phones as $p ) { echo self::phone_chip( $p, 'sm' ); } // phpcs:ignore -- escaped in helper ?>
					</div>
					<a class="sdh-top-mark" href="<?php echo esc_url( home_url( '/' ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo self::logo_full( self::logo_img() ); // phpcs:ignore ?></a>
					<a class="sdh-addr" href="<?php echo esc_url( self::MAPS ); ?>" data-sdsc-nav target="_blank" rel="noopener"><?php echo self::icon( 'pin' ); // phpcs:ignore ?><span><?php echo esc_html( self::ADDRESS ); ?></span></a>
				</div>
			</div>
			<div class="sdh-bar">
				<div class="sdh-wrap">
					<a class="sdh-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Sam's Driving School — home"><?php echo self::logo_full( self::logo_img() ); // phpcs:ignore -- core-escaped ?></a>
					<nav class="sdh-nav" id="sdh-nav" aria-label="Main">
						<div class="sdh-drawer-logo" aria-hidden="true"><?php echo self::logo_full( self::logo_img() ); // phpcs:ignore ?></div>
						<?php
						wp_nav_menu( array(
							'theme_location' => 'primary',
							'menu_class'     => 'sdh-menu',
							'container'      => false,
							'fallback_cb'    => false,
							'depth'          => 2,
						) );
						?>
						<div class="sdh-drawer-cta">
							<a class="sdh-btn sdh-btn--go" href="<?php echo esc_url( $enroll ); ?>">See classes &amp; prices</a>
							<?php foreach ( $phones as $p ) { echo self::phone_chip( $p, 'lg' ); } // phpcs:ignore -- escaped in helper ?>
							<p class="sdh-drawer-addr"><?php echo esc_html( self::ADDRESS ); ?></p>
						</div>
					</nav>
					<div class="sdh-cta">
						<?php if ( $phones ) : ?>
							<a class="sdh-call" href="<?php echo esc_attr( self::tel( $phones[0] ) ); ?>"><span class="sdh-call-ic"><?php echo self::icon( 'phone' ); // phpcs:ignore ?></span><span class="sdh-call-t"><small>Call us</small><?php echo esc_html( $phones[0] ); ?></span></a>
							<?php if ( isset( $phones[1] ) ) : ?><span class="sdh-call-2"><?php echo self::phone_chip( $phones[1], 'sm' ); // phpcs:ignore ?></span><?php endif; ?>
						<?php endif; ?>
						<a class="sdh-btn sdh-btn--go" href="<?php echo esc_url( $enroll ); ?>">Enroll now</a>
					</div>
					<button class="sdh-burger" type="button" aria-controls="sdh-nav" aria-expanded="false" aria-label="Open menu"><span></span><span></span><span></span></button>
				</div>
			</div>
		</header>
		<div id="sdsc-main" tabindex="-1"></div>
		<?php
	}

	/* ================================================================ *
	 * Maryland hero (replaces .banner-sec)
	 * ================================================================ */

	/** Black-eyed Susan (Maryland state flower). */
	public static function susan_svg( $size = 22 ) {
		$petals = '';
		for ( $i = 0; $i < 12; $i++ ) { $petals .= '<ellipse cx="12" cy="5" rx="2.1" ry="5" transform="rotate(' . ( $i * 30 ) . ' 12 12)"/>'; }
		return '<svg class="mdh-susan" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" aria-hidden="true"><g fill="#f6b416">' . $petals . '</g><circle cx="12" cy="12" r="4.2" fill="#3b2412"/><circle cx="11" cy="11" r="1.3" fill="#6b4422"/></svg>';
	}

	/** Roadside strip of Black-eyed Susans, as a data URI tile (400x40). */
	protected static function susans_tile_uri() {
		$g = '';
		$xs = array( 14, 52, 88, 131, 170, 214, 251, 290, 333, 371 );
		foreach ( $xs as $k => $x ) {
			$h = 18 + ( $k * 7 ) % 12; $y = 40 - $h;
			$g .= '<path d="M' . $x . ' 40 V' . ( $y + 4 ) . '" stroke="#2f5d2a" stroke-width="2"/>';
			$g .= '<path d="M' . $x . ' ' . ( 34 - $k % 3 ) . ' q-7 -3 -9 -9 q7 1 9 7z" fill="#3c7a33"/>';
			$petals = '';
			for ( $i = 0; $i < 10; $i++ ) { $petals .= '<ellipse cx="' . $x . '" cy="' . ( $y - 4 ) . '" rx="1.5" ry="4" transform="rotate(' . ( $i * 36 ) . ' ' . $x . ' ' . $y . ')"/>'; }
			$g .= '<g fill="#f6b416">' . $petals . '</g><circle cx="' . $x . '" cy="' . $y . '" r="2.8" fill="#3b2412"/>';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="40" viewBox="0 0 400 40"><path d="M0 40 Q50 30 100 36 T200 34 T300 37 T400 35 V40z" fill="#1d3a1c"/>' . $g . '</svg>';
		return 'data:image/svg+xml,' . rawurlencode( $svg );
	}

	/** Screw-pile lighthouse in the Chesapeake Bay (like Thomas Point Shoal), shaded as a 3D hexagonal cottage. */
	protected static function lighthouse_svg() {
		return '<svg class="mdh-lh-svg" viewBox="0 0 140 170" aria-hidden="true"><defs>'
			. '<linearGradient id="mdlPile" x1="0" x2="1"><stop offset="0" stop-color="#5b6d92"/><stop offset=".45" stop-color="#2a3b60"/><stop offset="1" stop-color="#101a33"/></linearGradient>'
			. '<linearGradient id="mdlDeck" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#3a4c74"/><stop offset="1" stop-color="#121d38"/></linearGradient>'
			. '<linearGradient id="mdlWl" x1="0" x2="1"><stop offset="0" stop-color="#fffdf6"/><stop offset="1" stop-color="#ece5d2"/></linearGradient>'
			. '<linearGradient id="mdlWc" x1="0" x2="1"><stop offset="0" stop-color="#e6decb"/><stop offset="1" stop-color="#c9c0aa"/></linearGradient>'
			. '<linearGradient id="mdlWr" x1="0" x2="1"><stop offset="0" stop-color="#b3aa94"/><stop offset="1" stop-color="#7f7765"/></linearGradient>'
			. '<linearGradient id="mdlRl" x1="0" x2="1"><stop offset="0" stop-color="#e2474c"/><stop offset="1" stop-color="#b82a30"/></linearGradient>'
			. '<linearGradient id="mdlRr" x1="0" x2="1"><stop offset="0" stop-color="#8f1d23"/><stop offset="1" stop-color="#5e1015"/></linearGradient>'
			. '<radialGradient id="mdlGlow"><stop offset="0" stop-color="#fff8d6" stop-opacity=".95"/><stop offset=".35" stop-color="#ffd65a" stop-opacity=".55"/><stop offset="1" stop-color="#ffd65a" stop-opacity="0"/></radialGradient>'
			. '<linearGradient id="mdlGlass" x1="0" x2="1"><stop offset="0" stop-color="#fffbe2"/><stop offset=".5" stop-color="#ffe17a"/><stop offset="1" stop-color="#e0a92c"/></linearGradient>'
			. '</defs>'
			// water ring + reflection
			. '<ellipse cx="70" cy="163" rx="58" ry="5" fill="#0c1a36" opacity=".55"/><ellipse cx="70" cy="163" rx="40" ry="2.4" fill="none" stroke="#6f8fc4" stroke-opacity=".45" class="mdh-lh-ring"/>'
			// screw piles (back ones darker, front ones lit)
			. '<g stroke-linecap="round"><path d="M44 160 L54 118 M96 160 L86 118" stroke="#16223f" stroke-width="4"/>'
			. '<path d="M28 162 L46 118 M112 162 L94 118 M70 164 V118" stroke="url(#mdlPile)" stroke-width="5"/>'
			. '<path d="M38 140 H102 M33 152 L107 152" stroke="#1d2b4c" stroke-width="2"/><path d="M46 120 L70 150 L94 120" stroke="#22325a" stroke-width="1.6" fill="none"/></g>'
			// deck with railing (slight perspective: top face + front face)
			. '<path d="M18 112 L30 104 H110 L122 112z" fill="#4a5d86"/><rect x="18" y="112" width="104" height="8" fill="url(#mdlDeck)"/>'
			. '<g stroke="#e9e4d6" stroke-width="1.3"><path d="M20 104 H120"/><path d="M24 104 v8 M36 104 v8 M48 104 v8 M60 104 v8 M72 104 v8 M84 104 v8 M96 104 v8 M108 104 v8 M118 104 v8"/></g>'
			// hexagonal cottage: three visible faces
			. '<polygon points="30,104 44,108 44,74 30,72" fill="url(#mdlWl)"/><polygon points="44,108 96,108 96,74 44,74" fill="url(#mdlWc)"/><polygon points="96,108 110,104 110,72 96,74" fill="url(#mdlWr)"/>'
			. '<g fill="#ffd27a"><rect x="34" y="82" width="6" height="11" opacity=".9"/><rect x="52" y="82" width="8" height="12"/><rect x="66" y="82" width="8" height="12" class="mdh-lh-win"/><rect x="80" y="82" width="8" height="12"/><rect x="100" y="82" width="6" height="11" opacity=".55"/></g>'
			. '<path d="M44 74 V108 M96 74 V108" stroke="#8b8370" stroke-width=".8"/>'
			// hipped roof faces
			. '<polygon points="26,73 44,75 70,50" fill="url(#mdlRl)"/><polygon points="44,75 96,75 70,50" fill="#c9343a"/><polygon points="96,75 114,73 70,50" fill="url(#mdlRr)"/>'
			. '<path d="M26 73 L44 75 H96 L114 73" stroke="#5e1015" stroke-width="1.4" fill="none"/>'
			// lantern room
			. '<circle cx="70" cy="34" r="30" fill="url(#mdlGlow)" class="mdh-lh-halo"/>'
			. '<rect x="58" y="44" width="24" height="8" fill="#2a2f3a"/><path d="M55 44 H85" stroke="#c9cfd6" stroke-width="1.6"/>'
			. '<rect x="60" y="27" width="20" height="17" rx="1" fill="url(#mdlGlass)" class="mdh-lh-lamp"/><path d="M66 27 V44 M74 27 V44" stroke="#2a2f3a" stroke-width="1.4"/>'
			. '<path d="M56 28 Q70 12 84 28z" fill="#b82a30"/><path d="M70 28 Q77 18 84 28z" fill="#7d171c"/>'
			. '<rect x="69" y="8" width="2" height="9" fill="#2a2f3a"/><circle cx="70" cy="8" r="2" fill="#2a2f3a"/>'
			. '</svg>';
	}

	/** Skipjack — Maryland's state boat — with its raked mast and big leg-o-mutton sail. */
	protected static function skipjack_svg() {
		return '<svg viewBox="0 0 140 110" aria-hidden="true"><path d="M58 8 L52 86" stroke="#2a2f3a" stroke-width="3"/>'
			. '<path d="M57 12 L104 84 L54 84z" fill="#f4efe2"/><path d="M53 30 L20 84 L50 84z" fill="#e6dfcc"/>'
			. '<path d="M6 86 H132 L120 100 H22z" fill="#f2efe6"/><path d="M6 86 H132" stroke="#1b2b4d" stroke-width="3"/><path d="M110 92 L140 82" stroke="#2a2f3a" stroke-width="2.5"/></svg>';
	}

	/**
	 * Baltimore oriole (state bird) built as a small 3D object: a shaded body plus two wings that
	 * really rotate in 3D around the shoulder (CSS perspective), so they foreshorten as they flap.
	 */
	protected static function oriole_html( $mod ) {
		$wing = '<svg viewBox="0 0 40 30" aria-hidden="true"><path d="M2 29 Q6 12 20 4 Q32 -1 39 2 Q33 9 30 16 Q22 26 12 29z" fill="url(#mdoWing)"/>'
			. '<path d="M10 24 Q17 15 29 9" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round" opacity=".9"/>'
			. '<path d="M30 16 L37 13 M27 20 L35 18 M24 24 L32 23" stroke="#000" stroke-width="1.6"/></svg>';
		$body = '<svg viewBox="0 0 70 34" aria-hidden="true"><defs>'
			. '<radialGradient id="mdoBody" cx=".45" cy=".3" r=".8"><stop offset="0" stop-color="#ffc06a"/><stop offset=".45" stop-color="#f7861f"/><stop offset="1" stop-color="#b9500c"/></radialGradient>'
			. '<radialGradient id="mdoHead" cx=".4" cy=".3" r=".8"><stop offset="0" stop-color="#4a4a4a"/><stop offset=".6" stop-color="#121212"/><stop offset="1" stop-color="#000"/></radialGradient>'
			. '<linearGradient id="mdoWing" x1="0" x2="1" y1="1" y2="0"><stop offset="0" stop-color="#050505"/><stop offset=".6" stop-color="#262626"/><stop offset="1" stop-color="#3d3d3d"/></linearGradient>'
			. '</defs>'
			. '<path d="M14 18 L1 13 L4 19 L1 25z" fill="#111"/><path d="M5 15 L14 18 L5 23z" fill="#f47a1f"/>'
			. '<ellipse cx="32" cy="19" rx="20" ry="9.5" fill="url(#mdoBody)"/>'
			. '<ellipse cx="30" cy="14" rx="11" ry="3" fill="#fff" opacity=".28"/>'
			. '<circle cx="51" cy="13" r="8" fill="url(#mdoHead)"/><path d="M44 18 Q48 23 54 20" fill="#0a0a0a"/>'
			. '<path d="M58 12 L69 14 L58 16z" fill="#9aa1ab"/><path d="M58 14 L69 14 L58 16z" fill="#6b727c"/>'
			. '<circle cx="53.5" cy="11" r="1.6" fill="#000"/><circle cx="54" cy="10.5" r=".6" fill="#fff"/></svg>';
		return '<span class="mdh-oriole ' . esc_attr( $mod ) . '"><span class="mdo">'
			. '<span class="mdo-wing mdo-wing--far">' . $wing . '</span>'
			. '<span class="mdo-body">' . $body . '</span>'
			. '<span class="mdo-wing mdo-wing--near">' . $wing . '</span>'
			. '</span></span>';
	}

	/** Maryland blue crab, shaded with a domed shell, red-tipped claws and stalk eyes. */
	protected static function crab_svg() {
		$leg = function ( $d ) { return '<path d="' . $d . '" />'; };
		return '<svg viewBox="0 0 100 60" aria-hidden="true"><defs>'
			. '<radialGradient id="mdcShell" cx=".45" cy=".25" r=".85"><stop offset="0" stop-color="#9cc8f5"/><stop offset=".35" stop-color="#4f8fd8"/><stop offset=".75" stop-color="#2a63ad"/><stop offset="1" stop-color="#173f78"/></radialGradient>'
			. '<linearGradient id="mdcLeg" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#5b97dc"/><stop offset="1" stop-color="#1f4d8c"/></linearGradient>'
			. '<radialGradient id="mdcClaw" cx=".3" cy=".3" r=".9"><stop offset="0" stop-color="#7fb2ee"/><stop offset=".6" stop-color="#2f6cb8"/><stop offset="1" stop-color="#1b4580"/></radialGradient>'
			. '<linearGradient id="mdcTip" x1="0" x2="1"><stop offset="0" stop-color="#ff8a5c"/><stop offset="1" stop-color="#c8341c"/></linearGradient>'
			. '<radialGradient id="mdcShadow"><stop offset="0" stop-color="#000" stop-opacity=".55"/><stop offset="1" stop-color="#000" stop-opacity="0"/></radialGradient>'
			. '</defs>'
			. '<ellipse cx="50" cy="54" rx="40" ry="5" fill="url(#mdcShadow)"/>'
			. '<g fill="none" stroke="url(#mdcLeg)" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round">'
			. '<g class="mdh-legs mdh-legs--a">' . $leg( 'M30 34 L18 40 L12 52' ) . $leg( 'M36 37 L28 46 L26 56' ) . $leg( 'M64 37 L72 46 L74 56' ) . $leg( 'M70 34 L82 40 L88 52' ) . '</g>'
			. '<g class="mdh-legs mdh-legs--b">' . $leg( 'M33 36 L22 44 L18 55' ) . $leg( 'M67 36 L78 44 L82 55' ) . '</g>'
			. '<path d="M74 31 L90 33 L97 28 L94 36z" stroke-width="2.2" fill="#2a63ad"/><path d="M26 31 L10 33 L3 28 L6 36z" stroke-width="2.2" fill="#2a63ad"/>'
			. '</g>'
			// claws
			. '<path d="M30 26 Q20 20 16 14" stroke="#2f6cb8" stroke-width="4" fill="none" stroke-linecap="round"/><path d="M70 26 Q80 20 84 14" stroke="#2f6cb8" stroke-width="4" fill="none" stroke-linecap="round"/>'
			. '<g class="mdh-claw mdh-claw--l"><ellipse cx="14" cy="11" rx="8" ry="5.5" transform="rotate(-30 14 11)" fill="url(#mdcClaw)"/><path d="M8 6 Q2 2 1 6 Q4 9 9 9z" fill="url(#mdcTip)"/><path d="M10 13 Q3 14 2 11 Q6 9 10 10z" fill="url(#mdcTip)"/></g>'
			. '<g class="mdh-claw mdh-claw--r"><ellipse cx="86" cy="11" rx="8" ry="5.5" transform="rotate(30 86 11)" fill="url(#mdcClaw)"/><path d="M92 6 Q98 2 99 6 Q96 9 91 9z" fill="url(#mdcTip)"/><path d="M90 13 Q97 14 98 11 Q94 9 90 10z" fill="url(#mdcTip)"/></g>'
			// shell with lateral spines
			. '<path d="M10 28 L22 22 Q50 8 78 22 L90 28 L78 31 Q50 44 22 31z" fill="url(#mdcShell)"/>'
			. '<path d="M22 31 Q50 44 78 31 Q50 38 22 31z" fill="#123766" opacity=".55"/>'
			. '<ellipse cx="44" cy="20" rx="17" ry="4.5" fill="#fff" opacity=".32"/><ellipse cx="40" cy="19" rx="6" ry="1.6" fill="#fff" opacity=".55"/>'
			. '<path d="M30 24 Q50 28 70 24" stroke="#1d4a86" stroke-width="1" fill="none" opacity=".6"/>'
			// stalk eyes
			. '<path d="M45 16 V11 M55 16 V11" stroke="#1d4a86" stroke-width="2"/><circle cx="45" cy="10" r="2.4" fill="#0b0b0b"/><circle cx="55" cy="10" r="2.4" fill="#0b0b0b"/><circle cx="45.8" cy="9.2" r=".8" fill="#fff"/><circle cx="55.8" cy="9.2" r=".8" fill="#fff"/>'
			. '</svg>';
	}

	/** Sign supports: two posts for wide signs, one centred post for small ones. */
	protected static function posts( $kind ) {
		if ( 'one' === $kind ) { return '<span class="mdh-post mdh-post--c"></span>'; }
		if ( 'bb' === $kind ) { return '<span class="mdh-post mdh-post--beam mdh-post--l"></span><span class="mdh-post mdh-post--beam mdh-post--r"></span><span class="mdh-brace"></span>'; }
		return '<span class="mdh-post mdh-post--l"></span><span class="mdh-post mdh-post--r"></span>';
	}

	/** Brand signs that drive past: only facts the site already states. */
	protected static function brand_track() {
		$g = class_exists( 'SDSC_WhyUs' ) ? SDSC_WhyUs::google() : null;
		$logo = SDSC_Brand::img( 'sams-logo-wide.webp' );
		$o  = '<div class="mdh-track">';
		$o .= '<div class="mdh-guide"><div class="mdh-guide-face"><span class="mdh-guide-l1">Rockville</span><span class="mdh-guide-l2">Sam&rsquo;s Driving School</span><span class="mdh-guide-arrow">&#8599;</span></div>' . self::posts( 'two' ) . '</div>';
		if ( $g ) {
			$o .= '<div class="mdh-bb"><div class="mdh-bb-face">' . self::logo_full( '<img src="' . esc_url( $logo ) . '" alt="" width="120" height="24"/>', 'sdx-logo--bb' ) . '<b>' . esc_html( $g['score'] ) . ' <i>&#9733;&#9733;&#9733;&#9733;&#9733;</i></b><span>on Google &middot; ' . esc_html( $g['count'] ) . ' reviews</span></div><span class="mdh-bb-lamp"></span>' . self::posts( 'bb' ) . '</div>';
		}
		$o .= '<div class="mdh-diamond"><div class="mdh-diamond-face"><span>STUDENT<br>DRIVER<br>AHEAD</span></div>' . self::posts( 'one' ) . '</div>';
		$o .= '<div class="mdh-bb mdh-bb--dark"><div class="mdh-bb-face"><b>Best of 2025</b><span>Rockville &middot; BusinessRate award</span></div><span class="mdh-bb-lamp"></span>' . self::posts( 'bb' ) . '</div>';
		$o .= '<div class="mdh-guide mdh-guide--blue"><div class="mdh-guide-face"><span class="mdh-guide-l1">Women-owned</span><span class="mdh-guide-l2">&amp; operated</span></div>' . self::posts( 'two' ) . '</div>';
		$o .= '<div class="mdh-guide"><div class="mdh-guide-face"><span class="mdh-guide-l2">Free pickup</span><span class="mdh-guide-l1">Rockville Metro</span></div>' . self::posts( 'two' ) . '</div>';
		$o .= '<div class="mdh-bb"><div class="mdh-bb-face"><b>Male &amp; female instructors</b><span>English &middot; Spanish &middot; Farsi (Persian)</span></div><span class="mdh-bb-lamp"></span>' . self::posts( 'bb' ) . '</div>';
		return $o . '</div>';
	}

	/** Far skyline: Baltimore-style towers with the Annapolis State House dome in the middle. One tile, 1600x320. */
	protected static function skyline_svg() {
		$towers = array(
			array( 20, 150, 60 ), array( 90, 210, 46 ), array( 145, 120, 70 ), array( 225, 240, 40 ), array( 272, 175, 58 ),
			array( 340, 130, 52 ), array( 400, 260, 44 ), array( 452, 190, 64 ), array( 525, 140, 48 ),
			array( 980, 150, 54 ), array( 1042, 230, 44 ), array( 1094, 170, 70 ), array( 1172, 285, 40 ), array( 1220, 205, 60 ),
			array( 1288, 140, 50 ), array( 1346, 250, 46 ), array( 1400, 185, 66 ), array( 1474, 130, 52 ), array( 1534, 205, 50 ),
		);
		$s = '<svg class="mdh-tile" viewBox="0 0 1600 320" preserveAspectRatio="xMidYMax slice" aria-hidden="true">';
		$s .= '<defs><pattern id="mdhWin" width="12" height="16" patternUnits="userSpaceOnUse"><rect x="3" y="4" width="4" height="6" fill="#ffd27a" opacity=".55"/></pattern>'
			. '<pattern id="mdhWin2" width="14" height="18" patternUnits="userSpaceOnUse"><rect x="4" y="5" width="5" height="6" fill="#ffe6ad" opacity=".35"/></pattern></defs>';
		foreach ( $towers as $k => $t ) {
			list( $x, $h, $w ) = $t;
			$y = 320 - $h;
			$s .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" fill="#18284a" style="fill:var(--mdh-bld,#18284a)"/>';
			$s .= '<rect x="' . ( $x + 4 ) . '" y="' . ( $y + 8 ) . '" width="' . ( $w - 8 ) . '" height="' . ( $h - 8 ) . '" fill="url(#' . ( $k % 2 ? 'mdhWin' : 'mdhWin2' ) . ')" style="opacity:var(--mdh-win,1)"/>';
			if ( 0 === $k % 4 ) { $s .= '<rect x="' . ( $x + $w / 2 - 1 ) . '" y="' . ( $y - 22 ) . '" width="2" height="22" fill="#18284a" style="fill:var(--mdh-bld,#18284a)"/><circle cx="' . ( $x + $w / 2 ) . '" cy="' . ( $y - 23 ) . '" r="2.2" fill="#ff5a5a" class="mdh-blink"/>'; }
		}
		// Maryland State House (Annapolis): wings, portico, octagonal drums, dome, lantern and spire.
		$s .= '<g fill="#1f3159" style="fill:var(--mdh-bld2,#1f3159)" transform="translate(800 0)">'
			. '<rect x="-150" y="250" width="300" height="70"/><rect x="-200" y="272" width="60" height="48"/><rect x="140" y="272" width="60" height="48"/>'
			. '<polygon points="-46,250 0,226 46,250"/><rect x="-40" y="250" width="80" height="70"/>'
			. '<rect x="-34" y="196" width="68" height="40"/><rect x="-28" y="160" width="56" height="38"/>'
			. '<path d="M-26 162 Q0 112 26 162 Z"/><rect x="-11" y="112" width="22" height="22"/><path d="M-12 113 Q0 92 12 113 Z"/>'
			. '<rect x="-5" y="80" width="10" height="16"/><path d="M-6 82 Q0 70 6 82 Z"/><rect x="-1" y="40" width="2" height="34"/><circle cx="0" cy="40" r="3"/>'
			. '</g>'
			. '<g fill="#ffd27a" opacity=".55" style="opacity:calc(var(--mdh-win,1) * .55)" transform="translate(800 0)"><rect x="-130" y="266" width="8" height="12"/><rect x="-110" y="266" width="8" height="12"/><rect x="-90" y="266" width="8" height="12"/><rect x="82" y="266" width="8" height="12"/><rect x="102" y="266" width="8" height="12"/><rect x="122" y="266" width="8" height="12"/><rect x="-22" y="206" width="6" height="12"/><rect x="-3" y="206" width="6" height="12"/><rect x="16" y="206" width="6" height="12"/></g>';
		return $s . '</svg>';
	}

	/** Chesapeake Bay Bridge over the water. One tile, 1600x200. */
	protected static function bay_svg() {
		$s  = '<svg class="mdh-tile" viewBox="0 0 1600 200" preserveAspectRatio="xMidYMax slice" aria-hidden="true">';
		$s .= '<g stroke="#2c4475" fill="none" stroke-width="3" style="stroke:var(--mdh-bridge,#2c4475)">';
		foreach ( array( 260, 1060 ) as $x0 ) {
			$s .= '<path d="M' . ( $x0 - 220 ) . ' 112 Q' . ( $x0 - 110 ) . ' 60 ' . $x0 . ' 30 Q' . ( $x0 + 110 ) . ' 88 ' . ( $x0 + 220 ) . ' 30 Q' . ( $x0 + 330 ) . ' 60 ' . ( $x0 + 440 ) . ' 112"/>';
		}
		$s .= '</g><g fill="#2c4475" style="fill:var(--mdh-bridge,#2c4475)">';
		foreach ( array( 260, 480, 1060, 1280 ) as $x ) { $s .= '<rect x="' . ( $x - 6 ) . '" y="26" width="5" height="100"/><rect x="' . ( $x + 1 ) . '" y="26" width="5" height="100"/><rect x="' . ( $x - 6 ) . '" y="40" width="12" height="3"/><rect x="' . ( $x - 6 ) . '" y="70" width="12" height="3"/>'; }
		$s .= '<rect x="0" y="110" width="1600" height="6"/>';
		for ( $x = 20; $x < 1600; $x += 70 ) { $s .= '<rect x="' . $x . '" y="116" width="5" height="24"/>'; }
		$s .= '</g><g stroke="#2c4475" stroke-width=".8" opacity=".7" style="stroke:var(--mdh-bridge,#2c4475)">';
		for ( $x = 60; $x < 1580; $x += 22 ) { $s .= '<line x1="' . $x . '" y1="110" x2="' . $x . '" y2="' . ( 110 - 70 * abs( sin( ( $x % 800 ) / 800 * M_PI * 2 ) ) * 0.5 ) . '"/>'; }
		$s .= '</g><g fill="#ffe3a3" opacity=".9" style="opacity:calc(var(--mdh-lights,1) * .9)">';
		for ( $x = 40; $x < 1600; $x += 140 ) { $s .= '<circle cx="' . $x . '" cy="107" r="1.6" class="mdh-blink" style="animation-delay:' . ( ( $x % 7 ) * 0.3 ) . 's"/>'; }
		return $s . '</g></svg>';
	}

	/** Near shore: trees, a crab-shack roofline and shrubs. One tile, 1600x160. */
	protected static function shore_svg() {
		$s = '<svg class="mdh-tile" viewBox="0 0 1600 160" preserveAspectRatio="xMidYMax slice" aria-hidden="true"><g fill="#0d1a30" style="fill:var(--mdh-shore,#0d1a30)">';
		$s .= '<path style="fill:var(--mdh-ground,#0d1a30)" d="M0 160V118c60-10 120-14 180-8s140 4 210-6 160-10 240 2 150 10 230 0 170-12 250-2 150 12 240 4 150-8 250 0V160z"/>';
		$trees = array( 40, 120, 210, 330, 420, 560, 640, 760, 900, 990, 1130, 1210, 1320, 1450, 1530 );
		foreach ( $trees as $k => $x ) {
			$h = 40 + ( ( $k * 37 ) % 34 );
			$s .= '<rect x="' . ( $x - 2 ) . '" y="' . ( 118 - $h * 0.3 ) . '" width="4" height="' . ( $h * 0.3 + 4 ) . '"/>';
			$top = 118 - $h * 0.3;
			// Bare branches (seen in winter, hidden under the leaves the rest of the year).
			$s .= '<path fill="none" stroke-width="1.6" stroke-linecap="round" style="stroke:var(--mdh-shore,#0d1a30)" d="M' . $x . ' ' . $top . 'L' . ( $x - 9 ) . ' ' . ( $top - $h * 0.55 ) . 'M' . $x . ' ' . ( $top - 4 ) . 'L' . ( $x + 10 ) . ' ' . ( $top - $h * 0.6 ) . 'M' . $x . ' ' . $top . 'L' . ( $x + 1 ) . ' ' . ( $top - $h * 0.8 ) . 'M' . ( $x - 5 ) . ' ' . ( $top - $h * 0.3 ) . 'L' . ( $x - 15 ) . ' ' . ( $top - $h * 0.45 ) . 'M' . ( $x + 6 ) . ' ' . ( $top - $h * 0.35 ) . 'L' . ( $x + 16 ) . ' ' . ( $top - $h * 0.42 ) . '"/>';
			$s .= '<ellipse style="fill:var(--mdh-leaf' . ( $k % 3 ? '1' : '2' ) . ',#0d1a30);opacity:var(--mdh-canopy,1)" cx="' . $x . '" cy="' . ( 118 - $h * 0.62 ) . '" rx="' . ( 14 + $k % 3 * 4 ) . '" ry="' . ( $h * 0.45 ) . '"/>';
		}
		$s .= '<path d="M470 118v-26h50l14-16 14 16h36v26z"/><rect x="560" y="80" width="3" height="20"/>';
		return $s . '</g></svg>';
	}

	public static function hero() {
		$title  = trim( (string) get_theme_mod( 'ruzbahani_banner_title', 'Affordable driving lessons' ) );
		$sub    = trim( (string) get_theme_mod( 'ruzbahani_banner_subtitle', 'Driving School Rockville – Book Today' ) );
		$badge  = trim( (string) get_theme_mod( 'ruzbahani_banner_subtitle2', '' ) );
		$phones = self::phones();
		?>
		<section class="mdh" data-mdh aria-labelledby="mdh-title">
			<div class="mdh-stage" aria-hidden="true">
				<div class="mdh-world">
					<div class="mdh-layer mdh-sky" style="--d:0">
						<span class="mdh-sun"></span><span class="mdh-stars"></span>
						<span class="mdh-cloud mdh-cloud--a"></span><span class="mdh-cloud mdh-cloud--b"></span><span class="mdh-cloud mdh-cloud--c"></span>
					</div>
					<div class="mdh-layer mdh-far" style="--d:.15"><div class="mdh-strip mdh-strip--far"><?php echo self::skyline_svg() . self::skyline_svg(); // phpcs:ignore -- static svg ?></div></div>
					<div class="mdh-layer mdh-water" style="--d:.28"><span class="mdh-glint"></span></div>
					<div class="mdh-layer mdh-bay" style="--d:.32"><div class="mdh-strip mdh-strip--bay"><?php echo self::bay_svg() . self::bay_svg(); // phpcs:ignore ?></div></div>
					<div class="mdh-layer mdh-shore" style="--d:.5"><div class="mdh-strip mdh-strip--shore"><?php echo self::shore_svg() . self::shore_svg(); // phpcs:ignore ?></div></div>
					<div class="mdh-layer mdh-birds" style="--d:.1"><?php echo self::oriole_html( 'mdh-oriole--a' ) . self::oriole_html( 'mdh-oriole--b' ) . self::oriole_html( 'mdh-oriole--c' ); // phpcs:ignore -- static svg ?></div>
					<div class="mdh-layer mdh-boats" style="--d:.3"><span class="mdh-lh"><?php echo self::lighthouse_svg(); // phpcs:ignore ?><span class="mdh-lh-beam"></span><span class="mdh-lh-beam mdh-lh-beam--r"></span></span><span class="mdh-skipjack"><?php echo self::skipjack_svg(); // phpcs:ignore ?></span></div>
					<div class="mdh-layer mdh-props" style="--d:.7"><?php echo self::brand_track(); // phpcs:ignore -- escaped in helper ?></div>
					<div class="mdh-road">
						<span class="mdh-susans" style="background-image:url(&quot;<?php echo esc_attr( self::susans_tile_uri() ); ?>&quot;)"></span>
						<span class="mdh-crab"><?php echo self::crab_svg(); // phpcs:ignore ?></span>
						<div class="mdh-asphalt"><span class="mdh-edge mdh-edge--far"></span><span class="mdh-lane"></span><span class="mdh-edge mdh-edge--near"></span></div>
						<div class="mdh-car-wrap">
							<span class="mdh-beam"></span>
							<?php echo SDSC_Brand::car( 'right', 'mdh-car' ); // phpcs:ignore -- escaped in helper ?>
							<span class="mdh-car-shadow"></span>
						</div>
					</div>
				</div>
			</div>
			<div class="mdh-copy">
				<p class="mdh-kicker"><?php echo self::susan_svg( 22 ); // phpcs:ignore ?>Rockville, Maryland</p>
				<h1 id="mdh-title" class="mdh-title"><?php echo esc_html( $title ? $title : 'Affordable driving lessons' ); ?> <span>in Rockville, Maryland</span></h1>
				<p class="mdh-sub"><?php echo esc_html( $sub ); ?><?php if ( $badge ) : ?> <em class="mdh-badge"><?php echo esc_html( $badge ); ?></em><?php endif; ?></p>
				<div class="mdh-ctas">
					<a class="mdh-btn mdh-btn--go" href="<?php echo esc_url( home_url( '/services/' ) ); ?>">See classes &amp; prices</a>
					<?php if ( $phones ) : ?><a class="mdh-btn mdh-btn--ghost" href="<?php echo esc_attr( self::tel( $phones[0] ) ); ?>"><?php echo self::icon( 'phone' ); // phpcs:ignore ?>Call <?php echo esc_html( $phones[0] ); ?></a><?php endif; ?>
				</div>
				<ul class="mdh-facts">
					<li>MVA 36-hour driver's ed</li>
					<li>Male &amp; female instructors &middot; English, Spanish &amp; Farsi (Persian)</li>
					<li>Free pickup at Rockville Metro</li>
				</ul>
			</div>
		</section>
		<?php if ( wp_script_is( 'sdsc-hero-sky', 'enqueued' ) ) : ?><script>(function(s){if(!s||!window.HTMLCanvasElement){return;}s.classList.add('mdh-wait');setTimeout(function(){s.classList.remove('mdh-wait');},2500);})(document.currentScript&&document.currentScript.previousElementSibling);</script><?php endif; ?>
		<?php
	}

	/* ================================================================ *
	 * Footer (light beam + giant wordmark)
	 * ================================================================ */

	protected static function footer_links() {
		$links = array();
		$locs  = get_nav_menu_locations();
		$menu  = ! empty( $locs['footer'] ) ? $locs['footer'] : ( $locs['primary'] ?? 0 );
		if ( $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $it ) {
				if ( empty( $it->menu_item_parent ) && ! empty( $it->url ) ) { $links[] = array( $it->title, $it->url ); }
			}
		}
		if ( ! $links ) {
			$links = array( array( 'Home', home_url( '/' ) ), array( 'Services', home_url( '/services/' ) ), array( 'Contact Us', home_url( '/contact-us/' ) ) );
		}
		return $links;
	}

	public static function footer() {
		$phones = self::phones();
		$year   = (int) current_time( 'Y' );
		$fb     = get_theme_mod( 'ruzbahani_facebook' );
		$ig     = get_theme_mod( 'ruzbahani_instagram' );
		$svc    = home_url( '/services/' );
		$learn  = array(
			array( "Driver's Ed — 36 hours", $svc ),
			array( 'Behind-the-wheel practice', $svc ),
			array( 'Road test car rental', home_url( '/car-rentals/' ) ),
			array( 'Online 3-hour course', $svc ),
		);
		if ( get_page_by_path( 'register-course' ) ) { $learn[] = array( 'Register for a class', home_url( '/register-course/' ) ); }
		$word = 'SAM\'S';
		$d    = 0;
		$dl   = function () use ( &$d ) { $d += 70; return '--bwf-d:' . $d . 'ms'; };
		?>
		<footer class="bwf" data-bwf data-in="false">
			<div class="bwf-rule" aria-hidden="true"></div>
			<div class="bwf-sky" aria-hidden="true"><div class="bwf-wash"></div><div class="bwf-bands"></div><div class="bwf-beam"></div><div class="bwf-glow"></div><div class="bwf-grain"></div></div>
			<div class="bwf-inner">
				<div class="bwf-col">
					<p class="bwf-label" style="<?php echo esc_attr( $dl() ); ?>">&copy; <?php echo (int) $year; ?> Sam's Driving School LLC</p>
					<ul class="bwf-socials">
						<?php if ( $fb ) : ?><li class="bwf-fade" style="<?php echo esc_attr( $dl() ); ?>"><a class="bwf-soc" href="<?php echo esc_url( $fb ); ?>" target="_blank" rel="noopener" aria-label="Facebook"><?php echo self::icon( 'fb' ); // phpcs:ignore ?><span class="bwf-tip" aria-hidden="true">Facebook</span></a></li><?php endif; ?>
						<?php if ( $ig ) : ?><li class="bwf-fade" style="<?php echo esc_attr( $dl() ); ?>"><a class="bwf-soc" href="<?php echo esc_url( $ig ); ?>" target="_blank" rel="noopener" aria-label="Instagram"><?php echo self::icon( 'ig' ); // phpcs:ignore ?><span class="bwf-tip" aria-hidden="true">Instagram</span></a></li><?php endif; ?>
						<li class="bwf-fade" style="<?php echo esc_attr( $dl() ); ?>"><a class="bwf-soc" href="<?php echo esc_url( self::MAPS ); ?>" data-sdsc-nav target="_blank" rel="noopener" aria-label="Directions"><?php echo self::icon( 'pin' ); // phpcs:ignore ?><span class="bwf-tip" aria-hidden="true">Directions</span></a></li>
						<?php if ( $phones ) : ?><li class="bwf-fade" style="<?php echo esc_attr( $dl() ); ?>"><a class="bwf-soc" href="<?php echo esc_attr( self::tel( $phones[0] ) ); ?>" aria-label="Call <?php echo esc_attr( $phones[0] ); ?>"><?php echo self::icon( 'phone' ); // phpcs:ignore ?><span class="bwf-tip" aria-hidden="true">Call us</span></a></li><?php endif; ?>
					</ul>
					<div class="bwf-credits">
						<p class="bwf-fade" style="<?php echo esc_attr( $dl() ); ?>"><a href="<?php echo esc_url( self::MAPS ); ?>" data-sdsc-nav target="_blank" rel="noopener"><?php echo esc_html( self::ADDRESS ); ?></a></p>
						<p class="bwf-fade" style="<?php echo esc_attr( $dl() ); ?>">
							<span class="bwf-phones"><?php foreach ( $phones as $p ) { echo self::phone_chip( $p ); } // phpcs:ignore -- escaped in helper ?></span>
						</p>
						<p class="bwf-fade bwf-by" style="<?php echo esc_attr( $dl() ); ?>">Designed &amp; developed by <b>Saman Abyar</b></p>
					</div>
				</div>
				<nav class="bwf-col" aria-label="Explore">
					<p class="bwf-label" style="<?php echo esc_attr( $dl() ); ?>">Explore</p>
					<ul class="bwf-links">
						<?php foreach ( self::footer_links() as $l ) : ?>
							<li class="bwf-fade" style="<?php echo esc_attr( $dl() ); ?>"><a class="bwf-link" href="<?php echo esc_url( $l[1] ); ?>"><span class="bwf-link-t"><?php echo esc_html( $l[0] ); ?></span><?php echo self::icon( 'arrow' ); // phpcs:ignore ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
				<nav class="bwf-col" aria-label="Learn to drive">
					<p class="bwf-label" style="<?php echo esc_attr( $dl() ); ?>">Learn to drive</p>
					<ul class="bwf-links">
						<?php foreach ( $learn as $l ) : ?>
							<li class="bwf-fade" style="<?php echo esc_attr( $dl() ); ?>"><a class="bwf-link" href="<?php echo esc_url( $l[1] ); ?>"><span class="bwf-link-t"><?php echo esc_html( $l[0] ); ?></span><?php echo self::icon( 'arrow' ); // phpcs:ignore ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			</div>
			<div class="bwf-word">
				<p class="bwf-sr">Sam's Driving School</p>
				<div class="bwf-word-box">
					<div class="bwf-word-in" aria-hidden="true">
						<span class="bwf-mark"><?php echo self::logo_full( '<img class="bwf-mark-art" src="' . esc_url( SDSC_URL . 'assets/img/sams-logo-wide-dark.webp?v=' . SDSC_VERSION ) . '" width="900" height="182" alt="" loading="lazy" decoding="async" />', 'sdx-logo--foot' ); // phpcs:ignore ?></span>
					</div>
				</div>
				<div class="bwf-foot" aria-hidden="true"></div>
			</div>
		</footer>
		<?php
	}
}
