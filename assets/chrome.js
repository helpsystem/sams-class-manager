/* Sam's Class Manager — header behaviour, Maryland hero parallax, light-beam footer. */
(function () {
	'use strict';
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
	function approach(from, to, k, dt) { return to + (from - to) * Math.pow(1 - clamp(k, 0, 1), clamp(dt, 0, 0.1) * 60); }

	/* ---------------- Header ---------------- */
	function header(h) {
		var burger = h.querySelector('.sdh-burger');
		var nav = h.querySelector('.sdh-nav');
		/* Shrinking the header changes the page height; with one threshold the browser's scroll correction
		   flips it back and forth (the "shaking" header). Two thresholds with a wide gap keep it stable. */
		var sc = false, tick = 0;
		var onScroll = function () {
			if (tick) { return; }
			tick = requestAnimationFrame(function () {
				tick = 0;
				var y = window.scrollY || window.pageYOffset;
				if (!sc && y > 140) { sc = true; } else if (sc && y < 8) { sc = false; } else { return; }
				h.classList.toggle('is-scrolled', sc);
			});
		};
		window.addEventListener('scroll', onScroll, { passive: true });
		onScroll();
		function setOpen(open) {
			h.classList.toggle('is-open', open);
			burger.setAttribute('aria-expanded', open ? 'true' : 'false');
			burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
			document.documentElement.style.overflow = open ? 'hidden' : '';
			if (open) { var f = nav.querySelector('a'); if (f) { setTimeout(function () { f.focus(); }, 60); } }
		}
		if (burger) {
			burger.addEventListener('click', function () { setOpen(!h.classList.contains('is-open')); });
			document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && h.classList.contains('is-open')) { setOpen(false); burger.focus(); } });
			h.querySelector('.sdh-bar').addEventListener('click', function (e) { if (e.target === e.currentTarget && h.classList.contains('is-open')) { setOpen(false); } });
			document.addEventListener('click', function (e) { if (h.classList.contains('is-open') && !nav.contains(e.target) && !burger.contains(e.target)) { setOpen(false); } });
			nav.addEventListener('click', function (e) { if (e.target.closest('a')) { setOpen(false); } });
			window.addEventListener('resize', function () { if (window.innerWidth > 1240 && h.classList.contains('is-open')) { setOpen(false); } });
		}
	}

	/* ---------------- Maryland hero ---------------- */
	function hero(s) {
		var tx = 0, ty = 0, mx = 0, my = 0, sy = 0, raf = 0, last = 0, live = true;
		function frame(now) {
			raf = 0;
			var dt = last ? (now - last) / 1000 : 0.016; last = now;
			mx = approach(mx, tx, 0.08, dt); my = approach(my, ty, 0.08, dt);
			var r = s.getBoundingClientRect();
			sy = clamp(-r.top / Math.max(1, r.height), 0, 1);
			s.style.setProperty('--mx', mx.toFixed(4));
			s.style.setProperty('--my', my.toFixed(4));
			s.style.setProperty('--sy', sy.toFixed(4));
			if (live && (Math.abs(mx - tx) > 0.001 || Math.abs(my - ty) > 0.001)) { raf = requestAnimationFrame(frame); } else { last = 0; }
		}
		function kick() { if (!raf) { raf = requestAnimationFrame(frame); } }
		if (!reduce) {
			s.addEventListener('pointermove', function (e) {
				if (e.pointerType === 'touch') { return; }
				var r = s.getBoundingClientRect();
				tx = clamp((e.clientX - r.left) / r.width * 2 - 1, -1, 1);
				ty = clamp((e.clientY - r.top) / r.height * 2 - 1, -1, 1);
				kick();
			});
			s.addEventListener('pointerleave', function () { tx = 0; ty = 0; kick(); });
			window.addEventListener('scroll', kick, { passive: true });
		}
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (es) { live = es[0].isIntersecting; s.classList.toggle('is-paused', !live); if (live) { kick(); } }).observe(s);
		}
	}

	/* ---------------- Footer: beam + wordmark ---------------- */
	function drift(t) { return 58 + 9 * (0.7 * Math.sin(t * 0.21) + 0.3 * Math.sin(t * 0.077 + 1.3)); }
	function footer(root) {
		var word = root.querySelector('.bwf-word'), inner = root.querySelector('.bwf-word-in'), box = root.querySelector('.bwf-word-box');
		var ptr = { u: 0.5, x: 0, y: 0, inside: false };
		function place() {
			var r = root.getBoundingClientRect();
			root.style.setProperty('--bwf-rw', r.width + 'px');
			root.style.setProperty('--bwf-rh', r.height + 'px');
			var top = 0;
			[].forEach.call(inner.querySelectorAll('.bwf-l'), function (s, i) {
				var x = 0, y = 0, el = s;
				while (el && el !== root) { x += el.offsetLeft; y += el.offsetTop; el = el.offsetParent; }
				s.style.setProperty('--bwf-x', x + 'px');
				s.style.setProperty('--bwf-y', y + 'px');
				if (i === 0) { top = y; }
			});
			root.style.setProperty('--bwf-top', top + 'px');
			root.style.setProperty('--bwf-bot', top + (parseFloat(inner.style.fontSize) || 0) * 0.92 + 'px');
		}
		function fit() {
			var pad = parseFloat(getComputedStyle(box).paddingLeft) || 0;
			var target = box.clientWidth - pad * 2;
			inner.style.fontSize = '100px';
			var measured = inner.offsetWidth;
			if (!(measured > 0) || !(target > 0)) { return; }
			var size = Math.min(100 * target / measured, target * 0.42);
			inner.style.fontSize = size + 'px';
			word.style.height = Math.max(0, size * (0.8 + 0.12)) + 'px';
			place();
		}
		var t0 = 0;
		function schedule() { cancelAnimationFrame(t0); t0 = requestAnimationFrame(fit); }
		fit();
		if ('ResizeObserver' in window) { new ResizeObserver(schedule).observe(root); } else { window.addEventListener('resize', schedule); }
		if (document.fonts && document.fonts.ready) { document.fonts.ready.then(schedule); }

		var visible = false, raf = 0, last = 0, t = performance.now() / 1000, b = 58, px = 0, py = 0, g = 0;
		function set() {
			root.style.setProperty('--bwf-b', b.toFixed(2) + '%');
			root.style.setProperty('--bwf-px', px.toFixed(1) + 'px');
			root.style.setProperty('--bwf-py', py.toFixed(1) + 'px');
			root.style.setProperty('--bwf-g', g.toFixed(3));
		}
		function tick(now) {
			var dt = Math.min(0.1, last ? (now - last) / 1000 : 0.016); last = now; t += dt;
			var idle = drift(t);
			var target = ptr.inside ? (30 + 52 * clamp(ptr.u, 0, 1)) * 0.75 + idle * 0.25 : idle;
			b = approach(b, target, 0.035, dt); px = approach(px, ptr.x, 0.16, dt); py = approach(py, ptr.y, 0.16, dt); g = approach(g, ptr.inside ? 1 : 0, 0.06, dt);
			set();
			raf = visible ? requestAnimationFrame(tick) : 0;
		}
		function onPtr(e) {
			var r = root.getBoundingClientRect();
			ptr.x = e.clientX - r.left; ptr.y = e.clientY - r.top; ptr.u = r.width ? ptr.x / r.width : 0.5; ptr.inside = e.type !== 'pointerleave';
			if (reduce) { px = ptr.x; py = ptr.y; g = ptr.inside ? 1 : 0; set(); }
		}
		root.addEventListener('pointermove', onPtr);
		root.addEventListener('pointerenter', onPtr);
		root.addEventListener('pointerleave', onPtr);
		[].forEach.call(root.querySelectorAll('.bwf-l'), function (l) {
			l.addEventListener('click', function () { if (reduce) { return; } l.classList.remove('is-hop'); void l.offsetWidth; l.classList.add('is-hop'); });
			l.addEventListener('animationend', function () { l.classList.remove('is-hop'); });
		});
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (es) {
				visible = es[0].isIntersecting;
				if (visible) { root.setAttribute('data-in', 'true'); place(); if (!reduce && !raf) { last = 0; raf = requestAnimationFrame(tick); } }
			}, { threshold: 0.12 }).observe(root);
		} else { root.setAttribute('data-in', 'true'); }
		if (reduce) { root.setAttribute('data-in', 'true'); }
	}

	function boot() {
		[].forEach.call(document.querySelectorAll('[data-sdh]'), header);
		[].forEach.call(document.querySelectorAll('[data-mdh]'), hero);
		[].forEach.call(document.querySelectorAll('[data-bwf]'), footer);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
