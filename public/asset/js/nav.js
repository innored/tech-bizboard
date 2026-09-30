(function () {
  'use strict';
  var toggle = document.getElementById('nav-toggle');
  if (!toggle) return;
  var header = document.querySelector('.app-header');
  var dim = document.getElementById('nav-dim');

  function setOpen(open) {
    header.classList.toggle('nav-open', open);
    document.body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? '메뉴 닫기' : '메뉴 열기');
  }

  toggle.addEventListener('click', function () {
    setOpen(!header.classList.contains('nav-open'));
  });
  if (dim) {
    dim.addEventListener('click', function () { setOpen(false); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setOpen(false);
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 900) setOpen(false);
  });
})();
