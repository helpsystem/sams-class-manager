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
	const DBV = '2';
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
			card varchar(40) NOT NULL DEFAULT '',
			receipt_url varchar(255) NOT NULL DEFAULT '',
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
		// Early bird (automatic, Discounts & offers → Early bird): the better of the two, never both.
		if ( class_exists( 'SDSC_Promo' ) ) {
			$eb = SDSC_Promo::earlybird( $class_id );
			if ( $eb && $eb['cents'] < (int) round( $n * 100 ) ) { $n = $eb['cents'] / 100; }
		}
		return (int) round( $n * 100 );
	}

	/** URL of the "Continue to payment" button, or '' when online payment is not possible for this class. */
	public static function url( $reg_id, $class_id ) {
		if ( ! self::ready() || ! self::amount( $class_id ) ) { return ''; }
		$u = add_query_arg( array( 'action' => 'sdsc_pay', 'r' => (int) $reg_id, 't' => self::token( $reg_id ) ), admin_url( 'admin-post.php' ) );
		return class_exists( 'SDSC_Short' ) ? SDSC_Short::make( $u ) : $u;
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
		$amount = class_exists( 'SDSC_Promo' ) ? SDSC_Promo::amount_for( $reg_id, $cid ) : self::amount( $cid );
		$promo  = class_exists( 'SDSC_Promo' ) ? SDSC_Promo::for_reg( $reg_id ) : null;
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
					'product_data' => array( 'name' => mb_substr( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ), 0, 200 ), 'description' => 'Student: ' . trim( $reg['first_name'] . ' ' . $reg['last_name'] ) . ( $promo && $amount < (int) $promo['list_cents'] ? ' · ' . ( 'EARLY-BIRD' === $promo['code'] ? $promo['label'] : 'Discount code ' . $promo['code'] . ' (' . $promo['label'] . ')' ) : '' ) ),
				),
			) ),
			'metadata'            => array( 'sdsc_reg' => $reg_id, 'sdsc_class' => $cid, 'site' => wp_parse_url( home_url(), PHP_URL_HOST ), 'promo' => $promo ? $promo['code'] : '' ),
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
				self::card_details( (int) $row['id'], (string) ( $s['payment_intent'] ?? '' ) );
				self::send_receipt( (int) $row['id'] );
				do_action( 'sdsc_paid', (int) $row['reg_id'], (int) $row['class_id'], $row );
			}
		}
		return true;
	}

	/** Card brand / last 4 and Stripe's own receipt link, read once after payment. */
	protected static function card_details( $id, $pi ) {
		if ( ! $pi ) { return; }
		list( $code, $p ) = self::stripe( 'GET', 'payment_intents/' . rawurlencode( $pi ), array( 'expand' => array( 'latest_charge' ) ) );
		if ( 200 !== $code ) { return; }
		$ch   = is_array( $p['latest_charge'] ?? null ) ? $p['latest_charge'] : array();
		$card = $ch['payment_method_details']['card'] ?? array();
		global $wpdb;
		$wpdb->update( self::table(), array(
			'card'        => $card ? mb_substr( ucfirst( (string) ( $card['brand'] ?? 'card' ) ) . ' •••• ' . preg_replace( '/\D/', '', (string) ( $card['last4'] ?? '' ) ), 0, 40 ) : ( 'link' === ( $ch['payment_method_details']['type'] ?? '' ) ? 'Card via Stripe Link' : '' ),
			'receipt_url' => esc_url_raw( (string) ( $ch['receipt_url'] ?? '' ) ),
		), array( 'id' => $id ) );
	}

	public static function receipt_no( $row ) {
		return 'SDS-' . substr( (string) $row['paid_at'], 0, 4 ) . '-' . str_pad( (string) $row['reg_id'], 6, '0', STR_PAD_LEFT );
	}

	protected static function money( $cents ) { return '$' . number_format( $cents / 100, 2 ); }

	/** Branded receipt to the student + a summary to the office. Public so staff can resend it. */
	public static function send_receipt( $pay_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE id = %d AND status = 'paid'", $pay_id ), ARRAY_A ); // phpcs:ignore
		$reg = $row ? self::reg( (int) $row['reg_id'] ) : null;
		if ( ! $reg ) { return false; }
		$cid     = (int) $reg['class_id'];
		$name    = html_entity_decode( SDSC_Registration::class_label( $cid ), ENT_QUOTES, 'UTF-8' );
		$title   = html_entity_decode( get_the_title( $cid ), ENT_QUOTES, 'UTF-8' );
		$days    = (string) get_post_meta( $cid, '_sdsc_days_time', true );
		$start   = (string) get_post_meta( $cid, '_sdsc_start', true );
		$end     = (string) get_post_meta( $cid, '_sdsc_end', true );
		$zoom    = (string) get_post_meta( $cid, '_sdsc_zoom_link', true );
		$list    = class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::sessions( $cid ) : array();
		$listed  = (float) get_post_meta( $cid, '_sdsc_price', true ) * 100;
		$pr        = class_exists( 'SDSC_Promo' ) ? SDSC_Promo::for_reg( (int) $row['reg_id'] ) : null;
		$promo_lbl = $pr ? ( 'EARLY-BIRD' === $pr['code'] ? $pr['label'] : 'Discount code ' . $pr['code'] . ' (' . $pr['label'] . ')' ) : 'Discount';
		$paid    = (int) $row['amount'];
		$no      = self::receipt_no( $row );
		$date    = wp_date( 'F j, Y \a\t g:i A', strtotime( get_gmt_from_date( $row['paid_at'] ) . ' UTC' ) );
		$test    = 'test' === $row['mode'];
		$student = trim( $reg['first_name'] . ' ' . $reg['last_name'] );
		$f       = 'font-family:Arial,Helvetica,sans-serif;';
		$tr      = function ( $k, $v, $strong = false ) use ( $f ) {
			return '<tr><td style="' . $f . 'padding:9px 0;border-bottom:1px solid #edf0f2;font-size:14px;color:#5b646d">' . esc_html( $k ) . '</td><td align="right" style="' . $f . 'padding:9px 0;border-bottom:1px solid #edf0f2;font-size:14px;color:#15191d;' . ( $strong ? 'font-weight:700' : '' ) . '">' . $v . '</td></tr>';
		};
		$btn = function ( $label, $url, $solid = true ) use ( $f ) {
			return '<td style="padding:4px"><a href="' . esc_url( $url ) . '" style="' . $f . 'display:inline-block;padding:12px 20px;border-radius:999px;font-size:14px;font-weight:700;text-decoration:none;' . ( $solid ? 'background:#00703c;color:#ffffff' : 'background:#ffffff;color:#00703c;border:1px solid #00703c' ) . '">' . esc_html( $label ) . '</a></td>';
		};

		$h  = $test ? '<div style="' . $f . 'margin:0 0 18px;padding:10px 14px;border-radius:10px;background:#fff6dc;color:#8a4b00;font-size:13px;font-weight:700;text-align:center">TEST PAYMENT — no real money was charged.</div>' : '';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:4px 0 22px">'
			. '<div style="width:58px;height:58px;margin:0 auto;border-radius:50%;background:#e7f5ec;color:#00703c;font:800 30px/58px Arial,Helvetica,sans-serif;text-align:center">&#10003;</div>'
			. '<div style="' . $f . 'margin:14px 0 2px;font-size:13px;letter-spacing:1.5px;text-transform:uppercase;color:#5b646d;font-weight:700">Payment receipt</div>'
			. '<div style="' . $f . 'font-size:38px;font-weight:800;color:#15191d;letter-spacing:-1px">' . esc_html( self::money( $paid ) ) . '</div>'
			. '<div style="' . $f . 'font-size:14px;color:#5b646d">Paid ' . esc_html( $date ) . '</div></td></tr></table>';

		$h .= '<p style="' . $f . 'margin:0 0 18px;font-size:15px;line-height:1.6;color:#15191d">Hi ' . esc_html( $reg['first_name'] ) . ', thank you for enrolling. Your payment was received and your seat is confirmed. Please keep this e-mail as your receipt.</p>';

		// Receipt details.
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 6px">'
			. $tr( 'Receipt number', esc_html( $no ), true )
			. $tr( 'Date paid', esc_html( $date ) )
			. $tr( 'Payment method', esc_html( $row['card'] ? $row['card'] : 'Card' ) )
			. $tr( 'Student', esc_html( $student ) )
			. $tr( 'E-mail', esc_html( $reg['email'] ) )
			. '</table>';

		// Line items.
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 22px;border:1px solid #e3e7ea;border-radius:12px;border-collapse:separate">'
			. '<tr><td style="' . $f . 'padding:14px 16px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#5b646d;font-weight:700;border-bottom:1px solid #e3e7ea">Description</td><td align="right" style="' . $f . 'padding:14px 16px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#5b646d;font-weight:700;border-bottom:1px solid #e3e7ea">Amount</td></tr>'
			. '<tr><td style="' . $f . 'padding:14px 16px;font-size:15px;color:#15191d"><b>' . esc_html( $title ) . '</b><br><span style="font-size:13px;color:#5b646d">' . esc_html( trim( ( $start ? 'Starts ' . date_i18n( 'M j, Y', strtotime( $start ) ) : '' ) . ( $days ? ' · ' . $days : '' ) ) ) . '</span></td><td align="right" style="' . $f . 'padding:14px 16px;font-size:15px;color:#15191d;white-space:nowrap">' . esc_html( self::money( $listed > $paid ? $listed : $paid ) ) . '</td></tr>'
			. ( $listed > $paid ? '<tr><td style="' . $f . 'padding:0 16px 12px;font-size:14px;color:#00703c">' . esc_html( $promo_lbl ) . '</td><td align="right" style="' . $f . 'padding:0 16px 12px;font-size:14px;color:#00703c">&minus;' . esc_html( self::money( $listed - $paid ) ) . '</td></tr>' : '' )
			. '<tr><td style="' . $f . 'padding:14px 16px;font-size:16px;font-weight:800;color:#15191d;border-top:2px solid #15191d;background:#f7f9fa;border-radius:0 0 0 12px">Total paid</td><td align="right" style="' . $f . 'padding:14px 16px;font-size:16px;font-weight:800;color:#15191d;border-top:2px solid #15191d;background:#f7f9fa;border-radius:0 0 12px 0">' . esc_html( self::money( $paid ) ) . ' <span style="display:inline-block;margin-left:6px;padding:2px 9px;border-radius:999px;background:#00703c;color:#ffffff;font-size:11px;letter-spacing:1px;vertical-align:2px">PAID</span></td></tr>'
			. '</table>';

		// Class details.
		$h .= '<div style="' . $f . 'margin:0 0 6px;font-size:17px;font-weight:800;color:#00703c">Your class</div>';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px">'
			. $tr( 'Course', esc_html( $title ) )
			. ( $start ? $tr( 'Dates', esc_html( date_i18n( 'M j', strtotime( $start ) ) . ( $end ? ' – ' . date_i18n( 'M j, Y', strtotime( $end ) ) : '' ) ) ) : '' )
			. ( $days ? $tr( 'Days & time', esc_html( $days ) ) : '' )
			. $tr( 'Where', $zoom ? '<a href="' . esc_url( $zoom ) . '" style="color:#00703c;font-weight:700">Online on Zoom</a>' : 'Online on Zoom — link sent before the first class' )
			. '</table>';
		if ( $list ) {
			$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px">';
			foreach ( $list as $x ) {
				$h .= '<tr><td width="34" style="' . $f . 'padding:6px 0;font-size:13px;font-weight:700;color:#00703c">' . (int) $x['n'] . '</td><td style="' . $f . 'padding:6px 0;font-size:14px;color:#15191d">' . esc_html( SDSC_Lessons::when( $x['start'], 'en' ) ) . ( 1 === (int) $x['n'] ? ' <span style="color:#b32d2e;font-weight:700">· mandatory</span>' : '' ) . '</td></tr>';
			}
			$h .= '</table>';
		}
		$h .= '<p style="' . $f . 'margin:0 0 6px;font-size:14px;line-height:1.6;color:#15191d"><b>Before the first class:</b> join from a computer with camera and microphone on, sitting at a desk, for the whole class. Session 1 is mandatory under MVA rules.</p>';

		// Buttons.
		$bt = array();
		if ( $list && class_exists( 'SDSC_Schedule' ) ) { $bt[] = $btn( 'Add to my calendar', SDSC_Schedule::cal_page( $reg['email'] ) ); }
		if ( $zoom ) { $bt[] = $btn( 'Zoom link', $zoom, ! $bt ); }
		if ( $row['receipt_url'] ) { $bt[] = $btn( 'Stripe receipt', $row['receipt_url'], false ); }
		if ( $bt ) { $h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:18px auto 6px"><tr>' . implode( '', $bt ) . '</tr></table>'; }

		$h .= '<p style="' . $f . 'margin:18px 0 0;font-size:12px;line-height:1.6;color:#8a929a;text-align:center">Refunds follow our <a href="' . esc_url( home_url( '/policy/' ) ) . '" style="color:#5b646d">school policies</a>. Sam’s Driving School LLC · 751 Rockville Pike, Unit 9B, Rockville, MD 20852 · (301) 726-3030</p>';

		$subj = ( $test ? '[TEST] ' : '' ) . 'Your receipt ' . $no . ' — ' . $title;
		$hdr  = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent = class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::send( $reg['email'], $subj, $h, 'info', $hdr ) : wp_mail( $reg['email'], $subj, $h, $hdr );

		// Office copy (English + Persian).
		$dash = 'https://dashboard.stripe.com/' . ( $test ? 'test/' : '' ) . 'payments/' . rawurlencode( $row['payment_intent'] );
		$office = array(
			'subject_en' => ( $test ? '[TEST] ' : '' ) . 'New enrollment: ' . $student . ' — ' . $title . ' — ' . self::money( $paid ),
			'subject_fa' => 'پرداخت جدید ثبت‌نام',
			'title_en'   => 'New paid enrollment' . ( $test ? ' (test)' : '' ) . ' — ' . self::money( $paid ),
			'title_fa'   => 'ثبت‌نام پرداخت‌شده' . ( $test ? ' (آزمایشی)' : '' ) . ' — ' . self::money( $paid ),
			'rows'       => array(
				array( 'en' => 'Student', 'fa' => 'نام شاگرد', 'v' => $student, 'ltr' => true ),
				array( 'en' => 'E-mail', 'fa' => 'ایمیل', 'v' => $reg['email'], 'ltr' => true ),
				array( 'en' => 'Phone', 'fa' => 'تلفن', 'v' => $reg['phone'], 'ltr' => true ),
				array( 'en' => 'Class', 'fa' => 'کلاس', 'v' => $name, 'ltr' => true ),
				array( 'en' => 'Class dates', 'fa' => 'تاریخ کلاس', 'v' => trim( ( $start ? date_i18n( 'M j', strtotime( $start ) ) : '' ) . ( $end ? ' – ' . date_i18n( 'M j, Y', strtotime( $end ) ) : '' ) . ( $days ? "\n" . $days : '' ) ), 'ltr' => true ),
				array( 'en' => 'Listed price', 'fa' => 'قیمت اصلی', 'v' => self::money( $listed > $paid ? $listed : $paid ), 'ltr' => true ),
				array( 'en' => 'Discount', 'fa' => 'تخفیف', 'v' => $listed > $paid ? $promo_lbl . ' (−' . self::money( $listed - $paid ) . ')' : '', 'ltr' => true ),
				array( 'en' => 'Amount paid', 'fa' => 'مبلغ پرداختی', 'v' => self::money( $paid ), 'ltr' => true ),
				array( 'en' => 'Receipt', 'fa' => 'شماره رسید', 'v' => $no, 'ltr' => true ),
				array( 'en' => 'Card', 'fa' => 'کارت', 'v' => $row['card'] ? $row['card'] : '', 'ltr' => true ),
				array( 'en' => 'Paid at', 'fa' => 'زمان پرداخت', 'v' => $date, 'ltr' => true ),
				array( 'en' => 'Receipt e-mailed to student', 'fa' => 'رسید برای شاگرد ایمیل شد', 'v' => $sent ? 'Yes' : 'No — please resend', 'v_fa' => $sent ? 'بله' : 'خیر — لطفاً دوباره بفرستید' ),
			),
			'note_en'    => $test ? 'This was a test payment (Stripe test mode). No real money moved.' : '',
			'note_fa'    => $test ? 'این یک پرداخت آزمایشی بود (حالت تست Stripe). پول واقعی جابه‌جا نشد.' : '',
			'buttons'    => array_filter( array(
				array( 'en' => 'Open the registration', 'fa' => 'باز کردن ثبت‌نام', 'url' => admin_url( 'admin.php?page=sdsc-registrations&view=' . (int) $row['reg_id'] ) ),
				$row['payment_intent'] ? array( 'en' => 'Open in Stripe', 'fa' => 'باز کردن در Stripe', 'url' => $dash ) : null,
			) ),
		);
		if ( class_exists( 'SDSC_Mailer' ) ) { SDSC_Mailer::admin_report( $office ); } else { wp_mail( get_option( 'admin_email' ), $office['subject_en'], $office['title_en'], $hdr ); }

		if ( $sent ) { $wpdb->update( self::table(), array( 'mailed' => 1 ), array( 'id' => $row['id'] ) ); }
		return (bool) $sent;
	}

	/** wp-cli / staff: fill card details if missing and send the receipt again. */
	public static function resend( $reg_id ) {
		$row = self::paid_row( (int) $reg_id );
		if ( ! $row ) { return false; }
		if ( '' === $row['card'] ) { self::card_details( (int) $row['id'], (string) $row['payment_intent'] ); }
		return self::send_receipt( (int) $row['id'] );
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
