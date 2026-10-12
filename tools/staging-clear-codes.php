<?php
/** Staging: C-68 / C-69 were placeholders — clear the codes until the school gives the real ones. */
foreach ( array( 'C-68', 'C-69' ) as $code ) {
	$f = get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sdsc_course_code', 'meta_value' => $code ) ); // phpcs:ignore
	if ( ! $f ) { continue; }
	update_post_meta( $f[0], '_sdsc_course_code', '' );
	wp_update_post( array( 'ID' => $f[0], 'post_title' => "Driver's Education Program (PM Class)" ) );
}
foreach ( SDSC_Registration::open_classes() as $p ) {
	echo $p->ID, ' | ', html_entity_decode( get_the_title( $p ), ENT_QUOTES ), ' | code="', get_post_meta( $p->ID, '_sdsc_course_code', true ), '" | ', SDSC_Registration::class_label( $p->ID ), "\n";
}
foreach ( SDSC_Class_Feed::items() as $i ) { echo 'feed: ', $i['name'], ' → ', $i['url'], "\n"; }
if ( class_exists( 'SDSC_Discounts' ) ) { SDSC_Discounts::purge(); }
