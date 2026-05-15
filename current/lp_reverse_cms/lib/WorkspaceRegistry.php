<?php

declare(strict_types=1);

require_once __DIR__ . '/LpFs.php';
require_once __DIR__ . '/LpWorkspace.php';

/**
 * Maps each workspace folder (ws_{hex32}) to owning user email and metadata.
 * Stored in data/workspace_registry.json (not per-ws; shared across sessions).
 */
final class WorkspaceRegistry
{
    private string $cmsRoot;

    private string $filePath;

    private string $lockPath;

    public function __construct(string $cmsRoot)
    {
        $this->cmsRoot = rtrim($cmsRoot, '/\\');
        $this->filePath = $this->cmsRoot . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'workspace_registry.json';
        $this->lockPath = $this->cmsRoot . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . '.workspace_registry.lock';
    }

    /** Notify activity for the current session workspace (first touch records owner). */
    public static function touchCurrent(string $cmsRoot, string $ownerEmail): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $email = strtolower(trim($ownerEmail));
        if ($email === '') {
            return;
        }
        LpWorkspace::bootstrap();
        $hex = LpWorkspace::id();
        if ($hex === 'cli' || !LpWorkspace::isValidId($hex)) {
            return;
        }
        $inst = new self($cmsRoot);
        $inst->touch('ws_' . $hex, $email);
    }

    /**
     * Serialize registry mutations vs reads across tabs/processes (flock advisory lock).
     * file_put_contents(..., LOCK_EX) alone does not cover read–modify–write.
     *
     * @template T
     * @param callable(): T $fn
     *
     * @return T
     */
    private function withFileLock(int $lockFlag, callable $fn): mixed
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($this->lockPath, 'cb');
        if ($fp === false) {
            return $fn();
        }
        if (!flock($fp, $lockFlag)) {
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
     * @param array{'email': string, 'role': string} $actor
     *
     * @return list<array{id: string, owner_email: string, created_at: string, last_active_at: string, state: string, bytes: int, mtime: int, is_current: bool, legacy: bool, can_delete: bool}>
     */
    public function listForActor(array $actor): array
    {
        $email = strtolower(trim($actor['email']));
        $role  = $actor['role'];
        $roleLc = strtolower(trim((string) $role));
        $raw   = $this->load();
        /** @var array<string, array<string, mixed>> $ws */
        $ws = $raw['workspaces'] ?? [];
        if (!is_array($ws)) {
            $ws = [];
        }

        $diskNames = $this->collectDiskWorkspaceNames();
        $current   = 'ws_' . LpWorkspace::id();
        $memoSide = $this->loadMemoSidecarMap();

        $out = [];
        foreach ($diskNames as $name) {
            $meta = isset($ws[$name]) && is_array($ws[$name]) ? $ws[$name] : null;
            $legacy = $meta === null;

            if ($legacy) {
                if ($roleLc !== 'super_admin') {
                    continue;
                }
                [$bytes, $mtime] = $this->dirStats($name);
                $out[] = [
                    'id'              => $name,
                    'owner_email'     => '',
                    'created_at'        => '',
                    'last_active_at'    => '',
                    'state'             => 'legacy',
                    'memo'              => $this->memoTextFromSidecarEntry($memoSide[$name] ?? null),
                    'bytes'             => $bytes,
                    'mtime'             => $mtime,
                    'is_current'        => $name === $current,
                    'legacy'            => true,
                    // この行が一覧に載る時点で API は super_admin のみ。常に true（実削除は deleteIfAllowed で再検証）
                    'can_delete'      => true,
                ];

                continue;
            }

            $owner = strtolower(trim((string) ($meta['owner_email'] ?? '')));
            if ($owner === '') {
                continue;
            }
            if ($roleLc !== 'super_admin' && $roleLc !== 'admin' && $owner !== $email) {
                continue;
            }
            // admin sees only own (same as owner) per product rule; super_admin sees all
            if ($roleLc === 'admin' && $owner !== $email) {
                continue;
            }

            [$bytes, $mtime] = $this->dirStats($name);
            $st = (string) ($meta['state'] ?? 'active');
            if ($st === '') {
                $st = 'active';
            }
            $regMemo = (string) ($meta['memo'] ?? '');
            $sideMemo = $this->memoTextFromSidecarEntry($memoSide[$name] ?? null);
            $out[] = [
                'id'               => $name,
                'owner_email'      => $owner,
                'created_at'         => (string) ($meta['created_at'] ?? ''),
                'last_active_at'     => (string) ($meta['last_active_at'] ?? ''),
                'state'              => $st,
                'memo'               => $regMemo !== '' ? $regMemo : $sideMemo,
                'bytes'              => $bytes,
                'mtime'              => $mtime,
                'is_current'         => $name === $current,
                'legacy'             => false,
                'can_delete'       => $roleLc === 'super_admin'
                    || ($owner !== '' && $owner === $email),
            ];
        }

        usort($out, static fn (array $a, array $b): int => (int) ($b['mtime'] <=> $a['mtime']));

        return $out;
    }

    /**
     * Delete filesystem trees and registry entry if actor is allowed.
     *
     * @param array{'email': string, 'role': string} $actor
     */
    public function deleteIfAllowed(string $workspaceFolder, array $actor): bool
    {
        if (!preg_match('/^ws_[a-f0-9]{32}$/', $workspaceFolder)) {
            return false;
        }
        $workspaceFolder = strtolower($workspaceFolder);
        $email = strtolower(trim($actor['email']));
        $roleLc = strtolower(trim((string) ($actor['role'] ?? '')));

        $allowed = $this->withFileLock(LOCK_EX, function () use ($workspaceFolder, $email, $roleLc): bool {
            $raw = $this->readJsonFile();
            /** @var array<string, array<string, mixed>> $map */
            $map = $raw['workspaces'] ?? [];
            if (!is_array($map)) {
                $map = [];
            }
            $meta = $map[$workspaceFolder] ?? null;
            $legacy = !is_array($meta);

            if ($legacy) {
                if ($roleLc !== 'super_admin') {
                    return false;
                }

                return true;
            }

            $owner = strtolower(trim((string) ($meta['owner_email'] ?? '')));

            return ($owner !== '' && $owner === $email) || $roleLc === 'super_admin';
        });

        if (!$allowed) {
            return false;
        }

        foreach (['output', 'data'] as $sub) {
            $path = $this->cmsRoot . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $workspaceFolder;
            if (is_dir($path)) {
                try {
                    LpFs::removeTree($path);
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        'removeTree failed: ' . $path . ' — ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        $this->withFileLock(LOCK_EX, function () use ($workspaceFolder): void {
            $raw = $this->readJsonFile();
            /** @var array<string, array<string, mixed>> $map */
            $map = $raw['workspaces'] ?? [];
            if (!is_array($map)) {
                return;
            }
            if (!isset($map[$workspaceFolder])) {
                return;
            }
            unset($map[$workspaceFolder]);
            $raw['workspaces'] = $map;
            $this->writeJsonFile($raw);
        });

        $this->withFileLock(LOCK_EX, function () use ($workspaceFolder): void {
            $this->writeMemoSidecarUnderLock($workspaceFolder, '');
        });

        return true;
    }

    /**
     * Update the user-editable memo for a workspace.
     *
     * @param array{'email': string, 'role': string} $actor
     */
    public function updateMemo(string $folderName, string $memo, array $actor): bool
    {
        if (!preg_match('/^ws_[a-f0-9]{32}$/', $folderName)) {
            return false;
        }
        $folderName = strtolower($folderName);
        $email = strtolower(trim($actor['email']));
        $roleLc = strtolower(trim((string) ($actor['role'] ?? '')));

        return (bool) $this->withFileLock(LOCK_EX, function () use ($folderName, $memo, $email, $roleLc): bool {
            $raw = $this->readJsonFile();
            /** @var array<string, array<string, mixed>> $map */
            $map = $raw['workspaces'] ?? [];
            if (!is_array($map)) {
                $map = [];
            }
            if (isset($map[$folderName]) && is_array($map[$folderName])) {
                $owner = strtolower(trim((string) ($map[$folderName]['owner_email'] ?? '')));
                if ($owner !== $email && $roleLc !== 'super_admin') {
                    return false;
                }
                $map[$folderName]['memo'] = $memo;
                $raw['workspaces'] = $map;
                $this->writeJsonFile($raw);

                return true;
            }

            // レジストリなし（典型的な legacy）: super_admin のみ、ディスク上に ws が存在する場合に sidecar に保存
            if ($roleLc !== 'super_admin') {
                return false;
            }
            if (!$this->hasWorkspaceFolderOnDisk($folderName)) {
                return false;
            }
            $this->writeMemoSidecarUnderLock($folderName, $memo);

            return true;
        });
    }

    /** @return array<string, mixed> */
    private function loadMemoSidecarMap(): array
    {
        $path = $this->cmsRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'workspace_memos.json';
        if (!is_readable($path)) {
            return [];
        }
        $j = json_decode((string) file_get_contents($path), true);

        return is_array($j) ? $j : [];
    }

    /**
     * @param mixed $entry
     */
    private function memoTextFromSidecarEntry(mixed $entry): string
    {
        if ($entry === null) {
            return '';
        }
        if (is_string($entry)) {
            return substr($entry, 0, 500);
        }
        if (is_array($entry)) {
            return substr(trim((string) ($entry['memo'] ?? '')), 0, 500);
        }

        return '';
    }

    private function hasWorkspaceFolderOnDisk(string $folderName): bool
    {
        $data = $this->cmsRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $folderName;
        $out = $this->cmsRoot . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . $folderName;

        return is_dir($data) || is_dir($out);
    }

    private function writeMemoSidecarUnderLock(string $folderName, string $memo): void
    {
        $path = $this->cmsRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'workspace_memos.json';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $map = [];
        if (is_readable($path)) {
            $j = json_decode((string) file_get_contents($path), true);
            if (is_array($j)) {
                $map = $j;
            }
        }
        if ($memo === '') {
            unset($map[$folderName]);
        } else {
            $map[$folderName] = [
                'memo'       => $memo,
                'updated_at' => gmdate('c'),
            ];
        }
        file_put_contents(
            $path,
            json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function touch(string $folderName, string $email): void
    {
        if (!preg_match('/^ws_[a-f0-9]{32}$/', $folderName)) {
            return;
        }
        $folderName = strtolower($folderName);
        $email      = strtolower(trim($email));

        $this->withFileLock(LOCK_EX, function () use ($folderName, $email): void {
            $raw = $this->readJsonFile();
            /** @var array<string, array<string, mixed>> $map */
            $map = $raw['workspaces'] ?? [];
            if (!is_array($map)) {
                $map = [];
            }
            $now = gmdate('c');

            if (!isset($map[$folderName])) {
                $map[$folderName] = [
                    'owner_email'      => $email,
                    'created_at'       => $now,
                    'last_active_at'   => $now,
                    'state'            => 'active',
                ];
            } else {
                $exOwner = strtolower(trim((string) ($map[$folderName]['owner_email'] ?? '')));
                if ($exOwner !== '' && $exOwner !== $email) {
                    return;
                }
                if ($exOwner === '') {
                    $map[$folderName]['owner_email'] = $email;
                }
                $map[$folderName]['last_active_at'] = $now;
            }

            $raw['workspaces'] = $map;
            $this->writeJsonFile($raw);
        });
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        return $this->withFileLock(
            LOCK_SH,
            fn (): array => $this->readJsonFile()
        );
    }

    /** @return array<string, mixed> */
    private function readJsonFile(): array
    {
        if (!is_readable($this->filePath)) {
            return ['workspaces' => []];
        }
        $j = json_decode((string) file_get_contents($this->filePath), true);

        return is_array($j) ? $j : ['workspaces' => []];
    }

    /** @param array<string, mixed> $data */
    private function writeJsonFile(array $data): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!isset($data['workspaces']) || !is_array($data['workspaces'])) {
            $data['workspaces'] = [];
        }

        file_put_contents(
            $this->filePath,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /** @return list<string> */
    private function collectDiskWorkspaceNames(): array
    {
        $re  = '/^ws_([a-f0-9]{32})$/';
        $out = [];
        foreach (['output', 'data'] as $sub) {
            $parent = $this->cmsRoot . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($parent)) {
                continue;
            }
            foreach (scandir($parent) ?: [] as $ent) {
                if ($ent === '.' || $ent === '..') {
                    continue;
                }
                if (preg_match($re, $ent) !== 1) {
                    continue;
                }
                $out[strtolower($ent)] = true;
            }
        }

        return array_keys($out);
    }

    /** @return array{0: int, 1: int} bytes, max mtime */
    private function dirStats(string $name): array
    {
        $totalB = 0;
        $maxM   = 0;
        foreach (['output', 'data'] as $sub) {
            $root = $this->cmsRoot . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($root)) {
                continue;
            }
            $totalB += self::dirBytes($root);
            $maxM = max($maxM, (int) filemtime($root));
        }

        return [$totalB, $maxM];
    }

    private static function dirBytes(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        $out = shell_exec('du -sb ' . escapeshellarg($path) . ' 2>/dev/null');
        if (is_string($out) && preg_match('/^(\d+)/', trim($out), $m) === 1) {
            return (int) $m[1];
        }
        // du が使えない環境（Windows/WSL 等）では再帰スキャン。256 MB 超で打ち切り（一覧表示用の概算で十分）
        $totalB = 0;
        $limit  = 256 * 1024 * 1024;
        $it     = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if ($f->isFile()) {
                $totalB += $f->getSize();
                if ($totalB >= $limit) {
                    return $totalB;
                }
            }
        }

        return $totalB;
    }
}
