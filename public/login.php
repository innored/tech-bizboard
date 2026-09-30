<?php
/**
 * 미로그인 첫 화면 진입
 *
 * @date 2026-09-14
 * @link https://lucy.conbus.co.kr/tech-bizboard/login
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sso = tbb_sso();
$sso->startSession();
if ($sso->getUser() !== null) {
    header('Location: ./');
    exit;
}

tbb_render_login($sso);
