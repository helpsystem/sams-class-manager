<?php
/**
 * One-off (wp eval-file): the school's four cars and Saghar as an instructor. Safe to run again.
 */
global $wpdb;
$now = current_time( 'mysql' );
$V   = $wpdb->prefix . 'driveflow_vehicles';
$I   = $wpdb->prefix . 'driveflow_instructors';
$out = array();
foreach ( array( '4HB1511', '9FF6904', '2GA9727', '6GG8203' ) as $plate ) {
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $V WHERE plate_number = %s", $plate ) ) ) { $out[] = $plate . ' already there'; continue; }
	$wpdb->insert( $V, array( 'plate_number' => $plate, 'model' => 'Nissan Versa', 'color' => 'Gray', 'transmission' => 'Automatic', 'status' => 'active', 'current_fuel_level' => 100, 'maintenance_status' => 'operational', 'created_at' => $now, 'updated_at' => $now ) );
	$out[] = $plate . ' added #' . $wpdb->insert_id;
}
$users = get_users( array( 'role' => 'sdsc_manager', 'search' => 'Saghar', 'search_columns' => array( 'display_name' ), 'number' => 5 ) );
$u     = null;
foreach ( $users as $x ) { if ( false === strpos( $x->user_email, '@preview.' ) ) { $u = $x; break; } }
if ( ! $u ) { $out[] = 'Saghar (school manager account) not found'; }
elseif ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $I WHERE user_id = %d OR LOWER(email) = %s", $u->ID, strtolower( $u->user_email ) ) ) ) { $out[] = 'Saghar is already an instructor'; }
else {
	$wpdb->insert( $I, array( 'user_id' => $u->ID, 'name' => $u->display_name, 'phone' => '', 'email' => $u->user_email, 'default_vehicle_id' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$out[] = 'Saghar added as instructor #' . $wpdb->insert_id;
}
echo implode( "\n", $out ), "\n";
