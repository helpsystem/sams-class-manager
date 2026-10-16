<?php
/** Transfer module test on the test site: creates a registration, records in/out, renders the panel and the record, sends the e-mail to a test address, then removes everything. */
if ( ! class_exists( 'SDSC_Transfer' ) ) { echo "SDSC_Transfer missing\n"; return; }
global $wpdb;
$n = 0; $f = 0;
$ok = function ( $c, $m ) use ( &$n, &$f ) { $n++; if ( ! $c ) { $f++; } echo ( $c ? 'ok   ' : 'FAIL ' ) . $m . "\n"; };
$adm = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $adm[0] ?? 0 ) );
SDSC_Transfer::install();
foreach ( (array) $wpdb->get_col( "SELECT id FROM " . SDSC_Registration::table() . " WHERE email = 'trtest@example.test'" ) as $old ) { $wpdb->delete( SDSC_Transfer::table(), array( 'reg_id' => $old ) ); $wpdb->delete( SDSC_Registration::table(), array( 'id' => $old ) ); }
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
$ok( 'Transfer in · 12 h class' === SDSC_Transfer::tag( $rid ), 'tag: ' . SDSC_Transfer::tag( $rid ) );
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
// extended transfer-in: purpose, BTW, MVA; covered sessions; classroom flag; docs needed
SDSC_Transfer::save( $rid, 'in', array( 'school' => 'Other School', 'hours' => 6, 'btw_hours' => 2, 'btw_lessons' => 1, 'units' => '1-2', 'final_pass' => 0, 'mva' => 'permit', 'mva_no' => 'X123', 'purpose' => 'both', 'on_date' => '2026-10-01' ) );
$in = SDSC_Transfer::get( $rid, 'in' );
$ok( 2.0 == $in['btw_hours'] && 'permit' === $in['mva'] && 'both' === $in['purpose'] && '1-2' === $in['units'], 'extended fields saved' );
$ok( false !== strpos( SDSC_Transfer::tag( $rid ), '6 h class · 2 h BTW' ), 'tag: ' . SDSC_Transfer::tag( $rid ) );
$cov = SDSC_Transfer::covered( $cid, 'trtest@example.test' );
$sess = $cid ? SDSC_Schedule::sessions( $cid ) : array();
$ok( ( $sess ? 2 === count( $cov ) && $cov[0] === $sess[0]['date'] : array() === $cov ), 'covered sessions: ' . count( $cov ) . ( $sess ? ' of ' . count( $sess ) . ' (6 h = 2 sessions)' : ' (class has no timetable)' ) );
$ok( SDSC_Transfer::classroom( $cid, 'trtest@example.test' ), 'classroom student (both)' );
$ok( 'permit' === SDSC_Transfer::mva_for( 'trtest@example.test' ), 'MVA status by e-mail' );
if ( class_exists( 'SDSC_Followup' ) ) { $ok( isset( SDSC_Followup::docs_missing( 'trtest@example.test' )['permit'] ), 'follow-up asks for the permit scan' ); }
if ( $sess ) { list( $abs ) = SDSC_Schedule::absences( $cid, 'trtest@example.test' ); $ok( 0 === $abs, 'no absences counted for covered sessions' ); }
$ok( $cid ? isset( SDSC_ZoomAuto::recipients( $cid )['trtest@example.test'] ) : true, 'gets the Zoom e-mail' );
SDSC_Transfer::save( $rid, 'in', array( 'school' => 'Other School', 'hours' => 30, 'purpose' => 'btw', 'mva' => 'license' ) );
$ok( ! SDSC_Transfer::classroom( $cid, 'trtest@example.test' ), 'behind-the-wheel-only is not a classroom student' );
$ok( $cid ? ! isset( SDSC_ZoomAuto::recipients( $cid )['trtest@example.test'] ) : true, 'behind-the-wheel-only gets no Zoom e-mail' );
$ok( $cid ? ! isset( SDSC_Schedule::roster( $cid )['trtest@example.test'] ) : true, 'behind-the-wheel-only is not on the attendance roster' );
$ok( 'license' === SDSC_Transfer::mva_for( 'trtest@example.test' ), 'license holder recorded (follow-up asks for the license scan on the next page load)' );
$rec = SDSC_Transfer::record_html( SDSC_Student_File::from_regs( array( $rid ) )[0] );
$ok( false !== strpos( $rec, 'Behind-the-wheel at that school' ) && false !== strpos( $rec, 'Full driver’s license' ) && false !== strpos( $rec, 'Behind the wheel only' ), 'record shows BTW, MVA status and purpose' );
SDSC_Transfer::save( $rid, 'in', array( 'school' => 'Other School', 'hours' => 12, 'on_date' => '2026-10-01' ) );
// e-mail (no attachments: no docs on file) — the test site never e-mails the office; wp_mail goes to the guard
$_POST = array( 'r' => $rid, '_wpnonce' => wp_create_nonce( 'sdsc_transfer_' . $rid ), 'to' => array( 'email', 'parent_email' ), 'docs' => array(), 'msg' => 'Test message' );
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
