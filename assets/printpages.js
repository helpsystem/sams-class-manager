/* Sam's Driving School — student file printouts laid out as exact US Letter pages.
 *
 * Each form (<section class="pg" data-part="…" data-st="…">) is cut into real pages here, so the number of
 * pages is known before printing. With "2-sided" on (the default), every form starts on the front of a
 * sheet: when a form ends on a front side, a blank back is added. The copy of the ID / permit goes on the
 * back of the Driver Education Test Answer Sheet. Every page carries the student's file number, the form
 * name and "page x of y" of that form.
 */
(function () {
	'use strict';
	var root = document.querySelector('.sheet');
	if (!root) { return; }
	var KEY = 'sdsc_duplex';
	var duplex = true;
	try { duplex = localStorage.getItem(KEY) !== '0'; } catch (e) {}
	var src = Array.prototype.slice.call(root.querySelectorAll('section.pg'));
	if (!src.length) { return; }
	var tpl = src.map(function (s) { return s.cloneNode(true); });
	var holder = document.createElement('div');
	holder.className = 'pps';
	root.parentNode.insertBefore(holder, root);
	root.style.display = 'none';

	function el(tag, cls, html) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (html != null) { e.innerHTML = html; } return e; }

	/* ------------------------------------------------------------ cutting one form into pages */
	function cut(section) {
		var head = section.querySelector(':scope > table.wrap > thead td');
		var body = section.querySelector(':scope > table.wrap > tbody > tr > td');
		var blocks = body ? Array.prototype.slice.call(body.children) : [];
		var pages = [], cur = null;
		function page() {
			var prev = cur, p = el('div', 'pp');
			if (head) { p.appendChild(el('div', 'pp-h', head.innerHTML)); }
			var b = el('div', 'pp-b');
			p.appendChild(b);
			p.appendChild(el('div', 'pp-f'));
			holder.appendChild(p);
			pages.push(p);
			cur = b;
			// A heading never stays alone at the bottom of a page: it moves with what follows it.
			if (prev) { var l = prev.lastElementChild; if (l && l.tagName === 'H2' && prev.children.length > 1) { b.appendChild(l); } }
			return b;
		}
		function fits() { return cur.scrollHeight <= cur.clientHeight + 1; }
		function empty() { return !cur.children.length; }
		/* Containers that may be split between pages, and how to make an empty copy of them. */
		function parts(x) {
			if (x.tagName === 'TABLE') {
				var rows = Array.prototype.slice.call(x.tBodies.length ? x.tBodies[0].rows : x.rows).filter(function (r) { return r.parentNode.tagName !== 'THEAD'; });
				return rows.length > 1 ? { kids: rows, shell: function () { var t = x.cloneNode(false); if (x.tHead) { t.appendChild(x.tHead.cloneNode(true)); } t.appendChild(document.createElement('tbody')); return { box: t, into: t.tBodies[0] }; } } : null;
			}
			if (/\b(policy|units|ids)\b/.test(x.className) && x.children.length > 1) {
				return { kids: Array.prototype.slice.call(x.children), shell: function () { var d = x.cloneNode(false); return { box: d, into: d }; } };
			}
			return null;
		}
		function put(x) {
			cur.appendChild(x);
			if (fits()) { return; }
			cur.removeChild(x);
			var sp = parts(x);
			if (!sp) {
				if (!empty()) { page(); }
				cur.appendChild(x); // too tall for one page on its own: it stays (rare)
				return;
			}
			var sh = sp.shell(); cur.appendChild(sh.box);
			var moved = 0;
			for (var i = 0; i < sp.kids.length; i++) {
				var k = sp.kids[i];
				sh.into.appendChild(k);
				if (fits()) { moved++; continue; }
				sh.into.removeChild(k);
				if (!moved) { cur.removeChild(sh.box); if (!empty()) { page(); } sh = sp.shell(); cur.appendChild(sh.box); sh.into.appendChild(k); moved = 1; continue; }
				var pin = sh.into, hd = pin.lastElementChild;
				page(); sh = sp.shell(); cur.appendChild(sh.box);
				if (hd && /^H[1-6]$/.test(hd.tagName) && pin.children.length > 1) { sh.into.appendChild(hd); } // keep a sub-heading with its text
				sh.into.appendChild(k); moved = 1;
			}
		}
		page();
		blocks.forEach(put);
		return pages;
	}

	/* ------------------------------------------------------------ building the printout */
	var ORDER = ['form', 'sheet', 'idcopy', 'attendance'];
	function build() {
		holder.innerHTML = '';
		var total = 0, info = [];
		tpl.forEach(function (t, idx) {
			var s = t.cloneNode(true), part = s.getAttribute('data-part') || '', prevPart = idx ? tpl[idx - 1].getAttribute('data-part') : '';
			var sameStudent = idx && tpl[idx - 1].getAttribute('data-st') === s.getAttribute('data-st');
			// 2-sided: each form starts on the front of a sheet — except the ID copy, which goes on the back of the answer sheet.
			var back = part === 'idcopy' && prevPart === 'sheet' && sameStudent;
			if (duplex && total % 2 === 1 && !back) { holder.appendChild(el('div', 'pp pp-blank', '<p>This page is intentionally left blank.</p>')); total++; }
			var pages = cut(s);
			var name = s.getAttribute('data-name') || '', file = s.getAttribute('data-file') || '';
			pages.forEach(function (p, i) { p.querySelector('.pp-f').textContent = (file ? 'File no. ' + file + ' · ' : '') + name + (pages.length > 1 ? ' · page ' + (i + 1) + ' of ' + pages.length : ''); });
			total += pages.length;
			info.push(name + ': ' + pages.length);
		});
		if (duplex && total % 2 === 1 && tpl.length > 1) { holder.appendChild(el('div', 'pp pp-blank', '<p>This page is intentionally left blank.</p>')); total++; }
		var n = document.getElementById('ppn');
		if (n) { n.textContent = total + ' page' + (total === 1 ? '' : 's') + (duplex ? ' · ' + Math.ceil(total / 2) + ' sheet' + (Math.ceil(total / 2) === 1 ? '' : 's') + ' 2-sided' : ''); }
	}

	var sw = document.getElementById('ppdup');
	if (sw) {
		sw.checked = duplex;
		sw.addEventListener('change', function () { duplex = sw.checked; try { localStorage.setItem(KEY, duplex ? '1' : '0'); } catch (e) {} build(); });
	}
	function go() { build(); if (location.hash === '#print') { setTimeout(function () { window.print(); }, 300); } }
	// Images (signatures, ID copies, logo) must be loaded before the pages are measured.
	var imgs = Array.prototype.slice.call(root.querySelectorAll('img'));
	Promise.all(imgs.map(function (i) { return i.complete ? 0 : new Promise(function (ok) { i.onload = i.onerror = ok; }); })).then(function () {
		return document.fonts && document.fonts.ready ? document.fonts.ready : 0;
	}).then(go);
})();
