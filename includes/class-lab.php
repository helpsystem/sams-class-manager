<?php
/**
 * Test lab — TEST SITE ONLY (testsaman.samsdrivingschool.org). One page that walks the school team through a
 * full real-data test on a phone, a tablet and the office scanner:
 *   1. the online registration form (as a student would fill it, with a finger signature),
 *   2. the ID / permit scan in Student documents (camera, photo or scanner file) and the comparison,
 *   3. every printed form (registration form, test answer sheet, attendance, ID copy, MVA DE-003),
 *   4. erase everything that was entered for that e-mail when the test is over.
 * The test site never e-mails the office (SDSC_Mailer::admin_report). This file is not installed on the live site.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Lab {

	public static function on() { return false !== strpos( home_url(), 'testsaman.' ); }

	public static function init() {
		if ( ! self::on() ) { return; }
		add_action( 'template_redirect', array( __CLASS__, 'page' ), 0 );
	}

	public static function url( $email = '' ) { return add_query_arg( array_filter( array( 'sdsc_lab' => 1, 'e' => $email ? strtolower( $email ) : null ) ), home_url( '/' ) ); }

	/** The page that shows the online registration form. */
	protected static function reg_page() {
		global $wpdb;
		$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE '%sdsc_registration_form%' ORDER BY ID LIMIT 1" ); // phpcs:ignore
		if ( ! $id ) { $id = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_status = 'publish' AND m.meta_value LIKE '%sdsc_registration_form%' ORDER BY p.ID LIMIT 1" ); } // phpcs:ignore
		return $id ? get_permalink( $id ) : '';
	}

	protected static function state( $email ) {
		global $wpdb;
		$s = array( 'regs' => array(), 'docs' => 0, 'check' => array(), 'summary' => '' );
		if ( ! is_email( $email ) ) { return $s; }
		$s['regs']  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, source_entry_id, created_at, first_name, middle_name, last_name, dob, class_id, (student_sig IS NOT NULL) has_sig FROM ' . SDSC_Registration::table() . ' WHERE email = %s ORDER BY id DESC', $email ), ARRAY_A ); // phpcs:ignore
		$s['docs']  = class_exists( 'SDSC_Docs' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SDSC_Docs::t_docs() . ' WHERE email = %s', $email ) ) : 0; // phpcs:ignore
		$s['check'] = class_exists( 'SDSC_IDScan' ) ? SDSC_IDScan::get( $email ) : array();
		$s['summary'] = class_exists( 'SDSC_IDScan' ) ? SDSC_IDScan::summary( $email ) : '';
		return $s;
	}

	protected static function print_url( $reg, $email, $parts ) {
		return add_query_arg( array(
			'sdsc_report' => 'file',
			'from'        => substr( $reg['created_at'], 0, 10 ),
			'to'          => current_time( 'Y-m-d' ),
			'who'         => $email,
			'rid'         => (int) $reg['id'],
			'out'         => 'print',
			'parts'       => $parts,
			'_wpnonce'    => wp_create_nonce( 'sdsc_report' ),
		), home_url( '/' ) );
	}

	/** Erase everything entered for this e-mail on the test site. */
	protected static function erase( $email ) {
		global $wpdb;
		$n = array();
		$t = SDSC_Registration::table();
		$n['registration forms'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE email = %s", $email ) ); // phpcs:ignore
		$posts = get_posts( array( 'post_type' => SDSC_CPT_Registrants::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_sdsc_email', 'value' => $email ) ) ) );
		foreach ( $posts as $pid ) { wp_delete_post( $pid, true ); }
		$n['class sign-ups'] = count( $posts );
		if ( class_exists( 'SDSC_Docs' ) ) {
			$dir  = SDSC_Docs::dir();
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, file, thumb FROM ' . SDSC_Docs::t_docs() . ' WHERE email = %s', $email ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $r ) {
				foreach ( array( $r['file'], $r['thumb'] ) as $f ) { if ( $dir && preg_match( '/^[a-f0-9]{32}\.bin$/', (string) $f ) && is_file( $dir . $f ) ) { @unlink( $dir . $f ); } } // phpcs:ignore
				$wpdb->delete( SDSC_Docs::t_log(), array( 'doc_id' => (int) $r['id'] ) );
				$wpdb->delete( SDSC_Docs::t_docs(), array( 'id' => (int) $r['id'] ) );
			}
			$n['documents'] = count( $rows );
		}
		$n['ID checks'] = delete_option( 'sdsc_idc_' . md5( $email ) ) ? 1 : 0;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}driveflow_students SET license_number = '' WHERE LOWER(email) = %s", $email ) ); // phpcs:ignore
		$links = get_option( 'sdsc_reg_links', array() );
		if ( is_array( $links ) && isset( $links[ $email ] ) ) { unset( $links[ $email ] ); update_option( 'sdsc_reg_links', $links, false ); }
		return $n;
	}

	public static function page() {
		if ( empty( $_GET['sdsc_lab'] ) ) { return; } // phpcs:ignore
		if ( ! is_user_logged_in() ) { auth_redirect(); }
		if ( ! class_exists( 'SDSC_Docs' ) || ! SDSC_Docs::is_office() ) { wp_die( 'The test lab is for the school team. Sign in with your staff account.', 'Test lab', array( 'response' => 403 ) ); }
		$uid   = get_current_user_id();
		$email = strtolower( sanitize_email( wp_unslash( $_REQUEST['e'] ?? get_user_meta( $uid, 'sdsc_lab_email', true ) ) ) ); // phpcs:ignore
		if ( is_email( $email ) ) { update_user_meta( $uid, 'sdsc_lab_email', $email ); }
		$msg = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['erase'] ) && is_email( $email ) && check_admin_referer( 'sdsc_lab_erase' ) ) {
			$n   = self::erase( $email );
			$msg = 'Erased for ' . $email . ': ' . implode( ', ', array_map( function ( $k, $v ) { return $v . ' ' . $k; }, array_keys( $n ), $n ) ) . '.';
		}
		$s     = self::state( $email );
		$reg   = $s['regs'][0] ?? null;
		$regp  = self::reg_page();
		$docs  = is_email( $email ) ? SDSC_Docs::url( $email ) : '';
		$names = class_exists( 'SDSC_Student_File' ) ? SDSC_Student_File::parts() : array();
		$self  = self::url( $email );
		$e     = function ( $v ) { return esc_html( (string) $v ); };

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Test lab — Sam’s Driving School</title>
<style>
:root{--g:#00703c;--g2:#e8f5ee;--ink:#1d2327;--mut:#5b646d;--ln:#e3e7ea;--warn:#8a5a00;--wbg:#fff6e0}
*{box-sizing:border-box}body{margin:0;background:#f4f6f7;color:var(--ink);font:15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
.w{max-width:760px;margin:0 auto;padding:16px}
.top{background:var(--g);color:#fff;border-radius:14px;padding:16px 18px;margin-bottom:14px}
.top h1{margin:0 0 4px;font-size:20px}.top p{margin:0;opacity:.9;font-size:13.5px}
.note{background:var(--wbg);color:var(--warn);border-radius:10px;padding:10px 12px;font-size:13.5px;margin:0 0 14px}
.ok{background:var(--g2);color:var(--g)}
.card{background:#fff;border:1px solid var(--ln);border-radius:14px;padding:16px;margin-bottom:12px}
.card h2{margin:0 0 6px;font-size:16.5px;display:flex;gap:10px;align-items:center}
.n{flex:0 0 26px;height:26px;border-radius:50%;background:var(--g);color:#fff;font-size:13px;display:inline-flex;align-items:center;justify-content:center}
.card p{margin:4px 0 10px;color:var(--mut);font-size:14px}
.st{display:inline-block;font-size:12.5px;font-weight:600;padding:3px 9px;border-radius:99px;background:#f0f1f2;color:var(--mut);margin:0 0 10px}
.st.y{background:var(--g2);color:var(--g)}
.btns{display:flex;flex-wrap:wrap;gap:8px}
.b{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:10px 14px;border-radius:10px;background:var(--g);color:#fff;text-decoration:none;font-weight:600;font-size:14.5px;border:0;cursor:pointer}
.b.o{background:#fff;color:var(--g);box-shadow:inset 0 0 0 1.5px var(--g)}
.b.r{background:#b32d2e}
form.em{display:flex;gap:8px;flex-wrap:wrap}form.em input{flex:1 1 220px;min-height:44px;padding:10px 12px;border:1.5px solid var(--ln);border-radius:10px;font-size:16px}
ul{margin:6px 0 10px 18px;padding:0;color:var(--mut);font-size:14px}li{margin:3px 0}
.qr{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.qr canvas,.qr img{width:150px;height:150px;image-rendering:pixelated;border:8px solid #fff;outline:1px solid var(--ln);border-radius:6px}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;word-break:break-all;color:var(--mut)}
table{width:100%;border-collapse:collapse;font-size:13.5px;margin:6px 0 10px}td,th{border-bottom:1px solid var(--ln);padding:6px 4px;text-align:left}th{color:var(--mut);font-weight:600}
@media(max-width:520px){.w{padding:10px}.b{flex:1 1 100%;justify-content:center}}
</style></head><body><div class="w">
<div class="top"><h1>🧪 Test lab</h1><p>Test site only — nothing here reaches students or e-mails the office. Use your own real details; erase them at the end (step 5).</p></div>
<?php if ( $msg ) { echo '<p class="note ok">' . $e( $msg ) . '</p>'; } ?>

<div class="card"><h2><span class="n">0</span>Your test e-mail</h2>
<p>The e-mail you will use on the registration form. Everything below follows it.</p>
<form class="em" method="get"><input type="hidden" name="sdsc_lab" value="1"><input type="email" name="e" required placeholder="you@example.com" value="<?php echo esc_attr( $email ); ?>" autocomplete="email"><button class="b" type="submit">Use this e-mail</button></form>
<?php if ( is_email( $email ) ) : ?>
<div class="qr" style="margin-top:12px"><div id="qr"></div><div><b>Open this page on the phone</b><p style="margin:4px 0">Scan the code with the phone camera, sign in with your staff account, and you land on this same page.</p><div class="mono"><?php echo $e( $self ); ?></div></div></div>
<?php endif; ?>
</div>

<?php if ( is_email( $email ) ) : ?>
<div class="card"><h2><span class="n">1</span>Registration form</h2>
<?php if ( $reg ) : ?>
<span class="st y">✓ Found — file no. <?php echo $e( SDSC_Registration::file_no( $reg ) ); ?> · <?php echo $e( trim( $reg['first_name'] . ' ' . $reg['last_name'] ) ); ?> · <?php echo $reg['has_sig'] ? 'signed' : 'no signature'; ?></span>
<?php else : ?>
<span class="st">Not filled yet</span>
<?php endif; ?>
<p>Fill it like a student, on the phone: your real name, date of birth and address exactly as you normally write them, sign with your finger. <b>Stop at the payment page</b> — do not pay.</p>
<div class="btns"><?php if ( $regp ) : ?><a class="b" href="<?php echo esc_url( $regp ); ?>" target="_blank" rel="noopener">📝 Open the registration form</a><?php else : ?><span class="st">The registration page was not found on the test site.</span><?php endif; ?><a class="b o" href="<?php echo esc_url( $self ); ?>">↻ Check again</a></div>
</div>

<div class="card"><h2><span class="n">2</span>Scan your ID / permit — phone or tablet</h2>
<span class="st<?php echo $s['summary'] ? ' y' : ''; ?>"><?php echo $s['summary'] ? '✓ ' . $e( $s['summary'] ) : 'No scan saved yet'; ?></span>
<?php if ( ! empty( $s['check']['diff'] ) ) { echo '<p><b>Differences noted:</b> ' . $e( implode( ', ', (array) $s['check']['diff'] ) ) . '</p>'; } ?>
<ul><li><b>Live camera</b>: hold the <b>back</b> of the card inside the frame — the barcode is read on its own.</li><li><b>Take photo</b>: the front side, flat, good light, no glare.</li><li>Check the comparison table: ✓ same, ✗ different. Then <b>Save the check</b>; try <b>Copy into registration</b> on one field.</li></ul>
<div class="btns"><?php if ( $docs ) : ?><a class="b" href="<?php echo esc_url( $docs ); ?>" target="_blank" rel="noopener">📷 Open Student documents</a><?php endif; ?></div>
</div>

<div class="card"><h2><span class="n">3</span>Scan with the office scanner — computer</h2>
<p>Open this page on the office computer (same link as above), scan the card on the scanner (JPG, PNG or PDF, 300 dpi), then in Student documents press <b>Scanner file</b> and choose the file. A crooked scan is straightened and cropped by itself.</p>
<span class="st<?php echo $s['docs'] ? ' y' : ''; ?>"><?php echo (int) $s['docs']; ?> document<?php echo 1 === (int) $s['docs'] ? '' : 's'; ?> on file</span>
</div>

<div class="card"><h2><span class="n">4</span>Printed forms</h2>
<?php if ( $reg && $names ) : ?>
<p>Each one opens in a new tab, US Letter. Check that every box is filled, readable and fits.</p>
<div class="btns">
<?php foreach ( $names as $k => $label ) { echo '<a class="b o" target="_blank" rel="noopener" href="' . esc_url( self::print_url( $reg, $email, array( $k ) ) ) . '">🖨 ' . $e( $label ) . '</a>'; } ?>
<a class="b" target="_blank" rel="noopener" href="<?php echo esc_url( self::print_url( $reg, $email, array_keys( $names ) ) ); ?>">🖨 All forms together</a>
</div>
<?php else : ?>
<span class="st">Available after step 1</span>
<?php endif; ?>
</div>

<div class="card"><h2><span class="n">5</span>Erase my test data</h2>
<p>Removes from the test site everything entered for <b><?php echo $e( $email ); ?></b>: <?php echo count( $s['regs'] ); ?> registration form(s), the class sign-up, <?php echo (int) $s['docs']; ?> document(s) and the ID check.</p>
<form method="post" id="er"><?php wp_nonce_field( 'sdsc_lab_erase' ); ?><input type="hidden" name="e" value="<?php echo esc_attr( $email ); ?>"><button class="b r" type="button" id="erb">🗑 Erase my test data</button><button class="b r" type="submit" name="erase" value="1" id="ers" hidden>Tap again to erase — this cannot be undone</button></form>
</div>
<script src="<?php echo esc_url( SDSC_URL . 'assets/vendor/qrcode.min.js?ver=1.4.4' ); ?>"></script>
<script>(function(){try{var q=qrcode(0,'M');q.addData(<?php echo wp_json_encode( $self ); ?>);q.make();document.getElementById('qr').innerHTML=q.createImgTag(5,0);}catch(e){}
var a=document.getElementById('erb'),b=document.getElementById('ers');a.addEventListener('click',function(){a.hidden=true;b.hidden=false;setTimeout(function(){a.hidden=false;b.hidden=true;},6000);});})();</script>
<?php endif; ?>
</div></body></html>
		<?php
		exit;
	}
}
