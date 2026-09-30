/* API 사용량 조회 */
(function () {
  'use strict';

  var root = document.querySelector('[data-api-usage]');
  if (!root) return;

  var api = root.getAttribute('data-api') || 'api/api_usage';

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function token(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  var OK = { bg: 'rgba(5,150,105,.10)', fg: '#047857' };
  var ERR = { bg: 'rgba(220,38,38,.10)', fg: '#b91c1c' };
  var MUTED = { bg: 'var(--ink-100)', fg: 'var(--ink-500)' };

  function banner(color, html) {
    return '<div style="padding:12px 14px; border-radius:8px; font-size:14px; line-height:1.5;' +
      ' background:' + color.bg + '; color:' + color.fg + ';">' + html + '</div>';
  }

  function rawBlock(raw) {
    if (raw === undefined || raw === null) return '';
    var text = typeof raw === 'string' ? raw : JSON.stringify(raw, null, 2);
    return '<details style="margin-top:12px;">' +
      '<summary style="cursor:pointer; font-size:13px; color:var(--ink-500);">응답 전체 (raw)</summary>' +
      '<pre style="margin:8px 0 0; padding:12px; background:var(--ink-100); border-radius:8px;' +
      ' font-size:12px; line-height:1.5; overflow:auto; max-height:420px; white-space:pre;">' +
      escapeHtml(text) + '</pre></details>';
  }

  // 서버가 series 를 안 준 옛 스냅샷 대비: OpenAI raw 버킷에서 파생.
  function deriveSeries(raw) {
    if (!raw || !raw.data || !raw.data.length) return [];
    var days = raw.data.map(function (b) {
      var day = b.start_time ? new Date(b.start_time * 1000).toISOString().slice(0, 10)
        : (typeof b.starting_at === 'string' ? b.starting_at.slice(0, 10) : '');
      var daily = (b.results || []).reduce(function (sum, r) {
        if (r.amount && typeof r.amount === 'object') return sum + (r.amount.value || 0);   // OpenAI: 달러
        return sum + (parseFloat(r.amount) || 0) / 100;                                     // Anthropic: 센트
      }, 0);
      return { date: day, amount: daily };
    }).filter(function (x) { return x.date; });
    days.sort(function (a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : 0; });
    var run = 0;
    days.forEach(function (x) { run += x.amount; x.cumulative = Math.round(run * 10000) / 10000; });
    return days;
  }

  function drawChart(chartEl, series, currency) {
    if (!chartEl || typeof echarts === 'undefined') return;
    var chart = echarts.getInstanceByDom(chartEl) || echarts.init(chartEl);
    var muted = token('--ink-500', '#64748b');
    var fontSans = token('--font-sans', "'Pretendard GOV Variable', sans-serif");

    if (!series || !series.length) {
      chart.clear();
      chart.setOption({
        graphic: { type: 'text', left: 'center', top: 'middle',
          style: { text: '데이터가 없습니다.', fill: muted, fontSize: 13, fontFamily: fontSans } }
      });
      return chart;
    }

    var unit = currency || 'USD';
    chart.setOption({
      grid: { left: 8, right: 12, top: 24, bottom: 8, containLabel: true },
      tooltip: {
        trigger: 'axis',
        textStyle: { fontFamily: fontSans, fontSize: 12 },
        formatter: function (ps) {
          var lines = ['<strong>' + (ps[0] ? ps[0].axisValue : '') + '</strong>'];
          ps.forEach(function (p) {
            lines.push(p.marker + p.seriesName + ' ' +
              Number(p.value).toLocaleString('en-US', { maximumFractionDigits: 4 }) + ' ' + unit);
          });
          return lines.join('<br/>');
        }
      },
      legend: { data: ['일별', '누적'], right: 4, top: 0, textStyle: { color: muted, fontSize: 12, fontFamily: fontSans } },
      xAxis: {
        type: 'category', data: series.map(function (s) { return s.date; }), boundaryGap: true,
        axisLabel: { color: muted, fontSize: 11, fontFamily: fontSans, formatter: function (v) { return v.slice(5); } },
        axisTick: { show: false }
      },
      yAxis: {
        type: 'value',
        axisLabel: { color: muted, fontSize: 11, fontFamily: fontSans },
        splitLine: { lineStyle: { color: token('--ink-100', '#f1f5f9') } }
      },
      series: [
        { name: '일별', type: 'bar', data: series.map(function (s) { return s.amount; }),
          itemStyle: { color: token('--brand-400', '#6096fa'), borderRadius: [3, 3, 0, 0] }, barMaxWidth: 22 },
        { name: '누적', type: 'line', data: series.map(function (s) { return s.cumulative; }),
          smooth: true, symbol: 'circle', symbolSize: 5,
          lineStyle: { color: token('--brand-600', '#2563eb'), width: 2 }, itemStyle: { color: token('--brand-600', '#2563eb') } }
      ]
    }, true);
    return chart;
  }

  function Provider(key) {
    this.key = key;
    this.out = root.querySelector('[data-result="' + key + '"]');
    this.chartEl = root.querySelector('[data-chart="' + key + '"]');
    this.btn = root.querySelector('[data-refresh="' + key + '"]');
    var self = this;
    if (this.btn) this.btn.addEventListener('click', function () { self.load(true); });
  }

  Provider.prototype.render = function (d) {
    var html = '';
    if (d.ok) {
      var src = d.cached
        ? (d.stale ? '저장본(스냅샷) · 재조회 실패로 이전 값 유지' : '저장본(스냅샷)')
        : '방금 재조회';
      html += banner(OK,
        '✅ <strong>연결 성공</strong> (HTTP ' + d.status + ')<br>' +
        escapeHtml(d.month || '') + ' 누적 비용: <strong>' +
        escapeHtml(String(d.amount)) + ' ' + escapeHtml(d.currency || '') + '</strong>' +
        ' <span style="opacity:.7">(' + d.bucket_count + '일 집계)</span><br>' +
        '<span style="opacity:.7; font-size:13px;">데이터 시점: ' + escapeHtml(d.fetched_at || '-') +
        ' · ' + src + '</span>'
      );
      if (d.refresh_error) {
        html += banner(ERR, '⚠️ 새로고침 실패: ' + escapeHtml(d.refresh_error) +
          (d.refresh_status ? ' (HTTP ' + d.refresh_status + ')' : ''));
      }
    } else if (d.configured === false) {
      html += banner(MUTED, 'ℹ️ ' + escapeHtml(d.error || '키가 설정되지 않았습니다.') +
        ' <span style="opacity:.7">— 키를 등록하면 자동 조회됩니다.</span>');
    } else {
      html += banner(ERR,
        '❌ <strong>실패</strong>' + (d.status ? ' (HTTP ' + d.status + ')' : '') + '<br>' +
        escapeHtml(d.error || '알 수 없는 오류'));
    }
    html += rawBlock(d.raw);
    if (this.out) this.out.innerHTML = html;

    var series = (d.series && d.series.length) ? d.series : deriveSeries(d.raw);
    drawChart(this.chartEl, series, d.currency);
  };

  Provider.prototype.load = function (refresh) {
    var self = this;
    if (this.btn) this.btn.disabled = true;
    if (this.out) this.out.innerHTML = banner(MUTED, refresh ? '재조회 중…' : '불러오는 중…');
    var url = api + '?action=check&provider=' + encodeURIComponent(this.key) + (refresh ? '&refresh=1' : '');
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { self.render(d); })
      .catch(function (e) { if (self.out) self.out.innerHTML = banner(ERR, '요청 실패: ' + escapeHtml(String(e))); })
      .then(function () { if (self.btn) self.btn.disabled = false; });
  };

  var instances = [];
  Array.prototype.forEach.call(root.querySelectorAll('[data-result]'), function (el) {
    instances.push(new Provider(el.getAttribute('data-result')));
  });

  window.addEventListener('resize', function () {
    instances.forEach(function (p) {
      if (p.chartEl && typeof echarts !== 'undefined') {
        var c = echarts.getInstanceByDom(p.chartEl);
        if (c) c.resize();
      }
    });
  });

  // 페이지 로드 시 스냅샷 우선 표시(저장본 있으면 외부 API 호출 없음).
  instances.forEach(function (p) { p.load(false); });
})();
