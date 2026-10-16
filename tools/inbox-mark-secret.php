<?php
// Production: messages already imported that are security codes / sign-in e-mails -> handled, kept out of "To answer".
global $wpdb; $n = 0;
foreach ( $wpdb->get_results( 'SELECT id, subject, body_text FROM ' . SDSC_Inbox::table() . " WHERE kind='in' AND status='new'", ARRAY_A ) as $r ) {
	if ( SDSC_Inbox::secret( $r['subject'] . ' ' . mb_substr( (string) $r['body_text'], 0, 3000 ) ) ) { $wpdb->update( SDSC_Inbox::table(), array( 'status' => 'other', 'note' => 'Security code / sign-in e-mail — kept in the panel only, never forwarded' ), array( 'id' => (int) $r['id'] ) ); $n++; }
}
echo "security e-mails moved to Other: $n\n";
