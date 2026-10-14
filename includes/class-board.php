<?php
/**
 * Class board: every open class as a column, every student as a card.
 * Drag a card to another class (or use "Move to"), record cash / check payments,
 * add walk-in students and change the number of seats. Only the administrator and
 * the school manager see it.
 *
 * Seats: _sdsc_capacity on the class (default 30). The public registration form
 * refuses a full class (see SDSC_Registration::save + SDSC_Board::is_full).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Board {
	const PAGE        = 'sdsc-board';
	const DEFAULT_CAP = 30;
	const DBO         = 'sdsc_offpay_db';
	const DBV         = '1';
	const LOG         = 'sdsc_board_log';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		foreach ( array( 'move', 'seats', 'pay', 'void', 'add' ) as $a ) {
			add_action( 'wp_ajax_sdsc_board_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_offline_payments'; }

	public static function maybe_install() {
		if ( self::DBV === get_option( self::DBO ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reg_id bigint(20) unsigned NOT NULL DEFAULT 0,
			registrant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			class_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			method varchar(10) NOT NULL DEFAULT 'cash',
			amount int(11) unsigned NOT NULL DEFAULT 0,
			ref varchar(60) NOT NULL DEFAULT '',
			paid_on date DEFAULT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			status varchar(8) NOT NULL DEFAULT 'paid',
			by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			voided_at datetime DEFAULT NULL,
			voided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY reg (reg_id),
			KEY registrant (registrant_id),
			KEY email (email)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	/** Administrator or school manager only (never the front desk, never a "preview as" account). */
	public static function can() {
		if ( ! is_user_logged_in() ) { return false; }
		if ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview( get_current_user_id() ) ) { return false; }
		if ( current_user_can( 'manage_options' ) ) { return true; }
		return class_exists( 'SDSC_Access' ) && in_array( SDSC_Access::MANAGER, (array) wp_get_current_user()->roles, true );
	}

	public static function menu() {
		if ( ! self::can() ) { return; }
		add_submenu_page( 'sdsc-dashboard', 'Class board', 'Class board', 'read', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/* ------------------------------------------------------------ seats */

	public static function cap( $cid ) {
		$c = (int) get_post_meta( $cid, '_sdsc_capacity', true );
		return $c > 0 ? $c : self::DEFAULT_CAP;
	}

	public static function taken( $cid ) {
		return class_exists( 'SDSC_Class_Feed' ) ? (int) SDSC_Class_Feed::seats_taken( $cid ) : 0;
	}

	public static function is_full( $cid ) {
		return self::taken( $cid ) >= self::cap( $cid );
	}

	protected static function seats_of( $ids ) {
		$o = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$o[ $id ] = array( 'taken' => self::taken( $id ), 'cap' => self::cap( $id ) );
		}
		return $o;
	}

	/* ------------------------------------------------------------ data */

	protected static function classes() {
		$out = array();
		foreach ( SDSC_Registration::open_classes( true ) as $c ) {
			if ( ! get_post_meta( $c->ID, '_sdsc_capacity', true ) ) { update_post_meta( $c->ID, '_sdsc_capacity', self::DEFAULT_CAP ); }
			$out[ (int) $c->ID ] = $c;
		}
		return $out;
	}

	protected static function rt() { return SDSC_Registration::table(); }

	/** How far back "No class yet" looks: 30 days, or 120 with ?older=1. */
	protected static function days() { return empty( $_GET['older'] ) ? 30 : 120; } // phpcs:ignore

	protected static function offline_for( $reg_id, $registrant_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'paid' AND ( ( reg_id > 0 AND reg_id = %d ) OR ( registrant_id > 0 AND registrant_id = %d ) ) ORDER BY id", (int) $reg_id, (int) $registrant_id ), ARRAY_A ); // phpcs:ignore
	}

	/** One card's data. $r: registration row (object) or null; $pid: registrant post id. */
	protected static function card_data( $r, $pid = 0 ) {
		$pid = $r ? (int) $r->registrant_id : (int) $pid;
		if ( $pid && ! get_post( $pid ) ) { $pid = 0; }
		$name  = $r ? trim( $r->first_name . ' ' . $r->last_name ) : (string) get_post_meta( $pid, '_sdsc_name', true );
		$email = $r ? (string) $r->email : (string) get_post_meta( $pid, '_sdsc_email', true );
		$phone = $r ? (string) $r->phone : (string) get_post_meta( $pid, '_sdsc_phone', true );
		$pay   = array();
		if ( $r && class_exists( 'SDSC_Pay' ) ) {
			$online = SDSC_Pay::paid_row( (int) $r->id );
			if ( $online ) { $pay[] = array( 'k' => 'online', 't' => 'Paid online $' . number_format( $online['amount'] / 100, 2 ) ); }
		}
		$off = self::offline_for( $r ? (int) $r->id : 0, $pid );
		foreach ( $off as $o ) {
			$pay[] = array( 'k' => 'offline', 'id' => (int) $o['id'], 't' => self::method_label( $o['method'] ) . ( $o['ref'] ? ' #' . $o['ref'] : '' ) . ' $' . number_format( $o['amount'] / 100, 2 ) . ( $o['paid_on'] ? ' · ' . wp_date( 'M j', strtotime( $o['paid_on'] . ' 12:00' ) ) : '' ) );
		}
		if ( ! $pay && $pid && get_post_meta( $pid, '_sdsc_paid', true ) ) { $pay[] = array( 'k' => 'marked', 't' => 'Marked paid' ); }
		if ( ! $pay && class_exists( 'SDSC_Gate' ) && $email && ( SDSC_Gate::opened( $email ) || ( $r && $r->parent_email && SDSC_Gate::opened( $r->parent_email ) ) ) ) {
			$pay[] = array( 'k' => 'opened', 't' => 'Course access opened' );
		}
		return array(
			'key'   => $r ? 'r' . (int) $r->id : 'p' . $pid,
			'reg'   => $r ? (int) $r->id : 0,
			'pid'   => $pid,
			'cid'   => $r ? (int) $r->class_id : (int) get_post_meta( $pid, '_sdsc_class_id', true ),
			'no'    => $pid ? (int) get_post_meta( $pid, '_sdsc_student_no', true ) : 0,
			'name'  => '' !== $name ? $name : '(no name)',
			'email' => $email,
			'phone' => $phone,
			'src'   => $r ? (string) $r->source : 'office',
			'date'  => $r ? (string) $r->created_at : (string) get_post_meta( $pid, '_sdsc_registered_at', true ),
			'pay'   => $pay,
		);
	}

	protected static function method_label( $m ) {
		$l = array( 'cash' => 'Cash', 'check' => 'Check', 'zelle' => 'Zelle', 'other' => 'Other' );
		return $l[ $m ] ?? 'Other';
	}

	/** All cards grouped by column (0 = no class yet). */
	protected static function cards( $classes ) {
		global $wpdb;
		$ids   = array_keys( $classes );
		$since = wp_date( 'Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS );
		$in    = $ids ? implode( ',', array_map( 'intval', $ids ) ) : '0';
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, source, class_id, registrant_id, first_name, last_name, email, phone, parent_email FROM ' . self::rt() . " WHERE source <> 'demo' AND ( class_id IN ($in) OR ( ( class_id IS NULL OR class_id = 0 ) AND created_at > %s ) ) ORDER BY created_at", $since ) ); // phpcs:ignore
		$cols  = array( 0 => array() );
		foreach ( $ids as $id ) { $cols[ $id ] = array(); }
		$seen  = array();
		$count = array();
		foreach ( $rows as $r ) {
			$c = isset( $cols[ (int) $r->class_id ] ) ? (int) $r->class_id : 0;
			$d = self::card_data( $r );
			$cols[ $c ][] = $d;
			if ( $d['pid'] ) { $seen[ $d['pid'] ] = 1; }
			$e = strtolower( $d['email'] );
			if ( $e ) { $count[ $e ] = ( $count[ $e ] ?? 0 ) + 1; }
		}
		foreach ( $ids as $id ) { // students added in the old Class Manager screen (no registration form)
			foreach ( SDSC_CPT_Registrants::get_for_class( $id ) as $p ) {
				if ( isset( $seen[ $p->ID ] ) || 'trash' === $p->post_status ) { continue; }
				$d = self::card_data( null, $p->ID );
				$cols[ $id ][] = $d;
				$e = strtolower( $d['email'] );
				if ( $e ) { $count[ $e ] = ( $count[ $e ] ?? 0 ) + 1; }
			}
		}
		foreach ( $cols as $k => $list ) {
			foreach ( $list as $i => $d ) { $cols[ $k ][ $i ]['dup'] = $d['email'] && ( $count[ strtolower( $d['email'] ) ] ?? 0 ) > 1; }
		}
		return $cols;
	}

	/* ------------------------------------------------------------ actions */

	protected static function guard() {
		if ( ! self::can() ) { wp_send_json_error( array( 'msg' => 'Only the administrator and the school manager can do this.' ), 403 ); }
		check_ajax_referer( 'sdsc_board', 'nonce' );
	}

	protected static function log( $txt ) {
		$l = (array) get_option( self::LOG, array() );
		array_unshift( $l, array( 'at' => current_time( 'mysql' ), 'by' => wp_get_current_user()->display_name, 't' => $txt ) );
		update_option( self::LOG, array_slice( $l, 0, 150 ), false );
	}

	protected static function cname( $cid ) {
		return $cid ? ( class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::class_name( $cid, true ) : get_the_title( $cid ) ) : 'No class yet';
	}

	/** Put a registrant into a class (new student number, title), creating it when missing. */
	protected static function place( $pid, $cid, $name, $email, $phone, $paid ) {
		$no   = SDSC_CPT_Registrants::next_student_number( $cid );
		$code = (string) get_post_meta( $cid, '_sdsc_course_code', true );
		if ( $pid && get_post( $pid ) ) {
			wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish', 'post_title' => $name . ' — ' . $code . '-' . $no ) );
		} else {
			$pid = wp_insert_post( array( 'post_type' => SDSC_CPT_Registrants::CPT, 'post_status' => 'publish', 'post_title' => $name . ' — ' . $code . '-' . $no ) );
			if ( ! $pid || is_wp_error( $pid ) ) { return 0; }
			update_post_meta( $pid, '_sdsc_name', $name );
			update_post_meta( $pid, '_sdsc_email', $email );
			update_post_meta( $pid, '_sdsc_phone', $phone );
			update_post_meta( $pid, '_sdsc_registered_at', current_time( 'mysql' ) );
			update_post_meta( $pid, '_sdsc_paid', $paid ? 'offline' : '' );
		}
		update_post_meta( $pid, '_sdsc_class_id', $cid );
		update_post_meta( $pid, '_sdsc_student_no', $no );
		return (int) $pid;
	}

	protected static function find( $key ) {
		global $wpdb;
		$key = (string) $key;
		if ( preg_match( '/^r(\d+)$/', $key, $m ) ) {
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::rt() . ' WHERE id = %d', (int) $m[1] ) ); // phpcs:ignore
			return $r ? array( $r, (int) $r->registrant_id ) : array( null, 0 );
		}
		if ( preg_match( '/^p(\d+)$/', $key, $m ) && SDSC_CPT_Registrants::CPT === get_post_type( (int) $m[1] ) ) { return array( null, (int) $m[1] ); }
		return array( null, 0 );
	}

	protected static function reply( $r, $pid, $cids, $extra = array() ) {
		$d = self::card_data( $r, $pid );
		$d['dup'] = false;
		wp_send_json_success( array_merge( array( 'card' => self::card_html( $d, self::classes() ), 'seats' => self::seats_of( $cids ) ), $extra ) );
	}

	public static function ajax_move() {
		self::guard();
		global $wpdb;
		list( $r, $pid ) = self::find( $_POST['key'] ?? '' ); // phpcs:ignore
		$to = absint( $_POST['to'] ?? 0 );
		if ( ! $r && ! $pid ) { wp_send_json_error( array( 'msg' => 'Student not found. Reload the page.' ) ); }
		$classes = self::classes();
		if ( $to && ! isset( $classes[ $to ] ) ) { wp_send_json_error( array( 'msg' => 'That class is not open any more.' ) ); }
		$from = $r ? (int) $r->class_id : (int) get_post_meta( $pid, '_sdsc_class_id', true );
		if ( $from === $to ) { self::reply( $r, $pid, array( $to ) ); }
		if ( ! $to && ! $r ) { wp_send_json_error( array( 'msg' => 'This student was added by hand without a form, so it can only move between classes.' ) ); }
		if ( $to && self::is_full( $to ) ) {
			if ( empty( $_POST['grow'] ) ) { wp_send_json_error( array( 'full' => 1, 'msg' => self::cname( $to ) . ' is full (' . self::taken( $to ) . ' of ' . self::cap( $to ) . ' seats).' ) ); }
			update_post_meta( $to, '_sdsc_capacity', self::taken( $to ) + 1 );
			self::log( 'Seats of ' . self::cname( $to ) . ' raised to ' . ( self::taken( $to ) + 1 ) . ' to fit a moved student' );
		}
		$name  = $r ? trim( $r->first_name . ' ' . $r->last_name ) : (string) get_post_meta( $pid, '_sdsc_name', true );
		$email = $r ? (string) $r->email : (string) get_post_meta( $pid, '_sdsc_email', true );
		$phone = $r ? (string) $r->phone : (string) get_post_meta( $pid, '_sdsc_phone', true );
		if ( $to ) {
			$paid = $r && ( ( class_exists( 'SDSC_Pay' ) && SDSC_Pay::paid_row( (int) $r->id ) ) || self::offline_for( (int) $r->id, 0 ) );
			$pid  = self::place( $pid, $to, $name, $email, $phone, $paid );
			if ( $r ) { $wpdb->update( self::rt(), array( 'class_id' => $to, 'registrant_id' => $pid ), array( 'id' => (int) $r->id ) ); }
		} else {
			if ( $pid ) { wp_trash_post( $pid ); }
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::rt() . ' SET class_id = NULL WHERE id = %d', (int) $r->id ) ); // phpcs:ignore
		}
		if ( $pid ) {
			$h   = (array) get_post_meta( $pid, '_sdsc_moves', true );
			$h[] = array( 'from' => $from, 'to' => $to, 'by' => get_current_user_id(), 'at' => current_time( 'mysql' ) );
			update_post_meta( $pid, '_sdsc_moves', $h );
		}
		self::log( $name . ': ' . self::cname( $from ) . ' → ' . self::cname( $to ) );
		if ( $r ) { $r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::rt() . ' WHERE id = %d', (int) $r->id ) ); } // phpcs:ignore
		self::reply( $r, $to ? $pid : 0, array( $from, $to ) );
	}

	public static function ajax_seats() {
		self::guard();
		$cid = absint( $_POST['cid'] ?? 0 );
		if ( ! isset( self::classes()[ $cid ] ) ) { wp_send_json_error( array( 'msg' => 'Class not found.' ) ); }
		$n = max( 1, min( 200, absint( $_POST['cap'] ?? 0 ) ) );
		$t = self::taken( $cid );
		if ( $n < $t ) { wp_send_json_error( array( 'msg' => $t . ' students are already in this class. Move some out first, or keep at least ' . $t . ' seats.' ) ); }
		$old = self::cap( $cid );
		update_post_meta( $cid, '_sdsc_capacity', $n );
		if ( $old !== $n ) { self::log( 'Seats of ' . self::cname( $cid ) . ': ' . $old . ' → ' . $n ); }
		wp_send_json_success( array( 'seats' => self::seats_of( array( $cid ) ) ) );
	}

	public static function ajax_pay() {
		self::guard();
		global $wpdb;
		list( $r, $pid ) = self::find( $_POST['key'] ?? '' ); // phpcs:ignore
		if ( ! $r && ! $pid ) { wp_send_json_error( array( 'msg' => 'Student not found. Reload the page.' ) ); }
		$method = sanitize_key( $_POST['method'] ?? '' );
		if ( ! in_array( $method, array( 'cash', 'check', 'zelle', 'other' ), true ) ) { wp_send_json_error( array( 'msg' => 'Choose how they paid.' ) ); }
		$amount = (int) round( 100 * (float) str_replace( array( '$', ',' ), '', (string) wp_unslash( $_POST['amount'] ?? '' ) ) ); // phpcs:ignore
		if ( $amount < 100 || $amount > 500000 ) { wp_send_json_error( array( 'msg' => 'Type the amount in dollars (for example 378).' ) ); }
		$ref  = mb_substr( sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) ), 0, 60 );
		if ( 'check' === $method && '' === $ref ) { wp_send_json_error( array( 'msg' => 'Type the check number.' ) ); }
		$on   = sanitize_text_field( wp_unslash( $_POST['paid_on'] ?? '' ) );
		$on   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $on ) ? $on : current_time( 'Y-m-d' );
		$note = mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, 255 );
		$cid  = $r ? (int) $r->class_id : (int) get_post_meta( $pid, '_sdsc_class_id', true );
		$name = $r ? trim( $r->first_name . ' ' . $r->last_name ) : (string) get_post_meta( $pid, '_sdsc_name', true );
		$email = strtolower( $r ? (string) $r->email : (string) get_post_meta( $pid, '_sdsc_email', true ) );
		$wpdb->insert( self::table(), array(
			'reg_id' => $r ? (int) $r->id : 0, 'registrant_id' => (int) $pid, 'class_id' => $cid, 'email' => $email,
			'method' => $method, 'amount' => $amount, 'ref' => $ref, 'paid_on' => $on, 'note' => $note,
			'status' => 'paid', 'by_user' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
		) );
		if ( $pid && ! get_post_meta( $pid, '_sdsc_paid', true ) ) { update_post_meta( $pid, '_sdsc_paid', 'offline' ); }
		if ( class_exists( 'SDSC_Gate' ) ) { // the online course opens for the student (and the parent's login)
			$o = (array) get_option( SDSC_Gate::OPT_OPEN, array() );
			foreach ( array_filter( array( $email, $r ? strtolower( (string) $r->parent_email ) : '' ), 'is_email' ) as $e ) {
				if ( ! isset( $o[ $e ] ) ) { $o[ $e ] = array( 'by' => 'Offline payment — ' . wp_get_current_user()->display_name, 'at' => current_time( 'mysql' ) ); }
			}
			update_option( SDSC_Gate::OPT_OPEN, $o, false );
		}
		$txt = self::method_label( $method ) . ( $ref ? ' #' . $ref : '' ) . ' $' . number_format( $amount / 100, 2 );
		self::log( $name . ': payment recorded — ' . $txt );
		self::mail_office( 'recorded', $name, $email, $cid, $txt, $on, $note );
		self::reply( $r, $pid, array( $cid ) );
	}

	public static function ajax_void() {
		self::guard();
		global $wpdb;
		$id  = absint( $_POST['id'] ?? 0 );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE id = %d AND status = 'paid'", $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) { wp_send_json_error( array( 'msg' => 'Payment not found.' ) ); }
		$wpdb->update( self::table(), array( 'status' => 'void', 'voided_at' => current_time( 'mysql' ), 'voided_by' => get_current_user_id() ), array( 'id' => $id ) );
		$pid = (int) $row['registrant_id'];
		$r   = $row['reg_id'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::rt() . ' WHERE id = %d', (int) $row['reg_id'] ) ) : null; // phpcs:ignore
		if ( $r ) { $pid = (int) $r->registrant_id; }
		if ( ! self::offline_for( $r ? (int) $r->id : 0, $pid ) ) {
			if ( $pid && 'offline' === get_post_meta( $pid, '_sdsc_paid', true ) ) { update_post_meta( $pid, '_sdsc_paid', '' ); }
			if ( class_exists( 'SDSC_Gate' ) ) {
				$o = (array) get_option( SDSC_Gate::OPT_OPEN, array() );
				foreach ( array( strtolower( $row['email'] ), $r ? strtolower( (string) $r->parent_email ) : '' ) as $e ) {
					if ( $e && isset( $o[ $e ] ) && 0 === strpos( (string) ( $o[ $e ]['by'] ?? '' ), 'Offline payment' ) ) { unset( $o[ $e ] ); }
				}
				update_option( SDSC_Gate::OPT_OPEN, $o, false );
			}
		}
		$name = $r ? trim( $r->first_name . ' ' . $r->last_name ) : (string) get_post_meta( $pid, '_sdsc_name', true );
		$txt  = self::method_label( $row['method'] ) . ( $row['ref'] ? ' #' . $row['ref'] : '' ) . ' $' . number_format( $row['amount'] / 100, 2 );
		self::log( $name . ': payment cancelled — ' . $txt );
		self::mail_office( 'voided', $name, $row['email'], (int) $row['class_id'], $txt, (string) $row['paid_on'], '' );
		self::reply( $r, $pid, array( (int) $row['class_id'] ) );
	}

	public static function ajax_add() {
		self::guard();
		global $wpdb;
		$cid = absint( $_POST['cid'] ?? 0 );
		$classes = self::classes();
		if ( $cid && ! isset( $classes[ $cid ] ) ) { wp_send_json_error( array( 'msg' => 'Class not found.' ) ); }
		$f = function ( $k, $max = 100 ) { return mb_substr( sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ), 0, $max ); }; // phpcs:ignore
		$first = $f( 'first_name' ); $last = $f( 'last_name' );
		$email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ); // phpcs:ignore
		$phone = $f( 'phone', 40 );
		$pmail = strtolower( sanitize_email( wp_unslash( $_POST['parent_email'] ?? '' ) ) ); // phpcs:ignore
		if ( '' === $first || '' === $last ) { wp_send_json_error( array( 'msg' => 'Type the first and last name.' ) ); }
		if ( '' === $email && '' === $phone ) { wp_send_json_error( array( 'msg' => 'Type an e-mail or a phone number.' ) ); }
		if ( $email && $cid && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::rt() . ' WHERE LOWER(email) = %s AND class_id = %d', $email, $cid ) ) ) { // phpcs:ignore
			wp_send_json_error( array( 'msg' => 'This e-mail is already in this class.' ) );
		}
		if ( $cid && self::is_full( $cid ) ) {
			if ( empty( $_POST['grow'] ) ) { wp_send_json_error( array( 'full' => 1, 'msg' => self::cname( $cid ) . ' is full (' . self::taken( $cid ) . ' of ' . self::cap( $cid ) . ' seats).' ) ); }
			update_post_meta( $cid, '_sdsc_capacity', self::taken( $cid ) + 1 );
		}
		$wpdb->insert( self::rt(), array(
			'created_at' => current_time( 'mysql' ), 'source' => 'office', 'class_id' => $cid ? $cid : null,
			'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
			'parent_name' => $f( 'parent_name', 200 ), 'parent_email' => $pmail, 'parent_phone' => $f( 'parent_phone', 40 ),
			'notes' => 'Added on the class board by ' . wp_get_current_user()->display_name . '. The registration form and signatures are still needed.',
		) );
		$rid = (int) $wpdb->insert_id;
		if ( ! $rid ) { wp_send_json_error( array( 'msg' => 'Could not save. Please try again.' ) ); }
		if ( $cid ) {
			$pid = self::place( 0, $cid, $first . ' ' . $last, $email, $phone, false );
			$wpdb->update( self::rt(), array( 'registrant_id' => $pid ), array( 'id' => $rid ) );
		}
		self::log( $first . ' ' . $last . ' added to ' . self::cname( $cid ) );
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::rt() . ' WHERE id = %d', $rid ) ); // phpcs:ignore
		self::reply( $r, 0, array( $cid ), array( 'cid' => $cid ) );
	}

	protected static function mail_office( $what, $name, $email, $cid, $txt, $on, $note ) {
		if ( ! class_exists( 'SDSC_Mailer' ) ) { return; }
		$by  = wp_get_current_user();
		$to  = class_exists( 'SDSC_Refunds' ) && method_exists( 'SDSC_Refunds', 'approvers' ) ? SDSC_Refunds::approvers() : array( get_option( 'admin_email' ) );
		$rec = 'recorded' === $what;
		SDSC_Mailer::admin_report( array(
			'to'         => implode( ',', $to ),
			'subject_en' => ( $rec ? 'Payment recorded: ' : 'Payment cancelled: ' ) . $txt . ' — ' . $name,
			'subject_fa' => $rec ? 'ثبت پرداخت حضوری' : 'لغو پرداخت حضوری',
			'title_en'   => $rec ? 'Cash / check payment recorded' : 'Recorded payment cancelled',
			'title_fa'   => $rec ? 'پرداخت نقدی / چک ثبت شد' : 'پرداخت ثبت‌شده لغو شد',
			'lead_en'    => $rec ? $by->display_name . ' recorded a payment on the class board. The student\'s online course is now open.' : $by->display_name . ' cancelled a payment that was recorded by hand.',
			'lead_fa'    => $rec ? $by->display_name . ' یک پرداخت را در تابلوی کلاس‌ها ثبت کرد. دوره آنلاین شاگرد باز شد.' : $by->display_name . ' یک پرداخت دستی را لغو کرد.',
			'rows'       => array(
				array( 'en' => 'Student', 'fa' => 'شاگرد', 'v' => $name . ( $email ? ' · ' . $email : '' ), 'ltr' => true ),
				array( 'en' => 'Class', 'fa' => 'کلاس', 'v' => self::cname( $cid ), 'ltr' => true ),
				array( 'en' => 'Payment', 'fa' => 'پرداخت', 'v' => $txt, 'ltr' => true ),
				array( 'en' => 'Paid on', 'fa' => 'تاریخ پرداخت', 'v' => $on, 'ltr' => true ),
				array( 'en' => 'Note', 'fa' => 'توضیح', 'v' => $note, 'ltr' => true ),
				array( 'en' => 'By', 'fa' => 'توسط', 'v' => $by->display_name . ' · ' . $by->user_email, 'ltr' => true ),
			),
			'buttons'    => array( array( 'en' => 'Open the class board', 'fa' => 'باز کردن تابلوی کلاس‌ها', 'url' => admin_url( 'admin.php?page=' . self::PAGE ) ) ),
			'note_en'    => 'If you did not expect this, check it with the office today.',
			'note_fa'    => 'اگر انتظارش را نداشتید، همین امروز با دفتر بررسی کنید.',
		) );
	}

	/* ------------------------------------------------------------ screen */

	protected static function card_html( $d, $classes ) {
		$paid = (bool) $d['pay'];
		$h  = '<article class="sdb-card' . ( $paid ? ' is-paid' : '' ) . '" draggable="true" data-key="' . esc_attr( $d['key'] ) . '" data-cid="' . (int) $d['cid'] . '" data-name="' . esc_attr( strtolower( $d['name'] . ' ' . $d['email'] . ' ' . $d['phone'] ) ) . '">';
		$h .= '<header><span class="sdb-grip" aria-hidden="true">⋮⋮</span><b>' . esc_html( $d['name'] ) . '</b>' . ( $d['no'] && $d['cid'] ? '<span class="sdb-no">#' . (int) $d['no'] . '</span>' : '' ) . '</header>';
		$h .= '<p class="sdb-contact">' . ( $d['email'] ? esc_html( $d['email'] ) : '' ) . ( $d['phone'] ? '<br>' . esc_html( $d['phone'] ) : '' ) . '</p>';
		$h .= '<p class="sdb-tags">';
		foreach ( $d['pay'] as $p ) {
			$h .= '<span class="sdb-tag sdb-tag--' . esc_attr( $p['k'] ) . '">' . esc_html( $p['t'] );
			if ( 'offline' === $p['k'] ) { $h .= ' <button type="button" class="sdb-void" data-id="' . (int) $p['id'] . '" title="Cancel this recorded payment">×</button>'; }
			$h .= '</span>';
		}
		if ( ! $paid ) { $h .= '<span class="sdb-tag sdb-tag--due">Not paid</span>'; }
		$src = array( 'native' => 'Website form', 'forminator' => 'Old form', 'office' => 'Added by office' );
		$h .= '<span class="sdb-tag sdb-tag--src">' . esc_html( $src[ $d['src'] ] ?? ucfirst( $d['src'] ) ) . '</span>';
		if ( ! empty( $d['dup'] ) ) { $h .= '<span class="sdb-tag sdb-tag--dup" title="The same e-mail is on another card">Twice</span>'; }
		$h .= '</p><footer>';
		$h .= '<button type="button" class="button button-small sdb-pay">+ Cash / check</button>';
		if ( $d['reg'] ) { $h .= ' <a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=sdsc-registrations&view=' . $d['reg'] ) ) . '">Open</a>'; }
		$h .= ' <select class="sdb-moveto" aria-label="Move to"><option value="">Move to…</option>';
		if ( $d['reg'] ) { $h .= '<option value="0"' . disabled( 0, (int) $d['cid'], false ) . '>No class yet</option>'; }
		foreach ( $classes as $id => $c ) { $h .= '<option value="' . (int) $id . '"' . disabled( $id, (int) $d['cid'], false ) . '>' . esc_html( self::cname( $id ) ) . '</option>'; }
		$h .= '</select></footer></article>';
		return $h;
	}

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Only the administrator and the school manager can open the class board.', '', array( 'response' => 403 ) ); }
		$classes = self::classes();
		$cols    = self::cards( $classes );
		$seats   = self::seats_of( array_keys( $classes ) );
		?>
		<div class="wrap sdb">
			<h1>Class board</h1>
			<p class="sdb-intro">Drag a student to another class, or use <b>Move to</b>. Record cash, check or Zelle payments with <b>+ Cash / check</b> &mdash; the online course opens for that student and the administrator gets an e-mail. Change the seats of a class with <b>−</b> and <b>+</b>; when a class is full the website stops taking registrations for it.</p>
			<p><input type="search" id="sdb-q" class="regular-text" placeholder="Find a student (name, e-mail, phone)" /> <span id="sdb-msg" role="status" aria-live="polite"></span></p>
			<div class="sdb-cols">
				<?php foreach ( $cols as $cid => $list ) :
					$c = $cid ? $classes[ $cid ] : null;
					$st = $c ? get_post_meta( $cid, '_sdsc_start', true ) : '';
					?>
					<section class="sdb-col<?php echo $cid ? '' : ' sdb-col--none'; ?>" data-cid="<?php echo (int) $cid; ?>">
						<header class="sdb-colhead">
							<h2><?php echo esc_html( self::cname( $cid ) ); ?></h2>
							<?php if ( $c ) : ?>
								<p class="sdb-when"><?php echo esc_html( ( $st ? 'Starts ' . wp_date( 'D, M j', strtotime( substr( $st, 0, 10 ) . ' 12:00' ) ) : 'No start date' ) . ' · ' . ucfirst( (string) ( get_post_meta( $cid, '_sdsc_status', true ) ?: 'upcoming' ) ) ); ?></p>
								<div class="sdb-seats" data-cid="<?php echo (int) $cid; ?>">
									<span class="sdb-count"><b><?php echo (int) $seats[ $cid ]['taken']; ?></b> of <b><?php echo (int) $seats[ $cid ]['cap']; ?></b> seats</span>
									<button type="button" class="button button-small sdb-cap" data-d="-1" aria-label="One seat less">−</button>
									<button type="button" class="button button-small sdb-cap" data-d="1" aria-label="One seat more">+</button>
									<i class="sdb-bar"><i></i></i>
								</div>
							<?php else : ?>
								<p class="sdb-when">Registrations of the last <?php echo (int) self::days(); ?> days with no class (the old form did not ask). Drag each one into its class. <a href="<?php echo esc_url( self::days() > 30 ? admin_url( 'admin.php?page=' . self::PAGE ) : admin_url( 'admin.php?page=' . self::PAGE . '&older=1' ) ); ?>"><?php echo self::days() > 30 ? 'Last 30 days only' : 'Show 120 days'; ?></a></p>
							<?php endif; ?>
							<button type="button" class="button sdb-add">+ Add student</button>
						</header>
						<div class="sdb-list">
							<?php foreach ( $list as $d ) { echo self::card_html( $d, $classes ); } // phpcs:ignore ?>
						</div>
					</section>
				<?php endforeach; ?>
			</div>

			<h2>Recent changes</h2>
			<table class="widefat striped sdb-log"><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>
				<?php $log = array_slice( (array) get_option( self::LOG, array() ), 0, 25 );
				if ( ! $log ) { echo '<tr><td colspan="3">Nothing yet.</td></tr>'; }
				foreach ( $log as $l ) { echo '<tr><td>' . esc_html( wp_date( 'M j, g:i a', strtotime( $l['at'] ) ) ) . '</td><td>' . esc_html( $l['by'] ) . '</td><td>' . esc_html( $l['t'] ) . '</td></tr>'; } ?>
			</tbody></table>

			<dialog id="sdb-paydlg" class="sdb-dlg"><form method="dialog">
				<h2>Record a payment</h2><p class="sdb-who"></p>
				<label>How they paid <select name="method"><option value="cash">Cash</option><option value="check">Check</option><option value="zelle">Zelle</option><option value="other">Other</option></select></label>
				<label>Amount ($) <input name="amount" inputmode="decimal" required placeholder="378" /></label>
				<label class="sdb-ref">Check number <input name="ref" maxlength="60" /></label>
				<label>Date paid <input type="date" name="paid_on" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" /></label>
				<label>Note <input name="note" maxlength="255" placeholder="Optional" /></label>
				<p class="sdb-err" role="alert"></p>
				<p><button class="button button-primary" value="ok">Save payment</button> <button class="button" value="cancel" formnovalidate>Cancel</button></p>
			</form></dialog>

			<dialog id="sdb-adddlg" class="sdb-dlg"><form method="dialog">
				<h2>Add a student</h2><p class="sdb-who"></p>
				<label>First name <input name="first_name" required maxlength="100" /></label>
				<label>Last name <input name="last_name" required maxlength="100" /></label>
				<label>E-mail <input type="email" name="email" maxlength="190" /></label>
				<label>Phone <input name="phone" maxlength="40" /></label>
				<label>Parent name <input name="parent_name" maxlength="200" /></label>
				<label>Parent e-mail <input type="email" name="parent_email" maxlength="190" /></label>
				<label>Parent phone <input name="parent_phone" maxlength="40" /></label>
				<p class="description">The student still has to fill in and sign the registration form.</p>
				<p class="sdb-err" role="alert"></p>
				<p><button class="button button-primary" value="ok">Add</button> <button class="button" value="cancel" formnovalidate>Cancel</button></p>
			</form></dialog>
		</div>
		<style>
			.sdb-intro{max-width:900px}
			.sdb-cols{display:flex;gap:14px;overflow-x:auto;align-items:flex-start;padding:4px 2px 16px}
			.sdb-col{flex:0 0 300px;background:#f3f5f4;border:1px solid #dcdcde;border-radius:10px;display:flex;flex-direction:column;max-height:78vh}
			.sdb-col--none{background:#fff8e5;border-color:#f0c33c}
			.sdb-col.is-over{outline:3px dashed #00703c;outline-offset:-3px}
			.sdb-colhead{padding:10px 12px;border-bottom:1px solid #dcdcde}
			.sdb-colhead h2{margin:0 0 2px;font-size:15px}
			.sdb-when{margin:0 0 6px;color:#50575e;font-size:12px}
			.sdb-seats{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:8px}
			.sdb-count{margin-right:auto}
			.sdb-bar{display:block;width:100%;height:6px;background:#dcdcde;border-radius:6px;overflow:hidden}
			.sdb-bar>i{display:block;height:100%;background:#00703c}
			.sdb-seats.is-full .sdb-bar>i{background:#d63638}.sdb-seats.is-low .sdb-bar>i{background:#dba617}
			.sdb-seats.is-full .sdb-count{color:#d63638;font-weight:700}
			.sdb-list{overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px;min-height:60px}
			.sdb-card{background:#fff;border:1px solid #dcdcde;border-left:4px solid #d63638;border-radius:8px;padding:8px 10px;cursor:grab}
			.sdb-card.is-paid{border-left-color:#00703c}
			.sdb-card.is-dragging{opacity:.45}
			.sdb-card header{display:flex;gap:6px;align-items:center}
			.sdb-grip{color:#8c8f94;letter-spacing:-2px}
			.sdb-no{margin-left:auto;color:#50575e;font-size:12px}
			.sdb-contact{margin:4px 0;font-size:12px;color:#50575e;word-break:break-all}
			.sdb-tags{margin:4px 0;display:flex;flex-wrap:wrap;gap:4px}
			.sdb-tag{font-size:11px;border-radius:999px;padding:1px 8px;background:#f0f0f1}
			.sdb-tag--online,.sdb-tag--offline,.sdb-tag--marked,.sdb-tag--opened{background:#e6f4ea;color:#00561f}
			.sdb-tag--due{background:#fcf0f1;color:#8a2424}.sdb-tag--dup{background:#fff8e5;color:#6b4e00}
			.sdb-void{border:0;background:none;color:#8a2424;cursor:pointer;padding:0 2px;font-weight:700}
			.sdb-card footer{display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin-top:6px}
			.sdb-moveto{max-width:140px;font-size:12px}
			.sdb-dlg{border:0;border-radius:12px;padding:18px 22px;max-width:420px;width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.25)}
			.sdb-dlg label{display:block;margin:8px 0}.sdb-dlg input,.sdb-dlg select{display:block;width:100%;margin-top:2px}
			.sdb-err{color:#d63638;font-weight:600}
			#sdb-msg{margin-left:8px;font-weight:600}
			.sdb-card[hidden]{display:none}
		</style>
		<script>
		(function(){
			var N=<?php echo wp_json_encode( wp_create_nonce( 'sdsc_board' ) ); ?>,A=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,msg=document.getElementById('sdb-msg'),drag=null;
			function say(t,bad){msg.textContent=t;msg.style.color=bad?'#d63638':'#00703c';clearTimeout(say.t);say.t=setTimeout(function(){msg.textContent='';},6000);}
			function post(action,data){var f=new FormData();f.append('action','sdsc_board_'+action);f.append('nonce',N);for(var k in data){f.append(k,data[k]);}return fetch(A,{method:'POST',credentials:'same-origin',body:f}).then(function(r){return r.json();});}
			function seats(s){for(var id in s){var box=document.querySelector('.sdb-seats[data-cid="'+id+'"]');if(!box)continue;var t=s[id].taken,c=s[id].cap;box.querySelector('.sdb-count').innerHTML='<b>'+t+'</b> of <b>'+c+'</b> seats';box.querySelector('.sdb-bar>i').style.width=Math.min(100,Math.round(100*t/c))+'%';box.classList.toggle('is-full',t>=c);box.classList.toggle('is-low',t<c&&c-t<=3);}}
			[].forEach.call(document.querySelectorAll('.sdb-seats'),function(b){var m=b.querySelector('.sdb-count').textContent.match(/(\d+)\D+(\d+)/);if(m){var o={};o[b.dataset.cid]={taken:+m[1],cap:+m[2]};seats(o);}});
			function swap(old,html){var d=document.createElement('div');d.innerHTML=html;var n=d.firstElementChild;old.replaceWith(n);return n;}
			function move(card,to,grow){
				var from=card.closest('.sdb-col'),dest=document.querySelector('.sdb-col[data-cid="'+to+'"] .sdb-list');if(!dest)return;
				dest.prepend(card);
				post('move',{key:card.dataset.key,to:to,grow:grow?1:0}).then(function(r){
					if(r.success){var n=swap(card,r.data.card);seats(r.data.seats);n.scrollIntoView({block:'nearest'});say('Moved: '+n.querySelector('b').textContent);return;}
					from.querySelector('.sdb-list').prepend(card);
					if(r.data&&r.data.full&&confirm(r.data.msg+'\n\nAdd one more seat and move the student?')){move(card,to,true);return;}
					say((r.data&&r.data.msg)||'Could not move. Reload the page.',true);
				}).catch(function(){from.querySelector('.sdb-list').prepend(card);say('No connection. Try again.',true);});
			}
			document.addEventListener('dragstart',function(e){var c=e.target.closest&&e.target.closest('.sdb-card');if(!c)return;drag=c;c.classList.add('is-dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',c.dataset.key);});
			document.addEventListener('dragend',function(){if(drag)drag.classList.remove('is-dragging');drag=null;[].forEach.call(document.querySelectorAll('.sdb-col.is-over'),function(c){c.classList.remove('is-over');});});
			[].forEach.call(document.querySelectorAll('.sdb-col'),function(col){
				col.addEventListener('dragover',function(e){if(!drag)return;e.preventDefault();col.classList.add('is-over');});
				col.addEventListener('dragleave',function(e){if(!col.contains(e.relatedTarget))col.classList.remove('is-over');});
				col.addEventListener('drop',function(e){e.preventDefault();col.classList.remove('is-over');if(!drag)return;var c=drag;if(drag.closest('.sdb-col')===col)return;move(c,col.dataset.cid,false);});
			});
			document.addEventListener('change',function(e){if(e.target.classList.contains('sdb-moveto')&&e.target.value!==''){var c=e.target.closest('.sdb-card'),v=e.target.value;e.target.value='';move(c,v,false);}});
			document.addEventListener('click',function(e){
				var b=e.target.closest('.sdb-cap');
				if(b){var box=b.closest('.sdb-seats'),m=box.querySelector('.sdb-count').textContent.match(/(\d+)\D+(\d+)/),cap=+m[2]+(+b.dataset.d);if(cap<1)return;post('seats',{cid:box.dataset.cid,cap:cap}).then(function(r){if(r.success){seats(r.data.seats);say('Seats saved.');}else say(r.data.msg,true);});return;}
				var v=e.target.closest('.sdb-void');
				if(v){if(!confirm('Cancel this recorded payment? The administrator gets an e-mail.'))return;var c=v.closest('.sdb-card');post('void',{id:v.dataset.id}).then(function(r){if(r.success){swap(c,r.data.card);seats(r.data.seats);say('Payment cancelled.');}else say(r.data.msg,true);});return;}
				var p=e.target.closest('.sdb-pay');
				if(p){openPay(p.closest('.sdb-card'));return;}
				var a=e.target.closest('.sdb-add');
				if(a){openAdd(a.closest('.sdb-col'));return;}
			});
			var pd=document.getElementById('sdb-paydlg'),pf=pd.querySelector('form'),pcard=null;
			function refShow(){pf.querySelector('.sdb-ref').hidden=pf.method.value!=='check';}
			pf.method.addEventListener('change',refShow);
			function openPay(card){pcard=card;pf.reset();refShow();pf.querySelector('.sdb-err').textContent='';pd.querySelector('.sdb-who').textContent=card.querySelector('b').textContent;pd.showModal();pf.amount.focus();}
			pf.addEventListener('submit',function(e){if(e.submitter&&e.submitter.value==='cancel')return;e.preventDefault();var d={key:pcard.dataset.key};['method','amount','ref','paid_on','note'].forEach(function(k){d[k]=pf[k].value;});
				post('pay',d).then(function(r){if(r.success){pd.close();swap(pcard,r.data.card);seats(r.data.seats);say('Payment saved. The course is open for this student.');}else pf.querySelector('.sdb-err').textContent=r.data.msg;});});
			var ad=document.getElementById('sdb-adddlg'),af=ad.querySelector('form'),acol=null;
			function openAdd(col){acol=col;af.reset();af.querySelector('.sdb-err').textContent='';ad.querySelector('.sdb-who').textContent=col.querySelector('h2').textContent;ad.showModal();af.first_name.focus();}
			function add(grow){var d={cid:acol.dataset.cid,grow:grow?1:0};['first_name','last_name','email','phone','parent_name','parent_email','parent_phone'].forEach(function(k){d[k]=af[k].value;});
				post('add',d).then(function(r){if(r.success){ad.close();var t=document.createElement('div');t.innerHTML=r.data.card;acol.querySelector('.sdb-list').prepend(t.firstElementChild);seats(r.data.seats);say('Student added.');return;}
					if(r.data.full&&confirm(r.data.msg+'\n\nAdd one more seat and add the student?')){add(true);return;}af.querySelector('.sdb-err').textContent=r.data.msg;});}
			af.addEventListener('submit',function(e){if(e.submitter&&e.submitter.value==='cancel')return;e.preventDefault();add(false);});
			document.getElementById('sdb-q').addEventListener('input',function(){var q=this.value.trim().toLowerCase();[].forEach.call(document.querySelectorAll('.sdb-card'),function(c){c.hidden=q&&c.dataset.name.indexOf(q)<0;});});
		})();
		</script>
		<?php
	}
}
