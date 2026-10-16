/* Sam's Driving School — office scanner pop-up (SDS Scan on this computer → Brother on the network).
   Shared by the ID scan (both sides of a card, with a countdown before each side) and the document scan
   (glass page by page, or the document feeder / ADF, one or both sides) that saves one PDF to the file. */
(function () {
	'use strict';

	var BR = 'http://127.0.0.1:52700';
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function bridge(path, o) {
		o = o || {};
		var ctl = window.AbortController ? new AbortController() : null, t = ctl ? setTimeout(function () { ctl.abort(); }, o.timeout || 2500) : null;
		return fetch(BR + path, { method: o.method || 'GET', signal: ctl ? ctl.signal : undefined, targetAddressSpace: 'loopback' }).then(function (r) { if (t) { clearTimeout(t); } return r; });
	}
	function ver(v) { return String(v || '0').split('.').map(Number); }
	function atLeast(v, need) { var a = ver(v), b = ver(need); for (var i = 0; i < 3; i++) { if ((a[i] || 0) !== (b[i] || 0)) { return (a[i] || 0) > (b[i] || 0); } } return true; }
	function store(k, v) { try { if (v === undefined) { return localStorage.getItem('sdsc_scan_' + k); } localStorage.setItem('sdsc_scan_' + k, String(v)); } catch (e) { return null; } return null; }
	function timerSecs() { var v = parseInt(store('timer'), 10); return isNaN(v) ? 3 : v; }
	function beep() { try { var A = window.AudioContext || window.webkitAudioContext, a = new A(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 1200; g.gain.value = 0.08; o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + 0.09); setTimeout(function () { a.close(); }, 300); } catch (e) {} }
	function toImg(blob) { return new Promise(function (ok, no) { var u = URL.createObjectURL(blob), im = new Image(); im.onload = function () { var c = document.createElement('canvas'); c.width = im.naturalWidth; c.height = im.naturalHeight; c.getContext('2d').drawImage(im, 0, 0); URL.revokeObjectURL(u); ok(c); }; im.onerror = function () { URL.revokeObjectURL(u); no(new Error('The scanner sent a picture that could not be opened.')); }; im.src = u; }); }

	/* ------------------------------------------------------------ the pop-up */
	var P = null;
	function pop(title, onClose) {
		close();
		P = document.createElement('div');
		P.className = 'spop';
		P.setAttribute('role', 'dialog'); P.setAttribute('aria-modal', 'true'); P.setAttribute('aria-label', title);
		P.innerHTML = '<div class="spop-box"><header class="spop-h"><b>' + esc(title) + '</b><button type="button" class="spop-x" aria-label="Close">×</button></header><div class="spop-b"></div></div>';
		document.body.appendChild(P);
		document.documentElement.classList.add('spop-on');
		P.querySelector('.spop-x').addEventListener('click', function () { var f = onClose; close(); if (f) { f(); } });
		P._key = function (e) { if (e.key === 'Escape') { var f = onClose; close(); if (f) { f(); } } };
		document.addEventListener('keydown', P._key);
		return P;
	}
	function body(html) { if (!P) { return null; } var b = P.querySelector('.spop-b'); b.innerHTML = html; return b; }
	function close() { if (!P) { return; } stopCount(); document.removeEventListener('keydown', P._key); P.remove(); P = null; document.documentElement.classList.remove('spop-on'); }

	var CT = null;
	function stopCount() { if (CT) { clearInterval(CT); CT = null; } }
	/**
	 * A step with a picture of what to do, a big countdown and buttons. o: { art, title, text, go(), cancel(), extra, done:[canvas,label] }
	 * The countdown length (3 s by default; 5, 10 or off) is remembered on this computer.
	 */
	function step(o) {
		stopCount();
		var n = timerSecs();
		var b = body(
			'<div class="spop-step">' +
				'<div class="spop-art">' + (o.art || '') + '</div>' +
				'<div class="spop-txt"><p class="spop-t">' + o.title + '</p><p class="spop-m">' + (o.text || '') + '</p>' +
					(o.done && o.done.length ? '<div class="spop-done">' + o.done.map(function () { return '<figure><span></span><figcaption></figcaption></figure>'; }).join('') + '</div>' : '') +
				'</div>' +
				'<div class="spop-cd" aria-live="polite"><svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" class="spop-cd-bg"/><circle cx="60" cy="60" r="52" class="spop-cd-fg"/></svg><b class="spop-cd-n"></b><small class="spop-cd-l"></small></div>' +
			'</div>' +
			'<div class="spop-acts"><button type="button" class="sdd-b spop-go">🖨 Scan now</button><button type="button" class="sdd-b sdd-b--ghost spop-pause">⏸ Wait</button>' + (o.extra || '') + '<button type="button" class="sdd-b sdd-b--ghost spop-cancel">Cancel</button>' +
			'<label class="spop-tm">Timer <select><option value="3">3 s</option><option value="5">5 s</option><option value="10">10 s</option><option value="0">Off — I press Scan</option></select></label></div>'
		);
		if (!b) { return; }
		if (o.done) { o.done.forEach(function (d, i) { var f = b.querySelectorAll('.spop-done figure')[i]; var im = new Image(); im.alt = d[1]; im.src = d[0].toDataURL('image/jpeg', 0.7); f.querySelector('span').appendChild(im); f.querySelector('figcaption').textContent = '✓ ' + d[1]; }); }
		var sel = b.querySelector('.spop-tm select'); sel.value = String(n);
		var num = b.querySelector('.spop-cd-n'), lab = b.querySelector('.spop-cd-l'), fg = b.querySelector('.spop-cd-fg'), L = 2 * Math.PI * 52, left = n, paused = !n || !!o.paused;
		fg.style.strokeDasharray = L;
		function paint() {
			if (paused) { num.textContent = '⏸'; lab.textContent = n ? 'paused' : 'press Scan'; fg.style.strokeDashoffset = L; return; }
			num.textContent = left; lab.textContent = 'seconds'; fg.style.strokeDashoffset = L * (1 - left / n);
		}
		function fire() { stopCount(); o.go(); }
		function run() { stopCount(); if (paused || !n) { paint(); return; } paint(); CT = setInterval(function () { left--; if (left <= 0) { fire(); return; } paint(); }, 1000); }
		var root = b;
		root.querySelector('.spop-go').addEventListener('click', fire);
		root.querySelector('.spop-pause').addEventListener('click', function (e) { paused = !paused; e.currentTarget.textContent = paused ? '▶ Start the timer' : '⏸ Wait'; if (!paused) { left = n || 3; if (!n) { n = 3; } } run(); });
		root.querySelector('.spop-cancel').addEventListener('click', function () { close(); if (o.cancel) { o.cancel(); } });
		sel.addEventListener('change', function () { n = parseInt(sel.value, 10) || 0; store('timer', n); left = n; paused = !n; root.querySelector('.spop-pause').textContent = paused ? '▶ Start the timer' : '⏸ Wait'; run(); });
		if (paused) { root.querySelector('.spop-pause').textContent = '▶ Start the timer'; }
		run();
		return root;
	}
	function busy(text) { stopCount(); body('<div class="spop-busy"><span class="spop-spin"></span><p>' + text + '</p></div>'); }
	function problem(msg, retry, cancel) {
		stopCount();
		var b = body('<div class="spop-err"><p>⚠ ' + esc(msg) + '</p></div><div class="spop-acts"><button type="button" class="sdd-b spop-retry">↻ Try again</button><button type="button" class="sdd-b sdd-b--ghost spop-cancel">Close</button></div>');
		b.querySelector('.spop-retry').addEventListener('click', retry);
		b.querySelector('.spop-cancel').addEventListener('click', function () { close(); if (cancel) { cancel(); } });
	}

	/* Pictures of what to do. */
	var ART = {
		cardFront: '<div class="spop-glass"><div class="spop-card spop-card--f"><i></i><s></s><s></s><s></s></div><em>glass</em></div>',
		cardBack: '<div class="spop-glass"><div class="spop-card spop-card--b"><b></b></div><em>glass</em></div>',
		turn: '<div class="spop-glass"><div class="spop-card spop-card--turn"><b></b></div><em>turn it over in the same place</em></div>',
		page: '<div class="spop-glass spop-glass--page"><div class="spop-page"><s></s><s></s><s></s><s></s></div><em>face down · top edge to the back</em></div>',
		pageTurn: '<div class="spop-glass spop-glass--page"><div class="spop-page spop-page--turn"><s></s><s></s></div><em>turn the page over</em></div>',
		feeder: '<div class="spop-adf"><div class="spop-stack"><i></i><i></i><i></i></div><em>pages face up · top edge first</em></div>',
		feederTurn: '<div class="spop-adf"><div class="spop-stack spop-stack--turn"><i></i><i></i><i></i></div><em>turn the whole stack over · same order</em></div>',
	};

	/* ------------------------------------------------------------ document scan → one PDF */
	var JPG = function (c, q) { return new Promise(function (ok) { c.toBlob(function (b) { b.arrayBuffer().then(function (a) { ok(new Uint8Array(a)); }); }, 'image/jpeg', q || 0.82); }); };
	/** A plain PDF with one JPEG picture per page (the page size follows the scan resolution). */
	function pdf(pages) {
		return Promise.all(pages.map(function (p) { return JPG(p.c, 0.8).then(function (j) { return { j: j, w: p.c.width, h: p.c.height, dpi: p.dpi || 200, grey: !!p.grey }; }); })).then(function (list) {
			var enc = new TextEncoder(), parts = [], off = [], len = 0;
			function add(x) { var b = typeof x === 'string' ? enc.encode(x) : x; parts.push(b); len += b.length; }
			function obj(n, s, stream) { off[n] = len; add(n + ' 0 obj\n' + s); if (stream) { add('stream\n'); add(stream); add('\nendstream'); } add('\nendobj\n'); }
			add('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');
			var n = list.length, kids = [];
			for (var i = 0; i < n; i++) { kids.push((3 + i * 3) + ' 0 R'); }
			obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
			obj(2, '<< /Type /Pages /Count ' + n + ' /Kids [' + kids.join(' ') + '] >>');
			list.forEach(function (p, i) {
				var pg = 3 + i * 3, ct = pg + 1, im = pg + 2;
				var W = Math.round(p.w * 72 / p.dpi * 100) / 100, H = Math.round(p.h * 72 / p.dpi * 100) / 100;
				var cs = 'q ' + W + ' 0 0 ' + H + ' 0 0 cm /Im0 Do Q';
				obj(pg, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + W + ' ' + H + '] /Resources << /XObject << /Im0 ' + im + ' 0 R >> >> /Contents ' + ct + ' 0 R >>');
				obj(ct, '<< /Length ' + cs.length + ' >>\n', enc.encode(cs));
				obj(im, '<< /Type /XObject /Subtype /Image /Width ' + p.w + ' /Height ' + p.h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + p.j.length + ' >>\n', p.j);
			});
			var x = len, total = 3 + n * 3;
			var xr = 'xref\n0 ' + total + '\n0000000000 65535 f \n';
			for (var k = 1; k < total; k++) { xr += String(off[k]).padStart(10, '0') + ' 00000 n \n'; }
			add(xr + 'trailer\n<< /Size ' + total + ' /Root 1 0 R >>\nstartxref\n' + x + '\n%%EOF\n');
			return new Blob(parts, { type: 'application/pdf' });
		});
	}
	function rot(c, deg) { var r = document.createElement('canvas'), q = deg % 180 !== 0; r.width = q ? c.height : c.width; r.height = q ? c.width : c.height; var x = r.getContext('2d'); x.translate(r.width / 2, r.height / 2); x.rotate(deg * Math.PI / 180); x.drawImage(c, -c.width / 2, -c.height / 2); return r; }
	/** Grey, or black & white (clean text on white). */
	function tone(c, mode) {
		if (mode === 'color') { return c; }
		var x = c.getContext('2d'), d = x.getImageData(0, 0, c.width, c.height), a = d.data;
		for (var i = 0; i < a.length; i += 4) { var g = a[i] * 0.299 + a[i + 1] * 0.587 + a[i + 2] * 0.114; if (mode === 'bw') { g = g > 165 ? 255 : 0; } a[i] = a[i + 1] = a[i + 2] = g; }
		x.putImageData(d, 0, 0); return c;
	}
	/** An empty side (the back of a one-sided sheet in a two-sided feeder run). */
	function blank(c) {
		var w = 120, h = Math.round(120 * c.height / c.width), t = document.createElement('canvas'); t.width = w; t.height = h;
		var x = t.getContext('2d'); x.drawImage(c, 0, 0, w, h);
		var a = x.getImageData(6, 6, w - 12, h - 12).data, dark = 0, n = a.length / 4;
		for (var i = 0; i < a.length; i += 4) { if (a[i] * 0.299 + a[i + 1] * 0.587 + a[i + 2] * 0.114 < 200) { dark++; } }
		return dark / n < 0.004;
	}
	function thumbOf(c) { var k = Math.min(1, 420 / Math.max(c.width, c.height)), t = document.createElement('canvas'); t.width = Math.round(c.width * k); t.height = Math.round(c.height * k); var x = t.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, t.width, t.height); x.drawImage(c, 0, 0, t.width, t.height); return t.toDataURL('image/jpeg', 0.72); }

	/**
	 * Scan pages into one PDF and hand it to o.upload(file, type, label, thumb).
	 * o: { types: {key: label}, type, label, upload, scanWin, scanMac }
	 */
	function documents(o) {
		var D = { pages: [], cfg: null, sc: null, version: '' };
		var opt = {
			src: store('src') || 'glass', sides: store('sides') || 'one', mode: store('mode') || 'color', dpi: store('dpi') || '200', paper: store('paper') || 'letter', blank: store('blank') !== '0',
			type: o.type || 'other', label: o.label || '',
		};
		pop('Scan documents on the office scanner', null);
		busy('Connecting to the office scanner…');
		bridge('/status', { timeout: 20000 }).then(function (r) { return r.json(); }).then(function (d) {
			D.version = d.version || '0'; D.sc = d.scanner;
			if (!d.scanner) { problem('SDS Scan is running, but the Brother scanner was not found. Check that it is on and on the office Wi-Fi (or set its address with “Office scanner” in the ID scan above).', function () { documents(o); }); return; }
			settings();
		}).catch(function () { setup(); });

		function setup() {
			var mac = /Mac/.test(navigator.platform || navigator.userAgent);
			var b = body('<div class="spop-setup"><p class="spop-t">🖨 One-time setup on this computer</p><ol><li><a class="sdd-b" href="' + esc(mac ? o.scanMac : o.scanWin) + '" download>⬇ Download SDS Scan for ' + (mac ? 'Mac' : 'Windows') + '</a></li><li>Open it and run <b>Install SDS Scan</b>.</li><li>If the browser asks to use devices on your local network, choose <b>Allow</b>.</li></ol></div><div class="spop-acts"><button type="button" class="sdd-b spop-retry">↻ Check again</button><button type="button" class="sdd-b sdd-b--ghost spop-cancel">Close</button></div>');
			b.querySelector('.spop-retry').addEventListener('click', function () { documents(o); });
			b.querySelector('.spop-cancel').addEventListener('click', close);
		}

		function settings() {
			var adfOk = D.sc.adf && atLeast(D.version, '1.2.0');
			if (!adfOk && opt.src === 'adf') { opt.src = 'glass'; }
			var typeOpts = Object.keys(o.types || {}).map(function (k) { return '<option value="' + esc(k) + '"' + (k === opt.type ? ' selected' : '') + '>' + esc(o.types[k]) + '</option>'; }).join('');
			var radio = function (name, val, label, sub, dis) { return '<label class="spop-opt' + (dis ? ' is-off' : '') + '"><input type="radio" name="' + name + '" value="' + val + '"' + (opt[name] === val ? ' checked' : '') + (dis ? ' disabled' : '') + '><span><b>' + label + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</span></label>'; };
			var b = body(
				'<form class="spop-set">' +
				'<div class="spop-row"><label>Document <select name="type">' + typeOpts + '</select></label><label>Note (optional) <input name="label" maxlength="120" value="' + esc(opt.label) + '" placeholder="e.g. practice log, 6 pages"></label></div>' +
				'<fieldset><legend>Where are the pages?</legend><div class="spop-opts">' +
					radio('src', 'glass', '🔲 On the glass', 'one page at a time · also for cards, books, torn pages') +
					radio('src', 'adf', '📥 In the document feeder (ADF)', D.sc.adf ? (adfOk ? 'many pages in one go · up to 50 sheets' : 'update SDS Scan to use the feeder') : 'this scanner has no feeder', !adfOk) +
				'</div></fieldset>' +
				'<fieldset><legend>Sides</legend><div class="spop-opts">' +
					radio('sides', 'one', 'One side', 'printed on one side only') +
					radio('sides', 'two', 'Both sides', D.sc.duplex ? 'the feeder turns the pages by itself' : 'you turn the page (or the whole stack) over when asked') +
				'</div></fieldset>' +
				'<div class="spop-row">' +
					'<label>Colour <select name="mode"><option value="color">Colour</option><option value="grey">Grey</option><option value="bw">Black &amp; white (smallest)</option></select></label>' +
					'<label>Quality <select name="dpi"><option value="150">Normal (150 dpi)</option><option value="200">Sharp (200 dpi)</option><option value="300">Best (300 dpi)</option></select></label>' +
					'<label>Paper <select name="paper"><option value="letter">Letter 8.5 × 11</option><option value="legal">Legal 8.5 × 14</option><option value="full">Whole glass</option></select></label>' +
				'</div>' +
				'<label class="spop-chk"><input type="checkbox" name="blank"' + (opt.blank ? ' checked' : '') + '> Leave out empty pages (the blank backs of one-sided sheets)</label>' +
				(D.sc.adf && !atLeast(D.version, '1.2.0') ? '<p class="spop-note">A newer SDS Scan (1.2) can use the document feeder: <a href="' + esc(/Mac/.test(navigator.userAgent) ? o.scanMac : o.scanWin) + '" download>download</a>, run <b>Install SDS Scan</b> again, then reopen this window.</p>' : '') +
				'</form>' +
				'<div class="spop-acts"><button type="button" class="sdd-b spop-start">Start scanning →</button><button type="button" class="sdd-b sdd-b--ghost spop-cancel">Cancel</button><span class="spop-model">🖨 ' + esc(D.sc.model) + '</span></div>'
			);
			var f = b.querySelector('form');
			f.mode.value = opt.mode; f.dpi.value = opt.dpi; f.paper.value = opt.paper;
			function sync() { var adf = f.querySelector('[name=src]:checked').value === 'adf'; f.paper.querySelector('[value=full]').disabled = adf; if (adf && f.paper.value === 'full') { f.paper.value = 'letter'; } f.paper.querySelector('[value=legal]').disabled = false; }
			f.addEventListener('change', sync); sync();
			b.querySelector('.spop-cancel').addEventListener('click', close);
			b.querySelector('.spop-start').addEventListener('click', function () {
				opt.type = f.type.value; opt.label = f.label.value.trim(); opt.src = f.querySelector('[name=src]:checked').value; opt.sides = f.querySelector('[name=sides]:checked').value;
				opt.mode = f.mode.value; opt.dpi = f.dpi.value; opt.paper = f.paper.value; opt.blank = f.blank.checked;
				['src', 'sides', 'mode', 'dpi', 'paper'].forEach(function (k) { store(k, opt[k]); }); store('blank', opt.blank ? 1 : 0);
				if (opt.src === 'adf') { feeder(); } else { glass(false); }
			});
		}

		function q(extra) { return 'dpi=' + opt.dpi + (opt.mode === 'color' ? '' : '&gray=1') + (opt.paper !== 'full' ? '&paper=' + opt.paper : '') + (extra || ''); }
		function addPage(c) { D.pages.push({ c: tone(c, opt.mode), dpi: +opt.dpi }); }
		function done() { return D.pages.slice(-2).map(function (p, i, a) { return [p.c, 'Page ' + (D.pages.length - a.length + i + 1)]; }); }

		/* Glass: one page (or side) at a time, with the countdown before each. */
		function glass(back, empty) {
			var nr = D.pages.length + 1, two = opt.sides === 'two';
			step({
				paused: !!empty,
				art: back ? ART.pageTurn : ART.page,
				title: empty ? 'The glass looked empty' : (back ? 'Turn the page over — the back now' : (D.pages.length ? 'Next page on the glass' : 'Put the first page face down on the glass')),
				text: empty ? 'Put the next page on the glass and press Scan — or press Finished if that was the last page.' : (back ? 'Same place on the glass, then close the lid.' : 'Top edge towards the back of the scanner, then close the lid.') + ' Scanning starts by itself when the timer runs out.',
				done: D.pages.length ? done() : null,
				extra: D.pages.length ? '<button type="button" class="sdd-b sdd-b--ghost spop-fin">✓ Finished — ' + D.pages.length + ' page' + (D.pages.length > 1 ? 's' : '') + '</button>' : '',
				go: function () {
					busy('Scanning ' + (back ? 'the back of page ' + Math.ceil(nr / 2) : 'page ' + nr) + '…');
					bridge('/scan?' + q(), { method: 'POST', timeout: 120000 }).then(function (r) {
						if (!r.ok) { return r.json().then(function (j) { throw new Error(j.msg || 'Scan failed.'); }); }
						return r.blob();
					}).then(toImg).then(function (c) {
						beep();
						if (blank(c)) {
							// Nothing on the glass (or an empty back): do not keep scanning by itself.
							if (back && opt.blank) { glass(false); return; }
							if (!back) { glass(false, true); return; }
						}
						addPage(c);
						glass(two && !back);
					}).catch(function (e) { problem(e.message || 'Scan failed.', function () { glass(back); }, null); });
				},
				cancel: function () { D.pages = []; },
			});
			var fin = P && P.querySelector('.spop-fin'); if (fin) { fin.addEventListener('click', review); }
		}

		/* Feeder: all pages in one go; both sides by itself (duplex) or by turning the stack over. */
		function feeder(backs, fronts) {
			var manual = opt.sides === 'two' && !D.sc.duplex;
			step({
				art: backs ? ART.feederTurn : ART.feeder,
				title: backs ? 'Take the pages out and turn the whole stack over' : 'Put the pages in the document feeder',
				text: backs ? 'Do not change the order — just turn the stack over (the last page is now on top) and put it back in the feeder.' : 'Face up, top edge first, straight against the guides. Up to 50 sheets.',
				done: D.pages.length ? done() : null,
				go: function () {
					busy(backs ? 'Feeding the backs…' : 'Feeding the pages…');
					var dup = opt.sides === 'two' && D.sc.duplex ? '&duplex=1' : '';
					bridge('/scan?source=adf&' + q(dup), { method: 'POST', timeout: 600000 }).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) { throw new Error(j.msg || 'Scan failed.'); } return j; }); }).then(function (j) {
						var got = [], i = 0;
						function next() {
							if (i >= j.pages) { return Promise.resolve(got); }
							busy('Reading page ' + (i + 1) + ' of ' + j.pages + '…');
							return bridge('/page?id=' + encodeURIComponent(j.id) + '&i=' + i, { timeout: 60000 }).then(function (r) { if (!r.ok) { throw new Error('A page could not be read from SDS Scan.'); } return r.blob(); }).then(toImg).then(function (c) { got.push(c); i++; return next(); });
						}
						return next();
					}).then(function (got) {
						beep();
						if (manual && !backs) { feeder(true, got); return; }
						var list = got;
						if (manual && backs) {
							// fronts 1..n, backs come in reverse (n..1): page 1 front, page 1 back, page 2 front …
							list = [];
							fronts.forEach(function (f, k) { list.push(f); var bk = got[got.length - 1 - k]; if (bk) { list.push(bk); } });
							if (got.length > fronts.length) { list = list.concat(got.slice(0, got.length - fronts.length).reverse()); }
						}
						list.forEach(function (c) { if (opt.blank && opt.sides === 'two' && blank(c)) { return; } addPage(c); });
						review();
					}).catch(function (e) { problem(e.message || 'Scan failed.', function () { feeder(backs, fronts); }, null); });
				},
				cancel: function () { D.pages = []; },
			});
		}

		/* Check the pages: turn, move, remove; add more; save as one PDF. */
		function review() {
			stopCount();
			if (!D.pages.length) { problem('No pages were scanned.', function () { settings(); }); return; }
			var b = body('<p class="spop-t">' + D.pages.length + ' page' + (D.pages.length > 1 ? 's' : '') + ' — check them, then save</p><ol class="spop-strip"></ol>' +
				'<div class="spop-acts"><button type="button" class="sdd-b spop-save">💾 Save to documents (one PDF)</button><button type="button" class="sdd-b sdd-b--ghost spop-more">＋ Scan more pages</button><button type="button" class="sdd-b sdd-b--ghost spop-cancel">Discard</button></div>');
			var ol = b.querySelector('.spop-strip');
			function paint() {
				ol.innerHTML = D.pages.map(function (p, i) {
					return '<li><span class="spop-pg"></span><b>' + (i + 1) + '</b><div><button type="button" data-a="l" data-i="' + i + '" aria-label="Move page ' + (i + 1) + ' left"' + (i ? '' : ' disabled') + '>◀</button><button type="button" data-a="r" data-i="' + i + '" aria-label="Turn page ' + (i + 1) + '">↻</button><button type="button" data-a="d" data-i="' + i + '" aria-label="Remove page ' + (i + 1) + '">✕</button><button type="button" data-a="n" data-i="' + i + '" aria-label="Move page ' + (i + 1) + ' right"' + (i < D.pages.length - 1 ? '' : ' disabled') + '>▶</button></div></li>';
				}).join('');
				ol.querySelectorAll('.spop-pg').forEach(function (s, i) { var im = new Image(); im.alt = 'Page ' + (i + 1); im.src = thumbOf(D.pages[i].c); s.appendChild(im); });
				b.querySelector('.spop-t').textContent = D.pages.length + ' page' + (D.pages.length > 1 ? 's' : '') + ' — check them, then save';
				b.querySelector('.spop-save').disabled = !D.pages.length;
			}
			ol.addEventListener('click', function (e) {
				var t = e.target.closest('[data-a]'); if (!t) { return; }
				var i = +t.getAttribute('data-i'), a = t.getAttribute('data-a'), p = D.pages;
				if (a === 'r') { p[i].c = rot(p[i].c, 90); }
				if (a === 'd') { p.splice(i, 1); }
				if (a === 'l' && i > 0) { p.splice(i - 1, 0, p.splice(i, 1)[0]); }
				if (a === 'n' && i < p.length - 1) { p.splice(i + 1, 0, p.splice(i, 1)[0]); }
				paint();
			});
			b.querySelector('.spop-more').addEventListener('click', function () { if (opt.src === 'adf') { feeder(); } else { glass(false); } });
			b.querySelector('.spop-cancel').addEventListener('click', function () { D.pages = []; close(); });
			b.querySelector('.spop-save').addEventListener('click', function () {
				var n = D.pages.length;
				busy('Making the PDF (' + n + ' page' + (n > 1 ? 's' : '') + ')…');
				var th = thumbOf(D.pages[0].c);
				pdf(D.pages).then(function (blob) {
					if (blob.size > 19.5 * 1024 * 1024) { throw new Error('The PDF is ' + Math.round(blob.size / 1048576) + ' MB — over the 20 MB limit. Use Grey or Black & white, or Normal quality, or save fewer pages at a time.'); }
					var name = ((o.types || {})[opt.type] || 'document').replace(/[^\w]+/g, '-').toLowerCase() + '-' + n + 'p.pdf';
					busy('Saving to the student’s documents…');
					return Promise.resolve(o.upload(new File([blob], name, { type: 'application/pdf' }), opt.type, (opt.label ? opt.label + ' · ' : '') + n + ' page' + (n > 1 ? 's' : '') + ' · scanned', th));
				}).then(function () { D.pages = []; close(); }).catch(function (e) { problem(e.message, function () { review(); }); });
			});
			paint();
		}
	}

	window.SDSC_SCANPOP = { isOpen: function () { return !!P; }, bridge: bridge, pop: pop, body: body, close: close, step: step, busy: busy, problem: problem, art: ART, documents: documents, atLeast: atLeast, _pdf: pdf, _blank: blank };
})();
