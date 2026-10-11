/* Sam's Class Manager - month calendar for [sdsc_holidays] (data: window.SDSC_HOLIDAYS). */
(function () {
	'use strict';
	var H = window.SDSC_HOLIDAYS || {};
	var M = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
	function build(root) {
		var now = new Date(), y = now.getFullYear(), m = now.getMonth();
		var todayKey = key(now.getFullYear(), now.getMonth(), now.getDate());
		function render() {
			root.innerHTML = '';
			var head = document.createElement('div'); head.className = 'sdhc-cal-h';
			var prev = document.createElement('button'); prev.type = 'button'; prev.setAttribute('aria-label', 'Previous month'); prev.textContent = '\u2039';
			var next = document.createElement('button'); next.type = 'button'; next.setAttribute('aria-label', 'Next month'); next.textContent = '\u203a';
			var title = document.createElement('b'); title.textContent = M[m] + ' ' + y; title.setAttribute('aria-live', 'polite');
			prev.onclick = function () { m--; if (m < 0) { m = 11; y--; } render(); root.querySelector('[aria-label="Previous month"]').focus(); };
			next.onclick = function () { m++; if (m > 11) { m = 0; y++; } render(); root.querySelector('[aria-label="Next month"]').focus(); };
			head.appendChild(prev); head.appendChild(title); head.appendChild(next); root.appendChild(head);
			var t = document.createElement('table'); t.setAttribute('role', 'grid');
			var thead = '<thead><tr>' + ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map(function (d) { return '<th scope="col">' + d + '</th>'; }).join('') + '</tr></thead>';
			var first = new Date(y, m, 1).getDay(), days = new Date(y, m + 1, 0).getDate(), html = '<tbody><tr>', i;
			for (i = 0; i < first; i++) { html += '<td></td>'; }
			for (var d = 1; d <= days; d++) {
				var k = key(y, m, d), h = H[k], cls = [];
				if (k === todayKey) { cls.push('is-today'); }
				if (h) { cls.push('is-hol'); if (h.t === 'state') { cls.push('is-state'); } if (h.c) { cls.push('is-closed'); } }
				var lab = h ? h.n + (h.t === 'state' ? ' \u2014 MVA closed' : '') + (h.c ? ' \u2014 our office closed' : '') : '';
				html += '<td class="' + cls.join(' ') + '"' + (h ? ' data-k="' + k + '"' : '') + '><span' + (h ? ' tabindex="0" title="' + lab.replace(/"/g, '&quot;') + '" aria-label="' + d + ' ' + M[m] + ': ' + lab.replace(/"/g, '&quot;') + '"' : '') + '>' + d + '</span></td>';
				if ((first + d) % 7 === 0 && d < days) { html += '</tr><tr>'; }
			}
			html += '</tr></tbody>';
			t.innerHTML = thead + html; root.appendChild(t);
			var note = document.createElement('p'); note.className = 'sdhc-note'; note.setAttribute('aria-live', 'polite'); root.appendChild(note);
			var lg = document.createElement('div'); lg.className = 'sdhc-legend';
			lg.innerHTML = '<span><i style="background:#ffe1e1"></i>Holiday \u00b7 MVA closed</span><span><i style="background:#fff4cc"></i>Observance</span><span><i style="background:#c8102e"></i>Our office closed</span>';
			root.appendChild(lg);
			[].forEach.call(t.querySelectorAll('td[data-k] span'), function (s) {
				function show() { var h = H[s.parentNode.getAttribute('data-k')]; note.textContent = h.n + ' \u00b7 ' + h.e + (h.t === 'state' ? ' \u2014 MVA closed' : '') + (h.c ? ' \u2014 our office closed' : ''); }
				s.addEventListener('mouseenter', show); s.addEventListener('focus', show); s.addEventListener('click', show);
			});
		}
		render();
	}
	function boot() { [].forEach.call(document.querySelectorAll('[data-sdhc]'), build); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
