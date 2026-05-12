# ワークスペース一覧 UI（メモ・詳細・再編集）

## 概要

`index.php` のワークスペース折りたたみカードでは、**サイト URL・ページタイトル・ページ数・サイズ・解析日時**を主に表示し、`ws_id` などの識別子は **「詳細」展開／ダブルクリック**で確認する。

## 表示データの出所

| フィールド | 主な出所 |
|-----------|----------|
| `site_url`, `page_title`, `page_count`, `analyzed_at` | `data/<ws>/lp_structure.json`（`workspace_list.php` で付加） |
| `industry_hint` | `data/<ws>/industry_suggest.json` の `source_industry` |
| `memo`（ユーザー入力） | **登録済み WS**: `workspace_registry.json` の `memo`<br>**未登録（legacy）**: `data/workspace_memos.json`（sidecar） |

## メモの保存

- **POST** `store/workspace_memo_update.php`（CSRF 必須）
- 登録済み: 所有者または `super_admin`
- **legacy（レジストリ無し）**: `super_admin` のみ。ディスク上に `data/<ws>` または `output/<ws>` がある場合、`workspace_memos.json` に保存

ワークスペース削除成功時、対象キーは **sidecar からも削除**される。

## 「📂 開く」再編集フロー

1. **POST** `store/workspace_open.php` に `workspace_id` と CSRF
2. サーバーが `listForActor` で閲覧可否を確認後、`$_SESSION['lp_reverse_ws']` を該当 hex に設定し **`LpWorkspace::reset()`**
3. クライアントは **`?step=2`** に遷移（`workspace_manage.js`）

### 疑問: URL 取得からでなく、既存 ws から再開できるか

**はい。** 解析済み／生成済みのデータが `data/<ws>/` に残っていれば、「📂 開く」でセッションをその WS に切り替えて **ステップ 2（コンテンツ編集）**に入れる。**ステップ 1 の URL 読み込みは不要**。

前提: 対象 WS があなたの権限で一覧に表示されること（所有者／admin／super_admin のルールは従来どおり）。

## 関連ファイル

| ファイル | 役割 |
|----------|------|
| `store/workspace_list.php` | 一覧 JSON + 構造・業種のエンリッチ |
| `store/workspace_open.php` | セッション WS 切替 |
| `store/workspace_memo_update.php` | メモ保存 |
| `lib/WorkspaceRegistry.php` | レジストリ・`workspace_memos.json`・削除時の sidecar 掃除 |
| `assets/js/workspace_manage.js` | テーブル描画・詳細行・開く・削除 |

## サーバー権限（削除）

大量ファイル削除は Apache（`www-data`）が実行する。**グループ `lp-tool`** 付与が必要な場合あり。詳細は `WORKSPACE_DELETE_AND_PERMISSIONS.md`。
