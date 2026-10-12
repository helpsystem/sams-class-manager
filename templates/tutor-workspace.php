<?php
/** Tutor LMS dashboard → Workspace: a management screen (DriveFlow or Class Manager) inside the dashboard. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo '<div class="tutor-dashboard-content-inner">' . SDSC_Workspace::render() . '</div>'; // phpcs:ignore -- built from escaped parts
