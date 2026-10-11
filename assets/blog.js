/* Sam's Class Manager: article page helpers (copy link). */
(function () {
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-sdb-copy]');
		if (!b) { return; }
		var url = b.getAttribute('data-sdb-copy');
		var done = function () { b.classList.add('is-done'); setTimeout(function () { b.classList.remove('is-done'); }, 1800); };
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this link:', url); });
		} else {
			window.prompt('Copy this link:', url);
		}
	});
})();
