## Cursor修正依頼：余白検出を中央ストリップ走査に変更

### 問題
JPEG圧縮のリンギングで、グレー帯の行端（左右端）に非グレー画素が混入する。
現在の実装は「行の全画素が余白色」を条件にしているため、端1pxでも外れると
その行全体をコンテンツ行と判定してしまう。
結果: btn01.jpg の上下グレー帯（実際は約15px）が padding_top: 2 しか検出できない。

### 修正方針
行・列の走査を「全画素」から「中央50%ストリップ」に変更する。
端のJPEGアーチファクトを避け、グレー帯の中央部分だけで判定する。

### 修正箇所
`current/lp_reverse_cms/lib/composite_content_bounds.php`

#### 1. composite_row_has_content_gd を修正

```php
// 修正前
function composite_row_has_content_gd(GdImage $im, int $w, int $y): bool
{
    for ($x = 0; $x < $w; $x++) {
        if (!composite_is_padding_pixel_gd($im, $x, $y)) {
            return true;
        }
    }
    return false;
}

// 修正後（中央50%のみ走査）
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
```

#### 2. composite_col_has_content_gd を修正

```php
// 修正前
function composite_col_has_content_gd(GdImage $im, int $x, int $y0, int $y1): bool
{
    for ($y = $y0; $y <= $y1; $y++) {
        if (!composite_is_padding_pixel_gd($im, $x, $y)) {
            return true;
        }
    }
    return false;
}

// 修正後（中央50%のみ走査）
function composite_col_has_content_gd(GdImage $im, int $x, int $y0, int $y1): bool
{
    $h = $y1 - $y0 + 1;
    $ys = $y0 + (int) ($h * 0.25);
    $ye = $y0 + (int) ($h * 0.75);
    $ye = max($ye, $ys + 1);
    for ($y = $ys; $y <= $ye; $y++) {
        if (!composite_is_padding_pixel_gd($im, $x, $y)) {
            return true;
        }
    }
    return false;
}
```

#### 3. composite_row_has_non_margin_rgb_gd を修正

```php
// 修正前
function composite_row_has_non_margin_rgb_gd(GdImage $im, int $w, int $y, int $minRgb): bool
{
    for ($x = 0; $x < $w; $x++) {
        if (!composite_pixel_is_rgb_light_margin_gd($im, $x, $y, $minRgb)) {
            return true;
        }
    }
    return false;
}

// 修正後（中央50%のみ走査）
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
```

#### 4. composite_col_has_non_margin_rgb_gd を修正

```php
// 修正前
function composite_col_has_non_margin_rgb_gd(GdImage $im, int $x, int $y0, int $y1, int $minRgb): bool
{
    for ($y = $y0; $y <= $y1; $y++) {
        if (!composite_pixel_is_rgb_light_margin_gd($im, $x, $y, $minRgb)) {
            return true;
        }
    }
    return false;
}

// 修正後（中央50%のみ走査）
function composite_col_has_non_margin_rgb_gd(GdImage $im, int $x, int $y0, int $y1, int $minRgb): bool
{
    $h = $y1 - $y0 + 1;
    $ys = $y0 + (int) ($h * 0.25);
    $ye = $y0 + (int) ($h * 0.75);
    $ye = max($ye, $ys + 1);
    for ($y = $ys; $y <= $ye; $y++) {
        if (!composite_pixel_is_rgb_light_margin_gd($im, $x, $y, $minRgb)) {
            return true;
        }
    }
    return false;
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

成功条件: `content_bounds.padding_top` が **10 以上**であること。
（btn01.jpg の上下グレー帯は視覚的に約15pxあるため）

---

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
