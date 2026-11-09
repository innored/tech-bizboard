<?php
/**
 * CLI assert 헬퍼
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/assert.php
 */

declare(strict_types=1);

$GLOBALS['tbb_fail'] = 0;

function expect_true(bool $ok, string $msg): void
{
    if ($ok) {
        echo "OK  {$msg}\n";
        return;
    }
    $GLOBALS['tbb_fail']++;
    echo "FAIL  {$msg}\n";
}

function expect_eq(mixed $actual, mixed $expected, string $msg): void
{
    expect_true($actual === $expected, $msg . ' (actual=' . var_export($actual, true) . ')');
}

function tbb_test_done(): void
{
    $n = (int) $GLOBALS['tbb_fail'];
    echo $n === 0 ? "ALL PASSED\n" : "FAILED {$n}\n";
    exit($n === 0 ? 0 : 1);
}
