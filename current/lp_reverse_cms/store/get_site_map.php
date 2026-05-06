<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$path = LpWorkspace::dataDir(dirname(__DIR__)) . 'site_map.json';
if (!is_readable($path)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'site_map.json が見つかりません。解析を実行してください。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($path);
if ($raw === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'site_map.json の読み込みに失敗しました。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo $raw;

