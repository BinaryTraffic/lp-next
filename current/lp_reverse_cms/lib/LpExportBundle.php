<?php

declare(strict_types=1);

require_once __DIR__ . '/LpWorkspace.php';
require_once __DIR__ . '/LpCloneContext.php';

/**
 * LP 配布用: index.html + 参照に必要なディレクトリのみ ZIP 化。
 */
final class LpExportBundle
{
    /**
     * @return array<string, string> 相対パス => 絶対パス
     */
    public static function collectFiles(string $cmsRoot): array
    {
        $outputDir = LpWorkspace::outputDir($cmsRoot);
        $dataDir   = LpWorkspace::dataDir($cmsRoot);
        $outReal   = realpath($outputDir);
        if ($outReal === false || !is_file($outputDir . 'index.html')) {
            return [];
        }

        $cloneId = LpCloneContext::idFromDataDir($dataDir);
        if ($cloneId === '') {
            $cloneId = LpCloneContext::ensureIdInDataDir($dataDir);
        }

        $map = [];

        $addFile = static function (string $rel) use ($outputDir, $outReal, &$map): void {
            $rel = str_replace('\\', '/', trim($rel, '/'));
            if ($rel === '') {
                return;
            }
            $abs = $outputDir . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $r   = realpath($abs);
            if ($r !== false && is_file($r) && str_starts_with(str_replace('\\', '/', $r), str_replace('\\', '/', $outReal))) {
                $map[$rel] = $r;
            }
        };

        $addTree = static function (string $relDir) use ($outputDir, $outReal, &$map): void {
            $relDir = str_replace('\\', '/', trim($relDir, '/'));
            if ($relDir === '') {
                return;
            }
            $base = $outputDir . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
            $br   = realpath($base);
            if ($br === false || !is_dir($br)) {
                return;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($br, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($it as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $abs = $file->getPathname();
                $r   = realpath($abs);
                if ($r === false) {
                    continue;
                }
                $outNorm = str_replace('\\', '/', $outReal) . '/';
                $absNorm = str_replace('\\', '/', $r);
                if (!str_starts_with($absNorm, $outNorm)) {
                    continue;
                }
                $suffix = substr($absNorm, strlen($outNorm));

                $map[$suffix] = $r;
            }
        };

        $addFile('index.html');
        $addTree('assets');

        $customRel = 'sites/' . $cloneId . '/custom_images';
        $addTree($customRel);

        $html = (string) file_get_contents($outputDir . 'index.html');
        if (str_contains($html, 'ai_images')) {
            $addTree('ai_images');
        }

        return $map;
    }

    /**
     * HTML 内の /output/ws_<id>/ を相対参照用に除去（ZIP 直下配置向け）。
     */
    public static function rewriteHtmlForBundle(string $html, string $wsId): string
    {
        $pat = '#https?://[^"\'\\s>]*/output/ws_' . preg_quote($wsId, '#') . '/#i';
        $html = preg_replace($pat, '', $html) ?? $html;
        $html = str_replace('/output/ws_' . $wsId . '/', '', $html);

        return $html;
    }

    public static function streamZip(string $cmsRoot, string $downloadBase): void
    {
        if (!class_exists(ZipArchive::class)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
            echo 'ZipArchive が利用できません（php-zip）。';
            exit;
        }

        $files = self::collectFiles($cmsRoot);
        if ($files === []) {
            header('Location: index.php');
            exit;
        }

        $wsId = LpWorkspace::id();
        $htmlPath = realpath(LpWorkspace::outputDir($cmsRoot) . 'index.html');
        $htmlRaw  = $htmlPath !== false ? (string) file_get_contents($htmlPath) : '';
        $htmlOut  = self::rewriteHtmlForBundle($htmlRaw, $wsId);

        $tmp = tempnam(sys_get_temp_dir(), 'lpb_');
        if ($tmp === false) {
            http_response_code(500);
            echo '一時ファイルを作成できません';
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            http_response_code(500);
            echo 'ZIP を開けません';
            exit;
        }

        $zip->addFromString('index.html', $htmlOut);

        foreach ($files as $rel => $abs) {
            if ($rel === 'index.html') {
                continue;
            }
            $zip->addFile($abs, str_replace('\\', '/', $rel));
        }

        $zip->close();

        $name = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $downloadBase) ?: 'lp_bundle';
        $fn   = $name . '_' . date('Ymd_His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
