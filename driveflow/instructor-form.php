<?php
defined('ABSPATH') || exit;
/**
 * DriveFlow Pro — Instructor in-car lesson app.
 *
 * When Sam's Class Manager is active it draws the app (lessons, student record across instructors,
 * evaluation, car check, signatures, notifications, messages). Otherwise the classic form is used.
 * Variables from DriveFlow_Pro::instructor_form(): $brand, $instructors, $vehicles, $students,
 * $today_sessions, $current_instructor, $locked, $df_nonce.
 */
if (class_exists('SDSC_CarApp')) {
    echo SDSC_CarApp::render(get_defined_vars()); // phpcs:ignore -- markup built from escaped parts
    return;
}
include __DIR__ . '/instructor-form-classic.php';
