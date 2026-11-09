<?php
/**
 * 서비스구분 유지보수·개발비
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/revenue_maintenance_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_revmnt_' . bin2hex(random_bytes(4)) . '.db';
Database::reset(); Database::setPath($path); $pdo = Database::connection();
$rev = new TeamRevenueProvider($pdo, new RevenueTemplateProvider($pdo));
$rev->setCreatedBy('lucy@innored.co.kr');
$rev->setBypassMonthLock(true);

$row = $rev->createOneOff([
    'project_name' => '사이트 유지보수', 'assignee' => '루시', 'received_date' => '2026-11-05',
    'service_category' => '유지보수', 'billing_type' => 'PAID', 'supply_krw' => 500000,
    'solution_name' => '무시됨', 'solution_group' => 'ADTECH',
]);
expect_eq((string) $row['service_category'], '유지보수', '유지보수 저장');
expect_eq((int) $row['amount_krw'], 550000, '유지보수 합계 = 공급가+부가세');
expect_eq((string) $row['solution_group'], '', '솔루션이 아니면 솔루션구분 없음');

$tpl = (new RevenueTemplateProvider($pdo))->create([
    'project_name' => '월 유지보수', 'client_name' => '콘버스', 'assignee' => '루시',
    'service_category' => '유지보수', 'billing_type' => 'PAID', 'supply_krw' => 300000,
]);
expect_eq((string) $tpl['service_category'], '유지보수', '반복 설정에도 유지보수 저장');

$q = RevenueListQuery::resolve(['year' => '2026', 'service' => '유지보수'], '2026-11-09');
expect_eq($q['service'], '유지보수', '연간 필터 유지보수');

$dev = $rev->createOneOff([
    'project_name' => '관리자 화면 개발', 'assignee' => '루시', 'received_date' => '2026-11-06',
    'service_category' => '개발비', 'billing_type' => 'PAID', 'supply_krw' => 2000000,
]);
expect_eq((string) $dev['service_category'], '개발비', '개발비 저장');
expect_eq((int) $dev['amount_krw'], 2200000, '개발비 합계 = 공급가+부가세');
$qd = RevenueListQuery::resolve(['year' => '2026', 'service' => '개발비'], '2026-11-09');
expect_eq($qd['service'], '개발비', '연간 필터 개발비');

tbb_test_done();
