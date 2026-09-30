<?php
/**
 * 기안서 제목·본문 플레이스홀더 치환
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/DraftTextProcessor.php
 */

declare(strict_types=1);

class DraftTextProcessor
{
    private KoreanWonProcessor $korean;

    public function __construct(?KoreanWonProcessor $korean = null)
    {
        $this->korean = $korean ?? new KoreanWonProcessor();
    }

    /**
     * @param array<string, scalar|null> $vars
     */
    public function render(string $pattern, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $m) use ($vars): string {
                $key = $m[1];
                if (!array_key_exists($key, $vars)) {
                    return $m[0];
                }
                $value = $vars[$key];

                return $value === null ? '' : (string) $value;
            },
            $pattern
        );
    }

    /**
     * @return array<string, string>
     */
    public function yearMonthVars(string $yearMonth): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m) !== 1) {
            throw new InvalidArgumentException('대상 월은 YYYY-MM 형식이어야 합니다.');
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('Asia/Seoul'));
        $next = $start->modify('first day of next month');

        return [
            'year'           => (string) $year,
            'month'          => (string) $month,
            'month_pad'      => sprintf('%02d', $month),
            'last_day'       => $start->format('t'),
            'next_year'         => $next->format('Y'),
            'next_month_year'   => $next->format('Y'),
            'next_month'        => (string) (int) $next->format('n'),
            'next_month_pad'    => $next->format('m'),
        ];
    }

    /** 대상 월의 연·월 토큰만 치환한다. 그 외 `{...}` 는 그대로 둔다. */
    public function applyYearMonth(string $text, string $yearMonth): string
    {
        return $this->render($text, $this->yearMonthVars($yearMonth));
    }

    public function shiftDateToMonth(string $date, string $yearMonth): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) !== 1) {
            return $date;
        }
        $vars = $this->yearMonthVars($yearMonth);
        $last = (int) $vars['last_day'];
        $day = min((int) $d[3], $last);

        return sprintf('%s-%s-%02d', $vars['year'], $vars['month_pad'], $day);
    }

    public function shiftYearMonthInText(string $text, string $fromYm, string $toYm): string
    {
        $from = $this->yearMonthVars($fromYm);
        $to = $this->yearMonthVars($toYm);
        $out = str_replace($fromYm, $toYm, $text);
        $out = str_replace($from['month_pad'] . '월', $to['month_pad'] . '월', $out);
        $out = preg_replace(
            '/(?<!\d)' . preg_quote($from['month'], '/') . '월/u',
            $to['month'] . '월',
            $out
        ) ?? $out;
        $out = preg_replace(
            '/(?<!\d)' . preg_quote($from['month'], '/') . '\//u',
            $to['month'] . '/',
            $out
        ) ?? $out;

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function formatPaymentLines(array $items): string
    {
        if ($items === []) {
            return '(결제 항목을 입력하세요)';
        }

        $lines = [];
        $sum = 0;
        foreach ($items as $item) {
            $sum += (int) ($item['amount_krw'] ?? 0);
            $lines[] = $this->formatPaymentLine($item);
        }
        $lines[] = '합계 ' . number_format($sum) . '원';

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function formatPaymentNarratives(array $items): string
    {
        if ($items === []) {
            return '(결제 항목을 입력하세요)';
        }
        $lines = [];
        foreach ($items as $item) {
            $lines[] = $this->formatNarrativeLine($item);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function formatExchangeRates(array $items): string
    {
        $parts = [];
        $seen = [];
        foreach ($items as $item) {
            $currency = strtoupper(trim((string) ($item['currency'] ?? 'KRW')));
            if ($currency === 'KRW' || ($item['rate_ok'] ?? true) !== true) {
                continue;
            }
            if (isset($seen[$currency])) {
                continue;
            }
            $seen[$currency] = true;
            $rate = $this->formatForeign((float) ($item['exchange_rate'] ?? 0), true);
            $when = $this->mdDate((string) ($item['rate_date'] ?? $item['payment_date'] ?? ''));
            $part = $this->currencyLabel($currency) . ' ' . $rate;
            if ($when !== '') {
                $part .= ' (' . $when . ')';
            }
            $parts[] = $part;
        }

        return implode(' / ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function formatItemKrwLines(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $desc = $this->stripMoneySuffix(trim((string) ($item['description'] ?? '')));
            if ($desc === '') {
                $desc = '항목';
            }
            $krw = number_format((int) ($item['amount_krw'] ?? 0));
            $lines[] = $desc . ' : ' . $krw . '원';
        }

        return implode("\n", $lines);
    }

    /**
     * 제목을 먼저 만들고, 본문의 `{title}`에는 그 제목을 넣는다.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, scalar|null> $vars 제목 패턴의 `{title}`은 건명
     * @return array{title: string, body: string}
     */
    public function compose(string $titlePattern, string $bodyPattern, string $yearMonth, array $items, array $vars = []): array
    {
        $dates = $this->yearMonthVars($yearMonth);
        $title = $this->render($this->render($titlePattern, $dates), $vars);
        if ($this->isTeamExpenseTitle((string) ($vars['title'] ?? ''))) {
            return [
                'title' => $title,
                'body'  => $this->formatTeamExpenseTsv($items),
            ];
        }
        $tokens = $this->bodyTokens($yearMonth, $items, $vars, $title);
        $body = $this->render($bodyPattern, $tokens);
        $body = $this->omitEmptyExchangeRates($body, $tokens['exchange_rates']);
        $body = $this->applyPaymentsFallback($body, $this->formatPaymentLines($items));

        return [
            'title' => $title,
            'body'  => $body,
        ];
    }

    /**
     * 뼈대 본문에 연·월·템플릿 값과 결제 줄을 넣는다.
     * `{contents}` 또는 `{payments}`가 있으면 그 자리만 채우고, 없으면 결제금액 칸만 채운다.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, scalar|null> $vars
     */
    public function applyDraftBody(string $pattern, string $yearMonth, array $items, array $vars = []): string
    {
        $title = (string) ($vars['title'] ?? '');
        if ($this->isTeamExpenseTitle($title)) {
            return $this->formatTeamExpenseTsv($items);
        }
        $tokens = $this->bodyTokens($yearMonth, $items, $vars, $title);
        $body = $this->render($pattern, $tokens);
        $body = $this->omitEmptyExchangeRates($body, $tokens['exchange_rates']);

        return $this->applyPaymentsFallback($body, $this->formatPaymentLines($items));
    }

    public function applyPayments(string $body, string $block): string
    {
        $replaced = false;
        foreach (['{contents}', '{payments}'] as $token) {
            if (str_contains($body, $token)) {
                $body = str_replace($token, $block, $body);
                $replaced = true;
            }
        }
        if ($replaced) {
            return $body;
        }

        return $this->applyPaymentsFallback($body, $block);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, scalar|null> $vars
     * @return array<string, string>
     */
    private function bodyTokens(string $yearMonth, array $items, array $vars, string $composedTitle): array
    {
        $dates = $this->yearMonthVars($yearMonth);
        $sum = 0;
        foreach ($items as $item) {
            $sum += (int) ($item['amount_krw'] ?? 0);
        }
        $mode = strtoupper(trim((string) ($vars['content_mode'] ?? 'LINES')));
        $templateVendor = trim((string) ($vars['vendor'] ?? $vars['vendors'] ?? ''));
        $grouped = $this->canGroupByVendors($items, $templateVendor);
        $narratives = $this->formatPaymentNarratives($items);
        $groupedNarr = $grouped
            ? $this->formatGroupedNarratives($items, $templateVendor)
            : $narratives;
        $contents = $mode === 'LAST'
            ? (string) ($vars['contents_text'] ?? '')
            : $groupedNarr;
        $vendor = $templateVendor !== '' ? $templateVendor : $this->vendorsFromItems($items);
        $periodPattern = trim((string) ($vars['period_pattern'] ?? ''));
        $period = $periodPattern === ''
            ? '{year}-{month_pad}-01 ~ {year}-{month_pad}-{last_day}'
            : $periodPattern;
        $payRequest = (string) ($vars['pay_request'] ?? $vars['pay_request_pattern'] ?? '');
        $paymentMethod = (string) ($vars['payment_method'] ?? $vars['payment_method_text'] ?? '');
        $attachment = (string) ($vars['attachment'] ?? $vars['attachment_text'] ?? '');

        $tokens = $dates + [
            'title'           => $composedTitle,
            'assignee'        => (string) ($vars['assignee'] ?? ''),
            'account_info'    => (string) ($vars['account_info'] ?? ''),
            'note'            => (string) ($vars['note'] ?? ''),
            'vendors'         => $vendor,
            'vendor'          => $vendor,
            'period'          => $this->render($period, $dates),
            'payment_lines'   => $groupedNarr,
            'contents'        => $contents,
            'payments'        => $this->formatPaymentLines($items),
            'amount_korean'   => $this->korean->formal($sum),
            'amount_krw'      => number_format($sum),
            'exchange_rates'  => $this->formatExchangeRates($items),
            'item_krw_lines'  => $grouped
                ? $this->formatPersonKrwLines($items, $templateVendor)
                : $this->formatItemKrwLines($items),
            'payment_method'  => $this->render($paymentMethod, $dates),
            'pay_request'     => $this->render($payRequest, $dates),
            'attachment'      => $this->render($attachment, $dates),
        ];
        foreach ($vars as $key => $value) {
            if (!array_key_exists($key, $tokens) && is_scalar($value)) {
                $tokens[$key] = (string) $value;
            }
        }

        return $tokens;
    }

    /** 한 줄 결제 문장. 구분·결제일 없으면 생략한다. */
    private function formatNarrativeLine(array $item): string
    {
        $kind = strtoupper(trim((string) ($item['item_kind'] ?? '')));
        $label = $kind === 'CHARGE' ? '충전' : ($kind === 'PAY' ? '결제' : '');
        $currency = $this->itemCurrency($item);
        $desc = $this->stripMoneySuffix(trim((string) ($item['description'] ?? '')));
        $amount = $this->formatForeign((float) ($item['amount_foreign'] ?? 0));
        $symbol = $this->currencySymbol($currency);
        $when = $this->mdDate((string) ($item['payment_date'] ?? ''));
        $head = $label === '' ? $desc : trim($label . ' > ' . $desc);
        $line = trim($head . ' ' . $symbol . $amount);

        return $when === '' ? $line : $line . ' (' . $when . ')';
    }

    /**
     * 템플릿 업체가 둘 이상이고, 내용 괄호로 그중 두 곳 이상 잡히면 묶는다.
     *
     * @param list<array<string, mixed>> $items
     */
    private function canGroupByVendors(array $items, string $vendorField): bool
    {
        $vendors = $this->splitVendors($vendorField);
        if (count($vendors) < 2) {
            return false;
        }
        $hits = [];
        foreach ($items as $item) {
            $hit = $this->matchVendor($this->itemCompanyKey($item), $vendors);
            if ($hit !== null) {
                $hits[$hit] = true;
            }
        }

        return count($hits) >= 2;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function formatGroupedNarratives(array $items, string $vendorField): string
    {
        $vendors = $this->splitVendors($vendorField);
        $buckets = [];
        foreach ($vendors as $vendor) {
            $buckets[$vendor] = [];
        }
        $other = [];
        foreach ($items as $item) {
            $hit = $this->matchVendor($this->itemCompanyKey($item), $vendors);
            if ($hit === null) {
                $other[] = $item;
            } else {
                $buckets[$hit][] = $item;
            }
        }
        $blocks = [];
        foreach ($vendors as $vendor) {
            if ($buckets[$vendor] === []) {
                continue;
            }
            $lines = [$vendor];
            foreach ($buckets[$vendor] as $item) {
                $lines[] = $this->formatNarrativeLine($item);
            }
            $lines[] = $this->formatGroupSubtotal($buckets[$vendor]);
            $blocks[] = implode("\n", $lines);
        }
        if ($other !== []) {
            $lines = ['기타'];
            foreach ($other as $item) {
                $lines[] = $this->formatNarrativeLine($item);
            }
            $lines[] = $this->formatGroupSubtotal($other);
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function formatGroupSubtotal(array $items): string
    {
        $byCur = [];
        foreach ($items as $item) {
            $cur = $this->itemCurrency($item);
            $byCur[$cur] = ($byCur[$cur] ?? 0.0) + (float) ($item['amount_foreign'] ?? 0);
        }
        $parts = [];
        foreach ($byCur as $cur => $amount) {
            if ($cur === 'KRW') {
                $parts[] = number_format((int) round($amount)) . '원';
            } else {
                $parts[] = $this->currencySymbol($cur) . $this->formatForeign($amount);
            }
        }

        return '소계 ' . implode(' / ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function formatPersonKrwLines(array $items, string $vendorField): string
    {
        $vendors = $this->splitVendors($vendorField);
        $people = [];
        $order = [];
        foreach ($items as $item) {
            $person = $this->itemPersonName($item);
            if ($person === '') {
                $person = '항목';
            }
            if (!isset($people[$person])) {
                $people[$person] = [];
                $order[] = $person;
            }
            $key = $this->itemCompanyKey($item);
            $hit = $this->matchVendor($key, $vendors);
            $label = $hit !== null
                ? $this->vendorShortLabel($key, $hit)
                : $key;
            $people[$person][] = [
                'krw'   => (int) ($item['amount_krw'] ?? 0),
                'label' => $label,
            ];
        }
        $lines = [];
        foreach ($order as $person) {
            $parts = $people[$person];
            $bits = [];
            $sum = 0;
            foreach ($parts as $part) {
                $sum += $part['krw'];
                $bit = number_format($part['krw']) . '원';
                if ($part['label'] !== '') {
                    $bit .= '(' . $part['label'] . ')';
                }
                $bits[] = $bit;
            }
            if (count($bits) === 1) {
                $lines[] = $person . ' : ' . $bits[0];
            } else {
                $lines[] = $person . ' : ' . number_format($sum) . '원 = ' . implode(' + ', $bits);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function splitVendors(string $field): array
    {
        $out = [];
        foreach (preg_split('/\s*,\s*/u', $field) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $vendors
     */
    private function matchVendor(string $key, array $vendors): ?string
    {
        if ($key === '') {
            return null;
        }
        foreach ($vendors as $vendor) {
            if (mb_stripos($vendor, $key, 0, 'UTF-8') !== false) {
                return $vendor;
            }
        }

        return null;
    }

    private function vendorShortLabel(string $key, string $vendor): string
    {
        if ($key === '') {
            return '';
        }
        $pos = mb_stripos($vendor, $key, 0, 'UTF-8');
        if ($pos === false) {
            return $key;
        }

        return mb_substr($vendor, $pos, mb_strlen($key, 'UTF-8'), 'UTF-8');
    }

    private function itemPersonName(array $item): string
    {
        return $this->parseItemHead($item)['name'];
    }

    private function itemCompanyKey(array $item): string
    {
        return $this->parseItemHead($item)['key'];
    }

    /**
     * @return array{name: string, key: string}
     */
    private function parseItemHead(array $item): array
    {
        $desc = $this->stripMoneySuffix(trim((string) ($item['description'] ?? '')));
        if ($desc === '') {
            return ['name' => '', 'key' => ''];
        }
        if (preg_match('/^(.+?)\s*[\(（]([^\)）]+)[\)）]/u', $desc, $m) === 1) {
            return ['name' => trim($m[1]), 'key' => trim($m[2])];
        }

        return ['name' => $desc, 'key' => ''];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function vendorsFromItems(array $items): string
    {
        $names = [];
        foreach ($items as $item) {
            // 업체명은 vendor 필드 우선(내용 혼입 방지). 없으면 description으로 폴백(구 데이터 호환).
            $vendor = trim((string) ($item['vendor'] ?? ''));
            $name = $vendor !== '' ? $vendor : $this->stripMoneySuffix(trim((string) ($item['description'] ?? '')));
            if ($name === '' || in_array($name, $names, true)) {
                continue;
            }
            $names[] = $name;
        }

        return implode(', ', $names);
    }

    /** 원화만이면 빈 매매기준율 칸을 본문에서 뺀다. */
    private function omitEmptyExchangeRates(string $body, string $rates): string
    {
        if (trim($rates) !== '') {
            return $body;
        }

        $lines = preg_split("/\R/u", $body);
        if (!is_array($lines)) {
            return $body;
        }
        $kept = [];
        foreach ($lines as $line) {
            $stripped = preg_replace('/[ \t]*[\(（]\s*매매기준율\s*[\)）]/u', '', $line) ?? $line;
            $trimmed = trim($stripped);
            if ($trimmed === '' && preg_match('/매매기준율/u', $line) === 1) {
                continue;
            }
            if (preg_match('/^(?:\d+\.\s*)?매매기준율\s*[:：]?\s*$/u', $trimmed) === 1) {
                continue;
            }
            $kept[] = $stripped;
        }

        return implode("\n", $kept);
    }

    private function applyPaymentsFallback(string $body, string $block): string
    {
        if (str_contains($body, '{contents}') || str_contains($body, '{payments}') || str_contains($body, '{payment_lines}')) {
            return $body;
        }

        $legacy = preg_replace(
            '/(결제금액\s*:\s*)[^\r\n]*/u',
            '$1' . "\n" . $block,
            $body,
            1,
            $count
        );
        if (is_string($legacy) && $count > 0) {
            return $legacy;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatPaymentLine(array $item): string
    {
        $date = trim((string) ($item['payment_date'] ?? ''));
        $desc = trim((string) ($item['description'] ?? ''));
        $currency = strtoupper(trim((string) ($item['currency'] ?? 'KRW')));
        $foreign = $this->formatForeign((float) ($item['amount_foreign'] ?? 0));
        $krw = number_format((int) ($item['amount_krw'] ?? 0));
        $prefix = trim($date . ' ' . $desc);
        $head = $prefix === '' ? '' : $prefix . ' / ';

        if ($currency === 'KRW') {
            return '- ' . $head . 'KRW ' . $krw . ' (' . $krw . '원)';
        }

        if (($item['rate_ok'] ?? true) !== true) {
            return '- ' . $head . $currency . ' ' . $foreign . ' (예상 원화 0원)';
        }

        $rate = $this->formatForeign((float) ($item['exchange_rate'] ?? 0));

        return '- ' . $head . $currency . ' ' . $foreign . ' (예상 원화 ' . $krw . '원, 환율 ' . $rate . ')';
    }

    private function formatForeign(float $n, bool $keepCents = false): string
    {
        $formatted = number_format($n, 2, '.', ',');
        if ($keepCents) {
            return $formatted;
        }
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /** 설명 끝에 붙어 있는 $55, €99.00 같은 금액을 뺀다. */
    private function stripMoneySuffix(string $text): string
    {
        $out = preg_replace(
            '/\s*(?:[\$€¥₩]|USD|EUR|JPY|KRW)\s*[\d,]+(?:\.\d+)?\s*$/iu',
            '',
            $text
        );

        return trim((string) $out);
    }

    /** 줄 통화를 쓰고, 설명의 €/$ 와 어긋나면 설명 기호를 따른다. */
    private function itemCurrency(array $item): string
    {
        $currency = strtoupper(trim((string) ($item['currency'] ?? '')));
        $desc = (string) ($item['description'] ?? '');
        if (preg_match('/€|\bEUR\b/i', $desc) === 1) {
            return 'EUR';
        }
        if (preg_match('/¥|\bJPY\b/i', $desc) === 1) {
            return 'JPY';
        }
        if (preg_match('/₩|\bKRW\b/i', $desc) === 1) {
            return 'KRW';
        }
        if ($currency !== '') {
            return $currency;
        }
        if (preg_match('/\$|\bUSD\b/i', $desc) === 1) {
            return 'USD';
        }

        return 'KRW';
    }

    private function currencySymbol(string $currency): string
    {
        return match ($currency) {
            'USD' => '$',
            'EUR' => '€',
            'JPY' => '¥',
            default => '₩',
        };
    }

    /**
     * 그룹웨어 표에 붙일 탭 구분 값. 번호·확인·합계는 넣지 않는다.
     *
     * @param list<array<string, mixed>> $items
     */
    public function formatTeamExpenseTsv(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = implode("\t", [
                $this->mdDate((string) ($item['payment_date'] ?? '')),
                $this->tsvCell((string) ($item['description'] ?? '')),
                $this->tsvCell((string) ($item['vendor'] ?? '')),
                $this->tsvAmount($item),
                $this->tsvCell((string) ($item['note'] ?? '')),
            ]);
        }

        return implode("\n", $lines);
    }

    /** 탭·줄바꿈은 칸이 밀리지 않게 공백으로 바꾼다. */
    private function tsvCell(string $value): string
    {
        $value = preg_replace('/[\t\r\n]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** @param array<string, mixed> $item */
    private function tsvAmount(array $item): string
    {
        $krw = (int) ($item['amount_krw'] ?? 0);
        if ($krw <= 0) {
            $raw = $item['amount_foreign'] ?? $item['amount'] ?? 0;
            $krw = (int) round((float) $raw);
        }

        return $krw > 0 ? number_format($krw) : '';
    }

    /** 팀비 정산은 기안서가 아니다. */
    public function isTeamExpenseTitle(string $templateTitle): bool
    {
        return str_contains($templateTitle, '팀비') || str_contains($templateTitle, '팀 운영비');
    }

    /** 그룹웨어에 붙일 제목. 년월 대괄호는 빼고, 팀비 외 문서에 [기안서] 를 붙인다. */
    public function copyTitle(string $title, string $templateTitle): string
    {
        $title = $this->unwrapYearMonthBrackets($title);
        if ($title === '') {
            return '';
        }
        if ($this->isTeamExpenseTitle($templateTitle)) {
            return $title;
        }
        if (str_starts_with($title, '[기안서]')) {
            return $title;
        }

        return '[기안서] ' . $title;
    }

    /** `[2026년 9월]` 같은 년월 대괄호만 벗긴다. */
    private function unwrapYearMonthBrackets(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }
        $unwrapped = preg_replace('/\[(\d{4}년[^\]]*)\]\s*/u', '$1 ', $title);
        $normalized = preg_replace('/\s+/u', ' ', (string) $unwrapped);

        return trim((string) $normalized);
    }

    private function currencyLabel(string $currency): string
    {
        return match ($currency) {
            'USD' => '달러',
            'EUR' => '유로',
            'JPY' => '엔',
            default => $currency,
        };
    }

    private function mdDate(string $date): string
    {
        if (preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return '';
        }

        return ((int) $m[1]) . '/' . ((int) $m[2]);
    }
}
