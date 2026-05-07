# バグ修正指示書：JS テンプレートリテラル漏洩問題

**対象バージョン:** lp_reverse_cms v1.3.x  
**修正日:** 2026-05-05  
**症状:** 生成 HTML のヘッダーセクションに `` ` : ` `` や `IMG` という文字列が表示される

---

## 1. 症状

`https://www.otakaraya.jp/` をクローンした際、プレビューの `<header>` セクション末尾に以下のような文字列が表示される。

```
` : `<div class="wd_no_icon">IMG</div>
```

これにより：
- ヘッダー内に謎の記号（コロン、バッククォート）が表示される
- グレーの「IMG」プレースホルダーボックスが表示される
- FV（ファーストビュー）セクションが視覚的に崩れて見える

---

## 2. 原因

PHP の libxml HTML パーサーは、`<script>` タグ内の **JavaScript テンプレートリテラル**（バッククォート文字列）を正しく処理できない。

テンプレートリテラルの中に `<div>` 等の HTML タグが含まれている場合、libxml は「生テキスト」として扱わず、実際の DOM ノードとして解析してしまう。

```javascript
// 元サイトの <script> 内（検索オートコンプリートウィジェット）
const html = `<div class="wd_predictive_pages_grid">` +
    (hasIcon ? `<div class="wd_icon">...</div>` : `<div class="wd_no_icon">IMG</div>`);
```

libxml がこれを DOM に読み込むと：
- バッククォート（`` ` ``）がテキストノードとして残る
- ` : ` という三項演算子の記号もテキストノードに混入
- `<div class="wd_no_icon">IMG</div>` が実際の DOM 要素として挿入される

その結果、`buildSectionHtml()` がこれらのゴミノードをセクション HTML として保存してしまう。

---

## 3. 修正対象ファイル（2 ファイル）

### ファイル 1：`lib/LpAnalyzer.php`

**変更箇所：** `analyze()` メソッド内、`$dom->loadHTML()` の直前

**変更前：**
```php
libxml_use_internal_errors(true);
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
```

**変更後：**
```php
// Strip inline <script> content before libxml parsing.
// JS template literals containing <div> etc. are misread as DOM nodes by libxml's
// HTML parser, causing backtick/ternary text to leak into section HTML output.
$htmlForDom = (string) preg_replace_callback(
    '#(<script(?:\s[^>]*)?>).*?(</script>)#si',
    static fn(array $m): string => $m[1] . $m[2],
    $html
);

libxml_use_internal_errors(true);
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->loadHTML('<?xml encoding="UTF-8">' . $htmlForDom, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
```

---

### ファイル 2：`lib/LpGenerator.php`

**変更箇所：** `processSection()` メソッド内、`$dom->loadHTML()` の直前

**変更前：**
```php
libxml_use_internal_errors(true);
$dom = new DOMDocument('1.0', 'UTF-8');

// Wrap in a neutral container so loadHTML doesn't invent wrappers
$wrappedHtml = '<?xml encoding="UTF-8"><div id="__lp_root__">' . $originalHtml . '</div>';
$dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
```

**変更後：**
```php
// Strip inline <script> content — JS template literals with HTML inside are
// misread as DOM nodes by libxml, re-injecting garbled text on every generate().
$originalHtml = (string) preg_replace_callback(
    '#(<script(?:\s[^>]*)?>).*?(</script>)#si',
    static fn(array $m): string => $m[1] . $m[2],
    $originalHtml
);

libxml_use_internal_errors(true);
$dom = new DOMDocument('1.0', 'UTF-8');

// Wrap in a neutral container so loadHTML doesn't invent wrappers
$wrappedHtml = '<?xml encoding="UTF-8"><div id="__lp_root__">' . $originalHtml . '</div>';
$dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
```

---

## 4. 正規表現の動作説明

```
#(<script(?:\s[^>]*)?>).*?(</script>)#si
```

| 部分 | 意味 |
|------|------|
| `(<script(?:\s[^>]*)?>)` | `<script>` または `<script src="...">` 等の開始タグをキャプチャ（属性を保持） |
| `.*?` | タグ間のコンテンツ（インラインスクリプト）を最短マッチ |
| `(</script>)` | 閉じタグをキャプチャ |
| `s` フラグ | `.` が改行にもマッチ（複数行スクリプトに対応） |
| `i` フラグ | 大文字小文字を無視 |

置換後：`$m[1] . $m[2]` = 開始タグ + 閉じタグ（コンテンツ空）

- `<script src="..."></script>` → そのまま（src 属性は保持）
- `<script>var x = \`...\`;</script>` → `<script></script>`（コンテンツ削除）

---

## 5. GCP VM への適用手順

```bash
# ローカルから GCP VM へ転送
scp lib/LpAnalyzer.php  USER@GCP_VM:/path/to/lp_reverse_cms/lib/LpAnalyzer.php
scp lib/LpGenerator.php USER@GCP_VM:/path/to/lp_reverse_cms/lib/LpGenerator.php
```

---

## 6. 適用後の確認手順

1. **再解析は不要** — `LpGenerator` の修正により、既存の `lp_structure.json` でも次回生成時に自動的にスクリプト内容が除去される

2. **「LP を生成」ボタンをクリック**（Generate ステップのみ再実行）

3. プレビューを確認：
   - ヘッダーに `` ` : ` `` や `IMG` が消えていること
   - FV セクション（`.fv_renew_2025`）の背景画像が表示されること
   - ※ debug.php の `output_background_urls` は既に `["assets/img/E6_96_B0FV_250724_pc.webp"]` を示しており、背景 CSS の置換自体は正常完了済み

4. 次回以降は新規サイト取得時も自動的にこの問題が発生しない

---

## 7. 注意事項

- `<script src="...">` タグ（外部スクリプト参照）は**削除しない**。属性はそのまま保持されるため、クローンの JS 動作には影響なし
- インライン `<script>` の**コンテンツのみ**を空にする処理のため、クローンの視覚表現に影響なし
- 将来的に `LpAssetDownloader.php` でも同様の問題が発生した場合は、`downloadAll()` 内の `$dom->loadHTML()` 直前に同じ前処理を追加する

---

## 8. 関連バグ（別対応済み）

| バグ | 状態 | 対応内容 |
|------|------|---------|
| 日本語ファイル名の背景画像が取得されない（`新FV_250724_pc.webp`） | ✅ 修正済み (commit e571f4b) | `collectStylesheets()` に inline `<style>` の `url()` 収集を追加。`downloadUrl()` でデコード/エンコード両形式を `urlMap` に登録 |
| `jkan00h.ttc` フォントが未取得 | 未対応 | `FONT_EXTENSIONS` に `'ttc'` を追加すれば解決 |
