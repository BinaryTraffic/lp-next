# Site Reverse CMS（lp-next）ゼロから構築プロンプト v1.5

生成日: 2026-05-11  
対象バージョン: v1.5.0  
リポジトリ: https://github.com/BinaryTraffic/lp-next

---

## プロジェクト概要

「任意のWebサイトのLP（ランディングページ）をURLで指定するだけで、
そのデザイン・レイアウトを保ちながら、別業種向けの新しいLPをAIが自動生成する」
PHPフルスクラッチWebアプリ。

**例:** 美容室のLPデザインを参考に、買取店のLPを自動生成する

---

## 技術スタック

| 項目 | 内容 |
|------|------|
| 言語 | PHP 8.x（Composer不使用・フルスクラッチ） |
| PHP拡張 | curl / dom / json / mbstring / gd |
| Webサーバー | Linux / Apache2 |
| DB | **なし**（JSONファイルで全管理） |
| 認証 | Google OAuth 2.0 |
| AI | Anthropic Claude API（Vision含む）/ HuggingFace FLUX |
| フロントエンド | Bootstrap 5 / バニラJS |
| リポジトリ | https://github.com/BinaryTraffic/lp-next（mainブランチ） |

---

## ディレクトリ構成

```
/home/lp-next/                          ← DocumentRoot（Apache）
└── current/
    └── lp_reverse_cms/                 ← CMSルート
        ├── index.php                   ← 管理画面エントリ（要Google認証）
        ├── preview.php                 ← 生成LPプレビュー
        ├── export.php                  ← ZIPエクスポート
        ├── get_site_map.php
        ├── .env                        ← 秘密情報（gitignore）
        ├── assets/
        │   ├── css/index.css           ← 管理UI CSS
        │   └── js/
        │       ├── index.js            ← 管理UI JS
        │       └── workspace_manage.js
        ├── lib/                        ← コアクラス群（後述）
        ├── store/                      ← Ajax APIエンドポイント群（後述）
        ├── template/                   ← PHPテンプレート
        │   ├── editPage.php
        │   └── generated_lp.php
        ├── tools/                      ← CLIワーカー
        │   ├── analyze_worker.php
        │   ├── generate_worker.php
        │   ├── maintain_workspaces.php
        │   └── workspace_delete_async_worker.php
        ├── data/                       ← ワークスペースデータ（gitignore）
        │   ├── ws_{32hex}/             ← セッションごとのワークスペース
        │   │   ├── source.html
        │   │   ├── fetched.html
        │   │   ├── lp_structure.json
        │   │   ├── site_map.json
        │   │   ├── asset_map.json
        │   │   ├── client_data.json
        │   │   ├── fetch_failures.json
        │   │   ├── source_url.txt
        │   │   ├── internal_pages/     ← 内部ページ構造JSON
        │   │   └── page_client/        ← ページ別編集データ
        │   └── analyze_tasks/          ← 解析タスク管理
        └── output/                     ← 生成物（gitignore）
            └── ws_{32hex}/
                ├── index.html          ← 生成LPトップ
                ├── internal_N/         ← 内部ページ
                │   └── index.html
                ├── assets/
                │   ├── css/
                │   ├── img/
                │   ├── js/
                │   └── fonts/
                └── sites/
```

---

## コアクラス一覧（lib/）

| クラス | 役割 |
|--------|------|
| `LpWorkspace` | セッションごとのワークスペースID管理・パス解決 |
| `LpFetcher` | cURLでHTML取得（UA偽装・リダイレクト追跡・gzip展開） |
| `LpAnalyzer` | HTML→DOMDocument解析→lp_structure.json生成 |
| `LpAssetDownloader` | 画像・CSS・フォント・JS一括取得・asset_map.json生成 |
| `LpInternalPagesPipeline` | 同一ホスト内部ページを深さ1でクロール・解析 |
| `LpSiteMapper` | lp_structure + 内部ページ → site_map.json生成 |
| `LpGenerator` | lp_structure + client_data → 完全HTML生成 |
| `LpUrlContext` | 相対URL→絶対URL変換（base href・trailing-slash対応） |
| `LpIoNeutralizer` | フォーム送信先等の外部IO無効化 |
| `LpDomScriptCleanup` | 取得HTMLからscriptタグを除去 |
| `LpCmsDetector` | WordPressなどCMS種別を検出 |
| `LpTheme` | テーマ色・フォント推定 |
| `LpMapper` | セクション要素マッピング |
| `LpLinkRedirectVerifier` | 外部リンクリダイレクト確認 |
| `LpAssetAudit` | アセット取得品質チェック |
| `LpOutputAudit` | 生成物品質チェック |
| `LpExportBundle` | ZIP出力 |
| `LpCloneContext` / `LpCloneImagePack` | クローン画像管理 |
| `LpFs` | ファイルシステムユーティリティ |
| `UserRegistry` | ユーザー承認・ロール管理 |
| `WorkspaceRegistry` | ワークスペース一覧管理 |
| `AnalyzeTask` / `GenerateTask` | 非同期タスク管理 |
| `JobRegistry` | バックグラウンドジョブ管理 |
| `GoogleAuth` | Google OAuth 2.0 |

---

## 処理パイプライン（5ステップ）

### Step 1: HTML取得（LpFetcher）

```
URL入力 → cURLでHTMLフェッチ
  - User-Agent: Chrome/124 偽装
  - リダイレクト追跡（最大8回）
  - gzip/br自動展開
  - Cookie jar使用（session維持）
→ source.html / fetched.html に保存
```

### Step 2: LP構造解析（LpAnalyzer）

```
HTML → DOMDocument
  → scriptタグ除去（LpDomScriptCleanup）
  → body直下の構造要素を候補として収集:
      - section / header / footer / nav / main / article / aside
      - div
      - h1-h6（子要素ありの場合のみ ← MV/heroがh1ラッパーのサイト対応）
  → 各セクションから要素を抽出（data-lp-id でタグ付け）
  → lp_structure.json 出力
```

**lp_structure.json の構造:**
```json
{
  "source_url": "https://example.com/",
  "meta": { "title": "...", "description": "...", "charset": "UTF-8", "viewport": "..." },
  "head_extra": "<link rel='stylesheet' href='...'> ...",
  "body_head_snippets": "<style>...</style>",
  "sections": [
    {
      "id": "sec_0",
      "tag": "h1",
      "html": "<h1 id='mv'>...</h1>",
      "elements": [
        { "id": "elem_sec_0_0", "type": "img", "src": "https://...", "alt": "..." },
        { "id": "elem_sec_0_1", "type": "text", "text": "キャッチコピー" }
      ]
    }
  ],
  "internal_pages": [ ... ]
}
```

### Step 3: アセット取得（LpAssetDownloader）

```
HTML内の全アセットURL収集:
  - src / href 属性（img / link / script）
  - srcset / data-src / data-lazy-src（遅延読み込み）
  - CSS内 url() → CSSも再帰ダウンロード
  - picture > source[srcset]

URL正規化（LpUrlContext）:
  - 相対URL → 絶対URL変換（base href考慮）
  - // → https:
  - %エンコード正規化

保存先: output/ws_{id}/assets/{css|img|js|fonts}/
asset_map.json: { "https://example.com/img/foo.webp": "assets/img/foo.webp" }
```

### Step 4: 内部ページクロール（LpInternalPagesPipeline）

```
エントリページのセクションから同一ホストURLを収集
  → 深さ1のみ（内部ページからは追跡しない）
  → 最大100ページ（MAX_PAGES）
  → 各ページ: Fetch + Analyze + AssetDownload
  → internal_pages/{hash}.json に構造保存

site_map.json: {
  "pages": {
    "index":      { "source_url": "...", "local_path": "output/ws_.../index.html", ... },
    "internal_0": { "source_url": "...", "local_path": "output/ws_.../internal_0/index.html", ... },
    "internal_1": { ... }
  }
}
```

### Step 5: LP生成（LpGenerator）

```
lp_structure.json + client_data.json + asset_map.json
  → 各セクションHTML断片をDOM再構築
  → data-lp-id 要素にclient_dataを適用（text/src/href置換）
  → セクションを .lp-reverse-section-root（isolation:isolate）でラップ
  → 完全HTMLドキュメント組み立て
  → アセットURLをローカルパスに置換（asset_map）
  → 内部ページリンクを internal_N/ 形式に変換
  → クリックインターセプターJS注入
  → output/ws_{id}/index.html 書き出し
```

---

## クリックインターセプター（JS）

生成HTMLに埋め込むインラインJS。内部リンクのクリックをフックし、
`generate_internal.php` を呼び出してページを動的生成してプレビューする。

```javascript
(function(){
  var ORIGIN    = "https://original-site.com";
  var CMS       = "/current/lp_reverse_cms/store/generate_internal.php";
  var MAP       = { "https://original-site.com/news/": "internal_19", ... };
  var LOCAL_MAP = { "internal_0/index.html": "internal_0", ... };
  var OUT_PATH  = "/current/lp_reverse_cms/output/ws_{id}/";
  var LP_ROOT   = "";   // index: ""、depth-1ページ: "../"
  var WS_ID     = "d0a67b78b016d0c6e5a86210aaab040c";

  function doFetch(key) {
    fetch(CMS, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: key, ws_id: WS_ID })
    })
    .then(r => r.json())
    .then(d => {
      if (d.preview_relative) window.location.href = LP_ROOT + d.preview_relative;
    })
    .catch(() => {});
  }

  document.addEventListener('click', function(e) {
    var a = e.target.closest('a[href]');
    if (!a) return;
    // ORIGIN一致 → MAP検索
    // OUT_PATH含む → LOCAL_MAP検索
    // キー確定 → doFetch(key)
  });
})();
```

**深さ補正（fixOutputAssetPaths）**:

depth >= 1 のページでは以下全てに `../` × depth を付与:
1. `src="assets/` / `href="assets/`
2. CSS内 `url(assets/`
3. `srcset="..."` 内の `assets/`
4. **`href="internal_N/`** ← ナビリンクが壊れる元凶（v1.5で修正済み）

---

## LpUrlContext の重要実装

### trailing-slash パスの directory URL 解決

```php
private static function pathToDirectoryUrl(string $schemeHost, string $urlPath): string
{
    if ($urlPath === '' || $urlPath === '/') {
        return $schemeHost;
    }
    // trailing-slash は dirname() を使わない（1段上に解決されるバグを避ける）
    if (str_ends_with($urlPath, '/')) {
        $dir = rtrim($urlPath, '/');
    } else {
        $dir = dirname($urlPath);
    }
    if ($dir === '' || $dir === '/' || $dir === '.' || $dir === '\\') {
        return $schemeHost;
    }
    return $schemeHost . $dir;
}
```

---

## LpAnalyzer の重要実装

### body直下の構造要素候補収集

```php
// h1-h6 を body直下セクションとして扱う（MV/heroがh1ラッパーのサイト対応）
// 子要素がある場合のみ候補にする（テキストだけの見出しは除外）
$isBodyLevelHeading = in_array($tag, self::HEADING_TAGS) && $child->childElementCount > 0;
if (in_array($tag, self::SECTION_TAGS) || $tag === 'div' || $isBodyLevelHeading) {
    $candidates[] = $child;
}
```

---

## 認証・設定

### .env ファイル

```dotenv
# Google OAuth（必須）
GOOGLE_CLIENT_ID=xxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxx
GOOGLE_REDIRECT_URI=https://lp-next.jitan.app/current/lp_reverse_cms/store/auth_callback.php

# 管理者（必須）
CMS_SUPER_ADMIN=admin@example.com

# Anthropic Claude API（Vision・画像分析）
ANTHROPIC_API_KEY=sk-ant-xxx

# HuggingFace（FLUX画像生成）
HF_API_KEY=hf_xxx

# セッション名（任意）
SESSION_NAME=LP_REVERSE_CMS_SID
```

---

## Apache VirtualHost 設定

```apache
<VirtualHost *:443>
    ServerName lp-next.jitan.app
    DocumentRoot /home/lp-next

    <Directory /home/lp-next>
        AllowOverride All
        Require all granted
    </Directory>

    # セキュリティ: open_basedir
    php_admin_value open_basedir "/home/lp-next:/tmp"

    # data/ を外部から遮断
    <LocationMatch "^/current/lp_reverse_cms/data/">
        Require all denied
    </LocationMatch>

    Timeout 3600

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/lp-next.jitan.app/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/lp-next.jitan.app/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
</VirtualHost>
```

---

## セットアップ手順（ゼロから）

```bash
# 1. クローン
git clone https://github.com/BinaryTraffic/lp-next.git /home/lp-next
cd /home/lp-next

# 2. data/ / output/ の権限設定
mkdir -p current/lp_reverse_cms/data current/lp_reverse_cms/output
chown -R www-data:www-data \
    current/lp_reverse_cms/data \
    current/lp_reverse_cms/output
chmod -R u+rwX,g+rwX \
    current/lp_reverse_cms/data \
    current/lp_reverse_cms/output

# 3. .env 作成
nano current/lp_reverse_cms/.env
# → GOOGLE_CLIENT_ID / SECRET / REDIRECT_URI / CMS_SUPER_ADMIN を記入

# 4. Apache 設定
sudo cp /path/to/lp-next.jitan.app.conf /etc/apache2/sites-available/
sudo a2enmod ssl rewrite headers
sudo a2ensite lp-next.jitan.app.conf
sudo systemctl reload apache2

# 5. SSL（Let's Encrypt）
sudo certbot --apache -d lp-next.jitan.app

# 6. PHP拡張確認
php -m | grep -E 'curl|dom|json|mbstring|gd'
# → 4つ全て表示されること

# 7. ブラウザで動作確認
# https://lp-next.jitan.app/current/lp_reverse_cms/
# → Google認証画面が出ればOK
```

---

## 非同期タスク管理

長時間処理（解析・生成）はバックグラウンドプロセスで実行。

```
ブラウザ → analyze_start.php → proc_open() で analyze_worker.php 起動
         ↓
analyze_progress.php をポーリング（1秒ごと）
         ↓
analyze_worker.php が data/analyze_tasks/ana_{id}.json を更新
```

タスクファイル形式:
```json
{
  "task_id": "ana_xxx",
  "status": "running",
  "progress": 96,
  "total": 100,
  "phase": "サイト構造を解析中...",
  "owner_email": "user@example.com",
  "ws_id": "d0a67b78..."
}
```

**注意**: ポーリング中に "task not found" が一瞬出ることがある（タスク完了の瞬間）。
実際は `status: done` で完了している一時的なエラー。

---

## AI 画像パイプライン（S1 実装中）

| パターン | 判定type | 処理 | 状態 |
|----------|----------|------|------|
| 1 | `ui` | Claude Vision座標取得 → GD合成 | ✅ |
| 2 | `photo` | HuggingFace FLUX写真生成 | 🔲 |
| 3 | `illustration` | FLUX+styleキーワード生成 | 🔲 |
| 4 | `composite` | FLUX背景 → GD合成 | 🔲 |
| 5 | `gradient` | GD描画 → image_composite合成 | ✅ |
| 6 | `bordered` | FLUX（内側）→ border_fill合成 | ✅ |
| 7 | `badge` | GD形状描画 → テキスト合成 | ✅ |

---

## バージョン履歴

| バージョン | 内容 |
|-----------|------|
| v1.0.0 | 初期版 |
| v1.1.0 | v1.1系安定版 |
| v1.1.11-stable | 書き込み権限エラー明示など安定化 |
| v1.2.0 | ワークスペース分離（v1.2系） |
| v1.5.0 | MV/h1セクション検出・trailing-slash URL修正・内部ページナビリンク深さ補正 |

---

## トラブルシューティング

| 現象 | 確認箇所 |
|------|----------|
| 画面が真っ白・500 | PHPエラーログ / php -v / 拡張モジュール |
| スタイルが付かない | output/assets/css の有無 |
| 解析後も古い見た目 | 「保存＆LP生成」の再実行 |
| HTML取得後「見つかりません」 | data/ への書き込み権限（www-data） |
| 解析96%で "task not found" | 一時的なポーリングエラー。タスクは完了している |
| ナビリンクが全て同じページに飛ぶ | fixOutputAssetPaths で internal_N/ リンクに深さ補正が必要（v1.5修正済み） |
| 内部ページ画像が壊れる | trailing-slash URL の directory 解決（v1.5修正済み） |
