<?php
/**
 * Read-only pre-launch snapshot. Run: wp eval-file tools/preflight.php
 * Prints settings and counts only (no personal data).
 */
global $wpdb;
$o = function ( $k, $v ) { echo str_pad( $k, 38 ) . ': ' . $v . "\n"; };
$o( 'siteurl | home', get_option( 'siteurl' ) . ' | ' . get_option( 'home' ) );
$o( 'Search engines allowed (blog_public)', get_option( 'blog_public' ) );
$k = class_exists( 'SDSC_Api' ) ? (string) SDSC_Api::stripe_key() : '';
$o( 'Stripe key mode', $k ? ( false !== strpos( $k, '_test_' ) ? 'TEST' : ( false !== strpos( $k, '_live_' ) ? 'LIVE' : 'unknown' ) ) : 'none' );
foreach ( array( 'webhook_secret', 'stripe_webhook_secret' ) as $m ) { if ( class_exists( 'SDSC_Api' ) && method_exists( 'SDSC_Api', $m ) ) { $o( 'Stripe webhook secret', SDSC_Api::$m() ? 'set' : 'NOT set' ); } }
$o( 'School mailbox ready (SMTP)', class_exists( 'SDSC_Mailer' ) && SDSC_Mailer::ready() ? 'yes' : 'NO' );
$o( 'admin_email', get_option( 'admin_email' ) );
foreach ( array( 'C-68', 'C-69', 'TEST-1' ) as $c ) {
	$id = SDSC_Registration::find_class_by_code( $c );
	if ( ! $id ) { $o( "class $c", 'missing' ); continue; }
	$o( "class $c", get_post_status( $id ) . ' | start ' . get_post_meta( $id, '_sdsc_start', true ) . ' | $' . get_post_meta( $id, '_sdsc_price', true ) . ' | manual link ' . ( get_post_meta( $id, '_sdsc_payment_link', true ) ? 'yes' : 'no' ) . ' | regs ' . (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Registration::table() . ' WHERE class_id=%d', $id ) ) . ' | registrants ' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_sdsc_class_id' AND meta_value=%d", $id ) ) . ( get_post_meta( $id, '_sdsc_hidden', true ) ? ' | hidden' : '' ) );
}
if ( ! function_exists( 'is_plugin_active' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
$o( 'Forminator active', is_plugin_active( 'forminator/forminator.php' ) ? 'yes' : 'no' );
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}frmt_form_entry'" ) ) {
	$o( 'Forminator entries (form 526)', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}frmt_form_entry WHERE form_id=526" ) . ' | latest ' . $wpdb->get_var( "SELECT MAX(date_created) FROM {$wpdb->prefix}frmt_form_entry WHERE form_id=526" ) );
}
$o( 'Newest user account', $wpdb->get_var( "SELECT MAX(user_registered) FROM {$wpdb->users}" ) . ' | total ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
$o( 'Newest Woo order', (string) $wpdb->get_var( "SELECT MAX(post_date) FROM {$wpdb->posts} WHERE post_type='shop_order'" ) );
$o( 'Newest quiz attempt', (string) $wpdb->get_var( "SELECT MAX(attempt_started_at) FROM {$wpdb->prefix}tutor_quiz_attempts" ) );
$o( 'Tutor Pro active', is_plugin_active( 'tutor-pro/tutor-pro.php' ) ? 'yes' : 'no' );
$o( 'WPCode snippet 1379', (string) get_post_status( 1379 ) );
$up = get_site_transient( 'update_plugins' );
$o( 'Plugin updates waiting', $up && ! empty( $up->response ) ? implode( ', ', array_map( 'dirname', array_keys( $up->response ) ) ) : 'none' );
$o( 'Follow-up cron', ( $t = wp_next_scheduled( 'sdsc_followup_run' ) ) ? gmdate( 'Y-m-d H:i', $t ) . ' UTC' : 'NOT scheduled' );
$o( 'DISABLE_WP_CRON', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'true (needs a server cron)' : 'false' );
$o( 'WordPress / PHP', get_bloginfo( 'version' ) . ' / ' . PHP_VERSION );
$o( 'Wordfence login log (UA column)', $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wflogins'" ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wflogins" ) . ' rows | UA ' . ( $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}wflogins LIKE 'UA'" ) ? 'yes' : 'no' ) : 'no table' );
$o( 'Wordfence country lookup', class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'IP2Country' ) ? 'available' : 'no' );
$log = WP_CONTENT_DIR . '/debug.log';
$o( 'debug.log fatal errors (last 300 lines)', file_exists( $log ) ? (string) count( preg_grep( '/fatal/i', array_slice( file( $log ), -300 ) ) ) : 'no log' );
