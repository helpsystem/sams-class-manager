<?php
defined('ABSPATH') || exit;
/**
 * DriveFlow Pro — Lobby TV board.
 * When Sam's Class Manager is active it draws the TV (states worked out from the clock, short student
 * names, cars with fuel, instructors, office messages). Otherwise the classic board is used.
 */
if (class_exists('SDSC_TV')) {
    echo SDSC_TV::render(); // phpcs:ignore -- markup built from escaped parts
    return;
}
include __DIR__ . '/tv-board-classic.php';
