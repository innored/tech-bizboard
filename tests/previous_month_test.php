<?php
/**
 * 기안서 기본 대상 월(전월) 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/previous_month_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$tz = new DateTimeZone('Asia/Seoul');

expect_eq(
    tbb_previous_month(new DateTimeImmutable('2026-09-08', $tz)),
    '2026-08',
    '9월이면 기본 대상 월은 8월'
);
expect_eq(
    tbb_previous_month(new DateTimeImmutable('2026-01-03', $tz)),
    '2025-12',
    '1월이면 전년 12월'
);
expect_eq(
    tbb_today(new DateTimeImmutable('2026-09-09 11:46:00', $tz)),
    '2026-09-09',
    '오늘은 KST 날짜'
);

tbb_test_done();
