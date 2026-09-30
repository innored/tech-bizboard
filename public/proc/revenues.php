<?php
/**
 * 수입 목록 raw API
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/api/revenues_api.php
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$sso = tbb_guard();
$revenues = tbb_revenues();

function tbb_rev_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $year = trim((string) ($_GET['year'] ?? ''));
    $month = trim((string) ($_GET['month'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
        if ($revenues->isWritableMonth($month)) {
            $revenues->ensureMonth($month);
        }
        tbb_rev_json(['ok' => true, 'rows' => $revenues->listByMonth($month)]);
    }
    if (preg_match('/^\d{2}$/', $month) === 1 && preg_match('/^\d{4}$/', $year) === 1) {
        $ym = $year . '-' . $month;
        if ($revenues->isWritableMonth($ym)) {
            $revenues->ensureMonth($ym);
        }
        tbb_rev_json(['ok' => true, 'rows' => $revenues->listByMonth($ym)]);
    }
    if (preg_match('/^\d{4}$/', $year) !== 1) {
        $year = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y');
    }
    tbb_rev_json(['ok' => true, 'rows' => $revenues->listByYear($year)]);
}

$raw = file_get_contents('php://input');
$body = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($body)) {
    $body = $_POST;
}
if (!$sso->verifyCsrf((string) ($body['csrf'] ?? ''))) {
    tbb_rev_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
}

$action = trim((string) ($body['action'] ?? ''));

try {
    if ($action === 'create') {
        tbb_rev_json(['ok' => true, 'row' => $revenues->createOneOff($body)]);
    }
    if ($action === 'update') {
        $id = (int) ($body['id'] ?? 0);
        tbb_rev_json(['ok' => true, 'row' => $revenues->update($id, $body)]);
    }
    if ($action === 'delete') {
        $revenues->delete((int) ($body['id'] ?? 0));
        tbb_rev_json(['ok' => true]);
    }
} catch (InvalidArgumentException $e) {
    tbb_rev_json(['ok' => false, 'error' => $e->getMessage()], 400);
}

tbb_rev_json(['ok' => false, 'error' => '알 수 없는 요청입니다.'], 400);
