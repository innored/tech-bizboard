<?php
/**
 * 업로드된 영수증 원본 파일을 서버에 보관하고, (템플릿·월) 단위로 조회/다운로드/삭제한다.
 * 파일은 storage/receipts 아래에 내부 파일명으로 저장하고, 표시 이름(display_name)은 DB에 둔다.
 *
 * @date 2026-09-15
 */

declare(strict_types=1);

class DraftReceiptProvider
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
     *
     * @return array<string, mixed>
     */
    public function store(int $templateId, string $yearMonth, string $displayName, string $bytes, string $mime, string $uploadedName = ''): array
    {
        if ($templateId < 1) {
            throw new InvalidArgumentException('템플릿이 필요합니다.');
        }
        if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
            throw new InvalidArgumentException('대상 월 형식이 올바르지 않습니다.');
        }

        $ext = self::extFromMime($mime);
        $sub = $yearMonth; // YYYY-MM
        $dir = $this->baseDir . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('저장 폴더를 만들 수 없습니다.');
        }

        $display = self::sanitize($displayName);
        if ($display === '') {
            $display = '영수증' . $ext;
        } elseif (pathinfo($display, PATHINFO_EXTENSION) === '') {
            $display .= $ext;
        }
        $display = $this->uniqueDisplayName($templateId, $yearMonth, $display);

        $stored = bin2hex(random_bytes(16)) . $ext;
        $rel = $sub . '/' . $stored;
        if (file_put_contents($dir . '/' . $stored, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('파일 저장에 실패했습니다.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO tb_draft_receipts
                (expense_template_id, target_year_month, display_name, stored_name, mime, size_bytes, uploaded_by, uploaded_name, created_at)
             VALUES (:t, :ym, :dn, :sn, :mime, :sz, :by, :un, :ca)'
        );
        $stmt->execute([
            't'    => $templateId,
            'ym'   => $yearMonth,
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

    /** AI 분석 결과를 캐시로 저장한다(재분석 시 API 재호출 방지). */
    public function saveAnalysis(int $id, array $payload): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tb_draft_receipts SET analysis_json = :j, analyzed_at = :a WHERE id = :id'
        );
        $stmt->execute([
            'j'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'a'  => Database::nowKst(),
            'id' => $id,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFor(int $templateId, string $yearMonth): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tb_draft_receipts
             WHERE expense_template_id = :t AND target_year_month = :ym
             ORDER BY id'
        );
        $stmt->execute(['t' => $templateId, 'ym' => $yearMonth]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tb_draft_receipts WHERE id = :id');
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
        $this->pdo->prepare('DELETE FROM tb_draft_receipts WHERE id = :id')->execute(['id' => $id]);
    }

    /** AI 분석 후 추출 내용에 맞춰 표시 이름을 바꾼다(확장자는 저장본 유지, 중복 자동번호). */
    public function rename(int $id, string $display): array
    {
        $row = $this->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException('파일을 찾을 수 없습니다.');
        }
        $display = self::sanitize($display);
        if ($display === '') {
            return $row; // 빈 이름이면 기존 유지
        }
        $storedExt = pathinfo((string) ($row['stored_name'] ?? ''), PATHINFO_EXTENSION);
        if ($storedExt !== '') {
            $display = pathinfo($display, PATHINFO_FILENAME) . '.' . $storedExt;
        }
        $display = $this->uniqueDisplayName(
            (int) ($row['expense_template_id'] ?? 0),
            (string) ($row['target_year_month'] ?? ''),
            $display,
            $id
        );
        $this->pdo->prepare('UPDATE tb_draft_receipts SET display_name = :d WHERE id = :id')
            ->execute(['d' => $display, 'id' => $id]);

        return $this->findById($id) ?? $row;
    }

    private function uniqueDisplayName(int $templateId, string $yearMonth, string $display, int $exceptId = 0): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, display_name FROM tb_draft_receipts WHERE expense_template_id = :t AND target_year_month = :ym'
        );
        $stmt->execute(['t' => $templateId, 'ym' => $yearMonth]);
        $existing = [];
        foreach ($stmt->fetchAll() as $r) {
            if ((int) ($r['id'] ?? 0) === $exceptId) {
                continue;
            }
            $existing[(string) ($r['display_name'] ?? '')] = true;
        }
        if (!isset($existing[$display])) {
            return $display;
        }
        $ext = pathinfo($display, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($display, 0, -(strlen($ext) + 1)) : $display;
        $suffix = $ext !== '' ? '.' . $ext : '';
        $n = 1;
        do {
            $candidate = $base . '_' . $n . $suffix;
            $n++;
        } while (isset($existing[$candidate]));

        return $candidate;
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
