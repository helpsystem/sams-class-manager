<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Banners & alerts.
 *
 * 1) Banners — holiday greetings (US holidays + Nowruz, auto-dated), office-closed notices
 *    (weather / holiday) and custom announcements. Each has a start/end time and shows as a
 *    bar above the header, a one-time pop-up card, or both.
 * 2) Montgomery County alerts — official National Weather Service watches/warnings/advisories
 *    for both Montgomery County forecast zones (MDZ503, MDZ504), checked every 15 minutes.
 *    The admin page shows every alert in full; an alert appears on the website only after
 *    Saghar (or another admin) presses "Show on website". It disappears when NWS expires it.
 *
 * The page HTML stays cacheable: an empty placeholder is printed and a small script asks
 * /wp-json/sdsc/v1/notices what is live right now.
 */
class SDSC_Notices {

	const CAP     = 'sdsc_manage_notices';
	const OPT     = 'sdsc_notices';
	const ALERTS  = 'sdsc_alerts';
	const AMETA   = 'sdsc_alerts_meta';
	const ZONES   = 'MDZ503,MDZ504';
	const CRON    = 'sdsc_alerts_fetch';

	/* ------------------------------------------------------------------ */

	public static function init() {
		add_action( 'init', array( __CLASS__, 'caps' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 35 );
		add_action( 'admin_post_sdsc_notice_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sdsc_notice_remove', array( __CLASS__, 'remove' ) );
		add_action( 'admin_post_sdsc_alert_toggle', array( __CLASS__, 'alert_toggle' ) );
		add_action( 'admin_post_sdsc_alert_refresh', array( __CLASS__, 'alert_refresh' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'placeholder' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 9 );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::CRON, array( __CLASS__, 'fetch' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, 'sdsc_15min', self::CRON );
		}
	}

	public static function caps() {
		if ( get_option( 'sdsc_notices_caps' ) ) { return; }
		$r = get_role( 'administrator' );
		if ( $r ) { $r->add_cap( self::CAP ); }
		update_option( 'sdsc_notices_caps', 1, false );
	}

	public static function can() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	public static function schedules( $s ) {
		$s['sdsc_15min'] = array( 'interval' => 900, 'display' => 'Every 15 minutes' );
		return $s;
	}

	protected static function tz() {
		return wp_timezone();
	}

	protected static function now() {
		return new DateTimeImmutable( 'now', self::tz() );
	}

	/* ------------------------------------------------------------------ *
	 * Templates
	 * ------------------------------------------------------------------ */

	public static function themes() {
		return array(
			'info'      => 'Blue — information',
			'closure'   => 'Red — office closed',
			'snow'      => 'Winter / snow',
			'halloween' => 'Halloween',
			'autumn'    => 'Autumn leaves',
			'fireworks' => 'Fireworks',
			'sparkle'   => 'Gold sparkle',
			'flag'      => 'Red, white & blue',
			'hearts'    => 'Hearts',
			'shamrock'  => 'Shamrock green',
			'spring'    => 'Spring (Nowruz)',
			'flowers'   => 'Flowers',
			'calm'      => 'Calm navy',
			'celebrate' => 'Celebration (school green)',
		);
	}

	/** 3D icons (Microsoft Fluent Emoji 3D, MIT) stored in assets/img/3d. */
	public static function icons() {
		return array(
			'information' => 'Info', 'megaphone' => 'Megaphone', 'stop_sign' => 'Stop sign', 'warning' => 'Warning',
			'snowman' => 'Snowman', 'cloud_with_snow' => 'Snow cloud', 'cloud_with_lightning_and_rain' => 'Storm', 'sun_behind_rain_cloud' => 'Rain', 'tornado' => 'Tornado',
			'jack-o-lantern' => 'Pumpkin', 'fallen_leaf' => 'Autumn leaf', 'turkey' => 'Turkey', 'christmas_tree' => 'Christmas tree',
			'fireworks' => 'Fireworks', 'sparkles' => 'Sparkles', 'party_popper' => 'Party popper', 'glowing_star' => 'Star',
			'red_heart' => 'Heart', 'shamrock' => 'Shamrock', 'tulip' => 'Tulip', 'bouquet' => 'Bouquet', 'dove' => 'Dove',
			'military_medal' => 'Medal', 'classical_building' => 'Capitol', 'ballot_box_with_ballot' => 'Ballot box', 'feather' => 'Feather',
			'crab' => 'Maryland crab', 'hammer_and_wrench' => 'Tools', 'sailboat' => 'Sailboat', 'necktie' => 'Necktie', 'automobile' => 'Car',
		);
	}

	public static function theme_icon( $theme ) {
		$m = array( 'info' => 'information', 'closure' => 'stop_sign', 'snow' => 'snowman', 'halloween' => 'jack-o-lantern', 'autumn' => 'fallen_leaf', 'fireworks' => 'fireworks', 'sparkle' => 'sparkles', 'flag' => 'glowing_star', 'hearts' => 'red_heart', 'shamrock' => 'shamrock', 'spring' => 'tulip', 'flowers' => 'bouquet', 'calm' => 'dove', 'celebrate' => 'party_popper', 'alert-severe' => 'warning', 'alert-moderate' => 'warning', 'alert-minor' => 'sun_behind_rain_cloud' );
		return $m[ $theme ] ?? 'megaphone';
	}

	public static function icon_url( $slug ) {
		$slug = sanitize_file_name( (string) $slug );
		return $slug && isset( self::icons()[ $slug ] ) ? SDSC_URL . 'assets/img/3d/' . $slug . '.webp' : '';
	}

	protected static function alert_icon( $event ) {
		$e = strtolower( (string) $event );
		if ( preg_match( '/tornado/', $e ) ) { return 'tornado'; }
		if ( preg_match( '/snow|winter|ice|blizzard|freez|frost|sleet|wind chill|cold/', $e ) ) { return 'cloud_with_snow'; }
		if ( preg_match( '/thunder|storm|hurricane|tropical|wind/', $e ) ) { return 'cloud_with_lightning_and_rain'; }
		if ( preg_match( '/flood|rain/', $e ) ) { return 'sun_behind_rain_cloud'; }
		return 'warning';
	}

	/* ------------------------------------------------------------------ *
	 * Storage
	 * ------------------------------------------------------------------ */

	public static function all() {
		$v = get_option( self::OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	protected static function status( $n, $now = null ) {
		$now = $now ? $now : self::now();
		if ( empty( $n['active'] ) ) { return 'off'; }
		$s = self::dt( $n['start'] ?? '' );
		$e = self::dt( $n['end'] ?? '' );
		if ( $e && $e < $now ) { return 'ended'; }
		if ( $s && $s > $now ) { return 'scheduled'; }
		return 'live';
	}

	protected static function dt( $s ) {
		if ( ! $s ) { return null; }
		try { return new DateTimeImmutable( $s, self::tz() ); } catch ( Exception $e ) { return null; }
	}

	public static function save() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_notice' );
		$p   = wp_unslash( $_POST );
		$all = self::all();
		$id  = sanitize_key( $p['id'] ?? '' );
		if ( ! $id || ! isset( $all[ $id ] ) ) { $id = 'n' . wp_generate_password( 8, false, false ); }
		$theme = sanitize_key( $p['theme'] ?? 'info' );
		$place = in_array( $p['placement'] ?? '', array( 'bar', 'popup', 'both' ), true ) ? $p['placement'] : 'bar';
		$fmt   = function ( $v ) { $v = sanitize_text_field( (string) $v ); return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v ) ? str_replace( 'T', ' ', $v ) : ''; };
		$link  = esc_url_raw( trim( (string) ( $p['link'] ?? '' ) ) );
		if ( $link && ! preg_match( '#^(https?:|tel:|mailto:|/)#i', $link ) ) { $link = ''; }
		$all[ $id ] = array(
			'id'        => $id,
			'kind'      => sanitize_key( $p['kind'] ?? 'custom' ),
			'template'  => sanitize_key( $p['template'] ?? '' ),
			'theme'     => isset( self::themes()[ $theme ] ) ? $theme : 'info',
			'title'     => sanitize_text_field( $p['title'] ?? '' ),
			'text'      => sanitize_textarea_field( $p['text'] ?? '' ),
			'link'      => $link,
			'linktext'  => sanitize_text_field( $p['linktext'] ?? '' ),
			'lang'      => 'en_es' === ( $p['lang'] ?? '' ) ? 'en_es' : 'en',
			'title_es'  => sanitize_text_field( $p['title_es'] ?? '' ),
			'text_es'   => sanitize_textarea_field( $p['text_es'] ?? '' ),
			'linktext_es' => sanitize_text_field( $p['linktext_es'] ?? '' ),
			'home'      => ! empty( $p['home'] ),
			'icon'      => isset( self::icons()[ $p['icon'] ?? '' ] ) ? $p['icon'] : '',
			'start'     => $fmt( $p['start'] ?? '' ),
			'end'       => $fmt( $p['end'] ?? '' ),
			'placement' => $place,
			'active'    => ! empty( $p['active'] ),
			'by'        => wp_get_current_user()->display_name,
			'updated'   => current_time( 'mysql' ),
		);
		update_option( self::OPT, $all, false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-notices&saved=1' ) );
		exit;
	}

	public static function remove() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_notice_rm' );
		$id  = sanitize_key( $_GET['id'] ?? '' ); // phpcs:ignore
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPT, $all, false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-notices&removed=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Montgomery County alerts (National Weather Service)
	 * ------------------------------------------------------------------ */

	public static function alerts() {
		$v = get_option( self::ALERTS, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function fetch() {
		$r = wp_remote_get( 'https://api.weather.gov/alerts/active?zone=' . self::ZONES, array(
			'timeout' => 15,
			'headers' => array( 'User-Agent' => 'SamsDrivingSchool-site (' . home_url( '/' ) . ')', 'Accept' => 'application/geo+json' ),
		) );
		$meta = get_option( self::AMETA, array() );
		$meta = is_array( $meta ) ? $meta : array();
		$meta['checked'] = time();
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			$meta['error'] = is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r );
			update_option( self::AMETA, $meta, false );
			return;
		}
		$j    = json_decode( wp_remote_retrieve_body( $r ), true );
		$old  = self::alerts();
		$new  = array();
		foreach ( (array) ( $j['features'] ?? array() ) as $f ) {
			$p  = $f['properties'] ?? array();
			$id = (string) ( $p['id'] ?? '' );
			if ( ! $id || 'Actual' !== ( $p['status'] ?? '' ) ) { continue; }
			$key = 'a' . substr( md5( $id ), 0, 12 );
			$new[ $key ] = array(
				'key'         => $key,
				'event'       => sanitize_text_field( $p['event'] ?? '' ),
				'headline'    => sanitize_text_field( $p['headline'] ?? '' ),
				'severity'    => sanitize_text_field( $p['severity'] ?? '' ),
				'urgency'     => sanitize_text_field( $p['urgency'] ?? '' ),
				'area'        => sanitize_text_field( $p['areaDesc'] ?? '' ),
				'description' => sanitize_textarea_field( $p['description'] ?? '' ),
				'instruction' => sanitize_textarea_field( $p['instruction'] ?? '' ),
				'sender'      => sanitize_text_field( $p['senderName'] ?? 'National Weather Service' ),
				'sent'        => sanitize_text_field( $p['sent'] ?? '' ),
				'onset'       => sanitize_text_field( $p['onset'] ?? ( $p['effective'] ?? '' ) ),
				'ends'        => sanitize_text_field( $p['ends'] ?? ( $p['expires'] ?? '' ) ),
				'show'        => ! empty( $old[ $key ]['show'] ),
				'show_by'     => $old[ $key ]['show_by'] ?? '',
				'first_seen'  => $old[ $key ]['first_seen'] ?? time(),
				'seen'        => ! empty( $old[ $key ]['seen'] ),
			);
		}
		$meta['error'] = '';
		update_option( self::ALERTS, $new, false );
		update_option( self::AMETA, $meta, false );
	}

	public static function alert_refresh() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_alert_refresh' );
		self::fetch();
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-notices&tab=alerts&checked=1' ) );
		exit;
	}

	public static function alert_toggle() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_alert_toggle' );
		$key = sanitize_key( $_GET['key'] ?? '' ); // phpcs:ignore
		$a   = self::alerts();
		if ( isset( $a[ $key ] ) ) {
			$a[ $key ]['show']    = empty( $a[ $key ]['show'] );
			$a[ $key ]['show_by'] = $a[ $key ]['show'] ? wp_get_current_user()->display_name . ' · ' . current_time( 'M j, g:i a' ) : '';
			update_option( self::ALERTS, $a, false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-notices&tab=alerts' ) );
		exit;
	}

	protected static function alert_live( $a, $now ) {
		if ( empty( $a['ends'] ) ) { return true; }
		try { return new DateTimeImmutable( $a['ends'] ) > $now; } catch ( Exception $e ) { return true; }
	}

	protected static function local_time( $iso ) {
		if ( ! $iso ) { return ''; }
		try { return ( new DateTimeImmutable( $iso ) )->setTimezone( self::tz() )->format( 'D M j, g:i A' ); } catch ( Exception $e ) { return $iso; }
	}

	/* ------------------------------------------------------------------ *
	 * Public output
	 * ------------------------------------------------------------------ */

	/** What is live right now — used by the REST endpoint. */
	public static function live() {
		$now = self::now();
		$out = array();
		foreach ( self::alerts() as $a ) {
			if ( empty( $a['show'] ) || ! self::alert_live( $a, $now ) ) { continue; }
			$sev   = strtolower( $a['severity'] );
			$out[] = array(
				'id'        => $a['key'] . substr( md5( $a['ends'] ), 0, 4 ),
				'kind'      => 'alert',
				'theme'     => in_array( $sev, array( 'extreme', 'severe' ), true ) ? 'alert-severe' : ( 'moderate' === $sev ? 'alert-moderate' : 'alert-minor' ),
				'icon'      => self::icon_url( self::alert_icon( $a['event'] ) ),
				'title'     => $a['event'] . ' — Montgomery County',
				'text'      => ( $a['ends'] ? 'Until ' . self::local_time( $a['ends'] ) . '. ' : '' ) . wp_trim_words( $a['description'], 40 ),
				'link'      => 'https://www.weather.gov/lwx/',
				'linktext'  => 'National Weather Service',
				'placement' => 'bar',
				'order'     => 0,
			);
		}
		foreach ( self::all() as $n ) {
			if ( 'live' !== self::status( $n, $now ) ) { continue; }
			$out[] = array(
				'id'        => $n['id'] . substr( md5( $n['updated'] ), 0, 4 ),
				'kind'      => $n['kind'],
				'theme'     => $n['theme'],
				'icon'      => self::icon_url( ! empty( $n['icon'] ) ? $n['icon'] : self::theme_icon( $n['theme'] ) ),
				'title'     => $n['title'],
				'text'      => $n['text'],
				'link'      => $n['link'],
				'linktext'  => $n['linktext'],
				'title_es'  => 'en_es' === ( $n['lang'] ?? 'en' ) ? ( $n['title_es'] ?? '' ) : '',
				'text_es'   => 'en_es' === ( $n['lang'] ?? 'en' ) ? ( $n['text_es'] ?? '' ) : '',
				'linktext_es' => 'en_es' === ( $n['lang'] ?? 'en' ) ? ( $n['linktext_es'] ?? '' ) : '',
				'placement' => $n['placement'],
				'home'      => ! empty( $n['home'] ),
				'order'     => 'closure' === $n['kind'] ? 1 : 2,
			);
		}
		if ( class_exists( 'SDSC_Holidays' ) ) { $out = array_merge( $out, SDSC_Holidays::live_items() ); }
		usort( $out, function ( $a, $b ) { return $a['order'] <=> $b['order']; } );
		return $out;
	}

	public static function rest() {
		register_rest_route( 'sdsc/v1', '/notices', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$r = rest_ensure_response( array( 'items' => self::live() ) );
				$r->header( 'Cache-Control', 'public, max-age=60' );
				return $r;
			},
		) );
	}

	public static function placeholder() {
		echo '<div id="sdnb" class="sdnb" data-sdnb aria-live="polite"></div>';
	}

	public static function assets() {
		if ( is_admin() ) { return; }
		wp_enqueue_style( 'sdsc-notices', SDSC_URL . 'assets/notices.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-notices', SDSC_URL . 'assets/notices.js', array(), SDSC_VERSION, true );
		wp_localize_script( 'sdsc-notices', 'SDSC_NB', array( 'url' => rest_url( 'sdsc/v1/notices' ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Admin
	 * ------------------------------------------------------------------ */

	public static function menu() {
		$n     = count( array_filter( self::alerts(), function ( $a ) { return empty( $a['show'] ); } ) );
		$badge = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		add_submenu_page( 'sdsc-dashboard', 'Banners & alerts', 'Banners & alerts' . $badge, self::CAP, 'sdsc-notices', array( __CLASS__, 'page' ) );
	}

	public static function admin_notice() {
		if ( ! self::can() ) { return; }
		$s = get_current_screen();
		if ( $s && false !== strpos( (string) $s->id, 'sdsc-notices' ) ) { return; }
		$new = array_filter( self::alerts(), function ( $a ) { return empty( $a['show'] ); } );
		if ( ! $new ) { return; }
		$a = reset( $new );
		echo '<div class="notice notice-warning"><p><strong>Montgomery County alert:</strong> ' . esc_html( $a['event'] ) . ( count( $new ) > 1 ? ' (+' . ( count( $new ) - 1 ) . ' more)' : '' ) . ' — not shown on the website. <a href="' . esc_url( admin_url( 'admin.php?page=sdsc-notices&tab=alerts' ) ) . '">Review and decide</a></p></div>';
	}

	public static function dashboard_widget() {
		if ( ! self::can() ) { return; }
		wp_add_dashboard_widget( 'sdsc_alerts_widget', 'Montgomery County alerts', function () {
			$a = self::alerts();
			if ( ! $a ) { echo '<p>No active weather alerts for Montgomery County.</p>'; }
			foreach ( $a as $x ) {
				echo '<p><strong>' . esc_html( $x['event'] ) . '</strong> — ' . ( $x['show'] ? '<span style="color:#00703c">shown on website</span>' : '<span style="color:#b32d2e">not shown</span>' ) . '<br><small>' . esc_html( $x['ends'] ? 'Until ' . self::local_time( $x['ends'] ) : '' ) . '</small></p>';
			}
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=sdsc-notices' ) ) . '">Banners & alerts</a></p>';
		} );
	}

	public static function page() {
		if ( ! self::can() ) { return; }
		$tab = sanitize_key( $_GET['tab'] ?? 'banners' ); // phpcs:ignore
		wp_enqueue_style( 'sdsc-notices', SDSC_URL . 'assets/notices.css', array(), SDSC_VERSION );
		?>
		<div class="wrap sdnb-admin">
			<h1>Banners &amp; alerts</h1>
			<?php if ( isset( $_GET['saved'] ) && empty( $_GET['tab'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Banner saved. It shows on the site within a minute of its start time.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['removed'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Banner removed.</p></div><?php endif; ?>
			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo ! in_array( $tab, array( 'alerts', 'holidays' ), true ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-notices' ) ); ?>">Banners</a>
				<a class="nav-tab <?php echo 'holidays' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-notices&tab=holidays' ) ); ?>">Holiday calendar</a>
				<a class="nav-tab <?php echo 'alerts' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-notices&tab=alerts' ) ); ?>">Montgomery County alerts <?php $c = count( self::alerts() ); echo $c ? '(' . (int) $c . ')' : ''; ?></a>
			</nav>
			<?php if ( isset( $_GET['saved'] ) && 'holidays' === $tab ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Holiday settings saved.</p></div><?php endif; ?>
			<?php
			if ( 'alerts' === $tab ) { self::page_alerts(); }
			elseif ( 'holidays' === $tab && class_exists( 'SDSC_Holidays' ) ) { SDSC_Holidays::admin_tab(); }
			else { self::page_banners(); }
			?>
		</div>
		<?php
	}

	protected static function page_banners() {
		$all  = self::all();
		$now  = self::now();
		$edit = isset( $_GET['edit'] ) && isset( $all[ sanitize_key( $_GET['edit'] ) ] ) ? $all[ sanitize_key( $_GET['edit'] ) ] : null; // phpcs:ignore
		$tpl  = array();
		$seen = array();
		foreach ( SDSC_Holidays::upcoming( 13 ) as $o ) {
			if ( isset( $seen[ $o['key'] ] ) ) { continue; }
			$seen[ $o['key'] ] = 1;
			$d = $o['def']; $first = $o['date']->modify( '-' . (int) $d['days'] . ' days' );
			$tpl[ $o['key'] ] = array( 'kind' => 'holiday', 'label' => $d['name'], 'theme' => $d['theme'], 'icon' => SDSC_Holidays::icon_slug( $o['key'] ), 'title' => $d['t'], 'text' => $d['x'], 'title_es' => $d['te'], 'text_es' => $d['xe'], 'start' => $first->setTime( 0, 0 )->format( 'Y-m-d\\TH:i' ), 'end' => $o['date']->setTime( 23, 59 )->format( 'Y-m-d\\TH:i' ), 'date' => $o['date']->format( 'M j, Y' ), 'placement' => 'bar' );
		}
		$tomorrow = $now->modify( '+1 day' );
		$tpl['closed_weather'] = array( 'kind' => 'closure', 'label' => 'Closed — weather', 'theme' => 'snow', 'title' => 'Office closed due to snow', 'text' => 'Sam’s Driving School is closed on ' . $tomorrow->format( 'l, F j' ) . ' because of snow. Lessons and classes on that day will be rescheduled — we will contact you.', 'title_es' => 'Oficina cerrada por la nieve', 'text_es' => 'Sam’s Driving School estará cerrada el ' . strtolower( SDSC_Holidays::es_date( $tomorrow ) ) . ' por la nieve. Las clases y lecciones de ese día se reprogramarán; nos comunicaremos con usted.', 'start' => $now->format( 'Y-m-d\TH:i' ), 'end' => $tomorrow->setTime( 23, 59 )->format( 'Y-m-d\TH:i' ), 'placement' => 'both', 'date' => '' );
		$tpl['closed_holiday'] = array( 'kind' => 'closure', 'label' => 'Closed — holiday', 'theme' => 'closure', 'title' => 'Holiday hours', 'text' => 'Our office is closed on ' . $tomorrow->format( 'l, F j' ) . '. We reopen the next day at 8 AM.', 'title_es' => 'Horario de feriado', 'text_es' => 'Nuestra oficina estará cerrada el ' . SDSC_Holidays::es_date( $tomorrow ) . '. Volvemos a abrir al día siguiente a las 8 AM.', 'start' => $now->format( 'Y-m-d\TH:i' ), 'end' => $tomorrow->setTime( 23, 59 )->format( 'Y-m-d\TH:i' ), 'placement' => 'bar', 'date' => '' );
		$tpl['custom'] = array( 'kind' => 'custom', 'label' => 'Custom announcement', 'theme' => 'info', 'title' => '', 'text' => '', 'title_es' => '', 'text_es' => '', 'start' => $now->format( 'Y-m-d\TH:i' ), 'end' => $now->modify( '+7 days' )->setTime( 23, 59 )->format( 'Y-m-d\TH:i' ), 'placement' => 'bar', 'date' => '' );
		$v = $edit ? $edit : array( 'id' => '', 'kind' => 'custom', 'template' => '', 'theme' => 'info', 'title' => '', 'text' => '', 'link' => '', 'linktext' => '', 'icon' => '', 'lang' => 'en', 'title_es' => '', 'text_es' => '', 'linktext_es' => '', 'home' => false, 'start' => '', 'end' => '', 'placement' => 'bar', 'active' => true );
		$labels = array( 'live' => array( 'Live now', '#00703c' ), 'scheduled' => array( 'Scheduled', '#2271b1' ), 'ended' => array( 'Ended', '#787c82' ), 'off' => array( 'Turned off', '#787c82' ) );
		?>
		<h2>Your banners</h2>
		<?php if ( ! $all ) : ?>
			<p>No banners yet. Pick a template below.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th>Banner</th><th>Shows</th><th>Where</th><th>Status</th><th></th></tr></thead>
				<tbody>
				<?php
				uasort( $all, function ( $a, $b ) { return strcmp( $a['start'], $b['start'] ); } );
				foreach ( $all as $n ) :
					$st = self::status( $n, $now );
					?>
					<tr>
						<td><strong><?php echo esc_html( $n['title'] ? $n['title'] : '(no title)' ); ?></strong><br><small><?php echo esc_html( wp_trim_words( $n['text'], 14 ) ); ?></small></td>
						<td><?php echo esc_html( ( $n['start'] ? date_i18n( 'M j, g:i a', strtotime( $n['start'] ) ) : 'now' ) . ' → ' . ( $n['end'] ? date_i18n( 'M j, g:i a', strtotime( $n['end'] ) ) : 'no end' ) ); ?></td>
						<td><?php echo esc_html( array( 'bar' => 'Top bar', 'popup' => 'Pop-up', 'both' => 'Top bar + pop-up' )[ $n['placement'] ] ?? '' ); ?></td>
						<td><span style="color:<?php echo esc_attr( $labels[ $st ][1] ); ?>;font-weight:700">● <?php echo esc_html( $labels[ $st ][0] ); ?></span></td>
						<td style="white-space:nowrap"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-notices&edit=' . $n['id'] . '#sdnb-form' ) ); ?>">Edit</a>
							<a class="button-link-delete" style="margin-left:8px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_notice_remove&id=' . $n['id'] ), 'sdsc_notice_rm' ) ); ?>" onclick="return confirm('Remove this banner?');">Remove</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 id="sdnb-form" style="margin-top:28px"><?php echo $edit ? 'Edit banner' : 'New banner'; ?></h2>
		<?php if ( ! $edit ) : ?>
			<p>Choose a template — it fills in the text, design and dates. You can change anything before saving.</p>
			<div class="sdnb-tpls">
				<?php foreach ( $tpl as $k => $t ) : ?>
					<button type="button" class="sdnb-tpl sdnb-t--<?php echo esc_attr( $t['theme'] ); ?>" data-tpl="<?php echo esc_attr( $k ); ?>">
						<b><?php echo esc_html( $t['label'] ); ?></b><small><?php echo esc_html( $t['date'] ? $t['date'] : ( 'closure' === $t['kind'] ? 'Office notice' : 'Your own text' ) ); ?></small>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdnb-form">
			<input type="hidden" name="action" value="sdsc_notice_save" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $v['id'] ); ?>" />
			<input type="hidden" name="kind" value="<?php echo esc_attr( $v['kind'] ); ?>" />
			<input type="hidden" name="template" value="<?php echo esc_attr( $v['template'] ); ?>" />
			<?php wp_nonce_field( 'sdsc_notice' ); ?>
			<table class="form-table">
				<tr><th><label for="sdnb-title">Headline</label></th><td><input id="sdnb-title" name="title" class="regular-text" maxlength="90" value="<?php echo esc_attr( $v['title'] ); ?>" required /></td></tr>
				<tr><th><label for="sdnb-text">Message</label></th><td><textarea id="sdnb-text" name="text" rows="3" class="large-text" maxlength="400"><?php echo esc_textarea( $v['text'] ); ?></textarea><p class="description">Keep it short. Don't announce a price or discount here unless checkout already charges it.</p></td></tr>
				<tr><th>Button (optional)</th><td><input name="linktext" placeholder="Button text, e.g. Call us" value="<?php echo esc_attr( $v['linktext'] ); ?>" /> <input name="link" class="regular-text" placeholder="Link, e.g. tel:+13017263030 or /services/" value="<?php echo esc_attr( $v['link'] ); ?>" /></td></tr>
				<tr><th>Language</th><td><label><input type="radio" name="lang" value="en" <?php checked( $v['lang'] ?? 'en', 'en' ); ?> /> English only</label> &nbsp; <label><input type="radio" name="lang" value="en_es" <?php checked( $v['lang'] ?? 'en', 'en_es' ); ?> /> English + Español</label><p class="description">With Spanish, visitors get an EN / ES switch (Spanish opens first for Spanish-language browsers).</p></td></tr>
				<tr class="sdnb-es"><th><label for="sdnb-title-es">Título (Español)</label></th><td lang="es"><input id="sdnb-title-es" name="title_es" class="regular-text" maxlength="90" value="<?php echo esc_attr( $v['title_es'] ?? '' ); ?>" /></td></tr>
				<tr class="sdnb-es"><th><label for="sdnb-text-es">Mensaje (Español)</label></th><td lang="es"><textarea id="sdnb-text-es" name="text_es" rows="3" class="large-text" maxlength="400"><?php echo esc_textarea( $v['text_es'] ?? '' ); ?></textarea><input name="linktext_es" placeholder="Texto del botón, p. ej. Llámenos" value="<?php echo esc_attr( $v['linktext_es'] ?? '' ); ?>" /></td></tr>
				<tr><th><label for="sdnb-theme">Design</label></th><td><select id="sdnb-theme" name="theme"><?php foreach ( self::themes() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v['theme'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th><label for="sdnb-icon">3D picture</label></th><td><select id="sdnb-icon" name="icon"><option value="">Automatic (matches the design)</option><?php foreach ( self::icons() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v['icon'] ?? '', $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select> <img id="sdnb-icon-prev" src="" alt="" width="40" height="40" style="vertical-align:middle;margin-left:8px" /></td></tr>
				<tr><th>Show from / until</th><td><input type="datetime-local" name="start" value="<?php echo esc_attr( $v['start'] ? str_replace( ' ', 'T', $v['start'] ) : '' ); ?>" /> → <input type="datetime-local" name="end" value="<?php echo esc_attr( $v['end'] ? str_replace( ' ', 'T', $v['end'] ) : '' ); ?>" /><p class="description">Site time (<?php echo esc_html( wp_timezone_string() ); ?>). Empty start = now; empty end = until you turn it off.</p></td></tr>
				<tr><th>Where</th><td>
					<label><input type="radio" name="placement" value="bar" <?php checked( $v['placement'], 'bar' ); ?> /> Top bar on every page</label><br>
					<label><input type="radio" name="placement" value="popup" <?php checked( $v['placement'], 'popup' ); ?> /> Pop-up card (once per visitor)</label><br>
					<label><input type="radio" name="placement" value="both" <?php checked( $v['placement'], 'both' ); ?> /> Both — for important notices like a closure</label>
				</td></tr>
				<tr><th>Important</th><td><label><input type="checkbox" name="home" value="1" <?php checked( ! empty( $v['home'] ) ); ?> /> <strong>Show as a pop-up on the homepage</strong> (centered card, shown once per visitor)</label></td></tr>
				<tr><th>On / off</th><td><label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $v['active'] ) ); ?> /> Banner is on</label></td></tr>
				<tr><th>Preview</th><td><div class="sdnb-prev-wrap"><div class="sdnb-bar" id="sdnb-prev"><span class="sdnb-fx" aria-hidden="true"></span><div class="sdnb-in"><span class="sdnb-ic" aria-hidden="true"></span><p><b class="sdnb-h"></b> <span class="sdnb-t"></span></p><a class="sdnb-btn" href="#" onclick="return false"></a></div></div></div></td></tr>
			</table>
			<?php submit_button( $edit ? 'Save changes' : 'Save banner' ); ?>
			<?php if ( $edit ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-notices' ) ); ?>">Cancel</a><?php endif; ?>
		</form>
		<script>
		(function(){
			var T=<?php echo wp_json_encode( $tpl ); ?>, f=document.querySelector('.sdnb-form');
			var IB=<?php echo wp_json_encode( SDSC_URL . 'assets/img/3d/' ); ?>, TI=<?php echo wp_json_encode( array_combine( array_keys( self::themes() ), array_map( array( __CLASS__, 'theme_icon' ), array_keys( self::themes() ) ) ) ); ?>;
			var ICON={info:'ℹ️',closure:'⛔',snow:'❄️',halloween:'🎃',autumn:'🍂',fireworks:'🎆',sparkle:'✨',flag:'🇺🇸',hearts:'❤️',shamrock:'☘️',spring:'🌷',flowers:'💐',calm:'🕊️',celebrate:'🎉'};
			function v(n){return f.querySelector('[name="'+n+'"]');}
			function prev(){var p=document.getElementById('sdnb-prev'),th=v('theme').value;p.className='sdnb-bar sdnb-t--'+th;var ic=v('icon').value||TI[th]||'megaphone';p.querySelector('.sdnb-ic').innerHTML='<img class="sd3d" src="'+IB+ic+'.webp" alt="" width="34" height="34">';document.getElementById('sdnb-icon-prev').src=IB+ic+'.webp';p.querySelector('.sdnb-h').textContent=v('title').value;p.querySelector('.sdnb-t').textContent=v('text').value;var b=p.querySelector('.sdnb-btn');b.textContent=v('linktext').value;b.style.display=v('linktext').value?'':'none';}
			[].forEach.call(document.querySelectorAll('.sdnb-tpl'),function(btn){btn.addEventListener('click',function(){var t=T[btn.getAttribute('data-tpl')];v('kind').value=t.kind;v('template').value=btn.getAttribute('data-tpl');v('theme').value=t.theme;v('title').value=t.title;v('icon').value=t.icon||'';v('text').value=t.text;v('start').value=t.start;if(v('title_es')){v('title_es').value=t.title_es||'';v('text_es').value=t.text_es||'';if(t.title_es){f.querySelector('[name=lang][value=en_es]').checked=true;}}if(t.kind==='closure'){v('linktext_es').value='Llámenos';}v('end').value=t.end;f.querySelector('[name=placement][value="'+t.placement+'"]').checked=true;if(t.kind==='closure'&&!v('linktext').value){v('linktext').value='Call us';v('link').value='tel:+13017263030';}[].forEach.call(document.querySelectorAll('.sdnb-tpl'),function(x){x.classList.toggle('is-on',x===btn);});prev();v('title').focus();});});
			function es(){var on=f.querySelector('[name=lang][value=en_es]').checked;[].forEach.call(f.querySelectorAll('.sdnb-es'),function(r){r.style.display=on?'':'none';});}f.addEventListener('input',prev);f.addEventListener('change',function(){prev();es();});prev();es();
		})();
		</script>
		<?php
	}

	protected static function page_alerts() {
		$a    = self::alerts();
		$meta = get_option( self::AMETA, array() );
		$now  = self::now();
		$sevc = array( 'extreme' => '#7a0000', 'severe' => '#b32d2e', 'moderate' => '#d97706', 'minor' => '#2271b1' );
		?>
		<p style="max-width:860px">Official watches, warnings and advisories from the <strong>National Weather Service</strong> for Montgomery County, MD (both forecast zones: Northwest Montgomery and Central &amp; Southeast Montgomery). The list is checked automatically every 15 minutes. <strong>Nothing appears on the website until you press “Show on website”</strong>, and an alert leaves the website by itself when it expires.</p>
		<p>
			Last checked: <strong><?php echo ! empty( $meta['checked'] ) ? esc_html( human_time_diff( (int) $meta['checked'] ) . ' ago' ) : 'not yet'; ?></strong>
			<?php if ( ! empty( $meta['error'] ) ) : ?><span style="color:#b32d2e"> — last check failed (<?php echo esc_html( $meta['error'] ); ?>)</span><?php endif; ?>
			<a class="button" style="margin-left:8px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_alert_refresh' ), 'sdsc_alert_refresh' ) ); ?>">Check now</a>
		</p>
		<?php if ( ! $a ) : ?>
			<div class="sdnb-empty"><b>No active alerts for Montgomery County right now.</b><br>When the Weather Service issues one (snow, ice, storms, flooding, heat…), it appears here in full and a reminder shows at the top of the admin.</div>
			<p class="description">For a closure, use the <a href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-notices' ) ); ?>">Banners</a> tab → “Closed — weather”. Montgomery County's own Alert Montgomery text/email service has no public feed, so it can't be connected; you can still subscribe to it yourself at alert.montgomerycountymd.gov.</p>
		<?php endif; ?>
		<?php foreach ( $a as $x ) :
			$sev  = strtolower( $x['severity'] );
			$live = self::alert_live( $x, $now );
			?>
			<div class="sdnb-alert" style="border-left-color:<?php echo esc_attr( $sevc[ $sev ] ?? '#787c82' ); ?>">
				<div class="sdnb-alert-h">
					<div>
						<span class="sdnb-sev" style="background:<?php echo esc_attr( $sevc[ $sev ] ?? '#787c82' ); ?>"><?php echo esc_html( $x['severity'] ? $x['severity'] : 'Unknown' ); ?></span>
						<h3><?php echo esc_html( $x['event'] ); ?></h3>
						<p class="sdnb-meta"><?php echo esc_html( $x['area'] ); ?><br>
							From <?php echo esc_html( self::local_time( $x['onset'] ) ); ?> until <strong><?php echo esc_html( self::local_time( $x['ends'] ) ); ?></strong><?php echo $live ? '' : ' (expired)'; ?> · <?php echo esc_html( $x['sender'] ); ?></p>
					</div>
					<div class="sdnb-act">
						<?php if ( $x['show'] ) : ?>
							<p class="sdnb-on">● Shown on website<br><small><?php echo esc_html( $x['show_by'] ); ?></small></p>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_alert_toggle&key=' . $x['key'] ), 'sdsc_alert_toggle' ) ); ?>">Remove from website</a>
						<?php else : ?>
							<p class="sdnb-off">Not shown on website</p>
							<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_alert_toggle&key=' . $x['key'] ), 'sdsc_alert_toggle' ) ); ?>">Show on website</a>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( $x['headline'] ) : ?><p><strong><?php echo esc_html( $x['headline'] ); ?></strong></p><?php endif; ?>
				<div class="sdnb-desc"><?php echo nl2br( esc_html( $x['description'] ) ); ?></div>
				<?php if ( $x['instruction'] ) : ?><h4>What to do</h4><div class="sdnb-desc"><?php echo nl2br( esc_html( $x['instruction'] ) ); ?></div><?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php
	}
}
