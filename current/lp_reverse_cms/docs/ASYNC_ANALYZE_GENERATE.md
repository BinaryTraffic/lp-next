# 解析・生成フロー 非同期化 仕様書

## 背景・目的

長時間の PHP プロセス（解析 5〜30分、生成 1〜10分）が Apache スレッドを
占有し、ブラウザが固まる問題を根本解決する。

ws 削除で実証済みのパターン（非同期ワーカー + ポーリング）を
解析・生成フローに横展開する。

---

## アーキテクチャ

### 現状（同期）

```
ブラウザ ─── HTTP接続を保持（N分間） ───→ analyze_entry.php
                NDJSON ストリームで進捗プッシュ ←─
```

### 変更後（非同期）

```
ブラウザ ─→ analyze_start.php ─→ job_id を即返却（ms）
              ↓ nohup setsid
           analyze_worker.php（Apache と独立したプロセス）
              ↓ 進捗を {sha1(email)}.progress に書き込み
ブラウザ ─→ analyze_progress.php をポーリング（2〜3秒）
              ↓ 001/020 → 020/020 → done
           clearInterval
```

---

## ジョブ状態管理

ws 削除と同じ `WorkspaceDeleteTask` 方式を踏襲。
解析・生成それぞれ専用クラスを設ける。

### ファイル構造

```
data/
  analyze_tasks/
    {sha1(email)}.progress    ← 最新 task_id へのポインタ
    {task_id}.json            ← タスク本体
  generate_tasks/
    {sha1(email)}.progress
    {task_id}.json
```

### タスク JSON スキーマ

```json
{
  "task_id":      "ana_xxxxxxxxxxxxxxxx",
  "owner_email":  "user@example.com",
  "owner_role":   "admin",
  "status":       "pending | running | done | error | stale",
  "phase":        "fetch | analyze_entry | analyze_internal | finalize",
  "progress_text": "012/020",
  "pid":          12345,
  "started_at":   1700000000,
  "ended_at":     null,
  "source_url":   "https://example.com/",
  "workspace_id": "ws_xxxx",
  "error":        null
}
```

---

## 新規ファイル一覧

### 解析フロー

| ファイル | 役割 |
|---------|------|
| `store/analyze_start.php` | ジョブ作成 + ワーカー起動（即返却） |
| `store/analyze_progress.php` | 進捗取得（ポーリング用） |
| `tools/analyze_worker.php` | 独立プロセス。既存の fetch_lp + analyze_entry + analyze_internal + finalize を順次実行 |
| `lib/AnalyzeTask.php` | WorkspaceDeleteTask と同構造。sha1ポインタ + JSON管理 |

### 生成フロー

| ファイル | 役割 |
|---------|------|
| `store/generate_start.php` | ジョブ作成 + ワーカー起動（即返却） |
| `store/generate_progress.php` | 進捗取得 |
| `tools/generate_worker.php` | save_client + generate_entry + generate_internal を順次実行 |
| `lib/GenerateTask.php` | 同上 |

---

## analyze_start.php の仕様

### リクエスト

```json
POST store/analyze_start.php
{
  "url": "https://example.com/",
  "csrf": "..."
}
```

### 処理

1. CSRF 検証
2. URL バリデーション
3. `AnalyzeTask::createIfNotRunning()` でタスク作成（実行中なら task_id を返して終了）
4. `nohup setsid php tools/analyze_worker.php {cmsRoot} {task_id} > /dev/null 2>&1 &`
5. 即返却

### レスポンス

```json
{
  "ok": true,
  "task_id": "ana_xxxx",
  "already_running": false,
  "progress_text": "000/000"
}
```

---

## analyze_progress.php の仕様

### リクエスト

```
GET store/analyze_progress.php?task_id=ana_xxxx
```

`task_id` 省略時は `AnalyzeTask::latestTaskIdForActor(email)` を使用。

### stale 判定（ws削除と同じロジック）

- `status === 'running'` かつ `posix_kill(pid, 0)` が false → stale
- `status === 'running'` かつ `started_at` から 1800秒超過 → stale（解析は最大30分想定）

### レスポンス

```json
{
  "ok": true,
  "status": "running",
  "phase": "analyze_internal",
  "progress_text": "012/020",
  "done": false
}
```

---

## analyze_worker.php の仕様

```
php tools/analyze_worker.php {cmsRoot} {task_id}
```

### 処理フロー

```
1. タスクロード + status='running', pid, started_at を保存
2. fetch_lp（HTML/CSS/画像取得）
   → phase='fetch', progress_text='000/000'
3. analyze_entry（エントリページ解析）
   → phase='analyze_entry'
   → internal_count を取得
4. analyze_internal × N
   → phase='analyze_internal'
   → 1件完了ごとに progress_text='001/020' 更新
   → 各ループで lp_job_check_abort（停止シグナル確認）
5. finalize_analyze
   → phase='finalize'
6. status='done', ended_at を保存
```

### クラッシュ時

```php
} catch (Throwable $e) {
    $task['status'] = 'error';
    $task['error']  = $e->getMessage();
    $task['ended_at'] = time();
    AnalyzeTask::save(...);
}
```

---

## フロントエンド変更（assets/js/index.js）

### 現状の削除対象

- `runFetchAndAnalyze()` 内の直接 fetch 呼び出し
- NDJSON ストリームパーサー（`parseAnalyzeNdjsonStream`）
- `apiPostAnalyzeStream()`

### 新しいフロー

```js
async function runFetchAndAnalyze() {
  // 1. analyze_start.php を呼んで即 task_id を受け取る
  const { task_id } = await fetch('store/analyze_start.php', {...});

  // 2. モーダルを開く（既存の analyzeProgressModal）
  analyzeModal.show();

  // 3. ポーリング開始
  startAnalyzePolling(task_id);
}

function startAnalyzePolling(taskId) {
  analyzeTimer = setInterval(async () => {
    const data = await fetch(`store/analyze_progress.php?task_id=${taskId}`).then(r => r.json());
    updateAnalyzeProgressUI(data);

    if (data.done) {
      clearInterval(analyzeTimer);
      if (data.status === 'done') {
        await sleep(800);
        window.location.href = '?step=2';
      }
    }
  }, 2500);
}

// ページロード時：未完了ジョブがあればポーリング再開
function resumeAnalyzePollingIfNeeded() {
  fetch('store/analyze_progress.php')   // task_id なし = 最新タスク
    .then(r => r.json())
    .then(data => {
      if (data.ok && !data.done) {
        analyzeModal.show();
        startAnalyzePolling(data.task_id);
      }
    });
}
```

---

## 変更しない既存ファイル

| ファイル | 理由 |
|---------|------|
| `store/analyze_entry.php` | ワーカーから CLI 的に `require` して使う |
| `store/analyze_internal_page.php` | 同上 |
| `store/finalize_analyze.php` | 同上 |
| `lib/JobRegistry.php` | 既存ジョブ停止シグナルをそのまま流用 |

---

## 実装順序

1. `lib/AnalyzeTask.php` — WorkspaceDeleteTask をベースに作成
2. `store/analyze_start.php` + `store/analyze_progress.php`
3. `tools/analyze_worker.php`
4. `assets/js/index.js` のフロント変更
5. 動作確認後に生成フロー（`lib/GenerateTask.php` 〜）を同様に実装

---

## 確認手順

1. VM で `git pull`
2. `otakaraya.jp` で解析開始 → モーダルが開き `001/020` と進捗表示
3. 解析中にブラウザを閉じる → 再オープンでポーリングが再開される
4. 解析中に Apache を再起動 → ワーカーが継続し最終的に完了する
5. ワーカーを `kill -9` → 30分後に `stale` 表示
