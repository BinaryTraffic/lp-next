<?php

declare(strict_types=1);

require_once __DIR__ . '/LpWorkspace.php';

/**
 * クローンサイト単位の文脈（マルチユーザー時も「同一 ws 内の別クローン」を隔離）。
 *
 * - Workspace（ws_*）: PHP セッションに紐づく作業領域。
 * - CloneSite（clone_id）: 1 回の URL 取得で確定するクローン。custom_images は sites/<clone_id>/ 配下のみ。
 */
final class LpCloneContext
{
    public static function isValidId(string $id): bool
    {
        return strlen($id) === 32 && ctype_xdigit($id);
    }

    /**
     * clone_id.txt の生文字列から 32 桁 hex を得る。
     * UTF-8 BOM / 終端の改行で idFromDataDir が '' になり sites// の JSON を返していたのを防ぐ。
     */
    private static function parseStoredCloneId(string $raw): string
    {
        $bom = "\xEF\xBB\xBF";
        $t   = (string) $raw;
        if (strncmp($t, $bom, strlen($bom)) === 0) {
            $t = substr($t, strlen($bom));
        }
        $s = strtolower(trim(str_replace(["\r", "\n", "\0"], '', $t)));

        return self::isValidId($s) ? $s : '';
    }

    /**
     * data/clone_id.txt を読む（無効なら空）。
     */
    public static function idFromDataDir(string $dataDir): string
    {
        $f = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'clone_id.txt';
        if (!is_readable($f)) {
            return '';
        }
        $parsed = self::parseStoredCloneId((string) file_get_contents($f));

        return $parsed;
    }

    /**
     * 未取得の古いワークスペース用: clone_id を生成して保存。
     */
    public static function ensureIdInDataDir(string $dataDir): string
    {
        $id = self::idFromDataDir($dataDir);
        if ($id !== '') {
            return $id;
        }
        $id   = bin2hex(random_bytes(16));
        $path = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'clone_id.txt';
        file_put_contents($path, $id);

        return $id;
    }

    public static function sitesRootAbs(string $outputDir, string $cloneId): string
    {
        return rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR
            . $cloneId . DIRECTORY_SEPARATOR;
    }

    /**
     * このクローン用カスタム画像ディレクトリ（存在しなければ作成）。
     */
    public static function customImagesAbsDir(string $cmsRoot): string
    {
        $dataDir   = LpWorkspace::dataDir($cmsRoot);
        $outputDir = LpWorkspace::outputDir($cmsRoot);
        $cloneId   = self::ensureIdInDataDir($dataDir);
        $dir       = self::sitesRootAbs($outputDir, $cloneId) . 'custom_images' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('custom_images ディレクトリを作成できません: ' . $dir);
        }

        return $dir;
    }

    public static function customImagesRelSegment(string $cloneId, string $basename): string
    {
        return 'sites/' . $cloneId . '/custom_images/' . ltrim($basename, '/');
    }
}
