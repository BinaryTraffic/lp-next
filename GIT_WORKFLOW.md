# LP-NEXT Git ワークフロー

**確立日:** 2026-05-12  
**移行前:** GD ↔ GCP を rsync で直接同期（WSL 依存・手順書依存）  
**移行後:** GitHub をハブとした git ベースのデプロイ

---

## アーキテクチャ

```
Google Drive (開発)
      │
      │ git commit + git push
      ▼
  GitHub (main) ◀── 唯一の正
      │
      │ git pull（deploy.sh）
      ▼
  GCP 本番 (lp-next.jitan.app)
```

- **開発は GD（Google Drive）上で行う**
- **GitHub が常に正**（GD と GCP の橋渡し）
- **デプロイは `deploy.sh` 一発**（内部は `ssh gcp-lp "git pull"`）
- rsync は原則不要。WSL も不要

---

## 日常の開発フロー

```bash
# 1. GD 上でコード編集（Windows / Mac どちらでも）

# 2. コミット＆プッシュ（WSL または Mac ターミナルから）
cd /mnt/h/マイドライブ/projects/lp-next/current/lp_reverse_cms   # Windows WSL
# または
cd ~/Google\ Drive/マイドライブ/projects/lp-next/current/lp_reverse_cms  # Mac

git add -A
git status          # data/ output/ .env が含まれていないことを確認
git commit -m "説明"
git push origin main

# 3. GCP にデプロイ
bash /mnt/h/マイドライブ/projects/lp-next/deploy.sh
# または（Mac から）
bash ~/Google\ Drive/マイドライブ/projects/lp-next/deploy.sh
```

---

## 初回のみ: GCP で git 設定

GCP 側に SSH してリモートを確認・設定する（初回一度だけ）。

```bash
ssh gcp-lp
cd /home/lp-next/current/lp_reverse_cms

# リモート確認
git remote -v
# → origin  https://github.com/BinaryTraffic/lp-next.git (fetch/push) が出ればOK

# なければ追加
git remote add origin https://github.com/BinaryTraffic/lp-next.git

# ブランチ確認
git branch
# → * main が出ればOK

exit
```

---

## .gitignore の確認事項

以下が除外されていること（本番データを誤って push しない）:

```
data/
output/
.env
*.tar.gz
```

確認コマンド:
```bash
git check-ignore -v data/ output/ .env
```

---

## GCP 側で直接編集してしまったとき（緊急時）

GCP 上で直接ファイルを変更した場合は、**必ず GCP から push して GitHub を更新してから**
GD に pull する。逆をやると GCP の変更が消える。

```bash
# GCP 上で
ssh gcp-lp
cd /home/lp-next/current/lp_reverse_cms
git add -A
git commit -m "hotfix: 内容"
git push origin main

# GD 側に反映（Windows WSL）
cd /mnt/h/マイドライブ/projects/lp-next/current/lp_reverse_cms
git pull origin main
```

---

## 一度だけ必要な移行作業（2026-05-12）

今回の移行で行う順序。**この節は完了後に削除してよい。**

### Step 1: GD → GCP へ今日の修正を rsync（最後の rsync）

```bash
# WSL から
rsync -rlz --delete --inplace \
  --exclude='.env' \
  --exclude='data/' \
  --exclude='output/' \
  --exclude='*.tar.gz' \
  /mnt/h/マイドライブ/projects/lp-next/current/lp_reverse_cms/ \
  gcp-lp:/home/lp-next/current/lp_reverse_cms/
```

反映される変更:
- `store/analyze_start.php` — ログパスを task_id 単位に変更
- `store/generate_start.php` — ログパスを task_id 単位に変更
- `deploy.sh` — git pull ベースに書き換え

### Step 2: GCP 上でコミット＆プッシュ（167ファイル分を一括）

```bash
ssh gcp-lp
cd /home/lp-next/current/lp_reverse_cms

# .gitignore を念のため確認
git status --short | head -30

# コミット
git add -A
git commit -m "v1.5.0: fix picture source desktop override, cssHaystack separation, worker log per task_id, drvfs rsync flags"

# プッシュ
git push origin main

exit
```

### Step 3: GD を GitHub から同期（GD と GitHub を一致させる）

```bash
# WSL から
cd /mnt/h/マイドライブ/projects/lp-next/current/lp_reverse_cms
git pull origin main
```

### Step 4: 動作確認

```bash
# GCP のデプロイが git pull で動くか確認
bash /mnt/h/マイドライブ/projects/lp-next/deploy.sh
```

---

## よくある操作リファレンス

| 操作 | コマンド |
|------|---------|
| 状態確認 | `git status` |
| 差分確認 | `git diff` |
| コミット履歴 | `git log --oneline -10` |
| GCP の状態確認 | `ssh gcp-lp "cd /home/lp-next/current/lp_reverse_cms && git log --oneline -5"` |
| GD と GitHub が一致しているか | `git fetch && git status` |
| 直前のコミットを取り消す（未push） | `git reset HEAD~1` |
| GCP のコードをバージョン指定で戻す | `ssh gcp-lp "cd /home/lp-next/... && git checkout {hash}"` |

---

## 関連ファイル

| ファイル | 役割 |
|---------|------|
| `deploy.sh` | `ssh gcp-lp "git pull origin main"` のラッパー |
| `v1.5.0-gd-backup.md` | 今回の移行セッション記録 |
| `lp_reverse_cms_v1.5.0-gd-backup_20260512.tar.gz` | 移行前の GD バックアップ |
