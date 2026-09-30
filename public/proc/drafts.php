<?php
/**
 * 기안 목록·작성·상태 API
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/api/drafts_api.php
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$sso = tbb_guard();
$drafts = tbb_drafts();

function tbb_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tbb_normalize_month(string $month): string
{
    $month = trim($month);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) === 1) {
        $month = substr($month, 0, 7);
    }
    if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return tbb_previous_month();
    }

    return $month;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $month = tbb_normalize_month((string) ($_GET['month'] ?? ''));
    tbb_json([
        'ok'    => true,
        'month' => $month,
        'rows'  => $drafts->listByMonth($month),
    ]);
}

$raw = file_get_contents('php://input');
$body = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($body)) {
    $body = $_POST;
}
if (!$sso->verifyCsrf((string) ($body['csrf'] ?? ''))) {
    tbb_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = trim((string) ($body['action'] ?? ''));
if ($action === '' && $method === 'PATCH') {
    $action = isset($body['amount_foreign']) ? 'update' : 'status';
}

try {
    if ($action === 'create') {
        $month = tbb_normalize_month((string) ($body['month'] ?? ''));
        $templateId = (int) ($body['template_id'] ?? 0);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $contentsText = (string) ($body['contents_text'] ?? '');
        $draftBody = (string) ($body['draft_body'] ?? '');
        $draftTitle = (string) ($body['draft_title'] ?? '');
        tbb_json([
            'ok'  => true,
            'row' => $drafts->createFromTemplate($templateId, $month, $items, $contentsText, $draftBody, $draftTitle),
        ]);
    }
    if ($action === 'preview') {
        $month = tbb_normalize_month((string) ($body['month'] ?? ''));
        $templateId = (int) ($body['template_id'] ?? 0);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $contentsText = (string) ($body['contents_text'] ?? '');
        tbb_json([
            'ok'      => true,
            'preview' => $drafts->previewFromTemplate($templateId, $month, $items, $contentsText),
        ]);
    }
    if ($action === 'previous') {
        $month = tbb_normalize_month((string) ($body['month'] ?? ''));
        $templateId = (int) ($body['template_id'] ?? 0);
        tbb_json([
            'ok'       => true,
            'previous' => $drafts->loadPrevious($templateId, $month),
        ]);
    }
    if ($action === 'append-item') {
        $month = tbb_normalize_month((string) ($body['month'] ?? ''));
        $templateId = (int) ($body['template_id'] ?? 0);
        $item = is_array($body['item'] ?? null) ? $body['item'] : [];
        if ($templateId < 1) {
            tbb_json(['ok' => false, 'error' => '템플릿 id가 필요합니다.'], 400);
        }
        tbb_json([
            'ok'  => true,
            'row' => $drafts->appendItem($templateId, $month, $item),
        ]);
    }
    if ($action === 'rates') {
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if ($items === []) {
            tbb_json(['ok' => false, 'error' => '외화 항목을 입력하세요.'], 400);
        }
        tbb_json([
            'ok'     => true,
            'quotes' => $drafts->quoteRates($items, true),
        ]);
    }
    if ($action === 'rate') {
        $date = trim((string) ($body['date'] ?? ''));
        $currency = strtoupper(trim((string) ($body['currency'] ?? '')));
        $amount = (float) ($body['amount'] ?? 0);
        $quote = $drafts->quoteRate($date, $currency, $amount, true);
        if (($quote['success'] ?? false) !== true) {
            tbb_json([
                'ok'    => false,
                'error' => (string) ($quote['message'] ?? '환율을 가져오지 못했습니다.'),
            ], 400);
        }
        tbb_json([
            'ok'    => true,
            'quote' => $quote,
        ]);
    }

    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        tbb_json(['ok' => false, 'error' => '초안 id가 필요합니다.'], 400);
    }

    if ($action === 'update') {
        if (!isset($body['amount_foreign']) || $body['amount_foreign'] === '' || $body['amount_foreign'] === null) {
            tbb_json(['ok' => false, 'error' => '금액을 입력하세요.'], 400);
        }
        $amount = (float) $body['amount_foreign'];
        if ($amount < 0) {
            tbb_json(['ok' => false, 'error' => '금액은 0 이상이어야 합니다.'], 400);
        }
        tbb_json(['ok' => true, 'row' => $drafts->updateAmount($id, $amount)]);
    }
    if ($action === 'items') {
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $contentsText = array_key_exists('contents_text', $body) ? (string) $body['contents_text'] : null;
        $draftBody = array_key_exists('draft_body', $body) ? (string) $body['draft_body'] : null;
        $draftTitle = array_key_exists('draft_title', $body) ? (string) $body['draft_title'] : null;
        tbb_json(['ok' => true, 'row' => $drafts->replaceItems($id, $items, $contentsText, $draftBody, $draftTitle)]);
    }
    if ($action === 'status') {
        $status = strtoupper(trim((string) ($body['status'] ?? '')));
        tbb_json(['ok' => true, 'row' => $drafts->updateStatus($id, $status)]);
    }
} catch (InvalidArgumentException $e) {
    tbb_json(['ok' => false, 'error' => $e->getMessage()], 400);
}

tbb_json(['ok' => false, 'error' => '알 수 없는 요청입니다.'], 400);
