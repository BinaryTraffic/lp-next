<?php

declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/LpWorkspace.php';
require_once __DIR__ . '/UserRegistry.php';

/**
 * Require a logged-in, approved CMS user; return email + role for workspace APIs.
 *
 * @return array{email: string, role: string}
 */
function lp_reverse_store_auth_actor(string $cmsRoot): array
{
    if (session_status() === PHP_SESSION_NONE) {
        lp_reverse_session_start();
    }
    lp_reverse_load_env();

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $email = strtolower(trim((string) ($_SESSION['auth']['email'] ?? '')));
    if ($email === '') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $reg  = new UserRegistry(LpWorkspace::authRegistryDir($cmsRoot));
    $role = $reg->getRole($email);
    if ($role === null || in_array($role, ['pending', 'rejected'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return ['email' => $email, 'role' => $role];
}
