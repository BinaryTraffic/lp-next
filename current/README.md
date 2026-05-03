# LP-NEXT

**LP Reverse CMS** のソースを置く Git リポジトリです。

**リモート:** [https://github.com/BinaryTraffic/lp-next](https://github.com/BinaryTraffic/lp-next)

**このディレクトリ `current/`** が、いまの**作業ツリーと本番デプロイの基準**です（過去版の固定点は [Git タグ `v1.0.0` など](https://github.com/BinaryTraffic/lp-next/tags)。手順は [../releases/README.md](../releases/README.md)）。DocumentRoot は **`current/` を指す**設定にすると、次の URL 表と一致しやすいです。

```bash
git clone https://github.com/BinaryTraffic/lp-next.git
cd lp-next/current
```

アプリケーション本体・手順書は **`lp_reverse_cms/`** 以下です（本 README を含む `current/` が基準ディレクトリ）。

**スクリプト・画像の配置（開発用と本番用の分離）:** ルートHTML向けの配信用は **`assets/`**、作業専用は **`dev/`**。一覧と運用方針は [JOURNAL.md](JOURNAL.md) の「**開発/デプロイの棲み分け（スクリプト・画像）**」、詳細は [`dev/README.md`](dev/README.md) ・ [`assets/README.md`](assets/README.md) を参照。

---

## 安定版（フィックス版）の指し示し

次の **URL**・**版**に揃えていれば、**2026-04 時点のフィックス安定版**とみなせます（本番検証済みのライン）。

| 項目 | 値 |
|------|-----|
| 入口（DocumentRoot＝**`current/`**） | [https://lp-next.jitan.app/](https://lp-next.jitan.app/)（`current/index.html`） |
| リポジトリルート＝DocumentRoot（`/current/` 入口） | [https://lp-next.jitan.app/current/](https://lp-next.jitan.app/current/) |
| プロジェクトジャーナル（共同レビュー用） | [https://lp-next.jitan.app/current/journal.html](https://lp-next.jitan.app/current/journal.html)（素源: `current/JOURNAL.md`） |
| 管理画面（LP Reverse CMS） | DocumentRoot により: [https://lp-next.jitan.app/lp_reverse_cms/](https://lp-next.jitan.app/lp_reverse_cms/) または [https://lp-next.jitan.app/current/lp_reverse_cms/](https://lp-next.jitan.app/current/lp_reverse_cms/)（リポジトリルートを Web ルートにした場合の後者） |
| アプリ版 | **`1.3.0`**（`lp_reverse_cms/index.php` の `APP_VERSION`） |
| Git タグ | **`v1.2.0`**（`main` の本番想定）／過去: **`v1.1.0`**, **`v1.0.0`**。レガシー表記: `v1.1.11-stable` |

主な内容（v1.2 以降想定）: 資産解決の強化（`@import` / `url` / `srcset`）、**`data` / `output` 書き込み失敗の明示**、デプロイ時の **所有権・権限**（[ENVIRONMENT_AND_OPERATIONS.md](ENVIRONMENT_AND_OPERATIONS.md)）、共同作業・運用ドキュメント。以降の更新は `main` および **`develop/v1.2.0`** ブランチの運用に従います。

---

## ドキュメント（.md）の見方

Web サーバーが **Markdown をそのまま `text/plain` で返す**ことがあり、ブラウザでは読みにくい場合があります。次のいずれかを推奨します。

- [GitHub 上のファイル](https://github.com/BinaryTraffic/lp-next)で表示する  
- ローカルで VS Code / Cursor などエディタから開く  

---

## DocumentRoot の取り方（2 通り）

先方が **URL からツール（管理画面）へ辿り着ける**ようにするには、サーバーの **DocumentRoot をどこに置くか**で URL が変わります。

### A. リポジトリルートを DocumentRoot にする（推奨：入口 URL を分かりやすく）

クローン後の **リポジトリのルート**（例: `/home/user/lp-next`、`C:\...\lp-next`）を DocumentRoot にします。ルートに **`index.html`** があり、`/` から説明とリンクに辿れます。

| URL パス（ホスト直下からの相対） | 内容 |
|----------------------------------|------|
| `/` | 入口ページ（`index.html`）→ 管理画面・ドキュメントへのリンク |
| `/README.md` | 本ファイル（サーバーによっては生テキスト） |
| `/lp_reverse_cms/` | **管理画面**（`index.php`）← ここがメインのツール |
| `/lp_reverse_cms/README.md` | アプリ README |
| `/lp_reverse_cms/docs/` または `/lp_reverse_cms/docs/PROJECT_HISTORY_AND_SETUP.html` | 経緯・ゼロからの構築手順（HTML） |
| [`/ENVIRONMENT_AND_OPERATIONS.md`](ENVIRONMENT_AND_OPERATIONS.md) | **環境・起動・セキュリティ・運用**（本番、共同、トラブル目安） |

**PHP ビルトインサーバー例**（リポジトリルートで起動。`-t` はリポジトリルート＝`.`）:

```bash
cd /path/to/lp-next
php -S localhost:8080
```

- 入口: `http://localhost:8080/`  
- 管理画面: `http://localhost:8080/lp_reverse_cms/`  

**絶対パスで `-t` する例:**

```bash
php -S localhost:8080 -t "C:\path\to\lp-next"
```

**Apache** では `VirtualHost` の `DocumentRoot` を上記リポジトリルートに設定します。`.md` は多くの環境で **HTML 変換なし**のまま配信される想定です（読み方は上記「ドキュメントの見方」参照）。

**サーバーデプロイの留意点:** 初回に **`lp_reverse_cms/data/`** と **`output/`** を、**Web／PHP 実行ユーザー**（例: `www-data`）が**書き込める**ようにすること（多くの環境で、クローンした OS ユーザーの所有のままだと**解析に失敗**する）。[`ENVIRONMENT_AND_OPERATIONS.md`](ENVIRONMENT_AND_OPERATIONS.md) の **§3 運用**→**留意点**に具体例。

### B. `lp_reverse_cms` だけを DocumentRoot にする（従来）

**`lp_reverse_cms`**（`index.php` がある階層）だけを DocumentRoot にします。この場合 **`/` がそのまま管理画面**です。ルートの `index.html` やルート `README.md` は **その VirtualHost の URL からは見えません**（別ホスト・別パスで配信しない限り）。

- 例: `http://example.com/` → 管理画面  
- `php -S` 例: `php -S localhost:8080 -t lp_reverse_cms`（カレントはリポジトリルート想定）

---

## 共同作業・リモート同期の確認

作業前に最新を取り込みます。

```bash
git pull origin main
```

**リモートの `main` 先端と手元のコミットが同じか**（別環境・Cursor 同士で揃っているか）を短く確認する例:

```bash
git fetch origin
git rev-parse HEAD
git ls-remote origin refs/heads/main
```

両方のハッシュの**先頭 7 文字**が一致していれば、同じコミットを指しています。`git status` で `Your branch is up to date with 'origin/main'` でも確認できます。

**リモート URL**の確認: `git remote -v`（`https://github.com/BinaryTraffic/lp-next.git` であること）。

詳細な起動・ディレクトリは [lp_reverse_cms/README.md](lp_reverse_cms/README.md) を。本番向け（`data/` 非公開、HTTPS 等）の一覧は [ENVIRONMENT_AND_OPERATIONS.md](ENVIRONMENT_AND_OPERATIONS.md) も参照してください。
