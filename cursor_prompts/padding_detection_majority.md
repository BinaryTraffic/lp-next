## Cursor修正依頼：余白検出を多数決判定に変更

### 問題
中央50%ストリップ化で padding_top: 2→6 に改善したが、目標（≥10）に未達。
丸角ボタンのJPEG圧縮は中央ストリップにも滲みが及び、
「ストリップ内の全画素が白」という条件で行がコンテンツ扱いになる。

### 修正方針
「全画素が余白色」→「中央ストリップの80%以上が余白色」に変更する。
1〜2pxのJPEG滲みがあっても行をパディングと判定できる。

### 修正箇所
`current/lp_reverse_cms/lib/composite_content_bounds.php`

#### composite_row_has_content_gd を修正

```php
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
    // 80%以上が余白色なら「コンテンツなし」
    return ($padding / $total) < 0.80;
}
```

#### composite_row_has_non_margin_rgb_gd を修正

```php
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
    // 80%以上がグレー帯色なら「非マージン行ではない」
    return ($margin / $total) < 0.80;
}
```

#### composite_col_has_content_gd を修正

```php
function composite_col_has_content_gd(GdImage $im, int $x, int $y0, int $y1): bool
{
    $h  = $y1 - $y0 + 1;
    $ys = $y0 + (int) ($h * 0.25);
    $ye = $y0 + (int) ($h * 0.75);
    $ye = max($ye, $ys + 1);
    $ys = max($ys, $y0);
    $ye = min($ye, $y1);
    $total   = $ye - $ys + 1;
    $padding = 0;
    for ($y = $ys; $y <= $ye; $y++) {
        if (composite_is_padding_pixel_gd($im, $x, $y)) {
            ++$padding;
        }
    }
    return ($padding / $total) < 0.80;
}
```

#### composite_col_has_non_margin_rgb_gd を修正

```php
function composite_col_has_non_margin_rgb_gd(GdImage $im, int $x, int $y0, int $y1, int $minRgb): bool
{
    $h  = $y1 - $y0 + 1;
    $ys = $y0 + (int) ($h * 0.25);
    $ye = $y0 + (int) ($h * 0.75);
    $ye = max($ye, $ys + 1);
    $ys = max($ys, $y0);
    $ye = min($ye, $y1);
    $total  = $ye - $ys + 1;
    $margin = 0;
    for ($y = $ys; $y <= $ye; $y++) {
        if (composite_pixel_is_rgb_light_margin_gd($im, $x, $y, $minRgb)) {
            ++$margin;
        }
    }
    return ($margin / $total) < 0.80;
}
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
        "x_pct":         0.1,
        "y_pct":         0.2,
        "w_pct":         0.8,
        "h_pct":         0.6,
        "font_size_pct": 0.30,
        "bold":          true,
        "color":         "#ffffff"
      }
    ],
    "icons": []
  }'
```

成功条件: `content_bounds.padding_top` が **4 以上**であること。
（btn01.jpg の中央ストリップ実測値は 6px。角丸コーナー部の目視 ~15px との乖離は正常。）

---

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
