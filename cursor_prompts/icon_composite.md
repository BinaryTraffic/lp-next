## Cursor実装依頼：アイコン定数管理 + image_composite.php拡張

### プロジェクト
lp-next（PHP 8.x / Node.js不使用 / GCP Linux VM）
リポジトリ: https://github.com/BinaryTraffic/lp-next

---

### タスク1：icon_map.php 新規作成
場所: current/lp_reverse_cms/lib/icon_map.php

Claude Vision APIが返す icons[].label をキーに
SVGファイルパスを返す定数マップを定義する。

```php
const ICON_MAP = [
    'LINE'      => '/assets/icons/line.svg',
    'phone'     => '/assets/icons/phone.svg',
    'arrow'     => '/assets/icons/arrow-right.svg',
    'check'     => '/assets/icons/check-circle.svg',
    'calendar'  => '/assets/icons/calendar.svg',
    'mail'      => '/assets/icons/mail.svg',
    'map'       => '/assets/icons/map-pin.svg',
    'instagram' => '/assets/icons/instagram.svg',
    'twitter'   => '/assets/icons/twitter.svg',
    'other'     => '/assets/icons/star.svg',
];
```

SVGファイルは current/lp_reverse_cms/assets/icons/ に配置する。
各SVGはviewBox="0 0 24 24" の24px基準でシンプルなアウトラインアイコン。
SVGファイルも合わせて生成すること。

---

### タスク2：image_composite.php 拡張

現在の入力JSON（既存）:
```json
{
  "background_url": "/output/ai_images/hf_xxx.jpg",
  "width": 219,
  "height": 51,
  "texts": [
    {
      "content": "友だち追加",
      "x_pct": 0.18, "y_pct": 0.20,
      "w_pct": 0.64, "h_pct": 0.60,
      "font_size_pct": 0.45,
      "bold": true,
      "color": "#ffffff"
    }
  ]
}
```

拡張後の入力JSON（iconsを追加）:
```json
{
  "background_url": "/output/ai_images/hf_xxx.jpg",
  "width": 219,
  "height": 51,
  "texts": [...],
  "icons": [
    {
      "label": "LINE",
      "x_pct": 0.05,
      "y_pct": 0.15,
      "w_pct": 0.20,
      "h_pct": 0.70
    }
  ]
}
```

### タスク3：余白検出・実ボタンサイズ割り出し

キャプチャ画像には余白（白・透過）が含まれる場合がある。
GD を使い以下の処理を image_composite.php に組み込む。

#### 処理フロー
```
入力画像（219×51px）
    ↓
① 四辺から内側に向かって単色行・列をスキャン
② 余白ピクセルを検出（白 #ffffff ±10 または 透過）
③ content_bounds を算出
    {
      "padding_top":    10,  // px
      "padding_right":  20,
      "padding_bottom": 10,
      "padding_left":   20,
      "button_x":       20,  // 実ボタン左上X
      "button_y":       10,  // 実ボタン左上Y
      "button_w":      179,  // 実ボタン幅
      "button_h":       31   // 実ボタン高さ
    }
④ 新画像は実ボタンサイズ（179×31px）で生成
⑤ 元の余白サイズに合わせてキャンバスを拡張して出力
   → 最終出力は元と同じ 219×51px（余白込み）
```

#### 余白色の判定基準
- R,G,B がすべて 245 以上 → 白系余白
- アルファ値が 0（透過）→ 透過余白
- 上記どちらでもない行/列が実コンテンツ領域

#### レスポンスに content_bounds を追加
```json
{
  "url": "/output/ai_images/composed_{uniqid}.jpg",
  "content_bounds": {
    "padding_top": 10,
    "padding_right": 20,
    "padding_bottom": 10,
    "padding_left": 20,
    "button_w": 179,
    "button_h": 31
  }
}
```

---

### 合成順序（最終）
1. 入力画像から余白を検出 → content_bounds 算出
2. 実ボタン領域（button_w × button_h）で背景を生成・読み込む
3. icons[] を icon_map.php で解決 → SVGをラスタライズして座標に配置
4. texts[] をNotoSansCJKフォントで座標に配置
5. 余白を加えた元サイズ（width × height）のキャンバスに合成
6. JPEGで出力・保存

### 環境・制約
- PHP 8.x + GD または Imagick
- 日本語フォント:
    /usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc
    /usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc
- SVGのラスタライズ: Imagick使用（ImageMagickがVM上に存在する前提）
- icons が空配列の場合はテキスト合成のみ実行
- Node.js・Composer 使用不可
- エラーハンドリングは openai_image_proxy.php と同じ形式
- 出力: { "url": "/output/ai_images/composed_{uniqid}.jpg", "content_bounds": {...} }
