<?php

declare(strict_types=1);

/**
 * Serves workspace lp_structure.json for same-origin tools (e.g. lp_layout_images.html).
 * Direct HTTP access to data/*.json is denied by ../.htaccess — use this endpoint instead.
 */
require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$path = LpWorkspace::dataDir(dirname(__DIR__)) . 'lp_structure.json';
if (!is_readable($path)) {
    http_response_code(404);
    echo json_encode(['error' => 'lp_structure.json が見つかりません。CMS で解析を実行してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($path);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの読み込みに失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo $raw;
