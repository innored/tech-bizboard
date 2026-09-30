<?php
/**
 * 지출 템플릿 설정 화면
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/settings.php
 */

declare(strict_types=1);

$PAGE_TITLE = 'TechBizBoard · 기안 템플릿';
$SHOW_NAV = true;
$NAV = 'settings';
$rows = is_array($rows ?? null) ? $rows : [];
$selectedId = (int) ($selectedId ?? 0);
$cycleTypes = tbb_cycle_types();
$payTypes = tbb_payment_types();

/** 본문·제목에 넣을 치환 칩. 마우스를 올리면 설명이 뜬다. */
function tbb_tpl_token(string $insert, string $target, string $tip): string
{
    return '<button class="tpl-token tooltip" type="button" data-insert="' . h($insert)
        . '" data-target="' . h($target) . '" data-tooltip="' . h($tip) . '">'
        . h($insert) . '</button>';
}

require __DIR__ . '/_header.php';
?>
<main class="app-main">
  <div class="app-content" data-settings data-api="api/templates" data-selected="<?= h((string) $selectedId) ?>">
    <div class="page-head">
      <h1 class="text-h1">기안 템플릿</h1>
      <p class="text-sm text-muted">목록에서 항목을 고르고, 그룹웨어에 붙일 제목·내용을 적습니다.</p>
    </div>

    <div class="tpl-layout">
      <aside class="tpl-rail" aria-label="템플릿 목록">
        <div class="tpl-rail-head">
          <span class="tpl-rail-title">목록</span>
          <button class="btn btn-primary btn-sm" type="button" data-action="new">새 템플릿</button>
        </div>
        <label class="tpl-search">
          <span class="sr-only">템플릿 검색</span>
          <input class="input" type="search" id="tpl-filter" placeholder="건명·담당 검색" autocomplete="off" />
        </label>
        <div class="tpl-list" role="listbox" aria-label="템플릿">
          <?php if ($rows === []): ?>
            <p class="tpl-empty text-sm text-muted">아직 없습니다. 새 템플릿으로 시작하세요.</p>
          <?php endif; ?>
          <p class="tpl-empty text-sm text-muted" id="tpl-filter-empty" hidden>검색 결과가 없습니다.</p>
          <?php foreach ($rows as $row): ?>
            <?php
            $id = (int) ($row['id'] ?? 0);
            $active = (int) ($row['is_active'] ?? 0) === 1;
            $pay = tbb_pay_label((string) ($row['payment_type'] ?? ''));
            $createdBy = trim((string) ($row['created_by'] ?? ''));
            ?>
            <button
              class="tpl-item<?= $id === $selectedId ? ' is-selected' : '' ?>"
              type="button"
              role="option"
              data-action="edit"
              data-id="<?= h((string) $id) ?>"
              data-search="<?= h(mb_strtolower((string) ($row['title'] ?? '') . ' ' . (string) ($row['assignee'] ?? '') . ' ' . $createdBy, 'UTF-8')) ?>"
              aria-selected="<?= $id === $selectedId ? 'true' : 'false' ?>"
            >
              <span class="tpl-item-name"><?= h((string) ($row['title'] ?? '')) ?></span>
              <span class="tpl-item-meta">
                <?= h((string) ($row['assignee'] ?? '')) ?>
                · <?= h(tbb_cycle_label((string) ($row['cycle_type'] ?? ''))) ?>
                <?php if ($pay !== ''): ?> · <?= h($pay) ?><?php endif; ?>
                <?php if ($createdBy !== ''): ?> · <?= h($createdBy) ?><?php endif; ?>
              </span>
              <?= $active
                ? '<span class="badge badge-success">사용</span>'
                : '<span class="badge badge-muted">중지</span>' ?>
            </button>
          <?php endforeach; ?>
        </div>
      </aside>

      <section class="tpl-editor card" id="tpl-form-card" aria-live="polite">
        <div class="tpl-idle" id="tpl-idle"<?= $selectedId > 0 ? ' hidden' : '' ?>>
          <p class="text-h2">템플릿을 선택하세요</p>
          <p class="text-sm text-muted">목록에서 고치거나, 새 템플릿으로 추가합니다.</p>
        </div>

        <form id="tpl-form" class="tpl-form"<?= $selectedId > 0 ? '' : ' hidden' ?>>
          <input type="hidden" name="id" value="" />

          <div class="tpl-editor-head">
            <div class="field tpl-title-field">
              <label class="label" for="tpl-title">건명 <span class="req">*</span></label>
              <input class="input" type="text" id="tpl-title" name="title" required placeholder="예: Cursor AI, Claude 구독료" />
            </div>
            <div class="field tpl-assignee-field">
              <label class="label" for="tpl-assignee">담당 <span class="req">*</span></label>
              <input class="input" type="text" id="tpl-assignee" name="assignee" required placeholder="이름" />
            </div>
            <label class="switch tpl-active">
              <input type="checkbox" id="tpl-active" name="is_active" value="1" checked />
              <span class="track"><span class="thumb"></span></span>
              <span class="switch-label" id="tpl-active-label">사용</span>
            </label>
          </div>
          <p class="tpl-created-by hint" id="tpl-created-by" hidden></p>

          <div class="tpl-sheet">
            <p class="tpl-sheet-label">그룹웨어에 붙일 문구</p>
            <div class="field">
              <label class="label" for="tpl-title-text">제목 <span class="req">*</span></label>
              <input class="input" type="text" id="tpl-title-text" name="title_pattern" required placeholder="[{year}년 {month}월] 구독료 결제 기안" />
              <div class="tpl-tokens">
                <?= tbb_tpl_token('{year}', 'tpl-title-text', '기안 연도. 8월 기안 → 2026') ?>
                <?= tbb_tpl_token('{month}', 'tpl-title-text', '기안 달. 8월 기안 → 8') ?>
                <?= tbb_tpl_token('{month_pad}', 'tpl-title-text', '기안 달 두 자리. 8월 기안 → 08') ?>

              </div>
            </div>
            <div class="field">
              <span class="label">첨부 내용·파일명 규칙 <span class="text-muted">(AI 분석 시 자동)</span></span>
              <label class="switch tpl-active">
                <input type="checkbox" id="tpl-include-vendor" name="content_include_vendor" value="1" checked />
                <span class="track"><span class="thumb"></span></span>
                <span class="switch-label">내용·파일명에 거래처 표시</span>
              </label>
              <p class="hint">영수증을 AI 분석하면 추출 내용에 맞춰 내용·파일명이 자동 정리됩니다. (거래처는 위 설정에 따라 포함/생략)<br>
                · 개인 구독(영수증 이메일이 사용자와 매칭될 때): <b>사용자 (거래처) - 플랜</b> / 파일 <code>202608_박은혜(Cursor)</code><br>
                · 회사 영수증: <b>거래처 - 세부내용</b> / 파일 <code>202608_거래처_세부내용</code><br>
                · 거래처 미표시(서버 등 같은 업체): <b>세부내용만</b> / 파일 <code>202608_세부내용</code>
              </p>
            </div>
            <div class="field">
              <label class="label" for="tpl-body-text">내용 <span class="req">*</span></label>
              <textarea class="input textarea tpl-body" id="tpl-body-text" name="body_pattern" rows="12" required placeholder="본문을 그대로 적습니다."></textarea>
              <div class="tpl-tokens">
                <?= tbb_tpl_token('{year}', 'tpl-body-text', '기안 연도. 8월 기안 → 2026') ?>
                <?= tbb_tpl_token('{month}', 'tpl-body-text', '기안 달. 8월 기안 → 8') ?>
                <?= tbb_tpl_token('{month_pad}', 'tpl-body-text', '기안 달 두 자리. 8월 기안 → 08') ?>
                <?= tbb_tpl_token('{title}', 'tpl-body-text', '완성된 기안 제목') ?>
                <?= tbb_tpl_token('{vendors}', 'tpl-body-text', '업체/작업자 칸') ?>
                <?= tbb_tpl_token('{period}', 'tpl-body-text', '기간. 비우면 그달 1일~말일') ?>
                <?= tbb_tpl_token('{payment_lines}', 'tpl-body-text', '결제 줄. 충전/결제, 금액, 날짜') ?>
                <?= tbb_tpl_token('{payments}', 'tpl-body-text', '결제 줄 나열과 원화 합계') ?>
                <?= tbb_tpl_token('{amount_korean}', 'tpl-body-text', '원화 합계 한글. 예: 일금 이십만 원정') ?>
                <?= tbb_tpl_token('{amount_krw}', 'tpl-body-text', '원화 합계 숫자. 예: 200,000') ?>
                <?= tbb_tpl_token('{exchange_rates}', 'tpl-body-text', '외화 매매기준율. 원화만이면 비웁니다') ?>
                <?= tbb_tpl_token('{item_krw_lines}', 'tpl-body-text', '줄별 또는 인당 원화 내역') ?>
                <?= tbb_tpl_token('{payment_method}', 'tpl-body-text', '결제방식 칸') ?>
                <?= tbb_tpl_token('{pay_request}', 'tpl-body-text', '지급요청일') ?>
                <?= tbb_tpl_token('{attachment}', 'tpl-body-text', '첨부서류 칸') ?>
                <?= tbb_tpl_token('{account_info}', 'tpl-body-text', '계정') ?>
                <?= tbb_tpl_token('{note}', 'tpl-body-text', '비고 칸') ?>
                <?= tbb_tpl_token('{next_month}', 'tpl-body-text', '다음 달. 8월 기안 → 9') ?>
                <?= tbb_tpl_token('{next_month_pad}', 'tpl-body-text', '다음 달 두 자리. 8월 기안 → 09') ?>
                <?= tbb_tpl_token('{next_month_year}', 'tpl-body-text', '다음 달이 속한 해. 8월 기안 → 2026, 12월만 다음 해') ?>

              </div>
            </div>
          </div>

          <details class="tpl-meta" id="tpl-meta">
            <summary>업체 · 기간 · 결제방식 · 지급요청일 · 첨부 · 사이트 · 계정 · 주기 · 갱신일 · 비고</summary>
            <div class="tpl-form-grid">
              <div class="field">
                <div class="tpl-field-label">
                  <label class="label" for="tpl-vendor">업체/작업자</label>
                  <button class="tpl-token tooltip" type="button" data-insert="{vendors}" data-target="tpl-body-text" data-tooltip="본문에 이 칸 값이 들어갑니다">{vendors}</button>
                </div>
                <input class="input" type="text" id="tpl-vendor" name="vendor" placeholder="{vendors}에 들어갑니다" />
              </div>
              <div class="field">
                <div class="tpl-field-label">
                  <label class="label" for="tpl-period">기간 패턴</label>
                  <button class="tpl-token tooltip" type="button" data-insert="{period}" data-target="tpl-body-text" data-tooltip="본문에 이 칸 값이 들어갑니다">{period}</button>
                </div>
                <div class="tpl-tokens">
                  <button class="tpl-token tooltip" type="button" data-insert="{year}" data-target="tpl-period" data-tooltip="기안 연도. 8월 기안 → 2026">{year}</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{month}" data-target="tpl-period" data-tooltip="기안 달. 8월 기안 → 8">{month}</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{month_pad}" data-target="tpl-period" data-tooltip="기안 달 두 자리. 8월 기안 → 08">{month_pad}</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{last_day}" data-target="tpl-period" data-tooltip="그달 말일. 8월 기안 → 31">{last_day}</button>
                </div>
                <input class="input" type="text" id="tpl-period" name="period_pattern" placeholder="비우면 그달 1일~말일. 예: 1개월" />
              </div>
              <div class="field">
                <div class="tpl-field-label">
                  <label class="label" for="tpl-pay-method">결제방식 문구</label>
                  <button class="tpl-token tooltip" type="button" data-insert="{payment_method}" data-target="tpl-body-text" data-tooltip="본문에 이 칸 값이 들어갑니다">{payment_method}</button>
                </div>
                <div class="tpl-tokens">
                  <button class="tpl-token" type="button" data-insert="자동이체" data-target="tpl-pay-method" data-fill="1">자동이체</button>
                  <button class="tpl-token" type="button" data-insert="법인카드 결제" data-target="tpl-pay-method" data-fill="1">법인카드 결제</button>
                  <button class="tpl-token" type="button" data-insert="자동결제" data-target="tpl-pay-method" data-fill="1">자동결제</button>
                  <button class="tpl-token" type="button" data-insert="수기결제" data-target="tpl-pay-method" data-fill="1">수기결제</button>
                </div>
                <input class="input" type="text" id="tpl-pay-method" name="payment_method_text" placeholder="법인카드 결제, 자동이체" />
              </div>
              <div class="field">
                <div class="tpl-field-label">
                  <label class="label" for="tpl-pay-request">지급요청일 문구</label>
                  <button class="tpl-token tooltip" type="button" data-insert="{pay_request}" data-target="tpl-body-text" data-tooltip="본문에 이 칸 값이 들어갑니다">{pay_request}</button>
                </div>
                <div class="tpl-tokens">
                  <button class="tpl-token" type="button" data-insert="자동결제" data-target="tpl-pay-request" data-fill="1">자동결제</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{next_month}/14" data-target="tpl-pay-request" data-fill="1" data-tooltip="다음 달 14일. 8월 기안 → 9/14">익월 14일</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{next_month_year}-{next_month_pad}-14" data-target="tpl-pay-request" data-fill="1" data-tooltip="다음 달 14일. 8월 기안 → 2026-09-14">익월-14</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{next_month}" data-target="tpl-pay-request" data-tooltip="다음 달. 8월 기안 → 9">{next_month}</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{next_month_pad}" data-target="tpl-pay-request" data-tooltip="다음 달 두 자리. 8월 기안 → 09">{next_month_pad}</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{next_month_year}" data-target="tpl-pay-request" data-tooltip="다음 달이 속한 해. 8월 기안 → 2026, 12월만 다음 해">{next_month_year}</button>
                  <button class="tpl-token tooltip" type="button" data-insert="{last_day}" data-target="tpl-pay-request" data-tooltip="그달 말일. 8월 기안 → 31">{last_day}</button>
                </div>
                <input class="input" type="text" id="tpl-pay-request" name="pay_request_pattern" placeholder="익월 14일이면 {next_month}/14" />
                <p class="hint">8월 기안이면 {next_month}/14 → 9/14. {next_month_year}는 익월이 속한 해입니다.</p>
              </div>
              <div class="field">
                <div class="tpl-field-label">
                  <label class="label" for="tpl-attachment">첨부서류</label>
                  <button class="tpl-token tooltip" type="button" data-insert="{attachment}" data-target="tpl-body-text" data-tooltip="본문에 이 칸 값이 들어갑니다">{attachment}</button>
                </div>
                <div class="tpl-tokens">
                  <button class="tpl-token" type="button" data-insert="영수증 첨부" data-target="tpl-attachment" data-fill="1">영수증 첨부</button>
                  <button class="tpl-token" type="button" data-insert="거래명세서 첨부" data-target="tpl-attachment" data-fill="1">거래명세서 첨부</button>
                  <button class="tpl-token" type="button" data-insert="세금계산서 첨부" data-target="tpl-attachment" data-fill="1">세금계산서 첨부</button>
                </div>
                <input class="input" type="text" id="tpl-attachment" name="attachment_text" placeholder="영수증 첨부" />
              </div>
              <div class="field">
                <label class="label" for="tpl-site">결제 사이트</label>
                <input class="input" type="text" id="tpl-site" name="payment_site" inputmode="url" placeholder="https://" autocomplete="url" />
              </div>
              <div class="field">
                <div class="tpl-field-label">
                  <label class="label" for="tpl-account">계정</label>
                  <button class="tpl-token tooltip" type="button" data-insert="{account_info}" data-target="tpl-body-text" data-tooltip="본문에 이 칸 값이 들어갑니다">{account_info}</button>
                </div>
                <input class="input" type="text" id="tpl-account" name="account_info" placeholder="자동결제, 수기결제 등" />
              </div>
              <div class="field">
                <label class="label" for="tpl-cycle-trigger">주기 <span class="req">*</span></label>
                <div class="select" data-select>
                  <button type="button" class="select-trigger" id="tpl-cycle-trigger" aria-haspopup="listbox" aria-expanded="false">
                    <span class="select-value"><?= h(tbb_cycle_label('MONTHLY')) ?></span>
                    <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                  </button>
                  <input type="hidden" name="cycle_type" value="MONTHLY" />
                  <ul class="select-menu" role="listbox">
                    <?php foreach ($cycleTypes as $value => $label): ?>
                    <li class="select-option<?= $value === 'MONTHLY' ? ' is-selected' : '' ?>" data-value="<?= h($value) ?>"><?= h($label) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
              <div class="field">
                <label class="label" for="tpl-pay-trigger">결제방식</label>
                <div class="select" data-select>
                  <button type="button" class="select-trigger" id="tpl-pay-trigger" aria-haspopup="listbox" aria-expanded="false">
                    <span class="select-value">선택 안 함</span>
                    <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                  </button>
                  <input type="hidden" name="payment_type" value="" />
                  <ul class="select-menu" role="listbox">
                    <li class="select-option is-selected" data-value="">선택 안 함</li>
                    <?php foreach ($payTypes as $value => $label): ?>
                    <li class="select-option" data-value="<?= h($value) ?>"><?= h($label) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
              <div class="field">
                <label class="label" for="tpl-renewal">다음 갱신일</label>
                <input class="input" type="text" id="tpl-renewal" name="next_renewal_date" data-datepicker autocomplete="off" placeholder="YYYY-MM-DD" readonly />
              </div>
              <div class="field tpl-note-field">
                <div class="tpl-field-label">
                  <label class="label" for="tpl-note">비고</label>
                  <button class="tpl-token tooltip" type="button" data-insert="{note}" data-target="tpl-body-text" data-tooltip="본문에 이 칸 값이 들어갑니다">{note}</button>
                </div>
                <textarea class="input textarea" id="tpl-note" name="note" rows="3" placeholder="결제 시 참고할 내용을 적습니다."></textarea>
              </div>
            </div>
          </details>

          <div class="tpl-editor-foot">
            <button class="btn btn-primary" type="submit">저장</button>
            <button class="btn btn-ghost" type="button" data-action="cancel">취소</button>
          </div>
        </form>
      </section>
    </div>

    <script type="application/json" id="tpl-store"><?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
  </div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
