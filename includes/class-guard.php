<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Sign-up blocklist: stops known spam addresses and throwaway domains from creating accounts
 * through every registration form on the site (WordPress, Tutor LMS, the login/sign-up popup, WooCommerce).
 *
 * - Exact emails are compared Gmail-aware: dots and "+tags" are ignored, so k.uv.e.x.e@gmail.com
 *   and kuvexe+1@gmail.com count as the same address.
 * - Gmail addresses written with 4 or more dots (a common bot trick) are refused.
 * - Admin page: Class Manager → Blocked sign-ups (edit the list, see refused attempts).
 */
class SDSC_Guard {

	const OPT = 'sdsc_blocklist';
	const LOG = 'sdsc_blocklist_log';
	const MSG = 'Registration with this email address isn’t possible. Please call us at (301) 726-3030 and we’ll set you up.';

	public static function init() {
		add_filter( 'registration_errors', array( __CLASS__, 'wp_errors' ), 20, 3 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'wc_errors' ), 20, 4 );
		add_filter( 'xoo_el_process_registration_errors', array( __CLASS__, 'wc_errors' ), 20, 4 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_sdsc_blocklist_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sdsc_suspicious_csv', array( __CLASS__, 'suspicious_csv' ) );
		// Paused accounts: cannot sign in until the office releases them.
		add_filter( 'authenticate', array( __CLASS__, 'stop_paused' ), 99, 1 );
		add_action( 'admin_post_sdsc_account', array( __CLASS__, 'account_action' ) );
		add_filter( 'user_row_actions', array( __CLASS__, 'user_row' ), 20, 2 );
		// No public profiles: a profile page opens only for its owner and for staff.
		add_action( 'template_redirect', array( __CLASS__, 'private_profiles' ), 0 );
		// Honeypot ("bot trap"): hidden fields real visitors never see.
		add_action( 'init', array( __CLASS__, 'trap_check' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'trap_page' ), 0 );
		foreach ( array( 'register_form', 'woocommerce_register_form', 'tutor_student_reg_form_end', 'tutor_instructor_reg_form_end', 'xoo_el_register_form_end', 'xoo_el_form_end', 'comment_form_logged_in_after', 'comment_form_after_fields' ) as $h ) {
			add_action( $h, array( __CLASS__, 'trap_fields' ) );
		}
		add_action( 'wp_footer', array( __CLASS__, 'trap_js' ), 99 );
		// The old WPCode snippet "Block Spam Registrations" (allow-list of e-mail providers) refused real Gmail
		// sign-ups on the Tutor form and silently deleted students with school/work e-mails. This guard replaces it.
		add_action( 'init', array( __CLASS__, 'retire_snippet' ), 999 );
		add_action( 'wp_loaded', array( __CLASS__, 'retire_snippet' ), 1 );
		add_action( 'user_register', array( __CLASS__, 'after_signup' ), 5 );
		// Hide the user list from outsiders (bots collect user names from ?author=1 and the REST API).
		add_action( 'template_redirect', array( __CLASS__, 'no_author_scan' ), 1 );
		add_filter( 'rest_endpoints', array( __CLASS__, 'hide_users_api' ) );
	}

	public static function retire_snippet() {
		if ( function_exists( 'sds_block_spam_domains' ) ) {
			remove_filter( 'registration_errors', 'sds_block_spam_domains', 10 );
			remove_filter( 'woocommerce_registration_errors', 'sds_block_spam_domains', 10 );
		}
		if ( function_exists( 'sds_remove_spam_domain_user' ) ) { remove_action( 'user_register', 'sds_remove_spam_domain_user', 1 ); }
	}

	/* ------------------------------------------------------------------ *
	 * Limits: sign-ups per network address, too-fast forms, fake domains
	 * ------------------------------------------------------------------ */

	const PER_HOUR  = 3;   // accounts from one network address per hour
	const PER_DAY   = 6;   // ... per day
	const MIN_SECS  = 3;   // a person needs at least this long to fill the form
	const SURGE     = 20;  // e-mail the school when this many accounts are made in one day

	protected static function ip() { return sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); } // phpcs:ignore

	protected static function ip_count( $span ) { return (int) get_transient( 'sdsc_su_' . $span . '_' . md5( self::ip() ) ); }

	/** Throwaway inbox services (sign-ups only; real students do not use these). */
	/** Well-known providers and school / government addresses: always welcome. */
	protected static function common( $domain ) {
		$big = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'rocketmail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'comcast.net', 'verizon.net', 'att.net', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'zoho.com', 'yandex.com', 'mcpsmd.net', 'mcpsmd.org' );
		if ( in_array( $domain, $big, true ) ) { return true; }
		// Colleges, universities and school systems (also their student sub-domains, e.g. terpmail.umd.edu, students.pgcps.org).
		$schools = array( 'mcpsmd.net', 'mcpsmd.org', 'pgcps.org', 'fcps.edu', 'hcpss.org', 'aacps.org', 'bcps.org', 'fcpsmd.org', 'ccps.org', 'wcps.k12.md.us', 'apsva.us', 'lcps.org', 'k12.dc.us', 'dc.gov', 'mymail.mc.edu', 'montgomerycollege.edu', 'umd.edu', 'umbc.edu', 'towson.edu', 'gwu.edu', 'georgetown.edu', 'howard.edu', 'american.edu', 'jhu.edu', 'gmu.edu', 'udc.edu', 'catholic.edu', 'nvcc.edu', 'pgcc.edu', 'aacc.edu', 'ccbcmd.edu', 'hcc.edu', 'frederick.edu' );
		foreach ( $schools as $sd ) { if ( $domain === $sd || substr( $domain, -strlen( '.' . $sd ) ) === '.' . $sd ) { return true; } }
		if ( preg_match( '/\.(edu|ac|k12|sch|school)\.[a-z]{2}$|\.k12\.[a-z]{2}\.us$/', $domain ) ) { return true; } // edu.au, ac.uk, k12.md.us ...
		if ( preg_match( '/^(yahoo|hotmail|outlook|live)\.(co|com)\.[a-z]{2}$|^(yahoo|hotmail|outlook|live|gmail)\.[a-z]{2}$/', $domain ) ) { return true; }
		return (bool) preg_match( '/\.(edu|gov|mil|us)$/', $domain );
	}

	/** name@x.something.com — real people almost never use an address on a sub-domain; bot waves do. */
	protected static function sub_domain( $domain ) {
		if ( self::common( $domain ) ) { return false; }
		$p = explode( '.', $domain );
		$n = count( $p );
		$base = ( $n >= 3 && 2 === strlen( $p[ $n - 1 ] ) && in_array( $p[ $n - 2 ], array( 'co', 'com', 'org', 'net', 'ac', 'gov', 'edu' ), true ) ) ? 3 : 2; // example.co.uk
		return $n > $base;
	}

	/** Four or more new accounts in 30 minutes = a wave: for one hour only well-known providers may sign up. */
	protected static function busy() {
		if ( get_transient( 'sdsc_signup_busy' ) ) { return true; }
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s", gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) ) );
		if ( $n >= 4 ) { set_transient( 'sdsc_signup_busy', 1, HOUR_IN_SECONDS ); return true; }
		return false;
	}

	protected static function disposable() {
		return array( 'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com', 'temp-mail.org', 'tempmail.com', 'tempmail.net', 'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mohmal.com', 'emailondeck.com', 'mintemail.com', 'spamgourmet.com', 'mailnesia.com', 'tempinbox.com', 'burnermail.io', 'moakt.com', 'tmail.ws', 'tmpmail.org', 'mail.tm', '1secmail.com', 'linshiyouxiang.net' );
	}

	/** The e-mail domain must exist and accept mail (fake domains are refused). Cached for a day. */
	protected static function domain_ok( $domain ) {
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com', 'live.com', 'msn.com', 'me.com', 'ymail.com', 'protonmail.com', 'proton.me', 'comcast.net', 'verizon.net' ), true ) ) { return true; }
		if ( ! function_exists( 'checkdnsrr' ) ) { return true; }
		$k = 'sdsc_mx_' . md5( $domain );
		$v = get_transient( $k );
		if ( false === $v ) {
			$v = ( checkdnsrr( $domain . '.', 'MX' ) || checkdnsrr( $domain . '.', 'A' ) ) ? 'y' : 'n';
			set_transient( $k, $v, DAY_IN_SECONDS );
		}
		return 'y' === $v;
	}

	/** Extra checks for a sign-up happening right now (on top of the e-mail checks). */
	protected static function request_reason() {
		if ( is_user_logged_in() && current_user_can( 'list_users' ) ) { return ''; } // staff adding a student
		if ( self::ip_count( 'h' ) >= self::PER_HOUR || self::ip_count( 'd' ) >= self::PER_DAY ) { return 'too many sign-ups from one network'; }
		if ( isset( $_POST['sdsc_hp_t'] ) && (int) $_POST['sdsc_hp_t'] < self::MIN_SECS ) { return 'form sent too fast (bot)'; } // phpcs:ignore
		return '';
	}

	/** After an account is made: count it, catch forms that skip the checks, warn the school on a surge. */
	public static function after_signup( $user_id ) {
		if ( is_user_logged_in() && current_user_can( 'list_users' ) ) { return; }
		if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }
		foreach ( array( 'h' => HOUR_IN_SECONDS, 'd' => DAY_IN_SECONDS ) as $span => $ttl ) {
			$k = 'sdsc_su_' . $span . '_' . md5( self::ip() );
			set_transient( $k, (int) get_transient( $k ) + 1, $ttl );
		}
		$u = get_userdata( $user_id );
		$why = $u ? self::reason( $u->user_email ) : '';
		if ( $why ) { // a form that bypassed the sign-up checks: remove the account it just made
			self::log( $u->user_email, $why . ' (removed after sign-up)' );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
			return;
		}
		$day = current_time( 'Y-m-d' );
		$c   = get_option( 'sdsc_signups_day', array() );
		$c   = ( is_array( $c ) && ( $c['d'] ?? '' ) === $day ) ? $c : array( 'd' => $day, 'n' => 0, 'mailed' => 0 );
		$c['n']++;
		update_user_meta( $user_id, '_sdsc_signup_ip', self::ip() );
		update_user_meta( $user_id, '_sdsc_signup_ua', mb_substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 400 ) ); // phpcs:ignore
		if ( $c['n'] >= self::SURGE && ! $c['mailed'] ) {
			$c['mailed'] = 1;
			$recent = get_users( array( 'orderby' => 'registered', 'order' => 'DESC', 'number' => 25, 'date_query' => array( array( 'after' => $day . ' 00:00:00', 'inclusive' => true ) ) ) );
			$lines  = array();
			$ips    = array();
			foreach ( $recent as $ru ) {
				$rip     = (string) get_user_meta( $ru->ID, '_sdsc_signup_ip', true );
				$lines[] = $ru->user_registered . ' · ' . $ru->user_login . ' · ' . $ru->user_email . ( $rip ? ' · IP ' . $rip : '' );
				if ( $rip ) { $ips[ $rip ] = ( $ips[ $rip ] ?? 0 ) + 1; }
			}
			arsort( $ips );
			$top = array();
			foreach ( array_slice( $ips, 0, 5, true ) as $k => $n ) { $top[] = $k . ' (' . $n . ')'; }
			$log = array();
			foreach ( array_slice( (array) get_option( self::LOG, array() ), 0, 10 ) as $l ) { $log[] = ( $l['t'] ?? '' ) . ' · ' . ( $l['email'] ?? '' ) . ' · ' . ( $l['why'] ?? '' ); }
			if ( class_exists( 'SDSC_Mailer' ) ) {
				SDSC_Mailer::admin_report( array(
					'subject_en' => 'Unusual number of new accounts today (' . $c['n'] . ')',
					'subject_fa' => 'تعداد غیرعادی حساب جدید',
					'title_en'   => $c['n'] . ' new accounts today',
					'title_fa'   => $c['n'] . ' حساب جدید در امروز',
					'lead_en'    => 'More accounts than usual were created on the website today. If you did not expect this, check the list below.',
					'lead_fa'    => 'امروز بیش از حد معمول حساب کاربری در سایت ساخته شده. اگر انتظارش را نداشتید، فهرست زیر را بررسی کنید.',
					'rows'       => array(
						array( 'en' => 'Date', 'fa' => 'تاریخ', 'v' => $day, 'ltr' => true ),
						array( 'en' => 'Accounts today', 'fa' => 'حساب‌های امروز', 'v' => (string) $c['n'], 'ltr' => true ),
						array( 'en' => 'Busiest networks (IP)', 'fa' => 'پرتکرارترین IPها', 'v' => implode( "\n", $top ), 'ltr' => true ),
						array( 'en' => 'Latest new accounts', 'fa' => 'آخرین حساب‌های جدید', 'v' => implode( "\n", $lines ), 'ltr' => true ),
						array( 'en' => 'Latest refused sign-ups', 'fa' => 'آخرین ثبت‌نام‌های ردشده', 'v' => implode( "\n", $log ), 'ltr' => true ),
					),
					'note_en'    => 'Limits already in place: 3 accounts per network per hour, 6 per day.',
					'note_fa'    => 'محدودیت فعلی: از هر IP حداکثر ۳ حساب در ساعت و ۶ حساب در روز.',
					'buttons'    => array(
						array( 'en' => 'Suspicious accounts', 'fa' => 'حساب‌های مشکوک', 'url' => admin_url( 'admin.php?page=sdsc-suspicious' ) ),
						array( 'en' => 'Blocked sign-ups', 'fa' => 'ثبت‌نام‌های مسدود', 'url' => admin_url( 'admin.php?page=sdsc-blocklist' ) ),
					),
				) );
			}
		}
		update_option( 'sdsc_signups_day', $c, false );
	}

	public static function no_author_scan() {
		if ( is_user_logged_in() ) { return; }
		if ( isset( $_GET['author'] ) || is_author() ) { wp_safe_redirect( home_url( '/' ), 301 ); exit; } // phpcs:ignore
	}

	/** Tutor profile pages (/profile/name/) and author pages: only the owner and staff; everyone else (and Google) gets "not found". */
	public static function private_profiles() {
		$name = (string) get_query_var( 'tutor_profile_username' );
		$is_profile = '' !== $name || is_author();
		if ( ! $is_profile ) { return; }
		$owner = '' !== $name ? get_user_by( 'slug', $name ) : get_queried_object();
		if ( is_user_logged_in() && ( ( $owner instanceof WP_User && get_current_user_id() === (int) $owner->ID ) || ( class_exists( 'SDSC_Access' ) && SDSC_Access::is_staff() ) ) ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		$tpl = get_query_template( '404' );
		if ( $tpl ) { include $tpl; } else { echo '<!doctype html><title>Not found</title><p>Page not found.</p>'; }
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Pause / release an account (office and administrators)
	 * ------------------------------------------------------------------ */

	const PAUSED = '_sdsc_paused';

	public static function paused( $uid ) { return get_user_meta( (int) $uid, self::PAUSED, true ); }

	/** Staff and instructors are never paused from here. */
	protected static function protected_user( $u ) { return $u && ( user_can( $u, 'edit_posts' ) || user_can( $u, 'manage_options' ) || array_intersect( (array) $u->roles, array( 'administrator', 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor', 'sdsc_manager', 'sdsc_front_desk' ) ) ); }

	public static function stop_paused( $user ) {
		if ( $user instanceof WP_User && self::paused( $user->ID ) ) {
			return new WP_Error( 'sdsc_paused', 'This account is paused. Please call Sam’s Driving School at (301) 726-3030 and we will open it for you.' );
		}
		return $user;
	}

	public static function pause( $uid, $why = '' ) {
		$u = get_userdata( $uid );
		if ( ! $u || self::protected_user( $u ) ) { return false; }
		update_user_meta( $uid, self::PAUSED, array( 'by' => wp_get_current_user()->display_name, 'at' => current_time( 'mysql' ), 'why' => $why ) );
		if ( class_exists( 'WP_Session_Tokens' ) ) { WP_Session_Tokens::get_instance( $uid )->destroy_all(); } // signed out everywhere
		return true;
	}

	public static function release( $uid ) { delete_user_meta( (int) $uid, self::PAUSED ); return true; }

	public static function account_url( $do, $uid = 0 ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'sdsc_account', 'do' => $do, 'u' => (int) $uid ), admin_url( 'admin-post.php' ) ), 'sdsc_account_' . $do . '_' . (int) $uid );
	}

	public static function account_action() {
		$do  = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore
		$uid = absint( $_GET['u'] ?? 0 ); // phpcs:ignore
		if ( ! SDSC_Access::can( 'blocklist' ) ) { wp_die( 'You do not have permission to do this.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_account_' . $do . '_' . $uid );
		$n = 0;
		if ( 'pause' === $do ) { $n = (int) self::pause( $uid, 'paused by the office' ); }
		elseif ( 'release' === $do ) { $n = (int) self::release( $uid ); }
		elseif ( 'pause_wave' === $do || 'pause_all' === $do ) {
			foreach ( self::suspicious() as $x ) {
				if ( self::paused( $x['id'] ) ) { continue; }
				$wave = (bool) array_filter( $x['flags'], function ( $f ) { return 0 === strpos( $f, 'Bot wave' ); } );
				if ( 'pause_all' === $do || $wave ) { $n += (int) self::pause( $x['id'], $wave ? 'bot wave' : 'no activity' ); }
			}
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=sdsc-suspicious' );
		wp_safe_redirect( add_query_arg( array( 'sdsc_done' => $do, 'sdsc_n' => $n ), remove_query_arg( array( 'sdsc_done', 'sdsc_n' ), $back ) ) );
		exit;
	}

	/** Users screen: "Pause account" / "Release account" under each student. */
	public static function user_row( $actions, $u ) {
		if ( ! class_exists( 'SDSC_Access' ) || ! SDSC_Access::can( 'blocklist' ) || self::protected_user( $u ) ) { return $actions; }
		$actions['sdsc_pause'] = self::paused( $u->ID )
			? '<a style="color:#0b6b35;font-weight:700" href="' . esc_url( self::account_url( 'release', $u->ID ) ) . '">Release account</a>'
			: '<a style="color:#b32d2e" href="' . esc_url( self::account_url( 'pause', $u->ID ) ) . '">Pause account</a>';
		return $actions;
	}

	public static function hide_users_api( $endpoints ) {
		if ( is_user_logged_in() ) { return $endpoints; }
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) { unset( $endpoints[ $route ] ); }
		}
		return $endpoints;
	}

	/* ------------------------------------------------------------------ *
	 * Bot trap (honeypot)
	 *
	 * Every sign-up form gets an invisible "I'm not a robot" checkbox and an invisible text box.
	 * They are moved off-screen, hidden from screen readers (aria-hidden) and skipped by the keyboard
	 * (tabindex -1), so a real person never meets them. Bots fill in every field they find: when
	 * either field comes back filled, the sign-up is refused, the email is added to the blocklist,
	 * and the bot is sent to an empty dead-end page with no links or forms.
	 * ------------------------------------------------------------------ */

	const HP_BOX  = 'sdsc_hp_human';
	const HP_TEXT = 'sdsc_hp_url';

	public static function trap_fields() {
		static $done = 0;
		if ( $done++ > 3 ) { return; }
		echo '<div class="sdsc-hpot" aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
			. '<label>I\'m not a robot <input type="checkbox" name="' . self::HP_BOX . '" value="1" tabindex="-1" autocomplete="off"></label>'
			. '<label>Your website <input type="text" name="' . self::HP_TEXT . '" value="" tabindex="-1" autocomplete="off"></label></div>';
	}

	/** Adds the same hidden fields to sign-up forms built by JavaScript (pop-ups) and to any form with a password box. */
	public static function trap_js() {
		if ( is_user_logged_in() ) { return; }
		?>
		<script>(function(){var B=<?php echo wp_json_encode( self::HP_BOX ); ?>,T=<?php echo wp_json_encode( self::HP_TEXT ); ?>;function add(f){if(f.querySelector('[name="'+B+'"]')){if(!f.querySelector('[name=sdsc_hp_t]')){var t=document.createElement('input');t.type='hidden';t.name='sdsc_hp_t';t.value=S;f.appendChild(t);}return;}var d=document.createElement('div');d.className='sdsc-hpot';d.setAttribute('aria-hidden','true');d.style.cssText='position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;';d.innerHTML='<label>I\'m not a robot <input type="checkbox" name="'+B+'" value="1" tabindex="-1" autocomplete="off"></label><label>Your website <input type="text" name="'+T+'" value="" tabindex="-1" autocomplete="off"></label><input type="hidden" name="sdsc_hp_t" value="'+S+'">';f.appendChild(d);}var S=0;setInterval(function(){S++;[].forEach.call(document.querySelectorAll('input[name=sdsc_hp_t]'),function(i){i.value=S;});},1000);function scan(){[].forEach.call(document.querySelectorAll('form'),function(f){if(f.querySelector('input[type=password],input[name*=email i],input[type=email]'))add(f);});}scan();if(window.MutationObserver){new MutationObserver(function(){clearTimeout(scan.t);scan.t=setTimeout(scan,300);}).observe(document.body,{childList:true,subtree:true});}})();</script>
		<?php
	}

	public static function trap_check() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || is_user_logged_in() ) { return; } // phpcs:ignore
		$hit = ! empty( $_POST[ self::HP_BOX ] ) || ( isset( $_POST[ self::HP_TEXT ] ) && '' !== trim( (string) wp_unslash( $_POST[ self::HP_TEXT ] ) ) ) // phpcs:ignore
			|| ( isset( $_POST['sdsc_website'] ) && '' !== trim( (string) wp_unslash( $_POST['sdsc_website'] ) ) ); // phpcs:ignore
		if ( ! $hit ) { return; }
		$email = '';
		foreach ( array( 'user_email', 'email', 'xoo_el_reg_email', 'billing_email', 'sdsc_email' ) as $k ) {
			if ( ! empty( $_POST[ $k ] ) ) { $email = sanitize_email( wp_unslash( $_POST[ $k ] ) ); break; } // phpcs:ignore
		}
		$ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
		self::log( $email ? $email : '(no email)', 'bot trap' . ( $ip ? ' · IP ' . $ip : '' ) );
		if ( $email ) { self::add( array( $email ), array() ); } // block this exact address (Gmail look-alikes included)
		$url = add_query_arg( 'sdsc-hold', wp_generate_password( 10, false, false ), home_url( '/' ) );
		if ( wp_doing_ajax() || ( isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT'], 'json' ) ) ) { // phpcs:ignore
			wp_send_json( array( 'error' => 1, 'redirect' => $url, 'notice' => '' ), 200 );
		}
		wp_redirect( $url, 303 );
		exit;
	}

	/** The dead-end page: nothing to click, nothing to submit, not indexed. */
	public static function trap_page() {
		if ( empty( $_GET['sdsc-hold'] ) ) { return; } // phpcs:ignore
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		status_header( 200 );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Please wait</title>'
			. '<style>body{margin:0;display:grid;place-items:center;height:100vh;font:16px system-ui,sans-serif;color:#555;background:#f4f4f4}i{display:block;width:34px;height:34px;margin:0 auto 14px;border:3px solid #ccc;border-top-color:#777;border-radius:50%;animation:r 1s linear infinite}@keyframes r{to{transform:rotate(360deg)}}</style>'
			. '</head><body><div><i></i>Processing your request…</div></body></html>';
		exit;
	}

	public static function lists() {
		$o = get_option( self::OPT, array() );
		$o = is_array( $o ) ? $o : array();
		return array(
			'emails'  => array_values( array_filter( (array) ( $o['emails'] ?? array() ) ) ),
			'domains' => array_values( array_filter( (array) ( $o['domains'] ?? array() ) ) ),
		);
	}

	/** Lower-case; for Gmail drop dots and +tags so look-alike addresses match. */
	public static function norm( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( false === strpos( $email, '@' ) ) { return $email; }
		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( 'googlemail.com' === $domain ) { $domain = 'gmail.com'; }
		if ( 'gmail.com' === $domain ) {
			$local = str_replace( '.', '', preg_replace( '/\+.*/', '', $local ) );
		}
		return $local . '@' . $domain;
	}

	/** Reason the address is refused, or '' when it is fine. */
	public static function reason( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || false === strpos( $email, '@' ) ) { return ''; }
		list( $local, $domain ) = explode( '@', $email, 2 );
		$l = self::lists();
		foreach ( $l['domains'] as $d ) {
			$d = ltrim( strtolower( trim( $d ) ), '@.' );
			if ( $d && ( $domain === $d || substr( $domain, -strlen( '.' . $d ) ) === '.' . $d ) ) { return 'domain ' . $d; }
		}
		$n = self::norm( $email );
		foreach ( $l['emails'] as $e ) {
			if ( self::norm( $e ) === $n ) { return 'blocked address'; }
		}
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) && substr_count( preg_replace( '/\+.*/', '', $local ), '.' ) >= 4 ) {
			return 'gmail dot trick';
		}
		if ( in_array( $domain, self::disposable(), true ) ) { return 'throwaway e-mail service'; }
		if ( self::sub_domain( $domain ) ) { return 'e-mail on a sub-domain (bot pattern)'; }
		if ( self::busy() && ! self::common( $domain ) ) { return 'many new accounts right now: only well-known e-mail providers for the next hour'; }
		if ( ! self::common( $domain ) && ! self::domain_ok( $domain ) ) { return 'e-mail domain does not exist'; } // well-known providers and schools: no DNS lookup needed
		return '';
	}

	protected static function refuse( $err, $email ) {
		$why = self::request_reason();
		if ( $why ) {
			self::log( $email ? $email : '(no email)', $why . ( self::ip() ? ' · IP ' . self::ip() : '' ) );
			if ( is_wp_error( $err ) ) { $err->add( 'sdsc_limit', 'We could not create the account right now. Please try again later or call us at (301) 726-3030.' ); }
			return $err;
		}
		$why = self::reason( $email );
		if ( ! $why ) { return $err; }
		self::log( $email, $why );
		if ( is_wp_error( $err ) ) { $err->add( 'sdsc_blocked', self::MSG ); }
		return $err;
	}

	public static function wp_errors( $err, $login = '', $email = '' ) {
		// Tutor LMS calls this filter with empty values; its form posts the address as "email".
		if ( '' === (string) $email && isset( $_POST['email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$email = sanitize_email( wp_unslash( $_POST['email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( '' === (string) $email && isset( $_POST['user_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$email = sanitize_email( wp_unslash( $_POST['user_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return self::refuse( $err, $email );
	}

	public static function wc_errors( $err, $username = '', $password = '', $email = '' ) {
		return self::refuse( $err, $email );
	}

	protected static function log( $email, $why ) {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, array( 't' => current_time( 'mysql' ), 'email' => sanitize_text_field( $email ), 'why' => $why ) );
		update_option( self::LOG, array_slice( $log, 0, 100 ), false );
	}

	/** Adds addresses/domains to the list (used when deleting spam accounts). */
	public static function add( $emails = array(), $domains = array() ) {
		$l = self::lists();
		$l['emails']  = array_values( array_unique( array_merge( $l['emails'], array_map( 'strtolower', array_map( 'trim', $emails ) ) ) ) );
		$l['domains'] = array_values( array_unique( array_merge( $l['domains'], array_map( 'strtolower', array_map( 'trim', $domains ) ) ) ) );
		update_option( self::OPT, $l, false );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Blocked sign-ups', 'Blocked sign-ups', 'sdsc_access_blocklist', 'sdsc-blocklist', array( __CLASS__, 'page' ) );
		add_submenu_page( 'sdsc-dashboard', 'Suspicious accounts', 'Suspicious accounts', 'sdsc_access_blocklist', 'sdsc-suspicious', array( __CLASS__, 'suspicious_page' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Suspicious accounts: student-type accounts that never did anything
	 * (no class registration, no course, no quiz, no order) — a review list only, nothing is deleted here.
	 * ------------------------------------------------------------------ */

	/** "Windows · Chrome" style label from a browser user-agent. */
	public static function device( $ua ) {
		$ua = (string) $ua;
		if ( '' === $ua ) { return ''; }
		if ( preg_match( '/bot|crawl|spider|curl|wget|python|headless|phantom|httpclient|java\//i', $ua ) ) { return 'Bot / script (' . mb_substr( $ua, 0, 40 ) . ')'; }
		$os = 'Other';
		foreach ( array( 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows NT' => 'Windows', 'Mac OS X' => 'Mac', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux' ) as $k => $v ) { if ( false !== stripos( $ua, $k ) ) { $os = $v; break; } }
		$br = 'Other';
		foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung', 'Firefox/' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari' ) as $k => $v ) { if ( false !== stripos( $ua, $k ) ) { $br = $v; break; } }
		return $os . ' · ' . $br;
	}

	/** Last known network address, country and device for a user (sign-up record, then Wordfence's login log). */
	public static function trace( $uid ) {
		global $wpdb;
		$ip = (string) get_user_meta( $uid, '_sdsc_signup_ip', true );
		$ua = (string) get_user_meta( $uid, '_sdsc_signup_ua', true );
		static $wf = null;
		if ( null === $wf ) { $wf = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wflogins'" ); }
		$when = '';
		if ( $wf ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT IP, UA, ctime FROM {$wpdb->prefix}wflogins WHERE userID = %d ORDER BY id DESC LIMIT 1", $uid ) ); // phpcs:ignore
			if ( $row ) {
				if ( '' === $ip && class_exists( 'wfUtils' ) ) { $ip = (string) wfUtils::inet_ntop( $row->IP ); }
				if ( '' === $ua ) { $ua = (string) $row->UA; }
				$when = $row->ctime ? wp_date( 'M j, Y g:i a', (int) $row->ctime ) : '';
			}
		}
		$cc = ( $ip && class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'IP2Country' ) ) ? (string) wfUtils::IP2Country( $ip ) : '';
		return array( 'ip' => $ip, 'country' => $cc, 'device' => self::device( $ua ), 'ua' => $ua, 'seen' => $when );
	}

	public static function suspicious() {
		global $wpdb;
		$reg   = class_exists( 'SDSC_Registration' ) ? SDSC_Registration::table() : '';
		$has   = function ( $t ) use ( $wpdb ) { return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); };
		$where = array(
			"NOT EXISTS (SELECT 1 FROM {$wpdb->posts} e WHERE e.post_type = 'tutor_enrolled' AND e.post_author = u.ID)",
			"NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.post_author = u.ID AND p.post_type NOT IN ('tutor_enrolled','revision'))",
		);
		if ( $has( $wpdb->prefix . 'tutor_quiz_attempts' ) ) { $where[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}tutor_quiz_attempts a WHERE a.user_id = u.ID)"; }
		if ( $reg && $has( $reg ) ) { $where[] = "NOT EXISTS (SELECT 1 FROM $reg r WHERE LOWER(r.email) = LOWER(u.user_email) OR LOWER(r.parent_email) = LOWER(u.user_email))"; }
		if ( $has( $wpdb->prefix . 'wc_orders' ) ) { $where[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_orders o WHERE o.customer_id = u.ID)"; }
		$where[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.meta_key = '_customer_user' AND m.meta_value = u.ID)";
		$rows = $wpdb->get_results( "SELECT u.ID, u.user_login, u.user_email, u.display_name, u.user_registered FROM {$wpdb->users} u WHERE " . implode( ' AND ', $where ) . ' ORDER BY u.user_registered DESC' ); // phpcs:ignore
		$days = $wpdb->get_results( "SELECT DATE(user_registered) d, COUNT(*) n FROM {$wpdb->users} GROUP BY d HAVING n >= 10", OBJECT_K ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$u = get_userdata( $r->ID );
			if ( ! $u || array_diff( (array) $u->roles, array( 'subscriber', 'customer', 'tutor_student' ) ) || user_can( $u, 'edit_posts' ) ) { continue; } // staff and other roles are never listed
			$flags = array();
			$why   = self::reason( $r->user_email );
			if ( $why ) { $flags[] = $why; }
			$d = substr( $r->user_registered, 0, 10 );
			if ( isset( $days[ $d ] ) ) { $flags[] = 'Bot wave: created on ' . mysql2date( 'M j, Y', $d ) . ', when ' . (int) $days[ $d ]->n . ' accounts were made in one day'; }
			if ( preg_match( '/^[a-z]{2,}[0-9]{4,}$|^[a-z0-9]{14,}$/i', $r->user_login ) ) { $flags[] = 'Random-looking user name'; }
			$tr = self::trace( (int) $r->ID );
			if ( $tr['country'] && ! in_array( $tr['country'], array( 'US' ), true ) ) { $flags[] = 'Outside the US (' . $tr['country'] . ')'; }
			if ( 0 === strpos( $tr['device'], 'Bot' ) ) { $flags[] = 'Signed in with a script, not a browser'; }
			$out[] = array( 'id' => (int) $r->ID, 'login' => $r->user_login, 'email' => $r->user_email, 'name' => $r->display_name, 'registered' => $r->user_registered, 'flags' => $flags ) + $tr;
		}
		return $out;
	}

	public static function suspicious_page() {
		if ( ! SDSC_Access::can( 'blocklist' ) ) { return; }
		$list   = self::suspicious();
		$strong = count( array_filter( $list, function ( $x ) { return ! empty( $x['flags'] ); } ) );
		echo '<div class="wrap"><h1>Suspicious accounts</h1>';
		echo '<p>Student-type accounts that have <b>never done anything</b> on the website: no class registration, no online course, no quiz, no order. Staff and instructors are never listed. Nothing is deleted from this page — review first.</p>';
		echo '<p><b>' . count( $list ) . '</b> accounts with no activity · <b>' . (int) $strong . '</b> of them also have a warning sign (see the last column).</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_suspicious_csv' ), 'sdsc_suspicious_csv' ) ) . '">Download as CSV (Excel)</a></p>';
		echo '<div style="max-width:900px;margin:10px 0 16px;padding:12px 16px;border-radius:10px;background:#fff8e1;border:1px solid #ffd766"><b>What the warning signs mean</b><ul style="margin:6px 0 0 18px;list-style:disc">'
			. '<li><b>Bot wave</b> — the account was made on a day when many accounts (10 or more) were created at once. Real students sign up one by one, so these are almost always bots.</li>'
			. '<li><b>Random-looking user name</b> — letters followed by many numbers, or a long jumble of characters.</li>'
			. '<li><b>Throwaway e-mail service / e-mail domain does not exist / blocked</b> — the e-mail address itself would be refused today.</li>'
			. '<li><b>—</b> (no sign) — the account never did anything, but nothing else looks wrong. It may be a real person who signed up and never came back.</li>'
			. '</ul></div>';
		if ( isset( $_GET['sdsc_done'] ) ) { // phpcs:ignore
			$n = (int) ( $_GET['sdsc_n'] ?? 0 ); // phpcs:ignore
			echo '<div class="notice notice-success"><p>' . ( 'release' === $_GET['sdsc_done'] ? 'Account released — it can sign in again.' : esc_html( $n . ' account' . ( 1 === $n ? '' : 's' ) . ' paused — they cannot sign in until released.' ) ) . '</p></div>'; // phpcs:ignore
		}
		echo '<p><b>Pause</b> = the account stays, but cannot sign in (the person is asked to call the school). <b>Release</b> = it can sign in again. Nothing is deleted.</p>';
		echo '<p><a class="button" onclick="return confirm(\'Pause every account with a Bot wave sign?\')" href="' . esc_url( self::account_url( 'pause_wave' ) ) . '">Pause all “Bot wave” accounts</a> <a class="button" onclick="return confirm(\'Pause every account on this list?\')" href="' . esc_url( self::account_url( 'pause_all' ) ) . '">Pause all accounts on this list</a></p>';
		echo '<p style="color:#555">IP, country and device come from the sign-up record or the last sign-in that Wordfence logged. A MAC address never travels over the internet, so no website can see it. City-level location needs an outside lookup service and is not used.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Created</th><th>User name</th><th>E-mail</th><th>Name</th><th>IP · Country</th><th>Device (system · browser)</th><th>Warning signs</th><th>Status</th></tr></thead><tbody>';
		foreach ( $list as $x ) {
			$pz = self::paused( $x['id'] );
			echo '<tr><td>' . esc_html( mysql2date( 'M j, Y', $x['registered'] ) ) . '</td><td><a href="' . esc_url( get_edit_user_link( $x['id'] ) ) . '">' . esc_html( $x['login'] ) . '</a></td><td>' . esc_html( $x['email'] ) . '</td><td>' . esc_html( $x['name'] ) . '</td><td>' . ( $x['ip'] ? esc_html( $x['ip'] . ( $x['country'] ? ' · ' . $x['country'] : '' ) ) . ( $x['seen'] ? '<br><small>last sign-in ' . esc_html( $x['seen'] ) . '</small>' : '' ) : '<small>no record</small>' ) . '</td><td title="' . esc_attr( $x['ua'] ) . '">' . ( $x['device'] ? esc_html( $x['device'] ) : '<small>no record</small>' ) . '</td><td>' . ( $x['flags'] ? '<b style="color:#b32d2e">' . esc_html( implode( ' · ', $x['flags'] ) ) . '</b>' : '—' ) . '</td>'
				. '<td>' . ( $pz ? '<span style="color:#b32d2e;font-weight:700">Paused</span><br><a class="button button-small button-primary" href="' . esc_url( self::account_url( 'release', $x['id'] ) ) . '">Release account</a>' : '<span style="color:#0b6b35">Active</span><br><a class="button button-small" href="' . esc_url( self::account_url( 'pause', $x['id'] ) ) . '">Pause</a>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		// Paused accounts that are not on the list above (paused from the Users screen, or they had activity).
		$ids   = wp_list_pluck( $list, 'id' );
		$other = get_users( array( 'meta_key' => self::PAUSED, 'meta_compare' => 'EXISTS', 'exclude' => $ids, 'number' => 500 ) );
		if ( $other ) {
			echo '<h2 style="margin-top:24px">Other paused accounts</h2><table class="widefat striped"><thead><tr><th>User name</th><th>E-mail</th><th>Paused</th><th></th></tr></thead><tbody>';
			foreach ( $other as $o ) {
				$pz = (array) self::paused( $o->ID );
				echo '<tr><td><a href="' . esc_url( get_edit_user_link( $o->ID ) ) . '">' . esc_html( $o->user_login ) . '</a></td><td>' . esc_html( $o->user_email ) . '</td><td>' . esc_html( ( $pz['at'] ?? '' ) . ( ! empty( $pz['by'] ) ? ' · by ' . $pz['by'] : '' ) ) . '</td><td><a class="button button-small button-primary" href="' . esc_url( self::account_url( 'release', $o->ID ) ) . '">Release account</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function suspicious_csv() {
		if ( ! SDSC_Access::can( 'blocklist' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_suspicious_csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="suspicious-accounts-' . current_time( 'Y-m-d' ) . '.csv"' );
		$f = fopen( 'php://output', 'w' );
		fputcsv( $f, array( 'User ID', 'User name', 'E-mail', 'Name', 'Created', 'IP', 'Country', 'Device', 'Browser details', 'Last sign-in', 'Warning signs', 'Status' ) );
		foreach ( self::suspicious() as $x ) {
			$cells = array( $x['id'], $x['login'], $x['email'], $x['name'], $x['registered'], $x['ip'], $x['country'], $x['device'], $x['ua'], $x['seen'], implode( ' · ', $x['flags'] ), self::paused( $x['id'] ) ? 'Paused' : 'Active' );
			$cells = array_map( function ( $c ) { $c = (string) $c; return preg_match( '/^[=+\-@]/', $c ) ? "'" . $c : $c; }, $cells ); // no spreadsheet formulas
			fputcsv( $f, $cells );
		}
		fclose( $f );
		exit;
	}

	public static function save() {
		if ( ! SDSC_Access::can( 'blocklist' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_blocklist' );
		$split = function ( $k ) {
			$v = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
			return array_values( array_unique( array_filter( array_map( function ( $x ) { return strtolower( trim( $x ) ); }, preg_split( '/[\s,]+/', $v ) ) ) ) );
		};
		update_option( self::OPT, array( 'emails' => $split( 'emails' ), 'domains' => $split( 'domains' ) ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-blocklist&saved=1' ) );
		exit;
	}

	public static function page() {
		if ( ! SDSC_Access::can( 'blocklist' ) ) { return; }
		$l   = self::lists();
		$log = get_option( self::LOG, array() );
		?>
		<div class="wrap">
			<h1>Blocked sign-ups</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?><div class="notice notice-success"><p>Saved.</p></div><?php endif; ?>
			<p>Addresses and domains here cannot create an account on any form of the website (account sign-up, online course, sign-up pop-up). Gmail look-alikes (extra dots or “+” tags) are caught too, and Gmail addresses with 4 or more dots are refused automatically. Anyone refused is asked to call the school.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sdsc_blocklist_save" />
				<?php wp_nonce_field( 'sdsc_blocklist' ); ?>
				<table class="form-table">
					<tr><th><label for="sdsc-bl-e">Email addresses</label><br><small><?php echo count( $l['emails'] ); ?> blocked</small></th><td><textarea id="sdsc-bl-e" name="emails" rows="10" class="large-text code"><?php echo esc_textarea( implode( "\n", $l['emails'] ) ); ?></textarea><p class="description">One per line.</p></td></tr>
					<tr><th><label for="sdsc-bl-d">Domains</label><br><small><?php echo count( $l['domains'] ); ?> blocked</small></th><td><textarea id="sdsc-bl-d" name="domains" rows="6" class="large-text code"><?php echo esc_textarea( implode( "\n", $l['domains'] ) ); ?></textarea><p class="description">One per line, e.g. <code>ak6lay.shop</code>. Never add gmail.com, yahoo.com or similar — real students use them.</p></td></tr>
				</table>
				<?php submit_button( 'Save list' ); ?>
			</form>
			<h2>Refused attempts</h2>
			<?php if ( ! $log ) : ?>
				<p>None yet.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:800px"><thead><tr><th>When</th><th>Email</th><th>Reason</th></tr></thead><tbody>
				<?php foreach ( $log as $r ) : ?>
					<tr><td><?php echo esc_html( $r['t'] ); ?></td><td><?php echo esc_html( $r['email'] ); ?></td><td><?php echo esc_html( $r['why'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
