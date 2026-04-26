## Cursor実装依頼：画像パターン5〜7 対応

### プロジェクト
lp-next（PHP 8.x / GCP Linux VM）
リポジトリ: https://github.com/BinaryTraffic/lp-next

---

## パターン定義

| # | type | 例 | FLUX | 処理ファイル |
|---|---|---|---|---|
| 5 | `gradient` | グラデーション背景＋テキスト | 不要 | `gradient_bg_generator.php`（新規） |
| 6 | `bordered` | フォト/イラストを囲む装飾フレーム | 要（内側） | `image_composite.php`（既存）+ 呼出フロー |
| 7 | `badge` | 番号丸・リボンラベル・NEWタグ | 不要 | `badge_generator.php`（新規） |

---

## タスク1：claude_image_analyze.php のプロンプト拡張

### 変更箇所：`$prompt` 変数

`type` の判定基準と、新パターン固有フィールドを追加する。

#### 新しいプロンプト（既存部分の置き換え）

```
typeの判定基準:
- photo: 実写・写真
- illustration: イラスト・アイコン・ベクター
- ui: ボタン・電話番号・バナー文字など機能的UI
- composite: 上記が複数混在（背景+テキスト+イラストなど）
- gradient: グラデーション単色背景（写真なし。テキストを乗せる前提の帯・セクション背景）
- bordered: 写真やイラストを縁取るフレーム・ボーダーが存在する画像
- badge: ナンバリング丸・NEWリボン・価格タグなど小型アクセント要素
```

#### JSONスキーマに以下フィールドを追加

```json
{
  "type": "photo | illustration | ui | composite | gradient | bordered | badge",

  // --- type=gradient のみ ---
  "gradient": {
    "type": "linear | radial",
    "angle": 180,
    "colors": [
      { "color": "#3a7bd5", "stop": 0.0 },
      { "color": "#00d2ff", "stop": 1.0 }
    ]
  },

  // --- type=bordered のみ ---
  "border": {
    "color": "#c8a96e",
    "width_pct": 0.06,
    "inner_type": "photo | illustration",
    "inner_description": "Portrait of a woman smiling, natural light"
  },

  // --- type=badge のみ ---
  "badge": {
    "shape": "circle | pill | ribbon | rect",
    "bg_color": "#e63c3c",
    "text_color": "#ffffff"
  }
}
```

#### プロンプトの補足指示（追加）

```
gradient: gradient.colors に開始・終了（必要なら中間）の色をstop(0.0〜1.0)付きで返す。
          angle は linear のとき度数(0=上→下, 90=左→右)。radial のとき 0。
bordered: border.width_pct は画像短辺に対するフレーム幅の比率（片側）。
          inner_description は FLUX へ渡す英語プロンプト。
badge:    badge.shape は形状の最も近いもの。
          badge の色情報のみここで返す。テキストは通常どおり texts[] に含める。
```

### PHPの正規化処理（既存の icons 正規化の直後に追加）

```php
// gradient 正規化
if (($parsed['type'] ?? '') === 'gradient') {
    if (!isset($parsed['gradient']) || !is_array($parsed['gradient'])) {
        $parsed['gradient'] = [];
    }
    $g = $parsed['gradient'];
    $parsed['gradient'] = [
        'type'   => in_array($g['type'] ?? '', ['linear','radial'], true) ? $g['type'] : 'linear',
        'angle'  => isset($g['angle']) ? (int) $g['angle'] : 180,
        'colors' => (isset($g['colors']) && is_array($g['colors'])) ? $g['colors'] : [],
    ];
}

// bordered 正規化
if (($parsed['type'] ?? '') === 'bordered') {
    if (!isset($parsed['border']) || !is_array($parsed['border'])) {
        $parsed['border'] = [];
    }
    $b = $parsed['border'];
    $parsed['border'] = [
        'color'             => isset($b['color']) ? (string) $b['color'] : '#000000',
        'width_pct'         => isset($b['width_pct']) ? (float) $b['width_pct'] : 0.05,
        'inner_type'        => in_array($b['inner_type'] ?? '', ['photo','illustration'], true) ? $b['inner_type'] : 'photo',
        'inner_description' => isset($b['inner_description']) ? (string) $b['inner_description'] : '',
    ];
}

// badge 正規化
if (($parsed['type'] ?? '') === 'badge') {
    if (!isset($parsed['badge']) || !is_array($parsed['badge'])) {
        $parsed['badge'] = [];
    }
    $bd = $parsed['badge'];
    $parsed['badge'] = [
        'shape'      => in_array($bd['shape'] ?? '', ['circle','pill','ribbon','rect'], true) ? $bd['shape'] : 'circle',
        'bg_color'   => isset($bd['bg_color']) ? (string) $bd['bg_color'] : '#e63c3c',
        'text_color' => isset($bd['text_color']) ? (string) $bd['text_color'] : '#ffffff',
    ];
}
```

---

## タスク2：gradient_bg_generator.php 新規作成

場所: `current/lp_reverse_cms/store/gradient_bg_generator.php`

### 役割
グラデーション背景JPEGを生成し `/output/ai_images/grad_{hex}.jpg` に保存する。
後続で `image_composite.php` にそのURLを `background_url` として渡す想定。

### POST JSON 入力

```json
{
  "width": 800,
  "height": 200,
  "gradient": {
    "type": "linear",
    "angle": 90,
    "colors": [
      { "color": "#3a7bd5", "stop": 0.0 },
      { "color": "#00d2ff", "stop": 1.0 }
    ]
  }
}
```

### 仕様

- `gradient.type` が `"linear"` のとき:
  - `angle` を方向ベクトルに変換（0=上→下, 90=左→右, 180=下→上, 270=右→左, 斜め対応）
  - 各ピクセルについて投影距離 `t`（0.0〜1.0）を計算
  - `colors` の stops から線形補間で RGB を決定（stops は昇順前提、ない場合は 0.0/1.0 と仮定）
  - `imagecreatetruecolor` → ピクセル塗り → `imagejpeg` 品質92
- `gradient.type` が `"radial"` のとき:
  - 中心から四隅の距離を最大値として `t` を計算
  - 同じく colors で補間

### レスポンス

```json
{ "url": "/output/ai_images/grad_{hex}.jpg" }
```

### エラーハンドリング
`openai_image_proxy.php` と同形式（JSON + HTTPステータス）。

### 制約
- PHP 8.x + GD（`imagecreatetruecolor` / `imagesetpixel`）
- Node.js・Composer 使用不可
- 保存先: `output/ai_images/`（www-data所有。Apache経由のみ書き込み可）

---

## タスク3：badge_generator.php 新規作成

場所: `current/lp_reverse_cms/store/badge_generator.php`

### 役割
バッジ形状（丸・角丸ピル・リボン・矩形）をGDで描き、テキストを乗せてJPEG保存。
背景は白マット（透過なし）。

### POST JSON 入力

```json
{
  "width": 80,
  "height": 80,
  "badge": {
    "shape": "circle",
    "bg_color": "#e63c3c",
    "text_color": "#ffffff"
  },
  "texts": [
    {
      "content": "01",
      "x_pct": 0.0, "y_pct": 0.0, "w_pct": 1.0, "h_pct": 1.0,
      "font_size_pct": 0.45,
      "bold": true,
      "color": "#ffffff"
    }
  ]
}
```

### 仕様

#### 形状の描画

| shape | 描画方法 |
|---|---|
| `circle` | `imagefilledellipse`（短辺を直径とした正円。余白は白マット） |
| `pill` | 左右に半円 + 中央矩形（`imagefilledarc` × 2 + `imagefilledrectangle`） |
| `ribbon` | 矩形＋左端に三角の切り込み（`imagefilledpolygon`）|
| `rect` | `imagefilledrectangle`（角丸なし） |

#### テキストの配置
`image_composite.php` の `composite_render_gd` と同じロジックで texts[] を描画する。
（フォント解決・サイズ調整・中央揃えを再利用）

フォントは `.env` の `IMAGE_COMPOSITE_FONT` または `/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc` を使用。

#### レスポンス

```json
{ "url": "/output/ai_images/badge_{hex}.jpg" }
```

### エラーハンドリング
`openai_image_proxy.php` と同形式。

### 制約
- PHP 8.x + GD
- フォント: `image_composite.php` と同じ解決順序

---

## タスク4：bordered パターンの処理フロー（ドキュメントのみ）

bordered は既存ツールの組み合わせで対応できるため新規ファイル不要。
フロー説明コメントを `claude_image_analyze.php` の docblock に追加する。

### 処理フロー

```
① claude_image_analyze.php → type=bordered, border.width_pct, border.inner_type, border.inner_description を取得

② 内側矩形の計算（呼出元 JS で計算）:
   border_px = Math.round(min(width, height) * border.width_pct)
   inner_w   = width  - border_px * 2
   inner_h   = height - border_px * 2

③ hf_image_proxy.php に POST:
   { "mode": border.inner_type, "background_description": border.inner_description,
     "width": inner_w, "height": inner_h }
   → { "url": inner_url }

④ image_composite.php に POST:
   {
     "background_url": inner_url,
     "width": width,           ← 元サイズ（フレーム込み）
     "height": height,
     "texts": [...],           ← analyze の texts（あれば）
     "icons": [...],
     "border_fill": {          ← ★ image_composite.php への新パラメータ（タスク5参照）
       "color": border.color,
       "width_px": border_px
     }
   }
```

---

## タスク5：image_composite.php に border_fill パラメータ追加

bordered フローの④で使う。

### 追加するパラメータ

```json
"border_fill": {
  "color": "#c8a96e",
  "width_px": 24
}
```

### 処理

- `border_fill` が指定されたとき、`source_url` による余白検出は行わない
- `width` × `height` の全面キャンバスを `border_fill.color` で塗りつぶす
- 内側矩形 `(border_px, border_px, width-border_px*2, height-border_px*2)` に `background_url` をリサイズして貼る
- texts / icons は従来どおりフルキャンバス比率で内側にマップ（`composite_map_full_box_to_inner` を再利用）
- `content_bounds` は内側矩形から算出して返す

---

## 実装後の確認（Cursor が自分で実行する）

push 後、以下の curl を GCP VM 上で実行し、`"url"` キーを含む JSON が返ることを確認してレポートに含めること。

```bash
# gradient_bg_generator.php
curl -s -X POST https://lp-next.jitan.app/current/lp_reverse_cms/store/gradient_bg_generator.php \
  -H 'Content-Type: application/json' \
  -d '{"width":400,"height":100,"gradient":{"type":"linear","angle":90,"colors":[{"color":"#3a7bd5","stop":0},{"color":"#00d2ff","stop":1}]}}'

# badge_generator.php
curl -s -X POST https://lp-next.jitan.app/current/lp_reverse_cms/store/badge_generator.php \
  -H 'Content-Type: application/json' \
  -d '{"width":80,"height":80,"badge":{"shape":"circle","bg_color":"#e63c3c","text_color":"#ffffff"},"texts":[{"content":"01","x_pct":0,"y_pct":0,"w_pct":1,"h_pct":1,"font_size_pct":0.45,"bold":true,"color":"#ffffff"}]}'
```
