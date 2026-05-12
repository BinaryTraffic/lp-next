# ワークスペース削除とファイル権限

## 概要

CMS の「ワークスペース」一覧から `ws_*` を削除すると、`data/ws_*` と `output/ws_*` のディレクトリツリーをサーバー側で削除する。削除リクエストは **Apache 経由（ユーザー `www-data`）** で処理される。

## よくある症状

- **super_admin（SU）でも**「未登録（legacy）」の `ws_*` が削除できない。
- ブラウザではボタンは押せるが、API が **500** になる／アラートだけ出る。
- ディスク上は `data/ws_*` / `output/ws_*` が **`shimizu:lp-tool`**、ディレクトリ **`2775`（setgid）**、ファイル **`664`** などになっている。

## 原因

1. **権限モデル**  
   CLI や過去の処理で作成されたツリーが **`所有者: shimizu`・グループ `lp-tool`**・**グループ書き込み可**になっている。削除時は **`www-data` がグループ `lp-tool` の権限で書き込める必要**がある。

2. **`www-data` が `lp-tool` に未所属**の場合  
   `unlink` / `rmdir` が **Permission denied** になり、`LpFs::removeTree()` が失敗する。

3. **アプリ権限（別問題）**  
   「未登録」フォルダは **`WorkspaceRegistry::deleteIfAllowed`** で **`super_admin` のみ**許可。一覧も **`super_admin` にのみ** legacy 行を返す（通常ユーザーには見えない）。

## サーバー側の対処（本番 VM で実施済みの例）

Apache が新しい補助グループを認識するまで **プロセス再起動が必要**。

```bash
sudo usermod -aG lp-tool www-data
sudo systemctl restart apache2
```

確認:

```bash
sudo -u www-data groups   # 出力に lp-tool が含まれること
```

## 新規デプロイ時のチェックリスト

- [ ] `www-data` が **`lp-tool` グループに所属**している（上記 `usermod`）。
- [ ] **Apache 再起動後**にワーカー／PHP が新しいグループを拾っている。
- [ ] `CMS_SUPER_ADMIN` と **`auth_users.json`** で SU メールが一致している（`UserRegistry::getRole` が `super_admin` を返す）。
- [ ] 削除失敗時は **`workspace_delete.php` の JSON `error`** を確認（権限ヒントが付くコードパスあり）。

## アプリケーション側の関連実装

| 項目 | 場所 |
|------|------|
| 削除 API | `store/workspace_delete.php` |
| 許可判定・ツリー削除 | `lib/WorkspaceRegistry.php` の `deleteIfAllowed()` |
| 実削除 | `lib/LpFs.php` の `removeTree()`（失敗時は例外＋OS メッセージ） |
| 一覧・`can_delete` | `store/workspace_list.php` → `WorkspaceRegistry::listForActor()` |
| legacy 行の UI | `assets/js/workspace_manage.js`（一覧はサーバー契約に合わせて削除 UI を出す） |

## ジョブ一覧との見え方

「実行中ジョブ」に **対象 WS がディスクに無い**のにジョブだけ残る場合は、`store/job_list.php` 取得時に **`JobRegistry::reconcileStaleJobs()`** が走り、`stopping` の長期滞留などを整理する。またレスポンスに **`workspace_disk_present`** がある場合があり、フォルダ欠落が分かる。

## 参考コミット（履歴）

- 削除時の権限エラー文言・`LpFs` の明示的エラー: `90495a1` 付近
- legacy 削除 UI と `can_delete`・ジョブ reconcile: `75f5c8c` / `13a4a19` 付近

---

*最終更新: ワークスペース削除が `www-data` + `lp-tool` で成功することを確認した運用メモとして作成。*
