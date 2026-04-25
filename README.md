# LP-NEXT

**LP Reverse CMS** のソースを置く Git リポジトリです。

**リモート:** [https://github.com/BinaryTraffic/lp-next](https://github.com/BinaryTraffic/lp-next)

```bash
git clone https://github.com/BinaryTraffic/lp-next.git
cd lp-next
```

アプリケーション本体・手順書は **`lp_reverse_cms/`** 以下です（クローン後のカレントはリポジトリルート想定）。

---

## 安定版（フィックス版）の指し示し

次の **URL**・**版**に揃えていれば、**2026-04 時点のフィックス安定版**とみなせます（本番検証済みのライン）。

| 項目 | 値 |
|------|-----|
| 入口（DocumentRoot＝リポジトリルート） | [https://lp-next.jitan.app/](https://lp-next.jitan.app/) |
| 管理画面（LP Reverse CMS） | [https://lp-next.jitan.app/lp_reverse_cms/](https://lp-next.jitan.app/lp_reverse_cms/) |
| アプリ版 | **`1.1.11`**（`lp_reverse_cms/index.php` の `APP_VERSION`） |
| Git タグ | **`v1.1.11-stable`**（この安定版ラインに対応） |

主な内容: **`data` / `output` 書き込み失敗の明示**（取得 API・v1.1.11）、デプロイ時の **所有権・権限**の留意（[ENVIRONMENT_AND_OPERATIONS.md](ENVIRONMENT_AND_OPERATIONS.md)）、共同作業・運用ドキュメントの整理。

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
| `/lp_reverse_cms/docs/PROJECT_HISTORY_AND_SETUP.md` | 経緯・ゼロからの構築手順 |
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
