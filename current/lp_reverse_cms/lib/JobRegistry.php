<?php

declare(strict_types=1);

/**
 * Shared job registry (data/job_registry.json).
 *
 * Tracks analyze/generate jobs with owner and purpose so multi-user environments
 * can identify and stop long-running processes safely.
 */
final class JobRegistry
{
    private string $filePath;

    private string $lockPath;

    public function __construct(string $cmsRoot)
    {
        $root = rtrim($cmsRoot, '/\\');
        $this->filePath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'job_registry.json';
        $this->lockPath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.job_registry.lock';
    }

    /**
     * @param array{email:string,role:string} $actor
     * @param array{type:string,purpose:string,workspace_id:string,source_url:string} $meta
     * @return array<string,mixed>
     */
    public function start(array $actor, array $meta): array
    {
        $owner = strtolower(trim((string) ($actor['email'] ?? '')));
        $role = strtolower(trim((string) ($actor['role'] ?? 'preview')));
        $type = strtolower(trim((string) ($meta['type'] ?? '')));
        $purpose = trim((string) ($meta['purpose'] ?? ''));
        $workspaceId = strtolower(trim((string) ($meta['workspace_id'] ?? '')));
        $sourceUrl = trim((string) ($meta['source_url'] ?? ''));

        if ($owner === '' || $purpose === '' || !preg_match('/^ws_[a-f0-9]{32}$/', $workspaceId)) {
            throw new InvalidArgumentException('job start payload is invalid');
        }
        if (!in_array($type, ['analyze', 'generate'], true)) {
            throw new InvalidArgumentException('type must be analyze or generate');
        }

        return $this->withLock(LOCK_EX, function () use ($owner, $role, $type, $purpose, $workspaceId, $sourceUrl): array {
            $root = $this->readRoot();
            /** @var array<string,array<string,mixed>> $jobs */
            $jobs = $root['jobs'];

            foreach ($jobs as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (($row['workspace_id'] ?? '') !== $workspaceId) {
                    continue;
                }
                $st = (string) ($row['status'] ?? '');
                if (in_array($st, ['running', 'stopping'], true)) {
                    throw new RuntimeException('同じワークスペースで別ジョブが実行中です。先に停止または完了させてください。');
                }
            }

            $id = 'job_' . bin2hex(random_bytes(12));
            $now = gmdate('c');
            $jobs[$id] = [
                'id' => $id,
                'type' => $type,
                'status' => 'running',
                'workspace_id' => $workspaceId,
                'owner_email' => $owner,
                'owner_role' => $role,
                'purpose' => $purpose,
                'source_url' => $sourceUrl,
                'started_at' => $now,
                'last_heartbeat_at' => $now,
                'abort_requested' => false,
                'abort_requested_by' => null,
                'abort_requested_at' => null,
                'ended_at' => null,
                'result' => null,
                'error' => null,
            ];

            $root['jobs'] = $jobs;
            $this->writeRoot($root);

            return $jobs[$id];
        });
    }

    public function heartbeat(string $jobId, ?string $message = null): void
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return;
        }
        $this->withLock(LOCK_EX, function () use ($jobId, $message): void {
            $root = $this->readRoot();
            if (!isset($root['jobs'][$jobId]) || !is_array($root['jobs'][$jobId])) {
                return;
            }
            $row = $root['jobs'][$jobId];
            if (!in_array((string) ($row['status'] ?? ''), ['running', 'stopping'], true)) {
                return;
            }
            $row['last_heartbeat_at'] = gmdate('c');
            if ($message !== null && trim($message) !== '') {
                $row['last_message'] = trim($message);
            }
            $root['jobs'][$jobId] = $row;
            $this->writeRoot($root);
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(array $actor, bool $onlyActive = true): array
    {
        $email = strtolower(trim((string) ($actor['email'] ?? '')));
        $role = strtolower(trim((string) ($actor['role'] ?? 'preview')));
        $root = $this->withLock(LOCK_SH, fn (): array => $this->readRoot());
        $jobs = $root['jobs'];
        $out = [];
        foreach ($jobs as $row) {
            if (!is_array($row)) {
                continue;
            }
            $st = (string) ($row['status'] ?? '');
            if ($onlyActive && !in_array($st, ['running', 'stopping'], true)) {
                continue;
            }
            $owner = strtolower(trim((string) ($row['owner_email'] ?? '')));
            if (!in_array($role, ['admin', 'super_admin'], true) && $owner !== $email) {
                continue;
            }
            $out[] = $row;
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
        return $out;
    }

    /**
     * @param array{email:string,role:string} $actor
     */
    public function requestStop(string $jobId, array $actor): bool
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return false;
        }
        $email = strtolower(trim((string) ($actor['email'] ?? '')));
        $role = strtolower(trim((string) ($actor['role'] ?? 'preview')));

        return $this->withLock(LOCK_EX, function () use ($jobId, $email, $role): bool {
            $root = $this->readRoot();
            if (!isset($root['jobs'][$jobId]) || !is_array($root['jobs'][$jobId])) {
                return false;
            }
            $row = $root['jobs'][$jobId];
            $owner = strtolower(trim((string) ($row['owner_email'] ?? '')));
            $st = (string) ($row['status'] ?? '');
            if (!in_array($st, ['running', 'stopping'], true)) {
                return false;
            }
            if ($owner !== $email && !in_array($role, ['admin', 'super_admin'], true)) {
                return false;
            }
            $row['abort_requested'] = true;
            $row['abort_requested_by'] = $email;
            $row['abort_requested_at'] = gmdate('c');
            $row['status'] = 'stopping';
            $row['last_heartbeat_at'] = gmdate('c');
            $root['jobs'][$jobId] = $row;
            $this->writeRoot($root);
            return true;
        });
    }

    public function isStopRequested(string $jobId): bool
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return false;
        }
        $root = $this->withLock(LOCK_SH, fn (): array => $this->readRoot());
        $row = $root['jobs'][$jobId] ?? null;
        if (!is_array($row)) {
            return false;
        }
        return !empty($row['abort_requested']);
    }

    /**
     * @param array{email:string,role:string} $actor
     */
    public function canManage(string $jobId, array $actor): bool
    {
        $email = strtolower(trim((string) ($actor['email'] ?? '')));
        $role = strtolower(trim((string) ($actor['role'] ?? 'preview')));
        $root = $this->withLock(LOCK_SH, fn (): array => $this->readRoot());
        $row = $root['jobs'][$jobId] ?? null;
        if (!is_array($row)) {
            return false;
        }
        $owner = strtolower(trim((string) ($row['owner_email'] ?? '')));
        return $owner === $email || in_array($role, ['admin', 'super_admin'], true);
    }

    /**
     * @param 'done'|'stopped'|'error' $status
     * @param array<string,mixed>|null $result
     */
    public function finish(string $jobId, string $status, ?array $result = null, ?string $error = null): void
    {
        if (!in_array($status, ['done', 'stopped', 'error'], true)) {
            $status = 'error';
        }
        $this->withLock(LOCK_EX, function () use ($jobId, $status, $result, $error): void {
            $root = $this->readRoot();
            if (!isset($root['jobs'][$jobId]) || !is_array($root['jobs'][$jobId])) {
                return;
            }
            $row = $root['jobs'][$jobId];
            $row['status'] = $status;
            $row['ended_at'] = gmdate('c');
            $row['last_heartbeat_at'] = gmdate('c');
            $row['error'] = $error;
            $row['result'] = $result;
            $root['jobs'][$jobId] = $row;
            $this->writeRoot($root);
        });
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withLock(int $lock, callable $fn): mixed
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fp = fopen($this->lockPath, 'cb');
        if ($fp === false) {
            return $fn();
        }
        if (!flock($fp, $lock)) {
            fclose($fp);
            return $fn();
        }
        try {
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @return array{jobs: array<string,array<string,mixed>>}
     */
    private function readRoot(): array
    {
        if (!is_readable($this->filePath)) {
            return ['jobs' => []];
        }
        $raw = json_decode((string) file_get_contents($this->filePath), true);
        if (!is_array($raw)) {
            return ['jobs' => []];
        }
        $jobs = $raw['jobs'] ?? [];
        if (!is_array($jobs)) {
            $jobs = [];
        }
        return ['jobs' => $jobs];
    }

    /**
     * @param array{jobs: array<string,array<string,mixed>>} $root
     */
    private function writeRoot(array $root): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->filePath,
            json_encode($root, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}

