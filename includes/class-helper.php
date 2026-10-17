<?php
/**
 * SDSC_Helper — the school's built-in help assistant. No outside service, no cost.
 *
 * It answers only from written knowledge: the school's common questions (includes/helper-data.php, in English,
 * Spanish and Persian), the policy page, the guided tours and answers the office adds. It never reads a student's
 * record, payment, grade or password. The office decides, section by section, what it may answer for students and
 * visitors; a switched-off section (or a question it cannot answer) gets the office's hand-off message instead.
 *
 * @package SamsClassManager
 */
defined( 'ABSPATH' ) || exit;

class SDSC_Helper {
	const CUSTOM = 'sdsc_helper_custom';
	const MISS   = 'sdsc_helper_unanswered';
	const CFG    = 'sdsc_helper_cfg';
	const OFF    = 'sdsc_helper_off';
	const EDIT   = 'sdsc_helper_edit';
	const MAIL   = 'info@samsdrivingschool.org';
	const LANGS  = array( 'en' => 'English', 'es' => 'Español', 'fa' => 'فارسی' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'wp_footer', array( __CLASS__, 'widget' ), 50 );
		add_action( 'admin_footer', array( __CLASS__, 'widget' ), 50 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 43 );
		add_action( 'admin_post_sdsc_helper', array( __CLASS__, 'save' ) );
	}

	/* ------------------------------------------------------------ settings */

	public static function cfg() {
		$d = array(
			'call' => '(301) 726-3030',
			'text' => '(202) 600-0889',
			'msg'  => array(
				'en' => 'I can’t help with that here. Please call the office at {call} or send a text message to {text}.',
				'es' => 'No puedo ayudarle con eso aquí. Por favor llame a la oficina al {call} o envíe un mensaje de texto al {text}.',
				'fa' => 'در اینجا نمی‌توانم به این سؤال پاسخ بدهم. لطفاً با دفتر به شمارهٔ {call} تماس بگیرید یا به شمارهٔ {text} پیامک بفرستید.',
			),
		);
		$c = (array) get_option( self::CFG, array() );
		foreach ( array( 'call', 'text' ) as $k ) { if ( isset( $c[ $k ] ) && '' !== trim( (string) $c[ $k ] ) ) { $d[ $k ] = trim( (string) $c[ $k ] ); } }
		if ( isset( $c['text'] ) && 'cleared' !== $c['text'] ) { $d['text'] = trim( (string) $c['text'] ); }
		foreach ( array_keys( self::LANGS ) as $l ) { if ( ! empty( $c['msg'][ $l ] ) ) { $d['msg'][ $l ] = (string) $c['msg'][ $l ]; } }
		return $d;
	}

	protected static function digits( $n ) { $d = preg_replace( '/\D+/', '', (string) $n ); return strlen( $d ) === 10 ? '1' . $d : $d; }

	protected static function handoff( $lang, $q = '' ) {
		$c    = self::cfg();
		$text = '' !== $c['text'] ? $c['text'] : $c['call'];
		$call = '<a href="tel:+' . esc_attr( self::digits( $c['call'] ) ) . '" dir="ltr">' . esc_html( $c['call'] ) . '</a>';
		$body = '' !== $q ? '?&body=' . rawurlencode( mb_substr( $q, 0, 300 ) ) : '';
		$sms  = '<a href="sms:+' . esc_attr( self::digits( $text ) ) . esc_attr( $body ) . '" dir="ltr">' . esc_html( $text ) . '</a>';
		$m    = isset( $c['msg'][ $lang ] ) ? $c['msg'][ $lang ] : $c['msg']['en'];
		return str_replace( array( '{call}', '{text}' ), array( $call, $sms ), wp_kses_post( $m ) );
	}

	/* ------------------------------------------------------------ who is asking */

	/** 0 visitor, 1 student, 2 instructor, 3 staff */
	public static function level() {
		if ( ! is_user_logged_in() ) { return 0; }
		if ( class_exists( 'SDSC_Workspace' ) && SDSC_Workspace::is_staff() ) { return 3; }
		if ( current_user_can( 'manage_options' ) ) { return 3; }
		$u = wp_get_current_user();
		if ( array_intersect( (array) $u->roles, array( 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' ) ) ) { return 2; }
		return 1;
	}

	/* ------------------------------------------------------------ knowledge */

	protected static function data() {
		static $d = null;
		if ( null === $d ) { $d = require __DIR__ . '/helper-data.php'; }
		return $d;
	}

	public static function sections() {
		$s = self::data()['sections'];
		$s['policy']          = 'Policies (read from the website policy page — English only)';
		$s['tours_student']   = 'Student page tours (English only)';
		$s['tours_instructor'] = 'Instructor app tour (English only)';
		$s['tours_staff']     = 'Staff screen tours (English only)';
		$s['custom']          = 'Answers added by the office';
		return $s;
	}

	protected static function off() { return array_flip( (array) get_option( self::OFF, array() ) ); }

	/** Pages of the student dashboard: key => [ title en/es/fa, one line en/es/fa ]. */
	protected static function pages_def() {
		return array(
			'index'           => array( array( 'Dashboard', 'Panel', 'داشبورد' ), array( 'Your home page after you log in.', 'Su página de inicio al entrar.', 'صفحهٔ اصلی شما بعد از ورود.' ) ),
			'enrolled-courses' => array( array( 'My online course', 'Mi curso en línea', 'دورهٔ آنلاین من' ), array( 'Your online course and quizzes.', 'Su curso en línea y los exámenes.', 'دورهٔ آنلاین و کوییزهای شما.' ) ),
			'sdsc-lessons'    => array( array( 'My driving lessons', 'Mis clases prácticas', 'درس‌های رانندگی من' ), array( 'Your lessons, scorecards and calendar.', 'Sus lecciones, tarjetas de calificaciones y calendario.', 'درس‌ها، کارنامه‌ها و تقویم شما.' ) ),
			'sdsc-messages'   => array( array( 'Messages', 'Mensajes', 'پیام‌ها' ), array( 'Messages with the school.', 'Mensajes con la escuela.', 'پیام‌ها با مدرسه.' ) ),
			'sdsc-documents'  => array( array( 'My documents', 'Mis documentos', 'مدارک من' ), array( 'Your permit and other documents.', 'Su permiso y otros documentos.', 'پرمیت و مدارک دیگر شما.' ) ),
			'sdsc-payments'   => array( array( 'My payments', 'Mis pagos', 'پرداخت‌های من' ), array( 'Your payments and receipts.', 'Sus pagos y recibos.', 'پرداخت‌ها و رسیدهای شما.' ) ),
		);
	}

	/** Which page an answer sends the asker to (student dashboard tabs; login / sign-up for visitors). */
	protected static function links_map() {
		return array(
			'login' => 'login', 'locked' => 'login', 'whichemail' => 'login', 'oldstudent' => 'login', 'account' => 'login', 'reset' => 'lost',
			'register' => 'register',
			'quiz' => 'enrolled-courses', 'calendar' => 'sdsc-lessons', 'scorecard' => 'sdsc-lessons', 'cancel' => 'sdsc-lessons', 'book' => 'sdsc-lessons', 'absence' => 'sdsc-lessons',
			'receipt' => 'sdsc-payments', 'latefee' => 'sdsc-payments',
		);
	}

	protected static function page_url( $key ) {
		switch ( $key ) {
			case 'login':    return wp_login_url();
			case 'lost':     return wp_lostpassword_url();
			case 'register': return home_url( '/student-registration/' );
			case 'index':    return function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' );
		}
		return function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( $key ) : home_url( '/dashboard/' . $key . '/' );
	}

	/** [ url, text ] for the button at the end of an answer, or null. Only visitors and students get one. */
	protected static function link_for( $id, $level, $lang ) {
		$map = self::links_map();
		if ( $level > 1 || ! isset( $map[ $id ] ) ) { return null; }
		$li  = self::langidx( $lang );
		$key = $map[ $id ];
		$open = array( 'Open', 'Abrir', 'باز کردن' );
		$fix  = array( 'login' => array( 'Log in', 'Iniciar sesión', 'ورود به سایت' ), 'lost' => array( 'Lost your password?', '¿Olvidó su contraseña?', 'فراموشی رمز' ), 'register' => array( 'See class dates and register', 'Ver fechas y registrarse', 'دیدن تاریخ کلاس‌ها و ثبت‌نام' ) );
		if ( isset( $fix[ $key ] ) ) { return array( self::page_url( $key ), $fix[ $key ][ $li ] ); }
		$def = self::pages_def();
		if ( ! isset( $def[ $key ] ) ) { return null; }
		if ( ! is_user_logged_in() ) { return array( self::page_url( 'login' ), $fix['login'][ $li ] ); }
		return array( self::page_url( $key ), $open[ $li ] . ': ' . $def[ $key ][0][ $li ] );
	}

	/** The signed-in student's own dashboard sections (what their dashboard really lists). */
	public static function my_pages( $lang = 'en' ) {
		if ( 1 !== self::level() ) { return array(); }
		$li  = self::langidx( $lang );
		$def = self::pages_def();
		$base = ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'default_menus' ) ) ? (array) tutor_utils()->default_menus() : array();
		$items = (array) apply_filters( 'tutor_dashboard/nav_items', $base );
		$out = array();
		foreach ( $def as $key => $d ) {
			if ( 'index' !== $key && 'enrolled-courses' !== $key && ! isset( $items[ $key ] ) ) { continue; }
			$cap = is_array( $items[ $key ] ?? null ) ? ( $items[ $key ]['auth_cap'] ?? '' ) : '';
			if ( $cap && ! current_user_can( $cap ) ) { continue; }
			$out[] = array( 'u' => self::page_url( $key ), 't' => $d[0][ $li ], 'd' => $d[1][ $li ] );
		}
		return $out;
	}

	protected static function fill( $t ) {
		$c = self::cfg();
		return str_replace( array( '{P}', '{M}', '{S}' ), array( $c['call'], self::MAIL, '<a href="' . esc_url( home_url( '/student-registration/' ) ) . '">' . esc_html( home_url( '/student-registration/' ) ) . '</a>' ), $t );
	}

	protected static function langidx( $lang ) { return array( 'en' => 0, 'es' => 1, 'fa' => 2 )[ $lang ] ?? 0; }

	/** Every entry the asker may see (switched-off ones are flagged, not removed, so the bot can hand off). */
	protected static function entries( $level, $lang ) {
		$off  = self::off();
		$out  = array();
		$edit = (array) get_option( self::EDIT, array() );
		$li   = self::langidx( $lang );
		foreach ( self::data()['entries'] as $id => $e ) {
			if ( $e[1] > $level ) { continue; }
			$k = $e[2][ $li ]; $a = $e[3][ $li ];
			if ( ! empty( $edit[ $id ][ $lang ]['k'] ) ) { $k = $edit[ $id ][ $lang ]['k']; }
			if ( ! empty( $edit[ $id ][ $lang ]['a'] ) ) { $a = $edit[ $id ][ $lang ]['a']; }
			$a = self::fill( $a );
			$q = ! empty( $edit[ $id ][ $lang ]['q'] ) ? $edit[ $id ][ $lang ]['q'] : ( self::data()['labels'][ $id ][ $li ] ?? self::first_words( $k ) );
			$out[] = array( 'id' => $id, 'sec' => $e[0], 'off' => isset( $off[ $e[0] ] ), 'k' => $k, 't' => $q, 'q' => $q, 'a' => $a, 'body' => wp_strip_all_tags( $a ), 'link' => '' );
			if ( $lk = self::link_for( $id, $level, $lang ) ) { $out[ count( $out ) - 1 ]['link'] = $lk[0]; $out[ count( $out ) - 1 ]['linkt'] = $lk[1]; }
		}
		foreach ( (array) get_option( self::CUSTOM, array() ) as $c ) {
			if ( (int) ( $c['aud'] ?? 0 ) > $level || empty( $c['k'][ $lang ] ) || empty( $c['a'][ $lang ] ) ) { continue; }
			$sec = $c['sec'] ?? 'custom';
			$out[] = array( 'id' => 'c' . $c['id'], 'sec' => $sec, 'off' => isset( $off[ $sec ] ), 'k' => $c['k'][ $lang ], 't' => $q = ( $c['q'][ $lang ] ?? self::first_words( $c['k'][ $lang ] ) ), 'q' => $q, 'a' => wp_kses_post( $c['a'][ $lang ] ), 'body' => wp_strip_all_tags( $c['a'][ $lang ] ), 'link' => '' );
		}
		if ( 'en' !== $lang ) { return $out; }
		foreach ( self::policy_sections() as $i => $ps ) {
			$out[] = array( 'id' => 'p' . $i, 'sec' => 'policy', 'off' => isset( $off['policy'] ), 'k' => $ps['t'] . ' policy', 't' => $ps['t'], 'q' => $ps['t'], 'a' => $ps['a'], 'body' => wp_strip_all_tags( $ps['a'] ), 'link' => $ps['link'], 'linkt' => 'Read the full policy' );
		}
		if ( class_exists( 'SDSC_Tours' ) ) {
			if ( $level >= 1 ) {
				foreach ( SDSC_Tours::front_tours() as $key => $t ) {
					$ins = 0 === strpos( $key, 'instructor' );
					if ( ( $ins ? 2 : 1 ) > $level ) { continue; }
					$sec = $ins ? 'tours_instructor' : 'tours_student';
					foreach ( (array) $t[2] as $n => $st ) {
						$txt = wp_strip_all_tags( $st['content'] );
						if ( strlen( $txt ) < 40 ) { continue; }
						$out[] = array( 'id' => 'ft' . $key . $n, 'sec' => $sec, 'off' => isset( $off[ $sec ] ), 'k' => $t[0] . ' ' . $st['title'], 't' => $st['title'], 'q' => $st['title'], 'a' => $st['content'], 'body' => $txt, 'link' => '' );
					}
				}
			}
			if ( $level >= 3 ) {
				foreach ( SDSC_Tours::tours() as $key => $t ) {
					$url = SDSC_Tours::tour_url( $key );
					foreach ( (array) $t[5] as $n => $st ) {
						$txt = wp_strip_all_tags( $st['content'] );
						if ( strlen( $txt ) < 40 ) { continue; }
						$out[] = array( 'id' => 'st' . $key . $n, 'sec' => 'tours_staff', 'off' => false, 'k' => $t[1] . ' ' . $st['title'], 't' => $st['title'], 'q' => $st['title'], 'a' => $st['content'], 'body' => $txt, 'link' => $url, 'linkt' => 'Open: ' . $t[1] );
					}
				}
			}
		}
		return $out;
	}

	protected static function policy_sections() {
		static $c = null;
		if ( null !== $c ) { return $c; }
		$c = array();
		if ( ! class_exists( 'SDSC_Policies' ) ) { return $c; }
		$html = (string) SDSC_Policies::html();
		if ( '' === $html ) { return $c; }
		$pg    = SDSC_Policies::page();
		$link  = $pg ? get_permalink( $pg ) : home_url( '/policy/' );
		$parts = preg_split( '#(<h[34][^>]*>.*?</h[34]>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$title = '';
		foreach ( $parts as $x ) {
			if ( preg_match( '#^<h[34]#i', $x ) ) { $title = trim( wp_strip_all_tags( $x ) ); continue; }
			$txt = trim( wp_strip_all_tags( $x ) );
			if ( '' === $title || strlen( $txt ) < 30 ) { continue; }
			$short = strlen( $txt ) > 650 ? rtrim( mb_substr( $txt, 0, 650 ) ) . '…' : $txt;
			$c[]   = array( 't' => $title, 'a' => '<p><b>' . esc_html( $title ) . '</b></p><p>' . esc_html( $short ) . '</p>', 'link' => $link );
		}
		return $c;
	}

	protected static function first_words( $s ) { return implode( ' ', array_slice( explode( ' ', $s ), 0, 6 ) ); }

	/* ------------------------------------------------------------ matching */

	protected static function stopwords( $lang ) {
		$w = array(
			'en' => 'a an the is are am was be to of in on at for and or i my me we you your it its do does did can could how what when where why who which this that with not no cant cannot dont doesnt have has had will would should there about please help need want get got if but so im ive',
			'es' => 'el la los las un una unos unas de del y o a al en es son soy por para con mi me yo tu su sus que como cuando donde cual puedo puede quiero necesito hay no se lo le les mas muy pero si ya',
			'fa' => 'و در به از که را این آن من با برای است می یا هم شد شده بود کرد کنم کنید باید چه چرا کی کجا چطور آیا یک ما شما او',
		);
		return array_flip( explode( ' ', $w[ $lang ] ?? $w['en'] ) );
	}

	protected static function groups() {
		return array(
			array( 'login', 'log', 'signin', 'access', 'enter', 'verification', 'verify', 'code' ),
			array( 'email', 'mail', 'inbox' ),
			array( 'password', 'passcode', 'pass' ),
			array( 'cancel', 'reschedule', 'change', 'move', 'postpone' ),
			array( 'lesson', 'drive', 'driving', 'btw', 'wheel' ),
			array( 'permit', 'learner', 'learners' ),
			array( 'pay', 'payment', 'paid' ),
			array( 'cash', 'check', 'cheque', 'zelle' ),
			array( 'fee', 'price', 'cost' ),
			array( 'absent', 'absence', 'miss', 'missed' ),
			array( 'phone', 'call', 'text', 'contact', 'number' ),
		);
	}

	protected static function tokens( $s, $lang = 'en' ) {
		static $stop = array(), $map = null;
		if ( ! isset( $stop[ $lang ] ) ) { $stop[ $lang ] = self::stopwords( $lang ); }
		if ( null === $map ) { $map = array(); foreach ( self::groups() as $g ) { foreach ( $g as $w ) { $map[ $w ] = $g[0]; } } }
		$s = wp_strip_all_tags( (string) $s );
		if ( 'en' === $lang ) {
			$s = strtolower( str_replace( array( '’', "'" ), '', $s ) );
			$s = preg_replace( '/\bsign[\s-]+in\b/', 'signin', $s );
			$s = preg_replace( '/\blog[\s-]+in\b/', 'login', $s );
		} else {
			$s = mb_strtolower( str_replace( array( '’', "'", "\xE2\x80\x8C" ), array( '', '', ' ' ), $s ) );
			if ( 'es' === $lang ) { $s = remove_accents( $s ); }
			if ( 'fa' === $lang ) { $s = str_replace( array( 'ي', 'ك', 'ة' ), array( 'ی', 'ک', 'ه' ), $s ); $s = preg_replace( '/[\x{064B}-\x{065F}]/u', '', $s ); }
		}
		$out = array();
		foreach ( preg_split( '/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( isset( $stop[ $lang ][ $w ] ) || mb_strlen( $w ) < 2 ) { continue; }
			if ( 'en' === $lang ) {
				if ( strlen( $w ) > 4 ) { $w = preg_replace( '/(ing|ed|es|s)$/', '', $w ); }
				$w = $map[ $w ] ?? $w;
			} elseif ( 'es' === $lang && mb_strlen( $w ) > 4 ) {
				$w = preg_replace( '/(es|s)$/', '', $w );
			}
			$out[] = $w;
		}
		return array_values( array_unique( $out ) );
	}

	protected static function norm( $s ) {
		$s = mb_strtolower( remove_accents( wp_strip_all_tags( (string) $s ) ) );
		return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s ) );
	}

	/** [ [score, entry], … ] best first. */
	public static function search( $q, $level, $lang = 'en' ) {
		$qt = self::tokens( $q, $lang );
		if ( ! $qt ) { return array(); }
		$es = self::entries( $level, $lang );
		$df = $kt = $bt = array();
		foreach ( $es as $i => $e ) {
			$kt[ $i ] = array_flip( self::tokens( $e['k'], $lang ) );
			$bt[ $i ] = array_flip( self::tokens( $e['body'], $lang ) );
			foreach ( array_keys( $kt[ $i ] + $bt[ $i ] ) as $w ) { $df[ $w ] = ( $df[ $w ] ?? 0 ) + 1; }
		}
		$N   = max( 1, count( $es ) );
		$idf = function ( $w ) use ( $df, $N ) { return log( 1 + $N / ( 1 + ( $df[ $w ] ?? 0 ) ) ); };
		$tot = 0;
		foreach ( $qt as $w ) { if ( isset( $df[ $w ] ) ) { $tot += $idf( $w ); } }
		$res = array();
		$nq  = self::norm( $q );
		foreach ( $es as $i => $e ) {
			$sc = 0; $kh = 0;
			foreach ( $qt as $w ) {
				if ( isset( $kt[ $i ][ $w ] ) ) { $sc += $idf( $w ); $kh++; }
				elseif ( isset( $bt[ $i ][ $w ] ) ) { $sc += 0.4 * $idf( $w ); }
			}
			if ( $kh && $tot > 0 ) {
				$score = $sc / $tot;
				if ( $nq && false !== strpos( ' ' . self::norm( $e['k'] ) . ' ', ' ' . $nq . ' ' ) ) { $score += 0.3; } // the exact words appear in the entry's keywords
				$res[] = array( $score, $e );
			}
		}
		usort( $res, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		return $res;
	}

	/** Questions to offer for a typed question: only from the prepared list, never a made-up answer. */
	public static function suggest( $q, $level, $lang = 'en' ) {
		$lang = isset( self::LANGS[ $lang ] ) ? $lang : 'en';
		$out  = array();
		foreach ( self::search( $q, $level, $lang ) as $r ) {
			if ( $r[0] < 0.35 || count( $out ) >= 4 ) { break; }
			if ( $r[1]['off'] && $level < 2 ) { if ( ! $out ) { return array( 'found' => false, 'held' => true, 'html' => self::handoff( $lang, $q ) ); } continue; }
			$out[] = array( 'id' => $r[1]['id'], 'q' => $r[1]['q'] );
		}
		if ( ! $out ) { return array( 'found' => false, 'html' => self::handoff( $lang, $q ) ); }
		return array( 'found' => true, 'suggest' => $out );
	}

	/** The fixed answer of one prepared question. */
	public static function answer_id( $id, $level, $lang = 'en' ) {
		$lang = isset( self::LANGS[ $lang ] ) ? $lang : 'en';
		foreach ( self::entries( $level, $lang ) as $e ) {
			if ( (string) $e['id'] !== (string) $id ) { continue; }
			if ( $e['off'] && $level < 2 ) { break; }
			$html = $e['a'];
			if ( ! empty( $e['link'] ) ) { $html .= '<p><a href="' . esc_url( $e['link'] ) . '" style="display:inline-block;background:#0b5d2e;color:#fff;padding:6px 12px;border-radius:8px;text-decoration:none;font-weight:700">' . esc_html( $e['linkt'] ) . ' →</a></p>'; }
			return array( 'found' => true, 'html' => $html, 'q' => $e['q'] );
		}
		return array( 'found' => false, 'html' => self::handoff( $lang ) );
	}

	/** The list of prepared questions, by section (switched-off sections are not shown to visitors and students). */
	public static function questions( $level, $lang = 'en' ) {
		$lang = isset( self::LANGS[ $lang ] ) ? $lang : 'en';
		$secs = self::sections();
		$sn   = self::data()['section_names'];
		$li   = self::langidx( $lang );
		$by   = array();
		foreach ( self::entries( $level, $lang ) as $e ) {
			if ( 0 === strpos( $e['sec'], 'tours_' ) || ( $e['off'] && $level < 2 ) ) { continue; }
			$by[ $e['sec'] ][] = array( 'id' => $e['id'], 'q' => $e['q'] );
		}
		$out = array();
		foreach ( $secs as $k => $name ) {
			if ( empty( $by[ $k ] ) ) { continue; }
			$out[] = array( 'title' => isset( $sn[ $k ] ) ? $sn[ $k ][ $li ] : $name, 'items' => $by[ $k ] );
		}
		return $out;
	}

	/* ------------------------------------------------------------ REST */

	public static function rest() {
		foreach ( array( '' => 'rest_ask', '/menu' => 'rest_menu', '/answer' => 'rest_answer' ) as $path => $cb ) {
			register_rest_route( 'sdsc/v1', '/helper' . $path, array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, $cb ) ) );
		}
	}

	protected static function rl( $lang ) {
		$k = 'sdsc_help_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
		$n = (int) get_transient( $k );
		if ( $n >= 60 ) { return new WP_REST_Response( array( 'found' => false, 'html' => self::handoff( $lang ) ), 429 ); }
		set_transient( $k, $n + 1, 60 );
		return null;
	}

	protected static function lang_of( WP_REST_Request $r, $q = '' ) {
		$lang = (string) $r->get_param( 'lang' );
		$lang = isset( self::LANGS[ $lang ] ) ? $lang : 'en';
		if ( 'en' === $lang && preg_match( '/[\x{0600}-\x{06FF}]/u', $q ) ) { $lang = 'fa'; }
		return $lang;
	}

	public static function rest_menu( WP_REST_Request $r ) {
		$lang = self::lang_of( $r );
		return array( 'menu' => self::questions( self::level(), $lang ), 'pages' => self::my_pages( $lang ), 'lang' => $lang );
	}

	public static function rest_answer( WP_REST_Request $r ) {
		$lang = self::lang_of( $r );
		if ( $x = self::rl( $lang ) ) { return $x; }
		$a = self::answer_id( sanitize_text_field( (string) $r->get_param( 'id' ) ), self::level(), $lang );
		$a['lang'] = $lang;
		return $a;
	}

	public static function rest_ask( WP_REST_Request $r ) {
		$q    = trim( mb_substr( wp_strip_all_tags( (string) $r->get_param( 'q' ) ), 0, 300 ) );
		$lang = self::lang_of( $r, $q );
		if ( $x = self::rl( $lang ) ) { return $x; }
		if ( $r->get_param( 'contact' ) || mb_strlen( $q ) < 3 ) { return array( 'found' => false, 'html' => self::handoff( $lang, $q ), 'lang' => $lang ); }
		$a = self::suggest( $q, self::level(), $lang );
		if ( ! $a['found'] ) { self::miss( $q, $lang, ! empty( $a['held'] ) ); }
		$a['lang'] = $lang;
		return $a;
	}

	protected static function miss( $q, $lang, $held ) {
		$q = preg_replace( '/[\w.+-]+@[\w-]+\.[\w.]+/u', '[email]', $q );
		$q = preg_replace( '/\+?\d[\d\s().-]{6,}\d/', '[number]', $q );
		$l = (array) get_option( self::MISS, array() );
		array_unshift( $l, array( 't' => current_time( 'mysql' ), 'q' => $q, 'lv' => self::level(), 'lang' => $lang, 'held' => $held ? 1 : 0 ) );
		update_option( self::MISS, array_slice( $l, 0, 200 ), false );
	}

	/* ------------------------------------------------------------ widget */

	public static function widget() {
		if ( is_admin() && ! self::level() ) { return; }
		if ( is_customize_preview() ) { return; }
		$base = rest_url( 'sdsc/v1/helper' );
		$t = array(
			'en' => array( 'btn' => 'Need help?', 'title' => 'Sam’s Driving School help', 'intro' => 'Choose a question below. I answer only from the school’s own information and never see your record or payments. Not in the list? Contact the office.', 'ph' => 'Search the questions…', 'ask' => 'Search', 'c1' => 'I want to register', 'c2' => 'I registered but can’t log in', 'none' => 'My question isn’t here', 'back' => '← All questions', 'did' => 'Did you mean:', 'err' => 'Something went wrong. Please call', 'mine' => 'My pages' ),
			'es' => array( 'btn' => '¿Necesita ayuda?', 'title' => 'Ayuda de Sam’s Driving School', 'intro' => 'Elija una pregunta de la lista. Respondo solo con la información de la escuela y nunca veo su registro ni sus pagos. ¿No está en la lista? Contacte a la oficina.', 'ph' => 'Buscar en las preguntas…', 'ask' => 'Buscar', 'c1' => 'Quiero registrarme', 'c2' => 'Me registré pero no puedo entrar', 'none' => 'Mi pregunta no está aquí', 'back' => '← Todas las preguntas', 'did' => '¿Quiso decir?', 'err' => 'Algo salió mal. Por favor llame al', 'mine' => 'Mis páginas' ),
			'fa' => array( 'btn' => 'کمک می‌خواهید؟', 'title' => 'راهنمای Sam’s Driving School', 'intro' => 'یکی از سؤال‌های زیر را انتخاب کنید. من فقط از اطلاعات خود مدرسه جواب می‌دهم و هرگز پرونده یا پرداخت‌های شما را نمی‌بینم. سؤالتان در فهرست نیست؟ با دفتر تماس بگیرید.', 'ph' => 'جستجو در سؤال‌ها…', 'ask' => 'جستجو', 'c1' => 'می‌خواهم ثبت‌نام کنم', 'c2' => 'ثبت‌نام کردم ولی نمی‌توانم وارد شوم', 'none' => 'سؤال من اینجا نیست', 'back' => '← همهٔ سؤال‌ها', 'did' => 'منظورتان این بود:', 'err' => 'مشکلی پیش آمد. لطفاً تماس بگیرید', 'mine' => 'صفحه‌های من' ),
		);
		$call = self::cfg()['call'];
		$chip = 'border:1px solid #0b5d2e;background:#fff;color:#0b5d2e;border-radius:14px;padding:5px 10px;cursor:pointer;font-size:13px;text-align:start';
		?>
		<div id="sdsc-help" style="position:fixed;left:16px;bottom:16px;z-index:99990;font:14px/1.45 system-ui,-apple-system,Segoe UI,Tahoma,sans-serif">
			<button type="button" id="sdsc-help-btn" aria-expanded="false" style="border:0;border-radius:24px;background:#0b5d2e;color:#fff;padding:10px 16px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)">Need help?</button>
			<div id="sdsc-help-box" hidden style="position:absolute;left:0;bottom:48px;width:min(350px,calc(100vw - 32px));background:#fff;color:#15191d;border:1px solid #d5dbe0;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.25);overflow:hidden">
				<div style="background:#0b5d2e;color:#fff;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;gap:8px"><b id="sdsc-help-title">Sam’s Driving School help</b><span id="sdsc-help-langs"><?php foreach ( self::LANGS as $k => $n ) { echo '<button type="button" data-l="' . esc_attr( $k ) . '" style="border:1px solid rgba(255,255,255,.6);background:transparent;color:#fff;border-radius:10px;padding:1px 7px;margin-left:3px;cursor:pointer;font-size:12px">' . esc_html( strtoupper( $k ) ) . '</button>'; } ?></span></div>
				<div id="sdsc-help-log" style="max-height:340px;overflow:auto;padding:10px 12px"></div>
				<form id="sdsc-help-form" style="display:flex;gap:6px;padding:8px;border-top:1px solid #e3e7ea"><input id="sdsc-help-q" type="text" maxlength="300" aria-label="Search" style="flex:1;border:1px solid #cfd6db;border-radius:8px;padding:8px"><button id="sdsc-help-go" style="border:0;border-radius:8px;background:#0b5d2e;color:#fff;padding:8px 12px;font-weight:700;cursor:pointer"></button></form>
			</div>
		</div>
		<script>
		(function(){
			var T=<?php echo wp_json_encode( $t ); ?>,CALL=<?php echo wp_json_encode( $call ); ?>,BASE=<?php echo wp_json_encode( $base ); ?>,IN=<?php echo is_user_logged_in() ? 'true' : 'false'; ?>,CHIP=<?php echo wp_json_encode( $chip ); ?>;
			var b=document.getElementById('sdsc-help-btn'),x=document.getElementById('sdsc-help-box'),f=document.getElementById('sdsc-help-form'),q=document.getElementById('sdsc-help-q'),l=document.getElementById('sdsc-help-log');
			if(!b){return;}
			var lang='en',menu={},pages={};try{lang=localStorage.getItem('sdsc_help_lang')||'';}catch(e){}
			if(!T[lang]){var n=(navigator.language||'en').slice(0,2);lang=T[n]?n:'en';}
			function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
			function post(p,d){d.lang=lang;return fetch(BASE+p,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d),credentials:'same-origin'}).then(function(r){return r.json();});}
			function add(h,me){var d=document.createElement('div');d.style.cssText='margin:6px 0;padding:8px 10px;border-radius:10px;'+(me?'background:#eef5f0;':'background:#f6f7f8');d.innerHTML=h;l.appendChild(d);l.scrollTop=l.scrollHeight;return d;}
			function btn(txt,fn,st){var c=document.createElement('button');c.type='button';c.style.cssText=st||CHIP;c.textContent=txt;c.addEventListener('click',fn);return c;}
			function ask(id,qt){add(esc(qt),true);var w=add('…');post('/answer',{id:id}).then(function(r){w.innerHTML=r.html||'';w.appendChild(back());l.scrollTop=l.scrollHeight;}).catch(function(){w.textContent=T[lang].err+' '+CALL;});}
			function back(){var p=document.createElement('p');p.style.margin='8px 0 0';p.appendChild(btn(T[lang].back,show));return p;}
			function contact(qt){var w=add('…');post('',{contact:1,q:qt||''}).then(function(r){w.innerHTML=r.html;l.scrollTop=l.scrollHeight;});}
			function show(){var s=T[lang];x.dir=(lang==='fa')?'rtl':'ltr';l.innerHTML='';add(esc(s.intro));
				if(!IN){var cw=document.createElement('div');cw.style.cssText='display:flex;gap:6px;flex-wrap:wrap;margin:6px 0';cw.appendChild(btn(s.c1,function(){ask('register',s.c1);}));cw.appendChild(btn(s.c2,function(){ask('locked',s.c2);}));l.appendChild(cw);}
				var m=menu[lang];if(!m){post('/menu',{}).then(function(r){menu[lang]=r.menu||[];pages[lang]=r.pages||[];show();});return;}
					var pg=pages[lang]||[];if(IN&&pg.length){var pd=document.createElement('div');pd.style.cssText='margin:6px 0;border:1px solid #cfe3d6;background:#f3faf5;border-radius:8px;padding:6px 8px';var ph=document.createElement('b');ph.textContent=s.mine;pd.appendChild(ph);pg.forEach(function(p){var a=document.createElement('a');a.href=p.u;a.title=p.d;a.style.cssText='display:block;margin:4px 0;color:#0b5d2e;text-decoration:underline';a.textContent=p.t+' — '+p.d;pd.appendChild(a);});l.appendChild(pd);}
				m.forEach(function(sec){var d=document.createElement('details');d.style.cssText='margin:6px 0;border:1px solid #e3e7ea;border-radius:8px;padding:4px 8px';var sm=document.createElement('summary');sm.style.cssText='cursor:pointer;font-weight:700';sm.textContent=sec.title;d.appendChild(sm);
					sec.items.forEach(function(it){var p=document.createElement('div');p.style.margin='4px 0';p.appendChild(btn(it.q,function(){ask(it.id,it.q);},'border:0;background:none;color:#0b5d2e;text-decoration:underline;cursor:pointer;padding:2px 0;text-align:start;font-size:14px'));d.appendChild(p);});l.appendChild(d);});
				var nw=document.createElement('div');nw.style.margin='8px 0 0';nw.appendChild(btn(s.none,function(){contact('');}));l.appendChild(nw);}
			function paint(){var s=T[lang];x.dir=(lang==='fa')?'rtl':'ltr';b.textContent=s.btn;document.getElementById('sdsc-help-title').textContent=s.title;q.placeholder=s.ph;document.getElementById('sdsc-help-go').textContent=s.ask;
				Array.prototype.forEach.call(document.querySelectorAll('#sdsc-help-langs button'),function(c){c.style.background=c.getAttribute('data-l')===lang?'rgba(255,255,255,.3)':'transparent';});show();}
			paint();
			Array.prototype.forEach.call(document.querySelectorAll('#sdsc-help-langs button'),function(c){c.addEventListener('click',function(){lang=c.getAttribute('data-l');try{localStorage.setItem('sdsc_help_lang',lang);}catch(e){}paint();});});
			b.addEventListener('click',function(){x.hidden=!x.hidden;b.setAttribute('aria-expanded',String(!x.hidden));if(!x.hidden){q.focus();}});
			f.addEventListener('submit',function(e){e.preventDefault();var t=q.value.trim();if(t.length<2){return;}q.value='';add(esc(t),true);var w=add('…');
				post('',{q:t}).then(function(r){var s=T[r.lang||lang];if(r.found&&r.suggest){w.innerHTML=esc(s.did);var d=document.createElement('div');r.suggest.forEach(function(it){var p=document.createElement('div');p.style.margin='4px 0';p.appendChild(btn(it.q,function(){ask(it.id,it.q);}));d.appendChild(p);});w.appendChild(d);var nn=document.createElement('div');nn.style.marginTop='6px';nn.appendChild(btn(s.none,function(){contact(t);}));w.appendChild(nn);w.appendChild(back());}else{w.innerHTML=r.html||'';w.appendChild(back());}l.scrollTop=l.scrollHeight;}).catch(function(){w.textContent=T[lang].err+' '+CALL;});});
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------ office screen */

	public static function can() { return current_user_can( 'manage_options' ) || ( class_exists( 'SDSC_Workspace' ) && SDSC_Workspace::is_staff() ); }

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Help assistant', 'Help assistant', 'read', 'sdsc-helper', array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! self::can() ) { wp_die( 'Not allowed' ); }
		check_admin_referer( 'sdsc_helper' );
		$P = wp_unslash( $_POST ); // phpcs:ignore
		if ( ! empty( $P['clear'] ) ) { delete_option( self::MISS ); }
		if ( ! empty( $P['full'] ) ) {
			$cfg = array( 'call' => sanitize_text_field( $P['call'] ?? '' ), 'text' => sanitize_text_field( $P['text'] ?? '' ), 'msg' => array() );
			foreach ( array_keys( self::LANGS ) as $l ) { $cfg['msg'][ $l ] = wp_kses( (string) ( $P['msg'][ $l ] ?? '' ), array( 'b' => array(), 'a' => array( 'href' => array() ) ) ); }
			update_option( self::CFG, $cfg, false );
			$on  = array_map( 'sanitize_key', (array) ( $P['sec_on'] ?? array() ) );
			$off = array();
			foreach ( array_keys( self::sections() ) as $s ) { if ( ! in_array( $s, $on, true ) ) { $off[] = $s; } }
			update_option( self::OFF, $off, false );
			$edit = array();
			foreach ( (array) ( $P['edit'] ?? array() ) as $id => $ls ) {
				$id = sanitize_key( $id );
				if ( ! isset( self::data()['entries'][ $id ] ) ) { continue; }
				foreach ( array_keys( self::LANGS ) as $l ) {
					$li = self::langidx( $l ); $d = self::data()['entries'][ $id ];
					$a  = wp_kses_post( (string) ( $ls[ $l ]['a'] ?? '' ) ); $k = sanitize_text_field( (string) ( $ls[ $l ]['k'] ?? '' ) ); $ql = sanitize_text_field( (string) ( $ls[ $l ]['q'] ?? '' ) );
					if ( '' !== $ql && $ql !== ( self::data()['labels'][ $id ][ $li ] ?? '' ) ) { $edit[ $id ][ $l ]['q'] = $ql; }
					if ( '' !== $a && trim( $a ) !== trim( $d[3][ $li ] ) ) { $edit[ $id ][ $l ]['a'] = $a; }
					if ( '' !== $k && trim( $k ) !== trim( $d[2][ $li ] ) ) { $edit[ $id ][ $l ]['k'] = $k; }
				}
			}
			update_option( self::EDIT, $edit, false );
			$cust = array_values( array_filter( (array) get_option( self::CUSTOM, array() ), function ( $c ) use ( $P ) { return ! in_array( (string) $c['id'], array_map( 'strval', (array) ( $P['del'] ?? array() ) ), true ); } ) );
			$new  = array( 'id' => time() . wp_rand( 10, 99 ), 'aud' => min( 3, absint( $P['n_aud'] ?? 0 ) ), 'sec' => isset( self::sections()[ $P['n_sec'] ?? '' ] ) ? $P['n_sec'] : 'custom', 'k' => array(), 'a' => array(), 'q' => array() );
			$any = false;
			foreach ( array_keys( self::LANGS ) as $l ) {
				$k = sanitize_text_field( (string) ( $P['n_k'][ $l ] ?? '' ) ); $a = wp_kses_post( (string) ( $P['n_a'][ $l ] ?? '' ) );
				$nq = sanitize_text_field( (string) ( $P['n_q'][ $l ] ?? '' ) );
				if ( '' !== $a && ( '' !== $k || '' !== $nq ) ) { $new['k'][ $l ] = '' !== $k ? $k : $nq; $new['a'][ $l ] = $a; $new['q'][ $l ] = '' !== $nq ? $nq : $k; $any = true; }
			}
			if ( $any ) { $cust[] = $new; }
			update_option( self::CUSTOM, $cust, false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-helper&done=1' ) );
		exit;
	}

	public static function page() {
		if ( ! self::can() ) { return; }
		$miss = (array) get_option( self::MISS, array() );
		$cust = (array) get_option( self::CUSTOM, array() );
		$cfg  = self::cfg();
		$off  = self::off();
		$edit = (array) get_option( self::EDIT, array() );
		$secs = self::sections();
		$aud  = array( 'Everyone', 'Students and up', 'Instructors and staff', 'Staff only' );
		$ta   = 'width:100%;min-height:90px;';
		echo '<div class="wrap"><h1>Help assistant</h1>';
		if ( ! empty( $_GET['done'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>'; } // phpcs:ignore
		echo '<p>The assistant answers from the school’s written information only. It never reads a student’s record or payments. <b>Untick a section</b> and the assistant will not answer it for visitors and students — they get your hand-off message instead. (Instructors and staff still get answers.)</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_helper"><input type="hidden" name="full" value="1">';
		wp_nonce_field( 'sdsc_helper' );
		echo '<div style="background:#fff;border:1px solid #d5dbe0;padding:12px 16px;margin:12px 0;max-width:1200px"><h2 style="margin-top:0">Hand-off message and numbers</h2>';
		echo '<p><label><b>Office phone (calls)</b> <input name="call" value="' . esc_attr( $cfg['call'] ) . '" style="width:200px"></label> &nbsp; <label><b>Text-message number (Saghar)</b> <input name="text" value="' . esc_attr( $cfg['text'] ) . '" placeholder="202-xxx-xxxx" style="width:200px"></label> <span class="description">Leave the text number empty to use the office phone.</span></p>';
		echo '<p class="description">Use <code>{call}</code> for the office phone and <code>{text}</code> for the text-message number — they become tap-to-call / tap-to-text links. This message is shown when a section is switched off, and when the assistant is not sure.</p><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';
		foreach ( self::LANGS as $l => $n ) { echo '<div><b>' . esc_html( $n ) . '</b><textarea name="msg[' . esc_attr( $l ) . ']" style="' . $ta . '"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '>' . esc_textarea( $cfg['msg'][ $l ] ) . '</textarea></div>'; }
		echo '</div></div>';
		$by = array();
		foreach ( self::data()['entries'] as $id => $e ) { $by[ $e[0] ][ $id ] = $e; }
		foreach ( $secs as $sk => $sn ) {
			$auto = in_array( $sk, array( 'policy', 'tours_student', 'tours_instructor', 'tours_staff' ), true );
			echo '<div style="background:#fff;border:1px solid #d5dbe0;margin:12px 0;max-width:1200px"><div style="padding:10px 16px;background:#f3f6f4;border-bottom:1px solid #d5dbe0"><label style="font-size:15px;font-weight:700"><input type="checkbox" name="sec_on[]" value="' . esc_attr( $sk ) . '"' . ( isset( $off[ $sk ] ) ? '' : ' checked' ) . '> ' . esc_html( $sn ) . '</label> <span class="description">— tick = the assistant answers visitors and students from this section</span></div><div style="padding:8px 16px">';
			if ( $auto ) { echo '<p class="description">Filled in automatically from the website (policy page / guided tours). Edit the text there; use the tick to switch the whole section on or off.</p>'; }
			foreach ( $by[ $sk ] ?? array() as $id => $e ) {
				echo '<div style="border-top:1px solid #eef1f3;padding:8px 0"><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';
				foreach ( self::LANGS as $l => $n ) {
					$li = self::langidx( $l );
					$k  = $edit[ $id ][ $l ]['k'] ?? $e[2][ $li ]; $a = $edit[ $id ][ $l ]['a'] ?? $e[3][ $li ];
					$q = $edit[ $id ][ $l ]['q'] ?? ( self::data()['labels'][ $id ][ $li ] ?? '' );
					echo '<div><b>' . esc_html( $n ) . '</b> <small>(' . esc_html( $aud[ $e[1] ] ) . ')</small><input name="edit[' . esc_attr( $id ) . '][' . esc_attr( $l ) . '][q]" value="' . esc_attr( $q ) . '" placeholder="The question as people see it" style="width:100%;font-weight:700"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '><textarea name="edit[' . esc_attr( $id ) . '][' . esc_attr( $l ) . '][a]" style="width:100%;min-height:110px"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '>' . esc_textarea( $a ) . '</textarea><details><summary>Words people use</summary><textarea name="edit[' . esc_attr( $id ) . '][' . esc_attr( $l ) . '][k]" style="width:100%;min-height:50px"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '>' . esc_textarea( $k ) . '</textarea></details></div>';
				}
				echo '</div></div>';
			}
			foreach ( $cust as $c ) {
				if ( ( $c['sec'] ?? 'custom' ) !== $sk ) { continue; }
				echo '<div style="border-top:1px solid #eef1f3;padding:8px 0;background:#fcfdf6"><b>Added by the office</b> <small>(' . esc_html( $aud[ (int) $c['aud'] ] ?? '' ) . ')</small> <label style="float:right"><input type="checkbox" name="del[]" value="' . esc_attr( $c['id'] ) . '"> delete on save</label><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:6px">';
				foreach ( self::LANGS as $l => $n ) { echo '<div><b>' . esc_html( $n ) . '</b><br><i>' . esc_html( $c['k'][ $l ] ?? '—' ) . '</i><div' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '>' . wp_kses_post( $c['a'][ $l ] ?? '—' ) . '</div></div>'; }
				echo '</div></div>';
			}
			echo '</div></div>';
		}
		echo '<div style="background:#fff;border:1px solid #d5dbe0;padding:12px 16px;margin:12px 0;max-width:1200px"><h2 style="margin-top:0">Add an answer</h2><p><label>Section <select name="n_sec">';
		foreach ( $secs as $sk => $sn ) { echo '<option value="' . esc_attr( $sk ) . '"' . ( 'custom' === $sk ? ' selected' : '' ) . '>' . esc_html( $sn ) . '</option>'; }
		echo '</select></label> &nbsp; <label>Who can see it <select name="n_aud">';
		foreach ( $aud as $i => $lab ) { echo '<option value="' . (int) $i . '">' . esc_html( $lab ) . '</option>'; }
		echo '</select></label> <span class="description">Fill at least one language (words people use + answer).</span></p><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';
		foreach ( self::LANGS as $l => $n ) { echo '<div><b>' . esc_html( $n ) . '</b><input name="n_q[' . esc_attr( $l ) . ']" placeholder="The question, e.g. Where do I park?" style="width:100%;font-weight:700"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '><input name="n_k[' . esc_attr( $l ) . ']" placeholder="Words people use, e.g. office parking" style="width:100%"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '><textarea name="n_a[' . esc_attr( $l ) . ']" placeholder="Answer" style="width:100%;min-height:80px"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '></textarea></div>'; }
		echo '</div></div><p><button class="button button-primary button-hero">Save everything</button></p></form>';
		echo '<h2>Not answered yet (' . count( $miss ) . ')</h2>';
		if ( $miss ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>When</th><th>Language</th><th>Question</th><th>Why</th></tr></thead><tbody>';
			foreach ( array_slice( $miss, 0, 60 ) as $m ) { echo '<tr><td>' . esc_html( $m['t'] ) . '</td><td>' . esc_html( strtoupper( $m['lang'] ?? 'en' ) ) . '</td><td>' . esc_html( $m['q'] ) . '</td><td>' . ( ! empty( $m['held'] ) ? 'section switched off' : 'no answer yet' ) . '</td></tr>'; }
			echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px"><input type="hidden" name="action" value="sdsc_helper">';
			wp_nonce_field( 'sdsc_helper' );
			echo '<button class="button" name="clear" value="1">Clear this list</button></form>';
		} else { echo '<p>Nothing yet.</p>'; }
		echo '</div>';
	}
}
