<?php
/**
 * 팀비 관리대장 xlsx 월별 파싱 테스트
 *
 * @date 2026-09-16
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/TeamLedgerParser.php';

$file = dirname(__DIR__) . '/storage/receipts/2026-08/2026년도 팀비 관리대장(애드테크팀).xlsx';
if (!is_file($file)) {
    echo "SKIP  대장 파일 없음 ({$file})\n";
    tbb_test_done();
}

$parser = new TeamLedgerParser();

$aug = $parser->parse($file, '2026-08');
expect_eq(count($aug), 2, '2026-08 지출 줄 2개');
expect_eq($aug[0]['vendor'], '쿨마트', '첫 줄 거래처');
expect_eq($aug[0]['payment_date'], '2026-08-31', '첫 줄 결제일(serial 변환)');
expect_eq($aug[0]['description'], '차대/1993', '적요는 G 통째');
expect_eq((int) $aug[0]['amount'], 93470, '첫 줄 금액');

$jul = $parser->parse($file, '2026-07');
expect_eq(count($jul), 7, '2026-07 지출 줄 7개');

// 대상 월 아닌 값은 안 섞임
foreach ($aug as $r) {
    expect_true(strncmp($r['payment_date'], '2026-08', 7) === 0, '모두 8월: ' . $r['payment_date']);
}

// 형식 오류 방어
$threw = false;
try {
    $parser->parse($file, '2026-8');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
expect_true($threw, '잘못된 월 형식은 예외');

tbb_test_done();
