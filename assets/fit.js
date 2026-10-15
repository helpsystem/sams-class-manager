/* Sam's Class Manager — staff screens on phones: nothing runs off the right edge. Grids with fixed-width
   columns become one column, rows that do not fit wrap, and wide tables scroll sideways inside their own
   box instead of pushing the whole page wider than the screen. Only acts on narrow screens. */
(function () {
	'use strict';
	var SKIP = '.scal,.sdfb-tgrid,.wp-list-table,.CodeMirror,.mce-container,.wp-editor-wrap,.sdfit-x';
	function fit() {
		var vw = document.documentElement.clientWidth;
		if (vw > 782) { return; }
		var root = document.getElementById('wpbody-content') || document.body;
		[].forEach.call(root.querySelectorAll('*'), function (e) {
			if (e.closest(SKIP)) { return; }
			var cs = getComputedStyle(e);
			if (cs.position === 'fixed' || cs.position === 'absolute') { return; }
			var r = e.getBoundingClientRect();
			if (!r.width || (r.right <= vw + 1 && e.scrollWidth <= e.clientWidth + 2)) { return; }
			if (cs.display === 'grid' || cs.display === 'inline-grid') { e.style.gridTemplateColumns = 'minmax(0,1fr)'; }
			else if ((cs.display === 'flex' || cs.display === 'inline-flex') && cs.flexWrap === 'nowrap' && cs.flexDirection.indexOf('row') === 0 && cs.overflowX === 'visible') { e.style.flexWrap = 'wrap'; }
		});
		[].forEach.call(root.querySelectorAll('table'), function (t) {
			if (t.closest(SKIP) || t.classList.contains('wp-list-table')) { return; }
			if (t.getBoundingClientRect().right <= vw + 1) { return; }
			var w = document.createElement('div');
			w.className = 'sdfit-x';
			w.style.cssText = 'max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch';
			t.parentNode.insertBefore(w, t); w.appendChild(t);
		});
		[].forEach.call(root.querySelectorAll('input,select,textarea,img,pre,iframe'), function (i) {
			if (!i.closest(SKIP) && i.getBoundingClientRect().right > vw + 1) { i.style.maxWidth = '100%'; i.style.boxSizing = 'border-box'; }
		});
		[].forEach.call(root.querySelectorAll('form,div,section,p,ul,ol,details,fieldset'), function (e) {
			if (e.closest(SKIP)) { return; }
			var cs = getComputedStyle(e);
			if (cs.position === 'fixed' || cs.position === 'absolute') { return; }
			if (e.getBoundingClientRect().right > vw + 1 && cs.display !== 'inline') { e.style.maxWidth = '100%'; e.style.minWidth = '0'; e.style.boxSizing = 'border-box'; }
		});
	}
	var t;
	function later() { clearTimeout(t); t = setTimeout(fit, 120); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fit); } else { fit(); }
	window.addEventListener('load', function () { fit(); setTimeout(fit, 800); });
	window.addEventListener('resize', later);
})();
