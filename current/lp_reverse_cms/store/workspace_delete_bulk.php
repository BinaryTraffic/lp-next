<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/WorkspaceRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';
require_once $cmsRoot . '/lib/lp_reverse_csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $actor = lp_reverse_store_auth_actor($cmsRoot);
    $raw = (string) file_get_contents('php://input');
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
    $idsIn = $body['workspace_ids'] ?? null;
    if (!is_array($idsIn) || $idsIn === []) {
        throw new InvalidArgumentException('workspace_ids required');
    }

    $reg = new WorkspaceRegistry($cmsRoot);
    $current = strtolower('ws_' . LpWorkspace::id());
    $deleted = [];
    $failed = [];
    $clearedSession = false;

    foreach ($idsIn as $it) {
        $id = strtolower(trim((string) $it));
        if (!preg_match('/^ws_[a-f0-9]{32}$/', $id)) {
            $failed[] = ['id' => $id, 'error' => 'invalid_id'];
            continue;
        }
        $ok = $reg->deleteIfAllowed($id, $actor);
        if ($ok) {
            $deleted[] = $id;
            if ($id === $current) {
                $clearedSession = true;
            }
        } else {
            $failed[] = ['id' => $id, 'error' => 'forbidden_or_missing'];
        }
    }

    if ($clearedSession) {
        unset($_SESSION['lp_reverse_ws']);
    }

    echo json_encode([
        'ok' => true,
        'deleted' => $deleted,
        'failed' => $failed,
        'cleared_session' => $clearedSession,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

