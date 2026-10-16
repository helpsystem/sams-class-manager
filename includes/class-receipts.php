<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Receipts for money handled by hand (cash, check, Zelle …), both ways:
 *  - received from a student: class payments recorded on the Class board, lesson fees paid at the office;
 *  - paid to an instructor: payouts recorded in DriveFlow.
 *
 * Each payment gets one numbered receipt (R-26-00012 received, P-26-00007 paid out) and a private link
 * (long random token) that prints cleanly on letter paper. From the receipt screen the office can print it,
 * copy the link, e-mail it, or send it as a text (Twilio when connected, otherwise the phone's Messages app).
 * The receipt shows what was recorded when it was made; if the payment is later cancelled or deleted, the
 * receipt shows VOID. Card payments made online keep their own Stripe receipt.
 */
class SDSC_Receipts {

	const DBV = '1';
	const DBO = 'sdsc_receipts_db';
	const PAGE = 'sdsc-receipt';

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_receipts'; }

	public static function init() {
		add_action( 'init', array( __CLASS__, 'install' ), 5 );
		add_action( 'init', array( __CLASS__, 'public_view' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_sdsc_receipt_send', array( __CLASS__, 'send' ) );
	}

	public static function install() {
		if ( self::DBV === get_option( self::DBO ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			src varchar(10) NOT NULL DEFAULT '',
			src_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(3) NOT NULL DEFAULT 'in',
			token char(32) NOT NULL DEFAULT '',
			data longtext NULL,
			sent longtext NULL,
			by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY src (src,src_id),
			UNIQUE KEY token (token)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	/* ------------------------------------------------------------ who may */

	public static function can( $src ) {
		if ( ! is_user_logged_in() ) { return false; }
		if ( 'board' === $src ) { return class_exists( 'SDSC_Board' ) && SDSC_Board::can(); }
		if ( 'fee' === $src ) { return class_exists( 'SDSC_Lessons' ) && SDSC_Lessons::can(); }
		if ( 'payout' === $src ) { return current_user_can( 'manage_options' ) || ( current_user_can( 'driveflow_manage' ) && class_exists( 'SDSC_Access' ) && in_array( SDSC_Access::MANAGER, (array) wp_get_current_user()->roles, true ) ); }
		return false;
	}

	/** Link to the receipt screen for one payment (staff only). */
	public static function admin_url( $src, $id ) {
		return admin_url( 'admin.php?page=' . self::PAGE . '&src=' . rawurlencode( $src ) . '&id=' . (int) $id );
	}

	/* ------------------------------------------------------------ reading the payment */

	public static function method_label( $m ) {
		$m = strtolower( trim( (string) $m ) );
		$l = array( 'cash' => 'Cash', 'check' => 'Check', 'cheque' => 'Check', 'zelle' => 'Zelle', 'direct_deposit' => 'Direct deposit', 'bank' => 'Bank transfer', 'venmo' => 'Venmo', 'paypal' => 'PayPal', 'card' => 'Card at the office', 'other' => 'Other' );
		return $l[ $m ] ?? ucwords( str_replace( '_', ' ', $m ) );
	}

	/**
	 * The payment as it is now: array( kind, name, email, phone, cents, method, ref, date (Y-m-d), for, by, void ) or null.
	 */
	public static function source( $src, $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( 'board' === $src && class_exists( 'SDSC_Board' ) ) {
			$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Board::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
			if ( ! $p ) { return null; }
			$r = $p['reg_id'] && class_exists( 'SDSC_Registration' ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Registration::table() . ' WHERE id = %d', (int) $p['reg_id'] ), ARRAY_A ) : null; // phpcs:ignore
			$pid  = (int) $p['registrant_id'];
			$name = $r ? trim( $r['first_name'] . ' ' . $r['last_name'] ) : (string) get_post_meta( $pid, '_sdsc_name', true );
			$cid  = (int) $p['class_id'];
			$cls  = $cid ? ( class_exists( 'SDSC_Registration' ) ? SDSC_Registration::class_label( $cid ) : get_the_title( $cid ) ) : '';
			return array(
				'kind' => 'in', 'name' => $name, 'email' => (string) $p['email'], 'phone' => $r ? (string) $r['phone'] : (string) get_post_meta( $pid, '_sdsc_phone', true ),
				'cents' => (int) $p['amount'], 'method' => self::method_label( $p['method'] ), 'ref' => (string) $p['ref'], 'date' => (string) ( $p['paid_on'] ? $p['paid_on'] : substr( $p['created_at'], 0, 10 ) ),
				'for' => trim( "Driver's Education course" . ( $cls ? ' — ' . html_entity_decode( wp_strip_all_tags( $cls ), ENT_QUOTES, 'UTF-8' ) : '' ) ), 'note' => (string) $p['note'],
				'by' => self::user_name( (int) $p['by_user'] ), 'void' => 'paid' !== $p['status'],
			);
		}
		if ( 'fee' === $src && class_exists( 'SDSC_Lessons' ) ) {
			$f = SDSC_Lessons::fee( $id );
			if ( ! $f ) { return null; }
			$l = SDSC_Lessons::lesson( (int) $f['lesson_id'] );
			$how = (string) $f['paid_how'];
			if ( 0 === stripos( $how, 'Online' ) ) { return null; } // paid by card online: it has its own receipt
			$by  = preg_match( '/\(recorded by ([^)]+)\)\s*$/', $how, $m ) ? $m[1] : '';
			$how = trim( preg_replace( '/\(recorded by [^)]+\)\s*$/', '', $how ) );
			$ref = preg_match( '/#\s*([\w-]+)/', $how, $mm ) ? $mm[1] : '';
			$met = trim( preg_replace( '/#\s*[\w-]+/', '', $how ) );
			return array(
				'kind' => 'in', 'name' => $l ? trim( $l['first_name'] . ' ' . $l['last_name'] ) : '', 'email' => $l ? (string) $l['email'] : (string) $f['email'], 'phone' => $l ? (string) $l['phone'] : '',
				'cents' => (int) round( (float) $f['amount'] * 100 ), 'method' => $met ? $met : 'Paid at the office', 'ref' => $ref,
				'date' => $f['paid_at'] ? substr( (string) $f['paid_at'], 0, 10 ) : current_time( 'Y-m-d' ),
				'for' => SDSC_Lessons::reason_title( $f['reason'], 'en' ) . ( $l ? ' — behind-the-wheel lesson on ' . SDSC_Lessons::when( $l['starts_at'], 'en', false ) : '' ), 'note' => '',
				'by' => $by, 'void' => 'paid' !== $f['status'],
			);
		}
		if ( 'payout' === $src ) {
			$t = $wpdb->prefix . 'driveflow_instructor_payouts';
			if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { return null; }
			$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
			if ( ! $p ) { return null; }
			$i = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}driveflow_instructors WHERE id = %d", (int) $p['instructor_id'] ), ARRAY_A ); // phpcs:ignore
			$hrs  = (float) $p['hours_paid'];
			$per  = $p['period_start'] && $p['period_end'] ? wp_date( 'M j', strtotime( $p['period_start'] . ' 12:00' ) ) . ' – ' . wp_date( 'M j, Y', strtotime( $p['period_end'] . ' 12:00' ) ) : '';
			return array(
				'kind' => 'out', 'name' => $i ? (string) $i['name'] : (string) $p['instructor_name'], 'email' => $i ? (string) ( $i['email'] ?? '' ) : '', 'phone' => $i ? (string) ( $i['phone'] ?? '' ) : '',
				'cents' => (int) round( (float) $p['amount'] * 100 ), 'method' => self::method_label( $p['payment_method'] ), 'ref' => (string) $p['reference_number'], 'date' => (string) $p['payment_date'],
				'for' => 'Instructor pay' . ( $per ? ' for ' . $per : '' ) . ( $hrs > 0 ? ' — ' . rtrim( rtrim( number_format( $hrs, 2 ), '0' ), '.' ) . ' hours' . ( (float) $p['hourly_rate'] > 0 ? ' at $' . number_format( (float) $p['hourly_rate'], 2 ) . '/hour' : '' ) : '' ),
				'note' => (string) $p['notes'], 'by' => self::user_name( (int) $p['created_by'] ), 'void' => ! in_array( $p['status'], array( 'paid', 'archived' ), true ),
			);
		}
		return null;
	}

	protected static function user_name( $uid ) { $u = $uid ? get_userdata( $uid ) : null; return $u ? $u->display_name : ''; }

	/** The receipt row for a payment, made the first time it is asked for. */
	public static function get( $src, $id ) {
		global $wpdb;
		self::install();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE src = %s AND src_id = %d', $src, (int) $id ), ARRAY_A ); // phpcs:ignore
		if ( $row ) { return $row; }
		$s = self::source( $src, $id );
		if ( ! $s || $s['void'] ) { return null; }
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (src, src_id, kind, token, data, sent, by_user, created_at) VALUES (%s, %d, %s, %s, %s, %s, %d, %s)', $src, (int) $id, $s['kind'], wp_generate_password( 32, false, false ), wp_json_encode( $s ), '[]', get_current_user_id(), current_time( 'mysql' ) ) ); // phpcs:ignore
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE src = %s AND src_id = %d', $src, (int) $id ), ARRAY_A ); // phpcs:ignore
	}

	public static function number( $row ) {
		return ( 'out' === $row['kind'] ? 'P' : 'R' ) . '-' . substr( $row['created_at'], 2, 2 ) . '-' . str_pad( (string) (int) $row['id'], 5, '0', STR_PAD_LEFT );
	}

	public static function link( $row, $print = false ) {
		return add_query_arg( array_filter( array( 'sdsc_receipt' => $row['token'], 'print' => $print ? 1 : 0 ) ), home_url( '/' ) );
	}

	/** Snapshot taken when the receipt was made, with the live "void" state of the payment. */
	protected static function data( $row ) {
		$d    = json_decode( (string) $row['data'], true );
		$d    = is_array( $d ) ? $d : array();
		$now  = self::source( $row['src'], (int) $row['src_id'] );
		$d['void'] = ! $now || ! empty( $now['void'] );
		return $d;
	}

	/* ------------------------------------------------------------ words */

	public static function words( $cents ) {
		$n = intdiv( (int) $cents, 100 ); $c = (int) $cents % 100;
		$ones = array( '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen' );
		$tens = array( '', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety' );
		$three = function ( $x ) use ( $ones, $tens ) {
			$o = array();
			if ( $x >= 100 ) { $o[] = $ones[ intdiv( $x, 100 ) ] . ' hundred'; $x %= 100; }
			if ( $x >= 20 ) { $o[] = $tens[ intdiv( $x, 10 ) ] . ( $x % 10 ? '-' . $ones[ $x % 10 ] : '' ); } elseif ( $x ) { $o[] = $ones[ $x ]; }
			return implode( ' ', $o );
		};
		$parts = array();
		if ( $n >= 1000000 ) { $parts[] = $three( intdiv( $n, 1000000 ) ) . ' million'; $n %= 1000000; }
		if ( $n >= 1000 ) { $parts[] = $three( intdiv( $n, 1000 ) ) . ' thousand'; $n %= 1000; }
		if ( $n ) { $parts[] = $three( $n ); }
		$w = $parts ? implode( ' ', $parts ) : 'zero';
		return ucfirst( $w ) . ' and ' . sprintf( '%02d', $c ) . '/100 dollars';
	}

	/* ------------------------------------------------------------ the receipt itself */

	public static function html( $row ) {
		$d    = self::data( $row );
		$i    = class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::info() : array( 'name' => get_bloginfo( 'name' ), 'street' => '', 'city' => '', 'phones' => array(), 'email' => get_option( 'admin_email' ), 'web' => home_url( '/' ), 'logo' => '' );
		$out  = 'out' === $row['kind'];
		$no   = self::number( $row );
		$amt  = '$' . number_format( ( $d['cents'] ?? 0 ) / 100, 2 );
		$date = ! empty( $d['date'] ) ? wp_date( 'F j, Y', strtotime( $d['date'] . ' 12:00' ) ) : '';
		$meth = (string) ( $d['method'] ?? '' ) . ( ! empty( $d['ref'] ) ? ( false !== stripos( (string) $d['method'], 'check' ) ? ' #' : ' · ref ' ) . $d['ref'] : '' );
		$logo = $i['logo'] ? '<img class="rc-logo" src="' . esc_url( $i['logo'] ) . '" alt="' . esc_attr( $i['name'] ) . '">' : ( class_exists( 'SDSC_Brand' ) ? SDSC_Brand::logo( 'wide', 'rc-logo', 46 ) : '<b>' . esc_html( $i['name'] ) . '</b>' );
		$row2 = function ( $k, $v, $cls = '' ) { return '' === trim( (string) $v ) ? '' : '<tr class="' . $cls . '"><th>' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; };
		$h  = '<div class="rc' . ( $d['void'] ? ' is-void' : '' ) . '">';
		if ( $d['void'] ) { $h .= '<div class="rc-void" aria-label="Void">VOID</div>'; }
		$h .= '<header class="rc-h"><div>' . $logo . '</div><div class="rc-school"><b>' . esc_html( $i['name'] ) . '</b><br>' . esc_html( trim( $i['street'] . ', ' . $i['city'], ', ' ) ) . '<br>' . esc_html( implode( ' · ', (array) $i['phones'] ) ) . '<br>' . esc_html( $i['email'] ) . '</div></header>';
		$h .= '<div class="rc-title"><div><span>' . ( $out ? 'Payment voucher' : 'Payment receipt' ) . '</span><small>' . ( $out ? 'Paid by the school' : 'Received by the school' ) . '</small></div><div class="rc-no"><small>No.</small> <b>' . esc_html( $no ) . '</b><br><small>Date</small> ' . esc_html( $date ) . '</div></div>';
		$h .= '<div class="rc-amt"><b>' . esc_html( $amt ) . '</b><span>' . esc_html( self::words( (int) ( $d['cents'] ?? 0 ) ) ) . '</span></div>';
		$h .= '<table class="rc-t">'
			. $row2( $out ? 'Paid to' : 'Received from', esc_html( (string) ( $d['name'] ?? '' ) ) )
			. $row2( 'For', esc_html( (string) ( $d['for'] ?? '' ) ) )
			. $row2( 'Payment method', esc_html( $meth ) )
			. $row2( 'Note', esc_html( (string) ( $d['note'] ?? '' ) ) )
			. $row2( 'Recorded by', esc_html( (string) ( $d['by'] ?? '' ) ) )
			. '</table>';
		$h .= '<div class="rc-sign">'
			. ( $out
				? '<div><i></i>Received by (instructor)</div><div><i></i>Paid by (for ' . esc_html( $i['name'] ) . ')</div>'
				: '<div><i></i>Received by (for ' . esc_html( $i['name'] ) . ')</div>' )
			. '</div>';
		$h .= '<p class="rc-foot">' . ( $d['void'] ? 'This payment was cancelled; this receipt is no longer valid.' : ( $out ? 'Keep this voucher with your records.' : 'Thank you. Please keep this receipt for your records.' ) ) . ' Questions? ' . esc_html( $i['phones'][0] ?? '' ) . ' · ' . esc_html( $i['email'] ) . '</p>';
		$h .= '</div>';
		return $h;
	}

	protected static function css() {
		return '*{box-sizing:border-box}body{margin:0;background:#eef1ee;font:15px/1.5 Arial,Helvetica,sans-serif;color:#1d2327}
.rc-bar{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;padding:16px}
.rc-bar a,.rc-bar button{display:inline-flex;align-items:center;min-height:44px;padding:0 20px;border-radius:999px;border:0;background:#00703c;color:#fff;font:700 15px Arial,sans-serif;text-decoration:none;cursor:pointer}
.rc-bar .ghost{background:#fff;color:#0b5d2e;box-shadow:inset 0 0 0 1.5px #b9dcc8}
.rc{position:relative;max-width:760px;margin:0 auto 32px;background:#fff;border-radius:14px;padding:34px 38px;box-shadow:0 10px 40px -18px rgba(0,0,0,.35);overflow:hidden}
.rc-h{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding-bottom:16px;border-bottom:4px solid #00703c}
.rc-logo{height:46px;width:auto;max-width:260px;object-fit:contain}.rc-school{text-align:right;font-size:12.5px;color:#4b545c;line-height:1.55}.rc-school b{font-size:14px;color:#1d2327}
.rc-title{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin:22px 0 12px}.rc-title span{display:block;font-size:22px;font-weight:800;letter-spacing:.5px;text-transform:uppercase}.rc-title small{color:#646d75;font-size:13px}
.rc-no{text-align:right;font-size:14px}.rc-no small{color:#646d75;text-transform:uppercase;font-size:11px;letter-spacing:1px}.rc-no b{font-size:17px}
.rc-amt{display:flex;flex-direction:column;gap:2px;margin:6px 0 18px;padding:16px 18px;border-radius:12px;background:#eef8f2;border:1px solid #cfe9db}.rc-amt b{font-size:34px;letter-spacing:-.5px;color:#0b5d2e}.rc-amt span{font-size:13px;color:#4b545c}
.rc-t{width:100%;border-collapse:collapse}.rc-t th{width:170px;text-align:left;font-weight:600;color:#646d75;padding:10px 12px 10px 0;border-bottom:1px solid #e7ebe8;vertical-align:top;font-size:14px}.rc-t td{padding:10px 0;border-bottom:1px solid #e7ebe8;font-size:15px}
.rc-sign{display:flex;gap:40px;margin:42px 0 8px}.rc-sign div{flex:1;font-size:12.5px;color:#646d75}.rc-sign i{display:block;height:34px;border-bottom:1.5px solid #1d2327;margin-bottom:6px}
.rc-foot{margin:22px 0 0;font-size:12.5px;color:#646d75;text-align:center}
.rc-void{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font:900 140px/1 Arial,sans-serif;color:rgba(190,30,30,.16);transform:rotate(-24deg);pointer-events:none;letter-spacing:12px}
@media (max-width:600px){.rc{padding:22px 18px;border-radius:0}.rc-h{flex-direction:column}.rc-school{text-align:left}.rc-title{flex-direction:column;align-items:flex-start}.rc-no{text-align:left}.rc-t th{width:120px}.rc-sign{flex-direction:column;gap:18px}}
@media print{@page{size:letter;margin:14mm}body{background:#fff}.rc-bar{display:none}.rc{box-shadow:none;border-radius:0;padding:0;max-width:none}}';
	}

	/** The private link: anyone with it can see and print the receipt (no contact details are on it). */
	public static function public_view() {
		if ( empty( $_GET['sdsc_receipt'] ) ) { return; } // phpcs:ignore
		$tok = (string) wp_unslash( $_GET['sdsc_receipt'] ); // phpcs:ignore
		global $wpdb;
		$row = preg_match( '/^[A-Za-z0-9]{32}$/', $tok ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $tok ), ARRAY_A ) : null; // phpcs:ignore
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( ! $row ) { status_header( 404 ); echo '<!doctype html><meta charset="utf-8"><title>Receipt not found</title><p style="font:16px Arial;padding:40px;text-align:center">This receipt link is not valid. Please call the school.</p>'; exit; }
		$print = ! empty( $_GET['print'] ); // phpcs:ignore
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( self::number( $row ) ) . ' — ' . esc_html( class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::info()['name'] : get_bloginfo( 'name' ) ) . '</title><style>' . self::css() . '</style></head><body>'; // phpcs:ignore
		echo '<div class="rc-bar"><button type="button" onclick="window.print()">🖨 Print</button>' . ( self::can( $row['src'] ) ? '<a class="ghost" href="' . esc_url( self::admin_url( $row['src'], (int) $row['src_id'] ) ) . '">E-mail or text it</a>' : '' ) . '</div>';
		echo self::html( $row ); // phpcs:ignore -- escaped in html()
		if ( $print ) { echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});</script>'; }
		echo '</body></html>';
		exit;
	}

	/* ------------------------------------------------------------ texts */

	public static function text( $row ) {
		$d  = self::data( $row );
		$nm = class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::info()['name'] : get_bloginfo( 'name' );
		$a  = '$' . number_format( ( $d['cents'] ?? 0 ) / 100, 2 );
		$dt = ! empty( $d['date'] ) ? wp_date( 'M j, Y', strtotime( $d['date'] . ' 12:00' ) ) : '';
		return $nm . ' ' . ( 'out' === $row['kind'] ? 'payment voucher' : 'receipt' ) . ' ' . self::number( $row ) . ': ' . $a . ' ' . ( 'out' === $row['kind'] ? 'paid to you' : 'received' ) . ' by ' . strtolower( (string) ( $d['method'] ?? '' ) ) . ( $dt ? ' on ' . $dt : '' ) . '. View or print: ' . self::link( $row );
	}

	/* ------------------------------------------------------------ staff screen */

	public static function menu() {
		add_submenu_page( '', 'Receipt', 'Receipt', 'read', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function page() {
		$src = isset( $_GET['src'] ) ? sanitize_key( $_GET['src'] ) : ''; // phpcs:ignore
		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
		if ( ! self::can( $src ) ) { wp_die( 'Only the administrator and the school manager can open receipts.' ); }
		$row = self::get( $src, $id );
		echo '<div class="wrap sdsc-rcp"><h1>Receipt</h1>';
		if ( ! $row ) { echo '<div class="notice notice-error"><p>No receipt for this payment: it was cancelled, deleted, or paid online by card (those have their own Stripe receipt).</p></div></div>'; return; }
		$d    = self::data( $row );
		$link = self::link( $row );
		$msg  = get_transient( 'sdsc_rcp_msg_' . get_current_user_id() );
		if ( $msg ) { delete_transient( 'sdsc_rcp_msg_' . get_current_user_id() ); echo '<div class="notice notice-' . ( 0 === strpos( $msg, '!' ) ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( ltrim( $msg, '!' ) ) . '</p></div>'; }
		$sent = json_decode( (string) $row['sent'], true );
		$sent = is_array( $sent ) ? $sent : array();
		$sms_ok = class_exists( 'SDSC_SMS' ) && SDSC_SMS::connected();
		$to_e164 = class_exists( 'SDSC_SMS' ) ? SDSC_SMS::e164( (string) ( $d['phone'] ?? '' ) ) : '';
		$txt  = self::text( $row );
		?>
		<style><?php echo self::css(); // phpcs:ignore ?>
		.sdsc-rcp{max-width:1200px}.sdsc-rcp .rcp-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:22px;align-items:start}
		.sdsc-rcp .rc{margin:0;box-shadow:0 1px 3px rgba(0,0,0,.12)}.sdsc-rcp body{background:none}
		.rcp-side{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;position:sticky;top:46px}
		.rcp-side h2{margin:4px 0 10px;font-size:15px}.rcp-side .button{min-height:40px;line-height:38px}.rcp-side form{margin:0 0 16px}.rcp-side input[type=email],.rcp-side input[type=tel],.rcp-side input[type=text]{width:100%;min-height:40px}
		.rcp-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.rcp-log{margin:6px 0 0;padding:0;list-style:none;font-size:12px;color:#646970}.rcp-log li{padding:3px 0;border-top:1px solid #f0f0f1}
		@media (max-width:1000px){.sdsc-rcp .rcp-grid{grid-template-columns:1fr}.rcp-side{position:static}}</style>
		<p><?php echo 'out' === $row['kind'] ? 'Payment to an instructor.' : 'Payment received from a student.'; ?> Receipt <b><?php echo esc_html( self::number( $row ) ); ?></b><?php echo $d['void'] ? ' — <b style="color:#b32d2e">the payment was cancelled, so the receipt shows VOID</b>' : ''; ?>.</p>
		<div class="rcp-grid">
			<div><?php echo self::html( $row ); // phpcs:ignore ?></div>
			<aside class="rcp-side">
				<h2>Print or share</h2>
				<div class="rcp-row">
					<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( self::link( $row, true ) ); ?>">🖨 Print</a>
					<button type="button" class="button" data-copy="<?php echo esc_attr( $link ); ?>">🔗 Copy link</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'sdsc_receipt_send' ); ?>
					<input type="hidden" name="action" value="sdsc_receipt_send"><input type="hidden" name="src" value="<?php echo esc_attr( $src ); ?>"><input type="hidden" name="id" value="<?php echo (int) $id; ?>"><input type="hidden" name="how" value="mail">
					<label><b>✉ E-mail</b><br><input type="email" name="to" required value="<?php echo esc_attr( (string) ( $d['email'] ?? '' ) ); ?>" placeholder="name@example.com"></label>
					<p style="margin:8px 0 0"><button class="button">Send e-mail</button></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rcp-sms">
					<?php wp_nonce_field( 'sdsc_receipt_send' ); ?>
					<input type="hidden" name="action" value="sdsc_receipt_send"><input type="hidden" name="src" value="<?php echo esc_attr( $src ); ?>"><input type="hidden" name="id" value="<?php echo (int) $id; ?>"><input type="hidden" name="how" value="sms">
					<label><b>💬 Text message</b><br><input type="tel" name="to" value="<?php echo esc_attr( (string) ( $d['phone'] ?? '' ) ); ?>" placeholder="(301) 555-0123"></label>
					<p style="margin:8px 0 0;display:flex;gap:8px;flex-wrap:wrap">
						<?php if ( $sms_ok ) : ?><button class="button">Send text</button><?php endif; ?>
						<a class="button<?php echo $sms_ok ? '' : ' button-primary'; ?>" data-smsbody="<?php echo esc_attr( $txt ); ?>" href="<?php echo esc_attr( 'sms:' . $to_e164 . '?&body=' . rawurlencode( $txt ) ); ?>">Open in Messages</a>
						<button type="button" class="button" data-copy="<?php echo esc_attr( $txt ); ?>">Copy text</button>
					</p>
					<?php if ( ! $sms_ok ) : ?><p class="description">“Open in Messages” works on a phone, a tablet or a Mac with Messages. Or copy the text and send it from the school phone.</p><?php endif; ?>
				</form>
				<?php if ( $sent ) : ?><h2>Sent</h2><ul class="rcp-log"><?php foreach ( array_reverse( $sent ) as $s ) : ?><li><?php echo esc_html( $s['t'] . ' — ' . $s['how'] . ' to ' . $s['to'] . ( $s['by'] ? ' by ' . $s['by'] : '' ) . ( empty( $s['ok'] ) ? ' (failed)' : '' ) ); ?></li><?php endforeach; ?></ul><?php endif; ?>
			</aside>
		</div>
		<script>
		document.addEventListener('click',function(e){var b=e.target.closest('[data-copy]');if(!b)return;var t=b.getAttribute('data-copy'),done=function(){var o=b.textContent;b.textContent='Copied ✓';setTimeout(function(){b.textContent=o;},1600);};if(navigator.clipboard){navigator.clipboard.writeText(t).then(done);}else{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();document.execCommand('copy');a.remove();done();}});
		document.querySelectorAll('.rcp-sms input[name=to]').forEach(function(i){i.addEventListener('input',function(){var a=i.form.querySelector('[data-smsbody]'),d=i.value.replace(/\D/g,'');var n=d.length===10?'+1'+d:(d.length===11&&d[0]==='1'?'+'+d:'');a.href='sms:'+n+'?&body='+encodeURIComponent(a.getAttribute('data-smsbody'));});});
		</script>
		</div>
		<?php
	}

	public static function send() {
		check_admin_referer( 'sdsc_receipt_send' );
		$src = sanitize_key( $_POST['src'] ?? '' );
		$id  = absint( $_POST['id'] ?? 0 );
		if ( ! self::can( $src ) ) { wp_die( 'Not allowed.' ); }
		$row = self::get( $src, $id );
		$back = self::admin_url( $src, $id );
		$key  = 'sdsc_rcp_msg_' . get_current_user_id();
		if ( ! $row ) { set_transient( $key, '!This receipt cannot be sent.', 120 ); wp_safe_redirect( $back ); exit; }
		$how = 'sms' === ( $_POST['how'] ?? '' ) ? 'sms' : 'mail';
		$d   = self::data( $row );
		if ( 'mail' === $how ) {
			$to = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
			if ( ! is_email( $to ) ) { set_transient( $key, '!Type a valid e-mail address.', 120 ); wp_safe_redirect( $back ); exit; }
			$no   = self::number( $row );
			$subj = ( 'out' === $row['kind'] ? 'Payment voucher ' : 'Your receipt ' ) . $no . ' — $' . number_format( ( $d['cents'] ?? 0 ) / 100, 2 );
			$f    = 'font-family:Arial,Helvetica,sans-serif;';
			$tr   = function ( $k, $v ) use ( $f ) { return '' === trim( (string) $v ) ? '' : '<tr><td style="' . $f . 'padding:9px 0;border-bottom:1px solid #edf0f2;font-size:14px;color:#5b646d;width:40%">' . esc_html( $k ) . '</td><td align="right" style="' . $f . 'padding:9px 0;border-bottom:1px solid #edf0f2;font-size:14px;color:#15191d">' . esc_html( $v ) . '</td></tr>'; };
			$meth = (string) ( $d['method'] ?? '' ) . ( ! empty( $d['ref'] ) ? ( false !== stripos( (string) $d['method'], 'check' ) ? ' #' : ' · ref ' ) . $d['ref'] : '' );
			$body = '<p>Hi ' . esc_html( strtok( (string) ( $d['name'] ?? '' ), ' ' ) ) . ',</p><p>' . ( 'out' === $row['kind'] ? 'Here is the voucher for the payment the school made to you.' : 'Thank you. Here is the receipt for your payment.' ) . ' Please keep it for your records.</p>'
				. '<div style="' . $f . 'text-align:center;margin:18px 0 8px"><div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#5b646d;font-weight:700">' . ( 'out' === $row['kind'] ? 'Payment voucher' : 'Payment receipt' ) . ' ' . esc_html( $no ) . '</div>'
				. '<div style="font-size:36px;font-weight:800;color:#15191d">$' . esc_html( number_format( ( $d['cents'] ?? 0 ) / 100, 2 ) ) . '</div><div style="font-size:13px;color:#5b646d">' . esc_html( self::words( (int) ( $d['cents'] ?? 0 ) ) ) . '</div></div>'
				. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0">'
				. $tr( 'Date', ! empty( $d['date'] ) ? wp_date( 'F j, Y', strtotime( $d['date'] . ' 12:00' ) ) : '' )
				. $tr( 'out' === $row['kind'] ? 'Paid to' : 'Received from', (string) ( $d['name'] ?? '' ) )
				. $tr( 'For', (string) ( $d['for'] ?? '' ) )
				. $tr( 'Payment method', $meth )
				. '</table>'
				. ( $d['void'] ? '<p style="' . $f . 'color:#b42318;font-weight:700">This payment was cancelled; the receipt is void.</p>' : '' )
				. '<p style="text-align:center;margin:22px 0"><a href="' . esc_url( self::link( $row ) ) . '" style="' . $f . 'display:inline-block;padding:12px 22px;border-radius:999px;background:#00703c;color:#ffffff;font-weight:700;text-decoration:none">View or print the receipt</a></p>';
			$hdr  = array( 'Content-Type: text/html; charset=UTF-8' );
			$ok   = class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::send( $to, $subj, $body, 'info', $hdr ) : wp_mail( $to, $subj, $body, $hdr );
			self::sent( $row, 'E-mail', $to, $ok );
			set_transient( $key, $ok ? 'E-mailed to ' . $to . '.' : '!The e-mail could not be sent. Check Mail settings, or print the receipt.', 120 );
		} else {
			$to = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );
			if ( ! class_exists( 'SDSC_SMS' ) || ! SDSC_SMS::connected() ) { set_transient( $key, '!Texting is not connected. Use “Open in Messages” or “Copy text”.', 120 ); wp_safe_redirect( $back ); exit; }
			list( $ok, $m ) = SDSC_SMS::send( $to, self::text( $row ), 'receipt:' . $row['id'] );
			self::sent( $row, 'Text', $to, $ok );
			set_transient( $key, $ok ? $m : '!' . $m, 120 );
		}
		wp_safe_redirect( $back );
		exit;
	}

	protected static function sent( $row, $how, $to, $ok ) {
		global $wpdb;
		$s   = json_decode( (string) $row['sent'], true );
		$s   = is_array( $s ) ? $s : array();
		$s[] = array( 't' => wp_date( 'M j, Y g:i a' ), 'how' => $how, 'to' => $to, 'ok' => (bool) $ok, 'by' => wp_get_current_user()->display_name );
		$wpdb->update( self::table(), array( 'sent' => wp_json_encode( array_slice( $s, -30 ) ) ), array( 'id' => (int) $row['id'] ) );
	}
}
SDSC_Receipts::init();
