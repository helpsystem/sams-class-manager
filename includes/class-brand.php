<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Brand assets shared by the plugin's front-end pieces: the two SAM's logos and the
 * school car (one image per driving direction, with separate wheels that can spin).
 */
class SDSC_Brand {

	/** Wheel boxes as % of the car image (from the artwork's meta.json, 1861x640). */
	const WHEELS = array(
		'right' => array( array( 73.83, 56.17, 14.35 ), array( 14.94, 56.02, 14.29 ) ),
		'left'  => array( array( 11.82, 56.17, 14.35 ), array( 70.77, 56.02, 14.29 ) ),
	);

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'logo_fit_css' ), 99 );
	}

	/** The theme gives the header logo a fixed box; keep the logo's own proportions inside it. */
	public static function logo_fit_css() {
		echo '<style id="sams-logo-fit">img.custom-logo{object-fit:contain;object-position:left center}</style>' . "\n";
	}

	public static function register_assets() {
		wp_register_style( 'sdsc-brand', SDSC_URL . 'assets/brand.css', array(), SDSC_VERSION );
	}

	public static function img( $file ) {
		return SDSC_URL . 'assets/img/' . $file;
	}

	public static function logo( $variant = 'wide', $class = '', $height = 44 ) {
		$file = 'stacked' === $variant ? 'sams-logo-stacked.webp' : 'sams-logo-wide.webp';
		$w    = 'stacked' === $variant ? round( $height * 1.4 ) : round( $height * 4.95 );
		return '<img class="' . esc_attr( trim( 'sams-logo ' . $class ) ) . '" src="' . esc_url( self::img( $file ) ) . '" alt="Sam\'s Driving School" width="' . (int) $w . '" height="' . (int) $height . '" decoding="async" />';
	}

	/**
	 * The school car facing the direction it drives ('right' = moving left-to-right).
	 * Wheels are separate layers; rotate them with the --spin custom property (degrees).
	 */
	public static function car( $dir = 'right', $class = '' ) {
		$dir  = 'left' === $dir ? 'left' : 'right';
		$html = '<span class="' . esc_attr( trim( 'sams-car sams-car--' . $dir . ' ' . $class ) ) . '" aria-hidden="true">';
		$html .= '<img class="sams-car-body" src="' . esc_url( self::img( 'sams-car-' . $dir . '.webp' ) ) . '" alt="" width="744" height="256" decoding="async" />';
		foreach ( self::WHEELS[ $dir ] as $i => $w ) {
			$html .= '<img class="sams-wheel" src="' . esc_url( self::img( 'sams-wheel-' . $dir . '-' . $i . '.webp' ) ) . '" alt="" style="left:' . $w[0] . '%;top:' . $w[1] . '%;width:' . $w[2] . '%" decoding="async" />';
		}
		return $html . '</span>';
	}
}
