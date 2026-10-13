/* Sam's Class Manager — registration form: steps, road progress, signature pads, validation. */
(function () {
	'use strict';

	function SigPad(wrap) {
		var canvas = wrap.querySelector('canvas');
		var input = wrap.querySelector('input[type=hidden]');
		var ctx = canvas.getContext('2d');
		var drawing = false, last = null, dirty = false;

		function size() {
			var data = dirty ? canvas.toDataURL('image/png') : null;
			var ratio = Math.max(window.devicePixelRatio || 1, 1);
			var w = canvas.offsetWidth || 300, h = canvas.offsetHeight || 170;
			canvas.width = w * ratio; canvas.height = h * ratio;
			ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
			ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#13202e';
			if (data) { var img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0, w, h); }; img.src = data; }
		}
		function pos(e) {
			var r = canvas.getBoundingClientRect();
			return { x: e.clientX - r.left, y: e.clientY - r.top };
		}
		canvas.addEventListener('pointerdown', function (e) {
			drawing = true; last = pos(e); canvas.setPointerCapture(e.pointerId);
			ctx.beginPath(); ctx.arc(last.x, last.y, 1.1, 0, Math.PI * 2); ctx.fillStyle = '#13202e'; ctx.fill();
			mark(); e.preventDefault();
		});
		canvas.addEventListener('pointermove', function (e) {
			if (!drawing) { return; }
			var p = pos(e);
			ctx.beginPath(); ctx.moveTo(last.x, last.y);
			ctx.quadraticCurveTo(last.x, last.y, (last.x + p.x) / 2, (last.y + p.y) / 2);
			ctx.lineTo(p.x, p.y); ctx.stroke();
			last = p; e.preventDefault();
		});
		function end() { if (drawing) { drawing = false; input.value = canvas.toDataURL('image/png'); } }
		canvas.addEventListener('pointerup', end);
		canvas.addEventListener('pointercancel', end);
		canvas.style.touchAction = 'none';
		function mark() { dirty = true; wrap.classList.add('is-signed'); wrap.classList.remove('is-invalid'); }
		wrap.querySelector('.sdsc-sig-clear').addEventListener('click', function () {
			ctx.clearRect(0, 0, canvas.width, canvas.height); input.value = ''; dirty = false;
			wrap.classList.remove('is-signed');
		});
		this.resize = size;
		this.isEmpty = function () { return !input.value; };
		size();
	}

	function ageFrom(value) {
		if (!value) { return null; }
		var d = new Date(value + 'T00:00:00'), n = new Date();
		if (isNaN(d)) { return null; }
		var a = n.getFullYear() - d.getFullYear();
		var m = n.getMonth() - d.getMonth();
		if (m < 0 || (m === 0 && n.getDate() < d.getDate())) { a--; }
		return a;
	}

	function init(root) {
		var form = root.querySelector('.sdsc-reg-form');
		if (!form) { return; }
		form.querySelector('.sdsc-js-check').value = 'ok';

		var steps = [].slice.call(form.querySelectorAll('.sdsc-step'));
		var navItems = [].slice.call(root.querySelectorAll('.sdsc-steps-nav li'));
		var prev = form.querySelector('.sdsc-prev'), next = form.querySelector('.sdsc-next'), submit = form.querySelector('.sdsc-submit');
		var errBox = form.querySelector('.sdsc-reg-error');
		var car = root.querySelector('.sdsc-road-car'), fill = root.querySelector('.sdsc-road-fill');
		var pads = [].slice.call(form.querySelectorAll('.sdsc-sig')).map(function (w) { return { wrap: w, pad: new SigPad(w) }; });
		var cur = 0;

		function isMinor() {
			var a = ageFrom(form.querySelector('.sdsc-dob').value);
			return a !== null && a < 18;
		}
		function updateMinor() {
			var minor = isMinor();
			root.classList.toggle('is-minor', minor);
			form.querySelectorAll('[data-minor-required] input, input[data-minor-required]').forEach(function (el) { el.required = minor; });
		}

		function showError(msg) {
			errBox.textContent = msg || '';
			errBox.hidden = !msg;
		}

		function validateStep(i) {
			var step = steps[i], ok = true, first = null;
			step.querySelectorAll('input, select').forEach(function (el) {
				if (el.type === 'hidden' || el.disabled) { return; }
				var valid = el.checkValidity();
				if (el.name === 'phone' || el.name === 'parent_phone') {
					if (el.value && el.value.replace(/\D/g, '').length < 10) { valid = false; }
					if (el.required && !el.value) { valid = false; }
				}
				var holder = el.closest('.sdsc-field') || el.closest('.sdsc-course') || el.closest('.sdsc-consent');
				if (holder) { holder.classList.toggle('is-invalid', !valid); }
				if (!valid) { ok = false; first = first || el; }
			});
			if (i === 0 && !form.querySelector('input[name=class_id]:checked')) {
				ok = false; showError('Please choose a course to continue.'); return false;
			}
			if (i === 1) {
				var age = ageFrom(form.querySelector('.sdsc-dob').value);
				if (age === null || age < 14 || age > 100) {
					ok = false; form.querySelector('.sdsc-dob').closest('.sdsc-field').classList.add('is-invalid');
					showError('Please enter a valid date of birth.'); return false;
				}
				if (age < 16) {
					form.querySelector('.sdsc-dob').closest('.sdsc-field').classList.add('is-invalid');
					showError('In Maryland, students can register from age 16 with a parent’s signature. Please call us at (301) 726-3030 and we will help you plan.'); return false;
				}
			}
			pads.forEach(function (p) {
				if (!step.contains(p.wrap)) { return; }
				var need = p.wrap.hasAttribute('data-required') || (p.wrap.hasAttribute('data-minor-required') && isMinor());
				if (need && p.pad.isEmpty()) { ok = false; p.wrap.classList.add('is-invalid'); }
			});
			if (!ok) {
				showError('Please complete the highlighted fields.');
				if (first) { first.focus({ preventScroll: false }); }
			} else { showError(''); }
			return ok;
		}

		function go(i) {
			cur = Math.max(0, Math.min(steps.length - 1, i));
			steps.forEach(function (s, k) { s.classList.toggle('is-active', k === cur); });
			navItems.forEach(function (li, k) { li.classList.toggle('is-active', k === cur); li.classList.toggle('is-done', k < cur); });
			var pct = steps.length > 1 ? cur / (steps.length - 1) : 1;
			if (car) {
				car.style.left = 'calc(' + (pct * 100) + '% * 0.86)';
				var track = car.parentNode.offsetWidth * 0.86, dia = car.offsetWidth * 0.1435;
				if (dia) { car.style.setProperty('--spin', Math.round(pct * track / (Math.PI * dia) * 360) + 'deg'); }
			}
			if (fill) { fill.style.width = (pct * 100) + '%'; }
			prev.hidden = cur === 0;
			next.hidden = cur === steps.length - 1;
			submit.hidden = cur !== steps.length - 1;
			pads.forEach(function (p) { if (steps[cur].contains(p.wrap)) { p.pad.resize(); } });
			showError('');
		}

		next.addEventListener('click', function () {
			if (!validateStep(cur)) { return; }
			go(cur + 1);
			root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});
		prev.addEventListener('click', function () { go(cur - 1); });

		form.querySelector('.sdsc-dob').addEventListener('change', updateMinor);
		form.addEventListener('input', function (e) {
			var h = e.target.closest('.is-invalid'); if (h) { h.classList.remove('is-invalid'); }
		});
		form.querySelectorAll('input[name=class_id]').forEach(function (r) {
			r.addEventListener('change', function () { showError(''); });
		});

		form.addEventListener('submit', function (e) {
			updateMinor();
			for (var i = 0; i < steps.length; i++) {
				if (!validateStep(i)) { e.preventDefault(); go(i); validateStep(i); return; }
			}
			submit.disabled = true; submit.classList.add('is-loading'); submit.textContent = 'Submitting…';
			root.classList.add('is-submitting');
		});

		var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(function () { pads.forEach(function (p) { p.pad.resize(); }); }, 150); });
		updateMinor();
		go(0);
		if (form.querySelector('input[name=class_id]:checked') && root.querySelector('.sdsc-reg-alert') === null) { /* stay on step 1 so the user can confirm */ }
	}

	function boot() { document.querySelectorAll('.sdsc-reg').forEach(init); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
