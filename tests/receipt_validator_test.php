<?php
declare(strict_types=1);

require __DIR__ . '/assert.php';
require dirname(__DIR__) . '/src/bootstrap.php';

// 정상 PDF
expect_true(ReceiptValidator::check('%PDF-1.7 hello', 'application/pdf') === null, '정상 PDF 통과');
// 지원하지 않는 형식
expect_true(str_contains((string) ReceiptValidator::check('x', 'image/heic'), '형식'), 'HEIC 반려');
// 암호화 PDF
expect_true(str_contains((string) ReceiptValidator::check("%PDF-1.7 ... /Encrypt 12 0 R", 'application/pdf'), '암호'), '암호화 PDF 반려');
// 용량 초과
$big = str_repeat('a', 33 * 1024 * 1024);
expect_true(str_contains((string) ReceiptValidator::check($big, 'application/pdf'), '용량'), '용량 초과 반려');

tbb_test_done();
