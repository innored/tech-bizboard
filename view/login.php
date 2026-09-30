<?php
/**
 * 미로그인 첫 화면
 *
 * @date 2026-09-14
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/login.php
 */

declare(strict_types=1);

$LOGIN_HREF = (string) ($LOGIN_HREF ?? '#');
$APPLY_HREF = (string) ($APPLY_HREF ?? 'https://account.innored.co.kr/apply');
?>
<!doctype html>
<html lang="ko" class="login-html">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TechBizBoard · 로그인</title>
  <link rel="stylesheet" href="<?= tbb_asset('asset/fonts/PretendardGOV/pretendardvariable-gov.css') ?>" />
  <link rel="stylesheet" href="<?= tbb_asset('asset/css/login.css') ?>" />
</head>
<body class="login-body">
  <div class="login-split">
    <section class="login-ledger" aria-labelledby="login-title">
      <p class="login-mark">TechBizBoard</p>
      <p class="login-kicker">테크본부</p>
      <h1 id="login-title" class="login-title">이번 달 기안<br />매출이랑 맞춥니다</h1>
      <p class="login-lead">기안 올리고 매입이랑 매출을 같은 장부에 둡니다.</p>
      <p class="login-rule" aria-hidden="true"><span>기안</span><span>매출</span><span>손익</span></p>
    </section>
    <aside class="login-dock">
      <div class="login-dock-inner">
        <p class="login-dock-kicker">로그인</p>
        <p class="login-dock-copy">이노레드 통합 계정(SSO)으로 안전하게 로그인하세요.</p>
        <a class="login-stamp" href="<?= h($LOGIN_HREF) ?>">이노레드 통합 로그인</a>
        <p class="login-hint">아직 계정이 없으신가요? <a class="login-apply" href="<?= h($APPLY_HREF) ?>">가입 신청</a></p>
      </div>
    </aside>
  </div>
</body>
</html>
