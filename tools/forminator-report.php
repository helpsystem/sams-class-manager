<?php
/**
 * Read-only: Forminator registration entries (form 526) since a date, one line each.
 * Run: SINCE=2026-09-15 wp eval-file tools/forminator-report.php
 */
global $wpdb;
$since = getenv( 'SINCE' ) ? getenv( 'SINCE' ) : '2026-09-15';
$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT entry_id, date_created FROM {$wpdb->prefix}frmt_form_entry WHERE form_id = 526 AND date_created >= %s ORDER BY entry_id", $since ) );
foreach ( $rows as $e ) {
	$m = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}frmt_form_entry_meta WHERE entry_id = %d AND meta_key IN ('email-1','name-1','select-1','number-1')", $e->entry_id ) ) as $x ) { $m[ $x->meta_key ] = maybe_unserialize( $x->meta_value ); }
	$n   = is_array( $m['name-1'] ?? null ) ? trim( ( $m['name-1']['first-name'] ?? '' ) . ' ' . ( $m['name-1']['last-name'] ?? '' ) ) : (string) ( $m['name-1'] ?? '' );
	$sel = wp_strip_all_tags( is_array( $m['select-1'] ?? null ) ? implode( ',', $m['select-1'] ) : (string) ( $m['select-1'] ?? '' ) );
	echo $e->entry_id . "\t" . substr( $e->date_created, 0, 16 ) . "\t" . strtolower( trim( (string) ( $m['email-1'] ?? '' ) ) ) . "\t" . $n . "\t" . ( $m['number-1'] ?? '' ) . "\t" . mb_substr( $sel, 0, 40 ) . "\n";
}
echo 'TOTAL ' . count( $rows ) . "\n";
