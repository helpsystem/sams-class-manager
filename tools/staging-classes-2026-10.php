<?php
/**
 * One-off (staging): C-68 / C-69 dates and times, the staff-only $1 payment-test class and the TEST5 code.
 * Run: wp eval-file tools/staging-classes-2026-10.php
 */
$desc = 'A 10-day Zoom course plus 6 hours of behind-the-wheel training. Held over two weeks, Monday through Friday.';
$set  = function ( $code, $title, $start, $end, $days, $price, $excerpt, $sched, $extra = array() ) {
	$id = SDSC_Registration::find_class_by_code( $code );
	if ( ! $id ) {
		$f  = get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sdsc_course_code', 'meta_value' => $code ) ); // phpcs:ignore
		$id = $f ? $f[0] : wp_insert_post( array( 'post_type' => 'sdsc_class', 'post_status' => 'publish', 'post_title' => $title ) );
	}
	wp_update_post( array( 'ID' => $id, 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_status' => 'publish' ) );
	$meta = array_merge( array( '_sdsc_course_code' => $code, '_sdsc_start' => $start, '_sdsc_end' => $end, '_sdsc_days_time' => $days, '_sdsc_price' => $price, '_sdsc_status' => 'upcoming' ), $extra );
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
	if ( $sched ) { update_post_meta( $id, '_sdsc_sched', $sched ); } else { delete_post_meta( $id, '_sdsc_sched' ); }
	return $id;
};
$weekdays = array( 'days' => array( 1, 2, 3, 4, 5 ), 'from' => '18:00', 'to' => '21:15', 'count' => 10, 'remind' => 1 );
$a = $set( 'C-68', "Driver's Education Program (PM Class) C-68", '2026-10-26T18:00', '2026-11-06T21:15', 'Mon–Fri, 6:00–9:15 PM', '378', $desc, $weekdays );
$b = $set( 'C-69', "Driver's Education Program (PM Class) C-69", '2026-11-09T18:00', '2026-11-20T21:15', 'Mon–Fri, 6:00–9:15 PM', '378', $desc, $weekdays );
$t = $set( 'TEST-1', 'Payment test — $1 (staff only) TEST-1', '2026-11-07T00:00', '2026-11-07T23:59', 'Sat, Nov 7 · payment test, no class meets', '1', 'Staff-only $1 class for testing real card payments. Not a real course.', null, array( '_sdsc_capacity' => '5', '_sdsc_hidden' => '1' ) );
$codes = get_option( 'sdsc_promo_codes', array() );
$codes = is_array( $codes ) ? $codes : array();
$codes['TEST5'] = array( 'type' => 'pct', 'value' => 5, 'classes' => array( $t ), 'starts' => '', 'ends' => '2026-11-07', 'max' => 3, 'active' => true, 'note' => '$1 real-payment test', 'created' => current_time( 'mysql' ), 'by' => 1, 'last_used' => '' );
update_option( 'sdsc_promo_codes', $codes, false );
foreach ( array( $a, $b, $t ) as $id ) {
	$s = SDSC_Schedule::sessions( $id );
	echo $id, ' ', get_the_title( $id ), ' | ', get_post_meta( $id, '_sdsc_start', true ), ' → ', get_post_meta( $id, '_sdsc_end', true ), ' | $', get_post_meta( $id, '_sdsc_price', true ), ' | sessions: ', count( $s ), ( $s ? ' (' . $s[0]['date'] . ' … ' . end( $s )['date'] . ')' : '' ), "\n";
}
echo 'public list: ', implode( ', ', wp_list_pluck( SDSC_Registration::open_classes(), 'post_title' ) ), "\n";
$c = SDSC_Promo::check( 'test5', $t );
echo 'TEST5 on TEST-1: ', $c[1], ' → $', number_format( $c[2] / 100, 2 ), "\n";
echo 'Direct link: ', add_query_arg( 'course', 'TEST-1', SDSC_Class_Feed::reg_url() ), "\n";
if ( class_exists( 'SDSC_Discounts' ) ) { SDSC_Discounts::purge(); }
