<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Prices (Class Manager → Prices): one page where the admin and Saghar set the price of every class,
 * course, package and service shown on the site.
 *
 * Rule that keeps the site honest: a new price only goes live when checkout really charges it — either a
 * new payment link is entered with it, or "Checkout already charges this price" is ticked (the price was
 * changed in Stripe itself). Otherwise it waits as "pending" and the site keeps showing the current price.
 * Services that are paid in person (no payment link) change immediately.
 *
 * With Stripe connected (API connections), saving a new price makes the new payment link by itself: a Stripe
 * price on the same product, and a payment link that copies the old link's settings (questions, confirmation
 * page, promo codes …). The new price and link go live together. Test-mode links are never made for the
 * public site. Links the site made earlier are switched off when replaced; links made by hand are left alone.
 */
class SDSC_Prices {

	const OPT  = 'sdsc_prices';
	const SEEN = 'sdsc_prices_seen';
	const LOG  = 'sdsc_prices_log';
	const CAP  = 'sdsc_manage_prices';

	/** Fixed services: key => label. Behind-the-wheel packages are added as they are found on the site. */
	public static function catalog() {
		return array(
			'course'   => array( "Driver's Education — 36-hour course (standard price)", 'Used on the services overview and for new classes. Each scheduled class has its own price in the table above.' ),
			'btw_hour' => array( 'Behind-the-wheel — price per hour', 'Shown on the practice sign, e.g. "$60 per hour".' ),
			'btw_from' => array( 'Behind-the-wheel — "from" price', 'Shown on the services overview, e.g. "from $120".' ),
			'rental'   => array( 'Road test car rental', '' ),
			'online'   => array( 'Online 3-hour roadway safety course', '' ),
			'alcohol'  => array( '3-hour alcohol & drug program', 'A price, or text such as "Call for dates".' ),
			'dip'      => array( 'Driver improvement program', 'A price, or text such as "Call for dates".' ),
			'nervous'  => array( 'Lessons for nervous drivers', 'A price, or text such as "Call for details".' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'grant_caps' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
		add_action( 'admin_post_sdsc_prices_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'sdsc_services', array( __CLASS__, 'filter_services' ), 20 );
	}

	public static function grant_caps() {
		$r = get_role( 'administrator' );
		if ( $r && ! $r->has_cap( self::CAP ) && ! get_option( 'sdsc_prices_caps_done' ) ) {
			$r->add_cap( self::CAP );
			update_option( 'sdsc_prices_caps_done', 1 );
		}
	}

	public static function can() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/* ------------------------------ data ------------------------------ */

	protected static function all() {
		$v = get_option( self::OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	/** Live entry for a key: array( price, url ) — empty strings when not set (the site keeps its own value). */
	public static function entry( $key ) {
		$a = self::all();
		$e = isset( $a[ $key ] ) && is_array( $a[ $key ] ) ? $a[ $key ] : array();
		return array( 'price' => (string) ( $e['price'] ?? '' ), 'url' => (string) ( $e['url'] ?? '' ), 'pending' => (string) ( $e['pending'] ?? '' ) );
	}

	public static function price( $key, $default = '' ) {
		$e = self::entry( $key );
		return '' !== $e['price'] ? $e['price'] : $default;
	}

	public static function url( $key, $default = '' ) {
		$e = self::entry( $key );
		return '' !== $e['url'] ? $e['url'] : $default;
	}

	/** Apply the managed price/link to a parsed site item (array with 'price' and 'url'), and remember the item. */
	public static function apply_item( $key, $item, $label = '' ) {
		self::seen( $key, $label ? $label : ( $item['title'] ?? $key ), $item['price'] ?? '', $item['url'] ?? '' );
		$e = self::entry( $key );
		if ( '' !== $e['price'] ) { $item['price'] = $e['price']; }
		if ( '' !== $e['url'] ) { $item['url'] = $e['url']; }
		return $item;
	}

	/** Packages found on the site (title and the theme's own price), so they can be listed on the Prices page. */
	protected static function seen( $key, $label, $price, $url ) {
		if ( 0 !== strpos( $key, 'btw_' ) || isset( self::catalog()[ $key ] ) ) { return; }
		$s   = get_option( self::SEEN, array() );
		$s   = is_array( $s ) ? $s : array();
		$row = array( 'label' => wp_strip_all_tags( (string) $label ), 'price' => (string) $price, 'url' => (string) $url );
		if ( ( $s[ $key ] ?? null ) !== $row ) {
			$s[ $key ] = $row;
			update_option( self::SEEN, $s, false );
		}
	}

	/** Normalise what was typed: "250", "$250", "250.00" → "$250"; text such as "Call for dates" is kept. */
	public static function norm( $v ) {
		$v = trim( wp_strip_all_tags( (string) $v ) );
		if ( '' === $v ) { return ''; }
		$prefix = preg_match( '/^from\s+/i', $v ) ? 'from ' : '';
		$num    = preg_replace( '/^from\s+/i', '', $v );
		if ( preg_match( '/^\$?\s*(\d{1,5}(?:,\d{3})*(?:\.\d{1,2})?)$/', $num, $m ) ) {
			$n = (float) str_replace( ',', '', $m[1] );
			return $prefix . '$' . ( abs( $n - round( $n ) ) < 0.005 ? number_format( round( $n ) ) : number_format( $n, 2 ) );
		}
		return mb_substr( $v, 0, 40 );
	}

	protected static function is_checkout( $url ) {
		return (bool) preg_match( '#^https://(buy|checkout)\.stripe\.com/#', (string) $url );
	}

	/* ------------------------------ site hooks ------------------------------ */

	/** The services overview list (class-services.php). */
	public static function filter_services( $s ) {
		foreach ( $s as &$it ) {
			$t   = strtolower( $it['title'] ?? '' );
			$key = false !== strpos( $t, '36-hour' ) ? 'course' : ( false !== strpos( $t, 'behind-the-wheel' ) ? 'btw_from' : ( false !== strpos( $t, 'road test' ) ? 'rental' : ( false !== strpos( $t, 'roadway safety' ) ? 'online' : ( false !== strpos( $t, 'alcohol' ) ? 'alcohol' : ( false !== strpos( $t, 'improvement' ) ? 'dip' : ( false !== strpos( $t, 'nervous' ) ? 'nervous' : '' ) ) ) ) ) );
			if ( ! $key ) { continue; }
			$e = self::entry( $key );
			if ( '' !== $e['price'] ) { $it['price'] = $e['price']; }
			if ( '' !== $e['url'] && 'course' !== $key ) { $it['url'] = $e['url']; }
			if ( 'btw_from' === $key ) {
				$h = self::entry( 'btw_hour' );
				if ( '' !== $h['price'] ) { $it['big'] = $h['price']; }
			}
		}
		return $s;
	}

	/* ------------------------------ log + notice ------------------------------ */

	protected static function log( $msg ) {
		$l = get_option( self::LOG, array() );
		$l = is_array( $l ) ? $l : array();
		$u = wp_get_current_user();
		array_unshift( $l, array( 't' => time(), 'u' => $u && $u->ID ? $u->display_name : 'system', 'm' => $msg ) );
		update_option( self::LOG, array_slice( $l, 0, 60 ), false );
	}

	public static function notice() {
		if ( ! self::can() ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'sdsc-prices' ) ) { return; }
		$n = count( self::pending_list() );
		if ( $n ) {
			echo '<div class="notice notice-warning"><p><strong>Sam\'s Class Manager:</strong> ' . (int) $n . ' new price' . ( 1 === $n ? ' is' : 's are' ) . ' waiting for a payment link before showing on the site. <a href="' . esc_url( admin_url( 'admin.php?page=sdsc-prices' ) ) . '">Open Prices</a></p></div>';
		}
	}

	protected static function pending_list() {
		$out = array();
		foreach ( self::all() as $k => $e ) { if ( ! empty( $e['pending'] ) ) { $out[] = $k; } }
		foreach ( self::classes() as $p ) { if ( '' !== (string) get_post_meta( $p->ID, '_sdsc_price_pending', true ) ) { $out[] = 'class-' . $p->ID; } }
		return $out;
	}

	/* ------------------------------ classes ------------------------------ */

	protected static function classes() {
		if ( ! post_type_exists( 'sdsc_class' ) ) { return array(); }
		$q = new WP_Query( array(
			'post_type'      => 'sdsc_class',
			'post_status'    => array( 'publish', 'future', 'draft', 'private' ),
			'posts_per_page' => 40,
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_sdsc_status', 'value' => array( 'completed', 'cancelled' ), 'compare' => 'NOT IN' ) ),
			'meta_key'       => '_sdsc_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
		) );
		return $q->posts;
	}

	/* ------------------------------ Stripe: payment links made by the site ------------------------------ */

	const PRODUCTS = 'sdsc_prices_stripe';

	/** Stripe can make links here: a key is saved, and test mode is only used away from the public site. */
	public static function auto_ready() {
		if ( ! class_exists( 'SDSC_Api' ) || '' === SDSC_Api::stripe_key() ) { return false; }
		if ( 'live' === SDSC_Api::mode() ) { return true; }
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return 'samsdrivingschool.org' !== $host;
	}

	/** "$1,200" / "$12.50" → cents; 0 for text such as "Call for dates" or "from $120". */
	public static function cents( $price ) {
		$n = str_replace( ',', '', (string) $price );
		if ( ! preg_match( '/^\$(\d{1,6}(?:\.\d{1,2})?)$/', $n, $m ) ) { return 0; }
		return (int) round( (float) $m[1] * 100 );
	}

	protected static function bools( $v ) {
		if ( is_bool( $v ) ) { return $v ? 'true' : 'false'; }
		if ( is_array( $v ) ) { foreach ( $v as $k => $x ) { $v[ $k ] = self::bools( $x ); } }
		return $v;
	}

	protected static function api( $method, $path, $body = array(), $idem = '' ) {
		$h = array( 'Authorization' => 'Bearer ' . SDSC_Api::stripe_key() );
		if ( $idem ) { $h['Idempotency-Key'] = $idem; }
		$args = array( 'method' => $method, 'timeout' => 25, 'headers' => $h );
		if ( $body ) {
			$q = http_build_query( self::bools( $body ), '', '&' );
			if ( 'GET' === $method ) { $path .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . $q; } else { $args['body'] = $q; }
		}
		$r = wp_remote_request( 'https://api.stripe.com/v1/' . $path, $args );
		if ( is_wp_error( $r ) ) { return array( 0, array( 'error' => array( 'message' => $r->get_error_message() ) ) ); }
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		return array( (int) wp_remote_retrieve_response_code( $r ), is_array( $j ) ? $j : array() );
	}

	protected static $links = null;
	/** The active payment link with this address (the account's links are read once per save). */
	protected static function find_link( $url ) {
		if ( null === self::$links ) {
			self::$links = array();
			$after = '';
			for ( $page = 0; $page < 10; $page++ ) {
				list( $code, $j ) = self::api( 'GET', 'payment_links', array_filter( array( 'limit' => 100, 'active' => true, 'starting_after' => $after ) ) );
				if ( 200 !== $code || empty( $j['data'] ) ) { break; }
				foreach ( $j['data'] as $l ) { self::$links[ (string) $l['url'] ] = $l; }
				if ( empty( $j['has_more'] ) ) { break; }
				$after = end( $j['data'] )['id'];
			}
		}
		return self::$links[ $url ] ?? null;
	}

	protected static function clean( $v ) {
		if ( ! is_array( $v ) ) { return $v; }
		$o = array();
		foreach ( $v as $k => $x ) {
			if ( null === $x ) { continue; }
			$x = self::clean( $x );
			if ( is_array( $x ) && ! $x ) { continue; }
			$o[ $k ] = $x;
		}
		return $o;
	}

	/** The settings of an existing payment link, in the shape Stripe accepts for a new one. */
	protected static function settings( $l ) {
		$o = array();
		$ac = $l['after_completion'] ?? null;
		if ( $ac && ! empty( $ac['type'] ) ) {
			$o['after_completion'] = array( 'type' => $ac['type'] );
			if ( 'redirect' === $ac['type'] && ! empty( $ac['redirect']['url'] ) ) { $o['after_completion']['redirect'] = array( 'url' => $ac['redirect']['url'] ); }
			if ( 'hosted_confirmation' === $ac['type'] && ! empty( $ac['hosted_confirmation']['custom_message'] ) ) { $o['after_completion']['hosted_confirmation'] = array( 'custom_message' => $ac['hosted_confirmation']['custom_message'] ); }
		}
		foreach ( array( 'allow_promotion_codes', 'billing_address_collection', 'customer_creation', 'submit_type', 'inactive_message' ) as $k ) {
			if ( isset( $l[ $k ] ) && null !== $l[ $k ] ) { $o[ $k ] = $l[ $k ]; }
		}
		foreach ( array( 'phone_number_collection', 'tax_id_collection', 'consent_collection', 'custom_text', 'shipping_address_collection', 'payment_intent_data', 'automatic_tax', 'name_collection' ) as $k ) {
			if ( ! empty( $l[ $k ] ) && is_array( $l[ $k ] ) ) { $o[ $k ] = self::clean( $l[ $k ] ); }
		}
		if ( isset( $o['automatic_tax'] ) && empty( $o['automatic_tax']['enabled'] ) ) { unset( $o['automatic_tax'] ); }
		if ( isset( $o['tax_id_collection'] ) && empty( $o['tax_id_collection']['enabled'] ) ) { unset( $o['tax_id_collection'] ); }
		if ( isset( $o['name_collection'] ) ) { foreach ( $o['name_collection'] as $nk => $nv ) { if ( empty( $nv['enabled'] ) ) { unset( $o['name_collection'][ $nk ] ); } } if ( ! $o['name_collection'] ) { unset( $o['name_collection'] ); } }
		if ( ! empty( $l['custom_fields'] ) ) {
			foreach ( $l['custom_fields'] as $f ) {
				$c = array( 'key' => $f['key'], 'type' => $f['type'], 'optional' => ! empty( $f['optional'] ), 'label' => self::clean( $f['label'] ) );
				if ( ! empty( $f[ $f['type'] ] ) ) { $c[ $f['type'] ] = self::clean( $f[ $f['type'] ] ); }
				if ( 'dropdown' === $f['type'] && isset( $c['dropdown']['options'] ) ) { $c['dropdown']['options'] = array_map( function ( $op ) { return array( 'label' => $op['label'], 'value' => $op['value'] ); }, $c['dropdown']['options'] ); }
				$o['custom_fields'][] = $c;
			}
		}
		if ( ! empty( $l['invoice_creation']['enabled'] ) ) {
			$o['invoice_creation'] = array( 'enabled' => true );
			$d = self::clean( $l['invoice_creation']['invoice_data'] ?? array() );
			unset( $d['rendering_options'] );
			if ( $d ) { $o['invoice_creation']['invoice_data'] = $d; }
		}
		if ( ! empty( $l['restrictions']['completed_sessions']['limit'] ) ) { $o['restrictions'] = array( 'completed_sessions' => array( 'limit' => (int) $l['restrictions']['completed_sessions']['limit'] ) ); }
		if ( ! empty( $l['payment_method_types'] ) ) { $o['payment_method_types'] = $l['payment_method_types']; }
		$o['metadata'] = is_array( $l['metadata'] ?? null ) ? $l['metadata'] : array();
		return $o;
	}

	/**
	 * Make a payment link that charges $price. Returns array( ok, url, note ).
	 * $key identifies the item (class-123, btw_5, rental …); $old_url is the link shown now (its settings are copied).
	 */
	public static function make_link( $key, $label, $price, $old_url ) {
		$cents = self::cents( $price );
		if ( ! $cents ) { return array( false, '', 'not a plain price' ); }
		if ( ! self::auto_ready() ) { return array( false, '', 'Stripe is not connected' ); }
		$mode = SDSC_Api::mode();
		$tag  = 'sdsc-' . substr( md5( $mode . '|' . $key . '|' . $cents . '|' . $old_url . '|' . gmdate( 'Y-m-d' ) ), 0, 20 );
		$old  = self::is_checkout( $old_url ) ? self::find_link( $old_url ) : null;
		$product = ''; $currency = 'usd'; $qty = 1;
		if ( $old ) {
			list( $code, $li ) = self::api( 'GET', 'payment_links/' . rawurlencode( $old['id'] ) . '/line_items', array( 'limit' => 1 ) );
			$it = ( 200 === $code && ! empty( $li['data'][0] ) ) ? $li['data'][0] : null;
			if ( $it && ! empty( $it['price']['recurring'] ) ) { return array( false, '', 'the current link is a subscription' ); }
			if ( $it ) {
				$product  = is_array( $it['price']['product'] ) ? $it['price']['product']['id'] : (string) $it['price']['product'];
				$currency = (string) ( $it['price']['currency'] ?? 'usd' );
				$qty      = max( 1, (int) ( $it['quantity'] ?? 1 ) );
			}
		}
		$known = get_option( self::PRODUCTS, array() );
		$known = is_array( $known ) ? $known : array();
		if ( ! $product ) { $product = (string) ( $known[ $mode ][ $key ] ?? '' ); }
		if ( ! $product ) {
			list( $code, $pr ) = self::api( 'POST', 'products', array( 'name' => mb_substr( wp_strip_all_tags( $label ), 0, 250 ), 'metadata' => array( 'sdsc_key' => $key ) ), $tag . '-prod' );
			if ( 200 !== $code || empty( $pr['id'] ) ) { return array( false, '', 'Stripe did not make the product: ' . ( $pr['error']['message'] ?? 'error ' . $code ) ); }
			$product = $pr['id'];
		}
		$known[ $mode ][ $key ] = $product;
		update_option( self::PRODUCTS, $known, false );
		list( $code, $np ) = self::api( 'POST', 'prices', array( 'product' => $product, 'unit_amount' => $cents, 'currency' => $currency, 'nickname' => mb_substr( wp_strip_all_tags( $label ) . ' ' . $price, 0, 250 ), 'metadata' => array( 'sdsc_key' => $key ) ), $tag . '-price' );
		if ( 200 !== $code || empty( $np['id'] ) ) { return array( false, '', 'Stripe did not make the price: ' . ( $np['error']['message'] ?? 'error ' . $code ) ); }
		$base = array( 'line_items' => array( array( 'price' => $np['id'], 'quantity' => $qty ) ) );
		$set  = $old ? self::settings( $old ) : array();
		$meta = array_merge( (array) ( $set['metadata'] ?? array() ), array( 'sdsc_key' => $key, 'sdsc_auto' => '1', 'sdsc_site' => (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		list( $code, $nl ) = self::api( 'POST', 'payment_links', array_merge( $set, $base, array( 'metadata' => $meta ) ), $tag . '-link' );
		$note = '';
		if ( 200 !== $code && $old ) {
			$why = (string) ( $nl['error']['message'] ?? '' );
			// a setting Stripe will not copy: make the link with the main ones and say so
			$min = array_intersect_key( $set, array_flip( array( 'after_completion', 'allow_promotion_codes', 'billing_address_collection', 'phone_number_collection', 'custom_fields' ) ) );
			list( $code, $nl ) = self::api( 'POST', 'payment_links', array_merge( $min, $base, array( 'metadata' => $meta ) ), $tag . '-link2' );
			if ( 200 !== $code ) { list( $code, $nl ) = self::api( 'POST', 'payment_links', array_merge( $base, array( 'metadata' => $meta ) ), $tag . '-link3' ); }
			$note = ' (some settings of the old link could not be copied — check it in Stripe' . ( $why ? '. Stripe said: ' . mb_substr( $why, 0, 160 ) : '' ) . ')';
		}
		if ( 200 !== $code || empty( $nl['url'] ) ) { return array( false, '', 'Stripe did not make the link: ' . ( $nl['error']['message'] ?? 'error ' . $code ) ); }
		if ( $old && '1' === (string) ( $old['metadata']['sdsc_auto'] ?? '' ) ) {
			self::api( 'POST', 'payment_links/' . rawurlencode( $old['id'] ), array( 'active' => false ) );
			$note .= '; the previous link made by the site was switched off';
		} elseif ( $old ) {
			$note .= '; the old link still works in Stripe (it was made by hand)';
		}
		if ( null !== self::$links ) { self::$links[ $nl['url'] ] = $nl; }
		return array( true, (string) $nl['url'], 'new Stripe payment link made' . ( 'test' === $mode ? ' (test mode)' : '' ) . $note );
	}

	/**
	 * Decide what happens to one price change.
	 * Returns array( live price, url, pending price, note ).
	 */
	protected static function decide( $old_price, $old_url, $new_price, $new_url, $confirmed, $label ) {
		$note = '';
		if ( $new_url !== $old_url && '' !== $new_url ) {
			$note = $label . ': payment link updated';
		}
		if ( '' === $new_price || $new_price === $old_price ) {
			return array( $old_price, $new_url, '', $note );
		}
		$link_changed = '' !== $new_url && $new_url !== $old_url;
		if ( $link_changed || $confirmed || ! self::is_checkout( $new_url ) ) {
			return array( $new_price, $new_url, '', $label . ': ' . ( '' !== $old_price ? $old_price : '(site default)' ) . ' → ' . $new_price . ( $link_changed ? ' with a new payment link' : ( $confirmed ? ' (confirmed in Stripe)' : '' ) ) );
		}
		return array( $old_price, $new_url, $new_price, $label . ': ' . $new_price . ' saved as pending — the payment link still charges ' . ( '' !== $old_price ? $old_price : 'the old price' ) );
	}

	/* ------------------------------ save ------------------------------ */

	public static function save() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_prices' );
		$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$notes = array();

		// Scheduled classes.
		foreach ( (array) ( $post['c'] ?? array() ) as $id => $r ) {
			$id = absint( $id );
			if ( ! $id || 'sdsc_class' !== get_post_type( $id ) ) { continue; }
			$old_n   = (string) get_post_meta( $id, '_sdsc_price', true );
			$old     = '' !== $old_n ? self::norm( $old_n ) : '';
			$old_url = (string) get_post_meta( $id, '_sdsc_payment_link', true );
			$new     = self::norm( $r['price'] ?? '' );
			$url     = esc_url_raw( trim( (string) ( $r['url'] ?? $old_url ) ) );
			$pend    = (string) get_post_meta( $id, '_sdsc_price_pending', true );
			if ( '' === $new && '' !== $pend ) { $new = $pend; } // keep a pending price unless replaced
			$code    = (string) get_post_meta( $id, '_sdsc_course_code', true );
			$lbl     = 'Class ' . ( $code ? $code : '#' . $id );
			if ( '' !== $new && $new !== $old && $url === $old_url && empty( $r['ok'] ) && self::is_checkout( $old_url ) && self::auto_ready() ) {
				list( $ok, $nurl, $why ) = self::make_link( 'class-' . $id, html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) . ( $code ? ' (' . $code . ')' : '' ), $new, $old_url );
				if ( $ok ) { $url = $nurl; $notes[] = $lbl . ': ' . $why; } else { $notes[] = $lbl . ': no new link — ' . $why; }
			}
			list( $live, $url, $pending, $note ) = self::decide( $old, $old_url, $new, $url, ! empty( $r['ok'] ), $lbl );
			if ( $live !== $old ) { update_post_meta( $id, '_sdsc_price', preg_replace( '/[^\d.]/', '', $live ) ); }
			if ( $url !== $old_url ) { update_post_meta( $id, '_sdsc_payment_link', $url ); }
			if ( $pending ) { update_post_meta( $id, '_sdsc_price_pending', $pending ); } else { delete_post_meta( $id, '_sdsc_price_pending' ); }
			if ( $note ) { $notes[] = $note; }
		}

		// Services and packages.
		$a    = self::all();
		$keys = array_merge( array_keys( self::catalog() ), array_keys( (array) get_option( self::SEEN, array() ) ) );
		foreach ( (array) ( $post['s'] ?? array() ) as $k => $r ) {
			$k = sanitize_key( $k );
			if ( ! in_array( $k, $keys, true ) ) { continue; }
			$e        = $a[ $k ] ?? array();
			$default  = self::norm( $r['default'] ?? '' );
			$site_url = esc_url_raw( (string) ( $r['site_url'] ?? '' ), array( 'http', 'https', 'tel' ) );
			if ( ! empty( $r['reset'] ) ) {
				unset( $a[ $k ] );
				$notes[] = self::label( $k ) . ': back to the site default';
				continue;
			}
			$old     = '' !== ( $e['price'] ?? '' ) ? $e['price'] : $default;
			$old_url = '' !== ( $e['url'] ?? '' ) ? $e['url'] : $site_url;
			$new     = self::norm( $r['price'] ?? '' );
			if ( '' === $new && ! empty( $e['pending'] ) ) { $new = $e['pending']; }
			$in_url  = esc_url_raw( trim( (string) ( $r['url'] ?? '' ) ) );
			$url     = '' !== $in_url ? $in_url : $old_url;
			$linkable = ! in_array( $k, array( 'course', 'btw_hour', 'btw_from' ), true );
			$changed  = '' !== $new && $new !== $old;
			$sell     = ! empty( $r['mk'] ) && ! self::is_checkout( $old_url );
			if ( $linkable && $url === $old_url && empty( $r['ok'] ) && self::auto_ready() && ( ( $changed && self::is_checkout( $old_url ) ) || $sell ) ) {
				list( $ok, $nurl, $why ) = self::make_link( $k, self::label( $k ), $changed ? $new : $old, $old_url );
				if ( $ok ) { $url = $nurl; $notes[] = self::label( $k ) . ': ' . $why; } else { $notes[] = self::label( $k ) . ': no new link — ' . $why; }
			}
			list( $live, $url, $pending, $note ) = self::decide( $old, $old_url, $new, $url, ! empty( $r['ok'] ), self::label( $k ) );
			$row = array( 'price' => $live !== $default ? $live : '', 'url' => $url !== $site_url ? $url : '', 'pending' => $pending );
			if ( '' === $row['price'] && '' === $row['url'] && '' === $row['pending'] ) { unset( $a[ $k ] ); } else { $a[ $k ] = $row; }
			if ( $note ) { $notes[] = $note; }
		}
		update_option( self::OPT, $a, false );

		foreach ( $notes as $n ) { self::log( $n ); }
		if ( class_exists( 'SDSC_Discounts' ) ) { SDSC_Discounts::purge(); }
		set_transient( 'sdsc_prices_msg_' . get_current_user_id(), $notes ? $notes : array( 'Nothing changed.' ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-prices' ) );
		exit;
	}

	protected static function label( $k ) {
		$c = self::catalog();
		if ( isset( $c[ $k ] ) ) { return $c[ $k ][0]; }
		$s = (array) get_option( self::SEEN, array() );
		return isset( $s[ $k ]['label'] ) ? $s[ $k ]['label'] : $k;
	}

	/* ------------------------------ page ------------------------------ */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Prices', 'Prices', self::CAP, 'sdsc-prices', array( __CLASS__, 'page' ) );
	}

	protected static function status_cell( $pending, $live_url ) {
		if ( $pending ) { return '<span style="color:#996800;font-weight:600">&#9679; ' . esc_html( $pending ) . ' waiting for a payment link</span>'; }
		return '<span style="color:#00653a">&#9679; Live</span>' . ( $live_url && ! self::is_checkout( $live_url ) && 0 !== strpos( $live_url, 'tel:' ) ? '' : '' );
	}

	public static function page() {
		if ( ! self::can() ) { return; }
		$msg = get_transient( 'sdsc_prices_msg_' . get_current_user_id() );
		if ( $msg ) { delete_transient( 'sdsc_prices_msg_' . get_current_user_id() ); }
		$a    = self::all();
		$seen = (array) get_option( self::SEEN, array() );
		$svc  = class_exists( 'SDSC_Services' ) ? SDSC_Services::services() : array();
		$site = array();
		foreach ( $svc as $it ) {
			$t = strtolower( $it['title'] );
			if ( false !== strpos( $t, '36-hour' ) ) { $site['course'] = array( $it['price'], '' ); }
			elseif ( false !== strpos( $t, 'behind-the-wheel' ) ) { $site['btw_from'] = array( $it['price'], $it['url'] ); $site['btw_hour'] = array( wp_strip_all_tags( html_entity_decode( $it['big'] ) ), '' ); }
			elseif ( false !== strpos( $t, 'road test' ) ) { $site['rental'] = array( $it['price'], $it['url'] ); }
			elseif ( false !== strpos( $t, 'roadway safety' ) ) { $site['online'] = array( $it['price'], $it['url'] ); }
			elseif ( false !== strpos( $t, 'alcohol' ) ) { $site['alcohol'] = array( $it['price'], $it['url'] ); }
			elseif ( false !== strpos( $t, 'improvement' ) ) { $site['dip'] = array( $it['price'], $it['url'] ); }
			elseif ( false !== strpos( $t, 'nervous' ) ) { $site['nervous'] = array( $it['price'], $it['url'] ); }
		}
		$rows = array();
		foreach ( self::catalog() as $k => $info ) { $rows[ $k ] = array( $info[0], $info[1], $site[ $k ][0] ?? '', $site[ $k ][1] ?? '' ); }
		uksort( $seen, function ( $x, $y ) { return (int) substr( $x, 4 ) <=> (int) substr( $y, 4 ); } );
		foreach ( $seen as $k => $s ) { $rows[ $k ] = array( $s['label'], 'Behind-the-wheel package', $s['price'], $s['url'] ); }
		$log = get_option( self::LOG, array() );
		?>
		<div class="wrap sdsc-prices">
			<h1>Prices</h1>
			<?php if ( $msg ) : ?><div class="notice notice-info is-dismissible"><?php foreach ( $msg as $m ) : ?><p><?php echo esc_html( $m ); ?></p><?php endforeach; ?></div><?php endif; ?>
			<style>.sdsc-prices table input[type=text],.sdsc-prices table input[type=url]{width:100%}.sdsc-prices td{vertical-align:middle}.sdsc-prices .sdsc-cur{font-weight:700;font-size:14px}.sdsc-prices small{color:#646970}</style>
			<div style="max-width:980px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #dba617;padding:10px 14px;margin:14px 0">
				<p style="margin:.3em 0"><strong>How a price change works.</strong> Type the new price. It goes on the site straight away when you also paste the new payment link, or tick <em>Checkout already charges this price</em> after changing it in Stripe. Otherwise it waits as <em>pending</em> and the site keeps the current price — so a customer is never shown one price and charged another. Leave a box empty to keep the price as it is.</p>
				<?php if ( self::auto_ready() ) : ?>
				<p style="margin:.3em 0;color:#00653a"><strong>Stripe is connected (<?php echo esc_html( SDSC_Api::mode() ); ?> mode).</strong> Just type the new price and save: the site makes the new payment link in Stripe and puts the new price and link on the site together. Leave the link box as it is. Tick <em>Already changed in Stripe</em> only if you changed the price on the existing link yourself.</p>
				<?php else : ?>
				<p style="margin:.3em 0;color:#50575e">Once Stripe is connected in live mode (API connections), new payment links are made automatically and new prices go live at once.</p>
				<?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sdsc_prices' ); ?>
				<input type="hidden" name="action" value="sdsc_prices_save" />

				<h2>Scheduled classes</h2>
				<?php $cl = self::classes(); if ( ! $cl ) : ?><p>No upcoming classes.</p><?php else : ?>
				<table class="widefat striped" style="max-width:1200px">
					<thead><tr><th style="width:230px">Class</th><th style="width:90px">Price now</th><th style="width:120px">New price</th><th>Payment link</th><th style="width:150px"><?php echo self::auto_ready() ? 'Already changed in Stripe' : 'Checkout already charges this price'; ?></th><th style="width:210px">Status</th></tr></thead>
					<tbody>
					<?php foreach ( $cl as $p ) :
						$id   = $p->ID;
						$pr   = (string) get_post_meta( $id, '_sdsc_price', true );
						$url  = (string) get_post_meta( $id, '_sdsc_payment_link', true );
						$pend = (string) get_post_meta( $id, '_sdsc_price_pending', true );
						$code = (string) get_post_meta( $id, '_sdsc_course_code', true );
						$st   = (string) get_post_meta( $id, '_sdsc_start', true );
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><strong><?php echo esc_html( $code ? $code : '#' . $id ); ?></strong></a> <small><?php echo esc_html( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ); ?><?php echo $st ? ' · starts ' . esc_html( date_i18n( 'M j', strtotime( substr( $st, 0, 10 ) ) ) ) : ''; ?></small></td>
							<td class="sdsc-cur"><?php echo esc_html( '' !== $pr ? self::norm( $pr ) : '—' ); ?></td>
							<td><input type="text" name="c[<?php echo (int) $id; ?>][price]" value="" placeholder="<?php echo esc_attr( $pend ? $pend : 'e.g. 378' ); ?>" inputmode="decimal" /></td>
							<td><input type="url" name="c[<?php echo (int) $id; ?>][url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://buy.stripe.com/…" /></td>
							<td><label><input type="checkbox" name="c[<?php echo (int) $id; ?>][ok]" value="1" /> Yes</label></td>
							<td><?php echo self::status_cell( $pend, $url ); // phpcs:ignore -- escaped in helper ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>

				<h2 style="margin-top:28px">Courses, packages and services</h2>
				<table class="widefat striped" style="max-width:1200px">
					<thead><tr><th style="width:300px">Service</th><th style="width:110px">Price now</th><th style="width:120px">New price</th><th>Payment link</th><th style="width:150px"><?php echo self::auto_ready() ? 'Already changed in Stripe' : 'Checkout already charges this price'; ?></th><th style="width:210px">Status</th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $k => $r ) :
						$e   = $a[ $k ] ?? array();
						$now = '' !== ( $e['price'] ?? '' ) ? $e['price'] : $r[2];
						$url = '' !== ( $e['url'] ?? '' ) ? $e['url'] : $r[3];
						?>
						<tr>
							<td><strong><?php echo esc_html( $r[0] ); ?></strong><?php if ( $r[1] ) : ?><br><small><?php echo esc_html( $r[1] ); ?></small><?php endif; ?>
								<input type="hidden" name="s[<?php echo esc_attr( $k ); ?>][default]" value="<?php echo esc_attr( $r[2] ); ?>" />
								<input type="hidden" name="s[<?php echo esc_attr( $k ); ?>][site_url]" value="<?php echo esc_attr( $r[3] ); ?>" /></td>
							<td class="sdsc-cur"><?php echo esc_html( $now ? $now : '—' ); ?><?php if ( ! empty( $e['price'] ) ) : ?><br><label><small><input type="checkbox" name="s[<?php echo esc_attr( $k ); ?>][reset]" value="1" /> back to site default</small></label><?php endif; ?></td>
							<td><input type="text" name="s[<?php echo esc_attr( $k ); ?>][price]" value="" placeholder="<?php echo esc_attr( ! empty( $e['pending'] ) ? $e['pending'] : 'new price' ); ?>" /></td>
							<td><?php if ( 'course' === $k || 'btw_hour' === $k ) : ?><small>Set per class / per package</small><?php else : ?><input type="url" name="s[<?php echo esc_attr( $k ); ?>][url]" value="<?php echo esc_attr( 0 === strpos( $url, 'tel:' ) ? '' : $url ); ?>" placeholder="<?php echo 0 === strpos( $url, 'tel:' ) ? 'Booked by phone' : 'https://buy.stripe.com/…'; ?>" /><?php endif; ?></td>
							<td><?php if ( self::is_checkout( $url ) ) : ?><label><input type="checkbox" name="s[<?php echo esc_attr( $k ); ?>][ok]" value="1" /> Yes</label><?php elseif ( self::auto_ready() && ! in_array( $k, array( 'course', 'btw_hour', 'btw_from' ), true ) && self::cents( '' !== ( $e['pending'] ?? '' ) ? $e['pending'] : $now ) ) : ?><label title="Make a Stripe payment link for this price and use it on the site"><input type="checkbox" name="s[<?php echo esc_attr( $k ); ?>][mk]" value="1" /> Sell online (make a link)</label><?php else : ?><small>—</small><?php endif; ?></td>
							<td><?php echo self::status_cell( $e['pending'] ?? '', $url ); // phpcs:ignore -- escaped in helper ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! $seen ) : ?><p class="description">Behind-the-wheel packages appear here after the Services page has been opened once.</p><?php endif; ?>
				<?php submit_button( 'Save prices' ); ?>
			</form>

			<h2>Price history</h2>
			<?php if ( ! $log ) : ?><p>No changes yet.</p><?php else : ?>
				<table class="widefat striped" style="max-width:980px"><thead><tr><th style="width:170px">When</th><th style="width:140px">Who</th><th>Change</th></tr></thead><tbody>
				<?php foreach ( array_slice( $log, 0, 30 ) as $e ) : ?><tr><td><?php echo esc_html( date_i18n( 'M j, Y g:i a', $e['t'] ) ); ?></td><td><?php echo esc_html( $e['u'] ); ?></td><td><?php echo esc_html( $e['m'] ); ?></td></tr><?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
