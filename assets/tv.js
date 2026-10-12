/* Sam's Driving School — lobby TV. Polls /sdsc/v1/tv every 15 s, works out each lesson's state from the
   clock every few seconds, pages through long lists, rotates views when asked. */
(function () {
  'use strict';
  var C = window.STV, root = document.getElementById('stv');
  if (!C || !root) { return; }
  var D = C.first, t0 = Date.now(), base = D.now, reloadAt = D.reload, view = null, page = 0, rotTimer = null, pageTimer = null;
  var body = root.querySelector('.stv-body'), stats = root.querySelector('.stv-stats');

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  /* Server "local" epoch: PHP sends wall-clock time as if it were UTC, so read it back with UTC getters. */
  function now() { return base + (Date.now() - t0) / 1000; }
  function hm(ts) { var d = new Date(ts * 1000), h = d.getUTCHours(), m = d.getUTCMinutes(); return ((h % 12) || 12) + ':' + (m < 10 ? '0' : '') + m + ' ' + (h < 12 ? 'AM' : 'PM'); }
  function mins(s) { s = Math.max(0, Math.round(s / 60)); return s >= 60 ? Math.floor(s / 60) + ' h ' + (s % 60 ? s % 60 + ' min' : '') : s + ' min'; }

  function state(l) {
    var n = now(), st = (l.status || '').toLowerCase();
    if (st === 'cancelled' || st === 'canceled') { return ['cancelled', 'Cancelled', 'red']; }
    if (st === 'no_show' || st === 'no-show') { return ['no_show', 'No-show', 'red']; }
    if (st === 'completed' || n >= l.b) { return ['finished', 'Lesson finished', 'done']; }
    if (n >= l.a) { return ['on_road', 'On the road', 'green']; }
    if (n >= l.a - 900) { return ['ready', 'Ready to start class', 'yellow']; }
    return ['waiting', 'Awaiting class start', 'blue'];
  }
  var ORDER = { on_road: 0, ready: 1, waiting: 2, finished: 3, no_show: 4, cancelled: 5 };

  function fuel(f, down) {
    if (down) { return '<div class="stv-fuel is-down"><b>OUT OF SERVICE</b></div>'; }
    if (f == null) { return '<div class="stv-fuel is-na" title="No fuel report yet"><span>⛽</span><b>—</b></div>'; }
    var tone = f <= 25 ? 'low' : f <= 50 ? 'mid' : 'ok', bars = '';
    for (var i = 0; i < 10; i++) { bars += '<i class="' + (i < Math.round(f / 10) ? 'on' : '') + '"></i>'; }
    return '<div class="stv-fuel f-' + tone + '"><span>⛽ ' + f + '%</span><div class="stv-bars">' + bars + '</div></div>';
  }
  function plate(p) { return p ? '<div class="stv-plate"><i>MARYLAND</i><b>' + esc(p) + '</b></div>' : '<div class="stv-plate is-none"><b>Car TBA</b></div>'; }
  function sub(l, s) {
    var n = now();
    if (s[0] === 'waiting') { return 'Starts at ' + l.start + ' · in ' + mins(l.a - n); }
    if (s[0] === 'ready') { return 'Starts in ' + mins(l.a - n); }
    if (s[0] === 'on_road') { return 'Back about ' + hm(l.b) + ' · ' + mins(l.b - n) + ' left'; }
    if (s[0] === 'finished') { return 'Finished ' + hm(Math.min(l.b, n)); }
    return l.time;
  }

  function lessonCard(l) {
    var s = state(l);
    return '<article class="stv-card t-' + s[2] + ' s-' + s[0] + '">' +
      '<div class="stv-ct">' + plate(l.plate) + fuel(l.fuel, l.down) + '</div>' +
      '<span class="stv-badge"><i></i>' + esc(s[1]) + '</span>' +
      '<h2>' + esc(l.student) + '</h2>' +
      '<p class="stv-ins">👤 ' + esc(l.instructor || 'Instructor TBA') + '</p>' +
      '<p class="stv-meta">Lesson ' + l.n + (l.total ? ' of ' + l.total : '') + (l.car ? ' · ' + esc(l.car) : '') + '</p>' +
      '<footer><b>🕒 ' + esc(l.time) + '</b><span>' + esc(sub(l, s)) + '</span></footer></article>';
  }
  function carCard(c) {
    var nowL = null, next = null;
    D.lessons.forEach(function (l) { if (l.plate !== c.plate) { return; } var s = state(l)[0]; if (s === 'on_road') { nowL = l; } if ((s === 'waiting' || s === 'ready') && !next) { next = l; } });
    var st = c.down ? ['Out of service', 'red'] : nowL ? ['On the road', 'green'] : next ? ['Available', 'blue'] : ['Available', 'done'];
    return '<article class="stv-card stv-car t-' + st[1] + '"><div class="stv-ct">' + plate(c.plate) + fuel(c.fuel, c.down) + '</div><span class="stv-badge"><i></i>' + esc(st[0]) + '</span>' +
      '<h2>' + esc(c.car || 'School car') + '</h2>' +
      (nowL ? '<p class="stv-ins">With ' + esc(nowL.instructor) + ' &amp; ' + esc(nowL.student) + '</p><p class="stv-meta">Back about ' + hm(nowL.b) + '</p>' : '') +
      (next ? '<footer><b>Next: ' + esc(next.start) + '</b><span>' + esc(next.instructor) + ' · ' + esc(next.student) + '</span></footer>' : '<footer><b>' + (c.check ? '🔧 Needs a check' : 'No more lessons today') + '</b><span></span></footer>') + '</article>';
  }
  function insRow(i) {
    var nowL = null, next = null, done = 0, tot = 0;
    D.lessons.forEach(function (l) { if (l.instructor !== i.name) { return; } var s = state(l)[0]; if (s === 'cancelled' || s === 'no_show') { return; } tot++; if (s === 'finished') { done++; } if (s === 'on_road') { nowL = l; } if ((s === 'waiting' || s === 'ready') && !next) { next = l; } });
    var st = nowL ? ['On the road', 'green'] : next ? ['Next at ' + next.start, next && state(next)[0] === 'ready' ? 'yellow' : 'blue'] : ['Done for today', 'done'];
    return '<div class="stv-row t-' + st[1] + '"><span class="stv-av">' + esc((i.name.split(' ')[0] || '?')[0] + (i.name.split(' ')[1] || ' ')[0]) + '</span><div class="stv-rn"><b>' + esc(i.name) + '</b><small>' + done + ' of ' + tot + ' lessons done</small></div>' +
      '<div class="stv-rs"><span class="stv-badge"><i></i>' + esc(st[0]) + '</span><small>' + (nowL ? esc(nowL.student) + ' · ' + esc(nowL.plate) + ' · back ' + hm(nowL.b) : next ? esc(next.student) + (next.plate ? ' · ' + esc(next.plate) : '') : '') + '</small></div></div>';
  }

  function current() {
    var m = D.mode;
    if (m !== 'rotate') { return m; }
    var list = ['slots', 'vehicles', 'instructors'], k = Math.floor(Date.now() / 1000 / D.rotate) % 3;
    return list[k];
  }

  function draw() {
    var v = current(), n = now();
    if (v !== view) { view = v; page = 0; }
    root.dataset.view = v;
    root.querySelector('.stv-viewname').textContent = { slots: 'Lessons', vehicles: 'Cars', instructors: 'Instructors' }[v];
    var L = D.lessons.slice().sort(function (a, b) { var x = ORDER[state(a)[0]], y = ORDER[state(b)[0]]; return x - y || a.a - b.a; });
    var onRoad = L.filter(function (l) { return state(l)[0] === 'on_road'; }).length;
    var nextL = L.filter(function (l) { var s = state(l)[0]; return s === 'waiting' || s === 'ready'; }).sort(function (a, b) { return a.a - b.a; })[0];
    var live = L.filter(function (l) { var s = state(l)[0]; return s !== 'cancelled' && s !== 'no_show'; }).length;
    stats.innerHTML = '<span><b>' + live + '</b> lesson' + (live === 1 ? '' : 's') + ' today</span><span class="g"><b>' + onRoad + '</b> on the road</span>' + (nextL ? '<span class="y">Next start <b>' + esc(nextL.start) + '</b></span>' : '') + '<span><b>' + D.cars.filter(function (c) { return !c.down; }).length + '</b> cars ready</span>';
    var items = v === 'vehicles' ? D.cars.map(carCard) : v === 'instructors' ? D.instructors.map(insRow) : L.map(lessonCard);
    if (!items.length) {
      body.innerHTML = '<div class="stv-empty"><span>🚗</span><h2>' + (v === 'vehicles' ? 'No cars set up yet' : 'No driving lessons scheduled for today') + '</h2><p>Questions? Ask the front desk or call (301) 726-3030.</p></div>';
      return;
    }
    var per = v === 'instructors' ? 8 : (window.innerWidth >= 1600 ? 8 : window.innerWidth >= 1100 ? 6 : 4);
    var pages = Math.max(1, Math.ceil(items.length / per));
    if (page >= pages) { page = 0; }
    body.innerHTML = '<div class="stv-grid' + (v === 'instructors' ? ' is-rows' : '') + '">' + items.slice(page * per, page * per + per).join('') + '</div>' +
      (pages > 1 ? '<div class="stv-pager">' + Array.apply(null, Array(pages)).map(function (_, i) { return '<i class="' + (i === page ? 'on' : '') + '"></i>'; }).join('') + '</div>' : '');
  }

  function chrome() {
    var a = root.querySelector('.stv-alert');
    if (D.alert && D.alert.text) { a.hidden = false; a.className = 'stv-alert l-' + (D.alert.level || 'info'); a.querySelector('p').textContent = D.alert.text; }
    else { a.hidden = true; }
    var t = root.querySelector('.stv-track'), txt = (D.ticker || []).map(esc).join('<i>•</i>');
    if (t.dataset.k !== txt) { t.dataset.k = txt; t.innerHTML = '<span>' + txt + '</span><span aria-hidden="true">' + txt + '</span>'; t.style.animationDuration = Math.max(25, txt.length / 6) + 's'; }
  }

  function clock() {
    var d = new Date(now() * 1000), h = d.getUTCHours(), m = d.getUTCMinutes();
    root.querySelector('.stv-time').textContent = ((h % 12) || 12) + ':' + (m < 10 ? '0' : '') + m + ' ' + (h < 12 ? 'AM' : 'PM');
    root.querySelector('.stv-date').textContent = D.day;
  }

  function poll() {
    fetch(C.api + (C.api.indexOf('?') > -1 ? '&' : '?') + '_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.lessons) { return; }
      if (reloadAt && d.reload && d.reload !== reloadAt) { location.reload(); return; }
      D = d; base = d.now; t0 = Date.now(); reloadAt = d.reload;
      root.classList.remove('is-offline'); chrome(); draw();
    }).catch(function () { root.classList.add('is-offline'); });
  }

  chrome(); clock(); draw();
  setInterval(clock, 1000);
  setInterval(draw, 10000);
  setInterval(function () { page++; draw(); }, 12000);
  setInterval(poll, 15000);
  // Recover from a long sleep (TV turned off overnight).
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });

  root.querySelector('.stv-fs').addEventListener('click', function () { var e = document.documentElement; (e.requestFullscreen || e.webkitRequestFullscreen || function () {}).call(e); });

  /* starfield */
  var cv = root.querySelector('.stv-stars'), cx = cv.getContext && cv.getContext('2d'), stars = [];
  function size() { cv.width = window.innerWidth; cv.height = window.innerHeight; stars = []; for (var i = 0; i < 140; i++) { stars.push([Math.random() * cv.width, Math.random() * cv.height, Math.random() * 1.4 + 0.2, Math.random() * Math.PI * 2]); } }
  if (cx && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    size(); window.addEventListener('resize', function () { size(); draw(); });
    (function tick(t) { cx.clearRect(0, 0, cv.width, cv.height); stars.forEach(function (s) { cx.globalAlpha = 0.35 + 0.35 * Math.sin(t / 900 + s[3]); cx.fillStyle = '#cfe8ff'; cx.beginPath(); cx.arc(s[0], s[1], s[2], 0, 7); cx.fill(); }); requestAnimationFrame(tick); })(0);
  }
})();
