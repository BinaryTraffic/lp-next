## Cursor実装依頼：テキスト自動置換 UI 統合

### 目的

`store/text_replace.php` を LP 編集画面（Step 2）から呼び出せるようにする。
ユーザーが「業種」を入力して「AI 置換」ボタンを押すと、LP の全テキストが
自動で業種向けに書き換わる。その後、通常の保存フロー（save_client.php）で確定。

---

### 変更ファイル

1. `current/lp_reverse_cms/template/editPage.php` — AI 置換パネルを追加
2. `current/lp_reverse_cms/assets/js/index.js` — 置換ロジックを追加

---

### Step 1: editPage.php に AI 置換パネルを追加

編集フォームの**先頭**（最初のセクション一覧の上）に以下のパネルを挿入する。

```html
<!-- AI テキスト自動置換パネル -->
<div id="ai-text-replace-panel" class="card mb-4 border-primary">
  <div class="card-header bg-primary text-white d-flex align-items-center gap-2">
    <span>✨ AI テキスト自動生成</span>
    <small class="ms-auto opacity-75">業種を入力して全テキストを一括置換</small>
  </div>
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label small mb-1">ターゲット業種 <span class="text-danger">*</span></label>
        <input type="text" id="ai-industry" class="form-control"
               placeholder="例：ネイルサロン、歯科クリニック、学習塾">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">トーン</label>
        <select id="ai-tone" class="form-select">
          <option value="polite">丁寧・上品</option>
          <option value="casual">カジュアル</option>
          <option value="professional">ビジネス</option>
        </select>
      </div>
      <div class="col-md-2">
        <button id="ai-replace-btn" class="btn btn-primary w-100">
          AI 置換
        </button>
      </div>
      <div class="col-md-2">
        <button id="ai-replace-undo" class="btn btn-outline-secondary w-100" style="display:none">
          元に戻す
        </button>
      </div>
    </div>
    <div id="ai-replace-status" class="mt-2 small text-muted"></div>
  </div>
</div>
```

---

### Step 2: index.js に置換ロジックを追加

`assets/js/index.js` の末尾（または DOMContentLoaded ブロック内）に追加する。

```javascript
// ===== AI テキスト自動置換 =====
(function () {
  const btn     = document.getElementById('ai-replace-btn');
  const undoBtn = document.getElementById('ai-replace-undo');
  const status  = document.getElementById('ai-replace-status');
  if (!btn) return;

  // 置換前の値をバックアップ（元に戻す用）
  let backup = null;

  btn.addEventListener('click', async function () {
    const industry = document.getElementById('ai-industry').value.trim();
    if (!industry) {
      status.textContent = '業種を入力してください';
      status.className = 'mt-2 small text-danger';
      return;
    }
    const tone = document.getElementById('ai-tone').value;

    // --- テキスト要素を収集 ---
    // data-lp-id かつ data-lp-field="text" の input/textarea を対象にする
    const inputs = document.querySelectorAll(
      '[data-lp-id][data-lp-field="text"], [data-lp-id][data-lp-field="content"]'
    );
    if (inputs.length === 0) {
      status.textContent = 'テキスト要素が見つかりません';
      status.className = 'mt-2 small text-warning';
      return;
    }

    // バックアップ
    backup = {};
    const elements = [];
    inputs.forEach(function (el) {
      const id = el.dataset.lpId;
      const type = el.dataset.lpType || 'paragraph';
      const label = el.dataset.lpLabel || '';
      const text = el.value || el.textContent || '';
      if (text.trim()) {
        backup[id] = text;
        elements.push({ id, type, label, original_text: text });
      }
    });

    if (elements.length === 0) {
      status.textContent = 'テキストが空のため置換できません';
      status.className = 'mt-2 small text-warning';
      return;
    }

    // --- API 呼び出し ---
    btn.disabled = true;
    status.textContent = `${elements.length} 件のテキストを AI で生成中...`;
    status.className = 'mt-2 small text-muted';

    try {
      const res = await fetch('/current/lp_reverse_cms/store/text_replace.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ industry, tone, elements })
      });
      const data = await res.json();

      if (!res.ok || data.error) {
        throw new Error(data.error || `HTTP ${res.status}`);
      }

      // --- 結果を各 input に反映 ---
      let replaced = 0;
      (data.items || []).forEach(function (item) {
        const el = document.querySelector(
          `[data-lp-id="${item.id}"][data-lp-field="text"],
           [data-lp-id="${item.id}"][data-lp-field="content"]`
        );
        if (el && item.replaced_text) {
          el.value = item.replaced_text;
          // change イベントを発火して既存の live-preview 等と連動させる
          el.dispatchEvent(new Event('change', { bubbles: true }));
          replaced++;
        }
      });

      status.textContent = `✅ ${replaced} 件を「${industry}」向けに置換しました。確認後、保存してください。`;
      status.className = 'mt-2 small text-success';
      undoBtn.style.display = '';

    } catch (e) {
      status.textContent = `❌ エラー: ${e.message}`;
      status.className = 'mt-2 small text-danger';
    } finally {
      btn.disabled = false;
    }
  });

  // 元に戻す
  undoBtn && undoBtn.addEventListener('click', function () {
    if (!backup) return;
    Object.keys(backup).forEach(function (id) {
      const el = document.querySelector(
        `[data-lp-id="${id}"][data-lp-field="text"],
         [data-lp-id="${id}"][data-lp-field="content"]`
      );
      if (el) {
        el.value = backup[id];
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    status.textContent = '元のテキストに戻しました';
    status.className = 'mt-2 small text-muted';
    undoBtn.style.display = 'none';
    backup = null;
  });
})();
```

---

### data 属性の確認・追加

`editPage.php` の各テキスト input/textarea に以下の data 属性が付いていること：

```html
<!-- 例：見出しフィールド -->
<input type="text"
  data-lp-id="elem_sec_0_14"
  data-lp-field="text"
  data-lp-type="heading"
  data-lp-label="ヒーロー見出し"
  value="<?= htmlspecialchars($elem['text'] ?? '') ?>">
```

- `data-lp-id` — すでにある場合は流用
- `data-lp-field="text"` — テキストフィールドを識別するキー。なければ追加
- `data-lp-type` — `heading` / `paragraph` / `button`（text_replace.php の type に使う）
- `data-lp-label` — UI ラベル文字列（任意）

既存の data 属性名が異なる場合は、JS 側のセレクタを実際の属性名に合わせること。

---

### 実装後の確認（Cursor が自分で実行する）

ブラウザ UI での確認はできないため、以下の curl で PHP 側のみ確認する：

```bash
curl -s -X POST https://lp-next.jitan.app/current/lp_reverse_cms/store/text_replace.php \
  -H 'Content-Type: application/json' \
  -d '{
    "industry": "ネイルサロン",
    "tone": "polite",
    "elements": [
      {"id":"h1", "type":"heading",   "label":"メイン見出し",  "original_text":"ペットサロンの予約管理を効率化"},
      {"id":"p1", "type":"paragraph", "label":"リード文",      "original_text":"施術中の電話予約で作業が中断するお悩みを解決します。"},
      {"id":"b1", "type":"button",    "label":"CTAボタン",    "original_text":"無料プラン申込"}
    ]
  }'
```

成功条件: HTTP 200、`items` に3件、各 `replaced_text` が「ネイルサロン」文脈の日本語。

UI は shimizu さんがブラウザで確認する。

---

### 制約
- PHP 8.x + JS（バニラ、外部ライブラリ追加不可）
- Node.js・Composer 使用不可
- コミット後 git push
