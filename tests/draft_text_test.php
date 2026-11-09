<?php
/**
 * 기안서 플레이스홀더 치환 테스트
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/tests/draft_text_test.php
 */

declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/KoreanWonProcessor.php';
require dirname(__DIR__) . '/src/DraftTextProcessor.php';

$proc = new DraftTextProcessor();

$title = $proc->render('[{year}년 {month}월] {title} 결제 기안', [
    'year'  => '2026',
    'month' => '9',
    'title' => 'Cursor AI, Claude 구독료',
]);
expect_eq($title, '[2026년 9월] Cursor AI, Claude 구독료 결제 기안', '제목 치환');

$body = $proc->render(
    '1. 건명: {title}\n2. 담당: {assignee}\n4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원)',
    [
        'title'          => 'Cursor AI, Claude 구독료',
        'assignee'       => '단테',
        'currency'       => 'USD',
        'amount_foreign' => '200',
        'amount_krw'     => '268520',
    ]
);
expect_true(str_contains($body, '단테'), '담당 치환');
expect_true(str_contains($body, 'USD 200'), '외화 치환');
expect_true(str_contains($body, '268520원'), '원화 치환');

$empty = $proc->render('{title} {amount_foreign} {amount_krw}', [
    'title'          => 'KINX iXCloud 서버 사용료',
    'amount_foreign' => '(금액 미입력)',
    'amount_krw'     => '(금액 미입력)',
]);
expect_eq($empty, 'KINX iXCloud 서버 사용료 (금액 미입력) (금액 미입력)', '금액 미입력 표시');

$unknown = $proc->render('고정 {unknown}', ['title' => 'x']);
expect_eq($unknown, '고정 {unknown}', '없는 키는 그대로');

expect_eq(
    $proc->applyYearMonth('[{year}년 {month}월] 서버 사용료 결제 기안', '2026-08'),
    '[2026년 8월] 서버 사용료 결제 기안',
    '제목 년월 치환'
);
expect_eq(
    $proc->applyYearMonth('[{year}년 {month}월] {title} 기안', '2026-01'),
    '[2026년 1월] {title} 기안',
    'year·month만 바꾸고 나머지 토큰은 유지'
);
$aug = $proc->yearMonthVars('2026-08');
expect_eq($aug['next_month_year'], '2026', '8월의 익월 연도는 2026');
expect_eq($aug['next_month'], '9', '8월의 익월은 9');
expect_eq($aug['next_year'], '2026', 'next_year는 익월 연도와 같음');
expect_eq(
    $proc->applyYearMonth('{next_month_year}-{next_month_pad}-14', '2026-08'),
    '2026-09-14',
    '익월 14일'
);
expect_eq($proc->yearMonthVars('2026-12')['next_month_year'], '2027', '12월만 익월 연도가 바뀜');

$items = [
    [
        'payment_date'   => '2026-08-23',
        'description'    => 'Rocket API Startup Plan',
        'currency'       => 'EUR',
        'amount_foreign' => 99,
        'amount_krw'     => 157928,
        'exchange_rate'  => 1595.23,
    ],
    [
        'payment_date'   => '2026-08-19',
        'description'    => 'X Developer API 충전',
        'currency'       => 'USD',
        'amount_foreign' => 55,
        'amount_krw'     => 75548,
        'exchange_rate'  => 1373.6,
    ],
];

$block = $proc->formatPaymentLines($items);
expect_true(str_contains($block, 'Rocket API Startup Plan / EUR 99'), 'EUR 줄');
expect_true(str_contains($block, 'X Developer API 충전 / USD 55'), 'USD 줄');
expect_true(str_contains($block, '합계 233,476원'), '원화 합계');

$withToken = $proc->applyDraftBody(
    "1. 건명: Rocket API\n4. 결제금액:\n{payments}\n5. 비고: 참고",
    '2026-08',
    $items
);
expect_true(str_contains($withToken, '{payments}') === false, '{payments} 치환');
expect_true(str_contains($withToken, 'EUR 99'), '본문에 EUR 줄');
expect_true(str_contains($withToken, '5. 비고: 참고'), '뼈대 비고 유지');

$composed = $proc->compose(
    '{month}월 테크본부 솔루션 연동을 위한 API 비용',
    "{title} 건에 대하여 진행하고자 하오니 재가하여 주시기 바랍니다.\n\n- 아 래 -\n\n{contents}\n",
    '2026-08',
    $items,
    ['title' => '솔루션 연동을 위한 API 구독 비용 (Rocket, X developer 등)']
);
expect_eq(
    $composed['title'],
    '8월 테크본부 솔루션 연동을 위한 API 비용',
    '제목은 패턴만 치환'
);
expect_true(str_contains($composed['body'], '{contents}') === false, '{contents} 치환');
expect_true(
    str_contains($composed['body'], '8월 테크본부 솔루션 연동을 위한 API 비용 건에 대하여'),
    '내용 {title}은 기안 제목'
);
expect_true(!str_contains($composed['body'], '솔루션 연동을 위한 API 구독 비용'), '내용에 건명을 넣지 않음');
expect_true(str_contains($composed['body'], '- 아 래 -'), '템플릿 뼈대 유지');
expect_true(str_contains($composed['body'], '€99'), '{contents}에 결제 문장');

$seedStyle = $proc->compose(
    '[{year}년 {month}월] {title} 결제 기안',
    "{title} 건에 대하여\n{contents}",
    '2026-08',
    $items,
    ['title' => 'KINX iXCloud 서버 사용료']
);
expect_eq(
    $seedStyle['title'],
    '[2026년 8월] KINX iXCloud 서버 사용료 결제 기안',
    '제목 패턴의 {title}은 건명'
);
expect_true(
    str_contains($seedStyle['body'], '[2026년 8월] KINX iXCloud 서버 사용료 결제 기안 건에 대하여'),
    '내용 {title}은 완성된 제목'
);

$legacy = $proc->applyDraftBody(
    '1. 건명: Rocket API
2. 담당: 루시
4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원, 환율 {exchange_rate} / {rate_date})
5. 비고: Startup Plan 등',
    '2026-08',
    $items
);
expect_true(!str_contains($legacy, '{currency}'), '구 금액 토큰 제거');
expect_true(str_contains($legacy, 'USD 55'), '구 본문에도 결제 줄');
expect_true(str_contains($legacy, '5. 비고: Startup Plan 등'), '구 본문 비고 유지');

$krwOnly = $proc->formatPaymentLines([
    [
        'payment_date'   => '2026-08-05',
        'description'    => '서버 사용료',
        'currency'       => 'KRW',
        'amount_foreign' => 120000,
        'amount_krw'     => 120000,
        'exchange_rate'  => 1,
    ],
]);
expect_true(str_contains($krwOnly, 'KRW 120,000'), '원화 줄은 환율 생략');
expect_true(!str_contains($krwOnly, '환율'), '원화 줄에 환율 없음');

$noRate = $proc->formatPaymentLines([
    [
        'payment_date'   => '2026-08-23',
        'description'    => 'Rocket API',
        'currency'       => 'EUR',
        'amount_foreign' => 99,
        'amount_krw'     => 0,
        'rate_ok'        => false,
    ],
]);
expect_true(str_contains($noRate, '0원'), '환율 실패 줄은 0원');
expect_true(!str_contains($noRate, '환율 미확인'), '환율 미확인 문구 없음');

expect_eq($proc->shiftDateToMonth('2026-08-23', '2026-09'), '2026-09-23', '같은 날로 이동');
expect_eq($proc->shiftDateToMonth('2026-01-31', '2026-02'), '2026-02-28', '없는 날은 말일');
expect_eq($proc->shiftDateToMonth('', '2026-09'), '', '빈 날짜는 그대로');

$shiftedText = $proc->shiftYearMonthInText(
    "07월 애드테크팀 AI 구독\n기간 2026-07-01 ~ 2026-07-31",
    '2026-07',
    '2026-08'
);
expect_true(str_contains($shiftedText, '08월'), '07월 → 08월');
expect_true(str_contains($shiftedText, '2026-08-01 ~ 2026-08-31'), '기간 월 이동');
expect_true(!str_contains($shiftedText, '07월'), '이전 월 표기 제거');

$narr = $proc->formatPaymentNarratives([
    [
        'payment_date'   => '2026-08-19',
        'description'    => 'X Developer API 충전',
        'currency'       => 'USD',
        'amount_foreign' => 55,
        'item_kind'      => 'CHARGE',
        'rate_ok'        => true,
    ],
    [
        'payment_date'   => '2026-08-23',
        'description'    => 'Rocket API Startup Plan',
        'currency'       => 'EUR',
        'amount_foreign' => 99,
        'item_kind'      => 'PAY',
        'rate_ok'        => true,
    ],
]);
expect_eq(
    $narr,
    "충전 > X Developer API 충전 $55 (8/19)\n결제 > Rocket API Startup Plan €99 (8/23)",
    '충전·결제 문장'
);

$dupNarr = $proc->formatPaymentNarratives([
    [
        'payment_date'   => '2026-08-19',
        'description'    => 'X Developer API 충전 $55',
        'currency'       => 'USD',
        'amount_foreign' => 55,
        'item_kind'      => 'CHARGE',
    ],
    [
        'payment_date'   => '2026-08-23',
        'description'    => 'Rocket API Startup Plan €99.00',
        'currency'       => 'EUR',
        'amount_foreign' => 99,
        'item_kind'      => 'PAY',
    ],
]);
expect_eq(
    $dupNarr,
    "충전 > X Developer API 충전 $55 (8/19)\n결제 > Rocket API Startup Plan €99 (8/23)",
    '설명에 있던 금액은 해당 통화 기호로만 한 번'
);
expect_true(!str_contains($dupNarr, '$99'), 'EUR 줄에 달러 기호 없음');

$inferNarr = $proc->formatPaymentNarratives([
    [
        'payment_date'   => '2026-08-23',
        'description'    => 'Rocket API Startup Plan €99.00',
        'currency'       => 'USD',
        'amount_foreign' => 99,
        'item_kind'      => 'PAY',
    ],
]);
expect_eq($inferNarr, '결제 > Rocket API Startup Plan €99 (8/23)', '설명의 유로 기호를 따른다');

$bareNarr = $proc->formatPaymentNarratives([
    [
        'payment_date'   => '',
        'description'    => '서버 사용료',
        'currency'       => 'KRW',
        'amount_foreign' => 120000,
        'item_kind'      => '',
    ],
]);
expect_eq($bareNarr, '서버 사용료 ₩120,000', '결제일·구분 없으면 생략');

$kinxBody = $proc->compose(
    '{year}년 {month_pad}월 클라우드 서버 이용에 대한 비용 건 (애드테크)',
    "1. 제 목 : {title}\n2. 업체/작업자 : {vendors}\n3. 기 간 : {period}\n4. 내 용 : {year}년 {month_pad}월 클라우드 애드테크팀 서버 이용료\n5. 금 액 : {amount_korean} (￦{amount_krw}) 부가세(포함)\n6. 결제방식 : {payment_method}\n7. 지급요청일 : {pay_request}\n8. 첨부서류 : {attachment}",
    '2026-07',
    [[
        'payment_date'   => '2026-08-14',
        'description'    => '클라우드 서버',
        'currency'       => 'KRW',
        'amount_foreign' => 830578,
        'amount_krw'     => 830578,
        'rate_ok'        => true,
    ]],
    [
        'title'           => 'KINX',
        'vendor'          => '(주)케이아이엔엑스',
        'payment_method'  => '자동이체',
        'pay_request'     => '{next_year}-{next_month_pad}-14',
        'attachment'      => '거래명세서 첨부',
        'content_mode'    => 'LINES',
    ]
);
expect_eq(
    $kinxBody['title'],
    '2026년 07월 클라우드 서버 이용에 대한 비용 건 (애드테크)',
    '제목 month_pad'
);
expect_true(str_contains($kinxBody['body'], '1. 제 목 : 2026년 07월 클라우드 서버 이용에 대한 비용 건 (애드테크)'), '본문 제목은 완성 제목');
expect_true(str_contains($kinxBody['body'], '(주)케이아이엔엑스'), '업체');
expect_true(str_contains($kinxBody['body'], '2026-07-01 ~ 2026-07-31'), '기간 기본');
expect_true(str_contains($kinxBody['body'], '일금 팔십 삼만 오백 칠십 팔 원정'), '한글 금액');
expect_true(str_contains($kinxBody['body'], '￦830,578'), '원화 쉼표');
expect_true(str_contains($kinxBody['body'], '2026-08-14'), '다음달 14일 지급요청');

$last = $proc->compose(
    '{month}월 개발툴',
    "3. 내 용 :\n{contents}\n5. 금 액 : {amount_korean} ({amount_krw})",
    '2026-08',
    [[
        'payment_date'   => '2026-08-07',
        'description'    => 'Cursor Ultra',
        'currency'       => 'USD',
        'amount_foreign' => 200,
        'amount_krw'     => 268860,
        'rate_ok'        => true,
    ]],
    [
        'title'         => '개발툴',
        'content_mode'  => 'LAST',
        'contents_text' => "1) Cursor Ultra 2인\n박은혜 $60",
    ]
);
expect_true(str_contains($last['body'], '박은혜 $60'), 'LAST 내용은 직접 글');
expect_true(!str_contains($last['body'], '충전 >'), 'LAST는 결제 문장을 내용에 넣지 않음');
expect_true(str_contains($last['body'], '일금 이십 육만 팔천 팔백 육십 원정'), 'LAST여도 한글 금액');

$fx = $proc->formatExchangeRates([
    [
        'currency'      => 'USD',
        'exchange_rate' => 1411,
        'rate_date'     => '2026-08-19',
        'rate_ok'       => true,
    ],
    [
        'currency'      => 'EUR',
        'exchange_rate' => 1627.72,
        'rate_date'     => '2026-08-21',
        'rate_ok'       => true,
    ],
    [
        'currency' => 'KRW',
        'rate_ok'  => true,
    ],
]);
expect_eq($fx, '달러 1,411.00 (8/19) / 유로 1,627.72 (8/21)', '외화 환율 요약');

$krwRateBody = $proc->compose(
    '{title}',
    "5. 금 액 : {amount_korean} ({amount_krw})\n           (매매기준율 {exchange_rates})\n{item_krw_lines}",
    '2026-08',
    [[
        'description'    => '서버 사용료',
        'currency'       => 'KRW',
        'amount_foreign' => 120000,
        'amount_krw'     => 120000,
        'rate_ok'        => true,
    ]],
    ['title' => 'KINX']
);
expect_true(!str_contains($krwRateBody['body'], '매매기준율'), '원화만이면 매매기준율 없음');
expect_true(str_contains($krwRateBody['body'], '120,000'), '원화 금액은 유지');
expect_true(str_contains($krwRateBody['body'], '서버 사용료 : 120,000원'), '줄별 원화는 유지');

$fxRateBody = $proc->compose(
    '{title}',
    "5. 금 액 : {amount_korean} ({amount_krw})\n           (매매기준율 {exchange_rates})",
    '2026-08',
    [[
        'description'    => 'Cursor Ultra',
        'currency'       => 'USD',
        'amount_foreign' => 200,
        'amount_krw'     => 268860,
        'exchange_rate'  => 1344.3,
        'rate_date'      => '2026-08-07',
        'rate_ok'        => true,
    ]],
    ['title' => '개발툴']
);
expect_true(str_contains($fxRateBody['body'], '매매기준율 달러 1,344.30 (8/7)'), '외화면 매매기준율 유지');

$devItems = [
    [
        'description'    => '박은혜 (cursor) - 1개월 (Pro+)',
        'currency'       => 'USD',
        'amount_foreign' => 60,
        'amount_krw'     => 80466,
        'rate_ok'        => true,
    ],
    [
        'description'    => '임호재 (cursor)  - 1개월 (Ultra)',
        'currency'       => 'USD',
        'amount_foreign' => 200,
        'amount_krw'     => 268220,
        'rate_ok'        => true,
    ],
    [
        'description'    => '박은혜 (Claude) - 1개월 (Max Plan)',
        'currency'       => 'USD',
        'amount_foreign' => 110,
        'amount_krw'     => 147521,
        'rate_ok'        => true,
    ],
    [
        'description'    => '주진돈 (Claude)- 1개월 (Max Plan)',
        'currency'       => 'USD',
        'amount_foreign' => 200,
        'amount_krw'     => 268220,
        'rate_ok'        => true,
    ],
];
$grouped = $proc->compose(
    '{title}',
    "3. 내 용 :\n{payment_lines}\n5. 금 액 :\n{item_krw_lines}",
    '2026-08',
    $devItems,
    [
        'title'         => '개발툴',
        'vendor'        => 'Anysphere(Cursor), Anthropic (Claude)',
        'content_mode'  => 'LINES',
    ]
);
expect_true(str_contains($grouped['body'], "Anysphere(Cursor)\n박은혜 (cursor) - 1개월 (Pro+) $60\n임호재 (cursor)  - 1개월 (Ultra) $200\n소계 $260"), '내용 회사 블록·외화 소계');
expect_true(str_contains($grouped['body'], "Anthropic (Claude)\n박은혜 (Claude) - 1개월 (Max Plan) $110\n주진돈 (Claude)- 1개월 (Max Plan) $200\n소계 $310"), 'Claude 블록');
expect_true(str_contains($grouped['body'], '박은혜 : 227,987원 = 80,466원(Cursor) + 147,521원(Claude)'), '인당 합계가 앞');
expect_true(str_contains($grouped['body'], '임호재 : 268,220원(Cursor)'), '한 건은 식 없이');
expect_true(str_contains($grouped['body'], '주진돈 : 268,220원(Claude)'), '한 건 Claude');
expect_true(!str_contains($grouped['body'], '주진돈 : 268,220원(Claude) ='), '한 건은 등호 없음');

$noVendor = $proc->compose(
    '{title}',
    "{contents}\n{item_krw_lines}",
    '2026-08',
    $devItems,
    ['title' => '개발툴', 'content_mode' => 'LINES']
);
expect_true(!str_contains($noVendor['body'], '소계 $260'), '업체 없으면 나열');
expect_true(str_contains($noVendor['body'], '박은혜 (cursor) - 1개월 (Pro+) : 80,466원'), '업체 없으면 줄별 원화');

$lastGrouped = $proc->compose(
    '{title}',
    "{contents}\n{item_krw_lines}",
    '2026-08',
    $devItems,
    [
        'title'         => '개발툴',
        'vendor'        => 'Anysphere(Cursor), Anthropic (Claude)',
        'content_mode'  => 'LAST',
        'contents_text' => "1) Cursor Ultra 2인\n박은혜 $60",
    ]
);
expect_true(str_contains($lastGrouped['body'], '박은혜 $60'), 'LAST 내용은 직접 글');
expect_true(!str_contains($lastGrouped['body'], '소계 $260'), 'LAST는 회사 블록 없음');
expect_true(str_contains($lastGrouped['body'], '박은혜 : 227,987원 = 80,466원(Cursor) + 147,521원(Claude)'), 'LAST여도 금액은 인당 합산');

expect_eq(
    $proc->copyTitle('[2026년 8월] Cursor AI, Claude 구독료 결제 기안', 'Cursor AI, Claude 구독료'),
    '[기안서] 2026년 8월 Cursor AI, Claude 구독료 결제 기안',
    '일반 문서는 [기안서]만 대괄호'
);
expect_eq(
    $proc->copyTitle('[기안서] [2026년 8월] 이미 붙음', 'Cursor AI'),
    '[기안서] 2026년 8월 이미 붙음',
    '이미 [기안서]면 년월 대괄호만 벗김'
);
expect_eq(
    $proc->copyTitle('[2026년] 도메인 갱신 기안', '도메인 및 SSL 인증서 갱신'),
    '[기안서] 2026년 도메인 갱신 기안',
    '연간 제목도 년 대괄호 없음'
);
expect_eq(
    $proc->copyTitle('[2026년 8월] 팀비정산', '팀비정산'),
    '2026년 8월 팀비정산',
    '팀비는 접두어 없고 년월 대괄호만 벗김'
);
expect_eq(
    $proc->copyTitle('[2026년 8월] 팀 운영비 정산', '팀 운영비 정산'),
    '2026년 8월 팀 운영비 정산',
    '팀 운영비도 접두어 없음'
);
expect_eq($proc->copyTitle('  ', 'Cursor AI'), '', '빈 제목은 그대로');

$teamItems = [
    [
        'payment_date'   => '2026-07-16',
        'description'    => "팀\t회식",
        'vendor'         => "식당A\n본점",
        'note'           => '프로젝트X',
        'amount_krw'     => 23600,
    ],
    [
        'payment_date'   => '2026-07-20',
        'description'    => '간식',
        'vendor'         => '편의점',
        'note'           => '',
        'amount_krw'     => 8000,
    ],
];
expect_eq(
    $proc->formatTeamExpenseTsv($teamItems),
    "7/16\t팀 회식\t식당A 본점\t23,600\t프로젝트X\n7/20\t간식\t편의점\t8,000\t",
    '팀비 본문은 날짜·적요·거래처·금액·비고 탭 구분'
);
expect_eq($proc->formatTeamExpenseTsv([]), '', '팀비 줄이 없으면 빈 본문');

$teamBody = $proc->compose(
    '[{year}년 {month}월] 팀 운영비 정산 결제 기안',
    "1. 건명: {title}\n4. 결제금액: {payments}",
    '2026-07',
    $teamItems,
    ['title' => '팀 운영비 정산']
);
expect_eq(
    $teamBody['body'],
    "7/16\t팀 회식\t식당A 본점\t23,600\t프로젝트X\n7/20\t간식\t편의점\t8,000\t",
    '팀비 compose는 기안서 뼈대 없이 탭만'
);
expect_true(!str_contains($teamBody['body'], '건명'), '팀비 본문에 건명 없음');

$applied = $proc->applyDraftBody(
    "1. 건명: {title}\n{contents}",
    '2026-07',
    $teamItems,
    ['title' => '팀비정산']
);
expect_eq(
    $applied,
    "7/16\t팀 회식\t식당A 본점\t23,600\t프로젝트X\n7/20\t간식\t편의점\t8,000\t",
    '팀비 applyDraftBody도 탭만'
);

tbb_test_done();
