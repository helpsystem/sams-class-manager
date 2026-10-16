<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Native student registration form (replaces the Forminator "Registration Forms" form).
 *
 * - Public shortcode: [sdsc_registration_form] (optional attr: course="C-68").
 *   The course can also be preselected with ?course=C-68 in the page URL.
 * - Data is stored in {prefix}sdsc_registrations. Signatures are stored as PNG
 *   blobs in the database (never as public files) and are only served to
 *   administrators through an authenticated endpoint.
 * - Each registration also creates/updates an sdsc_registrant for the chosen
 *   class, marked "form done", so the existing class dashboard keeps working.
 * - WP-CLI: `wp sdsc import-forminator --form=526 [--dry-run]` imports old entries.
 */
class SDSC_Registration {
	const DB_VERSION   = '3';
	const DB_OPTION    = 'sdsc_registration_db_version';
	const REPORT_SOURCE = -1; // pseudo "form id" used by the reports portal

	/**
	 * The student's file number, e.g. SDS-0353. Registrations that came from the earlier website form keep
	 * their old number (the form entry number); newer ones use the registration number (both are unique —
	 * the new numbers started after the last old one).
	 */
	public static function file_no( $r ) {
		$r   = (array) $r;
		$num = ( 'forminator' === ( $r['source'] ?? '' ) && ! empty( $r['source_entry_id'] ) ) ? (int) $r['source_entry_id'] : (int) ( $r['id'] ?? 0 );
		return $num ? 'SDS-' . str_pad( (string) $num, 4, '0', STR_PAD_LEFT ) : '';
	}
	const MAX_SIG_BYTES = 400000;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sdsc_registrations';
	}

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 20 );
		add_shortcode( 'sdsc_registration_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_sdsc_register', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_sdsc_register', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_sdsc_pay_parent', array( __CLASS__, 'pay_parent' ) );
		add_action( 'admin_post_sdsc_pay_parent', array( __CLASS__, 'pay_parent' ) );
		add_action( 'admin_post_sdsc_sig', array( __CLASS__, 'serve_signature' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'sdsc import-forminator', array( __CLASS__, 'cli_import' ) );
		}
	}

	/* ============================= Install ============================= */

	public static function maybe_install() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			source varchar(20) NOT NULL DEFAULT 'native',
			source_entry_id bigint(20) unsigned DEFAULT NULL,
			class_id bigint(20) unsigned DEFAULT NULL,
			registrant_id bigint(20) unsigned DEFAULT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			middle_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			dob date DEFAULT NULL,
			age smallint(5) unsigned DEFAULT NULL,
			gender varchar(20) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			parent_name varchar(200) NOT NULL DEFAULT '',
			parent_phone varchar(40) NOT NULL DEFAULT '',
			parent_email varchar(190) NOT NULL DEFAULT '',
			parent_consent tinyint(1) NOT NULL DEFAULT 0,
			follow_n tinyint(3) unsigned NOT NULL DEFAULT 0,
			follow_at datetime DEFAULT NULL,
			done_at datetime DEFAULT NULL,
			street varchar(200) NOT NULL DEFAULT '',
			apt varchar(100) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(50) NOT NULL DEFAULT '',
			zip varchar(20) NOT NULL DEFAULT '',
			consent tinyint(1) NOT NULL DEFAULT 0,
			signed_date date DEFAULT NULL,
			notes text NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			student_sig mediumblob NULL,
			parent_sig mediumblob NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_entry (source,source_entry_id),
			KEY email (email),
			KEY class_id (class_id)
		) $charset;" );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/* ============================= Public form ============================= */

	public static function register_assets() {
		wp_register_style( 'sdsc-registration', SDSC_URL . 'assets/registration.css', array( 'sdsc-brand' ), SDSC_VERSION );
		wp_register_script( 'sdsc-datepicker', SDSC_URL . 'assets/datepicker.js', array(), SDSC_VERSION, true );
		wp_register_script( 'sdsc-registration', SDSC_URL . 'assets/registration.js', array( 'sdsc-datepicker' ), SDSC_VERSION, true );
	}

	/** Classes that are open for registration (upcoming or active, not ended). */
	public static function open_classes( $with_hidden = false ) {
		$posts = get_posts( array(
			'post_type'      => SDSC_CPT_Classes::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_key'       => '_sdsc_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
		) );
		$now = current_time( 'Y-m-d\TH:i' );
		$out = array();
		foreach ( $posts as $p ) {
			$status = get_post_meta( $p->ID, '_sdsc_status', true ) ?: 'upcoming';
			$end    = get_post_meta( $p->ID, '_sdsc_end', true );
			if ( ! in_array( $status, array( 'upcoming', 'active' ), true ) ) { continue; }
			if ( $end && $end < $now ) { continue; }
			if ( ! $with_hidden && class_exists( 'SDSC_Promo' ) && SDSC_Promo::hidden( $p->ID ) ) { continue; } // staff-only classes: direct link only
			$out[] = $p;
		}
		return $out;
	}

	public static function class_label( $class_id, $staff = false ) {
		$code  = $staff ? get_post_meta( $class_id, '_sdsc_course_code', true ) : self::public_code( $class_id );
		$start = get_post_meta( $class_id, '_sdsc_start', true );
		$when  = $start ? date_i18n( 'M j, Y', strtotime( $start ) ) : '';
		$label = get_the_title( $class_id );
		return trim( $label . ( $when ? ' — starts ' . $when : '' ) . ( $code ? ' (' . $code . ')' : '' ) );
	}

	/**
	 * The class code students see: time of day + start date, e.g. PM-1026 (evening class starting Oct 26).
	 * The school's real course number (C-70 …) stays inside: staff screens, student files and reports.
	 * Two classes that start the same day at the same time of day get PM-1026, PM-1026B, … (oldest first).
	 */
	public static function public_code( $class_id ) {
		static $memo = array();
		$class_id = (int) $class_id;
		if ( isset( $memo[ $class_id ] ) ) { return $memo[ $class_id ]; }
		$base = self::public_base( $class_id );
		if ( '' === $base ) { return $memo[ $class_id ] = ''; }
		$day  = substr( (string) get_post_meta( $class_id, '_sdsc_start', true ), 0, 10 );
		$same = array();
		foreach ( get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => array( 'publish', 'private', 'future', 'draft', 'pending' ), 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'meta_query' => array( array( 'key' => '_sdsc_start', 'value' => $day, 'compare' => 'LIKE' ) ) ) ) as $id ) { // phpcs:ignore
			if ( self::public_base( (int) $id ) === $base ) { $same[] = (int) $id; }
		}
		$i = array_search( $class_id, $same, true );
		return $memo[ $class_id ] = $base . ( $i ? chr( 65 + (int) $i ) : '' );
	}

	protected static function public_base( $class_id ) {
		$start = (string) get_post_meta( $class_id, '_sdsc_start', true );
		if ( ! preg_match( '/^\d{4}-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/', $start, $m ) ) { return ''; }
		$h = isset( $m[3] ) && '' !== $m[3] && '00:00' !== $m[3] . ':' . $m[4] ? (int) $m[3] : null;
		if ( null === $h && class_exists( 'SDSC_Schedule' ) ) {
			$sc = SDSC_Schedule::sched( $class_id );
			if ( ! empty( $sc['from'] ) ) { $h = (int) substr( $sc['from'], 0, 2 ); }
		}
		return ( null === $h ? 'C' : ( $h < 12 ? 'AM' : 'PM' ) ) . '-' . $m[1] . $m[2];
	}

	/** Link value for ?course= — the public code, or id-N for a class without a start date. */
	public static function course_param( $class_id ) {
		$pc = self::public_code( $class_id );
		return '' !== $pc ? $pc : 'id-' . (int) $class_id;
	}

	public static function find_class_by_code( $code ) {
		if ( ! $code ) { return 0; }
		// Public codes (PM-1026): matched against the classes open for registration (hidden ones included).
		if ( preg_match( '/^(AM|PM|C)-\d{4}[A-Z]?$/i', (string) $code ) ) {
			$code = strtoupper( (string) $code );
			foreach ( self::open_classes( true ) as $p ) { if ( self::public_code( $p->ID ) === $code ) { return (int) $p->ID; } }
			return 0;
		}
		// Classes without a course code yet are linked as ?course=id-123.
		if ( preg_match( '/^id-(\d+)$/', (string) $code, $m ) ) {
			$id = (int) $m[1];
			return 'sdsc_class' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? $id : 0;
		}
		$p = get_posts( array(
			'post_type'      => SDSC_CPT_Classes::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_sdsc_course_code', 'value' => $code ) ),
		) );
		return $p ? (int) $p[0] : 0;
	}

	const LOGO_URL = '/wp-content/uploads/2025/10/cropped-logo-1-e1760971082483.png';

	/** Inline SVG of the school car (side view), used in the progress road and success screen. */
	public static function car_svg() {
		return '<svg class="sdsc-car-svg" viewBox="0 0 120 48" aria-hidden="true" focusable="false">'
			. '<path d="M8 34c0-6 3-9 9-10l14-2 13-10c3-2 6-3 10-3h22c4 0 7 1 10 4l9 9 11 2c5 1 7 4 7 8v4c0 2-1 3-3 3H11c-2 0-3-1-3-3z" fill="#5b6470"/>'
			. '<path d="M48 13c2-2 4-2 7-2h20c3 0 5 1 7 3l7 8H38z" fill="#cfe6f5"/>'
			. '<path d="M64 11h2v11h-2z" fill="#5b6470"/>'
			. '<path d="M14 30h96" stroke="#2fae4f" stroke-width="3" stroke-linecap="round"/>'
			. '<rect x="70" y="25" width="16" height="3" rx="1.5" fill="#ffd700"/>'
			. '<circle cx="104" cy="31" r="2" fill="#ffd700"/>'
			. '<g class="sdsc-wheel"><circle cx="32" cy="38" r="8" fill="#1f2933"/><circle cx="32" cy="38" r="3.5" fill="#cbd2d9"/><path d="M32 31v14M25 38h14" stroke="#1f2933" stroke-width="1.2"/></g>'
			. '<g class="sdsc-wheel"><circle cx="94" cy="38" r="8" fill="#1f2933"/><circle cx="94" cy="38" r="3.5" fill="#cbd2d9"/><path d="M94 31v14M87 38h14" stroke="#1f2933" stroke-width="1.2"/></g>'
			. '</svg>';
	}

	public static function field( $name, $label, $type = 'text', $req = false, $extra = '' ) {
		return '<label class="sdsc-field"><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" placeholder=" " ' . ( $req ? 'required ' : '' ) . $extra . ' />'
			. '<span>' . esc_html( $label ) . ( $req ? ' <b>*</b>' : '' ) . '</span></label>';
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'course' => '' ), $atts, 'sdsc_registration_form' );
		wp_enqueue_style( 'sdsc-registration' );
		if ( isset( $_GET['sdsc_parent'] ) && class_exists( 'SDSC_Followup' ) ) { // phpcs:ignore -- the parent's own link (token checked inside)
			wp_enqueue_script( 'sdsc-registration' );
			return SDSC_Followup::parent_form();
		}
		wp_enqueue_script( 'sdsc-registration' );

		$result = isset( $_GET['sdsc_reg'] ) ? sanitize_key( $_GET['sdsc_reg'] ) : '';
		if ( 'done' === $result ) {
			$class_id = isset( $_GET['sdsc_class'] ) ? absint( $_GET['sdsc_class'] ) : 0;
			return self::render_success( $class_id );
		}
		if ( 'paid' === $result && class_exists( 'SDSC_Pay' ) ) {
			return self::render_paid();
		}

		$classes  = self::open_classes();
		$want     = $atts['course'] ? $atts['course'] : ( isset( $_GET['course'] ) ? sanitize_text_field( wp_unslash( $_GET['course'] ) ) : '' );
		$selected = self::find_class_by_code( $want );
		if ( $selected && ! in_array( (int) $selected, array_map( 'intval', wp_list_pluck( $classes, 'ID' ) ), true ) && in_array( (int) $selected, array_map( 'intval', wp_list_pluck( self::open_classes( true ), 'ID' ) ), true ) ) {
			array_unshift( $classes, get_post( $selected ) ); // a staff-only class opened through its direct link
		}
		$error    = 'error' === $result && isset( $_GET['sdsc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sdsc_msg'] ) ) : '';
		$steps    = array( 'Course', 'Student', 'Parent', 'Address', 'Sign' );
		$paystep  = class_exists( 'SDSC_Pay' ) && SDSC_Pay::ready();
		// Picked on the homepage (?course=…): show only that class, so nobody picks another one by mistake.
		$picked   = $selected && in_array( (int) $selected, array_map( 'intval', wp_list_pluck( $classes, 'ID' ) ), true ) ? (int) $selected : 0;

		ob_start();
		?>
		<div class="sdsc-reg" id="sdsc">
			<div class="sdsc-reg-card">
				<header class="sdsc-reg-head">
					<?php echo SDSC_Brand::logo( 'wide', 'sdsc-reg-logo', 46 ); // phpcs:ignore -- escaped in helper ?>
					<div>
						<h2 class="sdsc-reg-title">Student Registration</h2>
						<p class="sdsc-reg-sub">Takes about 3 minutes. Your information is kept private.</p>
					</div>
				</header>

				<div class="sdsc-road" aria-hidden="true">
					<div class="sdsc-road-track"><span class="sdsc-road-fill"></span></div>
					<div class="sdsc-road-car"><?php echo SDSC_Brand::car( 'right' ); // phpcs:ignore ?></div>
					<span class="sdsc-road-flag">🏁</span>
				</div>
				<ol class="sdsc-steps-nav">
					<?php foreach ( $steps as $i => $s ) : ?>
						<li data-step="<?php echo (int) $i; ?>" class="<?php echo 0 === $i ? 'is-active' : ''; ?>"><span><?php echo (int) $i + 1; ?></span><?php echo esc_html( $s ); ?></li>
					<?php endforeach; ?>
					<?php if ( $paystep ) : ?><li class="sdsc-step-pay" aria-label="Step <?php echo count( $steps ) + 1; ?>: payment, right after you submit"><span><?php echo count( $steps ) + 1; ?></span>Pay</li><?php endif; ?>
				</ol>

				<?php if ( $error ) : ?>
					<div class="sdsc-reg-alert" role="alert"><?php echo esc_html( $error ); ?></div>
				<?php endif; ?>

				<form class="sdsc-reg-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
					<input type="hidden" name="action" value="sdsc_register" />
					<input type="hidden" name="sdsc_return" value="<?php echo esc_url( get_permalink() ); ?>" />
					<input type="hidden" name="sdsc_js" value="" class="sdsc-js-check" />
					<div class="sdsc-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="sdsc_website" tabindex="-1" autocomplete="off" /></label></div>

					<section class="sdsc-step is-active" data-step="0">
						<h3><?php echo $picked ? 'Your class' : 'Choose your course'; ?></h3>
						<?php if ( $picked ) : ?><p class="sdsc-picked-note">This is the class you chose. Check the dates, then press <b>Next</b>.</p><?php endif; ?>
						<?php if ( $classes ) : ?>
							<div class="sdsc-courses<?php echo $picked ? ' is-picked' : ''; ?>" role="radiogroup" aria-label="Course">
								<?php foreach ( $classes as $c ) :
									$start = get_post_meta( $c->ID, '_sdsc_start', true );
									$end   = get_post_meta( $c->ID, '_sdsc_end', true );
									$days  = get_post_meta( $c->ID, '_sdsc_days_time', true );
									$price = get_post_meta( $c->ID, '_sdsc_price', true );
									$code  = self::public_code( $c->ID );
									$full  = class_exists( 'SDSC_Board' ) && SDSC_Board::is_full( $c->ID );
									?>
									<label class="sdsc-course"<?php echo $picked && (int) $c->ID !== $picked ? ' hidden data-other="1"' : ''; ?>>
										<input type="radio" name="class_id" value="<?php echo esc_attr( $c->ID ); ?>" required <?php checked( $selected, $c->ID ); ?><?php disabled( $full ); ?> />
										<span class="sdsc-course-body">
											<span class="sdsc-course-name"><?php echo esc_html( get_the_title( $c ) ); ?></span>
											<span class="sdsc-course-meta">
												<?php if ( $start ) : ?><span>📅 <?php echo esc_html( date_i18n( 'D, M j', strtotime( $start ) ) . ( $end ? ' – ' . date_i18n( 'M j, Y', strtotime( $end ) ) : '' ) ); ?></span><?php endif; ?>
												<?php if ( $days ) : ?><span>🕘 <?php echo esc_html( $days ); ?></span><?php endif; ?>
												<?php if ( $code ) : ?><span class="sdsc-code"><?php echo esc_html( $code ); ?></span><?php endif; ?>
												<?php if ( $full ) : ?><span class="sdsc-full" style="color:#b32d2e;font-weight:700">Class full</span><?php endif; ?>
											</span>
										</span>
										<?php
										if ( '' !== $price ) :
											$fmt = function ( $n ) { return '$' . number_format_i18n( (float) $n, ( floor( (float) $n ) == (float) $n ) ? 0 : 2 ); };
											$now = class_exists( 'SDSC_Pay' ) ? SDSC_Pay::amount( $c->ID ) / 100 : 0;
											$eb  = class_exists( 'SDSC_Promo' ) ? SDSC_Promo::earlybird( $c->ID ) : null;
											if ( $now > 0 && $now < (float) $price ) : ?>
												<span class="sdsc-course-price"><s style="opacity:.55;font-weight:500"><?php echo esc_html( $fmt( $price ) ); ?></s> <?php echo esc_html( $fmt( $now ) ); ?><?php if ( $eb && (int) round( $now * 100 ) === $eb['cents'] ) : ?><small class="sdsc-eb">Early-bird price until <?php echo esc_html( date_i18n( 'M j', strtotime( $eb['until'] ) ) ); ?></small><?php endif; ?></span>
											<?php else : ?>
												<span class="sdsc-course-price"><?php echo esc_html( $fmt( $price ) ); ?></span>
											<?php endif; ?>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</div>
							<?php if ( $picked && count( $classes ) > 1 ) : ?>
								<button type="button" class="sdsc-other-btn" onclick="var f=this.closest('form');[].forEach.call(f.querySelectorAll('.sdsc-course[data-other]'),function(l){l.hidden=false;});this.remove();">Choose a different class</button>
							<?php endif; ?>
							<?php if ( class_exists( 'SDSC_Promo' ) ) : ?>
							<div class="sdsc-promo" data-rest="<?php echo esc_url( rest_url( 'sdsc/v1/promo' ) ); ?>">
								<label class="sdsc-field"><input type="text" name="promo" maxlength="30" placeholder=" " autocomplete="off" autocapitalize="characters" spellcheck="false" value="<?php echo esc_attr( isset( $_GET['code'] ) ? SDSC_Promo::clean( wp_unslash( $_GET['code'] ) ) : '' ); // phpcs:ignore ?>" /><span>Discount code (optional)</span></label>
								<button type="button" class="sdsc-promo-apply">Apply</button>
								<p class="sdsc-promo-msg" aria-live="polite"></p>
							</div>
							<script>
							(function(){var w=document.currentScript.previousElementSibling,i=w.querySelector('input'),b=w.querySelector('button'),m=w.querySelector('.sdsc-promo-msg'),f=w.closest('form');
							function cid(){var r=f.querySelector('input[name=class_id]:checked');return r?r.value:'';}
							function prices(reset,txt,cl){[].forEach.call(f.querySelectorAll('.sdsc-course'),function(l){var p=l.querySelector('.sdsc-course-price');if(!p)return;if(!p.dataset.o)p.dataset.o=p.innerHTML;p.innerHTML=p.dataset.o;});if(!reset&&cl){var lab=f.querySelector('input[name=class_id]:checked');lab=lab&&lab.closest('.sdsc-course');var p=lab&&lab.querySelector('.sdsc-course-price');if(p)p.innerHTML='<s style="opacity:.55;font-weight:500">'+cl+'</s> '+txt;}}
							function go(){var c=i.value.trim();if(!c){m.textContent='';m.className='sdsc-promo-msg';prices(true);return;}if(!cid()){m.textContent='Choose a course first.';m.className='sdsc-promo-msg is-bad';return;}b.disabled=true;m.textContent='Checking…';m.className='sdsc-promo-msg';
							fetch(w.dataset.rest+'?code='+encodeURIComponent(c)+'&class='+cid(),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){b.disabled=false;if(d&&d.ok){m.textContent='✓ '+d.msg+' You pay '+d.price+' (you save '+d.save+').';m.className='sdsc-promo-msg is-ok';prices(false,d.price,d.list);}else{m.textContent=(d&&(d.msg||d.message))||'This code is not valid.';m.className='sdsc-promo-msg is-bad';prices(true);}}).catch(function(){b.disabled=false;m.textContent='Could not check the code. You can still continue — we will check it when you submit.';m.className='sdsc-promo-msg';});}
							b.addEventListener('click',go);i.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();go();}});
							f.addEventListener('change',function(e){if(e.target.name==='class_id'&&i.value.trim())go();});if(i.value.trim()&&cid())go();})();
							</script>
							<?php endif; ?>
						<?php else : ?>
							<p class="sdsc-note">There are no open courses right now. Please call us at <a href="tel:+13017263030">(301) 726-3030</a> and we'll help you find the next class.</p>
						<?php endif; ?>
					</section>

					<section class="sdsc-step" data-step="1">
						<h3>Student information</h3>
						<div class="sdsc-grid">
							<?php
							echo self::field( 'first_name', 'First name', 'text', true, 'maxlength="100" autocomplete="given-name"' );
							echo self::field( 'middle_name', 'Middle name', 'text', false, 'maxlength="100" autocomplete="additional-name"' );
							echo self::field( 'last_name', 'Last name', 'text', true, 'maxlength="100" autocomplete="family-name"' );
							echo self::field( 'dob', 'Date of birth', 'date', true, 'class="sdsc-dob sdsc-datepicker" min="1920-01-01" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '"' );
							?>
							<label class="sdsc-field sdsc-select"><select name="gender"><option value="">Prefer not to say</option><option>Male</option><option>Female</option></select><span>Gender</span></label>
							<?php
							echo self::field( 'email', 'Email', 'email', true, 'maxlength="190" autocomplete="email"' );
							echo self::field( 'phone', 'Phone', 'tel', true, 'maxlength="40" autocomplete="tel"' );
							?>
						</div>
						<p class="sdsc-young-note">In Maryland, students usually start driver's ed at 16. You can still send this form — we will call you to find the right class.</p>
					</section>

					<section class="sdsc-step" data-step="2">
						<h3>Parent / guardian</h3>
						<p class="sdsc-note sdsc-minor-note"><b>The student is under 18.</b> In Maryland a parent or guardian must fill in this step, read the policies and sign. <b>Parent not with you right now?</b> Press Continue — we will send them a secure link to finish it, and the seat is held meanwhile.</p>
						<p class="sdsc-note sdsc-adult-note"><b>The student is 18 or older</b>, so this step is optional. We recommend adding a parent or emergency contact: we only use it in an emergency, for class reminders, or to send the payment link.</p>
						<div class="sdsc-grid">
							<?php
							echo self::field( 'parent_name', 'Parent / guardian full name', 'text', false, 'maxlength="200" autocomplete="off" data-minor-required="1"' );
							echo self::field( 'parent_phone', 'Parent / guardian phone', 'tel', false, 'maxlength="40" autocomplete="off" data-minor-required="1"' );
							echo self::field( 'parent_email', 'Parent / guardian email', 'email', false, 'maxlength="190" autocomplete="off" data-minor-required="1"' );
							?>
						</div>
						<label class="sdsc-consent sdsc-parent-consent">
							<input type="checkbox" name="parent_consent" value="1" data-minor-required="1" />
							<span>I am the student's parent or legal guardian. I have read the <a href="<?php echo esc_url( home_url( '/policy/' ) ); ?>" target="_blank" rel="noopener">policies, terms and conditions</a> and I agree to them for my child.<b class="sdsc-minor-star"> *</b></span>
						</label>
						<div class="sdsc-sig" data-name="parent_sig" data-minor-required="1">
							<span class="sdsc-sig-label">Parent / guardian signature</span>
							<div class="sdsc-sig-paper"><canvas class="sdsc-sig-pad" height="170"></canvas><span class="sdsc-sig-hint">Sign here with your finger or mouse</span></div>
							<button type="button" class="sdsc-sig-clear">Clear</button>
							<input type="hidden" name="parent_sig" />
						</div>
					</section>

					<section class="sdsc-step" data-step="3">
						<h3>Home address</h3>
						<div class="sdsc-grid">
							<?php
							echo self::field( 'street', 'Street address', 'text', true, 'maxlength="200" autocomplete="address-line1"' );
							echo self::field( 'apt', 'Apartment, suite, etc.', 'text', false, 'maxlength="100" autocomplete="address-line2"' );
							echo self::field( 'city', 'City', 'text', true, 'maxlength="100" autocomplete="address-level2"' );
							echo self::field( 'state', 'State', 'text', false, 'maxlength="50" value="MD" autocomplete="address-level1"' );
							echo self::field( 'zip', 'ZIP code', 'text', true, 'maxlength="20" inputmode="numeric" autocomplete="postal-code"' );
							?>
						</div>
					</section>

					<section class="sdsc-step" data-step="4">
						<h3>Review &amp; sign</h3>
						<div class="sdsc-sig" data-name="student_sig" data-required="1">
							<span class="sdsc-sig-label">Student signature <b>*</b></span>
							<div class="sdsc-sig-paper"><canvas class="sdsc-sig-pad" height="170"></canvas><span class="sdsc-sig-hint">Sign here with your finger or mouse</span></div>
							<button type="button" class="sdsc-sig-clear">Clear</button>
							<input type="hidden" name="student_sig" />
						</div>
						<label class="sdsc-consent">
							<input type="checkbox" name="consent" value="1" required />
							<span>I confirm that I have read and fully understood the forms, <a href="<?php echo esc_url( home_url( '/policy/' ) ); ?>" target="_blank" rel="noopener">policies, and terms and conditions</a>, and I agree to all the stipulated terms. <b>*</b></span>
						</label>
						<label class="sdsc-consent sdsc-consent--optional">
							<input type="checkbox" name="offers" value="1" />
							<span>Yes, send me birthday wishes, new class dates and special offers by e-mail and text message. <em>Optional — you can stop at any time.</em></span>
						</label>
					</section>

					<div class="sdsc-reg-error" role="alert" hidden></div>
					<div class="sdsc-nav">
						<button type="button" class="sdsc-btn sdsc-btn-ghost sdsc-prev" hidden>&larr; Back</button>
						<button type="button" class="sdsc-btn sdsc-next">Continue &rarr;</button>
						<button type="submit" class="sdsc-btn sdsc-submit" hidden><?php echo $paystep ? 'Submit &amp; go to payment &rarr;' : 'Submit registration'; ?></button>
					</div>
				</form>
			</div>
			<p class="sdsc-reg-foot">🔒 Secure form · Questions? Call <a href="tel:+13017263030">(301) 726-3030</a></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Back from Stripe Checkout. */
	protected static function render_paid() {
		$rid  = isset( $_GET['sdsc_r'] ) ? absint( $_GET['sdsc_r'] ) : 0; // phpcs:ignore
		$rtok = isset( $_GET['sdsc_t'] ) ? sanitize_text_field( wp_unslash( $_GET['sdsc_t'] ) ) : ''; // phpcs:ignore
		$sid  = isset( $_GET['sdsc_s'] ) ? sanitize_text_field( wp_unslash( $_GET['sdsc_s'] ) ) : ''; // phpcs:ignore
		$row  = SDSC_Pay::confirm_return( $rid, $rtok, $sid );
		$name = $row ? self::class_label( (int) $row['class_id'] ) : '';
		ob_start();
		?>
		<div class="sdsc-reg" id="sdsc">
			<div class="sdsc-reg-card sdsc-reg-done">
				<?php echo SDSC_Brand::logo( 'stacked', 'sdsc-reg-logo sdsc-reg-logo--stacked', 110 ); // phpcs:ignore ?>
				<?php if ( $row ) : ?>
					<h2 class="sdsc-reg-title">Payment received — you're in!</h2>
					<?php if ( $name ) : ?><p class="sdsc-done-course"><?php echo esc_html( $name ); ?></p><?php endif; ?>
					<p>We received <?php echo esc_html( '$' . number_format( $row['amount'] / 100, 2 ) ); ?>. Your receipt with the class dates is on its way to <?php echo esc_html( $row['email'] ); ?>. The Zoom link and class details come in a separate e-mail before the first session — please check your Spam / Junk folder too.</p>
					<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push({event:'sdsc_payment_complete',value:<?php echo (float) ( $row['amount'] / 100 ); ?>,currency:'USD'});</script>
				<?php else : ?>
					<h2 class="sdsc-reg-title">We're confirming your payment</h2>
					<p>This can take a minute. You will receive an e-mail as soon as it is confirmed. If you were not charged, you can go back and try again.</p>
				<?php endif; ?>
				<p><a class="sdsc-btn" href="tel:+13017263030">Questions? Call (301) 726-3030</a></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function render_success( $class_id ) {
		$pay  = $class_id ? get_post_meta( $class_id, '_sdsc_payment_link', true ) : '';
		$name = $class_id ? self::class_label( $class_id ) : '';
		$rid  = isset( $_GET['sdsc_r'] ) ? absint( $_GET['sdsc_r'] ) : 0; // phpcs:ignore
		$rtok = isset( $_GET['sdsc_t'] ) ? sanitize_text_field( wp_unslash( $_GET['sdsc_t'] ) ) : ''; // phpcs:ignore
		if ( $rid && class_exists( 'SDSC_Pay' ) && hash_equals( SDSC_Pay::token( $rid ), $rtok ) ) {
			$online = SDSC_Pay::url( $rid, $class_id );
			if ( $online ) { $pay = $online; }
		}
		ob_start();
		?>
		<div class="sdsc-reg" id="sdsc">
			<div class="sdsc-reg-card sdsc-reg-done">
				<div class="sdsc-done-scene" aria-hidden="true">
					<div class="sdsc-done-road"></div>
					<div class="sdsc-done-car"><?php echo SDSC_Brand::car( 'left' ); // phpcs:ignore ?></div>
					<span class="sdsc-done-flag">🏁</span>
				</div>
				<?php echo SDSC_Brand::logo( 'stacked', 'sdsc-reg-logo sdsc-reg-logo--stacked', 110 ); // phpcs:ignore ?>
				<?php if ( $pay ) : ?>
					<ol class="sdsc-steps-nav sdsc-steps-nav--done" aria-label="Registration steps">
						<?php foreach ( array( 'Course', 'Student', 'Parent', 'Address', 'Sign' ) as $i => $st ) : ?><li class="is-done"><span><?php echo (int) $i + 1; ?></span><?php echo esc_html( $st ); ?></li><?php endforeach; ?>
						<li class="sdsc-step-pay is-active" aria-current="step"><span>6</span>Pay</li>
					</ol>
				<?php endif; ?>
				<h2 class="sdsc-reg-title"><?php echo $pay ? 'Forms done — last step: payment' : 'You\'re registered!'; ?></h2>
				<?php if ( $name ) : ?><p class="sdsc-done-course"><?php echo esc_html( $name ); ?></p><?php endif; ?>
				<?php if ( $pay ) : ?>
					<p>Your seat is confirmed as soon as the payment goes through.</p>
					<?php
					if ( $rid && class_exists( 'SDSC_Promo' ) && SDSC_Pay::url( $rid, $class_id ) ) {
						$due = SDSC_Promo::amount_for( $rid, $class_id );
						$pr  = SDSC_Promo::for_reg( $rid );
						echo '<p class="sdsc-due">Amount due: <b>$' . esc_html( number_format( $due / 100, 2 ) ) . '</b>' . ( $pr ? ' <span class="sdsc-due-code">(' . esc_html( $pr['label'] . ' with ' . $pr['code'] ) . ')</span>' : '' ) . '</p>';
					}
					?>
					<p><a class="sdsc-btn" href="<?php echo esc_url( $pay ); ?>">Continue to payment &rarr;</a></p>
					<?php
					if ( $rid && class_exists( 'SDSC_Pay' ) && SDSC_Pay::url( $rid, $class_id ) ) :
						global $wpdb;
						$pr   = $wpdb->get_row( $wpdb->prepare( 'SELECT parent_name, parent_phone, parent_email FROM ' . self::table() . ' WHERE id = %d', $rid ) );
						$sent = isset( $_GET['sdsc_sent'] ) ? sanitize_key( $_GET['sdsc_sent'] ) : ''; // phpcs:ignore
						$sms  = class_exists( 'SDSC_Sms' ) && SDSC_Sms::connected();
						?>
						<div class="sdsc-parent-pay">
							<p class="sdsc-or"><span>or</span></p>
							<h3>Is a parent paying?</h3>
							<p>Send them a secure payment link<?php echo $sms ? ' by email and text message' : ' by email'; ?>. They can pay from their own phone or computer.</p>
							<?php if ( 'ok' === $sent ) : ?><p class="sdsc-sent is-ok" role="status">✓ Sent. Your seat is confirmed as soon as the payment goes through.</p><?php elseif ( 'limit' === $sent ) : ?><p class="sdsc-sent" role="status">The link was already sent a few times. Please call us at (301) 726-3030 if it did not arrive.</p><?php elseif ( 'bad' === $sent ) : ?><p class="sdsc-sent" role="alert">Please enter a valid email address.</p><?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sdsc-parent-form">
								<input type="hidden" name="action" value="sdsc_pay_parent" />
								<input type="hidden" name="r" value="<?php echo (int) $rid; ?>" />
								<input type="hidden" name="t" value="<?php echo esc_attr( $rtok ); ?>" />
								<input type="hidden" name="c" value="<?php echo (int) $class_id; ?>" />
								<label class="sdsc-field"><input type="email" name="parent_email" maxlength="190" required placeholder=" " value="<?php echo esc_attr( $pr ? $pr->parent_email : '' ); ?>" /><span>Parent's email</span></label>
								<?php if ( $sms ) : ?><label class="sdsc-field"><input type="tel" name="parent_phone" maxlength="40" placeholder=" " value="<?php echo esc_attr( $pr ? $pr->parent_phone : '' ); ?>" /><span>Parent's mobile (optional, for a text)</span></label><?php endif; ?>
								<button type="submit" class="sdsc-btn sdsc-btn-ghost">Send payment link to my parent</button>
							</form>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<p>We've received your registration and will contact you shortly with payment and class details.</p>
					<p><a class="sdsc-btn" href="tel:+13017263030">Call (301) 726-3030</a></p>
				<?php endif; ?>
			</div>
		</div>
		<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push({event:'sdsc_registration_complete'});</script>
		<?php
		return ob_get_clean();
	}

	/** "Send payment link to my parent": e-mail (and text, when Twilio is connected) the secure payment link. */
	public static function pay_parent() {
		$rid  = absint( $_POST['r'] ?? 0 );
		$tok  = sanitize_text_field( wp_unslash( $_POST['t'] ?? '' ) );
		$cid  = absint( $_POST['c'] ?? 0 );
		$back = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		if ( 0 !== strpos( $back, home_url() ) ) { $back = home_url( '/' ); }
		$back = remove_query_arg( 'sdsc_sent', $back );
		if ( ! $rid || ! class_exists( 'SDSC_Pay' ) || ! hash_equals( SDSC_Pay::token( $rid ), $tok ) ) { wp_safe_redirect( home_url( '/' ) ); exit; }
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, class_id, first_name, last_name, parent_name, parent_email, parent_phone FROM ' . self::table() . ' WHERE id = %d', $rid ) );
		if ( ! $r || (int) $r->class_id !== $cid ) { wp_safe_redirect( home_url( '/' ) ); exit; }
		$url = SDSC_Pay::url( $rid, $cid );
		if ( ! $url ) { wp_safe_redirect( add_query_arg( 'sdsc_sent', 'bad', $back ) . '#sdsc' ); exit; }
		$email = sanitize_email( wp_unslash( $_POST['parent_email'] ?? '' ) );
		if ( ! is_email( $email ) ) { wp_safe_redirect( add_query_arg( 'sdsc_sent', 'bad', $back ) . '#sdsc' ); exit; }
		$key = 'sdsc_payparent_' . $rid;
		$n   = (int) get_transient( $key );
		if ( $n >= 3 ) { wp_safe_redirect( add_query_arg( 'sdsc_sent', 'limit', $back ) . '#sdsc' ); exit; }
		set_transient( $key, $n + 1, DAY_IN_SECONDS );
		$phone = sanitize_text_field( wp_unslash( $_POST['parent_phone'] ?? '' ) );
		// Keep the parent's contact on the registration (only fills empty fields).
		$upd = array();
		if ( ! $r->parent_email ) { $upd['parent_email'] = $email; }
		if ( ! $r->parent_phone && $phone ) { $upd['parent_phone'] = substr( $phone, 0, 40 ); }
		if ( $upd ) { $wpdb->update( self::table(), $upd, array( 'id' => $rid ) ); }

		$student = trim( $r->first_name . ' ' . $r->last_name );
		$class   = self::class_label( $cid );
		$due     = class_exists( 'SDSC_Promo' ) ? SDSC_Promo::amount_for( $rid, $cid ) : SDSC_Pay::amount( $cid );
		$amount  = '$' . number_format( $due / 100, 2 );
		$subject = 'Payment link for ' . $student . ' — Sam\'s Driving School';
		$html    = '<p>Hello' . ( $r->parent_name ? ' ' . esc_html( $r->parent_name ) : '' ) . ',</p>'
			. '<p>' . esc_html( $student ) . ' has registered for <b>' . esc_html( $class ) . '</b> at Sam\'s Driving School and asked you to complete the payment.</p>'
			. '<p>Amount due: <b>' . esc_html( $amount ) . '</b></p>'
			. '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 20px;border-radius:999px;background:#00703c;color:#fff;font-weight:700;text-decoration:none">Pay securely</a></p>'
			. '<p>The seat is confirmed as soon as the payment goes through. Questions? Call (301) 726-3030.</p>';
		if ( class_exists( 'SDSC_Mailer' ) ) { SDSC_Mailer::send( $email, $subject, $html, 'info', array( 'Content-Type: text/html; charset=UTF-8' ) ); } else { wp_mail( $email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) ); }
		if ( $phone && class_exists( 'SDSC_Sms' ) && SDSC_Sms::connected() ) {
			SDSC_Sms::send( $phone, 'Sam\'s Driving School: ' . $student . ' registered for ' . $class . '. Pay ' . $amount . ' securely here: ' . $url, 'reg:' . $rid . ':payparent' );
		}
		wp_safe_redirect( add_query_arg( 'sdsc_sent', 'ok', $back ) . '#sdsc' );
		exit;
	}

	/* ============================= Submit ============================= */

	protected static function fail( $return, $msg ) {
		$url = add_query_arg( array( 'sdsc_reg' => 'error', 'sdsc_msg' => rawurlencode( $msg ) ), $return );
		wp_safe_redirect( $url . '#sdsc' );
		exit;
	}

	protected static function client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = array_map( 'trim', explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip    = $parts[0];
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Decode a canvas data URL into PNG bytes, or '' if invalid/empty. */
	public static function decode_png( $data_url ) {
		if ( ! is_string( $data_url ) || 0 !== strpos( $data_url, 'data:image/png;base64,' ) ) { return ''; }
		$bin = base64_decode( substr( $data_url, 22 ), true );
		if ( ! $bin || strlen( $bin ) > self::MAX_SIG_BYTES || 0 !== strpos( $bin, "\x89PNG\r\n\x1a\n" ) ) { return ''; }
		return $bin;
	}

	/**
	 * Old Forminator signatures are very large PNGs (often 1+ MB). Scale them down to
	 * at most 600px wide, keeping transparency, so the table stays small and pages load fast.
	 */
	public static function shrink_png( $bin, $max_w = 600 ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) { return $bin; }
		$src = @imagecreatefromstring( $bin );
		if ( ! $src ) { return $bin; }
		$w = imagesx( $src ); $h = imagesy( $src );
		$nw = min( $w, $max_w ); $nh = max( 1, (int) round( $h * $nw / $w ) );
		$dst = imagecreatetruecolor( $nw, $nh );
		imagealphablending( $dst, false ); imagesavealpha( $dst, true );
		imagefill( $dst, 0, 0, imagecolorallocatealpha( $dst, 255, 255, 255, 127 ) );
		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );
		ob_start(); imagepng( $dst, null, 9 ); $out = ob_get_clean();
		imagedestroy( $src ); imagedestroy( $dst );
		return ( $out && strlen( $out ) < strlen( $bin ) ) ? $out : $bin;
	}

	public static function age_from_dob( $dob ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $dob, wp_timezone() );
		if ( ! $d ) { return null; }
		return (int) $d->diff( new DateTime( 'now', wp_timezone() ) )->y;
	}

	public static function handle_submit() {
		$return = isset( $_POST['sdsc_return'] ) ? esc_url_raw( wp_unslash( $_POST['sdsc_return'] ) ) : home_url( '/' );
		if ( 0 !== strpos( $return, home_url() ) ) { $return = home_url( '/' ); }
		$return = remove_query_arg( array( 'sdsc_reg', 'sdsc_msg', 'sdsc_class' ), $return );

		// Bot checks: honeypot + JS marker.
		if ( ! empty( $_POST['sdsc_website'] ) || ( $_POST['sdsc_js'] ?? '' ) !== 'ok' ) {
			self::fail( $return, 'Your submission could not be verified. Please make sure JavaScript is enabled and try again.' );
		}

		// Rate limit: 5 submissions per IP per hour.
		$ip  = self::client_ip();
		$key = 'sdsc_reg_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		// Signed-in office staff (walk-in students on the office Wi-Fi) are not limited.
		$staff = class_exists( 'SDSC_Docs' ) && SDSC_Docs::is_office();
		if ( $n >= 5 && ! $staff ) {
			self::fail( $return, 'Too many submissions from your network. Please try again later or call us.' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$p = function( $k, $max = 200 ) {
			return isset( $_POST[ $k ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ), 0, $max ) : '';
		};

		$class_id = absint( $_POST['class_id'] ?? 0 );
		$open_ids = wp_list_pluck( self::open_classes( true ), 'ID' );
		if ( ! $class_id || ! in_array( $class_id, array_map( 'intval', $open_ids ), true ) ) {
			self::fail( $return, 'Please choose a course.' );
		}
		if ( class_exists( 'SDSC_Board' ) && SDSC_Board::is_full( $class_id ) ) {
			self::fail( $return, 'Sorry, this class is full. Please choose another class or call us at (301) 726-3030.' );
		}

		$d = array(
			'first_name'   => $p( 'first_name', 100 ),
			'middle_name'  => $p( 'middle_name', 100 ),
			'last_name'    => $p( 'last_name', 100 ),
			'dob'          => $p( 'dob', 10 ),
			'gender'       => in_array( $p( 'gender', 10 ), array( 'Male', 'Female' ), true ) ? $p( 'gender', 10 ) : '',
			'email'        => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'        => $p( 'phone', 40 ),
			'parent_name'  => $p( 'parent_name', 200 ),
			'parent_phone' => $p( 'parent_phone', 40 ),
			'parent_email' => sanitize_email( wp_unslash( $_POST['parent_email'] ?? '' ) ),
			'parent_consent' => empty( $_POST['parent_consent'] ) ? 0 : 1,
			'street'       => $p( 'street', 200 ),
			'apt'          => $p( 'apt', 100 ),
			'city'         => $p( 'city', 100 ),
			'state'        => $p( 'state', 50 ),
			'zip'          => $p( 'zip', 20 ),
		);

		if ( ! $d['first_name'] || ! $d['last_name'] ) { self::fail( $return, 'Please enter the student\'s first and last name.' ); }
		$age = self::age_from_dob( $d['dob'] );
		if ( null === $age || $age < 14 || $age > 100 ) { self::fail( $return, 'Please enter a valid date of birth.' ); }
		if ( $d['parent_email'] && ! is_email( $d['parent_email'] ) ) { self::fail( $return, 'Please enter a valid parent / guardian email, or leave it empty.' ); }
		if ( ! is_email( $d['email'] ) ) { self::fail( $return, 'Please enter a valid email address.' ); }
		if ( strlen( preg_replace( '/\D/', '', $d['phone'] ) ) < 10 ) { self::fail( $return, 'Please enter a valid phone number.' ); }
		if ( ! $d['street'] || ! $d['city'] || ! $d['zip'] ) { self::fail( $return, 'Please complete the address.' ); }
		if ( empty( $_POST['consent'] ) ) { self::fail( $return, 'Please accept the terms to continue.' ); }
		$promo = class_exists( 'SDSC_Promo' ) ? SDSC_Promo::clean( wp_unslash( $_POST['promo'] ?? '' ) ) : '';
		if ( '' !== $promo ) {
			$chk = SDSC_Promo::check( $promo, $class_id );
			if ( ! $chk[0] ) { self::fail( $return, 'Discount code ' . $promo . ': ' . $chk[1] . ' Remove the code or try another one.' ); }
		}

		$student_sig = self::decode_png( wp_unslash( $_POST['student_sig'] ?? '' ) );
		$parent_sig  = self::decode_png( wp_unslash( $_POST['parent_sig'] ?? '' ) );
		if ( ! $student_sig ) { self::fail( $return, 'Please add the student signature.' ); }
		// Under 18 with the parent part missing, or under 16: the registration is still accepted and
		// SDSC_Followup chases what is missing (parent link, e-mail reminders, texts for the office).

		global $wpdb;
		$row = array_merge( $d, array(
			'created_at'  => current_time( 'mysql' ),
			'source'      => 'native',
			'class_id'    => $class_id,
			'age'         => $age,
			'consent'     => 1,
			'signed_date' => current_time( 'Y-m-d' ),
			'ip'          => $ip,
			'student_sig' => $student_sig,
			'parent_sig'  => $parent_sig ? $parent_sig : null,
		) );
		$ok = $wpdb->insert( self::table(), $row );
		if ( ! $ok ) {
			self::fail( $return, 'Sorry, something went wrong saving your registration. Please call us at (301) 726-3030.' );
		}
		$reg_id = (int) $wpdb->insert_id;

		if ( '' !== $promo ) { SDSC_Promo::attach( $reg_id, $promo, $class_id ); } elseif ( class_exists( 'SDSC_Promo' ) ) { SDSC_Promo::lock_auto( $reg_id, $class_id ); }

		$registrant_id = self::link_registrant( $class_id, $d );
		if ( $registrant_id ) {
			$wpdb->update( self::table(), array( 'registrant_id' => $registrant_id ), array( 'id' => $reg_id ) );
		}

		self::notify_admin( $reg_id, $class_id, $d );
		if ( class_exists( 'SDSC_Followup' ) ) { SDSC_Followup::on_registered( $reg_id ); }
		do_action( 'sdsc_registered', $reg_id, $d, ! empty( $_POST['offers'] ) );

		$q = array( 'sdsc_reg' => 'done', 'sdsc_class' => $class_id );
		if ( class_exists( 'SDSC_Pay' ) ) { $q['sdsc_r'] = $reg_id; $q['sdsc_t'] = SDSC_Pay::token( $reg_id ); }
		wp_safe_redirect( add_query_arg( $q, $return ) . '#sdsc' );
		exit;
	}

	/** Reuse an existing registrant (same class + email) or create one; mark the form as done. */
	protected static function link_registrant( $class_id, $d ) {
		$existing = get_posts( array(
			'post_type'      => SDSC_CPT_Registrants::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array( 'key' => '_sdsc_class_id', 'value' => $class_id ),
				array( 'key' => '_sdsc_email', 'value' => $d['email'] ),
			),
		) );
		$name = trim( $d['first_name'] . ' ' . $d['last_name'] );
		if ( $existing ) {
			$id = (int) $existing[0];
		} else {
			$no   = SDSC_CPT_Registrants::next_student_number( $class_id );
			$code = get_post_meta( $class_id, '_sdsc_course_code', true );
			$id   = wp_insert_post( array(
				'post_type'   => SDSC_CPT_Registrants::CPT,
				'post_title'  => $name . ' — ' . $code . '-' . $no,
				'post_status' => 'publish',
			) );
			if ( ! $id || is_wp_error( $id ) ) { return 0; }
			update_post_meta( $id, '_sdsc_class_id', $class_id );
			update_post_meta( $id, '_sdsc_student_no', $no );
			update_post_meta( $id, '_sdsc_name', $name );
			update_post_meta( $id, '_sdsc_email', $d['email'] );
			update_post_meta( $id, '_sdsc_paid', '' );
			update_post_meta( $id, '_sdsc_registered_at', current_time( 'mysql' ) );
		}
		update_post_meta( $id, '_sdsc_phone', $d['phone'] );
		update_post_meta( $id, '_sdsc_form_done', current_time( 'mysql' ) );
		return $id;
	}

	protected static function notify_admin( $reg_id, $class_id, $d ) {
		global $wpdb;
		$r    = $wpdb->get_row( $wpdb->prepare( 'SELECT id, created_at, first_name, middle_name, last_name, dob, age, gender, email, phone, parent_name, parent_phone, parent_email, parent_consent, street, apt, city, state, zip, notes, ip, (parent_sig IS NOT NULL) has_p FROM ' . self::table() . ' WHERE id = %d', $reg_id ), ARRAY_A ); // phpcs:ignore
		$r    = $r ? $r : $d;
		$code = get_post_meta( $class_id, '_sdsc_course_code', true );
		$start = (string) get_post_meta( $class_id, '_sdsc_start', true );
		$link = admin_url( 'admin.php?page=sdsc-registrations&view=' . $reg_id );
		$name = trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['middle_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
		$addr = trim( ( $r['street'] ?? '' ) . ( ! empty( $r['apt'] ) ? ', ' . $r['apt'] : '' ) . "\n" . ( $r['city'] ?? '' ) . ', ' . ( $r['state'] ?? '' ) . ' ' . ( $r['zip'] ?? '' ), " ,\n" );
		$age  = isset( $r['age'] ) ? (int) $r['age'] : 0;
		$missing_en = array(); $missing_fa = array();
		if ( class_exists( 'SDSC_Followup' ) ) {
			$i = SDSC_Followup::issues( (object) array_merge( array( 'id' => $reg_id, 'registrant_id' => 0, 'has_p' => 0, 'parent_consent' => 0, 'follow_n' => 0, 'follow_at' => null, 'done_at' => null, 'class_id' => $class_id ), (array) $r ) );
			$fa_words = array( 'name' => 'نام', 'phone' => 'تلفن', 'email' => 'ایمیل', 'policies' => 'تأیید قوانین', 'signature' => 'امضا' );
			if ( isset( $i['parent'] ) ) { $missing_en[] = 'Parent: ' . implode( ', ', $i['parent'] ); $missing_fa[] = 'والدین: ' . implode( '، ', array_map( function ( $w ) use ( $fa_words ) { return $fa_words[ $w ] ?? $w; }, $i['parent'] ) ); }
			if ( isset( $i['pay'] ) ) { $missing_en[] = 'Payment not received yet'; $missing_fa[] = 'پرداخت هنوز انجام نشده'; }
			if ( isset( $i['age16'] ) ) { $missing_en[] = 'Student is under 16'; $missing_fa[] = 'شاگرد زیر ۱۶ سال است'; }
		}
		$rows = array(
			array( 'en' => 'Student', 'fa' => 'نام شاگرد', 'v' => $name, 'ltr' => true ),
			array( 'en' => 'Date of birth / age', 'fa' => 'تاریخ تولد / سن', 'v' => trim( ( $r['dob'] ?? '' ) . ( $age ? ' · ' . $age : '' ) ), 'ltr' => true ),
			array( 'en' => 'Gender', 'fa' => 'جنسیت', 'v' => $r['gender'] ?? '', 'ltr' => true ),
			array( 'en' => 'E-mail', 'fa' => 'ایمیل', 'v' => $r['email'] ?? '', 'ltr' => true ),
			array( 'en' => 'Phone', 'fa' => 'تلفن', 'v' => $r['phone'] ?? '', 'ltr' => true ),
			array( 'en' => 'Address', 'fa' => 'آدرس', 'v' => $addr, 'ltr' => true ),
			array( 'en' => 'Class', 'fa' => 'کلاس', 'v' => get_the_title( $class_id ) . ( $code ? " ($code)" : '' ), 'ltr' => true ),
			array( 'en' => 'Class starts', 'fa' => 'شروع کلاس', 'v' => $start ? date_i18n( 'M j, Y', strtotime( $start ) ) : '', 'ltr' => true ),
			array( 'en' => 'Parent / guardian', 'fa' => 'والدین / سرپرست', 'v' => trim( ( $r['parent_name'] ?? '' ) . "\n" . ( $r['parent_phone'] ?? '' ) . "\n" . ( $r['parent_email'] ?? '' ) ), 'ltr' => true ),
			array( 'en' => 'Parent signed', 'fa' => 'امضای والدین', 'v' => ! empty( $r['has_p'] ) ? 'Yes' : 'No', 'v_fa' => ! empty( $r['has_p'] ) ? 'بله' : 'خیر' ),
			array( 'en' => 'Notes', 'fa' => 'توضیحات', 'v' => $r['notes'] ?? '', 'ltr' => true ),
			array( 'en' => 'Submitted', 'fa' => 'زمان ثبت', 'v' => ( $r['created_at'] ?? current_time( 'mysql' ) ) . ( ! empty( $r['ip'] ) ? ' · IP ' . $r['ip'] : '' ), 'ltr' => true ),
			array( 'en' => 'Still missing', 'fa' => 'موارد ناقص', 'v' => $missing_en ? implode( "\n", $missing_en ) : 'Nothing — complete', 'v_fa' => $missing_fa ? implode( "\n", $missing_fa ) : 'هیچ — کامل است' ),
		);
		if ( class_exists( 'SDSC_Mailer' ) ) {
			$args = array(
				'subject_en' => 'New student registration' . ( $code ? " — $code" : '' ) . ' — ' . $name,
				'subject_fa' => 'ثبت‌نام جدید شاگرد',
				'title_en'   => 'New registration: ' . $name,
				'title_fa'   => 'ثبت‌نام جدید: ' . $name,
				'lead_en'    => 'A student completed the registration form on the website.',
				'lead_fa'    => 'یک شاگرد فرم ثبت‌نام سایت را کامل کرد.',
				'rows'       => $rows,
				'note_en'    => $missing_en ? 'Follow up: ' . implode( '; ', $missing_en ) . '.' : '',
				'note_fa'    => $missing_fa ? 'پیگیری لازم: ' . implode( '؛ ', $missing_fa ) . '.' : '',
				'buttons'    => array( array( 'en' => 'Open the registration', 'fa' => 'باز کردن ثبت‌نام', 'url' => $link ) ),
			);
			SDSC_Mailer::admin_report( $args );
			return;
		}
		wp_mail( get_option( 'admin_email' ), 'New student registration' . ( $code ? " — $code" : '' ), "New registration: $name\n$link" );
	}

	/* ============================= Admin ============================= */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Registrations', 'Registrations', 'sdsc_access_registrations', 'sdsc-registrations', array( __CLASS__, 'render_admin' ) );
	}

	public static function sig_url( $id, $who ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_sig&id=' . (int) $id . '&who=' . $who ), 'sdsc_sig_' . (int) $id );
	}

	public static function serve_signature() {
		$id  = absint( $_GET['id'] ?? 0 );
		$who = ( $_GET['who'] ?? '' ) === 'parent' ? 'parent_sig' : 'student_sig';
		if ( ! SDSC_Access::can( 'registrations' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'sdsc_sig_' . $id ) ) {
			status_header( 403 );
			exit;
		}
		global $wpdb;
		$bin = $wpdb->get_var( $wpdb->prepare( "SELECT $who FROM " . self::table() . ' WHERE id = %d', $id ) );
		if ( ! $bin ) { status_header( 404 ); exit; }
		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $bin ) );
		echo $bin; // phpcs:ignore -- binary PNG
		exit;
	}

	public static function render_admin() {
		if ( ! SDSC_Access::can( 'registrations' ) ) { wp_die( 'You do not have permission to access this page.' ); }
		global $wpdb;
		$t    = self::table();
		$view = absint( $_GET['view'] ?? 0 );
		echo '<div class="wrap" dir="ltr" style="text-align:left">';
		if ( $view ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT id, created_at, source, source_entry_id, class_id, first_name, middle_name, last_name, dob, age, gender, email, phone, parent_name, parent_phone, parent_email, parent_consent, street, apt, city, state, zip, consent, signed_date, notes, (student_sig IS NOT NULL) has_s, (parent_sig IS NOT NULL) has_p FROM $t WHERE id = %d", $view ) );
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=sdsc-registrations' ) ) . '">&larr; All registrations</a></p>';
			if ( ! $r ) { echo '<p>Not found.</p></div>'; return; }
			echo '<h1>' . esc_html( trim( "$r->first_name $r->middle_name $r->last_name" ) ) . ' <small style="font-size:14px;color:#50575e;font-weight:600">File no. ' . esc_html( self::file_no( $r ) ) . '</small></h1>';
			if ( class_exists( 'SDSC_Docs' ) && SDSC_Docs::is_office() ) { echo '<p>' . SDSC_Docs::link( $r->email ) . '</p>'; } // phpcs:ignore -- escaped inside
			if ( isset( $_GET['sdsc_reminded'] ) ) { echo '<div class="notice notice-success"><p>Reminder e-mail sent.</p></div>'; } // phpcs:ignore
			if ( class_exists( 'SDSC_Followup' ) && 'native' === $r->source ) { echo SDSC_Followup::panel( $r->id ); } // phpcs:ignore -- escaped inside
			if ( isset( $_GET['sdsc_access'] ) ) { echo '<div class="notice notice-success"><p>' . ( 'opened' === $_GET['sdsc_access'] ? 'Online course access opened.' : 'Manual course access removed.' ) . '</p></div>'; } // phpcs:ignore
			if ( class_exists( 'SDSC_Gate' ) ) { echo SDSC_Gate::panel( $r->email ); } // phpcs:ignore -- escaped inside
			if ( class_exists( 'SDSC_Transfer' ) && 'demo' !== $r->source ) { echo '<div id="sdsc-transfer"></div>' . SDSC_Transfer::panel( $r ); } // phpcs:ignore -- escaped inside
			echo '<table class="widefat striped" style="max-width:800px">';
			$rows = array(
				'Submitted'       => $r->created_at . ( 'forminator' === $r->source ? ' (imported from Forminator #' . $r->source_entry_id . ')' : '' ),
				'Course'          => ( $r->class_id ? self::class_label( $r->class_id, true ) : '—' ) . ( class_exists( 'SDSC_Transfer' ) && SDSC_Transfer::tag( $r->id ) ? ' · ' . SDSC_Transfer::tag( $r->id ) : '' ),
				'Date of birth'   => $r->dob . ( $r->age ? " (age $r->age)" : '' ),
				'Gender'          => $r->gender,
				'Email'           => $r->email,
				'Phone'           => $r->phone,
				'Parent/guardian' => trim( $r->parent_name . ' ' . $r->parent_phone . ' ' . ( $r->parent_email ?? '' ) ) . ( ! empty( $r->parent_consent ) ? ' — accepted the policies' : '' ),
				'Address'         => trim( "$r->street $r->apt, $r->city $r->state $r->zip", ' ,' ),
				'Consent'         => $r->consent ? 'Yes — signed ' . $r->signed_date : 'No',
				'Notes'           => $r->notes,
			);
			foreach ( $rows as $k => $v ) {
				echo '<tr><th style="width:180px">' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
			}
			echo '</table><h2>Signatures</h2><div style="display:flex;gap:24px;flex-wrap:wrap">';
			if ( $r->has_s ) { echo '<figure><img src="' . esc_url( self::sig_url( $r->id, 'student' ) ) . '" style="max-width:360px;border:1px solid #ccd0d4;background:#fff" alt="Student signature"/><figcaption>Student</figcaption></figure>'; }
			if ( $r->has_p ) { echo '<figure><img src="' . esc_url( self::sig_url( $r->id, 'parent' ) ) . '" style="max-width:360px;border:1px solid #ccd0d4;background:#fff" alt="Parent signature"/><figcaption>Parent / guardian</figcaption></figure>'; }
			echo '</div></div>';
			return;
		}

		$s     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per   = 50;
		$where = '1=1';
		$args  = array();
		$by_code = $s && preg_match( '/^[A-Z]{1,4}-?\d{1,4}$/i', trim( $s ) ) ? (int) ( get_posts( array( 'post_type' => 'sdsc_class', 'post_status' => array( 'publish', 'private' ), 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sdsc_course_code', 'meta_value' => strtoupper( trim( $s ) ) ) )[0] ?? 0 ) : 0;
		if ( $by_code ) { // a course code such as C-35 lists that class's students
			$where = 'class_id = %d';
			$args  = array( $by_code );
		} elseif ( $s ) {
			$like  = '%' . $wpdb->esc_like( $s ) . '%';
			$where = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s OR (source <> %s AND id = %d) OR (source = %s AND source_entry_id = %d))';
			$num   = preg_match( '/^\s*(?:SDS-?)?0*(\d+)\s*$/i', $s, $mm ) ? (int) $mm[1] : -1;
			$args  = array( $like, $like, $like, $like, 'forminator', $num, 'forminator', $num );
		}
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $where", $args ) : "SELECT COUNT(*) FROM $t" );
		$sql   = "SELECT id, created_at, source, source_entry_id, class_id, first_name, last_name, email, phone, age FROM $t WHERE $where ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per, ( $paged - 1 ) * $per ) ) ) );

		echo '<h1>Registrations <span class="count">(' . (int) $total . ')</span></h1>';
		echo '<p class="sdsc-lead" id="sdsc-reg-lead">Every sign-up from the website, newest first. Click a student’s name to see the whole form, the signed contract and the signatures.</p>';
		if ( current_user_can( 'manage_options' ) ) { echo '<p class="description">Administrator note: the form is the <code>[sdsc_registration_form]</code> shortcode; add <code>?course=CODE</code> to a link to preselect a class.</p>'; }
		echo '<form method="get" id="sdsc-reg-search"><input type="hidden" name="page" value="sdsc-registrations"/><p class="search-box"><input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="Name, email, phone, file no. or course code (C-35)" aria-label="Search registrations"/> <button class="button">Search</button></p></form>';
		echo '<table class="widefat striped" id="sdsc-reg-list"><thead><tr><th>Date</th><th>File no.</th><th>Student</th><th>Age</th><th>Email</th><th>Phone</th><th>Course</th><th>Source</th><th>Follow-up</th></tr></thead><tbody>';
		if ( ! $rows ) { echo '<tr><td colspan="7">No registrations yet.</td></tr>'; }
		foreach ( $rows as $r ) {
			$url  = admin_url( 'admin.php?page=sdsc-registrations&view=' . $r->id );
			$code = $r->class_id ? get_post_meta( $r->class_id, '_sdsc_course_code', true ) : '';
			echo '<tr><td>' . esc_html( mysql2date( 'M j, Y g:i a', $r->created_at ) ) . '</td><td>' . esc_html( self::file_no( $r ) ) . '</td><td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( "$r->first_name $r->last_name" ) . '</strong></a></td><td>' . esc_html( (string) $r->age ) . '</td><td>' . esc_html( $r->email ) . '</td><td>' . esc_html( $r->phone ) . '</td><td>' . esc_html( $code ?: '—' ) . '</td><td>' . esc_html( array( 'native' => 'Website', 'forminator' => 'Website (old form)', 'demo' => 'Demo', 'archive' => 'Course list (archive)' )[ $r->source ] ?? ucfirst( (string) $r->source ) ) . '</td><td>' . ( 'archive' === $r->source ? '<span style="color:#6b7280" title="Added from the office course list: name, phone and course only. Payment, transfer and attendance are not on record.">Course list only</span>' : ( class_exists( 'SDSC_Followup' ) && 'demo' !== $r->source ? SDSC_Followup::badges( $r->id, 'native' !== $r->source ) : '' ) ) . '</td></tr>'; // phpcs:ignore -- escaped in badges()
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p>' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) . '</p>';
		}
		echo '</div>';
	}

	/* ============================= Reports portal integration ============================= */

	/**
	 * Native registrations shaped like the Forminator rows the reports portal expects,
	 * keyed by lowercase email (latest registration wins).
	 */
	public static function report_rows_by_email() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, created_at, first_name, middle_name, last_name, dob, age, gender, email, phone, parent_name, parent_phone, street, apt, city, state, zip, signed_date, (student_sig IS NOT NULL) has_s, (parent_sig IS NOT NULL) has_p FROM ' . self::table() . ' ORDER BY created_at ASC, id ASC' );
		$out  = array();
		foreach ( $rows as $r ) {
			$email = strtolower( trim( $r->email ) );
			if ( ! $email ) { continue; }
			$out[ $email ] = array(
				'date_created'        => $r->created_at,
				'forminator_entry_id' => 'R' . $r->id,
				'name-1-first-name'   => $r->first_name,
				'name-1-middle-name'  => $r->middle_name,
				'name-1-last-name'    => $r->last_name,
				'date-1'              => $r->dob ? date( 'm/d/Y', strtotime( $r->dob ) ) : '',
				'number-1'            => (string) $r->age,
				'select-2'            => $r->gender,
				'email-1'             => $r->email,
				'phone-1'             => $r->phone,
				'name-2'              => $r->parent_name,
				'phone-2'             => $r->parent_phone,
				'address-1-street_address' => $r->street,
				'address-1-address_line'   => $r->apt,
				'address-1-city'      => $r->city,
				'address-1-state'     => $r->state,
				'address-1-zip'       => $r->zip,
				'date-2'              => $r->signed_date ? date( 'm/d/Y', strtotime( $r->signed_date ) ) : '',
				'signature-1'         => $r->has_s ? self::sig_url( $r->id, 'student' ) : '',
				'signature-2'         => $r->has_p ? self::sig_url( $r->id, 'parent' ) : '',
			);
		}
		return $out;
	}

	/* ============================= WP-CLI import ============================= */

	/**
	 * Import Forminator registration entries.
	 *
	 * ## OPTIONS
	 * --form=<id>
	 * : Forminator form ID.
	 * [--dry-run]
	 * : Only count, do not write.
	 */
	public static function cli_import( $args, $assoc ) {
		global $wpdb;
		self::install();
		$form_id = absint( $assoc['form'] ?? 0 );
		$dry     = ! empty( $assoc['dry-run'] );
		if ( ! $form_id ) { WP_CLI::error( 'Missing --form' ); }
		$p       = $wpdb->prefix;
		$entries = $wpdb->get_results( $wpdb->prepare( "SELECT entry_id, date_created FROM {$p}frmt_form_entry WHERE form_id = %d ORDER BY entry_id ASC", $form_id ) );
		$uploads = wp_get_upload_dir();
		$stats   = array( 'total' => count( $entries ), 'imported' => 0, 'skipped_existing' => 0, 'sig_student' => 0, 'sig_parent' => 0, 'sig_missing' => 0 );

		$read_sig = function( $v ) use ( $uploads, &$stats ) {
			$v = maybe_unserialize( $v );
			$path = '';
			if ( is_array( $v ) && isset( $v['file'] ) ) {
				$path = $v['file']['file_path'] ?? '';
				$base = realpath( $uploads['basedir'] );
				$rp   = $path ? realpath( $path ) : false;
				// Use the stored path only when it is inside this site's uploads; otherwise (entries copied from another site) use the file URL.
				if ( ( ! $rp || ! is_readable( $rp ) || 0 !== strpos( $rp, $base ) ) && ! empty( $v['file']['file_url'] ) ) {
					$rel  = preg_replace( '#^https?://[^/]+/wp-content/uploads/#', '', $v['file']['file_url'] );
					$path = trailingslashit( $uploads['basedir'] ) . $rel;
				}
			}
			if ( ! $path ) { return null; }
			$real = realpath( $path );
			if ( ! $real || 0 !== strpos( $real, realpath( $uploads['basedir'] ) ) || ! is_readable( $real ) ) { $stats['sig_missing']++; return null; }
			$bin = file_get_contents( $real );
			if ( ! $bin || 0 !== strpos( $bin, "\x89PNG" ) || strlen( $bin ) > 2000000 ) { $stats['sig_missing']++; return null; }
			return self::shrink_png( $bin );
		};

		foreach ( $entries as $e ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE source = 'forminator' AND source_entry_id = %d", $e->entry_id ) );
			if ( $exists ) { $stats['skipped_existing']++; continue; }
			$meta = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$p}frmt_form_entry_meta WHERE entry_id = %d", $e->entry_id ) ) as $m ) {
				$meta[ $m->meta_key ] = $m->meta_value;
			}
			$name = maybe_unserialize( $meta['name-1'] ?? '' );
			$addr = maybe_unserialize( $meta['address-1'] ?? '' );
			$dob  = DateTime::createFromFormat( 'm/d/Y', trim( (string) ( $meta['date-1'] ?? '' ) ) );
			$sd   = DateTime::createFromFormat( 'm/d/Y', trim( (string) ( $meta['date-2'] ?? '' ) ) );
			$gen  = trim( (string) ( $meta['select-2'] ?? '' ) );
			$gen  = 'Fmale' === $gen ? 'Female' : ( in_array( $gen, array( 'Male', 'Female' ), true ) ? $gen : '' );
			$pn   = maybe_unserialize( $meta['name-2'] ?? '' );
			if ( is_array( $pn ) ) { $pn = trim( implode( ' ', array_filter( array( $pn['prefix'] ?? '', $pn['first-name'] ?? '', $pn['middle-name'] ?? '', $pn['last-name'] ?? '' ) ) ) ); }
			$s_sig = isset( $meta['signature-1'] ) ? $read_sig( $meta['signature-1'] ) : null;
			$p_sig = isset( $meta['signature-2'] ) ? $read_sig( $meta['signature-2'] ) : null;
			if ( $s_sig ) { $stats['sig_student']++; }
			if ( $p_sig ) { $stats['sig_parent']++; }
			$notes = isset( $meta['select-1'] ) ? 'Course date (old form): ' . wp_strip_all_tags( (string) maybe_unserialize( $meta['select-1'] ) ) : '';
			$row = array(
				'created_at'      => $e->date_created,
				'source'          => 'forminator',
				'source_entry_id' => (int) $e->entry_id,
				'first_name'      => is_array( $name ) ? (string) ( $name['first-name'] ?? '' ) : (string) $name,
				'middle_name'     => is_array( $name ) ? (string) ( $name['middle-name'] ?? '' ) : '',
				'last_name'       => is_array( $name ) ? (string) ( $name['last-name'] ?? '' ) : '',
				'dob'             => $dob ? $dob->format( 'Y-m-d' ) : null,
				'age'             => is_numeric( $meta['number-1'] ?? '' ) ? (int) $meta['number-1'] : null,
				'gender'          => $gen,
				'email'           => sanitize_email( (string) ( $meta['email-1'] ?? '' ) ),
				'phone'           => mb_substr( (string) ( $meta['phone-1'] ?? '' ), 0, 40 ),
				'parent_name'     => mb_substr( (string) $pn, 0, 200 ),
				'parent_phone'    => mb_substr( (string) ( $meta['phone-2'] ?? '' ), 0, 40 ),
				'street'          => is_array( $addr ) ? (string) ( $addr['street_address'] ?? '' ) : '',
				'apt'             => is_array( $addr ) ? (string) ( $addr['address_line'] ?? '' ) : '',
				'city'            => is_array( $addr ) ? (string) ( $addr['city'] ?? '' ) : '',
				'state'           => is_array( $addr ) ? (string) ( $addr['state'] ?? '' ) : '',
				'zip'             => is_array( $addr ) ? (string) ( $addr['zip'] ?? '' ) : '',
				'consent'         => ! empty( $meta['consent-1'] ) ? 1 : 0,
				'signed_date'     => $sd ? $sd->format( 'Y-m-d' ) : null,
				'notes'           => $notes,
				'ip'              => mb_substr( (string) ( $meta['_forminator_user_ip'] ?? '' ), 0, 64 ),
				'student_sig'     => $s_sig,
				'parent_sig'      => $p_sig,
			);
			if ( ! $dry ) {
				if ( false === $wpdb->insert( self::table(), $row ) ) {
					WP_CLI::warning( 'Insert failed for entry ' . $e->entry_id . ': ' . $wpdb->last_error );
					continue;
				}
			}
			$stats['imported']++;
		}
		foreach ( $stats as $k => $v ) { WP_CLI::line( str_pad( $k, 18 ) . $v ); }
		WP_CLI::success( $dry ? 'Dry run complete (nothing written).' : 'Import complete.' );
	}
}

/* Student transfers between schools (transfer in / out, printed record, e-mail with documents). */
require_once __DIR__ . '/class-transfer.php';
SDSC_Transfer::init();
