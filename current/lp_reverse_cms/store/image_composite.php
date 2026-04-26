<?php

declare(strict_types=1);

/**
 * 背景画像とテキスト（Claude Vision 座標 0〜1）を合成し JPEG を保存する。
 *
 * POST JSON:
 * {
 *   "source_url": "/output/assets/img/btn01.jpg",
 *   "background_url": "/output/ai_images/hf_xxx.jpg",
 *   "width": 219,
 *   "height": 51,
 *   "texts": [{
 *     "content": "予約はこちら",
 *     "x_pct": 0.18, "y_pct": 0.20, "w_pct": 0.64, "h_pct": 0.60,
 *     "font_size_pct": 0.30, "bold": true, "color": "#ffffff"
 *   }],
 *   "icons": [{
 *     "label": "LINE",
 *     "x_pct": 0.05, "y_pct": 0.15, "w_pct": 0.20, "h_pct": 0.70
 *   }],
 *   "border_fill": { "color": "#c8a96e", "width_px": 24 }
 * }
 *
 * source_url を指定すると、元画像からグレー帯などの余白（RGB 各チャンネル 200 以上かつほぼ白でない画素の帯）を検出し、
 * 出力キャンバスを width×height で余白色で塗ったうえで、内側矩形に background のみリサイズ合成する。
 *
 * border_fill を指定したときは source_url による余白検出は行わない。全面を color で塗り、width_px 幅の枠の内側に background を貼る（bordered フロー用）。
 *
 * bordered 呼出フロー（新規ファイル不要）:
 * ① 本エンドポイントで type=bordered を解析（claude_image_analyze.php）
 * ② クライアントで inner_w/h = width - 2*round(min(w,h)*width_pct) 等を算出
 * ③ hf_image_proxy.php で内側画像を生成 → inner_url
 * ④ 本エンドポイントに background_url=inner_url, border_fill={color,width_px}, width/height=外枠サイズ を POST
 *
 * icons が空でないときは SVG を Imagick でラスタライズして背景の上・テキストの下に重ねる（Imagick 必須）。
 *
 * crop_to_button: true かつ source_url 指定時、保存 JPEG を余白の内側矩形（button_w×button_h）だけに切り出す。
 * ボタン差し替えツールは板サイズと一致する書き出し用に true を送る。
 *
 * 成功: { "url": "...", "content_bounds": { ... }, "output_width"?, "output_height"? }
 *
 * 余白検出（白 RGB≥245 または透過）は GD。本エンドポイントは GD 必須。
 * エンジン: テキストは GD + FreeType を優先。不可のとき Imagick。icons は Imagick で SVG ラスタ化。
 * フォント: .env の IMAGE_COMPOSITE_FONT* → lp_reverse_cms/fonts/ の Noto 系 →
 * 代表的な /usr/share/fonts/... → fontconfig（fc-list :lang=ja）の順で解決。
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/icon_map.php';
require_once __DIR__ . '/../lib/composite_content_bounds.php';
require_once __DIR__ . '/../lib/composite_fonts.php';
require_once __DIR__ . '/../lib/composite_color_geom.php';
require_once __DIR__ . '/../lib/composite_gd_draw_texts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$backgroundUrl = isset($in['background_url']) ? trim((string) $in['background_url']) : '';
$sourceUrl = isset($in['source_url']) ? trim((string) $in['source_url']) : '';
/** @var array{color: string, width_px: int}|null $borderFill */
$borderFill = null;
if (isset($in['border_fill']) && is_array($in['border_fill'])) {
    $bf = $in['border_fill'];
    $borderFill = [
        'color'    => isset($bf['color']) ? trim((string) $bf['color']) : '#000000',
        'width_px' => isset($bf['width_px']) ? (int) $bf['width_px'] : 0,
    ];
}
$outW = isset($in['width']) ? (int) $in['width'] : 0;
$outH = isset($in['height']) ? (int) $in['height'] : 0;
$cropToButton = !empty($in['crop_to_button']);
/** @var list<array<string, mixed>> $texts */
$texts = isset($in['texts']) && is_array($in['texts']) ? $in['texts'] : [];
/** @var list<array<string, mixed>> $icons */
$icons = isset($in['icons']) && is_array($in['icons']) ? $in['icons'] : [];
$hasIcons = $icons !== [];

if ($backgroundUrl === '') {
    http_response_code(400);
    echo json_encode(['error' => 'background_url が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($outW < 16 || $outH < 16 || $outW > 8192 || $outH > 8192) {
    http_response_code(400);
    echo json_encode(['error' => 'width / height は 16〜8192 の整数である必要があります'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor') || !function_exists('imagecolorat')) {
    http_response_code(500);
    echo json_encode([
        'error' => 'image_composite には余白検出用の GD 拡張が必要です',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$engine = composite_pick_engine();
if ($engine === '') {
    http_response_code(500);
    echo json_encode([
        'error' => 'PHP に GD（FreeType 付き）または Imagick 拡張が必要です',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$bgPath = composite_resolve_background_path($backgroundUrl);
if ($bgPath === null) {
    http_response_code(400);
    echo json_encode(['error' => 'background_url が無効か、output 配下のファイルとして解決できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sourcePath = null;
if ($sourceUrl !== '' && $borderFill === null) {
    $sourcePath = composite_resolve_background_path($sourceUrl);
    if ($sourcePath === null) {
        http_response_code(400);
        echo json_encode(['error' => 'source_url が無効か、output 配下のファイルとして解決できません'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if ($cropToButton && $sourcePath === null) {
    http_response_code(400);
    echo json_encode(['error' => 'crop_to_button は source_url と併用してください'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($borderFill !== null) {
    $bp = max(0, $borderFill['width_px']);
    if ($bp * 2 >= $outW || $bp * 2 >= $outH) {
        http_response_code(400);
        echo json_encode(['error' => 'border_fill.width_px がキャンバスに対して大きすぎます'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($outW - 2 * $bp < 1 || $outH - 2 * $bp < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'border_fill 指定時の内側サイズが無効です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
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

if ($hasIcons && !composite_imagick_available()) {
    http_response_code(500);
    echo json_encode([
        'error' => 'icons の合成には PHP Imagick 拡張と ImageMagick（SVG ラスタライズ）が必要です',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    http_response_code(500);
    echo json_encode(['error' => 'CMS ルートの解決に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$aiDir = $cmsRoot . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'ai_images';
if (!is_dir($aiDir) && !@mkdir($aiDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'output/ai_images を作成できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fname = 'composed_' . bin2hex(random_bytes(8)) . '.jpg';
$destAbs = $aiDir . DIRECTORY_SEPARATOR . $fname;
$publicUrl = '/output/ai_images/' . $fname;

/** @var array{error: ?string, content_bounds: array<string, int>, output_width?: int, output_height?: int} $renderResult */
$renderResult = ['error' => '内部エラー', 'content_bounds' => []];
if ($hasIcons) {
    $fontRegularGdIcons = composite_expand_font_for_gd($fontRegularFile);
    $fontBoldGdIcons = $fontBoldFile !== '' ? composite_expand_font_for_gd($fontBoldFile) : '';
    $gdFontsOkIcons = ($fontRegularGdIcons !== '' || $fontBoldGdIcons !== '');
    if ($gdFontsOkIcons) {
        $renderResult = composite_render_gd($bgPath, $outW, $outH, $texts, $icons, $destAbs, $fontRegularGdIcons, $fontBoldGdIcons, $sourcePath, $borderFill, $cropToButton);
    } else {
        $renderResult = composite_render_imagick($bgPath, $outW, $outH, $texts, $icons, $destAbs, $fontRegularFile, $fontBoldFile, $sourcePath, $borderFill, $cropToButton);
    }
} elseif ($engine === 'gd') {
    $fontRegularGd = composite_expand_font_for_gd($fontRegularFile);
    $fontBoldGd = $fontBoldFile !== '' ? composite_expand_font_for_gd($fontBoldFile) : '';
    $gdFontsOk = ($fontRegularGd !== '' || $fontBoldGd !== '');
    if (!$gdFontsOk && composite_imagick_available()) {
        $renderResult = composite_render_imagick($bgPath, $outW, $outH, $texts, [], $destAbs, $fontRegularFile, $fontBoldFile, $sourcePath, $borderFill, $cropToButton);
    } elseif (!$gdFontsOk) {
        http_response_code(500);
        echo json_encode([
            'error' => 'GD が .ttc の face を解決できませんでした（Windows ではドライブ文字と :index が衝突することがあります）。Imagick を有効にするか、NotoSansCJKjp-Regular.otf 等の .otf を IMAGE_COMPOSITE_FONT に指定してください。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $renderResult = composite_render_gd($bgPath, $outW, $outH, $texts, [], $destAbs, $fontRegularGd, $fontBoldGd, $sourcePath, $borderFill, $cropToButton);
    }
} else {
    $renderResult = composite_render_imagick($bgPath, $outW, $outH, $texts, [], $destAbs, $fontRegularFile, $fontBoldFile, $sourcePath, $borderFill, $cropToButton);
}
if ($renderResult['error'] !== null) {
    $err = $renderResult['error'];
    $badRequest = $err === '背景画像の読み込みに失敗しました'
        || $err === 'source_url の画像サイズが width / height と一致しません'
        || $err === 'source_url の画像を読み込めませんでした';
    http_response_code($badRequest ? 400 : 500);
    echo json_encode(['error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$outJson = [
    'url'            => $publicUrl,
    'content_bounds' => $renderResult['content_bounds'],
];
if (isset($renderResult['output_width'], $renderResult['output_height'])) {
    $outJson['output_width'] = $renderResult['output_width'];
    $outJson['output_height'] = $renderResult['output_height'];
}
echo json_encode($outJson, JSON_UNESCAPED_UNICODE);

function composite_pick_engine(): string
{
    if (extension_loaded('gd') && function_exists('imagettftext') && function_exists('imagettfbbox')) {
        return 'gd';
    }
    if (extension_loaded('imagick') && class_exists('Imagick', false)) {
        return 'imagick';
    }

    return '';
}

function composite_imagick_available(): bool
{
    return extension_loaded('imagick') && class_exists('Imagick', false);
}

/**
 * @return GdImage|false
 */
function composite_gd_clone_rect(GdImage $src, int $sx, int $sy, int $sw, int $sh)
{
    if ($sw < 1 || $sh < 1) {
        return false;
    }
    $dst = imagecreatetruecolor($sw, $sh);
    if ($dst === false) {
        return false;
    }
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $tr = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    if ($tr !== false) {
        imagefill($dst, 0, 0, $tr);
    }
    imagealphablending($dst, true);
    imagecopy($dst, $src, 0, 0, $sx, $sy, $sw, $sh);

    return $dst;
}

/**
 * Imagick を PNG 経由で GdImage に（余白検出用・フラット前推奨）。
 *
 * @return GdImage|false
 */
function composite_imagick_to_gd_probe(Imagick $img): GdImage|false
{
    try {
        $clone = clone $img;
        $clone->setImageFormat('png');
        if (defined('Imagick::ALPHACHANNEL_ACTIVATE')) {
            $clone->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        }
        $blob = $clone->getImageBlob();
        $clone->clear();
        if ($blob === '') {
            return false;
        }
        $gd = @imagecreatefromstring($blob);

        return $gd !== false ? $gd : false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 角丸ボタン板など「角が透過」の PNG で、マットを面色にすると透過部まで同色で塗られ角丸が消える。
 * 四隅の複数が透過なら true。
 */
function composite_image_has_transparent_corners_gd($im): bool
{
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 2 || $h < 2) {
        return false;
    }
    $corners = [[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]];
    $transparent = 0;
    foreach ($corners as [$x, $y]) {
        $ci = imagecolorat($im, $x, $y);
        if (imageistruecolor($im)) {
            $a = ($ci >> 24) & 127;
            if ($a > 60) {
                ++$transparent;
            }
        } else {
            $cols = @imagecolorsforindex($im, $ci);
            if (is_array($cols) && ($cols['alpha'] ?? 0) > 60) {
                ++$transparent;
            }
        }
    }

    return $transparent >= 2;
}

/**
 * PNG 透過を JPEG にする際のマット RGB。
 * 角透過の板は白マット（角丸を保持）。不透明画像のみ従来どおり面付近をサンプル。
 *
 * @return array{0:int,1:int,2:int}
 */
function composite_sample_matte_rgb($im): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 1 || $h < 1) {
        return [255, 255, 255];
    }
    if (composite_image_has_transparent_corners_gd($im)) {
        return [255, 255, 255];
    }
    $pts = [
        [(int) ($w / 2), (int) ($h / 2)],
        [(int) ($w / 2), (int) ($h / 3)],
        [(int) ($w / 3), (int) ($h / 2)],
        [(int) (2 * $w / 3), (int) ($h / 2)],
    ];
    foreach ($pts as [$x, $y]) {
        if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
            continue;
        }
        $ci = imagecolorat($im, $x, $y);
        if (imageistruecolor($im)) {
            $a = ($ci >> 24) & 127;
            if ($a >= 126) {
                continue;
            }
            $r = ($ci >> 16) & 0xFF;
            $g = ($ci >> 8) & 0xFF;
            $b = $ci & 0xFF;
        } else {
            $cols = imagecolorsforindex($im, $ci);
            $a = (int) ($cols['alpha'] ?? 0);
            if ($a >= 126) {
                continue;
            }
            $r = (int) $cols['red'];
            $g = (int) $cols['green'];
            $b = (int) $cols['blue'];
        }

        return [$r, $g, $b];
    }

    return [255, 255, 255];
}

/**
 * @param list<array<string, mixed>> $icons 空なら無視（通常は Imagick 経路で icons を渡す）
 *
 * @return array{error: ?string, content_bounds: array<string, int>, output_width?: int, output_height?: int}
 */
function composite_render_gd(
    string $bgPath,
    int $outW,
    int $outH,
    array $texts,
    array $icons,
    string $destAbs,
    string $fontRegularGd,
    string $fontBoldGd,
    ?string $sourcePath = null,
    ?array $borderFill = null,
    bool $cropToButton = false
): array {
    if ($borderFill !== null) {
        $borderPx = max(0, (int) ($borderFill['width_px'] ?? 0));
        [$br, $bgc, $bb] = composite_parse_color((string) ($borderFill['color'] ?? '#000000')) ?? [0, 0, 0];
        $bx = $borderPx;
        $by = $borderPx;
        $bw = $outW - 2 * $borderPx;
        $bh = $outH - 2 * $borderPx;
        $fullBase = imagecreatetruecolor($outW, $outH);
        if ($fullBase === false) {
            return ['error' => 'キャンバスの作成に失敗しました', 'content_bounds' => []];
        }
        $matte = imagecolorallocate($fullBase, $br, $bgc, $bb);
        if ($matte === false) {
            imagedestroy($fullBase);

            return ['error' => 'キャンバスの初期化に失敗しました', 'content_bounds' => []];
        }
        imagefilledrectangle($fullBase, 0, 0, max(0, $outW - 1), max(0, $outH - 1), $matte);
        imagealphablending($fullBase, true);
        imagesavealpha($fullBase, false);

        $plateIm = composite_image_load($bgPath);
        if ($plateIm === false) {
            imagedestroy($fullBase);

            return ['error' => '背景画像の読み込みに失敗しました', 'content_bounds' => []];
        }
        imagesavealpha($plateIm, true);
        imagealphablending($plateIm, true);
        $pw = imagesx($plateIm);
        $ph = imagesy($plateIm);
        $marginBounds = [
            'padding_top'    => $borderPx,
            'padding_right'  => $borderPx,
            'padding_bottom' => $borderPx,
            'padding_left'   => $borderPx,
            'button_x'       => $bx,
            'button_y'       => $by,
            'button_w'       => $bw,
            'button_h'       => $bh,
        ];
        $cbJson = composite_content_bounds_for_json($marginBounds);
        imagecopyresampled($fullBase, $plateIm, $bx, $by, 0, 0, $bw, $bh, $pw, $ph);
        imagedestroy($plateIm);
    } elseif ($sourcePath !== null) {
        $sourceGd = composite_image_load($sourcePath);
        if ($sourceGd === false) {
            return ['error' => 'source_url の画像を読み込めませんでした', 'content_bounds' => []];
        }
        $sow = imagesx($sourceGd);
        $soh = imagesy($sourceGd);
        if ($sow !== $outW || $soh !== $outH) {
            imagedestroy($sourceGd);

            return ['error' => 'source_url の画像サイズが width / height と一致しません', 'content_bounds' => []];
        }
        $marginBounds = composite_detect_margin_with_fallback($sourceGd, $outW, $outH);
        [$mr, $mg, $mb] = composite_sample_margin_average_rgb_gd($sourceGd, $marginBounds);
        imagedestroy($sourceGd);

        $fullBase = imagecreatetruecolor($outW, $outH);
        if ($fullBase === false) {
            return ['error' => 'キャンバスの作成に失敗しました', 'content_bounds' => []];
        }
        $matte = imagecolorallocate($fullBase, $mr, $mg, $mb);
        if ($matte === false) {
            imagedestroy($fullBase);

            return ['error' => 'キャンバスの初期化に失敗しました', 'content_bounds' => []];
        }
        imagefilledrectangle($fullBase, 0, 0, max(0, $outW - 1), max(0, $outH - 1), $matte);
        imagealphablending($fullBase, true);
        imagesavealpha($fullBase, false);

        $plateIm = composite_image_load($bgPath);
        if ($plateIm === false) {
            imagedestroy($fullBase);

            return ['error' => '背景画像の読み込みに失敗しました', 'content_bounds' => []];
        }
        imagesavealpha($plateIm, true);
        imagealphablending($plateIm, true);
        $pw = imagesx($plateIm);
        $ph = imagesy($plateIm);
        $cbJson = composite_content_bounds_for_json($marginBounds);
        $bx = $marginBounds['button_x'];
        $by = $marginBounds['button_y'];
        $bw = $marginBounds['button_w'];
        $bh = $marginBounds['button_h'];
        imagecopyresampled($fullBase, $plateIm, $bx, $by, 0, 0, $bw, $bh, $pw, $ph);
        imagedestroy($plateIm);
    } else {
        $srcIm = composite_image_load($bgPath);
        if ($srcIm === false) {
            return ['error' => '背景画像の読み込みに失敗しました', 'content_bounds' => []];
        }
        imagesavealpha($srcIm, true);
        imagealphablending($srcIm, true);

        $fullBase = imagecreatetruecolor($outW, $outH);
        if ($fullBase === false) {
            imagedestroy($srcIm);

            return ['error' => 'キャンバスの作成に失敗しました', 'content_bounds' => []];
        }

        $sw = imagesx($srcIm);
        $sh = imagesy($srcIm);
        [$mr, $mg, $mb] = composite_sample_matte_rgb($srcIm);
        $matte = imagecolorallocate($fullBase, $mr, $mg, $mb);
        if ($matte === false) {
            imagedestroy($srcIm);
            imagedestroy($fullBase);

            return ['error' => 'キャンバスの初期化に失敗しました', 'content_bounds' => []];
        }
        imagefilledrectangle($fullBase, 0, 0, $outW, $outH, $matte);
        imagealphablending($fullBase, true);
        imagesavealpha($fullBase, false);
        imagecopyresampled($fullBase, $srcIm, 0, 0, 0, 0, $outW, $outH, $sw, $sh);
        imagedestroy($srcIm);

        $bounds = composite_detect_content_bounds_gd($fullBase);
        $bounds = composite_expand_content_bounds($bounds, $outW, $outH, 1);
        $cbJson = composite_content_bounds_for_json($bounds);
        $bx = $bounds['button_x'];
        $by = $bounds['button_y'];
        $bw = $bounds['button_w'];
        $bh = $bounds['button_h'];
    }

    $work = composite_gd_clone_rect($fullBase, $bx, $by, $bw, $bh);
    if ($work === false) {
        imagedestroy($fullBase);

        return ['error' => '実ボタン領域の切り出しに失敗しました', 'content_bounds' => $cbJson];
    }

    if ($icons !== []) {
        if (!composite_imagick_available()) {
            imagedestroy($work);
            imagedestroy($fullBase);

            return ['error' => 'icons の合成には Imagick が必要です', 'content_bounds' => $cbJson];
        }
        $iconErr = composite_gd_layer_svg_icons($work, $bw, $bh, $icons, $outW, $outH, $bx, $by, $bw, $bh);
        if ($iconErr !== null) {
            imagedestroy($work);
            imagedestroy($fullBase);

            return ['error' => $iconErr, 'content_bounds' => $cbJson];
        }
    }

    imagealphablending($work, true);
    imagesavealpha($work, true);

    $drawR = composite_gd_draw_texts($work, $outW, $outH, $bx, $by, $bw, $bh, $texts, $fontRegularGd, $fontBoldGd);
    $drewText = $drawR['drew'];

    $nonEmpty = 0;
    foreach ($texts as $it) {
        if (is_array($it) && trim((string) ($it['content'] ?? '')) !== '') {
            ++$nonEmpty;
        }
    }
    if ($nonEmpty > 0 && !$drewText) {
        imagedestroy($work);
        imagedestroy($fullBase);

        return ['error' => 'テキストを描画できませんでした（.ttc の face 解決・GD FreeType・bold フォントを確認。中央プリセットの JSON で bold を外すと Regular のみで描画されます）', 'content_bounds' => $cbJson];
    }

    imagealphablending($fullBase, true);
    imagecopy($fullBase, $work, $bx, $by, 0, 0, $bw, $bh);
    imagedestroy($work);

    $outWFinal = $outW;
    $outHFinal = $outH;
    if ($cropToButton && $sourcePath !== null && $borderFill === null
        && $bw > 0 && $bh > 0 && $bx >= 0 && $by >= 0
        && $bx + $bw <= imagesx($fullBase) && $by + $bh <= imagesy($fullBase)) {
        $cropped = imagecreatetruecolor($bw, $bh);
        if ($cropped !== false) {
            imagealphablending($cropped, true);
            imagesavealpha($cropped, false);
            imagecopy($cropped, $fullBase, 0, 0, $bx, $by, $bw, $bh);
            imagedestroy($fullBase);
            $fullBase = $cropped;
            $outWFinal = $bw;
            $outHFinal = $bh;
        }
    }

    if (!imagejpeg($fullBase, $destAbs, 92)) {
        imagedestroy($fullBase);

        return ['error' => '画像の保存に失敗しました', 'content_bounds' => $cbJson];
    }
    imagedestroy($fullBase);

    return [
        'error'          => null,
        'content_bounds' => $cbJson,
        'output_width'   => $outWFinal,
        'output_height'  => $outHFinal,
    ];
}

/**
 * SVG アイコンを Imagick でラスタ化し GD キャンバスへ貼り付け。
 *
 * @param list<array<string, mixed>> $icons
 */
function composite_gd_layer_svg_icons(
    GdImage $target,
    int $canvasW,
    int $canvasH,
    array $icons,
    int $fullW,
    int $fullH,
    int $buttonX,
    int $buttonY,
    int $buttonW,
    int $buttonH
): ?string {
    $cmsRoot = realpath(dirname(__DIR__));
    if ($cmsRoot === false) {
        return 'CMS ルートの解決に失敗しました';
    }
    foreach ($icons as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = isset($item['label']) ? trim((string) $item['label']) : '';
        if ($label === '') {
            continue;
        }
        $svgPath = icon_map_resolve_absolute_path($cmsRoot, $label);
        if ($svgPath === null) {
            return 'アイコン SVG を解決できません: ' . mb_substr($label, 0, 80);
        }
        $xPct = composite_clamp_pct($item['x_pct'] ?? 0.0);
        $yPct = composite_clamp_pct($item['y_pct'] ?? 0.0);
        $wPct = composite_clamp_pct($item['w_pct'] ?? 1.0);
        $hPct = composite_clamp_pct($item['h_pct'] ?? 1.0);
        $mapped = composite_map_full_box_to_inner($fullW, $fullH, $buttonX, $buttonY, $buttonW, $buttonH, $xPct, $yPct, $wPct, $hPct);
        if ($mapped === null) {
            continue;
        }
        [$boxLeft, $boxTop, $boxW, $boxH] = $mapped;

        try {
            $ic = new Imagick();
            $ic->setBackgroundColor(new ImagickPixel('transparent'));
            $maxSide = max($boxW, $boxH, 1);
            $dpi = (int) max(144, min(576, (int) round(96 * ($maxSide / 24) * 2)));
            $ic->setResolution($dpi, $dpi);
            $ic->readImage($svgPath);
            $ic->setIteratorIndex(0);
            $ic->setImageFormat('png32');
            if (defined('Imagick::ALPHACHANNEL_ACTIVATE')) {
                $ic->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            }
            $iw = $ic->getImageWidth();
            $ih = $ic->getImageHeight();
            if ($iw < 1 || $ih < 1) {
                $ic->clear();

                return 'アイコンのラスタサイズが無効です: ' . mb_substr($label, 0, 40);
            }
            $scale = min($boxW / $iw, $boxH / $ih);
            $newW = max(1, (int) round($iw * $scale));
            $newH = max(1, (int) round($ih * $scale));
            $ic->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1, true);
            $blob = $ic->getImageBlob();
            $ic->clear();
            if ($blob === '') {
                return 'アイコン PNG の生成に失敗しました';
            }
            $ov = @imagecreatefromstring($blob);
            if ($ov === false) {
                return 'アイコン画像のデコードに失敗しました';
            }
            imagesavealpha($ov, true);
            imagealphablending($ov, true);
            $xOff = $boxLeft + (int) round(($boxW - $newW) / 2);
            $yOff = $boxTop + (int) round(($boxH - $newH) / 2);
            imagealphablending($target, true);
            imagecopy($target, $ov, $xOff, $yOff, 0, 0, imagesx($ov), imagesy($ov));
            imagedestroy($ov);
        } catch (Throwable $e) {
            return 'Imagick SVG 読込エラー (' . mb_substr($label, 0, 40) . '): ' . mb_substr($e->getMessage(), 0, 220);
        }
    }

    return null;
}

/**
 * icons[] を SVG→ラスタライズして背景 Imagick 上に合成（テキストより先に呼ぶ）。
 * $mapFullW 等を渡すとパーセントは元キャンバス幅・高さ基準で、内側キャンバス座標に変換する。
 *
 * @param list<array<string, mixed>> $icons
 */
function composite_imagick_layer_icons(
    Imagick $img,
    int $canvasW,
    int $canvasH,
    array $icons,
    ?int $mapFullW = null,
    ?int $mapFullH = null,
    ?int $mapBx = null,
    ?int $mapBy = null,
    ?int $mapBw = null,
    ?int $mapBh = null
): ?string {
    $cmsRoot = realpath(dirname(__DIR__));
    if ($cmsRoot === false) {
        return 'CMS ルートの解決に失敗しました';
    }
    $useMap = $mapFullW !== null && $mapFullH !== null && $mapBx !== null && $mapBy !== null && $mapBw !== null && $mapBh !== null;
    foreach ($icons as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = isset($item['label']) ? trim((string) $item['label']) : '';
        if ($label === '') {
            continue;
        }
        $svgPath = icon_map_resolve_absolute_path($cmsRoot, $label);
        if ($svgPath === null) {
            return 'アイコン SVG を解決できません: ' . mb_substr($label, 0, 80);
        }
        $xPct = composite_clamp_pct($item['x_pct'] ?? 0.0);
        $yPct = composite_clamp_pct($item['y_pct'] ?? 0.0);
        $wPct = composite_clamp_pct($item['w_pct'] ?? 1.0);
        $hPct = composite_clamp_pct($item['h_pct'] ?? 1.0);
        if ($useMap) {
            $mapped = composite_map_full_box_to_inner(
                $mapFullW,
                $mapFullH,
                $mapBx,
                $mapBy,
                $mapBw,
                $mapBh,
                $xPct,
                $yPct,
                $wPct,
                $hPct
            );
            if ($mapped === null) {
                continue;
            }
            [$boxLeft, $boxTop, $boxW, $boxH] = $mapped;
        } else {
            $boxLeft = (int) round($canvasW * $xPct);
            $boxTop = (int) round($canvasH * $yPct);
            $boxW = max(1, (int) round($canvasW * $wPct));
            $boxH = max(1, (int) round($canvasH * $hPct));
            $boxLeft = max(0, min($boxLeft, $canvasW - 1));
            $boxTop = max(0, min($boxTop, $canvasH - 1));
            $boxW = min($boxW, $canvasW - $boxLeft);
            $boxH = min($boxH, $canvasH - $boxTop);
        }

        try {
            $ic = new Imagick();
            $ic->setBackgroundColor(new ImagickPixel('transparent'));
            $maxSide = max($boxW, $boxH, 1);
            $dpi = (int) max(144, min(576, (int) round(96 * ($maxSide / 24) * 2)));
            $ic->setResolution($dpi, $dpi);
            $ic->readImage($svgPath);
            $ic->setIteratorIndex(0);
            $ic->setImageFormat('png32');
            if (defined('Imagick::ALPHACHANNEL_ACTIVATE')) {
                $ic->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            }
            $iw = $ic->getImageWidth();
            $ih = $ic->getImageHeight();
            if ($iw < 1 || $ih < 1) {
                $ic->clear();

                return 'アイコンのラスタサイズが無効です: ' . mb_substr($label, 0, 40);
            }
            $scale = min($boxW / $iw, $boxH / $ih);
            $newW = max(1, (int) round($iw * $scale));
            $newH = max(1, (int) round($ih * $scale));
            $ic->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1, true);
            $xOff = $boxLeft + (int) round(($boxW - $newW) / 2);
            $yOff = $boxTop + (int) round(($boxH - $newH) / 2);
            $img->compositeImage($ic, Imagick::COMPOSITE_OVER, $xOff, $yOff);
            $ic->clear();
        } catch (Throwable $e) {
            return 'Imagick SVG 読込エラー (' . mb_substr($label, 0, 40) . '): ' . mb_substr($e->getMessage(), 0, 220);
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $texts
 * @param list<array<string, mixed>> $icons
 *
 * @return array{error: ?string, content_bounds: array<string, int>, output_width?: int, output_height?: int}
 */
function composite_render_imagick(
    string $bgPath,
    int $outW,
    int $outH,
    array $texts,
    array $icons,
    string $destAbs,
    string $fontRegularFile,
    string $fontBoldFile,
    ?string $sourcePath = null,
    ?array $borderFill = null,
    bool $cropToButton = false
): array {
    try {
        if ($borderFill !== null) {
            $borderPx = max(0, (int) ($borderFill['width_px'] ?? 0));
            [$br, $bgc, $bb] = composite_parse_color((string) ($borderFill['color'] ?? '#000000')) ?? [0, 0, 0];
            $bx = $borderPx;
            $by = $borderPx;
            $bw = $outW - 2 * $borderPx;
            $bh = $outH - 2 * $borderPx;
            $marginBounds = [
                'padding_top'    => $borderPx,
                'padding_right'  => $borderPx,
                'padding_bottom' => $borderPx,
                'padding_left'   => $borderPx,
                'button_x'       => $bx,
                'button_y'       => $by,
                'button_w'       => $bw,
                'button_h'       => $bh,
            ];
            $cbJson = composite_content_bounds_for_json($marginBounds);

            $canvas = new Imagick();
            $canvas->newImage($outW, $outH, new ImagickPixel(sprintf('rgb(%d,%d,%d)', $br, $bgc, $bb)));
            $canvas->setImageColorspace(Imagick::COLORSPACE_SRGB);

            $plate = new Imagick($bgPath);
            $plate->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $plate->resizeImage($bw, $bh, Imagick::FILTER_LANCZOS, 1, false);
            $canvas->compositeImage($plate, Imagick::COMPOSITE_OVER, $bx, $by);
            $plate->clear();

            $base = clone $canvas;
            $inner = clone $canvas;
            $canvas->clear();
            $inner->cropImage($bw, $bh, $bx, $by);
        } elseif ($sourcePath !== null) {
            $sourceGd = composite_image_load($sourcePath);
            if ($sourceGd === false) {
                return ['error' => 'source_url の画像を読み込めませんでした', 'content_bounds' => []];
            }
            $sow = imagesx($sourceGd);
            $soh = imagesy($sourceGd);
            if ($sow !== $outW || $soh !== $outH) {
                imagedestroy($sourceGd);

                return ['error' => 'source_url の画像サイズが width / height と一致しません', 'content_bounds' => []];
            }
            $marginBounds = composite_detect_margin_with_fallback($sourceGd, $outW, $outH);
            [$mr, $mg, $mb] = composite_sample_margin_average_rgb_gd($sourceGd, $marginBounds);
            imagedestroy($sourceGd);

            $canvas = new Imagick();
            $canvas->newImage($outW, $outH, new ImagickPixel(sprintf('rgb(%d,%d,%d)', $mr, $mg, $mb)));
            $canvas->setImageColorspace(Imagick::COLORSPACE_SRGB);

            $plate = new Imagick($bgPath);
            $plate->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $cbJson = composite_content_bounds_for_json($marginBounds);
            $bx = $marginBounds['button_x'];
            $by = $marginBounds['button_y'];
            $bw = $marginBounds['button_w'];
            $bh = $marginBounds['button_h'];
            $plate->resizeImage($bw, $bh, Imagick::FILTER_LANCZOS, 1, false);
            $canvas->compositeImage($plate, Imagick::COMPOSITE_OVER, $bx, $by);
            $plate->clear();

            $base = clone $canvas;
            $inner = clone $canvas;
            $canvas->clear();
            $inner->cropImage($bw, $bh, $bx, $by);
        } else {
            $img = new Imagick($bgPath);
            $img->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $img->resizeImage($outW, $outH, Imagick::FILTER_LANCZOS, 1, false);

            $gdProbe = composite_imagick_to_gd_probe($img);
            if ($gdProbe === false) {
                $bounds = [
                    'padding_top'    => 0,
                    'padding_right'  => 0,
                    'padding_bottom' => 0,
                    'padding_left'   => 0,
                    'button_x'       => 0,
                    'button_y'       => 0,
                    'button_w'       => $outW,
                    'button_h'       => $outH,
                ];
            } else {
                $bounds = composite_detect_content_bounds_gd($gdProbe);
                imagedestroy($gdProbe);
            }
            $bounds = composite_expand_content_bounds($bounds, $outW, $outH, 1);
            $cbJson = composite_content_bounds_for_json($bounds);
            $bx = $bounds['button_x'];
            $by = $bounds['button_y'];
            $bw = $bounds['button_w'];
            $bh = $bounds['button_h'];

            $cornerPts = [
                [0, 0],
                [$outW - 1, 0],
                [0, $outH - 1],
                [$outW - 1, $outH - 1],
            ];
            $transparentCorners = 0;
            foreach ($cornerPts as [$sx, $sy]) {
                if ($sx < 0 || $sy < 0 || $sx >= $outW || $sy >= $outH) {
                    continue;
                }
                $p = $img->getImagePixelColor($sx, $sy);
                $a = $p->getColorValue(Imagick::COLOR_ALPHA);
                if ($a < 0.92) {
                    ++$transparentCorners;
                }
            }
            if ($transparentCorners >= 2) {
                $img->setImageBackgroundColor(new ImagickPixel('white'));
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                    $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                }
            } else {
                $mattePx = null;
                $samplePts = [
                    [(int) ($outW / 2), (int) ($outH / 2)],
                    [(int) ($outW / 2), (int) ($outH / 3)],
                    [(int) ($outW / 3), (int) ($outH / 2)],
                    [(int) (2 * $outW / 3), (int) ($outH / 2)],
                ];
                foreach ($samplePts as [$sx, $sy]) {
                    if ($sx < 0 || $sy < 0 || $sx >= $outW || $sy >= $outH) {
                        continue;
                    }
                    $p = $img->getImagePixelColor($sx, $sy);
                    $a = $p->getColorValue(Imagick::COLOR_ALPHA);
                    if ($a < 0.85) {
                        continue;
                    }
                    $mattePx = $p;
                    break;
                }
                if ($mattePx !== null) {
                    $img->setImageBackgroundColor($mattePx);
                    if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                        $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                    }
                }
            }

            $base = clone $img;
            $inner = clone $img;
            $img->clear();
            $inner->cropImage($bw, $bh, $bx, $by);
        }

        if ($icons !== []) {
            $iconErr = composite_imagick_layer_icons($inner, $bw, $bh, $icons, $outW, $outH, $bx, $by, $bw, $bh);
            if ($iconErr !== null) {
                $base->clear();
                $inner->clear();

                return ['error' => $iconErr, 'content_bounds' => $cbJson];
            }
        }

        foreach ($texts as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = isset($item['content']) ? (string) $item['content'] : '';
            if ($content === '') {
                continue;
            }
            $xPct = composite_clamp_pct($item['x_pct'] ?? 0.0);
            $yPct = composite_clamp_pct($item['y_pct'] ?? 0.0);
            $wPct = composite_clamp_pct($item['w_pct'] ?? 1.0);
            $hPct = composite_clamp_pct($item['h_pct'] ?? 1.0);
            $fsPct = isset($item['font_size_pct']) ? (float) $item['font_size_pct'] : 0.3;
            $fsPct = max(0.05, min($fsPct, 1.0));
            $bold = !empty($item['bold']);
            $colorStr = isset($item['color']) ? trim((string) $item['color']) : '#ffffff';
            $rgb = composite_parse_color($colorStr) ?? [255, 255, 255];

            $mapped = composite_map_full_box_to_inner($outW, $outH, $bx, $by, $bw, $bh, $xPct, $yPct, $wPct, $hPct);
            if ($mapped === null) {
                continue;
            }
            [$boxLeft, $boxTop, $boxW, $boxH] = $mapped;

            $fontPath = $bold && $fontBoldFile !== '' ? $fontBoldFile : ($fontRegularFile !== '' ? $fontRegularFile : $fontBoldFile);
            if ($fontPath === '') {
                continue;
            }

            $fontPx = max(6, (int) round($boxH * $fsPct * 0.76));
            $fontPx = min($fontPx, max(6, $boxH - 2));
            $maxTh = max(6, (int) round($boxH * 0.82));

            $draw = new ImagickDraw();
            $draw->setFont($fontPath);
            $draw->setTextAntialias(true);
            $draw->setFontSize((float) $fontPx);
            $draw->setFillColor(new ImagickPixel(sprintf('rgb(%d,%d,%d)', $rgb[0], $rgb[1], $rgb[2])));

            $metrics = $inner->queryFontMetrics($draw, $content);
            $tw = (float) ($metrics['textWidth'] ?? 0);
            $th = (float) ($metrics['textHeight'] ?? 0);
            while (($tw > $boxW || $th > $maxTh) && $fontPx > 6) {
                --$fontPx;
                $draw->setFontSize((float) $fontPx);
                $metrics = $inner->queryFontMetrics($draw, $content);
                $tw = (float) ($metrics['textWidth'] ?? 0);
                $th = (float) ($metrics['textHeight'] ?? 0);
            }

            $asc = (float) ($metrics['ascender'] ?? 0);
            $xDraw = (int) round($boxLeft + ($boxW - $tw) / 2);
            $yDraw = (int) round($boxTop + ($boxH - $th) / 2 + $asc);
            $xDraw = max($boxLeft, min($xDraw, $boxLeft + max(0, $boxW - (int) $tw)));

            if ($bold && $fontBoldFile === '' && $fontRegularFile !== '') {
                $inner->annotateImage($draw, $xDraw + 1, $yDraw, 0, $content);
            }
            $inner->annotateImage($draw, $xDraw, $yDraw, 0, $content);
        }

        $base->compositeImage($inner, Imagick::COMPOSITE_OVER, $bx, $by);
        $inner->clear();

        $outWFinal = $outW;
        $outHFinal = $outH;
        if ($cropToButton && $sourcePath !== null && $borderFill === null
            && $bw > 0 && $bh > 0 && $bx >= 0 && $by >= 0) {
            $base->cropImage($bw, $bh, $bx, $by);
            $outWFinal = $bw;
            $outHFinal = $bh;
        }

        $base->setImageFormat('jpeg');
        $base->setImageCompressionQuality(92);
        if (!$base->writeImage($destAbs)) {
            $base->clear();

            return ['error' => '画像の保存に失敗しました', 'content_bounds' => $cbJson];
        }
        $base->clear();
    } catch (Throwable $e) {
        return ['error' => 'Imagick 処理エラー: ' . mb_substr($e->getMessage(), 0, 400), 'content_bounds' => []];
    }

    return [
        'error'          => null,
        'content_bounds' => $cbJson,
        'output_width'   => $outWFinal,
        'output_height'  => $outHFinal,
    ];
}

/**
 * @return non-falsy-string|null
 */
function composite_resolve_background_path(string $url): ?string
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

/**
 * @return GdImage|false
 */
function composite_image_load(string $path)
{
    $info = @getimagesize($path);
    if ($info === false) {
        return false;
    }
    $t = $info[2] ?? 0;
    return match ($t) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_PNG => imagecreatefrompng($path),
        IMAGETYPE_GIF => imagecreatefromgif($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        default => false,
    };
}
