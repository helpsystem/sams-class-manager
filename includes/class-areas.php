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

	/** Small road-sign icons, drawn inline (no outside files). */
	protected static function ic( $n ) {
		$i = array(
			'pin'   => '<path d="M12 22s7-6.6 7-12a7 7 0 1 0-14 0c0 5.4 7 12 7 12z"/><circle cx="12" cy="10" r="2.6"/>',
			'metro' => '<rect x="5" y="3" width="14" height="14" rx="3"/><path d="M5 11h14M8.5 14h.01M15.5 14h.01M8 21l2-4M16 21l-2-4"/>',
			'zoom'  => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10.5 21 7v10l-5-3.5"/>',
			'wheel' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/><path d="M12 14.5V21M9.6 11.2 3.4 9.6M14.4 11.2l6.2-1.6"/>',
			'flag'  => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
			'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'phone' => '<path d="M6.6 3h3l1.6 4.2-2 1.3a11 11 0 0 0 6.3 6.3l1.3-2 4.2 1.6v3a2 2 0 0 1-2.2 2A17 17 0 0 1 4.6 5.2 2 2 0 0 1 6.6 3z"/>',
		);
		return '<svg class="sda-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $i[ $n ] ?? '' ) . '</svg>';
	}

	protected static function index_css() {
		return '<style>
.sdai{--g:#00703c;--gd:#004d29;--y:#ffc20e;max-width:1180px;margin:0 auto;padding:clamp(18px,3vw,36px) 16px clamp(40px,6vw,72px);font-family:Overpass,system-ui,sans-serif;color:#15191d}
.sdai .sda-ic{width:1.15em;height:1.15em;flex:none}
.sdai-hero{position:relative;overflow:hidden;border-radius:28px;padding:clamp(28px,5vw,56px);color:#fff;background:radial-gradient(120% 140% at 100% 0%,rgba(255,194,14,.22),transparent 45%),linear-gradient(135deg,#00703c,#003d20 70%)}
.sdai-hero::after{content:"";position:absolute;left:-5%;right:-5%;bottom:22px;height:6px;background:repeating-linear-gradient(90deg,var(--y) 0 46px,transparent 46px 84px);opacity:.55;transform:skewX(-20deg);animation:sdai-road 2.4s linear infinite}
@keyframes sdai-road{to{background-position:84px 0}}
.sdai-hero>*{position:relative;z-index:1}
.sdai-kick{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;background:rgba(255,255,255,.14);box-shadow:inset 0 0 0 1px rgba(255,255,255,.3);font-weight:800;font-size:.78rem;letter-spacing:.1em;text-transform:uppercase}
.sdai h2.sda-title{color:#fff;margin:16px 0 10px;font-size:clamp(2.1rem,5vw,3.6rem);line-height:1;font-weight:900;letter-spacing:-.025em}
.sdai h2.sda-title span{color:var(--y)}
.sdai-lead{max-width:62ch;font-size:clamp(1rem,1.6vw,1.15rem);line-height:1.6;color:rgba(255,255,255,.88);margin:0 0 22px}
.sdai-stats{list-style:none;margin:0 0 30px;padding:0;display:flex;flex-wrap:wrap;gap:10px}
.sdai-stats li{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:14px;background:rgba(255,255,255,.1);box-shadow:inset 0 0 0 1px rgba(255,255,255,.18);font-weight:700;font-size:.95rem}
.sdai-stats b{font-size:1.25rem;color:var(--y);font-weight:900}
.sdai-ctas{display:flex;flex-wrap:wrap;gap:10px}
.sdai-btn{display:inline-flex;align-items:center;gap:8px;min-height:48px;padding:0 22px;border-radius:999px;font-weight:800;text-decoration:none!important;transition:transform .2s,box-shadow .2s}
.sdai-btn--y{background:var(--y);color:#15191d!important;box-shadow:0 10px 24px -10px rgba(255,194,14,.8)}
.sdai-btn--o{color:#fff!important;box-shadow:inset 0 0 0 2px rgba(255,255,255,.6)}
.sdai-btn--g{background:var(--g);color:#fff!important}
.sdai-btn:hover,.sdai-btn:focus-visible{transform:translateY(-2px)}
.sdai-h{display:flex;flex-wrap:wrap;align-items:end;justify-content:space-between;gap:8px 20px;margin:clamp(36px,6vw,64px) 0 18px}
.sdai-h h3{margin:0;font-size:clamp(1.5rem,3vw,2.1rem);font-weight:900;letter-spacing:-.02em}
.sdai-h p{margin:0;color:#5b646d;max-width:52ch}
.sdai-towns{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px}
.sdai-town a{position:relative;display:flex;flex-direction:column;height:100%;padding:16px;border-radius:14px;background:var(--g);color:#fff!important;text-decoration:none!important;box-shadow:inset 0 0 0 3px var(--g),inset 0 0 0 5px #fff,0 18px 30px -22px rgba(0,60,30,.9);transition:transform .3s cubic-bezier(.2,.8,.2,1),box-shadow .3s}
.sdai-town a:hover,.sdai-town a:focus-visible{transform:translateY(-4px) rotate(-.4deg);box-shadow:inset 0 0 0 3px var(--g),inset 0 0 0 5px var(--y),0 26px 40px -24px rgba(0,60,30,.95);outline:none}
.sdai-town-top{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:.72rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;opacity:.85}
.sdai-town-name{margin:10px 0 12px;font-size:1.5rem;line-height:1.1;font-weight:900;letter-spacing:-.01em}
.sdai-town-meta{display:flex;align-items:center;gap:7px;margin-top:auto;padding-top:10px;border-top:1px solid rgba(255,255,255,.3);font-size:.9rem;font-weight:700}
.sdai-town-go{display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#fff;color:var(--g);transition:transform .3s}
.sdai-town a:hover .sdai-town-go{transform:translateX(4px);background:var(--y);color:#15191d}
.sdai-town--home a{background:#15191d;box-shadow:inset 0 0 0 3px #15191d,inset 0 0 0 5px var(--y),0 18px 30px -22px rgba(0,0,0,.9)}
.sdai-town--home .sdai-town-top{color:var(--y);opacity:1}
.sdai-steps{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;counter-reset:s}
.sdai-steps li{position:relative;padding:22px 20px 20px;border-radius:18px;background:#fff;box-shadow:0 0 0 1px #e1e6ea,0 20px 40px -32px rgba(0,0,0,.5)}
.sdai-steps li::before{counter-increment:s;content:counter(s);position:absolute;top:18px;right:18px;font-size:2.4rem;font-weight:900;line-height:1;color:#eef2ef}
.sdai-steps .sda-ic{width:40px;height:40px;padding:9px;border-radius:12px;background:#e7f5ec;color:var(--g)}
.sdai-steps h4{margin:14px 0 6px;font-size:1.1rem;font-weight:900}
.sdai-steps p{margin:0;color:#5b646d;line-height:1.55}
.sdai .sda-classes li{background:#fff;box-shadow:0 0 0 1px #e1e6ea}
.sdai-end{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-top:clamp(36px,6vw,60px);padding:clamp(22px,4vw,34px);border-radius:22px;background:#fff8e1;box-shadow:inset 0 0 0 2px var(--y)}
.sdai-end h3{margin:0 0 4px;font-size:1.4rem;font-weight:900}
.sdai-end p{margin:0;color:#5b4a00}
@media (prefers-reduced-motion:reduce){.sdai-hero::after{animation:none}.sdai-town a,.sdai-btn{transition:none}}
</style>';
	}

	public static function index() {
		$towns = self::areas();
		$metro = count( array_filter( wp_list_pluck( $towns, 1 ) ) );
		ob_start();
		echo self::css(); // phpcs:ignore
		echo self::index_css(); // phpcs:ignore
		?>
		<div class="sdai">
			<header class="sdai-hero">
				<span class="sdai-kick"><?php echo self::ic( 'pin' ); // phpcs:ignore ?>Montgomery County, Maryland</span>
				<h2 class="sda-title">Areas we <span>serve</span></h2>
				<p class="sdai-lead">Sam's Driving School is at 751 Rockville Pike in Rockville. The 30 hours of class are on Zoom, so students join from anywhere in Montgomery County, and we offer free pickup at Rockville Metro for driving lessons.</p>
				<ul class="sdai-stats">
					<li><b><?php echo (int) count( $towns ); ?></b> communities</li>
					<li><?php echo self::ic( 'zoom' ); // phpcs:ignore ?><b>30h</b> class on Zoom</li>
					<li><?php echo self::ic( 'metro' ); // phpcs:ignore ?><b><?php echo (int) $metro; ?></b> on the Metro Red Line</li>
					<li><?php echo self::ic( 'flag' ); // phpcs:ignore ?>Approved by MVA</li>
				</ul>
				<div class="sdai-ctas">
					<a class="sdai-btn sdai-btn--y" href="<?php echo esc_url( home_url( '/#driver-ed' ) ); ?>">See class dates <?php echo self::ic( 'arrow' ); // phpcs:ignore ?></a>
					<a class="sdai-btn sdai-btn--o" href="tel:+13017263030"><?php echo self::ic( 'phone' ); // phpcs:ignore ?>(301) 726-3030</a>
				</div>
			</header>

			<div class="sdai-h"><h3>Pick your town</h3><p>Each page shows how students from that town take the course with us and how to get to the school.</p></div>
			<ul class="sdai-towns">
				<?php foreach ( $towns as $slug => $a ) : $home = 'rockville' === $slug; ?>
					<li class="sdai-town<?php echo $home ? ' sdai-town--home' : ''; ?>">
						<a href="<?php echo esc_url( self::url( $slug ) ); ?>">
							<span class="sdai-town-top"><span><?php echo $home ? 'Our school is here' : 'Montgomery County'; ?></span><?php echo self::ic( $home ? 'pin' : 'wheel' ); // phpcs:ignore ?></span>
							<span class="sdai-town-name"><?php echo esc_html( $a[0] ); ?></span>
							<span class="sdai-town-meta">
								<?php if ( $a[1] ) : ?><?php echo self::ic( 'metro' ); // phpcs:ignore ?><span>Red Line: <?php echo esc_html( $a[1] ); ?></span>
								<?php else : ?><?php echo self::ic( 'zoom' ); // phpcs:ignore ?><span>Class on Zoom</span><?php endif; ?>
								<span class="sdai-town-go" style="margin-left:auto"><?php echo self::ic( 'arrow' ); // phpcs:ignore ?></span>
							</span>
							<span class="screen-reader-text"> — driving school for <?php echo esc_html( $a[0] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="sdai-h"><h3>From anywhere in the county</h3></div>
			<ol class="sdai-steps">
				<li><?php echo self::ic( 'zoom' ); // phpcs:ignore ?><h4>30 hours of class on Zoom</h4><p>Join the MVA 36-hour course from home, wherever you live in Montgomery County.</p></li>
				<li><?php echo self::ic( 'wheel' ); // phpcs:ignore ?><h4>6 hours behind the wheel</h4><p>Two-hour lessons with a certified instructor in a dual-control car, with free pickup at Rockville Metro.</p></li>
				<li><?php echo self::ic( 'flag' ); // phpcs:ignore ?><h4>Road test</h4><p>Take your MVA road test in our insured school car, and practise the route with us first.</p></li>
			</ol>

			<div class="sdai-h"><h3>Upcoming 36-hour classes</h3><p>Pick a date and enroll online.</p></div>
			<?php echo self::classes_html(); // phpcs:ignore ?>

			<div class="sdai-end">
				<div><h3>Not sure which class fits?</h3><p>Call us and we will help you pick a date. English, Spanish and Farsi (Persian).</p></div>
				<a class="sdai-btn sdai-btn--g" href="tel:+13017263030"><?php echo self::ic( 'phone' ); // phpcs:ignore ?>Call (301) 726-3030</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * "We're hiring" card that leads to the Join our team page. Used in the site footer (dark) and on Contact Us (light).
	 */
	public static function hiring_card( $variant = 'light' ) {
		if ( ! get_page_by_path( self::JOIN ) || is_page( self::JOIN ) ) { return ''; } // not on the Join page itself
		static $css = false;
		$h = '';
		if ( ! $css ) {
			$css = true;
			$h  .= '<style>
.sdhire{--g:#00703c;--y:#ffc20e;position:relative;display:grid;gap:10px;padding:18px 18px 16px;border-radius:16px;font-family:Overpass,system-ui,sans-serif;text-align:left;isolation:isolate;overflow:hidden}
.sdhire--dark{margin-top:22px;color:#eef3ef;background:linear-gradient(160deg,rgba(255,194,14,.14),rgba(0,112,60,.18));box-shadow:inset 0 0 0 1px rgba(255,194,14,.45)}
.sdhire--light{max-width:1100px;margin:28px auto;padding:clamp(20px,4vw,34px);grid-template-columns:auto 1fr auto;align-items:center;gap:14px 24px;color:#15191d;background:linear-gradient(120deg,#fff8e1,#fff 60%);box-shadow:inset 0 0 0 2px var(--y),0 24px 40px -34px rgba(0,0,0,.6)}
.sdhire-badge{display:inline-flex;align-items:center;gap:8px;width:max-content;padding:4px 11px;border-radius:999px;background:var(--y);color:#15191d;font-size:.72rem;font-weight:900;letter-spacing:.1em;text-transform:uppercase}
.sdhire-dot{width:8px;height:8px;border-radius:50%;background:#00703c;box-shadow:0 0 0 0 rgba(0,112,60,.7);animation:sdhire-p 1.8s infinite}
@keyframes sdhire-p{70%{box-shadow:0 0 0 8px rgba(0,112,60,0)}100%{box-shadow:0 0 0 0 rgba(0,112,60,0)}}
.sdhire-t{margin:0!important;font-size:1.15rem;line-height:1.2;font-weight:900;color:inherit}
.sdhire--light .sdhire-t{font-size:clamp(1.3rem,2.6vw,1.7rem)}
.sdhire-p{margin:0!important;font-size:.92rem;line-height:1.5;opacity:.85}
.sdhire-chips{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 0;padding:0;list-style:none}
.sdhire-chips li{padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700;background:rgba(0,112,60,.1);color:#004d29}
.sdhire--dark .sdhire-chips li{background:rgba(255,255,255,.1);color:#eef3ef}
.sdhire-ic{display:grid;place-items:center;width:64px;height:64px;border-radius:18px;background:#00703c;color:#fff}
.sdhire-ic svg{width:36px;height:36px;animation:sdhire-w 6s ease-in-out infinite}
@keyframes sdhire-w{0%,100%{transform:rotate(0)}40%{transform:rotate(-24deg)}70%{transform:rotate(14deg)}}
.sdhire-btn{display:inline-flex;align-items:center;gap:8px;width:max-content;min-height:42px;padding:0 18px;border-radius:999px;background:var(--y);color:#15191d!important;font-weight:900;text-decoration:none!important;transition:transform .2s,box-shadow .2s;box-shadow:0 10px 22px -12px rgba(255,194,14,.9)}
.sdhire--light .sdhire-btn{background:#00703c;color:#fff!important;min-height:48px;padding:0 24px;box-shadow:0 12px 24px -14px rgba(0,112,60,.9)}
.sdhire-btn svg{width:16px;height:16px;transition:transform .25s}
.sdhire-btn:hover,.sdhire-btn:focus-visible{transform:translateY(-2px);outline:none}
.sdhire-btn:hover svg,.sdhire-btn:focus-visible svg{transform:translateX(4px)}
@media (max-width:720px){.sdhire--light{grid-template-columns:1fr}.sdhire--light .sdhire-ic{display:none}}
@media (prefers-reduced-motion:reduce){.sdhire-dot,.sdhire-ic svg{animation:none}}
</style>';
		}
		$url   = home_url( '/' . self::JOIN . '/' );
		$arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
		$badge = '<span class="sdhire-badge"><span class="sdhire-dot" aria-hidden="true"></span>We\'re hiring</span>';
		if ( 'dark' === $variant ) {
			return $h . '<div class="sdhire sdhire--dark">' . $badge
				. '<p class="sdhire-t">Become a driving instructor</p>'
				. '<p class="sdhire-p">Behind-the-wheel and Zoom classroom roles in Rockville.</p>'
				. '<a class="sdhire-btn" href="' . esc_url( $url ) . '">Apply now ' . $arrow . '</a></div>';
		}
		return $h . '<section class="sdhire sdhire--light" aria-label="Jobs at Sam\'s Driving School">'
			. '<span class="sdhire-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/><path d="M12 14.5V21M9.6 11.2 3.4 9.6M14.4 11.2l6.2-1.6"/></svg></span>'
			. '<div style="display:grid;gap:8px">' . $badge
			. '<h2 class="sdhire-t">Teach Maryland\'s new drivers with us</h2>'
			. '<p class="sdhire-p">We are looking for patient, calm driving instructors. Instructors who speak Spanish or Farsi (Persian) are especially welcome.</p>'
			. '<ul class="sdhire-chips"><li>Behind-the-wheel instructor</li><li>Classroom instructor (Zoom)</li><li>Not licensed yet? Ask about the apprentice path</li></ul></div>'
			. '<a class="sdhire-btn" href="' . esc_url( $url ) . '">Apply now ' . $arrow . '</a></section>';
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

	protected static function join_css() {
		return '<style>
.sdj{--g:#00703c;--gd:#003d20;--y:#ffc20e;max-width:1180px;margin:0 auto;padding:clamp(18px,3vw,36px) 16px clamp(40px,6vw,72px);font-family:Overpass,system-ui,sans-serif;color:#15191d}
.sdj .sda-ic{width:1.15em;height:1.15em;flex:none}
.sdj-hero{position:relative;overflow:hidden;display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,.9fr);gap:28px;align-items:center;border-radius:28px;padding:clamp(28px,5vw,56px);color:#fff;background:radial-gradient(120% 140% at 100% 0%,rgba(255,194,14,.24),transparent 45%),linear-gradient(135deg,#00703c,#003d20 70%)}
.sdj-hero::after{content:"";position:absolute;left:-5%;right:-5%;bottom:22px;height:6px;background:repeating-linear-gradient(90deg,var(--y) 0 46px,transparent 46px 84px);opacity:.5;transform:skewX(-20deg);animation:sdj-road 2.4s linear infinite}
@keyframes sdj-road{to{background-position:84px 0}}
.sdj-hero>*{position:relative;z-index:1}
.sdj-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;background:var(--y);color:#15191d;font-weight:900;font-size:.76rem;letter-spacing:.1em;text-transform:uppercase}
.sdj-dot{width:8px;height:8px;border-radius:50%;background:var(--g);animation:sdj-p 1.8s infinite}
@keyframes sdj-p{0%{box-shadow:0 0 0 0 rgba(0,112,60,.8)}70%,100%{box-shadow:0 0 0 9px rgba(0,112,60,0)}}
.sdj h2.sda-title{color:#fff;margin:16px 0 12px;font-size:clamp(2.1rem,5vw,3.5rem);line-height:1.02;font-weight:900;letter-spacing:-.025em}
.sdj h2.sda-title span{color:var(--y)}
.sdj-lead{margin:0 0 24px;max-width:60ch;font-size:clamp(1rem,1.6vw,1.13rem);line-height:1.6;color:rgba(255,255,255,.9)}
.sdj-ctas{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:24px}
.sdj-btn{display:inline-flex;align-items:center;gap:8px;min-height:50px;padding:0 24px;border:0;border-radius:999px;font:inherit;font-weight:900;text-decoration:none!important;cursor:pointer;transition:transform .2s,box-shadow .2s}
.sdj-btn--y{background:var(--y);color:#15191d!important;box-shadow:0 12px 26px -12px rgba(255,194,14,.9)}
.sdj-btn--o{color:#fff!important;box-shadow:inset 0 0 0 2px rgba(255,255,255,.6)}
.sdj-btn--g{background:var(--g);color:#fff!important;box-shadow:0 12px 26px -14px rgba(0,112,60,.9)}
.sdj-btn:hover,.sdj-btn:focus-visible{transform:translateY(-2px)}
.sdj-chips{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px}
.sdj-chips li{display:flex;align-items:center;gap:7px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.1);box-shadow:inset 0 0 0 1px rgba(255,255,255,.2);font-weight:700;font-size:.9rem}
.sdj-card{position:relative;padding:22px;border-radius:22px;background:#fff;color:#15191d;box-shadow:0 30px 60px -30px rgba(0,0,0,.6);transform:rotate(1.5deg)}
.sdj-card::before{content:"";position:absolute;top:-10px;left:50%;width:70px;height:18px;margin-left:-35px;border-radius:6px;background:rgba(255,194,14,.85)}
.sdj-card-k{margin:0 0 4px;font-size:.72rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:var(--g)}
.sdj-card-t{margin:0 0 14px;font-size:1.3rem;font-weight:900;line-height:1.15}
.sdj-card ul{list-style:none;margin:0 0 16px;padding:0;display:grid;gap:9px}
.sdj-card li{display:flex;gap:9px;align-items:flex-start;line-height:1.4}
.sdj-card li .sda-ic{color:var(--g);margin-top:2px}
.sdj-h{display:flex;flex-wrap:wrap;align-items:end;justify-content:space-between;gap:8px 20px;margin:clamp(40px,6vw,68px) 0 18px}
.sdj-h h3{margin:0;font-size:clamp(1.5rem,3vw,2.1rem);font-weight:900;letter-spacing:-.02em}
.sdj-h p{margin:0;color:#5b646d;max-width:54ch}
.sdj-roles{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.sdj-role{display:flex;flex-direction:column;gap:10px;padding:24px;border-radius:20px;background:#fff;box-shadow:0 0 0 1px #e1e6ea,0 24px 44px -34px rgba(0,0,0,.55);transition:transform .3s cubic-bezier(.2,.8,.2,1),box-shadow .3s}
.sdj-role:hover{transform:translateY(-4px);box-shadow:0 0 0 2px var(--g),0 30px 50px -34px rgba(0,60,30,.7)}
.sdj-role-ic{display:grid;place-items:center;width:52px;height:52px;border-radius:16px;background:#e7f5ec;color:var(--g)}
.sdj-role-ic .sda-ic{width:28px;height:28px}
.sdj-role h4{margin:4px 0 0;font-size:1.2rem;font-weight:900}
.sdj-role p{margin:0;color:#5b646d;line-height:1.55}
.sdj-role a{margin-top:auto;font-weight:900;color:var(--g)!important;text-decoration:none!important}
.sdj-role--new{background:#fff8e1;box-shadow:inset 0 0 0 2px var(--y)}
.sdj-role--new .sdj-role-ic{background:var(--y);color:#15191d}
.sdj-why{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.sdj-why li{display:flex;gap:14px;align-items:flex-start;padding:18px;border-radius:18px;background:#f4f8f5}
.sdj-why .sda-ic{width:40px;height:40px;padding:9px;border-radius:12px;background:#fff;color:var(--g);box-shadow:0 0 0 1px #dfe8e2}
.sdj-why b{display:block;margin-bottom:3px;font-size:1.02rem}
.sdj-why span{color:#5b646d;line-height:1.5;font-size:.95rem}
.sdj-path{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;counter-reset:p;position:relative}
.sdj-path::before{content:"";position:absolute;left:30px;right:30px;top:26px;height:4px;background:repeating-linear-gradient(90deg,var(--y) 0 18px,transparent 18px 30px)}
.sdj-path li{position:relative;padding-top:66px}
.sdj-path li::before{counter-increment:p;content:counter(p);position:absolute;top:0;left:0;display:grid;place-items:center;width:56px;height:56px;border-radius:50%;background:var(--g);color:#fff;font-size:1.4rem;font-weight:900;box-shadow:0 0 0 6px #fff,0 12px 22px -12px rgba(0,60,30,.9)}
.sdj-path b{display:block;margin-bottom:4px;font-size:1.05rem}
.sdj-path span{color:#5b646d;line-height:1.5;font-size:.95rem}
.sdj-note{margin:16px 0 0;font-size:.92rem;color:#5b646d}
.sdj-note a{color:var(--g)!important;font-weight:800}
.sdj-apply{display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.4fr);gap:clamp(18px,3vw,32px);margin-top:clamp(40px,6vw,68px);padding:clamp(18px,3vw,32px);border-radius:28px;background:linear-gradient(160deg,#f4f8f5,#fff);box-shadow:0 0 0 1px #e1e6ea}
.sdj-apply-side{padding:clamp(20px,3vw,28px);border-radius:22px;background:linear-gradient(160deg,#00703c,#003d20);color:#fff;align-self:start;position:sticky;top:110px}
.sdj-apply-side h3{margin:12px 0 10px;color:#fff;font-size:clamp(1.5rem,3vw,2rem);font-weight:900;line-height:1.1}
.sdj-apply-side p{margin:0 0 16px;color:rgba(255,255,255,.88);line-height:1.55}
.sdj-apply-side ol{margin:0 0 18px;padding-left:20px;display:grid;gap:8px;color:rgba(255,255,255,.92)}
.sdj-apply-side a{color:var(--y)!important;font-weight:900}
.sdj .sda-form{max-width:none;grid-template-columns:1fr 1fr;gap:14px}
.sdj .sda-form .sdj-full{grid-column:1/-1}
.sdj .sda-form label{font-size:.92rem}
.sdj .sda-form input,.sdj .sda-form select,.sdj .sda-form textarea{width:100%;box-sizing:border-box;background:#fff;border-color:#cfd8dd;transition:border-color .2s,box-shadow .2s}
.sdj .sda-form input:focus,.sdj .sda-form select:focus,.sdj .sda-form textarea:focus{outline:none;border-color:var(--g);box-shadow:0 0 0 4px rgba(0,112,60,.15)}
.sdj .sda-form fieldset label{position:relative;padding:9px 14px;border-radius:999px;background:#fff;box-shadow:inset 0 0 0 1.5px #cfd8dd;cursor:pointer;font-size:.9rem;transition:background .2s,box-shadow .2s}
.sdj .sda-form fieldset input{width:auto;accent-color:#00703c}
.sdj .sda-form fieldset label:has(input:checked){background:#e7f5ec;box-shadow:inset 0 0 0 2px var(--g)}
.sdj .sda-form .sdj-btn{justify-self:start}
.sdj .sda-ok{display:flex;gap:12px;align-items:center;padding:22px;font-size:1.1rem}
@media (max-width:900px){.sdj-hero,.sdj-apply{grid-template-columns:1fr}.sdj-card{transform:none}.sdj-apply-side{position:static}.sdj-path{grid-template-columns:1fr 1fr;row-gap:24px}.sdj-path::before{display:none}}
@media (max-width:560px){.sdj .sda-form{grid-template-columns:1fr}.sdj-path{grid-template-columns:1fr}.sdj-path li{padding:6px 0 0 72px;min-height:56px}}
@media (prefers-reduced-motion:reduce){.sdj-hero::after,.sdj-dot{animation:none}.sdj-role,.sdj-btn{transition:none}}
</style>';
	}

	public static function join() {
		$st  = isset( $_GET['sdsc_join'] ) ? sanitize_key( $_GET['sdsc_join'] ) : ''; // phpcs:ignore
		$chk = '<svg class="sda-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
		$mva = 'https://mva.maryland.gov/your-mva-guide/businesses/drivers-education-providers/instructor-licensing-certification';
		ob_start();
		echo self::css(); // phpcs:ignore
		echo self::join_css(); // phpcs:ignore
		?>
		<div class="sdj">
			<header class="sdj-hero">
				<div>
					<span class="sdj-badge"><span class="sdj-dot" aria-hidden="true"></span>We're hiring · Rockville, MD</span>
					<h2 class="sda-title">Teach Maryland's <span>new drivers</span> with us</h2>
					<p class="sdj-lead">Sam's Driving School is an MVA-approved driving school in Rockville. We are looking for patient, calm instructors for behind-the-wheel lessons and for our 36-hour driver's ed classes on Zoom. Instructors who speak Spanish or Farsi (Persian) are especially welcome.</p>
					<div class="sdj-ctas">
						<a class="sdj-btn sdj-btn--y" href="#apply">Apply now <?php echo self::ic( 'arrow' ); // phpcs:ignore ?></a>
						<a class="sdj-btn sdj-btn--o" href="tel:+13017263030"><?php echo self::ic( 'phone' ); // phpcs:ignore ?>(301) 726-3030</a>
					</div>
					<ul class="sdj-chips">
						<li><?php echo self::ic( 'flag' ); // phpcs:ignore ?>MVA-approved school</li>
						<li><?php echo self::ic( 'wheel' ); // phpcs:ignore ?>Dual-control cars</li>
						<li><?php echo self::ic( 'zoom' ); // phpcs:ignore ?>Classroom on Zoom</li>
					</ul>
				</div>
				<aside class="sdj-card" aria-label="Who we are looking for">
					<p class="sdj-card-k">Who we're looking for</p>
					<p class="sdj-card-t">Patient. Calm. Clear.</p>
					<ul>
						<li><?php echo $chk; // phpcs:ignore ?>You enjoy teaching new and nervous drivers</li>
						<li><?php echo $chk; // phpcs:ignore ?>You hold a Maryland instructor license — or want to start the path</li>
						<li><?php echo $chk; // phpcs:ignore ?>English, Spanish or Farsi (Persian) — more languages welcome</li>
					</ul>
					<a class="sdj-btn sdj-btn--g" href="#apply">Start your application</a>
				</aside>
			</header>

			<div class="sdj-h"><h3>Open roles</h3><p>Choose one, or tell us you would like to do both.</p></div>
			<div class="sdj-roles">
				<article class="sdj-role">
					<span class="sdj-role-ic"><?php echo self::ic( 'wheel' ); // phpcs:ignore ?></span>
					<h4>Behind-the-wheel instructor</h4>
					<p>Two-hour lessons in our dual-control cars, road test practice, and nervous drivers.</p>
					<a href="#apply">Apply for this role &rarr;</a>
				</article>
				<article class="sdj-role">
					<span class="sdj-role-ic"><?php echo self::ic( 'zoom' ); // phpcs:ignore ?></span>
					<h4>Classroom instructor (Zoom)</h4>
					<p>Teach the classroom part of the MVA 36-hour course online.</p>
					<a href="#apply">Apply for this role &rarr;</a>
				</article>
				<article class="sdj-role sdj-role--new">
					<span class="sdj-role-ic"><?php echo self::ic( 'flag' ); // phpcs:ignore ?></span>
					<h4>Not licensed yet?</h4>
					<p>In Maryland, new instructors start with an apprentice instructor permit through a certified driver education school. Apply and tell us — we will talk about the path.</p>
					<a href="#apply">Tell us about you &rarr;</a>
				</article>
			</div>

			<div class="sdj-h"><h3>Why teach with us</h3></div>
			<ul class="sdj-why">
				<li><?php echo self::ic( 'flag' ); // phpcs:ignore ?><div><b>An MVA-approved school</b><span>Teach the official 36-hour driver's ed course in Rockville.</span></div></li>
				<li><?php echo self::ic( 'wheel' ); // phpcs:ignore ?><div><b>Dual-control cars</b><span>Every behind-the-wheel lesson is in a dual-control car.</span></div></li>
				<li><?php echo self::ic( 'zoom' ); // phpcs:ignore ?><div><b>Classroom on Zoom</b><span>The 30 hours of class are taught online.</span></div></li>
				<li><?php echo self::ic( 'pin' ); // phpcs:ignore ?><div><b>Students across the county</b><span>Students from all of Montgomery County, in English, Spanish and Farsi.</span></div></li>
			</ul>

			<div class="sdj-h"><h3>Your path to teaching in Maryland</h3><p>What the MVA asks of driving instructors.</p></div>
			<ol class="sdj-path">
				<li><b>The basics</b><span>A high school diploma or equivalent and a valid driver's license.</span></li>
				<li><b>Background check</b><span>Fingerprinting and a criminal background check.</span></li>
				<li><b>Certification course</b><span>The instructor certification course through a certified school.</span></li>
				<li><b>MVA license</b><span>An apprentice permit first, then the MVA instructor license.</span></li>
			</ol>
			<p class="sdj-note">From the MVA's instructor licensing page. <a href="<?php echo esc_url( $mva ); ?>" target="_blank" rel="noopener">Read the MVA requirements</a>.</p>

			<section class="sdj-apply" id="apply" aria-labelledby="sdj-apply-t">
				<div class="sdj-apply-side">
					<span class="sdj-badge"><span class="sdj-dot" aria-hidden="true"></span>Apply in two minutes</span>
					<h3 id="sdj-apply-t">Ready to start?</h3>
					<p>Send us a few details. No CV needed.</p>
					<ol>
						<li>Fill in this short form</li>
						<li>We read your application</li>
						<li>We call you to talk about the next steps</li>
					</ol>
					<p>Questions? Call <a href="tel:+13017263030">(301) 726-3030</a></p>
				</div>
				<div>
					<?php if ( 'ok' === $st ) : ?><p class="sda-ok"><?php echo $chk; // phpcs:ignore ?>Thank you! We received your application and will call you soon.</p>
					<?php else : ?>
						<?php if ( 'bad' === $st ) : ?><p class="sda-bad">Please enter your name, a valid e-mail and a phone number.</p><?php endif; ?>
						<form class="sda-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="sdsc_join" />
							<div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
							<label class="sdj-full">Full name <input type="text" name="name" maxlength="150" required autocomplete="name"></label>
							<label>Phone <input type="tel" name="phone" maxlength="40" required autocomplete="tel"></label>
							<label>E-mail <input type="email" name="email" maxlength="190" required autocomplete="email"></label>
							<label>Position <select name="role"><option>Behind-the-wheel instructor</option><option>Classroom instructor (Zoom)</option><option>Both</option></select></label>
							<label>Maryland instructor license <select name="license"><option>Licensed instructor</option><option>Apprentice permit</option><option>Not yet</option></select></label>
							<fieldset class="sdj-full"><legend>Languages you teach in</legend><?php foreach ( array( 'English', 'Spanish', 'Farsi (Persian)', 'Other' ) as $l ) : ?><label><input type="checkbox" name="langs[]" value="<?php echo esc_attr( $l ); ?>"> <?php echo esc_html( $l ); ?></label><?php endforeach; ?></fieldset>
							<fieldset class="sdj-full"><legend>When can you teach?</legend><?php foreach ( array( 'Weekday mornings', 'Weekday afternoons', 'Evenings', 'Weekends' ) as $l ) : ?><label><input type="checkbox" name="times[]" value="<?php echo esc_attr( $l ); ?>"> <?php echo esc_html( $l ); ?></label><?php endforeach; ?></fieldset>
							<label class="sdj-full">About you (experience, anything we should know) <textarea name="about" rows="4" maxlength="2000"></textarea></label>
							<button class="sdj-btn sdj-btn--g sdj-full" type="submit">Send application <?php echo self::ic( 'arrow' ); // phpcs:ignore ?></button>
						</form>
					<?php endif; ?>
				</div>
			</section>
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
		$lab = array( 'name' => array( 'Name', 'نام' ), 'phone' => array( 'Phone', 'تلفن' ), 'email' => array( 'E-mail', 'ایمیل' ), 'role' => array( 'Position', 'سمت' ), 'license' => array( 'Maryland instructor license', 'مجوز مربیگری مریلند' ), 'langs' => array( 'Languages', 'زبان‌ها' ), 'times' => array( 'Can teach', 'زمان‌های آزاد' ), 'about' => array( 'About', 'درباره متقاضی' ) );
		$rows = array();
		foreach ( $lab as $k => $l ) { $rows[] = array( 'en' => $l[0], 'fa' => $l[1], 'v' => $d[ $k ] ?? '', 'ltr' => true ); }
		$rows[] = array( 'en' => 'Sent', 'fa' => 'زمان ارسال', 'v' => current_time( 'mysql' ) . ' · IP ' . substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 64 ), 'ltr' => true ); // phpcs:ignore
		$args = array(
			'subject_en' => 'Instructor application — ' . $d['name'],
			'subject_fa' => 'درخواست همکاری مربی',
			'title_en'   => 'New instructor application',
			'title_fa'   => 'درخواست جدید همکاری مربی',
			'lead_en'    => 'Someone applied on the Join our team page.',
			'lead_fa'    => 'یک نفر از صفحه «Join our team» درخواست همکاری فرستاد.',
			'rows'       => $rows,
			'buttons'    => array( array( 'en' => 'All applications', 'fa' => 'همه درخواست‌ها', 'url' => admin_url( 'admin.php?page=sdsc-applicants' ) ) ),
		);
		if ( class_exists( 'SDSC_Mailer' ) ) { SDSC_Mailer::admin_report( $args ); } else { wp_mail( get_option( 'admin_email' ), $args['subject_en'], print_r( $d, true ) ); } // phpcs:ignore
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
