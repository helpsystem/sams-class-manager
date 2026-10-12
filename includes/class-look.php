<?php
/**
 * School look for Tutor LMS sign-in and sign-up forms (dashboard sign-in, /tutor-login/, student and
 * instructor registration): logo, brand green buttons, softer fields and a visible terms link.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Look {
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
	}

	public static function assets() {
		if ( is_user_logged_in() || is_admin() ) { return; }
		wp_enqueue_style( 'sdsc-tutor-forms', SDSC_URL . 'assets/tutor-forms.css', array(), SDSC_VERSION );
		$logo = class_exists( 'SDSC_Brand' ) && method_exists( 'SDSC_Brand', 'img' ) ? SDSC_Brand::img( 'sams-logo-stacked.webp' ) : '';
		if ( $logo ) { wp_add_inline_style( 'sdsc-tutor-forms', ':root{--sdsc-form-logo:url("' . esc_url_raw( $logo ) . '")}' ); }
	}
}
