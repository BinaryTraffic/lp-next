# Site Reverse CMS — ゼロからの構築手順書

> **対象バージョン:** v1.5.006+  
> **最終更新:** 2026-05-14  
> **リポジトリ:** https://github.com/BinaryTraffic/lp-next

---

## 目次

1. [アプリ概要](#1-アプリ概要)
2. [アーキテクチャ](#2-アーキテクチャ)
3. [ディレクトリ構成](#3-ディレクトリ構成)
4. [必要要件](#4-必要要件)
5. [macOS セットアップ](#5-macos-セットアップ)
6. [Windows + WSL2 セットアップ](#6-windows--wsl2-セットアップ)
7. [Ubuntu / Debian（GCP VM 本番）セットアップ](#7-ubuntu--debianGCP-VM-本番セットアップ)
8. [Google OAuth クライアント作成](#8-google-oauth-クライアント作成)
9. [環境変数（.env）設定](#9-環境変数env設定)
10. [Apache 設定](#10-apache-設定)
11. [ファイル権限](#11-ファイル権限)
12. [初期ユーザー登録](#12-初期ユーザー登録)
13. [動作確認](#13-動作確認)
14. [GCP VM へのデプロイ](#14-gcp-vm-へのデプロイ)
15. [アップデート手順](#15-アップデート手順)
16. [トラブルシューティング](#16-トラブルシューティング)

---

## 1. アプリ概要

**Site Reverse CMS** は、既存の公開 LP（URL）を解析・複製し、テキスト・画像を書き換えて新しい静的 HTML として出力するツールです。

### 主な処理フロー（3 ステップ）

```
Step 1: 解析
  URL 入力 → HTML 取得 → DOM 解析 → CSS/画像/JS/フォント ダウンロード
  → lp_structure.json（構造 JSON）生成

Step 2: 編集
  管理 UI でテキスト・画像を編集 → client_data.json に保存

Step 3: 生成
  lp_structure.json + client_data.json → output/ws_xxx/index.html 出力
  → プレビュー / ZIP エクスポート
```

### 技術スタック

| 領域 | 技術 |
|------|------|
| バックエンド | PHP 8.1+（DB なし、JSON ファイルストレージ） |
| フロントエンド | Bootstrap 5.3.3 / Bootstrap Icons 1.11 / Vanilla JS |
| 認証 | Google OAuth 2.0（Composer 不使用） |
| Web サーバー | Apache 2.4（mod_php / php-fpm） |
| AI 連携 | Anthropic Claude API / OpenAI API / Hugging Face |
| バージョン管理 | Git（GitHub）、デプロイは `git pull` |

---

## 2. アーキテクチャ

```
ブラウザ
  │
  ├─ index.php          管理 UI（Step 1-2-3 ステッパー）
  ├─ preview.php         生成済みサイト プレビュー（iframe）
  ├─ image_checklist.php 画像作業指示書
  ├─ export.php          ZIP エクスポート
  │
  ├─ store/              AJAX エンドポイント群（JSON 返却）
  │    ├─ analyze_start.php / analyze_progress.php  解析ジョブ制御
  │    ├─ generate_start.php / generate_progress.php 生成ジョブ制御
  │    ├─ save_client.php   編集データ保存
  │    ├─ debug.php         診断情報
  │    ├─ auth_callback.php Google OAuth コールバック
  │    └─ ... 他 50+ エンドポイント
  │
  ├─ lib/                PHP ライブラリ
  │    ├─ LpFetcher.php         HTML 取得（cURL）
  │    ├─ LpAnalyzer.php        DOM 解析・構造抽出
  │    ├─ LpAssetDownloader.php アセット取得・ローカル保存
  │    ├─ LpGenerator.php       output/index.html 生成
  │    ├─ LpUrlContext.php      URL 正規化・バリアント解決
  │    ├─ LpWorkspace.php       ワークスペース（セッション分離）
  │    ├─ JobRegistry.php       バックグラウンドジョブ管理
  │    ├─ GoogleAuth.php        OAuth 2.0
  │    ├─ UserRegistry.php      ユーザー承認・ロール管理
  │    └─ ... 他
  │
  ├─ tools/
  │    └─ analyze_worker.php    解析ワーカー（CLI として起動）
  │
  ├─ assets/             管理 UI 用フロントエンド
  │    ├─ css/index.css
  │    └─ js/index.js / workspace_manage.js
  │
  ├─ data/               ← .gitignore（セッション別データ）
  │    └─ ws_{32hex}/
  │         ├─ lp_structure.json
  │         ├─ client_data.json
  │         ├─ asset_map.json
  │         ├─ fetch_failures.json
  │         ├─ site_map.json
  │         └─ source_url.txt
  │
  └─ output/             ← .gitignore（生成済みファイル）
       └─ ws_{32hex}/
            ├─ index.html
            └─ assets/css/ img/ js/ fonts/
```

### ワークスペース分離

- ログイン後のセッションごとに 32 文字の HEX ID（`ws_xxx`）を発行
- `data/ws_xxx/` にデータ、`output/ws_xxx/` に生成物を分離
- 複数ユーザーが同時に異なる LP を作業できる

---

## 3. ディレクトリ構成

```
lp-next/                         ← Git リポジトリルート
├─ current/
│   └─ lp_reverse_cms/           ← アプリ本体
│       ├─ .env                  ← 環境変数（要作成・gitignore）
│       ├─ index.php
│       ├─ preview.php
│       ├─ lib/
│       ├─ store/
│       ├─ tools/
│       ├─ assets/
│       ├─ data/                 ← 要: Apache 書き込み権限
│       └─ output/               ← 要: Apache 書き込み権限
├─ deploy.sh                     ← GCP デプロイスクリプト
└─ docs/                         ← このファイルの場所
```

---

## 4. 必要要件

### 共通

| 項目 | バージョン |
|------|-----------|
| PHP | **8.1 以上**（8.2/8.3 推奨） |
| Apache | 2.4 以上 |
| Git | 2.x |

### 必須 PHP 拡張

```
php-curl        # HTTP 取得（cURL）
php-gd          # 画像処理（プレースホルダー生成・getimagesize）
php-zip         # ZIP 作成・展開
php-mbstring    # マルチバイト文字列
php-xml         # DOM/XML パーサー
php-json        # JSON（通常は標準組み込み）
php-intl        # 国際化（任意だが推奨）
```

### 外部サービス（オプション）

| サービス | 用途 | 必須 |
|---------|------|------|
| Google OAuth | ログイン認証 | **必須** |
| Anthropic Claude API | AI テキスト生成・画像解析 | 任意 |
| OpenAI API | AI 画像生成 | 任意 |
| Hugging Face API | AI 画像生成 | 任意 |
| 法人番号 API | 会社情報検索 | 任意 |

---

## 5. macOS セットアップ

### 5-1. Homebrew インストール

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

### 5-2. PHP・Apache インストール

```bash
brew install php@8.2 httpd

# PHP を PATH に追加
echo 'export PATH="/opt/homebrew/opt/php@8.2/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc

# PHP バージョン確認
php -v
```

### 5-3. PHP 拡張確認

macOS の Homebrew PHP は主要拡張が同梱されています。確認：

```bash
php -m | grep -E 'curl|gd|zip|mbstring|xml|json'
```

不足している場合：

```bash
brew install php@8.2
# gd は --with-freetype オプションが必要な場合がある
brew reinstall php@8.2 --with-freetype 2>/dev/null || true
```

### 5-4. Git クローン

```bash
cd ~/projects
git clone https://github.com/BinaryTraffic/lp-next.git
cd lp-next/current/lp_reverse_cms
```

### 5-5. Apache 設定（macOS）

`/opt/homebrew/etc/httpd/httpd.conf` を編集：

```apache
# PHP モジュール読み込み（brew でインストールされたパスを確認）
LoadModule php_module /opt/homebrew/opt/php@8.2/lib/httpd/modules/libphp.so

# DirectoryIndex に index.php 追加
DirectoryIndex index.php index.html

# .php ファイルを PHP で処理
<FilesMatch \.php$>
    SetHandler application/x-httpd-php
</FilesMatch>
```

`/opt/homebrew/etc/httpd/extra/httpd-vhosts.conf`（または `httpd.conf` に直接）：

```apache
Alias /lp_reverse_cms /Users/YOUR_NAME/projects/lp-next/current/lp_reverse_cms

<Directory /Users/YOUR_NAME/projects/lp-next/current/lp_reverse_cms>
    AllowOverride All
    Require all granted
    Options -Indexes +FollowSymLinks
</Directory>
```

Apache 起動：

```bash
brew services start httpd
# 再起動
brew services restart httpd
```

### 5-6. data / output ディレクトリ作成

```bash
cd ~/projects/lp-next/current/lp_reverse_cms
mkdir -p data output
chmod 755 data output
```

### 5-7. .env 作成

```bash
cp .env.example .env   # サンプルがあれば
# なければ直接作成（Section 9 参照）
nano .env
```

---

## 6. Windows + WSL2 セットアップ

> ローカル開発向け。本番は GCP VM（Section 7）を参照。

### 6-1. WSL2 有効化

PowerShell（管理者）で：

```powershell
wsl --install -d Ubuntu-24.04
wsl --set-default-version 2
```

再起動後、Ubuntu を起動してユーザー設定。

### 6-2. Ubuntu（WSL2 内）でのセットアップ

以降は WSL 内で実行：

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.2 + Apache
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y \
    apache2 \
    php8.2 \
    php8.2-cli \
    php8.2-curl \
    php8.2-gd \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-zip \
    php8.2-intl \
    libapache2-mod-php8.2 \
    git

# Apache モジュール有効化
sudo a2enmod rewrite php8.2
```

### 6-3. Windows ポート 80 競合回避

```bash
sudo nano /etc/apache2/ports.conf
```

```apache
# 80 → 8080 に変更（Windows が 80 を使用しているため）
Listen 8080
```

`/etc/apache2/sites-enabled/000-default.conf` の `<VirtualHost *:80>` も `*:8080` に変更。

### 6-4. Git リポジトリ設定

WSL のホームディレクトリに git 管理用ディレクトリを作成し、Windows 側の Google Drive を working tree として使う：

```bash
# git ディレクトリ（メタデータ）は WSL ホームに
mkdir -p ~/lp-next
git init --bare ~/lp-next

# working tree は Google Drive / 任意のパス
# 例: /mnt/h/マイドライブ/projects/lp-next

git --git-dir=~/lp-next/.git \
    --work-tree='/mnt/h/マイドライブ/projects/lp-next' \
    remote add origin https://github.com/BinaryTraffic/lp-next.git

git --git-dir=~/lp-next/.git \
    --work-tree='/mnt/h/マイドライブ/projects/lp-next' \
    pull origin main
```

> **Note:** Windows ファイルシステム（`/mnt/h/` 等）で git を直接使うと `dubious ownership` エラーが出る場合があります。上記の `--git-dir` / `--work-tree` 分離方式を使うか、WSL 内の `~/` 以下にフルクローンしてシンボリックリンクを張る方法もあります。

### 6-5. Apache 設定（WSL2）

```bash
sudo nano /etc/apache2/sites-enabled/lp_local.conf
```

```apache
<VirtualHost *:8080>
    ServerName localhost
    DocumentRoot /mnt/h/マイドライブ/projects/lp-next

    Alias /current /mnt/h/マイドライブ/projects/lp-next/current
    Alias /lp_reverse_cms /mnt/h/マイドライブ/projects/lp-next/current/lp_reverse_cms

    <Directory /mnt/h/マイドライブ/projects/lp-next>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>
</VirtualHost>
```

```bash
sudo service apache2 restart
```

### 6-6. data / output ディレクトリ

```bash
APP=/mnt/h/マイドライブ/projects/lp-next/current/lp_reverse_cms
mkdir -p "$APP/data" "$APP/output"
sudo chown www-data:www-data "$APP/data" "$APP/output"
sudo chmod 775 "$APP/data" "$APP/output"
```

### 6-7. アクセス確認

ブラウザで `http://localhost:8080/lp_reverse_cms/` を開く。

---

## 7. Ubuntu / Debian（GCP VM 本番）セットアップ

### 7-1. サーバー初期設定

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y ufw fail2ban

# ファイアウォール
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 7-2. PHP + Apache インストール

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y \
    apache2 \
    php8.2 \
    php8.2-cli \
    php8.2-curl \
    php8.2-gd \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-zip \
    php8.2-intl \
    libapache2-mod-php8.2 \
    git \
    unzip \
    certbot \
    python3-certbot-apache

sudo a2enmod rewrite php8.2 ssl headers
sudo systemctl restart apache2
```

### 7-3. リポジトリ配置

```bash
# デプロイ用ディレクトリ
sudo mkdir -p /home/lp-next
sudo chown $USER:$USER /home/lp-next

cd /home/lp-next
git clone https://github.com/BinaryTraffic/lp-next.git .
```

### 7-4. データディレクトリ準備

```bash
APP=/home/lp-next/current/lp_reverse_cms

mkdir -p "$APP/data" "$APP/output"
sudo chown -R www-data:www-data "$APP/data" "$APP/output"
sudo chmod -R 775 "$APP/data" "$APP/output"

# アプリ全体をwww-dataが読めるように
sudo chown -R www-data:www-data "$APP"
sudo chmod -R 755 "$APP"
# data/output は書き込み可
sudo chmod -R 775 "$APP/data" "$APP/output"
```

### 7-5. Apache バーチャルホスト設定

```bash
sudo nano /etc/apache2/sites-available/lp-next.conf
```

```apache
<VirtualHost *:80>
    ServerName lp-next.jitan.app
    DocumentRoot /home/lp-next

    <Directory /home/lp-next>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    # PHP タイムアウト（解析・生成は長くなる場合がある）
    <FilesMatch \.php$>
        SetHandler application/x-httpd-php
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/lp-next_error.log
    CustomLog ${APACHE_LOG_DIR}/lp-next_access.log combined
</VirtualHost>
```

```bash
sudo a2ensite lp-next.conf
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

### 7-6. HTTPS（Let's Encrypt）

```bash
sudo certbot --apache -d lp-next.jitan.app
# 自動更新確認
sudo certbot renew --dry-run
```

### 7-7. PHP タイムアウト設定

```bash
sudo nano /etc/php/8.2/apache2/php.ini
```

```ini
max_execution_time = 300      ; 解析・生成で最大 5 分
memory_limit = 512M
upload_max_filesize = 32M
post_max_size = 32M
max_input_time = 120
```

```bash
sudo systemctl restart apache2
```

### 7-8. SSH エイリアス設定（ローカル PC 側）

`~/.ssh/config`（Windows の場合は `C:\Users\NAME\.ssh\config`）：

```
Host gcp-lp
    HostName YOUR_GCP_EXTERNAL_IP
    User YOUR_USERNAME
    IdentityFile ~/.ssh/your-gcp-key
```

---

## 8. Google OAuth クライアント作成

### 8-1. Google Cloud Console 設定

1. https://console.cloud.google.com/ にアクセス
2. プロジェクト作成（または既存プロジェクト選択）
3. **API とサービス** → **認証情報** → **認証情報を作成** → **OAuth 2.0 クライアント ID**
4. **アプリケーションの種類:** ウェブアプリケーション
5. **名前:** Site Reverse CMS（任意）
6. **承認済みのリダイレクト URI** に以下を追加：

| 環境 | URI |
|------|-----|
| ローカル（WSL） | `http://localhost:8080/lp_reverse_cms/store/auth_callback.php` |
| ローカル（macOS） | `http://localhost/lp_reverse_cms/store/auth_callback.php` |
| 本番 | `https://lp-next.jitan.app/lp_reverse_cms/store/auth_callback.php` |

7. **クライアント ID** と **クライアント シークレット** をコピー

### 8-2. OAuth 同意画面

1. **OAuth 同意画面** → **外部** を選択（社内利用なら **内部**）
2. アプリ名・サポートメール・スコープ（`email`, `profile`, `openid`）を設定
3. テストユーザーに自分のアカウントを追加

---

## 9. 環境変数（.env）設定

`lp_reverse_cms/.env` を作成：

```dotenv
# ─── Google OAuth（必須）─────────────────────────────────
GOOGLE_CLIENT_ID=xxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxx
GOOGLE_REDIRECT_URI=https://lp-next.jitan.app/lp_reverse_cms/store/auth_callback.php

# ─── スーパー管理者（必須）────────────────────────────────
# カンマ区切りで複数指定可
CMS_SUPER_ADMIN=your@gmail.com

# ─── Anthropic Claude API（AI テキスト生成・画像解析）──────
ANTHROPIC_API_KEY=sk-ant-xxxxxx
# クライアントキー（フロント→バックエンドの中継認証。任意）
ANTHROPIC_DENY_CLIENT_KEY=

# ─── OpenAI API（AI 画像生成）──────────────────────────────
OPENAI_API_KEY=sk-xxxxxx
OPENAI_DENY_CLIENT_KEY=

# ─── Hugging Face（AI 画像生成）────────────────────────────
HF_TOKEN=hf_xxxxxx
HUGGINGFACE_API_TOKEN=hf_xxxxxx
HF_IMAGE_MODEL=stabilityai/stable-diffusion-xl-base-1.0
HF_DENY_CLIENT_KEY=

# ─── 法人番号 API（会社情報検索、任意）──────────────────────
HOUJIN_BANGOU_APP_ID=
HOUJIN_BANGOU_API_BASE=https://api.houjin-bangou.nta.go.jp/4/

# ─── API 使用量コスト計算（任意、$USD/単位）────────────────
LP_USAGE_ANTHROPIC_INPUT_PER_MTOK_USD=3.0
LP_USAGE_ANTHROPIC_OUTPUT_PER_MTOK_USD=15.0
LP_USAGE_HF_USD_PER_CALL=0.001

# ─── 画像アップロード上限（バイト）─────────────────────────
LP_USER_IMAGE_MAX_BYTES=10485760
LP_IMAGE_PACK_ZIP_UPLOAD_MAX_BYTES=104857600
LP_IMAGE_PACK_IMPORT_MAX_TOTAL_BYTES=104857600
LP_IMAGE_PACK_IMPORT_MAX_FILES=500
LP_IMAGE_PACK_IMPORT_MAX_ENTRY_BYTES=20971520

# ─── その他制限────────────────────────────────────────────
LP_LIST_IMAGES_MAX=200
LP_IMAGE_TEXT_MEMO_MAX=50
LP_IMAGE_TEXT_MEMO_MAX_BYTES=1048576
# LP_IMAGE_TEXT_MEMO_DISABLE=1   # メモ機能を無効にする場合
```

> **セキュリティ注意:** `.env` は必ず `.gitignore` に追加され、リポジトリにコミットされません。本番では `chmod 600 .env` を推奨します。

---

## 10. Apache 設定

### 10-1. PHP タイムアウト（Apache レベル）

解析・生成処理は長時間かかるため、Apache の `Timeout` も延ばします：

```apache
# /etc/apache2/apache2.conf または VirtualHost 内
Timeout 300
```

### 10-2. .htaccess（アプリに含まれている場合）

`lp_reverse_cms/` に `.htaccess` が存在する場合は `AllowOverride All` が必須。

### 10-3. output / data への外部アクセス制限

```apache
# data/ は直接アクセス禁止
<Directory /home/lp-next/current/lp_reverse_cms/data>
    Require all denied
</Directory>

# output/ 内の HTML は serve_workspace_output.php 経由で配信
# （直接アクセスはプレビュー動作のため許可）
```

### 10-4. セッション設定（推奨）

```bash
sudo nano /etc/php/8.2/apache2/php.ini
```

```ini
session.gc_maxlifetime = 86400   ; 24 時間
session.cookie_httponly = 1
session.cookie_secure = 1        ; HTTPS 使用時
session.use_strict_mode = 1
```

---

## 11. ファイル権限

```bash
APP=/home/lp-next/current/lp_reverse_cms

# アプリ全体: www-data が読み取れること
sudo find "$APP" -type f -exec chmod 644 {} \;
sudo find "$APP" -type d -exec chmod 755 {} \;
sudo chown -R www-data:www-data "$APP"

# data/ output/: www-data が書き込めること
sudo chmod -R 775 "$APP/data" "$APP/output"

# .env: 所有者のみ読み取り可
sudo chmod 600 "$APP/.env"
sudo chown www-data:www-data "$APP/.env"

# tools/ の PHP CLI ワーカーは実行権限不要（php コマンドで直接呼ぶ）
```

---

## 12. 初期ユーザー登録

### 12-1. スーパー管理者

`.env` の `CMS_SUPER_ADMIN` に Google アカウントのメールアドレスを設定すると、そのアカウントが最初からスーパー管理者として扱われます。

```dotenv
CMS_SUPER_ADMIN=admin@example.com
```

### 12-2. 一般ユーザー追加

1. スーパー管理者でログイン
2. ナビバー右上 → ハンバーガーメニュー → **ユーザー管理**
3. メールアドレス・表示名・ロールを設定して追加

### ロール一覧

| ロール | 権限 |
|--------|------|
| `super_admin` | 全機能 + ユーザー管理 |
| `admin` | 解析・編集・生成・プレビュー |
| `preview` | プレビュー閲覧のみ |

---

## 13. 動作確認

### 13-1. PHP 拡張確認

```bash
php -m | grep -E 'curl|gd|zip|mbstring|xml|json|intl'
```

すべて表示されれば OK。

### 13-2. 書き込みテスト

```bash
APP=/home/lp-next/current/lp_reverse_cms
sudo -u www-data touch "$APP/data/.write_test" && echo "OK" || echo "FAIL"
sudo -u www-data rm "$APP/data/.write_test"
```

### 13-3. 診断エンドポイント

ログイン後、ブラウザで以下にアクセス：

```
https://lp-next.jitan.app/lp_reverse_cms/store/debug.php
```

JSON が返ってくれば正常。内容で確認すべき項目：

- `files.asset_map` → 解析後 `true` になること
- `summary.unfetched_total` → 0 に近いこと
- `output_unreplaced.total` → 0 が理想

### 13-4. 環境変数確認

```
https://lp-next.jitan.app/lp_reverse_cms/store/env_keys_status.php
```

各 API キーの設定状態が確認できます（ログイン必須）。

---

## 14. GCP VM へのデプロイ

### 14-1. 標準デプロイ（git pull）

ローカルで変更をコミット・push 後：

```bash
# WSL 内から（shimizu ユーザー）
bash '/mnt/h/マイドライブ/projects/lp-next/deploy.sh'
```

`deploy.sh` の内容：

```bash
#!/bin/bash
set -e
echo "デプロイ開始..."
ssh gcp-lp "cd /home/lp-next/current/lp_reverse_cms && git pull origin main"
echo "完了！"
echo "https://lp-next.jitan.app/current/lp_reverse_cms/"
```

### 14-2. 初回デプロイ後に必要な追加作業

GCP VM 上で：

```bash
# .env 作成（リポジトリに含まれないため手動）
nano /home/lp-next/current/lp_reverse_cms/.env

# data/output ディレクトリ作成・権限設定
sudo mkdir -p /home/lp-next/current/lp_reverse_cms/data
sudo mkdir -p /home/lp-next/current/lp_reverse_cms/output
sudo chown -R www-data:www-data \
    /home/lp-next/current/lp_reverse_cms/data \
    /home/lp-next/current/lp_reverse_cms/output
sudo chmod -R 775 \
    /home/lp-next/current/lp_reverse_cms/data \
    /home/lp-next/current/lp_reverse_cms/output
```

### 14-3. デプロイ後の確認

```bash
ssh gcp-lp "tail -f /var/log/apache2/lp-next_error.log"
```

---

## 15. アップデート手順

### マイナー更新（コードのみ）

```bash
# ローカルで変更 → commit → push
git commit -m "fix: ..."
git push origin main

# デプロイ
bash deploy.sh
```

### バージョン番号の更新

`index.php` 冒頭の `define('APP_VERSION', ...)` を変更してからコミット：

```php
define('APP_VERSION', '1.5.007');
```

### DB・スキーマ変更なし

JSON ファイルストレージのため、マイグレーションは不要です。ただし `lp_structure.json` のフォーマットが変わる場合は、既存ワークスペースの再解析が必要になることがあります。

---

## 16. トラブルシューティング

### Apache が起動しない

```bash
sudo apache2ctl configtest   # 設定構文チェック
sudo journalctl -u apache2 -n 50
```

### PHP エラーが表示されない / 500 エラー

```bash
tail -f /var/log/apache2/lp-next_error.log
```

`php.ini` で `display_errors = Off`（本番推奨）のため、エラーはログに出力されます。

### data / output への書き込み失敗

```bash
sudo chown -R www-data:www-data /home/lp-next/current/lp_reverse_cms/data
sudo chmod -R 775 /home/lp-next/current/lp_reverse_cms/data
```

### OAuth ログインできない

1. `.env` の `GOOGLE_REDIRECT_URI` が Google Cloud Console の承認済み URI と一致しているか確認
2. HTTPS 環境でない場合は `session.cookie_secure = 0` に変更
3. `store/debug.php` の `version` が返ってくるか確認（PHP 自体は動いているか）

### 解析がタイムアウトする

- `php.ini` の `max_execution_time` を増やす
- 対象サイトが重い場合は、解析ワーカー（`tools/analyze_worker.php`）が CLI として起動されているため、Apache の Timeout とは別に PHP CLI の設定が適用される

### アバター画像が表示されない

Google のプロフィール画像 URL がセッションに保存されていない場合、イニシャル（名前の頭文字）にフォールバックします。再ログインで解消することがほとんどです。

---

*以上で Site Reverse CMS のゼロからの構築が完了します。問題が解決しない場合はリポジトリの Issues または開発チームまでご連絡ください。*
