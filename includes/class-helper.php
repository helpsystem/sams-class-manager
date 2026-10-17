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
			'text' => '',
			'msg'  => array(
				'en' => 'I can’t help with that here. Please call the office at {call} or send a text message to {text}.',
				'es' => 'No puedo ayudarle con eso aquí. Por favor llame a la oficina al {call} o envíe un mensaje de texto al {text}.',
				'fa' => 'در اینجا نمی‌توانم به این سؤال پاسخ بدهم. لطفاً با دفتر به شمارهٔ {call} تماس بگیرید یا به شمارهٔ {text} پیامک بفرستید.',
			),
		);
		$c = (array) get_option( self::CFG, array() );
		foreach ( array( 'call', 'text' ) as $k ) { if ( isset( $c[ $k ] ) && '' !== trim( (string) $c[ $k ] ) ) { $d[ $k ] = trim( (string) $c[ $k ] ); } }
		if ( isset( $c['text'] ) ) { $d['text'] = trim( (string) $c['text'] ); }
		foreach ( array_keys( self::LANGS ) as $l ) { if ( ! empty( $c['msg'][ $l ] ) ) { $d['msg'][ $l ] = (string) $c['msg'][ $l ]; } }
		return $d;
	}

	protected static function digits( $n ) { $d = preg_replace( '/\D+/', '', (string) $n ); return strlen( $d ) === 10 ? '1' . $d : $d; }

	protected static function handoff( $lang ) {
		$c    = self::cfg();
		$text = '' !== $c['text'] ? $c['text'] : $c['call'];
		$call = '<a href="tel:+' . esc_attr( self::digits( $c['call'] ) ) . '" dir="ltr">' . esc_html( $c['call'] ) . '</a>';
		$sms  = '<a href="sms:+' . esc_attr( self::digits( $text ) ) . '" dir="ltr">' . esc_html( $text ) . '</a>';
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

	protected static function fill( $t ) {
		$c = self::cfg();
		return str_replace( array( '{P}', '{M}' ), array( $c['call'], self::MAIL ), $t );
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
			$out[] = array( 'id' => $id, 'sec' => $e[0], 'off' => isset( $off[ $e[0] ] ), 'k' => $k, 't' => self::first_words( $k ), 'a' => $a, 'body' => wp_strip_all_tags( $a ), 'link' => '' );
		}
		foreach ( (array) get_option( self::CUSTOM, array() ) as $c ) {
			if ( (int) ( $c['aud'] ?? 0 ) > $level || empty( $c['k'][ $lang ] ) || empty( $c['a'][ $lang ] ) ) { continue; }
			$sec = $c['sec'] ?? 'custom';
			$out[] = array( 'id' => 'c' . $c['id'], 'sec' => $sec, 'off' => isset( $off[ $sec ] ), 'k' => $c['k'][ $lang ], 't' => self::first_words( $c['k'][ $lang ] ), 'a' => wp_kses_post( $c['a'][ $lang ] ), 'body' => wp_strip_all_tags( $c['a'][ $lang ] ), 'link' => '' );
		}
		if ( 'en' !== $lang ) { return $out; }
		foreach ( self::policy_sections() as $i => $ps ) {
			$out[] = array( 'id' => 'p' . $i, 'sec' => 'policy', 'off' => isset( $off['policy'] ), 'k' => $ps['t'] . ' policy', 't' => $ps['t'], 'a' => $ps['a'], 'body' => wp_strip_all_tags( $ps['a'] ), 'link' => $ps['link'], 'linkt' => 'Read the full policy' );
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
						$out[] = array( 'id' => 'ft' . $key . $n, 'sec' => $sec, 'off' => isset( $off[ $sec ] ), 'k' => $t[0] . ' ' . $st['title'], 't' => $st['title'], 'a' => $st['content'], 'body' => $txt, 'link' => '' );
					}
				}
			}
			if ( $level >= 3 ) {
				foreach ( SDSC_Tours::tours() as $key => $t ) {
					$url = SDSC_Tours::tour_url( $key );
					foreach ( (array) $t[5] as $n => $st ) {
						$txt = wp_strip_all_tags( $st['content'] );
						if ( strlen( $txt ) < 40 ) { continue; }
						$out[] = array( 'id' => 'st' . $key . $n, 'sec' => 'tours_staff', 'off' => false, 'k' => $t[1] . ' ' . $st['title'], 't' => $st['title'], 'a' => $st['content'], 'body' => $txt, 'link' => $url, 'linkt' => 'Open: ' . $t[1] );
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

	public static function answer( $q, $level, $lang = 'en' ) {
		$lang = isset( self::LANGS[ $lang ] ) ? $lang : 'en';
		$r    = self::search( $q, $level, $lang );
		$min  = 0.5;
		if ( ! $r || $r[0][0] < $min ) { return array( 'found' => false, 'html' => self::handoff( $lang ) ); }
		$e = $r[0][1];
		// A section the office switched off is not answered for visitors and students.
		if ( $e['off'] && $level < 2 ) { return array( 'found' => false, 'held' => true, 'html' => self::handoff( $lang ) ); }
		$html = $e['a'];
		if ( ! empty( $e['link'] ) ) { $html .= '<p><a href="' . esc_url( $e['link'] ) . '">' . esc_html( $e['linkt'] ) . ' →</a></p>'; }
		$more = array();
		foreach ( array_slice( $r, 1, 3 ) as $x ) { if ( $x[0] >= $min * 0.8 && $x[1]['id'] !== $e['id'] && ! ( $x[1]['off'] && $level < 2 ) ) { $more[] = $x[1]['t']; } }
		return array( 'found' => true, 'html' => $html, 'related' => array_values( array_unique( $more ) ), 'id' => $e['id'] );
	}

	/* ------------------------------------------------------------ REST */

	public static function rest() {
		register_rest_route( 'sdsc/v1', '/helper', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_ask' ) ) );
	}

	public static function rest_ask( WP_REST_Request $r ) {
		$q    = trim( mb_substr( wp_strip_all_tags( (string) $r->get_param( 'q' ) ), 0, 300 ) );
		$lang = (string) $r->get_param( 'lang' );
		$lang = isset( self::LANGS[ $lang ] ) ? $lang : 'en';
		if ( 'en' === $lang && preg_match( '/[\x{0600}-\x{06FF}]/u', $q ) ) { $lang = 'fa'; }
		$k = 'sdsc_help_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
		$n = (int) get_transient( $k );
		if ( $n >= 30 ) { return new WP_REST_Response( array( 'found' => false, 'html' => self::handoff( $lang ) ), 429 ); }
		set_transient( $k, $n + 1, 60 );
		if ( mb_strlen( $q ) < 3 ) { return array( 'found' => false, 'html' => '…' ); }
		$a = self::answer( $q, self::level(), $lang );
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
		$url = rest_url( 'sdsc/v1/helper' );
		$t = array(
			'en' => array( 'btn' => 'Need help?', 'title' => 'Sam’s Driving School help', 'intro' => 'Ask a question about classes, lessons, Zoom, e-mails or logging in. I answer from the school’s own information and never see your record or payments.', 'ph' => 'Type your question…', 'ask' => 'Ask', 'c1' => 'I want to register', 'q1' => 'How do I register?', 'c2' => 'I registered but can’t log in', 'q2' => 'I registered but cannot log in', 'rel' => 'Related', 'err' => 'Something went wrong. Please call' ),
			'es' => array( 'btn' => '¿Necesita ayuda?', 'title' => 'Ayuda de Sam’s Driving School', 'intro' => 'Pregunte sobre clases, lecciones, Zoom, correos o cómo iniciar sesión. Respondo con la información de la escuela y nunca veo su registro ni sus pagos.', 'ph' => 'Escriba su pregunta…', 'ask' => 'Enviar', 'c1' => 'Quiero registrarme', 'q1' => '¿Cómo me registro?', 'c2' => 'Me registré pero no puedo entrar', 'q2' => 'Me registré pero no puedo entrar', 'rel' => 'Relacionado', 'err' => 'Algo salió mal. Por favor llame al' ),
			'fa' => array( 'btn' => 'کمک می‌خواهید؟', 'title' => 'راهنمای Sam’s Driving School', 'intro' => 'دربارهٔ کلاس‌ها، درس‌ها، Zoom، ایمیل‌ها یا ورود به حساب بپرسید. من از اطلاعات خود مدرسه جواب می‌دهم و هرگز پرونده یا پرداخت‌های شما را نمی‌بینم.', 'ph' => 'سؤالتان را بنویسید…', 'ask' => 'بپرس', 'c1' => 'می‌خواهم ثبت‌نام کنم', 'q1' => 'چطور ثبت‌نام کنم؟', 'c2' => 'ثبت‌نام کردم ولی نمی‌توانم وارد شوم', 'q2' => 'ثبت‌نام کردم ولی نمی‌توانم وارد شوم', 'rel' => 'مرتبط', 'err' => 'مشکلی پیش آمد. لطفاً تماس بگیرید' ),
		);
		$call = self::cfg()['call'];
		$chip = 'border:1px solid #0b5d2e;background:#fff;color:#0b5d2e;border-radius:14px;padding:4px 10px;cursor:pointer;font-size:13px';
		?>
		<div id="sdsc-help" style="position:fixed;left:16px;bottom:16px;z-index:99990;font:14px/1.45 system-ui,-apple-system,Segoe UI,Tahoma,sans-serif">
			<button type="button" id="sdsc-help-btn" aria-expanded="false" style="border:0;border-radius:24px;background:#0b5d2e;color:#fff;padding:10px 16px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)">Need help?</button>
			<div id="sdsc-help-box" hidden style="position:absolute;left:0;bottom:48px;width:min(340px,calc(100vw - 32px));background:#fff;color:#15191d;border:1px solid #d5dbe0;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.25);overflow:hidden">
				<div style="background:#0b5d2e;color:#fff;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;gap:8px"><b id="sdsc-help-title">Sam’s Driving School help</b><span id="sdsc-help-langs"><?php foreach ( self::LANGS as $k => $n ) { echo '<button type="button" data-l="' . esc_attr( $k ) . '" style="border:1px solid rgba(255,255,255,.6);background:transparent;color:#fff;border-radius:10px;padding:1px 7px;margin-left:3px;cursor:pointer;font-size:12px">' . esc_html( strtoupper( $k ) ) . '</button>'; } ?></span></div>
				<div id="sdsc-help-log" style="max-height:300px;overflow:auto;padding:10px 12px"></div>
				<?php if ( ! is_user_logged_in() ) : ?><div id="sdsc-help-chips" style="padding:0 12px 8px;display:flex;gap:6px;flex-wrap:wrap"><button type="button" class="sdsc-chip" data-k="1" style="<?php echo esc_attr( $chip ); ?>"></button><button type="button" class="sdsc-chip" data-k="2" style="<?php echo esc_attr( $chip ); ?>"></button></div><?php endif; ?>
				<form id="sdsc-help-form" style="display:flex;gap:6px;padding:8px;border-top:1px solid #e3e7ea"><input id="sdsc-help-q" type="text" maxlength="300" aria-label="Question" style="flex:1;border:1px solid #cfd6db;border-radius:8px;padding:8px"><button id="sdsc-help-go" style="border:0;border-radius:8px;background:#0b5d2e;color:#fff;padding:8px 12px;font-weight:700;cursor:pointer"></button></form>
			</div>
		</div>
		<script>
		(function(){
			var T=<?php echo wp_json_encode( $t ); ?>,CALL=<?php echo wp_json_encode( $call ); ?>;
			var b=document.getElementById('sdsc-help-btn'),x=document.getElementById('sdsc-help-box'),f=document.getElementById('sdsc-help-form'),q=document.getElementById('sdsc-help-q'),l=document.getElementById('sdsc-help-log');
			if(!b){return;}
			var lang='en';try{lang=localStorage.getItem('sdsc_help_lang')||'';}catch(e){}
			if(!T[lang]){var n=(navigator.language||'en').slice(0,2);lang=T[n]?n:'en';}
			function esc(s){return s.replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
			function add(h,me){var d=document.createElement('div');d.style.cssText='margin:6px 0;padding:8px 10px;border-radius:10px;'+(me?'background:#eef5f0;':'background:#f6f7f8');d.innerHTML=h;l.appendChild(d);l.scrollTop=l.scrollHeight;return d;}
			function paint(){var s=T[lang];x.dir=(lang==='fa')?'rtl':'ltr';b.textContent=s.btn;document.getElementById('sdsc-help-title').textContent=s.title;q.placeholder=s.ph;document.getElementById('sdsc-help-go').textContent=s.ask;l.innerHTML='';add(esc(s.intro));
				Array.prototype.forEach.call(document.querySelectorAll('.sdsc-chip'),function(c){c.textContent=s['c'+c.getAttribute('data-k')];});
				Array.prototype.forEach.call(document.querySelectorAll('#sdsc-help-langs button'),function(c){c.style.background=c.getAttribute('data-l')===lang?'rgba(255,255,255,.3)':'transparent';});}
			paint();
			Array.prototype.forEach.call(document.querySelectorAll('#sdsc-help-langs button'),function(c){c.addEventListener('click',function(){lang=c.getAttribute('data-l');try{localStorage.setItem('sdsc_help_lang',lang);}catch(e){}paint();});});
			function ask(t){add(esc(t),true);var w=add('…');
				fetch(<?php echo wp_json_encode( $url ); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({q:t,lang:lang}),credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){
					var s=T[r.lang||lang],h=r.html||'';if(r.related&&r.related.length){h+='<p style="margin:8px 0 0;font-size:12px;color:#5b646d">'+s.rel+': '+r.related.map(esc).join(' · ')+'</p>';}w.innerHTML=h;w.dir=((r.lang||lang)==='fa')?'rtl':'ltr';l.scrollTop=l.scrollHeight;
				}).catch(function(){w.textContent=T[lang].err+' '+CALL;});}
			Array.prototype.forEach.call(document.querySelectorAll('.sdsc-chip'),function(c){c.addEventListener('click',function(){ask(T[lang]['q'+c.getAttribute('data-k')]);});});
			b.addEventListener('click',function(){x.hidden=!x.hidden;b.setAttribute('aria-expanded',String(!x.hidden));if(!x.hidden){q.focus();}});
			f.addEventListener('submit',function(e){e.preventDefault();var t=q.value.trim();if(t.length<3){return;}q.value='';ask(t);});
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
					$a  = wp_kses_post( (string) ( $ls[ $l ]['a'] ?? '' ) ); $k = sanitize_text_field( (string) ( $ls[ $l ]['k'] ?? '' ) );
					if ( '' !== $a && trim( $a ) !== trim( $d[3][ $li ] ) ) { $edit[ $id ][ $l ]['a'] = $a; }
					if ( '' !== $k && trim( $k ) !== trim( $d[2][ $li ] ) ) { $edit[ $id ][ $l ]['k'] = $k; }
				}
			}
			update_option( self::EDIT, $edit, false );
			$cust = array_values( array_filter( (array) get_option( self::CUSTOM, array() ), function ( $c ) use ( $P ) { return ! in_array( (string) $c['id'], array_map( 'strval', (array) ( $P['del'] ?? array() ) ), true ); } ) );
			$new  = array( 'id' => time() . wp_rand( 10, 99 ), 'aud' => min( 3, absint( $P['n_aud'] ?? 0 ) ), 'sec' => isset( self::sections()[ $P['n_sec'] ?? '' ] ) ? $P['n_sec'] : 'custom', 'k' => array(), 'a' => array() );
			$any = false;
			foreach ( array_keys( self::LANGS ) as $l ) {
				$k = sanitize_text_field( (string) ( $P['n_k'][ $l ] ?? '' ) ); $a = wp_kses_post( (string) ( $P['n_a'][ $l ] ?? '' ) );
				if ( '' !== $k && '' !== $a ) { $new['k'][ $l ] = $k; $new['a'][ $l ] = $a; $any = true; }
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
					echo '<div><b>' . esc_html( $n ) . '</b> <small>(' . esc_html( $aud[ $e[1] ] ) . ')</small><textarea name="edit[' . esc_attr( $id ) . '][' . esc_attr( $l ) . '][a]" style="width:100%;min-height:110px"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '>' . esc_textarea( $a ) . '</textarea><details><summary>Words people use</summary><textarea name="edit[' . esc_attr( $id ) . '][' . esc_attr( $l ) . '][k]" style="width:100%;min-height:50px"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '>' . esc_textarea( $k ) . '</textarea></details></div>';
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
		foreach ( self::LANGS as $l => $n ) { echo '<div><b>' . esc_html( $n ) . '</b><input name="n_k[' . esc_attr( $l ) . ']" placeholder="Words people use, e.g. office parking" style="width:100%"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '><textarea name="n_a[' . esc_attr( $l ) . ']" placeholder="Answer" style="width:100%;min-height:80px"' . ( 'fa' === $l ? ' dir="rtl"' : '' ) . '></textarea></div>'; }
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
