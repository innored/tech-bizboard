<?php
declare(strict_types=1);
require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_revsvc_' . bin2hex(random_bytes(4)) . '.db';
Database::reset(); Database::setPath($path); $pdo = Database::connection();
$rev = new TeamRevenueProvider($pdo, new RevenueTemplateProvider($pdo));
$rev->setCreatedBy('lucy@innored.co.kr');

// 유상 + 서비스구분
$paid = $rev->createOneOff([
    'project_name' => '솔루션 연동', 'assignee' => '루시', 'received_date' => '2026-09-10',
    'service_category' => '솔루션 서비스', 'billing_type' => 'PAID', 'supply_krw' => 1000000,
]);
expect_eq((string) $paid['service_category'], '솔루션 서비스', '유상 서비스구분 저장');
expect_eq((int) $paid['amount_krw'], 1100000, '유상 합계 = 공급가+부가세');
expect_eq((int) $paid['list_value_krw'], 0, '유상 정상가 0');

// 무상: 금액을 보내도 0으로 강제, 정상가만 보존
$free = $rev->createOneOff([
    'project_name' => '무상 컨설팅', 'assignee' => '루시', 'received_date' => '2026-09-11',
    'service_category' => '컨설팅', 'billing_type' => 'FREE',
    'supply_krw' => 500000, 'list_value_krw' => 500000,
]);
expect_eq((int) $free['amount_krw'], 0, '무상 합계 0');
expect_eq((int) $free['supply_krw'], 0, '무상 공급가 0');
expect_eq((int) $free['vat_krw'], 0, '무상 부가세 0');
expect_eq((int) $free['list_value_krw'], 500000, '무상 정상가 보존');
expect_eq((string) $free['billing_type'], 'FREE', '무상 구분 저장');

// 유상→무상 전환
$toFree = $rev->update((int) $paid['id'], ['billing_type' => 'FREE', 'list_value_krw' => 1000000, 'supply_krw' => 1000000]);
expect_eq((int) $toFree['amount_krw'], 0, '유상→무상: 합계 0');
expect_eq((int) $toFree['list_value_krw'], 1000000, '유상→무상: 정상가 반영');

// 무상→유상 전환
$toPaid = $rev->update((int) $free['id'], ['billing_type' => 'PAID', 'supply_krw' => 200000]);
expect_eq((int) $toPaid['amount_krw'], 220000, '무상→유상: 합계 재계산');
expect_eq((int) $toPaid['list_value_krw'], 0, '무상→유상: 정상가 0');

// 잘못된 서비스구분 거부
$bad = false;
try { $rev->createOneOff(['project_name'=>'x','assignee'=>'루시','received_date'=>'2026-09-12','service_category'=>'해킹','billing_type'=>'PAID','supply_krw'=>1]); }
catch (InvalidArgumentException $e) { $bad = true; }
expect_true($bad, '목록 밖 서비스구분 거부');

// 유상 비표준 부가세: caller가 vat_krw+amount_krw를 직접 지정하면 덮어쓰지 않는다
$nonStdVat = $rev->createOneOff([
    'project_name' => '비표준 부가세', 'assignee' => '루시', 'received_date' => '2026-09-20',
    'billing_type' => 'PAID',
    'supply_krw' => 200000, 'vat_krw' => 10000, 'amount_krw' => 210000,
]);
expect_eq((int) $nonStdVat['vat_krw'],    10000,  '비표준 부가세 보존(덮어쓰기 금지)');
expect_eq((int) $nonStdVat['amount_krw'], 210000, '비표준 합계 보존(220000으로 덮으면 실패)');

tbb_test_done();
