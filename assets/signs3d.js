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
