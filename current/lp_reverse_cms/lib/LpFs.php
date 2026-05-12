<?php

declare(strict_types=1);

/** Filesystem helpers shared by maintenance tools and HTTP handlers. */
final class LpFs
{
    /**
     * Remove a directory tree. $dir must be a real path under an allowed root (caller validates).
     *
     * @throws RuntimeException on failure
     */
    public static function removeTree(string $dir): void
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
                if (!@rmdir($p)) {
                    $last = error_get_last();
                    throw new RuntimeException(
                        'rmdir failed: ' . $p . ($last ? ' — ' . (string) ($last['message'] ?? '') : '')
                    );
                }
            } elseif (!@unlink($p)) {
                $last = error_get_last();
                throw new RuntimeException(
                    'unlink failed: ' . $p . ($last ? ' — ' . (string) ($last['message'] ?? '') : '')
                );
            }
        }
        if (!@rmdir($path)) {
            $last = error_get_last();
            throw new RuntimeException(
                'rmdir failed (root): ' . $path . ($last ? ' — ' . (string) ($last['message'] ?? '') : '')
            );
        }
    }
}
