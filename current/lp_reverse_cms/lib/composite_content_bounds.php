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

function composite_row_has_content_gd(GdImage $im, int $w, int $y): bool
{
    for ($x = 0; $x < $w; $x++) {
        if (!composite_is_padding_pixel_gd($im, $x, $y)) {
            return true;
        }
    }

    return false;
}

function composite_col_has_content_gd(GdImage $im, int $x, int $y0, int $y1): bool
{
    for ($y = $y0; $y <= $y1; $y++) {
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
 * 検出矩形が縁のアンチエイリアス1pxを欠くと、貼り戻しでマット色が縦に覗くことがある。画像端までクランプして拡張する。
 *
 * @param array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int} $b
 *
 * @return array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int}
 */
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
