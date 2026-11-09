<?php
/**
 * 리더십 공유용 수익 요약(조회 전용). 허용 IP + SSO 로그인 + 역할(admin·member·viewer·leadership)이 있어야 열린다.
 * 데이터는 페이지에 직접 넣는다(별도 API 없음 → IP 제한을 API로 우회할 수 없다).
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/leadership
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sso = tbb_leadership_guard();
$SSO_USER = $sso->getUser();
$SHOW_LOGOUT = true;
$BRAND_LINK = !tbb_is_leadership_only($sso->getUser()); // 리포트 전용 계정은 로고로 다른 화면에 가지 못한다
$PAGE_SCRIPTS = ['asset/js/revenue_profit.js', 'asset/js/revenue_profit_view.js', 'asset/js/leadership.js'];

$today = tbb_today();
$year = trim((string) ($_GET['year'] ?? ''));
if (preg_match('/^\d{4}$/', $year) !== 1) {
    $year = substr($today, 0, 4);
}
$years = DashboardProvider::yearOptions();
if (!in_array((int) $year, $years, true)) {
    $years[] = (int) $year;
    rsort($years);
}

// 유상만 내려보낸다(무상·화면에 안 쓰는 칸은 페이지에 싣지 않는다)
$keys = [
    'id', 'target_year_month', 'received_date', 'project_name', 'client_name', 'service_category',
    'solution_name', 'solution_group', 'campaign_spend_krw', 'tech_fee_rate', 'supply_krw',
    'cost_krw', 'cost_note', 'billing_type', 'note',
];
$rows = [];
foreach (tbb_revenues()->listByYear($year) as $row) {
    if (($row['billing_type'] ?? '') !== 'PAID') {
        continue;
    }
    $rows[] = array_intersect_key($row, array_flip($keys));
}

require dirname(__DIR__) . '/view/leadership.php';
