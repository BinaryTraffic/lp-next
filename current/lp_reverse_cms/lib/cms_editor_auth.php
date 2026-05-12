<?php

declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/LpWorkspace.php';
require_once __DIR__ . '/UserRegistry.php';

/** @return non-empty-string|false Email when super_admin/admin, false otherwise */
function lp_reverse_resolve_workspace_editor(string $cmsRoot)
{
    if (session_status() === PHP_SESSION_NONE) {
        lp_reverse_session_start();
    }
    lp_reverse_load_env();

    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        return false;
    }

    $em = strtolower(trim((string) ($_SESSION['auth']['email'] ?? '')));
    if ($em === '') {
        return false;
    }

    $reg = new UserRegistry(LpWorkspace::authRegistryDir($cmsRoot));
    $r   = $reg->getRole($em);

    return ($r === 'super_admin' || $r === 'admin') ? $em : false;
}
