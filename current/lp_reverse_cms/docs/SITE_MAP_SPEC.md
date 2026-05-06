# LP Reverse CMS — サイトマップ JSON 仕様書

## ミッション定義

**目的:** URL からクローンサイトのレイアウト（見た目）を再現すること  
**スコープ外:** フォーム送信・決済・メール等のデータ IN/OUT 機能  
**原則:** データ IO 領域は「見た目は残す・機能は無効化・後付けフックポイントを明示」

---

## 出力ファイル

`data/site_map.json` — クローンサイト全体のインデックス兼骨格

このファイル 1 つが以下をすべて兼ねる：
- サイトツリーのインデックス
- リソース（CSS/JS/画像/フォント）の完全マニフェスト
- エラー発生箇所のツリー座標
- データ IO 領域のフックポイント一覧

`site_map.json` のツリー構造は `output/` ディレクトリ構造と 1 対 1 対応する。

---

## JSON 構造

```json
{
  "meta": {
    "entry_url": "https://example.com/",
    "cloned_at": "2026-05-06T10:00:00Z",
    "charset": "UTF-8",
    "viewport": "width=device-width, initial-scale=1",
    "base_url": "https://example.com/",
    "cms": {
      "detected": "wordpress",
      "confidence": "high",
      "version": "6.5",
      "signals": ["wp-json link header", "body.page-id-*", "<!-- wp: block -->"],
      "rest_api_base": "https://example.com/wp-json/",
      "has_gutenberg": true,
      "theme": "twentytwentyfour"
    }
  },

  "resources": {
    "css": [
      {
        "original_url": "https://example.com/style.css",
        "local_path": "output/assets/css/style.css",
        "load_order": 0,
        "applied_to": ["index", "page-a"],
        "has_imports": true,
        "has_variables": true
      }
    ],
    "js": [
      {
        "original_url": "https://example.com/main.js",
        "local_path": "output/assets/js/main.js",
        "load_order": 0,
        "defer": true,
        "affects_rendering": true
      }
    ],
    "fonts": [
      {
        "original_url": "https://fonts.googleapis.com/css2?family=Noto+Sans+JP",
        "local_path": "output/assets/fonts/NotoSansJP.woff2",
        "format": "woff2",
        "used_in": ["index", "page-a"]
      }
    ],
    "images": [
      {
        "original_url": "https://example.com/hero.jpg",
        "local_path": "output/assets/img/hero.jpg",
        "has_srcset": true,
        "srcset_variants": [
          {
            "original_url": "https://example.com/hero-2x.jpg",
            "local_path": "output/assets/img/hero-2x.jpg",
            "descriptor": "2x"
          }
        ],
        "used_in_picture": false
      }
    ]
  },

  "pages": {
    "index": {
      "source_url": "https://example.com/",
      "local_path": "output/index.html",
      "coordinate": "entry",
      "status": "ok",

      "page_type": {
        "template": "front-page",
        "post_type": "page",
        "post_id": 1,
        "is_archive": false,
        "is_singular": true,
        "slug": "home"
      },

      "rendering_notes": {
        "has_js_dependent_content": false,
        "has_lazy_load": true,
        "has_picture_source": true,
        "has_gutenberg_blocks": true,
        "inline_styles_count": 3,
        "snapshot_reliability": "full"
      },

      "dynamic_regions": [
        {
          "coordinate": "entry.section[2]",
          "type": "ajax_loaded",
          "selector": "#recent-posts-widget",
          "note": "JS 実行後に差し込まれるコンテンツ",
          "snapshot_taken": false
        }
      ],

      "data_io_regions": [
        {
          "coordinate": "entry.section[4]",
          "type": "contact_form",
          "original_action": "https://example.com/send",
          "fields": ["name", "email", "message"],
          "status": "neutralized"
        },
        {
          "coordinate": "entry.section[6]",
          "type": "payment",
          "provider": "stripe",
          "status": "neutralized"
        },
        {
          "coordinate": "entry.section[7]",
          "type": "newsletter",
          "provider": "mailchimp",
          "status": "neutralized"
        }
      ],

      "sections": [
        {
          "id": "section-0",
          "coordinate": "entry.section[0]",
          "type": "hero",
          "elements": {
            "heading": "キャッチコピーテキスト",
            "image": "output/assets/img/hero.jpg",
            "cta_href": "/contact"
          }
        }
      ]
    },

    "page-a": {
      "source_url": "https://example.com/page-a",
      "local_path": "output/page-a/index.html",
      "coordinate": "internal[0]",
      "status": "ok",
      "page_type": { "template": "page", "slug": "page-a" },
      "rendering_notes": { "snapshot_reliability": "full" },
      "dynamic_regions": [],
      "data_io_regions": [],
      "sections": []
    },

    "page-k": {
      "source_url": "https://example.com/page-k",
      "local_path": "output/page-k/index.html",
      "coordinate": "internal[10]",
      "status": "error",
      "error": {
        "phase": "analyze",
        "severity": "fatal",
        "message": "Unexpected end of JSON input"
      },
      "page_type": null,
      "rendering_notes": null,
      "dynamic_regions": [],
      "data_io_regions": [],
      "sections": []
    }
  }
}
```

---

## coordinate（ツリー座標）仕様

処理中に発生した例外はすべて coordinate を付与して記録する。

| 値 | 意味 |
|----|------|
| `entry` | エントリページ本体 |
| `internal[N]` | 同一ドメイン内リンク先 N 番目 |
| `entry.section[N]` | エントリページの N 番目セクション |
| `internal[N].section[M]` | 内部ページ N の M 番目セクション |

エラーログ出力フォーマット（NDJSON）：

```json
{"level":"internal_page","coordinate":"internal[10]","phase":"analyze","severity":"fatal","message":"Unexpected end of JSON input","source_url":"https://example.com/page-k","ts":"2026-05-06T10:05:00Z"}
```

---

## data_io_regions — データ IO 領域の処理ルール

### 無効化方法

| type | 無効化方法 |
|------|-----------|
| `contact_form` | `action="#"` に書き換え |
| `newsletter` | `action="#"` に書き換え |
| `payment` | JS ハンドラ除去・ボタン外観は保持 |
| `login` | `action="#"` に書き換え |
| `external_embed` | プレースホルダー div に置換 |
| `mailto` | そのまま保持（機能無効化不要） |

### HTML 出力例

```html
<form
  data-lp-io-type="contact"
  data-lp-io-original-action="https://example.com/send"
  data-lp-io-coordinate="entry.section[4]"
  action="#"
  method="post"
>
  <!-- 見た目はそのまま再現 -->
  <input type="text" name="name" placeholder="お名前">
  <button type="submit">送信</button>
</form>
```

`data-lp-io-*` 属性が後付け実装のフックポイントになる。

---

## CMS 判定ロジック

HTML 取得後に以下を順番にチェックし `meta.cms.detected` に記録する。

| CMS | 判定シグナル |
|-----|------------|
| WordPress | `<link rel='https://api.w.org/'>` / `body.page-id-*` / `<!-- wp: -->` / `/wp-content/` |
| Shopify | `cdn.shopify.com` へのリソース参照 / `Shopify.theme` オブジェクト |
| Wix | `static.wixstatic.com` / `wix-bolt` メタタグ |
| Squarespace | `squarespace.com` スクリプトドメイン |
| 不明・静的 | `"unknown"` / `"static"` |

---

## output/ ディレクトリ構造（site_map.json と 1 対 1）

```
output/
├── index.html              ← pages.index
├── page-a/
│   └── index.html          ← pages.page-a  (coordinate: internal[0])
├── page-k/
│   └── index.html          ← pages.page-k  (coordinate: internal[10])
└── assets/
    ├── css/                ← resources.css
    ├── img/                ← resources.images
    ├── js/                 ← resources.js
    └── fonts/              ← resources.fonts
```

---

## rendering_notes — 表示崩れリスク管理

| フィールド | 内容 |
|-----------|------|
| `has_js_dependent_content` | JS 実行後にレイアウトが変わる領域がある |
| `has_lazy_load` | 遅延読み込み画像がある（`data-src` 等） |
| `has_picture_source` | `<picture>/<source>` 要素がある |
| `has_gutenberg_blocks` | WordPress Gutenberg ブロックがある |
| `inline_styles_count` | インラインスタイルの個数 |
| `snapshot_reliability` | `full` / `partial` / `none` |

---

## 実装対象ファイル

| ファイル | 役割 |
|---------|------|
| `lib/LpSiteMapper.php` | site_map.json の生成・更新・読み込み |
| `lib/LpCmsDetector.php` | CMS 判定ロジック |
| `lib/LpIoNeutralizer.php` | データ IO 領域の検出・無効化・data 属性付与 |
| `store/get_site_map.php` | site_map.json を返す API エンドポイント |

既存ファイルとの関係：
- `LpAnalyzer.php` → 解析結果を `LpSiteMapper` に渡す
- `LpGenerator.php` → `site_map.json` を参照して output/ を生成
- `store/analyze_lp.php` → `LpCmsDetector` と `LpIoNeutralizer` を組み込む
