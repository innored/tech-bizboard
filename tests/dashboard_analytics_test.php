<?php
declare(strict_types=1);
require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_dashan_' . bin2hex(random_bytes(4)) . '.db';
Database::reset(); Database::setPath($path); $pdo = Database::connection();
$rev = new TeamRevenueProvider($pdo, new RevenueTemplateProvider($pdo));
$rev->setCreatedBy('lucy@innored.co.kr');

// 유상: 솔루션 2건(거래처 A·B), 컨설팅 1건(거래처 A)
$rev->createOneOff(['project_name'=>'p1','assignee'=>'루시','received_date'=>'2026-10-05','service_category'=>'솔루션','solution_group'=>'GENERAL','solution_id'=>'lift_optima','solution_name'=>'Lift Optima','billing_type'=>'PAID','supply_krw'=>1000000]);
$rev->createOneOff(['project_name'=>'p2','assignee'=>'루시','received_date'=>'2026-10-05','service_category'=>'솔루션','solution_group'=>'GENERAL','solution_id'=>'lift_optima','solution_name'=>'Lift Optima','client_name'=>'B','billing_type'=>'PAID','supply_krw'=>2000000]);
$rev->createOneOff(['project_name'=>'p3','assignee'=>'루시','received_date'=>'2026-10-05','service_category'=>'컨설팅','client_name'=>'A','billing_type'=>'PAID','supply_krw'=>500000]);
// p1 거래처도 A
$pdo->exec("UPDATE tb_team_revenues SET client_name='A' WHERE project_name='p1'");

// 무상: 솔루션 Hawk-AI 2건(거래처 C), 하나는 총액 30만, 하나는 0
$rev->createOneOff(['project_name'=>'f1','assignee'=>'루시','received_date'=>'2026-10-05','service_category'=>'솔루션','solution_group'=>'GENERAL','solution_name'=>'Hawk-AI','client_name'=>'C','billing_type'=>'FREE','amount_krw'=>300000]);
$rev->createOneOff(['project_name'=>'f2','assignee'=>'루시','received_date'=>'2026-10-05','service_category'=>'솔루션','solution_group'=>'GENERAL','solution_name'=>'Hawk-AI','client_name'=>'C','billing_type'=>'FREE','amount_krw'=>0]);

$a = (new DashboardProvider($pdo))->revenueAnalytics(2026);

// 유/무상 요약
expect_eq((int) $a['billing']['paid_count'], 3, '유상 3건');
expect_eq((int) $a['billing']['paid_amount'], 3850000, '유상 매출 합계(1.1M+2.2M+0.55M)');
expect_eq((int) $a['billing']['free_count'], 2, '무상 2건');
expect_eq((int) $a['billing']['free_value'], 300000, '무상 정상가 합계(30만+0)');

// 서비스 구분: 솔루션(매출 3.3M)이 컨설팅(0.55M)보다 앞
expect_eq((string) $a['service_categories'][0]['label'], '솔루션', '서비스구분 1위=솔루션');
expect_eq((int) $a['service_categories'][0]['paid_amount'], 3300000, '솔루션 유상 매출');
expect_eq((int) $a['service_categories'][0]['free_count'], 2, '솔루션 무상 건수(교차)');
expect_eq((string) $a['service_categories'][1]['label'], '컨설팅', '서비스구분 2위=컨설팅');

// 솔루션별: Lift Optima(유상 3.3M) 1위, Hawk-AI(무상만)
expect_eq((string) $a['solutions'][0]['label'], 'Lift Optima', '솔루션 1위=Lift Optima');
expect_eq((int) $a['solutions'][0]['paid_amount'], 3300000, 'Lift Optima 유상 매출');
$hawk = null;
foreach ($a['solutions'] as $s) { if ($s['label'] === 'Hawk-AI') { $hawk = $s; break; } }
expect_true($hawk !== null, '솔루션 목록에 Hawk-AI');
expect_eq((int) $hawk['free_count'], 2, 'Hawk-AI 무상 2건');

// 무상 집중: 거래처 C 2건, 솔루션 Hawk-AI 2건
expect_eq((string) $a['free_top_clients'][0]['label'], 'C', '무상 거래처 1위=C');
expect_eq((int) $a['free_top_clients'][0]['free_count'], 2, '무상 거래처 C 2건');
expect_eq((string) $a['free_top_solutions'][0]['label'], 'Hawk-AI', '무상 솔루션 1위=Hawk-AI');
expect_eq((int) $a['free_top_solutions'][0]['free_count'], 2, '무상 솔루션 Hawk-AI 2건');

Database::reset(); @unlink($path);
tbb_test_done();
