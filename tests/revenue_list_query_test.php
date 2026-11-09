<?php
/**
 * 매출 화면 쿼리 해석
 *
 * @date 2026-10-12
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/revenue_list_query_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/RevenueListQuery.php';

$today = '2026-10-12';

$open = RevenueListQuery::resolve([], $today);
expect_eq($open['tab'], 'month', '쿼리 없음은 월별');
expect_eq($open['year'], '2026', '쿼리 없음은 올해');
expect_eq($open['month'], '10', '쿼리 없음은 이번 달');

$annual = RevenueListQuery::resolve(['year' => '2026'], $today);
expect_eq($annual['tab'], 'year', '연도만 있으면 연간');
expect_eq($annual['month'], '', '연간은 달을 채우지 않음');

$month = RevenueListQuery::resolve(['year' => '2026', 'month' => '03'], $today);
expect_eq($month['tab'], 'month', '달이 있으면 월별');
expect_eq($month['month'], '03', '달 유지');

$keep = RevenueListQuery::resolve(['tab' => 'year', 'year' => '2026', 'month' => '03'], $today);
expect_eq($keep['tab'], 'year', 'tab=year 우선');
expect_eq($keep['month'], '03', '연간에도 달 쿼리는 남김');

$old = RevenueListQuery::resolve(['tab' => 'month', 'year' => '2024'], $today);
expect_eq($old['month'], '01', '다른 해 월별에 달이 없으면 1월');

$thisYear = RevenueListQuery::resolve(['tab' => 'month', 'year' => '2026'], $today);
expect_eq($thisYear['month'], '10', '올해 월별에 달이 없으면 이번 달');

$tpl = RevenueListQuery::resolve(['tab' => 'tpl', 'year' => '2026'], $today);
expect_eq($tpl['tab'], 'tpl', '반복 설정 탭');

$badTab = RevenueListQuery::resolve(['tab' => 'nope', 'year' => '2026'], $today);
expect_eq($badTab['tab'], 'year', '알 수 없는 탭이고 달이 없으면 연간');

$filters = RevenueListQuery::resolve([
    'year' => '2026',
    'client' => '__none__',
    'service' => '컨설팅',
    'solution' => '웹방화벽',
    'billing' => 'FREE',
    'kind' => 'repeat',
], $today);
expect_eq($filters['client'], '__none__', '미지정 거래처 토큰');
expect_eq($filters['service'], '컨설팅', '서비스구분');
expect_eq($filters['solution'], '웹방화벽', '솔루션');
expect_eq($filters['billing'], 'FREE', '유상/무상');
expect_eq($filters['kind'], 'repeat', '구분');

$invalid = RevenueListQuery::resolve([
    'year' => '2026',
    'month' => '13',
    'service' => '해킹',
    'billing' => 'NOPE',
    'kind' => 'x',
    'client' => "줄\n바꿈",
    'solution' => "줄\n바꿈",
], $today);
expect_eq($invalid['month'], '', '13월은 달이 아님');
expect_eq($invalid['tab'], 'year', '잘못된 달이면 연간');
expect_eq($invalid['service'], '', '없는 서비스구분은 전체');
expect_eq($invalid['billing'], '', '없는 유상/무상은 전체');
expect_eq($invalid['kind'], '', '없는 구분은 전체');
expect_eq($invalid['client'], '', '줄바꿈 거래처는 전체');
expect_eq($invalid['solution'], '', '줄바꿈 솔루션은 전체');

$profitTab = RevenueListQuery::resolve(['tab' => 'profit', 'year' => '2026'], '2026-11-09');
expect_eq($profitTab['tab'], 'profit', '수익 탭');
expect_eq($profitTab['month'], '', '수익 탭은 달 없음');

tbb_test_done();
