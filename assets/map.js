/* Sam's Class Manager — map block (open-now, app order, copy) + directions chooser for address links. */
(function () {
	'use strict';
	var D = window.SDSC_MAP || {};
	var ua = navigator.userAgent || '';
	var ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	var mac = !ios && /Macintosh/.test(ua);
	var first = ios || mac ? 'apple' : 'google';
	var ICON = {
		google: '<path d="M21 3 3 10.5l7.2 2.3L12.5 20z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
		apple: '<path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="currentColor"/>',
		waze: '<path d="M5 16h14M6.5 16l1.6-5.2A2 2 0 0 1 10 9.4h4a2 2 0 0 1 1.9 1.4L17.5 16M4 16v3h2.5v-2m11 0v2H20v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
		copy: '<rect x="8" y="8" width="12" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" fill="none" stroke="currentColor" stroke-width="1.8"/>'
	};
	function svg(k) { return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + ICON[k] + '</svg>'; }

	function copy(text, done) {
		function fallback() {
			var t = document.createElement('textarea');
			t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
			document.body.appendChild(t); t.select();
			try { document.execCommand('copy'); } catch (e) {}
			document.body.removeChild(t); done();
		}
		if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, fallback); } else { fallback(); }
	}

	/* ---------- Open now (school time zone) ---------- */
	var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
	function nyNow() {
		try {
			var p = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', weekday: 'long', hour: 'numeric', minute: 'numeric', hour12: false }).formatToParts(new Date());
			var o = {}; p.forEach(function (x) { o[x.type] = x.value; });
			return { day: o.weekday, min: (parseInt(o.hour, 10) % 24) * 60 + parseInt(o.minute, 10) };
		} catch (e) { return null; }
	}
	function toMin(t) { var a = t.split(':'); return parseInt(a[0], 10) * 60 + parseInt(a[1], 10); }
	function label(t) { var a = t.split(':'), h = parseInt(a[0], 10), m = parseInt(a[1], 10); return (h % 12 || 12) + (m ? ':' + (m < 10 ? '0' : '') + m : '') + (h < 12 ? ' AM' : ' PM'); }
	function openNow(el) {
		var hours; try { hours = JSON.parse(el.getAttribute('data-hours')); } catch (e) { return; }
		var now = nyNow(); if (!now || !hours || !hours.length) { return; }
		var by = {}; hours.forEach(function (h) { by[h.day] = h; });
		var today = by[now.day], txt = '', open = false;
		if (today && now.min >= toMin(today.open) && now.min < toMin(today.close)) {
			open = true; txt = 'Open now · until ' + label(today.close);
		} else if (today && now.min < toMin(today.open)) {
			txt = 'Closed · opens ' + label(today.open);
		} else {
			var i = DAYS.indexOf(now.day);
			for (var k = 1; k <= 7; k++) {
				var d = DAYS[(i + k) % 7];
				if (by[d]) { txt = 'Closed · opens ' + (k === 1 ? 'tomorrow' : d) + ' ' + label(by[d].open); break; }
			}
		}
		if (!txt) { return; }
		el.querySelector('b').textContent = txt;
		el.classList.toggle('is-open', open);
		el.hidden = false;
		var box = el.closest('.sdmap');
		var row = box && box.querySelector('.sdmap-hours [data-days~="' + now.day + '"]');
		if (row) { row.classList.add('is-today'); }
	}

	function block(s) {
		var go = s.querySelector('[data-sdmap-apps]');
		var pref = go && go.querySelector('[data-app="' + first + '"]');
		if (pref) { go.insertBefore(pref, go.firstChild); pref.classList.add('is-first'); }
		var o = s.querySelector('.sdmap-open'); if (o) { openNow(o); }
		var c = s.querySelector('.sdmap-copy');
		if (c) {
			c.addEventListener('click', function () {
				var sp = c.querySelector('span');
				copy(c.getAttribute('data-copy'), function () {
					c.classList.add('is-done'); sp.textContent = 'Address copied';
					setTimeout(function () { c.classList.remove('is-done'); sp.textContent = 'Copy address'; }, 2200);
				});
			});
		}
	}

	/* ---------- Chooser ---------- */
	var sheet = null, lastFocus = null;
	function build() {
		var apps = [['google', 'Google Maps'], ['apple', 'Apple Maps'], ['waze', 'Waze']];
		apps.sort(function (a, b) { return (b[0] === first) - (a[0] === first); });
		var h = '<div class="sdnav-bg" data-close></div><div class="sdnav-box" role="dialog" aria-modal="true" aria-labelledby="sdnav-h">'
			+ '<button type="button" class="sdnav-x" data-close aria-label="Close">×</button>'
			+ '<h2 id="sdnav-h">Get directions</h2><p></p><div class="sdnav-list">';
		apps.forEach(function (a, i) {
			if (!D[a[0]]) { return; }
			h += '<a href="' + D[a[0]] + '" target="_blank" rel="noopener"' + (i === 0 ? ' class="is-first"' : '') + '>' + svg(a[0]) + '<span>' + a[1] + '</span></a>';
		});
		h += '<button type="button" data-copy>' + svg('copy') + '<span>Copy address</span></button></div></div>';
		sheet = document.createElement('div');
		sheet.className = 'sdnav'; sheet.hidden = true; sheet.innerHTML = h;
		sheet.querySelector('p').textContent = (D.name ? D.name + ' — ' : '') + (D.address || '');
		document.body.appendChild(sheet);
		sheet.addEventListener('click', function (e) {
			if (e.target.closest('[data-close]')) { close(); return; }
			var cb = e.target.closest('[data-copy]');
			if (cb) {
				copy(D.address || '', function () { cb.querySelector('span').textContent = 'Address copied'; setTimeout(close, 900); });
				return;
			}
			if (e.target.closest('a')) { setTimeout(close, 300); }
		});
		sheet.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { close(); }
			if (e.key === 'Tab') {
				var f = sheet.querySelectorAll('a,button'), a = f[0], z = f[f.length - 1];
				if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
				else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
			}
		});
	}
	function open() {
		if (!sheet) { build(); }
		var cb = sheet.querySelector('[data-copy] span'); if (cb) { cb.textContent = 'Copy address'; }
		lastFocus = document.activeElement;
		sheet.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		setTimeout(function () { var f = sheet.querySelector('.sdnav-list a'); if (f) { f.focus(); } }, 30);
	}
	function close() {
		if (!sheet || sheet.hidden) { return; }
		sheet.hidden = true;
		document.documentElement.style.overflow = '';
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	function boot() {
		[].forEach.call(document.querySelectorAll('.sdmap'), block);
		if (!D.google) { return; }
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('a[data-sdsc-nav]');
			if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0) { return; }
			e.preventDefault(); open();
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
