/* Sam's Class Manager — road signs rise like dominoes as they scroll in; the car drives the road. */
(function () {
	'use strict';
	function clamp(v) { return v < 0 ? 0 : v > 1 ? 1 : v; }
	// Fast rise, small overshoot past upright, then settle (like a sign springing up).
	function rise(t) {
		if (t < 0.75) { var u = t / 0.75; return u * u * (3 - 2 * u); }
		var w = (t - 0.75) / 0.25;
		return 1 + 0.05 * Math.sin(w * Math.PI) * (1 - w);
	}
	function init(root) {
		if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
		var slots = [].slice.call(root.querySelectorAll('.sds-sign-slot'));
		var road = root.querySelector('.sds-asphalt');
		var raf = 0;
		root.classList.add('is-live');

		function paint() {
			raf = 0;
			var vh = window.innerHeight || 800;
			var rowTop = null, col = 0;
			slots.forEach(function (slot) {
				var r = slot.getBoundingClientRect();
				col = (rowTop !== null && Math.abs(r.top - rowTop) < 4) ? col + 1 : 0;
				rowTop = r.top;
				// Starts when the slot's top passes 95% of the viewport, done ~40% later; columns lag like falling dominoes.
				var p = clamp(((vh * 0.95) - r.top) / (vh * 0.4) - col * 0.18);
				slot.style.setProperty('--t', (Math.round(rise(p) * 1000) / 1000).toString());
			});
			if (road) {
				var rr = road.getBoundingClientRect();
				var cp = clamp((vh - rr.top) / (vh * 0.9));
				road.style.setProperty('--car', cp.toFixed(3));
				var carEl = road.querySelector('.sams-car');
				if (carEl) { var dist = cp * (road.offsetWidth - carEl.offsetWidth), dia = carEl.offsetWidth * 0.1435; carEl.style.setProperty('--spin', Math.round(-dist / (Math.PI * dia) * 360) + 'deg'); }
			}
		}
		function kick() { if (!raf) { raf = requestAnimationFrame(paint); } }
		window.addEventListener('scroll', kick, { passive: true });
		window.addEventListener('resize', kick);
		paint();
	}
	function boot() { document.querySelectorAll('.sds-road').forEach(init); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
