<?php
/**
 * API 사용량 조회 화면
 *
 * @date 2026-09-15
 */

$PAGE_TITLE = 'TechBizBoard · API 사용량';
$SHOW_NAV = true;
$NAV = 'api_usage';

/** 자동 조회 지원 공급자 (Admin API 보유) */
$autoProviders = [
    'openai'    => 'OpenAI',
    'anthropic' => 'Claude (Anthropic)',
];

/** 공급자별 자동 조회 조건 설명 (정적 안내) */
$providerNotes = [
    'openai'    => '조직 Admin 키(<code>OPENAI_ADMIN_KEY</code>)로 이번 달 사용량(지출)을 자동 조회합니다.',
    'anthropic' => 'Anthropic은 <strong>조직 관리자(Admin) 계정</strong>만 Admin API 키(<code>ANTHROPIC_ADMIN_KEY</code>)를 발급할 수 있습니다. 관리자 키가 없으면 자동 조회가 불가능하며, 아래에 “미설정”으로 표시됩니다.',
];

require __DIR__ . '/_header.php';
?>
<main class="app-main">
  <div class="app-content" data-api-usage data-api="api/api_usage">
    <div class="page-head">
      <h1 class="text-h1">API 사용량 조회</h1>
    </div>

    <section class="card" style="max-width:720px;">
      <p class="dash-chart-title" style="margin:0 0 10px;">조회 가능 범위 요약</p>
      <div style="overflow:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:14px;">
          <thead>
            <tr style="text-align:left; color:var(--ink-500);">
              <th style="padding:6px 8px; border-bottom:1px solid var(--ink-200);">공급자</th>
              <th style="padding:6px 8px; border-bottom:1px solid var(--ink-200);">사용량·지출</th>
              <th style="padding:6px 8px; border-bottom:1px solid var(--ink-200);">잔액·충전내역</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style="padding:8px;">OpenAI</td>
              <td style="padding:8px; color:#047857;">✅ 자동 조회 가능</td>
              <td style="padding:8px; color:var(--ink-500);">❌ API 없음(콘솔 확인)</td>
            </tr>
            <tr>
              <td style="padding:8px;">Claude (Anthropic)</td>
              <td style="padding:8px; color:var(--ink-600);">⚠️ 조직 <strong>관리자 계정</strong>만 가능</td>
              <td style="padding:8px; color:var(--ink-500);">❌ API 없음(콘솔 확인)</td>
            </tr>
            <tr>
              <td style="padding:8px;">Gemini (Google)</td>
              <td style="padding:8px; color:var(--ink-500);">❌ 공식 API 없음(수동·추후)</td>
              <td style="padding:8px; color:var(--ink-500);">❌ API 없음(콘솔 확인)</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-sm text-faint" style="margin:12px 0 0; line-height:1.6;">
        현재 <strong>자동 사용량 조회가 되는 곳은 OpenAI뿐</strong>입니다.
        Claude는 <strong>Anthropic 조직 관리자 계정</strong>으로 Admin 키를 발급해야 자동 조회가 가능하고,
        Gemini는 사용량·비용 조회용 공식 API 자체가 없습니다.
        <br>
        또한 <strong>세 공급자 모두 잔액·충전(크레딧) 내역을 조회하는 공식 API를 제공하지 않아</strong>,
        해당 정보는 각 공급자 콘솔에서만 확인할 수 있습니다.
      </p>
    </section>

    <?php foreach ($autoProviders as $key => $label): ?>
    <section class="card" style="max-width:720px; margin-top:12px;">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <p class="dash-chart-title" style="margin:0;"><?= h($label) ?></p>
        <button type="button" class="btn btn-outline btn-sm" data-refresh="<?= h($key) ?>">새로고침 (재조회)</button>
      </div>
      <?php if (isset($providerNotes[$key])): ?>
      <p class="text-sm" style="margin:6px 0 0; color:var(--ink-600); line-height:1.6;"><?= $providerNotes[$key] ?></p>
      <?php endif; ?>
      <p class="text-sm text-faint" style="margin:4px 0 0;">
        이번 달 누적 비용 · 한도 절약을 위해 저장된 스냅샷을 우선 표시하고, 새로고침 시에만 재조회합니다.
      </p>
      <div data-result="<?= h($key) ?>" style="margin-top:12px;"></div>
      <div style="margin-top:12px;">
        <p class="text-sm text-faint" style="margin:0 0 4px;">일자별 누적 비용</p>
        <div data-chart="<?= h($key) ?>" style="width:100%; height:280px;"></div>
      </div>
    </section>
    <?php endforeach; ?>

    <section class="card" style="max-width:720px; margin-top:12px;">
      <p class="dash-chart-title" style="margin:0 0 8px;">Gemini (Google)</p>
      <div style="padding:12px 14px; border-radius:8px; background:var(--ink-100); color:var(--ink-600); font-size:14px; line-height:1.6;">
        ⚠️ Google Gemini(AI Studio)는 사용량·비용을 조회하는 공식 API가 없습니다.
        비용은 <a href="https://aistudio.google.com/" target="_blank" rel="noopener" style="color:var(--brand-600);">AI Studio</a>
        또는 유료 프로젝트의 <strong>Google Cloud Billing</strong> 콘솔에서 확인해야 합니다.
        <br>
        <span class="text-faint">※ Gemini 사용량·비용은 <strong>수동 입력</strong> 방식으로 <strong>추후 별도 적용 예정</strong>입니다.</span>
      </div>
    </section>

    <section class="card" style="max-width:720px; margin-top:12px;">
      <p class="dash-chart-title" style="margin:0 0 8px;">충전 내역 · 현재 잔액</p>
      <div style="padding:12px 14px; border-radius:8px; background:var(--ink-100); color:var(--ink-600); font-size:14px; line-height:1.6;">
        ⚠️ 공급자들은 대체로 <strong>잔액·충전(크레딧) 내역</strong> 조회 API를 제공하지 않습니다
        (Admin API는 사용량·지출만 지원). 따라서 이 화면에서 잔액 자동 조회는 불가능합니다.
        <br>
        현재 잔액·충전 내역은 각 공급자 콘솔에서 확인하세요
        (예: OpenAI <a href="https://platform.openai.com/settings/organization/billing/credit-grants" target="_blank" rel="noopener" style="color:var(--brand-600);">Billing → Credit grants</a>).
        <br>
        <span class="text-faint">※ 충전 내역 수동 입력 → 잔액 자동 계산(총 충전 − 총 지출) 및 잔액 추이는 <strong>추후 별도 적용 예정</strong>입니다.</span>
      </div>
    </section>
  </div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
