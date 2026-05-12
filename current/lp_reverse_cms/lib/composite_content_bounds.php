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

/**
 * ボタン矩形の左上コーナーをスキャンして border-radius を近似取得する。
 * x方向・y方向それぞれで「最初の非パディング画素」までの距離の小さい方を返す。
 */
function composite_detect_border_radius_gd(
    GdImage $im,
    int $bx,
    int $by,
    int $bw,
    int $bh
): int {
    $maxScan = min(50, (int) ($bw / 2), (int) ($bh / 2));
    if ($maxScan < 1) {
        return 0;
    }

    $rx = $maxScan;
    for ($x = $bx; $x < $bx + $maxScan; $x++) {
        if (!composite_is_padding_pixel_gd($im, $x, $by)) {
            $rx = $x - $bx;
            break;
        }
    }

    $ry = $maxScan;
    for ($y = $by; $y < $by + $maxScan; $y++) {
        if (!composite_is_padding_pixel_gd($im, $bx, $y)) {
            $ry = $y - $by;
            break;
        }
    }

    $radius = min($rx, $ry);

    return max(0, min($radius, (int) (min($bw, $bh) / 2)));
}

/**
 * 点 ($x, $y) が角丸矩形の内側にあるかどうかを返す。
 * 矩形: ($rx, $ry) を左上とする $rw × $rh。角丸半径 $radius。
 */
function composite_point_in_rounded_rect(
    int $x,
    int $y,
    int $rx,
    int $ry,
    int $rw,
    int $rh,
    int $radius
): bool {
    $lx = $x - $rx;
    $ly = $y - $ry;

    if ($lx < 0 || $ly < 0 || $lx >= $rw || $ly >= $rh) {
        return false;
    }
    if ($radius <= 0) {
        return true;
    }

    $inCorner = false;
    $nearLeft   = $lx < $radius;
    $nearRight  = $lx >= $rw - $radius;
    $nearTop    = $ly < $radius;
    $nearBottom = $ly >= $rh - $radius;

    if (($nearLeft || $nearRight) && ($nearTop || $nearBottom)) {
        $inCorner = true;
        $cx = $nearLeft ? $radius : $rw - $radius;
        $cy = $nearTop ? $radius : $rh - $radius;
        $dx = $lx - $cx;
        $dy = $ly - $cy;

        return ($dx * $dx + $dy * $dy) <= ($radius * $radius);
    }

    return !$inCorner;
}

/** 
 * 行・列は中央50%ストリップのみ走査。ストリップ内「80%以上が余白」ならパディング行／列とみなす（JPEG 滲み耐性）。
 */
function composite_row_has_content_gd(GdImage $im, int $w, int $y): bool
{
    $x0 = (int) ($w * 0.25);
    $x1 = (int) ($w * 0.75);
    $x1 = max($x1, $x0 + 1);
    $total   = $x1 - $x0;
    $padding = 0;
    for ($x = $x0; $x < $x1; $x++) {
        if (composite_is_padding_pixel_gd($im, $x, $y)) {
            ++$padding;
        }
    }

    return ($padding / $total) < 0.80;
}

function composite_col_has_content_gd(GdImage $im, int $x, int $y0, int $y1): bool
{
    $h  = $y1 - $y0 + 1;
    $ys = $y0 + (int) ($h * 0.25);
    $ye = $y0 + (int) ($h * 0.75);
    $ye = max($ye, $ys + 1);
    $ys = max($ys, $y0);
    $ye = min($ye, $y1);
    if ($ys > $ye) {
        $ys = $y0;
        $ye = $y1;
    }
    $total   = $ye - $ys + 1;
    $padding = 0;
    for ($y = $ys; $y <= $ye; $y++) {
        if (composite_is_padding_pixel_gd($im, $x, $y)) {
            ++$padding;
        }
    }

    return ($padding / $total) < 0.80;
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
 * @return array{padding_top: int, padding_right: int, padding_bottom: int, padding_left: int, button_w: int, button_h: int, border_radius?: int}
 */
function composite_content_bounds_for_json(array $b): array
{
    $out = [
        'padding_top'    => (int) $b['padding_top'],
        'padding_right'  => (int) $b['padding_right'],
        'padding_bottom' => (int) $b['padding_bottom'],
        'padding_left'   => (int) $b['padding_left'],
        'button_w'       => (int) $b['button_w'],
        'button_h'       => (int) $b['button_h'],
    ];
    if (array_key_exists('border_radius', $b)) {
        $out['border_radius'] = (int) $b['border_radius'];
    }

    return $out;
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
    $total  = $x1 - $x0;
    $margin = 0;
    for ($x = $x0; $x < $x1; $x++) {
        if (composite_pixel_is_rgb_light_margin_gd($im, $x, $y, $minRgb)) {
            ++$margin;
        }
    }

    return ($margin / $total) < 0.80;
}

function composite_col_has_non_margin_rgb_gd(GdImage $im, int $x, int $y0, int $y1, int $minRgb): bool
{
    $h  = $y1 - $y0 + 1;
    $ys = $y0 + (int) ($h * 0.25);
    $ye = $y0 + (int) ($h * 0.75);
    $ye = max($ye, $ys + 1);
    $ys = max($ys, $y0);
    $ye = min($ye, $y1);
    if ($ys > $ye) {
        $ys = $y0;
        $ye = $y1;
    }
    $total  = $ye - $ys + 1;
    $margin = 0;
    for ($y = $ys; $y <= $ye; $y++) {
        if (composite_pixel_is_rgb_light_margin_gd($im, $x, $y, $minRgb)) {
            ++$margin;
        }
    }

    return ($margin / $total) < 0.80;
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
 * padding の上下・左右を対称化する。
 * - 片側だけ 0 のときは反対側をコピー（従来どおり）。
 * - 両側とも正で値が違うときは max に揃える（JPEG 滲み等で T≠B だけ検出されるケース）。
 *
 * button_w / button_h も補完後の padding に合わせて再計算する。
 *
 * @param array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int} $bounds composite_detect_*_bounds_gd が返す配列
 *
 * @return array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int}
 */
function composite_symmetry_fallback(array $bounds, int $imgW, int $imgH): array
{
    $pt = (int) $bounds['padding_top'];
    $pb = (int) $bounds['padding_bottom'];
    if ($pt > 0 && $pb === 0) {
        $bounds['padding_bottom'] = $pt;
    } elseif ($pb > 0 && $pt === 0) {
        $bounds['padding_top'] = $pb;
    } elseif ($pt !== $pb) {
        $pv = max($pt, $pb);
        $capV = (int) ($imgH / 2);
        if ($pv > $capV) {
            $pv = $capV;
        }
        $bounds['padding_top'] = $pv;
        $bounds['padding_bottom'] = $pv;
    }

    $pl = (int) $bounds['padding_left'];
    $pr = (int) $bounds['padding_right'];
    if ($pl > 0 && $pr === 0) {
        $bounds['padding_right'] = $pl;
    } elseif ($pr > 0 && $pl === 0) {
        $bounds['padding_left'] = $pr;
    } elseif ($pl !== $pr) {
        $ph = max($pl, $pr);
        $capH = (int) ($imgW / 2);
        if ($ph > $capH) {
            $ph = $capH;
        }
        $bounds['padding_left'] = $ph;
        $bounds['padding_right'] = $ph;
    }

    $bounds['button_x'] = (int) $bounds['padding_left'];
    $bounds['button_y'] = (int) $bounds['padding_top'];
    $bounds['button_w'] = $imgW - (int) $bounds['padding_left'] - (int) $bounds['padding_right'];
    $bounds['button_h'] = $imgH - (int) $bounds['padding_top'] - (int) $bounds['padding_bottom'];

    $bounds['button_w'] = max(1, $bounds['button_w']);
    $bounds['button_h'] = max(1, $bounds['button_h']);

    return $bounds;
}

/**
 * source 画像から余白を検出する（image_composite の source_url 経路と同一）。
 * グレー帯 → 白/透過 の順で試み、どちらも検出できなければ全面ボタンを返す。
 * ①② いずれかで余白が取れた場合は composite_symmetry_fallback で上下余白を揃える。
 *
 * @return array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int}
 */
function composite_detect_margin_with_fallback(GdImage $sourceGd, int $outW, int $outH): array
{
    $bounds = composite_detect_rgb_light_margin_bounds_gd($sourceGd, 200);
    $totalPad = $bounds['padding_top'] + $bounds['padding_right']
        + $bounds['padding_bottom'] + $bounds['padding_left'];

    if ($totalPad > 0) {
        return composite_symmetry_fallback($bounds, $outW, $outH);
    }

    $bounds = composite_detect_content_bounds_gd($sourceGd);
    $totalPad = $bounds['padding_top'] + $bounds['padding_right']
        + $bounds['padding_bottom'] + $bounds['padding_left'];

    if ($totalPad > 0) {
        return composite_symmetry_fallback($bounds, $outW, $outH);
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
