/* 기안작성: 결제 항목 입력 후 최종 본문 생성 */
(function () {
  'use strict';

  var root = document.querySelector('[data-drafts]');
  if (!root) return;

  var apiUrl = root.getAttribute('data-api') || 'api/drafts';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var month = root.getAttribute('data-month') || '';
  // 전전월 이전은 마감(admin 은 빈 값이라 항상 편집 가능).
  var editableFrom = root.getAttribute('data-editable-from') || '';
  var monthLocked = editableFrom !== '' && month !== '' && month < editableFrom;
  var idle = document.getElementById('draft-idle');
  var compose = document.getElementById('draft-compose');
  var saveBtn = document.getElementById('draft-save');
  var doneBtn = document.getElementById('draft-done');
  var reopenBtn = document.getElementById('draft-reopen');
  var filter = document.getElementById('draft-filter');
  var filterEmpty = document.getElementById('draft-filter-empty');
  var monthInput = document.getElementById('draft-month');
  var itemRows = document.getElementById('draft-item-rows');
  var itemTpl = document.getElementById('draft-item-row-tpl');
  var itemSum = document.getElementById('draft-item-sum');
  var TEAM_NOTE_DEFAULT = '팀비';

  function syncItemHost() {
    itemRows = (compose && compose.classList.contains('is-team'))
      ? document.getElementById('draft-team-rows')
      : document.getElementById('draft-item-rows');
    return itemRows;
  }

  var templates = [];
  var drafts = [];
  try { templates = JSON.parse((document.getElementById('draft-templates') || {}).textContent || '[]') || []; } catch (e) { templates = []; }
  try { drafts = JSON.parse((document.getElementById('draft-store') || {}).textContent || '[]') || []; } catch (e) { drafts = []; }

  /* ── 영수증 업로드 헬퍼 ────────────────────────────────────────────── */
  function sanitizeName(s) {
    return String(s).replace(/[\/\\:*?"<>|]/g, '_').replace(/\s+/g, ' ')
      .replace(/_{2,}/g, '_').replace(/^_+|_+$/g, '').trim();
  }
  function resolvePattern(pattern, tokens) {
    var p = pattern && String(pattern).trim() ? String(pattern) : '{year_month}_{user}_{title}';
    return p.replace(/\{(\w+)\}/g, function (_, k) { return tokens[k] != null ? String(tokens[k]) : ''; });
  }
  function extOf(name) { var d = String(name).lastIndexOf('.'); return d > -1 ? String(name).slice(d) : ''; }
  function uniqueName(base, ext, used) {
    var name = base + ext, n = 1;
    while (used[name]) { name = base + '_' + n + ext; n++; }
    used[name] = true;
    return name;
  }
  function downloadRenamed(file, newName) {
    var url = URL.createObjectURL(file);
    var a = document.createElement('a');
    a.href = url; a.download = newName;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }
  /* ─────────────────────────────────────────────────────────────────── */

  var previewTimer = null;
  var previewSeq = 0;
  var fillingRows = false;
  var bodyDirty = false;
  var titleDirty = false;

  function toast(title, body, kind) {
    if (typeof tbbToast === 'function') tbbToast(title, body, kind);
  }

  /** 임시저장·작성완료 시 줄이 없을 때. 팀비는 라벨(지출 내역)에 맞춘다. */
  function toastEmptyItems() {
    var team = compose.classList.contains('is-team');
    toast(
      team ? '지출 내역을 입력하세요.' : '결제 항목을 입력하세요.',
      team ? '금액이 있는 줄을 한 줄 이상 적으세요.' : '통화와 금액을 한 줄 이상 적으세요.',
      'danger'
    );
  }

  /** 완료(DONE) 문서는 조회 전용 — 항목 추가·수정·삭제를 막는다. 초안·작성중만 편집 가능. */
  function isComposeDone() {
    return compose.getAttribute('data-status') === 'DONE';
  }

  /** 완료 또는 마감월이면 편집 잠금. */
  function isComposeLocked() {
    return isComposeDone() || monthLocked;
  }

  /** 잠금 사유에 맞는 안내를 띄운다. */
  function toastLocked() {
    if (monthLocked) {
      toast('마감된 달입니다.', '전전월 이전은 수정할 수 없습니다.', 'danger');
    } else {
      toast('완료된 문서입니다.', '수정하려면 먼저 완료를 해제하세요.', 'danger');
    }
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(function () {
        return fallbackCopy(text);
      });
    }
    return fallbackCopy(text);
  }

  function fallbackCopy(text) {
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      ok ? resolve() : reject(new Error('copy'));
    });
  }

  function post(payload) {
    payload.csrf = csrf;
    return fetch(apiUrl, {
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

  function applyYearMonth(text, ym) {
    var m = String(ym || '').match(/^(\d{4})-(\d{2})$/);
    if (!m) return text || '';
    var year = m[1];
    var monthNum = String(parseInt(m[2], 10));
    return String(text || '').replace(/\{year\}/g, year).replace(/\{month\}/g, monthNum);
  }

  function findTemplate(id) {
    var n = parseInt(id, 10);
    for (var i = 0; i < templates.length; i++) {
      if (parseInt(templates[i].id, 10) === n) return templates[i];
    }
    return null;
  }

  function findDraftByTemplate(id) {
    var n = parseInt(id, 10);
    for (var i = 0; i < drafts.length; i++) {
      if (parseInt(drafts[i].expense_template_id, 10) === n) return drafts[i];
    }
    return null;
  }

  function upsertDraft(row) {
    if (!row) return;
    var id = parseInt(row.id, 10);
    for (var i = 0; i < drafts.length; i++) {
      if (parseInt(drafts[i].id, 10) === id) {
        drafts[i] = row;
        return;
      }
    }
    drafts.push(row);
  }

  function badgeHtml(draft) {
    var status = draft && draft.status ? draft.status : '';
    if (status === 'DONE') return '<span class="badge badge-success">완료</span>';
    if (status) return '<span class="badge badge-brand">작성중</span>';
    return '<span class="badge badge-muted">미작성</span>';
  }

  function monthHint(summary) {
    var total = summary.total;
    if (total < 1) return '사용 중인 템플릿이 없습니다.';
    if (summary.done < 1) return '완료한 기안이 없습니다.';
    return '완료 기안 합계 · ' + total + '건 중 ' + summary.done + '건';
  }

  function computeMonthSummary() {
    var done = 0;
    var writing = 0;
    var sum = 0;
    for (var i = 0; i < templates.length; i++) {
      var draft = findDraftByTemplate(templates[i].id);
      if (!draft || !draft.status) continue;
      if (draft.status === 'DONE') {
        done += 1;
        sum += Number(draft.amount_krw || 0);
      } else {
        writing += 1;
      }
    }
    var total = templates.length;
    return {
      total: total,
      idle: Math.max(0, total - done - writing),
      writing: writing,
      done: done,
      amount_krw: sum
    };
  }

  function refreshMonthSummary() {
    var board = root.querySelector('[data-month-summary]');
    if (!board) return;
    var summary = computeMonthSummary();
    var sumEl = board.querySelector('[data-sum-krw]');
    if (sumEl) sumEl.textContent = Number(summary.amount_krw).toLocaleString('ko-KR');
    var hintEl = board.querySelector('[data-month-hint]');
    if (hintEl) hintEl.textContent = monthHint(summary);
    var idleEl = board.querySelector('[data-count-idle]');
    var writingEl = board.querySelector('[data-count-writing]');
    var doneEl = board.querySelector('[data-count-done]');
    if (idleEl) idleEl.textContent = String(summary.idle);
    if (writingEl) writingEl.textContent = String(summary.writing);
    if (doneEl) doneEl.textContent = String(summary.done);
    var meter = board.querySelector('[data-month-meter]');
    if (meter) {
      meter.setAttribute('aria-label', '미작성 ' + summary.idle + ', 작성중 ' + summary.writing + ', 완료 ' + summary.done);
      var idleSeg = meter.querySelector('[data-meter="idle"]');
      var writingSeg = meter.querySelector('[data-meter="writing"]');
      var doneSeg = meter.querySelector('[data-meter="done"]');
      if (idleSeg) idleSeg.style.flexGrow = String(summary.idle);
      if (writingSeg) writingSeg.style.flexGrow = String(summary.writing);
      if (doneSeg) doneSeg.style.flexGrow = String(summary.done);
    }
  }

  function setListBadge(templateId, draft) {
    var item = root.querySelector('.tpl-item[data-id="' + String(templateId) + '"]');
    if (!item) return;
    var status = draft && draft.status ? draft.status : '';
    item.setAttribute('data-status', status === 'DONE' ? 'done' : (status ? 'writing' : 'idle'));
    var badge = item.querySelector('.badge');
    if (!badge) return;
    var wrap = document.createElement('div');
    wrap.innerHTML = badgeHtml(draft);
    var next = wrap.firstElementChild;
    if (next) badge.replaceWith(next);
  }

  function setSelected(id) {
    root.querySelectorAll('.tpl-item').forEach(function (btn) {
      var on = btn.getAttribute('data-id') === String(id);
      btn.classList.toggle('is-selected', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function monthUrl(ym, id) {
    var u = '?month=' + encodeURIComponent(ym);
    if (id) u += '&id=' + encodeURIComponent(id);
    return u;
  }

  function showCompose(on) {
    if (idle) idle.hidden = on;
    if (compose) compose.hidden = !on;
  }

  function parseMoney(value) {
    var s = String(value || '').replace(/,/g, '').replace(/[^\d.]/g, '');
    if (s === '' || s === '.') return 0;
    var n = Number(s);
    return isFinite(n) ? n : 0;
  }

  function formatMoney(value, integersOnly) {
    var raw = String(value == null ? '' : value).replace(/,/g, '');
    if (raw === '' || raw === '.') return raw === '.' ? '0.' : '';
    raw = raw.replace(/[^\d.]/g, '');
    if (integersOnly) {
      var whole = raw.split('.')[0].replace(/^0+(?=\d)/, '');
      if (whole === '') return '';
      return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    var parts = raw.split('.');
    var intPart = parts[0].replace(/^0+(?=\d)/, '');
    if (intPart === '') intPart = parts.length > 1 ? '0' : '';
    var grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (parts.length > 1) return grouped + '.' + parts.slice(1).join('').replace(/\D/g, '');
    if (String(value).replace(/,/g, '').slice(-1) === '.') return grouped + '.';
    return grouped;
  }

  function applyMoneyFormat(el, integersOnly) {
    if (!el) return;
    var start = el.selectionStart;
    var digitsBefore = String(el.value || '').slice(0, start).replace(/\D/g, '').length;
    var next = formatMoney(el.value, integersOnly);
    if (el.value === next) return;
    el.value = next;
    var seen = 0;
    var i = 0;
    for (; i < next.length; i++) {
      if (/\d/.test(next.charAt(i))) {
        seen += 1;
        if (seen >= digitsBefore) {
          i += 1;
          break;
        }
      }
    }
    if (typeof el.setSelectionRange === 'function') el.setSelectionRange(i, i);
  }

  function collectItems() {
    var items = [];
    syncItemHost();
    if (!itemRows) return items;
    itemRows.querySelectorAll('.draft-item-row').forEach(function (tr) {
      var amount = parseMoney((tr.querySelector('[data-field="amount"]') || {}).value);
      if (!(amount > 0)) return;
      var krw = Math.round(parseMoney((tr.querySelector('[data-field="amount_krw"]') || {}).value));
      var group = tr.closest ? tr.closest('.draft-attach-group') : null;
      var rid = group ? parseInt(group.getAttribute('data-receipt-id') || '', 10) : NaN;
      var item = {
        payment_date: String((tr.querySelector('[data-field="payment_date"]') || {}).value || '').trim(),
        description: String((tr.querySelector('[data-field="description"]') || {}).value || '').trim(),
        vendor: String((tr.querySelector('[data-field="vendor"]') || {}).value || '').trim(),
        note: String((tr.querySelector('[data-field="note"]') || {}).value || '').trim(),
        currency: String((tr.querySelector('[data-field="currency"]') || {}).value || 'KRW'),
        amount: amount,
        item_kind: String((tr.querySelector('[data-field="item_kind"]') || {}).value || ''),
        receipt_id: rid > 0 ? rid : null
      };
      if (krw > 0) item.amount_krw = Math.round(krw);
      items.push(item);
    });
    return items;
  }

  // 현재 DOM 항목행을 draft.items 형태(amount_foreign, receipt_id)로 스냅샷(빈 행 포함) — 재렌더 편집 보존용.
  function snapshotItems() {
    var out = [];
    var host = document.getElementById('draft-item-rows');
    if (!host) return out;
    host.querySelectorAll('.draft-item-row').forEach(function (tr) {
      var group = tr.closest ? tr.closest('.draft-attach-group') : null;
      var rid = group ? parseInt(group.getAttribute('data-receipt-id') || '', 10) : NaN;
      var krw = Math.round(parseMoney((tr.querySelector('[data-field="amount_krw"]') || {}).value));
      out.push({
        payment_date: String((tr.querySelector('[data-field="payment_date"]') || {}).value || '').trim(),
        description: String((tr.querySelector('[data-field="description"]') || {}).value || '').trim(),
        vendor: String((tr.querySelector('[data-field="vendor"]') || {}).value || '').trim(),
        note: String((tr.querySelector('[data-field="note"]') || {}).value || '').trim(),
        currency: String((tr.querySelector('[data-field="currency"]') || {}).value || 'KRW'),
        item_kind: String((tr.querySelector('[data-field="item_kind"]') || {}).value || ''),
        amount_foreign: parseMoney((tr.querySelector('[data-field="amount"]') || {}).value),
        amount_krw: krw > 0 ? krw : null,
        receipt_id: rid > 0 ? rid : null
      });
    });
    return out;
  }

  /** 결제일·차대·거래처·메모·구분 중 하나라도 채워졌으면 '작성 중'인 줄로 본다. */
  function rowHasContent(tr) {
    if (!tr) return false;
    var fields = ['payment_date', 'description', 'vendor', 'note', 'item_kind'];
    for (var i = 0; i < fields.length; i++) {
      var el = tr.querySelector('[data-field="' + fields[i] + '"]');
      if (el && String(el.value || '').trim() !== '') return true;
    }
    return false;
  }

  function clearAmountInvalid(tr) {
    var el = tr && tr.querySelector('[data-field="amount"]');
    if (el) el.classList.remove('is-invalid');
  }

  /** 내용은 있는데 금액이 빈 줄(첫 번째)을 돌려준다. 없으면 null. 표시용 is-invalid도 정리한다. */
  function firstMissingAmountRow() {
    if (!itemRows) return null;
    var found = null;
    itemRows.querySelectorAll('.draft-item-row').forEach(function (tr) {
      clearAmountInvalid(tr);
      var amount = parseMoney((tr.querySelector('[data-field="amount"]') || {}).value);
      if (!(amount > 0) && rowHasContent(tr) && !found) found = tr;
    });
    return found;
  }

  function optionLabel(opt) {
    var label = '';
    if (!opt) return '';
    opt.childNodes.forEach(function (node) {
      if (node.nodeType === 3) label += node.textContent;
    });
    return label.trim() || String(opt.textContent || '').trim();
  }

  function setRowSelect(tr, field, value) {
    var hidden = tr.querySelector('[data-field="' + field + '"]');
    if (!hidden) return;
    var wrap = hidden.closest('[data-select]');
    hidden.value = value;
    if (!wrap) return;
    var valueEl = wrap.querySelector('.select-value');
    var match = null;
    wrap.querySelectorAll('.select-option').forEach(function (opt) {
      var on = (opt.getAttribute('data-value') || '') === String(value);
      opt.classList.toggle('is-selected', on);
      opt.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) match = opt;
    });
    if (!valueEl) return;
    valueEl.textContent = match ? optionLabel(match) : String(value);
    valueEl.classList.remove('is-placeholder');
  }

  function setRowKind(row, value) {
    var kind = value || '';
    var hidden = row.querySelector('[data-field="item_kind"]');
    if (hidden) hidden.value = kind;
    row.querySelectorAll('[data-kind]').forEach(function (btn) {
      var on = (btn.getAttribute('data-kind') || '') === kind;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function renumberItems() {
    if (!itemRows) return;
    itemRows.querySelectorAll('.draft-item-row').forEach(function (row, i) {
      var title = row.querySelector('[data-role="item-index"]');
      if (title) title.textContent = '항목 ' + (i + 1);
    });
    syncCollapseAllBtn();
  }

  function rowKindLabel(row) {
    var btn = row.querySelector('[data-kind].is-active');
    if (!btn) return '';
    var kind = btn.getAttribute('data-kind') || '';
    return kind ? String(btn.textContent || '').trim() : '';
  }

  function syncItemSummary(row) {
    if (!row) return;
    var desc = String((row.querySelector('[data-field="description"]') || {}).value || '').trim();
    var currency = String((row.querySelector('[data-field="currency"]') || {}).value || 'KRW');
    var amount = parseMoney((row.querySelector('[data-field="amount"]') || {}).value);
    var krw = Math.round(parseMoney((row.querySelector('[data-field="amount_krw"]') || {}).value));
    var date = String((row.querySelector('[data-field="payment_date"]') || {}).value || '').trim();
    var parts = [];
    var kind = rowKindLabel(row);
    if (kind) parts.push(kind);
    if (desc) parts.push(desc);
    if (amount > 0) parts.push(currency + ' ' + formatMoney(amount, currency === 'KRW'));
    if (krw > 0 && currency !== 'KRW') parts.push(formatMoney(krw, true) + '원');
    if (date) parts.push(date);
    var el = row.querySelector('[data-role="item-summary"]');
    if (el) el.textContent = parts.join(' · ') || '내용 없음';
  }

  function setItemCollapsed(row, collapsed) {
    if (!row) return;
    row.classList.toggle('is-collapsed', collapsed);
    row.querySelectorAll('[data-action="toggle-item"]').forEach(function (btn) {
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      if (btn.classList.contains('draft-item-toggle')) {
        btn.setAttribute('aria-label', collapsed ? '항목 펼치기' : '항목 접기');
      }
    });
    syncItemSummary(row);
    syncCollapseAllBtn();
  }

  function syncCollapseAllBtn() {
    var btn = root.querySelector('[data-action="collapse-items"]');
    if (!btn || !itemRows) return;
    if (compose && compose.classList.contains('is-team')) {
      btn.hidden = true;
      return;
    }
    var rows = itemRows.querySelectorAll('.draft-item-row');
    var open = itemRows.querySelectorAll('.draft-item-row:not(.is-collapsed)').length;
    btn.hidden = rows.length < 1;
    btn.textContent = open > 0 ? '모두 접기' : '모두 펼치기';
  }

  function collapseAllItems(collapsed) {
    if (!itemRows) return;
    itemRows.querySelectorAll('.draft-item-row').forEach(function (row) {
      setItemCollapsed(row, collapsed);
    });
  }

  /** 한 줄만 펼치고, 둘 이상이면 전부 접는다. 팀비 표는 접지 않는다. */
  function applyDefaultCollapse() {
    if (!itemRows) return;
    if (compose && compose.classList.contains('is-team')) {
      collapseAllItems(false);
      return;
    }
    var rows = itemRows.querySelectorAll('.draft-item-row');
    rows.forEach(function (row) {
      setItemCollapsed(row, rows.length > 1);
    });
  }

  function bootRowSelects(node) {
    if (window.DesignSystem && typeof window.DesignSystem.initSelects === 'function') {
      window.DesignSystem.initSelects(node);
    }
  }

  function bootRowDate(el) {
    if (!el || el._adp || typeof AirDatepicker === 'undefined') return;
    el._adp = new AirDatepicker(el, {
      locale: {
        days: ['일요일', '월요일', '화요일', '수요일', '목요일', '금요일', '토요일'],
        daysShort: ['일', '월', '화', '수', '목', '금', '토'],
        daysMin: ['일', '월', '화', '수', '목', '금', '토'],
        months: ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
        monthsShort: ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
        today: '오늘',
        clear: '초기화',
        dateFormat: 'yyyy-MM-dd',
        firstDay: 0
      },
      dateFormat: 'yyyy-MM-dd',
      autoClose: true,
      buttons: ['today', 'clear'],
      position: 'bottom left',
      container: document.body,
      onSelect: function () {
        bodyDirty = false;
        schedulePreview();
      }
    });
  }

  function currentItemTpl() {
    if (compose && compose.classList.contains('is-team')) {
      return document.getElementById('draft-team-row-tpl') || itemTpl;
    }
    return itemTpl;
  }

  function addItemRow(item, hostEl) {
    var tpl = currentItemTpl();
    syncItemHost();
    var host = hostEl || itemRows;
    if (!tpl || !host) return;
    var node = tpl.content.firstElementChild.cloneNode(true);
    host.appendChild(node);
    bootRowSelects(node);
    var dateEl = node.querySelector('[data-field="payment_date"]');
    var descEl = node.querySelector('[data-field="description"]');
    var vendorEl = node.querySelector('[data-field="vendor"]');
    var noteEl = node.querySelector('[data-field="note"]');
    var amtEl = node.querySelector('[data-field="amount"]');
    if (item) {
      if (dateEl) dateEl.value = item.payment_date || '';
      setRowKind(node, item.item_kind || '');
      if (descEl) descEl.value = item.description || '';
      if (vendorEl) vendorEl.value = item.vendor || '';
      if (noteEl) noteEl.value = item.note || '';
      setRowSelect(node, 'currency', item.currency || 'KRW');
      if (amtEl && item.amount_foreign != null) {
        amtEl.value = formatMoney(item.amount_foreign, (item.currency || 'KRW') === 'KRW');
      }
      if (item.amount_krw != null) setRowKrw(node, item.amount_krw);
    } else {
      var prevRows = host.querySelectorAll('.draft-item-row');
      var prev = prevRows.length > 1 ? prevRows[prevRows.length - 2] : null;
      var prevDate = prev ? String((prev.querySelector('[data-field="payment_date"]') || {}).value || '').trim() : '';
      if (dateEl && !dateEl.value && prevDate) dateEl.value = prevDate;
      if (noteEl && compose && compose.classList.contains('is-team')) {
        var prevNote = prev ? String((prev.querySelector('[data-field="note"]') || {}).value || '').trim() : '';
        noteEl.value = prevNote || TEAM_NOTE_DEFAULT;
      }
    }
    bootRowDate(dateEl);
    if (dateEl && dateEl._adp && dateEl.value) {
      dateEl._adp.selectDate(dateEl.value);
    }
    syncRateBtn(node);
    syncAmountScale(node);
    syncItemSummary(node);
    renumberItems();
    // 불러오기는 기본 접기, +항목 추가는 바로 입력할 수 있게 펼친다.
    if (!item) {
      setItemCollapsed(node, false);
    }
  }

  function fillItems(items) {
    fillingRows = true;
    syncItemHost();
    // 비팀 모드: 첨부별 그룹 렌더(캐시된 첨부 목록 사용). clear/지난달/저장후 재렌더 모두 여기로.
    if (!isTeamMode()) {
      renderAttachmentGroups(items || [], receiptFilesCache);
      fillingRows = false;
      return;
    }
    var cardHost = document.getElementById('draft-item-rows');
    var teamHost = document.getElementById('draft-team-rows');
    if (cardHost) cardHost.innerHTML = '';
    if (teamHost) teamHost.innerHTML = '';
    if (!items || !items.length) {
      addItemRow(null);
    } else {
      // 주의: forEach는 (item, index)를 넘기므로 index가 addItemRow의 hostEl로 새면 안 됨.
      items.forEach(function (it) { addItemRow(it); });
    }
    fillingRows = false;
    applyDefaultCollapse();
  }

  function filledItemRows() {
    var out = [];
    syncItemHost();
    if (!itemRows) return out;
    itemRows.querySelectorAll('.draft-item-row').forEach(function (tr) {
      var amount = parseMoney((tr.querySelector('[data-field="amount"]') || {}).value);
      if (!(amount > 0)) return;
      out.push(tr);
    });
    return out;
  }

  function rowCurrency(tr) {
    return String((tr.querySelector('[data-field="currency"]') || {}).value || 'KRW');
  }

  function fxItemRows() {
    var out = [];
    syncItemHost();
    if (!itemRows) return out;
    itemRows.querySelectorAll('.draft-item-row').forEach(function (tr) {
      if (rowCurrency(tr) !== 'KRW') out.push(tr);
    });
    return out;
  }

  function rowRatePayload(tr) {
    var date = String((tr.querySelector('[data-field="payment_date"]') || {}).value || '').trim();
    return {
      date: date,
      currency: rowCurrency(tr),
      amount: parseMoney((tr.querySelector('[data-field="amount"]') || {}).value)
    };
  }

  function refreshRatesForRows(rows, trigger, silent) {
    if (!rows.length) {
      if (!silent) toast('외화 줄이 없습니다.', '통화를 USD, EUR, JPY로 바꿉니다.', 'danger');
      return;
    }
    var items = rows.map(rowRatePayload);
    var buttons = [];
    if (trigger) buttons.push(trigger);
    rows.forEach(function (tr) {
      var btn = tr.querySelector('[data-action="refresh-rate"]');
      if (btn) buttons.push(btn);
    });
    var header = root.querySelector('[data-action="refresh-rates"]');
    if (header && rows.length > 1) buttons.push(header);
    buttons.forEach(function (btn) {
      btn.disabled = true;
      btn.classList.add('is-loading');
    });
    post({ action: 'rates', items: items })
      .then(function (data) {
        var quotes = data.quotes || [];
        var ok = 0;
        var fail = 0;
        var bits = [];
        quotes.forEach(function (quote, i) {
          var tr = rows[i];
          if (!tr) return;
          if (quote && quote.success) {
            setRowKrw(tr, quote.amount_krw);
            ok += 1;
            var rate = Number(quote.rate || 0).toLocaleString('ko-KR', { maximumFractionDigits: 4 });
            bits.push((quote.currency || items[i].currency) + ' ' + rate);
          } else {
            if (!silent) setRowKrw(tr, 0);
            fail += 1;
          }
        });
        if (!silent) {
          if (fail && !ok) {
            toast('환율을 가져오지 못했습니다.', '원화는 0원으로 넣었습니다. 내용을 직접 고치면 됩니다.', 'danger');
          } else if (fail) {
            toast('일부 환율만 가져왔습니다.', bits.join(' · ') + ' / 나머지는 0원', 'danger');
          } else {
            toast('환율을 가져왔습니다.', bits.join(' · '));
          }
        }
        schedulePreview();
      })
      .catch(function (err) {
        if (!silent) {
          rows.forEach(function (tr) { setRowKrw(tr, 0); });
          toast('환율을 가져오지 못했습니다.', err.message || '원화는 0원으로 넣었습니다.', 'danger');
        }
      })
      .then(function () {
        buttons.forEach(function (btn) {
          btn.disabled = false;
          btn.classList.remove('is-loading');
        });
      });
  }

  function refreshAllRates(trigger) {
    refreshRatesForRows(fxItemRows(), trigger);
  }

  function refreshRowRate(tr, trigger, silent) {
    if (!tr) return;
    if (rowCurrency(tr) === 'KRW') {
      if (!silent) toast('외화 줄이 아닙니다.', '통화를 USD, EUR, JPY로 바꿉니다.', 'danger');
      return;
    }
    refreshRatesForRows([tr], trigger || tr.querySelector('[data-action="refresh-rate"]'), silent);
  }

  function maybeAutoRate(tr) {
    if (!tr || fillingRows) return;
    var amount = parseMoney((tr.querySelector('[data-field="amount"]') || {}).value);
    if (rowCurrency(tr) === 'KRW' || !(amount > 0)) return;
    refreshRowRate(tr, tr.querySelector('[data-action="refresh-rate"]'), true);
  }

  function isTeamExpenseTitle(templateTitle) {
    var t = String(templateTitle || '');
    return t.indexOf('팀비') !== -1 || t.indexOf('팀 운영비') !== -1;
  }

  function setHelpTip(id, text) {
    var el = document.getElementById(id);
    if (!el) return;
    el.setAttribute('data-tooltip', text);
    el.setAttribute('aria-label', text);
  }

  function unwrapYearMonthBrackets(title) {
    return String(title || '')
      .replace(/\[(\d{4}년[^\]]*)\]\s*/g, '$1 ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function copyTitleForClipboard(title, templateTitle) {
    var t = unwrapYearMonthBrackets(title);
    if (!t) return '';
    if (isTeamExpenseTitle(templateTitle)) return t;
    if (t.indexOf('[기안서]') === 0) return t;
    return '[기안서] ' + t;
  }

  function tsvCell(value) {
    return String(value || '').replace(/[\t\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function mdDate(date) {
    var m = String(date || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return '';
    return String(parseInt(m[2], 10)) + '/' + String(parseInt(m[3], 10));
  }

  function formatTeamTsvFromRows() {
    return collectItems().map(function (item) {
      var krw = Math.round(Number(item.amount_krw || item.amount || 0));
      var amt = krw > 0 ? formatMoney(krw, true) : '';
      return [mdDate(item.payment_date), tsvCell(item.description), tsvCell(item.vendor), amt, tsvCell(item.note)].join('\t');
    }).join('\n');
  }

  function previewCell(text) {
    var td = document.createElement('td');
    td.textContent = text || '';
    return td;
  }

  function renderTeamPreview() {
    var tbody = document.getElementById('draft-team-preview-body');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!compose || !compose.classList.contains('is-team')) return;
    syncItemHost();
    if (!itemRows) return;
    var rows = itemRows.querySelectorAll('.draft-item-row');
    if (!rows.length) {
      var empty = document.createElement('tr');
      var td = document.createElement('td');
      td.colSpan = 5;
      td.className = 'is-empty';
      td.textContent = '줄을 입력하면 표가 채워집니다.';
      empty.appendChild(td);
      tbody.appendChild(empty);
      return;
    }
    rows.forEach(function (tr) {
      var amount = parseMoney((tr.querySelector('[data-field="amount"]') || {}).value);
      var row = document.createElement('tr');
      row.appendChild(previewCell(mdDate(String((tr.querySelector('[data-field="payment_date"]') || {}).value || ''))));
      row.appendChild(previewCell(String((tr.querySelector('[data-field="description"]') || {}).value || '').trim()));
      row.appendChild(previewCell(String((tr.querySelector('[data-field="vendor"]') || {}).value || '').trim()));
      var amtTd = previewCell(amount > 0 ? formatMoney(amount, true) : '');
      amtTd.className = 'num';
      row.appendChild(amtTd);
      row.appendChild(previewCell(String((tr.querySelector('[data-field="note"]') || {}).value || '').trim()));
      tbody.appendChild(row);
    });
  }

  function currentTitle() {
    return (document.getElementById('draft-title') || {}).value || '';
  }

  function currentBody() {
    return (document.getElementById('draft-body') || {}).value || '';
  }

  function applyPreview(preview, template, draft) {
    var titleEl = document.getElementById('draft-title');
    var bodyEl = document.getElementById('draft-body');
    // 라이브 preview가 없으면(완료·마감 등 재계산 차단 시) 저장된 완성 본문을 그대로 쓴다.
    // 그래야 완료 문서 복사본이 저장 시점 값으로 치환된 채 유지된다. 저장본이 없을 때만 패턴을 부분 치환.
    var storedTitle = draft && draft.draft_title ? String(draft.draft_title) : '';
    var storedBody = draft && draft.draft_body ? String(draft.draft_body) : '';
    var title = preview && preview.draft_title
      ? preview.draft_title
      : (storedTitle || applyYearMonth((template && template.title_pattern) || '', month));
    var body = preview && preview.draft_body
      ? preview.draft_body
      : (storedBody || applyYearMonth((template && template.body_pattern) || '', month));
    if (!preview && !storedBody && template) {
      if (template.title) {
        title = title.replace(/\{title\}/g, template.title);
      }
      body = body.replace(/\{title\}/g, title);
    }
    title = copyTitleForClipboard(title, template && template.title);
    if (isTeamExpenseTitle(template && template.title) && !preview) {
      body = formatTeamTsvFromRows();
    }
    if (titleEl && !titleDirty) titleEl.value = title;
    if (bodyEl && !bodyDirty) bodyEl.value = body;
    compose.setAttribute('data-title', titleEl ? titleEl.value : title);
    compose.setAttribute('data-body', bodyEl ? bodyEl.value : body);
    renderTeamPreview();
    if (itemSum) {
      var sum = preview && preview.amount_krw != null ? Number(preview.amount_krw) : (draft ? Number(draft.amount_krw || 0) : 0);
      itemSum.textContent = sum.toLocaleString('ko-KR');
    }
    if (preview && preview.items && itemRows) {
      var rows = filledItemRows();
      preview.items.forEach(function (item, i) {
        var row = rows[i];
        if (!row) return;
        var krwEl = row.querySelector('[data-field="amount_krw"]');
        if (krwEl && item.amount_krw != null) {
          var nextKrw = Number(item.amount_krw);
          var typed = parseMoney(krwEl.value);
          // 원화는 자동계산이라 수동값은 없지만, 환율 조회 실패로 0이 오면 기존 계산값을 지키지 않는다.
          var keepKrw = item.rate_ok === false && nextKrw === 0 && typed > 0;
          if (!keepKrw) setRowKrw(row, nextKrw);
        }
        syncRateBtn(row);
      });
    }
  }

  function syncRateBtn(tr) {
    if (!tr) return;
    var cur = String((tr.querySelector('[data-field="currency"]') || {}).value || 'KRW');
    var btn = tr.querySelector('[data-action="refresh-rate"]');
    if (btn) btn.hidden = cur === 'KRW';
  }

  function setRowKrw(tr, amount) {
    var krwEl = tr && tr.querySelector('[data-field="amount_krw"]');
    if (!krwEl) return;
    var n = Math.round(Number(amount || 0));
    krwEl.value = n > 0 ? formatMoney(n, true) : '';
    syncItemSummary(tr);
  }

  function syncAmountScale(tr) {
    var amtEl = tr && tr.querySelector('[data-field="amount"]');
    if (!amtEl) return;
    var krw = rowCurrency(tr) === 'KRW';
    amtEl.setAttribute('inputmode', krw ? 'numeric' : 'decimal');
    if (amtEl.value !== '') amtEl.value = formatMoney(amtEl.value, krw);
  }

  function resetRowMoney(tr) {
    if (!tr) return;
    var amtEl = tr.querySelector('[data-field="amount"]');
    if (amtEl) amtEl.value = '';
    setRowKrw(tr, 0);
    syncAmountScale(tr);
  }

  function syncKrwFromAmount(tr) {
    if (!tr || rowCurrency(tr) !== 'KRW') return;
    setRowKrw(tr, parseMoney((tr.querySelector('[data-field="amount"]') || {}).value));
  }

  function schedulePreview() {
    if (fillingRows) return;
    // 완료 문서는 저장 시점의 환율·원화를 그대로 유지한다(재조회로 값이 바뀌지 않게).
    if (isComposeLocked()) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(runPreview, 350);
  }

  function runPreview() {
    var templateId = parseInt(root.getAttribute('data-selected') || '0', 10);
    if (!(templateId > 0)) return;
    var items = collectItems();
    var template = findTemplate(templateId);
    var draft = findDraftByTemplate(templateId);
    var seq = ++previewSeq;
    post({ action: 'preview', template_id: templateId, month: month, items: items })
      .then(function (data) {
        if (seq !== previewSeq) return;
        applyPreview(data.preview, template, draft);
      })
      .catch(function () {
        if (seq !== previewSeq) return;
        applyPreview(null, template, draft);
      });
  }

  function renderPane(template) {
    if (!template) {
      showCompose(false);
      if (compose) compose.classList.remove('is-team');
      syncItemHost();
      var idleEditable = document.getElementById('draft-team-editable');
      var idleBody = document.getElementById('draft-body-wrap');
      var idleCards = document.getElementById('draft-item-rows');
      if (idleEditable) idleEditable.hidden = true;
      if (idleBody) idleBody.hidden = false;
      if (idleCards) idleCards.hidden = false;
      root.setAttribute('data-selected', '');
      loadReceiptFiles();
      return;
    }
    var draft = findDraftByTemplate(template.id);
    var titleEl = document.getElementById('draft-tpl-title');
    var metaEl = document.getElementById('draft-tpl-meta');
    var pay = template.pay_label || '';
    var meta = [template.assignee || '', template.cycle_label || ''].filter(Boolean);
    if (pay) meta.push(pay);
    if (draft && draft.created_by) meta.push(draft.created_by);
    if (titleEl) titleEl.textContent = template.title || '';
    if (metaEl) metaEl.textContent = meta.join(' · ');

    var team = isTeamExpenseTitle(template.title);
    compose.classList.toggle('is-team', team);
    syncItemHost();
    var teamEditable = document.getElementById('draft-team-editable');
    var cardList = document.getElementById('draft-item-rows');
    var bodyWrap = document.getElementById('draft-body-wrap');
    if (teamEditable) teamEditable.hidden = !team;   // 팀: 편집 가능한 붙여넣기 표
    if (cardList) cardList.hidden = team;
    if (bodyWrap) bodyWrap.hidden = team;            // 팀은 textarea 대신 표 편집
    var receiptArea = document.getElementById('draft-receipt-area');
    // 모든 템플릿(팀비 포함)에서 노출하되 완료(DONE)·마감월엔 숨김.
    // 현재 draft 상태로 직접 판정(data-status는 아래 979줄에서 세팅되므로 stale 회피).
    if (receiptArea) receiptArea.hidden = (draft && draft.status === 'DONE') || monthLocked;
    // 비팀 모드는 첨부 그룹을 #draft-item-rows에 렌더하므로 별도 파일목록은 숨김(팀은 loadReceiptFiles가 표시).
    var receiptFilesEl = document.getElementById('draft-receipt-files');
    if (receiptFilesEl && !team) { receiptFilesEl.hidden = true; receiptFilesEl.innerHTML = ''; }
    // 팀은 아래 편집표의 "+ 행 추가"를 쓰므로 상단 "+ 항목 추가"는 숨김.
    var topAddItem = root.querySelector('.draft-items-actions [data-action="add-item"]');
    if (topAddItem) topAddItem.hidden = team;
    var itemsLabel = document.getElementById('draft-items-label');
    if (itemsLabel) itemsLabel.textContent = team ? '지출 내역' : '결제 항목';
    var bodyLabel = document.getElementById('draft-body-label');
    if (bodyLabel) bodyLabel.textContent = team ? '표에 붙일 값' : '내용';
    setHelpTip('draft-items-tip', team
      ? '그룹웨어 표와 같은 칸입니다. 적요·거래처·금액을 채웁니다.'
      : '이달 결제 줄을 적습니다. 지난달 불러오기로 시작할 수 있습니다.');
    setHelpTip('draft-body-tip', team
      ? '복사한 뒤 그룹웨어 표의 첫 날짜 칸에 붙입니다.'
      : '그룹웨어 본문입니다. 복사해 내용 칸에 붙입니다.');
    root.querySelectorAll('[data-copy="body"]').forEach(function (btn) {
      btn.setAttribute(
        'data-tooltip',
        team ? '표의 첫 날짜 칸을 찍고 붙여 넣습니다.' : '그룹웨어 본문에 붙입니다.'
      );
    });
    var bodyHint = document.getElementById('draft-body-hint');
    if (bodyHint) bodyHint.hidden = !team;
    root.setAttribute('data-selected', String(template.id));

    var draftItems = draft && draft.items ? draft.items : [];
    if (team) {
      fillItems(draftItems);              // 팀: 기존 평면/표
    } else {
      loadComposeGroups(draftItems);      // 비팀: 첨부 목록 조회 후 그룹 렌더(+미리보기)
    }
    bodyDirty = false;
    titleDirty = false;
    applyPreview(null, template, draft);
    compose.setAttribute('data-draft-id', draft ? String(draft.id) : '');
    compose.setAttribute('data-status', draft ? (draft.status || 'DRAFT') : '');
    syncActionButtons(draft);

    showCompose(true);
    root.setAttribute('data-selected', String(template.id));
    setSelected(template.id);
    if (team) loadReceiptFiles();        // 비팀은 loadComposeGroups가 그룹으로 처리
    schedulePreview();
  }

  function pickTemplate(id) {
    var template = findTemplate(id);
    if (!template) return;
    renderPane(template);
    history.replaceState(null, '', monthUrl(month, template.id));
  }

  function goMonth(ym) {
    if (!/^\d{4}-\d{2}$/.test(ym) || ym === month) return;
    var id = root.getAttribute('data-selected') || '';
    location.href = monthUrl(ym, id && id !== '0' ? id : '');
  }

  function syncActionButtons(draft) {
    var done = !!(draft && draft.status === 'DONE');
    if (saveBtn) saveBtn.hidden = done || monthLocked;
    if (doneBtn) doneBtn.hidden = !draft || done || monthLocked;
    if (reopenBtn) reopenBtn.hidden = !done || monthLocked;
    // 초기화는 편집 가능한 상태에서만(완료·마감월엔 숨김).
    var clearBtn = root.querySelector('[data-action="clear-items"]');
    if (clearBtn) clearBtn.hidden = done || monthLocked;
    if (!done) syncTopDownloadAll([]);
  }

  /** 완료 문서면 상단(모두 펼치기 옆)에 전체 다운로드를 둔다. */
  function syncTopDownloadAll(files) {
    var btn = document.getElementById('draft-download-all');
    if (!btn) return;
    var selId = parseInt(root.getAttribute('data-selected') || '0', 10);
    var list = files || [];
    var show = compose.getAttribute('data-status') === 'DONE' && selId > 0 && list.length > 1;
    btn.hidden = !show;
    if (show) {
      btn.href = 'api/receipt_file?action=download_all&template_id=' + selId + '&month=' + encodeURIComponent(month);
    }
  }

  function saveDraftRequest() {
    if (isComposeLocked()) { toastLocked(); return null; }
    var templateId = parseInt(root.getAttribute('data-selected') || '0', 10);
    if (!(templateId > 0)) return null;
    var missing = firstMissingAmountRow();
    if (missing) {
      var badAmt = missing.querySelector('[data-field="amount"]');
      if (badAmt) badAmt.classList.add('is-invalid');
      setItemCollapsed(missing, false);
      if (badAmt) badAmt.focus();
      toast('금액을 입력하세요.', '통화와 금액은 필수입니다. 금액이 빈 줄이 있습니다.', 'danger');
      return null;
    }
    var items = collectItems();
    if (!items.length) {
      toastEmptyItems();
      return null;
    }
    var id = parseInt(compose.getAttribute('data-draft-id') || '0', 10);
    if (id > 0) {
      bodyDirty = false;
      return post({
        action: 'items',
        id: id,
        items: items,
        draft_title: currentTitle(),
        draft_body: currentBody()
      });
    }
    return post({
      action: 'create',
      template_id: templateId,
      month: month,
      items: items,
      draft_body: currentBody(),
      draft_title: currentTitle()
    });
  }

  function persistDraft(row) {
    upsertDraft(row);
    setListBadge(row.expense_template_id, row);
    var keepTitle = currentTitle();
    var keepBody = currentBody();
    var keepTitleDirty = titleDirty;
    var keepBodyDirty = bodyDirty;
    renderPane(findTemplate(row.expense_template_id));
    if (keepTitleDirty) {
      var titleEl = document.getElementById('draft-title');
      if (titleEl) titleEl.value = keepTitle;
      compose.setAttribute('data-title', keepTitle);
      titleDirty = true;
    }
    if (keepBodyDirty) {
      var bodyEl = document.getElementById('draft-body');
      if (bodyEl) bodyEl.value = keepBody;
      compose.setAttribute('data-body', keepBody);
      bodyDirty = true;
    }
    refreshMonthSummary();
  }

  root.addEventListener('click', function (e) {
    var pick = e.target.closest('[data-action="pick"]');
    if (pick) {
      pickTemplate(pick.getAttribute('data-id'));
      return;
    }
    var add = e.target.closest('[data-action="add-item"]');
    if (add) {
      if (isComposeLocked()) { toastLocked(); return; }
      addItemRow(null, isTeamMode() ? null : directAddBody());
      return;
    }
    var clearItems = e.target.closest('[data-action="clear-items"]');
    if (clearItems) {
      if (isComposeLocked()) { toastLocked(); return; }
      if (collectItems().length && !window.confirm('결제 항목을 모두 지우고 초기화할까요?')) return;
      fillItems([]);
      schedulePreview();
      toast('결제 항목을 초기화했습니다.', '다시 입력하거나 첨부파일을 AI로 분석하세요.');
      return;
    }
    var foldAll = e.target.closest('[data-action="collapse-items"]');
    if (foldAll) {
      var open = itemRows ? itemRows.querySelectorAll('.draft-item-row:not(.is-collapsed)').length : 0;
      collapseAllItems(open > 0);
      return;
    }
    var foldOne = e.target.closest('[data-action="toggle-item"]');
    if (foldOne) {
      var foldRow = foldOne.closest('.draft-item-row');
      if (foldRow) setItemCollapsed(foldRow, !foldRow.classList.contains('is-collapsed'));
      return;
    }
    var loadPrev = e.target.closest('[data-action="load-previous"]');
    if (loadPrev) {
      if (isComposeLocked()) { toastLocked(); return; }
      var templateId = parseInt(root.getAttribute('data-selected') || '0', 10);
      if (!(templateId > 0)) return;
      if (collectItems().length && !window.confirm('지금 입력한 줄을 지난달 데이터로 바꿀까요?')) return;
      loadPrev.disabled = true;
      post({ action: 'previous', template_id: templateId, month: month })
        .then(function (data) {
          var prev = data.previous || {};
          if (!prev.found) {
            toast('지난달 기안이 없습니다.', '이 템플릿으로 작성한 이전 월이 없습니다.', 'danger');
            return;
          }
          fillItems(prev.items || []);
          bodyDirty = false;
          toast('지난달 줄을 넣었습니다.', (prev.from_month || '') + ' 결제일을 이번 달로 옮겼습니다.');
          schedulePreview();
        })
        .catch(function (err) {
          toast('불러오지 못했습니다.', err.message || '', 'danger');
        })
        .then(function () {
          loadPrev.disabled = false;
        });
      return;
    }
    var refreshAll = e.target.closest('[data-action="refresh-rates"]');
    if (refreshAll) {
      if (isComposeLocked()) { toastLocked(); return; }
      refreshAllRates(refreshAll);
      return;
    }
    var refresh = e.target.closest('[data-action="refresh-rate"]');
    if (refresh) {
      if (isComposeLocked()) { toastLocked(); return; }
      refreshRowRate(refresh.closest('.draft-item-row'), refresh);
      return;
    }
    var remove = e.target.closest('[data-action="remove-item"]');
    if (remove) {
      if (isComposeLocked()) { toastLocked(); return; }
      var tr = remove.closest('.draft-item-row');
      if (tr && itemRows && itemRows.querySelectorAll('.draft-item-row').length > 1) {
        tr.remove();
        renumberItems();
        applyDefaultCollapse();
        itemRows.querySelectorAll('.draft-item-row').forEach(syncItemSummary);
        bodyDirty = false;
        schedulePreview();
      }
      return;
    }
    var copyBtn = e.target.closest('[data-copy]');
    if (copyBtn) {
      var field = copyBtn.getAttribute('data-copy');
      var template = findTemplate(parseInt(root.getAttribute('data-selected') || '0', 10));
      var text = field === 'title'
        ? copyTitleForClipboard(currentTitle() || compose.getAttribute('data-title'), template && template.title)
        : (currentBody() || compose.getAttribute('data-body'));
      copyText(text || '').then(function () {
        var teamCopy = isTeamExpenseTitle(template && template.title);
        var copyHint = field === 'body' && teamCopy
          ? '표의 첫 날짜 칸을 찍고 붙여 넣으면 됩니다.'
          : '그룹웨어에 붙여 넣으면 됩니다.';
        toast(field === 'title' ? '제목을 복사했습니다.' : '내용을 복사했습니다.', copyHint);
      }).catch(function (err) {
        toast('복사에 실패했습니다.', err.message || '', 'danger');
      });
      return;
    }
    var done = e.target.closest('[data-action="done"]');
    if (done) {
      var req = saveDraftRequest();
      if (!req) return;
      if (doneBtn) doneBtn.disabled = true;
      req.then(function (data) {
        persistDraft(data.row);
        var id = parseInt(data.row.id || compose.getAttribute('data-draft-id') || '0', 10);
        if (!(id > 0)) throw new Error('초안 id가 필요합니다.');
        return post({ action: 'status', id: id, status: 'DONE' });
      }).then(function (data) {
        persistDraft(data.row);
        toast('작성완료로 표시했습니다.', '그룹웨어에 올린 기안입니다.');
      }).catch(function (err) {
        toast('작성완료하지 못했습니다.', err.message || '', 'danger');
      }).then(function () {
        if (doneBtn) doneBtn.disabled = false;
      });
      return;
    }
    var reopen = e.target.closest('[data-action="reopen"]');
    if (reopen) {
      var reopenId = parseInt(compose.getAttribute('data-draft-id') || '0', 10);
      if (!(reopenId > 0)) return;
      post({ action: 'status', id: reopenId, status: 'DRAFT' })
        .then(function (data) {
          persistDraft(data.row);
          toast('초안으로 되돌렸습니다.', '다시 수정하거나 작성완료할 수 있습니다.');
        })
        .catch(function (err) {
          toast('상태 변경에 실패했습니다.', err.message || '', 'danger');
        });
    }
  });

  var itemsRoot = root.querySelector('.draft-items');
  if (itemsRoot) {
    itemsRoot.addEventListener('input', function (e) {
      var field = e.target && e.target.getAttribute ? e.target.getAttribute('data-field') : '';
      var tr = e.target && e.target.closest ? e.target.closest('.draft-item-row') : null;
      if (field === 'amount') {
        applyMoneyFormat(e.target, tr && rowCurrency(tr) === 'KRW');
      }
      if (field === 'amount' && tr) {
        syncKrwFromAmount(tr);
        clearAmountInvalid(tr);
      }
      if (tr) syncItemSummary(tr);
      bodyDirty = false;
      schedulePreview();
    });
    itemsRoot.addEventListener('change', function (e) {
      var tr = e.target && e.target.closest ? e.target.closest('.draft-item-row') : null;
      var field = e.target && e.target.getAttribute ? e.target.getAttribute('data-field') : '';
      if (tr) syncRateBtn(tr);
      if (tr && field === 'currency') {
        resetRowMoney(tr);
      }
      if (tr && (field === 'amount' || field === 'payment_date')) {
        maybeAutoRate(tr);
      }
      if (tr) syncItemSummary(tr);
      bodyDirty = false;
      schedulePreview();
    });
    itemsRoot.addEventListener('select:change', function (e) {
      var wrap = e.target && e.target.closest ? e.target.closest('[data-select]') : null;
      var tr = wrap && wrap.closest('.draft-item-row');
      var hidden = wrap ? wrap.querySelector('[data-field]') : null;
      var field = hidden ? hidden.getAttribute('data-field') : '';
      if (tr) syncRateBtn(tr);
      if (tr && field === 'currency') {
        resetRowMoney(tr);
      }
      if (tr) syncItemSummary(tr);
      bodyDirty = false;
      schedulePreview();
    });
    itemsRoot.addEventListener('click', function (e) {
      var kindBtn = e.target && e.target.closest ? e.target.closest('[data-kind]') : null;
      if (!kindBtn) return;
      var row = kindBtn.closest('.draft-item-row');
      if (!row) return;
      setRowKind(row, kindBtn.getAttribute('data-kind') || '');
      syncItemSummary(row);
      bodyDirty = false;
      schedulePreview();
    });
  }

  var titleEl = document.getElementById('draft-title');
  if (titleEl) {
    titleEl.addEventListener('input', function () {
      titleDirty = true;
      compose.setAttribute('data-title', titleEl.value);
    });
  }

  var bodyEl = document.getElementById('draft-body');
  if (bodyEl) {
    bodyEl.addEventListener('input', function () {
      bodyDirty = true;
      compose.setAttribute('data-body', bodyEl.value);
    });
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      var req = saveDraftRequest();
      if (!req) return;
      saveBtn.disabled = true;
      req.then(function (data) {
        persistDraft(data.row);
        var team = compose.classList.contains('is-team');
        toast(
          '임시저장했습니다.',
          team ? '제목을 복사하고, 표 값은 첫 날짜 칸에 붙이세요.' : '제목·본문을 복사해 그룹웨어에 붙이세요.'
        );
      }).catch(function (err) {
        toast('임시저장하지 못했습니다.', err.message || '', 'danger');
      }).then(function () {
        saveBtn.disabled = false;
      });
    });
  }

  if (filter) {
    filter.addEventListener('input', function () {
      var q = (filter.value || '').trim().toLowerCase();
      var visible = 0;
      root.querySelectorAll('.tpl-item').forEach(function (btn) {
        var hay = btn.getAttribute('data-search') || '';
        var hide = q !== '' && hay.indexOf(q) === -1;
        btn.hidden = hide;
        if (!hide) visible += 1;
      });
      if (filterEmpty) {
        filterEmpty.hidden = !(q !== '' && visible === 0);
      }
    });
  }

  if (monthInput) {
    monthInput.addEventListener('change', function () {
      goMonth((monthInput.value || '').trim());
    });
    if (monthInput._adp && typeof monthInput._adp.update === 'function') {
      monthInput._adp.update({
        onSelect: function (meta) {
          var formatted = meta && meta.formattedDate;
          if (Array.isArray(formatted)) formatted = formatted[0];
          if (typeof formatted === 'string' && formatted) {
            goMonth(formatted);
          }
        }
      });
    }
  }

  if (monthLocked) {
    root.classList.add('is-month-locked');
    var lockHint = document.getElementById('draft-lock-hint');
    if (lockHint) lockHint.hidden = false;
  }

  /* ── 서버 보관 영수증 파일 목록 ─────────────────────────────────── */
  // 업로드 IIFE 내부에서 실제 구현이 할당된다(파일별 "AI 분석" → 검토 카드).
  var receiptAnalyzeFile = function () {};

  /* ── AI 분석 로딩바 ─────────────────────────────────────────────── */
  // 목록(container)은 렌더 때 innerHTML이 비워지므로, 바는 목록의 형제로 붙여 유지한다.
  var analyzingCount = 0;
  function receiptAnalyzingBar() {
    var host = document.getElementById('draft-receipt-files');
    if (!host || !host.parentNode) return null;
    var bar = document.getElementById('draft-receipt-analyzing');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'draft-receipt-analyzing';
      bar.className = 'draft-analyzing';
      bar.hidden = true;
      bar.setAttribute('role', 'status');
      bar.setAttribute('aria-live', 'polite');
      bar.innerHTML =
        '<span class="draft-analyzing-label"><span class="draft-spinner" aria-hidden="true"></span>'
        + '<span data-role="analyzing-text">AI 분석 중…</span></span>'
        + '<span class="draft-analyzing-track"><span class="draft-analyzing-fill"></span></span>';
      host.parentNode.insertBefore(bar, host);
    }
    return bar;
  }
  function setAnalyzing(delta) {
    analyzingCount = Math.max(0, analyzingCount + delta);
    var bar = receiptAnalyzingBar();
    if (!bar) return;
    bar.hidden = analyzingCount === 0;
    var txt = bar.querySelector('[data-role="analyzing-text"]');
    if (txt) txt.textContent = analyzingCount > 1 ? ('AI 분석 중… (' + analyzingCount + ')') : 'AI 분석 중…';
  }

  function formatBytes(n) {
    n = Number(n) || 0;
    if (n >= 1048576) return (n / 1048576).toFixed(1) + 'MB';
    if (n >= 1024) return Math.round(n / 1024) + 'KB';
    return n + 'B';
  }

  function deleteReceiptFile(id) {
    fetch('api/receipt_file', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'delete', id: id, csrf: csrf })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || '삭제에 실패했습니다.');
        if (isTeamMode()) {
          loadReceiptFiles();
        } else {
          // 비팀: 해당 그룹 카드(+그 안의 항목행)를 제거하고 캐시 정리.
          var sec = document.querySelector('.draft-attach-group[data-receipt-id="' + id + '"]');
          if (sec && sec.parentNode) sec.parentNode.removeChild(sec);
          receiptFilesCache = receiptFilesCache.filter(function (f) { return String(f.id) !== String(id); });
          renumberItems();
          schedulePreview();
        }
        toast('첨부 파일을 삭제했습니다.', '');
      })
      .catch(function (err) { toast('삭제 실패', err.message || '', 'danger'); });
  }

  // 팀비 관리대장(xlsx) → 기안 월 지출 내역을 팀 테이블에 채운다.
  function fillTeamLedger(id, btn) {
    if (isComposeLocked()) { toastLocked(); return; }
    if (btn) btn.disabled = true;
    fetch('api/receipt_file', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'ledger_month', id: id, month: month, csrf: csrf })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || '채우기에 실패했습니다.');
        var rows = data.items || [];
        if (!rows.length) { toast('해당 월 내역이 없습니다.', month, 'danger'); return; }
        if (collectItems().length && !window.confirm(month + ' 내역으로 표를 채웁니다. 지금 입력한 줄을 대체할까요?')) return;
        fillItems(rows.map(function (r) {
          return {
            payment_date: r.payment_date || '',
            description: r.description || '',
            vendor: r.vendor || '',
            currency: 'KRW',
            amount_foreign: r.amount,
            amount_krw: r.amount,
            note: (r.note && r.note.trim()) ? r.note : '팀비'   // 프로젝트/비고 기본값
          };
        }));
        schedulePreview();
        toast(month + ' 내역 ' + rows.length + '줄을 채웠습니다.', '표에서 확인·수정하세요.');
      })
      .catch(function (err) { toast('채우기 실패', err.message || '', 'danger'); })
      .then(function () { if (btn) btn.disabled = false; });
  }

  // 인라인 SVG 아이콘(24 그리드, currentColor 스트로크 — 앱 공통 스타일)
  var RECEIPT_ICONS = {
    sparkles: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>',
    refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>'
  };

  function receiptActionBtn(icon, label, className) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'draft-receipt-files-act tooltip' + (className ? ' ' + className : '');
    b.setAttribute('data-tooltip', label);
    b.setAttribute('aria-label', label);
    b.innerHTML = RECEIPT_ICONS[icon];
    return b;
  }

  function renderReceiptFiles(container, files) {
    syncTopDownloadAll(files);
    container.innerHTML = '';
    if (!files.length) { container.hidden = true; return; }
    container.hidden = false;
    var selId = parseInt(root.getAttribute('data-selected') || '0', 10);

    var head = document.createElement('div');
    head.className = 'draft-receipt-files-head';
    var collapsed = container.classList.contains('is-collapsed');
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'draft-receipt-files-toggle';
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
      + '<span class="draft-receipt-files-title">첨부 파일 (' + files.length + ')</span>';
    toggle.addEventListener('click', function () {
      var nowCollapsed = container.classList.toggle('is-collapsed');
      toggle.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
    });
    head.appendChild(toggle);
    // 팀비 작성엔 AI 분석 버튼 없음. 작성중일 때만 여기, 완료면 상단(모두 펼치기 옆)으로.
    if (files.length > 1 && selId > 0 && compose.getAttribute('data-status') !== 'DONE') {
      var all = document.createElement('a');
      all.className = 'btn btn-outline btn-sm tooltip tooltip-bottom draft-receipt-files-bulk';
      all.href = 'api/receipt_file?action=download_all&template_id=' + selId + '&month=' + encodeURIComponent(month);
      all.setAttribute('data-tooltip', '첨부 파일을 한꺼번에 받습니다.');
      all.innerHTML = RECEIPT_ICONS.download + '<span>전체 파일 다운로드</span>';
      head.appendChild(all);
    }
    container.appendChild(head);

    var ul = document.createElement('ul');
    ul.className = 'draft-receipt-files-list';
    files.forEach(function (f) {
      var li = document.createElement('li');
      li.className = 'draft-receipt-files-item';

      var a = document.createElement('a');
      a.className = 'draft-receipt-files-name tooltip tooltip-bottom';
      a.href = 'api/receipt_file?action=download&id=' + f.id;
      a.textContent = f.display_name;
      a.setAttribute('data-tooltip', '다운로드');
      a.setAttribute('aria-label', f.display_name + ' 다운로드');
      li.appendChild(a);
      // 용량은 파일명 바로 옆
      var meta = document.createElement('span');
      meta.className = 'draft-receipt-files-meta';
      meta.textContent = formatBytes(f.size_bytes);
      li.appendChild(meta);

      // 우측 끝 액션 클러스터(대장 채우기 + 삭제). 팀비엔 AI 분석 버튼 없음.
      var actions = document.createElement('div');
      actions.className = 'draft-receipt-files-actions';
      if (f.is_ledger && !isComposeLocked()) {
        var fill = document.createElement('button');
        fill.type = 'button';
        fill.className = 'btn btn-secondary btn-sm draft-receipt-files-fill';
        fill.textContent = (month || '') + ' 내역 채우기';
        fill.addEventListener('click', function () { fillTeamLedger(f.id, fill); });
        actions.appendChild(fill);
      }
      if (f.can_delete) {
        var del = receiptActionBtn('trash', '삭제', 'is-danger');
        del.classList.add('tooltip-bottom');
        del.addEventListener('click', function () { deleteReceiptFile(f.id); });
        actions.appendChild(del);
      }
      li.appendChild(actions);

      ul.appendChild(li);
    });
    container.appendChild(ul);
  }

  /** 현재 (템플릿·월)의 서버 보관 첨부 파일 목록을 불러온다. 상태와 무관하게 노출(완료 후에도 다운로드 가능). */
  function loadReceiptFiles() {
    var container = document.getElementById('draft-receipt-files');
    if (!container) return;
    var id = parseInt(root.getAttribute('data-selected') || '0', 10);
    if (!(id > 0)) { container.hidden = true; container.innerHTML = ''; return; }
    fetch('api/receipt_file?action=list&template_id=' + id + '&month=' + encodeURIComponent(month), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { renderReceiptFiles(container, (data && data.files) || []); })
      .catch(function () { /* 목록 조회 실패는 조용히 무시 */ });
  }

  /* ── 첨부별 그룹 렌더(비팀 모드): 첨부 카드 안에 편집 항목행 ──────── */
  var receiptFilesCache = [];

  function isTeamMode() { return compose && compose.classList.contains('is-team'); }

  // items: draft 결제항목(각 receipt_id 포함) / files: receipt_file list 응답
  function renderAttachmentGroups(items, files) {
    var host = document.getElementById('draft-item-rows');
    if (!host) return;
    fillingRows = true;            // 행 생성 중 dirty/preview 억제(초기 로딩·재렌더)
    receiptFilesCache = files || [];
    syncTopDownloadAll(files);
    host.innerHTML = '';
    var selId = parseInt(root.getAttribute('data-selected') || '0', 10);
    if (files && files.length) host.appendChild(buildBulkBar(files, selId));

    var byReceipt = {};
    (items || []).forEach(function (it) {
      var k = (it.receipt_id != null && it.receipt_id !== '') ? String(it.receipt_id) : '';
      (byReceipt[k] = byReceipt[k] || []).push(it);
    });
    var known = {};
    (files || []).forEach(function (f) {
      known[String(f.id)] = true;
      host.appendChild(buildGroup(f, byReceipt[String(f.id)] || []));
    });
    // 첨부 없음 + 삭제된 첨부를 가리키는 항목 → 직접 추가 그룹.
    // 실제 항목이 있을 때만 노출(미작성/빈 상태는 업로드 영역만 보이게). 수동 입력은 상단 "+ 항목 추가"로 생성.
    var loose = (byReceipt[''] || []).slice();
    Object.keys(byReceipt).forEach(function (k) {
      if (k !== '' && !known[k]) loose = loose.concat(byReceipt[k]);
    });
    if (loose.length) {
      host.appendChild(buildGroup(null, loose));
    }

    fillingRows = false;
    renumberItems();
    applyDefaultCollapse();
  }

  function buildBulkBar(files, selId) {
    var allAnalyzed = files.every(function (f) { return f.analyzed; });
    var hasAnalyzed = files.some(function (f) { return f.analyzed; });
    var bar = document.createElement('div');
    bar.className = 'draft-attach-bar';
    var title = document.createElement('span');
    title.className = 'draft-attach-bar-title';
    title.textContent = '첨부 파일 (' + files.length + ')';
    bar.appendChild(title);
    var locked = isComposeLocked();   // 완료·마감월엔 분석 버튼 숨김(다운로드는 유지)
    // 전체 분석 — 아직 분석 안 된 파일만
    if (selId > 0 && !allAnalyzed && !locked) {
      var an = document.createElement('button');
      an.type = 'button';
      an.className = 'btn btn-secondary btn-sm tooltip tooltip-bottom draft-receipt-files-bulk';
      an.setAttribute('data-tooltip', '아직 분석하지 않은 첨부를 한꺼번에 분석합니다.');
      an.innerHTML = RECEIPT_ICONS.sparkles + '<span>전체 분석</span>';
      an.addEventListener('click', function () {
        an.disabled = true;
        files.forEach(function (f) { if (!f.analyzed) receiptAnalyzeFile(f.id, f.display_name, f.uploaded_name, false); });
      });
      bar.appendChild(an);
    }
    // 전체 재분석 — 분석된 것 포함 모두 다시(수정 항목 교체). 확인 1회. 잠금 시 숨김.
    if (selId > 0 && hasAnalyzed && !locked) {
      var re = document.createElement('button');
      re.type = 'button';
      re.className = 'btn btn-outline btn-sm tooltip tooltip-bottom draft-receipt-files-bulk';
      re.setAttribute('data-tooltip', '모든 첨부를 다시 분석합니다. 고친 항목은 사라집니다.');
      re.innerHTML = RECEIPT_ICONS.refresh + '<span>전체 재분석</span>';
      re.addEventListener('click', function () {
        if (!window.confirm('모든 첨부를 다시 분석합니다. 수정한 항목이 사라집니다. 계속할까요?')) return;
        re.disabled = true;
        files.forEach(function (f) { receiptAnalyzeFile(f.id, f.display_name, f.uploaded_name, true, null, true); });
      });
      bar.appendChild(re);
    }
    if (files.length > 1 && selId > 0 && allAnalyzed && compose.getAttribute('data-status') !== 'DONE') {
      var all = document.createElement('a');
      all.className = 'btn btn-outline btn-sm tooltip tooltip-bottom draft-receipt-files-bulk';
      all.href = 'api/receipt_file?action=download_all&template_id=' + selId + '&month=' + encodeURIComponent(month);
      all.setAttribute('data-tooltip', '첨부 파일을 한꺼번에 받습니다.');
      all.innerHTML = RECEIPT_ICONS.download + '<span>전체 파일 다운로드</span>';
      bar.appendChild(all);
    }
    return bar;
  }

  function buildGroup(file, groupItems) {
    var sec = document.createElement('section');
    sec.className = 'draft-attach-group';
    sec.setAttribute('data-receipt-id', file ? String(file.id) : '');
    sec.appendChild(buildGroupHead(file));
    var body = document.createElement('div');
    body.className = 'draft-attach-items';
    sec.appendChild(body);
    (groupItems || []).forEach(function (it) { addItemRow(it, body); });
    if (!isComposeLocked()) {
      var add = document.createElement('button');
      add.type = 'button';
      add.className = 'btn btn-ghost btn-sm draft-attach-additem';
      add.textContent = '+ 항목 추가';
      add.addEventListener('click', function () { addItemRow(null, body); schedulePreview(); });
      sec.appendChild(add);
    }
    return sec;
  }

  function buildGroupHead(file) {
    var head = document.createElement('div');
    head.className = 'draft-attach-head';
    if (!file) {
      var label = document.createElement('span');
      label.className = 'draft-attach-head-label';
      label.textContent = '📁 직접 추가 (첨부 없음)';
      head.appendChild(label);
      return head;
    }
    var a = document.createElement('a');
    a.className = 'draft-receipt-files-name tooltip tooltip-bottom';
    a.href = 'api/receipt_file?action=download&id=' + file.id;
    a.textContent = file.display_name;
    a.setAttribute('data-tooltip', '다운로드');
    a.setAttribute('aria-label', file.display_name + ' 다운로드');
    head.appendChild(a);
    // 용량은 파일명 바로 옆
    var meta = document.createElement('span');
    meta.className = 'draft-receipt-files-meta';
    meta.textContent = formatBytes(file.size_bytes);
    head.appendChild(meta);
    // 우측 끝 액션 클러스터(상태/분석/삭제 버튼)
    var actions = document.createElement('div');
    actions.className = 'draft-attach-head-actions';
    if (file.analyzed) {
      var done = document.createElement('span');
      done.className = 'draft-receipt-files-done tooltip tooltip-bottom';
      done.setAttribute('data-tooltip', 'AI 분석완료');
      done.setAttribute('aria-label', 'AI 분석완료');
      done.innerHTML = RECEIPT_ICONS.check;
      actions.appendChild(done);
    }
    // 완료·마감월엔 분석/삭제 숨김(조회·다운로드만).
    if (!isComposeLocked()) {
      var an = receiptActionBtn(file.analyzed ? 'refresh' : 'sparkles', file.analyzed ? '재분석' : 'AI 분석', file.analyzed ? '' : 'is-primary');
      an.classList.add('tooltip-bottom');
      an.addEventListener('click', function () { receiptAnalyzeFile(file.id, file.display_name, file.uploaded_name, file.analyzed, an); });
      actions.appendChild(an);
      if (file.can_delete) {
        var del = receiptActionBtn('trash', '삭제', 'is-danger');
        del.classList.add('tooltip-bottom');
        del.addEventListener('click', function () { deleteReceiptFile(file.id); });
        actions.appendChild(del);
      }
    }
    head.appendChild(actions);
    return head;
  }

  // "직접 추가" 그룹의 항목 컨테이너(없으면 만들어 붙임). 비팀 전역 +항목 추가용.
  function directAddBody() {
    var host = document.getElementById('draft-item-rows');
    if (!host) return null;
    var sec = host.querySelector('.draft-attach-group[data-receipt-id=""]');
    if (!sec) { sec = buildGroup(null, []); host.appendChild(sec); }
    return sec.querySelector('.draft-attach-items');
  }

  // 비팀 모드 초기/재로딩: 첨부 목록을 받아 그룹 렌더 후 미리보기 갱신
  function loadComposeGroups(items) {
    var id = parseInt(root.getAttribute('data-selected') || '0', 10);
    var done = function (files) { renderAttachmentGroups(items, files); schedulePreview(); };
    if (!(id > 0)) { done([]); return; }
    fetch('api/receipt_file?action=list&template_id=' + id + '&month=' + encodeURIComponent(month), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { done((data && data.files) || []); })
      .catch(function () { done([]); });
  }

  /* ── 영수증 업로드 로직 ──────────────────────────────────────────── */
  (function () {
    var receiptInput = document.getElementById('draft-receipt-input');
    var dropzone = root.querySelector('[data-receipt-dropzone]');
    var reviewList = root.querySelector('[data-receipt-review]');
    if (!receiptInput || !dropzone || !reviewList) return;

    /** 현재 선택된 템플릿의 데이터를 읽는다. */
    function currentTemplate() {
      var id = parseInt(root.getAttribute('data-selected') || '0', 10);
      return id > 0 ? findTemplate(id) : null;
    }

    /** 파일명 프리필: 템플릿 패턴 + 토큰 치환 + 배치 중복 번호 */
    function buildFilename(file, usedMap) {
      var tpl = currentTemplate();
      var pattern = (tpl && tpl.filename_pattern) || '';
      var userName = (root.getAttribute('data-user-name') || '').trim();
      var tokens = {
        year_month: month.replace(/-/g, ''),
        user: userName,
        title: (tpl && tpl.title) || '',
        vendor: (tpl && tpl.vendor) || '',
        assignee: (tpl && tpl.assignee) || ''
      };
      var base = sanitizeName(resolvePattern(pattern, tokens));
      var ext = extOf(file.name);
      return uniqueName(base, ext, usedMap);
    }

    /** 검토 행 생성 */
    function createReviewRow(file, filename) {
      var row = document.createElement('div');
      row.className = 'draft-receipt-row';
      row.setAttribute('data-receipt-row', '');
      // 헤더
      var head = document.createElement('div');
      head.className = 'draft-receipt-row-head';
      var nameSpan = document.createElement('span');
      nameSpan.className = 'draft-receipt-file-name';
      nameSpan.textContent = file.name;
      head.appendChild(nameSpan);
      row.appendChild(head);
      // 경고
      var warnEl = document.createElement('div');
      warnEl.className = 'draft-receipt-warnings';
      warnEl.hidden = true;
      row.appendChild(warnEl);
      // 로딩 표시
      var loadEl = document.createElement('div');
      loadEl.className = 'draft-receipt-loading';
      loadEl.textContent = 'AI 분석 중…';
      row.appendChild(loadEl);
      // 카드 영역 (성공 시 채워짐)
      var cardArea = document.createElement('div');
      cardArea.className = 'draft-receipt-card';
      row.appendChild(cardArea);
      // 확정 버튼 (숨김, 성공 시 표시)
      var appendBtn = document.createElement('button');
      appendBtn.className = 'btn btn-secondary btn-sm draft-receipt-append';
      appendBtn.type = 'button';
      appendBtn.textContent = '추가';
      appendBtn.hidden = true;
      row.appendChild(appendBtn);
      // 파일명 + 다운로드
      var fileRow = document.createElement('div');
      fileRow.className = 'draft-receipt-file-row';
      var fileInput = document.createElement('input');
      fileInput.type = 'text';
      fileInput.className = 'input draft-receipt-filename';
      fileInput.value = filename;
      fileInput.title = '다운로드할 파일명 (수정 가능)';
      var dlBtn = document.createElement('button');
      dlBtn.type = 'button';
      dlBtn.className = 'btn btn-outline btn-sm';
      dlBtn.textContent = '이름 바꿔 다운로드';
      dlBtn.addEventListener('click', function () {
        downloadRenamed(file, sanitizeName(fileInput.value) || filename);
      });
      fileRow.appendChild(fileInput);
      fileRow.appendChild(dlBtn);
      row.appendChild(fileRow);
      return { row: row, loadEl: loadEl, warnEl: warnEl, cardArea: cardArea, appendBtn: appendBtn, fileInput: fileInput };
    }

    /** AI 추출 항목 카드를 cardArea에 추가. addItemRow 패턴 재사용. */
    function prefillReceiptCard(cardArea, item, appendBtn) {
      // 분석 원본 보존: 일반 카드엔 거래처 칸이 없어도 append 시 fallback 으로 저장한다.
      cardArea._analyzed = item || {};
      // AI 배지 안내
      var badge = document.createElement('p');
      badge.className = 'draft-receipt-ai-badge';
      badge.innerHTML = '<span class="badge badge-brand">AI 추출 · 확인 필요</span>';
      cardArea.appendChild(badge);

      // 기존 addItemRow 로직을 이용해 카드를 새로 만들지 않고,
      // 카드 내 인라인 편집 필드를 직접 렌더링한다.
      var tpl = currentItemTpl();
      if (!tpl) return null;
      var node = tpl.content.firstElementChild.cloneNode(true);
      // remove/toggle 버튼 비활성: 영수증 행이 자체적으로 관리
      var toggleBtn = node.querySelector('[data-action="toggle-item"]');
      if (toggleBtn) toggleBtn.remove();
      var summaryBtn = node.querySelector('.draft-item-summary');
      if (summaryBtn) { summaryBtn.style.pointerEvents = 'none'; }
      var bar = node.querySelector('.draft-item-bar');
      if (bar) bar.hidden = true;
      cardArea.appendChild(node);
      bootRowSelects(node);
      // 값 채우기
      var dateEl = node.querySelector('[data-field="payment_date"]');
      var descEl = node.querySelector('[data-field="description"]');
      var vendorEl = node.querySelector('[data-field="vendor"]');
      var amtEl = node.querySelector('[data-field="amount"]');
      if (dateEl && item.payment_date) dateEl.value = item.payment_date;
      if (descEl && item.description) descEl.value = item.description;
      if (vendorEl && item.vendor) vendorEl.value = item.vendor;
      setRowSelect(node, 'currency', item.currency || 'KRW');
      if (amtEl && item.amount != null) amtEl.value = formatMoney(item.amount, (item.currency || 'KRW') === 'KRW');
      if (item.amount_krw != null) setRowKrw(node, item.amount_krw);
      bootRowDate(dateEl);
      if (dateEl && dateEl._adp && dateEl.value) dateEl._adp.selectDate(dateEl.value);
      syncRateBtn(node);
      syncAmountScale(node);
      syncItemSummary(node);
      appendBtn.hidden = false;
      return node;
    }

    /** 항목 데이터를 cardArea의 node에서 읽는다. */
    function readItemFromCard(cardArea) {
      var node = cardArea.querySelector('.draft-item-row');
      if (!node) return null;
      var a = cardArea._analyzed || {};
      // 카드 필드 값 우선, 비어있거나 필드 자체가 없으면 AI 추출값으로 보완(특히 일반 카드엔 거래처 칸 없음).
      function fld(name, fallback) {
        var el = node.querySelector('[data-field="' + name + '"]');
        var v = el ? String(el.value || '').trim() : '';
        return v !== '' ? v : (fallback != null ? String(fallback).trim() : '');
      }
      return {
        payment_date: fld('payment_date', a.payment_date),
        description: fld('description', a.description),
        vendor: fld('vendor', a.vendor),
        currency: fld('currency', a.currency) || 'KRW',
        amount: parseMoney((node.querySelector('[data-field="amount"]') || {}).value) || (a.amount != null ? a.amount : 0)
      };
    }

    /** 파일을 서버에 저장만 한다(원본 이름으로 저장; 파일명은 AI 분석 후에 맞춰 변경). */
    function storeFile(file) {
      var tpl = currentTemplate();
      var formData = new FormData();
      formData.append('file', file);
      formData.append('template_id', String((tpl && tpl.id) || ''));
      formData.append('month', month);
      formData.append('display_name', file.name);
      formData.append('user_name', (root.getAttribute('data-user-name') || '').trim());
      formData.append('csrf', csrf);
      return fetch('api/receipt', { method: 'POST', credentials: 'same-origin', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || '업로드에 실패했습니다.');
        })
        .catch(function (err) {
          toast('업로드 실패', (file.name || '') + ' — ' + (err.message || ''), 'danger');
        });
    }

    /** 서버 저장 파일의 AI 분석 결과(한 항목)를 검토 카드로 만든다. */
    function addAnalyzedReview(item, sourceName, cached) {
      var row = document.createElement('div');
      row.className = 'draft-receipt-row';
      var head = document.createElement('div');
      head.className = 'draft-receipt-row-head';
      var nameSpan = document.createElement('span');
      nameSpan.className = 'draft-receipt-file-name';
      nameSpan.textContent = (sourceName || 'AI 분석') + (cached ? ' · 저장된 분석 · 확인 필요' : ' · AI 추출 · 확인 필요');
      head.appendChild(nameSpan);
      row.appendChild(head);
      var cardArea = document.createElement('div');
      cardArea.className = 'draft-receipt-card';
      row.appendChild(cardArea);
      var appendBtn = document.createElement('button');
      appendBtn.type = 'button';
      appendBtn.className = 'btn btn-secondary btn-sm draft-receipt-append';
      appendBtn.textContent = '추가';
      appendBtn.hidden = true;
      row.appendChild(appendBtn);
      prefillReceiptCard(cardArea, item || {}, appendBtn);
      appendBtn.addEventListener('click', function () { appendItemToServer(cardArea, appendBtn, row); });
      reviewList.appendChild(row);
    }

    // 파일 목록의 "AI 분석" 버튼 → 서버 저장 파일을 분석해 검토 카드 생성(모듈 브리지에 할당)
    receiptAnalyzeFile = function (id, name, uploaderName, refresh, btn, skipConfirm) {
      var tpl = currentTemplate();
      if (!tpl) { toast('템플릿을 먼저 선택하세요.', '', 'danger'); return; }
      if (isComposeLocked()) { toastLocked(); return; }
      // 재분석: 편집된 항목이 있으면 API 호출 전에 확인(교체 예정). 일괄 재분석은 확인 1회이므로 skip.
      if (refresh && !isTeamMode() && !skipConfirm) {
        var secChk = document.querySelector('.draft-attach-group[data-receipt-id="' + id + '"]');
        if (secChk && secChk.querySelector('.draft-item-row') &&
            !window.confirm('이 첨부의 항목을 다시 추출합니다. 수정한 내용이 사라집니다. 계속할까요?')) return;
      }
      if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
      setAnalyzing(1);
      fetch('api/receipt_file', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'analyze', template_id: parseInt(tpl.id, 10), month: month, id: id, refresh: !!refresh, csrf: csrf })
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || '분석에 실패했습니다.');
          var extracted = data.items || [];
          if (!extracted.length) { toast('추출 결과가 없습니다.', name || '', 'danger'); return; }
          var mapIt = function (it) {
            return {
              payment_date: it.payment_date || '',
              description: it.description || '',
              vendor: it.vendor || '',
              currency: it.currency || 'KRW',
              amount_foreign: it.amount,
              amount_krw: it.amount_krw
            };
          };
          if (!isTeamMode()) {
            // 비팀: 해당 첨부 그룹 안에 항목행 생성(재분석이면 교체) + 헤더 갱신(리네임·분석완료).
            var sec = document.querySelector('.draft-attach-group[data-receipt-id="' + id + '"]');
            var body = sec ? sec.querySelector('.draft-attach-items') : null;
            if (body && refresh) body.innerHTML = '';
            extracted.forEach(function (it) { addItemRow(mapIt(it), body || undefined); });
            if (sec) {
              var cached = receiptFilesCache.filter(function (f) { return String(f.id) === String(id); })[0];
              if (!cached) { cached = { id: id }; receiptFilesCache.push(cached); }
              cached.display_name = data.display_name || cached.display_name || name;
              cached.analyzed = true;
              if (cached.can_delete == null) cached.can_delete = true;
              cached.uploaded_name = cached.uploaded_name || uploaderName;
              var oldHead = sec.querySelector('.draft-attach-head');
              if (oldHead) sec.replaceChild(buildGroupHead(cached), oldHead);
            }
          } else {
            extracted.forEach(function (it) { addItemRow(mapIt(it)); });
          }
          schedulePreview();
          toast('AI 분석 항목을 추가했습니다.', (name || '') + ' — 항목에서 확인·수정하세요.');
          if (data.warnings && data.warnings.length) toast('분석 경고', data.warnings.join(' / '));
          if (isTeamMode() && !data.cached) loadReceiptFiles();  // 팀은 별도 파일목록 갱신
        })
        .catch(function (err) { toast('AI 분석 실패', err.message || '', 'danger'); })
        .then(function () {
          setAnalyzing(-1);
          if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
        });
    };

    /** append-item POST 후 항목 목록/합계 갱신 */
    function appendItemToServer(cardArea, appendBtn, row) {
      var tpl = currentTemplate();
      if (!tpl) { toast('템플릿을 먼저 선택하세요.', '', 'danger'); return; }
      var item = readItemFromCard(cardArea);
      if (!item || !(item.amount > 0)) {
        toast('금액을 입력하세요.', '통화와 금액은 필수입니다.', 'danger');
        return;
      }
      var templateId = parseInt(tpl.id, 10);
      appendBtn.disabled = true;
      fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          action: 'append-item',
          template_id: templateId,
          month: month,
          item: item,
          csrf: csrf
        })
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || '저장에 실패했습니다.');
          // row.items 로 목록/합계 갱신
          var serverRow = data.row;
          if (serverRow) {
            upsertDraft(serverRow);
            setListBadge(serverRow.expense_template_id, serverRow);
            // 항목 목록 교체 (replaceItems 경로 제외, fillItems 재사용)
            fillItems(serverRow.items || []);
            if (itemSum) {
              itemSum.textContent = Number(serverRow.amount_krw || 0).toLocaleString('ko-KR');
            }
            refreshMonthSummary();
            schedulePreview();
          }
          // 이 행을 완료 표시
          row.classList.add('is-done');
          appendBtn.hidden = true;
          var doneMsg = document.createElement('p');
          doneMsg.className = 'draft-receipt-done';
          doneMsg.textContent = '추가됨';
          row.appendChild(doneMsg);
          toast('항목을 추가했습니다.', (item.description || '') + ' ' + (item.amount || '') + ' ' + (item.currency || ''));
        })
        .catch(function (err) {
          toast('항목 추가 실패', err.message || '', 'danger');
        })
        .then(function () {
          appendBtn.disabled = false;
        });
    }

    /** 파일 배치 업로드(서버 저장만). 분석은 아래 첨부 목록의 "AI 분석"으로. */
    function handleFiles(fileList) {
      if (!fileList || !fileList.length) return;
      if (isComposeLocked()) { toastLocked(); return; }
      var tpl = currentTemplate();
      if (!tpl) { toast('템플릿을 먼저 선택하세요.', '왼쪽에서 템플릿을 고른 뒤 업로드하세요.', 'danger'); return; }
      var jobs = [];
      for (var i = 0; i < fileList.length; i++) { jobs.push(storeFile(fileList[i])); }
      Promise.all(jobs).then(function () {
        // 비팀: 편집 항목을 보존하며 새 첨부 그룹을 반영. 팀: 기존 파일목록 갱신.
        if (isTeamMode()) loadReceiptFiles();
        else loadComposeGroups(snapshotItems());
        toast('업로드했습니다.', '첨부 카드의 “AI 분석”으로 내용을 채우세요.');
      });
    }

    receiptInput.addEventListener('change', function () {
      handleFiles(receiptInput.files);
      receiptInput.value = '';
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
      var dt = e.dataTransfer;
      if (dt && dt.files) handleFiles(dt.files);
    });
  })();
  /* ─────────────────────────────────────────────────────────────────── */

  var preselect = parseInt(root.getAttribute('data-selected') || '0', 10);
  if (preselect > 0) {
    renderPane(findTemplate(preselect));
  }
})();
