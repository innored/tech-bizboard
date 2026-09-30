<?php
/**
 * 기안 작성 화면. 완료 건은 초안으로 되돌릴 수 있다.
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/drafts.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sso = tbb_guard();
$SSO_USER = $sso->getUser();
$SHOW_LOGOUT = $sso->isLoginRequired();

$month = trim((string) ($_GET['month'] ?? ''));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) === 1) {
    $month = substr($month, 0, 7);
}
if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
    $month = tbb_previous_month();
}
$monthInput = $month;

$CSRF_TOKEN = $sso->csrfToken();
$PAGE_SCRIPTS = ['asset/js/drafts.js'];

$templates = tbb_templates()->listActive();
foreach ($templates as &$tpl) {
    $tpl['cycle_label'] = tbb_cycle_label((string) ($tpl['cycle_type'] ?? ''));
    $tpl['pay_label'] = tbb_pay_label((string) ($tpl['payment_type'] ?? ''));
}
unset($tpl);
$rows = tbb_drafts()->listByMonth($month);
$selectedId = (int) ($_GET['id'] ?? 0);
$draftsByTemplate = [];
foreach ($rows as $row) {
    $tid = (int) ($row['expense_template_id'] ?? 0);
    if ($tid > 0) {
        $draftsByTemplate[$tid] = $row;
    }
}

// 남은 일이 위로 오도록: 미작성 → 작성중 → 완료. 같은 상태면 원래 순서 유지(안정 정렬).
$statusRank = static function (array $tpl) use ($draftsByTemplate): int {
    $tid = (int) ($tpl['id'] ?? 0);
    $draft = $draftsByTemplate[$tid] ?? null;
    $status = is_array($draft) ? (string) ($draft['status'] ?? '') : '';
    if ($status === '') {
        return 0;
    }

    return $status === 'DONE' ? 2 : 1;
};
usort($templates, static fn (array $a, array $b): int => $statusRank($a) <=> $statusRank($b));

$monthLabel = $month;
if (preg_match('/^(\d{4})-(\d{2})$/', $month, $monthParts) === 1) {
    $monthLabel = $monthParts[1] . '년 ' . (int) $monthParts[2] . '월';
}

$doneCount = 0;
$writingCount = 0;
$amountKrw = 0;
foreach ($templates as $tpl) {
    $tid = (int) ($tpl['id'] ?? 0);
    $draft = $draftsByTemplate[$tid] ?? null;
    if (!is_array($draft) || (string) ($draft['status'] ?? '') === '') {
        continue;
    }
    if ((string) $draft['status'] === 'DONE') {
        $doneCount++;
        $amountKrw += (int) ($draft['amount_krw'] ?? 0);
    } else {
        $writingCount++;
    }
}
$templateCount = count($templates);
$monthSummary = [
    'total'      => $templateCount,
    'idle'       => max(0, $templateCount - $doneCount - $writingCount),
    'writing'    => $writingCount,
    'done'       => $doneCount,
    'amount_krw' => $amountKrw,
];

require dirname(__DIR__) . '/view/drafts.php';
