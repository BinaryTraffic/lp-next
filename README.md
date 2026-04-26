# LP-NEXT（リポジトリ構成）

[BinaryTraffic/lp-next](https://github.com/BinaryTraffic/lp-next) のディレクトリ構成と **Git 運用**のメモです。

## Git ブランチ

| ブランチ | 役割 |
|----------|------|
| **main** | 本番・安定版。マージ先は常にここ。 |
| **develop/v1.2.0** | 次の（v1.2 系の）作業。フィーチャーは原則ここに集約し、都度 `main` へ。 |

`git clone` 直後の通常作業: `main` から作業用ブランチを切るか、既存の `develop/v1.2.0` を追います。

## 版の固定（タグ）

| タグ | 役割 |
|------|------|
| **v1.0.0** | 過去版の固定点（初期版）。 |
| **v1.1.0** | 過去版の固定点（v1.1 系・安定ライン）。 |
| **v1.2.0** | 現行本番想定の固定点。 |

`git tag -l` で一覧し、`git show v1.2.0` で指すコミットを確認します。過去ツリーの出し方は [releases/README.md](releases/README.md) を参照。

```text
lp-next/
├── current/         ← 現在の作業・デプロイ対象（LP Reverse CMS 本体）
├── releases/        ← 版取得の手順（README のみ。実体の固定は Git タグで行う）
└── README.md        ← 本ファイル
```

| 領域 | 用途 |
|------|------|
| [**current/**](current/) | **いま触るのはここ。** `lp_reverse_cms/`・ルート用 `index.html`・各種 `.md` がここにあります。本番の **DocumentRoot は `current/` を向ける**のが扱いやすいです。 |
| [**releases/**](releases/) | タグの使い方の説明のみ。旧来の `releases/v1.x/` 配下のコピー展開は廃止し、**タグで版を区切ります。** |

- 作業中の手順・安定版表・URL の説明: [**current/README.md**](current/README.md)  
- 入口 HTML（`current` を DocumentRoot にした前提）: [**current/index.html**](current/index.html)  
- 運用・権限: [**current/ENVIRONMENT_AND_OPERATIONS.md**](current/ENVIRONMENT_AND_OPERATIONS.md)

**移行前の本番**がリポジトリ**直下**を DocumentRoot にしていた場合は、**`current/` へ合わせる**か、URL 用にシンボリックリンク・別 VirtualHost を検討してください。
