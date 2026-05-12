<?php

declare(strict_types=1);

require_once __DIR__ . '/composite_color_geom.php';

/**
 * composite_render_gd / badge_generator と同一の texts[] 描画ロジック。
 *
 * @param list<array<string, mixed>> $texts
 *
 * @return array{drew: bool}
 */
function composite_gd_draw_texts(
    GdImage $work,
    int $outW,
    int $outH,
    int $bx,
    int $by,
    int $bw,
    int $bh,
    array $texts,
    string $fontRegularGd,
    string $fontBoldGd
): array {
    $drewText = false;

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

        $fontFile = $bold && $fontBoldGd !== '' ? $fontBoldGd : ($fontRegularGd !== '' ? $fontRegularGd : $fontBoldGd);
        if ($fontFile === '') {
            continue;
        }
        $fontPx = max(6, (int) round($boxH * $fsPct * 0.76));
        $fontPx = min($fontPx, max(6, $boxH - 2));
        $maxTh = max(6, (int) round($boxH * 0.82));

        $bbox = @imagettfbbox($fontPx, 0, $fontFile, $content);
        if ($bbox === false && $fontFile !== $fontRegularGd && $fontRegularGd !== '') {
            $fontFile = $fontRegularGd;
            $bbox = @imagettfbbox($fontPx, 0, $fontFile, $content);
        }
        if ($bbox === false) {
            continue;
        }
        $tw = (int) abs($bbox[2] - $bbox[0]);
        $th = (int) abs($bbox[7] - $bbox[1]);
        while (($tw > $boxW || $th > $maxTh) && $fontPx > 6) {
            --$fontPx;
            $bbox = @imagettfbbox($fontPx, 0, $fontFile, $content);
            if ($bbox === false) {
                break;
            }
            $tw = (int) abs($bbox[2] - $bbox[0]);
            $th = (int) abs($bbox[7] - $bbox[1]);
        }
        if ($bbox === false) {
            continue;
        }

        $asc = (int) abs($bbox[7]);
        $xDraw = (int) round($boxLeft + ($boxW - $tw) / 2);
        $yDraw = (int) round($boxTop + ($boxH - $th) / 2 + $asc);
        $xDraw = max($boxLeft, min($xDraw, $boxLeft + max(0, $boxW - $tw)));

        $col = imagecolorallocate($work, $rgb[0], $rgb[1], $rgb[2]);
        if ($col === false) {
            continue;
        }
        if ($bold && $fontBoldGd === '' && $fontRegularGd !== '') {
            if (@imagettftext($work, $fontPx, 0, $xDraw + 1, $yDraw, $col, $fontFile, $content) !== false) {
                $drewText = true;
            }
        }
        if (@imagettftext($work, $fontPx, 0, $xDraw, $yDraw, $col, $fontFile, $content) !== false) {
            $drewText = true;
        }
    }

    return ['drew' => $drewText];
}
