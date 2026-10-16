<?php
// Production: answer one inbox message from info@ (used for the first live test, asked for by the owner). Args via env: IB_FROM (sender e-mail), IB_CC (user id to copy).
global $wpdb;
$from = strtolower( (string) getenv( 'IB_FROM' ) );
$id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . SDSC_Inbox::table() . " WHERE kind='in' AND from_email=%s ORDER BY sent_at DESC LIMIT 1", $from ) );
$cc   = get_userdata( (int) getenv( 'IB_CC' ) );
if ( ! $id || ! $cc ) { echo "not found\n"; return; }
$text = "Yes\nFor more information send text or call 202-6000889";
$r = SDSC_Inbox::reply( $id, $text, (int) getenv( 'IB_CC' ), 'from the panel', array( $cc->user_email ) );
echo 'message #' . $id . ': ' . ( $r[0] ? 'SENT ' : 'FAILED ' ) . $r[1] . ' cc ' . preg_replace( '/^(..).*@/', '$1***@', $cc->user_email ) . "\n";
$l = get_option( 'sdsc_mail_log', array() ); $x = is_array( $l ) ? reset( $l ) : null;
if ( $x ) { echo 'mail log: ' . ( $x['ok'] ? 'ok' : 'error ' . $x['e'] ) . ' | ' . $x['s'] . "\n"; }
