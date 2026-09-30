<?php
/**
 * 공통 상단
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/_header.php
 */

declare(strict_types=1);

$PAGE_TITLE = $PAGE_TITLE ?? 'TechBizBoard';
$SHOW_NAV = $SHOW_NAV ?? false;
$SHOW_LOGOUT = $SHOW_LOGOUT ?? false;
$NAV = $NAV ?? '';
$SSO_USER = $SSO_USER ?? null;
?>
<!doctype html>
<html lang="ko" data-theme="light">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= h($PAGE_TITLE) ?></title>
  <link rel="stylesheet" href="<?= tbb_asset('asset/fonts/PretendardGOV/pretendardvariable-gov.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/css/design-system.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/css/themes.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/css/palettes.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/css/mobile.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/vendor/air-datepicker/air-datepicker.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/css/air-datepicker-theme.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/css/tech-bizboard.css') ?>" />
  <?php if (!empty($CSRF_TOKEN)): ?>
  <meta name="csrf-token" content="<?= h((string) $CSRF_TOKEN) ?>" />
  <?php endif; ?>
</head>
<body>
<?php
$navItems = [
    ['href' => './',         'label' => '대시보드', 'key' => 'dash'],
    ['href' => 'drafts',     'label' => '매입(지출)', 'key' => 'drafts'],
    ['href' => 'revenues',   'label' => '매출(수입)',     'key' => 'revenues'],
    ['href' => 'api-usage',  'label' => 'API 사용량', 'key' => 'api_usage'],
    ['href' => 'settings',   'label' => '설정',     'key' => 'settings'],
];
if (!tbb_is_admin()) {
    $adminOnly = ['settings', 'api_usage'];
    $navItems = array_values(array_filter(
        $navItems,
        static fn (array $item): bool => !in_array($item['key'], $adminOnly, true)
    ));
}
?>
<header class="app-header">
  <a class="brand" href="./" aria-label="TechBizBoard 홈">
    <span class="brand-text">
      <span class="name">TechBizBoard</span>
      <!-- <span class="ver">테크본부의 업무와 손익을 한눈에</span> -->
    </span>
  </a>
  <?php if ($SHOW_NAV): ?>
  <nav class="app-nav" aria-label="주요 메뉴">
    <?php foreach ($navItems as $item): ?>
      <a href="<?= h($item['href']) ?>"<?= $NAV === $item['key'] ? ' class="is-active" aria-current="page"' : '' ?>><?= h($item['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="app-header-actions">
    <?php
      $ssoLabel = is_array($SSO_USER)
          ? trim((string) (($SSO_USER['name'] ?? '') !== '' ? $SSO_USER['name'] : ($SSO_USER['email'] ?? '')))
          : '';
      $ssoIsDemo = is_array($SSO_USER) && !empty($SSO_USER['is_demo']);
      $ssoAccount = (function_exists('tbb_sso') && $ssoLabel !== '' && !$ssoIsDemo)
          ? tbb_sso()->accountUrl()
          : '';
    ?>
    <?php
      $ssoFace = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.4"/><path d="M5.6 19a6.4 6.4 0 0 1 12.8 0"/></svg>';
    ?>
    <?php if ($ssoLabel !== '' && $ssoAccount !== ''): ?>
      <a class="sso-user" href="<?= h($ssoAccount) ?>" title="허브에서 정보 수정" target="_blank" rel="noopener noreferrer" onclick="var w=window.open(this.href,'innored-account'); if(w){w.opener=null; return false;}"><?= $ssoFace ?><span><?= h($ssoLabel) ?></span></a>
    <?php elseif ($ssoLabel !== ''): ?>
      <span class="sso-user is-static" title="데모 계정은 정보를 바꿀 수 없습니다"><?= $ssoFace ?><span><?= h($ssoLabel) ?></span></span>
    <?php endif; ?>
    <?php if ($SHOW_LOGOUT): ?>
    <a class="btn btn-ghost btn-icon logout-btn" href="sso/logout" aria-label="로그아웃" title="로그아웃">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
    </a>
    <?php endif; ?>
    <button type="button" class="nav-toggle" id="nav-toggle" aria-label="메뉴 열기" aria-expanded="false" aria-controls="nav-sheet">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
  </div>
  <div class="nav-sheet" id="nav-sheet">
    <nav class="sheet-nav" aria-label="모바일 메뉴">
      <?php foreach ($navItems as $item): ?>
        <a href="<?= h($item['href']) ?>"<?= $NAV === $item['key'] ? ' class="is-active" aria-current="page"' : '' ?>><?= h($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <?php endif; ?>
</header>
<?php if ($SHOW_NAV): ?><div class="nav-dim" id="nav-dim"></div><?php endif; ?>
<script src="<?= tbb_asset('asset/js/nav.js') ?>"></script>
<script src="<?= tbb_asset('asset/js/design-system.js') ?>"></script>
