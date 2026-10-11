<?php
/** Tutor LMS dashboard → "My driving lessons": the student's BTW lessons, fees (due, paid, waived) and refunds. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo '<div class="tutor-dashboard-content-inner">' . SDSC_Lessons::shortcode() . '</div>'; // phpcs:ignore -- built from escaped parts
