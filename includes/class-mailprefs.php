<?php
/**
 * My e-mails — each member of the school team chooses which e-mails reach them. Works for every e-mail the
 * site sends (school reports, DriveFlow, Tutor LMS, WordPress): before a message goes out, team members who
 * switched that kind off are taken off To, Cc and Bcc; a message left with no one is not sent (and is noted
 * in the e-mail log). Password and sign-in e-mails always go. Phone and web notifications are not affected.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_MailPrefs {

	const META = 'sdsc_mail_prefs';
	const PAGE = 'sdsc-my-email';

	/** key => [ label, what it covers, subject pattern ]. Checked in this order; the first match wins. */
	public static function cats() {
		return array(
			'account'  => array( 'Password and sign-in', 'Password resets, new-login and security codes — always sent.', '/password|log ?in|sign[- ]?in|verif|security code|two-factor|2fa|wordfence|new user|account (details|created)/i' ),
			'reports'  => array( 'Daily and weekly reports', 'Summaries, daily reports and digests.', '/report|summary|digest|daily|weekly|monthly|today’s|this week/i' ),
			'refunds'  => array( 'Refunds', 'Refund requests and approvals.', '/refund/i' ),
			'payments' => array( 'Payments', 'Online payments, receipts and payment problems.', '/payment|paid|receipt|invoice|charge|stripe|order/i' ),
			'regs'     => array( 'New registrations', 'Students who registered or still miss something.', '/registration|registered|sign-?up|enroll/i' ),
			'lessons'  => array( 'Driving lessons', 'Bookings, times chosen from a link, changes, cancellations, no-shows.', '/lesson|driving|behind the wheel|btw|booked|booking|cancel|no-show|offer|chose/i' ),
			'classes'  => array( 'Classes and Zoom', 'Class reminders, Zoom attendance and class changes.', '/class|zoom|attendance|session|course/i' ),
			'team'     => array( 'Instructors', 'Instructor applications, onboarding and agreements.', '/instructor|onboarding|agreement|applicat/i' ),
			'messages' => array( 'Messages', 'Chat messages and replies.', '/message|chat|repl/i' ),
			'prices'   => array( 'Price watch', 'Other schools’ price changes.', '/price/i' ),
			'site'     => array( 'Website and system', 'Updates, backups, plugin and server notices.', '/update|backup|plugin|site|wordpress|server|error|warning/i' ),
			'other'    => array( 'Everything else', 'Any other e-mail from the website.', '/.*/' ),
		);
	}

	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'filter' ), 999 );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'short' ), 999, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_sdsc_mailprefs', array( __CLASS__, 'save' ) );
	}

	public static function can() { return is_user_logged_in() && class_exists( 'SDSC_Workspace' ) && SDSC_Workspace::is_staff(); }

	/** cat => true (on) / false (off) for a user. Missing = on. */
	public static function get( $uid ) {
		$p = get_user_meta( (int) $uid, self::META, true );
		$p = is_array( $p ) ? $p : array();
		$out = array();
		foreach ( array_keys( self::cats() ) as $k ) { $out[ $k ] = 'account' === $k ? true : ! isset( $p[ $k ] ) || (bool) $p[ $k ]; }
		return $out;
	}

	public static function kind( $subject ) {
		foreach ( self::cats() as $k => $c ) { if ( preg_match( $c[2], (string) $subject ) ) { return $k; } }
		return 'other';
	}

	protected static $skipped = null;

	/** Take team members who switched this kind off out of the recipients. */
	public static function filter( $atts ) {
		self::$skipped = null;
		$kind = self::kind( wp_strip_all_tags( (string) ( $atts['subject'] ?? '' ) ) );
		if ( 'account' === $kind ) { return $atts; }
		$cache = array();
		$off   = function ( $addr ) use ( $kind, &$cache ) {
			if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) { $addr = $m[1]; }
			$e = strtolower( trim( $addr ) );
			if ( ! is_email( $e ) ) { return false; }
			if ( ! isset( $cache[ $e ] ) ) {
				$u = get_user_by( 'email', $e );
				$cache[ $e ] = $u && ! self::get( $u->ID )[ $kind ];
			}
			return $cache[ $e ];
		};
		$removed = array();
		$to      = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
		$keep    = array();
		foreach ( $to as $a ) { $a = trim( (string) $a ); if ( '' === $a ) { continue; } if ( $off( $a ) ) { $removed[] = $a; } else { $keep[] = $a; } }
		$atts['to'] = $keep;
		// Cc / Bcc lines in the headers.
		$h = $atts['headers'] ?? array();
		$h = is_array( $h ) ? $h : explode( "\n", str_replace( "\r\n", "\n", (string) $h ) );
		foreach ( $h as $i => $line ) {
			if ( ! preg_match( '/^\s*(cc|bcc)\s*:(.*)$/i', (string) $line, $m ) ) { continue; }
			$list = array_filter( array_map( 'trim', explode( ',', $m[2] ) ), function ( $a ) use ( $off, &$removed ) { if ( '' !== $a && $off( $a ) ) { $removed[] = $a; return false; } return '' !== $a; } );
			if ( $list ) { $h[ $i ] = $m[1] . ': ' . implode( ', ', $list ); } else { unset( $h[ $i ] ); }
		}
		$atts['headers'] = array_values( $h );
		if ( $removed ) { self::$skipped = array( 'to' => $removed, 'subject' => (string) ( $atts['subject'] ?? '' ), 'kind' => $kind, 'empty' => ! $keep ); }
		return $atts;
	}

	/** Nobody left to send to: do not send, and note it in the e-mail log. */
	public static function short( $ret, $atts ) {
		if ( self::$skipped ) {
			$l = get_option( 'sdsc_mail_log', array() );
			$l = is_array( $l ) ? $l : array();
			array_unshift( $l, array( 't' => time(), 'to' => implode( ', ', self::$skipped['to'] ), 's' => wp_strip_all_tags( self::$skipped['subject'] ), 'id' => '', 'ok' => 0, 'e' => 'Not sent to them — switched off in My e-mails (' . self::cats()[ self::$skipped['kind'] ][0] . ')' ) );
			update_option( 'sdsc_mail_log', array_slice( $l, 0, 100 ), false );
		}
		if ( empty( $atts['to'] ) ) { self::$skipped = null; return true; }
		self::$skipped = null;
		return $ret;
	}

	/* ------------------------------------------------------------ the page */

	public static function url() { return admin_url( 'admin.php?page=' . self::PAGE ); }

	public static function menu() {
		if ( ! self::can() ) { return; }
		add_submenu_page( 'sdsc-dashboard', 'My e-mails', 'My e-mails', 'read', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! self::can() ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'sdsc_mailprefs' );
		$on  = array_map( 'sanitize_key', (array) wp_unslash( $_POST['on'] ?? array() ) ); // phpcs:ignore
		$out = array();
		foreach ( array_keys( self::cats() ) as $k ) { if ( 'account' !== $k ) { $out[ $k ] = in_array( $k, $on, true ) ? 1 : 0; } }
		update_user_meta( get_current_user_id(), self::META, $out );
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url() ) );
		exit;
	}

	public static function page() {
		if ( ! self::can() ) { wp_die( 'Not allowed.' ); }
		$u    = wp_get_current_user();
		$p    = self::get( $u->ID );
		$cats = self::cats();
		$log  = array_filter( (array) get_option( 'sdsc_mail_log', array() ), function ( $r ) use ( $u ) { return false !== stripos( (string) ( $r['to'] ?? '' ), $u->user_email ); } );
		?>
		<div class="wrap sdsc-mp">
			<h1>My e-mails</h1>
			<p>Choose which e-mails from the website reach <b><?php echo esc_html( $u->user_email ); ?></b>. Phone and web notifications (the bell) keep coming either way.</p>
			<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore ?><div class="notice notice-success"><p>Saved.</p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sdsc_mailprefs' ); ?><input type="hidden" name="action" value="sdsc_mailprefs">
				<p><button type="button" class="button" onclick="document.querySelectorAll('.sdsc-mp input[name=\'on[]\']').forEach(function(c){c.checked=true;})">All on</button> <button type="button" class="button" onclick="document.querySelectorAll('.sdsc-mp input[name=\'on[]\']').forEach(function(c){c.checked=false;})">All off</button></p>
				<table class="widefat striped" style="max-width:760px">
					<tbody>
					<?php foreach ( $cats as $k => $c ) : ?>
						<tr>
							<td style="width:44px;text-align:center"><?php if ( 'account' === $k ) : ?><input type="checkbox" checked disabled><?php else : ?><input type="checkbox" name="on[]" value="<?php echo esc_attr( $k ); ?>" id="mp-<?php echo esc_attr( $k ); ?>" <?php checked( $p[ $k ] ); ?>><?php endif; ?></td>
							<td><label for="mp-<?php echo esc_attr( $k ); ?>"><b><?php echo esc_html( $c[0] ); ?></b><br><span class="description"><?php echo esc_html( $c[1] ); ?></span></label></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button class="button button-primary button-hero">Save</button></p>
			</form>
			<?php if ( $log ) : ?>
				<h2>Latest e-mails to you</h2>
				<table class="widefat striped" style="max-width:760px"><thead><tr><th>When</th><th>Subject</th><th>Kind</th><th></th></tr></thead><tbody>
				<?php foreach ( array_slice( $log, 0, 20 ) as $r ) : ?>
					<tr><td><?php echo esc_html( wp_date( 'M j g:i a', (int) $r['t'] ) ); ?></td><td><?php echo esc_html( $r['s'] ); ?></td><td><?php echo esc_html( $cats[ self::kind( $r['s'] ) ][0] ); ?></td><td><?php echo ! empty( $r['ok'] ) ? 'Sent' : esc_html( false !== strpos( (string) $r['e'], 'My e-mails' ) ? 'Not sent (switched off)' : 'Failed' ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
