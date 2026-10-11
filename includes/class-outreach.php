<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Celebrations & campaigns.
 *
 *  - Today: students whose birthday or driver's-license anniversary is today (and the next 7 days).
 *    Birthday / anniversary e-mails go out automatically every morning once the school mailbox is connected.
 *    Each card has a "Text" button that opens the phone's Messages app with the number and the
 *    ready-made message filled in — the staff member only presses Send.
 *  - Students: language (English / Spanish), license date, e-mail / text opt-outs, and a link the student
 *    can use to tell us the day they got their license.
 *  - Campaigns: one message to many students — e-mails are queued and sent a few at a time,
 *    texts are a tap-through list ("Text next").
 *  - Messages: the English and Spanish wording of every automatic message, editable, with a preview.
 *
 * Student details come from the registration table (SDSC_Registration). This module keeps only its own
 * preferences per student in {prefix}sdsc_outreach. Every marketing e-mail carries an unsubscribe link and
 * the List-Unsubscribe headers that Gmail and Yahoo require from bulk senders.
 */
class SDSC_Outreach {
	const DBV  = '2';
	const DBO  = 'sdsc_outreach_db';
	const TPL  = 'sdsc_outreach_tpl';
	const SET  = 'sdsc_outreach_set';
	const CAMP = 'sdsc_campaigns';
	const HOOK = 'sdsc_outreach_tick';
	const PAGE = 'sdsc-outreach';

	protected static $contacts = null;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sdsc_outreach';
	}

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 21 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_menu', array( __CLASS__, 'badge' ), 1001 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'student', 'tpl', 'set', 'camp_save', 'camp_state', 'test', 'send_one' ) as $a ) {
			add_action( 'admin_post_sdsc_or_' . $a, array( __CLASS__, 'h_' . $a ) );
		}
		add_action( 'wp_ajax_sdsc_or_mark', array( __CLASS__, 'ajax_mark' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'template_redirect', array( __CLASS__, 'front' ), 0 );
		add_action( 'sdsc_registered', array( __CLASS__, 'on_register' ), 10, 3 );
	}

	/** New registration: remember if the student agreed to greetings and offers. */
	public static function on_register( $reg_id, $d, $offers ) {
		if ( ! $offers || empty( $d['email'] ) ) { return; }
		self::save_pref( self::key( $d['email'], $d['first_name'] ), array( 'mkt' => 1, 'mkt_at' => current_time( 'mysql' ), 'opt_email' => 0, 'opt_sms' => 0 ) );
	}

	/* ================================================================ data */

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			ckey char(32) NOT NULL,
			lang char(2) NOT NULL DEFAULT 'en',
			license_date date DEFAULT NULL,
			license_src varchar(10) NOT NULL DEFAULT '',
			opt_email tinyint(1) NOT NULL DEFAULT 0,
			opt_sms tinyint(1) NOT NULL DEFAULT 0,
			mkt tinyint(1) NOT NULL DEFAULT 0,
			mkt_at datetime DEFAULT NULL,
			sent text NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (ckey)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	public static function key( $email, $first ) {
		return md5( strtolower( trim( (string) $email ) ) . '|' . strtolower( trim( (string) $first ) ) );
	}

	/** One entry per student (same e-mail + first name = same student; the newest registration wins). */
	public static function contacts() {
		if ( null !== self::$contacts ) { return self::$contacts; }
		global $wpdb;
		self::$contacts = array();
		if ( ! class_exists( 'SDSC_Registration' ) ) { return self::$contacts; }
		$t     = SDSC_Registration::table();
		$rows  = $wpdb->get_results( "SELECT id, created_at, class_id, first_name, last_name, dob, email, phone, parent_name, parent_phone FROM $t ORDER BY id DESC", ARRAY_A ); // phpcs:ignore
		$prefs = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::table(), ARRAY_A ) as $p ) { $prefs[ $p['ckey'] ] = $p; } // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$em = strtolower( trim( $r['email'] ) );
			if ( ! is_email( $em ) ) { continue; }
			$k = self::key( $em, $r['first_name'] );
			if ( isset( self::$contacts[ $k ] ) ) { self::$contacts[ $k ]['n']++; continue; }
			$p    = $prefs[ $k ] ?? array();
			$sent = json_decode( (string) ( $p['sent'] ?? '' ), true );
			self::$contacts[ $k ] = array(
				'k'      => $k,
				'id'     => (int) $r['id'],
				'first'  => self::nice( $r['first_name'] ),
				'last'   => self::nice( $r['last_name'] ),
				'dob'    => ( $r['dob'] && '0000-00-00' !== $r['dob'] ) ? $r['dob'] : '',
				'email'  => $em,
				'phone'  => $r['phone'],
				'pname'  => self::nice( $r['parent_name'] ),
				'pphone' => $r['parent_phone'],
				'since'  => $r['created_at'],
				'class'  => (int) $r['class_id'],
				'n'      => 1,
				'lang'   => in_array( $p['lang'] ?? '', array( 'en', 'es' ), true ) ? $p['lang'] : 'en',
				'lic'    => ( ! empty( $p['license_date'] ) && '0000-00-00' !== $p['license_date'] ) ? $p['license_date'] : '',
				'licsrc' => $p['license_src'] ?? '',
				'oe'     => (int) ( $p['opt_email'] ?? 0 ),
				'os'     => (int) ( $p['opt_sms'] ?? 0 ),
				'mkt'    => (int) ( $p['mkt'] ?? 0 ),
				'sent'   => is_array( $sent ) ? $sent : array(),
			);
		}
		return self::$contacts;
	}

	protected static function nice( $s ) {
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		if ( '' === $s ) { return ''; }
		// "JOHN" or "john" → "John"; leave names that already mix cases alone (McDonald, DeShawn).
		return ( $s === strtoupper( $s ) || $s === strtolower( $s ) ) ? mb_convert_case( strtolower( $s ), MB_CASE_TITLE, 'UTF-8' ) : $s;
	}

	public static function contact( $k ) {
		return self::contacts()[ $k ] ?? null;
	}

	protected static function save_pref( $k, $data ) {
		global $wpdb;
		$t = self::table();
		$data['updated_at'] = current_time( 'mysql' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT ckey FROM $t WHERE ckey = %s", $k ) ) ) { // phpcs:ignore
			$wpdb->update( $t, $data, array( 'ckey' => $k ) );
		} else {
			$wpdb->insert( $t, array_merge( array( 'ckey' => $k ), $data ) );
		}
		self::$contacts = null;
	}

	protected static function mark_sent( $k, $flag ) {
		$c = self::contact( $k );
		if ( ! $c ) { return; }
		$s          = $c['sent'];
		$s[ $flag ] = time();
		self::save_pref( $k, array( 'sent' => wp_json_encode( $s ) ) );
	}

	public static function age( $dob, $on = null ) {
		if ( ! $dob ) { return null; }
		$on = $on ?: current_time( 'Y-m-d' );
		$a  = (int) substr( $on, 0, 4 ) - (int) substr( $dob, 0, 4 );
		if ( substr( $on, 5 ) < substr( $dob, 5 ) ) { $a--; }
		return $a;
	}

	/** Month-day of a date for a given year (Feb 29 is celebrated on Feb 28 in other years). */
	protected static function md( $date, $year ) {
		$md = substr( $date, 5, 5 );
		if ( '02-29' === $md && ! checkdate( 2, 29, (int) $year ) ) { $md = '02-28'; }
		return $md;
	}

	/** Birthdays (b) and license anniversaries (a) on a day. */
	public static function on_day( $day ) {
		$y   = substr( $day, 0, 4 );
		$md  = substr( $day, 5, 5 );
		$out = array( 'b' => array(), 'a' => array() );
		foreach ( self::contacts() as $c ) {
			if ( $c['dob'] && self::md( $c['dob'], $y ) === $md && (int) substr( $c['dob'], 0, 4 ) < (int) $y ) {
				$out['b'][] = $c;
			}
			if ( $c['lic'] && self::md( $c['lic'], $y ) === $md && (int) substr( $c['lic'], 0, 4 ) < (int) $y ) {
				$out['a'][] = $c;
			}
		}
		return $out;
	}

	public static function phone_e164( $p ) {
		$d = preg_replace( '/\D/', '', (string) $p );
		if ( 10 === strlen( $d ) ) { return '+1' . $d; }
		if ( 11 === strlen( $d ) && '1' === $d[0] ) { return '+' . $d; }
		return strlen( $d ) > 10 ? '+' . $d : '';
	}

	public static function phone_nice( $p ) {
		$d = preg_replace( '/\D/', '', (string) $p );
		if ( 11 === strlen( $d ) && '1' === $d[0] ) { $d = substr( $d, 1 ); }
		return 10 === strlen( $d ) ? '(' . substr( $d, 0, 3 ) . ') ' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 ) : $p;
	}

	/* ================================================================ settings & wording */

	public static function settings() {
		$s = get_option( self::SET, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array(
			'auto'  => 1,   // automatic birthday / anniversary e-mails
			'hour'  => 9,   // not before this hour (school time)
			'batch' => 20,  // campaign e-mails per 5 minutes
		) );
	}

	public static function defaults() {
		return array(
			'birthday' => array(
				'en' => array(
					'subject' => 'Happy birthday, {first_name}! 🎂',
					'heading' => 'Happy birthday, {first_name}!',
					'body'    => "Everyone at Sam’s Driving School is wishing you a wonderful birthday and a great year ahead — on the road and everywhere else.\n\nThank you for learning with us. Enjoy your day, and keep driving safely!",
					'sms'     => "Happy birthday, {first_name}! 🎉 Everyone at Sam's Driving School wishes you a wonderful day and a great year ahead. Drive safe! – Sam's Driving School",
				),
				'es' => array(
					'subject' => '¡Feliz cumpleaños, {first_name}! 🎂',
					'heading' => '¡Feliz cumpleaños, {first_name}!',
					'body'    => "Todo el equipo de Sam’s Driving School le desea un feliz cumpleaños y un gran año por delante, en el camino y en todo lo demás.\n\nGracias por aprender con nosotros. ¡Disfrute su día y siga manejando con cuidado!",
					'sms'     => "¡Feliz cumpleaños, {first_name}! 🎉 Todo el equipo de Sam's Driving School le desea un día maravilloso y un gran año. ¡Maneje con cuidado! – Sam's Driving School",
				),
			),
			'anniversary' => array(
				'en' => array(
					'subject' => 'Happy license anniversary, {first_name}! 🚗',
					'heading' => '{years} on the road!',
					'body'    => "Today marks {years} since you earned your driver’s license — congratulations, {first_name}! We are proud to have been part of your journey.\n\nKnow someone getting ready for a permit, a driving course or the road test? We would love to help them too. Just reply to this e-mail or call us at {phone}.",
					'sms'     => "Happy license anniversary, {first_name}! 🚗 {years} on the road — congratulations from Sam's Driving School. Know someone who needs lessons? We'd love to help: {phone}",
				),
				'es' => array(
					'subject' => '¡Feliz aniversario de su licencia, {first_name}! 🚗',
					'heading' => '¡{years} al volante!',
					'body'    => "Hoy se cumple {years} desde que obtuvo su licencia de conducir. ¡Felicidades, {first_name}! Estamos orgullosos de haber sido parte de su camino.\n\n¿Conoce a alguien que se prepara para el permiso, un curso de manejo o el examen de manejo? Con gusto le ayudamos también. Responda a este correo o llámenos al {phone}.",
					'sms'     => "¡Feliz aniversario de su licencia, {first_name}! 🚗 {years} al volante. Felicidades de parte de Sam's Driving School. ¿Alguien necesita clases? Llámenos: {phone}",
				),
			),
			'license_ask' => array(
				'en' => array(
					'subject' => 'Did you get your driver’s license, {first_name}?',
					'heading' => 'Tell us your good news!',
					'body'    => "Hi {first_name},\n\nIf you have passed your road test, congratulations! Let us know the date you got your license so we can celebrate it with you every year. It takes ten seconds:",
					'sms'     => "Hi {first_name}, it's Sam's Driving School! Did you get your driver's license? Tell us the date so we can celebrate with you: {link}",
					'btn'     => 'Tell us the date',
				),
				'es' => array(
					'subject' => '¿Ya obtuvo su licencia de conducir, {first_name}?',
					'heading' => '¡Cuéntenos la buena noticia!',
					'body'    => "Hola {first_name}:\n\nSi ya aprobó su examen de manejo, ¡felicidades! Díganos la fecha en que obtuvo su licencia para celebrarla con usted cada año. Solo toma diez segundos:",
					'sms'     => "Hola {first_name}, le saluda Sam's Driving School. ¿Ya obtuvo su licencia de conducir? Díganos la fecha para celebrar con usted: {link}",
					'btn'     => 'Indicar la fecha',
				),
			),
		);
	}

	public static function templates() {
		$saved = get_option( self::TPL, array() );
		$d     = self::defaults();
		foreach ( $d as $kind => $langs ) {
			foreach ( $langs as $l => $f ) {
				foreach ( $f as $field => $v ) {
					if ( isset( $saved[ $kind ][ $l ][ $field ] ) && '' !== trim( $saved[ $kind ][ $l ][ $field ] ) ) {
						$d[ $kind ][ $l ][ $field ] = $saved[ $kind ][ $l ][ $field ];
					}
				}
			}
		}
		return $d;
	}

	/** Campaign starting points. */
	public static function presets() {
		$web = home_url( '/' );
		return array(
			'classes' => array(
				'label' => 'New class dates',
				'url'   => $web,
				'en'    => array(
					'subject' => 'New class dates are open at Sam’s Driving School',
					'heading' => 'New classes are open',
					'body'    => "Hi {first_name},\n\nWe have just opened new dates: [write the course and the dates here].\n\nSeats are limited, so if you — or a friend or family member — are ready to start, now is a great time to register. Questions? Reply to this e-mail or call us at {phone}.",
					'btn'     => 'See classes & register',
					'sms'     => "Hi {first_name}, new class dates are open at Sam's Driving School! [course & dates]. Register: {link} Questions? {phone}",
				),
				'es' => array(
					'subject' => 'Nuevas fechas de clases en Sam’s Driving School',
					'heading' => 'Nuevas clases disponibles',
					'body'    => "Hola {first_name}:\n\nAcabamos de abrir nuevas fechas: [escriba aquí el curso y las fechas].\n\nLos cupos son limitados. Si usted, un amigo o un familiar está listo para empezar, este es un buen momento para inscribirse. ¿Preguntas? Responda a este correo o llámenos al {phone}.",
					'btn'     => 'Ver clases e inscribirse',
					'sms'     => "Hola {first_name}, ¡hay nuevas fechas de clases en Sam's Driving School! [curso y fechas]. Inscríbase: {link} ¿Preguntas? {phone}",
				),
			),
			'offer' => array(
				'label' => 'New discount',
				'url'   => $web,
				'en'    => array(
					'subject' => 'A special offer for Sam’s Driving School students',
					'heading' => 'A special offer for you',
					'body'    => "Hi {first_name},\n\nAs one of our students, you get early access to a new offer: [describe the discount exactly as it appears at checkout, and when it ends].\n\nThe discount is applied automatically at checkout on our website.",
					'btn'     => 'See the offer',
					'sms'     => "Hi {first_name}, a special offer from Sam's Driving School: [offer & end date]. Details: {link}",
				),
				'es' => array(
					'subject' => 'Una oferta especial para estudiantes de Sam’s Driving School',
					'heading' => 'Una oferta especial para usted',
					'body'    => "Hola {first_name}:\n\nComo estudiante nuestro, tiene acceso anticipado a una nueva oferta: [describa el descuento tal como aparece al pagar y cuándo termina].\n\nEl descuento se aplica automáticamente al pagar en nuestro sitio web.",
					'btn'     => 'Ver la oferta',
					'sms'     => "Hola {first_name}, una oferta especial de Sam's Driving School: [oferta y fecha final]. Detalles: {link}",
				),
			),
			'news' => array(
				'label' => 'Announcement',
				'url'   => '',
				'en'    => array( 'subject' => '', 'heading' => '', 'body' => "Hi {first_name},\n\n", 'btn' => '', 'sms' => "Hi {first_name}, " ),
				'es'    => array( 'subject' => '', 'heading' => '', 'body' => "Hola {first_name}:\n\n", 'btn' => '', 'sms' => "Hola {first_name}, " ),
			),
		);
	}

	public static function years( $n, $lang ) {
		if ( 'es' === $lang ) { return 1 === $n ? '1 año' : $n . ' años'; }
		return 1 === $n ? '1 year' : $n . ' years';
	}

	public static function fill( $text, $c, $lang, $vars = array() ) {
		$i   = SDSC_Mailer::info();
		$map = array_merge( array(
			'{first_name}' => $c['first'] ?? '',
			'{last_name}'  => $c['last'] ?? '',
			'{school}'     => 'Sam’s Driving School',
			'{phone}'      => $i['phones'][0],
			'{website}'    => preg_replace( '#^https?://|/$#', '', $i['web'] ),
			'{link}'       => '',
			'{years}'      => '',
		), $vars );
		$out = strtr( (string) $text, $map );
		return trim( preg_replace( '/[ \t]+([.,!?:])/u', '$1', preg_replace( '/[ \t]{2,}/', ' ', $out ) ) );
	}

	/** Has the text still got a "[write … here]" line the staff forgot to replace? */
	public static function unfinished( $text ) {
		return (bool) preg_match( '/\[[^\]\n]{3,}\]/u', (string) $text );
	}

	/** The e-mail body (inside the school layout). */
	public static function email_body( $heading, $body, $btn = '', $url = '', $icon = '' ) {
		$h = '';
		if ( $icon || $heading ) {
			$h .= '<div style="text-align:center;margin:4px 0 22px">';
			if ( $icon ) { $h .= '<div style="font-size:46px;line-height:1.1">' . $icon . '</div>'; }
			if ( $heading ) { $h .= '<h1 style="margin:10px 0 0;font:800 26px/1.25 Arial,Helvetica,sans-serif;color:#00703c;letter-spacing:-.3px">' . esc_html( $heading ) . '</h1>'; }
			$h .= '<div style="width:64px;height:4px;margin:14px auto 0;border-radius:4px;background:#ffc20e;font-size:0;line-height:0">&nbsp;</div></div>';
		}
		foreach ( preg_split( "/\n{2,}/", trim( (string) $body ) ) as $p ) {
			if ( '' === trim( $p ) ) { continue; }
			$h .= '<p style="margin:0 0 16px">' . nl2br( esc_html( trim( $p ) ) ) . '</p>';
		}
		if ( $btn && $url ) {
			$h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:24px auto 10px"><tr><td style="border-radius:999px;background:#00703c">'
				. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 30px;font:700 15px Arial,Helvetica,sans-serif;color:#ffffff;text-decoration:none;border-radius:999px">' . esc_html( $btn ) . ' &rarr;</a></td></tr></table>';
		}
		return $h;
	}

	/** Built message for one student: [subject, html, sms]. $kind = birthday|anniversary|license_ask */
	public static function build( $kind, $c, $lang ) {
		$t    = self::templates()[ $kind ][ $lang ];
		$vars = array();
		if ( 'anniversary' === $kind && $c['lic'] ) {
			$vars['{years}'] = self::years( (int) current_time( 'Y' ) - (int) substr( $c['lic'], 0, 4 ), $lang );
		}
		$url = '';
		if ( 'license_ask' === $kind ) {
			$url            = self::url( 'lic', $c['k'] ) . ( 'es' === $lang ? '&l=es' : '' );
			$vars['{link}'] = $url;
		}
		$icon = array( 'birthday' => '🎂', 'anniversary' => '🚗', 'license_ask' => '🎉' )[ $kind ];
		return array(
			self::fill( $t['subject'], $c, $lang, $vars ),
			self::email_body( self::fill( $t['heading'], $c, $lang, $vars ), self::fill( $t['body'], $c, $lang, $vars ), $t['btn'] ?? '', $url, $icon ),
			self::fill( $t['sms'], $c, $lang, $vars ),
		);
	}

	/* ================================================================ links for students */

	public static function token( $k, $what ) {
		return $k . '.' . substr( hash_hmac( 'sha256', $k . '|' . $what, wp_salt( 'nonce' ) ), 0, 20 );
	}

	public static function check( $tok, $what ) {
		if ( ! preg_match( '/^([a-f0-9]{32})\.([a-f0-9]{20})$/', (string) $tok, $m ) ) { return false; }
		return hash_equals( substr( hash_hmac( 'sha256', $m[1] . '|' . $what, wp_salt( 'nonce' ) ), 0, 20 ), $m[2] ) ? $m[1] : false;
	}

	public static function url( $what, $k ) {
		return add_query_arg( 'sdsc_' . $what, self::token( $k, $what ), home_url( '/' ) );
	}

	/* ================================================================ sending */

	/** Send one e-mail to a student with the unsubscribe link and headers. */
	public static function mail_to( $c, $subject, $html, $lang ) {
		$u = self::url( 'unsub', $c['k'] );
		SDSC_Mailer::$extra = ( 'es' === $lang ? '¿No desea recibir estos correos? ' : 'Don’t want these e-mails? ' )
			. '<a href="' . esc_url( $u ) . '" style="color:#8a939b">' . ( 'es' === $lang ? 'Cancelar suscripción' : 'Unsubscribe' ) . '</a>';
		$ok = SDSC_Mailer::send( $c['email'], $subject, $html, 'info', array(
			'List-Unsubscribe: <' . $u . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		) );
		SDSC_Mailer::$extra = '';
		return (bool) $ok;
	}

	/** Birthday / anniversary / license-question e-mail to one student. Returns true when sent. */
	public static function send_kind( $kind, $c ) {
		if ( $c['oe'] ) { return false; }
		list( $subject, $html ) = self::build( $kind, $c, $c['lang'] );
		$ok = self::mail_to( $c, $subject, $html, $c['lang'] );
		if ( $ok ) {
			$flag = array( 'birthday' => 'b', 'anniversary' => 'a', 'license_ask' => 'q' )[ $kind ] . current_time( 'Y' ) . 'e';
			self::mark_sent( $c['k'], $flag );
		}
		return $ok;
	}

	public static function schedules( $s ) {
		$s['sdsc_5min'] = array( 'interval' => 300, 'display' => 'Every 5 minutes' );
		return $s;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 60, 'sdsc_5min', self::HOOK ); }
	}

	public static function tick() {
		if ( get_transient( 'sdsc_or_lock' ) ) { return; }
		set_transient( 'sdsc_or_lock', 1, 240 );
		try {
			self::daily();
			self::drain();
		} finally {
			delete_transient( 'sdsc_or_lock' );
		}
	}

	/** Once a day, after the set hour: birthday and anniversary e-mails. */
	protected static function daily() {
		$s     = self::settings();
		$today = current_time( 'Y-m-d' );
		if ( ! $s['auto'] || ! SDSC_Mailer::ready() ) { return; }
		if ( get_option( 'sdsc_or_day' ) === $today || (int) current_time( 'G' ) < (int) $s['hour'] ) { return; }
		update_option( 'sdsc_or_day', $today, false );
		$y    = current_time( 'Y' );
		$list = self::on_day( $today );
		foreach ( $list['b'] as $c ) {
			if ( empty( $c['sent'][ 'b' . $y . 'e' ] ) ) { self::send_kind( 'birthday', $c ); }
		}
		foreach ( $list['a'] as $c ) {
			if ( empty( $c['sent'][ 'a' . $y . 'e' ] ) ) { self::send_kind( 'anniversary', $c ); }
		}
	}

	/* ---------------- campaigns */

	public static function campaigns() {
		$c = get_option( self::CAMP, array() );
		return is_array( $c ) ? $c : array();
	}

	protected static function put( $camp ) {
		$all                 = self::campaigns();
		$all[ $camp['id'] ]  = $camp;
		update_option( self::CAMP, $all, false );
	}

	public static function blank() {
		$p = self::presets()['classes'];
		return array(
			'id' => 0, 'name' => '', 'preset' => 'classes', 'who' => 'all', 'months' => 24, 'lang' => 'each',
			'email' => 1, 'text' => 1, 'parent' => 1, 'optin' => 0, 'url' => $p['url'],
			'en' => $p['en'], 'es' => $p['es'],
			'status' => 'draft', 'created' => 0, 'by' => 0, 'started' => 0,
			'q' => array(), 'tq' => array(), 'tot' => 0, 'sent' => 0, 'fail' => 0, 'texts' => array(),
		);
	}

	/** Students a campaign is for. */
	public static function audience( $camp ) {
		$out   = array();
		$since = gmdate( 'Y-m-d', strtotime( '-' . max( 1, (int) $camp['months'] ) . ' months', current_time( 'timestamp' ) ) ); // phpcs:ignore
		foreach ( self::contacts() as $k => $c ) {
			$age = self::age( $c['dob'] );
			switch ( $camp['who'] ) {
				case 'recent':     $in = substr( $c['since'], 0, 10 ) >= $since; break;
				case 'adult':      $in = null !== $age && $age >= 18; break;
				case 'minor':      $in = null !== $age && $age < 18; break;
				case 'licensed':   $in = (bool) $c['lic']; break;
				case 'unlicensed': $in = ! $c['lic']; break;
				default:           $in = true;
			}
			if ( $in ) { $out[] = $k; }
		}
		return $out;
	}

	/** Which language(s) a student receives a campaign in. */
	public static function langs_for( $camp, $c ) {
		if ( 'both' === $camp['lang'] ) { return array( 'en', 'es' ); }
		if ( 'each' === $camp['lang'] ) { return array( $c['lang'] ); }
		return array( 'es' === $camp['lang'] ? 'es' : 'en' );
	}

	public static function camp_message( $camp, $c ) {
		$langs = self::langs_for( $camp, $c );
		$url   = $camp['url'];
		$subj  = array();
		$html  = '';
		$sms   = array();
		foreach ( $langs as $i => $l ) {
			$t      = $camp[ $l ];
			$vars   = array( '{link}' => $url );
			$subj[] = self::fill( $t['subject'], $c, $l, $vars );
			if ( $i ) { $html .= '<div style="margin:26px 0;border-top:1px dashed #d5d9dd;font-size:0;line-height:0">&nbsp;</div>'; }
			$html  .= self::email_body( self::fill( $t['heading'], $c, $l, $vars ), self::fill( $t['body'], $c, $l, $vars ), $t['btn'], $url );
			$sms[]  = self::fill( $t['sms'], $c, $l, $vars );
		}
		return array( implode( ' / ', array_filter( $subj ) ), $html, implode( "\n\n", $sms ), $langs[0] );
	}

	/** Send queued campaign e-mails, a few at a time. */
	protected static function drain() {
		if ( ! SDSC_Mailer::ready() ) { return; }
		$left = max( 1, min( 60, (int) self::settings()['batch'] ) );
		foreach ( self::campaigns() as $camp ) {
			if ( 'sending' !== $camp['status'] ) { continue; }
			while ( $left > 0 && $camp['q'] ) {
				$k = array_shift( $camp['q'] );
				$c = self::contact( $k );
				if ( ! $c || $c['oe'] ) { continue; }
				list( $subject, $html, , $lang ) = self::camp_message( $camp, $c );
				if ( self::mail_to( $c, $subject, $html, $lang ) ) { $camp['sent']++; } else { $camp['fail']++; }
				$left--;
				self::put( $camp ); // save after every e-mail, so nothing is sent twice
			}
			if ( ! $camp['q'] ) { $camp['status'] = 'done'; self::put( $camp ); }
			if ( $left <= 0 ) { break; }
		}
	}

	/* ================================================================ admin: menu & assets */

	public static function can() {
		return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'outreach' ) : current_user_can( 'manage_options' );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Celebrations & campaigns', 'Celebrations & campaigns', 'sdsc_access_outreach', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** Red counter: today's birthdays and anniversaries that have not been texted yet. */
	public static function pending_today() {
		$y = current_time( 'Y' );
		$n = 0;
		$l = self::on_day( current_time( 'Y-m-d' ) );
		foreach ( $l['b'] as $c ) { if ( ! $c['os'] && empty( $c['sent'][ 'b' . $y . 't' ] ) ) { $n++; } }
		foreach ( $l['a'] as $c ) { if ( ! $c['os'] && empty( $c['sent'][ 'a' . $y . 't' ] ) ) { $n++; } }
		return $n;
	}

	public static function badge() {
		global $submenu;
		if ( ! self::can() ) { return; }
		$n = self::pending_today();
		if ( ! $n ) { return; }
		foreach ( (array) ( $submenu['sdsc-dashboard'] ?? array() ) as $i => $it ) {
			if ( self::PAGE === $it[2] ) { $submenu['sdsc-dashboard'][ $i ][0] .= ' <span class="update-plugins count-' . $n . '"><span class="plugin-count">' . $n . '</span></span>'; } // phpcs:ignore
		}
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) { return; }
		wp_enqueue_style( 'sdsc-outreach', SDSC_URL . 'assets/outreach.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-outreach', SDSC_URL . 'assets/outreach.js', array(), SDSC_VERSION, true );
		$p = self::presets();
		wp_localize_script( 'sdsc-outreach', 'SDSC_OR', array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sdsc_or' ),
			'presets' => $p,
			'sample'  => array( 'first_name' => 'Maria', 'phone' => SDSC_Mailer::info()['phones'][0], 'school' => 'Sam’s Driving School' ),
		) );
	}

	/* ================================================================ admin: page */

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		$tabs = array( 'today' => 'Today', 'students' => 'Students', 'campaigns' => 'Campaigns', 'messages' => 'Message wording', 'settings' => 'Settings' );
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'today'; // phpcs:ignore
		?>
		<div class="wrap sdo">
			<h1 class="sdo-title"><span class="sdo-title-ic" aria-hidden="true">🎉</span> Celebrations &amp; campaigns</h1>
			<?php if ( isset( $_GET['msg'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['err'] ) ) : // phpcs:ignore ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<?php if ( ! SDSC_Mailer::ready() ) : ?>
				<div class="sdo-hold"><b>E-mails are on hold.</b> Nothing is e-mailed until the school mailbox is connected on the <?php echo current_user_can( 'manage_options' ) ? '<a href="' . esc_url( admin_url( 'admin.php?page=sdsc-email' ) ) . '">Email</a>' : 'Email'; ?> page, so no message is sent from an unverified server and lands in spam. Texts work now.</div>
			<?php endif; ?>
			<nav class="sdo-tabs" aria-label="Sections">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a class="sdo-tab<?php echo $k === $tab ? ' is-on' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $k ) ); ?>"<?php echo $k === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><?php if ( 'today' === $k && ( $n = self::pending_today() ) ) : ?> <span class="sdo-count"><?php echo (int) $n; ?></span><?php endif; ?></a>
				<?php endforeach; ?>
			</nav>
			<p class="sdo-phone-hint" hidden>Tip: the <b>Text</b> buttons open your phone&rsquo;s Messages app. Open this page on your phone (signed in to the website) to send each text with one tap.</p>
			<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
		</div>
		<?php
	}

	/* ---------------- shared bits */

	protected static function chip( $text, $tone = '', $cls = '' ) {
		return '<span class="sdo-chip' . ( $tone ? ' sdo-chip--' . $tone : '' ) . ( $cls ? ' ' . $cls : '' ) . '">' . esc_html( $text ) . '</span>';
	}

	/** "Text" button: opens Messages with the number and the message. Both languages ride along for the language switch. */
	protected static function sms_button( $phone, $texts, $lang, $label, $mark, $class = '' ) {
		$to = self::phone_e164( $phone );
		if ( ! $to ) { return '<span class="sdo-muted">No valid phone</span>'; }
		$attr = '';
		foreach ( $texts as $l => $t ) { $attr .= ' data-' . $l . '="' . esc_attr( $t ) . '"'; }
		return '<a class="sdo-btn sdo-btn--text ' . esc_attr( $class ) . '" data-sms="' . esc_attr( $to ) . '" data-lang="' . esc_attr( $lang ) . '"' . $attr
			. ' data-mark="' . esc_attr( $mark ) . '" href="' . esc_attr( 'sms:' . $to . '?&body=' . rawurlencode( $texts[ $lang ] ?? reset( $texts ) ) ) . '">'
			. '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H8l-4 4z"/></svg>' . esc_html( $label ) . '</a>';
	}

	protected static function lang_switch( $lang ) {
		return '<span class="sdo-lang" role="group" aria-label="Message language">'
			. '<button type="button" data-set="en"' . ( 'en' === $lang ? ' class="is-on" aria-pressed="true"' : ' aria-pressed="false"' ) . '>EN</button>'
			. '<button type="button" data-set="es"' . ( 'es' === $lang ? ' class="is-on" aria-pressed="true"' : ' aria-pressed="false"' ) . '>ES</button></span>';
	}

	protected static function post_url( $action, $extra = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'sdsc_or_' . $action ), $extra ), admin_url( 'admin-post.php' ) ), 'sdsc_or' );
	}

	/* ---------------- Today */

	protected static function tab_today() {
		$today = current_time( 'Y-m-d' );
		$list  = self::on_day( $today );
		$y     = current_time( 'Y' );
		?>
		<p class="sdo-lead">Every morning the birthday and license-anniversary e-mails go out by themselves<?php echo SDSC_Mailer::ready() ? '' : ' (once e-mail is connected)'; ?>. Here you send the personal text: tap <b>Text</b>, your phone opens Messages with the number and the greeting ready, and you press Send.</p>

		<?php foreach ( array( 'b' => array( 'Birthdays today', 'birthday', '🎂' ), 'a' => array( 'License anniversaries today', 'anniversary', '🚗' ) ) as $t => $meta ) : ?>
			<section class="sdo-sec" id="sdo-<?php echo esc_attr( $meta[1] ); ?>">
				<h2><span aria-hidden="true"><?php echo esc_html( $meta[2] ); ?></span> <?php echo esc_html( $meta[0] ); ?> <span class="sdo-n"><?php echo count( $list[ $t ] ); ?></span></h2>
				<?php if ( ! $list[ $t ] ) : ?>
					<p class="sdo-empty"><?php echo 'b' === $t ? 'No birthdays today.' : 'No license anniversaries today. Anniversaries appear once a student&rsquo;s license date is filled in on the Students tab.'; ?></p>
				<?php else : ?>
					<div class="sdo-cards">
						<?php foreach ( $list[ $t ] as $c ) { self::celebration_card( $c, $meta[1], $t, $y ); } ?>
					</div>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>

		<section class="sdo-sec">
			<h2><span aria-hidden="true">📅</span> Coming up this week</h2>
			<?php
			$rows = array();
			for ( $i = 1; $i <= 7; $i++ ) {
				$d = gmdate( 'Y-m-d', strtotime( $today . ' +' . $i . ' day' ) );
				$l = self::on_day( $d );
				foreach ( $l['b'] as $c ) { $rows[] = array( $d, $c, 'Birthday', '🎂' ); }
				foreach ( $l['a'] as $c ) { $rows[] = array( $d, $c, 'License anniversary', '🚗' ); }
			}
			if ( ! $rows ) { echo '<p class="sdo-empty">Nothing in the next seven days.</p>'; }
			else {
				echo '<ul class="sdo-soon">';
				foreach ( $rows as $r ) {
					echo '<li><span class="sdo-soon-d">' . esc_html( wp_date( 'D, M j', strtotime( $r[0] . ' 12:00' ) ) ) . '</span><span aria-hidden="true">' . esc_html( $r[3] ) . '</span> <b>' . esc_html( trim( $r[1]['first'] . ' ' . $r[1]['last'] ) ) . '</b> <span class="sdo-muted">' . esc_html( $r[2] ) . '</span></li>';
				}
				echo '</ul>';
			}
			?>
		</section>
		<?php
	}

	protected static function celebration_card( $c, $kind, $t, $y ) {
		$texts = array();
		foreach ( array( 'en', 'es' ) as $l ) { $texts[ $l ] = self::build( $kind, $c, $l )[2]; }
		$age    = self::age( $c['dob'] );
		$emailed = ! empty( $c['sent'][ $t . $y . 'e' ] );
		$texted  = ! empty( $c['sent'][ $t . $y . 't' ] );
		$sub     = 'b' === $t
			? 'Turns ' . (int) ( (int) $y - (int) substr( $c['dob'], 0, 4 ) )
			: self::years( (int) $y - (int) substr( $c['lic'], 0, 4 ), 'en' ) . ' with a license';
		?>
		<article class="sdo-card" data-k="<?php echo esc_attr( $c['k'] ); ?>">
			<header class="sdo-card-h">
				<div class="sdo-av" aria-hidden="true"><?php echo esc_html( mb_substr( $c['first'], 0, 1 ) . mb_substr( $c['last'], 0, 1 ) ); ?></div>
				<div>
					<h3><?php echo esc_html( trim( $c['first'] . ' ' . $c['last'] ) ); ?></h3>
					<p class="sdo-muted"><?php echo esc_html( $sub ); ?> · <?php echo esc_html( self::phone_nice( $c['phone'] ) ); ?></p>
				</div>
				<?php echo self::lang_switch( $c['lang'] ); // phpcs:ignore ?>
			</header>
			<p class="sdo-bubble" data-en="<?php echo esc_attr( $texts['en'] ); ?>" data-es="<?php echo esc_attr( $texts['es'] ); ?>"><?php echo esc_html( $texts[ $c['lang'] ] ); ?></p>
			<div class="sdo-status">
				<?php
				echo $c['oe'] ? self::chip( 'E-mail: unsubscribed', 'off' ) : ( $emailed ? self::chip( '✓ E-mail sent', 'ok' ) : self::chip( SDSC_Mailer::ready() ? 'E-mail: goes out this morning' : 'E-mail: on hold' ) ); // phpcs:ignore
				echo $c['os'] ? self::chip( 'Texts: opted out', 'off' ) : ( $texted ? self::chip( '✓ Texted', 'ok', 'sdo-texted' ) : '' ); // phpcs:ignore
				?>
			</div>
			<div class="sdo-actions">
				<?php
				if ( ! $c['os'] ) {
					echo self::sms_button( $c['phone'], $texts, $c['lang'], 'Text ' . $c['first'], $t . '|' . $c['k'] ); // phpcs:ignore
					$age_now = self::age( $c['dob'] );
					if ( null !== $age_now && $age_now < 18 && self::phone_e164( $c['pphone'] ) && self::phone_e164( $c['pphone'] ) !== self::phone_e164( $c['phone'] ) ) {
						echo self::sms_button( $c['pphone'], $texts, $c['lang'], 'Text parent', $t . '|' . $c['k'], 'sdo-btn--ghost' ); // phpcs:ignore
					}
				}
				if ( ! $c['oe'] && ! $emailed && SDSC_Mailer::ready() ) {
					echo '<a class="sdo-btn sdo-btn--ghost" href="' . esc_url( self::post_url( 'send_one', array( 'kind' => 'b' === $t ? 'birthday' : 'anniversary', 'k' => $c['k'] ) ) ) . '">E-mail now</a>';
				}
				?>
				<button type="button" class="sdo-btn sdo-btn--ghost sdo-copy">Copy text</button>
			</div>
		</article>
		<?php
	}

	/* ---------------- Students */

	protected static function tab_students() {
		$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore
		$f      = isset( $_GET['f'] ) ? sanitize_key( $_GET['f'] ) : ''; // phpcs:ignore
		$pg     = max( 1, (int) ( $_GET['pg'] ?? 1 ) ); // phpcs:ignore
		$all    = self::contacts();
		$digits = preg_replace( '/\D/', '', $q );
		$rows   = array_filter( $all, function ( $c ) use ( $q, $f, $digits ) {
			if ( 'lic' === $f && ! $c['lic'] ) { return false; }
			if ( 'nolic' === $f && $c['lic'] ) { return false; }
			if ( 'es' === $f && 'es' !== $c['lang'] ) { return false; }
			if ( 'mkt' === $f && ! $c['mkt'] ) { return false; }
			if ( 'off' === $f && ! $c['oe'] && ! $c['os'] ) { return false; }
			if ( '' === $q ) { return true; }
			$hay = strtolower( $c['first'] . ' ' . $c['last'] . ' ' . $c['email'] );
			return false !== strpos( $hay, strtolower( $q ) ) || ( strlen( $digits ) >= 4 && false !== strpos( preg_replace( '/\D/', '', $c['phone'] . ' ' . $c['pphone'] ), $digits ) );
		} );
		$total = count( $rows );
		$per   = 40;
		$rows  = array_slice( $rows, ( $pg - 1 ) * $per, $per );
		$base  = admin_url( 'admin.php?page=' . self::PAGE . '&tab=students' );
		$ask   = self::templates()['license_ask'];
		?>
		<p class="sdo-lead">Every student who registered on the website, once (newest details first). Set the language they prefer and, after their road test, the day they got their license — or tap <b>Ask</b> to send them a link where they fill it in themselves.</p>
		<form class="sdo-filter" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><input type="hidden" name="tab" value="students">
			<label class="screen-reader-text" for="sdo-q">Search students</label>
			<input type="search" id="sdo-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Name, e-mail or phone">
			<select name="f" aria-label="Show">
				<?php foreach ( array( '' => 'All students', 'nolic' => 'No license date yet', 'lic' => 'Has license date', 'es' => 'Prefers Spanish', 'mkt' => 'Agreed to offers', 'off' => 'Opted out' ) as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $f, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button">Search</button>
			<span class="sdo-muted"><?php echo (int) $total; ?> of <?php echo count( $all ); ?> students</span>
		</form>
		<div class="sdo-list">
			<?php foreach ( $rows as $c ) :
				$age   = self::age( $c['dob'] );
				$texts = array();
				foreach ( array( 'en', 'es' ) as $l ) { $texts[ $l ] = self::build( 'license_ask', $c, $l )[2]; }
				?>
				<form class="sdo-row" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'sdsc_or' ); ?>
					<input type="hidden" name="action" value="sdsc_or_student"><input type="hidden" name="k" value="<?php echo esc_attr( $c['k'] ); ?>">
					<input type="hidden" name="back" value="<?php echo esc_attr( add_query_arg( array( 'q' => $q, 'f' => $f, 'pg' => $pg ), $base ) ); ?>">
					<div class="sdo-row-who">
						<b><?php echo esc_html( trim( $c['first'] . ' ' . $c['last'] ) ); ?><?php echo $c['mkt'] ? ' <span class="sdo-chip sdo-chip--ok">Agreed to offers</span>' : ''; ?></b>
						<span class="sdo-muted"><?php echo $c['dob'] ? esc_html( 'Birthday ' . wp_date( 'M j', strtotime( $c['dob'] . ' 12:00' ) ) . ( null !== $age ? ' · ' . $age : '' ) ) : 'No birthday'; ?></span>
						<span class="sdo-muted"><?php echo esc_html( self::phone_nice( $c['phone'] ) . ' · ' . $c['email'] ); ?></span>
					</div>
					<label class="sdo-f"><span>Language</span>
						<select name="lang"><option value="en"<?php selected( $c['lang'], 'en' ); ?>>English</option><option value="es"<?php selected( $c['lang'], 'es' ); ?>>Español</option></select>
					</label>
					<label class="sdo-f"><span>License date<?php echo 'student' === $c['licsrc'] ? ' <em class="sdo-by">by student</em>' : ''; ?></span>
						<input type="date" name="lic" value="<?php echo esc_attr( $c['lic'] ); ?>" min="1970-01-01" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
					</label>
					<div class="sdo-f sdo-f--checks"><span>Opted out of</span>
						<label><input type="checkbox" name="oe" value="1"<?php checked( $c['oe'] ); ?>> E-mail</label>
						<label><input type="checkbox" name="os" value="1"<?php checked( $c['os'] ); ?>> Texts</label>
					</div>
					<div class="sdo-row-act">
						<button class="sdo-btn">Save</button>
						<?php if ( ! $c['lic'] ) : ?>
							<?php if ( ! $c['os'] ) { echo self::sms_button( $c['phone'], $texts, $c['lang'], 'Ask by text', 'q|' . $c['k'], 'sdo-btn--ghost' ); } // phpcs:ignore ?>
							<?php if ( ! $c['oe'] && SDSC_Mailer::ready() ) : ?><a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::post_url( 'send_one', array( 'kind' => 'license_ask', 'k' => $c['k'] ) ) ); ?>">Ask by e-mail</a><?php endif; ?>
						<?php endif; ?>
					</div>
				</form>
			<?php endforeach; ?>
			<?php if ( ! $rows ) : ?><p class="sdo-empty">No students match.</p><?php endif; ?>
		</div>
		<?php if ( $total > $per ) : ?>
			<p class="sdo-pages">
				<?php for ( $i = 1; $i <= (int) ceil( $total / $per ); $i++ ) : ?>
					<a class="<?php echo $i === $pg ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'q' => $q, 'f' => $f, 'pg' => $i ), $base ) ); ?>"><?php echo (int) $i; ?></a>
				<?php endfor; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/* ---------------- Campaigns */

	protected static function tab_campaigns() {
		$id = isset( $_GET['c'] ) ? sanitize_key( $_GET['c'] ) : ''; // phpcs:ignore
		if ( '' !== $id ) {
			$all  = self::campaigns();
			$camp = 'new' === $id ? self::blank() : ( $all[ (int) $id ] ?? null );
			if ( ! $camp ) { echo '<p>Campaign not found.</p>'; return; }
			'draft' === $camp['status'] ? self::editor( $camp ) : self::progress( $camp );
			return;
		}
		$all = self::campaigns();
		krsort( $all );
		?>
		<p class="sdo-lead">One message to many students — new class dates, a new discount, an announcement. E-mails go out a few at a time in the background; texts are a list you tap through on your phone.</p>
		<p><a class="sdo-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=campaigns&c=new' ) ); ?>">+ New campaign</a></p>
		<?php if ( ! $all ) : ?>
			<p class="sdo-empty">No campaigns yet.</p>
		<?php else : ?>
			<div class="sdo-camps">
				<?php foreach ( $all as $camp ) :
					$texted = count( $camp['texts'] );
					$tt     = count( $camp['tq'] );
					$done   = $camp['tot'] ? round( 100 * ( $camp['sent'] + $camp['fail'] ) / $camp['tot'] ) : 0;
					?>
					<a class="sdo-camp" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=campaigns&c=' . (int) $camp['id'] ) ); ?>">
						<span class="sdo-camp-t"><?php echo esc_html( $camp['name'] ?: '(untitled)' ); ?></span>
						<span class="sdo-camp-s sdo-st--<?php echo esc_attr( $camp['status'] ); ?>"><?php echo esc_html( ucfirst( $camp['status'] ) ); ?></span>
						<span class="sdo-muted"><?php echo esc_html( $camp['created'] ? wp_date( 'M j, Y', $camp['created'] ) : '' ); ?></span>
						<?php if ( 'draft' !== $camp['status'] ) : ?>
							<span class="sdo-camp-m"><?php echo $camp['email'] ? esc_html( 'E-mails ' . $camp['sent'] . ' / ' . $camp['tot'] . ( $camp['fail'] ? ' · ' . $camp['fail'] . ' failed' : '' ) ) : ''; ?><?php echo $camp['text'] ? esc_html( ( $camp['email'] ? ' · ' : '' ) . 'Texts ' . $texted . ' / ' . $tt ) : ''; ?></span>
							<?php if ( $camp['email'] ) : ?><span class="sdo-bar"><span style="width:<?php echo (int) $done; ?>%"></span></span><?php endif; ?>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	protected static function editor( $camp ) {
		$aud = array(
			'all'        => 'Every student',
			'recent'     => 'Registered in the last … months',
			'adult'      => '18 and over today',
			'minor'      => 'Under 18 today',
			'unlicensed' => 'No license date yet',
			'licensed'   => 'Has a license date',
		);
		$counts = array();
		foreach ( array_keys( $aud ) as $w ) { $counts[ $w ] = 'recent' === $w ? null : count( self::audience( array_merge( $camp, array( 'who' => $w ) ) ) ); }
		?>
		<form class="sdo-editor" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sdo-editor">
			<?php wp_nonce_field( 'sdsc_or' ); ?>
			<input type="hidden" name="action" value="sdsc_or_camp_save"><input type="hidden" name="id" value="<?php echo (int) $camp['id']; ?>">
			<div class="sdo-ed-main">
				<div class="sdo-panel">
					<h2>1. What is it about?</h2>
					<label class="sdo-f sdo-f--wide"><span>Campaign name (only staff see it)</span><input type="text" name="name" required maxlength="120" value="<?php echo esc_attr( $camp['name'] ); ?>" placeholder="e.g. November driver’s ed dates"></label>
					<div class="sdo-presets" role="group" aria-label="Start from">
						<?php foreach ( self::presets() as $k => $p ) : ?>
							<label class="sdo-preset"><input type="radio" name="preset" value="<?php echo esc_attr( $k ); ?>"<?php checked( $camp['preset'], $k ); ?>><span><?php echo esc_html( $p['label'] ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<p class="sdo-note">Choosing a type fills in a ready-made message in both languages. Replace every line in <b>[square brackets]</b> — the campaign cannot start while one is left. Only announce discounts that are already live on the Discounts page, exactly as checkout charges them.</p>
				</div>

				<div class="sdo-panel">
					<h2>2. Who gets it?</h2>
					<div class="sdo-aud">
						<?php foreach ( $aud as $k => $l ) : ?>
							<label class="sdo-radio"><input type="radio" name="who" value="<?php echo esc_attr( $k ); ?>"<?php checked( $camp['who'], $k ); ?>>
								<span><?php echo 'recent' === $k ? 'Registered in the last <input type="number" name="months" min="1" max="120" value="' . (int) $camp['months'] . '" class="sdo-num" aria-label="Months"> months' : esc_html( $l ); // phpcs:ignore ?>
								<?php if ( null !== $counts[ $k ] ) : ?><em class="sdo-muted"><?php echo (int) $counts[ $k ]; ?></em><?php endif; ?></span></label>
						<?php endforeach; ?>
					</div>
					<div class="sdo-inline">
						<label class="sdo-check"><input type="checkbox" name="email" value="1"<?php checked( $camp['email'] ); ?>> Send by e-mail</label>
						<label class="sdo-check"><input type="checkbox" name="text" value="1"<?php checked( $camp['text'] ); ?>> Send by text</label>
						<label class="sdo-check"><input type="checkbox" name="parent" value="1"<?php checked( $camp['parent'] ); ?>> Text the parent of students under 18</label>
						<label class="sdo-check"><input type="checkbox" name="optin" value="1"<?php checked( ! empty( $camp['optin'] ) ); ?>> Text only students who ticked &ldquo;send me offers&rdquo; <em class="sdo-muted">(<?php echo (int) count( array_filter( self::contacts(), function ( $c ) { return $c['mkt']; } ) ); ?> so far)</em></label>
					</div>
					<p class="sdo-note">Students who unsubscribed or opted out of texts are always left out. New registrations can tick &ldquo;send me birthday wishes, new class dates and offers&rdquo; on the form; offer texts to people who said yes are the safest.</p>
				</div>

				<div class="sdo-panel">
					<h2>3. Language</h2>
					<div class="sdo-inline">
						<?php foreach ( array( 'each' => 'Each student’s own language', 'en' => 'English to everyone', 'es' => 'Spanish to everyone', 'both' => 'Both languages in one message' ) as $k => $l ) : ?>
							<label class="sdo-radio"><input type="radio" name="lang" value="<?php echo esc_attr( $k ); ?>"<?php checked( $camp['lang'], $k ); ?>> <span><?php echo esc_html( $l ); ?></span></label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="sdo-panel">
					<h2>4. The message</h2>
					<label class="sdo-f sdo-f--wide"><span>Button link (used as {link})</span><input type="url" name="url" value="<?php echo esc_attr( $camp['url'] ); ?>" placeholder="https://samsdrivingschool.org/…"></label>
					<div class="sdo-langtabs" role="tablist">
						<button type="button" role="tab" class="is-on" aria-selected="true" data-pane="en">English</button>
						<button type="button" role="tab" aria-selected="false" data-pane="es">Español</button>
					</div>
					<?php foreach ( array( 'en', 'es' ) as $l ) : $t = $camp[ $l ]; ?>
						<div class="sdo-pane" data-pane="<?php echo esc_attr( $l ); ?>"<?php echo 'en' === $l ? '' : ' hidden'; ?>>
							<label class="sdo-f sdo-f--wide"><span>E-mail subject</span><input type="text" name="<?php echo esc_attr( $l ); ?>[subject]" data-f="subject" value="<?php echo esc_attr( $t['subject'] ); ?>"></label>
							<label class="sdo-f sdo-f--wide"><span>Headline</span><input type="text" name="<?php echo esc_attr( $l ); ?>[heading]" data-f="heading" value="<?php echo esc_attr( $t['heading'] ); ?>"></label>
							<label class="sdo-f sdo-f--wide"><span>E-mail text</span><textarea name="<?php echo esc_attr( $l ); ?>[body]" data-f="body" rows="8"><?php echo esc_textarea( $t['body'] ); ?></textarea></label>
							<label class="sdo-f sdo-f--wide"><span>Button text</span><input type="text" name="<?php echo esc_attr( $l ); ?>[btn]" data-f="btn" value="<?php echo esc_attr( $t['btn'] ); ?>"></label>
							<label class="sdo-f sdo-f--wide"><span>Text message <em class="sdo-cc" data-for="<?php echo esc_attr( $l ); ?>"></em></span><textarea name="<?php echo esc_attr( $l ); ?>[sms]" data-f="sms" rows="3"><?php echo esc_textarea( $t['sms'] ); ?></textarea></label>
						</div>
					<?php endforeach; ?>
					<p class="sdo-note">You can use <code>{first_name}</code>, <code>{phone}</code>, <code>{link}</code> and <code>{website}</code>. The school signature, address and unsubscribe link are added to every e-mail automatically.</p>
				</div>

				<div class="sdo-ed-bar">
					<button class="sdo-btn" name="go" value="save">Save draft</button>
					<?php if ( $camp['id'] ) : ?>
						<button class="sdo-btn sdo-btn--ghost" name="go" value="test"<?php disabled( ! SDSC_Mailer::ready() ); ?>>E-mail me a test</button>
						<button class="sdo-btn sdo-btn--go" name="go" value="start" data-confirm="Start this campaign now? E-mails begin going out within a few minutes and cannot be called back.">Start campaign</button>
						<button class="sdo-btn sdo-btn--danger" name="go" value="delete" data-confirm="Delete this draft?">Delete</button>
					<?php else : ?>
						<span class="sdo-muted">Save the draft first — then you can send yourself a test and start it.</span>
					<?php endif; ?>
				</div>
			</div>

			<aside class="sdo-ed-prev" aria-label="Preview">
				<div class="sdo-prev-h"><b>Preview</b> <?php echo self::lang_switch( 'en' ); // phpcs:ignore ?></div>
				<div class="sdo-mock">
					<div class="sdo-mock-subj"></div>
					<div class="sdo-mock-mail">
						<div class="sdo-mock-logo">Sam&rsquo;s Driving School</div>
						<div class="sdo-mock-road"></div>
						<h3 class="sdo-mock-h"></h3>
						<div class="sdo-mock-b"></div>
						<p class="sdo-mock-btn"><span></span></p>
						<div class="sdo-mock-sig"><?php echo esc_html( SDSC_Mailer::info()['name'] ); ?><br><?php echo esc_html( SDSC_Mailer::info()['street'] . ', ' . SDSC_Mailer::info()['city'] ); ?></div>
					</div>
					<div class="sdo-mock-phone"><div class="sdo-mock-sms"></div></div>
				</div>
				<p class="sdo-muted">Shown with the sample name “Maria”.</p>
			</aside>
		</form>
		<?php
	}

	protected static function progress( $camp ) {
		$all   = self::contacts();
		$tq    = $camp['tq'];
		$left  = count( array_diff( $tq, array_keys( $camp['texts'] ) ) );
		$done  = $camp['tot'] ? round( 100 * ( $camp['sent'] + $camp['fail'] ) / $camp['tot'] ) : 0;
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=campaigns' ) ); ?>">&larr; All campaigns</a></p>
		<div class="sdo-panel">
			<h2><?php echo esc_html( $camp['name'] ); ?> <span class="sdo-camp-s sdo-st--<?php echo esc_attr( $camp['status'] ); ?>"><?php echo esc_html( ucfirst( $camp['status'] ) ); ?></span></h2>
			<p class="sdo-muted">Started <?php echo esc_html( wp_date( 'M j, Y g:i a', $camp['started'] ) ); ?></p>
			<?php if ( $camp['email'] ) : ?>
				<p><b>E-mails:</b> <?php echo (int) $camp['sent']; ?> sent<?php echo $camp['fail'] ? ', ' . (int) $camp['fail'] . ' failed' : ''; ?>, <?php echo count( $camp['q'] ); ?> waiting (of <?php echo (int) $camp['tot']; ?>).
					<?php if ( $camp['q'] && ! SDSC_Mailer::ready() ) : ?><br><span class="sdo-warn">Waiting until e-mail is connected.</span><?php endif; ?></p>
				<span class="sdo-bar sdo-bar--big"><span style="width:<?php echo (int) $done; ?>%"></span></span>
				<p class="sdo-inline">
					<?php if ( 'sending' === $camp['status'] ) : ?><a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::post_url( 'camp_state', array( 'id' => $camp['id'], 'to' => 'paused' ) ) ); ?>">Pause e-mails</a><?php endif; ?>
					<?php if ( 'paused' === $camp['status'] ) : ?><a class="sdo-btn" href="<?php echo esc_url( self::post_url( 'camp_state', array( 'id' => $camp['id'], 'to' => 'sending' ) ) ); ?>">Resume e-mails</a><?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( $camp['text'] ) : ?>
			<div class="sdo-panel">
				<h2>Texts <span class="sdo-n"><?php echo (int) ( count( $tq ) - $left ); ?> / <?php echo count( $tq ); ?></span></h2>
				<?php if ( $left ) : ?>
					<p><button type="button" class="sdo-btn sdo-btn--go sdo-next">Text next person</button> <span class="sdo-muted">Opens Messages for the next person on the list. Press Send, come back, tap again.</span></p>
				<?php endif; ?>
				<ol class="sdo-tq">
					<?php foreach ( $tq as $item ) :
						list( $k, $who ) = array_pad( explode( ':', $item ), 2, 's' );
						$c = $all[ $k ] ?? null;
						if ( ! $c ) { continue; }
						list( , , $sms, $lang ) = self::camp_message( $camp, $c );
						$phone = 'p' === $who ? $c['pphone'] : $c['phone'];
						$sent  = isset( $camp['texts'][ $item ] );
						?>
						<li class="sdo-tq-i<?php echo $sent ? ' is-sent' : ''; ?>">
							<span class="sdo-tq-n"><b><?php echo esc_html( trim( $c['first'] . ' ' . $c['last'] ) ); ?></b><?php echo 'p' === $who ? ' <em class="sdo-muted">parent' . ( $c['pname'] ? ' · ' . esc_html( $c['pname'] ) : '' ) . '</em>' : ''; ?></span>
							<span class="sdo-muted"><?php echo esc_html( self::phone_nice( $phone ) ); ?></span>
							<?php echo self::sms_button( $phone, array( $lang => $sms ), $lang, $sent ? 'Text again' : 'Text', 'c|' . $item . '|' . $camp['id'], $sent ? 'sdo-btn--ghost' : '' ); // phpcs:ignore ?>
							<?php if ( $sent ) : ?><span class="sdo-chip sdo-chip--ok">✓ Texted</span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
		<?php endif; ?>
		<?php
	}

	/* ---------------- Message wording */

	protected static function tab_messages() {
		$t      = self::templates();
		$labels = array(
			'birthday'    => array( 'Birthday', 'Sent automatically on the student’s birthday; the text is what the Text button fills in.' ),
			'anniversary' => array( 'License anniversary', 'Sent every year on the day the student got their license. {years} becomes “1 year”, “2 years” …' ),
			'license_ask' => array( 'Asking for the license date', 'Sent when you tap Ask on the Students tab. {link} is the student’s personal link.' ),
		);
		$demo = array( 'k' => str_repeat( '0', 32 ), 'first' => 'Maria', 'last' => 'Lopez', 'lic' => ( (int) current_time( 'Y' ) - 2 ) . '-06-15', 'dob' => '', 'lang' => 'en' );
		?>
		<p class="sdo-lead">The words of every automatic message, in English and Spanish. Change them here; the preview shows how a student sees the e-mail.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdo-msgs">
			<?php wp_nonce_field( 'sdsc_or' ); ?><input type="hidden" name="action" value="sdsc_or_tpl">
			<?php foreach ( $labels as $kind => $meta ) : ?>
				<section class="sdo-panel">
					<h2><?php echo esc_html( $meta[0] ); ?></h2>
					<p class="sdo-muted"><?php echo esc_html( $meta[1] ); ?></p>
					<div class="sdo-two">
						<?php foreach ( array( 'en' => 'English', 'es' => 'Español' ) as $l => $ln ) : $f = $t[ $kind ][ $l ]; ?>
							<div class="sdo-col">
								<h3><?php echo esc_html( $ln ); ?></h3>
								<label class="sdo-f sdo-f--wide"><span>E-mail subject</span><input type="text" name="t[<?php echo esc_attr( $kind . '][' . $l ); ?>][subject]" value="<?php echo esc_attr( $f['subject'] ); ?>"></label>
								<label class="sdo-f sdo-f--wide"><span>Headline</span><input type="text" name="t[<?php echo esc_attr( $kind . '][' . $l ); ?>][heading]" value="<?php echo esc_attr( $f['heading'] ); ?>"></label>
								<label class="sdo-f sdo-f--wide"><span>E-mail text</span><textarea rows="6" name="t[<?php echo esc_attr( $kind . '][' . $l ); ?>][body]"><?php echo esc_textarea( $f['body'] ); ?></textarea></label>
								<?php if ( isset( $f['btn'] ) ) : ?><label class="sdo-f sdo-f--wide"><span>Button text</span><input type="text" name="t[<?php echo esc_attr( $kind . '][' . $l ); ?>][btn]" value="<?php echo esc_attr( $f['btn'] ); ?>"></label><?php endif; ?>
								<label class="sdo-f sdo-f--wide"><span>Text message <em class="sdo-cc"></em></span><textarea rows="3" class="sdo-smsf" name="t[<?php echo esc_attr( $kind . '][' . $l ); ?>][sms]"><?php echo esc_textarea( $f['sms'] ); ?></textarea></label>
								<details class="sdo-prevbox"><summary>Preview e-mail</summary>
									<?php
									list( $subj, $html ) = self::build( $kind, array_merge( $demo, array( 'lang' => $l ) ), $l );
									SDSC_Mailer::$extra = 'es' === $l ? '¿No desea recibir estos correos? <u>Cancelar suscripción</u>' : 'Don’t want these e-mails? <u>Unsubscribe</u>';
									$doc = SDSC_Mailer::template( $subj, $html );
									SDSC_Mailer::$extra = '';
									?>
									<p class="sdo-muted"><b>Subject:</b> <?php echo esc_html( $subj ); ?></p>
									<iframe class="sdo-frame" title="E-mail preview" srcdoc="<?php echo esc_attr( $doc ); ?>"></iframe>
								</details>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
			<p class="sdo-note">Placeholders: <code>{first_name}</code> <code>{last_name}</code> <code>{phone}</code> <code>{website}</code> <code>{years}</code> <code>{link}</code>. Empty a field and save to bring back the original wording.</p>
			<p><button class="sdo-btn">Save wording</button></p>
		</form>
		<?php
	}

	/* ---------------- Settings */

	protected static function tab_settings() {
		$s = self::settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdo-panel sdo-settings">
			<?php wp_nonce_field( 'sdsc_or' ); ?><input type="hidden" name="action" value="sdsc_or_set">
			<label class="sdo-check"><input type="checkbox" name="auto" value="1"<?php checked( $s['auto'] ); ?>> Send birthday and license-anniversary e-mails automatically</label>
			<label class="sdo-f"><span>Not before (school time)</span>
				<select name="hour"><?php for ( $h = 6; $h <= 14; $h++ ) : ?><option value="<?php echo (int) $h; ?>"<?php selected( (int) $s['hour'], $h ); ?>><?php echo esc_html( ( $h > 12 ? $h - 12 : $h ) . ( $h >= 12 ? ' pm' : ' am' ) ); ?></option><?php endfor; ?></select>
			</label>
			<label class="sdo-f"><span>Campaign e-mails every 5 minutes</span><input type="number" name="batch" min="1" max="60" value="<?php echo (int) $s['batch']; ?>" class="sdo-num"></label>
			<p class="sdo-note">Sending slowly keeps the school&rsquo;s address in good standing with Gmail and Outlook. 20 every 5 minutes is 240 an hour.</p>
			<p><button class="sdo-btn">Save</button></p>
		</form>
		<?php
	}

	/* ================================================================ handlers */

	protected static function guard() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_or' );
	}

	protected static function back( $tab, $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), array_map( 'rawurlencode', $args ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function h_student() {
		self::guard();
		$k = sanitize_key( $_POST['k'] ?? '' );
		if ( ! self::contact( $k ) ) { self::back( 'students', array( 'err' => 'Student not found.' ) ); }
		$lic = sanitize_text_field( wp_unslash( $_POST['lic'] ?? '' ) );
		$lic = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $lic ) && $lic <= current_time( 'Y-m-d' ) ? $lic : null;
		$was = self::contact( $k );
		self::save_pref( $k, array(
			'lang'         => 'es' === ( $_POST['lang'] ?? '' ) ? 'es' : 'en',
			'license_date' => $lic,
			'license_src'  => $lic ? ( $lic === $was['lic'] ? $was['licsrc'] : 'staff' ) : '',
			'opt_email'    => empty( $_POST['oe'] ) ? 0 : 1,
			'opt_sms'      => empty( $_POST['os'] ) ? 0 : 1,
		) );
		$back = esc_url_raw( wp_unslash( $_POST['back'] ?? '' ) );
		wp_safe_redirect( add_query_arg( 'msg', rawurlencode( 'Saved.' ), $back ?: admin_url( 'admin.php?page=' . self::PAGE . '&tab=students' ) ) );
		exit;
	}

	public static function h_tpl() {
		self::guard();
		$in  = (array) ( $_POST['t'] ?? array() ); // phpcs:ignore -- sanitised below
		$out = array();
		foreach ( self::defaults() as $kind => $langs ) {
			foreach ( $langs as $l => $fields ) {
				foreach ( $fields as $f => $v ) {
					$val = wp_unslash( $in[ $kind ][ $l ][ $f ] ?? '' );
					$out[ $kind ][ $l ][ $f ] = in_array( $f, array( 'body', 'sms' ), true ) ? sanitize_textarea_field( $val ) : sanitize_text_field( $val );
				}
			}
		}
		update_option( self::TPL, $out, false );
		self::back( 'messages', array( 'msg' => 'Wording saved.' ) );
	}

	public static function h_set() {
		self::guard();
		update_option( self::SET, array(
			'auto'  => empty( $_POST['auto'] ) ? 0 : 1,
			'hour'  => min( 14, max( 6, (int) ( $_POST['hour'] ?? 9 ) ) ),
			'batch' => min( 60, max( 1, (int) ( $_POST['batch'] ?? 20 ) ) ),
		), false );
		self::back( 'settings', array( 'msg' => 'Settings saved.' ) );
	}

	public static function h_camp_save() {
		self::guard();
		$all  = self::campaigns();
		$id   = (int) ( $_POST['id'] ?? 0 );
		$camp = $id && isset( $all[ $id ] ) ? $all[ $id ] : self::blank();
		if ( 'draft' !== $camp['status'] ) { self::back( 'campaigns', array( 'c' => $id, 'err' => 'This campaign has already started.' ) ); }
		$go = sanitize_key( $_POST['go'] ?? 'save' );
		if ( 'delete' === $go && $id ) {
			unset( $all[ $id ] );
			update_option( self::CAMP, $all, false );
			self::back( 'campaigns', array( 'msg' => 'Draft deleted.' ) );
		}
		$camp['name']   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$camp['preset'] = isset( self::presets()[ $_POST['preset'] ?? '' ] ) ? sanitize_key( $_POST['preset'] ) : 'news';
		$camp['who']    = in_array( $_POST['who'] ?? '', array( 'all', 'recent', 'adult', 'minor', 'licensed', 'unlicensed' ), true ) ? sanitize_key( $_POST['who'] ) : 'all';
		$camp['months'] = min( 120, max( 1, (int) ( $_POST['months'] ?? 24 ) ) );
		$camp['lang']   = in_array( $_POST['lang'] ?? '', array( 'each', 'en', 'es', 'both' ), true ) ? sanitize_key( $_POST['lang'] ) : 'each';
		$camp['email']  = empty( $_POST['email'] ) ? 0 : 1;
		$camp['text']   = empty( $_POST['text'] ) ? 0 : 1;
		$camp['parent'] = empty( $_POST['parent'] ) ? 0 : 1;
		$camp['optin']  = empty( $_POST['optin'] ) ? 0 : 1;
		$camp['url']    = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		foreach ( array( 'en', 'es' ) as $l ) {
			$in = (array) ( $_POST[ $l ] ?? array() ); // phpcs:ignore -- sanitised below
			foreach ( array( 'subject', 'heading', 'body', 'btn', 'sms' ) as $f ) {
				$v = wp_unslash( $in[ $f ] ?? '' );
				$camp[ $l ][ $f ] = in_array( $f, array( 'body', 'sms' ), true ) ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
			}
		}
		if ( ! $camp['id'] ) {
			$camp['id']      = $all ? max( array_keys( $all ) ) + 1 : 1;
			$camp['created'] = time();
			$camp['by']      = get_current_user_id();
		}
		self::put( $camp );

		if ( 'test' === $go ) {
			$me = wp_get_current_user();
			$c  = array( 'k' => str_repeat( 'f', 32 ), 'first' => $me->first_name ?: $me->display_name, 'last' => '', 'email' => $me->user_email, 'lang' => 'en', 'oe' => 0 );
			list( $subject, $html, , $lang ) = self::camp_message( $camp, $c );
			$ok = SDSC_Mailer::ready() && self::mail_to( $c, '[Test] ' . $subject, $html, $lang );
			self::back( 'campaigns', array( 'c' => $camp['id'], $ok ? 'msg' : 'err' => $ok ? 'Test sent to ' . $me->user_email . '.' : 'The test could not be sent — is e-mail connected?' ) );
		}
		if ( 'start' === $go ) {
			$langs = 'each' === $camp['lang'] || 'both' === $camp['lang'] ? array( 'en', 'es' ) : array( $camp['lang'] );
			foreach ( $langs as $l ) {
				foreach ( array( 'subject', 'heading', 'body', 'btn', 'sms' ) as $f ) {
					if ( self::unfinished( $camp[ $l ][ $f ] ) ) {
						self::back( 'campaigns', array( 'c' => $camp['id'], 'err' => 'Replace the [bracketed] line in the ' . ( 'es' === $l ? 'Spanish' : 'English' ) . ' ' . ( 'sms' === $f ? 'text message' : ( 'btn' === $f ? 'button' : $f ) ) . ' first.' ) );
					}
				}
				if ( $camp['email'] && ( '' === trim( $camp[ $l ]['subject'] ) || '' === trim( $camp[ $l ]['body'] ) ) ) {
					self::back( 'campaigns', array( 'c' => $camp['id'], 'err' => 'Fill in the ' . ( 'es' === $l ? 'Spanish' : 'English' ) . ' subject and e-mail text.' ) );
				}
				if ( $camp['text'] && '' === trim( $camp[ $l ]['sms'] ) ) {
					self::back( 'campaigns', array( 'c' => $camp['id'], 'err' => 'Fill in the ' . ( 'es' === $l ? 'Spanish' : 'English' ) . ' text message.' ) );
				}
			}
			if ( ! $camp['email'] && ! $camp['text'] ) { self::back( 'campaigns', array( 'c' => $camp['id'], 'err' => 'Choose e-mail, text or both.' ) ); }
			$keys = self::audience( $camp );
			$all  = self::contacts();
			$q    = array();
			$tq   = array();
			$seen = array();
			foreach ( $keys as $k ) {
				$c = $all[ $k ];
				if ( $camp['email'] && ! $c['oe'] ) { $q[] = $k; }
				if ( $camp['text'] && ! $c['os'] && ( empty( $camp['optin'] ) || $c['mkt'] ) ) {
					$age = self::age( $c['dob'] );
					$who = $camp['parent'] && null !== $age && $age < 18 && self::phone_e164( $c['pphone'] ) ? 'p' : 's';
					$ph  = self::phone_e164( 'p' === $who ? $c['pphone'] : $c['phone'] );
					if ( $ph && ! isset( $seen[ $ph ] ) ) { $seen[ $ph ] = 1; $tq[] = $k . ':' . $who; } // one text per phone number
				}
			}
			if ( ! $q && ! $tq ) { self::back( 'campaigns', array( 'c' => $camp['id'], 'err' => 'Nobody matches this audience.' ) ); }
			$camp['q']       = $q;
			$camp['tq']      = $tq;
			$camp['tot']     = count( $q );
			$camp['status']  = $q ? 'sending' : 'done';
			$camp['started'] = time();
			self::put( $camp );
			self::back( 'campaigns', array( 'c' => $camp['id'], 'msg' => 'Campaign started: ' . count( $q ) . ' e-mails queued, ' . count( $tq ) . ' texts on your list.' ) );
		}
		self::back( 'campaigns', array( 'c' => $camp['id'], 'msg' => 'Draft saved.' ) );
	}

	public static function h_camp_state() {
		self::guard();
		$all = self::campaigns();
		$id  = (int) ( $_GET['id'] ?? 0 );
		$to  = sanitize_key( $_GET['to'] ?? '' );
		if ( isset( $all[ $id ] ) && in_array( $to, array( 'paused', 'sending' ), true ) && in_array( $all[ $id ]['status'], array( 'paused', 'sending' ), true ) ) {
			$all[ $id ]['status'] = $to;
			update_option( self::CAMP, $all, false );
		}
		self::back( 'campaigns', array( 'c' => $id ) );
	}

	public static function h_send_one() {
		self::guard();
		$kind = sanitize_key( $_GET['kind'] ?? '' );
		$c    = self::contact( sanitize_key( $_GET['k'] ?? '' ) );
		$tab  = 'license_ask' === $kind ? 'students' : 'today';
		if ( ! $c || ! in_array( $kind, array( 'birthday', 'anniversary', 'license_ask' ), true ) ) { self::back( $tab, array( 'err' => 'Not found.' ) ); }
		if ( ! SDSC_Mailer::ready() ) { self::back( $tab, array( 'err' => 'E-mail is not connected yet.' ) ); }
		$ok = self::send_kind( $kind, $c );
		self::back( $tab, array( $ok ? 'msg' : 'err' => $ok ? 'E-mail sent to ' . $c['first'] . '.' : 'The e-mail could not be sent.' ) );
	}

	public static function h_test() { self::back( 'today' ); }

	/** A Text button was tapped: remember it. */
	public static function ajax_mark() {
		check_ajax_referer( 'sdsc_or' );
		if ( ! self::can() ) { wp_send_json_error( null, 403 ); }
		$parts = explode( '|', sanitize_text_field( wp_unslash( $_POST['mark'] ?? '' ) ) );
		$y     = current_time( 'Y' );
		if ( in_array( $parts[0], array( 'b', 'a', 'q' ), true ) && ! empty( $parts[1] ) && self::contact( $parts[1] ) ) {
			self::mark_sent( $parts[1], $parts[0] . $y . 't' );
			wp_send_json_success();
		}
		if ( 'c' === $parts[0] && isset( $parts[2] ) ) {
			$all = self::campaigns();
			$id  = (int) $parts[2];
			if ( isset( $all[ $id ] ) && in_array( $parts[1], $all[ $id ]['tq'], true ) ) {
				$all[ $id ]['texts'][ $parts[1] ] = time();
				update_option( self::CAMP, $all, false );
				wp_send_json_success();
			}
		}
		wp_send_json_error();
	}

	/* ================================================================ pages for students */

	public static function front() {
		if ( isset( $_GET['sdsc_unsub'] ) ) { self::page_unsub(); } // phpcs:ignore
		if ( isset( $_GET['sdsc_lic'] ) ) { self::page_lic(); } // phpcs:ignore
	}

	protected static function shell( $title, $inner, $lang = 'en' ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$i = SDSC_Mailer::info();
		echo '<!doctype html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title . ' · ' . $i['name'] ) . '</title><style>'
			. ':root{--g:#00703c;--y:#ffc20e;--ink:#15191d;--mut:#5b646d;--bg:#f6f4ee}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:var(--bg);font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink)}'
			. '.c{width:100%;max-width:460px;background:#fff;border-radius:18px;box-shadow:0 1px 0 rgba(0,0,0,.04),0 18px 40px -18px rgba(0,0,0,.25);overflow:hidden}'
			. '.top{padding:20px 26px;border-bottom:4px solid var(--g)}.top img{display:block;max-width:180px;height:auto}.top b{font-size:20px}'
			. '.road{height:6px;background:repeating-linear-gradient(90deg,var(--y) 0 26px,#2d3238 26px 46px)}.in{padding:26px}'
			. 'h1{margin:0 0 10px;font-size:24px;line-height:1.25;color:var(--g)}p{margin:0 0 14px;color:#2b3238}.em{font-size:42px;line-height:1}'
			. 'label{display:block;margin:6px 0 6px;font-weight:600}input[type=date]{width:100%;padding:12px 14px;font:inherit;border:1.5px solid #c8cdd2;border-radius:12px}input[type=date]:focus{outline:3px solid rgba(0,112,60,.25);border-color:var(--g)}'
			. 'button{width:100%;margin-top:16px;padding:14px;border:0;border-radius:999px;background:var(--g);color:#fff;font:700 16px/1 inherit;font-family:inherit;cursor:pointer}button:focus-visible{outline:3px solid var(--y);outline-offset:2px}'
			. '.sw{margin-top:18px;font-size:13px;color:var(--mut);text-align:center}.sw a{color:var(--g)}.f{padding:14px 26px;background:#fafaf7;font-size:13px;color:var(--mut)}.hp{position:absolute;left:-9999px}'
			. '</style></head><body><main class="c"><div class="top">' . ( $i['logo'] ? '<img src="' . esc_url( $i['logo'] ) . '" alt="' . esc_attr( $i['name'] ) . '">' : '<b>' . esc_html( $i['name'] ) . '</b>' ) . '</div><div class="road"></div><div class="in">'
			. $inner . '</div><div class="f">' . esc_html( $i['name'] ) . ' · ' . esc_html( $i['street'] . ', ' . $i['city'] ) . ' · ' . esc_html( $i['phones'][0] ) . '</div></main></body></html>';
		exit;
	}

	protected static function page_unsub() {
		$k = self::check( sanitize_text_field( wp_unslash( $_GET['sdsc_unsub'] ) ), 'unsub' ); // phpcs:ignore
		$c = $k ? self::contact( $k ) : null;
		$l = $c ? $c['lang'] : 'en';
		$es = 'es' === $l;
		if ( ! $c ) { status_header( 404 ); self::shell( 'Link not valid', '<h1>' . ( $es ? 'Enlace no válido' : 'This link is not valid' ) . '</h1><p>' . ( $es ? 'Llámenos y con gusto le ayudamos.' : 'Please call us and we will be glad to help.' ) . '</p>', $l ); }
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore -- also the one-click request from Gmail / Yahoo
			self::save_pref( $k, array( 'opt_email' => 1 ) );
			self::shell( 'Unsubscribed', '<div class="em">👋</div><h1>' . ( $es ? 'Listo, ya no recibirá estos correos' : 'You’re unsubscribed' ) . '</h1><p>' . ( $es ? 'No le enviaremos más correos de promociones ni felicitaciones a ' : 'We won’t send any more offers or greetings to ' ) . '<b>' . esc_html( $c['email'] ) . '</b>.' . ( $es ? ' Los mensajes sobre sus clases y pedidos seguirán llegando.' : ' Messages about your own classes and orders still arrive.' ) . '</p>', $l );
		}
		self::shell( 'Unsubscribe', '<h1>' . ( $es ? '¿Cancelar la suscripción?' : 'Unsubscribe?' ) . '</h1><p>' . ( $es ? 'Dejaremos de enviar promociones y felicitaciones a ' : 'We will stop sending offers and greetings to ' ) . '<b>' . esc_html( $c['email'] ) . '</b>.</p><form method="post"><button>' . ( $es ? 'Sí, cancelar suscripción' : 'Yes, unsubscribe me' ) . '</button></form>', $l );
	}

	protected static function page_lic() {
		$k  = self::check( sanitize_text_field( wp_unslash( $_GET['sdsc_lic'] ) ), 'lic' ); // phpcs:ignore
		$c  = $k ? self::contact( $k ) : null;
		$l  = isset( $_GET['l'] ) ? ( 'es' === $_GET['l'] ? 'es' : 'en' ) : ( $c ? $c['lang'] : 'en' ); // phpcs:ignore
		$es = 'es' === $l;
		$sw = '<p class="sw"><a href="' . esc_url( add_query_arg( 'l', $es ? 'en' : 'es' ) ) . '">' . ( $es ? 'English' : 'Español' ) . '</a></p>';
		if ( ! $c ) { status_header( 404 ); self::shell( 'Link not valid', '<h1>' . ( $es ? 'Enlace no válido' : 'This link is not valid' ) . '</h1><p>' . ( $es ? 'Llámenos y con gusto le ayudamos.' : 'Please call us and we will be glad to help.' ) . '</p>', $l ); }
		$err = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore
			$d = sanitize_text_field( wp_unslash( $_POST['d'] ?? '' ) ); // phpcs:ignore
			if ( empty( $_POST['website'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && $d <= current_time( 'Y-m-d' ) && $d >= '1970-01-01' && ( ! $c['dob'] || $d > $c['dob'] ) ) { // phpcs:ignore
				self::save_pref( $k, array( 'license_date' => $d, 'license_src' => 'student', 'lang' => $l ) );
				self::shell( 'Thank you', '<div class="em">🎉</div><h1>' . ( $es ? '¡Felicidades, ' : 'Congratulations, ' ) . esc_html( $c['first'] ) . '!</h1><p>' . ( $es ? 'Gracias por contarnos. Celebraremos este día con usted cada año.' : 'Thank you for letting us know. We will celebrate this day with you every year.' ) . '</p><p>' . ( $es ? 'Maneje con cuidado. — Sam’s Driving School' : 'Drive safe! — Sam’s Driving School' ) . '</p>', $l );
			}
			$err = '<p style="color:#b32d2e">' . ( $es ? 'Elija una fecha válida.' : 'Please choose a valid date.' ) . '</p>';
		}
		self::shell( $es ? 'Fecha de su licencia' : 'Your license date',
			'<div class="em">🚗</div><h1>' . ( $es ? '¡Hola, ' : 'Hi ' ) . esc_html( $c['first'] ) . ( $es ? '! ¿Cuándo obtuvo su licencia?' : '! When did you get your license?' ) . '</h1>'
			. '<p>' . ( $es ? 'Indíquenos el día en que recibió su licencia de conducir y lo celebraremos con usted cada año.' : 'Tell us the day you received your driver’s license and we will celebrate it with you every year.' ) . '</p>' . $err
			. '<form method="post"><label for="d">' . ( $es ? 'Fecha de la licencia' : 'License date' ) . '</label><input type="date" id="d" name="d" required min="1970-01-01" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '" value="' . esc_attr( $c['lic'] ) . '">'
			. '<input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"><button>' . ( $es ? 'Guardar' : 'Save' ) . '</button></form>' . $sw, $l );
	}
}
