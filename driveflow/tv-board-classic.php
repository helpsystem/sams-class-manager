<?php
defined('ABSPATH') || exit;

/**
 * DriveFlow Pro — Ultra-Premium Smart Lobby TV Display
 * Version 2.5 — Pixel-perfect recreation of mockup tv_board_premium_mockup_1789740811476.jpg
 * 100% standalone, dedicated internal navigation menu, zero theme header/footer.
 */
$session_count = !empty($today_sessions) ? count($today_sessions) : 0;
$instructor_app_url = !empty($instructor_app_url) ? $instructor_app_url : (!empty($settings['instructor_page_id']) ? get_permalink($settings['instructor_page_id']) : site_url('/instructor-session/'));
$admin_hub_url = !empty($admin_hub_url) ? $admin_hub_url : admin_url('admin.php?page=driveflow-pro-hub');
?>
<div class="dfv2-tv-wrap" data-driveflow-board dir="ltr">

    <!-- Animated subtle starfield canvas background -->
    <canvas class="dfv2-starfield" aria-hidden="true"></canvas>

    <!-- ══════════════════ BROADCAST ALERT BANNER (CONTROLLED BY ADMIN) ══════════════════ -->
    <aside class="dfv2-broadcast-banner" id="dfv2-broadcast-banner" style="display:none;" role="alert">
        <div class="dfv2-broadcast-pulse"></div>
        <div class="dfv2-broadcast-icon-box">
            <span class="dfv2-broadcast-icon">⚠️</span>
        </div>
        <div class="dfv2-broadcast-body">
            <div class="dfv2-broadcast-tag">DIRECT ADMIN BROADCAST</div>
            <div class="dfv2-broadcast-text" id="dfv2-broadcast-text"></div>
        </div>
        <button type="button" class="dfv2-broadcast-close" id="dfv2-broadcast-close" title="Dismiss Alert">&times;</button>
    </aside>

    <!-- ══════════════════ 1. HEADER (MATCHING MOCKUP) ══════════════════ -->
    <header class="dfv2-header">

        <!-- Left: Brand -->
        <div class="dfv2-brand">
            <div class="dfv2-logo-icon" aria-hidden="true">🎓</div>
            <div class="dfv2-brand-text">
                <strong class="dfv2-school-name"><?php echo esc_html(!empty($brand['name']) ? $brand['name'] : "SAM'S DRIVING SCHOOL LLC"); ?></strong>
                <span class="dfv2-school-sub">Smart Lobby TV &bull; Live Dispatch Terminal</span>
            </div>
        </div>

        <!-- Center: Live Badge + Sleek Perspective Switcher -->
        <div class="dfv2-center-badge">
            <div class="dfv2-live-pill">
                <span class="dfv2-pulse-dot"></span>
                <span class="dfv2-live-text">LIVE LOBBY BOARD</span>
            </div>
            <div class="dfv2-header-view-switcher" role="group" aria-label="TV Display Perspective">
                <button type="button" class="dfv2-mode-btn is-active" data-tv-view="slots" title="View 2-Hour Standard Lesson Blocks">⏱ 2-Hour Slots</button>
                <button type="button" class="dfv2-mode-btn" data-tv-view="vehicles" title="View by Maryland Fleet Training Vehicles">🚗 Vehicles</button>
                <button type="button" class="dfv2-mode-btn" data-tv-view="instructors" title="View by Certified Driving Instructors">👨‍🏫 Instructors</button>
                <button type="button" class="dfv2-mode-btn dfv2-refresh-quick-btn" data-btn-refresh title="Force Live Schedule Sync">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                </button>
            </div>
        </div>

        <!-- Right: Yellow Digital Clock + Controls -->
        <div class="dfv2-header-right">
            <div class="dfv2-clock-block">
                <time data-board-clock class="dfv2-clock-time">10:47:32 AM</time>
                <div data-board-date class="dfv2-clock-date"><?php echo esc_html(date_i18n('l, F j, Y')); ?></div>
            </div>
            <div class="dfv2-controls">
                <button type="button" class="dfv2-ctrl-btn" data-toggle-speech title="Toggle Voice Announcer">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                </button>
                <button type="button" class="dfv2-ctrl-btn is-on" data-toggle-sound title="Toggle Chime Alert">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </button>
                <button type="button" class="dfv2-ctrl-btn" data-toggle-fullscreen title="Fullscreen Mode (F)">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                </button>
            </div>
        </div>
    </header>

    <!-- Hidden message notification banner -->
    <p class="dfv2-board-message" data-board-message style="display:none;"></p>

    <!-- ══════════════════ 2. SESSIONS GRID (MATCHING MOCKUP) ══════════════════ -->
    <main class="dfv2-sessions-wrap">
        <div class="dfv2-grid" data-session-list>
            <?php if (!empty($today_sessions)) : ?>
                <?php foreach ($today_sessions as $s) :
                    $is_active    = ('active' === $s['status']);
                    $is_completed = ('completed' === $s['status']);
                    $card_mod     = $is_active ? 'dfv2-card--active' : ($is_completed ? 'dfv2-card--completed' : 'dfv2-card--upcoming');
                    $status_class = $is_active ? 'status-active' : ($is_completed ? 'status-completed' : 'status-upcoming');
                    $status_icon  = $is_active ? '●' : ($is_completed ? '✓' : '●');
                    $status_text  = $is_active ? 'ACTIVE · ON ROAD' : ($is_completed ? 'COMPLETED' : 'UPCOMING NEXT');
                    $plate        = !empty($s['plate_number']) ? strtoupper($s['plate_number']) : '8WD 4931';
                    $start_time   = !empty($s['scheduled_start']) ? date_i18n('g:i A', strtotime($s['scheduled_start'])) : '9:00 AM';
                    $end_time     = !empty($s['scheduled_end']) ? date_i18n('g:i A', strtotime($s['scheduled_end'])) : '11:00 AM';
                    $student      = !empty($s['student_name']) ? $s['student_name'] : 'Enrolled Student';
                    $instructor   = !empty($s['instructor_name']) ? $s['instructor_name'] : 'Assigned Instructor';
                    $lesson_num   = !empty($s['session_number']) ? $s['session_number'] : 5;
                    $topic        = !empty($s['lesson_topic']) ? $s['lesson_topic'] : 'Highway Merging';
                    $fuel_val     = isset($s['fuel']) ? max(5, min(100, (int)$s['fuel'])) : 75;
                    $is_low       = ($fuel_val <= 25);
                    $fuel_color   = $is_low ? '#ff3b57' : ($fuel_val <= 50 ? '#ffd60a' : '#22ff88');
                    $lit_bars     = $fuel_val > 0 ? max(1, (int)round($fuel_val / 10)) : 0;
                ?>
                <article class="dfv2-card <?php echo esc_attr($card_mod); ?>">
                    <!-- Card Top: Plate + Fuel Gauge + Status -->
                    <div class="dfv2-card-top">
                        <div class="dfv2-card-top-row">
                            <div class="maryland-plate" data-plate="<?php echo esc_attr($plate); ?>" role="button" tabindex="0" title="Maryland Registration: <?php echo esc_attr($plate); ?>">
                                <span class="plate-bolt bolt-tl"></span>
                                <span class="plate-bolt bolt-tr"></span>
                                <span class="plate-number"><?php echo esc_html($plate); ?></span>
                                <span class="plate-bolt bolt-bl"></span>
                                <span class="plate-bolt bolt-br"></span>
                                <span class="plate-shine"></span>
                            </div>
                            <div class="lb-fuel <?php echo ($is_low ? 'low' : ''); ?>" style="--f: <?php echo esc_attr($fuel_color); ?>;" role="meter" aria-label="Fuel level" aria-valuenow="<?php echo esc_attr($fuel_val); ?>" title="Vehicle Fuel: <?php echo esc_attr($fuel_val); ?>%">
                                <div class="lb-fuel-top">
                                    <svg viewBox="0 0 24 24" class="lb-fuel-icon" fill="currentColor" width="14" height="14"><path d="M19.77 7.23l.01-.01-3.72-3.72L15 4.56l2.11 2.11c-.94.36-1.61 1.26-1.61 2.33 0 1.38 1.12 2.5 2.5 2.5.36 0 .69-.08 1-.21v7.21c0 .55-.45 1-1 1s-1-.45-1-1V14c0-1.1-.9-2-2-2h-1V5c0-1.1-.9-2-2-2H6c-1.1 0-2 .9-2 2v16h10v-7.5h1.5v5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V9c0-.69-.28-1.32-.73-1.77zM12 10H6V5h6v5zm6 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/></svg>
                                    <span class="lb-fuel-pct"><?php echo esc_html($fuel_val); ?>%</span>
                                </div>
                                <div class="lb-fuel-grid">
                                    <?php for ($bi = 0; $bi < 10; $bi++): ?>
                                        <i class="lb-fuel-seg <?php echo ($bi < $lit_bars) ? 'on' : ''; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <div class="lb-fuel-labels">
                                    <span>E</span>
                                    <span><?php echo ($is_low ? 'LOW FUEL' : 'FUEL'); ?></span>
                                    <span>F</span>
                                </div>
                            </div>
                        </div>
                        <div class="dfv2-status-badge <?php echo esc_attr($status_class); ?>">
                            <?php if ($is_active) : ?><span class="dfv2-dot-pulse"></span><?php endif; ?>
                            <span><?php echo esc_html($status_icon . ' ' . $status_text); ?></span>
                        </div>
                    </div>

                    <!-- Card Mid: Graduation Icon + Large Centered Student Name + Pills -->
                    <div class="dfv2-card-mid">
                        <div class="dfv2-card-cap-icon" aria-hidden="true">🎓</div>
                        <h3 class="dfv2-student-name"><?php echo esc_html($student); ?></h3>
                        <div class="dfv2-pills-row">
                            <span class="dfv2-pill dfv2-pill-instructor">
                                👨‍🏫 <?php echo esc_html($instructor); ?>
                            </span>
                            <span class="dfv2-pill dfv2-pill-lesson">
                                🚙 Lesson <?php echo esc_html($lesson_num); ?> &bull; <?php echo esc_html($topic); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Card Footer: Highlighted Time Box -->
                    <div class="dfv2-card-time">
                        <span class="dfv2-time-clock-icon">🕐</span>
                        <span class="dfv2-time-text"><?php echo esc_html($start_time . ' – ' . $end_time); ?></span>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php else : ?>
                <!-- Standby Demo Cards matching Marcus Johnson (62%), Sarah Chen (78%), David Kim (34%) -->
                <article class="dfv2-card dfv2-card--active">
                    <div class="dfv2-card-top">
                        <div class="dfv2-card-top-row">
                            <div class="maryland-plate" data-plate="8WD 4931">
                                <span class="plate-bolt bolt-tl"></span><span class="plate-bolt bolt-tr"></span>
                                <span class="plate-number">8WD 4931</span>
                                <span class="plate-bolt bolt-bl"></span><span class="plate-bolt bolt-br"></span>
                                <span class="plate-shine"></span>
                            </div>
                            <div class="lb-fuel" style="--f: #22ff88;" role="meter" aria-label="Fuel level" aria-valuenow="62" title="Vehicle Fuel: 62%">
                                <div class="lb-fuel-top">
                                    <svg viewBox="0 0 24 24" class="lb-fuel-icon" fill="currentColor" width="14" height="14"><path d="M19.77 7.23l.01-.01-3.72-3.72L15 4.56l2.11 2.11c-.94.36-1.61 1.26-1.61 2.33 0 1.38 1.12 2.5 2.5 2.5.36 0 .69-.08 1-.21v7.21c0 .55-.45 1-1 1s-1-.45-1-1V14c0-1.1-.9-2-2-2h-1V5c0-1.1-.9-2-2-2H6c-1.1 0-2 .9-2 2v16h10v-7.5h1.5v5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V9c0-.69-.28-1.32-.73-1.77zM12 10H6V5h6v5zm6 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/></svg>
                                    <span class="lb-fuel-pct">62%</span>
                                </div>
                                <div class="lb-fuel-grid">
                                    <i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i>
                                </div>
                                <div class="lb-fuel-labels"><span>E</span><span>FUEL</span><span>F</span></div>
                            </div>
                        </div>
                        <div class="dfv2-status-badge status-active">
                            <span class="dfv2-dot-pulse"></span>
                            <span>● ACTIVE · ON ROAD</span>
                        </div>
                    </div>
                    <div class="dfv2-card-mid">
                        <div class="dfv2-card-cap-icon">🎓</div>
                        <h3 class="dfv2-student-name">Marcus Johnson</h3>
                        <div class="dfv2-pills-row">
                            <span class="dfv2-pill dfv2-pill-instructor">👨‍🏫 Mr. Anderson</span>
                            <span class="dfv2-pill dfv2-pill-lesson">🚙 Lesson 5 · Highway Merging</span>
                        </div>
                    </div>
                    <div class="dfv2-card-time">
                        <span class="dfv2-time-clock-icon">🕐</span>
                        <span class="dfv2-time-text">9:00 AM – 11:00 AM</span>
                    </div>
                </article>

                <article class="dfv2-card dfv2-card--upcoming">
                    <div class="dfv2-card-top">
                        <div class="dfv2-card-top-row">
                            <div class="maryland-plate" data-plate="8WD 4931">
                                <span class="plate-bolt bolt-tl"></span><span class="plate-bolt bolt-tr"></span>
                                <span class="plate-number">8WD 4931</span>
                                <span class="plate-bolt bolt-bl"></span><span class="plate-bolt bolt-br"></span>
                                <span class="plate-shine"></span>
                            </div>
                            <div class="lb-fuel" style="--f: #22ff88;" role="meter" aria-label="Fuel level" aria-valuenow="78" title="Vehicle Fuel: 78%">
                                <div class="lb-fuel-top">
                                    <svg viewBox="0 0 24 24" class="lb-fuel-icon" fill="currentColor" width="14" height="14"><path d="M19.77 7.23l.01-.01-3.72-3.72L15 4.56l2.11 2.11c-.94.36-1.61 1.26-1.61 2.33 0 1.38 1.12 2.5 2.5 2.5.36 0 .69-.08 1-.21v7.21c0 .55-.45 1-1 1s-1-.45-1-1V14c0-1.1-.9-2-2-2h-1V5c0-1.1-.9-2-2-2H6c-1.1 0-2 .9-2 2v16h10v-7.5h1.5v5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V9c0-.69-.28-1.32-.73-1.77zM12 10H6V5h6v5zm6 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/></svg>
                                    <span class="lb-fuel-pct">78%</span>
                                </div>
                                <div class="lb-fuel-grid">
                                    <i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i>
                                </div>
                                <div class="lb-fuel-labels"><span>E</span><span>FUEL</span><span>F</span></div>
                            </div>
                        </div>
                        <div class="dfv2-status-badge status-upcoming">
                            <span>● UPCOMING NEXT</span>
                        </div>
                    </div>
                    <div class="dfv2-card-mid">
                        <div class="dfv2-card-cap-icon">🎓</div>
                        <h3 class="dfv2-student-name">Sarah Chen</h3>
                        <div class="dfv2-pills-row">
                            <span class="dfv2-pill dfv2-pill-instructor" style="background:#1e40af;">👩‍🏫 Ms. Rivera</span>
                            <span class="dfv2-pill dfv2-pill-lesson">🚙 Lesson 5 · Highway Merging</span>
                        </div>
                    </div>
                    <div class="dfv2-card-time">
                        <span class="dfv2-time-clock-icon">🕐</span>
                        <span class="dfv2-time-text">10:30 AM – 12:30 PM</span>
                    </div>
                </article>

                <article class="dfv2-card dfv2-card--completed">
                    <div class="dfv2-card-top">
                        <div class="dfv2-card-top-row">
                            <div class="maryland-plate" data-plate="8WD 4931">
                                <span class="plate-bolt bolt-tl"></span><span class="plate-bolt bolt-tr"></span>
                                <span class="plate-number">8WD 4931</span>
                                <span class="plate-bolt bolt-bl"></span><span class="plate-bolt bolt-br"></span>
                                <span class="plate-shine"></span>
                            </div>
                            <div class="lb-fuel" style="--f: #ffd60a;" role="meter" aria-label="Fuel level" aria-valuenow="34" title="Vehicle Fuel: 34%">
                                <div class="lb-fuel-top">
                                    <svg viewBox="0 0 24 24" class="lb-fuel-icon" fill="currentColor" width="14" height="14"><path d="M19.77 7.23l.01-.01-3.72-3.72L15 4.56l2.11 2.11c-.94.36-1.61 1.26-1.61 2.33 0 1.38 1.12 2.5 2.5 2.5.36 0 .69-.08 1-.21v7.21c0 .55-.45 1-1 1s-1-.45-1-1V14c0-1.1-.9-2-2-2h-1V5c0-1.1-.9-2-2-2H6c-1.1 0-2 .9-2 2v16h10v-7.5h1.5v5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V9c0-.69-.28-1.32-.73-1.77zM12 10H6V5h6v5zm6 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/></svg>
                                    <span class="lb-fuel-pct">34%</span>
                                </div>
                                <div class="lb-fuel-grid">
                                    <i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg on"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i><i class="lb-fuel-seg"></i>
                                </div>
                                <div class="lb-fuel-labels"><span>E</span><span>FUEL</span><span>F</span></div>
                            </div>
                        </div>
                        <div class="dfv2-status-badge status-completed">
                            <span>✓ COMPLETED</span>
                        </div>
                    </div>
                    <div class="dfv2-card-mid">
                        <div class="dfv2-card-cap-icon">🎓</div>
                        <h3 class="dfv2-student-name">David Kim</h3>
                        <div class="dfv2-pills-row">
                            <span class="dfv2-pill dfv2-pill-instructor" style="background:rgba(30,64,175,0.5);color:#cbd5e1;">👨‍🏫 Mr. Thompson</span>
                            <span class="dfv2-pill dfv2-pill-lesson">🚙 Lesson 5 · Highway Merging</span>
                        </div>
                    </div>
                    <div class="dfv2-card-time">
                        <span class="dfv2-time-clock-icon">🕐</span>
                        <span class="dfv2-time-text">7:00 AM – 9:00 AM</span>
                    </div>
                </article>
            <?php endif; ?>
        </div><!-- /.dfv2-grid -->

        <div class="dfv2-pager" style="display:none;"></div>
    </main>

    <!-- ══════════════════ 3. MULTI-MESSAGE TICKER FOOTER (MATCHING MOCKUP) ══════════════════ -->
    <footer class="dfv2-ticker">
        <div class="dfv2-ticker-badge">
            <span class="dfv2-ticker-live-dot"></span>
            ANNOUNCEMENTS
        </div>
        <div class="dfv2-ticker-track">
            <div class="dfv2-ticker-text" data-ticker-content>
                <?php 
                $active_ticker_items = !empty($ticker_items) ? $ticker_items : array(
                    "Welcome to " . ($brand['name'] ?? "Sam's Driving School LLC"),
                    "Please have your Maryland learner permit ready before departure",
                    "Safe driving is respect for life — Always buckle up",
                    "Maryland MVA COMAR 11.23 Regulatory Compliant",
                    "All instructional vehicles are dual-brake safety certified"
                );
                foreach ($active_ticker_items as $t_item) : ?>
                    <span class="dfv2-ticker-item"><span class="dfv2-ticker-bullet">◆</span> <?php echo esc_html($t_item); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </footer>

</div><!-- /.dfv2-tv-wrap -->
