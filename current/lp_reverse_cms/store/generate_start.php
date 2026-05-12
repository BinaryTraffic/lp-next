<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/lp_reverse_csrf.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';
require_once $cmsRoot . '/lib/GenerateTask.php';
require_once $cmsRoot . '/lib/AnalyzeTask.php';

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
    $clientData = $body['client_data'] ?? null;
    if (!is_array($clientData)) {
        throw new InvalidArgumentException('client_data required');
    }
    // Use session workspace; fall back to latest completed analyze task's workspace
    // if the session workspace has no analysis data (e.g. session changed after analyze completed).
    $workspaceId = 'ws_' . LpWorkspace::id();
    $dataDir = rtrim($cmsRoot, '/\\') . '/data/' . $workspaceId . '/';
    if (!is_readable($dataDir . 'lp_structure.json')) {
        $latestAnalyzeId = AnalyzeTask::latestTaskIdForActor($cmsRoot, (string) $actor['email']);
        if ($latestAnalyzeId !== '') {
            $latestAnalyze = AnalyzeTask::load($cmsRoot, $latestAnalyzeId);
            if (is_array($latestAnalyze) && ($latestAnalyze['status'] ?? '') === 'done') {
                $wsCandidate = (string) ($latestAnalyze['workspace_id'] ?? '');
                if (preg_match('/^ws_[a-f0-9]{32}$/', $wsCandidate)) {
                    $workspaceId = $wsCandidate;
                }
            }
        }
    }
    $created = GenerateTask::createIfNotRunning($cmsRoot, $actor, $workspaceId, $clientData);
    if (empty($created['already_running'])) {
        $worker = $cmsRoot . '/tools/generate_worker.php';
        // mod_php では PHP_BINARY が .so になるため CLI バイナリを明示的に解決する
        $phpBin = PHP_BINARY;
        if (!is_file($phpBin) || !is_executable($phpBin)) {
            $phpBin = trim((string) shell_exec('which php8.2 2>/dev/null'))
                   ?: trim((string) shell_exec('which php 2>/dev/null'))
                   ?: '/usr/bin/php8.2';
        }
        $prefix = stripos(PHP_OS, 'darwin') === 0 ? 'nohup' : 'nohup setsid';
        $cmd = $prefix . ' ' . escapeshellarg($phpBin) . ' '
            . escapeshellarg($worker) . ' '
            . escapeshellarg($cmsRoot) . ' '
            . escapeshellarg((string) $created['task_id'])
            . ' > /tmp/generate_worker_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $created['task_id']) . '.log 2>&1 &';
        shell_exec($cmd);
    }

    echo json_encode([
        'ok' => true,
        'task_id' => $created['task_id'],
        'workspace_id' => $workspaceId,
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

