(function () {
  'use strict';
  var shell = document.getElementById('layout-shell');
  var toggle = document.getElementById('menu-toggle');
  var backdrop = document.getElementById('sidebar-backdrop');
  if (!shell || !toggle) return;
  function setOpen(open) {
    shell.classList.toggle('sidebar-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('sidebar-lock', open);
  }
  toggle.addEventListener('click', function () { setOpen(!shell.classList.contains('sidebar-open')); });
  if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') setOpen(false); });
  var links = document.querySelectorAll('.side-link');
  for (var i = 0; i < links.length; i++) links[i].addEventListener('click', function () { setOpen(false); });
})();
