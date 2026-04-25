<?php
declare(strict_types=1);

$indexHtml = __DIR__ . '/index.html';
if (is_readable($indexHtml)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($indexHtml);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
// Fallback when index.html is not deployed yet (same content as index.html)
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
  <p class="muted">このディレクトリの一覧です。Markdown（.md）は環境によってはブラウザで平文表示になります。レイアウト付きは .html を開いてください。</p>
  <ul>
    <li><a href="PROJECT_HISTORY_AND_SETUP.md">PROJECT_HISTORY_AND_SETUP.md</a> — 経緯・ゼロからの構築手順</li>
    <li><a href="REPORT_LP_INSIGHTS_FEEDBACK.html">REPORT_LP_INSIGHTS_FEEDBACK.html</a> — 分析蓄積・CMS フィードバック（レポート・Word 向け）</li>
    <li><a href="REPORT_LP_INSIGHTS_FEEDBACK.md">REPORT_LP_INSIGHTS_FEEDBACK.md</a> — 同上（Markdown）</li>
  </ul>
  <p class="back"><a href="../">← 管理画面（lp_reverse_cms）</a> · <a href="../../">← リポジトリ入口</a></p>
</body>
</html>
