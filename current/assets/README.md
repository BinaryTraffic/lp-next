# 配信用静的ファイル（`assets/`）

**DocumentRoot＝`current/`** 前提で、**ルートの HTML**（`index.html` / `journal.html` / `prompt_context_demo.html` など）から相対・絶対パスで**本番含めて配信する**JS・画像・小さな補助ファイルを置く。

| 下位 | 例 |
|------|-----|
| **`images/`** | 配信用ロゴ、ヒーロー下書きで確定した画像（パス例: `assets/images/...`） |
| **`js/`** | 上記HTML専用の小さなスクリプト（CDNと併用可） |

## 本番用・開発用の違い

- **ここ（`current/assets/`）** … 本番URLに乗る想定。パスをHTMLに書くときの基準にする。
- **[`../dev/`](../dev/)** … 開発専用。本番に同期しない or 非公開（ジャーナル参照）。

`lp_reverse_cms` 管理画面専用の既存リソースは **`lp_reverse_cms/assets/`** のまま。混同しないこと。
