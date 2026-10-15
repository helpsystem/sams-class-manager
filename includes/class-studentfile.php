<?php
/**
 * Student files for Reports: exam results (Tutor LMS quizzes), the test answer sheet, the registration
 * form with the policies and signatures, and class attendance — gathered per student and printed on
 * US Letter pages with a running header, footer and "Page X of Y".
 *
 * Accuracy rules:
 *  - Quizzes are read in the order of the course curriculum (topic order, then quiz order), never by
 *    "the n-th quiz the student happened to take".
 *  - For every quiz the best attempt (highest score; the latest one on a tie) is shown, and the answers
 *    shown are from that same attempt. Questions are numbered as in the quiz (question order).
 *  - Letters A/B/C/D follow the order of the options in the quiz. A wrong answer shows the student's
 *    letter and the correct letter taken from the question itself.
 *  - The registration form is matched by a link the office set by hand, else by e-mail.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Student_File {
	const LINKS = 'sdsc_reg_links'; // e-mail => registration id, set by hand
	const PASS  = 80;

	public static function init() {
		add_action( 'admin_post_sdsc_reg_link', array( __CLASS__, 'h_link' ) );
		add_action( 'admin_init', array( __CLASS__, 'retire_old_page' ) );
	}

	/**
	 * The old "Student Records & Reports" screen (wp-admin) printed every wrong answer as "correct: A" and
	 * every unit quiz as 100%. It now opens the Student files report; ?legacy=1 still shows the old screen.
	 */
	public static function retire_old_page() {
		if ( 'sdsc-reports' !== ( $_GET['page'] ?? '' ) || ! empty( $_GET['legacy'] ) || wp_doing_ajax() || ! class_exists( 'SDSC_Reports' ) ) { return; } // phpcs:ignore
		$url = SDSC_Reports::url( array( 'r' => 'file' ) );
		if ( ! empty( $_GET['sdsc_embed'] ) ) { // phpcs:ignore -- inside the dashboard frame: open in the whole window
			echo '<!doctype html><script>window.top.location.href=' . wp_json_encode( $url ) . ';</script>';
			exit;
		}
		wp_safe_redirect( $url );
		exit;
	}

	/* ------------------------------------------------------------ courses and quizzes */

	/** Tutor LMS courses that contain quizzes: [id => title]. */
	public static function courses() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT DISTINCT c.ID, c.post_title FROM {$wpdb->posts} c JOIN {$wpdb->posts} t ON t.post_parent = c.ID AND t.post_type = 'topics' JOIN {$wpdb->posts} q ON q.post_parent = t.ID AND q.post_type = 'tutor_quiz' WHERE c.post_type = 'courses' AND c.post_status = 'publish' ORDER BY c.post_title", ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) { $out[ (int) $r['ID'] ] = $r['post_title']; }
		return $out;
	}

	public static function default_course() {
		$c = self::courses();
		return $c ? (int) array_key_first( $c ) : 0;
	}

	/** Quizzes of a course in curriculum order: [ [id, title, questions, is_final], … ] */
	public static function quizzes( $course ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT q.ID, q.post_title, (SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_questions x WHERE x.quiz_id = q.ID) n FROM {$wpdb->posts} t JOIN {$wpdb->posts} q ON q.post_parent = t.ID AND q.post_type = 'tutor_quiz' AND q.post_status = 'publish' WHERE t.post_parent = %d AND t.post_type = 'topics' ORDER BY t.menu_order, t.ID, q.menu_order, q.ID", $course ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		$last = count( $rows ) - 1;
		foreach ( $rows as $i => $r ) {
			$final = (bool) preg_match( '/final/i', $r['post_title'] ) || ( $i === $last && (int) $r['n'] > 10 );
			$out[] = array( (int) $r['ID'], $r['post_title'], (int) $r['n'], $final );
		}
		return $out;
	}

	/* ------------------------------------------------------------ people */

	protected static function links() { $l = get_option( self::LINKS, array() ); return is_array( $l ) ? $l : array(); }

	/**
	 * The students of a report: [email => [email, name, user_id, reg (row or null), class_id]].
	 * $a: class, from, to, who, course.
	 */
	public static function people( $a, $limit = 800 ) {
		global $wpdb;
		$reg_t = class_exists( 'SDSC_Registration' ) ? SDSC_Registration::table() : '';
		$out   = array();
		$add   = function ( $email, $name = '' ) use ( &$out ) {
			$e = strtolower( trim( (string) $email ) );
			if ( ! is_email( $e ) ) { return; }
			if ( ! isset( $out[ $e ] ) ) { $out[ $e ] = array( 'email' => $e, 'name' => trim( (string) $name ) ); }
			elseif ( '' === $out[ $e ]['name'] ) { $out[ $e ]['name'] = trim( (string) $name ); }
		};
		$demo = ( class_exists( 'SDSC_Preview' ) && SDSC_Preview::is_preview() ) ? '' : " AND email NOT LIKE '%%@preview.samsdrivingschool.invalid'";
		if ( ! empty( $a['class'] ) ) {
			foreach ( SDSC_Schedule::roster( (int) $a['class'] ) as $p ) { $add( $p['email'], $p['first'] . ' ' . $p['last'] ); }
		} else {
			$from = $a['from'] . ' 00:00:00';
			$to   = $a['to'] . ' 23:59:59';
			if ( $reg_t ) {
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT email, first_name, last_name FROM $reg_t WHERE source <> 'demo' AND created_at BETWEEN %s AND %s" . $demo . ' ORDER BY created_at', $from, $to ), ARRAY_A ) as $r ) { $add( $r['email'], $r['first_name'] . ' ' . $r['last_name'] ); } // phpcs:ignore
			}
			if ( ! empty( $a['course'] ) ) {
				// Students enrolled in the online course, or who finished a quiz in it, in the same dates.
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT u.user_email, u.display_name FROM {$wpdb->users} u WHERE u.ID IN ( SELECT post_author FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'tutor_enrolled' AND post_date BETWEEN %s AND %s UNION SELECT user_id FROM {$wpdb->prefix}tutor_quiz_attempts WHERE course_id = %d AND attempt_status = 'attempt_ended' AND attempt_ended_at BETWEEN %s AND %s )", (int) $a['course'], $from, $to, (int) $a['course'], $from, $to ), ARRAY_A ); // phpcs:ignore
				foreach ( $rows as $r ) { $add( $r['user_email'], $r['display_name'] ); }
			}
		}
		// Registration, account and class for each.
		$links = self::links();
		foreach ( $out as $e => &$p ) {
			$reg = null;
			if ( $reg_t ) {
				if ( ! empty( $links[ $e ] ) ) { $reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $reg_t WHERE id = %d", (int) $links[ $e ] ), ARRAY_A ); } // phpcs:ignore
				if ( ! $reg ) {
					$reg = ! empty( $a['class'] )
						? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $reg_t WHERE email = %s AND class_id = %d ORDER BY id DESC LIMIT 1", $e, (int) $a['class'] ), ARRAY_A ) // phpcs:ignore
						: null;
					if ( ! $reg ) { $reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $reg_t WHERE email = %s AND source <> 'demo' ORDER BY (student_sig IS NOT NULL) DESC, id DESC LIMIT 1", $e ), ARRAY_A ); } // phpcs:ignore
				}
			}
			$u            = get_user_by( 'email', $e );
			$p['user_id'] = $u ? (int) $u->ID : 0;
			$p['reg']     = $reg ? $reg : null;
			$p['class']   = ! empty( $a['class'] ) ? (int) $a['class'] : ( $reg ? (int) $reg['class_id'] : 0 );
			if ( $reg ) { $p['name'] = trim( $reg['first_name'] . ' ' . ( $reg['middle_name'] ? $reg['middle_name'] . ' ' : '' ) . $reg['last_name'] ); }
			if ( '' === $p['name'] && $u ) { $p['name'] = $u->display_name; }
			$p['phone'] = $reg ? $reg['phone'] : '';
		}
		unset( $p );
		if ( '' !== (string) ( $a['who'] ?? '' ) ) {
			$q   = strtolower( $a['who'] );
			$out = array_filter( $out, function ( $p ) use ( $q ) { return false !== strpos( strtolower( $p['name'] . ' ' . $p['email'] . ' ' . preg_replace( '/\D/', '', $p['phone'] ) ), $q ) || ( preg_replace( '/\D/', '', $q ) && false !== strpos( preg_replace( '/\D/', '', $p['phone'] ), preg_replace( '/\D/', '', $q ) ) ); } );
		}
		uasort( $out, function ( $x, $y ) { $lx = self::last( $x['name'] ); $ly = self::last( $y['name'] ); return strcasecmp( $lx . $x['name'], $ly . $y['name'] ); } );
		return array_slice( $out, 0, $limit, true );
	}

	protected static function last( $n ) { $p = preg_split( '/\s+/', trim( (string) $n ) ); return (string) end( $p ); }

	/** People built from registration ids (for the "Registration forms" printout). */
	public static function from_regs( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids ) { return array(); }
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . SDSC_Registration::table() . ' WHERE id IN (' . implode( ',', $ids ) . ') ORDER BY created_at, id', ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$e     = strtolower( trim( $r['email'] ) );
			$u     = is_email( $e ) ? get_user_by( 'email', $e ) : false;
			$out[] = array( 'email' => $e, 'name' => trim( $r['first_name'] . ' ' . ( $r['middle_name'] ? $r['middle_name'] . ' ' : '' ) . $r['last_name'] ), 'user_id' => $u ? (int) $u->ID : 0, 'reg' => $r, 'class' => (int) $r['class_id'], 'phone' => $r['phone'] );
		}
		return $out;
	}

	/* ------------------------------------------------------------ results */

	/**
	 * Best attempt per quiz for these users: [user][quiz] => [pct, earned, total, ended, attempts, attempt_id].
	 */
	public static function results( $course, $user_ids ) {
		global $wpdb;
		$user_ids = array_values( array_filter( array_map( 'intval', (array) $user_ids ) ) );
		if ( ! $course || ! $user_ids ) { return array(); }
		$out = array();
		foreach ( array_chunk( $user_ids, 300 ) as $chunk ) {
			$in   = implode( ',', $chunk );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT attempt_id, user_id, quiz_id, earned_marks, total_marks, attempt_ended_at FROM {$wpdb->prefix}tutor_quiz_attempts WHERE course_id = %d AND attempt_status = 'attempt_ended' AND user_id IN ($in) ORDER BY attempt_id", $course ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $r ) {
				$pct = (float) $r['total_marks'] > 0 ? round( (float) $r['earned_marks'] / (float) $r['total_marks'] * 100, 1 ) : 0.0;
				$cur = $out[ $r['user_id'] ][ $r['quiz_id'] ] ?? null;
				$n   = ( $cur['attempts'] ?? 0 ) + 1;
				if ( ! $cur || $pct >= $cur['pct'] ) {
					$out[ $r['user_id'] ][ $r['quiz_id'] ] = array( 'pct' => $pct, 'earned' => (float) $r['earned_marks'], 'total' => (float) $r['total_marks'], 'ended' => $r['attempt_ended_at'], 'attempts' => $n, 'attempt_id' => (int) $r['attempt_id'], 'first' => $cur['first'] ?? $r['attempt_ended_at'] );
				} else {
					$out[ $r['user_id'] ][ $r['quiz_id'] ]['attempts'] = $n;
				}
			}
		}
		return $out;
	}

	/** Answers of the given attempts: [attempt_id][question_id] => [given letters, correct letters, ok]. */
	public static function answers( $attempt_ids ) {
		global $wpdb;
		$attempt_ids = array_values( array_filter( array_map( 'intval', (array) $attempt_ids ) ) );
		if ( ! $attempt_ids ) { return array(); }
		$rows = array();
		foreach ( array_chunk( $attempt_ids, 400 ) as $chunk ) {
			$rows = array_merge( $rows, (array) $wpdb->get_results( "SELECT quiz_attempt_id, question_id, given_answer, is_correct FROM {$wpdb->prefix}tutor_quiz_attempt_answers WHERE quiz_attempt_id IN (" . implode( ',', $chunk ) . ')', ARRAY_A ) ); // phpcs:ignore
		}
		$qids    = array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'question_id' ) ) ) );
		$letters = array();
		$correct = array();
		if ( $qids ) {
			$opts = (array) $wpdb->get_results( "SELECT answer_id, belongs_question_id, is_correct FROM {$wpdb->prefix}tutor_quiz_question_answers WHERE belongs_question_id IN (" . implode( ',', $qids ) . ') ORDER BY belongs_question_id, answer_order, answer_id', ARRAY_A ); // phpcs:ignore
			$i    = array();
			foreach ( $opts as $o ) {
				$q = (int) $o['belongs_question_id'];
				$n = $i[ $q ] = ( $i[ $q ] ?? -1 ) + 1;
				$l = $n < 26 ? chr( 65 + $n ) : (string) ( $n + 1 );
				$letters[ $q ][ (int) $o['answer_id'] ] = $l;
				if ( (int) $o['is_correct'] ) { $correct[ $q ][] = $l; }
			}
		}
		$out = array();
		foreach ( $rows as $r ) {
			$q     = (int) $r['question_id'];
			$given = maybe_unserialize( $r['given_answer'] );
			$given = is_array( $given ) ? array_values( $given ) : ( '' === (string) $given ? array() : array( $given ) );
			$g     = array();
			foreach ( $given as $id ) { $g[] = $letters[ $q ][ (int) $id ] ?? '?'; }
			$out[ (int) $r['quiz_attempt_id'] ][ $q ] = array( implode( ',', $g ), implode( ',', $correct[ $q ] ?? array() ), (int) $r['is_correct'] );
		}
		return $out;
	}

	/** Question ids of a quiz in quiz order. */
	public static function questions( $quiz ) {
		global $wpdb;
		static $c = array();
		if ( ! isset( $c[ $quiz ] ) ) { $c[ $quiz ] = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT question_id FROM {$wpdb->prefix}tutor_quiz_questions WHERE quiz_id = %d ORDER BY question_order, question_id", $quiz ) ) ); } // phpcs:ignore
		return $c[ $quiz ];
	}

	/** One line per student for the "Exam results" report. */
	public static function exam_rows( $a ) {
		$course  = (int) ( $a['course'] ?: self::default_course() );
		$people  = self::people( array_merge( $a, array( 'course' => $course ) ) );
		$quizzes = self::quizzes( $course );
		$units   = array_values( array_filter( $quizzes, function ( $q ) { return ! $q[3]; } ) );
		$final   = array_values( array_filter( $quizzes, function ( $q ) { return $q[3]; } ) );
		$res     = self::results( $course, wp_list_pluck( $people, 'user_id' ) );
		$rows    = array();
		$tot     = array( 'pass' => 0, 'fail' => 0, 'none' => 0 );
		foreach ( $people as $p ) {
			$r      = $res[ $p['user_id'] ] ?? array();
			$passed = 0;
			$done   = 0;
			foreach ( $units as $q ) { if ( isset( $r[ $q[0] ] ) ) { $done++; if ( $r[ $q[0] ]['pct'] >= self::PASS ) { $passed++; } } }
			$f      = $final ? ( $r[ $final[0][0] ] ?? null ) : null;
			$status = ! $p['user_id'] ? 'No online account' : ( $f ? ( $f['pct'] >= self::PASS ? 'Passed' : 'Not passed' ) : 'Final not taken' );
			$tot[ $f ? ( $f['pct'] >= self::PASS ? 'pass' : 'fail' ) : 'none' ]++;
			$rows[] = array( self::file_no( $p ), $p['name'], $p['email'], $p['class'] ? SDSC_Schedule::class_name( $p['class'], true ) : '—', $done . '/' . count( $units ) . ( $done ? ' (' . $passed . ' at ' . self::PASS . '%+)' : '' ), $f ? self::pct( $f['pct'] ) : '—', $f ? (string) $f['attempts'] : '', $f ? wp_date( 'm/d/Y', strtotime( get_gmt_from_date( $f['ended'] ) . ' UTC' ) ) : '', $status );
		}
		return array( $rows, $tot, $course );
	}

	protected static function pct( $v ) { return ( abs( $v - round( $v ) ) < 0.05 ? (string) (int) round( $v ) : number_format( $v, 1 ) ) . '%'; }

	/* ------------------------------------------------------------ linking a form by hand */

	public static function h_link() {
		if ( ! class_exists( 'SDSC_Reports' ) || ! SDSC_Reports::can( 'file' ) ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_reg_link' );
		$e   = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
		$rid = absint( $_POST['reg2'] ?? 0 ) ? absint( $_POST['reg2'] ) : absint( $_POST['reg'] ?? 0 ); // phpcs:ignore
		$l   = self::links();
		$msg = 'Nothing changed.';
		if ( $e && ! empty( $_POST['unlink'] ) ) { unset( $l[ $e ] ); $msg = 'Form link removed.'; }
		elseif ( $e && $rid ) {
			global $wpdb;
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . SDSC_Registration::table() . ' WHERE id = %d', $rid ) ) ) { $l[ $e ] = $rid; $msg = 'Registration #' . $rid . ' is now this student’s form.'; } // phpcs:ignore
			else { $msg = 'There is no registration #' . $rid . '.'; }
		}
		update_option( self::LINKS, $l, false );
		$back = wp_get_referer() ? wp_get_referer() : SDSC_Reports::url( array( 'r' => 'file' ) );
		wp_safe_redirect( add_query_arg( 'srp_msg', rawurlencode( $msg ), remove_query_arg( 'srp_msg', $back ) ) );
		exit;
	}

	/** Registrations that could be this person's (same first or last name), for the link picker. */
	public static function suggestions( $p ) {
		global $wpdb;
		$parts = preg_split( '/\s+/', trim( $p['name'] ) );
		if ( ! $parts || '' === $parts[0] ) { return array(); }
		$first = $parts[0];
		$last  = end( $parts );
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, first_name, last_name, email, created_at FROM ' . SDSC_Registration::table() . " WHERE source <> 'demo' AND ((first_name LIKE %s AND last_name LIKE %s) OR last_name LIKE %s) ORDER BY (first_name LIKE %s) DESC, id DESC LIMIT 5", $wpdb->esc_like( $first ) . '%', $wpdb->esc_like( $last ) . '%', $wpdb->esc_like( $last ), $wpdb->esc_like( $first ) . '%' ), ARRAY_A ); // phpcs:ignore
	}

	/* ------------------------------------------------------------ printing */

	public static function css() {
		return '
@page { size: letter portrait; margin: 0.85in 0.55in 0.75in;
	@top-left { content: "Sam’s Driving School LLC · MVA-licensed driving school"; font: 700 8.5pt Arial, sans-serif; color: #00703c; vertical-align: bottom; padding-bottom: 6pt; }
	@top-right { content: "' . esc_attr( wp_date( 'm/d/Y g:i A' ) ) . '"; font: 8.5pt Arial, sans-serif; color: #5b646d; vertical-align: bottom; padding-bottom: 6pt; }
	@bottom-left { content: "751 Rockville Pike, Unit 9B, Rockville, MD 20852 · (301) 726-3030 · Confidential — student file"; font: 8pt Arial, sans-serif; color: #5b646d; }
	@bottom-right { content: "Page " counter(page) " of " counter(pages); font: 700 8.5pt Arial, sans-serif; color: #15191d; } }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; font: 10pt/1.38 Arial, Helvetica, sans-serif; color: #15191d; background: #eef1f3; }
.bar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; align-items: center; padding: 10px; background: #15191d; color: #fff; font: 13px Arial, sans-serif; }
.bar button, .bar a { padding: 9px 16px; border: 0; border-radius: 999px; background: #00703c; color: #fff; font: 700 13px Arial, sans-serif; text-decoration: none; cursor: pointer; }
.bar a { background: #3c434a; }
.sheet { width: 8.5in; min-height: 11in; margin: 18px auto; padding: 0.85in 0.55in 0.75in; background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.08); }
.pg { break-after: page; page-break-after: always; }
.pg:last-child { break-after: auto; page-break-after: auto; }
table.wrap { width: 100%; border-collapse: collapse; }
table.wrap > thead { display: table-header-group; }
table.wrap > thead td { padding: 0 0 8px; }
table.wrap > tbody > tr > td { padding: 0; }
.who { display: flex; justify-content: space-between; align-items: flex-end; gap: 10px; padding: 0 0 5px; border-bottom: 2.5pt solid #00703c; }
.who .logo img, .who .logo svg { height: 0.42in; width: auto; }
.who .t { flex: 1; }
.who h1 { margin: 0; font-size: 14pt; line-height: 1.15; }
.who .s { font-size: 9pt; color: #5b646d; }
.who .r { text-align: right; font-size: 8.5pt; color: #5b646d; line-height: 1.35; }
.road { height: 3px; margin: 3px 0 0; background: repeating-linear-gradient(90deg, #ffc20e 0 20px, transparent 20px 34px); }
h2 { margin: 10px 0 4px; padding: 3px 8px; font-size: 8.5pt; letter-spacing: .6px; text-transform: uppercase; background: #e7f5ec; color: #0b3d24; border-radius: 3px; break-after: avoid; }
table.f { width: 100%; border-collapse: collapse; }
table.f th { width: 1.5in; padding: 3.5px 8px; text-align: left; font-size: 8pt; font-weight: 700; color: #5b646d; text-transform: uppercase; letter-spacing: .3px; vertical-align: top; border-bottom: 0.5pt solid #e1e5e8; }
table.f td { padding: 3.5px 8px; border-bottom: 0.5pt solid #e1e5e8; vertical-align: top; }
table.f tr, .agree, .sigs, .office, .unit, .final { break-inside: avoid; page-break-inside: avoid; }
.blank { color: #9aa3ab; }
.agree { margin: 4px 0; padding: 6px 8px; border: 0.75pt solid #d5dbe0; border-radius: 4px; font-size: 9pt; }
.box { display: inline-block; width: 11px; height: 11px; margin-right: 6px; border: 1pt solid #15191d; text-align: center; line-height: 9px; font-size: 9px; vertical-align: -1px; }
.policy { font-size: 9.2pt; line-height: 1.45; color: #1d2327; }
.policy h1, .policy h2, .policy h3, .policy h4 { all: unset; display: block; font-weight: 700; font-size: 9.8pt; margin: 8px 0 3px; color: #0b3d24; break-after: avoid; }
.policy p { margin: 0 0 4px; } .policy ul, .policy ol { margin: 0 0 4px; padding-left: 14px; } .policy li { margin: 0 0 1px; }
.policy a { color: inherit; text-decoration: none; }
.policy p { text-align: left; margin: 0 0 5px; break-inside: avoid; } .policy h4 { margin-top: 10px; }
.keep { break-inside: avoid; page-break-inside: avoid; }
.sigs { display: flex; gap: 18px; margin-top: 8px; }
.sig { flex: 1; }
.sig .pad { height: 0.95in; display: flex; align-items: flex-end; justify-content: center; border-bottom: 1pt solid #15191d; }
.sig .pad img { max-height: 0.9in; max-width: 100%; }
.sig small { display: flex; justify-content: space-between; font-size: 8pt; color: #5b646d; padding-top: 2px; }
.office { display: flex; gap: 18px; margin-top: 16px; font-size: 8pt; color: #5b646d; }
.office div { flex: 1; border-top: 0.75pt solid #9aa3ab; padding-top: 2px; }
.meta { margin-top: 8px; font-size: 7.5pt; color: #7b838a; }
.units { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 6px 0 0; }
.unit, .final { border: 0.75pt solid #cfd6dc; border-radius: 5px; overflow: hidden; }
.unit header, .final header { display: flex; justify-content: space-between; align-items: baseline; padding: 3px 7px; background: #f3f6f7; border-bottom: 0.75pt solid #cfd6dc; font-size: 8.5pt; font-weight: 700; }
.unit header small, .final header small { font-weight: 400; color: #5b646d; }
.qs { display: grid; grid-template-columns: repeat(2, 1fr); grid-auto-flow: column; grid-template-rows: repeat(5, auto); gap: 0 10px; padding: 4px 7px 5px; font-size: 8.6pt; }
.final .qs { grid-template-columns: repeat(5, 1fr); grid-auto-flow: column; grid-template-rows: repeat(10, auto); }
.q { display: flex; gap: 4px; align-items: baseline; white-space: nowrap; line-height: 1.5; }
.q .n { width: 18px; text-align: right; color: #5b646d; }
.ok { color: #0b7a3b; font-weight: 700; }
.bad { color: #b42318; font-weight: 700; text-decoration: line-through; }
.fix { color: #0b7a3b; font-weight: 700; font-size: 8pt; }
.none { color: #9aa3ab; }
.score { font-weight: 800; }
.score.pass { color: #0b7a3b; } .score.fail { color: #b42318; }
.legend { margin: 6px 0 0; font-size: 7.8pt; color: #5b646d; }
.final { margin-top: 8px; }
.result { display: flex; gap: 18px; margin-top: 8px; padding: 6px 9px; border-radius: 5px; background: #f6f7f7; font-size: 9pt; break-inside: avoid; }
table.t { width: 100%; border-collapse: collapse; }
table.t th { padding: 4pt 5pt; background: #e7f5ec; color: #0b3d24; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .3px; border-bottom: 1px solid #b8dcc6; }
table.t td { padding: 3.5pt 5pt; border-bottom: 0.5pt solid #d5dbe0; font-size: 9pt; }
table.t tr { break-inside: avoid; }
.empty { padding: 30px; text-align: center; color: #5b646d; }
.ids { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 8px; }
.ids figure { margin: 0; break-inside: avoid; } .ids img { width: 100%; max-height: 3.4in; object-fit: contain; border: 0.75pt solid #cfd6dc; border-radius: 6px; }
.ids figcaption { font-size: 8.5pt; color: #5b646d; margin-top: 2px; }
.de3 { max-width: 8.5in; margin: 14px auto; } .de3-s { font: 600 13px Arial, sans-serif; color: #15191d; } .de3-w { font: 600 13px Arial, sans-serif; color: #8a4b00; background: #fff7e6; padding: 8px 12px; border-radius: 8px; }
.de3 iframe { width: 100%; height: 88vh; border: 1px solid #cfd6dc; border-radius: 8px; background: #fff; }
@media print { .de3 { display: none; } }
@media screen { .sheet .pg + .pg { margin-top: 0.4in; padding-top: 0.4in; border-top: 2px dashed #cfd6dc; } }
@media print { body { background: #fff; } .bar { display: none; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; } }
';
	}

	/** Header that repeats at the top of every page of one student's section. */
	protected static function who( $title, $p, $file_no ) {
		$logo = class_exists( 'SDSC_Brand' ) ? SDSC_Brand::logo( 'wide', '', 40 ) : '<b>Sam’s Driving School</b>';
		$cls  = $p['class'] ? SDSC_Schedule::class_name( $p['class'], true ) : '';
		return '<thead><tr><td><div class="who"><span class="logo">' . $logo . '</span><span class="t"><h1>' . esc_html( $title ) . '</h1><span class="s">' . esc_html( $p['name'] ) . ( $cls ? ' · ' . esc_html( $cls ) : '' ) . '</span></span>'
			. '<span class="r">File no. <b>' . esc_html( $file_no ) . '</b><br>' . esc_html( $p['email'] ) . '</span></div><div class="road"></div></td></tr></thead>';
	}

	public static function file_no( $p ) {
		return ! empty( $p['reg'] ) && class_exists( 'SDSC_Registration' ) ? SDSC_Registration::file_no( $p['reg'] ) : '—';
	}

	/** Registration form page(s). */
	public static function form_html( $p ) {
		$r = $p['reg'];
		if ( ! $r ) {
			return '<section class="pg"><table class="wrap">' . self::who( 'Student Registration Form', $p, self::file_no( $p ) ) . '<tbody><tr><td><p class="empty">No registration form is on file for this student. The office can link the right form on the Reports screen (Student files → “Link a form”).</p></td></tr></tbody></table></section>';
		}
		$v     = function ( $x ) { $x = trim( (string) $x ); return '' === $x ? '<span class="blank">—</span>' : esc_html( $x ); };
		$img   = function ( $bin ) { return ( $bin && 0 === strpos( $bin, "\x89PNG" ) ) ? '<img alt="" src="data:image/png;base64,' . base64_encode( $bin ) . '">' : ''; }; // phpcs:ignore
		$d     = function ( $local, $f = 'm/d/Y' ) { return $local ? wp_date( $f, strtotime( get_gmt_from_date( $local ) . ' UTC' ) ) : ''; };
		$minor = ( $r['age'] && (int) $r['age'] < 18 ) || $r['parent_name'] || $r['parent_sig'];
		$paid  = class_exists( 'SDSC_Pay' ) ? SDSC_Pay::paid_row( (int) $r['id'] ) : null;
		$addr  = trim( $r['street'] . ( $r['apt'] ? ', ' . $r['apt'] : '' ) . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['zip'], ' ,' );
		$sd    = $r['signed_date'] ? wp_date( 'm/d/Y', strtotime( $r['signed_date'] . ' 12:00:00' ) ) : $d( $r['created_at'] );
		$pol   = class_exists( 'SDSC_Policies' ) ? SDSC_Policies::html() : '';
		$pol   = $pol ? wp_kses( $pol, array( 'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array() ) ) : '';
		$h  = '<section class="pg"><table class="wrap">' . self::who( 'Student Registration Form', $p, self::file_no( $p ) ) . '<tbody><tr><td>';
		$h .= '<h2>Course</h2><table class="f"><tr><th>Class</th><td>' . $v( $r['class_id'] ? SDSC_Registration::class_label( (int) $r['class_id'], true ) : self::online_span( $p ) ) . '</td></tr>'
			. '<tr><th>Registration</th><td>#' . (int) $r['id'] . ' · submitted ' . esc_html( $d( $r['created_at'], 'm/d/Y g:i A' ) ) . ( 'forminator' === $r['source'] ? ' (earlier website form)' : '' ) . '</td></tr>'
			. '<tr><th>Payment</th><td>' . $v( $paid ? 'Paid online ' . '$' . number_format( $paid['amount'] / 100, 2 ) . ( ! empty( $paid['paid_at'] ) ? ' on ' . $d( $paid['paid_at'] ) : '' ) : 'Not paid online (see the office records)' ) . '</td></tr></table>';
		$h .= '<h2>Student</h2><table class="f">'
			. '<tr><th>Full name</th><td>' . $v( trim( $r['first_name'] . ' ' . $r['middle_name'] . ' ' . $r['last_name'] ) ) . '</td></tr>'
			. '<tr><th>Date of birth</th><td>' . $v( $r['dob'] ? wp_date( 'm/d/Y', strtotime( $r['dob'] . ' 12:00:00' ) ) . ( $r['age'] ? '  (age ' . (int) $r['age'] . ' when registered)' : '' ) : '' ) . '</td></tr>'
			. '<tr><th>Gender</th><td>' . $v( ucfirst( (string) $r['gender'] ) ) . '</td></tr>'
			. '<tr><th>Phone</th><td>' . $v( $r['phone'] ) . '</td></tr>'
			. '<tr><th>E-mail</th><td>' . $v( $r['email'] ) . '</td></tr>'
			. '<tr><th>Home address</th><td>' . $v( $addr ) . '</td></tr></table>';
		if ( $minor ) {
			$h .= '<h2>Parent / guardian</h2><table class="f"><tr><th>Name</th><td>' . $v( $r['parent_name'] ) . '</td></tr><tr><th>Phone</th><td>' . $v( $r['parent_phone'] ) . '</td></tr><tr><th>E-mail</th><td>' . $v( $r['parent_email'] ) . '</td></tr></table>';
		}
		if ( trim( (string) $r['notes'] ) ) { $h .= '<h2>Notes</h2><table class="f"><tr><td>' . nl2br( esc_html( $r['notes'] ) ) . '</td></tr></table>'; }
		// The full policy text first, then what the student (and parent) agreed to and signed.
		if ( class_exists( 'SDSC_Agreement' ) ) { $h .= '<h2>' . esc_html( SDSC_Agreement::TITLE ) . '</h2><div class="policy">' . SDSC_Agreement::html() . '</div>'; }
		if ( $pol ) { $h .= '<h2>Refund Policies — extra practice and car rental</h2><div class="policy">' . $pol . '</div>'; }
		$h .= '<div class="keep"><h2>Agreement and signatures</h2><p class="agree"><span class="box">' . ( $r['consent'] ? '✓' : '' ) . '</span>I confirm that I have read and fully understood the forms, policies, and terms and conditions, and I agree to all the stipulated terms.</p>';
		if ( $minor && ( $r['parent_consent'] || 'forminator' !== $r['source'] ) ) { $h .= '<p class="agree"><span class="box">' . ( $r['parent_consent'] ? '✓' : '' ) . '</span>I am the student’s parent or legal guardian. I have read the policies, terms and conditions and I agree to them for my child.</p>'; }
		elseif ( $minor ) { $h .= '<p class="agree">The earlier website form had no separate parent check box; ' . ( $r['parent_sig'] ? 'the parent / guardian signed the form below.' : 'no parent / guardian signature was given.' ) . '</p>'; }
		$h .= '<div class="sigs"><div class="sig"><div class="pad">' . $img( $r['student_sig'] ) . '</div><small><span>Student signature</span><span>Date: ' . esc_html( $r['student_sig'] ? $sd : '__________' ) . '</span></small></div>';
		if ( $minor ) { $h .= '<div class="sig"><div class="pad">' . $img( $r['parent_sig'] ) . '</div><small><span>Parent / guardian signature</span><span>Date: ' . esc_html( $r['parent_sig'] ? $sd : '__________' ) . '</span></small></div>'; }
		$idc = class_exists( 'SDSC_IDScan' ) ? SDSC_IDScan::summary( $r['email'] ) : '';
		$h .= '</div><div class="office"><div>Received by (office)</div><div>Date</div><div>' . ( $idc ? 'ID checked: ' . esc_html( $idc ) : 'Permit / ID checked' ) . '</div></div>';
		$h .= '<p class="meta">Consent given and signed electronically on the school website' . ( $r['ip'] ? ' from IP ' . esc_html( $r['ip'] ) : '' ) . ' on ' . esc_html( $sd ) . '.</p></div>';
		return $h . '</td></tr></tbody></table></section>';
	}

	/** Test answer sheet page. */
	public static function sheet_html( $p, $course, $quizzes, $res, $ans ) {
		$r   = $res[ $p['user_id'] ] ?? array();
		$h   = '<section class="pg"><table class="wrap">' . self::who( 'Driver Education Test Answer Sheet', $p, self::file_no( $p ) ) . '<tbody><tr><td>';
		if ( ! $p['user_id'] ) {
			return $h . '<p class="empty">This student has no online account, so there are no online quiz or exam results.</p></td></tr></tbody></table></section>';
		}
		$reg = $p['reg'];
		$h  .= '<table class="f"><tr><th>Student</th><td><b>' . esc_html( strtoupper( $p['name'] ) ) . '</b></td><th>Date of birth</th><td>' . esc_html( $reg && $reg['dob'] ? wp_date( 'm/d/Y', strtotime( $reg['dob'] . ' 12:00:00' ) ) : '—' ) . '</td></tr>'
			. '<tr><th>Online course</th><td>' . esc_html( get_the_title( $course ) ) . '</td><th>Phone</th><td>' . esc_html( $p['phone'] ? $p['phone'] : '—' ) . '</td></tr></table>';
		$unit_html = '';
		$final     = '';
		$u         = 0;
		foreach ( $quizzes as $q ) {
			$best = $r[ $q[0] ] ?? null;
			$qa   = $best ? ( $ans[ $best['attempt_id'] ] ?? array() ) : array();
			$qs   = '';
			$list = self::questions( $q[0] );
			$n    = max( count( $list ), $q[2] );
			for ( $i = 0; $i < $n; $i++ ) {
				$a = isset( $list[ $i ] ) ? ( $qa[ $list[ $i ] ] ?? null ) : null;
				if ( ! $best ) { $cell = '<span class="none">—</span>'; }
				elseif ( ! $a ) { $cell = '<span class="none">no answer</span>'; }
				elseif ( $a[2] ) { $cell = '<span class="ok">' . esc_html( $a[0] ) . ' ✓</span>'; }
				else { $cell = '<span class="bad">' . esc_html( '' !== $a[0] ? $a[0] : '—' ) . '</span> <span class="fix">→ ' . esc_html( $a[1] ) . '</span>'; }
				$qs .= '<span class="q"><span class="n">' . ( $i + 1 ) . '.</span>' . $cell . '</span>';
			}
			$sc = $best ? '<span class="score ' . ( $best['pct'] >= self::PASS ? 'pass' : 'fail' ) . '">' . esc_html( self::pct( $best['pct'] ) ) . '</span> <small>' . esc_html( wp_date( 'm/d/Y', strtotime( get_gmt_from_date( $best['ended'] ) . ' UTC' ) ) . ( $best['attempts'] > 1 ? ' · ' . $best['attempts'] . ' tries' : '' ) ) . '</small>' : '<small>Not taken</small>';
			if ( $q[3] ) {
				$final .= '<div class="final"><header><span>' . esc_html( $q[1] ) . ' · ' . (int) $n . ' questions</span><span>' . $sc . '</span></header><div class="qs">' . $qs . '</div></div>';
				$final .= '<div class="result"><span>Final exam: <b>' . ( $best ? esc_html( self::pct( $best['pct'] ) ) . ' — ' . ( $best['pct'] >= self::PASS ? 'PASSED' : 'NOT PASSED' ) : 'NOT TAKEN' ) . '</b></span><span>Passing grade: ' . self::PASS . '%</span>' . ( $best ? '<span>Correct: ' . esc_html( rtrim( rtrim( number_format( $best['earned'], 2 ), '0' ), '.' ) . ' of ' . rtrim( rtrim( number_format( $best['total'], 2 ), '0' ), '.' ) ) . '</span>' : '' ) . '</div>';
			} else {
				$u++;
				$unit_html .= '<div class="unit"><header><span>Unit ' . $u . '</span><span>' . $sc . '</span></header><div class="qs">' . $qs . '</div></div>';
			}
		}
		$h .= '<div class="units">' . $unit_html . '</div>' . $final;
		$h .= '<p class="legend">✓ correct · <span class="bad">B</span> <span class="fix">→ C</span> the student’s answer was B, the correct answer is C · Each quiz shows the student’s best attempt (highest score; the latest one when equal). Letters follow the order of the options in the quiz.</p>';
		return $h . '</td></tr></tbody></table></section>';
	}

	/** Class attendance page. */
	public static function attendance_html( $p ) {
		$list = ( $p['class'] && class_exists( 'SDSC_ZoomRoll' ) ) ? SDSC_Schedule::sessions( $p['class'] ) : array();
		if ( ! $list ) { return '<section class="pg"><table class="wrap">' . self::who( 'Class Attendance Record', $p, self::file_no( $p ) ) . '<tbody><tr><td><p class="empty">' . ( $p['class'] ? 'This class has no Zoom sessions scheduled yet.' : 'This student is not in a class of the new class system, so there is no attendance on record here.' ) . '</p></td></tr></tbody></table></section>'; }
		$rows  = SDSC_ZoomRoll::rows( $p['class'] );
		$mine  = $rows[ $p['email'] ] ?? array();
		$lbl   = array( 'present' => 'Present', 'late' => 'Late', 'left' => 'Left early', 'absent' => 'Absent' );
		$today = current_time( 'Y-m-d' );
		$att   = 0;
		$t     = '<table class="t"><thead><tr><th>#</th><th>Date</th><th>Time</th><th>Attendance</th><th>Note</th></tr></thead><tbody>';
		foreach ( $list as $x ) {
			$m    = $mine[ $x['date'] ] ?? array();
			$v    = (string) ( $m['actual'] ?? '' );
			$att += in_array( $v, array( 'present', 'late', 'left' ), true ) ? 1 : 0;
			$t   .= '<tr><td>' . (int) $x['n'] . '</td><td>' . esc_html( wp_date( 'D m/d/Y', SDSC_Lessons::ts( $x['start'] ) ) ) . '</td><td>' . esc_html( wp_date( 'g:i A', SDSC_Lessons::ts( $x['start'] ) ) ) . '</td><td>' . esc_html( $v ? ( $lbl[ $v ] ?? ucfirst( $v ) ) : ( $x['date'] <= $today ? 'Not marked' : 'Upcoming' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $m['staff_note'] ?? '' ) ) . '</td></tr>';
		}
		$t .= '</tbody></table>';
		list( $used, $miss1 ) = SDSC_Schedule::absences( $p['class'], $p['email'] );
		$sum = '<div class="result"><span>Attended: <b>' . $att . ' of ' . count( $list ) . '</b></span><span>Absences counted: <b>' . (int) $used . ' of ' . SDSC_Schedule::ALLOWED . ' allowed</b></span>' . ( $miss1 ? '<span><b>Missed the first session</b></span>' : '' ) . '</div>';
		return '<section class="pg"><table class="wrap">' . self::who( 'Class Attendance Record', $p, self::file_no( $p ) ) . '<tbody><tr><td>' . $t . $sum . '<div class="office"><div>Instructor signature</div><div>Date</div><div>School manager</div></div></td></tr></tbody></table></section>';
	}

	/** Older students (no class in the new class system): the online course and the days of the first and last unit test. */
	public static function online_span( $p ) {
		global $wpdb;
		$c = self::default_course();
		if ( ! $c || empty( $p['user_id'] ) ) { return ''; }
		$d = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(attempt_ended_at) a, MAX(attempt_ended_at) b FROM {$wpdb->prefix}tutor_quiz_attempts WHERE course_id = %d AND user_id = %d AND attempt_status = 'attempt_ended'", $c, (int) $p['user_id'] ), ARRAY_A ); // phpcs:ignore
		if ( empty( $d['a'] ) ) { return ''; }
		$f = function ( $x ) { return wp_date( 'm/d/Y', strtotime( substr( $x, 0, 10 ) . ' 12:00:00' ) ); };
		return get_the_title( $c ) . ' · Zoom classes ' . $f( $d['a'] ) . ( substr( $d['a'], 0, 10 ) !== substr( $d['b'], 0, 10 ) ? ' – ' . $f( $d['b'] ) : '' );
	}

	/** Whole printout: $parts = any of form, sheet, attendance. */
	/** The forms that can be printed, in print order: key => exact name. */
	public static function parts() {
		return array(
			'form'       => 'Student Registration Form',
			'sheet'      => 'Driver Education Test Answer Sheet',
			'attendance' => 'Class Attendance Record',
			'idcopy'     => 'Copy of ID / Learner’s Permit',
			'de003'      => 'Student Record and Completion Form (MVA DE-003)',
		);
	}

	/** Copies of the student's ID / permit / license scans on file. */
	public static function idcopy_html( $p ) {
		$imgs = class_exists( 'SDSC_Docs' ) ? SDSC_Docs::images( $p['email'], array( 'id_front', 'id_back', 'permit', 'license' ) ) : array();
		$h    = '<section class="pg"><table class="wrap">' . self::who( 'Copy of ID / Learner’s Permit', $p, self::file_no( $p ) ) . '<tbody><tr><td>';
		if ( ! $imgs ) { return $h . '<p class="empty">No ID, learner’s permit or license is on file in Student documents.</p></td></tr></tbody></table></section>'; }
		$h .= '<div class="ids">';
		foreach ( $imgs as $i ) { $h .= '<figure><img alt="" src="' . esc_attr( $i['src'] ) . '"><figcaption>' . esc_html( $i['label'] ) . '</figcaption></figure>'; }
		$idc = class_exists( 'SDSC_IDScan' ) ? SDSC_IDScan::summary( $p['email'] ) : '';
		return $h . '</div>' . ( $idc ? '<p class="meta">Checked: ' . esc_html( $idc ) . '</p>' : '' ) . '<div class="office"><div>Copy made by (office)</div><div>Date</div></div></td></tr></tbody></table></section>';
	}

	/** Whole printout: $parts = any of the keys of parts(). */
	public static function print_page( $people, $parts, $course, $title ) {
		$need    = array_intersect( array( 'sheet', 'de003' ), $parts );
		$quizzes = $need ? self::quizzes( $course ) : array();
		$res     = $quizzes ? self::results( $course, wp_list_pluck( $people, 'user_id' ) ) : array();
		$ans     = array();
		if ( $res && in_array( 'sheet', $parts, true ) ) {
			$ids = array();
			foreach ( $res as $by ) { foreach ( $by as $x ) { $ids[] = $x['attempt_id']; } }
			$ans = self::answers( $ids );
		}
		$html_parts = array_values( array_diff( $parts, array( 'de003' ) ) );
		$de3        = in_array( 'de003', $parts, true ) && class_exists( 'SDSC_DE003' );
		$names      = self::parts();
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title><style>' . self::css() . '</style></head><body>'; // phpcs:ignore
		echo '<div class="bar"><span>' . esc_html( $title ) . ' · ' . count( $people ) . ' student' . ( 1 === count( $people ) ? '' : 's' ) . ' · US Letter</span>'
			. ( $html_parts ? '<button onclick="window.print()">🖨 Print / Save as PDF</button>' : '' )
			. ( $de3 ? '<button id="de3go" type="button">📄 ' . esc_html( $names['de003'] ) . '</button>' : '' )
			. '<a href="javascript:window.close()">Close</a></div>';
		if ( $de3 ) {
			$data = array();
			foreach ( $people as $p ) { $data[] = array( 'name' => $p['name'], 'file' => self::file_no( $p ), 'f' => SDSC_DE003::fields( $p, $quizzes, $res ) ); }
			$set  = SDSC_DE003::settings();
			$miss = array_filter( array( 'School number' => $set['number'], 'Maryland State ID of the authorized school official' => SDSC_DE003::rep_id( $set )[0] ), function ( $v ) { return '' === $v; } );
			echo '<div class="de3" id="de3"><p class="de3-s" id="de3s">' . ( $html_parts ? 'The DE-003 forms open as a separate PDF.' : 'Preparing the official MVA form…' ) . '</p>'
				. ( $miss ? '<p class="de3-w">Not filled yet: ' . esc_html( implode( ', ', array_keys( $miss ) ) ) . ' — enter them once under Reports → “MVA form DE-003 — school details”.</p>' : '' )
				. '<iframe id="de3f" title="' . esc_attr( $names['de003'] ) . '" hidden></iframe></div>';
			echo '<script src="' . esc_url( SDSC_URL . 'assets/vendor/pdf-lib.min.js?ver=1.17.1' ) . '"></script>'; // bundled (MIT), see assets/vendor/README.txt
			echo '<script>(function(){var D=' . wp_json_encode( $data ) . ',U=' . wp_json_encode( add_query_arg( array( 'action' => 'sdsc_de003_pdf', '_n' => wp_create_nonce( 'sdsc_de003' ) ), admin_url( 'admin-ajax.php' ) ) ) . ',dbg=/[?&]de3debug=1/.test(location.search),S=document.getElementById("de3s"),F=document.getElementById("de3f"),busy=false,url="";'
				. 'function latin(v){return String(v==null?"":v).replace(/[‘’]/g,"\'").replace(/[“”]/g,"\"").replace(/[–—]/g,"-").normalize("NFKD").replace(/[^\x20-\x7E\xA0-\xFF]/g,"");}'
				. 'function fit(fl,t,hv){try{fl.setMaxLength(undefined);}catch(e){}fl.setText(t);var r=fl.acroField.getWidgets()[0].getRectangle(),W=r.width-5,H=r.height,ml=false;try{ml=fl.isMultiline();}catch(e){}var s=Math.max(5,Math.min(10,H*0.6));function lines(z){var n=1,x=0;t.split(/\s+/).forEach(function(wd){var ww=hv.widthOfTextAtSize(wd+" ",z);if(x+ww>W&&x>0){n++;x=ww;}else{x+=ww;}});return n;}if(!ml){var s1=s;while(s1>6.5&&hv.widthOfTextAtSize(t,s1)>W)s1-=0.25;if(hv.widthOfTextAtSize(t,s1)<=W){s=s1;}else{fl.enableMultiline();ml=true;}}if(ml){while(s>5&&lines(s)*s*1.2>H-2)s-=0.25;}var da="/Helv "+s.toFixed(2)+" Tf 0 g";fl.acroField.setDefaultAppearance(da);fl.acroField.getWidgets().forEach(function(w){w.setDefaultAppearance(da);});}async function make(){if(busy)return;if(url){show();return;}busy=true;S.textContent="Preparing the official MVA form…";try{if(!window.PDFLib)throw new Error("The PDF tool did not load. Reload the page.");var r=await fetch(U,{credentials:"same-origin"});if(!r.ok)throw new Error(await r.text());var src=await r.arrayBuffer(),out=await PDFLib.PDFDocument.create();'
				. 'for(var i=0;i<D.length;i++){S.textContent="Filling "+(i+1)+" of "+D.length+": "+D[i].name;var doc=await PDFLib.PDFDocument.load(src),form=doc.getForm(),hv=await doc.embedFont(PDFLib.StandardFonts.Helvetica);form.getFields().forEach(function(fl){var n=fl.getName();if(!(fl instanceof PDFLib.PDFTextField))return;var v=dbg?n:D[i].f[n];if(v!==undefined&&v!==null&&v!==""){try{fit(fl,latin(v),hv);}catch(e){}}});form.updateFieldAppearances(hv);form.flatten({updateFieldAppearances:false});var pg=await out.copyPages(doc,doc.getPageIndices());pg.forEach(function(p){out.addPage(p);});}'
				. 'out.setTitle(' . wp_json_encode( $names['de003'] ) . '+(D.length===1?" — "+D[0].name:""));var b=await out.save();url=URL.createObjectURL(new Blob([b],{type:"application/pdf"}));show();}catch(e){S.textContent="The form could not be prepared: "+e.message;}busy=false;}'
				. 'function show(){S.textContent=D.length+" form"+(D.length===1?"":"s")+" ready — print or save with the buttons of the PDF viewer below.";F.hidden=false;F.src=url;F.scrollIntoView();}'
				. 'document.getElementById("de3go").addEventListener("click",make);' . ( $html_parts ? '' : 'make();' ) . '})();</script>';
		}
		if ( $html_parts ) {
			echo '<div class="sheet">';
			if ( ! $people ) { echo '<p class="empty">No students for these filters.</p>'; }
			foreach ( $people as $p ) {
				foreach ( $html_parts as $k ) {
					if ( 'form' === $k ) { echo self::form_html( $p ); } // phpcs:ignore -- escaped inside
					if ( 'sheet' === $k ) { echo self::sheet_html( $p, $course, $quizzes, $res, $ans ); } // phpcs:ignore
					if ( 'attendance' === $k ) { echo self::attendance_html( $p ); } // phpcs:ignore
					if ( 'idcopy' === $k ) { echo self::idcopy_html( $p ); } // phpcs:ignore
				}
			}
			echo '</div>';
		}
		echo '<script>if(location.hash==="#print"){window.addEventListener("load",function(){window.print();});}</script></body></html>';
		exit;
	}
}
