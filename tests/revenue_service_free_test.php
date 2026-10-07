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
    'service_category' => '솔루션', 'billing_type' => 'PAID', 'supply_krw' => 1000000,
]);
expect_eq((string) $paid['service_category'], '솔루션', '유상 서비스구분 저장');
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

// 우리 솔루션: 서비스구분=솔루션일 때만 저장, 그 외엔 비움
$withSol = $rev->createOneOff([
    'project_name' => '솔루션 건', 'assignee' => '루시', 'received_date' => '2026-10-07',
    'service_category' => '솔루션', 'billing_type' => 'PAID', 'supply_krw' => 100000,
    'solution_id' => 'lift_optima', 'solution_name' => 'Lift Optima',
]);
expect_eq((string) $withSol['solution_name'], 'Lift Optima', '솔루션: 우리 솔루션 이름 저장');
expect_eq((string) $withSol['solution_id'], 'lift_optima', '솔루션: 우리 솔루션 id 저장');

$nonSol = $rev->createOneOff([
    'project_name' => '컨설팅 건', 'assignee' => '루시', 'received_date' => '2026-10-07',
    'service_category' => '컨설팅', 'billing_type' => 'PAID', 'supply_krw' => 100000,
    'solution_id' => 'lift_optima', 'solution_name' => 'Lift Optima',
]);
expect_eq((string) $nonSol['solution_name'], '', '비솔루션 구분: 우리 솔루션 이름 비움');
expect_eq((string) $nonSol['solution_id'], '', '비솔루션 구분: 우리 솔루션 id 비움');

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

// 무상: 정상가 없이 총액(amount)만 주면 총액을 무상 가치로 환산 저장 (폼이 공급가/부가세/총액만 보냄)
$freeAmt = $rev->createOneOff([
    'project_name' => '무상 총액환산', 'assignee' => '루시', 'received_date' => '2026-10-05',
    'service_category' => '기타', 'billing_type' => 'FREE',
    'supply_krw' => 300000, 'vat_krw' => 30000, 'amount_krw' => 330000,
]);
expect_eq((int) $freeAmt['amount_krw'], 0, '무상 합계 0(총액환산)');
expect_eq((int) $freeAmt['supply_krw'], 0, '무상 공급가 0(총액환산)');
expect_eq((int) $freeAmt['list_value_krw'], 330000, '무상: 총액을 무상 가치로 환산');
expect_eq($rev->freeValueByMonth('2026-10'), 330000, '10월 무상 제공 총액(총액환산)');

// 무상 제공 총액 집계 (위에서 만든 무상/전환 건 기준)
$rev2 = new TeamRevenueProvider($pdo, new RevenueTemplateProvider($pdo));
expect_eq($rev2->freeValueByMonth('2026-09'), 1000000, '9월 무상 제공 총액');
expect_eq($rev2->freeValueByYear('2026'), 1330000, '2026 무상 제공 총액(9월 100만 + 10월 33만)');

tbb_test_done();
