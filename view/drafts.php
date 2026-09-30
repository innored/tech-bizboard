<?php
/**
 * 기안 작성 화면
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/drafts.php
 */

declare(strict_types=1);

$PAGE_TITLE = 'TechBizBoard · 매입(지출)';
$SHOW_NAV = true;
$NAV = 'drafts';
$month = $month ?? '';
$monthInput = $monthInput ?? $month;
$templates = is_array($templates ?? null) ? $templates : [];
$rows = is_array($rows ?? null) ? $rows : [];
$selectedId = (int) ($selectedId ?? 0);
$draftsByTemplate = is_array($draftsByTemplate ?? null) ? $draftsByTemplate : [];
$monthLabel = (string) ($monthLabel ?? $month);
$monthSummary = is_array($monthSummary ?? null) ? $monthSummary : [
    'total' => 0, 'idle' => 0, 'writing' => 0, 'done' => 0, 'amount_krw' => 0,
];

function tbb_write_badge(?array $draft): string
{
    $status = (string) ($draft['status'] ?? '');
    if ($status === 'DONE') {
        return '<span class="badge badge-success">완료</span>';
    }
    if ($status !== '') {
        return '<span class="badge badge-brand">작성중</span>';
    }

    return '<span class="badge badge-muted">미작성</span>';
}

/** 미작성=idle, 작성중=writing, 완료=done. rail 좌측 점 색을 정한다. */
function tbb_status_key(?array $draft): string
{
    $status = (string) ($draft['status'] ?? '');
    if ($status === 'DONE') {
        return 'done';
    }

    return $status !== '' ? 'writing' : 'idle';
}

function tbb_month_meter_label(array $summary): string
{
    return '미작성 ' . (int) ($summary['idle'] ?? 0)
        . ', 작성중 ' . (int) ($summary['writing'] ?? 0)
        . ', 완료 ' . (int) ($summary['done'] ?? 0);
}

/** 작성 칸 설명. 마우스를 올리면 한 줄 안내가 뜬다. */
function tbb_help_tip(string $text, string $place = 'bottom', string $id = ''): string
{
    $placeClass = $place === 'top' ? '' : ' tooltip-' . $place;
    $idAttr = $id !== '' ? ' id="' . h($id) . '"' : '';

    return '<span class="tooltip' . $placeClass . ' draft-tip" tabindex="0"' . $idAttr
        . ' data-tooltip="' . h($text) . '" aria-label="' . h($text) . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<circle cx="12" cy="12" r="10"/>'
        . '<path d="M12 16v-4M12 8h.01"/>'
        . '</svg></span>';
}

require __DIR__ . '/_header.php';
?>
<main class="app-main">
  <div
    class="app-content"
    data-drafts
    data-api="api/drafts"
    data-month="<?= h((string) $month) ?>"
    data-selected="<?= h((string) $selectedId) ?>"
    data-editable-from="<?= tbb_is_admin() ? '' : h(tbb_previous_month()) ?>"
    data-user-name="<?= h((string) ($SSO_USER['name'] ?? '')) ?>"
  >
    <div class="page-head">
      <h1 class="text-h1">매입(지출)</h1>
      <p class="text-sm text-muted">템플릿을 골라 작성한 뒤 그룹웨어에 복사하세요.<?php if (!tbb_is_admin()): ?> 전전월 이전은 마감되어 수정할 수 없습니다.<?php endif; ?></p>
      <p class="hint" id="draft-lock-hint" hidden>마감된 달입니다. 조회만 가능하며 수정·작성은 당월과 전월에서만 됩니다.</p>
    </div>

    <div class="draft-toolbar" data-month-summary>
      <form class="draft-toolbar-month" method="get" action="drafts">
        <label class="sr-only" for="draft-month">기안작성 대상 월</label>
        <div class="input-wrap">
          <svg class="icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
          <input class="input has-icon-left" type="text" id="draft-month" name="month" value="<?= h($monthInput) ?>" data-datepicker-month autocomplete="off" placeholder="연-월 선택" readonly />
        </div>
        <?php if ($selectedId > 0): ?>
          <input type="hidden" name="id" value="<?= h((string) $selectedId) ?>" />
        <?php endif; ?>
        <button class="btn btn-outline" type="submit">조회</button>
      </form>
      <div class="draft-toolbar-status">
        <div
          class="draft-progress"
          role="img"
          data-month-meter
          aria-label="<?= h(tbb_month_meter_label($monthSummary)) ?>"
        >
          <div class="draft-meter">
            <span class="draft-meter-seg is-idle" data-meter="idle" style="flex-grow: <?= (int) $monthSummary['idle'] ?>"></span>
            <span class="draft-meter-seg is-writing" data-meter="writing" style="flex-grow: <?= (int) $monthSummary['writing'] ?>"></span>
            <span class="draft-meter-seg is-done" data-meter="done" style="flex-grow: <?= (int) $monthSummary['done'] ?>"></span>
          </div>
          <ul class="draft-tally">
            <li class="is-idle"><span>미작성</span> <b data-count-idle><?= (int) $monthSummary['idle'] ?></b></li>
            <li class="is-writing"><span>작성중</span> <b data-count-writing><?= (int) $monthSummary['writing'] ?></b></li>
            <li class="is-done"><span>완료</span> <b data-count-done><?= (int) $monthSummary['done'] ?></b></li>
          </ul>
        </div>
        <div class="draft-head-sum">
          <span class="draft-head-sum-label">완료 합계</span>
          <strong><span data-sum-krw><?= h(number_format((int) $monthSummary['amount_krw'])) ?></span><span class="unit">원</span></strong>
        </div>
      </div>
    </div>

    <div class="tpl-layout">
      <aside class="tpl-rail" aria-label="작성할 템플릿">
        <div class="tpl-rail-head">
          <span class="tpl-rail-title">템플릿</span>
        </div>
        <label class="tpl-search">
          <span class="sr-only">템플릿 검색</span>
          <input class="input" type="search" id="draft-filter" placeholder="건명·담당 검색" autocomplete="off" />
        </label>
        <div class="tpl-list" role="listbox" aria-label="템플릿">
          <?php if ($templates === []): ?>
            <p class="tpl-empty text-sm text-muted">사용 중인 템플릿이 없습니다.<?php if (tbb_is_admin()): ?> <a href="settings">설정</a>에서 만드세요.<?php endif; ?></p>
          <?php endif; ?>
          <p class="tpl-empty text-sm text-muted" id="draft-filter-empty" hidden>검색 결과가 없습니다.</p>
          <?php foreach ($templates as $tpl): ?>
            <?php
            $id = (int) ($tpl['id'] ?? 0);
            $draft = $draftsByTemplate[$id] ?? null;
            $pay = tbb_pay_label((string) ($tpl['payment_type'] ?? ''));
            $statusKey = tbb_status_key(is_array($draft) ? $draft : null);
            ?>
            <button
              class="tpl-item<?= $id === $selectedId ? ' is-selected' : '' ?>"
              type="button"
              role="option"
              data-action="pick"
              data-id="<?= h((string) $id) ?>"
              data-status="<?= h($statusKey) ?>"
              data-search="<?= h(mb_strtolower((string) ($tpl['title'] ?? '') . ' ' . (string) ($tpl['assignee'] ?? ''), 'UTF-8')) ?>"
              aria-selected="<?= $id === $selectedId ? 'true' : 'false' ?>"
            >
              <span class="tpl-item-name"><?= h((string) ($tpl['title'] ?? '')) ?></span>
              <span class="tpl-item-meta">
                <?= h((string) ($tpl['assignee'] ?? '')) ?>
                · <?= h(tbb_cycle_label((string) ($tpl['cycle_type'] ?? ''))) ?>
                <?php if ($pay !== ''): ?> · <?= h($pay) ?><?php endif; ?>
              </span>
              <?= tbb_write_badge(is_array($draft) ? $draft : null) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </aside>

      <section class="tpl-editor card" id="draft-pane" aria-live="polite">
        <div class="tpl-idle" id="draft-idle"<?= $selectedId > 0 ? ' hidden' : '' ?>>
          <p class="text-h2">템플릿을 선택하세요</p>
          <p class="text-sm text-muted">왼쪽에서 고른 뒤, 결제 항목을 입력하고 임시저장합니다.</p>
        </div>

        <div id="draft-compose" class="draft-compose"<?= $selectedId > 0 ? '' : ' hidden' ?>>
          <div class="draft-compose-head">
            <div class="draft-compose-head-copy">
              <h2 class="text-h2" id="draft-tpl-title"></h2>
              <p class="text-sm text-muted" id="draft-tpl-meta"></p>
            </div>
          </div>

          <div class="draft-items">
            <div class="draft-items-head">
              <div class="draft-items-head-title">
                <span class="draft-step" aria-hidden="true">1</span>
                <p class="tpl-sheet-label" id="draft-items-label">결제 항목</p>
                <?= tbb_help_tip('이달 결제 항목을 적습니다. 지난달 불러오기로 시작할 수 있습니다.', 'bottom', 'draft-items-tip') ?>
              </div>
              <div class="draft-items-actions">
                <button class="btn btn-outline btn-sm tooltip tooltip-bottom" type="button" data-action="collapse-items" data-tooltip="결제 항목을 접거나 펼칩니다.">모두 접기</button>
                <a class="btn btn-outline btn-sm tooltip tooltip-bottom" id="draft-download-all" hidden href="#" data-tooltip="첨부 파일을 한꺼번에 받습니다.">전체 파일 다운로드</a>
                <button class="btn btn-outline btn-sm tooltip tooltip-bottom" type="button" data-action="load-previous" data-tooltip="지난달 항목을 가져와 날짜만 이번 달로 바꿉니다.">지난달 불러오기</button>
                <button class="btn btn-outline btn-sm tooltip tooltip-bottom" type="button" data-action="refresh-rates" data-tooltip="결제일(없으면 오늘) 매매기준율로 다시 계산합니다.">환율 조회</button>
                <button class="btn btn-outline btn-sm tooltip tooltip-bottom" type="button" data-action="clear-items" data-tooltip="입력한 결제 항목을 모두 지웁니다.">초기화</button>
                <button class="btn btn-secondary btn-sm tooltip tooltip-bottom" type="button" data-action="add-item" data-tooltip="입력 항목을 추가합니다.">+ 항목 추가</button>
              </div>
            </div>
            <div class="draft-receipt-upload" id="draft-receipt-area" hidden>
              <div class="draft-receipt-dropzone" data-receipt-dropzone>
                <input type="file" multiple accept=".pdf,image/*" data-receipt-upload id="draft-receipt-input" class="sr-only" />
                <label for="draft-receipt-input" class="draft-receipt-label">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  <span>첨부파일 업로드 (PDF · 이미지)</span>
                  <span class="draft-receipt-hint">클릭하거나 파일을 여기에 끌어다 놓으세요. 여러 파일 동시 가능.</span>
                </label>
              </div>
              <div class="draft-receipt-review" data-receipt-review></div>
            </div>
            <div class="draft-receipt-files" id="draft-receipt-files" data-receipt-files hidden></div>
            <div class="draft-item-list" id="draft-item-rows"></div>
          </div>

          <div class="tpl-sheet">
            <div class="tpl-sheet-head">
              <p class="tpl-sheet-label draft-step-label"><span class="draft-step" aria-hidden="true">2</span>그룹웨어 붙여넣기<?= tbb_help_tip('오른쪽 복사 버튼으로 그룹웨어에 붙입니다.') ?></p>
              <div class="draft-compose-actions">
                <button class="btn btn-secondary btn-sm tooltip tooltip-bottom" type="button" data-copy="title" data-tooltip="그룹웨어 제목 칸에 붙입니다.">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  제목 복사
                </button>
                <button class="btn btn-secondary btn-sm tooltip tooltip-bottom" type="button" data-copy="body" data-tooltip="그룹웨어 본문에 붙입니다.">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  내용 복사
                </button>
              </div>
            </div>
            <div class="field">
              <label class="label" for="draft-title">제목<?= tbb_help_tip('그룹웨어 문서 제목입니다. 팀비 외에는 [기안서]가 붙습니다.') ?></label>
              <input class="input" type="text" id="draft-title" tabindex="0" />
            </div>
            <div class="field">
              <div class="draft-body-head">
                <label class="label" for="draft-body"><span id="draft-body-label">내용</span><?= tbb_help_tip('그룹웨어 본문입니다. 복사해 내용 칸에 붙입니다.', 'bottom', 'draft-body-tip') ?></label>
                <p class="text-sm text-muted draft-body-hint" id="draft-body-hint" hidden>그룹웨어 표의 첫 날짜 칸을 찍고 붙여 넣습니다.</p>
              </div>
              <div class="draft-team-editable" id="draft-team-editable" hidden>
                <div class="draft-team-preview-scroll">
                  <table class="draft-team-table">
                    <thead>
                      <tr>
                        <th>날짜<?= tbb_help_tip('지출한 날입니다. 그룹웨어에 붙여넣으면 7/16 형태가 됩니다.') ?></th>
                        <th>적요<?= tbb_help_tip('무엇을 썼는지 적습니다. 예: 차대') ?></th>
                        <th>거래처<?= tbb_help_tip('돈을 낸 곳입니다. 예: 스타벅스') ?></th>
                        <th class="num">금액<?= tbb_help_tip('원화 금액입니다.') ?></th>
                        <th>프로젝트/비고<?= tbb_help_tip('그룹웨어 비고 칸에 붙습니다.') ?></th>
                        <th class="col-actions"><span class="sr-only">삭제</span></th>
                      </tr>
                    </thead>
                    <tbody id="draft-team-rows"></tbody>
                  </table>
                </div>
                <button class="btn btn-outline btn-sm draft-team-addrow" type="button" data-action="add-item">+ 행 추가</button>
              </div>
              <div class="input-wrap" id="draft-body-wrap">
                <textarea class="input textarea tpl-body" id="draft-body" rows="12" tabindex="0"></textarea>
              </div>
            </div>
          </div>

          <div class="draft-actionbar">
            <p class="draft-actionbar-sum">
              <span class="draft-actionbar-sum-label">합계</span>
              <strong><span id="draft-item-sum">0</span><span class="unit">원</span></strong>
            </p>
            <div class="draft-actionbar-save">
              <button class="btn btn-outline tooltip tooltip-top" type="button" id="draft-save" data-tooltip="이달 초안을 저장합니다.">임시저장</button>
              <button class="btn btn-secondary tooltip tooltip-top" type="button" id="draft-done" data-action="done" hidden data-tooltip="그룹웨어에 올린 뒤 작성완료로 표시합니다.">작성완료</button>
              <button class="btn btn-ghost tooltip tooltip-top" type="button" id="draft-reopen" data-action="reopen" hidden data-tooltip="다시 수정할 수 있게 초안으로 되돌립니다.">초안으로</button>
            </div>
          </div>
        </div>
      </section>
    </div>

    <template id="draft-item-row-tpl">
      <article class="card draft-item-row">
        <div class="draft-item-bar">
          <button class="draft-item-toggle" type="button" data-action="toggle-item" aria-expanded="true" aria-label="항목 접기">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </button>
          <button class="draft-item-summary" type="button" data-action="toggle-item">
            <span data-role="item-summary">내용 없음</span>
          </button>
          <button class="btn btn-ghost btn-sm draft-item-remove tooltip" type="button" data-action="remove-item" aria-label="항목 삭제" data-tooltip="이 항목을 삭제합니다.">×</button>
        </div>
        <div class="draft-item-body">
        <div class="draft-item-card-grid">
          <div class="field draft-item-desc">
            <label class="label">내용<?= tbb_help_tip('결제 항목에 넣을 상품·서비스 이름입니다.') ?></label>
            <input class="input" type="text" data-field="description" placeholder="예: Rocket API Startup Plan" />
            <input type="hidden" data-field="vendor" value="" />
          </div>
          <div class="draft-item-money">
            <div class="field draft-item-date">
              <label class="label">결제일<?= tbb_help_tip('외화 환율의 조회 기준일입니다. 비우면 오늘(한국시간)로 매매기준율을 가져오고, 주말·공휴일이면 최대 7일 전 고시입니다.') ?></label>
              <input class="input" type="text" data-field="payment_date" data-datepicker autocomplete="off" placeholder="YYYY-MM-DD" readonly />
            </div>
            <div class="field draft-item-currency">
              <label class="label">통화 <span class="req" aria-hidden="true">*</span><?= tbb_help_tip('KRW는 원화 그대로입니다. 외화는 수출입은행 매매기준율로 원화를 계산합니다.') ?></label>
              <div class="select" data-select>
                <button type="button" class="select-trigger" aria-haspopup="listbox" aria-expanded="false">
                  <span class="select-value">KRW</span>
                  <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <input type="hidden" data-field="currency" value="KRW" />
                <ul class="select-menu" role="listbox">
                  <?php foreach (tbb_currencies() as $code => $label): ?>
                  <li class="select-option<?= $code === 'KRW' ? ' is-selected' : '' ?>" data-value="<?= h($code) ?>"><?= h($label) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
            <div class="field draft-item-amount">
              <label class="label">금액 <span class="req" aria-hidden="true">*</span><?= tbb_help_tip('선택한 통화의 결제 금액입니다.') ?></label>
              <input class="input draft-amount" type="text" inputmode="decimal" data-field="amount" placeholder="0" autocomplete="off" required aria-required="true" />
            </div>
            <div class="field draft-item-krw">
              <label class="label">원화<?= tbb_help_tip('기안 합계에 쓰는 원 금액입니다. 외화는 결제일(없으면 오늘)의 수출입은행 매매기준율입니다. 주말·공휴일이면 최대 7일 전 고시를 씁니다.') ?></label>
              <div class="draft-krw-cell">
                <input class="input draft-amount draft-krw-input" type="text" inputmode="numeric" data-field="amount_krw" data-role="krw" placeholder="0" autocomplete="off" readonly tabindex="-1" aria-readonly="true" title="금액과 환율로 자동 계산됩니다." />
                <button class="draft-rate-btn tooltip" type="button" data-action="refresh-rate" hidden aria-label="환율 조회" data-tooltip="결제일(없으면 오늘) 매매기준율로 다시 조회합니다.">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                </button>
              </div>
            </div>
          </div>
        </div>
        </div>
      </article>
    </template>

    <template id="draft-team-row-tpl">
      <tr class="draft-item-row draft-team-row">
        <td>
          <input class="input" type="text" data-field="payment_date" data-datepicker autocomplete="off" placeholder="YYYY-MM-DD" readonly />
        </td>
        <td>
          <input class="input" type="text" data-field="description" placeholder="차대" />
        </td>
        <td>
          <input class="input" type="text" data-field="vendor" placeholder="스타벅스" />
        </td>
        <td class="num">
          <input class="input draft-amount" type="text" inputmode="numeric" data-field="amount" placeholder="0" autocomplete="off" />
        </td>
        <td>
          <input class="input" type="text" data-field="note" placeholder="프로젝트/비고" />
        </td>
        <td class="col-actions">
          <input type="hidden" data-field="currency" value="KRW" />
          <input type="hidden" data-field="item_kind" value="" />
          <input type="hidden" data-field="amount_krw" data-role="krw" value="" />
          <button class="btn btn-ghost btn-sm draft-item-remove tooltip" type="button" data-action="remove-item" aria-label="항목 삭제" data-tooltip="이 항목을 삭제합니다.">×</button>
        </td>
      </tr>
    </template>

    <script type="application/json" id="draft-templates"><?= json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
    <script type="application/json" id="draft-store"><?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
  </div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
