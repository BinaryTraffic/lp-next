<?php

declare(strict_types=1);

/**
 * Minimal .env loader (no Composer). Does not overwrite existing getenv() / $_ENV.
 */
function lp_reverse_load_env(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    if ($path === null) {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
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
        if (getenv($k) === false) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}
