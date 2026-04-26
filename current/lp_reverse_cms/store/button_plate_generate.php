<?php

declare(strict_types=1);

/**
 * ボタン地（文字なし）の PNG を GD で生成し output/ai_images/ に保存する。
 *
 * POST JSON:
 * {
 *   "width": 200, "height": 48,
 *   "shape": "rect" | "rounded" | "pill",
 *   "style": "flat" | "gradient_3d" | "outline" | "soft_flat",
 *   "color": "#0b57d0",
 *   "color2": "#063d9e",       // gradient_3d の下端（省略時は color を暗く）
 *   "radius_pct": 0.18,        // rounded のみ、0〜0.5
 *   "stroke_width": 2,         // outline
 *   "inner_color": "#ffffff"   // outline の内側
 * }
 *
 * 成功: { "url": "/output/ai_images/plate_<hex>.png" }
 *
 * shape / style は lib/LpTheme.php の button_plate 定義に合わせて検証（未知は既定へ）。
 */
require_once __DIR__ . '/../lib/LpTheme.php';

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

$w = isset($in['width']) ? (int) $in['width'] : 0;
$h = isset($in['height']) ? (int) $in['height'] : 0;
if ($w < 16 || $h < 16 || $w > 2048 || $h > 2048) {
    http_response_code(400);
    echo json_encode(['error' => 'width / height は 16〜2048 である必要があります'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bpDef = LpTheme::buttonPlateDefaults();

$shape = strtolower(trim((string) ($in['shape'] ?? $bpDef['shape'] ?? 'rounded')));
if (!LpTheme::isValidButtonPlateShape($shape)) {
    $shape = (string) ($bpDef['shape'] ?? 'rounded');
}

$style = strtolower(trim((string) ($in['style'] ?? $bpDef['style'] ?? 'flat')));
if (!LpTheme::isValidButtonPlateStyle($style)) {
    $style = (string) ($bpDef['style'] ?? 'flat');
}

$defaultHex = (string) ($bpDef['color'] ?? '#0b57d0');
$defaultRgb = plate_parse_hex($defaultHex, [11, 87, 208]);
$color = plate_parse_hex((string) ($in['color'] ?? $defaultHex), $defaultRgb);
$color2 = isset($in['color2']) ? plate_parse_hex((string) $in['color2'], null) : null;
if ($color2 === null) {
    $color2 = plate_darken_rgb($color, 0.22);
}

$defRad = isset($bpDef['radius_pct']) ? (float) $bpDef['radius_pct'] : 0.18;
$radiusPct = isset($in['radius_pct']) ? (float) $in['radius_pct'] : $defRad;
$radiusPct = max(0.0, min(0.5, $radiusPct));

$defStroke = isset($bpDef['stroke_width']) ? (int) $bpDef['stroke_width'] : 2;
$strokeW = isset($in['stroke_width']) ? max(1, min(8, (int) $in['stroke_width'])) : $defStroke;

$defInner = (string) ($bpDef['inner_color'] ?? '#ffffff');
$innerRgb = plate_parse_hex($defInner, [255, 255, 255]);
$innerColor = plate_parse_hex((string) ($in['inner_color'] ?? $defInner), $innerRgb);

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP GD が利用できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 整数スキャンライン描画の角を滑らかにするため 2x で描いて縮小 */
$plateSs = 2;
$rw = $w * $plateSs;
$rh = $h * $plateSs;
$rPix = 0;
if ($shape === 'pill') {
    $rPix = (int) floor(min($rw, $rh) / 2);
} elseif ($shape === 'rounded') {
    $rPix = (int) round(min($rw, $rh) * $radiusPct);
    $rPix = max(2, min($rPix, (int) floor(min($rw, $rh) / 2)));
}
$strokeHi = max(1, (int) round($strokeW * $plateSs));

$im = imagecreatetruecolor($rw, $rh);
if ($im === false) {
    http_response_code(500);
    echo json_encode(['error' => '画像バッファの確保に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

imagealphablending($im, true);
imagesavealpha($im, true);
$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefill($im, 0, 0, $transparent);

if ($style === 'outline') {
    plate_draw_outline($im, $rw, $rh, $shape, $rPix, $color, $innerColor, $strokeHi);
} elseif ($style === 'gradient_3d') {
    plate_draw_gradient($im, $rw, $rh, $shape, $rPix, $color, $color2);
    plate_draw_inner_shadow_top($im, $rw, $rh, $shape, $rPix, 0.12);
} elseif ($style === 'soft_flat') {
    plate_draw_flat($im, $rw, $rh, $shape, $rPix, $color);
    plate_draw_inner_shadow_top($im, $rw, $rh, $shape, $rPix, 0.18);
    plate_draw_bottom_shade($im, $rw, $rh, $shape, $rPix, 0.08);
} else {
    plate_draw_flat($im, $rw, $rh, $shape, $rPix, $color);
}

if ($plateSs > 1) {
    $small = imagecreatetruecolor($w, $h);
    if ($small === false) {
        imagedestroy($im);
        http_response_code(500);
        echo json_encode(['error' => '画像バッファの確保に失敗しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    imagealphablending($small, false);
    imagesavealpha($small, true);
    $tr2 = imagecolorallocatealpha($small, 0, 0, 0, 127);
    imagefill($small, 0, 0, $tr2);
    imagealphablending($small, true);
    imagecopyresampled($small, $im, 0, 0, 0, 0, $w, $h, $rw, $rh);
    imagedestroy($im);
    $im = $small;
}

$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    imagedestroy($im);
    http_response_code(500);
    echo json_encode(['error' => 'CMS ルート解決エラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir = $cmsRoot . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'ai_images';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    imagedestroy($im);
    http_response_code(500);
    echo json_encode(['error' => 'output/ai_images を作成できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fname = 'plate_' . bin2hex(random_bytes(8)) . '.png';
$dest = $dir . DIRECTORY_SEPARATOR . $fname;
if (!imagepng($im, $dest, 6)) {
    imagedestroy($im);
    http_response_code(500);
    echo json_encode(['error' => 'PNG の保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}
imagedestroy($im);

echo json_encode(['url' => '/output/ai_images/' . $fname], JSON_UNESCAPED_UNICODE);

// -------------------------------------------------------------------------
/** @param array{0:int,1:int,2:int} $rgb */
function plate_parse_hex(string $s, ?array $fallback): array
{
    $s = trim($s);
    if (preg_match('/^#?([0-9a-f]{6})$/i', $s, $m)) {
        $n = hexdec($m[1]);

        return [(int) (($n >> 16) & 255), (int) (($n >> 8) & 255), (int) ($n & 255)];
    }
    if (preg_match('/^#?([0-9a-f]{3})$/i', $s, $m)) {
        $h = $m[1];
        $r = hexdec(str_repeat($h[0], 2));
        $g = hexdec(str_repeat($h[1], 2));
        $b = hexdec(str_repeat($h[2], 2));

        return [$r, $g, $b];
    }

    return $fallback ?? [0, 0, 0];
}

/** @param array{0:int,1:int,2:int} $rgb */
function plate_darken_rgb(array $rgb, float $amt): array
{
    $amt = max(0.0, min(1.0, $amt));

    return [
        (int) round($rgb[0] * (1 - $amt)),
        (int) round($rgb[1] * (1 - $amt)),
        (int) round($rgb[2] * (1 - $amt)),
    ];
}

/** @param array{0:int,1:int,2:int} $a @param array{0:int,1:int,2:int} $b */
function plate_lerp_rgb(array $a, array $b, float $t): array
{
    $t = max(0.0, min(1.0, $t));

    return [
        (int) round($a[0] + ($b[0] - $a[0]) * $t),
        (int) round($a[1] + ($b[1] - $a[1]) * $t),
        (int) round($a[2] + ($b[2] - $a[2]) * $t),
    ];
}

/**
 * @return array{0:int,1:int}
 */
function plate_row_bounds(int $w, int $h, int $r, int $y, string $shape): array
{
    if ($shape === 'rect' || $r <= 0) {
        return [0, $w - 1];
    }
    if ($shape === 'pill') {
        $ry = (int) floor($h / 2);
        if ($ry < 1) {
            return [0, $w - 1];
        }
        $dy = $y - $ry;
        $d = $ry * $ry - $dy * $dy;
        if ($d < 0) {
            return [$w, 0];
        }
        $dx = sqrt($d);
        $xL = (int) max(0, floor($ry - $dx));
        $xR = (int) min($w - 1, ceil($w - $ry - 1 + $dx));

        return [$xL, $xR];
    }

    $r = min($r, (int) floor(min($w, $h) / 2));
    if ($y < $r) {
        $xL = (int) floor($r - sqrt(max(0, $r * $r - ($r - $y) * ($r - $y))));
    } elseif ($y < $h - $r) {
        $xL = 0;
    } else {
        $k = $y - ($h - $r);
        $xL = (int) floor($r - sqrt(max(0, $r * $r - $k * $k)));
    }
    if ($y < $r) {
        $xR = (int) ceil($w - $r + sqrt(max(0, $r * $r - ($r - $y) * ($r - $y)))) - 1;
    } elseif ($y < $h - $r) {
        $xR = $w - 1;
    } else {
        $k = $y - ($h - $r);
        $xR = (int) ceil($w - $r + sqrt(max(0, $r * $r - $k * $k))) - 1;
    }

    return [max(0, $xL), min($w - 1, $xR)];
}

/** @param array{0:int,1:int,2:int} $rgb */
function plate_draw_flat($im, int $w, int $h, string $shape, int $r, array $rgb): void
{
    $col = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    for ($y = 0; $y < $h; $y++) {
        [$xL, $xR] = plate_row_bounds($w, $h, $r, $y, $shape);
        if ($xL <= $xR) {
            imageline($im, $xL, $y, $xR, $y, $col);
        }
    }
}

/** @param array{0:int,1:int,2:int} $top @param array{0:int,1:int,2:int} $bot */
function plate_draw_gradient($im, int $w, int $h, string $shape, int $r, array $top, array $bot): void
{
    $den = max(1, $h - 1);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $den;
        $rgb = plate_lerp_rgb($top, $bot, $t);
        $col = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
        [$xL, $xR] = plate_row_bounds($w, $h, $r, $y, $shape);
        if ($xL <= $xR) {
            imageline($im, $xL, $y, $xR, $y, $col);
        }
    }
}

/** @param array{0:int,1:int,2:int} $stroke @param array{0:int,1:int,2:int} $inner */
function plate_draw_outline($im, int $w, int $h, string $shape, int $r, array $stroke, array $inner, int $sw): void
{
    plate_draw_flat($im, $w, $h, $shape, $r, $stroke);
    $iw = $w - 2 * $sw;
    $ih = $h - 2 * $sw;
    if ($iw < 4 || $ih < 4) {
        return;
    }
    $rIn = $shape === 'pill' ? (int) floor($ih / 2) : max(0, $r - $sw);
    if ($shape === 'rounded' && $rIn > 0) {
        $rIn = max(1, min($rIn, (int) floor(min($iw, $ih) / 2)));
    }
    $im2 = imagecreatetruecolor($iw, $ih);
    if ($im2 === false) {
        return;
    }
    imagealphablending($im2, true);
    imagesavealpha($im2, true);
    $tr = imagecolorallocatealpha($im2, 0, 0, 0, 127);
    imagefill($im2, 0, 0, $tr);
    plate_draw_flat($im2, $iw, $ih, $shape, $rIn, $inner);
    imagecopy($im, $im2, $sw, $sw, 0, 0, $iw, $ih);
    imagedestroy($im2);
}

function plate_draw_inner_shadow_top($im, int $w, int $h, string $shape, int $r, float $alpha): void
{
    $alpha = max(0.02, min(0.5, $alpha));
    $lines = max(1, (int) round($h * 0.12));
    for ($i = 0; $i < $lines; $i++) {
        $y = $i;
        if ($y >= $h) {
            break;
        }
        $a = (int) round(127 * (1 - $alpha * (1 - $i / $lines)));
        $col = imagecolorallocatealpha($im, 255, 255, 255, max(0, min(127, $a)));
        [$xL, $xR] = plate_row_bounds($w, $h, $r, $y, $shape);
        if ($xL <= $xR) {
            imageline($im, $xL, $y, $xR, $y, $col);
        }
    }
}

function plate_draw_bottom_shade($im, int $w, int $h, string $shape, int $r, float $alpha): void
{
    $alpha = max(0.02, min(0.4, $alpha));
    $lines = max(1, (int) round($h * 0.1));
    for ($i = 0; $i < $lines; $i++) {
        $y = $h - 1 - $i;
        if ($y < 0) {
            break;
        }
        $a = (int) round(127 * (1 - $alpha * (1 - $i / $lines)));
        $col = imagecolorallocatealpha($im, 0, 0, 0, max(0, min(127, $a)));
        [$xL, $xR] = plate_row_bounds($w, $h, $r, $y, $shape);
        if ($xL <= $xR) {
            imageline($im, $xL, $y, $xR, $y, $col);
        }
    }
}
