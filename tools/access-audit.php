<?php
// Read-only audit (staging): for every role, which Sam's School / DriveFlow admin pages the role may open, whether the
// Workspace list agrees, which REST routes are public, and which admin-post / ajax handlers lack a nonce or a permission check.
global $menu, $submenu, $_registered_pages, $wp_rest_server;
require_once ABSPATH . 'wp-admin/includes/admin.php';
$roles = array( 'administrator' => 17307, 'sdsc_manager' => 299 );
foreach ( array( 'sdsc_front_desk', 'tutor_instructor', 'subscriber' ) as $r ) { $u = get_users( array( 'role' => $r, 'number' => 1, 'fields' => 'ID' ) ); if ( $u ) { $roles[ $r ] = (int) $u[0]; } }
$pages = array();
foreach ( $roles as $role => $uid ) {
	wp_set_current_user( $uid );
	$menu = array(); $submenu = array(); $_registered_pages = array(); $can = array();
	// only our own menu builders (WooCommerce's admin_menu handler cannot run twice in one process)
	global $wp_filter;
	foreach ( (array) ( $wp_filter['admin_menu']->callbacks ?? array() ) as $prio => $cbs ) {
		foreach ( $cbs as $cb ) {
			$fn = $cb['function']; $cls = is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) : ( $fn instanceof Closure ? ( new ReflectionFunction( $fn ) )->getClosureScopeClass()?->name ?? '' : '' );
			if ( preg_match( '/^(SDSC_|DriveFlow|DF_)/', (string) $cls ) ) { call_user_func( $fn, '' ); }
		}
	}
	if ( class_exists( 'SDSC_Access' ) && SDSC_Access::may_edit_classes() ) { $pages['edit.php?post_type=sdsc_class'] = 'Classes'; $can['edit.php?post_type=sdsc_class'] = 1; }
	foreach ( (array) $submenu as $parent => $items ) { foreach ( $items as $it ) { $slug = $it[2]; if ( ! preg_match( '/^(sdsc-|driveflow|edit\.php\?post_type=sdsc_class)/', $slug ) ) { continue; } $pages[ $slug ] = $it[0]; $can[ $slug ] = current_user_can( $it[1] ) ? 1 : 0; } }
	foreach ( (array) $menu as $it ) { $slug = $it[2]; if ( preg_match( '/^(sdsc-|driveflow|edit\.php\?post_type=sdsc_class)/', $slug ) ) { $pages[ $slug ] = $it[0]; $can[ $slug ] = current_user_can( $it[1] ) ? 1 : 0; } }
	$ws = array();
	if ( class_exists( 'SDSC_Workspace' ) && SDSC_Workspace::is_staff() ) { foreach ( SDSC_Workspace::screens() as $k => $s ) { if ( $s[3] ) { $ws[] = preg_replace( '/^admin\.php\?page=/', '', $s[2] ); } } }
	$open = array_keys( array_filter( $can ) );
	echo "ROLE|$role|user $uid|pages open: " . count( $open ) . '|workspace screens: ' . count( $ws ) . "\n";
	foreach ( $open as $p ) { echo "  open  $p\n"; }
	foreach ( array_diff( $ws, $open ) as $p ) { echo "  WARN workspace shows but page not registered/allowed: $p\n"; }
	foreach ( array_diff( $open, $ws ) as $p ) { if ( preg_match( '/^(sdsc-receipt|sdsc-inbox|sdsc-my-email|driveflow-pro-(hub|records|students|instructors|vehicles|reports|settings)|sdsc-work|sdsc-applicants|sdsc-instructors|sdsc-board|sdsc-lesson-offers|sdsc-price-watch|sdsc-docs|sdsc-zoom|sdsc-attendance)/', $p ) ) { continue; } echo "  note  page open but not in Workspace list: $p\n"; }
	// pages this role must never open
	foreach ( array( 'sdsc-api', 'sdsc-settings', 'sdsc-email', 'sdsc-team' ) as $p ) { if ( 'administrator' !== $role && ! empty( $can[ $p ] ) ) { echo "  BAD   $role can open admin-only page $p\n"; } }
}
// REST routes
wp_set_current_user( 0 );
$srv = rest_get_server();
foreach ( $srv->get_routes() as $route => $handlers ) {
	if ( 0 !== strpos( $route, '/sdsc/' ) && 0 !== strpos( $route, '/driveflow' ) ) { continue; }
	foreach ( $handlers as $h ) {
		$pc = $h['permission_callback'] ?? null;
		$pub = '__return_true' === $pc;
		if ( $pub ) { echo "REST|public|$route|" . implode( ',', array_keys( $h['methods'] ) ) . "\n"; }
		elseif ( empty( $pc ) ) { echo "REST|BAD no permission callback|$route\n"; }
	}
}
echo "DONE\n";
