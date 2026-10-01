<?php
/**
 * SQLite 스키마·시드 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/database_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_test_' . bin2hex(random_bytes(4)) . '.db';
if (is_file($path)) {
    unlink($path);
}

Database::setPath($path);
$pdo = Database::connection();

$tables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'tb_%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);
expect_eq($tables, [
    'tb_draft_expenses',
    'tb_draft_payment_items',
    'tb_draft_receipts',
    'tb_expense_templates',
    'tb_revenue_receipts',
    'tb_revenue_templates',
    'tb_team_revenues',
    'tb_users',
], '테이블 8개');

$views = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='view' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);
expect_eq($views, ['vi_monthly_pnl'], '뷰 1개');

$fk = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
expect_eq($fk, 1, 'foreign_keys ON');

$count = (int) $pdo->query('SELECT COUNT(*) FROM tb_expense_templates')->fetchColumn();
expect_eq($count, 7, '시드 7행');

$monthly = (int) $pdo->query(
    "SELECT COUNT(*) FROM tb_expense_templates WHERE cycle_type='MONTHLY'"
)->fetchColumn();
expect_eq($monthly, 5, '월간 템플릿 5개');

$pdo2 = Database::connection();
expect_true($pdo === $pdo2, 'PDO 싱글톤');

$again = (int) $pdo2->query('SELECT COUNT(*) FROM tb_expense_templates')->fetchColumn();
expect_eq($again, 7, '재연결 시 시드 중복 없음');

$emptyPattern = (int) $pdo->query(
    "SELECT COUNT(*) FROM tb_expense_templates WHERE title_pattern='' OR body_pattern=''"
)->fetchColumn();
expect_eq($emptyPattern, 0, '제목·본문 패턴 비어 있지 않음');

$cols = $pdo->query('PRAGMA table_info(tb_expense_templates)')->fetchAll();
$colNames = array_map(static fn ($r) => (string) ($r['name'] ?? ''), $cols);
expect_true(in_array('payment_site', $colNames, true), '결제 사이트 컬럼');
expect_true(in_array('vendor', $colNames, true), '업체 컬럼');
expect_true(in_array('content_mode', $colNames, true), '내용 모드 컬럼');
expect_true(in_array('period_pattern', $colNames, true), '기간 패턴 컬럼');
expect_true(in_array('created_by', $colNames, true), '지출 템플릿 작성자');

$draftCols = array_map(
    static fn ($r) => (string) ($r['name'] ?? ''),
    $pdo->query('PRAGMA table_info(tb_draft_expenses)')->fetchAll()
);
expect_true(in_array('contents_text', $draftCols, true), '직접 내용 컬럼');
expect_true(in_array('created_by', $draftCols, true), '기안 작성자');

$itemCols = array_map(
    static fn ($r) => (string) ($r['name'] ?? ''),
    $pdo->query('PRAGMA table_info(tb_draft_payment_items)')->fetchAll()
);
expect_true(in_array('item_kind', $itemCols, true), '결제 구분 컬럼');
expect_true(in_array('vendor', $itemCols, true), '결제 거래처 컬럼');
expect_true(in_array('note', $itemCols, true), '결제 비고 컬럼');

$revCols = array_map(
    static fn ($r) => (string) ($r['name'] ?? ''),
    $pdo->query('PRAGMA table_info(tb_team_revenues)')->fetchAll()
);
expect_true(in_array('revenue_template_id', $revCols, true), '수입 템플릿 FK');
expect_true(in_array('target_year_month', $revCols, true), '대상 년월');
expect_true(in_array('supply_krw', $revCols, true), '공급가');
expect_true(in_array('vat_krw', $revCols, true), '부가세');
expect_true(in_array('created_by', $revCols, true), '수입 작성자');

$revTplCols = array_map(
    static fn ($r) => (string) ($r['name'] ?? ''),
    $pdo->query('PRAGMA table_info(tb_revenue_templates)')->fetchAll()
);
expect_true(in_array('start_year_month', $revTplCols, true), '시작월');
expect_true(in_array('end_year_month', $revTplCols, true), '종료월');
expect_true(in_array('created_by', $revTplCols, true), '수입 템플릿 작성자');

Database::reset();
@unlink($path);

tbb_test_done();
