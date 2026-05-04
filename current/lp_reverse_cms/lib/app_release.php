<?php

declare(strict_types=1);

/**
 * 画面表示用のビルド識別子（Git の短ハッシュ、無ければ主要ソースの最新更新日）。
 */
function lp_reverse_app_build_label(string $cmsRoot): string
{
    $hash = lp_reverse_git_short_hash($cmsRoot);
    if ($hash !== null) {
        return $hash;
    }

    $cmsRoot = rtrim($cmsRoot, '/\\');
    $files   = [
        $cmsRoot . DIRECTORY_SEPARATOR . 'index.php',
        $cmsRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'index.js',
        $cmsRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'index.css',
        $cmsRoot . DIRECTORY_SEPARATOR . 'preview.php',
    ];

    $max = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            $max = max($max, (int) filemtime($f));
        }
    }

    return $max > 0 ? gmdate('Ymd', $max) : gmdate('Ymd');
}

/** @param non-empty-string $startDir cms root (lp_reverse_cms) */
function lp_reverse_git_short_hash(string $startDir): ?string
{
    $dir = rtrim($startDir, '/\\');

    for ($depth = 0; $depth < 10; $depth++) {
        $gitDir = $dir . DIRECTORY_SEPARATOR . '.git';
        if (!is_dir($gitDir)) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;

            continue;
        }

        $headFile = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';
        if (!is_readable($headFile)) {
            return null;
        }

        $head = trim((string) file_get_contents($headFile));
        $hex  = null;

        if (preg_match('/^[0-9a-f]{7,40}$/i', $head)) {
            $hex = $head;
        } elseif (preg_match('#^ref:\s+(.+)$#', $head, $m)) {
            $refPath = trim($m[1]);
            $fullRef = $gitDir . DIRECTORY_SEPARATOR . $refPath;
            if (is_readable($fullRef)) {
                $hex = trim((string) file_get_contents($fullRef));
            }
            if (($hex === null || $hex === '') && is_readable($gitDir . DIRECTORY_SEPARATOR . 'packed-refs')) {
                $packed = (string) file_get_contents($gitDir . DIRECTORY_SEPARATOR . 'packed-refs');
                if (preg_match('/^([0-9a-f]{40}) ' . preg_quote($refPath, '/') . '$/m', $packed, $pm)) {
                    $hex = $pm[1];
                }
            }
        }

        if ($hex !== null && preg_match('/^[0-9a-f]{7,40}$/i', $hex)) {
            return strtolower(substr($hex, 0, 7));
        }

        return null;
    }

    return null;
}
