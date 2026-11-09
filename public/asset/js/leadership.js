/* 리더십 수익 요약: 페이지에 실린 유상 수입을 수익 화면으로 그린다. 연도를 바꾸면 그 해로 다시 연다. */
(function () {
  'use strict';

  var root = document.querySelector('[data-leadership]');
  if (!root || typeof RevenueProfitView === 'undefined') return;
  var panel = root.querySelector('[data-panel="profit"]');
  var year = root.getAttribute('data-year') || '';

  var rows = [];
  try {
    rows = JSON.parse((document.getElementById('leadership-rows') || {}).textContent || '[]') || [];
  } catch (e) {
    rows = [];
  }

  var view = RevenueProfitView.mount(panel, { today: root.getAttribute('data-today') || '' });
  view.render(rows, year);

  panel.addEventListener('select:change', function (e) {
    if (!e.target.closest('[data-rev-year]')) return;
    var next = String((e.detail && e.detail.value) || '');
    if (/^\d{4}$/.test(next) && next !== year) {
      window.location.href = 'leadership?year=' + encodeURIComponent(next);
    }
  });
})();
