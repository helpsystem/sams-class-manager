<?php
// Staging only (Stripe test mode): automatic payment links on a price change. Switches off / archives what it makes.
if ( 'test' !== SDSC_Api::mode() ) { echo "Not in test mode - stopping.\n"; return; }
$ok = 0; $bad = 0; $t = function ( $c, $m ) use ( &$ok, &$bad ) { if ( $c ) { $ok++; echo "ok  $m\n"; } else { $bad++; echo "BAD $m\n"; } };
$r = new ReflectionClass( 'SDSC_Prices' ); $api = $r->getMethod( 'api' ); $api->setAccessible( true );
$A = function ( $m, $p, $b = array() ) use ( $api ) { return $api->invoke( null, $m, $p, $b, '' ); };
$made_links = array(); $made_products = array();
$t( SDSC_Prices::auto_ready(), 'auto_ready on staging in test mode' );
$t( 1234 === SDSC_Prices::cents( '$12.34' ) && 120000 === SDSC_Prices::cents( '$1,200' ) && 0 === SDSC_Prices::cents( 'from $120' ) && 0 === SDSC_Prices::cents( 'Call for dates' ), 'cents()' );
$key = 'sdsctest_' . wp_generate_password( 5, false, false );

// 1. no link yet -> product + price + link
list( $o1, $u1, $n1 ) = SDSC_Prices::make_link( $key, 'SDSC TEST - delete me', '$12.34', '' );
$t( $o1 && 0 === strpos( $u1, 'https://buy.stripe.com/test_' ), "new link: $u1 ($n1)" );

// 2. a hand-made link with settings -> copy them
list( $c, $p ) = $A( 'POST', 'prices', array( 'unit_amount' => 5000, 'currency' => 'usd', 'product_data' => array( 'name' => 'SDSC TEST hand-made - delete me' ) ) );
$made_products[] = $p['product'] ?? '';
list( $c, $hand ) = $A( 'POST', 'payment_links', array(
	'line_items' => array( array( 'price' => $p['id'], 'quantity' => 1 ) ),
	'allow_promotion_codes' => true, 'phone_number_collection' => array( 'enabled' => true ),
	'after_completion' => array( 'type' => 'redirect', 'redirect' => array( 'url' => 'https://testsaman.samsdrivingschool.org/thank-you/' ) ),
	'custom_fields' => array( array( 'key' => 'student', 'type' => 'text', 'label' => array( 'type' => 'custom', 'custom' => 'Student name' ) ), array( 'key' => 'branch', 'type' => 'dropdown', 'optional' => true, 'label' => array( 'type' => 'custom', 'custom' => 'Office' ), 'dropdown' => array( 'options' => array( array( 'label' => 'Silver Spring', 'value' => 'ss' ), array( 'label' => 'Rockville', 'value' => 'rv' ) ) ) ) ),
	'custom_text' => array( 'submit' => array( 'message' => 'We will call you to book.' ) ),
	'billing_address_collection' => 'required', 'metadata' => array( 'note' => 'hand' ),
) );
$t( 200 === $c && ! empty( $hand['url'] ), 'hand-made test link created' );
$made_links[] = $hand['id'] ?? '';
list( $o2, $u2, $n2 ) = SDSC_Prices::make_link( $key . 'h', 'SDSC TEST hand', '$55', $hand['url'] );
$t( $o2 && $u2 !== $hand['url'], "replacement link: $n2" );
list( $c, $all ) = $A( 'GET', 'payment_links', array( 'limit' => 20 ) );
$new = null; foreach ( (array) ( $all['data'] ?? array() ) as $l ) { if ( $l['url'] === $u2 ) { $new = $l; } if ( in_array( $l['url'], array( $u1, $u2 ), true ) ) { $made_links[] = $l['id']; } }
$t( $new && ! empty( $new['allow_promotion_codes'] ) && ! empty( $new['phone_number_collection']['enabled'] ) && 'required' === $new['billing_address_collection'], 'copied: promo codes, phone, billing address' );
$t( $new && 'redirect' === $new['after_completion']['type'] && false !== strpos( $new['after_completion']['redirect']['url'], 'thank-you' ), 'copied: thank-you redirect' );
$t( $new && 2 === count( $new['custom_fields'] ) && 'Office' === $new['custom_fields'][1]['label']['custom'] && 2 === count( $new['custom_fields'][1]['dropdown']['options'] ), 'copied: custom questions incl. dropdown' );
$t( $new && 'We will call you to book.' === ( $new['custom_text']['submit']['message'] ?? '' ), 'copied: submit text' );
$t( $new && 'hand' === ( $new['metadata']['note'] ?? '' ) && '1' === ( $new['metadata']['sdsc_auto'] ?? '' ), 'metadata kept + marked as made by the site' );
list( $c, $li ) = $A( 'GET', 'payment_links/' . ( $new['id'] ?? 'x' ) . '/line_items' );
$t( 5500 === (int) ( $li['data'][0]['price']['unit_amount'] ?? 0 ) && ( $li['data'][0]['price']['product'] ?? '' ) === $made_products[0], 'charges $55 on the same product' );
list( $c, $h2 ) = $A( 'GET', 'payment_links/' . $hand['id'] );
$t( ! empty( $h2['active'] ) && false !== strpos( $n2, 'made by hand' ), 'hand-made link left active' );

// 3. replace the site's own link -> the old one is switched off
list( $o3, $u3, $n3 ) = SDSC_Prices::make_link( $key, 'SDSC TEST - delete me', '$15', $u1 );
$t( $o3 && false !== strpos( $n3, 'switched off' ), "site link replaced: $n3" );
list( $c, $all ) = $A( 'GET', 'payment_links', array( 'limit' => 20 ) );
foreach ( (array) ( $all['data'] ?? array() ) as $l ) { if ( $l['url'] === $u1 ) { $t( empty( $l['active'] ), 'previous site link is off' ); } if ( $l['url'] === $u3 ) { $made_links[] = $l['id']; } }
// 4. text price -> no link
list( $o4 ) = SDSC_Prices::make_link( $key, 'x', 'Call for dates', '' ); $t( ! $o4, 'text price makes no link' );

// clean up: links off, products archived, option entries removed
$known = get_option( SDSC_Prices::PRODUCTS, array() );
foreach ( array( $key, $key . 'h' ) as $k ) { if ( ! empty( $known['test'][ $k ] ) ) { $made_products[] = $known['test'][ $k ]; unset( $known['test'][ $k ] ); } }
update_option( SDSC_Prices::PRODUCTS, $known, false );
foreach ( array_unique( array_filter( $made_links ) ) as $id ) { $A( 'POST', 'payment_links/' . $id, array( 'active' => false ) ); }
foreach ( array_unique( array_filter( $made_products ) ) as $id ) { $A( 'POST', 'products/' . $id, array( 'active' => false ) ); }
list( $c, $all ) = $A( 'GET', 'payment_links', array( 'limit' => 50, 'active' => true ) ); $left = 0;
foreach ( (array) ( $all['data'] ?? array() ) as $l ) { if ( false !== strpos( (string) ( $l['metadata']['sdsc_key'] ?? '' ), 'sdsctest_' ) || 'hand' === ( $l['metadata']['note'] ?? '' ) ) { $left++; } }
$t( 0 === $left, 'clean-up: no test links left active' );
echo "PASS $ok FAIL $bad\n";
