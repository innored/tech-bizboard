/* 지출 템플릿 설정 */
(function () {
  'use strict';

  var root = document.querySelector('[data-settings]');
  if (!root) return;

  var apiUrl = root.getAttribute('data-api') || 'api/templates';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var form = document.getElementById('tpl-form');
  var idle = document.getElementById('tpl-idle');
  var filter = document.getElementById('tpl-filter');
  var activeLabel = document.getElementById('tpl-active-label');
  var createdByEl = document.getElementById('tpl-created-by');
  var meta = document.getElementById('tpl-meta');
  var filterEmpty = document.getElementById('tpl-filter-empty');
  var storeEl = document.getElementById('tpl-store');
  var rows = [];
  try { rows = JSON.parse(storeEl ? storeEl.textContent : '[]') || []; } catch (e) { rows = []; }

  function toast(title, body, kind) {
    if (typeof tbbToast === 'function') tbbToast(title, body, kind);
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

  function findRow(id) {
    var n = parseInt(id, 10);
    for (var i = 0; i < rows.length; i++) {
      if (parseInt(rows[i].id, 10) === n) return rows[i];
    }
    return null;
  }

  function setSelected(id) {
    root.querySelectorAll('.tpl-item').forEach(function (btn) {
      var on = btn.getAttribute('data-id') === String(id);
      btn.classList.toggle('is-selected', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function showEditor(on) {
    idle.hidden = on;
    form.hidden = !on;
  }

  function syncActiveLabel() {
    if (!activeLabel) return;
    activeLabel.textContent = form.elements.is_active.checked ? '사용' : '중지';
  }

  function setListActive(id, on) {
    var item = root.querySelector('.tpl-item[data-id="' + String(id) + '"]');
    if (!item) return;
    var badge = item.querySelector('.badge');
    if (!badge) return;
    badge.className = on ? 'badge badge-success' : 'badge badge-muted';
    badge.textContent = on ? '사용' : '중지';
  }

  function setSelectValue(name, value) {
    var hidden = form.elements[name];
    if (!hidden) return;
    var wrap = hidden.closest('[data-select]');
    if (!wrap) {
      hidden.value = value;
      return;
    }
    var valueEl = wrap.querySelector('.select-value');
    var match = null;
    wrap.querySelectorAll('.select-option').forEach(function (opt) {
      var on = (opt.getAttribute('data-value') || '') === String(value);
      opt.classList.toggle('is-selected', on);
      opt.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) match = opt;
    });
    hidden.value = value;
    if (!valueEl) return;
    if (match) {
      var label = '';
      match.childNodes.forEach(function (node) {
        if (node.nodeType === 3) label += node.textContent;
      });
      valueEl.textContent = label.trim() || match.textContent.trim();
      valueEl.classList.remove('is-placeholder');
      return;
    }
    valueEl.textContent = value;
  }

  function resetForm() {
    form.reset();
    form.elements.id.value = '';
    form.elements.is_active.checked = true;
    form.elements.content_include_vendor.checked = true;
    syncActiveLabel();
    setSelectValue('cycle_type', 'MONTHLY');
    setSelectValue('payment_type', '');
    if (form.elements.next_renewal_date._adp) {
      form.elements.next_renewal_date._adp.clear();
    }
    if (meta) meta.open = false;
    setCreatedBy('');
  }

  function setCreatedBy(email) {
    if (!createdByEl) return;
    var value = (email || '').trim();
    createdByEl.hidden = !value;
    createdByEl.textContent = value ? ('작성자 ' + value) : '';
  }

  function fillForm(row) {
    form.elements.id.value = row.id || '';
    form.elements.title.value = row.title || '';
    form.elements.assignee.value = row.assignee || '';
    form.elements.account_info.value = row.account_info || '';
    form.elements.payment_site.value = row.payment_site || '';
    setSelectValue('cycle_type', row.cycle_type || 'MONTHLY');
    setSelectValue('payment_type', row.payment_type || '');
    form.elements.title_pattern.value = row.title_pattern || '';
    form.elements.body_pattern.value = row.body_pattern || '';
    form.elements.vendor.value = row.vendor || '';
    form.elements.period_pattern.value = row.period_pattern || '';
    form.elements.payment_method_text.value = row.payment_method_text || '';
    form.elements.pay_request_pattern.value = row.pay_request_pattern || '';
    form.elements.attachment_text.value = row.attachment_text || '';
    form.elements.note.value = row.note || '';
    form.elements.is_active.checked = Number(row.is_active) === 1;
    form.elements.content_include_vendor.checked = Number(row.content_include_vendor) !== 0;
    syncActiveLabel();
    var renewal = (row.next_renewal_date || '').toString().slice(0, 10);
    form.elements.next_renewal_date.value = renewal;
    if (form.elements.next_renewal_date._adp && renewal) {
      form.elements.next_renewal_date._adp.selectDate(renewal);
    } else if (form.elements.next_renewal_date._adp && !renewal) {
      form.elements.next_renewal_date._adp.clear();
    }
    setCreatedBy(row.created_by || '');
    if (meta) meta.open = false;
  }

  function insertToken(targetId, token, fill) {
    var el = document.getElementById(targetId);
    if (!el) return;
    el.focus();
    if (fill) {
      el.value = token;
      if (el.setSelectionRange) el.setSelectionRange(token.length, token.length);
      return;
    }
    var start = el.selectionStart || el.value.length;
    var end = el.selectionEnd || start;
    el.value = el.value.slice(0, start) + token + el.value.slice(end);
    var pos = start + token.length;
    if (el.setSelectionRange) el.setSelectionRange(pos, pos);
  }

  function scrollEditorIntoView() {
    if (!window.matchMedia('(max-width: 900px)').matches) return;
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var header = document.querySelector('.app-header');
    var offset = (header ? header.getBoundingClientRect().height : 0) + 12;
    var top = form.getBoundingClientRect().top + window.scrollY - offset;
    window.scrollTo({ top: Math.max(0, top), behavior: reduce ? 'auto' : 'smooth' });
  }

  root.addEventListener('click', function (e) {
    var insert = e.target.closest('[data-insert]');
    if (insert) {
      insertToken(insert.getAttribute('data-target'), insert.getAttribute('data-insert'), insert.getAttribute('data-fill') === '1');
      return;
    }
    var neu = e.target.closest('[data-action="new"]');
    if (neu) {
      resetForm();
      setSelected('');
      showEditor(true);
      history.replaceState(null, '', 'settings');
      form.elements.title.focus();
      scrollEditorIntoView();
      return;
    }
    var cancel = e.target.closest('[data-action="cancel"]');
    if (cancel) {
      resetForm();
      setSelected('');
      showEditor(false);
      history.replaceState(null, '', 'settings');
      return;
    }
    var edit = e.target.closest('[data-action="edit"]');
    if (edit) {
      var row = findRow(edit.getAttribute('data-id'));
      if (!row) return;
      fillForm(row);
      setSelected(row.id);
      showEditor(true);
      history.replaceState(null, '', 'settings?id=' + encodeURIComponent(row.id));
      scrollEditorIntoView();
      return;
    }
  });

  form.elements.is_active.addEventListener('change', function () {
    syncActiveLabel();
    var id = parseInt(form.elements.id.value || '0', 10);
    if (!(id > 0)) return;

    var on = form.elements.is_active.checked;
    var input = form.elements.is_active;
    input.disabled = true;
    post({ action: 'active', id: id, is_active: on ? 1 : 0 })
      .then(function () {
        var row = findRow(id);
        if (row) row.is_active = on ? 1 : 0;
        setListActive(id, on);
        toast(on ? '사용으로 바꿨습니다.' : '중지했습니다.');
      })
      .catch(function (err) {
        input.checked = !on;
        syncActiveLabel();
        toast('바꾸지 못했습니다.', err.message || '', 'danger');
      })
      .then(function () {
        input.disabled = false;
      });
  });

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

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    var data = {
      action: form.elements.id.value ? 'update' : 'create',
      id: form.elements.id.value ? parseInt(form.elements.id.value, 10) : 0,
      title: form.elements.title.value,
      assignee: form.elements.assignee.value,
      account_info: form.elements.account_info.value,
      payment_site: form.elements.payment_site.value,
      cycle_type: form.elements.cycle_type.value,
      payment_type: form.elements.payment_type.value,
      next_renewal_date: form.elements.next_renewal_date.value,
      title_pattern: form.elements.title_pattern.value,
      body_pattern: form.elements.body_pattern.value,
      vendor: form.elements.vendor.value,
      period_pattern: form.elements.period_pattern.value,
      payment_method_text: form.elements.payment_method_text.value,
      pay_request_pattern: form.elements.pay_request_pattern.value,
      attachment_text: form.elements.attachment_text.value,
      note: form.elements.note.value,
      is_active: form.elements.is_active.checked ? 1 : 0,
      content_include_vendor: form.elements.content_include_vendor.checked ? 1 : 0
    };
    post(data).then(function (res) {
      var id = (res.row && res.row.id) || data.id;
      if (typeof tbbToastFlash === 'function') {
        tbbToastFlash('저장했습니다.', '템플릿을 반영했습니다.');
      }
      location.href = 'settings?id=' + encodeURIComponent(id);
    }).catch(function (err) {
      toast('저장하지 못했습니다.', err.message || '', 'danger');
    });
  });

  var preselect = parseInt(root.getAttribute('data-selected') || '0', 10);
  if (preselect > 0) {
    var pre = findRow(preselect);
    if (pre) {
      fillForm(pre);
      setSelected(pre.id);
      showEditor(true);
    }
  }
})();
