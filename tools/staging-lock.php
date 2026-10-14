<?php
/**
 * Plugin Name: SDSC staging lock
 * Description: The test site holds a copy of real student data. Only signed-in staff may see it; everyone else is sent to the login page. Install on the TEST site only (wp-content/mu-plugins).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'template_redirect', function () {
	if ( is_user_logged_in() && ( ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview( get_current_user_id() ) ) || current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) || current_user_can( 'sdsc_access_registrations' ) ) ) { return; }
	if ( is_user_logged_in() ) { wp_logout(); }
	nocache_headers();
	wp_safe_redirect( wp_login_url( home_url( '/' ) ) );
	exit;
}, 0 );
add_filter( 'option_users_can_register', '__return_zero' );                    // no sign-ups on the test site
add_filter( 'rest_authentication_errors', function ( $r ) { return ( ! empty( $r ) || is_user_logged_in() ) ? $r : new WP_Error( 'locked', 'Test site.', array( 'status' => 401 ) ); } );
add_action( 'wp_head', function () { echo '<meta name="robots" content="noindex,nofollow">' . "\n"; }, 0 );
