# 2フェーズ解析 実装指示書

## 背景と目的

`analyze_lp.php` は1リクエストで以下を実行している：
1. エントリページ解析（LpAnalyzer + LpMapper）
2. 内部ページ20件を順番に取得・解析・アセットDL（LpInternalPagesPipeline::run）
3. 画像メモ付与・業種推定・lp_structure.json 保存

20件の内部ページ処理がApache Timeout（300秒）を超えてタイムアウトする。

**解決策：** 生成フェーズと同じ「1リクエスト = 1ページ」方式に分割する。

---

## 全体フロー

```
Phase 1: store/analyze_entry.php
  → エントリページのみ解析
  → 内部ページ候補URL一覧を data/internal_candidate_urls.json に保存
  → lp_structure.json を internal_pages:[] で保存
  → site_map.json に index=ok / internal_N=pending で保存

Phase 2: store/analyze_internal_page.php（1リクエスト = 1ページ）
  → POST {"index": 0}
  → 1件分を取得・アセットDL・解析
  → lp_structure.json の internal_pages[N] を追記
  → site_map.json の pages["internal_N"].status を更新

Finalize: store/finalize_analyze.php
  → patchInternalRelativeHrefs（内部リンクの相対パス付与）
  → 画像メモ付与（lp_reverse_enrich_structure_image_text_memos）
  → 業種推定（lp_reverse_suggest_industries_from_structure）
  → lp_structure.json / site_map.json を最終保存

フロントエンド（assets/js/index.js）が順番に呼び出す
```

---

## Phase 0：候補URL一覧フォーマット

`data/ws_xxx/internal_candidate_urls.json`

```json
{
  "entry_url": "https://example.com/",
  "urls": [
    {"index": 0, "canonical_url": "https://example.com/page-a", "status": "pending"},
    {"index": 1, "canonical_url": "https://example.com/page-b", "status": "processed"},
    {"index": 2, "canonical_url": "https://example.com/page-c", "status": "error"}
  ],
  "total": 3,
  "processed": 1,
  "pending": 1,
  "error": 1
}
```

`status` は `pending` / `processed` / `error` の3値。

---

## LpInternalPagesPipeline への追加メソッド（lib/LpInternalPagesPipeline.php）

既存の `run()` は維持したまま、以下2メソッドを追加する。

### 追加1: `extractCandidateUrls()`

```php
/**
 * エントリ構造から内部ページ候補 URL を返す（重複除去・MAX_PAGES 制限済み）
 *
 * @param array<string,mixed> $structure analyze 済みエントリ構造
 * @param string $entryUrl エントリ URL（エントリ自身を除外するため）
 * @return list<string> 正規化済み URL リスト
 */
public static function extractCandidateUrls(array $structure, string $entryUrl): array
{
    $entryCanon = LpUrlContext::canonicalHttpDocumentIdentity($entryUrl);
    $urls = self::collectInternalDocumentUrlsFromEntryStructure($structure);
    $urls = array_values(array_filter($urls, static fn(string $u): bool => $u !== $entryCanon));
    $urls = array_values(array_unique($urls));
    sort($urls);
    return array_slice($urls, 0, self::MAX_PAGES);
}
```

`collectInternalDocumentUrlsFromEntryStructure()` は `private static` → **`public static` に変更する**（または `extractCandidateUrls` 内でコピー実装）。

---

### 追加2: `processSingleUrl()`

```php
/**
 * 内部ページ 1 件を取得・解析してマニフェストエントリを返す
 *
 * @param string $canonUrl    正規化済み URL
 * @param string $dataDir     data/ws_xxx/（末尾スラッシュ含む）
 * @param string $outputDir   output/ws_xxx/（末尾スラッシュ含む）
 * @return array{
 *   fetch_ok: bool,
 *   canonical_url: string,
 *   source_canonical: string,
 *   structure_file: string|null,
 *   section_count: int,
 *   asset_new_downloads: int,
 *   error?: string
 * }
 */
public static function processSingleUrl(
    string $canonUrl,
    string $dataDir,
    string $outputDir
): array
{
    // 既存の run() ループ内の 1 イテレーション分をそのままメソッド化する
    // visited_identity の共有は不要（各リクエストは独立。重複チェックは省略 or 候補URL側で制御）

    $dataDir   = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR;
    $outputDir = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR;

    if (!is_dir($dataDir . 'internal_pages')) {
        mkdir($dataDir . 'internal_pages', 0755, true);
    }

    $assetPath = $dataDir . 'asset_map.json';
    $existing  = [];
    if (is_readable($assetPath)) {
        $dec = json_decode((string) file_get_contents($assetPath), true);
        if (is_array($dec)) {
            $existing = $dec;
        }
    }

    $fetcher    = new LpFetcher();
    $downloader = new LpAssetDownloader($outputDir);
    $analyzer   = new LpAnalyzer();
    $mapper     = new LpMapper();

    try {
        $res      = $fetcher->fetch($canonUrl);
        $html     = $res['html'];
        $finalUrl = $res['final_url'];
        $identity = LpUrlContext::canonicalHttpDocumentIdentity($finalUrl);
        $slug     = self::slugForCanonical($canonUrl);

        $newMap = $downloader->downloadAll($html, $finalUrl, $existing, [
            'max_new_downloads'    => self::INTERNAL_ASSET_MAX_NEW_DOWNLOADS,
            'max_elapsed_seconds'  => self::INTERNAL_ASSET_MAX_ELAPSED_SECONDS,
        ]);
        $merged = array_merge($existing, $newMap);
        file_put_contents(
            $assetPath,
            json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $sub = $analyzer->analyze($html, $finalUrl);
        unset($sub['parse_diagnostics']);
        $sub = $mapper->enrich($sub);
        $sub['internal_pages'] = [];

        $structureRel = 'internal_pages/' . $slug . '.json';
        self::storagePut(
            $dataDir . $structureRel,
            json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return [
            'fetch_ok'            => true,
            'canonical_url'       => $identity,
            'source_canonical'    => $canonUrl,
            'structure_file'      => $structureRel,
            'final_fetch_url'     => $finalUrl,
            'section_count'       => count($sub['sections'] ?? []),
            'asset_new_downloads' => $downloader->getNewDownloadCount(),
            'asset_sync_limited'  => $downloader->hasExceededBudget(),
        ];
    } catch (Throwable $e) {
        return [
            'fetch_ok'       => false,
            'canonical_url'  => $canonUrl,
            'source_canonical' => $canonUrl,
            'structure_file' => null,
            'section_count'  => 0,
            'asset_new_downloads' => 0,
            'error'          => $e->getMessage(),
        ];
    }
}
```

---

## store/analyze_entry.php（新規作成）

**役割:** エントリページのみ解析し、内部ページ候補一覧を返す

```
POST /store/analyze_entry.php
Body: {"stream_progress": true} （オプション、NDJSON ストリーム）

処理:
1. data/fetched.html / data/source_url.txt を読む
2. LpAnalyzer::analyze() → エントリ構造
3. LpMapper::enrich()
4. LpLinkRedirectVerifier::verifyAndAnnotate()
5. LpInternalPagesPipeline::extractCandidateUrls() で内部候補 URL リスト取得
6. data/internal_candidate_urls.json を保存
7. lp_structure.json を internal_pages:[] で保存
8. site_map.json を構築:
   - pages["index"].status = "ok"
   - pages["internal_0"] 〜 ["internal_N"].status = "pending"（座標・source_url のみ）
9. industry_suggest.json を保存（エントリのみで推定）

レスポンス（JSON または NDJSON terminal）:
{
  "ok": true,
  "section_count": 10,
  "total_elements": 2130,
  "internal_count": 20,
  "internal_candidate_urls": [
    {"index": 0, "canonical_url": "https://..."},
    ...
  ]
}
```

**set_time_limit(0)** を先頭に設定すること。

---

## store/analyze_internal_page.php（新規作成）

**役割:** 内部ページ1件を取得・解析する

```
POST /store/analyze_internal_page.php
Body: {"index": 0}

処理:
1. data/internal_candidate_urls.json を読む
2. urls[index] を取得（存在しない → 404）
3. status === "error" はスキップして error 返却
4. LpInternalPagesPipeline::processSingleUrl() を呼ぶ
5. lp_structure.json の internal_pages[index] に追記（LOCK_EX）
6. data/internal_candidate_urls.json の urls[index].status を更新
7. site_map.json の pages["internal_N"].status を ok or error に更新

レスポンス:
{
  "ok": true,
  "index": 0,
  "key": "internal_0",
  "canonical_url": "https://...",
  "section_count": 8,
  "asset_new_downloads": 12
}
```

**set_time_limit(0)** を先頭に設定すること。

`lp_structure.json` への追記は **LOCK_EX** で排他制御すること（並列リクエストはないが念のため）。

---

## store/finalize_analyze.php（新規作成）

**役割:** 全内部ページ解析後の後処理

```
POST /store/finalize_analyze.php

処理:
1. lp_structure.json を読む
2. LpInternalPagesPipeline::patchInternalRelativeHrefs() で内部リンク修正
   ※ patchInternalRelativeHrefs を public static に変更すること
3. lp_reverse_enrich_structure_image_text_memos() で画像メモ付与
4. lp_reverse_suggest_industries_from_structure() で業種推定
5. lp_structure.json を最終保存
6. site_map.json を LpSiteMapper::build() で再構築・保存

レスポンス:
{
  "ok": true,
  "internal_pages_ok": 18,
  "internal_pages_error": 2
}
```

---

## store/list_analyze_internals.php（新規作成）

```
GET /store/list_analyze_internals.php

レスポンス:
{
  "ok": true,
  "entry_url": "https://example.com/",
  "urls": [
    {"index": 0, "canonical_url": "https://...", "status": "pending"},
    {"index": 1, "canonical_url": "https://...", "status": "processed"},
    ...
  ],
  "total": 20,
  "processed": 1,
  "pending": 18,
  "error": 1
}
```

---

## フロントエンド（assets/js/index.js）の変更方針

現在の「解析する」ボタンのフローを以下に変更する。
`analyze_lp.php` へのフォールバックを維持すること。

```
① analyze_entry.php を呼ぶ（NDJSON ストリームで進捗表示）
  → 成功: internal_count を取得
  → 失敗: analyze_lp.php にフォールバック（従来フロー）

② internals を順番に analyze_internal_page.php へ POST
  ├─ 1件完了 → プログレスバー更新
  ├─ error はスキップ表示
  └─ AbortController で停止可能（FIX_ABORT_GENERATE.md と同様）

③ finalize_analyze.php を呼ぶ（画像メモ・業種推定・最終保存）

④ 解析完了 → step2（コンテンツ編集）へ進む
```

**進捗表示:**
```
[解析中] エントリページ解析...
[===========>        ] 11 / 20 内部ページ解析中... (https://example.com/page-k)
[最終処理] 画像メモ・業種推定を処理しています...
```

**フォールバック判定:**
```js
// analyze_entry.php が 200 を返したら2フェーズモード
// 400 / 404 / ネットワークエラーなら analyze_lp.php にフォールバック
```

---

## LpInternalPagesPipeline への変更まとめ

| メソッド | 変更内容 |
|---------|---------|
| `collectInternalDocumentUrlsFromEntryStructure()` | `private` → `public static` に変更 |
| `patchInternalRelativeHrefs()` | `private` → `public static` に変更 |
| `extractCandidateUrls()` | **新規追加** |
| `processSingleUrl()` | **新規追加** |
| `run()` | **変更なし**（既存のフォールバック用） |

---

## 変更・作成対象ファイル

| ファイル | 種別 | 内容 |
|---------|------|------|
| `store/analyze_entry.php` | 新規 | Phase 1: エントリのみ解析 |
| `store/analyze_internal_page.php` | 新規 | Phase 2: 内部ページ1件解析 |
| `store/finalize_analyze.php` | 新規 | Phase 3: リンクパッチ・メモ・業種・最終保存 |
| `store/list_analyze_internals.php` | 新規 | 候補URL一覧と status 返却 |
| `lib/LpInternalPagesPipeline.php` | 修正 | extractCandidateUrls / processSingleUrl 追加、2メソッドを public static に変更 |
| `assets/js/index.js` | 修正 | 2フェーズ解析フロー + プログレスバー |
| `store/analyze_lp.php` | **維持** | フォールバック用（変更なし） |

---

## 注意事項

- `set_time_limit(0)` は analyze_entry / analyze_internal_page / finalize_analyze すべてに設定
- `lp_structure.json` への partial write（`analyze_internal_page.php` で `internal_pages[N]` を追記）は
  **read → modify → LOCK_EX write** のパターンで実装する（並列ではないが安全のため）
- `open_basedir` は `/home/lp-next:/tmp` のため、パスは必ずこの範囲内
- `site_map.json` の `pages["internal_N"]` の初期構造は analyze_entry.php で作成する：
  ```json
  "internal_0": {
    "source_url": "https://...",
    "coordinate": "internal[0]",
    "local_path": "output/ws_xxx/internal_0/index.html",
    "status": "pending",
    "sections": [],
    "data_io_regions": [],
    "dynamic_regions": []
  }
  ```
- Apache Timeout 3600 は別途 vhost に追加すること（タイムアウト根本対策）
