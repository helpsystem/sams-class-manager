<?php
/**
 * "Areas we serve" pages (one per Montgomery County community) and the "Join our team" page for
 * driving instructors. The pages are real WordPress pages (created once, editable like any page) so
 * Rank Math lists them in the sitemap; their content is built here from facts the site already holds:
 * the office address, the live class list, prices, and public transit (Metro Red Line) access.
 * Nothing is invented per town: the differences between towns are only real ones (Metro station,
 * directions from that town).
 */
defined( 'ABSPATH' ) || exit;

class SDSC_Areas {

	const PARENT = 'areas-we-serve';
	const JOIN   = 'join-our-team';

	/** Montgomery County communities. 'metro' = Red Line station in or next to the community. */
	public static function areas() {
		return array(
			'rockville'      => array( 'Rockville', 'Rockville' ),
			'north-bethesda' => array( 'North Bethesda', 'North Bethesda' ),
			'bethesda'       => array( 'Bethesda', 'Bethesda' ),
			'potomac'        => array( 'Potomac', '' ),
			'gaithersburg'   => array( 'Gaithersburg', '' ),
			'germantown'     => array( 'Germantown', '' ),
			'derwood'        => array( 'Derwood', 'Shady Grove' ),
			'silver-spring'  => array( 'Silver Spring', 'Silver Spring' ),
			'wheaton'        => array( 'Wheaton', 'Wheaton' ),
			'kensington'     => array( 'Kensington', '' ),
			'chevy-chase'    => array( 'Chevy Chase', 'Friendship Heights' ),
			'olney'          => array( 'Olney', '' ),
			'aspen-hill'     => array( 'Aspen Hill', '' ),
		);
	}

	public static function init() {
		add_shortcode( 'sdsc_area', array( __CLASS__, 'area' ) );
		add_shortcode( 'sdsc_areas', array( __CLASS__, 'index' ) );
		add_shortcode( 'sdsc_join', array( __CLASS__, 'join' ) );
		add_action( 'init', array( __CLASS__, 'ensure_pages' ), 30 );
		add_action( 'admin_post_nopriv_sdsc_join', array( __CLASS__, 'join_save' ) );
		add_action( 'admin_post_sdsc_join', array( __CLASS__, 'join_save' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'seo_title' ), 25 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'seo_desc' ), 25 );
		add_action( 'wp_head', array( __CLASS__, 'schema' ), 31 );
	}

	/* ------------------------------------------------------------ pages */

	public static function ensure_pages() {
		if ( '3' === get_option( 'sdsc_areas_pages' ) || ! current_user_can( 'manage_options' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { return; }
		$parent = get_page_by_path( self::PARENT );
		$pid    = $parent ? $parent->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Areas We Serve', 'post_name' => self::PARENT, 'post_content' => '[sdsc_areas]' ) );
		foreach ( self::areas() as $slug => $a ) {
			$path = self::PARENT . '/driving-school-' . $slug;
			if ( get_page_by_path( $path ) ) { continue; }
			wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $pid, 'post_title' => 'Driving School near ' . $a[0] . ', MD', 'post_name' => 'driving-school-' . $slug, 'post_content' => '[sdsc_area town="' . $slug . '"]' ) );
		}
		if ( ! get_page_by_path( self::JOIN ) ) {
			wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Join Our Team — Driving Instructors', 'post_name' => self::JOIN, 'post_content' => '[sdsc_join]' ) );
		}
		update_option( 'sdsc_areas_pages', '3', false );
	}

	public static function url( $slug ) { return home_url( '/' . self::PARENT . '/driving-school-' . $slug . '/' ); }

	protected static function current_town() {
		if ( ! is_page() ) { return ''; }
		$p = get_post();
		return $p && preg_match( '/\[sdsc_area town="([a-z-]+)"\]/', $p->post_content, $m ) && isset( self::areas()[ $m[1] ] ) ? $m[1] : '';
	}

	public static function seo_title( $t ) {
		$s = self::current_town();
		if ( $s ) { return 'Driving School near ' . self::areas()[ $s ][0] . ', MD | MVA-Approved Driver\'s Ed | Sam\'s Driving School'; }
		if ( is_page( self::JOIN ) ) { return 'Driving Instructor Jobs in Rockville, MD | Join Sam\'s Driving School'; }
		if ( is_page( self::PARENT ) ) { return 'Areas We Serve in Montgomery County, MD | Sam\'s Driving School'; }
		return $t;
	}

	public static function seo_desc( $d ) {
		$s = self::current_town();
		if ( $s ) {
			$n = self::areas()[ $s ][0];
			return 'Students from ' . $n . ', MD: MVA-approved 36-hour driver\'s ed on Zoom from home + 6 hours behind the wheel, driving lessons and a road test car. Sam\'s Driving School, 751 Rockville Pike. (301) 726-3030.';
		}
		if ( is_page( self::JOIN ) ) { return 'Sam\'s Driving School in Rockville, MD is looking for driving instructors. Apply online in two minutes.'; }
		if ( is_page( self::PARENT ) ) { return 'Sam\'s Driving School in Rockville teaches students from all of Montgomery County, MD: Bethesda, Potomac, Gaithersburg, Silver Spring, Germantown and more.'; }
		return $d;
	}

	public static function schema() {
		$s = self::current_town();
		if ( ! $s ) { return; }
		$n = self::areas()[ $s ][0];
		$d = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Service',
			'name'        => 'Driver education and driving lessons for ' . $n . ', MD',
			'serviceType' => 'Driving school',
			'provider'    => array( '@id' => home_url( '/' ) . '#driving-school', '@type' => 'DrivingSchool', 'name' => 'Sam\'s Driving School', 'telephone' => '+1-301-726-3030' ),
			'areaServed'  => array( '@type' => 'City', 'name' => $n . ', Maryland' ),
			'url'         => self::url( $s ),
		);
		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore
	}

	protected static function css() {
		static $done = false;
		if ( $done ) { return ''; }
		$done = true;
		return '<style>
.sda{max-width:1100px;margin:0 auto;padding:clamp(24px,4vw,48px) 16px;font-family:Overpass,system-ui,sans-serif;color:#15191d}
.sda .sda-title{font-size:clamp(2rem,4.6vw,3.2rem);line-height:1.05;font-weight:900;letter-spacing:-.02em;margin:10px 0 12px}
.sda h2{font-size:clamp(1.4rem,2.6vw,1.9rem);font-weight:900;margin:34px 0 12px}
.sda p{line-height:1.6}
.sda-kicker{display:inline-flex;gap:6px;align-items:center;padding:5px 12px;border-radius:999px;background:#e7f5ec;color:#005a30;font-weight:800;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase}
.sda-lead{font-size:1.12rem;color:#3d454c;max-width:70ch}
.sda-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
.sda-card{padding:18px;border-radius:16px;background:#fff;box-shadow:0 0 0 1px #e1e6ea,0 18px 36px -30px rgba(0,0,0,.45)}
.sda-card h3{margin:0 0 6px;font-size:1.1rem;font-weight:900}
.sda-card p{margin:0;color:#5b646d}
.sda-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 20px;border-radius:999px;background:#00703c;color:#fff!important;font-weight:800;text-decoration:none!important}
.sda-btn--ghost{background:#fff;color:#005a30!important;box-shadow:inset 0 0 0 2px #00703c}
.sda-ctas{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}
.sda-classes{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.sda-classes li{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;padding:14px 16px;border-radius:14px;background:#f6f8f7;box-shadow:inset 0 0 0 1px #e1e6ea}
.sda-classes b{font-size:1.05rem}
.sda-towns{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px}
.sda-towns a{display:inline-flex;padding:8px 14px;border-radius:999px;background:#eef5f1;color:#0b3d24!important;font-weight:800;text-decoration:none!important}
.sda-towns a:hover{background:#d7ecdf}
.sda-faq details{padding:12px 16px;border-radius:12px;background:#fff;box-shadow:0 0 0 1px #e1e6ea;margin:0 0 8px}
.sda-faq summary{cursor:pointer;font-weight:800}
.sda-form{display:grid;gap:12px;max-width:640px}
.sda-form label{display:grid;gap:5px;font-weight:700}
.sda-form input,.sda-form select,.sda-form textarea{min-height:46px;padding:10px 12px;border:1px solid #cdd5da;border-radius:10px;font:inherit}
.sda-form fieldset{border:0;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:8px 16px}
.sda-form fieldset legend{font-weight:700;margin-bottom:6px;width:100%}
.sda-form fieldset label{display:flex;align-items:center;gap:6px;font-weight:600}
.sda-form fieldset input{min-height:0}
.sda-ok{padding:14px 16px;border-radius:12px;background:#eaf6ef;color:#0b4f2c;font-weight:700}
.sda-bad{padding:14px 16px;border-radius:12px;background:#fdecea;color:#912018;font-weight:700}
</style>';
	}

	protected static function classes_html() {
		if ( ! class_exists( 'SDSC_Class_Feed' ) ) { return ''; }
		$items = SDSC_Class_Feed::items();
		if ( ! $items ) { return '<p>New classes are being scheduled. Call <a href="tel:+13017263030">(301) 726-3030</a> and we will hold a seat for you.</p>'; }
		$h = '<ul class="sda-classes">';
		foreach ( $items as $c ) {
			$h .= '<li><span><b>' . esc_html( $c['name'] ) . '</b><br><span>' . esc_html( $c['when'] ) . '</span></span><span>' . esc_html( $c['price'] ) . ' ' . ( $c['url'] ? '<a class="sda-btn" href="' . esc_url( $c['url'] ) . '">Enroll now</a>' : '<a class="sda-btn sda-btn--ghost" href="tel:+13017263030">Call us</a>' ) . '</span></li>';
		}
		return $h . '</ul>';
	}

	/* ------------------------------------------------------------ one town */

	public static function area( $atts ) {
		$atts = shortcode_atts( array( 'town' => '' ), $atts );
		$all  = self::areas();
		if ( ! isset( $all[ $atts['town'] ] ) ) { return ''; }
		list( $town, $metro ) = $all[ $atts['town'] ];
		$home  = 'rockville' === $atts['town'];
		$dir   = 'https://www.google.com/maps/dir/?api=1&origin=' . rawurlencode( $town . ', MD' ) . '&destination=' . rawurlencode( 'Sam\'s Driving School, 751 Rockville Pike, Rockville, MD 20852' );
		ob_start();
		echo self::css(); // phpcs:ignore
		?>
		<div class="sda">
			<span class="sda-kicker">Approved by MVA · Montgomery County, MD</span>
			<h2 class="sda-title">Driving school for <?php echo esc_html( $town ); ?> students</h2>
			<p class="sda-lead"><?php echo $home ? 'Sam\'s Driving School is right here in Rockville, at 751 Rockville Pike.' : 'Sam\'s Driving School is in Rockville, at 751 Rockville Pike, and teaches students from ' . esc_html( $town ) . ' and all of Montgomery County.'; ?> MVA-approved 36-hour driver's ed, behind-the-wheel lessons and a car for your road test, with male and female instructors who speak English, Spanish and Farsi (Persian).</p>
			<div class="sda-ctas"><a class="sda-btn" href="<?php echo esc_url( home_url( '/#driver-ed' ) ); ?>">See class dates</a><a class="sda-btn sda-btn--ghost" href="tel:+13017263030">Call (301) 726-3030</a></div>

			<h2>How it works for students from <?php echo esc_html( $town ); ?></h2>
			<div class="sda-grid">
				<div class="sda-card"><h3>Class on Zoom, from home</h3><p>The 30 hours of class for the MVA 36-hour course are on Zoom, so you join from <?php echo esc_html( $town ); ?> without driving anywhere.</p></div>
				<div class="sda-card"><h3>6 hours behind the wheel</h3><p>Two-hour driving lessons with a certified instructor in a dual-control car, plus extra practice from 2 to 60 hours if you want more.</p></div>
				<div class="sda-card"><h3>Getting to us</h3><p><?php
				if ( $home ) { echo 'Our office is at 751 Rockville Pike, Unit 9B, and we offer free pickup at Rockville Metro.'; }
				elseif ( $metro ) { echo 'Take the Metro Red Line from ' . esc_html( $metro ) . ' station to Rockville station — no transfer — and use our free pickup at Rockville Metro.'; }
				else { echo 'Our office is at 751 Rockville Pike, Unit 9B, Rockville. We also offer free pickup at Rockville Metro.'; }
				?> <a href="<?php echo esc_url( $dir ); ?>" target="_blank" rel="noopener">Directions from <?php echo esc_html( $town ); ?></a></p></div>
				<div class="sda-card"><h3>Road test</h3><p>Take your MVA road test in our insured school car, and practise the test route with us first.</p></div>
			</div>

			<h2>Upcoming 36-hour classes</h2>
			<?php echo self::classes_html(); // phpcs:ignore ?>

			<h2>Questions from <?php echo esc_html( $town ); ?> families</h2>
			<div class="sda-faq">
				<details><summary>Do we have to come to Rockville for the 36-hour class?</summary><p>No. The 30 hours of class are on Zoom. Only the 6 hours behind the wheel are in the car.</p></details>
				<details><summary>Who can register?</summary><p>In Maryland, students usually start at 16 with a parent's signature. From 18 you can register on your own. Registration takes about 3 minutes online.</p></details>
				<details><summary>Which languages do your instructors speak?</summary><p>English, Spanish and Farsi (Persian). We have male and female instructors.</p></details>
			</div>

			<h2>Other areas we serve</h2>
			<?php echo self::towns( $atts['town'] ); // phpcs:ignore ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function towns( $except = '' ) {
		$h = '<ul class="sda-towns">';
		foreach ( self::areas() as $slug => $a ) {
			if ( $slug === $except ) { continue; }
			$h .= '<li><a href="' . esc_url( self::url( $slug ) ) . '">' . esc_html( $a[0] ) . '</a></li>';
		}
		return $h . '</ul>';
	}

	public static function index() {
		ob_start();
		echo self::css(); // phpcs:ignore
		?>
		<div class="sda">
			<span class="sda-kicker">Montgomery County, MD</span>
			<h2 class="sda-title">Areas we serve</h2>
			<p class="sda-lead">Sam's Driving School is at 751 Rockville Pike in Rockville. The 30 hours of class are on Zoom, so students join from anywhere in Montgomery County, and we offer free pickup at Rockville Metro for driving lessons.</p>
			<?php echo self::towns(); // phpcs:ignore ?>
			<h2>Upcoming 36-hour classes</h2>
			<?php echo self::classes_html(); // phpcs:ignore ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------ join our team */

	public static function table() { global $wpdb; return $wpdb->prefix . 'sdsc_applicants'; }

	protected static function install() {
		if ( '1' === get_option( 'sdsc_applicants_db' ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			name varchar(150) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			role varchar(40) NOT NULL DEFAULT '',
			license varchar(40) NOT NULL DEFAULT '',
			langs varchar(120) NOT NULL DEFAULT '',
			times varchar(120) NOT NULL DEFAULT '',
			about text NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'sdsc_applicants_db', '1', false );
	}

	public static function join() {
		$st = isset( $_GET['sdsc_join'] ) ? sanitize_key( $_GET['sdsc_join'] ) : ''; // phpcs:ignore
		ob_start();
		echo self::css(); // phpcs:ignore
		?>
		<div class="sda">
			<span class="sda-kicker">We're hiring · Rockville, MD</span>
			<h2 class="sda-title">Teach Maryland's new drivers with us</h2>
			<p class="sda-lead">Sam's Driving School is an MVA-approved driving school in Rockville. We are looking for patient, calm instructors for behind-the-wheel lessons and for our 36-hour driver's ed classes on Zoom. Instructors who speak Spanish or Farsi (Persian) are especially welcome.</p>
			<div class="sda-grid">
				<div class="sda-card"><h3>Behind-the-wheel instructor</h3><p>Two-hour lessons in our dual-control cars, road test practice, and nervous drivers.</p></div>
				<div class="sda-card"><h3>Classroom instructor (Zoom)</h3><p>Teach the classroom part of the MVA 36-hour course online.</p></div>
				<div class="sda-card"><h3>Not licensed yet?</h3><p>In Maryland, new instructors start with an apprentice instructor permit through a certified driver education school. Apply and tell us — we will talk about the path.</p></div>
			</div>
			<h2>What Maryland asks of instructors</h2>
			<p>From the MVA's instructor licensing page: a high school diploma or equivalent, fingerprinting and a criminal background check, a valid driver's license, the instructor certification course through a certified school, and an MVA instructor license (apprentice permit first). <a href="https://mva.maryland.gov/your-mva-guide/businesses/drivers-education-providers/instructor-licensing-certification" target="_blank" rel="noopener">Read the MVA requirements</a>.</p>
			<h2 id="apply">Apply in two minutes</h2>
			<?php if ( 'ok' === $st ) : ?><p class="sda-ok">Thank you! We received your application and will call you soon.</p>
			<?php else : ?>
				<?php if ( 'bad' === $st ) : ?><p class="sda-bad">Please enter your name, a valid e-mail and a phone number.</p><?php endif; ?>
				<form class="sda-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sdsc_join" />
					<div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
					<label>Full name <input type="text" name="name" maxlength="150" required autocomplete="name"></label>
					<label>Phone <input type="tel" name="phone" maxlength="40" required autocomplete="tel"></label>
					<label>E-mail <input type="email" name="email" maxlength="190" required autocomplete="email"></label>
					<label>Position <select name="role"><option>Behind-the-wheel instructor</option><option>Classroom instructor (Zoom)</option><option>Both</option></select></label>
					<label>Maryland instructor license <select name="license"><option>Licensed instructor</option><option>Apprentice permit</option><option>Not yet</option></select></label>
					<fieldset><legend>Languages you teach in</legend><?php foreach ( array( 'English', 'Spanish', 'Farsi (Persian)', 'Other' ) as $l ) : ?><label><input type="checkbox" name="langs[]" value="<?php echo esc_attr( $l ); ?>"> <?php echo esc_html( $l ); ?></label><?php endforeach; ?></fieldset>
					<fieldset><legend>When can you teach?</legend><?php foreach ( array( 'Weekday mornings', 'Weekday afternoons', 'Evenings', 'Weekends' ) as $l ) : ?><label><input type="checkbox" name="times[]" value="<?php echo esc_attr( $l ); ?>"> <?php echo esc_html( $l ); ?></label><?php endforeach; ?></fieldset>
					<label>About you (experience, anything we should know) <textarea name="about" rows="4" maxlength="2000"></textarea></label>
					<button class="sda-btn" type="submit">Send application</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function join_save() {
		$back = get_permalink( get_page_by_path( self::JOIN ) );
		$back = $back ? $back : home_url( '/' . self::JOIN . '/' );
		if ( ! empty( $_POST['website'] ) ) { wp_safe_redirect( add_query_arg( 'sdsc_join', 'ok', $back ) . '#apply' ); exit; }
		$key = 'sdsc_join_rl_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) { wp_safe_redirect( add_query_arg( 'sdsc_join', 'bad', $back ) . '#apply' ); exit; }
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		$f = function ( $k, $l ) { return mb_substr( sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ), 0, $l ); };
		$a = function ( $k ) { return implode( ', ', array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST[ $k ] ?? array() ) ) ) ); };
		$d = array( 'name' => $f( 'name', 150 ), 'phone' => $f( 'phone', 40 ), 'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ), 'role' => $f( 'role', 40 ), 'license' => $f( 'license', 40 ), 'langs' => mb_substr( $a( 'langs' ), 0, 120 ), 'times' => mb_substr( $a( 'times' ), 0, 120 ), 'about' => mb_substr( sanitize_textarea_field( wp_unslash( $_POST['about'] ?? '' ) ), 0, 2000 ) );
		if ( ! $d['name'] || ! is_email( $d['email'] ) || strlen( preg_replace( '/\D/', '', $d['phone'] ) ) < 10 ) { wp_safe_redirect( add_query_arg( 'sdsc_join', 'bad', $back ) . '#apply' ); exit; }
		self::install();
		global $wpdb;
		$wpdb->insert( self::table(), $d + array( 'created_at' => current_time( 'mysql' ), 'ip' => substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 64 ) ) );
		$body = "New instructor application\n\n";
		foreach ( $d as $k => $v ) { $body .= ucfirst( $k ) . ': ' . $v . "\n"; }
		$body .= "\nAll applications: " . admin_url( 'admin.php?page=sdsc-applicants' );
		wp_mail( get_option( 'admin_email' ), 'Instructor application — ' . $d['name'], $body );
		wp_safe_redirect( add_query_arg( 'sdsc_join', 'ok', $back ) . '#apply' );
		exit;
	}

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Instructor applications', 'Instructor applications', 'sdsc_access_registrations', 'sdsc-applicants', array( __CLASS__, 'admin' ) );
	}

	public static function admin() {
		self::install();
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 200' );
		echo '<div class="wrap"><h2 class="sda-title">Instructor applications</h2><p>From the <a href="' . esc_url( home_url( '/' . self::JOIN . '/' ) ) . '" target="_blank">Join our team</a> page.</p>';
		if ( ! $rows ) { echo '<p>No applications yet.</p></div>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Name</th><th>Phone</th><th>E-mail</th><th>Position</th><th>License</th><th>Languages</th><th>Times</th><th>About</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( mysql2date( 'M j, Y', $r->created_at ) ) . '</td><td><b>' . esc_html( $r->name ) . '</b></td><td><a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $r->phone ) ) . '">' . esc_html( $r->phone ) . '</a></td><td><a href="mailto:' . esc_attr( $r->email ) . '">' . esc_html( $r->email ) . '</a></td><td>' . esc_html( $r->role ) . '</td><td>' . esc_html( $r->license ) . '</td><td>' . esc_html( $r->langs ) . '</td><td>' . esc_html( $r->times ) . '</td><td>' . esc_html( $r->about ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
