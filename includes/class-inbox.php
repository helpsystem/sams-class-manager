<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * School inbox (info@): new e-mails show in the panel (administrator + school manager), are forwarded to the
 * people chosen (Saghar), and a reply to that forward is sent on to the student from info@ — so staff never
 * need the webmail. Staff can also answer from the panel.
 *
 * How it works
 *  - Every 5 minutes the site reads new messages from the info@ mailbox over IMAP (read-only: the messages
 *    stay unread in webmail). Only messages that arrive after the inbox is switched on are forwarded.
 *  - A forward goes out from info@ with Reply-To info@ and a reference in the subject: [SDS #123-ab12cd].
 *    When the reply comes back into info@ from one of the chosen people with a valid reference, the quoted
 *    part is cut off and the answer is sent to the student from info@ (same conversation: In-Reply-To),
 *    and a copy is saved in the mailbox's Sent folder.
 *  - Newsletters, automatic replies, bounces and spam are kept in the panel but never forwarded.
 *  - Runs only on the public site (never on the test site).
 */
class SDSC_Imap {
	protected $s = null;
	protected $n = 0;
	public $err = '';

	public function connect( $host, $port, $user, $pass ) {
		$ctx = stream_context_create( array( 'ssl' => array( 'verify_peer' => true, 'verify_peer_name' => true ) ) );
		$this->s = @stream_socket_client( 'ssl://' . $host . ':' . $port, $no, $msg, 20, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore
		if ( ! $this->s ) { $this->err = 'Could not reach the mail server: ' . $msg; return false; }
		stream_set_timeout( $this->s, 40 );
		$hello = fgets( $this->s );
		if ( 0 !== strpos( (string) $hello, '* OK' ) ) { $this->err = 'Mail server did not answer.'; return false; }
		list( $ok ) = $this->cmd( 'LOGIN ' . $this->q( $user ) . ' ' . $this->q( $pass ) );
		if ( ! $ok ) { $this->err = 'The mailbox refused the login.'; return false; }
		return true;
	}

	public function q( $s ) { return '"' . addcslashes( (string) $s, "\"\\" ) . '"'; }

	/** Send a command; returns array( ok, untagged responses (literal data inlined) ). */
	public function cmd( $c, $literal = null ) {
		$tag = 'S' . ( ++$this->n );
		if ( null !== $literal ) {
			fwrite( $this->s, $tag . ' ' . $c . ' {' . strlen( $literal ) . "}\r\n" );
			$l = fgets( $this->s );
			if ( 0 !== strpos( (string) $l, '+' ) ) { return array( false, array( (string) $l ) ); }
			fwrite( $this->s, $literal . "\r\n" );
		} else {
			fwrite( $this->s, $tag . ' ' . $c . "\r\n" );
		}
		$out = array(); $cur = '';
		while ( true ) {
			$l = fgets( $this->s );
			if ( false === $l ) { return array( false, $out ); }
			if ( preg_match( '/\{(\d+)\}\r\n$/', $l, $m ) ) {
				$need = (int) $m[1]; $data = '';
				while ( strlen( $data ) < $need ) { $chunk = fread( $this->s, min( 65536, $need - strlen( $data ) ) ); if ( false === $chunk || '' === $chunk ) { break; } $data .= $chunk; }
				$cur .= $l . $data;
				continue;
			}
			$cur .= $l;
			if ( 0 === strpos( $cur, $tag . ' ' ) ) { return array( 0 === strpos( $cur, $tag . ' OK' ), $out ); }
			$out[] = $cur; $cur = '';
		}
	}

	public function select( $box = 'INBOX' ) {
		list( $ok, $r ) = $this->cmd( 'EXAMINE ' . $this->q( $box ) ); // read-only: nothing is changed in the mailbox
		$v = 0; foreach ( $r as $x ) { if ( preg_match( '/UIDVALIDITY (\d+)/', $x, $m ) ) { $v = (int) $m[1]; } }
		return $ok ? $v : false;
	}

	public function search( $criteria ) {
		list( $ok, $r ) = $this->cmd( 'UID SEARCH ' . $criteria );
		$ids = array();
		foreach ( $r as $x ) { if ( 0 === strpos( $x, '* SEARCH' ) ) { foreach ( preg_split( '/\s+/', trim( substr( $x, 8 ) ) ) as $u ) { if ( ctype_digit( $u ) ) { $ids[] = (int) $u; } } } }
		sort( $ids );
		return $ok ? $ids : array();
	}

	public function fetch( $uid ) {
		list( $ok, $r ) = $this->cmd( 'UID FETCH ' . (int) $uid . ' (UID BODY.PEEK[])' );
		foreach ( $r as $x ) { if ( preg_match( '/BODY\[\] \{(\d+)\}\r\n/', $x, $m, PREG_OFFSET_CAPTURE ) ) { return substr( $x, $m[0][1] + strlen( $m[0][0] ), (int) $m[1][0] ); } }
		return '';
	}

	public function sent_folder() {
		list( $ok, $r ) = $this->cmd( 'LIST "" "*"' );
		$pick = '';
		foreach ( $r as $x ) {
			if ( ! preg_match( '/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/', trim( $x ), $m ) ) { continue; }
			$name = trim( $m[2] ); $name = '"' === $name[0] ? stripcslashes( substr( $name, 1, -1 ) ) : $name;
			if ( false !== stripos( $m[1], '\\Sent' ) ) { return $name; }
			if ( ! $pick && preg_match( '/(^|[.\/])Sent( Items| Messages)?$/i', $name ) ) { $pick = $name; }
		}
		return $pick;
	}

	public function append( $box, $mime ) {
		list( $ok ) = $this->cmd( 'APPEND ' . $this->q( $box ) . ' (\\Seen)', $mime );
		return $ok;
	}

	public function close() { if ( $this->s ) { @fwrite( $this->s, 'S0 LOGOUT' . "\r\n" ); @fclose( $this->s ); $this->s = null; } } // phpcs:ignore
}

/** A small MIME reader: headers, text, HTML and attachment names. */
class SDSC_Mime {
	public static function split( $raw ) {
		$p = preg_split( "/\r?\n\r?\n/", $raw, 2 );
		return array( $p[0], $p[1] ?? '' );
	}
	public static function headers( $head ) {
		$head = preg_replace( "/\r?\n[ \t]+/", ' ', $head );
		$h = array();
		foreach ( preg_split( "/\r?\n/", $head ) as $l ) { if ( false !== strpos( $l, ':' ) ) { list( $k, $v ) = explode( ':', $l, 2 ); $h[ strtolower( trim( $k ) ) ][] = trim( $v ); } }
		return $h;
	}
	public static function word( $s ) {
		if ( false === strpos( (string) $s, '=?' ) ) { return (string) $s; }
		$d = function_exists( 'iconv_mime_decode' ) ? @iconv_mime_decode( $s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ) : false; // phpcs:ignore
		return false === $d ? mb_decode_mimeheader( $s ) : $d;
	}
	public static function param( $v, $name ) {
		if ( preg_match( '/' . $name . '\*?=\s*(?:"([^"]*)"|([^;\s]+))/i', (string) $v, $m ) ) {
			$x = '' !== $m[1] ? $m[1] : $m[2];
			if ( preg_match( "/^([\w-]+)'[^']*'(.*)$/", $x, $e ) ) { $x = mb_convert_encoding( rawurldecode( $e[2] ), 'UTF-8', $e[1] ); }
			return self::word( $x );
		}
		return '';
	}
	public static function decode( $body, $enc, $charset ) {
		$enc = strtolower( trim( (string) $enc ) );
		if ( 'base64' === $enc ) { $body = base64_decode( preg_replace( '/\s+/', '', $body ) ); }
		elseif ( 'quoted-printable' === $enc ) { $body = quoted_printable_decode( $body ); }
		$charset = strtolower( trim( (string) $charset ) );
		if ( $charset && ! in_array( $charset, array( 'utf-8', 'utf8', 'us-ascii' ), true ) ) {
			$c = @mb_convert_encoding( $body, 'UTF-8', $charset ); // phpcs:ignore
			if ( false !== $c ) { $body = $c; }
		}
		return $body;
	}
	/** array( text, html, attachments[ name => size ] ) */
	public static function parts( $raw, $depth = 0 ) {
		list( $head, $body ) = self::split( $raw );
		$h  = self::headers( $head );
		$ct = $h['content-type'][0] ?? 'text/plain';
		$type = strtolower( trim( explode( ';', $ct )[0] ) );
		$cd = $h['content-disposition'][0] ?? '';
		$fn = self::param( $cd, 'filename' ); if ( '' === $fn ) { $fn = self::param( $ct, 'name' ); }
		$out = array( '', '', array() );
		if ( 0 === strpos( $type, 'multipart/' ) && $depth < 8 ) {
			$b = self::param( $ct, 'boundary' );
			if ( '' === $b ) { return $out; }
			$chunks = explode( '--' . $b, $body );
			array_shift( $chunks );
			foreach ( $chunks as $c ) {
				if ( 0 === strpos( $c, '--' ) ) { break; }
				$c = ltrim( $c, "\r\n" );
				list( $t, $hh, $a ) = self::parts( $c, $depth + 1 );
				if ( '' === $out[0] && '' !== $t ) { $out[0] = $t; }
				if ( '' === $out[1] && '' !== $hh ) { $out[1] = $hh; }
				$out[2] = array_merge( $out[2], $a );
			}
			return $out;
		}
		$is_att = '' !== $fn || 0 === stripos( $cd, 'attachment' ) || 'message/rfc822' === $type;
		if ( $is_att ) { $data = self::decode( $body, $h['content-transfer-encoding'][0] ?? '', '' ); $out[2][ '' !== $fn ? $fn : $type ] = strlen( $data ); return $out; }
		$data = self::decode( $body, $h['content-transfer-encoding'][0] ?? '', self::param( $ct, 'charset' ) );
		if ( 'text/plain' === $type ) { $out[0] = $data; }
		elseif ( 'text/html' === $type ) { $out[1] = $data; }
		return $out;
	}
	public static function address( $v ) {
		$v = self::word( (string) $v );
		if ( preg_match( '/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $v, $m ) ) { return array( strtolower( trim( $m[2] ) ), trim( $m[1] ) ); }
		if ( preg_match( '/[\w.+\'-]+@[\w.-]+\.\w+/', $v, $m ) ) { return array( strtolower( $m[0] ), '' ); }
		return array( '', trim( $v ) );
	}
	public static function html_to_text( $h ) {
		$h = preg_replace( '#<(script|style|head)[^>]*>.*?</\1>#is', '', (string) $h );
		$h = preg_replace( '#<br\s*/?>#i', "\n", $h );
		$h = preg_replace( '#</(p|div|tr|li|h\d|blockquote)>#i', "\n", $h );
		$t = html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( "/[ \t]+\n/", "\n", $t ) ) );
	}
}

class SDSC_Inbox {

	const DBV  = '1';
	const DBO  = 'sdsc_inbox_db';
	const OPT  = 'sdsc_inbox';
	const PAGE = 'sdsc-inbox';
	const HOOK = 'sdsc_inbox_fetch';
	const MARK = '—— Reply above this line to answer ——';

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_inbox'; }

	public static function init() {
		add_action( 'init', array( __CLASS__, 'install' ), 5 );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
		add_action( self::HOOK, array( __CLASS__, 'fetch' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 26 );
		add_action( 'admin_post_sdsc_inbox', array( __CLASS__, 'post' ) );
	}

	public static function install() {
		if ( self::DBV === get_option( self::DBO ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(4) NOT NULL DEFAULT 'in',
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			uid bigint(20) unsigned NOT NULL DEFAULT 0,
			msgid varchar(255) NOT NULL DEFAULT '',
			from_email varchar(190) NOT NULL DEFAULT '',
			from_name varchar(190) NOT NULL DEFAULT '',
			to_email varchar(190) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			body_text mediumtext NULL,
			body_html mediumtext NULL,
			atts text NULL,
			status varchar(10) NOT NULL DEFAULT 'new',
			note varchar(255) NOT NULL DEFAULT '',
			by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			fwd_at datetime DEFAULT NULL,
			sent_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY parent (parent_id),
			KEY msgid (msgid(100))
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	/* ------------------------------------------------------------ settings */

	public static function opts() {
		$o = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array( 'on' => 0, 'fwd' => array(), 'since' => 0, 'last' => 0, 'valid' => 0, 'checked' => 0, 'err' => '' ) );
	}
	protected static function save_opts( $o ) { update_option( self::OPT, $o, false ); }

	/** Only the public site reads the real mailbox. */
	public static function live_site() {
		return 'samsdrivingschool.org' === preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	public static function can() {
		if ( ! is_user_logged_in() ) { return false; }
		if ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview( get_current_user_id() ) ) { return false; }
		return current_user_can( 'manage_options' ) || ( class_exists( 'SDSC_Access' ) && in_array( SDSC_Access::MANAGER, (array) wp_get_current_user()->roles, true ) );
	}

	/** People who may get forwards (administrators and school managers, never preview accounts). */
	public static function staff() {
		$out = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator', 'sdsc_manager' ) ) ) as $u ) {
			if ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview( $u->ID ) ) { continue; }
			if ( ! is_email( $u->user_email ) || false !== strpos( $u->user_email, '.invalid' ) ) { continue; }
			$out[ $u->ID ] = $u;
		}
		return $out;
	}

	protected static function fwd_emails() {
		$st = self::staff(); $o = self::opts(); $e = array();
		foreach ( (array) $o['fwd'] as $id ) { if ( isset( $st[ $id ] ) ) { $e[] = strtolower( $st[ $id ]->user_email ); } }
		return array_values( array_unique( $e ) );
	}

	protected static function own_addresses() {
		$ids = class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::identities() : array();
		return array_map( function ( $x ) { return strtolower( $x[0] ); }, $ids );
	}

	public static function schedules( $s ) { $s['sdsc_5min'] = array( 'interval' => 300, 'display' => 'Every 5 minutes' ); return $s; }

	public static function schedule() {
		$on = self::opts()['on'] && self::live_site();
		$next = wp_next_scheduled( self::HOOK );
		if ( $on && ! $next ) { wp_schedule_event( time() + 60, 'sdsc_5min', self::HOOK ); }
		if ( ! $on && $next ) { wp_clear_scheduled_hook( self::HOOK ); }
	}

	public static function ref( $id ) { return (int) $id . '-' . substr( hash_hmac( 'sha256', 'inbox|' . (int) $id, wp_salt( 'auth' ) ), 0, 6 ); }

	/* ------------------------------------------------------------ reading the mailbox */

	public static function fetch( $force = false ) {
		$o = self::opts();
		if ( ( ! $o['on'] && ! $force ) || ! self::live_site() || ! class_exists( 'SDSC_Mailer' ) ) { return 'off'; }
		if ( get_transient( 'sdsc_inbox_lock' ) ) { return 'busy'; }
		set_transient( 'sdsc_inbox_lock', 1, 240 );
		list( $user, $pass ) = SDSC_Mailer::imap_account();
		$im = new SDSC_Imap();
		$n = 0;
		if ( ! $user || ! $pass || ! $im->connect( 'imap.hostinger.com', 993, $user, $pass ) ) {
			$o['err'] = $im->err ? $im->err : 'The info@ mailbox password is not saved (Sam\'s School → Email).';
		} else {
			$valid = $im->select( 'INBOX' );
			if ( false === $valid ) {
				$o['err'] = 'Could not open the inbox.';
			} else {
				if ( (int) $o['valid'] !== (int) $valid || ! $o['last'] ) {
					// first run (or the mailbox was rebuilt): start from the messages of the last 7 days
					$uids = $im->search( 'SINCE ' . gmdate( 'd-M-Y', ( $o['since'] ? $o['since'] : time() ) - 7 * DAY_IN_SECONDS ) );
					$o['valid'] = (int) $valid;
				} else {
					$uids = array_values( array_filter( $im->search( 'UID ' . ( (int) $o['last'] + 1 ) . ':*' ), function ( $u ) use ( $o ) { return $u > (int) $o['last']; } ) );
				}
				foreach ( array_slice( $uids, 0, 40 ) as $uid ) {
					$raw = $im->fetch( $uid );
					if ( '' !== $raw ) { self::take( $uid, $raw, $o ); $n++; }
					$o['last'] = max( (int) $o['last'], $uid );
				}
				$o['err'] = '';
			}
		}
		$o['checked'] = time();
		self::save_opts( $o );
		$im->close();
		self::flush_outbox();
		delete_transient( 'sdsc_inbox_lock' );
		return $n;
	}

	/** Store one message, then forward it or pass a staff reply on to the student. */
	public static function take( $uid, $raw, $o ) {
		global $wpdb;
		list( $head ) = SDSC_Mime::split( $raw );
		$h     = SDSC_Mime::headers( $head );
		$msgid = trim( $h['message-id'][0] ?? '', " <>\t" );
		if ( $msgid && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE msgid = %s LIMIT 1', $msgid ) ) ) { return 0; }
		list( $from, $name ) = SDSC_Mime::address( $h['from'][0] ?? '' );
		list( $to ) = SDSC_Mime::address( $h['to'][0] ?? '' );
		$subj  = mb_substr( SDSC_Mime::word( $h['subject'][0] ?? '(no subject)' ), 0, 250 );
		list( $text, $html, $atts ) = SDSC_Mime::parts( $raw );
		if ( '' === trim( $text ) && '' !== $html ) { $text = SDSC_Mime::html_to_text( $html ); }
		$date  = strtotime( $h['date'][0] ?? '' ) ?: time();
		$own   = self::own_addresses();
		$staff = self::fwd_emails();
		// what kind of message is it?
		$auto  = ( isset( $h['auto-submitted'] ) && 'no' !== strtolower( $h['auto-submitted'][0] ) ) || preg_match( '/bulk|junk|list/i', $h['precedence'][0] ?? '' ) || isset( $h['list-unsubscribe'] ) || isset( $h['list-id'] )
			|| preg_match( '/^(mailer-daemon|postmaster|no-?reply|donotreply|do-not-reply)@/i', $from ) || preg_match( '/yes/i', $h['x-spam-flag'][0] ?? '' ) || preg_match( '/^\s*(auto|out of office)/i', $subj );
		$status = 'new'; $note = '';
		if ( in_array( $from, $own, true ) ) { $status = 'other'; $note = 'Sent by the school itself'; }
		elseif ( $auto ) { $status = 'other'; $note = 'Automatic / newsletter / spam — not forwarded'; }
		$old = $o['since'] && $date < (int) $o['since'];
		$wpdb->insert( self::table(), array(
			'kind' => 'in', 'uid' => (int) $uid, 'msgid' => mb_substr( $msgid, 0, 250 ), 'from_email' => mb_substr( $from, 0, 190 ), 'from_name' => mb_substr( $name, 0, 190 ),
			'to_email' => mb_substr( $to, 0, 190 ), 'subject' => $subj, 'body_text' => mb_substr( (string) $text, 0, 200000 ), 'body_html' => mb_substr( (string) $html, 0, 400000 ),
			'atts' => wp_json_encode( $atts ), 'status' => $status, 'note' => $note, 'sent_at' => wp_date( 'Y-m-d H:i:s', $date ), 'created_at' => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return 0; }
		// A staff member answering a forward: pass it on to the student.
		if ( in_array( $from, $staff, true ) && preg_match( '/\[SDS #(\d+)-([a-f0-9]{6})\]/', $subj, $m ) ) {
			$orig = (int) $m[1];
			$auth = strtolower( implode( ' ', (array) ( $h['authentication-results'] ?? array() ) ) );
			if ( ! hash_equals( self::ref( $orig ), $orig . '-' . $m[2] ) ) { $wpdb->update( self::table(), array( 'status' => 'other', 'note' => 'Reference did not match — not sent on' ), array( 'id' => $id ) ); return $id; }
			if ( $auth && preg_match( '/(dkim|spf|dmarc)=fail/', $auth ) && ! preg_match( '/dkim=pass/', $auth ) ) { $wpdb->update( self::table(), array( 'status' => 'new', 'note' => 'Could not confirm the sender — check before answering' ), array( 'id' => $id ) ); return $id; }
			$answer = self::strip_quote( $text );
			if ( '' === trim( $answer ) ) { $wpdb->update( self::table(), array( 'status' => 'other', 'note' => 'Empty reply — nothing sent' ), array( 'id' => $id ) ); return $id; }
			$u = get_user_by( 'email', $from );
			$r = self::reply( $orig, $answer, $u ? $u->ID : 0, 'by e-mail' );
			$wpdb->update( self::table(), array( 'kind' => 'staf', 'parent_id' => $orig, 'status' => $r[0] ? 'done' : 'new', 'note' => $r[0] ? 'Sent on to the student' : 'Could not send on: ' . $r[1] ), array( 'id' => $id ) );
			return $id;
		}
		if ( 'new' === $status && ! $old && ! in_array( $from, $staff, true ) ) { self::queue_forward( $id ); }
		return $id;
	}

	/** Keep only what the staff member wrote (cut the quoted forward). */
	public static function strip_quote( $t ) {
		$t = str_replace( "\r\n", "\n", (string) $t );
		$cut = strlen( $t );
		foreach ( array( '/^.*Reply above this line.*$/mi', '/^-{2,}\s*Original Message\s*-{2,}/mi', '/^_{5,}\s*$/m', '/^From:\s.*samsdrivingschool/mi', '/^On .{0,300}?wrote:\s*$/msi', '/^El .{0,300}?escribió:\s*$/msi' ) as $re ) {
			if ( preg_match( $re, $t, $m, PREG_OFFSET_CAPTURE ) && $m[0][1] < $cut ) { $cut = $m[0][1]; }
		}
		$t = substr( $t, 0, $cut );
		$lines = array_filter( explode( "\n", $t ), function ( $l ) { return 0 !== strpos( ltrim( $l ), '>' ); } );
		return trim( implode( "\n", $lines ) );
	}

	/* ------------------------------------------------------------ forwarding to staff */

	protected static function queue_forward( $id ) {
		$q = (array) get_option( 'sdsc_inbox_outbox', array() );
		$q[] = (int) $id;
		update_option( 'sdsc_inbox_outbox', array_values( array_unique( $q ) ), false );
	}

	/** Forwards are sent after the mailbox is closed (sending takes a few seconds each). */
	public static function flush_outbox() {
		$q = (array) get_option( 'sdsc_inbox_outbox', array() );
		if ( ! $q ) { return; }
		update_option( 'sdsc_inbox_outbox', array(), false );
		foreach ( array_slice( $q, 0, 20 ) as $id ) { self::forward( (int) $id ); }
		if ( count( $q ) > 20 ) { update_option( 'sdsc_inbox_outbox', array_slice( $q, 20 ), false ); }
	}

	public static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	public static function forward( $id ) {
		global $wpdb;
		$r  = self::row( $id );
		$to = self::fwd_emails();
		if ( ! $r || ! $to || $r['fwd_at'] ) { return false; }
		$who  = trim( $r['from_name'] ) ? $r['from_name'] . ' <' . $r['from_email'] . '>' : $r['from_email'];
		$atts = json_decode( (string) $r['atts'], true );
		$atts = is_array( $atts ) ? array_keys( $atts ) : array();
		$f    = 'font-family:Arial,Helvetica,sans-serif;';
		$body = '<p style="' . $f . 'color:#8a939b;font-size:12px;margin:0 0 10px">' . esc_html( self::MARK ) . '</p>'
			. '<p style="' . $f . 'margin:0 0 6px"><b>New e-mail to ' . esc_html( $r['to_email'] ? $r['to_email'] : 'info@' ) . '</b></p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" style="' . $f . 'font-size:14px;margin:0 0 14px">'
			. '<tr><td style="color:#5b646d;padding:2px 12px 2px 0">From</td><td>' . esc_html( $who ) . '</td></tr>'
			. '<tr><td style="color:#5b646d;padding:2px 12px 2px 0">Date</td><td>' . esc_html( wp_date( 'D, M j, Y g:i a', strtotime( $r['sent_at'] ) ) ) . '</td></tr>'
			. '<tr><td style="color:#5b646d;padding:2px 12px 2px 0">Subject</td><td>' . esc_html( $r['subject'] ) . '</td></tr>'
			. ( $atts ? '<tr><td style="color:#5b646d;padding:2px 12px 2px 0">Attachments</td><td>' . esc_html( implode( ', ', $atts ) ) . ' (open them in the panel or webmail)</td></tr>' : '' )
			. '</table>'
			. '<div style="' . $f . 'border-left:3px solid #00703c;padding:6px 0 6px 14px;white-space:pre-wrap;font-size:14px;line-height:1.55">' . esc_html( mb_substr( (string) $r['body_text'], 0, 20000 ) ) . '</div>'
			. '<p style="' . $f . 'margin:18px 0 0;font-size:13px;color:#5b646d"><b>To answer:</b> just reply to this e-mail. Your answer is sent to ' . esc_html( $r['from_email'] ) . ' from info@samsdrivingschool.org within about 5 minutes. Or open it in the panel: <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&id=' . (int) $id ) ) . '">Inbox</a>.</p>';
		$subj = '[SDS #' . self::ref( $id ) . '] ' . $r['subject'] . ' — ' . ( trim( $r['from_name'] ) ? $r['from_name'] : $r['from_email'] );
		$ids  = SDSC_Mailer::identities();
		$ok   = SDSC_Mailer::send( $to, $subj, $body, 'info', array( 'Content-Type: text/html; charset=UTF-8', 'X-SDSC-Inbox: fwd', 'Reply-To: ' . $ids['info'][1] . ' <' . $ids['info'][0] . '>' ) );
		$wpdb->update( self::table(), array( 'fwd_at' => $ok ? current_time( 'mysql' ) : null, 'note' => $ok ? 'Forwarded to ' . implode( ', ', $to ) : 'Forward failed' ), array( 'id' => (int) $id ) );
		return $ok;
	}

	/* ------------------------------------------------------------ answering the student */

	/** Send $text to the sender of message $id from info@ (same conversation). Returns array( ok, message ). */
	public static function reply( $id, $text, $uid, $how = 'from the panel', $cc = array() ) {
		global $wpdb, $phpmailer;
		$r = self::row( $id );
		if ( ! $r || 'in' !== $r['kind'] || ! is_email( $r['from_email'] ) ) { return array( false, 'Message not found.' ); }
		$text = trim( (string) $text );
		if ( '' === $text ) { return array( false, 'Type an answer.' ); }
		$subj = 'Re: ' . preg_replace( '/^((re|fw|fwd)\s*:\s*)+/i', '', $r['subject'] );
		$q    = '<p style="color:#8a939b;font-size:12px;margin:22px 0 4px">On ' . esc_html( wp_date( 'M j, Y g:i a', strtotime( $r['sent_at'] ) ) ) . ', ' . esc_html( $r['from_name'] ? $r['from_name'] : $r['from_email'] ) . ' wrote:</p>'
			. '<blockquote style="margin:0;padding:0 0 0 12px;border-left:3px solid #d9dee2;color:#5b646d;white-space:pre-wrap;font-size:13px">' . esc_html( mb_substr( (string) $r['body_text'], 0, 4000 ) ) . '</blockquote>';
		$body = '<div style="white-space:pre-wrap">' . esc_html( $text ) . '</div>' . $q;
		$hdr  = array( 'Content-Type: text/html; charset=UTF-8', 'X-SDSC-Inbox: reply' );
		$cc   = array_values( array_filter( array_map( 'sanitize_email', is_array( $cc ) ? $cc : preg_split( '/[\s,;]+/', (string) $cc ) ), 'is_email' ) );
		if ( $cc ) { $hdr[] = 'Cc: ' . implode( ', ', $cc ); }
		if ( $r['msgid'] ) { $hdr[] = 'In-Reply-To: <' . $r['msgid'] . '>'; $hdr[] = 'References: <' . $r['msgid'] . '>'; }
		$ok = SDSC_Mailer::send( $r['from_email'], $subj, $body, 'info', $hdr );
		$mime = ( $ok && isset( $phpmailer ) && is_object( $phpmailer ) && method_exists( $phpmailer, 'getSentMIMEMessage' ) ) ? $phpmailer->getSentMIMEMessage() : '';
		$wpdb->insert( self::table(), array(
			'kind' => 'out', 'parent_id' => (int) $id, 'from_email' => SDSC_Mailer::identities()['info'][0], 'to_email' => $r['from_email'], 'subject' => mb_substr( $subj, 0, 250 ),
			'body_text' => $text, 'status' => $ok ? 'sent' : 'failed', 'note' => 'Answered ' . $how . ( $cc ? ' (cc ' . implode( ', ', $cc ) . ')' : '' ), 'by_user' => (int) $uid, 'sent_at' => current_time( 'mysql' ), 'created_at' => current_time( 'mysql' ),
		) );
		if ( $ok ) {
			$wpdb->update( self::table(), array( 'status' => 'replied' ), array( 'id' => (int) $id ) );
			if ( $mime && self::live_site() ) { self::save_sent( $mime ); }
		}
		return array( (bool) $ok, $ok ? 'Sent to ' . $r['from_email'] . '.' : 'The e-mail could not be sent. Check Sam\'s School → Email.' );
	}

	/** Put a copy of the answer in the mailbox's Sent folder, so webmail shows the whole conversation. */
	protected static function save_sent( $mime ) {
		list( $user, $pass ) = SDSC_Mailer::imap_account();
		$im = new SDSC_Imap();
		if ( $user && $pass && $im->connect( 'imap.hostinger.com', 993, $user, $pass ) ) {
			$box = $im->sent_folder();
			if ( $box ) { $im->append( $box, $mime ); }
		}
		$im->close();
	}

	/* ------------------------------------------------------------ screen */

	public static function count_new() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE kind = 'in' AND status = 'new'" ); // phpcs:ignore
	}

	public static function menu() {
		if ( ! self::can() ) { return; }
		$n = self::count_new();
		add_submenu_page( 'sdsc-dashboard', 'Inbox', 'Inbox' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ), 'read', self::PAGE, array( __CLASS__, 'page' ) );
	}

	protected static function back( $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function post() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_inbox' );
		global $wpdb;
		$do = sanitize_key( $_POST['do'] ?? '' );
		$id = absint( $_POST['id'] ?? 0 );
		if ( 'reply' === $do ) {
			$r = self::reply( $id, wp_unslash( $_POST['text'] ?? '' ), get_current_user_id(), 'from the panel', wp_unslash( $_POST['cc'] ?? '' ) ); // phpcs:ignore
			self::back( array( 'id' => $id, 'msg' => rawurlencode( ( $r[0] ? '' : '!' ) . $r[1] ) ) );
		}
		if ( in_array( $do, array( 'done', 'other', 'new' ), true ) ) {
			$wpdb->update( self::table(), array( 'status' => $do ), array( 'id' => $id, 'kind' => 'in' ) );
			self::back( 'new' === $do ? array( 'id' => $id ) : array() );
		}
		if ( 'check' === $do ) {
			$n = self::fetch( true );
			$o = self::opts();
			self::back( array( 'msg' => rawurlencode( $o['err'] ? '!' . $o['err'] : ( 'busy' === $n ? 'A check is already running.' : ( 'off' === $n ? '!The inbox only runs on the public site.' : $n . ' new message(s).' ) ) ) ) );
		}
		if ( 'settings' === $do && current_user_can( 'manage_options' ) ) {
			$o   = self::opts();
			$on  = ! empty( $_POST['on'] ) ? 1 : 0;
			$fwd = array_values( array_intersect( array_map( 'absint', (array) ( $_POST['fwd'] ?? array() ) ), array_keys( self::staff() ) ) );
			if ( $on && ! $o['on'] ) { $o['since'] = time(); $o['last'] = 0; $o['valid'] = 0; } // forward only what arrives from now on
			$o['on'] = $on; $o['fwd'] = $fwd;
			self::save_opts( $o );
			self::schedule();
			self::back( array( 'msg' => rawurlencode( 'Settings saved.' ) ) );
		}
		self::back();
	}

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		global $wpdb;
		$o   = self::opts();
		$msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore
		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
		$f   = isset( $_GET['f'] ) ? sanitize_key( $_GET['f'] ) : 'new'; // phpcs:ignore
		$form = function ( $do, $label, $cls = 'button', $extra = '' ) use ( $id ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'sdsc_inbox', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_inbox"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="id" value="' . (int) $id . '">' . $extra . '<button class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</button></form>';
		};
		echo '<div class="wrap sdsc-ib"><h1 class="wp-heading-inline">Inbox</h1> <span style="color:#646970">info@samsdrivingschool.org</span>';
		echo '<style>.sdsc-ib .ib-list td{vertical-align:top}.sdsc-ib .ib-new td{font-weight:600}.sdsc-ib .ib-chip{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11px;background:#f0f0f1}.sdsc-ib .ib-chip.new{background:#fcf0c3}.sdsc-ib .ib-chip.replied,.sdsc-ib .ib-chip.done{background:#d7f2e1}.sdsc-ib .ib-msg{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin:12px 0}.sdsc-ib .ib-body{white-space:pre-wrap;font-size:14px;line-height:1.55}.sdsc-ib iframe{width:100%;min-height:420px;border:1px solid #eee;border-radius:8px;background:#fff}.sdsc-ib textarea{width:100%;min-height:150px}.sdsc-ib .ib-out{border-left:3px solid #00703c;background:#f6fbf8}</style>';
		if ( $msg ) { echo '<div class="notice notice-' . ( 0 === strpos( $msg, '!' ) ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( ltrim( $msg, '!' ) ) . '</p></div>'; }
		if ( ! $o['on'] ) { echo '<div class="notice notice-warning"><p>The inbox is switched off. ' . ( current_user_can( 'manage_options' ) ? 'Switch it on in the settings at the bottom of this page.' : 'The administrator can switch it on.' ) . '</p></div>'; }
		elseif ( $o['err'] ) { echo '<div class="notice notice-error"><p>Last check: ' . esc_html( $o['err'] ) . '</p></div>'; }

		if ( $id ) {
			$r = self::row( $id );
			if ( ! $r || 'in' !== $r['kind'] && 'staf' !== $r['kind'] ) { echo '<p>Message not found.</p></div>'; return; }
			if ( 'staf' === $r['kind'] && $r['parent_id'] ) { $r = self::row( (int) $r['parent_id'] ); $id = (int) $r['id']; }
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">← All messages</a></p>';
			$atts = json_decode( (string) $r['atts'], true );
			echo '<div class="ib-msg"><h2 style="margin-top:0">' . esc_html( $r['subject'] ) . '</h2>';
			echo '<p><b>' . esc_html( $r['from_name'] ? $r['from_name'] : $r['from_email'] ) . '</b> &lt;' . esc_html( $r['from_email'] ) . '&gt; · ' . esc_html( wp_date( 'D, M j, Y g:i a', strtotime( $r['sent_at'] ) ) ) . ' <span class="ib-chip ' . esc_attr( $r['status'] ) . '">' . esc_html( $r['status'] ) . '</span>' . ( $r['note'] ? ' <span style="color:#646970">' . esc_html( $r['note'] ) . '</span>' : '' ) . '</p>';
			if ( is_array( $atts ) && $atts ) { echo '<p>📎 ' . esc_html( implode( ', ', array_keys( $atts ) ) ) . ' <span style="color:#646970">— open attachments in webmail</span></p>'; }
			if ( trim( (string) $r['body_html'] ) ) {
				echo '<iframe sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="' . esc_attr( '<base target="_blank"><meta charset="utf-8">' . wp_kses_post( $r['body_html'] ) ) . '"></iframe>';
			} else {
				echo '<div class="ib-body">' . esc_html( (string) $r['body_text'] ) . '</div>';
			}
			echo '</div>';
			$thread = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE parent_id = %d AND kind = 'out' ORDER BY id", $id ), ARRAY_A );
			foreach ( $thread as $t ) {
				$u = $t['by_user'] ? get_userdata( (int) $t['by_user'] ) : null;
				echo '<div class="ib-msg ib-out"><p><b>' . esc_html( $u ? $u->display_name : 'Staff' ) . '</b> → ' . esc_html( $t['to_email'] ) . ' · ' . esc_html( wp_date( 'M j, g:i a', strtotime( $t['sent_at'] ) ) ) . ' · ' . esc_html( $t['note'] ) . ( 'failed' === $t['status'] ? ' <b style="color:#b32d2e">(not sent)</b>' : '' ) . '</p><div class="ib-body">' . esc_html( (string) $t['body_text'] ) . '</div></div>';
			}
			echo '<div class="ib-msg"><h3 style="margin-top:0">Answer ' . esc_html( $r['from_email'] ) . '</h3><p style="color:#646970">Sent from info@samsdrivingschool.org, in the same conversation.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'sdsc_inbox', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_inbox"><input type="hidden" name="do" value="reply"><input type="hidden" name="id" value="' . (int) $id . '"><textarea name="text" required placeholder="Hi …"></textarea><p><label>Cc <input type="text" name="cc" class="regular-text" placeholder="optional, e.g. a colleague’s e-mail"></label></p><p><button class="button button-primary">Send answer</button></p></form>';
			echo '<p>' . $form( 'done', 'Mark as handled' ) . ' ' . $form( 'other', 'Not for us (no answer needed)' ) . ' ' . $form( 'new', 'Mark as new' ) . '</p></div></div>'; // phpcs:ignore
			if ( 'new' === $r['status'] ) { $wpdb->update( self::table(), array( 'status' => 'read' ), array( 'id' => $id ) ); }
			return;
		}

		$tabs  = array( 'new' => 'To answer', 'all' => 'All', 'replied' => 'Answered', 'other' => 'Other' );
		echo '<ul class="subsubsub">';
		foreach ( $tabs as $k => $l ) { echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&f=' . $k ) ) . '"' . ( $f === $k ? ' class="current"' : '' ) . '>' . esc_html( $l ) . '</a> | </li>'; }
		echo '</ul><div style="clear:both;padding-top:6px">' . $form( 'check', 'Check now' ) . ' <span style="color:#646970">Checked ' . ( $o['checked'] ? esc_html( human_time_diff( $o['checked'] ) ) . ' ago' : 'never' ) . ' · checks every 5 minutes</span></div>'; // phpcs:ignore
		$where = array( 'new' => "status IN ('new','read')", 'all' => '1=1', 'replied' => "status IN ('replied','done')", 'other' => "status = 'other'" );
		$rows  = $wpdb->get_results( 'SELECT id, from_email, from_name, subject, status, note, sent_at, fwd_at FROM ' . self::table() . " WHERE kind = 'in' AND " . ( $where[ $f ] ?? $where['new'] ) . ' ORDER BY sent_at DESC LIMIT 200', ARRAY_A ); // phpcs:ignore
		echo '<table class="widefat striped ib-list" style="margin-top:10px"><thead><tr><th style="width:240px">From</th><th>Subject</th><th style="width:150px">Received</th><th style="width:110px">Status</th></tr></thead><tbody>';
		if ( ! $rows ) { echo '<tr><td colspan="4">Nothing here.</td></tr>'; }
		foreach ( $rows as $x ) {
			$url = admin_url( 'admin.php?page=' . self::PAGE . '&id=' . (int) $x['id'] );
			echo '<tr class="' . ( 'new' === $x['status'] ? 'ib-new' : '' ) . '"><td><a href="' . esc_url( $url ) . '">' . esc_html( $x['from_name'] ? $x['from_name'] : $x['from_email'] ) . '</a><br><small>' . esc_html( $x['from_email'] ) . '</small></td><td><a href="' . esc_url( $url ) . '">' . esc_html( $x['subject'] ) . '</a>' . ( $x['note'] ? '<br><small style="color:#646970">' . esc_html( $x['note'] ) . '</small>' : '' ) . '</td><td>' . esc_html( wp_date( 'M j, g:i a', strtotime( $x['sent_at'] ) ) ) . '</td><td><span class="ib-chip ' . esc_attr( $x['status'] ) . '">' . esc_html( $x['status'] ) . '</span></td></tr>';
		}
		echo '</tbody></table>';

		if ( current_user_can( 'manage_options' ) ) {
			echo '<h2 style="margin-top:30px">Settings</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'sdsc_inbox', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_inbox"><input type="hidden" name="do" value="settings">'; // phpcs:ignore
			echo '<p><label><input type="checkbox" name="on" value="1"' . checked( $o['on'], 1, false ) . '> Read the info@ mailbox and show new e-mails here' . ( self::live_site() ? '' : ' <b>(only works on the public site)</b>' ) . '</label></p>';
			echo '<p><b>Forward every new e-mail to</b> (they can answer the student just by replying):</p>';
			foreach ( self::staff() as $u ) { echo '<p style="margin:2px 0"><label><input type="checkbox" name="fwd[]" value="' . (int) $u->ID . '"' . checked( in_array( $u->ID, (array) $o['fwd'], true ), true, false ) . '> ' . esc_html( $u->display_name ) . ' — ' . esc_html( $u->user_email ) . '</label></p>'; }
			echo '<p class="description">Only e-mails that arrive after the inbox is switched on are forwarded. Newsletters, automatic replies and spam are never forwarded.</p><p><button class="button button-primary">Save</button></p></form>';
		}
		echo '</div>';
	}
}
SDSC_Inbox::init();
