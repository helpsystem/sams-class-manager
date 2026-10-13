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
			document.getElementById(id).scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
			if (history.replaceState) { history.replaceState(null, '', '#' + id); }
			setTimeout(function () { glow(id); }, 450);
		});
		if (ids.indexOf(location.hash.slice(1)) > -1) {
			// Opened straight on a section: settle again once images above it have loaded and moved it.
			var go = function (g) { var el = document.getElementById(location.hash.slice(1)); if (el) { el.scrollIntoView(); if (g) { glow(el.id); } } };
			setTimeout(function () { go(true); }, 300);
			window.addEventListener('load', function () { setTimeout(function () { if (window.scrollY > 0) { go(false); } }, 150); });
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
