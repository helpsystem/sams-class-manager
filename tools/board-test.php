<?php
/**
 * Class board self-test (staging). Run as an administrator:
 *   wp --user=<admin> eval-file board-test.php
 * Adds two throw-away students (e2e-board-*@example.com), moves them, records and
 * cancels a cash payment, checks seat limits, then removes everything it made.
 */
if ( ! class_exists( 'SDSC_Board' ) ) { echo "SDSC_Board missing\n"; return; }
SDSC_Board::maybe_install();
add_filter( 'pre_wp_mail', '__return_false' );            // no e-mails during the test
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new Exception( 'die' ); }; } );

global $wpdb;
$pass = 0; $fail = 0;
$ok   = function ( $c, $t ) use ( &$pass, &$fail ) { if ( $c ) { $pass++; echo "PASS $t\n"; } else { $fail++; echo "FAIL $t\n"; } };
$call = function ( $a, $post ) {
	$_POST = $_REQUEST = array_merge( $post, array( 'nonce' => wp_create_nonce( 'sdsc_board' ) ) );
	ob_start();
	try { call_user_func( array( 'SDSC_Board', 'ajax_' . $a ) ); } catch ( Exception $e ) {}
	return json_decode( ob_get_clean(), true );
};

$classes = array_map( 'intval', wp_list_pluck( SDSC_Registration::open_classes( true ), 'ID' ) );
if ( count( $classes ) < 2 ) { echo "Need two open classes\n"; return; }
list( $A, $B ) = $classes;
$capA = get_post_meta( $A, '_sdsc_capacity', true ); $capB = get_post_meta( $B, '_sdsc_capacity', true );
$log0 = get_option( SDSC_Board::LOG ); $open0 = get_option( SDSC_Gate::OPT_OPEN );
$tA0  = SDSC_Board::taken( $A ); $tB0 = SDSC_Board::taken( $B );
$made = array();

try {
	$ok( SDSC_Board::can(), 'administrator can use the board' );
	$r = $call( 'add', array( 'cid' => $A, 'first_name' => 'E2E', 'last_name' => 'Board One', 'email' => 'e2e-board-1@example.com', 'phone' => '' ) );
	$ok( ! empty( $r['success'] ), 'add a walk-in student to class A' );
	$rid = (int) $wpdb->get_var( "SELECT id FROM " . SDSC_Registration::table() . " WHERE email = 'e2e-board-1@example.com'" ); $made[] = $rid;
	$ok( SDSC_Board::taken( $A ) === $tA0 + 1, 'class A has one more seat taken' );

	$r = $call( 'move', array( 'key' => 'r' . $rid, 'to' => $B ) );
	$row = $wpdb->get_row( "SELECT * FROM " . SDSC_Registration::table() . " WHERE id = $rid" );
	$ok( ! empty( $r['success'] ) && (int) $row->class_id === $B && (int) get_post_meta( $row->registrant_id, '_sdsc_class_id', true ) === $B, 'move the student to class B (form and roster)' );
	$ok( SDSC_Board::taken( $A ) === $tA0 && SDSC_Board::taken( $B ) === $tB0 + 1, 'seat counts follow the move' );

	$r = $call( 'pay', array( 'key' => 'r' . $rid, 'method' => 'check', 'amount' => '378', 'ref' => '', 'paid_on' => '', 'note' => '' ) );
	$ok( empty( $r['success'] ), 'a check without a number is refused' );
	$r = $call( 'pay', array( 'key' => 'r' . $rid, 'method' => 'cash', 'amount' => '378', 'ref' => '', 'paid_on' => '', 'note' => 'test' ) );
	$ok( ! empty( $r['success'] ) && SDSC_Gate::paid_email( 'e2e-board-1@example.com' ), 'cash $378 recorded and counts as paid' );
	$ok( SDSC_Gate::opened( 'e2e-board-1@example.com' ), 'online course opened for the student' );
	$pay = (int) $wpdb->get_var( "SELECT id FROM " . SDSC_Board::table() . " WHERE email = 'e2e-board-1@example.com' AND status = 'paid'" );
	$r = $call( 'void', array( 'id' => $pay ) );
	$ok( ! empty( $r['success'] ) && ! SDSC_Gate::paid_email( 'e2e-board-1@example.com' ) && ! SDSC_Gate::opened( 'e2e-board-1@example.com' ), 'cancelling the payment locks the course again' );

	update_post_meta( $B, '_sdsc_capacity', SDSC_Board::taken( $B ) );
	$ok( SDSC_Board::is_full( $B ), 'class B is full when seats = students' );
	$r = $call( 'add', array( 'cid' => $B, 'first_name' => 'E2E', 'last_name' => 'Board Two', 'email' => 'e2e-board-2@example.com', 'phone' => '' ) );
	$ok( empty( $r['success'] ) && ! empty( $r['data']['full'] ), 'adding to a full class asks first' );
	$r = $call( 'add', array( 'cid' => $B, 'first_name' => 'E2E', 'last_name' => 'Board Two', 'email' => 'e2e-board-2@example.com', 'phone' => '', 'grow' => 1 ) );
	$made[] = (int) $wpdb->get_var( "SELECT id FROM " . SDSC_Registration::table() . " WHERE email = 'e2e-board-2@example.com'" );
	$ok( ! empty( $r['success'] ) && SDSC_Board::cap( $B ) === SDSC_Board::taken( $B ), '"add a seat" grows the class by one' );
	$r = $call( 'seats', array( 'cid' => $B, 'cap' => 1 ) );
	$ok( empty( $r['success'] ), 'seats cannot go below the students already in the class' );
	$r = $call( 'move', array( 'key' => 'r' . $rid, 'to' => 0 ) );
	$ok( ! empty( $r['success'] ) && null === $wpdb->get_var( "SELECT class_id FROM " . SDSC_Registration::table() . " WHERE id = $rid" ), 'move back to "No class yet"' );

	$_POST = array( 'class_id' => $B );
	$ok( SDSC_Board::is_full( $B ) === ( SDSC_Board::taken( $B ) >= SDSC_Board::cap( $B ) ), 'website registration uses the same full check' );
} catch ( Throwable $e ) { $fail++; echo 'ERROR ' . $e->getMessage() . "\n"; }

// clean up everything the test made
foreach ( array_filter( $made ) as $id ) {
	$pid = (int) $wpdb->get_var( "SELECT registrant_id FROM " . SDSC_Registration::table() . " WHERE id = $id" );
	if ( $pid ) { wp_delete_post( $pid, true ); }
	$wpdb->delete( SDSC_Registration::table(), array( 'id' => $id ) );
}
$wpdb->query( "DELETE FROM " . SDSC_Board::table() . " WHERE email LIKE 'e2e-board-%@example.com'" );
foreach ( array( $A => $capA, $B => $capB ) as $id => $c ) { '' === $c ? delete_post_meta( $id, '_sdsc_capacity' ) : update_post_meta( $id, '_sdsc_capacity', $c ); }
false === $log0 ? delete_option( SDSC_Board::LOG ) : update_option( SDSC_Board::LOG, $log0, false );
update_option( SDSC_Gate::OPT_OPEN, $open0, false );
$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . SDSC_Registration::table() . " WHERE email LIKE 'e2e-board-%'" );
echo "left over test rows: $left\nRESULT: $pass passed, $fail failed\n";
