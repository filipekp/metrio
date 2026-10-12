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

// Informativní kontrola odečtu; server vždy znovu validuje a změny klienta nejsou důvěryhodné.
(function () {
  'use strict';
  var form = document.getElementById('reading-form');
  if (!form) return;
  var meter = form.querySelector('[name="meter_id"]');
  var date = form.querySelector('[name="reading_date"]');
  var value = form.querySelector('[name="value"]');
  var msg = document.getElementById('reading-limits-message');
  var limits = null;
  function check() {
    var number = Number((value.value || '').trim().replace(',', '.'));
    var invalid = !!(limits && (limits.installation_day || limits.duplicate ||
      (value.value !== '' && (!isFinite(number) || (limits.minimum !== null && number < limits.minimum) ||
       (limits.maximum !== null && number > limits.maximum)))));
    value.setCustomValidity(invalid ? 'Stav neodpovídá sousedním odečtům nebo je datum již obsazeno.' : '');
    if (limits && invalid) msg.textContent = value.validationMessage;
  }
  function reload() {
    limits = null;
    msg.textContent = 'Kontroluji návaznost odečtů…';
    value.setCustomValidity('');
    if (!meter.value || !date.value) return;
    var selectedMeter = meter.value, selectedDate = date.value;
    fetch('?page=reading_limits&meter_id=' + encodeURIComponent(selectedMeter) + '&date=' + encodeURIComponent(selectedDate),
      {credentials:'same-origin'}).then(function (r) { return r.json(); }).then(function (data) {
        if (selectedMeter !== meter.value || selectedDate !== date.value) return;
        if (!data.ok) { msg.textContent = data.message || 'Odečet nelze ověřit.'; return; }
        limits = data.limits;
        msg.textContent = 'Přípustný stav: od ' + (limits.minimum === null ? '0' : limits.minimum) +
          (limits.maximum === null ? ' bez horního omezení' : ' do ' + limits.maximum) + '.';
        check();
      }).catch(function () { msg.textContent = 'Online kontrola není dostupná; stav ověří server při uložení.'; });
  }
  meter.addEventListener('change', reload);
  date.addEventListener('change', reload);
  value.addEventListener('input', check);
  reload();
})();
