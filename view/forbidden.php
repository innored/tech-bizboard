<?php
/**
 * 접근 제한 화면(리더십 리포트). 로그인 화면과 같은 레이아웃.
 * 필요한 변수: $FORBIDDEN_TITLE, $FORBIDDEN_BODY, $FORBIDDEN_ACTION_HREF, $FORBIDDEN_ACTION_LABEL
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/forbidden.php
 */

declare(strict_types=1);

$FORBIDDEN_TITLE = (string) ($FORBIDDEN_TITLE ?? '이 페이지를 열 수 없습니다');
$FORBIDDEN_BODY = (string) ($FORBIDDEN_BODY ?? '');
$FORBIDDEN_ACTION_HREF = (string) ($FORBIDDEN_ACTION_HREF ?? '');
$FORBIDDEN_ACTION_LABEL = (string) ($FORBIDDEN_ACTION_LABEL ?? '');
?>
<!doctype html>
<html lang="ko" class="login-html">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex" />
  <title>TechBizBoard · 접근 제한</title>
  <link rel="stylesheet" href="<?= tbb_asset('asset/fonts/PretendardGOV/pretendardvariable-gov.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/css/login.css') ?>" />
</head>
<body class="login-body">
  <div class="login-split">
    <section class="login-ledger" aria-labelledby="forbidden-title">
      <p class="login-mark">TechBizBoard</p>
      <p class="login-kicker">테크본부</p>
      <h1 id="forbidden-title" class="login-title">테크본부<br />수익 리포트</h1>
      <p class="login-lead">허용된 네트워크와 계정에서만 볼 수 있는 페이지입니다.</p>
    </section>
    <aside class="login-dock">
      <div class="login-dock-inner">
        <p class="login-dock-kicker">접근 제한</p>
        <p class="login-dock-copy"><strong><?= h($FORBIDDEN_TITLE) ?></strong><br /><?= h($FORBIDDEN_BODY) ?></p>
        <?php if ($FORBIDDEN_ACTION_HREF !== '' && $FORBIDDEN_ACTION_LABEL !== ''): ?>
          <a class="login-stamp" href="<?= h($FORBIDDEN_ACTION_HREF) ?>"><?= h($FORBIDDEN_ACTION_LABEL) ?></a>
        <?php endif; ?>
        <p class="login-hint">문의: 테크본부</p>
      </div>
    </aside>
  </div>
</body>
</html>
