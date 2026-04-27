## Cursor修正依頼：GD再描画方式（角丸クリップ合成）

### 問題（現状）

`source_mask_composite` 方式はJPEGリンギングの遷移帯がそのまま出力に残り、
角丸がぼやけたりギザつく。ピクセル転写ではなくGDで再描画することで解決する。

### 修正方針

ソース画像から「パラメータ」だけ読み取り、クリーンに再描画する。

```
ソース画像から取得:
  - 余白色 (bg_color)     ← composite_sample_margin_average_rgb_gd で取得済み
  - padding 4辺           ← composite_detect_margin_with_fallback で取得済み
  - border_radius         ← ★ 新規追加

描画（再構築）:
  1. bg_color で全面塗りつぶし
  2. 板を button_w × button_h にスケール
  3. 板を「角丸クリップ」でボタン領域に貼る
  4. テキスト・アイコン描画（既存ロジック流用）
  5. JPEG出力
```

---

### Step 1: composite_content_bounds.php に2関数を追加

#### composite_detect_border_radius_gd（新規追加）

ボタン領域の左上コーナーから走査し、角丸半径を近似検出する。

```php
/**
 * ボタン矩形の左上コーナーをスキャンして border-radius を近似取得する。
 * x方向・y方向それぞれで「最初の非パディング画素」までの距離の小さい方を返す。
 * 最大50pxまで走査し、検出できなければ 0 を返す。
 */
function composite_detect_border_radius_gd(
    GdImage $im,
    int $bx, int $by,
    int $bw, int $bh
): int {
    $maxScan = min(50, (int)($bw / 2), (int)($bh / 2));

    // 左上コーナーからx方向スキャン（y = by で右へ）
    $rx = $maxScan;
    for ($x = $bx; $x < $bx + $maxScan; $x++) {
        if (!composite_is_padding_pixel_gd($im, $x, $by)) {
            $rx = $x - $bx;
            break;
        }
    }

    // 左上コーナーからy方向スキャン（x = bx で下へ）
    $ry = $maxScan;
    for ($y = $by; $y < $by + $maxScan; $y++) {
        if (!composite_is_padding_pixel_gd($im, $bx, $y)) {
            $ry = $y - $by;
            break;
        }
    }

    $radius = min($rx, $ry);
    // 最大値はボタン短辺の半分まで
    return max(0, min($radius, (int)(min($bw, $bh) / 2)));
}
```

#### composite_point_in_rounded_rect（新規追加）

点 (x, y) が角丸矩形の内側かどうか判定する。

```php
/**
 * 点 ($x, $y) が角丸矩形の内側にあるかどうかを返す。
 * 矩形: ($rx, $ry) を左上とする $rw × $rh。角丸半径 $radius。
 */
function composite_point_in_rounded_rect(
    int $x, int $y,
    int $rx, int $ry,
    int $rw, int $rh,
    int $radius
): bool {
    $lx = $x - $rx;
    $ly = $y - $ry;

    if ($lx < 0 || $ly < 0 || $lx >= $rw || $ly >= $rh) return false;
    if ($radius <= 0) return true;

    // 4コーナーの円内判定
    $inCorner = false;
    $nearLeft   = $lx < $radius;
    $nearRight  = $lx >= $rw - $radius;
    $nearTop    = $ly < $radius;
    $nearBottom = $ly >= $rh - $radius;

    if (($nearLeft || $nearRight) && ($nearTop || $nearBottom)) {
        $inCorner = true;
        $cx = $nearLeft  ? $radius      : $rw - $radius;
        $cy = $nearTop   ? $radius      : $rh - $radius;
        $dx = $lx - $cx;
        $dy = $ly - $cy;
        return ($dx * $dx + $dy * $dy) <= ($radius * $radius);
    }

    return !$inCorner; // コーナー外 = 常に内側
}
```

---

### Step 2: composite_render_gd を GD再描画方式に変更

`current/lp_reverse_cms/store/image_composite.php` の `composite_render_gd` 内、
「キャンバス作成〜板貼り付け」の部分を以下に置き換える。

```php
// --- マージン・ボタン領域の検出 ---
$marginBounds = composite_detect_margin_with_fallback($sourceGd, $outW, $outH);
[$mr, $mg, $mb] = composite_sample_margin_average_rgb_gd($sourceGd, $marginBounds);

$bx = $marginBounds['button_x'];
$by = $marginBounds['button_y'];
$bw = $marginBounds['button_w'];
$bh = $marginBounds['button_h'];

// --- border_radius 検出 ---
$radius = composite_detect_border_radius_gd($sourceGd, $bx, $by, $bw, $bh);

// --- 出力キャンバス作成 ---
$output = imagecreatetruecolor($outW, $outH);
imagesavealpha($output, true);
imagealphablending($output, false);

// --- 背景色（余白色）で全面塗りつぶし ---
$bgColor = imagecolorallocate($output, $mr, $mg, $mb);
imagefill($output, 0, 0, $bgColor);
imagealphablending($output, true);

// --- 板をボタンサイズにスケーリング ---
$plateScaled = imagecreatetruecolor($bw, $bh);
imagecopyresampled(
    $plateScaled, $plateGd,
    0, 0, 0, 0,
    $bw, $bh,
    imagesx($plateGd), imagesy($plateGd)
);

// --- 板を角丸クリップしてボタン領域に描画 ---
for ($py = 0; $py < $bh; $py++) {
    for ($px = 0; $px < $bw; $px++) {
        if (composite_point_in_rounded_rect($px, $py, 0, 0, $bw, $bh, $radius)) {
            $c = imagecolorat($plateScaled, $px, $py);
            imagesetpixel($output, $bx + $px, $by + $py, $c);
        }
    }
}
imagedestroy($plateScaled);
```

**変数名の注意:**
- `$plateGd` は板画像の GdImage 変数。実際のコードの変数名に合わせること
- `$sourceGd`, `$outW`, `$outH` も同様

---

### Step 3: content_bounds に border_radius を追加

`composite_render_gd` が返す（または呼び出し元が組み立てる）`content_bounds` 配列に
`border_radius` を追加する。

```php
$content_bounds = [
    'padding_top'    => $marginBounds['padding_top'],
    'padding_right'  => $marginBounds['padding_right'],
    'padding_bottom' => $marginBounds['padding_bottom'],
    'padding_left'   => $marginBounds['padding_left'],
    'button_x'       => $bx,
    'button_y'       => $by,
    'button_w'       => $bw,
    'button_h'       => $bh,
    'border_radius'  => $radius,   // ← 追加
];
```

---

### Step 4: composite_render_imagick も同様に修正

Imagick パスが存在する場合:
- `composite_detect_border_radius_gd` で $radius を取得（GdImage を使用）
- Imagick の `drawRoundRectangle` または GD と同様のピクセル走査で板を角丸クリップ
- `imagick_draw` が使える場合: `ImagickDraw::roundRectangle()` で形状を作り、板をクリップして合成

Imagick の実装が複雑になる場合は GD パスと同じピクセル走査で可。

---

### 実装後の確認（Cursor が自分で実行する）

```bash
curl -s -X POST https://lp-next.jitan.app/current/lp_reverse_cms/store/image_composite.php \
  -H 'Content-Type: application/json' \
  -d '{
    "source_url":     "/output/assets/img/btn01.jpg",
    "background_url": "/output/ai_images/plate_02cf2171c0699548.png",
    "width":  329,
    "height": 122,
    "texts": [
      {
        "content":       "無料プラン申込",
        "x_pct": 0.1, "y_pct": 0.2, "w_pct": 0.8, "h_pct": 0.6,
        "font_size_pct": 0.30, "bold": true, "color": "#ffffff"
      }
    ],
    "icons": []
  }'
```

成功条件:
- `content_bounds.border_radius` が 1 以上（角丸が検出されている）
- `content_bounds.padding_top` が 4 以上
- `output_url` の画像で：上下グレー帯あり・ボタン角丸あり・テキストあり

---

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
