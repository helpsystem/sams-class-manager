/* Sam's School — Celebrations & campaigns */
(function () {
	'use strict';
	var D = window.SDSC_OR || {};
	var ua = navigator.userAgent || '';
	var apple = /iPhone|iPad|iPod|Macintosh/.test(ua);
	var mobile = /Android|iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);

	/* sms: link that opens Messages with number and text (iOS/macOS use "&body=", Android "?body=") */
	function smsHref(to, body) {
		return 'sms:' + to + (apple ? '&' : '?') + 'body=' + encodeURIComponent(body);
	}
	function textOf(a) {
		var l = a.getAttribute('data-lang') || 'en';
		return a.getAttribute('data-' + l) || a.getAttribute('data-en') || a.getAttribute('data-es') || '';
	}
	function refresh(a) { a.href = smsHref(a.getAttribute('data-sms'), textOf(a)); }

	function mark(a) {
		var m = a.getAttribute('data-mark');
		if (!m || !D.ajax) { return; }
		var fd = new FormData();
		fd.append('action', 'sdsc_or_mark');
		fd.append('_ajax_nonce', D.nonce);
		fd.append('mark', m);
		try { fetch(D.ajax, { method: 'POST', credentials: 'same-origin', body: fd }); } catch (e) {}
		var card = a.closest('.sdo-card');
		if (card && !card.querySelector('.sdo-texted')) {
			var s = card.querySelector('.sdo-status');
			if (s) { s.insertAdjacentHTML('beforeend', '<span class="sdo-chip sdo-chip--ok sdo-texted">✓ Texted</span>'); }
		}
		var li = a.closest('.sdo-tq-i');
		if (li && !li.classList.contains('is-sent')) {
			li.classList.add('is-sent');
			a.classList.add('sdo-btn--ghost');
			li.insertAdjacentHTML('beforeend', '<span class="sdo-chip sdo-chip--ok">✓ Texted</span>');
		}
	}

	function init() {
		document.querySelectorAll('a[data-sms]').forEach(refresh);
		var hint = document.querySelector('.sdo-phone-hint');
		if (hint && !mobile && document.querySelector('a[data-sms]')) { hint.hidden = false; }

		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('a[data-sms]');
			if (a) { refresh(a); mark(a); return; }

			var sw = e.target.closest && e.target.closest('.sdo-lang button');
			if (sw) {
				var l = sw.getAttribute('data-set');
				var box = sw.closest('.sdo-card') || sw.closest('.sdo-ed-prev');
				sw.parentNode.querySelectorAll('button').forEach(function (b) {
					var on = b === sw; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
				});
				if (box && box.classList.contains('sdo-card')) {
					var bub = box.querySelector('.sdo-bubble');
					if (bub) { bub.textContent = bub.getAttribute('data-' + l) || bub.textContent; }
					box.querySelectorAll('a[data-sms]').forEach(function (x) { x.setAttribute('data-lang', l); refresh(x); });
				} else if (box) { preview(l); }
				return;
			}

			var cp = e.target.closest && e.target.closest('.sdo-copy');
			if (cp) {
				var b = cp.closest('.sdo-card').querySelector('.sdo-bubble');
				if (b && navigator.clipboard) {
					navigator.clipboard.writeText(b.textContent).then(function () {
						var t = cp.textContent; cp.textContent = 'Copied ✓'; setTimeout(function () { cp.textContent = t; }, 1400);
					});
				}
				return;
			}

			var nx = e.target.closest && e.target.closest('.sdo-next');
			if (nx) {
				var next = document.querySelector('.sdo-tq-i:not(.is-sent) a[data-sms]');
				if (next) { next.scrollIntoView({ block: 'center' }); refresh(next); mark(next); window.location.href = next.href; }
				else { nx.textContent = 'Everyone has been texted ✓'; nx.disabled = true; }
				return;
			}

			var cf = e.target.closest && e.target.closest('[data-confirm]');
			if (cf && !window.confirm(cf.getAttribute('data-confirm'))) { e.preventDefault(); }
		});

		editor();
		counters(document);
	}

	/* character counter for text messages (one text is 160 plain characters, 70 with emoji/accents) */
	function counters(root) {
		root.querySelectorAll('textarea[data-f="sms"], textarea.sdo-smsf').forEach(function (ta) {
			var out = ta.closest('label').querySelector('.sdo-cc');
			if (!out) { return; }
			var upd = function () {
				var v = ta.value, uni = /[^\x00-\x7F]/.test(v), lim = uni ? 70 : 160, n = v.length;
				var parts = n <= lim ? 1 : Math.ceil(n / (uni ? 67 : 153));
				out.textContent = '· ' + n + ' characters' + (parts > 1 ? ' · ' + parts + ' texts' : '');
				out.classList.toggle('is-long', parts > 2);
			};
			ta.addEventListener('input', upd); upd();
		});
	}

	/* ---------------- campaign editor ---------------- */
	var curLang = 'en';
	function fill(s) {
		var S = D.sample || {};
		var link = (document.querySelector('#sdo-editor [name=url]') || {}).value || '';
		return String(s || '')
			.replace(/\{first_name\}/g, S.first_name || 'Maria')
			.replace(/\{last_name\}/g, '')
			.replace(/\{phone\}/g, S.phone || '')
			.replace(/\{school\}/g, S.school || '')
			.replace(/\{website\}/g, location.host)
			.replace(/\{link\}/g, link);
	}
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function val(l, f) { var el = document.querySelector('#sdo-editor [name="' + l + '[' + f + ']"]'); return el ? el.value : ''; }
	function preview(l) {
		var ed = document.getElementById('sdo-editor');
		if (!ed) { return; }
		curLang = l || curLang;
		var mode = (ed.querySelector('[name=lang]:checked') || {}).value || 'each';
		var langs = mode === 'both' ? ['en', 'es'] : [mode === 'es' ? 'es' : (mode === 'en' ? 'en' : curLang)];
		var subj = [], body = '', sms = [];
		langs.forEach(function (x, i) {
			subj.push(fill(val(x, 'subject')));
			if (i) { body += '<hr style="border:0;border-top:1px dashed #ccc;margin:14px 16px">'; }
			if (val(x, 'heading')) { body += '<h3 class="sdo-mock-h">' + esc(fill(val(x, 'heading'))) + '</h3>'; }
			body += '<div class="sdo-mock-b">' + fill(val(x, 'body')).split(/\n{2,}/).filter(Boolean).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('') + '</div>';
			if (val(x, 'btn') && ed.querySelector('[name=url]').value) { body += '<p class="sdo-mock-btn"><span>' + esc(fill(val(x, 'btn'))) + ' →</span></p>'; }
			sms.push(fill(val(x, 'sms')));
		});
		ed.querySelector('.sdo-mock-subj').textContent = subj.filter(Boolean).join(' / ');
		var mail = ed.querySelector('.sdo-mock-mail');
		mail.querySelectorAll('.sdo-mock-h,.sdo-mock-b,.sdo-mock-btn,hr').forEach(function (n) { n.remove(); });
		mail.querySelector('.sdo-mock-road').insertAdjacentHTML('afterend', body);
		ed.querySelector('.sdo-mock-sms').textContent = sms.join('\n\n');
		var emailOn = ed.querySelector('[name=email]').checked, textOn = ed.querySelector('[name=text]').checked;
		mail.style.display = emailOn ? '' : 'none';
		ed.querySelector('.sdo-mock-subj').style.display = emailOn ? '' : 'none';
		ed.querySelector('.sdo-mock-phone').style.display = textOn ? '' : 'none';
	}
	function editor() {
		var ed = document.getElementById('sdo-editor');
		if (!ed) { return; }
		ed.addEventListener('input', function () { preview(); });
		ed.addEventListener('change', function (e) {
			if (e.target.name === 'preset') {
				var p = (D.presets || {})[e.target.value];
				if (p && window.confirm('Replace the message with the “' + p.label + '” wording in both languages?')) {
					['en', 'es'].forEach(function (l) {
						['subject', 'heading', 'body', 'btn', 'sms'].forEach(function (f) {
							var el = ed.querySelector('[name="' + l + '[' + f + ']"]');
							if (el) { el.value = (p[l] && p[l][f]) || ''; el.dispatchEvent(new Event('input')); }
						});
					});
					if (p.url) { ed.querySelector('[name=url]').value = p.url; }
				}
			}
			if (e.target.name === 'months') { var r = ed.querySelector('[name=who][value=recent]'); if (r) { r.checked = true; } }
			preview();
		});
		ed.querySelectorAll('.sdo-langtabs button').forEach(function (b) {
			b.addEventListener('click', function () {
				var l = b.getAttribute('data-pane');
				ed.querySelectorAll('.sdo-langtabs button').forEach(function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
				ed.querySelectorAll('.sdo-pane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== l; });
				var sw = ed.querySelector('.sdo-ed-prev .sdo-lang button[data-set="' + l + '"]');
				if (sw) { sw.click(); }
			});
		});
		preview('en');
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
