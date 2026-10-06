<?php
/**
 * 수입 화면
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/revenues.php
 */

declare(strict_types=1);

$PAGE_TITLE = 'TechBizBoard · 매출(수입)';
$SHOW_NAV = true;
$NAV = 'revenues';
$year = (string) ($year ?? '');
$month = (string) ($month ?? '');
$years = is_array($years ?? null) ? $years : DashboardProvider::yearOptions();
$today = (string) ($today ?? tbb_today());
$isYearView = $month === '';

require __DIR__ . '/_header.php';
?>
<main class="app-main">
  <div
    class="app-content rev-page"
    data-revenues
    data-api="api/revenues"
    data-tpl-api="api/revenue_templates"
    data-year="<?= h($year) ?>"
    data-month="<?= h($month) ?>"
    data-today="<?= h($today) ?>"
    data-editable-from="<?= tbb_is_admin() ? '' : h(tbb_previous_month()) ?>"
  >
    <div class="page-head">
      <h1 class="text-h1">매출(수입)</h1>
      <p class="text-sm text-muted">테크본부의 수입 내역을 관리합니다.<?php if (!tbb_is_admin()): ?> 오늘 기준 전전월 데이터는 마감처리되어 수정이 불가능합니다.<?php endif; ?></p>
    </div>

    <div class="tabs" data-tabs>
      <div class="tab-list" role="tablist" aria-label="수입">
        <button class="tab is-active" type="button" data-tab="list">목록</button>
        <button class="tab" type="button" data-tab="tpl">반복 설정</button>
      </div>
      <div class="tab-panels">
        <div class="tab-panel is-active" data-panel="list" role="tabpanel">
          <div class="rev-toolbar">
            <div class="field rev-year-field">
              <label class="label sr-only" for="rev-year-trigger">연도</label>
              <div class="select" data-select data-rev-year>
                <button type="button" class="select-trigger" id="rev-year-trigger" aria-haspopup="listbox" aria-expanded="false">
                  <span class="select-value"><?= h($year) ?>년</span>
                  <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <input type="hidden" id="rev-year" value="<?= h($year) ?>" />
                <ul class="select-menu" role="listbox">
                  <?php foreach ($years as $y): ?>
                    <?php $y = (int) $y; ?>
                    <li class="select-option<?= (string) $y === $year ? ' is-selected' : '' ?>" data-value="<?= h((string) $y) ?>"><?= h((string) $y) ?>년</li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
            <button
              class="btn btn-sm<?= $isYearView ? ' btn-secondary' : ' btn-outline' ?>"
              type="button"
              data-action="year-view"
              id="rev-year-view"
              aria-pressed="<?= $isYearView ? 'true' : 'false' ?>"
            >연간 보기</button>
          </div>
          <div class="rev-year-kpis" aria-label="연간 요약">
            <article class="rev-year-kpi">
              <span class="rev-year-kpi-label">공급가</span>
              <strong class="rev-year-kpi-amt"><span id="rev-year-supply">0</span><span class="unit">원</span></strong>
            </article>
            <article class="rev-year-kpi">
              <span class="rev-year-kpi-label">부가세</span>
              <strong class="rev-year-kpi-amt"><span id="rev-year-vat">0</span><span class="unit">원</span></strong>
            </article>
            <article class="rev-year-kpi is-total">
              <span class="rev-year-kpi-label">합계</span>
              <strong class="rev-year-kpi-amt"><span id="rev-year-total">0</span><span class="unit">원</span></strong>
              <span class="rev-year-kpi-n" id="rev-year-count">총 0건</span>
            </article>
            <article class="rev-year-kpi">
              <span class="rev-year-kpi-label">무상 제공</span>
              <strong class="rev-year-kpi-amt"><span id="rev-year-free">0</span><span class="unit">원</span></strong>
            </article>
          </div>
          <div class="rev-year-index" id="rev-year-index" aria-label="월별 수입"></div>
          <div class="card rev-card rev-year-wrap" id="rev-year-wrap"<?= $isYearView ? '' : ' hidden' ?>>
            <div class="rev-year-groups" id="rev-year-groups"></div>
          </div>
          <div class="card rev-card" id="rev-month-card"<?= $isYearView ? ' hidden' : '' ?>>
            <div class="rev-card-head">
              <p class="eyebrow">수입 목록</p>
              <span class="rev-card-lock" id="rev-month-lock" hidden title="잠긴 달" aria-label="잠긴 달">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
              </span>
              <div class="rev-card-meta">
                <span class="text-sm text-faint" id="rev-sum-free-wrap">무상 <strong id="rev-sum-free">0</strong>원</span>
                <span class="text-sm text-faint" id="rev-list-count">총 0건</span>
              </div>
            </div>
          <p class="hint" id="rev-lock-hint" hidden>전전월 이전은 조회만 가능합니다. 당월과 전월만 수정할 수 있습니다.</p>
          <div class="rev-table-above">
            <button
              class="btn btn-primary btn-sm"
              type="button"
              data-action="add-row"
              id="rev-add-row"
              <?= $isYearView ? 'disabled' : '' ?>
            >+ 추가</button>
          </div>
          <div class="table-wrap rev-table-wrap">
            <table class="table" id="rev-list-table">
              <thead>
                <tr>
                  <th class="col-date">입금일</th>
                  <th class="col-project">프로젝트</th>
                  <th class="col-client">거래처</th>
                  <th class="col-svc">서비스구분</th>
                  <th class="col-billing">유상/무상</th>
                  <th class="num col-money">공급가</th>
                  <th class="num col-money">부가세</th>
                  <th class="num col-money">합계</th>
                  <th class="col-assignee">담당</th>
                  <th class="col-author">작성자</th>
                  <th class="col-kind">구분</th>
                  <th class="col-note">메모</th>
                  <th class="col-receipts">증빙</th>
                  <th class="col-actions">액션</th>
                </tr>
              </thead>
              <tbody id="rev-list-body"></tbody>
              <tfoot>
                <tr>
                  <td class="col-date"></td>
                  <td class="col-project rev-total-label">해당 월 총계</td>
                  <td class="col-client"></td>
                  <td class="col-svc"></td>
                  <td class="col-billing"></td>
                  <td class="num col-money" id="rev-sum-supply">0</td>
                  <td class="num col-money" id="rev-sum-vat">0</td>
                  <td class="num col-money" id="rev-sum-amount">0</td>
                  <td class="col-assignee"></td>
                  <td class="col-author"></td>
                  <td class="col-kind"></td>
                  <td class="col-note"></td>
                  <td class="col-receipts"></td>
                  <td class="col-actions"></td>
                </tr>
              </tfoot>
            </table>
          </div>
          </div>
        </div>
        <div class="tab-panel" data-panel="tpl" role="tabpanel">
          <div class="rev-toolbar">
            <p class="hint rev-save-hint">각 줄의 저장을 누르면 반영됩니다. 기간은 시작월만 있어도 되고, 종료월을 비우면 계속 반복합니다.</p>
            <button class="btn btn-primary btn-sm" type="button" data-action="add-tpl">+ 추가</button>
          </div>
          <div class="card rev-card">
            <div class="rev-card-head">
              <p class="eyebrow">반복 설정</p>
              <div class="rev-card-meta">
                <p class="rev-month-total">기본 공급가 총계 <strong id="rev-tpl-total">0</strong><span class="unit">원</span></p>
                <span class="text-sm text-faint" id="rev-tpl-count">총 0건</span>
              </div>
            </div>
          <div class="table-wrap rev-table-wrap">
            <table class="table" id="rev-tpl-table">
              <thead>
                <tr>
                  <th class="col-project">프로젝트</th>
                  <th class="col-client">거래처</th>
                  <th class="num col-money">기본 공급가</th>
                  <th class="col-assignee">담당</th>
                  <th class="col-author">작성자</th>
                  <th class="col-period">기간</th>
                  <th class="col-active">사용</th>
                  <th class="col-note">메모</th>
                  <th class="col-actions">액션</th>
                </tr>
              </thead>
              <tbody id="rev-tpl-body"></tbody>
              <tfoot>
                <tr>
                  <td class="col-project rev-total-label">총계</td>
                  <td class="col-client"></td>
                  <td class="num col-money" id="rev-tpl-sum-supply">0</td>
                  <td class="col-assignee"></td>
                  <td class="col-author"></td>
                  <td class="col-period"></td>
                  <td class="col-active"></td>
                  <td class="col-note"></td>
                  <td class="col-actions"></td>
                </tr>
              </tfoot>
            </table>
          </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- 수입 등록/편집 모달 -->
<div class="rev-modal-backdrop" id="rev-modal" hidden aria-modal="true" role="dialog" aria-labelledby="rev-m-title">
  <div class="rev-modal-box">
    <div class="rev-modal-head">
      <h2 class="rev-modal-title" id="rev-m-title">수입 등록</h2>
      <button class="rev-modal-close btn btn-ghost btn-icon" type="button" data-action="close-rev-modal" aria-label="닫기">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="rev-modal-body">
      <div class="rev-modal-grid">
        <div class="field">
          <label class="label" for="rev-m-received_date">입금일 <span class="req">*</span></label>
          <div class="input-wrap">
            <svg class="icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            <input class="input has-icon-left" type="text" id="rev-m-received_date" autocomplete="off" placeholder="YYYY-MM-DD" readonly />
          </div>
        </div>
        <div class="field">
          <label class="label" for="rev-m-project_name">프로젝트 <span class="req">*</span></label>
          <input class="input" type="text" id="rev-m-project_name" placeholder="프로젝트명" />
        </div>
        <div class="field">
          <label class="label" for="rev-m-client_name">거래처</label>
          <input class="input" type="text" id="rev-m-client_name" placeholder="거래처명" />
        </div>
        <div class="field">
          <label class="label" for="rev-m-service_category-trigger">서비스구분</label>
          <div class="select" data-select id="rev-m-service_category-wrap">
            <button type="button" class="select-trigger" id="rev-m-service_category-trigger" aria-haspopup="listbox" aria-expanded="false">
              <span class="select-value">선택</span>
              <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <input type="hidden" id="rev-m-service_category" value="" />
            <ul class="select-menu" role="listbox">
              <li class="select-option" data-value="">선택</li>
              <li class="select-option" data-value="솔루션 서비스">솔루션 서비스</li>
              <li class="select-option" data-value="컨설팅">컨설팅</li>
              <li class="select-option" data-value="기타">기타</li>
            </ul>
          </div>
        </div>
        <div class="field">
          <label class="label" for="rev-m-billing_type-trigger">유상/무상</label>
          <div class="select" data-select id="rev-m-billing_type-wrap">
            <button type="button" class="select-trigger" id="rev-m-billing_type-trigger" aria-haspopup="listbox" aria-expanded="false">
              <span class="select-value">유상</span>
              <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <input type="hidden" id="rev-m-billing_type" value="PAID" />
            <ul class="select-menu" role="listbox">
              <li class="select-option is-selected" data-value="PAID">유상</li>
              <li class="select-option" data-value="FREE">무상</li>
            </ul>
          </div>
        </div>
        <div class="field" id="rev-m-supply-field">
          <label class="label" for="rev-m-supply_krw">공급가 <span class="req">*</span></label>
          <input class="input num" type="text" id="rev-m-supply_krw" inputmode="numeric" placeholder="0" />
        </div>
        <div class="field" id="rev-m-list-field" hidden>
          <label class="label" for="rev-m-list_value_krw">정상가</label>
          <input class="input num" type="text" id="rev-m-list_value_krw" inputmode="numeric" placeholder="0" />
        </div>
        <div class="field">
          <label class="label" for="rev-m-assignee">담당 <span class="req">*</span></label>
          <input class="input" type="text" id="rev-m-assignee" placeholder="담당자" />
        </div>
        <div class="field">
          <label class="label" for="rev-m-status-trigger">상태</label>
          <div class="select" data-select id="rev-m-status-wrap">
            <button type="button" class="select-trigger" id="rev-m-status-trigger" aria-haspopup="listbox" aria-expanded="false">
              <span class="select-value">완료</span>
              <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <input type="hidden" id="rev-m-status" value="COMPLETED" />
            <ul class="select-menu" role="listbox">
              <li class="select-option is-selected" data-value="COMPLETED">완료</li>
              <li class="select-option" data-value="PENDING">미확인</li>
            </ul>
          </div>
        </div>
        <div class="field rev-modal-note">
          <label class="label" for="rev-m-note">메모</label>
          <input class="input" type="text" id="rev-m-note" placeholder="메모" />
        </div>
      </div>
      <!-- 증빙 섹션: 나중 태스크에서 구현 -->
      <div class="rev-modal-receipts" id="rev-m-receipts">
        <!-- placeholder: 증빙 첨부(드롭존·목록)는 다음 태스크에서 구현 -->
      </div>
    </div>
    <div class="rev-modal-foot">
      <button class="btn btn-outline" type="button" data-action="close-rev-modal">취소</button>
      <button class="btn btn-primary" type="button" id="rev-m-save">저장</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
