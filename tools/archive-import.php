<?php
/**
 * Past courses from the office spreadsheet (2023–2026) → the site. Reads /tmp/courses.csv
 * (n,start,end,am|pm,name,phone1,phone2 — no e-mails). Env:
 *   ARCH_DO=report  (default) nothing is written; prints what would happen and every doubtful case
 *   ARCH_DO=classes  make the past classes (private, completed, no price, no timetable, not on the website)
 *   ARCH_DO=link     set class_id on existing registrations that have none and match exactly one roster row
 *   ARCH_DO=students add roster people who are not on the site as archive registrations (source 'archive')
 *   ARCH_DO=undo     remove everything this tool made (classes with _sdsc_archive_import, archive registrations, links it set)
 * Rules: never overwrite a filled class_id, a name, a phone or an e-mail; ambiguous matches are listed, not applied.
 * The spreadsheet is a course roster — a row means the person was on that course's list; it says nothing about
 * payments, transfers (half the course elsewhere) or extra practice, so nothing about money or attendance is written.
 */
global $wpdb;
$do  = getenv( 'ARCH_DO' ) ?: 'report';
$csv = '/tmp/courses.csv';
if ( ! is_readable( $csv ) ) { echo "no $csv\n"; return; }
$norm  = function ( $s ) { $s = strtolower( remove_accents( (string) $s ) ); return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z ]+/', ' ', $s ) ) ); };
$phone = function ( $p ) { $d = preg_replace( '/\D+/', '', (string) $p ); return strlen( $d ) >= 10 ? substr( $d, -10 ) : ''; };
// Stripe cross-check (optional /tmp/stripe.csv: rowno,paid_date,amount,email,payer_is_student) made from the school's Stripe export
$stripe = array();
if ( is_readable( '/tmp/stripe.csv' ) ) { foreach ( array_map( 'str_getcsv', file( '/tmp/stripe.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) as $x ) { if ( count( $x ) >= 5 ) { $stripe[ (int) $x[0] ] = array( 'date' => $x[1], 'amount' => $x[2], 'email' => strtolower( trim( $x[3] ) ), 'self' => '1' === $x[4] ); } } }
$rows  = array(); $courses = array(); $rowno = 0;
foreach ( array_map( 'str_getcsv', file( $csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) as $r ) {
	if ( count( $r ) < 7 || ! ctype_digit( (string) $r[0] ) ) { continue; }
	$n = (int) $r[0]; $rowno++; $rows[] = array( 'n' => $n, 'start' => $r[1], 'end' => $r[2], 'ap' => $r[3], 'name' => trim( $r[4] ), 'p1' => $phone( $r[5] ), 'p2' => $phone( $r[6] ), 'stripe' => $stripe[ $rowno ] ?? null );
	if ( ! isset( $courses[ $n ] ) ) { $courses[ $n ] = array( 'start' => $r[1], 'end' => $r[2], 'ap' => $r[3], 'count' => 0 ); }
	$courses[ $n ]['count']++;
	if ( $courses[ $n ]['start'] > $r[1] ) { $courses[ $n ]['start'] = $r[1]; } // a few rows carry a different date — keep the earliest start, latest end
	if ( $courses[ $n ]['end'] < $r[2] ) { $courses[ $n ]['end'] = $r[2]; }
	if ( ! $courses[ $n ]['ap'] && $r[3] ) { $courses[ $n ]['ap'] = $r[3]; }
}
ksort( $courses );
$code = function ( $n ) { return 'C-' . str_pad( (string) $n, 2, '0', STR_PAD_LEFT ); };
$D    = function ( $ymd, $fmt ) { return ( new DateTimeImmutable( $ymd . ' 12:00:00', wp_timezone() ) )->format( $fmt ); };
$dre  = function ( $ymd ) use ( $D ) { return '/' . implode( '|', array_map( function ( $f ) use ( $D, $ymd ) { return preg_quote( $D( $ymd, $f ), '/' ); }, array( 'm-d-Y', 'm/d/Y', 'n/j/Y', 'M j', 'F j' ) ) ) . '/i'; };
$rt   = SDSC_Registration::table();
$cls  = array(); // n => class post id (already on the site)
foreach ( get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1 ) ) as $p ) {
	$c = (string) get_post_meta( $p->ID, '_sdsc_course_code', true );
	if ( preg_match( '/^C-0*(\d+)$/i', $c, $m ) ) { $cls[ (int) $m[1] ] = $p->ID; }
}
echo "Stripe cross-check rows: " . count( $stripe ) . "\n";
echo "courses in file: " . count( $courses ) . " (" . min( array_keys( $courses ) ) . "–" . max( array_keys( $courses ) ) . "), rows: " . count( $rows ) . "; classes already on the site with a C-nn code: " . count( $cls ) . "\n";
$overlap = array_intersect_key( $courses, $cls );
if ( $overlap ) { echo "NOTE: file courses that already exist on the site (left untouched): " . implode( ', ', array_map( $code, array_keys( $overlap ) ) ) . "\n"; }

/* ---------------- classes */
if ( 'classes' === $do || 'report' === $do ) {
	$made = 0;
	foreach ( $courses as $n => $c ) {
		if ( isset( $cls[ $n ] ) ) { continue; }
		$title = 'Driver’s Education Program (' . ( 'pm' === $c['ap'] ? 'PM' : ( 'am' === $c['ap'] ? 'AM' : 'Past' ) ) . ' Class) — ' . $code( $n ) . ' · ' . $D( $c['start'], 'M j' ) . '–' . $D( $c['end'], 'M j, Y' );
		if ( 'report' === $do ) { if ( $made < 3 ) { echo "  would make: $title (" . $c['count'] . " on the list)\n"; } $made++; continue; }
		$id = wp_insert_post( array( 'post_type' => 'sdsc_class', 'post_status' => 'private', 'post_title' => $title ), true );
		if ( is_wp_error( $id ) ) { echo "  ERROR " . $code( $n ) . ': ' . $id->get_error_message() . "\n"; continue; }
		update_post_meta( $id, '_sdsc_course_code', $code( $n ) );
		update_post_meta( $id, '_sdsc_start', $c['start'] . 'T' . ( 'pm' === $c['ap'] ? '18:00' : '09:00' ) );
		update_post_meta( $id, '_sdsc_end', $c['end'] . 'T' . ( 'pm' === $c['ap'] ? '21:15' : '12:00' ) );
		update_post_meta( $id, '_sdsc_status', 'completed' );
		update_post_meta( $id, '_sdsc_days_time', 'Mon–Fri, ' . ( 'pm' === $c['ap'] ? '6:00–9:15 PM' : ( 'am' === $c['ap'] ? '9:00 AM–12:00 PM' : 'past class' ) ) );
		update_post_meta( $id, '_sdsc_capacity', max( 30, (int) $c['count'] ) );
		update_post_meta( $id, '_sdsc_archive_import', wp_date( 'Y-m-d' ) ); // marks everything this tool made
		update_post_meta( $id, '_sdsc_zoom_auto', 'off' );
		$cls[ $n ] = $id; $made++;
	}
	echo ( 'report' === $do ? "classes to make: " : "classes made: " ) . $made . "\n";
}

/* ---------------- index the site's registrations */
$regs = $wpdb->get_results( "SELECT id, first_name, last_name, phone, parent_phone, email, class_id, registrant_id, source, notes, DATE(created_at) d FROM $rt WHERE source <> 'demo' ORDER BY id", ARRAY_A );
$byName = array(); $byPhone = array();
foreach ( $regs as $g ) {
	$byName[ $norm( $g['first_name'] . ' ' . $g['last_name'] ) ][] = $g;
	foreach ( array( $phone( $g['phone'] ), $phone( $g['parent_phone'] ) ) as $p ) { if ( $p ) { $byPhone[ $p ][] = $g; } }
}
/* match every roster row to site registrations: exact full name, else a phone; keep it only when it is ONE person */
$link = array(); $amb = array(); $none = array(); $seen_reg = array();
foreach ( $rows as $i => $r ) {
	$k = $norm( $r['name'] );
	$cands = $byName[ $k ] ?? array();
	$how = 'name';
	if ( ! $cands ) { foreach ( array( $r['p1'], $r['p2'] ) as $p ) { if ( $p && ! empty( $byPhone[ $p ] ) ) { $cands = $byPhone[ $p ]; $how = 'phone'; break; } } }
	if ( ! $cands ) { $none[] = $i; continue; }
	// a phone match must not contradict the name (siblings share a parent phone)
	if ( 'phone' === $how ) {
		$t = explode( ' ', $k );
		$cands = array_values( array_filter( $cands, function ( $g ) use ( $norm, $t ) { $gn = explode( ' ', $norm( $g['first_name'] . ' ' . $g['last_name'] ) ); return $t && $gn && ( $gn[0] === $t[0] || end( $gn ) === end( $t ) ); } ) );
		if ( ! $cands ) { $none[] = $i; continue; }
	}
	$ids = array_values( array_unique( wp_list_pluck( $cands, 'id' ) ) );
	if ( count( $ids ) > 1 ) {
		// the same person registered twice on the site: pick the one whose old-form course date (notes) matches, else leave it
		$pick = array_values( array_filter( $cands, function ( $g ) use ( $r, $dre ) { return $g['notes'] && false !== strpos( (string) $g['notes'], 'Course date' ) && preg_match( $dre( $r['start'] ), (string) $g['notes'] ); } ) );
		if ( 1 === count( $pick ) ) { $cands = $pick; $ids = array( $pick[0]['id'] ); } else { $amb[] = array( $r, $ids ); continue; }
	}
	$g = $cands[0];
	if ( isset( $seen_reg[ $g['id'] ] ) ) { $amb[] = array( $r, array( $g['id'], 'already matched to course ' . $seen_reg[ $g['id'] ] ) ); continue; }
	$seen_reg[ $g['id'] ] = $r['n'];
	$link[] = array( 'reg' => $g, 'row' => $r, 'how' => $how );
}
$ws = count( array_filter( $rows, function ( $r ) { return ! empty( $r['stripe'] ); } ) );
echo "roster rows with a Stripe course payment: $ws of " . count( $rows ) . " (" . count( array_filter( $rows, function ( $r ) { return $r['start'] >= '2024-04-25'; } ) ) . " rows fall in the Stripe period)\n";
echo "roster rows matched to one site registration: " . count( $link ) . " (" . count( array_filter( $link, function ( $x ) { return 'phone' === $x['how']; } ) ) . " by phone); doubtful (listed, not applied): " . count( $amb ) . "; not on the site: " . count( $none ) . "\n";
$has_class = array_filter( $link, function ( $x ) { return (int) $x['reg']['class_id'] > 0; } );
echo "  of the matched registrations, " . count( $has_class ) . " already have a class and are left as they are\n";
// old-form course date in notes vs roster course: a cross-check
$chk = array( 'agree' => 0, 'differ' => 0, 'no note' => 0 ); $differ = array();
foreach ( $link as $x ) {
	$note = (string) $x['reg']['notes'];
	if ( false === strpos( $note, 'Course date' ) ) { $chk['no note']++; continue; }
	if ( preg_match( $dre( $x['row']['start'] ), $note ) ) { $chk['agree']++; } else { $chk['differ']++; if ( count( $differ ) < 8 ) { $differ[] = 'reg #' . $x['reg']['id'] . ' course ' . $x['row']['n'] . ' (' . $x['row']['start'] . ') vs note "' . mb_substr( $note, 0, 60 ) . '"'; } }
}
echo "  cross-check with the old form's chosen course date: agree " . $chk['agree'] . ", differ " . $chk['differ'] . ", no note " . $chk['no note'] . "\n";
foreach ( $differ as $d ) { echo "    differ: $d\n"; }
if ( 'report' === $do ) { foreach ( array_slice( $amb, 0, 12 ) as $a ) { echo "  doubtful: course " . $a[0]['n'] . ' ' . $a[0]['name'] . ' → registrations ' . implode( ',', (array) $a[1] ) . "\n"; } }

/* ---------------- link */
if ( 'link' === $do ) {
	$set = 0; $skip = 0;
	foreach ( $link as $x ) {
		$g = $x['reg']; $n = $x['row']['n'];
		if ( (int) $g['class_id'] > 0 || empty( $cls[ $n ] ) ) { $skip++; continue; }
		// only when the roster course date does not contradict the old form's note
		$note = (string) $g['notes'];
		if ( false !== strpos( $note, 'Course date' ) && ! preg_match( $dre( $x['row']['start'] ), $note ) ) { $skip++; continue; }
		$st = $x['row']['stripe'];
		$wpdb->update( $rt, array( 'class_id' => (int) $cls[ $n ], 'notes' => trim( $note . "\n" . 'Course ' . $code( $n ) . ' from the office course list (' . $x['row']['start'] . ')' . ( $st ? '; Stripe course payment $' . $st['amount'] . ' on ' . $st['date'] : '; no online course payment found in Stripe' ) ) ), array( 'id' => (int) $g['id'] ) );
		if ( $g['registrant_id'] && ! get_post_meta( (int) $g['registrant_id'], '_sdsc_class_id', true ) ) { update_post_meta( (int) $g['registrant_id'], '_sdsc_class_id', (int) $cls[ $n ] ); }
		$set++;
	}
	echo "registrations linked to their past class: $set (skipped $skip)\n";
}

/* ---------------- students */
if ( 'students' === $do ) {
	$add = 0; $dup = 0;
	foreach ( $none as $i ) {
		$r = $rows[ $i ];
		if ( empty( $cls[ $r['n'] ] ) ) { continue; }
		$parts = preg_split( '/\s+/', trim( $r['name'] ) );
		$first = array_shift( $parts ); $last = implode( ' ', $parts );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $rt WHERE source = 'archive' AND first_name = %s AND last_name = %s AND class_id = %d", $first, $last, (int) $cls[ $r['n'] ] ) ) ) { $dup++; continue; }
		$st   = $r['stripe'];
		$note = 'From the office course list (' . $code( $r['n'] ) . ', ' . $r['start'] . '). The list does not say whether the course was completed here or transferred.';
		$note .= $st ? ' Stripe: course payment of $' . $st['amount'] . ' on ' . $st['date'] . ( $st['self'] ? ' (paid by the student)' : ' (paid by someone else — parent/guardian?)' ) . ( $st['email'] ? '; e-mail taken from that payment.' : '.' )
			: ' No online course payment found in Stripe (cash / Zelle / check, a transfer, or paid under another name).';
		$email = $st && $st['email'] && is_email( $st['email'] ) ? $st['email'] : '';
		$wpdb->insert( $rt, array(
			'created_at' => $r['start'] . ' 09:00:00', 'source' => 'archive', 'class_id' => (int) $cls[ $r['n'] ],
			'first_name' => mb_substr( $first, 0, 100 ), 'last_name' => mb_substr( $last, 0, 100 ), 'phone' => $r['p1'], 'parent_phone' => $r['p2'],
			'email' => $st && $st['self'] ? $email : '', 'parent_email' => $st && ! $st['self'] ? $email : '',
			'notes' => $note,
		) );
		$add++;
	}
	echo "archive students added: $add (already there: $dup)\n";
}

/* ---------------- undo */
if ( 'undo' === $do ) {
	$n1 = (int) $wpdb->query( "DELETE FROM $rt WHERE source = 'archive'" );
	$ids = get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_sdsc_archive_import' ) );
	$n2 = 0;
	foreach ( $ids as $id ) {
		$wpdb->query( $wpdb->prepare( "UPDATE $rt SET class_id = NULL, notes = TRIM(REPLACE(notes, CONCAT('\nCourse ', %s, ' from the office course list'), '')) WHERE class_id = %d AND source <> 'archive'", get_post_meta( $id, '_sdsc_course_code', true ), $id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $rt SET notes = TRIM(SUBSTRING_INDEX(notes, '\nCourse C-', 1)) WHERE notes LIKE %s", '%from the office course list%' ) );
		wp_delete_post( $id, true ); $n2++;
	}
	echo "undo: archive students removed $n1, classes removed $n2, links cleared\n";
}
