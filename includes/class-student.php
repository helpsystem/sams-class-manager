<?php
/**
 * The student's Tutor dashboard, trimmed to what the school uses.
 *
 * Hidden for students (staff and instructors see everything as before):
 *  - the "You haven't enrolled in a course yet" card, "Quick Tips", "Complete your profile",
 *    the sidebar "Explore Courses" link;
 *  - Reviews, Billing (Tutor orders — the school takes payments through Stripe), the course
 *    Wishlist, the Profile link, and in Settings: cover photo, Skill/Occupation, Public profile
 *    (display name, bio) and the Social accounts tab.
 * Added: "My payments" — online payments (Stripe), cash / check payments recorded on the class
 * board, and refunds, with the Stripe receipt.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Student {
	const TAB = 'sdsc-payments';

	public static function init() {
		add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'nav' ), 40 );
		add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'template' ) );
		add_action( 'template_redirect', array( __CLASS__, 'block_pages' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'trim' ), 5 );
	}

	/** Students only: never staff, instructors or administrators. */
	public static function is_student() {
		if ( ! is_user_logged_in() ) { return false; }
		if ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) ) { return false; }
		$roles = (array) wp_get_current_user()->roles;
		return ! array_intersect( $roles, array( 'tutor_instructor', 'administrator', 'editor', 'shop_manager' ) ) && ! ( class_exists( 'SDSC_Access' ) && array_intersect( $roles, array( SDSC_Access::MANAGER, SDSC_Access::FRONTDESK ) ) );
	}

	protected static function dash_page() {
		global $wp_query;
		return (string) ( $wp_query->query_vars['tutor_dashboard_page'] ?? '' );
	}

	public static function nav( $items ) {
		if ( ! is_array( $items ) || ! self::is_student() ) { return $items; }
		foreach ( array( 'wishlist', 'reviews', 'purchase_history', 'my-profile', 'question-answer', 'my-quiz-attempts-legacy' ) as $k ) { unset( $items[ $k ] ); }
		$items[ self::TAB ] = array( 'title' => 'My payments', 'icon' => 'purchase', 'active_icon' => 'purchase' );
		return $items;
	}

	public static function template( $tpl ) {
		return self::TAB === self::dash_page() ? dirname( __DIR__ ) . '/templates/tutor-my-payments.php' : $tpl;
	}

	/** Pages that make no sense for our students go to the dashboard (Billing goes to My payments). */
	public static function block_pages() {
		if ( ! self::is_student() || ! function_exists( 'tutor_utils' ) ) { return; }
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore
		$dash = trim( (string) wp_parse_url( tutor_utils()->tutor_dashboard_url(), PHP_URL_PATH ), '/' );
		if ( '' === $dash || 0 !== strpos( $path, $dash ) ) { return; }
		$rest = trim( substr( $path, strlen( $dash ) ), '/' );
		$to   = '';
		if ( preg_match( '#^(account/)?(billing|purchase_history)#', $rest ) ) { $to = tutor_utils()->tutor_dashboard_url( self::TAB ); }
		elseif ( preg_match( '#^(account/)?(reviews|wishlist|my-profile)#', $rest ) || preg_match( '#^courses/wishlist#', $rest ) ) { $to = tutor_utils()->tutor_dashboard_url(); }
		elseif ( preg_match( '#^account/settings#', $rest ) && isset( $_GET['tab'] ) && 'social' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) { $to = tutor_utils()->tutor_dashboard_url( 'account/settings' ); } // phpcs:ignore
		if ( $to ) { wp_safe_redirect( $to ); exit; }
	}

	/** Hides Tutor's built-in cards and fields that our school does not use (students only, dashboard only). */
	public static function trim() {
		if ( ! self::is_student() || ! function_exists( 'tutor_utils' ) || ! is_page( (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) ) ) { return; }
		?>
		<style id="sdsc-student-trim">
			.tutor-profile-completion,.tutor-dashboard-quick-tips-card,.tutor-dashboard-welcome-card:has(a[href*="/courses"]),a.tutor-see-all-courses,
			.tutor-dashboard-layout [class*="cover-photo"],.tutor-dashboard-layout [class*="cover_photo"],.tutor-dashboard-layout [class*="profile-cover"]{display:none!important}
			.sdsc-pay-list{display:flex;flex-direction:column;gap:10px;margin:8px 0 20px}
			.sdsc-pay{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;border:1px solid #e3e7ea;border-radius:12px;padding:12px 14px;background:#fff}
			.sdsc-pay b{font-size:16px}.sdsc-pay small{display:block;color:#5b646d;margin-top:2px}
			.sdsc-pill{font-size:12px;font-weight:700;border-radius:999px;padding:3px 10px;background:#e6f4ea;color:#00561f;white-space:nowrap}
			.sdsc-pill--ref{background:#fff8e5;color:#6b4e00}
			.sdsc-pay a{color:#00703c;font-weight:700}
		</style>
		<script>
		(function(){
			function hide(el){ if(el){ el.style.display='none'; } }
			function run(){
				// user menu and any link to pages we do not use
				document.querySelectorAll('.tutor-dashboard-layout a[href], .tutor-dropdown a[href]').forEach(function(a){
					var h=a.getAttribute('href')||'';
					if(/\/(reviews|billing|wishlist|purchase_history|my-profile)\/?(\?|$)/.test(h)||/\/profile\//.test(h)){ hide(a.closest('li')||a); }
				});
				// settings: Social Accounts tab
				document.querySelectorAll('.tutor-profile-settings-tab button, .tutor-tabs-nav button').forEach(function(b){ if(/Social Accounts/i.test(b.textContent)){ hide(b); } });
				// settings: Public Profile section, Skill/Occupation field
				document.querySelectorAll('#tutor-account-form h5, #tutor-account-form .tutor-h5').forEach(function(h){ if(/Public Profile/i.test(h.textContent)){ hide(h.parentElement); } });
				document.querySelectorAll('#tutor-account-form label').forEach(function(l){ if(/Skill\s*\/\s*Occupation/i.test(l.textContent)){ hide(l.closest('.tutor-input-field')||l.parentElement); } });
				// courses: Wishlist tab
				document.querySelectorAll('.tutor-dashboard-layout a, .tutor-dashboard-layout button').forEach(function(x){ if(/^\s*Wishlist\s*$/i.test(x.textContent)){ hide(x); } });
			}
			run(); document.addEventListener('DOMContentLoaded',run); new MutationObserver(function(){ run(); }).observe(document.body,{childList:true,subtree:true});
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------ My payments */

	public static function payments_html() {
		if ( ! is_user_logged_in() ) { return '<p>Please sign in.</p>'; }
		global $wpdb;
		$email = strtolower( wp_get_current_user()->user_email );
		$rt    = class_exists( 'SDSC_Registration' ) ? SDSC_Registration::table() : '';
		$regs  = $rt ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $rt WHERE LOWER(email) = %s OR LOWER(parent_email) = %s", $email, $email ) ) ) : array(); // phpcs:ignore
		$in    = $regs ? implode( ',', $regs ) : '0';
		$items = array();
		if ( class_exists( 'SDSC_Pay' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Pay::table() . " WHERE status = 'paid' AND mode = 'live' AND ( LOWER(email) = %s OR reg_id IN ($in) ) ORDER BY paid_at DESC", $email ), ARRAY_A ) as $p ) { // phpcs:ignore
				$items[] = array( 't' => strtotime( (string) $p['paid_at'] ), 'what' => self::cname( (int) $p['class_id'] ), 'how' => $p['card'] ? $p['card'] : 'Card', 'amt' => $p['amount'] / 100, 'pill' => 'Paid', 'url' => $p['receipt_url'] );
			}
		}
		if ( class_exists( 'SDSC_Board' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Board::table() . " WHERE status = 'paid' AND ( LOWER(email) = %s OR reg_id IN ($in) ) ORDER BY paid_on DESC", $email ), ARRAY_A ) as $p ) { // phpcs:ignore
				$m = array( 'cash' => 'Cash', 'check' => 'Check', 'zelle' => 'Zelle', 'other' => 'Paid at the office' );
				$items[] = array( 't' => strtotime( (string) $p['paid_on'] . ' 12:00' ), 'what' => self::cname( (int) $p['class_id'] ), 'how' => ( $m[ $p['method'] ] ?? 'Paid at the office' ) . ( $p['ref'] ? ' #' . $p['ref'] : '' ), 'amt' => $p['amount'] / 100, 'pill' => 'Paid', 'url' => '' );
			}
		}
		if ( class_exists( 'SDSC_Refunds' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SDSC_Refunds::table() . " WHERE email = %s AND status = 'done' ORDER BY id DESC", $email ), ARRAY_A ) as $r ) { // phpcs:ignore
				$items[] = array( 't' => strtotime( (string) $r['created_at'] ), 'what' => 'Refund', 'how' => 'stripe' === $r['source'] ? 'Back to your card' : 'Given back by the office', 'amt' => (float) $r['amount'], 'pill' => 'Refunded', 'url' => '' );
			}
		}
		usort( $items, function ( $a, $b ) { return $b['t'] <=> $a['t']; } );
		$h = '<div class="tutor-dashboard-content-inner"><h3 class="tutor-fs-5 tutor-fw-medium tutor-mb-16">My payments</h3>';
		if ( ! $items ) {
			$h .= '<p>No payments yet. When you pay for a class online or at the office, it shows here with its receipt.</p>';
		} else {
			$h .= '<div class="sdsc-pay-list">';
			foreach ( $items as $i ) {
				$h .= '<div class="sdsc-pay"><div><b>' . ( 'Refunded' === $i['pill'] ? '−' : '' ) . '$' . esc_html( number_format( $i['amt'], 2 ) ) . '</b> · ' . esc_html( $i['what'] )
					. '<small>' . esc_html( wp_date( 'M j, Y', $i['t'] ) . ' · ' . $i['how'] ) . ( $i['url'] ? ' · <a href="' . esc_url( $i['url'] ) . '" target="_blank" rel="noopener">Receipt</a>' : '' ) . '</small></div>'
					. '<span class="sdsc-pill' . ( 'Refunded' === $i['pill'] ? ' sdsc-pill--ref' : '' ) . '">' . esc_html( $i['pill'] ) . '</span></div>';
			}
			$h .= '</div>';
		}
		$h .= '<p style="color:#5b646d">Something missing or wrong? Call or text (301) 726-3030.</p></div>';
		return $h;
	}

	protected static function cname( $cid ) {
		if ( ! $cid ) { return 'Payment'; }
		return class_exists( 'SDSC_Schedule' ) ? SDSC_Schedule::class_name( $cid ) : html_entity_decode( (string) get_the_title( $cid ), ENT_QUOTES, 'UTF-8' );
	}
}
