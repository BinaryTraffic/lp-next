# LP-NEXT

**LP Reverse CMS** のソースを置く Git リポジトリです。

**リモート:** [https://github.com/BinaryTraffic/lp-next](https://github.com/BinaryTraffic/lp-next)

```bash
git clone https://github.com/BinaryTraffic/lp-next.git
cd lp-next
```

アプリケーション本体・手順書は **`lp_reverse_cms/`** 以下です（クローン後のカレントはリポジトリルート想定）。

- [lp_reverse_cms/README.md](lp_reverse_cms/README.md) — 概要・ディレクトリ・起動方法
- [lp_reverse_cms/docs/PROJECT_HISTORY_AND_SETUP.md](lp_reverse_cms/docs/PROJECT_HISTORY_AND_SETUP.md) — 経緯とゼロからの構築

Web サーバーのドキュメントルートは **`lp_reverse_cms`**（`index.php` がある階層）にしてください。

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

両方のハッシュの**先頭 7 文字**（例: `4895729`）が一致していれば、同じコミットを指しています。`git status` で `Your branch is up to date with 'origin/main'` でも確認できます。

**リモート URL**の確認: `git remote -v`（`https://github.com/BinaryTraffic/lp-next.git` であること）。

詳細な起動・開発手順は [lp_reverse_cms/README.md](lp_reverse_cms/README.md) を参照してください。
