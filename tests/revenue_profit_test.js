/* 수익 탭 집계 */
'use strict';

var profit = require('../public/asset/js/revenue_profit.js');
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
  { service_category: '솔루션', billing_type: 'PAID', solution_group: 'GENERAL', supply_krw: 3000000, campaign_spend_krw: 0, tech_fee_rate: null },
  { service_category: '솔루션', billing_type: 'PAID', solution_group: 'ADTECH', supply_krw: 1000000, campaign_spend_krw: '10000000', tech_fee_rate: '10.00' },
  { service_category: '솔루션', billing_type: 'PAID', solution_group: 'ADTECH', supply_krw: 750000, campaign_spend_krw: 0, tech_fee_rate: null },
  { service_category: '솔루션', billing_type: 'PAID', solution_group: '', supply_krw: 500000 },
  { service_category: '컨설팅', billing_type: 'PAID', solution_group: '', supply_krw: 999 },
  { service_category: '솔루션', billing_type: 'FREE', solution_group: 'GENERAL', supply_krw: 0 }
];

var target = profit.targetRows(rows);
expectEq(target.length, 4, '솔루션 유상만');

var sum = profit.summarize(target);
expectEq(sum.adtech, { spend: 10000000, profit: 1750000, rated_profit: 1000000, missing_spend_count: 1, count: 2 }, '애드테크 집계');
expectEq(sum.general, { sales: 3000000, profit: 3000000, count: 1 }, '일반 집계');
expectEq(sum.total, { profit: 4750000, base: 13000000, rated_profit: 4000000 }, '전체 집계');
expectEq(sum.project, { sales: 0, cost: 0, profit: 0, count: 0 }, '개발·운영 없음');
expectEq(sum.unclassified_count, 1, '미분류 건수');

expectEq(profit.rate(1000000, 10000000), 10, '수익률 10%');
expectEq(profit.rate(4000000, 13000000), 30.8, '수익률 소수 첫째 자리');
expectEq(profit.rate(1, 0), null, '분모 0이면 null');
expectEq(profit.formatRate(10), '10.0%', '수익률 표시');
expectEq(profit.formatRate(null), '-', '수익률 없음 표시');

expectEq(profit.group(rows[1]), 'ADTECH', '그룹 키');
expectEq(profit.group({ solution_group: 'x' }), '', '모르는 그룹은 미분류');
expectEq(profit.rowRate(rows[0]), 100, '일반 행 수익률 100');
expectEq(profit.rowRate(rows[1]), 10, '애드테크 행 수익률');
expectEq(profit.rowRate(rows[2]), null, '집행금액 미입력 행 수익률 없음');
expectEq(profit.rowRate(rows[3]), null, '미분류 행 수익률 없음');

expectEq(profit.roundFee('7.555'), 7.56, '테크피 소수 둘째 자리 반올림');
expectEq(profit.roundFee('abc'), 0, '테크피 숫자 아님');
expectEq(profit.formatFee('7.25'), '7.25%', '테크피 둘째 자리 표시');
expectEq(profit.formatFee('10.00'), '10%', '테크피 끝 0 제거');
expectEq(profit.formatFee(null), '-', '테크피 없음');
expectEq(profit.keepsSupply(750000, 0), true, '집행금액 없는 기존 건은 공급가 유지');
expectEq(profit.keepsSupply(1000000, 10000000), false, '집행금액 있는 건은 자동 계산');
expectEq(profit.keepsSupply(0, 0), false, '새 건은 자동 계산');

/* 개발·운영: 개발비·유지보수 유상, 수익 = 공급가 - 원가 */
var projRows = [
  { service_category: '개발비', billing_type: 'PAID', project_name: 'LG U+ 심플 사서함', received_date: '2026-05-10', supply_krw: 95000000, cost_krw: 10867884 },
  { service_category: '유지보수', billing_type: 'PAID', project_name: 'LG U+ 심플 사서함', received_date: '2026-06-30', supply_krw: 0, cost_krw: '373338' },
  { service_category: '유지보수', billing_type: 'PAID', project_name: 'LG U+ 심플 번역기', received_date: '2026-01-31', supply_krw: 2500000, cost_krw: 436465 },
  { service_category: '개발비', billing_type: 'FREE', project_name: '무상', supply_krw: 0, cost_krw: 0 },
  { service_category: '기타', billing_type: 'PAID', project_name: '기타', supply_krw: 1000 }
];
var projTarget = profit.targetRows(projRows);
expectEq(projTarget.length, 3, '개발비·유지보수 유상만 대상');
expectEq(profit.group(projRows[0]), 'PROJECT', '개발비는 개발·운영');
expectEq(profit.rowProfit(projRows[0]), 84132116, '개발비 수익 = 공급가 - 원가');
expectEq(profit.rowProfit(projRows[1]), -373338, '청구 없는 달은 마이너스');
expectEq(profit.rowProfit(rows[0]), 3000000, '솔루션 수익 = 공급가');
expectEq(profit.rowRate(projRows[0]), 88.6, '개발·운영 수익률 = 수익 / 공급가');
expectEq(profit.rowRate(projRows[1]), null, '공급가 0이면 수익률 없음');
var projSum = profit.summarize(projTarget);
expectEq(projSum.project, { sales: 97500000, cost: 11677687, profit: 85822313, count: 3 }, '개발·운영 집계');
expectEq(projSum.total, { profit: 85822313, base: 97500000, rated_profit: 85822313 }, '개발·운영이 전체에 포함');
expectEq(profit.projectTotals(projTarget), [
  { name: 'LG U+ 심플 번역기', sales: 2500000, cost: 436465, profit: 2063535, count: 1 },
  { name: 'LG U+ 심플 사서함', sales: 95000000, cost: 11241222, profit: 83758778, count: 2 }
], '프로젝트별 소계(이름순)');
expectEq(profit.orderRows(projTarget.concat([rows[1]])).map(function (r) { return r.received_date || 'adtech'; }),
  ['adtech', '2026-01-31', '2026-06-30', '2026-05-10'], '애드테크 → 개발·운영(프로젝트 이름순, 안은 최신순)');

var csvRows = [
  { solution_group: 'GENERAL', received_date: '2026-11-01', project_name: '소셜 "구독"', client_name: '로얄캐닌', solution_name: 'Social Radar', supply_krw: 3000000 },
  { solution_group: 'ADTECH', received_date: '2026-07-27', project_name: '=cmd', client_name: '아이디엇', solution_name: 'Lift Optima', supply_krw: 1000000, campaign_spend_krw: '10000000', tech_fee_rate: '10.00' },
  { solution_group: 'ADTECH', received_date: '2026-08-01', project_name: 'LO', client_name: '', solution_name: 'Lift Optima', supply_krw: 750000, campaign_spend_krw: 0 },
  { service_category: '유지보수', received_date: '2026-06-30', project_name: 'LG U+ 심플 사서함', client_name: 'LG U+', supply_krw: 0, cost_krw: 373338, cost_note: 'ARS, 서버비' }
];
var csv = profit.toCsv(csvRows);
var lines = csv.split('\r\n');
expectEq(csv.charAt(0), '﻿', 'CSV는 BOM으로 시작');
expectEq(lines[0], '﻿구분,입금일,프로젝트,거래처,솔루션/서비스,집행금액,테크피(%),공급가,원가,수익,수익률(%),원가 메모', 'CSV 머리');
expectEq(lines[1], '애드테크,2026-08-01,LO,,Lift Optima,,,750000,,750000,,', '애드테크 최신순, 미입력은 빈칸');
expectEq(lines[2], "애드테크,2026-07-27,'=cmd,아이디엇,Lift Optima,10000000,10,1000000,,1000000,10,", '수식 주입 방지·숫자는 콤마 없이');
expectEq(lines[3], '일반 솔루션,2026-11-01,"소셜 ""구독""",로얄캐닌,Social Radar,,,3000000,,3000000,100,', '따옴표 이스케이프·일반 100');
expectEq(lines[4], '개발·운영,2026-06-30,LG U+ 심플 사서함,LG U+,유지보수,,,0,373338,-373338,,"ARS, 서버비"', '개발·운영: 원가·마이너스 수익·메모');
expectEq(lines.length, 5, '행 수');

var empty = profit.summarize([]);
expectEq(empty.total, { profit: 0, base: 0, rated_profit: 0 }, '빈 목록');

console.log(failed === 0 ? 'ALL PASSED' : 'FAILED ' + failed);
process.exit(failed === 0 ? 0 : 1);
