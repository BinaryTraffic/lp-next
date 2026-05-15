# 開発ワークフロー — LP-NEXT / Site Reverse CMS

最終更新: 2026-05-15

---

## 環境の役割分担

```
[PC ローカル (Windows)]        [GitHub]                   [GCP VM 本番]
  C:\Users\hshim\Documents\     BinaryTraffic/lp-next      /home/lp-next
  IPI\lp-next\                  ↑ git push                 ↓ git fetch/checkout
  （Apache VH → WSL mount）     fix/* → PR → main          https://lp-next.jitan.app/
```

| 環境 | 目的 | URL / パス |
|------|------|-----------|
| **PC ローカル (Windows)** | 開発・修正・動作確認 | `http://localhost/current/lp_reverse_cms/` |
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
