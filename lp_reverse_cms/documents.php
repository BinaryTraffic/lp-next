<?php
declare(strict_types=1);

/** ドキュメント一覧（/docs/ から mod_rewrite でも直叩きでも可） */
$app  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$docs = $app . '/docs/';
$d    = static fn (string $f): string => htmlspecialchars($docs . $f, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ドキュメント — LP Reverse CMS</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.55; color: #222; }
    h1 { font-size: 1.25rem; border-bottom: 1px solid #ccc; padding-bottom: 0.5rem; }
    ul { padding-left: 1.2rem; }
    li { margin: 0.65rem 0; }
    a { color: #0b57d0; }
    .muted { color: #555; font-size: 0.9rem; }
    .back { margin-top: 2rem; font-size: 0.9rem; }
  </style>
</head>
<body>
  <h1>LP Reverse CMS — ドキュメント</h1>
  <p class="muted">ブラウザで読むなら <strong>.html</strong> を推奨（インデント・表付き）。.md は文字化けすることがあります。</p>
  <ul>
    <li><a href="<?= $d('PROJECT_HISTORY_AND_SETUP.html') ?>">PROJECT_HISTORY_AND_SETUP.html</a> — 経緯・ゼロからの構築手順（読みやすい HTML）</li>
    <li><a href="<?= $d('PROJECT_HISTORY_AND_SETUP.md') ?>">PROJECT_HISTORY_AND_SETUP.md</a> — 同上（Markdown）</li>
    <li><a href="<?= $d('REPORT_LP_INSIGHTS_FEEDBACK.html') ?>">REPORT_LP_INSIGHTS_FEEDBACK.html</a> — 分析蓄積・CMS フィードバック（レポート・Word 向け）</li>
    <li><a href="<?= $d('REPORT_LP_INSIGHTS_FEEDBACK.md') ?>">REPORT_LP_INSIGHTS_FEEDBACK.md</a> — 同上（Markdown）</li>
  </ul>
  <p class="back"><a href="./">← 管理画面（lp_reverse_cms）</a> · <a href="../">← リポジトリ入口</a></p>
</body>
</html>
