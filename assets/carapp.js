/* Sam's Driving School — in-car lesson app. Vanilla JS, no build step. */
(function () {
  'use strict';
  var S = window.SCA;
  var root = document.getElementById('sca');
  if (!S || !root) { return; }
  var main = document.getElementById('sca-main');
  var foot = document.getElementById('sca-foot');

  /* ============================================================ helpers */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function $(sel, el) { return (el || document).querySelector(sel); }
  function $$(sel, el) { return [].slice.call((el || document).querySelectorAll(sel)); }
  function api(path, opt) {
    opt = opt || {};
    var o = { method: opt.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': S.nonce, 'Accept': 'application/json' } };
    if (opt.body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opt.body); }
    return fetch(S.rest + path, o).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { var e = new Error((j && j.message) || ('Error ' + r.status)); e.status = r.status; throw e; } return j; });
    });
  }
  function toast(title, body, tone, icon) {
    if (window.SDSC_TOAST) { window.SDSC_TOAST({ title: title, body: body || '', tone: tone || '', icon: icon || (tone === 'bad' ? '⚠' : tone === 'good' ? '✓' : 'ℹ') }); }
  }
  function store(k, v) { try { if (v === undefined) { return JSON.parse(localStorage.getItem(k) || 'null'); } if (v === null) { localStorage.removeItem(k); } else { localStorage.setItem(k, JSON.stringify(v)); } } catch (e) { return null; } }
  function initials(n) { var p = String(n || '?').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); }
  function plate(p, small) { return p ? '<span class="sca-plate' + (small ? ' is-sm' : '') + '" title="Maryland plate ' + esc(p) + '"><i>MARYLAND</i>' + esc(p) + '</span>' : ''; }
  function tel(p) { return String(p || '').replace(/[^\d+]/g, ''); }
  function greet() { var h = new Date().getHours(); return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'; }
  function longDate() { try { return new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }); } catch (e) { return ''; } }
  function fmtTimer(sec) { sec = Math.max(0, Math.floor(sec)); var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60; return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s; }
  var STATUS = { upcoming: ['Scheduled', 'blue'], active: ['In progress', 'green'], completed: ['Report saved', 'done'], cancelled: ['Cancelled', 'grey'], canceled: ['Cancelled', 'grey'], no_show: ['No-show', 'red'] };
  var FUEL = [[10, 'E'], [25, '¼'], [50, '½'], [75, '¾'], [100, 'F']];
  var ISSUES = [['flat_tire', '🛞', 'Tire / pressure'], ['wipers_broken', '🌧', 'Wipers'], ['check_engine', '⚠️', 'Check-engine light'], ['brakes', '🛑', 'Brakes'], ['body_damage', '💥', 'Scrape / dent'], ['climate_control', '❄️', 'AC / heater'], ['noise', '🔊', 'Strange noise'], ['other', '❓', 'Something else']];
  var LEG = { 4: 'No coaching', 3: 'Occasional coaching', 2: 'Significant coaching', 1: 'Not adequate yet' };

  /* modal dialog (no browser alert/confirm — they freeze some in-car tablets) */
  function ask(title, html, okText, cancelText, tone) {
    return new Promise(function (res) {
      var d = document.createElement('div');
      d.className = 'sca-modal';
      d.innerHTML = '<div class="sca-dlg" role="alertdialog" aria-modal="true" aria-labelledby="sca-dt"><h3 id="sca-dt">' + esc(title) + '</h3><div class="sca-dbody">' + html + '</div><div class="sca-dact">' + (cancelText ? '<button type="button" class="sca-b sca-b--ghost" data-r="0">' + esc(cancelText) + '</button>' : '') + '<button type="button" class="sca-b ' + (tone === 'bad' ? 'sca-b--bad' : 'sca-b--pri') + '" data-r="1">' + esc(okText || 'OK') + '</button></div></div>';
      root.appendChild(d);
      var ok = d.querySelector('[data-r="1"]'); ok.focus();
      d.addEventListener('click', function (e) { var b = e.target.closest('[data-r]'); if (b || e.target === d) { d.remove(); res(b ? b.dataset.r === '1' : false); } });
      d.addEventListener('keydown', function (e) { if (e.key === 'Escape') { d.remove(); res(false); } });
    });
  }

  /* ============================================================ state */
  var A = { data: null, tab: null, ins: store('sca-ins') || 0, loading: false, cal: 0 };
  var L = null; // the open lesson
  var timerTick = null;

  /* ============================================================ router */
  function go(hash, replace) { if (location.hash !== hash) { history[replace ? 'replaceState' : 'pushState'](null, '', hash); } route(); }
  window.addEventListener('popstate', route);
  function route() {
    var h = location.hash || '#/';
    var m = h.match(/^#\/lesson\/(\d+)/);
    clearInterval(timerTick);
    if (m) { openLesson(parseInt(m[1], 10)); return; }
    if (h.indexOf('#/walkin') === 0) { openWalkin(); return; }
    L = null; renderHome();
  }

  /* ============================================================ agenda */
  function loadAgenda(silent) {
    if (A.loading) { return Promise.resolve(A.data); }
    A.loading = true;
    root.classList.add('is-syncing');
    return api('car/agenda' + (S.office && A.ins ? '?ins=' + A.ins : '')).then(function (d) {
      var before = A.data;
      A.data = d; A.cal = d.cal;
      if (before && silent) { markChanges(before, d); }
      return d;
    }).catch(function (e) {
      if (!silent) { main.innerHTML = '<div class="sca-empty"><b>Couldn’t load your lessons</b><p>' + esc(e.message) + '</p><button type="button" class="sca-b sca-b--pri" data-act="retry">Try again</button></div>'; }
      throw e;
    }).finally(function () { A.loading = false; root.classList.remove('is-syncing'); });
  }

  var changed = {};
  function sig(x) { return [x.start, x.end, x.instructor, x.plate, x.status].join('|'); }
  function markChanges(a, b) {
    var old = {};
    ['due', 'today', 'next'].forEach(function (k) { (a[k] || []).forEach(function (x) { old[x.id] = x; }); });
    ['due', 'today', 'next'].forEach(function (k) { (b[k] || []).forEach(function (x) { if (!old[x.id]) { changed[x.id] = 'New'; } else if (sig(old[x.id]) !== sig(x)) { changed[x.id] = 'Updated'; } }); });
  }

  function all() { var d = A.data || {}, seen = {}; return [].concat(d.due || [], d.today || [], d.next || []).filter(function (x) { if (seen[x.id]) { return false; } seen[x.id] = 1; return true; }); }
  function findLesson(id) { return all().filter(function (x) { return x.id === id; })[0] || null; }

  function card(x) {
    var st = STATUS[x.status] || [x.status, 'grey'];
    var open = x.status === 'upcoming' || x.status === 'active';
    var due = !!x.due;
    var ch = changed[x.id];
    var tags = [];
    if (x.plate) { tags.push(plate(x.plate, true) + (x.car ? '<span class="sca-tag">' + esc(x.car) + '</span>' : '')); }
    if (x.first) { tags.push('<span class="sca-tag t-blue">First lesson</span>'); }
    if (x.prev) { tags.push('<span class="sca-tag t-amber" title="Last lesson was with another instructor">↪ Was with ' + esc(x.prev) + '</span>'); }
    if (S.office && !A.ins) { tags.push('<span class="sca-tag">👤 ' + esc(x.instructor || 'No instructor') + '</span>'); }
    var lessonOf = 'Lesson ' + x.number + (x.total ? ' of ' + x.total : '');
    return '<article class="sca-card' + (due ? ' is-due' : '') + (ch ? ' is-changed' : '') + (open ? '' : ' is-closed') + '" data-id="' + x.id + '">' +
      (ch ? '<span class="sca-flag">' + esc(ch) + '</span>' : '') +
      '<div class="sca-when"><b>' + esc(x.time.split('–')[0]) + '</b><small>' + esc(x.time.split('–')[1] || '') + '</small><em>' + esc(x.day) + '</em></div>' +
      '<div class="sca-cb"><div class="sca-ct"><span class="sca-av2" aria-hidden="true">' + esc(initials(x.student.name)) + '</span><div><h3>' + esc(x.student.name) + '</h3><p>' + esc(lessonOf) + (x.mins ? ' · ' + x.mins + ' min' : '') + '</p></div><span class="sca-st s-' + st[1] + '">' + esc(due ? 'Report missing' : st[0]) + '</span></div>' +
      (tags.length ? '<div class="sca-tags">' + tags.join('') + '</div>' : '') +
      '<div class="sca-ca">' +
      (x.student.phone ? '<a class="sca-b sca-b--ghost sca-b--icon" href="tel:' + esc(tel(x.student.phone)) + '" aria-label="Call ' + esc(x.student.name) + '">📞<span>Call</span></a>' : '') +
      (x.student.uid && window.SDSC_CHAT ? '<button type="button" class="sca-b sca-b--ghost sca-b--icon" data-act="msg" data-uid="' + x.student.uid + '" aria-label="Message ' + esc(x.student.name) + '">💬<span>Message</span></button>' : '') +
      (open ? '<a class="sca-b sca-b--pri" href="#/lesson/' + x.id + '">' + (due ? 'Write report' : 'Start lesson') + ' →</a>' : '<span class="sca-done">✓ ' + esc(st[0]) + '</span>') +
      '</div></div></article>';
  }

  function renderHome() {
    foot.hidden = true; foot.innerHTML = '';
    root.dataset.state = 'home';
    if (!A.data) {
      main.innerHTML = '<div class="sca-skel" aria-hidden="true"><i></i><i></i><i></i></div>';
      loadAgenda(false).then(renderHome).catch(function () {});
      return;
    }
    var d = A.data;
    var openToday = d.today.filter(function (x) { return x.status === 'upcoming' || x.status === 'active'; });
    if (!A.tab) { A.tab = openToday.length ? 'today' : d.due.length ? 'due' : d.today.length ? 'today' : 'next'; }
    var list = A.tab === 'due' ? d.due : A.tab === 'next' ? d.next : d.today;
    var who = S.me ? S.me.name.split(' ')[0] : (S.user || '').split(' ')[0];
    var groups = {}, order = [];
    list.forEach(function (x) { var k = A.tab === 'today' ? 'Today' : x.day; if (!groups[k]) { groups[k] = []; order.push(k); } groups[k].push(x); });
    var nextUp = openToday.filter(function (x) { return x.ts * 1000 > Date.now() - 30 * 60000; })[0];
    main.innerHTML =
      '<section class="sca-hero"><div><h1>' + esc(greet()) + (who ? ', ' + esc(who) : '') + '</h1><p>' + esc(longDate()) + '</p></div>' +
      (S.office && d.instructors ? '<label class="sca-pick"><span>Instructor</span><select data-act="ins"><option value="0">' + (S.me ? 'Me (' + esc(S.me.name) + ')' : 'Everyone') + '</option>' + d.instructors.map(function (i) { return '<option value="' + i.id + '"' + (String(i.id) === String(A.ins) ? ' selected' : '') + '>' + esc(i.name) + '</option>'; }).join('') + '</select></label>' : '') +
      '</section>' +
      (nextUp ? '<a class="sca-next" href="#/lesson/' + nextUp.id + '"><span class="sca-next-k">Next up · ' + esc(nextUp.time) + '</span><b>' + esc(nextUp.student.name) + '</b><span>Lesson ' + nextUp.number + (nextUp.plate ? ' · Car ' + esc(nextUp.plate) : '') + '</span><i aria-hidden="true">→</i></a>' : '') +
      '<nav class="sca-seg" role="tablist" aria-label="Lessons">' +
      tab('today', 'Today', d.today.length) + tab('due', 'Needs report', d.due.length, d.due.length ? 'bad' : '') + tab('next', 'Upcoming', d.next.length) +
      '</nav>' +
      '<div class="sca-list" role="tabpanel">' + (list.length ? order.map(function (k) { return (A.tab === 'today' ? '' : '<h2 class="sca-gh">' + esc(k) + '</h2>') + groups[k].map(card).join(''); }).join('') : emptyTab()) + '</div>' +
      '<div class="sca-walk"><a class="sca-b sca-b--ghost" href="#/walkin">＋ Lesson that isn’t on the schedule</a></div>';
  }
  function tab(k, label, n, tone) { return '<button type="button" role="tab" aria-selected="' + (A.tab === k) + '" class="sca-tab' + (A.tab === k ? ' is-on' : '') + '" data-tab="' + k + '">' + esc(label) + '<span class="sca-n' + (tone ? ' n-' + tone : '') + '">' + n + '</span></button>'; }
  function emptyTab() {
    var t = { today: ['No lessons today', 'Lessons the office books for you appear here right away.'], due: ['All reports are in', 'Nice work — every finished lesson has its report.'], next: ['Nothing booked yet', 'New bookings show up here and you get a notification.'] }[A.tab];
    return '<div class="sca-empty"><span class="sca-ei" aria-hidden="true">' + (A.tab === 'due' ? '✓' : '🚗') + '</span><b>' + esc(t[0]) + '</b><p>' + esc(t[1]) + '</p></div>';
  }

  /* ============================================================ lesson */
  function blankLesson(x, student) {
    var col = Math.max(1, Math.min(6, x ? x.number : (student ? student.next : 1)));
    return {
      x: x, s: student, col: col, r: { s: {}, c: {} }, plate: x ? x.plate : '', fuel: null, refuel: false, gallons: '', cost: '',
      inc: false, types: [], urgency: 'routine', desc: '', notes: '', result: 'Pass', sigI: '', sigS: '', photo: '',
      t0: 0, acc: 0, name: x ? x.student.name : '', changedBanner: ''
    };
  }
  function draftKey() { return 'sca-draft-' + (L.x ? L.x.id : 'walk-' + (L.name || '').toLowerCase()); }
  var saveT = null;
  function saveDraft() {
    clearTimeout(saveT);
    saveT = setTimeout(function () {
      if (!L) { return; }
      var d = {}; ['col', 'r', 'plate', 'fuel', 'refuel', 'gallons', 'cost', 'inc', 'types', 'urgency', 'desc', 'notes', 'result', 'sigI', 'sigS', 't0', 'acc'].forEach(function (k) { d[k] = L[k]; });
      d.at = Date.now();
      store(draftKey(), d);
      var s = $('.sca-saved'); if (s) { s.textContent = 'Draft saved on this device'; }
    }, 350);
  }
  function restoreDraft() {
    var d = store(draftKey());
    if (!d || Date.now() - (d.at || 0) > 7 * 864e5) { return false; }
    Object.keys(d).forEach(function (k) { if (k !== 'at') { L[k] = d[k]; } });
    return true;
  }

  function openLesson(id) {
    root.dataset.state = 'lesson';
    main.innerHTML = '<div class="sca-skel" aria-hidden="true"><i></i><i></i><i></i></div>';
    foot.hidden = true;
    var p = A.data ? Promise.resolve(A.data) : loadAgenda(false);
    p.then(function () {
      var x = findLesson(id);
      if (!x) { main.innerHTML = '<div class="sca-empty"><b>This lesson isn’t on your schedule any more</b><p>The office may have moved it to another instructor or cancelled it.</p><a class="sca-b sca-b--pri" href="#/">Back to my lessons</a></div>'; return; }
      return api('car/student?id=' + x.student.id + '&name=' + encodeURIComponent(x.student.name)).catch(function () { return null; }).then(function (s) {
        L = blankLesson(x, s);
        var had = restoreDraft();
        renderLesson();
        if (had) { toast('Draft restored', 'Everything you entered for this lesson is back.', 'good', '💾'); }
      });
    }).catch(function () {});
  }

  function openWalkin() {
    root.dataset.state = 'lesson';
    foot.hidden = true;
    var opts = (S.students || []).map(function (s) { return '<option value="' + esc(s.name) + '">'; }).join('');
    main.innerHTML = '<div class="sca-lesson"><a class="sca-back" href="#/">‹ My lessons</a><section class="sca-panel"><h2>Lesson that isn’t on the schedule</h2><p class="sca-hint">Pick the student. If they’re new, type their full legal name — the office will see the lesson in the records.</p>' +
      '<label class="sca-field"><span>Student</span><input type="text" list="sca-stu" id="sca-wname" autocomplete="off" placeholder="Start typing a name…"><datalist id="sca-stu">' + opts + '</datalist></label>' +
      '<label class="sca-field"><span>Lesson number</span><input type="number" id="sca-wnum" min="1" max="50" inputmode="numeric" placeholder="Filled in from the student’s record"></label>' +
      '<button type="button" class="sca-b sca-b--pri sca-b--wide" data-act="walk-go">Continue →</button></section></div>';
    $('#sca-wname').focus();
  }
  function walkGo() {
    var n = ($('#sca-wname').value || '').trim();
    if (n.length < 3) { toast('Type the student’s full name', '', 'bad'); return; }
    var hit = (S.students || []).filter(function (s) { return s.name.toLowerCase() === n.toLowerCase(); })[0];
    api('car/student?' + (hit ? 'id=' + hit.id + '&' : '') + 'name=' + encodeURIComponent(hit ? hit.name : n)).catch(function () { return null; }).then(function (s) {
      var num = parseInt($('#sca-wnum').value, 10) || (s ? s.next : 1);
      var nowD = new Date();
      L = blankLesson(null, s);
      L.name = s ? s.name : n;
      L.walk = { number: num, start: nowD };
      L.col = Math.max(1, Math.min(6, num));
      restoreDraft();
      renderLesson();
    });
  }

  function lessonNumber() { return L.x ? L.x.number : (L.walk ? L.walk.number : 1); }

  function renderLesson() {
    var x = L.x, s = L.s || { history: [], grid: { dates: {}, skills: {}, conds: {}, cols: {} }, total: 0, done: 0 };
    var name = x ? x.student.name : L.name;
    var num = lessonNumber();
    var total = (x && x.total) || s.total || 0;
    var done = s.done || 0;
    var phone = (x && x.student.phone) || s.phone;
    var uid = (x && x.student.uid) || s.uid;
    var pct = total ? Math.min(100, Math.round(done / total * 100)) : 0;
    var hist = (s.history || []).filter(function (h) { return h.status === 'completed' || (x && h.id === x.id); });
    var path = hist.map(function (h) {
      var cur = x && h.id === x.id;
      return '<li class="' + (cur ? 'is-cur' : 'is-done') + (h.mine ? ' is-mine' : '') + '"><b>' + (cur ? 'Now' : '#' + h.number) + '</b><span>' + esc(cur ? 'Lesson ' + num : h.day) + '</span><small>' + esc(cur ? 'You' : (h.mine ? 'You' : h.instructor)) + (h.result && h.result !== 'Pass' && !cur ? ' · ' + esc(h.result) : '') + '</small></li>';
    }).join('');
    if (!x) { path += '<li class="is-cur"><b>Now</b><span>Lesson ' + num + '</span><small>You</small></li>'; }

    main.innerHTML =
      '<div class="sca-lesson">' +
      '<a class="sca-back" href="#/">‹ My lessons</a>' +
      (L.changedBanner ? '<div class="sca-alert a-warn" role="status">' + L.changedBanner + '</div>' : '') +
      '<section class="sca-lh">' +
      '<div class="sca-lh-top"><span class="sca-av3" aria-hidden="true">' + esc(initials(name)) + '</span><div class="sca-lh-t"><h1>' + esc(name) + '</h1>' +
      '<p>Lesson ' + num + (total ? ' of ' + total : '') + (x ? ' · ' + esc(x.day) + ' ' + esc(x.time) : ' · not on the schedule') + '</p>' +
      (s.permit ? '<p class="sca-permit">Permit ' + esc(s.permit) + '</p>' : '') + '</div>' +
      '<div class="sca-lh-act">' + (phone ? '<a class="sca-b sca-b--ghost sca-b--icon" href="tel:' + esc(tel(phone)) + '">📞<span>Call</span></a>' : '') + (uid && window.SDSC_CHAT ? '<button type="button" class="sca-b sca-b--ghost sca-b--icon" data-act="msg" data-uid="' + uid + '">💬<span>Message</span></button>' : '') + '</div></div>' +
      (total ? '<div class="sca-progress"><div class="sca-bar2"><i style="width:' + pct + '%"></i></div><span><b>' + done + '</b> of ' + total + ' lessons done · <b>' + Math.max(0, total - done) + '</b> left</span></div>' : '') +
      '<div class="sca-lh-tags">' + (x && x.plate ? plate(x.plate) : '') + (x && x.car ? '<span class="sca-tag">' + esc(x.car) + '</span>' : '') + (x && x.prev ? '<span class="sca-tag t-amber">↪ New to you — last lesson was with ' + esc(x.prev) + '</span>' : '') + '<button type="button" class="sca-timer' + (L.t0 ? ' is-on' : '') + '" data-act="timer" aria-live="off"><span class="sca-tdot"></span><span class="sca-tval">' + (L.t0 || L.acc ? fmtTimer(elapsed()) : 'Start lesson timer') + '</span></button></div>' +
      '</section>' +
      (path ? '<section class="sca-path" aria-label="Lesson history"><h2>Lesson history <small>' + (hist.length > 1 ? 'all instructors' : '') + '</small></h2><ol>' + path + '</ol></section>' : '') +
      (s.note ? '<section class="sca-note"><span aria-hidden="true">📝</span><div><b>Note from ' + esc(s.note.by) + ' after lesson ' + s.note.number + ' (' + esc(s.note.date) + ')</b><p>' + esc(s.note.text) + '</p></div></section>' : '') +
      '<nav class="sca-steps" aria-label="Report sections"><a href="#sca-s1" data-step="1"><i>1</i>Skills<em data-prog="skills"></em></a><a href="#sca-s2" data-step="2"><i>2</i>Car<em data-prog="car"></em></a><a href="#sca-s3" data-step="3"><i>3</i>Sign<em data-prog="sign"></em></a></nav>' +
      skillsSection(s) + carSection() + signSection() +
      '</div>';

    foot.hidden = false;
    foot.innerHTML = '<div class="sca-foot-in"><div class="sca-fs"><b class="sca-fprog"></b><span class="sca-saved">Changes are kept on this device until you save</span></div><button type="button" class="sca-b sca-b--pri sca-b--save" data-act="save">Save report</button></div>';
    setupPads();
    paintAll();
    startTimerTick();
    window.scrollTo(0, 0);
  }

  function skillsSection(s) {
    var g = s.grid || { skills: {}, conds: {}, cols: {}, dates: {} };
    var colInfo = g.cols || {};
    var other = [1, 2, 3, 4, 5, 6].filter(function (c) { return c !== L.col; });
    function histPills(kind, i) {
      var src = (kind === 's' ? g.skills : g.conds) || {};
      var row = src[i] || {};
      return other.map(function (c) {
        var v = row['l' + c]; if (!v) { return ''; }
        var ci = colInfo['l' + c];
        return '<i class="r' + esc(v) + '" title="Lesson ' + c + (ci ? ' · ' + esc(ci.instructor) + ' · ' + esc(ci.date) : '') + '">L' + c + '<b>' + esc(v) + '</b></i>';
      }).join('');
    }
    function row(kind, i, label, na) {
      var cur = (L.r[kind] || {})[i] || '';
      var btns = [4, 3, 2, 1].map(function (v) { return '<button type="button" class="v' + v + (String(cur) === String(v) ? ' is-on' : '') + '" data-rate="' + kind + ':' + i + ':' + v + '" aria-pressed="' + (String(cur) === String(v)) + '" title="' + esc(LEG[v]) + '">' + v + '</button>'; }).join('');
      if (na) { btns += '<button type="button" class="vna' + (cur === '' ? ' is-on' : '') + '" data-rate="' + kind + ':' + i + ':" aria-pressed="' + (cur === '') + '" title="Did not apply today">N/A</button>'; }
      var h = histPills(kind, i);
      return '<div class="sca-sk' + (cur ? ' is-set' : '') + '" data-row="' + kind + ':' + i + '"><div class="sca-skn"><b>' + esc(label) + '</b>' + (h ? '<span class="sca-hist">' + h + '</span>' : '') + '</div><div class="sca-rate" role="group" aria-label="' + esc(label) + '">' + btns + '</div></div>';
    }
    var colWarn = colInfo['l' + L.col] ? '<p class="sca-alert a-warn">Column L' + L.col + ' already holds lesson ' + colInfo['l' + L.col].number + ' (' + esc(colInfo['l' + L.col].instructor) + ', ' + esc(colInfo['l' + L.col].date) + '). Your ratings will take its place on the progress sheet.</p>' : '';
    return '<section class="sca-panel" id="sca-s1"><div class="sca-ph"><h2><i>1</i> Skills</h2>' +
      '<label class="sca-col">Progress sheet column <select data-act="col">' + [1, 2, 3, 4, 5, 6].map(function (c) { return '<option value="' + c + '"' + (c === L.col ? ' selected' : '') + '>L-' + c + (colInfo['l' + c] ? ' (' + esc(colInfo['l' + c].instructor.split(' ')[0]) + ')' : '') + '</option>'; }).join('') + '</select></label></div>' +
      colWarn +
      '<div class="sca-legend">' + [4, 3, 2, 1].map(function (v) { return '<span class="v' + v + '"><b>' + v + '</b>' + esc(LEG[v]) + '</span>'; }).join('') + '</div>' +
      '<div class="sca-quick"><button type="button" class="sca-b sca-b--ghost sca-b--sm" data-act="fill4">Rate the rest “4”</button><button type="button" class="sca-b sca-b--ghost sca-b--sm" data-act="clear">Clear my ratings</button></div>' +
      '<div class="sca-sks">' + S.skills.map(function (k, i) { return row('s', i, k, false); }).join('') + '</div>' +
      '<h3 class="sca-sub">Driving conditions <small>only when they applied</small></h3>' +
      '<div class="sca-sks">' + S.conds.map(function (k, i) { return row('c', i, k, true); }).join('') + '</div>' +
      '<details class="sca-sheet"><summary>See the whole progress sheet (all lessons)</summary>' + sheetTable(g) + '</details>' +
      '</section>';
  }

  function sheetTable(g) {
    var cols = [1, 2, 3, 4, 5, 6];
    var head = '<tr><th scope="col">Skill</th>' + cols.map(function (c) { var ci = (g.cols || {})['l' + c]; return '<th scope="col" class="' + (c === L.col ? 'is-cur' : '') + '">L-' + c + (ci ? '<small>' + esc(ci.instructor.split(' ')[0]) + '<br>' + esc(ci.date) + '</small>' : (c === L.col ? '<small>now</small>' : '')) + '</th>'; }).join('') + '</tr>';
    function line(kind, i, label) {
      var src = (kind === 's' ? g.skills : g.conds) || {};
      return '<tr><th scope="row">' + esc(label) + '</th>' + cols.map(function (c) { var v = c === L.col ? ((L.r[kind] || {})[i] || '') : ((src[i] || {})['l' + c] || ''); return '<td class="' + (c === L.col ? 'is-cur ' : '') + (v ? 'r' + esc(v) : '') + '">' + esc(v || '·') + '</td>'; }).join('') + '</tr>';
    }
    return '<div class="sca-tw"><table class="sca-table"><thead>' + head + '</thead><tbody>' + S.skills.map(function (k, i) { return line('s', i, k); }).join('') + S.conds.map(function (k, i) { return line('c', i, k); }).join('') + '</tbody></table></div>';
  }

  function carSection() {
    var opts = '<option value="">Choose the car…</option>' + (S.vehicles || []).map(function (v) { return '<option value="' + esc(v.plate) + '"' + (v.plate === L.plate ? ' selected' : '') + '>' + esc(v.plate) + (v.model ? ' — ' + esc(v.model) : '') + '</option>'; }).join('');
    return '<section class="sca-panel" id="sca-s2"><div class="sca-ph"><h2><i>2</i> Car check</h2></div>' +
      '<label class="sca-field"><span>Car used</span><select data-act="plate">' + opts + '</select></label>' +
      '<div class="sca-field"><span>Fuel left after the lesson <em class="sca-req">required</em></span><div class="sca-fuel" role="radiogroup" aria-label="Fuel level">' +
      FUEL.map(function (f) { return '<button type="button" data-fuel="' + f[0] + '" class="' + (L.fuel === f[0] ? 'is-on ' : '') + (f[0] <= 25 ? 'f-low' : f[0] <= 50 ? 'f-mid' : 'f-ok') + '" aria-pressed="' + (L.fuel === f[0]) + '"><b>' + f[1] + '</b><small>' + f[0] + '%</small></button>'; }).join('') +
      '</div></div>' +
      '<div class="sca-lowfuel"' + (L.fuel !== null && L.fuel <= 25 ? '' : ' hidden') + '><p><b>⛽ Low fuel — refuel before the next lesson.</b> MVA practice: stop at a gas station with the student and teach safe refueling.</p>' +
      '<label class="sca-check"><input type="checkbox" data-act="refuel"' + (L.refuel ? ' checked' : '') + '> I did (or will do) the refueling lesson with the student</label>' +
      '<div class="sca-two"><label class="sca-field"><span>Gallons added</span><input type="number" step="0.1" min="0" inputmode="decimal" data-act="gallons" value="' + esc(L.gallons) + '" placeholder="e.g. 8.5"></label><label class="sca-field"><span>Cost ($)</span><input type="number" step="0.01" min="0" inputmode="decimal" data-act="cost" value="' + esc(L.cost) + '" placeholder="e.g. 28.50"></label></div></div>' +
      '<div class="sca-field"><span>Any problem with the car?</span><div class="sca-yn"><button type="button" data-inc="0" class="' + (!L.inc ? 'is-on y-ok' : '') + '" aria-pressed="' + !L.inc + '">✓ No, all good</button><button type="button" data-inc="1" class="' + (L.inc ? 'is-on y-bad' : '') + '" aria-pressed="' + !!L.inc + '">⚠ Yes, report it</button></div></div>' +
      '<div class="sca-inc"' + (L.inc ? '' : ' hidden') + '>' +
      '<div class="sca-chips">' + ISSUES.map(function (t) { var on = L.types.indexOf(t[0]) > -1; return '<button type="button" data-type="' + t[0] + '" class="' + (on ? 'is-on' : '') + '" aria-pressed="' + on + '">' + t[1] + ' ' + esc(t[2]) + '</button>'; }).join('') + '</div>' +
      '<div class="sca-yn sca-urg"><button type="button" data-urg="routine" class="' + (L.urgency === 'routine' ? 'is-on y-warn' : '') + '">🟡 Safe to drive — fix soon</button><button type="button" data-urg="urgent_ground" class="' + (L.urgency === 'urgent_ground' ? 'is-on y-bad' : '') + '">🔴 Not safe — take the car off the road</button></div>' +
      '<label class="sca-field"><span>What happened? <em class="sca-req">required</em></span><textarea rows="3" data-act="desc" placeholder="e.g. Rear right tire pressure light came on; tire looks low.">' + esc(L.desc) + '</textarea></label>' +
      '</div></section>';
  }

  function signSection() {
    return '<section class="sca-panel" id="sca-s3"><div class="sca-ph"><h2><i>3</i> Notes & signatures</h2></div>' +
      '<div class="sca-field"><span>Result of this lesson</span><div class="sca-yn"><button type="button" data-res="Pass" class="' + (L.result === 'Pass' ? 'is-on y-ok' : '') + '">✓ Pass</button><button type="button" data-res="Fail" class="' + (L.result === 'Fail' ? 'is-on y-bad' : '') + '">Needs more practice</button></div></div>' +
      '<label class="sca-field"><span>Feedback for the student <small>(e-mailed to them and shown to the next instructor)</small></span><textarea rows="4" data-act="notes" placeholder="What went well, what to practice before the next lesson…">' + esc(L.notes) + '</textarea></label>' +
      '<div class="sca-field"><span>Photo in the car <small>(optional)</small></span><div class="sca-photo">' + (L.photo ? '<img src="' + L.photo + '" alt="Photo taken in the car">' : '<span class="sca-ph0">No photo</span>') + '<label class="sca-b sca-b--ghost sca-b--sm"><input type="file" accept="image/*" capture="user" data-act="photo" hidden>' + (L.photo ? 'Retake' : '📷 Take photo') + '</label>' + (L.photo ? '<button type="button" class="sca-b sca-b--ghost sca-b--sm" data-act="nophoto">Remove</button>' : '') + '</div></div>' +
      '<div class="sca-sigs">' + pad('sigI', 'Instructor signature') + pad('sigS', 'Student signature') + '</div>' +
      '</section>';
  }
  function pad(k, label) { return '<div class="sca-sig"><div class="sca-sigh"><span>' + esc(label) + ' <em class="sca-req">required</em></span><button type="button" class="sca-link" data-clear="' + k + '">Clear</button></div><div class="sca-pad' + (L[k] ? ' is-signed' : '') + '"><canvas data-pad="' + k + '" aria-label="' + esc(label) + ' — sign with your finger"></canvas><span class="sca-padh">Sign here</span></div></div>'; }

  /* ---------- signature pads */
  function setupPads() {
    $$('canvas[data-pad]').forEach(function (cv) {
      var k = cv.dataset.pad, ctx = cv.getContext('2d'), drawing = false, last = null;
      function size() {
        var r = cv.parentElement.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
        cv.width = Math.max(1, Math.round(r.width * dpr)); cv.height = Math.max(1, Math.round(r.height * dpr));
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1f33';
        if (L && L[k]) { var img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0, r.width, r.height); }; img.src = L[k]; }
      }
      size();
      cv._size = size;
      function pos(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
      cv.addEventListener('pointerdown', function (e) { drawing = true; last = pos(e); cv.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.arc(last.x, last.y, 1.1, 0, 7); ctx.fillStyle = '#0b1f33'; ctx.fill(); e.preventDefault(); });
      cv.addEventListener('pointermove', function (e) { if (!drawing) { return; } var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; e.preventDefault(); });
      function end() { if (!drawing) { return; } drawing = false; L[k] = cv.toDataURL('image/png'); cv.parentElement.classList.add('is-signed'); paintProgress(); saveDraft(); }
      cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end); cv.addEventListener('pointerleave', end);
    });
  }
  var rT = null;
  window.addEventListener('resize', function () { clearTimeout(rT); rT = setTimeout(function () { $$('canvas[data-pad]').forEach(function (c) { if (c._size) { c._size(); } }); }, 200); });

  /* ---------- timer */
  function elapsed() { return (L.acc || 0) + (L.t0 ? (Date.now() - L.t0) / 1000 : 0); }
  function startTimerTick() {
    clearInterval(timerTick);
    timerTick = setInterval(function () { var t = $('.sca-tval'); if (t && L && (L.t0 || L.acc)) { t.textContent = fmtTimer(elapsed()) + (L.t0 ? '' : ' (paused)'); } }, 1000);
  }

  /* ---------- progress + repaint */
  function counts() {
    var rated = Object.keys(L.r.s).filter(function (k) { return L.r.s[k]; }).length;
    var car = L.fuel !== null && (!L.inc || L.desc.trim().length > 2);
    var sig = (L.sigI ? 1 : 0) + (L.sigS ? 1 : 0);
    return { rated: rated, total: S.skills.length, car: car, sig: sig };
  }
  function paintProgress() {
    if (!L) { return; }
    var c = counts();
    var e = $('[data-prog="skills"]'); if (e) { e.textContent = c.rated + '/' + c.total; e.parentElement.classList.toggle('is-ok', c.rated === c.total); }
    e = $('[data-prog="car"]'); if (e) { e.textContent = c.car ? '✓' : '·'; e.parentElement.classList.toggle('is-ok', c.car); }
    e = $('[data-prog="sign"]'); if (e) { e.textContent = c.sig + '/2'; e.parentElement.classList.toggle('is-ok', c.sig === 2); }
    var f = $('.sca-fprog'); if (f) { f.textContent = 'Skills ' + c.rated + '/' + c.total + ' · Car ' + (c.car ? '✓' : '—') + ' · Signatures ' + c.sig + '/2'; }
    var sb = $('[data-act="save"]'); if (sb) { sb.classList.toggle('is-ready', c.rated > 0 && c.car && c.sig === 2); }
  }
  function paintAll() { paintProgress(); }

  /* ============================================================ save */
  function buildForm() {
    var x = L.x, g = (L.s && L.s.grid) || { dates: {}, skills: {}, conds: {} };
    var fd = new FormData();
    var instr = x ? x.instructor : (S.me ? S.me.name : '');
    var now = new Date();
    function mysql(d) { function p(n) { return (n < 10 ? '0' : '') + n; } return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':00'; }
    var start = x ? x.start : mysql(L.t0 || L.acc ? new Date(now - elapsed() * 1000) : now);
    var end = x ? x.end : mysql(now);
    fd.append('action', 'driveflow_submit_evaluation');
    fd.append('df_nonce', S.dfNonce);
    fd.append('session_id', x ? x.id : 0);
    fd.append('student_name', x ? x.student.name : L.name);
    fd.append('instructor_name', instr);
    fd.append('instructor_cert_no', S.me ? S.me.license : '');
    fd.append('plate_number', L.plate || '');
    fd.append('session_number', lessonNumber());
    fd.append('lesson_topic', (x && x.topic) || 'Behind-the-wheel driving lesson');
    fd.append('scheduled_start', start);
    fd.append('scheduled_end', end);
    var lessonDate = (x ? x.start : start).slice(0, 10);
    for (var c = 1; c <= 6; c++) { fd.append('date_l' + c, c === L.col ? lessonDate : (g.dates['l' + c] || '')); }
    S.skills.forEach(function (_, i) { for (var c2 = 1; c2 <= 6; c2++) { var v = c2 === L.col ? (L.r.s[i] || '') : (((g.skills || {})[i] || {})['l' + c2] || ''); fd.append('skill_' + i + '_l' + c2, v); } });
    S.conds.forEach(function (_, i) { for (var c3 = 1; c3 <= 6; c3++) { var v = c3 === L.col ? (L.r.c[i] || '') : (((g.conds || {})[i] || {})['l' + c3] || ''); fd.append('cond_' + i + '_l' + c3, v); } });
    fd.append('final_evaluation', L.result);
    fd.append('fuel_level_pct', L.fuel);
    if (L.refuel) { fd.append('student_refuel_conducted', '1'); }
    fd.append('fuel_gallons', L.gallons || 0);
    fd.append('fuel_cost', L.cost || 0);
    fd.append('has_vehicle_incident', L.inc ? 'yes' : 'no');
    if (L.inc) { L.types.forEach(function (t) { fd.append('incident_types[]', t); }); fd.append('incident_urgency', L.urgency); fd.append('incident_description', L.desc); }
    fd.append('instructor_notes', L.notes);
    fd.append('instructor_signature_data', L.sigI);
    fd.append('student_signature_data', L.sigS);
    if (L.photo) { fd.append('selfie', L.photo); }
    return fd;
  }

  function jump(id, msg) { var el = document.getElementById(id); if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } if (msg) { toast(msg, '', 'bad'); } }

  function save() {
    var c = counts();
    if (!L.x && !(L.name || '').trim()) { toast('Student name is missing', '', 'bad'); return; }
    if (c.rated === 0) { jump('sca-s1', 'Rate at least the skills you practiced'); return; }
    if (L.fuel === null) { jump('sca-s2', 'Choose how much fuel is left'); return; }
    if (L.inc && L.desc.trim().length < 3) { jump('sca-s2', 'Describe the car problem for the office'); return; }
    if (!L.sigI || !L.sigS) { jump('sca-s3', 'Both signatures are needed'); return; }
    var steps = Promise.resolve(true);
    if (c.rated < c.total) {
      steps = steps.then(function (ok) { return ok && ask('Some skills are not rated', '<p>' + (c.total - c.rated) + ' of ' + c.total + ' skills have no rating for this lesson. That’s fine if you didn’t practice them today.</p>', 'Save anyway', 'Go back'); });
    }
    if (L.fuel <= 25 && !L.refuel) {
      steps = steps.then(function (ok) { return ok && ask('Low fuel', '<p>The car is at ' + L.fuel + '%. The office will be told the car needs fuel before the next lesson.</p>', 'OK, save', 'Go back'); });
    }
    steps.then(function (ok) {
      if (!ok) { return; }
      var btn = $('[data-act="save"]');
      btn.disabled = true; btn.textContent = 'Saving…';
      fetch(S.ajax, { method: 'POST', credentials: 'same-origin', body: buildForm() })
        .then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'The server answered in an unexpected way (' + r.status + ').' } }; }); })
        .then(function (res) {
          if (!res || !res.success) { throw new Error((res && res.data && res.data.message) || 'The report was not saved.'); }
          var sid = res.data.session_id;
          var mins = Math.round(elapsed() / 60);
          return api('car/saved', { method: 'POST', body: { session_id: sid, column: L.col, minutes: mins } }).catch(function () {}).then(function () { return res; });
        })
        .then(function (res) {
          store(draftKey(), null);
          done(res.data);
          loadAgenda(true).catch(function () {});
          if (window.SDSC_PULSE) { window.SDSC_PULSE(); }
        })
        .catch(function (e) {
          btn.disabled = false; btn.textContent = 'Save report';
          ask('Not saved yet', '<p>' + esc(e.message) + '</p><p>Your report is still on this device — you can try again when the signal is better.</p>', 'OK');
        });
    });
  }

  function done(d) {
    clearInterval(timerTick);
    foot.hidden = true;
    var name = L.x ? L.x.student.name : L.name, uid = (L.x && L.x.student.uid) || (L.s && L.s.uid);
    var nextOpen = (A.data ? A.data.today : []).filter(function (x) { return (x.status === 'upcoming' || x.status === 'active') && (!L.x || x.id !== L.x.id); })[0];
    main.innerHTML = '<div class="sca-ok"><span class="sca-okc" aria-hidden="true">✓</span><h1>Report saved</h1><p><b>' + esc(name) + '</b> · lesson ' + lessonNumber() + ' · ' + esc(L.result === 'Pass' ? 'Pass' : 'Needs more practice') + '</p><p class="sca-hint">The student gets their progress e-mail now. The office sees the report in the records' + (L.inc ? ' and has been alerted about the car problem' : '') + '.</p>' +
      '<div class="sca-okb">' + (nextOpen ? '<a class="sca-b sca-b--pri" href="#/lesson/' + nextOpen.id + '">Next: ' + esc(nextOpen.student.name) + ' · ' + esc(nextOpen.time.split('–')[0]) + ' →</a>' : '') + '<a class="sca-b ' + (nextOpen ? 'sca-b--ghost' : 'sca-b--pri') + '" href="#/">Back to my lessons</a>' + (uid && window.SDSC_CHAT ? '<button type="button" class="sca-b sca-b--ghost" data-act="msg" data-uid="' + uid + '">💬 Message ' + esc(name.split(' ')[0]) + '</button>' : '') + '</div></div>';
    L = null;
    window.scrollTo(0, 0);
  }

  /* ============================================================ events */
  root.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tab],[data-act],[data-rate],[data-fuel],[data-inc],[data-type],[data-urg],[data-res],[data-clear],[data-step]');
    if (!t) { return; }
    if (t.dataset.tab) { A.tab = t.dataset.tab; renderHome(); return; }
    if (t.dataset.step) { e.preventDefault(); jump('sca-s' + t.dataset.step); return; }
    var a = t.dataset.act;
    if (a === 'retry') { loadAgenda(false).then(route); return; }
    if (a === 'walk-go') { walkGo(); return; }
    if (a === 'msg') {
      var to = t.dataset.uid;
      api('chat/open', { method: 'POST', body: { to: to } }).then(function (r) { window.SDSC_CHAT(r.id); }).catch(function (er) { toast('Can’t open the conversation', er.message, 'bad'); });
      return;
    }
    if (!L) { return; }
    if (a === 'save') { save(); return; }
    if (a === 'timer') {
      if (L.t0) { L.acc = elapsed(); L.t0 = 0; } else { L.t0 = Date.now(); }
      t.classList.toggle('is-on', !!L.t0);
      $('.sca-tval').textContent = fmtTimer(elapsed()) + (L.t0 ? '' : ' (paused)');
      saveDraft(); return;
    }
    if (a === 'fill4') { S.skills.forEach(function (_, i) { if (!L.r.s[i]) { L.r.s[i] = '4'; } }); refreshSkills(); saveDraft(); return; }
    if (a === 'clear') { ask('Clear your ratings?', '<p>Only this lesson’s ratings are cleared. Earlier lessons stay as they are.</p>', 'Clear', 'Cancel', 'bad').then(function (ok) { if (ok) { L.r = { s: {}, c: {} }; refreshSkills(); saveDraft(); } }); return; }
    if (a === 'nophoto') { L.photo = ''; rerenderSign(); return; }
    if (t.dataset.rate) {
      var p = t.dataset.rate.split(':'), kind = p[0], i = p[1], v = p[2];
      L.r[kind][i] = (L.r[kind][i] === v && kind === 's') ? '' : v;
      var rowEl = t.closest('.sca-sk');
      $$('button', rowEl).forEach(function (b) { var on = b.dataset.rate === kind + ':' + i + ':' + (L.r[kind][i] || ''); b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on); });
      rowEl.classList.toggle('is-set', !!L.r[kind][i]);
      paintProgress(); saveDraft(); updateSheet(); return;
    }
    if (t.dataset.fuel) {
      L.fuel = parseInt(t.dataset.fuel, 10);
      $$('[data-fuel]').forEach(function (b) { var on = b === t; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on); });
      $('.sca-lowfuel').hidden = L.fuel > 25;
      paintProgress(); saveDraft(); return;
    }
    if (t.dataset.inc) {
      L.inc = t.dataset.inc === '1';
      $$('[data-inc]').forEach(function (b) { var on = b.dataset.inc === (L.inc ? '1' : '0'); b.classList.toggle('is-on', on); b.classList.toggle('y-ok', on && !L.inc); b.classList.toggle('y-bad', on && L.inc); b.setAttribute('aria-pressed', on); });
      $('.sca-inc').hidden = !L.inc;
      if (L.inc) { var ta = $('[data-act="desc"]'); if (ta) { ta.focus(); } }
      paintProgress(); saveDraft(); return;
    }
    if (t.dataset.type) {
      var ix = L.types.indexOf(t.dataset.type);
      if (ix > -1) { L.types.splice(ix, 1); } else { L.types.push(t.dataset.type); }
      t.classList.toggle('is-on', ix < 0); t.setAttribute('aria-pressed', ix < 0); saveDraft(); return;
    }
    if (t.dataset.urg) { L.urgency = t.dataset.urg; $$('[data-urg]').forEach(function (b) { var on = b === t; b.classList.toggle('is-on', on); b.classList.toggle('y-warn', on && L.urgency === 'routine'); b.classList.toggle('y-bad', on && L.urgency !== 'routine'); }); saveDraft(); return; }
    if (t.dataset.res) { L.result = t.dataset.res; $$('[data-res]').forEach(function (b) { var on = b === t; b.classList.toggle('is-on', on); b.classList.toggle('y-ok', on && L.result === 'Pass'); b.classList.toggle('y-bad', on && L.result !== 'Pass'); }); saveDraft(); return; }
    if (t.dataset.clear) {
      var k = t.dataset.clear; L[k] = '';
      var cv = $('canvas[data-pad="' + k + '"]'); var cx = cv.getContext('2d'); cx.save(); cx.setTransform(1, 0, 0, 1, 0, 0); cx.clearRect(0, 0, cv.width, cv.height); cx.restore();
      cv.parentElement.classList.remove('is-signed'); paintProgress(); saveDraft(); return;
    }
  });

  root.addEventListener('input', function (e) {
    var t = e.target, a = t.dataset.act;
    if (!L || !a) { return; }
    if (a === 'notes' || a === 'desc' || a === 'gallons' || a === 'cost') { L[a] = t.value; if (a === 'desc') { paintProgress(); } saveDraft(); }
  });
  root.addEventListener('change', function (e) {
    var t = e.target, a = t.dataset.act;
    if (a === 'ins') { A.ins = parseInt(t.value, 10) || 0; store('sca-ins', A.ins); A.data = null; A.tab = null; renderHome(); return; }
    if (!L) { return; }
    if (a === 'plate') { L.plate = t.value; saveDraft(); return; }
    if (a === 'refuel') { L.refuel = t.checked; saveDraft(); return; }
    if (a === 'col') { L.col = parseInt(t.value, 10); var sec = $('#sca-s1'); sec.outerHTML = skillsSection(L.s || {}); paintProgress(); saveDraft(); return; }
    if (a === 'photo' && t.files && t.files[0]) {
      var file = t.files[0], img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var m = 900, w = img.width, h = img.height, r = Math.min(1, m / Math.max(w, h));
        var c = document.createElement('canvas'); c.width = Math.round(w * r); c.height = Math.round(h * r);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        L.photo = c.toDataURL('image/jpeg', 0.8); URL.revokeObjectURL(url); rerenderSign();
      };
      img.src = url;
    }
  });
  root.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.id === 'sca-wname') { e.preventDefault(); walkGo(); } });

  function refreshSkills() { var sec = $('#sca-s1'); if (sec) { sec.outerHTML = skillsSection(L.s || {}); } paintProgress(); }
  function updateSheet() { var d = $('.sca-sheet'); if (d && d.open) { d.innerHTML = '<summary>See the whole progress sheet (all lessons)</summary>' + sheetTable((L.s && L.s.grid) || {}); d.open = true; } }
  function rerenderSign() { var sec = $('#sca-s3'); if (sec) { sec.outerHTML = signSection(); setupPads(); paintProgress(); saveDraft(); } }

  /* step nav highlight while scrolling */
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (ents) { ents.forEach(function (en) { if (en.isIntersecting) { var n = en.target.id.slice(-1); $$('.sca-steps a').forEach(function (a) { a.classList.toggle('is-cur', a.dataset.step === n); }); } }); }, { rootMargin: '-40% 0px -55% 0px' });
    new MutationObserver(function () { $$('.sca-panel[id^="sca-s"]').forEach(function (s) { if (!s._obs) { s._obs = 1; io.observe(s); } }); }).observe(main, { childList: true, subtree: true });
  }

  /* ============================================================ live updates */
  var calSeen = 0, refreshT = null;
  function liveRefresh() {
    clearTimeout(refreshT);
    refreshT = setTimeout(function () {
      loadAgenda(true).then(function () {
        if (root.dataset.state === 'home') { renderHome(); return; }
        if (L && L.x) {
          var now = findLesson(L.x.id);
          var msg = '';
          if (!now) { msg = '⚠ This lesson was taken off your schedule by the office. Saving may not be possible — check your messages.'; }
          else if (sig(now) !== sig(L.x)) {
            var parts = [];
            if (now.start !== L.x.start || now.end !== L.x.end) { parts.push('time is now ' + now.day + ' ' + now.time); }
            if (now.plate !== L.x.plate) { parts.push('car is now ' + (now.plate || 'not set')); L.plate = now.plate; }
            if (now.instructor !== L.x.instructor) { parts.push('instructor is now ' + now.instructor); }
            msg = 'ℹ The office updated this lesson: ' + parts.join(', ') + '.';
            L.x = now;
          }
          if (msg) { L.changedBanner = esc(msg); var b = $('.sca-alert.a-warn.sca-live'); if (b) { b.innerHTML = esc(msg); } else { var back = $('.sca-back'); if (back) { back.insertAdjacentHTML('afterend', '<div class="sca-alert a-warn sca-live" role="status">' + esc(msg) + '</div>'); } } }
        }
      }).catch(function () {});
    }, 600);
  }
  window.addEventListener('sdsc:pulse', function (e) {
    var p = e.detail || {};
    root.classList.add('is-live');
    if (!calSeen) { calSeen = p.cal; return; }
    if (p.cal && p.cal !== calSeen) { calSeen = p.cal; liveRefresh(); }
  });
  window.addEventListener('sdsc:note', function (e) { if (e.detail && (e.detail.kind === 'lesson' || e.detail.kind === 'report')) { liveRefresh(); } });
  // Without the notification script, still refresh every minute.
  setInterval(function () { if (!window.SDSC_PULSE && !document.hidden) { liveRefresh(); } }, 60000);
  window.addEventListener('online', function () { root.classList.remove('is-offline'); liveRefresh(); });
  window.addEventListener('offline', function () { root.classList.add('is-offline'); toast('You’re offline', 'Keep going — the report stays on this device. Save when the signal is back.', 'warn', '📶'); });
  window.addEventListener('beforeunload', function (e) { if (L && (counts().rated || L.sigI)) { saveDraft(); } });

  route();
})();
