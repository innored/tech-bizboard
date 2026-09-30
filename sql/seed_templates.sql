INSERT INTO tb_expense_templates (
    title, assignee, account_info, cycle_type, currency, default_amount,
    payment_type, next_renewal_date, title_pattern, filename_pattern, body_pattern, note, is_active
) VALUES
(
    'KINX iXCloud 서버 사용료',
    '베리',
    '수기결제',
    'MONTHLY',
    'KRW',
    NULL,
    'MANUAL',
    NULL,
    '[{year}년 {month}월] KINX iXCloud 서버 사용료 결제 기안',
    '{year_month}_{title}',
    '1. 건명: {title}
2. 담당: {assignee}
3. 계정: {account_info}
4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원, 환율 {exchange_rate} / {rate_date})
5. 비고: {note}',
    '금액은 매월 수기 입력',
    1
),
(
    'Cursor AI, Claude 구독료',
    '단테',
    '개인계정 / 자동결제',
    'MONTHLY',
    'USD',
    200,
    'AUTO',
    NULL,
    '[{year}년 {month}월] Cursor AI, Claude 구독료 결제 기안',
    '{year_month}_{user}_커서·클로드',
    '1. 건명: {title}
2. 담당: {assignee}
3. 계정: {account_info}
4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원, 환율 {exchange_rate} / {rate_date})
5. 비고: {note}',
    NULL,
    1
),
(
    'Rocket API, X Developer 등',
    '루시',
    'developer 계정 / 자동결제',
    'MONTHLY',
    'EUR',
    99,
    'AUTO',
    NULL,
    '[{year}년 {month}월] Rocket API, X Developer 등 결제 기안',
    '{year_month}_{title}',
    '1. 건명: {title}
2. 담당: {assignee}
3. 계정: {account_info}
4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원, 환율 {exchange_rate} / {rate_date})
5. 비고: {note}',
    'Startup Plan 등',
    1
),
(
    'Google Gemini API',
    '단테',
    'developer 계정 / 자동결제',
    'MONTHLY',
    'USD',
    NULL,
    'AUTO',
    NULL,
    '[{year}년 {month}월] Google Gemini API 결제 기안',
    '{year_month}_{title}',
    '1. 건명: {title}
2. 담당: {assignee}
3. 계정: {account_info}
4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원, 환율 {exchange_rate} / {rate_date})
5. 비고: {note}',
    '사용량 변동',
    1
),
(
    '팀 운영비 정산',
    '베리',
    '수기결제',
    'MONTHLY',
    'KRW',
    NULL,
    'MANUAL',
    NULL,
    '[{year}년 {month}월] 팀 운영비 정산 결제 기안',
    NULL,
    '1. 건명: {title}
2. 담당: {assignee}
3. 계정: {account_info}
4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원, 환율 {exchange_rate} / {rate_date})
5. 비고: {note}',
    NULL,
    1
),
(
    '애플 개발자 계정 갱신',
    '단테',
    '연간 / USD $99',
    'YEARLY',
    'USD',
    99,
    'MANUAL',
    NULL,
    '[{year}년] 애플 개발자 계정 갱신 기안',
    NULL,
    '1. 건명: {title}
2. 담당: {assignee}
3. 계정: {account_info}
4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원, 환율 {exchange_rate} / {rate_date})
5. 비고: {note}',
    '만료 D-30 / D-14 알림',
    1
),
(
    '도메인 및 SSL 인증서 갱신',
    '베리',
    'N년 비정기',
    'IRREGULAR',
    'KRW',
    NULL,
    'MANUAL',
    NULL,
    '[{year}년] 도메인 및 SSL 인증서 갱신 기안',
    NULL,
    '1. 건명: {title}
2. 담당: {assignee}
3. 계정: {account_info}
4. 결제금액: {currency} {amount_foreign} (예상 원화 {amount_krw}원, 환율 {exchange_rate} / {rate_date})
5. 비고: {note}',
    '만료 D-30 / D-14 알림',
    1
);
