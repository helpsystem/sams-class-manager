<?php
/**
 * Student transfers between driving schools.
 *
 *  - Transfer IN: a student who did part of the course at another school. The office records the other
 *    school and the classroom hours completed there when adding the student (class board → Add a student)
 *    or later on the student's registration record. The record shows how many hours are still needed here.
 *  - Transfer OUT: a student leaving for another school. The office records the destination and prints a
 *    transfer record (hours completed here, attendance, registration form, ID copy) and/or e-mails the
 *    record with the documents on file to the student and/or the parent. Each send is logged.
 *
 * Nothing is sent automatically: printing and e-mailing are buttons on the registration record.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Transfer {
	const HOURS = 30; // classroom hours of the Maryland driver education course (plus 6 hours behind the wheel)
	const DBV   = 'sdsc_transfer_db';

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_transfer'; }

	public static function init() {
		add_action( 'init', array( __CLASS__, 'install' ), 20 );
		add_action( 'admin_post_sdsc_transfer_save', array( __CLASS__, 'h_save' ) );
		add_action( 'admin_post_sdsc_transfer_print', array( __CLASS__, 'h_print' ) );
		add_action( 'admin_post_sdsc_transfer_mail', array( __CLASS__, 'h_mail' ) );
	}

	const BTW   = 6;  // behind-the-wheel hours of the course
	const PURPOSES = array( 'both' => 'Classroom + behind the wheel', 'class' => 'Classroom only', 'btw' => 'Behind the wheel only', 'extra' => 'Extra practice only' );
	const MVA      = array( '' => 'Not known', 'none' => 'No permit yet', 'permit' => 'Learner’s permit', 'provisional' => 'Provisional license', 'license' => 'Full driver’s license' );

	public static function install() {
		if ( '3' === get_option( self::DBV ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reg_id bigint(20) unsigned NOT NULL,
			dir varchar(3) NOT NULL DEFAULT 'in',
			school varchar(200) NOT NULL DEFAULT '',
			hours decimal(4,1) NOT NULL DEFAULT 0,
			on_date date DEFAULT NULL,
			note text NULL,
			units varchar(60) NOT NULL DEFAULT '',
			btw_hours decimal(4,1) NOT NULL DEFAULT 0,
			btw_lessons tinyint(3) unsigned NOT NULL DEFAULT 0,
			final_pass tinyint(1) NOT NULL DEFAULT 0,
			mva varchar(12) NOT NULL DEFAULT '',
			mva_no varchar(40) NOT NULL DEFAULT '',
			mva_date date DEFAULT NULL,
			purpose varchar(12) NOT NULL DEFAULT 'both',
			sent_log text NULL,
			by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reg_dir (reg_id,dir)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBV, '3', false );
	}

	public static function can() { return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'registrations' ) : current_user_can( 'manage_options' ); }

	/* ------------------------------------------------------------ data */

	public static function get( $rid, $dir ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE reg_id = %d AND dir = %s', (int) $rid, $dir ), ARRAY_A );
	}

	public static function both( $rid ) { return array( 'in' => self::get( $rid, 'in' ), 'out' => self::get( $rid, 'out' ) ); }

	/** Save (insert or update) one direction. $a: school, hours, on_date, note. Returns the row id. */
	public static function save( $rid, $dir, $a ) {
		global $wpdb;
		$dir  = 'out' === $dir ? 'out' : 'in';
		$data = array(
			'school'  => mb_substr( sanitize_text_field( (string) ( $a['school'] ?? '' ) ), 0, 200 ),
			'hours'   => max( 0, min( 99, round( (float) ( $a['hours'] ?? 0 ) * 2 ) / 2 ) ),
			'on_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $a['on_date'] ?? '' ) ) ? $a['on_date'] : null,
			'note'    => mb_substr( sanitize_textarea_field( (string) ( $a['note'] ?? '' ) ), 0, 2000 ),
			'units'       => mb_substr( sanitize_text_field( (string) ( $a['units'] ?? '' ) ), 0, 60 ),
			'btw_hours'   => max( 0, min( 99, round( (float) ( $a['btw_hours'] ?? 0 ) * 2 ) / 2 ) ),
			'btw_lessons' => max( 0, min( 99, (int) ( $a['btw_lessons'] ?? 0 ) ) ),
			'final_pass'  => empty( $a['final_pass'] ) ? 0 : 1,
			'mva'         => isset( self::MVA[ (string) ( $a['mva'] ?? '' ) ] ) ? (string) $a['mva'] : '',
			'mva_no'      => mb_substr( sanitize_text_field( (string) ( $a['mva_no'] ?? '' ) ), 0, 40 ),
			'mva_date'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $a['mva_date'] ?? '' ) ) ? $a['mva_date'] : null,
			'purpose'     => isset( self::PURPOSES[ (string) ( $a['purpose'] ?? '' ) ] ) ? (string) $a['purpose'] : 'both',
			'by_user' => get_current_user_id(),
			'updated' => current_time( 'mysql' ),
		);
		$old = self::get( $rid, $dir );
		if ( $old ) {
			$wpdb->update( self::table(), $data, array( 'id' => (int) $old['id'] ) );
			return (int) $old['id'];
		}
		$wpdb->insert( self::table(), $data + array( 'reg_id' => (int) $rid, 'dir' => $dir, 'created' => current_time( 'mysql' ) ) );
		return (int) $wpdb->insert_id;
	}

	public static function remove( $rid, $dir ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'reg_id' => (int) $rid, 'dir' => 'out' === $dir ? 'out' : 'in' ) );
	}

	protected static function reg( $rid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Registration::table() . ' WHERE id = %d', (int) $rid ), ARRAY_A );
	}

	/** Short text for lists and cards: "Transfer in · 12 h from X" / "Transferred out → Y". */
	public static function tag( $rid ) {
		$b = self::both( $rid );
		$o = array();
		if ( $b['in'] ) {
			$i = $b['in']; $bits = array();
			if ( $i['hours'] > 0 ) { $bits[] = self::h( $i['hours'] ) . ' class'; }
			if ( $i['btw_hours'] > 0 ) { $bits[] = self::h( $i['btw_hours'] ) . ' BTW'; }
			if ( in_array( $i['mva'], array( 'license', 'provisional' ), true ) ) { $bits[] = 'has license'; }
			if ( 'both' !== $i['purpose'] && isset( self::PURPOSES[ $i['purpose'] ] ) ) { $bits[] = strtolower( self::PURPOSES[ $i['purpose'] ] ); }
			$o[] = 'Transfer in' . ( $bits ? ' · ' . implode( ' · ', $bits ) : '' );
		}
		if ( $b['out'] ) { $o[] = 'Transferred out' . ( $b['out']['school'] ? ' → ' . $b['out']['school'] : '' ); }
		return implode( ' · ', $o );
	}

	public static function h( $n ) { $n = (float) $n; return ( abs( $n - round( $n ) ) < 0.05 ? (string) (int) round( $n ) : number_format( $n, 1 ) ) . ' h'; }

	/* ------------------------------------------------------------ what other screens need */

	/** Transfer-in rows of a class keyed by the student's e-mail (for attendance, Zoom and the roster). */
	public static function ins_of_class( $cid ) {
		global $wpdb;
		static $cache = array();
		$cid = (int) $cid;
		if ( ! isset( $cache[ $cid ] ) ) {
			$cache[ $cid ] = array();
			if ( $cid && self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) ) {
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT t.*, r.email FROM ' . self::table() . ' t JOIN ' . SDSC_Registration::table() . " r ON r.id = t.reg_id WHERE t.dir = 'in' AND r.class_id = %d", $cid ), ARRAY_A ) as $x ) { // phpcs:ignore
					$e = strtolower( trim( (string) $x['email'] ) );
					if ( $e ) { $cache[ $cid ][ $e ] = $x; }
				}
			}
		}
		return $cache[ $cid ];
	}

	/** Is this student in the class for the classroom part? (Behind-the-wheel-only and extra-practice students are not.) */
	public static function classroom( $cid, $email ) {
		$x = self::ins_of_class( $cid )[ strtolower( trim( (string) $email ) ) ] ?? null;
		return ! $x || in_array( $x['purpose'], array( 'both', 'class' ), true );
	}

	/**
	 * Session dates of the class this student already completed at the previous school: the first N sessions,
	 * N = hours done there ÷ length of a session. Those sessions are not expected and never count as absences.
	 */
	public static function covered( $cid, $email ) {
		$x = self::ins_of_class( $cid )[ strtolower( trim( (string) $email ) ) ] ?? null;
		if ( ! $x || (float) $x['hours'] <= 0 || ! class_exists( 'SDSC_Schedule' ) ) { return array(); }
		$list = SDSC_Schedule::sessions( $cid );
		if ( ! $list ) { return array(); }
		$len  = max( 0.5, ( strtotime( $list[0]['end'] ) - strtotime( $list[0]['start'] ) ) / 3600 );
		$n    = (int) floor( (float) $x['hours'] / $len + 0.01 );
		return array_slice( array_column( $list, 'date' ), 0, $n );
	}

	/** MVA status recorded on a transfer-in for this e-mail ('' when none): permit | provisional | license | none. */
	public static function mva_for( $email ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) || self::table() !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) ) { return ''; }
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT t.mva FROM ' . self::table() . ' t JOIN ' . SDSC_Registration::table() . " r ON r.id = t.reg_id WHERE t.dir = 'in' AND LOWER(r.email) = %s ORDER BY t.updated DESC LIMIT 1", $email ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------ hours completed here (attendance) */

	/**
	 * Classroom hours attended in this student's class: [hours, attended sessions, total sessions, first date, last date, rows].
	 * Sessions marked present / late / left early count for their scheduled length.
	 */
	public static function done_here( $reg ) {
		$cid   = (int) ( $reg['class_id'] ?? 0 );
		$email = strtolower( trim( (string) ( $reg['email'] ?? '' ) ) );
		if ( ! $cid || ! class_exists( 'SDSC_Schedule' ) ) { return array( 0, 0, 0, '', '', array() ); }
		$list = SDSC_Schedule::sessions( $cid );
		$roll = ( $email && class_exists( 'SDSC_ZoomRoll' ) ) ? ( SDSC_ZoomRoll::rows( $cid )[ $email ] ?? array() ) : array();
		$hrs  = 0; $att = 0; $first = ''; $last = ''; $rows = array();
		foreach ( $list as $x ) {
			$m   = $roll[ $x['date'] ] ?? array();
			$v   = (string) ( $m['actual'] ?? '' );
			$ok  = in_array( $v, array( 'present', 'late', 'left' ), true );
			$len = max( 0, ( strtotime( $x['end'] ) - strtotime( $x['start'] ) ) / 3600 );
			if ( $ok ) { $hrs += $len; $att++; $first = $first ?: $x['date']; $last = $x['date']; }
			$rows[] = array( 'n' => (int) $x['n'], 'date' => $x['date'], 'start' => $x['start'], 'len' => $len, 'status' => $v );
		}
		return array( round( $hrs * 2 ) / 2, $att, count( $list ), $first, $last, $rows );
	}

	/* ------------------------------------------------------------ registration record panel */

	public static function panel( $r ) {
		$r   = (array) $r;
		$rid = (int) $r['id'];
		$b   = self::both( $rid );
		list( $hrs, $att, $tot ) = self::done_here( $r );
		$msg = isset( $_GET['sdsc_transfer'] ) ? sanitize_text_field( wp_unslash( $_GET['sdsc_transfer'] ) ) : ''; // phpcs:ignore
		$f   = function ( $k, $dir, $v ) { return esc_attr( (string) ( $v[ $k ] ?? '' ) ); };
		$act = admin_url( 'admin-post.php' );
		$in  = $b['in'] ?: array(); $out = $b['out'] ?: array();
		$need = max( 0, self::HOURS - (float) ( $in['hours'] ?? 0 ) );

		$h  = '<div style="margin:14px 0;padding:14px 16px;border-radius:12px;background:#f6f8f7;border:1px solid #dfe5e1;max-width:800px"><h3 style="margin:0 0 4px">Transfer between schools</h3>';
		$h .= '<p style="margin:0 0 10px;color:#5b646d">Classroom hours attended here so far: <b>' . esc_html( self::h( $hrs ) ) . '</b> (' . (int) $att . ' of ' . (int) $tot . ' sessions)' . ( $in ? ' · still needed here: <b>' . esc_html( self::h( $need ) ) . '</b> classroom, <b>' . esc_html( self::h( max( 0, self::BTW - (float) ( $in['btw_hours'] ?? 0 ) ) ) ) . '</b> behind the wheel' . ( $in['hours'] > 0 && ! empty( $r['class_id'] ) ? ' · the first ' . count( self::covered( (int) $r['class_id'], $r['email'] ) ) . ' sessions count as done at ' . esc_html( $in['school'] ?: 'the previous school' ) : '' ) : '' ) . '. The course is ' . self::HOURS . ' classroom + ' . self::BTW . ' behind-the-wheel hours.</p>';
		if ( $msg ) { $h .= '<div class="notice notice-success inline" style="margin:0 0 10px"><p>' . esc_html( $msg ) . '</p></div>'; }
		$h .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px">';

		// Transfer IN
		$sel = function ( $name, $opts, $cur ) { $o = '<select name="' . $name . '">'; foreach ( $opts as $k => $v ) { $o .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $cur, (string) $k, false ) . '>' . esc_html( $v ) . '</option>'; } return $o . '</select>'; };
		$h .= '<form method="post" action="' . esc_url( $act ) . '" style="background:#fff;border:1px solid #dfe5e1;border-radius:10px;padding:12px">' . wp_nonce_field( 'sdsc_transfer_' . $rid, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="sdsc_transfer_save"><input type="hidden" name="r" value="' . $rid . '"><input type="hidden" name="dir" value="in">'
			. '<h4 style="margin:0 0 8px">Came from another school' . ( $in ? ' <span style="color:#00703c">✓</span>' : '' ) . '</h4>'
			. '<p style="margin:0 0 6px"><label>School they came from<br><input type="text" name="school" value="' . $f( 'school', 'in', $in ) . '" class="regular-text" maxlength="200" placeholder="Name of the other driving school"></label></p>'
			. '<p style="margin:0 0 6px"><label>Here for<br>' . $sel( 'purpose', self::PURPOSES, $in['purpose'] ?? 'both' ) . '</label></p>'
			. '<p style="margin:0 0 6px"><label>Classroom hours completed there <input type="number" name="hours" value="' . $f( 'hours', 'in', $in ) . '" min="0" max="30" step="0.5" style="width:80px"> of ' . self::HOURS . '</label> &nbsp; <label>units <input type="text" name="units" value="' . $f( 'units', 'in', $in ) . '" maxlength="60" style="width:90px" placeholder="1–4"></label></p>'
			. '<p style="margin:0 0 6px"><label>Behind-the-wheel hours there <input type="number" name="btw_hours" value="' . $f( 'btw_hours', 'in', $in ) . '" min="0" max="6" step="0.5" style="width:80px"> of ' . self::BTW . '</label> &nbsp; <label>lessons <input type="number" name="btw_lessons" value="' . $f( 'btw_lessons', 'in', $in ) . '" min="0" max="9" style="width:60px"></label></p>'
			. '<p style="margin:0 0 6px"><label><input type="checkbox" name="final_pass" value="1"' . checked( ! empty( $in['final_pass'] ), true, false ) . '> Passed the final exam there</label></p>'
			. '<p style="margin:0 0 6px"><label>MVA status<br>' . $sel( 'mva', self::MVA, $in['mva'] ?? '' ) . '</label> <label>number <input type="text" name="mva_no" value="' . $f( 'mva_no', 'in', $in ) . '" maxlength="40" style="width:130px"></label> <label>issued <input type="date" name="mva_date" value="' . $f( 'mva_date', 'in', $in ) . '"></label></p>'
			. '<p style="margin:0 0 6px"><label>Transfer date<br><input type="date" name="on_date" value="' . $f( 'on_date', 'in', $in ) . '"></label></p>'
			. '<p style="margin:0 0 8px"><label>Note (units done, paperwork received…)<br><textarea name="note" rows="2" class="large-text">' . esc_textarea( (string) ( $in['note'] ?? '' ) ) . '</textarea></label></p>'
			. '<p style="margin:0"><button class="button button-primary">Save</button> ' . ( $in ? '<button class="button" name="remove" value="1" onclick="return confirm(\'Remove the transfer-in record?\')">Remove</button>' : '' ) . '</p></form>';

		// Transfer OUT
		$h .= '<form method="post" action="' . esc_url( $act ) . '" style="background:#fff;border:1px solid #dfe5e1;border-radius:10px;padding:12px">' . wp_nonce_field( 'sdsc_transfer_' . $rid, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="sdsc_transfer_save"><input type="hidden" name="r" value="' . $rid . '"><input type="hidden" name="dir" value="out">'
			. '<h4 style="margin:0 0 8px">Leaving for another school' . ( $out ? ' <span style="color:#00703c">✓</span>' : '' ) . '</h4>'
			. '<p style="margin:0 0 6px"><label>School they are going to<br><input type="text" name="school" value="' . $f( 'school', 'out', $out ) . '" class="regular-text" maxlength="200" placeholder="Name of the other driving school"></label></p>'
			. '<p style="margin:0 0 6px"><label>Classroom hours completed here<br><input type="number" name="hours" value="' . esc_attr( $out ? $out['hours'] : $hrs ) . '" min="0" max="30" step="0.5" style="width:90px"> of ' . self::HOURS . '</label> <span class="description">(from the attendance sheet: ' . esc_html( self::h( $hrs ) ) . ')</span></p>'
			. '<p style="margin:0 0 6px"><label>Transfer date<br><input type="date" name="on_date" value="' . esc_attr( $out['on_date'] ?? current_time( 'Y-m-d' ) ) . '"></label></p>'
			. '<p style="margin:0 0 8px"><label>Note<br><textarea name="note" rows="2" class="large-text">' . esc_textarea( (string) ( $out['note'] ?? '' ) ) . '</textarea></label></p>'
			. '<p style="margin:0"><button class="button button-primary">Save</button> ' . ( $out ? '<button class="button" name="remove" value="1" onclick="return confirm(\'Remove the transfer-out record?\')">Remove</button>' : '' ) . '</p></form>';
		$h .= '</div>';

		// Print and e-mail (needs a saved transfer-out record)
		$h .= '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #dfe5e1"><h4 style="margin:0 0 8px">Give the student their records</h4>';
		if ( ! $out ) {
			$h .= '<p style="margin:0;color:#5b646d">Save the “Leaving for another school” box first, then print the transfer record or e-mail it with the documents on file.</p></div></div>';
			return $h;
		}
		$purl = wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_transfer_print&r=' . $rid ), 'sdsc_transfer_' . $rid );
		$h   .= '<p style="margin:0 0 10px"><a class="button" target="_blank" rel="noopener" href="' . esc_url( $purl ) . '">🖨 Print the transfer record</a> <span class="description">Transfer record + attendance + registration form + ID copy, for the student to take to the other school (or Save as PDF).</span></p>';
		// e-mail form
		$docs = class_exists( 'SDSC_Docs' ) ? self::docs_of( $r['email'] ) : array();
		$h   .= '<form method="post" action="' . esc_url( $act ) . '" style="background:#fff;border:1px solid #dfe5e1;border-radius:10px;padding:12px;max-width:560px">' . wp_nonce_field( 'sdsc_transfer_' . $rid, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="sdsc_transfer_mail"><input type="hidden" name="r" value="' . $rid . '">'
			. '<p style="margin:0 0 6px"><b>E-mail the transfer record</b></p><p style="margin:0 0 6px">To: ';
		$any = false;
		foreach ( array( 'email' => 'Student', 'parent_email' => 'Parent / guardian' ) as $k => $lbl ) {
			if ( is_email( (string) $r[ $k ] ) ) { $any = true; $h .= '<label style="margin-right:14px"><input type="checkbox" name="to[]" value="' . esc_attr( $k ) . '" checked> ' . $lbl . ' (' . esc_html( $r[ $k ] ) . ')</label>'; }
		}
		if ( ! $any ) { $h .= '<span style="color:#8a2424">no e-mail address on this record</span>'; }
		$h .= '</p><p style="margin:0 0 6px">Attach from the student’s documents:';
		if ( ! $docs ) { $h .= ' <span class="description">nothing on file yet — scan or upload documents first (Documents screen)</span>'; }
		foreach ( $docs as $d ) { $h .= '<br><label><input type="checkbox" name="docs[]" value="' . (int) $d['id'] . '" checked> ' . esc_html( $d['label'] ) . ' <span class="description">(' . esc_html( $d['size'] ) . ')</span></label>'; }
		$h .= '</p><p style="margin:0 0 6px"><label>Message to add (optional)<br><textarea name="msg" rows="2" class="large-text" placeholder="e.g. Please hand this to the new school. Call us with any question."></textarea></label></p>'
			. '<p style="margin:0"><button class="button button-primary"' . ( $any ? '' : ' disabled' ) . ' onclick="return confirm(\'Send the transfer record and the selected documents now?\')">✉ Send</button></p>';
		if ( ! empty( $out['sent_log'] ) ) {
			$h .= '<p class="description" style="margin:8px 0 0">Sent: ' . esc_html( str_replace( "\n", ' · ', trim( (string) $out['sent_log'] ) ) ) . '</p>';
		}
		$h .= '</form></div></div>';
		return $h;
	}

	/** Documents on file for an e-mail: [id, label, mime, size, file]. */
	protected static function docs_of( $email ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, label, mime, size, file, created FROM ' . SDSC_Docs::t_docs() . ' WHERE email = %s ORDER BY id DESC', $email ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $d ) {
			$out[] = array( 'id' => (int) $d['id'], 'file' => $d['file'], 'mime' => $d['mime'], 'size' => size_format( (int) $d['size'] ), 'label' => ( SDSC_Docs::types()[ $d['type'] ] ?? $d['type'] ) . ( $d['label'] ? ' — ' . $d['label'] : '' ) . ' · ' . mysql2date( 'M j, Y', $d['created'] ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------ handlers */

	protected static function back( $rid, $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'sdsc-registrations', 'view' => (int) $rid, 'sdsc_transfer' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) . '#sdsc-transfer' );
		exit;
	}

	public static function h_save() {
		$rid = absint( $_POST['r'] ?? 0 ); // phpcs:ignore
		if ( ! self::can() || ! $rid || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'sdsc_transfer_' . $rid ) ) { wp_die( 'Not allowed.', 403 ); } // phpcs:ignore
		$dir = 'out' === ( $_POST['dir'] ?? '' ) ? 'out' : 'in'; // phpcs:ignore
		if ( ! empty( $_POST['remove'] ) ) { self::remove( $rid, $dir ); self::back( $rid, 'Transfer record removed.' ); } // phpcs:ignore
		self::save( $rid, $dir, wp_unslash( $_POST ) ); // phpcs:ignore -- fields are sanitised in save()
		self::back( $rid, 'Transfer record saved.' );
	}

	/** Person array the student-file printouts expect. */
	protected static function person( $r ) {
		$p = class_exists( 'SDSC_Student_File' ) ? SDSC_Student_File::from_regs( array( (int) $r['id'] ) ) : array();
		return $p ? $p[0] : array( 'email' => $r['email'], 'name' => trim( $r['first_name'] . ' ' . $r['last_name'] ), 'user_id' => 0, 'reg' => $r, 'class' => (int) $r['class_id'] );
	}

	public static function h_print() {
		$rid = absint( $_GET['r'] ?? 0 ); // phpcs:ignore
		if ( ! self::can() || ! $rid || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'sdsc_transfer_' . $rid ) ) { wp_die( 'Not allowed.', 403 ); } // phpcs:ignore
		$r = self::reg( $rid );
		if ( ! $r || ! class_exists( 'SDSC_Student_File' ) ) { wp_die( 'Not found.' ); }
		$p = self::person( $r );
		SDSC_Student_File::print_page( array( $p ), array( 'transfer', 'attendance', 'form', 'idcopy' ), $p['class'] ? 0 : SDSC_Student_File::default_course(), 'Transfer record — ' . $p['name'] );
	}

	/** The transfer record page (student-file printout part "transfer"). */
	public static function record_html( $p ) {
		$r   = (array) ( $p['reg'] ?? array() );
		$b   = self::both( (int) ( $r['id'] ?? 0 ) );
		$in  = $b['in'] ?: array(); $out = $b['out'] ?: array();
		list( $hrs, $att, $tot, $first, $last ) = self::done_here( $r );
		$here = $out && '' !== (string) $out['hours'] ? (float) $out['hours'] : $hrs;
		$d    = function ( $x ) { return $x ? wp_date( 'm/d/Y', strtotime( $x . ' 12:00:00' ) ) : '—'; };
		$cls  = ! empty( $p['class'] ) ? SDSC_Schedule::class_name( $p['class'], true ) : '—';
		$rows = array(
			'Student'                       => trim( $r['first_name'] . ' ' . ( $r['middle_name'] ?? '' ) . ' ' . $r['last_name'] ),
			'Date of birth'                 => $r['dob'] ? wp_date( 'm/d/Y', strtotime( $r['dob'] . ' 12:00:00' ) ) : '—',
			'Address'                       => trim( "{$r['street']} {$r['apt']}, {$r['city']} {$r['state']} {$r['zip']}", ' ,' ),
			'Phone / e-mail'                => trim( $r['phone'] . ' · ' . $r['email'], ' ·' ),
			'Parent / guardian'             => trim( $r['parent_name'] . ' ' . $r['parent_phone'] . ' ' . $r['parent_email'] ),
			'Course at this school'         => $cls . ( $first ? ' · attended ' . $d( $first ) . ' – ' . $d( $last ) : '' ),
			'Classroom hours completed here' => self::h( $here ) . ' of ' . self::HOURS . ' (' . (int) $att . ' of ' . (int) $tot . ' sessions attended)',
		);
		if ( $in ) {
			$rows['Previous school']               = ( $in['school'] ?: '—' ) . ( $in['on_date'] ? ' · transferred in ' . $d( $in['on_date'] ) : '' ) . ( 'both' !== $in['purpose'] && isset( self::PURPOSES[ $in['purpose'] ] ) ? ' · here for: ' . self::PURPOSES[ $in['purpose'] ] : '' );
			$rows['Classroom hours at that school'] = self::h( $in['hours'] ) . ( $in['units'] ? ' (units ' . $in['units'] . ')' : '' ) . ( $in['final_pass'] ? ' · final exam passed there' : '' );
			$rows['Behind-the-wheel at that school'] = self::h( $in['btw_hours'] ) . ( $in['btw_lessons'] ? ' (' . (int) $in['btw_lessons'] . ' lessons)' : '' );
			$rows['Total classroom hours to date']  = self::h( (float) $in['hours'] + $here ) . ' of ' . self::HOURS;
			if ( $in['mva'] ) { $rows['MVA status'] = ( self::MVA[ $in['mva'] ] ?? $in['mva'] ) . ( $in['mva_no'] ? ' · ' . $in['mva_no'] : '' ) . ( $in['mva_date'] ? ' · issued ' . $d( $in['mva_date'] ) : '' ); }
		}
		$rows['Hours still required'] = self::h( max( 0, self::HOURS - $here - (float) ( $in['hours'] ?? 0 ) ) ) . ' classroom · behind-the-wheel per the receiving school’s records';
		if ( $out ) {
			$rows['Transferring to'] = ( $out['school'] ?: '—' ) . ( $out['on_date'] ? ' · ' . $d( $out['on_date'] ) : '' );
			if ( $out['note'] ) { $rows['Note'] = $out['note']; }
		}
		$t = '<table class="f">';
		foreach ( $rows as $k => $v ) { $t .= '<tr><th style="width:2.4in;text-align:left;vertical-align:top;padding:4px 6px 4px 0">' . esc_html( $k ) . '</th><td style="padding:4px 0">' . esc_html( (string) $v ) . '</td></tr>'; }
		$t .= '</table>';
		$t .= '<p style="margin:14px 0 0;font-size:9.5pt">This record states the classroom instruction this student completed at Sam’s Driving School LLC, an MVA-licensed driver education school, as shown on the attached Class Attendance Record. It is issued at the request of the student / parent for transfer to another licensed driving school. Online unit tests and the final exam, where taken, are kept in the student file.</p>';
		$t .= '<div class="office"><div>School manager / authorized official</div><div>Date</div><div>Student / parent received</div></div>';
		return '<section class="pg"><table class="wrap">' . self::head( 'Driver Education Transfer Record', $p ) . '<tbody><tr><td>' . $t . '</td></tr></tbody></table></section>';
	}

	/** Same page header as the other student-file printouts. */
	protected static function head( $title, $p ) {
		$logo = class_exists( 'SDSC_Brand' ) && method_exists( 'SDSC_Brand', 'logo' ) ? SDSC_Brand::logo( 'wide', '', 40 ) : '<b>Sam’s Driving School</b>';
		$cls  = ! empty( $p['class'] ) ? SDSC_Schedule::class_name( $p['class'], true ) : '';
		$no   = SDSC_Student_File::file_no( $p );
		return '<thead><tr><td><div class="who"><span class="logo">' . $logo . '</span><span class="t"><h1>' . esc_html( $title ) . '</h1><span class="s">' . esc_html( $p['name'] ) . ( $cls ? ' · ' . esc_html( $cls ) : '' ) . '</span></span>'
			. '<span class="r">File no. <b>' . esc_html( $no ) . '</b><br>' . esc_html( $p['email'] ) . '</span></div><div class="road"></div></td></tr></thead>';
	}

	/** E-mail body: the transfer record as a simple table, plus the attendance list. */
	protected static function mail_html( $r, $first, $msg ) {
		$b   = self::both( (int) $r['id'] );
		$in  = $b['in'] ?: array(); $out = $b['out'] ?: array();
		list( $hrs, $att, $tot, $f0, $l0, $sess ) = self::done_here( $r );
		$here = $out && '' !== (string) $out['hours'] ? (float) $out['hours'] : $hrs;
		$d    = function ( $x ) { return $x ? wp_date( 'M j, Y', strtotime( $x . ' 12:00:00' ) ) : '—'; };
		$f    = 'font-family:Arial,Helvetica,sans-serif;';
		$tr   = function ( $k, $v ) use ( $f ) { return '<tr><td style="' . $f . 'padding:7px 10px 7px 0;border-bottom:1px solid #edf0f2;font-size:14px;color:#5b646d;vertical-align:top;white-space:nowrap">' . esc_html( $k ) . '</td><td style="' . $f . 'padding:7px 0;border-bottom:1px solid #edf0f2;font-size:14px;color:#15191d">' . esc_html( $v ) . '</td></tr>'; };
		$h  = '<p style="' . $f . 'font-size:15px;line-height:1.6;margin:0 0 14px">Hi ' . esc_html( $first ) . ',</p>';
		$h .= '<p style="' . $f . 'font-size:15px;line-height:1.6;margin:0 0 14px">Here is the record of the driver education classroom instruction completed at Sam’s Driving School, for the new school' . ( $out['school'] ? ' (' . esc_html( $out['school'] ) . ')' : '' ) . '. The documents on file are attached.</p>';
		if ( $msg ) { $h .= '<p style="' . $f . 'font-size:15px;line-height:1.6;margin:0 0 14px;padding:10px 14px;background:#f6f8f7;border-radius:10px">' . nl2br( esc_html( $msg ) ) . '</p>'; }
		$h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 14px">'
			. $tr( 'Student', trim( $r['first_name'] . ' ' . $r['last_name'] ) )
			. $tr( 'Date of birth', $r['dob'] ? wp_date( 'M j, Y', strtotime( $r['dob'] . ' 12:00:00' ) ) : '—' )
			. $tr( 'Course', $r['class_id'] ? SDSC_Schedule::class_name( (int) $r['class_id'] ) : '—' )
			. $tr( 'Classroom hours completed here', self::h( $here ) . ' of ' . self::HOURS . ' (' . (int) $att . ' of ' . (int) $tot . ' sessions)' . ( $f0 ? ' · ' . $d( $f0 ) . ' – ' . $d( $l0 ) : '' ) )
			. ( $in ? $tr( 'Completed at ' . ( $in['school'] ?: 'the previous school' ), self::h( $in['hours'] ) ) : '' )
			. $tr( 'Classroom hours still required', self::h( max( 0, self::HOURS - $here - (float) ( $in['hours'] ?? 0 ) ) ) )
			. ( $out['school'] ? $tr( 'Transferring to', $out['school'] . ( $out['on_date'] ? ' · ' . $d( $out['on_date'] ) : '' ) ) : '' )
			. '</table>';
		if ( $sess ) {
			$lbl = array( 'present' => 'Present', 'late' => 'Late', 'left' => 'Left early', 'absent' => 'Absent', '' => '—' );
			$h  .= '<div style="' . $f . 'font-size:13px;font-weight:700;color:#5b646d;margin:0 0 4px">Attendance</div><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px">';
			foreach ( $sess as $s ) { $h .= '<tr><td style="' . $f . 'font-size:13px;padding:3px 10px 3px 0;color:#5b646d">' . (int) $s['n'] . '</td><td style="' . $f . 'font-size:13px;padding:3px 10px 3px 0">' . esc_html( wp_date( 'D M j, Y', strtotime( $s['start'] ) ) ) . '</td><td style="' . $f . 'font-size:13px;padding:3px 0">' . esc_html( $lbl[ $s['status'] ] ?? ucfirst( $s['status'] ) ) . '</td></tr>'; }
			$h  .= '</table>';
		}
		$h .= '<p style="' . $f . 'font-size:13px;line-height:1.6;color:#5b646d;margin:0">Sam’s Driving School LLC · MVA-licensed driver education school · 751 Rockville Pike, Unit 9B, Rockville, MD 20852 · (301) 726-3030 · info@samsdrivingschool.org</p>';
		return $h;
	}

	public static function h_mail() {
		$rid = absint( $_POST['r'] ?? 0 ); // phpcs:ignore
		if ( ! self::can() || ! $rid || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'sdsc_transfer_' . $rid ) ) { wp_die( 'Not allowed.', 403 ); } // phpcs:ignore
		$r   = self::reg( $rid );
		$out = $r ? self::get( $rid, 'out' ) : null;
		if ( ! $r || ! $out ) { wp_die( 'Save the transfer-out record first.' ); }
		$to = array();
		foreach ( (array) ( $_POST['to'] ?? array() ) as $k ) { // phpcs:ignore
			$k = sanitize_key( $k );
			if ( in_array( $k, array( 'email', 'parent_email' ), true ) && is_email( (string) $r[ $k ] ) ) { $to[ $k ] = strtolower( trim( $r[ $k ] ) ); }
		}
		if ( ! $to ) { self::back( $rid, 'Choose at least one address to send to.' ); }
		// attachments: decrypt the chosen documents into a private temp folder for the length of the send
		$files = array(); $names = array();
		$dir   = get_temp_dir() . 'sdsc-tr-' . wp_generate_password( 12, false ) . '/';
		$ids   = array_values( array_filter( array_map( 'absint', (array) ( $_POST['docs'] ?? array() ) ) ) ); // phpcs:ignore
		if ( $ids && class_exists( 'SDSC_Docs' ) ) {
			wp_mkdir_p( $dir );
			foreach ( self::docs_of( $r['email'] ) as $d ) {
				if ( ! in_array( $d['id'], $ids, true ) ) { continue; }
				$bytes = SDSC_Docs::bytes( $d['id'] );
				if ( '' === $bytes ) { continue; }
				$ext  = SDSC_Docs::MIMES[ $d['mime'] ] ?? 'bin';
				$path = $dir . sanitize_file_name( preg_replace( '/[^A-Za-z0-9]+/', '-', strtolower( $r['last_name'] . '-' . $r['first_name'] . '-' . preg_replace( '/ · .*$/', '', $d['label'] ) ) ) . '-' . $d['id'] . '.' . $ext );
				if ( false !== file_put_contents( $path, $bytes ) ) { $files[] = $path; $names[] = preg_replace( '/ · .*$/', '', $d['label'] ); } // phpcs:ignore
			}
		}
		$msg  = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['msg'] ?? '' ) ), 0, 2000 ); // phpcs:ignore
		$subj = 'Driver education transfer record — ' . trim( $r['first_name'] . ' ' . $r['last_name'] );
		$sent = array();
		foreach ( $to as $k => $addr ) {
			$first = 'email' === $k ? $r['first_name'] : ( $r['parent_name'] ? preg_replace( '/\s.*$/', '', trim( $r['parent_name'] ) ) : $r['first_name'] );
			$ok    = wp_mail( $addr, $subj, self::mail_html( $r, $first, $msg ), array( 'Content-Type: text/html; charset=UTF-8', 'X-SDSC-From: info' ), $files );
			if ( $ok ) { $sent[] = $addr; }
		}
		foreach ( $files as $f ) { @unlink( $f ); } // phpcs:ignore
		if ( $files ) { @rmdir( $dir ); } // phpcs:ignore
		if ( $sent ) {
			global $wpdb;
			$line = wp_date( 'M j, Y g:i a' ) . ' by ' . wp_get_current_user()->display_name . ' to ' . implode( ', ', $sent ) . ( $names ? ' (' . count( $names ) . ' file' . ( 1 === count( $names ) ? '' : 's' ) . ': ' . implode( ', ', $names ) . ')' : ' (no attachments)' );
			$wpdb->update( self::table(), array( 'sent_log' => trim( (string) $out['sent_log'] . "\n" . $line ) ), array( 'id' => (int) $out['id'] ) );
			foreach ( $ids as $id ) { SDSC_Docs::note_sent( $id ); }
			self::back( $rid, 'Transfer record sent to ' . implode( ' and ', $sent ) . '.' );
		}
		self::back( $rid, 'The e-mail could not be sent. Check the mail settings and try again.' );
	}
}
