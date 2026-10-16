<?php
if ( 'test' !== SDSC_Api::mode() ) { return; }
$r = new ReflectionClass( 'SDSC_Prices' );
$api = $r->getMethod( 'api' ); $api->setAccessible( true ); $set = $r->getMethod( 'settings' ); $set->setAccessible( true ); $fl = $r->getMethod( 'find_link' ); $fl->setAccessible( true );
$A = function ( $m, $p, $b = array() ) use ( $api ) { return $api->invoke( null, $m, $p, $b, '' ); };
list( $c, $p ) = $A( 'POST', 'prices', array( 'unit_amount' => 700, 'currency' => 'usd', 'product_data' => array( 'name' => 'SDSC DEBUG - delete me' ) ) );
list( $c, $l ) = $A( 'POST', 'payment_links', array( 'line_items' => array( array( 'price' => $p['id'], 'quantity' => 1 ) ), 'allow_promotion_codes' => true ) );
echo "create $c url=" . ( $l['url'] ?? '' ) . "\n";
list( $c, $lst ) = $A( 'GET', 'payment_links', array( 'limit' => 5, 'active' => true ) );
echo "list $c n=" . count( $lst['data'] ?? array() ) . ' err=' . ( $lst['error']['message'] ?? '' ) . "\n";
foreach ( (array) ( $lst['data'] ?? array() ) as $x ) { echo ' - ' . $x['url'] . ' active=' . var_export( $x['active'], true ) . "\n"; }
$f = $fl->invoke( null, $l['url'] ); echo 'find_link: ' . ( $f ? 'found' : 'NOT found' ) . "\n";
$s = $set->invoke( null, $l ); echo 'settings: ' . wp_json_encode( $s ) . "\n";
list( $c, $n ) = $A( 'POST', 'payment_links', array_merge( $s, array( 'line_items' => array( array( 'price' => $p['id'], 'quantity' => 1 ) ) ) ) );
echo "copy $c " . ( $n['error']['message'] ?? 'ok' ) . ' param=' . ( $n['error']['param'] ?? '' ) . "\n";
foreach ( array( $l['id'] ?? '', $n['id'] ?? '' ) as $id ) { if ( $id ) { $A( 'POST', 'payment_links/' . $id, array( 'active' => false ) ); } }
$A( 'POST', 'products/' . $p['product'], array( 'active' => false ) );
echo "cleaned\n";
