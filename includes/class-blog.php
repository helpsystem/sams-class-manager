<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * "Driving Tips & News" — the school's blog, organised like a learning centre.
 *
 *  - Seven topic categories (Permit & License, Road Test & Skills, Maryland Laws & MVA Updates,
 *    Safe Driving, Parents & Teens, New to Maryland, School News), each with a colour and a 3D icon
 *    that staff can change under Posts → Categories.
 *  - Hub page /driving-tips/ with search, topic chips, a featured article and paging.
 *    Topic pages live under the same address: /driving-tips/road-test-skills/.
 *  - Article page: breadcrumb, topic badge, reading time, level, share links, call-to-action
 *    and related articles from the same topic.
 *  - "Article details" box on the post editor: level (Beginner / Intermediate / Advanced),
 *    language (English / Español / both) and "Feature on the hub".
 *  One-time setup runs automatically (option sdsc_blog_setup). Turn off with option sdsc_blog = 0.
 */
class SDSC_Blog {

	const SETUP = 2;
	const SLUG  = 'driving-tips';
	const PER   = 9;

	public static function on() {
		return '0' !== get_option( 'sdsc_blog', '1' );
	}

	/** slug => [name, description, colour, 3D icon] — the order is the order shown on the site. */
	public static function topics() {
		return array(
			'permit-license'   => array( 'Permit & License', 'Learner’s permit, the MVA knowledge test and every step to a Maryland driver’s license.', '#1d5fd1', 'information' ),
			'road-test-skills' => array( 'Road Test & Skills', 'Parallel parking, the MVA road test course and the skills examiners look for.', '#00703c', 'automobile' ),
			'maryland-laws'    => array( 'Maryland Laws & MVA Updates', 'Rules of the road in Maryland and news from the MVA, explained in plain English.', '#6741a8', 'classical_building' ),
			'safe-driving'     => array( 'Safe Driving', 'Defensive driving, snow and rain, night driving and highways.', '#d9480f', 'warning' ),
			'parents-teens'    => array( 'Parents & Teens', 'For parents of new drivers: practice hours, the Rookie Driver program and supervised driving.', '#c2255c', 'red_heart' ),
			'new-to-maryland'  => array( 'New to Maryland', 'Moved here or converting a license from another state or country? Start here.', '#0b7285', 'glowing_star' ),
			'school-news'      => array( 'School News', 'Awards, new classes and announcements from Sam’s Driving School.', '#a86b00', 'megaphone' ),
		);
	}

	/** Existing articles → topic (matched by slug, so it works the same on staging and live). */
	protected static function initial_topics() {
		return array(
			'common-mistakes-during-road-test'      => array( 'road-test-skills', 'beginner' ),
			'how-to-master-parallel-parking'        => array( 'road-test-skills', 'beginner' ),
			'how-to-get-your-maryland-drivers-license' => array( 'permit-license', 'beginner' ),
			'honored-and-grateful-sams-driving-school-named-best-of-2025-in-rockville' => array( 'school-news', '' ),
		);
	}

	public static function levels() {
		return array( 'beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced' );
	}

	public static function init() {
		if ( ! self::on() ) { return; }
		add_action( 'init', array( __CLASS__, 'setup' ), 99 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 21 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_shortcode( 'sdsc_blog', array( __CLASS__, 'shortcode' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'category_add_form_fields', array( __CLASS__, 'term_fields_add' ) );
		add_action( 'category_edit_form_fields', array( __CLASS__, 'term_fields_edit' ) );
		add_action( 'created_category', array( __CLASS__, 'save_term' ) );
		add_action( 'edited_category', array( __CLASS__, 'save_term' ) );
		add_filter( 'manage_edit-category_columns', array( __CLASS__, 'term_cols' ) );
		add_filter( 'manage_category_custom_column', array( __CLASS__, 'term_col' ), 10, 3 );
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'post_cols' ) );
		add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'post_col' ), 10, 2 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'template_redirect', array( __CLASS__, 'old_links' ), 1 );
	}

	/** /category/<topic>/ (the address before the blog was organised) → /driving-tips/<topic>/ */
	public static function old_links() {
		if ( ! is_404() || self::SLUG !== get_option( 'category_base' ) ) { return; }
		$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		if ( ! preg_match( '#^category/([a-z0-9-]+)#', $path, $m ) ) { return; }
		$t = get_term_by( 'slug', $m[1], 'category' );
		wp_safe_redirect( $t && 'uncategorized' !== $t->slug ? get_term_link( $t ) : self::url(), 301 );
		exit;
	}

	/* ------------------------------------------------------------------ setup */

	public static function setup() {
		if ( (int) get_option( 'sdsc_blog_setup', 0 ) >= self::SETUP ) { return; }
		$ids = array();
		foreach ( self::topics() as $slug => $t ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( ! $term ) {
				$r = wp_insert_term( $t[0], 'category', array( 'slug' => $slug, 'description' => $t[1] ) );
				if ( is_wp_error( $r ) ) { continue; }
				$id = (int) $r['term_id'];
			} else {
				$id = (int) $term->term_id;
			}
			if ( ! get_term_meta( $id, 'sdsc_color', true ) ) { update_term_meta( $id, 'sdsc_color', $t[2] ); }
			if ( ! get_term_meta( $id, 'sdsc_icon', true ) ) { update_term_meta( $id, 'sdsc_icon', $t[3] ); }
			if ( '' === get_term_meta( $id, 'sdsc_order', true ) ) { update_term_meta( $id, 'sdsc_order', count( $ids ) ); }
			$ids[ $slug ] = $id;
		}
		// The empty "News" category from the old theme demo is folded into School News.
		$old = get_term_by( 'slug', 'news', 'category' );
		if ( $old && ! $old->count && isset( $ids['school-news'] ) ) { wp_delete_term( $old->term_id, 'category' ); }

		// Sort existing articles. Only posts that are still "Uncategorized" are touched.
		$unc = (int) get_option( 'default_category' );
		foreach ( self::initial_topics() as $pslug => $set ) {
			$p = get_page_by_path( $pslug, OBJECT, 'post' );
			if ( ! $p || empty( $ids[ $set[0] ] ) ) { continue; }
			$cur = wp_get_post_categories( $p->ID );
			if ( ! $cur || $cur === array( $unc ) ) { wp_set_post_categories( $p->ID, array( $ids[ $set[0] ] ) ); }
			if ( $set[1] && ! get_post_meta( $p->ID, '_sdsc_level', true ) ) { update_post_meta( $p->ID, '_sdsc_level', $set[1] ); }
		}
		// New posts without a topic land in School News instead of "Uncategorized".
		if ( isset( $ids['school-news'] ) ) { update_option( 'default_category', $ids['school-news'] ); }

		// Hub page.
		$hub = (int) get_option( 'sdsc_blog_page' );
		if ( ! $hub || ! get_post( $hub ) ) {
			$p   = get_page_by_path( self::SLUG );
			$hub = $p ? $p->ID : wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Driving Tips & News',
				'post_name'    => self::SLUG,
				'post_content' => '[sdsc_blog]',
			) );
			if ( $hub && ! is_wp_error( $hub ) ) {
				update_option( 'sdsc_blog_page', (int) $hub );
				update_post_meta( $hub, 'rank_math_title', 'Driving Tips & News for Maryland Learners | %sitename%' );
				update_post_meta( $hub, 'rank_math_description', 'Permit test prep, road test skills, Maryland driving laws and safe-driving tips from Sam’s Driving School in Rockville, MD.' );
			}
		}

		// Topic pages share the hub's address: /driving-tips/<topic>/
		if ( '' === (string) get_option( 'category_base' ) ) {
			global $wp_rewrite;
			$wp_rewrite->set_category_base( self::SLUG );
		}
		// Taxonomy URLs were built before the base changed, so the rules are rebuilt on the next request.
		update_option( 'sdsc_blog_flush', 1 );
		update_option( 'sdsc_blog_setup', self::SETUP );
		self::$just_setup = true;
	}

	protected static $just_setup = false;

	public static function maybe_flush() {
		if ( self::$just_setup || ! get_option( 'sdsc_blog_flush' ) ) { return; }
		delete_option( 'sdsc_blog_flush' );
		flush_rewrite_rules( false );
	}

	/* ------------------------------------------------------------------ data */

	public static function url() {
		$id = (int) get_option( 'sdsc_blog_page' );
		return $id ? get_permalink( $id ) : home_url( '/' . self::SLUG . '/' );
	}

	public static function is_hub() {
		$id = (int) get_option( 'sdsc_blog_page' );
		return $id && is_page( $id );
	}

	/** Topic info for a term (falls back to neutral styling for categories staff add later). */
	public static function topic( $term ) {
		if ( ! $term || is_wp_error( $term ) ) { return null; }
		$c = get_term_meta( $term->term_id, 'sdsc_color', true );
		$i = get_term_meta( $term->term_id, 'sdsc_icon', true );
		return array(
			'id'    => (int) $term->term_id,
			'slug'  => $term->slug,
			'name'  => $term->name,
			'desc'  => $term->description,
			'count' => (int) $term->count,
			'color' => preg_match( '/^#[0-9a-f]{6}$/i', (string) $c ) ? $c : '#3b4752',
			'icon'  => $i && preg_match( '/^[a-z_]+$/', $i ) ? $i : '',
			'url'   => get_term_link( $term ),
		);
	}

	/** All topics in display order; hides "Uncategorized" and (optionally) empty ones. */
	public static function all_topics( $hide_empty = true ) {
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => $hide_empty ) );
		if ( is_wp_error( $terms ) ) { return array(); }
		$out = array();
		foreach ( $terms as $t ) {
			if ( 'uncategorized' === $t->slug ) { continue; }
			$x = self::topic( $t );
			$x['order'] = (int) get_term_meta( $t->term_id, 'sdsc_order', true );
			$out[] = $x;
		}
		usort( $out, function ( $a, $b ) { return $a['order'] - $b['order'] ?: strcmp( $a['name'], $b['name'] ); } );
		return $out;
	}

	public static function post_topic( $id = null ) {
		$cats = get_the_category( $id );
		foreach ( (array) $cats as $c ) {
			if ( 'uncategorized' !== $c->slug ) { return self::topic( $c ); }
		}
		return null;
	}

	public static function minutes( $id = null ) {
		$p = get_post( $id );
		$w = $p ? str_word_count( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ) ) : 0;
		return max( 1, (int) ceil( $w / 200 ) );
	}

	/** Plain-text summary. Block boundaries (<br>, </p>, </h2> …) become spaces so words never run together. */
	public static function excerpt( $id, $words = 22 ) {
		$p = get_post( $id );
		if ( ! $p ) { return ''; }
		$src = '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : $p->post_content;
		$src = preg_replace( '#<(br|/p|/h[1-6]|/li|/div)[^>]*>#i', ' ', strip_shortcodes( $src ) );
		$txt = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $src ), ENT_QUOTES, 'UTF-8' ) ) );
		return wp_trim_words( $txt, $words, '…' );
	}

	public static function icon_url( $slug ) {
		return $slug ? SDSC_URL . 'assets/img/3d/' . $slug . '.webp' : '';
	}

	public static function icon_list() {
		$out = array();
		foreach ( (array) glob( SDSC_DIR . 'assets/img/3d/*.webp' ) as $f ) { $out[] = basename( $f, '.webp' ); }
		return $out;
	}

	protected static function fix( $s ) {
		return class_exists( 'SDSC_WhyUs' ) && method_exists( 'SDSC_WhyUs', 'fix_name' ) ? SDSC_WhyUs::fix_name( $s ) : $s;
	}

	/* ------------------------------------------------------------------ front end */

	public static function assets() {
		if ( is_admin() ) { return; }
		// Loaded everywhere: the homepage section and Elementor-cached widgets use the same badges.
		wp_enqueue_style( 'sdsc-blog', SDSC_URL . 'assets/blog.css', array(), SDSC_VERSION );
		if ( is_singular( 'post' ) ) {
			wp_enqueue_script( 'sdsc-blog', SDSC_URL . 'assets/blog.js', array(), SDSC_VERSION, true );
		}
	}

	public static function body_class( $c ) {
		if ( self::is_hub() || is_category() ) { $c[] = 'sdb-hub-page'; }
		if ( is_singular( 'post' ) ) { $c[] = 'sdb-post-page'; }
		return $c;
	}

	public static function template( $t ) {
		if ( self::is_hub() || is_category() ) {
			return SDSC_DIR . 'templates/blog-hub.php';
		}
		if ( is_singular( 'post' ) ) {
			return SDSC_DIR . 'templates/blog-post.php';
		}
		return $t;
	}

	public static function shortcode() {
		return self::hub_html();
	}

	public static function badge( $tp, $link = true ) {
		if ( ! $tp ) { return ''; }
		$in = ( $tp['icon'] ? '<img src="' . esc_url( self::icon_url( $tp['icon'] ) ) . '" alt="" width="20" height="20" loading="lazy">' : '' ) . '<span>' . esc_html( $tp['name'] ) . '</span>';
		$st = ' style="--tc:' . esc_attr( $tp['color'] ) . '"';
		return $link
			? '<a class="sdb-badge" href="' . esc_url( $tp['url'] ) . '"' . $st . '>' . $in . '</a>'
			: '<span class="sdb-badge"' . $st . '>' . $in . '</span>';
	}

	protected static function level_html( $id ) {
		$l = get_post_meta( $id, '_sdsc_level', true );
		$all = self::levels();
		return isset( $all[ $l ] ) ? '<span class="sdb-level sdb-level--' . esc_attr( $l ) . '"><i aria-hidden="true"></i>' . esc_html( $all[ $l ] ) . '</span>' : '';
	}

	/** One article card. $big = featured layout. */
	public static function card( $id, $big = false, $heading = 'h3' ) {
		$tp   = self::post_topic( $id );
		$t    = self::fix( get_the_title( $id ) );
		$url  = get_permalink( $id );
		$ex   = self::fix( self::excerpt( $id, $big ? 40 : 22 ) );
		$img  = has_post_thumbnail( $id ) ? get_the_post_thumbnail_url( $id, $big ? 'large' : 'medium_large' ) : '';
		$lang = get_post_meta( $id, '_sdsc_lang', true );
		$h  = '<article class="sdb-card' . ( $big ? ' sdb-card--big' : '' ) . '"' . ( $tp ? ' style="--tc:' . esc_attr( $tp['color'] ) . '"' : '' ) . ( 'es' === $lang ? ' lang="es"' : '' ) . '>';
		$h .= '<a class="sdb-card-img" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true"' . ( $img ? ' style="--bg:url(' . esc_url( $img ) . ')"' : '' ) . '>';
		$h .= $img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy">' : '<span class="sdb-card-ph">' . ( $tp && $tp['icon'] ? '<img src="' . esc_url( self::icon_url( $tp['icon'] ) ) . '" alt="">' : '' ) . '</span>';
		$h .= '</a><div class="sdb-card-body">';
		$h .= '<div class="sdb-card-top">' . self::badge( $tp ) . ( 'es' === $lang ? '<span class="sdb-lang">ES</span>' : ( 'both' === $lang ? '<span class="sdb-lang">EN · ES</span>' : '' ) ) . '</div>';
		$h .= '<' . $heading . ' class="sdb-card-title"><a href="' . esc_url( $url ) . '">' . esc_html( $t ) . '</a></' . $heading . '>';
		$h .= '<p class="sdb-card-ex">' . esc_html( $ex ) . '</p>';
		$h .= '<div class="sdb-card-meta"><time datetime="' . esc_attr( get_the_date( 'c', $id ) ) . '">' . esc_html( get_the_date( 'M j, Y', $id ) ) . '</time><span>' . self::minutes( $id ) . ' min read</span>' . self::level_html( $id ) . '</div>';
		$h .= '</div></article>';
		return $h;
	}

	/** Hub (all topics) or a topic page, depending on the current request. */
	public static function hub_html() {
		$cur  = is_category() ? self::topic( get_queried_object() ) : null;
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$pg   = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
		$base = $cur ? $cur['url'] : self::url();

		$args = array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => self::PER, 'paged' => $pg, 'ignore_sticky_posts' => true );
		if ( $cur ) { $args['cat'] = $cur['id']; }
		if ( '' !== $q ) { $args['s'] = $q; }

		// Featured article on the first page of the hub (marked in "Article details", else the newest).
		$feat = 0;
		if ( ! $cur && '' === $q && 1 === $pg ) {
			$f = get_posts( array( 'post_type' => 'post', 'numberposts' => 1, 'meta_key' => '_sdsc_featured', 'meta_value' => '1', 'fields' => 'ids' ) );
			$feat = $f ? (int) $f[0] : (int) ( get_posts( array( 'post_type' => 'post', 'numberposts' => 1, 'fields' => 'ids' ) )[0] ?? 0 );
			if ( $feat ) { $args['post__not_in'] = array( $feat ); }
		}
		$list   = new WP_Query( $args );
		$topics = self::all_topics( true );
		$total  = (int) wp_count_posts( 'post' )->publish;

		ob_start();
		?>
		<section class="sdb-hero"<?php echo $cur ? ' style="--tc:' . esc_attr( $cur['color'] ) . '"' : ''; ?>>
			<div class="sdb-wrap">
				<nav class="sdb-crumbs" aria-label="Breadcrumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a><span aria-hidden="true">›</span><?php if ( $cur ) : ?><a href="<?php echo esc_url( self::url() ); ?>">Driving Tips &amp; News</a><span aria-hidden="true">›</span><span aria-current="page"><?php echo esc_html( $cur['name'] ); ?></span><?php else : ?><span aria-current="page">Driving Tips &amp; News</span><?php endif; ?></nav>
				<?php if ( $cur && $cur['icon'] ) : ?><img class="sdb-hero-icon sd3d" src="<?php echo esc_url( self::icon_url( $cur['icon'] ) ); ?>" alt="" width="96" height="96"><?php endif; ?>
				<p class="sdb-kicker"><?php echo $cur ? 'Topic' : 'Learning centre'; ?></p>
				<h1 class="sdb-h1"><?php echo $cur ? esc_html( $cur['name'] ) : 'Driving Tips &amp; News'; ?></h1>
				<p class="sdb-lead"><?php echo $cur ? esc_html( $cur['desc'] ) : 'Permit test prep, road test skills, Maryland driving laws and safe-driving advice from Sam&rsquo;s Driving School in Rockville.'; ?></p>
				<form class="sdb-search" role="search" method="get" action="<?php echo esc_url( $base ); ?>">
					<label class="screen-reader-text" for="sdb-q">Search articles</label>
					<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="m20 20-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
					<input id="sdb-q" type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo $cur ? 'Search ' . esc_attr( $cur['name'] ) . '…' : 'Search tips, e.g. parallel parking'; ?>">
					<button type="submit">Search</button>
				</form>
			</div>
		</section>

		<div class="sdb-wrap sdb-body">
			<?php if ( $topics ) : ?>
			<nav class="sdb-chips" aria-label="Topics">
				<a class="sdb-chip<?php echo $cur ? '' : ' is-on'; ?>" href="<?php echo esc_url( self::url() ); ?>"<?php echo $cur ? '' : ' aria-current="page"'; ?>><span>All articles</span><b><?php echo (int) $total; ?></b></a>
				<?php foreach ( $topics as $t ) : $on = $cur && $cur['id'] === $t['id']; ?>
					<a class="sdb-chip<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo esc_url( $t['url'] ); ?>" style="--tc:<?php echo esc_attr( $t['color'] ); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><?php if ( $t['icon'] ) : ?><img src="<?php echo esc_url( self::icon_url( $t['icon'] ) ); ?>" alt="" width="22" height="22" loading="lazy"><?php endif; ?><span><?php echo esc_html( $t['name'] ); ?></span><b><?php echo (int) $t['count']; ?></b></a>
				<?php endforeach; ?>
			</nav>
			<?php endif; ?>

			<?php if ( '' !== $q ) : ?>
				<p class="sdb-result"><?php echo (int) $list->found_posts; ?> result<?php echo 1 === (int) $list->found_posts ? '' : 's'; ?> for <strong>&ldquo;<?php echo esc_html( $q ); ?>&rdquo;</strong> · <a href="<?php echo esc_url( $base ); ?>">Clear search</a></p>
			<?php endif; ?>

			<?php if ( $feat ) : ?>
				<div class="sdb-feature"><p class="sdb-label">Featured</p><?php echo self::card( $feat, true, 'h2' ); // phpcs:ignore ?></div>
			<?php endif; ?>

			<?php if ( $list->have_posts() ) : ?>
				<?php if ( $feat ) : ?><h2 class="sdb-h2">Latest articles</h2><?php endif; ?>
				<div class="sdb-grid">
					<?php foreach ( $list->posts as $p ) { echo self::card( $p->ID, false, $feat ? 'h3' : 'h2' ); } // phpcs:ignore ?>
				</div>
				<?php if ( $list->max_num_pages > 1 ) : ?>
					<nav class="sdb-pages" aria-label="Pages">
						<?php for ( $i = 1; $i <= $list->max_num_pages; $i++ ) :
							$u = add_query_arg( array_filter( array( 'pg' => $i > 1 ? $i : null, 'q' => '' !== $q ? $q : null ) ), $base ); ?>
							<a href="<?php echo esc_url( $u ); ?>"<?php echo $i === $pg ? ' aria-current="page" class="is-on"' : ''; ?>><?php echo (int) $i; ?></a>
						<?php endfor; ?>
					</nav>
				<?php endif; ?>
			<?php elseif ( ! $feat ) : ?>
				<div class="sdb-empty">
					<?php if ( $cur && $cur['icon'] ) : ?><img src="<?php echo esc_url( self::icon_url( $cur['icon'] ) ); ?>" alt="" width="72" height="72"><?php endif; ?>
					<h2><?php echo '' !== $q ? 'No articles match your search' : 'Articles on this topic are coming soon'; ?></h2>
					<p>Have a question about <?php echo $cur ? esc_html( strtolower( $cur['name'] ) ) : 'driving in Maryland'; ?>? Our instructors are happy to help.</p>
					<p><a class="sdb-btn" href="<?php echo esc_url( self::url() ); ?>">See all articles</a></p>
				</div>
			<?php endif; ?>
			<?php wp_reset_postdata(); ?>

			<?php
			$all = self::all_topics( false );
			if ( ! $cur && '' === $q && $all ) :
				?>
				<section class="sdb-topics" aria-labelledby="sdb-topics-h">
					<h2 class="sdb-h2" id="sdb-topics-h">Browse by topic</h2>
					<div class="sdb-topic-grid">
						<?php foreach ( $all as $t ) : ?>
							<a class="sdb-topic" href="<?php echo esc_url( $t['url'] ); ?>" style="--tc:<?php echo esc_attr( $t['color'] ); ?>">
								<?php if ( $t['icon'] ) : ?><img class="sd3d" src="<?php echo esc_url( self::icon_url( $t['icon'] ) ); ?>" alt="" width="56" height="56" loading="lazy"><?php endif; ?>
								<span class="sdb-topic-name"><?php echo esc_html( $t['name'] ); ?></span>
								<span class="sdb-topic-desc"><?php echo esc_html( $t['desc'] ); ?></span>
								<span class="sdb-topic-n"><?php echo $t['count'] ? (int) $t['count'] . ' article' . ( 1 === $t['count'] ? '' : 's' ) : 'Coming soon'; ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php echo self::cta(); // phpcs:ignore ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function cta() {
		$phone = class_exists( 'SDSC_Chrome' ) ? SDSC_Chrome::PHONE_MAIN : '(301) 726-3030';
		$tel   = 'tel:+1' . preg_replace( '/\D/', '', $phone );
		$book  = get_option( 'sdsc_blog_cta_url', home_url( '/contact/' ) );
		return '<aside class="sdb-cta"><div><p class="sdb-cta-k">Ready for the road?</p><p class="sdb-cta-h">Learn to drive with Sam&rsquo;s Driving School in Rockville.</p></div>'
			. '<div class="sdb-cta-b"><a class="sdb-btn sdb-btn--y" href="' . esc_url( $book ) . '">Contact us</a><a class="sdb-btn sdb-btn--o" href="' . esc_attr( $tel ) . '">Call ' . esc_html( $phone ) . '</a></div></aside>';
	}

	/** Article page parts, used by templates/blog-post.php. */
	public static function post_head( $id ) {
		$tp  = self::post_topic( $id );
		$mod = get_the_modified_date( 'U', $id ) - get_the_date( 'U', $id ) > DAY_IN_SECONDS;
		ob_start();
		?>
		<header class="sdb-hero sdb-hero--post"<?php echo $tp ? ' style="--tc:' . esc_attr( $tp['color'] ) . '"' : ''; ?>>
			<div class="sdb-wrap sdb-narrow">
				<nav class="sdb-crumbs" aria-label="Breadcrumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a><span aria-hidden="true">›</span><a href="<?php echo esc_url( self::url() ); ?>">Driving Tips &amp; News</a><?php if ( $tp ) : ?><span aria-hidden="true">›</span><a href="<?php echo esc_url( $tp['url'] ); ?>"><?php echo esc_html( $tp['name'] ); ?></a><?php endif; ?></nav>
				<div class="sdb-card-top"><?php echo self::badge( $tp ); // phpcs:ignore ?><?php echo self::level_html( $id ); // phpcs:ignore ?></div>
				<h1 class="sdb-h1"><?php echo esc_html( self::fix( get_the_title( $id ) ) ); ?></h1>
				<p class="sdb-post-meta">
					<span>By Sam&rsquo;s Driving School</span>
					<time datetime="<?php echo esc_attr( get_the_date( 'c', $id ) ); ?>"><?php echo esc_html( get_the_date( 'F j, Y', $id ) ); ?></time>
					<?php if ( $mod ) : ?><span>Updated <?php echo esc_html( get_the_modified_date( 'M j, Y', $id ) ); ?></span><?php endif; ?>
					<span><?php echo (int) self::minutes( $id ); ?> min read</span>
				</p>
			</div>
		</header>
		<?php
		return ob_get_clean();
	}

	public static function share( $id ) {
		$u = rawurlencode( get_permalink( $id ) );
		$t = rawurlencode( self::fix( get_the_title( $id ) ) );
		$i = array(
			'fb'   => '<path d="M14 8h3V4h-3c-2.8 0-4 1.7-4 4.3V10H7v4h3v8h4v-8h3l1-4h-4V8.6c0-.4.3-.6.6-.6z" fill="currentColor"/>',
			'x'    => '<path d="M4 4h4.5l4 5.6L17.4 4H20l-6.3 7.3L20.5 20H16l-4.3-6-5.3 6H4l7-8z" fill="currentColor"/>',
			'mail' => '<rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="m4 7 8 6 8-6" fill="none" stroke="currentColor" stroke-width="2"/>',
			'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
		);
		$svg = function ( $k ) use ( $i ) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $i[ $k ] . '</svg>'; };
		return '<div class="sdb-share"><span>Share</span>'
			. '<a href="https://www.facebook.com/sharer/sharer.php?u=' . $u . '" target="_blank" rel="noopener" aria-label="Share on Facebook">' . $svg( 'fb' ) . '</a>'
			. '<a href="https://twitter.com/intent/tweet?url=' . $u . '&amp;text=' . $t . '" target="_blank" rel="noopener" aria-label="Share on X">' . $svg( 'x' ) . '</a>'
			. '<a href="mailto:?subject=' . $t . '&amp;body=' . $u . '" aria-label="Share by email">' . $svg( 'mail' ) . '</a>'
			. '<button type="button" data-sdb-copy="' . esc_attr( get_permalink( $id ) ) . '" aria-label="Copy link">' . $svg( 'link' ) . '<em>Copied</em></button></div>';
	}

	public static function related( $id ) {
		$tp   = self::post_topic( $id );
		$args = array( 'post_type' => 'post', 'numberposts' => 3, 'post__not_in' => array( $id ), 'fields' => 'ids' );
		$ids  = $tp ? get_posts( $args + array( 'cat' => $tp['id'] ) ) : array();
		if ( count( $ids ) < 3 ) {
			$more = get_posts( array( 'post_type' => 'post', 'numberposts' => 3 - count( $ids ), 'post__not_in' => array_merge( array( $id ), $ids ), 'fields' => 'ids' ) );
			$ids  = array_merge( $ids, $more );
		}
		if ( ! $ids ) { return ''; }
		$h = '<section class="sdb-related" aria-labelledby="sdb-rel-h"><h2 class="sdb-h2" id="sdb-rel-h">Keep reading</h2><div class="sdb-grid">';
		foreach ( $ids as $r ) { $h .= self::card( $r ); }
		return $h . '</div></section>';
	}

	public static function prev_next() {
		$p = get_previous_post();
		$n = get_next_post();
		if ( ! $p && ! $n ) { return ''; }
		$h = '<nav class="sdb-pn" aria-label="More articles">';
		$h .= $p ? '<a class="sdb-pn-p" href="' . esc_url( get_permalink( $p ) ) . '"><small>← Previous</small><span>' . esc_html( self::fix( get_the_title( $p ) ) ) . '</span></a>' : '<span></span>';
		$h .= $n ? '<a class="sdb-pn-n" href="' . esc_url( get_permalink( $n ) ) . '"><small>Next →</small><span>' . esc_html( self::fix( get_the_title( $n ) ) ) . '</span></a>' : '<span></span>';
		return $h . '</nav>';
	}

	/* ------------------------------------------------------------------ admin */

	public static function meta_box() {
		add_meta_box( 'sdsc-article', 'Article details', array( __CLASS__, 'meta_box_html' ), 'post', 'side', 'high' );
	}

	public static function meta_box_html( $post ) {
		wp_nonce_field( 'sdsc_article', 'sdsc_article_nonce' );
		$l = get_post_meta( $post->ID, '_sdsc_level', true );
		$g = get_post_meta( $post->ID, '_sdsc_lang', true );
		$f = get_post_meta( $post->ID, '_sdsc_featured', true );
		echo '<p><label for="sdsc_level"><strong>Level</strong></label><br><select id="sdsc_level" name="sdsc_level" style="width:100%"><option value="">— Not shown —</option>';
		foreach ( self::levels() as $k => $v ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $l, $k, false ) . '>' . esc_html( $v ) . '</option>'; }
		echo '</select></p><p><label for="sdsc_lang"><strong>Language</strong></label><br><select id="sdsc_lang" name="sdsc_lang" style="width:100%">';
		foreach ( array( '' => 'English', 'es' => 'Español', 'both' => 'English + Español' ) as $k => $v ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $g, $k, false ) . '>' . esc_html( $v ) . '</option>'; }
		echo '</select></p><p><label><input type="checkbox" name="sdsc_featured" value="1"' . checked( $f, '1', false ) . '> Feature on the Driving Tips page</label></p>';
		echo '<p class="description">Pick one topic under <em>Categories</em>. Reading time is calculated automatically.</p>';
	}

	public static function save_meta( $id, $post ) {
		if ( ! isset( $_POST['sdsc_article_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sdsc_article_nonce'] ), 'sdsc_article' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $id ) ) { return; }
		$l = sanitize_key( $_POST['sdsc_level'] ?? '' );
		$g = sanitize_key( $_POST['sdsc_lang'] ?? '' );
		isset( self::levels()[ $l ] ) ? update_post_meta( $id, '_sdsc_level', $l ) : delete_post_meta( $id, '_sdsc_level' );
		in_array( $g, array( 'es', 'both' ), true ) ? update_post_meta( $id, '_sdsc_lang', $g ) : delete_post_meta( $id, '_sdsc_lang' );
		if ( ! empty( $_POST['sdsc_featured'] ) ) {
			// Only one featured article at a time.
			foreach ( get_posts( array( 'post_type' => 'post', 'numberposts' => -1, 'post_status' => 'any', 'meta_key' => '_sdsc_featured', 'fields' => 'ids' ) ) as $o ) {
				if ( $o !== $id ) { delete_post_meta( $o, '_sdsc_featured' ); }
			}
			update_post_meta( $id, '_sdsc_featured', '1' );
		} else {
			delete_post_meta( $id, '_sdsc_featured' );
		}
	}

	protected static function icon_select( $cur ) {
		$h = '<select name="sdsc_icon" id="sdsc_icon"><option value="">— None —</option>';
		foreach ( self::icon_list() as $i ) { $h .= '<option value="' . esc_attr( $i ) . '"' . selected( $cur, $i, false ) . '>' . esc_html( ucwords( str_replace( '_', ' ', $i ) ) ) . '</option>'; }
		return $h . '</select>';
	}

	public static function term_fields_add() {
		wp_nonce_field( 'sdsc_term', 'sdsc_term_nonce' );
		echo '<div class="form-field"><label for="sdsc_color">Topic colour</label><input type="color" id="sdsc_color" name="sdsc_color" value="#00703c"></div>';
		echo '<div class="form-field"><label for="sdsc_icon">3D icon</label>' . self::icon_select( '' ) . '</div>'; // phpcs:ignore
		echo '<div class="form-field"><label for="sdsc_order">Order on the site</label><input type="number" id="sdsc_order" name="sdsc_order" value="20" min="0" max="99"></div>';
	}

	public static function term_fields_edit( $term ) {
		wp_nonce_field( 'sdsc_term', 'sdsc_term_nonce' );
		$c = get_term_meta( $term->term_id, 'sdsc_color', true ) ?: '#00703c';
		$i = get_term_meta( $term->term_id, 'sdsc_icon', true );
		$o = get_term_meta( $term->term_id, 'sdsc_order', true );
		echo '<tr class="form-field"><th scope="row"><label for="sdsc_color">Topic colour</label></th><td><input type="color" id="sdsc_color" name="sdsc_color" value="' . esc_attr( $c ) . '"></td></tr>';
		echo '<tr class="form-field"><th scope="row"><label for="sdsc_icon">3D icon</label></th><td>' . self::icon_select( $i ) . ( $i ? ' <img src="' . esc_url( self::icon_url( $i ) ) . '" alt="" width="32" height="32" style="vertical-align:middle">' : '' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr class="form-field"><th scope="row"><label for="sdsc_order">Order on the site</label></th><td><input type="number" id="sdsc_order" name="sdsc_order" value="' . esc_attr( '' === $o ? 20 : $o ) . '" min="0" max="99" style="width:90px"></td></tr>';
	}

	public static function save_term( $id ) {
		if ( ! isset( $_POST['sdsc_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sdsc_term_nonce'] ), 'sdsc_term' ) ) { return; }
		if ( ! current_user_can( 'manage_categories' ) ) { return; }
		$c = sanitize_hex_color( wp_unslash( $_POST['sdsc_color'] ?? '' ) );
		$i = sanitize_key( $_POST['sdsc_icon'] ?? '' );
		if ( $c ) { update_term_meta( $id, 'sdsc_color', $c ); }
		in_array( $i, self::icon_list(), true ) ? update_term_meta( $id, 'sdsc_icon', $i ) : delete_term_meta( $id, 'sdsc_icon' );
		update_term_meta( $id, 'sdsc_order', max( 0, min( 99, (int) ( $_POST['sdsc_order'] ?? 20 ) ) ) );
	}

	public static function term_cols( $c ) {
		$new = array();
		foreach ( $c as $k => $v ) {
			if ( 'name' === $k ) { $new['sdsc_look'] = ''; }
			$new[ $k ] = $v;
		}
		return $new;
	}

	public static function term_col( $out, $col, $id ) {
		if ( 'sdsc_look' !== $col ) { return $out; }
		$c = get_term_meta( $id, 'sdsc_color', true );
		$i = get_term_meta( $id, 'sdsc_icon', true );
		return '<span style="display:inline-grid;place-items:center;width:34px;height:34px;border-radius:9px;background:' . esc_attr( $c ?: '#ccc' ) . '22;box-shadow:inset 0 0 0 2px ' . esc_attr( $c ?: '#ccc' ) . '">' . ( $i ? '<img src="' . esc_url( self::icon_url( $i ) ) . '" alt="" width="24" height="24">' : '' ) . '</span>';
	}

	public static function post_cols( $c ) {
		$c['sdsc_level'] = 'Level';
		return $c;
	}

	public static function post_col( $col, $id ) {
		if ( 'sdsc_level' !== $col ) { return; }
		$l = get_post_meta( $id, '_sdsc_level', true );
		echo esc_html( self::levels()[ $l ] ?? '—' );
		if ( get_post_meta( $id, '_sdsc_featured', true ) ) { echo ' <span title="Featured" style="color:#d4a000">★</span>'; }
	}
}
