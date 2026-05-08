<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/lp_reverse_csrf.php';
require_once $cmsRoot . '/lib/JobRegistry.php';

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
    $jobId = trim((string) ($body['job_id'] ?? ''));
    if ($jobId === '') {
        throw new InvalidArgumentException('job_id required');
    }
    $status = trim((string) ($body['status'] ?? 'done'));
    $error = isset($body['error']) ? trim((string) $body['error']) : null;
    $result = isset($body['result']) && is_array($body['result']) ? $body['result'] : null;

    $reg = new JobRegistry($cmsRoot);
    if (!$reg->canManage($jobId, $actor)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'このジョブは更新できません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $reg->finish($jobId, $status, $result, $error);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

