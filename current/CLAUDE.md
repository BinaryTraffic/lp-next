# LP-NEXT プロジェクト — Claude 用コンテキスト

---

## ⚠️ AI への必須指示

**git commit / push / GCP デプロイ を行う前に、必ず [`DEV_WORKFLOW.md`](DEV_WORKFLOW.md) を読むこと。**

確認すべき項目：
- 作業ブランチが正しいか（`main` か `fix/*` か）
- PC / Mac どちらの環境か（BOM・desktop.ini リスクが異なる）
- GCP SSH 前に **OpenVPN Connect** で VPN 接続済みか
- `lp-next-fresh` と `lp-next` のどちらで作業すべきか
- **Apache VirtualHost の参照先**が正しいか（ローカル・GCP 両方。下記「VH 参照先の確認」参照）

---

## プロジェクト概要

「任意のWebサイトのLP（ランディングページ）をURLで指定するだけで、
そのデザイン・レイアウトを保ちながら、別業種向けの新しいLPをAIが自動生成する」
PHPフルスクラッチWebアプリ。

- **リポジトリ**: https://github.com/BinaryTraffic/lp-next
- **本番**: https://lp-next.jitan.app/current/lp_reverse_cms/
- **現行バージョン**: v1.5.0

---

## 開発体制

| 役割 | 内容 |
|------|------|
| オーナー・開発者 | shimizu（ソロ開発・レビュアーなし） |
| PC 開発環境 | Windows 11 + WSL Ubuntu（事務所据え置き） |
| Mac 開発環境 | MacBook Air M2（ノマド・外出時） |
| ファイル同期 | Google Drive オフラインミラーリング（PC・Mac 共通） |
| サーバー | GCP VM（lp-next.jitan.app） |

### ファイル管理・デプロイの全体像

**Google Drive が常に最新のソース（source of truth）。Git はバックアップ兼デプロイ手段。**

```
事務所PC（Windows/WSL）  ───┐
                            │  Google Drive でミラーリング同期
ノマドMac（macOS）       ───┘  （PC・Mac は同時に使わない運用）
         ↓
   編集完了後に git commit & push
         ↓
      GitHub（バックアップ）
         ↓
   GCP VM で git pull → 本番反映
```

- PC と Mac は**同時に電源を入れない運用**（事務所 or ノマドのどちらか一方がアクティブ）
- そのため Git のコリジョンは発生しない
- ローカルでの `git pull` は不要（Google Drive が同期を担う）
- `.git/` フォルダも Google Drive で同期されているが、同時操作しないため問題なし

### PC と Mac の環境差異（重要）

| 項目 | PC（WSL） | Mac（macOS） |
|------|-----------|--------------|
| ファイルの実体 | WSL ローカルディスク（ext4） | Google Drive 仮想FS（FUSE）マウント |
| Web サーバー | Apache2（`www-data` ユーザー） | PHP ビルトインサーバー（ログインユーザーで起動） |
| Apache で配信 | 可能 | **不可**（`_www` が仮想FSにアクセス不可・`Operation not permitted`） |
| Google Drive パス | `C:\Users\...\Google Drive\マイドライブ\` | `/Users/bintr/Library/CloudStorage/GoogleDrive-shimizu@binarytraffic.jp/マイドライブ/` |
| GOOGLE_REDIRECT_URI | `http://localhost/current/lp_reverse_cms/store/auth_callback.php` | `http://localhost:8080/lp_reverse_cms/store/auth_callback.php` |

> **引き継ぐ人へ**: このプロジェクトのコードは Google Drive で管理されており、GitHub はあくまでバックアップ兼本番デプロイの踏み台です。ローカルで最新コードを見たい場合は Google Drive のミラーフォルダを参照してください。GitHub の内容が最新とは限りません（最後に push したタイミングまでの状態です）。

---

## 技術スタック

| 項目 | 内容 |
|------|------|
| 言語 | PHP 8.x（Composer不使用・フルスクラッチ） |
| PHP拡張 | curl / dom / json / mbstring / gd / zip |
| Webサーバー | Linux / Apache2 |
| DB | なし（JSONファイルで全管理） |
| 認証 | Google OAuth 2.0 |
| AI | Anthropic Claude API（Vision） / HuggingFace FLUX |
| フロントエンド | Bootstrap 5 / バニラJS |

---

## ディレクトリ構成

```
lp-next/
└── current/
    ├── CLAUDE.md              ← このファイル
    ├── DEV_WORKFLOW.md        ← 開発フロー詳細
    ├── lp_reverse_cms/        ← CMSルート
    │   ├── index.php          ← 管理画面（APP_VERSION定義）
    │   ├── .env               ← 秘密情報（gitignore）
    │   ├── lib/               ← コアクラス群
    │   ├── store/             ← Ajax APIエンドポイント
    │   ├── template/          ← PHPテンプレート
    │   ├── tools/             ← CLIワーカー
    │   ├── assets/            ← 管理UI用CSS/JS
    │   ├── data/              ← ワークスペースデータ（gitignore）
    │   └── output/            ← 生成物（gitignore）
    └── cursor_prompts/        ← Cursor向け実装指示書
        └── rebuild_from_scratch_v1_5.md
```

---

## コアクラス

| クラス | ファイル | 役割 |
|--------|----------|------|
| `LpWorkspace` | lib/LpWorkspace.php | セッションごとのワークスペース管理 |
| `LpFetcher` | lib/LpFetcher.php | cURLでHTML取得 |
| `LpAnalyzer` | lib/LpAnalyzer.php | HTML→構造解析→lp_structure.json |
| `LpUrlContext` | lib/LpUrlContext.php | 相対URL→絶対URL変換 |
| `LpAssetDownloader` | lib/LpAssetDownloader.php | 画像/CSS/JS一括取得 |
| `LpInternalPagesPipeline` | lib/LpInternalPagesPipeline.php | 内部ページクロール（深さ1） |
| `LpSiteMapper` | lib/LpSiteMapper.php | site_map.json生成 |
| `LpGenerator` | lib/LpGenerator.php | lp_structure→完全HTML生成 |
| `LpIoNeutralizer` | lib/LpIoNeutralizer.php | フォーム送信先の無効化 |

---

## 処理パイプライン

```
URL入力
  → LpFetcher      HTML取得（Chrome UA偽装・gzip展開）
  → LpAnalyzer     構造解析 → lp_structure.json
  → LpAssetDownloader  画像/CSS/JS取得 → asset_map.json
  → LpInternalPagesPipeline  内部ページクロール（最大100ページ・深さ1）
  → LpSiteMapper   site_map.json生成
  → LpGenerator    client_data適用 → 完全HTML出力
  → クリックインターセプターJS注入
```

---

## v1.5.0 で修正した重要バグ

### 1. MV/heroセクションが解析されない
`LpAnalyzer::findStructuralElements()` が `h1-h6` を構造要素として認識しなかった。  
**修正**: 子要素ありの `h1-h6` も候補に追加。

```php
// lib/LpAnalyzer.php
$isBodyLevelHeading = in_array($tag, self::HEADING_TAGS) && $child->childElementCount > 0;
if (in_array($tag, self::SECTION_TAGS) || $tag === 'div' || $isBodyLevelHeading) {
    $candidates[] = $child;
}
```

### 2. trailing-slash URLのアセット解決が1段上になる
`pathToDirectoryUrl()` で `dirname()` を使うと `/foo/bar/` → `/foo` になるバグ。  
**修正**: trailing-slash の場合は `rtrim()` を使用。

```php
// lib/LpUrlContext.php
if (str_ends_with($urlPath, '/')) {
    $dir = rtrim($urlPath, '/');  // dirname()は使わない
} else {
    $dir = dirname($urlPath);
}
```

### 3. 内部ページのナビリンクが全て同じページに飛ぶ
`internal_8/index.html` 内の `href="internal_19/index.html"` が
`internal_8/internal_19/index.html` に解決されてクリックインターセプターが失敗。  
**修正**: `fixOutputAssetPaths()` で `internal_N/` リンクにも深さ補正を追加。

```php
// lib/LpGenerator.php
$html = (string) preg_replace(
    '/\bhref="(internal_\d+\/)/i',
    'href="' . $prefix . '$1',
    $html
);
```

---

## 開発フロー（確定版）

```
[PC ローカル]                        [Mac ローカル]
  コード修正（WSL）                    コード修正（Cursor / エディタ）
    ↓                                   ↓
  http://localhost/current/            php -S localhost:8080
  lp_reverse_cms/                      http://localhost:8080/lp_reverse_cms/
    ↓                                   ↓（Google Drive 経由で自動同期）
  git commit & push                   git commit & push（Cursor / ターミナル）
    ↓（PowerShell経由）                  ↓
[GitHub main] ←─────────────────────────┘
    ↓
[GCP本番] git pull
    ↓
[QC確認] lp-next.jitan.app
```

詳細は `DEV_WORKFLOW.md` を参照。

---

## 環境別アクセス先

| 環境 | URL / パス |
|------|-----------|
| PC ローカル（WSL） | `http://localhost/current/lp_reverse_cms/` |
| Mac ローカル（PHP ビルトイン） | `http://localhost:8080/lp_reverse_cms/` |
| GCP 本番 | `https://lp-next.jitan.app/current/lp_reverse_cms/` |
| SSH | `ssh gcp-lp`（~/.ssh/config 設定済み） |

---

## GCP サーバー情報

- **IP**: 34.85.117.181
- **User**: shimizu
- **SSH Key**: `~/.ssh/order-piroshispecial`
- **DocumentRoot**: `/home/lp-next`
- **CMSパス**: `/home/lp-next/current/lp_reverse_cms/`
- **Apache設定**: `/etc/apache2/sites-enabled/lp-next.jitan.app-le-ssl.conf`

---

## .env 設定項目

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://lp-next.jitan.app/current/lp_reverse_cms/store/auth_callback.php
CMS_SUPER_ADMIN=shimizu@binarytraffic.jp
ANTHROPIC_API_KEY=
HF_API_KEY=
```

ローカル開発時（PC/WSL）: `GOOGLE_REDIRECT_URI=http://localhost/current/lp_reverse_cms/store/auth_callback.php`  
ローカル開発時（Mac）: `GOOGLE_REDIRECT_URI=http://localhost:8080/lp_reverse_cms/store/auth_callback.php`

---

## git 運用

- **main ブランチ**が本番・安定版
- **QC 修正は `fix/*` ブランチ** → PR → main マージの流れ
- **タグ**: v1.0.0 / v1.1.0 / v1.1.11-stable / v1.2.0 / **v1.5.006**（現行）
- コミット・Push は `lp-next-fresh` クローンから行う（`lp-next` は desktop.ini 混入による git 破損のため）
- GCP デプロイ手順は `DEV_WORKFLOW.md` の「GCP VM へのデプロイ手順」を参照

> **ナビバーでデプロイ確認**: `v1.5.006 · YYYYMMDD+HHmm #コミットハッシュ` が表示される。
> ハッシュが最新コミットと一致していれば反映済み。

---

## 未解決・今後の課題（v1.5 以降）

### 機能
- [ ] AI画像生成（photo/illustration/composite）— HuggingFace FLUX
- [ ] テキスト自動生成（業種・トーン指定）
- [ ] 承認ログと学習ループ

### インフラ（検討中）
- [ ] Google Drive + SFTP デプロイへの移行
- [x] Mac ローカル開発環境セットアップ（PHP ビルトインサーバー）
- [ ] CLAUDE.md の Mac 側同期フロー確立

---

## よく使うコマンド

```bash
# GCP SSH
ssh gcp-lp

# GCP デプロイ（fix/* ブランチ反映 — PR マージ前）
# ※ 事前に OpenVPN Connect で VPN 接続すること
ssh gcp-lp "cd /home/lp-next && git fetch origin fix/image-load-retry && git reset --hard FETCH_HEAD"

# GCP デプロイ（PR マージ後 main に戻す）
ssh gcp-lp "cd /home/lp-next && git checkout main && git pull origin main"

# デプロイ確認（コミットハッシュ）
ssh gcp-lp "cd /home/lp-next && git rev-parse --short HEAD"

# ローカルApache起動（WSL）
sudo service apache2 start

# Mac ローカル起動（PHP ビルトインサーバー）
# ※ Google Drive 仮想FSはApacheの_wwwユーザーからアクセス不可のため、ビルトインサーバーを使用
# ※ Homebrew の PHP が必要（brew install php）
eval "$(/opt/homebrew/bin/brew shellenv zsh)"
cd "/Users/bintr/Library/CloudStorage/GoogleDrive-shimizu@binarytraffic.jp/マイドライブ/projects/lp-next/current"
php -S localhost:8080
# → http://localhost:8080/lp_reverse_cms/ でアクセス
# ※ 8080が使用中の場合: sudo apachectl stop してから再実行

# git push（PowerShell）
$tmp="$env:TEMP\lp-push-$(Get-Random)"
git clone https://github.com/BinaryTraffic/lp-next.git $tmp
# → 変更を適用してpush
```
