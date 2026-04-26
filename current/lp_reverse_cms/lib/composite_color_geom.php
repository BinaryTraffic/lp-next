<?php

declare(strict_types=1);

/**
 * 入力座標は元キャンバス outW×outH に対する比率。戻りは内側キャンバス上の矩形（交差なしなら null）。
 *
 * @return array{0: int, 1: int, 2: int, 3: int}|null boxLeft, boxTop, boxW, boxH
 */
function composite_map_full_box_to_inner(
    int $outW,
    int $outH,
    int $buttonX,
    int $buttonY,
    int $buttonW,
    int $buttonH,
    float $xPct,
    float $yPct,
    float $wPct,
    float $hPct
): ?array {
    $fl = (int) round($outW * $xPct);
    $ft = (int) round($outH * $yPct);
    $fw = max(1, (int) round($outW * $wPct));
    $fh = max(1, (int) round($outH * $hPct));
    $fr = $fl + $fw - 1;
    $fb = $ft + $fh - 1;

    $ir0 = $buttonX;
    $it0 = $buttonY;
    $ir1 = $buttonX + $buttonW - 1;
    $ib1 = $buttonY + $buttonH - 1;

    $il = max($fl, $ir0);
    $it = max($ft, $it0);
    $ir = min($fr, $ir1);
    $ib = min($fb, $ib1);
    if ($il > $ir || $it > $ib) {
        return null;
    }

    return [$il - $buttonX, $it - $buttonY, $ir - $il + 1, $ib - $it + 1];
}

/**
 * @return array{0: int, 1: int, 2: int}|null
 */
function composite_parse_color(string $hex): ?array
{
    $hex = trim($hex);
    if (preg_match('/^#([0-9a-f]{6})$/i', $hex, $m)) {
        $s = $m[1];

        return [
            (int) hexdec(substr($s, 0, 2)),
            (int) hexdec(substr($s, 2, 2)),
            (int) hexdec(substr($s, 4, 2)),
        ];
    }
    if (preg_match('/^#([0-9a-f]{3})$/i', $hex, $m)) {
        $s = $m[1];

        return [
            (int) hexdec($s[0] . $s[0]),
            (int) hexdec($s[1] . $s[1]),
            (int) hexdec($s[2] . $s[2]),
        ];
    }

    return null;
}

function composite_clamp_pct(mixed $v): float
{
    $f = is_numeric($v) ? (float) $v : 0.0;
    if ($f < 0.0) {
        return 0.0;
    }
    if ($f > 1.0) {
        return 1.0;
    }

    return $f;
}
