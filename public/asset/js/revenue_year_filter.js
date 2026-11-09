/* 연간 수입 필터. 브라우저와 node 테스트가 같은 함수를 쓴다. */
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  } else {
    root.RevenueYearFilter = api;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  var NONE = '__none__';
  var SERVICES = ['솔루션', '컨설팅', '유지보수', '개발비', '기타'];

  function clientKey(row) {
    var name = String((row && row.client_name) || '').trim();
    return name === '' ? NONE : name;
  }

  function oneOf(value, allowed) {
    var text = String(value || '').trim();
    return allowed.indexOf(text) >= 0 ? text : '';
  }

  function normalizeFilters(raw) {
    raw = raw || {};
    var client = String(raw.client || '').trim();
    if (client.indexOf('\n') >= 0 || client.indexOf('\r') >= 0) client = '';
    return {
      client: client,
      service: oneOf(raw.service, SERVICES),
      solution: solutionOf(raw.solution),
      billing: oneOf(raw.billing, ['PAID', 'FREE']),
      kind: oneOf(raw.kind, ['repeat', 'once'])
    };
  }

  function solutionOf(value) {
    var name = String(value || '').trim();
    if (name.indexOf('\n') >= 0 || name.indexOf('\r') >= 0) return '';
    return name;
  }

  function solutionKey(row) {
    var name = String((row && row.solution_name) || '').trim();
    return name === '' ? NONE : name;
  }

  function matches(row, filters) {
    if (filters.client && clientKey(row) !== filters.client) return false;
    if (filters.service && String(row.service_category || '') !== filters.service) return false;
    if (filters.solution && solutionKey(row) !== filters.solution) return false;
    var free = row.billing_type === 'FREE';
    if (filters.billing === 'PAID' && free) return false;
    if (filters.billing === 'FREE' && !free) return false;
    var repeat = !!row.revenue_template_id;
    if (filters.kind === 'repeat' && !repeat) return false;
    if (filters.kind === 'once' && repeat) return false;
    return true;
  }

  function filterRows(rows, filters) {
    var normalized = normalizeFilters(filters);
    return (rows || []).filter(function (row) { return matches(row, normalized); });
  }

  /* 무상은 건수만. 금액이 있어도 공급가·부가세·합계에 넣지 않는다. */
  function summarize(rows) {
    var supply = 0;
    var vat = 0;
    var amount = 0;
    var paid = 0;
    var free = 0;
    (rows || []).forEach(function (row) {
      if (row.billing_type === 'FREE') {
        free += 1;
        return;
      }
      paid += 1;
      supply += Number(row.supply_krw || 0);
      vat += Number(row.vat_krw || 0);
      amount += Number(row.amount_krw || 0);
    });
    return {
      supply_krw: supply,
      vat_krw: vat,
      amount_krw: amount,
      paid_count: paid,
      free_count: free
    };
  }

  function clientOptions(rows) {
    var names = {};
    var hasNone = false;
    (rows || []).forEach(function (row) {
      var key = clientKey(row);
      if (key === NONE) hasNone = true;
      else names[key] = true;
    });
    var list = Object.keys(names).sort(function (a, b) {
      return a.localeCompare(b, 'ko');
    });
    if (hasNone) list.push(NONE);
    return list;
  }

  function solutionOptions(rows) {
    var names = {};
    var hasNone = false;
    (rows || []).forEach(function (row) {
      var key = solutionKey(row);
      if (key === NONE) hasNone = true;
      else names[key] = true;
    });
    var list = Object.keys(names).sort(function (a, b) {
      return a.localeCompare(b, 'ko');
    });
    if (hasNone) list.push(NONE);
    return list;
  }

  function keepClient(client, options) {
    var key = String(client || '');
    if (!key) return '';
    return (options || []).indexOf(key) >= 0 ? key : '';
  }

  return {
    NONE: NONE,
    clientKey: clientKey,
    solutionKey: solutionKey,
    normalizeFilters: normalizeFilters,
    filterRows: filterRows,
    summarize: summarize,
    clientOptions: clientOptions,
    solutionOptions: solutionOptions,
    keepClient: keepClient
  };
});
