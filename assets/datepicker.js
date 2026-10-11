/* Sam's Class Manager — accessible date picker that enhances <input type="date" class="sdsc-datepicker">.
   The original input keeps its name and receives YYYY-MM-DD; people see and type MM/DD/YYYY. */
(function () {
	'use strict';
	var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	var DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function us(d) { return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + '/' + d.getFullYear(); }
	function parseIso(v) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; }
	function parseUs(v) {
		var m = /^\s*(\d{1,2})[\/\-. ](\d{1,2})[\/\-. ](\d{4})\s*$/.exec(v || '');
		if (!m) { return null; }
		var d = new Date(+m[3], +m[1] - 1, +m[2]);
		return d.getMonth() === +m[1] - 1 ? d : null;
	}
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
	var uid = 0;

	function enhance(src) {
		if (src.dataset.dpReady) { return; }
		src.dataset.dpReady = '1';
		var max = parseIso(src.getAttribute('max')) || new Date(2100, 0, 1);
		var min = parseIso(src.getAttribute('min')) || new Date(1900, 0, 1);
		var id = 'sdsc-dp-' + (++uid);

		var text = el('input', 'sdsc-dp-input');
		text.type = 'text'; text.inputMode = 'numeric'; text.autocomplete = 'bday'; text.placeholder = ' ';
		text.setAttribute('aria-describedby', id + '-hint');
		if (src.required) { text.required = true; }
		var btn = el('button', 'sdsc-dp-btn'); btn.type = 'button'; btn.setAttribute('aria-label', 'Open calendar'); btn.setAttribute('aria-haspopup', 'dialog'); btn.setAttribute('aria-expanded', 'false');
		btn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
		var hint = el('small', 'sdsc-dp-hint', 'MM/DD/YYYY'); hint.id = id + '-hint';

		src.type = 'hidden';
		src.parentNode.insertBefore(text, src);
		var field = src.closest('.sdsc-field');
		(field || src.parentNode).appendChild(btn);
		(field || src.parentNode).appendChild(hint);
		if (field) { field.classList.add('sdsc-has-dp'); }

		var pop = el('div', 'sdsc-dp-pop'); pop.id = id; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Choose a date'); pop.hidden = true;
		(field || src.parentNode).appendChild(pop);

		var view = 'day', cursor = parseIso(src.value) || new Date(Math.min(max.getTime(), new Date(new Date().getFullYear() - 16, 0, 1).getTime()));

		function setValue(d) {
			if (!d) { src.value = ''; text.value = ''; }
			else { src.value = iso(d); text.value = us(d); cursor = d; }
			src.dispatchEvent(new Event('change', { bubbles: true }));
			text.dispatchEvent(new Event('input', { bubbles: true }));
		}
		function inRange(d) { return d >= min && d <= max; }

		function render() {
			pop.innerHTML = '';
			var head = el('div', 'sdsc-dp-head');
			var ys = el('select', 'sdsc-dp-year'); ys.setAttribute('aria-label', 'Year');
			for (var y = max.getFullYear(); y >= min.getFullYear(); y--) { var o = el('option', null, String(y)); o.value = y; if (y === cursor.getFullYear()) { o.selected = true; } ys.appendChild(o); }
			var ms = el('select', 'sdsc-dp-month'); ms.setAttribute('aria-label', 'Month');
			MONTHS.forEach(function (m, i) { var o = el('option', null, m); o.value = i; if (i === cursor.getMonth()) { o.selected = true; } ms.appendChild(o); });
			ys.onchange = function () { cursor = new Date(+ys.value, cursor.getMonth(), 1); render(); };
			ms.onchange = function () { cursor = new Date(cursor.getFullYear(), +ms.value, 1); render(); };
			head.appendChild(ms); head.appendChild(ys);
			pop.appendChild(head);

			var nav = el('div', 'sdsc-dp-nav');
			var prev = el('button', 'sdsc-dp-step', '‹'); prev.type = 'button'; prev.setAttribute('aria-label', view === 'day' ? 'Previous month' : 'Previous years');
			var next = el('button', 'sdsc-dp-step', '›'); next.type = 'button'; next.setAttribute('aria-label', view === 'day' ? 'Next month' : 'Next years');
			var title = el('button', 'sdsc-dp-title'); title.type = 'button';
			title.textContent = view === 'day' ? MONTHS[cursor.getMonth()] + ' ' + cursor.getFullYear() : view === 'month' ? String(cursor.getFullYear()) : (cursor.getFullYear() - 7) + '–' + (cursor.getFullYear() + 8);
			title.setAttribute('aria-label', view === 'day' ? 'Pick a month' : view === 'month' ? 'Pick a year' : 'Back to days');
			title.onclick = function () { view = view === 'day' ? 'month' : view === 'month' ? 'year' : 'day'; render(); };
			prev.onclick = function () { shift(-1); };
			next.onclick = function () { shift(1); };
			nav.appendChild(prev); nav.appendChild(title); nav.appendChild(next);
			pop.appendChild(nav);

			var grid = el('div', 'sdsc-dp-grid sdsc-dp-grid--' + view); grid.setAttribute('role', 'grid');
			if (view === 'day') {
				DOW.forEach(function (d) { grid.appendChild(el('span', 'sdsc-dp-dow', d)); });
				var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
				for (var b = 0; b < first.getDay(); b++) { grid.appendChild(el('span', 'sdsc-dp-blank')); }
				var days = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
				var sel = parseIso(src.value);
				for (var d = 1; d <= days; d++) {
					var dt = new Date(cursor.getFullYear(), cursor.getMonth(), d);
					var c = el('button', 'sdsc-dp-day', String(d)); c.type = 'button';
					c.dataset.d = iso(dt);
					c.setAttribute('aria-label', MONTHS[dt.getMonth()] + ' ' + d + ', ' + dt.getFullYear());
					c.tabIndex = d === cursor.getDate() ? 0 : -1;
					if (sel && iso(sel) === iso(dt)) { c.classList.add('is-selected'); c.setAttribute('aria-pressed', 'true'); }
					if (iso(dt) === iso(new Date())) { c.classList.add('is-today'); }
					var hol = window.SDSC_HOLIDAYS && window.SDSC_HOLIDAYS[iso(dt)];
					if (hol) { c.classList.add('is-hol'); c.title = hol.n + (hol.t === 'state' ? ' (MVA closed)' : ''); c.setAttribute('aria-label', c.getAttribute('aria-label') + ', ' + c.title); }
					if (!inRange(dt)) { c.disabled = true; }
					c.onclick = (function (x) { return function () { setValue(x); close(true); }; })(dt);
					grid.appendChild(c);
				}
			} else if (view === 'month') {
				MONTHS.forEach(function (m, i) {
					var c = el('button', 'sdsc-dp-cell', m.slice(0, 3)); c.type = 'button';
					if (i === cursor.getMonth()) { c.classList.add('is-selected'); }
					c.onclick = function () { cursor = new Date(cursor.getFullYear(), i, 1); view = 'day'; render(); };
					grid.appendChild(c);
				});
			} else {
				for (var yy = cursor.getFullYear() - 7; yy <= cursor.getFullYear() + 8; yy++) {
					var yc = el('button', 'sdsc-dp-cell', String(yy)); yc.type = 'button';
					if (yy === cursor.getFullYear()) { yc.classList.add('is-selected'); }
					if (yy < min.getFullYear() || yy > max.getFullYear()) { yc.disabled = true; }
					yc.onclick = (function (v) { return function () { cursor = new Date(v, cursor.getMonth(), 1); view = 'month'; render(); }; })(yy);
					grid.appendChild(yc);
				}
			}
			pop.appendChild(grid);

			var foot = el('div', 'sdsc-dp-foot');
			var clr = el('button', 'sdsc-dp-clear', 'Clear'); clr.type = 'button'; clr.onclick = function () { setValue(null); close(true); };
			var done = el('button', 'sdsc-dp-done', 'Done'); done.type = 'button'; done.onclick = function () { close(true); };
			foot.appendChild(clr); foot.appendChild(done);
			pop.appendChild(foot);
		}
		function shift(n) {
			if (view === 'day') { cursor = new Date(cursor.getFullYear(), cursor.getMonth() + n, 1); }
			else if (view === 'month') { cursor = new Date(cursor.getFullYear() + n, cursor.getMonth(), 1); }
			else { cursor = new Date(cursor.getFullYear() + 16 * n, cursor.getMonth(), 1); }
			render();
		}
		function focusDay() { var f = pop.querySelector('.sdsc-dp-day[tabindex="0"]') || pop.querySelector('.sdsc-dp-day:not([disabled])'); if (f) { f.focus(); } }
		function open() {
			view = 'day'; render(); pop.hidden = false; btn.setAttribute('aria-expanded', 'true');
			setTimeout(focusDay, 0);
			document.addEventListener('mousedown', outside, true);
		}
		function close(refocus) {
			pop.hidden = true; btn.setAttribute('aria-expanded', 'false');
			document.removeEventListener('mousedown', outside, true);
			if (refocus) { text.focus(); }
		}
		function outside(e) { if (!pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) { close(false); } }

		pop.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
			var t = e.target;
			if (!t.classList.contains('sdsc-dp-day')) { return; }
			var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
			if (!step) { return; }
			e.preventDefault();
			var d = parseIso(t.dataset.d); d.setDate(d.getDate() + step);
			if (!inRange(d)) { return; }
			cursor = d; render(); focusDay();
		});
		btn.addEventListener('click', function () { if (pop.hidden) { open(); } else { close(true); } });
		text.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' && e.altKey) { e.preventDefault(); open(); } });
		text.addEventListener('input', function (e) {
			if (!e.isTrusted) { return; }
			var v = text.value.replace(/[^\d]/g, '').slice(0, 8);
			var out = v.length > 4 ? v.slice(0, 2) + '/' + v.slice(2, 4) + '/' + v.slice(4) : v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v;
			if (out !== text.value) { text.value = out; }
			var d = parseUs(out);
			src.value = d && inRange(d) ? iso(d) : '';
			if (d) { cursor = d; }
			src.dispatchEvent(new Event('change', { bubbles: true }));
		});
		var init = parseIso(src.value); if (init) { text.value = us(init); }
		// Validation hook used by the form: the visible box is the one people fix.
		src.sdscVisible = text;
	}

	window.SDSCDatePicker = { enhance: enhance, init: function (root) { (root || document).querySelectorAll('input.sdsc-datepicker').forEach(enhance); } };
	function boot() { window.SDSCDatePicker.init(document); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
