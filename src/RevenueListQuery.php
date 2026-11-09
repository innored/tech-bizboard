<?php
/**
 * 매출(수입) 화면의 연도·월·탭·필터 쿼리를 화면 상태로 맞춘다.
 *
 * @date 2026-10-12
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/RevenueListQuery.php
 */

declare(strict_types=1);

class RevenueListQuery
{
    public const CLIENT_NONE = '__none__';

    /** @var list<string> */
    public const SERVICES = ['솔루션', '컨설팅', '유지보수', '개발비', '기타'];

    /**
     * @param array<string, mixed> $query
     * @return array{year: string, month: string, tab: string, client: string, service: string, solution: string, billing: string, kind: string}
     */
    public static function resolve(array $query, string $today): array
    {
        $year = trim((string) ($query['year'] ?? ''));
        $month = self::month($query['month'] ?? '');
        $tab = trim((string) ($query['tab'] ?? ''));
        $yearGiven = preg_match('/^\d{4}$/', $year) === 1;
        $todayYear = strlen($today) >= 4 ? substr($today, 0, 4) : '1970';
        $todayMonth = strlen($today) >= 7 ? substr($today, 5, 2) : '01';

        if (!$yearGiven) {
            $year = $todayYear;
            if ($month === '') {
                $month = $todayMonth;
            }
        }
        if (!in_array($tab, ['month', 'year', 'profit', 'tpl'], true)) {
            $tab = $month !== '' ? 'month' : 'year';
        }
        if ($tab === 'month' && $month === '') {
            $month = $year === $todayYear ? $todayMonth : '01';
        }

        return [
            'year'    => $year,
            'month'   => $month,
            'tab'     => $tab,
            'client'   => self::plainName($query['client'] ?? ''),
            'service'  => self::choice((string) ($query['service'] ?? ''), self::SERVICES),
            'solution' => self::plainName($query['solution'] ?? ''),
            'billing'  => self::choice((string) ($query['billing'] ?? ''), ['PAID', 'FREE']),
            'kind'     => self::choice((string) ($query['kind'] ?? ''), ['repeat', 'once']),
        ];
    }

    private static function month(mixed $value): string
    {
        $month = trim((string) $value);
        if (preg_match('/^\d{2}$/', $month) !== 1) {
            return '';
        }
        $num = (int) $month;
        if ($num < 1 || $num > 12) {
            return '';
        }

        return sprintf('%02d', $num);
    }

    private static function plainName(mixed $value): string
    {
        $client = trim((string) $value);
        if ($client === '' || str_contains($client, "\n") || str_contains($client, "\r")) {
            return '';
        }

        return $client;
    }

    /**
     * @param list<string> $allowed
     */
    private static function choice(string $value, array $allowed): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : '';
    }
}
