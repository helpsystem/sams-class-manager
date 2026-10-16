<?php
// Staging: timetable presets, holiday shifting, skip/extra days, and the welcome e-mail. Makes one throw-away class and removes it.
global $wpdb; $ok = 0; $bad = 0;
$t = function ( $c, $m ) use ( &$ok, &$bad ) { if ( $c ) { $ok++; echo "ok  $m\n"; } else { $bad++; echo "BAD $m\n"; } };
$sent = array();
add_filter( 'pre_wp_mail', function ( $r, $a ) use ( &$sent ) { $sent[] = $a; return true; }, 1, 2 );
$cid = wp_insert_post( array( 'post_type' => 'sdsc_class', 'post_status' => 'draft', 'post_title' => 'ZZTEST timetable (PM Class)' ) );
update_post_meta( $cid, '_sdsc_start', '2025-08-11T09:00' );
update_post_meta( $cid, '_sdsc_course_code', 'ZZ-1' );
$s = SDSC_Schedule::sched( $cid );
$t( array( 1, 2, 3, 4, 5 ) === $s['days'] && 10 === $s['count'] && '18:00' === $s['from'], 'new PM class starts from the evening preset Mon–Fri ×10' );
$am = SDSC_Schedule::preset( 'am' );
update_post_meta( $cid, SDSC_Schedule::META, $am );
$l = SDSC_Schedule::sessions( $cid );
$t( 10 === count( $l ) && '2025-08-11' === $l[0]['date'] && '2025-08-22' === $l[9]['date'] && '2025-08-11 09:00:00' === $l[0]['start'], 'Aug 11 2025 morning class: units 1–10 = Aug 11 … Aug 22 (' . $l[0]['date'] . ' … ' . $l[9]['date'] . ')' );
// Labor Day 2025 (Sep 1) is a school-closed holiday? check shifting with a class starting Aug 29 2025
update_post_meta( $cid, '_sdsc_start', '2025-08-29T09:00' );
$l = SDSC_Schedule::sessions( $cid );
$dates = wp_list_pluck( $l, 'date' );
$closed = SDSC_Schedule::closed_from( '2025-08-29' );
$t( ! isset( $closed['2025-09-01'] ) || ! in_array( '2025-09-01', $dates, true ), 'school-closed holiday is skipped (Labor Day ' . ( isset( $closed['2025-09-01'] ) ? 'closed' : 'not marked closed' ) . ')' );
// skip + extra
update_post_meta( $cid, '_sdsc_start', '2025-08-11T09:00' );
$am['skip'] = array( '2025-08-13' ); $am['extra'] = array( '2025-08-23' );
update_post_meta( $cid, SDSC_Schedule::META, $am );
$l = SDSC_Schedule::sessions( $cid ); $dates = wp_list_pluck( $l, 'date' );
$t( 10 === count( $l ) && ! in_array( '2025-08-13', $dates, true ) && in_array( '2025-08-23', $dates, true ) && '2025-08-25' === end( $dates ) ? false : ( 10 === count( $l ) && ! in_array( '2025-08-13', $dates, true ) && in_array( '2025-08-23', $dates, true ) ), 'taken-out day gone, added day in, still 10 units: ' . implode( ' ', $dates ) );
$t( in_array( '2025-08-23', $dates, true ) && '2025-08-22' === $dates[8] && '2025-08-23' === $dates[9], 'the added Saturday becomes unit 10 (no overflow to the next week)' );
// save handler
wp_set_current_user( 17307 );
$_POST = array( 'sdsc_sched_nonce' => wp_create_nonce( 'sdsc_sched' ), 'sdsc_sched' => array( 'days' => array( 1, 3 ), 'from' => '10:00', 'to' => '11:30', 'count' => '4', 'skip' => '2025-08-13,bad,2025-08-18', 'extra' => '2025-08-16' ) );
wp_set_current_user( 17307 );
SDSC_Schedule::save_meta( $cid );
$s = SDSC_Schedule::sched( $cid );
$t( array( '2025-08-13', '2025-08-18' ) === $s['skip'] && array( '2025-08-16' ) === $s['extra'] && 4 === $s['count'], 'calendar clicks saved (bad dates dropped)' );
// welcome e-mail
update_post_meta( $cid, SDSC_Schedule::META, SDSC_Schedule::preset( 'am' ) );
update_post_meta( $cid, '_sdsc_zoom_link', 'https://zoom.us/j/92066367353?pwd=test' );
update_post_meta( $cid, '_sdsc_zoom_meeting_id', '92066367353' );
update_post_meta( $cid, '_sdsc_zoom_passcode', '876573' );
$wpdb->insert( SDSC_Registration::table(), array( 'first_name' => 'Zed', 'last_name' => 'Test', 'email' => 'zztest-sched@example.test', 'phone' => '3015550000', 'class_id' => $cid, 'source' => 'staff', 'created_at' => current_time( 'mysql' ) ) );
$n = SDSC_ZoomAuto::send( $cid );
$m = end( $sent );
$miss = array(); foreach ( array( 'August 11, 2025 9:00 AM', '(unit 10)', '920 6636 7353', '876573', 'IMPORTANT', '10 meetings (Monday, Tuesday, Wednesday, Thursday, Friday)' ) as $needle ) { if ( ! $m || false === strpos( $m['message'], $needle ) ) { $miss[] = $needle; } }
$t( 1 === $n && ! $miss, 'welcome e-mail: units, Zoom ID, passcode, IMPORTANT, 10 meetings Mon–Fri' . ( $miss ? ' — missing: ' . implode( ' | ', $miss ) . ' (sent=' . $n . ')' : '' ) );
$t( $m && 0 === strpos( $m['subject'], 'Welcome to your Driver Education class' ), 'subject: ' . ( $m ? $m['subject'] : '-' ) );
// the automatic 24h-before e-mail (plain text from class-email.php) is upgraded to the same welcome e-mail
$up = SDSC_ZoomAuto::upgrade_auto_mail( array( 'to' => 'x@example.test', 'subject' => 'Your Zoom class link — ZZTEST', 'message' => "Hi Zed,\n\nHere is everything for your class “ZZTEST” (class ZZ-1):\n\nZoom link: x\n\nSee you", 'headers' => '' ) );
$t( 0 === strpos( $up['subject'], 'Welcome to your Driver Education class' ) && false !== strpos( $up['message'], 'Dear Zed,' ) && false !== strpos( $up['message'], '(unit 10)' ) && in_array( 'X-SDSC-From: info', (array) $up['headers'], true ), 'automatic 24h e-mail uses the same welcome text' );
$t( 'Other' === SDSC_ZoomAuto::upgrade_auto_mail( array( 'subject' => 'Other', 'message' => 'x', 'headers' => '' ) )['subject'], 'other e-mails untouched' );
// cleanup
$wpdb->delete( SDSC_Registration::table(), array( 'email' => 'zztest-sched@example.test' ) );
foreach ( get_posts( array( 'post_type' => 'sdsc_registrant', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_sdsc_class_id', 'meta_value' => $cid, 'fields' => 'ids' ) ) as $p ) { wp_delete_post( $p, true ); }
wp_delete_post( $cid, true );
$t( ! get_post( $cid ), 'test class removed' );
echo "PASS $ok FAIL $bad\n";
