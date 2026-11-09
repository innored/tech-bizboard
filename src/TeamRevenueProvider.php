<?php
/**
 * 팀 수입 월별 raw 조회·저장
 *
 * @date 2026-09-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/TeamRevenueProvider.php
 */

declare(strict_types=1);

class TeamRevenueProvider
{
    /** 서비스 구분 고정 목록 */
    public const SERVICE_CATEGORIES = ['솔루션', '컨설팅', '유지보수', '개발비', '기타'];

    /** 솔루션구분: 애드테크 / 일반 솔루션 */
    public const SOLUTION_GROUPS = ['ADTECH', 'GENERAL'];

    /** 원가를 받는 서비스구분 = 수익 탭 '용역' 그룹(수익 = 공급가 - 원가) */
    public const COST_CATEGORIES = ['컨설팅', '유지보수', '개발비', '기타'];

    private string $createdBy = '';
    private ?DateTimeImmutable $now = null;
    private bool $bypassMonthLock = false;

    public function __construct(
        private PDO $pdo,
        private RevenueTemplateProvider $templates
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

    /** 전전월(지난달보다 앞선 달)은 수정할 수 없다. 9월이면 7월부터 잠근다. */
    public static function isMonthLocked(string $yearMonth, ?DateTimeImmutable $now = null): bool
    {
        if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
            return true;
        }

        return $yearMonth < tbb_previous_month($now);
    }

    /**
     * 공급가에서 부가세 10%·합계를 채운다.
     *
     * @return array{supply_krw: int, vat_krw: int, amount_krw: int}
     */
    public static function applySupply(int $supplyKrw): array
    {
        $supply = max(0, $supplyKrw);
        $vat = (int) round($supply * 0.1);

        return [
            'supply_krw' => $supply,
            'vat_krw'    => $vat,
            'amount_krw' => $supply + $vat,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByYear(string $year): array
    {
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            throw new InvalidArgumentException('연도는 YYYY 형식이어야 합니다.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tb_team_revenues
             WHERE target_year_month LIKE :prefix
             ORDER BY received_date, id'
        );
        $stmt->execute(['prefix' => $year . '-%']);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * 연간 인덱스용 12달 칸. 없는 달은 0건이고, 줄을 만들지 않는다.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{month: string, count: int, amount_krw: int, supply_krw: int, vat_krw: int}>
     */
    public static function yearMonthBuckets(string $year, array $rows): array
    {
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            throw new InvalidArgumentException('연도는 YYYY 형식이어야 합니다.');
        }
        $buckets = [];
        for ($i = 1; $i <= 12; $i++) {
            $mm = sprintf('%02d', $i);
            $buckets[$mm] = [
                'month'      => $mm,
                'count'      => 0,
                'amount_krw' => 0,
                'supply_krw' => 0,
                'vat_krw'    => 0,
            ];
        }
        foreach ($rows as $row) {
            $ym = (string) ($row['target_year_month'] ?? '');
            if (!str_starts_with($ym, $year . '-')) {
                continue;
            }
            $mm = substr($ym, 5, 2);
            if (!isset($buckets[$mm])) {
                continue;
            }
            $buckets[$mm]['count']++;
            $buckets[$mm]['amount_krw'] += (int) ($row['amount_krw'] ?? 0);
            $buckets[$mm]['supply_krw'] += (int) ($row['supply_krw'] ?? 0);
            $buckets[$mm]['vat_krw'] += (int) ($row['vat_krw'] ?? 0);
        }

        return array_values($buckets);
    }

    /**
     * 그달 기존 줄만. 반복 raw는 만들지 않는다.
     *
     * @return list<array<string, mixed>>
     */
    public function listByMonth(string $yearMonth): array
    {
        $this->assertYearMonth($yearMonth);
        $stmt = $this->pdo->prepare(
            'SELECT *,
                    (SELECT COUNT(*) FROM tb_revenue_receipts rr WHERE rr.revenue_id = tb_team_revenues.id) AS receipt_count
             FROM tb_team_revenues
             WHERE target_year_month = :ym
             ORDER BY received_date, id'
        );
        $stmt->execute(['ym' => $yearMonth]);

        return $stmt->fetchAll() ?: [];
    }

    /** 기간 안 사용 중 템플릿 중 없는 달 줄을 만든다. */
    public function ensureMonth(string $yearMonth): int
    {
        $this->assertYearMonth($yearMonth);
        $created = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($this->templates->listActiveForMonth($yearMonth) as $tpl) {
                $tid = (int) ($tpl['id'] ?? 0);
                if ($tid < 1 || $this->hasTemplateMonth($tid, $yearMonth)) {
                    continue;
                }
                $billing = strtoupper(trim((string) ($tpl['billing_type'] ?? 'PAID')));
                $billing = $billing === 'FREE' ? 'FREE' : 'PAID';
                if ($billing === 'FREE') {
                    $money = ['supply_krw' => 0, 'vat_krw' => 0, 'amount_krw' => 0];
                    $listValue = (int) ($tpl['list_value_krw'] ?? 0);
                } else {
                    $supply = (int) ($tpl['supply_krw'] ?? 0);
                    $vat = (int) ($tpl['vat_krw'] ?? 0);
                    $amount = (int) ($tpl['amount_krw'] ?? 0);
                    // 템플릿에 부가세·총액이 저장돼 있으면 그대로 복사, 없으면(구버전) 공급가 기준 자동계산
                    $money = ($vat === 0 && $amount === 0)
                        ? self::applySupply($supply)
                        : ['supply_krw' => $supply, 'vat_krw' => $vat, 'amount_krw' => $amount];
                    $listValue = 0;
                }
                $this->insertRow([
                    'revenue_template_id' => $tid,
                    'target_year_month'   => $yearMonth,
                    'project_name'        => (string) ($tpl['project_name'] ?? ''),
                    'client_name'         => $tpl['client_name'] ?? null,
                    'service_category'    => (string) ($tpl['service_category'] ?? ''),
                    'solution_name'       => (string) ($tpl['solution_name'] ?? ''),
                    'solution_id'         => (string) ($tpl['solution_id'] ?? ''),
                    'solution_group'      => (string) ($tpl['solution_group'] ?? ''),
                    'campaign_spend_krw'  => (int) ($tpl['campaign_spend_krw'] ?? 0),
                    'tech_fee_rate'       => isset($tpl['tech_fee_rate']) ? (float) $tpl['tech_fee_rate'] : null,
                    'cost_krw'            => (int) ($tpl['cost_krw'] ?? 0),
                    'cost_note'           => (string) ($tpl['cost_note'] ?? ''),
                    'billing_type'        => $billing,
                    'list_value_krw'      => $listValue,
                    'supply_krw'          => $money['supply_krw'],
                    'vat_krw'             => $money['vat_krw'],
                    'amount_krw'          => $money['amount_krw'],
                    'status'              => 'PENDING',
                    'received_date'       => $yearMonth . '-01',
                    'assignee'            => (string) ($tpl['assignee'] ?? ''),
                    'note'                => $tpl['note'] ?? null,
                    'created_by'          => $this->createdBy,
                    'created_at'          => Database::nowKst(),
                ]);
                $created++;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createOneOff(array $data): array
    {
        $row = $this->normalize($data, true);
        $this->assertWritable((string) $row['target_year_month']);
        $this->insertRow($row);

        return $this->requireId((int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $current = $this->requireId($id);
        $this->assertWritable((string) ($current['target_year_month'] ?? ''));
        $row = $this->normalize(array_merge($current, $data), false, $data);
        $this->assertWritable((string) $row['target_year_month']);
        $row['id'] = $id;
        $stmt = $this->pdo->prepare(
            'UPDATE tb_team_revenues
             SET target_year_month = :target_year_month,
                 project_name = :project_name,
                 client_name = :client_name,
                 service_category = :service_category,
                 solution_name = :solution_name,
                 solution_id = :solution_id,
                 solution_group = :solution_group,
                 campaign_spend_krw = :campaign_spend_krw,
                 tech_fee_rate = :tech_fee_rate,
                 cost_krw = :cost_krw,
                 cost_note = :cost_note,
                 billing_type = :billing_type,
                 list_value_krw = :list_value_krw,
                 supply_krw = :supply_krw,
                 vat_krw = :vat_krw,
                 amount_krw = :amount_krw,
                 status = :status,
                 received_date = :received_date,
                 assignee = :assignee,
                 note = :note
             WHERE id = :id'
        );
        $stmt->execute([
            'id'                => $id,
            'target_year_month' => $row['target_year_month'],
            'project_name'      => $row['project_name'],
            'client_name'       => $row['client_name'],
            'service_category'  => $row['service_category'],
            'solution_name'     => $row['solution_name'],
            'solution_id'       => $row['solution_id'],
            'solution_group'    => $row['solution_group'],
            'campaign_spend_krw' => $row['campaign_spend_krw'],
            'tech_fee_rate'     => $row['tech_fee_rate'],
            'cost_krw'          => $row['cost_krw'],
            'cost_note'         => $row['cost_note'],
            'billing_type'      => $row['billing_type'],
            'list_value_krw'    => $row['list_value_krw'],
            'supply_krw'        => $row['supply_krw'],
            'vat_krw'           => $row['vat_krw'],
            'amount_krw'        => $row['amount_krw'],
            'status'            => $row['status'],
            'received_date'     => $row['received_date'],
            'assignee'          => $row['assignee'],
            'note'              => $row['note'],
        ]);

        return $this->requireId($id);
    }

    public function delete(int $id): void
    {
        $current = $this->requireId($id);
        $this->assertWritable((string) ($current['target_year_month'] ?? ''));
        $stmt = $this->pdo->prepare('DELETE FROM tb_team_revenues WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_team_revenues WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 특정 월의 무상 제공 총액(정상가 합계, billing_type='FREE')
     */
    public function freeValueByMonth(string $yearMonth): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(list_value_krw),0) FROM tb_team_revenues
             WHERE target_year_month = :ym AND billing_type = 'FREE'"
        );
        $stmt->execute(['ym' => $yearMonth]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * 특정 연도의 무상 제공 총액(정상가 합계, billing_type='FREE')
     */
    public function freeValueByYear(string $year): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(list_value_krw),0) FROM tb_team_revenues
             WHERE target_year_month LIKE :like AND billing_type = 'FREE'"
        );
        $stmt->execute(['like' => $year . '-%']);
        return (int) $stmt->fetchColumn();
    }

    private function hasTemplateMonth(int $templateId, string $yearMonth): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM tb_team_revenues
             WHERE revenue_template_id = :tid AND target_year_month = :ym'
        );
        $stmt->execute(['tid' => $templateId, 'ym' => $yearMonth]);

        return $stmt->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertRow(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_team_revenues (
                revenue_template_id, target_year_month, project_name, client_name,
                service_category, solution_name, solution_id, solution_group, campaign_spend_krw, tech_fee_rate, cost_krw, cost_note, supply_krw, vat_krw, amount_krw, billing_type, list_value_krw,
                status, received_date, assignee, note, created_by, created_at
            ) VALUES (
                :revenue_template_id, :target_year_month, :project_name, :client_name,
                :service_category, :solution_name, :solution_id, :solution_group, :campaign_spend_krw, :tech_fee_rate, :cost_krw, :cost_note, :supply_krw, :vat_krw, :amount_krw, :billing_type, :list_value_krw,
                :status, :received_date, :assignee, :note, :created_by, :created_at
            )'
        );
        if (!array_key_exists('created_by', $row)) {
            $row['created_by'] = $this->createdBy;
        }
        if (!array_key_exists('service_category', $row)) {
            $row['service_category'] = '';
        }
        if (!array_key_exists('solution_name', $row)) {
            $row['solution_name'] = '';
        }
        if (!array_key_exists('solution_id', $row)) {
            $row['solution_id'] = '';
        }
        if (!array_key_exists('billing_type', $row)) {
            $row['billing_type'] = 'PAID';
        }
        $row += ['solution_group' => '', 'campaign_spend_krw' => 0, 'tech_fee_rate' => null, 'cost_krw' => 0, 'cost_note' => ''];
        if (!array_key_exists('list_value_krw', $row)) {
            $row['list_value_krw'] = 0;
        }
        $stmt->execute($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireId(int $id): array
    {
        $row = $this->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException('수입을 찾을 수 없습니다.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data        정규화 대상(update 시 current+caller 병합본)
     * @param array<string, mixed>|null $callerData  caller 원본(isset 판단용; null=데이터와 동일)
     * @return array<string, mixed>
     */
    private function normalize(array $data, bool $creating, ?array $callerData = null): array
    {
        $callerData ??= $data;
        $name = trim((string) ($data['project_name'] ?? ''));
        $assignee = trim((string) ($data['assignee'] ?? ''));
        $date = trim((string) ($data['received_date'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('프로젝트명은 필수입니다.');
        }
        if ($assignee === '') {
            throw new InvalidArgumentException('담당은 필수입니다.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('입금일은 YYYY-MM-DD 형식이어야 합니다.');
        }
        $money = $this->normalizeMoney($data);
        $category = trim((string) ($data['service_category'] ?? ''));
        if ($category !== '' && !in_array($category, self::SERVICE_CATEGORIES, true)) {
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
            // 무상: 입력한 총액을 무상 제공 가치로 기록하고 실수입은 0(합계 제외)
            if ($listValue === 0) {
                $listValue = $money['amount_krw'];
            }
            $money = ['supply_krw' => 0, 'vat_krw' => 0, 'amount_krw' => 0];
        } else {
            $listValue = 0; // 유상은 정상가 미사용
            // caller가 vat_krw·amount_krw 둘 다 넘기지 않았으면 공급가에서 자동 계산한다.
            // 어느 한쪽이라도 caller가 명시했으면 normalizeMoney() 결과를 그대로 쓴다.
            if (!isset($callerData['vat_krw']) && !isset($callerData['amount_krw'])) {
                $money = self::applySupply($money['supply_krw']);
            }
        }
        $group = self::applySolutionGroup($data, $category, $billing, $callerData, $money);
        $money = $group['money'];
        $cost = self::applyCost($data, $category, $billing);
        $status = strtoupper(trim((string) ($data['status'] ?? ($creating ? 'COMPLETED' : 'COMPLETED'))));
        if ($status !== 'PENDING' && $status !== 'COMPLETED') {
            throw new InvalidArgumentException('상태는 PENDING, COMPLETED 만 허용합니다.');
        }
        $client = trim((string) ($data['client_name'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));

        return [
            'revenue_template_id' => $creating ? null : ($data['revenue_template_id'] ?? null),
            'target_year_month'   => substr($date, 0, 7),
            'project_name'        => $name,
            'client_name'         => $client === '' ? null : $client,
            'service_category'    => $category,
            'solution_name'       => $solutionName,
            'solution_id'         => $solutionId,
            'solution_group'      => $group['solution_group'],
            'campaign_spend_krw'  => $group['campaign_spend_krw'],
            'tech_fee_rate'       => $group['tech_fee_rate'],
            'cost_krw'            => $cost['cost_krw'],
            'cost_note'           => $cost['cost_note'],
            'billing_type'        => $billing,
            'list_value_krw'      => $listValue,
            'supply_krw'          => $money['supply_krw'],
            'vat_krw'             => $money['vat_krw'],
            'amount_krw'          => $money['amount_krw'],
            'status'              => $status,
            'received_date'       => $date,
            'assignee'            => $assignee,
            'note'                => $note === '' ? null : $note,
            'created_by'          => $this->createdBy,
            'created_at'          => Database::nowKst(),
        ];
    }

    /**
     * 솔루션구분·집행금액·테크피 규칙. 수입·반복 설정이 같이 쓴다.
     * 애드테크 유상은 집행금액·테크피 필수이고, caller가 공급가 없이 집행금액·테크피를 보내면 공급가를 자동 계산한다.
     *
     * @param array<string, mixed> $data        정규화 대상(병합본)
     * @param array<string, mixed> $callerData  caller 원본(isset 판단용)
     * @param array{supply_krw: int, vat_krw: int, amount_krw: int} $money
     * @return array{solution_group: string, campaign_spend_krw: int, tech_fee_rate: ?float, money: array{supply_krw: int, vat_krw: int, amount_krw: int}}
     */
    public static function applySolutionGroup(array $data, string $category, string $billing, array $callerData, array $money): array
    {
        $result = ['solution_group' => '', 'campaign_spend_krw' => 0, 'tech_fee_rate' => null, 'money' => $money];
        if ($category !== '솔루션') {
            return $result;
        }
        $group = strtoupper(trim((string) ($data['solution_group'] ?? '')));
        if (!in_array($group, self::SOLUTION_GROUPS, true)) {
            throw new InvalidArgumentException('솔루션구분을 선택해 주세요.');
        }
        $result['solution_group'] = $group;
        if ($group !== 'ADTECH' || $billing !== 'PAID') {
            return $result;
        }
        $spend = (int) str_replace(',', '', (string) ($data['campaign_spend_krw'] ?? 0));
        if ($spend < 1) {
            throw new InvalidArgumentException('집행금액은 1원 이상이어야 합니다.');
        }
        $rateRaw = trim((string) ($data['tech_fee_rate'] ?? ''));
        $rate = is_numeric($rateRaw) ? round((float) $rateRaw, 2) : 0.0;
        if ($rate <= 0 || $rate > 100) {
            throw new InvalidArgumentException('테크피는 0 초과 100 이하여야 합니다.');
        }
        $result['campaign_spend_krw'] = $spend;
        $result['tech_fee_rate'] = $rate;
        $changed = isset($callerData['campaign_spend_krw']) || isset($callerData['tech_fee_rate']);
        if ($changed && !isset($callerData['supply_krw'])) {
            $result['money'] = self::applySupply((int) round($spend * $rate / 100));
        }

        return $result;
    }

    /**
     * 원가 규칙. 수입·반복 설정이 같이 쓴다.
     * 개발비·유지보수 유상만 원가를 받고, 나머지는 0·빈 메모로 지운다.
     *
     * @param array<string, mixed> $data
     * @return array{cost_krw: int, cost_note: string}
     */
    public static function applyCost(array $data, string $category, string $billing): array
    {
        if (!in_array($category, self::COST_CATEGORIES, true) || $billing !== 'PAID') {
            return ['cost_krw' => 0, 'cost_note' => ''];
        }
        $cost = (int) str_replace(',', '', trim((string) ($data['cost_krw'] ?? 0)));
        if ($cost < 0) {
            throw new InvalidArgumentException('원가는 0 이상이어야 합니다.');
        }

        return [
            'cost_krw'  => $cost,
            'cost_note' => mb_substr(trim((string) ($data['cost_note'] ?? '')), 0, 255),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{supply_krw: int, vat_krw: int, amount_krw: int}
     */
    private function normalizeMoney(array $data): array
    {
        $supply = (int) ($data['supply_krw'] ?? 0);
        $vat = (int) ($data['vat_krw'] ?? 0);
        $amount = (int) ($data['amount_krw'] ?? 0);
        if ($supply < 0 || $vat < 0 || $amount < 0) {
            throw new InvalidArgumentException('금액은 0 이상이어야 합니다.');
        }
        $field = trim((string) ($data['money_field'] ?? ''));
        if ($field === 'supply') {
            return self::applySupply($supply);
        }
        if ($field === 'vat') {
            return [
                'supply_krw' => $supply,
                'vat_krw'    => $vat,
                'amount_krw' => $supply + $vat,
            ];
        }
        if ($field === 'amount') {
            return [
                'supply_krw' => $supply,
                'vat_krw'    => $vat,
                'amount_krw' => $amount,
            ];
        }

        return [
            'supply_krw' => $supply,
            'vat_krw'    => $vat,
            'amount_krw' => $amount,
        ];
    }

    private function assertYearMonth(string $yearMonth): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
            throw new InvalidArgumentException('대상월은 YYYY-MM 형식이어야 합니다.');
        }
    }

    private function assertWritable(string $yearMonth): void
    {
        if (!$this->isWritableMonth($yearMonth)) {
            throw new InvalidArgumentException('전전월 이전은 수정할 수 없습니다.');
        }
    }
}
