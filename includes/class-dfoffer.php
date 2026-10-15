<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Lesson offers: the office picks several possible lesson times (day, time, length, instructor, car), says how
 * many the student should take (e.g. 2 of 5), and sends the student a personal link by e-mail and text. The
 * student opens the link, ticks the times that suit them and books — every chosen time is checked again
 * (instructor, car, the student's own lessons, the MVA 2-hours-a-day rule) and booked through the same path
 * as the office form, with the usual confirmation. Times are not held: the office sees open offers, and a
 * time that was taken in the meantime shows as "no longer available" to the student.
 *
 * Office side: the "Offer several times" mode of the Schedule a lesson form, and Sam's School → Lesson offers.
 * Student side: admin-post.php?action=sdsc_pick&t=<token> (works without an account; never cached).
 */
class SDSC_DFOffer {

	const DBV  = '1';
	const DBO  = 'sdsc_dfo_db';
	const PAGE = 'sdsc-lesson-offers';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'install' ) );
		add_action( 'admin_post_sdsc_dfb_offer', array( __CLASS__, 'h_offer' ) );
		add_action( 'admin_post_sdsc_dfo_act', array( __CLASS__, 'h_act' ) );
		add_action( 'admin_post_sdsc_pick', array( __CLASS__, 'pick_page' ) );
		add_action( 'admin_post_nopriv_sdsc_pick', array( __CLASS__, 'pick_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 33 );
	}

	public static function t() { global $wpdb; return $wpdb->prefix . 'sdsc_df_offers'; }

	public static function install() {
		if ( self::DBV === get_option( self::DBO ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::t() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL,
			student longtext NOT NULL,
			slots longtext NOT NULL,
			pick tinyint(3) unsigned NOT NULL DEFAULT 1,
			status varchar(12) NOT NULL DEFAULT 'open',
			booked longtext NULL,
			sent varchar(120) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			booked_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	public static function link( $token ) { return add_query_arg( array( 'action' => 'sdsc_pick', 't' => $token ), admin_url( 'admin-post.php' ) ); }

	public static function get( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) { return null; }
		$o = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE token = %s', $token ), ARRAY_A ); // phpcs:ignore
		if ( ! $o ) { return null; }
		$o['student'] = (array) json_decode( $o['student'], true );
		$o['slots']   = (array) json_decode( $o['slots'], true );
		$o['booked']  = (array) json_decode( (string) $o['booked'], true );
		if ( 'open' === $o['status'] && SDSC_Lessons::ts( $o['expires_at'] ) < time() ) { $o['status'] = 'expired'; }
		return $o;
	}

	/** Open offers that include this time for this instructor or car (shown as a warning to the office). */
	public static function overlaps( $start, $end, $ins, $car ) {
		global $wpdb;
		if ( self::DBV !== get_option( self::DBO ) ) { return array(); }
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT student, slots FROM ' . self::t() . " WHERE status = 'open' AND expires_at > %s", current_time( 'mysql' ) ), ARRAY_A ) as $o ) { // phpcs:ignore
			$st = (array) json_decode( $o['student'], true );
			foreach ( (array) json_decode( $o['slots'], true ) as $s ) {
				$a = $s['start'];
				$b = wp_date( 'Y-m-d H:i:s', SDSC_Lessons::ts( $a ) + (int) $s['minutes'] * 60 );
				if ( $a < $end && $b > $start && ( ( $ins && (int) $s['ins'] === (int) $ins ) || ( $car && (int) $s['car'] === (int) $car ) ) ) { $out[] = trim( ( $st['first'] ?? '' ) . ' ' . ( $st['last'] ?? '' ) ); }
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ------------------------------------------------------------------ office: create */

	public static function h_offer() {
		if ( ! SDSC_DFBook::can() ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_dfb_book' );
		self::install();
		global $wpdb;
		$back = remove_query_arg( array( 'sdsc_booked', 'sdsc_berr' ), wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=driveflow-pro-calendar' ) );
		$fail = function ( $m ) use ( $back ) { wp_safe_redirect( add_query_arg( 'sdsc_berr', rawurlencode( $m ), $back ) ); exit; };
		$p    = wp_unslash( $_POST ); // phpcs:ignore -- sanitised below
		$st   = array(
			'first'    => sanitize_text_field( $p['first'] ?? '' ),
			'last'     => sanitize_text_field( $p['last'] ?? '' ),
			'email'    => strtolower( sanitize_email( $p['email'] ?? '' ) ),
			'phone'    => sanitize_text_field( $p['phone'] ?? '' ),
			'permit'   => in_array( $p['permit'] ?? '', array( 'yes', 'no' ), true ) ? $p['permit'] : '',
			'permitno' => sanitize_text_field( mb_substr( (string) ( $p['permitno'] ?? '' ), 0, 40 ) ),
			'lang'     => 'es' === ( $p['lang'] ?? '' ) ? 'es' : 'en',
			'type'     => isset( SDSC_DFBook::types()[ $p['type'] ?? '' ] ) ? $p['type'] : 'extra',
			'no'       => min( 99, absint( $p['no'] ?? 1 ) ),
			'topic'    => sanitize_text_field( mb_substr( (string) ( $p['topic'] ?? '' ), 0, 150 ) ),
			'place'    => sanitize_text_field( mb_substr( (string) ( $p['place'] ?? '' ), 0, 250 ) ),
			'note'     => sanitize_text_field( mb_substr( (string) ( $p['note'] ?? '' ), 0, 500 ) ),
		);
		if ( ! $st['first'] || ( ! is_email( $st['email'] ) && strlen( preg_replace( '/\D/', '', $st['phone'] ) ) < 10 ) ) { $fail( 'Enter the student’s name and an e-mail or a mobile number.' ); }
		$raw   = json_decode( (string) ( $p['slots'] ?? '[]' ), true );
		$slots = array();
		foreach ( (array) $raw as $s ) {
			$start = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', (string) ( $s['start'] ?? '' ) ) ? $s['start'] : '';
			$min   = in_array( (int) ( $s['minutes'] ?? 0 ), array( 60, 90, 120, 180 ), true ) ? (int) $s['minutes'] : 0;
			$car   = absint( $s['car'] ?? 0 );
			$ins   = absint( $s['ins'] ?? 0 );
			if ( ! $ins && ! empty( $s['ins_new'] ) ) { $ins = SDSC_DFBook::ensure_typed_instructor( sanitize_text_field( mb_substr( (string) $s['ins_new'], 0, 120 ) ), '', $car ); }
			if ( $start && $min && $ins && $car ) { $slots[] = array( 'start' => $start, 'minutes' => $min, 'ins' => $ins, 'car' => $car ); }
		}
		if ( ! $slots ) { $fail( 'Add at least one time to the offer.' ); }
		usort( $slots, function ( $a, $b ) { return strcmp( $a['start'], $b['start'] ); } );
		foreach ( $slots as $s ) {
			list( $err ) = SDSC_DFBook::check( array_merge( self::d( $st, $s ), array( 'name' => trim( $st['first'] . ' ' . $st['last'] ) ) ) );
			$err = array_values( array_filter( $err, function ( $e ) { return false === strpos( $e, '2 hours a day' ); } ) ); // the daily limit is checked when the student chooses
			if ( $err ) { $fail( wp_date( 'D M j, g:i A', SDSC_Lessons::ts( $s['start'] ) ) . ': ' . implode( ' ', $err ) ); }
		}
		$pick  = max( 1, min( count( $slots ), absint( $p['pick'] ?? 1 ) ) );
		$days  = in_array( (int) ( $p['days'] ?? 3 ), array( 1, 2, 3, 7 ), true ) ? (int) $p['days'] : 3;
		$exp   = min( time() + $days * DAY_IN_SECONDS, SDSC_Lessons::ts( $slots[0]['start'] ) - 2 * HOUR_IN_SECONDS );
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->insert( self::t(), array( 'token' => $token, 'student' => wp_json_encode( $st ), 'slots' => wp_json_encode( $slots ), 'pick' => $pick, 'status' => 'open', 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ), 'expires_at' => wp_date( 'Y-m-d H:i:s', $exp ) ) );
		if ( ! $wpdb->insert_id ) { $fail( 'The offer could not be saved.' ); }
		$sent = ! empty( $p['send'] ) ? self::send( self::get( $token ) ) : 'not sent';
		$msg  = 'Offer of ' . count( $slots ) . ' time' . ( count( $slots ) > 1 ? 's' : '' ) . ' for ' . $st['first'] . ' (pick ' . $pick . ') — ' . $sent . '. Link: ' . self::link( $token );
		wp_safe_redirect( add_query_arg( 'sdsc_booked', rawurlencode( $msg ), $back ) );
		exit;
	}

	protected static function d( $st, $s ) {
		return array(
			'start' => $s['start'], 'minutes' => (int) $s['minutes'], 'type' => $st['type'], 'ins' => (int) $s['ins'], 'car' => (int) $s['car'], 'ins_new' => '', 'ins_ph' => '',
			'first' => $st['first'], 'last' => $st['last'], 'name' => trim( $st['first'] . ' ' . $st['last'] ), 'email' => $st['email'], 'phone' => $st['phone'],
			'permit' => $st['permit'], 'permitno' => $st['permitno'], 'lang' => $st['lang'], 'place' => $st['place'] ? $st['place'] : ( SDSC_Lessons::settings()['place'] ?? '' ),
			'no' => $st['no'], 'topic' => $st['topic'], 'note' => $st['note'], 'mail' => true, 'tell' => true, 'exclude' => 0,
		);
	}

	/** E-mail and text the link. Returns a short result. */
	public static function send( $o ) {
		global $wpdb;
		$st   = $o['student'];
		$es   = 'es' === $st['lang'];
		$url  = self::link( $o['token'] );
		$n    = (int) $o['pick'];
		$c    = count( $o['slots'] );
		$exp  = wp_date( $es ? 'j \d\e F, g:i a' : 'l, F j \a\t g:i A', SDSC_Lessons::ts( $o['expires_at'] ) );
		$done = array();
		if ( is_email( $st['email'] ) && class_exists( 'SDSC_Mailer' ) ) {
			$list = '';
			foreach ( $o['slots'] as $s ) { $list .= '<li>' . esc_html( SDSC_Lessons::when( $s['start'], $st['lang'] ) . ' · ' . ( $s['minutes'] / 60 ) . ( $es ? ' h' : ' h' ) ) . '</li>'; }
			$body = '<p style="margin:0 0 14px">' . ( $es ? 'Hola ' : 'Hi ' ) . esc_html( $st['first'] ) . ',</p>'
				. '<p style="margin:0 0 14px">' . ( $es ? 'Le ofrecemos estos horarios para sus clases de manejo. Por favor elija <b>' . $n . '</b>:' : 'Here are the times we can offer for your driving lessons. Please choose <b>' . $n . '</b>:' ) . '</p>'
				. '<ul style="margin:0 0 16px 18px;padding:0">' . $list . '</ul>'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:18px auto"><tr><td style="border-radius:999px;background:#00703c"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 30px;font:700 15px Arial,sans-serif;color:#ffffff;text-decoration:none;border-radius:999px">' . ( $es ? 'Elegir mis clases' : 'Choose my lessons' ) . ' →</a></td></tr></table>'
				. '<p style="margin:0 0 6px;font-size:13px;color:#5b646d;text-align:center">' . ( $es ? 'Este enlace es personal y vale hasta el ' : 'This personal link works until ' ) . esc_html( $exp ) . '. ' . ( $es ? 'Los horarios se reservan en el orden en que se eligen.' : 'Times are booked in the order people choose them.' ) . '</p>';
			$ok     = SDSC_Mailer::send( $st['email'], $es ? 'Elija sus clases de manejo' : 'Choose your driving lesson times', $body, 'info', array( 'Content-Type: text/html; charset=UTF-8' ) );
			$done[] = $ok ? 'e-mailed' : 'e-mail failed';
		}
		if ( '' !== trim( (string) $st['phone'] ) && class_exists( 'SDSC_SMS' ) ) {
			$txt    = $es ? 'Hola ' . $st['first'] . ', elija ' . $n . ' de ' . $c . ' horarios para sus clases de manejo: ' . $url . ' – Sam’s Driving School' : 'Hi ' . $st['first'] . ', please choose ' . $n . ' of ' . $c . ' times for your driving lessons: ' . $url . ' – Sam’s Driving School';
			$done[] = 'text ' . SDSC_SMS::deliver( $st['phone'], $txt, 'offer:' . $o['id'] . ':' . time(), trim( $st['first'] . ' ' . $st['last'] ), '🗓️ Lesson times to choose' );
		}
		$res = $done ? implode( ', ', $done ) : 'no e-mail or phone to send to';
		$wpdb->update( self::t(), array( 'sent' => mb_substr( current_time( 'M j g:i a' ) . ': ' . $res, 0, 120 ) ), array( 'id' => $o['id'] ) );
		return $res;
	}

	/* ------------------------------------------------------------------ student page */

	public static function pick_page() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$o = self::get( sanitize_text_field( wp_unslash( $_REQUEST['t'] ?? '' ) ) ); // phpcs:ignore
		if ( ! $o ) { self::page_out( 'Link not found', '<p>This link is not valid. Please call <b>(301) 726-3030</b>.</p>', 'en' ); }
		$st  = $o['student'];
		$L   = $st['lang'];
		$es  = 'es' === $L;
		$ph  = '(301) 726-3030';
		if ( 'booked' === $o['status'] ) { self::page_out( $es ? 'Clases reservadas' : 'Lessons booked', self::booked_html( $o ), $L ); }
		if ( 'open' !== $o['status'] ) { self::page_out( $es ? 'Enlace vencido' : 'This link has expired', '<p>' . ( $es ? 'Este enlace ya no está activo. Llame o envíe un texto al <b>' . $ph . '</b> para elegir otros horarios.' : 'This link is no longer active. Call or text <b>' . $ph . '</b> and we will find new times for you.' ) . '</p>', $L ); }

		$msg = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_n'] ?? '' ) ), 'sdsc_pick_' . $o['token'] ) ) { $msg = $es ? 'La página venció. Inténtelo de nuevo.' : 'The page expired. Please try again.'; }
			else {
				$want = array_values( array_unique( array_map( 'absint', (array) ( $_POST['s'] ?? array() ) ) ) ); // phpcs:ignore
				$want = array_values( array_filter( $want, function ( $i ) use ( $o ) { return isset( $o['slots'][ $i ] ); } ) );
				$need = min( (int) $o['pick'], count( self::available( $o ) ) );
				if ( count( $want ) !== $need ) { $msg = $es ? 'Por favor elija ' . $need . ' horario(s).' : 'Please choose ' . $need . ' time' . ( $need > 1 ? 's' : '' ) . '.'; }
				else {
					$r = self::book_picks( $o, $want );
					if ( true === $r ) { self::page_out( $es ? 'Clases reservadas' : 'Lessons booked', self::booked_html( self::get( $o['token'] ) ), $L ); }
					$msg = $r;
					$o   = self::get( $o['token'] );
				}
			}
		}
		$avail = self::available( $o );
		$need  = min( (int) $o['pick'], count( $avail ) );
		if ( ! $need ) { self::page_out( $es ? 'Sin horarios' : 'No times left', '<p>' . ( $es ? 'Los horarios ofrecidos ya no están disponibles. Llame o envíe un texto al <b>' . $ph . '</b>.' : 'The times we offered are no longer available. Please call or text <b>' . $ph . '</b> and we will offer new ones.' ) . '</p>', $L ); }
		$h  = '<p>' . ( $es ? 'Hola ' : 'Hi ' ) . esc_html( $st['first'] ) . '! ' . ( $es ? 'Elija <b>' . $need . '</b> de estos horarios para sus clases de manejo.' : 'Choose <b>' . $need . '</b> of these times for your driving lessons.' ) . '</p>';
		if ( $msg ) { $h .= '<p class="err">' . esc_html( $msg ) . '</p>'; }
		$h .= '<form method="post" id="pk"><input type="hidden" name="_n" value="' . esc_attr( wp_create_nonce( 'sdsc_pick_' . $o['token'] ) ) . '"><input type="hidden" name="t" value="' . esc_attr( $o['token'] ) . '"><ul class="slots">';
		foreach ( $o['slots'] as $i => $s ) {
			$ok  = isset( $avail[ $i ] );
			$ins = self::ins_first( (int) $s['ins'] );
			$h  .= '<li class="' . ( $ok ? '' : 'gone' ) . '"><label><input type="checkbox" name="s[]" value="' . (int) $i . '" data-day="' . esc_attr( substr( $s['start'], 0, 10 ) ) . '" data-min="' . (int) $s['minutes'] . '"' . ( $ok ? '' : ' disabled' ) . '><span><b>' . esc_html( SDSC_Lessons::when( $s['start'], $L, false ) ) . '</b><br>'
				. esc_html( wp_date( 'g:i A', SDSC_Lessons::ts( $s['start'] ) ) . ' – ' . wp_date( 'g:i A', SDSC_Lessons::ts( $s['start'] ) + $s['minutes'] * 60 ) . ( $ins ? ' · ' . ( $es ? 'con ' : 'with ' ) . $ins : '' ) )
				. ( $ok ? '' : '<br><small>' . ( $es ? 'Ya no está disponible' : 'No longer available' ) . '</small>' ) . '</span></label></li>';
		}
		if ( 'de' === $st['type'] ) { $h .= '<p class="m">' . ( $es ? 'Regla del MVA: como máximo 2 horas de manejo por día, así que elija días distintos.' : 'MVA rule: at most 2 hours of driving a day, so choose different days.' ) . '</p>'; }
		$place = $st['place'] ? $st['place'] : ( SDSC_Lessons::settings()['place'] ?? '' );
		$sset  = SDSC_Lessons::settings();
		$h .= '</ul><p class="m">' . ( $es ? 'Lugar de encuentro: ' : 'Meeting place: ' ) . '<b>' . esc_html( $place ) . '</b></p>'
			. '<p class="m">' . ( $es ? 'Puede cancelar o cambiar sin costo hasta ' . (int) $sset['hours'] . ' horas antes de cada clase. Después puede aplicarse un cargo de $' . esc_html( SDSC_Lessons::money( $sset['fee'] ) ) . '.' : 'You can cancel or change free of charge until ' . (int) $sset['hours'] . ' hours before each lesson. After that a $' . esc_html( SDSC_Lessons::money( $sset['fee'] ) ) . ' fee may apply.' ) . '</p>'
			. '<button type="submit" id="go" disabled>' . ( $es ? 'Reservar mis clases' : 'Book my lessons' ) . '</button><p class="m" id="cnt"></p></form>'
			. '<script>(function(){var n=' . (int) $need . ',de=' . ( 'de' === $st['type'] ? 'true' : 'false' ) . ',f=document.getElementById("pk"),b=document.getElementById("go"),c=document.getElementById("cnt");function u(){var on=[].slice.call(f.querySelectorAll("input[name=\'s[]\']:checked")),k=on.length,day={};on.forEach(function(i){day[i.dataset.day]=(day[i.dataset.day]||0)+(+i.dataset.min);});b.disabled=k!==n;c.textContent=k+" / "+n;f.querySelectorAll("input[name=\'s[]\']:not(:checked)").forEach(function(i){if(!i.closest(".gone"))i.disabled=k>=n||(de&&(day[i.dataset.day]||0)+(+i.dataset.min)>120);});}f.addEventListener("change",u);f.addEventListener("submit",function(){b.disabled=true;b.textContent="…";});u();})();</script>';
		self::page_out( $es ? 'Elija sus clases de manejo' : 'Choose your driving lessons', $h, $L );
	}

	protected static function ins_first( $id ) {
		global $wpdb;
		$n = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}driveflow_instructors WHERE id = %d", $id ) ); // phpcs:ignore
		return trim( (string) strtok( $n, ' ' ) );
	}

	/** Slots still free right now (index => slot). */
	protected static function available( $o ) {
		$out = array();
		foreach ( $o['slots'] as $i => $s ) {
			if ( SDSC_Lessons::ts( $s['start'] ) < time() + HOUR_IN_SECONDS ) { continue; }
			list( $err ) = SDSC_DFBook::check( self::d( $o['student'], $s ) );
			if ( ! $err ) { $out[ $i ] = $s; }
		}
		return $out;
	}

	/** Books the chosen times one by one; true, or a message for the student. */
	protected static function book_picks( $o, $want ) {
		global $wpdb;
		// One row at a time per offer, so a double click cannot book twice.
		$lock = 'sdsc_pick_lock_' . $o['id'];
		if ( get_transient( $lock ) ) { return 'Please wait a moment and try again.'; }
		set_transient( $lock, 1, 60 );
		$es   = 'es' === $o['student']['lang'];
		$ids  = array();
		$miss = array();
		$no   = (int) $o['student']['no'];
		foreach ( $want as $i ) {
			$d = self::d( $o['student'], $o['slots'][ $i ] );
			$d['no']    = $no++;
			$d['offer'] = (int) $o['id'];
			list( $err ) = SDSC_DFBook::check( $d );
			if ( $err ) { $miss[] = SDSC_Lessons::when( $d['start'], $o['student']['lang'] ) . ( false !== strpos( implode( ' ', $err ), '2 hours a day' ) ? ( $es ? ' (máximo 2 horas por día)' : ' (2 hours a day at most)' ) : '' ); continue; }
			$r = SDSC_DFBook::book( $d );
			if ( is_wp_error( $r ) ) { $miss[] = SDSC_Lessons::when( $d['start'], $o['student']['lang'] ); continue; }
			$ids[] = (int) $r['id'];
		}
		if ( $ids ) {
			$wpdb->update( self::t(), array( 'status' => $miss ? 'open' : 'booked', 'booked' => wp_json_encode( array_merge( $o['booked'], $ids ) ), 'booked_at' => current_time( 'mysql' ), 'pick' => max( 0, (int) $o['pick'] - count( $ids ) ) ), array( 'id' => $o['id'] ) );
			self::tell_office( $o, $ids, $miss );
		}
		delete_transient( $lock );
		if ( ! $miss ) { return true; }
		return ( $ids ? ( $es ? 'Reservamos ' . count( $ids ) . ' clase(s). ' : 'We booked ' . count( $ids ) . ' lesson(s). ' ) : '' ) . ( $es ? 'Estos horarios ya no se pudieron reservar: ' : 'These times could not be booked: ' ) . implode( '; ', $miss ) . ( $es ? '. Elija otro.' : '. Please choose another.' );
	}

	protected static function tell_office( $o, $ids, $miss ) {
		if ( ! class_exists( 'SDSC_Mailer' ) || ! SDSC_Mailer::ready() ) { return; }
		if ( false !== strpos( home_url(), 'testsaman.' ) ) { return; } // the test site never e-mails the office
		global $wpdb;
		$st   = $o['student'];
		$rows = array( array( 'en' => 'Student', 'fa' => 'شاگرد', 'v' => trim( $st['first'] . ' ' . $st['last'] ), 'ltr' => true ) );
		foreach ( $ids as $id ) {
			$s      = $wpdb->get_row( $wpdb->prepare( 'SELECT scheduled_start, instructor_name, plate_number FROM ' . SDSC_DriveFlow::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
			$rows[] = array( 'en' => 'Booked', 'fa' => 'رزرو شد', 'v' => wp_date( 'D M j, g:i A', SDSC_Lessons::ts( $s['scheduled_start'] ) ) . ' · ' . $s['instructor_name'] . ' · ' . $s['plate_number'], 'ltr' => true );
		}
		if ( $miss ) { $rows[] = array( 'en' => 'Could not book', 'fa' => 'رزرو نشد', 'v' => implode( '; ', $miss ), 'ltr' => true ); }
		SDSC_Mailer::admin_report( array(
			'to'         => implode( ',', class_exists( 'SDSC_Refunds' ) ? SDSC_Refunds::approvers() : array( get_option( 'admin_email' ) ) ),
			'subject_en' => trim( $st['first'] . ' ' . $st['last'] ) . ' chose ' . count( $ids ) . ' lesson time' . ( count( $ids ) > 1 ? 's' : '' ),
			'subject_fa' => 'شاگرد زمان جلسه‌هایش را انتخاب کرد',
			'title_en'   => 'Lesson times chosen from the link',
			'title_fa'   => 'زمان جلسه‌ها از طریق لینک انتخاب شد',
			'lead_en'    => 'The lessons are on the DriveFlow calendar and the student was sent the confirmation.',
			'lead_fa'    => 'جلسه‌ها در تقویم DriveFlow ثبت شد و تأییدیه برای شاگرد رفت.',
			'rows'       => $rows,
			'buttons'    => array( array( 'en' => 'Open the calendar', 'fa' => 'باز کردن تقویم', 'url' => admin_url( 'admin.php?page=driveflow-pro-calendar' ) ) ),
		) );
	}

	protected static function booked_html( $o ) {
		global $wpdb;
		$es = 'es' === $o['student']['lang'];
		$em = is_email( $o['student']['email'] ?? '' );
		$h  = '<p>' . ( $es ? '¡Listo! Estas son sus clases. ' . ( $em ? 'Le enviamos la confirmación por correo electrónico.' : 'Le enviaremos la confirmación por mensaje de texto.' ) : 'All set! These are your lessons. ' . ( $em ? 'We sent you the confirmation by e-mail.' : 'We will text you the confirmation.' ) ) . '</p><ul class="slots">';
		foreach ( (array) $o['booked'] as $id ) {
			$s = $wpdb->get_row( $wpdb->prepare( 'SELECT scheduled_start, scheduled_end, instructor_name, status FROM ' . SDSC_DriveFlow::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
			if ( ! $s ) { continue; }
			$h .= '<li class="ok"><span><b>' . esc_html( SDSC_Lessons::when( $s['scheduled_start'], $o['student']['lang'], false ) ) . '</b><br>' . esc_html( wp_date( 'g:i A', SDSC_Lessons::ts( $s['scheduled_start'] ) ) . ' – ' . wp_date( 'g:i A', SDSC_Lessons::ts( $s['scheduled_end'] ) ) . ' · ' . trim( (string) strtok( $s['instructor_name'], ' ' ) ) ) . ( 'cancelled' === $s['status'] ? ' · ' . ( $es ? 'cancelada' : 'cancelled' ) : '' ) . '</span></li>';
		}
		return $h . '</ul><p class="m">' . ( $es ? 'Para cambiar o cancelar, llame o envíe un texto al <b>(301) 726-3030</b>.' : 'To change or cancel, call or text <b>(301) 726-3030</b>.' ) . '</p>';
	}

	protected static function page_out( $title, $body, $lang ) {
		$logo = class_exists( 'SDSC_Mailer' ) ? ( SDSC_Mailer::info()['logo'] ?? '' ) : '';
		echo '<!doctype html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . ' — Sam’s Driving School</title>'
			. '<style>body{margin:0;background:#f6f4ee;font:16px/1.55 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#15191d}.w{max-width:560px;margin:0 auto;padding:22px 16px 40px}.c{background:#fff;border-radius:16px;padding:22px 20px;box-shadow:0 10px 30px -18px rgba(0,0,0,.35)}h1{font-size:22px;margin:0 0 10px}header{text-align:center;padding:6px 0 16px}header img{max-width:190px;height:auto}.bar{height:6px;border-radius:3px;background:repeating-linear-gradient(90deg,#ffc20e 0 26px,#2d3238 26px 46px);margin:0 0 16px}'
			. '.slots{list-style:none;margin:0 0 14px;padding:0;display:grid;gap:8px}.slots li label,.slots li>span{display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border:2px solid #dfe4e8;border-radius:12px;cursor:pointer}.slots input{width:22px;height:22px;margin-top:2px;accent-color:#00703c}.slots li label:has(input:checked){border-color:#00703c;background:#ecf7f1}.slots li.gone{opacity:.5}.slots li.gone label{cursor:not-allowed}.slots li.ok>span{display:block;border-color:#a7e3c1;background:#ecf7f1}.slots small{color:#b42318}'
			. 'button{width:100%;min-height:52px;border:0;border-radius:999px;background:#00703c;color:#fff;font-weight:700;font-size:17px;font-family:inherit;cursor:pointer}button[disabled]{opacity:.45;cursor:not-allowed}.m{font-size:14px;color:#5b646d}.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px 12px}#cnt{text-align:center}</style></head><body><div class="w">'
			. '<header>' . ( $logo ? '<img src="' . esc_url( $logo ) . '" alt="Sam’s Driving School">' : '<b>Sam’s Driving School</b>' ) . '</header><div class="bar"></div><div class="c"><h1>' . esc_html( $title ) . '</h1>' . $body . '</div></div></body></html>'; // phpcs:ignore -- $body built from escaped parts
		exit;
	}

	/* ------------------------------------------------------------------ office: list */

	public static function menu() {
		if ( ! class_exists( 'SDSC_DFBook' ) || ! SDSC_DFBook::can() ) { return; }
		add_submenu_page( 'sdsc-dashboard', 'Lesson offers', 'Lesson offers', 'read', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function h_act() {
		if ( ! SDSC_DFBook::can() ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_dfo_act' );
		global $wpdb;
		$o   = self::get( sanitize_text_field( wp_unslash( $_POST['t'] ?? '' ) ) ); // phpcs:ignore
		$act = sanitize_key( $_POST['do'] ?? '' ); // phpcs:ignore
		$msg = 'Nothing changed.';
		if ( $o && 'resend' === $act && 'open' === $o['status'] ) { $msg = 'Sent again: ' . self::send( $o ) . '.'; }
		if ( $o && 'cancel' === $act && 'open' === $o['status'] ) { $wpdb->update( self::t(), array( 'status' => 'cancelled' ), array( 'id' => $o['id'] ) ); $msg = 'Offer withdrawn; the link no longer works.'; }
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page() {
		if ( ! SDSC_DFBook::can() ) { wp_die( 'Not allowed.' ); }
		self::install();
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT token FROM ' . self::t() . ' ORDER BY id DESC LIMIT 100', ARRAY_A ); // phpcs:ignore
		$msg  = sanitize_text_field( wp_unslash( $_GET['msg'] ?? '' ) ); // phpcs:ignore
		?>
		<div class="wrap">
			<h1>Lesson offers</h1>
			<p>Times offered to students with a personal link. Make a new offer from the lesson calendar: <b>+ Schedule Lesson → Offer several times</b>. <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=driveflow-pro-calendar' ) ); ?>">Open the calendar</a></p>
			<?php if ( $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th>Student</th><th>Times offered</th><th>To choose</th><th>Status</th><th>Sent</th><th>Link</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : $o = self::get( $row['token'] ); $st = $o['student']; $by = get_userdata( (int) $o['created_by'] ); ?>
					<tr>
						<td><b><?php echo esc_html( trim( $st['first'] . ' ' . $st['last'] ) ); ?></b><br><span class="description"><?php echo esc_html( trim( $st['email'] . ' · ' . $st['phone'], ' ·' ) ); ?></span><br><span class="description"><?php echo esc_html( 'by ' . ( $by ? $by->display_name : '—' ) . ', ' . wp_date( 'M j g:i a', SDSC_Lessons::ts( $o['created_at'] ) ) ); ?></span></td>
						<td><?php foreach ( $o['slots'] as $s ) { echo esc_html( wp_date( 'D M j, g:i A', SDSC_Lessons::ts( $s['start'] ) ) . ' · ' . ( $s['minutes'] / 60 ) . 'h · ' . self::ins_first( (int) $s['ins'] ) ) . '<br>'; } ?></td>
						<td><?php echo (int) $o['pick']; ?></td>
						<td><?php echo esc_html( array( 'open' => 'Waiting for the student', 'booked' => 'Booked', 'expired' => 'Expired', 'cancelled' => 'Withdrawn' )[ $o['status'] ] ?? $o['status'] ); ?><?php echo 'open' === $o['status'] ? '<br><span class="description">until ' . esc_html( wp_date( 'M j g:i a', SDSC_Lessons::ts( $o['expires_at'] ) ) ) . '</span>' : ''; ?><?php echo $o['booked'] ? '<br><span class="description">' . count( $o['booked'] ) . ' booked</span>' : ''; ?></td>
						<td class="description"><?php echo esc_html( $o['sent'] ); ?></td>
						<td><input type="text" readonly value="<?php echo esc_attr( self::link( $o['token'] ) ); ?>" onclick="this.select()" style="width:220px"></td>
						<td><?php if ( 'open' === $o['status'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><?php wp_nonce_field( 'sdsc_dfo_act' ); ?><input type="hidden" name="action" value="sdsc_dfo_act"><input type="hidden" name="t" value="<?php echo esc_attr( $o['token'] ); ?>"><button class="button" name="do" value="resend">Send again</button> <button class="button" name="do" value="cancel">Withdraw</button></form>
						<?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><tr><td colspan="7">No offers yet.</td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
