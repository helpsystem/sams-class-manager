/* Sam's Class Manager \u2014 renders live banners / pop-ups / county alerts into #sdnb. */
(function () {
	'use strict';
	var C = window.SDSC_NB || {};
	var box = document.getElementById('sdnb');
	if (!box || !C.url || !window.fetch) { return; }
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var ICON = { info: '\u2139\ufe0f', closure: '\u26d4', snow: '\u2744\ufe0f', halloween: '\ud83c\udf83', autumn: '\ud83c\udf42', fireworks: '\ud83c\udf86', sparkle: '\u2728', flag: '\ud83c\uddfa\ud83c\uddf8', hearts: '\u2764\ufe0f', shamrock: '\u2618\ufe0f', spring: '\ud83c\udf37', flowers: '\ud83d\udc90', calm: '\ud83d\udd4a\ufe0f', celebrate: '\ud83c\udf89', 'alert-severe': '\u26a0\ufe0f', 'alert-moderate': '\u26a0\ufe0f', 'alert-minor': '\ud83c\udf26\ufe0f' };
	var FX = {
		snow: ['\u2744', 'fall'], halloween: ['\ud83e\udd87', 'fall'], autumn: ['\ud83c\udf41', 'fall'], hearts: ['\u2665', 'rise'], shamrock: ['\u2618', 'fall'],
		spring: ['\ud83c\udf38', 'fall'], flowers: ['\ud83c\udf38', 'fall'], fireworks: ['\u2726', 'twinkle'], sparkle: ['\u2726', 'twinkle'], celebrate: ['\u2726', 'twinkle']
	};
	var ES_FIRST = /^es\b/i.test(navigator.language || '');
	function store(kind, k, v) {
		try {
			var s = window[kind];
			if (v === undefined) { return s.getItem(k); }
			s.setItem(k, v);
		} catch (e) { return null; }
	}
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text) { e.textContent = text; } return e; }
	function fx(theme, n) {
		var f = FX[theme]; if (!f || reduce) { return null; }
		var w = el('span', 'sdnb-fx' + (f[1] !== 'fall' ? ' sdnb-fx--' + f[1] : ''));
		w.setAttribute('aria-hidden', 'true');
		for (var i = 0; i < n; i++) {
			var p = el('i', '', f[0]);
			p.style.left = (4 + Math.random() * 92) + '%';
			p.style.animationDuration = (3.5 + Math.random() * 4) + 's';
			p.style.animationDelay = (-Math.random() * 6) + 's';
			p.style.fontSize = (10 + Math.random() * 9) + 'px';
			w.appendChild(p);
		}
		return w;
	}
	function safeLink(u) { return /^(https?:|tel:|mailto:|\/)/i.test(u || '') ? u : ''; }
	function hasEs(n) { return !!(n.title_es || n.text_es); }
	/* the text of a notice in one language */
	function pick(n, lang) {
		if (lang === 'es' && hasEs(n)) { return { t: n.title_es || n.title, x: n.text_es || n.text, b: n.linktext_es || n.linktext, l: 'es' }; }
		return { t: n.title, x: n.text, b: n.linktext, l: 'en' };
	}
	function button(href, label) {
		href = safeLink(href);
		if (!href || !label) { return null; }
		var a = el('a', 'sdnb-btn', label); a.href = href;
		if (/^https?:/i.test(href) && href.indexOf(location.host) === -1) { a.target = '_blank'; a.rel = 'noopener'; }
		return a;
	}
	/* EN / ES switch; calls fill(lang) */
	function langSwitch(start, fill) {
		var w = el('span', 'sdnb-lang'); w.setAttribute('role', 'group'); w.setAttribute('aria-label', 'Language');
		['en', 'es'].forEach(function (l) {
			var b = el('button', '', l.toUpperCase()); b.type = 'button'; b.lang = l;
			b.setAttribute('aria-label', l === 'en' ? 'English' : 'Espa\u00f1ol');
			b.setAttribute('aria-pressed', l === start ? 'true' : 'false');
			b.addEventListener('click', function () {
				[].forEach.call(w.children, function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
				fill(l);
			});
			w.appendChild(b);
		});
		return w;
	}

	function bar(n) {
		if (store('localStorage', 'sdnb-x-' + n.id)) { return; }
		var b = el('div', 'sdnb-bar sdnb-t--' + n.theme);
		b.setAttribute('role', n.kind === 'alert' || n.kind === 'closure' ? 'alert' : 'region');
		b.setAttribute('aria-label', n.kind === 'alert' ? 'Weather alert' : 'Announcement');
		var f = fx(n.theme, 14); if (f) { b.appendChild(f); }
		var inn = el('div', 'sdnb-in');
		var ic = el('span', 'sdnb-ic'); ic.setAttribute('aria-hidden', 'true');
		if (n.icon) { var im = el('img', 'sd3d'); im.src = n.icon; im.alt = ''; im.width = 34; im.height = 34; ic.appendChild(im); } else { ic.textContent = ICON[n.theme] || ''; }
		inn.appendChild(ic);
		var p = el('p'), h = el('b', 'sdnb-h'), t = el('span', 'sdnb-t'), slot = el('span', 'sdnb-bslot');
		p.appendChild(h); p.appendChild(document.createTextNode(' ')); p.appendChild(t);
		inn.appendChild(p); inn.appendChild(slot);
		var detail = null;
		function fill(lang) {
			var v = pick(n, lang);
			p.lang = v.l; h.textContent = v.t;
			slot.innerHTML = '';
			var bt = button(n.link, v.b); if (bt) { slot.appendChild(bt); }
			if (n.kind === 'alert' && (v.x || '').length > 120) {
				t.textContent = '';
				var more = el('button', 'sdnb-more', 'Details'); more.type = 'button'; more.setAttribute('aria-expanded', 'false');
				t.appendChild(more);
				if (!detail) { detail = el('p', 'sdnb-detail'); detail.hidden = true; inn.appendChild(detail); }
				detail.textContent = v.x;
				more.addEventListener('click', function () { detail.hidden = !detail.hidden; more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true'); more.textContent = detail.hidden ? 'Details' : 'Hide'; });
			} else {
				t.textContent = v.x || '';
			}
		}
		var start = ES_FIRST && hasEs(n) ? 'es' : 'en';
		fill(start);
		if (hasEs(n)) { inn.appendChild(langSwitch(start, fill)); }
		b.appendChild(inn);
		/* click anywhere on the bar (except its buttons and links) opens the full notice */
		b.classList.add('is-clickable');
		b.title = 'Open';
		inn.addEventListener('click', function (e) { if (e.target.closest('a,button')) { return; } popup(n, true); });
		var x = el('button', 'sdnb-x', '\u00d7'); x.type = 'button'; x.setAttribute('aria-label', 'Close announcement');
		x.addEventListener('click', function () { store('localStorage', 'sdnb-x-' + n.id, '1'); b.remove(); });
		b.appendChild(x);
		box.appendChild(b);
	}

	var popOpen = false;
	function popup(n, force) {
		if (popOpen || (!force && store('localStorage', 'sdnb-p-' + n.id))) { return; }
		popOpen = true;
		var center = !!n.home || !!force;
		var wrap = center ? el('div', 'sdnb-modal') : null;
		var c = el('div', 'sdnb-pop sdnb-t--' + n.theme + (center ? ' sdnb-pop--center' : ''));
		c.setAttribute('role', 'dialog'); c.setAttribute('aria-modal', center ? 'true' : 'false');
		var art = el('div', 'sdnb-pop-art');
		var f = fx(n.theme, 12); if (f) { art.appendChild(f); }
		if (n.icon) { var big = el('img', 'sd3d sd3d--big'); big.src = n.icon; big.alt = ''; big.width = 120; big.height = 120; art.appendChild(big); }
		else { art.appendChild(el('span', '', ICON[n.theme] || '\ud83d\udce3')); }
		c.appendChild(art);
		var body = el('div', 'sdnb-pop-b'), h = el('h2'), t = el('p'), slot = el('div', 'sdnb-bslot');
		body.appendChild(h); body.appendChild(t); body.appendChild(slot);
		function fill(lang) {
			var v = pick(n, lang);
			body.lang = v.l; h.textContent = v.t; t.textContent = v.x || ''; t.hidden = !v.x;
			c.setAttribute('aria-label', v.t);
			slot.innerHTML = '';
			var bt = button(n.link, v.b); if (bt) { slot.appendChild(bt); }
		}
		var start = ES_FIRST && hasEs(n) ? 'es' : 'en';
		fill(start);
		if (hasEs(n)) { body.insertBefore(langSwitch(start, fill), h); }
		c.appendChild(body);
		var x = el('button', 'sdnb-x', '\u00d7'); x.type = 'button'; x.setAttribute('aria-label', 'Close');
		var last = null;
		function close() {
			popOpen = false;
			if (!force) { store('localStorage', 'sdnb-p-' + n.id, '1'); }
			(wrap || c).remove(); document.removeEventListener('keydown', esc);
			if (center) { document.documentElement.style.overflow = ''; if (last && last.focus) { last.focus(); } }
		}
		function esc(e) { if (e.key === 'Escape') { close(); } }
		x.addEventListener('click', close);
		document.addEventListener('keydown', esc);
		c.appendChild(x);
		setTimeout(function () {
			if (center) {
				var bg = el('div', 'sdnb-modal-bg'); bg.addEventListener('click', close);
				wrap.appendChild(bg); wrap.appendChild(c); document.body.appendChild(wrap);
				last = document.activeElement; document.documentElement.style.overflow = 'hidden';
				x.focus();
			} else {
				document.body.appendChild(c);
			}
		}, force ? 0 : (center ? 600 : 1200));
	}

	var isHome = document.body && document.body.classList.contains('home');
	var url = C.url + (C.url.indexOf('?') > -1 ? '&' : '?') + 't=' + Math.floor(Date.now() / 60000);
	fetch(url, { credentials: 'omit' }).then(function (r) { return r.ok ? r.json() : { items: [] }; }).then(function (d) {
		var items = d.items || [];
		/* homepage: the important pop-up first */
		if (isHome) { items.filter(function (n) { return n.home; }).forEach(popup); }
		items.forEach(function (n) {
			if (n.placement === 'bar' || n.placement === 'both') { bar(n); }
			if (!n.home && (n.placement === 'popup' || n.placement === 'both')) { popup(n); }
		});
	}).catch(function () {});
})();
