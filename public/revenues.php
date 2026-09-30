<?php
/**
 * 수입 목록·반복 설정
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/revenues.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sso = tbb_guard();
$SSO_USER = $sso->getUser();
$SHOW_LOGOUT = $sso->isLoginRequired();
$CSRF_TOKEN = $sso->csrfToken();
$PAGE_SCRIPTS = ['asset/js/revenues.js'];

$year = trim((string) ($_GET['year'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
$today = tbb_today();
if (preg_match('/^\d{4}$/', $year) !== 1) {
    $year = substr($today, 0, 4);
    if (preg_match('/^\d{2}$/', $month) !== 1) {
        $month = substr($today, 5, 2);
    }
} elseif (preg_match('/^\d{2}$/', $month) !== 1) {
    $month = '';
}
$years = DashboardProvider::yearOptions();
$yearInt = (int) $year;
if (!in_array($yearInt, $years, true)) {
    $years[] = $yearInt;
    rsort($years);
}

require dirname(__DIR__) . '/view/revenues.php';
