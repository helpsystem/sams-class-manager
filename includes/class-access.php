<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Who can open which part of Sam's Class Manager.
 *
 *  - Every screen the plugin adds is a "feature" with its own capability.
 *  - Four roles: Administrator (the whole site — technical owner), School Manager (runs the school:
 *    every management screen, articles, classes, prices and discounts, WooCommerce orders, Tutor courses
 *    and Forminator entries; no pages/layout, plugins, themes, users, code, settings or API keys),
 *    Instructor and Front Desk.
 *    Instructor = the online-course instructor role when it exists (tutor_instructor), otherwise our own.
 *    Front Desk = new role for reception staff.
 *  - Sam's School → Team access: the administrator ticks which role may open which screen.
 *    Screens marked "Admin only" (API keys, settings, team access) can never be handed out.
 *  - The Sam's School menu is sorted into groups with headings, and each person only sees what they may open.
 */
class SDSC_Access {

	const VER       = 8;
	const MANAGER   = 'sdsc_manager';
	const FRONTDESK = 'sdsc_front_desk';
	const OWN_INSTR = 'sdsc_instructor';

	/** Menu groups in display order. */
	public static function groups() {
		return array(
			'students' => 'Students & classes',
			'website'  => 'Website',
			'sales'    => 'Prices & offers',
			'security' => 'Security',
			'setup'    => 'Setup',
			'help'     => 'Help',
		);
	}

	/**
	 * key => [label, group, capability, admin page slug, what it is for, default roles, locked to admins]
	 * Existing capabilities (banners, prices, API) are kept so nothing changes for people who already have them.
	 */
	public static function features() {
		return array(
			'registrations' => array( 'Registrations', 'students', 'sdsc_access_registrations', 'sdsc-registrations', 'Student sign-ups, contracts and signatures.', array( 'frontdesk' ), false ),
			'reports'       => array( 'Records & Reports', 'students', 'sdsc_access_reports', 'sdsc-reports', 'Full student records (including SSNs and signatures) to print and file.', array(), false ),
			'outreach'      => array( 'Celebrations & campaigns', 'students', 'sdsc_access_outreach', 'sdsc-outreach', 'Birthday and license-anniversary wishes, group e-mails and texts to students.', array( 'frontdesk' ), false ),
			'lessons'       => array( 'BTW lessons & fees', 'students', 'sdsc_access_lessons', 'sdsc-lessons', 'Behind-the-wheel bookings, confirmation e-mails, late-cancellation / no-show fees and waivers.', array( 'frontdesk' ), false ),
			'driveflow'     => array( 'Lesson calendar', 'students', 'driveflow_manage', 'admin.php?page=driveflow-pro-calendar', 'DriveFlow Pro: the lesson calendar, students and lesson credits, instructors, cars, evaluations, payouts and the lobby TV.', array(), false ),
			'texts'         => array( 'Texts to send', 'students', 'sdsc_access_texts', 'sdsc-texts', 'Reminders, confirmations and greetings to text from the school phone, one tap each.', array( 'frontdesk' ), false ),
			'attendance'    => array( 'Class attendance', 'students', 'sdsc_access_attendance', 'sdsc-attendance', 'Zoom class attendance sheet, confirmations and absence notices.', array( 'frontdesk', 'instructor' ), false ),
			'sms'           => array( 'Text messages', 'students', 'sdsc_access_sms', 'sdsc-sms', 'Texts sent through Twilio, their delivery status and a test text.', array( 'frontdesk' ), false ),
			'refunds'       => array( 'Refunds', 'sales', 'sdsc_access_refunds', 'sdsc-refunds', 'Full or prorated refunds by the refund policy, sent back to the card or recorded.', array(), false ),
			'notices'       => array( 'Banners & alerts', 'website', 'sdsc_manage_notices', 'sdsc-notices', 'Site banners, homepage pop-ups, holiday calendar and Montgomery County alerts.', array( 'frontdesk' ), false ),
			'feed'          => array( 'Website listing', 'website', 'sdsc_access_feed', 'sdsc-feed', 'Which classes show on the website and how.', array(), false ),
			'mva'           => array( 'MVA headlines', 'website', 'sdsc_access_mva', 'sdsc-mva', 'Daily MVA headlines; turn one into a draft article.', array( 'instructor', 'frontdesk' ), false ),
			'prices'        => array( 'Prices', 'sales', 'sdsc_manage_prices', 'sdsc-prices', 'Course and service prices used at checkout.', array(), false ),
			'discounts'     => array( 'Discounts', 'sales', 'sdsc_access_discounts', 'sdsc-discounts', 'Percentage discounts and their preview links.', array(), false ),
			'promo'         => array( 'Discount codes', 'sales', 'sdsc_access_discounts', 'sdsc-promo', 'Codes students type at registration (percent or dollars off, per course, dates, number of uses).', array(), false ),
			'tv'            => array( 'Lobby TV', 'website', 'sdsc_access_tv', 'sdsc-tv', 'The lobby TV: messages, ticker, view and remote reload.', array( 'frontdesk' ), false ),
			'blocklist'     => array( 'Blocked sign-ups', 'security', 'sdsc_access_blocklist', 'sdsc-blocklist', 'Spam e-mails and domains that may not create accounts, plus the bot-trap log.', array(), false ),
			'api'           => array( 'API connections', 'setup', 'sdsc_manage_api', 'sdsc-api', 'Stripe, Zoom and other keys.', array(), true ),
			'settings'      => array( 'Settings', 'setup', 'manage_options', 'sdsc-settings', 'General plugin settings.', array(), true ),
			'email'         => array( 'Email', 'setup', 'manage_options', 'sdsc-email', 'Mailboxes, delivery checks, test e-mail and send log.', array(), true ),
			'team'          => array( 'Team access', 'setup', 'manage_options', 'sdsc-team', 'Who can open which screen.', array(), true ),
			'updates'       => array( 'What’s new', 'help', 'read', 'sdsc-updates', 'Explanations of every change, written by the administrator.', array( 'instructor', 'frontdesk' ), true ),
			'tours'         => array( 'Guided tours', 'help', 'read', 'sdsc-tours', 'Step-by-step walkthroughs of every screen you can open.', array( 'instructor', 'frontdesk' ), true ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'setup' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_menu', array( __CLASS__, 'organise' ), 999 );
		add_action( 'admin_post_sdsc_team_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// Classes (dates, prices, Zoom links) are edited by the administrator and the school manager only.
		add_filter( 'map_meta_cap', array( __CLASS__, 'class_caps' ), 20, 4 );
		foreach ( array( 'load-edit.php', 'load-post-new.php', 'load-post.php' ) as $h ) { add_action( $h, array( __CLASS__, 'class_gate' ) ); }
		add_action( 'admin_menu', function () { if ( ! self::may_edit_classes() ) { remove_menu_page( 'edit.php?post_type=sdsc_class' ); } }, 1000 );
	}

	/** An instructor (or other helper) who is not administrator, school manager or front desk. */
	public static function plain_instructor() {
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) { return false; }
		$r = (array) wp_get_current_user()->roles;
		if ( array_intersect( $r, array( self::MANAGER, self::FRONTDESK ) ) ) { return false; }
		return (bool) array_intersect( $r, array( 'tutor_instructor', self::OWN_INSTR, 'driveflow_instructor' ) );
	}

	/** Who may add or change classes: administrator and school manager. */
	public static function may_edit_classes() {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		return is_user_logged_in() && in_array( self::MANAGER, (array) wp_get_current_user()->roles, true );
	}

	public static function class_caps( $caps, $cap, $uid, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post' ), true ) || empty( $args[0] ) ) { return $caps; }
		if ( 'sdsc_class' !== get_post_type( (int) $args[0] ) || (int) $uid !== get_current_user_id() || self::may_edit_classes() ) { return $caps; }
		return array( 'do_not_allow' );
	}

	public static function class_gate() {
		global $typenow;
		$pt = $typenow ? $typenow : ( isset( $_GET['post'] ) ? get_post_type( absint( $_GET['post'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'sdsc_class' === $pt && ! self::may_edit_classes() ) { wp_die( 'Classes are managed by the office (administrator and school manager).', 'Not allowed', array( 'response' => 403, 'back_link' => true ) ); }
	}

	/* ------------------------------------------------------------ roles */

	/** The role used for instructors on this site. */
	public static function instructor_role() {
		return get_role( 'tutor_instructor' ) ? 'tutor_instructor' : self::OWN_INSTR;
	}

	/** role key (admin|instructor|frontdesk) => WP role name */
	public static function roles() {
		return array(
			'manager'    => self::MANAGER,
			'instructor' => self::instructor_role(),
			'frontdesk'  => self::FRONTDESK,
		);
	}

	public static function role_label( $k ) {
		return array( 'admin' => 'Administrator', 'manager' => 'School Manager', 'instructor' => 'Instructor', 'frontdesk' => 'Front Desk' )[ $k ] ?? $k;
	}

	/**
	 * School Manager = Editor (pages, posts, media, comments) + WooCommerce Shop Manager (orders, products,
	 * coupons, reports) + course and form management where those plugins are installed.
	 * Never: plugins, themes, users, core settings, code snippets, updates, exporting the database.
	 */
	public static function manager_caps() {
		$caps = array();
		foreach ( array( 'editor', 'shop_manager' ) as $base ) {
			$r = get_role( $base );
			if ( $r ) { $caps = array_merge( $caps, $r->capabilities ); }
		}
		foreach ( array( 'list_users', 'manage_tutor', 'manage_tutor_instructor', 'tutor_instructor', 'manage_forminator', 'edit_tutor_course', 'read_tutor_course', 'publish_tutor_courses', 'edit_tutor_courses', 'edit_others_tutor_courses', 'read_private_tutor_courses', 'delete_tutor_courses', 'edit_tutor_lesson', 'edit_tutor_quiz' ) as $c ) {
			$caps[ $c ] = true;
		}
		// Hard "no" list, whatever the base roles contain. Pages (site layout) stay with the administrator.
		foreach ( array( 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'unfiltered_html', 'manage_options', 'activate_plugins', 'install_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins', 'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes', 'edit_theme_options', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'remove_users', 'update_core', 'export', 'import', 'unfiltered_upload', 'edit_files', 'wpcode_edit_snippets', 'wpcode_activate_snippets', 'wpcode_file_editor' ) as $c ) {
			unset( $caps[ $c ] );
		}
		$caps['read'] = true;
		return $caps;
	}

	public static function setup() {
		if ( (int) get_option( 'sdsc_access_ver', 0 ) >= self::VER ) { return; }
		if ( ! get_role( self::MANAGER ) ) {
			add_role( self::MANAGER, 'School Manager', self::manager_caps() );
		}
		if ( ! get_role( self::FRONTDESK ) ) {
			add_role( self::FRONTDESK, 'Front Desk', array( 'read' => true ) );
		}
		if ( ! get_role( 'tutor_instructor' ) && ! get_role( self::OWN_INSTR ) ) {
			add_role( self::OWN_INSTR, 'Instructor', array( 'read' => true ) );
		}
		$admin = get_role( 'administrator' );
		foreach ( self::features() as $k => $f ) {
			if ( in_array( $f[2], array( 'read', 'manage_options' ), true ) ) { continue; }
			if ( $admin ) { $admin->add_cap( $f[2] ); }
			if ( $f[6] ) { continue; }
			foreach ( self::roles() as $rk => $rname ) {
				$r = get_role( $rname );
				if ( $r && ( 'manager' === $rk || in_array( $rk, $f[5], true ) ) ) { $r->add_cap( $f[2] ); }
			}
		}
		update_option( 'sdsc_access_ver', self::VER );
	}

	/** Can the current user open this feature? */
	public static function can( $key ) {
		$f = self::features()[ $key ] ?? null;
		if ( ! $f ) { return current_user_can( 'manage_options' ); }
		if ( 'read' === $f[2] ) { return self::is_staff(); }
		return current_user_can( $f[2] ) || current_user_can( 'manage_options' );
	}

	/** Staff = administrators and anyone given at least one screen (students never are). */
	public static function is_staff() {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		foreach ( self::features() as $f ) {
			if ( ! in_array( $f[2], array( 'read', 'manage_options' ), true ) && current_user_can( $f[2] ) ) { return true; }
		}
		return false;
	}

	/** Features the current user can open, grouped. */
	public static function mine() {
		$out = array();
		foreach ( self::features() as $k => $f ) {
			if ( self::can( $k ) ) { $out[ $k ] = $f; }
		}
		return $out;
	}

	/* ------------------------------------------------------------ menu */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'Team access', 'Team access', 'manage_options', 'sdsc-team', array( __CLASS__, 'page' ) );
	}

	/** Runs after every screen has been added: gives each item its capability, sorts into groups, renames the menu. */
	public static function organise() {
		global $submenu, $menu;
		if ( empty( $submenu['sdsc-dashboard'] ) ) { return; }
		$feat  = self::features();
		$slugs = array();
		foreach ( $feat as $k => $f ) { $slugs[ $f[3] ] = $k; }
		$order = array_flip( array_keys( self::groups() ) );
		$fpos  = array_flip( array_keys( $feat ) );

		$items = $submenu['sdsc-dashboard'];
		foreach ( $items as $i => $it ) {
			if ( 'sdsc-dashboard' === $it[2] ) { $items[ $i ][0] = 'Overview'; }
			if ( isset( $slugs[ $it[2] ] ) ) {
				if ( ! self::can( $slugs[ $it[2] ] ) ) { unset( $items[ $i ] ); continue; }
				$f = $feat[ $slugs[ $it[2] ] ];
				$items[ $i ][1] = $f[2];
				// Plain English labels (one screen was still labelled in Persian).
				$badge = preg_match( '/<span.*$/s', $it[0], $m ) ? ' ' . $m[0] : '';
				$items[ $i ][0] = $f[0] . $badge;
			}
		}
		usort( $items, function ( $a, $b ) use ( $slugs, $feat, $order, $fpos ) {
			$ka = $slugs[ $a[2] ] ?? null;
			$kb = $slugs[ $b[2] ] ?? null;
			// The dashboard and screens this map does not know stay first, in their own order.
			$ga = $ka ? $order[ $feat[ $ka ][1] ] : -1;
			$gb = $kb ? $order[ $feat[ $kb ][1] ] : -1;
			if ( $ga !== $gb ) { return $ga - $gb; }
			return ( $ka ? $fpos[ $ka ] : 0 ) - ( $kb ? $fpos[ $kb ] : 0 );
		} );
		$submenu['sdsc-dashboard'] = array_values( $items ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		foreach ( (array) $menu as $i => $m ) {
			if ( isset( $m[2] ) && 'sdsc-dashboard' === $m[2] ) {
				// WordPress points the top item at the first screen the person may open, so staff without
				// access to the dashboard still get a working menu.
				$menu[ $i ][0] = "Sam's School"; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		}
	}

	public static function assets( $hook ) {
		if ( ! self::is_staff() ) { return; }
		$map = array();
		foreach ( self::features() as $k => $f ) { $map[ $f[3] ] = $f[1]; }
		wp_enqueue_style( 'sdsc-admin-ui', SDSC_URL . 'assets/admin-ui.css', array(), SDSC_VERSION );
		wp_enqueue_script( 'sdsc-admin-ui', SDSC_URL . 'assets/admin-ui.js', array(), SDSC_VERSION, true );
		wp_localize_script( 'sdsc-admin-ui', 'SDSC_MENU', array( 'groups' => self::groups(), 'map' => $map ) );
	}

	/* ------------------------------------------------------------ Team access page */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		$roles  = self::roles();
		$counts = count_users();
		?>
		<div class="wrap sdsc-team">
			<h1>Team access</h1>
			<p class="sdsc-lead">Choose which screens of <strong>Sam&rsquo;s School</strong> each role can open. Administrators always see everything. People only see the screens — and the guided tours — they are allowed to use.</p>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Access saved.</p></div><?php endif; ?>

			<div class="sdsc-team-roles">
				<?php foreach ( array( 'admin' => 'administrator' ) + $roles as $rk => $rname ) : $n = (int) ( $counts['avail_roles'][ $rname ] ?? 0 ); ?>
					<div class="sdsc-team-role sdsc-team-role--<?php echo esc_attr( $rk ); ?>">
						<span class="sdsc-team-dot"></span>
						<strong><?php echo esc_html( self::role_label( $rk ) ); ?></strong>
						<span><?php echo (int) $n; ?> <?php echo 1 === $n ? 'person' : 'people'; ?></span>
						<a href="<?php echo esc_url( admin_url( 'users.php?role=' . $rname ) ); ?>">View</a>
					</div>
				<?php endforeach; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sdsc_team_save">
				<?php wp_nonce_field( 'sdsc_team' ); ?>
				<table class="widefat sdsc-team-table" id="sdsc-team-matrix">
					<thead><tr><th>Screen</th><th>Administrator</th><?php foreach ( $roles as $rk => $rname ) : ?><th><?php echo esc_html( self::role_label( $rk ) ); ?></th><?php endforeach; ?></tr></thead>
					<tbody>
					<?php
					$last = '';
					foreach ( self::features() as $k => $f ) :
						if ( $f[1] !== $last ) :
							$last = $f[1];
							?>
							<tr class="sdsc-team-group"><th colspan="<?php echo 2 + count( $roles ); ?>"><?php echo esc_html( self::groups()[ $f[1] ] ); ?></th></tr>
						<?php endif; ?>
						<tr>
							<td><strong><?php echo esc_html( $f[0] ); ?></strong><br><span class="description"><?php echo esc_html( $f[4] ); ?></span></td>
							<td><span class="sdsc-team-yes" title="Always">&#10003;</span></td>
							<?php foreach ( $roles as $rk => $rname ) : $r = get_role( $rname ); ?>
								<td>
									<?php if ( 'read' === $f[2] ) : ?>
										<span class="sdsc-team-yes" title="Everyone">&#10003;</span>
									<?php elseif ( $f[6] ) : ?>
										<span class="sdsc-team-lock">Admin only</span>
									<?php elseif ( ! $r ) : ?>
										<span class="description">Role missing</span>
									<?php else : ?>
										<label class="sdsc-switch"><input type="checkbox" name="grant[<?php echo esc_attr( $rk ); ?>][]" value="<?php echo esc_attr( $k ); ?>"<?php checked( $r->has_cap( $f[2] ) ); ?>><span></span><em class="screen-reader-text"><?php echo esc_html( self::role_label( $rk ) . ': ' . $f[0] ); ?></em></label>
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">To make someone an Instructor or Front Desk member, open <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Users</a>, edit the person and change their <em>Role</em>. Changes take effect on their next page load.</p>
				<?php submit_button( 'Save access' ); ?>
			</form>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_team' );
		$grant = isset( $_POST['grant'] ) && is_array( $_POST['grant'] ) ? wp_unslash( $_POST['grant'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( self::roles() as $rk => $rname ) {
			$r = get_role( $rname );
			if ( ! $r ) { continue; }
			$on = array_map( 'sanitize_key', (array) ( $grant[ $rk ] ?? array() ) );
			foreach ( self::features() as $k => $f ) {
				if ( $f[6] || in_array( $f[2], array( 'read', 'manage_options' ), true ) ) { continue; }
				in_array( $k, $on, true ) ? $r->add_cap( $f[2] ) : $r->remove_cap( $f[2] );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-team&saved=1' ) );
		exit;
	}
}
