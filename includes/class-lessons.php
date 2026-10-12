<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Behind-the-wheel lessons: confirmations, the late-cancellation / no-show fee, and waivers.
 *
 * School policy (BTW policy 7): a fee is charged for failing to appear for a scheduled lesson,
 * cancelling with less than 48 hours' notice, or arriving without a valid learner's permit.
 * The amount and the notice period are settings (default $60 and 48 hours).
 *
 *  - Book a lesson → the student gets a confirmation e-mail (English or Spanish) with the date, time,
 *    meeting place, what to bring and the exact last moment to cancel without a fee.
 *    A reminder goes out one day before that moment.
 *  - Cancel → the time the student told us decides: in time = no fee; too late = fee.
 *  - After the lesson → Completed / No-show / No permit. The last two create the fee.
 *  - Every fee is a record: due → paid (online or at the office) or waived. A waiver needs a reason,
 *    nothing is charged, and the record stays visible to the staff and to the student.
 *
 * Nothing is ever charged automatically: a fee becomes a WooCommerce order the student pays through
 * the "Pay" link (or a payment link set in Settings, or at the office).
 */
class SDSC_Lessons {
	const DBV  = '2';
	const DBO  = 'sdsc_lessons_db';
	const SET  = 'sdsc_lessons_set';
	const PAGE = 'sdsc-lessons';
	const HOOK = 'sdsc_lessons_tick';
	const TAB  = 'sdsc-lessons';   // Tutor dashboard page key

	/* ================================================================ setup */

	public static function t_lessons() { global $wpdb; return $wpdb->prefix . 'sdsc_lessons'; }
	public static function t_fees() { global $wpdb; return $wpdb->prefix . 'sdsc_lesson_fees'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 21 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_menu', array( __CLASS__, 'badge' ), 1001 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'book', 'edit', 'cancel', 'outcome', 'waive', 'paid', 'resend', 'set' ) as $a ) {
			add_action( 'admin_post_sdsc_ls_' . $a, array( __CLASS__, 'h_' . $a ) );
		}
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		// Payments made online close the fee by themselves.
		foreach ( array( 'processing', 'completed' ) as $st ) {
			add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'order_paid' ) );
		}
		// Student side: "My driving lessons" in the Tutor dashboard, and a shortcode for any page.
		add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'tutor_nav' ) );
		add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'tutor_template' ) );
		add_shortcode( 'sdsc_my_lessons', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ) );
	}

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$l = self::t_lessons();
		$f = self::t_fees();
		dbDelta( "CREATE TABLE $l (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ckey char(32) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			first_name varchar(80) NOT NULL DEFAULT '',
			last_name varchar(80) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			lang char(2) NOT NULL DEFAULT 'en',
			lesson_no tinyint(3) unsigned NOT NULL DEFAULT 0,
			starts_at datetime NOT NULL,
			minutes smallint(5) unsigned NOT NULL DEFAULT 120,
			place varchar(255) NOT NULL DEFAULT '',
			instructor varchar(120) NOT NULL DEFAULT '',
			instructor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			car_id bigint(20) unsigned NOT NULL DEFAULT 0,
			permit varchar(10) NOT NULL DEFAULT '',
			practice varchar(60) NOT NULL DEFAULT '',
			note text NULL,
			status varchar(20) NOT NULL DEFAULT 'booked',
			notice_at datetime DEFAULT NULL,
			conf_at datetime DEFAULT NULL,
			remind_at datetime DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			log longtext NULL,
			PRIMARY KEY  (id),
			KEY starts_at (starts_at),
			KEY email (email),
			KEY status (status),
			KEY instructor_id (instructor_id)
		) $c;" );
		dbDelta( "CREATE TABLE $f (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			amount decimal(8,2) NOT NULL DEFAULT 0,
			reason varchar(20) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'due',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			paid_at datetime DEFAULT NULL,
			paid_how varchar(40) NOT NULL DEFAULT '',
			waived_at datetime DEFAULT NULL,
			waived_by bigint(20) unsigned NOT NULL DEFAULT 0,
			waive_note varchar(255) NOT NULL DEFAULT '',
			mailed_at datetime DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY lesson_id (lesson_id),
			KEY email (email),
			KEY status (status)
		) $c;" );
		update_option( self::DBO, self::DBV, false );
		update_option( 'sdsc_lessons_flush', 1, false );
	}

	public static function settings() {
		$i = class_exists( 'SDSC_Mailer' ) ? SDSC_Mailer::info() : array( 'street' => '', 'city' => '' );
		return wp_parse_args( (array) get_option( self::SET, array() ), array(
			'fee'         => 60,
			'hours'       => 48,
			'place'       => trim( $i['street'] . ', ' . $i['city'], ', ' ),
			'pay'         => 'woo',      // woo | link | office
			'link'        => '',
			'remind'      => 1,
			'instructors' => '',
			'directions'  => 'Located behind the building — enter from Mt. Vernon Place',
		) );
	}

	/* ================================================================ data */

	public static function lesson( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_lessons() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	}

	public static function fee( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_fees() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	}

	public static function fees_for_lesson( $lid ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_fees() . ' WHERE lesson_id = %d ORDER BY id', $lid ), ARRAY_A ); // phpcs:ignore
	}

	protected static function now() { return current_time( 'mysql' ); }

	public static function ts( $local ) {
		try { return ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp(); } catch ( Exception $e ) { return 0; }
	}

	/** The last moment the student can cancel without a fee (local time string). */
	public static function deadline( $l ) {
		return wp_date( 'Y-m-d H:i:s', self::ts( $l['starts_at'] ) - (int) self::settings()['hours'] * HOUR_IN_SECONDS );
	}

	public static function add_log( $id, $ev ) {
		global $wpdb;
		$l   = self::lesson( $id );
		$log = json_decode( (string) ( $l['log'] ?? '' ), true );
		$log = is_array( $log ) ? $log : array();
		$u   = wp_get_current_user();
		$log[] = array( 't' => self::now(), 'by' => $u && $u->ID ? $u->display_name : 'System', 'e' => $ev );
		$wpdb->update( self::t_lessons(), array( 'log' => wp_json_encode( $log ), 'updated_at' => self::now() ), array( 'id' => $id ) );
	}

	public static function statuses() {
		return array(
			'booked'      => array( 'Booked', 'Reservado' ),
			'done'        => array( 'Completed', 'Completada' ),
			'cancelled'   => array( 'Cancelled in time', 'Cancelada a tiempo' ),
			'late_cancel' => array( 'Late cancellation', 'Cancelación tardía' ),
			'no_show'     => array( 'No-show', 'No se presentó' ),
			'no_permit'   => array( 'No valid permit', 'Sin permiso válido' ),
		);
	}

	public static function reasons() {
		return array(
			'late_cancel' => array( 'Cancelled with less than %d hours’ notice', 'Cancelación con menos de %d horas de aviso' ),
			'no_show'     => array( 'Did not come to the lesson', 'No se presentó a la clase' ),
			'no_permit'   => array( 'Came without a valid learner’s permit', 'Llegó sin un permiso de aprendizaje válido' ),
		);
	}

	public static function reason_text( $r, $lang = 'en' ) {
		$t = self::reasons()[ $r ][ 'es' === $lang ? 1 : 0 ] ?? $r;
		return sprintf( $t, (int) self::settings()['hours'] );
	}

	/** Find (or create) the fee for a lesson. One fee per lesson. */
	public static function make_fee( $l, $reason ) {
		global $wpdb;
		foreach ( self::fees_for_lesson( $l['id'] ) as $f ) { return $f; }
		$wpdb->insert( self::t_fees(), array(
			'lesson_id'  => $l['id'],
			'email'      => $l['email'],
			'amount'     => (float) self::settings()['fee'],
			'reason'     => $reason,
			'status'     => 'due',
			'created_by' => get_current_user_id(),
			'created_at' => self::now(),
		) );
		$f = self::fee( $wpdb->insert_id );
		self::add_log( $l['id'], sprintf( 'Fee of $%s added: %s', self::money( $f['amount'] ), self::reason_text( $reason ) ) );
		return $f;
	}

	public static function money( $n ) {
		$n = (float) $n;
		return floor( $n ) == $n ? number_format( $n, 0 ) : number_format( $n, 2 ); // phpcs:ignore
	}

	/* ================================================================ payment */

	protected static function woo_ok() {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_create_order' ) ) { return false; }
		$g = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();
		return ! empty( $g );
	}

	/** Where the student pays: a WooCommerce order, the payment link from Settings, or '' (pay at the office). */
	public static function pay_url( $f ) {
		if ( 'due' !== $f['status'] ) { return ''; }
		$s = self::settings();
		if ( 'woo' === $s['pay'] && self::woo_ok() ) {
			$o = $f['order_id'] ? wc_get_order( $f['order_id'] ) : null;
			if ( ! $o || ! $o->needs_payment() ) { $o = self::make_order( $f ); }
			if ( $o ) { return $o->get_checkout_payment_url(); }
		}
		if ( in_array( $s['pay'], array( 'woo', 'link' ), true ) && $s['link'] ) { return $s['link']; }
		return '';
	}

	protected static function make_order( $f ) {
		global $wpdb;
		$l = self::lesson( $f['lesson_id'] );
		if ( ! $l ) { return null; }
		try {
			$o    = wc_create_order( array( 'status' => 'pending', 'customer_id' => 0, 'created_via' => 'sdsc_lessons' ) );
			$item = new WC_Order_Item_Fee();
			$item->set_name( self::reason_title( $f['reason'], 'en' ) . ' — lesson of ' . wp_date( 'M j, Y g:i a', self::ts( $l['starts_at'] ) ) );
			$item->set_amount( (float) $f['amount'] );
			$item->set_total( (float) $f['amount'] );
			$item->set_tax_status( 'none' );
			$o->add_item( $item );
			$o->set_billing_first_name( $l['first_name'] );
			$o->set_billing_last_name( $l['last_name'] );
			$o->set_billing_email( $l['email'] );
			$o->set_billing_phone( $l['phone'] );
			$o->update_meta_data( '_sdsc_fee_id', (int) $f['id'] );
			$o->calculate_totals( false );
			$o->add_order_note( 'Behind-the-wheel fee #' . (int) $f['id'] . ' (Class Manager → BTW lessons).' );
			$o->save();
			$wpdb->update( self::t_fees(), array( 'order_id' => $o->get_id() ), array( 'id' => $f['id'] ) );
			return $o;
		} catch ( Exception $e ) {
			return null;
		}
	}

	public static function order_paid( $order_id ) {
		global $wpdb;
		$o = wc_get_order( $order_id );
		if ( ! $o ) { return; }
		$fid = (int) $o->get_meta( '_sdsc_fee_id' );
		$f   = $fid ? self::fee( $fid ) : null;
		if ( ! $f || 'due' !== $f['status'] ) { return; }
		$wpdb->update( self::t_fees(), array( 'status' => 'paid', 'paid_at' => self::now(), 'paid_how' => 'Online (order #' . $o->get_order_number() . ')' ), array( 'id' => $fid ) );
		self::add_log( $f['lesson_id'], 'Fee paid online, order #' . $o->get_order_number() );
	}

	protected static function cancel_order( $f, $why ) {
		if ( $f['order_id'] && function_exists( 'wc_get_order' ) ) {
			$o = wc_get_order( $f['order_id'] );
			if ( $o && $o->needs_payment() ) { $o->update_status( 'cancelled', $why ); }
		}
	}

	/* ================================================================ messages */

	public static function reason_title( $r, $lang ) {
		$m = array(
			'late_cancel' => array( 'Late cancellation fee', 'Cargo por cancelación tardía' ),
			'no_show'     => array( 'Missed lesson fee', 'Cargo por no presentarse' ),
			'no_permit'   => array( 'No-permit fee', 'Cargo por no traer permiso' ),
		);
		return $m[ $r ][ 'es' === $lang ? 1 : 0 ] ?? 'Fee';
	}

	public static function when( $local, $lang, $with_time = true ) {
		$ts = self::ts( $local );
		if ( 'es' === $lang ) {
			$d = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );
			$m = array( '', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
			$s = $d[ (int) wp_date( 'w', $ts ) ] . ' ' . wp_date( 'j', $ts ) . ' de ' . $m[ (int) wp_date( 'n', $ts ) ] . ' de ' . wp_date( 'Y', $ts );
			return $with_time ? $s . ', ' . wp_date( 'g:i a', $ts ) : $s;
		}
		return wp_date( $with_time ? 'l, F j, Y \a\t g:i a' : 'l, F j, Y', $ts );
	}

	public static function lesson_label( $l, $lang ) {
		$n = (int) $l['lesson_no'];
		if ( $n > 3 ) { $n = 0; }
		if ( 'es' === $lang ) { return $n ? 'Clase práctica ' . $n . ' de 3' : 'Práctica adicional'; }
		return $n ? 'Behind-the-wheel lesson ' . $n . ' of 3' : 'Extra practice lesson';
	}

	public static function phone() {
		return SDSC_Mailer::info()['phones'][0];
	}

	/** A details table for the e-mail. */
	public static function facts( $rows ) {
		$h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-radius:12px;background:#f6f4ee">';
		foreach ( $rows as $r ) {
			$h .= '<tr><td style="padding:10px 14px;font:600 13px Arial,sans-serif;color:#5b646d;white-space:nowrap;vertical-align:top;width:34%">' . esc_html( $r[0] ) . '</td>'
				. '<td style="padding:10px 14px;font:15px/1.5 Arial,sans-serif;color:#15191d">' . $r[1] . '</td></tr>';
		}
		return $h . '</table>';
	}

	public static function box( $html, $tone = 'warn' ) {
		$bg = 'warn' === $tone ? '#fff8e1' : '#e7f5ec';
		$bd = 'warn' === $tone ? '#ffc20e' : '#00703c';
		return '<div style="margin:0 0 18px;padding:14px 16px;border-radius:12px;background:' . $bg . ';border-left:4px solid ' . $bd . ';font:14px/1.6 Arial,sans-serif;color:#15191d">' . $html . '</div>';
	}

	protected static function gcal( $l ) {
		$a = self::ts( $l['starts_at'] );
		$b = $a + (int) $l['minutes'] * 60;
		return add_query_arg( array(
			'action'   => 'TEMPLATE',
			'text'     => 'Driving lesson — Sam’s Driving School',
			'dates'    => gmdate( 'Ymd\THis\Z', $a ) . '/' . gmdate( 'Ymd\THis\Z', $b ),
			'location' => $l['place'],
			'details'  => 'Bring your valid learner’s permit. To cancel or change, call or text ' . self::phone() . '.',
		), 'https://calendar.google.com/calendar/render' );
	}

	/** [subject, html] — $kind: confirm | update | remind | cancelled | fee | waived */
	public static function message( $kind, $l, $f = null ) {
		$es    = 'es' === $l['lang'];
		$lang  = $es ? 'es' : 'en';
		$s     = self::settings();
		$first = $l['first_name'] ?: ( $es ? 'estudiante' : 'there' );
		$fee   = '$' . self::money( $f ? $f['amount'] : $s['fee'] );
		$hrs   = (int) $s['hours'];
		$dl    = self::when( self::deadline( $l ), $lang );
		$ph    = esc_html( self::phone() );
		$facts = self::facts( array_filter( array(
			array( $es ? 'Clase' : 'Lesson', esc_html( self::lesson_label( $l, $lang ) ) ),
			array( $es ? 'Fecha y hora' : 'Date & time', '<b>' . esc_html( self::when( $l['starts_at'], $lang ) ) . '</b>' ),
			array( $es ? 'Duración' : 'Length', esc_html( $es ? ( (int) $l['minutes'] / 60 ) . ' horas' : ( (int) $l['minutes'] / 60 ) . ' hours' ) ),
			array( $es ? 'Lugar de encuentro' : 'Meeting place', esc_html( $l['place'] ) . ( $s['directions'] && $l['place'] === $s['place'] ? '<br><span style="color:#5b646d">' . esc_html( $s['directions'] ) . '</span>' : '' ) ),
			$l['instructor'] ? array( $es ? 'Instructor' : 'Instructor', esc_html( $l['instructor'] ) ) : null,
		) ) );
		$policy = $es
			? '<b>Política de cancelación:</b> puede cancelar o cambiar esta clase sin costo hasta el <b>' . esc_html( $dl ) . '</b> (' . $hrs . ' horas antes), llamando o enviando un mensaje de texto al ' . $ph . '. Se cobra un cargo de <b>' . $fee . '</b> si cancela con menos de ' . $hrs . ' horas de aviso, si no se presenta, o si llega sin un permiso de aprendizaje válido.'
			: '<b>Cancellation policy:</b> you can cancel or change this lesson free of charge until <b>' . esc_html( $dl ) . '</b> (' . $hrs . ' hours before) by calling or texting ' . $ph . '. A <b>' . $fee . '</b> fee applies if you cancel with less than ' . $hrs . ' hours&rsquo; notice, do not come, or arrive without a valid learner&rsquo;s permit.';
		$bring = $es
			? '<p style="margin:0 0 16px">Traiga su <b>permiso de aprendizaje válido</b> — sin él la clase no se puede realizar. Nos encontramos en el lugar indicado; no ofrecemos servicio de recogida.</p>'
			: '<p style="margin:0 0 16px">Please bring your <b>valid learner&rsquo;s permit</b> — the lesson cannot take place without it. We meet at the place above; pick-up and drop-off are not offered.</p>';
		$sch = class_exists( 'SDSC_Schedule' );
		$btn = $sch ? '<p style="margin:0 0 6px;text-align:center;font:600 14px Arial,sans-serif">' . ( $es ? '¿Estará allí? Confírmenos con un toque:' : 'Will you be there? Let us know with one tap:' ) . '</p>' . SDSC_Schedule::buttons_html( 'btw', $l['id'], '', $l['email'], $lang ) : '';
		$cal = $sch ? SDSC_Schedule::cal_link_html( $l['email'], $lang ) : '<p style="margin:0 0 16px;text-align:center"><a href="' . esc_url( self::gcal( $l ) ) . '" style="color:#00703c;font-weight:700">' . ( $es ? 'Agregar a Google Calendar' : 'Add to Google Calendar' ) . '</a></p>';
		$hi  = '<p style="margin:0 0 16px">' . ( $es ? 'Hola ' : 'Hi ' ) . esc_html( $first ) . ',</p>';
		$lnk = self::my_url();

		switch ( $kind ) {
			case 'confirm':
			case 'update':
				$subj = 'update' === $kind
					? ( $es ? 'Cambio en su clase de manejo — ' : 'Your driving lesson has changed — ' ) . self::when( $l['starts_at'], $lang )
					: ( $es ? 'Clase de manejo confirmada — ' : 'Driving lesson confirmed — ' ) . self::when( $l['starts_at'], $lang );
				$head = 'update' === $kind ? ( $es ? 'Su clase ha cambiado' : 'Your lesson has changed' ) : ( $es ? '¡Su clase está confirmada!' : 'Your lesson is confirmed!' );
				$body = $hi . '<p style="margin:0 0 16px">' . ( 'update' === $kind
						? ( $es ? 'Estos son los nuevos datos de su clase:' : 'Here are the new details of your lesson:' )
						: ( $es ? 'Le esperamos para su clase práctica de manejo:' : 'We look forward to seeing you for your behind-the-wheel lesson:' ) ) . '</p>'
					. $facts . $bring . self::box( $policy ) . $btn . $cal;
				return array( $subj, self::wrap( $head, $body, '🚗' ) );

			case 'remind':
				$subj = $es ? 'Recordatorio: su clase de manejo es el ' . self::when( $l['starts_at'], $lang, false ) : 'Reminder: your driving lesson on ' . self::when( $l['starts_at'], $lang, false );
				$body = $hi . '<p style="margin:0 0 16px">' . ( $es ? 'Le recordamos su próxima clase:' : 'A quick reminder of your next lesson:' ) . '</p>' . $facts
					. self::box( $es
						? 'Si necesita cancelar o cambiar la clase, avísenos <b>antes del ' . esc_html( $dl ) . '</b> llamando o enviando un texto al ' . $ph . '. Después de ese momento se cobra un cargo de <b>' . $fee . '</b>.'
						: 'If you need to cancel or change it, please tell us <b>before ' . esc_html( $dl ) . '</b> by calling or texting ' . $ph . '. After that a <b>' . $fee . '</b> fee applies.' )
					. $bring . $btn . $cal;
				return array( $subj, self::wrap( $es ? 'Recordatorio de su clase' : 'Lesson reminder', $body, '⏰' ) );

			case 'last':
				$subj = $es ? 'Último aviso: cancelación gratis hasta las ' . wp_date( 'g:i a', self::ts( self::deadline( $l ) ) ) : 'Last call: free cancellation until ' . wp_date( 'g:i a', self::ts( self::deadline( $l ) ) );
				$body = $hi . '<p style="margin:0 0 16px">' . ( $es ? 'Todavía no confirmó su clase:' : 'You haven’t confirmed your lesson yet:' ) . '</p>' . $facts
					. self::box( $es
						? 'Puede cancelar sin costo solo hasta el <b>' . esc_html( $dl ) . '</b>. Después se cobra <b>' . $fee . '</b> si cancela, no se presenta o llega sin permiso válido.'
						: 'You can cancel free of charge only until <b>' . esc_html( $dl ) . '</b>. After that a <b>' . $fee . '</b> fee applies if you cancel, don’t come, or arrive without a valid permit.' )
					. $btn;
				return array( $subj, self::wrap( $es ? '¿Estará allí?' : 'Will you be there?', $body, '⏳' ) );

			case 'soon':
				$subj = $es ? 'Su clase de manejo empieza en 1 hora' : 'Your driving lesson starts in 1 hour';
				$body = $hi . $facts . $bring . $btn;
				return array( $subj, self::wrap( $es ? 'Le esperamos en una hora' : 'See you in one hour', $body, '🚗' ) );

			case 'cancelled':
				$subj = $es ? 'Su clase de manejo fue cancelada' : 'Your driving lesson has been cancelled';
				$body = $hi . '<p style="margin:0 0 16px">' . ( $es
						? 'Cancelamos su clase del <b>' . esc_html( self::when( $l['starts_at'], $lang ) ) . '</b>. Usted nos avisó a tiempo, así que <b>no hay ningún cargo</b>.'
						: 'Your lesson on <b>' . esc_html( self::when( $l['starts_at'], $lang ) ) . '</b> has been cancelled. You told us in time, so there is <b>no fee</b>.' ) . '</p>'
					. '<p style="margin:0 0 16px">' . ( $es ? 'Para reservar una nueva fecha, llame o envíe un texto al ' . $ph . '.' : 'To book a new date, call or text ' . $ph . '.' ) . '</p>';
				return array( $subj, self::wrap( $es ? 'Clase cancelada' : 'Lesson cancelled', $body, '📅' ) );

			case 'fee':
				$url  = $f ? self::pay_url( $f ) : '';
				$why  = self::reason_text( $f['reason'], $lang );
				$subj = self::reason_title( $f['reason'], $lang ) . ' — ' . $fee;
				$body = $hi . '<p style="margin:0 0 16px">' . ( $es
						? 'Según nuestra política de clases prácticas, se aplica un cargo de <b>' . $fee . '</b> a su clase del <b>' . esc_html( self::when( $l['starts_at'], $lang ) ) . '</b>.'
						: 'Under our behind-the-wheel policy, a <b>' . $fee . '</b> fee applies to your lesson on <b>' . esc_html( self::when( $l['starts_at'], $lang ) ) . '</b>.' ) . '</p>'
					. self::facts( array( array( $es ? 'Motivo' : 'Reason', esc_html( $why ) ), array( $es ? 'Monto' : 'Amount', '<b>' . $fee . '</b>' ) ) )
					. '<p style="margin:0 0 16px">' . ( $url
						? ( $es ? 'Puede pagarlo en línea con el botón de abajo, o en nuestra oficina.' : 'You can pay it online with the button below, or at our office.' )
						: ( $es ? 'Puede pagarlo en nuestra oficina o llamando al ' . $ph . '.' : 'You can pay it at our office or by calling ' . $ph . '.' ) ) . '</p>'
					. '<p style="margin:0 0 16px">' . ( $es ? '¿Cree que se trata de un error? Responda a este correo o llámenos al ' . $ph . '.' : 'Think this is a mistake? Reply to this e-mail or call us at ' . $ph . '.' ) . '</p>';
				return array( $subj, self::wrap( self::reason_title( $f['reason'], $lang ), $body, '🧾', $url ? ( $es ? 'Pagar ' . $fee : 'Pay ' . $fee ) : '', $url ) );

			case 'waived':
				$subj = $es ? 'Buenas noticias: no tiene que pagar el cargo' : 'Good news: your fee has been waived';
				$body = $hi . '<p style="margin:0 0 16px">' . ( $es
						? 'Como cortesía, Sam&rsquo;s Driving School <b>perdonó el cargo de ' . $fee . '</b> de su clase del ' . esc_html( self::when( $l['starts_at'], $lang ) ) . '. <b>No tiene que pagar nada.</b>'
						: 'As a courtesy, Sam&rsquo;s Driving School has <b>waived the ' . $fee . ' fee</b> for your lesson on ' . esc_html( self::when( $l['starts_at'], $lang ) ) . '. <b>There is nothing to pay.</b>' ) . '</p>'
					. '<p style="margin:0 0 16px">' . ( $es
						? 'Esto queda anotado en su historial. Le recordamos que futuras cancelaciones con menos de ' . $hrs . ' horas de aviso o ausencias pueden tener el cargo.'
						: 'This is noted on your record. Please remember that future cancellations with less than ' . $hrs . ' hours&rsquo; notice, or missed lessons, may be charged.' ) . '</p>';
				return array( $subj, self::wrap( $es ? 'Cargo perdonado' : 'Fee waived', $body, '💚' ) );
		}
		return array( '', '' );
	}

	protected static function wrap( $heading, $body, $icon, $btn = '', $url = '' ) {
		$h = SDSC_Outreach::email_body( $heading, '', '', '', $icon ) . $body;
		if ( $btn && $url ) { $h .= SDSC_Outreach::email_body( '', '', $btn, $url ); }
		if ( $l = self::my_url() ) {
				$h .= '<p style="margin:18px 0 0;font:13px Arial,sans-serif;color:#5b646d;text-align:center"><a href="' . esc_url( $l ) . '" style="color:#5b646d">See all your lessons</a></p>';
		}
		return $h;
	}

	/** Text message version (for the "Text" button). */
	public static function sms( $kind, $l, $f = null ) {
		$es  = 'es' === $l['lang'];
		$s   = self::settings();
		$fee = '$' . self::money( $f ? $f['amount'] : $s['fee'] );
		$w   = self::when( $l['starts_at'], $es ? 'es' : 'en' );
		$dl  = self::when( self::deadline( $l ), $es ? 'es' : 'en' );
		$n = $l['first_name'];
		$where = $l['place'] . ( $s['directions'] && $l['place'] === $s['place'] ? ' (' . $s['directions'] . ')' : '' );
		$u = class_exists( 'SDSC_Schedule' ) ? ' ' . ( $es ? 'Confirme: ' : 'Confirm: ' ) . SDSC_Schedule::att_url( 'btw', $l['id'], '', $l['email'] ) : '';
		if ( 'remind' === $kind ) {
			return $es
				? 'Hola ' . $n . ', le recordamos su clase de manejo: ' . $w . '. Para cancelar sin costo avísenos antes del ' . $dl . ' al ' . self::phone() . '. – Sam’s Driving School'
				: 'Hi ' . $n . ', reminder of your driving lesson: ' . $w . '. To cancel free of charge, tell us before ' . $dl . ' at ' . self::phone() . '.' . $u . ' – Sam’s Driving School';
		}
		if ( 'last' === $kind ) {
			return $es ? 'Hola ' . $n . ', su clase es el ' . $w . '. Cancelación gratis solo hasta el ' . $dl . '; después ' . $fee . '.' . $u . ' – Sam’s Driving School'
				: 'Hi ' . $n . ', your lesson is ' . $w . '. Free cancellation only until ' . $dl . '; after that ' . $fee . '.' . $u . ' – Sam’s Driving School';
		}
		if ( 'today' === $kind ) {
			return $es ? 'Hola ' . $n . ', hoy tiene su clase de manejo a las ' . wp_date( 'g:i a', self::ts( $l['starts_at'] ) ) . ' en ' . $where . '. Traiga su permiso de aprendizaje.' . $u . ' – Sam’s Driving School'
				: 'Hi ' . $n . ', your driving lesson is today at ' . wp_date( 'g:i a', self::ts( $l['starts_at'] ) ) . ' at ' . $where . '. Please bring your learner’s permit.' . $u . ' – Sam’s Driving School';
		}
		if ( 'soon' === $kind ) {
			return $es ? 'Hola ' . $n . ', su clase de manejo empieza en 1 hora en ' . $where . '. Traiga su permiso.' . $u . ' – Sam’s Driving School'
				: 'Hi ' . $n . ', your driving lesson starts in 1 hour at ' . $where . '. Bring your permit.' . $u . ' – Sam’s Driving School';
		}
		if ( 'cancelled' === $kind ) {
			return $es ? 'Hola ' . $n . ', su clase del ' . $w . ' fue cancelada sin cargo. Para reservar otra fecha llame o escriba al ' . self::phone() . '. – Sam’s Driving School'
				: 'Hi ' . $n . ', your lesson on ' . $w . ' is cancelled with no fee. To book a new date, call or text ' . self::phone() . '. – Sam’s Driving School';
		}
		if ( 'waived' === $kind && $f ) {
			return $es ? 'Hola ' . $n . ', buenas noticias: perdonamos el cargo de ' . $fee . ' de su clase del ' . $w . '. No tiene que pagar nada. – Sam’s Driving School'
				: 'Hi ' . $n . ', good news: we waived the ' . $fee . ' fee for your lesson on ' . $w . '. There is nothing to pay. – Sam’s Driving School';
		}
		if ( 'update' === $kind ) {
			return $es ? 'Hola ' . $n . ', su clase de manejo cambió a: ' . $w . ', en ' . $where . '. Cancelación gratis hasta el ' . $dl . '; después se cobra ' . $fee . '. – Sam’s Driving School'
				: 'Hi ' . $n . ', your driving lesson has moved to ' . $w . ' at ' . $where . '. Free cancellation until ' . $dl . '; after that a ' . $fee . ' fee applies. – Sam’s Driving School';
		}
		if ( 'fee' === $kind && $f ) {
			return $es
				? 'Hola ' . $l['first_name'] . ', según nuestra política se aplica un cargo de ' . $fee . ' a su clase del ' . $w . ' (' . self::reason_text( $f['reason'], 'es' ) . '). Le enviamos los detalles por correo. – Sam’s Driving School'
				: 'Hi ' . $l['first_name'] . ', under our policy a ' . $fee . ' fee applies to your lesson on ' . $w . ' (' . self::reason_text( $f['reason'], 'en' ) . '). Details are in your e-mail. – Sam’s Driving School';
		}
		$u = class_exists( 'SDSC_Schedule' ) ? ' ' . ( $es ? 'Confirme su asistencia: ' : 'Confirm you’ll be there: ' ) . SDSC_Schedule::att_url( 'btw', $l['id'], '', $l['email'] ) : '';
		return $es
			? 'Hola ' . $l['first_name'] . ', su clase de manejo está confirmada: ' . $w . ', en ' . $where . '. Traiga su permiso de aprendizaje. Puede cancelar sin costo hasta el ' . $dl . '; después se cobra ' . $fee . '.' . $u . ' – Sam’s Driving School'
			: 'Hi ' . $l['first_name'] . ', your driving lesson is confirmed: ' . $w . ' at ' . $where . '. Please bring your learner’s permit. Free cancellation until ' . $dl . '; after that a ' . $fee . ' fee applies.' . $u . ' – Sam’s Driving School';
	}

	/** Send one of the e-mails above and note it on the lesson. */
	public static function mail( $kind, $l, $f = null ) {
		if ( ! $l['email'] || ! is_email( $l['email'] ) ) { return false; }
		list( $subj, $html ) = self::message( $kind, $l, $f );
		$ok = (bool) SDSC_Mailer::send( $l['email'], $subj, $html, 'info' );
		$names = array( 'confirm' => 'Confirmation', 'update' => 'Change notice', 'remind' => 'Reminder', 'last' => 'Last-call reminder', 'soon' => 'One-hour reminder', 'cancelled' => 'Cancellation notice', 'fee' => 'Fee notice', 'waived' => 'Waiver notice' );
		self::add_log( $l['id'], ( $names[ $kind ] ?? $kind ) . ' e-mail ' . ( $ok ? 'sent to ' . $l['email'] : 'could not be sent' ) );
		// The same news by text, when Twilio is switched on for lessons.
		if ( class_exists( 'SDSC_SMS' ) && SDSC_SMS::settings()['lessons'] && $l['phone'] ) {
			$labels  = array( 'confirm' => '🚗 Lesson confirmation', 'update' => '🚗 Lesson changed', 'remind' => '⏰ Lesson reminder', 'last' => '⏳ Last free-cancel day', 'soon' => '⏰ Lesson in 1 hour', 'cancelled' => '📅 Lesson cancelled', 'fee' => '🧾 Fee notice', 'waived' => '💚 Fee waived' );
			$expires = in_array( $kind, array( 'confirm', 'update', 'remind', 'last', 'soon' ), true ) ? $l['starts_at'] : wp_date( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
			$ctx     = 'lesson:' . $l['id'] . ':' . $kind . ( in_array( $kind, array( 'confirm', 'update' ), true ) ? ':' . time() : '' );
			$t       = SDSC_SMS::deliver( $l['phone'], self::sms( $kind, $l, $f ), $ctx, self::name( $l ), $labels[ $kind ] ?? $kind, $expires );
			self::add_log( $l['id'], ( $names[ $kind ] ?? $kind ) . ' text: ' . $t );
		}
		return $ok;
	}

	/* ================================================================ cron */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 120, 'hourly', self::HOOK ); }
		if ( get_option( 'sdsc_lessons_flush' ) ) { delete_option( 'sdsc_lessons_flush' ); flush_rewrite_rules( false ); }
	}

	/** Reminders, one day before the free-cancellation deadline (only for lessons booked before that). */
	public static function tick() {
		global $wpdb;
		$s = self::settings();
		if ( ! $s['remind'] || ! SDSC_Mailer::ready() ) { return; }
		$now  = time();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_lessons() . " WHERE status = 'booked' AND remind_at IS NULL AND conf_at IS NOT NULL AND starts_at > %s ORDER BY starts_at LIMIT 40", self::now() ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $l ) {
			$dl   = self::ts( $l['starts_at'] ) - (int) $s['hours'] * HOUR_IN_SECONDS;
			$from = $dl - DAY_IN_SECONDS;
			if ( $now < $from || $now >= $dl - HOUR_IN_SECONDS ) { continue; }
			if ( self::ts( $l['conf_at'] ) > $from ) { continue; }   // booked recently: the confirmation was the reminder
			if ( self::mail( 'remind', $l ) ) {
				$wpdb->update( self::t_lessons(), array( 'remind_at' => self::now() ), array( 'id' => $l['id'] ) );
			}
		}
	}

	/* ================================================================ admin */

	public static function can() {
		return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'lessons' ) : current_user_can( 'manage_options' );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'BTW lessons & fees', 'BTW lessons & fees', 'sdsc_access_lessons', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** Lessons whose time has passed but nobody marked the outcome. */
	public static function needs_outcome() {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_lessons() . " WHERE status = 'booked' AND starts_at < %s ORDER BY starts_at", self::now() ), ARRAY_A ); // phpcs:ignore
	}

	public static function badge() {
		global $submenu;
		if ( ! self::can() ) { return; }
		$n = count( self::needs_outcome() );
		if ( ! $n ) { return; }
		foreach ( (array) ( $submenu['sdsc-dashboard'] ?? array() ) as $i => $it ) {
			if ( self::PAGE === $it[2] ) { $submenu['sdsc-dashboard'][ $i ][0] .= ' <span class="update-plugins count-' . $n . '"><span class="plugin-count">' . $n . '</span></span>'; } // phpcs:ignore
		}
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) { return; }
		wp_enqueue_style( 'sdsc-outreach', SDSC_URL . 'assets/outreach.css', array(), SDSC_VERSION );
		wp_enqueue_style( 'sdsc-lessons', SDSC_URL . 'assets/lessons.css', array( 'sdsc-outreach' ), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-lessons', SDSC_URL . 'assets/lessons.js', array(), SDSC_VERSION, true );
		$people = array();
		if ( class_exists( 'SDSC_Outreach' ) ) {
			foreach ( SDSC_Outreach::contacts() as $c ) {
				$people[] = array( $c['k'], $c['first'], $c['last'], $c['email'], $c['phone'], $c['lang'] );
			}
		}
		wp_localize_script( 'sdsc-lessons', 'SDSC_LS', array( 'people' => $people, 'asg' => class_exists( 'SDSC_Fleet' ) ? SDSC_Fleet::assignment_map() : array() ) );
	}

	protected static function url( $tab = 'schedule', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	protected static function form_open( $action, $class = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">'
			. wp_nonce_field( 'sdsc_ls', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_ls_' . esc_attr( $action ) . '">';
	}

	protected static function chip( $text, $tone = '' ) {
		return '<span class="sdo-chip' . ( $tone ? ' sdo-chip--' . $tone : '' ) . '">' . esc_html( $text ) . '</span>';
	}

	protected static function status_chip( $st ) {
		$tone = array( 'booked' => '', 'done' => 'ok', 'cancelled' => 'off', 'late_cancel' => 'warn', 'no_show' => 'warn', 'no_permit' => 'warn' )[ $st ] ?? '';
		return '<span class="sdo-chip sdl-st sdl-st--' . esc_attr( $st ) . '">' . esc_html( self::statuses()[ $st ][0] ?? $st ) . '</span>';
	}

	protected static function fee_chip( $f ) {
		$m = '$' . self::money( $f['amount'] );
		if ( 'paid' === $f['status'] ) { return '<span class="sdo-chip sdo-chip--ok">' . esc_html( 'Fee ' . $m . ' · paid' ) . '</span>'; }
		if ( 'waived' === $f['status'] ) { return '<span class="sdo-chip sdl-waived">' . esc_html( 'Fee ' . $m . ' · waived' ) . '</span>'; }
		return '<span class="sdo-chip sdl-due">' . esc_html( 'Fee ' . $m . ' · due' ) . '</span>';
	}

	protected static function sms_link( $phone, $text, $label ) {
		$to = class_exists( 'SDSC_Outreach' ) ? SDSC_Outreach::phone_e164( $phone ) : '';
		if ( ! $to ) { return ''; }
		return '<a class="sdo-btn sdo-btn--ghost sdl-sms" data-sms="' . esc_attr( $to ) . '" data-body="' . esc_attr( $text ) . '" href="' . esc_attr( 'sms:' . $to . '?&body=' . rawurlencode( $text ) ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		$tabs = array( 'schedule' => 'Schedule', 'book' => 'Book a lesson', 'fees' => 'Fees', 'student' => 'Student history', 'settings' => 'Settings' );
		$df   = class_exists( 'SDSC_DriveFlow' ) && SDSC_DriveFlow::on();
		if ( $df ) { unset( $tabs['book'] ); SDSC_DriveFlow::sync(); }
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'schedule'; // phpcs:ignore
		if ( isset( $_GET['id'] ) && 'schedule' === $tab ) { $tab = 'lesson'; } // phpcs:ignore
		$due = self::count_fees( 'due' );
		?>
		<div class="wrap sdo sdl">
			<h1 class="sdo-title"><span class="sdo-title-ic" aria-hidden="true">🚗</span> Behind-the-wheel lessons &amp; fees</h1>
			<?php if ( isset( $_GET['msg'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['err'] ) ) : // phpcs:ignore ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<?php if ( ! SDSC_Mailer::ready() ) : ?>
				<div class="sdo-hold"><b>E-mails are on hold.</b> Confirmations and fee notices are e-mailed once the school mailbox is connected on the Email page. You can still book lessons and text students.</div>
			<?php endif; ?>
			<nav class="sdo-tabs" aria-label="Sections">
				<?php foreach ( $tabs as $k => $label ) : $on = $k === $tab || ( 'lesson' === $tab && 'schedule' === $k ); ?>
					<a class="sdo-tab<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo esc_url( self::url( $k ) ); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><?php
					if ( 'schedule' === $k && ( $n = count( self::needs_outcome() ) ) ) { echo ' <span class="sdo-count" title="Lessons waiting for an outcome">' . (int) $n . '</span>'; }
					if ( 'fees' === $k && $due ) { echo ' <span class="sdo-count" title="Fees not paid yet">' . (int) $due . '</span>'; }
					?></a>
				<?php endforeach; ?>
				<?php if ( $df ) : ?><a class="sdo-tab" href="<?php echo esc_url( SDSC_DriveFlow::calendar_url() ); ?>">Book or move a lesson &rarr; DriveFlow calendar</a><?php endif; ?>
			</nav>
			<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
		</div>
		<?php
	}

	protected static function count_fees( $st ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_fees() . ' WHERE status = %s', $st ) ); // phpcs:ignore
	}

	protected static function name( $l ) {
		return trim( $l['first_name'] . ' ' . $l['last_name'] ) ?: $l['email'];
	}

	/* ---------------- Schedule */

	protected static function tab_schedule() {
		global $wpdb;
		$late = self::needs_outcome();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_lessons() . " WHERE starts_at >= %s AND status = 'booked' ORDER BY starts_at LIMIT 200", self::now() ), ARRAY_A ); // phpcs:ignore
		$past = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_lessons() . " WHERE status <> 'booked' AND starts_at >= %s ORDER BY starts_at DESC LIMIT 30", wp_date( 'Y-m-d H:i:s', time() - 21 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
		?>
		<p class="sdo-lead">Book each lesson here after you agree on it with the student by phone or text. The student gets a confirmation e-mail with the time, the meeting place and the exact moment until which they can cancel for free. After the lesson, mark how it went &mdash; <b>No-show</b> and <b>No permit</b> add the fee; you can waive any fee, and the waiver stays on both your record and the student&rsquo;s.</p>
		<p class="sdo-actions"><a class="sdo-btn" href="<?php echo esc_url( class_exists( 'SDSC_DriveFlow' ) && SDSC_DriveFlow::on() ? SDSC_DriveFlow::calendar_url() : self::url( 'book' ) ); ?>">+ Book a lesson</a><?php if ( class_exists( 'SDSC_Fleet' ) && SDSC_Fleet::can() ) : ?><a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-board' ) ); ?>">Instructor &amp; car board</a><a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-board&tab=times' ) ); ?>">Find free times</a><?php endif; ?></p>

		<?php if ( $late ) : ?>
			<section class="sdo-sec">
				<h2><span aria-hidden="true">✅</span> How did these lessons go? <span class="sdo-n"><?php echo count( $late ); ?></span></h2>
				<div class="sdl-list"><?php foreach ( $late as $l ) { self::row( $l, true ); } ?></div>
			</section>
		<?php endif; ?>

		<section class="sdo-sec">
			<h2><span aria-hidden="true">📅</span> Upcoming lessons <span class="sdo-n"><?php echo count( $rows ); ?></span></h2>
			<?php if ( ! $rows ) : ?><p class="sdo-empty">No lessons booked yet.</p><?php else : ?>
				<?php $day = ''; foreach ( $rows as $l ) :
					$d = wp_date( 'l, F j', self::ts( $l['starts_at'] ) );
					if ( $d !== $day ) { echo ( $day ? '</div>' : '' ) . '<h3 class="sdl-day">' . esc_html( $d ) . '</h3><div class="sdl-list">'; $day = $d; }
					self::row( $l );
				endforeach; echo '</div>'; ?>
			<?php endif; ?>
		</section>

		<?php if ( $past ) : ?>
			<section class="sdo-sec">
				<h2><span aria-hidden="true">🗂️</span> Last three weeks</h2>
				<div class="sdl-list"><?php foreach ( $past as $l ) { self::row( $l ); } ?></div>
			</section>
		<?php endif; ?>
		<?php
	}

	protected static function row( $l, $ask = false ) {
		$fees = self::fees_for_lesson( $l['id'] );
		$dl   = self::ts( self::deadline( $l ) );
		$open = 'booked' === $l['status'] && self::ts( $l['starts_at'] ) > time();
		?>
		<article class="sdl-row">
			<div class="sdl-when"><b><?php echo esc_html( wp_date( 'g:i a', self::ts( $l['starts_at'] ) ) ); ?></b><span><?php echo esc_html( wp_date( 'M j', self::ts( $l['starts_at'] ) ) ); ?></span></div>
			<div class="sdl-who">
				<a href="<?php echo esc_url( self::url( 'schedule', array( 'id' => $l['id'] ) ) ); ?>"><b><?php echo esc_html( self::name( $l ) ); ?></b></a>
				<span class="sdo-muted"><?php echo esc_html( self::lesson_label( $l, 'en' ) . ( $l['instructor'] ? ' · ' . $l['instructor'] : '' ) ); ?></span>
				<span class="sdl-chips">
					<?php
					echo self::status_chip( $l['status'] ); // phpcs:ignore
					foreach ( $fees as $f ) { echo self::fee_chip( $f ); } // phpcs:ignore
					if ( $open ) {
						echo time() < $dl ? self::chip( 'Free cancel until ' . wp_date( 'D g:i a', $dl ) ) : self::chip( 'Inside ' . (int) self::settings()['hours'] . ' h — fee applies', 'warn' ); // phpcs:ignore
					}
					if ( 'booked' === $l['status'] ) {
						echo $l['conf_at'] ? self::chip( 'E-mailed', '' ) : self::chip( 'Not e-mailed', 'off' ); // phpcs:ignore
						$rv = class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::row( 'btw', $l['id'], '', $l['email'] ) : null;
						if ( $rv && 'yes' === $rv['rsvp'] ) { echo self::chip( '✓ Student confirmed', 'ok' ); } // phpcs:ignore
						elseif ( $rv && 'no' === $rv['rsvp'] ) { echo self::chip( 'Student says can’t come · ' . wp_date( 'D g:i a', self::ts( $rv['rsvp_at'] ) ), 'warn' ); } // phpcs:ignore
						else { echo self::chip( 'No answer yet', 'off' ); } // phpcs:ignore
					}
					?>
				</span>
			</div>
			<div class="sdl-act">
				<?php if ( $ask ) : ?>
					<?php echo self::form_open( 'outcome', 'sdl-inline' ); // phpcs:ignore ?>
						<input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
						<button class="sdo-btn sdo-btn--go" name="o" value="done">Completed</button>
						<button class="sdo-btn sdo-btn--ghost" name="o" value="no_show">No-show</button>
						<button class="sdo-btn sdo-btn--ghost" name="o" value="no_permit">No permit</button>
					</form>
				<?php else : ?>
					<a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::url( 'schedule', array( 'id' => $l['id'] ) ) ); ?>">Open</a>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/* ---------------- One lesson */

	protected static function tab_lesson() {
		$l = self::lesson( (int) $_GET['id'] ); // phpcs:ignore
		if ( ! $l ) { echo '<p class="sdo-empty">Lesson not found.</p>'; return; }
		$fees   = self::fees_for_lesson( $l['id'] );
		$future = self::ts( $l['starts_at'] ) > time();
		$dl     = self::ts( self::deadline( $l ) );
		$log    = json_decode( (string) $l['log'], true );
		$hrs    = (int) self::settings()['hours'];
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; Schedule</a></p>
		<div class="sdo-two sdl-two">
			<div>
				<section class="sdo-panel">
					<h2><?php echo esc_html( self::name( $l ) ); ?> <?php echo self::status_chip( $l['status'] ); // phpcs:ignore ?></h2>
					<dl class="sdl-dl">
						<dt>Lesson</dt><dd><?php echo esc_html( self::lesson_label( $l, 'en' ) ); ?></dd>
						<dt>When</dt><dd><b><?php echo esc_html( self::when( $l['starts_at'], 'en' ) ); ?></b> · <?php echo esc_html( ( (int) $l['minutes'] / 60 ) . ' h' ); ?></dd>
						<dt>Free cancel until</dt><dd><?php echo esc_html( self::when( self::deadline( $l ), 'en' ) ); ?></dd>
						<dt>Meeting place</dt><dd><?php echo esc_html( $l['place'] ); ?></dd>
						<dt>Instructor</dt><dd><?php echo $l['instructor'] ? esc_html( $l['instructor'] ) : '<span class="sdo-warn">Not assigned yet</span>'; ?></dd>
						<?php if ( class_exists( 'SDSC_Fleet' ) && SDSC_Fleet::cars() ) : $car = SDSC_Fleet::res( $l['car_id'] ?? 0 ); ?><dt>Car</dt><dd><?php echo $car ? esc_html( SDSC_Fleet::label( $car ) ) : '<span class="sdo-warn">Not chosen yet</span>'; ?></dd><?php endif; ?>
						<dt>Learner&rsquo;s permit</dt><dd><?php echo esc_html( array( 'yes' => 'Has a permit', 'no' => 'No permit yet', '' => 'Not asked' )[ $l['permit'] ?? '' ] ?? '' ); ?><?php echo ! empty( $l['practice'] ) ? ' · Practice: ' . esc_html( $l['practice'] ) : ''; ?></dd>
						<dt>Student</dt><dd><?php if ( $l['email'] ) : ?><a href="<?php echo esc_url( self::url( 'student', array( 'email' => $l['email'] ) ) ); ?>"><?php echo esc_html( $l['email'] ); ?></a><?php else : ?><span class="sdo-muted">no e-mail</span><?php endif; ?><?php echo $l['phone'] ? ' · ' . esc_html( SDSC_Outreach::phone_nice( $l['phone'] ) ) : ''; ?> · <?php echo 'es' === $l['lang'] ? 'Español' : 'English'; ?></dd>
						<?php if ( $l['notice_at'] ) : ?><dt>Student told us</dt><dd><?php echo esc_html( self::when( $l['notice_at'], 'en' ) ); ?></dd><?php endif; ?>
						<?php if ( $l['note'] ) : ?><dt>Note</dt><dd><?php echo nl2br( esc_html( $l['note'] ) ); ?></dd><?php endif; ?>
					</dl>
					<div class="sdo-actions">
						<?php
						echo self::sms_link( $l['phone'], self::sms( 'confirm', $l ), 'Text confirmation' ); // phpcs:ignore
						if ( 'booked' === $l['status'] ) {
							echo self::form_open( 'resend', 'sdl-inline' ) . '<input type="hidden" name="id" value="' . (int) $l['id'] . '"><input type="hidden" name="what" value="confirm"><button class="sdo-btn sdo-btn--ghost">' . ( $l['conf_at'] ? 'E-mail confirmation again' : 'E-mail confirmation' ) . '</button></form>'; // phpcs:ignore
						}
						?>
					</div>
				</section>

				<?php foreach ( $fees as $f ) : self::fee_panel( $f, $l ); endforeach; ?>

				<?php if ( 'booked' === $l['status'] ) : ?>
					<?php if ( ! $future ) : ?>
						<section class="sdo-panel">
							<h2>How did the lesson go?</h2>
							<?php echo self::form_open( 'outcome', 'sdl-inline' ); // phpcs:ignore ?>
								<input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
								<button class="sdo-btn sdo-btn--go" name="o" value="done">Completed</button>
								<button class="sdo-btn sdo-btn--ghost" name="o" value="no_show">No-show (fee)</button>
								<button class="sdo-btn sdo-btn--ghost" name="o" value="no_permit">No valid permit (fee)</button>
							</form>
						</section>
					<?php else : ?>
						<section class="sdo-panel">
							<h2>Cancel this lesson</h2>
							<?php echo self::form_open( 'cancel', 'sdl-form' ); // phpcs:ignore ?>
								<input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
								<?php $rv = class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::row( 'btw', $l['id'], '', $l['email'] ) : null; $said = $rv && 'no' === $rv['rsvp'] ? self::ts( $rv['rsvp_at'] ) : time(); ?>
								<?php if ( $rv && 'no' === $rv['rsvp'] ) : ?><p class="sdo-warn">The student said they can&rsquo;t come (via the link) on <?php echo esc_html( wp_date( 'l, M j, g:i a', $said ) ); ?><?php echo $rv['note'] ? ' — ' . esc_html( $rv['note'] ) : ''; ?>.</p><?php endif; ?>
								<label class="sdo-f"><span>When did the student tell us?</span>
									<input type="datetime-local" name="notice" value="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i', $said ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>" required data-deadline="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i', $dl ) ); ?>" class="sdl-notice"></label>
								<p class="sdl-verdict" aria-live="polite" data-ok="In time — no fee. The student gets a cancellation notice." data-late="<?php echo esc_attr( 'Less than ' . $hrs . ' hours before the lesson — the $' . self::money( self::settings()['fee'] ) . ' fee is added. You can waive it right after.' ); ?>"></p>
								<label class="sdo-f"><span>Note (optional)</span><input type="text" name="note" placeholder="e.g. sick, texted at 9:15"></label>
								<label class="sdo-check"><input type="checkbox" name="mail" value="1" checked> E-mail the student</label>
								<p><button class="sdo-btn sdo-btn--danger">Cancel the lesson</button></p>
							</form>
						</section>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<div>
				<?php if ( ! empty( $l['df_id'] ) ) : ?>
					<section class="sdo-panel">
						<h2>Booked in DriveFlow</h2>
						<p>Date, time, instructor and car are changed on the DriveFlow calendar (drag the lesson). The student is e-mailed the new details automatically; cancelling here also cancels it in DriveFlow.</p>
						<p><a class="sdo-btn" href="<?php echo esc_url( SDSC_DriveFlow::calendar_url() ); ?>">Open the DriveFlow calendar</a></p>
					</section>
				<?php elseif ( 'booked' === $l['status'] && $future ) : ?>
					<section class="sdo-panel">
						<h2>Change date or details</h2>
						<?php self::lesson_form( $l ); ?>
					</section>
				<?php endif; ?>
				<section class="sdo-panel">
					<h2>History</h2>
					<ol class="sdl-log">
						<?php foreach ( array_reverse( is_array( $log ) ? $log : array() ) as $e ) : ?>
							<li><span class="sdo-muted"><?php echo esc_html( wp_date( 'M j, g:i a', self::ts( $e['t'] ) ) . ' · ' . $e['by'] ); ?></span><?php echo esc_html( $e['e'] ); ?></li>
						<?php endforeach; ?>
					</ol>
				</section>
			</div>
		</div>
		<?php
	}

	protected static function fee_panel( $f, $l ) {
		$by = $f['waived_by'] ? get_userdata( $f['waived_by'] ) : null;
		?>
		<section class="sdo-panel sdl-fee sdl-fee--<?php echo esc_attr( $f['status'] ); ?>">
			<h2>🧾 <?php echo esc_html( self::reason_title( $f['reason'], 'en' ) ); ?> — $<?php echo esc_html( self::money( $f['amount'] ) ); ?> <?php echo self::fee_chip( $f ); // phpcs:ignore ?></h2>
			<p class="sdo-muted"><?php echo esc_html( self::reason_text( $f['reason'] ) . ' · added ' . wp_date( 'M j, g:i a', self::ts( $f['created_at'] ) ) ); ?></p>
			<?php if ( 'waived' === $f['status'] ) : ?>
				<p><b>Waived</b> on <?php echo esc_html( wp_date( 'M j, Y', self::ts( $f['waived_at'] ) ) ); ?><?php echo $by ? ' by ' . esc_html( $by->display_name ) : ''; ?>. Reason: <?php echo esc_html( $f['waive_note'] ); ?>. Nothing to pay.</p>
			<?php elseif ( 'paid' === $f['status'] ) : ?>
				<p><b>Paid</b> on <?php echo esc_html( wp_date( 'M j, Y', self::ts( $f['paid_at'] ) ) ); ?> — <?php echo esc_html( $f['paid_how'] ); ?>.</p>
			<?php else : ?>
				<div class="sdo-actions">
					<?php echo self::sms_link( $l['phone'], self::sms( 'fee', $l, $f ), 'Text about the fee' ); // phpcs:ignore ?>
					<?php echo self::form_open( 'resend', 'sdl-inline' ) . '<input type="hidden" name="id" value="' . (int) $l['id'] . '"><input type="hidden" name="what" value="fee"><button class="sdo-btn sdo-btn--ghost">' . ( $f['mailed_at'] ? 'E-mail the fee again' : 'E-mail the fee' ) . '</button></form>'; // phpcs:ignore ?>
				</div>
				<details class="sdl-det" open>
					<summary>Waive this fee</summary>
					<?php echo self::form_open( 'waive', 'sdl-form' ); // phpcs:ignore ?>
						<input type="hidden" name="fid" value="<?php echo (int) $f['id']; ?>">
						<label class="sdo-f"><span>Reason (kept on the record)</span><input type="text" name="why" required maxlength="200" placeholder="e.g. family emergency, first time"></label>
						<label class="sdo-check"><input type="checkbox" name="mail" value="1" checked> Tell the student by e-mail that nothing is owed</label>
						<p><button class="sdo-btn sdo-btn--go">Waive the fee</button></p>
					</form>
				</details>
				<details class="sdl-det">
					<summary>Paid at the office</summary>
					<?php echo self::form_open( 'paid', 'sdl-form' ); // phpcs:ignore ?>
						<input type="hidden" name="fid" value="<?php echo (int) $f['id']; ?>">
						<label class="sdo-f"><span>How</span><select name="how"><option>Cash</option><option>Card at the office</option><option>Zelle</option><option>Check</option><option>Other</option></select></label>
						<p><button class="sdo-btn">Mark as paid</button></p>
					</form>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ---------------- Book */

	/** A booking that was stopped by a clash comes back here so nothing has to be typed again. */
	protected static function draft() {
		if ( empty( $_GET['draft'] ) ) { return null; } // phpcs:ignore
		$d = get_transient( 'sdsc_ls_draft_' . get_current_user_id() );
		return is_array( $d ) ? $d : null;
	}

	protected static function lesson_form( $l = null ) {
		$s     = self::settings();
		$edit  = (bool) $l;
		$draft = self::draft();
		if ( ! $edit ) {
			// Pre-filled from the board ("click a free spot") or from Free times.
			$g = function ( $k ) { return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; }; // phpcs:ignore
			$l = array( 'first_name' => $g( 'first' ), 'last_name' => $g( 'last' ), 'email' => $g( 'email' ), 'phone' => $g( 'phone' ), 'instructor_id' => (int) $g( 'ins' ), 'car_id' => (int) $g( 'car' ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $g( 'start' ) ) ) { $l['starts_at'] = str_replace( 'T', ' ', $g( 'start' ) ) . ':00'; }
			if ( in_array( (int) $g( 'len' ), array( 60, 90, 120, 180 ), true ) ) { $l['minutes'] = (int) $g( 'len' ); }
			if ( ! $l['car_id'] && $l['instructor_id'] && class_exists( 'SDSC_Fleet' ) && ( $ir = SDSC_Fleet::res( $l['instructor_id'] ) ) ) { $l['car_id'] = (int) $ir['def_car']; }
		}
		if ( $draft && (int) ( $draft['id'] ?? 0 ) === (int) ( $edit ? $l['id'] : 0 ) ) { $l = array_merge( (array) $l, $draft['d'] ); }
		$start = ! empty( $l['starts_at'] ) ? substr( str_replace( ' ', 'T', $l['starts_at'] ), 0, 16 ) : '';
		$fleet = class_exists( 'SDSC_Fleet' );
		$ins   = $fleet ? array() : array_filter( array_map( 'trim', explode( "\n", (string) $s['instructors'] ) ) );
		echo self::form_open( $edit ? 'edit' : 'book', 'sdl-form' ); // phpcs:ignore
		if ( $edit ) { echo '<input type="hidden" name="id" value="' . (int) $l['id'] . '">'; }
		?>
		<?php if ( $draft && ! empty( $draft['why'] ) ) : ?>
			<div class="sdo-hold sdl-clash"><b>This time clashes:</b><ul class="sdl-ul"><?php foreach ( $draft['why'] as $w ) : ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?></ul>
				Change the time, instructor or car — or tick &ldquo;Book anyway&rdquo; below.</div>
		<?php endif; ?>
		<?php if ( ! $edit ) : ?>
			<label class="sdo-f sdo-f--wide"><span>Find the student</span>
				<input type="search" class="sdl-find" list="sdl-people" placeholder="Type a name, e-mail or phone" autocomplete="off">
				<datalist id="sdl-people"></datalist>
			</label>
			<input type="hidden" name="ckey" class="sdl-ckey">
		<?php endif; ?>
		<div class="sdl-grid">
			<label class="sdo-f"><span>First name</span><input type="text" name="first" required value="<?php echo esc_attr( $l['first_name'] ?? '' ); ?>" class="sdl-first"></label>
			<label class="sdo-f"><span>Last name</span><input type="text" name="last" value="<?php echo esc_attr( $l['last_name'] ?? '' ); ?>" class="sdl-last"></label>
			<label class="sdo-f"><span>E-mail (if they have one)</span><input type="email" name="email" value="<?php echo esc_attr( $l['email'] ?? '' ); ?>" class="sdl-email"></label>
			<label class="sdo-f"><span>Mobile</span><input type="tel" name="phone" value="<?php echo esc_attr( $l['phone'] ?? '' ); ?>" class="sdl-phone"></label>
			<label class="sdo-f"><span>Date and start time</span><input type="datetime-local" name="start" required value="<?php echo esc_attr( $start ); ?>" step="900" class="sdl-start"></label>
			<label class="sdo-f"><span>Length</span><select name="minutes"><?php foreach ( array( 60, 90, 120, 180 ) as $m ) : ?><option value="<?php echo (int) $m; ?>"<?php selected( (int) ( $l['minutes'] ?? 120 ), $m ); ?>><?php echo esc_html( ( $m / 60 ) . ' hours' ); ?></option><?php endforeach; ?></select></label>
			<label class="sdo-f"><span>Lesson</span><select name="no"><?php foreach ( array( 1 => 'Lesson 1 of 3', 2 => 'Lesson 2 of 3', 3 => 'Lesson 3 of 3', 0 => 'Extra practice' ) as $k => $v ) : ?><option value="<?php echo (int) $k; ?>"<?php selected( (int) ( $l['lesson_no'] ?? 1 ), $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></label>
			<?php if ( $fleet ) : ?>
				<label class="sdo-f"><span>Instructor</span><?php echo SDSC_Fleet::select_html( 'instructor', (int) ( $l['instructor_id'] ?? 0 ), 'instructor_id' ); // phpcs:ignore ?></label>
				<?php if ( SDSC_Fleet::cars() || ! empty( $l['car_id'] ) ) : ?><label class="sdo-f"><span>Car</span><?php echo SDSC_Fleet::select_html( 'car', (int) ( $l['car_id'] ?? 0 ), 'car_id' ); // phpcs:ignore ?></label><?php endif; ?>
			<?php else : ?>
			<label class="sdo-f"><span>Instructor</span>
				<?php if ( $ins ) : ?>
					<select name="instructor"><option value="">—</option><?php foreach ( $ins as $i ) : ?><option<?php selected( $l['instructor'] ?? '', $i ); ?>><?php echo esc_html( $i ); ?></option><?php endforeach; ?></select>
				<?php else : ?>
					<input type="text" name="instructor" value="<?php echo esc_attr( $l['instructor'] ?? '' ); ?>">
				<?php endif; ?>
			</label>
			<?php endif; ?>
			<label class="sdo-f"><span>Learner&rsquo;s permit</span><select name="permit"><?php foreach ( array( '' => 'Not asked', 'yes' => 'Yes, has a permit', 'no' => 'No permit yet' ) as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>"<?php selected( $l['permit'] ?? '', $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></label>
			<label class="sdo-f"><span>Practice hours logged</span><input type="text" name="practice" value="<?php echo esc_attr( $l['practice'] ?? '' ); ?>" placeholder="e.g. 60 h, all logged" maxlength="60"></label>
			<label class="sdo-f sdo-f--wide"><span>Meeting place</span><input type="text" name="place" required value="<?php echo esc_attr( ! empty( $l['place'] ) ? $l['place'] : $s['place'] ); ?>"></label>
			<label class="sdo-f"><span>Message language</span><select name="lang" class="sdl-lang"><option value="en"<?php selected( $l['lang'] ?? 'en', 'en' ); ?>>English</option><option value="es"<?php selected( $l['lang'] ?? 'en', 'es' ); ?>>Español</option></select></label>
			<label class="sdo-f sdo-f--wide"><span>Note for the team (not sent)</span><input type="text" name="note" value="<?php echo esc_attr( $l['note'] ?? '' ); ?>"></label>
		</div>
		<?php if ( $edit ) : ?>
			<label class="sdo-f"><span>When did the student ask for the change?</span><input type="datetime-local" name="notice" value="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>"></label>
			<p class="sdo-note">Moving a lesson to another time with less than <?php echo (int) $s['hours']; ?> hours&rsquo; notice counts as a late cancellation of the original time and adds the fee (you can waive it).</p>
		<?php endif; ?>
		<label class="sdo-check"><input type="checkbox" name="mail" value="1" checked> <?php echo $edit ? 'E-mail the new details to the student' : 'E-mail the confirmation to the student'; ?> <span class="sdo-muted">(no e-mail? the confirmation text waits in <i>Texts to send</i>)</span></label>
		<?php if ( $draft && ! empty( $draft['why'] ) ) : ?><label class="sdo-check"><input type="checkbox" name="force" value="1"> Book anyway, even though it clashes</label><?php endif; ?>
		<p><button class="sdo-btn"><?php echo $edit ? 'Save changes' : 'Book and confirm'; ?></button></p>
		</form>
		<?php
	}

	protected static function tab_book() {
		$s = self::settings();
		?>
		<div class="sdo-two sdl-two">
			<section class="sdo-panel">
				<h2>Book a lesson</h2>
				<?php self::lesson_form(); ?>
			</section>
			<section class="sdo-panel">
				<h2>What the student receives</h2>
				<ul class="sdl-ul">
					<li>A confirmation e-mail in English or Spanish with the date, time, length, meeting place and instructor.</li>
					<li>&ldquo;Bring your valid learner&rsquo;s permit&rdquo; and &ldquo;pick-up is not offered&rdquo;.</li>
					<li>The exact last moment to cancel for free (<?php echo (int) $s['hours']; ?> hours before), and the $<?php echo esc_html( self::money( $s['fee'] ) ); ?> fee for late cancellation, no-show or no permit.</li>
					<li>An &ldquo;Add to Google Calendar&rdquo; link.</li>
					<?php if ( $s['remind'] ) : ?><li>A reminder one day before the free-cancellation deadline.</li><?php endif; ?>
				</ul>
				<p class="sdo-note">The lesson also appears under &ldquo;My driving lessons&rdquo; in the student&rsquo;s dashboard on the website.</p>
			</section>
		</div>
		<?php
	}

	/* ---------------- Fees */

	protected static function tab_fees() {
		global $wpdb;
		$st   = isset( $_GET['st'] ) && in_array( $_GET['st'], array( 'due', 'paid', 'waived', 'all' ), true ) ? sanitize_key( $_GET['st'] ) : 'due'; // phpcs:ignore
		$sql  = 'SELECT f.*, l.first_name, l.last_name, l.starts_at, l.phone FROM ' . self::t_fees() . ' f LEFT JOIN ' . self::t_lessons() . ' l ON l.id = f.lesson_id';
		$rows = (array) $wpdb->get_results( 'all' === $st ? $sql . ' ORDER BY f.id DESC LIMIT 300' : $wpdb->prepare( $sql . ' WHERE f.status = %s ORDER BY f.id DESC LIMIT 300', $st ), ARRAY_A ); // phpcs:ignore
		$sum  = array();
		foreach ( array( 'due', 'paid', 'waived' ) as $k ) {
			$sum[ $k ] = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM ' . self::t_fees() . ' WHERE status = %s', $k ), ARRAY_A ); // phpcs:ignore
		}
		?>
		<div class="sdl-sum">
			<?php foreach ( array( 'due' => 'Not paid yet', 'paid' => 'Paid', 'waived' => 'Waived' ) as $k => $t ) : ?>
				<a class="sdl-sum-i sdl-sum-i--<?php echo esc_attr( $k ); ?><?php echo $k === $st ? ' is-on' : ''; ?>" href="<?php echo esc_url( self::url( 'fees', array( 'st' => $k ) ) ); ?>"><b>$<?php echo esc_html( self::money( $sum[ $k ]['s'] ) ); ?></b><span><?php echo esc_html( $t . ' · ' . (int) $sum[ $k ]['n'] ); ?></span></a>
			<?php endforeach; ?>
			<a class="sdl-sum-i<?php echo 'all' === $st ? ' is-on' : ''; ?>" href="<?php echo esc_url( self::url( 'fees', array( 'st' => 'all' ) ) ); ?>"><b>All</b><span>every fee</span></a>
		</div>
		<?php if ( ! $rows ) : ?><p class="sdo-empty">Nothing here.</p><?php else : ?>
			<table class="widefat striped sdl-table">
				<thead><tr><th>Student</th><th>Lesson</th><th>Reason</th><th>Amount</th><th>Status</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $f ) : $by = $f['waived_by'] ? get_userdata( $f['waived_by'] ) : null; ?>
					<tr>
						<td><b><?php echo esc_html( trim( $f['first_name'] . ' ' . $f['last_name'] ) ); ?></b><br><span class="sdo-muted"><?php echo esc_html( $f['email'] ); ?></span></td>
						<td><?php echo $f['starts_at'] ? esc_html( wp_date( 'M j, Y g:i a', self::ts( $f['starts_at'] ) ) ) : '—'; ?></td>
						<td><?php echo esc_html( self::reason_text( $f['reason'] ) ); ?></td>
						<td>$<?php echo esc_html( self::money( $f['amount'] ) ); ?></td>
						<td><?php
							echo self::fee_chip( $f ); // phpcs:ignore
							if ( 'waived' === $f['status'] ) { echo '<br><span class="sdo-muted">' . esc_html( wp_date( 'M j', self::ts( $f['waived_at'] ) ) . ( $by ? ' · ' . $by->display_name : '' ) . ' · ' . $f['waive_note'] ) . '</span>'; }
							if ( 'paid' === $f['status'] ) { echo '<br><span class="sdo-muted">' . esc_html( wp_date( 'M j', self::ts( $f['paid_at'] ) ) . ' · ' . $f['paid_how'] ) . '</span>'; }
						?></td>
						<td><a class="sdo-btn sdo-btn--ghost" href="<?php echo esc_url( self::url( 'schedule', array( 'id' => $f['lesson_id'] ) ) ); ?>">Open</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/* ---------------- Student history */

	protected static function tab_student() {
		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore
		?>
		<form method="get" class="sdo-filter sdl-inline">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><input type="hidden" name="tab" value="student">
			<input type="email" name="email" value="<?php echo esc_attr( $email ); ?>" placeholder="Student e-mail" required>
			<button class="sdo-btn sdo-btn--ghost">Show</button>
		</form>
		<?php
		if ( ! $email ) { echo '<p class="sdo-empty">Enter a student&rsquo;s e-mail to see every lesson, fee, payment and waiver.</p>'; return; }
		echo self::history_html( $email, 'admin' ); // phpcs:ignore
	}

	/** Lessons and fees of one student — used by the staff screen and the student's dashboard. */
	public static function history_html( $email, $who = 'student', $lang = 'en' ) {
		global $wpdb;
		$es   = 'es' === $lang;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_lessons() . ' WHERE email = %s ORDER BY starts_at DESC', strtolower( $email ) ), ARRAY_A ); // phpcs:ignore
		if ( ! $rows ) {
			return '<p class="sdo-empty sdl-empty">' . ( $es ? 'Todavía no hay clases prácticas reservadas.' : 'No behind-the-wheel lessons booked yet.' ) . ( 'student' === $who ? ' ' . ( $es ? 'Para reservar, llame o envíe un texto al ' : 'To book, call or text ' ) . esc_html( self::phone() ) . '.' : '' ) . '</p>';
		}
		$s = self::settings();
		$h = '<div class="sdl-hist">';
		foreach ( $rows as $l ) {
			$ts     = self::ts( $l['starts_at'] );
			$future = $ts > time();
			$h     .= '<article class="sdl-h"><div class="sdl-h-top"><div><b>' . esc_html( self::when( $l['starts_at'], $lang ) ) . '</b><br><span class="sdl-m">'
				. esc_html( self::lesson_label( $l, $lang ) . ' · ' . ( (int) $l['minutes'] / 60 ) . ( $es ? ' horas' : ' hours' ) . ' · ' . $l['place'] ) . '</span></div>'
				. '<span class="sdl-pill sdl-pill--' . esc_attr( $l['status'] ) . '">' . esc_html( self::statuses()[ $l['status'] ][ $es ? 1 : 0 ] ?? $l['status'] ) . '</span>';
			if ( 'admin' === $who ) { $h .= ' <a class="sdo-btn sdo-btn--ghost" href="' . esc_url( self::url( 'schedule', array( 'id' => $l['id'] ) ) ) . '">Open</a>'; }
			$h .= '</div>';
			if ( 'booked' === $l['status'] && $future ) {
				$dl  = self::ts( self::deadline( $l ) );
				$h  .= '<p class="sdl-note">' . ( time() < $dl
					? ( $es ? 'Puede cancelar sin costo hasta el <b>' . esc_html( self::when( self::deadline( $l ), 'es' ) ) . '</b>, llamando o enviando un texto al ' . esc_html( self::phone() ) . '.' : 'Free cancellation until <b>' . esc_html( self::when( self::deadline( $l ), 'en' ) ) . '</b> — call or text ' . esc_html( self::phone() ) . '.' )
					: ( $es ? 'Faltan menos de ' . (int) $s['hours'] . ' horas: cancelar ahora tiene un cargo de $' . esc_html( self::money( $s['fee'] ) ) . '.' : 'Less than ' . (int) $s['hours'] . ' hours to go: cancelling now carries the $' . esc_html( self::money( $s['fee'] ) ) . ' fee.' ) ) . '</p>';
			}
			foreach ( self::fees_for_lesson( $l['id'] ) as $f ) {
				$m  = '$' . self::money( $f['amount'] );
				$h .= '<div class="sdl-fee-s sdl-fee-s--' . esc_attr( $f['status'] ) . '"><div><b>' . esc_html( self::reason_title( $f['reason'], $lang ) . ' · ' . $m ) . '</b><br><span class="sdl-m">' . esc_html( self::reason_text( $f['reason'], $lang ) ) . '</span></div><div>';
				if ( 'waived' === $f['status'] ) {
					$by = 'admin' === $who && $f['waived_by'] ? get_userdata( $f['waived_by'] ) : null;
					$h .= '<span class="sdl-pill sdl-pill--waived">' . ( $es ? 'Perdonado' : 'Waived' ) . '</span><br><span class="sdl-m">'
						. esc_html( ( $es ? 'Perdonado por la escuela el ' : 'Waived by the school on ' ) . wp_date( 'M j, Y', self::ts( $f['waived_at'] ) ) . ( $by ? ' · ' . $by->display_name . ' · ' . $f['waive_note'] : '' ) . ( $es ? ' — no tiene que pagar.' : ' — nothing to pay.' ) ) . '</span>';
				} elseif ( 'paid' === $f['status'] ) {
					$h .= '<span class="sdl-pill sdl-pill--done">' . ( $es ? 'Pagado' : 'Paid' ) . '</span><br><span class="sdl-m">' . esc_html( wp_date( 'M j, Y', self::ts( $f['paid_at'] ) ) ) . '</span>';
				} else {
					$url = 'student' === $who ? self::pay_url( $f ) : '';
					$h  .= '<span class="sdl-pill sdl-pill--due">' . ( $es ? 'Pendiente' : 'Due' ) . '</span>';
					if ( $url ) { $h .= '<br><a class="sdl-pay" href="' . esc_url( $url ) . '">' . ( $es ? 'Pagar ' : 'Pay ' ) . esc_html( $m ) . '</a>'; }
					elseif ( 'student' === $who ) { $h .= '<br><span class="sdl-m">' . ( $es ? 'Pague en la oficina o al ' : 'Pay at the office or call ' ) . esc_html( self::phone() ) . '</span>'; }
				}
				$h .= '</div></div>';
			}
			$h .= '</article>';
		}
		return $h . '</div>';
	}

	/* ---------------- Settings */

	protected static function tab_settings() {
		$s = self::settings();
		?>
		<?php echo self::form_open( 'set', 'sdo-panel sdo-settings sdl-form' ); // phpcs:ignore ?>
			<h2>Policy</h2>
			<div class="sdl-grid">
				<label class="sdo-f"><span>Fee ($)</span><input type="number" name="fee" min="0" step="1" value="<?php echo esc_attr( $s['fee'] ); ?>" class="sdo-num"></label>
				<label class="sdo-f"><span>Free cancellation until (hours before)</span><input type="number" name="hours" min="1" max="168" value="<?php echo (int) $s['hours']; ?>" class="sdo-num"></label>
			</div>
			<p class="sdo-note">Your written policy (BTW policy 7) says: $60 for a missed lesson, cancelling with less than 48 hours&rsquo; notice, or arriving without a valid learner&rsquo;s permit. Change these numbers only together with the policy page.</p>
			<label class="sdo-f sdo-f--wide"><span>Default meeting place</span><input type="text" name="place" value="<?php echo esc_attr( $s['place'] ); ?>"></label>
			<label class="sdo-f sdo-f--wide"><span>How to find the meeting place (added to texts and e-mails when the lesson is at the default place)</span><input type="text" name="directions" value="<?php echo esc_attr( $s['directions'] ); ?>"></label>
			<?php if ( class_exists( 'SDSC_Fleet' ) ) : ?>
				<p class="sdo-note">Instructors and cars are managed on <a href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-board&tab=instructors' ) ); ?>">Instructors &amp; cars</a>.</p>
				<input type="hidden" name="instructors" value="<?php echo esc_attr( $s['instructors'] ); ?>">
			<?php else : ?>
			<label class="sdo-f sdo-f--wide"><span>Instructors (one per line)</span><textarea name="instructors" rows="3"><?php echo esc_textarea( $s['instructors'] ); ?></textarea></label>
			<?php endif; ?>
			<h2>Payment of fees</h2>
			<label class="sdo-radio"><input type="radio" name="pay" value="woo"<?php checked( $s['pay'], 'woo' ); ?>> Online through the website checkout (WooCommerce)<?php echo self::woo_ok() ? '' : ' — <span class="sdo-warn">no payment method is switched on in WooCommerce yet, so the link below or the office is used</span>'; ?></label>
			<label class="sdo-radio"><input type="radio" name="pay" value="link"<?php checked( $s['pay'], 'link' ); ?>> A payment link (for example a Stripe Payment Link for exactly this amount)</label>
			<label class="sdo-radio"><input type="radio" name="pay" value="office"<?php checked( $s['pay'], 'office' ); ?>> At the office only</label>
			<label class="sdo-f sdo-f--wide"><span>Payment link</span><input type="url" name="link" value="<?php echo esc_attr( $s['link'] ); ?>" placeholder="https://buy.stripe.com/…"></label>
			<h2>Reminder</h2>
			<label class="sdo-check"><input type="checkbox" name="remind" value="1"<?php checked( $s['remind'] ); ?>> E-mail a reminder one day before the free-cancellation deadline</label>
			<p><button class="sdo-btn">Save</button></p>
		</form>
		<?php
	}

	/* ================================================================ handlers */

	protected static function guard() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_ls' );
	}

	protected static function back( $tab, $args = array() ) {
		wp_safe_redirect( self::url( $tab, array_map( 'rawurlencode', $args ) ) );
		exit;
	}

	protected static function local_from_input( $v ) {
		$v = sanitize_text_field( wp_unslash( (string) $v ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v ) ) { return ''; }
		return str_replace( 'T', ' ', $v ) . ':00';
	}

	protected static function fields() {
		$p     = wp_unslash( $_POST ); // phpcs:ignore -- each field sanitised below
		$start = self::local_from_input( $p['start'] ?? '' );
		return array(
			'first_name' => sanitize_text_field( $p['first'] ?? '' ),
			'last_name'  => sanitize_text_field( $p['last'] ?? '' ),
			'email'      => strtolower( sanitize_email( $p['email'] ?? '' ) ),
			'phone'      => sanitize_text_field( $p['phone'] ?? '' ),
			'lang'       => 'es' === ( $p['lang'] ?? '' ) ? 'es' : 'en',
			'lesson_no'  => min( 3, max( 0, (int) ( $p['no'] ?? 1 ) ) ),
			'starts_at'  => $start,
			'minutes'    => in_array( (int) ( $p['minutes'] ?? 120 ), array( 60, 90, 120, 180 ), true ) ? (int) $p['minutes'] : 120,
			'place'      => sanitize_text_field( $p['place'] ?? '' ),
			'instructor' => sanitize_text_field( $p['instructor'] ?? '' ),
			'note'       => sanitize_text_field( $p['note'] ?? '' ),
			'permit'     => in_array( $p['permit'] ?? '', array( 'yes', 'no' ), true ) ? $p['permit'] : '',
			'practice'   => sanitize_text_field( mb_substr( (string) ( $p['practice'] ?? '' ), 0, 60 ) ),
		) + self::fleet_fields( $p );
	}

	/** Instructor and car chosen from the lists (the instructor's name is kept on the lesson too). */
	protected static function fleet_fields( $p ) {
		if ( ! class_exists( 'SDSC_Fleet' ) || ! isset( $p['instructor_id'] ) ) { return array(); }
		$ir  = SDSC_Fleet::res( (int) $p['instructor_id'] );
		$cr  = SDSC_Fleet::res( (int) ( $p['car_id'] ?? 0 ) );
		return array(
			'instructor_id' => $ir && 'instructor' === $ir['kind'] ? (int) $ir['id'] : 0,
			'instructor'    => $ir && 'instructor' === $ir['kind'] ? $ir['name'] : '',
			'car_id'        => $cr && 'car' === $cr['kind'] ? (int) $cr['id'] : 0,
		);
	}

	/** Stop a booking that clashes; the form comes back filled in with the reasons. */
	protected static function clash_check( $d, $id, $tab, $args ) {
		if ( ! class_exists( 'SDSC_Fleet' ) || ! empty( $_POST['force'] ) ) { return; } // phpcs:ignore
		$why = SDSC_Fleet::conflicts( $d['starts_at'], $d['minutes'], $d['instructor_id'] ?? 0, $d['car_id'] ?? 0, $id );
		if ( ! $why ) { return; }
		set_transient( 'sdsc_ls_draft_' . get_current_user_id(), array( 'id' => $id, 'd' => $d, 'why' => $why ), 30 * MINUTE_IN_SECONDS );
		self::back( $tab, array_merge( $args, array( 'draft' => 1 ) ) );
	}

	protected static function after_save( $d ) {
		delete_transient( 'sdsc_ls_draft_' . get_current_user_id() );
		if ( class_exists( 'SDSC_Fleet' ) && ! empty( $d['instructor_id'] ) ) {
			SDSC_Fleet::assign( $d['email'], $d['phone'], trim( $d['first_name'] . ' ' . $d['last_name'] ), $d['instructor_id'], true );
		}
	}

	public static function h_book() {
		global $wpdb;
		self::guard();
		$d = self::fields();
		if ( ! $d['first_name'] || ( ! is_email( $d['email'] ) && ! SDSC_Outreach::phone_e164( $d['phone'] ) ) || ! $d['starts_at'] || ! $d['place'] ) { self::back( 'book', array( 'err' => 'Please fill in the name, an e-mail or a mobile number, the date and time, and the meeting place.' ) ); }
		if ( $d['email'] && ! is_email( $d['email'] ) ) { self::back( 'book', array( 'err' => 'That e-mail address does not look right.' ) ); }
		if ( self::ts( $d['starts_at'] ) < time() - HOUR_IN_SECONDS ) { self::back( 'book', array( 'err' => 'That time is in the past.' ) ); }
		self::clash_check( $d, 0, 'book', array() );
		$u = $d['email'] ? get_user_by( 'email', $d['email'] ) : null;
		$d = array_merge( $d, array(
			'ckey'       => sanitize_key( $_POST['ckey'] ?? '' ) ?: SDSC_Outreach::key( $d['email'] ?: $d['phone'], $d['first_name'] ),
			'user_id'    => $u ? $u->ID : 0,
			'status'     => 'booked',
			'created_by' => get_current_user_id(),
			'created_at' => self::now(),
		) );
		$wpdb->insert( self::t_lessons(), $d );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { self::back( 'book', array( 'err' => 'Could not save the lesson.' ) ); }
		self::add_log( $id, 'Lesson booked for ' . self::when( $d['starts_at'], 'en' ) . ( $d['instructor'] ? ' with ' . $d['instructor'] : '' ) . ( ! empty( $_POST['force'] ) ? ' (booked despite a clash)' : '' ) );
		self::after_save( $d );
		$msg = 'Lesson booked.';
		if ( ! $d['email'] ) {
			$msg .= ' No e-mail on file — send the confirmation from Texts to send (it is waiting there).';
		} elseif ( ! empty( $_POST['mail'] ) ) {
			if ( self::mail( 'confirm', self::lesson( $id ) ) ) {
				$wpdb->update( self::t_lessons(), array( 'conf_at' => self::now() ), array( 'id' => $id ) );
				$msg .= ' Confirmation e-mailed to ' . $d['email'] . '.';
			} else {
				$msg .= ' The confirmation e-mail could not be sent' . ( SDSC_Mailer::ready() ? '.' : ' (e-mail is not connected yet).' );
			}
		}
		self::back( 'schedule', array( 'id' => $id, 'msg' => $msg ) );
	}

	public static function h_edit() {
		global $wpdb;
		self::guard();
		$id = (int) ( $_POST['id'] ?? 0 );
		$l  = self::lesson( $id );
		if ( ! $l || 'booked' !== $l['status'] ) { self::back( 'schedule', array( 'err' => 'This lesson can no longer be changed.' ) ); }
		$d = self::fields();
		if ( ! $d['first_name'] || ( ! is_email( $d['email'] ) && ! SDSC_Outreach::phone_e164( $d['phone'] ) ) || ! $d['starts_at'] || ! $d['place'] ) { self::back( 'schedule', array( 'id' => $id, 'err' => 'Please fill in the name, an e-mail or a mobile number, the date and time, and the place.' ) ); }
		self::clash_check( $d, $id, 'schedule', array( 'id' => $id ) );
		self::after_save( $d );
		$moved  = $d['starts_at'] !== $l['starts_at'];
		$notice = self::local_from_input( $_POST['notice'] ?? '' ) ?: self::now();
		$msg    = 'Saved.';
		if ( $moved && self::ts( $notice ) > self::ts( self::deadline( $l ) ) ) {
			// Late change: the original time counts as a late cancellation; the new time is a new lesson.
			$wpdb->update( self::t_lessons(), array( 'status' => 'late_cancel', 'notice_at' => $notice ), array( 'id' => $id ) );
			self::add_log( $id, 'Moved to ' . self::when( $d['starts_at'], 'en' ) . ' with less than ' . (int) self::settings()['hours'] . ' hours’ notice' );
			$f = self::make_fee( self::lesson( $id ), 'late_cancel' );
			$u = $d['email'] ? get_user_by( 'email', $d['email'] ) : null;
			$wpdb->insert( self::t_lessons(), array_merge( $d, array( 'ckey' => $l['ckey'], 'user_id' => $u ? $u->ID : 0, 'status' => 'booked', 'created_by' => get_current_user_id(), 'created_at' => self::now() ) ) );
			$nid = (int) $wpdb->insert_id;
			self::add_log( $nid, 'Booked as the new time for the lesson of ' . self::when( $l['starts_at'], 'en' ) );
			if ( ! empty( $_POST['mail'] ) ) {
				if ( self::mail( 'confirm', self::lesson( $nid ) ) ) { $wpdb->update( self::t_lessons(), array( 'conf_at' => self::now() ), array( 'id' => $nid ) ); }
				self::send_fee( $f );
			}
			self::back( 'schedule', array( 'id' => $id, 'msg' => 'Moved with less than ' . (int) self::settings()['hours'] . ' hours’ notice: the original time is recorded as a late cancellation with the fee (you can waive it below), and the new time is booked.' ) );
		}
		$wpdb->update( self::t_lessons(), array_merge( $d, array( 'remind_at' => $moved ? null : $l['remind_at'] ) ), array( 'id' => $id ) );
		self::add_log( $id, $moved ? 'Moved from ' . self::when( $l['starts_at'], 'en' ) . ' to ' . self::when( $d['starts_at'], 'en' ) : 'Details changed' );
		if ( ! empty( $_POST['mail'] ) && self::mail( 'update', self::lesson( $id ) ) ) {
			$wpdb->update( self::t_lessons(), array( 'conf_at' => self::now() ), array( 'id' => $id ) );
			$msg .= ' The student was e-mailed the new details.';
		}
		self::back( 'schedule', array( 'id' => $id, 'msg' => $msg ) );
	}

	protected static function send_fee( $f ) {
		global $wpdb;
		$l = self::lesson( $f['lesson_id'] );
		if ( $l && self::mail( 'fee', $l, $f ) ) {
			$wpdb->update( self::t_fees(), array( 'mailed_at' => self::now() ), array( 'id' => $f['id'] ) );
			return true;
		}
		return false;
	}

	public static function h_cancel() {
		global $wpdb;
		self::guard();
		$id = (int) ( $_POST['id'] ?? 0 );
		$l  = self::lesson( $id );
		if ( ! $l || 'booked' !== $l['status'] ) { self::back( 'schedule', array( 'err' => 'This lesson is not open.' ) ); }
		$notice = self::local_from_input( $_POST['notice'] ?? '' ) ?: self::now();
		if ( self::ts( $notice ) > time() + 60 ) { $notice = self::now(); }
		$late = self::ts( $notice ) > self::ts( self::deadline( $l ) );
		$note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		$wpdb->update( self::t_lessons(), array( 'status' => $late ? 'late_cancel' : 'cancelled', 'notice_at' => $notice ), array( 'id' => $id ) );
		self::add_log( $id, 'Cancelled — student told us ' . self::when( $notice, 'en' ) . ( $late ? ' (late)' : ' (in time)' ) . ( $note ? ': ' . $note : '' ) );
		if ( ! empty( $l['df_id'] ) && class_exists( 'SDSC_DriveFlow' ) ) { SDSC_DriveFlow::cancel_session( $l['df_id'] ); }
		$l = self::lesson( $id );
		if ( $late ) {
			$f = self::make_fee( $l, 'late_cancel' );
			if ( ! empty( $_POST['mail'] ) ) { self::send_fee( $f ); }
			self::back( 'schedule', array( 'id' => $id, 'msg' => 'Cancelled late: the fee was added. You can waive it below.' ) );
		}
		if ( ! empty( $_POST['mail'] ) ) { self::mail( 'cancelled', $l ); }
		self::back( 'schedule', array( 'id' => $id, 'msg' => 'Cancelled in time — no fee.' ) );
	}

	public static function h_outcome() {
		global $wpdb;
		self::guard();
		$id = (int) ( $_POST['id'] ?? 0 );
		$o  = sanitize_key( $_POST['o'] ?? '' );
		$l  = self::lesson( $id );
		if ( ! $l || 'booked' !== $l['status'] || ! in_array( $o, array( 'done', 'no_show', 'no_permit' ), true ) ) { self::back( 'schedule', array( 'err' => 'Nothing changed.' ) ); }
		if ( self::ts( $l['starts_at'] ) > time() ) { self::back( 'schedule', array( 'id' => $id, 'err' => 'This lesson has not started yet.' ) ); }
		$wpdb->update( self::t_lessons(), array( 'status' => $o ), array( 'id' => $id ) );
		self::add_log( $id, 'Marked: ' . self::statuses()[ $o ][0] );
		if ( 'done' === $o ) { self::back( 'schedule', array( 'msg' => self::name( $l ) . ': lesson completed.' ) ); }
		$f = self::make_fee( self::lesson( $id ), $o );
		$sent = self::send_fee( $f );
		self::back( 'schedule', array( 'id' => $id, 'msg' => 'Fee added' . ( $sent ? ' and e-mailed to the student' : '' ) . '. You can waive it below.' ) );
	}

	public static function h_waive() {
		global $wpdb;
		self::guard();
		$f   = self::fee( (int) ( $_POST['fid'] ?? 0 ) );
		$why = sanitize_text_field( wp_unslash( $_POST['why'] ?? '' ) );
		if ( ! $f || 'due' !== $f['status'] ) { self::back( 'fees', array( 'err' => 'This fee is not open.' ) ); }
		if ( '' === $why ) { self::back( 'schedule', array( 'id' => $f['lesson_id'], 'err' => 'Please write a reason for the waiver.' ) ); }
		$wpdb->update( self::t_fees(), array( 'status' => 'waived', 'waived_at' => self::now(), 'waived_by' => get_current_user_id(), 'waive_note' => $why ), array( 'id' => $f['id'] ) );
		self::cancel_order( $f, 'Fee waived by the school: ' . $why );
		self::add_log( $f['lesson_id'], 'Fee of $' . self::money( $f['amount'] ) . ' waived — ' . $why );
		$l = self::lesson( $f['lesson_id'] );
		if ( ! empty( $_POST['mail'] ) && $l ) { self::mail( 'waived', $l, self::fee( $f['id'] ) ); }
		self::back( 'schedule', array( 'id' => $f['lesson_id'], 'msg' => 'Fee waived. It stays on the record as waived; nothing is charged.' ) );
	}

	public static function h_paid() {
		global $wpdb;
		self::guard();
		$f   = self::fee( (int) ( $_POST['fid'] ?? 0 ) );
		$how = sanitize_text_field( wp_unslash( $_POST['how'] ?? 'Office' ) );
		if ( ! $f || 'due' !== $f['status'] ) { self::back( 'fees', array( 'err' => 'This fee is not open.' ) ); }
		$wpdb->update( self::t_fees(), array( 'status' => 'paid', 'paid_at' => self::now(), 'paid_how' => $how . ' (recorded by ' . wp_get_current_user()->display_name . ')' ), array( 'id' => $f['id'] ) );
		self::cancel_order( $f, 'Paid at the office instead.' );
		self::add_log( $f['lesson_id'], 'Fee marked paid: ' . $how );
		self::back( 'schedule', array( 'id' => $f['lesson_id'], 'msg' => 'Fee marked as paid.' ) );
	}

	public static function h_resend() {
		global $wpdb;
		self::guard();
		$l = self::lesson( (int) ( $_POST['id'] ?? 0 ) );
		if ( ! $l ) { self::back( 'schedule' ); }
		if ( 'fee' === ( $_POST['what'] ?? '' ) ) {
			$ok = false;
			foreach ( self::fees_for_lesson( $l['id'] ) as $f ) { if ( 'due' === $f['status'] ) { $ok = self::send_fee( $f ); } }
		} else {
			$ok = self::mail( 'confirm', $l );
			if ( $ok ) { $wpdb->update( self::t_lessons(), array( 'conf_at' => self::now() ), array( 'id' => $l['id'] ) ); }
		}
		self::back( 'schedule', $ok ? array( 'id' => $l['id'], 'msg' => 'E-mail sent to ' . $l['email'] . '.' ) : array( 'id' => $l['id'], 'err' => 'The e-mail could not be sent' . ( SDSC_Mailer::ready() ? '.' : ' (e-mail is not connected yet).' ) ) );
	}

	public static function h_set() {
		self::guard();
		$p = wp_unslash( $_POST ); // phpcs:ignore
		update_option( self::SET, array(
			'fee'         => max( 0, round( (float) ( $p['fee'] ?? 60 ), 2 ) ),
			'hours'       => min( 168, max( 1, (int) ( $p['hours'] ?? 48 ) ) ),
			'place'       => sanitize_text_field( $p['place'] ?? '' ),
			'pay'         => in_array( $p['pay'] ?? '', array( 'woo', 'link', 'office' ), true ) ? $p['pay'] : 'woo',
			'link'        => esc_url_raw( $p['link'] ?? '' ),
			'remind'      => empty( $p['remind'] ) ? 0 : 1,
			'instructors' => sanitize_textarea_field( $p['instructors'] ?? '' ),
			'directions'  => sanitize_text_field( $p['directions'] ?? '' ),
		), false );
		self::back( 'settings', array( 'msg' => 'Settings saved.' ) );
	}

	/* ================================================================ student side */

	public static function my_url() {
		if ( function_exists( 'tutor_utils' ) ) {
			$u = tutor_utils()->tutor_dashboard_url( self::TAB );
			if ( $u ) { return $u; }
		}
		return '';
	}

	public static function tutor_nav( $items ) {
		if ( ! is_array( $items ) ) { return $items; }
		$new = array();
		foreach ( $items as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'enrolled-courses' === $k ) { $new[ self::TAB ] = array( 'title' => 'My driving lessons', 'icon' => 'calendar-check', 'active_icon' => 'calendar-check' ); }
		}
		if ( ! isset( $new[ self::TAB ] ) ) { $new[ self::TAB ] = array( 'title' => 'My driving lessons', 'icon' => 'calendar-check', 'active_icon' => 'calendar-check' ); }
		return $new;
	}

	public static function tutor_template( $tpl ) {
		global $wp_query;
		if ( isset( $wp_query->query_vars['tutor_dashboard_page'] ) && self::TAB === $wp_query->query_vars['tutor_dashboard_page'] ) {
			return SDSC_PATH . 'templates/tutor-my-lessons.php';
		}
		return $tpl;
	}

	public static function front_assets() {
		if ( ! is_user_logged_in() ) { return; }
		wp_register_style( 'sdsc-lessons-front', SDSC_URL . 'assets/lessons.css', array(), SDSC_VERSION );
		global $wp_query;
		$q = $wp_query->query_vars['tutor_dashboard_page'] ?? '';
		if ( self::TAB === $q ) { wp_enqueue_style( 'sdsc-lessons-front' ); }
	}

	/** The signed-in student's own lessons and fees (also [sdsc_my_lessons]). */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) { return '<p>' . esc_html__( 'Please sign in to see your lessons.', 'sdsc' ) . '</p>'; }
		wp_enqueue_style( 'sdsc-lessons-front' );
		$u    = wp_get_current_user();
		$lang = 'en';
		if ( class_exists( 'SDSC_Outreach' ) ) {
			foreach ( SDSC_Outreach::contacts() as $c ) { if ( $c['email'] === strtolower( $u->user_email ) ) { $lang = $c['lang']; break; } }
		}
		$s = self::settings();
		$h = '<div class="sdl-front"><h3>' . ( 'es' === $lang ? 'Mis clases prácticas' : 'My driving lessons' ) . '</h3>'
			. '<p class="sdl-m">' . ( 'es' === $lang
				? 'Cancelación gratis hasta ' . (int) $s['hours'] . ' horas antes de la clase. Cancelar más tarde, no presentarse o llegar sin permiso de aprendizaje válido tiene un cargo de $' . esc_html( self::money( $s['fee'] ) ) . '.'
				: 'Free cancellation up to ' . (int) $s['hours'] . ' hours before a lesson. Cancelling later, missing a lesson, or arriving without a valid learner’s permit carries a $' . esc_html( self::money( $s['fee'] ) ) . ' fee.' ) . '</p>'
			. ( class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::student_html( $u->user_email, $lang ) : '' )
			. self::history_html( $u->user_email, 'student', $lang )
			. ( class_exists( 'SDSC_Refunds' ) ? SDSC_Refunds::student_html( $u->user_email, $lang ) : '' ) . '</div>';
		return $h;
	}
}
