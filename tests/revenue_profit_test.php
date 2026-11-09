<?php
/**
 * 솔루션구분·집행금액·테크피 저장 규칙 테스트
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/revenue_profit_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

function tbb_columns(PDO $pdo, string $table): array
{
    return array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
}

// 1) 새 DB에 컬럼 세 개
$path = sys_get_temp_dir() . '/tbb_profit_' . bin2hex(random_bytes(4)) . '.db';
Database::reset(); Database::setPath($path); $pdo = Database::connection();
foreach (['tb_team_revenues', 'tb_revenue_templates'] as $table) {
    $cols = tbb_columns($pdo, $table);
    foreach (['solution_group', 'campaign_spend_krw', 'tech_fee_rate'] as $col) {
        expect_true(in_array($col, $cols, true), "{$table}.{$col} 컬럼");
    }
}

// 2) 옛 스키마 → 컬럼 추가 시 1회 백필
$oldPath = sys_get_temp_dir() . '/tbb_profit_old_' . bin2hex(random_bytes(4)) . '.db';
$old = new PDO('sqlite:' . $oldPath);
$old->exec(
    "CREATE TABLE tb_revenue_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_name TEXT NOT NULL, client_name TEXT,
        service_category TEXT NOT NULL DEFAULT '', solution_name TEXT NOT NULL DEFAULT '', solution_id TEXT NOT NULL DEFAULT '',
        billing_type TEXT NOT NULL DEFAULT 'PAID', supply_krw INTEGER NOT NULL DEFAULT 0, vat_krw INTEGER NOT NULL DEFAULT 0,
        amount_krw INTEGER NOT NULL DEFAULT 0, list_value_krw INTEGER NOT NULL DEFAULT 0, assignee TEXT NOT NULL,
        start_year_month TEXT NOT NULL, end_year_month TEXT, note TEXT, is_active INTEGER NOT NULL DEFAULT 1,
        created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL)"
);
$old->exec(
    "CREATE TABLE tb_team_revenues (
        id INTEGER PRIMARY KEY AUTOINCREMENT, revenue_template_id INTEGER, target_year_month TEXT NOT NULL,
        project_name TEXT NOT NULL, client_name TEXT, service_category TEXT NOT NULL DEFAULT '',
        solution_name TEXT NOT NULL DEFAULT '', solution_id TEXT NOT NULL DEFAULT '',
        supply_krw INTEGER NOT NULL DEFAULT 0, vat_krw INTEGER NOT NULL DEFAULT 0, amount_krw INTEGER NOT NULL,
        billing_type TEXT NOT NULL DEFAULT 'PAID', list_value_krw INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'COMPLETED', received_date TEXT NOT NULL, assignee TEXT NOT NULL, note TEXT,
        created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL)"
);
$ins = $old->prepare(
    "INSERT INTO tb_team_revenues (target_year_month, project_name, service_category, solution_id, amount_krw, received_date, assignee, created_at)
     VALUES ('2026-07', :p, '솔루션', :sid, 0, '2026-07-01', '루시', '2026-07-01 00:00:00')"
);
foreach (['lift_optima', 'sr_royalcanin', 'reb'] as $sid) {
    $ins->execute([':p' => $sid, ':sid' => $sid]);
}
$old->exec(
    "INSERT INTO tb_revenue_templates (project_name, service_category, solution_id, assignee, start_year_month, created_at)
     VALUES ('lo', '솔루션', 'lift_optima', '루시', '2026-07', '2026-07-01 00:00:00')"
);
Database::reset(); Database::setPath($oldPath); $old = Database::connection();
$groups = $old->query('SELECT solution_id, solution_group FROM tb_team_revenues')->fetchAll(PDO::FETCH_KEY_PAIR);
expect_eq($groups['lift_optima'] ?? null, 'ADTECH', '백필: Lift Optima → ADTECH');
expect_eq($groups['sr_royalcanin'] ?? null, 'GENERAL', '백필: Royalcanin → GENERAL');
expect_eq($groups['reb'] ?? null, '', '백필: 그 외 솔루션은 미분류');
expect_eq((string) $old->query('SELECT solution_group FROM tb_revenue_templates')->fetchColumn(), 'ADTECH', '백필: 템플릿도 채움');

$old->exec("UPDATE tb_team_revenues SET solution_group = '' WHERE solution_id = 'lift_optima'");
Database::reset(); Database::setPath($oldPath); $old = Database::connection();
expect_eq(
    (string) $old->query("SELECT solution_group FROM tb_team_revenues WHERE solution_id = 'lift_optima'")->fetchColumn(),
    '',
    '백필은 컬럼이 처음 생길 때 한 번만'
);

// 3) 저장 규칙
Database::reset(); Database::setPath($path); $pdo = Database::connection();
$templates = new RevenueTemplateProvider($pdo);
$rev = new TeamRevenueProvider($pdo, $templates);
$rev->setCreatedBy('lucy@innored.co.kr');
$rev->setBypassMonthLock(true);

$base = [
    'project_name' => '캠페인', 'assignee' => '루시', 'received_date' => '2026-11-05',
    'service_category' => '솔루션', 'billing_type' => 'PAID',
];
function tbb_expect_error(callable $fn, string $message, string $label): void
{
    try {
        $fn();
        expect_true(false, $label . ' (예외 없음)');
    } catch (InvalidArgumentException $e) {
        expect_eq($e->getMessage(), $message, $label);
    }
}

$ad = $rev->createOneOff($base + ['solution_group' => 'ADTECH', 'campaign_spend_krw' => 10000000, 'tech_fee_rate' => 10]);
expect_eq((string) $ad['solution_group'], 'ADTECH', '애드테크 저장');
expect_eq((int) $ad['campaign_spend_krw'], 10000000, '집행금액 저장');
expect_eq((float) $ad['tech_fee_rate'], 10.0, '테크피 저장');
expect_eq((int) $ad['supply_krw'], 1000000, '공급가 자동 = 집행 × 테크피');
expect_eq((int) $ad['vat_krw'], 100000, '부가세 자동');
expect_eq((int) $ad['amount_krw'], 1100000, '총액 자동');

$manual = $rev->createOneOff($base + ['solution_group' => 'ADTECH', 'campaign_spend_krw' => 10000000, 'tech_fee_rate' => 10, 'supply_krw' => 900000]);
expect_eq((int) $manual['supply_krw'], 900000, '공급가 수정값 유지');
$manual = $rev->update((int) $manual['id'], ['note' => 'x']);
expect_eq((int) $manual['supply_krw'], 900000, '다른 필드만 수정해도 공급가 유지');
expect_eq((float) $manual['tech_fee_rate'], 10.0, '다른 필드만 수정해도 테크피 유지');

$big = $rev->createOneOff($base + ['solution_group' => 'ADTECH', 'campaign_spend_krw' => 3000000000, 'tech_fee_rate' => 5]);
expect_eq((int) $big['campaign_spend_krw'], 3000000000, '집행금액 21억 초과 보존');
expect_eq((int) $big['supply_krw'], 150000000, '큰 집행금액 공급가');

$frac = $rev->createOneOff($base + ['solution_group' => 'ADTECH', 'campaign_spend_krw' => 1234567, 'tech_fee_rate' => '7.5']);
expect_eq((int) $frac['supply_krw'], 92593, '소수 테크피 반올림');

tbb_expect_error(fn () => $rev->createOneOff($base + ['solution_group' => 'ADTECH', 'campaign_spend_krw' => 0, 'tech_fee_rate' => 10]),
    '집행금액은 1원 이상이어야 합니다.', '집행금액 0 거부');
tbb_expect_error(fn () => $rev->createOneOff($base + ['solution_group' => 'ADTECH', 'campaign_spend_krw' => 100, 'tech_fee_rate' => 0]),
    '테크피는 0 초과 100 이하여야 합니다.', '테크피 0 거부');
tbb_expect_error(fn () => $rev->createOneOff($base + ['solution_group' => 'ADTECH', 'campaign_spend_krw' => 100, 'tech_fee_rate' => 100.01]),
    '테크피는 0 초과 100 이하여야 합니다.', '테크피 100 초과 거부');
tbb_expect_error(fn () => $rev->createOneOff($base),
    '솔루션구분을 선택해 주세요.', '솔루션인데 솔루션구분 없음 거부');
tbb_expect_error(fn () => $rev->createOneOff($base + ['solution_group' => 'OTHER']),
    '솔루션구분을 선택해 주세요.', '잘못된 솔루션구분 거부');

$gen = $rev->createOneOff($base + ['solution_group' => 'GENERAL', 'campaign_spend_krw' => 5000, 'tech_fee_rate' => 10, 'supply_krw' => 3000000]);
expect_eq((int) $gen['campaign_spend_krw'], 0, '일반: 집행금액 0');
expect_eq($gen['tech_fee_rate'], null, '일반: 테크피 NULL');
expect_eq((int) $gen['supply_krw'], 3000000, '일반: 공급가 그대로');

$cons = $rev->createOneOff(['service_category' => '컨설팅', 'solution_group' => 'ADTECH', 'campaign_spend_krw' => 5000, 'tech_fee_rate' => 10, 'supply_krw' => 1000] + $base);
expect_eq((string) $cons['solution_group'], '', '비솔루션: 솔루션구분 비움');
expect_eq((int) $cons['campaign_spend_krw'], 0, '비솔루션: 집행금액 0');
expect_eq($cons['tech_fee_rate'], null, '비솔루션: 테크피 NULL');

$free = $rev->createOneOff(['billing_type' => 'FREE', 'solution_group' => 'ADTECH', 'supply_krw' => 1000] + $base);
expect_eq((string) $free['solution_group'], 'ADTECH', '무상: 솔루션구분 유지');
expect_eq((int) $free['campaign_spend_krw'], 0, '무상: 집행금액 0');
expect_eq($free['tech_fee_rate'], null, '무상: 테크피 NULL');

$toGen = $rev->update((int) $ad['id'], ['solution_group' => 'GENERAL']);
expect_eq((int) $toGen['campaign_spend_krw'], 0, '애드테크→일반: 집행금액 0');
expect_eq($toGen['tech_fee_rate'], null, '애드테크→일반: 테크피 NULL');
expect_eq((int) $toGen['supply_krw'], 1000000, '애드테크→일반: 공급가 유지');

// 4) 반복 설정 → 새 달 복사
$tpl = $templates->create([
    'project_name' => 'LO 월정', 'assignee' => '루시', 'start_year_month' => '2026-12', 'is_active' => 1,
    'service_category' => '솔루션', 'billing_type' => 'PAID',
    'solution_group' => 'ADTECH', 'campaign_spend_krw' => 5000000, 'tech_fee_rate' => 10,
]);
expect_eq((int) $tpl['supply_krw'], 500000, '템플릿: 공급가 자동');
tbb_expect_error(fn () => $templates->create([
    'project_name' => 'x', 'assignee' => '루시', 'start_year_month' => '2026-12',
    'service_category' => '솔루션', 'billing_type' => 'PAID',
]), '솔루션구분을 선택해 주세요.', '템플릿: 솔루션구분 필수');
$rev->ensureMonth('2026-12');
$made = array_values(array_filter($rev->listByMonth('2026-12'), fn ($r) => (int) ($r['revenue_template_id'] ?? 0) === (int) $tpl['id']));
expect_eq(count($made), 1, '템플릿 줄 생성');
expect_eq((string) $made[0]['solution_group'], 'ADTECH', '템플릿 줄: 솔루션구분 복사');
expect_eq((int) $made[0]['campaign_spend_krw'], 5000000, '템플릿 줄: 집행금액 복사');
expect_eq((float) $made[0]['tech_fee_rate'], 10.0, '템플릿 줄: 테크피 복사');
expect_eq((int) $made[0]['supply_krw'], 500000, '템플릿 줄: 공급가');

tbb_test_done();
