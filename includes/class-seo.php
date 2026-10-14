<?php
/**
 * What Google reads about the school (homepage title, description, local-business data) and
 * which pages it should not index. Facts come from the site's own settings:
 * address and opening hours from Rank Math (Titles & Meta → Local SEO), phones from the header,
 * social links from the theme. Nothing here is typed in by hand twice.
 */
defined( 'ABSPATH' ) || exit;

class SDSC_Seo {

	const TITLE = 'Affordable Driving School in Rockville, MD | MVA-Approved | Sam\'s Driving School';
	const DESC  = 'MVA-approved driving school in Rockville, MD. 36-hour driver\'s ed (30 hours of class on Zoom + 6 hours behind the wheel), driving lessons and a road test car. Serving Montgomery County. Call (301) 726-3030.';

	public static function init() {
		if ( is_admin() ) { return; }
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'title' ), 20 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'doc_title' ), 30 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'description' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( __CLASS__, 'description' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/og_title', array( __CLASS__, 'title' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'robots' ), 20 );
		add_filter( 'rank_math/sitemap/exclude_post_type', array( __CLASS__, 'sitemap_exclude' ), 20, 2 );
		add_filter( 'rank_math/sitemap/posts_to_exclude', array( __CLASS__, 'private_ids' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'local_business' ), 30 );
		add_action( 'template_redirect', array( __CLASS__, 'one_description' ), 0 );
	}

	public static function title( $t ) { return is_front_page() ? self::TITLE : $t; }
	public static function doc_title( $t ) { return is_front_page() ? self::TITLE : $t; }
	public static function description( $d ) { return is_front_page() ? self::DESC : $d; }

	/** Quiz pages are short test screens, not content for Google. */
	public static function robots( $r ) {
		if ( is_singular( array( 'tutor_quiz', 'tutor_assignments' ) ) || ( is_page() && in_array( get_queried_object_id(), self::private_ids( array() ), true ) ) ) {
			$r['index'] = 'noindex';
		}
		return $r;
	}

	/** Account, cart and checkout pages: nothing for Google there (they only redirect or ask to log in). */
	public static function private_ids( $ids ) {
		$out = array();
		foreach ( array( 'checkout', 'cart', 'my-account', 'dashboard', 'student-driving-portal', 'instructor-portal' ) as $slug ) {
			$p = get_page_by_path( $slug );
			if ( $p ) { $out[] = (int) $p->ID; }
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $k ) { $id = (int) wc_get_page_id( $k ); if ( $id > 0 ) { $out[] = $id; } }
		}
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), $out ) ) );
	}

	public static function sitemap_exclude( $exclude, $type ) {
		return in_array( $type, array( 'tutor_quiz', 'tutor_assignments' ), true ) ? true : $exclude;
	}

	/**
	 * The theme prints its own <meta name="description"> (the site tagline) next to the one from
	 * Rank Math, so Google sees two. Keep only Rank Math's.
	 */
	public static function one_description() {
		if ( ! defined( 'RANK_MATH_VERSION' ) || is_feed() || wp_doing_ajax() ) { return; }
		$tag = get_bloginfo( 'description' );
		if ( '' === trim( (string) $tag ) ) { return; }
		$needle = '<meta name="description" content="' . get_bloginfo( 'description', 'display' ) . '">';
		ob_start( function ( $html ) use ( $needle ) {
			$i = strpos( $html, '</head>' );
			if ( false === $i ) { return $html; }
			$head = substr( $html, 0, $i );
			if ( substr_count( $head, 'name="description"' ) < 2 ) { return $html; }
			return str_replace( $needle, '', $head ) . substr( $html, $i );
		} );
	}

	/** Opening hours from Rank Math as schema.org OpeningHoursSpecification. */
	protected static function hours() {
		if ( ! class_exists( 'SDSC_Map' ) ) { return array(); }
		$out = array();
		foreach ( SDSC_Map::hours() as $h ) {
			$out[] = array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $h['day'], 'opens' => $h['open'], 'closes' => $h['close'] );
		}
		return $out;
	}

	/** Homepage: the school as a local driving school (address, phones, hours, area served). */
	public static function local_business() {
		if ( ! is_front_page() ) { return; }
		$o    = get_option( 'rank-math-options-titles', array() );
		$a    = is_array( $o ) && ! empty( $o['local_address'] ) && is_array( $o['local_address'] ) ? $o['local_address'] : array();
		$home = home_url( '/' );
		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => array( 'DrivingSchool', 'LocalBusiness' ),
			'@id'         => $home . '#driving-school',
			'name'        => 'Sam\'s Driving School',
			'url'         => $home,
			'description' => self::DESC,
			'telephone'   => '+1-301-726-3030',
			'address'     => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $a['streetAddress'] ?? '751 Rockville Pike, Unit 9B',
				'addressLocality' => $a['addressLocality'] ?? 'Rockville',
				'addressRegion'   => $a['addressRegion'] ?? 'MD',
				'postalCode'      => $a['postalCode'] ?? '20852',
				'addressCountry'  => $a['addressCountry'] ?? 'US',
			),
			'areaServed'  => array_merge(
				array( array( '@type' => 'AdministrativeArea', 'name' => 'Montgomery County, Maryland' ) ),
				class_exists( 'SDSC_Areas' ) ? array_values( array_map( function ( $a ) { return array( '@type' => 'City', 'name' => $a[0] . ', Maryland' ); }, SDSC_Areas::areas() ) ) : array( array( '@type' => 'City', 'name' => 'Rockville, Maryland' ) )
			),
			'knowsLanguage' => array( 'en', 'es', 'fa' ),
			'contactPoint'  => array(
				array( '@type' => 'ContactPoint', 'telephone' => '+1-301-726-3030', 'contactType' => 'customer service', 'availableLanguage' => array( 'English', 'Spanish', 'Persian' ) ),
				array( '@type' => 'ContactPoint', 'telephone' => '+1-202-600-0889', 'contactType' => 'customer service' ),
			),
		);
		$hours = self::hours();
		if ( $hours ) { $data['openingHoursSpecification'] = $hours; }
		if ( class_exists( 'SDSC_Map' ) ) {
			$data['hasMap'] = SDSC_Map::links()['view'] ?? '';
			if ( '' === $data['hasMap'] ) { unset( $data['hasMap'] ); }
		}
		$logo = get_theme_mod( 'custom_logo' );
		if ( $logo && ( $src = wp_get_attachment_image_url( $logo, 'full' ) ) ) { $data['logo'] = $src; $data['image'] = $src; }
		$same = array_values( array_filter( array( get_theme_mod( 'ruzbahani_facebook' ), get_theme_mod( 'ruzbahani_instagram' ) ) ) );
		if ( $same ) { $data['sameAs'] = $same; }
		echo "\n<script type=\"application/ld+json\" class=\"sdsc-local\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore
	}
}
