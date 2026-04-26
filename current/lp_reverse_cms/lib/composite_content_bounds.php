<?php

declare(strict_types=1);

/**
 * 画像の非コンテンツ領域（白 RGB≥245 または透過）をスキャンし、実コンテンツの矩形を求める。
 * image_composite.php と image_content_bounds.php で共用。
 */

/**
 * 白系（RGB≥245）または GD 透過ピクセルを余白とみなす。
 */
function composite_is_padding_pixel_gd(GdImage $im, int $x, int $y): bool
{
    if ($x < 0 || $y < 0 || $x >= imagesx($im) || $y >= imagesy($im)) {
        return true;
    }
    $ci = imagecolorat($im, $x, $y);
    if (imageistruecolor($im)) {
        $a = ($ci >> 24) & 127;
        if ($a >= 126) {
            return true;
        }
        $r = ($ci >> 16) & 0xFF;
        $g = ($ci >> 8) & 0xFF;
        $b = $ci & 0xFF;

        return $r >= 245 && $g >= 245 && $b >= 245;
    }
    $cols = @imagecolorsforindex($im, $ci);
    if (!is_array($cols)) {
        return false;
    }
    $a = (int) ($cols['alpha'] ?? 0);
    if ($a >= 126) {
        return true;
    }
    $r = (int) ($cols['red'] ?? 0);
    $g = (int) ($cols['green'] ?? 0);
    $b = (int) ($cols['blue'] ?? 0);

    return $r >= 245 && $g >= 245 && $b >= 245;
}

/** 行・列は中央50%ストリップのみ走査（JPEG 端のリンギングを無視） */
function composite_row_has_content_gd(GdImage $im, int $w, int $y): bool
{
    $x0 = (int) ($w * 0.25);
    $x1 = (int) ($w * 0.75);
    $x1 = max($x1, $x0 + 1);
    for ($x = $x0; $x < $x1; $x++) {
        if (!composite_is_padding_pixel_gd($im, $x, $y)) {
            return true;
        }
    }

    return false;
}

function composite_col_has_content_gd(GdImage $im, int $x, int $y0, int $y1): bool
{
    $h = $y1 - $y0 + 1;
    $ys = $y0 + (int) ($h * 0.25);
    $ye = $y0 + (int) ($h * 0.75);
    $ye = max($ye, $ys + 1);
    $ys = max($y0, $ys);
    $ye = min($y1, $ye);
    if ($ys > $ye) {
        $ys = $y0;
        $ye = $y1;
    }
    for ($y = $ys; $y <= $ye; $y++) {
        if (!composite_is_padding_pixel_gd($im, $x, $y)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{
 *   padding_top: int,
 *   padding_right: int,
 *   padding_bottom: int,
 *   padding_left: int,
 *   button_x: int,
 *   button_y: int,
 *   button_w: int,
 *   button_h: int
 * }
 */
function composite_detect_content_bounds_gd(GdImage $im): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 1 || $h < 1) {
        return [
            'padding_top'    => 0,
            'padding_right'  => 0,
            'padding_bottom' => 0,
            'padding_left'   => 0,
            'button_x'       => 0,
            'button_y'       => 0,
            'button_w'       => max(1, $w),
            'button_h'       => max(1, $h),
        ];
    }

    $top = 0;
    while ($top < $h && !composite_row_has_content_gd($im, $w, $top)) {
        ++$top;
    }
    if ($top >= $h) {
        return [
            'padding_top'    => 0,
            'padding_right'  => 0,
            'padding_bottom' => 0,
            'padding_left'   => 0,
            'button_x'       => 0,
            'button_y'       => 0,
            'button_w'       => $w,
            'button_h'       => $h,
        ];
    }

    $bottom = $h - 1;
    while ($bottom > $top && !composite_row_has_content_gd($im, $w, $bottom)) {
        --$bottom;
    }

    $left = 0;
    while ($left < $w && !composite_col_has_content_gd($im, $left, $top, $bottom)) {
        ++$left;
    }

    $right = $w - 1;
    while ($right > $left && !composite_col_has_content_gd($im, $right, $top, $bottom)) {
        --$right;
    }

    $button_x = $left;
    $button_y = $top;
    $button_w = max(1, $right - $left + 1);
    $button_h = max(1, $bottom - $top + 1);

    return [
        'padding_top'    => $top,
        'padding_right'  => $w - 1 - $right,
        'padding_bottom' => $h - 1 - $bottom,
        'padding_left'   => $left,
        'button_x'       => $button_x,
        'button_y'       => $button_y,
        'button_w'       => $button_w,
        'button_h'       => $button_h,
    ];
}

/**
 * API 応答用（button_x/y は含めない）。
 *
 * @param array<string, int> $b
 *
 * @return array{padding_top: int, padding_right: int, padding_bottom: int, padding_left: int, button_w: int, button_h: int}
 */
function composite_content_bounds_for_json(array $b): array
{
    return [
        'padding_top'    => (int) $b['padding_top'],
        'padding_right'  => (int) $b['padding_right'],
        'padding_bottom' => (int) $b['padding_bottom'],
        'padding_left'   => (int) $b['padding_left'],
        'button_w'       => (int) $b['button_w'],
        'button_h'       => (int) $b['button_h'],
    ];
}

/**
 * 元画像の「明るい余白」（グレー帯など）検出用。
 * 各チャンネルが minRgb 以上かつ「ほぼ白」でない（min(R,G,B) < nearWhiteMin）なら余白。
 * 白（255 付近）の内側バッファは内側領域に含め、ボタン全面塗りを防ぐ。
 * 透過ピクセルは余白。
 */
function composite_pixel_is_rgb_light_margin_gd(GdImage $im, int $x, int $y, int $minRgb, int $nearWhiteMin = 250): bool
{
    if ($x < 0 || $y < 0 || $x >= imagesx($im) || $y >= imagesy($im)) {
        return true;
    }
    $ci = imagecolorat($im, $x, $y);
    if (imageistruecolor($im)) {
        $a = ($ci >> 24) & 127;
        if ($a >= 126) {
            return true;
        }
        $r = ($ci >> 16) & 0xFF;
        $g = ($ci >> 8) & 0xFF;
        $b = $ci & 0xFF;
        if ($r < $minRgb || $g < $minRgb || $b < $minRgb) {
            return false;
        }
        $mn = min($r, $g, $b);

        return $mn < $nearWhiteMin;
    }
    $cols = @imagecolorsforindex($im, $ci);
    if (!is_array($cols)) {
        return false;
    }
    $a = (int) ($cols['alpha'] ?? 0);
    if ($a >= 126) {
        return true;
    }
    $r = (int) ($cols['red'] ?? 0);
    $g = (int) ($cols['green'] ?? 0);
    $b = (int) ($cols['blue'] ?? 0);
    if ($r < $minRgb || $g < $minRgb || $b < $minRgb) {
        return false;
    }
    $mn = min($r, $g, $b);

    return $mn < $nearWhiteMin;
}

function composite_row_has_non_margin_rgb_gd(GdImage $im, int $w, int $y, int $minRgb): bool
{
    $x0 = (int) ($w * 0.25);
    $x1 = (int) ($w * 0.75);
    $x1 = max($x1, $x0 + 1);
    for ($x = $x0; $x < $x1; $x++) {
        if (!composite_pixel_is_rgb_light_margin_gd($im, $x, $y, $minRgb)) {
            return true;
        }
    }

    return false;
}

function composite_col_has_non_margin_rgb_gd(GdImage $im, int $x, int $y0, int $y1, int $minRgb): bool
{
    $h = $y1 - $y0 + 1;
    $ys = $y0 + (int) ($h * 0.25);
    $ye = $y0 + (int) ($h * 0.75);
    $ye = max($ye, $ys + 1);
    $ys = max($y0, $ys);
    $ye = min($y1, $ye);
    if ($ys > $ye) {
        $ys = $y0;
        $ye = $y1;
    }
    for ($y = $ys; $y <= $ye; $y++) {
        if (!composite_pixel_is_rgb_light_margin_gd($im, $x, $y, $minRgb)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{
 *   padding_top: int, padding_right: int, padding_bottom: int, padding_left: int,
 *   button_x: int, button_y: int, button_w: int, button_h: int
 * }
 */
function composite_detect_rgb_light_margin_bounds_gd(GdImage $im, int $minRgb = 200): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 1 || $h < 1) {
        return [
            'padding_top'    => 0,
            'padding_right'  => 0,
            'padding_bottom' => 0,
            'padding_left'   => 0,
            'button_x'       => 0,
            'button_y'       => 0,
            'button_w'       => max(1, $w),
            'button_h'       => max(1, $h),
        ];
    }

    $top = 0;
    while ($top < $h && !composite_row_has_non_margin_rgb_gd($im, $w, $top, $minRgb)) {
        ++$top;
    }
    if ($top >= $h) {
        return [
            'padding_top'    => 0,
            'padding_right'  => 0,
            'padding_bottom' => 0,
            'padding_left'   => 0,
            'button_x'       => 0,
            'button_y'       => 0,
            'button_w'       => $w,
            'button_h'       => $h,
        ];
    }

    $bottom = $h - 1;
    while ($bottom > $top && !composite_row_has_non_margin_rgb_gd($im, $w, $bottom, $minRgb)) {
        --$bottom;
    }

    $left = 0;
    while ($left < $w && !composite_col_has_non_margin_rgb_gd($im, $left, $top, $bottom, $minRgb)) {
        ++$left;
    }

    $right = $w - 1;
    while ($right > $left && !composite_col_has_non_margin_rgb_gd($im, $right, $top, $bottom, $minRgb)) {
        --$right;
    }

    $button_x = $left;
    $button_y = $top;
    $button_w = max(1, $right - $left + 1);
    $button_h = max(1, $bottom - $top + 1);

    return [
        'padding_top'    => $top,
        'padding_right'  => $w - 1 - $right,
        'padding_bottom' => $h - 1 - $bottom,
        'padding_left'   => $left,
        'button_x'       => $button_x,
        'button_y'       => $button_y,
        'button_w'       => $button_w,
        'button_h'       => $button_h,
    ];
}

/**
 * 余白帯の画素から平均 RGB（塗りつぶし用）。
 *
 * @param array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int} $b
 *
 * @return array{0:int,1:int,2:int}
 */
function composite_sample_margin_average_rgb_gd(GdImage $im, array $b): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    $pt = (int) $b['padding_top'];
    $pr = (int) $b['padding_right'];
    $pb = (int) $b['padding_bottom'];
    $pl = (int) $b['padding_left'];
    $bx = (int) $b['button_x'];
    $by = (int) $b['button_y'];
    $bw = (int) $b['button_w'];
    $bh = (int) $b['button_h'];

    $sumR = 0;
    $sumG = 0;
    $sumB = 0;
    $n = 0;

    $addPx = static function (GdImage $im, int $x, int $y) use (&$sumR, &$sumG, &$sumB, &$n): void {
        if ($x < 0 || $y < 0 || $x >= imagesx($im) || $y >= imagesy($im)) {
            return;
        }
        $ci = imagecolorat($im, $x, $y);
        if (imageistruecolor($im)) {
            $a = ($ci >> 24) & 127;
            if ($a >= 126) {
                return;
            }
            $sumR += ($ci >> 16) & 0xFF;
            $sumG += ($ci >> 8) & 0xFF;
            $sumB += $ci & 0xFF;
        } else {
            $cols = @imagecolorsforindex($im, $ci);
            if (!is_array($cols) || (int) ($cols['alpha'] ?? 0) >= 126) {
                return;
            }
            $sumR += (int) ($cols['red'] ?? 0);
            $sumG += (int) ($cols['green'] ?? 0);
            $sumB += (int) ($cols['blue'] ?? 0);
        }
        ++$n;
    };

    for ($y = 0; $y < $pt && $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $addPx($im, $x, $y);
        }
    }
    for ($y = $by + $bh; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $addPx($im, $x, $y);
        }
    }
    for ($y = $by; $y < $by + $bh && $y < $h; $y++) {
        for ($x = 0; $x < $pl; $x++) {
            $addPx($im, $x, $y);
        }
        for ($x = $bx + $bw; $x < $w; $x++) {
            $addPx($im, $x, $y);
        }
    }

    if ($n === 0) {
        return [200, 200, 200];
    }

    return [
        (int) round($sumR / $n),
        (int) round($sumG / $n),
        (int) round($sumB / $n),
    ];
}

/**
 * 検出矩形が縁のアンチエイリアス1pxを欠くと、貼り戻しでマット色が縦に覗くことがある。画像端までクランプして拡張する。
 *
 * @param array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int} $b
 *
 * @return array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int}
 */
/**
 * source 画像から余白を検出する（image_composite の source_url 経路と同一）。
 * グレー帯 → 白/透過 の順で試み、どちらも検出できなければ全面ボタンを返す。
 *
 * @return array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int}
 */
function composite_detect_margin_with_fallback(GdImage $sourceGd, int $outW, int $outH): array
{
    $bounds = composite_detect_rgb_light_margin_bounds_gd($sourceGd, 200);
    $totalPad = $bounds['padding_top'] + $bounds['padding_right']
        + $bounds['padding_bottom'] + $bounds['padding_left'];

    if ($totalPad > 0) {
        return $bounds;
    }

    $bounds = composite_detect_content_bounds_gd($sourceGd);
    $totalPad = $bounds['padding_top'] + $bounds['padding_right']
        + $bounds['padding_bottom'] + $bounds['padding_left'];

    if ($totalPad > 0) {
        return $bounds;
    }

    return [
        'padding_top'    => 0,
        'padding_right'  => 0,
        'padding_bottom' => 0,
        'padding_left'   => 0,
        'button_x'       => 0,
        'button_y'       => 0,
        'button_w'       => $outW,
        'button_h'       => $outH,
    ];
}

function composite_expand_content_bounds(array $b, int $maxW, int $maxH, int $px): array
{
    if ($px < 1 || $maxW < 1 || $maxH < 1) {
        return $b;
    }
    $x0 = (int) $b['button_x'];
    $y0 = (int) $b['button_y'];
    $w = (int) $b['button_w'];
    $h = (int) $b['button_h'];
    $x0n = max(0, $x0 - $px);
    $y0n = max(0, $y0 - $px);
    $x1 = min($maxW - 1, $x0 + $w - 1 + $px);
    $y1 = min($maxH - 1, $y0 + $h - 1 + $px);
    $wn = max(1, $x1 - $x0n + 1);
    $hn = max(1, $y1 - $y0n + 1);

    return [
        'padding_top'    => $y0n,
        'padding_right'  => $maxW - 1 - $x1,
        'padding_bottom' => $maxH - 1 - $y1,
        'padding_left'   => $x0n,
        'button_x'       => $x0n,
        'button_y'       => $y0n,
        'button_w'       => $wn,
        'button_h'       => $hn,
    ];
}
