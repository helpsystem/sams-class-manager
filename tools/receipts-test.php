<?php
// Staging check for receipts (cash / check / payouts). Removes everything it makes.
global $wpdb; $ok = 0; $bad = 0;
$t = function ( $c, $m ) use ( &$ok, &$bad ) { if ( $c ) { $ok++; echo "ok  $m\n"; } else { $bad++; echo "BAD $m\n"; } };
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ); wp_set_current_user( $admin[0] );
SDSC_Receipts::install(); SDSC_Lessons::install();
$t( 'Three hundred seventy-eight and 00/100 dollars' === SDSC_Receipts::words( 37800 ), 'words 378: ' . SDSC_Receipts::words( 37800 ) );
$t( 'One thousand two hundred five and 50/100 dollars' === SDSC_Receipts::words( 120550 ), 'words 1205.50: ' . SDSC_Receipts::words( 120550 ) );
$em = 'rcpt-test-' . wp_generate_password( 5, false, false ) . '@example.test';
// 1. class board: check payment
$wpdb->insert( SDSC_Board::table(), array( 'email' => $em, 'method' => 'check', 'amount' => 37800, 'ref' => '1042', 'paid_on' => current_time( 'Y-m-d' ), 'status' => 'paid', 'by_user' => $admin[0], 'created_at' => current_time( 'mysql' ) ) );
$bid = (int) $wpdb->insert_id;
$r = SDSC_Receipts::get( 'board', $bid );
$t( $r && 'in' === $r['kind'] && 32 === strlen( $r['token'] ), 'board receipt made: ' . ( $r ? SDSC_Receipts::number( $r ) : '-' ) );
$h = $r ? SDSC_Receipts::html( $r ) : '';
$t( false !== strpos( $h, '$378.00' ) && false !== strpos( $h, 'Check #1042' ) && false !== strpos( $h, 'Payment receipt' ) && false === strpos( $h, 'VOID' ), 'board receipt shows amount, check #, title' );
$t( false === strpos( $h, $em ), 'no e-mail address on the receipt' );
$r2 = SDSC_Receipts::get( 'board', $bid ); $t( $r2 && $r2['id'] === $r['id'], 'same receipt the second time' );
$res = wp_remote_get( SDSC_Receipts::link( $r ), array( 'sslverify' => false, 'timeout' => 20 ) );
$code = wp_remote_retrieve_response_code( $res ); $body = wp_remote_retrieve_body( $res );
$t( 200 === $code && false !== strpos( $body, SDSC_Receipts::number( $r ) ), "private link opens ($code)" );
$cc = (string) wp_remote_retrieve_header( $res, 'cache-control' ); $xr = (string) wp_remote_retrieve_header( $res, 'x-robots-tag' );
$t( false !== stripos( $cc, 'no-store' ) && false !== stripos( $xr, 'noindex' ), "not cached, not indexed ($cc | $xr)" );
$res = wp_remote_get( add_query_arg( 'sdsc_receipt', str_repeat( 'a', 32 ), home_url( '/' ) ), array( 'sslverify' => false ) );
$t( 404 === wp_remote_retrieve_response_code( $res ), 'wrong token: 404' );
$tx = SDSC_Receipts::text( $r ); $t( false !== strpos( $tx, '$378.00' ) && false !== strpos( $tx, $r['token'] ), 'text message: ' . $tx );
$wpdb->update( SDSC_Board::table(), array( 'status' => 'void' ), array( 'id' => $bid ) );
$t( false !== strpos( SDSC_Receipts::html( $r ), 'VOID' ), 'cancelled payment: receipt shows VOID' );
// 2. lesson fee paid at the office by check
$wpdb->insert( SDSC_Lessons::t_fees(), array( 'lesson_id' => 0, 'email' => $em, 'amount' => 60, 'reason' => 'no_show', 'status' => 'paid', 'paid_at' => current_time( 'mysql' ), 'paid_how' => 'Check #2201 (recorded by Test Person)', 'created_at' => current_time( 'mysql' ) ) );
$fid = (int) $wpdb->insert_id;
$t( $fid > 0 && 'Check #2201 (recorded by Test Person)' === $wpdb->get_var( $wpdb->prepare( 'SELECT paid_how FROM ' . SDSC_Lessons::t_fees() . ' WHERE id=%d', $fid ) ), 'long "paid how" is stored in full' );
$rf = SDSC_Receipts::get( 'fee', $fid ); $hf = $rf ? SDSC_Receipts::html( $rf ) : '';
$t( $rf && false !== strpos( $hf, '$60.00' ) && false !== strpos( $hf, 'Check #2201' ) && false !== strpos( $hf, 'Test Person' ), 'fee receipt: $60, check #2201, recorded by' );
$wpdb->insert( SDSC_Lessons::t_fees(), array( 'lesson_id' => 0, 'email' => $em, 'amount' => 60, 'reason' => 'no_show', 'status' => 'paid', 'paid_at' => current_time( 'mysql' ), 'paid_how' => 'Online (order #123)', 'created_at' => current_time( 'mysql' ) ) );
$fid2 = (int) $wpdb->insert_id; $t( null === SDSC_Receipts::get( 'fee', $fid2 ), 'online fee: no office receipt (Stripe has its own)' );
// 3. payout to an instructor
$pt = $wpdb->prefix . 'driveflow_instructor_payouts';
$wpdb->insert( $pt, array( 'instructor_id' => 0, 'instructor_name' => 'Test Instructor', 'amount' => 420, 'hours_paid' => 12, 'hourly_rate' => 35, 'payment_date' => current_time( 'Y-m-d' ), 'payment_method' => 'cash', 'reference_number' => '', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'notes' => '', 'status' => 'paid', 'created_by' => $admin[0], 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$pid = (int) $wpdb->insert_id;
$rp = SDSC_Receipts::get( 'payout', $pid ); $hp = $rp ? SDSC_Receipts::html( $rp ) : '';
$t( $rp && 'out' === $rp['kind'] && 0 === strpos( SDSC_Receipts::number( $rp ), 'P-' ), 'payout voucher made: ' . ( $rp ? SDSC_Receipts::number( $rp ) : '-' ) );
$t( false !== strpos( $hp, '$420.00' ) && false !== strpos( $hp, 'Paid to' ) && false !== strpos( $hp, 'Received by (instructor)' ) && false !== strpos( $hp, '12 hours at $35.00/hour' ), 'voucher: amount, paid to, instructor signature, hours' );
$wpdb->delete( $pt, array( 'id' => $pid ) );
$t( false !== strpos( SDSC_Receipts::html( $rp ), 'VOID' ), 'deleted payout: voucher shows VOID' );
// access
$fd = get_users( array( 'role' => 'sdsc_front_desk', 'number' => 1, 'fields' => 'ID' ) );
if ( $fd ) { wp_set_current_user( $fd[0] ); $t( ! SDSC_Receipts::can( 'board' ) && ! SDSC_Receipts::can( 'payout' ), 'front desk cannot open receipts' ); wp_set_current_user( $admin[0] ); }
$ti = get_users( array( 'role' => 'tutor_instructor', 'number' => 1, 'fields' => 'ID' ) );
if ( $ti ) { wp_set_current_user( $ti[0] ); $t( ! SDSC_Receipts::can( 'board' ) && ! SDSC_Receipts::can( 'fee' ) && ! SDSC_Receipts::can( 'payout' ), 'instructor cannot open receipts' ); wp_set_current_user( $admin[0] ); }
// clean up
$wpdb->delete( SDSC_Board::table(), array( 'id' => $bid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . SDSC_Lessons::t_fees() . ' WHERE email = %s', $em ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . SDSC_Receipts::table() . ' WHERE (src = %s AND src_id = %d) OR (src = %s AND src_id IN (%d,%d)) OR (src = %s AND src_id = %d)', 'board', $bid, 'fee', $fid, $fid2, 'payout', $pid ) );
$t( 0 == $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Receipts::table() . ' WHERE (src = %s AND src_id = %d) OR (src = %s AND src_id = %d)', 'board', $bid, 'payout', $pid ) ), 'test rows removed' );
echo "PASS $ok FAIL $bad\n";
