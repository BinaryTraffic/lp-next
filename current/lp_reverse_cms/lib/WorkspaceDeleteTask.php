<?php

declare(strict_types=1);

final class WorkspaceDeleteTask
{
    private const DIR_NAME = 'workspace_delete_tasks';

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

    /**
     * @param list<string> $ids
     * @param array{email: string, role: string} $actor
     *
     * @return array{task_id: string, progress_text: string}
     */
    public static function create(string $cmsRoot, array $actor, array $ids): array
    {
        $taskId = 'wdt_' . bin2hex(random_bytes(12));
        $total = count($ids);
        $now = time();
        $task = [
            'task_id' => $taskId,
            'owner_email' => strtolower(trim((string) $actor['email'])),
            'owner_role' => (string) ($actor['role'] ?? ''),
            'status' => 'queued',
            'workspace_ids' => array_values($ids),
            'total' => $total,
            'done' => 0,
            'deleted' => 0,
            'failed' => [],
            'current' => '',
            'started_at' => $now,
            'updated_at' => $now,
            'ended_at' => 0,
            'progress_text' => sprintf('%03d/%03d', 0, max(1, $total)),
        ];
        file_put_contents(
            self::taskPath($cmsRoot, $taskId),
            json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        file_put_contents(self::pointerPath($cmsRoot, (string) $actor['email']), $taskId . PHP_EOL, LOCK_EX);

        return ['task_id' => $taskId, 'progress_text' => (string) $task['progress_text']];
    }

    /** @return null|array<string, mixed> */
    public static function load(string $cmsRoot, string $taskId): ?array
    {
        if (!preg_match('/^wdt_[a-f0-9]{24}$/', $taskId)) {
            return null;
        }
        $path = self::taskPath($cmsRoot, $taskId);
        if (!is_readable($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    /** @param array<string, mixed> $task */
    public static function save(string $cmsRoot, string $taskId, array $task): void
    {
        $task['updated_at'] = time();
        file_put_contents(
            self::taskPath($cmsRoot, $taskId),
            json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    public static function latestTaskIdForActor(string $cmsRoot, string $email): string
    {
        $path = self::pointerPath($cmsRoot, $email);
        if (!is_readable($path)) {
            return '';
        }
        $id = trim((string) file_get_contents($path));
        if (!preg_match('/^wdt_[a-f0-9]{24}$/', $id)) {
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

