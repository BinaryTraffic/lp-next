# Cursor実装指示：LP Reverse CMS v1.2.0 解像度向上版

## 目的

現在の v1.1.11 を安定版として維持したまま、v1.2.0 では参照LPの再現精度を上げる。

主な目的は以下。

- CSS内 url(...) の取得・保存・置換
- @import CSS の取得
- srcset / data-src / lazyload 系画像の取得
- base href 対応
- 未取得アセット・未置換URLの診断強化
- output/index.html の見た目再現率向上

---

## 前提

- PHP 8.x
- 既存構成は維持
- 既存の関数名・クラス名・ファイル名は原則変更しない
- v1.1.11 の動作を壊さない
- まずは差分改修で進める
- 既存機能の省略は禁止

---

## バージョン更新

`index.php` の APP_VERSION を以下に更新する。

```php
const APP_VERSION = '1.2.0';
```

README.md に v1.2.0 の履歴を追加する。

```md
| 1.2.0 | CSS内 url() / @import / srcset / lazyload / base href 対応強化、debug診断強化 |
```

---

## 改修対象

主に以下を改修する。

```text
lib/LpAssetDownloader.php
lib/LpGenerator.php
store/debug.php
README.md
```

必要なら補助クラスを追加してよい。

```text
lib/LpUrlResolver.php
```

ただし既存クラスを壊さず、最小変更で実装する。

---

# 1. URL解決処理の共通化

## 要件

相対URLを絶対URLへ変換する処理を共通化する。

対応する形式：

```text
https://example.com/a.css
http://example.com/a.css
//example.com/a.css
/assets/a.css
./assets/a.css
../assets/a.css
assets/a.css
```

さらに `<base href="">` がある場合は優先する。

## 実装方針

`LpAssetDownloader` 内に既存のURL解決処理がある場合は、それを拡張する。

可能なら以下のようなメソッドにする。

```php
private function resolveUrl(string $url, string $baseUrl): ?string
```

戻り値は絶対URL。

ただし以下はスキップ。

```text
data:
blob:
mailto:
tel:
javascript:
#
```

---

# 2. base href 対応

## 要件

HTML内に `<base href="">` がある場合、アセット取得時の基準URLとして使う。

## 対象

```html
<base href="https://example.com/lp/">
```

## 実装方針

DOMDocument で base タグを取得し、存在する場合は `$baseUrl` として使用する。

存在しない場合は従来通り source_url を基準にする。

---

# 3. CSS内 url(...) 対応

## 要件

CSSファイルを保存した後、CSS本文内の `url(...)` を解析し、画像・フォント等を取得する。

対象例：

```css
background-image: url("../img/main.jpg");
background: url('/assets/bg.webp') no-repeat;
@font-face {
  src: url("./font.woff2") format("woff2");
}
```

## 対象拡張子

```text
jpg
jpeg
png
gif
webp
svg
ico
woff
woff2
ttf
otf
eot
```

## 除外

```text
data:
blob:
about:
#
```

## 実装方針

CSS内のURLを抽出する。

```php
preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $css, $matches);
```

抽出したURLを、CSSファイル自身のURLを基準に絶対URL化する。

取得後、CSS内の `url(...)` をローカルパスへ置換する。

output構成は以下を維持する。

```text
output/assets/css/
output/assets/img/
output/assets/js/
output/assets/font/
```

フォントは可能なら以下へ保存する。

```text
output/assets/font/
```

---

# 4. CSS @import 対応

## 要件

CSS内の `@import` を検出し、対象CSSも取得する。

対象例：

```css
@import url("common.css");
@import "layout.css";
```

## 実装方針

以下を検出する。

```php
preg_match_all('/@import\s+(?:url\()?["\']?([^"\')]+)["\']?\)?/i', $css, $matches);
```

取得したCSSも `output/assets/css/` に保存する。

さらに、そのCSS内の `url(...)` も再帰的に解析する。

無限ループ防止のため、取得済みURLは配列で管理する。

```php
$downloadedUrls[$absoluteUrl] = true;
```

---

# 5. img srcset 対応

## 要件

`srcset` 内の複数画像をすべて取得する。

対象例：

```html
<img srcset="small.jpg 480w, large.jpg 1200w">
```

## 実装方針

カンマで分解し、各項目の先頭URLだけ抽出する。

```php
$items = explode(',', $srcset);
```

各URLを取得してローカルパスへ置換する。

属性の構造は維持する。

```html
srcset="assets/img/small.jpg 480w, assets/img/large.jpg 1200w"
```

---

# 6. lazyload画像対応

## 要件

以下の属性も画像取得対象にする。

```text
data-src
data-original
data-lazy
data-lazy-src
data-bg
data-background
```

## 実装方針

imgタグ以外の要素にも存在する可能性があるため、XPathで属性検索する。

---

# 7. picture/source 対応

## 要件

以下も取得対象にする。

```html
<picture>
  <source srcset="image.webp" type="image/webp">
  <img src="image.jpg">
</picture>
```

対象：

```text
source[srcset]
source[src]
```

---

# 8. LpGenerator の置換強化

## 要件

`asset_map.json` を使って、生成HTML内のURL置換精度を上げる。

対応する表記ゆれ：

```text
https://host/path
http://host/path
//host/path
https:\/\/host\/path
host%5Cpath
https://host\path
```

## 実装方針

既存のWindows由来の `\` 正規化処理を維持しつつ、置換前にURLを正規化する。

ただし `data:` `mailto:` `tel:` は置換対象外。

---

# 9. debug.php 強化

## 表示する項目

```text
APP_VERSION
source_url
asset_map 件数
CSS 件数
画像件数
JS 件数
フォント件数
未取得URL一覧
未置換URL一覧
CSS内未置換 url(...) 一覧
@import 未解決一覧
```

## 未置換URLチェック対象

```text
output/index.html
output/assets/css/*.css
```

チェックする文字列：

```text
http://
https://
//
%5C
\
```

ただし以下は除外：

```text
data:
mailto:
tel:
javascript:
```

---

# 10. 完了条件

以下を満たすこと。

1. v1.1.11 の基本動作が壊れていない
2. URL取得 → 解析 → 編集 → 生成 が通る
3. CSS内背景画像が取得される
4. Webフォントが取得される
5. srcset画像が取得される
6. lazyload画像が取得される
7. debug.php で未取得・未置換が確認できる
8. README.md に v1.2.0 の変更点が追記される

---

# 11. 注意

- 既存ファイル名・関数名はむやみに変更しない
- 既存ロジックを削除しない
- まず差分改修で対応する
- 処理が長くなる場合はコメントを残す
- 取得失敗しても全体処理を止めず、debug用に記録する

---

# 12. 安定版管理・Git運用

v1.1.11 は安定版としてタグを切る。

```bash
git status
git add .
git commit -m "stable: v1.1.11"
git tag v1.1.11
git push origin main
git push origin v1.1.11
```

v1.2.0 の作業はブランチを切って行う。

```bash
git checkout -b feature/v1.2-assets-resolution
```

完成後は以下。

```bash
git add .
git commit -m "release: v1.2.0 improve asset resolution"
git tag v1.2.0
git push origin feature/v1.2-assets-resolution
git push origin v1.2.0
```

基本はバージョンごとにフォルダを増やすのではなく、Git tag で管理する。

ローカル保険としてフォルダ保存したい場合のみ、以下のようにする。

```text
LP-ReverseCMS/
├── current/
└── releases/
    ├── v1.1.11/
    └── v1.2.0/
```
