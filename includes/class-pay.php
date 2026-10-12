<?php
/**
 * Online payment for class registrations (Stripe Checkout).
 *
 * After a student submits the registration form, "Continue to payment" opens a Stripe Checkout page
 * created by the server for exactly the class price shown on the form (or less, when a confirmed
 * Driver's Education discount is live). When Stripe reports the payment, the registration is marked
 * paid and the student gets the class confirmation (dates, Zoom link, calendar link).
 *
 * Payment is confirmed two ways, both idempotent:
 *  - the student returns to the site (we read the Checkout Session from Stripe), and
 *  - the Stripe webhook POST /wp-json/sdsc/v1/stripe (signature checked with the signing secret).
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Pay {
	const DBV = '1';
	const DBO = 'sdsc_pay_db';

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_payments'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 22 );
		add_action( 'admin_post_nopriv_sdsc_pay', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_sdsc_pay', array( __CLASS__, 'start' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reg_id bigint(20) unsigned NOT NULL DEFAULT 0,
			class_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			mode varchar(4) NOT NULL DEFAULT 'test',
			session_id varchar(255) NOT NULL DEFAULT '',
			payment_intent varchar(255) NOT NULL DEFAULT '',
			amount int(11) unsigned NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT 'usd',
			status varchar(12) NOT NULL DEFAULT 'open',
			created_at datetime NOT NULL,
			paid_at datetime DEFAULT NULL,
			mailed tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY session (session_id),
			KEY reg (reg_id),
			KEY email (email)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	/* ------------------------------------------------------------ helpers */

	public static function token( $reg_id ) {
		return substr( hash_hmac( 'sha256', 'pay|' . (int) $reg_id, wp_salt( 'nonce' ) . 'sdsc-pay' ), 0, 24 );
	}

	protected static function check( $reg_id, $tok ) {
		return $reg_id > 0 && is_string( $tok ) && hash_equals( self::token( $reg_id ), $tok );
	}

	public static function ready() {
		return class_exists( 'SDSC_Api' ) && '' !== SDSC_Api::stripe_key();
	}

	/** Amount in cents for a class, or 0 when the class has no usable price. Never more than the price shown. */
	public static function amount( $class_id ) {
		$p = (string) get_post_meta( $class_id, '_sdsc_price', true );
		if ( ! is_numeric( $p ) || (float) $p <= 0 ) { return 0; }
		$n = (float) $p;
		if ( class_exists( 'SDSC_Discounts' ) ) {
			$d = SDSC_Discounts::get( 'course' );
			if ( $d && empty( $d['preview'] ) ) { $n = $n * ( 1 - (float) $d['pct'] / 100 ); }
		}
		return (int) round( $n * 100 );
	}

	/** URL of the "Continue to payment" button, or '' when online payment is not possible for this class. */
	public static function url( $reg_id, $class_id ) {
		if ( ! self::ready() || ! self::amount( $class_id ) ) { return ''; }
		return add_query_arg( array( 'action' => 'sdsc_pay', 'r' => (int) $reg_id, 't' => self::token( $reg_id ) ), admin_url( 'admin-post.php' ) );
	}

	protected static function reg( $reg_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, class_id, first_name, last_name, email, phone FROM ' . SDSC_Registration::table() . ' WHERE id = %d', $reg_id ), ARRAY_A ); // phpcs:ignore
	}

	public static function paid_row( $reg_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE reg_id = %d AND status = 'paid' ORDER BY id DESC LIMIT 1", $reg_id ), ARRAY_A ); // phpcs:ignore
	}

	protected static function stripe( $method, $path, $body = array() ) {
		$args = array( 'method' => $method, 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . SDSC_Api::stripe_key() ) );
		if ( $body ) {
			if ( 'GET' === $method ) { $path .= '?' . http_build_query( $body ); } else { $args['body'] = http_build_query( $body ); }
		}
		$r = wp_remote_request( 'https://api.stripe.com/v1/' . $path, $args );
		if ( is_wp_error( $r ) ) { return array( 0, array( 'error' => array( 'message' => $r->get_error_message() ) ) ); }
		return array( (int) wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
	}

	/* ------------------------------------------------------------ start checkout */

	public static function start() {
		$reg_id = isset( $_GET['r'] ) ? absint( $_GET['r'] ) : 0; // phpcs:ignore
		$tok    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore
		$reg    = self::check( $reg_id, $tok ) ? self::reg( $reg_id ) : null;
		if ( ! $reg ) { wp_die( 'This payment link is not valid. Please call the school at (301) 726-3030.', 'Payment', array( 'response' => 403 ) ); }
		$back = self::return_url( $reg );
		if ( self::paid_row( $reg_id ) ) { wp_safe_redirect( add_query_arg( array( 'sdsc_reg' => 'paid', 'sdsc_r' => $reg_id, 'sdsc_t' => $tok ), $back ) . '#sdsc' ); exit; }
		$cid    = (int) $reg['class_id'];
		$amount = self::amount( $cid );
		if ( ! self::ready() || ! $amount ) { wp_die( 'Online payment is not available for this class yet. Please call the school at (301) 726-3030.', 'Payment' ); }

		global $wpdb;
		// Reuse a recent open Checkout page for the same registration and amount.
		$open = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE reg_id = %d AND status = 'open' AND amount = %d AND mode = %s AND created_at > %s ORDER BY id DESC LIMIT 1", $reg_id, $amount, SDSC_Api::mode(), gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 20 * HOUR_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
		if ( $open ) {
			list( $code, $s ) = self::stripe( 'GET', 'checkout/sessions/' . rawurlencode( $open['session_id'] ) );
			if ( 200 === $code && 'open' === ( $s['status'] ?? '' ) && ! empty( $s['url'] ) ) { wp_redirect( $s['url'] ); exit; } // phpcs:ignore -- Stripe-hosted page
			if ( 200 === $code && 'complete' === ( $s['status'] ?? '' ) ) { self::settle( $s ); wp_safe_redirect( add_query_arg( array( 'sdsc_reg' => 'paid', 'sdsc_r' => $reg_id, 'sdsc_t' => $tok ), $back ) . '#sdsc' ); exit; }
			$wpdb->update( self::table(), array( 'status' => 'expired' ), array( 'id' => $open['id'] ) );
		}

		$name = class_exists( 'SDSC_Registration' ) ? SDSC_Registration::class_label( $cid ) : get_the_title( $cid );
		$ok   = add_query_arg( array( 'sdsc_reg' => 'paid', 'sdsc_r' => $reg_id, 'sdsc_t' => $tok ), $back ) . '&sdsc_s={CHECKOUT_SESSION_ID}#sdsc';
		list( $code, $s ) = self::stripe( 'POST', 'checkout/sessions', array(
			'mode'                => 'payment',
			'customer_email'      => $reg['email'],
			'client_reference_id' => (string) $reg_id,
			'line_items'          => array( array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => 'usd',
					'unit_amount'  => $amount,
					'product_data' => array( 'name' => mb_substr( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ), 0, 200 ), 'description' => 'Student: ' . trim( $reg['first_name'] . ' ' . $reg['last_name'] ) ),
				),
			) ),
			'metadata'            => array( 'sdsc_reg' => $reg_id, 'sdsc_class' => $cid, 'site' => wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'payment_intent_data' => array( 'metadata' => array( 'sdsc_reg' => $reg_id, 'sdsc_class' => $cid ), 'description' => mb_substr( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ), 0, 200 ) ),
			'success_url'         => $ok,
			'cancel_url'          => add_query_arg( array( 'sdsc_reg' => 'done', 'sdsc_class' => $cid, 'sdsc_r' => $reg_id, 'sdsc_t' => $tok ), $back ) . '#sdsc',
		) );
		if ( 200 !== $code || empty( $s['url'] ) ) {
			error_log( 'SDSC_Pay: Stripe Checkout failed (' . $code . '): ' . ( $s['error']['message'] ?? 'no message' ) ); // phpcs:ignore
			wp_die( 'We could not open the payment page right now. Please try again in a minute or call the school at (301) 726-3030.', 'Payment' );
		}
		$wpdb->insert( self::table(), array(
			'reg_id' => $reg_id, 'class_id' => $cid, 'email' => strtolower( $reg['email'] ), 'mode' => SDSC_Api::mode(),
			'session_id' => $s['id'], 'amount' => $amount, 'currency' => 'usd', 'status' => 'open', 'created_at' => current_time( 'mysql' ),
		) );
		wp_redirect( $s['url'] ); // phpcs:ignore -- Stripe-hosted Checkout page
		exit;
	}

	/** The registration page the student came from (falls back to the page holding the form). */
	protected static function return_url( $reg ) {
		$ref = wp_get_referer();
		if ( $ref && 0 === strpos( $ref, home_url() ) && false === strpos( $ref, '/wp-admin/' ) ) { return remove_query_arg( array( 'sdsc_reg', 'sdsc_class', 'sdsc_r', 'sdsc_t', 'sdsc_s' ), $ref ); }
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => '[sdsc_registration_form', 'numberposts' => 1 ) );
		return $pages ? get_permalink( $pages[0] ) : home_url( '/' );
	}

	/* ------------------------------------------------------------ confirm */

	/** Return from Stripe: read the session and settle it. Returns the paid row or null. */
	public static function confirm_return( $reg_id, $tok, $session_id ) {
		if ( ! self::check( $reg_id, $tok ) ) { return null; }
		$row = self::paid_row( $reg_id );
		if ( $row ) { return $row; }
		if ( ! $session_id || ! preg_match( '/^cs_(test|live)_[A-Za-z0-9]+$/', $session_id ) || ! self::ready() ) { return null; }
		global $wpdb;
		$mine = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE session_id = %s AND reg_id = %d', $session_id, $reg_id ) ); // phpcs:ignore
		if ( ! $mine ) { return null; }
		list( $code, $s ) = self::stripe( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
		if ( 200 === $code ) { self::settle( $s ); }
		return self::paid_row( $reg_id );
	}

	/** Mark a Checkout Session paid (once) and send the confirmations. */
	public static function settle( $s ) {
		if ( ! is_array( $s ) || empty( $s['id'] ) || 'paid' !== ( $s['payment_status'] ?? '' ) ) { return false; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE session_id = %s', $s['id'] ), ARRAY_A ); // phpcs:ignore
		if ( ! $row || (int) ( $s['amount_total'] ?? -1 ) !== (int) $row['amount'] || (string) ( $s['client_reference_id'] ?? '' ) !== (string) $row['reg_id'] ) { return false; }
		if ( 'paid' !== $row['status'] ) {
			$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'paid', paid_at = %s, payment_intent = %s WHERE id = %d AND status <> 'paid'", current_time( 'mysql' ), (string) ( $s['payment_intent'] ?? '' ), $row['id'] ) ); // phpcs:ignore
			if ( 1 === (int) $n ) {
				self::mail( (int) $row['reg_id'], $row );
				do_action( 'sdsc_paid', (int) $row['reg_id'], (int) $row['class_id'], $row );
			}
		}
		return true;
	}

	protected static function mail( $reg_id, $row ) {
		$reg = self::reg( $reg_id );
		if ( ! $reg ) { return; }
		$cid   = (int) $reg['class_id'];
		$name  = SDSC_Registration::class_label( $cid );
		$zoom  = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		$amt   = '$' . number_format( $row['amount'] / 100, 2 );
		$list  = class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::sessions( $cid ) : array();
		$rows  = '';
		foreach ( $list as $x ) {
			$rows .= '<li>' . esc_html( 'Session ' . $x['n'] . ': ' . SDSC_Lessons::when( $x['start'], 'en' ) ) . ( 1 === (int) $x['n'] ? ' <b>(mandatory)</b>' : '' ) . '</li>';
		}
		$body = '<div style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#1d2327">'
			. '<p>Hi ' . esc_html( $reg['first_name'] ) . ',</p>'
			. '<p>Thank you — we received your payment of <b>' . esc_html( $amt ) . '</b>. Your seat in <b>' . esc_html( $name ) . '</b> is confirmed.</p>'
			. ( $rows ? '<p><b>Your class sessions</b></p><ol style="padding-left:18px">' . $rows . '</ol>' : '<p>We will e-mail you the class schedule shortly.</p>' )
			. ( $zoom ? '<p><b>Zoom link:</b> <a href="' . esc_url( $zoom ) . '">' . esc_html( $zoom ) . '</a><br>Camera and microphone on, at a desk, for the whole class.</p>' : '' )
			. ( $list && class_exists( 'SDSC_Schedule' ) ? '<p><a href="' . esc_url( SDSC_Schedule::cal_page( $reg['email'] ) ) . '">Add all sessions to my calendar</a></p>' : '' )
			. '<p>Questions? Call (301) 726-3030.<br>Sam’s Driving School</p></div>';
		$subj = 'Payment received — ' . html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
		$sent = class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::send( $reg['email'], $subj, $body, 'info', array( 'Content-Type: text/html; charset=UTF-8' ) ) : wp_mail( $reg['email'], $subj, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		wp_mail( get_option( 'admin_email' ), 'Paid online: ' . trim( $reg['first_name'] . ' ' . $reg['last_name'] ) . ' — ' . html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ), $amt . ' paid by card (' . $row['mode'] . ' mode). Registration #' . $reg_id . ', ' . $reg['email'] . '. Stripe session ' . $row['session_id'] . '.' );
		global $wpdb;
		if ( $sent ) { $wpdb->update( self::table(), array( 'mailed' => 1 ), array( 'id' => $row['id'] ) ); }
	}

	/* ------------------------------------------------------------ webhook */

	public static function routes() {
		register_rest_route( 'sdsc/v1', '/stripe', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'webhook' ),
			'permission_callback' => '__return_true', // authenticated by the Stripe-Signature check below
		) );
	}

	public static function webhook( WP_REST_Request $req ) {
		$secret = class_exists( 'SDSC_Api' ) ? SDSC_Api::stripe_webhook_secret() : '';
		$body   = $req->get_body();
		if ( '' === $secret || ! self::verify( $body, (string) $req->get_header( 'stripe_signature' ), $secret ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$e = json_decode( $body, true );
		if ( in_array( $e['type'] ?? '', array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) ) {
			self::settle( $e['data']['object'] ?? array() );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** Stripe-Signature: t=..., v1=... ; HMAC-SHA256 of "t.payload", 5-minute tolerance. */
	public static function verify( $payload, $header, $secret ) {
		$t = 0; $sigs = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) { continue; }
			if ( 't' === $kv[0] ) { $t = (int) $kv[1]; } elseif ( 'v1' === $kv[0] ) { $sigs[] = $kv[1]; }
		}
		if ( ! $t || ! $sigs || abs( time() - $t ) > 300 ) { return false; }
		$want = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sigs as $s ) { if ( hash_equals( $want, $s ) ) { return true; } }
		return false;
	}
}
