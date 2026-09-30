<?php
/**
 * SSO 클라이언트 미설정 안내
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/sso_required.php
 */

declare(strict_types=1);

$PAGE_TITLE = 'TechBizBoard · SSO 설정 필요';
$SHOW_NAV = false;
require __DIR__ . '/_header.php';
?>
<main class="app-main">
  <div class="app-content">
    <div class="page-head">
      <h1 class="text-h1">SSO 설정 필요</h1>
      <p class="text-sm">Account Hub 클라이언트를 등록한 뒤 .env 의 SSO_CLIENT_ID/SSO_CLIENT_SECRET 를 채워 주세요.</p>
    </div>
    <div class="alert alert-info" role="status">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
      <div>
        <p class="alert-body">Redirect URI: <?= h((string) (tbb_config()['sso']['redirect_uri'] ?? '')) ?></p>
        <p class="alert-body">인증 서버: <a href="https://account.innored.co.kr/">account.innored.co.kr</a></p>
      </div>
    </div>
  </div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
