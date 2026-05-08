<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/lp_reverse_csrf.php';
require_once $cmsRoot . '/lib/WorkspaceDeleteTask.php';

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
    $ids = [];
    foreach ($idsIn as $it) {
        $id = strtolower(trim((string) $it));
        if (preg_match('/^ws_[a-f0-9]{32}$/', $id) !== 1) {
            continue;
        }
        $ids[$id] = true;
    }
    $ids = array_keys($ids);
    if ($ids === []) {
        throw new InvalidArgumentException('valid workspace_ids required');
    }

    $created = WorkspaceDeleteTask::createIfNotRunning($cmsRoot, $actor, $ids);
    $taskId = $created['task_id'];
    if (empty($created['already_running'])) {
        $worker = $cmsRoot . '/tools/workspace_delete_async_worker.php';
        $cmd = 'nohup setsid php ' . escapeshellarg($worker) . ' '
            . escapeshellarg($cmsRoot) . ' '
            . escapeshellarg($taskId)
            . ' > /dev/null 2>&1 &';
        shell_exec($cmd);
    }

    echo json_encode([
        'ok' => true,
        'task_id' => $taskId,
        'already_running' => !empty($created['already_running']),
        'progress_text' => $created['progress_text'],
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

