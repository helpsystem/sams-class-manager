/* Sam's Class Manager — 3D signs: follow the pointer on hover; reflective sheen moves with scroll. */
(function () {
	'use strict';
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var signs = [];
	var raf = 0;

	function sheen() {
		raf = 0;
		var vh = window.innerHeight || 800, vw = window.innerWidth || 1200;
		signs.forEach(function (s) {
			var r = s.getBoundingClientRect();
			if (r.bottom < 0 || r.top > vh) { return; }
			// 0 as the sign enters at the bottom, 1 as it leaves at the top; nudged a little by column.
			var p = 1 - (r.top + r.height / 2) / vh + (r.left / vw - 0.5) * 0.25;
			s.style.setProperty('--sheen', Math.max(-0.2, Math.min(1.2, p)).toFixed(3));
		});
	}
	function kick() { if (!raf) { raf = requestAnimationFrame(sheen); } }

	// The whole item (sign + card) is the hover area, so the sign stays turned toward you on the button too.
	function tilt(s) {
		var area = s.closest('.sdsc3d-item') || s;
		area.addEventListener('pointermove', function (e) {
			if (e.pointerType === 'touch') { return; }
			var r = s.getBoundingClientRect();
			var x = Math.max(-1, Math.min(1, (e.clientX - r.left) / r.width - 0.5));
			var y = Math.max(-1, Math.min(1, (e.clientY - r.top) / r.height - 0.5));
			s.classList.add('is-hover');
			s.style.setProperty('--ry', (x * 30 + 12).toFixed(1) + 'deg');
			s.style.setProperty('--rx', (-y * 12).toFixed(1) + 'deg');
		});
		area.addEventListener('pointerleave', function () {
			s.classList.remove('is-hover');
			s.style.removeProperty('--ry');
			s.style.removeProperty('--rx');
		});
	}

	function boot() {
		signs = [].slice.call(document.querySelectorAll('.s3d'));
		if (!signs.length) { return; }
		if (!reduce) {
			signs.forEach(tilt);
			window.addEventListener('scroll', kick, { passive: true });
			window.addEventListener('resize', kick);
		}
		sheen();
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();

/* v0.9.6 homepage: service jump bar (shows after the hero, highlights the section on screen),
   and a short glow on the section a visitor jumps to. */
(function () {
	function boot() {
		var bar = document.querySelector('[data-sds-jump]');
		var hero = document.querySelector('.mdh');
		var head = document.querySelector('.sdh');
		var links = bar ? [].slice.call(bar.querySelectorAll('a[data-for]')) : [];
		var targets = links.map(function (a) { return document.getElementById(a.getAttribute('data-for')); });
		function place() {
			if (!bar) { return; }
			var top = head ? Math.max(0, head.getBoundingClientRect().bottom) : 0;
			bar.style.top = top + 'px';
			var show = !hero || hero.getBoundingClientRect().bottom < top + 10;
			bar.classList.toggle('is-on', show);
			var cur = -1, line = top + 90;
			targets.forEach(function (t, i) { if (t && t.getBoundingClientRect().top <= line) { cur = i; } });
			if (cur > -1 && targets[cur] && targets[cur].getBoundingClientRect().bottom < line) {
				// past the section: only stay highlighted while some of it is still on screen
				var next = targets[cur + 1];
				if (!next || next.getBoundingClientRect().top > window.innerHeight) { cur = targets[cur].getBoundingClientRect().bottom > 0 ? cur : -1; }
			}
			links.forEach(function (a, i) { a.classList.toggle('is-here', i === cur); if (i === cur) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); } });
		}
		if (bar && targets.some(Boolean)) {
			bar.hidden = false;
			var tick = false;
			window.addEventListener('scroll', function () { if (!tick) { tick = true; requestAnimationFrame(function () { tick = false; place(); }); } }, { passive: true });
			window.addEventListener('resize', place);
			place();
		} else if (bar) { bar.remove(); }

		function glow(id) {
			var el = id && document.getElementById(id);
			if (!el) { return; }
			el.classList.remove('is-arrived'); void el.offsetWidth; el.classList.add('is-arrived');
			setTimeout(function () { el.classList.remove('is-arrived'); }, 1800);
		}
		var ids = ['driver-ed', 'practice', 'online-course', 'road-test-car', 'services'];
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('a[href*="#"]');
			if (!a || a.hostname !== location.hostname || a.pathname !== location.pathname) { return; }
			var id = a.hash.slice(1);
			if (ids.indexOf(id) < 0 || !document.getElementById(id)) { return; }
			e.preventDefault();
			if (!(window.sdsStackTo && window.sdsStackTo(document.getElementById(id)))) {
				document.getElementById(id).scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
			}
			if (history.replaceState) { history.replaceState(null, '', '#' + id); }
			setTimeout(function () { glow(id); }, 450);
		});
		if (ids.indexOf(location.hash.slice(1)) > -1) {
			// Opened straight on a section: settle again once images above it have loaded and moved it.
			var go = function (g) { var el = document.getElementById(location.hash.slice(1)); if (el) { if (!(window.sdsStackTo && window.sdsStackTo(el))) { el.scrollIntoView(); } if (g) { glow(el.id); } } };
			setTimeout(function () { go(true); }, 300);
			window.addEventListener('load', function () { setTimeout(function () { if (window.scrollY > 0) { go(false); } }, 150); });
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();

/* v0.9.7 Our services stack (no libraries): pins the stack and, while you scroll, tips the front card
   back and off while the next one comes forward. Short: about a fifth of the screen per card. */
(function () {
	function boot() {
		var root = document.querySelector('[data-sds-stack]');
		if (!root) { return; }
		var cards = [].slice.call(root.querySelectorAll('.sds-stack-card'));
		var n = cards.length;
		var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
		if (n < 2 || reduce || !('CSS' in window) || !CSS.supports('position', 'sticky')) { return; }
		var seg = 160, top = 120, pin = 420;
		function measure() {
			var head = document.querySelector('.sdh'), bar = document.querySelector('[data-sds-jump]');
			top = (head ? head.getBoundingClientRect().height : 70) + (bar ? 50 : 10) + 8;
			var vh = window.innerHeight;
			if (vh < 520) { root.classList.remove('is-live'); cards.forEach(function (c) { c.style.transform = c.style.opacity = ''; }); return false; }
			var small = window.innerWidth < 700;
			pin = vh - top - 12;
			var card = Math.min(small ? 520 : 400, pin - 70);
			root.style.setProperty('--card', card + 'px');
			seg = Math.max(140, Math.round(vh * (small ? 0.24 : 0.2)));
			root.style.setProperty('--top', top + 'px');
			root.style.setProperty('--pin', pin + 'px');
			root.style.setProperty('--seg', seg + 'px');
			root.classList.add('is-live');
			return true;
		}
		function paint() {
			if (!root.classList.contains('is-live')) { return; }
			// The header gets shorter once the page scrolls (top bar hides): keep the pin right under it.
			var head = document.querySelector('.sdh');
			var nt = (head ? Math.max(0, head.getBoundingClientRect().bottom) : 70) + 58;
			if (Math.abs(nt - top) > 2) { top = nt; root.style.setProperty('--top', top + 'px'); pin = window.innerHeight - top - 12; root.style.setProperty('--pin', pin + 'px'); root.style.setProperty('--card', Math.min(window.innerWidth < 700 ? 520 : 400, pin - 70) + 'px'); }
			var p = Math.min(Math.max((top - root.getBoundingClientRect().top) / seg, 0), n - 1);
			var a = Math.min(Math.floor(p), n - 1), f = p - a;
			if (a === n - 1) { f = 0; }
			cards.forEach(function (c, i) {
				var t, o = 1;
				if (i < a) { t = 'translate3d(0,-115%,0) rotateX(32deg)'; o = 0; }
				else if (i === a) { t = 'translate3d(0,' + (-f * 150) + '%,0) rotateX(' + (f * 40) + 'deg)'; o = Math.max(0, 1 - f * 2.4); }
				else { var b = i - a - f; t = 'translate3d(0,' + (b * 14) + 'px,0) scale(' + (1 - b * 0.05) + ')'; o = b > 3 ? 0 : 1; }
				c.style.transform = t; c.style.opacity = o;
				c.setAttribute('aria-hidden', i < a ? 'true' : 'false');
			});
			root.classList.toggle('is-done', p >= n - 1.2);
		}
		var tick = false;
		function onScroll() { if (!tick) { tick = true; requestAnimationFrame(function () { tick = false; paint(); }); } }
		if (measure()) { paint(); }
		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', function () { measure(); paint(); });
		// Links to a card inside the stack (#online-course, #road-test-car) scroll to where that card is in front.
		window.sdsStackTo = function (el) {
			var i = cards.indexOf(el);
			if (i < 0 || !root.classList.contains('is-live')) { return false; }
			var y = window.scrollY + root.getBoundingClientRect().top - top + i * seg + 2;
			window.scrollTo({ top: y, behavior: reduce ? 'auto' : 'smooth' });
			return true;
		};
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
