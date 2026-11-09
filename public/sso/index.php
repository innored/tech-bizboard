<?php
/**
 * Redirect URI. 허브가 ?code= 와 함께 되돌린다.
 *
 * @date 2026-09-14
 * @link https://lucy.conbus.co.kr/tech-bizboard/sso/
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$sso = tbb_sso();
$sso->startSession();

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    if ($sso->getUser() !== null) {
        header('Location: ' . $sso->homeUrl());
        exit;
    }
    header('Location: ' . $sso->authorizeUrl());
    exit;
}

// 서버에서 /userinfo?code= 로 즉시 1회 교환한다. access_token은 세션에만 둔다.
if (!$sso->exchangeCode($code)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo '로그인 실패';
    exit;
}

// 로그인 화면이 남긴 원래 주소(예: 리더십 페이지)로 돌아간다. 없으면 홈.
$back = tbb_safe_return_path((string) ($_SESSION['tbb_return_to'] ?? ''));
unset($_SESSION['tbb_return_to']);
header('Location: ' . ($back !== '' ? $back : $sso->homeUrl()));
exit;
