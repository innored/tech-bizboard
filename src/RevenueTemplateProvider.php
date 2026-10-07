<?php
/**
 * 수입 반복 템플릿 조회·저장
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/RevenueTemplateProvider.php
 */

declare(strict_types=1);

class RevenueTemplateProvider
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
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM tb_revenue_templates ORDER BY is_active DESC, id'
        );

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /**
     * 사용 중이고 대상월이 시작~종료 안에 있는 템플릿.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveForMonth(string $yearMonth): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
            throw new InvalidArgumentException('대상월은 YYYY-MM 형식이어야 합니다.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tb_revenue_templates
             WHERE is_active = 1
               AND start_year_month <= :ym
               AND (end_year_month IS NULL OR end_year_month = \'\' OR end_year_month >= :ym)
             ORDER BY id'
        );
        $stmt->execute(['ym' => $yearMonth]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_revenue_templates WHERE id = :id');
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
        $row['created_at'] = Database::nowKst();
        $row['created_by'] = $this->createdBy;
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_revenue_templates (
                project_name, client_name, service_category, solution_name, solution_id, billing_type, supply_krw, vat_krw, amount_krw, list_value_krw, assignee,
                start_year_month, end_year_month, note, is_active, created_by, created_at
            ) VALUES (
                :project_name, :client_name, :service_category, :solution_name, :solution_id, :billing_type, :supply_krw, :vat_krw, :amount_krw, :list_value_krw, :assignee,
                :start_year_month, :end_year_month, :note, :is_active, :created_by, :created_at
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
        $existing = $this->requireId($id);
        $row = $this->normalize($data);
        // 반복 사용(1) → 해제(0) 전환 시 중지 일시를 메모에 남긴다.
        if ((int) ($existing['is_active'] ?? 1) === 1 && $row['is_active'] === 0) {
            $stamp = '[반복 중지 ' . substr(Database::nowKst(), 0, 16) . ']';
            $note = (string) ($row['note'] ?? '');
            $row['note'] = $note === '' ? $stamp : ($note . "\n" . $stamp);
        }
        $row['id'] = $id;
        $stmt = $this->pdo->prepare(
            'UPDATE tb_revenue_templates
             SET project_name = :project_name,
                 client_name = :client_name,
                 service_category = :service_category,
                 solution_name = :solution_name,
                 solution_id = :solution_id,
                 billing_type = :billing_type,
                 supply_krw = :supply_krw,
                 vat_krw = :vat_krw,
                 amount_krw = :amount_krw,
                 list_value_krw = :list_value_krw,
                 assignee = :assignee,
                 start_year_month = :start_year_month,
                 end_year_month = :end_year_month,
                 note = :note,
                 is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute($row);

        return $this->requireId($id);
    }

    public function delete(int $id): void
    {
        $this->requireId($id);
        $stmt = $this->pdo->prepare('DELETE FROM tb_revenue_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireId(int $id): array
    {
        $row = $this->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException('수입 템플릿을 찾을 수 없습니다.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $name = trim((string) ($data['project_name'] ?? ''));
        $assignee = trim((string) ($data['assignee'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('프로젝트명은 필수입니다.');
        }
        if ($assignee === '') {
            throw new InvalidArgumentException('담당은 필수입니다.');
        }
        $start = trim((string) ($data['start_year_month'] ?? ''));
        if ($start === '') {
            $start = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m');
        }
        if (preg_match('/^\d{4}-\d{2}$/', $start) !== 1) {
            throw new InvalidArgumentException('시작월은 YYYY-MM 형식이어야 합니다.');
        }
        $end = trim((string) ($data['end_year_month'] ?? ''));
        if ($end === '') {
            $end = null;
        } elseif (preg_match('/^\d{4}-\d{2}$/', $end) !== 1) {
            throw new InvalidArgumentException('종료월은 YYYY-MM 형식이어야 합니다.');
        } elseif ($end < $start) {
            throw new InvalidArgumentException('종료월이 시작월보다 앞입니다.');
        }
        $supply = (int) ($data['supply_krw'] ?? 0);
        $vat = (int) ($data['vat_krw'] ?? 0);
        $amount = (int) ($data['amount_krw'] ?? 0);
        if ($supply < 0 || $vat < 0 || $amount < 0) {
            throw new InvalidArgumentException('금액은 0 이상이어야 합니다.');
        }
        $client = trim((string) ($data['client_name'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));

        $category = trim((string) ($data['service_category'] ?? ''));
        if ($category !== '' && !in_array($category, TeamRevenueProvider::SERVICE_CATEGORIES, true)) {
            throw new InvalidArgumentException('서비스 구분 값이 올바르지 않습니다.');
        }
        // 우리 솔루션: 서비스구분이 '솔루션'일 때만 유지(id=식별, name=표시 스냅샷)
        $solutionName = trim((string) ($data['solution_name'] ?? ''));
        $solutionId = trim((string) ($data['solution_id'] ?? ''));
        if ($category !== '솔루션') {
            $solutionName = '';
            $solutionId = '';
        }

        $billing = strtoupper(trim((string) ($data['billing_type'] ?? 'PAID')));
        if ($billing !== 'PAID' && $billing !== 'FREE') {
            throw new InvalidArgumentException('유상/무상 값이 올바르지 않습니다.');
        }
        $listValue = (int) ($data['list_value_krw'] ?? 0);
        if ($listValue < 0) {
            throw new InvalidArgumentException('정상가는 0 이상이어야 합니다.');
        }
        if ($billing === 'FREE') {
            // 무상: 입력한 총액을 무상 제공 가치(정상가)로 기록하고 공급가·부가세·총액은 0
            if ($listValue === 0) {
                $listValue = $amount;
            }
            $supply = 0;
            $vat = 0;
            $amount = 0;
        } else {
            $listValue = 0;    // 유상: 정상가 미사용
            // 부가세·총액을 둘 다 넘기지 않았으면 공급가에서 자동 계산(구 API 호환)
            if (!isset($data['vat_krw']) && !isset($data['amount_krw'])) {
                $auto = TeamRevenueProvider::applySupply($supply);
                $vat = $auto['vat_krw'];
                $amount = $auto['amount_krw'];
            }
        }

        return [
            'project_name'     => $name,
            'client_name'      => $client === '' ? null : $client,
            'service_category' => $category,
            'solution_name'    => $solutionName,
            'solution_id'      => $solutionId,
            'billing_type'     => $billing,
            'supply_krw'       => $supply,
            'vat_krw'          => $vat,
            'amount_krw'       => $amount,
            'list_value_krw'   => $listValue,
            'assignee'         => $assignee,
            'start_year_month' => $start,
            'end_year_month'   => $end,
            'note'             => $note === '' ? null : $note,
            'is_active'        => empty($data['is_active']) && array_key_exists('is_active', $data) ? 0 : 1,
        ];
    }
}
