/* Sam's Class Manager — living sky for the Maryland hero.
 *
 * Time of day follows the visitor's own clock: night, dawn, sunrise, morning, midday, golden hour, sunset, dusk.
 * The sun and moon are placed from their real positions for the visitor's date, time and time zone, and the moon
 * shows its real phase. The season follows the date (spring blossom, summer green, autumn leaves, bare winter
 * trees) and the weather is Rockville's current weather (clear, clouds, fog, rain, snow, thunderstorm), read from
 * the site's own /wp-json/sdsc/v1/weather endpoint. Every layer of the scene is re-lit to match.
 *
 * Preview: ?mdh_time=18:40  &mdh_date=2026-06-21  &mdh_wx=clear|clouds|fog|rain|snow|storm  &mdh_season=spring|summer|autumn|winter
 */
(function () {
	'use strict';

	var RAD = Math.PI / 180;
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var Q = new URLSearchParams(location.search);

	function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
	function smooth(a, b, v) { var t = clamp((v - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); }
	function bell(v, c, w) { var x = (v - c) / w; return Math.exp(-x * x); }
	function hex(h) { h = h.replace('#', ''); return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)]; }
	function lin(c) { c /= 255; return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
	function srgb(c) { c = c <= 0.0031308 ? c * 12.92 : 1.055 * Math.pow(c, 1 / 2.4) - 0.055; return Math.round(clamp(c, 0, 1) * 255); }
	// Mix in linear light so blends look like light, not paint.
	function mix(a, b, t) {
		a = typeof a === 'string' ? hex(a) : a; b = typeof b === 'string' ? hex(b) : b;
		return [srgb(lin(a[0]) + (lin(b[0]) - lin(a[0])) * t), srgb(lin(a[1]) + (lin(b[1]) - lin(a[1])) * t), srgb(lin(a[2]) + (lin(b[2]) - lin(a[2])) * t)];
	}
	function css(c, a) { return a === undefined ? 'rgb(' + c.join(',') + ')' : 'rgba(' + c.join(',') + ',' + a + ')'; }

	/* ------------------------------------------------------------ astronomy */

	// Where the visitor roughly is, from the browser's time zone (no permission prompt, nothing leaves the page).
	var ZONES = {
		'America/New_York': [39.08, -77.15], 'America/Detroit': [42.3, -83.0], 'America/Chicago': [41.88, -87.63],
		'America/Denver': [39.74, -104.99], 'America/Phoenix': [33.45, -112.07], 'America/Los_Angeles': [34.05, -118.24],
		'America/Anchorage': [61.2, -149.9], 'Pacific/Honolulu': [21.3, -157.86], 'America/Puerto_Rico': [18.47, -66.1],
		'America/Toronto': [43.65, -79.38], 'America/Mexico_City': [19.43, -99.13], 'America/El_Salvador': [13.69, -89.19],
		'America/Guatemala': [14.63, -90.51], 'America/Bogota': [4.71, -74.07], 'America/Lima': [-12.05, -77.04],
		'Europe/London': [51.5, -0.12], 'Europe/Madrid': [40.42, -3.7], 'Europe/Paris': [48.86, 2.35], 'Asia/Tehran': [35.69, 51.39]
	};
	function place() {
		var tz = '';
		try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
		if (ZONES[tz]) { return ZONES[tz]; }
		var lon = -new Date().getTimezoneOffset() / 4;
		return [lon < -30 ? 38 : 40, clamp(lon, -180, 180)];
	}
	function days(date) { return date.getTime() / 86400000 - 10957.5; } // days since J2000.0
	function eqToHorizon(ra, dec, d, lat, lon) {
		var gmst = (280.46061837 + 360.98564736629 * d) % 360;
		var H = (gmst + lon - ra) * RAD, la = lat * RAD, de = dec * RAD;
		var alt = Math.asin(Math.sin(la) * Math.sin(de) + Math.cos(la) * Math.cos(de) * Math.cos(H));
		var az = Math.atan2(-Math.sin(H), Math.tan(de) * Math.cos(la) - Math.sin(la) * Math.cos(H));
		return { alt: alt / RAD, az: (az / RAD + 360) % 360 };
	}
	function eclToEq(lam, beta, d) {
		var e = (23.439 - 0.0000004 * d) * RAD, l = lam * RAD, b = beta * RAD;
		var ra = Math.atan2(Math.sin(l) * Math.cos(e) - Math.tan(b) * Math.sin(e), Math.cos(l));
		var dec = Math.asin(Math.sin(b) * Math.cos(e) + Math.cos(b) * Math.sin(e) * Math.sin(l));
		return { ra: (ra / RAD + 360) % 360, dec: dec / RAD };
	}
	function heavens(date, lat, lon) {
		var d = days(date);
		var g = (357.529 + 0.98560028 * d) % 360, q = (280.459 + 0.98564736 * d) % 360;
		var sunLon = (q + 1.915 * Math.sin(g * RAD) + 0.02 * Math.sin(2 * g * RAD)) % 360;
		var se = eclToEq(sunLon, 0, d), sun = eqToHorizon(se.ra, se.dec, d, lat, lon);
		var L = (218.316 + 13.176396 * d) % 360, M = (134.963 + 13.064993 * d) % 360, F = (93.272 + 13.22935 * d) % 360;
		var moonLon = (L + 6.289 * Math.sin(M * RAD) + 360) % 360, moonLat = 5.128 * Math.sin(F * RAD);
		var me = eclToEq(moonLon, moonLat, d), moon = eqToHorizon(me.ra, me.dec, d, lat, lon);
		var elong = ((moonLon - sunLon) % 360 + 360) % 360; // 0 new, 180 full
		moon.lit = (1 - Math.cos(elong * RAD)) / 2;
		moon.waxing = elong < 180;
		return { sun: sun, moon: moon };
	}
	function seasonOf(date, lat) {
		var o = Q.get('mdh_season');
		if (/^(spring|summer|autumn|winter)$/.test(o || '')) { return o; }
		var md = (date.getMonth() + 1) * 100 + date.getDate();
		var s = md >= 1222 || md < 320 ? 'winter' : md < 621 ? 'spring' : md < 922 ? 'summer' : 'autumn';
		if (lat < 0) { s = { winter: 'summer', summer: 'winter', spring: 'autumn', autumn: 'spring' }[s]; }
		return s;
	}

	/* ------------------------------------------------------------ colour keys (by sun altitude) */

	// zenith, upper sky, horizon, sun-side glow
	var KEYS = [
		[-18, '#02050e', '#060c20', '#0c1631', '#0c1631'],
		[-12, '#030a1f', '#0b1a3e', '#1b2c58', '#2b305f'],
		[-7, '#0a1736', '#1d376e', '#5c6596', '#a86f72'],
		[-3, '#13285a', '#38558f', '#d4876a', '#ff8d4d'],
		[0.5, '#1f4383', '#5577b4', '#f2a067', '#ffae5c'],
		[4, '#2a5ea4', '#6b98cf', '#f4c08c', '#ffd18a'],
		[10, '#2e6cb6', '#78acdf', '#dcdcd2', '#fff2d2'],
		[22, '#2a6cc0', '#6aa6e2', '#c6def1', '#ffffff'],
		[55, '#1d60be', '#5a9de3', '#b6d6f1', '#ffffff']
	];
	function keyAt(alt) {
		if (alt <= KEYS[0][0]) { return KEYS[0].slice(1).map(hex); }
		for (var i = 1; i < KEYS.length; i++) {
			if (alt <= KEYS[i][0]) {
				var a = KEYS[i - 1], b = KEYS[i], t = smooth(a[0], b[0], alt);
				return [mix(a[1], b[1], t), mix(a[2], b[2], t), mix(a[3], b[3], t), mix(a[4], b[4], t)];
			}
		}
		return KEYS[KEYS.length - 1].slice(1).map(hex);
	}

	/* ------------------------------------------------------------ noise */

	function rng(seed) { return function () { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; }; }
	// Value noise that wraps every `per` cells, so the cloud field tiles seamlessly.
	function makeNoise(seed) {
		var r = rng(seed), S = 256, g = new Float32Array(S * S);
		for (var i = 0; i < S * S; i++) { g[i] = r(); }
		function v(x, y, per) { x = ((x % per) + per) % per; y = ((y % S) + S) % S; return g[y * S + (x % S)]; }
		return function (x, y, per) {
			var xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi;
			var u = xf * xf * (3 - 2 * xf), w = yf * yf * (3 - 2 * yf);
			var a = v(xi, yi, per), b = v(xi + 1, yi, per), c = v(xi, yi + 1, per), d = v(xi + 1, yi + 1, per);
			return a + (b - a) * u + (c - a) * w + (a - b - c + d) * u * w;
		};
	}

	/* ------------------------------------------------------------ the hero */

	function Hero(root, wx) {
		this.root = root;
		this.layer = root.querySelector('.mdh-sky');
		this.stage = root.querySelector('.mdh-stage');
		if (!this.layer || !this.stage) { return; }
		this.dpr = Math.min(window.devicePixelRatio || 1, 2);
		this.wx = wx;
		this.sky = this.canvas('mdh-cv-sky', this.layer);
		this.clouds = document.createElement('div');
		this.clouds.className = 'mdh-cv-clouds';
		this.cloudCv = document.createElement('canvas');
		this.clouds.appendChild(this.cloudCv);
		this.layer.appendChild(this.clouds);
		this.stars = this.canvas('mdh-cv-stars', this.layer);
		this.fx = this.canvas('mdh-cv-fx', this.stage);
		this.fog = document.createElement('div'); this.fog.className = 'mdh-fx-fog'; this.stage.appendChild(this.fog);
		this.flash = document.createElement('div'); this.flash.className = 'mdh-fx-flash'; this.stage.appendChild(this.flash);
		this.loc = place();
		var r = rng(20240521);
		this.starList = [];
		for (var i = 0; i < 420; i++) {
			var m = Math.pow(r(), 3.2), t = r();
			this.starList.push({ x: r(), y: Math.pow(r(), 1.35) * 0.62, m: m, c: t < 0.12 ? [255, 214, 170] : t < 0.3 ? [205, 220, 255] : [255, 250, 240], p: r() * 6.28, s: 0.6 + r() * 2.2 });
		}
		this.noise = makeNoise(7331);
		root.classList.add('has-sky');
		var self = this;
		this.resize();
		this.update(true);
		root.classList.remove('mdh-wait');
		window.addEventListener('resize', function () { clearTimeout(self.rt); self.rt = setTimeout(function () { self.resize(); self.update(true); }, 150); });
		setInterval(function () { self.update(false); }, 60000);
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { self.update(false); } });
		this.loop();
	}

	Hero.prototype.canvas = function (cls, parent) {
		var c = document.createElement('canvas');
		c.className = cls;
		c.setAttribute('aria-hidden', 'true');
		parent.appendChild(c);
		return c;
	};

	Hero.prototype.resize = function () {
		this.W = Math.max(1, Math.round(this.layer.clientWidth * this.dpr));
		this.H = Math.max(1, Math.round(this.layer.clientHeight * this.dpr));
		this.sky.width = this.stars.width = this.W; this.sky.height = this.stars.height = this.H;
		this.FW = Math.max(1, Math.round(this.stage.clientWidth * this.dpr));
		this.FH = Math.max(1, Math.round(this.stage.clientHeight * this.dpr));
		this.fx.width = this.FW; this.fx.height = this.FH;
		this.field = null; this.particles = null;
	};

	Hero.prototype.now = function () {
		var t = Q.get('mdh_time'), dt = Q.get('mdh_date'), d = new Date();
		if (dt && /^\d{4}-\d{2}-\d{2}$/.test(dt)) { var p = dt.split('-'); d.setFullYear(+p[0], +p[1] - 1, +p[2]); }
		if (t && /^\d{1,2}:\d{2}$/.test(t)) { var h = t.split(':'); d.setHours(+h[0], +h[1], 0, 0); }
		return d;
	};

	// Sky position (0..1 of the sky layer) for an altitude / azimuth: east on the left, west on the right.
	Hero.prototype.spot = function (alt, az) {
		var hz = 0.66;
		var x = clamp(0.5 + (az - 180) / 230, 0.05, 0.95);
		var k = alt >= 0 ? (1 - Math.exp(-alt / 20)) / (1 - Math.exp(-60 / 20)) : alt / 25;
		return { x: x, y: hz - (hz - 0.07) * clamp(k, -0.5, 1), hz: hz };
	};

	Hero.prototype.update = function (force) {
		var date = this.now(), h = heavens(date, this.loc[0], this.loc[1]), wx = this.wx;
		var key = Math.round(h.sun.alt * 2) / 2 + '|' + Math.round(h.sun.az) + '|' + Math.round(h.moon.alt) + '|' + this.W + '|' + wx.kind + wx.cover;
		if (!force && key === this.last) { return; }
		this.last = key;
		var wet = wx.kind === 'rain' || wx.kind === 'storm' || wx.kind === 'snow' || wx.kind === 'fog';
		// How much the cloud deck greys out the sky (0 clear … 1 solid overcast).
		var oc = wet ? Math.max(0.8, wx.cover) : smooth(0.55, 1, wx.cover) * 0.75;
		if (wx.kind === 'storm') { oc = 1; }
		this.state = { alt: h.sun.alt, sun: h.sun, moon: h.moon, oc: oc, season: seasonOf(date, this.loc[0]), date: date };
		this.paintSky();
		this.paintClouds();
		this.paintStars(performance.now() / 1000);
		this.relight();
		this.particles = null;
	};

	/* ------------------------------------------------------------ sky */

	Hero.prototype.paintSky = function () {
		var c = this.sky.getContext('2d'), W = this.W, H = this.H, st = this.state, alt = st.alt, oc = st.oc;
		var k = keyAt(alt), sp = this.spot(alt, st.sun.az), sx = sp.x * W, sy = sp.y * H, hz = sp.hz * H;
		if (oc > 0) {
			// Overcast: the whole sky turns to an even grey that still follows the light of the hour.
			var grey = mix(mix('#151a24', '#a4adb8', smooth(-8, 14, alt)), '#8d7a78', bell(alt, 0, 5) * 0.5);
			var greyTop = mix(grey, '#000000', 0.18);
			k = [mix(k[0], greyTop, oc * 0.9), mix(k[1], grey, oc * 0.9), mix(k[2], mix(grey, '#ffffff', 0.12), oc * 0.85), mix(k[3], grey, oc * 0.7)];
		}
		var g = c.createLinearGradient(0, 0, 0, hz);
		g.addColorStop(0, css(k[0])); g.addColorStop(0.55, css(k[1])); g.addColorStop(1, css(k[2]));
		c.fillStyle = g; c.fillRect(0, 0, W, H);
		c.fillStyle = css(k[2]); c.fillRect(0, hz, W, H - hz);

		var clear = 1 - oc * 0.75;
		var gi = (bell(alt, 1, 7) * 0.95 + bell(alt, -6, 4) * 0.35 + (alt > 8 ? 0.12 : 0)) * clear;
		c.save(); c.translate(sx, hz); c.scale(1, 0.42);
		var rg = c.createRadialGradient(0, 0, 0, 0, 0, W * 0.75);
		rg.addColorStop(0, css(k[3], 0.85 * gi)); rg.addColorStop(0.35, css(k[3], 0.35 * gi)); rg.addColorStop(1, css(k[3], 0));
		c.fillStyle = rg; c.fillRect(-W * 2, -H * 3, W * 4, H * 6);
		c.restore();

		var bv = bell(alt, -2.5, 3.2) * 0.4 * clear;
		if (bv > 0.01) {
			c.save(); c.translate((1 - sp.x) * W, hz - H * 0.07); c.scale(1, 0.25);
			var bg = c.createRadialGradient(0, 0, 0, 0, 0, W * 0.7);
			bg.addColorStop(0, 'rgba(226,150,170,' + bv + ')'); bg.addColorStop(1, 'rgba(226,150,170,0)');
			c.fillStyle = bg; c.fillRect(-W * 2, -H * 4, W * 4, H * 8);
			c.restore();
		}
		var hzA = smooth(-2, 15, alt) * (0.35 + oc * 0.2);
		if (hzA > 0) {
			var hg = c.createLinearGradient(0, hz - H * 0.28, 0, hz);
			hg.addColorStop(0, 'rgba(235,242,250,0)'); hg.addColorStop(1, 'rgba(235,242,250,' + hzA + ')');
			c.fillStyle = hg; c.fillRect(0, hz - H * 0.28, W, H * 0.28);
		}
		var mw = smooth(-14, -18, alt) * (1 - st.moon.lit * smooth(-5, 10, st.moon.alt) * 0.8) * (1 - Math.min(1, this.wx.cover * 1.3));
		if (mw > 0.02) {
			c.save(); c.globalCompositeOperation = 'screen';
			c.translate(W * 0.62, H * 0.05); c.rotate(0.55);
			for (var i = 0; i < 26; i++) {
				var px = (i / 25 - 0.5) * W * 1.4, w = W * (0.06 + 0.05 * Math.sin(i * 1.7));
				var mg = c.createRadialGradient(px, 0, 0, px, 0, w);
				mg.addColorStop(0, 'rgba(170,180,215,' + (0.05 * mw) + ')'); mg.addColorStop(1, 'rgba(170,180,215,0)');
				c.fillStyle = mg; c.fillRect(px - w, -w, w * 2, w * 2);
			}
			c.restore();
		}
		if (st.moon.alt > -3 && oc < 0.95) { this.paintMoon(c, 1 - oc); }
		if (alt > -2.5) { this.paintSun(c, sx, sy, alt, 1 - oc * 0.92); }
	};

	Hero.prototype.paintSun = function (c, x, y, alt, vis) {
		if (vis <= 0.03) { return; }
		var W = this.W, low = 1 - smooth(-1, 14, alt);
		var r = clamp(W * 0.017, 13 * this.dpr, 34 * this.dpr) * (1 + low * 0.12);
		var core = mix('#fffdf6', '#ff9a4a', low), mid = mix('#fff1c9', '#ff7a3a', low);
		c.save(); c.globalAlpha = vis;
		[[r * 14, 0.10 + low * 0.10], [r * 6, 0.22 + low * 0.08], [r * 2.6, 0.45]].forEach(function (b) {
			var g = c.createRadialGradient(x, y, r * 0.6, x, y, b[0]);
			g.addColorStop(0, css(mid, b[1])); g.addColorStop(1, css(mid, 0));
			c.fillStyle = g; c.fillRect(x - b[0], y - b[0], b[0] * 2, b[0] * 2);
		});
		c.translate(x, y); c.scale(1, 1 - low * 0.14);
		var d = c.createRadialGradient(0, 0, 0, 0, 0, r);
		d.addColorStop(0, css(core)); d.addColorStop(0.7, css(core)); d.addColorStop(1, css(mid));
		c.fillStyle = d; c.beginPath(); c.arc(0, 0, r, 0, Math.PI * 2); c.fill();
		c.restore();
	};

	Hero.prototype.paintMoon = function (c, clearness) {
		var st = this.state, m = st.moon, sp = this.spot(m.alt, m.az), W = this.W, H = this.H;
		var x = sp.x * W, y = sp.y * H, r = clamp(W * 0.012, 10 * this.dpr, 22 * this.dpr);
		var day = smooth(-6, 8, st.alt), vis = clamp((m.alt + 3) / 4, 0, 1) * (1 - day * 0.6) * clearness;
		if (vis <= 0.02 || m.lit < 0.03) { return; }
		var gr = r * (5 + m.lit * 4), ga = 0.22 * m.lit * (1 - day) * vis;
		var g = c.createRadialGradient(x, y, r, x, y, gr);
		g.addColorStop(0, 'rgba(210,222,255,' + ga + ')'); g.addColorStop(1, 'rgba(210,222,255,0)');
		c.fillStyle = g; c.fillRect(x - gr, y - gr, gr * 2, gr * 2);
		var s = Math.ceil(r * 2 + 4), o = document.createElement('canvas'); o.width = o.height = s;
		var q = o.getContext('2d'), cx = s / 2;
		// Lit part only: the disc, minus the night side (an ellipse whose width follows the phase).
		q.save();
		q.beginPath(); q.arc(cx, cx, r, 0, Math.PI * 2); q.clip();
		var surf = q.createRadialGradient(cx - r * 0.3, cx - r * 0.3, r * 0.1, cx, cx, r);
		surf.addColorStop(0, '#fbf8ee'); surf.addColorStop(1, '#d6d1c1');
		q.fillStyle = surf; q.fillRect(0, 0, s, s);
		[[-0.25, -0.28, 0.32, 0.22], [0.22, -0.18, 0.24, 0.2], [0.05, 0.22, 0.3, 0.18], [-0.32, 0.12, 0.18, 0.14], [0.35, 0.3, 0.12, 0.1]].forEach(function (a) {
			q.fillStyle = 'rgba(128,126,118,.26)';
			q.beginPath(); q.ellipse(cx + a[0] * r, cx + a[1] * r, a[2] * r, a[3] * r, 0.4, 0, Math.PI * 2); q.fill();
		});
		q.globalCompositeOperation = 'destination-out';
		var lit = m.lit, litRight = m.waxing; // northern-hemisphere view: waxing moon is lit on the right
		var ex = Math.abs(1 - 2 * lit) * r;   // terminator half-width
		q.beginPath();
		if (litRight) {
			q.rect(0, 0, cx, s);                                           // left half dark…
			if (lit < 0.5) { q.ellipse(cx, cx, ex, r + 1, 0, 0, Math.PI * 2); } // …plus a crescent's worth more
		} else {
			q.rect(cx, 0, s - cx, s);
			if (lit < 0.5) { q.ellipse(cx, cx, ex, r + 1, 0, 0, Math.PI * 2); }
		}
		q.fill();
		if (lit >= 0.5) {
			// Gibbous: give back the lit bulge on the dark half.
			q.globalCompositeOperation = 'source-over';
			q.save(); q.beginPath(); q.ellipse(cx, cx, ex, r, 0, 0, Math.PI * 2); q.clip();
			q.fillStyle = surf; q.fillRect(litRight ? 0 : cx, 0, cx, s);
			q.restore();
		}
		q.restore();
		// Faint earthshine on the dark side.
		c.save(); c.globalAlpha = vis * 0.12 * (1 - day);
		c.fillStyle = '#9fb0d8'; c.beginPath(); c.arc(x, y, r, 0, Math.PI * 2); c.fill();
		c.globalAlpha = vis; c.drawImage(o, x - cx, y - cx);
		c.restore();
	};

	/* ------------------------------------------------------------ clouds */

	// Shape is computed once per size / cloudiness and cached; colour is re-applied whenever the light changes.
	Hero.prototype.cloudField = function (w, h) {
		var cover = this.wx.cover, side = this.state.sun.az < 180 ? -1 : 1;
		var id = w + 'x' + h + '|' + cover + '|' + side;
		if (this.field && this.field.id === id) { return this.field; }
		var n = this.noise, N = 6, a = new Float32Array(w * h), l = new Float32Array(w * h);
		function fbm(u, v) {
			var s = 0, amp = 0.53, f = 1;
			for (var o = 0; o < 6; o++) { s += amp * n(u * f, v * f, N * f); amp *= 0.5; f *= 2; }
			return s;
		}
		function dens(u, v) {
			var qx = fbm(u + 5.2, v + 1.3) - 0.5, qy = fbm(u + 1.7, v + 9.2) - 0.5;
			return fbm(u + 1.25 * qx, v + 1.25 * qy);
		}
		var th = 0.585 - cover * 0.2, lx = side * 0.16;
		for (var y = 0; y < h; y++) {
			var yy = y / h, prof = smooth(0.0, 0.22, yy) * (1 - smooth(0.62, 0.98, yy));
			var v = y / w * N + 3;
			for (var x = 0; x < w; x++) {
				var u = x / w * N, d = dens(u, v);
				var al = smooth(th, th + 0.15, d) * prof;
				var i = y * w + x;
				a[i] = al;
				if (al > 0) {
					var d2 = dens(u + lx, v - 0.16);
					l[i] = clamp(0.62 + (d - d2) * 5.5 - yy * 0.25, 0, 1);
				}
			}
		}
		this.field = { id: id, a: a, l: l };
		return this.field;
	};

	Hero.prototype.paintClouds = function () {
		var st = this.state, alt = st.alt, oc = st.oc, wx = this.wx;
		var el = this.clouds, cw = Math.round(clamp(el.clientWidth / 2, 300, 900)) * 2, w = cw / 2;
		var h = Math.max(40, Math.round(cw * el.clientHeight / Math.max(1, el.clientWidth)));
		var f = this.cloudField(w, h), cv = this.cloudCv;
		cv.width = cw; cv.height = h;
		var c = cv.getContext('2d'), img = c.createImageData(w, h), data = img.data;
		var day = smooth(0, 12, alt), gold = bell(alt, 2, 5);
		var lit = mix(mix('#39425f', '#ffb27a', gold), '#ffffff', day);
		var shade = mix(mix('#0d1326', '#86677f', bell(alt, 0, 5)), '#a9b8cc', smooth(-1, 12, alt));
		if (alt < -8 && st.moon.alt > 0) { lit = mix(lit, '#8e9bbf', st.moon.lit * 0.6); }
		if (oc > 0.4) { // rain clouds: heavier and greyer
			var storm = wx.kind === 'storm' ? 0.35 : 0;
			lit = mix(lit, mix('#20242d', '#c3c9d1', smooth(-6, 14, alt)), oc * 0.7);
			shade = mix(shade, mix('#0b0d12', '#5d6571', smooth(-6, 14, alt)), oc * 0.8 + storm);
		}
		var aMax = (0.7 + smooth(0, 15, alt) * 0.25) * 255;
		for (var i = 0; i < w * h; i++) {
			var a = f.a[i];
			if (a <= 0) { continue; }
			var t = f.l[i], j = i * 4;
			data[j] = shade[0] + (lit[0] - shade[0]) * t;
			data[j + 1] = shade[1] + (lit[1] - shade[1]) * t;
			data[j + 2] = shade[2] + (lit[2] - shade[2]) * t;
			data[j + 3] = a * aMax;
		}
		c.putImageData(img, 0, 0);
		c.drawImage(cv, 0, 0, w, h, w, 0, w, h); // second, identical half so the drift loops seamlessly
		el.style.animationDuration = Math.round(clamp(900 - (wx.wind || 8) * 18, 240, 900)) + 's';
	};

	/* ------------------------------------------------------------ stars */

	Hero.prototype.paintStars = function (t) {
		var c = this.stars.getContext('2d'), W = this.W, H = this.H, st = this.state;
		var a = smooth(-4, -13, st.alt) * (1 - st.moon.lit * smooth(0, 20, st.moon.alt) * 0.45) * (1 - Math.min(1, this.wx.cover * 1.15)) * (1 - st.oc);
		c.clearRect(0, 0, W, H);
		this.starsOn = a > 0.01;
		if (!this.starsOn) { return; }
		var d = this.dpr;
		for (var i = 0; i < this.starList.length; i++) {
			var s = this.starList[i];
			var tw = reduce ? 1 : 0.72 + 0.28 * Math.sin(t * s.s + s.p);
			var al = a * (0.18 + 0.82 * s.m) * tw * (1 - s.y * 0.6), r = (0.35 + s.m * 1.25) * d;
			c.fillStyle = css(s.c, al);
			c.beginPath(); c.arc(s.x * W, s.y * H, r, 0, 6.283); c.fill();
			if (s.m > 0.55) {
				var g = c.createRadialGradient(s.x * W, s.y * H, 0, s.x * W, s.y * H, r * 4);
				g.addColorStop(0, css(s.c, al * 0.35)); g.addColorStop(1, css(s.c, 0));
				c.fillStyle = g; c.fillRect(s.x * W - r * 4, s.y * H - r * 4, r * 8, r * 8);
			}
		}
	};

	/* ------------------------------------------------------------ rain, snow, leaves, petals, fireflies */

	Hero.prototype.makeParticles = function () {
		var st = this.state, wx = this.wx, W = this.FW, H = this.FH, d = this.dpr, r = rng(99), list = [], kind = 'none';
		var amt = clamp(wx.amount || 0.4, 0.15, 1), area = W * H / (d * d) / (1440 * 700);
		if (wx.kind === 'rain' || wx.kind === 'storm') {
			kind = 'rain';
			var nR = Math.round((140 + amt * 420) * area);
			for (var i = 0; i < nR; i++) { list.push({ x: r() * W, y: r() * H, z: 0.4 + r() * 0.6 }); }
		} else if (wx.kind === 'snow') {
			kind = 'snow';
			var nS = Math.round((90 + amt * 260) * area);
			for (var k = 0; k < nS; k++) { list.push({ x: r() * W, y: r() * H, z: 0.35 + r() * 0.65, p: r() * 6.28 }); }
		} else if (st.season === 'autumn' && wx.kind !== 'fog') {
			kind = 'leaf';
			var cols = ['#c8551b', '#e08a1e', '#b8321c', '#d9a320', '#9c4a1a'];
			for (var a = 0; a < Math.round(16 * area + 6); a++) { list.push({ x: r() * W, y: r() * H, z: 0.5 + r() * 0.5, p: r() * 6.28, rot: r() * 6.28, c: cols[a % cols.length] }); }
		} else if (st.season === 'spring' && wx.kind !== 'fog') {
			kind = 'petal';
			for (var b = 0; b < Math.round(22 * area + 6); b++) { list.push({ x: r() * W, y: r() * H, z: 0.4 + r() * 0.6, p: r() * 6.28, rot: r() * 6.28 }); }
		} else if (st.season === 'summer' && st.alt < -3 && wx.kind === 'clear') {
			kind = 'fly';
			for (var e = 0; e < 26; e++) { list.push({ x: r() * W, y: H * (0.55 + r() * 0.25), p: r() * 6.28, s: 0.5 + r() }); }
		}
		this.particles = { kind: kind, list: list, wind: clamp((wx.wind || 8) / 40, 0.05, 1) };
	};

	Hero.prototype.drawParticles = function (dt, t) {
		if (!this.particles) { this.makeParticles(); }
		var P = this.particles, c = this.fx.getContext('2d'), W = this.FW, H = this.FH, d = this.dpr, st = this.state;
		c.clearRect(0, 0, W, H);
		if (P.kind === 'none') { return false; }
		var day = smooth(-8, 12, st.alt), i, p;
		if (P.kind === 'rain') {
			var col = 'rgba(' + (day > 0.5 ? '205,215,230' : '160,175,205') + ',', sl = P.wind * 0.35 + 0.12;
			c.lineCap = 'round';
			for (i = 0; i < P.list.length; i++) {
				p = P.list[i];
				var v = (900 + p.z * 700) * d * dt, len = (10 + p.z * 18) * d;
				p.y += v; p.x += v * sl;
				if (p.y > H) { p.y -= H + len; p.x = Math.random() * W; }
				if (p.x > W) { p.x -= W; }
				c.strokeStyle = col + (0.12 + p.z * 0.28) + ')'; c.lineWidth = (0.6 + p.z * 0.8) * d;
				c.beginPath(); c.moveTo(p.x, p.y); c.lineTo(p.x - len * sl, p.y - len); c.stroke();
			}
		} else if (P.kind === 'snow') {
			for (i = 0; i < P.list.length; i++) {
				p = P.list[i];
				p.y += (22 + p.z * 55) * d * dt; p.x += (Math.sin(t * 0.8 + p.p) * 14 + P.wind * 30) * p.z * d * dt;
				if (p.y > H) { p.y = -4; p.x = Math.random() * W; }
				if (p.x > W) { p.x -= W; } if (p.x < 0) { p.x += W; }
				c.fillStyle = 'rgba(255,255,255,' + (0.45 + p.z * 0.5) + ')';
				c.beginPath(); c.arc(p.x, p.y, (0.8 + p.z * 2.2) * d, 0, 6.283); c.fill();
			}
		} else if (P.kind === 'leaf' || P.kind === 'petal') {
			var leaf = P.kind === 'leaf';
			for (i = 0; i < P.list.length; i++) {
				p = P.list[i];
				p.y += (leaf ? 30 + p.z * 40 : 18 + p.z * 26) * d * dt;
				p.x += (Math.sin(t * (leaf ? 0.9 : 1.3) + p.p) * (leaf ? 40 : 26) + 25 * P.wind) * d * dt;
				p.rot += dt * (leaf ? 1.6 : 2.4) * (p.z - 0.2);
				if (p.y > H + 20) { p.y = -20; p.x = Math.random() * W; }
				if (p.x > W + 20) { p.x = -20; }
				var sz = (leaf ? 5 + p.z * 5 : 3 + p.z * 3) * d, flip = Math.cos(t * 2 + p.p);
				c.save(); c.translate(p.x, p.y); c.rotate(p.rot); c.scale(1, 0.35 + Math.abs(flip) * 0.65);
				c.globalAlpha = 0.55 + day * 0.4;
				c.fillStyle = leaf ? p.c : (flip > 0 ? '#f7c6d6' : '#f2a9c0');
				c.beginPath(); c.moveTo(-sz, 0); c.quadraticCurveTo(0, -sz * 0.75, sz, 0); c.quadraticCurveTo(0, sz * 0.75, -sz, 0); c.fill();
				if (leaf) { c.strokeStyle = 'rgba(70,30,10,.45)'; c.lineWidth = 0.6 * d; c.beginPath(); c.moveTo(-sz, 0); c.lineTo(sz, 0); c.stroke(); }
				c.restore();
			}
		} else if (P.kind === 'fly') {
			for (i = 0; i < P.list.length; i++) {
				p = P.list[i];
				p.x += Math.sin(t * 0.5 * p.s + p.p) * 12 * d * dt; p.y += Math.cos(t * 0.7 * p.s + p.p) * 8 * d * dt;
				var gl = Math.max(0, Math.sin(t * p.s + p.p)), rr = 9 * d;
				var g = c.createRadialGradient(p.x, p.y, 0, p.x, p.y, rr);
				g.addColorStop(0, 'rgba(232,255,140,' + (0.85 * gl) + ')'); g.addColorStop(1, 'rgba(232,255,140,0)');
				c.fillStyle = g; c.fillRect(p.x - rr, p.y - rr, rr * 2, rr * 2);
			}
		}
		return true;
	};

	Hero.prototype.loop = function () {
		var self = this, last = performance.now(), lastStars = 0, nextBolt = performance.now() + 4000;
		if (reduce) { this.drawParticles(0, 0); return; }
		function frame(now) {
			requestAnimationFrame(frame);
			if (self.root.classList.contains('is-paused') || document.hidden) { last = now; return; }
			var dt = Math.min(0.05, (now - last) / 1000);
			if (dt < 0.03) { return; } // ~30 fps is plenty for weather
			last = now;
			self.drawParticles(dt, now / 1000);
			if (self.starsOn && now - lastStars > 66) { lastStars = now; self.paintStars(now / 1000); }
			if (self.wx.kind === 'storm' && now > nextBolt) {
				nextBolt = now + 5000 + Math.random() * 9000;
				var f = self.flash;
				f.classList.remove('is-on'); void f.offsetWidth; f.classList.add('is-on');
			}
		}
		requestAnimationFrame(frame);
	};

	/* ------------------------------------------------------------ re-light the rest of the scene */

	var SEASON = {
		spring: { leaf1: '#6fae4f', leaf2: '#f0b4c9', ground: '#355a33', canopy: 1, susan: 0.35 },
		summer: { leaf1: '#2e6b33', leaf2: '#3f7e38', ground: '#2c4a2b', canopy: 1, susan: 1 },
		autumn: { leaf1: '#c4621f', leaf2: '#d6a224', ground: '#4b4329', canopy: 0.95, susan: 0.8 },
		winter: { leaf1: '#6b5a4c', leaf2: '#6b5a4c', ground: '#56544e', canopy: 0.22, susan: 0 }
	};

	Hero.prototype.relight = function () {
		var st = this.state, alt = st.alt, oc = st.oc, wx = this.wx, R = this.root.style;
		var amb = smooth(-9, 14, alt) * (1 - oc * 0.28), warm = bell(alt, 2, 6.5) * (1 - oc * 0.8);
		var lights = 1 - smooth(-2, 6 + oc * 10, alt);
		function set(n, v) { R.setProperty('--mdh-' + n, v); }
		function tint(night, day, warmC, wk) { return css(mix(mix(night, day, amb), warmC, warm * (wk || 0.35))); }
		var k = keyAt(alt), se = SEASON[st.season] || SEASON.summer;
		var snow = wx.kind === 'snow' ? clamp(0.55 + (wx.amount || 0) * 0.45, 0, 1) : 0;
		var dark = '#0a1527';
		set('bld', tint('#111c36', '#76879f', '#8b5f5c'));
		set('bld2', tint('#1a2a52', '#8696ab', '#9a6a62'));
		set('bridge', tint('#2c4475', '#8e9db2', '#a27668'));
		set('shore', tint('#0a1527', '#2b2a24', '#3b3122', 0.5));
		set('leaf1', css(mix(mix(dark, mix(se.leaf1, '#e9eef4', snow * 0.7), 0.15 + amb * 0.85), '#ffb070', warm * 0.25)));
		set('leaf2', css(mix(mix(dark, mix(se.leaf2, '#e9eef4', snow * 0.7), 0.15 + amb * 0.85), '#ffb070', warm * 0.25)));
		set('ground', css(mix(mix(dark, mix(se.ground, '#e8edf3', snow), 0.12 + amb * 0.88), '#ffb070', warm * 0.2)));
		set('canopy', String(snow ? Math.max(se.canopy, 0.5) : se.canopy));
		set('susan', String(se.susan));
		set('win', (0.1 + lights * 0.9).toFixed(3));
		set('lights', lights.toFixed(3));
		set('day', amb.toFixed(3));
		set('w1', css(mix(mix(mix('#2a3b70', '#7fb1db', amb), k[3], warm * 0.55), '#7c838c', oc * 0.6)));
		set('w2', css(mix(mix(mix('#1b2d5c', '#3f78ad', amb), '#b0684e', warm * 0.3), '#555c66', oc * 0.6)));
		set('w3', css(mix(mix('#0f1f42', '#22507f', amb), '#353b44', oc * 0.6)));
		set('a1', css(mix('#2d323a', '#575c64', amb)));
		set('a2', css(mix('#1b1f25', '#383c43', amb)));
		set('haze', css(k[2], (0.12 + amb * 0.22 + oc * 0.15).toFixed(3)));
		set('scene', (0.78 + amb * 0.3).toFixed(3));
		set('wet', (wx.kind === 'rain' || wx.kind === 'storm' ? 0.4 + (wx.amount || 0) * 0.6 : 0).toFixed(3));
		set('fog', (wx.kind === 'fog' ? 0.85 : oc * 0.18).toFixed(3));
		set('fogc', css(mix('#1d2433', '#d9dee5', smooth(-8, 12, alt))));
		var src = alt > -1 && oc < 0.7 ? st.sun : (st.moon.alt > 0 && oc < 0.7 ? st.moon : null);
		if (src) {
			var p = this.spot(src.alt, src.az);
			set('glx', (p.x * 100).toFixed(2) + '%');
			set('glc', src === st.sun ? css(mix('#fff4d6', '#ffb35c', 1 - smooth(-1, 16, alt))) : 'rgb(214,226,255)');
			set('glo', (src === st.sun ? 0.35 + warm * 0.5 + amb * 0.2 : st.moon.lit * 0.6) * (1 - oc) + '');
		} else { set('glo', '0'); }
		var tod = alt < -12 ? 'night' : alt < -4 ? (st.sun.az < 180 ? 'dawn' : 'dusk') : alt < 6 ? (st.sun.az < 180 ? 'sunrise' : 'sunset') : alt < 18 ? (st.sun.az < 180 ? 'morning' : 'evening') : 'day';
		this.root.setAttribute('data-tod', tod);
		this.root.setAttribute('data-season', st.season);
		this.root.setAttribute('data-wx', wx.kind);
	};

	/* ------------------------------------------------------------ start */

	function weather(cb) {
		var o = Q.get('mdh_wx');
		var preset = { clear: [0.1, 0], clouds: [0.7, 0], fog: [0.95, 0], rain: [1, 0.6], snow: [1, 0.6], storm: [1, 1] };
		if (preset[o]) { cb({ kind: o, cover: preset[o][0], amount: preset[o][1], wind: o === 'storm' ? 35 : 12 }); return; }
		var fallback = { kind: 'clear', cover: 0.25, amount: 0, wind: 8 }, done = false;
		function finish(w) { if (!done) { done = true; cb(w); } }
		var url = window.SDSC_SKY && window.SDSC_SKY.wx;
		if (!url || !window.fetch) { finish(fallback); return; }
		setTimeout(function () { finish(fallback); }, 1500);
		fetch(url, { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (w) {
			finish(w && w.kind ? { kind: w.kind, cover: +w.cover || 0, amount: +w.amount || 0, wind: +w.wind || 8 } : fallback);
		}).catch(function () { finish(fallback); });
	}

	function boot() {
		var els = document.querySelectorAll('[data-mdh]');
		if (!els.length) { return; }
		weather(function (wx) {
			[].forEach.call(els, function (el) { try { new Hero(el, wx); } catch (e) { el.classList.remove('mdh-wait'); if (window.console) { console.warn(e); } } });
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
