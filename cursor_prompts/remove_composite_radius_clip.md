## Cursor修正依頼：composite の radius クリップを削除

### 問題

現在の `composite_render_gd` は板を貼る際に `composite_point_in_rounded_rect` で
radius=5（JPEG検出値）にクリップしている。
板自身が大きい radius の透過PNG（角丸アルファ付き）の場合、
小さい方の radius に刈り取られてしまい意図しない形状になる。

### 修正方針

composite は「背景色で塗る → 板をアルファブレンドでそのまま貼る」だけにする。
板のアルファチャンネルが形状を定義するため、composite 側で radius クリップは不要。

### 修正箇所

`current/lp_reverse_cms/store/image_composite.php`

#### composite_render_gd の板貼り付け部分を変更

```php
// --- 修正前（ピクセル走査で角丸クリップ） ---
for ($py = 0; $py < $bh; $py++) {
    for ($px = 0; $px < $bw; $px++) {
        if (composite_point_in_rounded_rect($px, $py, 0, 0, $bw, $bh, $radius)) {
            $c = imagecolorat($plateScaled, $px, $py);
            imagesetpixel($output, $bx + $px, $by + $py, $c);
        }
    }
}

// --- 修正後（アルファブレンドでそのまま貼る） ---
imagealphablending($output, true);
imagecopyresampled(
    $output, $plateGd,
    $bx, $by, 0, 0,
    $bw, $bh,
    imagesx($plateGd), imagesy($plateGd)
);
```

- `$plateScaled` の中間バッファは不要になるため削除する
- `composite_detect_border_radius_gd` の呼び出しは **削除しない**
  （`content_bounds.border_radius` のレスポンスキーとして引き続き返す）
- `composite_point_in_rounded_rect` 関数自体も削除しない（将来使用の可能性あり）

#### composite_render_imagick も同様に修正

Imagick 経路の `composite_imagick_apply_scaled_plate_rounded_clip` 相当箇所を、
alpha composite（`Imagick::COMPOSITE_OVER`）のシンプルな貼り付けに変更する。

```php
// 修正後イメージ（実際のコードに合わせること）
$plateMagick = new Imagick($platePath);
$plateMagick->resizeImage($bw, $bh, Imagick::FILTER_LANCZOS, 1);
$imagick->compositeImage($plateMagick, Imagick::COMPOSITE_OVER, $bx, $by);
$plateMagick->destroy();
```

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
- `content_bounds.border_radius` が JSON に含まれていること（値は参考値として保持）
- `output_url` の画像で、板自身の角丸（大きいほうの radius）が反映されていること

---

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
