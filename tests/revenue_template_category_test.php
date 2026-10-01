<?php
declare(strict_types=1);
require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';
$path = sys_get_temp_dir() . '/tbb_revtpl_' . bin2hex(random_bytes(4)) . '.db';
Database::reset(); Database::setPath($path); $pdo = Database::connection();
$tpl = new RevenueTemplateProvider($pdo); $tpl->setCreatedBy('lucy@innored.co.kr');
$row = $tpl->create([
    'project_name'=>'솔루션 유지보수','client_name'=>'콘버스','supply_krw'=>1000000,
    'assignee'=>'루시','start_year_month'=>'2026-01','end_year_month'=>'2026-12',
    'service_category'=>'솔루션 서비스',
]);
expect_eq((string) $row['service_category'], '솔루션 서비스', '템플릿 서비스구분 저장');

$rev = new TeamRevenueProvider($pdo, $tpl); $rev->setCreatedBy('lucy@innored.co.kr');
$rev->ensureMonth('2026-06');
$rows = $rev->listByMonth('2026-06');
expect_true(count($rows) >= 1, '월 자동생성 행 존재');
expect_eq((string) $rows[0]['service_category'], '솔루션 서비스', '생성 행에 템플릿 category 복사');
tbb_test_done();
