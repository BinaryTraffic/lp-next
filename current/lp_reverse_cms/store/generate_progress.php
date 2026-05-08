<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/GenerateTask.php';

function lp_generate_pid_matches_task(int $pid, string $taskId): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (!function_exists('posix_kill') || !@posix_kill($pid, 0)) {
        return false;
    }
    $cmdlinePath = '/proc/' . $pid . '/cmdline';
    if (!is_readable($cmdlinePath)) {
        return true;
    }
    $cmdline = (string) file_get_contents($cmdlinePath);
    if ($cmdline === '') {
        return true;
    }
    $cmdline = str_replace("\0", ' ', $cmdline);

    return str_contains($cmdline, 'tools/generate_worker.php')
        && str_contains($cmdline, $taskId);
}

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
        $updatedAt = (int) ($task['updated_at'] ?? $startAt);
        $age = $startAt > 0 ? (time() - $startAt) : PHP_INT_MAX;
        $idleSec = $updatedAt > 0 ? (time() - $updatedAt) : PHP_INT_MAX;
        $pidAlive = lp_generate_pid_matches_task($pid, $taskId);
        if (!$pidAlive || $age > 900 || $idleSec > 600) {
            $task['status'] = 'stale';
            $task['error'] = !$pidAlive
                ? 'worker process not found or pid reused (pid=' . $pid . ')'
                : ($age > 900 ? 'timeout (>900s)' : 'no heartbeat/update (>600s)');
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

