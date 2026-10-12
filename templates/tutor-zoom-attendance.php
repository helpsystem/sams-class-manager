<?php
/** Tutor LMS dashboard → "Zoom attendance": the instructor / office roll-call checklist for Zoom classes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo '<div class="tutor-dashboard-content-inner">' . SDSC_ZoomRoll::render() . '</div>'; // phpcs:ignore -- built from escaped parts
