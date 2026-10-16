<?php
/**
 * SDSC_Helper — the school's built-in help assistant. No outside service, no cost.
 *
 * It answers only from written knowledge: the school's common questions (below), the text of the guided tours
 * (students, instructors, staff) and answers the office adds on its own screen. It never reads a student's
 * record, payment, grade or password. When it is not sure it says so and points to the office.
 * Questions it could not answer are kept (without e-mails or phone numbers) so the office can add an answer.
 *
 * @package SamsClassManager
 */
defined( 'ABSPATH' ) || exit;

class SDSC_Helper {
	const CUSTOM = 'sdsc_helper_custom';
	const MISS   = 'sdsc_helper_unanswered';
	const PHONE  = '(301) 726-3030';
	const MAIL   = 'info@samsdrivingschool.org';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'wp_footer', array( __CLASS__, 'widget' ), 50 );
		add_action( 'admin_footer', array( __CLASS__, 'widget' ), 50 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 43 );
		add_action( 'admin_post_sdsc_helper', array( __CLASS__, 'save' ) );
	}

	/* ------------------------------------------------------------ who is asking */

	/** public < student < instructor < staff */
	public static function level() {
		if ( ! is_user_logged_in() ) { return 0; }
		if ( class_exists( 'SDSC_Workspace' ) && SDSC_Workspace::is_staff() ) { return 3; }
		if ( current_user_can( 'manage_options' ) ) { return 3; }
		$u = wp_get_current_user();
		if ( array_intersect( (array) $u->roles, array( 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' ) ) ) { return 2; }
		return 1;
	}

	/* ------------------------------------------------------------ knowledge */

	/** [ audience level (0 public … 3 staff), title/keywords, answer ] — only facts the school's own screens state. */
	protected static function faq() {
		$p = self::PHONE; $m = self::MAIL;
		return array(
			array( 0, 'log in login sign in cannot log in verification email link password access account', 'For security the website sometimes e-mails you a <b>verification link</b> before it lets you in. Open that e-mail and click the link, then log in again. Look in <b>Spam / Junk</b> too, and add ' . $m . ' to your contacts. If nothing arrives in a few minutes, call the school at ' . $p . '.' ),
			array( 0, 'reset forgot password lost password', 'On the login page choose <b>Lost your password?</b> and enter your e-mail. The link arrives by e-mail (check Spam / Junk). Still stuck? Call ' . $p . '.' ),
			array( 0, 'email did not arrive no email not received spam junk', 'Please check your <b>Spam / Junk</b> folder and add ' . $m . ' to your contacts. If it is not there, call the school at ' . $p . ' and we will check the address on your registration.' ),
			array( 0, 'zoom link zoom details meeting id passcode when do i get the zoom link class link', 'Your Zoom link, meeting ID and passcode come in a <b>separate e-mail before the first session</b> — not at registration. Please check your Spam / Junk folder too. Not there the day before? Call ' . $p . '.' ),
			array( 0, 'receipt payment confirmation registration confirmation', 'Your receipt with the class dates is e-mailed when you register. The Zoom details come later in a separate e-mail. For anything about a payment, please call ' . $p . '.' ),
			array( 0, 'pay cash check zelle pay at office pay in person', 'You can pay at the office by cash, check or Zelle. Ask the office for the registration link for cash / check payments (they can text it to you), fill it in, and pay at the office. Call ' . $p . ' for details.' ),
			array( 0, 'contact office phone call address email hours', 'Call or text the school at <b>' . $p . '</b> or write to ' . $m . '.' ),
			array( 1, 'quiz course locked open online course payment cannot start quiz', 'The online course and quizzes open after your payment is received. If you already paid and the quiz is still locked, call the school at ' . $p . ' and we will open it.' ),
			array( 1, 'cancel change lesson reschedule fee cancellation deadline free cancellation', 'You can cancel or change a lesson for <b>free until the deadline shown on that lesson</b>. Later than that, a missed lesson, or coming without your learner’s permit carries the fee. To change something, call or text ' . $p . ' before the deadline.' ),
			array( 1, 'permit learners permit bring permit what to bring behind the wheel', 'Bring your <b>valid learner’s permit</b> to every behind-the-wheel lesson. No permit — no lesson.' ),
			array( 1, 'absence absent miss class zoom attendance how many classes can i miss', 'Zoom session 1 is mandatory. After it, up to <b>4 absences</b> are allowed — always tell the school before you miss a class: ' . $p . '.' ),
			array( 1, 'book lesson schedule driving lesson choose time instructor private link', 'Your private booking link lets you choose your instructor, the day and a time when an instructor and car are free. Do not share the link — it is yours and it expires. No link? Call ' . $p . '.' ),
			array( 1, 'calendar add to calendar iphone google outlook reminder', 'On your lessons page tap <b>Add all to calendar</b>: your classes and lessons go to your iPhone, Google or Outlook calendar with an alert one hour before, and changes update by themselves.' ),
			array( 1, 'scorecard evaluation progress skills results instructor notes', 'After each behind-the-wheel lesson your instructor fills in a scorecard. You see the skills you have mastered and the advice for your next lesson on your lessons page.' ),
			array( 1, 'two hours per day daily limit how many hours can i drive lesson length', 'Driver’s Ed students may drive at most <b>2 hours a day</b> (Maryland MVA rule), so a Driver’s Ed lesson is at most 2 hours.' ),
			array( 1, 'lesson times hours of operation when are lessons', 'Behind-the-wheel lessons are scheduled between 9 AM and 9 PM, depending on when an instructor and a car are free.' ),
			array( 2, 'running late sick car problem cannot make lesson cancel lesson instructor', 'Running late, sick or a car problem? <b>Call the office</b> (' . $p . '). Never cancel a lesson yourself.' ),
			array( 2, 'permit no permit student without permit before you drive', 'Before you drive, ask to see the student’s <b>valid learner’s permit</b>. No permit — no lesson: tell the office.' ),
			array( 2, 'report evaluation save lesson finish lesson scorecard signature', 'Fill the report in three parts: <b>1 Skills</b>, <b>2 Car check</b>, <b>3 Notes &amp; signatures</b>. Your answers stay on the phone until you save, even without signal. Saving closes the lesson and sends the student their scorecard.' ),
			array( 2, 'incident accident low fuel gas fuel level car check', 'In the Car check, fuel below a quarter: refuel with the student and enter gallons and cost. An <b>incident or low fuel alerts the office at once</b>.' ),
			array( 2, 'lesson not on schedule walk in start lesson unbooked', 'Use <b>Not on the schedule?</b> to start a lesson the office has not booked yet, and tell the office afterwards.' ),
		);
	}

	protected static function entries( $level ) {
		$out = array();
		foreach ( self::faq() as $i => $f ) {
			if ( $f[0] <= $level ) { $out[] = array( 'id' => 'f' . $i, 'k' => $f[1], 't' => self::first_words( $f[1] ), 'a' => $f[2], 'body' => wp_strip_all_tags( $f[2] ), 'link' => '' ); }
		}
		foreach ( (array) get_option( self::CUSTOM, array() ) as $c ) {
			if ( (int) ( $c['aud'] ?? 0 ) <= $level && ! empty( $c['k'] ) && ! empty( $c['a'] ) ) { $out[] = array( 'id' => 'c' . $c['id'], 'k' => $c['k'], 't' => self::first_words( $c['k'] ), 'a' => wp_kses_post( $c['a'] ), 'body' => wp_strip_all_tags( $c['a'] ), 'link' => '' ); }
		}
		foreach ( self::policy_sections() as $i => $ps ) {
			$out[] = array( 'id' => 'p' . $i, 'k' => $ps['t'] . ' policy', 't' => $ps['t'], 'a' => $ps['a'], 'body' => wp_strip_all_tags( $ps['a'] ), 'link' => $ps['link'], 'linkt' => 'Read the full policy' );
		}
		if ( class_exists( 'SDSC_Tours' ) ) {
			// The written tours: students / instructors (website pages) and staff (dashboard screens).
			if ( $level >= 1 ) {
				foreach ( SDSC_Tours::front_tours() as $key => $t ) {
					$aud = 0 === strpos( $key, 'instructor' ) ? 2 : 1;
					if ( $aud > $level ) { continue; }
					foreach ( (array) $t[2] as $n => $st ) {
						$txt = wp_strip_all_tags( $st['content'] );
						if ( strlen( $txt ) < 40 ) { continue; }
						$out[] = array( 'id' => 'ft' . $key . $n, 'k' => $t[0] . ' ' . $st['title'], 't' => $st['title'], 'a' => $st['content'], 'body' => $txt, 'link' => '' );
					}
				}
			}
			if ( $level >= 3 ) {
				foreach ( SDSC_Tours::tours() as $key => $t ) {
					$url = SDSC_Tours::tour_url( $key );
					foreach ( (array) $t[5] as $n => $st ) {
						$txt = wp_strip_all_tags( $st['content'] );
						if ( strlen( $txt ) < 40 ) { continue; }
						$out[] = array( 'id' => 'st' . $key . $n, 'k' => $t[1] . ' ' . $st['title'], 't' => $st['title'], 'a' => $st['content'], 'body' => $txt, 'link' => $url, 'linkt' => 'Open: ' . $t[1] );
					}
				}
			}
		}
		return $out;
	}

	/** The school's own policy page, cut into one entry per heading — so fees, refunds and attendance are answered in the school's words. */
	protected static function policy_sections() {
		static $c = null;
		if ( null !== $c ) { return $c; }
		$c = array();
		if ( ! class_exists( 'SDSC_Policies' ) ) { return $c; }
		$html = (string) SDSC_Policies::html();
		if ( '' === $html ) { return $c; }
		$pg   = SDSC_Policies::page();
		$link = $pg ? get_permalink( $pg ) : home_url( '/policy/' );
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

	protected static function stop() {
		return array_flip( array( 'a', 'an', 'the', 'is', 'are', 'am', 'was', 'be', 'to', 'of', 'in', 'on', 'at', 'for', 'and', 'or', 'i', 'my', 'me', 'we', 'you', 'your', 'it', 'its', 'do', 'does', 'did', 'can', 'could', 'how', 'what', 'when', 'where', 'why', 'who', 'which', 'this', 'that', 'with', 'not', 'no', 'cant', 'cannot', 'dont', 'doesnt', 'have', 'has', 'had', 'will', 'would', 'should', 'there', 'about', 'please', 'help', 'need', 'want', 'get', 'got', 'if', 'but', 'so', 'am', 'im', 'ive' ) );
	}

	/** Words that mean the same thing for matching (the first word is what they turn into). */
	protected static function groups() {
		return array(
			array( 'login', 'log', 'signin', 'sign', 'access', 'enter', 'verification', 'verify', 'code' ),
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

	protected static function tokens( $s ) {
		static $stop, $map;
		if ( null === $stop ) {
			$stop = self::stop();
			$map  = array();
			foreach ( self::groups() as $g ) { foreach ( $g as $w ) { $map[ $w ] = $g[0]; } }
		}
		$s = strtolower( str_replace( array( '’', "'" ), '', wp_strip_all_tags( (string) $s ) ) );
		$out = array();
		foreach ( preg_split( '/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( isset( $stop[ $w ] ) || strlen( $w ) < 2 ) { continue; }
			if ( strlen( $w ) > 4 ) { $w = preg_replace( '/(ing|ed|es|s)$/', '', $w ); }
			$out[] = $map[ $w ] ?? $w;
		}
		return array_values( array_unique( $out ) );
	}

	/** Best answers for a question: [ [score, entry], … ] sorted. */
	public static function search( $q, $level ) {
		$qt = self::tokens( $q );
		if ( ! $qt ) { return array(); }
		$es = self::entries( $level );
		$df = array();
		$kt = array();
		$bt = array();
		foreach ( $es as $i => $e ) {
			$kt[ $i ] = array_flip( self::tokens( $e['k'] ) );
			$bt[ $i ] = array_flip( self::tokens( $e['body'] ) );
			foreach ( array_keys( $kt[ $i ] + $bt[ $i ] ) as $w ) { $df[ $w ] = ( $df[ $w ] ?? 0 ) + 1; }
		}
		$N   = max( 1, count( $es ) );
		$idf = function ( $w ) use ( $df, $N ) { return log( 1 + $N / ( 1 + ( $df[ $w ] ?? 0 ) ) ); };
		$tot = 0;
		foreach ( $qt as $w ) { if ( isset( $df[ $w ] ) ) { $tot += $idf( $w ); } } // words the assistant has never seen do not count against a match
		$res = array();
		foreach ( $es as $i => $e ) {
			$sc = 0; $kh = 0;
			foreach ( $qt as $w ) {
				if ( isset( $kt[ $i ][ $w ] ) ) { $sc += $idf( $w ); $kh++; }
				elseif ( isset( $bt[ $i ][ $w ] ) ) { $sc += 0.4 * $idf( $w ); }
			}
			if ( $kh && $tot > 0 ) { $res[] = array( $sc / $tot, $e ); }
		}
		usort( $res, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		return $res;
	}

	public static function answer( $q, $level ) {
		$r = self::search( $q, $level );
		$min = 0.5;
		if ( ! $r || $r[0][0] < $min ) {
			return array( 'found' => false, 'html' => 'I am not sure about that one, and I would rather not guess. Please call or text the school at <a href="tel:+13017263030">' . self::PHONE . '</a> or write to <a href="mailto:' . self::MAIL . '">' . self::MAIL . '</a>.' );
		}
		$e    = $r[0][1];
		$html = $e['a'];
		if ( ! empty( $e['link'] ) ) { $html .= '<p><a href="' . esc_url( $e['link'] ) . '">' . esc_html( $e['linkt'] ) . ' →</a></p>'; }
		$more = array();
		foreach ( array_slice( $r, 1, 3 ) as $x ) { if ( $x[0] >= $min * 0.8 && $x[1]['id'] !== $e['id'] ) { $more[] = $x[1]['t']; } }
		return array( 'found' => true, 'html' => $html, 'related' => array_values( array_unique( $more ) ), 'id' => $e['id'] );
	}

	/* ------------------------------------------------------------ REST */

	public static function rest() {
		register_rest_route( 'sdsc/v1', '/helper', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'rest_ask' ),
		) );
	}

	public static function rest_ask( WP_REST_Request $r ) {
		$q  = trim( mb_substr( wp_strip_all_tags( (string) $r->get_param( 'q' ) ), 0, 300 ) );
		$ip = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
		$k  = 'sdsc_help_' . $ip;
		$n  = (int) get_transient( $k );
		if ( $n >= 30 ) { return new WP_REST_Response( array( 'found' => false, 'html' => 'Please wait a minute and try again, or call ' . self::PHONE . '.' ), 429 ); }
		set_transient( $k, $n + 1, 60 );
		if ( strlen( $q ) < 3 ) { return array( 'found' => false, 'html' => 'Type your question, for example: “I did not get the Zoom link”.' ); }
		$a = self::answer( $q, self::level() );
		if ( ! $a['found'] ) { self::miss( $q ); }
		return $a;
	}

	protected static function miss( $q ) {
		$q = preg_replace( '/[\w.+-]+@[\w-]+\.[\w.]+/', '[email]', $q );
		$q = preg_replace( '/\+?\d[\d\s().-]{6,}\d/', '[number]', $q );
		$l = (array) get_option( self::MISS, array() );
		array_unshift( $l, array( 't' => current_time( 'mysql' ), 'q' => $q, 'lv' => self::level() ) );
		update_option( self::MISS, array_slice( $l, 0, 200 ), false );
	}

	/* ------------------------------------------------------------ widget */

	public static function widget() {
		if ( is_admin() && ! self::level() ) { return; }
		if ( is_customize_preview() ) { return; }
		$url = rest_url( 'sdsc/v1/helper' );
		?>
		<div id="sdsc-help" style="position:fixed;left:16px;bottom:16px;z-index:99990;font:14px/1.45 system-ui,-apple-system,Segoe UI,sans-serif">
			<button type="button" id="sdsc-help-btn" aria-expanded="false" style="border:0;border-radius:24px;background:#0b5d2e;color:#fff;padding:10px 16px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)">Need help?</button>
			<div id="sdsc-help-box" hidden style="position:absolute;left:0;bottom:48px;width:min(340px,calc(100vw - 32px));background:#fff;color:#15191d;border:1px solid #d5dbe0;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.25);overflow:hidden">
				<div style="background:#0b5d2e;color:#fff;padding:10px 12px;font-weight:700">Sam’s Driving School help</div>
				<div id="sdsc-help-log" style="max-height:300px;overflow:auto;padding:10px 12px"><p style="margin:0 0 8px">Ask a question about classes, lessons, Zoom, e-mails or logging in. I answer from the school’s own information and never see your record or payments.</p></div>
				<form id="sdsc-help-form" style="display:flex;gap:6px;padding:8px;border-top:1px solid #e3e7ea"><input id="sdsc-help-q" type="text" maxlength="300" placeholder="Type your question…" aria-label="Your question" style="flex:1;border:1px solid #cfd6db;border-radius:8px;padding:8px"><button style="border:0;border-radius:8px;background:#0b5d2e;color:#fff;padding:8px 12px;font-weight:700;cursor:pointer">Ask</button></form>
			</div>
		</div>
		<script>
		(function(){
			var b=document.getElementById('sdsc-help-btn'),x=document.getElementById('sdsc-help-box'),f=document.getElementById('sdsc-help-form'),q=document.getElementById('sdsc-help-q'),l=document.getElementById('sdsc-help-log');
			if(!b){return;}
			function add(h,me){var d=document.createElement('div');d.style.cssText='margin:6px 0;padding:8px 10px;border-radius:10px;'+(me?'background:#eef5f0;text-align:right':'background:#f6f7f8');d.innerHTML=h;l.appendChild(d);l.scrollTop=l.scrollHeight;return d;}
			function esc(s){return s.replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
			b.addEventListener('click',function(){x.hidden=!x.hidden;b.setAttribute('aria-expanded',String(!x.hidden));if(!x.hidden){q.focus();}});
			f.addEventListener('submit',function(e){e.preventDefault();var t=q.value.trim();if(t.length<3){return;}add(esc(t),true);q.value='';var w=add('…');
				fetch(<?php echo wp_json_encode( $url ); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({q:t}),credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){
					var h=r.html||'';if(r.related&&r.related.length){h+='<p style="margin:8px 0 0;font-size:12px;color:#5b646d">Related: '+r.related.map(esc).join(' · ')+'</p>';}w.innerHTML=h;l.scrollTop=l.scrollHeight;
				}).catch(function(){w.innerHTML='Something went wrong. Please call <?php echo esc_js( self::PHONE ); ?>.';});});
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
		$c = (array) get_option( self::CUSTOM, array() );
		if ( ! empty( $_POST['del'] ) ) {
			$c = array_values( array_filter( $c, function ( $x ) { return (string) $x['id'] !== (string) $_POST['del']; } ) ); // phpcs:ignore
		} elseif ( ! empty( $_POST['k'] ) && ! empty( $_POST['a'] ) ) {
			$c[] = array( 'id' => time() . wp_rand( 10, 99 ), 'aud' => min( 3, absint( $_POST['aud'] ?? 0 ) ), 'k' => sanitize_text_field( wp_unslash( $_POST['k'] ) ), 'a' => wp_kses_post( wp_unslash( $_POST['a'] ) ) );
		}
		if ( ! empty( $_POST['clear'] ) ) { delete_option( self::MISS ); }
		update_option( self::CUSTOM, $c, false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-helper&done=1' ) );
		exit;
	}

	public static function page() {
		if ( ! self::can() ) { return; }
		$miss = (array) get_option( self::MISS, array() );
		$cust = (array) get_option( self::CUSTOM, array() );
		$aud  = array( 'Everyone', 'Students and up', 'Instructors and staff', 'Staff only' );
		echo '<div class="wrap"><h1>Help assistant</h1><p>The assistant answers from the school’s written information only (common questions, the guided tours, and the answers you add here). It never reads a student’s record or payments. Questions it could not answer are listed below — add an answer and it will know it next time.</p>';
		echo '<h2>Add an answer</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sdsc_helper">';
		wp_nonce_field( 'sdsc_helper' );
		echo '<p><label>Words people use (the question) <br><input name="k" style="width:100%;max-width:600px" maxlength="200" placeholder="e.g. where is the office parking"></label></p>';
		echo '<p><label>Answer<br><textarea name="a" rows="4" style="width:100%;max-width:600px"></textarea></label></p><p><label>Who can see it <select name="aud">';
		foreach ( $aud as $i => $l ) { echo '<option value="' . (int) $i . '">' . esc_html( $l ) . '</option>'; }
		echo '</select></label> <button class="button button-primary">Add</button></p></form>';
		echo '<h2>Not answered yet (' . count( $miss ) . ')</h2>';
		if ( $miss ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>When</th><th>Question</th></tr></thead><tbody>';
			foreach ( array_slice( $miss, 0, 60 ) as $m ) { echo '<tr><td>' . esc_html( $m['t'] ) . '</td><td>' . esc_html( $m['q'] ) . '</td></tr>'; }
			echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px"><input type="hidden" name="action" value="sdsc_helper">';
			wp_nonce_field( 'sdsc_helper' );
			echo '<button class="button" name="clear" value="1">Clear this list</button></form>';
		} else { echo '<p>Nothing yet.</p>'; }
		echo '<h2>Answers you added (' . count( $cust ) . ')</h2>';
		foreach ( $cust as $c ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 8px;padding:8px;border:1px solid #e3e7ea;max-width:900px"><input type="hidden" name="action" value="sdsc_helper">';
			wp_nonce_field( 'sdsc_helper' );
			echo '<b>' . esc_html( $c['k'] ) . '</b> <small>(' . esc_html( $aud[ (int) $c['aud'] ] ?? '' ) . ')</small><br>' . wp_kses_post( $c['a'] ) . '<br><button class="button" name="del" value="' . esc_attr( $c['id'] ) . '">Delete</button></form>';
		}
		echo '</div>';
	}
}
