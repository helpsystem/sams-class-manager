/* Sam's Class Manager - admin UI: grouped menu headings + guided tours.
 * The tour engine is a plain-JavaScript port of the "product tour" component:
 * dimmed page with a spotlight on the target, a card with title/text, progress dots,
 * Back / Next / Done, Esc to leave, arrow keys and Enter to move, focus kept inside the card,
 * and no motion for people who ask their system for reduced motion. */
(function () {
	'use strict';

	/* ---------------- menu group headings ---------------- */
	function menuHeadings() {
		var M = window.SDSC_MENU;
		var box = document.querySelector('#toplevel_page_sdsc-dashboard .wp-submenu');
		if (!M || !box || box.querySelector('.sdsc-menu-h')) { return; }
		var last = '';
		Array.prototype.forEach.call(box.querySelectorAll('li'), function (li) {
			var a = li.querySelector('a');
			if (!a) { return; }
			var m = (a.getAttribute('href') || '').match(/page=([a-z0-9_-]+)/i);
			var g = m && M.map[m[1]];
			if (!g || g === last) { return; }
			last = g;
			var h = document.createElement('li');
			h.className = 'sdsc-menu-h';
			h.setAttribute('data-group', g);
			h.setAttribute('aria-hidden', 'true');
			h.textContent = M.groups[g] || g;
			li.parentNode.insertBefore(h, li);
		});
	}

	/* ---------------- tour engine ---------------- */
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var T = null;

	function el(tag, cls, html) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (html != null) { e.innerHTML = html; }
		return e;
	}

	function findTarget(sel) {
		if (!sel) { return null; }
		var list;
		try { list = document.querySelectorAll(sel); } catch (e) { return null; }
		for (var i = 0; i < list.length; i++) {
			var r = list[i].getBoundingClientRect();
			if (r.width > 0 && r.height > 0) { return list[i]; }
		}
		return null;
	}

	function Tour(steps, opts) {
		this.steps = steps;
		this.opts = opts || {};
		this.i = 0;
		this.build();
	}

	Tour.prototype.build = function () {
		var self = this;
		this.root = el('div', 'sdt' + (reduce ? ' sdt--still' : ''));
		this.root.setAttribute('role', 'dialog');
		this.root.setAttribute('aria-modal', 'true');
		this.shade = el('div', 'sdt-shade');
		this.spot = el('div', 'sdt-spot');
		this.card = el('div', 'sdt-card');
		this.card.innerHTML =
			'<div class="sdt-head"><h3 class="sdt-title" id="sdt-title"></h3>' +
			'<button type="button" class="sdt-x" aria-label="Close tour"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>' +
			'<div class="sdt-body"></div>' +
			'<div class="sdt-foot"><div class="sdt-dots" aria-hidden="true"></div><span class="sdt-count"></span>' +
			'<div class="sdt-btns"><button type="button" class="sdt-back"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>Back</button>' +
			'<button type="button" class="sdt-next" data-primary></button></div></div>';
		this.root.setAttribute('aria-labelledby', 'sdt-title');
		this.root.appendChild(this.shade);
		this.root.appendChild(this.spot);
		this.root.appendChild(this.card);
		this.card.querySelector('.sdt-x').addEventListener('click', function () { self.close(false); });
		this.card.querySelector('.sdt-back').addEventListener('click', function () { self.go(self.i - 1); });
		this.card.querySelector('.sdt-next').addEventListener('click', function () { self.next(); });
		this.shade.addEventListener('click', function () { self.card.classList.add('sdt-nudge'); setTimeout(function () { self.card.classList.remove('sdt-nudge'); }, 300); });
		this.onKey = function (e) { self.key(e); };
		this.onMove = function () { self.place(); };
	};

	Tour.prototype.open = function () {
		this.prevFocus = document.activeElement;
		document.body.appendChild(this.root);
		document.addEventListener('keydown', this.onKey, true);
		window.addEventListener('resize', this.onMove);
		window.addEventListener('scroll', this.onMove, true);
		var self = this;
		requestAnimationFrame(function () { self.root.classList.add('is-on'); self.go(0); });
	};

	Tour.prototype.close = function (finished) {
		document.removeEventListener('keydown', this.onKey, true);
		window.removeEventListener('resize', this.onMove);
		window.removeEventListener('scroll', this.onMove, true);
		var r = this.root;
		r.classList.remove('is-on');
		setTimeout(function () { if (r.parentNode) { r.parentNode.removeChild(r); } }, reduce ? 0 : 200);
		if (this.prevFocus && this.prevFocus.focus) { try { this.prevFocus.focus(); } catch (e) {} }
		if (this.opts.onClose) { this.opts.onClose(!!finished); }
		T = null;
	};

	Tour.prototype.next = function () {
		if (this.i >= this.steps.length - 1) { this.close(true); } else { this.go(this.i + 1); }
	};

	Tour.prototype.go = function (i) {
		if (i < 0 || i >= this.steps.length) { return; }
		this.i = i;
		var st = this.steps[i], n = this.steps.length, self = this;
		this.card.querySelector('.sdt-title').textContent = st.title;
		this.card.querySelector('.sdt-body').innerHTML = st.content;
		this.card.querySelector('.sdt-back').hidden = i === 0;
		var nx = this.card.querySelector('.sdt-next');
		nx.innerHTML = i === n - 1 ? 'Done' : 'Next<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
		var dots = this.card.querySelector('.sdt-dots');
		dots.innerHTML = '';
		for (var d = 0; d < n; d++) { dots.appendChild(el('span', d === i ? 'is-on' : '')); }
		this.card.querySelector('.sdt-count').textContent = (i + 1) + ' / ' + n;
		dots.hidden = n > 12;
		this.card.querySelector('.sdt-count').hidden = n <= 12;

		this.target = findTarget(st.target);
		if (this.target) {
			this.target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center', inline: 'nearest' });
		}
		this.place();
		setTimeout(function () { self.place(); }, reduce ? 0 : 340);
		setTimeout(function () { nx.focus(); }, 40);
	};

	Tour.prototype.place = function () {
		var st = this.steps[this.i];
		if (!st) { return; }
		var vw = window.innerWidth, vh = window.innerHeight, gap = 14, pad = 8;
		var c = this.card.getBoundingClientRect();
		var cw = c.width || 340, ch = c.height || 180;
		var r = this.target && this.target.isConnected ? this.target.getBoundingClientRect() : null;
		var place = st.placement || 'auto';
		if (!r || place === 'center') {
			this.root.classList.add('is-center');
			this.spot.style.cssText = 'opacity:0;top:' + (vh / 2) + 'px;left:' + (vw / 2) + 'px;width:0;height:0';
			this.card.style.left = Math.round(vw / 2 - cw / 2) + 'px';
			this.card.style.top = Math.round(vh / 2 - ch / 2) + 'px';
			return;
		}
		this.root.classList.remove('is-center');
		this.spot.style.cssText = 'opacity:1;top:' + (r.top - pad) + 'px;left:' + (r.left - pad) + 'px;width:' + (r.width + pad * 2) + 'px;height:' + (r.height + pad * 2) + 'px';
		if (place === 'auto') {
			if (r.bottom + gap + pad + ch < vh) { place = 'bottom'; }
			else if (r.top - gap - pad - ch > 0) { place = 'top'; }
			else if (r.right + gap + pad + cw < vw) { place = 'right'; }
			else { place = 'left'; }
		}
		var cx = r.left + r.width / 2, cy = r.top + r.height / 2, left, top;
		if (place === 'bottom') { left = cx - cw / 2; top = r.bottom + gap + pad; }
		else if (place === 'top') { left = cx - cw / 2; top = r.top - gap - pad - ch; }
		else if (place === 'right') { left = r.right + gap + pad; top = cy - ch / 2; }
		else { left = r.left - gap - pad - cw; top = cy - ch / 2; }
		left = Math.min(Math.max(12, left), vw - 12 - cw);
		top = Math.min(Math.max(12, top), vh - 12 - ch);
		this.card.style.left = Math.round(left) + 'px';
		this.card.style.top = Math.round(top) + 'px';
	};

	Tour.prototype.key = function (e) {
		if (e.key === 'Escape') { e.preventDefault(); this.close(false); }
		else if (e.key === 'ArrowRight' || (e.key === 'Enter' && e.target === document.body)) { e.preventDefault(); this.next(); }
		else if (e.key === 'ArrowLeft') { e.preventDefault(); this.go(this.i - 1); }
		else if (e.key === 'Tab') {
			var f = Array.prototype.filter.call(this.card.querySelectorAll('button'), function (b) { return !b.hidden; });
			if (!f.length) { return; }
			var first = f[0], last = f[f.length - 1];
			if (!this.card.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
			else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		}
	};

	/* ---------------- wiring ---------------- */
	function markSeen(key) {
		var D = window.SDSC_TOUR;
		if (!D || !key) { return; }
		D.seen = 1;
		try { localStorage.setItem('sdt_seen_' + key, '1'); } catch (e) {}
		var b = document.querySelector('.sdt-launch');
		if (b) { b.classList.remove('is-new'); }
		try {
			var fd = new FormData();
			fd.append('action', 'sdsc_tour_seen');
			fd.append('_ajax_nonce', D.nonce);
			fd.append('key', key);
			fetch(D.ajax, { method: 'POST', credentials: 'same-origin', body: fd });
		} catch (e) {}
	}

	function start() {
		var D = window.SDSC_TOUR;
		if (!D || !D.steps || !D.steps.length || T) { return; }
		T = new Tour(D.steps, { onClose: function () { markSeen(D.key); } });
		T.open();
	}

	function launcher() {
		var D = window.SDSC_TOUR;
		if (!D || !D.key) { return; }
		try { if (localStorage.getItem('sdt_seen_' + D.key)) { D.seen = 1; } } catch (e) {}
		var b = el('button', 'sdt-launch' + (Number(D.seen) === 1 ? '' : ' is-new'),
			'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.4M12 16.8v.2"/></svg><span>Take the tour</span>');
		b.type = 'button';
		b.setAttribute('aria-label', 'Take the guided tour of ' + D.title);
		b.addEventListener('click', start);
		document.body.appendChild(b);
	}

	function init() {
		menuHeadings();
		launcher();
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('.sdsc-tour-start a, a[href="#sdsc-tour"]');
			if (a) { e.preventDefault(); start(); }
		});
		var D = window.SDSC_TOUR;
		if (D && Number(D.auto) === 1) {
			if (window.history && history.replaceState) {
				history.replaceState(null, '', location.href.replace(/([?&])sdsc_tour=[^&]*(&|$)/, function (m, a, b) { return b ? a : ''; }));
			}
			setTimeout(start, 450);
		}
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
