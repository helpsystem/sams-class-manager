<?php
/**
 * One-off: "What's new" note about the dashboard tours, English screens and the wider layout. No e-mail is sent.
 * Run: wp eval-file tools/update-note-tours.php   (safe to run twice — it will not add the note again)
 */
$title = 'Every screen now has its own guided tour';
$all   = SDSC_Updates::all();
foreach ( $all as $x ) { if ( $x['title'] === $title ) { echo "already there\n"; return; } }
$body = '<p>Everything you open from the <b>Workspace</b> menu now explains itself.</p>'
	. '<ul><li><b>How this screen works</b> — next to the title of every screen. It walks you through that screen step by step and points at the real buttons. A yellow dot means you have not taken that tour yet.</li>'
	. '<li><b>Help → Guided tours</b> — all tours in one place, grouped like the Workspace menu, plus two “day in the life” paths (school manager and front desk) and the tours students and instructors see.</li>'
	. '<li><b>Zoom attendance, Reports &amp; printing, Messages</b> and the <b>in-car app</b> have their own tours too.</li>'
	. '<li>All school screens are now in English and share one look. The Classes screen, the class form and the Zoom-link e-mail to students were partly in Persian before.</li>'
	. '<li>Screens use the full width of your monitor, so long tables are easier to read.</li>'
	. '<li><b>Discounts</b> in the Workspace menu is now called <b>Site-wide sale</b> (it is one tab of <b>Discounts &amp; offers</b>).</li></ul>';
$id   = (int) get_option( 'sdsc_updates_next', 1 );
$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
array_unshift( $all, array( 'id' => $id, 't' => time(), 'author' => $admins ? (int) $admins[0] : 0, 'title' => $title, 'body' => $body, 'roles' => array( 'manager', 'frontdesk' ) ) );
update_option( 'sdsc_updates_next', $id + 1, false );
update_option( SDSC_Updates::OPT, $all, false );
echo "added #$id\n";
