<?php
/**
 * 설정 로드와 공통 함수
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/bootstrap.php
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');

require_once dirname(__DIR__) . '/lib/env_loader.php';
tbb_load_env(dirname(__DIR__) . '/.env');

// 동적 HTML·API 응답은 캐시하지 않는다. 정적 CSS/JS는 Apache가 직접 주되
// tbb_asset()의 ?v=filemtime 으로 버스팅되므로, 파일을 고치면 새로고침에 바로 반영된다.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate');
}

require_once __DIR__ . '/SsoHandler.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/KrwAmountProcessor.php';
require_once __DIR__ . '/KoreanWonProcessor.php';
require_once __DIR__ . '/ExchangeRateProvider.php';
require_once __DIR__ . '/ReceiptAnalyzer.php';
require_once __DIR__ . '/ReceiptValidator.php';
require_once __DIR__ . '/TeamLedgerParser.php';
require_once __DIR__ . '/DraftReceiptProvider.php';
require_once __DIR__ . '/RevenueReceiptProvider.php';
require_once __DIR__ . '/DraftTextProcessor.php';
require_once __DIR__ . '/ExpenseTemplateProvider.php';
require_once __DIR__ . '/DraftExpenseProvider.php';
require_once __DIR__ . '/RevenueTemplateProvider.php';
require_once __DIR__ . '/TeamRevenueProvider.php';
require_once __DIR__ . '/DashboardProvider.php';

function tbb_root(): string
{
    return dirname(__DIR__);
}

/**
 * 설정은 전부 환경변수(.env 또는 진짜 환경변수)에서 읽는다. 비밀값은 코드/웹루트에 두지 않는다.
 * .env 는 bootstrap 맨 위 tbb_load_env()가 미리 올려 둔다.
 */
function tbb_config(): array
{
    $env = static function (string $key, string $default = ''): string {
        $value = getenv($key);

        return (is_string($value) && $value !== '') ? $value : $default;
    };

    $dsn = $env('DB_DSN');
    if ($dsn === '') {
        $host = $env('DB_HOST');
        $name = $env('DB_NAME');
        if ($host !== '' && $name !== '') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $host,
                $env('DB_PORT', '3306'),
                $name
            );
        }
    }

    $requireLogin = in_array(strtolower($env('SSO_REQUIRE_LOGIN', '0')), ['1', 'true', 'on', 'yes'], true);

    // SSO redirect_uri/home_url 은 서버마다 호스트도 경로도 달라서(.env 에 명시):
    //   개발: https://lucy-dev.conbus.co.kr/tech-bizboard/sso/  · 홈 .../tech-bizboard/
    //   운영: https://tbb.conbus.co.kr/sso/                     · 홈 https://tbb.conbus.co.kr/
    // 값이 없으면 빈 문자열 → 로그인이 명시적으로 실패한다(잘못된 호스트로 조용히 안 튐).
    return [
        'exchange_url' => $env('EXCHANGE_URL', 'https://lucy-dev.conbus.co.kr/api/exchange/'),
        'db' => [
            'dsn'      => $dsn,
            'user'     => $env('DB_USER'),
            'password' => $env('DB_PASSWORD'),
        ],
        'sso' => [
            'require_login' => $requireLogin,
            'provider_url'  => $env('SSO_PROVIDER_URL', 'https://account.innored.co.kr'),
            'client_id'     => $env('SSO_CLIENT_ID', 'tbb'),
            'redirect_uri'  => $env('SSO_REDIRECT_URI', ''),
            'home_url'      => $env('SSO_HOME_URL', ''),
        ],
    ];
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** 기안·수입 필터 기본값은 전월(KST)이다. */
function tbb_previous_month(?DateTimeImmutable $now = null): string
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));

    return $now->modify('first day of last month')->format('Y-m');
}

function tbb_today(?DateTimeImmutable $now = null): string
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));

    return $now->format('Y-m-d');
}

/** 에셋 실제 파일은 public/ 아래에 있다. 반환 URL은 /tech-bizboard/ 기준 상대경로. */
function tbb_asset(string $path): string
{
    $full = tbb_root() . '/public/' . ltrim($path, '/');
    $ver = is_file($full) ? (string) filemtime($full) : (string) time();

    return h($path) . '?v=' . $ver;
}

/**
 * @return array<string, string>
 */
function tbb_cycle_types(): array
{
    return [
        'MONTHLY'    => '월간',
        'YEARLY'     => '연간',
        'IRREGULAR'  => '비정기',
    ];
}

function tbb_cycle_label(string $cycle): string
{
    $map = tbb_cycle_types();

    return $map[$cycle] ?? $map['MONTHLY'];
}

/**
 * @return array<string, string>
 */
function tbb_payment_types(): array
{
    return [
        'AUTO'   => '자동결제',
        'MANUAL' => '수기결제',
    ];
}

function tbb_pay_label(string $pay): string
{
    return tbb_payment_types()[$pay] ?? '';
}

/**
 * @return array<string, string>
 */
function tbb_content_modes(): array
{
    return [
        'LINES' => '결제 줄로 만듦',
        'LAST'  => '지난 글 고침',
    ];
}

/**
 * @return array<string, string>
 */
function tbb_item_kinds(): array
{
    return [
        'PAY'    => '결제',
        'CHARGE' => '충전',
    ];
}

/**
 * @return array<string, string>
 */
function tbb_currencies(): array
{
    return [
        'KRW' => 'KRW',
        'USD' => 'USD',
        'EUR' => 'EUR',
        'JPY' => 'JPY',
    ];
}

function tbb_sso(): SsoHandler
{
    $config = tbb_config();
    $sso = $config['sso'] ?? [];
    if (!is_array($sso)) {
        $sso = [];
    }

    return new SsoHandler($sso);
}

function tbb_guard(): SsoHandler
{
    $sso = tbb_sso();
    $sso->startSession();
    $sso->requireLogin();
    tbb_touch_user($sso);

    return $sso;
}

/** 로그인한 사용자를 email→name 디렉터리(tb_users)에 적립한다(영수증 이메일 매칭용). 실패해도 요청은 계속. */
function tbb_touch_user(SsoHandler $sso): void
{
    try {
        $u = $sso->getUser();
        $email = trim((string) ($u['email'] ?? ''));
        $name = trim((string) ($u['name'] ?? ''));
        if ($email === '' || $name === '') {
            return;
        }
        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT INTO tb_users (email, name, updated_at) VALUES (:e, :n, :u)
               ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = VALUES(updated_at)'
            : 'INSERT INTO tb_users (email, name, updated_at) VALUES (:e, :n, :u)
               ON CONFLICT(email) DO UPDATE SET name = excluded.name, updated_at = excluded.updated_at';
        $pdo->prepare($sql)->execute(['e' => $email, 'n' => $name, 'u' => Database::nowKst()]);
    } catch (Throwable $e) {
        // 디렉터리 적립 실패는 무시(로그인 흐름 보호)
    }
}

/** email→name 디렉터리에서 이름을 찾는다. 없으면 빈 문자열. */
function tbb_name_for_email(string $email): string
{
    $email = trim($email);
    if ($email === '') {
        return '';
    }
    try {
        $stmt = Database::connection()->prepare('SELECT name FROM tb_users WHERE email = :e');
        $stmt->execute(['e' => $email]);

        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function tbb_is_api_request(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($script !== '' && str_contains($script, '/public/proc/')) {
        return true;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

    return (bool) preg_match('#/tech-bizboard/api/#', $uri);
}

/** 미로그인 첫 화면. 호출하면 여기서 끝난다. */
function tbb_render_login(SsoHandler $sso): never
{
    $LOGIN_HREF = $sso->isConfigured() ? $sso->authorizeUrl() : '#';
    $APPLY_HREF = $sso->applyUrl();
    require tbb_root() . '/view/login.php';
    exit;
}

/** 로그인한 사람 이메일. 세션이 없으면 빈 문자열. */
function tbb_created_by(?SsoHandler $sso = null): string
{
    if ($sso === null && session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $sso ??= tbb_sso();
    $user = $sso->getUser();

    return trim((string) ($user['email'] ?? ''));
}

/** SSO 역할이 admin 이면 참. 세션이 없으면 거짓. */
function tbb_is_admin(?SsoHandler $sso = null): bool
{
    if ($sso === null && session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $sso ??= tbb_sso();

    return $sso->hasRole('admin');
}

/** admin이 아니면 화면은 홈으로, API는 403. */
function tbb_require_admin(?SsoHandler $sso = null): void
{
    $sso ??= tbb_sso();
    if (tbb_is_admin($sso)) {
        return;
    }
    if (tbb_is_api_request()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => '관리자만 할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ./', true, 302);
    exit;
}

function tbb_drafts(): DraftExpenseProvider
{
    $config = tbb_config();
    $url = (string) ($config['exchange_url'] ?? 'https://lucy-dev.conbus.co.kr/api/exchange/');
    $pdo = Database::connection();
    $provider = new DraftExpenseProvider(
        $pdo,
        new ExpenseTemplateProvider($pdo),
        new ExchangeRateProvider($url, tbb_root() . '/storage/cache'),
        new KrwAmountProcessor(),
        new DraftTextProcessor()
    );
    $provider->setCreatedBy(tbb_created_by());
    $provider->setBypassMonthLock(tbb_is_admin());

    return $provider;
}

function tbb_templates(): ExpenseTemplateProvider
{
    $provider = new ExpenseTemplateProvider(Database::connection());
    $provider->setCreatedBy(tbb_created_by());

    return $provider;
}

function tbb_revenue_templates(): RevenueTemplateProvider
{
    $provider = new RevenueTemplateProvider(Database::connection());
    $provider->setCreatedBy(tbb_created_by());

    return $provider;
}

function tbb_revenues(): TeamRevenueProvider
{
    $pdo = Database::connection();
    $provider = new TeamRevenueProvider($pdo, new RevenueTemplateProvider($pdo));
    $provider->setCreatedBy(tbb_created_by());
    $provider->setBypassMonthLock(tbb_is_admin());

    return $provider;
}

function tbb_dashboard(): DashboardProvider
{
    return new DashboardProvider(Database::connection());
}

function tbb_receipt_analyzer(): ReceiptAnalyzer
{
    return new ReceiptAnalyzer((string) getenv('CLAUDE_API_KEY'));
}

function tbb_receipts(): DraftReceiptProvider
{
    $provider = new DraftReceiptProvider(Database::connection(), tbb_root() . '/storage/receipts');
    $provider->setCreatedBy(tbb_created_by());

    return $provider;
}

function tbb_revenue_receipts(): RevenueReceiptProvider
{
    $provider = new RevenueReceiptProvider(Database::connection(), tbb_root() . '/storage/revenue-receipts');
    $provider->setCreatedBy(tbb_created_by());

    return $provider;
}
