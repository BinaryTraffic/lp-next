# generate_internal.php 低速問題 — 修正指示書

## 原因分析

`generate_internal.php` が 1件あたり 1.5〜2分かかる根本原因は `LpGenerator::applyAssetMap()` にある。

### 処理コスト内訳

```
generate() 呼び出し時
  ├─ processSection() × セクション数
  │    └─ DOMDocument::loadHTML / XPath / saveHTML（中程度）
  ├─ applyAssetMap()                                   ← 最大ボトルネック
  │    ├─ applyAbsoluteAssetReplacements()
  │    │    forEach asset in asset_map（例: 1000件）
  │    │      × LpUrlContext::httpHttpsAssetUrlVariants × 2 = 最大8バリアント生成
  │    │      × str_replace or preg_replace on full HTML  ← 繰り返し実行
  │    └─ applyRelativeAssetReplacements()
  │         forEach relative asset × preg_replace × 7属性
  └─ normalizeMalformedWindowsUrls()                   ← Linux で不要
       └─ preg_replace × 2（Windows URL 正規化）
```

**問題の核心：**
- asset_map.json はサイト全体のアセット（全ページ共通）を持つ
- 内部ページの HTML にはそのページで使うアセットのみ存在する
- **ページに存在しない URL に対しても str_replace / preg_replace を全件実行している**
- 例: アセット 1000件 × 4バリアント = 4000回の str_replace/preg_replace を 500KB の HTML に適用

---

## 修正方針

### 修正1: `normalizeMalformedWindowsUrls` を Linux でスキップ（ゼロリスク）

`lib/LpGenerator.php` の `normalizeMalformedWindowsUrls()` 冒頭に追加：

```php
private function normalizeMalformedWindowsUrls(string $html): string
{
    // This is a Windows-only artifact (PHP dirname() producing backslashes).
    // On Linux production, the method is a no-op.
    if (DIRECTORY_SEPARATOR !== '\\') {
        return $html;
    }

    $extra = '[]:_-';
    // ... 既存コード続く
```

---

### 修正2: `str_contains` ゲートを追加（最大の改善）

`applyAbsoluteAssetReplacements()` のループに `str_contains` 事前チェックを追加：

```php
private function applyAbsoluteAssetReplacements(string $html, array $expanded): string
{
    if ($expanded === []) {
        return $html;
    }
    uksort($expanded, static fn($a, $b) => strlen($b) - strlen($a));
    foreach ($expanded as $from => $to) {
        if ($from === '' || $to === '') {
            continue;
        }
        // ★ 追加: このページの HTML に含まれていなければスキップ
        if (!str_contains($html, $from)) {
            continue;
        }
        if (str_starts_with($from, '//')) {
            $qf = preg_quote($from, '~');
            $html = preg_replace('~(?<![/:])' . $qf . '~', $to, $html) ?? $html;
            $encFrom = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
            if ($encFrom !== $from) {
                $eq = preg_quote($encFrom, '~');
                $encTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
                $html = preg_replace('~(?<![/:])' . $eq . '~', $encTo, $html) ?? $html;
            }
        } else {
            $html = str_replace($from, $to, $html);
            $encFrom = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
            $encTo   = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
            if ($encFrom !== $from) {
                // ★ 追加: HTML エンコード版も事前チェック
                if (str_contains($html, $encFrom)) {
                    $html = str_replace($encFrom, $encTo, $html);
                }
            }
        }
    }

    return $html;
}
```

---

### 修正3: `applyRelativeAssetReplacements` にも同様のゲート追加

```php
private function applyRelativeAssetReplacements(string $html, array $expanded): string
{
    if ($expanded === []) {
        return $html;
    }
    uksort($expanded, static fn($a, $b) => strlen($b) - strlen($a));
    $attrs = ['href', 'src', 'poster', 'data-src', 'data-bg', 'data-lazy-src', 'data-original'];
    foreach ($expanded as $from => $to) {
        if ($from === '' || $to === '' || $from === $to) {
            continue;
        }
        if (str_starts_with($from, 'http://') || str_starts_with($from, 'https://') || str_starts_with($from, '//')) {
            continue;
        }
        // ★ 追加: HTML に含まれていなければ全属性ループをスキップ
        if (!str_contains($html, $from)) {
            continue;
        }
        $qf = preg_quote($from, '~');
        foreach ($attrs as $attr) {
            // ... 既存コードそのまま
```

---

## 期待効果

| 条件 | 処理数（before） | 処理数（after） | 削減率 |
|------|----------------|----------------|--------|
| アセット1000件 × 4バリアント、ページ内実在50件 | 4000回 | ~250回 | **93%削減** |
| `normalizeMalformedWindowsUrls`（Linux） | 2回 preg_replace | 0回 | 100%削減 |

理論上の時間: 2分 → **数秒〜十数秒** に改善見込み。

---

## 変更対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `lib/LpGenerator.php` | `normalizeMalformedWindowsUrls` に Linux スキップ追加、`applyAbsoluteAssetReplacements` と `applyRelativeAssetReplacements` に `str_contains` ゲート追加 |

**`store/generate_lp.php` / `store/generate_entry.php` / `store/generate_internal.php` は変更不要**

---

## 注意事項

- `str_contains` は PHP 8.0+ ネイティブ（既存コードが PHP 8.2 前提なので問題なし）
- `str_contains` は大文字小文字を区別するが、URL は通常小文字で統一されているため問題なし
- `uksort` による長いキー優先順序は維持すること（短いキーが長いキーの部分マッチで誤置換するのを防ぐため）
