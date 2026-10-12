<?php
/** Tutor LMS dashboard → "Reports": rosters, attendance, registrations, payments, lessons and fees, with print and CSV. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo '<div class="tutor-dashboard-content-inner">' . SDSC_Reports::render() . '</div>'; // phpcs:ignore -- built from escaped parts
