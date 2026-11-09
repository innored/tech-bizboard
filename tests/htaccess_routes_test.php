<?php
/**
 * 두 .htaccess(개발 서브경로용 앱 루트 · 운영 public/ docroot용)의 페이지 목록이 같은지.
 * 한쪽에만 넣으면 개발은 되는데 운영만 404가 난다(2026-11-09 /leadership 사고).
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/htaccess_routes_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';

/** @return list<string> 페이지 RewriteRule 의 (a|b|c) 목록, 정렬 */
function tbb_htaccess_pages(string $file): array
{
    $text = (string) file_get_contents($file);
    if (preg_match('/^\s*RewriteRule \^\(([a-z|\-]+)\)\/\?\$ (?:public\/)?\$1\.php/m', $text, $m) !== 1) {
        return [];
    }
    $pages = explode('|', $m[1]);
    sort($pages);

    return $pages;
}

$root = tbb_htaccess_pages(dirname(__DIR__) . '/.htaccess');
$public = tbb_htaccess_pages(dirname(__DIR__) . '/public/.htaccess');

expect_true($root !== [], '앱 루트 .htaccess 페이지 규칙 찾음');
expect_eq($public, $root, '운영(public/) .htaccess 페이지 목록 = 개발(앱 루트) 목록');
foreach ($root as $page) {
    expect_true(is_file(dirname(__DIR__) . '/public/' . $page . '.php'), "public/{$page}.php 있음");
}

tbb_test_done();
