<?php
// Staging check for the school inbox (no real mailbox is touched; staging sends no e-mail). Removes what it makes.
global $wpdb; $ok = 0; $bad = 0;
$t = function ( $c, $m ) use ( &$ok, &$bad ) { if ( $c ) { $ok++; echo "ok  $m\n"; } else { $bad++; echo "BAD $m\n"; } };
$sent = array();
add_filter( 'pre_wp_mail', function ( $r, $a ) use ( &$sent ) { $sent[] = $a; return $r; }, 1, 2 );
SDSC_Inbox::install();
$t( ! SDSC_Inbox::live_site(), 'staging is not the live site (mailbox never read here)' );
$t( 'off' === SDSC_Inbox::fetch( true ), 'fetch refuses to run on staging' );
$staff = SDSC_Inbox::staff(); $su = reset( $staff );
$keep = get_option( SDSC_Inbox::OPT );
update_option( SDSC_Inbox::OPT, array( 'on' => 1, 'fwd' => array( $su->ID ), 'since' => time() - 60, 'last' => 0, 'valid' => 0 ), false );
$o = SDSC_Inbox::opts();
$tag = 'ibt' . strtolower( wp_generate_password( 6, false, false ) );
$stu = $tag . '@example.test';
$mk = function ( $from, $subj, $body, $extra = '', $date = null ) use ( $tag ) {
	static $n = 0; $n++;
	return "Message-ID: <$tag-$n@example.test>\r\nFrom: $from\r\nTo: info@samsdrivingschool.org\r\nSubject: $subj\r\nDate: " . gmdate( 'r', $date ? $date : time() ) . "\r\n$extra" . "Content-Type: text/plain; charset=UTF-8\r\n\r\n$body\r\n";
};
// 1. a student writes
$id = SDSC_Inbox::take( 101, $mk( "Test Student <$stu>", 'Question about my lesson', "Hi, can I move my lesson to Saturday?\nThanks" ), $o );
$r  = SDSC_Inbox::row( $id );
$t( $r && 'new' === $r['status'] && $stu === $r['from_email'], 'student e-mail stored as new' );
SDSC_Inbox::flush_outbox();
$r = SDSC_Inbox::row( $id );
$fw = end( $sent );
echo '   (fwd_at=' . $r['fwd_at'] . ' to=' . wp_json_encode( $fw ? $fw['to'] : null ) . ")\n";
$t( $r['fwd_at'] && $fw && in_array( strtolower( $su->user_email ), array_map( 'strtolower', (array) $fw['to'] ), true ), 'forwarded to ' . $su->display_name );
$t( $fw && false !== strpos( $fw['subject'], '[SDS #' . SDSC_Inbox::ref( $id ) . ']' ), 'forward subject has the reference: ' . ( $fw ? $fw['subject'] : '' ) );
$t( $fw && preg_grep( '/^Reply-To:.*info@/i', (array) $fw['headers'] ), 'forward Reply-To is info@' );
$t( $fw && false !== strpos( $fw['message'], 'move my lesson' ), 'forward contains the student text' );
// 2. duplicate is ignored
$dup = "Message-ID: <$tag-1@example.test>\r\nFrom: Test Student <$stu>\r\nSubject: Question about my lesson\r\n\r\nHi again\r\n";
$t( 0 === SDSC_Inbox::take( 101, $dup, $o ), 'the same message twice is stored once' );
// 3. staff replies by e-mail → sent on to the student
$sent = array();
$rid = SDSC_Inbox::take( 102, $mk( $su->display_name . ' <' . $su->user_email . '>', 'Re: [SDS #' . SDSC_Inbox::ref( $id ) . '] Question about my lesson — Test Student', "Yes, Saturday 9am works. See you!\n\nOn Fri, Oct 16, 2026 at 1:10 PM Sam's Driving School LLC <info@samsdrivingschool.org> wrote:\n> —— Reply above this line to answer ——\n> Hi, can I move my lesson" ), $o );
$out = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Inbox::table() . " WHERE parent_id = %d AND kind = 'out'", $id ), ARRAY_A );
$m2 = end( $sent );
$t( $out && 'Yes, Saturday 9am works. See you!' === $out['body_text'] && 'sent' === $out['status'], 'staff answer cut and sent on: ' . ( $out ? json_encode( $out['body_text'] ) : '-' ) );
$t( $m2 && in_array( $stu, (array) $m2['to'], true ) && 0 === strpos( $m2['subject'], 'Re: Question about my lesson' ), 'goes to the student with Re: subject' );
$t( $m2 && preg_grep( '/^In-Reply-To: <' . preg_quote( $tag, '/' ) . '-1@example\.test>/', (array) $m2['headers'] ), 'same conversation (In-Reply-To)' );
$t( $m2 && preg_grep( '/^X-SDSC-From: info/i', (array) $m2['headers'] ), 'sent from info@' );
$t( 'replied' === SDSC_Inbox::row( $id )['status'] && 'staf' === SDSC_Inbox::row( $rid )['kind'], 'original marked answered' );
// 4. forged reference
$sent = array();
$fid = SDSC_Inbox::take( 103, $mk( $su->user_email, 'Re: [SDS #' . $id . '-000000] hack', 'send this' ), $o );
$t( ! $sent && 'other' === SDSC_Inbox::row( $fid )['status'], 'wrong reference: nothing sent on' );
// 5. a stranger with a valid reference is NOT treated as staff
$sid = SDSC_Inbox::take( 104, $mk( "Someone <x$stu>", 'Re: [SDS #' . SDSC_Inbox::ref( $id ) . '] hi', 'spoof' ), $o );
$t( 'in' === SDSC_Inbox::row( $sid )['kind'] && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Inbox::table() . " WHERE parent_id = %d AND kind='out' AND body_text='spoof'", $id ) ), 'stranger cannot answer through the reference' );
// 6. newsletter / automatic
$nid = SDSC_Inbox::take( 105, $mk( "News <news$stu>", 'Big sale', 'buy', "List-Unsubscribe: <mailto:u@x>\r\n" ), $o );
$aid = SDSC_Inbox::take( 106, $mk( "Bob <bob$stu>", 'Automatic reply: away', 'away', "Auto-Submitted: auto-replied\r\n" ), $o );
$t( 'other' === SDSC_Inbox::row( $nid )['status'] && 'other' === SDSC_Inbox::row( $aid )['status'], 'newsletter and auto-reply not forwarded' );
// 6b. security codes / sign-in links stay in the panel
SDSC_Inbox::flush_outbox(); $sent = array();
$cid = SDSC_Inbox::take( 109, $mk( "Pay Co <billing$stu>", 'Your Stripe verification code', 'Your code is 123456' ), $o );
$lid = SDSC_Inbox::take( 110, $mk( "Bank <bank$stu>", 'Hello', "Click to reset your password: https://x" ), $o );
SDSC_Inbox::flush_outbox();
$t( 'other' === SDSC_Inbox::row( $cid )['status'] && 'other' === SDSC_Inbox::row( $lid )['status'] && ! $sent, 'verification code and password reset not forwarded' );
$t( ! SDSC_Inbox::secret( "Hi, can I move my lesson to Saturday?" ) && ! SDSC_Inbox::secret( "What is included in the 378 dollars? Is that for the online class" ), 'normal student questions are still forwarded' );
// 6c. emoji reactions are not forwarded
$sent = array();
$eid = SDSC_Inbox::take( 111, $mk( "Test Student <$stu>", 'Re: Question about my lesson', "\xF0\x9F\x91\x8D\n\nTest Student reacted via Gmail\n<https://www.google.com/gmail/about/>\n\nOn Fri, Oct 16, 2026 Sam's Driving School LLC <info@samsdrivingschool.org> wrote:\n> Yes, Saturday works" ), $o );
SDSC_Inbox::flush_outbox();
$t( 'done' === SDSC_Inbox::row( $eid )['status'] && ! $sent && ! SDSC_Inbox::row( $eid )['fwd_at'], 'emoji reaction stored, not forwarded: ' . SDSC_Inbox::row( $eid )['note'] );
$t( '' === SDSC_Inbox::reaction( "ok thanks" ) && '' === SDSC_Inbox::reaction( "Yes 👍 see you Saturday" ), 'short real answers are not treated as reactions' );
// 6d. forwards show only the new text, line breaks kept
$sent = array();
$qid = SDSC_Inbox::take( 112, $mk( "Parent <p$stu>", 'Re: Driver\'s Ed', "Line one\nLine two\n\nOn Thu, Oct 15, 2026 Sam's Driving School LLC <info@samsdrivingschool.org> wrote:\n> Yes\n> For more information send text" ), $o );
SDSC_Inbox::flush_outbox();
$fm = end( $sent );
$t( $fm && false !== strpos( $fm['message'], 'Line one<br' ) && false !== strpos( $fm['message'], 'Earlier messages' ) && false === strpos( substr( $fm['message'], 0, strpos( $fm['message'], 'Earlier messages' ) ), 'For more information' ), 'forward shows the new text first, old quote below' );
// 7. failed DKIM from staff
$did = SDSC_Inbox::take( 107, $mk( $su->user_email, 'Re: [SDS #' . SDSC_Inbox::ref( $id ) . '] x', 'fake', "Authentication-Results: mx.hostinger.com; dkim=fail; spf=fail\r\n" ), $o );
$t( 'new' === SDSC_Inbox::row( $did )['status'] && false !== strpos( SDSC_Inbox::row( $did )['note'], 'confirm' ), 'failed sender check: held for review' );
// 8. old message (before switch-on) is not forwarded
SDSC_Inbox::flush_outbox(); $sent = array();
$old = SDSC_Inbox::take( 108, $mk( "Old <old$stu>", 'old one', 'old', '', time() - 3 * DAY_IN_SECONDS ), $o );
SDSC_Inbox::flush_outbox();
$t( ! $sent && ! SDSC_Inbox::row( $old )['fwd_at'], 'messages from before switch-on are not forwarded' );
// 9. reply from the panel
$sent = array();
$pr = SDSC_Inbox::reply( $id, "Second answer from the panel", $su->ID );
$t( $pr[0] && $sent && in_array( $stu, (array) end( $sent )['to'], true ), 'panel answer sent' );
// 10. access
$fd = get_users( array( 'role' => 'sdsc_front_desk', 'number' => 1 ) );
if ( $fd ) { wp_set_current_user( $fd[0]->ID ); $t( ! SDSC_Inbox::can(), 'front desk cannot open the inbox' ); }
$ti = get_users( array( 'role' => 'tutor_instructor', 'number' => 1 ) );
if ( $ti ) { wp_set_current_user( $ti[0]->ID ); $t( ! SDSC_Inbox::can(), 'instructor cannot open the inbox' ); }
wp_set_current_user( $su->ID ); $t( SDSC_Inbox::can(), $su->display_name . ' can open the inbox' );
// clean up
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . SDSC_Inbox::table() . ' WHERE msgid LIKE %s OR from_email LIKE %s OR to_email LIKE %s OR parent_id = %d', $tag . '%', '%' . $stu, '%' . $stu, $id ) );
delete_option( 'sdsc_inbox_outbox' );
if ( false === $keep ) { delete_option( SDSC_Inbox::OPT ); } else { update_option( SDSC_Inbox::OPT, $keep, false ); }
wp_clear_scheduled_hook( SDSC_Inbox::HOOK );
$t( 0 == $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Inbox::table() . ' WHERE from_email LIKE %s OR to_email LIKE %s', '%' . $stu, '%' . $stu ) ), 'test rows removed' );
echo "PASS $ok FAIL $bad\n";
