<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/GenerateTask.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $actor = lp_reverse_store_auth_actor($cmsRoot);
    $taskId = strtolower(trim((string) ($_GET['task_id'] ?? '')));
    if ($taskId === '') {
        $taskId = GenerateTask::latestTaskIdForActor($cmsRoot, (string) $actor['email']);
    }
    if ($taskId === '') {
        echo json_encode([
            'ok' => true,
            'exists' => false,
            'status' => 'none',
            'phase' => '',
            'progress_text' => '000/000',
            'done' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $task = GenerateTask::load($cmsRoot, $taskId);
    if (!is_array($task) || !GenerateTask::canView($task, $actor)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'task not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = (string) ($task['status'] ?? 'error');
    if ($status === 'running') {
        $pid = (int) ($task['pid'] ?? 0);
        $startAt = (int) ($task['started_at'] ?? 0);
        $age = $startAt > 0 ? (time() - $startAt) : PHP_INT_MAX;
        $pidAlive = $pid > 0 && (function_exists('posix_kill') ? @posix_kill($pid, 0) : true);
        if (!$pidAlive || $age > 900) {
            $task['status'] = 'stale';
            $task['error'] = $pidAlive
                ? 'timeout (>900s)'
                : 'worker process not found (pid=' . $pid . ')';
            GenerateTask::save($cmsRoot, $taskId, $task);
            $status = 'stale';
        }
    }

    echo json_encode([
        'ok' => true,
        'exists' => true,
        'task_id' => $taskId,
        'workspace_id' => (string) ($task['workspace_id'] ?? ''),
        'status' => $status,
        'phase' => (string) ($task['phase'] ?? ''),
        'progress_text' => (string) ($task['progress_text'] ?? '000/000'),
        'error' => (string) ($task['error'] ?? ''),
        'done' => in_array($status, ['done', 'error', 'stale'], true),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

