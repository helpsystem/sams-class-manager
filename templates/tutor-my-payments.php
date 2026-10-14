<?php
/** Tutor LMS dashboard → "My payments": online, office (cash / check) payments and refunds. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo SDSC_Student::payments_html(); // phpcs:ignore -- built from escaped parts
