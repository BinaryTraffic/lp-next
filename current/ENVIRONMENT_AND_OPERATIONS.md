# 環境・起動・セキュリティ・運用（Site Reverse CMS / LP-NEXT）

[LP-NEXT ルート README](README.md) の補足として、**本番・共同開発・障害切り分け**に使う要点を集約しています。Markdown は [GitHub 上](https://github.com/BinaryTraffic/lp-next) かエディタで読む習慣にするとよいです。

**参照本番例（フィックス安定版 `1.1.11` ライン）:** 入口 <https://lp-next.jitan.app/> ／ 管理 <https://lp-next.jitan.app/lp_reverse_cms/> 。揃い確認は README の **「安定版（フィックス版）の指し示し」** と Git タグ **`v1.1.11-stable`**。

---

## 1. 環境・起動

- **PHP 8.x** を用意し、拡張 **curl** / **dom** / **json** / **mbstring** を有効にする。`php -m` で確認。
- **DocumentRoot** は**どちらか一方に統一**する（混在すると URL・相対パスを誤る原因になる）。
  - **ルート＝リポジトリ**（`lp-next.jitan.app` のパターン）: **`/`** が入口（`index.html` 等）、**`/lp_reverse_cms/`** が管理画面（`index.php`）。
  - **`lp_reverse_cms` だけ**に DocumentRoot を置く: **`/`** がそのまま管理画面のルート（`index.php` が文書ルート直下）。
- **`index.php` を `file://` で開かない**。**必ず HTTP(S) 経由**で触る。`store/*.php` への相対リクエストが正しくつながるため。

---

## 2. セキュリティ（本番で特に）

- **`lp_reverse_cms/data/`**（または DocumentRoot 配下の `data/`）を **Web から直接読めない**ようにする。`.htaccess` や Nginx の `location` などで**拒否**する。`source.html`・`client_data.json` などが**丸見え**になるのは避ける。
- **管理画面の URL** を**知られにくいパス**に隠すだけに頼らない。可能なら **Basic 認証**・**VPN**・**IP 制限**の**いずれか**を検討する。公開向けの「LP 取得・生成」系ツールは**スパム・悪用**のリスクを念頭に置く。
- **HTTPS を前提**にする（フォーム・Cookie・参照 URL の扱い）。

---

## 3. 運用

- **`git pull` 後**、このプロダクト用の**マイグレーションは基本不要**だが、**`data/`** と **`output/`** はリポジトリに含めない想定のため、サーバー上で**解析〜生成をやり直す**か、**別途バックアップから復元**する。
- **`data/`** と **`output/`** への**書き込み権限**を、Web サーバー／**PHP の実行ユーザー**に付与する。解析・**アセット取得**で失敗しがち。
- **アセット取得**は**時間がかかる**ことがある。**タイムアウト**（PHP `max_execution_time`、**プロキシ**・**ロードバランサ**）を確認する。
- **バージョン確認**は管理画面のバッジ、または `index.php` の **`APP_VERSION`**。不具合時はリポジトリの **`main`** と **`git rev-parse HEAD`** を揃えて比較する。
- **静的ファイルの入れ分け（`current/`）:** 開発専用のスクリプト・モック画像は **`dev/`**、本番に配信するルート用アセットは **`assets/`**（`current/` 直下。DocumentRoot＝`current/` 想定）。本番に **`dev/` を同梱する場合**は Apache の `dev/.htaccess` 拒否＋Nginx では同等制御、または**デプロイ先に `dev/` を置かない**（`rsync` の `--exclude 'dev'` 等）。[JOURNAL.md](JOURNAL.md)（該当節）・[dev/README.md](dev/README.md) を参照。

### 留意点: `data/` と `output/` の**所有・権限**（再発しがち）

- **クローン直後**や、作業用 OS ユーザーの**デプロイ**では、`lp_reverse_cms/data/`・`lp_reverse_cms/output/` の**所有者**が*そのユーザー*のまま**755**等になることがある。**Apache / `php-fpm` の実ユーザー**（多くの Linux では **`www-data`**。環境で変わる）が**書き込めない**と、解析で `source.html` 等を保存できない。アプリ **v1.1.11** 以降は、取得段階で**ファイルに書き込めません: …/data/source.html** など**明示**する（以前はうっかり「HTMLが見つかりません」まで進んでいた）。
- **初回**および**不調時**は、上記2ディレクトリを、例: **`chown -R www-data:運用用グループ`**＋**`chmod -R u+rwX,g+rwX`** など、**Web/PHP 実行ユーザー＋（任意）共同グループ**に合わせる。開発用 UID を同じ**グループ**に入れておくと SSH での中身確認と両立しやすい。
- **`git pull` / `rsync` 後**に所有が戻ると**再発**する。`ls -la` で**所有者**を再確認。

※ **`data/` をブラウザから直 URL で読ませない**扱い（**§2 セキュリティ**）と併用すること。

---

## 4. ドキュメント・共同開発

- **`.md` は**ブラウザからだと**平文**になりがち。[**BinaryTraffic/lp-next**](https://github.com/BinaryTraffic/lp-next) を開くか、**エディタ**で同一パスを読む習慣がよい。
- 作業前に **`git pull origin main`**。ずれが疑わしいときは **`git rev-parse HEAD`** と **`git ls-remote origin refs/heads/main`** の**先頭 7 文字**を比較する（[ルート README](README.md) の共同作業節と同趣旨）。

---

## 5. トラブル時のチェック

| 現象 | まず見る |
|------|----------|
| 画面が**真っ白**・**500** | **PHP エラーログ**、`php -v`、**拡張モジュール**（上記 4 系） |
| **スタイルが付かない** | **`output/assets/css`** の有無、`store/debug.php` の **map ／ 未置換** |
| **解析後**も**古い見た目** | **「保存＆LP生成」**の再実行、**`asset_map`** の反映、生成物 **`output/index.html`** |
| 取得ののち **「HTMLが見つかりません」**（解析段階） | 多くは **`lp_reverse_cms/data/` へ書けていない**（権限）。**v1.1.11** 以降は取得 API が**書き込み失敗を明示**。**`data/`・`output/`** の所有者・**書き込み**（Web/PHP ユーザー）を確認 |
| 挙動が**コードの期待と違う** | デプロイ先の `HEAD` を **`git rev-parse HEAD`**・リモート **`main`** と揃えたか（上記 3・4 節） |

より細かい初回手順は [lp_reverse_cms/docs/PROJECT_HISTORY_AND_SETUP.md](lp_reverse_cms/docs/PROJECT_HISTORY_AND_SETUP.md) の「4.5」「4.8」、ディレクトリの説明は [lp_reverse_cms/README.md](lp_reverse_cms/README.md) を参照。
