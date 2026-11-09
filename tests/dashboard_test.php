<?php
/**
 * 대시보드 연·전월·분기 합계 테스트
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/dashboard_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_dash_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();
$now = new DateTimeImmutable('2026-09-09', new DateTimeZone('Asia/Seoul'));

expect_eq(DashboardProvider::defaultYear($now), 2026, '기본 연도는 전월의 해');
expect_eq(DashboardProvider::defaultQuarter(2026, $now), 3, '올해면 오늘 분기');
expect_eq(DashboardProvider::defaultQuarter(2025, $now), 4, '다른 해면 4분기');

$rev = new TeamRevenueProvider($pdo, new RevenueTemplateProvider($pdo));
$rev->createOneOff([
    'project_name'  => '1월',
    'assignee'      => '루시',
    'received_date' => '2026-01-10',
    'amount_krw'    => 10,
]);
$rev->createOneOff([
    'project_name'  => '7월',
    'assignee'      => '루시',
    'received_date' => '2026-07-10',
    'amount_krw'    => 50,
]);
$rev->createOneOff([
    'project_name'  => '8월',
    'assignee'      => '루시',
    'received_date' => '2026-08-10',
    'amount_krw'    => 100,
]);

$pdo->exec(
    "INSERT INTO tb_draft_expenses
        (target_year_month, draft_title, draft_body, currency, amount_krw, status, created_at)
     VALUES
        ('2026-07', '7월 지출', '본문', 'KRW', 20, 'DRAFT', '2026-09-09 00:00:00'),
        ('2026-08', '8월 지출', '본문', 'KRW', 40, 'DONE', '2026-09-09 00:00:00')"
);

$dash = new DashboardProvider($pdo);
$out = $dash->summarize(2026, $now);

expect_eq($out['year'], 2026, '연도');
expect_eq(count($out['series']), 12, '12개월');
expect_eq($out['year_total']['revenue_krw'], 160, '연간 수입');
expect_eq($out['year_total']['expense_krw'], 60, '연간 지출');
expect_eq($out['year_total']['margin_krw'], 100, '연간 이익');
expect_eq($out['previous_month']['ym'], '2026-08', '전월 키');
expect_eq($out['previous_month']['revenue_krw'], 100, '전월 수입');
expect_eq($out['previous_month']['expense_krw'], 40, '전월 지출');
expect_eq($out['previous_month']['margin_krw'], 60, '전월 이익');
expect_eq($out['series'][6]['ym'], '2026-07', '7월 키');
expect_eq($out['series'][6]['revenue_krw'], 50, '7월 수입');
expect_eq($out['series'][6]['expense_krw'], 20, '7월 지출');
expect_eq($out['series'][8]['revenue_krw'], 0, '없는 달은 0');
$q3 = DashboardProvider::quarterTotal($out['series'], 3);
expect_eq($q3['revenue_krw'], 150, '3분기 수입');
expect_eq($q3['expense_krw'], 60, '3분기 지출');
expect_eq($q3['margin_krw'], 90, '3분기 이익');
$q1 = DashboardProvider::quarterTotal($out['series'], 1);
expect_eq($q1['revenue_krw'], 10, '1분기 수입');
expect_eq(DashboardProvider::breakEvenMonth($out['series']), 1, '1월부터 누적 이익');

$late = [];
for ($m = 1; $m <= 12; $m++) {
    $revAmt = $m === 7 ? 100 : 0;
    $expAmt = $m <= 6 ? 10 : 0;
    $late[] = [
        'ym'          => sprintf('2026-%02d', $m),
        'revenue_krw' => $revAmt,
        'expense_krw' => $expAmt,
        'margin_krw'  => $revAmt - $expAmt,
    ];
}
expect_eq(DashboardProvider::breakEvenMonth($late), 7, '누적이 넘는 달');

$empty = $dash->summarize(2025, $now);
expect_eq($empty['year_total']['revenue_krw'], 0, '데이터 없는 해 0');
expect_eq($empty['previous_month']['ym'], '2026-08', '전월은 연도와 무관');
expect_eq(DashboardProvider::breakEvenMonth($empty['series']), null, '미도달');

try {
    $dash->summarize(1999, $now);
    expect_true(false, '잘못된 연도는 실패해야 함');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '연도'), '연도 오류');
}

tbb_test_done();
