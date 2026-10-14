<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Refunds — full, or prorated by the school's refund policy (policy 15):
 * the course price is $378 (30% Zoom classes, 70% behind the wheel); each completed Zoom class is $11.50
 * and each behind-the-wheel session $88. What was used is kept, the rest goes back.
 *
 *  1. Find the student → the screen lists their payments (Stripe Checkout / Payment Links and WooCommerce
 *     orders) with what can still be refunded.
 *  2. Count the Zoom classes and BTW sessions used (BTW is pre-filled from the lessons marked Completed)
 *     → the screen shows the calculation.
 *  3. Review → one more screen with the exact amount and where it goes → "Refund $X".
 *
 * Money is only moved by that last button, by a user with the Refunds permission. Stripe refunds use an
 * idempotency key, so a double click cannot refund twice. A refund paid outside the website (cash, Zelle,
 * check) can be recorded without moving money. Every refund is kept as a record that the staff and the
 * student both see.
 */
class SDSC_Refunds {
	const DBV  = '1';
	const DBO  = 'sdsc_refunds_db';
	const SET  = 'sdsc_refunds_set';
	const PAGE = 'sdsc-refunds';

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_refunds'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 21 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 34 );
		foreach ( array( 'review', 'do', 'set' ) as $a ) { add_action( 'admin_post_sdsc_rf_' . $a, array( __CLASS__, 'h_' . $a ) ); }
	}

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL DEFAULT '',
			name varchar(160) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT '',
			source_ref varchar(80) NOT NULL DEFAULT '',
			paid decimal(10,2) NOT NULL DEFAULT 0,
			classes smallint(5) unsigned NOT NULL DEFAULT 0,
			class_rate decimal(8,2) NOT NULL DEFAULT 0,
			btw smallint(5) unsigned NOT NULL DEFAULT 0,
			btw_rate decimal(8,2) NOT NULL DEFAULT 0,
			other decimal(10,2) NOT NULL DEFAULT 0,
			other_label varchar(120) NOT NULL DEFAULT '',
			amount decimal(10,2) NOT NULL DEFAULT 0,
			kind varchar(10) NOT NULL DEFAULT 'prorated',
			status varchar(12) NOT NULL DEFAULT 'pending',
			refund_ref varchar(80) NOT NULL DEFAULT '',
			err varchar(255) NOT NULL DEFAULT '',
			asked_on date DEFAULT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			lang char(2) NOT NULL DEFAULT 'en',
			by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::SET, array() ), array(
			'price'      => 378,
			'class_rate' => 11.5,
			'classes'    => 10,
			'btw_rate'   => 88,
			'btw'        => 3,
		) );
	}

	public static function can() {
		return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'refunds' ) : current_user_can( 'manage_options' );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Refunds', 'Refunds', 'sdsc_access_refunds', self::PAGE, array( __CLASS__, 'page' ) );
	}

	protected static function money( $n ) {
		$n = round( (float) $n, 2 );
		return floor( $n ) == $n ? number_format( $n, 0 ) : number_format( $n, 2 ); // phpcs:ignore
	}

	protected static function url( $tab = 'list', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	protected static function back( $tab, $args = array() ) {
		wp_safe_redirect( self::url( $tab, array_map( 'rawurlencode', $args ) ) );
		exit;
	}

	/* ================================================================ payments */

	protected static function stripe( $method, $path, $args = array(), $idem = '' ) {
		$key = class_exists( 'SDSC_Api' ) ? SDSC_Api::stripe_key() : '';
		if ( '' === $key ) { return new WP_Error( 'nokey', 'Stripe is not connected.' ); }
		$h = array( 'Authorization' => 'Bearer ' . $key );
		if ( $idem ) { $h['Idempotency-Key'] = $idem; }
		$url = 'https://api.stripe.com/v1/' . $path;
		$res = 'GET' === $method
			? wp_remote_get( $args ? $url . '?' . http_build_query( $args ) : $url, array( 'timeout' => 20, 'headers' => $h ) )
			: wp_remote_post( $url, array( 'timeout' => 30, 'headers' => $h, 'body' => $args ) );
		if ( is_wp_error( $res ) ) { return $res; }
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( (int) wp_remote_retrieve_response_code( $res ) >= 300 ) {
			return new WP_Error( 'stripe', 'Stripe: ' . ( $j['error']['message'] ?? 'error ' . wp_remote_retrieve_response_code( $res ) ) );
		}
		return is_array( $j ) ? $j : array();
	}

	/**
	 * Payments this e-mail made: array of [ id, source, ref, date, label, paid, refundable ].
	 * source: stripe (ref = PaymentIntent id) | woo (ref = order id).
	 */
	public static function payments( $email ) {
		$out = array();
		$err = '';
		if ( class_exists( 'SDSC_Api' ) && SDSC_Api::stripe_key() ) {
			$seen = array();
			$s    = self::stripe( 'GET', 'checkout/sessions', array( 'customer_details' => array( 'email' => $email ), 'limit' => 20 ) );
			if ( is_wp_error( $s ) ) { $err = $s->get_error_message(); }
			foreach ( is_array( $s ) ? (array) ( $s['data'] ?? array() ) : array() as $cs ) {
				if ( 'paid' !== ( $cs['payment_status'] ?? '' ) || empty( $cs['payment_intent'] ) ) { continue; }
				$pi = is_array( $cs['payment_intent'] ) ? $cs['payment_intent']['id'] : $cs['payment_intent'];
				if ( isset( $seen[ $pi ] ) ) { continue; }
				$seen[ $pi ] = 1;
				$p = self::stripe( 'GET', 'payment_intents/' . rawurlencode( $pi ), array( 'expand' => array( 'latest_charge' ) ) );
				if ( is_wp_error( $p ) ) { continue; }
				$ch      = is_array( $p['latest_charge'] ?? null ) ? $p['latest_charge'] : array();
				$paid    = ( $ch['amount_captured'] ?? $p['amount_received'] ?? 0 ) / 100;
				$refunded = ( $ch['amount_refunded'] ?? 0 ) / 100;
				$out[]   = array(
					'id'         => 'stripe:' . $pi,
					'source'     => 'stripe',
					'ref'        => $pi,
					'date'       => (int) ( $p['created'] ?? 0 ),
					'label'      => 'Stripe card payment' . ( ! empty( $ch['payment_method_details']['card']['last4'] ) ? ' · card ••' . $ch['payment_method_details']['card']['last4'] : '' ),
					'paid'       => $paid,
					'refundable' => max( 0, $paid - $refunded ),
				);
			}
		}
		if ( function_exists( 'wc_get_orders' ) ) {
			foreach ( wc_get_orders( array( 'billing_email' => $email, 'status' => array( 'processing', 'completed' ), 'limit' => 20 ) ) as $o ) {
				$paid  = (float) $o->get_total();
				$out[] = array(
					'id'         => 'woo:' . $o->get_id(),
					'source'     => 'woo',
					'ref'        => (string) $o->get_id(),
					'date'       => $o->get_date_paid() ? $o->get_date_paid()->getTimestamp() : $o->get_date_created()->getTimestamp(),
					'label'      => 'Website order #' . $o->get_order_number() . ' · ' . $o->get_payment_method_title(),
					'paid'       => $paid,
					'refundable' => max( 0, $paid - (float) $o->get_total_refunded() ),
				);
			}
		}
		usort( $out, function ( $a, $b ) { return $b['date'] <=> $a['date']; } );
		return array( $out, $err );
	}

	/* ================================================================ screens */

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		wp_enqueue_style( 'sdsc-outreach', SDSC_URL . 'assets/outreach.css', array(), SDSC_VERSION );
		wp_enqueue_style( 'sdsc-lessons', SDSC_URL . 'assets/lessons.css', array( 'sdsc-outreach' ), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-lessons', SDSC_URL . 'assets/lessons.js', array(), SDSC_VERSION, true );
		$people = array();
		if ( class_exists( 'SDSC_Outreach' ) ) { foreach ( SDSC_Outreach::contacts() as $c ) { $people[] = array( $c['k'], $c['first'], $c['last'], $c['email'], $c['phone'], $c['lang'] ); } }
		wp_localize_script( 'sdsc-lessons', 'SDSC_LS', array( 'people' => $people ) );
		$tabs = array( 'list' => 'Refunds made', 'new' => 'New refund', 'settings' => 'Policy amounts' );
		$tab  = isset( $_GET['tab'] ) && ( isset( $tabs[ $_GET['tab'] ] ) || 'confirm' === $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'list'; // phpcs:ignore
		?>
		<div class="wrap sdo sdl">
			<h1 class="sdo-title"><span class="sdo-title-ic" aria-hidden="true">↩️</span> Refunds</h1>
			<?php if ( isset( $_GET['msg'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['err'] ) ) : // phpcs:ignore ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<nav class="sdo-tabs" aria-label="Sections">
				<?php foreach ( $tabs as $k => $label ) : $on = $k === $tab || ( 'confirm' === $tab && 'new' === $k ); ?>
					<a class="sdo-tab<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo esc_url( self::url( $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
		</div>
		<?php
	}

	protected static function tab_list() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 200', ARRAY_A ); // phpcs:ignore
		?>
		<p class="sdo-lead">Refunds follow the refund policy: the student asks in writing, the Zoom classes and behind-the-wheel sessions already used are kept, and the rest goes back. <a href="<?php echo esc_url( self::url( 'new' ) ); ?>">Start a refund &rarr;</a></p>
		<?php if ( ! $rows ) : ?><p class="sdo-empty">No refunds yet.</p><?php return; endif; ?>
		<table class="widefat striped sdl-table">
			<thead><tr><th>Date</th><th>Student</th><th>Paid</th><th>Kept</th><th>Refunded</th><th>How</th><th>By</th></tr></thead>
			<tbody><?php foreach ( $rows as $r ) : $u = get_userdata( $r['by_user'] ); ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'M j, Y', $r['created_at'] ) ); ?></td>
					<td><b><?php echo esc_html( $r['name'] ); ?></b><br><span class="sdo-muted"><?php echo esc_html( $r['email'] ); ?></span></td>
					<td>$<?php echo esc_html( self::money( $r['paid'] ) ); ?></td>
					<td><?php echo esc_html( self::kept_text( $r ) ); ?></td>
					<td><b>$<?php echo esc_html( self::money( $r['amount'] ) ); ?></b><?php echo 'failed' === $r['status'] ? '<br><span class="sdo-warn">Failed: ' . esc_html( $r['err'] ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( self::how_text( $r ) ); ?></td>
					<td><?php echo esc_html( $u ? $u->display_name : '—' ); ?><?php echo $r['note'] ? '<br><span class="sdo-muted">' . esc_html( $r['note'] ) . '</span>' : ''; ?></td>
				</tr>
			<?php endforeach; ?></tbody>
		</table>
		<?php
	}

	public static function kept_text( $r, $lang = 'en' ) {
		$es    = 'es' === $lang;
		$parts = array();
		if ( (int) $r['classes'] ) { $parts[] = (int) $r['classes'] . ( $es ? ' clases Zoom × $' : ' Zoom classes × $' ) . self::money( $r['class_rate'] ); }
		if ( (int) $r['btw'] ) { $parts[] = (int) $r['btw'] . ( $es ? ' clases prácticas × $' : ' BTW sessions × $' ) . self::money( $r['btw_rate'] ); }
		if ( (float) $r['other'] > 0 ) { $parts[] = ( $r['other_label'] ?: ( $es ? 'Otro' : 'Other' ) ) . ' $' . self::money( $r['other'] ); }
		return $parts ? implode( ' + ', $parts ) : ( $es ? 'Nada (reembolso completo)' : 'Nothing (full refund)' );
	}

	public static function how_text( $r ) {
		if ( 'stripe' === $r['source'] ) { return 'To the card (Stripe)' . ( $r['refund_ref'] ? ' · ' . $r['refund_ref'] : '' ); }
		if ( 'woo' === $r['source'] ) { return 'Website order #' . $r['source_ref']; }
		return 'Recorded only — ' . ( $r['source_ref'] ?: 'paid outside the website' );
	}

	protected static function tab_new() {
		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore
		$s     = self::settings();
		?>
		<form method="get" class="sdo-filter sdl-inline">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><input type="hidden" name="tab" value="new">
			<input type="search" class="sdl-find" list="sdl-people" placeholder="Student name or e-mail" autocomplete="off" style="min-width:280px">
			<datalist id="sdl-people"></datalist>
			<input type="email" name="email" class="sdl-email" value="<?php echo esc_attr( $email ); ?>" placeholder="E-mail" required>
			<button class="sdo-btn sdo-btn--ghost">Find payments</button>
		</form>
		<?php
		if ( ! $email ) { echo '<p class="sdo-empty">Find the student first. Their payments on the website and in Stripe are listed with the amount that can still be refunded.</p>'; return; }
		list( $pays, $err ) = self::payments( $email );
		$name = '';
		$btw  = 0;
		if ( class_exists( 'SDSC_Outreach' ) ) {
			foreach ( SDSC_Outreach::contacts() as $c ) { if ( $c['email'] === strtolower( $email ) ) { $name = trim( $c['first'] . ' ' . $c['last'] ); break; } }
		}
		if ( class_exists( 'SDSC_Lessons' ) ) {
			global $wpdb;
			$btw = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Lessons::t_lessons() . " WHERE email = %s AND status = 'done'", strtolower( $email ) ) ); // phpcs:ignore
		}
		?>
		<?php if ( $err ) : ?><p class="sdo-warn"><?php echo esc_html( $err ); ?></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdo-panel sdl-form sdl-calc" data-class="<?php echo esc_attr( $s['class_rate'] ); ?>" data-btw="<?php echo esc_attr( $s['btw_rate'] ); ?>">
			<?php wp_nonce_field( 'sdsc_rf' ); ?><input type="hidden" name="action" value="sdsc_rf_review"><input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
			<h2>1. Which payment?</h2>
			<?php foreach ( $pays as $i => $p ) : ?>
				<label class="sdo-radio sdl-pay-opt"><input type="radio" name="pay" value="<?php echo esc_attr( $p['id'] ); ?>" data-max="<?php echo esc_attr( $p['refundable'] ); ?>" data-paid="<?php echo esc_attr( $p['paid'] ); ?>"<?php checked( 0, $i ); ?><?php disabled( $p['refundable'] <= 0 ); ?>>
					<b>$<?php echo esc_html( self::money( $p['paid'] ) ); ?></b> · <?php echo esc_html( wp_date( 'M j, Y', $p['date'] ) . ' · ' . $p['label'] ); ?>
					<?php echo $p['refundable'] < $p['paid'] ? ' <span class="sdo-muted">(already refunded $' . esc_html( self::money( $p['paid'] - $p['refundable'] ) ) . ')</span>' : ''; ?>
				</label>
			<?php endforeach; ?>
			<label class="sdo-radio sdl-pay-opt"><input type="radio" name="pay" value="manual"<?php checked( ! $pays ); ?> data-max="" data-paid=""> Paid outside the website (cash, Zelle, check) &mdash; <b>only record</b> a refund you give back the same way</label>
			<div class="sdl-grid sdl-manual"<?php echo $pays ? ' hidden' : ''; ?>>
				<label class="sdo-f"><span>Amount the student paid ($)</span><input type="number" name="mpaid" min="0" step="0.01" class="sdl-mpaid"></label>
				<label class="sdo-f"><span>Refunded by</span><select name="mhow"><option>Cash</option><option>Zelle</option><option>Check</option><option>Card at the office</option><option>Other</option></select></label>
			</div>

			<h2>2. What was used</h2>
			<div class="sdl-grid">
				<label class="sdo-f"><span>Zoom classes attended (of <?php echo (int) $s['classes']; ?>) × $<?php echo esc_html( self::money( $s['class_rate'] ) ); ?></span><input type="number" name="classes" min="0" max="60" value="0" class="sdl-c"></label>
				<label class="sdo-f"><span>BTW sessions done (of <?php echo (int) $s['btw']; ?>) × $<?php echo esc_html( self::money( $s['btw_rate'] ) ); ?></span><input type="number" name="btw" min="0" max="60" value="<?php echo (int) $btw; ?>" class="sdl-b"></label>
				<label class="sdo-f"><span>Other amount kept ($, optional)</span><input type="number" name="other" min="0" step="0.01" value="0" class="sdl-o"></label>
				<label class="sdo-f"><span>What it is for</span><input type="text" name="other_label" maxlength="120" placeholder="e.g. late cancellation fee"></label>
			</div>
			<?php if ( $btw ) : ?><p class="sdo-note">BTW sessions are pre-filled from the lessons marked Completed (<?php echo (int) $btw; ?>).</p><?php endif; ?>
			<label class="sdo-check"><input type="checkbox" name="full" value="1" class="sdl-full"> Full refund (keep nothing)</label>

			<div class="sdl-result" aria-live="polite"></div>

			<h2>3. Details</h2>
			<div class="sdl-grid">
				<label class="sdo-f"><span>Student name</span><input type="text" name="name" value="<?php echo esc_attr( $name ); ?>" required></label>
				<label class="sdo-f"><span>Written request received on</span><input type="date" name="asked" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
				<label class="sdo-f sdo-f--wide"><span>Note (kept on the record)</span><input type="text" name="note" maxlength="250" placeholder="e.g. moving out of state"></label>
				<label class="sdo-f"><span>E-mail language</span><select name="lang"><option value="en">English</option><option value="es">Español</option></select></label>
			</div>
			<p><button class="sdo-btn">Review the refund &rarr;</button></p>
		</form>
		<?php
	}

	/** Review: everything checked again on the server; the confirm screen shows the exact numbers. */
	public static function h_review() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_rf' );
		$p     = wp_unslash( $_POST ); // phpcs:ignore -- sanitised below
		$s     = self::settings();
		$email = strtolower( sanitize_email( $p['email'] ?? '' ) );
		$pay   = sanitize_text_field( $p['pay'] ?? '' );
		$full  = ! empty( $p['full'] );
		$r     = array(
			'email'       => $email,
			'name'        => sanitize_text_field( $p['name'] ?? '' ),
			'classes'     => $full ? 0 : min( 60, max( 0, (int) ( $p['classes'] ?? 0 ) ) ),
			'class_rate'  => (float) $s['class_rate'],
			'btw'         => $full ? 0 : min( 60, max( 0, (int) ( $p['btw'] ?? 0 ) ) ),
			'btw_rate'    => (float) $s['btw_rate'],
			'other'       => $full ? 0 : max( 0, round( (float) ( $p['other'] ?? 0 ), 2 ) ),
			'other_label' => sanitize_text_field( $p['other_label'] ?? '' ),
			'asked_on'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p['asked'] ?? '' ) ? $p['asked'] : null,
			'note'        => sanitize_text_field( $p['note'] ?? '' ),
			'lang'        => 'es' === ( $p['lang'] ?? '' ) ? 'es' : 'en',
			'kind'        => $full ? 'full' : 'prorated',
		);
		if ( ! is_email( $email ) ) { self::back( 'new', array( 'err' => 'Missing e-mail.' ) ); }
		if ( 'manual' === $pay ) {
			$r['source']     = 'manual';
			$r['source_ref'] = sanitize_text_field( $p['mhow'] ?? 'Cash' );
			$r['paid']       = max( 0, round( (float) ( $p['mpaid'] ?? 0 ), 2 ) );
			$max             = $r['paid'];
		} else {
			$found = null;
			foreach ( self::payments( $email )[0] as $x ) { if ( $x['id'] === $pay ) { $found = $x; } }
			if ( ! $found ) { self::back( 'new', array( 'email' => $email, 'err' => 'That payment was not found any more. Please pick it again.' ) ); }
			$r['source']     = $found['source'];
			$r['source_ref'] = $found['ref'];
			$r['paid']       = $found['paid'];
			$max             = $found['refundable'];
		}
		$kept        = $r['classes'] * $r['class_rate'] + $r['btw'] * $r['btw_rate'] + $r['other'];
		$r['amount'] = round( max( 0, min( $max, $r['paid'] - $kept ) ), 2 );
		if ( $r['amount'] <= 0 ) { self::back( 'new', array( 'email' => $email, 'err' => 'Nothing to refund: what was used is at least what was paid.' ) ); }
		$tok = wp_generate_password( 20, false );
		if ( 'manual' !== $r['source'] ) { $r = self::new_code( $r ); } // real money moves: needs the e-mailed code
		set_transient( 'sdsc_rf_' . get_current_user_id() . '_' . $tok, $r, 30 * MINUTE_IN_SECONDS );
		self::back( 'confirm', array( 't' => $tok ) );
	}

	/* ------------------------------------------------------------ approval code */

	const CODE_TTL = 15; // minutes
	const CODE_TRIES = 5;

	/** Who must see the code: the site administrator e-mail and every School Manager (office). */
	public static function approvers() {
		$to = array( strtolower( (string) get_option( 'admin_email' ) ) );
		if ( class_exists( 'SDSC_Access' ) ) {
			foreach ( get_users( array( 'role' => SDSC_Access::MANAGER, 'fields' => array( 'ID', 'user_email' ) ) ) as $u ) {
				if ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview( (int) $u->ID ) ) { continue; } // never the "preview as" accounts
				$to[] = strtolower( $u->user_email );
			}
		}
		return array_values( array_unique( array_filter( $to, 'is_email' ) ) );
	}

	protected static function code_hash( $code ) { return hash_hmac( 'sha256', (string) $code, wp_salt( 'nonce' ) . 'sdsc-refund' ); }

	protected static function new_code( $r ) {
		$code           = (string) random_int( 100000, 999999 );
		$r['_code']     = self::code_hash( $code );
		$r['_code_exp'] = time() + self::CODE_TTL * MINUTE_IN_SECONDS;
		$r['_tries']    = 0;
		$by             = wp_get_current_user();
		$args = array(
			'to'         => implode( ',', self::approvers() ),
			'subject_en' => 'Refund approval code ' . $code . ' — $' . self::money( $r['amount'] ) . ' to ' . $r['name'],
			'subject_fa' => 'کد تأیید بازپرداخت',
			'title_en'   => 'Refund approval code: ' . $code,
			'title_fa'   => 'کد تأیید بازپرداخت: ' . $code,
			'lead_en'    => $by->display_name . ' asked to refund money on the website. The refund goes through only after this code is typed in. If you did not expect this, do not share the code.',
			'lead_fa'    => $by->display_name . ' درخواست بازپرداخت پول را در سایت ثبت کرد. بازپرداخت فقط بعد از وارد کردن این کد انجام می‌شود. اگر انتظارش را نداشتید، کد را به کسی ندهید.',
			'rows'       => array(
				array( 'en' => 'Code', 'fa' => 'کد', 'v' => $code, 'ltr' => true ),
				array( 'en' => 'Valid for', 'fa' => 'اعتبار', 'v' => self::CODE_TTL . ' minutes', 'v_fa' => self::CODE_TTL . ' دقیقه' ),
				array( 'en' => 'Student', 'fa' => 'شاگرد', 'v' => $r['name'] . ' · ' . $r['email'], 'ltr' => true ),
				array( 'en' => 'Paid', 'fa' => 'مبلغ پرداختی', 'v' => '$' . self::money( $r['paid'] ), 'ltr' => true ),
				array( 'en' => 'Refund', 'fa' => 'مبلغ بازپرداخت', 'v' => '$' . self::money( $r['amount'] ), 'ltr' => true ),
				array( 'en' => 'Goes back through', 'fa' => 'از طریق', 'v' => 'stripe' === $r['source'] ? 'Stripe — card (' . SDSC_Api::mode() . ' mode)' : 'Website order #' . $r['source_ref'], 'ltr' => true ),
				array( 'en' => 'Asked by', 'fa' => 'درخواست‌دهنده', 'v' => $by->display_name . ' · ' . $by->user_email . ' · IP ' . sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 'ltr' => true ), // phpcs:ignore
				array( 'en' => 'Note', 'fa' => 'توضیح', 'v' => $r['note'], 'ltr' => true ),
			),
			'note_en'    => 'Never give this code over the phone to someone who calls you.',
			'note_fa'    => 'این کد را هرگز تلفنی به کسی که با شما تماس می‌گیرد ندهید.',
		);
		if ( class_exists( 'SDSC_Mailer' ) ) { SDSC_Mailer::admin_report( $args ); } else { wp_mail( $args['to'], $args['subject_en'], $args['lead_en'] . "\n\nCode: " . $code ); }
		return $r;
	}

	protected static function tab_confirm() {
		$tok = sanitize_key( $_GET['t'] ?? '' ); // phpcs:ignore
		$r   = get_transient( 'sdsc_rf_' . get_current_user_id() . '_' . $tok );
		if ( ! is_array( $r ) ) { echo '<p class="sdo-empty">This review has expired. <a href="' . esc_url( self::url( 'new' ) ) . '">Start again</a>.</p>'; return; }
		$kept = $r['paid'] - $r['amount'];
		?>
		<section class="sdo-panel sdl-confirm">
			<h2>Check before refunding</h2>
			<dl class="sdl-dl">
				<dt>Student</dt><dd><?php echo esc_html( $r['name'] . ' · ' . $r['email'] ); ?></dd>
				<dt>Paid</dt><dd>$<?php echo esc_html( self::money( $r['paid'] ) ); ?></dd>
				<dt>Kept</dt><dd><?php echo esc_html( self::kept_text( $r ) ); ?> = <b>$<?php echo esc_html( self::money( $kept ) ); ?></b></dd>
				<dt>Refund</dt><dd class="sdl-big">$<?php echo esc_html( self::money( $r['amount'] ) ); ?></dd>
				<dt>Goes back</dt><dd><?php echo esc_html( 'stripe' === $r['source'] ? 'To the card the student paid with, through Stripe (' . SDSC_Api::mode() . ' mode). Banks take 5–10 business days to show it.' : ( 'woo' === $r['source'] ? 'Through website order #' . $r['source_ref'] . ' and its payment method.' : 'You give it back yourself (' . $r['source_ref'] . '); the website only records it.' ) ); ?></dd>
				<?php if ( $r['note'] ) : ?><dt>Note</dt><dd><?php echo esc_html( $r['note'] ); ?></dd><?php endif; ?>
			</dl>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sdsc_rf_do' ); ?><input type="hidden" name="action" value="sdsc_rf_do"><input type="hidden" name="t" value="<?php echo esc_attr( $tok ); ?>">
				<label class="sdo-check"><input type="checkbox" name="mail" value="1" checked> E-mail the student a refund receipt</label>
				<label class="sdo-check"><input type="checkbox" name="sure" value="1" required> I checked the amount and the student asked in writing</label>
				<?php if ( ! empty( $r['_code'] ) ) : ?>
					<?php if ( ! empty( $_GET['codeerr'] ) ) : // phpcs:ignore ?><p class="sdo-err" style="color:#b32d2e;font-weight:700"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['codeerr'] ) ) ); // phpcs:ignore ?></p><?php endif; ?>
					<label class="sdo-f" style="display:block;margin:10px 0"><span style="display:block;font-weight:700">Approval code</span><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required style="font-size:1.4rem;letter-spacing:.3em;width:9em;padding:8px 10px"><small style="display:block;color:#555">A 6-digit code was e-mailed to the administrator and the school manager<?php echo esc_html( ' (' . count( self::approvers() ) . ' address' . ( 1 === count( self::approvers() ) ? '' : 'es' ) . ')' ); ?>. It works for <?php echo (int) self::CODE_TTL; ?> minutes and <?php echo (int) self::CODE_TRIES; ?> tries.</small></label>
				<?php endif; ?>
				<p><button class="sdo-btn sdo-btn--danger"><?php echo 'manual' === $r['source'] ? 'Record refund of $' : 'Refund $'; ?><?php echo esc_html( self::money( $r['amount'] ) ); ?></button> <a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::url( 'new', array( 'email' => $r['email'] ) ) ); ?>">Change</a></p>
			</form>
		</section>
		<?php
	}

	public static function h_do() {
		global $wpdb;
		if ( ! self::can() || ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview() ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_rf_do' );
		$tok = sanitize_key( $_POST['t'] ?? '' );
		$key = 'sdsc_rf_' . get_current_user_id() . '_' . $tok;
		$r   = get_transient( $key );
		if ( ! is_array( $r ) || empty( $_POST['sure'] ) ) { self::back( 'new', array( 'err' => 'Not refunded: the review expired or was not confirmed.' ) ); }
		if ( ! empty( $r['_code'] ) ) {
			$given = preg_replace( '/\D/', '', (string) ( $_POST['code'] ?? '' ) );
			if ( time() > (int) $r['_code_exp'] || (int) $r['_tries'] >= self::CODE_TRIES ) {
				delete_transient( $key );
				self::back( 'new', array( 'email' => $r['email'], 'err' => 'Not refunded: the approval code expired or had too many wrong tries. Start the refund again for a new code.' ) );
			}
			if ( ! hash_equals( (string) $r['_code'], self::code_hash( $given ) ) ) {
				$r['_tries'] = (int) $r['_tries'] + 1;
				set_transient( $key, $r, 30 * MINUTE_IN_SECONDS );
				self::back( 'confirm', array( 't' => $tok, 'codeerr' => 'Wrong approval code — ' . ( self::CODE_TRIES - $r['_tries'] ) . ' tries left. Nothing was refunded.' ) );
			}
		}
		delete_transient( $key );   // one use only
		$r['note'] = trim( $r['note'] . ( ! empty( $r['_code'] ) ? ' [approved with e-mailed code]' : '' ) );
		unset( $r['_code'], $r['_code_exp'], $r['_tries'] );
		$row = array_merge( $r, array( 'status' => 'pending', 'by_user' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( self::table(), $row );
		$id = (int) $wpdb->insert_id;
		$ok = true;
		$ref = '';
		$err = '';
		if ( 'stripe' === $r['source'] ) {
			$res = self::stripe( 'POST', 'refunds', array(
				'payment_intent' => $r['source_ref'],
				'amount'         => (int) round( $r['amount'] * 100 ),
				'reason'         => 'requested_by_customer',
				'metadata'       => array( 'sdsc_refund' => $id, 'student' => $r['email'] ),
			), 'sdsc-refund-' . $id );
			if ( is_wp_error( $res ) ) { $ok = false; $err = $res->get_error_message(); } else { $ref = $res['id'] ?? ''; }
		} elseif ( 'woo' === $r['source'] ) {
			$res = wc_create_refund( array( 'order_id' => (int) $r['source_ref'], 'amount' => $r['amount'], 'reason' => 'Refund per refund policy (' . self::kept_text( $r ) . ')', 'refund_payment' => true ) );
			if ( is_wp_error( $res ) ) { $ok = false; $err = $res->get_error_message(); } else { $ref = 'refund #' . $res->get_id(); }
		}
		$wpdb->update( self::table(), array( 'status' => $ok ? 'done' : 'failed', 'refund_ref' => $ref, 'err' => substr( $err, 0, 255 ) ), array( 'id' => $id ) );
		if ( $ok && 'stripe' === $r['source'] && class_exists( 'SDSC_Pay' ) ) {
			$total = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . self::table() . " WHERE source = 'stripe' AND source_ref = %s AND status = 'done'", $r['source_ref'] ) ); // phpcs:ignore
			SDSC_Pay::mark_refunded( $r['source_ref'], (int) round( $total * 100 ) );
		}
		if ( ! $ok ) { self::back( 'list', array( 'err' => 'The refund did not go through: ' . $err . ' Nothing was refunded; the attempt is kept in the list.' ) ); }
		if ( ! empty( $_POST['mail'] ) ) { self::mail( self::row( $id ) ); }
		self::back( 'list', array( 'msg' => ( 'manual' === $r['source'] ? 'Refund recorded: $' : 'Refunded $' ) . self::money( $r['amount'] ) . ' to ' . $r['name'] . '.' ) );
	}

	public static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	}

	protected static function mail( $r ) {
		if ( ! $r || ! class_exists( 'SDSC_Mailer' ) ) { return; }
		$es    = 'es' === $r['lang'];
		$first = strtok( $r['name'], ' ' ) ?: ( $es ? 'estudiante' : 'there' );
		$amt   = '$' . self::money( $r['amount'] );
		$where = 'stripe' === $r['source'] || 'woo' === $r['source']
			? ( $es ? 'Lo devolvimos a la tarjeta con la que pagó. Su banco puede tardar de 5 a 10 días hábiles en mostrarlo.' : 'It has been sent back to the card you paid with. Your bank may take 5–10 business days to show it.' )
			: ( $es ? 'Forma de devolución: ' . $r['source_ref'] . '.' : 'Refunded by: ' . $r['source_ref'] . '.' );
		$rows  = array(
			array( $es ? 'Pagado' : 'Paid', esc_html( '$' . self::money( $r['paid'] ) ) ),
			array( $es ? 'Servicios usados' : 'Services used', esc_html( self::kept_text( $r, $r['lang'] ) ) ),
			array( $es ? 'Reembolso' : 'Refund', '<b>' . esc_html( $amt ) . '</b>' ),
		);
		$t = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-radius:12px;background:#f6f4ee">';
		foreach ( $rows as $x ) { $t .= '<tr><td style="padding:10px 14px;font:600 13px Arial,sans-serif;color:#5b646d;width:40%">' . esc_html( $x[0] ) . '</td><td style="padding:10px 14px;font:15px Arial,sans-serif;color:#15191d">' . $x[1] . '</td></tr>'; }
		$t   .= '</table>';
		$body = SDSC_Outreach::email_body( $es ? 'Su reembolso' : 'Your refund', '', '', '', '↩️' )
			. '<p style="margin:0 0 16px">' . ( $es ? 'Hola ' : 'Hi ' ) . esc_html( $first ) . ',</p>'
			. '<p style="margin:0 0 16px">' . ( $es ? 'Procesamos su reembolso de <b>' . $amt . '</b> según nuestra política de reembolsos.' : 'We have processed your refund of <b>' . $amt . '</b> under our refund policy.' ) . '</p>'
			. $t . '<p style="margin:0 0 16px">' . esc_html( $where ) . '</p>'
			. '<p style="margin:0 0 16px">' . ( $es ? '¿Preguntas? Responda a este correo o llame al ' : 'Questions? Reply to this e-mail or call ' ) . esc_html( SDSC_Mailer::info()['phones'][0] ) . '.</p>';
		SDSC_Mailer::send( $r['email'], ( $es ? 'Reembolso de ' : 'Refund of ' ) . $amt . ' — Sam’s Driving School', $body, 'info' );
	}

	/** The student's refunds for their dashboard. */
	public static function student_html( $email, $lang = 'en' ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE email = %s AND status = 'done' ORDER BY id DESC", strtolower( $email ) ), ARRAY_A ); // phpcs:ignore
		if ( ! $rows ) { return ''; }
		$es = 'es' === $lang;
		$h  = '<h3>' . ( $es ? 'Reembolsos' : 'Refunds' ) . '</h3><div class="sdl-hist">';
		foreach ( $rows as $r ) {
			$h .= '<article class="sdl-h"><div class="sdl-h-top"><div><b>$' . esc_html( self::money( $r['amount'] ) ) . '</b> · ' . esc_html( mysql2date( 'M j, Y', $r['created_at'] ) ) . '<br><span class="sdl-m">'
				. esc_html( ( $es ? 'Pagado $' : 'Paid $' ) . self::money( $r['paid'] ) . ' · ' . ( $es ? 'usado: ' : 'used: ' ) . self::kept_text( $r, $lang ) ) . '</span></div><span class="sdl-pill sdl-pill--done">' . ( $es ? 'Reembolsado' : 'Refunded' ) . '</span></div></article>';
		}
		return $h . '</div>';
	}

	/* ---------------- settings */

	protected static function tab_settings() {
		$s = self::settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdo-panel sdo-settings sdl-form">
			<?php wp_nonce_field( 'sdsc_rf' ); ?><input type="hidden" name="action" value="sdsc_rf_set">
			<p class="sdo-note">From the refund policy (policy 15): course price $378 (30% Zoom classes, 70% behind the wheel); each completed Zoom class $11.50, each behind-the-wheel session $88. Change these only together with the policy page.</p>
			<div class="sdl-grid">
				<label class="sdo-f"><span>Course price ($)</span><input type="number" step="0.01" name="price" value="<?php echo esc_attr( $s['price'] ); ?>"></label>
				<label class="sdo-f"><span>Per Zoom class ($)</span><input type="number" step="0.01" name="class_rate" value="<?php echo esc_attr( $s['class_rate'] ); ?>"></label>
				<label class="sdo-f"><span>Zoom classes in the course</span><input type="number" name="classes" value="<?php echo (int) $s['classes']; ?>"></label>
				<label class="sdo-f"><span>Per BTW session ($)</span><input type="number" step="0.01" name="btw_rate" value="<?php echo esc_attr( $s['btw_rate'] ); ?>"></label>
				<label class="sdo-f"><span>BTW sessions in the course</span><input type="number" name="btw" value="<?php echo (int) $s['btw']; ?>"></label>
			</div>
			<p><button class="sdo-btn">Save</button></p>
		</form>
		<?php
	}

	public static function h_set() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_rf' );
		$p = wp_unslash( $_POST ); // phpcs:ignore
		update_option( self::SET, array(
			'price'      => max( 0, round( (float) ( $p['price'] ?? 378 ), 2 ) ),
			'class_rate' => max( 0, round( (float) ( $p['class_rate'] ?? 11.5 ), 2 ) ),
			'classes'    => max( 1, (int) ( $p['classes'] ?? 10 ) ),
			'btw_rate'   => max( 0, round( (float) ( $p['btw_rate'] ?? 88 ), 2 ) ),
			'btw'        => max( 1, (int) ( $p['btw'] ?? 3 ) ),
		), false );
		self::back( 'settings', array( 'msg' => 'Saved.' ) );
	}
}
