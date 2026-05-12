<?php

declare(strict_types=1);

/**
 * Minimal .env loader (no Composer). Does not overwrite existing getenv() / $_ENV.
 *
 * 既定のキー置き場（このリポジトリ）:
 *   lp_reverse_cms/.env
 * 例（GCP VM 等）:
 *   /home/lp-next/current/lp_reverse_cms/.env
 *
 * 別パスを使う場合のみ、プロセス環境変数 LP_REVERSE_ENV_PATH に .env の絶対パスを設定。
 */
function lp_reverse_env_file_path(): string
{
    $override = getenv('LP_REVERSE_ENV_PATH');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
}

function lp_reverse_load_env(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    if ($path === null) {
        $path = lp_reverse_env_file_path();
    }
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === '') {
            continue;
        }
        if ($v !== '' && str_starts_with($v, '"') && str_ends_with($v, '"')) {
            $v = stripcslashes(substr($v, 1, -1));
        } elseif ($v !== '' && str_starts_with($v, "'") && str_ends_with($v, "'")) {
            $v = substr($v, 1, -1);
        }
        // php-fpm 等で変数だけ空文字セットされていると getenv()!==false で .env が無視されるため、
        // 「未設定または空」のときだけ .env の値を適用する（非空のプロセス環境は上書きしない）。
        $prior = getenv($k);

        if ($prior !== false && trim((string) $prior) !== '') {
            continue;
        }

        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
}
