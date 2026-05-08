<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/WorkspaceRegistry.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $actor = lp_reverse_store_auth_actor($cmsRoot);
    $reg   = new WorkspaceRegistry($cmsRoot);
    $list  = $reg->listForActor($actor);

    echo json_encode(
        [
            'ok'          => true,
            'workspaces'  => $list,
            'current_ws'  => 'ws_' . LpWorkspace::id(),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
