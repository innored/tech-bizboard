<?php
declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

// Claude tool_use 응답을 흉내내는 주입식 HTTP
$fakeResponse = static function (string $url, array $headers, string $body): array {
    // 요청에 document 블록과 tool_choice가 실려야 한다
    expect_true(str_contains($body, '"type":"document"'), '요청에 document 블록 포함');
    expect_true(str_contains($body, 'extract_receipt'), '요청에 extract_receipt 도구 지정');
    $payload = [
        'content' => [[
            'type' => 'tool_use',
            'name' => 'extract_receipt',
            'input' => [
                'items' => [[
                    'description'  => 'Cursor Pro 구독',
                    'amount'       => 20,
                    'currency'     => 'usd',
                    'payment_date' => '2026-09-03',
                    'vendor'       => 'Cursor',
                    'confidence'   => 0.9,
                ]],
                'warnings' => [],
            ],
        ]],
    ];
    return ['status' => 200, 'body' => json_encode($payload), 'error' => ''];
};

$analyzer = new ReceiptAnalyzer('sk-test', 'claude-opus-4-8', $fakeResponse);
$out = $analyzer->analyze('%PDF-1.7 fake bytes', 'application/pdf', ['vendor' => 'Cursor', 'currency' => 'USD']);

expect_eq(count($out['items']), 1, '항목 1개 추출');
expect_eq((string) $out['items'][0]['description'], 'Cursor Pro 구독', '내용 추출');
expect_eq((float) $out['items'][0]['amount'], 20.0, '금액 추출');
expect_eq((string) $out['items'][0]['currency'], 'USD', '통화 대문자 정규화');
expect_eq((string) $out['items'][0]['payment_date'], '2026-09-03', '결제일 유지');

// 지원하지 않는 형식은 예외
try {
    $analyzer->analyze('x', 'image/heic');
    expect_true(false, 'HEIC 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '형식'), 'HEIC 예외 메시지');
}

// 비200 응답은 예외
$err = static fn (string $u, array $h, string $b): array =>
    ['status' => 401, 'body' => json_encode(['error' => ['message' => 'invalid key']]), 'error' => ''];
try {
    (new ReceiptAnalyzer('sk-test', 'claude-opus-4-8', $err))->analyze('%PDF', 'application/pdf');
    expect_true(false, '401 예외');
} catch (RuntimeException $e) {
    expect_true(str_contains($e->getMessage(), 'invalid key'), '401 예외 메시지');
}

// ── Fix round 1: missing keys + invalid date — no PHP warnings ──────────────
// Set an error handler that captures any E_NOTICE/E_WARNING emitted during analyze()
$capturedWarnings = [];
set_error_handler(static function (int $errno, string $errstr) use (&$capturedWarnings): bool {
    $capturedWarnings[] = $errstr;
    return true; // suppress default handler
}, E_WARNING | E_NOTICE);

$fakeMissingKeys = static function (string $url, array $headers, string $body): array {
    $payload = [
        'content' => [[
            'type' => 'tool_use',
            'name' => 'extract_receipt',
            'input' => [
                'items' => [
                    // (a) item missing 'currency' and 'payment_date' entirely
                    [
                        'description' => 'Missing fields item',
                        'amount'      => 100,
                        'vendor'      => 'TestVendor',
                    ],
                    // (b) item with invalid payment_date format
                    [
                        'description'  => 'Bad date item',
                        'amount'       => 50,
                        'currency'     => 'USD',
                        'payment_date' => '2026/09/03',
                        'vendor'       => 'TestVendor2',
                    ],
                ],
                'warnings' => [],
            ],
        ]],
    ];
    return ['status' => 200, 'body' => json_encode($payload), 'error' => ''];
};

$out2 = (new ReceiptAnalyzer('sk-test', 'claude-opus-4-8', $fakeMissingKeys))->analyze('%PDF', 'application/pdf');

restore_error_handler();

// No PHP warnings/notices must have been emitted
expect_true(
    count($capturedWarnings) === 0,
    '누락 키 처리 시 PHP 경고 없음 (captured: ' . implode('; ', $capturedWarnings) . ')'
);

// (a) missing-key item: currency and payment_date come back as null
expect_true($out2['items'][0]['currency'] === null, '누락 currency → null');
expect_true($out2['items'][0]['payment_date'] === null, '누락 payment_date → null');

// (b) invalid-date item: payment_date is null and warnings has an entry about the date
expect_true($out2['items'][1]['payment_date'] === null, '잘못된 결제일 → null');
expect_true(
    count(array_filter($out2['warnings'], static fn(string $w) => str_contains($w, '2026/09/03'))) > 0,
    '잘못된 결제일 경고 포함'
);

tbb_test_done();
