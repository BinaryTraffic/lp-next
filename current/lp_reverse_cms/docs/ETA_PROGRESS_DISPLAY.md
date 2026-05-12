# 残り時間表示 実装指示書

## 目的

解析・生成フェーズでページ数がわかった時点から残り時間の目安を表示する。
サイト規模ごとに実際の経過をもとにリアルタイム更新する。

---

## 表示タイミングと内容

### 解析フェーズ（analyze）

| タイミング | 表示例 |
|-----------|--------|
| analyze_entry 完了直後 | `[解析中] 内部ページ 20件 を検出。目安: 約 10〜20 分` |
| 1件目完了後 | `[解析中] 2 / 20  残り 約 9分 (1件あたり 28秒)` |
| 途中 | `[========>         ] 9 / 20  残り 約 5分` |
| 完了 | `[====================] 解析完了 (合計 9分32秒)` |

### 生成フェーズ（generate）

| タイミング | 表示例 |
|-----------|--------|
| ページ数判明時 | `[生成中] 21件 (エントリ + 内部20) / 目安: 約 3〜10 分` |
| 1件目完了後 | `[生成中] 2 / 21  残り 約 8分` |
| 完了 | `[生成完了] 合計 7分45秒` |

---

## 実装: `assets/js/index.js`

### 1. ETA 計算ユーティリティ（関数として追加）

```js
/**
 * 経過時間から残り時間の文字列を返す。
 * @param {number} startMs     フェーズ開始時刻 (Date.now())
 * @param {number} done        完了件数
 * @param {number} total       全件数
 * @returns {string}           "残り 約X分" or "残り 約X秒"
 */
function etaString(startMs, done, total) {
  if (done <= 0) return '';
  const elapsedSec = (Date.now() - startMs) / 1000;
  const avgSec = elapsedSec / done;
  const remainSec = Math.round(avgSec * (total - done));
  const perSec = Math.round(avgSec);
  const remainStr = remainSec >= 60
    ? `約 ${Math.ceil(remainSec / 60)} 分`
    : `約 ${remainSec} 秒`;
  return `残り ${remainStr}  (1件あたり ${perSec}秒)`;
}

/**
 * 秒数を "X分Y秒" 形式にフォーマット。
 */
function formatDuration(totalSec) {
  const m = Math.floor(totalSec / 60);
  const s = Math.round(totalSec % 60);
  return m > 0 ? `${m}分${s}秒` : `${s}秒`;
}

/**
 * 件数から初期目安文字列を返す（実測前の rough estimate）。
 * @param {number} count 件数
 * @param {'analyze'|'generate'} phase
 */
function roughEstimate(count, phase) {
  // analyze: ~20〜60秒/ページ, generate: ~5〜20秒/ページ
  const [lo, hi] = phase === 'analyze' ? [20, 60] : [5, 20];
  const loMin = Math.ceil(count * lo / 60);
  const hiMin = Math.ceil(count * hi / 60);
  if (loMin === hiMin) return `約 ${loMin} 分`;
  return `約 ${loMin}〜${hiMin} 分`;
}
```

---

### 2. 解析フロー（`runAnalyze()` または相当する関数）

analyze_entry 完了後、内部ページループ前に追加：

```js
// analyze_entry の戻り値から件数を取得
const internalCount = entryResult.internal_count ?? 0;

// 初期目安を表示
setAnalyzeProgress(0, internalCount,
  `内部ページ ${internalCount} 件を検出。目安: ${roughEstimate(internalCount, 'analyze')}`
);

const analyzeStartMs = Date.now();

// 内部ページループ
for (let i = 0; i < internalCount; i++) {
  // ... analyze_internal_page を呼ぶ

  const label = i + 1 < internalCount
    ? etaString(analyzeStartMs, i + 1, internalCount)
    : `完了 (合計 ${formatDuration((Date.now() - analyzeStartMs) / 1000)})`;
  setAnalyzeProgress(i + 1, internalCount, label);
}
```

---

### 3. 生成フロー（`runSaveAndGenerate()` の内部ページループ）

既存の `setSaveGenProgress()` 呼び出しに ETA を追加する。

ループの**外**（内部ページ数判明直後）に開始時刻を記録：

```js
const totalPages = internalKeys.length + 1; // +1 for entry
setSaveGenProgress(0, totalPages,
  `${totalPages} 件を生成します。目安: ${roughEstimate(totalPages, 'generate')}`
);

const genStartMs = Date.now();
let genDone = 0;
```

generate_entry 完了後：

```js
genDone = 1;
const labelEntry = etaString(genStartMs, genDone, totalPages);
setSaveGenProgress(genDone, totalPages, `エントリページ完了。${labelEntry}`);
```

generate_internal ループ内、1件完了ごと：

```js
genDone++;
const labelInternal = genDone < totalPages
  ? etaString(genStartMs, genDone, totalPages)
  : `完了 (合計 ${formatDuration((Date.now() - genStartMs) / 1000)})`;
setSaveGenProgress(genDone, totalPages, labelInternal);
```

---

## 表示形式の統一

既存の `setSaveGenProgress(completed, total, label)` の `label` 部分に
ETA 文字列をそのまま渡す。プログレスバーのラベル行に自然に収まる。

```
[=============>      ] 13 / 20  残り 約 4分  (1件あたり 27秒)
```

---

## 注意事項

- 1件目が完了するまでは `roughEstimate()` の固定値を表示、2件目以降は実測 ETA に切り替える
- AbortError でループが中断された場合は ETA 表示をクリアする
- finalize フェーズ（画像メモ・業種推定）は時間が読みにくいため「最終処理中…」のみで可
- 解析フェーズが存在しない場合（analyze_entry.php が 404 → フォールバック）は ETA 非表示

---

## 変更対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `assets/js/index.js` | `etaString` / `roughEstimate` / `formatDuration` 追加、解析・生成ループに ETA 表示挿入 |
