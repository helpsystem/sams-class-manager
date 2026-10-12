<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * "Texts to send" — one list, made for the school iPhone, of every text that is due right now:
 *
 *  Lessons    confirmation after booking · reminder before the free-cancellation deadline · "your lesson is today"
 *             · fee notice · fee waived · cancelled in time
 *  Zoom       the day before each session · on the day when the student has not answered
 *  Celebrate  birthdays · license anniversaries
 *
 * Each card has one "Text" button: Messages opens with the number and the ready text (English or Spanish),
 * the staff member presses Send, comes back, and the card is ticked off. Nothing is sent by the website
 * and nothing costs anything. Marketing texts are not in this list.
 *
 * The list is worked out fresh every time from the lessons, classes and students; only "texted" ticks are
 * stored ({prefix}sdsc_textq).
 */
class SDSC_TextQ {
	const DBV  = '1';
	const DBO  = 'sdsc_textq_db';
	const PAGE = 'sdsc-texts';

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_textq'; }

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'install' ), 21 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 29 );
		add_action( 'admin_menu', array( __CLASS__, 'badge' ), 1002 );
		add_action( 'wp_ajax_sdsc_tq_mark', array( __CLASS__, 'ajax_mark' ) );
	}

	public static function install() {
		if ( get_option( self::DBO ) === self::DBV ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			k varchar(190) NOT NULL,
			st varchar(8) NOT NULL DEFAULT 'sent',
			at datetime NOT NULL,
			by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (k)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DBO, self::DBV, false );
	}

	public static function can() {
		return class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'texts' ) : current_user_can( 'manage_options' );
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Texts to send', 'Texts to send', 'sdsc_access_texts', self::PAGE, array( __CLASS__, 'page' ) );
	}

	protected static function done_keys() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT k, st FROM ' . self::table(), ARRAY_A ) as $r ) { $out[ $r['k'] ] = $r['st']; } // phpcs:ignore
		return $out;
	}

	/* ================================================================ what is due */

	/** Every text due now: [k, group, name, phone, why, texts{en,es}, lang, when(ts for sorting), link]. */
	public static function items() {
		global $wpdb;
		$done  = self::done_keys();
		$now   = time();
		$today = current_time( 'Y-m-d' );
		$items = array();
		$add   = function ( $it ) use ( &$items, $done ) {
			if ( isset( $done[ $it['k'] ] ) || ! SDSC_Outreach::phone_e164( $it['phone'] ) ) { return; }
			$items[] = $it;
		};

		/* ---- behind-the-wheel lessons */
		if ( class_exists( 'SDSC_Lessons' ) ) {
			$hrs  = (int) SDSC_Lessons::settings()['hours'];
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Lessons::t_lessons() . ' WHERE starts_at > %s ORDER BY starts_at', wp_date( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $l ) {
				if ( '@preview.samsdrivingschool.invalid' === substr( $l['email'], -34 ) ) { continue; } // "View as" demo data
				$start = SDSC_Lessons::ts( $l['starts_at'] );
				$dl    = $start - $hrs * HOUR_IN_SECONDS;
				$name  = trim( $l['first_name'] . ' ' . $l['last_name'] );
				$rv    = class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::row( 'btw', $l['id'], '', $l['email'] ) : null;
				$ans   = $rv ? $rv['rsvp'] : '';
				$base  = array( 'group' => 'lessons', 'name' => $name, 'phone' => $l['phone'], 'lang' => $l['lang'], 'when' => $start, 'link' => admin_url( 'admin.php?page=sdsc-lessons&tab=schedule&id=' . $l['id'] ) );
				$sms   = function ( $kind, $f = null ) use ( $l ) {
					$x = $l;
					$o = array();
					foreach ( array( 'en', 'es' ) as $lg ) { $x['lang'] = $lg; $o[ $lg ] = SDSC_Lessons::sms( $kind, $x, $f ); }
					return $o;
				};
				$when  = wp_date( 'D M j, g:i a', $start );
				if ( 'booked' === $l['status'] && $start > $now ) {
					$add( $base + array( 'k' => 'btw:' . $l['id'] . ':confirm:' . $l['starts_at'], 'why' => 'Lesson booked — ' . $when, 'texts' => $sms( 'confirm' ) ) );
					if ( $now >= $dl - 30 * HOUR_IN_SECONDS && $now < $dl && ! in_array( $ans, array( 'yes', 'no' ), true ) && SDSC_Lessons::ts( $l['created_at'] ) < $dl - 30 * HOUR_IN_SECONDS ) {
						$add( $base + array( 'k' => 'btw:' . $l['id'] . ':remind', 'why' => 'Free cancellation ends ' . wp_date( 'D g:i a', $dl ) . ' · not confirmed yet', 'texts' => $sms( 'remind' ) ) );
					}
					if ( wp_date( 'Y-m-d', $start ) === $today && 'no' !== $ans ) {
						$add( $base + array( 'k' => 'btw:' . $l['id'] . ':today', 'why' => 'Lesson today at ' . wp_date( 'g:i a', $start ), 'texts' => $sms( 'today' ) ) );
					}
				}
				if ( 'cancelled' === $l['status'] && $l['notice_at'] && SDSC_Lessons::ts( $l['notice_at'] ) > $now - 3 * DAY_IN_SECONDS ) {
					$add( $base + array( 'k' => 'btw:' . $l['id'] . ':cancelled', 'why' => 'Cancelled in time — no fee', 'texts' => $sms( 'cancelled' ) ) );
				}
				foreach ( SDSC_Lessons::fees_for_lesson( $l['id'] ) as $f ) {
					if ( 'due' === $f['status'] ) {
						$add( $base + array( 'k' => 'fee:' . $f['id'] . ':due', 'why' => SDSC_Lessons::reason_title( $f['reason'], 'en' ) . ' $' . SDSC_Lessons::money( $f['amount'] ), 'texts' => $sms( 'fee', $f ) ) );
					} elseif ( 'waived' === $f['status'] && SDSC_Lessons::ts( $f['waived_at'] ) > $now - 3 * DAY_IN_SECONDS ) {
						$add( $base + array( 'k' => 'fee:' . $f['id'] . ':waived', 'why' => 'Fee waived — tell the student', 'texts' => $sms( 'waived', $f ) ) );
					}
				}
			}
		}

		/* ---- Zoom classes */
		if ( class_exists( 'SDSC_Schedule' ) ) {
			$tom = wp_date( 'Y-m-d', $now + DAY_IN_SECONDS );
			$ids = get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => 'any', 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => SDSC_Schedule::META ) ); // phpcs:ignore
			foreach ( $ids as $cid ) {
				if ( ! SDSC_Schedule::sched( $cid )['remind'] || in_array( get_post_meta( $cid, '_sdsc_status', true ), array( 'cancelled', 'completed' ), true ) ) { continue; }
				$list = SDSC_Schedule::sessions( $cid );
				foreach ( $list as $x ) {
					$start = SDSC_Lessons::ts( $x['start'] );
					$isTom = $x['date'] === $tom;
					$isTod = $x['date'] === $today && $start > $now;
					if ( ! $isTom && ! $isTod ) { continue; }
					foreach ( SDSC_Schedule::roster( $cid ) as $p ) {
						if ( '@preview.samsdrivingschool.invalid' === substr( $p['email'], -34 ) ) { continue; }
						$rv = SDSC_Schedule::row( 'zoom', $cid, $x['date'], $p['email'] );
						if ( $isTod && $rv && in_array( $rv['rsvp'], array( 'yes', 'no' ), true ) ) { continue; }
						$u     = SDSC_Schedule::att_url( 'zoom', $cid, $x['date'], $p['email'] );
						$t     = array();
						foreach ( array( 'en', 'es' ) as $lg ) {
							$w = SDSC_Lessons::when( $x['start'], $lg );
							$t[ $lg ] = 'es' === $lg
								? 'Hola ' . $p['first'] . ', ' . ( $isTom ? 'mañana' : 'hoy' ) . ' tiene la clase de Zoom ' . $x['n'] . ' de ' . count( $list ) . ': ' . $w . '.' . ( 1 === $x['n'] ? ' La primera clase es obligatoria.' : '' ) . ' Confirme o avise si no puede: ' . $u . ' – Sam’s Driving School'
								: 'Hi ' . $p['first'] . ', your Zoom class ' . $x['n'] . ' of ' . count( $list ) . ' is ' . ( $isTom ? 'tomorrow' : 'today' ) . ': ' . $w . '.' . ( 1 === $x['n'] ? ' The first class is mandatory.' : '' ) . ' Confirm or tell us if you can’t make it: ' . $u . ' – Sam’s Driving School';
						}
						$add( array(
							'k'     => 'zoom:' . $cid . ':' . $x['date'] . ':' . md5( $p['email'] ) . ':' . ( $isTom ? 'day' : 'today' ),
							'group' => 'zoom',
							'name'  => trim( $p['first'] . ' ' . $p['last'] ),
							'phone' => $p['phone'],
							'why'   => SDSC_Schedule::class_name( $cid ) . ' · class ' . $x['n'] . ' ' . ( $isTom ? 'tomorrow' : 'today' ) . ' ' . wp_date( 'g:i a', $start ),
							'texts' => $t,
							'lang'  => $p['lang'],
							'when'  => $start,
							'link'  => admin_url( 'admin.php?page=sdsc-attendance&class=' . $cid ),
						) );
					}
				}
			}
		}

		/* ---- birthdays and license anniversaries (shared with Celebrations: a text marked here is marked there) */
		if ( class_exists( 'SDSC_Outreach' ) ) {
			$y = current_time( 'Y' );
			$d = SDSC_Outreach::on_day( $today );
			foreach ( array( 'b' => 'birthday', 'a' => 'anniversary' ) as $t => $kind ) {
				foreach ( $d[ $t ] as $c ) {
					if ( $c['os'] || ! empty( $c['sent'][ $t . $y . 't' ] ) ) { continue; }
					$tx = array();
					foreach ( array( 'en', 'es' ) as $lg ) { $tx[ $lg ] = SDSC_Outreach::build( $kind, $c, $lg )[2]; }
					$add( array(
						'k'     => 'or:' . $t . ':' . $c['k'] . ':' . $y,
						'group' => 'celebrate',
						'name'  => trim( $c['first'] . ' ' . $c['last'] ),
						'phone' => $c['phone'],
						'why'   => 'b' === $t ? '🎂 Birthday today' : '🚗 License anniversary today',
						'texts' => $tx,
						'lang'  => $c['lang'],
						'when'  => strtotime( $today . ' 09:00' ),
						'link'  => admin_url( 'admin.php?page=sdsc-outreach' ),
						'or'    => $t . '|' . $c['k'],
					) );
				}
			}
		}
		usort( $items, function ( $a, $b ) { return $a['when'] <=> $b['when']; } );
		return $items;
	}

	public static function badge() {
		global $submenu;
		if ( ! self::can() ) { return; }
		$n = count( self::items() );
		if ( ! $n ) { return; }
		foreach ( (array) ( $submenu['sdsc-dashboard'] ?? array() ) as $i => $it ) {
			if ( self::PAGE === $it[2] ) { $submenu['sdsc-dashboard'][ $i ][0] .= ' <span class="update-plugins count-' . $n . '"><span class="plugin-count">' . $n . '</span></span>'; } // phpcs:ignore
		}
	}

	/* ================================================================ screen */

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		wp_enqueue_style( 'sdsc-outreach', SDSC_URL . 'assets/outreach.css', array(), SDSC_VERSION );
		wp_enqueue_style( 'sdsc-lessons', SDSC_URL . 'assets/lessons.css', array( 'sdsc-outreach' ), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-textq', SDSC_URL . 'assets/textq.js', array(), SDSC_VERSION, true );
		wp_localize_script( 'sdsc-textq', 'SDSC_TQ', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'sdsc_tq' ) ) );
		$items  = self::items();
		$groups = array( 'lessons' => '🚗 Driving lessons', 'zoom' => '💻 Zoom classes', 'celebrate' => '🎉 Birthdays & anniversaries' );
		?>
		<div class="wrap sdo sdl sdq">
			<h1 class="sdo-title"><span class="sdo-title-ic" aria-hidden="true">💬</span> Texts to send <span class="sdo-n sdq-left"><?php echo count( $items ); ?></span></h1>
			<p class="sdo-lead">Open this page on the school iPhone (tip: Share &rarr; <b>Add to Home Screen</b>). Tap <b>Text</b>: Messages opens with the number and the message ready &mdash; press Send and come back. The card is ticked off by itself. Reminders, confirmations and greetings only; no advertising.</p>
			<?php if ( $items ) : ?><p><a class="sdo-btn sdq-next" href="#">Text the next one &rarr;</a></p><?php endif; ?>
			<?php if ( ! $items ) : ?><div class="sdo-empty sdq-done-all">✅ All done &mdash; nothing to text right now.</div><?php endif; ?>
			<?php foreach ( $groups as $g => $label ) :
				$list = array_filter( $items, function ( $i ) use ( $g ) { return $g === $i['group']; } );
				if ( ! $list ) { continue; } ?>
				<section class="sdo-sec">
					<h2><?php echo esc_html( $label ); ?> <span class="sdo-n"><?php echo count( $list ); ?></span></h2>
					<div class="sdq-list">
						<?php foreach ( $list as $it ) : $lang = 'es' === $it['lang'] ? 'es' : 'en'; ?>
							<article class="sdq-card" data-k="<?php echo esc_attr( $it['k'] ); ?>">
								<header>
									<div><b><?php echo esc_html( $it['name'] ); ?></b> <span class="sdo-muted"><?php echo esc_html( SDSC_Outreach::phone_nice( $it['phone'] ) ); ?></span><br><span class="sdq-why"><?php echo esc_html( $it['why'] ); ?></span></div>
									<span class="sdo-lang" role="group" aria-label="Message language"><button type="button" data-set="en" class="<?php echo 'en' === $lang ? 'is-on' : ''; ?>">EN</button><button type="button" data-set="es" class="<?php echo 'es' === $lang ? 'is-on' : ''; ?>">ES</button></span>
								</header>
								<p class="sdo-bubble"><?php echo esc_html( $it['texts'][ $lang ] ); ?></p>
								<div class="sdo-actions">
									<a class="sdo-btn sdo-btn--text sdq-go" data-sms="<?php echo esc_attr( SDSC_Outreach::phone_e164( $it['phone'] ) ); ?>" data-lang="<?php echo esc_attr( $lang ); ?>" data-en="<?php echo esc_attr( $it['texts']['en'] ); ?>" data-es="<?php echo esc_attr( $it['texts']['es'] ); ?>"<?php echo isset( $it['or'] ) ? ' data-or="' . esc_attr( $it['or'] ) . '"' : ''; ?> href="#"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H8l-4 4z"/></svg>Text <?php echo esc_html( strtok( $it['name'], ' ' ) ); ?></a>
									<button type="button" class="sdo-btn sdo-btn--ghost sdq-skip">Skip</button>
									<a class="sdq-open" href="<?php echo esc_url( $it['link'] ); ?>">Details</a>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public static function ajax_mark() {
		check_ajax_referer( 'sdsc_tq' );
		if ( ! self::can() ) { wp_send_json_error( null, 403 ); }
		global $wpdb;
		$k  = substr( sanitize_text_field( wp_unslash( $_POST['k'] ?? '' ) ), 0, 190 );
		$st = 'skip' === ( $_POST['st'] ?? '' ) ? 'skip' : 'sent';
		if ( '' === $k ) { wp_send_json_error(); }
		if ( 'undo' === ( $_POST['st'] ?? '' ) ) {
			$wpdb->delete( self::table(), array( 'k' => $k ) );
			wp_send_json_success();
		}
		$wpdb->replace( self::table(), array( 'k' => $k, 'st' => $st, 'at' => current_time( 'mysql' ), 'by_user' => get_current_user_id() ) );
		// Birthday / anniversary: tick it in Celebrations too.
		$or = sanitize_text_field( wp_unslash( $_POST['or'] ?? '' ) );
		if ( 'sent' === $st && preg_match( '/^([ba])\|([a-f0-9]{32})$/', $or, $m ) && class_exists( 'SDSC_Outreach' ) ) {
			$c = SDSC_Outreach::contact( $m[2] );
			if ( $c ) {
				$s = $c['sent'];
				$s[ $m[1] . current_time( 'Y' ) . 't' ] = time();
				$wpdb->update( SDSC_Outreach::table(), array( 'sent' => wp_json_encode( $s ), 'updated_at' => current_time( 'mysql' ) ), array( 'ckey' => $m[2] ) );
				if ( ! $wpdb->rows_affected ) { $wpdb->insert( SDSC_Outreach::table(), array( 'ckey' => $m[2], 'sent' => wp_json_encode( $s ), 'updated_at' => current_time( 'mysql' ) ) ); }
			}
		}
		if ( 0 === strpos( $k, 'btw:' ) && class_exists( 'SDSC_Lessons' ) ) {
			$p = explode( ':', $k );
			SDSC_Lessons::add_log( (int) $p[1], ucfirst( $p[2] ) . ' text ' . ( 'skip' === $st ? 'skipped' : 'sent from the phone' ) );
		}
		wp_send_json_success();
	}
}
