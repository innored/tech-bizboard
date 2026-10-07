<?php
/**
 * 우리 솔루션 목록 (account-hub API 프록시) — 뷰에서 서버사이드로 직접 렌더하므로 보조용
 *
 * @date 2026-10-06
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

tbb_guard();

echo json_encode(['ok' => true, 'solutions' => tbb_solutions()], JSON_UNESCAPED_UNICODE);
