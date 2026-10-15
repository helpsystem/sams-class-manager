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

	public static function settings() {
		$o = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array(
			'school' => 'Sam’s Driving School',
			'branch' => '751 Rockville Pike, Unit 9B, Rockville, MD 20852',
			'number' => '',
			'rep_id' => '',
			'hours'  => '3',
		) );
	}

	public static function h_save() {
		if ( ! class_exists( 'SDSC_Reports' ) || ! SDSC_Reports::is_boss() ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_de003_save' );
		$in  = wp_unslash( $_POST ); // phpcs:ignore -- sanitised below
		$out = array();
		foreach ( array( 'school', 'branch', 'number', 'rep_id', 'hours' ) as $k ) { $out[ $k ] = sanitize_text_field( mb_substr( (string) ( $in[ $k ] ?? '' ), 0, 120 ) ); }
		update_option( self::OPT, $out, false );
		wp_safe_redirect( add_query_arg( 'srp_msg', rawurlencode( 'MVA form details saved.' ), wp_get_referer() ? remove_query_arg( 'srp_msg', wp_get_referer() ) : SDSC_Reports::url() ) );
		exit;
	}

	/** Small settings form for the Reports screen (administrator and school manager). */
	public static function settings_form() {
		$s = self::settings();
		$f = function ( $k, $l, $hint = '' ) use ( $s ) { return '<label>' . esc_html( $l ) . ( $hint ? ' <small>' . esc_html( $hint ) . '</small>' : '' ) . '<input name="' . esc_attr( $k ) . '" value="' . esc_attr( $s[ $k ] ) . '"></label>'; };
		return '<details class="srp-access"' . ( $s['number'] && $s['rep_id'] ? '' : ' open' ) . '><summary>MVA form DE-003 — school details' . ( $s['number'] && $s['rep_id'] ? '' : ' (please complete)' ) . '</summary>'
			. '<p class="srp-m">Printed on every Student Record and Completion Form. The school number and the State ID come from your MVA certification.</p>'
			. '<form class="srp-de3" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'sdsc_de003_save', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_de003_save">'
			. $f( 'school', 'Name of driving school' ) . $f( 'branch', 'Branch location' ) . $f( 'number', 'School number', '(MVA)' ) . $f( 'rep_id', 'Maryland State ID of the authorized school official' ) . $f( 'hours', 'Hours per classroom session', '(30 hours ÷ number of sessions)' )
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
		// School.
		$f['Name of Driving School'] = $set['school'];
		$f['Branch Location']        = $set['branch'];
		$f['School Number']          = $set['number'];
		$f['Maryland State ID of Authorized School Rep'] = $set['rep_id'];

		// Classroom: the sessions the student attended.
		$units = array_values( array_filter( $quizzes, function ( $q ) { return ! $q[3]; } ) );
		$final = array_values( array_filter( $quizzes, function ( $q ) { return $q[3]; } ) );
		$mine  = $p['user_id'] ? ( $res[ $p['user_id'] ] ?? array() ) : array();
		$pct   = function ( $q ) use ( $mine ) { return isset( $mine[ $q[0] ] ) ? rtrim( rtrim( number_format( $mine[ $q[0] ]['pct'], 1 ), '0' ), '.' ) . '%' : ''; };
		if ( $cid && class_exists( 'SDSC_ZoomRoll' ) ) {
			$list = SDSC_Schedule::sessions( $cid );
			$att  = SDSC_ZoomRoll::rows( $cid )[ $p['email'] ] ?? array();
			$over = SDSC_ZoomRoll::session_instructors( $cid );
			$main = SDSC_ZoomRoll::instructor_of( $cid );
			$row  = 0;
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
				$f[ 'Unit ' . $row ]            = $n <= count( $units ) ? (string) $n : 'Final';
				$f[ 'Instructor Initials ' . $row ]  = $ins['ini'];
				$f[ 'Instructor ID Number ' . $row ] = $ins['no'];
				$f[ 'Unit Test Score ' . $row ]      = $n <= count( $units ) ? $pct( $units[ $n - 1 ] ) : ( $final ? $pct( $final[0] ) : '' );
			}
		}
		if ( $final ) { $f['Unit 11'] = $pct( $final[0] ); } // "Final Score" on the first page

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
