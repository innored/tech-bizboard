<?php
declare(strict_types=1);
require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';
$path = sys_get_temp_dir() . '/tbb_revtpl_' . bin2hex(random_bytes(4)) . '.db';
Database::reset(); Database::setPath($path); $pdo = Database::connection();
$tpl = new RevenueTemplateProvider($pdo); $tpl->setCreatedBy('lucy@innored.co.kr');
$row = $tpl->create([
    'project_name'=>'솔루션 유지보수','client_name'=>'콘버스','supply_krw'=>1000000,
    'assignee'=>'루시','start_year_month'=>'2026-01','end_year_month'=>'2026-12',
    'service_category'=>'솔루션 서비스',
]);
expect_eq((string) $row['service_category'], '솔루션 서비스', '템플릿 서비스구분 저장');

expect_eq((string) $row['billing_type'], 'PAID', '템플릿 기본 유상');
expect_eq((int) $row['list_value_krw'], 0, '유상 템플릿 정상가 0');
// 부가세·총액 미지정 → 공급가 기준 자동계산
expect_eq((int) $row['vat_krw'], 100000, '유상 템플릿 부가세 자동계산');
expect_eq((int) $row['amount_krw'], 1100000, '유상 템플릿 총액 자동계산');

$rev = new TeamRevenueProvider($pdo, $tpl); $rev->setCreatedBy('lucy@innored.co.kr');
$rev->ensureMonth('2026-06');
$rows = $rev->listByMonth('2026-06');
expect_true(count($rows) >= 1, '월 자동생성 행 존재');
expect_eq((string) $rows[0]['service_category'], '솔루션 서비스', '생성 행에 템플릿 category 복사');
expect_eq((string) $rows[0]['billing_type'], 'PAID', '유상 템플릿 → 유상 수입');
expect_eq((int) $rows[0]['supply_krw'], 1000000, '유상 템플릿 공급가 전개');
expect_eq((int) $rows[0]['vat_krw'], 100000, '템플릿 부가세 전개 복사');
expect_eq((int) $rows[0]['amount_krw'], 1100000, '템플릿 총액 전개 복사');

// 부가세·총액 수동 override → 그대로 저장되고 전개 시 복사
$ov = $tpl->create([
    'project_name'=>'수동금액','client_name'=>'콘버스','assignee'=>'루시',
    'start_year_month'=>'2026-01','end_year_month'=>'2026-12',
    'service_category'=>'컨설팅','billing_type'=>'PAID',
    'supply_krw'=>1000000,'vat_krw'=>50000,'amount_krw'=>1050000,
]);
expect_eq((int) $ov['vat_krw'], 50000, '수동 부가세 저장');
expect_eq((int) $ov['amount_krw'], 1050000, '수동 총액 저장');
$rev->ensureMonth('2026-05');
$may = $rev->listByMonth('2026-05');
$ovRow = null;
foreach ($may as $r) { if ((int) ($r['revenue_template_id'] ?? 0) === (int) $ov['id']) { $ovRow = $r; break; } }
expect_true($ovRow !== null, '수동금액 템플릿 전개 행 존재');
expect_eq((int) $ovRow['vat_krw'], 50000, '수동 부가세 전개 복사');
expect_eq((int) $ovRow['amount_krw'], 1050000, '수동 총액 전개 복사');

/* 무상 템플릿: 공급가 0·정상가 보존, 전개 시 FREE·금액 0·정상가 승계 */
$free = $tpl->create([
    'project_name'=>'무상 컨설팅','client_name'=>'콘버스','assignee'=>'루시',
    'start_year_month'=>'2026-01','end_year_month'=>'2026-12',
    'service_category'=>'컨설팅','billing_type'=>'FREE',
    'supply_krw'=>500000,'list_value_krw'=>500000,
]);
expect_eq((string) $free['billing_type'], 'FREE', '무상 템플릿 저장');
expect_eq((int) $free['supply_krw'], 0, '무상 템플릿 공급가 0');
expect_eq((int) $free['list_value_krw'], 500000, '무상 템플릿 정상가 보존');

$rev->ensureMonth('2026-07');
$jul = $rev->listByMonth('2026-07');
$freeRow = null;
foreach ($jul as $r) { if ((int) $r['revenue_template_id'] === (int) $free['id']) { $freeRow = $r; break; } }
expect_true($freeRow !== null, '무상 템플릿 전개 행 존재');
expect_eq((string) $freeRow['billing_type'], 'FREE', '무상 템플릿 → 무상 수입');
expect_eq((int) $freeRow['amount_krw'], 0, '무상 수입 합계 0');
expect_eq((int) $freeRow['list_value_krw'], 500000, '무상 수입 정상가 승계');

/* 수정: 유상 → 무상 전환 */
$updated = $tpl->update((int) $row['id'], [
    'project_name'=>'솔루션 유지보수','client_name'=>'콘버스','assignee'=>'루시',
    'start_year_month'=>'2026-01','end_year_month'=>'2026-12',
    'service_category'=>'솔루션 서비스','billing_type'=>'FREE',
    'supply_krw'=>1000000,'list_value_krw'=>1200000,
]);
expect_eq((string) $updated['billing_type'], 'FREE', '유상→무상 수정');
expect_eq((int) $updated['supply_krw'], 0, '무상 전환 시 공급가 0');
expect_eq((int) $updated['list_value_krw'], 1200000, '무상 전환 시 정상가 저장');

/* 반복 해제(사용 1→0): 메모에 중지 일시 기록 + 이후 월 자동생성 안 됨 */
$rep = $tpl->create([
    'project_name'=>'연간 구독','client_name'=>'콘버스','assignee'=>'루시',
    'start_year_month'=>'2026-01','end_year_month'=>'2026-12',
    'service_category'=>'솔루션 서비스','billing_type'=>'PAID','supply_krw'=>300000,
    'note'=>'원본메모',
]);
$off = $tpl->update((int) $rep['id'], [
    'project_name'=>'연간 구독','client_name'=>'콘버스','assignee'=>'루시',
    'start_year_month'=>'2026-01','end_year_month'=>'2026-12',
    'service_category'=>'솔루션 서비스','billing_type'=>'PAID','supply_krw'=>300000,
    'note'=>'원본메모','is_active'=>0,
]);
expect_eq((int) $off['is_active'], 0, '반복 해제됨');
expect_true(strpos((string) $off['note'], '원본메모') !== false, '기존 메모 보존');
expect_true(strpos((string) $off['note'], '[반복 중지 ') !== false, '중지 일시 메모 기록');

$rev->ensureMonth('2026-08');
$aug = $rev->listByMonth('2026-08');
$repAug = null;
foreach ($aug as $r) { if ((int) ($r['revenue_template_id'] ?? 0) === (int) $rep['id']) { $repAug = $r; break; } }
expect_true($repAug === null, '반복 해제 템플릿은 이후 월 자동생성 안 됨');

/* 재활성 시 다시 생성됨 (중지 전환이 아니므로 추가 스탬프 없음) */
$on = $tpl->update((int) $rep['id'], [
    'project_name'=>'연간 구독','client_name'=>'콘버스','assignee'=>'루시',
    'start_year_month'=>'2026-01','end_year_month'=>'2026-12',
    'service_category'=>'솔루션 서비스','billing_type'=>'PAID','supply_krw'=>300000,
    'note'=>(string) $off['note'],'is_active'=>1,
]);
expect_eq((int) $on['is_active'], 1, '반복 재활성');
$rev->ensureMonth('2026-09');
$sep = $rev->listByMonth('2026-09');
$repSep = null;
foreach ($sep as $r) { if ((int) ($r['revenue_template_id'] ?? 0) === (int) $rep['id']) { $repSep = $r; break; } }
expect_true($repSep !== null, '재활성 후 월 자동생성 재개');

tbb_test_done();
