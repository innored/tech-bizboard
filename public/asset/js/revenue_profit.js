/* 수익 탭 집계. 브라우저와 node 테스트가 같은 함수를 쓴다. */
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  } else {
    root.RevenueProfit = api;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  var GROUPS = ['ADTECH', 'GENERAL'];
  /* 개발비·유지보수: 수익 = 공급가 - 원가 (서버 TeamRevenueProvider::COST_CATEGORIES와 같게) */
  var COST_CATEGORIES = ['개발비', '유지보수'];

  function num(value) {
    return Number(String(value == null ? '' : value).replace(/,/g, '')) || 0;
  }

  function isProject(row) {
    return !!row && COST_CATEGORIES.indexOf(row.service_category) >= 0;
  }

  /* ADTECH·GENERAL(솔루션), PROJECT(개발비·유지보수), ''(미분류) */
  function group(row) {
    if (isProject(row)) return 'PROJECT';
    var g = String((row && row.solution_group) || '').trim().toUpperCase();
    return GROUPS.indexOf(g) >= 0 ? g : '';
  }

  /* 유상 중 솔루션·개발비·유지보수만 */
  function targetRows(rows) {
    return (rows || []).filter(function (row) {
      return row && row.billing_type === 'PAID' && (row.service_category === '솔루션' || isProject(row));
    });
  }

  /* 개발·운영은 공급가 - 원가, 솔루션은 공급가 */
  function rowProfit(row) {
    var supply = num(row && row.supply_krw);
    return isProject(row) ? supply - num(row.cost_krw) : supply;
  }

  /* 백분율, 소수 첫째 자리. 분모가 0이면 null */
  function rate(numerator, denominator) {
    if (!denominator) return null;
    return Math.round((numerator / denominator) * 1000) / 10;
  }

  function rowRate(row) {
    var g = group(row);
    if (g === 'GENERAL') return 100;
    if (g === 'PROJECT') {
      var sales = num(row.supply_krw);
      return sales > 0 ? rate(rowProfit(row), sales) : null;
    }
    if (g === 'ADTECH') {
      var spend = num(row.campaign_spend_krw);
      return spend > 0 ? rate(num(row.supply_krw), spend) : null;
    }
    return null;
  }

  function formatRate(value) {
    return value == null ? '-' : value.toFixed(1) + '%';
  }

  /* 테크피(%) 소수 둘째 자리. 서버 round(…, 2)와 같게 지수 표기로 반올림한다 */
  function roundFee(value) {
    var n = parseFloat(String(value == null ? '' : value).replace(/[,%\s]/g, ''));
    if (isNaN(n)) return 0;
    return Number(Math.round(Number(n + 'e2')) + 'e-2');
  }

  function formatFee(value) {
    if (value == null || value === '') return '-';
    var n = roundFee(value);
    return n ? String(n) + '%' : '-';
  }

  /* 모달을 열 때 공급가는 있는데 집행금액이 없던 건(기존 데이터): 집행금액을 넣어도 공급가를 덮지 않는다 */
  function keepsSupply(initialSupply, initialSpend) {
    return num(initialSupply) > 0 && num(initialSpend) <= 0;
  }

  var GROUP_ORDER = ['ADTECH', 'GENERAL', 'PROJECT', ''];
  var GROUP_NAMES = { ADTECH: '애드테크', GENERAL: '일반 솔루션', PROJECT: '개발·운영', '': '미분류' };

  /* 화면·CSV 공통 순서: 애드테크 → 일반 → 개발·운영 → 미분류, 그룹 안은 입금일 최신순.
     개발·운영은 프로젝트 이름순으로 묶은 뒤 그 안에서 최신순 */
  function orderRows(rows) {
    return (rows || []).slice().sort(function (a, b) {
      var byGroup = GROUP_ORDER.indexOf(group(a)) - GROUP_ORDER.indexOf(group(b));
      if (byGroup !== 0) return byGroup;
      if (group(a) === 'PROJECT') {
        var byName = projectName(a).localeCompare(projectName(b));
        if (byName !== 0) return byName;
      }
      var byDate = String(b.received_date || '').localeCompare(String(a.received_date || ''));
      if (byDate !== 0) return byDate;
      return Number(b.id || 0) - Number(a.id || 0);
    });
  }

  function projectName(row) {
    return String((row && row.project_name) || '').trim();
  }

  /* 개발·운영 프로젝트별 소계, 이름순 */
  function projectTotals(rows) {
    var map = {};
    var names = [];
    (rows || []).forEach(function (row) {
      if (group(row) !== 'PROJECT') return;
      var name = projectName(row);
      if (!map[name]) {
        map[name] = { name: name, sales: 0, cost: 0, profit: 0, count: 0 };
        names.push(name);
      }
      map[name].sales += num(row.supply_krw);
      map[name].cost += num(row.cost_krw);
      map[name].profit += rowProfit(row);
      map[name].count += 1;
    });
    return names.sort(function (a, b) { return a.localeCompare(b); }).map(function (n) { return map[n]; });
  }

  function csvCell(value) {
    var text = value == null ? '' : String(value);
    // 엑셀 수식 주입 방지. 숫자(마이너스 수익)는 그대로 둔다
    if (typeof value !== 'number' && /^[=+\-@]/.test(text)) text = "'" + text;
    return /[",\r\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
  }

  /* 엑셀에서 한글이 깨지지 않게 BOM을 붙인다. 숫자는 콤마 없이 */
  function toCsv(rows) {
    var head = ['구분', '입금일', '프로젝트', '거래처', '솔루션/서비스', '집행금액', '테크피(%)', '공급가', '원가', '수익', '수익률(%)', '원가 메모'];
    var lines = [head.join(',')];
    orderRows(rows).forEach(function (row) {
      var g = group(row);
      var spend = num(row.campaign_spend_krw);
      var adtechRated = g === 'ADTECH' && spend > 0;
      var rowRateValue = rowRate(row);
      lines.push([
        GROUP_NAMES[g],
        row.received_date || '',
        row.project_name || '',
        row.client_name || '',
        g === 'PROJECT' ? (row.service_category || '') : (row.solution_name || ''),
        adtechRated ? spend : '',
        adtechRated ? roundFee(row.tech_fee_rate) : '',
        num(row.supply_krw),
        g === 'PROJECT' ? num(row.cost_krw) : '',
        rowProfit(row),
        rowRateValue == null ? '' : rowRateValue,
        g === 'PROJECT' ? (row.cost_note || '') : ''
      ].map(csvCell).join(','));
    });
    return '\ufeff' + lines.join('\r\n');
  }

  function summarize(rows) {
    var adtech = { spend: 0, profit: 0, rated_profit: 0, missing_spend_count: 0, count: 0 };
    var general = { sales: 0, profit: 0, count: 0 };
    var project = { sales: 0, cost: 0, profit: 0, count: 0 };
    var unclassified = 0;
    (rows || []).forEach(function (row) {
      var g = group(row);
      var supply = num(row.supply_krw);
      if (g === 'ADTECH') {
        var spend = num(row.campaign_spend_krw);
        adtech.count += 1;
        adtech.profit += supply;
        if (spend > 0) {
          adtech.spend += spend;
          adtech.rated_profit += supply;
        } else {
          adtech.missing_spend_count += 1;
        }
      } else if (g === 'PROJECT') {
        project.count += 1;
        project.sales += supply;
        project.cost += num(row.cost_krw);
        project.profit += rowProfit(row);
      } else if (g === 'GENERAL') {
        general.count += 1;
        general.sales += supply;
        general.profit += supply;
      } else {
        unclassified += 1;
      }
    });
    return {
      adtech: adtech,
      general: general,
      project: project,
      total: {
        profit: adtech.profit + general.profit + project.profit,
        base: adtech.spend + general.sales + project.sales,
        rated_profit: adtech.rated_profit + general.profit + project.profit
      },
      unclassified_count: unclassified
    };
  }

  return {
    group: group,
    targetRows: targetRows,
    rowProfit: rowProfit,
    projectTotals: projectTotals,
    summarize: summarize,
    rate: rate,
    rowRate: rowRate,
    formatRate: formatRate,
    roundFee: roundFee,
    formatFee: formatFee,
    keepsSupply: keepsSupply,
    orderRows: orderRows,
    toCsv: toCsv
  };
});
