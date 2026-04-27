## Cursor実装依頼：テキスト自動生成（S3）

### 目的

参照LPのテキスト群を Claude API で業種置換する。
「美容室のLP」→「マッサージ店のLP」のように、構造・レイアウトを保ちながら
テキストだけを別業種向けに自動生成する。

---

### 新規ファイル

`current/lp_reverse_cms/store/text_replace.php`

---

### リクエスト仕様（POST JSON）

```json
{
  "source_industry": "トリミングサロン",
  "target_industry": "ネイルサロン",
  "tone": "polite",
  "texts": [
    { "id": "h1_main",   "role": "heading",  "content": "ペットの毛並みを美しく" },
    { "id": "p_hero",    "role": "body",     "content": "施術中の電話予約で<strong>作業が中断する</strong>お悩みはありませんか？" },
    { "id": "btn_cta",   "role": "button",   "content": "無料プラン申込" },
    { "id": "badge_01",  "role": "badge",    "content": "No.1" }
  ]
}
```

| フィールド | 型 | 説明 |
|---|---|---|
| `source_industry` | string | 元LPの業種 |
| `target_industry` | string | 生成したい業種 |
| `tone` | string | `polite`（丁寧）/ `casual`（カジュアル）/ `professional`（ビジネス） |
| `texts[].id` | string | 呼び出し元が管理する識別子（変更不可） |
| `texts[].role` | string | `heading` / `body` / `button` / `badge` / `label` |
| `texts[].content` | string | 元テキスト（HTML タグ含む場合あり） |

---

### レスポンス仕様

```json
{
  "texts": [
    { "id": "h1_main",  "content": "あなたの指先を美しく整える" },
    { "id": "p_hero",   "content": "施術中の電話対応で<strong>集中が途切れる</strong>お悩みはありませんか？" },
    { "id": "btn_cta",  "content": "無料体験を予約する" },
    { "id": "badge_01", "content": "No.1" }
  ],
  "source_industry": "トリミングサロン",
  "target_industry": "ネイルサロン"
}
```

エラー時:
```json
{ "error": "Claude API error: ...", "status": 500 }
```

---

### 実装

#### Claude API へ送るプロンプト

```php
$systemPrompt = <<<PROMPT
あなたはLPのコピーライターです。
与えられたテキスト一覧を、指定された業種向けに書き直してください。

ルール:
- 元の文体・トーン（{$tone}）を保つ
- 文字数は元テキストの ±30% 以内に収める
- HTML タグ（<strong>, <br> など）はそのまま残し、タグ内のテキストだけ置換する
- role が "button" のテキストは 10文字以内の簡潔なアクション表現にする
- role が "badge" のテキストは数字・記号のみの場合は変更しない（"No.1" → "No.1"）
- role が "heading" は業種のキーワードを必ず含める
- 返答は JSON 配列のみ。説明文は不要

出力フォーマット:
[
  { "id": "xxx", "content": "置換後テキスト" },
  ...
]
PROMPT;

$userPrompt = "元業種: {$sourceIndustry}\n"
            . "目標業種: {$targetIndustry}\n\n"
            . "テキスト一覧:\n"
            . json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
```

#### Claude API 呼び出し（claude_image_analyze.php と同じパターン）

```php
$payload = [
    'model'      => 'claude-3-5-haiku-20241022',  // 高速・低コスト
    'max_tokens' => 4096,
    'system'     => $systemPrompt,
    'messages'   => [
        ['role' => 'user', 'content' => $userPrompt]
    ]
];

// API キーは claude_image_analyze.php と同じ定数 or 環境変数を使う
```

#### レスポンスのパース

Claude は JSON 配列を返す。`json_decode` して `id` をキーにマッピングし、
元の texts 配列に上書きして返す。

Claude が Markdown コードブロック（\`\`\`json ... \`\`\`）で返した場合は
正規表現で JSON 部分を抽出すること。

```php
// コードブロック除去
$raw = trim($content);
if (preg_match('/```(?:json)?\s*([\s\S]+?)```/i', $raw, $m)) {
    $raw = trim($m[1]);
}
$replaced = json_decode($raw, true);
```

---

### テキスト数が多い場合の分割

`texts` の合計文字数が 3000文字を超える場合、50件ずつ分割して複数回 API を呼び出し、
結果をマージして返す。

---

### 実装後の確認（Cursor が自分で実行する）

```bash
curl -s -X POST https://lp-next.jitan.app/current/lp_reverse_cms/store/text_replace.php \
  -H 'Content-Type: application/json' \
  -d '{
    "source_industry": "トリミングサロン",
    "target_industry": "ネイルサロン",
    "tone": "polite",
    "texts": [
      { "id": "h1",    "role": "heading", "content": "ペットのトリミングで困っていませんか？" },
      { "id": "body1", "role": "body",    "content": "施術中の電話予約で<strong>作業が中断する</strong>お悩みを解決します。" },
      { "id": "btn1",  "role": "button",  "content": "無料プラン申込" },
      { "id": "badge", "role": "badge",   "content": "No.1" }
    ]
  }'
```

成功条件:
- `texts` 配列が返る
- 各 `content` が「ネイルサロン」文脈の日本語になっている
- `btn1` は 10文字以内
- `badge` は "No.1" のまま
- HTML タグが保持されている（`<strong>` など）

---

### 制約
- PHP 8.x strict_types
- Node.js・Composer 使用不可
- API キーは claude_image_analyze.php と同じ方法で取得
- コミット後 git push
