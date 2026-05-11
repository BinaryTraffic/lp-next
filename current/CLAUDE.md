# LP-NEXT プロジェクト — Claude 用コンテキスト

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
| PC 開発環境 | Windows 11 + WSL Ubuntu |
| Mac 開発環境 | MacBook Air M2 |
| ファイル同期 | Google Drive |
| サーバー | GCP VM（lp-next.jitan.app） |

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
[PC/Mac ローカル]
  コード修正
    ↓
  ローカル動作確認
  http://localhost/current/lp_reverse_cms/
    ↓
  git commit（WSL or Cursor）
    ↓
  git push（Windows PowerShell 経由）
    ↓
[GitHub main]
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
| Mac ローカル | 未セットアップ（要PHP/Apache） |
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

ローカル開発時は `GOOGLE_REDIRECT_URI=http://localhost/current/lp_reverse_cms/store/auth_callback.php`

---

## git 運用

- **main ブランチ**が本番・安定版
- **タグ**: v1.0.0 / v1.1.0 / v1.1.11-stable / v1.2.0 / **v1.5.0**（現行）
- WSLからのpushはネットワーク制限のため **PowerShell経由**で実行
- GCPデプロイは `git pull origin main` のみ

---

## 未解決・今後の課題（v1.5 以降）

### 機能
- [ ] AI画像生成（photo/illustration/composite）— HuggingFace FLUX
- [ ] テキスト自動生成（業種・トーン指定）
- [ ] 承認ログと学習ループ

### インフラ（検討中）
- [ ] Google Drive + SFTP デプロイへの移行
- [ ] Mac ローカル開発環境セットアップ（PHP/Apache on Mac）
- [ ] CLAUDE.md の Mac 側同期フロー確立

---

## よく使うコマンド

```bash
# GCP SSH
ssh gcp-lp

# GCP デプロイ
ssh gcp-lp "cd /home/lp-next && git pull origin main"

# ローカルApache起動（WSL）
sudo service apache2 start

# git push（PowerShell）
$tmp="$env:TEMP\lp-push-$(Get-Random)"
git clone https://github.com/BinaryTraffic/lp-next.git $tmp
# → 変更を適用してpush
```
