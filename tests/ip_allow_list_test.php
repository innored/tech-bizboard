<?php
/**
 * 리더십 페이지 IP 허용 목록(단일 IP·CIDR 대역)
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/ip_allow_list_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/IpAllowList.php';

$list = IpAllowList::fromString(' 203.0.113.10 , 198.51.100.0/24,, 2001:db8::/32 ');
expect_eq($list->rules(), ['203.0.113.10', '198.51.100.0/24', '2001:db8::/32'], '쉼표 구분·공백·빈칸 정리');

expect_true($list->allows('203.0.113.10'), '단일 IP 일치');
expect_true(!$list->allows('203.0.113.11'), '단일 IP 불일치');
expect_true($list->allows('198.51.100.0'), '대역 시작');
expect_true($list->allows('198.51.100.255'), '대역 끝');
expect_true(!$list->allows('198.51.101.1'), '대역 밖');
expect_true($list->allows('2001:db8:abcd::1'), 'IPv6 대역');
expect_true(!$list->allows('2001:db9::1'), 'IPv6 대역 밖');
expect_true(!$list->allows('::ffff:203.0.113.11'), 'IPv4-mapped 불일치');
expect_true($list->allows('::ffff:203.0.113.10'), 'IPv4-mapped IPv6도 IPv4로 본다');
expect_true(!$list->allows(''), '빈 IP 거부');
expect_true(!$list->allows('not-an-ip'), '형식 아닌 IP 거부');

$empty = IpAllowList::fromString('');
expect_true(!$empty->allows('203.0.113.10'), '목록이 비면 아무도 못 들어온다(실수로 열리지 않게)');

$bad = IpAllowList::fromString('198.51.100.0/99, 10.0.0.0/x, nope');
expect_true(!$bad->allows('198.51.100.1'), '잘못된 접두 길이 규칙은 무시');
expect_true(!$bad->allows('10.0.0.1'), '숫자 아닌 접두 길이 무시');

$all = IpAllowList::fromString('0.0.0.0/0');
expect_true($all->allows('8.8.8.8'), '/0 은 IPv4 전체');
expect_true(!$all->allows('2001:db8::1'), 'IPv4 규칙은 IPv6에 안 맞음');

tbb_test_done();
