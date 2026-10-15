/* Sam's Driving School — student documents (office: any student; students: their own).
   Photos are made smaller on the device before upload (max 2400px) and get a small preview; PDFs go as they are. */
(function () {
  'use strict';
  var C = window.SDSC_DOCS, root = document.querySelector('#sdd .sdd-app');
  if (!C || !root) { return; }
  var S = { email: '', person: null, docs: [], home: null, q: '' };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function post(action, data) {
    var fd = new FormData();
    fd.append('action', 'sdsc_doc_' + action);
    fd.append('_wpnonce', C.nonce);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) { throw new Error((j && j.data && j.data.msg) || 'Something went wrong. Please try again.'); }
      return j.data;
    });
  }
  function toast(msg, bad) {
    var t = document.createElement('div');
    t.className = 'sdd-toast' + (bad ? ' is-bad' : '');
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('is-out'); }, 2600);
    setTimeout(function () { t.remove(); }, 3100);
  }

  /* ---------------------------------------------------------------- images */
  function loadImage(file) {
    return new Promise(function (ok, no) {
      var u = URL.createObjectURL(file), im = new Image();
      im.onload = function () { ok(im); };
      im.onerror = function () { URL.revokeObjectURL(u); no(); };
      im.src = u;
    });
  }
  function draw(im, max, q) {
    var w = im.naturalWidth, h = im.naturalHeight, k = Math.min(1, max / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width = Math.round(w * k); c.height = Math.round(h * k);
    var x = c.getContext('2d');
    x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
    x.drawImage(im, 0, 0, c.width, c.height);
    return c;
  }
  /** -> Promise<{file, thumb}> */
  function prep(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { return Promise.resolve({ file: file, thumb: '' }); }
    return loadImage(file).then(function (im) {
      var thumb = draw(im, 420, 1).toDataURL('image/jpeg', 0.72);
      var big = Math.max(im.naturalWidth, im.naturalHeight) > 2600 || file.size > 1800000;
      if (!big) { return { file: file, thumb: thumb }; }
      return new Promise(function (ok) {
        draw(im, 2400, 1).toBlob(function (b) {
          ok({ file: b ? new File([b], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file, thumb: thumb });
        }, 'image/jpeg', 0.86);
      });
    }).catch(function () { return { file: file, thumb: '' }; });
  }

  /* ---------------------------------------------------------------- upload */
  function upload(files, type, label) {
    files = Array.prototype.slice.call(files || []);
    if (!files.length) { return; }
    if (C.office && !S.email) { toast('Choose the student first.', true); return; }
    var box = root.querySelector('.sdd-queue');
    files.reduce(function (p, f) {
      return p.then(function () {
        var row = document.createElement('li');
        row.innerHTML = '<span class="sdd-q-n">' + esc(f.name || 'Photo') + '</span><span class="sdd-q-bar"><i></i></span><span class="sdd-q-s">Preparing…</span>';
        if (box) { box.appendChild(row); }
        return prep(f).then(function (x) {
          return new Promise(function (ok) {
            var fd = new FormData();
            fd.append('action', 'sdsc_doc_upload'); fd.append('_wpnonce', C.nonce);
            fd.append('email', S.email); fd.append('type', type); fd.append('label', label || '');
            fd.append('file', x.file, x.file.name || 'document');
            if (x.thumb) { fd.append('thumb', x.thumb); }
            var r = new XMLHttpRequest();
            r.open('POST', C.ajax);
            r.withCredentials = true;
            r.upload.onprogress = function (e) { if (e.lengthComputable) { row.querySelector('i').style.width = Math.round(e.loaded / e.total * 100) + '%'; row.querySelector('.sdd-q-s').textContent = 'Uploading…'; } };
            r.onload = function () {
              var j = null; try { j = JSON.parse(r.responseText); } catch (e) {}
              if (j && j.success) {
                row.classList.add('is-ok'); row.querySelector('.sdd-q-s').textContent = 'Saved ✓';
                S.docs.unshift(j.data.doc); paintDocs(); paintSlots();
                setTimeout(function () { row.remove(); }, 1800);
              } else {
                row.classList.add('is-bad'); row.querySelector('.sdd-q-s').textContent = (j && j.data && j.data.msg) || 'Upload failed';
              }
              ok();
            };
            r.onerror = function () { row.classList.add('is-bad'); row.querySelector('.sdd-q-s').textContent = 'No connection — try again'; ok(); };
            r.send(fd);
          });
        });
      });
    }, Promise.resolve());
  }

  function picker(type, opts) {
    var i = document.createElement('input');
    i.type = 'file';
    i.accept = opts.camera ? 'image/*' : 'image/*,application/pdf';
    if (opts.camera) { i.setAttribute('capture', 'environment'); }
    if (opts.multiple) { i.multiple = true; }
    i.addEventListener('change', function () { upload(i.files, type(), opts.label ? opts.label() : ''); });
    i.click();
  }

  /* ---------------------------------------------------------------- views */
  function docCard(d, withWho) {
    var img = d.thumb ? '<img src="' + esc(d.thumb) + '" alt="" loading="lazy">' : '<span class="sdd-ico">' + (d.pdf ? 'PDF' : 'FILE') + '</span>';
    var acts = '<a class="sdd-b" href="' + esc(d.view) + '" target="_blank" rel="noopener">View</a><a class="sdd-b sdd-b--ghost" href="' + esc(d.dl) + '">Download</a>';
    if (C.office && d.status === 'new') { acts += '<button type="button" class="sdd-b sdd-b--go" data-check="' + d.id + '">Mark checked</button>'; }
    if (C.remove || (!C.office && d.status === 'new')) { acts += '<button type="button" class="sdd-b sdd-b--del" data-del="' + d.id + '" aria-label="Remove ' + esc(d.typeL) + '">Remove</button>'; }
    return '<li class="sdd-doc' + (d.status === 'new' ? ' is-new' : '') + '"><a class="sdd-doc-img" href="' + esc(d.view) + '" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">' + img + '</a>' +
      '<div class="sdd-doc-b"><b>' + esc(d.typeL) + '</b>' + (d.label ? '<span class="sdd-doc-l">' + esc(d.label) + '</span>' : '') +
      (withWho && d.name ? '<a class="sdd-doc-who" href="#" data-open="' + esc(d.email) + '">' + esc(d.name || d.email) + '</a>' : '') +
      '<small>' + esc(d.when) + (d.by ? ' · ' + esc(d.by) : '') + ' · ' + esc(d.size) + '</small>' +
      (d.status === 'new' ? '<span class="sdd-chip sdd-chip--new">' + (C.office ? 'From the student — to check' : 'Waiting for the office') + '</span>' : (d.checked ? '<span class="sdd-chip">Checked by ' + esc(d.checked) + '</span>' : '')) +
      '<div class="sdd-acts">' + acts + '</div></div></li>';
  }

  function slot(type, title) {
    var d = S.docs.filter(function (x) { return x.type === type; })[0];
    return '<div class="sdd-slot" data-slot="' + type + '"><div class="sdd-slot-img">' + (d && d.thumb ? '<img src="' + esc(d.thumb) + '" alt="">' : (d ? '<span class="sdd-ico">' + (d.pdf ? 'PDF' : 'FILE') + '</span>' : '<span class="sdd-slot-ph" aria-hidden="true">🪪</span>')) + '</div>' +
      '<b>' + esc(title) + '</b><small>' + (d ? 'On file · ' + esc(d.when) : 'Not on file yet') + '</small>' +
      '<div class="sdd-slot-acts"><button type="button" class="sdd-b" data-cam="' + type + '">📷 Take photo</button><button type="button" class="sdd-b sdd-b--ghost" data-file="' + type + '">⬆ Upload scan</button></div></div>';
  }
  function paintSlots() {
    var el = root.querySelector('.sdd-slots');
    if (el) { el.innerHTML = slot('id_front', C.all.id_front) + slot('id_back', C.all.id_back); }
  }
  function paintDocs() {
    var el = root.querySelector('.sdd-docs');
    if (!el) { return; }
    el.innerHTML = S.docs.length ? S.docs.map(function (d) { return docCard(d, false); }).join('') : '<li class="sdd-empty">' + (C.office ? 'No documents in this file yet.' : 'You have not added any documents yet.') + '</li>';
    var n = root.querySelector('.sdd-count'); if (n) { n.textContent = S.docs.length; }
  }

  function addBox() {
    var opts = Object.keys(C.types).filter(function (k) { return k !== 'id_front' && k !== 'id_back'; }).map(function (k) { return '<option value="' + k + '">' + esc(C.types[k]) + '</option>'; }).join('');
    return '<section class="sdd-add" aria-label="Add documents">' +
      '<h4>Add documents</h4>' +
      '<div class="sdd-slots"></div>' +
      '<div class="sdd-other"><label>Other document <select class="sdd-type">' + opts + '</select></label>' +
      '<label>Note (optional) <input type="text" class="sdd-label" maxlength="120" placeholder="e.g. practice log page 2"></label>' +
      '<div class="sdd-other-acts"><button type="button" class="sdd-b" data-cam="other">📷 Take photo</button><button type="button" class="sdd-b sdd-b--ghost" data-file="other">⬆ Upload files</button></div></div>' +
      '<div class="sdd-drop" tabindex="0"><b>Drop scanned files here</b><span>PDF or photos · up to 20 MB each · several at once</span></div>' +
      '<ul class="sdd-queue" aria-live="polite"></ul></section>';
  }

  function studentView() {
    var p = S.person || {};
    root.innerHTML =
      (C.office ? '<p class="sdd-back"><a href="#" data-home>← All students</a></p>' +
        '<div class="sdd-person"><span class="sdd-av" aria-hidden="true">' + esc(((p.name || p.email || '?').match(/[A-Za-z]/g) || ['?']).slice(0, 2).join('').toUpperCase()) + '</span>' +
        '<div><h4>' + esc(p.name || p.email) + '</h4><p>' + esc(p.email) + (p.phone ? ' · ' + esc(p.phone) : '') + (p.from && p.from.length ? ' · ' + esc(p.from.join(', ')) : '') + '</p></div></div>' : '') +
      (C.office && window.SDSC_IDSCAN ? '<section class="sdd-idscan" aria-label="Scan an ID"></section>' : '') +
      addBox() +
      '<section class="sdd-list"><h4>On file <span class="sdd-count">0</span></h4><ul class="sdd-docs"></ul></section>';
    paintSlots(); paintDocs();
    var ids = root.querySelector('.sdd-idscan');
    if (ids && S.email) { window.SDSC_IDSCAN.mount(ids, S.email, { upload: function (file, type, label) { upload([file], type, label); } }); }
  }

  function openStudent(email) {
    S.email = email;
    root.innerHTML = '<p class="sdd-loading">Loading…</p>';
    post('list', { email: email }).then(function (d) {
      S.person = d.person; S.docs = d.docs || []; studentView();
      if (C.office && window.history && history.replaceState) { history.replaceState(null, '', location.pathname + '?student=' + encodeURIComponent(email)); }
    }).catch(function (e) { root.innerHTML = '<p class="sdd-err">' + esc(e.message) + '</p>'; });
  }

  function officeHome() {
    S.email = ''; S.person = null; S.docs = [];
    if (window.history && history.replaceState) { history.replaceState(null, '', location.pathname); }
    root.innerHTML =
      (C.ok ? '' : '<p class="sdd-err">The private document folder is not ready on the server. Please tell the administrator.</p>') +
      '<div class="sdd-find"><label for="sdd-q">Find a student</label><input id="sdd-q" type="search" autocomplete="off" placeholder="Name, e-mail or phone" value="' + esc(S.q) + '"><ul class="sdd-results" role="listbox"></ul></div>' +
      '<section class="sdd-list"><h4>From students — to check <span class="sdd-count sdd-count--new">…</span></h4><ul class="sdd-docs sdd-pend"></ul></section>' +
      '<section class="sdd-list"><h4>Recently added</h4><ul class="sdd-docs sdd-recent"></ul></section>';
    var q = root.querySelector('#sdd-q'), t = null;
    q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { find(q.value); }, 250); });
    if (S.q) { find(S.q); }
    post('list', {}).then(function (d) {
      var pe = root.querySelector('.sdd-pend'), re = root.querySelector('.sdd-recent');
      if (!pe) { return; }
      pe.innerHTML = d.pending.length ? d.pending.map(function (x) { return docCard(x, true); }).join('') : '<li class="sdd-empty">Nothing to check. ✓</li>';
      re.innerHTML = d.recent.length ? d.recent.map(function (x) { return docCard(x, true); }).join('') : '<li class="sdd-empty">No documents yet. Find a student above to add the first one.</li>';
      root.querySelector('.sdd-count--new').textContent = d.pending.length;
    }).catch(function () {});
    setTimeout(function () { q.focus(); }, 30);
  }

  function find(v) {
    S.q = v;
    var ul = root.querySelector('.sdd-results');
    if (!ul) { return; }
    if (v.trim().length < 2) { ul.innerHTML = ''; return; }
    post('find', { q: v }).then(function (d) {
      ul.innerHTML = d.people.length ? d.people.map(function (p) {
        return '<li><button type="button" data-open="' + esc(p.email) + '"><b>' + esc(p.name || p.email) + '</b><small>' + esc(p.email) + (p.phone ? ' · ' + esc(p.phone) : '') + ' · ' + esc(p.from.join(', ')) + '</small>' +
          '<span class="sdd-n">' + (p.docs ? p.docs + ' on file' : 'no documents') + (p.new ? ' · ' + p.new + ' to check' : '') + '</span></button></li>';
      }).join('') : '<li class="sdd-empty">No student found. Check the spelling, or search by e-mail or phone.</li>';
    }).catch(function (e) { ul.innerHTML = '<li class="sdd-err">' + esc(e.message) + '</li>'; });
  }

  /* ---------------------------------------------------------------- events */
  root.addEventListener('click', function (e) {
    var t = e.target.closest('[data-open],[data-home],[data-cam],[data-file],[data-check],[data-del]');
    if (!t) { return; }
    if (t.hasAttribute('data-open')) { e.preventDefault(); openStudent(t.getAttribute('data-open')); return; }
    if (t.hasAttribute('data-home')) { e.preventDefault(); officeHome(); return; }
    var otherType = function () { var s = root.querySelector('.sdd-type'); return s ? s.value : 'other'; };
    var otherLabel = function () { var s = root.querySelector('.sdd-label'); return s ? s.value : ''; };
    if (t.hasAttribute('data-cam') || t.hasAttribute('data-file')) {
      var k = t.getAttribute('data-cam') || t.getAttribute('data-file'), slotType = k !== 'other';
      picker(function () { return slotType ? k : otherType(); }, { camera: t.hasAttribute('data-cam'), multiple: !slotType && t.hasAttribute('data-file'), label: slotType ? null : otherLabel });
      return;
    }
    if (t.hasAttribute('data-check')) {
      t.disabled = true;
      post('check', { id: t.getAttribute('data-check') }).then(function () { toast('Marked as checked'); if (S.email) { openStudent(S.email); } else { officeHome(); } }).catch(function (x) { t.disabled = false; toast(x.message, true); });
      return;
    }
    if (t.hasAttribute('data-del')) {
      // An inline confirm (no browser dialog): the button asks once more.
      if (!t.classList.contains('is-sure')) { t.classList.add('is-sure'); t.textContent = 'Remove for good?'; setTimeout(function () { t.classList.remove('is-sure'); t.textContent = 'Remove'; }, 4000); return; }
      t.disabled = true;
      post('remove', { id: t.getAttribute('data-del') }).then(function () {
        S.docs = S.docs.filter(function (d) { return String(d.id) !== t.getAttribute('data-del'); });
        if (S.email || !C.office) { paintDocs(); paintSlots(); } else { officeHome(); }
        toast('Removed');
      }).catch(function (x) { t.disabled = false; toast(x.message, true); });
    }
  });
  // Drag and drop scans onto the page (desktop).
  ['dragenter', 'dragover'].forEach(function (ev) { root.addEventListener(ev, function (e) { var d = root.querySelector('.sdd-drop'); if (!d) { return; } e.preventDefault(); d.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { root.addEventListener(ev, function (e) { var d = root.querySelector('.sdd-drop'); if (d) { d.classList.remove('is-over'); } }); });
  root.addEventListener('drop', function (e) {
    if (!root.querySelector('.sdd-drop')) { return; }
    e.preventDefault();
    var s = root.querySelector('.sdd-type'), l = root.querySelector('.sdd-label');
    upload(e.dataTransfer.files, s ? s.value : 'other', l ? l.value : '');
  });
  root.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('sdd-drop')) { e.preventDefault(); picker(function () { var s = root.querySelector('.sdd-type'); return s ? s.value : 'other'; }, { multiple: true }); }
  });

  /* ---------------------------------------------------------------- start */
  if (!C.office) { openStudent(''); }
  else if (C.open) { openStudent(C.open); }
  else { officeHome(); }
})();
