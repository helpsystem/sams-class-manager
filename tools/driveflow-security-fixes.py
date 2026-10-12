#!/usr/bin/env python3
"""DriveFlow Pro security fixes from the 2026-10-12 audit. Usage: security-fixes-2026-10-12.py <plugin-dir>
Idempotent. Works on the GitHub copy (manage_options) and on a copy whose admin cap was renamed to driveflow_manage.
1. Instructor onboarding can no longer reset the password / role of an existing account it is not linked to.
2. REST: listing and editing sessions is admin-only; instructors may only create sessions as themselves.
3. Magic-link self-booking counts already-booked lessons against the package and only accepts sane windows.
"""
import sys, re
d = sys.argv[1].rstrip('/')
F = d + '/driveflow-pro.php'
C = d + '/includes/class-credit-manager.php'
s = open(F).read()
cap = 'driveflow_manage' if "current_user_can('driveflow_manage')" in s else 'manage_options'
MARK = '/* sec-2026-10-12 */'
if MARK in s:
    print('driveflow-pro.php already patched'); sys.exit(0)

def rep(t, a, b):
    assert t.count(a) == 1, ('anchor not found once', a[:80], t.count(a))
    return t.replace(a, b)

# 1. onboarding account linking
s = rep(s, """            if ($existing_user) {
                $wp_user_id = $existing_user->ID;
                wp_set_password($password, $wp_user_id);
                wp_update_user(array(
                    'ID'           => $wp_user_id,
                    'display_name' => $name,
                    'first_name'   => $name,
                ));
                $existing_user->set_role('driveflow_instructor');
                $account_created = true;""", """            if ($existing_user) { """ + MARK + """
                // Only the account already linked to this instructor may be updated from an onboarding link.
                // Anything else would let a link holder take over someone else's account by typing their e-mail.
                if ((int) ($current['user_id'] ?? 0) !== (int) $existing_user->ID || user_can($existing_user, 'manage_options') || user_can($existing_user, '""" + cap + """')) {
                    wp_send_json_error('An account with this e-mail already exists. Please sign in to that account, or ask the school office to link it for you.');
                }
                $wp_user_id = $existing_user->ID;
                wp_set_password($password, $wp_user_id);
                wp_update_user(array(
                    'ID'           => $wp_user_id,
                    'display_name' => $name,
                    'first_name'   => $name,
                ));
                $existing_user->add_role('driveflow_instructor');
                $account_created = true;""")

# 2. REST permissions
s = rep(s, """            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_sessions'), 'permission_callback' => array($this, 'can_write')),""",
        """            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_sessions'), 'permission_callback' => array($this, 'can_manage_rest')),""")
s = rep(s, """            array('methods' => WP_REST_Server::EDITABLE, 'callback' => array($this, 'update_session'), 'permission_callback' => array($this, 'can_write')),""",
        """            array('methods' => WP_REST_Server::EDITABLE, 'callback' => array($this, 'update_session'), 'permission_callback' => array($this, 'can_manage_rest')),""")
s = rep(s, """    public function can_write($request) {
        // A wp_rest nonce is public for anonymous visitors; never accept it as authorization on its own.
        return is_user_logged_in() && current_user_can('edit_posts');
    }""", """    public function can_write($request) {
        // A wp_rest nonce is public for anonymous visitors; never accept it as authorization on its own.
        if (current_user_can('""" + cap + """')) {
            return true;
        }
        $me = $this->get_current_instructor();
        return $me && 'active' === $me['status'] && current_user_can('edit_posts');
    }

    /** Listing every session (with evaluations) and editing any session: school admins only. */
    public function can_manage_rest($request) {
        return current_user_can('""" + cap + """');
    }""")
s = rep(s, """        $data = $this->clean_session($request->get_json_params() ?: array());
        if (!$data['student_name']) {
            return new WP_Error('missing_student', 'Student name is required.', array('status' => 400));
        }""", """        $data = $this->clean_session($request->get_json_params() ?: array());
        if (!$data['student_name']) {
            return new WP_Error('missing_student', 'Student name is required.', array('status' => 400));
        }
        if (!current_user_can('""" + cap + """')) {
            $me = $this->get_current_instructor();
            $data['instructor_name'] = $me ? $me['name'] : '';
        }""")
open(F, 'w').write(s)

# 3. self-booking limits
c = open(C).read()
if MARK not in c:
    c = rep(c, """if ($profile['remaining_sessions'] <= 0)""", """$booked = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}driveflow_sessions WHERE student_id = %d AND status IN ('upcoming','active')", $profile['id'])); """ + MARK + """
        $ts_s = strtotime($start_time); $ts_e = strtotime($end_time); $now_l = current_time('timestamp');
        if (!$ts_s || !$ts_e || $ts_e <= $ts_s || ($ts_e - $ts_s) > 4 * HOUR_IN_SECONDS || $ts_s < $now_l || $ts_s > $now_l + 120 * DAY_IN_SECONDS) {
            return array('success' => false, 'message' => 'Please choose one of the times offered.');
        }
        if ($profile['remaining_sessions'] - $booked <= 0)""")
    open(C, 'w').write(c)
print('patched', cap)
