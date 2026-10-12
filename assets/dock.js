/* Sam's Driving School — notifications (bell, toasts) and messages (chat). No dependencies. */
(function () {
  'use strict';
  var C = window.SDSC_DOCK;
  if (!C || !C.rest || window.SDSC_DOCK_READY) { return; }
  window.SDSC_DOCK_READY = true;

  /* ---------------------------------------------------------- helpers */
  function h(tag, cls, html) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (html != null) { e.innerHTML = html; } return e; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function linkify(s) {
    return esc(s).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');
  }
  function api(path, opt) {
    opt = opt || {};
    var o = { method: opt.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce, 'Accept': 'application/json' } };
    if (opt.body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opt.body); }
    return fetch(C.rest + path, o).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { throw new Error((j && j.message) || ('Error ' + r.status)); } return j; });
    });
  }
  function initials(n) { var p = String(n || '?').replace(/[^\p{L}\s]/gu, '').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); }
  var mobile = function () { return window.matchMedia('(max-width: 640px)').matches; };

  /* ---------------------------------------------------------- sound + browser alerts */
  var heard = false;
  document.addEventListener('pointerdown', function () { heard = true; }, { once: true, passive: true });
  function chime() {
    if (!heard) { return; }
    try {
      var A = window.AudioContext || window.webkitAudioContext; if (!A) { return; }
      var a = new A(), o = a.createOscillator(), g = a.createGain();
      o.type = 'sine'; o.frequency.setValueAtTime(660, a.currentTime); o.frequency.exponentialRampToValueAtTime(990, a.currentTime + 0.12);
      g.gain.setValueAtTime(0.08, a.currentTime); g.gain.exponentialRampToValueAtTime(0.001, a.currentTime + 0.35);
      o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + 0.36);
    } catch (e) {}
  }
  function browserAlert(n) {
    try {
      if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) { return; }
      var x = new Notification(n.title, { body: n.body || '', tag: 'sdsc-' + (n.ref || n.id) });
      x.onclick = function () { window.focus(); if (n.url) { location.href = n.url; } };
    } catch (e) {}
  }

  /* ---------------------------------------------------------- UI shell */
  var mount = document.getElementById('sdsc-dock-mount');
  var adminBell = document.getElementById('wp-admin-bar-sdsc-bell');
  var dock = h('div', 'sdk-dock' + (mount ? ' sdk-dock--inline' : ''));
  dock.innerHTML =
    (C.chat ? '<button type="button" class="sdk-btn sdk-btn--chat" aria-label="Messages" title="Messages"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16.1A2.5 2.5 0 0 1 4 14V5.5Z" fill="currentColor"/></svg><span class="sdk-badge" hidden></span></button>' : '') +
    (adminBell ? '' : '<button type="button" class="sdk-btn sdk-btn--bell" aria-label="Notifications" title="Notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a6 6 0 0 0-6 6v3.3l-1.6 2.9A1 1 0 0 0 5.3 17h13.4a1 1 0 0 0 .9-1.8L18 12.3V9a6 6 0 0 0-6-6Zm0 19a3 3 0 0 0 2.8-2H9.2A3 3 0 0 0 12 22Z" fill="currentColor"/></svg><span class="sdk-badge" hidden></span></button>');
  (mount || document.body).appendChild(dock);
  var toasts = h('div', 'sdk-toasts'); toasts.setAttribute('aria-live', 'polite'); document.body.appendChild(toasts);

  var panel = h('div', 'sdk-panel'); panel.hidden = true; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Notifications');
  document.body.appendChild(panel);
  var scrim = h('div', 'sdk-scrim'); scrim.hidden = true; document.body.appendChild(scrim);

  var bellBtn = dock.querySelector('.sdk-btn--bell') || adminBell;
  var chatBtn = dock.querySelector('.sdk-btn--chat');

  function setBadge(btn, n) {
    if (!btn) { return; }
    var b = btn.querySelector('.sdk-badge') || btn.querySelector('[data-sdsc-count]');
    if (!b) { return; }
    b.textContent = n > 99 ? '99+' : n; b.hidden = !n; b.style.display = n ? '' : 'none';
  }

  function closeAll() { panel.hidden = true; scrim.hidden = true; if (drawer) { drawer.classList.remove('is-open'); } document.documentElement.classList.remove('sdk-lock'); }
  scrim.addEventListener('click', closeAll);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAll(); } });

  /* ---------------------------------------------------------- notifications panel */
  function notePanel() {
    panel.innerHTML = '<div class="sdk-ph"><b>Notifications</b><button type="button" class="sdk-x" aria-label="Close">×</button></div><div class="sdk-ph-act"><button type="button" class="sdk-link" data-a="all">✓ Mark all read</button><button type="button" class="sdk-link" data-a="alerts">🔔 Phone/desktop alerts</button></div><div class="sdk-plist"><p class="sdk-empty">Loading…</p></div>';
    panel.hidden = false;
    if (mobile()) { scrim.hidden = false; document.documentElement.classList.add('sdk-lock'); }
    place();
    api('notes').then(function (rows) {
      var l = panel.querySelector('.sdk-plist');
      if (!rows.length) { l.innerHTML = '<p class="sdk-empty">Nothing new. Changes to your lessons, messages and reports will show up here.</p>'; return; }
      l.innerHTML = rows.map(function (n) {
        return '<a class="sdk-note' + (n.seen ? '' : ' is-new') + (n.tone ? ' t-' + n.tone : '') + '" href="' + esc(n.url || '#') + '" data-id="' + n.id + '"><span class="sdk-ni">' + esc(n.icon || '🔔') + '</span><span class="sdk-nt"><b>' + esc(n.title) + '</b>' + (n.body ? '<small>' + esc(n.body) + '</small>' : '') + '<em>' + esc(n.when) + '</em></span></a>';
      }).join('');
    }).catch(function (e) { panel.querySelector('.sdk-plist').innerHTML = '<p class="sdk-empty">' + esc(e.message) + '</p>'; });
  }
  function place() {
    if (mobile() || !bellBtn) { panel.style.top = ''; panel.style.left = ''; panel.style.right = ''; panel.classList.toggle('is-sheet', mobile()); return; }
    panel.classList.remove('is-sheet');
    var r = bellBtn.getBoundingClientRect(), w = Math.min(380, window.innerWidth - 24);
    var below = r.top < window.innerHeight / 2;
    panel.style.left = Math.max(12, Math.min(window.innerWidth - w - 12, r.right - w)) + 'px';
    panel.style.top = below ? (r.bottom + 8) + 'px' : '';
    panel.style.bottom = below ? '' : (window.innerHeight - r.top + 8) + 'px';
  }
  panel.addEventListener('click', function (e) {
    var a = e.target.closest('[data-a]'), x = e.target.closest('.sdk-x'), n = e.target.closest('.sdk-note');
    if (x) { closeAll(); return; }
    if (a && a.dataset.a === 'all') { api('notes/seen', { method: 'POST', body: {} }).then(function () { notePanel(); pulse(); }); return; }
    if (a && a.dataset.a === 'alerts') {
      if (!('Notification' in window)) { toast({ icon: 'ℹ', title: 'This browser can’t show alerts', body: 'Keep this page open to see new notifications.' }); return; }
      Notification.requestPermission().then(function (p) { toast({ icon: p === 'granted' ? '✓' : 'ℹ', title: p === 'granted' ? 'Alerts are on' : 'Alerts are off', body: p === 'granted' ? 'You’ll get an alert when this page is in the background.' : 'You can allow them later in the browser settings.' }); });
      return;
    }
    if (n) {
      api('notes/seen', { method: 'POST', body: { ids: [n.dataset.id] } }).catch(function () {});
      if (n.getAttribute('href') === '#') { e.preventDefault(); n.classList.remove('is-new'); }
      if (C.chat && /[?&]t=\d+/.test(n.getAttribute('href') || '') && !document.getElementById('sdsc-chat-page')) {
        e.preventDefault(); closeAll(); openChat(parseInt(n.getAttribute('href').match(/[?&]t=(\d+)/)[1], 10));
      }
    }
  });
  if (bellBtn) {
    bellBtn.addEventListener('click', function (e) {
      e.preventDefault();
      if (!panel.hidden) { closeAll(); return; }
      if (drawer) { drawer.classList.remove('is-open'); }
      notePanel();
    });
  }
  window.addEventListener('resize', function () { if (!panel.hidden) { place(); } });

  function toast(n) {
    var t = h('a', 'sdk-toast' + (n.tone ? ' t-' + n.tone : ''));
    t.href = n.url || '#';
    t.innerHTML = '<span class="sdk-ni">' + esc(n.icon || '🔔') + '</span><span class="sdk-nt"><b>' + esc(n.title) + '</b>' + (n.body ? '<small>' + esc(n.body) + '</small>' : '') + '</span><button type="button" class="sdk-x" aria-label="Dismiss">×</button>';
    t.addEventListener('click', function (e) {
      if (e.target.closest('.sdk-x')) { e.preventDefault(); t.remove(); return; }
      if (n.id) { api('notes/seen', { method: 'POST', body: { ids: [n.id] } }).catch(function () {}); }
      if (n.kind === 'chat' && C.chat && !document.getElementById('sdsc-chat-page')) { e.preventDefault(); t.remove(); var m = (n.url || '').match(/[?&]t=(\d+)/); openChat(m ? parseInt(m[1], 10) : 0); }
      else if (!n.url) { e.preventDefault(); t.remove(); }
    });
    toasts.appendChild(t);
    while (toasts.children.length > 3) { toasts.firstChild.remove(); }
    setTimeout(function () { t.classList.add('is-out'); setTimeout(function () { t.remove(); }, 400); }, 8000);
  }
  window.SDSC_TOAST = toast;

  /* ---------------------------------------------------------- pulse (polling) */
  var last = -1, timer = null, baseTitle = document.title;
  function pulse() {
    clearTimeout(timer);
    return api('pulse?after=' + Math.max(0, last)).then(function (p) {
      if (last >= 0) {
        (p['new'] || []).forEach(function (n) {
          if (n.kind === 'chat' && openThread && n.ref === 'chat-' + openThread && drawerVisible()) { return; }
          toast(n); browserAlert(n);
          window.dispatchEvent(new CustomEvent('sdsc:note', { detail: n }));
        });
        if ((p['new'] || []).length) { chime(); }
      }
      last = p.last || 0;
      setBadge(bellBtn, p.unread); setBadge(chatBtn, p.chat);
      var total = (p.unread || 0) + (p.chat || 0);
      document.title = (total ? '(' + total + ') ' : '') + baseTitle;
      window.dispatchEvent(new CustomEvent('sdsc:pulse', { detail: p }));
    }).catch(function () {}).then(function () {
      timer = setTimeout(pulse, document.hidden ? 60000 : 20000);
    });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { pulse(); if (openThread) { loadThread(true); } } });
  window.SDSC_PULSE = pulse;

  /* ---------------------------------------------------------- chat */
  var drawer = null, openThread = 0, threadLast = 0, threadTimer = null, chatRoot = null, page = document.getElementById('sdsc-chat-page');
  function drawerVisible() { return (drawer && drawer.classList.contains('is-open')) || !!page; }

  function chatShell(root, inline) {
    root.innerHTML =
      '<div class="sdk-chat' + (inline ? ' sdk-chat--page' : '') + '">' +
      '<section class="sdk-list"><header class="sdk-ch"><b>Messages</b>' + (inline ? '' : '<button type="button" class="sdk-x" aria-label="Close">×</button>') + '</header>' +
      (C.office ? '<div class="sdk-find"><input type="search" placeholder="Find a student or instructor…" aria-label="Find a person"></div>' : '') +
      '<div class="sdk-threads"><p class="sdk-empty">Loading…</p></div></section>' +
      '<section class="sdk-conv" hidden><header class="sdk-ch"><button type="button" class="sdk-back" aria-label="Back">‹</button><span class="sdk-av"></span><span class="sdk-ct"><b></b><small></small></span>' + (inline ? '' : '<button type="button" class="sdk-x" aria-label="Close">×</button>') + '</header>' +
      '<div class="sdk-msgs" role="log" aria-live="polite"></div>' +
      '<form class="sdk-compose"><textarea rows="1" maxlength="2000" placeholder="Write a message…" aria-label="Message"></textarea><button type="submit" class="sdk-send" aria-label="Send"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z" fill="currentColor"/></svg></button></form>' +
      '<p class="sdk-ro" hidden>The office can read this conversation but not write in it.</p></section></div>';
    chatRoot = root.querySelector('.sdk-chat');
    var ta = chatRoot.querySelector('textarea');
    ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(140, ta.scrollHeight) + 'px'; });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !mobile()) { e.preventDefault(); chatRoot.querySelector('.sdk-compose').requestSubmit(); } });
    chatRoot.querySelector('.sdk-compose').addEventListener('submit', function (e) { e.preventDefault(); send(); });
    chatRoot.querySelector('.sdk-back').addEventListener('click', function () { showList(); });
    chatRoot.addEventListener('click', function (e) {
      if (e.target.closest('.sdk-x')) { closeAll(); return; }
      var t = e.target.closest('[data-thread]'); if (t) { openConv(parseInt(t.dataset.thread, 10)); return; }
      var c = e.target.closest('[data-to]');
      if (c) { api('chat/open', { method: 'POST', body: { to: c.dataset.to } }).then(function (r) { openConv(r.id); }).catch(function (er) { toast({ icon: '⚠', title: er.message }); }); }
    });
    var find = chatRoot.querySelector('.sdk-find input'), ft = null;
    if (find) {
      find.addEventListener('input', function () {
        clearTimeout(ft);
        var q = find.value.trim();
        if (q.length < 2) { loadThreads(); return; }
        ft = setTimeout(function () {
          api('chat/find?q=' + encodeURIComponent(q)).then(function (rows) {
            chatRoot.querySelector('.sdk-threads').innerHTML = rows.length ? '<p class="sdk-sec">People</p>' + rows.map(contactRow).join('') : '<p class="sdk-empty">No one found.</p>';
          });
        }, 300);
      });
    }
  }

  function contactRow(c) { return '<button type="button" class="sdk-row" data-to="' + esc(c.to) + '"><span class="sdk-av">' + esc(initials(c.name)) + '</span><span class="sdk-rt"><b>' + esc(c.name) + '</b><small>' + esc(c.sub || '') + '</small></span><span class="sdk-go">New</span></button>'; }

  function loadThreads() {
    if (!chatRoot) { return Promise.resolve(); }
    return api('chat/threads').then(function (d) {
      var box = chatRoot.querySelector('.sdk-threads');
      var find = chatRoot.querySelector('.sdk-find input');
      if (find && find.value.trim().length >= 2) { return d; }
      var have = {};
      var rows = d.threads.map(function (t) {
        return '<button type="button" class="sdk-row' + (t.unread ? ' is-unread' : '') + (t.id === openThread ? ' is-on' : '') + '" data-thread="' + t.id + '"><span class="sdk-av k-' + esc(t.kind) + '">' + esc(initials(t.title)) + '</span><span class="sdk-rt"><b>' + esc(t.title) + '</b><small>' + (t.last ? (t.mine ? 'You: ' : '') + esc(t.last) : esc(t.sub)) + '</small></span><span class="sdk-meta">' + esc(t.at) + (t.unread ? '<i class="sdk-dot" aria-label="unread"></i>' : '') + '</span></button>';
      });
      d.threads.forEach(function (t) { have[t.title] = 1; });
      var start = d.contacts.filter(function (c) { return !have[c.name]; }).map(contactRow);
      box.innerHTML = (rows.length ? rows.join('') : '<p class="sdk-empty">No conversations yet.</p>') + (start.length ? '<p class="sdk-sec">Start a conversation</p>' + start.join('') : '');
      return d;
    }).catch(function (e) { chatRoot.querySelector('.sdk-threads').innerHTML = '<p class="sdk-empty">' + esc(e.message) + '</p>'; });
  }

  function showList() {
    openThread = 0; clearTimeout(threadTimer);
    chatRoot.classList.remove('is-conv');
    chatRoot.querySelector('.sdk-conv').hidden = true;
    loadThreads();
  }

  function openConv(id) {
    if (!id) { return; }
    openThread = id; threadLast = 0;
    var conv = chatRoot.querySelector('.sdk-conv');
    conv.hidden = false; chatRoot.classList.add('is-conv');
    conv.querySelector('.sdk-msgs').innerHTML = '<p class="sdk-empty">Loading…</p>';
    [].forEach.call(chatRoot.querySelectorAll('.sdk-row.is-on'), function (r) { r.classList.remove('is-on'); });
    var row = chatRoot.querySelector('[data-thread="' + id + '"]'); if (row) { row.classList.add('is-on'); row.classList.remove('is-unread'); var dot = row.querySelector('.sdk-dot'); if (dot) { dot.remove(); } }
    loadThread(false).then(function () { if (!mobile()) { conv.querySelector('textarea').focus(); } });
  }

  var lastDay = '';
  function bubble(m) {
    var out = '';
    if (m.day !== lastDay) { out += '<p class="sdk-day"><span>' + esc(m.day) + '</span></p>'; lastDay = m.day; }
    return out + '<div class="sdk-msg' + (m.mine ? ' is-mine' : '') + '" data-id="' + m.id + '">' + (m.mine ? '' : '<span class="sdk-who">' + esc(m.who) + '</span>') + '<div class="sdk-bub">' + linkify(m.body) + '</div><span class="sdk-time">' + esc(m.time) + '</span></div>';
  }

  function loadThread(incremental) {
    clearTimeout(threadTimer);
    var id = openThread; if (!id) { return Promise.resolve(); }
    return api('chat/thread/' + id + (incremental && threadLast ? '?after=' + threadLast : '')).then(function (d) {
      if (id !== openThread) { return; }
      var conv = chatRoot.querySelector('.sdk-conv'), box = conv.querySelector('.sdk-msgs');
      conv.querySelector('.sdk-ct b').textContent = d.title; conv.querySelector('.sdk-ct small').textContent = d.sub;
      conv.querySelector('.sdk-ch .sdk-av').textContent = initials(d.title);
      conv.querySelector('.sdk-compose').hidden = !d.write; conv.querySelector('.sdk-ro').hidden = !!d.write;
      var near = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
      if (!incremental || !threadLast) {
        lastDay = '';
        box.innerHTML = d.msgs.length ? d.msgs.map(bubble).join('') : '<p class="sdk-empty">Say hello 👋 — your message goes straight to ' + esc(d.title) + '.</p>';
        near = true;
      } else if (d.msgs.length) {
        var em = box.querySelector('.sdk-empty'); if (em) { em.remove(); }
        d.msgs.forEach(function (m) { if (!box.querySelector('[data-id="' + m.id + '"]')) { box.insertAdjacentHTML('beforeend', bubble(m)); } });
      }
      if (d.msgs.length) { threadLast = d.msgs[d.msgs.length - 1].id; }
      [].forEach.call(box.querySelectorAll('.sdk-msg.is-mine'), function (b) { b.classList.toggle('is-seen', parseInt(b.dataset.id, 10) <= d.seen); });
      if (near) { box.scrollTop = box.scrollHeight; }
      pulse();
    }).catch(function (e) { toast({ icon: '⚠', title: e.message }); }).then(function () {
      if (openThread === id && drawerVisible()) { threadTimer = setTimeout(function () { loadThread(true); }, document.hidden ? 30000 : 5000); }
    });
  }

  function send() {
    var ta = chatRoot.querySelector('.sdk-compose textarea'), body = ta.value.trim();
    if (!body || !openThread) { return; }
    var btn = chatRoot.querySelector('.sdk-send'); btn.disabled = true;
    api('chat/thread/' + openThread, { method: 'POST', body: { body: body } }).then(function (r) {
      ta.value = ''; ta.style.height = '';
      var box = chatRoot.querySelector('.sdk-msgs'); var em = box.querySelector('.sdk-empty'); if (em) { em.remove(); }
      box.insertAdjacentHTML('beforeend', bubble(r.msg)); threadLast = r.msg.id; box.scrollTop = box.scrollHeight;
      loadThreads();
    }).catch(function (e) { toast({ icon: '⚠', title: 'Not sent', body: e.message, tone: 'bad' }); }).then(function () { btn.disabled = false; ta.focus(); });
  }

  function openChat(threadId) {
    if (page) { if (threadId) { openConv(threadId); } return; }
    panel.hidden = true;
    if (!drawer) { drawer = h('aside', 'sdk-drawer'); drawer.setAttribute('aria-label', 'Messages'); document.body.appendChild(drawer); chatShell(drawer, false); }
    drawer.classList.add('is-open'); scrim.hidden = false;
    if (mobile()) { document.documentElement.classList.add('sdk-lock'); }
    loadThreads().then(function (d) {
      if (threadId) { openConv(threadId); return; }
      // One obvious conversation (e.g. a student who only talks to the office): open it straight away.
      if (d && !C.office && d.threads.length === 1 && !d.contacts.length) { openConv(d.threads[0].id); }
    });
  }
  window.SDSC_CHAT = openChat;
  if (chatBtn) {
    chatBtn.addEventListener('click', function () {
      if (page) { page.scrollIntoView({ behavior: 'smooth' }); return; }
      if (drawer && drawer.classList.contains('is-open')) { closeAll(); return; }
      openChat(0);
    });
  }
  if (page) {
    chatShell(page, true);
    loadThreads().then(function (d) {
      var t = parseInt(page.dataset.thread || '0', 10);
      if (t) { openConv(t); }
      else if (d && !mobile() && d.threads.length) { openConv(d.threads[0].id); }
    });
  }

  pulse();
})();
