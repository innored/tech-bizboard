<?php
/**
 * 템플릿 선택 후 기안 1장 작성 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/draft_create_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_create_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();

$cacheDir = sys_get_temp_dir() . '/tbb_fx_create_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0775, true);

$templates = new ExpenseTemplateProvider($pdo);
$provider = new DraftExpenseProvider(
    $pdo,
    $templates,
    new ExchangeRateProvider(
        'https://lucy-dev.conbus.co.kr/api/exchange/',
        $cacheDir,
        static function (): ?string {
            return json_encode([
                'success'   => true,
                'date'      => '2026-08-01',
                'rate_date' => '2026-08-01',
                'currency'  => 'USD',
                'rate'      => 1342.6,
                'unit'      => 1,
            ]);
        }
    ),
    new KrwAmountProcessor(),
    new DraftTextProcessor()
);
$provider->setCreatedBy('lucy@innored.co.kr');

$active = $templates->listActive();
expect_eq(count($active), 7, '활성 템플릿 전부(월간+연간+비정기)');

$pdo->exec("UPDATE tb_expense_templates SET is_active = 0 WHERE title = '팀 운영비 정산'");
$activeAfterStop = $templates->listActive();
expect_eq(count($activeAfterStop), 6, '중지 템플릿은 작성 목록에서 제외');

$kinxId = (int) $pdo->query(
    "SELECT id FROM tb_expense_templates WHERE title = 'KINX iXCloud 서버 사용료'"
)->fetchColumn();
$appleId = (int) $pdo->query(
    "SELECT id FROM tb_expense_templates WHERE title = '애플 개발자 계정 갱신'"
)->fetchColumn();
$stoppedId = (int) $pdo->query(
    "SELECT id FROM tb_expense_templates WHERE title = '팀 운영비 정산'"
)->fetchColumn();

expect_eq($provider->listByMonth('2026-08'), [], '화면만 열면 기안이 생기지 않음');

$krwItem = [
    'payment_date' => '2026-08-01',
    'description'  => '서버 사용료',
    'currency'     => 'KRW',
    'amount'       => 1,
];

$row = $provider->createFromTemplate($kinxId, '2026-08', [$krwItem]);
expect_true((int) ($row['id'] ?? 0) > 0, '작성 시 id 반환');
expect_eq((string) ($row['created_by'] ?? ''), 'lucy@innored.co.kr', '기안 작성자');
expect_eq((string) ($row['target_year_month'] ?? ''), '2026-08', '대상 월 저장');
expect_eq((int) ($row['expense_template_id'] ?? 0), $kinxId, '선택한 템플릿');
expect_eq((string) ($row['status'] ?? ''), 'DRAFT', '작성 직후 초안');
expect_eq(
    (string) ($row['draft_title'] ?? ''),
    '[2026년 8월] KINX iXCloud 서버 사용료 결제 기안',
    '제목 {month}는 8 (08 아님)'
);
expect_eq(count($provider->listByMonth('2026-08')), 1, '작성한 1장만 목록에');

$kinxTpl = $templates->findById($kinxId);
$templates->update($kinxId, array_merge($kinxTpl ?? [], [
    'title_pattern' => '{year}년 {month}월 클라우드 서버 이용에 대한 비용 건',
    'body_pattern'  => "{title} 건에 대하여 진행하고자 하오니 재가하여 주시기 바랍니다.\n\n{contents}",
]));
$preview = $provider->previewFromTemplate($kinxId, '2026-08', [$krwItem]);
expect_eq(
    (string) ($preview['draft_title'] ?? ''),
    '2026년 8월 클라우드 서버 이용에 대한 비용 건',
    '설정 제목을 바꾸면 미리보기도 바뀜'
);
expect_true(
    str_contains((string) ($preview['draft_body'] ?? ''), '건에 대하여 진행하고자'),
    '설정 본문을 바꾸면 미리보기도 바뀜'
);
expect_eq(
    (string) ($provider->listByMonth('2026-08')[0]['draft_title'] ?? ''),
    '[2026년 8월] KINX iXCloud 서버 사용료 결제 기안',
    '이미 작성한 초안 제목은 그대로 둠'
);

$yearly = $provider->createFromTemplate($appleId, '2026-08', [[
    'payment_date' => '2026-08-01',
    'description'  => '애플 개발자 계정',
    'currency'     => 'USD',
    'amount'       => 99,
]]);
expect_true(str_contains((string) ($yearly['draft_title'] ?? ''), '애플'), '연간 템플릿도 작성 가능');
expect_eq(count($provider->listByMonth('2026-08')), 2, '연간 작성 후 2장');

try {
    $provider->createFromTemplate($kinxId, '2026-08', [$krwItem]);
    expect_true(false, '같은 달 같은 템플릿 중복 작성은 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '이미'), '중복 작성 메시지');
}

try {
    $provider->createFromTemplate($stoppedId, '2026-08');
    expect_true(false, '중지 템플릿은 작성 불가');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '중지'), '중지 템플릿 메시지');
}

try {
    $provider->createFromTemplate(0, '2026-08');
    expect_true(false, '없는 템플릿은 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '템플릿'), '없는 템플릿 메시지');
}

try {
    $provider->createFromTemplate($kinxId, '2026/08');
    expect_true(false, '월 형식 오류는 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), 'YYYY-MM'), '월 형식 메시지');
}

Database::reset();
@unlink($path);
foreach (glob($cacheDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);

tbb_test_done();
