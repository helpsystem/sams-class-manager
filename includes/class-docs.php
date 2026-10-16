<?php
/**
 * Student documents — one file per student (keyed by e-mail): ID card front and back, learner's permit,
 * licence, practice log, certificates and anything else the school needs on file.
 *
 *  - Staff (administrator, school manager, front desk) add documents from the dashboard: upload a scanned
 *    PDF or image (drag and drop from the office scanner) or take a photo with the phone camera.
 *  - Students add their own from "My documents" on their dashboard; the office then marks them checked.
 *  - Files are stored outside the public website folder when possible, always encrypted (libsodium),
 *    under random names. They are only ever sent through a permission-checked link, never cached.
 *  - Every upload, view, check and removal is written to a log (who, when, from where).
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Docs {

	const TAB   = 'sdsc-documents';
	const DBV   = '1';
	const MAX   = 20971520; // 20 MB
	const MIMES = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif', 'application/pdf' => 'pdf' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'install' ) );
		add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'tutor_nav' ) );
		add_filter( 'tutor_instructor_dashboard_nav', array( __CLASS__, 'tutor_nav' ) );
		add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'tutor_template' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'upload', 'list', 'find', 'file', 'check', 'remove', 'staff' ) as $a ) {
			add_action( 'wp_ajax_sdsc_doc_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* ------------------------------------------------------------ setup */

	public static function t_docs() { global $wpdb; return $wpdb->prefix . 'sdsc_docs'; }
	public static function t_log() { global $wpdb; return $wpdb->prefix . 'sdsc_docs_log'; }

	public static function install() {
		if ( get_option( 'sdsc_docs_dbv' ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t_docs() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			type varchar(30) NOT NULL DEFAULT 'other',
			label varchar(190) NOT NULL DEFAULT '',
			file varchar(80) NOT NULL,
			thumb varchar(80) NOT NULL DEFAULT '',
			mime varchar(60) NOT NULL,
			size int(10) unsigned NOT NULL DEFAULT 0,
			source varchar(10) NOT NULL DEFAULT 'staff',
			status varchar(10) NOT NULL DEFAULT 'ok',
			by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			checked_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY status (status)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t_log() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			doc_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(12) NOT NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY doc_id (doc_id)
		) $c;" );
		update_option( 'sdsc_docs_dbv', self::DBV );
	}

	/** Private folder: outside the website folder when the server allows, else a random folder with deny rules. */
	public static function dir() {
		$d = (string) get_option( 'sdsc_docs_dir', '' );
		if ( $d && is_dir( $d ) && wp_is_writable( $d ) ) { return trailingslashit( $d ); }
		$cands = array( dirname( untrailingslashit( ABSPATH ) ) . '/sdsc-private-docs' );
		$up    = wp_upload_dir( null, false );
		$cands[] = trailingslashit( $up['basedir'] ) . 'sdsc-private-' . wp_generate_password( 20, false, false );
		foreach ( $cands as $c ) {
			if ( ( is_dir( $c ) || wp_mkdir_p( $c ) ) && wp_is_writable( $c ) ) {
				@file_put_contents( $c . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore
				@file_put_contents( $c . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
				update_option( 'sdsc_docs_dir', $c, false );
				return trailingslashit( $c );
			}
		}
		return '';
	}

	/* ------------------------------------------------------------ types & people */

	public static function types( $student = false ) {
		$t = array(
			'id_front'     => 'ID card — front',
			'id_back'      => 'ID card — back',
			'permit'       => 'Learner’s permit',
			'license'      => 'Driver’s license',
			'practice_log' => 'Practice log',
			'certificate'  => 'Certificate',
			'parent_id'    => 'Parent / guardian ID',
			'other'        => 'Other document',
		) + self::ins_types();
		return $student ? array_intersect_key( $t, array_flip( array( 'id_front', 'id_back', 'permit', 'license', 'practice_log', 'other' ) ) ) : $t;
	}

	/** Instructor file: what the school keeps for each instructor. The first three are required. */
	public static function ins_types() {
		return array(
			'ins_dl_front' => 'Driver’s license — front',
			'ins_dl_back'  => 'Driver’s license — back',
			'ins_cert'     => 'MVA instructor license / certificate',
			'ins_agree'    => 'Signed instructor agreement',
			'ins_w9'       => 'W-9 / tax form',
			'ins_bg'       => 'Background check',
			'ins_insur'    => 'Car insurance',
			'ins_other'    => 'Other instructor document',
		);
	}
	const INS_NEED = array( 'ins_dl_front', 'ins_dl_back', 'ins_cert' );

	/** Instructors (not the sample ones), each with the e-mail their file is kept under. */
	public static function instructors() {
		global $wpdb;
		$t = $wpdb->prefix . 'driveflow_instructors';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return array(); }
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, user_id, name, phone, email, status FROM $t WHERE name NOT LIKE 'Demo %' AND name NOT LIKE 'Preview %' AND email NOT LIKE '%@preview.samsdrivingschool.invalid' ORDER BY status = 'active' DESC, name", ARRAY_A ) as $r ) { // phpcs:ignore
			$e = strtolower( trim( (string) $r['email'] ) );
			if ( ! is_email( $e ) && $r['user_id'] && ( $u = get_userdata( (int) $r['user_id'] ) ) ) { $e = strtolower( $u->user_email ); }
			if ( ! is_email( $e ) ) { $e = 'instructor-' . (int) $r['id'] . '@instructors.samsdrivingschool.invalid'; } // typed in by the office, no e-mail yet
			$out[ $e ] = array( 'id' => (int) $r['id'], 'name' => $r['name'], 'phone' => (string) $r['phone'], 'email' => $e, 'active' => 'active' === $r['status'] );
		}
		return $out;
	}
	/** Is this e-mail an instructor's file? */
	public static function instructor( $email ) {
		static $all = null;
		if ( null === $all ) { $all = self::instructors(); }
		$email = strtolower( trim( (string) $email ) );
		if ( isset( $all[ $email ] ) ) { return $all[ $email ]; }
		$u = is_email( $email ) ? get_user_by( 'email', $email ) : null;
		if ( $u && array_intersect( (array) $u->roles, array( 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' ) ) ) { return array( 'id' => 0, 'name' => $u->display_name, 'phone' => '', 'email' => $email, 'active' => true ); }
		return null;
	}
	/** Instructor files: the front office, and each instructor their own. Student files: the whole school team. */
	public static function may_see( $email ) {
		if ( ! self::is_office() ) { return self::me() === strtolower( (string) $email ); }
		if ( self::instructor( $email ) ) { return self::is_front_office() || self::me() === strtolower( (string) $email ); }
		return true;
	}

	/** The school team: administrator, school manager, front desk and instructors (they check permits in the car). */
	public static function is_office() {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		if ( ! class_exists( 'SDSC_Access' ) ) { return false; }
		return (bool) array_intersect( (array) wp_get_current_user()->roles, array( SDSC_Access::MANAGER, SDSC_Access::FRONTDESK, 'tutor_instructor', 'sdsc_instructor', 'driveflow_instructor' ) );
	}

	/** Administrator, school manager and front desk (without instructors). */
	public static function is_front_office() {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		return class_exists( 'SDSC_Access' ) && (bool) array_intersect( (array) wp_get_current_user()->roles, array( SDSC_Access::MANAGER, SDSC_Access::FRONTDESK ) );
	}

	/** Instructors see other people's documents only as "on file" with a stamp-size preview. */
	public static function limited( $email ) {
		return ! self::is_front_office() && self::me() !== strtolower( (string) $email );
	}

	/** Remove a document: administrator and school manager only. */
	public static function can_remove() {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		return class_exists( 'SDSC_Access' ) && in_array( SDSC_Access::MANAGER, (array) wp_get_current_user()->roles, true );
	}

	protected static function me() { return strtolower( trim( (string) wp_get_current_user()->user_email ) ); }

	public static function url( $email = '' ) {
		$u = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( self::TAB ) : home_url( '/dashboard/' . self::TAB . '/' );
		return $email ? add_query_arg( 'student', rawurlencode( strtolower( $email ) ), $u ) : $u;
	}

	/* ------------------------------------------------------------ storage */

	protected static function key() {
		$seed = ( defined( 'SDSC_SECRET_KEY' ) && SDSC_SECRET_KEY ) ? SDSC_SECRET_KEY : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( 'sdsc-docs|' . $seed, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	protected static function put( $bytes ) {
		$dir = self::dir();
		if ( ! $dir ) { return ''; }
		$name = bin2hex( random_bytes( 16 ) ) . '.bin';
		$n    = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ok   = file_put_contents( $dir . $name, $n . sodium_crypto_secretbox( $bytes, $n, self::key() ) ); // phpcs:ignore
		return $ok ? $name : '';
	}

	/**
	 * Keep a file in the private, encrypted document store (used by other parts of the site, e.g. the instructor
	 * onboarding link, so ID cards never land in the public uploads folder). Returns the document id or 0.
	 */
	public static function store( $email, $bytes, $type, $label = '', $source = 'staff', $name = '' ) {
		global $wpdb;
		$email = strtolower( sanitize_email( (string) $email ) );
		if ( ! is_email( $email ) || '' === (string) $bytes || strlen( $bytes ) > self::MAX ) { return 0; }
		$mime = function_exists( 'finfo_open' ) ? (string) finfo_buffer( finfo_open( FILEINFO_MIME_TYPE ), $bytes ) : '';
		if ( ! isset( self::MIMES[ $mime ] ) ) { return 0; }
		$file = self::put( $bytes );
		if ( ! $file ) { return 0; }
		if ( ! isset( self::types()[ $type ] ) ) { $type = 'other'; }
		$wpdb->insert( self::t_docs(), array(
			'email' => $email, 'name' => substr( (string) $name, 0, 190 ), 'type' => $type, 'label' => substr( sanitize_text_field( $label ), 0, 190 ),
			'file' => $file, 'thumb' => '', 'mime' => $mime, 'size' => strlen( $bytes ), 'source' => 'student' === $source ? 'student' : 'staff',
			'status' => 'new', 'by_user' => get_current_user_id(), 'created' => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;
		if ( $id ) { self::log( $id, 'upload' ); }
		return $id;
	}

	protected static function get( $name ) {
		if ( ! preg_match( '/^[a-f0-9]{32}\.bin$/', (string) $name ) ) { return ''; }
		$raw = @file_get_contents( self::dir() . $name ); // phpcs:ignore
		$nb  = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( ! $raw || strlen( $raw ) <= $nb ) { return ''; }
		$p = sodium_crypto_secretbox_open( substr( $raw, $nb ), substr( $raw, 0, $nb ), self::key() );
		return false === $p ? '' : $p;
	}

	protected static function log( $doc, $action ) {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore
		$wpdb->insert( self::t_log(), array( 'doc_id' => (int) $doc, 'user_id' => get_current_user_id(), 'action' => $action, 'ip' => substr( $ip, 0, 45 ), 'at' => current_time( 'mysql' ) ) );
	}

	/* ------------------------------------------------------------ queries */

	protected static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_docs() . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	/** May the current user see this document? */
	protected static function may( $r ) {
		if ( ! $r || ! is_user_logged_in() ) { return false; }
		return self::may_see( $r['email'] ) || self::me() === $r['email'];
	}

	public static function shape( $r ) {
		$by = $r['by_user'] ? get_userdata( (int) $r['by_user'] ) : null;
		$ck = $r['checked_by'] ? get_userdata( (int) $r['checked_by'] ) : null;
		$f  = admin_url( 'admin-ajax.php?action=sdsc_doc_file&id=' . (int) $r['id'] . '&_wpnonce=' . wp_create_nonce( 'sdsc_doc_file' ) );
		$out = array(
			'id'      => (int) $r['id'],
			'type'    => $r['type'],
			'typeL'   => self::types()[ $r['type'] ] ?? 'Document',
			'label'   => $r['label'],
			'pdf'     => 'application/pdf' === $r['mime'],
			'size'    => size_format( (int) $r['size'] ),
			'when'    => mysql2date( 'M j, Y g:i A', $r['created'] ), // stored in school time
			'by'      => 'student' === $r['source'] ? 'Student' : ( $by ? $by->display_name : '' ),
			'source'  => $r['source'],
			'status'  => $r['status'],
			'checked' => $ck ? $ck->display_name : '',
			'view'    => $f,
			'thumb'   => $r['thumb'] ? $f . '&thumb=1' : '',
			'dl'      => $f . '&dl=1',
		);
		if ( self::limited( $r['email'] ) ) { $out = array_merge( $out, array( 'view' => '', 'dl' => '', 'tiny' => 1 ) ); }
		return $out;
	}

	/** Search students by name, e-mail or phone across registrations, behind-the-wheel students and accounts. */
	public static function find( $q ) {
		global $wpdb;
		$q = trim( (string) $q );
		if ( strlen( $q ) < 2 ) { return array(); }
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$out  = array();
		$add  = function ( $email, $name, $phone, $from ) use ( &$out ) {
			$e = strtolower( trim( (string) $email ) );
			if ( ! is_email( $e ) ) { return; }
			if ( ! isset( $out[ $e ] ) ) { $out[ $e ] = array( 'email' => $e, 'name' => trim( (string) $name ), 'phone' => (string) $phone, 'from' => array() ); }
			if ( ! $out[ $e ]['name'] ) { $out[ $e ]['name'] = trim( (string) $name ); }
			if ( ! $out[ $e ]['phone'] ) { $out[ $e ]['phone'] = (string) $phone; }
			$out[ $e ]['from'][ $from ] = 1;
		};
		if ( class_exists( 'SDSC_Registration' ) ) {
			$t = SDSC_Registration::table();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT email, first_name, last_name, phone FROM $t WHERE first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s OR email LIKE %s OR phone LIKE %s ORDER BY id DESC LIMIT 30", $like, $like, $like, $like, $like ) ) as $r ) { // phpcs:ignore
				$add( $r->email, $r->first_name . ' ' . $r->last_name, $r->phone, 'Registration' );
			}
		}
		$dt = $wpdb->prefix . 'driveflow_students';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dt ) ) === $dt ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT email, name, phone FROM $dt WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s LIMIT 20", $like, $like, $like ) ) as $r ) { // phpcs:ignore
				$add( $r->email, $r->name, $r->phone, 'Driving lessons' );
			}
		}
		foreach ( get_users( array( 'search' => '*' . $q . '*', 'search_columns' => array( 'user_email', 'display_name', 'user_login' ), 'number' => 10 ) ) as $u ) {
			$add( $u->user_email, $u->display_name, '', 'Account' );
		}
		$counts = array();
		if ( $out ) {
			$in = implode( ',', array_fill( 0, count( $out ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT email, COUNT(*) n, SUM(status = %s) pend FROM ' . self::t_docs() . " WHERE email IN ($in) GROUP BY email", array_merge( array( 'new' ), array_keys( $out ) ) ) ) as $r ) { // phpcs:ignore
				$counts[ $r->email ] = array( (int) $r->n, (int) $r->pend );
			}
		}
		foreach ( $out as $e => &$p ) {
			$p['from'] = array_keys( $p['from'] );
			$p['docs'] = $counts[ $e ][0] ?? 0;
			$p['new']  = $counts[ $e ][1] ?? 0;
		}
		return array_slice( array_values( $out ), 0, 20 );
	}

	/** Who is this e-mail (for the student panel)? */
	protected static function person( $email ) {
		$f = self::find( $email );
		foreach ( $f as $p ) { if ( $p['email'] === $email ) { return $p; } }
		return array( 'email' => $email, 'name' => '', 'phone' => '', 'from' => array(), 'docs' => 0, 'new' => 0 );
	}

	/* ------------------------------------------------------------ ajax */

	protected static function guard( $nonce = 'sdsc_docs' ) {
		if ( ! is_user_logged_in() || ! check_ajax_referer( $nonce, '_wpnonce', false ) ) { wp_send_json_error( array( 'msg' => 'Please sign in again.' ), 403 ); }
	}

	public static function ajax_find() {
		self::guard();
		if ( ! self::is_office() ) { wp_send_json_error( array( 'msg' => 'Not allowed.' ), 403 ); }
		wp_send_json_success( array( 'people' => self::find( sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) ) ) );
	}

	public static function ajax_list() {
		self::guard();
		global $wpdb;
		$email = self::is_office() ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ) : self::me();
		if ( self::is_office() && ! $email && ! self::is_front_office() ) { wp_send_json_success( array( 'pending' => array(), 'recent' => array(), 'limited' => 1 ) ); }
		if ( self::is_office() && ! $email ) {
			// Office home: student uploads still to check, and the latest additions.
			$pend = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_docs() . ' WHERE status = %s ORDER BY id DESC LIMIT 50', 'new' ), ARRAY_A );
			$rec  = $wpdb->get_results( 'SELECT * FROM ' . self::t_docs() . ' ORDER BY id DESC LIMIT 40', ARRAY_A ); // phpcs:ignore
			$ok   = function ( $r ) { return self::may_see( $r['email'] ); };
			$pend = array_values( array_filter( (array) $pend, $ok ) );
			$rec  = array_slice( array_values( array_filter( (array) $rec, $ok ) ), 0, 12 );
			$m    = function ( $r ) { return self::shape( $r ) + array( 'email' => $r['email'], 'name' => $r['name'] ); };
			wp_send_json_success( array( 'pending' => array_map( $m, (array) $pend ), 'recent' => array_map( $m, (array) $rec ) ) );
		}
		if ( ! $email ) { wp_send_json_error( array( 'msg' => 'No student.' ), 400 ); }
		if ( ! self::may_see( $email ) ) { wp_send_json_error( array( 'msg' => 'Instructor files are open to the office and to the instructor only.' ), 403 ); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_docs() . ' WHERE email = %s ORDER BY FIELD(type,%s,%s) DESC, id DESC', $email, 'id_back', 'id_front' ), ARRAY_A );
		$person = self::is_office() ? self::person( $email ) : null;
		$ins    = self::instructor( $email );
		if ( $person && $ins ) { $person['kind'] = 'instructor'; $person['name'] = $ins['name']; $person['phone'] = $person['phone'] ? $person['phone'] : $ins['phone']; $person['from'] = array( 'Instructor' ); $person['self'] = self::me() === $email; }
		wp_send_json_success( array( 'person' => $person, 'docs' => array_map( array( __CLASS__, 'shape' ), (array) $rows ) ) );
	}

	public static function ajax_upload() {
		self::guard();
		global $wpdb;
		$office = self::is_office();
		$email  = $office ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ) : self::me();
		if ( ! is_email( $email ) ) { wp_send_json_error( array( 'msg' => 'Choose the student first.' ), 400 ); }
		if ( ! self::may_see( $email ) ) { wp_send_json_error( array( 'msg' => 'Not allowed.' ), 403 ); }
		$type = sanitize_key( wp_unslash( $_POST['type'] ?? 'other' ) );
		if ( ! isset( self::types( ! $office )[ $type ] ) ) { $type = 'other'; }
		if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { wp_send_json_error( array( 'msg' => 'The file did not arrive. Please try again.' ), 400 ); } // phpcs:ignore
		$f = $_FILES['file']; // phpcs:ignore
		if ( (int) $f['size'] <= 0 || (int) $f['size'] > self::MAX ) { wp_send_json_error( array( 'msg' => 'Files can be up to 20 MB.' ), 400 ); }
		$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $f['tmp_name'] ) : '';
		if ( ! isset( self::MIMES[ $mime ] ) ) { wp_send_json_error( array( 'msg' => 'Please upload a photo (JPG, PNG, HEIC) or a PDF.' ), 400 ); }
		$name = self::put( (string) file_get_contents( $f['tmp_name'] ) ); // phpcs:ignore
		if ( ! $name ) { wp_send_json_error( array( 'msg' => 'Could not store the file. Please tell the administrator.' ), 500 ); }
		$thumb = '';
		$t     = (string) wp_unslash( $_POST['thumb'] ?? '' ); // phpcs:ignore -- validated below
		if ( preg_match( '#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $t, $m ) && strlen( $m[1] ) < 400000 ) {
			$bin = base64_decode( $m[1], true );
			if ( $bin && 0 === strpos( $bin, "\xFF\xD8" ) ) { $thumb = self::put( $bin ); }
		}
		$who = self::person( $email );
		if ( ! $who['name'] && ( $ins = self::instructor( $email ) ) ) { $who['name'] = $ins['name']; }
		$wpdb->insert( self::t_docs(), array(
			'email'   => $email,
			'name'    => substr( $who['name'] ? $who['name'] : ( $office ? '' : wp_get_current_user()->display_name ), 0, 190 ),
			'type'    => $type,
			'label'   => substr( sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ), 0, 190 ),
			'file'    => $name,
			'thumb'   => $thumb,
			'mime'    => $mime,
			'size'    => (int) $f['size'],
			'source'  => $office ? 'staff' : 'student',
			'status'  => $office ? 'ok' : 'new',
			'by_user' => get_current_user_id(),
			'created' => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;
		self::log( $id, 'upload' );
		if ( ! $office && class_exists( 'SDSC_Notify' ) ) {
			SDSC_Notify::add( SDSC_Notify::office( true ), 'New document from a student', ( $who['name'] ? $who['name'] : $email ) . ' added: ' . self::types()[ $type ], array( 'kind' => 'doc', 'icon' => '📎', 'url' => self::url( $email ), 'ref' => 'doc:' . $id ) );
		}
		wp_send_json_success( array( 'doc' => self::shape( self::row( $id ) ) ) );
	}

	public static function ajax_check() {
		self::guard();
		if ( ! self::is_office() ) { wp_send_json_error( array( 'msg' => 'Not allowed.' ), 403 ); }
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );
		$wpdb->update( self::t_docs(), array( 'status' => 'ok', 'checked_by' => get_current_user_id() ), array( 'id' => $id ) );
		self::log( $id, 'check' );
		wp_send_json_success( array( 'doc' => self::shape( self::row( $id ) ) ) );
	}

	public static function ajax_remove() {
		self::guard();
		$r = self::row( absint( $_POST['id'] ?? 0 ) );
		// Office: administrator and school manager. Students: their own uploads that were not checked yet.
		$own = $r && ! self::is_office() && self::me() === $r['email'] && 'new' === $r['status'];
		if ( ! $r || ! ( self::can_remove() || $own ) ) { wp_send_json_error( array( 'msg' => 'Not allowed.' ), 403 ); }
		global $wpdb;
		foreach ( array( $r['file'], $r['thumb'] ) as $n ) { if ( $n && preg_match( '/^[a-f0-9]{32}\.bin$/', $n ) ) { @unlink( self::dir() . $n ); } } // phpcs:ignore
		$wpdb->delete( self::t_docs(), array( 'id' => (int) $r['id'] ) );
		self::log( (int) $r['id'], 'remove' );
		wp_send_json_success();
	}

	/** Sends the file itself (decrypted) to someone allowed to see it. Never cached. */
	public static function ajax_file() {
		if ( ! is_user_logged_in() || ! check_ajax_referer( 'sdsc_doc_file', '_wpnonce', false ) ) { status_header( 403 ); exit( 'Please sign in again.' ); }
		$r = self::row( absint( $_GET['id'] ?? 0 ) );
		if ( ! self::may( $r ) ) { status_header( 403 ); exit( 'Not allowed.' ); }
		$thumb = ! empty( $_GET['thumb'] ) && $r['thumb'];
		$tiny  = self::limited( $r['email'] );
		if ( $tiny && ! $thumb ) { status_header( 403 ); exit( 'Only the office can open this document.' ); }
		$bytes = self::get( $thumb ? $r['thumb'] : $r['file'] );
		if ( '' === $bytes ) { status_header( 404 ); exit( 'File not found.' ); }
		if ( $tiny ) { $bytes = self::stamp( $bytes ); if ( '' === $bytes ) { status_header( 403 ); exit( 'Not allowed.' ); } }
		if ( ! $thumb ) { self::log( (int) $r['id'], ! empty( $_GET['dl'] ) ? 'download' : 'view' ); }
		$ext  = self::MIMES[ $r['mime'] ] ?? 'bin';
		$file = sanitize_file_name( ( $r['name'] ? $r['name'] : 'student' ) . ' - ' . ( self::types()[ $r['type'] ] ?? 'document' ) . '.' . $ext );
		nocache_headers();
		header( 'Content-Type: ' . ( $thumb ? 'image/jpeg' : $r['mime'] ) );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'Content-Disposition: ' . ( ! empty( $_GET['dl'] ) ? 'attachment' : 'inline' ) . '; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, private, max-age=0' );
		if ( 'application/pdf' !== $r['mime'] || $thumb ) { header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'" ); }
		header( 'Referrer-Policy: no-referrer' );
		echo $bytes; // phpcs:ignore -- binary file
		exit;
	}

	/** A stamp-size copy (72 px wide) of a thumbnail: enough to see a document is there, too small to read. */
	protected static function stamp( $bytes ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) { return ''; }
		$im = @imagecreatefromstring( $bytes ); // phpcs:ignore
		if ( ! $im ) { return ''; }
		$w  = imagesx( $im );
		$h  = imagesy( $im );
		$nw = 72;
		$nh = max( 1, (int) round( $h * $nw / max( 1, $w ) ) );
		$sm = imagecreatetruecolor( $nw, $nh );
		imagecopyresampled( $sm, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
		ob_start();
		imagejpeg( $sm, null, 60 );
		$out = (string) ob_get_clean();
		imagedestroy( $im );
		imagedestroy( $sm );
		return $out;
	}

	/** Instructors and how complete their files are (front office). */
	public static function ajax_staff() {
		self::guard();
		if ( ! self::is_front_office() ) { wp_send_json_error( array( 'msg' => 'Not allowed.' ), 403 ); }
		global $wpdb;
		$all = self::instructors();
		$have = array();
		if ( $all ) {
			$in = implode( ',', array_fill( 0, count( $all ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT email, type, COUNT(*) n FROM ' . self::t_docs() . " WHERE email IN ($in) GROUP BY email, type", array_keys( $all ) ), ARRAY_A ) as $r ) { $have[ $r['email'] ][ $r['type'] ] = (int) $r['n']; } // phpcs:ignore
		}
		$names = self::ins_types();
		$out   = array();
		foreach ( $all as $e => $i ) {
			$h     = $have[ $e ] ?? array();
			$miss  = array_values( array_map( function ( $k ) use ( $names ) { return $names[ $k ]; }, array_diff( self::INS_NEED, array_keys( $h ) ) ) );
			$out[] = $i + array( 'docs' => array_sum( $h ), 'missing' => $miss, 'temp' => false !== strpos( $e, '@instructors.samsdrivingschool.invalid' ) );
		}
		wp_send_json_success( array( 'staff' => $out ) );
	}

	/* ------------------------------------------------------------ dashboard */

	public static function tutor_nav( $items ) {
		// Always registered: Tutor builds its page addresses from this list (also when no one is signed in).
		if ( ! is_array( $items ) ) { return $items; }
		$items[ self::TAB ] = array( 'title' => is_user_logged_in() && self::is_office() ? 'Documents' : 'My documents', 'icon' => 'notes', 'active_icon' => 'notes' );
		return $items;
	}

	public static function tutor_template( $tpl ) {
		global $wp_query;
		if ( self::TAB === ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' ) ) { return dirname( __DIR__ ) . '/templates/tutor-documents.php'; }
		return $tpl;
	}

	public static function assets() {
		if ( ! is_user_logged_in() || self::TAB !== get_query_var( 'tutor_dashboard_page' ) ) { return; }
		wp_enqueue_style( 'sdsc-docs', SDSC_URL . 'assets/docs.css', array(), SDSC_VERSION . '.' . filemtime( SDSC_DIR . 'assets/docs.css' ) );
		wp_enqueue_script( 'sdsc-docs', SDSC_URL . 'assets/docs.js', array(), SDSC_VERSION . '.' . filemtime( SDSC_DIR . 'assets/docs.js' ), true );
		$want = isset( $_GET['student'] ) ? strtolower( sanitize_email( wp_unslash( $_GET['student'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		wp_localize_script( 'sdsc-docs', 'SDSC_DOCS', array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'sdsc_docs' ),
			'office' => self::is_office() ? 1 : 0,
			'remove' => self::can_remove() ? 1 : 0,
			'types'  => self::types( ! self::is_office() ),
			'all'    => self::types(),
			'open'   => self::is_office() ? $want : '',
			'insTypes' => self::ins_types(),
			'front'  => self::is_front_office() ? 1 : 0,
			'me'     => self::me(),
			'iAmIns' => self::is_office() && ! self::is_front_office() ? 1 : 0,
			'ok'     => self::dir() ? 1 : 0,
		) );
	}

	/** The latest image of each of these types for a student, as data URIs (for printing the student file). */
	public static function images( $email, $types ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) { return array(); }
		$out = array();
		foreach ( (array) $types as $t ) {
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_docs() . " WHERE email = %s AND type = %s AND mime IN ('image/jpeg','image/png','image/webp') ORDER BY id DESC LIMIT 1", $email, $t ), ARRAY_A ); // phpcs:ignore
			if ( ! $r ) { continue; }
			$b = self::get( $r['file'] );
			if ( '' === $b ) { continue; }
			self::log( (int) $r['id'], 'print' );
			$out[] = array( 'label' => ( self::types()[ $t ] ?? $t ) . ' · added ' . mysql2date( 'M j, Y', $r['created'] ), 'src' => 'data:' . $r['mime'] . ';base64,' . base64_encode( $b ) ); // phpcs:ignore
		}
		return $out;
	}

	/** Number of student uploads waiting for the office to check (for badges). */
	public static function pending() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_docs() . ' WHERE status = %s', 'new' ) );
	}

	/** Small "Documents" link for other staff screens (registration record, students). */
	public static function link( $email ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) { return ''; }
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_docs() . ' WHERE email = %s', $email ) );
		return '<a class="button" target="_top" href="' . esc_url( self::url( $email ) ) . '">📎 Documents' . ( $n ? ' (' . $n . ')' : '' ) . '</a>';
	}
}
