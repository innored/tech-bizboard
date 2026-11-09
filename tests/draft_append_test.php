<?php
declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_append_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();

$cacheDir = sys_get_temp_dir() . '/tbb_fx_app_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0775, true);
$rates = new ExchangeRateProvider(
    'https://x/', $cacheDir,
    static fn (string $url): ?string => str_contains($url, 'currency=USD')
        ? json_encode(['success' => true, 'date' => '2026-09-03', 'rate_date' => '2026-09-03', 'currency' => 'USD', 'rate' => 1342.6, 'unit' => 1])
        : null
);
$provider = new DraftExpenseProvider($pdo, new ExpenseTemplateProvider($pdo), $rates, new KrwAmountProcessor(), new DraftTextProcessor());

// 대상 템플릿(예: Cursor) id 확보
$provider->ensureMonthlyDrafts('2026-09');
$templateId = 0;
$draftId0 = 0;
foreach ($provider->listByMonth('2026-09') as $row) {
    if (str_contains((string) $row['draft_title'], 'Cursor')) {
        $templateId = (int) $row['expense_template_id'];
        $draftId0 = (int) $row['id'];
    }
}
expect_true($templateId > 0, 'Cursor 템플릿 id 확보');

// 기존 항목 수 확인
$before = count($provider->findById($draftId0)['items'] ?? []);

// 항목 추가 1
$row1 = $provider->appendItem($templateId, '2026-09', [
    'description' => '항목 A', 'amount' => 20, 'currency' => 'USD', 'payment_date' => '2026-09-03', 'vendor' => 'Cursor',
]);
expect_eq(count($row1['items']), $before + 1, '항목 A 추가');

// 항목 추가 2 (같은 기안서에 누적, 앞 항목 유지)
$row2 = $provider->appendItem($templateId, '2026-09', [
    'description' => '항목 B', 'amount' => 30, 'currency' => 'USD', 'payment_date' => '2026-09-04', 'vendor' => 'Cursor',
]);
expect_eq(count($row2['items']), $before + 2, '항목 B 추가 후 둘 다 유지(클로버 없음)');
$descs = array_map(static fn ($i) => (string) $i['description'], $row2['items']);
expect_true(in_array('항목 A', $descs, true) && in_array('항목 B', $descs, true), '두 항목 모두 존재');

// 금액 합계가 반영됨
expect_true((int) $row2['amount_krw'] > (int) $row1['amount_krw'], '합계 원화 증가');

Database::reset();
@unlink($path);
foreach (glob($cacheDir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($cacheDir);
tbb_test_done();
