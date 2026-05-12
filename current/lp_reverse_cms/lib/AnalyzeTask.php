<?php

declare(strict_types=1);

final class AnalyzeTask
{
    private static function writeJsonFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('json_encode failed: ' . $path);
        }
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('write failed: ' . $path);
        }
    }

    private static function writeTextFile(string $path, string $body): void
    {
        if (file_put_contents($path, $body, LOCK_EX) === false) {
            throw new RuntimeException('write failed: ' . $path);
        }
    }

    private const DIR_NAME = 'analyze_tasks';
    private const LOCK_FILE = '.analyze_tasks.lock';

    private static function dir(string $cmsRoot): string
    {
        return rtrim($cmsRoot, '/\\') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . self::DIR_NAME;
    }

    private static function ensureDir(string $cmsRoot): string
    {
        $dir = self::dir($cmsRoot);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private static function actorKey(string $email): string
    {
        return sha1(strtolower(trim($email)));
    }

    private static function pointerPath(string $cmsRoot, string $email): string
    {
        return self::ensureDir($cmsRoot) . DIRECTORY_SEPARATOR . self::actorKey($email) . '.progress';
    }

    private static function taskPath(string $cmsRoot, string $taskId): string
    {
        return self::ensureDir($cmsRoot) . DIRECTORY_SEPARATOR . $taskId . '.json';
    }

    private static function lockPath(string $cmsRoot): string
    {
        return self::ensureDir($cmsRoot) . DIRECTORY_SEPARATOR . self::LOCK_FILE;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function withLock(string $cmsRoot, callable $fn): mixed
    {
        $fp = fopen(self::lockPath($cmsRoot), 'cb');
        if ($fp === false) {
            return $fn();
        }
        if (!flock($fp, LOCK_EX)) {
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
     * @param array{email: string, role: string} $actor
     *
     * @return array{task_id: string, progress_text: string, already_running: bool}
     */
    public static function createIfNotRunning(
        string $cmsRoot,
        array $actor,
        string $workspaceId,
        string $sourceUrl,
        int $crawlDepth = 1
    ): array {
        return self::withLock($cmsRoot, function () use ($cmsRoot, $actor, $workspaceId, $sourceUrl, $crawlDepth): array {
            $latest = self::latestTaskIdForActor($cmsRoot, (string) $actor['email']);
            if ($latest !== '') {
                $prev = self::load($cmsRoot, $latest);
                if (is_array($prev)) {
                    $st = (string) ($prev['status'] ?? '');
                    if ($st === 'pending' || $st === 'running') {
                        return [
                            'task_id' => $latest,
                            'progress_text' => (string) ($prev['progress_text'] ?? '000/100'),
                            'already_running' => true,
                        ];
                    }
                }
            }
            $taskId = 'ana_' . bin2hex(random_bytes(12));
            $now = time();
            $task = [
                'task_id' => $taskId,
                'owner_email' => strtolower(trim((string) $actor['email'])),
                'owner_role' => (string) ($actor['role'] ?? ''),
                'status' => 'pending',
                'phase' => 'fetch',
                'progress_text' => '000/100',
                'pid' => 0,
                'started_at' => $now,
                'ended_at' => 0,
                'source_url' => $sourceUrl,
                'workspace_id' => $workspaceId,
                'crawl_depth' => max(1, $crawlDepth),
                'error' => null,
                'updated_at' => $now,
            ];
            self::writeJsonFile(self::taskPath($cmsRoot, $taskId), $task);
            self::writeTextFile(self::pointerPath($cmsRoot, (string) $actor['email']), $taskId . PHP_EOL);

            return ['task_id' => $taskId, 'progress_text' => '000/100', 'already_running' => false];
        });
    }

    /** @return null|array<string, mixed> */
    public static function load(string $cmsRoot, string $taskId): ?array
    {
        if (!preg_match('/^ana_[a-f0-9]{24}$/', $taskId)) {
            return null;
        }
        $path = self::taskPath($cmsRoot, $taskId);
        if (!is_readable($path)) {
            return null;
        }
        $fp = fopen($path, 'r');
        if ($fp === false) {
            return null;
        }
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $json = json_decode((string) $content, true);

        return is_array($json) ? $json : null;
    }

    /** @param array<string, mixed> $task */
    public static function save(string $cmsRoot, string $taskId, array $task): void
    {
        $task['updated_at'] = time();
        self::writeJsonFile(self::taskPath($cmsRoot, $taskId), $task);
    }

    public static function latestTaskIdForActor(string $cmsRoot, string $email): string
    {
        $path = self::pointerPath($cmsRoot, $email);
        if (!is_readable($path)) {
            return '';
        }
        $id = trim((string) file_get_contents($path));
        if (!preg_match('/^ana_[a-f0-9]{24}$/', $id)) {
            return '';
        }

        return $id;
    }

    /**
     * @param array{email: string, role: string} $actor
     */
    public static function canView(array $task, array $actor): bool
    {
        $owner = strtolower(trim((string) ($task['owner_email'] ?? '')));
        $email = strtolower(trim((string) ($actor['email'] ?? '')));
        $role = (string) ($actor['role'] ?? '');

        return $owner !== '' && ($owner === $email || $role === 'super_admin');
    }
}

