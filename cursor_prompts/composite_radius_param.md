## Cursor修正依頼：border_radius パラメータ追加 ＋ スーパーサンプリング AA

### 背景・経緯

`remove_composite_radius_clip` で composite のクリップを外したところ、
板（plate PNG）自身が大きい radius を持っていたため、巨大 pill 形状＋黒背景になった。

**方針転換：**
- 板は「色・テクスチャのみ」とし、形状（radius）は持たせない
- composite が radius を一元管理する
- radius は API パラメータで明示指定できるようにする（省略時は JPEG 検出値）
- スーパーサンプリングでアンチエイリアスを実現

---

### 修正 1：remove_composite_radius_clip を差し戻す

`composite_render_gd` の板貼り付けを `composite_point_in_rounded_rect` による
角丸クリップ方式に**戻す**（ee4fb58 の状態）。

`imagecopyresampled` 単純貼り付けに変えた部分を元の走査ループに戻すこと。

---

### 修正 2：`border_radius` を API リクエストパラメータに追加

`current/lp_reverse_cms/store/image_composite.php` のリクエスト解析部分。

```php
// border_radius: 明示指定があればそれを使用、なければ JPEG 検出値
$requestedRadius = isset($params['border_radius']) ? (int)$params['border_radius'] : null;
```

`composite_render_gd` / `composite_render_imagick` の呼び出し時に渡し、
関数内で以下のように使う：

```php
// 検出
$detectedRadius = composite_detect_border_radius_gd($sourceGd, $bx, $by, $bw, $bh);
// 明示指定があればそちらを優先
$radius = ($requestedRadius !== null) ? $requestedRadius : $detectedRadius;
```

`content_bounds` レスポンスには両方を返す：

```json
"content_bounds": {
  "padding_top": 6,
  "border_radius": 15,
  "border_radius_detected": 5
}
```

---

### 修正 3：スーパーサンプリングで角丸をアンチエイリアス化

`current/lp_reverse_cms/store/image_composite.php` の板クリップ描画部分を
以下のスーパーサンプリング方式に変更する。

```php
$factor = 4; // 4倍サンプリング
$bwS = $bw * $factor;
$bhS = $bh * $factor;
$rS  = $radius * $factor;

// 4x サイズで板を生成
$plateHi = imagecreatetruecolor($bwS, $bhS);
imagealphablending($plateHi, false);
imagesavealpha($plateHi, true);
imagecopyresampled($plateHi, $plateGd, 0, 0, 0, 0, $bwS, $bhS, imagesx($plateGd), imagesy($plateGd));

// 4x サイズで透過マスクを作成し角丸を描画
$mask = imagecreatetruecolor($bwS, $bhS);
imagealphablending($mask, false);
imagesavealpha($mask, true);
$trans  = imagecolorallocatealpha($mask, 0, 0, 0, 127);
$opaque = imagecolorallocate($mask, 255, 255, 255);
imagefill($mask, 0, 0, $trans);

// 角丸矩形をマスクに描画（composite_point_in_rounded_rect を使って確実に）
for ($my = 0; $my < $bhS; $my++) {
    for ($mx = 0; $mx < $bwS; $mx++) {
        if (composite_point_in_rounded_rect($mx, $my, 0, 0, $bwS, $bhS, $rS)) {
            imagesetpixel($mask, $mx, $my, $opaque);
        }
    }
}

// マスクを適用した 4x 合成画像を作成
$compositeHi = imagecreatetruecolor($bwS, $bhS);
imagealphablending($compositeHi, false);
imagesavealpha($compositeHi, true);
imagefill($compositeHi, 0, 0, $trans);

for ($my = 0; $my < $bhS; $my++) {
    for ($mx = 0; $mx < $bwS; $mx++) {
        $m = imagecolorat($mask, $mx, $my);
        $ma = ($m >> 24) & 0x7F;
        if ($ma < 64) { // ほぼ不透明 = 角丸内
            imagesetpixel($compositeHi, $mx, $my, imagecolorat($plateHi, $mx, $my));
        }
    }
}
imagedestroy($mask);
imagedestroy($plateHi);

// 4x → 1x に縮小（縮小の平均化がアンチエイリアスになる）
$plateAA = imagecreatetruecolor($bw, $bh);
imagealphablending($plateAA, false);
imagesavealpha($plateAA, true);
$trans1 = imagecolorallocatealpha($plateAA, 0, 0, 0, 127);
imagefill($plateAA, 0, 0, $trans1);
imagecopyresampled($plateAA, $compositeHi, 0, 0, 0, 0, $bw, $bh, $bwS, $bhS);
imagedestroy($compositeHi);

// 背景の上にアンチエイリアス済み板を合成
imagealphablending($output, true);
imagecopy($output, $plateAA, $bx, $by, 0, 0, $bw, $bh);
imagedestroy($plateAA);
```

**注意：** 4x ループ（$bwS × $bhS）はピクセル数が 16 倍になる。
329×116 ボタンなら 4x = 1316×464 ≈ 61 万ピクセル。PHP の処理時間は数秒以内で問題なし。

---

### 実装後の確認（Cursor が自分で実行する）

```bash
# border_radius を明示指定してテスト
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
- `content_bounds.border_radius` が 15（指定値がそのまま使われる）
- `content_bounds.border_radius_detected` が検出値（参考）
- `output_url` の画像で：グレー帯あり・角丸あり（ジャギなし）・テキストあり

---

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
