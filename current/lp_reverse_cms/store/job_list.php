<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/JobRegistry.php';

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
        $outDir = $cmsRoot . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . $wid;
        $jobs[$idx]['workspace_disk_present'] = is_dir($dataDir) || is_dir($outDir);
    }
    echo json_encode(['ok' => true, 'jobs' => $jobs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

