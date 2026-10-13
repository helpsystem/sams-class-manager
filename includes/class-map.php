<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * "Find us" map + directions that open in any navigation app.
 *
 * - Replaces Elementor's Google Maps widget (the contact page) with a branded block:
 *   highway-sign info card, embedded map, buttons for Google Maps / Apple Maps / Waze, copy address.
 * - Every address link on the site (header, footer, contact icon box) gets data-sdsc-nav, which opens
 *   a small chooser so people pick their own app. Without JavaScript the link simply opens Google Maps.
 * - The address and Google Place ID come from the school's Google Business listing.
 *   Turn the block off with: wp option update sdsc_map 0
 */
class SDSC_Map {

	const PLACE_ID = 'ChIJ_4Zgdy7Nt4kRwf6CVIqIVvM';
	const NAME     = 'Sam’s Driving School';
	const STREET   = '751 Rockville Pike, Unit 9B';
	const CITY     = 'Rockville, MD 20852';
	const OLD      = '12730 Twinbrook';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 8 );
		add_shortcode( 'sdsc_map', array( __CLASS__, 'shortcode' ) );
		if ( self::on() ) {
			add_filter( 'elementor/widget/render_content', array( __CLASS__, 'widget' ), 25, 2 );
		}
	}

	public static function on() {
		return '0' !== get_option( 'sdsc_map', '1' );
	}

	/** Address used for the map pin: street and city without the unit number. */
	public static function pin_address() {
		return trim( preg_replace( '/,\s*(Unit|Suite|Ste\.?|#)\s*[\w-]+/i', '', self::STREET ) ) . ', ' . self::CITY;
	}

	public static function address() {
		return self::STREET . ', ' . self::CITY;
	}

	/** Deep links. On phones each one opens the app when it is installed, otherwise the website. */
	public static function links() {
		$addr = self::address();
		$full = 'Sam\'s Driving School, ' . $addr;
		return array(
			'google' => 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $full ) . '&destination_place_id=' . self::PLACE_ID,
			'apple'  => 'https://maps.apple.com/?daddr=' . rawurlencode( $addr ) . '&q=' . rawurlencode( 'Sam\'s Driving School' ),
			'waze'   => 'https://waze.com/ul?q=' . rawurlencode( $addr ) . '&navigate=yes',
			'view'   => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $full ) . '&query_place_id=' . self::PLACE_ID,
			// The street address alone (no business name, no unit number) always gets a pin, centred on the building.
			'embed'  => 'https://maps.google.com/maps?q=' . rawurlencode( self::pin_address() ) . '&z=16&output=embed',
		);
	}

	/** Opening hours as saved in Rank Math (Titles & Meta → Local SEO) — the same data Google sees in the schema. */
	public static function hours() {
		$o = get_option( 'rank-math-options-titles', array() );
		$h = is_array( $o ) && ! empty( $o['opening_hours'] ) && is_array( $o['opening_hours'] ) ? $o['opening_hours'] : array();
		$out = array();
		foreach ( $h as $row ) {
			if ( empty( $row['day'] ) || empty( $row['time'] ) || ! preg_match( '/^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', trim( $row['time'] ), $m ) ) { continue; }
			$out[] = array( 'day' => sanitize_text_field( $row['day'] ), 'open' => $m[1], 'close' => $m[2] );
		}
		return $out;
	}

	protected static function fmt( $t ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $t ) );
		$s = ( 0 === $h % 12 ? 12 : $h % 12 ) . ( $m ? ':' . sprintf( '%02d', $m ) : '' );
		return $s . ( $h < 12 ? ' AM' : ' PM' );
	}

	public static function assets() {
		if ( is_admin() ) { return; }
		wp_enqueue_style( 'sdsc-map', SDSC_URL . 'assets/map.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-map', SDSC_URL . 'assets/map.js', array(), SDSC_VERSION, true );
		$l = self::links();
		wp_localize_script( 'sdsc-map', 'SDSC_MAP', array(
			'name'    => self::NAME,
			'address' => self::address(),
			'google'  => $l['google'],
			'apple'   => $l['apple'],
			'waze'    => $l['waze'],
		) );
	}

	public static function shortcode() {
		return self::block();
	}

	public static function widget( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) { return $content; }
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) { return $content; }
		if ( 'google_maps' === $widget->get_name() ) {
			return self::block() . ( class_exists( 'SDSC_Holidays' ) ? SDSC_Holidays::calendar_html() : '' )
				. ( class_exists( 'SDSC_Areas' ) ? SDSC_Areas::hiring_card( 'light' ) : '' ); // Contact Us: "We're hiring" band
		}
		if ( false !== strpos( $content, 'elementor-icon-box-description' ) ) {
			$content = self::clickable( $content );
		}
		// Old directions links (the school's previous Twinbrook address) → current address + app chooser.
		if ( false !== strpos( $content, 'google.com/maps/dir/' ) ) {
			$content = preg_replace( '#href="https://www\.google\.com/maps/dir/[^"]*"#', 'href="' . esc_url( self::links()['google'] ) . '" data-sdsc-nav target="_blank" rel="noopener"', $content );
		}
		return $content;
	}

	const EMAIL_MAIN = 'info@samsdrivingschool.org';

	/**
	 * Contact icon boxes (Address / Phone / Email): every number, e-mail and the address become links,
	 * and the school's own e-mail (info@) is listed above the Gmail address.
	 */
	public static function clickable( $content ) {
		return preg_replace_callback( '#(<p class="elementor-icon-box-description"[^>]*>)(.*?)(</p>)#s', function ( $m ) {
			$body = $m[2];
			if ( false !== stripos( $body, '<a ' ) ) { return $m[0]; }
			$link = 'style="color:inherit;text-decoration:underline;text-underline-offset:3px"';
			// E-mail: info@ first, then the Gmail address.
			if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+/', $body, $em ) ) {
				$mail = function ( $e ) use ( $link ) { return '<a class="sdmap-mail" href="mailto:' . esc_attr( strtolower( $e ) ) . '" ' . $link . '>' . esc_html( $e ) . '</a>'; };
				$body = preg_replace_callback( '/[\w.+-]+@[\w-]+\.[\w.]+/', function ( $x ) use ( $mail ) { return $mail( $x[0] ); }, $body );
				if ( false === stripos( $body, self::EMAIL_MAIN ) ) {
					$body = preg_replace( '#(<b[^>]*>)?(\s*<i [^>]*fa-envelope[^>]*></i>)?\s*<a class="sdmap-mail"#', '$1$2 ' . $mail( self::EMAIL_MAIN ) . '<br>$2 <a class="sdmap-mail"', $body, 1 );
				}
				return $m[1] . $body . $m[3];
			}
			// Phones: (301) 726-3030, (202)600-0889, 301-726-3030 …
			if ( preg_match( '/\(?\d{3}\)?\s*[-.]?\s*\d{3}[-.\s]\d{4}/', $body ) ) {
				$body = preg_replace_callback( '/\(?(\d{3})\)?\s*[-.]?\s*(\d{3})[-.\s](\d{4})/', function ( $x ) use ( $link ) {
					return '<a href="tel:+1' . $x[1] . $x[2] . $x[3] . '" ' . $link . '>(' . $x[1] . ') ' . $x[2] . '-' . $x[3] . '</a>';
				}, $body );
				return $m[1] . $body . $m[3];
			}
			// Address → directions (the directions-link rewrite below adds the app chooser and new tab).
			if ( false !== stripos( $body, 'Rockville Pike' ) ) {
				return $m[1] . '<a href="' . esc_url( self::links()['google'] ) . '" ' . $link . '>' . trim( $body ) . '</a>' . $m[3];
			}
			return $m[0];
		}, $content );
	}

	protected static function icon( $n ) {
		$p = array(
			'nav'   => '<path d="M21 3 3 10.5l7.2 2.3L12.5 20z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
			'pin'   => '<path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="currentColor"/>',
			'car'   => '<path d="M5 16h14M6.5 16l1.6-5.2A2 2 0 0 1 10 9.4h4a2 2 0 0 1 1.9 1.4L17.5 16M4 16v3h2.5v-2m11 0v2H20v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8" cy="16" r="1.3" fill="currentColor"/><circle cx="16" cy="16" r="1.3" fill="currentColor"/>',
			'copy'  => '<rect x="8" y="8" width="12" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" fill="none" stroke="currentColor" stroke-width="1.8"/>',
			'phone' => '<path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z" fill="currentColor"/>',
			'star'  => '<path d="m12 2.8 2.8 5.8 6.3.8-4.6 4.4 1.2 6.3L12 17l-5.7 3.1 1.2-6.3-4.6-4.4 6.3-.8z" fill="currentColor"/>',
			'clock' => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
			'out'   => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
		);
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $p[ $n ] . '</svg>';
	}

	public static function block() {
		$l     = self::links();
		$hours = self::hours();
		$g     = class_exists( 'SDSC_WhyUs' ) && method_exists( 'SDSC_WhyUs', 'google' ) ? SDSC_WhyUs::google() : null;
		$phone = class_exists( 'SDSC_Chrome' ) ? SDSC_Chrome::PHONE_MAIN : '(301) 726-3030';
		$tel   = 'tel:+1' . preg_replace( '/\D/', '', $phone );
		$id    = 'sdmap-' . wp_unique_id();

		$h  = '<div class="sdmap-wrap"><section class="sdmap" aria-labelledby="' . esc_attr( $id ) . '">';
		$h .= '<div class="sdmap-sign"><div class="sdmap-sign-in">';
		$h .= '<p class="sdmap-kicker">' . self::icon( 'pin' ) . 'Find us</p>';
		$h .= '<h2 id="' . esc_attr( $id ) . '" class="sdmap-name">' . esc_html( self::NAME ) . '</h2>';
		$h .= '<address class="sdmap-addr">' . esc_html( self::STREET ) . '<br>' . esc_html( self::CITY ) . '</address>';

		$h .= '<div class="sdmap-meta">';
		if ( $hours ) {
			$h .= '<span class="sdmap-open" data-hours="' . esc_attr( wp_json_encode( $hours ) ) . '" hidden>' . self::icon( 'clock' ) . '<b></b></span>';
		}
		if ( $g && ! empty( $g['score'] ) && ! empty( $g['count'] ) ) {
			$h .= '<a class="sdmap-rate" href="' . esc_url( $l['view'] ) . '" target="_blank" rel="noopener">' . self::icon( 'star' ) . '<b>' . esc_html( $g['score'] ) . '</b> · ' . esc_html( $g['count'] ) . ' Google reviews</a>';
		}
		$h .= '</div>';

		$h .= '<p class="sdmap-go-l">Get directions in</p><div class="sdmap-go" data-sdmap-apps>';
		$h .= '<a class="sdmap-app" data-app="google" href="' . esc_url( $l['google'] ) . '" target="_blank" rel="noopener">' . self::icon( 'nav' ) . '<span>Google Maps</span></a>';
		$h .= '<a class="sdmap-app" data-app="apple" href="' . esc_url( $l['apple'] ) . '" target="_blank" rel="noopener">' . self::icon( 'pin' ) . '<span>Apple Maps</span></a>';
		$h .= '<a class="sdmap-app" data-app="waze" href="' . esc_url( $l['waze'] ) . '" target="_blank" rel="noopener">' . self::icon( 'car' ) . '<span>Waze</span></a>';
		$h .= '</div>';

		$h .= '<div class="sdmap-sub">';
		$h .= '<button type="button" class="sdmap-copy" data-copy="' . esc_attr( self::address() ) . '">' . self::icon( 'copy' ) . '<span>Copy address</span></button>';
		$h .= '<a class="sdmap-call" href="' . esc_attr( $tel ) . '">' . self::icon( 'phone' ) . '<span>' . esc_html( $phone ) . '</span></a>';
		$h .= '</div>';

		if ( $hours ) {
			// Every day, always visible; today is highlighted and the live status sits in the header.
			$h .= '<div class="sdmap-hours"><div class="sdmap-hours-top"><p class="sdmap-hours-h">' . self::icon( 'clock' ) . 'Office hours</p><span class="sdmap-hours-now" hidden></span></div><table>';
			foreach ( $hours as $r ) {
				$h .= '<tr data-day="' . esc_attr( $r['day'] ) . '"><th scope="row">' . esc_html( $r['day'] ) . '</th><td>' . esc_html( self::fmt( $r['open'] ) . ' – ' . self::fmt( $r['close'] ) ) . '</td></tr>';
			}
			$h .= '</table></div>';
		}
		$h .= '</div></div>';

		$h .= '<div class="sdmap-frame">';
		$h .= '<iframe src="' . esc_url( $l['embed'] ) . '" title="Map: ' . esc_attr( self::NAME . ', ' . self::address() ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>';
		$h .= '<a class="sdmap-big" href="' . esc_url( $l['view'] ) . '" target="_blank" rel="noopener">' . self::icon( 'out' ) . '<span>Open larger map</span></a>';
		$h .= '</div>';
		$h .= '</section></div>';
		return $h;
	}
}
