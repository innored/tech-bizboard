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
                project_name, client_name, supply_krw, assignee,
                start_year_month, end_year_month, note, is_active, created_by, created_at
            ) VALUES (
                :project_name, :client_name, :supply_krw, :assignee,
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
        $this->requireId($id);
        $row = $this->normalize($data);
        $row['id'] = $id;
        $stmt = $this->pdo->prepare(
            'UPDATE tb_revenue_templates
             SET project_name = :project_name,
                 client_name = :client_name,
                 supply_krw = :supply_krw,
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
        if ($supply < 0) {
            throw new InvalidArgumentException('공급가는 0 이상이어야 합니다.');
        }
        $client = trim((string) ($data['client_name'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));

        return [
            'project_name'     => $name,
            'client_name'      => $client === '' ? null : $client,
            'supply_krw'       => $supply,
            'assignee'         => $assignee,
            'start_year_month' => $start,
            'end_year_month'   => $end,
            'note'             => $note === '' ? null : $note,
            'is_active'        => empty($data['is_active']) && array_key_exists('is_active', $data) ? 0 : 1,
        ];
    }
}
