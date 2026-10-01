<?php
/**
 * 수입 증빙 파일 — 업로드/목록/다운로드/삭제.
 *
 * POST multipart/form-data: file, revenue_id, csrf, [user_name]  → {ok, file}
 * GET  ?action=list&revenue_id=..                                 → {ok, files:[...]}
 * GET  ?action=download&id=..                                     → 파일 스트리밍
 * POST action=delete, id, csrf                                    → {ok}
 *
 * 라우팅: api/revenue_file  (^api/([a-z_]+)/?$ → proc/$1.php)
 *
 * @date 2026-10-01
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$sso     = tbb_guard();
$recs    = tbb_revenue_receipts();
$me      = tbb_created_by();
$isAdmin = tbb_is_admin();

function rev_file_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_GET['action'] ?? '');

// POST(삭제)는 JSON 본문으로 온다 → 파일 없는 POST만 파싱.
$body = [];
if ($method === 'POST' && empty($_FILES)) {
    $decodedBody = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($decodedBody) ? $decodedBody : $_POST;
    if ($action === '') {
        $action = (string) ($body['action'] ?? '');
    }
}
if ($method === 'POST' && !empty($_FILES)) {
    $action = $action !== '' ? $action : 'upload';
}

// ── 업로드 ──────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'upload') {
    if (!$sso->verifyCsrf((string) ($_POST['csrf'] ?? ''))) {
        rev_file_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
    }

    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        rev_file_json(['ok' => false, 'error' => '파일 업로드에 실패했습니다.'], 400);
    }

    $bytes = (string) file_get_contents((string) $file['tmp_name']);
    $mime  = (string) (mime_content_type((string) $file['tmp_name']) ?: ($file['type'] ?? ''));

    $problem = ReceiptValidator::check($bytes, $mime);
    if ($problem !== null) {
        rev_file_json(['ok' => false, 'error' => $problem], 400);
    }

    $revenueId = (int) ($_POST['revenue_id'] ?? 0);
    if ($revenueId < 1) {
        rev_file_json(['ok' => false, 'error' => '수입 항목이 필요합니다.'], 400);
    }

    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    if ($displayName === '') {
        $displayName = (string) ($file['name'] ?? '증빙');
    }
    $uploadedName = trim((string) ($_POST['user_name'] ?? ''));

    try {
        $row = $recs->store($revenueId, $displayName, $bytes, $mime, $uploadedName);
        rev_file_json([
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
        error_log('revenue_file upload: ' . $e->getMessage());
        rev_file_json(['ok' => false, 'error' => '저장에 실패했습니다.'], 500);
    } finally {
        unset($bytes);
    }
}

// ── 목록 ────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $revenueId = (int) ($_GET['revenue_id'] ?? 0);
    if ($revenueId < 1) {
        rev_file_json(['ok' => true, 'files' => []]);
    }
    $files = [];
    foreach ($recs->listFor($revenueId) as $row) {
        $by = (string) ($row['uploaded_by'] ?? '');
        $files[] = [
            'id'            => (int) ($row['id'] ?? 0),
            'display_name'  => (string) ($row['display_name'] ?? ''),
            'size_bytes'    => (int) ($row['size_bytes'] ?? 0),
            'uploaded_by'   => $by,
            'uploaded_name' => (string) ($row['uploaded_name'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'can_delete'    => $isAdmin || ($by !== '' && $by === $me),
        ];
    }
    rev_file_json(['ok' => true, 'files' => $files]);
}

// ── 다운로드 ─────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'download') {
    $id  = (int) ($_GET['id'] ?? 0);
    $row = $id > 0 ? $recs->findById($id) : null;
    if ($row === null) {
        rev_file_json(['ok' => false, 'error' => '파일을 찾을 수 없습니다.'], 404);
    }
    $path = $recs->absPath($row);
    if (!is_file($path)) {
        rev_file_json(['ok' => false, 'error' => '파일이 존재하지 않습니다.'], 404);
    }
    $name     = (string) ($row['display_name'] ?? '증빙');
    $mime     = str_replace(["\r", "\n"], '', (string) ($row['mime'] ?? 'application/octet-stream'));
    $fallback = str_replace(['"', "\\"], '_', (string) preg_replace('/[^\x20-\x7E]/', '_', $name));

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header("Content-Disposition: attachment; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

// ── 삭제 ─────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    if (!$sso->verifyCsrf((string) ($body['csrf'] ?? ''))) {
        rev_file_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
    }
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        rev_file_json(['ok' => false, 'error' => 'id가 필요합니다.'], 400);
    }
    try {
        $recs->delete($id, $me, $isAdmin);
        rev_file_json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        rev_file_json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

rev_file_json(['ok' => false, 'error' => '알 수 없는 요청입니다.'], 400);
