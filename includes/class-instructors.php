<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Instructors (Sam's School → Instructors): the administrator and the school manager add an instructor by
 * hand and can assign them to lessons and Zoom classes right away — before the instructor has filled the
 * onboarding form or signed the agreement. Each row shows what is still missing; the onboarding link can be
 * sent from here, and an agreement signed on paper can be recorded.
 *
 * An instructor is a DriveFlow instructor profile (calendar, in-car app, lobby TV). "Site login" also makes
 * a site account with the instructor role, which the Zoom class screen and the attendance sheet need; no
 * password is e-mailed — the instructor sets it from the onboarding link or "Forgot password".
 */
class SDSC_Instructors {

	const PAGE = 'sdsc-instructors';
	const META = 'sdsc_instr_meta';   // DriveFlow id => [ langs[], paper_at, paper_by, note ]

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 28 );
		foreach ( array( 'add', 'save', 'paper', 'state' ) as $a ) { add_action( 'admin_post_sdsc_ins_' . $a, array( __CLASS__, 'h_' . $a ) ); }
	}

	public static function can() {
		if ( ! class_exists( 'SDSC_DriveFlow' ) || ! SDSC_DriveFlow::on() ) { return false; }
		return class_exists( 'SDSC_Board' ) ? SDSC_Board::can() : current_user_can( 'manage_options' );
	}

	public static function url( $args = array() ) { return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) ); }

	public static function menu() {
		if ( ! self::can() ) { return; }
		add_submenu_page( 'sdsc-dashboard', 'Instructors', 'Instructors', 'read', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function meta_all() { $m = get_option( self::META, array() ); return is_array( $m ) ? $m : array(); }

	protected static function set_meta( $id, $vals ) {
		$m        = self::meta_all();
		$m[ $id ] = array_merge( (array) ( $m[ $id ] ?? array() ), $vals );
		update_option( self::META, $m, false );
	}

	protected static function t() { global $wpdb; return $wpdb->prefix . 'driveflow_instructors'; }

	protected static function langs() { return array( 'EN' => 'English', 'ES' => 'Spanish', 'FA' => 'Farsi' ); }

	protected static function guard( $n ) {
		if ( ! self::can() ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( $n );
	}

	protected static function back( $msg, $err = false ) {
		wp_safe_redirect( self::url( array( $err ? 'err' : 'msg' => rawurlencode( $msg ) ) ) );
		exit;
	}

	protected static function posted() {
		$p = wp_unslash( $_POST ); // phpcs:ignore -- sanitised below
		return array(
			'first' => sanitize_text_field( $p['first'] ?? '' ),
			'last'  => sanitize_text_field( $p['last'] ?? '' ),
			'email' => strtolower( sanitize_email( $p['email'] ?? '' ) ),
			'phone' => sanitize_text_field( $p['phone'] ?? '' ),
			'lic'   => sanitize_text_field( mb_substr( (string) ( $p['lic'] ?? '' ), 0, 100 ) ),
			'car'   => absint( $p['car'] ?? 0 ),
			'langs' => array_values( array_intersect( array_keys( self::langs() ), (array) ( $p['langs'] ?? array() ) ) ),
			'note'  => sanitize_text_field( mb_substr( (string) ( $p['note'] ?? '' ), 0, 300 ) ),
		);
	}

	/** A site account with the instructor role (existing account by e-mail is given the role). */
	protected static function login_for( $email, $first, $last ) {
		if ( ! is_email( $email ) ) { return 0; }
		$role = class_exists( 'SDSC_Access' ) ? SDSC_Access::instructor_role() : 'tutor_instructor';
		$u    = get_user_by( 'email', $email );
		if ( $u ) {
			if ( array_intersect( (array) $u->roles, array( 'administrator', class_exists( 'SDSC_Access' ) ? SDSC_Access::MANAGER : 'sdsc_manager' ) ) ) { return (int) $u->ID; }
			if ( ! in_array( $role, (array) $u->roles, true ) ) { $u->add_role( $role ); }
			return (int) $u->ID;
		}
		$login = sanitize_user( strtolower( $first . ( $last ? '.' . $last : '' ) ), true );
		if ( ! $login || username_exists( $login ) ) { $login = sanitize_user( strtok( $email, '@' ), true ) . wp_rand( 10, 99 ); }
		$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ), 'role' => $role ) );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	public static function h_add() {
		self::guard( 'sdsc_ins_add' );
		global $wpdb;
		$d = self::posted();
		if ( ! $d['first'] ) { self::back( 'Enter the instructor’s first name.', true ); }
		if ( ! is_email( $d['email'] ) && strlen( preg_replace( '/\D/', '', $d['phone'] ) ) < 10 ) { self::back( 'Enter an e-mail or a mobile number, so the instructor can get lessons and the onboarding link.', true ); }
		if ( $d['email'] && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . " WHERE LOWER(email) = %s AND status = 'active'", $d['email'] ) ) ) { self::back( 'An active instructor with this e-mail already exists.', true ); } // phpcs:ignore
		$now  = current_time( 'mysql' );
		$name = trim( $d['first'] . ' ' . $d['last'] );
		$uid  = ! empty( $_POST['login'] ) ? self::login_for( $d['email'], $d['first'], $d['last'] ) : 0; // phpcs:ignore
		$wpdb->insert( self::t(), array( 'user_id' => $uid, 'name' => $name, 'email' => $d['email'], 'phone' => $d['phone'], 'license_number' => $d['lic'], 'default_vehicle_id' => $d['car'], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { self::back( 'The instructor could not be saved.', true ); }
		self::set_meta( $id, array( 'langs' => $d['langs'], 'note' => $d['note'], 'added_by' => get_current_user_id(), 'added_at' => $now ) );
		if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
		$msg = $name . ' added. They can be assigned to lessons now.';
		if ( $uid ) { $msg .= ' Site login created (for Zoom classes and attendance).'; }
		if ( ! empty( $_POST['link'] ) && class_exists( 'SDSC_Onboard' ) && SDSC_Onboard::on() ) { $msg .= ' ' . SDSC_Onboard::send( $id ); } // phpcs:ignore
		self::back( $msg );
	}

	public static function h_save() {
		self::guard( 'sdsc_ins_save' );
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
		$r  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $r ) { self::back( 'Instructor not found.', true ); }
		$d   = self::posted();
		$up  = array( 'name' => trim( $d['first'] . ' ' . $d['last'] ) ?: $r['name'], 'email' => $d['email'], 'phone' => $d['phone'], 'license_number' => $d['lic'], 'default_vehicle_id' => $d['car'], 'updated_at' => current_time( 'mysql' ) );
		if ( ! empty( $_POST['login'] ) && ! (int) $r['user_id'] ) { $up['user_id'] = self::login_for( $d['email'], $d['first'], $d['last'] ); } // phpcs:ignore
		$wpdb->update( self::t(), $up, array( 'id' => $id ) );
		self::set_meta( $id, array( 'langs' => $d['langs'], 'note' => $d['note'] ) );
		if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
		self::back( $up['name'] . ' saved.' );
	}

	/** The instructor signed the agreement on paper: record it (the online signature is no longer asked for). */
	public static function h_paper() {
		self::guard( 'sdsc_ins_paper' );
		global $wpdb;
		$id   = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_POST['date'] ?? '' ) ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : current_time( 'Y-m-d' ); // phpcs:ignore
		$wpdb->update( self::t(), array( 'agreement_signed' => 1, 'agreement_signed_at' => $date . ' 12:00:00', 'agreement_ip' => 'paper', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		self::set_meta( $id, array( 'paper_at' => $date, 'paper_by' => get_current_user_id() ) );
		self::back( 'Agreement recorded as signed on paper (' . $date . '). Keep the signed copy in the instructor’s file.' );
	}

	public static function h_state() {
		self::guard( 'sdsc_ins_state' );
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
		$st = 'inactive' === ( $_POST['to'] ?? '' ) ? 'inactive' : 'active'; // phpcs:ignore
		$wpdb->update( self::t(), array( 'status' => $st, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		if ( method_exists( 'DriveFlow_Pro', 'notify_calendar_change' ) ) { DriveFlow_Pro::notify_calendar_change(); }
		self::back( 'inactive' === $st ? 'Instructor switched off: no new lessons can be assigned to them. Their lessons and records are kept.' : 'Instructor switched on again.' );
	}

	/* ------------------------------------------------------------------ page */

	protected static function form( $act, $r = array(), $m = array() ) {
		global $wpdb;
		$cars  = (array) $wpdb->get_results( "SELECT id, plate_number, model FROM {$wpdb->prefix}driveflow_vehicles WHERE status = 'active' ORDER BY plate_number", ARRAY_A ); // phpcs:ignore
		$parts = preg_split( '/\s+/', trim( (string) ( $r['name'] ?? '' ) ), 2 );
		$out   = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sdin-form">' . wp_nonce_field( 'sdsc_ins_' . $act, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="sdsc_ins_' . $act . '">';
		if ( $r ) { $out .= '<input type="hidden" name="id" value="' . (int) $r['id'] . '">'; }
		$f = function ( $label, $html ) { return '<label><span>' . esc_html( $label ) . '</span>' . $html . '</label>'; };
		$out .= '<div class="sdin-grid">'
			. $f( 'First name', '<input name="first" required value="' . esc_attr( $parts[0] ?? '' ) . '">' )
			. $f( 'Last name', '<input name="last" value="' . esc_attr( $parts[1] ?? '' ) . '">' )
			. $f( 'E-mail', '<input type="email" name="email" value="' . esc_attr( $r['email'] ?? '' ) . '">' )
			. $f( 'Mobile', '<input type="tel" name="phone" value="' . esc_attr( $r['phone'] ?? '' ) . '" placeholder="(301) 555-0123">' )
			. $f( 'MVA instructor license #', '<input name="lic" value="' . esc_attr( $r['license_number'] ?? '' ) . '">' );
		$o = '<option value="0">—</option>';
		foreach ( $cars as $c ) { $o .= '<option value="' . (int) $c['id'] . '"' . selected( (int) ( $r['default_vehicle_id'] ?? 0 ), (int) $c['id'], false ) . '>' . esc_html( $c['plate_number'] . ' · ' . $c['model'] ) . '</option>'; }
		$out .= $f( 'Usual car', '<select name="car">' . $o . '</select>' );
		$l = '';
		foreach ( self::langs() as $k => $v ) { $l .= '<label class="sdin-tick"><input type="checkbox" name="langs[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, (array) ( $m['langs'] ?? array( 'EN' ) ), true ), true, false ) . '> ' . esc_html( $v ) . '</label> '; }
		$out .= '<div class="sdin-langs"><span>Teaches in</span>' . $l . '</div>';
		$out .= $f( 'Note for the office', '<input name="note" value="' . esc_attr( $m['note'] ?? '' ) . '" maxlength="300">' ) . '</div>';
		if ( ! $r || ! (int) $r['user_id'] ) { $out .= '<label class="sdin-tick"><input type="checkbox" name="login" value="1"' . checked( ! $r, true, false ) . '> Create a site login (needed for Zoom classes and the attendance sheet; no password is e-mailed)</label>'; }
		if ( ! $r ) { $out .= '<label class="sdin-tick"><input type="checkbox" name="link" value="1" checked> Send the onboarding link now (profile, license, payment details, agreement)</label>'; }
		return $out . '<p><button class="button button-primary">' . ( $r ? 'Save' : 'Add the instructor' ) . '</button></p></form>';
	}

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::t() . " WHERE email NOT LIKE '%@preview.samsdrivingschool.invalid' ORDER BY status = 'active' DESC, name", ARRAY_A ); // phpcs:ignore
		$meta = self::meta_all();
		$sent = class_exists( 'SDSC_Onboard' ) ? SDSC_Onboard::sent() : array();
		$cars = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, plate_number FROM {$wpdb->prefix}driveflow_vehicles", ARRAY_A ) as $c ) { $cars[ (int) $c['id'] ] = $c['plate_number']; } // phpcs:ignore
		$next = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT instructor_id, COUNT(*) n, MIN(scheduled_start) s FROM ' . SDSC_DriveFlow::t() . " WHERE status IN ('upcoming','active') AND scheduled_start >= %s GROUP BY instructor_id", current_time( 'mysql' ) ), ARRAY_A ) as $x ) { $next[ (int) $x['instructor_id'] ] = $x; } // phpcs:ignore
		$msg = sanitize_text_field( wp_unslash( $_GET['msg'] ?? '' ) ); // phpcs:ignore
		$err = sanitize_text_field( wp_unslash( $_GET['err'] ?? '' ) ); // phpcs:ignore
		?>
		<div class="wrap sdin">
			<style>
				.sdin{max-width:1200px}.sdin h1{font-weight:800}.sdin .lead{color:#5b646d;margin:0 0 14px}
				.sdin-card{background:#fff;border:1px solid #e3e7ea;border-radius:12px;padding:14px 16px;margin:0 0 14px}
				.sdin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px 12px;margin:0 0 8px}
				.sdin-grid label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:#334155}.sdin-grid input,.sdin-grid select{min-height:34px;font-weight:400}
				.sdin-langs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12px;font-weight:700;color:#334155}.sdin-langs>span{width:100%}
				.sdin-tick{display:flex;gap:6px;align-items:center;font-size:13px;margin:4px 0;font-weight:400}
				table.sdin-t{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e3e7ea;border-radius:12px;overflow:hidden}
				.sdin-t th,.sdin-t td{padding:9px 10px;border-bottom:1px solid #eef1f3;text-align:left;vertical-align:top;font-size:13px}.sdin-t th{background:#f6f8f9;color:#5b646d;font-size:12px}
				.sdin-chip{display:inline-block;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:700}
				.sdin-ok{background:#e3f4ea;color:#0b5d2e}.sdin-wait{background:#fff3c4;color:#6b5000}.sdin-off{background:#eef1f3;color:#5b646d}
				.sdin-mut{color:#5b646d;font-size:12px}.sdin details{margin-top:6px}.sdin summary{cursor:pointer;color:#00703c;font-weight:600}
				.sdin-acts{display:flex;flex-wrap:wrap;gap:6px}.sdin-acts form{margin:0}
				tr.is-off td{opacity:.6}
			</style>
			<h1>Instructors</h1>
			<p class="lead">Add an instructor by hand and assign them to lessons and Zoom classes right away. Until they finish onboarding (profile, license, payment details, signed agreement) they show as &ldquo;Can teach — onboarding pending&rdquo;, and you can send or re-send their link here.</p>
			<?php if ( $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
			<?php if ( $err ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div><?php endif; ?>

			<details class="sdin-card" <?php echo $rows ? '' : 'open'; ?>><summary>+ Add an instructor</summary><?php echo self::form( 'add' ); // phpcs:ignore -- escaped in form() ?></details>

			<table class="sdin-t">
				<tr><th>Instructor</th><th>Status</th><th>License · car · languages</th><th>Upcoming lessons</th><th>Actions</th></tr>
				<?php foreach ( $rows as $r ) :
					$m    = $meta[ $r['id'] ] ?? array();
					$miss = class_exists( 'SDSC_Onboard' ) ? SDSC_Onboard::missing( $r ) : array();
					$off  = 'active' !== $r['status'];
					$nx   = $next[ (int) $r['id'] ] ?? null;
					?>
					<tr class="<?php echo $off ? 'is-off' : ''; ?>">
						<td><b><?php echo esc_html( $r['name'] ); ?></b><div class="sdin-mut"><?php echo esc_html( trim( $r['email'] . ' · ' . $r['phone'], ' ·' ) ); ?></div><div class="sdin-mut"><?php echo (int) $r['user_id'] ? 'Site login ✓' : 'No site login'; ?></div></td>
						<td><?php
						if ( $off ) { echo '<span class="sdin-chip sdin-off">Switched off</span>'; }
						elseif ( $miss ) { echo '<span class="sdin-chip sdin-wait">Can teach — onboarding pending</span><div class="sdin-mut">Missing: ' . esc_html( implode( ', ', $miss ) ) . '</div>'; $ls = $sent[ (int) $r['id'] ] ?? null; echo '<div class="sdin-mut">' . ( $ls ? 'Link sent ' . esc_html( human_time_diff( $ls[0] ) ) . ' ago' : 'No link sent yet' ) . '</div>'; }
						else { echo '<span class="sdin-chip sdin-ok">Ready</span>'; }
						if ( ! empty( $m['paper_at'] ) ) { echo '<div class="sdin-mut">Agreement signed on paper ' . esc_html( $m['paper_at'] ) . '</div>'; }
						elseif ( (int) $r['agreement_signed'] && $r['agreement_signed_at'] ) { echo '<div class="sdin-mut">Agreement signed online ' . esc_html( wp_date( 'M j, Y', strtotime( $r['agreement_signed_at'] ) ) ) . '</div>'; }
						?></td>
						<td><?php echo esc_html( $r['license_number'] ? $r['license_number'] : 'License # —' ); ?><div class="sdin-mut"><?php echo esc_html( ( $cars[ (int) $r['default_vehicle_id'] ] ?? 'No usual car' ) . ' · ' . ( $m['langs'] ? implode( '/', (array) $m['langs'] ) : 'languages —' ) ); ?></div><?php if ( ! empty( $m['note'] ) ) : ?><div class="sdin-mut"><?php echo esc_html( $m['note'] ); ?></div><?php endif; ?></td>
						<td><?php echo $nx ? (int) $nx['n'] . ' · next ' . esc_html( wp_date( 'D M j, g:i A', strtotime( $nx['s'] ) ) ) : '<span class="sdin-mut">none</span>'; ?></td>
						<td>
							<div class="sdin-acts">
								<?php if ( ! $off && $miss && class_exists( 'SDSC_Onboard' ) && SDSC_Onboard::on() ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sdsc_onboard_send' ); ?><input type="hidden" name="action" value="sdsc_onboard_send"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>"><button class="button"><?php echo isset( $sent[ (int) $r['id'] ] ) ? 'Send link again' : 'Send onboarding link'; ?></button></form>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sdsc_ins_state' ); ?><input type="hidden" name="action" value="sdsc_ins_state"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>"><input type="hidden" name="to" value="<?php echo $off ? 'active' : 'inactive'; ?>"><button class="button"><?php echo $off ? 'Switch on' : 'Switch off'; ?></button></form>
							</div>
							<?php if ( ! $off && ! (int) $r['agreement_signed'] ) : ?>
								<details><summary>Signed the agreement on paper</summary>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sdsc_ins_paper' ); ?><input type="hidden" name="action" value="sdsc_ins_paper"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>"><input type="date" name="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"> <button class="button">Record</button></form>
								</details>
							<?php endif; ?>
							<details><summary>Edit</summary><?php echo self::form( 'save', $r, $m ); // phpcs:ignore -- escaped in form() ?></details>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><tr><td colspan="5" class="sdin-mut">No instructors yet.</td></tr><?php endif; ?>
			</table>
			<p class="sdin-mut">Switching an instructor off keeps their lessons, evaluations and pay records; it only stops new assignments. To assign an instructor to a Zoom class, they need a site login (tick it when adding or editing).</p>
		</div>
		<?php
	}
}
