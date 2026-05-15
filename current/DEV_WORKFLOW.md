# 開発ワークフロー — LP-NEXT / Site Reverse CMS

最終更新: 2026-05-15

---

## 環境の役割分担

```
[PC (Windows)]                 [GitHub]                   [GCP VM 本番]
  C:\Users\hshim\Documents\     BinaryTraffic/lp-next      /home/lp-next
  IPI\lp-next\                  ↑ git push                 ↓ git fetch/checkout
  （Apache VH → WSL mount）     fix/* → PR → main          https://lp-next.jitan.app/

[Mac OSX（ノマド）]
  Google Drive 経由でリポジトリにアクセス
  ↑↓ git push / pull
  ※ PC と Mac は同時開発しない（排他利用）
```

| 環境 | 目的 | URL / パス |
|------|------|-----------|
| **PC ローカル (Windows)** | 開発・修正・動作確認 | `http://localhost/current/lp_reverse_cms/` |
| **Mac OSX（ノマド）** | Google Drive 経由で開発（PC と排他利用） | ローカル MAMP 等 |
| **GitHub (BinaryTraffic/lp-next)** | コード管理・PR レビュー | `https://github.com/BinaryTraffic/lp-next` |
| **GCP VM** | QC 確認・本番配信 | `https://lp-next.jitan.app/current/lp_reverse_cms/` |

---

## PC ローカル環境の実態

| 項目 | パス |
|------|------|
| **メインリポジトリ** | `C:\Users\hshim\Documents\IPI\lp-next` |
| **作業用クローン（git 破損回避）** | `C:\Users\hshim\Documents\IPI\lp-next-fresh` |
| **Apache VH が指すディレクトリ** | WSL から `/mnt/c/Users/hshim/Documents/IPI/lp-next/current` |
| **Apache 起動** | WSL で `sudo service apache2 start` |

> **lp-next-fresh について**  
> メインリポジトリの `.git/` 内に `desktop.ini` が混入して `git fetch` が壊れた経緯があり、
> コミット・プッシュ作業は `lp-next-fresh` クローンで行っている。  
> PR #1 が `main` にマージされたら `lp-next-fresh` は削除して良い。

---

## 日常の開発フロー（QC 修正時）

```
1. lp-next-fresh でコード修正
        ↓
2. ブラウザで http://localhost/... 動作確認
        ↓
3. git add / git commit（PowerShell or Claude Code から）
        ↓
4. git push origin fix/<ブランチ名>
        ↓
5. GCP VM へ fetch & checkout（後述手順）
        ↓
6. ナビバーのコミットハッシュで反映確認
        ↓
7. QC が lp-next.jitan.app で確認
        ↓
8. 全 SEQ 完了 → GitHub で PR をマージ → GCP を main に戻す
```

---

## コミット・Push 手順

### PowerShell から（基本）

```powershell
# 作業用クローンで操作
cd C:\Users\hshim\Documents\IPI\lp-next-fresh

# 状態確認
git status
git diff

# ステージング（ファイルを指定して追加）
git add current/lp_reverse_cms/assets/js/index.js

# コミット
git commit -m "fix: 修正内容の説明"

# Push
git push origin fix/image-load-retry
```

### Claude Code から

ツール経由で `git -C "C:\Users\hshim\Documents\IPI\lp-next-fresh" ...` で操作可能。

---

## GCP VM へのデプロイ手順

### 前提：VPN 接続が必要

**OpenVPN Connect** で `vpn-public45.glocalnet.jp` に接続してから SSH する。  
VPN 未接続だと `Connection timed out` になる。

### フィーチャーブランチを反映する（PR マージ前）

```bash
# SSH 接続
ssh gcp-lp

# GCP VM での操作
cd /home/lp-next

# ブランチを fetch（初回）
git fetch origin fix/image-load-retry

# ブランチを作成してチェックアウト
git checkout -b fix/image-load-retry FETCH_HEAD
# → すでにブランチが存在する場合:
# git checkout fix/image-load-retry && git reset --hard FETCH_HEAD
```

### 同ブランチに新しいコミットを追加反映する

```bash
ssh gcp-lp
cd /home/lp-next
git fetch origin fix/image-load-retry
git reset --hard FETCH_HEAD
```

### PR マージ後に main に戻す

```bash
ssh gcp-lp
cd /home/lp-next
git checkout main
git pull origin main
```

---

## デプロイ確認方法

ナビバーに **Git 短ハッシュ** が表示される：

```
Site Reverse CMS  [v1.5.006 · 20260515+1423 #8268957]
```

`#8268957` の部分が最新コミットのハッシュと一致していれば反映済み。

### ハッシュの確認コマンド

```bash
# GitHub 上の最新ハッシュ確認
git -C "C:\Users\hshim\Documents\IPI\lp-next-fresh" log --oneline -1

# GCP VM 上のハッシュ確認
ssh gcp-lp "cd /home/lp-next && git rev-parse --short HEAD"
```

---

## PR マージ後の手順まとめ

1. GitHub で PR #1 をマージ
2. GCP VM を `main` に戻す:
   ```bash
   ssh gcp-lp "cd /home/lp-next && git checkout main && git pull origin main"
   ```
3. ナビバーのハッシュが `main` の最新コミットと一致することを確認
4. `lp-next-fresh` ディレクトリは削除して良い

---

## ブランチ運用ルール

| ブランチ | 用途 |
|----------|------|
| `main` | 本番安定版。GCP VM の平時はここ |
| `fix/<topic>` | QC 修正・バグ修正。PR 経由で main にマージ |
| `feature/<topic>` | 新機能開発 |

---

## トラブルシューティング

| 現象 | 対処 |
|------|------|
| `ssh gcp-lp` がタイムアウト | OpenVPN Connect で VPN 接続してから再試行 |
| `git fetch` が `fatal: bad object` | `.git/` 内に `desktop.ini` 等が混入。`lp-next-fresh` から作業する |
| ローカルで CMS が開かない | WSL で `sudo service apache2 start` |
| 解析でファイル書き込みエラー | `data/` `output/` を `chown www-data` |
| GCP に反映されない | `git fetch + reset --hard FETCH_HEAD` を再実行。VPN 接続も確認 |
| ナビバーのハッシュが古い | ブラウザのハードリロード（Ctrl+Shift+R）。または PHP の OPcache をリセット |
| GCP の `git checkout` が `pathspec` エラー | `git checkout -b ブランチ名 FETCH_HEAD` で新規作成する |
| PHP が BOM を出力して JSON が壊れる | 下記「BOM 問題」参照 |
| `git fetch` が `fatal: bad object refs/desktop.ini` | 下記「desktop.ini 問題」参照 |

---

## Windows / Mac 混在環境の注意点

PC（Windows）と Mac OSX（Google Drive ノマド）は**同時開発しない**が、  
OS の違いによるファイル汚染が git を壊すことがある。

### desktop.ini 問題（Windows 固有）

Windows エクスプローラーがフォルダを開くと `.git/` 直下に `desktop.ini` を自動生成することがある。  
これが git の refs と衝突し、以下のエラーが発生する：

```
fatal: bad object refs/desktop.ini
```

**確認・対処：**

```powershell
# .git/ 内の desktop.ini を確認
Get-ChildItem -Path "C:\Users\hshim\Documents\IPI\lp-next\.git" -Filter "desktop.ini" -Recurse

# 削除
Remove-Item "C:\Users\hshim\Documents\IPI\lp-next\.git\desktop.ini" -Force
```

削除後も `packed-refs` にエントリが残っている場合は完全回復しないことがある。  
**その場合は新規クローンで対処**（現在の `lp-next-fresh` はこの理由で作成）：

```powershell
git clone https://github.com/BinaryTraffic/lp-next.git C:\Users\hshim\Documents\IPI\lp-next-fresh
```

**再発防止：** `.git/` フォルダをエクスプローラーで直接開かない。

---

### BOM（Byte Order Mark）問題（Mac / Windows 混在）

Mac の一部エディタや Google Drive 経由の編集で、PHP・JSON ファイル先頭に  
**UTF-8 BOM（`\xEF\xBB\xBF`）** が付くことがある。  
PHP は BOM を出力してしまい、`Content-Type: application/json` のレスポンスが壊れる。

**症状：**
- `store/*.php` の JSON レスポンス先頭に `???` や文字化けが混入
- JavaScript 側で `JSON.parse()` がエラー
- `json_decode()` が `null` を返す

**確認方法（PowerShell）：**

```powershell
# BOM が付いているファイルを検出
$files = Get-ChildItem -Path "C:\Users\hshim\Documents\IPI\lp-next\current" -Recurse -Include "*.php","*.json"
foreach ($f in $files) {
    $bytes = [System.IO.File]::ReadAllBytes($f.FullName)
    if ($bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        Write-Host "BOM detected: $($f.FullName)"
    }
}
```

**確認方法（Mac / WSL）：**

```bash
# BOM 付きファイルを検出
grep -rl $'\xef\xbb\xbf' current/lp_reverse_cms/
```

**除去方法（Mac / WSL）：**

```bash
# 1ファイル除去
sed -i '1s/^\xef\xbb\xbf//' current/lp_reverse_cms/store/some_file.php

# ディレクトリ一括除去
find current/lp_reverse_cms -name "*.php" -o -name "*.json" | \
  xargs sed -i '1s/^\xef\xbb\xbf//'
```

**予防策：**
- VS Code では `files.encoding: utf8`（BOM なし）に設定する
- Mac の場合、エディタの「エンコーディング」を **UTF-8（BOM なし）** に固定する
- `.gitattributes` に以下を追記しておくと git 管理上の確認が容易：

```gitattributes
*.php text eol=lf
*.json text eol=lf
*.js text eol=lf
*.css text eol=lf
```
