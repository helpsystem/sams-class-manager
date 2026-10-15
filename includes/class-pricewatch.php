<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Price watch (Sam's School → Price watch): the published prices of other Maryland driving schools next to
 * ours, checked automatically (every day … every week, or with the "Check now" button), kept as a history,
 * drawn on charts, and turned into plain advice. Administrator and school manager only.
 *
 * Honest by design: a new competitor price is only accepted after it is read the same way twice in a row
 * (or a person confirms it), so a page that is half loaded or laid out differently never shows as a "price
 * change". Every reading keeps the words it was taken from, and any price can be corrected by hand.
 */
class SDSC_PriceWatch {

	const PAGE    = 'sdsc-price-watch';
	const DBV     = '1';
	const DBO     = 'sdsc_pw_db';
	const SCHOOLS = 'sdsc_pw_schools';
	const PENDING = 'sdsc_pw_pending';
	const FREQ    = 'sdsc_pw_freq';     // hours between checks; 0 = off
	const STATE   = 'sdsc_pw_state';
	const HOOK    = 'sdsc_pw_tick';
	const BATCH   = 5;                  // schools checked per hourly tick
	const UA      = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

	/** key => [label, low, high] — the price range a reading must fall in to count. */
	public static function services() {
		return array(
			'course36' => array( "36-hour Driver's Ed", 200, 800 ),
			'btw2'     => array( 'Behind-the-wheel, 2 hours', 60, 260 ),
			'btw10'    => array( 'Behind-the-wheel, 10 hours', 280, 1000 ),
			'rental'   => array( 'Road test car', 60, 400 ),
			'roadway3' => array( '3-hour Roadway Safety', 40, 250 ),
			'dip'      => array( 'Driver Improvement (DIP)', 50, 300 ),
		);
	}

	/** Words that point at each service on a competitor's page. */
	protected static function patterns() {
		return array(
			'course36' => "/(36[\s\-]*(hour|hr)|driver'?s?\s*(ed\b|education)|30\s*\/\s*6|drivers?\s+ed\b)/i",
			'btw2'     => '/(\b2[\s\-]*(hours?|hrs?)\b|\btwo[\s\-]*hours?\b|\bone\s+(lesson|session)\b|\b1\s+(lesson|session)\b|2[\s\-]*hour\s+btw)/i',
			'btw10'    => '/(\b10[\s\-]*(hours?|hrs?)\b|\bten[\s\-]*hours?\b|\bfive\s+(lessons|sessions)\b|\b5\s+(lessons|sessions)\b|\(10h\))/i',
			'rental'   => '/(car\s+rental|rent(al)?\s+(a|the)?\s*(car|vehicle)|rent\s+vehicle|test\s+day\s+car|car\s+only|road\s*test\s+(car|escort|vehicle)|vehicle\s+for\s+(the\s+)?(driving|road)\s+test|mva\s+(road\s+)?test\s+escort)/i',
			'roadway3' => '/(roadway\s+safety|alcohol|alchohol|\bdrugs?\b|\brsep\b|3[\s\-]*hour\s+road)/i',
			'dip'      => '/(driver\s+improvement|\bdip\b)/i',
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 27 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'wp_ajax_sdsc_pw_check', array( __CLASS__, 'ajax_check' ) );
		foreach ( array( 'freq', 'school', 'price', 'pending', 'import', 'report' ) as $a ) {
			add_action( 'admin_post_sdsc_pw_' . $a, array( __CLASS__, 'h_' . $a ) );
		}
	}

	public static function can() {
		return class_exists( 'SDSC_Board' ) ? SDSC_Board::can() : current_user_can( 'manage_options' );
	}

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_pw_obs'; }

	public static function maybe_install() {
		if ( self::DBV === get_option( self::DBO ) ) { return; }
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			school varchar(40) NOT NULL,
			service varchar(20) NOT NULL,
			price decimal(8,2) NOT NULL,
			reg decimal(8,2) NULL,
			disc tinyint(1) NOT NULL DEFAULT 0,
			src varchar(10) NOT NULL DEFAULT 'auto',
			url varchar(255) NOT NULL DEFAULT '',
			snippet varchar(255) NOT NULL DEFAULT '',
			at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ss (school,service,at)
		) " . $wpdb->get_charset_collate() . ';' );
		if ( ! get_option( self::SCHOOLS ) ) { self::seed(); }
		update_option( self::DBO, self::DBV, false );
	}

	/* ------------------------------------------------------------------ data */

	public static function schools() {
		$s = get_option( self::SCHOOLS, array() );
		return is_array( $s ) ? $s : array();
	}

	protected static function save_schools( $s ) { update_option( self::SCHOOLS, $s, false ); }

	/** Latest accepted price per school and service: [school][service] => row. */
	public static function current() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT o.* FROM ' . self::table() . ' o JOIN (SELECT school, service, MAX(id) mid FROM ' . self::table() . ' GROUP BY school, service) l ON l.mid = o.id', ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) { $out[ $r['school'] ][ $r['service'] ] = $r; }
		return $out;
	}

	public static function history( $service ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT school, price, reg, disc, src, at FROM ' . self::table() . ' WHERE service = %s ORDER BY at ASC, id ASC', $service ), ARRAY_A ); // phpcs:ignore
	}

	protected static function add( $school, $service, $price, $reg = null, $disc = 0, $src = 'auto', $url = '', $snippet = '', $at = '' ) {
		global $wpdb;
		$wpdb->insert( self::table(), array(
			'school'  => $school,
			'service' => $service,
			'price'   => round( (float) $price, 2 ),
			'reg'     => null === $reg ? null : round( (float) $reg, 2 ),
			'disc'    => $disc ? 1 : 0,
			'src'     => $src,
			'url'     => mb_substr( (string) $url, 0, 255 ),
			'snippet' => mb_substr( (string) $snippet, 0, 255 ),
			'at'      => $at ? $at : current_time( 'mysql' ),
		) );
	}

	/** Our own prices from the Prices screen (number or null). */
	public static function ours() {
		$n = function ( $key ) {
			$v = class_exists( 'SDSC_Prices' ) ? SDSC_Prices::price( $key ) : '';
			return preg_match( '/\$?\s*(\d{1,5}(?:,\d{3})*(?:\.\d+)?)/', (string) $v, $m ) ? (float) str_replace( ',', '', $m[1] ) : null;
		};
		$hour = $n( 'btw_hour' );
		return array(
			'course36' => $n( 'course' ) ?? 378.0,
			'btw2'     => $hour ? $hour * 2 : ( $n( 'btw_from' ) ?? 120.0 ),
			'btw10'    => $hour ? $hour * 10 : 600.0,
			'rental'   => $n( 'rental' ) ?? 140.0,
			'roadway3' => $n( 'online' ) ?? 90.0,
			'dip'      => $n( 'dip' ),
		);
	}

	/** Records our own price whenever it differs from the last one stored. */
	public static function record_ours() {
		$cur = self::current();
		foreach ( self::ours() as $svc => $p ) {
			if ( null === $p ) { continue; }
			$last = $cur['self'][ $svc ]['price'] ?? null;
			if ( null === $last || abs( (float) $last - $p ) > 0.005 ) { self::add( 'self', $svc, $p, null, 0, 'self', home_url( '/' ), 'Prices screen' ); }
		}
	}

	/* -------------------------------------------------------------- schedule */

	public static function freq() {
		$f = get_option( self::FREQ, '24' );
		return in_array( (string) $f, array( '0', '24', '48', '72', '168' ), true ) ? (int) $f : 24;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 300, 'hourly', self::HOOK ); }
	}

	/** Hourly: checks the next few schools that are due, then sends one report when the round is complete. */
	public static function tick() {
		self::maybe_install();
		self::record_ours();
		$h = self::freq();
		if ( ! $h ) { return; }
		$due = array();
		foreach ( self::schools() as $id => $s ) {
			if ( empty( $s['active'] ) ) { continue; }
			if ( time() - (int) ( $s['last'] ?? 0 ) >= $h * HOUR_IN_SECONDS - 600 ) { $due[] = $id; }
		}
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore
		foreach ( array_slice( $due, 0, self::BATCH ) as $id ) { self::check( $id ); }
		if ( count( $due ) <= self::BATCH ) { self::maybe_report(); }
	}

	/* ----------------------------------------------------------------- check */

	/** Fetches a page as plain text lines (scripts, styles and the head left out), or WP_Error. */
	protected static function fetch_text( $url ) {
		$r = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 4, 'user-agent' => self::UA, 'headers' => array( 'Accept' => 'text/html' ) ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code >= 400 ) { return new WP_Error( 'http', 'HTTP ' . $code ); }
		$html = (string) wp_remote_retrieve_body( $r );
		$html = preg_replace( '#<head\b.*?</head>#is', ' ', $html );
		$html = preg_replace( '#<(script|style|noscript|svg|template)\b.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<(del|s|strike)\b[^>]*>(.*?)</\1>#is', ' [was $2] ', $html ); // crossed-out prices
		$html = preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th|/section|/article|/span|/a|/button|/label)\b[^>]*>#i', "\n", $html );
		$txt  = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$txt  = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $txt );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $txt ) ), 'strlen' ) );
		return $lines;
	}

	/** "$420420" (a site that prints every number twice) → 420; "$1**380" → 1380; "$1,080.00" → 1080. */
	protected static function prices_in( $s ) {
		$out = array();
		if ( ! preg_match_all( '/\$\s*([0-9][0-9,*]*(?:\.\d{1,2})?)/', $s, $m, PREG_OFFSET_CAPTURE ) ) { return $out; }
		foreach ( $m[1] as $x ) {
			$raw = str_replace( array( ',', '*' ), '', $x[0] );
			$int = preg_replace( '/\.\d+$/', '', $raw );
			if ( strlen( $int ) >= 4 && 0 === strlen( $int ) % 2 && substr( $int, 0, strlen( $int ) / 2 ) === substr( $int, strlen( $int ) / 2 ) ) { $raw = substr( $int, 0, strlen( $int ) / 2 ); }
			$v = (float) $raw;
			if ( $v > 0 ) { $out[] = array( $v, $x[1] ); }
		}
		return $out;
	}

	/**
	 * Reads one service from page lines: the first price in range in the 3 lines that start where the
	 * service is named. Two different prices side by side with sale words (regular / was / special / sale /
	 * down to / crossed out) count as a discount: the lower one is the price, the higher the regular price.
	 */
	protected static function read( $lines, $svc, $whole_page = false ) {
		list( , $lo, $hi ) = self::services()[ $svc ];
		$pat   = self::patterns()[ $svc ];
		$sale  = '/(regular|reg\.|was\b|\[was|special|sale|down to|now\b|save|off\b|discount|promo)/i';
		$n     = count( $lines );
		$best  = null;
		for ( $i = 0; $i < $n; $i++ ) {
			if ( ! $whole_page && ! preg_match( $pat, $lines[ $i ] ) ) { continue; }
			if ( 'course36' === $svc && preg_match( '/(practice|extra|additional|remedial|refresher)/i', $lines[ $i ] ) ) { continue; }
			if ( 'dip' === $svc && preg_match( '/young\s+driver/i', $lines[ $i ] ) ) { continue; }
			// Add-ons and bundles are not the service itself (interpreter, translation, "add 2 hours" to a test).
			if ( preg_match( '/(translation|traducci|interpreter|int[eé]rprete|add[\s\-]*on|\badd\s+\d|\bbundle\b)/i', $lines[ $i ] ) ) { continue; }
			// The first line (of this one and the next two) that holds a price in range; the line after it too
			// when it also has a price and sale words ("Regular $425" / "Special price down to $360").
			$inr  = function ( $t ) use ( $lo, $hi ) { return array_values( array_filter( self::prices_in( $t ), function ( $p ) use ( $lo, $hi ) { return $p[0] >= $lo && $p[0] <= $hi; } ) ); };
			$win  = '';
			$ps   = array();
			for ( $j = $i; $j < min( $n, $i + ( $whole_page ? 1 : 3 ) ); $j++ ) {
				if ( $j > $i && self::names_other( $lines[ $j ], $svc ) ) { break; } // the next service's heading
				$ps = $inr( $lines[ $j ] );
				if ( ! $ps ) { continue; }
				$win = $lines[ $j ];
				$nx  = $lines[ $j + 1 ] ?? '';
				if ( $nx && $inr( $nx ) && ( preg_match( $sale, $lines[ $j ] . ' ' . $nx ) || preg_match( '/^\$\s*[0-9][0-9,.]*$/', trim( $nx ) ) ) ) {
					$win .= ' | ' . $lines[ $j + 1 ];
					$ps   = $inr( $win );
				}
				break;
			}
			if ( ! $ps ) { continue; }
			$ctx   = implode( ' | ', array_slice( $lines, $i, max( 1, $j - $i + 1 ) ) );
			if ( mb_strlen( $ctx ) > 400 ) { $ctx = mb_substr( $ctx, 0, 400 ); }
			// Several prices for one service (online / in person, regular / sale): the lowest is the one people
			// compare; a "total" price wins over its parts ("$360 + $30 fee, $390 total").
			$first = min( array_map( function ( $p ) { return $p[0]; }, $ps ) );
			if ( preg_match( '/total[^$]{0,25}\$\s*([0-9][0-9,]*)/i', $win, $tm ) && (float) str_replace( ',', '', $tm[1] ) >= $lo && (float) str_replace( ',', '', $tm[1] ) <= $hi ) { $first = (float) str_replace( ',', '', $tm[1] ); }
			$reg   = null;
			$disc  = 0;
			$pair  = false;
			if ( isset( $ps[1] ) && abs( $ps[1][0] - $ps[0][0] ) > 0.5 ) {
				$between = substr( $win, $ps[0][1], $ps[1][1] - $ps[0][1] );
				$between = preg_replace( '/^\$?\s*[0-9][0-9,*.]*/', '', $between );
				// Two prices with nothing but separators between them (a crossed-out regular price beside the
				// sale price), or with sale words around them.
				$pair = (bool) preg_match( '/^[\s|\/\-–—:]*\$?$/u', $between ) || preg_match( $sale, $win );
			}
			if ( $pair ) {
				$reg   = max( $ps[0][0], $ps[1][0] );
				$first = min( $ps[0][0], $ps[1][0] );
				$disc  = 1;
			}
			$hit = array( 'price' => $first, 'reg' => $reg, 'disc' => $disc, 'snippet' => mb_substr( preg_replace( '/\s+/', ' ', $ctx ), 0, 250 ) );
			// A school may list the same service more than once (online and in person): keep the lowest.
			if ( ! $best || $hit['price'] < $best['price'] ) { $best = $hit; }
		}
		return $best;
	}

	/** True when a line names another service (and not this one) — the start of the next block. */
	protected static function names_other( $line, $svc ) {
		$pats = self::patterns();
		if ( preg_match( $pats[ $svc ], $line ) ) { return false; }
		foreach ( array( 'course36', 'rental', 'roadway3', 'dip' ) as $o ) {
			if ( $o !== $svc && preg_match( $pats[ $o ], $line ) ) { return true; }
		}
		return false;
	}

	/** Sale / promo wording anywhere on the page. */
	protected static function promo_lines( $lines ) {
		$out = array();
		foreach ( $lines as $l ) {
			if ( mb_strlen( $l ) > 220 ) { continue; }
			if ( preg_match( '/(\bsale\b|special\s+(price|offer|discount)|\d+\s*%\s*off|save\s+\$\d|\bpromo|coupon|limited\s+time|discount|fall\s+special|black\s+friday|price\s+down|was\s+\$\d)/i', $l ) ) { $out[] = $l; }
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 5 );
	}

	/** Checks one school: every page, every service; returns a short summary. */
	public static function check( $id ) {
		$all = self::schools();
		if ( empty( $all[ $id ] ) ) { return array( 'ok' => false, 'msg' => 'Unknown school' ); }
		$s      = $all[ $id ];
		$found  = array();
		$promos = array();
		$errors = array();
		$hash   = '';
		foreach ( (array) ( $s['pages'] ?? array() ) as $pg ) {
			$lines = self::fetch_text( $pg['url'] );
			if ( is_wp_error( $lines ) ) { $errors[] = $pg['url'] . ': ' . $lines->get_error_message(); continue; }
			$hash  .= md5( implode( "\n", $lines ) );
			$promos = array_merge( $promos, self::promo_lines( $lines ) );
			$svcs   = ! empty( $pg['svc'] ) ? array( $pg['svc'] ) : array_keys( self::services() );
			foreach ( $svcs as $svc ) {
				if ( isset( $found[ $svc ] ) ) { continue; }
				$r = self::read( $lines, $svc );
				if ( ! $r && ! empty( $pg['svc'] ) ) { $r = self::read( $lines, $svc, true ); }
				if ( $r ) { $r['url'] = $pg['url']; $found[ $svc ] = $r; }
			}
		}
		$events = self::apply_readings( $id, $found );
		$promos = array_slice( array_values( array_unique( $promos ) ), 0, 5 );
		$was    = (array) ( $s['promos'] ?? array() );
		if ( $promos && $promos !== $was ) {
			$events[] = array( 'type' => 'promo', 'school' => $id, 'text' => implode( ' · ', $promos ) );
			$s['promo_since'] = current_time( 'mysql' );
		}
		$s['promos']  = $promos;
		$s['last']    = time();
		$s['status']  = $errors && ! $found ? 'failed' : ( $errors ? 'partial' : 'ok' );
		$s['errors']  = array_slice( $errors, 0, 3 );
		$s['changed'] = $hash && $hash !== ( $s['hash'] ?? '' ) && ! empty( $s['hash'] );
		$s['hash']    = $hash;
		$s['read']    = array_map( function ( $r ) { return $r['price']; }, $found );
		$all          = self::schools();
		$all[ $id ]   = $s;
		self::save_schools( $all );
		self::log_events( $events );
		return array( 'ok' => 'failed' !== $s['status'], 'status' => $s['status'], 'read' => count( $found ), 'events' => count( $events ) );
	}

	/** A new value is only accepted when it is read twice in a row (or confirmed by a person). */
	protected static function apply_readings( $id, $found ) {
		$cur     = self::current();
		$pending = get_option( self::PENDING, array() );
		$pending = is_array( $pending ) ? $pending : array();
		$events  = array();
		$ignore  = (array) get_option( 'sdsc_pw_ignore', array() );
		foreach ( $found as $svc => $r ) {
			$k    = $id . '|' . $svc;
			if ( in_array( $k . '|' . round( $r['price'] ), $ignore, true ) ) { continue; } // marked "wrong reading" before
			$last = $cur[ $id ][ $svc ] ?? null;
			if ( $last && abs( (float) $last['price'] - $r['price'] ) < 0.5 ) {
				unset( $pending[ $k ] );
				// Same price; only the sale marking changed (e.g. the regular price was taken off the page).
				if ( (int) $last['disc'] !== (int) $r['disc'] && 'manual' !== $last['src'] ) { self::add( $id, $svc, $r['price'], $r['reg'], $r['disc'], 'auto', $r['url'], $r['snippet'] ); }
				continue;
			}
			if ( ! $last ) {
				// A service never seen for this school: a person confirms it once.
				$pending[ $k ] = array_merge( $r, array( 'old' => null, 'new' => 1, 'first' => $pending[ $k ]['first'] ?? current_time( 'mysql' ) ) );
				continue;
			}
			$p   = $pending[ $k ] ?? null;
			$big = abs( $r['price'] - (float) $last['price'] ) > 0.25 * (float) $last['price']; // > 25%: a person confirms
			if ( $p && ! $big && abs( (float) $p['price'] - $r['price'] ) < 0.5 ) {
				self::add( $id, $svc, $r['price'], $r['reg'], $r['disc'], 'auto', $r['url'], $r['snippet'] );
				$events[] = array( 'type' => 'change', 'school' => $id, 'service' => $svc, 'old' => (float) $last['price'], 'new' => $r['price'], 'disc' => $r['disc'], 'reg' => $r['reg'] );
				unset( $pending[ $k ] );
			} else {
				$pending[ $k ] = array_merge( $r, array( 'old' => (float) $last['price'], 'big' => $big ? 1 : 0, 'first' => $p['first'] ?? current_time( 'mysql' ) ) );
			}
		}
		update_option( self::PENDING, $pending, false );
		return $events;
	}

	protected static function log_events( $events ) {
		if ( ! $events ) { return; }
		$st          = get_option( self::STATE, array() );
		$st          = is_array( $st ) ? $st : array();
		$st['feed']  = array_slice( array_merge( array_map( function ( $e ) { $e['at'] = current_time( 'mysql' ); return $e; }, $events ), (array) ( $st['feed'] ?? array() ) ), 0, 200 );
		$st['unsent'] = array_merge( (array) ( $st['unsent'] ?? array() ), $events );
		update_option( self::STATE, $st, false );
	}

	/* ---------------------------------------------------------------- report */

	protected static function maybe_report( $force = false ) {
		$st     = get_option( self::STATE, array() );
		$st     = is_array( $st ) ? $st : array();
		$unsent = (array) ( $st['unsent'] ?? array() );
		if ( ! $unsent && ! $force ) { return false; }
		$names = array_map( function ( $s ) { return $s['name']; }, self::schools() );
		$svcs  = self::services();
		$rows  = array();
		foreach ( array_slice( $unsent, 0, 25 ) as $e ) {
			$who = $names[ $e['school'] ] ?? $e['school'];
			if ( 'change' === $e['type'] ) {
				$up     = $e['new'] > $e['old'];
				$rows[] = array(
					'en'   => $who . ' — ' . $svcs[ $e['service'] ][0],
					'fa'   => $who . ' — ' . $svcs[ $e['service'] ][0],
					'v'    => '$' . self::n( $e['old'] ) . ' → $' . self::n( $e['new'] ) . ( $up ? ' (up)' : ' (down)' ) . ( $e['disc'] ? ' — sale' : '' ),
					'v_fa' => '$' . self::n( $e['old'] ) . ' → $' . self::n( $e['new'] ) . ( $up ? ' (افزایش)' : ' (کاهش)' ) . ( $e['disc'] ? ' — حراج' : '' ),
					'ltr'  => true,
				);
			} else {
				$rows[] = array( 'en' => $who . ' — sale / promo text', 'fa' => $who . ' — متن تخفیف یا حراج', 'v' => $e['text'], 'ltr' => true );
			}
		}
		$ins   = self::insights();
		$pos   = array();
		foreach ( $ins as $svc => $x ) {
			if ( null === $x['ours'] || ! $x['n'] ) { continue; }
			$pos[] = array(
				'en'   => 'Our ' . $svcs[ $svc ][0],
				'fa'   => 'قیمت ما — ' . $svcs[ $svc ][0],
				'v'    => '$' . self::n( $x['ours'] ) . ' — market $' . self::n( $x['min'] ) . '–$' . self::n( $x['max'] ) . ', median $' . self::n( $x['median'] ) . ' (' . $x['rank_txt'] . ')',
				'v_fa' => '$' . self::n( $x['ours'] ) . ' — بازار $' . self::n( $x['min'] ) . ' تا $' . self::n( $x['max'] ) . '، میانه $' . self::n( $x['median'] ),
				'ltr'  => true,
			);
		}
		if ( class_exists( 'SDSC_Mailer' ) ) {
			SDSC_Mailer::admin_report( array(
				'to'         => implode( ',', class_exists( 'SDSC_Refunds' ) ? SDSC_Refunds::approvers() : array( get_option( 'admin_email' ) ) ),
				'subject_en' => 'Price watch: ' . count( $unsent ) . ' change' . ( 1 === count( $unsent ) ? '' : 's' ) . ' at other schools',
				'subject_fa' => 'پایش قیمت: ' . count( $unsent ) . ' تغییر در مدرسه‌های دیگر',
				'title_en'   => 'Competitor prices changed',
				'title_fa'   => 'قیمت رقبا تغییر کرد',
				'lead_en'    => $unsent ? 'These changes were read twice on the schools\' own pages before being reported.' : 'No changes since the last report. Here is where our prices stand.',
				'lead_fa'    => $unsent ? 'این تغییرات دو بار پشت سر هم روی صفحه خود مدرسه‌ها خوانده شده‌اند.' : 'از گزارش قبلی تغییری نبوده. جایگاه قیمت‌های ما:',
				'rows'       => array_merge( $rows, $pos ),
				'buttons'    => array( array( 'en' => 'Open Price watch', 'fa' => 'باز کردن پایش قیمت', 'url' => admin_url( 'admin.php?page=' . self::PAGE ) ) ),
			) );
		}
		$st['unsent']    = array();
		$st['last_mail'] = current_time( 'mysql' );
		update_option( self::STATE, $st, false );
		return true;
	}

	/* -------------------------------------------------------------- insights */

	protected static function n( $v ) { return null === $v ? '—' : ( abs( $v - round( $v ) ) < 0.005 ? number_format( $v ) : number_format( $v, 2 ) ); }

	protected static function median( $a ) {
		sort( $a );
		$c = count( $a );
		if ( ! $c ) { return null; }
		return $c % 2 ? $a[ intdiv( $c, 2 ) ] : ( $a[ $c / 2 - 1 ] + $a[ $c / 2 ] ) / 2;
	}

	/** Where we stand per service, and what it suggests. */
	public static function insights() {
		$cur     = self::current();
		$schools = self::schools();
		$out     = array();
		$since   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		foreach ( self::services() as $svc => $def ) {
			$vals = array();
			$disc = 0;
			foreach ( $cur as $sid => $by ) {
				if ( 'self' === $sid || ! isset( $by[ $svc ] ) || empty( $schools[ $sid ]['active'] ) ) { continue; }
				$vals[ $sid ] = (float) $by[ $svc ]['price'];
				if ( (int) $by[ $svc ]['disc'] ) { $disc++; }
			}
			$ours = isset( $cur['self'][ $svc ] ) ? (float) $cur['self'][ $svc ]['price'] : null;
			$x    = array( 'n' => count( $vals ), 'ours' => $ours, 'disc' => $disc, 'min' => null, 'max' => null, 'median' => null, 'rank' => null, 'rank_txt' => '', 'advice' => '', 'advice_fa' => '', 'cheapest' => '' );
			if ( $vals ) {
				$x['min']    = min( $vals );
				$x['max']    = max( $vals );
				$x['median'] = self::median( array_values( $vals ) );
				$x['cheapest'] = $schools[ array_search( $x['min'], $vals, true ) ]['name'] ?? '';
				if ( null !== $ours ) {
					$below      = count( array_filter( $vals, function ( $v ) use ( $ours ) { return $v < $ours - 0.5; } ) );
					$x['rank']  = $below + 1;
					$x['rank_txt'] = self::ord( $below + 1 ) . ' lowest of ' . ( count( $vals ) + 1 );
					$d   = $ours - $x['median'];
					$pct = $x['median'] ? $d / $x['median'] * 100 : 0;
					if ( $pct > 8 ) {
						$x['advice']    = 'About ' . round( $pct ) . '% above the market median. Make the extras visible (Zoom evenings, English/Spanish/Farsi, male and female instructors) or consider a short promotion.';
						$x['advice_fa'] = 'حدود ' . round( $pct ) . '٪ بالاتر از میانه بازار. مزیت‌ها را برجسته کنید یا یک تخفیف کوتاه‌مدت در نظر بگیرید.';
					} elseif ( $pct < -8 ) {
						$x['advice']    = 'About ' . round( -$pct ) . '% below the market median. There may be room to raise the price by up to $' . self::n( -$d ) . ' and still be at the median.';
						$x['advice_fa'] = 'حدود ' . round( -$pct ) . '٪ پایین‌تر از میانه بازار. احتمالاً می‌شود تا $' . self::n( -$d ) . ' گران‌تر کرد و هنوز در میانه بود.';
					} else {
						$x['advice']    = 'In line with the market (within 8% of the median).';
						$x['advice_fa'] = 'هم‌سطح بازار (کمتر از ۸٪ فاصله با میانه).';
					}
				} else {
					$x['advice']    = 'We do not publish a price for this. ' . count( $vals ) . ' schools do (median $' . self::n( $x['median'] ) . ').';
					$x['advice_fa'] = 'ما برای این سرویس قیمت اعلام نکرده‌ایم؛ ' . count( $vals ) . ' مدرسه اعلام کرده‌اند.';
				}
			}
			$out[ $svc ] = $x;
		}
		return $out;
	}

	protected static function ord( $n ) {
		$v = $n % 100;
		if ( $v >= 11 && $v <= 13 ) { return $n . 'th'; }
		$e = array( 1 => 'st', 2 => 'nd', 3 => 'rd' );
		return $n . ( $e[ $n % 10 ] ?? 'th' );
	}

	/** Price changes in the last N days: [up, down]. */
	protected static function moves( $days = 30 ) {
		$st   = get_option( self::STATE, array() );
		$from = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ); // feed times are local
		$up   = 0;
		$down = 0;
		foreach ( (array) ( $st['feed'] ?? array() ) as $e ) {
			if ( 'change' !== $e['type'] || $e['at'] < $from ) { continue; }
			if ( $e['new'] > $e['old'] ) { $up++; } else { $down++; }
		}
		return array( $up, $down );
	}

	/* ------------------------------------------------------------- dashboard */

	/** Link to the full screen: inside the dashboard workspace when it exists, else wp-admin. */
	public static function full_url() {
		return class_exists( 'SDSC_Workspace' ) ? SDSC_Workspace::url( 'pricewatch' ) : admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** Compact card for the dashboard home (administrator and school manager): where we stand, recent moves, "Sync now". */
	public static function dash_card() {
		if ( ! self::can() ) { return ''; }
		self::maybe_install();
		self::record_ours();
		$schools = self::schools();
		$active  = array_filter( $schools, function ( $s ) { return ! empty( $s['active'] ); } );
		$svcs    = self::services();
		$ins     = self::insights();
		$pend    = (array) get_option( self::PENDING, array() );
		$st      = (array) get_option( self::STATE, array() );
		list( $up, $down ) = self::moves( 30 );
		$sales   = count( array_filter( $active, function ( $s ) { return ! empty( $s['promos'] ); } ) );
		$last    = max( array_merge( array( 0 ), array_map( function ( $s ) { return (int) ( $s['last'] ?? 0 ); }, $active ) ) );
		$full    = self::full_url();
		$m       = function ( $v ) { return null === $v ? '—' : '$' . self::n( (float) $v ); };

		$h  = '<section class="sov-card sov-pw" id="sov-pw"><header><h4>Price watch</h4><a href="' . esc_url( $full ) . '">Open full page →</a></header>';
		$h .= '<div class="sov-pw-bar"><span class="sov-pw-when">Last check: <b>' . ( $last ? esc_html( human_time_diff( $last ) . ' ago' ) : 'never' ) . '</b> · ' . count( $active ) . ' Maryland schools</span>'
			. '<span class="sov-pw-prog" aria-live="polite"></span><button type="button" class="sov-btn sov-pw-sync">⟳ Sync now</button></div>';
		$h .= '<div class="sov-pw-kpis">'
			. '<a href="' . esc_url( $full ) . '"><b><span class="sov-up">▲ ' . (int) $up . '</span> <span class="sov-dn">▼ ' . (int) $down . '</span></b><small>Price moves, 30 days</small></a>'
			. '<a href="' . esc_url( $full ) . '"><b>' . (int) $sales . '</b><small>Schools with a sale now</small></a>'
			. '<a href="' . esc_url( $full ) . '" class="' . ( $pend ? 'is-warn' : '' ) . '"><b>' . count( $pend ) . '</b><small>Readings to confirm</small></a></div>';

		$h .= '<div class="sov-pw-scroll"><table class="sov-pw-t"><thead><tr><th>Service</th><th>Us</th><th>Market median</th><th>Lowest</th><th>Where we stand</th></tr></thead><tbody>';
		foreach ( $svcs as $k => $d ) {
			$x = $ins[ $k ];
			if ( ! $x['n'] && null === $x['ours'] ) { continue; }
			$pos = '<span class="sov-muted">—</span>';
			if ( null !== $x['ours'] && $x['median'] ) {
				$pct = ( $x['ours'] - $x['median'] ) / $x['median'] * 100;
				$cls = $pct > 8 ? 'sov-up' : ( $pct < -8 ? 'sov-dn' : 'sov-mid' );
				$pos = '<span class="' . $cls . '">' . ( abs( $pct ) < 0.5 ? 'At median' : ( $pct > 0 ? '+' : '−' ) . round( abs( $pct ) ) . '%' ) . '</span><small>' . esc_html( $x['rank_txt'] ) . '</small>';
			} elseif ( null === $x['ours'] ) {
				$pos = '<small>We do not list a price</small>';
			}
			$h .= '<tr><td>' . esc_html( $d[0] ) . '</td><td><b>' . esc_html( $m( $x['ours'] ) ) . '</b></td><td>' . esc_html( $m( $x['median'] ) ) . ( $x['n'] ? '<small>' . (int) $x['n'] . ' schools</small>' : '' ) . '</td>'
				. '<td>' . esc_html( $m( $x['min'] ) ) . ( $x['cheapest'] ? '<small>' . esc_html( $x['cheapest'] ) . '</small>' : '' ) . '</td><td>' . $pos . '</td></tr>';
		}
		$h .= '</tbody></table></div>';

		$feed = array_slice( array_values( array_filter( (array) ( $st['feed'] ?? array() ), function ( $e ) { return in_array( $e['type'] ?? '', array( 'change', 'promo' ), true ); } ) ), 0, 5 );
		if ( $feed ) {
			$h .= '<p class="sov-pw-h">Latest changes</p><ul class="sov-list">';
			foreach ( $feed as $e ) {
				$who = $schools[ $e['school'] ]['name'] ?? $e['school'];
				if ( 'change' === $e['type'] ) {
					$upd  = $e['new'] > $e['old'];
					$what = ( $svcs[ $e['service'] ][0] ?? $e['service'] ) . ': $' . self::n( (float) $e['old'] ) . ' → $' . self::n( (float) $e['new'] ) . ( ! empty( $e['disc'] ) ? ' (sale)' : '' );
					$chip = '<span class="sov-chip ' . ( $upd ? 'sov-chip--due' : 'sov-chip--done' ) . '">' . ( $upd ? '▲ Up' : '▼ Down' ) . '</span>';
				} else {
					$what = 'Sale wording: ' . wp_trim_words( (string) $e['text'], 14 );
					$chip = '<span class="sov-chip">Sale</span>';
				}
				$h .= '<li><span class="sov-what"><b>' . esc_html( $who ) . '</b><small>' . esc_html( $what . ' · ' . wp_date( 'M j', class_exists( 'SDSC_Lessons' ) ? SDSC_Lessons::ts( $e['at'] ) : strtotime( $e['at'] ) ) ) . '</small></span>' . $chip . '</li>';
			}
			$h .= '</ul>';
		} else {
			$h .= '<p class="sov-empty">No price changes yet. Changes show here once a new price is read twice in a row.</p>';
		}
		$ids = array_keys( $active );
		$h  .= '<script>(function(){var c=document.getElementById("sov-pw");if(!c)return;var b=c.querySelector(".sov-pw-sync"),p=c.querySelector(".sov-pw-prog"),ids=' . wp_json_encode( $ids ) . ',names=' . wp_json_encode( array_map( function ( $s ) { return $s['name']; }, $active ) ) . ',AJ=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',N=' . wp_json_encode( wp_create_nonce( 'sdsc_pw_check' ) ) . ';'
			. 'function post(id,cb){var x=new XMLHttpRequest();x.open("POST",AJ);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.timeout=90000;x.onload=function(){var r=null;try{r=JSON.parse(x.responseText);}catch(e){}cb(r);};x.onerror=x.ontimeout=function(){cb(null);};x.send("action=sdsc_pw_check&_n="+encodeURIComponent(N)+"&id="+encodeURIComponent(id));}'
			. 'b.addEventListener("click",function(){var i=0,bad=0;b.disabled=true;c.classList.add("is-busy");'
			. 'function next(){if(i>=ids.length){p.textContent="Finishing…";post("done",function(){p.textContent="Done"+(bad?" · "+bad+" could not be read":"")+". Refreshing…";setTimeout(function(){location.reload();},700);});return;}'
			. 'p.textContent="Checking "+(i+1)+" of "+ids.length+": "+(names[ids[i]]||ids[i]);post(ids[i],function(r){if(!r||!r.success||!r.data.ok)bad++;i++;next();});}next();});})();</script>';
		return $h . '</section>';
	}

	/* ----------------------------------------------------------------- admin */

	public static function menu() {
		if ( ! self::can() ) { return; }
		add_submenu_page( 'sdsc-dashboard', 'Price watch', 'Price watch', 'read', self::PAGE, array( __CLASS__, 'page' ) );
	}

	protected static function guard( $nonce ) {
		if ( ! self::can() ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( $nonce );
	}

	protected static function back( $msg = '' ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'pwm' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function h_freq() {
		self::guard( 'sdsc_pw_freq' );
		update_option( self::FREQ, sanitize_text_field( wp_unslash( $_POST['freq'] ?? '24' ) ), false ); // phpcs:ignore
		self::back( 'Saved.' );
	}

	public static function h_report() {
		self::guard( 'sdsc_pw_report' );
		self::maybe_report( true );
		self::back( 'Report e-mailed to the administrator and the school manager.' );
	}

	/** Add or edit a school (name, city, pages "url" or "url | service" one per line, on/off) or remove it. */
	public static function h_school() {
		self::guard( 'sdsc_pw_school' );
		$all = self::schools();
		$id  = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore
		if ( ! empty( $_POST['remove'] ) && isset( $all[ $id ] ) ) { // phpcs:ignore
			$all[ $id ]['active'] = 0;
			self::save_schools( $all );
			self::back( 'School switched off (its history is kept).' );
		}
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore
		if ( '' === $name ) { self::back( 'A name is needed.' ); }
		if ( ! $id ) { $id = sanitize_key( $name ); }
		$pages = array();
		foreach ( preg_split( '/\r?\n/', (string) wp_unslash( $_POST['pages'] ?? '' ) ) as $l ) { // phpcs:ignore
			$p   = array_map( 'trim', explode( '|', $l ) );
			$url = esc_url_raw( $p[0] ?? '' );
			if ( ! $url || ! preg_match( '#^https?://#', $url ) ) { continue; }
			$svc     = isset( $p[1], self::services()[ $p[1] ] ) ? $p[1] : '';
			$pages[] = array( 'url' => $url, 'svc' => $svc );
		}
		$old         = $all[ $id ] ?? array();
		$all[ $id ]  = array_merge( $old, array(
			'name'   => $name,
			'city'   => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ), // phpcs:ignore
			'pages'  => $pages,
			'active' => ! empty( $_POST['active'] ) ? 1 : 0, // phpcs:ignore
			'js'     => ! empty( $_POST['js'] ) ? 1 : 0, // phpcs:ignore
		) );
		self::save_schools( $all );
		self::back( 'School saved.' );
	}

	/** Set a price by hand (a reading the robot cannot get, or a correction). */
	public static function h_price() {
		self::guard( 'sdsc_pw_price' );
		$id   = sanitize_key( wp_unslash( $_POST['school'] ?? '' ) ); // phpcs:ignore
		$svc  = sanitize_key( wp_unslash( $_POST['service'] ?? '' ) ); // phpcs:ignore
		$p    = (float) preg_replace( '/[^0-9.]/', '', (string) wp_unslash( $_POST['price'] ?? '' ) ); // phpcs:ignore
		$reg  = (float) preg_replace( '/[^0-9.]/', '', (string) wp_unslash( $_POST['reg'] ?? '' ) ); // phpcs:ignore
		if ( ( 'self' !== $id && ! isset( self::schools()[ $id ] ) ) || ! isset( self::services()[ $svc ] ) || $p <= 0 ) { self::back( 'Choose a school, a service and a price.' ); }
		self::add( $id, $svc, $p, $reg > $p ? $reg : null, $reg > $p ? 1 : 0, 'manual', '', 'Entered by ' . wp_get_current_user()->display_name );
		$pend = get_option( self::PENDING, array() );
		unset( $pend[ $id . '|' . $svc ] );
		update_option( self::PENDING, $pend, false );
		self::back( 'Price saved.' );
	}

	/** Accept or reject a reading that is waiting for its second confirmation. */
	public static function h_pending() {
		self::guard( 'sdsc_pw_pending' );
		$k    = sanitize_text_field( wp_unslash( $_POST['k'] ?? '' ) ); // phpcs:ignore
		$pend = get_option( self::PENDING, array() );
		if ( isset( $pend[ $k ] ) && ! empty( $_POST['accept'] ) ) { // phpcs:ignore
			list( $id, $svc ) = explode( '|', $k );
			$r = $pend[ $k ];
			self::add( $id, $svc, $r['price'], $r['reg'], $r['disc'], 'auto', $r['url'], $r['snippet'] );
			if ( null !== $r['old'] ) { self::log_events( array( array( 'type' => 'change', 'school' => $id, 'service' => $svc, 'old' => (float) $r['old'], 'new' => (float) $r['price'], 'disc' => $r['disc'], 'reg' => $r['reg'] ) ) ); }
		} elseif ( isset( $pend[ $k ] ) ) {
			$ig   = (array) get_option( 'sdsc_pw_ignore', array() );
			$ig[] = $k . '|' . round( (float) $pend[ $k ]['price'] );
			update_option( 'sdsc_pw_ignore', array_slice( array_values( array_unique( $ig ) ), -300 ), false );
		}
		unset( $pend[ $k ] );
		update_option( self::PENDING, $pend, false );
		self::back( 'Done.' );
	}

	/** Our own past prices: CSV lines "date, service, price" (service: 36-hour / btw2 / btw10 / rental / roadway / dip). */
	public static function h_import() {
		self::guard( 'sdsc_pw_import' );
		$txt = (string) wp_unslash( $_POST['csv'] ?? '' ); // phpcs:ignore
		if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) && $_FILES['file']['size'] < 2000000 ) { // phpcs:ignore
			$txt .= "\n" . file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore
		}
		$n = 0;
		foreach ( preg_split( '/\r?\n/', $txt ) as $l ) {
			$c = str_getcsv( $l );
			if ( count( $c ) < 3 ) { continue; }
			$ts  = strtotime( trim( $c[0] ) );
			$svc = self::svc_alias( $c[1] );
			$p   = (float) preg_replace( '/[^0-9.]/', '', $c[2] );
			if ( ! $ts || ! $svc || $p <= 0 ) { continue; }
			self::add( 'self', $svc, $p, null, 0, 'import', '', 'Imported', gmdate( 'Y-m-d 12:00:00', $ts ) );
			$n++;
		}
		self::back( $n . ' past price' . ( 1 === $n ? '' : 's' ) . ' imported.' );
	}

	protected static function svc_alias( $s ) {
		$s = strtolower( trim( (string) $s ) );
		if ( isset( self::services()[ $s ] ) ) { return $s; }
		$map = array( '36' => 'course36', 'driver' => 'course36', 'course' => 'course36', '10' => 'btw10', '2' => 'btw2', 'rental' => 'rental', 'car' => 'rental', 'roadway' => 'roadway3', 'alcohol' => 'roadway3', 'online' => 'roadway3', 'improvement' => 'dip', 'dip' => 'dip' );
		foreach ( $map as $k => $v ) { if ( false !== strpos( $s, $k ) ) { return $v; } }
		return '';
	}

	/** "Check now": one school per request, so the page can show progress. */
	public static function ajax_check() {
		if ( ! self::can() || ! check_ajax_referer( 'sdsc_pw_check', '_n', false ) ) { wp_send_json_error( 'Not allowed', 403 ); }
		$id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore
		if ( 'done' === $id ) {
			self::record_ours();
			self::maybe_report();
			wp_send_json_success( array( 'ok' => true ) );
		}
		wp_send_json_success( self::check( $id ) );
	}

	/* ------------------------------------------------------------------ page */

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		self::maybe_install();
		self::record_ours();
		$schools = self::schools();
		$cur     = self::current();
		$svcs    = self::services();
		$ins     = self::insights();
		$pend    = (array) get_option( self::PENDING, array() );
		$st      = (array) get_option( self::STATE, array() );
		list( $up, $down ) = self::moves( 30 );
		$active  = array_filter( $schools, function ( $s ) { return ! empty( $s['active'] ); } );
		$last    = max( array_merge( array( 0 ), array_map( function ( $s ) { return (int) ( $s['last'] ?? 0 ); }, $active ) ) );
		$hist    = array();
		foreach ( $svcs as $k => $d ) { $hist[ $k ] = self::history( $k ); }
		$names   = array_map( function ( $s ) { return $s['name']; }, $schools );
		$names['self'] = "Sam's (us)";
		$data    = array( 'svcs' => array_map( function ( $d ) { return $d[0]; }, $svcs ), 'hist' => $hist, 'names' => $names, 'cur' => array(), 'ins' => $ins );
		foreach ( $cur as $sid => $by ) {
			if ( 'self' !== $sid && empty( $schools[ $sid ]['active'] ) ) { continue; }
			foreach ( $by as $svc => $r ) { $data['cur'][ $svc ][] = array( 's' => $sid, 'p' => (float) $r['price'], 'd' => (int) $r['disc'], 'r' => null === $r['reg'] ? null : (float) $r['reg'] ); }
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['pwm'] ?? '' ) ); // phpcs:ignore
		$f   = self::freq();
		?>
		<div class="wrap sdpw">
			<style>
				.sdpw{--g:#00703c;--ink:#15191d;--mut:#5b646d;--line:#e3e7ea;--up:#b42318;--dn:#0b7a3b;max-width:1280px}
				.sdpw h1{font-weight:800}.sdpw .lead{color:var(--mut);margin:0 0 14px;font-size:14px}
				.sdpw .bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:0 0 16px}
				.sdpw .bar form{display:flex;gap:6px;align-items:center;margin:0}.sdpw .grow{flex:1}
				.sdpw .btn{background:var(--g);color:#fff;border:0;border-radius:999px;padding:8px 16px;font-weight:700;cursor:pointer}
				.sdpw .btn.ghost{background:#fff;color:var(--g);box-shadow:inset 0 0 0 2px var(--g)}
				.sdpw .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:0 0 16px}
				.sdpw .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px}
				.sdpw .card h3{margin:0 0 6px;font-size:13px;color:var(--mut);text-transform:uppercase;letter-spacing:.04em}
				.sdpw .big{font-size:26px;font-weight:800;color:var(--ink)}.sdpw .sm{font-size:12px;color:var(--mut)}
				.sdpw .tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}.sdpw .tabs button{border:1px solid var(--line);background:#fff;border-radius:999px;padding:6px 12px;cursor:pointer;font-weight:600}
				.sdpw .tabs button.on{background:var(--ink);color:#fff;border-color:var(--ink)}
				.sdpw .two{display:grid;grid-template-columns:1.3fr 1fr;gap:12px;margin:0 0 16px}@media(max-width:1000px){.sdpw .two{grid-template-columns:1fr}}
				.sdpw svg text{font:11px -apple-system,Segoe UI,Arial,sans-serif;fill:#5b646d}
				.sdpw table.t{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
				.sdpw table.t th,.sdpw table.t td{padding:7px 9px;border-bottom:1px solid #eef1f3;text-align:left;font-size:13px;vertical-align:top}
				.sdpw table.t th{background:#f6f8f9;font-size:12px;color:var(--mut)}.sdpw tr.us td{background:#ecf7f1;font-weight:700}
				.sdpw .up{color:var(--up);font-weight:700}.sdpw .dn{color:var(--dn);font-weight:700}
				.sdpw .tag{display:inline-block;border-radius:999px;padding:1px 7px;font-size:11px;font-weight:700;background:#fff3c4;color:#6b5000;margin-left:4px}
				.sdpw .tag.bad{background:#fde2e1;color:#8a1c14}.sdpw .tag.ok{background:#e3f4ea;color:#0b5d2e}
				.sdpw details{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 14px;margin:0 0 12px}.sdpw summary{font-weight:700;cursor:pointer}
				.sdpw textarea{width:100%;font-family:monospace}.sdpw .adv{font-size:13px;margin:6px 0 0}.sdpw .adv[dir=rtl]{font-family:Tahoma,Arial,sans-serif}
				.sdpw #pwprog{font-weight:700;color:var(--g)}
			</style>
			<h1>Price watch</h1>
			<p class="lead">Published prices of <?php echo count( $active ); ?> Maryland driving schools next to ours. A competitor's new price is counted only after it is read the same way twice in a row; every number links to the page it came from.</p>
			<?php if ( $msg ) : ?><div class="notice notice-success"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>

			<div class="bar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sdsc_pw_freq"><?php wp_nonce_field( 'sdsc_pw_freq' ); ?>
					<label for="pwfreq"><b>Check automatically</b></label>
					<select id="pwfreq" name="freq"><?php foreach ( array( '24' => 'Every day', '48' => 'Every 2 days', '72' => 'Every 3 days', '168' => 'Every week', '0' => 'Off (button only)' ) as $v => $l ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( (string) $f, $v ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
					<button class="btn ghost">Save</button>
				</form>
				<span class="grow sm">Last check: <?php echo $last ? esc_html( human_time_diff( $last ) . ' ago' ) : 'never'; ?><?php if ( ! empty( $st['last_mail'] ) ) : ?> · last e-mail report <?php echo esc_html( $st['last_mail'] ); ?><?php endif; ?></span>
				<span id="pwprog"></span>
				<button class="btn" id="pwnow" type="button">Check now</button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="sdsc_pw_report"><?php wp_nonce_field( 'sdsc_pw_report' ); ?><button class="btn ghost">E-mail report</button></form>
			</div>

			<div class="kpis">
				<div class="card"><h3>Schools watched</h3><div class="big"><?php echo count( $active ); ?></div><div class="sm"><?php echo count( array_filter( $active, function ( $s ) { return 'failed' === ( $s['status'] ?? '' ); } ) ); ?> could not be read last time</div></div>
				<div class="card"><h3>Price moves, 30 days</h3><div class="big"><span class="up">▲ <?php echo (int) $up; ?></span> &nbsp; <span class="dn">▼ <?php echo (int) $down; ?></span></div><div class="sm">increases / decreases at other schools</div></div>
				<div class="card"><h3>Schools with a sale now</h3><div class="big"><?php echo count( array_filter( $active, function ( $s ) { return ! empty( $s['promos'] ); } ) ); ?></div><div class="sm">sale, special or discount wording on their page</div></div>
				<div class="card"><h3>Waiting for confirmation</h3><div class="big"><?php echo count( $pend ); ?></div><div class="sm">new readings seen once</div></div>
			</div>

			<div class="tabs" id="pwtabs"><?php $i = 0; foreach ( $svcs as $k => $d ) : ?><button type="button" data-s="<?php echo esc_attr( $k ); ?>" class="<?php echo 0 === $i++ ? 'on' : ''; ?>"><?php echo esc_html( $d[0] ); ?></button><?php endforeach; ?></div>
			<div class="two">
				<div class="card"><h3 id="pwt1">Today: every school</h3><div id="pwbars"></div></div>
				<div class="card"><h3>Over time: us vs. the market</h3><div id="pwline"></div><div id="pwadv"></div></div>
			</div>

			<?php if ( $pend ) : ?>
			<details open><summary>Waiting for confirmation (<?php echo count( $pend ); ?>)</summary>
				<table class="t"><tr><th>School</th><th>Service</th><th>Was</th><th>Read now</th><th>Words on the page</th><th></th></tr>
				<?php foreach ( $pend as $k => $r ) : list( $sid, $svc ) = explode( '|', $k ); ?>
					<tr><td><?php echo esc_html( $names[ $sid ] ?? $sid ); ?></td><td><?php echo esc_html( $svcs[ $svc ][0] ?? $svc ); ?></td><td><?php echo null === $r['old'] ? '<span class="tag ok">new</span>' : '$' . esc_html( self::n( (float) $r['old'] ) ); ?></td>
					<td class="<?php echo null === $r['old'] ? '' : ( $r['price'] > $r['old'] ? 'up' : 'dn' ); ?>">$<?php echo esc_html( self::n( (float) $r['price'] ) ); ?><?php echo $r['disc'] ? ' <span class="tag">sale</span>' : ''; ?></td>
					<td class="sm"><?php echo esc_html( $r['snippet'] ); ?> <a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener">page</a></td>
					<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px"><input type="hidden" name="action" value="sdsc_pw_pending"><input type="hidden" name="k" value="<?php echo esc_attr( $k ); ?>"><?php wp_nonce_field( 'sdsc_pw_pending' ); ?><button class="btn" name="accept" value="1">Correct</button><button class="btn ghost">Wrong reading</button></form></td></tr>
				<?php endforeach; ?></table>
			</details>
			<?php endif; ?>

			<details open><summary>All schools and prices</summary>
				<table class="t">
					<tr><th>School</th><?php foreach ( $svcs as $d ) : ?><th><?php echo esc_html( $d[0] ); ?></th><?php endforeach; ?><th>Sale / promo on the page</th><th>Checked</th></tr>
					<tr class="us"><td>Sam's (us)</td><?php foreach ( $svcs as $k => $d ) : ?><td><?php echo isset( $cur['self'][ $k ] ) ? '$' . esc_html( self::n( (float) $cur['self'][ $k ]['price'] ) ) : '—'; ?></td><?php endforeach; ?><td></td><td class="sm">Prices screen</td></tr>
					<?php
					uasort( $active, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
					foreach ( $active as $sid => $s ) :
						?>
						<tr><td><b><?php echo esc_html( $s['name'] ); ?></b><div class="sm"><?php echo esc_html( $s['city'] ?? '' ); ?> · <?php foreach ( (array) $s['pages'] as $j => $pg ) : ?><a href="<?php echo esc_url( $pg['url'] ); ?>" target="_blank" rel="noopener">page <?php echo (int) $j + 1; ?></a> <?php endforeach; ?></div></td>
						<?php foreach ( $svcs as $k => $d ) : $r = $cur[ $sid ][ $k ] ?? null; ?>
							<td><?php if ( $r ) : ?><span title="<?php echo esc_attr( $r['snippet'] . ' — ' . $r['at'] . ' (' . $r['src'] . ')' ); ?>">$<?php echo esc_html( self::n( (float) $r['price'] ) ); ?></span><?php echo (int) $r['disc'] ? '<span class="tag">sale' . ( $r['reg'] ? ' from $' . esc_html( self::n( (float) $r['reg'] ) ) : '' ) . '</span>' : ''; ?><?php if ( isset( $cur['self'][ $k ] ) ) { $dd = (float) $r['price'] - (float) $cur['self'][ $k ]['price']; echo '<div class="sm ' . ( $dd < 0 ? 'up' : 'dn' ) . '">' . ( $dd < 0 ? '$' . esc_html( self::n( -$dd ) ) . ' cheaper' : ( $dd > 0 ? '$' . esc_html( self::n( $dd ) ) . ' more' : 'same' ) ) . '</div>'; } ?><?php else : ?>—<?php endif; ?></td>
						<?php endforeach; ?>
						<td class="sm"><?php echo esc_html( implode( ' · ', (array) ( $s['promos'] ?? array() ) ) ); ?></td>
						<td class="sm"><?php echo ! empty( $s['last'] ) ? esc_html( human_time_diff( (int) $s['last'] ) . ' ago' ) : 'not yet'; ?><?php
							$stt = $s['status'] ?? '';
							if ( 'failed' === $stt ) { echo ' <span class="tag bad">could not read</span>'; } elseif ( 'partial' === $stt ) { echo ' <span class="tag">partly</span>'; }
							if ( ! empty( $s['js'] ) ) { echo ' <span class="tag">prices load by script — check by hand</span>'; }
							if ( ! empty( $s['changed'] ) ) { echo ' <span class="tag ok">page changed</span>'; }
						?></td></tr>
					<?php endforeach; ?>
				</table>
				<p class="sm">Hover a price to see the words it was read from and when. Red "cheaper" means that school is cheaper than us; green "more" means we are cheaper.</p>
			</details>

			<details><summary>Recent changes</summary>
				<table class="t"><tr><th>When</th><th>School</th><th>What</th></tr>
				<?php foreach ( array_slice( (array) ( $st['feed'] ?? array() ), 0, 60 ) as $e ) : ?>
					<tr><td class="sm"><?php echo esc_html( $e['at'] ); ?></td><td><?php echo esc_html( $names[ $e['school'] ] ?? $e['school'] ); ?></td><td><?php
					if ( 'change' === $e['type'] ) {
						$upx = $e['new'] > $e['old'];
						echo esc_html( $svcs[ $e['service'] ][0] ?? $e['service'] ) . ': $' . esc_html( self::n( $e['old'] ) ) . ' → <span class="' . ( $upx ? 'up' : 'dn' ) . '">$' . esc_html( self::n( $e['new'] ) ) . ' ' . ( $upx ? '▲' : '▼' ) . ' ' . esc_html( round( ( $e['new'] - $e['old'] ) / max( 1, $e['old'] ) * 100 ) ) . '%</span>' . ( $e['disc'] ? ' <span class="tag">sale</span>' : '' );
					} else {
						echo 'New sale / promo wording: ' . esc_html( $e['text'] );
					}
					?></td></tr>
				<?php endforeach; ?>
				<?php if ( empty( $st['feed'] ) ) : ?><tr><td colspan="3" class="sm">No changes yet. The first readings were taken on <?php echo esc_html( gmdate( 'M j, Y', strtotime( '2026-10-15' ) ) ); ?>.</td></tr><?php endif; ?>
				</table>
			</details>

			<details><summary>Set a price by hand</summary>
				<p class="sm">For a school whose prices load by script, a price you got by phone, or to correct a reading. If the regular price is higher, it is shown as a sale.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
					<input type="hidden" name="action" value="sdsc_pw_price"><?php wp_nonce_field( 'sdsc_pw_price' ); ?>
					<select name="school"><option value="self">Sam's (us)</option><?php foreach ( $active as $sid => $s ) : ?><option value="<?php echo esc_attr( $sid ); ?>"><?php echo esc_html( $s['name'] ); ?></option><?php endforeach; ?></select>
					<select name="service"><?php foreach ( $svcs as $k => $d ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $d[0] ); ?></option><?php endforeach; ?></select>
					<input name="price" placeholder="Price $" size="8" required><input name="reg" placeholder="Regular $ (optional)" size="14"><button class="btn">Save price</button>
				</form>
			</details>

			<details><summary>Our past prices (import)</summary>
				<p class="sm">Upload a CSV, or paste lines, with three columns: <code>date, service, price</code> — for example <code>2024-03-01, 36-hour, 350</code>. Service can be: 36-hour, btw2, btw10, rental, roadway, dip. They appear on the "Over time" chart as our line.</p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sdsc_pw_import"><?php wp_nonce_field( 'sdsc_pw_import' ); ?>
					<p><input type="file" name="file" accept=".csv,.txt"></p><textarea name="csv" rows="4" placeholder="2024-03-01, 36-hour, 350"></textarea><p><button class="btn">Import</button></p>
				</form>
			</details>

			<details><summary>Schools watched — add or edit</summary>
				<p class="sm">One page per line. To read only one service from a page, add <code>| service</code>, e.g. <code>https://example.com/drivers-ed | course36</code>.</p>
				<?php foreach ( array_merge( $schools, array( '' => array( 'name' => '', 'city' => '', 'pages' => array(), 'active' => 1 ) ) ) as $sid => $s ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="border-top:1px solid #eef1f3;padding:8px 0;display:grid;grid-template-columns:200px 140px 1fr auto;gap:6px;align-items:start">
						<input type="hidden" name="action" value="sdsc_pw_school"><input type="hidden" name="id" value="<?php echo esc_attr( $sid ); ?>"><?php wp_nonce_field( 'sdsc_pw_school' ); ?>
						<input name="name" value="<?php echo esc_attr( $s['name'] ); ?>" placeholder="<?php echo $sid ? '' : 'New school name'; ?>"><input name="city" value="<?php echo esc_attr( $s['city'] ?? '' ); ?>" placeholder="City">
						<textarea name="pages" rows="<?php echo max( 1, count( (array) $s['pages'] ) ); ?>"><?php echo esc_textarea( implode( "\n", array_map( function ( $p ) { return $p['url'] . ( $p['svc'] ? ' | ' . $p['svc'] : '' ); }, (array) $s['pages'] ) ) ); ?></textarea>
						<span><label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $s['active'] ) ); ?>> on</label> <label><input type="checkbox" name="js" value="1" <?php checked( ! empty( $s['js'] ) ); ?>> by hand</label> <button class="btn ghost"><?php echo $sid ? 'Save' : 'Add'; ?></button></span>
					</form>
				<?php endforeach; ?>
			</details>
		</div>
		<script>
		(function(){
			var D=<?php echo wp_json_encode( $data ); ?>, ids=<?php echo wp_json_encode( array_keys( $active ) ); ?>;
			var AJ=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, N=<?php echo wp_json_encode( wp_create_nonce( 'sdsc_pw_check' ) ); ?>;
			var G='#00703c', M='#5b646d', svgNS='http://www.w3.org/2000/svg';
			function el(t,a,p){var e=document.createElementNS(svgNS,t);for(var k in a)e.setAttribute(k,a[k]);if(p)p.appendChild(e);return e;}
			function money(v){return '$'+(Math.round(v*100)/100).toLocaleString();}
			function bars(s){
				var box=document.getElementById('pwbars');box.innerHTML='';
				var rows=(D.cur[s]||[]).slice().sort(function(a,b){return a.p-b.p;});
				if(!rows.length){box.textContent='No prices found for this service yet.';return;}
				var W=560,rh=22,H=rows.length*rh+10,L=170,max=Math.max.apply(null,rows.map(function(r){return r.r||r.p;}))*1.08;
				var sv=el('svg',{viewBox:'0 0 '+W+' '+H,width:'100%',role:'img','aria-label':'Prices of every school'},box);
				var ins=D.ins[s]; if(ins&&ins.median){var mx=L+(W-L-60)*ins.median/max;el('line',{x1:mx,x2:mx,y1:0,y2:H,stroke:M,'stroke-dasharray':'3 3'},sv);el('text',{x:mx+3,y:H-2},sv).textContent='median '+money(ins.median);}
				rows.forEach(function(r,i){
					var y=5+i*rh, w=(W-L-60)*r.p/max, us=r.s==='self';
					el('text',{x:L-6,y:y+14,'text-anchor':'end','font-weight':us?'700':'400',fill:us?G:'#15191d'},sv).textContent=(D.names[r.s]||r.s);
					if(r.r){el('rect',{x:L,y:y+3,width:(W-L-60)*r.r/max,height:rh-8,rx:3,fill:'none',stroke:'#e2a800','stroke-dasharray':'3 2'},sv);}
					el('rect',{x:L,y:y+3,width:Math.max(2,w),height:rh-8,rx:3,fill:us?G:(r.d?'#e2a800':'#9aa5ae')},sv);
					el('text',{x:L+w+5,y:y+14,fill:'#15191d','font-weight':us?'700':'400'},sv).textContent=money(r.p)+(r.d?' sale':'');
				});
			}
			function line(s){
				var box=document.getElementById('pwline');box.innerHTML='';
				var h=(D.hist[s]||[]); if(!h.length){box.textContent='No history yet.';return;}
				// rebuild, at every date something changed, the latest price of each school
				var last={},pts=[],days={};
				h.forEach(function(r){last[r.school]=parseFloat(r.price);var d=r.at.slice(0,10);var others=Object.keys(last).filter(function(k){return k!=='self';}).map(function(k){return last[k];}).sort(function(a,b){return a-b;});
					var med=others.length?(others.length%2?others[(others.length-1)/2]:(others[others.length/2-1]+others[others.length/2])/2):null;
					days[d]={t:Date.parse(d+'T12:00:00'),us:last.self,med:med,min:others[0],max:others[others.length-1]};});
				Object.keys(days).sort().forEach(function(d){pts.push(days[d]);});
				var now=Date.now(), t0=pts[0].t, t1=Math.max(now,t0+7*86400000); var lastp=pts[pts.length-1]; pts.push({t:t1,us:lastp.us,med:lastp.med,min:lastp.min,max:lastp.max});
				var W=480,H=220,L=46,R=10,T=10,B=26;
				var vals=[];pts.forEach(function(p){[p.us,p.med,p.min,p.max].forEach(function(v){if(v!=null)vals.push(v);});});
				var lo=Math.min.apply(null,vals)*0.92, hi=Math.max.apply(null,vals)*1.05;
				function X(t){return L+(W-L-R)*(t-t0)/(t1-t0);} function Y(v){return T+(H-T-B)*(1-(v-lo)/(hi-lo));}
				var sv=el('svg',{viewBox:'0 0 '+W+' '+H,width:'100%',role:'img','aria-label':'Our price and the market over time'},box);
				for(var g=0;g<=4;g++){var v=lo+(hi-lo)*g/4;el('line',{x1:L,x2:W-R,y1:Y(v),y2:Y(v),stroke:'#eef1f3'},sv);el('text',{x:L-4,y:Y(v)+4,'text-anchor':'end'},sv).textContent=money(Math.round(v));}
				function step(key){var d='',prev=null;pts.forEach(function(p){if(p[key]==null)return;var x=X(p.t),y=Y(p[key]);d+=(prev===null?'M':'H'+x+'V')+(prev===null?x+','+y:y);prev=y;});return d;}
				var band='';pts.forEach(function(p,i){if(p.max!=null)band+=(band?'L':'M')+X(p.t)+','+Y(p.max);});for(var i=pts.length-1;i>=0;i--){if(pts[i].min!=null)band+='L'+X(pts[i].t)+','+Y(pts[i].min);}
				if(band)el('path',{d:band+'Z',fill:'#9aa5ae',opacity:.18},sv);
				el('path',{d:step('med'),fill:'none',stroke:M,'stroke-width':2,'stroke-dasharray':'5 4'},sv);
				el('path',{d:step('us'),fill:'none',stroke:G,'stroke-width':3},sv);
				el('text',{x:L,y:H-6},sv).textContent=new Date(t0).toLocaleDateString();el('text',{x:W-R,y:H-6,'text-anchor':'end'},sv).textContent='today';
				var lg=el('g',{},sv);el('rect',{x:W-200,y:T,width:12,height:4,fill:G},lg);el('text',{x:W-184,y:T+5},lg).textContent='Us';el('rect',{x:W-150,y:T,width:12,height:4,fill:M},lg);el('text',{x:W-134,y:T+5},lg).textContent='Median';el('rect',{x:W-84,y:T-2,width:12,height:8,fill:'#9aa5ae',opacity:.4},lg);el('text',{x:W-68,y:T+5},lg).textContent='Range';
			}
			function adv(s){var x=D.ins[s],b=document.getElementById('pwadv');b.innerHTML='';if(!x||!x.n)return;
				var p=document.createElement('p');p.className='adv';p.innerHTML='<b>'+(x.ours!=null?'Us '+money(x.ours)+' · '+x.rank_txt+' · ':'')+'market '+money(x.min)+'–'+money(x.max)+', median '+money(x.median)+'</b>'+(x.disc?' · '+x.disc+' on sale':'')+(x.cheapest?' · cheapest: '+x.cheapest:'');b.appendChild(p);
				var a=document.createElement('p');a.className='adv';a.textContent=x.advice;b.appendChild(a);
				var f=document.createElement('p');f.className='adv';f.dir='rtl';f.textContent=x.advice_fa;b.appendChild(f);}
			function show(s){bars(s);line(s);adv(s);[].forEach.call(document.querySelectorAll('#pwtabs button'),function(b){b.classList.toggle('on',b.getAttribute('data-s')===s);});}
			[].forEach.call(document.querySelectorAll('#pwtabs button'),function(b){b.addEventListener('click',function(){show(b.getAttribute('data-s'));});});
			show(Object.keys(D.svcs)[0]);
			document.getElementById('pwnow').addEventListener('click',function(){
				var btn=this,prog=document.getElementById('pwprog'),i=0,bad=0;btn.disabled=true;
				function next(){
					if(i>=ids.length){prog.textContent='Finishing…';post('done',function(){location.reload();});return;}
					prog.textContent='Checking '+(i+1)+' of '+ids.length+': '+(D.names[ids[i]]||ids[i]);
					post(ids[i],function(r){if(!r||!r.success||!r.data.ok)bad++;i++;next();});
				}
				function post(id,cb){var x=new XMLHttpRequest();x.open('POST',AJ);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');x.timeout=90000;
					x.onload=function(){var r=null;try{r=JSON.parse(x.responseText);}catch(e){}cb(r);};x.onerror=x.ontimeout=function(){cb(null);};
					x.send('action=sdsc_pw_check&_n='+encodeURIComponent(N)+'&id='+encodeURIComponent(id));}
				next();
			});
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ seed */

	/**
	 * Starting list, read by hand from each school's own pages on Oct 15, 2026 (lowest listed price where a
	 * school lists several; a sale is kept with its regular price). Montgomery County first, then nearby.
	 */
	protected static function seed() {
		$S = array(
			'international' => array( 'International Driving School', 'Rockville', array( 'https://internationaldrivingmd.com/', 'https://internationaldrivingmd.com/behind-the-wheel-practices/' ), array( 'course36' => array( 360, 425 ), 'btw2' => array( 130, 145 ), 'btw10' => 567, 'rental' => array( 130, 165 ), 'roadway3' => array( 90, 120 ), 'dip' => array( 120, 140 ) ) ),
			'cyrus'         => array( 'Cyrus Driving School', 'Rockville', array( 'https://www.cyrusdrivingschool.com/', 'https://www.cyrusdrivingschool.com/course/rental-car-for-mva-test|rental' ), array( 'btw2' => 120, 'btw10' => 550, 'rental' => 130 ) ),
			'aspenhill'     => array( 'Aspen Hill Driving School', 'Silver Spring', array( 'https://aspenhilldrivingschool.com/' ), array( 'course36' => 400, 'btw2' => 120, 'btw10' => 500, 'rental' => 120, 'roadway3' => 100, 'dip' => 150 ) ),
			'tena'          => array( '10A Driving School', 'Silver Spring', array( 'https://10adriving.com/packages/', 'https://10adriving.com/3-hour-road-way-safety/|roadway3' ), array( 'btw2' => 110, 'btw10' => 550, 'roadway3' => 75 ) ),
			'selam'         => array( 'Selam Driving School', 'Silver Spring', array( 'https://www.selamdrivingschool.com/' ), array( 'course36' => 390, 'dip' => 120 ) ),
			'habesha'       => array( 'Habesha Spring Driving School', 'Silver Spring / Bethesda', array( 'https://hdmvds.com/' ), array( 'course36' => 395, 'rental' => 100 ) ),
			'fourlife'      => array( '4Life Driving School', 'Bethesda', array( 'https://www.4lifedrivingschool.com/', 'https://www.4lifedrivingschool.com/btw', 'https://www.4lifedrivingschool.com/mva-escort|rental' ), array( 'course36' => 398, 'btw2' => 139, 'btw10' => 649, 'rental' => 199 ) ),
			'gregs'         => array( "Greg's Driving School", 'Rockville / Gaithersburg / Germantown', array( 'https://www.gregsdrivingschool.net/drivers-ed.html|course36', 'https://www.gregsdrivingschool.net/extra-driving-practice.html', 'https://www.gregsdrivingschool.net/roadway-safety-program.html|roadway3', 'https://www.gregsdrivingschool.net/driver-improvement.html|dip' ), array( 'course36' => 449, 'btw2' => 130, 'btw10' => 650, 'rental' => 225, 'roadway3' => 85, 'dip' => 85 ) ),
			'mrgeorge'      => array( "Mr. George's Driving School", 'Gaithersburg', array( 'https://www.drivingschoolmd.com/index.html' ), array( 'course36' => array( 380, 440 ) ), 1 ),
			'charlies'      => array( "Charlie's Driving School", 'Gaithersburg', array( 'https://www.charliesdrivingschool.com/' ), array( 'course36' => 380, 'rental' => 120, 'roadway3' => 100, 'dip' => 140 ) ),
			'astick'        => array( 'Astick Driving School', 'Gaithersburg', array( 'https://astickdrivingschool.com/producto/36-hour-driving-course/|course36', 'https://astickdrivingschool.com/' ), array( 'course36' => 380, 'rental' => 120, 'roadway3' => 100, 'dip' => 100 ) ),
			'action'        => array( 'Action Driving School', 'Gaithersburg / Silver Spring', array( 'https://www.actiondrivingschoolmd.com/', 'https://www.actiondrivingschoolmd.com/plans-pricing' ), array( 'course36' => 350, 'btw2' => 90, 'btw10' => 420, 'rental' => 130, 'roadway3' => 80, 'dip' => 100 ) ),
			'frontline'     => array( 'Front Line Driving School', 'Wheaton / Olney', array( 'https://www.frontlinedrivingschool.com/services' ), array( 'course36' => 390, 'roadway3' => 90, 'dip' => 90 ) ),
			'adriano'       => array( "Adriano's Driving School", 'Frederick', array( 'https://www.adrianosdrivingschool.com/prices' ), array( 'course36' => 300, 'btw2' => 100, 'btw10' => 450, 'rental' => 150 ) ),
			'starlinx'      => array( 'StarLinx Driving School', 'Greenbelt', array( 'https://starlinxdriving.com/pricing/' ), array( 'course36' => 350, 'btw2' => 140, 'btw10' => 600, 'rental' => 150, 'roadway3' => 100, 'dip' => 150 ) ),
			'fw'            => array( 'FW Driving School', 'Marlow Heights', array( 'https://fwdrivingschool.com/services/' ), array( 'course36' => 420, 'btw2' => 120, 'roadway3' => 120, 'dip' => 150 ) ),
			'learn2turn'    => array( 'Learn2TurnNow Driving School', 'Fort Washington', array( 'https://www.learn2turnnow.com/drivers-education.html|course36' ), array( 'course36' => 375 ) ),
			'roadmaster'    => array( 'Road Master Driving School', 'Hyattsville', array( 'https://roadmasterdrivingschoolacademy.com/prices/' ), array( 'course36' => 350, 'btw2' => 100, 'btw10' => 480, 'rental' => 150, 'roadway3' => 100, 'dip' => 100 ) ),
			'aby'           => array( 'ABY Driving School', 'Laurel', array( 'https://abydriving.com/' ), array( 'course36' => 325, 'btw10' => 450 ) ),
			'elies'         => array( "Elie's Driving School", 'Laurel', array( 'https://www.eliesdrivingschool.com/driver-s-education|course36' ), array( 'course36' => 295 ) ),
			'amerigo'       => array( 'Amerigo Driving School', 'Hyattsville', array( 'https://www.amerigodrivingschool.com/driving-education' ), array( 'roadway3' => 80, 'dip' => 100 ) ),
		);
		$schools = array();
		$at      = '2026-10-15 09:00:00';
		foreach ( $S as $id => $x ) {
			$pages = array();
			foreach ( $x[2] as $u ) { $p = explode( '|', $u ); $pages[] = array( 'url' => $p[0], 'svc' => $p[1] ?? '' ); }
			$schools[ $id ] = array( 'name' => $x[0], 'city' => $x[1], 'pages' => $pages, 'active' => 1, 'js' => ! empty( $x[4] ) ? 1 : 0, 'last' => 0 );
			foreach ( $x[3] as $svc => $v ) {
				$p   = is_array( $v ) ? $v[0] : $v;
				$reg = is_array( $v ) ? $v[1] : null;
				self::add( $id, $svc, $p, $reg, $reg ? 1 : 0, 'seed', $pages[0]['url'], 'Read by hand on Oct 15, 2026', $at );
			}
		}
		self::save_schools( $schools );
		foreach ( array( 'course36' => 378, 'btw2' => 120, 'btw10' => 600, 'rental' => 140, 'roadway3' => 90 ) as $svc => $p ) {
			self::add( 'self', $svc, $p, null, 0, 'seed', home_url( '/' ), 'Our price on Oct 15, 2026', $at );
		}
	}
}
