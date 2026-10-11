<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Holiday calendar: US federal holidays, Maryland state holidays (MVA offices closed),
 * and popular observances — computed for any year, with per-holiday settings:
 * banner on/off, how many days before it shows, where (bar / pop-up / homepage pop-up),
 * office closed, English / English + Spanish text.
 *
 * Used by: Banners & alerts (auto banners), the public calendar [sdsc_holidays],
 * the contact page, and every date picker on the site (holidays are marked).
 * Maryland list per the Department of Budget and Management; weekend rule: Saturday → Friday,
 * Sunday → Monday (observed).
 */
class SDSC_Holidays {

	const OPT = 'sdsc_holiday_cfg';

	public static function init() {
		add_action( 'admin_post_sdsc_holidays_save', array( __CLASS__, 'save' ) );
		add_shortcode( 'sdsc_holidays', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 9 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'data' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'data' ), 20 );
	}

	protected static function tz() { return wp_timezone(); }

	protected static function d( $y, $m, $d ) {
		return new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $y, $m, $d ), self::tz() );
	}

	/** n-th weekday (0=Sun…6=Sat) of a month; n = -1 for the last one. */
	public static function nth( $y, $m, $dow, $n ) {
		if ( $n > 0 ) {
			$d = self::d( $y, $m, 1 );
			return $d->modify( '+' . ( ( ( $dow - (int) $d->format( 'w' ) + 7 ) % 7 ) + 7 * ( $n - 1 ) ) . ' days' );
		}
		$d = self::d( $y, $m, 1 )->modify( 'last day of this month' );
		return $d->modify( '-' . ( ( (int) $d->format( 'w' ) - $dow + 7 ) % 7 ) . ' days' );
	}

	/**
	 * Definitions. type: federal | state | observance.  state = Maryland state holiday (MVA closed).
	 * Keys: name, es, type, theme, on (banner on by default), days (shown N days before), when (fn year→date|null),
	 * t / x (English title / text), te / xe (Spanish title / text).
	 */
	public static function defs() {
		$c = __CLASS__;
		return array(
			'new_year'     => array( 'name' => 'New Year’s Day', 'es' => 'Año Nuevo', 'type' => 'state', 'theme' => 'sparkle', 'on' => 1, 'days' => 2, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 1, 1 ); },
				't' => 'Happy New Year!', 'x' => 'Wishing you a safe and happy new year. Getting your license this year? We’re here to help.', 'te' => '¡Feliz Año Nuevo!', 'xe' => 'Le deseamos un año nuevo feliz y seguro. ¿Va a sacar su licencia este año? Estamos para ayudarle.' ),
			'mlk'          => array( 'name' => 'Martin Luther King Jr. Day', 'es' => 'Día de Martin Luther King Jr.', 'type' => 'state', 'theme' => 'calm', 'on' => 0, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 1, 1, 3 ); },
				't' => 'Honoring Dr. Martin Luther King Jr.', 'x' => 'Today we honor Dr. King’s legacy of service, equality and community.', 'te' => 'En honor al Dr. Martin Luther King Jr.', 'xe' => 'Hoy honramos el legado de servicio, igualdad y comunidad del Dr. King.' ),
			'valentine'    => array( 'name' => 'Valentine’s Day', 'es' => 'Día de San Valentín', 'type' => 'observance', 'theme' => 'hearts', 'on' => 0, 'days' => 1, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 2, 14 ); },
				't' => 'Happy Valentine’s Day', 'x' => 'Love the road — buckle up and drive with care.', 'te' => 'Feliz Día de San Valentín', 'xe' => 'Cuide a quien ama: abróchese el cinturón y maneje con cuidado.' ),
			'presidents'   => array( 'name' => 'Presidents’ Day', 'es' => 'Día de los Presidentes', 'type' => 'state', 'theme' => 'flag', 'on' => 0, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 2, 1, 3 ); },
				't' => 'Happy Presidents’ Day', 'x' => 'Enjoy the long weekend and drive safely.', 'te' => 'Feliz Día de los Presidentes', 'xe' => 'Disfrute el fin de semana largo y maneje con cuidado.' ),
			'st_patrick'   => array( 'name' => 'St. Patrick’s Day', 'es' => 'Día de San Patricio', 'type' => 'observance', 'theme' => 'shamrock', 'on' => 0, 'days' => 1, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 3, 17 ); },
				't' => 'Happy St. Patrick’s Day', 'x' => 'Celebrating tonight? Plan a sober ride home.', 'te' => 'Feliz Día de San Patricio', 'xe' => '¿Va a celebrar esta noche? Planee cómo regresar a casa sin manejar.' ),
			'nowruz'       => array( 'name' => 'Nowruz (Persian New Year)', 'es' => 'Nowruz (Año Nuevo persa)', 'type' => 'observance', 'theme' => 'spring', 'on' => 1, 'days' => 3, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 3, 20 ); },
				't' => 'Happy Nowruz! نوروز مبارک', 'x' => 'Wishing our Persian community a joyful and healthy new year.', 'te' => '¡Feliz Nowruz!', 'xe' => 'Deseamos a la comunidad persa un año nuevo lleno de alegría y salud.' ),
			'maryland_day' => array( 'name' => 'Maryland Day', 'es' => 'Día de Maryland', 'type' => 'observance', 'theme' => 'flag', 'on' => 0, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 3, 25 ); },
				't' => 'Happy Maryland Day!', 'x' => 'Proud to teach Maryland drivers since day one.', 'te' => '¡Feliz Día de Maryland!', 'xe' => 'Orgullosos de enseñar a los conductores de Maryland.' ),
			'cinco'        => array( 'name' => 'Cinco de Mayo', 'es' => 'Cinco de Mayo', 'type' => 'observance', 'theme' => 'celebrate', 'on' => 0, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 5, 5 ); },
				't' => 'Happy Cinco de Mayo!', 'x' => 'Celebrate safely — never drive impaired.', 'te' => '¡Feliz Cinco de Mayo!', 'xe' => 'Celebre con responsabilidad: nunca maneje bajo los efectos del alcohol.' ),
			'mothers'      => array( 'name' => 'Mother’s Day', 'es' => 'Día de las Madres', 'type' => 'observance', 'theme' => 'flowers', 'on' => 0, 'days' => 1, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 5, 0, 2 ); },
				't' => 'Happy Mother’s Day', 'x' => 'To every mom who sat in the passenger seat with patience — thank you.', 'te' => 'Feliz Día de las Madres', 'xe' => 'A cada mamá que nos acompañó con paciencia en el asiento del pasajero: ¡gracias!' ),
			'memorial'     => array( 'name' => 'Memorial Day', 'es' => 'Día de los Caídos (Memorial Day)', 'type' => 'state', 'theme' => 'flag', 'on' => 1, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 5, 1, -1 ); },
				't' => 'Remembering on Memorial Day', 'x' => 'Honoring those who gave their lives in service to our country.', 'te' => 'Recordamos en el Memorial Day', 'xe' => 'Honramos a quienes dieron su vida al servicio de nuestro país.' ),
			'fathers'      => array( 'name' => 'Father’s Day', 'es' => 'Día del Padre', 'type' => 'observance', 'theme' => 'calm', 'on' => 0, 'days' => 1, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 6, 0, 3 ); },
				't' => 'Happy Father’s Day', 'x' => 'To every dad who taught us to check the mirrors — thank you.', 'te' => 'Feliz Día del Padre', 'xe' => 'A cada papá que nos enseñó a revisar los espejos: ¡gracias!' ),
			'juneteenth'   => array( 'name' => 'Juneteenth', 'es' => 'Juneteenth (Día de la Liberación)', 'type' => 'state', 'theme' => 'calm', 'on' => 0, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 6, 19 ); },
				't' => 'Happy Juneteenth', 'x' => 'Celebrating freedom and the progress we build together.', 'te' => 'Feliz Juneteenth', 'xe' => 'Celebramos la libertad y el progreso que construimos juntos.' ),
			'july4'        => array( 'name' => 'Independence Day', 'es' => 'Día de la Independencia', 'type' => 'state', 'theme' => 'fireworks', 'on' => 1, 'days' => 3, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 7, 4 ); },
				't' => 'Happy Fourth of July!', 'x' => 'Enjoy the fireworks — and if you celebrate, never drive impaired.', 'te' => '¡Feliz 4 de Julio!', 'xe' => 'Disfrute los fuegos artificiales y, si celebra, nunca maneje bajo los efectos del alcohol.' ),
			'labor'        => array( 'name' => 'Labor Day', 'es' => 'Día del Trabajo', 'type' => 'state', 'theme' => 'flag', 'on' => 0, 'days' => 1, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 9, 1, 1 ); },
				't' => 'Happy Labor Day', 'x' => 'Enjoy the long weekend and travel safely.', 'te' => 'Feliz Día del Trabajo', 'xe' => 'Disfrute el fin de semana largo y viaje con cuidado.' ),
			'columbus'     => array( 'name' => 'Columbus Day', 'es' => 'Día de Colón', 'type' => 'state', 'theme' => 'info', 'on' => 0, 'days' => 1, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 10, 1, 2 ); },
				't' => 'Columbus Day', 'x' => 'Planning a road test? Book around the holiday.', 'te' => 'Día de Colón', 'xe' => '¿Tiene examen de manejo? Programe su cita considerando el feriado.' ),
			'halloween'    => array( 'name' => 'Halloween', 'es' => 'Halloween (Noche de Brujas)', 'type' => 'observance', 'theme' => 'halloween', 'on' => 1, 'days' => 6, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 10, 31 ); },
				't' => 'Happy Halloween!', 'x' => 'Driving tonight? Slow down and watch for trick-or-treaters.', 'te' => '¡Feliz Halloween!', 'xe' => '¿Va a manejar esta noche? Baje la velocidad y cuidado con los niños pidiendo dulces.' ),
			'election'     => array( 'name' => 'General Election Day', 'es' => 'Día de Elecciones', 'type' => 'state', 'theme' => 'flag', 'on' => 0, 'days' => 1, 'when' => function ( $y ) use ( $c ) { return 0 === $y % 2 ? $c::nth( $y, 11, 1, 1 )->modify( '+1 day' ) : null; },
				't' => 'Election Day', 'x' => 'Make your voice heard — and drive safely to the polls.', 'te' => 'Día de Elecciones', 'xe' => 'Haga oír su voz y maneje con cuidado.' ),
			'veterans'     => array( 'name' => 'Veterans Day', 'es' => 'Día de los Veteranos', 'type' => 'state', 'theme' => 'flag', 'on' => 1, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 11, 11 ); },
				't' => 'Thank you, Veterans', 'x' => 'Honoring all who served our country.', 'te' => 'Gracias, Veteranos', 'xe' => 'Honramos a todos los que sirvieron a nuestro país.' ),
			'thanksgiving' => array( 'name' => 'Thanksgiving Day', 'es' => 'Día de Acción de Gracias', 'type' => 'state', 'theme' => 'autumn', 'on' => 1, 'days' => 3, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 11, 4, 4 ); },
				't' => 'Happy Thanksgiving', 'x' => 'We’re thankful for every student who trusts us. Safe travels this weekend!', 'te' => 'Feliz Día de Acción de Gracias', 'xe' => 'Agradecemos a cada estudiante que confía en nosotros. ¡Buen viaje este fin de semana!' ),
			'native'       => array( 'name' => 'American Indian Heritage Day', 'es' => 'Día de la Herencia Indígena Americana', 'type' => 'state', 'theme' => 'autumn', 'on' => 0, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::nth( $y, 11, 4, 4 )->modify( '+1 day' ); },
				't' => 'American Indian Heritage Day', 'x' => 'Honoring the heritage of Native American communities in Maryland.', 'te' => 'Día de la Herencia Indígena Americana', 'xe' => 'Honramos la herencia de las comunidades indígenas de Maryland.' ),
			'christmas'    => array( 'name' => 'Christmas Day', 'es' => 'Navidad', 'type' => 'state', 'theme' => 'snow', 'on' => 1, 'days' => 6, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 12, 25 ); },
				't' => 'Happy Holidays!', 'x' => 'Wishing you a warm and safe holiday season from all of us at Sam’s Driving School.', 'te' => '¡Felices Fiestas!', 'xe' => 'Le deseamos unas fiestas cálidas y seguras de parte de todos en Sam’s Driving School.' ),
			'nye'          => array( 'name' => 'New Year’s Eve', 'es' => 'Nochevieja', 'type' => 'observance', 'theme' => 'sparkle', 'on' => 0, 'days' => 0, 'when' => function ( $y ) use ( $c ) { return $c::d( $y, 12, 31 ); },
				't' => 'Celebrate safely tonight', 'x' => 'Ring in the new year — and plan a sober ride home.', 'te' => 'Celebre con responsabilidad', 'xe' => 'Reciba el año nuevo y planee cómo regresar a casa sin manejar.' ),
		);
	}

	public static function icon_slug( $key ) {
		$m = array( 'new_year' => 'party_popper', 'mlk' => 'dove', 'valentine' => 'red_heart', 'presidents' => 'classical_building', 'st_patrick' => 'shamrock', 'nowruz' => 'tulip', 'maryland_day' => 'crab', 'cinco' => 'party_popper', 'mothers' => 'bouquet', 'memorial' => 'military_medal', 'fathers' => 'necktie', 'juneteenth' => 'glowing_star', 'july4' => 'fireworks', 'labor' => 'hammer_and_wrench', 'columbus' => 'sailboat', 'halloween' => 'jack-o-lantern', 'election' => 'ballot_box_with_ballot', 'veterans' => 'military_medal', 'thanksgiving' => 'turkey', 'native' => 'feather', 'christmas' => 'christmas_tree', 'nye' => 'fireworks' );
		return $m[ $key ] ?? 'glowing_star';
	}

	/** Saturday → Friday, Sunday → Monday (Maryland observed-day rule). */
	public static function observed( DateTimeImmutable $d ) {
		$w = (int) $d->format( 'w' );
		if ( 6 === $w ) { return $d->modify( '-1 day' ); }
		if ( 0 === $w ) { return $d->modify( '+1 day' ); }
		return $d;
	}

	public static function cfg( $key = null ) {
		$all = get_option( self::OPT, array() );
		$all = is_array( $all ) ? $all : array();
		if ( null === $key ) { return $all; }
		$d   = self::defs()[ $key ] ?? array();
		$c   = $all[ $key ] ?? array();
		return array(
			'banner'    => isset( $c['banner'] ) ? (bool) $c['banner'] : ! empty( $d['on'] ),
			'days'      => isset( $c['days'] ) ? max( 0, min( 30, (int) $c['days'] ) ) : (int) ( $d['days'] ?? 0 ),
			'placement' => $c['placement'] ?? 'bar',
			'home'      => ! empty( $c['home'] ),
			'closed'    => ! empty( $c['closed'] ),
			'lang'      => $c['lang'] ?? 'en_es',
			'theme'     => $c['theme'] ?? ( $d['theme'] ?? 'info' ),
			't'         => (string) ( $c['t'] ?? '' ),
			'x'         => (string) ( $c['x'] ?? '' ),
			'te'        => (string) ( $c['te'] ?? '' ),
			'xe'        => (string) ( $c['xe'] ?? '' ),
		);
	}

	/** All occurrences between two dates: [key, date, observed, def, cfg]. */
	public static function between( DateTimeImmutable $from, DateTimeImmutable $to ) {
		$out = array();
		$defs = self::defs();
		for ( $y = (int) $from->format( 'Y' ); $y <= (int) $to->format( 'Y' ); $y++ ) {
			foreach ( $defs as $k => $d ) {
				$day = call_user_func( $d['when'], $y );
				if ( ! $day ) { continue; }
				$obs = 'state' === $d['type'] ? self::observed( $day ) : $day;
				if ( $day->setTime( 23, 59 ) < $from || $day > $to ) { continue; }
				$out[] = array( 'key' => $k, 'date' => $day, 'observed' => $obs, 'def' => $d, 'cfg' => self::cfg( $k ) );
			}
		}
		usort( $out, function ( $a, $b ) { return $a['date'] <=> $b['date']; } );
		return $out;
	}

	public static function upcoming( $months = 12 ) {
		$now = new DateTimeImmutable( 'today', self::tz() );
		return self::between( $now, $now->modify( '+' . (int) $months . ' months' ) );
	}

	public static function es_date( DateTimeImmutable $d ) {
		$dias  = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );
		$meses = array( '', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		return $dias[ (int) $d->format( 'w' ) ] . ' ' . (int) $d->format( 'j' ) . ' de ' . $meses[ (int) $d->format( 'n' ) ];
	}

	/** Banner text for one occurrence, with the MVA / office-closed lines added automatically. */
	public static function texts( $o ) {
		$d  = $o['def'];
		$c  = $o['cfg'];
		$en = array( $c['t'] ? $c['t'] : $d['t'], $c['x'] ? $c['x'] : $d['x'] );
		$es = array( $c['te'] ? $c['te'] : $d['te'], $c['xe'] ? $c['xe'] : $d['xe'] );
		$when_en = $o['observed']->format( 'l, F j' );
		$when_es = self::es_date( $o['observed'] );
		if ( $c['closed'] ) {
			$en[1] .= ' Our office is closed on ' . $when_en . '.';
			$es[1] .= ' Nuestra oficina estará cerrada el ' . $when_es . '.';
		}
		if ( 'state' === $d['type'] ) {
			$en[1] .= ' MVA offices are closed on ' . $when_en . '.';
			$es[1] .= ' Las oficinas de la MVA estarán cerradas el ' . $when_es . '.';
		}
		$repl = array( '{date}' => $o['date']->format( 'l, F j' ), '{year}' => $o['date']->format( 'Y' ) );
		return array( 'en' => array_map( function ( $s ) use ( $repl ) { return strtr( $s, $repl ); }, $en ), 'es' => $es );
	}

	/** Live holiday banners for the notices endpoint. */
	public static function live_items() {
		$now = new DateTimeImmutable( 'now', self::tz() );
		$out = array();
		foreach ( self::between( $now->modify( '-1 day' ), $now->modify( '+40 days' ) ) as $o ) {
			$c = $o['cfg'];
			if ( ! $c['banner'] ) { continue; }
			$last  = max( $o['date'], $o['observed'] )->setTime( 23, 59 );
			$first = min( $o['date'], $o['observed'] )->modify( '-' . $c['days'] . ' days' )->setTime( 0, 0 );
			if ( $now < $first || $now > $last ) { continue; }
			$t = self::texts( $o );
			$out[] = array(
				'id'        => 'h' . $o['key'] . $o['date']->format( 'Y' ) . substr( md5( wp_json_encode( $c ) ), 0, 4 ),
				'kind'      => 'holiday',
				'theme'     => $c['theme'],
				'icon'      => SDSC_Notices::icon_url( self::icon_slug( $o['key'] ) ),
				'title'     => $t['en'][0],
				'text'      => $t['en'][1],
				'title_es'  => 'en_es' === $c['lang'] ? $t['es'][0] : '',
				'text_es'   => 'en_es' === $c['lang'] ? $t['es'][1] : '',
				'link'      => '',
				'linktext'  => '',
				'placement' => $c['placement'],
				'home'      => $c['home'],
				'order'     => $c['closed'] ? 1 : 3,
			);
		}
		return $out;
	}

	/** Compact map of dates → holiday for calendars and date pickers (this year + next). */
	public static function map() {
		$now = new DateTimeImmutable( 'today', self::tz() );
		$out = array();
		foreach ( self::between( $now->modify( 'first day of january this year' ), $now->modify( 'last day of december next year' ) ) as $o ) {
			$k = $o['observed']->format( 'Y-m-d' );
			$out[ $o['date']->format( 'Y-m-d' ) ] = array( 'n' => $o['def']['name'], 'e' => $o['def']['es'], 't' => $o['def']['type'], 'c' => $o['cfg']['closed'] ? 1 : 0 );
			if ( $k !== $o['date']->format( 'Y-m-d' ) ) {
				$out[ $k ] = array( 'n' => $o['def']['name'] . ' (observed)', 'e' => $o['def']['es'] . ' (feriado observado)', 't' => $o['def']['type'], 'c' => $o['cfg']['closed'] ? 1 : 0 );
			}
		}
		return $out;
	}

	/** Loaded on every page: Elementor caches widget HTML, so enqueuing from inside the widget is not reliable. */
	public static function assets() {
		wp_enqueue_style( 'sdsc-holcal', SDSC_URL . 'assets/holcal.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-holcal', SDSC_URL . 'assets/holcal.js', array(), SDSC_VERSION, true );
	}

	public static function data() {
		$h = 'var SDSC_HOLIDAYS=' . wp_json_encode( self::map() ) . ';';
		foreach ( array( 'sdsc-datepicker', 'sdsc-holcal' ) as $s ) {
			if ( wp_script_is( $s, 'registered' ) || wp_script_is( $s, 'enqueued' ) ) { wp_add_inline_script( $s, $h, 'before' ); }
		}
	}

	/* ------------------------------------------------------------------ *
	 * Public calendar
	 * ------------------------------------------------------------------ */

	public static function shortcode( $atts = array() ) {
		return self::calendar_html();
	}

	public static function calendar_html() {
		$list = array_slice( self::upcoming( 12 ), 0, 8 );
		$h  = '<section class="sdhc" aria-labelledby="sdhc-h"><div class="sdhc-head"><p class="sdhc-kicker">Holidays &amp; closures</p><h2 id="sdhc-h">Holiday calendar</h2>';
		$h .= '<p>US federal holidays, Maryland state holidays (MVA offices are closed) and our own office closures.</p></div>';
		$h .= '<div class="sdhc-grid"><div class="sdhc-cal" data-sdhc></div><ol class="sdhc-list">';
		foreach ( $list as $o ) {
			$b = '';
			if ( 'state' === $o['def']['type'] ) { $b .= '<span class="sdhc-b sdhc-b--mva">MVA closed</span>'; }
			if ( $o['cfg']['closed'] ) { $b .= '<span class="sdhc-b sdhc-b--off">Our office closed</span>'; }
			if ( 'observance' === $o['def']['type'] ) { $b .= '<span class="sdhc-b">Observance</span>'; }
			$obs = $o['observed']->format( 'Y-m-d' ) !== $o['date']->format( 'Y-m-d' ) ? ' <small>(observed ' . esc_html( $o['observed']->format( 'D, M j' ) ) . ')</small>' : '';
			$h  .= '<li><img class="sdhc-ic sd3d" src="' . esc_url( SDSC_Notices::icon_url( self::icon_slug( $o['key'] ) ) ) . '" alt="" width="44" height="44" loading="lazy" decoding="async" /><time datetime="' . esc_attr( $o['date']->format( 'Y-m-d' ) ) . '"><b>' . esc_html( $o['date']->format( 'M' ) ) . '</b>' . esc_html( $o['date']->format( 'j' ) ) . '</time><div><strong>' . esc_html( $o['def']['name'] ) . '</strong>' . $obs . '<br><span class="sdhc-es" lang="es">' . esc_html( $o['def']['es'] ) . '</span><div class="sdhc-bs">' . $b . '</div></div></li>';
		}
		$h .= '</ol></div></section>';
		return $h;
	}

	/* ------------------------------------------------------------------ *
	 * Admin tab (inside Banners & alerts)
	 * ------------------------------------------------------------------ */

	public static function save() {
		if ( ! SDSC_Notices::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_holidays' );
		$in  = isset( $_POST['h'] ) && is_array( $_POST['h'] ) ? wp_unslash( $_POST['h'] ) : array();
		$out = array();
		foreach ( array_keys( self::defs() ) as $k ) {
			$r = $in[ $k ] ?? array();
			$out[ $k ] = array(
				'banner'    => ! empty( $r['banner'] ),
				'days'      => max( 0, min( 30, (int) ( $r['days'] ?? 0 ) ) ),
				'placement' => in_array( $r['placement'] ?? '', array( 'bar', 'popup', 'both' ), true ) ? $r['placement'] : 'bar',
				'home'      => ! empty( $r['home'] ),
				'closed'    => ! empty( $r['closed'] ),
				'lang'      => 'en' === ( $r['lang'] ?? '' ) ? 'en' : 'en_es',
				'theme'     => isset( SDSC_Notices::themes()[ $r['theme'] ?? '' ] ) ? $r['theme'] : ( self::defs()[ $k ]['theme'] ),
				't'         => sanitize_text_field( $r['t'] ?? '' ),
				'x'         => sanitize_textarea_field( $r['x'] ?? '' ),
				'te'        => sanitize_text_field( $r['te'] ?? '' ),
				'xe'        => sanitize_textarea_field( $r['xe'] ?? '' ),
			);
		}
		update_option( self::OPT, $out, false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-notices&tab=holidays&saved=1' ) );
		exit;
	}

	public static function admin_tab() {
		$types = array( 'state' => 'Federal &amp; Maryland holiday · MVA closed', 'md' => 'Maryland state holiday · MVA closed', 'observance' => 'Observance' );
		$rows  = self::upcoming( 12 );
		?>
		<p style="max-width:900px">Every US and Maryland holiday for the next 12 months. Turn a holiday's banner on, choose how many days before it starts showing, where it shows, and whether <strong>our office is closed</strong> that day. Banners repeat automatically every year. Maryland state holidays automatically say <em>“MVA offices are closed on …”</em> (in English and Spanish), using the observed day when the holiday falls on a weekend.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sdsc_holidays_save" />
			<?php wp_nonce_field( 'sdsc_holidays' ); ?>
			<table class="widefat striped sdhc-admin">
				<thead><tr><th style="width:130px">Date</th><th>Holiday</th><th style="width:70px">Banner</th><th style="width:90px">Days before</th><th style="width:150px">Where</th><th style="width:120px">Homepage pop-up</th><th style="width:110px">Office closed</th><th style="width:120px">Language</th><th style="width:80px"></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $o ) :
					$k = $o['key']; $c = $o['cfg']; $d = $o['def']; $n = 'h[' . $k . ']';
					?>
					<tr>
						<td><strong><?php echo esc_html( $o['date']->format( 'D, M j, Y' ) ); ?></strong><?php if ( $o['observed'] != $o['date'] ) : ?><br><small>Observed <?php echo esc_html( $o['observed']->format( 'D, M j' ) ); ?></small><?php endif; ?></td>
						<td><strong><?php echo esc_html( $d['name'] ); ?></strong><br><small><?php echo wp_kses_post( $types[ in_array( $k, array( 'native', 'election' ), true ) ? 'md' : $d['type'] ] ); ?></small></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[banner]" value="1" <?php checked( $c['banner'] ); ?> aria-label="Banner for <?php echo esc_attr( $d['name'] ); ?>" /></td>
						<td><input type="number" min="0" max="30" name="<?php echo esc_attr( $n ); ?>[days]" value="<?php echo (int) $c['days']; ?>" style="width:64px" /></td>
						<td><select name="<?php echo esc_attr( $n ); ?>[placement]"><option value="bar" <?php selected( $c['placement'], 'bar' ); ?>>Top bar</option><option value="popup" <?php selected( $c['placement'], 'popup' ); ?>>Pop-up</option><option value="both" <?php selected( $c['placement'], 'both' ); ?>>Both</option></select></td>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[home]" value="1" <?php checked( $c['home'] ); ?> /> Important</label></td>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[closed]" value="1" <?php checked( $c['closed'] ); ?> /> Closed</label></td>
						<td><select name="<?php echo esc_attr( $n ); ?>[lang]"><option value="en_es" <?php selected( $c['lang'], 'en_es' ); ?>>English + Español</option><option value="en" <?php selected( $c['lang'], 'en' ); ?>>English only</option></select></td>
						<td><button type="button" class="button-link" onclick="var r=this.closest('tr').nextElementSibling;r.hidden=!r.hidden;">Edit text</button></td>
					</tr>
					<tr hidden class="sdhc-edit"><td></td><td colspan="8">
						<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:1000px">
							<div><b>English</b><br><input class="widefat" name="<?php echo esc_attr( $n ); ?>[t]" placeholder="<?php echo esc_attr( $d['t'] ); ?>" value="<?php echo esc_attr( $c['t'] ); ?>" /><textarea class="widefat" rows="2" name="<?php echo esc_attr( $n ); ?>[x]" placeholder="<?php echo esc_attr( $d['x'] ); ?>"><?php echo esc_textarea( $c['x'] ); ?></textarea></div>
							<div lang="es"><b>Español</b><br><input class="widefat" name="<?php echo esc_attr( $n ); ?>[te]" placeholder="<?php echo esc_attr( $d['te'] ); ?>" value="<?php echo esc_attr( $c['te'] ); ?>" /><textarea class="widefat" rows="2" name="<?php echo esc_attr( $n ); ?>[xe]" placeholder="<?php echo esc_attr( $d['xe'] ); ?>"><?php echo esc_textarea( $c['xe'] ); ?></textarea></div>
						</div>
						<p class="description">Leave empty to use the suggested text shown in grey. The MVA / office-closed sentence is added automatically.
							Design: <select name="<?php echo esc_attr( $n ); ?>[theme]"><?php foreach ( SDSC_Notices::themes() as $tk => $tl ) : ?><option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $c['theme'], $tk ); ?>><?php echo esc_html( $tl ); ?></option><?php endforeach; ?></select></p>
					</td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Save holiday settings' ); ?>
		</form>
		<p class="description">Show the public holiday calendar on any page with the shortcode <code>[sdsc_holidays]</code>. It is already on the Contact page under the map, and holidays are marked in every date picker on the site.</p>
		<?php
	}
}
