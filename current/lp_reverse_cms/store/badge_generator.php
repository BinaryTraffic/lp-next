<?php

declare(strict_types=1);

/**
 * バッジ形状＋テキストを GD で描画し JPEG 保存。
 *
 * POST JSON: { "width", "height", "badge": { "shape", "bg_color", "text_color" }, "texts": [...] }
 * 成功: { "url": "/output/ai_images/badge_<hex>.jpg" }
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/composite_fonts.php';
require_once __DIR__ . '/../lib/composite_gd_draw_texts.php';

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
$bdIn = isset($in['badge']) && is_array($in['badge']) ? $in['badge'] : [];
/** @var list<array<string, mixed>> $texts */
$texts = isset($in['texts']) && is_array($in['texts']) ? $in['texts'] : [];

if ($outW < 16 || $outH < 16 || $outW > 8192 || $outH > 8192) {
    http_response_code(400);
    echo json_encode(['error' => 'width / height は 16〜8192 の整数である必要があります'], JSON_UNESCAPED_UNICODE);
    exit;
}

$shape = in_array($bdIn['shape'] ?? '', ['circle', 'pill', 'ribbon', 'rect'], true) ? $bdIn['shape'] : 'circle';
$bgHex = isset($bdIn['bg_color']) ? trim((string) $bdIn['bg_color']) : '#e63c3c';
$rgbBg = composite_parse_color($bgHex) ?? [230, 60, 60];

if (!extension_loaded('gd') || !function_exists('imagettftext')) {
    http_response_code(500);
    echo json_encode(['error' => 'GD（FreeType 付き）が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fontRegularFile = composite_resolve_font_file(false);
$fontBoldFile = composite_resolve_font_file(true);
if ($fontRegularFile === '' && $fontBoldFile !== '') {
    $fontRegularFile = $fontBoldFile;
}
if ($fontBoldFile === '' && $fontRegularFile !== '') {
    $fontBoldFile = $fontRegularFile;
}
if ($fontRegularFile === '' && $fontBoldFile === '') {
    http_response_code(500);
    echo json_encode([
        'error' => '日本語フォントが見つかりません（' . composite_font_missing_hint() . '）',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fontRegularGd = composite_expand_font_for_gd($fontRegularFile);
$fontBoldGd = $fontBoldFile !== '' ? composite_expand_font_for_gd($fontBoldFile) : '';
if ($fontRegularGd === '' && $fontBoldGd === '') {
    http_response_code(500);
    echo json_encode(['error' => 'GD 用フォントパスを解決できませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}

$aiDir = LpWorkspace::outputDir($cmsRoot) . 'ai_images';
if (!is_dir($aiDir) && !@mkdir($aiDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'output/ai_images を作成できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$im = imagecreatetruecolor($outW, $outH);
if ($im === false) {
    http_response_code(500);
    echo json_encode(['error' => 'キャンバスの作成に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}
$white = imagecolorallocate($im, 255, 255, 255);
if ($white === false) {
    imagedestroy($im);
    http_response_code(500);
    echo json_encode(['error' => 'キャンバスの初期化に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}
imagefilledrectangle($im, 0, 0, max(0, $outW - 1), max(0, $outH - 1), $white);

$colBg = imagecolorallocate($im, $rgbBg[0], $rgbBg[1], $rgbBg[2]);
if ($colBg === false) {
    imagedestroy($im);
    http_response_code(500);
    echo json_encode(['error' => '色の割り当てに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$w1 = max(0, $outW - 1);
$h1 = max(0, $outH - 1);

switch ($shape) {
    case 'rect':
        imagefilledrectangle($im, 0, 0, $w1, $h1, $colBg);
        break;
    case 'circle':
        $d = min($outW, $outH);
        $cx = (int) round($outW / 2);
        $cy = (int) round($outH / 2);
        imagefilledellipse($im, $cx, $cy, $d, $d, $colBg);
        break;
    case 'pill':
        $r = (int) min(floor($outH / 2), floor($outW / 2));
        if ($r < 1) {
            $r = 1;
        }
        $my = (int) round($outH / 2);
        imagefilledrectangle($im, $r, 0, $w1 - $r, $h1, $colBg);
        imagefilledarc($im, $r, $my, 2 * $r, $outH, 90, 270, $colBg, IMG_ARC_PIE);
        imagefilledarc($im, $w1 - $r, $my, 2 * $r, $outH, 270, 90, $colBg, IMG_ARC_PIE);
        break;
    case 'ribbon':
        $cut = min(max(1, (int) round($outW * 0.15)), max(1, (int) round($outH * 0.45)));
        $mid = (int) round($outH / 2);
        $poly = [
            0, $mid,
            $cut, 0,
            $w1, 0,
            $w1, $h1,
            $cut, $h1,
        ];
        imagefilledpolygon($im, $poly, $colBg);
        break;
    default:
        imagefilledrectangle($im, 0, 0, $w1, $h1, $colBg);
}

imagealphablending($im, true);

$drawR = composite_gd_draw_texts($im, $outW, $outH, 0, 0, $outW, $outH, $texts, $fontRegularGd, $fontBoldGd);
$drewText = $drawR['drew'];

$nonEmpty = 0;
foreach ($texts as $it) {
    if (is_array($it) && trim((string) ($it['content'] ?? '')) !== '') {
        ++$nonEmpty;
    }
}
if ($nonEmpty > 0 && !$drewText) {
    imagedestroy($im);
    http_response_code(500);
    echo json_encode(['error' => 'テキストを描画できませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fname = 'badge_' . bin2hex(random_bytes(8)) . '.jpg';
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
