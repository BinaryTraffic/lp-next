# LP Reverse CMS

参照 LP の URL から HTML を取得し、DOM 解析で編集可能な構造を JSON 化。顧客向け文言を流し込み、同じ構成の LP を静的 HTML として再生成する PHP 製 MVP。

**アプリバージョン:** `1.1.8`（`index.php` の `APP_VERSION` と同期）

**ドキュメント:** [開発経緯・成果とゼロからの構築手順（PROJECT_HISTORY_AND_SETUP.md）](docs/PROJECT_HISTORY_AND_SETUP.md)

**公式リポジトリ:** [BinaryTraffic/lp-next](https://github.com/BinaryTraffic/lp-next)（クローン URL は末尾 `.git` 可）

---

## Git

初回クローン:

```bash
git clone https://github.com/BinaryTraffic/lp-next.git
cd lp-next
# 組み込みサーバー例（リポジトリルートから）
php -S localhost:8080 -t lp_reverse_cms
```

既にローカルで作業している場合のリモート設定例:

```text
git remote add origin https://github.com/BinaryTraffic/lp-next.git
git branch -M main
git push -u origin main
```

### 共同作業（別マシン・別 Cursor）

- 作業前: `git pull origin main`
- リモートと同じコミットか確認: `git fetch origin` のあと、`git rev-parse HEAD` と `git ls-remote origin refs/heads/main` のハッシュ先頭が一致するか見る（例: `4895729`）
- 接続先確認: `git remote -v` → `BinaryTraffic/lp-next.git`

---

## 技術スタック

| 項目 | 内容 |
|------|------|
| 言語 | PHP 8.x（`declare(strict_types=1)`） |
| HTML 取得 | cURL（`LpFetcher`） |
| HTML 解析 | `DOMDocument` / `DOMXPath`（`LpAnalyzer`） |
| 構造保存 | JSON（`data/lp_structure.json` 等） |
| テンプレート | 素の PHP（Blade 等は不使用） |
| 管理 UI | Bootstrap 5 + 独自 CSS/JS |

---

## ディレクトリ構成（現状）

```text
lp_reverse_cms/
├── index.php              # 管理画面（3 ステップ）
├── preview.php            # 生成 LP のプレビュー（デバイス切替）
├── export.php             # output/index.html をダウンロード
├── README.md              # 本ファイル
├── .htaccess
│
├── lib/
│   ├── LpFetcher.php      # cURL で HTML 取得・文字コード変換
│   ├── LpUrlContext.php   # `<base href>` 対応の相対 URL 解決
│   ├── LpAssetDownloader.php  # CSS / 画像 / JS / フォント取得、output/assets 保存
│   ├── LpAssetAudit.php   # 参照 URL 収集・未取得一覧（debug 用）
│   ├── LpOutputAudit.php  # 生成 HTML の未置換 URL スキャン
│   ├── LpAnalyzer.php     # セクション・要素抽出、data-lp-id 付与
│   ├── LpMapper.php       # セクション分類・UI メタデータ
│   └── LpGenerator.php    # 構造 + 顧客データ → HTML、asset_map 適用
│
├── store/
│   ├── fetch_lp.php       # POST: URL → HTML 取得 + アセット DL
│   ├── analyze_lp.php     # POST: fetched.html → lp_structure.json
│   ├── save_client.php    # POST: client_data.json 保存
│   ├── generate_lp.php    # POST: output/index.html 生成 + output_unreplaced.json
│   └── debug.php          # GET: 未取得・未置換・fetch 失敗などの JSON
│
├── template/
│   ├── editPage.php       # 編集フォーム
│   └── generated_lp.php   # プレビュー用ラッパ（参考）
│
├── assets/                # 管理画面用
│   ├── css/index.css
│   └── js/index.js
│
├── data/                  # 作業データ（.htaccess で直アクセス制限）
│   ├── source.html        # 取得直後の HTML
│   ├── fetched.html       # 解析入力（通常は source と同一）
│   ├── source_url.txt     # 最終リダイレクト後 URL
│   ├── asset_map.json     # 絶対 URL → ローカル相対パス
│   ├── fetch_failures.json    # HTTP 取得失敗 URL 一覧
│   ├── output_unreplaced.json # 生成 HTML に残った外部 URL スキャン結果
│   ├── lp_structure.json
│   └── client_data.json
│
└── output/                # 生成物
    ├── index.html
    └── assets/
        ├── css/
        ├── img/
        ├── js/
        └── fonts/
```

---

## 起動方法（開発）

PHP 組み込みサーバーの例（XAMPP の PHP を想定）:

```powershell
C:\xampp\php\php.exe -S localhost:8080 -t "C:\path\to\lp_reverse_cms"
```

ブラウザで **http://localhost:8080** を開く（`index.php` をファイルから直接開かないこと）。

---

## 利用フロー

1. **Step 1 — 解析する**  
   - `store/fetch_lp.php` が HTML を取得し `data/source.html` 等に保存。  
   - `LpAssetDownloader` が `<link rel="stylesheet">`、`<img>` / `srcset` / 遅延読み込み属性、`<script src>` 等を `output/assets/` に保存し、`data/asset_map.json` を更新。  
   - 続けて `analyze_lp.php` が `fetched.html` を解析し `lp_structure.json` を生成。

2. **Step 2 — 編集**  
   - セクション別にテキスト・画像 URL・リンクを編集。  
   - 「保存＆LP生成」で `save_client.php` → `generate_lp.php`。

3. **Step 3 — 確認**  
   - プレビュー / エクスポート。  
   - ナビの **🐛** または `store/debug.php` でアセット件数・未置換 URL の目安を確認可能。

---

## 生成 HTML とアセット

- 最終 HTML は `output/index.html`。  
- CSS / 画像 / JS は `output/assets/` 配下を相対参照（例: `assets/css/common.css`）。  
- `LpGenerator` は `asset_map.json` に基づき、生成後の HTML 内の絶対 URL をローカルパスへ置換する。  
- Windows 環境で過去に混入し得た `https://host\path` や `host%5C` 形式は、生成時に正規化してから置換する（v1.1.1）。

---

## 既知の注意点

- アセットの多い LP は **取得に数十秒** かかることがある。  
- 動的に挿入されるリソースのみのサイトは、静的取得では取りこぼしがある。  
- 本番運用では Apache 等のドキュメントルートに配置し、`data/` の保護を維持すること。

---

## バージョン履歴（概要）

| 版 | 内容 |
|----|------|
| 1.0.x | 初版 MVP（HTML のみ中心） |
| 1.1.0 | アセット DL、診断 UI、head の link 全属性保持 |
| 1.1.1 | Windows 起因の URL 不正（`\` / `%5C`）修正、`LpAssetDownloader` の相対 URL 解決修正 |
| 1.1.2 | `<base href>` 対応、CSS `url()` からフォント等も取得、`debug.php` に未取得・未置換一覧、生成後スキャン |
| 1.1.3 | `debug` の map 件数修正（`assets/` パス）、未取得判定の `/img`↔`/images`・Google Fonts ホスト差・ローカル実ファイル照合、favicon 取得、preconnect ノイズ除去 |
| 1.1.4 | `applyAssetMap` を絶対 URL 優先の二段置換に整理（相対キーが長い `href` 内を先に壊す問題の防止）、map 展開ループの代入バグ修正 |
| 1.1.5 | `//` マップキーを `https://` 内へ誤マッチしない置換に変更、相対キーは引用符付き href/src 等と srcset のみ置換（`assets/assets/...` 二重化の防止）、https から `//` への重複展開を廃止 |
| 1.1.6 | 未置換スキャンで HTML コメントを除外（コメント内 URL の誤検知防止）、`debug.php` 表示時に `output/index.html` を再スキャンして `output_unreplaced.json` を更新 |
| 1.1.7 | `LpAnalyzer`: `<picture>` 内の `img` を走査して編集対象化（従来は `picture` がコンテナ外でヒーロー背景相当の画像がフォームに出ない問題）、`class` に `bg` を含む画像はラベルを「背景画像」に、`source` の `srcset` も absolutize |
| 1.1.8 | `LpAnalyzer`: 誤った `</source>` 位置などで `img` が `source` の子になる DOM でも拾えるよう、`source` をコンテナとして再帰；`docs/PROJECT_HISTORY_AND_SETUP.md`（経緯・ゼロからの構築手順）追加 |

---

*この README はリポジトリ現状に合わせて整備されています。更新日はリポジトリの最終コミットを参照してください。*
