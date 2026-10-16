<?php
// Production: read-only mailbox check (EXAMINE only, nothing changed), then switch the inbox on with Saghar (user 299) as the forward recipient.
// Messages already in the mailbox are imported into the panel but NOT forwarded (only mail that arrives after switch-on is).
list( $u, $p ) = SDSC_Mailer::imap_account();
echo 'account: ' . ( $u ? preg_replace( '/^(..).*@/', '$1***@', $u ) : 'none' ) . ' password saved: ' . ( $p ? 'yes' : 'no' ) . "\n";
$im = new SDSC_Imap();
if ( ! $u || ! $p || ! $im->connect( 'imap.hostinger.com', 993, $u, $p ) ) { echo 'CONNECT FAILED ' . $im->err . "\n"; return; }
$v = $im->select( 'INBOX' );
echo 'inbox open (read-only): ' . ( false === $v ? 'no' : 'yes' ) . "\n";
echo 'messages last 7 days: ' . count( $im->search( 'SINCE ' . gmdate( 'd-M-Y', time() - 7 * DAY_IN_SECONDS ) ) ) . "\n";
echo 'sent folder: ' . $im->sent_folder() . "\n";
$im->close();
if ( false === $v ) { return; }
$st = SDSC_Inbox::staff();
if ( ! isset( $st[299] ) ) { echo "user 299 is not in the staff list - not switched on\n"; return; }
SDSC_Inbox::install();
update_option( SDSC_Inbox::OPT, array( 'on' => 1, 'fwd' => array( 299 ), 'since' => time(), 'last' => 0, 'valid' => 0 ), false );
SDSC_Inbox::schedule();
$n = SDSC_Inbox::fetch( true );
$o = SDSC_Inbox::opts();
global $wpdb;
echo 'first fetch: ' . $n . ' imported, err=' . ( $o['err'] ?: 'none' ) . ', forwarded=' . (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SDSC_Inbox::table() . ' WHERE fwd_at IS NOT NULL' ) . "\n";
echo 'next check: ' . ( wp_next_scheduled( SDSC_Inbox::HOOK ) ? wp_date( 'H:i', wp_next_scheduled( SDSC_Inbox::HOOK ) ) : 'NOT SCHEDULED' ) . "\n";
