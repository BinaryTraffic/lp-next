# 生成中断機能 — 実装指示書

## 設計方針

JS の `AbortController` でブラウザ側のリクエストを即時キャンセルし、
フラグファイル方式でサーバー側スクリプトにも停止シグナルを渡す。

```
ユーザー「停止」ボタン押下
  ↓
① JS: controller.abort() → 現在の fetch を即時キャンセル
② JS: aborted = true → 次の generate_internal ループを止める
③ JS: abort_generate.php を呼ぶ → data/abort.flag 生成
  ↓（次にリクエストが来た場合のフェイルセーフ）
④ generate_internal.php / generate_entry.php 起動時に abort.flag を検出 → 即時 abort レスポンス返却・フラグ削除
```

---

## 新規ファイル: `store/abort_generate.php`

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$dataDir  = LpWorkspace::dataDir(dirname(__DIR__));
$flagPath = $dataDir . 'abort.flag';

file_put_contents($flagPath, date('c'), LOCK_EX);

echo json_encode(['ok' => true, 'message' => 'abort signal sent'], JSON_UNESCAPED_UNICODE);
```

---

## `generate_entry.php` の修正

try ブロック冒頭（`$cmsRoot = dirname(__DIR__);` の直後）に追加：

```php
// abort.flag が存在したら即時停止
$abortFlag = $dataDir . 'abort.flag';
// ※ $dataDir はこの時点ではまだ未定義なので、下の $dataDir 確定後に移動する
```

実際には `$dataDir = LpWorkspace::dataDir($cmsRoot);` の直後に挿入：

```php
$abortFlag = $dataDir . 'abort.flag';
if (file_exists($abortFlag)) {
    @unlink($abortFlag);
    echo json_encode(['ok' => false, 'aborted' => true, 'error' => '生成が中断されました。'], JSON_UNESCAPED_UNICODE);
    exit;
}
```

---

## `generate_internal.php` の修正

同様に `$dataDir = LpWorkspace::dataDir($cmsRoot);` の直後に挿入：

```php
$abortFlag = $dataDir . 'abort.flag';
if (file_exists($abortFlag)) {
    @unlink($abortFlag);
    http_response_code(409);
    echo json_encode(['ok' => false, 'aborted' => true, 'error' => '生成が中断されました。'], JSON_UNESCAPED_UNICODE);
    exit;
}
```

---

## `assets/js/index.js` の修正

### 変更点1: AbortController の導入

`runSaveAndGenerate()` 関数冒頭付近に以下を追加：

```js
/** @type {AbortController|null} */
let generateAbortController = null;
```

関数の外側（モジュールスコープ）に宣言し、関数内で初期化：

```js
async function runSaveAndGenerate() {
  generateAbortController = new AbortController();
  const signal = generateAbortController.signal;
  // ... 既存コード
```

### 変更点2: fetch に signal を渡す

`apiPost` は既存の汎用関数のため、generate 専用の内部 fetch に signal を渡す。
`generate_entry.php` と `generate_internal.php` の `apiPost` を直接 fetch に変更：

```js
// generate_entry
const entryRes = await fetch('store/generate_entry.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({}),
  signal,
}).then(r => r.json());

// generate_internal ループ内
const one = await fetch('store/generate_internal.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ key }),
  signal,
}).then(r => r.json());
```

`signal` が abort されると fetch は `AbortError` をスローするので、catch でハンドリングが必要。

### 変更点3: 停止ボタンの追加

`ensureSaveGenProgressUi()` が作るプログレス UI に「停止」ボタンを追加する。
`saveGenProgressWrap` の HTML 生成部分に追加：

```js
wrap.innerHTML = `
  <div class="progress mb-1" style="height:8px">
    <div id="saveGenProgressBar" class="progress-bar" style="width:0%"></div>
  </div>
  <div id="saveGenProgressLabel" class="small text-muted mb-2"></div>
  <button id="saveGenAbortBtn" class="btn btn-sm btn-outline-danger">
    ■ 生成を停止
  </button>
`;

document.getElementById('saveGenAbortBtn')?.addEventListener('click', async () => {
  // ① 現在の fetch をキャンセル
  generateAbortController?.abort();
  // ② サーバーにフラグ送信
  try {
    await fetch('store/abort_generate.php', { method: 'POST' });
  } catch {}
  // ③ UI 更新
  setSaveGenProgress(0, 1, '停止しました。');
  document.getElementById('saveGenAbortBtn').disabled = true;
});
```

### 変更点4: AbortError のハンドリング

generate_internal のループに catch を追加（既存の catch を修正）：

```js
try {
  const one = await fetch('store/generate_internal.php', { ... signal }).then(r => r.json());
  // ...
} catch (e) {
  if (e instanceof DOMException && e.name === 'AbortError') {
    break; // ループ全体を終了
  }
  // 既存のスキップ処理
  console.warn('generate_internal skipped', key, e);
  completed++;
}
```

### 変更点5: 終了後に AbortController をリセット

```js
} finally {
  generateAbortController = null;
}
```

---

## 変更・作成対象ファイル

| ファイル | 種別 | 内容 |
|---------|------|------|
| `store/abort_generate.php` | 新規 | abort.flag を作成するエンドポイント |
| `store/generate_entry.php` | 修正 | abort.flag チェックを追加 |
| `store/generate_internal.php` | 修正 | abort.flag チェックを追加 |
| `assets/js/index.js` | 修正 | AbortController + 停止ボタン追加 |

---

## 動作フロー

```
[停止ボタン押下]
  ↓
generateAbortController.abort()
  → 現在の generate_internal fetch が AbortError
  → ループが break
  ↓
fetch('store/abort_generate.php') → data/abort.flag 作成
  ↓（万一次のリクエストが行った場合）
generate_internal.php 起動 → abort.flag 検出 → 409 返却 → ループの catch で break
  ↓
モーダルに「停止しました。」表示
```

---

## 注意事項

- `abort.flag` は `data/ws_xxx/abort.flag` ではなく `data/abort.flag`（ワークスペース共通）でもよいが、
  マルチワークスペース運用を考慮するなら `LpWorkspace::dataDir()` 配下（`data/ws_xxx/abort.flag`）にする
- 現在実行中の PHP スクリプトは `ignore_user_abort` 設定次第で継続することがある
  （Apache との接続が切れても PHP が動き続ける場合）。`abort.flag` による次回起動時チェックはそのフェイルセーフ
- `generate_lp.php`（フォールバック）には今回追加不要
