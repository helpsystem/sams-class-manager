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
		var seg = 300, top = 120, pin = 420, lastW = 0, lastH = 0, cur = 0, goal = 0, raf = 0;
		// Sizes are worked out only here (load, rotation, real resizes) and never while scrolling, so the
		// page does not have to redo its layout on every frame (that was the stutter).
		function measure() {
			var vw = window.innerWidth, vh = window.innerHeight;
			// Phones resize the window a little when the address bar shows or hides: ignore that.
			if (lastW === vw && Math.abs(vh - lastH) < 120 && root.classList.contains('is-live')) { return true; }
			lastW = vw; lastH = vh;
			var head = document.querySelector('.sdh'), bar = document.querySelector('[data-sds-jump]');
			top = (head ? Math.min(head.getBoundingClientRect().height, 90) : 70) + (bar ? 58 : 10);
			if (vh < 520) { root.classList.remove('is-live'); cards.forEach(function (c) { c.style.transform = c.style.opacity = ''; }); return false; }
			var small = vw < 700;
			pin = vh - top - 12;
			var card = Math.min(small ? 560 : 420, pin - 56);
			// Slower: a card changes every ~45% of the screen on phones, ~38% on computers.
			seg = Math.max(220, Math.round(vh * (small ? 0.45 : 0.38)));
			root.style.setProperty('--card', card + 'px');
			root.style.setProperty('--top', top + 'px');
			root.style.setProperty('--pin', pin + 'px');
			root.style.setProperty('--seg', seg + 'px');
			root.classList.toggle('is-small', small);
			root.classList.add('is-live');
			return true;
		}
		function ease(x) { return x < 0.5 ? 2 * x * x : 1 - Math.pow(-2 * x + 2, 2) / 2; }
		function draw(p) {
			var a = Math.min(Math.floor(p), n - 1), f = p - a;
			if (a === n - 1) { f = 0; }
			// Each card holds still for the first and last part of its turn, then moves: easier to read.
			var e = ease(Math.min(Math.max((f - 0.15) / 0.7, 0), 1));
			var shown = e > 0.55 && a < n - 1 ? a + 1 : a; // the next card's words appear as the front one leaves
			cards.forEach(function (c, i) {
				var t, o = 1;
				if (i < a) { t = 'translate3d(0,-120%,0) rotateX(28deg)'; o = 0; }
				else if (i === a) {
					t = 'translate3d(0,' + (-e * 125).toFixed(2) + '%,0) rotateX(' + (e * 28).toFixed(2) + 'deg)';
					o = e < 0.6 ? 1 : Math.max(0, 1 - (e - 0.6) / 0.4); // stays solid, so the next card never shows through it
				} else {
					var b = i - a - e;
					t = 'translate3d(0,' + (b * 16).toFixed(2) + 'px,0) scale(' + (1 - b * 0.045).toFixed(4) + ')';
					o = b > 2.5 ? 0 : 1;
				}
				c.style.transform = t; c.style.opacity = o;
				c.classList.toggle('is-front', i === shown);
				c.setAttribute('aria-hidden', i === shown ? 'false' : 'true');
			});
			root.classList.toggle('is-done', p >= n - 1.2);
		}
		function target() { return Math.min(Math.max((top - root.getBoundingClientRect().top) / seg, 0), n - 1); }
		// The cards follow the scroll smoothly (a short glide) instead of jumping with every finger move.
		function loop() {
			cur += (goal - cur) * 0.2;
			if (Math.abs(goal - cur) < 0.002) { cur = goal; }
			draw(cur);
			raf = cur !== goal ? requestAnimationFrame(loop) : 0;
		}
		function onScroll() {
			if (!root.classList.contains('is-live')) { return; }
			goal = target();
			if (!raf) { raf = requestAnimationFrame(loop); }
		}
		if (measure()) { cur = goal = target(); draw(cur); }
		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', function () { if (measure()) { cur = goal = target(); draw(cur); } });
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

/* v0.9.7 posts slider: moves the queue of articles (no libraries). Only the article in front is
   readable by screen readers and reachable with Tab; arrows and the ← → keys move it. */
(function () {
	function boot() {
		[].forEach.call(document.querySelectorAll('[data-sdp-slider]'), function (root) {
			var list = root.querySelector('.sdp-slides');
			if (!list || list.children.length < 2) { return; }
			root.classList.add('is-live');
			// The first item is the backdrop; start with the newest article in front (second place).
			list.insertBefore(list.lastElementChild, list.firstElementChild);
			function mark() {
				[].forEach.call(list.children, function (li, i) {
					var on = i === 1;
					li.setAttribute('aria-hidden', on ? 'false' : 'true');
					[].forEach.call(li.querySelectorAll('a,button'), function (a) { if (on) { a.removeAttribute('tabindex'); } else { a.setAttribute('tabindex', '-1'); } });
				});
			}
			function go(dir) {
				if (dir === 'next') { list.appendChild(list.firstElementChild); } else { list.insertBefore(list.lastElementChild, list.firstElementChild); }
				mark();
			}
			[].forEach.call(root.querySelectorAll('.sdp-slider-btn'), function (b) { b.addEventListener('click', function () { go(b.getAttribute('data-dir')); }); });
			root.addEventListener('keydown', function (e) { if (e.key === 'ArrowRight') { go('next'); } else if (e.key === 'ArrowLeft') { go('prev'); } });
			// Tapping a waiting card brings it to the front.
			list.addEventListener('click', function (e) {
				var li = e.target.closest('.sdp-slide');
				if (!li) { return; }
				var i = [].indexOf.call(list.children, li);
				if (i > 1) { e.preventDefault(); for (var k = 1; k < i; k++) { list.appendChild(list.firstElementChild); } mark(); }
			});
			mark();
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
