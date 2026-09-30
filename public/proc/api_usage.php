<?php
/**
 * API 사용량 조회 — 공급자별 연동 확인/집계 엔드포인트
 *
 * 외부 API 한도를 아끼기 위해 최초 조회 결과를 storage/cache 에 스냅샷으로
 * 저장하고, 이후에는 스냅샷을 돌려준다. ?refresh=1 일 때만 재조회한다.
 *
 * GET ?action=check&provider=openai|anthropic
 *
 * @date 2026-09-15
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$sso = tbb_guard();
tbb_require_admin($sso);

function tbb_api_usage_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tbb_api_usage_snapshot_path(string $name): string
{
    return tbb_root() . '/storage/cache/api_usage_' . $name . '.json';
}

function tbb_api_usage_read_snapshot(string $name): ?array
{
    $path = tbb_api_usage_snapshot_path($name);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function tbb_api_usage_write_snapshot(string $name, array $data): void
{
    $path = tbb_api_usage_snapshot_path($name);
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/** 이번 달 1일 00:00(UTC) 타임스탬프. */
function tbb_api_usage_month_start(): int
{
    return (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
}

/**
 * 일자별 목록을 날짜 오름차순 정렬 후 누적/합계를 계산한다.
 *
 * @param list<array{date: string, amount: float}> $days
 * @return array{series: list<array{date: string, amount: float, cumulative: float}>, total: float}
 */
function tbb_api_usage_series(array $days): array
{
    usort($days, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
    $running = 0.0;
    $total = 0.0;
    foreach ($days as $i => $day) {
        $total += $day['amount'];
        $running += $day['amount'];
        $days[$i]['amount'] = round($day['amount'], 6);
        $days[$i]['cumulative'] = round($running, 4);
    }

    return ['series' => $days, 'total' => round($total, 4)];
}

/**
 * @param list<array{date: string, amount: float}> $days
 */
function tbb_api_usage_payload(array $days, string $currency, int $startTs, mixed $raw): array
{
    $s = tbb_api_usage_series($days);

    return [
        'ok'           => true,
        'configured'   => true,
        'status'       => 200,
        'month'        => (new DateTimeImmutable('@' . $startTs))->format('Y-m'),
        'currency'     => strtoupper($currency),
        'amount'       => $s['total'],
        'bucket_count' => count($days),
        'series'       => $s['series'],
        'fetched_at'   => (new DateTimeImmutable('now'))->format('Y-m-d H:i'),
        'raw'          => $raw,
    ];
}

/**
 * @param list<string> $headers
 * @return array{status: int, body: string, error: string}
 */
function tbb_api_usage_http_get(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body'   => is_string($body) ? $body : '',
        'error'  => $error,
    ];
}

/** OpenAI 이번 달 일자별 비용(costs API). 금액 단위: USD(달러). */
function tbb_api_usage_fetch_openai(string $key): array
{
    $startTs = tbb_api_usage_month_start();
    $url = 'https://api.openai.com/v1/organization/costs?start_time=' . $startTs . '&limit=32';
    $res = tbb_api_usage_http_get($url, [
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ]);

    if ($res['body'] === '' || $res['error'] !== '') {
        return ['ok' => false, 'status' => $res['status'], 'error' => '네트워크 오류: ' . ($res['error'] !== '' ? $res['error'] : '응답 없음')];
    }

    $json = json_decode($res['body'], true);
    if ($res['status'] !== 200) {
        $msg = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
        return ['ok' => false, 'status' => $res['status'], 'error' => $msg !== '' ? $msg : mb_substr($res['body'], 0, 300), 'raw' => is_array($json) ? $json : $res['body']];
    }

    $currency = 'usd';
    $days = [];
    foreach ((is_array($json['data'] ?? null) ? $json['data'] : []) as $bucket) {
        $ts = (int) ($bucket['start_time'] ?? 0);
        $date = $ts > 0 ? (new DateTimeImmutable('@' . $ts))->format('Y-m-d') : '';
        $daily = 0.0;
        foreach (($bucket['results'] ?? []) as $result) {
            $amount = $result['amount'] ?? null;
            if (is_array($amount)) {
                $daily += (float) ($amount['value'] ?? 0);   // USD 달러
                $currency = (string) ($amount['currency'] ?? $currency);
            }
        }
        if ($date !== '') {
            $days[] = ['date' => $date, 'amount' => $daily];
        }
    }

    return tbb_api_usage_payload($days, $currency, $startTs, $json);
}

/** Anthropic 이번 달 일자별 비용(cost_report). 금액 단위: 센트 문자열 → USD 환산. */
function tbb_api_usage_fetch_anthropic(string $key): array
{
    $startTs = tbb_api_usage_month_start();
    $startIso = (new DateTimeImmutable('@' . $startTs))->format('Y-m-d\T00:00:00\Z');
    $url = 'https://api.anthropic.com/v1/organizations/cost_report?starting_at=' . rawurlencode($startIso) . '&bucket_width=1d&limit=31';
    $res = tbb_api_usage_http_get($url, [
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
        'Accept: application/json',
    ]);

    if ($res['body'] === '' || $res['error'] !== '') {
        return ['ok' => false, 'status' => $res['status'], 'error' => '네트워크 오류: ' . ($res['error'] !== '' ? $res['error'] : '응답 없음')];
    }

    $json = json_decode($res['body'], true);
    if ($res['status'] !== 200) {
        $msg = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
        return ['ok' => false, 'status' => $res['status'], 'error' => $msg !== '' ? $msg : mb_substr($res['body'], 0, 300), 'raw' => is_array($json) ? $json : $res['body']];
    }

    $currency = 'usd';
    $days = [];
    foreach ((is_array($json['data'] ?? null) ? $json['data'] : []) as $bucket) {
        $date = mb_substr((string) ($bucket['starting_at'] ?? ''), 0, 10);
        $daily = 0.0;
        foreach (($bucket['results'] ?? []) as $result) {
            // amount 는 "최소통화단위(센트)" 십진 문자열 → 달러로 환산
            $daily += (float) ($result['amount'] ?? 0) / 100.0;
            $currency = (string) ($result['currency'] ?? $currency);
        }
        if ($date !== '') {
            $days[] = ['date' => $date, 'amount' => $daily];
        }
    }

    return tbb_api_usage_payload($days, $currency, $startTs, $json);
}

$providers = [
    'openai'    => ['env' => 'OPENAI_ADMIN_KEY',    'fetch' => 'tbb_api_usage_fetch_openai'],
    'anthropic' => ['env' => 'ANTHROPIC_ADMIN_KEY', 'fetch' => 'tbb_api_usage_fetch_anthropic'],
];

$action = (string) ($_GET['action'] ?? '');

// 하위호환: 예전 action=check_openai
if ($action === 'check_openai') {
    $action = 'check';
    $_GET['provider'] = 'openai';
}

if ($action === 'check') {
    $provider = (string) ($_GET['provider'] ?? '');
    if (!isset($providers[$provider])) {
        tbb_api_usage_json(['ok' => false, 'error' => '알 수 없는 공급자입니다.'], 400);
    }
    $cfg = $providers[$provider];
    $refresh = isset($_GET['refresh']) && !in_array((string) $_GET['refresh'], ['', '0'], true);

    // 기본 조회: 스냅샷이 있으면 그대로 (외부 API 호출 없음)
    if (!$refresh) {
        $snap = tbb_api_usage_read_snapshot($provider);
        if ($snap !== null) {
            $snap['cached'] = true;
            tbb_api_usage_json($snap);
        }
    }

    // 최초 1회 또는 명시적 새로고침 → 실제 조회
    $key = (string) getenv($cfg['env']);
    if ($key === '') {
        tbb_api_usage_json([
            'ok'         => false,
            'configured' => false,
            'error'      => '.env 에 ' . $cfg['env'] . ' 가 설정되어 있지 않습니다.',
        ]);
    }

    $result = $cfg['fetch']($key);

    if (!($result['ok'] ?? false)) {
        // 재조회 실패 시 저장본 유지
        $snap = tbb_api_usage_read_snapshot($provider);
        if ($snap !== null) {
            $snap['cached'] = true;
            $snap['stale'] = true;
            $snap['refresh_error'] = (string) ($result['error'] ?? '재조회 실패');
            $snap['refresh_status'] = $result['status'] ?? null;
            tbb_api_usage_json($snap);
        }

        tbb_api_usage_json([
            'ok'         => false,
            'configured' => true,
            'status'     => $result['status'] ?? null,
            'error'      => (string) ($result['error'] ?? '조회 실패'),
            'raw'        => $result['raw'] ?? null,
        ]);
    }

    tbb_api_usage_write_snapshot($provider, $result);
    $result['cached'] = false;
    tbb_api_usage_json($result);
}

tbb_api_usage_json(['ok' => false, 'error' => '알 수 없는 요청입니다.'], 400);
