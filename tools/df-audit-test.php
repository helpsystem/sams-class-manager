<?php
/**
 * DriveFlow audit regression test (STAGING ONLY). Creates rows named "ZZTEST …", checks the 2026-10-16 fixes,
 * then deletes everything it created. Run: wp eval-file tools/df-audit-test.php
 */
if ( false === strpos( home_url(), 'testsaman.' ) ) { echo "Refusing: not the staging site.\n"; return; }

global $wpdb;
$P   = $wpdb->prefix;
$ok  = 0;
$bad = 0;
$T   = function ( $name, $cond, $info = '' ) use ( &$ok, &$bad ) {
	if ( $cond ) { $ok++; echo "  ok   $name\n"; } else { $bad++; echo "  FAIL $name" . ( '' !== $info ? " — $info" : '' ) . "\n"; }
};
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new Exception( 'die' ); }; } );
add_filter( 'pre_wp_mail', '__return_false', 1 ); // never send mail from this test
$call = function ( $action, $post ) {
	$_POST = $_REQUEST = $post;
	ob_start();
	try { do_action( 'wp_ajax_' . $action ); } catch ( Exception $e ) {}
	$out = ob_get_clean();
	$_POST = $_REQUEST = array();
	return json_decode( $out, true );
};
$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
$now   = current_time( 'mysql' );
$made  = array( 'students' => array(), 'sessions' => array(), 'instructors' => array(), 'payouts' => array() );

wp_set_current_user( $admin );
$n_admin = wp_create_nonce( 'driveflow_admin_nonce' );

// ---------- fixtures
$wpdb->insert( "{$P}driveflow_students", array( 'name' => 'ZZTEST Student A', 'email' => 'zztest-a@example.invalid', 'phone' => '0000000001', 'total_sessions' => 3, 'completed_sessions' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$sa = (int) $wpdb->insert_id; $made['students'][] = $sa;
$wpdb->insert( "{$P}driveflow_instructors", array( 'name' => 'ZZTEST Instructor', 'email' => 'zztest-i@example.invalid', 'phone' => '0', 'license_number' => 'ZZ1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$ia = (int) $wpdb->insert_id; $made['instructors'][] = $ia;
$mk = function ( $start, $status = 'upcoming', $len = 2 ) use ( $wpdb, $P, $sa, $now, &$made ) {
	$wpdb->insert( "{$P}driveflow_sessions", array( 'student_name' => 'ZZTEST Student A', 'student_id' => $sa, 'instructor_name' => 'ZZTEST Instructor', 'plate_number' => '', 'session_number' => 1, 'lesson_topic' => 'ZZTEST', 'scheduled_start' => $start, 'scheduled_end' => date( 'Y-m-d H:i:s', strtotime( $start ) + $len * 3600 ), 'status' => $status, 'form_data' => '{}', 'signature' => '', 'selfie' => '', 'created_at' => $now, 'updated_at' => $now ) );
	$id = (int) $wpdb->insert_id; $made['sessions'][] = $id; return $id;
};
$completed = function () use ( $wpdb, $P, $sa ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT completed_sessions FROM {$P}driveflow_students WHERE id = %d", $sa ) ); };

echo "1. Credits ledger\n";
$s1 = $mk( date( 'Y-m-d 09:00:00', current_time( 'timestamp' ) - DAY_IN_SECONDS ) );
$wpdb->insert( "{$P}driveflow_student_credits", array( 'student_id' => $sa, 'action_type' => 'session_deduction', 'credits_delta' => -1, 'notes' => 'Completed Driving Session #' . $s1 . '7', 'created_at' => $now ) );
$T( 'session #N not confused with #N7', 0 === DriveFlow_Credit_Manager::session_net_deducted( $sa, $s1 ) );
$r = $call( 'driveflow_quick_status', array( 'nonce' => $n_admin, 'session_id' => $s1, 'status' => 'completed' ) );
$T( 'quick status → completed takes 1 credit', 1 === $completed(), 'completed=' . $completed() . ' resp=' . wp_json_encode( $r ) );
$call( 'driveflow_quick_status', array( 'nonce' => $n_admin, 'session_id' => $s1, 'status' => 'completed' ) );
$T( 'same status twice: no second credit', 1 === $completed() );
$call( 'driveflow_quick_status', array( 'nonce' => $n_admin, 'session_id' => $s1, 'status' => 'cancelled' ) );
$T( 'completed → cancelled gives the credit back', 0 === $completed() );
$call( 'driveflow_quick_status', array( 'nonce' => $n_admin, 'session_id' => $s1, 'status' => 'completed' ) );
$T( 'completed again takes it again', 1 === $completed() );
$call( 'driveflow_update_session', array( 'nonce' => $n_admin, 'session_id' => $s1, 'status' => 'upcoming' ) );
$T( 'edit box completed → upcoming refunds', 0 === $completed() );
$call( 'driveflow_update_session', array( 'nonce' => $n_admin, 'session_id' => $s1, 'status' => 'completed' ) );
$T( 'edit box → completed deducts', 1 === $completed() );
$call( 'driveflow_delete_session', array( 'nonce' => $n_admin, 'session_id' => $s1 ) );
$T( 'deleting a completed lesson refunds', 0 === $completed() );
$led = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits_delta),0) FROM {$P}driveflow_student_credits WHERE student_id = %d AND notes LIKE %s", $sa, '%Session #' . $s1 ) );
$T( 'ledger for that lesson nets to zero', 0 === $led, "net=$led" );

echo "2. Unsigned remaining\n";
$wpdb->update( "{$P}driveflow_students", array( 'completed_sessions' => 5 ), array( 'id' => $sa ) );
$rows = $wpdb->get_results( "SELECT id, (CAST(total_sessions AS SIGNED) - CAST(completed_sessions AS SIGNED)) AS remaining_sessions FROM {$P}driveflow_students WHERE status = 'active'", ARRAY_A );
$T( 'list still loads when a student is over the package', is_array( $rows ) && count( $rows ) > 0 && '' === $wpdb->last_error, $wpdb->last_error );
$wpdb->update( "{$P}driveflow_students", array( 'completed_sessions' => 0 ), array( 'id' => $sa ) );

echo "3. Self-booking rules\n";
$tok = DriveFlow_Credit_Manager::get_or_create_token( $sa );
$d   = date( 'Y-m-d', current_time( 'timestamp' ) + 3 * DAY_IN_SECONDS );
$r1  = DriveFlow_Credit_Manager::self_book_session( $tok, "$d 08:00:00", "$d 10:00:00", 'ZZTEST Instructor' );
$T( '8 AM start refused', empty( $r1['success'] ) );
$r2  = DriveFlow_Credit_Manager::self_book_session( $tok, "$d 09:00:00", "$d 13:00:00", 'ZZTEST Instructor' );
$T( '4-hour lesson refused', empty( $r2['success'] ) );
$r3  = DriveFlow_Credit_Manager::self_book_session( $tok, "$d 09:00:00", "$d 11:00:00", 'Nobody Real' );
$T( 'made-up instructor refused', empty( $r3['success'] ) );
$r4  = DriveFlow_Credit_Manager::self_book_session( $tok, "$d 09:00:00", "$d 11:00:00", 'ZZTEST Instructor' );
$T( '9–11 block with a real instructor booked', ! empty( $r4['success'] ), wp_json_encode( $r4 ) );
$made['sessions'] = array_merge( $made['sessions'], array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$P}driveflow_sessions WHERE student_id = %d", $sa ) ) ) );
$r5  = DriveFlow_Credit_Manager::self_book_session( $tok, "$d 13:00:00", "$d 15:00:00", 'ZZTEST Instructor' );
$T( 'second lesson the same day refused (MVA 2 h/day)', empty( $r5['success'] ) && false !== strpos( (string) $r5['message'], '2 hours' ) );
$slots = DriveFlow_Availability_Engine::get_instructor_free_slots( 'ZZTEST Instructor', date( 'Y-m-d', current_time( 'timestamp' ) + 4 * DAY_IN_SECONDS ) );
$hrs = array_map( function ( $s ) { return (int) substr( $s['start_iso'] ?? '', 11, 2 ); }, (array) ( $slots['slots'] ?? array() ) );
$T( 'free slots are the school blocks', $hrs && ! array_diff( $hrs, array( 9, 11, 13, 15, 17 ) ), wp_json_encode( $hrs ) );

echo "4. Magic link expiry (school clock)\n";
if ( class_exists( 'DriveFlow_Magic_Links' ) ) {
	$ml = DriveFlow_Magic_Links::create_token( 'student_booking', $sa, 1, false );
	$tk = is_array( $ml ) ? ( $ml['token'] ?? '' ) : '';
	$v  = $tk ? DriveFlow_Magic_Links::verify_token( $tk ) : new WP_Error( 'x', 'no token' );
	$T( 'a 1-hour link works right after it is made', ! is_wp_error( $v ), is_wp_error( $v ) ? $v->get_error_message() : '' );
	if ( $tk ) { $wpdb->delete( "{$P}driveflow_magic_tokens", array( 'token' => $tk ) ); }
}

echo "5. CSV guard\n";
$fh = fopen( 'php://memory', 'w+' );
DriveFlow_Pro::fputcsv_safe( $fh, array( '=HYPERLINK("x")', '+1', '-5.00', '@cmd', 'Normal' ) );
rewind( $fh ); $line = trim( stream_get_contents( $fh ) );
$T( 'formula cells written as text', 0 === strpos( $line, '"\'=HYPERLINK' ) && false !== strpos( $line, "'@cmd" ) && false !== strpos( $line, ',-5.00,' ), $line );

echo "6. Instructor evaluation limits\n";
$iu = (int) ( get_users( array( 'role' => 'tutor_instructor', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
if ( $iu && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$P}driveflow_instructors WHERE user_id = %d", $iu ) ) ) {
	$wpdb->update( "{$P}driveflow_instructors", array( 'user_id' => $iu ), array( 'id' => $ia ) );
	wp_set_current_user( $iu );
	$n_ins = wp_create_nonce( 'driveflow_instructor_form' );
	$s2 = $mk( date( 'Y-m-d 09:00:00', current_time( 'timestamp' ) ), 'upcoming' );
	$r = $call( 'driveflow_submit_evaluation', array( 'df_nonce' => $n_ins, 'session_id' => $s2, 'student_name' => 'Someone Else', 'scheduled_start' => date( 'Y-m-d 00:00:00' ), 'scheduled_end' => date( 'Y-m-d 23:00:00' ), 'final_evaluation' => 'Pass' ) );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$P}driveflow_sessions WHERE id = %d", $s2 ), ARRAY_A );
	$T( 'booked lesson keeps the office times', $row && 7200 === strtotime( $row['scheduled_end'] ) - strtotime( $row['scheduled_start'] ), $row ? $row['scheduled_start'] . '→' . $row['scheduled_end'] : wp_json_encode( $r ) );
	$T( 'booked lesson keeps its student', $row && (int) $row['student_id'] === $sa && 'ZZTEST Student A' === $row['student_name'] );
	$T( 'credit taken from the right student', 1 === $completed() );
	$r = $call( 'driveflow_submit_evaluation', array( 'df_nonce' => $n_ins, 'session_id' => 0, 'student_name' => 'ZZTEST Student A', 'scheduled_start' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 5 * HOUR_IN_SECONDS ), 'scheduled_end' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'final_evaluation' => 'Pass' ) );
	$wid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$P}driveflow_sessions WHERE student_id = %d", $sa ) );
	if ( $wid ) { $made['sessions'][] = $wid; }
	$w = $wpdb->get_row( $wpdb->prepare( "SELECT scheduled_start, scheduled_end FROM {$P}driveflow_sessions WHERE id = %d", $wid ), ARRAY_A );
	$T( 'walk-in of 5 h is stored as 2 h', $w && strtotime( $w['scheduled_end'] ) - strtotime( $w['scheduled_start'] ) <= 7200, wp_json_encode( $w ) . ' ' . wp_json_encode( $r ) );
	$r = $call( 'driveflow_submit_evaluation', array( 'df_nonce' => $n_ins, 'session_id' => 0, 'student_name' => 'ZZTEST Student A', 'scheduled_start' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3 * DAY_IN_SECONDS ), 'scheduled_end' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3 * DAY_IN_SECONDS + 7200 ), 'final_evaluation' => 'Pass' ) );
	$T( 'walk-in from 3 days ago refused', is_array( $r ) && empty( $r['success'] ), wp_json_encode( $r ) );
	// office-completed lesson can still get its evaluation
	wp_set_current_user( $admin );
	$s3 = $mk( date( 'Y-m-d 11:00:00', current_time( 'timestamp' ) ), 'upcoming' );
	$call( 'driveflow_quick_status', array( 'nonce' => $n_admin, 'session_id' => $s3, 'status' => 'completed' ) );
	wp_set_current_user( $iu );
	$r = $call( 'driveflow_submit_evaluation', array( 'df_nonce' => $n_ins, 'session_id' => $s3, 'student_name' => 'ZZTEST Student A', 'final_evaluation' => 'Pass' ) );
	$T( 'office-completed lesson accepts the evaluation once', is_array( $r ) && ! empty( $r['success'] ), wp_json_encode( $r ) );
	$r = $call( 'driveflow_submit_evaluation', array( 'df_nonce' => $n_ins, 'session_id' => $s3, 'student_name' => 'ZZTEST Student A', 'final_evaluation' => 'Pass' ) );
	$T( '…and is locked after that', is_array( $r ) && empty( $r['success'] ) );
	$T( 'office "done" + evaluation = one credit for that lesson', 1 === DriveFlow_Credit_Manager::session_net_deducted( $sa, $s3 ) );
	$wpdb->update( "{$P}driveflow_instructors", array( 'user_id' => 0 ), array( 'id' => $ia ) );
} else {
	echo "  skip (no free instructor user on staging)\n";
}
wp_set_current_user( $admin );

echo "7. Payout double click\n";
$pp = array( 'nonce' => $n_admin, 'instructor_id' => $ia, 'amount' => '12.34', 'payment_date' => date( 'Y-m-d' ), 'reference_number' => 'ZZTEST', 'payment_method' => 'cash' );
$call( 'driveflow_record_instructor_payout', $pp );
$r = $call( 'driveflow_record_instructor_payout', $pp );
$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$P}driveflow_instructor_payouts WHERE instructor_id = %d", $ia ) );
$T( 'second identical payout refused', 1 === $cnt, "count=$cnt" );

echo "8. Portal and TV\n";
$df = ( new ReflectionClass( 'DriveFlow_Pro' ) )->newInstanceWithoutConstructor();
wp_set_current_user( 0 );
$_GET['df_student_lookup'] = (string) $sa;
$h = $df->student_portal_shortcode();
$T( 'visitor cannot open a student by number', false === strpos( $h, 'ZZTEST Student A' ) && false !== strpos( $h, 'Please sign in' ) );
unset( $_GET['df_student_lookup'] );
$tv = $df->tv_board( true );
$T( 'public TV page has no phone/licence/key', false === strpos( $tv, 'license_number' ) && false === strpos( $tv, 'tvApiKey' ) );
$req = new WP_REST_Request( 'GET', '/driveflow/v1/tv-sessions' );
$T( 'TV REST feed closed without key', false === $df->can_read_tv( $req ) );
wp_set_current_user( $admin );

echo "9. Onboarding cannot take a colleague's name\n";
if ( class_exists( 'DriveFlow_Magic_Links' ) ) {
	$wpdb->insert( "{$P}driveflow_instructors", array( 'name' => 'ZZTEST Newbie', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$ib = (int) $wpdb->insert_id; $made['instructors'][] = $ib;
	$ml = DriveFlow_Magic_Links::create_token( 'instructor_onboarding', $ib, 1, false );
	wp_set_current_user( 0 );
	$r = $call( 'driveflow_submit_instructor_onboarding', array( 'onboarding_nonce' => wp_create_nonce( 'driveflow_instructor_onboarding' ), 'token' => $ml['token'] ?? '', 'name' => 'zztest instructor', 'phone' => '1', 'email' => 'zztest-n@example.invalid', 'license_number' => 'ZZ2' ) );
	$T( 'name of another instructor refused', is_array( $r ) && empty( $r['success'] ), wp_json_encode( $r ) );
	wp_set_current_user( $admin );
	if ( ! empty( $ml['token'] ) ) { $wpdb->delete( "{$P}driveflow_magic_tokens", array( 'token' => $ml['token'] ) ); }
}

// ---------- clean up everything this test made
foreach ( array_unique( $made['sessions'] ) as $id ) { $wpdb->delete( "{$P}driveflow_sessions", array( 'id' => $id ) ); }
$wpdb->query( "DELETE FROM {$P}driveflow_sessions WHERE lesson_topic = 'ZZTEST' OR student_name LIKE 'ZZTEST%'" );
foreach ( $made['students'] as $id ) { $wpdb->delete( "{$P}driveflow_student_credits", array( 'student_id' => $id ) ); $wpdb->delete( "{$P}driveflow_students", array( 'id' => $id ) ); $wpdb->delete( "{$P}driveflow_magic_tokens", array( 'target_id' => $id, 'token_type' => 'student_booking' ) ); }
foreach ( $made['instructors'] as $id ) { $wpdb->delete( "{$P}driveflow_instructor_payouts", array( 'instructor_id' => $id ) ); $wpdb->delete( "{$P}driveflow_instructors", array( 'id' => $id ) ); }
$left = (int) $wpdb->get_var( "SELECT (SELECT COUNT(*) FROM {$P}driveflow_students WHERE name LIKE 'ZZTEST%') + (SELECT COUNT(*) FROM {$P}driveflow_instructors WHERE name LIKE 'ZZTEST%') + (SELECT COUNT(*) FROM {$P}driveflow_sessions WHERE student_name LIKE 'ZZTEST%')" );
echo "\nRESULT: $ok passed, $bad failed. Test rows left: $left\n";
