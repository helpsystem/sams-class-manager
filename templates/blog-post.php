<?php
/** Single article (Sam's Class Manager). The content itself still comes from the editor / Elementor. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>
<main id="main" class="site-main sdb-main">
<?php
while ( have_posts() ) :
	the_post();
	$sdb_id   = get_the_ID();
	$sdb_lang = get_post_meta( $sdb_id, '_sdsc_lang', true );
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'sdb-article' ); ?><?php echo 'es' === $sdb_lang ? ' lang="es"' : ''; ?>>
		<?php echo SDSC_Blog::post_head( $sdb_id ); // phpcs:ignore ?>
		<?php if ( has_post_thumbnail() ) : ?>
			<figure class="sdb-cover sdb-narrow" style="--bg:url(<?php echo esc_url( get_the_post_thumbnail_url( $sdb_id, 'large' ) ); ?>)">
				<?php the_post_thumbnail( 'large', array( 'alt' => esc_attr( get_the_title() ), 'fetchpriority' => 'high' ) ); ?>
			</figure>
		<?php endif; ?>
		<div class="sdb-wrap sdb-narrow">
			<div class="sdb-content entry-content">
				<?php
				the_content();
				wp_link_pages();
				?>
			</div>
			<?php echo SDSC_Blog::share( $sdb_id ); // phpcs:ignore ?>
			<?php echo SDSC_Blog::prev_next(); // phpcs:ignore ?>
		</div>
	</article>
	<div class="sdb-wrap">
		<?php echo SDSC_Blog::cta(); // phpcs:ignore ?>
		<?php echo SDSC_Blog::related( $sdb_id ); // phpcs:ignore ?>
		<?php if ( comments_open() || get_comments_number() ) { echo '<div class="sdb-narrow sdb-comments">'; comments_template(); echo '</div>'; } ?>
	</div>
<?php endwhile; ?>
</main>
<?php
get_footer();
