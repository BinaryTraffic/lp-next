# 開発ワークフロー — LP-NEXT v1.5

最終更新: 2026-05-11

---

## 環境の役割分担

```
[PC ローカル (WSL)]          [GitHub]            [GCP VM 本番]
  開発・動作確認              lp-next/main         lp-next.jitan.app
  http://localhost/           ↑push / tag          ↓git pull
  current/lp_reverse_cms/                         デプロイ反映
```

| 環境 | 目的 | URL |
|------|------|-----|
| **PC ローカル (WSL)** | 開発・動作確認・QCフィードバック検証 | `http://localhost/current/lp_reverse_cms/` |
| **GitHub (main)** | 正式コード管理・リリースタグ | `github.com/BinaryTraffic/lp-next` |
| **GCP VM 本番** | 本番配信・QCテスト | `https://lp-next.jitan.app/current/lp_reverse_cms/` |

---

## 日常の開発フロー

```
1. ローカルで実装・修正
        ↓
2. http://localhost/current/lp_reverse_cms/ で動作確認
        ↓
3. OK → git commit（WSLまたはCursorから）
        ↓
4. git push origin main（Windows PowerShellから）
        ↓
5. GCP VMで git pull → 本番反映
        ↓
6. QCが lp-next.jitan.app で確認
        ↓
7. フィードバック → ローカルに戻って修正（Step 1へ）
```

---

## ローカル環境

### アクセス先
- **CMS管理画面**: `http://localhost/current/lp_reverse_cms/`
- **ソースコード**: `/home/shimizu/project/current/lp_reverse_cms/`（WSL）
- **Windowsからの参照**: `\\wsl$\Ubuntu\home\shimizu\project\current\lp_reverse_cms\`

### Apache起動
WSLを開いて毎回起動:
```bash
sudo service apache2 start
```

### ローカル .env
`/home/shimizu/project/current/lp_reverse_cms/.env`

```dotenv
GOOGLE_CLIENT_ID=269176379460-xxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=（要設定）
GOOGLE_REDIRECT_URI=http://localhost/current/lp_reverse_cms/store/auth_callback.php
CMS_SUPER_ADMIN=shimizu@binarytraffic.jp
ANTHROPIC_API_KEY=（必要に応じて設定）
HF_API_KEY=（必要に応じて設定）
```

> **注意**: `.env` は `.gitignore` 対象。本番と別の値でOK。

### data/ / output/ 権限
```bash
sudo chown -R www-data:www-data \
    /home/shimizu/project/current/lp_reverse_cms/data \
    /home/shimizu/project/current/lp_reverse_cms/output
sudo chmod -R u+rwX,g+rX \
    /home/shimizu/project/current/lp_reverse_cms/data \
    /home/shimizu/project/current/lp_reverse_cms/output
```

---

## コミット・Push 手順

### WSL上でのgit操作
```bash
cd /home/shimizu/project

# 状態確認
git status
git diff

# ステージング（対象ファイルを指定）
git add current/lp_reverse_cms/lib/LpGenerator.php

# コミット
git commit -m "fix: 内部ページナビリンクの深さ補正"

# Push（WSLからはGitHub接続できないのでPowerShellを使う）
```

### PowerShellからのPush
```powershell
# Windowsターミナル（PowerShell）で実行
git -C \\wsl$\Ubuntu\home\shimizu\project push origin main
```

または Windows GitがWSLリポジトリを認識できない場合:
```powershell
# 一時クローン経由でpush（WSLパッチを当てる方式）
$tmp = "$env:TEMP\lp-push-$(Get-Random)"
git clone https://github.com/BinaryTraffic/lp-next.git $tmp
# → WSLの変更をパッチで適用してpush
```

---

## GCP本番デプロイ

```bash
# SSH接続
ssh gcp-lp

# 本番ディレクトリで pull
cd /home/lp-next
git pull origin main

# タグでのデプロイ（リリース時）
git pull origin main
git fetch --tags
git tag -l | sort -V | tail -5   # 最新タグ確認
```

---

## リリースタグ運用

```bash
# バージョンバンプ（index.phpのAPP_VERSIONを更新）
# → commit & push

# PowerShellからタグを打つ
$tmp = "$env:TEMP\lp-tag-$(Get-Random)"
git clone https://github.com/BinaryTraffic/lp-next.git $tmp
git -C $tmp tag -a v1.6.0 -m "v1.6.0 — ..."
git -C $tmp push origin v1.6.0
Remove-Item -Recurse -Force $tmp
```

---

## QCフィードバックの受け方

### フィードバックの流れ
```
QC → lp-next.jitan.app で確認
   → GitHubのIssueまたはSlackで報告
   → ローカルで再現・修正
   → commit → push → GCP pull で反映
   → QCに「確認してください」と連絡
```

### 環境別の役割
| 環境 | 担当 |
|------|------|
| ローカル (WSL) | shimizu（開発・修正・確認） |
| GCP本番 | QCチーム（動作確認・フィードバック） |
| GitHub | コードレビュー・バージョン管理 |

---

## PHP拡張一覧（必須）

```bash
# 確認コマンド
php -m | grep -E 'curl|dom|json|mbstring|gd|zip'

# インストール（未入りの場合）
sudo apt-get install -y php8.2-gd php8.2-zip
sudo service apache2 restart
```

| 拡張 | 用途 | 状態 |
|------|------|------|
| curl | HTML取得 | ✅ |
| dom | HTML解析 | ✅ |
| json | データ管理 | ✅ |
| mbstring | 日本語処理 | ✅ |
| gd | 画像合成（S1機能） | 要インストール |
| zip | エクスポート | 要インストール |

---

## トラブルシューティング

| 現象 | 対処 |
|------|------|
| ローカルでCMSが開かない | `sudo service apache2 start` |
| 解析でファイル書き込みエラー | `data/` `output/` の `chown www-data` |
| pushできない | PowerShell経由でpush（WSLは直接push不可） |
| 本番に反映されない | GCPで `git pull origin main` 実行 |
| Google認証エラー | `.env` の `REDIRECT_URI` がlocalhostになっているか確認 |
