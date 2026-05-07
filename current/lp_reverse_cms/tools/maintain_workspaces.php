<?php

declare(strict_types=1);

/**
 * List or prune per-session workspace dirs under data/ws_* and output/ws_*.
 *
 * Usage:
 *   php tools/maintain_workspaces.php
 *   php tools/maintain_workspaces.php --older-than-days=14
 *   sudo -u www-data php tools/maintain_workspaces.php --older-than-days=14 --apply
 *
 * Without --apply, never deletes (only lists). --apply requires --older-than-days.
 * Run as www-data when deleting (dirs are typically owned by www-data).
 */

$cmsRoot = dirname(__DIR__);

$olderThanDays = null;
$apply = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--older-than-days=(\d+)$/', $arg, $m) === 1) {
        $olderThanDays = (int) $m[1];
        continue;
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, <<<HELP
List or remove old workspace folders (data/ws_* and output/ws_*).

  php tools/maintain_workspaces.php
  php tools/maintain_workspaces.php --older-than-days=14
  sudo -u www-data php tools/maintain_workspaces.php --older-than-days=14 --apply

HELP);
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

if ($apply && ($olderThanDays === null || $olderThanDays < 1)) {
    fwrite(STDERR, "--apply requires --older-than-days=N with N >= 1\n");
    exit(1);
}

$re = '/^ws_([a-f0-9]{32})$/';

/** @return array<string, true> */
function collectWsNames(string $parent, string $re): array
{
    $out = [];
    if (!is_dir($parent)) {
        return $out;
    }
    foreach (scandir($parent) ?: [] as $ent) {
        if ($ent === '.' || $ent === '..') {
            continue;
        }
        if (preg_match($re, $ent, $m) !== 1) {
            continue;
        }
        $out[$ent] = true;
    }

    return $out;
}

$outputDir = rtrim($cmsRoot, '/\\') . DIRECTORY_SEPARATOR . 'output';
$dataDir   = rtrim($cmsRoot, '/\\') . DIRECTORY_SEPARATOR . 'data';

$names = array_keys(array_merge(
    collectWsNames($outputDir, $re),
    collectWsNames($dataDir, $re)
));
sort($names);

$cutoff = $olderThanDays !== null
    ? (time() - $olderThanDays * 86400)
    : null;

/** Fast dir size via du -sb (Linux); falls back to walking files. */
function dirBytes(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }
    $out = shell_exec('du -sb ' . escapeshellarg($path) . ' 2>/dev/null');
    if (is_string($out) && preg_match('/^(\d+)/', trim($out), $m) === 1) {
        return (int) $m[1];
    }
    $totalB = 0;
    $it = new RecursiveIteratorIterator(
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

/** @return array{0: int, 1: int} [bytes, max_mtime] */
function workspaceStats(string $cmsRoot, string $name): array
{
    $dirs = [
        rtrim($cmsRoot, '/\\') . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . $name,
        rtrim($cmsRoot, '/\\') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $name,
    ];
    $maxM   = 0;
    $totalB = 0;
    foreach ($dirs as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $totalB += dirBytes($root);
        $maxM = max($maxM, (int) filemtime($root));
    }

    return [$totalB, $maxM];
}

function humanBytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $x = (float) $b;
    while ($x >= 1024 && $i < count($u) - 1) {
        $x /= 1024;
        ++$i;
    }

    return sprintf('%.1f %s', $x, $u[$i]);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $path = realpath($dir);
    if ($path === false) {
        throw new RuntimeException('realpath failed: ' . $dir);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $p = $f->getPathname();
        if ($f->isDir()) {
            rmdir($p);
        } else {
            unlink($p);
        }
    }
    rmdir($path);
}

$rows = [];
foreach ($names as $name) {
    [$bytes, $mt] = workspaceStats($cmsRoot, $name);
    $rows[] = ['name' => $name, 'bytes' => $bytes, 'mtime' => $mt];
}

usort($rows, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

$totalAll = array_sum(array_column($rows, 'bytes'));

fwrite(STDOUT, sprintf(
    "CMS root: %s\nWorkspaces: %d, total size (approx): %s\n",
    $cmsRoot,
    count($rows),
    humanBytes((int) $totalAll)
));

fwrite(STDOUT, str_repeat('-', 100) . "\n");
fwrite(STDOUT, sprintf("%-36s  %12s  %s\n", 'workspace', 'size', 'last touched (output/data max mtime)'));
fwrite(STDOUT, str_repeat('-', 100) . "\n");

foreach ($rows as $r) {
    $line = sprintf(
        "%-36s  %12s  %s\n",
        $r['name'],
        humanBytes((int) $r['bytes']),
        date('c', (int) $r['mtime'])
    );
    fwrite(STDOUT, $line);
}

if ($cutoff === null) {
    fwrite(STDOUT, "\nTip: pass --older-than-days=N to select old trees; add --apply to delete (needs write permission).\n");
    exit(0);
}

$toDelete = [];
foreach ($rows as $r) {
    if ((int) $r['mtime'] < $cutoff) {
        $toDelete[] = $r;
    }
}

fwrite(STDOUT, str_repeat('-', 100) . "\n");
fwrite(STDOUT, sprintf(
    "Cutoff: %s (%d days ago) — %d workspace(s) qualify.\n",
    date('c', $cutoff),
    $olderThanDays,
    count($toDelete)
));

if ($toDelete === []) {
    exit(0);
}

$delBytes = array_sum(array_column($toDelete, 'bytes'));
fwrite(STDOUT, 'Approx space to free: ' . humanBytes((int) $delBytes) . "\n");

if (!$apply) {
    fwrite(STDOUT, "Dry-run only (no --apply). To delete, run with --apply as a user that can remove these dirs (often: sudo -u www-data).\n");
    exit(0);
}

foreach ($toDelete as $r) {
    $name = $r['name'];
    foreach (['output', 'data'] as $sub) {
        $path = rtrim($cmsRoot, '/\\') . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path)) {
            continue;
        }
        $rp = realpath($path);
        $expected = realpath(rtrim($cmsRoot, '/\\') . DIRECTORY_SEPARATOR . $sub);
        $prefixOk = $rp !== false && $expected !== false
            && ($rp === $expected || str_starts_with($rp, rtrim($expected, '/\\') . DIRECTORY_SEPARATOR));
        if (!$prefixOk) {
            fwrite(STDERR, "Skip unsafe path: {$path}\n");
            continue;
        }
        try {
            rrmdir($rp);
            fwrite(STDOUT, "Removed: {$rp}\n");
        } catch (Throwable $e) {
            fwrite(STDERR, "Failed {$rp}: " . $e->getMessage() . "\n");
        }
    }
}

fwrite(STDOUT, "Done.\n");
