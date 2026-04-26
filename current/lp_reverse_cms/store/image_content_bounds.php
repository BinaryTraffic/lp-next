<?php

declare(strict_types=1);

/**
 * POST JSON: { "image_url": "/output/..." | "output/..." }
 * 成功: {
 *   "full_width", "full_height",
 *   "content_bounds": { padding_*, button_w, button_h },
 *   "trimmed": true | false  // 内容領域がフルサイズより小さいか（image_composite の余白検出と同一）
 * }
 */
require_once __DIR__ . '/../lib/composite_content_bounds.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageUrl = isset($in['image_url']) ? trim((string) $in['image_url']) : '';

if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
    http_response_code(500);
    echo json_encode(['error' => 'GD 拡張が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$path = image_content_bounds_resolve_path($imageUrl);
if ($path === null) {
    http_response_code(400);
    echo json_encode(['error' => 'image_url が output 配下の画像として解決できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bin = @file_get_contents($path);
if ($bin === false || $bin === '') {
    http_response_code(400);
    echo json_encode(['error' => '画像を読み込めません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$im = @imagecreatefromstring($bin);
if ($im === false) {
    http_response_code(400);
    echo json_encode(['error' => 'GD が画像をデコードできません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fullW = imagesx($im);
$fullH = imagesy($im);
if ($fullW < 1 || $fullH < 1) {
    imagedestroy($im);
    http_response_code(400);
    echo json_encode(['error' => '画像サイズが不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bounds = composite_detect_margin_with_fallback($im, $fullW, $fullH);
imagedestroy($im);

$cb = composite_content_bounds_for_json($bounds);
$trimmed = ($cb['button_w'] < $fullW - 1) || ($cb['button_h'] < $fullH - 1);

echo json_encode([
    'full_width'      => $fullW,
    'full_height'     => $fullH,
    'content_bounds'  => $cb,
    'trimmed'         => $trimmed,
], JSON_UNESCAPED_UNICODE);

/**
 * image_composite.php の background 解決と同じ（output 配下のみ）。
 */
function image_content_bounds_resolve_path(string $url): ?string
{
    $url = str_replace('\\', '/', trim($url));
    if ($url === '' || str_contains($url, '..')) {
        return null;
    }

    $cmsRoot = realpath(dirname(__DIR__));
    if ($cmsRoot === false) {
        return null;
    }

    $outRoot = realpath($cmsRoot . DIRECTORY_SEPARATOR . 'output');
    if ($outRoot === false) {
        return null;
    }

    if (str_starts_with($url, '/output/')) {
        $full = $cmsRoot . str_replace('/', DIRECTORY_SEPARATOR, $url);
    } elseif (str_starts_with($url, 'output/')) {
        $full = $cmsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $url);
    } else {
        return null;
    }

    $resolved = realpath($full);
    if ($resolved === false || !is_file($resolved)) {
        return null;
    }
    if (!str_starts_with($resolved, $outRoot)) {
        return null;
    }

    return $resolved;
}
