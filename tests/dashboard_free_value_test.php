<?php
/**
 * DashboardProvider::summarize() year_free_value 테스트
 *
 * @date 2026-10-01
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/dashboard_free_value_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_dash_free_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();
$now = new DateTimeImmutable('2026-10-01', new DateTimeZone('Asia/Seoul'));

$rev = new TeamRevenueProvider($pdo, new RevenueTemplateProvider($pdo));
$rev->setBypassMonthLock(true); // 테스트: 월 잠금 우회

// 무상 수입 1건: list_value_krw=300000, billing_type='FREE', amount_krw=0
$rev->createOneOff([
    'project_name'   => '무상 테스트',
    'assignee'       => '루시',
    'received_date'  => '2026-03-15',
    'billing_type'   => 'FREE',
    'list_value_krw' => 300000,
]);

// 유상 수입 1건: billing_type='PAID' — year_free_value에 포함되지 않아야 함
$rev->createOneOff([
    'project_name'  => '유상 테스트',
    'assignee'      => '루시',
    'received_date' => '2026-05-10',
    'amount_krw'    => 100000,
]);

$dash = new DashboardProvider($pdo);
$out = $dash->summarize(2026, $now);

expect_true(array_key_exists('year_free_value', $out), 'year_free_value 키 존재');
expect_eq($out['year_free_value'], 300000, '무상 제공 총액 300000');

// 다른 해는 0
$other = $dash->summarize(2025, $now);
expect_eq($other['year_free_value'], 0, '데이터 없는 해 무상 제공 총액 0');

tbb_test_done();
