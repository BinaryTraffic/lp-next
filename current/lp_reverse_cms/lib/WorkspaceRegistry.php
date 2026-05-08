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

    public function __construct(string $cmsRoot)
    {
        $this->cmsRoot = rtrim($cmsRoot, '/\\');
        $this->filePath = $this->cmsRoot . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'workspace_registry.json';
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
     * @param array{'email': string, 'role': string} $actor
     *
     * @return list<array{id: string, owner_email: string, created_at: string, last_active_at: string, state: string, bytes: int, mtime: int, is_current: bool, legacy: bool}>
     */
    public function listForActor(array $actor): array
    {
        $email = strtolower(trim($actor['email']));
        $role  = $actor['role'];
        $raw   = $this->load();
        /** @var array<string, array<string, mixed>> $ws */
        $ws = $raw['workspaces'] ?? [];
        if (!is_array($ws)) {
            $ws = [];
        }

        $diskNames = $this->collectDiskWorkspaceNames();
        $current   = 'ws_' . LpWorkspace::id();

        $out = [];
        foreach ($diskNames as $name) {
            $meta = isset($ws[$name]) && is_array($ws[$name]) ? $ws[$name] : null;
            $legacy = $meta === null;

            if ($legacy) {
                if ($role !== 'super_admin') {
                    continue;
                }
                [$bytes, $mtime] = $this->dirStats($name);
                $out[] = [
                    'id'              => $name,
                    'owner_email'     => '',
                    'created_at'        => '',
                    'last_active_at'    => '',
                    'state'             => 'legacy',
                    'bytes'             => $bytes,
                    'mtime'             => $mtime,
                    'is_current'        => $name === $current,
                    'legacy'            => true,
                ];

                continue;
            }

            $owner = strtolower(trim((string) ($meta['owner_email'] ?? '')));
            if ($owner === '') {
                continue;
            }
            if ($role !== 'super_admin' && $role !== 'admin' && $owner !== $email) {
                continue;
            }
            // admin sees only own (same as owner) per product rule; super_admin sees all
            if ($role === 'admin' && $owner !== $email) {
                continue;
            }

            [$bytes, $mtime] = $this->dirStats($name);
            $st = (string) ($meta['state'] ?? 'active');
            if ($st === '') {
                $st = 'active';
            }
            $out[] = [
                'id'               => $name,
                'owner_email'      => $owner,
                'created_at'         => (string) ($meta['created_at'] ?? ''),
                'last_active_at'     => (string) ($meta['last_active_at'] ?? ''),
                'state'              => $st,
                'bytes'              => $bytes,
                'mtime'              => $mtime,
                'is_current'         => $name === $current,
                'legacy'             => false,
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
        $role  = $actor['role'];

        $raw = $this->load();
        /** @var array<string, array<string, mixed>> $map */
        $map = $raw['workspaces'] ?? [];
        if (!is_array($map)) {
            $map = [];
        }
        $meta = $map[$workspaceFolder] ?? null;
        $legacy = !is_array($meta);

        if ($legacy) {
            if ($role !== 'super_admin') {
                return false;
            }
        } else {
            $owner = strtolower(trim((string) ($meta['owner_email'] ?? '')));
            $allowed = ($owner !== '' && $owner === $email) || $role === 'super_admin';
            if (!$allowed) {
                return false;
            }
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

        if (!$legacy) {
            unset($map[$workspaceFolder]);
            $raw['workspaces'] = $map;
            $this->save($raw);
        }

        return true;
    }

    private function touch(string $folderName, string $email): void
    {
        if (!preg_match('/^ws_[a-f0-9]{32}$/', $folderName)) {
            return;
        }
        $folderName = strtolower($folderName);
        $email      = strtolower(trim($email));

        $raw = $this->load();
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
        $this->save($raw);
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if (!is_readable($this->filePath)) {
            return ['workspaces' => []];
        }
        $j = json_decode((string) file_get_contents($this->filePath), true);

        return is_array($j) ? $j : ['workspaces' => []];
    }

    /** @param array<string, mixed> $data */
    private function save(array $data): void
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
        $totalB = 0;
        $it     = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if ($f->isFile()) {
                $totalB += $f->getSize();
            }
        }

        return $totalB;
    }
}
