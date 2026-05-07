# 2フェーズ生成 実装指示書

## 設計方針

1フェーズの巨大リクエストを廃止し、**トップページ生成 → 内部ページ1件ずつ生成** に分割する。

```
Phase 1: store/generate_entry.php
  → output/index.html を生成
  → site_map.json の pages["index"].status = "generated"

Phase 2: store/generate_internal.php  (1リクエスト = 1ページ)
  → output/internal_N/index.html を生成
  → site_map.json の pages["internal_N"].status = "generated"

フロントエンド (assets/js/index.js) が順番に呼び出す
```

---

## Phase 0：同一ドメイン URL リストの確定

`site_map.json` の `pages` キーに既に `source_url` が揃っている。
これをそのまま内部ページの生成対象リストとして使う。

追加で `store/list_internal_urls.php` を作成し、
フロントエンドが生成前にリストを取得できるようにする：

```json
{
  "entry_url": "https://example.com/",
  "internals": [
    {"key": "internal_0", "source_url": "https://example.com/page-a", "status": "pending"},
    {"key": "internal_1", "source_url": "https://example.com/page-b", "status": "pending"}
  ]
}
```

`status` は `site_map.json` の各ページの現在状態を返す。

---

## store/generate_entry.php（新規作成）

**役割:** トップページ（entry）のみ生成する

```
POST /store/generate_entry.php

処理:
1. site_map.json を読み込む
2. pages["index"] のセクションから HTML を生成
3. data_io_regions を neutralize（action="#" + data-lp-io-* 付与）
4. クリックインターセプター JS を </body> 直前に注入（後述）
5. output/index.html として書き出す
6. site_map.json の pages["index"].status を "generated" に更新

レスポンス:
{
  "ok": true,
  "page": "index",
  "local_path": "output/index.html",
  "preview_url": "/current/lp_reverse_cms/output/ws_xxx/index.html"
}
```

---

## store/generate_internal.php（新規作成）

**役割:** 内部ページを1件ずつ生成する

```
POST /store/generate_internal.php
Body: {"key": "internal_0"}

処理:
1. site_map.json を読み込む
2. pages[$key] が存在しない → 404
3. pages[$key].status === "error" → スキップしてエラー返却
4. $key のセクションから HTML を生成
5. data_io_regions を neutralize
6. クリックインターセプター JS を </body> 直前に注入
7. local_path にディレクトリ作成 → index.html 書き出し
8. site_map.json の pages[$key].status を "generated" に更新

レスポンス:
{
  "ok": true,
  "page": "internal_0",
  "coordinate": "internal[0]",
  "source_url": "https://example.com/page-a",
  "local_path": "output/internal_0/index.html"
}
```

---

## クリックインターセプター JS（全ページに注入）

`output/index.html` と `output/internal_N/index.html` の `</body>` 直前に注入する。
`ORIGIN` はクローン元の `meta.entry_url` から取得する。

```javascript
<script data-lp-interceptor>
(function(){
  var ORIGIN = '{{ENTRY_ORIGIN}}'; // 例: https://example.com
  var CMS    = '/current/lp_reverse_cms/store/generate_internal.php';
  var MAP    = {{INTERNAL_URL_MAP}}; // {"https://example.com/page-a":"internal_0", ...}

  document.addEventListener('click', function(e){
    var a = e.target.closest('a[href]');
    if (!a) return;
    try {
      var url = new URL(a.href);
      if (url.origin !== ORIGIN) return;
    } catch(err) { return; }

    var key = MAP[a.href] || MAP[a.href.replace(/\/$/, '')];
    if (!key) return;

    e.preventDefault();

    fetch(CMS, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({key: key})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.local_path) {
        // output/index.html からの相対パスで遷移
        window.location.href = d.local_path.replace('output/', '');
      }
    })
    .catch(function(){ /* サイレント失敗 */ });
  });
})();
</script>
```

`{{ENTRY_ORIGIN}}` と `{{INTERNAL_URL_MAP}}` は PHP が生成時に埋め込む。

---

## INTERNAL_URL_MAP の生成（PHP側）

```php
// site_map.json から key → source_url の逆引きマップを生成
$urlMap = [];
foreach ($siteMap['pages'] as $key => $page) {
    if ($key === 'index') continue;
    $urlMap[$page['source_url']] = $key;
}
$mapJson = json_encode($urlMap, JSON_UNESCAPED_UNICODE);
// JS テンプレートの {{INTERNAL_URL_MAP}} を $mapJson で置換
```

---

## store/list_internal_urls.php（新規作成）

```
GET /store/list_internal_urls.php

レスポンス:
{
  "entry_url": "https://example.com/",
  "internals": [
    {"key": "internal_0", "source_url": "https://...", "status": "pending"},
    {"key": "internal_1", "source_url": "https://...", "status": "generated"},
    {"key": "internal_10", "source_url": "https://...", "status": "error"}
  ],
  "total": 20,
  "generated": 1,
  "pending": 18,
  "error": 1
}
```

---

## フロントエンド（assets/js/index.js）の変更方針

既存の「保存＆サイト生成」ボタンのフローを以下に変更する：

```
① generate_entry.php を呼ぶ → トップページ生成完了
② list_internal_urls.php でリスト取得
③ internals を順番に generate_internal.php へ POST
   ├─ 1件完了 → プログレスバー更新
   ├─ status: "error" はスキップ表示
   └─ 全件完了 → 「プレビューを開く」ボタン表示
```

進捗表示例：
```
[===========>        ] 11 / 20 ページ生成中...
```

---

## 既存 generate_lp.php の扱い

- **残す（削除しない）**
- フォールバック用として `lp_structure.json` のみの単一ページ生成に使用
- site_map.json がある場合は新エンドポイントを使う

---

## 変更・作成対象ファイル

| ファイル | 種別 | 内容 |
|---------|------|------|
| `store/generate_entry.php` | 新規 | Phase 1: トップページ生成 |
| `store/generate_internal.php` | 新規 | Phase 2: 内部ページ1件生成 |
| `store/list_internal_urls.php` | 新規 | 内部ページ一覧・状態返却 |
| `lib/LpGenerator.php` | 修正 | インターセプター JS 注入メソッド追加 |
| `lib/LpSiteMapper.php` | 修正 | 個別ページ status 更新メソッド追加 |
| `assets/js/index.js` | 修正 | 2フェーズ生成フロー + プログレス表示 |
| `store/generate_lp.php` | 維持 | フォールバック用（変更なし） |

---

## 注意事項

- `set_time_limit(0)` は `generate_entry.php` / `generate_internal.php` 両方に設定
- 内部ページの assets/ は `output/assets/` を共有（`../assets/` で参照）
- `open_basedir` は `/home/lp-next:/tmp` の範囲内で処理
- `generate_internal.php` は冪等（同じ key を再度 POST したら上書き再生成する）
