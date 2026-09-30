<?php
/**
 * 로컬 세션을 파기한 뒤 허브 로그아웃으로 보낸다.
 *
 * @date 2026-09-14
 * @link https://lucy.conbus.co.kr/tech-bizboard/sso/logout
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$sso = tbb_sso();
$sso->startSession();
$hubLogout = $sso->logoutUrl();
$sso->logout();
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: ' . $hubLogout);
exit;
