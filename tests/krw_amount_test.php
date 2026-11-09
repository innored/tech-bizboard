<?php
/**
 * 외화 → 원화 반올림 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/krw_amount_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/KrwAmountProcessor.php';

$proc = new KrwAmountProcessor();
expect_eq($proc->toKrw(200, 1342.6, 1), 268520, 'USD 200 × 1342.6');
expect_eq($proc->toKrw(99, 1561.04, 1), 154543, 'EUR 99 × 1561.04');
expect_eq($proc->toKrw(15000, 871.54, 100), 130731, 'JPY 15000 × 871.54 / 100');
expect_eq($proc->toKrw(10000, 1, 1), 10000, 'KRW 그대로');
expect_eq($proc->toKrw(10.4, 1, 1), 10, '원 단위 반올림 다운');
expect_eq($proc->toKrw(10.5, 1, 1), 11, '원 단위 반올림 업');
expect_eq($proc->toKrw(99, 1561.04, 0), 154543, 'unit 0은 1로 취급');

tbb_test_done();
