<?php
/**
 * 지출 템플릿 CRUD 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/template_crud_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$path = sys_get_temp_dir() . '/tbb_tpl_' . bin2hex(random_bytes(4)) . '.db';
Database::reset();
Database::setPath($path);
$pdo = Database::connection();
$provider = new ExpenseTemplateProvider($pdo);
$provider->setCreatedBy('lucy@innored.co.kr');

$all = $provider->listAll();
expect_true(count($all) >= 7, '시드 템플릿 목록');

$row = $provider->create([
    'title'         => '테스트 구독',
    'assignee'      => '루시',
    'account_info'  => '법인카드',
    'payment_site'  => 'https://cursor.com/dashboard',
    'cycle_type'    => 'MONTHLY',
    'currency'      => 'USD',
    'payment_type'  => 'AUTO',
    'title_pattern' => '[테스트] 구독료 결제 기안',
    'body_pattern'  => "건명: 테스트 구독\n금액은 작성 시 입력",
    'note'          => '직접 지정한 본문',
    'is_active'     => 1,
]);
expect_true((int) ($row['id'] ?? 0) > 0, '템플릿 생성 id');
expect_eq((string) ($row['created_by'] ?? ''), 'lucy@innored.co.kr', '작성자 저장');
expect_eq((string) ($row['title_pattern'] ?? ''), '[테스트] 구독료 결제 기안', '제목 그대로 저장');
expect_eq((string) ($row['body_pattern'] ?? ''), "건명: 테스트 구독\n금액은 작성 시 입력", '본문 그대로 저장');

$found = $provider->findById((int) $row['id']);
expect_true($found !== null, 'findById');
expect_eq((string) ($found['assignee'] ?? ''), '루시', '담당 저장');
expect_eq((string) ($found['payment_site'] ?? ''), 'https://cursor.com/dashboard', '결제 사이트 저장');

$row = $provider->update((int) $row['id'], [
    'title'                => '테스트 구독',
    'assignee'             => '루시',
    'account_info'         => '법인카드',
    'payment_site'         => 'https://cursor.com/dashboard',
    'cycle_type'           => 'MONTHLY',
    'currency'             => 'USD',
    'payment_type'         => 'AUTO',
    'title_pattern'        => '[테스트] 구독료 결제 기안',
    'body_pattern'         => "건명: 테스트 구독\n{contents}",
    'note'                 => '직접 지정한 본문',
    'is_active'            => 1,
    'vendor'               => 'Anysphere, Anthropic',
    'period_pattern'       => '1개월',
    'payment_method_text'  => '법인카드 결제',
    'pay_request_pattern'  => '자동결제',
    'attachment_text'      => '영수증 첨부',
]);
expect_eq((string) ($row['vendor'] ?? ''), 'Anysphere, Anthropic', '업체 저장');
expect_eq((string) ($row['content_mode'] ?? ''), 'LINES', '내용 모드는 기본 결제 줄');
expect_eq((string) ($row['period_pattern'] ?? ''), '1개월', '기간 패턴');
expect_eq((string) ($row['payment_method_text'] ?? ''), '법인카드 결제', '결제방식 문구');

$updated = $provider->update((int) $row['id'], [
    'title'         => '테스트 구독',
    'assignee'      => '단테',
    'account_info'  => '법인카드',
    'payment_site'  => 'https://claude.ai/settings',
    'cycle_type'    => 'YEARLY',
    'currency'      => 'USD',
    'payment_type'  => 'MANUAL',
    'title_pattern' => '[연간] 구독료 갱신 기안',
    'body_pattern'  => '갱신 본문',
    'note'          => '',
    'is_active'     => 1,
    'next_renewal_date' => '2027-05-01',
]);
expect_eq((string) ($updated['assignee'] ?? ''), '단테', '담당 수정');
expect_eq((string) ($updated['created_by'] ?? ''), 'lucy@innored.co.kr', '수정해도 작성자 유지');
expect_eq((string) ($updated['cycle_type'] ?? ''), 'YEARLY', '주기 수정');
expect_eq((string) ($updated['payment_site'] ?? ''), 'https://claude.ai/settings', '결제 사이트 수정');
expect_eq((string) ($updated['title_pattern'] ?? ''), '[연간] 구독료 갱신 기안', '제목 수정');
expect_eq(substr((string) ($updated['next_renewal_date'] ?? ''), 0, 10), '2027-05-01', '갱신일');

$off = $provider->setActive((int) $row['id'], false);
expect_eq((int) ($off['is_active'] ?? 1), 0, '비활성');

$ids = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $provider->listAll());
expect_true(in_array((int) $row['id'], $ids, true), '비활성도 목록에 포함');

try {
    $provider->create(['title' => '', 'assignee' => '루시', 'cycle_type' => 'MONTHLY', 'title_pattern' => 'T', 'body_pattern' => 'B']);
    expect_true(false, '빈 건명은 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '건명'), '건명 필수 메시지');
}

try {
    $provider->create([
        'title' => 'x', 'assignee' => '루시', 'cycle_type' => 'WEEKLY',
        'title_pattern' => 'T', 'body_pattern' => 'B',
    ]);
    expect_true(false, '잘못된 주기는 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '주기'), '주기 예외 메시지');
}

try {
    $provider->update(0, [
        'title' => 'x', 'assignee' => '루시', 'cycle_type' => 'MONTHLY',
        'title_pattern' => 'T', 'body_pattern' => 'B',
    ]);
    expect_true(false, '없는 템플릿 수정은 예외');
} catch (InvalidArgumentException $e) {
    expect_true(str_contains($e->getMessage(), '템플릿'), '없는 템플릿 메시지');
}

Database::reset();
@unlink($path);
tbb_test_done();
