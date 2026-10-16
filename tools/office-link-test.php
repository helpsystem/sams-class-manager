<?php
/** Office registration link (cash / check): key, short link, form flag, success page, dashboard card. */
$n = 0; $f = 0; $ok = function ( $c, $m ) use ( &$n, &$f ) { $n++; if ( ! $c ) { $f++; } echo ( $c ? 'ok   ' : 'FAIL ' ) . $m . "\n"; };
$k = SDSC_Registration::office_key();
$ok( 12 === strlen( $k ) && SDSC_Registration::is_office_key( $k ) && ! SDSC_Registration::is_office_key( 'x' . substr( $k, 1 ) ), 'office key checks' );
$l = SDSC_Registration::office_link( 0 );
$ok( 0 === strpos( $l, home_url( '/' ) ), 'short link: ' . $l );
$long = SDSC_Registration::office_link( 0, false );
$ok( false !== strpos( $long, 'office=' . $k ), 'long link carries the key' );
$cls = SDSC_Registration::open_classes();
if ( $cls ) { $ok( false !== strpos( SDSC_Registration::office_link( $cls[0]->ID, false ), 'course=' ), 'class link carries the course' ); }
$_GET = array( 'office' => $k );
$html = SDSC_Registration::shortcode( array() );
$ok( false !== strpos( $html, 'name="sdsc_office" value="' . $k . '"' ), 'form carries the office key as a hidden field' );
$_GET = array();
$html = SDSC_Registration::shortcode( array() );
$ok( false === strpos( $html, 'sdsc_office' ), 'without the key the form is the normal one' );
$_GET = array( 'sdsc_reg' => 'done', 'sdsc_class' => $cls ? (int) $cls[0]->ID : 0, 'office' => $k );
$html = SDSC_Registration::shortcode( array() );
$ok( false !== strpos( $html, 'pay at the office' ) && false === strpos( $html, 'Continue to payment' ), 'success page: pay at the office, no online payment button' );
$_GET = array();
$adm = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $adm[0] ?? 0 ) );
$card = SDSC_Overview::render();
$ok( false !== strpos( $card, 'Register at the office (cash / check)' ) && false !== strpos( $card, 'sov-ol-qr' ) && false !== strpos( $card, 'sdsc_office_link_text' ), 'dashboard card with QR code and text form' );
echo "\n$n checks, $f failed\n";
