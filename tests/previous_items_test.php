<?php
/**
 * 지난달 결제 줄·내용 불러오기 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/previous_items_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_prev_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();

$cacheDir = sys_get_temp_dir() . '/tbb_fx_prev_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0775, true);

$templates = new ExpenseTemplateProvider($pdo);
$provider = new DraftExpenseProvider(
    $pdo,
    $templates,
    new ExchangeRateProvider(
        'https://lucy-dev.conbus.co.kr/api/exchange/',
        $cacheDir,
        static function (): ?string {
            return json_encode([
                'success'   => true,
                'date'      => '2026-08-23',
                'rate_date' => '2026-08-21',
                'currency'  => 'EUR',
                'rate'      => 1595.23,
                'unit'      => 1,
            ]);
        }
    ),
    new KrwAmountProcessor(),
    new DraftTextProcessor()
);

$rocketId = (int) $pdo->query(
    "SELECT id FROM tb_expense_templates WHERE title LIKE 'Rocket%'"
)->fetchColumn();

$empty = $provider->loadPrevious($rocketId, '2026-09');
expect_eq($empty['found'], false, '이전이 없으면 found false');
expect_eq($empty['items'], [], '이전이 없으면 줄 없음');

$provider->createFromTemplate($rocketId, '2026-08', [
    [
        'payment_date' => '2026-08-23',
        'description'  => 'Rocket API Startup Plan',
        'currency'     => 'EUR',
        'amount'       => 99,
        'item_kind'    => 'PAY',
    ],
    [
        'payment_date' => '2026-08-19',
        'description'  => 'X Developer API 충전',
        'currency'     => 'USD',
        'amount'       => 55,
        'item_kind'    => 'CHARGE',
    ],
], '충전 > X Developer API 충전 $55 (8/19)');

$prev = $provider->loadPrevious($rocketId, '2026-09');
expect_eq($prev['found'], true, '8월 기안을 찾음');
expect_eq($prev['from_month'], '2026-08', '출처 월');
expect_eq(count($prev['items']), 2, '줄 2개');
expect_eq((string) ($prev['items'][0]['payment_date'] ?? ''), '2026-09-23', '결제일 9월 같은 날');
expect_eq((string) ($prev['items'][0]['item_kind'] ?? ''), 'PAY', '구분 유지');
expect_eq((string) ($prev['items'][1]['item_kind'] ?? ''), 'CHARGE', '충전 구분');
expect_eq((string) ($prev['items'][1]['payment_date'] ?? ''), '2026-09-19', '충전일도 같은 날');
expect_true(str_contains((string) ($prev['contents_text'] ?? ''), '9/19') || str_contains((string) ($prev['contents_text'] ?? ''), '(9/19)'), '내용 날짜 월 이동은 연월 치환만');

$teamId = (int) $pdo->query(
    "SELECT id FROM tb_expense_templates WHERE title = '팀 운영비 정산'"
)->fetchColumn();
$provider->createFromTemplate($teamId, '2026-07', [
    [
        'payment_date' => '2026-07-16',
        'description'  => '팀 회식',
        'vendor'       => '식당A',
        'note'         => '프로젝트X',
        'currency'     => 'KRW',
        'amount'       => 23600,
    ],
]);
$teamPrev = $provider->loadPrevious($teamId, '2026-08');
expect_eq((string) ($teamPrev['items'][0]['vendor'] ?? ''), '식당A', '지난달 거래처 유지');
expect_eq((string) ($teamPrev['items'][0]['note'] ?? ''), '프로젝트X', '지난달 비고 유지');
expect_eq((string) ($teamPrev['items'][0]['payment_date'] ?? ''), '2026-08-16', '팀비 날짜도 이번 달로');

Database::reset();
@unlink($path);
tbb_test_done();
