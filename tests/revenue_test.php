<?php
/**
 * 수입 템플릿·월별 raw 테스트
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/revenue_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$sept = new DateTimeImmutable('2026-09-14', new DateTimeZone('Asia/Seoul'));
expect_true(TeamRevenueProvider::isMonthLocked('2026-07', $sept), '9월이면 7월 잠금');
expect_true(TeamRevenueProvider::isMonthLocked('2026-06', $sept), '그 이전 잠금');
expect_true(!TeamRevenueProvider::isMonthLocked('2026-08', $sept), '전월은 수정 가능');
expect_true(!TeamRevenueProvider::isMonthLocked('2026-09', $sept), '당월은 수정 가능');
$jan = new DateTimeImmutable('2026-01-10', new DateTimeZone('Asia/Seoul'));
expect_true(TeamRevenueProvider::isMonthLocked('2025-11', $jan), '1월이면 11월 잠금');
expect_true(!TeamRevenueProvider::isMonthLocked('2025-12', $jan), '1월에 12월은 가능');

$path = sys_get_temp_dir() . '/tbb_rev_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();
$tpl = new RevenueTemplateProvider($pdo);
$tpl->setCreatedBy('lucy@innored.co.kr');

$row = $tpl->create([
    'project_name'     => '유지보수',
    'client_name'      => '콘버스',
    'supply_krw'       => 1000000,
    'assignee'         => '루시',
    'start_year_month' => '2026-03',
    'end_year_month'   => '2026-12',
]);
expect_true((int) ($row['id'] ?? 0) > 0, '템플릿 id');
expect_eq((string) ($row['created_by'] ?? ''), 'lucy@innored.co.kr', '수입 템플릿 작성자');

expect_eq(count($tpl->listActiveForMonth('2026-03')), 1, '시작월 포함');
expect_eq(count($tpl->listActiveForMonth('2026-02')), 0, '시작 전 제외');
expect_eq(count($tpl->listActiveForMonth('2026-12')), 1, '종료월 포함');
expect_eq(count($tpl->listActiveForMonth('2027-01')), 0, '종료 후 제외');

try {
    $tpl->create([
        'project_name'     => '잘못',
        'assignee'         => '루시',
        'start_year_month' => '2026-12',
        'end_year_month'   => '2026-01',
    ]);
    expect_true(false, '종료월이 앞이면 실패해야 함');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '종료월'), '기간 오류');
}

$nowYm = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m');
$open = $tpl->create([
    'project_name' => '무기한',
    'assignee'     => '루시',
    'supply_krw'   => 0,
]);
expect_eq((string) ($open['start_year_month'] ?? ''), $nowYm, '시작월 기본 이번 달');
expect_eq(count($tpl->listActiveForMonth('2028-01')), 1, '종료 없으면 계속');

$tpl->update((int) $row['id'], array_merge($row, ['is_active' => 0]));
expect_eq(count($tpl->listActiveForMonth('2026-06')), 0, '끄면 ensure 대상 아님');

$tpl->update((int) $row['id'], array_merge($row, ['is_active' => 1]));
$rev = new TeamRevenueProvider($pdo, $tpl);
$rev->setCreatedBy('lucy@innored.co.kr');
$rev->setNow(new DateTimeImmutable('2026-04-15', new DateTimeZone('Asia/Seoul')));
$money = TeamRevenueProvider::applySupply(1000000);
expect_eq($money['vat_krw'], 100000, '부가세 10%');
expect_eq($money['amount_krw'], 1100000, '합계');

$created = $rev->ensureMonth('2026-03');
expect_eq($created, 1, '기간 안 템플릿 1건');
expect_eq($rev->ensureMonth('2026-03'), 0, '두 번 호출해도 추가 없음');
expect_eq($rev->ensureMonth('2026-02'), 0, '기간 밖 0');

$rows = $rev->listByMonth('2026-03');
expect_eq(count($rows), 1, '3월 1줄');
expect_eq((string) ($rows[0]['status'] ?? ''), 'PENDING', '반복 기본 예정');
expect_eq((int) ($rows[0]['supply_krw'] ?? 0), 1000000, '공급가 복사');
expect_eq((int) ($rows[0]['amount_krw'] ?? 0), 1100000, '합계 채움');
expect_eq((string) ($rows[0]['created_by'] ?? ''), 'lucy@innored.co.kr', '반복 줄 작성자');
$repeatId = (int) ($rows[0]['id'] ?? 0);

$one = $rev->createOneOff([
    'project_name'  => '단건',
    'assignee'      => '단테',
    'received_date' => '2026-03-15',
    'amount_krw'    => 220000,
    'supply_krw'    => 200000,
    'vat_krw'       => 20000,
]);
expect_eq((string) ($one['status'] ?? ''), 'COMPLETED', '단건 기본 완료');
expect_eq((string) ($one['created_by'] ?? ''), 'lucy@innored.co.kr', '단건 작성자');
expect_eq((string) ($one['target_year_month'] ?? ''), '2026-03', '입금일 년월');
expect_eq(count($rev->listByMonth('2026-03')), 2, '반복+단건');
expect_eq(count($rev->listByYear('2026')), 2, '올해 필터는 ensure 없음');
$buckets = TeamRevenueProvider::yearMonthBuckets('2026', $rev->listByYear('2026'));
expect_eq(count($buckets), 12, '연간은 12달 칸');
expect_eq((string) ($buckets[2]['month'] ?? ''), '03', '3월 칸');
expect_eq((int) ($buckets[2]['count'] ?? 0), 2, '3월 2건');
expect_eq((int) ($buckets[2]['supply_krw'] ?? 0), 1200000, '3월 공급가');
expect_eq((int) ($buckets[2]['vat_krw'] ?? 0), 120000, '3월 부가세');
expect_eq((int) ($buckets[2]['amount_krw'] ?? 0), 1320000, '3월 합계');
expect_eq((int) ($buckets[0]['count'] ?? 0), 0, '1월은 만들지 않음');

$rev->delete($repeatId);
expect_eq(count($rev->listByMonth('2026-03')), 1, '반복 줄 삭제');
expect_eq($rev->ensureMonth('2026-03'), 1, '다시 열면 재생성');

$patched = $rev->update((int) $one['id'], [
    'project_name'  => '단건',
    'assignee'      => '단테',
    'received_date' => '2026-04-01',
    'supply_krw'    => 200000,
    'vat_krw'       => 20000,
    'amount_krw'    => 999999,
    'money_field'   => 'amount',
    'status'        => 'COMPLETED',
]);
expect_eq((int) ($patched['amount_krw'] ?? 0), 999999, '합계만 수정');
expect_eq((int) ($patched['supply_krw'] ?? 0), 200000, '공급가 유지');
expect_eq((string) ($patched['target_year_month'] ?? ''), '2026-04', '입금일→년월');

$tpl->delete((int) $row['id']);
$left = $rev->listByMonth('2026-03');
$linked = array_values(array_filter($left, static fn ($r) => (int) ($r['revenue_template_id'] ?? 0) > 0));
expect_eq(count($linked), 0, '템플릿 삭제 후 FK 비움');

$rev->setNow(new DateTimeImmutable('2026-09-14', new DateTimeZone('Asia/Seoul')));
try {
    $rev->createOneOff([
        'project_name'  => '잠금',
        'assignee'      => '루시',
        'received_date' => '2026-07-01',
        'amount_krw'    => 1000,
    ]);
    expect_true(false, '전전월 생성은 실패해야 함');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '전전월'), '전전월 생성 오류');
}
try {
    $rev->update((int) $one['id'], [
        'project_name'  => '단건',
        'assignee'      => '단테',
        'received_date' => '2026-07-01',
        'amount_krw'    => 1,
    ]);
    expect_true(false, '전전월로 옮기면 실패해야 함');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '전전월'), '전전월 수정 오류');
}

$rev->setBypassMonthLock(true);
$adminJuly = $rev->createOneOff([
    'project_name'  => '관리자',
    'assignee'      => '루시',
    'received_date' => '2026-07-01',
    'amount_krw'    => 1000,
]);
expect_eq((string) ($adminJuly['target_year_month'] ?? ''), '2026-07', 'admin은 전전월 생성');
$adminPatched = $rev->update((int) $adminJuly['id'], [
    'project_name'  => '관리자',
    'assignee'      => '루시',
    'received_date' => '2026-06-01',
    'amount_krw'    => 2000,
]);
expect_eq((string) ($adminPatched['target_year_month'] ?? ''), '2026-06', 'admin은 전전월 수정');
$rev->setBypassMonthLock(false);

$pnl = $pdo->query("SELECT revenue_krw FROM vi_monthly_pnl WHERE ym = '2026-03'")->fetch();
expect_true((int) ($pnl['revenue_krw'] ?? 0) >= 1100000, '뷰는 합계');

Database::reset();
@unlink($path);

tbb_test_done();
