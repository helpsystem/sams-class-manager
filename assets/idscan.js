/* Sam's Driving School — scan an ID / learner's permit / license, read it, compare it with the registration.
   Barcode (PDF417, AAMVA standard on the back of every Maryland card) first; OCR of the printed front when
   there is no barcode. Everything runs in the browser from the plugin's own files. */
(function () {
	'use strict';
	var C = window.SDSC_IDS;
	if (!C) { return; }

	var loaded = {};
	function loadScript(src) {
		if (!loaded[src]) {
			loaded[src] = new Promise(function (ok, no) { var s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = function () { delete loaded[src]; no(new Error('Could not load a reader. Check the connection and try again.')); }; document.head.appendChild(s); });
		}
		return loaded[src];
	}
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function post(action, data) {
		var fd = new FormData(); fd.append('action', 'sdsc_id_' + action); fd.append('_n', C.nonce);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) { if (!j || !j.success) { throw new Error((j && j.data && j.data.msg) || 'Something went wrong.'); } return j.data; });
	}

	/* ------------------------------------------------------------ image clean-up */
	function canvasFrom(src, max) {
		var w = src.naturalWidth || src.videoWidth || src.width, h = src.naturalHeight || src.videoHeight || src.height, k = Math.min(1, (max || 2000) / Math.max(w, h));
		var c = document.createElement('canvas'); c.width = Math.round(w * k); c.height = Math.round(h * k);
		var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(src, 0, 0, c.width, c.height);
		return c;
	}
	function rotate(c, deg) {
		if (!deg) { return c; }
		var r = document.createElement('canvas'), q = deg % 180 !== 0;
		r.width = q ? c.height : c.width; r.height = q ? c.width : c.height;
		var x = r.getContext('2d'); x.translate(r.width / 2, r.height / 2); x.rotate(deg * Math.PI / 180); x.drawImage(c, -c.width / 2, -c.height / 2);
		return r;
	}
	function crop(c, x0, y0, w, h) { var r = document.createElement('canvas'); r.width = w; r.height = h; r.getContext('2d').drawImage(c, x0, y0, w, h, 0, 0, w, h); return r; }
	/** Find the card on a plain background (scanner glass, table) and cut it out; turn it landscape. */
	function autoCrop(c) {
		var w = c.width, h = c.height, d = c.getContext('2d').getImageData(0, 0, w, h).data, g = new Uint8Array(w * h), i;
		for (i = 0; i < w * h; i++) { g[i] = (d[i * 4] * 0.299 + d[i * 4 + 1] * 0.587 + d[i * 4 + 2] * 0.114) | 0; }
		var bw = Math.max(4, Math.round(Math.min(w, h) * 0.03)), sum = 0, n = 0, x, y;
		for (y = 0; y < h; y++) { for (x = 0; x < w; x++) { if (x < bw || y < bw || x >= w - bw || y >= h - bw) { sum += g[y * w + x]; n++; } } }
		var bg = sum / n, rows = new Uint32Array(h), cols = new Uint32Array(w);
		for (y = 0; y < h; y++) { for (x = 0; x < w; x++) { if (Math.abs(g[y * w + x] - bg) > 38) { rows[y]++; cols[x]++; } } }
		function span(arr, len, other) { var lim = other * 0.06, a = 0, b = len - 1; while (a < len && arr[a] < lim) { a++; } while (b > a && arr[b] < lim) { b--; } return [a, b]; }
		var ry = span(rows, h, w), rx = span(cols, w, h), cw = rx[1] - rx[0], ch = ry[1] - ry[0];
		var out = c;
		if (cw > w * 0.25 && ch > h * 0.2 && cw * ch < w * h * 0.93) {
			var m = Math.round(Math.max(cw, ch) * 0.015);
			var x0 = Math.max(0, rx[0] - m), y0 = Math.max(0, ry[0] - m);
			out = crop(c, x0, y0, Math.min(w - x0, cw + 2 * m), Math.min(h - y0, ch + 2 * m));
		}
		if (out.height > out.width * 1.15) { out = rotate(out, 90); }
		return out;
	}
	/** Stretch the contrast (2nd–98th percentile) so faded or dark photos read better. */
	function enhance(c) {
		var x = c.getContext('2d'), im = x.getImageData(0, 0, c.width, c.height), d = im.data, hist = new Uint32Array(256), i, n = d.length / 4;
		for (i = 0; i < d.length; i += 4) { hist[(d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114) | 0]++; }
		var lo = 0, hi = 255, acc = 0;
		for (i = 0; i < 256; i++) { acc += hist[i]; if (acc > n * 0.02) { lo = i; break; } }
		acc = 0; for (i = 255; i >= 0; i--) { acc += hist[i]; if (acc > n * 0.02) { hi = i; break; } }
		if (hi - lo < 40) { return c; }
		var k = 255 / (hi - lo), lut = new Uint8ClampedArray(256);
		for (i = 0; i < 256; i++) { lut[i] = (i - lo) * k; }
		for (i = 0; i < d.length; i += 4) { d[i] = lut[d[i]]; d[i + 1] = lut[d[i + 1]]; d[i + 2] = lut[d[i + 2]]; }
		x.putImageData(im, 0, 0);
		return c;
	}

	/* ------------------------------------------------------------ barcode */
	var reader = null;
	function zx() {
		return loadScript(C.zxing).then(function () {
			if (!reader) {
				var Z = window.ZXing, hints = new Map();
				hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.PDF_417]);
				hints.set(Z.DecodeHintType.TRY_HARDER, true);
				reader = new Z.MultiFormatReader(); reader.setHints(hints);
			}
			return window.ZXing;
		});
	}
	function decode(Z, c) {
		var bins = [Z.HybridBinarizer, Z.GlobalHistogramBinarizer];
		for (var i = 0; i < bins.length; i++) {
			try { var bmp = new Z.BinaryBitmap(new bins[i](new Z.HTMLCanvasElementLuminanceSource(c))); var r = reader.decode(bmp); if (r && /ANSI|AAMVA|DAQ|DCS|DAA/.test(r.getText())) { return r.getText(); } } catch (e) { /* not found in this try */ }
			try { reader.reset(); } catch (e2) {}
		}
		return null;
	}
	function barcode(c) {
		return zx().then(function (Z) {
			var sizes = [c, canvasFrom(c, 1400), canvasFrom(c, 2200)];
			for (var s = 0; s < sizes.length; s++) { for (var r = 0; r < 360; r += 90) { var t = decode(Z, rotate(sizes[s], r)); if (t) { return t; } } }
			return null;
		});
	}

	/** AAMVA (US / Canada driver's license and ID standard) → fields. */
	function aamva(text) {
		var t = String(text).replace(/\r\n?/g, '\n');
		function get(code) { var m = new RegExp('(?:^|[\\n\\x1e]|DL|ID)' + code + '([^\\n\\x1e]*)').exec(t); return m ? m[1].replace(/\s+$/, '').trim() : ''; }
		var canada = /DCGCAN/.test(t);
		function date(v) {
			v = (v || '').replace(/\D/g, ''); if (v.length !== 8) { return ''; }
			if (canada || +v.slice(0, 4) > 1900) { return v.slice(0, 4) + '-' + v.slice(4, 6) + '-' + v.slice(6, 8); }
			return v.slice(4, 8) + '-' + v.slice(0, 2) + '-' + v.slice(2, 4);
		}
		var f = { last: get('DCS'), first: get('DAC'), middle: get('DAD') };
		if (!f.first && get('DCT')) { var ft = get('DCT').split(/[ ,]+/); f.first = ft.shift() || ''; f.middle = f.middle || ft.join(' '); }
		if (!f.last && get('DAA')) { var a = get('DAA').split(','); f.last = a[0] || ''; f.first = f.first || a[1] || ''; f.middle = f.middle || a[2] || ''; }
		if (/^NONE$/i.test(f.middle)) { f.middle = ''; }
		f.dob = date(get('DBB')); f.issued = date(get('DBD')); f.expires = date(get('DBA'));
		f.sex = { '1': 'M', '2': 'F', '9': 'X', 'M': 'M', 'F': 'F' }[get('DBC').charAt(0)] || '';
		f.street = (get('DAG') + (get('DAH') ? ' ' + get('DAH') : '')).trim();
		f.city = get('DAI'); f.state = get('DAJ'); f.zip = get('DAK').replace(/\D/g, '').slice(0, 5);
		f.number = get('DAQ'); f['class'] = get('DCA');
		Object.keys(f).forEach(function (k) { if (!f[k]) { delete f[k]; } });
		return f;
	}

	/* ------------------------------------------------------------ OCR (front side) */
	var tworker = null;
	function ocrWorker(status) {
		if (tworker) { return tworker; }
		tworker = loadScript(C.tess + 'tesseract.min.js').then(function () {
			return window.Tesseract.createWorker('eng', 1, { workerPath: C.tess + 'worker.min.js', corePath: C.tess, langPath: C.tess, gzip: true, workerBlobURL: false, logger: function (m) { if (m && m.status && status) { status('Reading the printed text… ' + (m.progress ? Math.round(m.progress * 100) + '%' : '')); } } });
		}).catch(function (e) { tworker = null; throw e; });
		return tworker;
	}
	function ocr(c, status) {
		return ocrWorker(status).then(function (w) {
			var big = canvasFrom(c, 2400);
			return w.recognize(big).then(function (r) {
				if (r.data.confidence >= 60) { return r.data; }
				return w.recognize(rotate(big, 180)).then(function (r2) { return r2.data.confidence > r.data.confidence ? r2.data : r.data; });
			});
		});
	}
	function parseFront(text) {
		var t = String(text || '').toUpperCase().replace(/[|]/g, 'I'), f = {}, m;
		function d(s) { var x = /(\d{2})[\/\-.](\d{2})[\/\-.](\d{4})/.exec(s || ''); return x ? x[3] + '-' + x[1] + '-' + x[2] : ''; }
		if ((m = /\b([A-Z][- ]?\d{3}[- ]?\d{3}[- ]?\d{3}[- ]?\d{3})\b/.exec(t))) { f.number = m[1].replace(/\s/g, '-'); }
		if ((m = /DOB[:.\s]*([0-9\/\-.]{8,10})/.exec(t))) { f.dob = d(m[1]); }
		if ((m = /EXP[:.\s]*([0-9\/\-.]{8,10})/.exec(t))) { f.expires = d(m[1]); }
		if ((m = /ISS[:.\s]*([0-9\/\-.]{8,10})/.exec(t))) { f.issued = d(m[1]); }
		if ((m = /SEX[:.\s]*([MFX])\b/.exec(t))) { f.sex = m[1]; }
		if ((m = /(?:^|\n)\s*1\s+([A-Z][A-Z' \-]{1,40})\s*(?:\n|$)/.exec(t))) { f.last = m[1].trim(); }
		if ((m = /(?:^|\n)\s*2\s+([A-Z][A-Z' \-]{1,60})\s*(?:\n|$)/.exec(t))) { var p = m[1].trim().split(/\s+/); f.first = p.shift(); if (p.length) { f.middle = p.join(' '); } }
		if ((m = /(?:^|\n)\s*8\s+(\d+[^\n]{3,60})\n\s*([A-Z .'\-]{2,40}?),?\s+([A-Z]{2})\s+(\d{5})/.exec(t))) { f.street = m[1].trim(); f.city = m[2].trim(); f.state = m[3]; f.zip = m[4]; }
		else if ((m = /\n\s*([A-Z .'\-]{2,40}?),?\s+(MD|DC|VA|PA|DE|WV)\s+(\d{5})/.exec(t))) { f.city = m[1].trim(); f.state = m[2]; f.zip = m[3]; }
		if (!f.street && (m = /(?:^|\n)\s*(?:8\s+)?(\d+\s+[A-Z0-9 .#'\-]{3,60})\s*\n\s*[A-Z .'\-]{2,40}?,?\s+[A-Z]{2}\s+\d{5}/.exec(t))) { f.street = m[1].trim(); }
		if (/MARYLAND/.test(t)) { f.state = f.state || 'MD'; }
		return f;
	}

	/* ------------------------------------------------------------ compare */
	var ABBR = { STREET: 'ST', AVENUE: 'AVE', ROAD: 'RD', DRIVE: 'DR', COURT: 'CT', LANE: 'LN', PLACE: 'PL', BOULEVARD: 'BLVD', TERRACE: 'TER', CIRCLE: 'CIR', PARKWAY: 'PKWY', HIGHWAY: 'HWY', APARTMENT: 'APT', SUITE: 'STE', NORTH: 'N', SOUTH: 'S', EAST: 'E', WEST: 'W', NORTHWEST: 'NW', NORTHEAST: 'NE', SOUTHWEST: 'SW', SOUTHEAST: 'SE', UNIT: 'APT', '#': 'APT' };
	function norm(k, v) {
		v = String(v || '').toUpperCase().trim();
		if (k === 'zip') { return v.replace(/\D/g, '').slice(0, 5); }
		if (k === 'state') { return { MARYLAND: 'MD', VIRGINIA: 'VA', 'DISTRICT OF COLUMBIA': 'DC' }[v] || v; }
		if (k === 'sex') { return { MALE: 'M', FEMALE: 'F' }[v] || v.charAt(0); }
		if (k === 'street') { return v.replace(/#/g, ' APT ').replace(/[.,]/g, ' ').split(/\s+/).map(function (w) { return ABBR[w] || w; }).join(' ').trim(); }
		return v.replace(/[^A-Z0-9]/g, '');
	}
	var ROWS = [['first', 'First name'], ['middle', 'Middle name'], ['last', 'Last name'], ['dob', 'Date of birth'], ['sex', 'Sex'], ['street', 'Street address'], ['city', 'City'], ['state', 'State'], ['zip', 'ZIP code'], ['number', 'Document number'], ['issued', 'Issued'], ['expires', 'Expires'], ['class', 'Class']];
	var ONREG = { first: 1, middle: 1, last: 1, dob: 1, sex: 1, street: 1, city: 1, state: 1, zip: 1 };
	function us(d) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d || ''); return m ? m[2] + '/' + m[3] + '/' + m[1] : (d || ''); }
	function today() { var n = new Date(); return n.getFullYear() + '-' + ('0' + (n.getMonth() + 1)).slice(-2) + '-' + ('0' + n.getDate()).slice(-2); }

	/* ------------------------------------------------------------ the panel */
	function mount(el, email, opts) {
		opts = opts || {};
		var S = { reg: null, check: null, fields: null, src: '', img: null, side: '' };
		el.innerHTML = '<div class="ids-card"><div class="ids-head"><span class="ids-ic" aria-hidden="true">🪪</span><div><h4>Scan ID, learner’s permit or license</h4><p>Use the <b>back</b> of the card when you can — its barcode gives the exact name, date of birth, address and number. The picture is straightened and cropped automatically.</p></div></div>' +
			'<div class="ids-bar"><label>Document <select class="ids-doc"><option value="permit">Learner’s permit</option><option value="license">Driver’s license</option><option value="id">ID card</option></select></label>' +
			'<button type="button" class="sdd-b" data-ids="live">📷 Live camera</button><button type="button" class="sdd-b sdd-b--ghost" data-ids="photo">📸 Take photo</button><button type="button" class="sdd-b sdd-b--ghost" data-ids="file">⬆ Scanner file</button></div>' +
			'<p class="ids-status" role="status" aria-live="polite"></p><div class="ids-out"></div><p class="ids-last"></p></div>';
		var st = el.querySelector('.ids-status'), out = el.querySelector('.ids-out');
		function status(t, bad) { st.textContent = t || ''; st.className = 'ids-status' + (bad ? ' is-bad' : ''); }
		function last() { el.querySelector('.ids-last').textContent = S.check && S.check.summary ? 'Last check: ' + S.check.summary : ''; }
		post('reg', { email: email }).then(function (d) { S.reg = d.reg; S.check = d.check; last(); if (S.fields) { show(); } }).catch(function () {});

		el.querySelector('.ids-bar').addEventListener('click', function (e) {
			var b = e.target.closest('[data-ids]'); if (!b) { return; }
			var k = b.getAttribute('data-ids');
			if (k === 'live') { live(); return; }
			var i = document.createElement('input'); i.type = 'file'; i.accept = 'image/*'; if (k === 'photo') { i.setAttribute('capture', 'environment'); }
			i.addEventListener('change', function () { if (i.files[0]) { fromFile(i.files[0]); } }); i.click();
		});

		function fromFile(file) {
			if (!/^image\//.test(file.type)) { status('Please choose a photo or a scanner image (JPG or PNG). For a PDF from the scanner, save it as JPG.', true); return; }
			status('Opening the picture…');
			var u = URL.createObjectURL(file), im = new Image();
			im.onload = function () { var c = canvasFrom(im, 2600); URL.revokeObjectURL(u); run(c, null); };
			im.onerror = function () { status('This picture could not be opened (HEIC photos: set the camera to “Most compatible”, or use Take photo).', true); };
			im.src = u;
		}

		function run(c, pre) {
			out.innerHTML = '';
			status('Straightening and cropping…');
			setTimeout(function () {
				var fixed;
				try { fixed = enhance(autoCrop(canvasFrom(c, 2600))); } catch (e) { fixed = c; }
				S.img = fixed;
				status('Looking for the barcode…');
				(pre ? Promise.resolve(pre) : barcode(fixed)).then(function (text) {
					if (text) { S.fields = aamva(text); S.src = 'barcode'; S.side = 'back'; status('Read from the barcode ✓'); show(); return; }
					status('No barcode on this side — reading the printed text…');
					return ocr(fixed, status).then(function (data) {
						S.fields = parseFront(data.text); S.src = 'ocr'; S.side = 'front';
						status(Object.keys(S.fields).length ? 'Read from the printed text — please check each value.' : 'Could not read this card. Try the back side (barcode), more light, or hold the phone straight above the card.', !Object.keys(S.fields).length);
						show();
					});
				}).catch(function (e) { status(e.message, true); });
			}, 30);
		}

		function show() {
			var f = S.fields || {}, r = S.reg || {}, apply = C.apply && S.reg;
			var regv = function (k) { return k === 'dob' ? r.dob : r[k]; };
			var html = '<div class="ids-res"><figure class="ids-img"></figure><div class="ids-tab"><table><thead><tr><th>Field</th><th>On the card' + (S.src === 'ocr' ? ' <small>(read from text — check)</small>' : '') + '</th><th>On the registration' + (r.file ? ' <small>' + esc(r.file) + '</small>' : '') + '</th><th></th>' + (apply ? '<th title="Copy into the registration">Use</th>' : '') + '</tr></thead><tbody>';
			ROWS.forEach(function (row) {
				var k = row[0], cv = f[k] || '', rv = ONREG[k] ? (regv(k) || '') : null;
				html += '<tr data-k="' + k + '"><th>' + esc(row[1]) + '</th><td><input value="' + esc(k === 'dob' || k === 'issued' || k === 'expires' ? us(cv) : cv) + '" data-f="' + k + '" aria-label="' + esc(row[1]) + ' on the card"></td><td>' + (rv === null ? '<span class="ids-na">—</span>' : esc(k === 'dob' ? us(rv) : rv) || '<span class="ids-na">empty</span>') + '</td><td class="ids-m"></td>' + (apply ? '<td>' + (ONREG[k] ? '<input type="checkbox" data-use="' + k + '" aria-label="Use the card value for ' + esc(row[1]) + '">' : '') + '</td>' : '') + '</tr>';
			});
			html += '</tbody></table><div class="ids-flags"></div><div class="ids-acts"><button type="button" class="sdd-b sdd-b--go" data-act="save">✓ Save the scan and the check</button>' + (apply ? '<button type="button" class="sdd-b sdd-b--ghost" data-act="apply">Copy ticked values into the registration</button>' : '') + '</div>' +
				(!S.reg ? '<p class="ids-na">No registration form for this student, so there is nothing to compare with.</p>' : '') + '</div></div>';
			out.innerHTML = html;
			if (S.img) { var im = new Image(); im.alt = 'Scanned card'; im.src = S.img.toDataURL('image/jpeg', 0.9); out.querySelector('.ids-img').appendChild(im); }
			out.querySelectorAll('[data-f]').forEach(function (i) { i.addEventListener('input', marks); });
			marks();
			out.querySelector('[data-act="save"]').addEventListener('click', save);
			var ap = out.querySelector('[data-act="apply"]'); if (ap) { ap.addEventListener('click', applyIt); }
		}

		function values() {
			var f = {};
			out.querySelectorAll('[data-f]').forEach(function (i) {
				var k = i.getAttribute('data-f'), v = i.value.trim();
				if (/^(dob|issued|expires)$/.test(k)) { var m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(v); v = m ? m[3] + '-' + ('0' + m[1]).slice(-2) + '-' + ('0' + m[2]).slice(-2) : (/^\d{4}-\d{2}-\d{2}$/.test(v) ? v : ''); }
				if (v) { f[k] = v; }
			});
			return f;
		}
		function marks() {
			var f = values(), r = S.reg || {}, diff = [], flags = [];
			out.querySelectorAll('tr[data-k]').forEach(function (tr) {
				var k = tr.getAttribute('data-k'), m = tr.querySelector('.ids-m'), use = tr.querySelector('[data-use]');
				if (!ONREG[k] || !S.reg) { m.textContent = ''; return; }
				var a = norm(k, f[k]), b = norm(k, k === 'dob' ? r.dob : r[k]);
				if (!a) { m.innerHTML = '<span class="ids-na">not on card</span>'; if (use) { use.checked = false; use.disabled = true; } return; }
				if (use) { use.disabled = false; }
				if (!b) { m.innerHTML = '<span class="ids-add">missing</span>'; diff.push(k); if (use && !use.dataset.touched) { use.checked = true; } return; }
				if (a === b || (k === 'street' && (a.indexOf(b) === 0 || b.indexOf(a) === 0))) { m.innerHTML = '<span class="ids-ok">✓ same</span>'; if (use && !use.dataset.touched) { use.checked = false; } return; }
				m.innerHTML = '<span class="ids-bad">✗ differs</span>'; diff.push(k);
			});
			out.querySelectorAll('[data-use]').forEach(function (u) { u.onchange = function () { u.dataset.touched = '1'; }; });
			if (f.expires && f.expires < today()) { flags.push('<b class="ids-bad">This document expired on ' + esc(us(f.expires)) + '.</b>'); }
			if (f.dob) { var age = (new Date(today()) - new Date(f.dob)) / 31557600000; if (age < 15.75) { flags.push('<b class="ids-bad">Younger than 15 years 9 months — too young for a learner’s permit in Maryland.</b>'); } }
			if (diff.length) { flags.push(diff.length + ' value' + (diff.length > 1 ? 's differ' : ' differs') + ' from the registration.'); }
			out.querySelector('.ids-flags').innerHTML = flags.map(function (x) { return '<p>' + x + '</p>'; }).join('');
			S.diff = diff;
			return diff;
		}

		function save() {
			var b = out.querySelector('[data-act="save"]'), f = values(), doc = el.querySelector('.ids-doc').value;
			b.disabled = true; b.textContent = 'Saving…';
			var type = doc === 'permit' ? 'permit' : doc === 'license' ? 'license' : (S.side === 'back' ? 'id_back' : 'id_front');
			var label = (S.side === 'back' ? 'Back (barcode)' : 'Front') + ' — scanned and checked';
			var up = Promise.resolve();
			if (S.img && opts.upload) {
				up = new Promise(function (ok) { S.img.toBlob(function (bl) { opts.upload(new File([bl], 'scan.jpg', { type: 'image/jpeg' }), type, label); ok(); }, 'image/jpeg', 0.9); });
			}
			up.then(function () { return post('save', { email: email, fields: JSON.stringify(f), doc: doc, src: S.src, diff: JSON.stringify(marks()) }); })
				.then(function (d) { b.textContent = 'Saved ✓'; status(d.msg); S.check = { summary: d.summary }; last(); if (opts.saved) { opts.saved(); } })
				.catch(function (e) { b.disabled = false; b.textContent = '✓ Save the scan and the check'; status(e.message, true); });
		}
		function applyIt() {
			var use = [], b = out.querySelector('[data-act="apply"]');
			out.querySelectorAll('[data-use]:checked').forEach(function (u) { use.push(u.getAttribute('data-use')); });
			if (!use.length) { status('Tick the values to copy (the “Use” column).', true); return; }
			b.disabled = true;
			post('apply', { email: email, fields: JSON.stringify(values()), use: JSON.stringify(use) }).then(function (d) {
				status(d.msg); return post('reg', { email: email });
			}).then(function (d) { S.reg = d.reg; b.disabled = false; show(); }).catch(function (e) { b.disabled = false; status(e.message, true); });
		}

		/* Live camera with a card frame; reads the barcode as soon as it is in view. */
		function live() {
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { status('This browser cannot open the camera here. Use “Take photo”.', true); return; }
			var M = document.createElement('div'); M.className = 'ids-live';
			M.innerHTML = '<div class="ids-live-in"><video playsinline muted></video><div class="ids-frame" aria-hidden="true"></div><p class="ids-live-t">Hold the <b>back</b> of the card inside the frame — it is read automatically.</p><div class="ids-live-b"><button type="button" class="sdd-b" data-l="shot">Capture (front side)</button><button type="button" class="sdd-b sdd-b--ghost" data-l="x">Close</button></div></div>';
			document.body.appendChild(M);
			var v = M.querySelector('video'), stream = null, stop = false, Z = null;
			function close() { stop = true; if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); } M.remove(); }
			function frame() {
				var vw = v.videoWidth, vh = v.videoHeight; if (!vw) { return null; }
				var fw = vw * 0.86, fh = fw / 1.586; if (fh > vh * 0.86) { fh = vh * 0.86; fw = fh * 1.586; }
				return crop(canvasFrom(v, 4000), (vw - fw) / 2, (vh - fh) / 2, fw, fh);
			}
			M.addEventListener('click', function (e) {
				var b = e.target.closest('[data-l]'); if (!b) { return; }
				if (b.getAttribute('data-l') === 'x') { close(); return; }
				var c = frame(); close(); if (c) { run(c, null); }
			});
			navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false }).then(function (s) {
				stream = s; v.srcObject = s; return v.play();
			}).then(function () { return zx(); }).then(function (z) {
				Z = z;
				(function loop() {
					if (stop) { return; }
					var c = frame(), t = c ? decode(Z, canvasFrom(c, 1600)) : null;
					if (t) { close(); run(c, t); return; }
					setTimeout(loop, 350);
				})();
			}).catch(function (e) { close(); status(/Permission|NotAllowed/i.test(String(e && (e.name || e.message))) ? 'Camera permission was refused. Allow the camera for this site, or use “Take photo”.' : (e.message || 'The camera could not start.'), true); });
		}
	}

	window.SDSC_IDSCAN = { mount: mount, _aamva: aamva, _front: parseFront, _barcode: barcode, _ocr: ocr, _fix: function (c) { return enhance(autoCrop(c)); } };
})();
