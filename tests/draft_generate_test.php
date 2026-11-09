<?php
/**
 * 월간 기안 초안 생성 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/draft_generate_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_draft_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();

$pdo->exec(
    "INSERT INTO tb_expense_templates
        (title, assignee, account_info, cycle_type, currency, default_amount,
         payment_type, title_pattern, body_pattern, note, is_active)
     VALUES
        ('비활성 월간', '테스터', '', 'MONTHLY', 'KRW', 1000, 'MANUAL',
         '{title}', '{title}', NULL, 0)"
);

$cacheDir = sys_get_temp_dir() . '/tbb_fx_draft_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0775, true);

$rates = new ExchangeRateProvider(
    'https://lucy-dev.conbus.co.kr/api/exchange/',
    $cacheDir,
    static function (string $url): ?string {
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

$created = $provider->ensureMonthlyDrafts('2026-09');
expect_eq($created, 5, '활성 MONTHLY 5건만 생성');

$again = $provider->ensureMonthlyDrafts('2026-09');
expect_eq($again, 0, '같은 월 재실행 insert 0건');

$list = $provider->listByMonth('2026-09');
expect_eq(count($list), 5, '당월 목록 5건');

$byTitle = [];
foreach ($list as $row) {
    $byTitle[(string) $row['draft_title']] = $row;
}

$cursor = null;
$rocket = null;
$kinx = null;
foreach ($list as $row) {
    if (str_contains((string) $row['draft_title'], 'Cursor')) {
        $cursor = $row;
    }
    if (str_contains((string) $row['draft_title'], 'Rocket')) {
        $rocket = $row;
    }
    if (str_contains((string) $row['draft_title'], 'KINX')) {
        $kinx = $row;
    }
}

expect_true($cursor !== null, 'Cursor 초안 존재');
expect_eq((int) ($cursor['amount_krw'] ?? 0), 268520, 'USD 200 원화');
expect_eq((string) ($cursor['rate_date'] ?? ''), '2026-09-01', '결제일 없으면 월 1일 환율');

expect_true($rocket !== null, 'Rocket 초안 존재');
expect_eq((int) ($rocket['amount_krw'] ?? 0), 154543, 'EUR 99 원화');

expect_true($kinx !== null, 'KINX 초안 존재');
expect_eq((int) ($kinx['amount_krw'] ?? 0), 0, '금액 NULL이면 원화 0');
expect_true(str_contains((string) ($kinx['draft_title'] ?? ''), '2026년 9월'), '제목에 대상 년월');

$inactive = (int) $pdo->query(
    "SELECT COUNT(*) FROM tb_draft_expenses d
     JOIN tb_expense_templates t ON t.id = d.expense_template_id
     WHERE t.title = '비활성 월간'"
)->fetchColumn();
expect_eq($inactive, 0, '비활성 템플릿은 생성 안 함');

$yearly = (int) $pdo->query(
    "SELECT COUNT(*) FROM tb_draft_expenses d
     JOIN tb_expense_templates t ON t.id = d.expense_template_id
     WHERE t.cycle_type != 'MONTHLY' AND d.target_year_month = '2026-09'"
)->fetchColumn();
expect_eq($yearly, 0, '연간/비정기 템플릿은 월간 생성 제외');

Database::reset();
@unlink($path);
foreach (glob($cacheDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);

tbb_test_done();
