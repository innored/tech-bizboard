<?php
/**
 * 대시보드 손익 집계 API
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/api/dashboard_api.php
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$sso = tbb_guard();
$dash = tbb_dashboard();

function tbb_dash_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    tbb_dash_json(['ok' => false, 'error' => '알 수 없는 요청입니다.'], 400);
}

$year = trim((string) ($_GET['year'] ?? ''));
if (preg_match('/^\d{4}$/', $year) !== 1) {
    $year = (string) DashboardProvider::defaultYear();
}

try {
    $data = $dash->summarize((int) $year);
    $data['ok'] = true;
    tbb_dash_json($data);
} catch (InvalidArgumentException $e) {
    tbb_dash_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
