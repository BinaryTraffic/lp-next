<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/JobRegistry.php';
require_once $cmsRoot . '/lib/AnalyzeTask.php';
require_once $cmsRoot . '/lib/GenerateTask.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $actor = lp_reverse_store_auth_actor($cmsRoot);
    $reg = new JobRegistry($cmsRoot);
    $reg->reconcileStaleJobs();
    $jobs = $reg->list($actor, true);

    $actorEmail = strtolower(trim((string) ($actor['email'] ?? '')));
    $actorRole  = strtolower(trim((string) ($actor['role'] ?? '')));
    $isAdmin    = in_array($actorRole, ['admin', 'super_admin'], true);

    // AnalyzeTask / GenerateTask のアクティブエントリを JobRegistry 形式でマージ
    $taskScans = [
        'analyze'  => ['dir' => 'analyze_tasks',  'prefix' => 'ana_', 'pattern' => '/^ana_[a-f0-9]{24}$/'],
        'generate' => ['dir' => 'generate_tasks', 'prefix' => 'gen_', 'pattern' => '/^gen_[a-f0-9]{24}$/'],
    ];
    foreach ($taskScans as $type => $cfg) {
        $dir = $cmsRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $cfg['dir'];
        if (!is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . $cfg['prefix'] . '*.json') ?: [] as $file) {
            $taskId = basename($file, '.json');
            if (!preg_match($cfg['pattern'], $taskId)) {
                continue;
            }
            $task = $type === 'analyze'
                ? AnalyzeTask::load($cmsRoot, $taskId)
                : GenerateTask::load($cmsRoot, $taskId);
            if (!is_array($task)) {
                continue;
            }
            $st = (string) ($task['status'] ?? '');
            if (!in_array($st, ['pending', 'running'], true)) {
                continue;
            }
            $owner = strtolower(trim((string) ($task['owner_email'] ?? '')));
            if (!$isAdmin && $owner !== $actorEmail) {
                continue;
            }
            $startedAt = (int) ($task['started_at'] ?? 0);
            $jobs[] = [
                'id'                   => $taskId,
                'type'                 => $type,
                'status'               => 'running',
                'workspace_id'         => (string) ($task['workspace_id'] ?? ''),
                'owner_email'          => $owner,
                'owner_role'           => (string) ($task['owner_role'] ?? ''),
                'purpose'              => $type === 'analyze'
                    ? (string) ($task['source_url'] ?? '')
                    : 'LP生成',
                'source_url'           => (string) ($task['source_url'] ?? ''),
                'started_at'           => $startedAt > 0 ? gmdate('c', $startedAt) : gmdate('c'),
                'last_heartbeat_at'    => gmdate('c', (int) ($task['updated_at'] ?? $startedAt)),
                'abort_requested'      => false,
                'abort_requested_by'   => null,
                'abort_requested_at'   => null,
                'ended_at'             => null,
                'result'               => null,
                'error'                => null,
                'progress_text'        => (string) ($task['progress_text'] ?? ''),
            ];
        }
    }

    foreach ($jobs as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $wid = strtolower(trim((string) ($row['workspace_id'] ?? '')));
        if (preg_match('/^ws_[a-f0-9]{32}$/', $wid) !== 1) {
            $jobs[$idx]['workspace_disk_present'] = false;

            continue;
        }
        $dataDir = $cmsRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $wid;
        $outDir  = $cmsRoot . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . $wid;
        $jobs[$idx]['workspace_disk_present'] = is_dir($dataDir) || is_dir($outDir);
    }

    echo json_encode(['ok' => true, 'jobs' => array_values($jobs)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

