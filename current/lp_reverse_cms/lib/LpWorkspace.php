<?php

declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

/**
 * Per-browser-session workspace: data/ws_{id}/ and output/ws_{id}/.
 * Isolates fetch / generate / assets on shared hosts so one user cannot
 * overwrite another's preview (v1.2+).
 */
final class LpWorkspace
{
    private static bool $bootstrapped = false;

    private static string $id = '';

    public static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        if (PHP_SAPI === 'cli') {
            $forced = strtolower(trim((string) getenv('LP_WORKSPACE_ID')));
            if (self::isValidId($forced)) {
                self::$id = $forced;
            } else {
                self::$id = 'cli';
            }

            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            lp_reverse_session_start();
        }

        $sid = $_SESSION['lp_reverse_ws'] ?? '';
        if (!is_string($sid) || !self::isValidId($sid)) {
            $sid = bin2hex(random_bytes(16));
            $_SESSION['lp_reverse_ws'] = $sid;
        }

        self::$id = $sid;
    }

    public static function isValidId(string $id): bool
    {
        return strlen($id) === 32 && ctype_xdigit($id);
    }

    public static function id(): string
    {
        self::bootstrap();

        return self::$id;
    }

    /** Force re-bootstrap (use after putenv to switch workspace mid-request). */
    public static function reset(): void
    {
        self::$bootstrapped = false;
        self::$id = '';
    }

    public static function dataDir(string $cmsRoot): string
    {
        self::bootstrap();

        return rtrim($cmsRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'data'
            . DIRECTORY_SEPARATOR
            . 'ws_'
            . self::$id
            . DIRECTORY_SEPARATOR;
    }

    /**
     * auth_users.json の所在（ワークスペース ws_* とは別。全セッションで共有）。
     */
    public static function authRegistryDir(string $cmsRoot): string
    {
        return rtrim($cmsRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'data'
            . DIRECTORY_SEPARATOR;
    }

    public static function outputDir(string $cmsRoot): string
    {
        self::bootstrap();

        return rtrim($cmsRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'output'
            . DIRECTORY_SEPARATOR
            . 'ws_'
            . self::$id
            . DIRECTORY_SEPARATOR;
    }

    /** Leading slash; extends historical /output/... paths. */
    public static function outputWebAbsPrefix(): string
    {
        self::bootstrap();

        return '/output/ws_' . self::$id . '/';
    }

    /** Relative to CMS root (no leading slash). */
    public static function outputRelIndex(): string
    {
        self::bootstrap();

        return 'output/ws_' . self::$id . '/index.html';
    }
}
