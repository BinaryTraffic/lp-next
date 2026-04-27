# S3: 業種テキスト置換（text_replace.php）

## エンドポイント

`POST lp_reverse_cms/store/text_replace.php`  
`Content-Type: application/json`

## 認証

`claude_image_analyze.php` と同一:

- サーバの `ANTHROPIC_API_KEY`（`.env`）を優先
- `ANTHROPIC_DENY_CLIENT_KEY=1` のとき body の `api_key` は使わない
- 上記以外で鍵が無いときのみ JSON の `api_key` を使用可

## リクエスト

| フィールド | 必須 | 説明 |
|-----------|------|------|
| `industry` | ○ | 差し替え先の業種（日本語可） |
| `elements` | ○ | `{ id, original_text, type?, label? }` の配列（1〜60件、`original_text` 最大2000文字） |
| `tone` | | トーンのヒント |
| `source_context` | | 元LPの文脈メモ |
| `api_key` | | サーバ鍵が無いときの Claude API キー |

## レスポンス（200）

```json
{
  "industry": "…",
  "items": [
    { "id": "…", "original_text": "…", "replaced_text": "…" }
  ]
}
```

`items` は入力 `elements` と同じ id 集合・同じ件数。

## curl 成功条件の例

- HTTP **200**
- JSON に `industry` と `items` があり、各 `items[].id` / `replaced_text` が非空
- 各入力 id に対応する `replaced_text` が存在（欠落時は 502）

## 利用記録

`data/api_usage_events.jsonl` に `operation: text_replace` で記録。
