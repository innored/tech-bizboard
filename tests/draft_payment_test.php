<?php
/**
 * 기안 결제 항목 여러 줄 작성 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/draft_payment_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_pay_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();

$tables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name='tb_draft_payment_items'"
)->fetchAll(PDO::FETCH_COLUMN);
expect_eq($tables, ['tb_draft_payment_items'], '결제 항목 테이블');

$cacheDir = sys_get_temp_dir() . '/tbb_fx_pay_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0775, true);

$provider = new DraftExpenseProvider(
    $pdo,
    new ExpenseTemplateProvider($pdo),
    new ExchangeRateProvider(
        'https://lucy-dev.conbus.co.kr/api/exchange/',
        $cacheDir,
        static function (string $url): ?string {
            if (str_contains($url, 'currency=EUR')) {
                return json_encode([
                    'success'   => true,
                    'date'      => '2026-08-23',
                    'rate_date' => '2026-08-21',
                    'currency'  => 'EUR',
                    'rate'      => 1595.23,
                    'unit'      => 1,
                ]);
            }
            if (str_contains($url, 'currency=USD')) {
                return json_encode([
                    'success'   => true,
                    'date'      => '2026-08-19',
                    'rate_date' => '2026-08-19',
                    'currency'  => 'USD',
                    'rate'      => 1373.6,
                    'unit'      => 1,
                ]);
            }

            return null;
        }
    ),
    new KrwAmountProcessor(),
    new DraftTextProcessor()
);
$provider->setBypassMonthLock(true);

$rocketId = (int) $pdo->query(
    "SELECT id FROM tb_expense_templates WHERE title LIKE 'Rocket%'"
)->fetchColumn();
$kinxId = (int) $pdo->query(
    "SELECT id FROM tb_expense_templates WHERE title LIKE 'KINX%'"
)->fetchColumn();

$optional = $provider->previewFromTemplate($kinxId, '2026-08', [
    [
        'description' => '서버 사용료',
        'currency'    => 'KRW',
        'amount'      => 830578,
    ],
]);
expect_eq((string) ($optional['items'][0]['payment_date'] ?? 'x'), '', '결제일 없으면 빈값');
expect_eq((string) ($optional['items'][0]['item_kind'] ?? 'x'), '', '구분 없으면 빈값');
expect_eq((int) ($optional['amount_krw'] ?? 0), 830578, '원화는 그대로');
expect_true(!str_contains((string) ($optional['draft_body'] ?? ''), '결제 >'), '구분 없으면 결제 접두어 없음');

try {
    $provider->createFromTemplate($rocketId, '2026-08', []);
    expect_true(false, '결제 항목 없이 작성은 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '결제'), '결제 항목 필수 메시지');
}

$row = $provider->createFromTemplate($rocketId, '2026-08', [
    [
        'payment_date' => '2026-08-23',
        'description'  => 'Rocket API Startup Plan',
        'currency'     => 'EUR',
        'amount'       => 99,
    ],
    [
        'payment_date' => '2026-08-19',
        'description'  => 'X Developer API 충전',
        'currency'     => 'USD',
        'amount'       => 55,
    ],
]);

expect_true((int) ($row['id'] ?? 0) > 0, '작성 id');
expect_eq((int) ($row['amount_krw'] ?? 0), 157928 + 75548, '헤더 원화는 줄 합');
expect_true(str_contains((string) ($row['draft_body'] ?? ''), 'EUR 99'), '본문에 EUR');
expect_true(str_contains((string) ($row['draft_body'] ?? ''), 'USD 55'), '본문에 USD');
expect_true(str_contains((string) ($row['draft_body'] ?? ''), '합계 233,476원'), '본문 합계');

$items = $row['items'] ?? [];
expect_eq(count($items), 2, '결제 줄 2개');
expect_eq((string) ($items[0]['currency'] ?? ''), 'EUR', '첫 줄 EUR');
expect_eq((int) ($items[1]['amount_krw'] ?? 0), 75548, 'USD 줄 원화');

$updated = $provider->replaceItems((int) $row['id'], [
    [
        'payment_date' => '2026-08-05',
        'description'  => '서버 사용료',
        'currency'     => 'KRW',
        'amount'       => 120000,
    ],
]);
expect_eq(count($updated['items'] ?? []), 1, '줄 교체 후 1개');
expect_eq((int) ($updated['amount_krw'] ?? 0), 120000, '헤더 원화 갱신');
expect_true(str_contains((string) ($updated['draft_body'] ?? ''), 'KRW 120,000'), '본문 원화 줄');
expect_true(!str_contains((string) ($updated['draft_body'] ?? ''), 'EUR 99'), '이전 줄 제거');

$quote = $provider->quoteRate('2026-08-23', 'EUR', 99, true);
expect_true(($quote['success'] ?? false) === true, '환율 강제 조회 성공');
expect_eq((float) ($quote['rate'] ?? 0), 1595.23, 'EUR 강제 조회 rate');
expect_eq((int) ($quote['amount_krw'] ?? 0), 157928, 'EUR 강제 조회 원화');

$todayQuote = $provider->quoteRate('', 'EUR', 99, true);
expect_true(($todayQuote['success'] ?? false) === true, '결제일 없으면 오늘 환율');
expect_eq((int) ($todayQuote['amount_krw'] ?? 0), 157928, '오늘 환율 원화');

$noDateFx = $provider->previewFromTemplate($rocketId, '2026-08', [
    [
        'description' => 'Rocket API',
        'currency'    => 'EUR',
        'amount'      => 99,
    ],
]);
expect_eq((string) ($noDateFx['items'][0]['payment_date'] ?? 'x'), '', '환율만 쓰고 결제일은 비움');
expect_eq((int) ($noDateFx['items'][0]['amount_krw'] ?? 0), 157928, '결제일 없어도 외화 원화');

$override = $provider->previewFromTemplate($rocketId, '2026-08', [
    [
        'description'         => 'Rocket API',
        'currency'            => 'EUR',
        'amount'              => 99,
        'amount_krw'          => 150000,
        'amount_krw_manual'   => true,
    ],
]);
expect_eq((int) ($override['items'][0]['amount_krw'] ?? 0), 150000, '수기 원화가 환율보다 우선');

$batch = $provider->quoteRates([
    ['date' => '2026-08-23', 'currency' => 'EUR', 'amount' => 99],
    ['date' => '2026-08-19', 'currency' => 'USD', 'amount' => 55],
]);
expect_eq(count($batch), 2, '여러 통화 한 번에');
expect_true(($batch[0]['success'] ?? false) === true, '배치 EUR 성공');
expect_true(($batch[1]['success'] ?? false) === true, '배치 USD 성공');
expect_eq((int) ($batch[0]['amount_krw'] ?? 0), 157928, '배치 EUR 원화');
expect_eq((int) ($batch[1]['amount_krw'] ?? 0), 75548, '배치 USD 원화');

$teamId = (int) $pdo->query(
    "SELECT id FROM tb_expense_templates WHERE title = '팀 운영비 정산'"
)->fetchColumn();
$team = $provider->createFromTemplate($teamId, '2026-07', [
    [
        'payment_date' => '2026-07-16',
        'description'  => '팀 회식',
        'vendor'       => '식당A',
        'note'         => '프로젝트X',
        'currency'     => 'KRW',
        'amount'       => 23600,
    ],
    [
        'payment_date' => '2026-07-20',
        'description'  => '간식',
        'vendor'       => '편의점',
        'note'         => '',
        'currency'     => 'KRW',
        'amount'       => 8000,
    ],
]);
expect_eq((string) ($team['items'][0]['vendor'] ?? ''), '식당A', '팀비 거래처 저장');
expect_eq((string) ($team['items'][0]['note'] ?? ''), '프로젝트X', '팀비 비고 저장');
expect_eq(
    (string) ($team['draft_body'] ?? ''),
    "7/16\t팀 회식\t식당A\t23,600\t프로젝트X\n7/20\t간식\t편의점\t8,000\t",
    '팀비 작성 본문은 탭 구분'
);

Database::reset();
@unlink($path);
foreach (glob($cacheDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);

$path2 = sys_get_temp_dir() . '/tbb_pay_fail_' . bin2hex(random_bytes(4)) . '.db';
$cache2 = sys_get_temp_dir() . '/tbb_fx_fail_' . bin2hex(random_bytes(4));
mkdir($cache2, 0775, true);
Database::reset();
Database::setPath($path2);
$pdo2 = Database::connection();
$failing = new DraftExpenseProvider(
    $pdo2,
    new ExpenseTemplateProvider($pdo2),
    new ExchangeRateProvider(
        'https://lucy-dev.conbus.co.kr/api/exchange/',
        $cache2,
        static function (): ?string {
            return json_encode(['success' => false, 'message' => '고시 없음']);
        }
    ),
    new KrwAmountProcessor(),
    new DraftTextProcessor()
);
$rocketId2 = (int) $pdo2->query(
    "SELECT id FROM tb_expense_templates WHERE title LIKE 'Rocket%'"
)->fetchColumn();
$zero = $failing->createFromTemplate($rocketId2, '2026-08', [
    [
        'payment_date' => '2026-08-23',
        'description'  => 'Rocket API',
        'currency'     => 'EUR',
        'amount'       => 99,
    ],
], '', '직접 고친 본문', '직접 고친 제목');
expect_eq((int) ($zero['amount_krw'] ?? -1), 0, '환율 실패 시 원화 0');
expect_eq((int) ($zero['items'][0]['amount_krw'] ?? -1), 0, '줄 원화 0');

$manualZero = $failing->previewFromTemplate($rocketId2, '2026-08', [
    [
        'description'       => 'Rocket API',
        'currency'          => 'EUR',
        'amount'            => 99,
        'amount_krw'        => 161000,
        'amount_krw_manual' => true,
    ],
]);
expect_eq((int) ($manualZero['items'][0]['amount_krw'] ?? 0), 161000, '환율 실패해도 수기 원화');
expect_eq((string) ($zero['draft_body'] ?? ''), '직접 고친 본문', '작성 시 내용 그대로 저장');
expect_eq((string) ($zero['draft_title'] ?? ''), '직접 고친 제목', '작성 시 제목 그대로 저장');

$kept = $failing->replaceItems((int) $zero['id'], [
    [
        'payment_date' => '2026-08-23',
        'description'  => 'Rocket API',
        'currency'     => 'EUR',
        'amount'       => 99,
    ],
], null, '반영한 본문', '반영한 제목');
expect_eq((string) ($kept['draft_body'] ?? ''), '반영한 본문', '반영 시 내용 그대로');
expect_eq((string) ($kept['draft_title'] ?? ''), '반영한 제목', '반영 시 제목 그대로');

Database::reset();
@unlink($path2);
foreach (glob($cache2 . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cache2);

tbb_test_done();
