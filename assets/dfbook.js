/* "Schedule a driving lesson" — replaces DriveFlow's add-session form (calendar and records). */
(function () {
	var D = window.SDSC_DFB;
	var M = document.getElementById('sdfb-modal');
	if (!D || !M) { return; }
	// Take the place of DriveFlow's form so its "+ Schedule Lesson" button and slot clicks open this one.
	var old = document.getElementById('df-add-session-modal');
	if (old) { old.parentNode.removeChild(old); }
	M.id = 'df-add-session-modal';

	var $ = function (id) { return document.getElementById(id); };
	var F = $('sdfb-form'), q = $('sdfb-q'), hits = $('sdfb-hits'), card = $('sdfb-card'), chk = $('sdfb-check'), go = $('sdfb-go');
	var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

	// Lists
	D.ins.forEach(function (i) {
		var o = document.createElement('option'); o.value = i.id;
		o.textContent = i.name + (i.langs ? ' (' + i.langs + ')' : '') + (i.pending ? ' — onboarding not finished' : '');
		o.dataset.name = i.name; o.dataset.car = i.car; $('sdfb-ins').appendChild(o);
	});
	(function () { var n = $('sdfb-ins').querySelector('option[value="new"]'); if (n) { $('sdfb-ins').appendChild(n); } })();
	D.cars.forEach(function (c) {
		var o = document.createElement('option'); o.value = c.id;
		o.textContent = c.plate + (c.model ? ' · ' + c.model : '') + (c.down ? ' — ' + c.down : '');
		o.dataset.plate = c.plate; $('sdfb-car').appendChild(o);
	});
	D.places.forEach(function (p) { var o = document.createElement('option'); o.value = p; $('sdfb-places').appendChild(o); });
	if (D.addIns) { $('sdfb-addins').innerHTML = 'Instructor not in the list? <a href="' + esc(D.addIns) + '" target="_blank" rel="noopener">Add an instructor</a> — they can be assigned right away, even before they sign the agreement.'; }

	function reset() {
		F.reset(); slots = []; lastOk = false; week = null; wasOffer = false; $('sdfb-grid').innerHTML = ''; draw(); [].forEach.call(F.querySelectorAll('.sdfb-newins'), function (l) { l.hidden = true; }); card.hidden = true; card.innerHTML = ''; hits.innerHTML = ''; q.value = ''; chk.innerHTML = ''; go.disabled = true;
		$('sdfb-place').value = D.places[0] || '';
		lengths();
	}

	// Kind of lesson → allowed lengths
	function lengths() {
		var t = D.types[$('sdfb-type').value] || D.types.extra, sel = $('sdfb-min'), ok = false;
		[].forEach.call(sel.options, function (o) { var on = t[2].indexOf(+o.value) !== -1; o.disabled = !on; o.hidden = !on; if (on && o.selected) { ok = true; } });
		if (!ok) { sel.value = String(t[1]); }
	}
	$('sdfb-type').addEventListener('change', function () { lengths(); if (this.value === 'test') { $('sdfb-place').value = ''; $('sdfb-place').placeholder = 'Which MVA? e.g. MVA Gaithersburg — road test'; } });

	// Instructor → their usual car
	$('sdfb-ins').addEventListener('change', function () {
		var typed = this.value === 'new';
		[].forEach.call(F.querySelectorAll('.sdfb-newins'), function (l) { l.hidden = !typed; });
		$('sdfb-insname').required = typed;
		if (typed) { setTimeout(function () { $('sdfb-insname').focus(); }, 30); }
		var o = this.options[this.selectedIndex];
		if (o && o.dataset.car && +o.dataset.car && !$('sdfb-car').value) { $('sdfb-car').value = o.dataset.car; }
	});

	// Student search
	var cur = -1, list = [];
	function find(t) {
		t = t.toLowerCase().trim(); if (t.length < 2) { return []; }
		var words = t.split(/\s+/), digits = t.replace(/\D/g, '');
		return D.people.filter(function (p) {
			var hay = (p.f + ' ' + p.l + ' ' + p.e + ' ' + (p.c || '')).toLowerCase(), ph = (p.p || '').replace(/\D/g, '');
			return words.every(function (w) { return hay.indexOf(w) !== -1 || (digits.length >= 3 && ph.indexOf(digits) !== -1); });
		}).slice(0, 8);
	}
	function show() {
		hits.innerHTML = '';
		list.forEach(function (p, i) {
			var li = document.createElement('li'); li.setAttribute('role', 'option'); if (i === cur) { li.className = 'is-on'; }
			li.innerHTML = '<div><b>' + esc(p.f + ' ' + p.l) + '</b>' + (p.c ? ' · ' + esc(p.c) : '') + '<br><span>' + esc(p.e || '') + (p.p ? ' · ' + esc(p.p) : '') + '</span></div><span>' + p.d + ' done · ' + p.b + ' booked</span>';
			li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(p); });
			hits.appendChild(li);
		});
	}
	q.addEventListener('input', function () { list = find(q.value); cur = -1; show(); });
	q.addEventListener('keydown', function (e) {
		if (!list.length) { return; }
		if (e.key === 'ArrowDown') { cur = Math.min(list.length - 1, cur + 1); show(); e.preventDefault(); }
		else if (e.key === 'ArrowUp') { cur = Math.max(0, cur - 1); show(); e.preventDefault(); }
		else if (e.key === 'Enter') { e.preventDefault(); pick(list[cur < 0 ? 0 : cur]); }
		else if (e.key === 'Escape') { hits.innerHTML = ''; }
	});
	q.addEventListener('blur', function () { setTimeout(function () { hits.innerHTML = ''; }, 150); });
	function pick(p) {
		$('sdfb-first').value = p.f; $('sdfb-last').value = p.l; $('sdfb-email').value = p.e || ''; $('sdfb-phone').value = p.p || '';
		$('sdfb-lang').value = p.g === 'es' ? 'es' : 'en';
		if (p.pm) { $('sdfb-permitno').value = p.pm; }
		if (p.c) { $('sdfb-type').value = 'de'; lengths(); }
		$('sdfb-no').value = Math.max(p.n || 0, p.d + p.b) + 1;
		card.hidden = false;
		card.innerHTML = '<b>' + esc(p.f + ' ' + p.l) + '</b>' + (p.c ? ' · course ' + esc(p.c) : '') + ' · ' + p.d + ' lesson' + (p.d === 1 ? '' : 's') + ' done, ' + p.b + ' booked' + (p.c && p.d + p.b >= 3 ? ' · <b>the 6 hours of the course are covered</b> — this would be extra practice' : '');
		if (p.c && p.d + p.b >= 3) { $('sdfb-type').value = 'extra'; lengths(); }
		q.value = ''; hits.innerHTML = ''; list = [];
		check();
	}

	// Live check
	var timer = null, seq = 0;
	function filled() { return $('sdfb-first').value.trim() && ($('sdfb-email').value.trim() || $('sdfb-phone').value.replace(/\D/g, '').length >= 10) && $('sdfb-date').value && $('sdfb-time').value && $('sdfb-ins').value && ($('sdfb-ins').value !== 'new' || $('sdfb-insname').value.trim().length > 1) && $('sdfb-car').value; }
	function check() {
		clearTimeout(timer);
		timer = setTimeout(function () {
			if (!$('sdfb-date').value || !$('sdfb-time').value) { chk.innerHTML = ''; lastOk = false; $('sdfb-add').disabled = true; sync(); return; }
			var fd = new FormData(F), my = ++seq;
			fd.set('action', 'sdsc_dfb_check'); fd.set('_n', D.nonce); fd.delete('_wpnonce');
			fetch(D.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (r) {
				if (my !== seq || !r || !r.success) { return; }
				var x = r.data, h = '';
				if (x.errors.length) { h += '<div class="sdfb-err"><b>Cannot book yet:</b><ul>' + x.errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></div>'; }
				if (x.warnings.length) { h += '<div class="sdfb-warn"><b>Please check:</b><ul>' + x.warnings.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></div>'; }
				if (x.info && x.info.day) {
					var ins = $('sdfb-ins'), car = $('sdfb-car'), t = $('sdfb-time');
					h += '<div class="sdfb-ok"><b>' + esc(x.info.day) + ', ' + esc(t.options[t.selectedIndex].text) + ' – ' + esc(x.info.end) + '</b>'
						+ (ins.value ? ' · ' + esc(ins.value === 'new' ? $('sdfb-insname').value : ins.options[ins.selectedIndex].dataset.name) : '') + (car.value ? ' · ' + esc(car.options[car.selectedIndex].dataset.plate) : '')
						+ '<br>Free cancellation until <b>' + esc(x.info.dl) + '</b> (' + D.hours + ' hours before). After that a $' + D.fee + ' fee may apply — charged only if the manager decides.</div>';
				}
				chk.innerHTML = h;
				lastOk = !x.errors.length && filled();
				$('sdfb-add').disabled = !lastOk;
				sync();
			});
		}, 350);
	}
	F.addEventListener('change', check);
	F.addEventListener('input', function (e) { if (e.target !== q) { check(); } });
	F.addEventListener('submit', function (e) { if (go.disabled) { e.preventDefault(); } else { go.textContent = offer() ? 'Sending…' : 'Booking…'; } });

	// Offer several times: the student chooses from the list.
	var lastOk = false, slots = [];
	function offer() { var r = F.querySelector('input[name=mode]:checked'); return r && r.value === 'offer'; }
	function student() { return $('sdfb-first').value.trim() && ($('sdfb-email').value.trim() || $('sdfb-phone').value.replace(/\D/g, '').length >= 10); }
	function sync() {
		var on = offer();
		$('sdfb-offer').hidden = !on;
		F.querySelector('input[name=action]').value = on ? 'sdsc_dfb_offer' : 'sdsc_dfb_book';
		F.querySelector('input[name=mail]').closest('label').hidden = on;
		go.textContent = on ? 'Send the offer' : 'Book the lesson';
		go.disabled = on ? !(slots.length && student()) : !lastOk;
		// One time: date and start above. Several times: the week grid instead.
		[].forEach.call(F.querySelectorAll('.sdfb-one'), function (l) { l.hidden = on; });
		$('sdfb-date').required = !on; $('sdfb-time').required = !on;
		if (on !== wasOffer) { wasOffer = on; if (on) { grid(); } }
	}
	var wasOffer = false;
	function draw() {
		var ol = $('sdfb-list'); ol.innerHTML = '';
		slots.forEach(function (s, i) {
			var li = document.createElement('li'); li.textContent = s.label;
			var x = document.createElement('button'); x.type = 'button'; x.textContent = 'Remove'; x.addEventListener('click', function () { slots.splice(i, 1); draw(); });
			li.appendChild(x); ol.appendChild(li);
		});
		$('sdfb-slots').value = JSON.stringify(slots.map(function (s) { return { start: s.start, minutes: s.minutes, ins: s.ins, ins_new: s.ins_new, car: s.car }; }));
		$('sdfb-cnt').textContent = slots.length ? '(' + slots.length + ')' : '— none yet';
		[].forEach.call($('sdfb-grid').querySelectorAll('[data-s]'), function (b) { b.classList.toggle('is-sel', slots.some(function (x) { return x.start === b.dataset.s; })); });
		var p = $('sdfb-pick'), keep = +p.value || 1; p.innerHTML = '';
		for (var k = 1; k <= Math.max(1, slots.length); k++) { var o = document.createElement('option'); o.value = k; o.textContent = k + ' time' + (k > 1 ? 's' : '') + (slots.length ? ' of ' + slots.length : ''); p.appendChild(o); }
		p.value = String(Math.min(keep, Math.max(1, slots.length)));
		sync();
	}
	$('sdfb-add').addEventListener('click', function () {
		if (!lastOk) { return; }
		var ins = $('sdfb-ins'), car = $('sdfb-car'), t = $('sdfb-time'), m = $('sdfb-min');
		var start = $('sdfb-date').value + ' ' + t.value + ':00';
		if (slots.some(function (s) { return s.start === start; })) { return; }
		var who = ins.value === 'new' ? $('sdfb-insname').value.trim() : ins.options[ins.selectedIndex].dataset.name;
		var d = new Date($('sdfb-date').value + 'T12:00:00');
		slots.push({ start: start, minutes: +m.value, ins: ins.value === 'new' ? 0 : +ins.value, ins_new: ins.value === 'new' ? who : '', car: +car.value,
			label: d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) + ', ' + t.options[t.selectedIndex].text + ' · ' + m.options[m.selectedIndex].text + ' · ' + who + ' · ' + car.options[car.selectedIndex].dataset.plate });
		slots.sort(function (a, b) { return a.start < b.start ? -1 : 1; });
		draw();
	});
	[].forEach.call(F.querySelectorAll('input[name=mode]'), function (r) { r.addEventListener('change', sync); });

	// Week grid for offers: tap several days and times.
	var week = null, gseq = 0, gtimer = null;
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function monday(d) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); var k = (x.getDay() + 6) % 7; x.setDate(x.getDate() - k); return x; }
	function grid() {
		clearTimeout(gtimer);
		gtimer = setTimeout(function () {
			if (!offer()) { return; }
			if (!week) { week = monday(new Date()); }
			var end = new Date(week); end.setDate(end.getDate() + 6);
			$('sdfb-wlab').textContent = week.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' – ' + end.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
			$('sdfb-wprev').disabled = week <= monday(new Date());
			var G = $('sdfb-grid');
			if (!$('sdfb-ins').value || ($('sdfb-ins').value === 'new' && $('sdfb-insname').value.trim().length < 2) || !$('sdfb-car').value) { G.innerHTML = '<p class="sdfb-mini">Choose the instructor and the car first (section 3) — then the free times appear here.</p>'; return; }
			$('sdfb-wst').textContent = 'Checking…';
			var fd = new FormData(F), my = ++gseq;
			fd.set('action', 'sdsc_dfb_grid'); fd.set('_n', D.nonce); fd.delete('_wpnonce'); fd.set('week', ymd(week));
			fetch(D.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (r) {
				if (my !== gseq || !r || !r.success) { return; }
				$('sdfb-wst').textContent = '';
				var days = r.data.days, times = Object.keys(days[0].cells);
				var h = '<table><thead><tr><th></th>' + days.map(function (d) { return '<th>' + esc(d.label) + '<br><small>' + esc(d.num) + '</small></th>'; }).join('') + '</tr></thead><tbody>';
				times.forEach(function (t) {
					var hm = t.split(':'), a0 = +hm[0] * 60 + +hm[1], a1 = a0 + (+$('sdfb-min').value || 120);
					function h12(m, ap) { var hh = Math.floor(m / 60), mm = m % 60; return ((hh + 11) % 12 + 1) + (mm ? ':' + pad(mm) : (ap ? ':00' : '')) + (ap ? (hh < 12 ? ' AM' : ' PM') : ''); }
					var blk = h12(a0) + '–' + h12(a1), lab = h12(a0, 1) + ' – ' + h12(a1, 1);
					h += '<tr' + (a0 < 540 ? ' class="is-earlyrow"' : '') + '><th>' + blk + (a0 < 540 ? '<br><small>early</small>' : '') + '</th>' + days.map(function (d) {
						var c = d.cells[t], st = d.date + ' ' + t + ':00';
						return '<td><button type="button" data-s="' + st + '" data-l="' + esc(lab) + '" data-d="' + d.date + '" class="' + (c[0] === 2 ? 'is-no' : c[0] === 1 ? 'is-early' : 'is-ok') + '"' + (c[0] === 2 ? ' disabled' : '') + ' title="' + esc(c[1] || 'Free') + '">' + (c[0] === 2 ? '' : blk) + '</button></td>';
					}).join('') + '</tr>';
				});
				G.innerHTML = h + '</tbody></table>';
				draw();
			}).catch(function () { $('sdfb-wst').textContent = 'Could not check the times — try again.'; });
		}, 250);
	}
	$('sdfb-grid').addEventListener('click', function (e) {
		var b = e.target.closest('button[data-s]'); if (!b || b.disabled) { return; }
		var i = -1; slots.forEach(function (x, k) { if (x.start === b.dataset.s) { i = k; } });
		if (i > -1) { slots.splice(i, 1); draw(); return; }
		var ins = $('sdfb-ins'), car = $('sdfb-car'), m = $('sdfb-min');
		var who = ins.value === 'new' ? $('sdfb-insname').value.trim() : ins.options[ins.selectedIndex].dataset.name;
		var d = new Date(b.dataset.d + 'T12:00:00');
		slots.push({ start: b.dataset.s, minutes: +m.value, ins: ins.value === 'new' ? 0 : +ins.value, ins_new: ins.value === 'new' ? who : '', car: +car.value,
			label: d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) + ', ' + b.dataset.l + ' · ' + m.options[m.selectedIndex].text + ' · ' + who + ' · ' + car.options[car.selectedIndex].dataset.plate });
		slots.sort(function (a, c) { return a.start < c.start ? -1 : 1; });
		var p = $('sdfb-pick'); draw(); if (+p.value < Math.min(slots.length, 3) && slots.length <= 3) { p.value = String(Math.min(slots.length, +p.value || 1)); }
	});
	$('sdfb-wprev').addEventListener('click', function () { week.setDate(week.getDate() - 7); grid(); });
	$('sdfb-wnext').addEventListener('click', function () { week.setDate(week.getDate() + 7); grid(); });
	['sdfb-ins', 'sdfb-car', 'sdfb-type', 'sdfb-min', 'sdfb-insname', 'sdfb-email', 'sdfb-phone', 'sdfb-permit'].forEach(function (id) { $(id).addEventListener('change', grid); });

	// Open / close
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function prefill() {
		var s = $('df-modal-start-time').value, e = $('df-modal-end-time').value, pl = $('df-modal-plate').value, ins = $('df-modal-instructor').value;
		if (s && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s)) {
			$('sdfb-date').value = s.slice(0, 10);
			var hm = s.slice(11, 16).split(':'), m = Math.round((+hm[0] * 60 + +hm[1]) / 30) * 30, v = pad(Math.floor(m / 60)) + ':' + pad(m % 60);
			if ([].some.call($('sdfb-time').options, function (o) { return o.value === v; })) { $('sdfb-time').value = v; }
			if (e) { var len = (Date.parse(e) - Date.parse(s)) / 60000; if ([60, 90, 120, 180].indexOf(len) !== -1) { $('sdfb-min').value = String(len); } }
		}
		if (pl) { [].forEach.call($('sdfb-car').options, function (o) { if (o.dataset.plate === pl) { $('sdfb-car').value = o.value; } }); }
		if (ins) { [].forEach.call($('sdfb-ins').options, function (o) { if (o.dataset.name === ins) { $('sdfb-ins').value = o.value; } }); $('sdfb-ins').dispatchEvent(new Event('change')); }
		['df-modal-start-time', 'df-modal-end-time', 'df-modal-plate', 'df-modal-instructor'].forEach(function (i) { $(i).value = ''; });
	}
	var was = false;
	new MutationObserver(function () {
		var open = M.classList.contains('is-open');
		if (open && !was) { reset(); prefill(); check(); setTimeout(function () { q.focus(); }, 50); }
		was = open;
	}).observe(M, { attributes: true, attributeFilter: ['class'] });
	document.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('[data-df-open-modal="df-add-session-modal"]') : null;
		if (t) { e.preventDefault(); M.classList.add('is-open'); }
		if (e.target === M || (e.target.closest && e.target.closest('#df-add-session-modal [data-df-close-modal]'))) { M.classList.remove('is-open'); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && M.classList.contains('is-open') && document.activeElement !== q) { M.classList.remove('is-open'); } });
	lengths();
})();
