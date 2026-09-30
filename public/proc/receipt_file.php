<?php
/**
 * 서버 보관 영수증 파일 — 목록/다운로드/삭제.
 *
 * GET  ?action=list&template_id=..&month=YYYY-MM   → {ok, files:[...]}
 * GET  ?action=download&id=..                       → 첨부 파일 스트리밍
 * POST  action=delete, id, csrf                     → {ok}
 *
 * @date 2026-09-15
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$sso = tbb_guard();
$receipts = tbb_receipts();
$me = tbb_created_by();
$isAdmin = tbb_is_admin();

function tbb_receipt_file_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_GET['action'] ?? '');
// POST(삭제/분석/이름변경)는 JSON 본문으로 온다 → 본문을 한 번만 파싱해 공유.
$body = [];
if ($method === 'POST') {
    $decodedBody = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($decodedBody) ? $decodedBody : $_POST;
    if ($action === '') {
        $action = (string) ($body['action'] ?? '');
    }
}

if ($method === 'GET' && $action === 'list') {
    $templateId = (int) ($_GET['template_id'] ?? 0);
    $month = trim((string) ($_GET['month'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) === 1) {
        $month = substr($month, 0, 7);
    }
    if ($templateId < 1 || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        tbb_receipt_file_json(['ok' => true, 'files' => []]);
    }
    $files = [];
    foreach ($receipts->listFor($templateId, $month) as $row) {
        $by = (string) ($row['uploaded_by'] ?? '');
        // 이 파일이 만든 결제 항목 요약(항목별로 묶어 보여주기 위함)
        $summaryItems = [];
        $analysis = json_decode((string) ($row['analysis_json'] ?? ''), true);
        if (is_array($analysis)) {
            foreach (($analysis['items'] ?? []) as $it) {
                $summaryItems[] = [
                    'description' => (string) ($it['description'] ?? ''),
                    'currency'    => (string) ($it['currency'] ?? ''),
                    'amount'      => isset($it['amount']) && $it['amount'] !== null ? (float) $it['amount'] : null,
                    'amount_krw'  => isset($it['amount_krw']) && $it['amount_krw'] !== null ? (int) $it['amount_krw'] : null,
                ];
            }
        }
        $files[] = [
            'id'           => (int) ($row['id'] ?? 0),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'size_bytes'   => (int) ($row['size_bytes'] ?? 0),
            'uploaded_by'  => $by,
            'uploaded_name' => (string) ($row['uploaded_name'] ?? ''),
            'created_at'   => (string) ($row['created_at'] ?? ''),
            'analyzed'     => (string) ($row['analyzed_at'] ?? '') !== '',
            'items'        => $summaryItems,
            'is_ledger'    => (string) ($row['mime'] ?? '') === ReceiptValidator::XLSX_MIME,
            'can_delete'   => $isAdmin || ($by !== '' && $by === $me),
        ];
    }
    tbb_receipt_file_json(['ok' => true, 'files' => $files]);
}

if ($method === 'GET' && $action === 'download') {
    $id = (int) ($_GET['id'] ?? 0);
    $row = $id > 0 ? $receipts->findById($id) : null;
    if ($row === null) {
        tbb_receipt_file_json(['ok' => false, 'error' => '파일을 찾을 수 없습니다.'], 404);
    }
    $path = $receipts->absPath($row);
    if (!is_file($path)) {
        tbb_receipt_file_json(['ok' => false, 'error' => '파일이 존재하지 않습니다.'], 404);
    }
    $name = (string) ($row['display_name'] ?? 'receipt');
    $mime = (string) ($row['mime'] ?? 'application/octet-stream');
    $fallback = str_replace(['"', "\\"], '_', (string) preg_replace('/[^\x20-\x7E]/', '_', $name));

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header("Content-Disposition: attachment; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($method === 'POST' && $action === 'delete') {
    if (!$sso->verifyCsrf((string) ($body['csrf'] ?? ''))) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
    }
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'id가 필요합니다.'], 400);
    }
    try {
        $receipts->delete($id, $me, $isAdmin);
        tbb_receipt_file_json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        tbb_receipt_file_json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

// 팀비 관리대장(.xlsx)에서 대상 월 지출 내역을 추출해 반환(팀비 테이블 채우기용).
if ($method === 'POST' && $action === 'ledger_month') {
    if (!$sso->verifyCsrf((string) ($body['csrf'] ?? ''))) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
    }
    $id = (int) ($body['id'] ?? 0);
    $month = trim((string) ($body['month'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) === 1) {
        $month = substr($month, 0, 7);
    }
    $row = $receipts->findById($id);
    if ($row === null) {
        tbb_receipt_file_json(['ok' => false, 'error' => '파일을 찾을 수 없습니다.'], 404);
    }
    if ((string) ($row['mime'] ?? '') !== ReceiptValidator::XLSX_MIME) {
        tbb_receipt_file_json(['ok' => false, 'error' => '엑셀(.xlsx) 파일만 월 내역을 채울 수 있습니다.'], 400);
    }
    $path = $receipts->absPath($row);
    if (!is_file($path)) {
        tbb_receipt_file_json(['ok' => false, 'error' => '파일이 존재하지 않습니다.'], 404);
    }
    try {
        $rows = (new TeamLedgerParser())->parse($path, $month);
    } catch (Throwable $e) {
        tbb_receipt_file_json(['ok' => false, 'error' => '대장 분석 실패: ' . $e->getMessage()], 400);
    }

    return tbb_receipt_file_json(['ok' => true, 'items' => $rows, 'month' => $month, 'count' => count($rows)]);
}

if ($method === 'POST' && $action === 'analyze') {
    if (!$sso->verifyCsrf((string) ($body['csrf'] ?? ''))) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
    }
    $id = (int) ($body['id'] ?? 0);
    $row = $id > 0 ? $receipts->findById($id) : null;
    if ($row === null) {
        tbb_receipt_file_json(['ok' => false, 'error' => '파일을 찾을 수 없습니다.'], 404);
    }

    // 캐시된 분석 결과가 있으면 API 재호출 없이 반환(자동 캐싱). refresh=1이면 강제 재분석.
    $refresh = !empty($body['refresh']);
    $cached = (string) ($row['analysis_json'] ?? '');
    if (!$refresh && $cached !== '') {
        $decoded = json_decode($cached, true);
        if (is_array($decoded)) {
            tbb_receipt_file_json([
                'ok'          => true,
                'items'       => $decoded['items'] ?? [],
                'warnings'    => $decoded['warnings'] ?? [],
                'matched_name' => $decoded['matched_name'] ?? '',
                'cached'      => true,
            ]);
        }
    }

    $path = $receipts->absPath($row);
    if (!is_file($path)) {
        tbb_receipt_file_json(['ok' => false, 'error' => '파일이 존재하지 않습니다.'], 404);
    }
    $bytes = (string) file_get_contents($path);
    $mime = (string) ($row['mime'] ?? '');

    $templateId = (int) ($row['expense_template_id'] ?? 0);
    $tpl = $templateId > 0 ? tbb_templates()->findById($templateId) : null;
    $context = [
        'vendor'   => (string) ($tpl['vendor'] ?? ''),
        'currency' => (string) ($tpl['currency'] ?? ''),
        'title'    => (string) ($tpl['title'] ?? ''),
    ];

    try {
        $res = tbb_receipt_analyzer()->analyze($bytes, $mime, $context);
    } catch (Throwable $e) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'AI 분석 실패: ' . $e->getMessage()], 502);
    }

    $warnings = [];
    foreach ($res['warnings'] as $w) {
        $warnings[] = (string) $w;
    }

    // 영수증에서 추출한 이메일로 사용자 이름 매칭(회사 영수증 등 매칭 없으면 빈 값)
    $email = (string) ($res['email'] ?? '');
    $matchedName = $email !== '' ? tbb_name_for_email($email) : '';

    // 템플릿 설정: 내용·파일명에 거래처 표시 여부(기본 표시). 서버 등은 OFF.
    $includeVendor = (int) ($tpl['content_include_vendor'] ?? 1) === 1;

    // 외환이면 추출된 결제일의 환율로 원화 자동 계산
    $drafts = tbb_drafts();
    $items = [];
    $fnVendor = '';   // 파일명용: 첫 항목의 거래처
    $fnPlan = '';     // 파일명용: 첫 항목의 플랜/세부내용
    foreach ($res['items'] as $idx => $it) {
        $currency = $it['currency'] !== null ? strtoupper((string) $it['currency']) : null;
        $amount = $it['amount'] !== null ? (float) $it['amount'] : 0.0;
        $date = (string) ($it['payment_date'] ?? '');
        $it['amount_krw'] = null;
        if ($currency !== null && isset(tbb_currencies()[$currency]) && $amount > 0) {
            if ($currency === 'KRW') {
                $it['amount_krw'] = (int) round($amount);
            } else {
                try {
                    $q = $drafts->quoteRate($date, $currency, $amount);
                    if (($q['success'] ?? false) === true) {
                        $it['amount_krw'] = (int) ($q['amount_krw'] ?? 0);
                        $it['rate'] = $q['rate'] ?? null;
                        $it['rate_date'] = $q['rate_date'] ?? null;
                    } else {
                        $warnings[] = ($date !== '' ? $date : '오늘') . ' ' . $currency . ' 환율 조회 실패 — 원화는 추가 시 다시 계산됩니다.';
                    }
                } catch (Throwable $e) {
                    $warnings[] = '환율 계산 오류: ' . $e->getMessage();
                }
            }
        }
        // 내용 형식: 벤더사는 템플릿에 이미 있으므로 제외. "[사용자 -] 플랜/세부내용"만.
        // 플랜은 추출 설명의 첫 줄만 사용.
        $descRaw = trim((string) ($it['description'] ?? ''));
        $plan = $descRaw !== '' ? trim((string) (preg_split('/\r\n|\r|\n/', $descRaw)[0] ?? '')) : '';
        // 끝에 붙는 청구 기간 등 "(…연도…)" 괄호는 제거(내용 간결화)
        $plan = trim((string) preg_replace('/\s*\([^()]*\d{4}[^()]*\)\s*$/u', '', $plan));
        // 결제 식별자(UUID)·긴 해시는 제거. 예: "... d748d08c-1c83-49e5-9b2b-0c1ea82a7473"
        $plan = (string) preg_replace('/\s*\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/', '', $plan);
        $plan = trim((string) preg_replace('/\s*\b[0-9a-fA-F]{16,}\b\s*$/', '', $plan));
        // 거래처 표시 여부는 템플릿 설정(content_include_vendor)에 따름. 서버 등 OFF면 거래처 생략.
        // 개인 매칭: "사용자 (거래처) - 플랜" / 회사: "거래처 - 플랜" / 거래처 미표시: "플랜".
        $vendor = $includeVendor ? trim((string) ($it['vendor'] ?? '')) : '';
        if ($matchedName !== '') {
            $prefix = $vendor !== '' ? ($matchedName . ' (' . $vendor . ')') : $matchedName;
            $it['description'] = $plan !== '' ? ($prefix . ' - ' . $plan) : $prefix;
        } elseif ($vendor !== '') {
            $it['description'] = $plan !== '' ? ($vendor . ' - ' . $plan) : $vendor;
        } else {
            $it['description'] = $plan;
        }
        if ($idx === 0) { $fnVendor = $vendor; $fnPlan = $plan; }
        $items[] = $it;
    }
    unset($bytes);

    // 파일명 자동 리네임(내용과 동일 데이터 기반). 개인 → "년월_사용자(거래처)", 회사 → "년월_거래처_세부내용".
    $ym = str_replace('-', '', (string) ($row['target_year_month'] ?? ''));
    if ($fnVendor === '' && $includeVendor) {
        $fnVendor = trim((string) ($tpl['vendor'] ?? ''));
    }
    if ($matchedName !== '') {
        $newName = $fnVendor !== '' ? ($ym . '_' . $matchedName . '(' . $fnVendor . ')') : ($ym . '_' . $matchedName);
    } else {
        $newName = implode('_', array_filter([$ym, $fnVendor, $fnPlan], static fn($p) => $p !== ''));
    }
    $newDisplay = (string) ($row['display_name'] ?? '');
    if ($newName !== '' && $newName !== $ym) {
        try {
            $renamed = $receipts->rename($id, $newName);
            $newDisplay = (string) ($renamed['display_name'] ?? $newDisplay);
        } catch (Throwable $e) {
            // 리네임 실패는 조용히 무시(분석 결과는 그대로 반환)
        }
    }

    // 결과 자동 캐싱(다음 "AI 분석"부터는 API 재호출 없이 저장본 사용)
    $receipts->saveAnalysis($id, ['items' => $items, 'warnings' => $warnings, 'matched_name' => $matchedName]);
    tbb_receipt_file_json(['ok' => true, 'items' => $items, 'warnings' => $warnings, 'matched_name' => $matchedName, 'email' => $email, 'display_name' => $newDisplay, 'cached' => false]);
}

if ($method === 'POST' && $action === 'rename') {
    if (!$sso->verifyCsrf((string) ($body['csrf'] ?? ''))) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'CSRF 검증에 실패했습니다.'], 403);
    }
    $id = (int) ($body['id'] ?? 0);
    $name = trim((string) ($body['display_name'] ?? ''));
    if ($id < 1) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'id가 필요합니다.'], 400);
    }
    try {
        $row = $receipts->rename($id, $name);
        tbb_receipt_file_json(['ok' => true, 'display_name' => (string) ($row['display_name'] ?? '')]);
    } catch (InvalidArgumentException $e) {
        tbb_receipt_file_json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($method === 'GET' && $action === 'download_all') {
    $templateId = (int) ($_GET['template_id'] ?? 0);
    $month = trim((string) ($_GET['month'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) === 1) {
        $month = substr($month, 0, 7);
    }
    if ($templateId < 1 || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        tbb_receipt_file_json(['ok' => false, 'error' => '템플릿과 월이 필요합니다.'], 400);
    }
    $files = $receipts->listFor($templateId, $month);
    if ($files === []) {
        tbb_receipt_file_json(['ok' => false, 'error' => '첨부 파일이 없습니다.'], 404);
    }
    if (!class_exists('ZipArchive')) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'ZIP 기능을 사용할 수 없습니다.'], 500);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'rcz');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        tbb_receipt_file_json(['ok' => false, 'error' => 'ZIP 생성에 실패했습니다.'], 500);
    }
    $used = [];
    foreach ($files as $f) {
        $p = $receipts->absPath($f);
        if (!is_file($p)) {
            continue;
        }
        $name = (string) ($f['display_name'] ?? ('receipt_' . ($f['id'] ?? '')));
        $base = $name;
        $n = 1;
        while (isset($used[$name])) {
            $dot = strrpos($base, '.');
            $name = $dot !== false ? substr($base, 0, $dot) . '_' . $n . substr($base, $dot) : $base . '_' . $n;
            $n++;
        }
        $used[$name] = true;
        $zip->addFile($p, $name);
    }
    $zip->close();

    $zipName = $month . '_영수증.zip';
    $fallback = 'receipts_' . $month . '.zip';
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string) filesize($tmp));
    header("Content-Disposition: attachment; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($zipName));
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

tbb_receipt_file_json(['ok' => false, 'error' => '알 수 없는 요청입니다.'], 400);
