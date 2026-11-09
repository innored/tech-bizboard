/* 연간 수입 필터 */
'use strict';

var filter = require('../public/asset/js/revenue_year_filter.js');
var failed = 0;

function expectEq(actual, expected, msg) {
  var ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (ok) {
    console.log('OK  ' + msg);
    return;
  }
  failed += 1;
  console.log('FAIL  ' + msg + ' (actual=' + JSON.stringify(actual) + ')');
}

var rows = [
  { client_name: '가나다', service_category: '솔루션', solution_name: '웹방화벽', billing_type: 'PAID', revenue_template_id: 1, supply_krw: 100, vat_krw: 10, amount_krw: 110 },
  { client_name: '가나다', service_category: '컨설팅', solution_name: '', billing_type: 'FREE', revenue_template_id: null, supply_krw: 0, vat_krw: 0, amount_krw: 999 },
  { client_name: '', service_category: '기타', solution_name: '', billing_type: 'PAID', revenue_template_id: null, supply_krw: 50, vat_krw: 5, amount_krw: 55 },
  { client_name: '다라마', service_category: '솔루션', solution_name: '관제', billing_type: 'PAID', revenue_template_id: null, supply_krw: 20, vat_krw: 2, amount_krw: 22 }
];

var hit = filter.filterRows(rows, { client: '가나다', service: '솔루션', billing: 'PAID', kind: 'repeat' });
expectEq(hit.length, 1, '네 필터 AND');
expectEq(hit[0].amount_krw, 110, '반복 솔루션 유상만');

var loose = filter.filterRows(rows, { service: '없는값', billing: 'NOPE', kind: 'x', client: '줄\n바꿈' });
expectEq(loose.length, 4, '잘못된 필터는 전체');

var sum = filter.summarize(rows);
expectEq(sum, { supply_krw: 170, vat_krw: 17, amount_krw: 187, paid_count: 3, free_count: 1 }, '무상 금액은 합계에서 제외');

var options = filter.clientOptions(rows);
expectEq(options, ['가나다', '다라마', '__none__'], '거래처 가나다순, 미지정은 맨 아래');
expectEq(filter.keepClient('없는거래처', options), '', '없는 거래처는 전체');
expectEq(filter.keepClient('__none__', options), '__none__', '미지정은 행이 있으면 유지');
expectEq(filter.clientKey({ client_name: '  ' }), '__none__', '빈 거래처 키');

var sol = filter.filterRows(rows, { solution: '웹방화벽' });
expectEq(sol.length, 1, '솔루션 이름');
expectEq(filter.filterRows(rows, { solution: '__none__' }).length, 2, '솔루션 미지정');
expectEq(filter.solutionOptions(rows), ['관제', '웹방화벽', '__none__'], '솔루션 가나다순, 미지정은 맨 아래');
expectEq(filter.keepClient('없는솔루션', filter.solutionOptions(rows)), '', '없는 솔루션은 전체');
expectEq(filter.normalizeFilters({ solution: '줄\n바꿈' }).solution, '', '줄바꿈 솔루션은 전체');

if (failed) {
  console.log('FAILED ' + failed);
  process.exit(1);
}
console.log('ALL PASSED');
