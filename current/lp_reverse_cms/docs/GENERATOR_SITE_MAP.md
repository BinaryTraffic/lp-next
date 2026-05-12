# LpGenerator — site_map.json 参照による output/ 生成 実装指示書

## 現状と目標

**現状:** `LpGenerator` は `lp_structure.json` + `client_data.json` から `output/index.html` を1ファイル生成する

**目標:** `site_map.json` を参照し、`pages` の全エントリに対応した `output/` ディレクトリ構造を生成する

---

## output/ ディレクトリ構造（site_map.json と 1:1 対応）

```
output/
├── index.html                ← pages["index"]    (coordinate: entry)
├── internal_0/
│   └── index.html            ← pages["internal_0"] (coordinate: internal[0])
├── internal_1/
│   └── index.html            ← pages["internal_1"] (coordinate: internal[1])
├── internal_N/
│   └── index.html
└── assets/
    ├── css/
    ├── img/
    ├── js/
    └── fonts/
```

`local_path` フィールドがそのまま出力先パスになる。

---

## generate_lp.php の変更方針

`store/generate_lp.php` を以下の順で処理するよう変更する：

```
1. site_map.json を読み込む
2. site_map.json が存在しない場合 → 従来通り lp_structure.json にフォールバック
3. pages を順番に処理
   ├─ status === "ok"  → HTML 生成
   └─ status === "error" → スキップ（ログに記録）
4. 各ページの local_path にディレクトリを作成して index.html を書き出す
5. site_map.json の generated_at を更新する
```

---

## LpGenerator.php の変更方針

### 新メソッド: `generateFromSiteMap()`

```php
/**
 * site_map.json を参照して output/ 以下の全ページを生成する
 *
 * @param array<string,mixed> $siteMap   site_map.json の内容
 * @param array<string,mixed> $clientData client_data.json の内容
 * @param string $outputDir              output/ の絶対パス
 * @param array<string,string> $assetMap asset_map.json の内容
 * @return array{generated: int, skipped: int, errors: list<string>}
 */
public function generateFromSiteMap(
    array $siteMap,
    array $clientData,
    string $outputDir,
    array $assetMap
): array
```

処理フロー：

```
foreach $siteMap['pages'] as $key => $page:

  1. status チェック
     - "error" → $skipped++ / coordinateをログ記録 / continue

  2. 出力パス確定
     $localPath = $outputDir . '/' . ltrim($page['local_path'], 'output/')
     例: output/index.html → {outputDir}/index.html
         output/internal_0/index.html → {outputDir}/internal_0/index.html

  3. ディレクトリ作成
     mkdir(dirname($localPath), 0755, true) if not exists

  4. HTML 生成
     - $page['sections'] からセクション HTML を組み立てる
     - client_data.json の該当エントリで編集値を上書き
     - applyAssetMap() でローカルアセットパスに置換

  5. data_io_regions の neutralize 適用
     - $page['data_io_regions'] を参照
     - フォームの action="#" 書き換え
     - data-lp-io-type / data-lp-io-original-action / data-lp-io-coordinate 付与

  6. ファイル書き出し
     file_put_contents($localPath, $html)
     $generated++
```

---

## data_io_regions の HTML 適用（LpIoNeutralizer の拡張）

`LpIoNeutralizer` に以下のメソッドを追加する：

```php
/**
 * HTML 文字列に data_io_regions を適用して無効化・属性付与を行う
 *
 * @param string $html
 * @param list<array<string,mixed>> $ioRegions
 * @return string 処理済み HTML
 */
public static function applyNeutralization(string $html, array $ioRegions): string
```

処理内容：

```
foreach $ioRegions as $region:

  type === "contact_form" | "newsletter" | "login":
    - <form ... action="元URL"> を <form ... action="#"> に書き換え
    - data-lp-io-type="{type}" を追加
    - data-lp-io-original-action="{original_action}" を追加
    - data-lp-io-coordinate="{coordinate}" を追加

  type === "payment":
    - 決済ボタン要素に data-lp-io-type="payment" を追加
    - onclick / data-stripe 等の JS ハンドラ属性を除去

  type === "external_embed":
    - <iframe> を以下のプレースホルダーに置換:
      <div data-lp-io-type="external_embed"
           data-lp-io-coordinate="{coordinate}"
           style="background:#f0f0f0;padding:2rem;text-align:center">
        [外部コンテンツ: 後付け実装が必要]
      </div>
```

---

## site_map.json の更新

全ページ生成完了後に `site_map.json` を更新する：

```json
{
  "meta": {
    "...既存フィールド...",
    "generated_at": "2026-05-07T10:00:00Z",
    "generated_pages": 20,
    "skipped_pages": 1
  },
  "pages": {
    "index": {
      "...既存フィールド...",
      "status": "generated"
    },
    "internal_10": {
      "...既存フィールド...",
      "status": "error"
    }
  }
}
```

`status` の値に `"generated"` を追加する。

---

## エラーハンドリング

| ケース | 対応 |
|--------|------|
| `site_map.json` が存在しない | `lp_structure.json` にフォールバックして従来の単一ページ生成 |
| page.status === "error" | スキップ・ログ記録・generated_at には含めない |
| ディレクトリ作成失敗 | 例外をスロー・該当ページを error として記録 |
| セクション HTML が空 | 空ページとして生成（スキップしない） |

---

## generate_lp.php のレスポンス変更

```json
{
  "ok": true,
  "generated": 19,
  "skipped": 1,
  "skipped_coordinates": ["internal[10]"],
  "preview_url": "/current/lp_reverse_cms/output/index.html"
}
```

---

## 変更対象ファイル

| ファイル | 変更種別 | 内容 |
|---------|---------|------|
| `lib/LpGenerator.php` | 修正 | `generateFromSiteMap()` メソッド追加 |
| `lib/LpIoNeutralizer.php` | 修正 | `applyNeutralization()` メソッド追加 |
| `lib/LpSiteMapper.php` | 修正 | `generated_at` / `status: generated` 更新メソッド追加 |
| `store/generate_lp.php` | 修正 | site_map.json 参照フロー追加・フォールバック維持 |

---

## 注意事項

- `lp_structure.json` フォールバックは必ず残すこと（既存の単一ページワークスペースとの後方互換）
- `output/assets/` は既存のまま共有する（各ページから `../assets/` で参照）
- 内部ページの HTML 内の相対パス（`../` 等）は `local_path` の深さに応じて調整すること
- `open_basedir` は `/home/lp-next:/tmp` のためパス操作は必ずこの範囲内で行う
