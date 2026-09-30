<?php
/**
 * 영수증/인보이스 이미지·PDF 를 Claude 비전으로 분석해 결제 항목 필드를 추출한다.
 * 원본 파일은 저장하지 않는다(메모리 처리 후 폐기).
 *
 * @date 2026-09-15
 */

declare(strict_types=1);

class ReceiptAnalyzer
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** @var callable */
    private $http;

    public function __construct(
        private string $apiKey,
        private string $model = 'claude-opus-4-8',
        ?callable $http = null
    ) {
        $this->http = $http ?? [self::class, 'curlPost'];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{items: list<array<string, mixed>>, warnings: list<string>}
     */
    public function analyze(string $bytes, string $mime, array $context = []): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('CLAUDE_API_KEY 가 설정되어 있지 않습니다.');
        }
        $mime = strtolower(trim($mime));
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new InvalidArgumentException('지원하지 않는 파일 형식입니다: ' . $mime);
        }

        $source = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];

        $request = [
            'model'      => $this->model,
            'max_tokens' => 1500,
            'tools'      => [[
                'name'         => 'extract_receipt',
                'description'  => '영수증/인보이스/세금계산서에서 결제 항목 필드를 추출한다. 불확실한 값은 반드시 null 로 둔다. 값을 지어내지 말 것.',
                'input_schema' => self::schema(),
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'extract_receipt'],
            'messages'    => [[
                'role'    => 'user',
                'content' => [$source, ['type' => 'text', 'text' => $this->prompt($context)]],
            ]],
        ];

        $res = ($this->http)(self::ENDPOINT, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ], json_encode($request, JSON_UNESCAPED_UNICODE));

        if (($res['error'] ?? '') !== '' || ($res['body'] ?? '') === '') {
            throw new RuntimeException('분석 요청 실패: ' . (($res['error'] ?? '') !== '' ? $res['error'] : '응답 없음'));
        }
        $json = json_decode((string) $res['body'], true);
        if (($res['status'] ?? 0) !== 200) {
            $msg = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
            throw new RuntimeException('분석 실패(HTTP ' . ($res['status'] ?? 0) . '): ' . ($msg !== '' ? $msg : mb_substr((string) $res['body'], 0, 200)));
        }

        $input = null;
        foreach (($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'extract_receipt') {
                $input = $block['input'] ?? null;
                break;
            }
        }
        if (!is_array($input)) {
            throw new RuntimeException('추출 결과를 해석하지 못했습니다.');
        }

        return $this->normalize($input);
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'description'  => ['type' => ['string', 'null']],
                            'amount'       => ['type' => ['number', 'null']],
                            'currency'     => ['type' => ['string', 'null']],
                            'payment_date' => ['type' => ['string', 'null']],
                            'vendor'       => ['type' => ['string', 'null']],
                            'confidence'   => ['type' => ['number', 'null']],
                        ],
                        'required'             => ['description', 'amount', 'currency', 'payment_date', 'vendor'],
                        'additionalProperties' => false,
                    ],
                ],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'email'    => ['type' => ['string', 'null']],
            ],
            'required'             => ['items', 'warnings'],
            'additionalProperties' => false,
        ];
    }

    /** @param array<string, mixed> $context */
    private function prompt(array $context): string
    {
        $lines = [
            '이 문서는 결제 영수증/인보이스/세금계산서/거래명세서입니다. 결제 항목을 추출하세요.',
            '규칙:',
            '- 항목 분리: 거래명세서·세금계산서처럼 여러 품목/세부 비용이 나열되면 각 줄(품목)을 별도 item 으로 각각 추출한다(예: 서버 호스팅, 트래픽, 도메인 등을 개별 item 으로). 합계·소계·총액 줄은 item 으로 만들지 말 것. 단일 결제면 item 1개.',
            '- description(내용)은 결제 항목을 구분하는 핵심 명칭만 한 줄로. 거래명세서·세금계산서에 "세부사항"(계정·인스턴스·항목명 등)이 따로 있으면 그 세부사항만 쓴다(카테고리성 서비스명 "Cloud > IXcloud" 등은 벤더사로 충분하니 생략). 세부사항이 없으면 상품/플랜명을 쓴다. 예: 세부사항 "innored-tech" → "innored-tech"; 세부사항 없으면 "Startup Plan"·"Cursor Pro Plus". 사용기간·날짜·금액·수량·부가세, 결제 식별자·주문번호·UUID/해시는 넣지 말 것.',
            '- vendor(거래처)는 판매자/공급자의 "회사명(상호)"만 짧게. 상품·플랜·서비스명·설명·품목은 절대 넣지 말 것(그건 description). 예: "Cursor", "Amazon Web Services", "OpenAI", "(주)케이아이엔엑스". 세금계산서·거래명세서는 공급자(파는 쪽, 공급받는자 아님) 상호만.',
            '- 금액(amount)은 부가세가 포함된 "최종 결제 금액"을 통화 기준 숫자로. 세금계산서·거래명세서에서 "공급가액"과 "부가세(세액)"가 따로 있으면 둘을 합친 합계/총계/결제금액을 쓴다(공급가액만 쓰지 말 것). 예: 공급가액 830,466 + 부가세 83,046 → amount 913,512. 항목이 여러 개면 각 항목도 부가세 포함 금액으로. currency 는 KRW/USD/EUR/JPY 중 하나.',
            '- payment_date 는 YYYY-MM-DD 형식. 문서의 발행일/결제일/청구일(문서 상단의 대표 날짜) 하나를 사용하며, 한 문서에서 나온 모든 item 은 반드시 같은 날짜를 쓴다. 품목별 서비스 이용기간(예: Aug 16–Sep 16)은 payment_date 로 쓰지 말 것.',
            '- 확실하지 않은 값만 null. description·vendor 는 문서에 있으면 가급적 채울 것. 통화가 4종 외면 warnings 에 남길 것.',
            '- email: 청구 대상 이메일을 추출. "Bill to", "Billed to", "Sent to", "Receipt to", "청구지", "받는사람" 등에 적힌 이메일 주소를 우선한다. 개인·회사 이메일 모두 포함. 문서에 이메일이 전혀 없을 때만 null.',
        ];
        if (($context['vendor'] ?? '') !== '') {
            $lines[] = '- 예상 거래처: ' . (string) $context['vendor'];
        }
        if (($context['currency'] ?? '') !== '') {
            $lines[] = '- 예상 통화: ' . (string) $context['currency'];
        }
        if (($context['title'] ?? '') !== '') {
            $lines[] = '- 관련 기안: ' . (string) $context['title'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{items: list<array<string, mixed>>, warnings: list<string>}
     */
    private function normalize(array $input): array
    {
        $warnings = [];
        foreach (($input['warnings'] ?? []) as $w) {
            $warnings[] = (string) $w;
        }
        $items = [];
        foreach (($input['items'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $currency = ($raw['currency'] ?? null) === null ? null : strtoupper(trim((string) $raw['currency']));
            $date = ($raw['payment_date'] ?? null) === null ? null : trim((string) $raw['payment_date']);
            if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                $warnings[] = '결제일 형식을 인식하지 못했습니다: ' . $date;
                $date = null;
            }
            $items[] = [
                'description'  => ($raw['description'] ?? null) === null ? null : (string) $raw['description'],
                'amount'       => ($raw['amount'] ?? null) === null ? null : (float) $raw['amount'],
                'currency'     => $currency,
                'payment_date' => $date,
                'vendor'       => ($raw['vendor'] ?? null) === null ? null : (string) $raw['vendor'],
                'confidence'   => isset($raw['confidence']) && $raw['confidence'] !== null ? (float) $raw['confidence'] : null,
            ];
        }

        $email = null;
        if (isset($input['email']) && $input['email'] !== null) {
            $e = trim((string) $input['email']);
            if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e) === 1) {
                $email = $e;
            }
        }

        return ['items' => $items, 'warnings' => $warnings, 'email' => $email];
    }

    /** @return array{status: int, body: string, error: string} */
    private static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($out) ? $out : '', 'error' => $error];
    }
}
