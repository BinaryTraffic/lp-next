<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/cms_editor_auth.php';
require_once dirname(__DIR__) . '/lib/LpCloneImagePack.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);

    exit;
}

$cmsRoot = dirname(__DIR__);

if (lp_reverse_resolve_workspace_editor($cmsRoot) === false) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);

    exit;
}

if (!isset($_FILES['pack']) || !is_array($_FILES['pack'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ZIP がありません'], JSON_UNESCAPED_UNICODE);

    exit;
}

$err = (int) ($_FILES['pack']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'アップロードエラー (' . $err . ')'], JSON_UNESCAPED_UNICODE);

    exit;
}

$tmp = (string) ($_FILES['pack']['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '一時ファイルが無効です'], JSON_UNESCAPED_UNICODE);

    exit;
}

$maxZip = max(1_048_576, min(157_286_400, (int) (getenv('LP_IMAGE_PACK_ZIP_UPLOAD_MAX_BYTES') ?: '62914560')));
$size = (int) ($_FILES['pack']['size'] ?? 0);
if ($size < 48 || $size > $maxZip) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ZIP サイズが許容範囲外です'], JSON_UNESCAPED_UNICODE);

    exit;
}

$result = LpCloneImagePack::importFromUploadedZip($cmsRoot, $tmp);

if (($result['ok'] ?? false) !== true) {
    http_response_code(400);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
