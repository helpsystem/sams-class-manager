<?php
// Read-only reconciliation data for the accountant report. Prints totals and IDs only (emails are masked).
// Run: wp eval-file recon-data.php   — creates and changes nothing.
global $wpdb;
$r = new ReflectionClass( 'SDSC_Prices' ); $m = $r->getMethod( 'api' ); $m->setAccessible( true );
$A = function ( $method, $path, $b = array() ) use ( $m ) { return $m->invoke( null, $method, $path, $b, '' ); };
$mask = function ( $e ) { $e = strtolower( trim( (string) $e ) ); if ( ! strpos( $e, '@' ) ) { return $e ? '?' : ''; } list( $u, $d ) = explode( '@', $e, 2 ); return substr( $u, 0, 2 ) . '***@' . $d; };
$sec = isset( $GLOBALS['RECON'] ) ? $GLOBALS['RECON'] : ( getenv( 'RECON' ) ?: 'all' );

// ---------- 1. payment links -> service name
$links = array(); $after = '';
for ( $i = 0; $i < 10; $i++ ) {
	list( $c, $j ) = $A( 'GET', 'payment_links', array_filter( array( 'limit' => 100, 'starting_after' => $after, 'expand' => array( 'data.line_items' ) ) ) );
	if ( 200 !== $c ) { echo "LINKS ERROR $c\n"; break; }
	foreach ( $j['data'] as $l ) {
		$names = array(); $amt = 0;
		foreach ( (array) ( $l['line_items']['data'] ?? array() ) as $li ) { $names[] = $li['description']; $amt += (int) $li['amount_total']; }
		$links[ $l['id'] ] = array( implode( ' + ', $names ), $amt, $l['active'] ? 1 : 0 );
	}
	if ( empty( $j['has_more'] ) ) { break; }
	$after = end( $j['data'] )['id'];
}
if ( 'all' === $sec || 'links' === $sec ) { foreach ( $links as $id => $x ) { echo "L|$id|{$x[2]}|{$x[1]}|{$x[0]}\n"; } }

// ---------- 2. monthly money handled on the site
if ( 'all' === $sec || 'site' === $sec ) {
	$pt = $wpdb->prefix . 'driveflow_instructor_payouts';
	foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, COUNT(*) n, SUM(amount) s, SUM(hours_paid) h FROM {$pt} WHERE status IN ('paid','archived') GROUP BY ym ORDER BY ym", ARRAY_A ) as $x ) { echo "P|{$x['ym']}|{$x['n']}|{$x['s']}|{$x['h']}\n"; }
	foreach ( $wpdb->get_results( 'SELECT DATE_FORMAT(paid_on,\'%Y-%m\') ym, method, COUNT(*) n, SUM(amount)/100 s FROM ' . SDSC_Board::table() . " WHERE status='paid' GROUP BY ym, method ORDER BY ym", ARRAY_A ) as $x ) { echo "O|{$x['ym']}|{$x['method']}|{$x['n']}|{$x['s']}\n"; }
	foreach ( $wpdb->get_results( 'SELECT DATE_FORMAT(paid_at,\'%Y-%m\') ym, COUNT(*) n, SUM(amount) s FROM ' . SDSC_Lessons::t_fees() . " WHERE status='paid' AND paid_how NOT LIKE 'Online%' GROUP BY ym ORDER BY ym", ARRAY_A ) as $x ) { echo "F|{$x['ym']}|{$x['n']}|{$x['s']}\n"; }
	$st = $wpdb->prefix . 'driveflow_sessions';
	foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(scheduled_start,'%Y-%m') ym, COUNT(*) n, SUM(CASE WHEN TIMESTAMPDIFF(MINUTE,scheduled_start,scheduled_end)>0 THEN TIMESTAMPDIFF(MINUTE,scheduled_start,scheduled_end)/60 ELSE 2 END) h FROM {$st} WHERE status='completed' GROUP BY ym ORDER BY ym", ARRAY_A ) as $x ) { echo "S|{$x['ym']}|{$x['n']}|" . round( $x['h'], 1 ) . "\n"; }
	$rt = SDSC_Registration::table();
	foreach ( $wpdb->get_results( "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, source, COUNT(*) n, SUM(class_id>0) wc FROM {$rt} GROUP BY ym, source ORDER BY ym", ARRAY_A ) as $x ) { echo "R|{$x['ym']}|{$x['source']}|{$x['n']}|{$x['wc']}\n"; }
	echo 'I|instructors|' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}driveflow_instructors" ) . "\n";
}

// ---------- 3. Stripe course payments vs registrations
if ( 'all' === $sec || 'match' === $sec ) {
	$since = strtotime( getenv( 'RECON_SINCE' ) ?: '2024-04-01' );
	$sess = array(); $after = '';
	for ( $i = 0; $i < 60; $i++ ) {
		list( $c, $j ) = $A( 'GET', 'checkout/sessions', array_filter( array( 'limit' => 100, 'status' => 'complete', 'created' => array( 'gte' => $since ), 'starting_after' => $after ) ) );
		if ( 200 !== $c ) { echo "SESS ERROR $c " . ( $j['error']['message'] ?? '' ) . "\n"; break; }
		foreach ( $j['data'] as $s ) { $sess[] = $s; }
		if ( empty( $j['has_more'] ) ) { break; }
		$after = end( $j['data'] )['id'];
	}
	$course = function ( $s ) use ( $links ) {
		if ( ! empty( $s['metadata']['sdsc_reg'] ) ) { return 'site'; }
		$n = strtolower( $links[ $s['payment_link'] ?? '' ][0] ?? '' );
		return ( false !== strpos( $n, 'driver' ) && false !== strpos( $n, 'educ' ) ) || false !== strpos( $n, '36' ) || false !== strpos( $n, 'gls' ) ? 'link' : '';
	};
	$rt = SDSC_Registration::table();
	$regs = $wpdb->get_results( "SELECT id, created_at, source, class_id, LOWER(email) e, LOWER(parent_email) pe FROM {$rt} WHERE created_at >= '" . gmdate( 'Y-m-d', $since ) . "'", ARRAY_A );
	$by = array();
	foreach ( $regs as $g ) { foreach ( array( $g['e'], $g['pe'] ) as $e ) { if ( $e ) { $by[ $e ][] = $g; } } }
	$paid_emails = array(); $n_course = 0; $no_reg = array();
	foreach ( $sess as $s ) {
		$k = $course( $s ); if ( ! $k ) { continue; }
		$n_course++;
		$e = strtolower( (string) ( $s['customer_details']['email'] ?? $s['customer_email'] ?? '' ) );
		$paid_emails[ $e ] = 1;
		if ( 'site' === $k && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$rt} WHERE id = %d", (int) $s['metadata']['sdsc_reg'] ) ) ) { continue; }
		if ( ! $e || empty( $by[ $e ] ) ) { $no_reg[] = gmdate( 'Y-m-d', $s['created'] ) . ' | $' . number_format( $s['amount_total'] / 100, 2 ) . ' | ' . $k . ' | ' . $s['payment_intent'] . ' | ' . $mask( $e ); }
	}
	$offline = array(); foreach ( $wpdb->get_results( 'SELECT LOWER(email) e FROM ' . SDSC_Board::table() . " WHERE status='paid'", ARRAY_A ) as $x ) { $offline[ $x['e'] ] = 1; }
	$sitepaid = array(); foreach ( $wpdb->get_results( 'SELECT reg_id FROM ' . SDSC_Pay::table() . " WHERE status IN ('paid','refunded') AND mode='live'", ARRAY_A ) as $x ) { $sitepaid[ (int) $x['reg_id'] ] = 1; }
	$no_pay = array(); $checked = 0;
	foreach ( $regs as $g ) {
		if ( ! $g['class_id'] ) { continue; }
		$start = (string) get_post_meta( (int) $g['class_id'], '_sdsc_start', true );
		if ( ! $start || strtotime( $start ) > time() ) { continue; } // only classes that have started
		$checked++;
		$ok = isset( $sitepaid[ (int) $g['id'] ] ) || isset( $paid_emails[ $g['e'] ] ) || ( $g['pe'] && isset( $paid_emails[ $g['pe'] ] ) ) || isset( $offline[ $g['e'] ] ) || ( class_exists( 'SDSC_Gate' ) && ( SDSC_Gate::paid_email( $g['e'] ) || ( $g['pe'] && SDSC_Gate::paid_email( $g['pe'] ) ) ) );
		if ( ! $ok ) { $no_pay[] = 'reg #' . $g['id'] . ' | ' . substr( $g['created_at'], 0, 10 ) . ' | ' . $g['source'] . ' | class ' . get_post_meta( (int) $g['class_id'], '_sdsc_course_code', true ) . ' starts ' . substr( $start, 0, 10 ) . ' | ' . $mask( $g['e'] ); }
	}
	echo 'M|sessions|' . count( $sess ) . '|course payments|' . $n_course . '|registrations since|' . count( $regs ) . '|regs in started classes|' . $checked . "\n";
	echo 'M|course payments without a registration|' . count( $no_reg ) . "\n"; foreach ( $no_reg as $x ) { echo "NR|$x\n"; }
	echo 'M|registrations (started class) without a payment found|' . count( $no_pay ) . "\n"; foreach ( $no_pay as $x ) { echo "NP|$x\n"; }
}
