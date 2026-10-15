<?php
/**
 * MVA form DE-003 (09-25) "Student Record and Completion Form" — the official fillable PDF from the Maryland
 * MVA (https://mva.maryland.gov/media/194), filled in the browser with each student's data and printed or
 * saved as a PDF. The form itself is never changed; only its own fields are filled.
 *
 *  - Student: full name, date of birth, age when the class started, phone, address.
 *  - School: name, branch, MVA school number and the Maryland State ID of the authorized school official
 *    (entered once on the Reports screen).
 *  - Classroom: every session the student attended (date, start, end, hours, unit, instructor initials and
 *    MVA instructor number, the unit's test score) and the final exam score.
 *  - Behind the wheel: completed lessons (date, start, end, hours, lesson, tag number of the car,
 *    instructor initials and number, grade S/U from the instructor's evaluation).
 *  - Signatures and "electronically submitted" stay empty for the school official and the student.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_DE003 {
	const OPT  = 'sdsc_de003';
	const URL  = 'https://mva.maryland.gov/media/194';
	const NAME = 'Student Record and Completion Form (MVA DE-003)';

	public static function init() {
		add_action( 'wp_ajax_sdsc_de003_pdf', array( __CLASS__, 'ajax_pdf' ) );
		add_action( 'admin_post_sdsc_de003_save', array( __CLASS__, 'h_save' ) );
	}

	/** Defaults from the school's MVA certificate (Certified Driver's Education School, DE10008819, expires 08/06/2027). */
	public static function defaults() {
		return array(
			'school'    => 'Sam Driving School',
			'branch'    => '751 Rockville Pike Ste 9B, Rockville MD 20852',
			'number'    => 'DE10008819',
			'rep_user'  => '',
			'rep_id'    => '',
			'hours'     => '3',
			'cls_start' => '',
			'cls_end'   => '',
			'cls_ins'   => '',
		);
	}

	/** Saved values; an empty box falls back to the default (so nothing on the form stays blank by mistake). */
	public static function settings() {
		$o = get_option( self::OPT, array() );
		$o = array_filter( is_array( $o ) ? $o : array(), function ( $v ) { return '' !== (string) $v; } );
		return wp_parse_args( $o, self::defaults() );
	}

	/** People who may sign as the authorized school official: administrator and school manager. */
	public static function officials() {
		$out = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator', class_exists( 'SDSC_Access' ) ? SDSC_Access::MANAGER : 'sdsc_manager' ), 'fields' => array( 'ID', 'display_name', 'user_email' ) ) ) as $u ) { $out[ (int) $u->ID ] = $u; }
		return $out;
	}

	/** Maryland State ID of the authorized official: typed in, else from their own scanned license (Student documents), else their DriveFlow profile. */
	public static function rep_id( $set = null ) {
		$set = $set ? $set : self::settings();
		if ( '' !== $set['rep_id'] ) { return array( $set['rep_id'], 'typed in' ); }
		$uid = (int) $set['rep_user'];
		$u   = $uid ? get_userdata( $uid ) : null;
		if ( ! $u ) { return array( '', '' ); }
		if ( class_exists( 'SDSC_IDScan' ) ) {
			$c = SDSC_IDScan::get( $u->user_email );
			if ( ! empty( $c['fields']['number'] ) ) { return array( $c['fields']['number'], 'from the license scanned in Student documents' ); }
		}
		$i = self::instructor( $uid );
		return '' !== $i['no'] ? array( $i['no'], 'from the instructor profile' ) : array( '', '' );
	}

	/** County of a ZIP code (Maryland, DC, Virginia). */
	public static function county( $zip ) {
		static $map = null;
		$zip = substr( preg_replace( '/\D/', '', (string) $zip ), 0, 5 );
		if ( 5 !== strlen( $zip ) ) { return ''; }
		if ( null === $map ) {
			$map = array();
			$f   = __DIR__ . '/data-zip-county.php';
			foreach ( ( is_readable( $f ) ? (array) include $f : array() ) as $county => $zips ) { foreach ( explode( ' ', $zips ) as $z ) { $map[ $z ] = $county; } }
		}
		return $map[ $zip ] ?? '';
	}

	public static function h_save() {
		if ( ! class_exists( 'SDSC_Reports' ) || ! SDSC_Reports::is_boss() ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_de003_save' );
		$in  = wp_unslash( $_POST ); // phpcs:ignore -- sanitised below
		$out = array();
		foreach ( array_keys( self::defaults() ) as $k ) { $out[ $k ] = sanitize_text_field( mb_substr( (string) ( $in[ $k ] ?? '' ), 0, 120 ) ); }
		update_option( self::OPT, $out, false );
		wp_safe_redirect( add_query_arg( 'srp_msg', rawurlencode( 'MVA form details saved.' ), wp_get_referer() ? remove_query_arg( 'srp_msg', wp_get_referer() ) : SDSC_Reports::url() ) );
		exit;
	}

	/** Small settings form for the Reports screen (administrator and school manager). */
	public static function settings_form() {
		$s   = self::settings();
		$d   = self::defaults();
		$raw = get_option( self::OPT, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$f   = function ( $k, $l, $hint = '', $ph = '' ) use ( $raw ) { return '<label>' . esc_html( $l ) . ( $hint ? ' <small>' . esc_html( $hint ) . '</small>' : '' ) . '<input name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) ( $raw[ $k ] ?? '' ) ) . '" placeholder="' . esc_attr( $ph ) . '"></label>'; };
		$sel = function ( $k, $l, $opts, $hint = '' ) use ( $raw ) {
			$h = '<label>' . esc_html( $l ) . ( $hint ? ' <small>' . esc_html( $hint ) . '</small>' : '' ) . '<select name="' . esc_attr( $k ) . '"><option value="">— choose —</option>';
			foreach ( $opts as $v => $t ) { $h .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) ( $raw[ $k ] ?? '' ), (string) $v, false ) . '>' . esc_html( $t ) . '</option>'; }
			return $h . '</select></label>';
		};
		$off = array();
		foreach ( self::officials() as $id => $u ) { $off[ $id ] = $u->display_name; }
		$ins = array();
		global $wpdb;
		foreach ( (array) $wpdb->get_results( "SELECT name, license_number FROM {$wpdb->prefix}driveflow_instructors WHERE name NOT LIKE 'Sample%' ORDER BY name", ARRAY_A ) as $r ) { $ins[ $r['name'] ] = $r['name'] . ( $r['license_number'] ? ' · ' . $r['license_number'] : ' · no MVA number yet' ); } // phpcs:ignore
		list( $rid, $how ) = self::rep_id( $s );
		$ok  = '' !== $rid && '' !== $s['cls_start'] && '' !== $s['cls_ins'];
		return '<details class="srp-access"' . ( $ok ? '' : ' open' ) . '><summary>MVA form DE-003 — school details' . ( $ok ? '' : ' (please complete)' ) . '</summary>'
			. '<p class="srp-m">Filled on every Student Record and Completion Form. A box left empty uses the value in grey (from the school’s MVA certificate). County comes from the student’s ZIP code.</p>'
			. '<form class="srp-de3" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'sdsc_de003_save', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_de003_save">'
			. $f( 'school', 'Name of driving school', '', $d['school'] ) . $f( 'branch', 'Branch location', '', $d['branch'] ) . $f( 'number', 'School number', '(MVA certificate)', $d['number'] )
			. $sel( 'rep_user', 'Authorized school official', $off, '(signs the forms)' )
			. $f( 'rep_id', 'Their Maryland State ID', $rid && 'typed in' !== $how ? '(now: ' . $rid . ', ' . $how . ')' : '(or scan their license in Student documents)', $rid )
			. $f( 'hours', 'Hours per classroom session', '(30 hours ÷ sessions)', $d['hours'] )
			. '<p class="srp-m" style="margin-top:6px"><b>Older classes</b> (before the new class system): the unit rows are filled from each student’s unit tests — the date of the test is the class day. Their usual class time and Zoom instructor:</p>'
			. $f( 'cls_start', 'Class start time', '(e.g. 5:30 PM)' ) . $f( 'cls_end', 'Class end time', '(e.g. 8:30 PM)' ) . $sel( 'cls_ins', 'Zoom instructor', $ins )
			. '<p><button class="srp-btn">Save</button></p></form></details>';
	}

	/* ------------------------------------------------------------ the official PDF */

	protected static function file() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . 'sdsc-forms/DE-003.pdf';
	}

	/** The official form, downloaded from the MVA once and kept on the server. */
	public static function bytes() {
		$f = self::file();
		if ( is_readable( $f ) && filesize( $f ) > 10000 && ( time() - filemtime( $f ) ) < 90 * DAY_IN_SECONDS ) { return (string) file_get_contents( $f ); } // phpcs:ignore
		$r = wp_remote_get( self::URL, array( 'timeout' => 30, 'redirection' => 5 ) );
		$b = is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
		if ( 0 === strpos( $b, '%PDF' ) && false !== strpos( $b, 'AcroForm' ) ) {
			wp_mkdir_p( dirname( $f ) );
			@file_put_contents( dirname( $f ) . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore
			file_put_contents( $f, $b ); // phpcs:ignore
			return $b;
		}
		return is_readable( $f ) ? (string) file_get_contents( $f ) : ''; // an older copy is better than none // phpcs:ignore
	}

	public static function ajax_pdf() {
		if ( ! is_user_logged_in() || ! class_exists( 'SDSC_Reports' ) || ! SDSC_Reports::can( 'file' ) || ! check_ajax_referer( 'sdsc_de003', '_n', false ) ) { status_header( 403 ); exit( 'Not allowed.' ); }
		$b = self::bytes();
		if ( '' === $b ) { status_header( 502 ); exit( 'The MVA form could not be downloaded. Please try again later.' ); }
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $b ) );
		echo $b; // phpcs:ignore -- PDF bytes
		exit;
	}

	/* ------------------------------------------------------------ data */

	protected static function initials( $name ) {
		$p = preg_split( '/\s+/', trim( (string) $name ) );
		$i = '';
		foreach ( $p as $w ) { if ( '' !== $w ) { $i .= mb_strtoupper( mb_substr( $w, 0, 1 ) ); } }
		return mb_substr( $i, 0, 3 );
	}

	/** DriveFlow instructor (name, MVA number) for a site user id or a name. */
	protected static function instructor( $uid = 0, $name = '' ) {
		global $wpdb;
		static $c = array();
		$k = $uid . '|' . strtolower( $name );
		if ( isset( $c[ $k ] ) ) { return $c[ $k ]; }
		$t = $wpdb->prefix . 'driveflow_instructors';
		$r = null;
		if ( $uid ) {
			$u = get_userdata( $uid );
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT name, license_number FROM {$t} WHERE user_id = %d OR email = %s ORDER BY user_id = %d DESC LIMIT 1", $uid, $u ? $u->user_email : '-', $uid ), ARRAY_A ); // phpcs:ignore
			if ( ! $r && $u ) { $r = array( 'name' => $u->display_name, 'license_number' => (string) get_user_meta( $uid, 'sdsc_mva_no', true ) ); }
		} elseif ( '' !== $name ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT name, license_number FROM {$t} WHERE name = %s LIMIT 1", $name ), ARRAY_A ); // phpcs:ignore
			if ( ! $r ) { $r = array( 'name' => $name, 'license_number' => '' ); }
		}
		$c[ $k ] = $r ? array( 'ini' => self::initials( $r['name'] ), 'no' => (string) $r['license_number'], 'name' => $r['name'] ) : array( 'ini' => '', 'no' => '', 'name' => '' );
		return $c[ $k ];
	}

	protected static function ymd( $s ) { return $s ? wp_date( 'm/d/Y', strtotime( substr( $s, 0, 10 ) . ' 12:00:00' ) ) : ''; }
	protected static function hm( $local ) { return $local ? wp_date( 'g:i A', SDSC_Lessons::ts( $local ) ) : ''; }

	/** Field name → value for one student (see the class comment). $quizzes/$res as in SDSC_Student_File. */
	public static function fields( $p, $quizzes, $res ) {
		global $wpdb;
		$set = self::settings();
		$r   = $p['reg'];
		$f   = array();
		$cid = (int) $p['class'];
		$start = $cid ? substr( (string) get_post_meta( $cid, '_sdsc_start', true ), 0, 10 ) : '';

		// Student.
		$f['Full Name'] = $r ? trim( $r['first_name'] . ' ' . $r['middle_name'] . ' ' . $r['last_name'] ) : $p['name'];
		$f['Full Name'] = preg_replace( '/\s+/', ' ', $f['Full Name'] );
		if ( $r && $r['dob'] ) {
			$f['Date of Birth'] = self::ymd( $r['dob'] );
			if ( $start ) { $f['Age'] = (string) ( new DateTime( $r['dob'] ) )->diff( new DateTime( $start ) )->y; }
			elseif ( $r['age'] ) { $f['Age'] = (string) (int) $r['age']; }
		}
		$f['Phone Number']   = $r ? $r['phone'] : $p['phone'];
		$f['Street Address'] = $r ? trim( $r['street'] . ( $r['apt'] ? ', ' . $r['apt'] : '' ) ) : '';
		$f['City']           = $r ? $r['city'] : '';
		$f['State']          = $r ? ( 'Maryland' === $r['state'] ? 'MD' : $r['state'] ) : '';
		$f['Zip Code']       = $r ? $r['zip'] : '';
		$f['County']         = $r ? self::county( $r['zip'] ) : '';
		// School.
		$f['Name of Driving School'] = $set['school'];
		$f['Branch Location']        = $set['branch'];
		$f['School Number']          = $set['number'];
		$f['Maryland State ID of Authorized School Rep'] = self::rep_id( $set )[0];

		// Classroom: the sessions the student attended.
		$row = 0;
		$units = array_values( array_filter( $quizzes, function ( $q ) { return ! $q[3]; } ) );
		$final = array_values( array_filter( $quizzes, function ( $q ) { return $q[3]; } ) );
		$mine  = $p['user_id'] ? ( $res[ $p['user_id'] ] ?? array() ) : array();
		$pct   = function ( $q ) use ( $mine ) { return isset( $mine[ $q[0] ] ) ? rtrim( rtrim( number_format( $mine[ $q[0] ]['pct'], 1 ), '0' ), '.' ) . '%' : ''; };
		if ( $cid && class_exists( 'SDSC_ZoomRoll' ) ) {
			$list = SDSC_Schedule::sessions( $cid );
			$att  = SDSC_ZoomRoll::rows( $cid )[ $p['email'] ] ?? array();
			$over = SDSC_ZoomRoll::session_instructors( $cid );
			$main = SDSC_ZoomRoll::instructor_of( $cid );
			$row  = 0; // also read after the loop
			foreach ( $list as $x ) {
				$v = (string) ( $att[ $x['date'] ]['actual'] ?? '' );
				if ( ! in_array( $v, array( 'present', 'late', 'left' ), true ) ) { continue; }
				$row++;
				if ( $row > 35 ) { break; }
				$ins = self::instructor( (int) ( $over[ $x['date'] ] ?? $main ) );
				$n   = (int) $x['n'];
				$f[ 'Unit Date ' . $row ]       = self::ymd( $x['date'] );
				$f[ 'Unit Start Time ' . $row ] = self::hm( $x['start'] );
				$f[ 'Unit End Time ' . $row ]   = self::hm( $x['end'] );
				$f[ 'Hours ' . $row ]           = $set['hours'];
				// The form's own field names: on page 1 the unit column of row r is "Unit r" (r = 1–10, "Unit 11" is
				// the final score); on the continued page it is "Unit r+1" (rows 11–35 → "Unit 12"–"Unit 36").
				$f[ 'Unit ' . ( $row <= 10 ? $row : $row + 1 ) ] = $n <= count( $units ) ? (string) $n : 'Final';
				$f[ 'Instructor Initials ' . $row ]  = $ins['ini'];
				$f[ 'Instructor ID Number ' . $row ] = $ins['no'];
				$f[ 'Unit Test Score ' . $row ]      = $n <= count( $units ) ? $pct( $units[ $n - 1 ] ) : ( $final ? $pct( $final[0] ) : '' );
			}
		}
		// Older classes (no attendance in the new class system): one row per unit test taken — the test is
		// taken at the end of that day's class, so its date is the class day.
		if ( ! $row && $mine ) {
			$ins = '' !== $set['cls_ins'] ? self::instructor( 0, $set['cls_ins'] ) : array( 'ini' => '', 'no' => '' );
			foreach ( array_merge( $units, $final ) as $k => $q ) {
				$day = $q[3] ? ( $mine[ $q[0] ]['ended'] ?? '' ) : ( $mine[ $q[0] ]['first'] ?? ( $mine[ $q[0] ]['ended'] ?? '' ) ); // final: the attempt that counts
				if ( ! $day ) { continue; }
				$row++;
				if ( $row > 35 ) { break; }
				$f[ 'Unit Date ' . $row ]       = self::ymd( $day );
				$f[ 'Unit Start Time ' . $row ] = $set['cls_start'];
				$f[ 'Unit End Time ' . $row ]   = $set['cls_end'];
				$f[ 'Hours ' . $row ]           = $set['hours'];
				$f[ 'Unit ' . ( $row <= 10 ? $row : $row + 1 ) ] = $q[3] ? 'Final' : (string) ( $k + 1 );
				$f[ 'Instructor Initials ' . $row ]  = $ins['ini'];
				$f[ 'Instructor ID Number ' . $row ] = $ins['no'];
				$f[ 'Unit Test Score ' . $row ]      = $pct( $q );
			}
		}
		if ( $final ) {
			$f['Unit 11'] = $pct( $final[0] );                                                // "Final Score", page 1
			if ( ! empty( $row ) && $row > 10 ) { $f['Unit 37'] = $f['Unit 11']; }            // "Final Score", continued page
		}

		// Behind the wheel: completed lessons.
		$T   = $wpdb->prefix . 'driveflow_sessions';
		$ids = array();
		if ( $p['email'] ) { $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}driveflow_students WHERE LOWER(email) = %s", $p['email'] ) ) ); } // phpcs:ignore
		$where = $ids ? 'student_id IN (' . implode( ',', $ids ) . ')' : '1=0';
		$less  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$T} WHERE status = 'completed' AND ( {$where} OR LOWER(student_name) = %s ) ORDER BY scheduled_start LIMIT 6", strtolower( $p['name'] ) ), ARRAY_A ); // phpcs:ignore
		$i = 0;
		foreach ( $less as $l ) {
			$i++;
			$fd  = json_decode( (string) $l['form_data'], true );
			$ev  = is_array( $fd ) ? (string) ( $fd['final_evaluation'] ?? '' ) : '';
			$ins = self::instructor( 0, $l['instructor_name'] );
			$min = max( 0, ( SDSC_Lessons::ts( $l['scheduled_end'] ) - SDSC_Lessons::ts( $l['scheduled_start'] ) ) / 60 );
			$f[ 'Lesson Date ' . $i ]       = self::ymd( $l['scheduled_start'] );
			$f[ 'Lesson Start Time ' . $i ] = self::hm( $l['scheduled_start'] );
			$f[ 'Lesson End Time ' . $i ]   = self::hm( $l['scheduled_end'] );
			$f[ 'Lesson Hours ' . $i ]      = rtrim( rtrim( number_format( $min / 60, 2 ), '0' ), '.' );
			$f[ 'Lesson ' . $i ]            = (string) ( (int) $l['session_number'] ? (int) $l['session_number'] : $i );
			$f[ 'Tag Number ' . $i ]        = $l['plate_number'];
			$f[ 'Lesson Instructor Initials ' . $i ]  = $ins['ini'];
			$f[ 'Lesson Instructor ID Number ' . $i ] = $ins['no'];
			$f[ 'Lesson Grade ' . $i ]      = 0 === strcasecmp( $ev, 'Pass' ) ? 'S' : ( '' !== $ev ? 'U' : '' );
		}
		// Page 3 repeats the student and school details.
		return array_filter( $f, function ( $v ) { return '' !== (string) $v; } );
	}
}
