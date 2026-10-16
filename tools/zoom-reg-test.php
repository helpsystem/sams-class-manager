<?php
/**
 * Experiment: can a class meeting give every student a personal join link (Zoom registration)?
 * Makes a throw-away meeting on Zoom account 1 (recurring, fixed time, 2 occurrences, registration required,
 * auto-approve), registers a test person, prints what Zoom returns, then deletes the meeting. Nothing is e-mailed.
 */
$n   = (int) ( getenv( 'ZOOM_ACCT' ) ?: 1 );
$tok = SDSC_ZoomAuto::token( $n );
if ( is_wp_error( $tok ) ) { echo 'token: ' . $tok->get_error_message() . "\n"; return; }
$h   = array( 'Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json' );
$call = function ( $m, $path, $body = null ) use ( $h ) {
	$r = wp_remote_request( 'https://api.zoom.us/v2' . $path, array( 'method' => $m, 'timeout' => 25, 'headers' => $h, 'body' => null === $body ? null : wp_json_encode( $body ) ) );
	if ( is_wp_error( $r ) ) { return array( 0, $r->get_error_message() ); }
	return array( (int) wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$start = new DateTime( 'next monday 18:00', wp_timezone() );
$body  = array(
	'topic'      => 'SDSC TEST — personal links (delete me)',
	'type'       => 8, // recurring, fixed time
	'start_time' => $start->format( 'Y-m-d\TH:i:s' ),
	'duration'   => 195,
	'timezone'   => wp_timezone_string(),
	'recurrence' => array( 'type' => 2, 'repeat_interval' => 1, 'weekly_days' => '2,3,4,5,6', 'end_times' => 10 ),
	'settings'   => array( 'approval_type' => 0, 'registration_type' => 1, 'registrants_email_notification' => false, 'registrants_confirmation_email' => false, 'waiting_room' => true, 'join_before_host' => false, 'mute_upon_entry' => true, 'meeting_authentication' => false ),
);
list( $c, $j ) = $call( 'POST', '/users/me/meetings', $body );
echo "create: HTTP $c " . ( $c >= 300 ? wp_json_encode( $j ) : 'id=' . $j['id'] . ' type=' . $j['type'] . ' occurrences=' . count( (array) ( $j['occurrences'] ?? array() ) ) . ' reg_url=' . ( $j['registration_url'] ?? '-' ) . ' join=' . ( $j['join_url'] ?? '-' ) ) . "\n";
if ( $c >= 300 ) { return; }
$id = $j['id'];
$occ = (array) ( $j['occurrences'] ?? array() );
if ( $occ ) { echo 'first occurrences: ' . implode( ', ', array_map( function ( $o ) { return substr( $o['start_time'], 0, 16 ); }, array_slice( $occ, 0, 3 ) ) ) . "\n"; }
// a holiday: drop the 3rd occurrence (like the class calendar's "skip")
if ( count( $occ ) > 3 ) {
	list( $c2, $j2 ) = $call( 'DELETE', '/meetings/' . $id . '?occurrence_id=' . rawurlencode( $occ[2]['occurrence_id'] ) );
	echo "delete one occurrence (holiday): HTTP $c2\n";
}
// register a test person
list( $c3, $j3 ) = $call( 'POST', '/meetings/' . $id . '/registrants', array( 'email' => 'zoomtest-' . wp_rand( 1000, 9999 ) . '@example.com', 'first_name' => 'Test', 'last_name' => 'Student', 'auto_approve' => true ) );
echo "registrant: HTTP $c3 " . ( $c3 >= 300 ? wp_json_encode( $j3 ) : 'id=' . ( $j3['registrant_id'] ?? '?' ) . ' personal join_url=' . ( $j3['join_url'] ?? '-' ) ) . "\n";
// same e-mail twice?
list( $c4, $j4 ) = $call( 'POST', '/meetings/' . $id . '/registrants', array( 'email' => 'zoomtest-dup@example.com', 'first_name' => 'Dup', 'last_name' => 'One' ) );
list( $c5, $j5 ) = $call( 'POST', '/meetings/' . $id . '/registrants', array( 'email' => 'zoomtest-dup@example.com', 'first_name' => 'Dup', 'last_name' => 'Two' ) );
echo "same e-mail twice: HTTP $c4 then $c5 " . ( ( $j4['join_url'] ?? '' ) === ( $j5['join_url'] ?? 'x' ) ? '(same link returned)' : '(different link / refused: ' . wp_json_encode( $j5 ) . ')' ) . "\n";
// list registrants
list( $c6, $j6 ) = $call( 'GET', '/meetings/' . $id . '/registrants?status=approved&page_size=50' );
echo "registrants listed: HTTP $c6 total=" . ( $j6['total_records'] ?? '?' ) . "\n";
// meeting settings: can participants rename?
list( $c7, $j7 ) = $call( 'GET', '/users/me/settings' );
echo "account setting allow_participants_to_rename: " . var_export( $j7['in_meeting']['allow_participants_to_rename'] ?? 'n/a', true ) . "\n";
// clean up
list( $c8 ) = $call( 'DELETE', '/meetings/' . $id );
echo "test meeting deleted: HTTP $c8\n";
