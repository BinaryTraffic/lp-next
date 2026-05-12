<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$cmsRoot = rtrim((string) ($argv[1] ?? ''), "/\\");
$taskId = strtolower(trim((string) ($argv[2] ?? '')));
if ($cmsRoot === '' || $taskId === '') {
    fwrite(STDERR, "usage: php workspace_delete_async_worker.php <cmsRoot> <taskId>\n");
    exit(1);
}

require_once $cmsRoot . '/lib/WorkspaceDeleteTask.php';
require_once $cmsRoot . '/lib/WorkspaceRegistry.php';

try {
    $task = WorkspaceDeleteTask::load($cmsRoot, $taskId);
    if (!is_array($task)) {
        exit(0);
    }
    $status = (string) ($task['status'] ?? '');
    if ($status === 'done' || $status === 'error') {
        exit(0);
    }

    $task['status'] = 'running';
    $task['pid'] = (int) getmypid();
    $task['started_at'] = time();
    WorkspaceDeleteTask::save($cmsRoot, $taskId, $task);

    $actor = [
        'email' => (string) ($task['owner_email'] ?? ''),
        'role' => (string) ($task['owner_role'] ?? 'admin'),
    ];
    $ids = (array) ($task['workspace_ids'] ?? []);
    $total = max(1, (int) ($task['total'] ?? count($ids)));
    $reg = new WorkspaceRegistry($cmsRoot);

    $done = (int) ($task['done'] ?? 0);
    $deleted = (int) ($task['deleted'] ?? 0);
    $failed = is_array($task['failed'] ?? null) ? (array) $task['failed'] : [];

    foreach ($ids as $idx => $rawId) {
        $id = strtolower(trim((string) $rawId));
        $task['current'] = $id;
        WorkspaceDeleteTask::save($cmsRoot, $taskId, $task);

        if (preg_match('/^ws_[a-f0-9]{32}$/', $id) !== 1) {
            $failed[] = ['id' => $id, 'error' => 'invalid_id'];
        } else {
            try {
                $ok = $reg->deleteIfAllowed($id, $actor);
                if ($ok) {
                    $deleted++;
                } else {
                    $failed[] = ['id' => $id, 'error' => 'forbidden_or_missing'];
                }
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        $done = $idx + 1;
        $task['done'] = $done;
        $task['deleted'] = $deleted;
        $task['failed'] = $failed;
        $task['progress_text'] = sprintf('%03d/%03d', $done, $total);
        WorkspaceDeleteTask::save($cmsRoot, $taskId, $task);
    }

    $task['status'] = 'done';
    $task['current'] = '';
    $task['ended_at'] = time();
    $task['progress_text'] = sprintf('%03d/%03d', $total, $total);
    WorkspaceDeleteTask::save($cmsRoot, $taskId, $task);
} catch (Throwable $e) {
    $task = WorkspaceDeleteTask::load($cmsRoot, $taskId);
    if (is_array($task)) {
        $task['status'] = 'error';
        $task['error'] = $e->getMessage();
        $task['ended_at'] = time();
        WorkspaceDeleteTask::save($cmsRoot, $taskId, $task);
    }
}

