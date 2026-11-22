/* Menu burger et choix du thème (automatique / clair / sombre). Le choix est mémorisé sur l'appareil. */
(function () {
  'use strict';
  var KEY = 'rechargeplus_theme';
  var root = document.documentElement;
  var btn = document.getElementById('menu-btn');
  var menu = document.getElementById('menu');
  var overlay = document.getElementById('menu-overlay');
  var closeBtn = document.getElementById('menu-close');
  var radios = document.querySelectorAll('#theme-choice input[name=theme]');
  if (!btn || !menu || !overlay) return;

  function stored() {
    try { var t = localStorage.getItem(KEY); return t === 'light' || t === 'dark' ? t : 'auto'; } catch (e) { return 'auto'; }
  }
  function apply(mode) {
    if (mode === 'light' || mode === 'dark') root.setAttribute('data-theme', mode); else root.removeAttribute('data-theme');
    try { if (mode === 'auto') localStorage.removeItem(KEY); else localStorage.setItem(KEY, mode); } catch (e) { /* stockage indisponible : le choix vaut pour cette page seulement */ }
    var dark = mode === 'dark' || (mode === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    var meta = document.querySelector('meta[name=theme-color]');
    if (meta) meta.setAttribute('content', dark ? '#0e1513' : '#0f766e');
  }

  var current = stored();
  for (var i = 0; i < radios.length; i++) {
    radios[i].checked = radios[i].value === current;
    radios[i].addEventListener('change', function (ev) { apply(ev.target.value); });
  }
  apply(current);
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () { if (stored() === 'auto') apply('auto'); };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
  }

  function open() {
    menu.hidden = false; overlay.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    document.body.classList.add('menu-open');
    var checked = menu.querySelector('input[name=theme]:checked') || closeBtn;
    if (checked) checked.focus();
  }
  function close() {
    menu.hidden = true; overlay.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('menu-open');
    btn.focus();
  }
  var odo = document.getElementById('odo-toggle');
  if (odo && odo.form) odo.addEventListener('change', function () { odo.form.submit(); });
  var geo = document.getElementById('geo-toggle');
  if (geo && geo.form) geo.addEventListener('change', function () { geo.form.submit(); });

  btn.addEventListener('click', function () { if (menu.hidden) open(); else close(); });
  closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', close);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !menu.hidden) close(); });
})();
