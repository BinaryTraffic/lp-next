# LP-NEXT（リポジトリ構成）

[BinaryTraffic/lp-next](https://github.com/BinaryTraffic/lp-next) のディレクトリ構成です。

```text
lp-next/
├── current/        ← 現在の作業・デプロイ対象（LP Reverse CMS 本体）
├── releases/       ← 版ごとのスナップショット（参照・差分比較用）
│   ├── v1.0.0/     ← Git 初期コミット相当（RELEASE.txt 参照）
│   ├── v1.1.0/     ← v1.1.11 安定ライン（コミット 7dcb10e / タグ v1.1.11-stable 相当）
│   └── v1.2.0/     ← v1.2.0 機能系（例: コミット 7dfb9d5 相当。RELEASE.txt 参照）
└── README.md       ← 本ファイル
```

| 領域 | 用途 |
|------|------|
| [**current/**](current/) | **いま触るのはここ。** `lp_reverse_cms/`・ルート用 `index.html`・各種 `.md` がここにあります。本番の **DocumentRoot は `current/` を向ける**のが扱いやすいです。 |
| [**releases/v\*/** ](releases/) | `git archive` 相当の**凍結コピー**。過去版の挙動確認や、特定コミットとの差分に使います。中身を直接編集して本番に載せる運用は非推奨。 |

- 作業中の手順・安定版表・URL の説明: [**current/README.md**](current/README.md)  
- 入口 HTML（`current` を DocumentRoot にした前提）: [**current/index.html**](current/index.html)  
- 運用・権限: [**current/ENVIRONMENT_AND_OPERATIONS.md**](current/ENVIRONMENT_AND_OPERATIONS.md)

**Git:** 版管理の正は**タグとコミット**です。`releases/` は人間用の**フォルダ分け**であり、必ず `RELEASE.txt` のコミット指し示しと併用してください。

**移行前の本番**がリポジトリ**直下**を DocumentRoot にしていた場合は、**`current/` へ合わせる**か、URL 用にシンボリックリンク・別 VirtualHost を検討してください。
