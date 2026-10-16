<?php
// Production: switch off the $5 live test link (owner asked). Deactivates the link, archives its product, forgets the product id. Nothing is deleted.
$r = new ReflectionClass( 'SDSC_Prices' ); $m = $r->getMethod( 'api' ); $m->setAccessible( true );
$A = function ( $method, $path, $b = array() ) use ( $m ) { return $m->invoke( null, $method, $path, $b, '' ); };
$link = 'plink_1URDciGCVsYfANJEKOtybzat';
list( $c, $l ) = $A( 'POST', 'payment_links/' . $link, array( 'active' => 'false' ) );
echo 'link: ' . $c . ' active=' . ( isset( $l['active'] ) ? var_export( $l['active'], true ) : '?' ) . ' ' . ( $l['error']['message'] ?? '' ) . "\n";
$known = get_option( SDSC_Prices::PRODUCTS, array() );
$prod  = '';
foreach ( (array) $known as $mode => $keys ) { if ( isset( $keys['sdsc_live_test_5'] ) ) { $prod = $keys['sdsc_live_test_5']; unset( $known[ $mode ]['sdsc_live_test_5'] ); } }
if ( $prod ) {
	list( $c2, $p ) = $A( 'POST', 'products/' . $prod, array( 'active' => 'false' ) );
	echo 'product archived: ' . $c2 . ' active=' . ( isset( $p['active'] ) ? var_export( $p['active'], true ) : '?' ) . "\n";
	update_option( SDSC_Prices::PRODUCTS, $known, false );
	echo "product id removed from the site's list\n";
} else { echo "no product entry found\n"; }
list( $c3, $l3 ) = $A( 'GET', 'payment_links/' . $link );
echo 'check: link active=' . var_export( $l3['active'] ?? null, true ) . ' url=' . ( $l3['url'] ?? '' ) . "\n";
