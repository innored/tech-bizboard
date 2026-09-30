<?php
/**
 * 지출 템플릿 조회·저장
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/ExpenseTemplateProvider.php
 */

declare(strict_types=1);

class ExpenseTemplateProvider
{
    private string $createdBy = '';

    public function __construct(private PDO $pdo)
    {
    }

    public function setCreatedBy(string $email): void
    {
        $this->createdBy = trim($email);
    }

    /**
     * 기안 작성 목록. 사용 중인 템플릿만.
     *
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM tb_expense_templates
             WHERE is_active = 1
             ORDER BY id'
        );

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveMonthly(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM tb_expense_templates
             WHERE is_active = 1 AND cycle_type = 'MONTHLY'
             ORDER BY id"
        );

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM tb_expense_templates ORDER BY is_active DESC, id'
        );

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_expense_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $row = $this->normalize($data);
        $row['created_by'] = $this->createdBy;
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_expense_templates (
                title, assignee, account_info, payment_site, cycle_type, currency, default_amount,
                payment_type, next_renewal_date, title_pattern, filename_pattern, body_pattern,
                vendor, period_pattern, payment_method_text, pay_request_pattern, attachment_text,
                content_mode, content_include_vendor, note, created_by, is_active
            ) VALUES (
                :title, :assignee, :account_info, :payment_site, :cycle_type, :currency, :default_amount,
                :payment_type, :next_renewal_date, :title_pattern, :filename_pattern, :body_pattern,
                :vendor, :period_pattern, :payment_method_text, :pay_request_pattern, :attachment_text,
                :content_mode, :content_include_vendor, :note, :created_by, :is_active
            )'
        );
        $stmt->execute($row);

        return $this->requireId((int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $this->requireId($id);
        $row = $this->normalize($data);
        $row['id'] = $id;
        $stmt = $this->pdo->prepare(
            'UPDATE tb_expense_templates
             SET title = :title,
                 assignee = :assignee,
                 account_info = :account_info,
                 payment_site = :payment_site,
                 cycle_type = :cycle_type,
                 currency = :currency,
                 default_amount = :default_amount,
                 payment_type = :payment_type,
                 next_renewal_date = :next_renewal_date,
                 title_pattern = :title_pattern,
                 filename_pattern = :filename_pattern,
                 body_pattern = :body_pattern,
                 vendor = :vendor,
                 period_pattern = :period_pattern,
                 payment_method_text = :payment_method_text,
                 pay_request_pattern = :pay_request_pattern,
                 attachment_text = :attachment_text,
                 content_mode = :content_mode,
                 content_include_vendor = :content_include_vendor,
                 note = :note,
                 is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute($row);

        return $this->requireId($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function setActive(int $id, bool $active): array
    {
        $this->requireId($id);
        $stmt = $this->pdo->prepare(
            'UPDATE tb_expense_templates SET is_active = :active WHERE id = :id'
        );
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);

        return $this->requireId($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireId(int $id): array
    {
        $row = $this->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException('템플릿을 찾을 수 없습니다.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $assignee = trim((string) ($data['assignee'] ?? ''));
        $titleText = trim((string) ($data['title_pattern'] ?? ''));
        $filenamePattern = trim((string) ($data['filename_pattern'] ?? ''));
        $bodyText = (string) ($data['body_pattern'] ?? '');
        $cycle = strtoupper(trim((string) ($data['cycle_type'] ?? 'MONTHLY')));
        $payment = strtoupper(trim((string) ($data['payment_type'] ?? '')));
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'KRW')));
        if ($title === '') {
            throw new InvalidArgumentException('건명은 필수입니다.');
        }
        if ($assignee === '') {
            throw new InvalidArgumentException('담당은 필수입니다.');
        }
        if ($titleText === '') {
            throw new InvalidArgumentException('제목은 필수입니다.');
        }
        if (trim($bodyText) === '') {
            throw new InvalidArgumentException('내용은 필수입니다.');
        }
        if (!isset(tbb_cycle_types()[$cycle])) {
            throw new InvalidArgumentException('주기는 MONTHLY, YEARLY, IRREGULAR 만 허용합니다.');
        }
        if ($payment === '') {
            $payment = null;
        } elseif (!isset(tbb_payment_types()[$payment])) {
            throw new InvalidArgumentException('결제 구분은 AUTO, MANUAL 만 허용합니다.');
        }
        if ($currency === '') {
            $currency = 'KRW';
        }
        $amount = $data['default_amount'] ?? null;
        if ($amount === '' || $amount === null) {
            $amount = null;
        } else {
            $amount = (float) $amount;
        }
        $renewal = trim((string) ($data['next_renewal_date'] ?? ''));
        if ($renewal === '') {
            $renewal = null;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $renewal) !== 1) {
            throw new InvalidArgumentException('갱신일은 YYYY-MM-DD 형식이어야 합니다.');
        }
        $note = trim((string) ($data['note'] ?? ''));
        $site = trim((string) ($data['payment_site'] ?? ''));
        $vendor = trim((string) ($data['vendor'] ?? ''));
        $period = trim((string) ($data['period_pattern'] ?? ''));
        $payMethod = trim((string) ($data['payment_method_text'] ?? ''));
        $payRequest = trim((string) ($data['pay_request_pattern'] ?? ''));
        $attachment = trim((string) ($data['attachment_text'] ?? ''));
        $mode = strtoupper(trim((string) ($data['content_mode'] ?? 'LINES')));
        if ($mode === '') {
            $mode = 'LINES';
        }
        if (!isset(tbb_content_modes()[$mode])) {
            throw new InvalidArgumentException('내용 모드는 LINES, LAST 만 허용합니다.');
        }

        return [
            'title'                => $title,
            'assignee'             => $assignee,
            'account_info'         => trim((string) ($data['account_info'] ?? '')),
            'payment_site'         => $site === '' ? null : $site,
            'cycle_type'           => $cycle,
            'currency'             => $currency,
            'default_amount'       => $amount,
            'payment_type'         => $payment,
            'next_renewal_date'    => $renewal,
            'title_pattern'        => $titleText,
            'filename_pattern'     => $filenamePattern === '' ? null : $filenamePattern,
            'body_pattern'         => $bodyText,
            'vendor'               => $vendor === '' ? null : $vendor,
            'period_pattern'       => $period === '' ? null : $period,
            'payment_method_text'  => $payMethod === '' ? null : $payMethod,
            'pay_request_pattern'  => $payRequest === '' ? null : $payRequest,
            'attachment_text'      => $attachment === '' ? null : $attachment,
            'content_mode'         => $mode,
            // 내용·파일명에 거래처 표시 여부(기본 표시). 키가 없으면 1(표시) 유지.
            'content_include_vendor' => array_key_exists('content_include_vendor', $data)
                ? (!empty($data['content_include_vendor']) ? 1 : 0)
                : 1,
            'note'                 => $note === '' ? null : $note,
            'is_active'            => !empty($data['is_active']) ? 1 : 0,
        ];
    }
}
