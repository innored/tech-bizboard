<?php
/**
 * 결제 항목 ↔ 첨부(receipt_id) 저장/로딩 왕복 + 유효성 테스트
 *
 * @date 2026-09-16
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_receiptid_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();

$ym = '2026-08';

// 템플릿
$pdo->exec(
    "INSERT INTO tb_expense_templates
        (title, assignee, account_info, cycle_type, currency, default_amount,
         payment_type, title_pattern, body_pattern, note, is_active)
     VALUES
        ('구독료', '테스터', '', 'MONTHLY', 'KRW', 1000, 'MANUAL',
         '{title}', '{title}', NULL, 1)"
);
$templateId = (int) $pdo->lastInsertId();

// 이 (템플릿·월)에 실재하는 첨부 1건
$pdo->prepare(
    'INSERT INTO tb_draft_receipts (expense_template_id, target_year_month, display_name, stored_name, mime, size_bytes, created_at)
     VALUES (:t, :ym, :dn, :sn, :m, :sz, :ca)'
)->execute([
    't' => $templateId, 'ym' => $ym, 'dn' => 'a.pdf', 'sn' => $ym . '/a.pdf',
    'm' => 'application/pdf', 'sz' => 10, 'ca' => Database::nowKst(),
]);
$receiptId = (int) $pdo->lastInsertId();

$drafts = tbb_drafts();
$drafts->setBypassMonthLock(true);

// 생성: receipt_id 있는 항목
$row = $drafts->createFromTemplate($templateId, $ym, [
    ['description' => 'A', 'currency' => 'KRW', 'amount' => 1000, 'receipt_id' => $receiptId],
]);
$draftId = (int) $row['id'];
expect_eq((int) ($row['items'][0]['receipt_id'] ?? 0), $receiptId, '생성 시 receipt_id 저장');

// 재저장(replaceItems): 실재 첨부 + 첨부없음 + 잘못된 id
$re = $drafts->replaceItems($draftId, [
    ['description' => 'A', 'currency' => 'KRW', 'amount' => 1000, 'receipt_id' => $receiptId],
    ['description' => 'B', 'currency' => 'KRW', 'amount' => 2000],                       // 첨부 없음
    ['description' => 'C', 'currency' => 'KRW', 'amount' => 3000, 'receipt_id' => 99999], // 미존재 → null
]);
expect_eq((int) ($re['items'][0]['receipt_id'] ?? 0), $receiptId, '재저장 receipt_id 유지');
expect_true(($re['items'][1]['receipt_id'] ?? null) === null, '첨부 없으면 null');
expect_true(($re['items'][2]['receipt_id'] ?? null) === null, '미존재 첨부 id는 null(유효성)');

// 재로딩(attachItems 경로): 저장된 값이 그대로 읽히는지 loadPricedItems 기반 replace 결과로 확인됨
tbb_test_done();
