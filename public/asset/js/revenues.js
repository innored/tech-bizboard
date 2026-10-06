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
  var revModal = document.getElementById('rev-modal');
  var tplLoaded = false;

  var CAL_ICON =
    '<svg class="icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
  var TRASH_ICON =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';
  var SAVE_ICON =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M20 6 9 17l-5-5"/></svg>';
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
        return data;
      });
  }

  function loadList() {
    var y = currentYear();
    var m = currentMonth();
    var yearReq = fetchRows('?year=' + encodeURIComponent(y));
    if (!m) {
      return yearReq
        .then(function (data) {
          renderYearIndex(data.rows || [], data.free_value_year || 0);
          renderYearGroups(data.rows || []);
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
        renderYearIndex(pair[0].rows || [], pair[0].free_value_year || 0);
        renderMonthTable(pair[1].rows || [], pair[1].free_value_month || 0);
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
      supply += toInt(tr.getAttribute('data-supply') || '');
      vat += toInt(tr.getAttribute('data-vat') || '');
      amount += toInt(tr.getAttribute('data-amount') || '');
    });
    return { supply: supply, vat: vat, amount: amount };
  }

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function updateListSummary() {
    updateCount(listBody, listCount, listEmpty);
    syncEmptyRow(listBody, 13, '수입이 없습니다.', listEmpty);
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

  /* ── 서비스구분 레이블 ── */
  function svcLabel(val) {
    if (!val) return '';
    return val;
  }

  /* ── 유상/무상 레이블 ── */
  function billingLabel(val) {
    if (val === 'FREE') return '무상';
    return '유상';
  }

  /* ── 읽기전용 수입 목록 행 ── */
  function listRowHtml(row) {
    var id = row.id ? String(row.id) : '';
    var repeating = !!row.revenue_template_id;
    var billing = row.billing_type === 'FREE' ? 'FREE' : 'PAID';
    var supplyKrw = Number(row.supply_krw || 0);
    var vatKrw = Number(row.vat_krw || 0);
    var amountKrw = Number(row.amount_krw || 0);
    var listKrw = Number(row.list_value_krw || 0);
    var moneyDisplay = billing === 'FREE'
      ? '<span class="rev-free-label">무상(정상가 ' + fmt(listKrw) + ')</span>'
      : fmt(amountKrw);
    return (
      '<tr class="rev-row is-click" data-id="' + escapeAttr(id) + '"' + (id ? '' : ' data-draft="1"') +
        ' data-supply="' + escapeAttr(String(supplyKrw)) + '"' +
        ' data-vat="' + escapeAttr(String(vatKrw)) + '"' +
        ' data-amount="' + escapeAttr(String(amountKrw)) + '"' +
        ' data-list-value="' + escapeAttr(String(listKrw)) + '"' +
        ' data-status="' + escapeAttr(row.status || 'COMPLETED') + '"' +
        ' data-created-by="' + escapeAttr(row.created_by || '') + '">' +
        '<td class="col-date">' + escapeHtml(row.received_date || '') + '</td>' +
        '<td class="col-project">' + escapeHtml(row.project_name || '') + '</td>' +
        '<td class="col-client">' + escapeHtml(row.client_name || '') + '</td>' +
        '<td class="col-svc">' + escapeHtml(svcLabel(row.service_category || '')) + '</td>' +
        '<td class="col-billing">' +
          (billing === 'FREE'
            ? '<span class="badge badge-muted">무상</span>'
            : '<span class="badge badge-brand">유상</span>') +
        '</td>' +
        '<td class="num col-money">' + (billing === 'FREE' ? '' : fmt(supplyKrw)) + '</td>' +
        '<td class="num col-money">' + (billing === 'FREE' ? '' : fmt(vatKrw)) + '</td>' +
        '<td class="num col-money">' + moneyDisplay + '</td>' +
        '<td class="col-assignee">' + escapeHtml(row.assignee || '') + '</td>' +
        '<td class="col-author"><span class="rev-author">' + escapeHtml(row.created_by || '') + '</span></td>' +
        '<td class="rev-kind col-kind">' + (repeating ? '<span class="badge badge-brand">반복</span>' : '<span class="badge badge-muted">단건</span>') + '</td>' +
        '<td class="col-note">' + escapeHtml(row.note || '') + '</td>' +
        '<td class="col-receipts">' + (Number(row.receipt_count || 0) > 0
          ? '<span class="rev-receipt-badge" title="증빙 ' + Number(row.receipt_count) + '건">📎 ' + Number(row.receipt_count) + '</span>'
          : '') + '</td>' +
        '<td class="col-actions">' + deleteBtn('delete-row') + '</td>' +
      '</tr>'
    );
  }

  /* ── 기존 행의 표시 데이터를 row 객체로 추출 ── */
  function rowDataFromTr(tr) {
    return {
      id: tr.getAttribute('data-id') || '',
      received_date: tr.querySelector('.col-date') ? tr.querySelector('.col-date').textContent.trim() : '',
      project_name: tr.querySelector('.col-project') ? tr.querySelector('.col-project').textContent.trim() : '',
      client_name: tr.querySelector('.col-client') ? tr.querySelector('.col-client').textContent.trim() : '',
      service_category: tr.querySelector('.col-svc') ? tr.querySelector('.col-svc').textContent.trim() : '',
      billing_type: tr.querySelector('.col-billing .badge-muted') ? 'FREE' : 'PAID',
      supply_krw: toInt(tr.getAttribute('data-supply') || ''),
      vat_krw: toInt(tr.getAttribute('data-vat') || ''),
      amount_krw: toInt(tr.getAttribute('data-amount') || ''),
      list_value_krw: toInt(tr.getAttribute('data-list-value') || ''),
      assignee: tr.querySelector('.col-assignee') ? tr.querySelector('.col-assignee').textContent.trim() : '',
      status: tr.getAttribute('data-status') || 'COMPLETED',
      note: tr.querySelector('.col-note') ? tr.querySelector('.col-note').textContent.trim() : '',
      created_by: tr.getAttribute('data-created-by') || '',
      revenue_template_id: null
    };
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

  function renderYearIndex(rows, freeValueYear) {
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
    setText('rev-year-free', fmt(freeValueYear || 0));
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

  function renderMonthTable(rows, freeValueMonth) {
    if (!listBody) return;
    listBody.innerHTML = (rows || []).map(function (row) { return listRowHtml(row); }).join('');
    updateListSummary();
    setText('rev-sum-free', fmt(freeValueMonth || 0));
  }

  function refreshYearIndex() {
    fetchRows('?year=' + encodeURIComponent(currentYear()))
      .then(function (data) {
        renderYearIndex(data.rows || [], data.free_value_year || 0);
      })
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

  function markInvalid(tr, on) {
    tr.classList.toggle('is-invalid', !!on);
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
        if (!payload.id && data.row && data.row.id) {
          tr.removeAttribute('data-draft');
          tr.setAttribute('data-id', String(data.row.id));
          if (data.row.created_by) tr.setAttribute('data-created-by', data.row.created_by);
          var author = tr.querySelector('.rev-author');
          if (author && data.row.created_by) author.textContent = data.row.created_by;
        }
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

  /* ════════════════════════════════════════
     모달 열기 / 닫기 / 저장
  ════════════════════════════════════════ */

  function modalEl(id) {
    return document.getElementById(id);
  }

  function setSelectValue(wrapId, hiddenId, value, labelText) {
    var hidden = modalEl(hiddenId);
    if (hidden) hidden.value = value;
    var wrap = modalEl(wrapId);
    if (!wrap) return;
    var valSpan = wrap.querySelector('.select-value');
    if (valSpan) valSpan.textContent = labelText;
    wrap.querySelectorAll('.select-option').forEach(function (opt) {
      opt.classList.toggle('is-selected', opt.getAttribute('data-value') === value);
    });
  }

  function applyBillingVisibility(billing) {
    var supplyField = modalEl('rev-m-supply-field');
    var listField = modalEl('rev-m-list-field');
    if (billing === 'FREE') {
      if (supplyField) supplyField.hidden = true;
      if (listField) listField.hidden = false;
    } else {
      if (supplyField) supplyField.hidden = false;
      if (listField) listField.hidden = true;
    }
  }

  function openRevModal(rowData) {
    if (!revModal) return;

    var titleEl = modalEl('rev-m-title');
    var isNew = !rowData || !rowData.id;
    if (titleEl) titleEl.textContent = isNew ? '수입 등록' : '수입 편집';

    /* 모달 dataset에 현재 편집 id 저장 */
    revModal.dataset.editId = isNew ? '' : String(rowData.id);

    /* 필드 채우기 */
    var received_date = modalEl('rev-m-received_date');
    var project_name = modalEl('rev-m-project_name');
    var client_name = modalEl('rev-m-client_name');
    var supply_krw = modalEl('rev-m-supply_krw');
    var list_value_krw = modalEl('rev-m-list_value_krw');
    var assignee = modalEl('rev-m-assignee');
    var note = modalEl('rev-m-note');

    var billing = (rowData && rowData.billing_type) ? rowData.billing_type : 'PAID';
    var status = (rowData && rowData.status) ? rowData.status : 'COMPLETED';
    var svcCat = (rowData && rowData.service_category) ? rowData.service_category : '';

    if (received_date) received_date.value = isNew ? '' : (rowData.received_date || '');
    if (project_name) project_name.value = isNew ? '' : (rowData.project_name || '');
    if (client_name) client_name.value = isNew ? '' : (rowData.client_name || '');
    if (supply_krw) supply_krw.value = isNew ? '' : (rowData.supply_krw ? fmt(rowData.supply_krw) : '');
    if (list_value_krw) list_value_krw.value = isNew ? '' : (rowData.list_value_krw ? fmt(rowData.list_value_krw) : '');
    if (assignee) assignee.value = isNew ? '' : (rowData.assignee || '');
    if (note) note.value = isNew ? '' : (rowData.note || '');

    /* 서비스구분 */
    var svcLabel = svcCat || '선택';
    setSelectValue('rev-m-service_category-wrap', 'rev-m-service_category', svcCat, svcLabel);

    /* 유상/무상 */
    setSelectValue('rev-m-billing_type-wrap', 'rev-m-billing_type', billing, billing === 'FREE' ? '무상' : '유상');

    /* 상태 */
    setSelectValue('rev-m-status-wrap', 'rev-m-status', status, status === 'PENDING' ? '미확인' : '완료');

    /* 공급가/정상가 가시성 */
    applyBillingVisibility(billing);

    /* 증빙 섹션 초기화 */
    var receipts = modalEl('rev-m-receipts');
    if (receipts) {
      if (isNew) {
        receipts.setAttribute('data-receipts-disabled', '1');
        receipts.title = '저장 후 첨부 가능합니다.';
        initRevReceiptsSection(receipts, false);
      } else {
        receipts.removeAttribute('data-receipts-disabled');
        receipts.title = '';
        initRevReceiptsSection(receipts, true);
        loadRevFiles();
      }
    }

    /* 모달 표시 */
    revModal.hidden = false;
    document.body.classList.add('rev-modal-open');

    /* datepicker 초기화 */
    if (window.TechBizBoardDatepicker && received_date) {
      window.TechBizBoardDatepicker.day(received_date);
    }

    /* 디자인 시스템 select 초기화 */
    if (window.DesignSystem && typeof window.DesignSystem.initSelects === 'function') {
      window.DesignSystem.initSelects(revModal);
    }

    /* 포커스 */
    if (project_name) project_name.focus();
  }

  function closeRevModal() {
    if (!revModal) return;
    revModal.hidden = true;
    document.body.classList.remove('rev-modal-open');
    // 증빙이 추가/삭제됐으면 목록의 📎 개수를 갱신한다.
    if (revReceiptsDirty) {
      revReceiptsDirty = false;
      loadList();
    }
  }

  function upsertListRow(row) {
    if (!listBody) return;
    var existing = listBody.querySelector('tr.rev-row[data-id="' + escapeAttr(String(row.id)) + '"]');
    var html = listRowHtml(row);
    if (existing) {
      var tmp = document.createElement('tbody');
      tmp.innerHTML = html;
      var newTr = tmp.querySelector('tr');
      if (newTr) existing.replaceWith(newTr);
    } else {
      listBody.insertAdjacentHTML('afterbegin', html);
    }
    updateListSummary();
    refreshYearIndex();
  }

  function saveRevModal() {
    if (!revModal) return;
    var editId = revModal.dataset.editId || '';
    var isNew = !editId;

    var received_date = (modalEl('rev-m-received_date') || {}).value || '';
    var project_name = ((modalEl('rev-m-project_name') || {}).value || '').trim();
    var client_name = ((modalEl('rev-m-client_name') || {}).value || '').trim();
    var service_category = (modalEl('rev-m-service_category') || {}).value || '';
    var billing_type = (modalEl('rev-m-billing_type') || {}).value || 'PAID';
    var supply_krw = toInt((modalEl('rev-m-supply_krw') || {}).value || '');
    var list_value_krw = toInt((modalEl('rev-m-list_value_krw') || {}).value || '');
    var assignee = ((modalEl('rev-m-assignee') || {}).value || '').trim();
    var status = (modalEl('rev-m-status') || {}).value || 'COMPLETED';
    var note = ((modalEl('rev-m-note') || {}).value || '').trim();

    if (!project_name || !received_date || !assignee) {
      toast('저장 안 됨', '프로젝트, 입금일, 담당을 입력해 주세요.', 'danger');
      return;
    }
    if (billing_type !== 'FREE' && supply_krw === 0) {
      toast('저장 안 됨', '공급가를 입력해 주세요.', 'danger');
      return;
    }

    var vat_krw = billing_type === 'FREE' ? 0 : Math.round(supply_krw * 0.1);
    var amount_krw = billing_type === 'FREE' ? 0 : (supply_krw + vat_krw);

    var payload = {
      action: isNew ? 'create' : 'update',
      id: editId,
      received_date: received_date,
      project_name: project_name,
      client_name: client_name,
      service_category: service_category,
      billing_type: billing_type,
      supply_krw: supply_krw,
      list_value_krw: list_value_krw,
      vat_krw: vat_krw,
      amount_krw: amount_krw,
      assignee: assignee,
      status: status,
      note: note
    };

    var saveBtn = modalEl('rev-m-save');
    if (saveBtn) saveBtn.disabled = true;

    post(apiUrl, payload)
      .then(function (data) {
        var savedRow = data.row || {};
        /* 새로 저장된 row에 로컬 필드 보강(API가 전부 돌려주지 않을 수 있음) */
        if (!savedRow.received_date) savedRow.received_date = received_date;
        if (!savedRow.project_name) savedRow.project_name = project_name;
        if (!savedRow.client_name) savedRow.client_name = client_name;
        savedRow.service_category = savedRow.service_category || service_category;
        savedRow.billing_type = savedRow.billing_type || billing_type;
        if (savedRow.supply_krw == null) savedRow.supply_krw = supply_krw;
        if (savedRow.list_value_krw == null) savedRow.list_value_krw = list_value_krw;
        if (savedRow.vat_krw == null) savedRow.vat_krw = vat_krw;
        if (savedRow.amount_krw == null) savedRow.amount_krw = amount_krw;
        if (!savedRow.assignee) savedRow.assignee = assignee;
        savedRow.status = savedRow.status || status;
        savedRow.note = savedRow.note != null ? savedRow.note : note;

        upsertListRow(savedRow);

        if (isNew && savedRow.id) {
          /* 신규: 모달을 편집 모드로 전환(증빙 섹션 활성화) */
          revModal.dataset.editId = String(savedRow.id);
          var titleEl = modalEl('rev-m-title');
          if (titleEl) titleEl.textContent = '수입 편집';
          enableRevReceipts();
          toast('저장됨', '수입을 저장했습니다. 증빙을 첨부할 수 있습니다.', 'success');
        } else {
          toast('저장됨', '수입을 저장했습니다.', 'success');
          closeRevModal();
        }
      })
      .catch(function (err) {
        toast('저장 실패', err.message, 'danger');
      })
      .finally(function () {
        if (saveBtn) saveBtn.disabled = false;
      });
  }

  /* ════════════════════════════════════════
     증빙 섹션 (revenue_file)
  ════════════════════════════════════════ */

  var REV_FILE_API = 'api/revenue_file';
  // 모달에서 증빙을 올리거나 지우면 목록의 📎 개수가 바뀌므로, 모달을 닫을 때 목록을 새로고침한다.
  var revReceiptsDirty = false;

  var DOWNLOAD_ICON =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' +
    '<path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>';

  function currentRevId() {
    return (revModal && revModal.dataset.editId) ? String(revModal.dataset.editId).trim() : '';
  }

  function renderRevFiles(container, files) {
    container.innerHTML = '';
    if (!files.length) {
      var empty = document.createElement('p');
      empty.className = 'rev-receipts-empty';
      empty.textContent = '첨부된 증빙이 없습니다.';
      container.appendChild(empty);
      return;
    }
    var ul = document.createElement('ul');
    ul.className = 'rev-receipts-list';
    files.forEach(function (f) {
      var li = document.createElement('li');
      li.className = 'rev-receipts-item';

      var a = document.createElement('a');
      a.className = 'rev-receipts-name';
      a.href = REV_FILE_API + '?action=download&id=' + encodeURIComponent(f.id);
      a.textContent = f.display_name || f.original_name || String(f.id);
      a.setAttribute('aria-label', (f.display_name || f.original_name || '') + ' 다운로드');
      li.appendChild(a);

      if (f.size_bytes) {
        var meta = document.createElement('span');
        meta.className = 'rev-receipts-meta';
        var n = Number(f.size_bytes) || 0;
        meta.textContent = n >= 1048576 ? (n / 1048576).toFixed(1) + 'MB' :
                           (n >= 1024 ? Math.round(n / 1024) + 'KB' : n + 'B');
        li.appendChild(meta);
      }

      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-ghost btn-icon btn-sm rev-receipts-del';
      del.setAttribute('aria-label', '삭제');
      del.innerHTML = TRASH_ICON;
      del.addEventListener('click', function () {
        if (!window.confirm('이 증빙 파일을 삭제할까요?')) return;
        deleteRevFile(f.id);
      });
      li.appendChild(del);

      ul.appendChild(li);
    });
    container.appendChild(ul);
  }

  function loadRevFiles() {
    var revId = currentRevId();
    var receipts = modalEl('rev-m-receipts');
    if (!receipts || !revId) return;
    var listEl = receipts.querySelector('.rev-receipts-file-list');
    if (!listEl) return;
    fetch(REV_FILE_API + '?action=list&revenue_id=' + encodeURIComponent(revId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        renderRevFiles(listEl, (data && data.files) || []);
      })
      .catch(function () { /* 목록 조회 실패는 조용히 */ });
  }

  function deleteRevFile(fid) {
    fetch(REV_FILE_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'delete', id: fid, csrf: csrf })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || '삭제에 실패했습니다.');
        toast('삭제됨', '증빙 파일을 삭제했습니다.', 'success');
        revReceiptsDirty = true;
        loadRevFiles();
      })
      .catch(function (err) { toast('삭제 실패', err.message || '', 'danger'); });
  }

  function uploadRevFile(file) {
    var revId = currentRevId();
    if (!revId || !file) return;
    var fd = new FormData();
    fd.append('file', file);
    fd.append('revenue_id', revId);
    fd.append('csrf', csrf);
    var receipts = modalEl('rev-m-receipts');
    var progress = receipts ? receipts.querySelector('.rev-receipts-progress') : null;
    if (progress) progress.hidden = false;
    fetch(REV_FILE_API, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || '업로드에 실패했습니다.');
        toast('첨부됨', escapeHtml(file.name) + ' 을 첨부했습니다.', 'success');
        revReceiptsDirty = true;
        loadRevFiles();
      })
      .catch(function (err) { toast('업로드 실패', err.message || '', 'danger'); })
      .finally(function () { if (progress) progress.hidden = true; });
  }

  function initRevReceiptsSection(receipts, enabled) {
    receipts.innerHTML = '';
    if (!enabled) {
      var hint = document.createElement('p');
      hint.className = 'rev-receipts-hint';
      hint.textContent = '저장 후 증빙을 첨부할 수 있습니다.';
      receipts.appendChild(hint);
      return;
    }

    /* 드롭존 */
    var dropzone = document.createElement('div');
    dropzone.className = 'rev-receipts-dropzone';
    dropzone.setAttribute('role', 'button');
    dropzone.setAttribute('tabindex', '0');
    dropzone.setAttribute('aria-label', '증빙 파일 선택 또는 드래그');

    var fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.multiple = true;
    fileInput.className = 'rev-receipts-input';
    fileInput.setAttribute('aria-label', '증빙 파일 선택');
    fileInput.style.display = 'none';

    var dzLabel = document.createElement('span');
    dzLabel.className = 'rev-receipts-dz-label';
    dzLabel.textContent = '파일을 드래그하거나 클릭해서 선택';

    dropzone.appendChild(fileInput);
    dropzone.appendChild(dzLabel);

    dropzone.addEventListener('click', function () { fileInput.click(); });
    dropzone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });
    dropzone.addEventListener('dragover', function (e) {
      e.preventDefault();
      dropzone.classList.add('is-dragover');
    });
    dropzone.addEventListener('dragleave', function () {
      dropzone.classList.remove('is-dragover');
    });
    dropzone.addEventListener('drop', function (e) {
      e.preventDefault();
      dropzone.classList.remove('is-dragover');
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files) return;
      for (var i = 0; i < files.length; i++) { uploadRevFile(files[i]); }
    });
    fileInput.addEventListener('change', function () {
      if (!fileInput.files) return;
      for (var i = 0; i < fileInput.files.length; i++) { uploadRevFile(fileInput.files[i]); }
      fileInput.value = '';
    });

    /* 진행 표시 */
    var progress = document.createElement('p');
    progress.className = 'rev-receipts-progress';
    progress.textContent = '업로드 중...';
    progress.hidden = true;

    /* 파일 목록 컨테이너 */
    var listEl = document.createElement('div');
    listEl.className = 'rev-receipts-file-list';

    receipts.appendChild(dropzone);
    receipts.appendChild(progress);
    receipts.appendChild(listEl);
  }

  function enableRevReceipts() {
    var receipts = modalEl('rev-m-receipts');
    if (!receipts) return;
    receipts.removeAttribute('data-receipts-disabled');
    receipts.title = '';
    initRevReceiptsSection(receipts, true);
    loadRevFiles();
  }

  /* ════════════════════════════════════════
     이벤트 위임
  ════════════════════════════════════════ */

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
      if (isYearView()) {
        toast('달을 고르세요', '연간 보기에서는 줄을 추가할 수 없습니다.', 'danger');
        return;
      }
      if (isViewLocked()) {
        toast('수정할 수 없습니다', '전전월 이전은 볼 수만 있습니다.', 'danger');
        return;
      }
      openRevModal(null);
      return;
    }
    var addTpl = e.target.closest('[data-action="add-tpl"]');
    if (addTpl) {
      addTplRow();
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
      return;
    }
    /* 행 클릭 → 모달 열기 */
    var revRowClick = e.target.closest('tr.rev-row.is-click');
    if (revRowClick && listBody && listBody.contains(revRowClick)) {
      /* 삭제 버튼 영역 클릭 시는 모달 열지 않음 */
      if (e.target.closest('[data-action="delete-row"]')) return;
      if (isViewLocked()) return;
      openRevModal(rowDataFromTr(revRowClick));
      return;
    }
  });

  /* 모달 버튼 이벤트 */
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-action="close-rev-modal"]')) {
      closeRevModal();
      return;
    }
    if (e.target.closest('#rev-m-save')) {
      saveRevModal();
      return;
    }
    /* 백드롭 클릭 닫기 */
    if (revModal && !revModal.hidden && e.target === revModal) {
      closeRevModal();
    }
  });

  /* 모달 내 유상/무상 select 실시간 토글 */
  document.addEventListener('select:change', function (e) {
    if (!revModal || revModal.hidden) return;
    if (!e.target.closest('#rev-m-billing_type-wrap')) return;
    var billing = (modalEl('rev-m-billing_type') || {}).value || 'PAID';
    applyBillingVisibility(billing);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && revModal && !revModal.hidden) {
      closeRevModal();
    }
  });

  root.addEventListener('focusout', function (e) {
    var tr = e.target.closest('tr.rev-row');
    if (!tr) return;
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
