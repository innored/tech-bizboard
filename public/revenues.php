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
$PAGE_SCRIPTS = ['asset/js/revenue_year_filter.js', 'asset/js/revenue_profit.js', 'asset/js/revenue_profit_view.js', 'asset/js/revenues.js'];

$today = tbb_today();
$resolved = RevenueListQuery::resolve($_GET, $today);
$year = $resolved['year'];
$month = $resolved['month'];
$tab = $resolved['tab'];
$filters = [
    'client'   => $resolved['client'],
    'service'  => $resolved['service'],
    'solution' => $resolved['solution'],
    'billing'  => $resolved['billing'],
    'kind'     => $resolved['kind'],
];
$years = DashboardProvider::yearOptions();
$yearInt = (int) $year;
if (!in_array($yearInt, $years, true)) {
    $years[] = $yearInt;
    rsort($years);
}

require dirname(__DIR__) . '/view/revenues.php';
