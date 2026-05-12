<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/WorkspaceRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';
require_once $cmsRoot . '/lib/lp_reverse_csrf.php';
require_once $cmsRoot . '/lib/session_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $actor = lp_reverse_store_auth_actor($cmsRoot);

    $raw  = (string) file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        throw new InvalidArgumentException('JSON body required');
    }

    $csrf = isset($body['csrf']) ? trim((string) $body['csrf']) : '';
    if (!lp_reverse_csrf_validate($csrf !== '' ? $csrf : null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF verification failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $workspaceId = strtolower(trim((string) ($body['workspace_id'] ?? '')));
    if (!preg_match('/^ws_[a-f0-9]{32}$/', $workspaceId)) {
        throw new InvalidArgumentException('workspace_id must be ws_ plus 32 hex chars');
    }

    // Verify the actor can access this workspace
    $reg  = new WorkspaceRegistry($cmsRoot);
    $list = $reg->listForActor($actor);
    $found = false;
    foreach ($list as $ws) {
        if ((string) ($ws['id'] ?? '') === $workspaceId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied or workspace not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Switch session to the requested workspace
    if (session_status() === PHP_SESSION_NONE) {
        lp_reverse_session_start();
    }
    $_SESSION['lp_reverse_ws'] = substr($workspaceId, 3); // strip "ws_" prefix
    LpWorkspace::reset();

    echo json_encode(['ok' => true, 'workspace_id' => $workspaceId], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
