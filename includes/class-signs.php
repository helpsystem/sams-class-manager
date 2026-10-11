<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * 3D road signs for the site's real service sections.
 *
 * The theme's Elementor widgets (ruzbahani_courses, ruzbahani_packages, ruzbahani_rental,
 * ruzbahani_services) keep their content in theme template parts. We read that output
 * (dates, prices, Stripe links stay exactly as the theme has them) and draw it again as
 * MUTCD-style signs: official shapes keep their official legend (STOP, YIELD, school
 * symbol), and the service name and price go on a supplemental plaque under the sign.
 * Word-message signs (white regulatory, green guide, yellow warning) carry our own text,
 * which is how those sign families are used on real roads.
 *
 * Turn off with: wp option update sdsc_sign3d 0
 */
class SDSC_Signs {

	/** viewBox width/height per sign family. */
	const BOX = array(
		'stop'    => array( 100, 100 ),
		'yield'   => array( 100, 87 ),
		'school'  => array( 100, 100 ),
		'warning' => array( 100, 100 ),
		'reg'     => array( 80, 100 ),
		'guide'   => array( 130, 80 ),
		'service' => array( 100, 100 ),
	);

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 6 );
		if ( '0' !== get_option( 'sdsc_sign3d', '1' ) ) {
			add_filter( 'elementor/widget/render_content', array( __CLASS__, 'replace_widget' ), 20, 2 );
		}
	}

	public static function register_assets() {
		wp_register_style( 'sdsc-fonts', 'https://fonts.googleapis.com/css2?family=Overpass:wght@500;700;800;900&display=swap', array(), null );
		wp_register_style( 'sdsc-signs', SDSC_URL . 'assets/signs3d.css', array( 'sdsc-fonts', 'sdsc-brand' ), SDSC_VERSION );
		wp_register_script( 'sdsc-signs', SDSC_URL . 'assets/signs3d.js', array(), SDSC_VERSION, true );
	}

	public static function enqueue() {
		wp_enqueue_style( 'sdsc-signs' );
		wp_enqueue_script( 'sdsc-signs' );
	}

	/* ------------------------------------------------------------------ *
	 * Elementor hook
	 * ------------------------------------------------------------------ */

	public static function replace_widget( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) { return $content; }
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) { return $content; }
		$name = $widget->get_name();
		$out  = '';
		if ( 'ruzbahani_courses' === $name ) {
			$out = self::render_courses( $content );
		} elseif ( 'ruzbahani_packages' === $name || 'ruzbahani_rental' === $name ) {
			$out = self::render_packages( $content, $name );
		} elseif ( 'ruzbahani_why_us' === $name && class_exists( 'SDSC_WhyUs' ) ) {
			$out = SDSC_WhyUs::render( $content );
		} elseif ( 'ruzbahani_services' === $name ) {
			$out = self::render_overview( self::parse_overview( $content ) );
		} elseif ( 'ruzbahani_blog' === $name && class_exists( 'SDSC_Pages' ) && SDSC_Pages::on() ) {
			self::enqueue();
			$out = SDSC_Pages::blog( $content );
		} elseif ( false !== stripos( $name, 'google' ) && false !== strpos( $content, 'ti-widget' ) && class_exists( 'SDSC_Pages' ) && SDSC_Pages::on() ) {
			self::enqueue();
			$out = SDSC_Pages::reviews( $content );
		}
		return $out ? $out : $content;
	}

	/* ------------------------------------------------------------------ *
	 * Parsing the theme's markup
	 * ------------------------------------------------------------------ */

	protected static function xpath( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $html ) ) { return null; }
		$doc = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		return new DOMXPath( $doc );
	}

	protected static function q( $xp, $expr, $ctx = null ) {
		$n = $xp->query( $expr, $ctx );
		return ( $n && $n->length ) ? $n->item( 0 ) : null;
	}

	/** Text of a node; <br> becomes a line break. */
	protected static function text( $node ) {
		if ( ! $node ) { return ''; }
		$parts = array();
		foreach ( $node->childNodes as $c ) {
			$parts[] = ( 'br' === strtolower( $c->nodeName ) ) ? "\n" : $c->textContent;
		}
		$t = preg_replace( "/[ \t\r\x{00A0}\x{200B}]+/u", ' ', implode( '', $parts ) );
		return trim( preg_replace( "/ *\n */", "\n", $t ) );
	}

	protected static function cls( $c ) {
		return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
	}

	protected static function has_class( $node, $c ) {
		return $node && $node->attributes && false !== strpos( ' ' . $node->getAttribute( 'class' ) . ' ', ' ' . $c . ' ' );
	}

	/* ------------------------------------------------------------------ *
	 * Sign drawing
	 * ------------------------------------------------------------------ */

	protected static function svg_text( $lines ) {
		$o = '';
		foreach ( $lines as $l ) {
			$o .= '<text x="' . $l[0] . '" y="' . $l[1] . '" font-size="' . $l[2] . '"' . ( isset( $l[4] ) ? ' fill="' . $l[4] . '"' : '' ) . ( isset( $l[5] ) ? ' letter-spacing="' . $l[5] . '"' : '' ) . '>' . esc_html( $l[3] ) . '</text>';
		}
		return $o;
	}

	protected static function bolt( $x, $y ) {
		return '<circle class="s3d-bolt" cx="' . $x . '" cy="' . $y . '" r="1.9"/>';
	}

	/** Outline of each sign family (used for the face, the metal edge and the back). */
	protected static function shape( $type, $inset = 0 ) {
		$i = $inset;
		switch ( $type ) {
			case 'stop':
				$a = 29.29 + $i * 0.41; $b = 70.71 - $i * 0.41;
				return '<polygon points="' . "$a,$i $b,$i " . ( 100 - $i ) . ",$a " . ( 100 - $i ) . ",$b $b," . ( 100 - $i ) . " $a," . ( 100 - $i ) . " $i,$b $i,$a" . '"/>';
			case 'yield':
				return '<polygon points="' . ( $i * 1.73 ) . ',' . $i . ' ' . ( 100 - $i * 1.73 ) . ',' . $i . ' 50,' . ( 87 - $i * 2 ) . '"/>';
			case 'school':
				return '<polygon points="50,' . ( $i * 1.2 ) . ' ' . ( 100 - $i ) . ',' . ( 38 + $i * 0.3 ) . ' ' . ( 100 - $i ) . ',' . ( 100 - $i ) . ' ' . $i . ',' . ( 100 - $i ) . ' ' . $i . ',' . ( 38 + $i * 0.3 ) . '"/>';
			case 'warning':
				$k = $i * 1.414;
				return '<polygon points="50,' . $k . ' ' . ( 100 - $k ) . ',50 50,' . ( 100 - $k ) . ' ' . $k . ',50"/>';
			default:
				list( $w, $h ) = self::BOX[ $type ];
				$r = max( 1, ( 'guide' === $type ? 7 : 6 ) - $i * 0.8 );
				return '<rect x="' . $i . '" y="' . $i . '" width="' . ( $w - 2 * $i ) . '" height="' . ( $h - 2 * $i ) . '" rx="' . $r . '"/>';
		}
	}

	/** The printed face of a sign. $o: legend options per family. */
	protected static function face( $type, $o ) {
		$s = '';
		switch ( $type ) {
			case 'stop': // R1-1: white legend and border on red. Legend is always STOP.
				$s .= '<g fill="#fff">' . self::shape( 'stop' ) . '</g><g fill="#c8102e">' . self::shape( 'stop', 3.2 ) . '</g>';
				$s .= '<g class="s3d-legend" fill="#fff" text-anchor="middle">' . self::svg_text( array( array( 50, 61.5, 31, 'STOP', '#fff', '0.5' ) ) ) . '</g>';
				$s .= self::bolt( 50, 9 ) . self::bolt( 50, 91 );
				break;
			case 'yield': // R1-2: red band, white centre, red YIELD.
				$s .= '<g fill="#c8102e">' . self::shape( 'yield' ) . '</g><g fill="#fff">' . self::shape( 'yield', 9 ) . '</g>';
				$s .= '<g class="s3d-legend" text-anchor="middle">' . self::svg_text( array( array( 50, 33, 14.5, 'YIELD', '#c8102e', '0.4' ) ) ) . '</g>';
				$s .= self::bolt( 50, 5 ) . self::bolt( 50, 71 );
				break;
			case 'school': // S1-1: fluorescent yellow-green, black border, school-children symbol.
				$s .= '<g fill="#c6e01f">' . self::shape( 'school' ) . '</g><g fill="#15191d">' . self::shape( 'school', 2.6 ) . '</g><g fill="#c6e01f">' . self::shape( 'school', 4.4 ) . '</g>';
				$s .= '<g fill="#15191d">'
					. '<circle cx="38" cy="34" r="6.2"/><path d="M31 43h13l4 22-4 1-2-9-3 0 4 33h-6l-3-21-3 21h-6l3-33h-2l-3 14-4-1 4-20z"/>'
					. '<circle cx="64" cy="44" r="5"/><path d="M58.5 51.5h11l3.5 17-3.5.8-2-7.5h-2l3 26h-5l-2.3-16-2.4 16h-5l3-26h-1.7l-2.4 11-3.4-.8z"/>'
					. '</g>';
				$s .= self::bolt( 50, 10 ) . self::bolt( 50, 94 );
				break;
			case 'warning': // W-series word message: black on yellow.
				$s .= '<g fill="#ffc20e">' . self::shape( 'warning' ) . '</g><g fill="#15191d">' . self::shape( 'warning', 2.4 ) . '</g><g fill="#ffc20e">' . self::shape( 'warning', 4.2 ) . '</g>';
				$s .= '<g class="s3d-legend" fill="#15191d" text-anchor="middle">' . self::svg_text( $o['lines'] ) . '</g>';
				$s .= self::bolt( 50, 11 ) . self::bolt( 50, 89 );
				break;
			case 'reg': // White regulatory word-message sign.
				$s .= '<g fill="#fff">' . self::shape( 'reg' ) . '</g><g fill="#15191d">' . self::shape( 'reg', 2.2 ) . '</g><g fill="#fff">' . self::shape( 'reg', 3.8 ) . '</g>';
				$s .= '<g class="s3d-legend" fill="#15191d" text-anchor="middle">' . self::svg_text( $o['lines'] ) . '</g>';
				$s .= self::bolt( 40, 8 ) . self::bolt( 40, 92 );
				break;
			case 'guide': // Green guide sign, white border and legend.
				$s .= '<g fill="#00703c">' . self::shape( 'guide' ) . '</g><g fill="#fff">' . self::shape( 'guide', 2.4 ) . '</g><g fill="#00703c">' . self::shape( 'guide', 4.2 ) . '</g>';
				$s .= '<g class="s3d-legend" fill="#fff" text-anchor="middle">' . self::svg_text( $o['lines'] ) . '</g>';
				if ( ! empty( $o['arrow'] ) ) { $s .= '<path fill="#fff" d="M65 58l-7 7h4.5v8h5v-8H72z"/>'; }
				$s .= self::bolt( 30, 8 ) . self::bolt( 100, 8 ) . self::bolt( 30, 72 ) . self::bolt( 100, 72 );
				break;
			case 'service': // Blue general-service sign with white symbol.
				$s .= '<g fill="#0b5cab">' . self::shape( 'service' ) . '</g><g fill="#fff">' . self::shape( 'service', 2.6 ) . '</g><g fill="#0b5cab">' . self::shape( 'service', 4.6 ) . '</g>';
				$s .= self::symbol( $o['symbol'] ?? 'info' );
				$s .= self::bolt( 50, 9 ) . self::bolt( 50, 91 );
				break;
		}
		return $s;
	}

	protected static function symbol( $name ) {
		if ( 'laptop' === $name ) {
			return '<g fill="#fff"><rect x="26" y="28" width="48" height="32" rx="2.5"/><path d="M18 64h64l-5 8H23z"/></g>'
				. '<rect x="30" y="32" width="40" height="24" fill="#0b5cab"/>'
				. '<g fill="#fff"><circle cx="50" cy="40" r="4"/><path d="M42 53c0-5 3.6-8 8-8s8 3 8 8z"/></g>';
		}
		return '<g fill="#fff"><circle cx="50" cy="28" r="7"/><rect x="43" y="40" width="14" height="36" rx="2"/></g>';
	}

	/**
	 * One sign on its post, with plaques.
	 * $plaques: list of array( 'lines' => array( ... ), 'strong' => index of the line to emphasise ).
	 */
	public static function sign( $type, $opts = array(), $plaques = array(), $width = 150 ) {
		list( $w, $h ) = self::BOX[ $type ];
		$vb   = "0 0 $w $h";
		$out  = '<div class="s3d s3d--' . esc_attr( $type ) . '" style="--w:' . (int) $width . 'px;--ar:' . $w . '/' . $h . '" aria-hidden="true">';
		$out .= '<div class="s3d-tilt"><div class="s3d-assembly">';
		$out .= '<span class="s3d-post"></span>';
		$out .= '<div class="s3d-sign">';
		$out .= '<svg class="s3d-back" viewBox="' . $vb . '">' . self::shape( $type ) . '</svg>';
		for ( $z = 1; $z <= 5; $z++ ) {
			$out .= '<svg class="s3d-edge" style="--z:' . $z . '" viewBox="' . $vb . '">' . self::shape( $type ) . '</svg>';
		}
		$out .= '<svg class="s3d-face" viewBox="' . $vb . '">' . self::face( $type, $opts ) . '</svg>';
		$out .= '<span class="s3d-sheen"></span>';
		$out .= '</div>';
		foreach ( $plaques as $p ) {
			$out .= '<div class="s3d-plaque">';
			foreach ( $p['lines'] as $i => $line ) {
				$strong = isset( $p['strong'] ) && $p['strong'] === $i;
				$out   .= '<span' . ( $strong ? ' class="is-big"' : '' ) . '>' . esc_html( $line ) . '</span>';
			}
			$out .= '</div>';
		}
		$out .= '</div></div><span class="s3d-ground"></span></div>';
		return $out;
	}

	/** Sign templates Saghar can choose from (Class Manager → Website listing). */
	const SIGN_STYLES = array(
		'vms'     => array( 'Highway message sign', 'Electronic amber LED board with flashing beacons; the message changes like a real road message sign.' ),
		'exit'    => array( 'Green highway exit sign', 'Interstate guide sign with an EXIT tab for the course code, a yellow panel for seats and price, and sign lights.' ),
		'school'  => array( 'School crossing sign', 'Fluorescent yellow-green school sign with a date plaque underneath.' ),
		'marquee' => array( 'Night marquee board', 'Lit board with chasing bulbs and neon lettering, with a traffic light for seats.' ),
	);

	public static function sign_style() {
		$s = (string) get_option( 'sdsc_class_sign', 'vms' );
		// Admins can preview another template on the live page: ?sdsc_sign=exit
		if ( isset( $_GET['sdsc_sign'] ) && current_user_can( 'manage_options' ) ) { $s = sanitize_key( wp_unslash( $_GET['sdsc_sign'] ) ); } // phpcs:ignore WordPress.Security.NonceVerification
		return isset( self::SIGN_STYLES[ $s ] ) ? $s : 'vms';
	}

	/** Builds the chosen sign for one class. $c is a class item (feed or theme), $dates/$time are the short lines. */
	public static function class_sign( $c, $dates, $time, $style = '' ) {
		$style = $style ? $style : self::sign_style();
		$c     = wp_parse_args( $c, array( 'code' => '', 'name' => '', 'price' => '', 'now' => '', 'd' => null, 'low' => false ) );
		if ( 'school' === $style ) {
			$plaques = array( array( 'lines' => array_filter( array( $dates, $time ) ), 'strong' => 0 ) );
			if ( $c['d'] ) { $plaques[] = array( 'lines' => array( $c['d']['pct'] . '% OFF', $c['now'] ), 'strong' => 1 ); }
			return self::sign( 'school', array(), $plaques, 150 );
		}
		if ( 'marquee' === $style ) { return self::marquee( $c, $dates, $time ); }
		if ( 'exit' === $style ) { return self::exit_sign( $c, $dates, $time ); }
		return self::vms( $c, $dates, $time );
	}

	/** Shared facts for the sign builders. */
	protected static function sign_facts( $c ) {
		$left  = isset( $c['left'] ) ? (int) $c['left'] : -1;
		$state = 0 === $left ? 'full' : ( ! empty( $c['low'] ) ? 'low' : 'open' );
		return array(
			'state' => $state,
			'label' => 'full' === $state ? 'Class full' : ( 'low' === $state ? $left . ' seat' . ( 1 === $left ? '' : 's' ) . ' left' : 'Seats open' ),
			'shift' => preg_match( '/\b(AM|PM)\b/', (string) $c['name'], $m ) ? $m[1] : '',
			'price' => ! empty( $c['d'] ) ? $c['now'] : $c['price'],
			'old'   => ! empty( $c['d'] ) ? $c['price'] : '',
		);
	}

	protected static function mini_light( $class ) {
		return '<span class="' . esc_attr( $class ) . '"><i></i><i></i><i></i></span>';
	}

	/**
	 * Variable message sign (the electronic board on highways): amber LED text on a black screen,
	 * two flashing beacons on top, and two message "phases" that alternate like the real thing.
	 */
	protected static function vms( $c, $dates, $time ) {
		$f  = self::sign_facts( $c );
		$o  = '<div class="sdvms sdvms--' . $f['state'] . '" aria-hidden="true">';
		$o .= '<span class="sdvms-beacon sdvms-beacon--l"></span><span class="sdvms-beacon sdvms-beacon--r"></span>';
		$o .= '<div class="sdvms-box"><div class="sdvms-screen">';
		$o .= '<div class="sdvms-phase sdvms-phase--a"><p>Driver&rsquo;s Ed' . ( $f['shift'] ? ' ' . esc_html( $f['shift'] ) : '' ) . '</p>' . ( $dates ? '<p class="is-big">' . esc_html( $dates ) . '</p>' : '' ) . ( $time ? '<p>' . esc_html( $time ) . '</p>' : '' ) . '</div>';
		$o .= '<div class="sdvms-phase sdvms-phase--b"><p>' . ( $c['code'] ? 'Course ' . esc_html( $c['code'] ) : 'Enroll now' ) . '</p><p class="is-big sdvms-status">' . esc_html( $f['label'] ) . '</p>' . ( $f['price'] ? '<p>' . ( $f['old'] ? 'Now ' : '' ) . esc_html( $f['price'] ) . '</p>' : '' ) . '</div>';
		$o .= '</div>' . self::mini_light( 'sdvms-tl sdsl sdsl--' . $f['state'] ) . '</div>';
		$o .= '<span class="sdvms-post sdvms-post--l"></span><span class="sdvms-post sdvms-post--r"></span><span class="sdvms-ground"></span></div>';
		return $o;
	}

	/**
	 * Green interstate guide sign: EXIT tab with the course code, white legend in the highway typeface,
	 * a yellow "exit only" style panel with seats and price, sign lights underneath and a reflective glint.
	 */
	protected static function exit_sign( $c, $dates, $time ) {
		$f  = self::sign_facts( $c );
		$o  = '<div class="sdex sdex--' . $f['state'] . '" aria-hidden="true"><div class="sdex-sign">';
		if ( $c['code'] ) { $o .= '<span class="sdex-tab">Exit ' . esc_html( preg_replace( '/^C-?/i', '', $c['code'] ) ) . '</span>'; }
		$o .= '<div class="sdex-panel"><div class="sdex-in">';
		$o .= '<p class="sdex-l1">Driver&rsquo;s Ed' . ( $f['shift'] ? ' <small>' . esc_html( $f['shift'] ) . '</small>' : '' ) . '</p>';
		if ( $dates ) { $o .= '<p class="sdex-l2">' . esc_html( ucwords( strtolower( $dates ) ) ) . '</p>'; }
		if ( $time ) { $o .= '<p class="sdex-l3">' . esc_html( $time ) . '</p>'; }
		$o .= '<svg class="sdex-arrow" viewBox="0 0 40 40"><path d="M20 3 L35 19 H25 V37 H15 V19 H5 Z" fill="#fff"/></svg>';
		$o .= '</div><div class="sdex-only">' . self::mini_light( 'sdex-tl sdsl sdsl--' . $f['state'] ) . '<span class="sdex-status">' . esc_html( $f['label'] ) . '</span>' . ( $f['price'] ? '<span class="sdex-price">' . ( $f['old'] ? '<s>' . esc_html( $f['old'] ) . '</s>' : '' ) . esc_html( $f['price'] ) . '</span>' : '' ) . '</div>';
		$o .= '<span class="sdex-glint"></span></div>';
		$o .= '<span class="sdex-lamp sdex-lamp--l"></span><span class="sdex-lamp sdex-lamp--r"></span></div>';
		$o .= '<span class="sdex-post sdex-post--l"></span><span class="sdex-post sdex-post--r"></span><span class="sdex-ground"></span></div>';
		return $o;
	}

	/**
	 * Class listing sign: a night-lit marquee board on two posts, with chasing bulbs around the frame,
	 * neon lettering for the dates, and a small traffic light for the seats (green open, yellow few left,
	 * red full). Decorative: the card under it carries the same facts as text.
	 */
	public static function marquee( $c, $dates, $time ) {
		$left  = isset( $c['left'] ) ? (int) $c['left'] : -1;
		$state = 0 === $left ? 'full' : ( ! empty( $c['low'] ) ? 'low' : 'open' );
		$label = 'full' === $state ? 'Class full' : ( 'low' === $state ? $left . ' seat' . ( 1 === $left ? '' : 's' ) . ' left' : 'Seats open' );
		$top   = 'Driver&rsquo;s Ed' . ( preg_match( '/\b(AM|PM)\b/', (string) $c['name'], $m ) ? ' &middot; ' . $m[1] : '' );
		$price = ! empty( $c['d'] ) ? $c['now'] : $c['price'];
		$bulbs = function ( $side, $n ) { return '<span class="sdmq-b sdmq-b--' . $side . '">' . str_repeat( '<i></i>', $n ) . '</span>'; };
		$o  = '<div class="sdmq sdmq--' . $state . '" aria-hidden="true"><div class="sdmq-board">';
		$o .= $bulbs( 't', 15 ) . $bulbs( 'b', 15 ) . $bulbs( 'l', 5 ) . $bulbs( 'r', 5 );
		$o .= '<div class="sdmq-face">';
		$o .= '<p class="sdmq-top"><span class="sdmq-neon">' . $top . '</span>' . ( ! empty( $c['code'] ) ? '<span class="sdmq-code">' . esc_html( $c['code'] ) . '</span>' : '' ) . '</p>';
		if ( $dates ) { $o .= '<p class="sdmq-dates">' . esc_html( $dates ) . '</p>'; }
		if ( $time ) { $o .= '<p class="sdmq-time">' . esc_html( $time ) . '</p>'; }
		$o .= '<div class="sdmq-row"><span class="sdmq-tl"><i></i><i></i><i></i></span><span class="sdmq-status">' . esc_html( $label ) . '</span>';
		if ( $price ) { $o .= '<span class="sdmq-price">' . ( ! empty( $c['d'] ) ? '<s>' . esc_html( $c['price'] ) . '</s>' : '' ) . esc_html( $price ) . '</span>'; }
		$o .= '</div></div></div><span class="sdmq-post sdmq-post--l"></span><span class="sdmq-post sdmq-post--r"></span><span class="sdmq-ground"></span></div>';
		return $o;
	}

	/** Attach a live discount (if any) to a parsed item: sets now, d and swaps the checkout link. */
	protected static function discount( $item, $key ) {
		$d = class_exists( 'SDSC_Discounts' ) ? SDSC_Discounts::apply( $key, $item['price'], $item['url'] ) : null;
		$item['d']   = $d;
		$item['now'] = $d ? $d['new'] : $item['price'];
		if ( $d && $d['url'] ) { $item['url'] = $d['url']; }
		return $item;
	}

	/** Price for a caption card: plain, or old crossed out + new + badge. */
	protected static function price_html( $item ) {
		if ( empty( $item['d'] ) ) { return '<span class="sdsc3d-price">' . esc_html( $item['price'] ) . '</span>'; }
		$d = $item['d'];
		return '<span class="sdsc3d-price sdsc3d-price--sale"><s aria-label="Was ' . esc_attr( $d['old'] ) . '">' . esc_html( $d['old'] ) . '</s> ' . esc_html( $d['new'] )
			. ' <em class="sdsc3d-off">' . esc_html( $d['pct'] ) . '% OFF</em>'
			. ( $d['ends'] ? '<small class="sdsc3d-ends">Offer ends ' . esc_html( $d['ends'] ) . '</small>' : '' ) . '</span>';
	}

	/* ------------------------------------------------------------------ *
	 * Courses: Driver's Education Program (PM Class)
	 * ------------------------------------------------------------------ */

	protected static function short_dates( $s ) {
		$months = array( 'January' => 'JAN', 'February' => 'FEB', 'March' => 'MAR', 'April' => 'APR', 'May' => 'MAY', 'June' => 'JUN', 'July' => 'JUL', 'August' => 'AUG', 'September' => 'SEP', 'October' => 'OCT', 'November' => 'NOV', 'December' => 'DEC' );
		$time   = '';
		if ( preg_match( '/^(.*?)\s*\((.*?)\)\s*$/', $s, $m ) ) {
			$s    = $m[1];
			$time = strtoupper( str_replace( array( '.', ' ', '-' ), array( '', '', '–' ), $m[2] ) );
		}
		$s = strtr( $s, $months );
		$s = preg_replace( '/\s*-\s*/', ' – ', $s );
		return array( strtoupper( trim( $s ) ), $time );
	}

	public static function render_courses( $html ) {
		$xp = self::xpath( $html );
		if ( ! $xp ) { return ''; }
		$head = self::q( $xp, '//*[' . self::cls( 'heading-top' ) . ']' );
		$boxes = $xp->query( '//*[' . self::cls( 'singleBox' ) . ']' );
		if ( ! $head || ! $boxes || ! $boxes->length ) { return ''; }

		$title = self::text( self::q( $xp, './/h2', $head ) );
		$intro = array();
		$attn  = array();
		$after = false;
		foreach ( $xp->query( './h3|./p', $head ) as $n ) {
			if ( 'h3' === $n->nodeName ) { $after = true; continue; }
			$t = self::text( $n );
			if ( '' === $t ) { continue; }
			if ( $after ) { $attn[] = $t; } else { $intro[] = $t; }
		}

		$classes = array();
		foreach ( $boxes as $b ) {
			$link = self::q( $xp, './/a[@href]', $b );
			$classes[] = array(
				'when'  => self::text( self::q( $xp, './/*[' . self::cls( 'learnerCourse' ) . ']//h3', $b ) ),
				'name'  => self::text( self::q( $xp, './/h4', $b ) ),
				'desc'  => self::text( self::q( $xp, './/*[' . self::cls( 'advText' ) . ']//p', $b ) ),
				'price' => self::text( self::q( $xp, './/*[' . self::cls( 'discount-price1' ) . ']', $b ) ),
				'url'   => $link ? $link->getAttribute( 'href' ) : '',
				'cta'   => $link ? self::text( $link ) : 'Enroll Now',
			);
		}
		$schema = '';
		$feed   = class_exists( 'SDSC_Class_Feed' ) && SDSC_Class_Feed::on();
		if ( $feed ) {
			// Classes come from Class Manager; the theme's own description is the fallback text.
			$fallback = $classes ? $classes[0]['desc'] : '';
			$classes  = SDSC_Class_Feed::items( $fallback );
			$schema   = SDSC_Class_Feed::schema( $classes, $fallback );
		}
		if ( ! $feed && class_exists( 'SDSC_Prices' ) ) {
			$classes = array_map( function ( $c ) { return SDSC_Prices::apply_item( 'course', $c ); }, $classes );
		}
		$classes = array_map( function ( $c ) use ( $feed ) {
			$url = $c['url'];
			$c   = self::discount( $c, 'course' );
			if ( $feed ) {
				// The registration form sends students to the class's own payment link, so a discount
				// that needs a different Stripe link cannot be honoured here: show it only when the
				// price was changed on the existing links (no new link entered).
				if ( $c['d'] && ! empty( $c['d']['url'] ) ) { $c['d'] = null; $c['now'] = $c['price']; }
				$c['url'] = $url;
			}
			return $c;
		}, $classes );

		self::enqueue();
		$style = self::sign_style();
		ob_start();
		?>
		<section class="sdsc3d sdsc3d--courses" aria-labelledby="sdsc3d-courses-h">
			<div class="sdsc3d-head">
				<p class="sdsc3d-kicker">36-hour course · Required by the MVA</p>
				<h2 id="sdsc3d-courses-h"><?php echo esc_html( $title ); ?></h2>
				<?php foreach ( $intro as $p ) : ?><p><?php echo esc_html( $p ); ?></p><?php endforeach; ?>
			</div>
			<?php if ( $attn ) : ?>
				<div class="sdsc3d-attn" role="note">
					<span class="sdsc3d-attn-sign" aria-hidden="true"><svg viewBox="0 0 100 100"><polygon points="50,2 98,50 50,98 2,50" fill="#ffc20e"/><polygon points="50,6 94,50 50,94 6,50" fill="none" stroke="#15191d" stroke-width="3"/><text x="50" y="66" font-size="46" text-anchor="middle" fill="#15191d" font-weight="900">!</text></svg></span>
					<div><strong>Attention</strong><?php foreach ( $attn as $p ) : ?><p><?php echo esc_html( $p ); ?></p><?php endforeach; ?></div>
				</div>
			<?php endif; ?>
			<ul class="sdsc3d-grid sdsc3d-grid--courses">
				<?php if ( $feed && ! $classes ) : ?>
					<li class="sdsc3d-item sdsc3d-item--empty"><div class="sdsc3d-card"><h3>New classes are being scheduled</h3><p>Call us and we will hold a seat for you in the next class.</p><div class="sdsc3d-foot"><a class="sdsc3d-cta" href="tel:+13017263030">Call (301) 726-3030</a></div></div></li>
				<?php endif; ?>
				<?php foreach ( $classes as $c ) :
					if ( isset( $c['dates'] ) ) { $dates = $c['dates']; $time = $c['time']; } else { list( $dates, $time ) = self::short_dates( $c['when'] ); }
					?>
					<li class="sdsc3d-item sdsc3d-item--<?php echo esc_attr( $style ); ?>">
						<?php echo self::class_sign( $c, $dates, $time, $style ); // phpcs:ignore -- escaped in the sign builders ?>
						<div class="sdsc3d-card">
							<p class="sdsc3d-when"><?php echo esc_html( $c['when'] ); ?></p>
							<h3><?php echo esc_html( $c['name'] ); ?></h3>
							<?php if ( ! empty( $c['code'] ) || isset( $c['left'] ) ) : ?>
								<p class="sdsc3d-meta">
									<?php if ( ! empty( $c['code'] ) ) : ?><span class="sdsc3d-code">Course <?php echo esc_html( $c['code'] ); ?></span><?php endif; ?>
									<?php if ( isset( $c['left'] ) && 0 === $c['left'] ) : ?><span class="sdsc3d-seats is-full">Class full</span>
									<?php elseif ( ! empty( $c['low'] ) ) : ?><span class="sdsc3d-seats is-low">Only <?php echo (int) $c['left']; ?> seat<?php echo 1 === $c['left'] ? '' : 's'; ?> left</span><?php endif; ?>
								</p>
							<?php endif; ?>
							<p><?php echo esc_html( $c['desc'] ); ?></p>
							<div class="sdsc3d-foot">
								<?php echo self::price_html( $c ); // phpcs:ignore -- escaped in helper ?>
								<?php if ( $c['url'] ) : ?><a class="sdsc3d-cta" href="<?php echo esc_url( $c['url'] ); ?>"<?php echo $feed ? '' : ' target="_blank" rel="noopener"'; ?>><?php echo esc_html( $c['cta'] ); ?></a>
								<?php elseif ( $feed ) : ?><a class="sdsc3d-cta sdsc3d-cta--ghost" href="tel:+13017263030">Call for next class</a><?php endif; ?>
							</div>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php echo $schema; // phpcs:ignore -- JSON built with wp_json_encode ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 * Packages: 3-hour online, road-test car, behind-the-wheel packages
	 * ------------------------------------------------------------------ */

	public static function render_packages( $html, $widget = 'ruzbahani_packages' ) {
		$xp = self::xpath( $html );
		if ( ! $xp ) { return ''; }
		$nodes = $xp->query( '//*[' . self::cls( 'service-sign' ) . ' or ' . self::cls( 'service-signserv2' ) . ']' );
		if ( ! $nodes || ! $nodes->length ) { return ''; }
		$title = self::text( self::q( $xp, '//*[' . self::cls( 'roserv-title' ) . ']' ) );

		$featured = array();
		$btw      = array();
		foreach ( $nodes as $n ) {
			$link = self::q( $xp, './/a[@href]', $n );
			$item = array(
				'title' => str_replace( "\n", ' ', self::text( self::q( $xp, './/*[' . self::cls( 'service-title' ) . ']', $n ) ) ),
				'price' => self::text( self::q( $xp, './/*[' . self::cls( 'discounted-price' ) . ']', $n ) ),
				'desc'  => self::text( self::q( $xp, './/*[' . self::cls( 'service-desc' ) . ']', $n ) ),
				'url'   => $link ? $link->getAttribute( 'href' ) : '',
				'cta'   => $link ? self::text( $link ) : 'Book Now',
			);
			if ( preg_match( '/(\d+)\s*Lessons?\s*\n?\s*(\d+)\s*Hours?/i', $item['desc'], $m ) ) {
				$item['lessons'] = (int) $m[1];
				$item['hours']   = (int) $m[2];
				if ( class_exists( 'SDSC_Prices' ) ) { $item = SDSC_Prices::apply_item( 'btw_' . $item['lessons'], $item, 'Behind-the-wheel — ' . $item['lessons'] . ' lesson' . ( 1 === $item['lessons'] ? '' : 's' ) . ' (' . $item['hours'] . ' hours)' ); }
				$btw[]           = self::discount( $item, 'btw' );
			} else {
				$pkey = preg_match( '/online|3-hour/i', $item['title'] . ' ' . $item['desc'] ) ? 'online' : 'rental';
				if ( class_exists( 'SDSC_Prices' ) ) { $item = SDSC_Prices::apply_item( $pkey, $item ); }
				$featured[] = self::discount( $item, $pkey );
			}
		}

		self::enqueue();
		ob_start();
		?>
		<section class="sdsc3d sdsc3d--packages" aria-labelledby="sdsc3d-pk-<?php echo esc_attr( $widget ); ?>">
			<div class="sdsc3d-head<?php echo strlen( $title ) > 60 ? ' sdsc3d-head--long' : ''; ?>">
				<p class="sdsc3d-kicker"><?php echo 'ruzbahani_rental' === $widget ? 'MVA road test' : 'Practice · Road test · Online'; ?></p>
				<h2 id="sdsc3d-pk-<?php echo esc_attr( $widget ); ?>"><?php echo esc_html( $title ? $title : 'Rental car, extra practice and online course' ); ?></h2>
			</div>
			<?php if ( $featured ) : ?>
				<ul class="sdsc3d-grid sdsc3d-grid--featured">
					<?php foreach ( $featured as $f ) :
						$is_online = (bool) preg_match( '/online|3-hour/i', $f['title'] . ' ' . $f['desc'] );
						if ( $is_online ) {
							$sign = self::sign( 'service', array( 'symbol' => 'laptop' ), array( array( 'lines' => array_values( array_filter( array( 'ONLINE 3-HOUR', 'ROADWAY SAFETY', $f['now'], $f['d'] ? $f['d']['pct'] . '% OFF' : '' ) ) ), 'strong' => 2 ) ), 140 );
						} else {
							$sign = self::sign( 'guide', array( 'lines' => array( array( 65, 27, 15, 'ROAD TEST' ), array( 65, 47, 15, 'CAR RENTAL' ) ), 'arrow' => true ), array( array( 'lines' => array_values( array_filter( array( 'MVA TEST CAR', $f['now'], $f['d'] ? $f['d']['pct'] . '% OFF' : '' ) ) ), 'strong' => 1 ) ), 190 );
						}
						?>
						<li class="sdsc3d-item">
							<?php echo $sign; // phpcs:ignore -- escaped in sign() ?>
							<div class="sdsc3d-card">
								<h3><?php echo esc_html( $f['title'] ); ?></h3>
								<p><?php echo esc_html( str_replace( "\n", ' ', $f['desc'] ) ); ?></p>
								<div class="sdsc3d-foot">
									<?php echo self::price_html( $f ); // phpcs:ignore -- escaped in helper ?>
									<?php if ( $f['url'] ) : ?><a class="sdsc3d-cta" href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $f['cta'] ); ?></a><?php endif; ?>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $btw ) : ?>
				<div class="sdsc3d-sub">
					<h3>Behind-the-wheel practice</h3>
					<p>Two-hour lessons with a certified instructor in a dual-control car. Pick the number of hours you need.</p>
				</div>
				<ul class="sdsc3d-grid sdsc3d-grid--btw">
					<?php foreach ( $btw as $b ) :
						$big   = (string) $b['hours'];
						$size  = strlen( $big ) > 1 ? 40 : 46;
						$lines = array( array( 40, 22, 11.5, 'PRACTICE' ), array( 40, 64, $size, $big, null, '-1' ), array( 40, 84, 11.5, 'HOURS' ) );
						$pl    = array( array( 'lines' => array( $b['lessons'] . ' ' . ( 1 === $b['lessons'] ? 'LESSON' : 'LESSONS' ), $b['now'] ), 'strong' => 1 ) );
						?>
						<li class="sdsc3d-item sdsc3d-item--btw">
							<?php echo self::sign( 'reg', array( 'lines' => $lines ), $pl, 104 ); // phpcs:ignore -- escaped in sign() ?>
							<?php if ( $b['d'] ) : ?><span class="sdsc3d-mini-sale"><s><?php echo esc_html( $b['d']['old'] ); ?></s> <?php echo esc_html( $b['d']['pct'] ); ?>% OFF</span><?php endif; ?>
							<?php if ( $b['url'] ) : ?><a class="sdsc3d-cta sdsc3d-cta--sm" href="<?php echo esc_url( $b['url'] ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( sprintf( 'Book %d hours of behind-the-wheel practice for %s', $b['hours'], $b['now'] ) ); ?>"><?php echo esc_html( $b['cta'] ); ?></a><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 * Services overview (the road with all services)
	 * ------------------------------------------------------------------ */

	public static function overview_defaults() {
		return array(
			array( 'title' => '36 Hours Ed Course', 'desc' => 'Required by MVA' ),
			array( 'title' => 'Extra BTW Practice', 'desc' => 'From 2 to 60 Hours' ),
			array( 'title' => 'Road Test Practice', 'desc' => 'Special classes' ),
			array( 'title' => 'Nervous Drivers', 'desc' => 'Special classes' ),
			array( 'title' => 'Car Rental', 'desc' => 'For road Test' ),
			array( 'title' => 'Drug & Alcohol', 'desc' => '3 Hours program' ),
			array( 'title' => 'Driver Improvement', 'desc' => 'Special classes' ),
		);
	}

	public static function parse_overview( $html ) {
		$xp = self::xpath( $html );
		if ( ! $xp ) { return array(); }
		$items = array();
		foreach ( $xp->query( '//*[' . self::cls( 'service-sign' ) . ']' ) as $n ) {
			$items[] = array(
				'title' => str_replace( "\n", ' ', self::text( self::q( $xp, './/*[' . self::cls( 'service-title' ) . ']', $n ) ) ),
				'desc'  => self::text( self::q( $xp, './/*[' . self::cls( 'service-desc' ) . ']', $n ) ),
			);
		}
		return $items;
	}

	/** Which sign, plaque, link and blurb each service gets. */
	protected static function overview_sign( $item ) {
		$t    = strtolower( $item['title'] );
		$tel  = 'tel:+13017263030';
		$reg  = home_url( '/register-course/' );
		$plq  = function ( $a, $b = '' ) { return array( array( 'lines' => array_filter( array( strtoupper( $a ), strtoupper( $b ) ) ) ) ); };
		if ( false !== strpos( $t, '36' ) || false !== strpos( $t, 'ed course' ) ) {
			return array( 'school', array(), $plq( "Driver's Ed", '36 hours' ), 'Ten evenings on Zoom plus 6 hours behind the wheel, for teens and adults.', 'See class dates', home_url( '/services/' ) );
		}
		if ( false !== strpos( $t, 'btw' ) || false !== strpos( $t, 'practice' ) && false === strpos( $t, 'road test' ) ) {
			return array( 'reg', array( 'lines' => array( array( 40, 26, 12, 'BEHIND' ), array( 40, 41, 12, 'THE' ), array( 40, 56, 12, 'WHEEL' ), array( 40, 79, 15, '2–60 H' ) ) ), $plq( 'Practice', 'lessons' ), 'Two-hour lessons in a dual-control car, from 1 to 30 lessons.', 'Choose hours', home_url( '/services/' ) );
		}
		if ( false !== strpos( $t, 'road test' ) ) {
			return array( 'warning', array( 'lines' => array( array( 50, 43, 14, 'ROAD' ), array( 50, 58, 14, 'TEST' ), array( 50, 70, 9.5, 'AHEAD' ) ) ), $plq( 'Road test', 'practice' ), 'We drive the MVA test route with you until it feels routine.', 'Call us', $tel );
		}
		if ( false !== strpos( $t, 'nervous' ) ) {
			return array( 'service', array( 'symbol' => 'info' ), $plq( 'Nervous', 'drivers' ), 'Patient instructors, quiet streets first, and a pace set by you.', 'Call us', $tel );
		}
		if ( false !== strpos( $t, 'rental' ) ) {
			return array( 'guide', array( 'lines' => array( array( 65, 27, 15, 'ROAD TEST' ), array( 65, 47, 15, 'CAR RENTAL' ) ), 'arrow' => true ), $plq( 'For your', 'MVA test' ), 'Take your MVA road test in our insured school car.', 'Reserve', class_exists( 'SDSC_Prices' ) ? SDSC_Prices::url( 'rental', 'https://buy.stripe.com/6oU9ATblYgyhdmNglxaMU1r' ) : 'https://buy.stripe.com/6oU9ATblYgyhdmNglxaMU1r' );
		}
		if ( false !== strpos( $t, 'alcohol' ) || false !== strpos( $t, 'drug' ) ) {
			return array( 'stop', array(), $plq( 'Alcohol & drug', '3-hour program' ), 'The 3-hour alcohol and drug education program the MVA requires.', 'Call us', $tel );
		}
		if ( false !== strpos( $t, 'improvement' ) ) {
			return array( 'yield', array(), $plq( 'Driver', 'improvement' ), 'For drivers assigned the program by the MVA or a court.', 'Call us', $tel );
		}
		return array( 'service', array( 'symbol' => 'info' ), $plq( $item['title'] ), $item['desc'], 'Call us', $tel );
	}

	public static function render_overview( $items ) {
		if ( ! $items ) { $items = self::overview_defaults(); }
		self::enqueue();
		wp_enqueue_style( 'sdsc-services' );
		wp_enqueue_script( 'sdsc-services' );
		$widths = array( 'school' => 132, 'reg' => 104, 'warning' => 140, 'service' => 120, 'guide' => 178, 'stop' => 132, 'yield' => 146 );
		ob_start();
		?>
		<section class="sds-road sdsc3d sdsc3d--overview" aria-labelledby="sdsc3d-ov-h">
			<div class="sdsc3d-head">
				<p class="sdsc3d-kicker">Our driving school services</p>
				<h2 id="sdsc3d-ov-h">Every road to your license</h2>
			</div>
			<ul class="sds-signs sdsc3d-grid sdsc3d-grid--overview">
				<?php foreach ( $items as $i => $it ) :
					list( $type, $opts, $plaques, $blurb, $cta, $url ) = self::overview_sign( $it );
					?>
					<li class="sds-sign-slot sdsc3d-item" style="--i:<?php echo (int) $i; ?>">
						<div class="sdsc3d-rise"><?php echo self::sign( $type, $opts, $plaques, $widths[ $type ] ); // phpcs:ignore -- escaped in sign() ?></div>
						<div class="sdsc3d-card sds-sign-card">
							<h3><?php echo esc_html( $it['title'] ); ?></h3>
							<p class="sdsc3d-tag"><?php echo esc_html( $it['desc'] ); ?></p>
							<p><?php echo esc_html( $blurb ); ?></p>
							<div class="sdsc3d-foot"><a class="sdsc3d-cta sdsc3d-cta--ghost" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $cta ); ?></a></div>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="sds-asphalt" aria-hidden="true">
				<span class="sds-lane"></span>
				<?php echo SDSC_Brand::car( 'left', 'sds-road-car' ); // phpcs:ignore -- escaped in helper ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}
}
