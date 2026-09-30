/* 디자인 가이드 .alert 를 우측 상단 토스트로 띄운다 */
(function (global) {
  'use strict';

  var FLASH_KEY = 'techbizboard-toast';

  function toast(title, body, kind) {
    var type = kind === 'danger' ? 'danger' : (kind === 'info' ? 'info' : 'success');
    var el = document.getElementById('draft-toast');
    if (!el) return;
    el.className = 'alert alert-' + type + ' draft-toast is-visible';
    el.querySelectorAll('[data-toast-kind]').forEach(function (svg) {
      svg.hidden = svg.getAttribute('data-toast-kind') !== type;
    });
    var titleEl = el.querySelector('.alert-title');
    var bodyEl = el.querySelector('.alert-body');
    if (titleEl) titleEl.textContent = title || '';
    if (bodyEl) {
      bodyEl.textContent = body || '';
      bodyEl.hidden = !body;
    }
    clearTimeout(toast._t);
    toast._t = setTimeout(function () {
      el.classList.remove('is-visible');
    }, 3200);
  }

  function flash(title, body, kind) {
    try {
      sessionStorage.setItem(FLASH_KEY, JSON.stringify({
        title: title || '',
        body: body || '',
        kind: kind || 'success'
      }));
    } catch (e) {
      /* 저장 후 이동 전에만 쓴다 */
    }
  }

  function consumeFlash() {
    var raw = null;
    try {
      raw = sessionStorage.getItem(FLASH_KEY);
      if (raw) sessionStorage.removeItem(FLASH_KEY);
    } catch (e) {
      return;
    }
    if (!raw) return;
    try {
      var msg = JSON.parse(raw);
      toast(msg.title, msg.body, msg.kind);
    } catch (e) {
      /* 잘못된 값은 버린다 */
    }
  }

  global.tbbToast = toast;
  global.tbbToastFlash = flash;
  consumeFlash();
})(window);
