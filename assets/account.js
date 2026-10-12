/* Sam's Driving School — header account menu (open/close, keyboard, outside click). */
(function () {
  'use strict';
  /* Header fit check: if the menu would run under the logo, drop the phone / name first. */
  var hd = document.querySelector('.sdh'), nav = hd && hd.querySelector('.sdh-bar .sdh-nav'), mn = nav && nav.querySelector('.sdh-menu');
  function fit() {
    if (!hd || !nav || !mn) { return; }
    hd.classList.remove('is-tight');
    if (window.innerWidth <= 1240) { return; }
    if (mn.scrollWidth > nav.clientWidth + 2) { hd.classList.add('is-tight'); }
  }
  fit(); window.addEventListener('resize', fit); window.addEventListener('load', fit);
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fit); }
})();
(function () {
  'use strict';
  var me = document.querySelector('[data-sdh-me]');
  if (!me) { return; }
  var btn = me.querySelector('.sdh-me-btn'), menu = me.querySelector('.sdh-me-menu');
  function items() { return [].slice.call(menu.querySelectorAll('a')); }
  function place() {
    if (window.matchMedia('(max-width: 760px)').matches) { var r = btn.getBoundingClientRect(); menu.style.top = (r.bottom + 10) + 'px'; } else { menu.style.top = ''; }
  }
  function open(focusFirst) {
    menu.hidden = false; me.classList.add('is-open'); btn.setAttribute('aria-expanded', 'true'); place();
    if (focusFirst) { var a = items()[0]; if (a) { a.focus(); } }
  }
  function close(refocus) {
    if (menu.hidden) { return; }
    menu.hidden = true; me.classList.remove('is-open'); btn.setAttribute('aria-expanded', 'false');
    if (refocus) { btn.focus(); }
  }
  btn.addEventListener('click', function () { if (menu.hidden) { open(false); } else { close(false); } });
  btn.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown') { e.preventDefault(); open(true); } });
  menu.addEventListener('keydown', function (e) {
    var list = items(), i = list.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); (list[i + 1] || list[0]).focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); (list[i - 1] || list[list.length - 1]).focus(); }
    if (e.key === 'Tab' && !e.shiftKey && i === list.length - 1) { close(false); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(true); } });
  document.addEventListener('click', function (e) { if (!me.contains(e.target)) { close(false); } });
  window.addEventListener('resize', function () { if (!menu.hidden) { place(); } });
  window.addEventListener('scroll', function () { if (!menu.hidden && window.matchMedia('(max-width: 760px)').matches) { place(); } }, { passive: true });
})();
