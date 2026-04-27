## Cursor修正依頼：ソースマスク合成方式に変更

### 問題（スクリーンショットで確認済み）

1. **グレー帯が消える**（無料プラン申込 329×122）
   - 書き出し画像に元画像のグレー上下帯が残らず、板が全面に貼られる
2. **角丸が消える**（3例全て）
   - 元画像は角丸ボタンだが、書き出しは矩形になる

### 原因

現在の `composite_render_gd` は「空キャンバス → 余白色で塗りつぶし → 板を矩形貼り付け」。
ソース画像の形状（グレー帯・角丸）を全く引き継いでいない。

### 修正方針：「ソースマスク合成」に変更

```
旧: 空キャンバス → 余白色で塗る → 板を button 矩形に貼る → テキスト描画
新: ソース画像をコピー → ボタン領域の非パディング画素だけ板で置換 → テキスト描画
```

これにより:
- グレー帯・余白はソース画像のまま保持される
- 角丸コーナーの画素はパディング判定されるため置換されず、丸みが自然に残る

### 修正箇所

`current/lp_reverse_cms/store/image_composite.php`

`composite_render_gd` の「キャンバス作成〜板貼り付け」部分を書き換える。

#### 修正前（現在の処理）

```php
// 出力キャンバス作成
$output = imagecreatetruecolor($outW, $outH);
// 余白色で塗りつぶし
$bgColor = imagecolorallocate($output, $mr, $mg, $mb);
imagefill($output, 0, 0, $bgColor);
// 板をボタン領域に貼り付け（矩形）
imagecopyresampled(
    $output, $plateGd,
    $bx, $by, 0, 0,
    $bw, $bh,
    imagesx($plateGd), imagesy($plateGd)
);
```

（上記は概念コード。実際のコードに合わせて対応箇所を特定すること）

#### 修正後（ソースマスク合成）

```php
// 1. 出力キャンバスにソース画像をそのままコピー（余白・角丸を保持）
$output = imagecreatetruecolor($outW, $outH);
imagecopy($output, $sourceGd, 0, 0, 0, 0, $outW, $outH);

// 2. 板をボタン領域サイズにスケーリング
$plateScaled = imagecreatetruecolor($bw, $bh);
imagecopyresampled(
    $plateScaled, $plateGd,
    0, 0, 0, 0,
    $bw, $bh,
    imagesx($plateGd), imagesy($plateGd)
);

// 3. ボタン領域の非パディング画素だけ板で置換（角丸・余白は触らない）
for ($y = $by; $y < $by + $bh; $y++) {
    for ($x = $bx; $x < $bx + $bw; $x++) {
        if (!composite_is_padding_pixel_gd($sourceGd, $x, $y)) {
            $plateColor = imagecolorat($plateScaled, $x - $bx, $y - $by);
            imagesetpixel($output, $x, $y, $plateColor);
        }
    }
}
imagedestroy($plateScaled);
```

### 変数名について

- `$bx`, `$by`, `$bw`, `$bh` は `$marginBounds['button_x']` 等の値。実際のコードの変数名に合わせること
- `$sourceGd` はソース画像の GdImage。既に `composite_render_gd` 内で使用済みのはず
- 板変数名も実際のコードに合わせること

### composite_render_imagick についても同様に修正

Imagick パスが存在する場合、同じ考え方で:
- 出力ベースをソース画像からコピー
- 板をボタン領域にスケーリング
- ボタン領域の非パディング画素だけ置換

### 実装後の確認（Cursor が自分で実行する）

以下の3パターンを curl で叩き、書き出し画像を目視または JSON で確認する。

```bash
# 無料プラン申込（角丸ピンクボタン）
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
- `content_bounds.padding_top` が 4 以上
- `output_url` の画像で上下グレー帯が保持されていること
- `output_url` の画像でボタン角丸が保持されていること

---

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
