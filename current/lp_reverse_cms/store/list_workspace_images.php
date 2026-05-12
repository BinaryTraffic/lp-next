<?php

declare(strict_types=1);

/**
 * 現在のクローン用カスタム画像のみ一覧（+ ルート直下の旧版互換ディレクトリ）。
 * クローン取得の assets/img は含めない。
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/LpCloneContext.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'CMS root'], JSON_UNESCAPED_UNICODE);
    exit;
}

$outputDir = LpWorkspace::outputDir($cmsRoot);
$dataDir   = LpWorkspace::dataDir($cmsRoot);
$outReal   = realpath($outputDir);
$wsId      = LpWorkspace::id();
$prefix    = '/output/ws_' . $wsId . '/';
$relPrefix = 'output/ws_' . $wsId . '/';

if ($outReal === false || !is_dir($outReal)) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
$maxOut  = max(20, min(400, (int) (getenv('LP_LIST_IMAGES_MAX') ?: '200')));

/** @var list<array{mtime: int, path: string, rel: string, name: string}> $rows */
$rows = [];

$pushDir = static function (string $absDir) use ($outReal, $prefix, $relPrefix, $allowed, &$rows): void {
    if (!is_dir($absDir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $allowed, true)) {
            continue;
        }
        $real = realpath($file->getPathname());
        if ($real === false) {
            continue;
        }
        $realNorm = str_replace('\\', '/', $real);
        $outNorm  = str_replace('\\', '/', $outReal);
        if (!str_starts_with($realNorm, $outNorm)) {
            continue;
        }
        $inside = substr($realNorm, strlen($outNorm));
        $inside = ltrim($inside, '/');
        if ($inside === '') {
            continue;
        }
        $rows[] = [
            'mtime' => (int) $file->getMTime(),
            'path'  => $prefix . $inside,
            'rel'   => $relPrefix . $inside,
            'name'  => basename($inside),
        ];
    }
};

$cloneId = LpCloneContext::idFromDataDir($dataDir);
if ($cloneId !== '') {
    $pushDir(LpCloneContext::sitesRootAbs($outputDir, $cloneId) . 'custom_images');
}
$pushDir($outputDir . 'custom_images');
$pushDir($outputDir . 'user_uploads');

usort($rows, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

$rows  = array_slice($rows, 0, $maxOut);
$items = array_map(static function (array $r): array {
    return [
        'path' => $r['path'],
        'rel'  => $r['rel'],
        'name' => $r['name'],
    ];
}, $rows);

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
