<?php
/**
 * 팀비 관리대장(.xlsx)에서 특정 월의 지출 내역 줄을 추출한다.
 * 형식: 각 지출 줄은 E=날짜(엑셀 serial), F=사용처, G=사용내용, H=금액.
 * 헤더·요약("N월 팀비 지원금") 줄은 E가 serial이 아니거나 F/H가 비어 자동 제외된다.
 *
 * @date 2026-09-16
 */

declare(strict_types=1);

class TeamLedgerParser
{
    private const COL_DATE   = 5; // E
    private const COL_VENDOR = 6; // F
    private const COL_DESC   = 7; // G
    private const COL_AMOUNT = 8; // H

    /**
     * @return list<array{payment_date: string, description: string, vendor: string, amount: float, note: string}>
     */
    public function parse(string $path, string $yearMonth): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
            throw new InvalidArgumentException('대상 월 형식이 올바르지 않습니다.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('엑셀 파일을 열 수 없습니다.');
        }

        $shared = self::sharedStrings($zip);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $name) !== 1) {
                continue;
            }
            $xml = simplexml_load_string((string) $zip->getFromName((string) $name));
            if ($xml === false) {
                continue;
            }
            foreach ($xml->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $col = self::colNum((string) $c['r']);
                    $cells[$col] = ((string) $c['t'] === 's')
                        ? ($shared[(int) $c->v] ?? '')
                        : (string) $c->v;
                }
                $line = self::rowToLine($cells, $yearMonth);
                if ($line !== null) {
                    $out[] = $line;
                }
            }
        }
        $zip->close();

        // 결제일 오름차순 정렬(안정적 순서)
        usort($out, static fn(array $a, array $b): int => strcmp($a['payment_date'], $b['payment_date']));

        return $out;
    }

    /**
     * @param array<int, string> $cells
     * @return array{payment_date: string, description: string, vendor: string, amount: float, note: string}|null
     */
    private static function rowToLine(array $cells, string $yearMonth): ?array
    {
        $rawDate = trim((string) ($cells[self::COL_DATE] ?? ''));
        $vendor  = trim((string) ($cells[self::COL_VENDOR] ?? ''));
        $rawAmt  = trim((string) ($cells[self::COL_AMOUNT] ?? ''));
        // 지출 줄 판정: 날짜가 엑셀 serial(숫자), 사용처 있음, 금액 숫자.
        if ($rawDate === '' || !is_numeric($rawDate) || $vendor === '' || !is_numeric($rawAmt)) {
            return null;
        }
        $date = self::serialToDate((float) $rawDate);
        if ($date === '' || strncmp($date, $yearMonth, 7) !== 0) {
            return null; // 대상 월 아님
        }
        $amount = (float) $rawAmt;
        if ($amount <= 0) {
            return null;
        }

        return [
            'payment_date' => $date,
            'description'  => trim((string) ($cells[self::COL_DESC] ?? '')),
            'vendor'       => $vendor,
            'amount'       => $amount,
            'note'         => '',
        ];
    }

    /** 엑셀 날짜 serial → YYYY-MM-DD (1900 시스템, 기준일 1899-12-30). */
    private static function serialToDate(float $serial): string
    {
        $days = (int) floor($serial);
        if ($days <= 0) {
            return '';
        }
        $base = new DateTimeImmutable('1899-12-30');

        return $base->modify('+' . $days . ' days')->format('Y-m-d');
    }

    /** 셀 참조("F12")의 열 번호(A=1). */
    private static function colNum(string $ref): int
    {
        if (preg_match('/^([A-Z]+)/', $ref, $m) !== 1) {
            return 0;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }

    /** @return array<int, string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');
        if ($raw === false) {
            return [];
        }
        $xml = simplexml_load_string((string) $raw);
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $out[] = (string) $si->t;
                continue;
            }
            $text = '';
            foreach ($si->r as $r) {
                $text .= (string) $r->t;
            }
            $out[] = $text;
        }

        return $out;
    }
}
