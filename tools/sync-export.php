<?php
/**
 * READ-ONLY on the source site. Prints JSON of data created since SINCE:
 * users (with meta), Tutor enrollments, quiz attempts + answers, Forminator entries (form 526) after FRM_AFTER.
 * Run (as root, output kept private): SINCE='2026-10-05 00:00' FRM_AFTER=760 wp --path=SRC eval-file sync-export.php > /root/sdsc-sync.json
 */
global $wpdb;
$since = getenv( 'SINCE' ) ? getenv( 'SINCE' ) : '2026-10-05 00:00:00';
$frm   = (int) ( getenv( 'FRM_AFTER' ) ? getenv( 'FRM_AFTER' ) : 0 );
$out   = array( 'since' => $since, 'site' => get_option( 'siteurl' ), 'made' => current_time( 'mysql' ) );

$enr = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_date > %s", $since ), ARRAY_A );
foreach ( $enr as &$e ) { $e['_meta'] = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $e['ID'] ), ARRAY_A ); }
unset( $e );
$att = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tutor_quiz_attempts WHERE attempt_started_at > %s", $since ), ARRAY_A );
$ans = array();
if ( $att ) {
	$ids = implode( ',', array_map( 'intval', wp_list_pluck( $att, 'attempt_id' ) ) );
	$ans = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tutor_quiz_attempt_answers WHERE quiz_attempt_id IN ($ids)", ARRAY_A ); // phpcs:ignore
}
$uids = array_unique( array_merge(
	array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_registered > %s", $since ) ) ),
	array_map( 'intval', wp_list_pluck( $enr, 'post_author' ) ),
	array_map( 'intval', wp_list_pluck( $att, 'user_id' ) )
) );
$users = array();
foreach ( $uids as $id ) {
	$u = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $id ), ARRAY_A );
	if ( ! $u ) { continue; }
	$u['_meta'] = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key NOT IN ('session_tokens')", $id ), ARRAY_A );
	$users[]    = $u;
}
$fe = array();
if ( $frm && $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}frmt_form_entry'" ) ) {
	$fe = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}frmt_form_entry WHERE form_id = 526 AND entry_id > %d", $frm ), ARRAY_A );
	foreach ( $fe as &$f ) { $f['_meta'] = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value, date_created, date_updated FROM {$wpdb->prefix}frmt_form_entry_meta WHERE entry_id = %d", $f['entry_id'] ), ARRAY_A ); }
	unset( $f );
}
$out += array( 'users' => $users, 'enrollments' => $enr, 'attempts' => $att, 'answers' => $ans, 'forminator' => $fe );
echo wp_json_encode( $out );
