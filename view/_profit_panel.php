<?php
/**
 * 수익 화면(연도 선택·카드·접기 표). 매출(수입) 수익 탭과 리더십 페이지가 같이 쓴다.
 * 그리기·동작은 asset/js/revenue_profit_view.js. 필요한 변수: $year(string), $years(list<int>)
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/_profit_panel.php
 */

declare(strict_types=1);
?>
  <div class="rev-toolbar">
    <div class="rev-filters" aria-label="수익 필터">
      <div class="field rev-year-field">
        <label class="label sr-only" for="rev-year-profit-trigger">연도</label>
        <div class="select" data-select data-rev-year>
          <button type="button" class="select-trigger" id="rev-year-profit-trigger" aria-haspopup="listbox" aria-expanded="false">
            <span class="select-value"><?= h($year) ?>년</span>
            <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </button>
          <input type="hidden" id="rev-year-profit" value="<?= h($year) ?>" />
          <ul class="select-menu" role="listbox">
            <?php foreach ($years as $y): ?>
              <?php $y = (int) $y; ?>
              <li class="select-option<?= (string) $y === $year ? ' is-selected' : '' ?>" data-value="<?= h((string) $y) ?>"><?= h((string) $y) ?>년</li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <section class="rev-pf" aria-label="수익 요약">
    <div class="rev-pf-total" id="rev-profit-total">
      <div class="rev-pf-total-main">
        <span class="rev-pf-total-label">전체 수익</span>
        <strong class="rev-pf-total-amt"><span data-profit="profit">0</span><span class="unit">원</span></strong>
      </div>
      <dl class="rev-pf-total-side">
        <div><dt>수익률</dt><dd data-profit="rate">-</dd></div>
        <div><dt>건수</dt><dd><span data-profit="count">0</span>건</dd></div>
      </dl>
    </div>
    <div class="rev-pf-bar" id="rev-profit-bar" role="img" aria-label="그룹별 수익 비중">
      <span class="rev-pf-seg is-adtech" data-seg="ADTECH"></span>
      <span class="rev-pf-seg is-general" data-seg="GENERAL"></span>
      <span class="rev-pf-seg is-project" data-seg="PROJECT"></span>
    </div>
    <div class="rev-pf-groups">
      <div class="rev-pf-group is-adtech" id="rev-profit-adtech">
        <p class="rev-pf-name"><span class="rev-pf-dot" aria-hidden="true"></span>애드테크 솔루션<span class="rev-pf-share" data-profit="share"></span></p>
        <strong class="rev-pf-amt"><span data-profit="profit">0</span><span class="unit">원</span></strong>
        <p class="rev-pf-rate">수익률 <b data-profit="rate">-</b></p>
        <dl class="rev-pf-meta">
          <div><dt>건수</dt><dd><span data-profit="count">0</span>건</dd></div>
          <div><dt>집행금액</dt><dd><span data-profit="base">0</span>원</dd></div>
        </dl>
        <p class="rev-pf-warn" data-profit="missing" hidden></p>
      </div>
      <div class="rev-pf-group is-general" id="rev-profit-general">
        <p class="rev-pf-name"><span class="rev-pf-dot" aria-hidden="true"></span>일반 솔루션<span class="rev-pf-share" data-profit="share"></span></p>
        <strong class="rev-pf-amt"><span data-profit="profit">0</span><span class="unit">원</span></strong>
        <p class="rev-pf-rate">수익률 <b data-profit="rate">-</b></p>
        <dl class="rev-pf-meta">
          <div><dt>건수</dt><dd><span data-profit="count">0</span>건</dd></div>
          <div><dt>매출</dt><dd><span data-profit="base">0</span>원</dd></div>
        </dl>
      </div>
      <div class="rev-pf-group is-project" id="rev-profit-project">
        <p class="rev-pf-name"><span class="rev-pf-dot" aria-hidden="true"></span>용역<span class="rev-pf-share" data-profit="share"></span></p>
        <strong class="rev-pf-amt"><span data-profit="profit">0</span><span class="unit">원</span></strong>
        <p class="rev-pf-rate">수익률 <b data-profit="rate">-</b></p>
        <dl class="rev-pf-meta">
          <div><dt>건수</dt><dd><span data-profit="count">0</span>건</dd></div>
          <div><dt>매출</dt><dd><span data-profit="base">0</span>원</dd></div>
          <div><dt>원가</dt><dd><span data-profit="cost">0</span>원</dd></div>
        </dl>
      </div>
    </div>
  </section>
  <p class="rev-profit-note text-sm text-faint" id="rev-profit-note" hidden></p>
  <div class="rev-pt-wrap">
    <div class="rev-pt-head">
      <h2 class="rev-pt-title">상세 내역</h2>
      <div class="rev-pt-actions">
      <button type="button" class="btn btn-outline btn-sm" data-action="profit-toggle-all" id="rev-profit-toggle-all" aria-expanded="false">
        <svg data-icon="expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>
        <svg data-icon="collapse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="m7 20 5-5 5 5"/><path d="m7 4 5 5 5-5"/></svg>
        <span class="rev-profit-toggle-label">모두 펼치기</span>
      </button>
      <button type="button" class="btn btn-outline btn-sm tooltip tooltip-bottom" data-action="download-profit" data-tooltip="엑셀에서 열 수 있는 CSV로 받습니다.">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
        CSV 다운로드
      </button>
      </div>
    </div>
    <div class="rev-pt-scroll">
      <table class="rev-pt" id="rev-profit-table">
        <thead>
          <tr>
            <th class="col-date">입금일</th>
            <th class="col-project">프로젝트</th>
            <th class="col-client">거래처</th>
            <th class="col-svc">솔루션/서비스</th>
            <th class="num col-money">집행금액</th>
            <th class="num col-rate">테크피</th>
            <th class="num col-money">공급가</th>
            <th class="num col-money">원가<span class="tooltip tooltip-bottom draft-tip rev-th-tip" tabindex="0" data-tooltip="외주비·서버비 등 프로젝트에 들어간 비용입니다. 용역(컨설팅·유지보수·개발비·기타)은 공급가에서 원가를 빼고 수익을 계산합니다." aria-label="외주비·서버비 등 프로젝트에 들어간 비용입니다. 용역(컨설팅·유지보수·개발비·기타)은 공급가에서 원가를 빼고 수익을 계산합니다."><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg></span></th>
            <th class="num col-money">수익</th>
            <th class="num col-rate">수익률</th>
          </tr>
        </thead>
        <tbody id="rev-profit-body"></tbody>
      </table>
    </div>
  </div>
