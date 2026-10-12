<?php
/** Tutor LMS dashboard → "Messages": conversations with the school, the office or a driving instructor. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="tutor-dashboard-content-inner">
	<div id="sdsc-chat-page" class="sdk-page" data-thread="<?php echo isset( $_GET['t'] ) ? (int) $_GET['t'] : 0; // phpcs:ignore ?>">
		<p class="sdk-loading">Loading messages…</p>
	</div>
</div>
