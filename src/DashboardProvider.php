<?php
/**
 * 월별 손익 집계
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/DashboardProvider.php
 */

declare(strict_types=1);

class DashboardProvider
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function defaultYear(?DateTimeImmutable $now = null): int
    {
        return (int) substr(tbb_previous_month($now), 0, 4);
    }

    public static function defaultQuarter(int $year, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
        if ($year === (int) $now->format('Y')) {
            return (int) ceil(((int) $now->format('n')) / 3);
        }

        return 4;
    }

    /**
     * @return list<int>
     */
    public static function yearOptions(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
        $end = max((int) $now->format('Y'), self::defaultYear($now));
        $years = [];
        for ($y = $end; $y >= $end - 4; $y--) {
            $years[] = $y;
        }

        return $years;
    }

    /**
     * @return array{
     *   year: int,
     *   year_total: array{revenue_krw: int, expense_krw: int, margin_krw: int},
     *   previous_month: array{ym: string, revenue_krw: int, expense_krw: int, margin_krw: int},
     *   series: list<array{ym: string, revenue_krw: int, expense_krw: int, margin_krw: int}>
     * }
     */
    public function summarize(int $year, ?DateTimeImmutable $now = null): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('연도를 확인해 주세요.');
        }

        $byYm = [];
        $stmt = $this->pdo->prepare(
            'SELECT ym, revenue_krw, expense_krw, margin_krw
             FROM vi_monthly_pnl
             WHERE ym LIKE :prefix'
        );
        $stmt->execute(['prefix' => sprintf('%04d-', $year) . '%']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $ym = (string) ($row['ym'] ?? '');
            $byYm[$ym] = $this->triple($ym, $row);
        }

        $series = [];
        $rev = 0;
        $exp = 0;
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $year, $m);
            $item = $byYm[$ym] ?? $this->triple($ym, null);
            $series[] = $item;
            $rev += $item['revenue_krw'];
            $exp += $item['expense_krw'];
        }

        $prevYm = tbb_previous_month($now);
        $prev = $this->monthRow($prevYm);
        $prevPrevYm = (new DateTimeImmutable($prevYm . '-01'))
            ->modify('-1 month')
            ->format('Y-m');
        $prevPrev = $this->monthRow($prevPrevYm);

        return [
            'year'                => $year,
            'year_total'          => [
                'revenue_krw' => $rev,
                'expense_krw' => $exp,
                'margin_krw'  => $rev - $exp,
            ],
            'year_total_prev'     => $this->yearAggregate($year - 1),
            'previous_month'      => $prev,
            'previous_month_prev' => $prevPrev,
            'break_even_month'    => self::breakEvenMonth($series),
            'expense_breakdown'   => $this->breakdownExpenses($year),
            'revenue_breakdown'   => $this->breakdownRevenues($year),
            'revenue_project_breakdown' => $this->breakdownRevenueProjects($year),
            'series'              => $series,
        ];
    }

    /**
     * 해당 연도 매입을 기안서(지출 템플릿)별로 합산해 내림차순으로. 비중 도넛용.
     *
     * @return list<array{label: string, amount_krw: int}>
     */
    private function breakdownExpenses(int $year): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(t.title, d.draft_title) AS label, SUM(d.amount_krw) AS amount_krw
             FROM tb_draft_expenses d
             LEFT JOIN tb_expense_templates t ON t.id = d.expense_template_id
             WHERE d.target_year_month LIKE :prefix
             GROUP BY COALESCE(t.title, d.draft_title)
             HAVING SUM(d.amount_krw) > 0
             ORDER BY amount_krw DESC"
        );
        $stmt->execute(['prefix' => sprintf('%04d-', $year) . '%']);

        return $this->normalizeBreakdown($stmt, '기타');
    }

    /**
     * 해당 연도 매출을 거래처별로 합산해 내림차순으로. 비중 도넛용.
     *
     * @return list<array{label: string, amount_krw: int}>
     */
    private function breakdownRevenues(int $year): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CASE WHEN client_name IS NULL OR client_name = '' THEN '거래처 미지정' ELSE client_name END AS label,
                    SUM(amount_krw) AS amount_krw
             FROM tb_team_revenues
             WHERE target_year_month LIKE :prefix
             GROUP BY CASE WHEN client_name IS NULL OR client_name = '' THEN '거래처 미지정' ELSE client_name END
             HAVING SUM(amount_krw) > 0
             ORDER BY amount_krw DESC"
        );
        $stmt->execute(['prefix' => sprintf('%04d-', $year) . '%']);

        return $this->normalizeBreakdown($stmt, '거래처 미지정');
    }

    /**
     * 해당 연도 매출을 프로젝트(제목)별로 합산해 내림차순으로. KPI 팝오버용.
     *
     * @return list<array{label: string, amount_krw: int}>
     */
    private function breakdownRevenueProjects(int $year): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CASE WHEN project_name IS NULL OR project_name = '' THEN '프로젝트 미지정' ELSE project_name END AS label,
                    SUM(amount_krw) AS amount_krw
             FROM tb_team_revenues
             WHERE target_year_month LIKE :prefix
             GROUP BY CASE WHEN project_name IS NULL OR project_name = '' THEN '프로젝트 미지정' ELSE project_name END
             HAVING SUM(amount_krw) > 0
             ORDER BY amount_krw DESC"
        );
        $stmt->execute(['prefix' => sprintf('%04d-', $year) . '%']);

        return $this->normalizeBreakdown($stmt, '프로젝트 미지정');
    }

    /**
     * @return list<array{label: string, amount_krw: int}>
     */
    private function normalizeBreakdown(PDOStatement $stmt, string $fallback): array
    {
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = $fallback;
            }
            $out[] = [
                'label'      => $label,
                'amount_krw' => (int) ($row['amount_krw'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * 특정 연도의 매출·매입·손익 합계. 전년 대비(YoY) 비교용.
     *
     * @return array{revenue_krw: int, expense_krw: int, margin_krw: int}
     */
    private function yearAggregate(int $year): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(revenue_krw), 0) AS revenue_krw,
                    COALESCE(SUM(expense_krw), 0) AS expense_krw
             FROM vi_monthly_pnl
             WHERE ym LIKE :prefix'
        );
        $stmt->execute(['prefix' => sprintf('%04d-', $year) . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $rev = (int) ($row['revenue_krw'] ?? 0);
        $exp = (int) ($row['expense_krw'] ?? 0);

        return [
            'revenue_krw' => $rev,
            'expense_krw' => $exp,
            'margin_krw'  => $rev - $exp,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{ym: string, revenue_krw: int, expense_krw: int, margin_krw: int}
     */
    private function triple(string $ym, ?array $row): array
    {
        $rev = (int) ($row['revenue_krw'] ?? 0);
        $exp = (int) ($row['expense_krw'] ?? 0);

        return [
            'ym'          => $ym,
            'revenue_krw' => $rev,
            'expense_krw' => $exp,
            'margin_krw'  => $row === null ? 0 : (int) ($row['margin_krw'] ?? ($rev - $exp)),
        ];
    }

    /**
     * @return array{ym: string, revenue_krw: int, expense_krw: int, margin_krw: int}
     */
    private function monthRow(string $ym): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ym, revenue_krw, expense_krw, margin_krw
             FROM vi_monthly_pnl
             WHERE ym = :ym'
        );
        $stmt->execute(['ym' => $ym]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->triple($ym, is_array($row) ? $row : null);
    }

    /**
     * @param list<array{ym: string, revenue_krw: int, expense_krw: int, margin_krw: int}> $series
     * @return array{revenue_krw: int, expense_krw: int, margin_krw: int}
     */
    public static function quarterTotal(array $series, int $quarter): array
    {
        if ($quarter < 1 || $quarter > 4) {
            $quarter = 1;
        }
        $start = ($quarter - 1) * 3;
        $rev = 0;
        $exp = 0;
        for ($i = 0; $i < 3; $i++) {
            $row = $series[$start + $i] ?? [];
            $rev += (int) ($row['revenue_krw'] ?? 0);
            $exp += (int) ($row['expense_krw'] ?? 0);
        }

        return [
            'revenue_krw' => $rev,
            'expense_krw' => $exp,
            'margin_krw'  => $rev - $exp,
        ];
    }

    /**
     * 연초부터 누적 수입이 누적 지출을 처음 넘긴 달(1–12). 없으면 null.
     *
     * @param list<array{ym?: string, revenue_krw?: int, expense_krw?: int}> $series
     */
    public static function breakEvenMonth(array $series): ?int
    {
        $cum = 0;
        foreach ($series as $i => $row) {
            $cum += (int) ($row['revenue_krw'] ?? 0) - (int) ($row['expense_krw'] ?? 0);
            if ($cum <= 0) {
                continue;
            }
            $ym = (string) ($row['ym'] ?? '');
            if (preg_match('/^\d{4}-(\d{2})$/', $ym, $m) === 1) {
                return (int) $m[1];
            }

            return ((int) $i) + 1;
        }

        return null;
    }
}
