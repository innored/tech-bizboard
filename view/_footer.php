<?php
/**
 * 공통 하단
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/view/_footer.php
 */

$holidayJs = dirname(tbb_root()) . '/api/holidays/holidays.bundle.js';
$holidaySrc = 'https://lucy-dev.conbus.co.kr/api/holidays/holidays.bundle.js';
if (is_file($holidayJs)) {
    $holidaySrc .= '?v=' . filemtime($holidayJs);
}
?>
<div class="alert alert-success draft-toast" id="draft-toast" role="status" aria-live="polite">
  <svg data-toast-kind="info" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
  <svg data-toast-kind="success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
  <svg data-toast-kind="danger" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
  <div>
    <p class="alert-title"></p>
    <p class="alert-body"></p>
  </div>
</div>
<script src="<?= tbb_asset('asset/vendor/air-datepicker/air-datepicker.js') ?>"></script>
<script src="<?= h($holidaySrc) ?>"></script>
<script src="<?= tbb_asset('asset/js/air-datepicker-init.js') ?>"></script>
<script src="<?= tbb_asset('asset/js/toast.js') ?>"></script>
<?php
$PAGE_SCRIPTS = is_array($PAGE_SCRIPTS ?? null) ? $PAGE_SCRIPTS : [];
foreach ($PAGE_SCRIPTS as $src):
?>
<script src="<?= tbb_asset((string) $src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
