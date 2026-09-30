<?php
/**
 * 지출 템플릿 설정
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/settings.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sso = tbb_guard();
tbb_require_admin($sso);
$SSO_USER = $sso->getUser();
$SHOW_LOGOUT = $sso->isLoginRequired();
$CSRF_TOKEN = $sso->csrfToken();
$PAGE_SCRIPTS = ['asset/js/settings.js'];
$rows = tbb_templates()->listAll();
$selectedId = (int) ($_GET['id'] ?? 0);

require dirname(__DIR__) . '/view/settings.php';
