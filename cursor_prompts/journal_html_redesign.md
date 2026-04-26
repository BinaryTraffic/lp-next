## Cursor実装依頼：journal.html クライアント向けリデザイン

### プロジェクト
lp-next（PHP 8.x / GCP Linux VM）
対象ファイル: `current/journal.html`

---

### 改修方針

**変更前の問題点**
- `JOURNAL.md` をそのまま `marked.js` でレンダリング → 技術仕様・ファイル名・バグ注記がクライアントに丸見え
- `movePhaseBlock()` が探す「フェーズ進捗」見出しは JOURNAL.md に存在せず機能していない

**変更後の方針**
- JOURNAL.md 読み込みを廃止。進捗データは HTML 内の JS オブジェクトとして保持
- クライアントに見せる情報は「フェーズ概要」と「S1 直近チェック」だけ
- 打ち合わせメモ欄はそのまま維持（localStorage）
- marked.js 依存を削除

---

### 画面構成（上から順）

```
[ヘッダー] プロジェクト名 / 最終更新日 / ナビリンク
[フェーズ概要カード] 全フェーズの一覧テーブル
[S1 直近進捗カード] S1 内チェックリスト（直近のみ）
```

---

### タスク1：ヘッダー

```html
<header>
  <h1>LP-NEXT 開発状況</h1>
  <p class="meta">最終更新：2026-04-27</p>
  <nav class="nav">
    <a href="index.html">LP-NEXT 入口</a>
    <a href="lp_reverse_cms/">管理画面</a>
  </nav>
</header>
```

- `href="JOURNAL.md"` と `href="prompt_context_demo.html"` のリンクは削除する
- 最終更新日は文字列として直接書く（JSによる動的日時は削除）

---

### タスク2：フェーズ概要カード

クラス名 `phase-hero` を流用。内容は JS で描画する。

#### データ定義（`<script>` 内）

```js
const PHASES = [
  {
    label: 'Ph.0',
    name: 'LP複製エンジン',
    desc: 'URL入力 → HTML取得・アセット保存・手動編集・スタンドアロン出力',
    status: 'done',   // done | active | pending
  },
  {
    label: 'S1',
    name: 'AI画像分析・自動置換エンジン',
    desc: 'Claude Vision で画像タイプ判定 → HF FLUX で代替画像生成 → GD でテキスト合成',
    status: 'active',
  },
  {
    label: 'S2',
    name: 'AI画像提案エンジン',
    desc: '業種文脈に合う代替候補をAIが提示',
    status: 'pending',
  },
  {
    label: 'S3',
    name: 'テキスト自動生成',
    desc: '業種・トーンを指定してキャッチコピー・本文を生成',
    status: 'pending',
  },
  {
    label: 'S4',
    name: '承認ログと学習ループ',
    desc: '人間の選択をAIに蓄積し次回提案精度を向上',
    status: 'pending',
  },
  {
    label: 'S5',
    name: '精度向上・業種テンプレート化',
    desc: '自動生成精度の向上と業種別テンプレート整備',
    status: 'pending',
  },
];
```

#### ステータス表示

| status | バッジ表示 | 行の背景 |
|---|---|---|
| `done` | ✅ 完了 | `#e6f4ea`（薄緑） |
| `active` | 🔄 開発中 | `#fff8e5`（薄黄） |
| `pending` | 🔲 予定 | 白（既定） |

#### テーブル描画（JS）

```js
function renderPhases() {
  const rows = PHASES.map(p => {
    const badge = p.status === 'done'    ? '✅ 完了'
                : p.status === 'active'  ? '🔄 開発中'
                :                         '🔲 予定';
    const cls   = p.status === 'done'    ? 'st-done'
                : p.status === 'active'  ? 'st-active'
                :                         '';
    return `<tr class="${cls}">
      <td><strong>${p.label}</strong></td>
      <td>${p.name}</td>
      <td>${p.desc}</td>
      <td>${badge}</td>
    </tr>`;
  }).join('');

  document.getElementById('phase-hero').innerHTML = `
    <h2>フェーズ概要</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>フェーズ</th><th>内容</th><th>概要</th><th>状態</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}
```

---

### タスク3：S1 直近進捗カード

クラス名 `s1-card` を新設。`phase-hero` の直下に配置。

#### データ定義（`<script>` 内）

```js
const S1_ITEMS = [
  { text: 'Claude Vision で画像タイプ自動判定',       done: true  },
  { text: 'HF FLUX による任意サイズ画像生成',          done: true  },
  { text: 'ボタン・UI画像のテキスト合成（余白保持）',   done: true  },
  { text: 'グラデーション・バッジ・フレーム付き画像対応', done: false },
  { text: '写真・イラスト系画像の自動生成',             done: false },
  { text: 'composite（背景＋テキスト混在）画像の再合成', done: false },
];
```

#### 描画（JS）

```js
function renderS1() {
  const items = S1_ITEMS.map(i =>
    `<li class="${i.done ? 'done' : 'todo'}">
      ${i.done ? '✅' : '🔲'} ${i.text}
    </li>`
  ).join('');

  document.getElementById('s1-card').innerHTML = `
    <h2>S1 直近進捗</h2>
    <ul class="s1-list">${items}</ul>`;
}
```

#### スタイル追加

```css
.s1-card {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.9rem 1rem 1rem;
  margin: 0 0 1.1rem;
}
.s1-card h2 { font-size: 1.02rem; margin: 0 0 0.5rem; font-weight: 600; }
.s1-list { list-style: none; padding: 0; margin: 0; }
.s1-list li { padding: 0.35rem 0; border-bottom: 1px solid #eee; font-size: 0.92rem; }
.s1-list li:last-child { border-bottom: none; }
.s1-list li.done { color: #0d3d1f; }
.s1-list li.todo { color: #555; }

/* phase-hero の active 行 */
#md .table-wrap tr.st-active td,
.phase-hero tr.st-active td {
  background: #fff8e5 !important;
  color: #5a4500;
}
```

---

### タスク4：HTML 骨格の変更

#### 削除する要素（すべて削除）
- `<p id="load-msg">` — JOURNAL.md 読み込みステータス
- `<p id="err-msg">` — 読み込みエラー表示
- `<div id="md">` — JOURNAL.md レンダリング先
- `<section id="collab-panel">` — 打ち合わせメモ欄（不要）
- 打ち合わせメモ関連の CSS（`.collab` 系すべて）

#### 追加する要素（`phase-hero` の直下）
```html
<div id="s1-card" class="s1-card"></div>
```

#### `<body>` 内の最終構成

```html
<header>...</header>
<div id="phase-hero" class="phase-hero"></div>
<div id="s1-card" class="s1-card"></div>
```

---

### タスク5：JS の整理

#### 削除する関数・処理
- `fetch(src)` ブロック全体（JOURNAL.md 読み込み）
- `movePhaseBlock()`
- `wrapTables()`
- `colorStatusCells()`
- `marked.js` の `<script src>` タグ
- 打ち合わせメモ関連の関数すべて（`loadMsgs` / `saveMsgs` / `renderLog` / `escapeHtml` / イベントリスナー4本）
- `localStorage` への読み書き処理すべて

#### 残す・追加する処理

```js
document.addEventListener('DOMContentLoaded', function () {
  renderPhases();      // フェーズ概要テーブルを描画
  renderS1();          // S1 チェックリストを描画
});
```

---

### 更新手順（実装後の運用）

S1 完了時:
1. `PHASES` 配列の `S1.status` を `'done'` に変更
2. `S2.status` を `'active'` に変更
3. `S1_ITEMS` の該当行を `done: true` に変更

---

### 制約
- PHP・Node.js・Composer 使用不可
- 外部ライブラリ（marked.js）は削除
- localStorage は使用しない
- コミット後 git push
