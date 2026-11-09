<?php
/**
 * 원화 한글 표기 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/korean_won_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/KoreanWonProcessor.php';

$proc = new KoreanWonProcessor();

expect_eq($proc->toKorean(0), '영원', '0원');
expect_eq($proc->toKorean(578), '오백 칠십 팔', '백 미만 아닌 세 자리');
expect_eq($proc->toKorean(830578), '팔십 삼만 오백 칠십 팔', 'KINX 금액');
expect_eq($proc->toKorean(238749), '이십 삼만 팔천 칠백 사십 구', 'API 금액');
expect_eq($proc->toKorean(1607526), '백 육십만 칠천 오백 이십 육', '개발툴 금액');
expect_eq($proc->toKorean(10000), '일만', '일만');
expect_eq($proc->toKorean(1000), '천', '천은 일 생략');
expect_eq($proc->formal(238749), '일금 이십 삼만 팔천 칠백 사십 구 원정', '일금…원정');
expect_eq($proc->formal(830578), '일금 팔십 삼만 오백 칠십 팔 원정', 'KINX 일금');
expect_eq($proc->formal(0), '일금 영원정', '0원 일금');

tbb_test_done();
