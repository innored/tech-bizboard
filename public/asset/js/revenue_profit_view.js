/* 수익 화면(카드·접기 표·모두 펼치기·CSV). 매출(수입) 수익 탭과 리더십 페이지가 같이 쓴다.
   계산은 RevenueProfit(revenue_profit.js), 이 파일은 그리기와 화면 동작만 맡는다.

   var view = RevenueProfitView.mount(panelEl, { today: 'YYYY-MM-DD', onRowClick: function (row) {} });
   view.render(rows, '2026');   // rows = 그 해 수입 전체(무상 포함). 유상만 골라 그린다. */
(function (root) {
  'use strict';

  var GROUPS = [
    { key: 'ADTECH', label: '애드테크 솔루션', cls: 'is-adtech' },
    { key: 'GENERAL', label: '일반 솔루션', cls: 'is-general' },
    { key: 'PROJECT', label: '용역', cls: 'is-project' },
    { key: '', label: '미분류', cls: 'is-none' }
  ];
  var GROUP_CLASS = { ADTECH: 'is-adtech', GENERAL: 'is-general', PROJECT: 'is-project', '': 'is-none' };
  var COLS = 10;
  var EMPTY_TEXT = '유상 수입이 없습니다.';
  var CHEVRON = '<svg class="rev-profit-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>';

  function fmt(n) {
    return Number(n || 0).toLocaleString('ko-KR');
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function toast(title, body, kind) {
    if (typeof root.tbbToast === 'function') root.tbbToast(title, body, kind);
  }

  /* 수익 금액 칸. 마이너스는 빨간색 */
  function moneyHtml(n) {
    return n < 0 ? '<span class="rev-profit-warn">' + fmt(n) + '</span>' : fmt(n);
  }

  function mount(panel, options) {
    var api = root.RevenueProfit;
    var opts = options || {};
    var rowsCache = [];
    var open = {}; // 'g:그룹키' / 'p:프로젝트명' → true(펼침). 기본은 모두 접힘, 다시 그려도 유지
    if (!panel || !api) return { render: function () {}, rows: function () { return []; } };

    function $(id) { return panel.querySelector('#' + id); }
    function body() { return $('rev-profit-body'); }

    function setCard(id, values) {
      var card = $(id);
      if (!card) return;
      Object.keys(values).forEach(function (key) {
        var el = card.querySelector('[data-profit="' + key + '"]');
        if (el) el.textContent = values[key];
      });
    }

    /* 그룹별 수익 비중: 막대 조각 너비 + 그룹 이름 옆 %. 마이너스 수익 그룹은 0으로 본다 */
    var SHARE_CARDS = { ADTECH: 'rev-profit-adtech', GENERAL: 'rev-profit-general', PROJECT: 'rev-profit-project' };
    var SHARE_NAMES = { ADTECH: '애드테크 솔루션', GENERAL: '일반 솔루션', PROJECT: '용역' };
    function renderShares(profits) {
      var keys = Object.keys(SHARE_CARDS);
      var total = keys.reduce(function (acc, k) { return acc + Math.max(0, profits[k] || 0); }, 0);
      var bar = $('rev-profit-bar');
      var spoken = [];
      keys.forEach(function (k) {
        var pct = total > 0 ? Math.max(0, profits[k] || 0) / total * 100 : 0;
        var label = total > 0 ? (pct > 0 && pct < 1 ? '1% 미만' : Math.round(pct) + '%') : '';
        setCard(SHARE_CARDS[k], { share: label });
        if (bar) {
          var seg = bar.querySelector('[data-seg="' + k + '"]');
          if (seg) {
            seg.style.width = pct + '%';
            seg.hidden = pct === 0;
          }
        }
        if (label) spoken.push(SHARE_NAMES[k] + ' ' + label);
      });
      if (bar) {
        bar.hidden = total === 0;
        bar.setAttribute('aria-label', '그룹별 수익 비중: ' + spoken.join(', '));
      }
    }

    function toggleAttr(key) {
      return ' data-profit-toggle="' + esc(key) + '" tabindex="0" role="button" aria-expanded="' + (open[key] ? 'true' : 'false') + '"';
    }

    function parentAttr(key) {
      return key ? ' data-profit-parent="' + esc(key) + '"' : '';
    }

    function rowHtml(row, parentKey) {
      var g = api.group(row);
      var spend = Number(row.campaign_spend_krw || 0);
      var spendCell = '';
      var feeCell = '';
      var rateCell = '';
      var costCell = '';
      var svc = row.solution_name || '';
      if (g === 'ADTECH') {
        spendCell = spend > 0 ? fmt(spend) : '<span class="rev-profit-warn">미입력</span>';
        feeCell = spend > 0 ? api.formatFee(row.tech_fee_rate) : '-';
        rateCell = api.formatRate(api.rowRate(row));
      } else if (g === 'GENERAL') {
        rateCell = api.formatRate(100);
      } else if (g === 'PROJECT') {
        svc = row.service_category || '';
        costCell = '<span title="' + esc(row.cost_note || '') + '">' + fmt(row.cost_krw || 0) + '</span>';
        rateCell = api.formatRate(api.rowRate(row));
      } else {
        svc = row.solution_name || row.service_category || '';
      }
      return (
        '<tr class="rev-pt-row rev-profit-row ' + (GROUP_CLASS[g] || 'is-none') + (String(parentKey).indexOf('p:') === 0 ? ' is-nested' : '') + (opts.onRowClick ? ' is-click' : '') + '" data-id="' + esc(String(row.id || '')) + '"' + parentAttr(parentKey) + '>' +
          '<td class="col-date">' + esc(row.received_date || '') + '</td>' +
          '<td class="col-project"><span class="rev-ellip" title="' + esc(row.project_name || '') + '">' + esc(row.project_name || '') + '</span></td>' +
          '<td class="col-client">' + esc(row.client_name || '') + '</td>' +
          '<td class="col-svc">' + esc(svc) + '</td>' +
          '<td class="num col-money">' + spendCell + '</td>' +
          '<td class="num col-rate">' + esc(feeCell) + '</td>' +
          '<td class="num col-money">' + fmt(row.supply_krw) + '</td>' +
          '<td class="num col-money">' + costCell + '</td>' +
          '<td class="num col-money">' + moneyHtml(api.rowProfit(row)) + '</td>' +
          '<td class="num col-rate">' + esc(rateCell) + '</td>' +
        '</tr>'
      );
    }

    function groupHeadHtml(spec, rows) {
      var sum = api.summarize(rows);
      var sales = rows.reduce(function (acc, r) { return acc + Number(r.supply_krw || 0); }, 0);
      var profit = rows.reduce(function (acc, r) { return acc + api.rowProfit(r); }, 0);
      var rate = '';
      if (spec.key === 'ADTECH') rate = api.formatRate(api.rate(sum.adtech.rated_profit, sum.adtech.spend));
      else if (spec.key === 'GENERAL') rate = api.formatRate(100);
      else if (spec.key === 'PROJECT') rate = api.formatRate(api.rate(sum.project.profit, sum.project.sales));
      return (
        '<tr class="rev-pt-group rev-profit-toggle ' + spec.cls + '"' + toggleAttr('g:' + spec.key) + '>' +
          '<td class="col-date">' + CHEVRON + '</td>' +
          '<td class="col-label" colspan="3"><span class="rev-pt-label"><span class="rev-pf-dot" aria-hidden="true"></span>' + esc(spec.label) + '<span class="rev-pt-count">' + rows.length + '건</span></span></td>' +
          '<td class="num col-money">' + (sum.adtech.spend ? fmt(sum.adtech.spend) : '') + '</td>' +
          '<td class="num col-rate"></td>' +
          '<td class="num col-money">' + fmt(sales) + '</td>' +
          '<td class="num col-money">' + (spec.key === 'PROJECT' ? fmt(sum.project.cost) : '') + '</td>' +
          '<td class="num col-money">' + moneyHtml(profit) + '</td>' +
          '<td class="num col-rate">' + esc(rate) + '</td>' +
        '</tr>'
      );
    }

    /* 용역 프로젝트 소계 */
    function subtotalHtml(t) {
      return (
        '<tr class="rev-pt-sub rev-profit-toggle is-project"' + toggleAttr('p:' + t.name) + parentAttr('g:PROJECT') + '>' +
          '<td class="col-date">' + CHEVRON + '</td>' +
          '<td class="col-label" colspan="3"><span class="rev-pt-label"><span class="rev-ellip" title="' + esc(t.name) + '">' + esc(t.name || '(프로젝트명 없음)') + '</span><span class="rev-pt-count">' + t.count + '건</span></span></td>' +
          '<td class="num col-money"></td>' +
          '<td class="num col-rate"></td>' +
          '<td class="num col-money">' + fmt(t.sales) + '</td>' +
          '<td class="num col-money">' + fmt(t.cost) + '</td>' +
          '<td class="num col-money">' + moneyHtml(t.profit) + '</td>' +
          '<td class="num col-rate">' + esc(api.formatRate(api.rate(t.profit, t.sales))) + '</td>' +
        '</tr>'
      );
    }

    /* 부모가 펼쳐져 있고 부모 자신도 보일 때만 보인다(그룹 → 프로젝트 → 행) */
    function applyCollapse() {
      var b = body();
      if (!b) return;
      var shown = {};
      Array.prototype.forEach.call(b.querySelectorAll('tr'), function (tr) {
        var parent = tr.getAttribute('data-profit-parent');
        var visible = !parent || shown[parent] === true;
        tr.hidden = !visible;
        var key = tr.getAttribute('data-profit-toggle');
        if (key) {
          var isOpen = !!open[key];
          tr.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          tr.classList.toggle('is-open', isOpen);
          shown[key] = visible && isOpen;
        }
      });
      syncToggleAll();
    }

    function toggleKeys() {
      var b = body();
      if (!b) return [];
      return Array.prototype.map.call(b.querySelectorAll('[data-profit-toggle]'), function (tr) {
        return tr.getAttribute('data-profit-toggle');
      });
    }

    function isAllOpen() {
      var keys = toggleKeys();
      return keys.length > 0 && keys.every(function (k) { return !!open[k]; });
    }

    /* 하나라도 접혀 있으면 '모두 펼치기', 다 펼쳐져 있으면 '모두 접기' */
    function syncToggleAll() {
      var btn = $('rev-profit-toggle-all');
      if (!btn) return;
      var allOpen = isAllOpen();
      var label = btn.querySelector('.rev-profit-toggle-label');
      if (label) label.textContent = allOpen ? '모두 접기' : '모두 펼치기';
      var expandIcon = btn.querySelector('[data-icon="expand"]');
      var collapseIcon = btn.querySelector('[data-icon="collapse"]');
      if (expandIcon) expandIcon.hidden = allOpen;
      if (collapseIcon) collapseIcon.hidden = !allOpen;
      btn.setAttribute('aria-expanded', allOpen ? 'true' : 'false');
      btn.disabled = toggleKeys().length === 0;
    }

    function toggleAll() {
      if (isAllOpen()) {
        open = {};
      } else {
        toggleKeys().forEach(function (k) { open[k] = true; });
      }
      applyCollapse();
    }

    function toggleRow(tr) {
      var key = tr.getAttribute('data-profit-toggle');
      if (!key) return;
      open[key] = !open[key];
      applyCollapse();
    }

    function download() {
      if (!rowsCache.length) {
        toast('다운로드 안 됨', EMPTY_TEXT, 'danger');
        return;
      }
      var blob = new Blob([api.toCsv(rowsCache)], { type: 'text/csv;charset=utf-8' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = '테크본부_수익_' + String(opts.today || '').replace(/-/g, '') + '.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function render(rows, year) {
      var target = api.targetRows(rows).filter(function (r) {
        return String(r.target_year_month || '').indexOf(String(year) + '-') === 0;
      });
      rowsCache = target;
      var sum = api.summarize(target);
      setCard('rev-profit-adtech', {
        profit: fmt(sum.adtech.profit),
        rate: api.formatRate(api.rate(sum.adtech.rated_profit, sum.adtech.spend)),
        base: fmt(sum.adtech.spend),
        count: fmt(sum.adtech.count)
      });
      var missing = panel.querySelector('#rev-profit-adtech [data-profit="missing"]');
      if (missing) {
        missing.hidden = sum.adtech.missing_spend_count === 0;
        missing.textContent = '집행금액 미입력 ' + sum.adtech.missing_spend_count + '건은 수익률에서 제외';
      }
      setCard('rev-profit-general', {
        profit: fmt(sum.general.profit),
        rate: api.formatRate(sum.general.count ? 100 : null),
        base: fmt(sum.general.sales),
        count: fmt(sum.general.count)
      });
      setCard('rev-profit-project', {
        profit: fmt(sum.project.profit),
        rate: api.formatRate(api.rate(sum.project.profit, sum.project.sales)),
        base: fmt(sum.project.sales),
        cost: fmt(sum.project.cost),
        count: fmt(sum.project.count)
      });
      setCard('rev-profit-total', {
        profit: fmt(sum.total.profit),
        rate: api.formatRate(api.rate(sum.total.rated_profit, sum.total.base)),
        count: fmt(sum.adtech.count + sum.general.count + sum.project.count)
      });
      renderShares({ ADTECH: sum.adtech.profit, GENERAL: sum.general.profit, PROJECT: sum.project.profit });
      var note = $('rev-profit-note');
      if (note) {
        note.hidden = sum.unclassified_count === 0;
        note.textContent = '분류가 안 된 유상 ' + sum.unclassified_count + '건은 합계에서 빠집니다(솔루션구분 또는 서비스구분 미지정).';
      }
      var b = body();
      if (!b) return;
      if (!target.length) {
        b.innerHTML = '<tr class="rev-empty-row"><td class="rev-empty-cell" colspan="' + COLS + '">' + EMPTY_TEXT + '</td></tr>';
        syncToggleAll();
        return;
      }
      var ordered = api.orderRows(target);
      var html = '';
      GROUPS.forEach(function (spec) {
        var groupRows = ordered.filter(function (r) { return api.group(r) === spec.key; });
        if (!groupRows.length) return;
        html += groupHeadHtml(spec, groupRows);
        var groupKey = 'g:' + spec.key;
        if (spec.key !== 'PROJECT') {
          html += groupRows.map(function (r) { return rowHtml(r, groupKey); }).join('');
          return;
        }
        api.projectTotals(groupRows).forEach(function (t) {
          html += subtotalHtml(t) + groupRows.filter(function (r) {
            return String(r.project_name || '').trim() === t.name;
          }).map(function (r) { return rowHtml(r, 'p:' + t.name); }).join('');
        });
      });
      b.innerHTML = html;
      applyCollapse();
    }

    function rowById(id) {
      var found = null;
      rowsCache.forEach(function (r) { if (String(r.id) === String(id)) found = r; });
      return found;
    }

    panel.addEventListener('click', function (e) {
      if (e.target.closest('[data-action="profit-toggle-all"]')) {
        toggleAll();
        return;
      }
      if (e.target.closest('[data-action="download-profit"]')) {
        download();
        return;
      }
      var toggle = e.target.closest('[data-profit-toggle]');
      if (toggle) {
        toggleRow(toggle);
        return;
      }
      var tr = e.target.closest('tr.rev-profit-row[data-id]');
      if (tr && opts.onRowClick) {
        var row = rowById(tr.getAttribute('data-id'));
        if (row) opts.onRowClick(row);
      }
    });

    /* 접기 줄: Enter·Space로도 펼친다 */
    panel.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var tr = e.target.closest && e.target.closest('[data-profit-toggle]');
      if (!tr) return;
      e.preventDefault();
      toggleRow(tr);
    });

    return {
      render: render,
      rows: function () { return rowsCache; }
    };
  }

  root.RevenueProfitView = { mount: mount };
})(typeof globalThis !== 'undefined' ? globalThis : this);
