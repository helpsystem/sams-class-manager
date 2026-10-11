<?php
/** Driving Tips & News hub and topic pages (Sam's Class Manager). */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>
<main id="main" class="site-main sdb-main">
	<?php echo SDSC_Blog::hub_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</main>
<?php
get_footer();
