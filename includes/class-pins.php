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
		add_action( 'wp_footer', array( __CLASS__, 'sidenav' ) );
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
		wp_send_json_success( array( 'html' => self::strip(), 'quick' => self::quick(), 'side' => self::side_pins(), 'pins' => $p, 'msg' => $msg ) );
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
			. '.spn-star{position:absolute;top:2px;right:2px;border:0;background:none;font-size:17px;line-height:1;color:#9aa3ab;cursor:pointer;padding:9px;min-width:36px;min-height:36px}.spn-star.is-on{color:#c99500}.spn-star:hover{color:#c99500}'
			. '.sov-tool-w{position:relative}.sov-tool-w .sov-tool{padding-right:30px}'
			. '@media(max-width:600px){.spn-panel{position:fixed;left:4vw;right:4vw;top:auto;bottom:12px;width:auto}}';
		$js  = '(function(){var AJ=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',N=' . wp_json_encode( wp_create_nonce( 'sdsc_pin' ) ) . ';'
			. 'function send(body,cb){var x=new XMLHttpRequest();x.open("POST",AJ);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.onload=function(){var r=null;try{r=JSON.parse(x.responseText);}catch(e){}cb(r);};x.send("action=sdsc_pin&_n="+encodeURIComponent(N)+"&"+body);}'
			. 'function apply(r,keepOpen){if(!r||!r.success)return;var s=document.getElementById("sdsc-pins");if(s){var w=document.createElement("div");w.innerHTML=r.data.html;var n=w.firstChild;if(keepOpen){var d=n.querySelector(".spn-add");if(d)d.open=true;}s.parentNode.replaceChild(n,s);bindDrag();var m=n.querySelector(".spn-msg");if(m)m.textContent=r.data.msg||"";}'
			. '[].forEach.call(document.querySelectorAll(".spn-star"),function(b){var on=r.data.pins.indexOf(b.getAttribute("data-pin"))>-1;b.classList.toggle("is-on",on);b.textContent=on?"★":"☆";b.setAttribute("aria-pressed",on?"true":"false");b.title=on?"Unpin":"Pin to the top";});'
			. 'var sp=document.getElementById("snv-pins");if(sp&&r.data.side){sp.outerHTML=r.data.side;}[].forEach.call(document.querySelectorAll(".sws-pins"),function(q){q.style.display=r.data.pins.length?"":"none";q.innerHTML="<span class=sws-pin-h aria-hidden=true>📌</span>"+r.data.quick;});}'
			. 'document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest("[data-pin]");if(!b)return;e.preventDefault();var k=b.getAttribute("data-pin"),on=b.hasAttribute("data-off")?0:(b.classList.contains("is-on")?0:1),open=!!b.closest(".spn-panel");send("k="+k+"&on="+on,function(r){apply(r,open);});});'
			. 'function bindDrag(){var l=document.querySelector("#sdsc-pins .spn-list");if(!l||l._b)return;l._b=1;var d=null;l.addEventListener("dragstart",function(e){d=e.target.closest(".spn-pin");if(d){d.classList.add("is-drag");e.dataTransfer.effectAllowed="move";}});'
			. 'l.addEventListener("dragover",function(e){if(!d)return;e.preventDefault();var t=e.target.closest(".spn-pin");if(t&&t!==d){var r=t.getBoundingClientRect();l.insertBefore(d,(e.clientX-r.left)>r.width/2?t.nextSibling:t);}});'
			. 'l.addEventListener("dragend",function(){if(!d)return;d.classList.remove("is-drag");d=null;var ks=[].map.call(l.querySelectorAll(".spn-pin"),function(p){return p.getAttribute("data-key");});send("do=order&keys="+ks.join(","),function(){});});}'
			. 'if(document.readyState!=="loading")bindDrag();else document.addEventListener("DOMContentLoaded",bindDrag);'
			. 'document.addEventListener("click",function(e){var o=document.querySelector("#sdsc-pins .spn-add[open]");if(o&&!o.contains(e.target))o.open=false;});})();';
		return '<style id="sdsc-pins-css">' . $css . '</style><script>' . $js . '</script>';
	}

	/* ------------------------------------------------------------ side menu */

	/** One colour per group, used for the group heading and the icon tiles. */
	public static function color( $group ) {
		$c = array(
			'Lessons & attendance' => '#0284c7', 'Students & classes' => '#7c3aed', 'Instructors & cars' => '#ea580c', 'Money & accounting' => '#059669',
			'Messages' => '#db2777', 'Reports' => '#0d9488', 'Website' => '#4f46e5', 'Setup' => '#475569', 'Help' => '#a16207', 'My dashboard' => '#334155',
		);
		return $c[ $group ] ?? '#00703c';
	}

	/** Pinned block at the top of the side menu. */
	public static function side_pins() {
		$cat  = self::catalog();
		$pins = self::mine();
		$h    = '<div id="snv-pins" class="snv-pins"><p class="snv-h"><span aria-hidden="true">📌</span> Pinned</p>';
		foreach ( $pins as $k ) {
			$i  = $cat[ $k ];
			$h .= '<a class="snv-a" href="' . esc_url( $i[2] ) . '" style="--c:' . esc_attr( self::color( $i[4] ) ) . '"><span class="snv-ic" aria-hidden="true">' . esc_html( $i[0] ) . '</span><span class="snv-t">' . esc_html( $i[1] ) . '</span></a>';
		}
		if ( ! $pins ) { $h .= '<p class="snv-hint">Tap ☆ next to any screen below to keep it here.</p>'; }
		return $h . '</div>';
	}

	/** School team: the dashboard side menu becomes Pinned + collapsible colour-coded groups with a search box. */
	public static function sidenav() {
		if ( ! is_user_logged_in() || ! class_exists( 'SDSC_Workspace' ) || ! SDSC_Workspace::is_staff() || ! function_exists( 'tutor_utils' ) ) { return; }
		$page = (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' );
		if ( ! $page || ! is_page( $page ) ) { return; }
		$cat = self::catalog();
		if ( ! $cat ) { return; }
		$pins   = self::mine();
		$groups = array();
		foreach ( $cat as $k => $i ) { $groups[ $i[4] ][ $k ] = $i; }
		$h = '<div id="snv" class="snv" hidden><div class="snv-find"><input type="search" placeholder="Find a screen…" aria-label="Find a screen" autocomplete="off"></div>' . self::side_pins();
		foreach ( $groups as $g => $items ) {
			$h .= '<details class="snv-g" data-g="' . esc_attr( sanitize_title( $g ) ) . '" style="--c:' . esc_attr( self::color( $g ) ) . '"><summary><span class="snv-dot" aria-hidden="true"></span><span class="snv-gl">' . esc_html( $g ) . '</span><span class="snv-n">' . count( $items ) . '</span></summary><ul>';
			foreach ( $items as $k => $i ) {
				$on = in_array( $k, $pins, true );
				$h .= '<li class="snv-i"><a class="snv-a" href="' . esc_url( $i[2] ) . '"' . ( $i[3] ? ' title="' . esc_attr( $i[3] ) . '"' : '' ) . '><span class="snv-ic" aria-hidden="true">' . esc_html( $i[0] ) . '</span><span class="snv-t">' . esc_html( $i[1] ) . '</span></a>'
					. '<button type="button" class="spn-star snv-star' . ( $on ? ' is-on' : '' ) . '" data-pin="' . esc_attr( $k ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '" title="' . ( $on ? 'Unpin' : 'Pin to the top' ) . '">' . ( $on ? '★' : '☆' ) . '</button></li>';
			}
			$h .= '</ul></details>';
		}
		$h .= '</div>';
		$css = '.tutor-dashboard-sidebar-nav:has(#snv){max-height:calc(100vh - 130px);overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;padding-right:4px}'
			. '.snv{margin:4px 0 10px;font-size:15px;font-family:Inter,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}.snv[hidden]{display:none}'
			. '.snv-find input{width:100%;box-sizing:border-box;padding:9px 11px;margin:4px 0 8px;border:1px solid #dfe3e6;border-radius:10px;font-size:14.5px;background:#fff}'
			. '.snv-pins{margin:0 0 8px;padding:8px;border-radius:12px;background:#fffaf0;box-shadow:inset 0 0 0 1px #f3e2b3}'
			. '.snv-h{margin:0 0 6px 4px;font-size:13px;font-weight:700;letter-spacing:0;color:#a16207}'
			. '.snv-hint{margin:2px 4px;font-size:13.5px;color:#6b7280;line-height:1.45}'
			. '.snv-g+.snv-g{border-top:1px solid #f0f2f4}'
			. '.snv-g summary{display:flex;align-items:center;gap:9px;padding:10px 8px;list-style:none;cursor:pointer;font-size:14px;font-weight:700;letter-spacing:0;text-transform:none;color:var(--c);border-radius:8px;line-height:1.25}'
			. '.snv-g summary::-webkit-details-marker{display:none}.snv-g summary:hover{background:#f6f8f9}'
			. '.snv-g summary::after{content:"";margin-left:6px;width:7px;height:7px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(-45deg);transition:transform .15s}.snv-g[open] summary::after{transform:rotate(45deg)}'
			. '.snv-dot{width:9px;height:9px;border-radius:50%;background:var(--c);flex:none}'
			. '.snv-gl{flex:1;min-width:0}'
			. '.snv-n{font-size:12px;font-weight:700;color:#6b7280;background:#f1f3f5;border-radius:99px;padding:1px 8px;letter-spacing:0}'
			. '.snv-g ul{margin:0;padding:0 0 6px 4px;list-style:none}'
			. '.snv-i{display:flex;align-items:center;margin:0;list-style:none}'
			. '.snv-a{display:flex;flex:1;min-width:0;align-items:center;gap:10px;padding:6px 8px;border-radius:10px;color:#1a1f24!important;text-decoration:none!important;font-size:15px;font-weight:500;line-height:1.3}'
			. '.snv-a:hover{background:#f3f6f7}.snv-a.is-here{background:color-mix(in srgb,var(--c) 10%,#fff);font-weight:700;box-shadow:inset 3px 0 0 var(--c)}'
			. '.snv-ic{flex:none;display:inline-grid;place-items:center;width:30px;height:30px;border-radius:9px;font-size:16px;background:color-mix(in srgb,var(--c) 15%,#fff);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--c) 32%,#fff)}'
			. '.snv-t{min-width:0;white-space:normal;overflow-wrap:anywhere}.snv li::before,.snv li::after{content:none!important;display:none!important}'
			. '.snv .snv-star{position:static;flex:none;min-width:26px;min-height:30px;padding:2px;font-size:15px;color:#9aa3ab;opacity:0}'
			. '.snv-i:hover .snv-star,.snv .snv-star:focus,.snv .snv-star.is-on{opacity:1}.snv .snv-star.is-on{color:#c99500}'
			. '@media(hover:none){.snv .snv-star{opacity:.55}}';
		$js = '(function(){var s=document.getElementById("snv"),nav=document.querySelector(".tutor-dashboard-sidebar-nav");if(!s||!nav)return;'
			. 'var ul=nav.querySelector("ul");if(ul){var keep=/tutor-dashboard-menu-(index|logout)\b/;[].forEach.call(ul.children,function(li){if(!keep.test(li.className))li.style.display="none";});var out=ul.querySelector(".tutor-dashboard-menu-logout");nav.insertBefore(s,ul.nextSibling);if(out){var u2=document.createElement("ul");u2.className=ul.className;u2.appendChild(out);nav.insertBefore(u2,s.nextSibling);}}else{nav.insertBefore(s,nav.firstChild);}'
			. 's.hidden=false;var K="snv-open",saved=[];try{saved=JSON.parse(localStorage.getItem(K)||"[]")||[];}catch(e){}'
			. 'var gs=s.querySelectorAll(".snv-g");[].forEach.call(gs,function(d){if(saved.indexOf(d.getAttribute("data-g"))>-1)d.open=true;});'
			. 'var q=new URLSearchParams(location.search),here=location.pathname.replace(/\/$/,"");'
			. 'function mark(){[].forEach.call(s.querySelectorAll("a.snv-a"),function(a){var u;try{u=new URL(a.href,location.href);}catch(e){return;}var m=u.pathname.replace(/\/$/,"")===here&&(u.searchParams.get("screen")||"")===(q.get("screen")||"")&&(u.searchParams.get("page")||"")===(q.get("page")||"");a.classList.toggle("is-here",m);if(m){var d=a.closest("details");if(d)d.open=true;}});}mark();'
			. 'function save(){if(f.value)return;var o=[];[].forEach.call(gs,function(d){if(d.open)o.push(d.getAttribute("data-g"));});try{localStorage.setItem(K,JSON.stringify(o));}catch(e){}}'
			. '[].forEach.call(gs,function(d){d.addEventListener("toggle",save);});'
			. 'var f=s.querySelector(".snv-find input"),before=null;f.addEventListener("input",function(){var v=f.value.trim().toLowerCase();if(v&&!before){before=[].map.call(gs,function(d){return d.open;});}'
			. '[].forEach.call(gs,function(d,i){var n=0;[].forEach.call(d.querySelectorAll(".snv-i"),function(li){var ok=!v||li.textContent.toLowerCase().indexOf(v)>-1;li.style.display=ok?"":"none";if(ok)n++;});d.style.display=v&&!n?"none":"";if(v){d.open=n>0;}else if(before){d.open=before[i];}});if(!v)before=null;});'
			. 'new MutationObserver(function(){mark();}).observe(s,{childList:true});})();';
		echo self::assets() . '<style id="snv-css">' . $css . '</style>' . $h . '<script>' . $js . '</script>'; // phpcs:ignore -- escaped above
	}

	/** Pinned links for the Workspace bar (school team, every dashboard page). */
	public static function quick() {
		$cat = self::catalog();
		$h   = '';
		foreach ( self::mine() as $k ) { $h .= '<a href="' . esc_url( $cat[ $k ][2] ) . '" title="Pinned">' . esc_html( $cat[ $k ][0] . ' ' . $cat[ $k ][1] ) . '</a>'; }
		return $h;
	}
}
