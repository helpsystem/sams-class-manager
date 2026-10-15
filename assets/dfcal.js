/* Sam's Driving School — lesson calendar (replaces DriveFlow's fixed 2-hour grid). Times are school time. */
(function () {
	'use strict';
	var C = window.SDSC_CAL;
	if (!C) { return; }

	/* ------------------------------------------------------------ take DriveFlow's place, before its script runs */
	// This file is printed in the footer before DriveFlow's admin.js, so the page is already there: removing
	// DriveFlow's board now stops its old calendar (and its 20-second reloads) from starting at all.
	var booted = false;
	function boot() {
		if (booted) { return; }
		var board = document.getElementById('df-calendar-board');
		if (!board) { return; }
		booted = true;
		if (!board) { return; }
		var wrap = board.closest('.wrap') || board.parentNode;
		// WordPress moves admin notices next to the first heading — give them their own place above the banner.
		if (!wrap.querySelector('.wp-header-end')) { var hr = document.createElement('hr'); hr.className = 'wp-header-end'; wrap.insertBefore(hr, wrap.firstChild); }
		['.df-cal-view-modes-bar', '.df-calendar-toolbar', '.df-calendar-legend', '#df-assign-tray', '#df-calendar-board', '#df-block-modal'].forEach(function (s) { var n = document.querySelector(s); if (n) { n.parentNode.removeChild(n); } });
		var banner = wrap.querySelector('.df-header-banner');
		if (banner) {
			banner.classList.add('scal-banner');
			var h = banner.querySelector('h1'); if (h) { h.textContent = 'Lesson calendar'; }
			var p = banner.querySelector('.df-header-title p'); if (p) { p.textContent = 'Every behind-the-wheel lesson at its real time. Lessons are 2 hours. Drag a lesson to move it, click it for details, click empty time to book or block it.'; }
			var b = banner.querySelector('[data-df-open-modal="df-add-session-modal"]'); if (b) { b.innerHTML = '<span aria-hidden="true">＋</span> Schedule lesson'; }
			var k = document.getElementById('df-open-block-modal-btn'); if (k) { k.id = 'scal-block-btn'; k.innerHTML = '<span aria-hidden="true">⛔</span> Block time'; }
		}
		var root = document.createElement('div'); root.id = 'scal'; root.className = 'scal';
		(banner ? banner.parentNode : wrap).insertBefore(root, banner ? banner.nextSibling : wrap.firstChild);
		start(root);
	}


	/* ------------------------------------------------------------ helpers (all times are "naive" school-time values) */
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function parse(s) { var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || ''); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : NaN; }
	function dkey(t) { var d = new Date(t); return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
	function stamp(t) { var d = new Date(t); return dkey(t) + ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()); }
	function dayStart(t) { var d = new Date(t); return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()); }
	function addDays(t, n) { var d = new Date(t); return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + n); }
	function mins(t) { var d = new Date(t); return d.getUTCHours() * 60 + d.getUTCMinutes(); }
	var DN = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], MN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	var MNL = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	function hm(t) { var d = new Date(t), h = d.getUTCHours(), m = d.getUTCMinutes(); return ((h + 11) % 12 + 1) + (m ? ':' + pad(m) : '') + (h < 12 ? ' AM' : ' PM'); }
	function hmShort(t) { var d = new Date(t), h = d.getUTCHours(), m = d.getUTCMinutes(); return ((h + 11) % 12 + 1) + (m ? ':' + pad(m) : ''); }
	function dlong(t) { var d = new Date(t); return DN[d.getUTCDay()] + ', ' + MN[d.getUTCMonth()] + ' ' + d.getUTCDate(); }
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
	function monday(t) { var d = new Date(t); return addDays(dayStart(t), -((d.getUTCDay() + 6) % 7)); }
	function store(k, v) { try { if (v === undefined) { return JSON.parse(localStorage.getItem('scal_' + C.user + '_' + k)); } localStorage.setItem('scal_' + C.user + '_' + k, JSON.stringify(v)); } catch (e) { return null; } }
	function post(action, data) {
		var fd = new FormData(); fd.append('action', 'sdsc_cal_' + action); fd.append('_n', C.nonce);
		Object.keys(data || {}).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) { fd.append(k, data[k]); } });
		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json().catch(function () { return { success: false, data: { msg: 'The server did not answer (' + r.status + ').' } }; }); });
	}

	var OPEN = C.open * 60, CLOSE = C.close * 60, DAY = (C.day || C.open) * 60, STEP = 30, ROW = 26; // px per 30 minutes
	var STATUS = { upcoming: 'Booked', active: 'In the car now', completed: 'Completed', cancelled: 'Cancelled' };
	var OUT = { no_show: 'No-show', no_permit: 'No permit', late_cancel: 'Late cancel', cancelled: 'Cancelled', done: 'Completed' };
	var insColor = {}; C.ins.forEach(function (i) { insColor[i.name.toLowerCase()] = i.color; });

	/* ------------------------------------------------------------ state */
	var serverNow = parse(C.now), skew = serverNow - Date.now();
	function now() { return Date.now() + skew; }
	var S = {
		view: store('view') || (window.innerWidth < 760 ? 'day' : 'week'),
		date: dayStart(now()),
		ins: store('ins') || '', car: store('car') || '', q: '', showCancelled: store('cx') === true,
		events: [], blocked: [], loaded: '', busy: false
	};
	if (['day', 'week', 'month', 'list', 'byins', 'bycar'].indexOf(S.view) < 0) { S.view = 'week'; }

	function start(root) {
		root.innerHTML =
			'<div class="scal-bar">' +
				'<div class="scal-nav"><button type="button" class="scal-b" data-go="-1" aria-label="Previous">‹</button><button type="button" class="scal-b" data-go="0">Today</button><button type="button" class="scal-b" data-go="1" aria-label="Next">›</button><h2 class="scal-title" aria-live="polite"></h2></div>' +
				'<div class="scal-views" role="tablist">' +
					[['day', 'Day'], ['week', 'Week'], ['month', 'Month'], ['list', 'List'], ['byins', 'By instructor'], ['bycar', 'By car']].map(function (v) { return '<button type="button" role="tab" class="scal-v" data-view="' + v[0] + '">' + v[1] + '</button>'; }).join('') +
				'</div>' +
			'</div>' +
			'<div class="scal-filters">' +
				'<label>Instructor <select id="scal-ins"><option value="">All instructors</option>' + C.ins.map(function (i) { return '<option value="' + esc(i.name) + '">' + esc(i.name) + '</option>'; }).join('') + '</select></label>' +
				'<label>Car <select id="scal-car"><option value="">All cars</option>' + C.cars.map(function (c) { return '<option value="' + esc(c.plate) + '">' + esc(c.plate + (c.model ? ' — ' + c.model : '') + (c.down ? ' (' + c.down + ')' : '')) + '</option>'; }).join('') + '</select></label>' +
				'<label class="scal-search">Student <input type="search" id="scal-q" placeholder="Name or phone"></label>' +
				'<label class="scal-chk"><input type="checkbox" id="scal-cx"> Show cancelled</label>' +
				'<span class="scal-sp"></span><span class="scal-tz" title="All times are school time">🕘 ' + esc(C.tzShort) + ' · school time</span>' +
				'<button type="button" class="scal-b" id="scal-refresh" title="Reload">⟳</button><button type="button" class="scal-b" id="scal-print">🖨 Print</button>' +
			'</div>' +
			'<div class="scal-legend">' +
				(C.ins.length ? C.ins.map(function (i) { return '<span><i style="background:' + i.color + '"></i>' + esc(i.name) + '</span>'; }).join('') : '<span class="scal-warnline">No instructors yet — add them on the Instructors screen.</span>') +
				'<span class="scal-k scal-k--done">Completed</span><span class="scal-k scal-k--bad">No-show / late cancel</span><span class="scal-k scal-k--cx">Cancelled</span><span class="scal-k scal-k--blk">Blocked time</span>' +
				(C.cars.length ? '' : '<span class="scal-warnline">No cars yet — add them under Cars.</span>') +
			'</div>' +
			'<div class="scal-stats" id="scal-stats"></div>' +
			'<div class="scal-body" id="scal-body" tabindex="-1"></div>' +
			'<div class="scal-toast" id="scal-toast" role="status" aria-live="polite"></div>' +
			'<aside class="scal-panel" id="scal-panel" hidden aria-label="Lesson details"></aside>' +
			'<div class="scal-dlg" id="scal-dlg" hidden role="dialog" aria-modal="true"><div class="scal-dlg-in"></div></div>';

		var $ins = root.querySelector('#scal-ins'), $car = root.querySelector('#scal-car'), $q = root.querySelector('#scal-q'), $cx = root.querySelector('#scal-cx');
		$ins.value = S.ins; if ($ins.value !== S.ins) { S.ins = ''; }
		$car.value = S.car; if ($car.value !== S.car) { S.car = ''; }
		$cx.checked = S.showCancelled;
		$ins.addEventListener('change', function () { S.ins = this.value; store('ins', S.ins); draw(); });
		$car.addEventListener('change', function () { S.car = this.value; store('car', S.car); draw(); });
		$q.addEventListener('input', function () { S.q = this.value.trim().toLowerCase(); draw(); });
		$cx.addEventListener('change', function () { S.showCancelled = this.checked; store('cx', S.showCancelled); draw(); });
		root.querySelector('#scal-refresh').addEventListener('click', function () { load(true); });
		root.querySelector('#scal-print').addEventListener('click', function () { window.print(); });
		root.querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { go(+b.getAttribute('data-go')); }); });
		root.querySelectorAll('[data-view]').forEach(function (b) { b.addEventListener('click', function () { S.view = b.getAttribute('data-view'); store('view', S.view); load(); }); });
		var bb = document.getElementById('scal-block-btn'); if (bb) { bb.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); blockDialog(stamp(dayStart(S.date) + 9 * 3600000), stamp(dayStart(S.date) + 17 * 3600000)); }, true); }
		document.addEventListener('keydown', function (e) {
			if (e.target.closest && e.target.closest('input,select,textarea,.scal-dlg,#df-add-session-modal')) { return; }
			if (e.key === 'ArrowLeft') { go(-1); } else if (e.key === 'ArrowRight') { go(1); } else if (e.key === 't' || e.key === 'T') { go(0); } else if (e.key === 'Escape') { closePanel(); }
		});
		load();
		setInterval(function () { if (!document.hidden && !S.drag && document.getElementById('scal-dlg').hidden && !(document.getElementById('df-add-session-modal') || { classList: { contains: function () { return false; } } }).classList.contains('is-open')) { load(false, true); } }, 60000);
		setInterval(nowLine, 30000);
		// After the Schedule Lesson form books (it reloads the page) we are back here with a notice — nothing to do.
	}

	function go(n) {
		if (n === 0) { S.date = dayStart(now()); }
		else if (S.view === 'week') { S.date = addDays(S.date, 7 * n); }
		else if (S.view === 'month') { var d = new Date(S.date); S.date = Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1); }
		else if (S.view === 'list') { S.date = addDays(S.date, 14 * n); }
		else { S.date = addDays(S.date, n); }
		load();
	}

	function range() {
		if (S.view === 'week') { var m = monday(S.date); return [m, addDays(m, 6)]; }
		if (S.view === 'month') { var d = new Date(S.date), f = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1), l = Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0); return [monday(f), addDays(monday(l), 6)]; }
		if (S.view === 'list') { return [S.date, addDays(S.date, 13)]; }
		return [S.date, S.date];
	}

	function load(force, quiet) {
		var r = range(), key = dkey(r[0]) + '|' + dkey(r[1]);
		draw(true);
		if (!force && !quiet && key === S.loaded) { draw(); return; }
		if (!quiet) { S.busy = true; document.getElementById('scal-body').classList.add('is-busy'); }
		return post('events', { from: dkey(r[0]), to: dkey(r[1]) }).then(function (res) {
			S.busy = false; document.getElementById('scal-body').classList.remove('is-busy');
			if (!res || !res.success) { toast(res && res.data && res.data.msg ? res.data.msg : 'The calendar could not be loaded.', true); return; }
			if (res.data.now) { skew = parse(res.data.now) - Date.now(); }
			S.events = res.data.events.map(function (e) { e.a = parse(e.start); e.b = parse(e.end); return e; });
			S.blocked = res.data.blocked.map(function (b) { b.a = parse(b.start); b.b = parse(b.end); return b; });
			S.loaded = key;
			draw();
			if (S.open) { var e = byId(S.open); if (e) { panel(e); } else { closePanel(); } }
		}).catch(function () { S.busy = false; document.getElementById('scal-body').classList.remove('is-busy'); toast('No connection — the calendar could not be loaded.', true); });
	}
	function byId(id) { for (var i = 0; i < S.events.length; i++) { if (S.events[i].id === id) { return S.events[i]; } } return null; }

	function isCancelled(e) { return e.status === 'cancelled'; }
	function visible(e) {
		if (!S.showCancelled && isCancelled(e) && ['no_show', 'no_permit', 'late_cancel'].indexOf(e.out) < 0) { return false; }
		if (S.ins && (e.ins || '').toLowerCase() !== S.ins.toLowerCase()) { return false; }
		if (S.car && (e.plate || '').toLowerCase() !== S.car.toLowerCase()) { return false; }
		if (S.q) { var hay = (e.name + ' ' + (e.phone || '').replace(/\D/g, '') + ' ' + (e.email || '')).toLowerCase(); if (hay.indexOf(S.q) < 0 && hay.indexOf(S.q.replace(/\D/g, '') || '§') < 0) { return false; } }
		return true;
	}
	function blkVisible(b) {
		if (b.type === 'instructor' && S.ins && b.target.toLowerCase() !== S.ins.toLowerCase()) { return false; }
		if (b.type === 'vehicle' && S.car && b.target.toLowerCase() !== S.car.toLowerCase()) { return false; }
		return true;
	}
	function cls(e) {
		var c = 'scal-ev';
		if (e.status === 'completed' || e.out === 'done') { c += ' is-done'; }
		else if (['no_show', 'no_permit', 'late_cancel'].indexOf(e.out) > -1) { c += ' is-bad'; }
		else if (isCancelled(e)) { c += ' is-cx'; }
		else if (e.status === 'active') { c += ' is-live'; }
		if (e.b < now() && e.status === 'upcoming') { c += ' is-past'; }
		return c;
	}
	function color(e) { return insColor[(e.ins || '').toLowerCase()] || '#64748b'; }
	function label(e) {
		if (['no_show', 'no_permit', 'late_cancel'].indexOf(e.out) > -1) { return OUT[e.out]; }
		if (e.status === 'completed' || e.out === 'done') { return 'Completed'; }
		return STATUS[e.status] || e.status;
	}
	function typeName(e) { return C.types[e.type] || e.topic || 'Driving lesson'; }

	/* ------------------------------------------------------------ drawing */
	function draw(headerOnly) {
		var r = range(), title = '';
		var d0 = new Date(r[0]), d1 = new Date(r[1]);
		if (S.view === 'week') { title = MN[d0.getUTCMonth()] + ' ' + d0.getUTCDate() + ' – ' + (d0.getUTCMonth() !== d1.getUTCMonth() ? MN[d1.getUTCMonth()] + ' ' : '') + d1.getUTCDate() + ', ' + d1.getUTCFullYear(); }
		else if (S.view === 'month') { var md = new Date(S.date); title = MNL[md.getUTCMonth()] + ' ' + md.getUTCFullYear(); }
		else if (S.view === 'list') { title = MN[d0.getUTCMonth()] + ' ' + d0.getUTCDate() + ' – ' + MN[d1.getUTCMonth()] + ' ' + d1.getUTCDate() + ', ' + d1.getUTCFullYear(); }
		else { title = dlong(S.date) + ', ' + d0.getUTCFullYear() + (S.view === 'byins' ? ' · by instructor' : S.view === 'bycar' ? ' · by car' : ''); }
		var root = document.getElementById('scal');
		root.querySelector('.scal-title').textContent = title;
		root.querySelectorAll('[data-view]').forEach(function (b) { var on = b.getAttribute('data-view') === S.view; b.classList.toggle('is-on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
		root.querySelector('[data-go="0"]').textContent = S.view === 'week' ? 'This week' : S.view === 'month' ? 'This month' : 'Today';
		if (headerOnly) { return; }
		var body = document.getElementById('scal-body');
		var ev = S.events.filter(visible);
		stats(ev);
		if (S.view === 'month') { body.innerHTML = month(ev); bindMonth(body); return; }
		if (S.view === 'list') { body.innerHTML = list(ev); bindList(body); return; }
		var cols = [];
		if (S.view === 'week') { for (var i = 0; i < 7; i++) { var t = addDays(monday(S.date), i); cols.push({ day: t, key: dkey(t), head: '<b>' + DN[new Date(t).getUTCDay()] + '</b><span>' + new Date(t).getUTCDate() + '</span>', today: dkey(t) === dkey(now()) }); } }
		else if (S.view === 'day') { cols.push({ day: S.date, key: dkey(S.date), head: '<b>' + DN[new Date(S.date).getUTCDay()] + '</b><span>' + new Date(S.date).getUTCDate() + '</span>', today: dkey(S.date) === dkey(now()) }); }
		else if (S.view === 'byins') {
			var names = C.ins.map(function (i) { return i.name; });
			ev.forEach(function (e) { if (e.ins && names.indexOf(e.ins) < 0) { names.push(e.ins); } });
			if (S.ins) { names = names.filter(function (n) { return n.toLowerCase() === S.ins.toLowerCase(); }); }
			names.forEach(function (n) { cols.push({ day: S.date, key: dkey(S.date), ins: n, head: '<i style="background:' + (insColor[n.toLowerCase()] || '#64748b') + '"></i><b>' + esc(n) + '</b>', today: dkey(S.date) === dkey(now()) }); });
			if (ev.some(function (e) { return !e.ins; })) { cols.push({ day: S.date, key: dkey(S.date), ins: '', head: '<b>No instructor</b>', today: false }); }
		} else {
			var plates = C.cars.map(function (c) { return c.plate; });
			ev.forEach(function (e) { if (e.plate && plates.indexOf(e.plate) < 0) { plates.push(e.plate); } });
			if (S.car) { plates = plates.filter(function (p) { return p.toLowerCase() === S.car.toLowerCase(); }); }
			plates.forEach(function (p) { var c = C.cars.filter(function (x) { return x.plate === p; })[0]; cols.push({ day: S.date, key: dkey(S.date), plate: p, head: '<b class="scal-plate">' + esc(p) + '</b><span>' + esc(c ? c.model : '') + '</span>', today: dkey(S.date) === dkey(now()) }); });
			if (ev.some(function (e) { return !e.plate; })) { cols.push({ day: S.date, key: dkey(S.date), plate: '', head: '<b>No car</b>', today: false }); }
		}
		if (!cols.length) { body.innerHTML = '<p class="scal-empty">' + (S.view === 'byins' ? 'No instructors to show. Add instructors on the Instructors screen.' : 'No cars to show. Add cars under Cars.') + '</p>'; return; }
		body.innerHTML = grid(cols, ev);
		bindGrid(body, cols);
		nowLine();
		// Start the day view near the first lesson or the current time.
		var sc = body.querySelector('.scal-scroll');
		if (sc && !S.scrolled) { S.scrolled = true; var first = ev.filter(function (e) { return dkey(e.a) === cols[0].key || S.view === 'week'; }).map(function (e) { return mins(e.a); }).sort(function (a, b) { return a - b; })[0]; var target = first != null && first < DAY ? first : DAY; sc.scrollTop = Math.max(0, (target - OPEN - 30) / STEP * ROW); }
	}

	function stats(ev) {
		var r = range(), live = ev.filter(function (e) { return e.a >= r[0] && e.a < addDays(r[1], 1) && !isCancelled(e); });
		var hrs = live.reduce(function (s, e) { return s + (e.b - e.a) / 3600000; }, 0);
		var notAssigned = live.filter(function (e) { return (!e.ins || !e.plate) && e.status === 'upcoming'; }).length;
		var fees = ev.filter(function (e) { return e.fee; }).length;
		var open = ev.filter(function (e) { return e.status === 'upcoming' && !e.out && e.b < now(); }).length;
		document.getElementById('scal-stats').innerHTML = '<span><b>' + live.length + '</b> lesson' + (live.length === 1 ? '' : 's') + '</span><span><b>' + (Math.round(hrs * 10) / 10) + '</b> hours</span>' +
			(notAssigned ? '<span class="is-warn"><b>' + notAssigned + '</b> without instructor or car</span>' : '') + (fees ? '<span class="is-warn"><b>' + fees + '</b> with an open fee</span>' : '') + (open ? '<span class="is-warn"><b>' + open + '</b> past lesson' + (open === 1 ? '' : 's') + ' without an outcome — click to mark completed or no-show</span>' : '');
	}

	/* Overlapping lessons side by side: columns per cluster. */
	function layout(list) {
		list.sort(function (x, y) { return x.a - y.a || y.b - x.b; });
		var clusters = [], cur = null, end = 0;
		list.forEach(function (e) { if (!cur || e.a >= end) { cur = []; clusters.push(cur); end = 0; } cur.push(e); end = Math.max(end, e.b); });
		clusters.forEach(function (cl) {
			var lanes = [];
			cl.forEach(function (e) { for (var i = 0; i < lanes.length; i++) { if (lanes[i] <= e.a) { lanes[i] = e.b; e._lane = i; return; } } e._lane = lanes.length; lanes.push(e.b); });
			cl.forEach(function (e) { e._lanes = lanes.length; });
		});
	}

	function grid(cols, ev) {
		var rows = (CLOSE - OPEN) / STEP, h = rows * ROW;
		var html = '<div class="scal-grid" style="--cols:' + cols.length + '"><div class="scal-head"><div class="scal-gut"></div>' +
			cols.map(function (c, i) { return '<div class="scal-colh' + (c.today ? ' is-today' : '') + '" data-col="' + i + '"' + (S.view === 'week' ? ' data-day="' + c.key + '" role="button" tabindex="0" title="Open this day"' : '') + '>' + c.head + '</div>'; }).join('') + '</div>';
		html += '<div class="scal-scroll"><div class="scal-cols" style="height:' + h + 'px">';
		html += '<div class="scal-gut">';
		for (var m = OPEN; m < CLOSE; m += 60) { html += '<span style="top:' + ((m - OPEN) / STEP * ROW) + 'px">' + ((m / 60 + 11) % 12 + 1) + (m < 720 ? ' AM' : ' PM') + '</span>'; }
		html += '</div>';
		cols.forEach(function (c, ci) {
			html += '<div class="scal-col' + (c.today ? ' is-today' : '') + '" data-col="' + ci + '" data-day="' + c.key + '">';
			var d0 = parse(c.key + ' 00:00');
			if (DAY > OPEN) { html += '<div class="scal-early" style="height:' + ((DAY - OPEN) / STEP * ROW) + 'px"' + (ci === 0 ? '><span>Early — ' + (C.early ? 'manager only' : 'the manager books these') + '</span>' : '>') + '</div>'; }
			// Blocked time.
			S.blocked.filter(blkVisible).forEach(function (b) {
				if (c.ins !== undefined && b.type === 'vehicle') { return; }
				if (c.plate !== undefined && b.type === 'instructor') { return; }
				if (c.ins !== undefined && b.type === 'instructor' && b.target.toLowerCase() !== c.ins.toLowerCase()) { return; }
				if (c.plate !== undefined && b.type === 'vehicle' && b.target.toLowerCase() !== c.plate.toLowerCase()) { return; }
				var a = Math.max(b.a, d0 + OPEN * 60000), z = Math.min(b.b, d0 + CLOSE * 60000);
				if (z <= a) { return; }
				html += '<div class="scal-blk" data-blk="' + b.id + '" style="top:' + ((mins(a) - OPEN) / STEP * ROW) + 'px;height:' + Math.max(ROW / 2, (z - a) / 60000 / STEP * ROW) + 'px" title="' + esc((b.type === 'all' ? 'School closed' : b.target + ' unavailable') + (b.reason ? ' — ' + b.reason : '')) + '"><span>⛔ ' + esc(b.type === 'all' ? 'Blocked' : b.target) + (b.reason ? ' · ' + esc(b.reason) : '') + '</span></div>';
			});
			var mine = ev.filter(function (e) {
				if (dkey(e.a) !== c.key) { return false; }
				if (c.ins !== undefined && (e.ins || '') !== c.ins) { return false; }
				if (c.plate !== undefined && (e.plate || '') !== c.plate) { return false; }
				return true;
			});
			layout(mine);
			mine.forEach(function (e) {
				var top = (mins(e.a) - OPEN) / STEP * ROW, len = (e.b - e.a) / 60000, hgt = Math.max(ROW - 2, len / STEP * ROW - 2);
				var early = mins(e.a) < OPEN, late = mins(e.a) + len > CLOSE;
				if (early) { hgt += top; top = 0; }
				if (top + hgt > h) { hgt = h - top - 1; }
				var w = 100 / e._lanes, left = e._lane * w;
				var movable = (e.status === 'upcoming' || e.status === 'active') && e.a > now() - 5 * 60000;
				html += '<div class="' + cls(e) + (movable ? ' is-movable' : '') + '" data-id="' + e.id + '" role="button" tabindex="0" style="top:' + top + 'px;height:' + hgt + 'px;left:calc(' + left + '% + 2px);width:calc(' + w + '% - 4px);--c:' + color(e) + '" aria-label="' + esc(e.name + ', ' + hm(e.a) + ' to ' + hm(e.b) + ', ' + (e.ins || 'no instructor') + ', ' + label(e)) + '">' +
					'<span class="scal-t">' + (early ? '↑ ' : '') + hmShort(e.a) + '–' + hm(e.b) + (late ? ' ↓' : '') + '</span>' +
					'<b class="scal-n">' + esc(e.name) + (e.no ? ' <small>#' + e.no + '</small>' : '') + '</b>' +
					'<span class="scal-m">' + esc([S.view === 'byins' ? '' : e.ins || '⚠ no instructor', S.view === 'bycar' ? '' : e.plate || '⚠ no car'].filter(Boolean).join(' · ')) + '</span>' +
					(e.status !== 'upcoming' || e.out ? '<span class="scal-s">' + esc(label(e)) + '</span>' : '') +
					(e.fee ? '<span class="scal-fee" title="Open lesson fee">$</span>' : '') +
				'</div>';
			});
			html += '</div>';
		});
		html += '</div></div></div>';
		return html;
	}

	function nowLine() {
		var body = document.getElementById('scal-body'); if (!body) { return; }
		body.querySelectorAll('.scal-now').forEach(function (n) { n.parentNode.removeChild(n); });
		var t = now(), m = mins(t);
		if (m < OPEN || m > CLOSE) { return; }
		body.querySelectorAll('.scal-col[data-day="' + dkey(t) + '"]').forEach(function (col, i) {
			var n = document.createElement('div'); n.className = 'scal-now'; n.style.top = ((m - OPEN) / STEP * ROW) + 'px';
			if (i === 0) { n.innerHTML = '<span>' + hm(t) + '</span>'; }
			col.appendChild(n);
		});
	}

	function month(ev) {
		var r = range(), md = new Date(S.date).getUTCMonth(), html = '<div class="scal-month"><div class="scal-mh">' + ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div><div class="scal-mg">';
		for (var t = r[0]; t <= r[1]; t = addDays(t, 1)) {
			var k = dkey(t), list = ev.filter(function (e) { return dkey(e.a) === k; }).sort(function (a, b) { return a.a - b.a; });
			var blk = S.blocked.filter(function (b) { return blkVisible(b) && b.a < addDays(t, 1) && b.b > t; });
			html += '<div class="scal-md' + (new Date(t).getUTCMonth() !== md ? ' is-out' : '') + (k === dkey(now()) ? ' is-today' : '') + '" data-day="' + k + '" role="button" tabindex="0" aria-label="' + esc(dlong(t) + ': ' + list.length + ' lessons') + '"><span class="scal-mn">' + new Date(t).getUTCDate() + '</span>';
			if (blk.length) { html += '<span class="scal-mb">⛔ ' + esc(blk[0].type === 'all' ? 'Blocked' : blk[0].target + ' off') + '</span>'; }
			list.slice(0, 4).forEach(function (e) { html += '<span class="scal-mi ' + cls(e) + '" style="--c:' + color(e) + '" data-id="' + e.id + '">' + hmShort(e.a) + ' ' + esc(e.name.split(' ')[0]) + '</span>'; });
			if (list.length > 4) { html += '<span class="scal-more">+' + (list.length - 4) + ' more</span>'; }
			html += '</div>';
		}
		return html + '</div></div>';
	}

	function list(ev) {
		var r = range(), html = '<div class="scal-list">', any = false;
		for (var t = r[0]; t <= r[1]; t = addDays(t, 1)) {
			var k = dkey(t), items = ev.filter(function (e) { return dkey(e.a) === k; }).sort(function (a, b) { return a.a - b.a; });
			var blk = S.blocked.filter(function (b) { return blkVisible(b) && b.a < addDays(t, 1) && b.b > t; });
			if (!items.length && !blk.length) { continue; }
			any = true;
			html += '<h3 class="scal-lh' + (k === dkey(now()) ? ' is-today' : '') + '">' + esc(dlong(t)) + ' <small>' + items.length + ' lesson' + (items.length === 1 ? '' : 's') + '</small></h3><ul>';
			blk.forEach(function (b) { html += '<li class="scal-li is-blk"><span class="scal-lt">' + (b.a <= t && b.b >= addDays(t, 1) ? 'All day' : hm(Math.max(b.a, t)) + ' – ' + hm(Math.min(b.b, addDays(t, 1)))) + '</span><span>⛔ ' + esc(b.type === 'all' ? 'School closed' : b.target + ' unavailable') + (b.reason ? ' — ' + esc(b.reason) : '') + '</span></li>'; });
			items.forEach(function (e) {
				html += '<li class="scal-li ' + cls(e) + '" style="--c:' + color(e) + '" data-id="' + e.id + '" role="button" tabindex="0"><span class="scal-lt">' + hm(e.a) + ' – ' + hm(e.b) + '</span><span class="scal-ln"><b>' + esc(e.name) + '</b> <small>' + esc(typeName(e)) + '</small></span><span class="scal-lm">' + esc((e.ins || '⚠ no instructor') + ' · ' + (e.plate || '⚠ no car')) + '</span><span class="scal-ls">' + esc(label(e)) + '</span>' + (e.phone ? '<a class="scal-call" href="tel:' + esc(e.phone.replace(/[^\d+]/g, '')) + '" title="Call">📞</a>' : '') + '</li>';
			});
			html += '</ul>';
		}
		if (!any) { html += '<p class="scal-empty">No lessons in these two weeks' + (S.ins || S.car || S.q ? ' for this filter' : '') + '.</p>'; }
		return html + '</div>';
	}

	/* ------------------------------------------------------------ interaction */
	function bindMonth(body) {
		body.querySelectorAll('.scal-md').forEach(function (d) {
			function open() { S.date = parse(d.getAttribute('data-day') + ' 00:00'); S.view = 'day'; store('view', 'day'); S.scrolled = false; load(); }
			d.addEventListener('click', function (e) { var it = e.target.closest('[data-id]'); if (it) { panel(byId(+it.getAttribute('data-id'))); return; } open(); });
			d.addEventListener('keydown', function (e) { if (e.key === 'Enter') { open(); } });
		});
	}
	function bindList(body) {
		body.querySelectorAll('.scal-li[data-id]').forEach(function (li) {
			li.addEventListener('click', function (e) { if (e.target.closest('a')) { return; } panel(byId(+li.getAttribute('data-id'))); });
			li.addEventListener('keydown', function (e) { if (e.key === 'Enter') { panel(byId(+li.getAttribute('data-id'))); } });
		});
	}

	function bindGrid(body, cols) {
		body.querySelectorAll('.scal-colh[data-day]').forEach(function (h) {
			function open() { S.date = parse(h.getAttribute('data-day') + ' 00:00'); S.view = 'day'; store('view', 'day'); S.scrolled = false; load(); }
			h.addEventListener('click', open); h.addEventListener('keydown', function (e) { if (e.key === 'Enter') { open(); } });
		});
		body.querySelectorAll('.scal-blk').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); blockInfo(+b.getAttribute('data-blk')); }); });
		body.querySelectorAll('.scal-ev').forEach(function (el) {
			el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { panel(byId(+el.getAttribute('data-id'))); } });
			el.addEventListener('pointerdown', function (e) { if (e.button !== 0) { return; } dragStart(e, el, cols); });
		});
		body.querySelectorAll('.scal-col').forEach(function (col) {
			col.addEventListener('pointerdown', function (e) { if (e.button !== 0 || e.target !== col) { return; } selectStart(e, col, cols); });
		});
	}

	function minuteAt(col, clientY) {
		var r = col.getBoundingClientRect(), y = clientY - r.top;
		return OPEN + Math.max(0, Math.min((CLOSE - OPEN), Math.round(y / ROW) * STEP));
	}

	/* Drag a lesson to move it (or its lower edge to change its length). */
	function dragStart(e, el, cols) {
		var ev = byId(+el.getAttribute('data-id')); if (!ev) { return; }
		var resize = false; // lessons are 2 hours; the length is changed in the details panel
		var movable = el.classList.contains('is-movable');
		var sx = e.clientX, sy = e.clientY, moved = false, ghost = null, target = null;
		var len = (ev.b - ev.a) / 60000, startMin = mins(ev.a);
		function colAt(x, y) { var n = document.elementFromPoint(x, y); return n && n.closest ? n.closest('.scal-col') : null; }
		function mv(m) {
			if (!moved && Math.abs(m.clientX - sx) + Math.abs(m.clientY - sy) < 6) { return; }
			if (!movable) { return; }
			if (!moved) { moved = true; S.drag = true; el.classList.add('is-dragging'); ghost = el.cloneNode(true); ghost.classList.add('scal-ghost'); ghost.removeAttribute('data-id'); }
			if (resize) {
				var col = el.parentNode, m2 = minuteAt(col, m.clientY), nl = Math.max(30, Math.min(240, m2 - startMin));
				ghost.style.height = (nl / STEP * ROW - 2) + 'px'; ghost.querySelector('.scal-t').textContent = hmShort(ev.a) + '–' + hm(ev.a + nl * 60000) + ' (' + (nl / 60) + ' h)';
				if (ghost.parentNode !== col) { col.appendChild(ghost); ghost.style.top = el.style.top; ghost.style.left = el.style.left; ghost.style.width = el.style.width; }
				target = { col: col, start: startMin, len: nl };
			} else {
				var c2 = colAt(m.clientX, m.clientY); if (!c2) { return; }
				var off = Math.round((sy - el.getBoundingClientRect().top) / ROW) * STEP;
				var st = minuteAt(c2, m.clientY) - off; st = Math.max(OPEN, Math.min(CLOSE - len, Math.round(st / STEP) * STEP));
				if (ghost.parentNode !== c2) { c2.appendChild(ghost); }
				ghost.style.top = ((st - OPEN) / STEP * ROW) + 'px'; ghost.style.left = '2px'; ghost.style.width = 'calc(100% - 4px)';
				var d = parse(c2.getAttribute('data-day') + ' 00:00') + st * 60000;
				ghost.querySelector('.scal-t').textContent = hmShort(d) + '–' + hm(d + len * 60000);
				target = { col: c2, start: st, len: len };
			}
		}
		function up() {
			document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up);
			el.classList.remove('is-dragging'); if (ghost && ghost.parentNode) { ghost.parentNode.removeChild(ghost); }
			setTimeout(function () { S.drag = false; }, 0);
			if (!moved) { panel(ev); return; }
			if (!target) { return; }
			var ci = +target.col.getAttribute('data-col'), c = cols[ci], day = target.col.getAttribute('data-day');
			var ns = parse(day + ' 00:00') + target.start * 60000;
			var data = { id: ev.id, start: stamp(ns), minutes: target.len };
			var changes = [];
			if (ns !== ev.a || target.len !== len) { changes.push(dlong(ns) + ', ' + hm(ns) + ' – ' + hm(ns + target.len * 60000)); }
			if (c && c.ins !== undefined && c.ins !== ev.ins) { var ii = C.ins.filter(function (x) { return x.name === c.ins; })[0]; if (!ii) { toast('Choose a real instructor column.', true); return; } data.ins = ii.id; changes.push('instructor ' + c.ins); }
			if (c && c.plate !== undefined && c.plate !== ev.plate) { var cc = C.cars.filter(function (x) { return x.plate === c.plate; })[0]; if (!cc) { toast('Choose a real car column.', true); return; } data.car = cc.id; changes.push('car ' + c.plate); }
			if (!changes.length) { return; }
			confirmMove(ev, data, changes);
		}
		e.preventDefault();
		document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up);
	}

	/* Click or drag over empty time → book here / block it. */
	function selectStart(e, col, cols) {
		var a = Math.min(CLOSE - 120, minuteAt(col, e.clientY - ROW / 2)), b = a + 120, box = document.createElement('div');
		box.className = 'scal-sel'; col.appendChild(box);
		function paint() { var x = Math.min(a, b), y = Math.max(a, b); box.style.top = ((x - OPEN) / STEP * ROW) + 'px'; box.style.height = ((y - x) / STEP * ROW) + 'px'; box.textContent = hm(parse(col.getAttribute('data-day') + ' 00:00') + x * 60000) + ' – ' + hm(parse(col.getAttribute('data-day') + ' 00:00') + y * 60000); }
		paint();
		function mv(m) { var x = Math.min(CLOSE - 120, minuteAt(col, m.clientY - ROW / 2)); a = x; b = a + 120; paint(); }
		function up() {
			document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up);
			var x = Math.min(a, b), y = Math.max(a, b), d0 = parse(col.getAttribute('data-day') + ' 00:00'), c = cols[+col.getAttribute('data-col')];
			slotMenu(box, d0 + x * 60000, d0 + y * 60000, c);
		}
		e.preventDefault();
		document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up);
	}

	function slotMenu(box, a, b, c) {
		var past = a < now() - 5 * 60000, early = mins(a) < DAY;
		var m = document.createElement('div'); m.className = 'scal-menu';
		m.innerHTML = '<p>' + esc(dlong(a) + ', ' + hm(a) + ' – ' + hm(b)) + '</p>' + (past ? '<p class="scal-mnote">This time has passed.</p>' : (early && !C.early ? '<p class="scal-mnote">Before ' + (DAY / 60) + ' AM — only the school manager books early lessons.</p>' : (early ? '<p class="scal-mnote">Early start — instructors normally start at ' + (DAY / 60) + ' AM.</p>' : '') + '<button type="button" data-a="book">＋ Book a lesson here</button>')) + '<button type="button" data-a="block">⛔ Block this time</button><button type="button" data-a="x" class="scal-ghostbtn">Close</button>';
		box.appendChild(m);
		function close() { if (box.parentNode) { box.parentNode.removeChild(box); } document.removeEventListener('pointerdown', outside, true); }
		function outside(e) { if (!box.contains(e.target)) { close(); } }
		setTimeout(function () { document.addEventListener('pointerdown', outside, true); }, 0);
		m.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
		m.addEventListener('click', function (e) {
			var t = e.target.closest('button'); if (!t) { return; }
			var act = t.getAttribute('data-a'); close();
			if (act === 'book') { book(a, b, c); }
			if (act === 'block') { blockDialog(stamp(a), stamp(b), c); }
		});
	}

	function book(a, b, c) {
		var M = document.getElementById('df-add-session-modal'); if (!M) { toast('The Schedule Lesson form is not on this page.', true); return; }
		var set = function (id, v) { var n = document.getElementById(id); if (n) { n.value = v; } };
		var iso = function (t) { return stamp(t).replace(' ', 'T'); };
		var len = 120; // behind-the-wheel lessons are 2 hours
		set('df-modal-start-time', iso(a)); set('df-modal-end-time', iso(a + len * 60000));
		set('df-modal-instructor', c && c.ins ? c.ins : (S.ins || '')); set('df-modal-plate', c && c.plate ? c.plate : (S.car || ''));
		M.classList.add('is-open');
	}

	/* ------------------------------------------------------------ details panel */
	function panel(e) {
		if (!e) { return; }
		S.open = e.id;
		var P = document.getElementById('scal-panel'), started = e.a <= now(), up = e.status === 'upcoming' || e.status === 'active';
		var tel = (e.phone || '').replace(/[^\d+]/g, '');
		var hrsLeft = (e.a - now()) / 3600000;
		P.innerHTML = '<header><span class="scal-dot" style="background:' + color(e) + '"></span><div><h3>' + esc(e.name) + '</h3><p>' + esc(typeName(e)) + (e.no ? ' · lesson #' + e.no : '') + '</p></div><button type="button" class="scal-x" aria-label="Close">×</button></header>' +
			'<dl>' +
				'<dt>When</dt><dd>' + esc(dlong(e.a)) + '<br><b>' + hm(e.a) + ' – ' + hm(e.b) + '</b> (' + ((e.b - e.a) / 3600000) + ' h)</dd>' +
				'<dt>Status</dt><dd><span class="scal-pill ' + cls(e).replace('scal-ev', '') + '">' + esc(label(e)) + '</span>' + (e.fee ? ' <span class="scal-pill is-bad">Open fee</span>' : '') + (up && hrsLeft > 0 && hrsLeft < C.hours ? '<br><small>Less than ' + C.hours + ' hours away — cancelling now counts as late.</small>' : '') + '</dd>' +
				'<dt>Instructor</dt><dd>' + (e.ins ? esc(e.ins) : '<span class="scal-warnline">Not assigned</span>') + '</dd>' +
				'<dt>Car</dt><dd>' + (e.plate ? esc(e.plate) : '<span class="scal-warnline">Not assigned</span>') + '</dd>' +
				(e.place ? '<dt>Meet at</dt><dd>' + esc(e.place) + '</dd>' : '') +
				(e.phone ? '<dt>Phone</dt><dd><a href="tel:' + esc(tel) + '">' + esc(e.phone) + '</a> · <a href="sms:' + esc(tel) + '">Text</a></dd>' : '') +
				(e.email ? '<dt>E-mail</dt><dd><a href="mailto:' + esc(e.email) + '">' + esc(e.email) + '</a></dd>' : '') +
				(e.permit ? '<dt>Permit</dt><dd>' + (e.permit === 'yes' ? 'Confirmed' : 'Not confirmed') + '</dd>' : '') +
				(e.note ? '<dt>Note</dt><dd>' + esc(e.note) + '</dd>' : '') +
			'</dl>' +
			(up && !started ? '<form class="scal-edit"><h4>Change</h4>' +
				'<div class="scal-row"><label>Date<input type="date" name="d" value="' + dkey(e.a) + '" required></label><label>Start<select name="t">' + times(mins(e.a)) + '</select></label><label>Length<select name="m">' + [60, 90, 120, 180].map(function (x) { var cur = Math.round((e.b - e.a) / 60000); return '<option value="' + x + '"' + (x === cur ? ' selected' : '') + '>' + (x === 120 ? '2 hours (standard)' : (x / 60) + ' h') + '</option>'; }).join('') + (([60, 90, 120, 180].indexOf(Math.round((e.b - e.a) / 60000)) < 0) ? '<option value="' + Math.round((e.b - e.a) / 60000) + '" selected>' + Math.round((e.b - e.a) / 60000) + ' min</option>' : '') + '</select></label></div>' +
				'<div class="scal-row"><label>Instructor<select name="ins">' + (e.insId ? '' : '<option value="">— choose —</option>') + C.ins.map(function (i) { return '<option value="' + i.id + '"' + (i.name === e.ins ? ' selected' : '') + '>' + esc(i.name) + '</option>'; }).join('') + '</select></label><label>Car<select name="car">' + (e.carId ? '' : '<option value="">— choose —</option>') + C.cars.map(function (c) { return '<option value="' + c.id + '"' + (c.plate === e.plate ? ' selected' : '') + '>' + esc(c.plate) + '</option>'; }).join('') + '</select></label></div>' +
				'<button class="scal-btn">Save change</button></form>' : '') +
			'<div class="scal-acts">' +
				(up && !started ? '<button type="button" class="scal-btn scal-btn--danger" data-st="cancel">Cancel lesson</button>' : '') +
				(started && e.status !== 'completed' && !e.out ? '<button type="button" class="scal-btn" data-st="done">Completed</button><button type="button" class="scal-btn scal-btn--warn" data-st="no_show">No-show</button><button type="button" class="scal-btn scal-btn--warn" data-st="no_permit">No permit</button>' : '') +
				'<a class="scal-btn scal-btn--ghost" href="' + esc(C.records) + '">Evaluations &amp; records</a>' +
				(e.lid ? '<a class="scal-btn scal-btn--ghost" href="' + esc(C.lesson + e.lid) + '">BTW lesson &amp; fees</a>' : '') +
			'</div>';
		var wb = document.getElementById('wpadminbar'); P.style.top = wb && wb.offsetHeight && getComputedStyle(wb).display !== 'none' ? Math.max(0, wb.getBoundingClientRect().bottom) + 'px' : '0px';
		P.hidden = false; P.classList.add('is-open');
		P.querySelector('.scal-x').addEventListener('click', closePanel);
		var F = P.querySelector('.scal-edit');
		if (F) {
			F.addEventListener('submit', function (x) {
				x.preventDefault();
				var data = { id: e.id, start: F.d.value + ' ' + F.t.value, minutes: F.m.value, ins: F.ins.value, car: F.car.value };
				if (!F.d.value || !F.ins.value || !F.car.value) { toast('Choose the date, the instructor and the car.', true); return; }
				var ns = parse(data.start), ch = [];
				if (ns !== e.a || +data.minutes !== Math.round((e.b - e.a) / 60000)) { ch.push(dlong(ns) + ', ' + hm(ns) + ' – ' + hm(ns + data.minutes * 60000)); }
				var ii = C.ins.filter(function (i) { return String(i.id) === data.ins; })[0]; if (ii && ii.name !== e.ins) { ch.push('instructor ' + ii.name); }
				var cc = C.cars.filter(function (c) { return String(c.id) === data.car; })[0]; if (cc && cc.plate !== e.plate) { ch.push('car ' + cc.plate); }
				if (!ch.length) { toast('Nothing changed.'); return; }
				confirmMove(e, data, ch);
			});
		}
		P.querySelectorAll('[data-st]').forEach(function (b) { b.addEventListener('click', function () { statusDialog(e, b.getAttribute('data-st')); }); });
		P.querySelector('.scal-x').focus();
	}
	function closePanel() { var P = document.getElementById('scal-panel'); if (P) { P.hidden = true; P.classList.remove('is-open'); } S.open = 0; }
	function times(sel) { var h = ''; for (var m = OPEN; m <= CLOSE - 60; m += 30) { h += '<option value="' + pad(Math.floor(m / 60)) + ':' + pad(m % 60) + '"' + (m === sel ? ' selected' : '') + '>' + hm(Date.UTC(2000, 0, 1) + m * 60000) + '</option>'; } if ((sel - OPEN) % 30 || sel < OPEN || sel > CLOSE - 60) { h = '<option value="' + pad(Math.floor(sel / 60)) + ':' + pad(sel % 60) + '" selected>' + hm(Date.UTC(2000, 0, 1) + sel * 60000) + '</option>' + h; } return h; }

	/* ------------------------------------------------------------ dialogs */
	function dialog(html, onReady) {
		var D = document.getElementById('scal-dlg'), I = D.querySelector('.scal-dlg-in');
		I.innerHTML = html; D.hidden = false;
		var last = document.activeElement;
		function close() { D.hidden = true; I.innerHTML = ''; if (last && last.focus) { last.focus(); } }
		D.onclick = function (e) { if (e.target === D || e.target.closest('[data-close]')) { close(); } };
		D.onkeydown = function (e) { if (e.key === 'Escape') { close(); } };
		onReady(I, close);
		var f = I.querySelector('button:not([data-close]),input,select'); if (f) { f.focus(); }
	}

	function confirmMove(e, data, changes, warnings, tellWas) {
		var hasMail = !!e.email, hasPhone = !!e.phone, tellOn = hasMail && tellWas !== false;
		dialog('<h3>Change ' + esc(e.name) + '’s lesson?</h3><ul class="scal-ch">' + changes.map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('') + '</ul>' +
			(warnings && warnings.length ? '<div class="scal-wbox"><b>Please check:</b><ul>' + warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></div>' : '') +
			'<label class="scal-chk"><input type="checkbox" name="tell" ' + (hasMail ? (tellOn ? 'checked' : '') : 'disabled') + '> Tell the student by e-mail' + (hasMail ? '' : ' <small>(no e-mail on file' + (hasPhone ? ' — call or text ' + esc(e.phone) : '') + ')</small>') + '</label>' +
			'<div class="scal-dbtn"><button type="button" class="scal-btn scal-btn--ghost" data-close>Keep as it was</button><button type="button" class="scal-btn" data-ok>' + (warnings && warnings.length ? 'Change anyway' : 'Change') + '</button></div><p class="scal-derr" role="alert"></p>', function (I, close) {
			I.querySelector('[data-ok]').addEventListener('click', function () {
				var b = this; b.disabled = true; b.textContent = 'Saving…';
				var d = Object.assign({}, data, { tell: I.querySelector('[name=tell]').checked ? 1 : '', force: warnings && warnings.length ? 1 : '' });
				post('move', d).then(function (r) {
					if (r && r.success && r.data.confirm) { var tw = I.querySelector('[name=tell]').checked; close(); confirmMove(e, data, changes, r.data.warnings, tw); return; }
					if (!r || !r.success) { b.disabled = false; b.textContent = 'Try again'; I.querySelector('.scal-derr').textContent = r && r.data && r.data.msg ? r.data.msg : 'Not saved.'; return; }
					close(); toast(r.data.msg); load(true);
				}).catch(function () { b.disabled = false; b.textContent = 'Try again'; I.querySelector('.scal-derr').textContent = 'No connection — not saved.'; });
			});
		});
	}

	function statusDialog(e, to) {
		var hrsLeft = (e.a - now()) / 3600000, late = to === 'cancel' && hrsLeft < C.hours;
		var txt = {
			cancel: late ? 'This is less than ' + C.hours + ' hours before the lesson, so it counts as a late cancellation. The fee is not charged — it goes to the manager to decide.' : 'Cancelled in time — no fee.',
			done: 'Mark the lesson as completed. The instructor’s evaluation can still be added in Evaluations & records.',
			no_show: 'The student did not come. A missed-lesson fee is noted for the manager to decide — nothing is charged or sent.',
			no_permit: 'The student came without a valid learner’s permit, so the lesson did not happen. A fee is noted for the manager to decide — nothing is charged or sent.'
		}[to];
		var title = { cancel: 'Cancel this lesson?', done: 'Lesson completed?', no_show: 'Record a no-show?', no_permit: 'Record “no permit”?' }[to];
		dialog('<h3>' + esc(title) + '</h3><p><b>' + esc(e.name) + '</b> · ' + esc(dlong(e.a) + ', ' + hm(e.a)) + '</p><p>' + esc(txt) + '</p>' +
			(to === 'cancel' ? '<label class="scal-chk"><input type="checkbox" name="tell"' + (e.email && !late ? ' checked' : '') + (e.email ? '' : ' disabled') + '> Tell the student by e-mail' + (e.email ? (late ? ' <small>(late — the manager may want to call first)</small>' : '') : ' <small>(no e-mail on file)</small>') + '</label>' : '') +
			'<div class="scal-dbtn"><button type="button" class="scal-btn scal-btn--ghost" data-close>Back</button><button type="button" class="scal-btn' + (to === 'cancel' ? ' scal-btn--danger' : to === 'done' ? '' : ' scal-btn--warn') + '" data-ok>' + esc({ cancel: 'Cancel lesson', done: 'Completed', no_show: 'Record no-show', no_permit: 'Record no permit' }[to]) + '</button></div><p class="scal-derr" role="alert"></p>', function (I, close) {
			I.querySelector('[data-ok]').addEventListener('click', function () {
				var b = this; b.disabled = true; b.textContent = 'Saving…';
				var t = I.querySelector('[name=tell]');
				post('status', { id: e.id, to: to, tell: t && t.checked ? 1 : '' }).then(function (r) {
					if (!r || !r.success) { b.disabled = false; b.textContent = 'Try again'; I.querySelector('.scal-derr').textContent = r && r.data && r.data.msg ? r.data.msg : 'Not saved.'; return; }
					close(); closePanel(); toast(r.data.msg); load(true);
				});
			});
		});
	}

	function blockDialog(a, b, c) {
		var type = c && c.ins ? 'instructor' : c && c.plate ? 'vehicle' : 'all';
		var insId = c && c.ins ? (C.ins.filter(function (i) { return i.name === c.ins; })[0] || {}).id : '';
		var carId = c && c.plate ? (C.cars.filter(function (x) { return x.plate === c.plate; })[0] || {}).id : '';
		dialog('<h3>Block time</h3><p>No lessons can be booked in blocked time.</p>' +
			'<div class="scal-row"><label>Who<select name="type"><option value="all">The whole school (closed)</option><option value="instructor">One instructor (time off)</option><option value="vehicle">One car (service)</option></select></label></div>' +
			'<div class="scal-row" data-for="instructor"><label>Instructor<select name="ti">' + C.ins.map(function (i) { return '<option value="' + i.id + '">' + esc(i.name) + '</option>'; }).join('') + '</select></label></div>' +
			'<div class="scal-row" data-for="vehicle"><label>Car<select name="tc">' + C.cars.map(function (x) { return '<option value="' + x.id + '">' + esc(x.plate) + '</option>'; }).join('') + '</select></label></div>' +
			'<div class="scal-row"><label>From<input type="datetime-local" name="a" step="1800" value="' + a.replace(' ', 'T') + '"></label><label>To<input type="datetime-local" name="b" step="1800" value="' + b.replace(' ', 'T') + '"></label></div>' +
			'<div class="scal-row"><label class="scal-wide">Reason (shown on the calendar)<input name="why" maxlength="200" placeholder="e.g. Holiday, doctor, oil change"></label></div>' +
			'<div class="scal-dbtn"><button type="button" class="scal-btn scal-btn--ghost" data-close>Back</button><button type="button" class="scal-btn scal-btn--danger" data-ok>Block</button></div><p class="scal-derr" role="alert"></p>', function (I, close) {
			var T = I.querySelector('[name=type]'); T.value = type; if (insId) { I.querySelector('[name=ti]').value = insId; } if (carId) { I.querySelector('[name=tc]').value = carId; }
			function sync() { I.querySelectorAll('[data-for]').forEach(function (r) { r.hidden = r.getAttribute('data-for') !== T.value; }); }
			T.addEventListener('change', sync); sync();
			I.querySelector('[data-ok]').addEventListener('click', function () {
				var bt = this, A = I.querySelector('[name=a]').value.replace('T', ' ').slice(0, 16), B = I.querySelector('[name=b]').value.replace('T', ' ').slice(0, 16);
				if (!A || !B || B <= A) { I.querySelector('.scal-derr').textContent = 'Choose a start and an end after it.'; return; }
				bt.disabled = true; bt.textContent = 'Saving…';
				post('block', { type: T.value, target: T.value === 'instructor' ? I.querySelector('[name=ti]').value : T.value === 'vehicle' ? I.querySelector('[name=tc]').value : '', start: A, end: B, reason: I.querySelector('[name=why]').value }).then(function (r) {
					if (!r || !r.success) { bt.disabled = false; bt.textContent = 'Try again'; I.querySelector('.scal-derr').textContent = r && r.data && r.data.msg ? r.data.msg : 'Not saved.'; return; }
					close(); toast(r.data.msg, /move or cancel/.test(r.data.msg)); load(true);
				});
			});
		});
	}

	function blockInfo(id) {
		var b = S.blocked.filter(function (x) { return x.id === id; })[0]; if (!b) { return; }
		dialog('<h3>Blocked time</h3><p><b>' + esc(b.type === 'all' ? 'The whole school' : b.target) + '</b></p><p>' + esc(dlong(b.a) + ', ' + hm(b.a) + ' – ' + (dkey(b.a) !== dkey(b.b) ? dlong(b.b) + ', ' : '') + hm(b.b)) + '</p>' + (b.reason ? '<p>' + esc(b.reason) + '</p>' : '') +
			'<div class="scal-dbtn"><button type="button" class="scal-btn scal-btn--ghost" data-close>Close</button><button type="button" class="scal-btn scal-btn--danger" data-ok>Remove the block</button></div><p class="scal-derr" role="alert"></p>', function (I, close) {
			I.querySelector('[data-ok]').addEventListener('click', function () {
				var bt = this; bt.disabled = true;
				post('unblock', { id: id }).then(function (r) { if (!r || !r.success) { bt.disabled = false; I.querySelector('.scal-derr').textContent = 'Not removed.'; return; } close(); toast(r.data.msg); load(true); });
			});
		});
	}

	var tt;
	function toast(msg, bad) {
		var T = document.getElementById('scal-toast'); if (!T) { return; }
		T.textContent = msg; T.className = 'scal-toast is-on' + (bad ? ' is-bad' : '');
		clearTimeout(tt); tt = setTimeout(function () { T.className = 'scal-toast'; }, bad ? 9000 : 5000);
	}

	boot();
	if (!booted) { document.addEventListener('DOMContentLoaded', boot); }
})();
