<?php
/**
 * 영수증 업로드 엔드포인트 — 파일명을 바꿔 서버에 보관만 한다(분석은 별도).
 * AI 분석은 기안 담당자가 작성 시 receipt_file.php?action=analyze 로 나중에 수행.
 *
 * POST multipart/form-data: file, template_id, month, [display_name], csrf
 *
 * @date 2026-09-15
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$sso = tbb_guard();

function tbb_receipt_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    tbb_receipt_json(['ok' => false, 'error' => 'POST 만 허용합니다.'], 405);
}
if (!$sso->verifyCsrf((string) ($_POST['csrf'] ?? ''))) {
    tbb_receipt_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
    tbb_receipt_json(['ok' => false, 'error' => '파일 업로드에 실패했습니다.'], 400);
}

$bytes = (string) file_get_contents((string) $file['tmp_name']);
$mime = (string) (mime_content_type((string) $file['tmp_name']) ?: ($file['type'] ?? ''));

$problem = ReceiptValidator::check($bytes, $mime);
if ($problem !== null) {
    tbb_receipt_json(['ok' => false, 'error' => $problem], 400);
}

$templateId = (int) ($_POST['template_id'] ?? 0);
$month = trim((string) ($_POST['month'] ?? ''));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) === 1) {
    $month = substr($month, 0, 7);
}
if ($templateId < 1 || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
    tbb_receipt_json(['ok' => false, 'error' => '템플릿과 대상 월이 필요합니다.'], 400);
}
$displayName = trim((string) ($_POST['display_name'] ?? ''));
if ($displayName === '') {
    $displayName = (string) ($file['name'] ?? '영수증');
}
$uploadedName = trim((string) ($_POST['user_name'] ?? ''));

try {
    $row = tbb_receipts()->store($templateId, $month, $displayName, $bytes, $mime, $uploadedName);
    tbb_receipt_json([
        'ok'   => true,
        'file' => [
            'id'           => (int) ($row['id'] ?? 0),
            'display_name' => (string) ($row['display_name'] ?? $displayName),
            'size_bytes'   => (int) ($row['size_bytes'] ?? 0),
            'uploaded_by'  => (string) ($row['uploaded_by'] ?? ''),
            'created_at'   => (string) ($row['created_at'] ?? ''),
            'can_delete'   => true,
        ],
    ]);
} catch (Throwable $e) {
    tbb_receipt_json(['ok' => false, 'error' => '저장 실패: ' . $e->getMessage()], 500);
} finally {
    unset($bytes);
}
