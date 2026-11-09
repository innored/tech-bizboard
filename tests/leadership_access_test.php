<?php
/**
 * 리더십 페이지 접근 판정(IP → 로그인 → 역할)과 로그인 후 돌아갈 주소
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/leadership_access_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$ips = IpAllowList::fromString('203.0.113.0/24');
$member = ['email' => 'a@b.c', 'roles' => ['member']];

expect_eq(tbb_leadership_decide(null, $ips, '198.51.100.1'), 'forbidden_ip', '허용 IP가 아니면 로그인 화면도 안 보여준다');
expect_eq(tbb_leadership_decide($member, $ips, '198.51.100.1'), 'forbidden_ip', '로그인했어도 IP가 아니면 거부');
expect_eq(tbb_leadership_decide(null, $ips, '203.0.113.5'), 'login', '허용 IP + 미로그인 → 로그인');
expect_eq(tbb_leadership_decide($member, $ips, '203.0.113.5'), 'ok', 'member 허용');
expect_eq(tbb_leadership_decide(['roles' => ['viewer']], $ips, '203.0.113.5'), 'ok', 'viewer 허용');
expect_eq(tbb_leadership_decide(['roles' => ['ADMIN']], $ips, '203.0.113.5'), 'ok', 'admin 허용(대소문자 무시)');
expect_eq(tbb_leadership_decide(['roles' => []], $ips, '203.0.113.5'), 'forbidden_role', '역할 없는 계정 거부');
expect_eq(tbb_leadership_decide(['roles' => ['guest']], $ips, '203.0.113.5'), 'forbidden_role', '모르는 역할 거부');
expect_eq(tbb_leadership_decide($member, IpAllowList::fromString(''), '203.0.113.5'), 'forbidden_ip', '허용 목록이 비면 거부');

expect_eq(tbb_safe_return_path('/tech-bizboard/leadership?year=2026'), '/tech-bizboard/leadership?year=2026', '같은 사이트 경로는 그대로');
expect_eq(tbb_safe_return_path('//evil.example/x'), '', '프로토콜 상대 주소 거부');
expect_eq(tbb_safe_return_path('https://evil.example/'), '', '절대 주소 거부');
expect_eq(tbb_safe_return_path('/\\evil.example'), '', '역슬래시 우회 거부');
expect_eq(tbb_safe_return_path("/a\r\nLocation: x"), '', '줄바꿈 거부');
expect_eq(tbb_safe_return_path(''), '', '빈 값');

// leadership = 리더십 리포트 전용: 다른 화면은 리포트로 보내고, API는 403
expect_eq(tbb_leadership_route(['roles' => ['leadership']], false), 'redirect', 'leadership이 다른 화면 → 리포트로');
expect_eq(tbb_leadership_route(['roles' => ['LEADERSHIP']], true), 'forbidden', 'leadership이 API → 403(대소문자 무시)');
expect_eq(tbb_leadership_route(['roles' => ['leadership', 'member']], false), 'ok', '더 넓은 역할이 함께 있으면 제한 없음');
expect_eq(tbb_leadership_route(['roles' => ['leadership', 'viewer']], true), 'ok', 'viewer가 함께 있으면 viewer 규칙(조회 전용)');
expect_eq(tbb_leadership_route(['roles' => ['viewer']], false), 'ok', 'viewer는 앱 전체 조회 가능');
expect_eq(tbb_leadership_route(null, false), 'ok', '미로그인은 로그인 흐름에 맡긴다');
expect_eq(tbb_leadership_decide(['roles' => ['leadership']], $ips, '203.0.113.5'), 'ok', 'leadership은 리포트 허용');
expect_true(tbb_is_leadership_only(['roles' => ['leadership']]), 'leadership만 있으면 리포트 전용');
expect_true(!tbb_is_leadership_only(['roles' => ['leadership', 'admin']]), 'admin이 함께 있으면 리포트 전용 아님');

tbb_test_done();
