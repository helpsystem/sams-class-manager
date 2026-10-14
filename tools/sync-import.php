<?php
/**
 * Adds data exported by sync-export.php to THIS site without duplicates. Dry run unless APPLY=1.
 * Users are matched by e-mail (existing accounts are kept as they are); enrollments by student + course;
 * quiz attempts by student + quiz + start time. Prints counts only.
 * Run: SYNC=/root/sdsc-sync.json [APPLY=1] wp eval-file sync-import.php
 */
global $wpdb;
$apply = '1' === getenv( 'APPLY' );
$d     = json_decode( (string) file_get_contents( getenv( 'SYNC' ) ), true );
if ( ! $d ) { echo "No data\n"; return; }
$c = array( 'users_new' => 0, 'users_existing' => 0, 'enroll_new' => 0, 'enroll_dup' => 0, 'enroll_missing_course' => 0, 'attempts_new' => 0, 'attempts_dup' => 0, 'attempts_missing_quiz' => 0, 'answers_new' => 0, 'forminator_new' => 0, 'forminator_dup' => 0 );
$map = array(); // source user id => target user id
$uq  = function ( $s ) { return strtolower( trim( (string) $s ) ); };
if ( $apply ) { $wpdb->query( 'START TRANSACTION' ); }

foreach ( $d['users'] as $u ) {
	$t = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE LOWER(user_email) = %s", $uq( $u['user_email'] ) ) );
	if ( $t ) { $map[ (int) $u['ID'] ] = $t; $c['users_existing']++; continue; }
	$login = $u['user_login'];
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", $login ) ) ) { $login .= '_' . (int) $u['ID']; }
	$c['users_new']++;
	if ( ! $apply ) { $map[ (int) $u['ID'] ] = -1; continue; }
	$row = array_intersect_key( $u, array_flip( array( 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name' ) ) );
	$row['user_login'] = $login;
	$wpdb->insert( $wpdb->users, $row );
	$nid = (int) $wpdb->insert_id;
	$map[ (int) $u['ID'] ] = $nid;
	foreach ( $u['_meta'] as $m ) { $wpdb->insert( $wpdb->usermeta, array( 'user_id' => $nid, 'meta_key' => $m['meta_key'], 'meta_value' => $m['meta_value'] ) ); }
}

foreach ( $d['enrollments'] as $e ) {
	$uid = $map[ (int) $e['post_author'] ] ?? (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d", $e['post_author'] ) );
	if ( ! get_post( (int) $e['post_parent'] ) ) { $c['enroll_missing_course']++; continue; }
	if ( $uid > 0 && $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='tutor_enrolled' AND post_author=%d AND post_parent=%d", $uid, $e['post_parent'] ) ) ) { $c['enroll_dup']++; continue; }
	$c['enroll_new']++;
	if ( ! $apply ) { continue; }
	$row = $e; unset( $row['ID'], $row['_meta'] ); $row['post_author'] = $uid; $row['guid'] = '';
	$wpdb->insert( $wpdb->posts, $row );
	$pid = (int) $wpdb->insert_id;
	foreach ( $e['_meta'] as $m ) { $wpdb->insert( $wpdb->postmeta, array( 'post_id' => $pid, 'meta_key' => $m['meta_key'], 'meta_value' => $m['meta_value'] ) ); }
}

$amap = array();
foreach ( $d['attempts'] as $a ) {
	$uid = $map[ (int) $a['user_id'] ] ?? (int) $a['user_id'];
	if ( ! get_post( (int) $a['quiz_id'] ) ) { $c['attempts_missing_quiz']++; continue; }
	if ( $uid > 0 && $wpdb->get_var( $wpdb->prepare( "SELECT attempt_id FROM {$wpdb->prefix}tutor_quiz_attempts WHERE user_id=%d AND quiz_id=%d AND attempt_started_at=%s", $uid, $a['quiz_id'], $a['attempt_started_at'] ) ) ) { $c['attempts_dup']++; continue; }
	$c['attempts_new']++;
	if ( ! $apply ) { $amap[ (int) $a['attempt_id'] ] = -1; continue; }
	$row = $a; unset( $row['attempt_id'] ); $row['user_id'] = $uid;
	$wpdb->insert( $wpdb->prefix . 'tutor_quiz_attempts', $row );
	$amap[ (int) $a['attempt_id'] ] = (int) $wpdb->insert_id;
}
foreach ( $d['answers'] as $x ) {
	if ( ! isset( $amap[ (int) $x['quiz_attempt_id'] ] ) ) { continue; }
	$c['answers_new']++;
	if ( ! $apply ) { continue; }
	$row = $x; unset( $row['attempt_answer_id'] );
	$row['quiz_attempt_id'] = $amap[ (int) $x['quiz_attempt_id'] ];
	$row['user_id']         = $map[ (int) $x['user_id'] ] ?? (int) $x['user_id'];
	$wpdb->insert( $wpdb->prefix . 'tutor_quiz_attempt_answers', $row );
}

foreach ( $d['forminator'] as $f ) {
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$wpdb->prefix}frmt_form_entry WHERE entry_id = %d", $f['entry_id'] ) ) ) { $c['forminator_dup']++; continue; }
	$c['forminator_new']++;
	if ( ! $apply ) { continue; }
	$row = $f; unset( $row['_meta'] );
	$wpdb->insert( $wpdb->prefix . 'frmt_form_entry', $row );
	foreach ( $f['_meta'] as $m ) { $wpdb->insert( $wpdb->prefix . 'frmt_form_entry_meta', array( 'entry_id' => (int) $f['entry_id'] ) + $m ); }
}
if ( $apply ) { $wpdb->query( 'COMMIT' ); }
echo ( $apply ? 'APPLIED' : 'DRY RUN (nothing written)' ) . ' — source ' . $d['site'] . ' since ' . $d['since'] . "\n";
foreach ( $c as $k => $v ) { echo str_pad( $k, 24 ) . $v . "\n"; }
