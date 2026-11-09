<?php
/**
 * 팀 환율 API 파서·캐시 폴백 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/exchange_rate_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/ExchangeRateProvider.php';

$cacheDir = sys_get_temp_dir() . '/tbb_fx_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0775, true);

$fixture = json_encode([
    'success'        => true,
    'date'           => '2026-09-08',
    'rate_date'      => '2026-09-08',
    'currency'       => 'USD',
    'currency_label' => '달러',
    'rate'           => 1342.6,
    'unit'           => 1,
    'unit_note'      => '',
    'source'         => 'cache',
    'updated_at'     => '2026-09-08 14:02:07',
], JSON_UNESCAPED_UNICODE);

$hits = 0;
$provider = new ExchangeRateProvider(
    'https://lucy-dev.conbus.co.kr/api/exchange/',
    $cacheDir,
    function (string $url) use (&$hits, $fixture): ?string {
        $hits++;
        if (!str_contains($url, 'date=2026-09-08') || !str_contains($url, 'currency=USD')) {
            return null;
        }

        return $fixture;
    }
);

$krw = $provider->getRate('2026-09-08', 'KRW');
expect_true($krw['success'] === true, 'KRW는 API 없이 성공');
expect_eq($krw['rate'] ?? null, 1.0, 'KRW rate 1');
expect_eq($krw['unit'] ?? null, 1, 'KRW unit 1');
expect_eq($hits, 0, 'KRW는 HTTP 호출 없음');

$usd = $provider->getRate('2026-09-08', 'USD');
expect_true($usd['success'] === true, 'USD 픽스처 성공');
expect_eq($usd['rate'] ?? null, 1342.6, 'USD rate');
expect_eq($usd['unit'] ?? null, 1, 'USD unit');
expect_eq($usd['rate_date'] ?? null, '2026-09-08', 'USD rate_date');
expect_eq($hits, 1, 'USD 1회 호출');

$usdAgain = $provider->getRate('2026-09-08', 'USD');
expect_eq($hits, 1, '같은 일자·통화는 캐시');
expect_eq($usdAgain['rate'] ?? null, 1342.6, '캐시 rate 유지');

$forcedProvider = new ExchangeRateProvider(
    'https://lucy-dev.conbus.co.kr/api/exchange/',
    $cacheDir,
    function (string $url) use (&$hits, $fixture): ?string {
        $hits++;
        if (!str_contains($url, 'date=2026-09-08') || !str_contains($url, 'currency=USD')) {
            return null;
        }

        return $fixture;
    }
);
$forced = $forcedProvider->getRate('2026-09-08', 'USD', true);
expect_eq($hits, 2, '강제 조회는 파일 캐시를 건너뛴다');
expect_eq($forced['rate'] ?? null, 1342.6, '강제 조회 rate');

$multiHits = 0;
$multi = new ExchangeRateProvider(
    'https://lucy-dev.conbus.co.kr/api/exchange/',
    $cacheDir . '_multi',
    function (string $url) use (&$multiHits): ?string {
        $multiHits++;
        if (str_contains($url, 'currency=USD')) {
            return json_encode(['success' => true, 'date' => '2026-08-19', 'rate_date' => '2026-08-19', 'currency' => 'USD', 'rate' => 1411, 'unit' => 1]);
        }
        if (str_contains($url, 'currency=EUR')) {
            return json_encode(['success' => true, 'date' => '2026-08-23', 'rate_date' => '2026-08-21', 'currency' => 'EUR', 'rate' => 1627.72, 'unit' => 1]);
        }

        return null;
    }
);
expect_true($multi->getRate('2026-08-19', 'USD')['success'] === true, 'USD 성공');
expect_true($multi->getRate('2026-08-23', 'EUR')['success'] === true, 'EUR 성공');
expect_eq($multiHits, 2, 'USD·EUR 각 1회');
$multi->getRate('2026-08-19', 'USD', true);
expect_eq($multiHits, 2, '같은 요청 안에서는 두 번째 강제 조회도 HTTP 없음');

$failing = new ExchangeRateProvider(
    'https://lucy-dev.conbus.co.kr/api/exchange/',
    $cacheDir,
    fn (string $url): ?string => null
);
$fallback = $failing->getRate('2026-09-08', 'USD');
expect_true($fallback['success'] === true, 'HTTP 실패 시 파일 캐시 폴백');
expect_eq($fallback['rate'] ?? null, 1342.6, '폴백 rate');

$emptyDir = $cacheDir . '_empty';
mkdir($emptyDir, 0775, true);
$noCache = new ExchangeRateProvider(
    'https://lucy-dev.conbus.co.kr/api/exchange/',
    $emptyDir,
    fn (string $url): ?string => 'not-json'
);
$miss = $noCache->getRate('2026-09-01', 'EUR');
expect_true($miss['success'] === false, '캐시 없고 파싱 실패면 실패');

foreach (glob($cacheDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);
foreach (glob($cacheDir . '_multi/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir . '_multi');
@rmdir($emptyDir);

tbb_test_done();
