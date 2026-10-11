<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Brings the remaining theme areas in line with the new design:
 *  - inner-page title bars (Policies, Car Rentals, About, Contact …) become a Maryland night-road banner
 *  - plain Elementor text / icon / image boxes on those pages become cards
 *  - the homepage "Latest News" carousel is redrawn as road-sign cards
 *  - the Google reviews widget gets a matching section heading
 * Everything is CSS or a re-render of existing content; nothing in the database is changed.
 * Turn off with option sdsc_pages = 0.
 */
class SDSC_Pages {

	public static function on() {
		return '0' !== get_option( 'sdsc_pages', '1' );
	}

	public static function init() {
		if ( ! self::on() ) { return; }
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	public static function assets() {
		if ( is_admin() ) { return; }
		wp_enqueue_style( 'sdsc-pages', SDSC_URL . 'assets/pages.css', array( 'sdsc-fonts' ), SDSC_VERSION );
	}

	public static function body_class( $c ) {
		$c[] = 'sdp';
		return $c;
	}

	/** Homepage "Latest News": three newest posts as cards. */
	public static function blog( $html ) {
		$q = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 3, 'ignore_sticky_posts' => true, 'no_found_rows' => true ) );
		if ( ! $q->have_posts() ) { return ''; }
		$fix = class_exists( 'SDSC_WhyUs' ) ? array( 'SDSC_WhyUs', 'fix_name' ) : 'trim';
		ob_start();
		?>
		<section class="sdp-blog sdsc3d" aria-labelledby="sdp-blog-h">
			<div class="sdp-blog-head">
				<div class="sdsc3d-head">
					<p class="sdsc3d-kicker">News &amp; driving tips</p>
					<h2 id="sdp-blog-h">From the driver&rsquo;s seat</h2>
					<p>School news and practical tips for Maryland learners, written by our instructors.</p>
				</div>
				<?php $blog = class_exists( 'SDSC_Blog' ) && SDSC_Blog::on() ? SDSC_Blog::url() : ( get_option( 'page_for_posts' ) ? get_permalink( get_option( 'page_for_posts' ) ) : '' ); ?>
				<?php if ( $blog ) : ?><a class="sdp-btn sdp-btn--ghost" href="<?php echo esc_url( $blog ); ?>">All articles</a><?php endif; ?>
			</div>
			<?php if ( class_exists( 'SDSC_Blog' ) && SDSC_Blog::on() && ( $sdb_t = SDSC_Blog::all_topics( true ) ) ) : ?>
				<nav class="sdb-chips" aria-label="Topics">
					<?php foreach ( $sdb_t as $t ) : ?><a class="sdb-chip" href="<?php echo esc_url( $t['url'] ); ?>" style="--tc:<?php echo esc_attr( $t['color'] ); ?>"><?php if ( $t['icon'] ) : ?><img src="<?php echo esc_url( SDSC_Blog::icon_url( $t['icon'] ) ); ?>" alt="" width="22" height="22" loading="lazy"><?php endif; ?><span><?php echo esc_html( $t['name'] ); ?></span><b><?php echo (int) $t['count']; ?></b></a><?php endforeach; ?>
				</nav>
			<?php endif; ?>
			<ul class="sdp-blog-grid">
				<?php
				while ( $q->have_posts() ) :
					$q->the_post();
					$t = call_user_func( $fix, get_the_title() );
					?>
					<?php $sdb_tp = class_exists( 'SDSC_Blog' ) ? SDSC_Blog::post_topic( get_the_ID() ) : null; ?>
					<li class="sdp-post"<?php echo $sdb_tp ? ' style="--tc:' . esc_attr( $sdb_tp['color'] ) . '"' : ''; ?>>
						<a class="sdp-post-img" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"<?php $bg = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'medium' ) : ''; echo $bg ? ' style="--sdp-bg:url(' . esc_url( $bg ) . ')"' : ''; ?>>
							<?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy', 'alt' => '' ) ); } else { echo '<span class="sdp-post-ph"></span>'; } ?>
							<span class="sdp-post-date"><?php echo esc_html( get_the_date( 'M j, Y' ) ); ?></span>
						</a>
						<div class="sdp-post-body">
							<?php if ( $sdb_tp ) : ?><div class="sdp-post-tags"><?php echo SDSC_Blog::badge( $sdb_tp ); // phpcs:ignore ?><span class="sdp-post-meta"><?php echo (int) SDSC_Blog::minutes( get_the_ID() ); ?> min read</span></div><?php endif; ?>
							<h3><a href="<?php the_permalink(); ?>"><?php echo esc_html( $t ); ?></a></h3>
							<p><?php echo esc_html( call_user_func( $fix, wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 22, '…' ) ) ); ?></p>
							<a class="sdp-more" href="<?php the_permalink(); ?>">Read article<span aria-hidden="true"> &rarr;</span></a>
						</div>
					</li>
				<?php endwhile; wp_reset_postdata(); ?>
			</ul>
		</section>
		<?php
		return ob_get_clean();
	}

	/** Google reviews widget: same heading style as the other sections, widget kept as is. */
	public static function reviews( $html ) {
		$g = class_exists( 'SDSC_WhyUs' ) ? SDSC_WhyUs::google() : null;
		$h  = '<section class="sdp-reviews sdsc3d" aria-labelledby="sdp-rev-h"><div class="sdsc3d-head">';
		$h .= '<p class="sdsc3d-kicker">Student reviews</p><h2 id="sdp-rev-h">What our students say</h2>';
		if ( $g ) { $h .= '<p>Rated ' . esc_html( $g['score'] ) . ' out of 5 on Google from ' . esc_html( $g['count'] ) . ' reviews.</p>'; }
		$h .= '</div><div class="sdp-reviews-box">' . $html . '</div></section>';
		return $h;
	}
}
