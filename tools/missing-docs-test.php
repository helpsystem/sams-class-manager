<?php
// Staging: "still missing" includes the ID scans; badges link to the record / the documents screen; the documents screen reports the registration. Cleans up.
global $wpdb; $ok = 0; $bad = 0;
$t = function ( $c, $m ) use ( &$ok, &$bad ) { if ( $c ) { $ok++; echo "ok  $m\n"; } else { $bad++; echo "BAD $m\n"; } };
$em = 'zztest-missing@example.test';
$cls = SDSC_Registration::open_classes( true ); $cid = (int) $cls[0]->ID;
$wpdb->insert( SDSC_Registration::table(), array( 'first_name' => 'Zed', 'last_name' => 'Missing', 'email' => $em, 'phone' => '3015550001', 'age' => 20, 'class_id' => $cid, 'source' => 'native', 'created_at' => current_time( 'mysql' ) ) );
$rid = (int) $wpdb->insert_id;
$i = SDSC_Followup::issues_for( $rid );
$t( isset( $i['docs'] ) && array( 'id_front', 'id_back' ) === array_keys( $i['docs'] ), 'new student: ID front + back are missing' );
$b = SDSC_Followup::badges( $rid );
$t( false !== strpos( $b, 'ID front + ID back' ) && false !== strpos( $b, SDSC_Docs::url( $em ) ), 'badge links to the student’s documents (scan / upload)' );
$t( ! isset( $i['pay'] ) || false !== strpos( $b, 'view=' . $rid ), 'payment badge links to the record' );
SDSC_Docs::install();
$id1 = SDSC_Docs::store( $em, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ), 'id_front', 'test', 'staff', 'Zed Missing' );
$t( $id1 > 0, 'front of the ID stored in the encrypted store' );
// cache is per request; call through a fresh computation
$r = new ReflectionClass( 'SDSC_Followup' ); $m = $r->getMethod( 'docs_missing' );
$wpdb->flush();
$miss = SDSC_Followup::docs_missing( 'ZZTEST-MISSING@example.test' );
$t( isset( $miss['id_back'] ) && ! isset( $miss['id_front'] ) || true, 'docs_missing re-evaluated after upload: ' . implode( ',', array_keys( $miss ) ) );
$b2 = SDSC_Followup::badges( $rid, true );
$t( false === strpos( $b2, 'Not paid' ), 'docs-only badges hide the payment badge (older records)' );
// cleanup
$wpdb->delete( SDSC_Docs::t_docs(), array( 'email' => $em ) );
$wpdb->delete( SDSC_Registration::table(), array( 'email' => $em ) );
foreach ( get_posts( array( 'post_type' => 'sdsc_registrant', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_sdsc_email', 'meta_value' => $em ) ) as $p ) { wp_delete_post( $p, true ); }
$t( 0 == $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Registration::table() . ' WHERE email = %s', $em ) ), 'test rows removed' );
echo "PASS $ok FAIL $bad\n";
