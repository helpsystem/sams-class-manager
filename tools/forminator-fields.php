<?php
/** Read-only: field names of one Forminator entry; values shown only for choice-type fields. Run: ENTRY=761 wp eval-file tools/forminator-fields.php */
global $wpdb;
$id = (int) getenv( 'ENTRY' );
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}frmt_form_entry_meta WHERE entry_id = %d ORDER BY meta_id", $id ) ) as $m ) {
	$k = $m->meta_key;
	$v = maybe_unserialize( $m->meta_value );
	$show = preg_match( '/^(select|radio|checkbox|hidden|text)-/', $k ) && ! preg_match( '/@/', is_array( $v ) ? implode( ' ', $v ) : (string) $v );
	echo str_pad( $k, 22 ) . ( $show ? mb_substr( wp_strip_all_tags( is_array( $v ) ? implode( ', ', $v ) : (string) $v ), 0, 90 ) : '(hidden)' ) . "\n";
}
