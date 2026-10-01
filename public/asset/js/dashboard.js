/* 손익 대시보드 */
(function () {
  'use strict';

  var root = document.querySelector('[data-dashboard]');
  if (!root) return;

  var apiUrl = root.getAttribute('data-api') || 'api/dashboard';
  var yearEl = document.getElementById('dash-year');
  var chartEl = document.getElementById('dash-chart');
  var expDonutEl = document.getElementById('dash-exp-donut');
  var revDonutEl = document.getElementById('dash-rev-donut');
  var series = [];
  var chart = null;
  var expDonut = null;
  var revDonut = null;
  var currentYear = parseInt(root.getAttribute('data-year') || '', 10);
  var currentQuarter = parseInt(root.getAttribute('data-quarter') || '', 10);

  if (isNaN(currentYear)) currentYear = new Date().getFullYear();
  if (isNaN(currentQuarter) || currentQuarter < 1 || currentQuarter > 4) currentQuarter = 1;

  function fmt(n) {
    return Number(n || 0).toLocaleString('ko-KR');
  }

  function fmtAxis(v) {
    var n = Number(v) || 0;
    var abs = Math.abs(n);
    var sign = n < 0 ? '-' : '';
    if (abs >= 100000000) {
      var eok = abs / 100000000;
      var s = eok >= 10 ? String(Math.round(eok)) : String(Math.round(eok * 10) / 10);
      return sign + s + '억';
    }
    if (abs >= 10000) {
      return sign + Math.round(abs / 10000).toLocaleString('ko-KR') + '만';
    }
    return sign + abs.toLocaleString('ko-KR');
  }

  function fmtRate(rev, part) {
    rev = Number(rev || 0);
    part = Number(part || 0);
    if (rev <= 0) return '—';
    return Math.round((part / rev) * 100) + '%';
  }

  function setRate(sel, rev, part, overWhen) {
    var el = root.querySelector('[data-dash="' + sel + '"]');
    if (!el) return;
    el.textContent = fmtRate(rev, part);
    var loss = false;
    if (Number(rev || 0) > 0) {
      if (overWhen === 'over-rev') loss = Number(part || 0) > Number(rev);
      else loss = Number(part || 0) < 0;
    }
    el.classList.toggle('is-loss', loss);
    el.classList.toggle('is-gain', !loss && overWhen !== 'over-rev' && Number(rev) > 0 && Number(part) > 0);
  }

  /** 기준값 대비 증감(금액). 기준이 0이면 비교 불가로 '—'. */
  function setDelta(sel, cur, base) {
    var el = root.querySelector('[data-dash="' + sel + '"]');
    if (!el) return;
    var valEl = el.querySelector('.dash-delta-val') || el;
    cur = Number(cur || 0);
    base = Number(base || 0);
    var tone = '';
    var text = '—';
    if (base !== 0) {
      var d = cur - base;
      if (d > 0) { text = '▲ ' + fmt(d); tone = 'gain'; }
      else if (d < 0) { text = '▼ ' + fmt(-d); tone = 'loss'; }
      else { text = '± 0'; }
    }
    valEl.textContent = text;
    el.classList.toggle('is-gain', tone === 'gain');
    el.classList.toggle('is-loss', tone === 'loss');
  }

  /** 억·만 축약. 기간 카드 인라인 요약용. */
  function setShort(sel, value) {
    var el = root.querySelector('[data-dash="' + sel + '"]');
    if (el) el.textContent = fmtAxis(value);
  }

  /** 연초부터 누적 수입이 누적 지출을 처음 넘긴 달(1–12). 없으면 null. */
  function breakEvenMonth() {
    var cum = 0;
    for (var i = 0; i < series.length; i++) {
      var row = series[i] || {};
      cum += Number(row.revenue_krw || 0) - Number(row.expense_krw || 0);
      if (cum > 0) {
        var ym = String(row.ym || '');
        if (ym.length >= 7) return parseInt(ym.slice(5, 7), 10);
        return i + 1;
      }
    }
    return null;
  }

  function setBreakEven() {
    var el = root.querySelector('[data-dash="year-be"]');
    if (!el) return;
    var m = breakEvenMonth();
    var reached = m !== null;
    var text = reached ? (m + '월 손익분기 달성') : '올해 손익분기 미달';
    // 점(dot)은 유지하고 텍스트만 교체
    var dot = el.querySelector('.dash-be-dot');
    el.textContent = '';
    if (dot) el.appendChild(dot);
    else {
      var span = document.createElement('span');
      span.className = 'dash-be-dot';
      span.setAttribute('aria-hidden', 'true');
      el.appendChild(span);
    }
    el.appendChild(document.createTextNode(text));
    el.classList.toggle('is-reached', reached);
    el.classList.toggle('is-pending', !reached);
  }

  function monthLabel(ym) {
    ym = String(ym || '');
    if (ym.length >= 7) {
      return ym.slice(0, 4) + '년 ' + String(parseInt(ym.slice(5, 7), 10)) + '월';
    }
    return ym;
  }

  function token(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  function setMoney(sel, value, tone) {
    var el = root.querySelector('[data-dash="' + sel + '"]');
    if (!el) return;
    var n = Number(value || 0);
    el.textContent = '';
    el.appendChild(document.createTextNode(fmt(n)));
    var unit = document.createElement('span');
    unit.className = 'unit';
    unit.textContent = '원';
    el.appendChild(unit);
    el.classList.toggle('is-loss', n < 0);
    el.classList.toggle('is-gain', !!tone && n > 0);
  }

  function setText(id, text) {
    var el = document.getElementById(id) || root.querySelector('[data-dash="' + id + '"]');
    if (el) el.textContent = text;
  }

  function quarterTotal(q) {
    var start = (q - 1) * 3;
    var rev = 0;
    var exp = 0;
    for (var i = 0; i < 3; i++) {
      var row = series[start + i] || {};
      rev += Number(row.revenue_krw || 0);
      exp += Number(row.expense_krw || 0);
    }
    return { revenue_krw: rev, expense_krw: exp, margin_krw: rev - exp };
  }

  function defaultQuarterForYear(year) {
    var today = root.getAttribute('data-today') || '';
    var y;
    var m;
    if (/^\d{4}-\d{2}-\d{2}$/.test(today)) {
      y = parseInt(today.slice(0, 4), 10);
      m = parseInt(today.slice(5, 7), 10);
    } else {
      var d = new Date();
      y = d.getFullYear();
      m = d.getMonth() + 1;
    }
    if (year === y) {
      return Math.ceil(m / 3);
    }
    return 4;
  }

  function applyYearCards(data) {
    var y = data.year_total || {};
    var yPrev = data.year_total_prev || {};
    var p = data.previous_month || {};
    var pPrev = data.previous_month_prev || {};
    setMoney('year-rev', y.revenue_krw);
    setMoney('year-exp', y.expense_krw);
    setMoney('year-margin', y.margin_krw, true);
    setRate('year-rate', y.revenue_krw, y.margin_krw);
    setDelta('year-yoy', y.margin_krw, yPrev.margin_krw);
    setBreakEven();
    setMoney('prev-margin', p.margin_krw, true);
    setRate('prev-rate', p.revenue_krw, p.margin_krw);
    setDelta('prev-mom', p.margin_krw, pPrev.margin_krw);
    setShort('prev-rev-short', p.revenue_krw);
    setShort('prev-exp-short', p.expense_krw);
    setText('dash-year-label', String(data.year) + '년');
    setText('dash-prev-label', monthLabel(p.ym));
    setMoney('year-free-value', data.year_free_value);
  }

  function applyQuarter() {
    var q = quarterTotal(currentQuarter);
    var qPrev = currentQuarter > 1 ? quarterTotal(currentQuarter - 1) : null;
    setMoney('q-margin', q.margin_krw, true);
    setRate('q-rate', q.revenue_krw, q.margin_krw);
    setDelta('q-qoq', q.margin_krw, qPrev ? qPrev.margin_krw : 0);
    setShort('q-rev-short', q.revenue_krw);
    setShort('q-exp-short', q.expense_krw);
    setText('dash-q-label', String(currentYear) + '년 ' + currentQuarter + '분기');
    setText('dash-chart-hint', String(currentYear) + '년');
    drawChart();
  }

  function quarterBandGraphic() {
    if (!chart) return [];
    var qStart = (currentQuarter - 1) * 3;
    var qEnd = qStart + 2;
    var xStart = chart.convertToPixel({ xAxisIndex: 0 }, qStart);
    var xNext = chart.convertToPixel({ xAxisIndex: 0 }, qStart + 1);
    var xEnd = chart.convertToPixel({ xAxisIndex: 0 }, qEnd);
    var grid = chart.getModel().getComponent('grid', 0);
    var rect = grid && grid.coordinateSystem && grid.coordinateSystem.getRect();
    if (xStart == null || xEnd == null || !rect) return [];
    var slot = (xNext != null) ? (xNext - xStart) : 0;
    return [{
      id: 'q-band',
      type: 'rect',
      silent: true,
      z: 0,
      cursor: 'default',
      shape: {
        x: xStart - slot / 2,
        y: rect.y,
        width: (xEnd - xStart) + slot,
        height: rect.height
      },
      style: { fill: 'rgba(37, 99, 235, 0.10)' }
    }];
  }

  function drawChart() {
    if (!chartEl || typeof echarts === 'undefined') return;
    if (!chart) {
      chart = echarts.init(chartEl);
    }
    var labels = [];
    var rev = [];
    var exp = [];
    var margin = [];
    var cum = [];
    var running = 0;
    for (var i = 0; i < 12; i++) {
      var row = series[i] || {};
      var ym = String(row.ym || '');
      var month = ym.length >= 7 ? String(parseInt(ym.slice(5, 7), 10)) : String(i + 1);
      var r = Number(row.revenue_krw || 0);
      var e = Number(row.expense_krw || 0);
      var m = row.margin_krw != null ? Number(row.margin_krw) : (r - e);
      labels.push(month + '월');
      rev.push(r);
      exp.push(e);
      margin.push(m);
      running += m;
      cum.push(running);
    }
    var lastActive = -1;
    for (var a = 0; a < 12; a++) {
      var ar = series[a] || {};
      if (Number(ar.revenue_krw || 0) !== 0 || Number(ar.expense_krw || 0) !== 0) {
        lastActive = a;
      }
    }
    var marginLine = margin.map(function (v, i) {
      return lastActive >= 0 && i <= lastActive ? v : null;
    });
    var cumSolid = cum.map(function (v, i) {
      return lastActive >= 0 && i <= lastActive ? v : null;
    });
    var cumDash = cum.map(function (v, i) {
      return lastActive >= 0 && i >= lastActive ? v : null;
    });
    var spend = token('--ink-400', '#94a3b8');
    var income = token('--brand-600', '#2563eb');
    var ink = token('--ink-800', '#1e293b');
    var muted = token('--ink-500', '#64748b');
    var gain = token('--success', '#059669');
    var loss = token('--danger', '#dc2626');
    var fontSans = token('--font-sans', "'Pretendard GOV Variable', -apple-system, BlinkMacSystemFont, system-ui, sans-serif");
    chart.setOption({
      color: [income, spend, gain, ink],
      textStyle: { fontFamily: fontSans },
      tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        textStyle: { fontFamily: fontSans, fontSize: 12 },
        formatter: function (items) {
          var rows = (items || []).filter(function (it) {
            return String(it.seriesName || '').charAt(0) !== '_';
          });
          if (!rows.length) return '';
          var html = rows[0].axisValueLabel || rows[0].axisValue || '';
          var seen = {};
          rows.forEach(function (it) {
            var v = it.value;
            if (Array.isArray(v)) v = v[v.length - 1];
            if (v == null || v === '-') return;
            if (seen[it.seriesName]) return;
            seen[it.seriesName] = true;
            html += '<br/>' + it.marker + it.seriesName + '  ' + fmt(v) + '원';
          });
          return html;
        }
      },
      legend: {
        data: [
          { name: '매출', itemStyle: { color: income } },
          { name: '매입', itemStyle: { color: spend } },
          {
            name: '월 손익',
            itemStyle: { color: gain },
            lineStyle: { color: gain, type: 'dashed', width: 2 }
          },
          { name: '누적손익', itemStyle: { color: ink }, lineStyle: { color: ink, width: 2 } }
        ],
        bottom: 0,
        textStyle: { color: muted, fontSize: 12 }
      },
      grid: { left: 8, right: 12, top: 16, bottom: 40, containLabel: true },
      xAxis: {
        type: 'category',
        data: labels,
        axisTick: { show: false },
        axisLine: { lineStyle: { color: token('--ink-200', '#e2e8f0') } },
        axisLabel: { color: muted, fontSize: 11 }
      },
      yAxis: {
        type: 'value',
        splitLine: { lineStyle: { color: token('--ink-100', '#f1f5f9') } },
        axisLabel: {
          color: muted,
          fontSize: 11,
          formatter: fmtAxis
        }
      },
      graphic: [],
      series: [
        { name: '매출', type: 'bar', barGap: '20%', barMaxWidth: 18, z: 2, itemStyle: { color: income }, data: rev },
        { name: '매입', type: 'bar', barMaxWidth: 18, z: 2, itemStyle: { color: spend }, data: exp },
        {
          name: '월 손익',
          type: 'line',
          color: gain,
          data: marginLine,
          z: 3,
          symbol: 'circle',
          symbolSize: 6,
          connectNulls: false,
          lineStyle: { type: 'dashed', width: 2, color: gain },
          itemStyle: { color: gain }
        },
        {
          name: '_월 손익 손실',
          type: 'scatter',
          color: loss,
          data: marginLine.map(function (v) { return v != null && Number(v) < 0 ? v : null; }),
          z: 4,
          symbol: 'circle',
          symbolSize: 7,
          itemStyle: { color: loss },
          tooltip: { show: false },
          silent: true
        },
        {
          name: '누적손익',
          type: 'line',
          color: ink,
          data: cumSolid,
          z: 3,
          symbol: 'circle',
          symbolSize: 6,
          connectNulls: false,
          lineStyle: { type: 'solid', width: 2, color: ink },
          itemStyle: { color: ink }
        },
        {
          name: '누적손익',
          type: 'line',
          color: ink,
          data: cumDash,
          z: 3,
          symbol: 'circle',
          symbolSize: 6,
          connectNulls: false,
          lineStyle: { type: 'dashed', width: 2, color: ink },
          itemStyle: { color: ink }
        },
        {
          name: '_누적손익 손실',
          type: 'scatter',
          color: loss,
          data: cumSolid.map(function (v) { return v != null && Number(v) < 0 ? v : null; }),
          z: 4,
          symbol: 'circle',
          symbolSize: 7,
          itemStyle: { color: loss },
          tooltip: { show: false },
          silent: true
        }
      ]
    }, true);
    chart.setOption({ graphic: quarterBandGraphic() });
  }

  /** 상위 n개 + 나머지는 '기타'로 묶는다. 서버가 이미 내림차순 정렬해 준다. */
  function topNWithOther(list, n) {
    var items = (list || []).map(function (d) {
      return { label: String(d.label || ''), value: Number(d.amount_krw || 0) };
    }).filter(function (d) { return d.value > 0; });
    if (items.length <= n) return items;
    var top = items.slice(0, n);
    var rest = 0;
    for (var i = n; i < items.length; i++) rest += items[i].value;
    if (rest > 0) top.push({ label: '기타', value: rest });
    return top;
  }

  function drawDonut(el, chart, list) {
    if (!el || typeof echarts === 'undefined') return chart;
    if (!chart) chart = echarts.init(el);
    var muted = token('--ink-500', '#64748b');
    var surface = token('--surface', '#ffffff');
    var fontSans = token('--font-sans', "'Pretendard GOV Variable', sans-serif");
    var items = topNWithOther(list, 6);
    if (!items.length) {
      chart.clear();
      chart.setOption({
        graphic: {
          type: 'text', left: 'center', top: 'middle',
          style: { text: '데이터가 없습니다.', fill: muted, fontSize: 13, fontFamily: fontSans }
        }
      });
      return chart;
    }
    var palette = [
      token('--brand-600', '#2563eb'),
      token('--brand-400', '#6096fa'),
      token('--success', '#059669'),
      token('--info', '#0284c7'),
      token('--warning', '#d97706'),
      token('--brand-800', '#1e40af')
    ];
    var otherColor = token('--ink-300', '#cbd5e1');
    var total = 0;
    items.forEach(function (d) { total += d.value; });
    var pct = {};
    items.forEach(function (d) { pct[d.label] = total ? Math.round(d.value / total * 100) : 0; });
    var data = items.map(function (d, i) {
      return {
        name: d.label,
        value: d.value,
        itemStyle: { color: d.label === '기타' ? otherColor : palette[i % palette.length] }
      };
    });
    chart.setOption({
      graphic: [],
      tooltip: {
        trigger: 'item',
        textStyle: { fontFamily: fontSans, fontSize: 12 },
        formatter: function (p) { return p.name + '<br/>' + fmt(p.value) + '원 (' + p.percent + '%)'; }
      },
      legend: {
        type: 'scroll',
        orient: 'vertical',
        left: '54%',
        right: 8,
        top: 'middle',
        itemWidth: 10,
        itemHeight: 10,
        itemGap: 11,
        textStyle: {
          color: muted,
          fontSize: 11,
          fontFamily: fontSans,
          width: 118,
          overflow: 'break'
        },
        formatter: function (name) {
          return name + '  ' + (pct[name] || 0) + '%';
        }
      },
      series: [{
        type: 'pie',
        radius: ['50%', '72%'],
        center: ['27%', '50%'],
        avoidLabelOverlap: true,
        label: { show: false },
        labelLine: { show: false },
        itemStyle: { borderColor: surface, borderWidth: 2, borderRadius: 3 },
        data: data
      }]
    }, true);
    return chart;
  }

  /** 연간 KPI 매출·매입 셀의 항목 팝오버를 채운다. 도넛과 동일하게 상위 6 + 기타. */
  function renderKpiPop(kind, list) {
    var ul = root.querySelector('[data-kpi-list="' + kind + '"]');
    if (!ul) return;
    ul.textContent = '';
    var items = topNWithOther(list, 6);
    if (!items.length) {
      var empty = document.createElement('li');
      empty.className = 'dash-kpi-pop-empty';
      empty.textContent = '데이터가 없습니다.';
      ul.appendChild(empty);
      return;
    }
    items.forEach(function (d) {
      var li = document.createElement('li');
      var name = document.createElement('span');
      name.className = 'dash-kpi-pop-name';
      name.textContent = d.label;
      var amt = document.createElement('span');
      amt.className = 'dash-kpi-pop-amt';
      amt.textContent = fmt(d.value) + '원';
      li.appendChild(name);
      li.appendChild(amt);
      ul.appendChild(li);
    });
  }

  function applyBreakdowns(data) {
    if (!data) return;
    expDonut = drawDonut(expDonutEl, expDonut, data.expense_breakdown);
    revDonut = drawDonut(revDonutEl, revDonut, data.revenue_breakdown);
    renderKpiPop('rev', data.revenue_project_breakdown || data.revenue_breakdown);
    renderKpiPop('exp', data.expense_breakdown);
    var y = data.year != null ? data.year : currentYear;
    setText('dash-exp-year', String(y));
    setText('dash-rev-year', String(y));
  }

  function loadYear(year) {
    currentYear = year;
    root.setAttribute('data-year', String(year));
    history.replaceState(null, '', '?year=' + encodeURIComponent(String(year)));
    return fetch(apiUrl + '?year=' + encodeURIComponent(String(year)), { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || '집계를 불러오지 못했습니다.');
        series = data.series || [];
        applyYearCards(data);
        applyQuarter();
        applyBreakdowns(data);
      })
      .catch(function (err) {
        if (typeof tbbToast === 'function') {
          tbbToast('대시보드 오류', err.message, 'danger');
        }
      });
  }

  function setQuarter(q, skipDraw) {
    currentQuarter = q;
    root.setAttribute('data-quarter', String(q));
    root.querySelectorAll('[data-dash-quarter] button').forEach(function (btn) {
      btn.classList.toggle('is-active', parseInt(btn.getAttribute('data-quarter'), 10) === q);
    });
    if (!skipDraw) applyQuarter();
  }

  root.addEventListener('select:change', function (e) {
    if (!e.target.closest('[data-dash-year]')) return;
    var y = parseInt(yearEl && yearEl.value ? yearEl.value : '', 10);
    if (isNaN(y)) return;
    setQuarter(defaultQuarterForYear(y), true);
    loadYear(y);
  });

  var qGroup = root.querySelector('[data-dash-quarter]');
  if (qGroup) {
    qGroup.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-quarter]');
      if (!btn) return;
      var q = parseInt(btn.getAttribute('data-quarter'), 10);
      if (isNaN(q)) return;
      setQuarter(q);
    });
  }

  window.addEventListener('resize', function () {
    if (chart) {
      chart.resize();
      chart.setOption({ graphic: quarterBandGraphic() });
    }
    if (expDonut) expDonut.resize();
    if (revDonut) revDonut.resize();
  });

  var bootEl = document.getElementById('dash-bootstrap');
  var booted = false;
  if (bootEl && bootEl.textContent) {
    try {
      var boot = JSON.parse(bootEl.textContent);
      series = boot.series || [];
      applyYearCards(boot);
      applyQuarter();
      applyBreakdowns(boot);
      booted = true;
    } catch (e) {
      booted = false;
    }
  }
  if (!booted) {
    loadYear(currentYear);
  }
})();
