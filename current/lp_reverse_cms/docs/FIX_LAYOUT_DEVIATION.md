# レイアウト乖離 バグ修正指示書

## 対象URL: https://hal-tanteisya.com/lp/uwaki

生成 HTML が元サイトと大幅に乖離する原因は以下の3件。
いずれも解析フェーズで構造が壊れることが根本。

---

## Bug 1: `<?xml encoding="UTF-8">` が生成 HTML に混入

### 症状

生成 `index.html` の 2行目に以下が出力される：
```
<?xml encoding="UTF-8"><html lang="ja">
```

ブラウザは XML 宣言を無視するが、`<!DOCTYPE html>` の直後に謎のテキストが挿入されるためパース順序が乱れ、レイアウトが崩れる。

### 根本原因

`lib/LpIoNeutralizer.php` の `applyNeutralization()` (line 99, 143)：

```php
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
// ...
return $dom->saveHTML();  // ← XML PI が文書先頭ノードとして残りシリアライズされる
```

`loadHTML()` に `<?xml encoding="UTF-8">` を渡すと DOMDocument が Processing Instruction ノードとして保持し、
引数なしの `saveHTML()` が `<!DOCTYPE html>\n<?xml encoding="UTF-8"><html ...>` の形で出力する。

`$ioRegions === []` の場合は早期リターンするので問題ない。
IO リージョンが1件でもあるページ（フォーム・ボタン等がある LP）で必ず発生する。

### 修正箇所: `lib/LpIoNeutralizer.php`

`applyNeutralization()` の `return $dom->saveHTML();` を以下に変更：

```php
$out = $dom->saveHTML();
// DOMDocument が XML PI を文書先頭に混入するのを除去
return str_replace('<?xml encoding="UTF-8">', '', $out);
```

---

## Bug 2: `tel:` リンクが内部ページ URL として扱われる

### 症状

生成 HTML の電話番号リンクが以下のように壊れる：
```html
<!-- 正しい -->
<a href="tel:0120-313-256">...</a>

<!-- 実際の出力 -->
<a href="https://hal-tanteisya.com/lp/tel:0120-313-256">...</a>
```

さらに `https://hal-tanteisya.com/lp/tel:0120-313-256` が内部ページ候補 URL と判定され、
`internal_3` 等のスロットが生成される。クリックインターセプターが電話リンクを内部ページへ
ルーティングしてしまい、電話発信不可になる。

### 根本原因（2か所）

**根本原因 A: `lib/LpAnalyzer.php` line 800-801**

```php
// 現状: '#' と 'javascript:' のみガード、'tel:' / 'mailto:' がない
$absHref = (!str_starts_with($href, '#') && !str_starts_with($href, 'javascript:'))
    ? $this->absolutizeUrl($href)
    : $href;
```

`tel:0120-313-256` がガードを通過して `absolutizeUrl()` → `LpUrlContext::resolve()` → `resolveRelativeUrl()` へ渡される。

**根本原因 B: `lib/LpUrlContext.php` `resolveRelativeUrl()` line 150**

```php
if (str_starts_with($url, 'data:') || str_starts_with($url, 'blob:')) {
    return $url;
}
// ← ここに tel: / mailto: / javascript: のガードがない
// → 相対パス解決ロジックへ落ちて /lp/tel:0120-313-256 になる
```

### 修正1: `lib/LpUrlContext.php` の `resolveRelativeUrl()` — 最も根本的な修正

line 150 の `data:` / `blob:` チェックの直後に追加：

```php
if (str_starts_with($url, 'data:') || str_starts_with($url, 'blob:')) {
    return $url;
}
// ★ 追加: tel: / mailto: / javascript: 等の非階層スキームはそのまま返す
if (preg_match('/^[a-zA-Z][a-zA-Z0-9+\-.]*:[^\/]/', $url)) {
    return $url;
}
```

正規表現の意味: スキーム名（英字始まり）の後に `:` + `/` 以外の文字が続くもの。
`http://` や `https://` や `//` は前段のチェックで処理済み。`data:` / `blob:` も前段で除外済み。
`tel:0120-...`, `mailto:foo@bar`, `javascript:void(0)`, `sms:+81...` 等すべてに対応する。

### 修正2: `lib/LpAnalyzer.php` line 800-801 — 呼び出し側の防御的修正

```php
// 現状
$absHref = (!str_starts_with($href, '#') && !str_starts_with($href, 'javascript:'))
    ? $this->absolutizeUrl($href)
    : $href;

// 修正後
$absHref = (!str_starts_with($href, '#')
    && !str_starts_with($href, 'javascript:')
    && !str_starts_with($href, 'tel:')
    && !str_starts_with($href, 'mailto:'))
    ? $this->absolutizeUrl($href)
    : $href;
```

`normalizeHrefForStorage()`（line 1016）はすでに `tel:` / `mailto:` ガードを持っているが、
上記 line 800 は直接 `absolutizeUrl()` を呼ぶため別途修正が必要。

---

## Bug 3: lazy-load 画像が `space.gif` のままになる

### 症状

`src="space.gif"` + `data-src="実画像.webp"` を使う遅延読み込み画像が
JS なしのクローン環境では空白のままになる。

### 修正状況

`commit bec9af6` で `LpGenerator::promoteLazyLoadAttributes()` を追加済み。
新規生成では自動修正される。ZIP ファイルの出力は修正前の生成物であるため乖離がある。

**追加確認:** `promoteLazyLoadAttributes()` が対応するパターン：

```php
// 現状の実装 (LpGenerator.php)
$dataSrc = $node->getAttribute('data-src')
    ?: $node->getAttribute('data-lazy-src')
    ?: $node->getAttribute('data-original');

if ($dataSrc !== '' && $tag === 'img') {
    $src = trim($node->getAttribute('src'));
    if ($src === '' || str_contains($src, 'space.gif')) {
        $node->setAttribute('src', $dataSrc);
    }
}
```

hal-tanteisya.com は `src="space.gif"` + `data-src="実URL"` パターンのため、このロジックで正しく対応される。
修正不要。

---

## 変更対象ファイル

| ファイル | 行 | 変更内容 |
|---------|-----|---------|
| `lib/LpIoNeutralizer.php` | ~143 | `saveHTML()` 出力から XML PI を除去 |
| `lib/LpUrlContext.php` | ~150 | `resolveRelativeUrl()` に非階層スキームガード追加 |
| `lib/LpAnalyzer.php` | ~800 | `absolutizeUrl()` 呼び出し前に `tel:` / `mailto:` チェック追加 |

---

## 確認方法

1. `https://hal-tanteisya.com/lp/uwaki` を再解析・再生成する
2. 生成 `index.html` の先頭が `<!DOCTYPE html>\n<html lang="ja">` になっていること（`<?xml` がないこと）
3. 電話リンクが `href="tel:0120-313-256"` のままであること
4. 内部ページ一覧に `tel:` が含まれていないこと
