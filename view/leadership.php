<?php
/**
 * 리더십 공유용 수익 요약 화면(상단 메뉴 없음)
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/leadership.php
 */

declare(strict_types=1);

$PAGE_TITLE = 'TechBizBoard · 테크본부 수익';
$SHOW_NAV = false;
$BRAND_LINK = (bool) ($BRAND_LINK ?? true);
$year = (string) ($year ?? '');
$years = is_array($years ?? null) ? $years : DashboardProvider::yearOptions();
$today = (string) ($today ?? tbb_today());
$rows = is_array($rows ?? null) ? $rows : [];

require __DIR__ . '/_header.php';
?>
<main class="app-main">
  <div class="app-content rev-page" data-leadership data-year="<?= h($year) ?>" data-today="<?= h($today) ?>">
    <div class="page-head">
      <h1 class="text-h1">테크본부 수익</h1>
      <p class="text-sm text-muted"><?= h($year) ?>년 유상 서비스에 대한 수익입니다. 솔루션은 판매 금액 전체를, 용역은 외주비·서버비 같은 원가를 뺀 금액을 수익으로 계산했습니다.</p>
    </div>
    <div data-panel="profit">
      <?php require __DIR__ . '/_profit_panel.php'; ?>
    </div>
  </div>
</main>
<script type="application/json" id="leadership-rows"><?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php require __DIR__ . '/_footer.php'; ?>
