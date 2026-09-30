<?php
/**
 * Account Hub SSO — 코드 교환·세션·재조회
 *
 * @date 2026-09-14
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/SsoHandler.php
 */

declare(strict_types=1);

class SsoHandler
{
    /**
     * @param array{
     *   provider_url?: string,
     *   client_id?: string,
     *   redirect_uri?: string,
     *   home_url?: string,
     *   require_login?: bool
     * } $config
     * @param null|callable(string, array<string, string>): array{0: int, 1: string} $httpGet
     */
    public function __construct(
        private array $config,
        private int $lifetimeSeconds = 43200,
        private mixed $httpGet = null
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['client_id'] ?? '')) !== '';
    }

    /**
     * require_login이 false면 SSO 없이 페이지를 연다.
     * 키가 없으면 클라이언트가 있을 때만 로그인을 요구한다.
     */
    public function isLoginRequired(): bool
    {
        if (array_key_exists('require_login', $this->config)) {
            return (bool) $this->config['require_login'];
        }

        return $this->isConfigured();
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            return;
        }
        session_name('techbizboard');
        session_set_cookie_params([
            'lifetime' => $this->lifetimeSeconds,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * 화면에 줄 사용자. access_token은 넣지 않는다.
     *
     * @return array{
     *   sub: string,
     *   email: string,
     *   name: string,
     *   roles: list<string>,
     *   is_demo: bool,
     *   company?: string,
     *   department?: string,
     *   position?: string
     * }|null
     */
    public function getUser(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user)) {
            return null;
        }
        $sub = trim((string) ($user['sub'] ?? ''));
        if ($sub === '') {
            return null;
        }
        $roles = $user['roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }
        $safe = [
            'sub'     => $sub,
            'email'   => trim((string) ($user['email'] ?? '')),
            'name'    => trim((string) ($user['name'] ?? '')),
            'roles'   => array_values(array_map('strval', $roles)),
            'is_demo' => (bool) ($user['is_demo'] ?? false),
        ];
        foreach (['company', 'department', 'position'] as $key) {
            $value = trim((string) ($user[$key] ?? ''));
            if ($value !== '') {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** SSO 역할. admin / member. 대소문자는 가리지 않는다. */
    public function hasRole(string $role): bool
    {
        $needle = strtolower(trim($role));
        if ($needle === '') {
            return false;
        }
        foreach ($this->getUser()['roles'] ?? [] as $have) {
            if (strtolower((string) $have) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['tbb_csrf'])) {
            $_SESSION['tbb_csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['tbb_csrf'];
    }

    public function verifyCsrf(string $csrf): bool
    {
        if ($csrf === '') {
            return false;
        }

        return hash_equals($this->csrfToken(), $csrf);
    }

    public function authorizeUrl(): string
    {
        return $this->hubUrl('/authorize', [
            'client_id'    => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    public function logoutUrl(): string
    {
        return $this->hubUrl('/logout', [
            'client_id' => $this->clientId(),
            'redirect'  => $this->homeUrl(),
        ]);
    }

    public function homeUrl(): string
    {
        $home = trim((string) ($this->config['home_url'] ?? ''));
        if ($home !== '') {
            return $home;
        }

        // config(home_url)가 비면 특정 서버로 튀지 않도록 사이트 루트로 폴백한다.
        // (운영은 루트, 개발은 /tech-bizboard/ 이므로 홈 경로는 .env 로 명시하는 게 정확)
        return '/';
    }

    /** 허브 계정 정보 수정. 데모 계정은 화면에서 이 주소를 쓰지 않는다. */
    public function accountUrl(): string
    {
        return rtrim($this->providerUrl(), '/') . '/account';
    }

    /** 허브 가입 신청 */
    public function applyUrl(): string
    {
        return rtrim($this->providerUrl(), '/') . '/apply';
    }

    public function exchangeCode(string $code): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }
        $url = rtrim($this->providerUrl(), '/') . '/userinfo?code=' . rawurlencode($code);
        [$status, $body] = $this->request($url, []);
        if ($status !== 200) {
            return false;
        }

        return $this->storeUserinfo($body, true);
    }

    public function refreshUser(): bool
    {
        $token = trim((string) ($_SESSION['sso_at'] ?? ''));
        if ($token === '') {
            return $this->getUser() !== null;
        }
        [$status, $body] = $this->request(rtrim($this->providerUrl(), '/') . '/userinfo', [
            'Authorization' => 'Bearer ' . $token,
        ]);
        if ($status === 401 || $status === 403) {
            $this->logout();

            return false;
        }
        if ($status !== 200) {
            return $this->getUser() !== null;
        }

        return $this->storeUserinfo($body, false);
    }

    public function logout(): void
    {
        unset($_SESSION['user'], $_SESSION['sso_at']);
    }

    /**
     * 미로그인이면 첫 화면을 보여 준다. API는 401 JSON.
     */
    public function requireLogin(): void
    {
        if (!$this->isLoginRequired()) {
            return;
        }
        if ($this->getUser() !== null) {
            $this->refreshUser();
        }
        if ($this->getUser() !== null) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (function_exists('tbb_is_api_request') && tbb_is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (function_exists('tbb_render_login')) {
            tbb_render_login($this);
        }
    }

    private function storeUserinfo(string $body, bool $keepTokenFromBody): bool
    {
        $user = json_decode($body, true);
        if (!is_array($user)) {
            return false;
        }
        $sub = trim((string) ($user['sub'] ?? ''));
        if ($sub === '') {
            return false;
        }
        if ($keepTokenFromBody) {
            $token = trim((string) ($user['access_token'] ?? ''));
            if ($token !== '') {
                $_SESSION['sso_at'] = $token;
            }
        }
        unset($user['access_token']);
        $_SESSION['user'] = $user;

        return $this->getUser() !== null;
    }

    /**
     * @param array<string, string> $query
     */
    private function hubUrl(string $path, array $query): string
    {
        return rtrim($this->providerUrl(), '/') . $path . '?' . http_build_query($query);
    }

    private function providerUrl(): string
    {
        $url = trim((string) ($this->config['provider_url'] ?? ''));

        return $url !== '' ? $url : 'https://account.innored.co.kr';
    }

    private function clientId(): string
    {
        return trim((string) ($this->config['client_id'] ?? ''));
    }

    private function redirectUri(): string
    {
        return trim((string) ($this->config['redirect_uri'] ?? ''));
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: string}
     */
    private function request(string $url, array $headers): array
    {
        if (is_callable($this->httpGet)) {
            $result = ($this->httpGet)($url, $headers);
            if (is_array($result) && count($result) >= 2) {
                return [(int) $result[0], (string) $result[1]];
            }

            return [0, ''];
        }

        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => $lines,
                'ignore_errors' => true,
                'timeout'       => 10,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        $first = $http_response_header[0] ?? '';
        if (is_string($first) && preg_match('/\s(\d{3})\b/', $first, $match) === 1) {
            $status = (int) $match[1];
        }

        return [$status, is_string($body) ? $body : ''];
    }
}
