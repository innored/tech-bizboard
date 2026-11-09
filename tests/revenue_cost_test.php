<?php
/**
 * 개발비·유지보수 원가(수익 = 공급가 - 원가)
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/revenue_cost_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_revcost_' . bin2hex(random_bytes(4)) . '.db';
Database::reset(); Database::setPath($path); $pdo = Database::connection();
$tpls = new RevenueTemplateProvider($pdo);
$rev = new TeamRevenueProvider($pdo, $tpls);
$rev->setCreatedBy('lucy@innored.co.kr');
$rev->setBypassMonthLock(true);

foreach (['tb_team_revenues', 'tb_revenue_templates'] as $table) {
    $cols = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect_true(in_array('cost_krw', $cols, true) && in_array('cost_note', $cols, true), $table . ' 원가 컬럼');
}

$base = ['project_name' => 'LG U+ 심플 사서함', 'assignee' => '루시', 'received_date' => '2026-11-05', 'billing_type' => 'PAID'];

$dev = $rev->createOneOff(['service_category' => '개발비', 'supply_krw' => 95000000, 'cost_krw' => '10,867,884', 'cost_note' => ' ARS업체 10,300,000 / 서버비 567,884 '] + $base);
expect_eq((int) $dev['cost_krw'], 10867884, '개발비 원가 저장(콤마 허용)');
expect_eq((string) $dev['cost_note'], 'ARS업체 10,300,000 / 서버비 567,884', '원가 메모 저장');
expect_eq((int) $dev['supply_krw'], 95000000, '원가는 공급가를 건드리지 않음');

$ops = $rev->createOneOff(['service_category' => '유지보수', 'supply_krw' => 0, 'cost_krw' => 373338, 'cost_note' => '서버비'] + $base);
expect_eq([(int) $ops['supply_krw'], (int) $ops['cost_krw']], [0, 373338], '청구 없는 달: 공급가 0, 원가만');

$keep = $rev->update((int) $dev['id'], ['note' => '메모만 수정']);
expect_eq((int) $keep['cost_krw'], 10867884, '원가를 안 보내면 기존 원가 유지');

$cons = $rev->createOneOff(['service_category' => '컨설팅', 'supply_krw' => 3000000, 'cost_krw' => 500000, 'cost_note' => '외주'] + $base);
expect_eq((int) $cons['cost_krw'], 500000, '컨설팅도 원가 받음(용역)');
$etc = $rev->createOneOff(['service_category' => '기타', 'supply_krw' => 1000, 'cost_krw' => 100] + $base);
expect_eq((int) $etc['cost_krw'], 100, '기타도 원가 받음(용역)');

$sol = $rev->createOneOff(['service_category' => '솔루션', 'solution_group' => 'GENERAL', 'supply_krw' => 1000, 'cost_krw' => 500, 'cost_note' => 'x'] + $base);
expect_eq([(int) $sol['cost_krw'], (string) $sol['cost_note']], [0, ''], '솔루션은 원가 없음');

$free = $rev->createOneOff(['service_category' => '개발비', 'billing_type' => 'FREE', 'amount_krw' => 1000, 'cost_krw' => 500] + $base);
expect_eq((int) $free['cost_krw'], 0, '무상은 원가 없음');

$toSol = $rev->update((int) $ops['id'], ['service_category' => '솔루션', 'solution_group' => 'GENERAL']);
expect_eq([(int) $toSol['cost_krw'], (string) $toSol['cost_note']], [0, ''], '솔루션으로 바꾸면 원가 지움');

try {
    $rev->createOneOff(['service_category' => '개발비', 'supply_krw' => 1000, 'cost_krw' => -1] + $base);
    expect_true(false, '음수 원가 거부');
} catch (InvalidArgumentException $e) {
    expect_eq($e->getMessage(), '원가는 0 이상이어야 합니다.', '음수 원가 거부');
}

$long = $rev->createOneOff(['service_category' => '개발비', 'supply_krw' => 1000, 'cost_krw' => 1, 'cost_note' => str_repeat('가', 300)] + $base);
expect_eq(mb_strlen((string) $long['cost_note']), 255, '원가 메모 255자');

$tpl = $tpls->create(['project_name' => 'LG U+ 심플 사서함', 'assignee' => '루시', 'service_category' => '유지보수', 'billing_type' => 'PAID',
    'supply_krw' => 4275461, 'cost_krw' => 1445040, 'cost_note' => 'ARS업체 1,000,000 / 서버비 445,040', 'start_year_month' => '2026-12']);
expect_eq((int) $tpl['cost_krw'], 1445040, '반복 설정 원가 저장');
$rev->ensureMonth('2026-12');
$made = array_values(array_filter($rev->listByMonth('2026-12'), static fn (array $r): bool => (int) ($r['revenue_template_id'] ?? 0) === (int) $tpl['id']));
expect_eq([(int) ($made[0]['cost_krw'] ?? -1), (string) ($made[0]['cost_note'] ?? '')], [1445040, 'ARS업체 1,000,000 / 서버비 445,040'], '반복 설정 → 월 행으로 원가 복사');

@unlink($path);
tbb_test_done();
