<?php
/**
 * 기안 작성·조회·금액/상태 수정
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/DraftExpenseProvider.php
 */

declare(strict_types=1);

class DraftExpenseProvider
{
    private string $createdBy = '';
    private ?DateTimeImmutable $now = null;
    private bool $bypassMonthLock = false;
    /** @var array<int, list<int>> draft별 유효 첨부 id 캐시(저장 시 receipt_id 검증용) */
    private array $validReceiptCache = [];

    public function __construct(
        private PDO $pdo,
        private ExpenseTemplateProvider $templates,
        private ExchangeRateProvider $rates,
        private KrwAmountProcessor $krw,
        private DraftTextProcessor $text
    ) {
    }

    public function setCreatedBy(string $email): void
    {
        $this->createdBy = trim($email);
    }

    public function setNow(?DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    /** admin 은 전전월도 고친다. */
    public function setBypassMonthLock(bool $bypass): void
    {
        $this->bypassMonthLock = $bypass;
    }

    public function isWritableMonth(string $yearMonth): bool
    {
        return $this->bypassMonthLock || !self::isMonthLocked($yearMonth, $this->now);
    }

    /**
     * 템플릿+월의 기안을 삭제한다(결제항목은 FK ON DELETE CASCADE). "초기화"(미작성 복귀)용.
     * 삭제된 기안 행 수를 반환한다.
     */
    public function deleteByTemplateMonth(int $templateId, string $yearMonth): int
    {
        $this->assertMonth($yearMonth);
        $this->assertWritableMonth($yearMonth);
        $stmt = $this->pdo->prepare(
            'DELETE FROM tb_draft_expenses WHERE expense_template_id = :t AND target_year_month = :ym'
        );
        $stmt->execute(['t' => $templateId, 'ym' => $yearMonth]);

        return $stmt->rowCount();
    }

    /** 전전월(지난달보다 앞선 달)은 수정할 수 없다. 9월이면 7월부터 잠근다. 매출과 동일 규칙. */
    public static function isMonthLocked(string $yearMonth, ?DateTimeImmutable $now = null): bool
    {
        if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
            return true;
        }

        return $yearMonth < tbb_previous_month($now);
    }

    /** 마감된 달이면 예외. */
    private function assertWritableMonth(string $yearMonth): void
    {
        if (!$this->isWritableMonth($yearMonth)) {
            throw new InvalidArgumentException('전전월 이전은 마감되어 수정할 수 없습니다.');
        }
    }

    /** 완료(DONE) 기안은 수정 불가. 되돌린 뒤 수정한다. */
    private function assertDraftEditable(array $row): void
    {
        if ((string) ($row['status'] ?? '') === 'DONE') {
            throw new InvalidArgumentException('완료된 기안은 수정할 수 없습니다. 먼저 완료를 해제하세요.');
        }
    }

    /**
     * 활성 월간 템플릿의 당월 초안을 만들고, 신규 insert 건수를 반환한다.
     */
    public function ensureMonthlyDrafts(string $yearMonth): int
    {
        $this->assertMonth($yearMonth);

        $created = 0;
        foreach ($this->templates->listActiveMonthly() as $template) {
            $templateId = (int) ($template['id'] ?? 0);
            if ($templateId < 1 || $this->exists($templateId, $yearMonth)) {
                continue;
            }
            $this->insertDraft($template, $yearMonth);
            $created++;
        }

        return $created;
    }

    /**
     * 선택한 템플릿으로 해당 월 기안 1장을 만든다. 결제 항목이 있어야 한다.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function createFromTemplate(int $templateId, string $yearMonth, array $items = [], string $contentsText = '', string $draftBody = '', string $draftTitle = ''): array
    {
        $this->assertMonth($yearMonth);
        $this->assertWritableMonth($yearMonth);

        $template = $this->templates->findById($templateId);
        if ($template === null) {
            throw new InvalidArgumentException('템플릿을 찾을 수 없습니다.');
        }
        if ((int) ($template['is_active'] ?? 0) !== 1) {
            throw new InvalidArgumentException('중지된 템플릿으로는 작성할 수 없습니다.');
        }
        if ($this->exists($templateId, $yearMonth)) {
            throw new InvalidArgumentException('이 달에는 이미 작성한 기안입니다.');
        }

        $priced = $this->priceItems($items, $yearMonth);
        $this->pdo->beginTransaction();
        try {
            $id = $this->insertDraft($template, $yearMonth, $priced, $contentsText, $draftBody, $draftTitle);
            $this->saveItems($id, $priced);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->requireDraft($id);
    }

    /**
     * 저장하지 않고 제목·본문·원화만 계산한다.
     *
     * @param list<array<string, mixed>> $items
     * @return array{draft_title: string, draft_body: string, amount_krw: int, items: list<array<string, mixed>>}
     */
    public function previewFromTemplate(int $templateId, string $yearMonth, array $items, string $contentsText = ''): array
    {
        $this->assertMonth($yearMonth);
        $template = $this->templates->findById($templateId);
        if ($template === null) {
            throw new InvalidArgumentException('템플릿을 찾을 수 없습니다.');
        }
        $priced = $items === [] ? [] : $this->priceItems($items, $yearMonth);
        $composed = $this->text->compose(
            (string) ($template['title_pattern'] ?? ''),
            (string) ($template['body_pattern'] ?? ''),
            $yearMonth,
            $priced,
            $this->templateVars($template, $contentsText)
        );

        return [
            'draft_title' => $composed['title'],
            'draft_body'  => $composed['body'],
            'amount_krw'  => $this->sumKrw($priced),
            'items'       => $priced,
        ];
    }

    /**
     * 같은 템플릿의 직전 작성 월 결제 줄을 대상 월 일자로 옮긴다.
     *
     * @return array{found: bool, from_month: string, items: list<array<string, mixed>>, contents_text: string}
     */
    public function loadPrevious(int $templateId, string $yearMonth): array
    {
        $this->assertMonth($yearMonth);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tb_draft_expenses
             WHERE expense_template_id = :tid AND target_year_month < :ym
             ORDER BY target_year_month DESC
             LIMIT 1'
        );
        $stmt->execute(['tid' => $templateId, 'ym' => $yearMonth]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [
                'found'         => false,
                'from_month'    => '',
                'items'         => [],
                'contents_text' => '',
            ];
        }
        $fromMonth = (string) ($row['target_year_month'] ?? '');
        $withItems = $this->attachItems([$row]);
        $row = $withItems[0] ?? $row;
        $items = [];
        foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'payment_date'   => $this->text->shiftDateToMonth((string) ($item['payment_date'] ?? ''), $yearMonth),
                'description'    => (string) ($item['description'] ?? ''),
                'vendor'         => (string) ($item['vendor'] ?? ''),
                'note'           => (string) ($item['note'] ?? ''),
                'currency'       => (string) ($item['currency'] ?? 'KRW'),
                'amount_foreign' => (float) ($item['amount_foreign'] ?? 0),
                'item_kind'      => $this->normalizeItemKind((string) ($item['item_kind'] ?? '')),
            ];
        }
        $contents = (string) ($row['contents_text'] ?? '');
        if ($contents !== '' && $fromMonth !== '') {
            $contents = $this->text->shiftYearMonthInText($contents, $fromMonth, $yearMonth);
        }

        return [
            'found'         => true,
            'from_month'    => $fromMonth,
            'items'         => $items,
            'contents_text' => $contents,
        ];
    }

    /**
     * 결제일·통화로 환율을 다시 조회한다. 캐시를 건너뛴다.
     *
     * @return array{
     *   success: bool,
     *   message?: string,
     *   currency?: string,
     *   rate?: float,
     *   unit?: int,
     *   rate_date?: string,
     *   amount_krw?: int
     * }
     */
    public function quoteRate(string $date, string $currency, float $amount = 0, bool $forceRefresh = true): array
    {
        $currency = strtoupper(trim($currency));
        if (!isset(tbb_currencies()[$currency])) {
            throw new InvalidArgumentException('통화는 KRW, USD, EUR, JPY 만 허용합니다.');
        }
        $date = trim($date);
        if ($date === '') {
            $date = tbb_today();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('결제일은 YYYY-MM-DD 형식이어야 합니다.');
        }
        $quoted = $this->rates->getRate($date, $currency, $forceRefresh);
        if (($quoted['success'] ?? false) !== true) {
            return [
                'success'   => false,
                'message'   => (string) ($quoted['message'] ?? '환율을 가져오지 못했습니다.'),
                'currency'  => $currency,
                'amount_krw'=> 0,
            ];
        }
        $rate = (float) ($quoted['rate'] ?? 1);
        $unit = (int) ($quoted['unit'] ?? 1);
        $rateDate = (string) ($quoted['rate_date'] ?? $date);

        return [
            'success'   => true,
            'currency'  => $currency,
            'rate'      => $rate,
            'unit'      => $unit,
            'rate_date' => $rateDate,
            'amount_krw'=> $amount > 0 ? $this->krw->toKrw($amount, $rate, $unit) : 0,
        ];
    }

    /**
     * 여러 줄의 환율을 한 요청에서 조회한다. 한 줄이 실패해도 나머지는 계속한다.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function quoteRates(array $rows, bool $forceRefresh = true): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            $date = trim((string) ($row['date'] ?? $row['payment_date'] ?? ''));
            $amount = (float) ($row['amount'] ?? $row['amount_foreign'] ?? 0);
            try {
                $out[] = $this->quoteRate($date, $currency, $amount, $forceRefresh);
            } catch (InvalidArgumentException $e) {
                $out[] = [
                    'success'    => false,
                    'message'    => $e->getMessage(),
                    'currency'   => $currency,
                    'amount_krw' => 0,
                ];
            }
        }

        return $out;
    }

    /**
     * 작성된 기안의 결제 줄을 통째로 바꾸고 본문을 다시 만든다.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function replaceItems(int $id, array $items, ?string $contentsText = null, ?string $draftBody = null, ?string $draftTitle = null): array
    {
        $row = $this->requireDraft($id);
        $this->assertDraftEditable($row);
        $yearMonth = (string) ($row['target_year_month'] ?? '');
        $this->assertWritableMonth($yearMonth);
        $priced = $this->priceItems($items, $yearMonth);
        $contents = $contentsText === null ? (string) ($row['contents_text'] ?? '') : $contentsText;
        $this->pdo->beginTransaction();
        try {
            $this->saveItems($id, $priced);
            $this->refreshDraftText($id, $priced, $contents, $draftBody, $draftTitle);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->requireDraft($id);
    }

    /**
     * 해당 (템플릿·월) 기안서에 결제 항목 1개를 추가한다.
     * 기존 항목을 삭제하지 않고 INSERT 하여 동시 추가 충돌(클로버)을 피한다.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function appendItem(int $templateId, string $yearMonth, array $item): array
    {
        $this->assertMonth($yearMonth);
        $this->assertWritableMonth($yearMonth);

        // 기존 기안서 찾기
        $stmt = $this->pdo->prepare(
            'SELECT id FROM tb_draft_expenses WHERE expense_template_id = :t AND target_year_month = :ym'
        );
        $stmt->execute(['t' => $templateId, 'ym' => $yearMonth]);
        $draftId = (int) ($stmt->fetchColumn() ?: 0);

        // 없으면 생성(첫 항목) — createFromTemplate 내부에서 priceItems를 수행하므로 여기서 호출하지 않는다
        if ($draftId < 1) {
            return $this->createFromTemplate($templateId, $yearMonth, [$item]);
        }

        // 기존 기안서가 있을 때만 row를 가져오고 상태를 검사한 뒤 가격 계산
        $row = $this->requireDraft($draftId);
        $this->assertDraftEditable($row);

        // DONE 기안 또는 존재하지 않는 기안에서는 환율 API를 호출하지 않는다
        $pricedNew = $this->priceItems([$item], $yearMonth);

        $this->pdo->beginTransaction();
        try {
            $this->insertOneItem($draftId, $pricedNew[0]);
            $all = $this->loadPricedItems($draftId);
            // 이미 가져온 $row 를 재사용 — 트랜잭션 안에서 requireDraft 를 다시 호출하지 않는다
            $contents = (string) ($row['contents_text'] ?? '');
            $this->refreshDraftText($draftId, $all, $contents);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->requireDraft($draftId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByMonth(string $yearMonth): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, t.assignee, t.title AS template_title,
                    t.account_info, t.note AS template_note,
                    t.title_pattern, t.body_pattern, t.cycle_type, t.payment_type,
                    t.vendor, t.period_pattern, t.payment_method_text, t.pay_request_pattern,
                    t.attachment_text, t.content_mode, t.filename_pattern
             FROM tb_draft_expenses d
             LEFT JOIN tb_expense_templates t ON t.id = d.expense_template_id
             WHERE d.target_year_month = :ym
             ORDER BY d.id'
        );
        $stmt->execute(['ym' => $yearMonth]);

        return $this->attachItems($stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, t.assignee, t.title AS template_title,
                    t.account_info, t.note AS template_note,
                    t.title_pattern, t.body_pattern, t.cycle_type, t.payment_type,
                    t.vendor, t.period_pattern, t.payment_method_text, t.pay_request_pattern,
                    t.attachment_text, t.content_mode, t.filename_pattern
             FROM tb_draft_expenses d
             LEFT JOIN tb_expense_templates t ON t.id = d.expense_template_id
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $withItems = $this->attachItems([$row]);

        return $withItems[0] ?? $row;
    }

    /**
     * 저장된 환율·단위로 원화만 다시 계산한다. 환율 API는 호출하지 않는다.
     *
     * @return array<string, mixed>
     */
    public function updateAmount(int $id, float $amountForeign): array
    {
        $row = $this->requireDraft($id);
        $this->assertDraftEditable($row);
        $this->assertWritableMonth((string) ($row['target_year_month'] ?? ''));
        $rate = (float) ($row['exchange_rate'] ?? 1);
        $unit = (int) ($row['exchange_unit'] ?? 1);
        $amountKrw = $this->krw->toKrw($amountForeign, $rate, $unit);

        $stmt = $this->pdo->prepare(
            'UPDATE tb_draft_expenses
             SET amount_foreign = :amount_foreign,
                 amount_krw = :amount_krw
             WHERE id = :id'
        );
        $stmt->execute([
            'amount_foreign' => $amountForeign,
            'amount_krw'     => $amountKrw,
            'id'             => $id,
        ]);

        return $this->requireDraft($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(int $id, string $status): array
    {
        $allowed = ['DRAFT', 'DONE'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('상태는 DRAFT, DONE 만 허용합니다.');
        }
        $this->requireDraft($id);
        $stmt = $this->pdo->prepare(
            'UPDATE tb_draft_expenses SET status = :status WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'id' => $id]);

        return $this->requireDraft($id);
    }

    private function exists(int $templateId, string $yearMonth): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM tb_draft_expenses
             WHERE expense_template_id = :tid AND target_year_month = :ym'
        );
        $stmt->execute(['tid' => $templateId, 'ym' => $yearMonth]);

        return (bool) $stmt->fetchColumn();
    }

    private function assertMonth(string $yearMonth): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
            throw new InvalidArgumentException('target_year_month는 YYYY-MM 형식이어야 합니다.');
        }
    }

    /**
     * @param array<string, mixed> $template
     * @param list<array<string, mixed>> $priced
     */
    private function insertDraft(array $template, string $yearMonth, array $priced = [], string $contentsText = '', string $draftBody = '', string $draftTitle = ''): int
    {
        $paymentDate = $yearMonth . '-01';
        $currency = strtoupper(trim((string) ($template['currency'] ?? 'KRW')));
        $amountForeign = $template['default_amount'] ?? null;
        $rate = 1.0;
        $unit = 1;
        $rateDate = $paymentDate;
        $amountKrw = 0;

        if ($priced !== []) {
            $first = $priced[0];
            $currency = (string) ($first['currency'] ?? $currency);
            $amountForeign = (float) ($first['amount_foreign'] ?? 0);
            $rate = (float) ($first['exchange_rate'] ?? 1);
            $unit = (int) ($first['exchange_unit'] ?? 1);
            $rateDate = (string) ($first['rate_date'] ?? $paymentDate);
            $paymentDate = (string) ($first['payment_date'] ?? $paymentDate);
            $amountKrw = $this->sumKrw($priced);
            $composed = $this->text->compose(
                (string) ($template['title_pattern'] ?? ''),
                (string) ($template['body_pattern'] ?? ''),
                $yearMonth,
                $priced,
                $this->templateVars($template, $contentsText)
            );
            $title = $draftTitle !== '' ? $draftTitle : $composed['title'];
            $body = $draftBody !== '' ? $draftBody : $composed['body'];
        } else {
            $amountMissing = $amountForeign === null || $amountForeign === '';
            if ($currency !== 'KRW') {
                $quoted = $this->rates->getRate($paymentDate, $currency);
                if (($quoted['success'] ?? false) === true) {
                    $rate = (float) ($quoted['rate'] ?? 1);
                    $unit = (int) ($quoted['unit'] ?? 1);
                    $rateDate = (string) ($quoted['rate_date'] ?? $paymentDate);
                }
            }
            if (!$amountMissing) {
                $amountKrw = $this->krw->toKrw((float) $amountForeign, $rate, $unit);
            }
            $title = $draftTitle !== ''
                ? $draftTitle
                : $this->text->applyYearMonth((string) ($template['title_pattern'] ?? ''), $yearMonth);
            $body = $draftBody !== ''
                ? $draftBody
                : $this->text->applyYearMonth((string) ($template['body_pattern'] ?? ''), $yearMonth);
            $amountForeign = $amountMissing ? null : (float) $amountForeign;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_draft_expenses (
                expense_template_id, target_year_month, draft_title, draft_body,
                currency, amount_foreign, exchange_rate, exchange_unit, rate_date,
                amount_krw, payment_date, status, contents_text, created_by, created_at
            ) VALUES (
                :tid, :ym, :title, :body,
                :currency, :amount_foreign, :exchange_rate, :exchange_unit, :rate_date,
                :amount_krw, :payment_date, :status, :contents_text, :created_by, :created_at
            )'
        );
        $stmt->execute([
            'tid'            => (int) $template['id'],
            'ym'             => $yearMonth,
            'title'          => $title,
            'body'           => $body,
            'currency'       => $currency,
            'amount_foreign' => $amountForeign,
            'exchange_rate'  => $rate,
            'exchange_unit'  => $unit,
            'rate_date'      => $rateDate === '' ? null : $rateDate,
            'amount_krw'     => $amountKrw,
            'payment_date'   => $paymentDate === '' ? null : $paymentDate,
            'status'         => 'DRAFT',
            'contents_text'  => $contentsText === '' ? null : $contentsText,
            'created_by'     => $this->createdBy,
            'created_at'     => Database::nowKst(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return list<array<string, mixed>>
     */
    private function priceItems(array $raw, string $yearMonth): array
    {
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'KRW')));
            if (!isset(tbb_currencies()[$currency])) {
                throw new InvalidArgumentException('통화는 KRW, USD, EUR, JPY 만 허용합니다.');
            }
            $amount = $row['amount'] ?? $row['amount_foreign'] ?? null;
            if ($amount === null || $amount === '') {
                throw new InvalidArgumentException('금액을 입력하세요.');
            }
            $amount = (float) $amount;
            if ($amount <= 0) {
                throw new InvalidArgumentException('금액은 0보다 커야 합니다.');
            }
            $date = trim((string) ($row['payment_date'] ?? ''));
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException('결제일은 YYYY-MM-DD 형식이어야 합니다.');
            }
            $rate = 1.0;
            $unit = 1;
            $rateDate = $date;
            $rateOk = true;
            $manualKrw = (int) ($row['amount_krw'] ?? 0);
            $useManual = !empty($row['amount_krw_manual']) && $manualKrw > 0;
            $amountKrw = $this->krw->toKrw($amount, $rate, $unit);
            if ($currency !== 'KRW') {
                $lookupDate = $date !== '' ? $date : tbb_today();
                $quoted = $this->rates->getRate($lookupDate, $currency);
                $rateOk = ($quoted['success'] ?? false) === true;
                if ($rateOk) {
                    $rate = (float) ($quoted['rate'] ?? 1);
                    $unit = (int) ($quoted['unit'] ?? 1);
                    $rateDate = (string) ($quoted['rate_date'] ?? $lookupDate);
                    $amountKrw = $this->krw->toKrw($amount, $rate, $unit);
                } else {
                    $amountKrw = 0;
                    $rateDate = '';
                }
                if ($useManual || (!$rateOk && $manualKrw > 0)) {
                    $amountKrw = $manualKrw;
                }
            }
            $kind = $this->normalizeItemKind((string) ($row['item_kind'] ?? ''));
            $out[] = [
                'payment_date'   => $date,
                'description'    => trim((string) ($row['description'] ?? '')),
                'vendor'         => trim((string) ($row['vendor'] ?? '')),
                'note'           => trim((string) ($row['note'] ?? '')),
                'currency'       => $currency,
                'amount_foreign' => $amount,
                'exchange_rate'  => $rate,
                'exchange_unit'  => $unit,
                'rate_date'      => $rateDate,
                'amount_krw'     => $amountKrw,
                'rate_ok'        => $rateOk,
                'item_kind'      => $kind,
                'receipt_id'     => self::normalizeReceiptId($row['receipt_id'] ?? null),
            ];
        }
        if ($out === []) {
            throw new InvalidArgumentException('결제 항목을 입력하세요.');
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $priced
     */
    /** 첨부 id 정규화(양의 정수만, 아니면 null). 존재 검증은 resolveReceiptId에서. */
    private static function normalizeReceiptId(mixed $v): ?int
    {
        $n = is_numeric($v) ? (int) $v : 0;

        return $n > 0 ? $n : null;
    }

    /** 이 draft의 (템플릿·월) 첨부에 실재하는 receipt_id만 통과, 아니면 null(잘못/삭제된 첨부 방지). */
    private function resolveReceiptId(int $draftId, mixed $receiptId): ?int
    {
        $rid = self::normalizeReceiptId($receiptId);
        if ($rid === null) {
            return null;
        }
        if (!isset($this->validReceiptCache[$draftId])) {
            $stmt = $this->pdo->prepare(
                'SELECT r.id FROM tb_draft_receipts r
                 JOIN tb_draft_expenses d
                   ON d.expense_template_id = r.expense_template_id
                  AND d.target_year_month = r.target_year_month
                 WHERE d.id = :id'
            );
            $stmt->execute(['id' => $draftId]);
            $this->validReceiptCache[$draftId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        return in_array($rid, $this->validReceiptCache[$draftId], true) ? $rid : null;
    }

    private function saveItems(int $draftId, array $priced): void
    {
        unset($this->validReceiptCache[$draftId]);
        $del = $this->pdo->prepare('DELETE FROM tb_draft_payment_items WHERE draft_expense_id = :id');
        $del->execute(['id' => $draftId]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_draft_payment_items (
                draft_expense_id, receipt_id, payment_date, description, vendor, note, item_kind, currency, amount_foreign,
                exchange_rate, exchange_unit, rate_date, amount_krw, sort_order, created_at
            ) VALUES (
                :draft_id, :receipt_id, :payment_date, :description, :vendor, :note, :item_kind, :currency, :amount_foreign,
                :exchange_rate, :exchange_unit, :rate_date, :amount_krw, :sort_order, :created_at
            )'
        );
        $now = Database::nowKst();
        foreach ($priced as $i => $item) {
            $stmt->execute([
                'draft_id'       => $draftId,
                'receipt_id'     => $this->resolveReceiptId($draftId, $item['receipt_id'] ?? null),
                'payment_date'   => $item['payment_date'] === '' ? null : $item['payment_date'],
                'description'    => $item['description'] === '' ? null : $item['description'],
                'vendor'         => ($item['vendor'] ?? '') === '' ? null : $item['vendor'],
                'note'           => ($item['note'] ?? '') === '' ? null : $item['note'],
                'item_kind'      => (string) ($item['item_kind'] ?? ''),
                'currency'       => $item['currency'],
                'amount_foreign' => $item['amount_foreign'],
                'exchange_rate'  => $item['exchange_rate'],
                'exchange_unit'  => $item['exchange_unit'],
                'rate_date'      => ($item['rate_date'] ?? '') === '' ? null : $item['rate_date'],
                'amount_krw'     => $item['amount_krw'],
                'sort_order'     => $i,
                'created_at'     => $now,
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $priced
     */
    private function refreshDraftText(int $id, array $priced, string $contentsText = '', ?string $draftBody = null, ?string $draftTitle = null): void
    {
        $row = $this->requireDraft($id);
        $yearMonth = (string) ($row['target_year_month'] ?? '');
        $vars = $this->templateVars($row, $contentsText);
        $composed = $this->text->compose(
            (string) ($row['title_pattern'] ?? ''),
            (string) ($row['body_pattern'] ?? ''),
            $yearMonth,
            $priced,
            $vars
        );
        $title = ($draftTitle !== null && $draftTitle !== '') ? $draftTitle : $composed['title'];
        $body = ($draftBody !== null && $draftBody !== '') ? $draftBody : $composed['body'];
        $first = $priced[0];
        $stmt = $this->pdo->prepare(
            'UPDATE tb_draft_expenses
             SET draft_title = :title,
                 draft_body = :body,
                 currency = :currency,
                 amount_foreign = :amount_foreign,
                 exchange_rate = :exchange_rate,
                 exchange_unit = :exchange_unit,
                 rate_date = :rate_date,
                 amount_krw = :amount_krw,
                 payment_date = :payment_date,
                 contents_text = :contents_text
             WHERE id = :id'
        );
        $stmt->execute([
            'title'          => $title,
            'body'           => $body,
            'currency'       => $first['currency'],
            'amount_foreign' => $first['amount_foreign'],
            'exchange_rate'  => $first['exchange_rate'],
            'exchange_unit'  => $first['exchange_unit'],
            'rate_date'      => ($first['rate_date'] ?? '') === '' ? null : $first['rate_date'],
            'amount_krw'     => $this->sumKrw($priced),
            'payment_date'   => ($first['payment_date'] ?? '') === '' ? null : $first['payment_date'],
            'contents_text'  => $contentsText === '' ? null : $contentsText,
            'id'             => $id,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachItems(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $byDraft = [];
        if ($ids !== []) {
            $in = implode(',', array_map('intval', $ids));
            $stmt = $this->pdo->query(
                "SELECT * FROM tb_draft_payment_items
                 WHERE draft_expense_id IN ({$in})
                 ORDER BY sort_order, id"
            );
            foreach ($stmt === false ? [] : $stmt->fetchAll() as $item) {
                $did = (int) ($item['draft_expense_id'] ?? 0);
                $byDraft[$did][] = $item;
            }
        }
        foreach ($rows as $i => $row) {
            $rows[$i]['items'] = $byDraft[(int) ($row['id'] ?? 0)] ?? [];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, string>
     */
    private function templateVars(array $template, string $contentsText = ''): array
    {
        $title = (string) ($template['title'] ?? $template['template_title'] ?? '');

        return [
            'title'           => $title,
            'assignee'        => (string) ($template['assignee'] ?? ''),
            'account_info'    => (string) ($template['account_info'] ?? ''),
            'note'            => (string) ($template['note'] ?? $template['template_note'] ?? ''),
            'vendor'          => (string) ($template['vendor'] ?? ''),
            'period_pattern'  => (string) ($template['period_pattern'] ?? ''),
            'payment_method'  => (string) ($template['payment_method_text'] ?? ''),
            'pay_request'     => (string) ($template['pay_request_pattern'] ?? ''),
            'attachment'      => (string) ($template['attachment_text'] ?? ''),
            'content_mode'    => (string) ($template['content_mode'] ?? 'LINES'),
            'contents_text'   => $contentsText,
        ];
    }

    /** 비우면 빈값. 결제·충전만 허용한다. */
    private function normalizeItemKind(string $kind): string
    {
        $kind = strtoupper(trim($kind));

        return ($kind === 'CHARGE' || $kind === 'PAY') ? $kind : '';
    }

    /**
     * @param list<array<string, mixed>> $priced
     */
    private function sumKrw(array $priced): int
    {
        $sum = 0;
        foreach ($priced as $item) {
            $sum += (int) ($item['amount_krw'] ?? 0);
        }

        return $sum;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireDraft(int $id): array
    {
        $row = $this->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException('초안을 찾을 수 없습니다.');
        }

        return $row;
    }

    /** @param array<string, mixed> $item */
    private function insertOneItem(int $draftId, array $item): void
    {
        $ord = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tb_draft_payment_items WHERE draft_expense_id = :id');
        $ord->execute(['id' => $draftId]);
        $sortOrder = (int) $ord->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_draft_payment_items (
                draft_expense_id, receipt_id, payment_date, description, vendor, note, item_kind, currency, amount_foreign,
                exchange_rate, exchange_unit, rate_date, amount_krw, sort_order, created_at
            ) VALUES (
                :draft_id, :receipt_id, :payment_date, :description, :vendor, :note, :item_kind, :currency, :amount_foreign,
                :exchange_rate, :exchange_unit, :rate_date, :amount_krw, :sort_order, :created_at
            )'
        );
        $stmt->execute([
            'draft_id'       => $draftId,
            'receipt_id'     => $this->resolveReceiptId($draftId, $item['receipt_id'] ?? null),
            'payment_date'   => $item['payment_date'] === '' ? null : $item['payment_date'],
            'description'    => $item['description'] === '' ? null : $item['description'],
            'vendor'         => ($item['vendor'] ?? '') === '' ? null : $item['vendor'],
            'note'           => ($item['note'] ?? '') === '' ? null : $item['note'],
            'item_kind'      => (string) ($item['item_kind'] ?? ''),
            'currency'       => $item['currency'],
            'amount_foreign' => $item['amount_foreign'],
            'exchange_rate'  => $item['exchange_rate'],
            'exchange_unit'  => $item['exchange_unit'],
            'rate_date'      => ($item['rate_date'] ?? '') === '' ? null : $item['rate_date'],
            'amount_krw'     => $item['amount_krw'],
            'sort_order'     => $sortOrder,
            'created_at'     => Database::nowKst(),
        ]);
    }

    /**
     * 저장된 결제 항목을 priceItems 결과 형태로 다시 읽는다(재조회/재가격 없음).
     *
     * @return list<array<string, mixed>>
     */
    private function loadPricedItems(int $draftId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tb_draft_payment_items WHERE draft_expense_id = :id ORDER BY sort_order, id'
        );
        $stmt->execute(['id' => $draftId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'payment_date'   => (string) ($r['payment_date'] ?? ''),
                'description'    => (string) ($r['description'] ?? ''),
                'vendor'         => (string) ($r['vendor'] ?? ''),
                'note'           => (string) ($r['note'] ?? ''),
                'currency'       => (string) ($r['currency'] ?? 'KRW'),
                'amount_foreign' => (float) ($r['amount_foreign'] ?? 0),
                'exchange_rate'  => (float) ($r['exchange_rate'] ?? 1),
                'exchange_unit'  => (int) ($r['exchange_unit'] ?? 1),
                'rate_date'      => (string) ($r['rate_date'] ?? ''),
                'amount_krw'     => (int) ($r['amount_krw'] ?? 0),
                'rate_ok'        => true,
                'item_kind'      => (string) ($r['item_kind'] ?? ''),
                'receipt_id'     => isset($r['receipt_id']) && $r['receipt_id'] !== null ? (int) $r['receipt_id'] : null,
            ];
        }

        return $out;
    }
}
