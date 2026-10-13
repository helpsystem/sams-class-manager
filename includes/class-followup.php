<?php
/**
 * Registration follow-up: nobody is turned away at the door, but nothing is left unfinished.
 *
 * A registration is accepted even when something is missing (a parent who is not there to sign, no
 * payment yet, a student under 16). The system then keeps track of what is missing and follows up
 * until it is complete:
 *  - the parent gets a secure link to fill in their details, read the policies and sign;
 *  - e-mail reminders go out by themselves (right away, then after 2, 5 and 9 days);
 *  - texts are put on "Texts to send" for the office to send from the school phone (no Twilio needed);
 *  - the Registrations screen shows what is still missing for each student.
 */
defined( 'ABSPATH' ) || exit;

class SDSC_Followup {

	const DAYS = array( 0, 2, 5, 9 ); // e-mail rounds, in days after registering

	public static function init() {
		add_action( 'sdsc_followup_run', array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_post_nopriv_sdsc_parent_save', array( __CLASS__, 'parent_save' ) );
		add_action( 'admin_post_sdsc_parent_save', array( __CLASS__, 'parent_save' ) );
		add_action( 'admin_post_sdsc_followup_now', array( __CLASS__, 'remind_now' ) );
	}

	/** Only registrations made after this feature was switched on are followed up (older ones never get surprise e-mails). */
	public static function since() {
		$s = get_option( 'sdsc_followup_since' );
		if ( ! $s ) { $s = current_time( 'mysql' ); update_option( 'sdsc_followup_since', $s, false ); }
		return max( $s, wp_date( 'Y-m-d H:i:s', time() - 21 * DAY_IN_SECONDS ) );
	}

	public static function schedule() {
		self::since();
		if ( ! wp_next_scheduled( 'sdsc_followup_run' ) ) { wp_schedule_event( time() + 300, 'hourly', 'sdsc_followup_run' ); }
	}

	/* ------------------------------------------------------------ what is missing */

	public static function token( $rid ) {
		return substr( hash_hmac( 'sha256', 'parent|' . (int) $rid, wp_salt( 'nonce' ) . 'sdsc-parent' ), 0, 24 );
	}

	public static function parent_url( $rid ) {
		$page = get_page_by_path( 'register-course' );
		$base = $page ? get_permalink( $page ) : home_url( '/register-course/' );
		return add_query_arg( array( 'sdsc_parent' => (int) $rid, 't' => self::token( $rid ) ), $base );
	}

	protected static function row( $rid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, created_at, class_id, registrant_id, first_name, last_name, age, email, phone, parent_name, parent_phone, parent_email, parent_consent, (parent_sig IS NOT NULL) has_p, follow_n, follow_at, done_at FROM ' . SDSC_Registration::table() . ' WHERE id = %d', $rid ) );
	}

	public static function paid( $r ) {
		if ( class_exists( 'SDSC_Pay' ) && SDSC_Pay::paid_row( (int) $r->id ) ) { return true; }
		return $r->registrant_id && get_post_meta( (int) $r->registrant_id, '_sdsc_paid', true );
	}

	/** Missing pieces: parent, pay, age16 (staff only). */
	public static function issues( $r ) {
		$out = array();
		if ( null !== $r->age && (int) $r->age < 18 ) {
			$miss = array();
			if ( '' === trim( (string) $r->parent_name ) ) { $miss[] = 'name'; }
			if ( strlen( preg_replace( '/\D/', '', (string) $r->parent_phone ) ) < 10 ) { $miss[] = 'phone'; }
			if ( ! is_email( (string) $r->parent_email ) ) { $miss[] = 'email'; }
			if ( empty( $r->parent_consent ) ) { $miss[] = 'policies'; }
			if ( empty( $r->has_p ) ) { $miss[] = 'signature'; }
			if ( $miss ) { $out['parent'] = $miss; }
		}
		if ( null !== $r->age && (int) $r->age < 16 ) { $out['age16'] = true; }
		if ( class_exists( 'SDSC_Pay' ) && SDSC_Pay::amount( (int) $r->class_id ) > 0 && ! self::paid( $r ) ) { $out['pay'] = true; }
		return $out;
	}

	/** Small badges for the Registrations list. */
	public static function badges( $rid ) {
		$r = self::row( $rid );
		if ( ! $r ) { return ''; }
		$i = self::issues( $r );
		if ( ! $i ) { return '<span style="color:#0b6b35;font-weight:700">✓ Complete</span>'; }
		$b = array();
		if ( isset( $i['parent'] ) ) { $b[] = '<span style="background:#fff3cd;color:#7a5600;padding:2px 8px;border-radius:99px;font-weight:700">Parent: ' . esc_html( implode( ', ', $i['parent'] ) ) . '</span>'; }
		if ( isset( $i['pay'] ) ) { $b[] = '<span style="background:#fdecea;color:#912018;padding:2px 8px;border-radius:99px;font-weight:700">Not paid</span>'; }
		if ( isset( $i['age16'] ) ) { $b[] = '<span style="background:#e8eefc;color:#1d3d8f;padding:2px 8px;border-radius:99px;font-weight:700">Under 16 — call</span>'; }
		return implode( ' ', $b );
	}

	/** Checklist + actions for one registration (Registrations → view). */
	public static function panel( $rid ) {
		$r = self::row( $rid );
		if ( ! $r ) { return ''; }
		$i = self::issues( $r );
		$h = '<div style="margin:14px 0;padding:14px 16px;border-radius:12px;background:#f6f8f7;border:1px solid #dfe5e1"><h3 style="margin:0 0 8px">Follow-up</h3>';
		if ( ! $i ) { return $h . '<p style="margin:0;color:#0b6b35;font-weight:700">✓ Everything is complete.</p></div>'; }
		$h .= '<ul style="margin:0 0 10px 18px;list-style:disc">';
		if ( isset( $i['parent'] ) ) { $h .= '<li>Parent / guardian still needs to give: <b>' . esc_html( implode( ', ', $i['parent'] ) ) . '</b></li>'; }
		if ( isset( $i['pay'] ) ) { $h .= '<li>Payment not received yet</li>'; }
		if ( isset( $i['age16'] ) ) { $h .= '<li>Student is under 16 — call the family to check the start date</li>'; }
		$h .= '</ul><p style="margin:0 0 8px;color:#5b646d">E-mail reminders sent: ' . (int) $r->follow_n . ' of ' . count( self::DAYS ) . ( $r->follow_at ? ' · last ' . esc_html( mysql2date( 'M j, g:i a', $r->follow_at ) ) : '' ) . '. Texts are on <a href="' . esc_url( admin_url( 'admin.php?page=sdsc-texts' ) ) . '">Texts to send</a>.</p>';
		if ( isset( $i['parent'] ) ) {
			$h .= '<p style="margin:0 0 8px">Parent link: <input type="text" readonly value="' . esc_attr( self::parent_url( $rid ) ) . '" style="width:min(520px,100%)" onclick="this.select()"/></p>';
		}
		$h .= '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdsc_followup_now&r=' . (int) $rid ), 'sdsc_followup_' . (int) $rid ) ) . '">E-mail a reminder now</a></div>';
		return $h;
	}

	/* ------------------------------------------------------------ messages */

	protected static function links( $r, $i ) {
		$l = array();
		if ( isset( $i['parent'] ) ) { $l['parent'] = self::parent_url( $r->id ); }
		if ( isset( $i['pay'] ) && class_exists( 'SDSC_Pay' ) ) { $u = SDSC_Pay::url( (int) $r->id, (int) $r->class_id ); if ( $u ) { $l['pay'] = $u; } }
		return $l;
	}

	protected static function email( $r, $i, $round ) {
		$l = self::links( $r, $i );
		if ( ! $l ) { return false; }
		$student = trim( $r->first_name . ' ' . $r->last_name );
		$class   = SDSC_Registration::class_label( (int) $r->class_id );
		$to      = array();
		if ( is_email( (string) $r->parent_email ) ) { $to[] = $r->parent_email; }
		if ( is_email( (string) $r->email ) ) { $to[] = $r->email; }
		$to = array_unique( $to );
		if ( ! $to ) { return false; }
		$btn  = function ( $u, $t ) { return '<p><a href="' . esc_url( $u ) . '" style="display:inline-block;padding:12px 20px;border-radius:999px;background:#00703c;color:#fff;font-weight:700;text-decoration:none">' . esc_html( $t ) . '</a></p>'; };
		$html = '<p>Hello,</p><p>Thank you for registering ' . esc_html( $student ) . ' for <b>' . esc_html( $class ) . '</b> at Sam\'s Driving School. To finish the registration we still need:</p>';
		if ( isset( $l['parent'] ) ) { $html .= '<p><b>Parent / guardian:</b> because the student is under 18, a parent or guardian fills in their details, reads the policies and signs (about 2 minutes).</p>' . $btn( $l['parent'], 'Complete the parent part' ); }
		if ( isset( $l['pay'] ) ) { $html .= '<p><b>Payment:</b> the seat is confirmed as soon as the payment goes through.</p>' . $btn( $l['pay'], 'Pay securely' ); }
		$html .= '<p>Questions? Call (301) 726-3030.</p>';
		$subject = ( $round > 1 ? 'Reminder: ' : '' ) . 'Finish ' . $student . '\'s registration — Sam\'s Driving School';
		$hdr     = array( 'Content-Type: text/html; charset=UTF-8' );
		foreach ( $to as $e ) {
			if ( class_exists( 'SDSC_Mailer' ) ) { SDSC_Mailer::send( $e, $subject, $html, 'info', $hdr ); } else { wp_mail( $e, $subject, $html, $hdr ); }
		}
		return true;
	}

	/** Right after the form is sent: first reminder at once when something is missing. */
	public static function on_registered( $rid ) {
		$r = self::row( $rid );
		if ( ! $r ) { return; }
		$i = self::issues( $r );
		unset( $i['pay'] ); // the payment page opens next; payment reminders start with the next round
		if ( ! $i ) { return; }
		self::email( $r, $i, 1 );
		self::stamp( $rid, 1 );
	}

	protected static function stamp( $rid, $n, $done = false ) {
		global $wpdb;
		$d = array( 'follow_n' => $n, 'follow_at' => current_time( 'mysql' ) );
		if ( $done ) { $d['done_at'] = current_time( 'mysql' ); }
		$wpdb->update( SDSC_Registration::table(), $d, array( 'id' => (int) $rid ) );
	}

	/** Hourly: next e-mail round for anything still missing (registrations of the last 3 weeks). */
	public static function run() {
		global $wpdb;
		$since = self::since();
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . SDSC_Registration::table() . " WHERE source = 'native' AND done_at IS NULL AND created_at > %s ORDER BY id LIMIT 200", $since ) );
		foreach ( $ids as $rid ) {
			$r = self::row( $rid );
			if ( ! $r ) { continue; }
			$i = self::issues( $r );
			unset( $i['age16'] );
			if ( ! $i ) { self::stamp( $rid, (int) $r->follow_n, true ); continue; }
			$n = (int) $r->follow_n;
			if ( $n >= count( self::DAYS ) ) { continue; }
			$due = strtotime( $r->created_at ) + self::DAYS[ $n ] * DAY_IN_SECONDS;
			if ( current_time( 'timestamp' ) < $due ) { continue; } // phpcs:ignore
			if ( self::email( $r, $i, $n + 1 ) ) { self::stamp( $rid, $n + 1 ); }
		}
	}

	public static function remind_now() {
		$rid = absint( $_GET['r'] ?? 0 );
		check_admin_referer( 'sdsc_followup_' . $rid );
		if ( ! ( class_exists( 'SDSC_Access' ) ? SDSC_Access::can( 'registrations' ) : current_user_can( 'manage_options' ) ) ) { wp_die( 'Not allowed.', 403 ); }
		$r = self::row( $rid );
		if ( $r ) {
			$i = self::issues( $r );
			unset( $i['age16'] );
			if ( $i && self::email( $r, $i, (int) $r->follow_n + 1 ) ) { self::stamp( $rid, min( count( self::DAYS ), (int) $r->follow_n + 1 ) ); }
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sdsc-registrations&view=' . $rid . '&sdsc_reminded=1' ) );
		exit;
	}

	/** Cards for "Texts to send" (sent by hand from the school phone). A new card after each e-mail round. */
	public static function text_items() {
		global $wpdb;
		$out   = array();
		$since = self::since();
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . SDSC_Registration::table() . " WHERE source = 'native' AND done_at IS NULL AND created_at > %s AND created_at < %s ORDER BY id DESC LIMIT 100", $since, wp_date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ) );
		foreach ( $ids as $rid ) {
			$r = self::row( $rid );
			if ( ! $r ) { continue; }
			$i = self::issues( $r );
			unset( $i['age16'] );
			$l = $i ? self::links( $r, $i ) : array();
			if ( ! $l ) { continue; }
			$student = trim( $r->first_name . ' ' . $r->last_name );
			$parentp = strlen( preg_replace( '/\D/', '', (string) $r->parent_phone ) ) >= 10;
			$phone   = $parentp ? $r->parent_phone : $r->phone;
			$hi      = $parentp && $r->parent_name ? 'Hi ' . strtok( $r->parent_name, ' ' ) : 'Hi';
			$need    = array();
			if ( isset( $l['parent'] ) ) { $need[] = 'the parent signature: ' . $l['parent']; }
			if ( isset( $l['pay'] ) ) { $need[] = 'the payment: ' . $l['pay']; }
			$body = $hi . ', this is Sam\'s Driving School. ' . $student . '\'s registration still needs ' . implode( ' and ', $need ) . ' Questions? (301) 726-3030';
			$out[] = array(
				'k'     => 'reg:' . $rid . ':' . max( 1, (int) $r->follow_n ),
				'group' => 'registrations',
				'name'  => $student . ( $parentp ? ' (parent)' : '' ),
				'phone' => $phone,
				'why'   => 'Registration not complete — ' . implode( ' · ', array_filter( array( isset( $i['parent'] ) ? 'parent part' : '', isset( $i['pay'] ) ? 'not paid' : '' ) ) ),
				'texts' => array( 'en' => $body, 'es' => $body ),
				'lang'  => 'en',
				'when'  => strtotime( $r->created_at ),
				'link'  => admin_url( 'admin.php?page=sdsc-registrations&view=' . $rid ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------ the parent's page */

	public static function parent_form() {
		$rid = absint( $_GET['sdsc_parent'] ?? 0 ); // phpcs:ignore
		$tok = sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) ); // phpcs:ignore
		if ( ! $rid || ! hash_equals( self::token( $rid ), $tok ) ) { return '<div class="sdsc-reg"><div class="sdsc-reg-card"><p>This link is not valid. Please call us at (301) 726-3030.</p></div></div>'; }
		$r = self::row( $rid );
		if ( ! $r ) { return ''; }
		$i       = self::issues( $r );
		$student = trim( $r->first_name . ' ' . $r->last_name );
		ob_start();
		?>
		<div class="sdsc-reg" id="sdsc">
			<div class="sdsc-reg-card">
				<header class="sdsc-reg-head">
					<?php echo SDSC_Brand::logo( 'wide', 'sdsc-reg-logo', 46 ); // phpcs:ignore ?>
					<div><h2 class="sdsc-reg-title">Parent / guardian</h2><p class="sdsc-reg-sub"><?php echo esc_html( $student ); ?> · <?php echo esc_html( SDSC_Registration::class_label( (int) $r->class_id ) ); ?></p></div>
				</header>
				<?php if ( isset( $_GET['sdsc_done'] ) || ! isset( $i['parent'] ) ) : // phpcs:ignore ?>
					<p class="sdsc-sent is-ok">✓ Thank you — the parent part is complete.</p>
					<?php if ( isset( $i['pay'] ) && class_exists( 'SDSC_Pay' ) && ( $u = SDSC_Pay::url( $rid, (int) $r->class_id ) ) ) : ?>
						<p style="text-align:center">One more step: the payment confirms the seat.</p>
						<p style="text-align:center"><a class="sdsc-btn" href="<?php echo esc_url( $u ); ?>">Pay securely &rarr;</a></p>
					<?php endif; ?>
				<?php else : ?>
					<form class="sdsc-reg-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
						<input type="hidden" name="action" value="sdsc_parent_save" />
						<input type="hidden" name="r" value="<?php echo (int) $rid; ?>" />
						<input type="hidden" name="t" value="<?php echo esc_attr( $tok ); ?>" />
						<input type="hidden" class="sdsc-js-check" name="sdsc_js" value="" />
						<input type="hidden" class="sdsc-dob" value="<?php echo esc_attr( wp_date( 'Y-m-d', time() - 17 * YEAR_IN_SECONDS ) ); ?>" />
						<section class="sdsc-step is-active" data-step="0">
							<?php if ( isset( $_GET['sdsc_err'] ) ) : // phpcs:ignore ?><div class="sdsc-reg-alert" role="alert">Please fill in every field, tick the box and sign.</div><?php endif; ?>
							<p class="sdsc-note">Because <?php echo esc_html( $r->first_name ); ?> is under 18, Maryland requires a parent or guardian to complete this part and sign.</p>
							<div class="sdsc-grid">
								<?php
								$v = function ( $x ) { return 'value="' . esc_attr( (string) $x ) . '"'; };
								echo SDSC_Registration::field( 'parent_name', 'Parent / guardian full name', 'text', true, 'maxlength="200" ' . $v( $r->parent_name ) );
								echo SDSC_Registration::field( 'parent_phone', 'Parent / guardian phone', 'tel', true, 'maxlength="40" ' . $v( $r->parent_phone ) );
								echo SDSC_Registration::field( 'parent_email', 'Parent / guardian email', 'email', true, 'maxlength="190" ' . $v( $r->parent_email ) );
								?>
							</div>
							<label class="sdsc-consent">
								<input type="checkbox" name="parent_consent" value="1" required />
								<span>I am the student's parent or legal guardian. I have read the <a href="<?php echo esc_url( home_url( '/policy/' ) ); ?>" target="_blank" rel="noopener">policies, terms and conditions</a> and I agree to them for my child. <b>*</b></span>
							</label>
							<div class="sdsc-sig" data-name="parent_sig" data-required="1">
								<span class="sdsc-sig-label">Parent / guardian signature <b>*</b></span>
								<div class="sdsc-sig-paper"><canvas class="sdsc-sig-pad" height="170"></canvas><span class="sdsc-sig-hint">Sign here with your finger or mouse</span></div>
								<button type="button" class="sdsc-sig-clear">Clear</button>
								<input type="hidden" name="parent_sig" />
							</div>
						</section>
						<div class="sdsc-reg-error" role="alert" hidden></div>
						<div class="sdsc-nav">
							<button type="button" class="sdsc-btn sdsc-btn-ghost sdsc-prev" hidden>&larr; Back</button>
							<button type="button" class="sdsc-btn sdsc-next" hidden>Continue</button>
							<button type="submit" class="sdsc-btn sdsc-submit">Sign &amp; finish</button>
						</div>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function parent_save() {
		$rid = absint( $_POST['r'] ?? 0 );
		$tok = sanitize_text_field( wp_unslash( $_POST['t'] ?? '' ) );
		if ( ! $rid || ! hash_equals( self::token( $rid ), $tok ) ) { wp_safe_redirect( home_url( '/' ) ); exit; }
		$back = add_query_arg( array( 'sdsc_parent' => $rid, 't' => $tok ), wp_get_referer() && 0 === strpos( wp_get_referer(), home_url() ) ? remove_query_arg( array( 'sdsc_done', 'sdsc_err' ), wp_get_referer() ) : self::parent_url( $rid ) );
		$name  = sanitize_text_field( wp_unslash( $_POST['parent_name'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['parent_phone'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['parent_email'] ?? '' ) );
		$sig   = SDSC_Registration::decode_png( wp_unslash( $_POST['parent_sig'] ?? '' ) );
		if ( ! $name || strlen( preg_replace( '/\D/', '', $phone ) ) < 10 || ! is_email( $email ) || empty( $_POST['parent_consent'] ) || ! $sig ) {
			wp_safe_redirect( add_query_arg( 'sdsc_err', 1, $back ) . '#sdsc' ); exit;
		}
		global $wpdb;
		$wpdb->update( SDSC_Registration::table(), array( 'parent_name' => mb_substr( $name, 0, 200 ), 'parent_phone' => mb_substr( $phone, 0, 40 ), 'parent_email' => $email, 'parent_consent' => 1, 'parent_sig' => $sig ), array( 'id' => $rid ) );
		$r = self::row( $rid );
		if ( $r && ! self::issues( $r ) ) { self::stamp( $rid, (int) $r->follow_n, true ); }
		wp_safe_redirect( add_query_arg( 'sdsc_done', 1, $back ) . '#sdsc' );
		exit;
	}
}
