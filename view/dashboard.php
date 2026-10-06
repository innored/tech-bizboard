<?php
/**
 * 손익 대시보드
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/dashboard.php
 */

declare(strict_types=1);

$PAGE_TITLE = 'TechBizBoard · 대시보드';
$SHOW_NAV = true;
$NAV = 'dash';
$year = (int) ($year ?? DashboardProvider::defaultYear());
$quarter = (int) ($quarter ?? DashboardProvider::defaultQuarter($year));
$years = is_array($years ?? null) ? $years : DashboardProvider::yearOptions();
$summary = is_array($summary ?? null) ? $summary : [
    'year_total'          => ['revenue_krw' => 0, 'expense_krw' => 0, 'margin_krw' => 0],
    'year_total_prev'     => ['revenue_krw' => 0, 'expense_krw' => 0, 'margin_krw' => 0],
    'previous_month'      => ['ym' => tbb_previous_month(), 'revenue_krw' => 0, 'expense_krw' => 0, 'margin_krw' => 0],
    'previous_month_prev' => ['revenue_krw' => 0, 'expense_krw' => 0, 'margin_krw' => 0],
    'break_even_month'    => null,
    'year_free_value'     => 0,
    'series'              => [],
];
$yearTotal = $summary['year_total'];
$yearPrev = $summary['year_total_prev'] ?? ['revenue_krw' => 0, 'expense_krw' => 0, 'margin_krw' => 0];
$prev = $summary['previous_month'];
$prevPrev = $summary['previous_month_prev'] ?? ['revenue_krw' => 0, 'expense_krw' => 0, 'margin_krw' => 0];
$series = $summary['series'] ?? [];
$breakEven = $summary['break_even_month'] ?? null;
$expenseBreakdown = is_array($summary['expense_breakdown'] ?? null) ? $summary['expense_breakdown'] : [];
$revenueBreakdown = is_array($summary['revenue_breakdown'] ?? null) ? $summary['revenue_breakdown'] : [];
$revenueProjectBreakdown = is_array($summary['revenue_project_breakdown'] ?? null) ? $summary['revenue_project_breakdown'] : [];
$yearFreeValue = (int) ($summary['year_free_value'] ?? 0);
$qTotal = DashboardProvider::quarterTotal($series, $quarter);
$qPrevTotal = $quarter > 1 ? DashboardProvider::quarterTotal($series, $quarter - 1) : null;

$fmtWon = static function (int $n): string {
    return number_format($n);
};

/** 억·만 단위 축약. 기간 카드 인라인 요약용. */
$fmtShort = static function (int $n): string {
    $abs = abs($n);
    $sign = $n < 0 ? '-' : '';
    if ($abs >= 100000000) {
        $eok = $abs / 100000000;
        $s = $eok >= 10 ? (string) round($eok) : (string) (round($eok * 10) / 10);

        return $sign . $s . '억';
    }
    if ($abs >= 10000) {
        return $sign . number_format((int) round($abs / 10000)) . '만';
    }

    return $sign . number_format($abs);
};

$fmtRate = static function (int $rev, int $part): string {
    if ($rev <= 0) {
        return '—';
    }

    return (string) ((int) round(($part / $rev) * 100)) . '%';
};

/** 손익 부호에 따른 강조 클래스. 매출·매입은 중립이라 색을 주지 않는다. */
$toneClass = static function (int $n): string {
    if ($n < 0) {
        return ' is-loss';
    }
    if ($n > 0) {
        return ' is-gain';
    }

    return '';
};

/**
 * 기준값 대비 증감(금액). 기준이 0이면 비교 불가로 '—'.
 *
 * @return array{text: string, tone: string}
 */
$fmtDelta = static function (int $cur, int $base) use ($fmtWon): array {
    if ($base === 0) {
        return ['text' => '—', 'tone' => ''];
    }
    $d = $cur - $base;
    if ($d > 0) {
        return ['text' => '▲ ' . $fmtWon($d), 'tone' => ' is-gain'];
    }
    if ($d < 0) {
        return ['text' => '▼ ' . $fmtWon(-$d), 'tone' => ' is-loss'];
    }

    return ['text' => '± 0', 'tone' => ''];
};

$prevYm = (string) ($prev['ym'] ?? '');
$prevMonthLabel = '전월';
if (preg_match('/^(\d{4})-(\d{2})$/', $prevYm, $mm) === 1) {
    $prevMonthLabel = $mm[1] . '년 ' . ((int) $mm[2]) . '월';
}

$yearExp = (int) $yearTotal['expense_krw'];
$yearRev = (int) $yearTotal['revenue_krw'];
$yearMar = (int) $yearTotal['margin_krw'];
$prevExp = (int) $prev['expense_krw'];
$prevRev = (int) $prev['revenue_krw'];
$prevMar = (int) $prev['margin_krw'];
$qExp = (int) $qTotal['expense_krw'];
$qRev = (int) $qTotal['revenue_krw'];
$qMar = (int) $qTotal['margin_krw'];

$yearYoy = $fmtDelta($yearMar, (int) ($yearPrev['margin_krw'] ?? 0));
$prevMom = $fmtDelta($prevMar, (int) ($prevPrev['margin_krw'] ?? 0));
$qQoq = $qPrevTotal === null
    ? ['text' => '—', 'tone' => '']
    : $fmtDelta($qMar, (int) ($qPrevTotal['margin_krw'] ?? 0));

$beText = $breakEven === null ? '올해 손익분기 미달' : ((int) $breakEven) . '월 손익분기 달성';
$beState = $breakEven === null ? ' is-pending' : ' is-reached';

require __DIR__ . '/_header.php';
?>
<main class="app-main">
  <div
    class="app-content dash-page"
    data-dashboard
    data-api="api/dashboard"
    data-year="<?= h((string) $year) ?>"
    data-quarter="<?= h((string) $quarter) ?>"
    data-today="<?= h((string) ($today ?? '')) ?>"
  >
    <div class="dash-head">
      <div class="page-head">
        <h1 class="text-h1">대시보드</h1>
      </div>
      <div class="dash-toolbar">
        <div class="field">
          <label class="label sr-only" for="dash-year-trigger">연도</label>
          <div class="select" data-select data-dash-year>
            <button type="button" class="select-trigger" id="dash-year-trigger" aria-haspopup="listbox" aria-expanded="false">
              <span class="select-value"><?= h((string) $year) ?>년</span>
              <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <input type="hidden" id="dash-year" value="<?= h((string) $year) ?>" />
            <ul class="select-menu" role="listbox">
              <?php foreach ($years as $y): ?>
                <?php $y = (int) $y; ?>
                <li class="select-option<?= $y === $year ? ' is-selected' : '' ?>" data-value="<?= h((string) $y) ?>"><?= h((string) $y) ?>년</li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <div class="segmented" role="tablist" aria-label="분기" data-dash-quarter>
          <?php for ($q = 1; $q <= 4; $q++): ?>
            <button type="button" data-quarter="<?= $q ?>" class="<?= $q === $quarter ? 'is-active' : '' ?>"><?= $q ?>분기</button>
          <?php endfor; ?>
        </div>
      </div>
    </div>

    <section class="dash-hero" aria-label="올해 팀 손익">
      <div class="dash-hero-top">
        <div class="dash-hero-lead">
          <p class="dash-hero-kicker"><span id="dash-year-label"><?= h((string) $year) ?>년</span> · 올해 누적 손익<button type="button" class="dash-kpi-info dash-hero-info tooltip tooltip-bottom" data-tooltip="손익 = 매출 − 매입 (연초부터 누적)" aria-label="올해 누적 손익 계산 방식"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></button></p>
          <p class="dash-hero-num<?= $toneClass($yearMar) ?>" data-dash="year-margin"><?= h($fmtWon($yearMar)) ?><span class="unit">원</span></p>
          <p class="dash-be-chip<?= $beState ?>" data-dash="year-be"><span class="dash-be-dot" aria-hidden="true"></span><?= h($beText) ?></p>
        </div>
        <div class="dash-hero-side">
          <p class="dash-rate-label">손익률</p>
          <p class="dash-rate<?= $toneClass($yearMar) ?>" data-dash="year-rate"><?= h($fmtRate($yearRev, $yearMar)) ?></p>
          <p class="dash-delta<?= $yearYoy['tone'] ?>" data-dash="year-yoy">
            <span class="dash-delta-label">전년 대비</span>
            <span class="dash-delta-val"><?= h($yearYoy['text']) ?></span>
          </p>
        </div>
      </div>
      <div class="dash-kpi">
        <div class="dash-kpi-cell has-pop">
          <p class="dash-kpi-label is-earn">매출<button type="button" class="dash-kpi-info" aria-label="매출에 포함된 항목 보기"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></button></p>
          <p class="dash-kpi-num" data-dash="year-rev"><?= h($fmtWon($yearRev)) ?><span class="unit">원</span></p>
          <div class="dash-kpi-pop" role="tooltip">
            <p class="dash-kpi-pop-head">프로젝트별 매출</p>
            <ul class="dash-kpi-pop-list" data-kpi-list="rev"></ul>
          </div>
        </div>
        <div class="dash-kpi-cell has-pop">
          <p class="dash-kpi-label is-spend">매입<button type="button" class="dash-kpi-info" aria-label="매입에 포함된 항목 보기"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></button></p>
          <p class="dash-kpi-num" data-dash="year-exp"><?= h($fmtWon($yearExp)) ?><span class="unit">원</span></p>
          <div class="dash-kpi-pop" role="tooltip">
            <p class="dash-kpi-pop-head">기안서별 매입</p>
            <ul class="dash-kpi-pop-list" data-kpi-list="exp"></ul>
          </div>
        </div>
        <div class="dash-kpi-cell is-inline"<?= $yearFreeValue > 0 ? '' : ' hidden' ?>>
          <p class="dash-kpi-label">무상 제공(연)<button type="button" class="dash-kpi-info tooltip tooltip-bottom" data-tooltip="billing_type=FREE인 수입의 정상가(list_value_krw) 합계" aria-label="무상 제공 총액 설명"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></button></p>
          <p class="dash-kpi-num" data-dash="year-free-value"><?= h($fmtWon($yearFreeValue)) ?><span class="unit">원</span></p>
        </div>
      </div>
    </section>

    <div class="dash-periods">
      <article class="dash-period" aria-label="전월 손익">
        <header class="dash-period-head">
          <span>전월</span>
          <strong id="dash-prev-label"><?= h($prevMonthLabel) ?></strong>
        </header>
        <div class="dash-period-stat">
          <p class="dash-period-remain<?= $toneClass($prevMar) ?>" data-dash="prev-margin"><?= h($fmtWon($prevMar)) ?><span class="unit">원</span></p>
          <div class="dash-rate-block">
            <p class="dash-rate-label">손익률</p>
            <p class="dash-rate is-compact<?= $toneClass($prevMar) ?>" data-dash="prev-rate"><?= h($fmtRate($prevRev, $prevMar)) ?></p>
          </div>
        </div>
        <p class="dash-delta is-inline<?= $prevMom['tone'] ?>" data-dash="prev-mom">
          <span class="dash-delta-label">전월 대비</span>
          <span class="dash-delta-val"><?= h($prevMom['text']) ?></span>
        </p>
        <p class="dash-period-foot">
          <span class="is-earn">매출</span> <span data-dash="prev-rev-short"><?= h($fmtShort($prevRev)) ?></span>
          <span class="dash-dot">·</span>
          <span class="is-spend">매입</span> <span data-dash="prev-exp-short"><?= h($fmtShort($prevExp)) ?></span>
        </p>
      </article>
      <article class="dash-period" aria-label="선택한 분기 손익">
        <header class="dash-period-head">
          <span>선택한 분기</span>
          <strong id="dash-q-label"><?= h((string) $year) ?>년 <?= $quarter ?>분기</strong>
        </header>
        <div class="dash-period-stat">
          <p class="dash-period-remain<?= $toneClass($qMar) ?>" data-dash="q-margin"><?= h($fmtWon($qMar)) ?><span class="unit">원</span></p>
          <div class="dash-rate-block">
            <p class="dash-rate-label">손익률</p>
            <p class="dash-rate is-compact<?= $toneClass($qMar) ?>" data-dash="q-rate"><?= h($fmtRate($qRev, $qMar)) ?></p>
          </div>
        </div>
        <p class="dash-delta is-inline<?= $qQoq['tone'] ?>" data-dash="q-qoq">
          <span class="dash-delta-label">전분기 대비</span>
          <span class="dash-delta-val"><?= h($qQoq['text']) ?></span>
        </p>
        <p class="dash-period-foot">
          <span class="is-earn">매출</span> <span data-dash="q-rev-short"><?= h($fmtShort($qRev)) ?></span>
          <span class="dash-dot">·</span>
          <span class="is-spend">매입</span> <span data-dash="q-exp-short"><?= h($fmtShort($qExp)) ?></span>
        </p>
      </article>
    </div>

    <section class="card dash-chart-card">
      <div class="dash-chart-head">
        <div>
          <p class="dash-chart-title">월별 그래프</p>
        </div>
        <span class="text-sm text-faint" id="dash-chart-hint"><?= h((string) $year) ?>년</span>
      </div>
      <div id="dash-chart" class="dash-chart" role="img" aria-label="연간 월별 매출·매입 막대, 월 손익 점선, 누적손익 실선. 선택한 분기는 배경으로 표시합니다."></div>
    </section>

    <section class="dash-splits" aria-label="연간 비중">
      <div class="card dash-split-card">
        <div class="dash-chart-head">
          <p class="dash-chart-title">매출 비중</p>
          <span class="text-sm text-faint">거래처별 · <span id="dash-rev-year"><?= h((string) $year) ?></span>년</span>
        </div>
        <div id="dash-rev-donut" class="dash-donut" role="img" aria-label="거래처별 매출 비중"></div>
      </div>
      <div class="card dash-split-card">
        <div class="dash-chart-head">
          <p class="dash-chart-title">매입 비중</p>
          <span class="text-sm text-faint">기안서별 · <span id="dash-exp-year"><?= h((string) $year) ?></span>년</span>
        </div>
        <div id="dash-exp-donut" class="dash-donut" role="img" aria-label="기안서별 매입 비중"></div>
      </div>
    </section>

    <script type="application/json" id="dash-bootstrap"><?= json_encode([
        'year'                => $year,
        'year_total'          => $yearTotal,
        'year_total_prev'     => $yearPrev,
        'previous_month'      => $prev,
        'previous_month_prev' => $prevPrev,
        'break_even_month'    => $breakEven,
        'expense_breakdown'   => $expenseBreakdown,
        'revenue_breakdown'   => $revenueBreakdown,
        'revenue_project_breakdown' => $revenueProjectBreakdown,
        'year_free_value'     => $yearFreeValue,
        'series'              => $series,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
