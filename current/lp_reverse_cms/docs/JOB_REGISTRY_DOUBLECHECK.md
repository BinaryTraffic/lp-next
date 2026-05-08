# Job Registry Double Check

最終更新コミット: `b114dca`

## 目的

マルチユーザー運用で「誰が・何の目的で・どのWSで」解析/生成を実行しているかを可視化し、再読込後でも停止できることを確認する。

## 実装ファイル

- `lib/JobRegistry.php`
- `lib/lp_job_runtime.php`
- `store/job_start.php`
- `store/job_list.php`
- `store/job_stop.php`
- `store/job_finish.php`
- `store/analyze_entry.php`
- `store/analyze_internal_page.php`
- `store/analyze_lp.php`
- `store/finalize_analyze.php`
- `store/generate_entry.php`
- `store/generate_internal.php`
- `store/generate_lp.php`
- `index.php`
- `assets/js/index.js`

## 仕様チェック項目

### A. ジョブ開始

- [ ] 解析開始前に「目的」入力ダイアログが出る
- [ ] 生成開始前に「目的」入力ダイアログが出る
- [ ] 目的未入力で開始できない
- [ ] `job_start.php` が `purpose` 必須で受け付ける
- [ ] 同一 `workspace_id` で `running/stopping` ジョブがあると新規開始を拒否する

### B. 可視化（誰が/何のため）

- [ ] 画面「実行中ジョブ」パネルに以下が表示される
  - [ ] 種別（analyze/generate）
  - [ ] 実行者メール
  - [ ] 目的（purpose）
  - [ ] 対象WS
  - [ ] 開始時刻
- [ ] 再読込後も実行中ジョブが一覧に残る

### C. 停止制御

- [ ] ジョブ一覧の「停止」ボタンで `job_stop.php` が呼ばれる
- [ ] 停止要求後、ジョブが `stopping` になる
- [ ] 解析/生成ループ中で `stop requested` を検知して停止できる
- [ ] 生成停止ボタン（既存 UI）でも `job_stop` が呼ばれる
- [ ] 停止後に `job_finish` で `stopped` が記録される

### D. 権限

- [ ] 未ログインで `job_list.php` は 401
- [ ] 一般ユーザーは自分以外のジョブを停止できない
- [ ] admin/super_admin は他ユーザーのジョブ停止が可能

### E. セキュリティ/整合性

- [ ] `job_start/job_stop/job_finish` は CSRF トークン必須
- [ ] `job_registry.json` への read-modify-write が `flock` で保護される
- [ ] JSON 書き込みが `LOCK_EX` で行われる

## 手動テスト手順

1. ユーザーAでログインして Step1 から解析開始（目的を入力）
2. 解析中に「実行中ジョブ」へ `analyze` が表示されることを確認
3. ブラウザを再読込しても同ジョブが表示されることを確認
4. 停止ボタンで停止し、進捗処理が止まることを確認
5. ユーザーAで Step2 から生成開始（目的を入力）
6. 「保存＆サイト生成」中に停止ボタンで停止できることを確認
7. ユーザーB（一般権限）でログインし、ユーザーAジョブの停止可否を確認（拒否が正）
8. admin/super_admin でログインし、他ユーザージョブ停止可否を確認（許可が正）

## API スモーク確認例

未ログイン:

```bash
curl -sS -i "https://lp-next.jitan.app/current/lp_reverse_cms/store/job_list.php"
```

期待:

- HTTP `401`
- `{"ok":false,"error":"Unauthorized"}`

## 既知の注意点

- `job_registry.json` は `data/` 配下（Git 管理外）
- 停止は「安全停止」であり、処理中の任意時点で即 kill ではない
- 既に終了済みジョブは active list (`job_list.php`) に出ない

