# 非同期ワーカー プロセス堅牢化 指示書

## 背景

`workspace_delete_async_start.php` + `workspace_delete_async_worker.php` による
非同期削除ジョブに、以下3点の問題が残っている。

---

## Fix 1: プロセス切り離し（最優先）

### 問題

```php
// 現状
'php worker.php ... > /dev/null 2>&1 &'
shell_exec($cmd);
```

`&` のみでは PHP-FPM のプロセスグループに子プロセスとして残る。
FPM 再起動・デプロイ時に SIGHUP がプロセスグループ全体に送られ、
ワーカーが中断される。

### 修正箇所: `store/workspace_delete_async_start.php`

```php
// 修正後
$cmd = 'nohup setsid php ' . escapeshellarg($worker) . ' '
    . escapeshellarg($cmsRoot) . ' '
    . escapeshellarg($taskId)
    . ' > /dev/null 2>&1 &';
shell_exec($cmd);
```

`nohup` + `setsid` の組み合わせにより：
- `nohup`: SIGHUP を無視してプロセスを継続
- `setsid`: 新しいセッション・プロセスグループを作成し FPM から完全切り離し

---

## Fix 2: ワーカークラッシュ時の stale 検出（最優先）

### 問題

ワーカーが OOM / SIGKILL / 予期しない死亡で終了すると、
タスク JSON の `status` が `"running"` のまま永久ブロックとなる。
フロントは永遠にポーリングし続け、次の削除もブロックされる。

### 修正箇所1: `tools/workspace_delete_async_worker.php`

ワーカー起動直後に自 PID をタスクに書き込む：

```php
// status = 'running' に変更した直後に追加
$task['pid'] = getmypid();
$task['started_at'] = time();
WorkspaceDeleteTask::save($cmsRoot, $taskId, $task);
```

### 修正箇所2: `store/workspace_delete_async_progress.php`

進捗 API でステータスが `running` の場合、PID 生存確認とタイムアウトを追加する：

```php
if ($status === 'running') {
    $pid     = (int) ($task['pid'] ?? 0);
    $startAt = (int) ($task['started_at'] ?? 0);
    $age     = time() - $startAt;

    $pidAlive = $pid > 0 && (
        // posix_kill(pid, 0) はプロセス存在確認のみ（シグナル未送信）
        function_exists('posix_kill') ? posix_kill($pid, 0) : true
    );

    if (!$pidAlive || $age > 600) {
        $task['status'] = 'stale';
        $task['error']  = $pidAlive
            ? 'timeout (>600s)'
            : 'worker process not found (pid=' . $pid . ')';
        WorkspaceDeleteTask::save($cmsRoot, $taskId, $task);
        $status = 'stale';
    }
}
```

`stale` を受け取ったフロントはポーリングを停止し、エラーとして表示する。

### 修正箇所3: `assets/js/workspace_manage.js`

ポーリング停止条件に `stale` を追加する（現状 `done` / `error` のみ）：

```js
// 停止条件
if (['done', 'error', 'stale'].includes(data.status)) {
    clearInterval(pollTimer);
    // stale の場合はエラーメッセージを表示
    if (data.status === 'stale') {
        showDeleteError('削除ジョブが応答しなくなりました。管理者に確認してください。');
    }
}
```

---

## Fix 3: 二重起動チェックをアトミックに（低優先）

### 問題

`running` チェック → `create` の間に別リクエストが来ると、
2つのワーカーが同じタスクを二重実行する。

### 修正箇所: `lib/WorkspaceDeleteTask.php` の `create()`

`running` チェックと `create` を同一の `withFileLock(LOCK_EX)` 内に入れる。
または `workspace_delete_async_start.php` 側でロックを取得してから確認・作成する。

```php
// WorkspaceDeleteTask::createIfNotRunning() として新設するか、
// start.php 側でロックファイル (.delete_job.lock) を確保してから
// 既存 running ジョブの有無を確認し、create → shell_exec する順序で実装。
```

---

## 変更対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `store/workspace_delete_async_start.php` | `nohup setsid` 追加 |
| `tools/workspace_delete_async_worker.php` | `pid` / `started_at` をタスクに記録 |
| `store/workspace_delete_async_progress.php` | stale 検出ロジック追加 |
| `assets/js/workspace_manage.js` | ポーリング停止条件に `stale` 追加 |
| `lib/WorkspaceDeleteTask.php` | （Fix3）create をロック内に移動 |

---

## 検証手順

1. VM で `git pull`
2. ワークスペース複数選択 → 削除開始
3. ブラウザを閉じて10秒後に再オープン → ポーリングが再開し 023/023 になることを確認
4. FPM を再起動（`sudo systemctl restart php8.x-fpm`）しても削除が継続されることを確認
5. ワーカーを `kill -9` で強制終了 → 10分後または即時に `stale` 表示になることを確認
