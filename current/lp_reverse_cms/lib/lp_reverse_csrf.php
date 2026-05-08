<?php

declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

/**
 * Session-backed CSRF token for JSON POST endpoints (workspace_delete.php 等).
 */
function lp_reverse_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        lp_reverse_session_start();
    }
    $t = $_SESSION['lp_reverse_csrf'] ?? null;
    if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) {
        $t = bin2hex(random_bytes(32));
        $_SESSION['lp_reverse_csrf'] = $t;
    }

    return $t;
}

function lp_reverse_csrf_validate(?string $fromClient): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        lp_reverse_session_start();
    }
    $expected = $_SESSION['lp_reverse_csrf'] ?? null;
    if (!is_string($expected) || $expected === '') {
        return false;
    }
    $s = trim((string) ($fromClient ?? ''));

    return $s !== '' && hash_equals($expected, $s);
}
