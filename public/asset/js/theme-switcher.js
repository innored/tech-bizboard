/* =============================================================
   LinkBuilder — 라이트 / 다크 테마 전환
   html[data-theme] + localStorage(lb-theme) + 시스템 설정 연동
   ============================================================= */
(function () {
  'use strict';

  var STORAGE_KEY = 'lb-theme';
  var DEFAULT_MODE = 'light';
  var MODES = ['light', 'dark', 'system'];

  var state = { mode: DEFAULT_MODE, resolved: DEFAULT_MODE };
  var media = window.matchMedia('(prefers-color-scheme: dark)');

  function resolveMode(mode) {
    if (MODES.indexOf(mode) < 0) mode = DEFAULT_MODE;
    if (mode === 'system') return media.matches ? 'dark' : 'light';
    return mode;
  }

  function applyResolved(resolved) {
    state.resolved = resolved;
    document.documentElement.setAttribute('data-theme', resolved);
  }

  function applyMode(mode) {
    mode = MODES.indexOf(mode) >= 0 ? mode : DEFAULT_MODE;
    state.mode = mode;
    try { localStorage.setItem(STORAGE_KEY, mode); } catch (e) { /* ignore */ }
    applyResolved(resolveMode(mode));
    syncUi();
  }

  function syncUi() {
    document.querySelectorAll('[data-theme-btn]').forEach(function (btn) {
      var on = btn.getAttribute('data-theme-btn') === state.mode;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function buildToggle(container) {
    if (!container) return;
    container.setAttribute('role', 'group');
    container.setAttribute('aria-label', '테마');

    var items = [
      { id: 'light', label: '라이트', icon: '☀' },
      { id: 'dark', label: '다크', icon: '🌙' },
      { id: 'system', label: '시스템', icon: '◐' }
    ];

    items.forEach(function (item) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'theme-btn';
      btn.setAttribute('data-theme-btn', item.id);
      btn.setAttribute('aria-pressed', 'false');
      btn.setAttribute('title', item.label);
      btn.innerHTML = '<span class="theme-icon" aria-hidden="true">' + item.icon + '</span><span class="theme-label">' + item.label + '</span>';
      btn.addEventListener('click', function () { applyMode(item.id); });
      container.appendChild(btn);
    });
  }

  function onSystemChange() {
    if (state.mode === 'system') applyResolved(resolveMode('system'));
  }

  function init() {
    var saved = DEFAULT_MODE;
    try { saved = localStorage.getItem(STORAGE_KEY) || DEFAULT_MODE; } catch (e) { /* ignore */ }
    state.mode = MODES.indexOf(saved) >= 0 ? saved : DEFAULT_MODE;
    applyResolved(resolveMode(state.mode));
    buildToggle(document.getElementById('theme-switcher'));
    syncUi();
    if (media.addEventListener) media.addEventListener('change', onSystemChange);
    else if (media.addListener) media.addListener(onSystemChange);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.LinkBuilderTheme = { apply: applyMode, getMode: function () { return state.mode; } };
})();
