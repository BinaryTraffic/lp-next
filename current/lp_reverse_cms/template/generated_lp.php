<?php
/**
 * generated_lp.php — standalone wrapper used when serving the generated LP
 * inside the CMS preview context (adds a thin admin toolbar).
 *
 * Usage: include this file from preview.php or serve it directly.
 *
 * Expected variables:
 *   $generatedHtml : string  — full HTML of the generated LP
 */
if (!isset($generatedHtml)) {
    $outputFile    = __DIR__ . '/../output/index.html';
    $generatedHtml = file_exists($outputFile) ? (string) file_get_contents($outputFile) : '';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>生成LP プレビュー — LP Reverse CMS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body { background: #18191a; margin: 0; }
    .preview-toolbar {
      position: fixed;
      top: 0; left: 0; right: 0;
      height: 52px;
      background: #212529;
      border-bottom: 2px solid #0d6efd;
      display: flex;
      align-items: center;
      padding: 0 20px;
      gap: 12px;
      z-index: 9999;
    }
    .preview-toolbar .sep { width: 1px; height: 28px; background: #444; }
    .preview-frame-wrapper {
      margin-top: 52px;
      background: #fff;
    }
  </style>
</head>
<body>

<div class="preview-toolbar">
  <span class="text-white fw-bold small"><i class="bi bi-eye me-1"></i>LP Reverse CMS — プレビュー</span>
  <div class="sep"></div>
  <a href="../index.php" class="btn btn-sm btn-outline-light">
    <i class="bi bi-pencil-square me-1"></i>編集に戻る
  </a>
  <a href="../export.php" class="btn btn-sm btn-success ms-auto">
    <i class="bi bi-download me-1"></i>HTMLエクスポート
  </a>
</div>

<div class="preview-frame-wrapper">
  <?= $generatedHtml ?>
</div>

</body>
</html>
