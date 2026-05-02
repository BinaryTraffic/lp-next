<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/LpCloneContext.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'image ファイルがありません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'アップロードエラー (' . $err . ')'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmp = (string) ($_FILES['image']['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '一時ファイルが無効です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxBytes = max(100_000, min(20_000_000, (int) (getenv('LP_USER_IMAGE_MAX_BYTES') ?: '8388608')));
$size = (int) ($_FILES['image']['size'] ?? 0);
if ($size < 32 || $size > $maxBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ファイルサイズが許容範囲外です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawName = (string) ($_FILES['image']['name'] ?? 'upload');
$ext = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '対応していない拡張子です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($tmp) ?: '';
$mimeOk = match ($mime) {
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml' => true,
    default => false,
};
if (!$mimeOk) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '画像形式として認識できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dataDir = LpWorkspace::dataDir($cmsRoot);
try {
    $customDir = LpCloneContext::customImagesAbsDir($cmsRoot);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
$cloneId = LpCloneContext::ensureIdInDataDir($dataDir);

$base = 'up_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
$dest = $customDir . $base;
if (!move_uploaded_file($tmp, $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$relSeg = LpCloneContext::customImagesRelSegment($cloneId, $base);
$rel    = 'output/ws_' . LpWorkspace::id() . '/' . $relSeg;
$path   = '/' . $rel;

echo json_encode([
    'ok'   => true,
    'path' => $path,
    'rel'  => $rel,
    'name' => $base,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
