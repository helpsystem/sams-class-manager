/* Sam's Driving School — phone / tablet document scanner.
   Live camera → finds the page or card edges → takes the picture by itself when the phone is still →
   straightens it (perspective) → cleans it (shadows out, white paper, sharp text) → one picture or one PDF.
   Everything happens on the device; nothing leaves it until the office presses Save.
   window.SDSC_CAMSCAN.open({ kind: 'card'|'doc', multi: bool, title, onDone: function (file, thumb) → Promise }) */
(function () {
	'use strict';
	var W = window, D = document;

	/* =================================================================== image work (also runs in a worker) */
	function PROC() {
		function clamp(v) { return v < 0 ? 0 : (v > 255 ? 255 : v); }
		/** Straighten: H maps output pixel (x, y) to source (u, v). Bilinear sampling, 2×2 when shrinking a lot. */
		function warp(m) {
			var s = new Uint8ClampedArray(m.buf), sw = m.sw, sh = m.sh, H = m.H, ow = m.ow, oh = m.oh;
			var o = new Uint8ClampedArray(ow * oh * 4), ss = m.ss > 1 ? 2 : 1, inv = 1 / (ss * ss), x, y, j, q, i = 0;
			for (y = 0; y < oh; y++) {
				for (x = 0; x < ow; x++) {
					var r = 0, g = 0, b = 0;
					for (j = 0; j < ss; j++) {
						for (q = 0; q < ss; q++) {
							var px = x + (ss === 1 ? 0.5 : 0.25 + q * 0.5), py = y + (ss === 1 ? 0.5 : 0.25 + j * 0.5);
							var dn = H[6] * px + H[7] * py + H[8], u = (H[0] * px + H[1] * py + H[2]) / dn - 0.5, v = (H[3] * px + H[4] * py + H[5]) / dn - 0.5;
							if (u < 0) { u = 0; } if (v < 0) { v = 0; } if (u > sw - 1.001) { u = sw - 1.001; } if (v > sh - 1.001) { v = sh - 1.001; }
							var x0 = u | 0, y0 = v | 0, fx = u - x0, fy = v - y0, p = (y0 * sw + x0) * 4, p2 = p + sw * 4;
							var w00 = (1 - fx) * (1 - fy), w10 = fx * (1 - fy), w01 = (1 - fx) * fy, w11 = fx * fy;
							r += s[p] * w00 + s[p + 4] * w10 + s[p2] * w01 + s[p2 + 4] * w11;
							g += s[p + 1] * w00 + s[p + 5] * w10 + s[p2 + 1] * w01 + s[p2 + 5] * w11;
							b += s[p + 2] * w00 + s[p + 6] * w10 + s[p2 + 2] * w01 + s[p2 + 6] * w11;
						}
					}
					o[i] = r * inv; o[i + 1] = g * inv; o[i + 2] = b * inv; o[i + 3] = 255; i += 4;
				}
			}
			return { buf: o.buffer, w: ow, h: oh };
		}
		/** Paper brightness across the page (so shadows and uneven light can be taken out). */
		function background(a, w, h) {
			var B = Math.max(16, Math.round(Math.max(w, h) / 36)), gw = Math.ceil(w / B), gh = Math.ceil(h / B), g = new Float32Array(gw * gh), hist = new Uint32Array(64), bx, by, x, y;
			for (by = 0; by < gh; by++) {
				for (bx = 0; bx < gw; bx++) {
					hist.fill(0); var n = 0;
					for (y = by * B; y < Math.min(h, by * B + B); y += 2) { for (x = bx * B; x < Math.min(w, bx * B + B); x += 2) { var p = (y * w + x) * 4, l = Math.max(a[p], a[p + 1], a[p + 2]); hist[l >> 2]++; n++; } }
					var want = n * 0.1, c = 0, k = 63; for (; k > 0; k--) { c += hist[k]; if (c >= want) { break; } }
					g[by * gw + bx] = k * 4 + 2;
				}
			}
			// grow the bright paper over dark print and photos, then smooth
			var t = new Float32Array(g.length), pass, i2;
			for (pass = 0; pass < 2; pass++) {
				for (by = 0; by < gh; by++) { for (bx = 0; bx < gw; bx++) { var mx = 0; for (y = -1; y <= 1; y++) { for (x = -1; x <= 1; x++) { var yy = by + y, xx = bx + x; if (yy >= 0 && yy < gh && xx >= 0 && xx < gw && g[yy * gw + xx] > mx) { mx = g[yy * gw + xx]; } } } t[by * gw + bx] = mx; } }
				g.set(t);
			}
			for (pass = 0; pass < 3; pass++) {
				for (by = 0; by < gh; by++) { for (bx = 0; bx < gw; bx++) { var sm = 0, ct = 0; for (y = -1; y <= 1; y++) { for (x = -1; x <= 1; x++) { var y2 = by + y, x2 = bx + x; if (y2 >= 0 && y2 < gh && x2 >= 0 && x2 < gw) { sm += g[y2 * gw + x2]; ct++; } } } t[by * gw + bx] = sm / ct; } }
				g.set(t);
			}
			for (i2 = 0; i2 < g.length; i2++) { if (g[i2] < 70) { g[i2] = 70; } }
			return { g: g, gw: gw, gh: gh, B: B };
		}
		function bgAt(bg, x, y) {
			var fx = x / bg.B - 0.5, fy = y / bg.B - 0.5; if (fx < 0) { fx = 0; } if (fy < 0) { fy = 0; } if (fx > bg.gw - 1) { fx = bg.gw - 1; } if (fy > bg.gh - 1) { fy = bg.gh - 1; }
			var x0 = fx | 0, y0 = fy | 0, x1 = Math.min(bg.gw - 1, x0 + 1), y1 = Math.min(bg.gh - 1, y0 + 1), ax = fx - x0, ay = fy - y0, g = bg.g, w = bg.gw;
			return (g[y0 * w + x0] * (1 - ax) + g[y0 * w + x1] * ax) * (1 - ay) + (g[y1 * w + x0] * (1 - ax) + g[y1 * w + x1] * ax) * ay;
		}
		function pct(hist, total, f) { var want = total * f, c = 0; for (var k = 0; k < 256; k++) { c += hist[k]; if (c >= want) { return k; } } return 255; }
		/** clean = white paper, colours kept · grey · bw = black text on white · photo = levels only (ID cards) · orig */
		function tone(m) {
			var a = new Uint8ClampedArray(m.buf), w = m.w, h = m.h, mode = m.mode, n = w * h, i, p, hist = new Uint32Array(256);
			if (mode === 'orig') { return { buf: a.buffer, w: w, h: h }; }
			if (mode === 'photo') {
				for (i = 0; i < n; i += 3) { p = i * 4; hist[(a[p] * 0.299 + a[p + 1] * 0.587 + a[p + 2] * 0.114) | 0]++; }
				var lo = pct(hist, n / 3, 0.004), hi = pct(hist, n / 3, 0.996), k0 = hi - lo > 40 ? 255 / (hi - lo) : 1;
				if (hi - lo <= 40) { lo = 0; }
				for (p = 0; p < a.length; p += 4) { a[p] = clamp((a[p] - lo) * k0); a[p + 1] = clamp((a[p + 1] - lo) * k0); a[p + 2] = clamp((a[p + 2] - lo) * k0); }
				return { buf: a.buffer, w: w, h: h };
			}
			var bg = background(a, w, h), x, y;
			for (y = 0; y < h; y++) {
				var row = y * w * 4, step = 8, bnext = bgAt(bg, 0, y), bcur;
				for (x = 0; x < w; x++) {
					if (x % step === 0) { bcur = bnext; bnext = bgAt(bg, Math.min(w - 1, x + step), y); }
					var b0 = bcur + (bnext - bcur) * ((x % step) / step), s = 250 / b0; p = row + x * 4;
					a[p] = clamp(a[p] * s); a[p + 1] = clamp(a[p + 1] * s); a[p + 2] = clamp(a[p + 2] * s);
				}
			}
			hist.fill(0);
			for (i = 0; i < n; i += 2) { p = i * 4; hist[(a[p] * 0.299 + a[p + 1] * 0.587 + a[p + 2] * 0.114) | 0]++; }
			var blk = Math.min(110, pct(hist, n / 2, 0.01)), kk = 255 / (245 - blk * 0.85);
			if (mode === 'bw') {
				// Otsu on the cleaned page, kept in a sensible band, with a soft edge so letters stay smooth
				var tot = n / 2, sum = 0, sB = 0, wB = 0, best = 0, T = 180, t;
				for (t = 0; t < 256; t++) { sum += t * hist[t]; }
				for (t = 0; t < 256; t++) { wB += hist[t]; if (!wB) { continue; } var wF = tot - wB; if (!wF) { break; } sB += t * hist[t]; var mB = sB / wB, mF = (sum - sB) / wF, v = wB * wF * (mB - mF) * (mB - mF); if (v > best) { best = v; T = t; } }
				T = Math.max(150, Math.min(215, T + 12));
				for (p = 0; p < a.length; p += 4) { var l = a[p] * 0.299 + a[p + 1] * 0.587 + a[p + 2] * 0.114, o = l <= T - 14 ? 0 : (l >= T + 14 ? 255 : (l - T + 14) * 255 / 28); a[p] = a[p + 1] = a[p + 2] = o; }
				return { buf: a.buffer, w: w, h: h };
			}
			for (p = 0; p < a.length; p += 4) {
				var r = clamp((a[p] - blk * 0.85) * kk), g = clamp((a[p + 1] - blk * 0.85) * kk), b = clamp((a[p + 2] - blk * 0.85) * kk);
				if (mode === 'grey') { r = g = b = r * 0.299 + g * 0.587 + b * 0.114; }
				a[p] = r; a[p + 1] = g; a[p + 2] = b;
			}
			return { buf: a.buffer, w: w, h: h };
		}
		return { run: function (m) { return m.op === 'warp' ? warp(m) : tone(m); } };
	}

	var worker = null, jobs = {}, jobN = 0, local = null;
	function job(m) {
		if (worker === null) {
			try {
				var src = 'var P=(' + PROC.toString() + ')();self.onmessage=function(e){var r;try{r=P.run(e.data);r.id=e.data.id;self.postMessage(r,[r.buf]);}catch(x){self.postMessage({id:e.data.id,err:String(x)});}};';
				worker = new Worker(URL.createObjectURL(new Blob([src], { type: 'text/javascript' })));
				worker.onmessage = function (e) { var j = jobs[e.data.id]; delete jobs[e.data.id]; if (j) { if (e.data.err) { j[1](new Error(e.data.err)); } else { j[0](e.data); } } };
				worker.onerror = function () { worker = false; };
			} catch (e) { worker = false; }
		}
		if (!worker) { local = local || PROC(); return new Promise(function (ok) { setTimeout(function () { ok(local.run(m)); }, 0); }); }
		return new Promise(function (ok, no) { m.id = ++jobN; jobs[m.id] = [ok, no]; worker.postMessage(m, [m.buf]); });
	}

	/* =================================================================== geometry */
	function canvas(w, h) { var c = D.createElement('canvas'); c.width = Math.max(1, Math.round(w)); c.height = Math.max(1, Math.round(h)); return c; }
	function dist(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }
	/** Corners in order: top-left, top-right, bottom-right, bottom-left. */
	function order(q) {
		var cx = 0, cy = 0; q.forEach(function (p) { cx += p.x / 4; cy += p.y / 4; });
		q = q.slice().sort(function (a, b) { return Math.atan2(a.y - cy, a.x - cx) - Math.atan2(b.y - cy, b.x - cx); });
		var s = 0, best = 1e18; q.forEach(function (p, i) { if (p.x + p.y < best) { best = p.x + p.y; s = i; } });
		return q.slice(s).concat(q.slice(0, s));
	}
	function area(q) { var s = 0; for (var i = 0; i < q.length; i++) { var a = q[i], b = q[(i + 1) % q.length]; s += a.x * b.y - b.x * a.y; } return Math.abs(s) / 2; }
	function convex(q) { var sg = 0; for (var i = 0; i < 4; i++) { var a = q[i], b = q[(i + 1) % 4], c = q[(i + 2) % 4], z = (b.x - a.x) * (c.y - b.y) - (b.y - a.y) * (c.x - b.x); if (Math.abs(z) < 1e-9) { return false; } var s = z > 0 ? 1 : -1; if (sg && s !== sg) { return false; } sg = s; } return true; }
	/** Homography mapping the rectangle (0,0)-(w,h) onto quad q (for sampling the source). */
	function homography(q, w, h) {
		var src = [[0, 0], [w, 0], [w, h], [0, h]], A = [], B = [], i;
		for (i = 0; i < 4; i++) {
			var x = src[i][0], y = src[i][1], u = q[i].x, v = q[i].y;
			A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); B.push(u);
			A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); B.push(v);
		}
		for (i = 0; i < 8; i++) {
			var mx = i; for (var r = i + 1; r < 8; r++) { if (Math.abs(A[r][i]) > Math.abs(A[mx][i])) { mx = r; } }
			var t = A[i]; A[i] = A[mx]; A[mx] = t; var tb = B[i]; B[i] = B[mx]; B[mx] = tb;
			for (r = 0; r < 8; r++) { if (r === i) { continue; } var f = A[r][i] / A[i][i]; for (var c = i; c < 8; c++) { A[r][c] -= f * A[i][c]; } B[r] -= f * B[i]; }
		}
		var H = []; for (i = 0; i < 8; i++) { H.push(B[i] / A[i][i]); } H.push(1);
		return H;
	}
	function hull(pts) {
		pts.sort(function (a, b) { return a.x - b.x || a.y - b.y; });
		var cr = function (o, a, b) { return (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x); }, lo = [], up = [], i;
		for (i = 0; i < pts.length; i++) { while (lo.length >= 2 && cr(lo[lo.length - 2], lo[lo.length - 1], pts[i]) <= 0) { lo.pop(); } lo.push(pts[i]); }
		for (i = pts.length - 1; i >= 0; i--) { while (up.length >= 2 && cr(up[up.length - 2], up[up.length - 1], pts[i]) <= 0) { up.pop(); } up.push(pts[i]); }
		lo.pop(); up.pop(); return lo.concat(up);
	}
	/** Largest 4-corner shape inside a convex outline. */
	function bestQuad(h) {
		if (h.length > 90) { var st = h.length / 90, t = []; for (var s = 0; s < h.length; s += st) { t.push(h[Math.floor(s)]); } h = t; }
		var n = h.length, best = 0, out = null;
		if (n < 4) { return null; }
		var tri = function (a, b, c) { return Math.abs((b.x - a.x) * (c.y - a.y) - (b.y - a.y) * (c.x - a.x)) / 2; };
		for (var i = 0; i < n; i++) {
			for (var j = i + 2; j < n; j++) {
				var k1 = -1, a1 = 0, k2 = -1, a2 = 0, k;
				for (k = i + 1; k < j; k++) { var x1 = tri(h[i], h[j], h[k]); if (x1 > a1) { a1 = x1; k1 = k; } }
				for (k = j + 1; k < n + i; k++) { var x2 = tri(h[i], h[j], h[k % n]); if (x2 > a2) { a2 = x2; k2 = k % n; } }
				if (k1 >= 0 && k2 >= 0 && a1 + a2 > best) { best = a1 + a2; out = [h[i], h[k1], h[j], h[k2]]; }
			}
		}
		return out;
	}
	/* -------- finding the page: try "bright/dark region" and "closed edges", keep the one that looks most like a sheet */
	function grey(c, max) {
		var k = Math.min(1, max / Math.max(c.width, c.height)), w = Math.max(8, Math.round(c.width * k)), h = Math.max(8, Math.round(c.height * k));
		var t = canvas(w, h), x = t.getContext('2d', { willReadFrequently: true }); x.drawImage(c, 0, 0, w, h);
		var d = x.getImageData(0, 0, w, h).data, g = new Uint8Array(w * h), g2 = new Uint8Array(w * h), i, y, xx;
		for (i = 0; i < w * h; i++) { g[i] = (d[i * 4] * 0.299 + d[i * 4 + 1] * 0.587 + d[i * 4 + 2] * 0.114) | 0; }
		for (var pass = 0; pass < 2; pass++) {
			for (y = 0; y < h; y++) { for (xx = 0; xx < w; xx++) { var s = 0, n = 0; for (var dy = -1; dy <= 1; dy++) { for (var dx = -1; dx <= 1; dx++) { var yy = y + dy, x2 = xx + dx; if (yy >= 0 && yy < h && x2 >= 0 && x2 < w) { s += g[yy * w + x2]; n++; } } } g2[y * w + xx] = s / n; } }
			g.set(g2);
		}
		return { g: g, w: w, h: h, k: k };
	}
	function component(mask, w, h) {
		// the region under the middle of the picture (or the nearest one to it)
		var cx = w >> 1, cy = h >> 1, seed = -1, r, a;
		for (r = 0; r < Math.min(w, h) * 0.25 && seed < 0; r += 2) { for (a = 0; a < 16 && seed < 0; a++) { var x = Math.round(cx + r * Math.cos(a * Math.PI / 8)), y = Math.round(cy + r * Math.sin(a * Math.PI / 8)); if (x >= 0 && y >= 0 && x < w && y < h && mask[y * w + x]) { seed = y * w + x; } } }
		if (seed < 0) { return null; }
		var seen = new Uint8Array(w * h), qu = new Int32Array(w * h), qh = 0, qt = 0, cnt = 0, rowL = new Int32Array(h).fill(-1), rowR = new Int32Array(h).fill(-1), colT = new Int32Array(w).fill(-1), colB = new Int32Array(w).fill(-1), edge = 0;
		qu[qt++] = seed; seen[seed] = 1;
		while (qh < qt) {
			var p = qu[qh++], px = p % w, py = (p / w) | 0; cnt++;
			if (px === 0 || py === 0 || px === w - 1 || py === h - 1) { edge++; }
			if (rowL[py] < 0 || px < rowL[py]) { rowL[py] = px; } if (px > rowR[py]) { rowR[py] = px; }
			if (colT[px] < 0 || py < colT[px]) { colT[px] = py; } if (py > colB[px]) { colB[px] = py; }
			if (px > 0 && mask[p - 1] && !seen[p - 1]) { seen[p - 1] = 1; qu[qt++] = p - 1; }
			if (px < w - 1 && mask[p + 1] && !seen[p + 1]) { seen[p + 1] = 1; qu[qt++] = p + 1; }
			if (py > 0 && mask[p - w] && !seen[p - w]) { seen[p - w] = 1; qu[qt++] = p - w; }
			if (py < h - 1 && mask[p + w] && !seen[p + w]) { seen[p + w] = 1; qu[qt++] = p + w; }
		}
		var pts = [], i;
		for (i = 0; i < h; i++) { if (rowL[i] >= 0) { pts.push({ x: rowL[i], y: i }, { x: rowR[i] + 1, y: i }); } }
		for (i = 0; i < w; i++) { if (colT[i] >= 0) { pts.push({ x: i, y: colT[i] }, { x: i, y: colB[i] + 1 }); } }
		return { n: cnt, pts: pts, edge: edge };
	}
	function judge(comp, w, h) {
		if (!comp || comp.n < w * h * 0.06 || comp.n > w * h * 0.985) { return null; }
		var hl = hull(comp.pts), q = bestQuad(hl);
		if (!q) { return null; }
		q = order(q);
		var qa = area(q), ha = area(hl);
		if (!convex(q) || qa < w * h * 0.06) { return null; }
		// a sheet fills its corners (fill close to 1) and its outline is close to 4 straight sides
		var fill = Math.min(comp.n / qa, qa / comp.n), fit = qa / ha;
		for (var i = 0; i < 4; i++) { var a = q[i], b = q[(i + 1) % 4], c = q[(i + 3) % 4], v1x = b.x - a.x, v1y = b.y - a.y, v2x = c.x - a.x, v2y = c.y - a.y, cs = (v1x * v2x + v1y * v2y) / (Math.hypot(v1x, v1y) * Math.hypot(v2x, v2y)); if (Math.abs(cs) > 0.6) { return null; } }
		var score = fill * 0.6 + fit * 0.4 - (comp.edge > (w + h) * 0.5 ? 0.25 : 0);
		return score > 0.8 ? { q: q, score: score } : null;
	}
	function find(c, max) {
		var G = grey(c, max || 400), g = G.g, w = G.w, h = G.h, n = w * h, i, best = null;
		// 1. tone: the sheet is lighter (or darker) than what it lies on
		// the typical tone of the middle (the sheet) and of the picture border (what it lies on); split half-way
		var hc = new Uint32Array(256), hr = new Uint32Array(256), cn = 0, rn = 0, x, y, b = Math.round(Math.min(w, h) * 0.04);
		for (y = 0; y < h; y++) { for (x = 0; x < w; x++) { var gv = g[y * w + x]; if (x > w * 0.3 && x < w * 0.7 && y > h * 0.3 && y < h * 0.7) { hc[gv]++; cn++; } else if (x < b || y < b || x >= w - b || y >= h - b) { hr[gv]++; rn++; } } }
		var cm = pct(hc, cn, 0.5), rm = pct(hr, rn, 0.5), cands = [];
		if (Math.abs(cm - rm) > 8) {
			var T = (cm + rm) / 2, mk = new Uint8Array(n), light = cm > rm; for (i = 0; i < n; i++) { mk[i] = light ? (g[i] > T ? 1 : 0) : (g[i] < T ? 1 : 0); }
			best = judge(component(mk, w, h), w, h);
			if (best) { cands.push(best); }
		}
		// 2. edges: the outline of the sheet (white paper on a white table still has an edge)
		var mg = new Uint8Array(n), mh = new Uint32Array(256);
		for (y = 1; y < h - 1; y++) { for (x = 1; x < w - 1; x++) { i = y * w + x; var gx = g[i + 1] - g[i - 1] + (g[i - w + 1] - g[i - w - 1] + g[i + w + 1] - g[i + w - 1]) / 2, gy = g[i + w] - g[i - w] + (g[i + w - 1] - g[i - w - 1] + g[i + w + 1] - g[i - w + 1]) / 2, m = Math.min(255, Math.hypot(gx, gy)); mg[i] = m; mh[m | 0]++; } }
		// try a strong and a gentler edge level (a white sheet on a light table only has a faint edge)
		[Math.max(14, pct(mh, n, 0.9)), Math.max(8, pct(mh, n, 0.75))].forEach(function (et, pass) {
			if (pass && cands.length) { return; }
			var wall = new Uint8Array(n);
			for (i = 0; i < n; i++) { if (mg[i] >= et) { wall[i] = 1; } }
			for (y = 1; y < h - 1; y++) { for (x = 1; x < w - 1; x++) { i = y * w + x; if (mg[i] >= et) { wall[i - 1] = wall[i + 1] = wall[i - w] = wall[i + w] = 1; } } }
			// flood from the picture border through everything that is not an edge; what it cannot reach is enclosed
			var out = new Uint8Array(n), qu = new Int32Array(n), qh = 0, qt = 0, z, p;
			for (x = 0; x < w; x++) { p = x; if (!wall[p] && !out[p]) { out[p] = 1; qu[qt++] = p; } p = (h - 1) * w + x; if (!wall[p] && !out[p]) { out[p] = 1; qu[qt++] = p; } }
			for (y = 0; y < h; y++) { p = y * w; if (!wall[p] && !out[p]) { out[p] = 1; qu[qt++] = p; } p = y * w + w - 1; if (!wall[p] && !out[p]) { out[p] = 1; qu[qt++] = p; } }
			while (qh < qt) {
				p = qu[qh++]; var px = p % w;
				z = p - 1; if (px > 0 && !out[z] && !wall[z]) { out[z] = 1; qu[qt++] = z; }
				z = p + 1; if (px < w - 1 && !out[z] && !wall[z]) { out[z] = 1; qu[qt++] = z; }
				z = p - w; if (z >= 0 && !out[z] && !wall[z]) { out[z] = 1; qu[qt++] = z; }
				z = p + w; if (z < n && !out[z] && !wall[z]) { out[z] = 1; qu[qt++] = z; }
			}
			var inside = new Uint8Array(n); for (i = 0; i < n; i++) { inside[i] = out[i] ? 0 : 1; }
			var e2 = judge(component(inside, w, h), w, h);
			if (e2) { cands.push(e2); }
		});
		// every side must be a real edge: the same light/dark step all along it (wood grain or a text block is not)
		cands = cands.filter(function (z) { z.sup = support(g, w, h, z.q); z.score += z.sup * 0.3; return z.sup >= 0.6; });
		if (!cands.length) { return null; }
		// both ways may find something (e.g. the text block inside the page): take the biggest shape that still looks like a sheet
		var top = Math.max.apply(null, cands.map(function (z) { return z.score; }));
		best = cands.filter(function (z) { return z.score >= top - 0.08; }).sort(function (a, z) { return area(z.q) - area(a.q); })[0];
		return best.q.map(function (p) { return { x: p.x / G.k, y: p.y / G.k }; });
		function pct(hh, tot, f) { var want = tot * f, cc = 0; for (var k = 0; k < 256; k++) { cc += hh[k]; if (cc >= want) { return k; } } return 255; }
	}
	function support(g, w, h, q) {
		var cx = (q[0].x + q[1].x + q[2].x + q[3].x) / 4, cy = (q[0].y + q[1].y + q[2].y + q[3].y) / 4, worst = 1;
		for (var i = 0; i < 4; i++) {
			var a = q[i], b = q[(i + 1) % 4], L = dist(a, b), nx = -(b.y - a.y) / L, ny = (b.x - a.x) / L, pos = 0, neg = 0, n = 0;
			if ((a.x + b.x) / 2 * nx + (a.y + b.y) / 2 * ny < cx * nx + cy * ny) { nx = -nx; ny = -ny; }
			for (var t = 0.06; t <= 0.94; t += 0.88 / 23) {
				var px = a.x + (b.x - a.x) * t, py = a.y + (b.y - a.y) * t, best = 0;
				// allow the edge to be a pixel or two off the line
				for (var s = -2; s <= 2; s++) {
					var ix = Math.round(px - nx * (3 - s)), iy = Math.round(py - ny * (3 - s)), ox = Math.round(px + nx * (3 + s)), oy = Math.round(py + ny * (3 + s));
					if (ix < 0 || iy < 0 || ox < 0 || oy < 0 || ix >= w || ox >= w || iy >= h || oy >= h) { continue; }
					var d = g[iy * w + ix] - g[oy * w + ox]; if (Math.abs(d) > Math.abs(best)) { best = d; }
				}
				n++; if (best > 5) { pos++; } else if (best < -5) { neg++; }
			}
			worst = Math.min(worst, Math.max(pos, neg) / n);
		}
		return worst;
	}
	/**
	 * Put the corners exactly on the edges: along each side, find the strongest light/dark step across it at
	 * high resolution, fit a straight line through those points, and meet the four lines.
	 */
	function refine(c, q) {
		var G = grey(c, 1600), g = G.g, w = G.w, h = G.h, k = G.k, Q = q.map(function (p) { return { x: p.x * k, y: p.y * k }; }), lines = [], i;
		function at(x, y) { var xi = x | 0, yi = y | 0; if (xi < 0 || yi < 0 || xi >= w - 1 || yi >= h - 1) { return -1; } var fx = x - xi, fy = y - yi, p = yi * w + xi; return (g[p] * (1 - fx) + g[p + 1] * fx) * (1 - fy) + (g[p + w] * (1 - fx) + g[p + w + 1] * fx) * fy; }
		var cx = (Q[0].x + Q[1].x + Q[2].x + Q[3].x) / 4, cy = (Q[0].y + Q[1].y + Q[2].y + Q[3].y) / 4;
		for (i = 0; i < 4; i++) {
			var a = Q[i], b = Q[(i + 1) % 4], L = dist(a, b), ux = (b.x - a.x) / L, uy = (b.y - a.y) / L, nx = -uy, ny = ux;
			if ((a.x + b.x) / 2 * nx + (a.y + b.y) / 2 * ny < cx * nx + cy * ny) { nx = -nx; ny = -ny; } // normal points outwards
			var r = Math.max(6, L * 0.035), pts = [], t, s;
			for (t = 0.08; t <= 0.921; t += 0.84 / 40) {
				var px = a.x + (b.x - a.x) * t, py = a.y + (b.y - a.y) * t, bs = 0, bd = 0;
				for (s = -r; s <= r; s += 0.5) { var o = at(px + nx * (s + 1), py + ny * (s + 1)), in_ = at(px + nx * (s - 1), py + ny * (s - 1)); if (o < 0 || in_ < 0) { continue; } var d = Math.abs(in_ - o); if (d > bd) { bd = d; bs = s; } }
				if (bd > 5) { pts.push({ x: px + nx * bs, y: py + ny * bs }); }
			}
			if (pts.length < 10) { return q; }
			for (var pass = 0; pass < 2; pass++) {
				var mx = 0, my = 0; pts.forEach(function (p) { mx += p.x; my += p.y; }); mx /= pts.length; my /= pts.length;
				var sxx = 0, sxy = 0, syy = 0; pts.forEach(function (p) { var dx = p.x - mx, dy = p.y - my; sxx += dx * dx; sxy += dx * dy; syy += dy * dy; });
				var ang = 0.5 * Math.atan2(2 * sxy, sxx - syy), dxl = Math.cos(ang), dyl = Math.sin(ang);
				var res = pts.map(function (p) { return Math.abs((p.x - mx) * -dyl + (p.y - my) * dxl); }), med = res.slice().sort(function (u, v) { return u - v; })[res.length >> 1];
				if (pass === 0) { var keep = pts.filter(function (p, j) { return res[j] <= Math.max(1.5, med * 2.5); }); if (keep.length >= 8) { pts = keep; } }
			}
			lines.push({ x: mx, y: my, dx: dxl, dy: dyl });
		}
		var out = [];
		for (i = 0; i < 4; i++) {
			var A = lines[(i + 3) % 4], B = lines[i], den = A.dx * B.dy - A.dy * B.dx;
			if (Math.abs(den) < 1e-6) { return q; }
			var tt = ((B.x - A.x) * B.dy - (B.y - A.y) * B.dx) / den, X = A.x + A.dx * tt, Y = A.y + A.dy * tt;
			if (Math.hypot(X - Q[i].x, Y - Q[i].y) > Math.max(12, dist(Q[i], Q[(i + 1) % 4]) * 0.06)) { return q; } // not believable: keep the first guess
			out.push({ x: X / k, y: Y / k });
		}
		return convex(out) ? out : q;
	}
	/** How sharp a picture is (variance of the Laplacian on a small grey copy). */
	function sharp(c) {
		var G = grey(c, 320), g = G.g, w = G.w, h = G.h, s = 0, s2 = 0, n = 0;
		for (var y = 1; y < h - 1; y += 1) { for (var x = 1; x < w - 1; x += 1) { var i = y * w + x, l = 4 * g[i] - g[i - 1] - g[i + 1] - g[i - w] - g[i + w]; s += l; s2 += l * l; n++; } }
		var m = s / n; return s2 / n - m * m;
	}

	/* =================================================================== pages */
	var RATIO = { card: 85.6 / 53.98, letter: 11 / 8.5, legal: 14 / 8.5, a4: 297 / 210 };
	function outSize(q, kind, maxLong) {
		var w = Math.max(dist(q[0], q[1]), dist(q[3], q[2])), h = Math.max(dist(q[0], q[3]), dist(q[1], q[2])), r = Math.max(w, h) / Math.min(w, h);
		// a sheet seen at an angle looks a little squashed: snap to the real paper shape when it is close
		var targets = kind === 'card' ? [RATIO.card] : [RATIO.letter, RATIO.legal, RATIO.a4];
		targets.forEach(function (t) { if (Math.abs(r - t) / t < 0.07) { r = t; } });
		var long = Math.min(maxLong, Math.max(w, h)), short = long / r;
		return w >= h ? { w: Math.round(long), h: Math.round(short) } : { w: Math.round(short), h: Math.round(long) };
	}
	function rotate(c, deg) { if (!deg) { return c; } var q = deg % 180 !== 0, r = canvas(q ? c.height : c.width, q ? c.width : c.height), x = r.getContext('2d'); x.translate(r.width / 2, r.height / 2); x.rotate(deg * Math.PI / 180); x.drawImage(c, -c.width / 2, -c.height / 2); return r; }
	function thumb(c, max) { var k = Math.min(1, max / Math.max(c.width, c.height)), t = canvas(c.width * k, c.height * k), x = t.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, t.width, t.height); x.drawImage(c, 0, 0, t.width, t.height); return t; }
	function toBlob(c, q) { return new Promise(function (ok) { c.toBlob(function (b) { ok(b); }, 'image/jpeg', q); }); }
	function decode(b) {
		return (W.createImageBitmap ? createImageBitmap(b).catch(function () { return loadBlob(b); }) : loadBlob(b)).then(function (im) {
			var c = canvas(im.width || im.naturalWidth, im.height || im.naturalHeight); c.getContext('2d').drawImage(im, 0, 0); if (im.close) { im.close(); } return c;
		});
	}
	function loadBlob(b) { return new Promise(function (ok, no) { var u = URL.createObjectURL(b), im = new Image(); im.onload = function () { ok(im); setTimeout(function () { URL.revokeObjectURL(u); }, 1000); }; im.onerror = function () { URL.revokeObjectURL(u); no(); }; im.src = u; }); }
	function srcCanvas(pg) { return pg._src ? Promise.resolve(pg._src) : decode(pg.srcBlob); }
	/**
	 * Straighten + clean one page. Pages are kept as JPEG data (not open pictures) so a phone can hold many of them:
	 * the photo (for moving corners again), the straightened copy (a new look is quick), and the finished page.
	 */
	function processPage(pg, kind) {
		var q = pg.q, sz = outSize(q, kind, kind === 'card' ? 2200 : 3300);
		var key = q.map(function (p) { return Math.round(p.x) + ',' + Math.round(p.y); }).join(';') + '|' + sz.w + 'x' + sz.h;
		var warped;
		if (pg.wk === key && pg.warpBlob) {
			warped = decode(pg.warpBlob).then(function (c) { var d = c.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, c.width, c.height); return { buf: d.data.buffer, w: c.width, h: c.height }; });
		} else {
			warped = srcCanvas(pg).then(function (sc) {
				var sx = sc.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, sc.width, sc.height);
				var shrink = Math.max(dist(q[0], q[1]), dist(q[0], q[3])) / Math.max(sz.w, sz.h);
				return job({ op: 'warp', buf: sx.data.buffer, sw: sx.width, sh: sx.height, H: homography(q, sz.w, sz.h), ow: sz.w, oh: sz.h, ss: shrink > 1.4 ? 2 : 1 });
			}).then(function (r) {
				var c = canvas(r.w, r.h); c.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(r.buf.slice(0)), r.w, r.h), 0, 0);
				return toBlob(c, 0.95).then(function (b) { pg.warpBlob = b; pg.wk = key; return r; });
			});
		}
		return warped.then(function (r) {
			return job({ op: 'tone', buf: r.buf, w: r.w, h: r.h, mode: pg.mode });
		}).then(function (r) {
			var c = canvas(r.w, r.h); c.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(r.buf), r.w, r.h), 0, 0);
			c = rotate(c, pg.rot);
			pg.ow = c.width; pg.oh = c.height;
			pg.th = thumb(c, 260).toDataURL('image/jpeg', 0.75);
			pg.view = thumb(c, 1400).toDataURL('image/jpeg', 0.85);
			return toBlob(c, pg.mode === 'bw' ? 0.85 : 0.9);
		}).then(function (b) { pg.outBlob = b; return pg; });
	}
	/** A plain PDF with one JPEG per page; page size from the paper the page looks like. list: [{ j: Uint8Array, w, h }] */
	function pdf(list, kind) {
		var enc = new TextEncoder(), parts = [], off = [], len = 0;
		function add(x) { var b = typeof x === 'string' ? enc.encode(x) : x; parts.push(b); len += b.length; }
		function obj(n, s, stream) { off[n] = len; add(n + ' 0 obj\n' + s); if (stream) { add('stream\n'); add(stream); add('\nendstream'); } add('\nendobj\n'); }
		add('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');
		var n = list.length, kids = [];
		for (var i = 0; i < n; i++) { kids.push((3 + i * 3) + ' 0 R'); }
		obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
		obj(2, '<< /Type /Pages /Count ' + n + ' /Kids [' + kids.join(' ') + '] >>');
		list.forEach(function (p, i) {
			var pg = 3 + i * 3, ct = pg + 1, im = pg + 2, longIn = kind === 'card' ? 3.37 : (Math.max(p.w, p.h) / Math.min(p.w, p.h) > 1.5 ? 14 : 11), dpi = Math.max(p.w, p.h) / longIn;
			var Wd = Math.round(p.w * 72 / dpi * 100) / 100, Hd = Math.round(p.h * 72 / dpi * 100) / 100, cs = 'q ' + Wd + ' 0 0 ' + Hd + ' 0 0 cm /Im0 Do Q';
			obj(pg, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + Wd + ' ' + Hd + '] /Resources << /XObject << /Im0 ' + im + ' 0 R >> >> /Contents ' + ct + ' 0 R >>');
			obj(ct, '<< /Length ' + cs.length + ' >>\n', enc.encode(cs));
			obj(im, '<< /Type /XObject /Subtype /Image /Width ' + p.w + ' /Height ' + p.h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + p.j.length + ' >>\n', p.j);
		});
		var x = len, total = 3 + n * 3, xr = 'xref\n0 ' + total + '\n0000000000 65535 f \n';
		for (var k = 1; k < total; k++) { xr += String(off[k]).padStart(10, '0') + ' 00000 n \n'; }
		add(xr + 'trailer\n<< /Size ' + total + ' /Root 1 0 R >>\nstartxref\n' + x + '\n%%EOF\n');
		return new Blob(parts, { type: 'application/pdf' });
	}
	function bytes(b) { return b.arrayBuffer().then(function (a) { return new Uint8Array(a); }); }
	function scaled(c, maxLong) { var k = Math.min(1, maxLong / Math.max(c.width, c.height)); if (k === 1) { return c; } var t = canvas(c.width * k, c.height * k), x = t.getContext('2d'); x.imageSmoothingQuality = 'high'; x.drawImage(c, 0, 0, t.width, t.height); return t; }
	/** One picture → JPEG; several → one PDF (kept under the 20 MB upload limit). */
	function build(pages, kind) {
		var name = kind === 'card' ? 'card' : 'document', th = pages[0].th;
		return loadBlob(pages[0].outBlob).then(function (im) { var c = canvas(im.naturalWidth, im.naturalHeight); c.getContext('2d').drawImage(im, 0, 0); th = thumb(c, 420).toDataURL('image/jpeg', 0.72); }).catch(function () {}).then(function () {
			if (pages.length === 1) { var f1 = new File([pages[0].outBlob], name + '-scan.jpg', { type: 'image/jpeg' }); f1._thumb = th; return f1; }
			var tries = [null, [0.78, 2600], [0.7, 2000], [0.62, 1600]];
			function go(t) {
				var list = Promise.all(pages.map(function (pg) {
					if (!tries[t]) { return bytes(pg.outBlob).then(function (j) { return { j: j, w: pg.ow, h: pg.oh }; }); }
					return decode(pg.outBlob).then(function (c) { c = scaled(c, tries[t][1]); return toBlob(c, tries[t][0]).then(bytes).then(function (j) { return { j: j, w: c.width, h: c.height }; }); });
				}));
				return list.then(function (l) {
					var b = pdf(l, kind);
					if (b.size > 18500000 && t < tries.length - 1) { return go(t + 1); }
					var f = new File([b], name + '-scan-' + pages.length + 'p.pdf', { type: 'application/pdf' }); f._thumb = th; return f;
				});
			}
			return go(0);
		});
	}

	/* =================================================================== screen */
	var S = null; // the open scanner
	var ICON = {
		x: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
		bolt: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>',
		rot: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg>',
		del: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>',
		left: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>',
		right: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>',
		img: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 16l5-5 4 4 3-3 6 6"/></svg>',
	};
	var MODES = [['clean', 'Clean'], ['photo', 'Colour'], ['grey', 'Grey'], ['bw', 'B & W'], ['orig', 'Original']];
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function vib(ms) { try { if (navigator.vibrate) { navigator.vibrate(ms); } } catch (e) {} }

	function shell() {
		var r = D.createElement('div');
		r.className = 'csc'; r.setAttribute('role', 'dialog'); r.setAttribute('aria-modal', 'true'); r.setAttribute('aria-label', S.o.title || 'Scan');
		D.body.appendChild(r); D.documentElement.classList.add('csc-on');
		S.root = r;
		S.key = function (e) { if (e.key === 'Escape') { e.preventDefault(); if (S.view === 'edit') { review(); } else { close(); } } };
		D.addEventListener('keydown', S.key);
		return r;
	}
	function close() {
		if (!S) { return; }
		stopCam(); D.removeEventListener('keydown', S.key); if (S.root) { S.root.remove(); }
		D.documentElement.classList.remove('csc-on');
		S = null;
	}

	/* ---------------- camera */
	function stopCam() {
		if (!S) { return; }
		if (S.loop) { clearTimeout(S.loop); S.loop = 0; }
		if (S.stream) { S.stream.getTracks().forEach(function (t) { t.stop(); }); S.stream = null; }
	}
	function camera() {
		S.view = 'cam'; S.root.classList.add('is-cam');
		var many = S.o.multi;
		S.root.innerHTML =
			'<div class="csc-top"><button type="button" class="csc-ic" data-a="close" aria-label="Close">' + ICON.x + '</button><b>' + esc(S.o.title || 'Scan') + '</b>' +
			'<span class="csc-sp"></span><button type="button" class="csc-ic" data-a="torch" aria-label="Flash" hidden>' + ICON.bolt + '</button>' +
			'<button type="button" class="csc-chip' + (S.auto ? ' is-on' : '') + '" data-a="auto" aria-pressed="' + (S.auto ? 'true' : 'false') + '">Auto</button></div>' +
			'<div class="csc-view"><video playsinline muted autoplay></video><canvas class="csc-ov"></canvas><p class="csc-hint" aria-live="polite">Starting the camera…</p><div class="csc-flash"></div></div>' +
			'<div class="csc-bot"><button type="button" class="csc-stack" data-a="review" ' + (S.pages.length ? '' : 'hidden') + '>' + (S.pages.length ? '<img alt="" src="' + S.pages[S.pages.length - 1].th + '">' : '') + '<i>' + S.pages.length + '</i></button>' +
			'<button type="button" class="csc-shut" data-a="shoot" aria-label="Take picture"><i></i></button>' +
			(many && S.pages.length ? '<button type="button" class="csc-done" data-a="review">Done</button>' : '<button type="button" class="csc-ic csc-alt" data-a="native" aria-label="Use the camera app instead">' + ICON.img + '<small>Camera app</small></button>') + '</div>';
		var v = S.root.querySelector('video'), ov = S.root.querySelector('.csc-ov'), hint = S.root.querySelector('.csc-hint');
		S.v = v; S.ov = ov; S.hint = hint;
		if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && W.isSecureContext)) { hint.textContent = 'This browser cannot open the camera here. Use “Camera app”.'; return; }
		var want = { video: { facingMode: { ideal: 'environment' }, width: { ideal: 3840 }, height: { ideal: 2160 } }, audio: false };
		navigator.mediaDevices.getUserMedia(want).catch(function () { return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }); }).then(function (st) {
			if (!S || S.view !== 'cam') { st.getTracks().forEach(function (t) { t.stop(); }); return; }
			S.stream = st; v.srcObject = st;
			var tr = st.getVideoTracks()[0], caps = tr.getCapabilities ? tr.getCapabilities() : {};
			try { if (caps.focusMode && caps.focusMode.indexOf('continuous') >= 0) { tr.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }); } } catch (e) {}
			if (caps.torch) { var tb = S.root.querySelector('[data-a=torch]'); tb.hidden = false; }
			S.track = tr; S.ic = null;
			try { if (W.ImageCapture) { S.ic = new W.ImageCapture(tr); } } catch (e) { S.ic = null; }
			return v.play();
		}).then(function () {
			if (!S || !S.stream) { return; }
			hint.textContent = S.o.kind === 'card' ? 'Lay the card on a dark surface and hold the phone above it' : 'Hold the phone above the page, with the whole page in view';
			S.hist = []; S.armed = true;
			// a camera that only gives a small video picture (some iPhones/iPads): the camera app takes sharper pictures
			if (Math.min(v.videoWidth, v.videoHeight) < 1400 && !S.ic && S.o.kind === 'doc') { var nb = S.root.querySelector('[data-a=native] small'); if (nb) { nb.textContent = 'Sharper: camera app'; nb.parentNode.classList.add('is-tip'); } }
			tick();
		}).catch(function (e) {
			hint.textContent = e && e.name === 'NotAllowedError' ? 'The camera is blocked for this site. Allow it in the browser settings, or use “Camera app”.' : 'The camera could not start. Use “Camera app”.';
		});
	}
	/** Where the video picture sits on screen (object-fit: contain). */
	function vbox() {
		var v = S.v, r = v.getBoundingClientRect(), vw = v.videoWidth, vh = v.videoHeight;
		if (!vw) { return null; }
		var k = Math.min(r.width / vw, r.height / vh); return { x: (r.width - vw * k) / 2, y: (r.height - vh * k) / 2, k: k, w: r.width, h: r.height };
	}
	function frame(max) {
		var v = S.v, k = Math.min(1, max / Math.max(v.videoWidth, v.videoHeight)), c = canvas(v.videoWidth * k, v.videoHeight * k);
		c.getContext('2d').drawImage(v, 0, 0, c.width, c.height); return { c: c, k: k };
	}
	function drawQuad(q, k, state) {
		var ov = S.ov, b = vbox(), dpr = W.devicePixelRatio || 1;
		if (!b) { return; }
		if (ov.width !== Math.round(b.w * dpr)) { ov.width = Math.round(b.w * dpr); ov.height = Math.round(b.h * dpr); }
		var x = ov.getContext('2d'); x.setTransform(dpr, 0, 0, dpr, 0, 0); x.clearRect(0, 0, b.w, b.h);
		if (!q) { return; }
		var P = q.map(function (p) { return { x: b.x + p.x / k * b.k, y: b.y + p.y / k * b.k }; });
		x.beginPath(); P.forEach(function (p, i) { if (i) { x.lineTo(p.x, p.y); } else { x.moveTo(p.x, p.y); } }); x.closePath();
		x.fillStyle = state === 'go' ? 'rgba(0,160,90,.28)' : 'rgba(60,170,255,.18)'; x.fill();
		x.lineWidth = 3; x.strokeStyle = state === 'go' ? '#16c172' : '#4db3ff'; x.stroke();
		P.forEach(function (p) { x.beginPath(); x.arc(p.x, p.y, 7, 0, Math.PI * 2); x.fillStyle = '#fff'; x.fill(); });
	}
	function tick() {
		if (!S || S.view !== 'cam' || !S.stream) { return; }
		var f = frame(360), q = null;
		try { q = find(f.c, 360); } catch (e) { q = null; }
		var diag = Math.hypot(f.c.width, f.c.height), H = S.hist;
		H.push(q); if (H.length > 4) { H.shift(); }
		var still = q && H.length >= 3 && H.slice(-3).every(function (z) { return z && z.every(function (p, i) { return dist(p, q[i]) < diag * 0.012; }); });
		var big = q && area(q) > f.c.width * f.c.height * (S.o.kind === 'card' ? 0.08 : 0.18);
		if (!S.armed) {
			// after a picture: wait for the next page (the old one is taken away or the view changes)
			var last = S.lastQ;
			if (!q || !last || q.some(function (p, i) { return dist(p, last[i]) > diag * 0.08; })) { S.lost = (S.lost || 0) + 1; } else { S.lost = 0; }
			if (S.lost >= 3) { S.armed = true; S.lost = 0; }
		}
		drawQuad(q, f.k, still && big ? 'go' : '');
		if (!S.busy) {
			S.hint.textContent = !q ? (S.o.kind === 'card' ? 'Looking for the card…' : 'Looking for the page edges…') : (!big ? 'Move closer' : (!S.armed ? 'Next page, or tap Done' : (still ? (S.auto ? 'Hold still…' : 'Tap the button') : 'Hold steady')));
			if (S.auto && S.armed && still && big) {
				S.goN = (S.goN || 0) + 1;
				if (S.goN >= 2) { S.goN = 0; shoot(); return; }
			} else { S.goN = 0; }
		}
		S.loop = setTimeout(tick, 170);
	}
	function grab() {
		// The sharpest full picture we can get: a full-resolution photo where the browser offers one, else the video frame.
		var v = S.v, st = S.track && S.track.getSettings ? S.track.getSettings() : {}, px = (v.videoWidth || 0) * (v.videoHeight || 0);
		var still = function () { var c = canvas(v.videoWidth, v.videoHeight); c.getContext('2d').drawImage(v, 0, 0); return Promise.resolve(c); };
		if (S.ic && S.ic.takePhoto && px < 3800 * 2100) {
			return S.ic.getPhotoCapabilities().then(function (pc) {
				var o = {}; if (pc && pc.imageWidth && pc.imageWidth.max) { o.imageWidth = pc.imageWidth.max; } if (S.torch && pc && pc.fillLightMode && pc.fillLightMode.indexOf('flash') >= 0) { o.fillLightMode = 'flash'; }
				return Promise.race([S.ic.takePhoto(o), new Promise(function (_, no) { setTimeout(no, 4000); })]);
			}).then(function (b) { return W.createImageBitmap ? createImageBitmap(b) : loadBlob(b); }).then(function (im) {
				var c = fit(im); if (im.close) { im.close(); } return c;
			}).catch(function () { return S && S.v ? still() : Promise.reject(); });
		}
		void st; return still();
	}
	/** Keep big photos within what phones can hold in a canvas (about 16 megapixels). */
	function fit(im) { var w = im.width || im.naturalWidth, h = im.height || im.naturalHeight, k = Math.min(1, Math.sqrt(16000000 / (w * h)), 4096 / Math.max(w, h) * 1.25); if (k > 1) { k = 1; } var c = canvas(w * k, h * k), x = c.getContext('2d'); x.imageSmoothingQuality = 'high'; x.drawImage(im, 0, 0, c.width, c.height); return c; }
	function shoot() {
		if (!S || S.busy || !S.v || !S.v.videoWidth) { return; }
		S.busy = true; S.hint.textContent = 'Taking the picture…'; S.lastQ = S.hist[S.hist.length - 1] || null;
		var fl = S.root.querySelector('.csc-flash'); fl.classList.remove('is-on'); void fl.offsetWidth; fl.classList.add('is-on'); vib(30);
		grab().then(function (c) { return addPage(c); }).then(function () {
			if (!S) { return; }
			S.busy = false; S.armed = false; S.lost = 0;
			if (!S.o.multi) { review(); return; }
			camera_refresh();
		}).catch(function () { if (S) { S.busy = false; S.hint.textContent = 'That did not work — try again.'; } });
	}
	function camera_refresh() {
		// keep the camera running; only the bottom bar changes
		var bot = S.root.querySelector('.csc-bot'), pg = S.pages[S.pages.length - 1];
		bot.innerHTML = '<button type="button" class="csc-stack" data-a="review"><img alt="" src="' + pg.th + '"><i>' + S.pages.length + '</i></button><button type="button" class="csc-shut" data-a="shoot" aria-label="Take picture"><i></i></button><button type="button" class="csc-done" data-a="review">Done</button>';
		S.hint.textContent = 'Page ' + S.pages.length + ' saved. Next page, or tap Done';
	}
	function addPage(c) {
		var q = null;
		try { q = find(c, 400); if (q) { q = refine(c, q); } } catch (e) { q = null; }
		var pg = { _src: c, sw: c.width, sh: c.height, q: q || [{ x: 0, y: 0 }, { x: c.width, y: 0 }, { x: c.width, y: c.height }, { x: 0, y: c.height }], found: !!q, mode: S.mode, rot: 0 };
		S.pages.push(pg);
		return Promise.all([processPage(pg, S.o.kind), toBlob(c, 0.94).then(function (b) { pg.srcBlob = b; })]).then(function () { pg._src = null; return pg; });
	}
	function native() {
		var i = D.createElement('input'); i.type = 'file'; i.accept = 'image/*'; i.setAttribute('capture', 'environment');
		i.addEventListener('change', function () {
			var f = i.files && i.files[0]; if (!f) { return; }
			stopCam(); S.root.classList.remove('is-cam'); S.root.innerHTML = '<div class="csc-busy"><span class="csc-spin"></span><p>Finding the edges…</p></div>';
			(W.createImageBitmap ? createImageBitmap(f, { imageOrientation: 'from-image' }).catch(function () { return loadBlob(f); }) : loadBlob(f)).then(function (im) { return addPage(fit(im)); }).then(function () {
				var pg = S.pages[S.pages.length - 1]; if (!pg.found) { edit(S.pages.length - 1); } else { review(); }
			}).catch(function () { camera(); if (S) { S.hint.textContent = 'That picture could not be opened.'; } });
		});
		i.click();
	}

	/* ---------------- review all pages */
	function review() {
		stopCam(); S.view = 'review'; S.esrc = null; S.pre = null; S.root.classList.remove('is-cam');
		if (!S.pages.length) { camera(); return; }
		var n = S.pages.length;
		S.root.innerHTML =
			'<div class="csc-top"><button type="button" class="csc-ic" data-a="close" aria-label="Close">' + ICON.x + '</button><b>' + (n === 1 ? 'Check the picture' : n + ' pages') + '</b><span class="csc-sp"></span></div>' +
			'<div class="csc-rv">' + (n === 1 ? '<img class="csc-one" alt="Scanned page" src="' + S.pages[0].view + '">' :
				'<ol class="csc-grid">' + S.pages.map(function (p, i) { return '<li><button type="button" data-a="edit" data-i="' + i + '"><img alt="Page ' + (i + 1) + '" src="' + p.th + '"><span>' + (i + 1) + '</span>' + (p.found ? '' : '<em>check edges</em>') + '</button></li>'; }).join('') + '</ol>') +
			(S.pages.some(function (p) { return !p.found; }) ? '<p class="csc-warn">The edges were not found on ' + (n === 1 ? 'this picture' : 'a page marked “check edges”') + '. Tap ' + (n === 1 ? '“Adjust”' : 'it') + ' to set the corners.</p>' : '') + '</div>' +
			'<div class="csc-modes" role="group" aria-label="Look">' + MODES.map(function (m) { return '<button type="button" class="csc-chip' + (S.pages.every(function (p) { return p.mode === m[0]; }) ? ' is-on' : '') + '" data-a="modeall" data-m="' + m[0] + '">' + m[1] + '</button>'; }).join('') + '</div>' +
			'<div class="csc-bot csc-bot--rv">' +
			(n === 1 ? '<button type="button" class="csc-btn csc-btn--ghost" data-a="retake">Retake</button><button type="button" class="csc-btn csc-btn--ghost" data-a="edit" data-i="0">Adjust</button>' : '') +
			(S.o.multi ? '<button type="button" class="csc-btn csc-btn--ghost" data-a="more">+ Page</button>' : '') +
			'<button type="button" class="csc-btn" data-a="save">Save' + (n > 1 ? ' PDF' : '') + '</button></div>';
	}
	/* ---------------- one page: corners, look, turn, remove */
	function edit(i) {
		stopCam(); S.view = 'edit'; S.ei = i; S.root.classList.remove('is-cam');
		var pg = S.pages[i], n = S.pages.length;
		S.root.innerHTML =
			'<div class="csc-top"><button type="button" class="csc-chip" data-a="back">Back</button><b>' + (n > 1 ? (i + 1) + ' / ' + n : 'Adjust') + '</b><span class="csc-sp"></span>' +
			(n > 1 ? '<button type="button" class="csc-ic" data-a="mvl" aria-label="Move page earlier"' + (i ? '' : ' disabled') + '>' + ICON.left + '<small>Move</small></button><button type="button" class="csc-ic" data-a="mvr" aria-label="Move page later"' + (i < n - 1 ? '' : ' disabled') + '>' + ICON.right + '<small>Move</small></button>' : '') +
			'<button type="button" class="csc-ic" data-a="rot" aria-label="Turn">' + ICON.rot + '<small>Turn</small></button><button type="button" class="csc-ic" data-a="del" aria-label="Remove page">' + ICON.del + '<small>Remove</small></button></div>' +
			'<div class="csc-ed"><canvas class="csc-src"></canvas><canvas class="csc-lp" width="240" height="240" hidden></canvas></div>' +
			'<p class="csc-tip">Drag the corners to the edges of the ' + (S.o.kind === 'card' ? 'card' : 'page') + '. <button type="button" class="csc-link" data-a="full">Whole picture</button></p>' +
			'<div class="csc-modes" role="group" aria-label="Look">' + MODES.map(function (m) { return '<button type="button" class="csc-chip' + (pg.mode === m[0] ? ' is-on' : '') + '" data-a="mode" data-m="' + m[0] + '">' + m[1] + '</button>'; }).join('') + '</div>' +
			'<div class="csc-bot csc-bot--rv"><button type="button" class="csc-btn" data-a="apply">Done</button></div>';
		S.eq = pg.q.map(function (p) { return { x: p.x, y: p.y }; });
		S.pre = null; S.esrc = null;
		srcCanvas(pg).then(function (c) { if (!S || S.view !== 'edit' || S.ei !== i) { return; } S.esrc = c; paintEdit(); corners(); });
	}
	function edGeom() {
		var cv = S.root.querySelector('.csc-src'), wrap = cv.parentNode, pg = S.pages[S.ei], r = wrap.getBoundingClientRect(), k = Math.min(r.width / pg.sw, r.height / pg.sh);
		return { cv: cv, k: k, w: pg.sw * k, h: pg.sh * k, ox: (r.width - pg.sw * k) / 2, oy: (r.height - pg.sh * k) / 2 };
	}
	function paintEdit() {
		if (!S.esrc) { return; }
		var g = edGeom(), dpr = W.devicePixelRatio || 1, cv = g.cv;
		cv.style.left = g.ox + 'px'; cv.style.top = g.oy + 'px'; cv.style.width = g.w + 'px'; cv.style.height = g.h + 'px';
		if (cv.width !== Math.round(g.w * dpr)) { cv.width = Math.round(g.w * dpr); cv.height = Math.round(g.h * dpr); S.pre = null; }
		var x = cv.getContext('2d');
		if (!S.pre) { S.pre = canvas(cv.width, cv.height); var px = S.pre.getContext('2d'); px.imageSmoothingQuality = 'high'; px.drawImage(S.esrc, 0, 0, cv.width, cv.height); }
		x.setTransform(1, 0, 0, 1, 0, 0); x.drawImage(S.pre, 0, 0);
		var P = S.eq.map(function (p) { return { x: p.x * g.k * dpr, y: p.y * g.k * dpr }; });
		x.save(); x.fillStyle = 'rgba(0,0,0,.45)'; x.beginPath(); x.rect(0, 0, cv.width, cv.height); P.slice().reverse().forEach(function (p, i) { if (i) { x.lineTo(p.x, p.y); } else { x.moveTo(p.x, p.y); } }); x.closePath(); x.fill('evenodd'); x.restore();
		x.beginPath(); P.forEach(function (p, i) { if (i) { x.lineTo(p.x, p.y); } else { x.moveTo(p.x, p.y); } }); x.closePath(); x.lineWidth = 2.5 * dpr; x.strokeStyle = convex(S.eq) ? '#16c172' : '#ff5a4f'; x.stroke();
		P.forEach(function (p) { x.beginPath(); x.arc(p.x, p.y, 13 * dpr, 0, Math.PI * 2); x.fillStyle = 'rgba(255,255,255,.35)'; x.fill(); x.lineWidth = 3 * dpr; x.strokeStyle = '#fff'; x.stroke(); });
	}
	function corners() {
		var cv = S.root.querySelector('.csc-src'), lp = S.root.querySelector('.csc-lp'), drag = -1, pid = null;
		function at(e) { var r = cv.getBoundingClientRect(), g = edGeom(); return { x: (e.clientX - r.left) / g.k, y: (e.clientY - r.top) / g.k, sx: e.clientX - r.left, sy: e.clientY - r.top }; }
		function loupe(p) {
			var x = lp.getContext('2d'), g = edGeom(), sz = 240, srcW = 120 / (g.k * 2.5);
			lp.hidden = false; lp.style.left = Math.max(0, Math.min(g.w - 120, p.sx - 60)) + g.ox + 'px'; lp.style.top = Math.max(0, p.sy - 150) + g.oy + 'px';
			x.save(); x.clearRect(0, 0, sz, sz); x.beginPath(); x.arc(sz / 2, sz / 2, sz / 2 - 3, 0, Math.PI * 2); x.clip();
			x.fillStyle = '#000'; x.fillRect(0, 0, sz, sz);
			x.drawImage(S.esrc, p.x - srcW / 2, p.y - srcW / 2, srcW, srcW, 0, 0, sz, sz);
			x.strokeStyle = '#16c172'; x.lineWidth = 2; x.beginPath(); x.moveTo(sz / 2 - 18, sz / 2); x.lineTo(sz / 2 + 18, sz / 2); x.moveTo(sz / 2, sz / 2 - 18); x.lineTo(sz / 2, sz / 2 + 18); x.stroke();
			x.restore(); x.beginPath(); x.arc(sz / 2, sz / 2, sz / 2 - 3, 0, Math.PI * 2); x.lineWidth = 5; x.strokeStyle = '#fff'; x.stroke();
		}
		cv.addEventListener('pointerdown', function (e) {
			var p = at(e), g = edGeom(), best = -1, bd = 44 / g.k;
			S.eq.forEach(function (c, i) { var d = Math.hypot(c.x - p.x, c.y - p.y); if (d < bd) { bd = d; best = i; } });
			if (best < 0) { return; }
			drag = best; pid = e.pointerId; cv.setPointerCapture(pid); e.preventDefault(); loupe(p);
		});
		cv.addEventListener('pointermove', function (e) {
			if (drag < 0 || e.pointerId !== pid) { return; }
			var p = at(e), pg = S.pages[S.ei];
			S.eq[drag] = { x: Math.max(0, Math.min(pg.sw, p.x)), y: Math.max(0, Math.min(pg.sh, p.y)) };
			paintEdit(); loupe({ x: S.eq[drag].x, y: S.eq[drag].y, sx: S.eq[drag].x * edGeom().k, sy: S.eq[drag].y * edGeom().k });
		});
		var end = function () { drag = -1; lp.hidden = true; };
		cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);
	}
	function applyEdit() {
		var pg = S.pages[S.ei];
		if (!convex(S.eq)) { var t = S.root.querySelector('.csc-tip'); t.textContent = 'The corners cross over. Put each one on its own corner of the page.'; t.classList.add('is-bad'); return Promise.resolve(false); }
		pg.q = order(S.eq); pg.found = true;
		busy('Straightening…');
		return processPage(pg, S.o.kind).then(function () { S.pre = null; return true; });
	}
	function busy(t) { var b = D.createElement('div'); b.className = 'csc-busy csc-busy--over'; b.innerHTML = '<span class="csc-spin"></span><p>' + esc(t) + '</p>'; S.root.appendChild(b); }

	/* ---------------- clicks */
	function onClick(e) {
		var t = e.target.closest('[data-a]'); if (!t || !S || t.disabled) { return; }
		var a = t.getAttribute('data-a'), i = +t.getAttribute('data-i');
		if (a === 'close') { close(); return; }
		if (a === 'shoot') { S.armed = true; shoot(); return; }
		if (a === 'auto') { S.auto = !S.auto; try { localStorage.setItem('sdsc_camscan_auto', S.auto ? '1' : '0'); } catch (x) {} t.classList.toggle('is-on', S.auto); t.setAttribute('aria-pressed', S.auto ? 'true' : 'false'); return; }
		if (a === 'torch') { S.torch = !S.torch; t.classList.toggle('is-on', S.torch); try { S.track.applyConstraints({ advanced: [{ torch: S.torch }] }); } catch (x) {} return; }
		if (a === 'native') { native(); return; }
		if (a === 'review') { review(); return; }
		if (a === 'more') { camera(); return; }
		if (a === 'retake') { S.pages = []; camera(); return; }
		if (a === 'edit') { edit(i); return; }
		if (a === 'back') { S.pre = null; review(); return; }
		if (a === 'full') { var pg0 = S.pages[S.ei]; S.eq = [{ x: 0, y: 0 }, { x: pg0.sw, y: 0 }, { x: pg0.sw, y: pg0.sh }, { x: 0, y: pg0.sh }]; paintEdit(); return; }
		if (a === 'apply') { applyEdit().then(function (ok) { if (ok && S) { review(); } }); return; }
		if (a === 'mode' || a === 'rot') {
			var pg = S.pages[S.ei];
			if (a === 'mode') { pg.mode = t.getAttribute('data-m'); S.mode = pg.mode; S.root.querySelectorAll('[data-a=mode]').forEach(function (b) { b.classList.toggle('is-on', b === t); }); }
			else { pg.rot = (pg.rot + 90) % 360; }
			if (a === 'rot') { applyEdit().then(function (ok) { if (ok && S) { review(); } }); }
			return;
		}
		if (a === 'mvl' || a === 'mvr') { var j = S.ei + (a === 'mvl' ? -1 : 1), tmp = S.pages[j]; S.pages[j] = S.pages[S.ei]; S.pages[S.ei] = tmp; S.pre = null; edit(j); return; }
		if (a === 'del') { S.pages.splice(S.ei, 1); S.pre = null; if (S.pages.length) { review(); } else { camera(); } return; }
		if (a === 'modeall') {
			var m = t.getAttribute('data-m'); S.mode = m; try { localStorage.setItem('sdsc_camscan_mode_' + S.o.kind, m); } catch (x) {}
			busy('Applying…');
			S.pages.reduce(function (p, pg) { pg.mode = m; return p.then(function () { return processPage(pg, S.o.kind); }); }, Promise.resolve()).then(function () { if (S) { review(); } });
			return;
		}
		if (a === 'save') {
			busy(S.pages.length > 1 ? 'Making the PDF…' : 'Saving…');
			var o = S.o, kind = o.kind;
			build(S.pages, kind).then(function (f) { close(); return o.onDone ? o.onDone(f, f._thumb) : null; }).catch(function () { var b = S && S.root.querySelector('.csc-busy--over'); if (b) { b.remove(); } });
		}
	}

	W.SDSC_CAMSCAN = {
		supported: function () { return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && W.isSecureContext && W.Promise && W.URL); },
		open: function (o) {
			if (S) { close(); }
			o = o || {}; o.kind = o.kind === 'card' ? 'card' : 'doc';
			var mode = null, auto = true;
			try { mode = localStorage.getItem('sdsc_camscan_mode_' + o.kind); auto = localStorage.getItem('sdsc_camscan_auto') !== '0'; } catch (e) {}
			S = { o: o, pages: [], auto: auto, mode: mode && MODES.some(function (m) { return m[0] === mode; }) ? mode : (o.kind === 'card' ? 'photo' : 'clean') };
			shell().addEventListener('click', onClick);
			camera();
		},
		close: close,
		// for tests
		_find: find, _refine: refine, _homography: homography, _order: order, _proc: PROC, _outSize: outSize, _pdf: pdf, _sharp: sharp,
	};
})();
