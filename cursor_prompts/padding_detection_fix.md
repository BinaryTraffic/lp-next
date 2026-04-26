## Cursor修正依頼：source_url 余白検出のフォールバック

### 問題
`source_url` を指定しても `content_bounds` がすべて 0 になるケースがある。

### 原因
`source_url` コードパスは `composite_detect_rgb_light_margin_bounds_gd`（グレー帯専用）しか呼ばない。
余白が**白（R,G,B ≥ 250）**の場合、この検出器は `min(R,G,B) < nearWhiteMin(250)` を満たさないため
余白と判定されず、全面がボタン領域として扱われる。

### 修正方針
`source_url` を読み込んで余白検出するとき、以下の順で試みる。

```
① composite_detect_rgb_light_margin_bounds_gd（グレー帯検出）
    ↓ 4辺すべて padding = 0 だったとき（= 検出できなかった）
② composite_detect_content_bounds_gd（白・透過検出）でリトライ
    ↓ ②でも 4辺すべて 0
③ パディングなし（ボタン = 元画像フル）とみなす
```

### 修正箇所
`current/lp_reverse_cms/store/image_composite.php`

`composite_render_gd` と `composite_render_imagick` の両方に適用する。

#### 共通ヘルパー関数を追加（ファイル末尾）

```php
/**
 * source 画像から余白を検出する。
 * グレー帯 → 白/透過 の順で試み、どちらも検出できなければ全面ボタンを返す。
 *
 * @return array{padding_top:int,padding_right:int,padding_bottom:int,padding_left:int,button_x:int,button_y:int,button_w:int,button_h:int}
 */
function composite_detect_margin_with_fallback(GdImage $sourceGd, int $outW, int $outH): array
{
    // ① グレー帯検出
    $bounds = composite_detect_rgb_light_margin_bounds_gd($sourceGd, 200);
    $totalPad = $bounds['padding_top'] + $bounds['padding_right']
              + $bounds['padding_bottom'] + $bounds['padding_left'];

    if ($totalPad > 0) {
        return $bounds;
    }

    // ② 白・透過検出（フォールバック）
    $bounds = composite_detect_content_bounds_gd($sourceGd);
    $totalPad = $bounds['padding_top'] + $bounds['padding_right']
              + $bounds['padding_bottom'] + $bounds['padding_left'];

    if ($totalPad > 0) {
        return $bounds;
    }

    // ③ 余白なし（全面ボタン）
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
```

#### composite_render_gd の修正

```php
// 修正前
$marginBounds = composite_detect_rgb_light_margin_bounds_gd($sourceGd, 200);
[$mr, $mg, $mb] = composite_sample_margin_average_rgb_gd($sourceGd, $marginBounds);

// 修正後
$marginBounds = composite_detect_margin_with_fallback($sourceGd, $outW, $outH);
[$mr, $mg, $mb] = composite_sample_margin_average_rgb_gd($sourceGd, $marginBounds);
```

#### composite_render_imagick の修正

```php
// 修正前
$marginBounds = composite_detect_rgb_light_margin_bounds_gd($sourceGd, 200);
[$mr, $mg, $mb] = composite_sample_margin_average_rgb_gd($sourceGd, $marginBounds);

// 修正後
$marginBounds = composite_detect_margin_with_fallback($sourceGd, $outW, $outH);
[$mr, $mg, $mb] = composite_sample_margin_average_rgb_gd($sourceGd, $marginBounds);
```

### 実装後の確認（Cursor が自分で実行する）

以下を GCP VM 上で実行し、`content_bounds` の padding 値が 0 以外になることを確認してレポートに含めること。

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

`content_bounds.padding_top` または `padding_bottom` が 1 以上であれば修正成功。

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
