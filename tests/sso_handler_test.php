<?php
/**
 * Account Hub 연동 계약 테스트
 *
 * @date 2026-09-14
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/sso_handler_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/SsoHandler.php';

$_SESSION = [];

function tbb_sso_config(array $over = []): array
{
    return array_merge([
        'provider_url'  => 'https://account.innored.co.kr',
        'client_id'     => 'techbiz',
        'redirect_uri'  => 'https://lucy-dev.conbus.co.kr/tech-bizboard/sso/',
        'home_url'      => 'https://lucy-dev.conbus.co.kr/tech-bizboard/',
        'require_login' => false,
    ], $over);
}

function tbb_userinfo_fixture(array $over = []): array
{
    return array_merge([
        'sub'           => 'uuid-1',
        'email'         => 'lucy@innored.co.kr',
        'name'          => '루시',
        'company'       => '이노레드',
        'department'    => '테크본부',
        'position'      => '팀장',
        'email_verified'=> true,
        'is_demo'       => false,
        'roles'         => ['member'],
        'access_token'  => 'tok-abc',
        'token_type'    => 'Bearer',
        'expires_in'    => 2592000,
    ], $over);
}

$empty = new SsoHandler(tbb_sso_config(['client_id' => '']));
expect_true(!$empty->isConfigured(), 'client_id 비면 미설정');
expect_true(!$empty->isLoginRequired(), '미설정이면 로그인 강제 안 함');

$open = new SsoHandler(tbb_sso_config(['require_login' => false]));
expect_true(!$open->isLoginRequired(), 'require_login=false면 로그인 생략');

$forced = new SsoHandler(tbb_sso_config(['require_login' => true]));
expect_true($forced->isLoginRequired(), 'require_login=true면 로그인 필요');
expect_true($forced->isConfigured(), 'client_id만 있으면 설정됨');

$sso = new SsoHandler(tbb_sso_config());
$sso->startSession();
expect_eq($sso->getUser(), null, '초기 사용자 없음');

$csrf = $sso->csrfToken();
expect_true($csrf !== '', 'CSRF 토큰 발급');
expect_true($sso->verifyCsrf($csrf), 'CSRF 일치');
expect_true(!$sso->verifyCsrf('wrong'), 'CSRF 불일치');

$authUrl = $sso->authorizeUrl();
expect_eq(
    $authUrl,
    'https://account.innored.co.kr/authorize?client_id=techbiz&redirect_uri=https%3A%2F%2Flucy-dev.conbus.co.kr%2Ftech-bizboard%2Fsso%2F',
    'authorize URL은 등록 문자열 그대로'
);
expect_true(!str_contains($authUrl, 'response_type'), 'authorize에 response_type 없음');
expect_true(!str_contains($authUrl, 'scope'), 'authorize에 scope 없음');
expect_true(!str_contains($authUrl, 'state'), 'authorize에 state 없음');
expect_true(!str_contains($authUrl, 'openid'), 'authorize에 openid 없음');
expect_true(!str_contains($authUrl, 'prompt'), 'authorize에 prompt 없음');

expect_eq(
    $sso->logoutUrl(),
    'https://account.innored.co.kr/logout?client_id=techbiz&redirect=https%3A%2F%2Flucy-dev.conbus.co.kr%2Ftech-bizboard%2F',
    '로그아웃 URL은 허브 + 대표 URL'
);
expect_true(!str_contains($sso->logoutUrl(), '/sso/'), '로그아웃 redirect에 콜백 없음');
expect_eq($sso->homeUrl(), 'https://lucy-dev.conbus.co.kr/tech-bizboard/', '착지는 대표 URL');
expect_eq($sso->accountUrl(), 'https://account.innored.co.kr/account', '정보 수정은 허브 /account');

$headerView = (string) file_get_contents(dirname(__DIR__) . '/view/_header.php');
expect_true(str_contains($headerView, 'accountUrl'), '헤더 이름에 허브 정보 수정 링크');

$captured = [];
$ex = new SsoHandler(tbb_sso_config(), 43200, function (string $url, array $headers) use (&$captured): array {
    $captured = ['url' => $url, 'headers' => $headers];

    return [200, (string) json_encode(tbb_userinfo_fixture(), JSON_UNESCAPED_UNICODE)];
});
$ex->startSession();
expect_true($ex->exchangeCode('one-time-code'), '코드 교환 성공');
expect_eq($captured['url'] ?? '', 'https://account.innored.co.kr/userinfo?code=one-time-code', '교환은 /userinfo?code=');
expect_eq($captured['headers'] ?? [], [], '코드 교환에 Bearer 헤더 없음');
expect_eq($ex->getUser()['email'] ?? null, 'lucy@innored.co.kr', '세션 이메일');
expect_eq($ex->getUser()['name'] ?? null, '루시', '세션 이름');
expect_eq($ex->getUser()['roles'] ?? null, ['member'], '역할 보관');
expect_true($ex->hasRole('member'), 'member 역할');
expect_true(!$ex->hasRole('admin'), 'member는 admin 아님');

$_SESSION = [];
$adm = new SsoHandler(tbb_sso_config(), 43200, static fn (): array => [
    200,
    (string) json_encode(tbb_userinfo_fixture(['roles' => ['admin']]), JSON_UNESCAPED_UNICODE),
]);
$adm->startSession();
expect_true($adm->exchangeCode('admin-code'), 'admin 코드 교환');
expect_true($adm->hasRole('admin'), 'admin 역할');
expect_true($adm->hasRole('ADMIN'), 'admin 대소문자');
expect_true(!$adm->hasRole('member'), 'admin만이면 member 아님');
expect_eq($ex->getUser()['is_demo'] ?? null, false, 'is_demo 보관');
expect_true(!isset($ex->getUser()['access_token']), 'getUser에 access_token 없음');
expect_eq($_SESSION['sso_at'] ?? null, 'tok-abc', 'access_token은 서버 세션만');

$_SESSION = [];
$fail = new SsoHandler(tbb_sso_config(), 43200, static fn (): array => [400, '{"error":"invalid_code"}']);
$fail->startSession();
expect_true(!$fail->exchangeCode('bad'), 'invalid_code면 실패');
expect_eq($fail->getUser(), null, '실패 후 세션 없음');

$noSub = new SsoHandler(tbb_sso_config(), 43200, static fn (): array => [200, '{"email":"x@y.z"}']);
$noSub->startSession();
expect_true(!$noSub->exchangeCode('x'), 'sub 없으면 실패');

$_SESSION = [];
$refCalls = [];
$ref = new SsoHandler(tbb_sso_config(), 43200, function (string $url, array $headers) use (&$refCalls): array {
    $refCalls[] = ['url' => $url, 'headers' => $headers];
    if (str_contains($url, 'code=')) {
        return [200, (string) json_encode(tbb_userinfo_fixture(), JSON_UNESCAPED_UNICODE)];
    }

    $fresh = tbb_userinfo_fixture(['name' => '루시갱신']);
    unset($fresh['access_token']);

    return [200, (string) json_encode($fresh, JSON_UNESCAPED_UNICODE)];
});
$ref->startSession();
$ref->exchangeCode('c1');
expect_true($ref->refreshUser(), 'Bearer 재조회 성공');
expect_eq($refCalls[1]['url'] ?? '', 'https://account.innored.co.kr/userinfo', '재조회는 /userinfo');
expect_eq($refCalls[1]['headers']['Authorization'] ?? '', 'Bearer tok-abc', '재조회는 Bearer');
expect_eq($ref->getUser()['name'] ?? null, '루시갱신', '재조회로 이름 갱신');
expect_eq($_SESSION['sso_at'] ?? null, 'tok-abc', '재조회 후에도 토큰 유지');

$denied = new SsoHandler(tbb_sso_config(), 43200, function (string $url, array $headers): array {
    if (str_contains($url, 'code=')) {
        return [200, (string) json_encode(tbb_userinfo_fixture(), JSON_UNESCAPED_UNICODE)];
    }

    return [403, '{"error":"access_denied"}'];
});
$denied->startSession();
$denied->exchangeCode('c2');
expect_true(!$denied->refreshUser(), '403이면 재조회 실패');
expect_eq($denied->getUser(), null, '403이면 세션 삭제');
expect_true(!isset($_SESSION['sso_at']), '403이면 토큰 삭제');

$ex->logout();
expect_eq($ex->getUser(), null, '로그아웃 후 세션 없음');

$forced->requireLogin();
expect_true(true, 'CLI requireLogin은 화면을 열지 않음');

$loginView = (string) file_get_contents(dirname(__DIR__) . '/view/login.php');
expect_true(str_contains($loginView, '이노레드 통합 로그인'), '첫 화면에 통합 로그인 버튼');
expect_true(str_contains($loginView, '이번 달 기안'), '첫 화면 제목');
expect_true(!str_contains($loginView, 'sso/login'), '버튼이 sso/login 홉을 쓰지 않음');

$htaccess = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
expect_true((bool) preg_match('#\^sso/\?\$#', $htaccess), '/sso/ 를 index.php 로 보냄');

$ssoIndex = (string) file_get_contents(dirname(__DIR__) . '/public/sso/index.php');
expect_true(str_contains($ssoIndex, 'userinfo?code='), 'Redirect URI에서 코드 교환');
expect_true(!is_file(dirname(__DIR__) . '/public/sso/callback.php'), '구 callback.php 제거');
expect_true(!is_file(dirname(__DIR__) . '/public/sso/login.php'), '구 sso/login.php 제거');

tbb_test_done();
