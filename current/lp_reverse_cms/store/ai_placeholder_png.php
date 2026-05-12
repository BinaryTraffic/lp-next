<?php

declare(strict_types=1);

/**
 * プレースホルダ PNG 単体生成（テスト用）。
 *
 * POST JSON: { "width": 800, "height": 600, "lines": ["optional", "ascii"] }
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/placeholder_png.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    http_response_code(500);
    echo json_encode(['error' => 'CMS ルートの解決に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$in = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$w = isset($in['width']) ? (int) $in['width'] : 512;
$h = isset($in['height']) ? (int) $in['height'] : 384;
$lines = isset($in['lines']) && is_array($in['lines']) ? $in['lines'] : [];

try {
    $url = lp_reverse_save_placeholder_png($cmsRoot, $w, $h, '[ PLACEHOLDER ]', $lines);
    echo json_encode(['url' => $url], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
