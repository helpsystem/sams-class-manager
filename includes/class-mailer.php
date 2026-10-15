<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * School e-mail: who sends what, through which mailbox, and how it looks.
 *
 *  - Two sending identities on the school's own domain, each through its own authenticated Hostinger mailbox:
 *      info@     classes, registrations, orders, course e-mails (replies come back to the school)
 *      noreply@  passwords, account notices, alerts and staff notifications (Reply-To: info@)
 *    sam@ is the manager's personal mailbox; staff notifications are delivered to it.
 *  - Every e-mail goes out as a branded HTML message with a plain-text copy and the school's signature
 *    (address, phones, office hours, website). Full HTML e-mails from WooCommerce keep their own layout
 *    (styled to match in WooCommerce settings) and get the signature added.
 *  - Sam's School → Email (administrators only): mailbox passwords, domain checks (SPF / DKIM / DMARC),
 *    a test e-mail, a preview and a log of the last 100 messages.
 *  Passwords are stored encrypted with this site's secret keys and never shown again.
 */
class SDSC_Mailer {

	const OPT  = 'sdsc_mail';
	const LOG  = 'sdsc_mail_log';
	const HOST = 'smtp.hostinger.com';
	const PORT = 465;
	/** The school's legal name: sender name, signature and small print. */
	const NAME = 'Sam’s Driving School LLC';

	protected static $next  = null;   // identity forced by the e-mail being built (password reset …)
	protected static $plain = '';     // plain-text copy of the current message
	/** Extra small-print line for the next message (for example an unsubscribe link). Cleared after each send. */
	public static $extra = '';

	public static function domain() {
		return 'samsdrivingschool.org';
	}

	/** id => [address, display name] */
	public static function identities() {
		$o = self::opts();
		return array(
			'info'    => array( $o['info'] ?: 'info@' . self::domain(), self::NAME ),
			'noreply' => array( $o['noreply'] ?: 'noreply@' . self::domain(), self::NAME ),
		);
	}

	public static function opts() {
		$o = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array(
			'on'      => 0,          // send through the mailboxes (only once passwords are saved)
			'info'    => '',
			'noreply' => '',
			'staff'   => 'sam@' . self::domain(),  // where staff notifications go
			'pass'    => array(),    // id => encrypted password
		) );
	}

	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'shape' ), 99 );
		add_action( 'phpmailer_init', array( __CLASS__, 'phpmailer' ), 99 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'log_fail' ) );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'log_ok' ) );
		foreach ( array( 'retrieve_password_notification_email', 'wp_new_user_notification_email', 'wp_new_user_notification_email_admin', 'password_change_email', 'email_change_email', 'new_user_email_content', 'auto_core_update_email', 'recovery_mode_email' ) as $f ) {
			add_filter( $f, array( __CLASS__, 'mark_noreply' ) );
		}
		add_filter( 'retrieve_password_title', array( __CLASS__, 'mark_noreply_s' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 42 );
		add_action( 'admin_post_sdsc_mail_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sdsc_mail_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_post_sdsc_mail_brand', array( __CLASS__, 'brand_woo' ) );
	}

	/* ------------------------------------------------------------ identity */

	public static function mark_noreply( $x ) { self::$next = 'noreply'; return $x; }
	public static function mark_noreply_s( $x ) { self::$next = 'noreply'; return $x; }

	/** Our own code can ask for an identity with the header "X-SDSC-From: noreply". */
	protected static function wanted( $headers ) {
		foreach ( (array) $headers as $h ) {
			if ( is_string( $h ) && preg_match( '/^X-SDSC-From:\s*(info|noreply)/i', $h, $m ) ) { return strtolower( $m[1] ); }
		}
		return null;
	}

	protected static function crypt( $s, $dec = false ) {
		$key = hash( 'sha256', wp_salt( 'auth' ) . 'sdsc-mail', true );
		if ( $dec ) {
			$raw = base64_decode( (string) $s, true );
			if ( ! $raw || strlen( $raw ) < 17 ) { return ''; }
			return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		}
		$iv = random_bytes( 16 );
		return base64_encode( $iv . openssl_encrypt( $s, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv ) );
	}

	protected static function password( $id ) {
		$o = self::opts();
		return empty( $o['pass'][ $id ] ) ? '' : self::crypt( $o['pass'][ $id ], true );
	}

	/* ------------------------------------------------------------ message */

	/** Turn every e-mail into the branded HTML message (unless it is already a complete HTML document). */
	public static function shape( $a ) {
		$headers = is_array( $a['headers'] ) ? $a['headers'] : array_filter( explode( "\n", str_replace( "\r", '', (string) $a['headers'] ) ) );
		$id      = self::wanted( $headers ) ?: self::$next;
		$msg     = (string) $a['message'];
		$is_doc  = (bool) preg_match( '/<html[\s>]/i', $msg );
		$is_html = $is_doc || preg_match( '/<(p|br|table|div|a|strong|h[1-6]|ul)[\s>\/]/i', $msg );
		foreach ( $headers as $h ) {
			if ( is_string( $h ) && preg_match( '#^content-type:\s*text/html#i', $h ) ) { $is_html = true; }
		}

		if ( $is_doc ) {
			// WooCommerce / course e-mails: keep their layout, add the signature once.
			if ( false === strpos( $msg, 'sdsc-sig' ) && ! did_action( 'woocommerce_email_footer' ) ) {
				$msg = preg_replace( '#</body>#i', self::signature_html( true ) . '</body>', $msg, 1 );
			}
			self::$plain = self::to_text( $msg );
		} else {
			$body        = $is_html ? $msg : self::text_to_html( $msg );
			self::$plain = ( $is_html ? self::to_text( $msg ) : $msg ) . "\n\n" . self::signature_text();
			$msg         = self::template( $a['subject'], $body );
		}
		$a['message'] = $msg;

		$headers   = array_values( array_filter( $headers, function ( $h ) { return ! preg_match( '/^(content-type|x-sdsc-from):/i', (string) $h ); } ) );
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		if ( $id ) { $headers[] = 'X-SDSC-From: ' . $id; }
		$a['headers'] = $headers;
		self::$next   = null;
		return $a;
	}

	protected static function to_text( $html ) {
		$t = preg_replace( '#<(style|script|head)[^>]*>.*?</\1>#is', '', $html );
		$t = preg_replace( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $t );
		$t = preg_replace( '#<(br|/p|/div|/h[1-6]|/tr|/li)[^>]*>#i', "\n", $t );
		$t = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( "/[ \t]+/", ' ', $t ) ) );
	}

	protected static function text_to_html( $t ) {
		$t = esc_html( trim( str_replace( "\r", '', $t ) ) );
		$t = preg_replace( '#(https?://[^\s<]+)#', '<a href="$1" style="color:#00703c;font-weight:600;word-break:break-all">$1</a>', $t );
		$p = preg_split( "/\n{2,}/", $t );
		return '<p style="margin:0 0 16px">' . implode( '</p><p style="margin:0 0 16px">', array_map( 'nl2br', $p ) ) . '</p>';
	}

	/* ------------------------------------------------------------ look */

	public static function info() {
		$phone = class_exists( 'SDSC_Chrome' ) ? SDSC_Chrome::PHONE_MAIN : '(301) 726-3030';
		$hours = array();
		if ( class_exists( 'SDSC_Map' ) ) {
			foreach ( SDSC_Map::hours() as $r ) { $hours[] = $r['day'] . ' ' . self::t12( $r['open'] ) . '–' . self::t12( $r['close'] ); }
		}
		$logo = '';
		$cid  = get_theme_mod( 'custom_logo' );
		if ( $cid ) { $logo = wp_get_attachment_image_url( $cid, 'medium' ); }
		return array(
			'name'    => self::NAME,
			'street'  => class_exists( 'SDSC_Map' ) ? SDSC_Map::STREET : '751 Rockville Pike, Unit 9B',
			'city'    => class_exists( 'SDSC_Map' ) ? SDSC_Map::CITY : 'Rockville, MD 20852',
			'phones'  => array_unique( array( $phone, '(202) 600-0889' ) ),
			'email'   => self::identities()['info'][0],
			'web'     => home_url( '/' ),
			'hours'   => $hours,
			'logo'    => $logo,
			'map'     => class_exists( 'SDSC_Map' ) ? SDSC_Map::links()['google'] : '',
		);
	}

	protected static function t12( $t ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $t ) );
		return ( 0 === $h % 12 ? 12 : $h % 12 ) . ( $m ? ':' . sprintf( '%02d', $m ) : '' ) . ( $h < 12 ? 'am' : 'pm' );
	}

	public static function signature_text() {
		$i = self::info();
		$s = "—\n" . $i['name'] . "\n" . $i['street'] . ', ' . $i['city'] . "\n" . implode( ' · ', $i['phones'] ) . "\n" . $i['email'] . ' · ' . preg_replace( '#^https?://|/$#', '', $i['web'] );
		if ( $i['hours'] ) { $s .= "\nOffice hours: " . implode( ', ', $i['hours'] ); }
		return $s;
	}

	public static function signature_html( $standalone = false ) {
		$i   = self::info();
		$tel = function ( $p ) { return 'tel:+1' . preg_replace( '/\D/', '', $p ); };
		$ph  = array();
		foreach ( $i['phones'] as $p ) { $ph[] = '<a href="' . esc_attr( $tel( $p ) ) . '" style="color:#15191d;text-decoration:none;font-weight:600">' . esc_html( $p ) . '</a>'; }
		$h  = '<table role="presentation" class="sdsc-sig" cellpadding="0" cellspacing="0" border="0" width="100%" style="' . ( $standalone ? 'max-width:600px;margin:0 auto 24px;' : '' ) . 'border-top:3px solid #00703c;font-family:Arial,Helvetica,sans-serif">';
		$ico = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 96 ) : '';
		$h  .= '<tr><td style="padding:18px 0 0;vertical-align:top;width:58px">' . ( $ico
			? '<img src="' . esc_url( $ico ) . '" width="44" height="44" alt="" style="display:block;width:44px;height:44px;border-radius:10px;border:0">'
			: '<div style="width:44px;height:44px;border-radius:8px;background:#00703c;color:#ffffff;font:700 15px/44px Arial,sans-serif;text-align:center">SDS</div>' ) . '</td>';
		$h .= '<td style="padding:18px 0 0;vertical-align:top;font-size:13px;line-height:1.6;color:#57616b">';
		$h .= '<div style="font-size:15px;font-weight:700;color:#15191d">' . esc_html( $i['name'] ) . '</div>';
		$h .= '<div>' . ( $i['map'] ? '<a href="' . esc_url( $i['map'] ) . '" style="color:#57616b;text-decoration:none">' : '' ) . esc_html( $i['street'] . ', ' . $i['city'] ) . ( $i['map'] ? '</a>' : '' ) . '</div>';
		$h .= '<div>' . implode( ' &nbsp;·&nbsp; ', $ph ) . '</div>';
		$h .= '<div><a href="mailto:' . esc_attr( $i['email'] ) . '" style="color:#00703c;text-decoration:none">' . esc_html( $i['email'] ) . '</a> &nbsp;·&nbsp; <a href="' . esc_url( $i['web'] ) . '" style="color:#00703c;text-decoration:none">' . esc_html( preg_replace( '#^https?://|/$#', '', $i['web'] ) ) . '</a></div>';
		if ( $i['hours'] ) { $h .= '<div style="color:#8a939b;font-size:12px">Office hours: ' . esc_html( implode( ' · ', $i['hours'] ) ) . '</div>'; }
		$h .= '</td></tr></table>';
		return $h;
	}

	/** The branded layout: green header with the logo, white card, signature, small print. Table-based for every mail app. */
	public static function template( $subject, $body ) {
		$i    = self::info();
		$logo = $i['logo']
			? '<img src="' . esc_url( $i['logo'] ) . '" alt="' . esc_attr( $i['name'] ) . '" width="190" style="display:block;max-width:190px;height:auto;border:0">'
			: '<span style="font:800 22px Arial,sans-serif;color:#ffffff">' . esc_html( $i['name'] ) . '</span>';
		$pre = esc_html( wp_trim_words( self::to_text( $body ), 18, '…' ) );
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f6f4ee;-webkit-text-size-adjust:100%">'
			. '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $pre . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f6f4ee"><tr><td align="center" style="padding:28px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px">'
			. '<tr><td style="background:#ffffff;border-radius:14px 14px 0 0;padding:22px 28px;border-bottom:4px solid #00703c">' . $logo . '</td></tr>'
			. '<tr><td style="height:6px;line-height:6px;font-size:0;background:repeating-linear-gradient(90deg,#ffc20e 0 26px,#2d3238 26px 46px);background-color:#2d3238">&nbsp;</td></tr>'
			. '<tr><td style="background:#ffffff;padding:30px 28px 10px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:#2b3238">' . $body . '</td></tr>'
			. '<tr><td style="background:#ffffff;padding:6px 28px 26px;border-radius:0 0 14px 14px">' . self::signature_html() . '</td></tr>'
			. '<tr><td style="padding:18px 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.6;color:#8a939b;text-align:center">'
			. 'You are receiving this e-mail because of your account or enrollment with ' . esc_html( $i['name'] ) . ', ' . esc_html( $i['city'] ) . '.<br>Questions? Reply to <a href="mailto:' . esc_attr( $i['email'] ) . '" style="color:#8a939b">' . esc_html( $i['email'] ) . '</a> or call ' . esc_html( $i['phones'][0] ) . '.'
			. ( self::$extra ? '<br>' . self::$extra : '' )
			. '</td></tr></table></td></tr></table></body></html>';
	}

	/* ------------------------------------------------------------ sending */

	public static function phpmailer( $pm ) {
		$id = null;
		foreach ( (array) $pm->getCustomHeaders() as $h ) {
			if ( 'x-sdsc-from' === strtolower( $h[0] ) ) { $id = strtolower( trim( $h[1] ) ); }
		}
		$keep = array();
		foreach ( (array) $pm->getCustomHeaders() as $h ) {
			if ( 'x-sdsc-from' !== strtolower( $h[0] ) ) { $keep[] = $h; }
		}
		$pm->clearCustomHeaders();
		foreach ( $keep as $h ) { $pm->addCustomHeader( $h[0], $h[1] ); }
		if ( ! $id ) {
			// Staff-only notifications → noreply; everything a student or customer receives → info.
			$staff = array_map( 'strtolower', array( get_option( 'admin_email' ), self::opts()['staff'] ) );
			$to    = array_map( function ( $r ) { return strtolower( $r[0] ); }, $pm->getToAddresses() );
			$id    = $to && ! array_diff( $to, $staff ) ? 'noreply' : 'info';
		}
		$ids  = self::identities();
		$o    = self::opts();
		$id   = in_array( $id, array( 'info', 'noreply' ), true ) ? $id : 'info';
		$me   = $ids[ $id ];
		// Alias mode: noreply@ can be an alias of the info@ mailbox; it then logs in as info@ but still shows noreply@.
		$auth = self::usable( $id );
		$pass = self::password( $auth );
		if ( self::$plain ) { $pm->AltBody = self::$plain; }
		$pm->CharSet  = 'UTF-8';
		$pm->Hostname = self::domain();   // Message-ID @samsdrivingschool.org instead of the server's name
		$pm->XMailer  = ' ';
		// The school's addresses are used only when the message really goes through that mailbox;
		// otherwise the domain's SPF/DKIM would not match and the address would earn a bad reputation.
		if ( $o['on'] && $pass ) {
			$pm->setFrom( $me[0], $me[1], false );
			$pm->Sender = $ids[ $auth ][0];
			if ( 'noreply' === $id && ! $pm->getReplyToAddresses() ) { $pm->addReplyTo( $ids['info'][0], $ids['info'][1] ); }
			$pm->isSMTP();
			$pm->Host       = self::HOST;
			$pm->Port       = self::PORT;
			$pm->SMTPSecure = 'ssl';
			$pm->SMTPAuth   = true;
			$pm->Username   = $ids[ $auth ][0];
			$pm->Password   = $pass;
			$pm->Timeout    = 20;
		}
		self::$plain = '';
		self::$extra = '';
		$GLOBALS['sdsc_mail_id'] = $id;
	}

	/** One-mailbox mode: if the wanted mailbox has no password yet, send through the one that has. */
	public static function usable( $id ) {
		$id = in_array( $id, array( 'info', 'noreply' ), true ) ? $id : 'info';
		if ( self::password( $id ) ) { return $id; }
		$other = 'info' === $id ? 'noreply' : 'info';
		return self::password( $other ) ? $other : $id;
	}

	protected static function log( $to, $subject, $ok, $err = '' ) {
		$l = get_option( self::LOG, array() );
		array_unshift( $l, array( 't' => time(), 'to' => implode( ', ', (array) $to ), 's' => wp_strip_all_tags( (string) $subject ), 'id' => $GLOBALS['sdsc_mail_id'] ?? '', 'ok' => $ok ? 1 : 0, 'e' => $err ) );
		update_option( self::LOG, array_slice( $l, 0, 100 ), false );
	}

	public static function log_ok( $d ) { self::log( $d['to'] ?? '', $d['subject'] ?? '', true ); }
	public static function log_fail( $e ) {
		$d = is_wp_error( $e ) ? $e->get_error_data() : array();
		self::log( $d['to'] ?? '', $d['subject'] ?? '', false, is_wp_error( $e ) ? $e->get_error_message() : '' );
	}

	/** True once the school mailbox is connected, so e-mail goes out authenticated (not from the bare server). */
	public static function ready() {
		$o = self::opts();
		return (bool) ( $o['on'] && ( self::password( 'info' ) || self::password( 'noreply' ) ) );
	}

	/** Send a school message in the branded layout. $from = info|noreply */
	public static function send( $to, $subject, $body_html, $from = 'noreply', $headers = array() ) {
		$headers[] = 'X-SDSC-From: ' . $from;
		return wp_mail( $to, $subject, $body_html, $headers );
	}

	/**
	 * Report for the school office, in English and Persian (Farsi), with every detail.
	 * $a: subject_en, subject_fa, title_en, title_fa, lead_en, lead_fa,
	 *     rows => array( array( 'en' => label, 'fa' => label, 'v' => plain value, 'ltr' => bool ) ),
	 *     buttons => array( array( 'en' => text, 'fa' => text, 'url' => url ) ), note_en, note_fa, to, from
	 */
	public static function admin_report( $a ) {
		if ( false !== strpos( home_url(), 'testsaman.' ) ) { return false; } // the test site never e-mails the office
		$a = wp_parse_args( $a, array( 'to' => get_option( 'admin_email' ), 'from' => 'noreply', 'subject_en' => '', 'subject_fa' => '', 'title_en' => '', 'title_fa' => '', 'lead_en' => '', 'lead_fa' => '', 'rows' => array(), 'buttons' => array(), 'note_en' => '', 'note_fa' => '' ) );
		$f   = 'font-family:Arial,Helvetica,sans-serif;';
		$ffa = 'font-family:Tahoma,Vazirmatn,Arial,sans-serif;';
		$block = function ( $lang ) use ( $a, $f, $ffa ) {
			$fa   = 'fa' === $lang;
			$font = $fa ? $ffa : $f;
			$h  = '<div dir="' . ( $fa ? 'rtl' : 'ltr' ) . '" lang="' . $lang . '" style="' . $font . 'text-align:' . ( $fa ? 'right' : 'left' ) . ';margin:0 0 8px">';
			$h .= '<div style="' . $font . 'font-size:12px;letter-spacing:' . ( $fa ? '0' : '1.5px' ) . ';text-transform:uppercase;color:#5b646d;font-weight:700;margin:0 0 4px">' . ( $fa ? 'گزارش برای دفتر' : 'Office report' ) . '</div>';
			$h .= '<div style="' . $font . 'font-size:22px;font-weight:800;color:#15191d;margin:0 0 10px">' . esc_html( $a[ 'title_' . $lang ] ) . '</div>';
			if ( $a[ 'lead_' . $lang ] ) { $h .= '<p style="' . $font . 'margin:0 0 14px;font-size:15px;line-height:1.7;color:#15191d">' . esc_html( $a[ 'lead_' . $lang ] ) . '</p>'; }
			if ( $a['rows'] ) {
				$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 14px">';
				foreach ( $a['rows'] as $r ) {
					$v = (string) ( ( $fa && isset( $r['v_fa'] ) ) ? $r['v_fa'] : ( $r['v'] ?? '' ) );
					if ( '' === trim( $v ) ) { $v = '—'; }
					$val = nl2br( esc_html( $v ) );
					if ( $fa && ! empty( $r['ltr'] ) ) { $val = '<span dir="ltr" style="unicode-bidi:embed">' . $val . '</span>'; }
					$h .= '<tr><td style="' . $font . 'padding:7px 0;border-bottom:1px solid #eef1f3;font-size:13px;color:#5b646d;width:38%;vertical-align:top">' . esc_html( $r[ $lang ] ) . '</td><td style="' . $font . 'padding:7px 0;border-bottom:1px solid #eef1f3;font-size:14px;color:#15191d;font-weight:600;vertical-align:top">' . $val . '</td></tr>';
				}
				$h .= '</table>';
			}
			if ( $a[ 'note_' . $lang ] ) { $h .= '<p style="' . $font . 'margin:0 0 14px;padding:10px 12px;border-radius:8px;background:#fff8e1;font-size:14px;line-height:1.7;color:#5b4a00">' . esc_html( $a[ 'note_' . $lang ] ) . '</p>'; }
			foreach ( $a['buttons'] as $b ) {
				$h .= '<a href="' . esc_url( $b['url'] ) . '" style="' . $font . 'display:inline-block;margin:0 0 8px ' . ( $fa ? '0' : '0' ) . ';' . ( $fa ? 'margin-left:8px;' : 'margin-right:8px;' ) . 'padding:10px 18px;border-radius:999px;background:#00703c;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none">' . esc_html( $b[ $lang ] ) . '</a>';
			}
			return $h . '</div>';
		};
		$body = $block( 'en' ) . '<hr style="border:0;border-top:2px dashed #dfe5e1;margin:22px 0">' . $block( 'fa' );
		$subj = trim( $a['subject_en'] . ( $a['subject_fa'] ? ' | ' . $a['subject_fa'] : '' ) );
		return self::send( $a['to'], $subj, $body, $a['from'], array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/* ------------------------------------------------------------ checks */

	public static function dns() {
		$d   = self::domain();
		$txt = function ( $host ) {
			$r = @dns_get_record( $host, DNS_TXT ); // phpcs:ignore
			return is_array( $r ) ? array_map( function ( $x ) { return $x['txt'] ?? implode( '', (array) ( $x['entries'] ?? array() ) ); }, $r ) : array();
		};
		$spf = '';
		foreach ( $txt( $d ) as $t ) { if ( 0 === stripos( $t, 'v=spf1' ) ) { $spf = $t; } }
		$dmarc = implode( ' ', $txt( '_dmarc.' . $d ) );
		$dkim  = array();
		foreach ( array( 'hostingermail-a', 'hostingermail-b', 'hostingermail-c', 'hostingermail1', 'default' ) as $sel ) {
			$c = @dns_get_record( $sel . '._domainkey.' . $d, DNS_CNAME | DNS_TXT ); // phpcs:ignore
			if ( $c ) { $dkim[] = $sel; }
		}
		return array(
			'spf'   => array( $spf && false !== strpos( $spf, 'hostinger' ), $spf ?: 'missing' ),
			'dkim'  => array( (bool) $dkim, $dkim ? 'found: ' . implode( ', ', $dkim ) : 'not found — turn on DKIM in hPanel → Emails' ),
			'dmarc' => array( (bool) preg_match( '/p=(quarantine|reject)/i', $dmarc ), $dmarc ?: 'missing' ),
		);
	}

	/* ------------------------------------------------------------ admin */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Email', 'Email', 'manage_options', 'sdsc-email', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		$o   = self::opts();
		$ids = self::identities();
		$dns = self::dns();
		$log = get_option( self::LOG, array() );
		$woo = get_option( 'woocommerce_email_from_address' );
		?>
		<div class="wrap sdsc-mail">
			<h1>Email</h1>
			<p class="sdsc-lead">How the school&rsquo;s e-mails are sent and how they look. Class and order e-mails come from <b><?php echo esc_html( $ids['info'][0] ); ?></b>; passwords, notices and staff alerts from <b><?php echo esc_html( $ids['noreply'][0] ); ?></b> (replies go to info@).</p>
			<?php if ( isset( $_GET['msg'] ) ) : // phpcs:ignore ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>

			<h2>Delivery checklist</h2>
			<table class="widefat striped sdsc-mail-checks" id="sdsc-mail-checks"><tbody>
				<?php
				$rows = array(
					array( 'Authenticated sending (SMTP)', $o['on'] && ( self::password( 'info' ) || self::password( 'noreply' ) ), $o['on'] ? ( self::password( 'info' ) && self::password( 'noreply' ) ? 'On' : 'On — noreply@ is sent as an alias of info@' ) : 'Off — add a mailbox password below and switch it on' ),
					array( 'SPF', $dns['spf'][0], $dns['spf'][1] ),
					array( 'DKIM', $dns['dkim'][0], $dns['dkim'][1] ),
					array( 'DMARC policy', $dns['dmarc'][0], $dns['dmarc'][1] . ( $dns['dmarc'][0] ? '' : ' — move to p=quarantine once SPF and DKIM pass for two weeks' ) ),
					array( 'WooCommerce sender', $woo && false !== stripos( $woo, '@' . self::domain() ), $woo ?: 'not set' ),
				);
				foreach ( $rows as $r ) :
					?>
					<tr><td style="width:34px"><?php echo $r[1] ? '<span class="sdsc-ok">&#10003;</span>' : '<span class="sdsc-bad">!</span>'; ?></td><th style="width:230px"><?php echo esc_html( $r[0] ); ?></th><td><code><?php echo esc_html( $r[2] ); ?></code></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<?php if ( ! $woo || false === stripos( $woo, '@' . self::domain() ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px"><input type="hidden" name="action" value="sdsc_mail_brand"><?php wp_nonce_field( 'sdsc_mail' ); ?><?php submit_button( 'Use info@ and school colours for WooCommerce e-mails', 'secondary', 'submit', false ); ?></form>
			<?php endif; ?>

			<h2>Mailboxes</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="sdsc_mail_save"><?php wp_nonce_field( 'sdsc_mail' ); ?>
				<table class="form-table" role="presentation" id="sdsc-mail-boxes">
					<?php foreach ( array( 'info' => 'Classes & orders', 'noreply' => 'Notices & passwords' ) as $k => $label ) : ?>
						<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td>
							<input type="email" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $ids[ $k ][0] ); ?>" class="regular-text">
							<input type="password" name="pass_<?php echo esc_attr( $k ); ?>" value="" class="regular-text" placeholder="<?php echo self::password( $k ) ? 'Saved — type only to change' : ( 'noreply' === $k ? 'Leave empty if it is an alias of info@' : 'Mailbox password' ); ?>" autocomplete="new-password">
							<?php if ( 'noreply' === $k ) : ?><p class="description">If noreply@ is an alias of the info@ mailbox, leave this empty — it is sent with the info@ login.</p><?php endif; ?>
						</td></tr>
					<?php endforeach; ?>
					<tr><th scope="row">Staff notifications to</th><td><input type="email" name="staff" value="<?php echo esc_attr( $o['staff'] ); ?>" class="regular-text"><p class="description">The manager&rsquo;s mailbox.</p></td></tr>
					<tr><th scope="row">Send through the mailboxes</th><td><label><input type="checkbox" name="on" value="1"<?php checked( $o['on'] ); ?>> On</label><p class="description">Server: <?php echo esc_html( self::HOST . ':' . self::PORT ); ?> (SSL). Passwords are stored encrypted and never shown again.</p></td></tr>
				</table>
				<?php submit_button( 'Save mailboxes' ); ?>
			</form>

			<h2>Send a test</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdsc-mail-test">
				<input type="hidden" name="action" value="sdsc_mail_test"><?php wp_nonce_field( 'sdsc_mail' ); ?>
				<input type="email" name="to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" required>
				<select name="from"><option value="info">from info@</option><option value="noreply">from noreply@</option></select>
				<?php submit_button( 'Send test e-mail', 'secondary', 'submit', false ); ?>
				<p class="description">Tip: also send one to <a href="https://www.mail-tester.com" target="_blank" rel="noopener">mail-tester.com</a> for a full spam score.</p>
			</form>

			<h2>Preview</h2>
			<iframe class="sdsc-mail-prev" title="E-mail preview" srcdoc="<?php echo esc_attr( self::template( 'Your road test is confirmed', '<h2 style="margin:0 0 12px;font-size:22px;color:#15191d">Your road test is confirmed</h2><p style="margin:0 0 16px">Hi Alex,</p><p style="margin:0 0 16px">This is how every e-mail from the school will look. Your message goes here, and the signature below is added automatically.</p><p style="margin:0 0 22px"><a href="#" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#00703c;color:#ffffff;font-weight:700;text-decoration:none">View details</a></p>' ) ); ?>"></iframe>

			<h2>Recent e-mails</h2>
			<table class="widefat striped"><thead><tr><th>When</th><th>To</th><th>Subject</th><th>From</th><th>Result</th></tr></thead><tbody>
				<?php if ( ! $log ) : ?><tr><td colspan="5">Nothing sent yet.</td></tr><?php endif; ?>
				<?php foreach ( array_slice( $log, 0, 30 ) as $r ) : ?>
					<tr><td><?php echo esc_html( wp_date( 'M j, g:i a', $r['t'] ) ); ?></td><td><?php echo esc_html( $r['to'] ); ?></td><td><?php echo esc_html( $r['s'] ); ?></td><td><?php echo esc_html( $r['id'] ); ?></td><td><?php echo $r['ok'] ? '<span class="sdsc-ok">Sent</span>' : '<span class="sdsc-bad">Failed</span> ' . esc_html( $r['e'] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_mail' );
		$o = self::opts();
		foreach ( array( 'info', 'noreply' ) as $k ) {
			$e = sanitize_email( wp_unslash( $_POST[ $k ] ?? '' ) );
			$o[ $k ] = $e && preg_match( '/@' . preg_quote( self::domain(), '/' ) . '$/i', $e ) ? $e : '';
			$p = isset( $_POST[ 'pass_' . $k ] ) ? (string) wp_unslash( $_POST[ 'pass_' . $k ] ) : ''; // phpcs:ignore -- password, not sanitised
			if ( '' !== $p ) { $o['pass'][ $k ] = self::crypt( $p ); }
		}
		$s = sanitize_email( wp_unslash( $_POST['staff'] ?? '' ) );
		if ( $s ) { $o['staff'] = $s; }
		$o['on'] = empty( $_POST['on'] ) ? 0 : 1;
		update_option( self::OPT, $o, false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-email&msg=' . rawurlencode( 'Mailboxes saved.' ) ) );
		exit;
	}

	public static function test() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_mail' );
		$to   = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
		$from = 'info' === ( $_POST['from'] ?? '' ) ? 'info' : 'noreply';
		$ok   = $to && self::send( $to, 'Test e-mail from Sam’s Driving School', '<h2 style="margin:0 0 12px;font-size:22px;color:#15191d">It works</h2><p style="margin:0 0 16px">This test was sent from <b>' . esc_html( self::identities()[ $from ][0] ) . '</b> on ' . esc_html( wp_date( 'F j, Y g:i a' ) ) . '.</p><p style="margin:0">If it landed in Spam or Promotions, mark it “Not spam” and check the delivery checklist on the Email screen.</p>', $from );
		$last = get_option( self::LOG, array() )[0] ?? array();
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-email&msg=' . rawurlencode( $ok ? 'Test sent to ' . $to . '.' : 'Sending failed: ' . ( $last['e'] ?? 'unknown error' ) ) ) );
		exit;
	}

	/** WooCommerce e-mails: school sender and colours. */
	public static function brand_woo() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_mail' );
		$i = self::info();
		$wid = self::password( 'info' ) || ! self::password( 'noreply' ) ? 'info' : 'noreply';
		update_option( 'woocommerce_email_from_address', self::identities()[ $wid ][0] );
		update_option( 'woocommerce_email_from_name', self::NAME );
		update_option( 'woocommerce_email_base_color', '#00703c' );
		update_option( 'woocommerce_email_background_color', '#f6f4ee' );
		update_option( 'woocommerce_email_body_background_color', '#ffffff' );
		update_option( 'woocommerce_email_text_color', '#2b3238' );
		if ( $i['logo'] ) { update_option( 'woocommerce_email_header_image', $i['logo'] ); }
		update_option( 'woocommerce_email_footer_text', $i['name'] . ' · ' . $i['street'] . ', ' . $i['city'] . ' · ' . implode( ' · ', $i['phones'] ) . ' · ' . $i['email'] );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-email&msg=' . rawurlencode( 'WooCommerce e-mails now come from ' . self::identities()[ $wid ][0] . ' in school colours.' ) ) );
		exit;
	}
}
