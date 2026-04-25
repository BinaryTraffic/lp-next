<?php
declare(strict_types=1);

$outputFile = __DIR__ . '/output/index.html';

if (!file_exists($outputFile)) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>プレビュー — LP Reverse CMS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #18191a; }

    .preview-bar {
      position: fixed;
      top: 0; left: 0; right: 0;
      height: 52px;
      background: #212529;
      border-bottom: 2px solid #0d6efd;
      display: flex;
      align-items: center;
      padding: 0 16px;
      gap: 10px;
      z-index: 9999;
    }

    .preview-bar .sep {
      width: 1px; height: 28px;
      background: rgba(255,255,255,.2);
    }

    .preview-bar .device-btns .btn {
      border-radius: 4px;
    }

    .preview-bar .device-btns .btn.active {
      background: #0d6efd;
      color: #fff;
    }

    .iframe-container {
      margin-top: 52px;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      min-height: calc(100vh - 52px);
      padding: 0;
      transition: padding .3s ease;
    }

    .iframe-container.device-mobile  { padding: 20px; }
    .iframe-container.device-tablet  { padding: 20px; }

    iframe#previewFrame {
      width: 100%;
      border: none;
      height: calc(100vh - 52px);
      background: #fff;
      transition: width .3s ease, height .3s ease, box-shadow .3s ease;
    }

    .iframe-container.device-mobile iframe#previewFrame {
      width: 390px;
      height: 844px;
      border-radius: 20px;
      box-shadow: 0 0 0 10px #1a1a1a, 0 0 0 14px #333, 0 20px 60px rgba(0,0,0,.5);
    }

    .iframe-container.device-tablet iframe#previewFrame {
      width: 768px;
      height: 1024px;
      border-radius: 12px;
      box-shadow: 0 0 0 8px #1a1a1a, 0 20px 60px rgba(0,0,0,.5);
    }
  </style>
</head>
<body>

<div class="preview-bar">
  <a href="index.php" class="btn btn-sm btn-outline-light">
    <i class="bi bi-arrow-left me-1"></i>編集に戻る
  </a>

  <div class="sep"></div>

  <!-- Device switcher -->
  <div class="device-btns btn-group btn-group-sm" role="group">
    <button class="btn btn-outline-secondary active" id="btnDesktop" title="デスクトップ">
      <i class="bi bi-display"></i>
    </button>
    <button class="btn btn-outline-secondary" id="btnTablet" title="タブレット">
      <i class="bi bi-tablet-landscape"></i>
    </button>
    <button class="btn btn-outline-secondary" id="btnMobile" title="スマートフォン">
      <i class="bi bi-phone"></i>
    </button>
  </div>

  <div class="sep"></div>

  <span class="text-white small d-none d-md-inline">
    <i class="bi bi-eye me-1"></i>
    <?php
      $generated = new DateTime('@' . filemtime($outputFile));
      $generated->setTimezone(new DateTimeZone('Asia/Tokyo'));
      echo '生成：' . $generated->format('Y/m/d H:i');
    ?>
  </span>

  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-sm btn-outline-light" id="btnRefresh" title="再読み込み">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
    <a href="export.php" class="btn btn-sm btn-success">
      <i class="bi bi-download me-1"></i>エクスポート
    </a>
  </div>
</div>

<div class="iframe-container" id="iframeContainer">
  <iframe id="previewFrame" src="output/index.html" title="生成LP プレビュー"></iframe>
</div>

<script>
const container = document.getElementById('iframeContainer');

document.getElementById('btnDesktop').addEventListener('click', () => {
  container.className = 'iframe-container device-desktop';
  document.querySelectorAll('.device-btns .btn').forEach(b => b.classList.remove('active'));
  document.getElementById('btnDesktop').classList.add('active');
});
document.getElementById('btnTablet').addEventListener('click', () => {
  container.className = 'iframe-container device-tablet';
  document.querySelectorAll('.device-btns .btn').forEach(b => b.classList.remove('active'));
  document.getElementById('btnTablet').classList.add('active');
});
document.getElementById('btnMobile').addEventListener('click', () => {
  container.className = 'iframe-container device-mobile';
  document.querySelectorAll('.device-btns .btn').forEach(b => b.classList.remove('active'));
  document.getElementById('btnMobile').classList.add('active');
});
document.getElementById('btnRefresh').addEventListener('click', () => {
  document.getElementById('previewFrame').src = document.getElementById('previewFrame').src;
});
</script>
</body>
</html>
