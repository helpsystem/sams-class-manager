<?php
defined('ABSPATH') || exit;

/**
 * DriveFlow Pro - Instructor Mobile & Tablet In-Car Evaluation App
 * Official Driver Education Student In-Car Evaluation / Progress Record
 */
$school_name = !empty($brand['name']) ? $brand['name'] : 'SAM Driving School';
$logo_url = !empty($brand['logo']) ? $brand['logo'] : '';
?>

<div class="sam-eval-container" id="driveflow-instructor-app" dir="ltr">
  <style>
    .sam-eval-container {
      max-width: 980px;
      margin: 15px auto;
      background: #ffffff;
      padding: 24px 30px;
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.08);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
      color: #1f2937;
      direction: ltr;
      text-align: left;
    }
    .sam-eval-header {
      text-align: center;
      border-bottom: 2px solid #e5e7eb;
      padding-bottom: 18px;
      margin-bottom: 20px;
      position: relative;
    }
    .sam-eval-header h1 {
      margin: 0;
      font-size: 24px;
      font-weight: 800;
      color: #111827;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .sam-eval-header h2 {
      margin: 6px 0 0;
      font-size: 16px;
      font-weight: 700;
      color: #16a34a;
    }
    .sam-top-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #f0fdf4;
      color: #16a34a;
      border: 1px solid #bbf7d0;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      margin-bottom: 8px;
    }
    .sam-pulse-dot {
      width: 7px;
      height: 7px;
      background: #16a34a;
      border-radius: 50%;
      box-shadow: 0 0 8px #16a34a;
      animation: samPulse 1.5s infinite;
    }
    @keyframes samPulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.5; transform: scale(1.3); }
    }
    .sam-instructor-bar {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 14px 18px;
      margin-bottom: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }
    .sam-today-sessions-wrap {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 20px;
    }
    .sam-sessions-carousel {
      display: flex;
      gap: 10px;
      overflow-x: auto;
      padding-top: 8px;
      padding-bottom: 4px;
    }
    .sam-session-chip {
      background: #ffffff;
      border: 1px solid #93c5fd;
      border-radius: 6px;
      padding: 8px 12px;
      font-size: 12px;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.2s;
    }
    .sam-session-chip:hover {
      background: #2563eb;
      color: #ffffff;
      border-color: #1d4ed8;
    }
    .sam-session-chip.is-active {
      background: #2563eb;
      color: #ffffff;
      border-color: #1d4ed8;
      font-weight: 700;
    }
    .sam-meta-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 14px;
      margin-bottom: 20px;
      background: #f9fafb;
      padding: 16px;
      border-radius: 8px;
      border: 1px solid #e5e7eb;
    }
    .sam-input-group label {
      display: block;
      font-size: 11px;
      font-weight: 700;
      color: #4b5563;
      text-transform: uppercase;
      margin-bottom: 4px;
      letter-spacing: 0.3px;
    }
    .sam-input-group input, .sam-input-group select {
      width: 100%;
      padding: 8px 10px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 13px;
      box-sizing: border-box;
      outline: none;
      background: #ffffff;
      transition: border 0.2s;
    }
    .sam-input-group input:focus, .sam-input-group select:focus {
      border-color: #16a34a;
    }
    .sam-scale-box {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 20px;
    }
    .sam-scale-box p {
      margin: 0 0 6px;
      font-weight: 700;
      font-size: 13px;
      color: #166534;
    }
    .sam-scale-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 6px;
      font-size: 12px;
      color: #374151;
    }
    .sam-eval-table-wrap {
      overflow-x: auto;
      margin-bottom: 25px;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
    }
    .sam-eval-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      background: #fff;
    }
    .sam-eval-table th, .sam-eval-table td {
      border: 1px solid #e5e7eb;
      padding: 7px 8px;
    }
    .sam-eval-table th {
      background: #f3f4f6;
      font-weight: 700;
      color: #1f2937;
      text-align: center;
      font-size: 12px;
    }
    .sam-eval-table th.skill-col {
      text-align: left;
      width: 46%;
    }
    .sam-eval-table td select {
      width: 100%;
      padding: 4px;
      border: 1px solid #d1d5db;
      border-radius: 4px;
      font-size: 13px;
      text-align: center;
      background: #fff;
      font-weight: 600;
    }
    .sam-eval-table td select:focus {
      border-color: #16a34a;
    }
    .sam-section-title {
      background: #e5e7eb;
      font-weight: 800;
      color: #374151;
      padding: 8px 12px;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    /* Extra utilities: Stopwatch & Selfie */
    .sam-addon-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-bottom: 20px;
    }
    .sam-addon-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 14px 16px;
    }
    .sam-addon-title {
      font-size: 12px;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .sam-timer-display {
      font-family: monospace;
      font-size: 22px;
      font-weight: 800;
      color: #0f172a;
    }
    .sam-btn-sm {
      padding: 4px 10px;
      border-radius: 4px;
      border: 1px solid #cbd5e1;
      background: #fff;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
    }
    .sam-footer-box {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: 20px;
      margin-top: 20px;
    }
    .sam-eval-result {
      display: flex;
      align-items: center;
      gap: 20px;
      margin-bottom: 18px;
      padding-bottom: 15px;
      border-bottom: 1px dashed #d1d5db;
    }
    .sam-eval-result label {
      font-weight: 700;
      font-size: 14px;
    }
    .sam-radio-opt {
      display: flex;
      align-items: center;
      gap: 6px;
      font-weight: 700;
      cursor: pointer;
      font-size: 14px;
    }
    .sam-signatures {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }
    .sig-canvas-wrap {
      border: 1.5px dashed #9ca3af;
      background: #fff;
      border-radius: 6px;
      height: 95px;
      position: relative;
      touch-action: none;
    }
    .sig-canvas-wrap canvas {
      width: 100%;
      height: 100%;
      display: block;
    }
    .sig-clear-btn {
      position: absolute;
      top: 4px;
      right: 4px;
      font-size: 10px;
      background: #fee2e2;
      color: #b91c1c;
      border: 1px solid #fca5a5;
      padding: 2px 7px;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 600;
    }
    .sig-clear-btn:hover {
      background: #ef4444;
      color: #fff;
    }
    .sam-submit-btn {
      display: block;
      width: 100%;
      background: #16a34a;
      color: #fff;
      font-weight: 800;
      font-size: 16px;
      padding: 14px 20px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      margin-top: 24px;
      transition: background 0.2s, transform 0.1s;
      letter-spacing: 0.3px;
    }
    .sam-submit-btn:hover {
      background: #15803d;
      transform: translateY(-1px);
    }
    .sam-submit-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    .sam-feedback-alert {
      display: none;
      padding: 14px 18px;
      border-radius: 8px;
      margin-top: 18px;
      font-size: 14px;
      font-weight: 600;
    }
    .sam-feedback-success {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #15803d;
    }
    .sam-feedback-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #b91c1c;
    }
    @media (max-width: 680px) {
      .sam-eval-container { padding: 16px; }
      .sam-signatures { grid-template-columns: 1fr; }
      .sam-addon-grid { grid-template-columns: 1fr; }
    }
  </style>

  <!-- Form -->
  <form id="mvaInCarEvalForm">
    <input type="hidden" name="action" value="driveflow_submit_evaluation">
    <input type="hidden" name="df_nonce" value="<?php echo esc_attr($df_nonce ?? ''); ?>">
    <input type="hidden" name="session_id" id="sam-session-id" value="0">
    <input type="hidden" name="instructor_signature_data" id="instructorSigInput">
    <input type="hidden" name="student_signature_data" id="studentSigInput">
    <input type="hidden" name="selfie" id="samSelfieInput">

    <!-- Header -->
    <div class="sam-eval-header">
      <div class="sam-top-badge"><span class="sam-pulse-dot"></span> In-Car Instructor Terminal</div>
      <?php if (!empty($logo_url)) : ?>
        <div style="margin-bottom:8px;"><img src="<?php echo esc_url($logo_url); ?>" alt="" style="max-height:45px;max-width:160px;"></div>
      <?php endif; ?>
      <h1><?php echo esc_html($school_name); ?></h1>
      <h2>Driver Education Student In-Car Evaluation / Progress Record</h2>
    </div>

    <!-- Instructor Active Profile Bar -->
    <div class="sam-instructor-bar">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:24px;">👨‍🏫</span>
        <div>
          <strong style="font-size:14px;color:#0f172a;" id="sam-active-instructor-label">Select Assigned Instructor:</strong>
          <div style="font-size:12px;color:#64748b;">In-car evaluation and progress record sign-off</div>
        </div>
      </div>
      <?php if (!empty($locked)) : ?>
      <div style="min-width:220px;padding:6px 10px;border-radius:8px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:13px;font-weight:600;">
        ✓ Signed in: <?php echo esc_html($current_instructor['name']); ?><?php echo !empty($current_instructor['license_number']) ? ' (Lic: ' . esc_html($current_instructor['license_number']) . ')' : ''; ?>
      </div>
      <?php endif; ?>
      <div style="min-width:220px;<?php echo !empty($locked) ? 'display:none;' : ''; ?>">
        <select id="sam-instructor-quick-select" class="sam-input-group" style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;font-weight:600;">
          <option value="">-- Choose Instructor Profile --</option>
          <?php if (!empty($instructors)) : foreach ($instructors as $ins) : ?>
            <option value="<?php echo esc_attr($ins['name']); ?>" data-cert="<?php echo esc_attr($ins['license_number']); ?>">
              <?php echo esc_html($ins['name']); ?> (Lic: <?php echo esc_html($ins['license_number'] ?: 'MVA'); ?>)
            </option>
          <?php endforeach; endif; ?>
        </select>
      </div>
    </div>

    <!-- Today's Assigned Sessions Quick Picker (Live Dynamic Auto-Sync) -->
    <div class="sam-today-sessions-wrap" id="sam-today-sessions-wrap" style="<?php echo empty($today_sessions) ? 'display:none;' : ''; ?>">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
        <div style="font-size:12px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:0.3px;display:flex;align-items:center;gap:6px;">
          <span>📅 Today's Scheduled Lessons</span>
          <span class="sam-badge-live" id="sam-today-count-badge" style="background:#dbeafe;color:#1d4ed8;font-size:10px;padding:2px 7px;border-radius:10px;font-weight:800;"><?php echo count($today_sessions ?? array()); ?></span>
        </div>
        <div id="sam-sync-status-tag" style="font-size:11px;color:#059669;font-weight:600;display:inline-flex;align-items:center;gap:5px;">
          <span class="sam-pulse-dot" style="width:6px;height:6px;background:#10b981;"></span>
          <span id="sam-sync-status-text">Live Auto-Sync Active</span>
        </div>
      </div>
      <div class="sam-sessions-carousel" id="sam-today-sessions-carousel">
        <?php if (!empty($today_sessions)) : foreach ($today_sessions as $ts) : 
          $time_fmt = date_i18n('g:i A', strtotime($ts['scheduled_start']));
        ?>
          <div class="sam-session-chip" 
               data-session-id="<?php echo esc_attr($ts['id']); ?>"
               data-student="<?php echo esc_attr($ts['student_name']); ?>"
               data-instructor="<?php echo esc_attr($ts['instructor_name']); ?>"
               data-plate="<?php echo esc_attr($ts['plate_number']); ?>"
               data-number="<?php echo esc_attr($ts['session_number']); ?>"
               data-start="<?php echo esc_attr($ts['scheduled_start']); ?>"
               data-end="<?php echo esc_attr($ts['scheduled_end']); ?>"
               data-topic="<?php echo esc_attr($ts['lesson_topic']); ?>"
               data-status="<?php echo esc_attr($ts['status']); ?>">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <strong>#<?php echo esc_html($ts['session_number']); ?></strong> <?php echo esc_html($ts['student_name']); ?> • <?php echo esc_html($time_fmt); ?>
              <?php if (!empty($ts['plate_number'])) echo DriveFlow_Pro::render_maryland_plate($ts['plate_number'], 'sm'); ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Student & Instructor Info Meta Grid -->
    <div class="sam-meta-grid">
      <div class="sam-input-group">
        <label>Student Name *</label>
        <input type="text" name="student_name" id="sam-student-name" required placeholder="Full Legal Name" list="sam-students-list">
        <datalist id="sam-students-list">
          <?php if (!empty($students)) : foreach ($students as $st) : ?>
            <option value="<?php echo esc_attr($st['name']); ?>"><?php echo esc_html($st['name']); ?> (Permit: <?php echo esc_html($st['license_number']); ?>)</option>
          <?php endforeach; endif; ?>
        </datalist>
      </div>

      <div class="sam-input-group">
        <label>Instructor Name *</label>
        <input type="text" name="instructor_name" id="sam-instructor-name" required placeholder="Instructor Name" value="<?php echo esc_attr(!empty($locked) ? $current_instructor['name'] : ''); ?>" <?php echo !empty($locked) ? 'readonly' : ''; ?>>
      </div>

      <div class="sam-input-group">
        <label>Instructor License #</label>
        <input type="text" name="instructor_cert_no" id="sam-instructor-cert" placeholder="MVA Lic. / Cert #" value="<?php echo esc_attr(!empty($locked) ? $current_instructor['license_number'] : ''); ?>" <?php echo !empty($locked) ? 'readonly' : ''; ?>>
      </div>

      <div class="sam-input-group">
        <label>Fleet Vehicle Plate</label>
        <select name="plate_number" id="sam-vehicle-plate">
          <option value="">Select Training Vehicle...</option>
          <?php if (!empty($vehicles)) : foreach ($vehicles as $veh) : ?>
            <option value="<?php echo esc_attr($veh['plate_number']); ?>">
              <?php echo esc_html($veh['plate_number']); ?> (<?php echo esc_html($veh['model']); ?>)
            </option>
          <?php endforeach; endif; ?>
        </select>
        <div id="sam-plate-preview" style="margin-top:6px;"></div>
      </div>

      <div class="sam-input-group">
        <label>Lesson Number</label>
        <input type="number" name="session_number" id="sam-session-number" value="1" min="1" max="50">
      </div>

      <div class="sam-input-group">
        <label>Curriculum Focus Topic</label>
        <input type="text" name="lesson_topic" id="sam-lesson-topic" placeholder="e.g. Parallel Parking & Highway Driving" value="Behind-The-Wheel Driving Lesson">
      </div>
    </div>

    <!-- Lesson Dates (Sessions 1-6) -->
    <div class="sam-meta-grid" style="grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));">
      <div class="sam-input-group"><label>Dates: Lesson 1</label><input type="date" name="date_l1" id="sam-date-l1" value="<?php echo esc_attr(date('Y-m-d')); ?>"></div>
      <div class="sam-input-group"><label>Dates: Lesson 2</label><input type="date" name="date_l2"></div>
      <div class="sam-input-group"><label>Dates: Lesson 3</label><input type="date" name="date_l3"></div>
      <div class="sam-input-group"><label>Dates: Lesson 4</label><input type="date" name="date_l4"></div>
      <div class="sam-input-group"><label>Dates: Lesson 5</label><input type="date" name="date_l5"></div>
      <div class="sam-input-group"><label>Dates: Lesson 6</label><input type="date" name="date_l6"></div>
    </div>

    <!-- Scoring Legend Guide -->
    <div class="sam-scale-box">
      <p>Rating Scale Guide:</p>
      <div class="sam-scale-grid">
        <div><strong>4</strong> = Performs without coaching</div>
        <div><strong>3</strong> = Occasional coaching</div>
        <div><strong>2</strong> = Significant coaching</div>
        <div><strong>1</strong> = Does not perform adequately</div>
      </div>
    </div>

    <!-- Skills Table Container -->
    <div class="sam-eval-table-wrap">
      <table class="sam-eval-table">
        <thead>
          <tr>
            <th class="skill-col">Skills & Performance Criteria</th>
            <th>L-1</th>
            <th>L-2</th>
            <th>L-3</th>
            <th>L-4</th>
            <th>L-5</th>
            <th>L-6</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $skills = array(
            "Pre-entry checks",
            "Pre-start and starting",
            "Moving forward",
            "Moving backward",
            "Entering traffic",
            "Right Turns",
            "Left turns",
            "Negotiating intersections",
            "Changing lanes",
            "Slowing and stopping",
            "Parking and securing",
            "Parking on grades",
            "Angle parking",
            "Parallel parking",
            "Turnabouts",
            "Assessing highway conditions",
            "Space management",
            "Response to traffic controls",
            "Response to other users",
            "Passing",
            "Commentary driving",
            "Speed"
          );

          $conditions = array(
            "Adverse weather",
            "Night driving"
          );

          foreach ($skills as $idx => $skill) : ?>
            <tr>
              <td><strong><?php echo esc_html($skill); ?></strong></td>
              <?php for ($i = 1; $i <= 6; $i++) : ?>
                <td>
                  <select name="skill_<?php echo $idx; ?>_l<?php echo $i; ?>">
                    <option value="">-</option>
                    <option value="4" <?php selected($i, 1); ?>>4</option>
                    <option value="3">3</option>
                    <option value="2">2</option>
                    <option value="1">1</option>
                  </select>
                </td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>

          <tr>
            <td colspan="7" class="sam-section-title">Driving Conditions (When Appropriate)</td>
          </tr>

          <?php foreach ($conditions as $c_idx => $cond) : ?>
            <tr>
              <td><?php echo esc_html($cond); ?></td>
              <?php for ($i = 1; $i <= 6; $i++) : ?>
                <td>
                  <select name="cond_<?php echo $c_idx; ?>_l<?php echo $i; ?>">
                    <option value="">-</option>
                    <option value="4">4</option>
                    <option value="3">3</option>
                    <option value="2">2</option>
                    <option value="1">1</option>
                  </select>
                </td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- In-Car Utilities: Live Stopwatch & Selfie Photo Verification -->
    <div class="sam-addon-grid">
      <!-- Stopwatch Timer -->
      <div class="sam-addon-box">
        <div class="sam-addon-title">
          <span>⏱️ In-Car Session Timer</span>
          <div>
            <button type="button" class="sam-btn-sm" id="sam-timer-btn" style="background:#16a34a;color:#fff;border-color:#15803d;">▶ Start</button>
            <button type="button" class="sam-btn-sm" id="sam-timer-reset">Reset</button>
          </div>
        </div>
        <div class="sam-timer-display" id="sam-timer-digits">00:00:00</div>
        <small style="color:#64748b;font-size:11px;">Track exact behind-the-wheel instruction duration.</small>
      </div>

      <!-- Instructor Selfie Verification -->
      <div class="sam-addon-box">
        <div class="sam-addon-title">
          <span>📸 In-Car Verification Photo</span>
          <div>
            <button type="button" class="sam-btn-sm" id="sam-camera-btn">📷 Camera</button>
            <button type="button" class="sam-btn-sm" id="sam-flip-btn" style="display:none;">🔄 Flip</button>
            <button type="button" class="sam-btn-sm" id="sam-snap-btn" style="display:none;background:#2563eb;color:#fff;">✓ Capture</button>
          </div>
        </div>
        <div style="position:relative;background:#0f172a;border-radius:6px;height:70px;overflow:hidden;display:flex;align-items:center;justify-content:center;">
          <video id="sam-video" autoplay muted playsinline style="display:none;width:100%;height:100%;object-fit:cover;"></video>
          <canvas id="sam-snap-canvas" style="display:none;"></canvas>
          <img id="sam-selfie-preview" alt="" style="display:none;max-height:100%;object-fit:contain;">
          <span id="sam-cam-placeholder" style="color:#94a3b8;font-size:11px;">Tap Camera to verify presence</span>
        </div>
      </div>
    </div>

    <!-- ══════════════════ VEHICLE FUEL & FLEET INSPECTION (MANDATORY) ══════════════════ -->
    <div class="sam-fuel-inspection-wrap" style="background:#f8fafc;border:2px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:24px;box-shadow:0 2px 6px rgba(0,0,0,0.03);">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:26px;">⛽</span>
          <div>
            <h3 style="margin:0;font-size:15px;font-weight:800;color:#0f172a;text-transform:uppercase;letter-spacing:0.5px;">
              Mandatory Post-Lesson Fuel Gauge & Fleet Inspection
            </h3>
            <span style="font-size:12px;color:#64748b;">
              Required Maryland MVA vehicle turnover checklist. Record remaining fuel before session sign-off.
            </span>
          </div>
        </div>
        <span class="sam-fuel-status-badge" id="sam-fuel-badge" style="background:#10b981;color:#fff;font-weight:800;font-size:12px;padding:4px 12px;border-radius:999px;letter-spacing:0.5px;">
          ✓ FUEL LEVEL: 75% (3/4 TANK)
        </span>
      </div>

      <!-- Gauge & Slider Grid -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:20px;align-items:center;">
        <!-- Animated SVG Fuel Dial Gauge -->
        <div style="text-align:center;background:#0f172a;border-radius:12px;padding:16px 12px;box-shadow:inset 0 2px 8px rgba(0,0,0,0.5);position:relative;">
          <svg id="sam-fuel-dial" viewBox="0 0 200 115" style="width:100%;max-width:230px;height:auto;display:block;margin:0 auto;">
            <!-- Outer Arc Track -->
            <path d="M 25 100 A 75 75 0 0 1 175 100" fill="none" stroke="#1e293b" stroke-width="14" stroke-linecap="round" />
            <!-- Zone 1: Low (0 - 25%) Red -->
            <path d="M 25 100 A 75 75 0 0 1 54 48" fill="none" stroke="#ef4444" stroke-width="14" stroke-linecap="round" />
            <!-- Zone 2: Med (25 - 50%) Amber -->
            <path d="M 54 48 A 75 75 0 0 1 100 25" fill="none" stroke="#f59e0b" stroke-width="14" />
            <!-- Zone 3: Full (50 - 100%) Emerald Green -->
            <path d="M 100 25 A 75 75 0 0 1 175 100" fill="none" stroke="#10b981" stroke-width="14" stroke-linecap="round" />
            <!-- Tick Labels -->
            <text x="22" y="112" fill="#ef4444" font-size="11" font-weight="800">E</text>
            <text x="48" y="38" fill="#f59e0b" font-size="10" font-weight="700">1/4</text>
            <text x="100" y="18" fill="#94a3b8" font-size="10" font-weight="700" text-anchor="middle">1/2</text>
            <text x="150" y="38" fill="#10b981" font-size="10" font-weight="700">3/4</text>
            <text x="178" y="112" fill="#10b981" font-size="11" font-weight="800">F</text>
            <!-- Needle -->
            <g id="sam-needle-pivot" style="transform-origin: 100px 100px; transform: rotate(40deg); transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);">
              <polygon points="97,100 103,100 100,24" fill="#f8fafc" />
              <circle cx="100" cy="100" r="8" fill="#38bdf8" />
              <circle cx="100" cy="100" r="4" fill="#0f172a" />
            </g>
          </svg>
          <div style="margin-top:6px;display:flex;align-items:center;justify-content:center;gap:6px;">
            <span style="color:#94a3b8;font-size:12px;">Reading:</span>
            <strong id="sam-fuel-pct-text" style="color:#38bdf8;font-size:17px;font-weight:800;">75%</strong>
          </div>
        </div>

        <!-- Slider & Presets -->
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334151;margin-bottom:8px;text-transform:uppercase;">
            Select Vehicle Fuel Gauge Reading: *
          </label>
          <input type="range" id="sam-fuel-slider" min="5" max="100" step="5" value="75" style="width:100%;height:8px;accent-color:#0284c7;cursor:pointer;margin-bottom:12px;">
          <input type="hidden" name="fuel_level_pct" id="sam-fuel-level-pct" value="75">

          <!-- Presets -->
          <div style="display:grid;grid-template-columns:repeat(5, 1fr);gap:6px;margin-bottom:10px;">
            <button type="button" class="sam-fuel-preset-btn" data-pct="10" style="padding:7px 2px;background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;">E (10%)</button>
            <button type="button" class="sam-fuel-preset-btn" data-pct="25" style="padding:7px 2px;background:#fef3c7;border:1px solid #fde68a;color:#b45309;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;">1/4 (25%)</button>
            <button type="button" class="sam-fuel-preset-btn" data-pct="50" style="padding:7px 2px;background:#e0f2fe;border:1px solid #bae6fd;color:#0369a1;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;">1/2 (50%)</button>
            <button type="button" class="sam-fuel-preset-btn is-active" data-pct="75" style="padding:7px 2px;background:#0284c7;border:1px solid #0284c7;color:#fff;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;">3/4 (75%)</button>
            <button type="button" class="sam-fuel-preset-btn" data-pct="100" style="padding:7px 2px;background:#dcfce7;border:1px solid #86efac;color:#15803d;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;">FULL</button>
          </div>
          <small style="color:#64748b;font-size:11px;display:block;line-height:1.4;">
            💡 Operating standard: Minimum 25% (1/4 tank). Automatic refueling protocol activates when below threshold.
          </small>
        </div>
      </div>

      <!-- Critical Low Fuel Alert Banner (Auto-shown when <= 25%) -->
      <div id="sam-low-fuel-alert" style="display:none;margin-top:16px;background:#fef2f2;border:2px solid #ef4444;border-radius:10px;padding:16px 18px;box-shadow:0 4px 12px rgba(239, 68, 68, 0.12);">
        <div style="display:flex;align-items:flex-start;gap:12px;">
          <span style="font-size:32px;line-height:1;">⛽⚠️</span>
          <div style="flex:1;">
            <h4 style="margin:0 0 6px 0;font-size:14px;color:#991b1b;font-weight:800;text-transform:uppercase;">
              Critical Low Fuel Alert (≤ 25% Tank Remaining) — Refueling Required!
            </h4>
            <p style="font-size:12px;color:#7f1d1d;line-height:1.5;margin:0 0 10px 0;">
              This training vehicle requires refueling before the next student lesson.
              <strong>Maryland MVA Practical Curriculum Requirement:</strong> The instructor must stop at a gas station with the student during this session and teach hands-on vehicle refueling procedures (vehicle shutoff, 87 regular unleaded selection, fuel door operation, nozzle safety, and receipt collection).
            </p>
            <div style="background:#fff;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:10px;">
              <label style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#991b1b;cursor:pointer;">
                <input type="checkbox" name="student_refuel_conducted" id="sam-refuel-check" value="1">
                <span>✓ I have conducted / will conduct the Hands-On Student Gas Station Refueling Training Module</span>
              </label>
            </div>
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
              <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-size:12px;font-weight:600;color:#374151;">Gallons Added:</label>
                <input type="number" name="fuel_gallons" step="0.1" min="0" placeholder="e.g. 8.5" style="width:85px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;">
              </div>
              <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-size:12px;font-weight:600;color:#374151;">Cost / Reimbursement ($):</label>
                <input type="number" name="fuel_cost" step="0.01" min="0" placeholder="e.g. 28.50" style="width:85px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Vehicle Malfunction / Road Incident Inspection Report -->
      <div style="margin-top:18px;padding-top:14px;border-top:1px solid #e2e8f0;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
          <label style="font-size:13px;font-weight:800;color:#0f172a;text-transform:uppercase;letter-spacing:0.3px;">
            🚗 Vehicle Condition & Road Incident Report:
          </label>
          <div style="display:flex;gap:10px;">
            <label class="sam-radio-pill is-active" id="sam-incident-no-pill" style="display:inline-flex;align-items:center;gap:6px;background:#dcfce7;border:1px solid #86efac;color:#166534;font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;cursor:pointer;">
              <input type="radio" name="has_vehicle_incident" value="no" checked style="display:none;">
              <span>✓ No Issues (100% Operational)</span>
            </label>
            <label class="sam-radio-pill" id="sam-incident-yes-pill" style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;cursor:pointer;">
              <input type="radio" name="has_vehicle_incident" value="yes" style="display:none;">
              <span>⚠️ Report Malfunction / Issue</span>
            </label>
          </div>
        </div>

        <!-- Expandable Incident Details Box -->
        <div id="sam-incident-details-box" style="display:none;background:#fff;border:2px dashed #f59e0b;border-radius:10px;padding:14px;margin-top:10px;">
          <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:8px;text-transform:uppercase;">
            Select Malfunction / Incident Category (Check all that apply):
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(190px, 1fr));gap:8px;margin-bottom:12px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;background:#f8fafc;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;">
              <input type="checkbox" name="incident_types[]" value="flat_tire"> 🛞 Flat Tire / Low Pressure
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;background:#f8fafc;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;">
              <input type="checkbox" name="incident_types[]" value="wipers_broken"> 🌧️ Windshield Wipers Damaged
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;background:#f8fafc;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;">
              <input type="checkbox" name="incident_types[]" value="check_engine"> ⚠️ Check Engine Light On
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;background:#f8fafc;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;">
              <input type="checkbox" name="incident_types[]" value="brakes"> 🛑 Brake Squeal / Soft Pedal
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;background:#f8fafc;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;">
              <input type="checkbox" name="incident_types[]" value="body_damage"> 💥 Minor Scrape / Dent / Body
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;background:#f8fafc;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;">
              <input type="checkbox" name="incident_types[]" value="climate_control"> ❄️ AC / Heater Broken
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;background:#f8fafc;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;">
              <input type="checkbox" name="incident_types[]" value="noise"> 🔊 Strange Engine / Trans Noise
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#1e293b;background:#f8fafc;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;">
              <input type="checkbox" name="incident_types[]" value="other"> ❓ Other Mechanical Concern
            </label>
          </div>

          <div style="margin-bottom:12px;">
            <label style="display:block;font-size:12px;font-weight:700;color:#334151;margin-bottom:4px;">
              Urgency Level:
            </label>
            <div style="display:flex;gap:14px;flex-wrap:wrap;">
              <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#b45309;cursor:pointer;">
                <input type="radio" name="incident_urgency" value="routine" checked>
                <span>🟡 Routine Maintenance (Vehicle is still safe for next student)</span>
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#b91c1c;font-weight:700;cursor:pointer;">
                <input type="radio" name="incident_urgency" value="urgent_ground">
                <span>🔴 URGENT: Ground Vehicle (DO NOT dispatch to next student!)</span>
              </label>
            </div>
          </div>

          <div>
            <label style="display:block;font-size:12px;font-weight:700;color:#334151;margin-bottom:4px;">
              Describe Malfunction or Incident in Detail for Academy Fleet Crew: *
            </label>
            <textarea name="incident_description" id="sam-incident-desc" rows="2" placeholder="e.g. Right rear tire pressure warning came on, tire appears flat. Or: Driver windshield wiper blade rubber came off." style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;box-sizing:border-box;"></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- Instructor Notes / Feedback -->
    <div style="margin-bottom:20px;">
      <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;text-transform:uppercase;">
        Instructor Feedback & Coaching Recommendations (Emailed to Student):
      </label>
      <textarea name="instructor_notes" rows="2" placeholder="e.g. Excellent progress on parallel parking and lane changes. Continue practicing mirror checks and speed management when approaching intersections." style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box;outline:none;"></textarea>
    </div>

    <!-- Final Evaluation & Signatures -->
    <div class="sam-footer-box">
      <div class="sam-eval-result">
        <label>Final Evaluation:</label>
        <label class="sam-radio-opt" style="color: #15803d;">
          <input type="radio" name="final_evaluation" value="Pass" checked required> PASS
        </label>
        <label class="sam-radio-opt" style="color: #b91c1c;">
          <input type="radio" name="final_evaluation" value="Fail" required> FAIL
        </label>
      </div>

      <div class="sam-signatures">
        <!-- Instructor Signature -->
        <div>
          <label style="display:block; font-size: 12px; font-weight:700; margin-bottom: 6px; color:#111827;">
            Instructor Signature & Date:
          </label>
          <div class="sig-canvas-wrap">
            <button type="button" class="sig-clear-btn" onclick="clearSignatureCanvas('instructorSig', 'instructorSigInput')">Clear</button>
            <canvas id="instructorSig"></canvas>
          </div>
          <small style="color:#64748b;font-size:11px;">Sign with finger or stylus</small>
        </div>

        <!-- Student/Driver Signature -->
        <div>
          <label style="display:block; font-size: 12px; font-weight:700; margin-bottom: 6px; color:#111827;">
            Driver / Student Signature & Date:
          </label>
          <div class="sig-canvas-wrap">
            <button type="button" class="sig-clear-btn" onclick="clearSignatureCanvas('studentSig', 'studentSigInput')">Clear</button>
            <canvas id="studentSig"></canvas>
          </div>
          <small style="color:#64748b;font-size:11px;">Student signs on tablet/phone screen</small>
        </div>
      </div>
    </div>

    <!-- Submit Button & Feedback -->
    <button type="submit" class="sam-submit-btn" id="sam-submit-btn">
      Save & Submit Evaluation Record
    </button>
    <div id="sam-feedback-alert" class="sam-feedback-alert"></div>
  </form>

  <script>
    function setupSignaturePad(canvasId, inputId) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;
      const ctx = canvas.getContext('2d');
      let drawing = false;

      function resize() {
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width || 350;
        canvas.height = rect.height || 95;
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0f172a';
      }
      resize();
      window.addEventListener('resize', resize);

      function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        return { x: clientX - rect.left, y: clientY - rect.top };
      }

      function start(e) {
        drawing = true;
        const p = getPos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
      }
      function move(e) {
        if (!drawing) return;
        if (e.cancelable && e.type.startsWith('touch')) e.preventDefault();
        const p = getPos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
      }
      function stop() {
        if (drawing) {
          drawing = false;
          document.getElementById(inputId).value = canvas.toDataURL('image/png');
        }
      }

      canvas.addEventListener('mousedown', start);
      canvas.addEventListener('mousemove', move);
      window.addEventListener('mouseup', stop);

      canvas.addEventListener('touchstart', start, { passive: false });
      canvas.addEventListener('touchmove', move, { passive: false });
      window.addEventListener('touchend', stop);
    }

    function clearSignatureCanvas(canvasId, inputId) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;
      const ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      const input = document.getElementById(inputId);
      if (input) input.value = '';
    }

    // Initialize both signatures
    setupSignaturePad('instructorSig', 'instructorSigInput');
    setupSignaturePad('studentSig', 'studentSigInput');

    // Instructor Quick Select handler
    const insSelect = document.getElementById('sam-instructor-quick-select');
    if (insSelect) {
      insSelect.addEventListener('change', function() {
        const val = this.value;
        const cert = this.options[this.selectedIndex].getAttribute('data-cert') || '';
        document.getElementById('sam-instructor-name').value = val;
        document.getElementById('sam-instructor-cert').value = cert;
        if (val) {
          document.getElementById('sam-active-instructor-label').textContent = 'Active Instructor: ' + val;
        }
      });
    }

    // ══════════════════ DYNAMIC AUTO-SYNC FOR INSTRUCTOR APP ══════════════════
    let lastInstructorSyncChange = 0;
    let currentSelectedSessionId = 0;

    function bindSessionChipEvents() {
      document.querySelectorAll('.sam-session-chip').forEach(function(chip) {
        chip.onclick = function() {
          document.querySelectorAll('.sam-session-chip').forEach(c => c.classList.remove('is-active'));
          this.classList.add('is-active');

          currentSelectedSessionId = parseInt(this.dataset.sessionId, 10) || 0;
          document.getElementById('sam-session-id').value = currentSelectedSessionId || '0';
          document.getElementById('sam-student-name').value = this.dataset.student || '';
          document.getElementById('sam-instructor-name').value = this.dataset.instructor || '';
          document.getElementById('sam-session-number').value = this.dataset.number || '1';
          document.getElementById('sam-lesson-topic').value = this.dataset.topic || 'Behind-The-Wheel Driving Lesson';
          
          const plate = this.dataset.plate || '';
          const plateSelect = document.getElementById('sam-vehicle-plate');
          if (plate && plateSelect) {
            plateSelect.value = plate;
            updatePlatePreview(plate);
          }
        };
      });
    }
    bindSessionChipEvents();

    function syncInstructorTodaySessions() {
      const insName = document.getElementById('sam-instructor-name').value || '';
      const ajaxUrl = (window.DriveFlowPro && window.DriveFlowPro.ajaxurl) ? window.DriveFlowPro.ajaxurl : '/wp-admin/admin-ajax.php';
      const fetchUrl = ajaxUrl + '?action=driveflow_get_instructor_today_sessions' + (insName ? '&instructor_name=' + encodeURIComponent(insName) : '');

      fetch(fetchUrl, {
        headers: { 'Accept': 'application/json' }
      })
      .then(r => r.json())
      .then(res => {
        if (res && res.success && res.data) {
          const sessions = res.data.sessions || [];
          const lastChange = res.data.last_calendar_change || 0;
          renderTodaySessionsCarousel(sessions);
          lastInstructorSyncChange = lastChange;
        }
      })
      .catch(function() {});
    }

    function renderTodaySessionsCarousel(sessions) {
      const wrap = document.getElementById('sam-today-sessions-wrap');
      const carousel = document.getElementById('sam-today-sessions-carousel');
      const countBadge = document.getElementById('sam-today-count-badge');
      if (!wrap || !carousel) return;

      if (!sessions || sessions.length === 0) {
        wrap.style.display = 'none';
        carousel.innerHTML = '';
        if (countBadge) countBadge.textContent = '0';
        return;
      }

      wrap.style.display = 'block';
      if (countBadge) countBadge.textContent = sessions.length;

      let html = '';
      sessions.forEach(s => {
        const isActive = (s.id === currentSelectedSessionId);
        html += '<div class="sam-session-chip ' + (isActive ? 'is-active' : '') + '" ' +
                'data-session-id="' + s.id + '" ' +
                'data-student="' + (s.student_name || '').replace(/"/g, '&quot;') + '" ' +
                'data-instructor="' + (s.instructor_name || '').replace(/"/g, '&quot;') + '" ' +
                'data-plate="' + (s.plate_number || '').replace(/"/g, '&quot;') + '" ' +
                'data-number="' + (s.session_number || 1) + '" ' +
                'data-start="' + (s.scheduled_start || '') + '" ' +
                'data-end="' + (s.scheduled_end || '') + '" ' +
                'data-topic="' + (s.lesson_topic || 'Behind-The-Wheel Lesson').replace(/"/g, '&quot;') + '" ' +
                'data-status="' + (s.status || 'upcoming') + '">';
        html += '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">';
        html += '<strong>#' + (s.session_number || 1) + '</strong> ' + (s.student_name || 'Student') + ' • ' + (s.time_fmt || '') + ' ';
        if (s.plate_html) {
          html += s.plate_html;
        } else if (s.plate_number) {
          html += '<span style="font-size:10px;background:#1e293b;color:#facc15;padding:2px 5px;border-radius:4px;font-weight:700;">' + s.plate_number + '</span>';
        }
        html += '</div></div>';
      });

      carousel.innerHTML = html;
      bindSessionChipEvents();
    }

    // Auto-sync polling every 8 seconds for real-time dispatch updates
    setInterval(syncInstructorTodaySessions, 8000);

    function updatePlatePreview(plate) {
      const prev = document.getElementById('sam-plate-preview');
      if (!prev) return;
      if (plate) {
        prev.innerHTML = '<div class="maryland-plate plate-sm" title="Maryland Registration: ' + plate + ' (Click to Preview)" data-plate="' + plate + '" role="button" tabindex="0"><span class="plate-bolt bolt-tl"></span><span class="plate-bolt bolt-tr"></span><span class="plate-number">' + plate + '</span><span class="plate-bolt bolt-bl"></span><span class="plate-bolt bolt-br"></span><span class="plate-shine"></span></div>';
      } else {
        prev.innerHTML = '';
      }
    }
    const plateSelectEl = document.getElementById('sam-vehicle-plate');
    if (plateSelectEl) {
      plateSelectEl.addEventListener('change', function() {
        updatePlatePreview(this.value);
      });
    }

    // Stopwatch Timer
    let timerInterval = null;
    let timerSeconds = 0;
    let timerRunning = false;
    const timerBtn = document.getElementById('sam-timer-btn');
    const timerReset = document.getElementById('sam-timer-reset');
    const timerDigits = document.getElementById('sam-timer-digits');

    function pad2(n) { return n < 10 ? '0' + n : n; }
    function renderTimer() {
      const h = Math.floor(timerSeconds / 3600);
      const m = Math.floor((timerSeconds % 3600) / 60);
      const s = timerSeconds % 60;
      timerDigits.textContent = pad2(h) + ':' + pad2(m) + ':' + pad2(s);
    }

    if (timerBtn) {
      timerBtn.addEventListener('click', function() {
        timerRunning = !timerRunning;
        if (timerRunning) {
          timerBtn.textContent = '⏸ Pause';
          timerBtn.style.background = '#f59e0b';
          timerBtn.style.borderColor = '#d97706';
          timerInterval = setInterval(function() {
            timerSeconds++;
            renderTimer();
          }, 1000);
        } else {
          timerBtn.textContent = '▶ Resume';
          timerBtn.style.background = '#16a34a';
          timerBtn.style.borderColor = '#15803d';
          clearInterval(timerInterval);
        }
      });
    }

    if (timerReset) {
      timerReset.addEventListener('click', function() {
        timerRunning = false;
        clearInterval(timerInterval);
        timerSeconds = 0;
        renderTimer();
        timerBtn.textContent = '▶ Start';
        timerBtn.style.background = '#16a34a';
        timerBtn.style.borderColor = '#15803d';
      });
    }

    // Camera handling
    let camStream = null;
    let useFront = true;
    const camBtn = document.getElementById('sam-camera-btn');
    const flipBtn = document.getElementById('sam-flip-btn');
    const snapBtn = document.getElementById('sam-snap-btn');
    const video = document.getElementById('sam-video');
    const placeholder = document.getElementById('sam-cam-placeholder');
    const preview = document.getElementById('sam-selfie-preview');

    function startInCarCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Camera not supported on this device browser.');
        return;
      }
      if (camStream) camStream.getTracks().forEach(t => t.stop());

      navigator.mediaDevices.getUserMedia({
        video: { facingMode: useFront ? 'user' : 'environment' },
        audio: false
      }).then(function(stream) {
        camStream = stream;
        video.srcObject = stream;
        video.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';
        if (preview) preview.style.display = 'none';
        flipBtn.style.display = 'inline-block';
        snapBtn.style.display = 'inline-block';
        camBtn.textContent = 'Active ✓';
      }).catch(function(err) {
        alert('Could not start camera: ' + err.message);
      });
    }

    if (camBtn) camBtn.addEventListener('click', startInCarCamera);
    if (flipBtn) flipBtn.addEventListener('click', function() { useFront = !useFront; startInCarCamera(); });
    if (snapBtn) {
      snapBtn.addEventListener('click', function() {
        const snapCanvas = document.getElementById('sam-snap-canvas');
        snapCanvas.width = video.videoWidth || 640;
        snapCanvas.height = video.videoHeight || 480;
        const ctx = snapCanvas.getContext('2d');
        ctx.drawImage(video, 0, 0, snapCanvas.width, snapCanvas.height);
        const dataUrl = snapCanvas.toDataURL('image/jpeg', 0.85);
        document.getElementById('samSelfieInput').value = dataUrl;

        preview.src = dataUrl;
        preview.style.display = 'block';
        video.style.display = 'none';
        if (camStream) camStream.getTracks().forEach(t => t.stop());
      });
    }

    // ══════════════════ FUEL GAUGE & FLEET INSPECTION CONTROLLER ══════════════════
    function playFuelChime() {
      try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
        osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.15); // A5
        gain.gain.setValueAtTime(0.2, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.4);
      } catch(e) {}
    }

    function updateFuelDisplay(pct, triggerChime = true) {
      pct = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
      const slider = document.getElementById('sam-fuel-slider');
      const hidden = document.getElementById('sam-fuel-level-pct');
      const text = document.getElementById('sam-fuel-pct-text');
      const badge = document.getElementById('sam-fuel-badge');
      const pivot = document.getElementById('sam-needle-pivot');
      const lowAlert = document.getElementById('sam-low-fuel-alert');

      if (slider) slider.value = pct;
      if (hidden) hidden.value = pct;
      if (text) text.textContent = pct + '%';

      // Angle: from -80deg (0% E) to +80deg (100% F)
      const angle = -80 + (pct / 100) * 160;
      if (pivot) {
        pivot.style.transform = 'rotate(' + angle + 'deg)';
      }

      // Update preset buttons active state
      document.querySelectorAll('.sam-fuel-preset-btn').forEach(btn => {
        if (parseInt(btn.getAttribute('data-pct'), 10) === pct) {
          btn.classList.add('is-active');
          btn.style.background = '#0284c7';
          btn.style.color = '#fff';
        } else {
          btn.classList.remove('is-active');
          btn.style.background = '';
          btn.style.color = '';
        }
      });

      // Low fuel condition (<= 25%)
      if (pct <= 25) {
        if (badge) {
          badge.style.background = '#ef4444';
          badge.textContent = '⚠️ CRITICAL LOW FUEL: ' + pct + '% (≤ 1/4 TANK)';
        }
        if (lowAlert && lowAlert.style.display === 'none') {
          lowAlert.style.display = 'block';
          if (triggerChime) playFuelChime();
        }
      } else if (pct <= 50) {
        if (badge) {
          badge.style.background = '#f59e0b';
          badge.textContent = '● FUEL LEVEL: ' + pct + '% (1/2 TANK)';
        }
        if (lowAlert) lowAlert.style.display = 'none';
      } else {
        if (badge) {
          badge.style.background = '#10b981';
          badge.textContent = '✓ FUEL LEVEL: ' + pct + '% (OPTIMAL)';
        }
        if (lowAlert) lowAlert.style.display = 'none';
      }
    }

    // Fuel slider change
    const fuelSlider = document.getElementById('sam-fuel-slider');
    if (fuelSlider) {
      fuelSlider.addEventListener('input', function() {
        updateFuelDisplay(this.value, true);
      });
    }

    // Fuel preset buttons click
    document.querySelectorAll('.sam-fuel-preset-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        const val = this.getAttribute('data-pct');
        updateFuelDisplay(val, true);
      });
    });

    // Initialize fuel display (default 75%)
    updateFuelDisplay(75, false);

    // Vehicle Incident Report Toggle
    const incNoPill = document.getElementById('sam-incident-no-pill');
    const incYesPill = document.getElementById('sam-incident-yes-pill');
    const incBox = document.getElementById('sam-incident-details-box');
    if (incNoPill && incYesPill && incBox) {
      incNoPill.addEventListener('click', function() {
        const radioNo = this.querySelector('input[type="radio"]');
        if (radioNo) radioNo.checked = true;
        this.classList.add('is-active');
        incYesPill.classList.remove('is-active');
        this.style.background = '#dcfce7';
        this.style.color = '#166534';
        this.style.border = '1px solid #86efac';
        incYesPill.style.background = '';
        incYesPill.style.color = '#991b1b';
        incYesPill.style.border = '1px solid #fca5a5';
        incBox.style.display = 'none';
      });

      incYesPill.addEventListener('click', function() {
        const radioYes = this.querySelector('input[type="radio"]');
        if (radioYes) radioYes.checked = true;
        this.classList.add('is-active');
        incNoPill.classList.remove('is-active');
        this.style.background = '#fee2e2';
        this.style.color = '#991b1b';
        this.style.border = '1px solid #ef4444';
        incNoPill.style.background = '';
        incNoPill.style.color = '#166534';
        incNoPill.style.border = '1px solid #86efac';
        incBox.style.display = 'block';
        const descInput = document.getElementById('sam-incident-desc');
        if (descInput) descInput.focus();
      });
    }

    // Form Submission
    document.getElementById('mvaInCarEvalForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const form = this;

      // Validation: If incident reported, description is mandatory
      const hasInc = form.querySelector('input[name="has_vehicle_incident"]:checked');
      if (hasInc && hasInc.value === 'yes') {
        const desc = document.getElementById('sam-incident-desc');
        if (!desc || !desc.value.trim()) {
          alert('Please describe the vehicle malfunction or road incident before submitting the record.');
          if (desc) desc.focus();
          return;
        }
      }

      // Validation: If fuel <= 25%, confirm student refuel instruction
      const fuelLevel = parseInt(document.getElementById('sam-fuel-level-pct').value, 10) || 75;
      const refuelCheck = document.getElementById('sam-refuel-check');
      if (fuelLevel <= 25 && (!refuelCheck || !refuelCheck.checked)) {
        if (!confirm('CRITICAL LOW FUEL ALERT (≤ 25%):\n\nPlease confirm that you have conducted or scheduled the practical student refueling instruction module before returning the vehicle.\n\nDo you wish to proceed with submission?')) {
          return;
        }
      }

      const submitBtn = document.getElementById('sam-submit-btn');
      const feedback = document.getElementById('sam-feedback-alert');

      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving Evaluation Record & Dispatching Scorecard...';
      feedback.style.display = 'none';

      const formData = new FormData(form);
      const ajaxUrl = (window.DriveFlowPro && window.DriveFlowPro.ajaxurl) ? window.DriveFlowPro.ajaxurl : '/wp-admin/admin-ajax.php';

      fetch(ajaxUrl, {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(res => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save & Submit Evaluation Record';

        if (res && res.success) {
          feedback.className = 'sam-feedback-alert sam-feedback-success';
          feedback.innerHTML = '<strong>✓ Evaluation Saved Successfully!</strong> ' + (res.data.message || 'Record logged and student scorecard issued.') + ' [Status: ' + (res.data.evaluation || 'PASS') + ']';
          feedback.style.display = 'block';
          window.scrollTo({ top: feedback.offsetTop - 40, behavior: 'smooth' });
        } else {
          feedback.className = 'sam-feedback-alert sam-feedback-error';
          feedback.innerHTML = '<strong>✕ Submission Error:</strong> ' + ((res && res.data && res.data.message) || 'Failed to save record.');
          feedback.style.display = 'block';
        }
      })
      .catch(err => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save & Submit Evaluation Record';
        feedback.className = 'sam-feedback-alert sam-feedback-error';
        feedback.innerHTML = '<strong>✕ Network Error:</strong> Could not connect to WordPress server.';
        feedback.style.display = 'block';
      });
    });
  </script>
</div>
