# LP-NEXT デプロイ GUI アプリ — Python 実装仕様書

**目的:** `deploy.sh` と GCP↔GD rsync 手順を、Windows/Mac 双方でメンテ・実行できる
Python 製 GUI アプリ（単体実行ファイル）として置き換える。

**参照セッション:** https://claude.ai/epitaxy/local_1b8fafa7-d0cf-4d8f-9d18-db9794e5b1dd  
**作成日:** 2026-05-12

---

## 1. 背景と現状の問題

現在の運用は WSL（Windows Subsystem for Linux）上の bash スクリプトに依存している。

| 問題 | 内容 |
|------|------|
| WSL 依存 | Windows では WSL がないと `rsync` が実行できない |
| H: ドライブマウント | WSL から drvfs でマウントが必要（`sudo mount -t drvfs H: /mnt/h`） |
| drvfs の制約 | `mkstemp` 不可 → `--inplace` 必須。パーミッション変更不可 → `-a` 使用不可 |
| Mac では別パス | Google Drive のマウントパスが OS によって異なる |
| 手順書依存 | pull/push を間違えると本番データが消える。GUIで方向を明示したい |

---

## 2. アプリの要件

### 2.1 実行環境

| 項目 | 要件 |
|------|------|
| OS | Windows 10/11、macOS 12 以上 |
| 配布形式 | 単体実行ファイル（`.exe` / `.app`）。Python 不要 |
| ビルドツール | PyInstaller（推奨）または cx_Freeze |
| rsync | OS 標準 or バンドル。Windows は WSL rsync を呼び出すか、`cwrsync` を同梱 |

### 2.2 UI

- **GUI**: tkinter（標準ライブラリ、依存最小）または PyQt6
- **画面構成**: 単一ウィンドウ。操作は「方向選択 → 確認 → 実行 → ログ表示」の 4 ステップ
- **ログ**: リアルタイムストリーム表示（rsync の出力をそのまま流す）
- **ドライモード**: `--dry-run` で差分だけ確認できるトグルボタン

---

## 3. 操作フロー

```
┌─────────────────────────────────────────┐
│  LP-NEXT Deploy Tool                    │
│                                         │
│  方向: ○ Deploy (GD → GCP)  [デフォルト]  │
│        ○ Pull   (GCP → GD)              │
│                                         │
│  □ ドライラン（差分確認のみ）               │
│                                         │
│  [実行]                                  │
│                                         │
│  ────────────── ログ ──────────────────  │
│  sending incremental file list          │
│  lib/LpAnalyzer.php                     │
│  ...                                    │
│  sent 12,345 bytes  received 67 bytes   │
│  ✅ 完了                                 │
└─────────────────────────────────────────┘
```

---

## 4. rsync コマンド仕様

### 4.1 Deploy: GD → GCP（通常デプロイ）

```
rsync -rlz --delete --inplace \
  --exclude='.env' \
  --exclude='data/' \
  --exclude='output/' \
  --exclude='*.tar.gz' \
  {LOCAL_CMS_ROOT}/ \
  gcp-lp:/home/lp-next/current/lp_reverse_cms/
```

- `--delete`: GD にないファイルは GCP からも削除する
- `--inplace`: 転送先での一時ファイル作成を避ける（drvfs 対策として導入。GCP 側は通常 ext4 なので不要だが害もない）

### 4.2 Pull: GCP → GD（GCP の変更を手元に取り込む）

```
rsync -rlz --delete --inplace \
  --exclude='.env' \
  --exclude='data/' \
  --exclude='output/' \
  --exclude='*.tar.gz' \
  gcp-lp:/home/lp-next/current/lp_reverse_cms/ \
  {LOCAL_CMS_ROOT}/
```

- 方向が逆になるだけで、オプションは同一
- **`--delete` の危険性**: GCP 側の不要ファイルも消えるため、Pull 前に自動バックアップを取ることを推奨

### 4.3 オプション解説

| オプション | 理由 |
|-----------|------|
| `-r` | recursive（サブディレクトリも同期） |
| `-l` | シンボリックリンクをリンクのまま転送 |
| `-z` | 転送時に圧縮 |
| `--delete` | 送信元にないファイルを送信先から削除 |
| `--inplace` | mkstemp を使わず直接上書き（Windows drvfs 必須） |
| `-a` は**使用しない** | `chown`, `chmod`, `utimes` が drvfs で失敗するため |

---

## 5. パス設定

### 5.1 LOCAL_CMS_ROOT（プラットフォーム別）

| OS | 既定パス |
|----|---------|
| Windows | `H:\マイドライブ\projects\lp-next\current\lp_reverse_cms` |
| Mac | `~/Library/CloudStorage/GoogleDrive-{email}/マイドライブ/projects/lp-next/current/lp_reverse_cms` または `~/Google Drive/マイドライブ/...` |

- アプリ初回起動時にパスを入力させ、設定ファイル（`~/.lp_deploy_config.json`）に保存する

### 5.2 SSH ホスト

```
gcp-lp
```

`~/.ssh/config` に定義済みのエイリアス。アプリは SSH 設定そのものは変更しない。

---

## 6. 自動バックアップ機能（Pull 前）

Pull 実行前に、ローカル CMS ディレクトリを tar.gz でバックアップする。

```
{LOCAL_PROJECT_ROOT}/lp_reverse_cms_v{VERSION}_backup_{YYYYMMDD}.tar.gz
```

- バージョン番号は `lp_reverse_cms/index.php` の `APP_VERSION` 定数を読み取る
- `data/`, `output/`, `*.tar.gz` は除外
- バックアップ作成後に Pull を実行する

---

## 7. rsync 実行方法（Python 側の実装方針）

### Windows の場合

```python
import subprocess, platform

if platform.system() == 'Windows':
    # WSL 経由で rsync を呼ぶ
    # LOCAL_CMS_ROOT の Windows パスを /mnt/h/... 形式に変換
    wsl_path = local_path.replace('H:\\', '/mnt/h/').replace('\\', '/')
    cmd = ['wsl', 'rsync', ...flags..., wsl_path + '/', remote]
else:
    # Mac/Linux はそのまま
    cmd = ['rsync', ...flags..., local_path + '/', remote]

proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
for line in proc.stdout:
    log_callback(line)  # GUI のログエリアにリアルタイム表示
```

### Mac の場合

```python
cmd = ['rsync', '-rlz', '--delete', '--inplace',
       '--exclude=.env', '--exclude=data/', '--exclude=output/', '--exclude=*.tar.gz',
       local_path + '/', 'gcp-lp:/home/lp-next/current/lp_reverse_cms/']
```

---

## 8. 設定ファイル仕様

`~/.lp_deploy_config.json`:

```json
{
  "local_cms_root": "H:\\マイドライブ\\projects\\lp-next\\current\\lp_reverse_cms",
  "remote_host": "gcp-lp",
  "remote_path": "/home/lp-next/current/lp_reverse_cms",
  "backup_before_pull": true,
  "auto_dry_run_first": false
}
```

---

## 9. ビルド手順（PyInstaller）

```bash
# 仮想環境を作成
python -m venv venv
source venv/bin/activate  # Mac
venv\Scripts\activate     # Windows

pip install pyinstaller

# Windows 向けビルド
pyinstaller --onefile --windowed --name "LP-NEXT Deploy" deploy_gui.py

# Mac 向けビルド
pyinstaller --onefile --windowed --name "LP-NEXT Deploy" deploy_gui.py
# → dist/LP-NEXT Deploy.app
```

---

## 10. 今後の拡張候補

| 機能 | 優先度 |
|------|--------|
| 接続テスト（`ssh gcp-lp echo ok`）ボタン | 高 |
| 差分ファイル一覧の表示（dry-run 結果をパース） | 高 |
| バックアップ一覧と削除 UI | 中 |
| git ステータス表示（GCP 側が未コミットか確認） | 中 |
| 複数プロジェクト対応（プロファイル切り替え） | 低 |

---

## 関連ファイル

| ファイル | 場所 |
|---------|------|
| `deploy.sh` | `H:\マイドライブ\projects\lp-next\deploy.sh` |
| `v1.5.0-gd-backup.md` | `H:\マイドライブ\projects\lp-next\v1.5.0-gd-backup.md` |
| `DEPLOY_NOTES_20260512.md` | `H:\マイドライブ\projects\lp-next\DEPLOY_NOTES_20260512.md` |
