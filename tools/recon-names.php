<?php
// Read-only: match 2026 Stripe course payments with the website's registrations by e-mail, phone and name.
// Creates and changes nothing. Output lines: PAY|... (payments not matched), REG|... (registrations with no payment found), SUM|...
global $wpdb;
$r = new ReflectionClass( 'SDSC_Prices' ); $m = $r->getMethod( 'api' ); $m->setAccessible( true );
$A = function ( $method, $path, $b = array() ) use ( $m ) { return $m->invoke( null, $method, $path, $b, '' ); };
$since = strtotime( '2026-01-01 00:00:00 America/New_York' );

$norm  = function ( $s ) { $s = strtolower( remove_accents( (string) $s ) ); $s = preg_replace( '/[^a-z ]+/', ' ', $s ); return trim( preg_replace( '/\s+/', ' ', $s ) ); };
$phone = function ( $p ) { $d = preg_replace( '/\D+/', '', (string) $p ); return strlen( $d ) >= 10 ? substr( $d, -10 ) : ''; };
$tok   = function ( $s ) use ( $norm ) { return array_values( array_filter( explode( ' ', $norm( $s ) ), function ( $t ) { return strlen( $t ) > 1; } ) ); };

// payment links -> item name
$links = array(); $after = '';
for ( $i = 0; $i < 10; $i++ ) {
	list( $c, $j ) = $A( 'GET', 'payment_links', array_filter( array( 'limit' => 100, 'starting_after' => $after, 'expand' => array( 'data.line_items' ) ) ) );
	if ( 200 !== $c ) { break; }
	foreach ( $j['data'] as $l ) { $n = array(); foreach ( (array) ( $l['line_items']['data'] ?? array() ) as $li ) { $n[] = $li['description']; } $links[ $l['id'] ] = implode( ' + ', $n ); }
	if ( empty( $j['has_more'] ) ) { break; }
	$after = end( $j['data'] )['id'];
}
$is_course = function ( $item ) { $t = strtolower( $item ); return ! preg_match( '/roadway|alcohol|drug/', $t ) && preg_match( '/36 ?h|gls|ed course|driver.?s? ed|virtual class/', $t ); };

// 2026 completed checkouts
$sess = array(); $after = '';
for ( $i = 0; $i < 40; $i++ ) {
	list( $c, $j ) = $A( 'GET', 'checkout/sessions', array_filter( array( 'limit' => 100, 'status' => 'complete', 'created' => array( 'gte' => $since ), 'starting_after' => $after ) ) );
	if ( 200 !== $c ) { echo "SUM|error|$c\n"; break; }
	foreach ( $j['data'] as $s ) { $sess[] = $s; }
	if ( empty( $j['has_more'] ) ) { break; }
	$after = end( $j['data'] )['id'];
}

// registrations from 2026
$rt   = SDSC_Registration::table();
$regs = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, source, class_id, first_name, last_name, LOWER(email) e, phone, parent_name, LOWER(parent_email) pe, parent_phone FROM {$rt} WHERE created_at >= %s", '2026-01-01' ), ARRAY_A );
$byE = $byP = $byN = $byL = array();
foreach ( $regs as $g ) {
	foreach ( array( $g['e'], $g['pe'] ) as $e ) { if ( $e ) { $byE[ $e ][] = $g['id']; } }
	foreach ( array( $g['phone'], $g['parent_phone'] ) as $p ) { $p = $phone( $p ); if ( $p ) { $byP[ $p ][] = $g['id']; } }
	$full = $norm( $g['first_name'] . ' ' . $g['last_name'] );
	if ( $full ) { $byN[ $full ][] = $g['id']; }
	$ln = $tok( $g['last_name'] ); $fn = $tok( $g['first_name'] );
	if ( $ln && $fn ) { $byL[ end( $ln ) ][] = array( $g['id'], $fn[0] ); }
	if ( $g['parent_name'] ) { $pn = $norm( $g['parent_name'] ); if ( $pn ) { $byN[ $pn ][] = $g['id']; } }
}
$hit = array(); $how = array( 'email' => 0, 'phone' => 0, 'name' => 0, 'name (last + first)' => 0, 'site checkout' => 0 ); $no = array(); $n = 0;
foreach ( $sess as $s ) {
	$item = ! empty( $s['metadata']['sdsc_reg'] ) ? 'site checkout' : ( $links[ $s['payment_link'] ?? '' ] ?? '' );
	if ( 'site checkout' !== $item && ! $is_course( $item ) ) { continue; }
	$n++;
	$cf = array(); foreach ( (array) ( $s['custom_fields'] ?? array() ) as $f ) { $cf[ $f['key'] ] = $f['text']['value'] ?? ( $f['numeric']['value'] ?? ( $f['dropdown']['value'] ?? '' ) ); }
	$stud = $cf['studentname'] ?? $cf['studentsfullname'] ?? $cf['studentsname'] ?? '';
	$sph  = $phone( $cf['studentsphonenumber'] ?? '' );
	$em   = strtolower( (string) ( $s['customer_details']['email'] ?? '' ) );
	$pay  = (string) ( $s['customer_details']['name'] ?? '' );
	$pph  = $phone( $s['customer_details']['phone'] ?? '' );
	$found = null; $via = '';
	if ( 'site checkout' === $item ) { $found = array( (int) $s['metadata']['sdsc_reg'] ); $via = 'site checkout'; }
	if ( ! $found && $em && isset( $byE[ $em ] ) ) { $found = $byE[ $em ]; $via = 'email'; }
	if ( ! $found ) { foreach ( array( $sph, $pph ) as $p ) { if ( $p && isset( $byP[ $p ] ) ) { $found = $byP[ $p ]; $via = 'phone'; break; } } }
	if ( ! $found ) { foreach ( array( $stud, $pay ) as $nm ) { $k = $norm( $nm ); if ( $k && isset( $byN[ $k ] ) ) { $found = $byN[ $k ]; $via = 'name'; break; } } }
	if ( ! $found ) {
		foreach ( array( $stud, $pay ) as $nm ) {
			$t = $tok( $nm ); if ( count( $t ) < 2 ) { continue; }
			foreach ( $t as $last ) { foreach ( (array) ( $byL[ $last ] ?? array() ) as $x ) { if ( in_array( $x[1], $t, true ) ) { $found = array( $x[0] ); $via = 'name (last + first)'; break 3; } } }
		}
	}
	if ( $found ) { $how[ $via ]++; foreach ( $found as $id ) { $hit[ $id ] = 1; } continue; }
	$no[] = wp_date( 'Y-m-d', $s['created'] ) . '|' . number_format( $s['amount_total'] / 100, 2 ) . '|' . substr( preg_replace( '/\s+/', ' ', $item ), 0, 70 ) . '|' . trim( $stud ) . '|' . trim( $pay ) . '|' . ( $sph ? substr( $sph, -4 ) : '' ) . '|' . $s['payment_intent'];
}
// registrations with no payment found (cash / check recorded on the board also counts)
$off = array(); foreach ( $wpdb->get_results( 'SELECT reg_id, LOWER(email) e FROM ' . SDSC_Board::table() . " WHERE status='paid'", ARRAY_A ) as $x ) { $off[ 'r' . $x['reg_id'] ] = 1; $off[ $x['e'] ] = 1; }
$unpaid = array();
foreach ( $regs as $g ) {
	if ( isset( $hit[ (int) $g['id'] ] ) || isset( $off[ 'r' . $g['id'] ] ) || isset( $off[ $g['e'] ] ) || 'demo' === $g['source'] ) { continue; }
	$unpaid[] = $g['id'] . '|' . substr( $g['created_at'], 0, 10 ) . '|' . $g['source'] . '|' . trim( $g['first_name'] . ' ' . $g['last_name'] ) . '|' . trim( (string) $g['parent_name'] ) . '|' . ( $phone( $g['phone'] ) ? substr( $phone( $g['phone'] ), -4 ) : '' );
}
echo 'SUM|course payments 2026|' . $n . '|registrations 2026|' . count( $regs ) . "\n";
foreach ( $how as $k => $v ) { echo "SUM|matched by $k|$v\n"; }
echo 'SUM|payments not matched|' . count( $no ) . "\n";
echo 'SUM|registrations with no payment found|' . count( $unpaid ) . "\n";
foreach ( $no as $x ) { echo "PAY|$x\n"; }
foreach ( $unpaid as $x ) { echo "REG|$x\n"; }
