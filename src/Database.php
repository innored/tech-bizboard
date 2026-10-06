<?php
/**
 * DB 연결·스키마·시드
 *
 * 테스트는 Database::setPath()로 SQLite를 쓰고,
 * 앱은 config/app_config.php 의 db 블록(MySQL)을 쓴다.
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/Database.php
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;
    private static ?string $path = null;

    public static function setPath(string $path): void
    {
        self::$path = $path;
        self::$pdo = null;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$path = null;
    }

    public static function dbPath(): string
    {
        return self::$path ?? tbb_root() . '/storage/tech-bizboard.db';
    }

    public static function storageDir(): string
    {
        return dirname(self::dbPath());
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (self::$path !== null) {
            self::$pdo = self::connectSqlite(self::$path);
        } else {
            self::$pdo = self::connectFromConfig();
        }

        self::migrate(self::$pdo);
        if (self::templateCount(self::$pdo) === 0) {
            self::seed(self::$pdo);
        }

        return self::$pdo;
    }

    public static function nowKst(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    }

    /**
     * @return array{dsn: string, user: string, password: string}|null
     */
    public static function mysqlConfig(): ?array
    {
        $db = tbb_config()['db'] ?? null;
        if (!is_array($db)) {
            return null;
        }
        $dsn = trim((string) ($db['dsn'] ?? ''));
        $user = trim((string) ($db['user'] ?? ''));
        if ($dsn === '' || $user === '' || !str_starts_with($dsn, 'mysql:')) {
            return null;
        }

        return [
            'dsn'      => $dsn,
            'user'     => $user,
            'password' => (string) ($db['password'] ?? ''),
        ];
    }

    private static function connectFromConfig(): PDO
    {
        $mysql = self::mysqlConfig();
        if ($mysql !== null) {
            return new PDO($mysql['dsn'], $mysql['user'], $mysql['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            ]);
        }

        $dir = self::storageDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return self::connectSqlite(self::dbPath());
    }

    private static function connectSqlite(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $file = $driver === 'mysql'
            ? tbb_root() . '/sql/schema.mysql.sql'
            : tbb_root() . '/sql/schema.sql';
        $sql = file_get_contents($file);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException($file . ' 을 읽을 수 없습니다.');
        }
        if ($driver === 'sqlite') {
            $sql = self::sqliteOnlySchema($sql);
        }
        if (self::hasTable($pdo, 'tb_team_revenues')) {
            self::ensureRevenueColumns($pdo, $driver);
        }
        foreach (self::splitStatements($sql) as $stmt) {
            if ($driver === 'mysql' && self::isUniqueIndexCreate($stmt)) {
                self::ensureMysqlUniqueIndex($pdo, $stmt);
                continue;
            }
            if ($driver === 'mysql' && self::isPlainIndexCreate($stmt)) {
                self::ensureMysqlIndex($pdo, $stmt);
                continue;
            }
            $pdo->exec($stmt);
        }
        self::ensureColumn(
            $pdo,
            'tb_expense_templates',
            'payment_site',
            $driver === 'mysql' ? 'VARCHAR(255) NULL' : 'TEXT'
        );
        $tplText = $driver === 'mysql' ? 'VARCHAR(200) NULL' : 'TEXT';
        foreach (['vendor', 'period_pattern', 'payment_method_text', 'pay_request_pattern', 'attachment_text'] as $col) {
            self::ensureColumn($pdo, 'tb_expense_templates', $col, $tplText);
        }
        self::ensureColumn(
            $pdo,
            'tb_expense_templates',
            'content_mode',
            $driver === 'mysql' ? "VARCHAR(16) NOT NULL DEFAULT 'LINES'" : "TEXT NOT NULL DEFAULT 'LINES'"
        );
        self::ensureColumn(
            $pdo,
            'tb_draft_expenses',
            'contents_text',
            $driver === 'mysql' ? 'TEXT NULL' : 'TEXT'
        );
        self::ensureColumn(
            $pdo,
            'tb_draft_payment_items',
            'item_kind',
            $driver === 'mysql' ? "VARCHAR(16) NOT NULL DEFAULT 'PAY'" : "TEXT NOT NULL DEFAULT 'PAY'"
        );
        self::ensureColumn(
            $pdo,
            'tb_draft_payment_items',
            'vendor',
            $driver === 'mysql' ? 'VARCHAR(200) NULL' : 'TEXT'
        );
        self::ensureColumn(
            $pdo,
            'tb_draft_payment_items',
            'note',
            $driver === 'mysql' ? 'TEXT NULL' : 'TEXT'
        );
        // 결제 항목 ↔ 첨부(영수증) 연결. 기존 DB엔 멱등 추가(FK는 두지 않음: 저장 시 유효성 검증).
        self::ensureColumn(
            $pdo,
            'tb_draft_payment_items',
            'receipt_id',
            $driver === 'mysql' ? 'INT NULL' : 'INTEGER'
        );
        self::ensureRevenueColumns($pdo, $driver);
        self::ensureCreatedByColumns($pdo, $driver);
        self::ensureDraftStatusNoCopied($pdo, $driver);
    }

    /**
     * 예전 COPIED 값을 DRAFT 로 돌리고, ENUM 에서 COPIED 를 뺀다.
     */
    private static function ensureDraftStatusNoCopied(PDO $pdo, string $driver): void
    {
        if (!self::hasTable($pdo, 'tb_draft_expenses')) {
            return;
        }
        $pdo->exec("UPDATE tb_draft_expenses SET status = 'DRAFT' WHERE status = 'COPIED'");
        if ($driver !== 'mysql') {
            return;
        }
        $stmt = $pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column'
        );
        $stmt->execute(['table' => 'tb_draft_expenses', 'column' => 'status']);
        $type = (string) $stmt->fetchColumn();
        if ($type === '' || !str_contains($type, 'COPIED')) {
            return;
        }
        $pdo->exec(
            "ALTER TABLE tb_draft_expenses
             MODIFY status ENUM('DRAFT', 'DONE') NOT NULL DEFAULT 'DRAFT'"
        );
    }

    /**
     * 작성자 컬럼을 created_by 로 맞춘다. 예전 author_email 이 있으면 옮긴다.
     */
    private static function ensureCreatedByColumns(PDO $pdo, string $driver): void
    {
        $tables = [
            'tb_expense_templates',
            'tb_draft_expenses',
            'tb_revenue_templates',
            'tb_team_revenues',
        ];
        $definition = $driver === 'mysql'
            ? "VARCHAR(255) NOT NULL DEFAULT ''"
            : "TEXT NOT NULL DEFAULT ''";
        foreach ($tables as $table) {
            if (!self::hasTable($pdo, $table)) {
                continue;
            }
            $hasAuthor = self::hasColumn($pdo, $table, 'author_email');
            $hasCreated = self::hasColumn($pdo, $table, 'created_by');
            if ($hasAuthor && !$hasCreated) {
                self::renameColumn($pdo, $table, 'author_email', 'created_by', $definition);
                continue;
            }
            self::ensureColumn($pdo, $table, 'created_by', $definition);
            if ($hasAuthor && $hasCreated) {
                $pdo->exec(
                    'UPDATE ' . $table . '
                     SET created_by = author_email
                     WHERE (created_by IS NULL OR created_by = \'\')
                       AND author_email IS NOT NULL
                       AND author_email <> \'\''
                );
                self::dropColumn($pdo, $table, 'author_email');
            }
        }
    }

    private static function ensureRevenueColumns(PDO $pdo, string $driver): void
    {
        if (!self::hasTable($pdo, 'tb_team_revenues')) {
            return;
        }
        self::ensureColumn(
            $pdo,
            'tb_team_revenues',
            'revenue_template_id',
            $driver === 'mysql' ? 'INT NULL' : 'INTEGER'
        );
        self::ensureColumn(
            $pdo,
            'tb_team_revenues',
            'target_year_month',
            $driver === 'mysql' ? 'VARCHAR(7) NULL' : 'TEXT'
        );
        self::ensureColumn(
            $pdo,
            'tb_team_revenues',
            'supply_krw',
            $driver === 'mysql' ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'
        );
        self::ensureColumn(
            $pdo,
            'tb_team_revenues',
            'vat_krw',
            $driver === 'mysql' ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'
        );
        $pdo->exec(
            "UPDATE tb_team_revenues
             SET target_year_month = substr(received_date, 1, 7)
             WHERE target_year_month IS NULL OR target_year_month = ''"
        );
        $pdo->exec(
            "UPDATE tb_team_revenues
             SET supply_krw = amount_krw
             WHERE supply_krw = 0 AND vat_krw = 0 AND amount_krw <> 0"
        );

        // 반복 템플릿 유/무상·정상가·부가세·총액 (수입 등록 모달과 입력 통일)
        if (self::hasTable($pdo, 'tb_revenue_templates')) {
            self::ensureColumn(
                $pdo,
                'tb_revenue_templates',
                'billing_type',
                $driver === 'mysql' ? "VARCHAR(10) NOT NULL DEFAULT 'PAID'" : "TEXT NOT NULL DEFAULT 'PAID'"
            );
            $intDef = $driver === 'mysql' ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
            self::ensureColumn($pdo, 'tb_revenue_templates', 'vat_krw', $intDef);
            self::ensureColumn($pdo, 'tb_revenue_templates', 'amount_krw', $intDef);
            self::ensureColumn($pdo, 'tb_revenue_templates', 'list_value_krw', $intDef);
        }
    }

    private static function hasTable(PDO $pdo, string $table): bool
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table'
            );
            $stmt->execute(['table' => $table]);

            return (int) $stmt->fetchColumn() > 0;
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table"
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * CREATE DATABASE / USER / GRANT / USE 는 MySQL 전용이다.
     */
    private static function sqliteOnlySchema(string $sql): string
    {
        $keep = [];
        foreach (self::splitStatements($sql) as $stmt) {
            if (preg_match('/^(CREATE\s+DATABASE|CREATE\s+USER|GRANT\s+|FLUSH\s+PRIVILEGES|USE\s+)/i', $stmt) === 1) {
                continue;
            }
            $keep[] = $stmt;
        }

        return implode(";\n", $keep) . ';';
    }

    /**
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $parts = preg_split('/;\s*\R/', $sql) ?: [];
        $out = [];
        foreach ($parts as $stmt) {
            $trim = trim($stmt);
            if ($trim === '' || str_starts_with($trim, '--')) {
                $trim = trim((string) preg_replace('/^--.*$/m', '', $trim));
            }
            if ($trim !== '') {
                $out[] = $trim;
            }
        }

        return $out;
    }

    private static function isUniqueIndexCreate(string $stmt): bool
    {
        return preg_match('/^CREATE\s+UNIQUE\s+INDEX\b/i', $stmt) === 1;
    }

    private static function isPlainIndexCreate(string $stmt): bool
    {
        return preg_match('/^CREATE\s+INDEX\b/i', $stmt) === 1;
    }

    private static function ensureMysqlIndex(PDO $pdo, string $stmt): void
    {
        if (preg_match('/CREATE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(\S+)\s+ON\s+(\S+)/i', $stmt, $m) !== 1) {
            $pdo->exec($stmt);

            return;
        }
        $index = trim($m[1], '`');
        $table = trim($m[2], '`');
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $check->execute(['table' => $table, 'index' => $index]);
        if ((int) $check->fetchColumn() > 0) {
            return;
        }
        $pdo->exec($stmt);
    }

    private static function ensureMysqlUniqueIndex(PDO $pdo, string $stmt): void
    {
        if (preg_match('/CREATE\s+UNIQUE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(\S+)\s+ON\s+(\S+)/i', $stmt, $m) !== 1) {
            $pdo->exec($stmt);

            return;
        }
        $index = trim($m[1], '`');
        $table = trim($m[2], '`');
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $check->execute(['table' => $table, 'index' => $index]);
        if ((int) $check->fetchColumn() > 0) {
            return;
        }
        $pdo->exec($stmt);
    }

    /**
     * 이미 있는 테이블에 컬럼이 없으면 추가한다.
     */
    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (self::hasColumn($pdo, $table, $column)) {
            return;
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private static function renameColumn(
        PDO $pdo,
        string $table,
        string $from,
        string $to,
        string $definition
    ): void {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->exec(
                'ALTER TABLE `' . $table . '` CHANGE `' . $from . '` `' . $to . '` ' . $definition
            );

            return;
        }
        $pdo->exec('ALTER TABLE ' . $table . ' RENAME COLUMN ' . $from . ' TO ' . $to);
    }

    private static function dropColumn(PDO $pdo, string $table, string $column): void
    {
        if (!self::hasColumn($pdo, $table, $column)) {
            return;
        }
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->exec('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');

            return;
        }
        try {
            $pdo->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column);
        } catch (PDOException) {
            // 구버전 SQLite는 DROP COLUMN 을 지원하지 않는다.
        }
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);

            return (int) $stmt->fetchColumn() > 0;
        }
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private static function seed(PDO $pdo): void
    {
        if (self::templateCount($pdo) > 0) {
            return;
        }
        $sql = file_get_contents(tbb_root() . '/sql/seed_templates.sql');
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('sql/seed_templates.sql 을 읽을 수 없습니다.');
        }
        $pdo->exec($sql);
    }

    private static function templateCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM tb_expense_templates')->fetchColumn();
    }
}
