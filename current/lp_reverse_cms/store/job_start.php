<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/lp_reverse_csrf.php';
require_once $cmsRoot . '/lib/JobRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

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
    $csrf = trim((string) ($body['csrf'] ?? ''));
    if (!lp_reverse_csrf_validate($csrf !== '' ? $csrf : null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF verification failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $type = trim((string) ($body['type'] ?? ''));
    $purpose = trim((string) ($body['purpose'] ?? ''));
    $sourceUrl = trim((string) ($body['source_url'] ?? ''));
    $workspaceId = strtolower(trim((string) ($body['workspace_id'] ?? ('ws_' . LpWorkspace::id()))));

    if ($purpose === '') {
        throw new InvalidArgumentException('purpose is required');
    }

    $reg = new JobRegistry($cmsRoot);
    $job = $reg->start($actor, [
        'type' => $type,
        'purpose' => $purpose,
        'workspace_id' => $workspaceId,
        'source_url' => $sourceUrl,
    ]);
    echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

