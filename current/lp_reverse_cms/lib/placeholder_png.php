<?php

declare(strict_types=1);

/**
 * 置換不可時にサイズ等を示すプレースホルダ PNG を ai_images に保存する。
 */
require_once __DIR__ . '/LpWorkspace.php';

/**
 * @param list<string> $extraLines 追加で中央付近に描く短い行（最大3行まで使う）
 * @return string 公開 URL パス（/output/ws_.../ai_images/...）
 */
function lp_reverse_save_placeholder_png(
    string $cmsRoot,
    int $w,
    int $h,
    string $title = 'プレースホルダ',
    array $extraLines = [],
): string {
    $w = max(64, min(4096, $w));
    $h = max(64, min(4096, $h));

    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('GD 拡張が必要です');
    }

    $im = imagecreatetruecolor($w, $h);
    if ($im === false) {
        throw new RuntimeException('キャンバス作成に失敗しました');
    }

    $bg = imagecolorallocate($im, 45, 49, 52);
    $fg = imagecolorallocate($im, 230, 232, 235);
    $muted = imagecolorallocate($im, 150, 156, 162);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $bg);

    $lines = array_filter(array_merge(
        [$title, $w . ' × ' . $h . ' px'],
        array_slice($extraLines, 0, 4)
    ), static fn (string $s): bool => $s !== '');

    $font  = 5;
    $fontW = imagefontwidth($font);
    $lineH = imagefontheight($font) + 4;
    $maxCols = max(1, (int) floor($w * 0.88 / $fontW)); // 幅の88%に収まる最大文字数

    // タイトル行（$lines[0] = $title）が収まらない場合は切り詰め or 非表示
    $renderLines = [];
    foreach ($lines as $i => $line) {
        $text = mb_substr($line, 0, 80);
        if ($i === 0 && strlen($text) > $maxCols) {
            // 切り詰め後が3文字以上なら「...」付きで表示、それ未満は非表示
            if ($maxCols >= 4) {
                $text = substr($text, 0, $maxCols - 3) . '...';
            } else {
                continue; // 表示しない
            }
        }
        $renderLines[] = ['text' => $text, 'col' => $i === 0 ? $fg : $muted];
    }

    $totalH = count($renderLines) * $lineH;
    $y0 = (int) max(8, ($h - $totalH) / 2);

    foreach ($renderLines as $i => $row) {
        $tw = $fontW * strlen($row['text']);
        $x  = (int) max(8, ($w - $tw) / 2);
        $y  = $y0 + $i * $lineH;
        imagestring($im, $font, $x, $y, $row['text'], $row['col']);
    }

    $aiDir = LpWorkspace::outputDir($cmsRoot) . 'ai_images';
    if (!is_dir($aiDir) && !@mkdir($aiDir, 0755, true)) {
        throw new RuntimeException('output/ai_images を作成できません');
    }

    $fname = 'placeholder_' . bin2hex(random_bytes(8)) . '.png';
    $dest = $aiDir . DIRECTORY_SEPARATOR . $fname;
    if (!imagepng($im, $dest, 6)) {
        imagedestroy($im);
        throw new RuntimeException('PNG の保存に失敗しました');
    }
    imagedestroy($im);

    return LpWorkspace::outputWebAbsPrefix() . 'ai_images/' . $fname;
}
