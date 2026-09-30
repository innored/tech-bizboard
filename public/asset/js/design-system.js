/* =============================================================
   LinkBuilder Design System — Vanilla JS
   커스텀 Select 드롭다운 + Segmented Control
   의존성 없음. DOMContentLoaded 이후 [data-select] / [role=tablist] 자동 초기화.
   ============================================================= */
(function () {
  'use strict';

  var CHECK_SVG =
    '<svg class="check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

  var SEARCH_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>';

  // 다른 select / multiselect 드롭다운 닫기 (트리거 stopPropagation 으로 document click 이 막히는 경우 대비)
  function closeOpenSelects(except) {
    document.querySelectorAll('.select.is-open').forEach(function (el) {
      if (el === except) return;
      el.classList.remove('is-open');
      var t = el.querySelector('.select-trigger');
      if (t) t.setAttribute('aria-expanded', 'false');
      var m = el.querySelector('.select-menu');
      if (!m) return;
      m.style.position = '';
      m.style.left = '';
      m.style.top = '';
      m.style.right = '';
      m.style.minWidth = '';
      m.style.zIndex = '';
    });
  }

  /* ---------- Custom Select (검색 옵션 지원: data-searchable) ---------- */
  function initSelect(root) {
    if (!root || root._dsSelect) return;
    root._dsSelect = true;
    var trigger = root.querySelector('.select-trigger');
    var menu = root.querySelector('.select-menu');
    var valueEl = root.querySelector('.select-value');
    var hidden = root.querySelector('input[type="hidden"]');
    var options = Array.prototype.slice.call(root.querySelectorAll('.select-option'));
    if (!trigger || !menu || !valueEl) return;

    var searchable = root.hasAttribute('data-searchable');
    var searchInput = null, emptyEl = null, activeEl = null;

    // 각 옵션에 체크 아이콘 주입(마크업 단순화)
    options.forEach(function (opt) {
      if (!opt.querySelector('.check-icon')) opt.insertAdjacentHTML('beforeend', CHECK_SVG);
      opt.setAttribute('role', 'option');
    });

    // 검색창/빈결과 요소 주입
    if (searchable) {
      var search = document.createElement('li');
      search.className = 'select-search';
      search.innerHTML = '<span class="ss-wrap">' + SEARCH_SVG +
        '<input type="text" placeholder="검색…" autocomplete="off" /></span>';
      menu.insertBefore(search, menu.firstChild);
      searchInput = search.querySelector('input');

      emptyEl = document.createElement('li');
      emptyEl.className = 'select-empty';
      emptyEl.textContent = '검색 결과가 없습니다';
      emptyEl.style.display = 'none';
      menu.appendChild(emptyEl);

      searchInput.addEventListener('click', function (e) { e.stopPropagation(); });
      searchInput.addEventListener('input', function () { filter(this.value); });
      searchInput.addEventListener('keydown', handleKeys);
    }

    function visible() { return options.filter(function (o) { return o.style.display !== 'none'; }); }

    function setActive(el) {
      options.forEach(function (o) { o.classList.remove('is-active'); });
      activeEl = el || null;
      if (activeEl) { activeEl.classList.add('is-active'); activeEl.scrollIntoView({ block: 'nearest' }); }
    }
    function move(dir) {
      var vis = visible(); if (!vis.length) return;
      var i = vis.indexOf(activeEl);
      i = i < 0 ? (dir > 0 ? 0 : vis.length - 1) : i + dir;
      i = Math.max(0, Math.min(vis.length - 1, i));
      setActive(vis[i]);
    }
    function filter(q) {
      q = q.trim().toLowerCase();
      var any = false;
      options.forEach(function (o) {
        var match = o.textContent.toLowerCase().indexOf(q) >= 0;
        o.style.display = match ? '' : 'none';
        if (match) any = true;
      });
      if (emptyEl) emptyEl.style.display = any ? 'none' : 'block';
      setActive(visible()[0] || null);
    }

    function placeMenu() {
      var rect = trigger.getBoundingClientRect();
      var menuH = menu.scrollHeight || 88;
      var gap = 6;
      var below = window.innerHeight - rect.bottom;
      var top = (below < menuH + gap && rect.top > menuH + gap)
        ? rect.top - menuH - gap
        : rect.bottom + gap;
      var left = Math.min(rect.left, window.innerWidth - Math.max(rect.width, 120) - 8);
      menu.style.position = 'fixed';
      menu.style.left = Math.max(8, left) + 'px';
      menu.style.top = Math.max(8, top) + 'px';
      menu.style.right = 'auto';
      menu.style.minWidth = Math.max(rect.width, 120) + 'px';
      menu.style.zIndex = '70';
    }

    function resetMenu() {
      menu.style.position = '';
      menu.style.left = '';
      menu.style.top = '';
      menu.style.right = '';
      menu.style.minWidth = '';
      menu.style.zIndex = '';
    }

    function open() {
      closeOpenSelects(root);
      root.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      placeMenu();
      if (searchable) { searchInput.value = ''; filter(''); setTimeout(function () { searchInput.focus(); }, 0); }
      var vis = visible();
      var selected = vis.filter(function (o) { return o.classList.contains('is-selected'); })[0];
      setActive(selected || vis[0] || null);
    }
    function close() {
      root.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
      resetMenu();
    }
    function toggle() { root.classList.contains('is-open') ? close() : open(); }

    function choose(opt) {
      options.forEach(function (o) {
        o.classList.remove('is-selected');
        o.setAttribute('aria-selected', 'false');
      });
      opt.classList.add('is-selected');
      opt.setAttribute('aria-selected', 'true');
      valueEl.textContent = opt.textContent.trim();
      valueEl.classList.remove('is-placeholder');
      if (hidden) {
        hidden.value = opt.hasAttribute('data-value')
          ? (opt.getAttribute('data-value') || '')
          : opt.textContent.trim();
      }
      root.dispatchEvent(new CustomEvent('select:change', {
        bubbles: true,
        detail: { value: hidden ? hidden.value : null, label: valueEl.textContent }
      }));
      close();
      trigger.focus();
    }

    function handleKeys(e) {
      var isOpen = root.classList.contains('is-open');
      switch (e.key) {
        case 'ArrowDown': e.preventDefault(); isOpen ? move(1) : open(); break;
        case 'ArrowUp':   e.preventDefault(); isOpen ? move(-1) : open(); break;
        case 'Enter':     e.preventDefault(); isOpen && activeEl ? choose(activeEl) : open(); break;
        case 'Escape':    if (isOpen) { e.preventDefault(); close(); trigger.focus(); } break;
        case 'Tab':       close(); break;
        case ' ':         if (e.target === trigger) { e.preventDefault(); isOpen && activeEl ? choose(activeEl) : open(); } break;
      }
    }

    trigger.addEventListener('click', function (e) { e.stopPropagation(); toggle(); });
    trigger.addEventListener('keydown', handleKeys);

    options.forEach(function (opt) {
      opt.addEventListener('mouseenter', function () { setActive(opt); });
      opt.addEventListener('click', function (e) { e.stopPropagation(); choose(opt); });
    });

    document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(); });
    window.addEventListener('scroll', function () {
      if (root.classList.contains('is-open')) close();
    }, true);
    window.addEventListener('resize', function () {
      if (root.classList.contains('is-open')) close();
    });
  }

  var X_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

  /* ---------- Multi Select (검색 + 다중선택) ---------- */
  function initMultiSelect(root) {
    var trigger = root.querySelector('.select-trigger');
    var menu = root.querySelector('.select-menu');
    var tagsEl = root.querySelector('.ms-tags');
    var hidden = root.querySelector('input[type="hidden"]');
    var options = Array.prototype.slice.call(root.querySelectorAll('.select-option'));
    if (!trigger || !menu || !tagsEl) return;

    var placeholder = tagsEl.getAttribute('data-placeholder') || '선택…';
    var searchable = root.hasAttribute('data-searchable');
    var searchInput = null, emptyEl = null, activeEl = null;

    options.forEach(function (o) { o.setAttribute('role', 'option'); });

    if (searchable) {
      var search = document.createElement('li');
      search.className = 'select-search';
      search.innerHTML = '<span class="ss-wrap">' + SEARCH_SVG +
        '<input type="text" placeholder="검색…" autocomplete="off" /></span>';
      menu.insertBefore(search, menu.firstChild);
      searchInput = search.querySelector('input');
      emptyEl = document.createElement('li');
      emptyEl.className = 'select-empty';
      emptyEl.textContent = '검색 결과가 없습니다';
      emptyEl.style.display = 'none';
      menu.appendChild(emptyEl);
      searchInput.addEventListener('click', function (e) { e.stopPropagation(); });
      searchInput.addEventListener('input', function () { filter(this.value); });
      searchInput.addEventListener('keydown', handleKeys);
    }

    function visible() { return options.filter(function (o) { return o.style.display !== 'none'; }); }
    function setActive(el) {
      options.forEach(function (o) { o.classList.remove('is-active'); });
      activeEl = el || null;
      if (activeEl) { activeEl.classList.add('is-active'); activeEl.scrollIntoView({ block: 'nearest' }); }
    }
    function move(dir) {
      var vis = visible(); if (!vis.length) return;
      var i = vis.indexOf(activeEl);
      i = i < 0 ? (dir > 0 ? 0 : vis.length - 1) : Math.max(0, Math.min(vis.length - 1, i + dir));
      setActive(vis[i]);
    }
    function filter(q) {
      q = q.trim().toLowerCase(); var any = false;
      options.forEach(function (o) {
        var m = o.textContent.toLowerCase().indexOf(q) >= 0;
        o.style.display = m ? '' : 'none'; if (m) any = true;
      });
      if (emptyEl) emptyEl.style.display = any ? 'none' : 'block';
      setActive(visible()[0] || null);
    }

    function labelOf(o) { return o.getAttribute('data-label') || o.textContent.trim(); }
    function valueOf(o) { return o.getAttribute('data-value') || o.textContent.trim(); }
    function selected() { return options.filter(function (o) { return o.classList.contains('is-selected'); }); }

    function render() {
      tagsEl.innerHTML = '';
      var sel = selected();
      if (!sel.length) {
        var ph = document.createElement('span');
        ph.className = 'select-value is-placeholder';
        ph.textContent = placeholder;
        tagsEl.appendChild(ph);
      } else {
        sel.forEach(function (o) {
          var tag = document.createElement('span');
          tag.className = 'ms-tag';
          tag.appendChild(document.createTextNode(labelOf(o)));
          var btn = document.createElement('button');
          btn.type = 'button'; btn.setAttribute('aria-label', '제거'); btn.innerHTML = X_SVG;
          btn.addEventListener('click', function (e) { e.stopPropagation(); toggle(o); });
          tag.appendChild(btn);
          tagsEl.appendChild(tag);
        });
      }
      if (hidden) hidden.value = selected().map(valueOf).join(',');
    }

    function toggle(o) {
      var on = o.classList.toggle('is-selected');
      o.setAttribute('aria-selected', on ? 'true' : 'false');
      render();
      root.dispatchEvent(new CustomEvent('multiselect:change', {
        detail: { values: selected().map(valueOf) }
      }));
    }

    function open() {
      closeOpenSelects(root);
      root.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      if (searchable) { searchInput.value = ''; filter(''); setTimeout(function () { searchInput.focus(); }, 0); }
      setActive(visible()[0] || null);
    }
    function close() { root.classList.remove('is-open'); trigger.setAttribute('aria-expanded', 'false'); }

    function handleKeys(e) {
      var isOpen = root.classList.contains('is-open');
      switch (e.key) {
        case 'ArrowDown': e.preventDefault(); isOpen ? move(1) : open(); break;
        case 'ArrowUp':   e.preventDefault(); isOpen ? move(-1) : open(); break;
        case 'Enter':     e.preventDefault(); if (isOpen && activeEl) toggle(activeEl); else open(); break;
        case 'Escape':    if (isOpen) { e.preventDefault(); close(); trigger.focus(); } break;
        case 'Tab':       close(); break;
      }
    }

    trigger.addEventListener('click', function (e) {
      if (e.target.closest('.ms-tag button')) return; // 태그 제거 버튼 클릭은 제외
      e.stopPropagation();
      root.classList.contains('is-open') ? close() : open();
    });
    trigger.addEventListener('keydown', handleKeys);
    options.forEach(function (o) {
      o.addEventListener('mouseenter', function () { setActive(o); });
      o.addEventListener('click', function (e) { e.stopPropagation(); toggle(o); });
    });
    document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(); });

    render();
  }

  /* ---------- Segmented Control ---------- */
  function initSegmented(group) {
    group.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        group.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
      });
    });
  }

  /* ---------- Pagination (컴팩트 · "현재/총") ----------
     data-total(총 페이지), data-current(현재 페이지) 기준으로 맨앞/이전/다음/맨뒤 이동.
     숫자 버튼(.page-btn[data-page])이 있으면 활성 상태도 함께 동기화.
     실제 앱에서는 page:change 이벤트를 받아 서버 조회/렌더링에 연결하세요. */
  function initPagination(root) {
    var infoEl = root.querySelector('.pagination-info');
    var total = parseInt(root.getAttribute('data-total') || '1', 10);
    var cur = parseInt(root.getAttribute('data-current') || '1', 10);
    var numbered = root.querySelectorAll('.page-btn[data-page]');

    function updateInfo() { if (infoEl) infoEl.innerHTML = '<strong>' + cur + '</strong> / ' + total; }
    function updateEnds() {
      root.querySelectorAll('[data-nav="first"],[data-nav="prev"]').forEach(function (b) { b.disabled = cur <= 1; });
      root.querySelectorAll('[data-nav="last"],[data-nav="next"]').forEach(function (b) { b.disabled = cur >= total; });
    }
    function go(page) {
      cur = Math.max(1, Math.min(total, page));
      numbered.forEach(function (b) { b.classList.toggle('is-active', parseInt(b.getAttribute('data-page'), 10) === cur); });
      updateInfo(); updateEnds();
      root.dispatchEvent(new CustomEvent('page:change', { detail: { page: cur, total: total } }));
    }

    root.addEventListener('click', function (e) {
      var nav = e.target.closest('[data-nav]');
      if (nav && !nav.disabled) {
        var t = nav.getAttribute('data-nav');
        go(t === 'first' ? 1 : t === 'last' ? total : t === 'prev' ? cur - 1 : cur + 1);
        return;
      }
      var btn = e.target.closest('.page-btn[data-page]');
      if (btn && !btn.disabled) go(parseInt(btn.getAttribute('data-page'), 10));
    });

    updateInfo(); updateEnds();
  }

  /* ---------- Copy to clipboard (코드 영역 내 복사 버튼) ---------- */
  var COPIED_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(function () { fallbackCopy(text); });
    }
    fallbackCopy(text);
    return Promise.resolve();
  }
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
  function initCopy(btn) {
    var original = btn.innerHTML;
    var timer;
    btn.addEventListener('click', function () {
      var block = btn.closest('.codeblock');
      if (!block) return;
      var src = block.querySelector('code, pre');
      var text;
      if (src) { text = src.textContent.trim(); }
      else {
        var clone = block.cloneNode(true);
        clone.querySelectorAll('.code-copy').forEach(function (b) { b.remove(); });
        text = clone.textContent.trim();
      }
      copyText(text).then(function () {
        btn.classList.add('is-copied');
        btn.innerHTML = COPIED_SVG;
        btn.setAttribute('aria-label', '복사됨');
        clearTimeout(timer);
        timer = setTimeout(function () {
          btn.classList.remove('is-copied');
          btn.innerHTML = original;
          btn.setAttribute('aria-label', '복사');
        }, 1500);
      });
    });
  }

  /* ---------- Tabs (언더라인 · 패널 전환) ---------- */
  function initTabs(root) {
    var tabs = Array.prototype.slice.call(root.querySelectorAll('.tab'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('.tab-panel'));
    if (!tabs.length) return;

    function activate(tab, silent) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.setAttribute('tabindex', on ? '0' : '-1');
      });
      var id = tab.getAttribute('data-tab');
      panels.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-panel') === id); });
      if (!silent) root.dispatchEvent(new CustomEvent('tab:change', { detail: { tab: id } }));
    }

    tabs.forEach(function (tab) {
      tab.setAttribute('role', 'tab');
      tab.addEventListener('click', function () { activate(tab); });
      tab.addEventListener('keydown', function (e) {
        var idx = tabs.indexOf(tab), next;
        if (e.key === 'ArrowRight') next = tabs[(idx + 1) % tabs.length];
        else if (e.key === 'ArrowLeft') next = tabs[(idx - 1 + tabs.length) % tabs.length];
        if (next) { e.preventDefault(); next.focus(); activate(next); }
      });
    });

    activate(root.querySelector('.tab.is-active') || tabs[0], true);
  }

  /* ---------- Sortable Table (헤더 클릭 정렬) ----------
     <table class="table" data-sortable> + <th data-sort="text|num|date">.
     셀에 data-value 를 주면 그 값 기준으로 정렬(배지 등 표시와 정렬키 분리 가능). */
  var SORT_ICONS = {
    none: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 9 4-4 4 4"/><path d="m8 15 4 4 4-4"/></svg>',
    ascending: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>',
    descending: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>'
  };

  function initSortTable(table) {
    var headRow = table.tHead && table.tHead.rows[0];
    var tbody = table.tBodies[0];
    if (!headRow || !tbody) return;
    var ths = Array.prototype.slice.call(headRow.cells);

    function cellVal(cell, type) {
      if (!cell) return type === 'num' ? 0 : '';
      var raw = cell.getAttribute('data-value');
      if (raw == null) raw = cell.textContent.trim();
      if (type === 'num') { var n = parseFloat(String(raw).replace(/[^0-9.\-]/g, '')); return isNaN(n) ? 0 : n; }
      if (type === 'date') return raw; // ISO(yyyy-MM-dd)는 문자열 정렬로도 정확
      return String(raw).toLowerCase();
    }

    function sortBy(th) {
      var idx = ths.indexOf(th);
      var type = th.getAttribute('data-sort') || 'text';
      var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
      var mul = dir === 'ascending' ? 1 : -1;

      ths.forEach(function (o) {
        if (!o.hasAttribute('data-sort')) return;
        var on = o === th;
        o.setAttribute('aria-sort', on ? dir : 'none');
        var ic = o.querySelector('.sort-icon');
        if (ic) ic.innerHTML = SORT_ICONS[on ? dir : 'none'];
      });

      var rows = Array.prototype.slice.call(tbody.rows);
      rows.sort(function (a, b) {
        var va = cellVal(a.cells[idx], type), vb = cellVal(b.cells[idx], type);
        return va < vb ? -1 * mul : va > vb ? 1 * mul : 0;
      });
      rows.forEach(function (r) { tbody.appendChild(r); });
      table.dispatchEvent(new CustomEvent('sort:change', { detail: { column: idx, type: type, dir: dir } }));
    }

    ths.forEach(function (th) {
      if (!th.hasAttribute('data-sort')) return;
      th.classList.add('sortable');
      th.setAttribute('aria-sort', 'none');
      th.setAttribute('tabindex', '0');
      var ic = document.createElement('span');
      ic.className = 'sort-icon';
      ic.innerHTML = SORT_ICONS.none;
      th.appendChild(ic);
      th.addEventListener('click', function () { sortBy(th); });
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sortBy(th); }
      });
    });
  }

  /* ---------- Tree (펼침/접힘 + 선택 / 체크박스 계층 연동) ---------- */
  // 부모 노드 상태를 직속 자식들로부터 계산: 전체 체크 → checked, 전무 → 해제, 혼합 → indeterminate
  function applyParentState(pNode, kids) {
    var full = kids.every(function (n) { return n.classList.contains('is-checked') && !n.classList.contains('is-indeterminate'); });
    var none = kids.every(function (n) { return !n.classList.contains('is-checked') && !n.classList.contains('is-indeterminate'); });
    pNode.classList.toggle('is-checked', full);
    pNode.classList.toggle('is-indeterminate', !full && !none);
  }
  function directChildNodes(li) {
    return Array.prototype.slice.call(li.querySelectorAll(':scope > ul > .tree-item > .tree-node'));
  }
  // 클릭 노드의 li → 위로 올라가며 조상 상태 갱신
  function updateAncestors(li, root) {
    var parentLi = li.parentElement.closest('.tree-item');
    if (!parentLi || !root.contains(parentLi)) return;
    applyParentState(parentLi.querySelector(':scope > .tree-node'), directChildNodes(parentLi));
    updateAncestors(parentLi, root);
  }
  // 초기 마크업의 자식 상태로 부모들 상태 세팅(리프 → 위로)
  function initCheckStates(root) {
    Array.prototype.slice.call(root.querySelectorAll('.tree-item')).reverse().forEach(function (li) {
      if (!li.querySelector(':scope > ul')) return;   // leaf
      applyParentState(li.querySelector(':scope > .tree-node'), directChildNodes(li));
    });
  }

  function initTree(root) {
    var checkable = root.classList.contains('checkable');
    // 초기 aria-expanded
    root.querySelectorAll('.tree-item').forEach(function (item) {
      if (item.classList.contains('is-leaf')) return;
      if (item.querySelector(':scope > ul')) {
        item.setAttribute('aria-expanded', item.classList.contains('is-collapsed') ? 'false' : 'true');
      }
    });
    if (checkable) initCheckStates(root);

    root.addEventListener('click', function (e) {
      var node = e.target.closest('.tree-node');
      if (!node || !root.contains(node)) return;
      var item = node.parentElement;
      var caretClicked = !!e.target.closest('.tree-caret');
      var hasChildren = !item.classList.contains('is-leaf') && !!item.querySelector(':scope > ul');
      // 펼침/접힘: 캐럿 클릭, 또는 선택형(비 checkable)에서 자식 있는 노드 클릭
      if (hasChildren && (caretClicked || !checkable)) {
        item.classList.toggle('is-collapsed');
        item.setAttribute('aria-expanded', item.classList.contains('is-collapsed') ? 'false' : 'true');
      }
      // 선택/체크 (캐럿만 클릭했을 땐 선택 상태 유지)
      if (!caretClicked) {
        if (checkable) {
          // 현재 완전체크면 해제, 아니면(부분/미체크) 전체 체크
          var on = !(node.classList.contains('is-checked') && !node.classList.contains('is-indeterminate'));
          // 자기 + 모든 하위 노드 동일하게
          item.querySelectorAll('.tree-node').forEach(function (n) {
            n.classList.toggle('is-checked', on);
            n.classList.remove('is-indeterminate');
          });
          updateAncestors(item, root);   // 조상 체크/부분선택 갱신
        } else {
          root.querySelectorAll('.tree-node.is-selected').forEach(function (n) { n.classList.remove('is-selected'); });
          node.classList.add('is-selected');
        }
      }
      root.dispatchEvent(new CustomEvent('tree:change', { detail: { node: node } }));
    });
  }

  /* ---------- Back to top (맨 위로 · 자동 생성) ---------- */
  function initToTop() {
    if (document.querySelector('.to-top')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'to-top';
    btn.setAttribute('aria-label', '맨 위로');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>';
    document.body.appendChild(btn);
    btn.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    var toggle = function () { btn.classList.toggle('is-visible', (window.pageYOffset || document.documentElement.scrollTop) > 300); };
    window.addEventListener('scroll', toggle, { passive: true });
    toggle();
  }

  function boot() {
    document.querySelectorAll('[data-select]').forEach(initSelect);
    document.querySelectorAll('[data-multiselect]').forEach(initMultiSelect);
    document.querySelectorAll('.tree').forEach(initTree);
    initToTop();
    document.querySelectorAll('.segmented').forEach(initSegmented);
    document.querySelectorAll('[data-tabs]').forEach(initTabs);
    document.querySelectorAll('[data-pagination]').forEach(initPagination);
    document.querySelectorAll('[data-copy]').forEach(initCopy);
    document.querySelectorAll('.table[data-sortable]').forEach(initSortTable);
  }

  window.DesignSystem = {
    initSelect: initSelect,
    initSelects: function (scope) {
      (scope || document).querySelectorAll('[data-select]').forEach(initSelect);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
