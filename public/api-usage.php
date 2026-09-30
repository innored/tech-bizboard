<?php
/**
 * API 사용량 조회 페이지 진입점 (admin 전용)
 *
 * @date 2026-09-15
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sso = tbb_guard();
tbb_require_admin($sso);

$PAGE_SCRIPTS = [
    'asset/vendor/echarts/echarts.min.js',
    'asset/js/api-usage.js',
];

require dirname(__DIR__) . '/view/api-usage.php';
