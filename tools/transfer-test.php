<?php
/** Transfer module test on the test site: creates a registration, records in/out, renders the panel and the record, sends the e-mail to a test address, then removes everything. */
if ( ! class_exists( 'SDSC_Transfer' ) ) { echo "SDSC_Transfer missing\n"; return; }
global $wpdb;
$n = 0; $f = 0;
$ok = function ( $c, $m ) use ( &$n, &$f ) { $n++; if ( ! $c ) { $f++; } echo ( $c ? 'ok   ' : 'FAIL ' ) . $m . "\n"; };
SDSC_Transfer::install();
$ok( $wpdb->get_var( "SHOW TABLES LIKE '" . SDSC_Transfer::table() . "'" ) === SDSC_Transfer::table(), 'table exists' );
$rt = SDSC_Registration::table();
$cid = (int) ( get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sdsc_status', 'meta_value' => 'upcoming' ) )[0] ?? 0 );
$wpdb->insert( $rt, array( 'created_at' => current_time( 'mysql' ), 'source' => 'office', 'class_id' => $cid ?: null, 'first_name' => 'Trtest', 'last_name' => 'Zzmodule', 'email' => 'trtest@example.test', 'parent_email' => 'trparent@example.test', 'phone' => '0000000000', 'dob' => '2010-01-01', 'notes' => 'transfer test' ) );
$rid = (int) $wpdb->insert_id; $ok( $rid > 0, "test registration #$rid" );
$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $rt WHERE id = %d", $rid ), ARRAY_A );
$id = SDSC_Transfer::save( $rid, 'in', array( 'school' => 'Other School <b>x</b>', 'hours' => '12.26', 'on_date' => '2026-10-01', 'note' => 'units 1-4' ) );
$in = SDSC_Transfer::get( $rid, 'in' );
$ok( $id && $in && 12.5 == $in['hours'] && 'Other School x' === $in['school'], 'transfer in saved, hours rounded to .5, tags stripped' );
SDSC_Transfer::save( $rid, 'in', array( 'school' => 'Other School', 'hours' => 12, 'on_date' => '2026-10-01' ) );
$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Transfer::table() . ' WHERE reg_id = %d', $rid ) ), 'second save updates, no duplicate row' );
$ok( 'Transfer in · 12 h done elsewhere' === SDSC_Transfer::tag( $rid ), 'tag: ' . SDSC_Transfer::tag( $rid ) );
list( $h, $a, $t ) = SDSC_Transfer::done_here( $r );
$ok( is_numeric( $h ) && $a <= $t, "done_here: $h h, $a of $t sessions" );
$panel = SDSC_Transfer::panel( (object) $r );
$ok( false !== strpos( $panel, 'still needed here: <b>18 h' ), 'panel shows hours still needed (18)' );
$ok( false !== strpos( $panel, 'Save the “Leaving for another school” box first' ), 'no print/e-mail before transfer out is saved' );
SDSC_Transfer::save( $rid, 'out', array( 'school' => 'New School', 'hours' => 6, 'on_date' => '2026-10-16', 'note' => 'moved' ) );
$panel = SDSC_Transfer::panel( (object) $r );
$ok( false !== strpos( $panel, 'sdsc_transfer_print' ) && false !== strpos( $panel, 'sdsc_transfer_mail' ) && false !== strpos( $panel, 'trparent@example.test' ), 'panel shows print + e-mail with both addresses' );
$p = SDSC_Student_File::from_regs( array( $rid ) )[0];
$rec = SDSC_Transfer::record_html( $p );
$ok( false !== strpos( $rec, 'Driver Education Transfer Record' ) && false !== strpos( $rec, '6 h of 30' ) && false !== strpos( $rec, 'Total classroom hours to date' ) && false !== strpos( $rec, '18 h of 30' ) && false !== strpos( $rec, '12 h classroom' ), 'record: hours here 6, total 18, still required 12' );
$ok( in_array( 'transfer', array_keys( SDSC_Student_File::parts() ), true ), 'student file has the transfer part' );
// e-mail (no attachments: no docs on file) — the test site never e-mails the office; wp_mail goes to the guard
$_POST = array( 'r' => $rid, '_wpnonce' => wp_create_nonce( 'sdsc_transfer_' . $rid ), 'to' => array( 'email', 'parent_email' ), 'docs' => array(), 'msg' => 'Test message' );
wp_set_current_user( 1 );
$caught = array();
add_filter( 'wp_mail', function ( $a ) use ( &$caught ) { $caught[] = $a; return $a; } );
add_filter( 'pre_wp_mail', function () { return true; } );
add_filter( 'wp_redirect', function ( $u ) { throw new Exception( $u ); } );
try { SDSC_Transfer::h_mail(); } catch ( Exception $e ) { $url = $e->getMessage(); }
$ok( 2 === count( $caught ), 'two e-mails prepared (student + parent): ' . count( $caught ) );
$ok( $caught && false !== strpos( $caught[0]['subject'], 'transfer record' ) && false !== strpos( $caught[0]['message'], '6 h of 30' ) && false !== strpos( $caught[0]['message'], 'Test message' ), 'e-mail has subject, hours and the message' );
$ok( $caught && in_array( 'X-SDSC-From: info', $caught[0]['headers'], true ), 'sent from info@' );
$out = SDSC_Transfer::get( $rid, 'out' );
$ok( ! empty( $out['sent_log'] ) && false !== strpos( $out['sent_log'], 'trtest@example.test' ), 'send logged on the record' );
$ok( isset( $url ) && false !== strpos( $url, 'sdsc_transfer=' ), 'redirects back to the record' );
// cleanup
$wpdb->delete( SDSC_Transfer::table(), array( 'reg_id' => $rid ) );
$wpdb->delete( $rt, array( 'id' => $rid ) );
$ok( null === $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $rt WHERE id = %d", $rid ) ), 'test data removed' );
echo "\n$n checks, $f failed\n";
