<?php
/**
 * 수입 행의 증빙 파일을 서버에 보관하고, revenue_id 단위로 조회/다운로드/삭제한다.
 * 파일은 storage/revenue-receipts/<YYYY-MM>/ 아래에 내부 파일명으로 저장하고,
 * 표시 이름(display_name)은 DB(tb_revenue_receipts)에 둔다.
 * AI 분석 없는 단순 저장 전용.
 *
 * @date 2026-10-01
 */

declare(strict_types=1);

class RevenueReceiptProvider
{
    private string $createdBy = '';

    public function __construct(
        private PDO $pdo,
        private string $baseDir
    ) {
    }

    public function setCreatedBy(string $email): void
    {
        $this->createdBy = $email;
    }

    /**
     * 파일을 저장하고 저장된 레코드를 반환한다.
     * 저장 하위 디렉터리는 revenue의 target_year_month(YYYY-MM)에서 결정한다.
     *
     * @return array<string, mixed>
     */
    public function store(int $revenueId, string $display, string $bytes, string $mime, string $uploadedName = ''): array
    {
        if ($revenueId < 1) {
            throw new InvalidArgumentException('수입 항목이 필요합니다.');
        }

        $yearMonth = $this->fetchYearMonth($revenueId);

        $ext = self::extFromMime($mime);
        $dir = $this->baseDir . '/' . $yearMonth;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('저장 폴더를 만들 수 없습니다.');
        }

        $display = self::sanitize($display);
        if ($display === '') {
            $display = '증빙' . $ext;
        } elseif (pathinfo($display, PATHINFO_EXTENSION) === '') {
            $display .= $ext;
        }

        $stored = bin2hex(random_bytes(16)) . $ext;
        $rel = $yearMonth . '/' . $stored;
        if (file_put_contents($dir . '/' . $stored, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('파일 저장에 실패했습니다.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_revenue_receipts
                (revenue_id, display_name, stored_name, mime, size_bytes, uploaded_by, uploaded_name, created_at)
             VALUES (:rid, :dn, :sn, :mime, :sz, :by, :un, :ca)'
        );
        $stmt->execute([
            'rid'  => $revenueId,
            'dn'   => $display,
            'sn'   => $rel,
            'mime' => $mime,
            'sz'   => strlen($bytes),
            'by'   => $this->createdBy !== '' ? $this->createdBy : null,
            'un'   => $uploadedName !== '' ? $uploadedName : null,
            'ca'   => Database::nowKst(),
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFor(int $revenueId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tb_revenue_receipts WHERE revenue_id = :rid ORDER BY id'
        );
        $stmt->execute(['rid' => $revenueId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_revenue_receipts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** 다운로드용 절대 경로. */
    public function absPath(array $row): string
    {
        return $this->baseDir . '/' . (string) ($row['stored_name'] ?? '');
    }

    public function delete(int $id, string $email, bool $isAdmin): void
    {
        $row = $this->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException('파일을 찾을 수 없습니다.');
        }
        if (!$isAdmin && (string) ($row['uploaded_by'] ?? '') !== $email) {
            throw new InvalidArgumentException('삭제 권한이 없습니다.');
        }
        $path = $this->absPath($row);
        if (is_file($path)) {
            @unlink($path);
        }
        $this->pdo->prepare('DELETE FROM tb_revenue_receipts WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * revenue_id 의 모든 첨부 DB행을 삭제한다(업로더 무관 — 전체 삭제용).
     * 실제 파일 unlink 는 트랜잭션 커밋 후 호출측에서 하도록, 삭제된 파일들의 절대 경로를 반환한다.
     *
     * @return list<string>
     */
    public function deleteAllFor(int $revenueId): array
    {
        $rows = $this->listFor($revenueId);
        if ($rows === []) {
            return [];
        }
        $paths = [];
        foreach ($rows as $row) {
            $paths[] = $this->absPath($row);
        }
        $this->pdo->prepare(
            'DELETE FROM tb_revenue_receipts WHERE revenue_id = :rid'
        )->execute(['rid' => $revenueId]);

        return $paths;
    }

    /** revenue의 target_year_month를 조회해 저장 하위 폴더를 결정한다. */
    private function fetchYearMonth(int $revenueId): string
    {
        $stmt = $this->pdo->prepare('SELECT target_year_month FROM tb_team_revenues WHERE id = :id');
        $stmt->execute(['id' => $revenueId]);
        $ym = $stmt->fetchColumn();
        if (!is_string($ym) || $ym === '') {
            throw new InvalidArgumentException('수입을 찾을 수 없습니다.');
        }
        $ym = trim($ym);
        if (preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            throw new InvalidArgumentException('수입을 찾을 수 없습니다.');
        }

        return $ym;
    }

    private static function sanitize(string $name): string
    {
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|', "\0"], '_', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    private static function extFromMime(string $mime): string
    {
        return [
            'application/pdf' => '.pdf',
            'image/jpeg'      => '.jpg',
            'image/png'       => '.png',
            'image/webp'      => '.webp',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
        ][strtolower(trim($mime))] ?? '';
    }
}
