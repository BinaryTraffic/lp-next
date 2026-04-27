## Cursor修正依頼：上下・左右パディングの対称補完

### 問題

btn01.jpg で `padding_bottom: 0` が検出される。
実際には上下対称のグレー帯があるが、下端の JPEG 圧縮特性の違いで検出が失敗している。
結果として合成画像のボタンが縦長（面長）になる。

### 修正方針

標準的な UI ボタンは上下・左右対称であることが多い。
padding が片側のみ 0 の場合、反対側の値で補完する。

### 修正箇所

`current/lp_reverse_cms/lib/composite_content_bounds.php`

既存の検出関数（`composite_detect_content_bounds_gd` または
`composite_detect_rgb_light_margin_bounds_gd`）が返す $bounds を
後処理する関数を追加し、呼び出し元で使用する。

#### 追加関数

```php
/**
 * padding の上下・左右が非対称（片側が 0）の場合に対称補完する。
 * 標準的な UI ボタンは対称であることが多いため、
 * 片側が 0 で反対側が検出済みの場合は反対側の値をコピーする。
 *
 * button_w / button_h も補完後の padding に合わせて再計算する。
 *
 * @param array $bounds composite_detect_*_bounds_gd が返す配列
 * @param int   $imgW   元画像の幅
 * @param int   $imgH   元画像の高さ
 * @return array 補完後の bounds
 */
function composite_symmetry_fallback(array $bounds, int $imgW, int $imgH): array
{
    // 上下補完
    if ($bounds['padding_top'] > 0 && $bounds['padding_bottom'] === 0) {
        $bounds['padding_bottom'] = $bounds['padding_top'];
    } elseif ($bounds['padding_bottom'] > 0 && $bounds['padding_top'] === 0) {
        $bounds['padding_top'] = $bounds['padding_bottom'];
    }

    // 左右補完
    if ($bounds['padding_left'] > 0 && $bounds['padding_right'] === 0) {
        $bounds['padding_right'] = $bounds['padding_left'];
    } elseif ($bounds['padding_right'] > 0 && $bounds['padding_left'] === 0) {
        $bounds['padding_left'] = $bounds['padding_right'];
    }

    // button 領域を再計算
    $bounds['button_x'] = $bounds['padding_left'];
    $bounds['button_y'] = $bounds['padding_top'];
    $bounds['button_w'] = $imgW - $bounds['padding_left'] - $bounds['padding_right'];
    $bounds['button_h'] = $imgH - $bounds['padding_top']  - $bounds['padding_bottom'];

    // 負値ガード
    $bounds['button_w'] = max(1, $bounds['button_w']);
    $bounds['button_h'] = max(1, $bounds['button_h']);

    return $bounds;
}
```

#### composite_detect_margin_with_fallback を修正

`image_composite.php` 内の `composite_detect_margin_with_fallback` 関数（または
`composite_content_bounds.php` 内にある場合はそこ）の return 直前に
`composite_symmetry_fallback` を挟む。

```php
function composite_detect_margin_with_fallback(GdImage $sourceGd, int $outW, int $outH): array
{
    // ① グレー帯検出
    $bounds = composite_detect_rgb_light_margin_bounds_gd($sourceGd, 200);
    $totalPad = $bounds['padding_top'] + $bounds['padding_right']
              + $bounds['padding_bottom'] + $bounds['padding_left'];

    if ($totalPad > 0) {
        return composite_symmetry_fallback($bounds, $outW, $outH);  // ← 追加
    }

    // ② 白・透過検出（フォールバック）
    $bounds = composite_detect_content_bounds_gd($sourceGd);
    $totalPad = $bounds['padding_top'] + $bounds['padding_right']
              + $bounds['padding_bottom'] + $bounds['padding_left'];

    if ($totalPad > 0) {
        return composite_symmetry_fallback($bounds, $outW, $outH);  // ← 追加
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

---

### 実装後の確認（Cursor が自分で実行する）

```bash
curl -s -X POST https://lp-next.jitan.app/current/lp_reverse_cms/store/image_composite.php \
  -H 'Content-Type: application/json' \
  -d '{
    "source_url":     "/output/assets/img/btn01.jpg",
    "background_url": "/output/ai_images/plate_02cf2171c0699548.png",
    "border_radius":  15,
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
- `content_bounds.padding_bottom` が `padding_top` と同じ値（対称補完済み）
- `content_bounds.button_h` が上下パディング分だけ縮んでいること
- 出力画像で上下のグレー帯が均等に見えること

---

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
