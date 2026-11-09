<?php
/**
 * viewer 역할 판정
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/viewer_role_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$sso = new SsoHandler(['client_id' => 'techbiz', 'require_login' => false]);

function tbb_viewer_case(SsoHandler $sso, array $roles): array
{
    $_SESSION['user'] = ['sub' => 'u', 'email' => 'a@b.c', 'name' => 'A', 'roles' => $roles];

    return [tbb_is_viewer($sso), tbb_is_admin($sso)];
}

expect_eq(tbb_viewer_case($sso, ['viewer']), [true, false], 'viewer는 조회 전용');
expect_eq(tbb_viewer_case($sso, ['VIEWER']), [true, false], '대소문자 무시');
expect_eq(tbb_viewer_case($sso, ['member']), [false, false], 'member는 편집 가능');
expect_eq(tbb_viewer_case($sso, ['admin']), [false, true], 'admin은 편집 가능');
expect_eq(tbb_viewer_case($sso, ['viewer', 'admin']), [false, true], 'admin이 함께 있으면 admin');
expect_eq(tbb_viewer_case($sso, []), [false, false], '역할 없음');

unset($_SESSION['user']);
expect_eq(tbb_is_viewer($sso), false, '로그인 안 함');

tbb_test_done();
