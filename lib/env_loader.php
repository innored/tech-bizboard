<?php
/**
 * .env 로더 — KEY=VALUE 한 줄씩 읽어 환경변수로 올린다.
 *
 * - `#` 주석 줄과 빈 줄은 건너뛴다.
 * - 값의 양끝 따옴표("  ' )는 벗긴다.
 * - 이미 실제 환경변수(Apache SetEnv, php-fpm env 등)가 있으면 그쪽을 우선하고 덮어쓰지 않는다.
 *   → 서버마다 .env 파일 대신 진짜 환경변수만 세팅해도 동작한다(포터빌리티).
 *
 * 사용: require 후 tbb_load_env('/경로/.env'); 이후 getenv('DB_HOST') 등으로 읽는다.
 *
 * @date 2026-09-10
 */

declare(strict_types=1);

function tbb_load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($key === '') {
            continue;
        }
        $len = strlen($value);
        if ($len >= 2
            && (($value[0] === '"' && $value[$len - 1] === '"')
                || ($value[0] === "'" && $value[$len - 1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        // 실제 환경변수가 이미 있으면 그대로 둔다.
        if (getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}
