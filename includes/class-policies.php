<?php
/**
 * "Registration received" e-mail with the school policies.
 *
 * Right after a student submits the registration form they receive: what they registered for, the
 * payment button when the class can be paid online, and the school policies as written on the site's
 * Policies page (so the e-mail always matches the page), plus a link to read them online.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Policies {
	const CACHE = 'sdsc_policies_html';

	public static function init() {
		add_action( 'sdsc_registered', array( __CLASS__, 'send' ), 20, 3 );
		add_action( 'save_post_page', array( __CLASS__, 'flush' ) );
		add_action( 'elementor/editor/after_save', array( __CLASS__, 'flush' ) );
	}

	public static function flush() { delete_transient( self::CACHE ); }

	public static function page() {
		$id = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_option( 'tutor_toc_page_id' ) : 0;
		$p  = $id ? get_post( $id ) : get_page_by_path( 'policy' );
		return $p && 'publish' === $p->post_status ? $p : null;
	}

	/** The policies as clean, e-mail-safe HTML (headings, paragraphs and lists only). */
	public static function html() {
		$c = get_transient( self::CACHE );
		if ( is_string( $c ) ) { return $c; }
		$p = self::page();
		if ( ! $p ) { return ''; }
		$raw = '';
		if ( class_exists( '\Elementor\Plugin' ) && get_post_meta( $p->ID, '_elementor_data', true ) ) {
			$raw = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $p->ID, false );
		}
		if ( '' === trim( wp_strip_all_tags( (string) $raw ) ) ) { $raw = apply_filters( 'the_content', $p->post_content ); } // phpcs:ignore
		$raw  = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', (string) $raw );
		$html = wp_kses( $raw, array( 'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'br' => array() ) );
		// The page ends with a services promo — not a policy.
		if ( preg_match( '#<(h[1-4]|p|li)>\s*(Our Driving School Services|Our services)\b#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$html = substr( $html, 0, $m[0][1] );
		} else {
			$pos = stripos( $html, 'Our Driving School Services' );
			if ( false !== $pos ) { $cut = strrpos( substr( $html, 0, $pos ), '<' ); $html = substr( $html, 0, false !== $cut ? $cut : $pos ); }
		}
		$html = preg_replace( '/\n\s*\n+/', "\n", trim( $html ) );
		$html = preg_replace( '#<(p|li|h[1-4])>\s*</\1>#', '', $html );
		$f    = 'font-family:Arial,Helvetica,sans-serif;';
		$html = strtr( $html, array(
			'<h1>' => '<h3 style="' . $f . 'margin:18px 0 6px;font-size:16px;color:#00703c">', '</h1>' => '</h3>',
			'<h2>' => '<h3 style="' . $f . 'margin:18px 0 6px;font-size:16px;color:#00703c">', '</h2>' => '</h3>',
			'<h3>' => '<h3 style="' . $f . 'margin:18px 0 6px;font-size:16px;color:#00703c">',
			'<h4>' => '<h4 style="' . $f . 'margin:14px 0 4px;font-size:14px;color:#15191d">',
			'<p>'  => '<p style="' . $f . 'margin:0 0 10px;font-size:14px;line-height:1.6;color:#15191d">',
			'<ul>' => '<ul style="' . $f . 'margin:0 0 10px;padding-left:20px;font-size:14px;line-height:1.6;color:#15191d">',
			'<ol>' => '<ol style="' . $f . 'margin:0 0 10px;padding-left:20px;font-size:14px;line-height:1.6;color:#15191d">',
			'<li>' => '<li style="margin:0 0 6px">',
		) );
		set_transient( self::CACHE, $html, 12 * HOUR_IN_SECONDS );
		return $html;
	}

	public static function send( $reg_id, $d, $offers = false ) {
		if ( empty( $d['email'] ) || ! is_email( $d['email'] ) ) { return; }
		global $wpdb;
		$reg = $wpdb->get_row( $wpdb->prepare( 'SELECT class_id FROM ' . SDSC_Registration::table() . ' WHERE id = %d', $reg_id ), ARRAY_A ); // phpcs:ignore
		$cid = $reg ? (int) $reg['class_id'] : 0;
		$f   = 'font-family:Arial,Helvetica,sans-serif;';
		$name  = $cid ? html_entity_decode( (string) get_post_field( 'post_title', $cid ), ENT_QUOTES, 'UTF-8' ) : '';
		$start = $cid ? (string) get_post_meta( $cid, '_sdsc_start', true ) : '';
		$days  = $cid ? (string) get_post_meta( $cid, '_sdsc_days_time', true ) : '';
		$pay   = $cid && class_exists( 'SDSC_Pay' ) ? SDSC_Pay::url( $reg_id, $cid ) : '';
		$link  = $cid ? (string) get_post_meta( $cid, '_sdsc_payment_link', true ) : '';
		$pay   = $pay ? $pay : $link;
		$pol   = self::html();
		$page  = self::page();
		$purl  = $page ? get_permalink( $page ) : home_url( '/policy/' );
		$purl  = class_exists( 'SDSC_Short' ) ? SDSC_Short::make( $purl ) : $purl;

		$h  = '<p style="' . $f . 'margin:0 0 14px;font-size:15px;line-height:1.6;color:#15191d">Hi ' . esc_html( $d['first_name'] ) . ', thank you for registering with Sam’s Driving School. We received your registration' . ( $name ? ' for <b>' . esc_html( $name ) . '</b>' : '' ) . '.</p>';
		if ( $start || $days ) {
			$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;border-radius:12px;background:#f7f9fa"><tr><td style="' . $f . 'padding:14px 16px;font-size:14px;line-height:1.6;color:#15191d">'
				. ( $start ? '<b>Starts:</b> ' . esc_html( date_i18n( 'l, F j, Y', strtotime( $start ) ) ) . '<br>' : '' ) . ( $days ? '<b>Days &amp; time:</b> ' . esc_html( $days ) . '<br>' : '' ) . '<b>Where:</b> Online on Zoom — the link comes with your class confirmation.</td></tr></table>';
		}
		if ( $pay ) {
			$h .= '<p style="' . $f . 'margin:0 0 6px;font-size:15px;color:#15191d"><b>Next step:</b> complete your payment to reserve your seat.</p>'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:14px auto 22px"><tr><td style="border-radius:999px;background:#00703c"><a href="' . esc_url( $pay ) . '" style="' . $f . 'display:inline-block;padding:14px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px">Complete payment →</a></td></tr></table>';
		} else {
			$h .= '<p style="' . $f . 'margin:0 0 16px;font-size:15px;color:#15191d">We will contact you shortly with payment and class details.</p>';
		}
		$h .= '<div style="margin:8px 0 0;padding:18px 18px 8px;border-radius:14px;border:1px solid #e3e7ea">'
			. '<div style="' . $f . 'font-size:12px;letter-spacing:1.4px;text-transform:uppercase;font-weight:800;color:#5b646d">School policies</div>'
			. '<p style="' . $f . 'margin:6px 0 10px;font-size:13px;color:#5b646d">By registering you agreed to these policies. Please keep this e-mail.</p>'
			. ( $pol ? $pol : '' )
			. '<p style="' . $f . 'margin:6px 0 10px;font-size:14px"><a href="' . esc_url( $purl ) . '" style="color:#00703c;font-weight:700">Read the policies online</a></p></div>'
			. ( class_exists( 'SDSC_Account' ) ? SDSC_Account::email_block() : '' )
			. '<p style="' . $f . 'margin:18px 0 0;font-size:14px;color:#15191d">Questions? Call or text (301) 726-3030.</p>';

		$subj = 'We received your registration' . ( $name ? ' — ' . $name : '' );
		$hdr  = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( class_exists( 'SDSC_Mailer' ) ) { SDSC_Mailer::send( $d['email'], $subj, $h, 'info', $hdr ); } else { wp_mail( $d['email'], $subj, $h, $hdr ); }
	}
}
