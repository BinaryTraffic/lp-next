# アセット上書きバグ + space.gif 修正指示書

## Bug 1: 内部ページ解析が CSS を上書きする（レイアウト崩壊の根本原因）

### 症状

`hal-tanteisya.com/lp/uwaki` のクローン出力 `assets/css/content.css` に
`div#fixed-header { position: fixed }` と `Contact_sp/Contact_pc` のスタイルが含まれない。

asset_map.json を確認すると：

```
"https://hal-tanteisya.com/css/lp/uwaki/content.css?t=...": "assets/css/content.css" ← 正しいLP CSS
"https://hal-tanteisya.com/css/content.css?t=...":           "assets/css/content.css" ← 上書き！
```

内部ページが参照する `/css/content.css`（サイト共通CSS）が、LPページ専用の
`/css/lp/uwaki/content.css` を上書きしている。

### 根本原因

`lib/LpAssetDownloader.php` の `seedFromMergedAssetMap()` が `$this->done` は正しく埋めるが
**`$this->fileRegistry` を埋めない**。

```php
private function seedFromMergedAssetMap(array $existingUrlMap): void
{
    foreach ($existingUrlMap as $url => $localPath) {
        $this->urlMap[$url] = $localPath;

        // done には追加 → 同一URLの再DLはスキップされる ✓
        $fk = LpUrlContext::canonicalHttpUrlForFetch($url);
        $this->done[$fk] = true;

        // ← fileRegistry は追加しない ← これがバグ
    }
}
```

内部ページ解析では新しい `LpAssetDownloader` インスタンスが作られるため
`fileRegistry` は空から始まる。`/css/content.css` の basename `content.css` は
「未割当て」と判定され、既存ファイルを上書きしてしまう。

### 修正箇所: `lib/LpAssetDownloader.php` の `seedFromMergedAssetMap()`

既存コード（`$this->done[$fk] = true;` の直前）に `fileRegistry` シードを追加：

```php
private function seedFromMergedAssetMap(array $existingUrlMap): void
{
    foreach ($existingUrlMap as $url => $localPath) {
        if (!is_string($url) || !is_string($localPath) || $url === '') {
            continue;
        }
        $this->urlMap[$url] = $localPath;

        // ★ 追加: 既存 localPath を fileRegistry に登録してファイル上書きを防ぐ
        // localPath 例: "assets/css/content.css" → key: "css/content.css"
        if (preg_match('#^assets/([^/]+)/([^/]+)$#', $localPath, $lm)) {
            $registryKey = $lm[1] . '/' . $lm[2];
            if (!isset($this->fileRegistry[$registryKey])) {
                $this->fileRegistry[$registryKey] = $url;
            }
        }

        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }
        $fk = LpUrlContext::canonicalHttpUrlForFetch($url);
        if ($fk !== '' && (str_starts_with($fk, 'http://') || str_starts_with($fk, 'https://'))) {
            $this->done[$fk] = true;
        }
    }
}
```

**効果:** 内部ページの `LpAssetDownloader` が `/css/content.css` を `content.css` として割り当てようとすると
`fileRegistry['css/content.css']` に別のURLが登録済みと判定され、
`allocateFilename()` の衝突処理で `content_abc1234.css` のようなハッシュ付き名前が生成される。
既存の `assets/css/content.css`（LP専用CSS）は上書きされない。

---

## Bug 2: `space.gif` が置換されない（lazy-load 画像が空白になる）

### 症状

新規生成の HTML で多数の画像が `src="assets/img/space.gif"` のまま。
`bec9af6` で追加した `promoteLazyLoadAttributes()` が効いていない。

### 根本原因

`lib/LpGenerator.php` の `processSection()` における処理順序：

```
① promoteLazyLoadAttributes(wrapRoot) → src を data-src に置換 ✓
② foreach ($section['elements'] as $element) {
     $newSrc = $override['src'] ?? $element['original_src'] ?? null;
     // original_src = "https://hal-tanteisya.com/space.gif"
     $node->setAttribute('src', $newSrc);  // ← ①の修正を上書き！
   }
③ saveHTML() → src="https://hal-tanteisya.com/space.gif" のまま出力
④ applyAssetMap() → src="assets/img/space.gif" に変換
```

`original_src` に space.gif の絶対URLが保存されているため、
要素処理ループで ① の修正が消える。

### 修正箇所: `lib/LpGenerator.php` の `processSection()`

`promoteLazyLoadAttributes()` の呼び出しを要素ループの**後**に移動する。

**修正前（現状）:**

```php
$wrapRoot = $xpathBoot->query('//*[@id="__lp_root__"]')->item(0);
if ($wrapRoot instanceof DOMElement) {
    LpDomScriptCleanup::stripScriptsAndJsSpills($wrapRoot);
    $this->promoteLazyLoadAttributes($wrapRoot);  // ← ループ前
}

$xpath = new DOMXPath($dom);
foreach ($section['elements'] as $element) {
    // ... src を original_src で上書き
}
```

**修正後:**

```php
$wrapRoot = $xpathBoot->query('//*[@id="__lp_root__"]')->item(0);
if ($wrapRoot instanceof DOMElement) {
    LpDomScriptCleanup::stripScriptsAndJsSpills($wrapRoot);
    // promoteLazyLoadAttributes はループ後に移動
}

$xpath = new DOMXPath($dom);
foreach ($section['elements'] as $element) {
    // ... src を original_src で上書き
}

// ★ 移動: 要素処理後に実行することで original_src による上書きを修正できる
if ($wrapRoot instanceof DOMElement) {
    $this->promoteLazyLoadAttributes($wrapRoot);
}

// Extract the root wrapper's children as HTML
$root = $xpath->query('//*[@id="__lp_root__"]');
```

**効果:**
- 要素処理で `src = "https://hal-tanteisya.com/space.gif"` が設定される
- その後 `promoteLazyLoadAttributes()` が `str_contains($src, 'space.gif')` で検出し `data-src` 値に置換
- `saveHTML()` 出力が正しい実画像 URL を含む
- `applyAssetMap()` で `assets/img/real-image.jpg` に変換される

---

## 変更対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `lib/LpAssetDownloader.php` | `seedFromMergedAssetMap()` に `fileRegistry` シードを追加 |
| `lib/LpGenerator.php` | `promoteLazyLoadAttributes()` 呼び出しを要素ループの後に移動 |

---

## 修正後の確認手順

1. VM で `git pull`
2. `https://hal-tanteisya.com/lp/uwaki` を**再解析**（asset_map.json をリセットするため）
3. 再生成
4. `assets/css/content.css` に `div#fixed-header { position: fixed` が含まれることを確認
5. 生成 HTML で `src="assets/img/space.gif"` が残っていないことを確認
