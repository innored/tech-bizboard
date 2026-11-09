<?php
/**
 * 템플릿 filename_pattern 필드 라운드트립 테스트
 *
 * @date 2026-09-15
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/template_filename_pattern_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_fptn_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();
$provider = new ExpenseTemplateProvider($pdo);
$provider->setCreatedBy('lucy@innored.co.kr');

$payload = [
    'title'            => '테스트 구독',
    'assignee'         => '루시',
    'account_info'     => '법인카드',
    'payment_site'     => 'https://cursor.com/dashboard',
    'cycle_type'       => 'MONTHLY',
    'currency'         => 'USD',
    'payment_type'     => 'AUTO',
    'title_pattern'    => '[테스트] 구독료 결제 기안',
    'body_pattern'     => "건명: 테스트 구독\n금액은 작성 시 입력",
    'note'             => '직접 지정한 본문',
    'is_active'        => 1,
    'filename_pattern' => '{year_month}_{user}_커서·클로드',
];

$row = $provider->create($payload);
expect_eq((string) ($row['filename_pattern'] ?? ''), '{year_month}_{user}_커서·클로드', 'filename_pattern 저장');

$upd = $provider->update((int) $row['id'], array_merge($payload, ['filename_pattern' => '{year_month}_{title}']));
expect_eq((string) ($upd['filename_pattern'] ?? ''), '{year_month}_{title}', 'filename_pattern 수정');

Database::reset();
@unlink($path);
tbb_test_done();
