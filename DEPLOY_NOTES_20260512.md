# デプロイ留意点 2026-05-12

## 現状の方向性

```
GCP (最新) ──PULL必要──► Google Drive (古い)
```

通常のデプロイは `Google Drive → GCP` だが、現在は **GCPが167ファイル分新しい**。
Google Drive から deploy.sh を実行すると GCP の変更が上書き消去される。

---

## GCP → Google Drive PULL コマンド

WSL から実行：

```bash
rsync -avz \
  --exclude='.env' \
  --exclude='data/' \
  --exclude='output/' \
  gcp-lp:/home/lp-next/current/lp_reverse_cms/ \
  /mnt/h/マイドライブ/projects/lp-next/current/lp_reverse_cms/
```

> **注意**: `--delete` は付けない（GCP側の data/ output/ を誤削除しないため）

---

## 今回のセッションで行った修正（GCPに適用済み・未コミット）

### 1. `lib/LpAnalyzer.php` ★最重要

#### バグ①: `bestPictureSourceSrcset()` — デスクトップ画像の上書き問題

**症状**: `<picture>` 要素のスマホ専用 `<source media="(max-width:768px)" srcset="splp_ohp_01.jpg">` の値が、デスクトップ向け `<img src="lp_ohp_01.jpg">` に上書きされていた。結果としてデスクトップで全セクションの背景画像がスマホ用小画像になりレイアウト崩壊。

**修正内容**: `max-width` のみを含む media query を持つ `<source>` をスキップするよう変更。

```php
// 追加条件（修正箇所）
if ($media !== '' && preg_match('/max-width/i', $media) && !preg_match('/min-width/i', $media)) {
    continue;
}
```

#### バグ②: `$extraCssContent` が `head_extra` に混入

**症状**: 外部 CSS ファイルのテキスト（385KB）が `<style>` タグなしで `<head>` に埋め込まれ、ブラウザが body コンテンツとしてレンダリングしていた。

**修正内容**: 外部 CSS は background-image 検出用の `$cssHaystack` としてのみ使用し、`head_extra`（生成 HTML に埋め込まれる）には含めないよう分離。

```php
$headExtra   = $this->extractHeadExtra($xpath);
$cssHaystack = $extraCssContent !== '' ? $headExtra . "\n" . $extraCssContent : $headExtra;
// extractSections には $cssHaystack を渡す（haystackのみ）
// lp_structure.json の head_extra には $headExtra のみ保存
```

#### 追加: `$extraCssContent` パラメーター

`analyze()` の引数に `string $extraCssContent = ''` を追加。`analyze_worker.php` からダウンロード済み CSS ファイルを結合して渡す。

---

### 2. `tools/analyze_worker.php`

`analyze()` 呼び出し前にダウンロード済み CSS ファイルを結合して `$extraCss` に格納し、background-image 検出精度を向上。

```php
$extraCss = '';
foreach (glob($cssDir . '/*.css') ?: [] as $cssFile) {
    $fsize = @filesize($cssFile);
    if ($fsize > 0 && $fsize < 600_000) {
        $content = @file_get_contents($cssFile);
        if ($content !== false) {
            $extraCss .= $content . "\n";
        }
    }
}
$structure = $analyzer->analyze($html, $finalUrl, null, 0.0, $extraCss);
```

---

### 3. `store/analyze_start.php`

mod_php 環境では `PHP_BINARY` が `.so` ファイルになるため、CLI バイナリを `which php8.2` で解決するフォールバックを追加。

---

## 今回のインシデント：/tmp ファイルによるワーカー起動阻害

**発生**: デバッグ中に `shimizu` 所有の `/tmp/analyze_worker_debug.log` が作成され、`www-data` が書き込めない状態になった。

`analyze_start.php` が `nohup ... > /tmp/analyze_worker_debug.log 2>&1 &` を実行する際、リダイレクト先に書き込み不可だとシェルがコマンド自体を起動しない（bash の仕様）。

**解決**: `sudo rm /tmp/analyze_worker_debug.log` で削除。

**再発防止案**: ログ出力先を `/tmp/analyze_worker_debug.log`（固定）から `/tmp/analyze_worker_{task_id}.log` に変更するか、`/dev/null` にする。

---

## GCP側に存在するがGoogleDriveにない新規ファイル

```
store/recompute_industry.php   （新規）
store/scan_link_depth.php      （新規）
```

PULL 後に Google Drive 側に追加される。

---

## PULL 後の確認事項

1. `lib/LpAnalyzer.php` — 上記2つのバグ修正が含まれているか
2. `tools/analyze_worker.php` — `$extraCss` 処理が含まれているか  
3. `store/analyze_start.php` — PHP_BINARY フォールバックが含まれているか
4. `data/` `output/` は rsync 対象外のため Google Drive に反映されない（正常）
5. `.env` は rsync 対象外のため上書きされない（正常）
