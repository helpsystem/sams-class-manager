<?php
/**
 * Pinned screens: everyone (administrator, school manager, front desk, instructor, student) can pin the
 * screens they use most. The pins show as a row at the top of the dashboard home and, for the school team,
 * in the Workspace bar on every dashboard page.
 *
 * Only screens the person can already open can be pinned: the list is built from their own Workspace menu
 * and their own dashboard menu, and every pin is checked against that list again when it is shown.
 *
 * @package SamsClassManager
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SDSC_Pins {
	const META = 'sdsc_pins';
	const MAX  = 10;

	public static function init() {
		add_action( 'wp_ajax_sdsc_pin', array( __CLASS__, 'ajax' ) );
	}

	/** Tutor dashboard menu items for this person: key => [icon, label, url, desc]. */
	protected static function tutor_items() {
		if ( ! function_exists( 'tutor_utils' ) || ! method_exists( tutor_utils(), 'tutor_dashboard_nav_ui_items' ) ) { return array(); }
		$icons = array( 'courses' => '📚', 'enrolled-courses' => '📚', 'my-quiz-attempts' => '📝', 'quiz-attempts' => '📝', 'calendar' => '📅', 'sdsc-lessons' => '🚗', 'sdsc-documents' => '📎', 'sdsc-messages' => '💬', 'sdsc-zoom-attendance' => '✅', 'sdsc-reports' => '📊', 'settings' => '⚙️', 'purchase_history' => '🧾', 'order-history' => '🧾', 'wishlist' => '♡', 'reviews' => '⭐', 'announcements' => '📣', 'question-answer' => '❓', 'assignments' => '📄', 'my-profile' => '👤', 'notes' => '🗒️' );
		$out   = array();
		foreach ( (array) tutor_utils()->tutor_dashboard_nav_ui_items() as $key => $v ) {
			if ( ! is_array( $v ) || in_array( $key, array( 'index', 'logout' ), true ) || ( $v['type'] ?? '' ) === 'separator' || empty( $v['title'] ) ) { continue; }
			if ( isset( $v['show_ui'] ) && ! $v['show_ui'] ) { continue; }
			$url = ! empty( $v['url'] ) ? $v['url'] : tutor_utils()->tutor_dashboard_url( $key );
			$out[] = array( $icons[ $key ] ?? ( 0 === strpos( $key, 'sdsc-w-' ) ? '🗂️' : '🔖' ), wp_strip_all_tags( $v['title'] ), $url, '' );
		}
		return $out;
	}

	/** Everything this person may pin: key => [icon, label, url, desc, group]. */
	public static function catalog() {
		static $c = array();
		$uid = get_current_user_id();
		if ( isset( $c[ $uid ] ) ) { return $c[ $uid ]; }
		$out = array();
		$add = function ( $i, $group ) use ( &$out ) {
			$k = substr( md5( strtok( (string) $i[2], '#' ) ), 0, 12 );
			if ( ! isset( $out[ $k ] ) ) { $out[ $k ] = array( $i[0], $i[1], $i[2], $i[3] ?? '', $group ); }
		};
		if ( class_exists( 'SDSC_Workspace' ) && SDSC_Workspace::is_staff() ) {
			$g = SDSC_Workspace::groups();
			foreach ( SDSC_Workspace::menu() as $grp => $items ) { foreach ( $items as $i ) { $add( $i, $g[ $grp ] ?? 'Workspace' ); } }
		}
		foreach ( self::tutor_items() as $i ) { $add( $i, 'My dashboard' ); }
		$c[ $uid ] = $out;
		return $out;
	}

	public static function key_for( $url ) { return substr( md5( strtok( (string) $url, '#' ) ), 0, 12 ); }

	/** Pinned keys still allowed for this person, in their order. */
	public static function mine() {
		$p   = get_user_meta( get_current_user_id(), self::META, true );
		$cat = self::catalog();
		return array_values( array_filter( is_array( $p ) ? $p : array(), function ( $k ) use ( $cat ) { return isset( $cat[ $k ] ); } ) );
	}

	public static function ajax() {
		if ( ! is_user_logged_in() || ! check_ajax_referer( 'sdsc_pin', '_n', false ) ) { wp_send_json_error( 'Not allowed', 403 ); }
		$k   = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['k'] ?? '' ) ); // phpcs:ignore
		$on  = ! empty( $_POST['on'] ); // phpcs:ignore
		$cat = self::catalog();
		$p   = self::mine();
		$msg = '';
		if ( 'order' === ( $_POST['do'] ?? '' ) ) { // phpcs:ignore
			$keys = array_values( array_filter( array_map( function ( $x ) { return preg_replace( '/[^a-f0-9]/', '', $x ); }, explode( ',', (string) wp_unslash( $_POST['keys'] ?? '' ) ) ), function ( $x ) use ( $p ) { return in_array( $x, $p, true ); } ) ); // phpcs:ignore
			$p    = array_values( array_unique( array_merge( $keys, $p ) ) );
		} elseif ( isset( $cat[ $k ] ) ) {
			$p = array_values( array_diff( $p, array( $k ) ) );
			if ( $on ) {
				if ( count( $p ) >= self::MAX ) { $msg = 'You can pin up to ' . self::MAX . ' screens. Unpin one first.'; }
				else { $p[] = $k; }
			}
		}
		update_user_meta( get_current_user_id(), self::META, $p );
		wp_send_json_success( array( 'html' => self::strip(), 'quick' => self::quick(), 'pins' => $p, 'msg' => $msg ) );
	}

	/** Star button for a screen (used next to each tool on the dashboard home). */
	public static function star( $url ) {
		$k  = self::key_for( $url );
		if ( ! isset( self::catalog()[ $k ] ) ) { return ''; }
		$on = in_array( $k, self::mine(), true );
		return '<button type="button" class="spn-star' . ( $on ? ' is-on' : '' ) . '" data-pin="' . esc_attr( $k ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '" title="' . ( $on ? 'Unpin' : 'Pin to the top' ) . '">' . ( $on ? '★' : '☆' ) . '</button>';
	}

	/** The pinned row with its "Pin a screen" picker. */
	public static function strip() {
		if ( ! is_user_logged_in() ) { return ''; }
		$cat  = self::catalog();
		if ( ! $cat ) { return ''; }
		$pins = self::mine();
		$h    = '<section class="spn" id="sdsc-pins" aria-label="Pinned screens"><div class="spn-row"><span class="spn-h">📌 Pinned</span><div class="spn-list">';
		foreach ( $pins as $k ) {
			$i  = $cat[ $k ];
			$h .= '<span class="spn-pin" draggable="true" data-key="' . esc_attr( $k ) . '"><a href="' . esc_url( $i[2] ) . '"><span aria-hidden="true">' . esc_html( $i[0] ) . '</span> ' . esc_html( $i[1] ) . '</a><button type="button" class="spn-x" data-pin="' . esc_attr( $k ) . '" data-off="1" aria-label="Unpin ' . esc_attr( $i[1] ) . '" title="Unpin">×</button></span>';
		}
		if ( ! $pins ) { $h .= '<span class="spn-hint">Pin the screens you use most — they will wait for you here.</span>'; }
		$h .= '</div><details class="spn-add"><summary>＋ Pin a screen</summary><div class="spn-panel">';
		$groups = array();
		foreach ( $cat as $k => $i ) { $groups[ $i[4] ][ $k ] = $i; }
		foreach ( $groups as $g => $items ) {
			$h .= '<div class="spn-col"><p>' . esc_html( $g ) . '</p>';
			foreach ( $items as $k => $i ) {
				$on = in_array( $k, $pins, true );
				$h .= '<button type="button" class="spn-opt' . ( $on ? ' is-on' : '' ) . '" data-pin="' . esc_attr( $k ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '"><span class="spn-s" aria-hidden="true">' . ( $on ? '★' : '☆' ) . '</span><span aria-hidden="true">' . esc_html( $i[0] ) . '</span> ' . esc_html( $i[1] ) . '</button>';
			}
			$h .= '</div>';
		}
		$h .= '</div></details></div><p class="spn-msg" role="status"></p></section>';
		return $h;
	}

	/** Small inline script and style, printed once per page. */
	public static function assets() {
		static $done = false;
		if ( $done ) { return ''; }
		$done = true;
		$css = '.spn{margin:0 0 14px;padding:10px 12px;border-radius:14px;background:#fff;box-shadow:0 0 0 1px #e3e7ea}'
			. '.spn-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}'
			. '.spn-h{font-size:12px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#00703c;margin-right:4px}'
			. '.spn-list{display:flex;flex-wrap:wrap;gap:6px;flex:1 1 auto;min-width:0}'
			. '.spn-pin{display:inline-flex;align-items:center;border-radius:999px;background:#ecf7f1;box-shadow:inset 0 0 0 1px #b8dcc6;cursor:grab}'
			. '.spn-pin.is-drag{opacity:.4}'
			. '.spn-pin a{padding:7px 4px 7px 12px;color:#0b3d24;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap}'
			. '.spn-x{border:0;background:none;color:#5b646d;font-size:16px;line-height:1;padding:4px 10px 6px 6px;cursor:pointer}.spn-x:hover{color:#b42318}'
			. '.spn-hint{font-size:13px;color:#5b646d}'
			. '.spn-add{position:relative}.spn-add summary{list-style:none;cursor:pointer;padding:7px 12px;border-radius:999px;font-size:13px;font-weight:700;color:#00703c;box-shadow:inset 0 0 0 1px #00703c;white-space:nowrap}.spn-add summary::-webkit-details-marker{display:none}'
			. '.spn-panel{position:absolute;right:0;top:calc(100% + 6px);z-index:50;width:min(760px,92vw);max-height:60vh;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;padding:12px;border-radius:14px;background:#fff;box-shadow:0 12px 34px rgba(0,0,0,.16),0 0 0 1px #e3e7ea}'
			. '.spn-col p{margin:2px 4px 6px;font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#00703c}'
			. '.spn-opt{display:flex;align-items:center;gap:6px;width:100%;padding:6px 8px;border:0;border-radius:8px;background:none;text-align:left;font-size:13px;color:#15191d;cursor:pointer}.spn-opt:hover{background:#f3f6f7}.spn-opt.is-on{font-weight:700}'
			. '.spn-s{color:#c99500;font-size:15px}'
			. '.spn-msg{margin:6px 0 0;font-size:12.5px;color:#8a4b00}.spn-msg:empty{display:none}'
			. '.spn-star{position:absolute;top:6px;right:6px;border:0;background:none;font-size:17px;line-height:1;color:#9aa3ab;cursor:pointer;padding:4px}.spn-star.is-on{color:#c99500}.spn-star:hover{color:#c99500}'
			. '.sov-tool-w{position:relative}.sov-tool-w .sov-tool{padding-right:30px}'
			. '@media(max-width:600px){.spn-panel{position:fixed;left:4vw;right:4vw;top:auto;bottom:12px;width:auto}}';
		$js  = '(function(){var AJ=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',N=' . wp_json_encode( wp_create_nonce( 'sdsc_pin' ) ) . ';'
			. 'function send(body,cb){var x=new XMLHttpRequest();x.open("POST",AJ);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.onload=function(){var r=null;try{r=JSON.parse(x.responseText);}catch(e){}cb(r);};x.send("action=sdsc_pin&_n="+encodeURIComponent(N)+"&"+body);}'
			. 'function apply(r,keepOpen){if(!r||!r.success)return;var s=document.getElementById("sdsc-pins");if(s){var w=document.createElement("div");w.innerHTML=r.data.html;var n=w.firstChild;if(keepOpen){var d=n.querySelector(".spn-add");if(d)d.open=true;}s.parentNode.replaceChild(n,s);bindDrag();var m=n.querySelector(".spn-msg");if(m)m.textContent=r.data.msg||"";}'
			. '[].forEach.call(document.querySelectorAll(".spn-star"),function(b){var on=r.data.pins.indexOf(b.getAttribute("data-pin"))>-1;b.classList.toggle("is-on",on);b.textContent=on?"★":"☆";b.setAttribute("aria-pressed",on?"true":"false");b.title=on?"Unpin":"Pin to the top";});'
			. '[].forEach.call(document.querySelectorAll(".sws-pins"),function(q){q.style.display=r.data.pins.length?"":"none";q.innerHTML="<span class=sws-pin-h aria-hidden=true>📌</span>"+r.data.quick;});}'
			. 'document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest("[data-pin]");if(!b)return;e.preventDefault();var k=b.getAttribute("data-pin"),on=b.hasAttribute("data-off")?0:(b.classList.contains("is-on")?0:1),open=!!b.closest(".spn-panel");send("k="+k+"&on="+on,function(r){apply(r,open);});});'
			. 'function bindDrag(){var l=document.querySelector("#sdsc-pins .spn-list");if(!l||l._b)return;l._b=1;var d=null;l.addEventListener("dragstart",function(e){d=e.target.closest(".spn-pin");if(d){d.classList.add("is-drag");e.dataTransfer.effectAllowed="move";}});'
			. 'l.addEventListener("dragover",function(e){if(!d)return;e.preventDefault();var t=e.target.closest(".spn-pin");if(t&&t!==d){var r=t.getBoundingClientRect();l.insertBefore(d,(e.clientX-r.left)>r.width/2?t.nextSibling:t);}});'
			. 'l.addEventListener("dragend",function(){if(!d)return;d.classList.remove("is-drag");d=null;var ks=[].map.call(l.querySelectorAll(".spn-pin"),function(p){return p.getAttribute("data-key");});send("do=order&keys="+ks.join(","),function(){});});}'
			. 'if(document.readyState!=="loading")bindDrag();else document.addEventListener("DOMContentLoaded",bindDrag);'
			. 'document.addEventListener("click",function(e){var o=document.querySelector("#sdsc-pins .spn-add[open]");if(o&&!o.contains(e.target))o.open=false;});})();';
		return '<style id="sdsc-pins-css">' . $css . '</style><script>' . $js . '</script>';
	}

	/** Pinned links for the Workspace bar (school team, every dashboard page). */
	public static function quick() {
		$cat = self::catalog();
		$h   = '';
		foreach ( self::mine() as $k ) { $h .= '<a href="' . esc_url( $cat[ $k ][2] ) . '" title="Pinned">' . esc_html( $cat[ $k ][0] . ' ' . $cat[ $k ][1] ) . '</a>'; }
		return $h;
	}
}
