<?php
/**
 * 팀 환율 API HTTP 클라이언트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/ExchangeRateProvider.php
 */

declare(strict_types=1);

class ExchangeRateProvider
{
    /** @var callable(string): ?string */
    private $httpGet;

    /** @var array<string, array<string, mixed>> */
    private array $memory = [];

    /**
     * @param callable(string): ?string|null $httpGet
     */
    public function __construct(
        private string $exchangeUrl,
        private string $cacheDir,
        ?callable $httpGet = null
    ) {
        $this->httpGet = $httpGet ?? [$this, 'httpGet'];
    }

    /**
     * @return array{
     *   success: bool,
     *   message?: string,
     *   date?: string,
     *   rate_date?: string,
     *   currency?: string,
     *   rate?: float,
     *   unit?: int
     * }
     */
    public function getRate(string $date, string $currency, bool $forceRefresh = false): array
    {
        $date = $this->normalizeDate($date);
        $currency = strtoupper(trim($currency));
        if ($date === null) {
            return ['success' => false, 'message' => 'date는 YYYY-MM-DD 형식이어야 합니다.'];
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return ['success' => false, 'message' => 'currency는 3자리 코드여야 합니다.'];
        }
        if ($currency === 'KRW') {
            return [
                'success'   => true,
                'date'      => $date,
                'rate_date' => $date,
                'currency'  => 'KRW',
                'rate'      => 1.0,
                'unit'      => 1,
            ];
        }

        $memKey = $date . '|' . $currency;
        if (isset($this->memory[$memKey])) {
            return $this->memory[$memKey];
        }

        $cached = $forceRefresh ? null : $this->readCache($date, $currency);
        if ($cached !== null) {
            return $this->memory[$memKey] = $cached;
        }

        $url = $this->buildUrl($date, $currency);
        $body = ($this->httpGet)($url);
        $parsed = is_string($body) ? $this->parseBody($body, $date, $currency) : null;
        if ($parsed !== null && ($parsed['success'] ?? false) === true) {
            $this->writeCache($date, $currency, $parsed);

            return $this->memory[$memKey] = $parsed;
        }

        $stale = $this->readCache($date, $currency, true);
        if ($stale !== null) {
            return $this->memory[$memKey] = $stale;
        }

        $failed = $parsed ?? [
            'success' => false,
            'message' => '환율을 가져오지 못했습니다.',
            'date'    => $date,
            'currency'=> $currency,
        ];

        return $this->memory[$memKey] = $failed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseBody(string $body, string $date, string $currency): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            return [
                'success' => false,
                'message' => (string) ($data['message'] ?? '환율 응답이 올바르지 않습니다.'),
                'date'    => $date,
                'currency'=> $currency,
            ];
        }
        $rate = $data['rate'] ?? null;
        $unit = $data['unit'] ?? 1;
        if (!is_numeric($rate)) {
            return [
                'success' => false,
                'message' => '매매기준율이 없습니다.',
                'date'    => $date,
                'currency'=> $currency,
            ];
        }

        return [
            'success'   => true,
            'date'      => (string) ($data['date'] ?? $date),
            'rate_date' => (string) ($data['rate_date'] ?? $date),
            'currency'  => (string) ($data['currency'] ?? $currency),
            'rate'      => (float) $rate,
            'unit'      => max(1, (int) $unit),
        ];
    }

    private function buildUrl(string $date, string $currency): string
    {
        $base = rtrim($this->exchangeUrl, '/');
        $query = http_build_query([
            'date'     => $date,
            'currency' => $currency,
        ]);

        return $base . '/?' . $query;
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)
            || preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)
        ) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $date, string $currency, bool $allowStale = false): ?array
    {
        $path = $this->cachePath($date, $currency);
        if (!is_file($path)) {
            return null;
        }
        if (!$allowStale && (time() - (int) filemtime($path)) > 86400) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) && ($data['success'] ?? false) === true ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCache(string $date, string $currency, array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents(
            $this->cachePath($date, $currency),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function cachePath(string $date, string $currency): string
    {
        return $this->cacheDir . '/exchange_' . $date . '_' . $currency . '.json';
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $httpCode !== 200 || $error !== '') {
            return null;
        }

        return $body;
    }
}
