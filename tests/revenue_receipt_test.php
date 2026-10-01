<?php
declare(strict_types=1);
require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';
$path = sys_get_temp_dir() . '/tbb_revrc_' . bin2hex(random_bytes(4)) . '.db';
Database::reset(); Database::setPath($path); $pdo = Database::connection();
$rev = new TeamRevenueProvider($pdo, new RevenueTemplateProvider($pdo)); $rev->setCreatedBy('lucy@innored.co.kr');
$r = $rev->createOneOff(['project_name'=>'솔루션','assignee'=>'루시','received_date'=>'2026-09-10','service_category'=>'솔루션 서비스','billing_type'=>'PAID','supply_krw'=>100000]);

$store = sys_get_temp_dir() . '/tbb_revstore_' . bin2hex(random_bytes(4));
$prov = new RevenueReceiptProvider($pdo, $store);
$prov->setCreatedBy('lucy@innored.co.kr');
$row = $prov->store((int) $r['id'], 'tax.pdf', '%PDF-1.4 dummy', 'application/pdf', '루시');
expect_true((int) ($row['id'] ?? 0) > 0, '증빙 저장 id');
expect_true(is_file($prov->absPath($row)), '증빙 파일 생성됨');
expect_eq(count($prov->listFor((int) $r['id'])), 1, 'listFor 1건');

$paths = $prov->deleteAllFor((int) $r['id']);
expect_eq(count($paths), 1, 'deleteAllFor 경로 1건');
expect_eq(count($prov->listFor((int) $r['id'])), 0, '삭제 후 0건');

// I-1: 존재하지 않는 revenue_id 로 store() 시 InvalidArgumentException 발생
$threw = false;
try {
    $prov->store(999999, 'dummy.pdf', '%PDF', 'application/pdf');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
expect_true($threw, '없는 revenue_id → InvalidArgumentException');

// 수입 삭제 → 증빙 정리 (proc와 동일 순서: deleteAllFor 먼저)
$r2 = $rev->createOneOff(['project_name'=>'c','assignee'=>'루시','received_date'=>'2026-09-13','service_category'=>'컨설팅','billing_type'=>'PAID','supply_krw'=>1]);
$prov->store((int) $r2['id'], 'a.pdf', '%PDF-1.4', 'application/pdf', '루시');
$paths2 = $prov->deleteAllFor((int) $r2['id']);
$rev->delete((int) $r2['id']);
expect_eq(count($prov->listFor((int) $r2['id'])), 0, '수입 삭제 흐름 후 증빙 0건');

// D-1: delete 흐름 후 파일이 실제로 디스크에서 제거되는지 검증 ($committed guard)
$r3 = $rev->createOneOff(['project_name'=>'파일삭제검증','assignee'=>'루시','received_date'=>'2026-09-14','service_category'=>'솔루션 서비스','billing_type'=>'PAID','supply_krw'=>5000]);
$row3 = $prov->store((int) $r3['id'], 'receipt3.pdf', '%PDF-1.4 d3', 'application/pdf', '루시');
$absPath3 = $prov->absPath($row3);
expect_true(is_file($absPath3), 'D-1: 삭제 전 파일 존재');
$paths3 = $prov->deleteAllFor((int) $r3['id']);
$rev->delete((int) $r3['id']);
foreach ($paths3 as $p) { if (is_file($p)) { @unlink($p); } }
expect_true(!is_file($absPath3), 'D-1: delete 흐름 후 파일 제거됨');
expect_eq(count($prov->listFor((int) $r3['id'])), 0, 'D-1: delete 흐름 후 listFor 0건');

tbb_test_done();
