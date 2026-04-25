# LP Reverse CMS — 開発経緯・成果とゼロからの構築手順

本ドキュメントは、これまでの実装の経緯と成果を整理し、**新しい環境にゼロから同じスタックを再現する**ための手順書です。  
アプリの技術概要とディレクトリ説明は [README.md](../README.md) にあります。

**バージョン管理:** 公式リポジトリは **<https://github.com/BinaryTraffic/lp-next>**（クローン: `https://github.com/BinaryTraffic/lp-next.git`）。プロジェクト表示名は LP-NEXT、製品名は「LP Reverse CMS」。

---

## 1. プロジェクトの目的

- 参照先の **公開 LP（URL）** から HTML を取得する。
- **DOM 解析**でセクション・見出し・段落・画像・リンクなどを抽出し、JSON に保存する。
- 管理画面で **顧客向け文言・画像 URL・リンク** を編集できるようにする。
- **CSS / 画像 / JS / フォント** をローカルに落とし、**静的な `output/index.html`** として再生成する。

すべて **PHP のみ**（DB 不要）で動く MVP として設計されています。

---

## 2. これまでの経緯と成果（要約）

### 2.1 フェーズ 1 — MVP（v1.0.x）

- cURL で HTML 取得、`DOMDocument` / `DOMXPath` で構造化。
- `lp_structure.json` / `client_data.json`、Bootstrap 5 の管理 UI。
- 素の PHP テンプレートで編集画面・プレビュー・エクスポート。

### 2.2 フェーズ 2 — アセット取得とローカル化（v1.1.0 〜）

- スタイルシート・画像・スクリプトを `output/assets/` に保存。
- `asset_map.json` で **絶対 URL → 相対パス** を記録し、生成 HTML に反映。
- `<head>` の `<link>` は **属性を落とさず** シリアライズして保持。

### 2.3 フェーズ 3 — URL・Windows・診断（v1.1.1 〜 v1.1.3）

- **Windows** で混入しうる `https://host\path` や `%5C` を生成時に正規化。
- **`<base href>`** と **CSS `url()`** からフォント等も取得。
- **`LpUrlContext`** で相対・プロトコル相対 URL を一貫して解決。
- **`store/debug.php`**（JSON）で map 件数・未取得・未置換・fetch 失敗を確認。
- 診断まわりの **パスパターン**（`assets/css` と `/assets/` の取り違え）や **未取得の誤検知**（`/img` と `/images`、Google Fonts ホスト差など）を修正。
- **favicon** の取得、favicon 用 **preconnect** のノイズ整理。

### 2.4 フェーズ 4 — 置換ロジックの堅牢化（v1.1.4 〜 v1.1.5）

- **`LpGenerator::applyAssetMap`**  
  - **絶対 URL** と **相対キー**を分離し、**絶対を先に**最長一致で置換（相対が `href` 内の長い文字列を壊す問題の防止）。
- **`//host/...`** を **`https://...` の途中の `//`** に誤マッチさせない（正規表現の `(?<![/:])`）。
- **相対キー**は **グローバル `str_replace` 禁止** → 引用符付き `href` / `src` 等と **`srcset` のみ**置換し、`assets/assets/...` の二重化を防止。
- **JSON 展開**で `https` から `//` を重複生成しない方針に整理。

### 2.5 フェーズ 5 — 診断の信頼性（v1.1.6）

- 未置換スキャンで **HTML コメント**を除去（コメント内 URL の誤検知防止）。
- **`debug.php` 表示時**に `output/index.html` を **再スキャンして `output_unreplaced.json` を更新**（永続 JSON と実ファイルのずれを防ぐ）。

### 2.6 フェーズ 6 — 編集 UI とヒーロー画像（v1.1.7 〜 v1.1.8）

- **`<picture>` 内の `<img>`** が編集対象に出てこない問題:
  - **`picture` をコンテナとして再帰**するよう変更。
- 元 HTML が **`<img>` の後に誤った `</source>`** があるなどで、パーサが **`<img>` を `<source>` の子**にしている場合:
  - **`source` もコンテナとして再帰**し、その内側の `img` を必ず走査。
- **`class` に `bg` を含む画像**はラベルを **「背景画像」** に。
- **`<source srcset>`** もセクション HTML 内で absolutize。

以上により、**「プレビューでは見えるが編集フォームにヒーロー／背景相当の画像が無い」**状態を解消しました。

---

## 3. 成果物（何ができれば完成か）

| 成果物 | 説明 |
|--------|------|
| `data/lp_structure.json` | 解析結果（セクション HTML・`data-lp-id` 付き要素一覧） |
| `data/client_data.json` | 編集した顧客向けデータ |
| `data/asset_map.json` | 元 URL とローカル `assets/...` の対応 |
| `output/index.html` | 再生成された静的 LP |
| `output/assets/{css,img,js,fonts}/` | 取得したアセット |
| `store/debug.php` | 診断用 JSON（ブラウザまたは API クライアントで取得） |

---

## 4. ゼロから構築する手順

### 4.1 前提条件

| 項目 | 推奨 |
|------|------|
| PHP | **8.0 以上**（`strict_types` 使用） |
| 拡張 | **curl**, **dom**, **json**, **mbstring**（標準的な XAMPP / パッケージで概ね有効） |
| 書き込み権限 | `lp_reverse_cms/data/` と `lp_reverse_cms/output/` に Web サーバー／PHP プロセスが書き込めること |

データベース・Composer・Node.js は **不要**です。

### 4.2 ソースの配置

1. リポジトリを **クローン**すると、**リポジトリルート**に `README.md`・`index.html`（入口）・**`lp_reverse_cms/`**（アプリ本体）が揃います。
2. Web サーバーの **DocumentRoot** は次のどちらかです（[ルート README.md](../../README.md) に表あり）。
   - **リポジトリルート** … `/` で入口 HTML、**`/lp_reverse_cms/`** で管理画面。
   - **`lp_reverse_cms` のみ** … `/` が直接管理画面（従来どおり）。
3. 初回は空でもよいですが、以下が **自動作成**されます（いずれの DocumentRoot でもアプリは `lp_reverse_cms/data` 等を使用）。
   - `lp_reverse_cms/data/`
   - `lp_reverse_cms/output/`
   - `lp_reverse_cms/output/assets/` 配下（アセット取得時）

### 4.2.1 DocumentRoot ＝ リポジトリルートのとき（URL の例）

先方がブラウザの **URL だけ**で辿れるようにする場合のイメージです（ホスト名・ポートは環境依存）。

| パス | 内容 |
|------|------|
| `/` | [リポジトリルートの index.html](../../index.html)（入口・リンク集） |
| `/README.md` | [ルート README.md](../../README.md)（Apache では多くの場合プレーン表示） |
| `/lp_reverse_cms/` | 管理画面（`index.php`） |
| `/lp_reverse_cms/docs/PROJECT_HISTORY_AND_SETUP.md` | 本ドキュメント |

Markdown は **HTML にレンダリングされず** `text/plain` のまま配信される想定です。読みやすくするには GitHub 上の表示か、ローカルエディタを使ってください。

### 4.3 ローカルで PHP ビルトインサーバーを使う（最短）

**パターン 1 — DocumentRoot をリポジトリルートにする**

```powershell
cd C:\path\to\lp-next
php -S localhost:8080
```

- 入口: `http://localhost:8080/`  
- 管理画面: `http://localhost:8080/lp_reverse_cms/`  

**パターン 2 — DocumentRoot を `lp_reverse_cms` のみにする**

```powershell
php -S localhost:8080 -t "C:\path\to\lp_reverse_cms"
```

ブラウザで **`http://localhost:8080`** を開きます（この場合はいきなり管理画面）。  
**`index.php` を `file://` で直接開かない**でください（`store/*.php` への相対パスが壊れます）。

### 4.4 Apache 等で運用する場合

- **VirtualHost の DocumentRoot** を **リポジトリルート**にするか、**`lp_reverse_cms` のみ**にするかを選ぶ（[ルート README.md](../../README.md) の「DocumentRoot の取り方」参照）。
- **`data/` を公開しない**（`lp_reverse_cms/.htaccess` の意図に沿って設定）。  
  ビルトインサーバーでは `.htaccess` は効かないため、本番では別途アクセス制御を推奨。

### 4.5 初回の動作確認フロー

1. 管理画面 **Step 1** で参照 LP の **HTTPS URL** を入力し **解析**する。  
   - 内部で HTML 取得・アセット DL・`lp_structure.json` 生成まで行われます（実装は `store/fetch_lp.php` など）。
2. **Step 2** で文言・画像 URL などを編集し、**保存＆LP生成**する。  
3. **Step 3** でプレビュー／エクスポート。  
4. 必要に応じて **`store/debug.php`** を開き、map 件数・未置換が想定どおりか確認する。

### 4.6 解析をやり直したとき

- **`elem_sec_*_*`（`data-lp-id`）の並びが変わる**ことがあります。  
- 既存の `client_data.json` のキーとズレる場合は、**必要な項目だけ入れ直す**か、バックアップから移してください。

### 4.7 リモートと手元が同じか（共同作業）

```bash
git fetch origin
git rev-parse HEAD
git ls-remote origin refs/heads/main
```

表示されるコミットハッシュの**先頭 7 文字**が一致すれば、GitHub 上の `main` と手元は同じ先端です。`git status` が `up to date with 'origin/main'` でも確認できます。

### 4.8 よくあるつまずき

| 現象 | 確認すること |
|------|----------------|
| 画面が真っ白 / 500 | PHP エラーログ、`php -v`、拡張モジュール |
| 解析は成功するがスタイルが無い | アセット取得の完了待ち、`output/assets/css` の有無、`debug.php` |
| favicon や CSS が未置換のまま | **保存＆LP生成**を再実行（`LpGenerator` の置換は生成時） |
| ヒーロー画像が編集に出ない | **v1.1.8 以降のコードで解析し直し**（`picture` / `source` 内の `img` 対応） |

---

## 5. ソースコードの読み順（理解用）

1. `index.php` — UI とステップ制御  
2. `store/fetch_lp.php` — 取得 + ダウンローダ  
3. `store/analyze_lp.php` — 解析エンドポイント  
4. `lib/LpAnalyzer.php` — セクション抽出・編集可能要素・`data-lp-id`  
5. `lib/LpAssetDownloader.php` — DOM / CSS から URL 収集と保存  
6. `lib/LpGenerator.php` — 顧客データの反映 + `asset_map` 適用  
7. `lib/LpOutputAudit.php` — 生成 HTML の未置換スキャン  
8. `store/debug.php` — 診断 JSON  

---

## 6. バージョン

アプリの版は **`index.php` の `APP_VERSION`** が正とします。詳細な変更履歴の表は [README.md](../README.md) を参照してください。

---

*この文書は LP Reverse CMS の「経緯・成果・再構築手順」を 1 か所に集約したものです。*
