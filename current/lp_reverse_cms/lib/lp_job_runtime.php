<?php

declare(strict_types=1);

require_once __DIR__ . '/JobRegistry.php';

/**
 * Parse optional job_id from JSON body.
 *
 * @return array{0:array<string,mixed>,1:string}
 */
function lp_job_parse_body_and_id(): array
{
    $raw = (string) file_get_contents('php://input');
    $body = $raw !== '' ? (json_decode($raw, true) ?? []) : [];
    if (!is_array($body)) {
        $body = [];
    }
    $jobId = trim((string) ($body['job_id'] ?? ''));
    return [$body, $jobId];
}

function lp_job_check_abort(JobRegistry $registry, string $jobId, string $message = '処理が停止されました。'): void
{
    if ($jobId !== '' && $registry->isStopRequested($jobId)) {
        $registry->finish($jobId, 'stopped', null, $message);
        throw new RuntimeException($message);
    }
}

