<?php
/**
 * 수입 반복 템플릿 API
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/api/revenue_templates_api.php
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$sso = tbb_guard();
$templates = tbb_revenue_templates();

function tbb_rev_tpl_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    tbb_rev_tpl_json(['ok' => true, 'rows' => $templates->listAll()]);
}

$raw = file_get_contents('php://input');
$body = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($body)) {
    $body = $_POST;
}
if (!$sso->verifyCsrf((string) ($body['csrf'] ?? ''))) {
    tbb_rev_tpl_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
}

$action = trim((string) ($body['action'] ?? ''));

try {
    if ($action === 'create') {
        if (!array_key_exists('is_active', $body)) {
            $body['is_active'] = 1;
        }
        tbb_rev_tpl_json(['ok' => true, 'row' => $templates->create($body)]);
    }
    if ($action === 'update') {
        $id = (int) ($body['id'] ?? 0);
        tbb_rev_tpl_json(['ok' => true, 'row' => $templates->update($id, $body)]);
    }
    if ($action === 'delete') {
        $templates->delete((int) ($body['id'] ?? 0));
        tbb_rev_tpl_json(['ok' => true]);
    }
} catch (InvalidArgumentException $e) {
    tbb_rev_tpl_json(['ok' => false, 'error' => $e->getMessage()], 400);
}

tbb_rev_tpl_json(['ok' => false, 'error' => '알 수 없는 요청입니다.'], 400);
