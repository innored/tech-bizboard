<?php
/**
 * 기안 초안 금액 수정·상태 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/draft_update_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_update_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();

$cacheDir = sys_get_temp_dir() . '/tbb_fx_upd_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0775, true);

$rateCalls = 0;
$rates = new ExchangeRateProvider(
    'https://lucy-dev.conbus.co.kr/api/exchange/',
    $cacheDir,
    static function (string $url) use (&$rateCalls): ?string {
        $rateCalls++;
        if (str_contains($url, 'currency=USD')) {
            return json_encode([
                'success'   => true,
                'date'      => '2026-09-01',
                'rate_date' => '2026-09-01',
                'currency'  => 'USD',
                'rate'      => 1342.6,
                'unit'      => 1,
            ]);
        }
        if (str_contains($url, 'currency=EUR')) {
            return json_encode([
                'success'   => true,
                'date'      => '2026-09-01',
                'rate_date' => '2026-09-01',
                'currency'  => 'EUR',
                'rate'      => 1561.04,
                'unit'      => 1,
            ]);
        }

        return null;
    }
);

$provider = new DraftExpenseProvider(
    $pdo,
    new ExpenseTemplateProvider($pdo),
    $rates,
    new KrwAmountProcessor(),
    new DraftTextProcessor()
);

$provider->ensureMonthlyDrafts('2026-09');
$callsAfterCreate = $rateCalls;

$list = $provider->listByMonth('2026-09');
$cursor = null;
$kinx = null;
foreach ($list as $row) {
    if (str_contains((string) $row['draft_title'], 'Cursor')) {
        $cursor = $row;
    }
    if (str_contains((string) $row['draft_title'], 'KINX')) {
        $kinx = $row;
    }
}

expect_true($cursor !== null, 'Cursor 초안 존재');
expect_true($kinx !== null, 'KINX 초안 존재');
expect_eq((string) ($cursor['assignee'] ?? ''), '단테', '목록에 담당자');
expect_eq((string) ($kinx['assignee'] ?? ''), '베리', 'KINX 담당자');

$updated = $provider->updateAmount((int) $cursor['id'], 250.0);
expect_eq((float) ($updated['amount_foreign'] ?? 0), 250.0, '외화 250으로 수정');
expect_eq((int) ($updated['amount_krw'] ?? 0), 335650, '저장된 환율로 원화 재계산');
expect_eq((float) ($updated['exchange_rate'] ?? 0), 1342.6, '환율은 스냅샷 유지');
expect_eq($rateCalls, $callsAfterCreate, '금액 수정 시 환율 API 재호출 없음');

$provider->updateStatus((int) $kinx['id'], 'DONE');

$done = $provider->findById((int) $kinx['id']);
expect_true($done !== null, 'findById 존재');
expect_eq((string) ($done['status'] ?? ''), 'DONE', '완료 상태 저장');

$reopened = $provider->updateStatus((int) $kinx['id'], 'DRAFT');
expect_eq((string) ($reopened['status'] ?? ''), 'DRAFT', '완료에서 초안으로 되돌림');

try {
    $provider->updateAmount(0, 1.0);
    expect_true(false, '없는 초안 금액 수정은 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '초안'), '없는 초안 예외 메시지');
}

try {
    $provider->updateStatus((int) $cursor['id'], 'NOPE');
    expect_true(false, '잘못된 상태는 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '상태'), '잘못된 상태 예외 메시지');
}

try {
    $provider->updateStatus((int) $cursor['id'], 'COPIED');
    expect_true(false, 'COPIED는 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), 'DRAFT'), 'COPIED 예외 메시지');
}

Database::reset();
@unlink($path);
foreach (glob($cacheDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);

tbb_test_done();
