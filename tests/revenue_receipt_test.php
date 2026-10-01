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
tbb_test_done();
