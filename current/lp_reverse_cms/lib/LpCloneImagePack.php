<?php

declare(strict_types=1);

require_once __DIR__ . '/LpWorkspace.php';
require_once __DIR__ . '/LpCloneContext.php';

/**
 * クローンで取得した画像（assets/img）とクローン単位カスタム画像（sites/&lt;clone&gt;/custom_images）の
 * ZIP 出力・インポート。ZIP 内の相対パスは output/ws_* 直下と一致させ、外部編集後に同構造で差し替え可能。
 */
final class LpCloneImagePack
{
    /** Same as upload_user_image + ico bmp tiff used by fetcher */
    private const IMAGE_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico', 'bmp', 'tif', 'tiff',
    ];

    /**
     * @return list<string> workspace-relative posix paths under output/
     */
    public static function collectImageRelativePaths(string $cmsRoot): array
    {
        $outputDir = LpWorkspace::outputDir($cmsRoot);
        $dataDir   = LpWorkspace::dataDir($cmsRoot);
        $outReal   = realpath($outputDir);
        if ($outReal === false || !is_dir($outReal)) {
            return [];
        }

        $cloneId = LpCloneContext::idFromDataDir($dataDir);
        if ($cloneId === '') {
            $cloneId = LpCloneContext::ensureIdInDataDir($dataDir);
        }

        /** @var list<string> $out */
        $out = [];

        $scanTree = static function (string $relDir) use ($outputDir, $outReal, &$out): void {
            $relDir = str_replace('\\', '/', trim($relDir, '/'));
            if ($relDir === '') {
                return;
            }

            $base = $outputDir . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
            $br   = realpath($base);

            if ($br === false || !is_dir($br)) {
                return;
            }

            $prefix = str_replace('\\', '/', $outReal) . '/';

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($br, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($it as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $abs = realpath($file->getPathname());
                if ($abs === false) {
                    continue;
                }
                $absNorm = str_replace('\\', '/', $abs);

                if (!str_starts_with($absNorm, $prefix)) {
                    continue;
                }

                $suffix = substr($absNorm, strlen($prefix));

                $ext = strtolower(pathinfo($suffix, PATHINFO_EXTENSION));

                if (!in_array($ext, self::IMAGE_EXT, true)) {
                    continue;
                }

                $out[] = $suffix;
            }
        };

        $scanTree('assets/img');
        $scanTree('sites/' . $cloneId . '/custom_images');

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * basename（小文字） => list&lt;posix rel&gt;
     *
     * @return array<string, list<string>>
     */
    public static function basenameConflictMap(string $cmsRoot): array
    {
        $map = [];

        foreach (self::collectImageRelativePaths($cmsRoot) as $rel) {
            $bn = strtolower(basename(str_replace('\\', '/', $rel)));
            if ($bn === '') {
                continue;
            }
            $map[$bn][] = str_replace('\\', '/', $rel);
        }

        foreach ($map as $k => $list) {
            $map[$k] = array_values(array_unique($list));
        }

        return $map;
    }

    public static function streamZip(string $cmsRoot, string $slugBase): void
    {
        if (!class_exists(ZipArchive::class)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'ZipArchive が利用できません（php-zip が必要です）';

            return;
        }

        $paths = self::collectImageRelativePaths($cmsRoot);

        $outputDir = LpWorkspace::outputDir($cmsRoot);
        $dataDir   = LpWorkspace::dataDir($cmsRoot);
        $cloneId   = LpCloneContext::idFromDataDir($dataDir);

        if ($cloneId === '') {
            $cloneId = LpCloneContext::ensureIdInDataDir($dataDir);
        }

        $manifest = [
            'v'        => 1,
            'ws_id'    => LpWorkspace::id(),
            'clone_id' => $cloneId,
            'hint_ja'  => 'ZIP 内パスは output/ws_*/ と同じ名前です。このままファイルを差し替えて ZIP を再インポートできます。',
            'files'    => array_map(static fn (string $r): array => [
                'rel'      => $r,
                'basename' => basename($r),
            ], $paths),
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'lpiexp_');

        if ($tmp === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo '一時ファイルを作成できません';

            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'ZIP を開けません';

            return;
        }

        $zip->addFromString(
            'clone_images_manifest.json',
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        foreach ($paths as $rel) {
            $posix = str_replace('\\', '/', $rel);
            $abs   = $outputDir . str_replace('/', DIRECTORY_SEPARATOR, $posix);
            if (!is_file($abs)) {
                continue;
            }
            $zip->addFile($abs, $posix);
            $zip->setCompressionName($posix, ZipArchive::CM_DEFLATE);
        }

        $zip->close();

        $nameBase = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $slugBase) ?: 'clone_images';
        $filename = $nameBase . '_images_' . date('Ymd_His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /**
     * @return array{ok:true,applied:int,replaced:list<string>,skipped:list<string>,errors:list<string>}|array{ok:false,error:string}
     */
    public static function importFromUploadedZip(string $cmsRoot, string $zipTmpPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'ZipArchive が利用できません'];
        }

        $outDir = LpWorkspace::outputDir($cmsRoot);
        $outR   = realpath($outDir);

        if ($outR === false || !is_dir($outDir)) {
            return ['ok' => false, 'error' => 'ワークスペース output が無効です'];
        }

        $dataDir = LpWorkspace::dataDir($cmsRoot);

        /** @phpstan-ignore-next-line */
        $cloneId = LpCloneContext::idFromDataDir($dataDir);
        if ($cloneId === '') {
            /** @phpstan-ignore-next-line */
            $cloneId = LpCloneContext::ensureIdInDataDir($dataDir);
        }

        /** @phpstan-ignore-next-line */
        $allowedPrefixAssets = 'assets/img/';
        /** @phpstan-ignore-next-line */
        $allowedPrefixCustom = 'sites/' . $cloneId . '/custom_images/';
        /** @phpstan-ignore-next-line */
        $basenameMap = self::basenameConflictMap($cmsRoot);

        $maxFiles = max(50, min(800, (int) (getenv('LP_IMAGE_PACK_IMPORT_MAX_FILES') ?: '400')));
        $maxBytesTotal = max(5_000_000, min(350_000_000, (int) (getenv('LP_IMAGE_PACK_IMPORT_MAX_TOTAL_BYTES') ?: '120000000')));
        $maxPerFile = max(200_000, min(120_000_000, (int) (getenv('LP_IMAGE_PACK_IMPORT_MAX_ENTRY_BYTES') ?: '40000000')));

        $zip = new ZipArchive();
        if ($zip->open($zipTmpPath) !== true) {
            return ['ok' => false, 'error' => 'ZIP を開けません'];
        }

        $replaced = [];
        $skipped  = [];
        $errs     = [];

        /** @phpstan-ignore-next-line */
        $outNormFs = str_replace('\\', '/', $outR);

        /** @phpstan-ignore-next-line */
        $applied = 0;

        /** @phpstan-ignore-next-line */
        $runningBytes = 0;

        /** @phpstan-ignore-next-line */
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $meta = $zip->statIndex($i);
            if (!is_array($meta)) {
                continue;
            }

            $origNameRaw = isset($meta['name']) ? (string) $meta['name'] : '';

            if ($applied >= $maxFiles) {
                break;
            }

            /** @phpstan-ignore-next-line */
            if ($origNameRaw === '' || substr($origNameRaw, -1) === DIRECTORY_SEPARATOR || substr(str_replace('\\', '/', $origNameRaw), -1) === '/') {
                continue;
            }

            /** @phpstan-ignore-next-line */
            $logical = str_replace('\\', '/', $origNameRaw);
            /** @phpstan-ignore-next-line */
            $logical = preg_replace('#^(\./)+#', '', trim($logical, '/')) ?? '';

            $logicalLc = strtolower($logical);

            /** @phpstan-ignore-next-line */
            if (
                str_starts_with($logicalLc, '__macosx/')
                || str_contains($logicalLc, '/__macosx/')
                || str_contains($logical, '..')
                || strtolower(basename($logical)) === '.ds_store'
            ) {
                continue;
            }

            $lcBaseManifest = strtolower(basename($logical));

            if ($lcBaseManifest === 'clone_images_manifest.json' || str_contains($logicalLc, '/clone_images_manifest.json')) {
                continue;
            }

            $extEntry = strtolower(pathinfo($logical, PATHINFO_EXTENSION));
            /** @phpstan-ignore-next-line */
            if (($meta['size'] ?? -1) === 0 || !in_array($extEntry, self::IMAGE_EXT, true)) {
                $skipped[] = $origNameRaw;

                continue;
            }

            $sizeGuess = isset($meta['size']) ? (int) $meta['size'] : $maxPerFile;

            /** @phpstan-ignore-next-line */
            if ($sizeGuess > $maxPerFile || $runningBytes + $sizeGuess > $maxBytesTotal) {
                $errs[] = 'サイズ超過または合計過大によりスキップ: ' . $origNameRaw;

                continue;
            }

            /** @phpstan-ignore-next-line */
            $targetRelNorm = '';

            /** @phpstan-ignore-next-line */
            if (str_contains($logical, '/')) {

                /** @phpstan-ignore-next-line */
                if (
                    !str_starts_with($logicalLc, $allowedPrefixAssets)
                    && !str_starts_with($logicalLc, $allowedPrefixCustom)
                ) {
                    $errs[] = '許可されていないパスです（assets/img または sites/&lt;clone&gt;/custom_images のみ）: ' . $origNameRaw;

                    continue;
                }
                /** @phpstan-ignore-next-line */
                $targetRelNorm = $logical;

                /** @phpstan-ignore-next-line */
                if (!in_array(strtolower(pathinfo($targetRelNorm, PATHINFO_EXTENSION)), self::IMAGE_EXT, true)) {
                    $errs[] = '許可されていない画像拡張子: ' . $origNameRaw;

                    continue;
                }
            } else {
                /** @phpstan-ignore-next-line */
                $bnb = strtolower(basename($logical));
                /** @phpstan-ignore-next-line */
                if ($bnb === '' || !isset($basenameMap[$bnb])) {
                    $errs[] = 'ZIP 直下の名前がワークスペースと一致しません（不明）: ' . $origNameRaw;

                    continue;
                }
                /** @phpstan-ignore-next-line */
                $candidates = $basenameMap[$bnb];
                /** @phpstan-ignore-next-line */
                if (count($candidates) !== 1) {
                    $errs[] = '同一ファイル名が複数あります（フォルダ構成を ZIP に含めてください）: ' . $bnb;

                    continue;
                }
                /** @phpstan-ignore-next-line */
                $targetRelNorm = $candidates[0];
            }

            /** @phpstan-ignore-next-line */
            $destAbsFs = realpath(dirname($outDir . str_replace('/', DIRECTORY_SEPARATOR, $targetRelNorm)));

            /** @phpstan-ignore-next-line */
            if ($destAbsFs === false) {
                /** @phpstan-ignore-next-line */
                $parentRel = dirname($targetRelNorm);
                /** @phpstan-ignore-next-line */
                $parentAbs = $outDir . str_replace('/', DIRECTORY_SEPARATOR, $parentRel);
                /** @phpstan-ignore-next-line */
                if (
                    /** @phpstan-ignore-next-line */
                    !(
                        mkdir($parentAbs, 0755, true)
                        /** @phpstan-ignore-next-line */
                        || is_dir($parentAbs)
                    )
                ) {
                    /** @phpstan-ignore-next-line */
                    $errs[] = '出力ディレクトリを作成できません: ' . $targetRelNorm;

                    continue;
                }
                /** @phpstan-ignore-next-line */
                $destAbsFs = realpath(dirname($outDir . str_replace('/', DIRECTORY_SEPARATOR, $targetRelNorm)));
                if ($destAbsFs === false) {
                    /** @phpstan-ignore-next-line */
                    $errs[] = '親ディレクトリを解決できません: ' . $targetRelNorm;

                    continue;
                }
            }

            /** @phpstan-ignore-next-line */
            $destFsNormDir = str_replace('\\', '/', $destAbsFs);
            /** @phpstan-ignore-next-line */
            if (
                strlen($destFsNormDir) < strlen($outNormFs)
                /** @phpstan-ignore-next-line */
                || substr($destFsNormDir . '/', 0, strlen($outNormFs) + 1) !== $outNormFs . '/'
            ) {
                $errs[] = 'パス検証エラー（zip slip）';

                /** @phpstan-ignore-next-line */
                continue;
            }

            /** @phpstan-ignore-next-line */
            $finalAbsNorm = str_replace('\\', '/', $destFsNormDir . '/' . basename($targetRelNorm));

            /** @phpstan-ignore-next-line */
            if (
                substr($finalAbsNorm, 0, strlen($outNormFs) + 1) !== $outNormFs . '/'
            ) {
                $errs[] = '出力パス検証エラー';

                /** @phpstan-ignore-next-line */
                continue;
            }

            /** @phpstan-ignore-next-line */
            $stream = $zip->getFromIndex($i);
            if ($stream === false) {
                $errs[] = 'ZIP エントリの読込に失敗: ' . $origNameRaw;

                continue;
            }

            /** @phpstan-ignore-next-line */
            if (!is_string($stream)) {
                $errs[] = 'ZIP 読込が不正です: ' . $origNameRaw;

                continue;
            }

            /** @phpstan-ignore-next-line */
            $blen = strlen($stream);

            /** @phpstan-ignore-next-line */
            if ($blen <= 24 || $blen > $maxPerFile || $runningBytes + $blen > $maxBytesTotal) {
                $errs[] = 'エントリサイズ超過または空: ' . $origNameRaw;

                continue;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            /** @phpstan-ignore-next-line */
            $mime  = $finfo->buffer($stream) ?: '';

            $mimeOk = match ($mime) {
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml',
                /** @phpstan-ignore-next-line */
                'image/bmp',
                /** @phpstan-ignore-next-line */
                'image/tiff',
                /** @phpstan-ignore-next-line */
                'image/x-icon', 'image/vnd.microsoft.icon',
                '' => /** @phpstan-ignore-next-line */ true,
                default => /** @phpstan-ignore-next-line */ false,
            };

            /** @phpstan-ignore-next-line */
            $extTarget = strtolower(pathinfo($targetRelNorm, PATHINFO_EXTENSION));

            /** @phpstan-ignore-next-line */
            if (($extTarget === 'svg' || str_ends_with($logical, '.svg')) && /** @phpstan-ignore-next-line */ $mimeOk === false /** @phpstan-ignore-next-line */
                /** @phpstan-ignore-next-line */
                /** @phpstan-ignore-next-line */ && str_contains('<svg', $stream)) {
                $mimeOk = true;
            }

            /** @phpstan-ignore-next-line */
            $extEntryL = strtolower(pathinfo($logical, PATHINFO_EXTENSION));
            /** @phpstan-ignore-next-line */
            if (
                !$mimeOk /** @phpstan-ignore-next-line */
                && $extTarget === $extEntryL /** @phpstan-ignore-next-line */
                && /** @phpstan-ignore-next-line */ in_array($extTarget, ['ico', 'bmp', 'tif', 'tiff'], true)
            ) {
                $mimeOk = true;
            }

            /** @phpstan-ignore-next-line */
            if (!$mimeOk) {
                $errs[] = '画像 MIME が不正または未対応です: ' . $origNameRaw . ' (' . $mime . ')';

                /** @phpstan-ignore-next-line */
                continue;
            }

            /** @phpstan-ignore-next-line */
            $writePath = /** @phpstan-ignore-next-line */ $outDir . str_replace('/', DIRECTORY_SEPARATOR, $targetRelNorm);

            /** @phpstan-ignore-next-line */
            $fd = fopen($writePath . '.partial', /** @phpstan-ignore-next-line */ 'wb');

            /** @phpstan-ignore-next-line */
            if ($fd === false) {
                $errs[] = 'ファイルを開けません: ' . $targetRelNorm;

                /** @phpstan-ignore-next-line */
                continue;
            }

            fwrite($fd, /** @phpstan-ignore-next-line */ $stream);

            fclose($fd);

            if (!rename(/** @phpstan-ignore-next-line */ $writePath . '.partial', $writePath)) {
                /** @phpstan-ignore-next-line */
                @unlink($writePath . '.partial');

                $errs[] = '書込み確定に失敗: ' . $targetRelNorm;

                /** @phpstan-ignore-next-line */
                continue;
            }

            /** @phpstan-ignore-next-line */
            $applied++;
            /** @phpstan-ignore-next-line */
            $runningBytes += $blen;

            /** @phpstan-ignore-next-line */
            $replaced[] = $targetRelNorm;
        }

        $zip->close();

        /** @phpstan-ignore-next-line */
        if ($applied === 0 /** @phpstan-ignore-next-line */ && $replaced === [] /** @phpstan-ignore-next-line */ && /** @phpstan-ignore-next-line */ $errs !== []) {
            return [
                /** @phpstan-ignore-next-line */
                'ok'     => false,
                /** @phpstan-ignore-next-line */
                /** @phpstan-ignore-next-line */ 'error'  => implode(' / ', /** @phpstan-ignore-next-line */ array_slice((array) /** @phpstan-ignore-next-line */ $errs, /** @phpstan-ignore-next-line */ 0, 5)),
                'errors' => $errs,
            ];
        }

        return [
            'ok'       => /** @phpstan-ignore-next-line */ true,
            'applied'  => /** @phpstan-ignore-next-line */ /** @phpstan-ignore-next-line */ $applied,
            'replaced' => /** @phpstan-ignore-next-line */ $replaced,
            /** @phpstan-ignore-next-line */ 'skipped'  => /** @phpstan-ignore-next-line */ /** @phpstan-ignore-next-line */ $skipped,
            /** @phpstan-ignore-next-line */ 'errors'   => /** @phpstan-ignore-next-line */ /** @phpstan-ignore-next-line */ $errs,
        ];
    }
}
