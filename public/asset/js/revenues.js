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
  var tplModal = document.getElementById('rev-tpl-modal');
  var tplLoaded = false;

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

  function deleteBtn(action) {
    return '<button class="btn btn-outline btn-sm" type="button" data-action="' + escapeAttr(action) + '" aria-label="삭제">' +
      TRASH_ICON + '</button>';
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
      supply += toInt(tr.getAttribute('data-supply') || '');
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
      ? '<span class="rev-free-label">무상(총액 ' + fmt(listKrw) + ')</span>'
      : fmt(amountKrw);
    return (
      '<tr class="rev-row is-click" data-id="' + escapeAttr(id) + '"' + (id ? '' : ' data-draft="1"') +
        ' data-supply="' + escapeAttr(String(supplyKrw)) + '"' +
        ' data-vat="' + escapeAttr(String(vatKrw)) + '"' +
        ' data-amount="' + escapeAttr(String(amountKrw)) + '"' +
        ' data-list-value="' + escapeAttr(String(listKrw)) + '"' +
        ' data-status="' + escapeAttr(row.status || 'COMPLETED') + '"' +
        ' data-note="' + escapeAttr(row.note || '') + '"' +
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
      note: tr.getAttribute('data-note') || '',
      created_by: tr.getAttribute('data-created-by') || '',
      revenue_template_id: null
    };
  }

  function tplRowHtml(row) {
    var id = row.id ? String(row.id) : '';
    var active = row.is_active === undefined ? true : Number(row.is_active) === 1;
    var supplyKrw = Number(row.supply_krw || 0);
    var listKrw = Number(row.list_value_krw || 0);
    var billing = row.billing_type === 'FREE' ? 'FREE' : 'PAID';
    var moneyDisplay = billing === 'FREE'
      ? '<span class="rev-free-label">무상(총액 ' + fmt(listKrw) + ')</span>'
      : fmt(supplyKrw);
    return (
      '<tr class="rev-row is-click" data-id="' + escapeAttr(id) + '"' +
        ' data-supply="' + escapeAttr(String(supplyKrw)) + '"' +
        ' data-vat="' + escapeAttr(String(Number(row.vat_krw || 0))) + '"' +
        ' data-amount="' + escapeAttr(String(Number(row.amount_krw || 0))) + '"' +
        ' data-list-value="' + escapeAttr(String(listKrw)) + '"' +
        ' data-service="' + escapeAttr(row.service_category || '') + '"' +
        ' data-billing="' + escapeAttr(billing) + '"' +
        ' data-start="' + escapeAttr(row.start_year_month || '') + '"' +
        ' data-end="' + escapeAttr(row.end_year_month || '') + '"' +
        ' data-active="' + (active ? '1' : '0') + '"' +
        ' data-note="' + escapeAttr(row.note || '') + '"' +
        ' data-created-by="' + escapeAttr(row.created_by || '') + '">' +
        '<td class="col-project">' + escapeHtml(row.project_name || '') + '</td>' +
        '<td class="col-client">' + escapeHtml(row.client_name || '') + '</td>' +
        '<td class="num col-money">' + moneyDisplay + '</td>' +
        '<td class="col-assignee">' + escapeHtml(row.assignee || '') + '</td>' +
        '<td class="col-author"><span class="rev-author">' + escapeHtml(row.created_by || '') + '</span></td>' +
        '<td class="col-period">' + escapeHtml(periodLabel(row.start_year_month, row.end_year_month) || '—') + '</td>' +
        '<td class="col-active">' + (active ? '<span class="badge badge-brand">사용</span>' : '<span class="badge badge-muted">중지</span>') + '</td>' +
        '<td class="col-note">' + escapeHtml(row.note || '') + '</td>' +
        '<td class="col-actions">' + deleteBtn('delete-tpl') + '</td>' +
      '</tr>'
    );
  }

  /* ── 반복 설정 행의 표시 데이터를 row 객체로 추출 ── */
  function tplDataFromTr(tr) {
    return {
      id: tr.getAttribute('data-id') || '',
      project_name: tr.querySelector('.col-project') ? tr.querySelector('.col-project').textContent.trim() : '',
      client_name: tr.querySelector('.col-client') ? tr.querySelector('.col-client').textContent.trim() : '',
      service_category: tr.getAttribute('data-service') || '',
      billing_type: tr.getAttribute('data-billing') === 'FREE' ? 'FREE' : 'PAID',
      supply_krw: toInt(tr.getAttribute('data-supply') || ''),
      vat_krw: toInt(tr.getAttribute('data-vat') || ''),
      amount_krw: toInt(tr.getAttribute('data-amount') || ''),
      list_value_krw: toInt(tr.getAttribute('data-list-value') || ''),
      assignee: tr.querySelector('.col-assignee') ? tr.querySelector('.col-assignee').textContent.trim() : '',
      start_year_month: tr.getAttribute('data-start') || '',
      end_year_month: tr.getAttribute('data-end') || '',
      is_active: tr.getAttribute('data-active') === '0' ? 0 : 1,
      note: tr.getAttribute('data-note') || '',
      created_by: tr.getAttribute('data-created-by') || ''
    };
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
    updateTplSummary();
  }

  function upsertTplRow(row) {
    if (!tplBody) return;
    var existing = tplBody.querySelector('tr.rev-row[data-id="' + escapeAttr(String(row.id)) + '"]');
    var html = tplRowHtml(row);
    if (existing) {
      var tmp = document.createElement('tbody');
      tmp.innerHTML = html;
      var newTr = tmp.querySelector('tr');
      if (newTr) existing.replaceWith(newTr);
    } else {
      tplBody.insertAdjacentHTML('afterbegin', html);
    }
    updateTplSummary();
  }

  /* ── 반복 설정 모달 열기 / 닫기 / 저장 ── */
  function openTplModal(rowData) {
    if (!tplModal) return;
    var isNew = !rowData || !rowData.id;
    var titleEl = document.getElementById('rev-t-title');
    if (titleEl) titleEl.textContent = isNew ? '반복 설정 등록' : '반복 설정 편집';
    tplModal.dataset.editId = isNew ? '' : String(rowData.id);

    var project = document.getElementById('rev-t-project_name');
    var client = document.getElementById('rev-t-client_name');
    var supply = document.getElementById('rev-t-supply_krw');
    var vat = document.getElementById('rev-t-vat_krw');
    var amount = document.getElementById('rev-t-amount_krw');
    var assignee = document.getElementById('rev-t-assignee');
    var period = document.getElementById('rev-t-period');
    var active = document.getElementById('rev-t-is_active');
    var note = document.getElementById('rev-t-note');

    var billing = (rowData && rowData.billing_type) ? rowData.billing_type : 'PAID';
    var svcCat = (rowData && rowData.service_category) ? rowData.service_category : '';

    var now = new Date();
    var defStart = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');

    /* 금액: 무상은 총액 칸에 무상 가치(list_value)를 보여주고, 레거시 유상 템플릿(총액 0)은 공급가로 자동 채운다 */
    var sVal = (!isNew && rowData.supply_krw) ? Number(rowData.supply_krw) : 0;
    var vVal = (!isNew && rowData.vat_krw) ? Number(rowData.vat_krw) : 0;
    var aVal = (!isNew && rowData.amount_krw) ? Number(rowData.amount_krw) : 0;
    if (billing === 'FREE') {
      aVal = (!isNew && rowData.list_value_krw) ? Number(rowData.list_value_krw) : 0;
      sVal = 0;
      vVal = 0;
    } else if (sVal && !aVal) {
      vVal = Math.round(sVal * 0.1);
      aVal = sVal + vVal;
    }

    if (project) project.value = isNew ? '' : (rowData.project_name || '');
    if (client) client.value = isNew ? '' : (rowData.client_name || '');
    if (supply) supply.value = sVal ? fmt(sVal) : '';
    if (vat) vat.value = vVal ? fmt(vVal) : '';
    if (amount) amount.value = aVal ? fmt(aVal) : '';
    if (assignee) assignee.value = isNew ? '' : (rowData.assignee || '');
    if (period) period.value = isNew ? defStart : periodLabel(rowData.start_year_month, rowData.end_year_month);
    if (active) active.checked = isNew ? true : Number(rowData.is_active) === 1;
    if (note) note.value = isNew ? '' : (rowData.note || '');

    /* 서비스구분 */
    setSelectValue('rev-t-service_category-wrap', 'rev-t-service_category', svcCat, svcCat || '선택');
    /* 유상/무상 */
    setSelectValue('rev-t-billing_type-wrap', 'rev-t-billing_type', billing, billing === 'FREE' ? '무상' : '유상');

    tplModal.hidden = false;
    document.body.classList.add('rev-modal-open');

    if (window.TechBizBoardDatepicker && period) {
      window.TechBizBoardDatepicker.monthRange(period);
    }
    if (window.DesignSystem && typeof window.DesignSystem.initSelects === 'function') {
      window.DesignSystem.initSelects(tplModal);
    }
    if (project) project.focus();
  }

  /* 금액 자동계산: 공급가 입력 → 부가세(10%)·총액, 부가세 입력 → 총액. 총액은 수동 override */
  function recalcMoney(prefix, changed) {
    var sEl = document.getElementById(prefix + '-supply_krw');
    var vEl = document.getElementById(prefix + '-vat_krw');
    var aEl = document.getElementById(prefix + '-amount_krw');
    if (!sEl || !vEl || !aEl) return;
    var supply = toInt(sEl.value);
    if (changed === 'supply') {
      var vat = Math.round(supply * 0.1);
      vEl.value = supply ? fmt(vat) : '';
      aEl.value = supply ? fmt(supply + vat) : '';
    } else if (changed === 'vat') {
      aEl.value = fmt(supply + toInt(vEl.value));
    }
  }

  function closeTplModal() {
    if (!tplModal) return;
    tplModal.hidden = true;
    document.body.classList.remove('rev-modal-open');
  }

  function saveTplModal() {
    if (!tplModal) return;
    var editId = tplModal.dataset.editId || '';
    var isNew = !editId;

    var project_name = ((document.getElementById('rev-t-project_name') || {}).value || '').trim();
    var client_name = ((document.getElementById('rev-t-client_name') || {}).value || '').trim();
    var service_category = (document.getElementById('rev-t-service_category') || {}).value || '';
    var billing_type = (document.getElementById('rev-t-billing_type') || {}).value || 'PAID';
    var supply_krw = toInt((document.getElementById('rev-t-supply_krw') || {}).value || '');
    var vat_krw = toInt((document.getElementById('rev-t-vat_krw') || {}).value || '');
    var amount_krw = toInt((document.getElementById('rev-t-amount_krw') || {}).value || '');
    var assignee = ((document.getElementById('rev-t-assignee') || {}).value || '').trim();
    var period = parsePeriod((document.getElementById('rev-t-period') || {}).value || '');
    var activeEl = document.getElementById('rev-t-is_active');
    var is_active = (activeEl && activeEl.checked) ? 1 : 0;
    var note = ((document.getElementById('rev-t-note') || {}).value || '').trim();

    if (!period.start || !project_name || !client_name || !service_category || !assignee) {
      toast('저장 안 됨', '메모를 제외한 모든 항목을 입력해 주세요.', 'danger');
      return;
    }
    if (billing_type !== 'FREE' && amount_krw <= 0) {
      toast('저장 안 됨', '총액을 입력해 주세요.', 'danger');
      return;
    }

    var payload = {
      action: isNew ? 'create' : 'update',
      id: editId,
      project_name: project_name,
      client_name: client_name,
      service_category: service_category,
      billing_type: billing_type,
      assignee: assignee,
      start_year_month: period.start,
      end_year_month: period.end,
      supply_krw: supply_krw,
      vat_krw: vat_krw,
      amount_krw: amount_krw,
      note: note,
      is_active: is_active
    };

    var saveBtnEl = document.getElementById('rev-t-save');
    if (saveBtnEl) saveBtnEl.disabled = true;

    post(tplApi, payload)
      .then(function (data) {
        var savedRow = data.row || {};
        if (!savedRow.project_name) savedRow.project_name = project_name;
        savedRow.client_name = savedRow.client_name != null ? savedRow.client_name : client_name;
        savedRow.service_category = savedRow.service_category || service_category;
        savedRow.billing_type = savedRow.billing_type || billing_type;
        if (!savedRow.assignee) savedRow.assignee = assignee;
        if (savedRow.supply_krw == null) savedRow.supply_krw = supply_krw;
        if (savedRow.vat_krw == null) savedRow.vat_krw = vat_krw;
        if (savedRow.amount_krw == null) savedRow.amount_krw = amount_krw;
        if (savedRow.list_value_krw == null) savedRow.list_value_krw = (billing_type === 'FREE' ? amount_krw : 0);
        savedRow.start_year_month = savedRow.start_year_month || period.start;
        savedRow.end_year_month = savedRow.end_year_month != null ? savedRow.end_year_month : period.end;
        if (savedRow.is_active == null) savedRow.is_active = is_active;
        savedRow.note = savedRow.note != null ? savedRow.note : note;
        upsertTplRow(savedRow);
        toast('저장됨', '반복 설정을 저장했습니다.', 'success');
        closeTplModal();
      })
      .catch(function (err) {
        toast('저장 실패', err.message, 'danger');
      })
      .finally(function () {
        if (saveBtnEl) saveBtnEl.disabled = false;
      });
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
    var vat_krw = modalEl('rev-m-vat_krw');
    var amount_krw = modalEl('rev-m-amount_krw');
    var assignee = modalEl('rev-m-assignee');
    var note = modalEl('rev-m-note');

    var billing = (rowData && rowData.billing_type) ? rowData.billing_type : 'PAID';
    var status = (rowData && rowData.status) ? rowData.status : 'COMPLETED';
    var svcCat = (rowData && rowData.service_category) ? rowData.service_category : '';

    /* 금액: 무상은 총액 칸에 무상 가치(list_value)를 보여주고, 레거시 유상행(총액 0)은 공급가로 자동 채운다 */
    var sVal = (!isNew && rowData.supply_krw) ? Number(rowData.supply_krw) : 0;
    var vVal = (!isNew && rowData.vat_krw) ? Number(rowData.vat_krw) : 0;
    var aVal = (!isNew && rowData.amount_krw) ? Number(rowData.amount_krw) : 0;
    if (billing === 'FREE') {
      aVal = (!isNew && rowData.list_value_krw) ? Number(rowData.list_value_krw) : 0;
      sVal = 0;
      vVal = 0;
    } else if (sVal && !aVal) {
      vVal = Math.round(sVal * 0.1);
      aVal = sVal + vVal;
    }

    if (received_date) received_date.value = isNew ? '' : (rowData.received_date || '');
    if (project_name) project_name.value = isNew ? '' : (rowData.project_name || '');
    if (client_name) client_name.value = isNew ? '' : (rowData.client_name || '');
    if (supply_krw) supply_krw.value = sVal ? fmt(sVal) : '';
    if (vat_krw) vat_krw.value = vVal ? fmt(vVal) : '';
    if (amount_krw) amount_krw.value = aVal ? fmt(aVal) : '';
    if (assignee) assignee.value = isNew ? '' : (rowData.assignee || '');
    if (note) note.value = isNew ? '' : (rowData.note || '');

    /* 서비스구분 */
    var svcLabel = svcCat || '선택';
    setSelectValue('rev-m-service_category-wrap', 'rev-m-service_category', svcCat, svcLabel);

    /* 유상/무상 */
    setSelectValue('rev-m-billing_type-wrap', 'rev-m-billing_type', billing, billing === 'FREE' ? '무상' : '유상');

    /* 상태 */
    setSelectValue('rev-m-status-wrap', 'rev-m-status', status, status === 'PENDING' ? '미확인' : '완료');

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
    var vat_krw = toInt((modalEl('rev-m-vat_krw') || {}).value || '');
    var amount_krw = toInt((modalEl('rev-m-amount_krw') || {}).value || '');
    var assignee = ((modalEl('rev-m-assignee') || {}).value || '').trim();
    var status = (modalEl('rev-m-status') || {}).value || 'COMPLETED';
    var note = ((modalEl('rev-m-note') || {}).value || '').trim();

    if (!received_date || !project_name || !client_name || !service_category || !assignee) {
      toast('저장 안 됨', '메모를 제외한 모든 항목을 입력해 주세요.', 'danger');
      return;
    }
    if (billing_type !== 'FREE' && amount_krw <= 0) {
      toast('저장 안 됨', '총액을 입력해 주세요.', 'danger');
      return;
    }

    var payload = {
      action: isNew ? 'create' : 'update',
      id: editId,
      received_date: received_date,
      project_name: project_name,
      client_name: client_name,
      service_category: service_category,
      billing_type: billing_type,
      supply_krw: supply_krw,
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
        if (savedRow.vat_krw == null) savedRow.vat_krw = vat_krw;
        if (savedRow.amount_krw == null) savedRow.amount_krw = amount_krw;
        // 무상 가치(list_value_krw)는 서버가 총액에서 환산해 돌려준다.
        if (savedRow.list_value_krw == null) savedRow.list_value_krw = (billing_type === 'FREE' ? amount_krw : 0);
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

  // 브라우저에서 바로 미리보기 가능한 타입 (PDF·이미지). xlsx 등은 다운로드만.
  var PREVIEWABLE = { 'application/pdf': 1, 'image/jpeg': 1, 'image/png': 1, 'image/webp': 1 };
  function isPreviewable(mime) { return !!PREVIEWABLE[String(mime || '').toLowerCase()]; }

  function closeRevViewer() {
    var overlay = document.getElementById('rev-viewer');
    if (!overlay) return;
    overlay.hidden = true;
    var body = overlay.querySelector('.rev-viewer-body');
    if (body) body.innerHTML = ''; // 로딩 중단·메모리 해제
    document.body.classList.remove('rev-viewer-open');
  }

  function openRevViewer(f) {
    var overlay = document.getElementById('rev-viewer');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'rev-viewer';
      overlay.className = 'rev-viewer';
      overlay.hidden = true;
      overlay.innerHTML =
        '<div class="rev-viewer-box" role="dialog" aria-modal="true" aria-label="증빙 미리보기">' +
          '<div class="rev-viewer-head">' +
            '<span class="rev-viewer-name"></span>' +
            '<span class="rev-viewer-actions">' +
              '<a class="btn btn-outline btn-sm rev-viewer-dl" rel="noopener">다운로드</a>' +
              '<button type="button" class="btn btn-ghost btn-icon btn-sm rev-viewer-close" aria-label="닫기">✕</button>' +
            '</span>' +
          '</div>' +
          '<div class="rev-viewer-body"></div>' +
        '</div>';
      document.body.appendChild(overlay);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) closeRevViewer(); });
      overlay.querySelector('.rev-viewer-close').addEventListener('click', closeRevViewer);
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) closeRevViewer();
      });
    }
    var viewUrl = REV_FILE_API + '?action=view&id=' + encodeURIComponent(f.id);
    overlay.querySelector('.rev-viewer-name').textContent = f.display_name || '증빙';
    overlay.querySelector('.rev-viewer-dl').href = REV_FILE_API + '?action=download&id=' + encodeURIComponent(f.id);
    var body = overlay.querySelector('.rev-viewer-body');
    body.innerHTML = '';
    if (String(f.mime || '').toLowerCase() === 'application/pdf') {
      var iframe = document.createElement('iframe');
      iframe.className = 'rev-viewer-frame';
      iframe.src = viewUrl;
      iframe.title = f.display_name || '증빙';
      body.appendChild(iframe);
    } else {
      var img = document.createElement('img');
      img.className = 'rev-viewer-img';
      img.src = viewUrl;
      img.alt = f.display_name || '증빙';
      body.appendChild(img);
    }
    overlay.hidden = false;
    document.body.classList.add('rev-viewer-open');
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

      if (isPreviewable(f.mime)) {
        var view = document.createElement('button');
        view.type = 'button';
        view.className = 'btn btn-outline btn-sm rev-receipts-view';
        view.textContent = '보기';
        view.addEventListener('click', function () { openRevViewer(f); });
        li.appendChild(view);
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
      openTplModal(null);
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
    /* 반복 설정 행 클릭 → 반복 모달 열기 */
    if (revRowClick && tplBody && tplBody.contains(revRowClick)) {
      if (e.target.closest('[data-action="delete-tpl"]')) return;
      openTplModal(tplDataFromTr(revRowClick));
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
    if (e.target.closest('[data-action="close-tpl-modal"]')) {
      closeTplModal();
      return;
    }
    if (e.target.closest('#rev-t-save')) {
      saveTplModal();
      return;
    }
    /* 백드롭 클릭 닫기 */
    if (revModal && !revModal.hidden && e.target === revModal) {
      closeRevModal();
    }
    if (tplModal && !tplModal.hidden && e.target === tplModal) {
      closeTplModal();
    }
  });

  /* 금액 칸 입력 시 자동계산 */
  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || typeof el.getAttribute !== 'function') return;
    var kind = el.getAttribute('data-money');
    if (!kind) return;
    if (revModal && !revModal.hidden && revModal.contains(el)) {
      recalcMoney('rev-m', kind);
    } else if (tplModal && !tplModal.hidden && tplModal.contains(el)) {
      recalcMoney('rev-t', kind);
    }
  });

  /* 금액 칸 포커스아웃 시 천단위 콤마로 정리 */
  document.addEventListener('focusout', function (e) {
    var el = e.target;
    if (!el || typeof el.getAttribute !== 'function') return;
    if (!el.getAttribute('data-money')) return;
    var inModal = (revModal && !revModal.hidden && revModal.contains(el)) ||
                  (tplModal && !tplModal.hidden && tplModal.contains(el));
    if (!inModal) return;
    el.value = String(el.value).trim() ? fmt(toInt(el.value)) : '';
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (revModal && !revModal.hidden) closeRevModal();
    if (tplModal && !tplModal.hidden) closeTplModal();
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
