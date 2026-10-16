<?php
// Read-only: for every open class — price, payment link (site-made or by hand, active, amount), seats (capacity, taken),
// and whether the registration table and the registrant posts agree. Changes nothing.
global $wpdb;
$r = new ReflectionClass( 'SDSC_Prices' ); $m = $r->getMethod( 'find_link' ); $m->setAccessible( true );
$L = function ( $url ) use ( $m ) { return $m->invoke( null, $url ); };
$A = $r->getMethod( 'api' ); $A->setAccessible( true );
$rt = SDSC_Registration::table();
foreach ( SDSC_Registration::open_classes( true ) as $c ) {
	$id    = (int) $c->ID;
	$code  = get_post_meta( $id, '_sdsc_course_code', true );
	$start = substr( (string) get_post_meta( $id, '_sdsc_start', true ), 0, 10 );
	$price = (string) get_post_meta( $id, '_sdsc_price', true );
	$url   = (string) get_post_meta( $id, '_sdsc_payment_link', true );
	$cap   = (int) get_post_meta( $id, '_sdsc_capacity', true );
	$taken = SDSC_Board::taken( $id );
	$regs  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rt} WHERE class_id = %d", $id ) );
	$demo  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rt} WHERE class_id = %d AND source = 'demo'", $id ) );
	$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sdsc_class_id' AND pm.meta_value = %s AND p.post_type = 'sdsc_registrant' AND p.post_status <> 'trash'", (string) $id ) );
	$link  = $url ? $L( $url ) : null;
	$how   = ! $url ? 'NO LINK' : ( ! $link ? 'link not active in Stripe' : ( ! empty( $link['metadata']['sdsc_auto'] ) ? 'site-made (auto)' : 'made by hand' ) );
	$amt   = '';
	if ( $link ) {
		list( $cc, $li ) = $A->invoke( null, 'GET', 'payment_links/' . rawurlencode( $link['id'] ) . '/line_items', array( 'limit' => 1 ), '' );
		if ( 200 === $cc && ! empty( $li['data'][0] ) ) { $amt = number_format( $li['data'][0]['amount_total'] / 100, 2 ); }
	}
	$flag = array();
	if ( 30 !== $cap ) { $flag[] = "capacity $cap (not 30)"; }
	if ( $taken !== $regs - $demo && $taken !== $regs ) { $flag[] = "seat count $taken but registrations $regs" . ( $demo ? " ($demo demo)" : '' ) . ", registrant posts $posts"; }
	if ( $amt && $price && abs( (float) $amt - (float) $price ) > 0.009 ) { $flag[] = "link charges \$$amt but price is \$$price"; }
	echo "CLASS|$code|$start|\$$price|$how|" . ( $amt ? "\$$amt" : '-' ) . "|seats $taken/$cap|regs $regs|posts $posts|" . ( $flag ? 'CHECK: ' . implode( '; ', $flag ) : 'ok' ) . "\n";
}
