<?php

declare(strict_types=1);

/**
 * グラデーション背景 JPEG を生成し output/ai_images に保存する。
 *
 * POST JSON: { "width", "height", "gradient": { "type": "linear"|"radial", "angle", "colors": [{ "color", "stop" }] } }
 * 成功: { "url": "/output/ai_images/grad_<hex>.jpg" }
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/composite_color_geom.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

require_once __DIR__ . '/../lib/LpWorkspace.php';
$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    http_response_code(500);
    echo json_encode(['error' => 'CMS ルートの解決に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$outW = isset($in['width']) ? (int) $in['width'] : 0;
$outH = isset($in['height']) ? (int) $in['height'] : 0;
$gIn = isset($in['gradient']) && is_array($in['gradient']) ? $in['gradient'] : [];

if ($outW < 16 || $outH < 16 || $outW > 8192 || $outH > 8192) {
    http_response_code(400);
    echo json_encode(['error' => 'width / height は 16〜8192 の整数である必要があります'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    echo json_encode(['error' => 'GD 拡張が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$aiDir = LpWorkspace::outputDir($cmsRoot) . 'ai_images';
if (!is_dir($aiDir) && !@mkdir($aiDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'output/ai_images を作成できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$gType = in_array($gIn['type'] ?? '', ['linear', 'radial'], true) ? $gIn['type'] : 'linear';
$angle = isset($gIn['angle']) ? (int) $gIn['angle'] : 180;
$rawStops = isset($gIn['colors']) && is_array($gIn['colors']) ? $gIn['colors'] : [];
$stops = gradient_normalize_stops($rawStops);
if ($stops === []) {
    http_response_code(400);
    echo json_encode(['error' => 'gradient.colors が無効です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$im = imagecreatetruecolor($outW, $outH);
if ($im === false) {
    http_response_code(500);
    echo json_encode(['error' => 'キャンバスの作成に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$wm = max(1, $outW - 1);
$hm = max(1, $outH - 1);
$cx = $wm / 2.0;
$cy = $hm / 2.0;

if ($gType === 'radial') {
    $maxD = 1.0;
    foreach ([[0.0, 0.0], [$wm, 0.0], [0.0, $hm], [$wm, $hm]] as $pt) {
        $d = hypot($pt[0] - $cx, $pt[1] - $cy);
        if ($d > $maxD) {
            $maxD = $d;
        }
    }
    for ($y = 0; $y < $outH; $y++) {
        for ($x = 0; $x < $outW; $x++) {
            $t = hypot($x - $cx, $y - $cy) / $maxD;
            $t = max(0.0, min(1.0, $t));
            [$r, $g, $b] = gradient_sample_rgb($stops, $t);
            $col = imagecolorallocate($im, $r, $g, $b);
            if ($col !== false) {
                imagesetpixel($im, $x, $y, $col);
            }
        }
    }
} else {
    $rad = deg2rad($angle);
    $ux = sin($rad);
    $uy = cos($rad);
    $sCorners = [];
    foreach ([[0.0, 0.0], [$wm, 0.0], [0.0, $hm], [$wm, $hm]] as $pt) {
        $sCorners[] = ($pt[0] - $cx) * $ux + ($pt[1] - $cy) * $uy;
    }
    $smin = min($sCorners);
    $smax = max($sCorners);
    $den = $smax - $smin;
    if ($den < 1e-9) {
        $den = 1.0;
    }
    for ($y = 0; $y < $outH; $y++) {
        for ($x = 0; $x < $outW; $x++) {
            $s = ($x - $cx) * $ux + ($y - $cy) * $uy;
            $t = ($s - $smin) / $den;
            $t = max(0.0, min(1.0, $t));
            [$r, $g, $b] = gradient_sample_rgb($stops, $t);
            $col = imagecolorallocate($im, $r, $g, $b);
            if ($col !== false) {
                imagesetpixel($im, $x, $y, $col);
            }
        }
    }
}

$fname = 'grad_' . bin2hex(random_bytes(8)) . '.jpg';
$destAbs = $aiDir . DIRECTORY_SEPARATOR . $fname;
$publicUrl = LpWorkspace::outputWebAbsPrefix() . 'ai_images/' . $fname;

if (!imagejpeg($im, $destAbs, 92)) {
    imagedestroy($im);
    http_response_code(500);
    echo json_encode(['error' => '画像の保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}
imagedestroy($im);

echo json_encode(['url' => $publicUrl], JSON_UNESCAPED_UNICODE);

/**
 * @param list<mixed> $raw
 *
 * @return list<array{t: float, r: int, g: int, b: int}>
 */
function gradient_normalize_stops(array $raw): array
{
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $hex = isset($item['color']) ? trim((string) $item['color']) : '';
        $rgb = composite_parse_color($hex);
        if ($rgb === null) {
            continue;
        }
        $stop = isset($item['stop']) ? (float) $item['stop'] : 0.0;
        $out[] = ['t' => max(0.0, min(1.0, $stop)), 'r' => $rgb[0], 'g' => $rgb[1], 'b' => $rgb[2]];
    }
    if ($out === []) {
        return [
            ['t' => 0.0, 'r' => 0x3a, 'g' => 0x7b, 'b' => 0xd5],
            ['t' => 1.0, 'r' => 0, 'g' => 0xd2, 'b' => 0xff],
        ];
    }
    usort($out, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);
    if ($out[0]['t'] > 0.0) {
        array_unshift($out, [
            't'   => 0.0,
            'r'   => $out[0]['r'],
            'g'   => $out[0]['g'],
            'b'   => $out[0]['b'],
        ]);
    }
    $last = $out[array_key_last($out)];
    if ($last['t'] < 1.0) {
        $out[] = [
            't'   => 1.0,
            'r'   => $last['r'],
            'g'   => $last['g'],
            'b'   => $last['b'],
        ];
    }

    return $out;
}

/**
 * @param list<array{t: float, r: int, g: int, b: int}> $stops
 *
 * @return array{0: int, 1: int, 2: int}
 */
function gradient_sample_rgb(array $stops, float $t): array
{
    $t = max(0.0, min(1.0, $t));
    if ($stops === []) {
        return [128, 128, 128];
    }
    if ($t <= $stops[0]['t']) {
        $a = $stops[0];

        return [$a['r'], $a['g'], $a['b']];
    }
    $n = count($stops);
    if ($t >= $stops[$n - 1]['t']) {
        $a = $stops[$n - 1];

        return [$a['r'], $a['g'], $a['b']];
    }
    for ($i = 0; $i < $n - 1; $i++) {
        $a = $stops[$i];
        $b = $stops[$i + 1];
        if ($t >= $a['t'] && $t <= $b['t']) {
            $den = $b['t'] - $a['t'];
            if ($den < 1e-9) {
                return [$b['r'], $b['g'], $b['b']];
            }
            $u = ($t - $a['t']) / $den;

            return [
                (int) round($a['r'] + ($b['r'] - $a['r']) * $u),
                (int) round($a['g'] + ($b['g'] - $a['g']) * $u),
                (int) round($a['b'] + ($b['b'] - $a['b']) * $u),
            ];
        }
    }
    $a = $stops[$n - 1];

    return [$a['r'], $a['g'], $a['b']];
}
