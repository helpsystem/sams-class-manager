<?php
/**
 * Short, friendly links for students: https://samsdrivingschool.org/go/k7Qm2Rx9
 *
 * The long signed links (attendance, calendar, unsubscribe, payment) contain an encoded e-mail and a
 * signature, which looks alarming in a text message. Every such link is now stored once and sent as a
 * short code; opening it forwards to the real link on the same site. Query arguments on the short link
 * (for example ?a=yes) are passed through.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Short {
	const DBV   = '1';
	const DBO   = 'sdsc_short_db';
	const CHARS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/l/I

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_short'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 19 );
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', function ( $v ) { $v[] = 'sdsc_go'; return $v; } );
		add_action( 'template_redirect', array( __CLASS__, 'go' ), -1 );
	}

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			code varchar(12) NOT NULL,
			lhash char(32) NOT NULL,
			url text NOT NULL,
			created_at datetime NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (code),
			UNIQUE KEY lhash (lhash)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
		update_option( 'sdsc_short_flush', 1, false );
	}

	public static function rewrite() {
		add_rewrite_rule( '^go/([A-Za-z0-9]{6,12})/?$', 'index.php?sdsc_go=$matches[1]', 'top' );
		if ( get_option( 'sdsc_short_flush' ) ) { delete_option( 'sdsc_short_flush' ); flush_rewrite_rules( false ); }
	}

	/** Short URL for a long same-site link (created once, then reused). Other links are returned unchanged. */
	public static function make( $long ) {
		$long = (string) $long;
		if ( '' === $long || 0 !== strpos( $long, home_url( '/' ) ) ) { return $long; }
		global $wpdb;
		$t = self::table();
		$h = md5( $long );
		$c = $wpdb->get_var( $wpdb->prepare( "SELECT code FROM $t WHERE lhash = %s", $h ) ); // phpcs:ignore
		if ( ! $c ) {
			for ( $i = 0; $i < 6 && ! $c; $i++ ) {
				$try = '';
				for ( $j = 0; $j < 8; $j++ ) { $try .= self::CHARS[ random_int( 0, strlen( self::CHARS ) - 1 ) ]; }
				$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (code, lhash, url, created_at) VALUES (%s, %s, %s, %s)", $try, $h, $long, current_time( 'mysql' ) ) ); // phpcs:ignore
				if ( 1 === (int) $ok ) { $c = $try; }
				else { $c = $wpdb->get_var( $wpdb->prepare( "SELECT code FROM $t WHERE lhash = %s", $h ) ); } // phpcs:ignore -- made by a parallel request
			}
			if ( ! $c ) { return $long; }
		}
		return get_option( 'permalink_structure' ) ? home_url( '/go/' . $c ) : add_query_arg( 'sdsc_go', $c, home_url( '/' ) );
	}

	public static function go() {
		$c = (string) get_query_var( 'sdsc_go' );
		if ( '' === $c && isset( $_GET['sdsc_go'] ) ) { $c = sanitize_text_field( wp_unslash( $_GET['sdsc_go'] ) ); } // phpcs:ignore
		if ( '' === $c ) { return; }
		nocache_headers();
		global $wpdb;
		$t   = self::table();
		$url = preg_match( '/^[A-Za-z0-9]{6,12}$/', $c ) ? $wpdb->get_var( $wpdb->prepare( "SELECT url FROM $t WHERE code = %s", $c ) ) : ''; // phpcs:ignore
		if ( ! $url ) {
			status_header( 404 );
			wp_die( 'This link is not valid any more. Please call Sam’s Driving School at (301) 726-3030.', 'Link not found', array( 'response' => 404 ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET hits = hits + 1 WHERE code = %s", $c ) ); // phpcs:ignore
		$extra = array();
		foreach ( array( 'a', 'l' ) as $k ) { if ( isset( $_GET[ $k ] ) ) { $extra[ $k ] = sanitize_key( wp_unslash( $_GET[ $k ] ) ); } } // phpcs:ignore
		wp_safe_redirect( $extra ? add_query_arg( $extra, $url ) : $url, 302 );
		exit;
	}
}
