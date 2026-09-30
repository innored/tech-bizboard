<?php
/**
 * TechBizBoard 진입점 · 손익 대시보드
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/index.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sso = tbb_sso();
$sso->startSession();
if ($sso->getUser() === null) {
    tbb_render_login($sso);
}

$sso = tbb_guard();
$SSO_USER = $sso->getUser();
$SHOW_LOGOUT = $sso->isLoginRequired();
$CSRF_TOKEN = $sso->csrfToken();
$PAGE_SCRIPTS = [
    'asset/vendor/echarts/echarts.min.js',
    'asset/js/dashboard.js',
];

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$today = $now->format('Y-m-d');
$year = trim((string) ($_GET['year'] ?? ''));
if (preg_match('/^\d{4}$/', $year) !== 1) {
    $year = (string) DashboardProvider::defaultYear($now);
}
$year = (int) $year;
$quarter = DashboardProvider::defaultQuarter($year, $now);
$years = DashboardProvider::yearOptions($now);
$summary = tbb_dashboard()->summarize($year, $now);

require dirname(__DIR__) . '/view/dashboard.php';
