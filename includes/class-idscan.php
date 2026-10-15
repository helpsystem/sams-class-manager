<?php
/**
 * ID / learner's permit / license scan for Student documents (school team: administrator, school manager,
 * front desk, instructors).
 *
 *  - Phone or tablet camera (live, with a card frame), a photo, or a file from the office scanner.
 *  - The picture is straightened, cropped to the card and made clearer on the device.
 *  - The barcode on the back of a Maryland (and any US/Canadian AAMVA) card is read first — it holds the
 *    exact name, date of birth, address, document number and dates. The printed front is read with OCR
 *    when there is no barcode; those values are marked "please check".
 *  - The data is compared with what the student typed on the registration form; differences are shown,
 *    and the office can copy chosen values into the registration (and the permit number into the
 *    lesson profile). Everything is recorded: who checked, when, and from which source.
 *
 * Nothing leaves the school's own site: the barcode and OCR readers run in the browser from the plugin.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_IDScan {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		if ( false !== strpos( home_url(), 'testsaman.' ) && is_readable( __DIR__ . '/class-lab.php' ) ) { require_once __DIR__ . '/class-lab.php'; SDSC_Lab::init(); } // test site only
		foreach ( array( 'reg', 'save', 'apply' ) as $a ) { add_action( 'wp_ajax_sdsc_id_' . $a, array( __CLASS__, 'ajax_' . $a ) ); }
	}

	public static function can() { return class_exists( 'SDSC_Docs' ) && SDSC_Docs::is_office(); }

	/** Changing the registration: administrator, school manager and front desk (not instructors). */
	public static function can_apply() {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		return class_exists( 'SDSC_Access' ) && (bool) array_intersect( (array) wp_get_current_user()->roles, array( SDSC_Access::MANAGER, SDSC_Access::FRONTDESK ) );
	}

	public static function assets() {
		if ( ! is_user_logged_in() || ! class_exists( 'SDSC_Docs' ) || SDSC_Docs::TAB !== get_query_var( 'tutor_dashboard_page' ) || ! self::can() ) { return; }
		$v = SDSC_VERSION . '.' . filemtime( SDSC_DIR . 'assets/idscan.js' );
		wp_enqueue_script( 'sdsc-idscan', SDSC_URL . 'assets/idscan.js', array(), $v, true );
		wp_enqueue_style( 'sdsc-idscan', SDSC_URL . 'assets/idscan.css', array(), SDSC_VERSION . '.' . filemtime( SDSC_DIR . 'assets/idscan.css' ) );
		wp_localize_script( 'sdsc-idscan', 'SDSC_IDS', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sdsc_ids' ),
			'apply' => self::can_apply() ? 1 : 0,
			'zxing' => SDSC_URL . 'assets/vendor/zxing.min.js?ver=0.21.3',
			'tess'  => SDSC_URL . 'assets/vendor/tesseract/',
			'scanWin' => SDSC_URL . 'assets/scan/SDS-Scan-Windows.zip?v=1.1.0',
			'scanMac' => SDSC_URL . 'assets/scan/SDS-Scan-Mac.zip?v=1.1.0',
		) );
	}

	protected static function guard() {
		if ( ! self::can() || ! check_ajax_referer( 'sdsc_ids', '_n', false ) ) { wp_send_json_error( array( 'msg' => 'Please sign in again.' ), 403 ); }
	}

	protected static function key( $email ) { return 'sdsc_idc_' . md5( strtolower( trim( (string) $email ) ) ); }

	public static function get( $email ) { $v = get_option( self::key( $email ), array() ); return is_array( $v ) ? $v : array(); }

	/** One line for printed forms: "MD learner’s permit …1234, expires 05/01/2028 — checked Oct 15, 2026 by Saghar". */
	public static function summary( $email ) {
		$c = self::get( $email );
		if ( empty( $c['at'] ) ) { return ''; }
		$f   = (array) ( $c['fields'] ?? array() );
		$no  = (string) ( $f['number'] ?? '' );
		$u   = ! empty( $c['by'] ) ? get_userdata( (int) $c['by'] ) : null;
		$doc = array( 'permit' => 'learner’s permit', 'license' => 'driver’s license', 'id' => 'ID card' )[ $c['doc'] ?? '' ] ?? 'ID';
		return trim( ( $f['state'] ?? '' ) . ' ' . $doc ) . ( $no ? ' …' . substr( preg_replace( '/[^A-Z0-9]/i', '', $no ), -4 ) : '' ) . ( ! empty( $f['expires'] ) ? ', expires ' . $f['expires'] : '' ) . ' — checked ' . mysql2date( 'M j, Y', $c['at'] ) . ( $u ? ' by ' . $u->display_name : '' );
	}

	protected static function reg( $email ) {
		global $wpdb;
		if ( ! class_exists( 'SDSC_Registration' ) ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, source, source_entry_id, first_name, middle_name, last_name, dob, gender, street, apt, city, state, zip, phone FROM ' . SDSC_Registration::table() . " WHERE email = %s AND source <> 'demo' ORDER BY id DESC LIMIT 1", strtolower( $email ) ), ARRAY_A ); // phpcs:ignore
	}

	public static function ajax_reg() {
		self::guard();
		$email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ); // phpcs:ignore
		$r     = $email ? self::reg( $email ) : null;
		$c     = self::get( $email );
		wp_send_json_success( array(
			'reg'   => $r ? array( 'id' => (int) $r['id'], 'file' => SDSC_Registration::file_no( $r ), 'first' => $r['first_name'], 'middle' => $r['middle_name'], 'last' => $r['last_name'], 'dob' => (string) $r['dob'], 'sex' => $r['gender'], 'street' => trim( $r['street'] . ( $r['apt'] ? ' ' . $r['apt'] : '' ) ), 'city' => $r['city'], 'state' => $r['state'], 'zip' => $r['zip'] ) : null,
			'check' => $c ? array( 'summary' => self::summary( $email ), 'fields' => $c['fields'] ?? array() ) : null,
		) );
	}

	/** The fields a scan may carry (all strings; dates as Y-m-d). */
	protected static function clean( $in ) {
		$keys = array( 'first', 'middle', 'last', 'dob', 'sex', 'street', 'city', 'state', 'zip', 'number', 'issued', 'expires', 'class' );
		$out  = array();
		foreach ( $keys as $k ) {
			$v = sanitize_text_field( mb_substr( (string) ( $in[ $k ] ?? '' ), 0, 120 ) );
			if ( in_array( $k, array( 'dob', 'issued', 'expires' ), true ) && $v && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) { $v = ''; }
			if ( '' !== $v ) { $out[ $k ] = $v; }
		}
		return $out;
	}

	public static function ajax_save() {
		self::guard();
		$email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ); // phpcs:ignore
		if ( ! is_email( $email ) ) { wp_send_json_error( array( 'msg' => 'Choose the student first.' ) ); }
		$f = json_decode( (string) wp_unslash( $_POST['fields'] ?? '' ), true ); // phpcs:ignore
		$f = self::clean( is_array( $f ) ? $f : array() );
		if ( ! $f ) { wp_send_json_error( array( 'msg' => 'Nothing was read from the card.' ) ); }
		$doc  = in_array( $_POST['doc'] ?? '', array( 'permit', 'license', 'id' ), true ) ? $_POST['doc'] : 'id'; // phpcs:ignore
		$src  = 'barcode' === ( $_POST['src'] ?? '' ) ? 'barcode' : 'ocr'; // phpcs:ignore
		$diff = array_values( array_filter( array_map( 'sanitize_text_field', (array) json_decode( (string) wp_unslash( $_POST['diff'] ?? '[]' ), true ) ) ) ); // phpcs:ignore
		$rec  = array( 'fields' => $f, 'doc' => $doc, 'src' => $src, 'diff' => $diff, 'at' => current_time( 'mysql' ), 'by' => get_current_user_id() );
		update_option( self::key( $email ), $rec, false );
		// The permit / license number goes into the student's behind-the-wheel profile.
		global $wpdb;
		if ( ! empty( $f['number'] ) && in_array( $doc, array( 'permit', 'license' ), true ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}driveflow_students SET license_number = %s, updated_at = %s WHERE LOWER(email) = %s", $f['number'], current_time( 'mysql' ), $email ) ); // phpcs:ignore
		}
		wp_send_json_success( array( 'msg' => 'ID check saved' . ( $diff ? ' — ' . count( $diff ) . ' difference' . ( count( $diff ) > 1 ? 's' : '' ) . ' noted.' : ', everything matches.' ), 'summary' => self::summary( $email ) ) );
	}

	/** Copy chosen values from the card into the student's registration. */
	public static function ajax_apply() {
		self::guard();
		if ( ! self::can_apply() ) { wp_send_json_error( array( 'msg' => 'Only the office can change a registration.' ) ); }
		global $wpdb;
		$email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ); // phpcs:ignore
		$r     = self::reg( $email );
		if ( ! $r ) { wp_send_json_error( array( 'msg' => 'This student has no registration form to update.' ) ); }
		$f    = self::clean( (array) json_decode( (string) wp_unslash( $_POST['fields'] ?? '' ), true ) ); // phpcs:ignore
		$want = array_map( 'sanitize_key', (array) json_decode( (string) wp_unslash( $_POST['use'] ?? '[]' ), true ) ); // phpcs:ignore
		$map  = array( 'first' => 'first_name', 'middle' => 'middle_name', 'last' => 'last_name', 'dob' => 'dob', 'sex' => 'gender', 'street' => 'street', 'city' => 'city', 'state' => 'state', 'zip' => 'zip' );
		$up   = array();
		$log  = array();
		foreach ( $want as $k ) {
			if ( ! isset( $map[ $k ], $f[ $k ] ) ) { continue; }
			$v = 'sex' === $k ? ( array( 'M' => 'Male', 'F' => 'Female', 'X' => 'Other' )[ $f[ $k ] ] ?? $f[ $k ] ) : ( in_array( $k, array( 'first', 'middle', 'last', 'street', 'city' ), true ) ? ucwords( strtolower( $f[ $k ] ) ) : $f[ $k ] );
			$up[ $map[ $k ] ] = $v;
			if ( 'street' === $k ) { $up['apt'] = ''; }
			$log[] = $map[ $k ] . ': "' . (string) ( $r[ $map[ $k ] ] ?? '' ) . '" → "' . $v . '"';
		}
		if ( ! $up ) { wp_send_json_error( array( 'msg' => 'Choose which values to copy.' ) ); }
		$wpdb->update( SDSC_Registration::table(), $up, array( 'id' => (int) $r['id'] ) );
		$c = self::get( $email );
		$c['applied'][] = array( 'at' => current_time( 'mysql' ), 'by' => get_current_user_id(), 'changes' => $log );
		update_option( self::key( $email ), $c, false );
		wp_send_json_success( array( 'msg' => count( $up ) . ' field' . ( count( $up ) > 1 ? 's' : '' ) . ' copied into registration ' . SDSC_Registration::file_no( $r ) . '.' ) );
	}
}
