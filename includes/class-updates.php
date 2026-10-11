<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * "What's new": the administrator explains each change to the team, inside their own panel.
 *
 *  - Administrator writes an update (title, message, who it is for) on Sam's School → What's new.
 *    Optionally a copy is e-mailed to those people in the school's e-mail layout (from noreply@).
 *  - Each person sees a red counter on the menu, a banner on their dashboard screens until they read it,
 *    and a "What's new" box on the WordPress dashboard. Read status is kept per person.
 */
class SDSC_Updates {

	const OPT  = 'sdsc_updates';
	const READ = 'sdsc_updates_read';
	const SEED = 1;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 43 );
		add_action( 'admin_menu', array( __CLASS__, 'badge' ), 1000 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'widget' ) );
		add_action( 'admin_post_sdsc_update_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sdsc_update_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_init', array( __CLASS__, 'seed' ) );
	}

	public static function all() {
		$u = get_option( self::OPT, array() );
		return is_array( $u ) ? $u : array();
	}

	/** Updates addressed to the current person (administrators see everything). */
	public static function mine() {
		$u     = wp_get_current_user();
		$admin = current_user_can( 'manage_options' );
		return array_values( array_filter( self::all(), function ( $x ) use ( $u, $admin ) {
			if ( $admin ) { return true; }
			return (bool) array_intersect( (array) $x['roles'], self::role_keys( $u ) );
		} ) );
	}

	protected static function role_keys( $u ) {
		$k = array();
		foreach ( SDSC_Access::roles() as $key => $wp ) {
			if ( in_array( $wp, (array) $u->roles, true ) ) { $k[] = $key; }
		}
		return $k;
	}

	public static function unread() {
		$last = (int) get_user_meta( get_current_user_id(), self::READ, true );
		$n    = 0;
		foreach ( self::mine() as $x ) {
			if ( (int) $x['id'] > $last && (int) $x['author'] !== get_current_user_id() ) { $n++; }
		}
		return $n;
	}

	/* ------------------------------------------------------------ menu & notices */

	public static function menu() {
		add_submenu_page( 'sdsc-dashboard', 'What’s new', 'What’s new', 'read', 'sdsc-updates', array( __CLASS__, 'page' ) );
	}

	public static function badge() {
		global $submenu, $menu;
		if ( ! SDSC_Access::is_staff() ) {
			foreach ( (array) ( $submenu['sdsc-dashboard'] ?? array() ) as $i => $it ) {
				if ( 'sdsc-updates' === $it[2] ) { unset( $submenu['sdsc-dashboard'][ $i ] ); } // phpcs:ignore
			}
			return;
		}
		$n = self::unread();
		if ( ! $n ) { return; }
		$b = ' <span class="update-plugins count-' . $n . '"><span class="plugin-count">' . $n . '</span></span>';
		foreach ( (array) ( $submenu['sdsc-dashboard'] ?? array() ) as $i => $it ) {
			if ( 'sdsc-updates' === $it[2] ) { $submenu['sdsc-dashboard'][ $i ][0] .= $b; } // phpcs:ignore
		}
		foreach ( (array) $menu as $i => $m ) {
			if ( isset( $m[2] ) && 'sdsc-dashboard' === $m[2] ) { $menu[ $i ][0] .= $b; } // phpcs:ignore
		}
	}

	public static function notice() {
		if ( ! SDSC_Access::is_staff() || 'sdsc-updates' === ( $_GET['page'] ?? '' ) ) { return; } // phpcs:ignore
		$n = self::unread();
		if ( ! $n ) { return; }
		$x = self::mine()[0];
		echo '<div class="notice sdsc-upd-notice"><p><span class="sdsc-upd-dot"></span><b>' . ( 1 === $n ? 'New update' : esc_html( $n . ' new updates' ) ) . ':</b> ' . esc_html( $x['title'] ) . ' &nbsp; <a class="button button-small button-primary" href="' . esc_url( admin_url( 'admin.php?page=sdsc-updates' ) ) . '">Read</a></p></div>';
	}

	public static function widget() {
		if ( ! SDSC_Access::is_staff() ) { return; }
		wp_add_dashboard_widget( 'sdsc_updates_widget', 'What’s new at Sam’s School', function () {
			$list = array_slice( self::mine(), 0, 4 );
			if ( ! $list ) { echo '<p>No updates yet.</p>'; return; }
			echo '<ul class="sdsc-upd-mini">';
			foreach ( $list as $x ) { echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=sdsc-updates#u' . (int) $x['id'] ) ) . '">' . esc_html( $x['title'] ) . '</a><span>' . esc_html( wp_date( 'M j', $x['t'] ) ) . '</span></li>'; }
			echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=sdsc-updates' ) ) . '">All updates &rarr;</a></p>';
		} );
	}

	/* ------------------------------------------------------------ page */

	public static function page() {
		if ( ! SDSC_Access::is_staff() ) { wp_die( 'Not allowed.' ); }
		$list  = self::mine();
		$last  = (int) get_user_meta( get_current_user_id(), self::READ, true );
		$admin = current_user_can( 'manage_options' );
		$edit  = $admin && isset( $_GET['edit'] ) ? self::find( (int) $_GET['edit'] ) : null; // phpcs:ignore
		// Opening the page marks everything as read.
		if ( $list ) { update_user_meta( get_current_user_id(), self::READ, max( array_map( function ( $x ) { return (int) $x['id']; }, $list ) ) ); }
		?>
		<div class="wrap sdsc-upd">
			<h1>What&rsquo;s new</h1>
			<?php if ( isset( $_GET['msg'] ) ) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); // phpcs:ignore ?></p></div><?php endif; ?>
			<p class="sdsc-lead"><?php echo $admin ? 'Explain each change to your team. They see it in their own panel with a counter until they have read it.' : 'Changes to the school&rsquo;s website and tools, explained. New items are marked until you open this page.'; ?></p>

			<?php if ( $admin ) : ?>
				<details class="sdsc-upd-new" id="sdsc-upd-form"<?php echo $edit ? ' open' : ''; ?>>
					<summary><?php echo $edit ? 'Edit update' : '+ Write an update'; ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="sdsc_update_save"><?php wp_nonce_field( 'sdsc_update' ); ?>
						<input type="hidden" name="id" value="<?php echo (int) ( $edit['id'] ?? 0 ); ?>">
						<p><label><b>Title</b><br><input type="text" name="title" class="large-text" required value="<?php echo esc_attr( $edit['title'] ?? '' ); ?>" placeholder="e.g. New Team access screen"></label></p>
						<div><b>Message</b><?php wp_editor( $edit['body'] ?? '', 'sdsc_update_body', array( 'textarea_name' => 'body', 'media_buttons' => false, 'teeny' => true, 'textarea_rows' => 8 ) ); ?></div>
						<p><b>For</b><br>
							<?php foreach ( SDSC_Access::roles() as $k => $wp ) : ?>
								<label style="margin-right:16px"><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $k ); ?>"<?php checked( in_array( $k, (array) ( $edit['roles'] ?? array( 'manager' ) ), true ) ); ?>> <?php echo esc_html( SDSC_Access::role_label( $k ) ); ?></label>
							<?php endforeach; ?>
						</p>
						<?php if ( ! $edit ) : ?><p><label><input type="checkbox" name="mail" value="1"> Also e-mail a copy to them (from noreply@, in the school&rsquo;s e-mail layout)</label></p><?php endif; ?>
						<?php submit_button( $edit ? 'Save changes' : 'Publish update', 'primary', 'submit', false ); ?>
						<?php if ( $edit ) : ?> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-updates' ) ); ?>">Cancel</a><?php endif; ?>
					</form>
				</details>
			<?php endif; ?>

			<div class="sdsc-upd-list" id="sdsc-upd-list">
				<?php if ( ! $list ) : ?><p>No updates yet.</p><?php endif; ?>
				<?php foreach ( $list as $x ) : $new = (int) $x['id'] > $last && (int) $x['author'] !== get_current_user_id(); $au = get_userdata( (int) $x['author'] ); ?>
					<article class="sdsc-upd-item<?php echo $new ? ' is-new' : ''; ?>" id="u<?php echo (int) $x['id']; ?>">
						<header>
							<?php if ( $new ) : ?><span class="sdsc-upd-new-tag">New</span><?php endif; ?>
							<h2><?php echo esc_html( $x['title'] ); ?></h2>
							<p class="sdsc-upd-meta"><?php echo esc_html( wp_date( 'F j, Y · g:i a', $x['t'] ) ); ?><?php echo $au ? ' · ' . esc_html( $au->display_name ) : ''; ?>
								<?php if ( $admin ) : ?> · For: <?php echo esc_html( implode( ', ', array_map( array( 'SDSC_Access', 'role_label' ), (array) $x['roles'] ) ) ); ?><?php endif; ?></p>
						</header>
						<div class="sdsc-upd-body"><?php echo wp_kses_post( wpautop( $x['body'] ) ); ?></div>
						<?php if ( $admin ) : ?>
							<p class="sdsc-upd-actions"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sdsc-updates&edit=' . (int) $x['id'] . '#sdsc-upd-form' ) ); ?>">Edit</a> · <a class="sdsc-del" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_update_delete&id=' . (int) $x['id'] ), 'sdsc_update' ) ); ?>" onclick="return confirm('Delete this update?')">Delete</a></p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	protected static function find( $id ) {
		foreach ( self::all() as $x ) { if ( (int) $x['id'] === $id ) { return $x; } }
		return null;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_update' );
		$all   = self::all();
		$id    = (int) ( $_POST['id'] ?? 0 );
		$roles = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ?? array() ) ), array_keys( SDSC_Access::roles() ) ) );
		$item  = array(
			'title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'body'  => wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) ),
			'roles' => $roles ?: array( 'manager' ),
		);
		if ( $id ) {
			foreach ( $all as $i => $x ) { if ( (int) $x['id'] === $id ) { $all[ $i ] = array_merge( $x, $item ); } }
			$msg = 'Update saved.';
		} else {
			$item['id']     = (int) get_option( 'sdsc_updates_next', 1 );
			$item['t']      = time();
			$item['author'] = get_current_user_id();
			update_option( 'sdsc_updates_next', $item['id'] + 1, false );
			array_unshift( $all, $item );
			$msg = 'Update published.';
			if ( ! empty( $_POST['mail'] ) && class_exists( 'SDSC_Mailer' ) ) {
				$n = self::email( $item );
				$msg .= ' E-mailed to ' . $n . ' ' . ( 1 === $n ? 'person' : 'people' ) . '.';
			}
		}
		update_option( self::OPT, $all, false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-updates&msg=' . rawurlencode( $msg ) ) );
		exit;
	}

	protected static function email( $item ) {
		$to = array();
		foreach ( $item['roles'] as $k ) {
			$wp = SDSC_Access::roles()[ $k ] ?? '';
			if ( ! $wp ) { continue; }
			foreach ( get_users( array( 'role' => $wp, 'fields' => array( 'user_email' ) ) ) as $u ) { $to[] = $u->user_email; }
		}
		$to   = array_unique( array_filter( $to ) );
		$link = admin_url( 'admin.php?page=sdsc-updates#u' . (int) $item['id'] );
		foreach ( $to as $addr ) {
			SDSC_Mailer::send( $addr, 'Update: ' . $item['title'], '<p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#00703c">What’s new at Sam’s School</p><h2 style="margin:0 0 14px;font-size:22px;color:#15191d">' . esc_html( $item['title'] ) . '</h2>' . wpautop( $item['body'] ) . '<p style="margin:22px 0"><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#00703c;color:#ffffff;font-weight:700;text-decoration:none">Open in your dashboard</a></p>', 'noreply' );
		}
		return count( $to );
	}

	public static function delete() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'sdsc_update' );
		$id = (int) ( $_GET['id'] ?? 0 );
		update_option( self::OPT, array_values( array_filter( self::all(), function ( $x ) use ( $id ) { return (int) $x['id'] !== $id; } ) ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-updates&msg=' . rawurlencode( 'Update deleted.' ) ) );
		exit;
	}

	/** First update: a summary of today's changes for the manager. */
	public static function seed() {
		if ( (int) get_option( 'sdsc_updates_seed', 0 ) >= self::SEED || self::all() ) { update_option( 'sdsc_updates_seed', self::SEED ); return; }
		$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
		$body   = '<p>Your account is now a <b>School Manager</b>: everything you need to run the school, without the technical settings.</p>'
			. '<ul><li><b>Sam’s School menu</b> — all school tools in one place, sorted into groups.</li>'
			. '<li><b>Records &amp; Reports</b> — print and file full student records. They contain SSNs and signatures: keep paper copies locked away.</li>'
			. '<li><b>Driving Tips &amp; News</b> — articles are now organised into topics. Pick a topic and, in <i>Article details</i>, the level and language.</li>'
			. '<li><b>Banners &amp; alerts</b> — holiday banners, homepage pop-ups and Montgomery County alerts (alerts appear on the site only after you press <i>Show on website</i>).</li>'
			. '<li><b>Guided tours</b> — press <i>Take the tour</i> at the bottom of any screen, or open <i>Help &amp; tours</i> in the top bar.</li></ul>'
			. '<p>New explanations will appear here whenever something changes.</p>';
		update_option( self::OPT, array( array( 'id' => 1, 't' => time(), 'author' => $admins ? (int) $admins[0] : 0, 'title' => 'Welcome to your new dashboard', 'body' => $body, 'roles' => array( 'manager' ) ) ), false );
		update_option( 'sdsc_updates_next', 2, false );
		update_option( 'sdsc_updates_seed', self::SEED );
	}
}
