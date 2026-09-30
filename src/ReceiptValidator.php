<?php
/**
 * 업로드 영수증 파일 검증(형식/용량/암호화 PDF/페이지수 휴리스틱).
 *
 * @date 2026-09-15
 */

declare(strict_types=1);

final class ReceiptValidator
{
    public const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', self::XLSX_MIME];
    private const MAX_BYTES = 32 * 1024 * 1024; // 32MB
    private const MAX_PAGES = 10;

    /** 문제가 있으면 한국어 에러 메시지, 없으면 null. */
    public static function check(string $bytes, string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        if (!in_array($mime, self::ALLOWED, true)) {
            return '지원하지 않는 파일 형식입니다(PDF·JPG·PNG·WEBP·XLSX만 가능). HEIC는 지원하지 않습니다.';
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return '파일 용량이 너무 큽니다(최대 32MB).';
        }
        if ($mime === 'application/pdf') {
            if (str_contains($bytes, '/Encrypt')) {
                return '암호화된 PDF는 분석할 수 없습니다. 비밀번호를 해제한 뒤 다시 업로드하세요.';
            }
            $pages = substr_count($bytes, '/Type/Page') + substr_count($bytes, '/Type /Page');
            if ($pages > self::MAX_PAGES) {
                return 'PDF 페이지 수가 너무 많습니다(최대 ' . self::MAX_PAGES . '페이지).';
            }
        }

        return null;
    }
}
