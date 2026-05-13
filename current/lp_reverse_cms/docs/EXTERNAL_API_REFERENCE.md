# 外部 API リファレンス — Site Reverse CMS

本システムが依存する外部 API・サービスの早見表。  
環境変数の設定先は **`.env.example`** を参照のこと。

---

## 1. Anthropic Messages API（Claude Vision）

| 項目 | 内容 |
|------|------|
| 用途 | 画像解析（type / 置換方針 / テキスト抽出） |
| 呼び出し元 | `store/claude_image_analyze.php`, `store/lp_ai_image_pipeline.php`, `lib/claude_vision_analyze.php` |
| エンドポイント | `https://api.anthropic.com/v1/messages` |
| 認証 | `x-api-key: $ANTHROPIC_API_KEY` + `anthropic-version: 2023-06-01` |
| モデル | `claude-sonnet-4-6`（ハードコード） |
| 入力 | base64 画像 + 解析プロンプト（`lib/claude_vision_analyze.php` 内） |
| 出力 | JSON: `type`, `replacement.mode`, `texts`, `background_description` 等 |
| レート制限 | Tier 依存。429 は自動リトライなし（呼び出し側で対処） |
| コスト目安 | Input $3/MTok, Output $15/MTok（`.env` で上書き可） |
| 公式ドキュメント | https://docs.anthropic.com/en/api/messages |

**主なレスポンスフィールド:**

```json
{
  "type": "photo|illustration|composite|ui|badge|gradient",
  "replacement": { "mode": "full|placeholder", "reason_ja": "..." },
  "background_description": "...",
  "texts": [{ "text": "...", "font_weight": "bold", "x_pct": 0.5, "y_pct": 0.3 }]
}
```

---

## 2. Hugging Face Inference API（画像生成）

| 項目 | 内容 |
|------|------|
| 用途 | テキストプロンプトから画像生成（背景・イラスト） |
| 呼び出し元 | `store/hf_image_proxy.php`, `lib/hf_image_client.php` |
| エンドポイント | `https://api-inference.huggingface.co/models/{MODEL_ID}` |
| 認証 | `Authorization: Bearer $HUGGINGFACE_API_TOKEN`（または `$HF_TOKEN`） |
| 既定モデル | `black-forest-labs/FLUX.1-schnell`（`.env` の `HF_IMAGE_MODEL` で変更可） |
| リクエスト | `POST` / `Content-Type: application/json` |
| レスポンス | 画像バイナリ（`image/jpeg` または `image/png`） |
| 503 対応 | モデル起動中の場合 12s 待機後に 1 回リトライ（`hf_image_client.php`） |
| コスト | 無料枠あり。有料プランは呼び出し単位課金 |
| 公式ドキュメント | https://huggingface.co/docs/api-inference/en/index |

**リクエスト例:**

```json
{
  "inputs": "photorealistic product background, white background",
  "parameters": { "width": 800, "height": 600 }
}
```

**トークン取得:** https://huggingface.co/settings/tokens

---

## 3. OpenAI Images API（DALL·E）

| 項目 | 内容 |
|------|------|
| 用途 | 画像生成（`lp_ai_image_review.html` 経由） |
| 呼び出し元 | `store/openai_image_proxy.php` |
| エンドポイント | `https://api.openai.com/v1/images/generations` |
| 認証 | `Authorization: Bearer $OPENAI_API_KEY` |
| モデル | `dall-e-3`（1024×1024 / 1024×1792） |
| コスト目安 | DALL·E 3: $0.04/枚（1024²）, $0.08/枚（ワイド） |
| 公式ドキュメント | https://platform.openai.com/docs/api-reference/images |

---

## 4. Google OAuth 2.0（認証）

| 項目 | 内容 |
|------|------|
| 用途 | 管理者ログイン（Google アカウント連携） |
| 呼び出し元 | `lib/GoogleAuth.php`, `store/auth_callback.php` |
| 認可エンドポイント | `https://accounts.google.com/o/oauth2/v2/auth` |
| トークンエンドポイント | `https://oauth2.googleapis.com/token` |
| ユーザー情報 | `https://www.googleapis.com/oauth2/v3/userinfo` |
| 必要スコープ | `openid email profile` |
| リダイレクト URI | `.env` の `GOOGLE_REDIRECT_URI`（Cloud Console の登録と完全一致） |
| 詳細 | `docs/AUTH_IMPLEMENTATION.md` |
| 公式ドキュメント | https://developers.google.com/identity/protocols/oauth2/web-server |

---

## 5. placehold.jp（プレースホルダー画像・外部）

| 項目 | 内容 |
|------|------|
| 用途 | フォールバック用プレースホルダー URL 生成（`buildPlaceholderUrl`） |
| 呼び出し元 | `assets/js/index.js`（`buildPlaceholderUrl` 関数） |
| 状態 | 現行フローでは未使用（Canvas 生成 / PHP GD が優先） |
| エンドポイント形式 | `https://placehold.jp/{W}x{H}.png` |
| テキスト表示 | `?text=FILENAME` でテキスト埋め込み可 |
| 色指定 | `?bgcolor=2d3134&color=e6e8eb`（16進、`#` 不要） |
| 例 | `https://placehold.jp/600x400.png?text=info_img.webp&bgcolor=2d3134&color=e6e8eb` |
| CORS | クロスオリジン Canvas 描画には対応していない場合あり → Canvas ローカル生成を優先 |
| 公式 | https://placehold.jp/ |

> **注意:** CORS 制限のため `blendPlaceholder`（Canvas 合成）では `drawLocalPlaceholder`（ローカル Canvas 描画）を使用。placehold.jp は参照用 URL 生成のみ。

---

## 6. ローカル生成・内部プロキシ（外部依存なし）

### img_proxy.php

| 項目 | 内容 |
|------|------|
| 用途 | ホットリンク保護された外部画像の CORS 回避 |
| エンドポイント | `store/img_proxy.php?url=エンコード済みURL` |
| 注意 | セッション Cookie を持たないサーバー発リクエストのため、`serve_workspace_output.php` 等のセッション必須 URL には使用不可（同一オリジン判定でスキップ） |

### placeholder_png.php（PHP GD）

| 項目 | 内容 |
|------|------|
| 用途 | ファイル名・サイズを表示したプレースホルダー PNG を `output/ai_images/` に生成 |
| 呼び出し元 | `store/ai_placeholder_png.php`, `store/lp_ai_image_pipeline.php` |
| 依存 | PHP GD 拡張（`imagecreatetruecolor`） |
| 出力 | `/output/ai_images/placeholder_<hex>.png` |

### image_composite.php（PHP GD / Imagick）

| 項目 | 内容 |
|------|------|
| 用途 | 背景画像 + テキストオーバーレイの合成 |
| フォント | NotoSansCJK（`IMAGE_COMPOSITE_FONT*` または `fonts/` ディレクトリ） |
| 描画順位 | GD + FreeType → Imagick → エラー |

---

## 環境変数まとめ

```
ANTHROPIC_API_KEY=              # Claude Vision
ANTHROPIC_DENY_CLIENT_KEY=1     # クライアント送信キーを拒否
OPENAI_API_KEY=                 # DALL·E
OPENAI_DENY_CLIENT_KEY=1
HUGGINGFACE_API_TOKEN=hf_...    # または HF_TOKEN=hf_...
HF_IMAGE_MODEL=                 # 省略時: black-forest-labs/FLUX.1-schnell
HF_DENY_CLIENT_KEY=1
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
CMS_SUPER_ADMIN=                # super_admin のメールアドレス
```

---

## コスト記録

`lib/api_usage_log.php` が各リクエスト後に以下へ追記・集計する。

| ファイル | 内容 |
|----------|------|
| `data/api_usage_events.jsonl` | リクエスト単位の生ログ |
| `data/api_usage_totals.json` | APIキー変数名別の概算 USD 累計 |

概算単価は `.env` の `LP_USAGE_*` 変数で上書き可能。**請求額の保証ではない。**
