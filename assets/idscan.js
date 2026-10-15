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
	function autoCrop(c, keep) {
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
		if (!keep && out.height > out.width * 1.15) { out = rotate(out, 90); }
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

	/* ------------------------------------------------------------ helpers for the automatic capture */
	/** Small grey copy of a canvas (for motion / sharpness checks). */
	function grey(c, w) {
		var k = w / c.width, h = Math.max(8, Math.round(c.height * k)), t = document.createElement('canvas'); t.width = w; t.height = h;
		var x = t.getContext('2d'); x.drawImage(c, 0, 0, w, h);
		var d = x.getImageData(0, 0, w, h).data, g = new Float32Array(w * h);
		for (var i = 0; i < w * h; i++) { g[i] = d[i * 4] * 0.299 + d[i * 4 + 1] * 0.587 + d[i * 4 + 2] * 0.114; }
		return { g: g, w: w, h: h };
	}
	/** Contrast (std dev), sharpness (variance of the Laplacian) and change from the previous frame. */
	function measure(a, prev) {
		var g = a.g, w = a.w, h = a.h, n = w * h, s = 0, s2 = 0, i, x, y, l = 0, l2 = 0, m = 0, diff = 0;
		for (i = 0; i < n; i++) { s += g[i]; s2 += g[i] * g[i]; }
		for (y = 1; y < h - 1; y++) { for (x = 1; x < w - 1; x++) { i = y * w + x; var v = 4 * g[i] - g[i - 1] - g[i + 1] - g[i - w] - g[i + w]; l += v; l2 += v * v; m++; } }
		if (prev && prev.g.length === n) { for (i = 0; i < n; i += 3) { diff += Math.abs(g[i] - prev.g[i]); } diff /= Math.ceil(n / 3); } else { diff = 99; }
		var mean = s / n;
		return { std: Math.sqrt(Math.max(0, s2 / n - mean * mean)), sharp: l2 / m - (l / m) * (l / m), diff: diff, mean: mean };
	}
	function same(a, b) { if (!a || !b || a.g.length !== b.g.length) { return 99; } var d = 0; for (var i = 0; i < a.g.length; i += 2) { d += Math.abs(a.g[i] - b.g[i]); } return d / Math.ceil(a.g.length / 2); }
	/** Quick barcode look (one size, upright and upside down) — used to tell the back from the front. */
	function quickCode(Z, c) { var b = canvasFrom(c, 1600); return decode(Z, b) || decode(Z, rotate(b, 180)); }
	/** A scanner page with both sides on it: find the two cards (side by side or one above the other). */
	function splitTwo(c) {
		var r = autoCrop(c, true), k = r.width / r.height;
		if (k > 2.3) { var hw = Math.round(r.width / 2); return [crop(r, 0, 0, hw, r.height), crop(r, hw, 0, r.width - hw, r.height)]; }
		if (k < 1.0) { var hh = Math.round(r.height / 2); return [crop(r, 0, 0, r.width, hh), crop(r, 0, hh, r.width, r.height - hh)]; }
		return [c];
	}
	function fix(c) { try { return enhance(autoCrop(canvasFrom(c, 2600))); } catch (e) { return c; } }

	/* ------------------------------------------------------------ finding the card on a scanner page */
	/** Typical tone of the page border (median of a ring 2–7 % in from the edges). */
	function ring(g, w, h) {
		var hist = new Uint32Array(256), n = 0, a = Math.round(Math.min(w, h) * 0.02), b = Math.round(Math.min(w, h) * 0.07), x, y;
		for (y = a; y < h - a; y++) { for (x = a; x < w - a; x++) { if (x < b || y < b || x >= w - b || y >= h - b) { hist[g[y * w + x]]++; n++; } } }
		var acc = 0; for (var i = 0; i < 256; i++) { acc += hist[i]; if (acc >= n / 2) { return i; } }
		return 255;
	}
	/** The card(s) on a scanner page: connected blobs that differ from the white lid. Returns boxes in source pixels. */
	function blobs(c, max) {
		var k = Math.min(1, (max || 320) / Math.max(c.width, c.height)), w = Math.max(8, Math.round(c.width * k)), h = Math.max(8, Math.round(c.height * k));
		var t = document.createElement('canvas'); t.width = w; t.height = h; var x = t.getContext('2d'); x.filter = 'blur(1px)'; x.drawImage(c, 0, 0, w, h); x.filter = 'none';
		var d = x.getImageData(0, 0, w, h).data, g = new Uint8Array(w * h), i, hist = new Uint32Array(256);
		for (i = 0; i < w * h; i++) { var r = d[i * 4], gg = d[i * 4 + 1], b = d[i * 4 + 2], l = (r * 0.299 + gg * 0.587 + b * 0.114) | 0, sat = Math.max(r, gg, b) - Math.min(r, gg, b); g[i] = l; hist[l]++; d[i * 4 + 3] = sat; }
		var bg = ring(g, w, h); // the background (white lid, or dark when the lid is open)
		var m = new Uint8Array(w * h), edge = Math.max(1, Math.round(Math.min(w, h) * 0.012));
		for (var yy = edge; yy < h - edge; yy++) { for (var xx = edge; xx < w - edge; xx++) { i = yy * w + xx; var gr = Math.abs(g[i + 1] - g[i - 1]) + Math.abs(g[i + w] - g[i - w]); if (Math.abs(bg - g[i]) > 8 || d[i * 4 + 3] > 18 || gr > 9) { m[i] = 1; } } }
		// close small gaps (white parts of the card) with a 2-pixel dilation
		var m2 = new Uint8Array(w * h);
		for (yy = 3; yy < h - 3; yy++) { for (xx = 3; xx < w - 3; xx++) { if (m[yy * w + xx]) { for (var dy = -3; dy <= 3; dy++) { for (var dx = -3; dx <= 3; dx++) { m2[(yy + dy) * w + xx + dx] = 1; } } } } }
		var seen = new Int32Array(w * h), out = [], id = 0, st = [];
		for (i = 0; i < w * h; i++) {
			if (!m2[i] || seen[i]) { continue; }
			id++; var x0 = w, y0 = h, x1 = 0, y1 = 0, n = 0; st.push(i); seen[i] = id;
			while (st.length) { var j = st.pop(), jx = j % w, jy = (j / w) | 0; n++; if (jx < x0) { x0 = jx; } if (jx > x1) { x1 = jx; } if (jy < y0) { y0 = jy; } if (jy > y1) { y1 = jy; }
				[j - 1, j + 1, j - w, j + w].forEach(function (q) { if (q >= 0 && q < w * h && m2[q] && !seen[q] && Math.abs((q % w) - jx) <= 1) { seen[q] = id; st.push(q); } }); }
			if (n > w * h * 0.004) { out.push({ x: x0 / k, y: y0 / k, w: (x1 - x0 + 1) / k, h: (y1 - y0 + 1) / k, n: n / (k * k) }); }
		}
		return out.sort(function (a, b) { return b.w * b.h - a.w * a.h; }); // a card outline encloses its printing
	}
	/** The ID card among the blobs (about 3.4 × 2.1 in, any rotation); dpi = pixels per inch of the page. */
	function cardOn(c, dpi) {
		var list = blobs(c), best = null;
		list.forEach(function (b) { var lo = Math.min(b.w, b.h) / dpi, hi = Math.max(b.w, b.h) / dpi; if (lo > 1.3 && lo < 4.2 && hi > 2.4 && hi < 6.5 && (!best || b.w * b.h > best.w * best.h)) { best = b; } });
		return best || list[0] || null;
	}
	/** Straighten a card that lies crooked on the glass and cut it out tightly. */
	function deskewCrop(c) {
		var k = Math.min(1, 700 / Math.max(c.width, c.height)), w = Math.round(c.width * k), h = Math.round(c.height * k);
		var t = document.createElement('canvas'); t.width = w; t.height = h; var tx = t.getContext('2d'); tx.filter = 'blur(1px)'; tx.drawImage(c, 0, 0, w, h); tx.filter = 'none';
		var b = blobs(t, 700)[0]; if (!b) { return c; }
		var d = t.getContext('2d').getImageData(0, 0, w, h).data, hist = new Uint32Array(256), i, pts = [];
		for (i = 0; i < w * h; i++) { hist[(d[i * 4] * 0.299 + d[i * 4 + 1] * 0.587 + d[i * 4 + 2] * 0.114) | 0]++; }
		var gg2 = new Uint8Array(w * h); for (i = 0; i < w * h; i++) { gg2[i] = (d[i * 4] * 0.299 + d[i * 4 + 1] * 0.587 + d[i * 4 + 2] * 0.114) | 0; } var bg = ring(gg2, w, h);
		var L = function (q) { return d[q * 4] * 0.299 + d[q * 4 + 1] * 0.587 + d[q * 4 + 2] * 0.114; };
		for (var y = Math.max(1, Math.floor(b.y)); y < Math.min(h - 1, b.y + b.h); y += 1) { for (var x = Math.max(1, Math.floor(b.x)); x < Math.min(w - 1, b.x + b.w); x += 1) { i = (y * w + x) * 4; var q = y * w + x, l = L(q), sat = Math.max(d[i], d[i + 1], d[i + 2]) - Math.min(d[i], d[i + 1], d[i + 2]), gr = Math.abs(L(q + 1) - L(q - 1)) + Math.abs(L(q + w) - L(q - w)); if (Math.abs(bg - l) > 8 || sat > 18 || gr > 9) { pts.push(x, y); } } }
		if (pts.length < 200) { return c; }
		var best = 0, area = Infinity, box = null;
		for (var a = -12; a <= 12; a += 0.5) {
			var r = a * Math.PI / 180, cs = Math.cos(r), sn = Math.sin(r), mx = 1e9, my = 1e9, Mx = -1e9, My = -1e9;
			for (i = 0; i < pts.length; i += 2) { var px = pts[i] * cs - pts[i + 1] * sn, py = pts[i] * sn + pts[i + 1] * cs; if (px < mx) { mx = px; } if (px > Mx) { Mx = px; } if (py < my) { my = py; } if (py > My) { My = py; } }
			var ar = (Mx - mx) * (My - my); if (ar < area - 0.5) { area = ar; best = a; box = [mx, my, Mx, My]; }
		}
		// Rotate the full picture by the same angle (around the origin, then shift) and cut the box out.
		var R = best * Math.PI / 180, s1 = 1 / k, pad = 0.005 * Math.max(box[2] - box[0], box[3] - box[1]);
		var bx = (box[0] - pad) * s1, by = (box[1] - pad) * s1, bw = (box[2] - box[0] + 2 * pad) * s1, bh = (box[3] - box[1] + 2 * pad) * s1;
		var o = document.createElement('canvas'); o.width = Math.round(bw); o.height = Math.round(bh);
		var ox = o.getContext('2d'); ox.fillStyle = '#fff'; ox.fillRect(0, 0, o.width, o.height);
		ox.translate(-bx, -by); ox.rotate(R); ox.drawImage(c, 0, 0);
		return o.height > o.width * 1.15 ? rotate(o, 90) : o;
	}
	function fixScan(c) { try { return enhance(deskewCrop(c)); } catch (e) { return fix(c); } }
	function blobOf(c) { return new Promise(function (ok) { c.toBlob(function (b) { ok(b); }, 'image/jpeg', 0.9); }); }
	function beep() { try { var A = window.AudioContext || window.webkitAudioContext, a = new A(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 1200; g.gain.value = 0.08; o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + 0.09); setTimeout(function () { a.close(); }, 300); } catch (e) {} if (navigator.vibrate) { navigator.vibrate(60); } }
	var DOCN = { permit: 'Learner’s permit', license: 'Driver’s license', id: 'ID card' };

	/* ------------------------------------------------------------ the panel */
	function mount(el, email, opts) {
		opts = opts || {};
		var S = { reg: null, check: null, fields: null, src: '', front: null, back: null, code: null, saved: false };
		el.innerHTML = '<div class="ids-card"><div class="ids-head"><span class="ids-ic" aria-hidden="true">🪪</span><div><h4>Scan ID, learner’s permit or license — both sides</h4><p>Hold the card in the frame: the <b>front</b> is taken by itself as soon as it is still and sharp, then turn the card over and the <b>back</b> is taken when its barcode is read. Both pictures are cropped, saved to the student’s documents and compared with the registration — nothing else to do.</p></div></div>' +
			'<div class="ids-bar"><label>Document <select class="ids-doc"><option value="permit">Learner’s permit</option><option value="license"' + (opts.staff ? ' selected' : '') + '>Driver’s license</option><option value="id">ID card</option></select></label>' +
			'<button type="button" class="sdd-b" data-ids="live">📷 Camera — both sides</button><button type="button" class="sdd-b" data-ids="scanner">🖨 Office scanner</button><button type="button" class="sdd-b sdd-b--ghost" data-ids="photo">📸 Take photos</button><button type="button" class="sdd-b sdd-b--ghost" data-ids="file">⬆ Files</button></div>' +
			'<ol class="ids-steps"><li data-s="front">Front</li><li data-s="back">Back</li><li data-s="read">Read</li><li data-s="saved">Saved</li></ol>' +
			'<p class="ids-status" role="status" aria-live="polite"></p><div class="ids-out"></div><p class="ids-last"></p></div>';
		var st = el.querySelector('.ids-status'), out = el.querySelector('.ids-out');
		function status(t, bad) { st.textContent = t || ''; st.className = 'ids-status' + (bad ? ' is-bad' : ''); }
		function step(k, on) { var li = el.querySelector('[data-s="' + k + '"]'); if (li) { li.classList.toggle('is-done', !!on); } }
		function steps() { step('front', S.front); step('back', S.back); step('read', S.fields); step('saved', S.saved); }
		function docv() { return el.querySelector('.ids-doc').value; }
		function last() { el.querySelector('.ids-last').textContent = S.check && S.check.summary ? 'Last check: ' + S.check.summary : ''; }
		post('reg', { email: email }).then(function (d) { S.reg = d.reg; S.check = d.check; last(); if (S.fields) { show(); } }).catch(function () {});

		function reset() { S.front = S.back = S.code = S.fields = null; S.saved = false; S.imgsSaved = false; S.spot = null; S.src = ''; out.innerHTML = ''; steps(); }

		el.querySelector('.ids-bar').addEventListener('click', function (e) {
			var b = e.target.closest('[data-ids]'); if (!b) { return; }
			var k = b.getAttribute('data-ids');
			reset();
			if (k === 'live') { live(); return; }
			if (k === 'scanner') { office(); return; }
			if (k === 'photo') { photo('front'); return; }
			var i = document.createElement('input'); i.type = 'file'; i.accept = 'image/*'; i.multiple = true;
			i.addEventListener('change', function () { files(Array.prototype.slice.call(i.files || [])); }); i.click();
		});

		function open(file) {
			return new Promise(function (ok, no) {
				if (!/^image\//.test(file.type)) { no(new Error('Please choose photos or scanner images (JPG or PNG). For a PDF from the scanner, save it as JPG.')); return; }
				var u = URL.createObjectURL(file), im = new Image();
				im.onload = function () { var c = canvasFrom(im, 3000); URL.revokeObjectURL(u); ok(c); };
				im.onerror = function () { no(new Error('This picture could not be opened (iPhone photos: set the camera to “Most compatible”, or use Scan both sides).')); };
				im.src = u;
			});
		}
		/** Put a picture where it belongs: with a barcode it is the back, otherwise the front. */
		/** Put a picture in its place: the side that was asked for — unless its barcode shows it is the back. */
		function place(c, scanned, want) {
			return zx().then(function (Z) {
				var f = scanned ? fixScan(c) : fix(c), t = quickCode(Z, f);
				var side = t ? 'back' : (want || (!S.front ? 'front' : 'back'));
				if (side === 'back') { S.back = f; S.code = t || null; } else { S.front = f; }
				S.imgsSaved = false;
				steps();
				return side;
			});
		}
		/* Scanner: one page with both sides, or two files. */
		function files(list) {
			if (!list.length) { return; }
			status('Opening the scan…');
			list.slice(0, 2).reduce(function (p, f) { return p.then(function () { return open(f); }).then(function (c) { var parts = list.length === 1 ? splitTwo(c) : [c]; return parts.reduce(function (q, x) { return q.then(function () { return place(x, true); }); }, Promise.resolve()); }); }, Promise.resolve())
				.then(function () {
					if (!S.front && !S.back) { status('No card was found on this scan.', true); return; }
					finish();
				}).catch(function (e) { status(e.message, true); });
		}
		/* Phone camera app: front, then back (each photo needs one tap — the browser asks for it). */
		function photo(side) {
			var i = document.createElement('input'); i.type = 'file'; i.accept = 'image/*'; i.setAttribute('capture', 'environment');
			i.addEventListener('change', function () {
				if (!i.files[0]) { return; }
				status('Checking the picture…');
				S.method = 'photo';
				open(i.files[0]).then(function (c) { return place(c, false, side); }).then(function () {
					if (S.front && S.back) { finish(); return; }
					var need = S.front ? 'back' : 'front';
					out.innerHTML = '<div class="ids-next ids-ask"><p class="ids-big">' + (need === 'back' ? '②' : '①') + ' Now the <b>' + SIDE[need] + '</b></p><p class="ids-m2">✓ The ' + (need === 'back' ? 'front' : 'back') + ' is done.</p><button type="button" class="sdd-b">📸 Take the ' + need + '</button> <button type="button" class="sdd-b sdd-b--ghost" data-skip="1">Skip — only the ' + (need === 'back' ? 'front' : 'back') + ' is needed</button></div>';
					status('');
					out.querySelector('.sdd-b:not([data-skip])').addEventListener('click', function () { photo(need); });
					out.querySelector('[data-skip]').addEventListener('click', finish);
				}).catch(function (e) { status(e.message, true); });
			});
			i.click();
		}

		/* Both sides in hand: read, compare, save — all at once. */
		function finish() {
			out.innerHTML = '';
			status('Reading the card…');
			var back = S.back, front = S.front;
			(S.code ? Promise.resolve(S.code) : (back ? barcode(back) : Promise.resolve(null))).then(function (text) {
				if (!text && front) { return barcode(front).then(function (t2) { if (t2) { var x = S.front; S.front = S.back; S.back = x; } return t2; }); }
				return text;
			}).then(function (text) {
				if (text) { S.fields = aamva(text); S.src = 'barcode'; return; }
				var o = S.front || S.back;
				status('No barcode found — reading the printed text…');
				return ocr(o, status).then(function (d) { S.fields = parseFront(d.text); S.src = 'ocr'; });
			}).then(function () {
				steps(); show();
				if (!Object.keys(S.fields || {}).length) { saveImgs().then(function () { status('Pictures saved to documents. The text could not be read — type the values below and press Save, or scan again.', true); }); return; }
				return save(true);
			}).catch(function (e) { status(e.message, true); });
		}

		function show() {
			var f = S.fields || {}, r = S.reg || {}, apply = +C.apply && S.reg;
			var regv = function (k) { return k === 'dob' ? r.dob : r[k]; };
			var fig = function (k, lab) { return S[k] ? '<figure class="ids-img" data-i="' + k + '"><figcaption>' + lab + ' <button type="button" class="ids-redo" data-redo="' + k + '">↻ Scan again</button></figcaption></figure>' : '<div class="ids-miss"><span>' + lab + ' — not scanned</span><button type="button" class="sdd-b sdd-b--ghost" data-redo="' + k + '">+ Scan the ' + k + '</button></div>'; };
			var html = '<div class="ids-res"><div class="ids-imgs">' + fig('front', 'Front') + fig('back', 'Back') + '</div><div class="ids-tab"><table><thead><tr><th>Field</th><th>On the card' + (S.src === 'ocr' ? ' <small>(read from text — check)</small>' : ' <small>(barcode)</small>') + '</th><th>On the registration' + (r.file ? ' <small>' + esc(r.file) + '</small>' : '') + '</th><th></th>' + (apply ? '<th title="Copy into the registration">Use</th>' : '') + '</tr></thead><tbody>';
			ROWS.forEach(function (row) {
				var k = row[0], cv = f[k] || '', rv = ONREG[k] ? (regv(k) || '') : null;
				html += '<tr data-k="' + k + '"><th>' + esc(row[1]) + '</th><td><input value="' + esc(k === 'dob' || k === 'issued' || k === 'expires' ? us(cv) : cv) + '" data-f="' + k + '" aria-label="' + esc(row[1]) + ' on the card"></td><td>' + (rv === null ? '<span class="ids-na">—</span>' : esc(k === 'dob' ? us(rv) : rv) || '<span class="ids-na">empty</span>') + '</td><td class="ids-m"></td>' + (apply ? '<td>' + (ONREG[k] ? '<input type="checkbox" data-use="' + k + '" aria-label="Use the card value for ' + esc(row[1]) + '">' : '') + '</td>' : '') + '</tr>';
			});
			html += '</tbody></table><div class="ids-flags"></div><div class="ids-acts"><button type="button" class="sdd-b sdd-b--ghost" data-act="save" hidden>Save my corrections</button>' + (apply ? '<button type="button" class="sdd-b" data-act="apply">Copy ticked values into the registration</button>' : '') + '</div>' +
				(!S.reg ? '<p class="ids-na">' + (opts.staff ? 'Instructor file: the license is kept on file — check the expiry date above.' : 'No registration form for this student, so there is nothing to compare with.') + '</p>' : '') + '</div></div>';
			out.innerHTML = html;
			[['front', S.front], ['back', S.back]].forEach(function (p) { if (!p[1]) { return; } var im = new Image(); im.alt = 'Card ' + p[0]; im.src = p[1].toDataURL('image/jpeg', 0.85); out.querySelector('[data-i="' + p[0] + '"]').prepend(im); });
			out.querySelectorAll('[data-f]').forEach(function (i) { i.addEventListener('input', function () { marks(); out.querySelector('[data-act="save"]').hidden = false; }); });
			marks();
			out.querySelector('[data-act="save"]').addEventListener('click', function () { save(false); });
			out.querySelectorAll('[data-redo]').forEach(function (b) { b.addEventListener('click', function () { redo(b.getAttribute('data-redo')); }); });
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
				if (use && !use.dataset.touched) { use.checked = true; }
			});
			out.querySelectorAll('[data-use]').forEach(function (u) { u.onchange = function () { u.dataset.touched = '1'; }; });
			if (f.expires && f.expires < today()) { flags.push('<b class="ids-bad">This document expired on ' + esc(us(f.expires)) + '.</b>'); }
			if (f.dob) { var age = (new Date(today()) - new Date(f.dob)) / 31557600000; if (age < 15.75) { flags.push('<b class="ids-bad">Younger than 15 years 9 months — too young for a learner’s permit in Maryland.</b>'); } }
			if (diff.length) { flags.push(diff.length + ' value' + (diff.length > 1 ? 's differ' : ' differs') + ' from the registration — the card values are ticked; one tap copies them.'); }
			var fl = out.querySelector('.ids-flags'); if (fl) { fl.innerHTML = flags.map(function (x) { return '<p>' + x + '</p>'; }).join(''); }
			S.diff = diff;
			return diff;
		}

		/** Save: both pictures into the student's documents (once) and the check. */
		/** Both pictures into the student's documents — once per scan. */
		function saveImgs() {
			if (S.imgsSaved || !opts.upload) { return Promise.resolve(); }
			S.imgsSaved = true;
			var name = DOCN[docv()] || 'ID', ups = [];
			[['front', S.front, 'id_front'], ['back', S.back, 'id_back']].forEach(function (p) {
				if (!p[1]) { return; }
				ups.push(blobOf(p[1]).then(function (bl) { return opts.upload(new File([bl], name.replace(/\W+/g, '-').toLowerCase() + '-' + p[0] + '.jpg', { type: 'image/jpeg' }), p[2], name + ' — ' + p[0] + (p[0] === 'back' && S.src === 'barcode' ? ' (barcode read)' : '') + ' · scanned'); }));
			});
			return Promise.all(ups);
		}
		function save(first) {
			var f = values(), doc = docv();
			status(first ? 'Saving the pictures and the check…' : 'Saving…');
			var ups = [saveImgs()];
			return Promise.all(ups).then(function () { return post('save', { email: email, fields: JSON.stringify(f), doc: doc, src: S.src, diff: JSON.stringify(marks()) }); })
				.then(function (d) {
					S.saved = true; steps();
					var sb = out.querySelector('[data-act="save"]'); if (sb) { sb.hidden = true; }
					status((first ? (S.front && S.back ? 'Front and back saved to documents · ' : 'Picture saved to documents · ') : '') + d.msg);
					S.check = { summary: d.summary }; last(); if (opts.saved) { opts.saved(); }
				})
				.catch(function (e) { status(e.message, true); });
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

		/* Office scanner (Brother on the network) through the SDS Scan program on this computer. */
		var BR = 'http://127.0.0.1:52700';
		function bridge(path, o) {
			o = o || {};
			var ctl = window.AbortController ? new AbortController() : null, t = ctl ? setTimeout(function () { ctl.abort(); }, o.timeout || 2500) : null;
			return fetch(BR + path, { method: o.method || 'GET', signal: ctl ? ctl.signal : undefined, targetAddressSpace: 'loopback' }).then(function (r) { if (t) { clearTimeout(t); } return r; });
		}
		var NEED = [1, 1, 0];
		function older(v) { var a = String(v || '0').split('.').map(Number); for (var i = 0; i < 3; i++) { if ((a[i] || 0) !== NEED[i]) { return (a[i] || 0) < NEED[i]; } } return false; }
		function office() {
			out.innerHTML = '';
			status('Connecting to the office scanner…');
			bridge('/status', { timeout: 20000 }).then(function (r) { return r.json(); }).then(function (d) {
				if (older(d.version)) { setup('update'); return; }
				if (!d.scanner) { setup('found'); return; }
				scanSide('front', d.scanner.model);
			}).catch(function () { setup('app'); });
		}
		function setup(why) {
			var mac = /Mac/.test(navigator.platform || navigator.userAgent), dl = mac ? C.scanMac : C.scanWin;
			status('');
			out.innerHTML = why === 'app' || why === 'update'
				? '<div class="ids-next ids-setup"><p>🖨 ' + (why === 'update' ? 'A faster version of SDS Scan is ready — please install it once more' : 'One-time setup on this computer') + '</p><ol><li><a class="sdd-b" href="' + esc(dl) + '" download>⬇ Download SDS Scan for ' + (mac ? 'Mac' : 'Windows') + '</a> <a href="' + esc(mac ? C.scanWin : C.scanMac) + '" download>(' + (mac ? 'Windows' : 'Mac') + ' version)</a></li><li>Open the downloaded file and run <b>Install SDS Scan</b>' + (mac ? ' (right-click → Open the first time)' : ' (if Windows warns: More info → Run anyway; firewall: Allow)') + '.' + (why === 'update' ? ' It replaces the old one.' : '') + '</li><li>If the browser asks to let this site use devices on your local network, choose <b>Allow</b>.</li></ol><button type="button" class="sdd-b" data-again>↻ Check again</button></div>'
				: '<div class="ids-next ids-setup"><p>SDS Scan is running, but the Brother scanner was not found on the network.</p><p class="ids-m2">Check that the Brother is on and on the office Wi-Fi. Or type its IP address (on the Brother: Menu → Network → WLAN → TCP/IP → IP Address):</p><input class="ids-ip" placeholder="192.168.1.40" inputmode="decimal"> <button type="button" class="sdd-b" data-ip>Use this address</button> <button type="button" class="sdd-b sdd-b--ghost" data-again>↻ Search again</button></div>';
			out.querySelector('[data-again]').addEventListener('click', function () { if (why === 'found') { status('Searching the network…'); bridge('/status?find=1', { timeout: 60000 }).then(function (r) { return r.json(); }).then(function (d) { if (d.scanner) { scanSide('front', d.scanner.model); } else { setup('found'); status('Still not found.', true); } }).catch(function () { setup('app'); }); } else { office(); } });
			var ip = out.querySelector('[data-ip]');
			if (ip) { ip.addEventListener('click', function () { var v = out.querySelector('.ids-ip').value.trim(); status('Checking ' + v + '…'); bridge('/config?ip=' + encodeURIComponent(v), { method: 'POST', timeout: 15000 }).then(function (r) { return r.json(); }).then(function (d) { if (d.ok) { scanSide('front', d.scanner.model); } else { status(d.msg, true); } }).catch(function () { status('SDS Scan did not answer.', true); }); }); }
		}
		/** One picture from the scanner; region in inches from the scanner's corner. */
		function grab(q) {
			return bridge('/scan?' + q, { method: 'POST', timeout: 120000 }).then(function (r) {
				if (!r.ok) { return r.json().then(function (j) { throw new Error(j.msg || 'Scan failed.'); }); }
				return r.blob();
			}).then(function (b) { return open(new File([b], 'scan.jpg', { type: 'image/jpeg' })); });
		}
		function r2(n) { return Math.max(0, Math.round(n * 100) / 100); }
		/** Quick low-resolution look at the whole glass to find where the card lies. */
		function locate() {
			status('Finding the card on the glass…');
			return grab('dpi=75&gray=1').then(function (c) {
				var b = cardOn(c, 75);
				if (!b) { throw new Error('No card was found on the glass. Put it face down and close the lid.'); }
				var r = { x: b.x / 75, y: b.y / 75, w: b.w / 75, h: b.h / 75 };
				if (Math.max(r.w, r.h) < 3.1) { var cx = r.x + r.w / 2, cy = r.y + r.h / 2; r = { x: cx - 1.95, y: cy - 1.95, w: 3.9, h: 3.9 }; } // only part of a white card was seen
				return r;
			});
		}
		/** Sharp colour picture of just that part of the glass (fast), straightened and cut out. */
		function sharp(box, m) {
			status('Scanning the card…');
			var x = r2(box.x - m), y = r2(box.y - m);
			return grab('dpi=300&x=' + x + '&y=' + y + '&w=' + r2(box.w + 2 * m) + '&h=' + r2(box.h + 2 * m)).then(function (c) {
				var b = cardOn(c, 300), e = 6;
				var inside = b && b.x > e && b.y > e && b.x + b.w < c.width - e && b.y + b.h < c.height - e;
				return { c: c, inside: inside, box: b ? { x: x + b.x / 300, y: y + b.y / 300, w: b.w / 300, h: b.h / 300 } : box };
			});
		}
		var SIDE = { front: 'FRONT of the card — the side with the photo', back: 'BACK of the card — the side with the barcode' };
		function scanSide(side, model, manual) {
			S.method = 'office'; S.model = model;
			var other = side === 'front' ? 'back' : 'front', have = S[other];
			var n = side === 'front' && !have ? 2 : 6, tmr = null;
			out.innerHTML = '<div class="ids-next ids-ask"><p class="ids-big">' + (side === 'front' ? '①' : '②') + ' Put the <b>' + SIDE[side] + '</b> face down on the glass</p>' +
				'<p class="ids-m2">' + (have ? '✓ The ' + other + ' is done. Turn the card over in the same place and close the lid.' : 'Anywhere on the glass, then close the lid.') + '</p>' +
				'<button type="button" class="sdd-b" data-go>🖨 Scan the ' + side + ' now</button> ' + (have ? '<button type="button" class="sdd-b sdd-b--ghost" data-skip>Skip — only the ' + other + ' is needed</button> ' : '') + '<button type="button" class="sdd-b sdd-b--ghost" data-x>Cancel</button> <span class="ids-cd"></span></div>';
			var cd = out.querySelector('.ids-cd');
			function go() {
				if (tmr) { clearInterval(tmr); tmr = null; }
				out.innerHTML = '<div class="ids-next"><p>Scanning the ' + side + '… <span class="ids-spin"></span></p></div>';
				var t0 = Date.now();
				// After the first side the card lies in a known spot: scan just there, with room for turning it over.
				var hint = S.spot || null;
				(hint ? sharp(hint, 0.6).then(function (r) { return r.inside ? r : locate().then(function (b) { return sharp(b, 0.35); }); })
					: locate().then(function (b) { return sharp(b, 0.35); })
				).then(function (r) {
					if (!r.inside) { return sharp(r.box, 0.7); } // the card touched the edge of the scan: once more with more room
					return r;
				}).then(function (r) {
					S.spot = r.box;
					return place(r.c, true, side);
				}).then(function (got) {
					beep();
					var note = got !== side ? 'That was the ' + got + ' (its barcode was read) — fine. ' : '';
					if (S.front && S.back) { finish(); return; }
					status('✓ ' + (got === 'front' ? 'Front' : 'Back') + ' scanned in ' + Math.round((Date.now() - t0) / 1000) + ' s. ' + note);
					scanSide(S.front ? 'back' : 'front', model);
				}).catch(function (e) { scanSide(side, model, true); status(e.message || 'Scan failed.', true); });
			}
			out.querySelector('[data-go]').addEventListener('click', go);
			var sk = out.querySelector('[data-skip]'); if (sk) { sk.addEventListener('click', function () { if (tmr) { clearInterval(tmr); } finish(); }); }
			out.querySelector('[data-x]').addEventListener('click', function () { if (tmr) { clearInterval(tmr); } out.innerHTML = ''; status('Scan cancelled.'); reset(); });
			if (manual) { return; } // after a problem: wait for "Scan now"
			tmr = setInterval(function () { n--; cd.textContent = n > 0 ? 'Scanning by itself in ' + n + '…' : ''; if (n <= 0) { go(); } }, 1000);
			cd.textContent = 'Scanning by itself in ' + n + '…';
		}
		/** Scan one side again (from the result screen). */
		function redo(side) {
			S.fields = null; S.saved = false; steps();
			if (S.method === 'office') { scanSide(side, S.model); return; }
			photo(side);
		}

		/* Live camera: front taken by itself when the card is still and sharp; back when its barcode is read. */
		function live() {
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { status('This browser cannot open the camera here. Use “Take photos”.', true); return; }
			var M = document.createElement('div'); M.className = 'ids-live';
			M.innerHTML = '<div class="ids-live-in"><div class="ids-vw"><video playsinline muted></video><div class="ids-frame" aria-hidden="true"><i></i></div><div class="ids-flash"></div></div>' +
				'<div class="ids-live-s"><span data-t="front">1 Front</span><span data-t="back">2 Back</span></div><p class="ids-live-t">Front of the card inside the frame — hold still.</p>' +
				'<div class="ids-live-b"><button type="button" class="sdd-b" data-l="shot">Take it now</button><button type="button" class="sdd-b sdd-b--ghost" data-l="skip" hidden>Skip — one side is enough</button><button type="button" class="sdd-b sdd-b--ghost" data-l="x">Cancel</button></div></div>';
			document.body.appendChild(M);
			var v = M.querySelector('video'), fr = M.querySelector('.ids-frame'), tip = M.querySelector('.ids-live-t'), stream = null, stop = false, Z = null;
			var want = 'front', prev = null, still = 0, peak = 0, lastShot = null, moved = true, since = Date.now(), lastTry = 0, busy = false;
			function close() { stop = true; if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); } M.remove(); }
			function box() { var vw = v.videoWidth, vh = v.videoHeight, fw = vw * 0.86, fh = fw / 1.586; if (fh > vh * 0.8) { fh = vh * 0.8; fw = fh * 1.586; } return { x: (vw - fw) / 2, y: (vh - fh) / 2, w: fw, h: fh, vw: vw, vh: vh }; }
			function place2() { var b = box(); if (!b.vw) { return; } fr.style.left = (b.x / b.vw * 100) + '%'; fr.style.top = (b.y / b.vh * 100) + '%'; fr.style.width = (b.w / b.vw * 100) + '%'; fr.style.height = (b.h / b.vh * 100) + '%'; }
			function frame() { var b = box(); if (!b.vw) { return null; } var c = document.createElement('canvas'); c.width = Math.round(b.w); c.height = Math.round(b.h); c.getContext('2d').drawImage(v, b.x, b.y, b.w, b.h, 0, 0, c.width, c.height); return c; }
			function mark() { M.querySelectorAll('[data-t]').forEach(function (s) { var t = s.getAttribute('data-t'); s.className = (t === 'front' ? S.front : S.back) ? 'is-done' : (t === want ? 'is-now' : ''); }); }
			function shot(c, code) {
				beep(); var fl = M.querySelector('.ids-flash'); fl.classList.add('on'); setTimeout(function () { fl.classList.remove('on'); }, 180);
				var f = fix(c);
				if (!code && Z) { code = quickCode(Z, f); }
				if (code) { S.back = f; S.code = code; } else if (want === 'back' && S.front) { S.back = f; } else { S.front = f; }
				lastShot = grey(c, 160); moved = false; still = 0; peak = 0; since = Date.now();
				steps();
				if (S.front && S.back) { close(); finish(); return; }
				want = S.front ? 'back' : 'front'; mark();
				M.querySelector('[data-l="skip"]').hidden = false;
				tip.innerHTML = want === 'back' ? '✓ Front taken. <b>Turn the card over</b> — the back is taken when the barcode is read.' : '✓ Back read. Now the <b>front</b> inside the frame — hold still.';
			}
			M.addEventListener('click', function (e) {
				var b = e.target.closest('[data-l]'); if (!b) { return; }
				if (b.getAttribute('data-l') === 'x') { close(); status('Scan cancelled.'); reset(); return; }
				if (b.getAttribute('data-l') === 'skip') { close(); finish(); return; }
				var c = frame(); if (c) { shot(c, null); }
			});
			navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 2560 }, height: { ideal: 1440 } }, audio: false }).then(function (s) {
				stream = s; v.srcObject = s;
				try { var tr = s.getVideoTracks()[0], cap = tr.getCapabilities ? tr.getCapabilities() : {}; if (cap.focusMode && cap.focusMode.indexOf('continuous') > -1) { tr.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }); } } catch (e) {}
				return v.play();
			}).then(function () { place2(); window.addEventListener('resize', place2); mark(); return zx(); }).then(function (z) {
				Z = z;
				(function loop() {
					if (stop) { return; }
					if (busy) { setTimeout(loop, 120); return; }
					var c = frame(); if (!c) { setTimeout(loop, 150); return; }
					var g = grey(c, 160), m = measure(g, prev); prev = g;
					peak = Math.max(peak * 0.985, m.sharp);
					// After a picture, wait until the card was moved / turned before taking the next one.
					if (!moved) { if (same(g, lastShot) > 14) { moved = true; still = 0; } setTimeout(loop, 150); return; }
					var ok = m.std > 22 && m.mean > 35 && m.mean < 235 && m.sharp > Math.max(25, peak * 0.7);
					still = ok && m.diff < 4.5 ? still + 1 : 0;
					fr.classList.toggle('is-ok', still > 1);
					var now = Date.now();
					// Look for the barcode (any side): if found, that picture is the back.
					if (now - lastTry > 300 && m.diff < 8) {
						lastTry = now; busy = true;
						var t = decode(Z, canvasFrom(c, 1600)); busy = false;
						if (t && !S.back) { shot(c, t); setTimeout(loop, 150); return; }
					}
					if (want === 'front' && still >= 5) { shot(c, null); }
					else if (want === 'back' && still >= 5 && now - since > 3500) { shot(c, null); } // no barcode on this card: take the back when still
					setTimeout(loop, 120);
				})();
			}).catch(function (e) { close(); status(/Permission|NotAllowed/i.test(String(e && (e.name || e.message))) ? 'Camera permission was refused. Allow the camera for this site, or use “Take photos”.' : (e.message || 'The camera could not start.'), true); });
		}
	}

	window.SDSC_IDSCAN = { mount: mount, _aamva: aamva, _front: parseFront, _barcode: barcode, _ocr: ocr, _fix: function (c) { return enhance(autoCrop(c)); }, _blobs: blobs, _card: cardOn, _deskew: deskewCrop };
})();
