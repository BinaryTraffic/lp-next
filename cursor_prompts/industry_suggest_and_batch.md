## Cursor実装依頼：業種サジェスト表示 ＋ 60件上限バグ修正

### 修正 1：60件上限でブロックされるバグを修正

`current/lp_reverse_cms/assets/js/index.js` の `initAiTextReplace` 内。

```javascript
// 修正前（return で止まって何も置換しない）
if (elements.length > AI_TEXT_REPLACE_MAX) {
    setAiStatus(`一度に置換できるのは ${AI_TEXT_REPLACE_MAX} 件までです`, 'danger');
    showToast(`最大 ${AI_TEXT_REPLACE_MAX} 件までです`, 'danger');
    backup = null;
    return;
}

// 修正後（先頭60件に絞って続行）
let toProcess = elements;
if (elements.length > AI_TEXT_REPLACE_MAX) {
    toProcess = elements.slice(0, AI_TEXT_REPLACE_MAX);
    setAiStatus(
        `${elements.length}件中 先頭 ${AI_TEXT_REPLACE_MAX} 件を処理します…`,
        'warning'
    );
}
// 以降の API 呼び出しでは elements の代わりに toProcess を使う
```

`backup` への記録も `toProcess` のみに絞ること（元に戻す対象を一致させる）。

---

### 修正 2：業種サジェスト機能を追加

#### 2-A. 新規エンドポイント `store/suggest_industries.php`

lp_structure.json のページタイトル・メタ説明を読み、Claude API で
「元業種ラベル」と「関連業種5件」を返す。

**リクエスト**: GET（パラメータなし）  
**レスポンス**:
```json
{
  "source_industry": "ペットサロン・トリミング",
  "suggestions": ["美容室", "エステサロン", "ネイルサロン", "マッサージ", "接骨院"]
}
```

**実装**:
```php
<?php
declare(strict_types=1);

// lp_structure.json を読む
$structPath = __DIR__ . '/../../data/lp_structure.json';
$struct = file_exists($structPath) ? json_decode(file_get_contents($structPath), true) : [];

$title = $struct['page_title'] ?? $struct['title'] ?? '';
$desc  = $struct['meta_description'] ?? $struct['description'] ?? '';

if (!$title && !$desc) {
    echo json_encode(['source_industry' => '', 'suggestions' => []]);
    exit;
}

// Claude API で業種判定
$prompt = "以下はLPのページ情報です。\n"
        . "タイトル: {$title}\n"
        . "説明: {$desc}\n\n"
        . "このLPの業種を10文字以内で答えてください。\n"
        . "次に、同じターゲット（個人・店舗・サービス業）で構造が流用できる別業種を5つ挙げてください。\n"
        . "JSON のみで返してください:\n"
        . '{"source_industry":"...","suggestions":["...","...","...","...","..."]}';

// API 呼び出し（claude_image_analyze.php と同じ認証方式）
// model: claude-haiku-4-5-20251001（軽量・低コスト）
// max_tokens: 256
```

`claude_image_analyze.php` と同じ API キー取得・呼び出しパターンを使うこと。  
レスポンスの JSON が取得できない場合はフォールバック:
```json
{ "source_industry": "", "suggestions": [] }
```

---

#### 2-B. editPage.php の AI パネルを更新

PHP 側で `suggest_industries.php` を **内部 include** または **file_get_contents** で呼び、
結果を PHP 変数に持つ（外部 HTTP 呼び出しは不要）。

```php
// editPage.php の先頭付近で suggest_industries のロジックを呼び出す
// または suggest_industries.php の処理を関数化して require する
$suggestResult = getSuggestIndustries(); // 関数名は実装に合わせる
$sourceIndustry  = $suggestResult['source_industry'] ?? '';
$suggestions     = $suggestResult['suggestions'] ?? [];
```

---

#### 2-C. AI パネルの HTML を更新

既存の `#ai-text-replace-panel` 内を以下に差し替える:

```html
<div id="ai-text-replace-panel" class="card shadow-sm mb-3 border-primary">
  <div class="card-header bg-primary text-white d-flex align-items-center gap-2">
    <span>✨ AI テキスト自動生成</span>
    <small class="ms-auto opacity-75">業種を入力して全テキストを一括置換（保存は従来どおり）</small>
  </div>
  <div class="card-body">

    <!-- 元業種の表示 -->
    <?php if ($sourceIndustry): ?>
    <p class="small text-muted mb-2">
      元LP業種: <strong class="text-body"><?= htmlspecialchars($sourceIndustry) ?></strong>
    </p>
    <?php endif; ?>

    <!-- 関連業種チップ -->
    <?php if ($suggestions): ?>
    <div class="mb-2 d-flex flex-wrap gap-1" id="ai-suggest-chips">
      <?php foreach ($suggestions as $s): ?>
      <button type="button"
              class="btn btn-sm btn-outline-primary ai-chip"
              data-value="<?= htmlspecialchars($s) ?>">
        <?= htmlspecialchars($s) ?>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 業種入力・トーン・実行 -->
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label small mb-1">
          ターゲット業種 <span class="text-danger">*</span>
        </label>
        <input type="text" id="ai-industry" class="form-control"
               placeholder="例：ネイルサロン、歯科クリニック、学習塾"
               list="ai-industry-list">
        <datalist id="ai-industry-list">
          <?php foreach ($suggestions as $s): ?>
          <option value="<?= htmlspecialchars($s) ?>">
          <?php endforeach; ?>
        </datalist>
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
        <button id="ai-replace-btn" type="button" class="btn btn-primary w-100">
          AI 置換
        </button>
      </div>
      <div class="col-md-2">
        <button id="ai-replace-undo" type="button"
                class="btn btn-outline-secondary w-100" hidden>
          元に戻す
        </button>
      </div>
    </div>

    <div id="ai-replace-status" class="mt-2 small text-muted" hidden></div>
  </div>
</div>
```

---

#### 2-D. チップクリックで入力欄にセット（JS）

`assets/js/index.js` の `initAiTextReplace` 内に追加:

```javascript
// サジェストチップのクリックでインプットにセット
document.querySelectorAll('.ai-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        const inp = document.getElementById('ai-industry');
        if (inp) {
            inp.value = chip.dataset.value;
            inp.focus();
        }
    });
});
```

---

### 実装後の確認（Cursor が自分で実行する）

```bash
# suggest_industries.php の動作確認
curl -s https://lp-next.jitan.app/current/lp_reverse_cms/store/suggest_industries.php
```

成功条件:
- `source_industry` に「ペット」「トリミング」等の語が含まれる
- `suggestions` に5件の業種が含まれる

UI確認は shimizu さんがブラウザで行う。

---

### 制約
- PHP 8.x + GD
- Node.js・Composer 使用不可
- コミット後 git push
