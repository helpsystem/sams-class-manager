<?php
// Read-only check of the live site's Stripe links. Creates and changes nothing in Stripe.
$r = new ReflectionClass( 'SDSC_Prices' ); $api = $r->getMethod( 'api' ); $api->setAccessible( true );
$A = function ( $m, $p, $b = array() ) use ( $api ) { return $api->invoke( null, $m, $p, $b, '' ); };
echo 'mode=' . SDSC_Api::mode() . ' auto_ready=' . ( SDSC_Prices::auto_ready() ? 'yes' : 'no' ) . ' online-class-checkout=' . ( class_exists( 'SDSC_Pay' ) && SDSC_Pay::ready() ? 'yes' : 'no' ) . "\n";
// what the site shows
$items = array();
if ( class_exists( 'SDSC_Services' ) ) { foreach ( SDSC_Services::services() as $s ) { if ( ! empty( $s['url'] ) ) { $items[] = array( 'Service: ' . wp_strip_all_tags( $s['title'] ), wp_strip_all_tags( html_entity_decode( (string) $s['price'] ) ), $s['url'] ); } } }
foreach ( (array) get_option( 'sdsc_prices_seen', array() ) as $k => $s ) { $e = SDSC_Prices::entry( $k ); $items[] = array( $s['label'], '' !== $e['price'] ? $e['price'] : $s['price'], '' !== $e['url'] ? $e['url'] : $s['url'] ); }
$q = new WP_Query( array( 'post_type' => 'sdsc_class', 'post_status' => 'publish', 'posts_per_page' => 40, 'meta_query' => array( array( 'key' => '_sdsc_status', 'value' => array( 'completed', 'cancelled' ), 'compare' => 'NOT IN' ) ) ) );
foreach ( $q->posts as $p ) { $u = (string) get_post_meta( $p->ID, '_sdsc_payment_link', true ); $items[] = array( 'Class ' . get_post_meta( $p->ID, '_sdsc_course_code', true ), '$' . get_post_meta( $p->ID, '_sdsc_price', true ), $u ? $u : '(none - site checkout)' ); }
// all live links
$links = array(); $after = '';
for ( $i = 0; $i < 10; $i++ ) { list( $c, $j ) = $A( 'GET', 'payment_links', array_filter( array( 'limit' => 100, 'starting_after' => $after ) ) ); if ( 200 !== $c ) { echo "list error $c " . ( $j['error']['message'] ?? '' ) . "\n"; break; } foreach ( $j['data'] as $l ) { $links[ $l['url'] ] = $l; } if ( empty( $j['has_more'] ) ) { break; } $after = end( $j['data'] )['id']; }
echo 'links in Stripe: ' . count( $links ) . ' (made by the site: ' . count( array_filter( $links, function ( $l ) { return '1' === ( $l['metadata']['sdsc_auto'] ?? '' ); } ) ) . ")\n";
$bad = 0;
foreach ( $items as $it ) {
	list( $name, $price, $url ) = $it;
	if ( 0 !== strpos( $url, 'https://buy.stripe.com/' ) ) { echo "-    $name | $price | $url\n"; continue; }
	$l = $links[ $url ] ?? null;
	if ( ! $l ) { echo "BAD  $name | $price | link not in this Stripe account\n"; $bad++; continue; }
	list( $c, $li ) = $A( 'GET', 'payment_links/' . $l['id'] . '/line_items', array( 'limit' => 5 ) );
	$amt = 0; foreach ( (array) ( $li['data'] ?? array() ) as $x ) { $amt += (int) $x['amount_total']; }
	$show = SDSC_Prices::cents( str_replace( 'from ', '', $price ) );
	$ok = $l['active'] && ( ! $show || $show === $amt );
	if ( ! $ok ) { $bad++; }
	echo ( $ok ? 'ok   ' : 'BAD  ' ) . "$name | site $price | Stripe $" . number_format( $amt / 100, 2 ) . ' | ' . ( $l['active'] ? 'active' : 'OFF' ) . ' | ' . ( '1' === ( $l['metadata']['sdsc_auto'] ?? '' ) ? 'made by site' : 'made by hand' ) . "\n";
}
// permissions: empty requests are refused for missing fields (400) when allowed, or for permission (403) when not. Nothing is created.
foreach ( array( 'products', 'prices', 'payment_links', 'checkout/sessions' ) as $ep ) { list( $c, $j ) = $A( 'POST', $ep, array( 'metadata' => array( 'sdsc_probe' => '1' ) ) ); echo "permission $ep: " . ( 403 === $c || 'permission_error' === ( $j['error']['type'] ?? '' ) ? 'NOT ALLOWED' : ( 400 === $c ? 'allowed' : "code $c" ) ) . ' (' . mb_substr( (string) ( $j['error']['message'] ?? '' ), 0, 90 ) . ")\n"; }
// recent real purchases (last 14 days) through Checkout
list( $c, $s ) = $A( 'GET', 'checkout/sessions', array( 'limit' => 20, 'status' => 'complete', 'created' => array( 'gte' => time() - 14 * DAY_IN_SECONDS ) ) );
echo 'completed checkouts, last 14 days: ' . ( 200 === $c ? count( $s['data'] ) : "error $c" ) . "\n";
echo "problems: $bad\n";
