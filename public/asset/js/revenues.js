/* 수입 목록·반복 설정 */
(function () {
  'use strict';

  var root = document.querySelector('[data-revenues]');
  if (!root) return;

  var apiUrl = root.getAttribute('data-api') || 'api/revenues';
  var tplApi = root.getAttribute('data-tpl-api') || 'api/revenue_templates';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var yearEl = document.getElementById('rev-year');
  var listBody = document.getElementById('rev-list-body');
  var listEmpty = document.getElementById('rev-list-empty');
  var listCount = document.getElementById('rev-list-count');
  var tplBody = document.getElementById('rev-tpl-body');
  var tplEmpty = document.getElementById('rev-tpl-empty');
  var tplCount = document.getElementById('rev-tpl-count');
  var yearWrap = document.getElementById('rev-year-wrap');
  var yearIndex = document.getElementById('rev-year-index');
  var yearGroups = document.getElementById('rev-year-groups');
  var yearEmpty = document.getElementById('rev-year-empty');
  var monthCard = document.getElementById('rev-month-card');
  var addRowBtn = document.getElementById('rev-add-row');
  var yearViewBtn = document.getElementById('rev-year-view');
  var saveHint = document.getElementById('rev-save-hint');
  var lockHint = document.getElementById('rev-lock-hint');
  var monthLock = document.getElementById('rev-month-lock');
  var tplLoaded = false;

  var CAL_ICON =
    '<svg class="icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
  var SAVE_ICON =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M20 6 9 17l-5-5"/></svg>';
  var TRASH_ICON =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';
  var LOCK_ICON =
    '<svg class="rev-lock-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';

  function toast(title, body, kind) {
    if (typeof tbbToast === 'function') tbbToast(title, body, kind);
  }

  function digits(value) {
    return String(value || '').replace(/[^\d]/g, '');
  }

  function toInt(value) {
    var n = parseInt(digits(value), 10);
    return isNaN(n) ? 0 : n;
  }

  function fmt(n) {
    return Number(n || 0).toLocaleString('ko-KR');
  }

  function post(url, payload) {
    payload.csrf = csrf;
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.ok) {
          throw new Error((data && data.error) || '요청에 실패했습니다.');
        }
        return data;
      });
    });
  }

  function currentYear() {
    var y = yearEl ? String(yearEl.value || '').trim() : '';
    if (/^\d{4}$/.test(y)) return y;
    y = String(root.getAttribute('data-year') || '').trim();
    return /^\d{4}$/.test(y) ? y : String(new Date().getFullYear());
  }

  function currentMonth() {
    var m = String(root.getAttribute('data-month') || '').trim();
    return /^\d{2}$/.test(m) ? m : '';
  }

  function isYearView() {
    return currentMonth() === '';
  }

  function editableFrom() {
    return String(root.getAttribute('data-editable-from') || '');
  }

  function isYmLocked(ym) {
    var from = editableFrom();
    return ym !== '' && from !== '' && ym < from;
  }

  function isViewLocked() {
    var m = currentMonth();
    return m !== '' && isYmLocked(currentYear() + '-' + m);
  }

  function syncMode() {
    var yearMode = isYearView();
    var locked = isViewLocked();
    if (yearWrap) yearWrap.hidden = !yearMode;
    if (monthCard) monthCard.hidden = yearMode;
    if (addRowBtn) addRowBtn.disabled = yearMode || locked;
    if (yearViewBtn) {
      yearViewBtn.setAttribute('aria-pressed', yearMode ? 'true' : 'false');
      yearViewBtn.classList.toggle('btn-secondary', yearMode);
      yearViewBtn.classList.toggle('btn-outline', !yearMode);
    }
    if (saveHint) {
      saveHint.textContent = yearMode
        ? '달 카드를 고르면 줄을 고칩니다.'
        : (locked ? '전전월 이전은 볼 수만 있습니다.' : '각 줄의 저장을 누르면 반영됩니다.');
    }
    if (monthCard) monthCard.classList.toggle('is-locked', locked);
    if (lockHint) lockHint.hidden = !locked;
    if (monthLock) monthLock.hidden = !locked;
  }

  function syncUrl() {
    var y = currentYear();
    var m = currentMonth();
    var q = '?year=' + encodeURIComponent(y);
    if (m) q += '&month=' + encodeURIComponent(m);
    history.replaceState(null, '', q);
    root.setAttribute('data-year', y);
    root.setAttribute('data-month', m);
    if (yearEl) yearEl.value = y;
  }

  function setPeriod(year, month) {
    root.setAttribute('data-year', year);
    root.setAttribute('data-month', month || '');
    if (yearEl) yearEl.value = year;
    syncUrl();
    syncMode();
    loadList();
  }

  function fetchRows(query) {
    return fetch(apiUrl + query, { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || '목록을 불러오지 못했습니다.');
        return data.rows || [];
      });
  }

  function loadList() {
    var y = currentYear();
    var m = currentMonth();
    var yearReq = fetchRows('?year=' + encodeURIComponent(y));
    if (!m) {
      return yearReq
        .then(function (rows) {
          renderYearIndex(rows);
          renderYearGroups(rows);
        })
        .catch(function (err) {
          toast('목록 오류', err.message, 'danger');
        });
    }
    return Promise.all([
      yearReq,
      fetchRows('?year=' + encodeURIComponent(y) + '&month=' + encodeURIComponent(m))
    ])
      .then(function (pair) {
        renderYearIndex(pair[0]);
        renderMonthTable(pair[1]);
      })
      .catch(function (err) {
        toast('목록 오류', err.message, 'danger');
      });
  }

  function loadTpl() {
    return fetch(tplApi, { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || '반복 설정을 불러오지 못했습니다.');
        tplLoaded = true;
        renderTpl(data.rows || []);
      })
      .catch(function (err) {
        toast('반복 설정 오류', err.message, 'danger');
      });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escapeAttr(s) {
    return escapeHtml(s);
  }

  function periodLabel(start, end) {
    var s = String(start || '').trim();
    var e = String(end || '').trim();
    if (!s) return '';
    return e ? (s + ' ~ ' + e) : s;
  }

  function parsePeriod(value) {
    var parts = String(value || '').split(/\s*~\s*/);
    return {
      start: (parts[0] || '').trim(),
      end: (parts[1] || '').trim()
    };
  }

  function periodHtml(start, end) {
    return (
      '<div class="input-wrap is-period">' +
        CAL_ICON +
        '<input class="input has-icon-left is-period" type="text" size="1" data-field="period" data-datepicker-month-range' +
          ' autocomplete="off" placeholder="시작월 ~ 종료월" readonly value="' + escapeAttr(periodLabel(start, end)) + '" />' +
      '</div>'
    );
  }

  function dateHtml(field, value, kind) {
    var attr = kind === 'month' ? 'data-datepicker-month' : 'data-datepicker';
    var ph = kind === 'month' ? '연-월 선택' : 'YYYY-MM-DD';
    return (
      '<div class="input-wrap">' +
        CAL_ICON +
        '<input class="input has-icon-left" type="text" size="1" data-field="' + escapeAttr(field) + '" ' + attr +
          ' autocomplete="off" placeholder="' + ph + '" readonly value="' + escapeAttr(value || '') + '" />' +
      '</div>'
    );
  }

  function deleteBtn(action) {
    return '<button class="btn btn-outline btn-sm" type="button" data-action="' + escapeAttr(action) + '" aria-label="삭제">' +
      TRASH_ICON + '</button>';
  }

  function saveBtn(action) {
    return '<button class="btn btn-outline btn-sm" type="button" data-action="' + escapeAttr(action) + '" aria-label="저장">' +
      SAVE_ICON + '</button>';
  }

  function updateCount(body, countEl, emptyEl) {
    var n = body ? body.querySelectorAll('tr.rev-row').length : 0;
    if (countEl) countEl.textContent = '총 ' + n + '건';
    if (emptyEl) emptyEl.hidden = n > 0;
  }

  function emptyRowHtml(colspan, msg) {
    return '<tr class="rev-empty-row"><td class="rev-empty-cell" colspan="' + colspan + '">' + escapeHtml(msg) + '</td></tr>';
  }

  /** 데이터가 없으면 표 본문에 전체 열 span 가운데 정렬 행을 넣고, 외부 힌트는 숨긴다. */
  function syncEmptyRow(body, colspan, msg, emptyEl) {
    if (emptyEl) emptyEl.hidden = true;
    if (!body) return;
    var hasRow = body.querySelector('tr.rev-row');
    var existing = body.querySelector('tr.rev-empty-row');
    if (hasRow) {
      if (existing) existing.remove();
      return;
    }
    if (!existing) body.insertAdjacentHTML('beforeend', emptyRowHtml(colspan, msg));
  }

  function listMoneySums() {
    var supply = 0;
    var vat = 0;
    var amount = 0;
    if (!listBody) return { supply: 0, vat: 0, amount: 0 };
    listBody.querySelectorAll('tr.rev-row').forEach(function (tr) {
      supply += toInt(fieldValue(tr, 'supply_krw'));
      vat += toInt(fieldValue(tr, 'vat_krw'));
      amount += toInt(fieldValue(tr, 'amount_krw'));
    });
    return { supply: supply, vat: vat, amount: amount };
  }

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function updateListSummary() {
    updateCount(listBody, listCount, listEmpty);
    syncEmptyRow(listBody, 11, '수입이 없습니다.', listEmpty);
    var sums = listMoneySums();
    setText('rev-list-total', fmt(sums.amount));
    setText('rev-sum-supply', fmt(sums.supply));
    setText('rev-sum-vat', fmt(sums.vat));
    setText('rev-sum-amount', fmt(sums.amount));
  }

  function tplSupplySum() {
    var supply = 0;
    if (!tplBody) return 0;
    tplBody.querySelectorAll('tr.rev-row').forEach(function (tr) {
      supply += toInt(fieldValue(tr, 'supply_krw'));
    });
    return supply;
  }

  function updateTplSummary() {
    updateCount(tplBody, tplCount, tplEmpty);
    syncEmptyRow(tplBody, 9, '반복 수입이 없습니다.', tplEmpty);
    var supply = tplSupplySum();
    setText('rev-tpl-total', fmt(supply));
    setText('rev-tpl-sum-supply', fmt(supply));
  }

  function bootWidgets(scope) {
    if (!scope) return;
    if (window.DesignSystem && typeof window.DesignSystem.initSelects === 'function') {
      window.DesignSystem.initSelects(scope);
    }
    if (!window.TechBizBoardDatepicker) return;
    scope.querySelectorAll('[data-datepicker]').forEach(function (el) {
      window.TechBizBoardDatepicker.day(el);
    });
    scope.querySelectorAll('[data-datepicker-month]').forEach(function (el) {
      window.TechBizBoardDatepicker.month(el);
    });
    scope.querySelectorAll('[data-datepicker-month-range]').forEach(function (el) {
      window.TechBizBoardDatepicker.monthRange(el);
    });
  }

  function listRowHtml(row, locked) {
    var id = row.id ? String(row.id) : '';
    var repeating = !!row.revenue_template_id;
    var status = row.status === 'PENDING' ? 'PENDING' : 'COMPLETED';
    var dis = locked ? ' disabled' : '';
    var dateCell = locked
      ? '<span class="rev-lock-text">' + escapeHtml(row.received_date || '') + '</span>'
      : dateHtml('received_date', row.received_date || '', 'day');
    var actions = locked ? '' : saveBtn('save-row') + deleteBtn('delete-row');
    return (
      '<tr class="rev-row' + (locked ? ' is-locked' : '') + '" data-id="' + escapeAttr(id) + '"' + (id ? '' : ' data-draft="1"') +
        ' data-created-by="' + escapeAttr(row.created_by || '') + '">' +
        '<td class="col-date">' + dateCell + '</td>' +
        '<td class="col-project"><input class="input" type="text" size="1" data-field="project_name" value="' + escapeAttr(row.project_name || '') + '" placeholder="프로젝트"' + dis + ' /></td>' +
        '<td class="col-client"><input class="input" type="text" size="1" data-field="client_name" value="' + escapeAttr(row.client_name || '') + '" placeholder="선택"' + dis + ' /></td>' +
        '<td class="num col-money"><input class="input num" type="text" size="1" inputmode="numeric" data-field="supply_krw" data-money="supply" value="' + escapeAttr(row.supply_krw == null || row.supply_krw === '' ? '' : fmt(row.supply_krw)) + '"' + dis + ' /></td>' +
        '<td class="num col-money"><input class="input num" type="text" size="1" inputmode="numeric" data-field="vat_krw" data-money="vat" value="' + escapeAttr(row.vat_krw == null || row.vat_krw === '' ? '' : fmt(row.vat_krw)) + '"' + dis + ' /></td>' +
        '<td class="num col-money"><input class="input num" type="text" size="1" inputmode="numeric" data-field="amount_krw" data-money="amount" value="' + escapeAttr(row.amount_krw == null || row.amount_krw === '' ? '' : fmt(row.amount_krw)) + '"' + dis + ' /></td>' +
        '<td class="col-assignee"><input class="input" type="text" size="1" data-field="assignee" value="' + escapeAttr(row.assignee || '') + '" placeholder="담당"' + dis + ' /></td>' +
        '<td class="col-author"><span class="rev-author">' + escapeHtml(row.created_by || '') + '</span></td>' +
        '<td class="rev-kind col-kind">' + (repeating ? '<span class="badge badge-brand">반복</span>' : '<span class="badge badge-muted">단건</span>') +
          '<input type="hidden" data-field="status" value="' + escapeAttr(status) + '" />' +
        '</td>' +
        '<td class="col-note"><input class="input" type="text" size="1" data-field="note" value="' + escapeAttr(row.note || '') + '"' + dis + ' /></td>' +
        '<td class="col-actions">' + actions + '</td>' +
      '</tr>'
    );
  }

  function tplRowHtml(row) {
    var id = row.id ? String(row.id) : '';
    var active = row.is_active === undefined ? true : Number(row.is_active) === 1;
    return (
      '<tr class="rev-row" data-id="' + escapeAttr(id) + '"' + (id ? '' : ' data-draft="1"') + '>' +
        '<td class="col-project"><input class="input" type="text" size="1" data-field="project_name" value="' + escapeAttr(row.project_name || '') + '" placeholder="프로젝트" /></td>' +
        '<td class="col-client"><input class="input" type="text" size="1" data-field="client_name" value="' + escapeAttr(row.client_name || '') + '" placeholder="선택" /></td>' +
        '<td class="num col-money"><input class="input num" type="text" size="1" inputmode="numeric" data-field="supply_krw" value="' + escapeAttr(row.supply_krw == null || row.supply_krw === '' ? '' : fmt(row.supply_krw)) + '" /></td>' +
        '<td class="col-assignee"><input class="input" type="text" size="1" data-field="assignee" value="' + escapeAttr(row.assignee || '') + '" placeholder="담당" /></td>' +
        '<td class="col-author"><span class="rev-author">' + escapeHtml(row.created_by || '') + '</span></td>' +
        '<td class="col-period">' + periodHtml(row.start_year_month, row.end_year_month) + '</td>' +
        '<td class="col-active"><label class="switch"><input type="checkbox" data-field="is_active" value="1"' + (active ? ' checked' : '') + ' /><span class="track"><span class="thumb"></span></span></label></td>' +
        '<td class="col-note"><input class="input" type="text" size="1" data-field="note" value="' + escapeAttr(row.note || '') + '" /></td>' +
        '<td class="col-actions">' + saveBtn('save-tpl') + deleteBtn('delete-tpl') + '</td>' +
      '</tr>'
    );
  }

  function compactWon(n) {
    var abs = Math.abs(Number(n) || 0);
    if (abs >= 10000) return Math.round(abs / 10000).toLocaleString('ko-KR') + '만';
    return fmt(abs);
  }

  function mdDate(value) {
    var m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return String(value || '');
    return String(parseInt(m[2], 10)) + '/' + String(parseInt(m[3], 10));
  }

  function yearMonthBuckets(year, rows) {
    var buckets = [];
    var i;
    for (i = 1; i <= 12; i++) {
      buckets.push({ month: String(i).padStart(2, '0'), count: 0, supply_krw: 0, vat_krw: 0, amount_krw: 0, rows: [] });
    }
    (rows || []).forEach(function (row) {
      var ym = String(row.target_year_month || '');
      if (ym.indexOf(year + '-') !== 0) return;
      var mm = ym.slice(5, 7);
      var idx = parseInt(mm, 10) - 1;
      if (idx < 0 || idx > 11) return;
      buckets[idx].count += 1;
      buckets[idx].supply_krw += Number(row.supply_krw || 0);
      buckets[idx].vat_krw += Number(row.vat_krw || 0);
      buckets[idx].amount_krw += Number(row.amount_krw || 0);
      buckets[idx].rows.push(row);
    });
    return buckets;
  }

  function cellState(year, month, count) {
    var today = String(root.getAttribute('data-today') || '');
    var nowYm = today.length >= 7 ? today.slice(0, 7) : '';
    var ym = year + '-' + month;
    if (count > 0) return 'is-filled';
    if (nowYm && ym > nowYm) return 'is-future';
    return 'is-empty';
  }

  function yearCellHtml(year, bucket) {
    var m = parseInt(bucket.month, 10);
    var state = cellState(year, bucket.month, bucket.count);
    var amount = state === 'is-filled' ? compactWon(bucket.amount_krw) : (state === 'is-future' ? '예정' : '·');
    var count = state === 'is-filled' ? (bucket.count + '건') : '';
    var active = currentMonth() === bucket.month ? ' is-active' : '';
    var locked = isYmLocked(year + '-' + bucket.month) ? ' is-locked' : '';
    return (
      '<button type="button" class="rev-year-cell ' + state + active + locked + '" data-goto-month="' + escapeAttr(bucket.month) + '"' +
        (locked ? ' title="잠긴 달"' : '') + '>' +
        (locked ? LOCK_ICON : '') +
        '<span class="rev-year-cell-m">' + m + '월</span>' +
        '<strong class="rev-year-cell-amt">' + escapeHtml(amount) + '</strong>' +
        '<span class="rev-year-cell-n">' + escapeHtml(count) + '</span>' +
      '</button>'
    );
  }

  function monthTag(ym) {
    var m = String(ym || '').match(/^\d{4}-(\d{2})$/);
    return m ? (parseInt(m[1], 10) + '월') : '';
  }

  function yearRowHtml(row) {
    var repeating = !!row.revenue_template_id;
    return (
      '<tr data-goto-month="' + escapeAttr(String(row.target_year_month || '').slice(5, 7)) + '">' +
        '<td class="col-ym">' + escapeHtml(monthTag(row.target_year_month)) + '</td>' +
        '<td class="col-date">' + escapeHtml(mdDate(row.received_date)) + '</td>' +
        '<td class="col-project">' + escapeHtml(row.project_name || '') + '</td>' +
        '<td class="col-client">' + escapeHtml(row.client_name || '') + '</td>' +
        '<td class="num col-money">' + fmt(row.supply_krw) + '</td>' +
        '<td class="num col-money">' + fmt(row.vat_krw) + '</td>' +
        '<td class="num col-money">' + fmt(row.amount_krw) + '</td>' +
        '<td class="col-assignee">' + escapeHtml(row.assignee || '') + '</td>' +
        '<td class="col-kind">' + (repeating ? '<span class="badge badge-brand">반복</span>' : '<span class="badge badge-muted">단건</span>') + '</td>' +
      '</tr>'
    );
  }

  /** 연간 보기: 한 해 전체 줄을 날짜순 단일 표로. 그룹 표 N개를 대체한다. */
  function yearListTableHtml(rows) {
    var sorted = rows.slice().sort(function (a, b) {
      return String(a.received_date || '').localeCompare(String(b.received_date || ''));
    });
    return (
      '<div class="table-wrap rev-table-wrap">' +
        '<table class="table rev-year-table">' +
          '<thead><tr>' +
            '<th class="col-ym">월</th><th class="col-date">입금일</th><th class="col-project">프로젝트</th><th class="col-client">거래처</th>' +
            '<th class="num col-money">공급가</th><th class="num col-money">부가세</th><th class="num col-money">합계</th>' +
            '<th class="col-assignee">담당</th><th class="col-kind">구분</th>' +
          '</tr></thead>' +
          '<tbody>' + (sorted.length ? sorted.map(yearRowHtml).join('') : emptyRowHtml(9, '데이터가 없습니다.')) + '</tbody>' +
        '</table>' +
      '</div>'
    );
  }

  function renderYearIndex(rows) {
    var year = currentYear();
    var buckets = yearMonthBuckets(year, rows);
    var supply = 0;
    var vat = 0;
    var total = 0;
    var count = 0;
    buckets.forEach(function (b) {
      supply += b.supply_krw;
      vat += b.vat_krw;
      total += b.amount_krw;
      count += b.count;
    });
    if (yearIndex) yearIndex.innerHTML = buckets.map(function (b) { return yearCellHtml(year, b); }).join('');
    setText('rev-year-supply', fmt(supply));
    setText('rev-year-vat', fmt(vat));
    setText('rev-year-total', fmt(total));
    setText('rev-year-count', '총 ' + count + '건');
    return buckets;
  }

  function renderYearGroups(rows) {
    var yr = currentYear();
    var yrRows = (rows || []).filter(function (r) {
      return String(r.target_year_month || '').indexOf(yr + '-') === 0;
    });
    if (yearGroups) yearGroups.innerHTML = yearListTableHtml(yrRows);
    if (yearEmpty) yearEmpty.hidden = true;
  }

  function renderMonthTable(rows) {
    if (!listBody) return;
    listBody.innerHTML = (rows || []).map(function (row) { return listRowHtml(row, isViewLocked()); }).join('');
    bootWidgets(listBody);
    updateListSummary();
  }

  function refreshYearIndex() {
    fetchRows('?year=' + encodeURIComponent(currentYear()))
      .then(renderYearIndex)
      .catch(function () { /* 월 표는 유지 */ });
  }

  function renderTpl(rows) {
    if (!tplBody) return;
    tplBody.innerHTML = rows.map(tplRowHtml).join('');
    bootWidgets(tplBody);
    updateTplSummary();
  }

  function fieldValue(tr, name) {
    var el = tr.querySelector('[data-field="' + name + '"]');
    if (!el) return '';
    if (el.type === 'checkbox') return el.checked ? '1' : '0';
    return el.value;
  }

  function setField(tr, name, value) {
    var el = tr.querySelector('[data-field="' + name + '"]');
    if (el) el.value = value;
  }

  function applyMoney(tr, field) {
    var supply = toInt(fieldValue(tr, 'supply_krw'));
    var vat = toInt(fieldValue(tr, 'vat_krw'));
    var amount = toInt(fieldValue(tr, 'amount_krw'));
    if (field === 'supply') {
      vat = Math.round(supply * 0.1);
      amount = supply + vat;
    } else if (field === 'vat') {
      amount = supply + vat;
    }
    setField(tr, 'supply_krw', fmt(supply));
    setField(tr, 'vat_krw', fmt(vat));
    setField(tr, 'amount_krw', fmt(amount));
  }

  function listPayload(tr, moneyField) {
    return {
      id: tr.getAttribute('data-id') || '',
      project_name: fieldValue(tr, 'project_name').trim(),
      client_name: fieldValue(tr, 'client_name').trim(),
      received_date: fieldValue(tr, 'received_date').trim(),
      assignee: fieldValue(tr, 'assignee').trim(),
      note: fieldValue(tr, 'note').trim(),
      status: fieldValue(tr, 'status') || 'COMPLETED',
      supply_krw: toInt(fieldValue(tr, 'supply_krw')),
      vat_krw: toInt(fieldValue(tr, 'vat_krw')),
      amount_krw: toInt(fieldValue(tr, 'amount_krw')),
      money_field: moneyField || ''
    };
  }

  function listReady(tr) {
    var amountEl = tr.querySelector('[data-field="amount_krw"]');
    var amountFilled = amountEl && String(amountEl.value).trim() !== '';
    return fieldValue(tr, 'project_name').trim() &&
      fieldValue(tr, 'received_date').trim() &&
      fieldValue(tr, 'assignee').trim() &&
      amountFilled;
  }

  function markInvalid(tr, on) {
    tr.classList.toggle('is-invalid', !!on);
  }

  function applySavedId(tr, row) {
    if (!row || !row.id) return;
    tr.removeAttribute('data-draft');
    tr.setAttribute('data-id', String(row.id));
    if (row.created_by) tr.setAttribute('data-created-by', row.created_by);
    var author = tr.querySelector('.rev-author');
    if (author && row.created_by) author.textContent = row.created_by;
  }

  function saveListRow(tr, moneyField) {
    if (isViewLocked()) {
      toast('수정할 수 없습니다', '전전월 이전은 볼 수만 있습니다.', 'danger');
      return;
    }
    if (tr._saving) return;
    if (!listReady(tr)) {
      markInvalid(tr, true);
      toast('저장 안 됨', '프로젝트, 입금일, 담당, 합계를 적어 주세요.', 'danger');
      return;
    }
    markInvalid(tr, false);
    var payload = listPayload(tr, moneyField);
    var isNew = !payload.id;
    payload.action = isNew ? 'create' : 'update';
    tr._saving = true;
    post(apiUrl, payload)
      .then(function (data) {
        applySavedId(tr, data.row);
        updateListSummary();
        refreshYearIndex();
        toast('저장됨', '수입을 저장했습니다.', 'success');
      })
      .catch(function (err) {
        toast('저장 실패', err.message, 'danger');
        markInvalid(tr, true);
      })
      .finally(function () {
        tr._saving = false;
      });
  }

  function tplReady(tr) {
    return fieldValue(tr, 'project_name').trim() && fieldValue(tr, 'assignee').trim();
  }

  function tplPayload(tr) {
    var period = parsePeriod(fieldValue(tr, 'period'));
    return {
      id: tr.getAttribute('data-id') || '',
      project_name: fieldValue(tr, 'project_name').trim(),
      client_name: fieldValue(tr, 'client_name').trim(),
      assignee: fieldValue(tr, 'assignee').trim(),
      start_year_month: period.start,
      end_year_month: period.end,
      supply_krw: toInt(fieldValue(tr, 'supply_krw')),
      note: fieldValue(tr, 'note').trim(),
      is_active: fieldValue(tr, 'is_active') === '1' ? 1 : 0
    };
  }

  function saveTplRow(tr) {
    if (tr._saving) return;
    if (!tplReady(tr)) {
      markInvalid(tr, true);
      toast('저장 안 됨', '프로젝트와 담당을 적어 주세요.', 'danger');
      return;
    }
    markInvalid(tr, false);
    var payload = tplPayload(tr);
    var isNew = !payload.id;
    payload.action = isNew ? 'create' : 'update';
    var supplyEl = tr.querySelector('[data-field="supply_krw"]');
    if (supplyEl) supplyEl.value = fmt(payload.supply_krw);
    tr._saving = true;
    post(tplApi, payload)
      .then(function (data) {
        applySavedId(tr, data.row);
        updateTplSummary();
        toast('저장됨', '반복 설정을 저장했습니다.', 'success');
      })
      .catch(function (err) {
        toast('저장 실패', err.message, 'danger');
        markInvalid(tr, true);
      })
      .finally(function () {
        tr._saving = false;
      });
  }

  function addListRow() {
    if (isYearView()) {
      toast('달을 고르세요', '연간 보기에서는 줄을 추가할 수 없습니다.', 'danger');
      return;
    }
    if (isViewLocked()) {
      toast('수정할 수 없습니다', '전전월 이전은 볼 수만 있습니다.', 'danger');
      return;
    }
    if (!listBody) return;
    var today = new Date();
    var pad = function (n) { return String(n).padStart(2, '0'); };
    var date = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
    var m = currentMonth();
    if (m) date = currentYear() + '-' + m + '-01';
    listBody.insertAdjacentHTML('afterbegin', listRowHtml({
      received_date: date,
      status: 'COMPLETED',
      supply_krw: '',
      vat_krw: '',
      amount_krw: ''
    }));
    var first = listBody.querySelector('tr');
    if (first) bootWidgets(first);
    updateListSummary();
    if (first) {
      var name = first.querySelector('[data-field="project_name"]');
      if (name) name.focus();
    }
  }

  function addTplRow() {
    if (!tplBody) return;
    var now = new Date();
    var start = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    tplBody.insertAdjacentHTML('afterbegin', tplRowHtml({
      start_year_month: start,
      is_active: 1,
      supply_krw: ''
    }));
    var first = tplBody.querySelector('tr');
    if (first) bootWidgets(first);
    updateTplSummary();
    if (first) {
      var name = first.querySelector('[data-field="project_name"]');
      if (name) name.focus();
    }
  }

  root.addEventListener('click', function (e) {
    var gotoMonth = e.target.closest('[data-goto-month]');
    if (gotoMonth) {
      var next = gotoMonth.getAttribute('data-goto-month') || '';
      if (gotoMonth.classList.contains('rev-year-cell') && currentMonth() === next) {
        next = '';
      }
      setPeriod(currentYear(), next);
      return;
    }
    var yearView = e.target.closest('[data-action="year-view"]');
    if (yearView) {
      if (!isYearView()) setPeriod(currentYear(), '');
      return;
    }
    var add = e.target.closest('[data-action="add-row"]');
    if (add) {
      addListRow();
      return;
    }
    var addTpl = e.target.closest('[data-action="add-tpl"]');
    if (addTpl) {
      addTplRow();
      return;
    }
    var saveList = e.target.closest('[data-action="save-row"]');
    if (saveList) {
      var listRow = saveList.closest('tr.rev-row');
      if (listRow) saveListRow(listRow, '');
      return;
    }
    var saveTpl = e.target.closest('[data-action="save-tpl"]');
    if (saveTpl) {
      var saveRow = saveTpl.closest('tr.rev-row');
      if (saveRow) saveTplRow(saveRow);
      return;
    }
    var del = e.target.closest('[data-action="delete-row"]');
    if (del) {
      if (isViewLocked()) {
        toast('수정할 수 없습니다', '전전월 이전은 볼 수만 있습니다.', 'danger');
        return;
      }
      var tr = del.closest('tr');
      if (!tr) return;
      var id = tr.getAttribute('data-id');
      if (!id) {
        tr.remove();
        updateListSummary();
        return;
      }
      if (!window.confirm('이 수입을 삭제할까요?')) return;
      post(apiUrl, { action: 'delete', id: id })
        .then(function () {
          tr.remove();
          updateListSummary();
          refreshYearIndex();
        })
        .catch(function (err) { toast('삭제 실패', err.message, 'danger'); });
      return;
    }
    var delTpl = e.target.closest('[data-action="delete-tpl"]');
    if (delTpl) {
      var trow = delTpl.closest('tr');
      if (!trow) return;
      var tid = trow.getAttribute('data-id');
      if (!tid) {
        trow.remove();
        updateTplSummary();
        return;
      }
      if (!window.confirm('이 반복 설정을 삭제할까요? 이미 있는 달 줄은 남습니다.')) return;
      post(tplApi, { action: 'delete', id: tid })
        .then(function () {
          trow.remove();
          updateTplSummary();
        })
        .catch(function (err) { toast('삭제 실패', err.message, 'danger'); });
    }
  });

  root.addEventListener('focusout', function (e) {
    var tr = e.target.closest('tr.rev-row');
    if (!tr) return;
    if (listBody && listBody.contains(tr)) {
      var money = e.target.getAttribute('data-money');
      if (money) {
        applyMoney(tr, money);
        updateListSummary();
      }
      return;
    }
    if (tplBody && tplBody.contains(tr) && e.target.getAttribute('data-field') === 'supply_krw') {
      e.target.value = fmt(toInt(e.target.value));
      updateTplSummary();
    }
  });

  root.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var tr = e.target.closest('tr.rev-row');
    if (!tr) return;
    if (e.target.tagName === 'TEXTAREA') return;
    if (e.target.hasAttribute('data-datepicker') || e.target.hasAttribute('data-datepicker-month') || e.target.hasAttribute('data-datepicker-month-range')) return;
    var money = e.target.getAttribute('data-money');
    if (money && listBody && listBody.contains(tr)) {
      e.preventDefault();
      applyMoney(tr, money);
      updateListSummary();
      return;
    }
    if (tplBody && tplBody.contains(tr) && e.target.getAttribute('data-field') === 'supply_krw') {
      e.preventDefault();
      e.target.value = fmt(toInt(e.target.value));
      updateTplSummary();
    }
  });

  root.addEventListener('select:change', function (e) {
    if (!e.target.closest('[data-rev-year]')) return;
    var y = yearEl ? String(yearEl.value || '').trim() : '';
    if (!/^\d{4}$/.test(y)) return;
    setPeriod(y, '');
  });

  var tabs = root.querySelector('[data-tabs]');
  if (tabs) {
    tabs.addEventListener('tab:change', function (e) {
      if (e.detail && e.detail.tab === 'tpl' && !tplLoaded) loadTpl();
    });
  }

  syncMode();
  loadList();
})();
