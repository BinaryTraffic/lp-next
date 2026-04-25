<?php
declare(strict_types=1);

define('APP_VERSION', '1.1.10');
define('APP_BUILD',   date('Ymd', filemtime(__FILE__)));

$dataDir        = __DIR__ . '/data/';
$structureFile  = $dataDir . 'lp_structure.json';
$clientFile     = $dataDir . 'client_data.json';
$outputFile     = __DIR__ . '/output/index.html';
$sourceUrlFile  = $dataDir . 'source_url.txt';

$hasStructure = file_exists($structureFile);
$hasOutput    = file_exists($outputFile);
$sourceUrl    = file_exists($sourceUrlFile) ? trim((string) file_get_contents($sourceUrlFile)) : '';

// Load data for edit template
$structure  = [];
$clientData = [];
if ($hasStructure) {
    $decoded = json_decode((string) file_get_contents($structureFile), true);
    if (is_array($decoded)) {
        $structure = $decoded;
    }
}
if (file_exists($clientFile)) {
    $decoded = json_decode((string) file_get_contents($clientFile), true);
    if (is_array($decoded)) {
        $clientData = $decoded;
    }
}

// Determine which step to show on initial load (1 = fetch, 2 = edit, 3 = done)
$initialStep = $hasOutput ? 3 : ($hasStructure ? 2 : 1);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LP Reverse CMS</title>

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <!-- Custom admin styles -->
  <link rel="stylesheet" href="assets/css/index.css">
</head>
<body class="bg-light">

<!-- ===== NAVBAR ===== -->
<nav class="navbar navbar-dark bg-primary shadow-sm">
  <div class="container-fluid">
    <span class="navbar-brand fw-bold">
      <i class="bi bi-arrow-repeat me-2"></i>LP Reverse CMS
      <span class="badge bg-white text-primary ms-2 fw-normal" style="font-size:.65rem;vertical-align:middle">
        v<?= APP_VERSION ?>
      </span>
    </span>
    <div class="d-flex align-items-center gap-2">
      <?php if ($hasOutput): ?>
        <a href="preview.php" target="_blank" class="btn btn-sm btn-light">
          <i class="bi bi-eye me-1"></i>プレビュー
        </a>
        <a href="export.php" class="btn btn-sm btn-success">
          <i class="bi bi-download me-1"></i>エクスポート
        </a>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-light" id="btnDiag" title="診断情報">
        <i class="bi bi-bug"></i>
      </button>
    </div>
  </div>
</nav>

<!-- ===== DIAGNOSTIC MODAL ===== -->
<div class="modal fade" id="diagModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="bi bi-bug me-2"></i>診断情報 — v<?= APP_VERSION ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="diagContent">
          <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
        </div>
        <div class="mt-3 p-3 bg-light rounded border small">
          <strong>スタイルが反映されない場合：</strong><br>
          Step 1 に戻り「<strong>解析する</strong>」をもう一度実行してください（CSS・画像が再ダウンロードされます）。<br>
          その後 Step 2 で「<strong>保存＆LP生成</strong>」を実行してください。
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== MAIN LAYOUT ===== -->
<div class="container-fluid py-4" style="max-width: 1200px;">

  <!-- Step indicator -->
  <div class="d-flex align-items-center gap-0 mb-4" id="stepIndicator">
    <?php
      $steps = [
        1 => ['icon' => 'bi-globe',        'label' => 'URLを取得'],
        2 => ['icon' => 'bi-pencil-square', 'label' => 'コンテンツ編集'],
        3 => ['icon' => 'bi-check-circle',  'label' => '生成完了'],
      ];
      foreach ($steps as $n => $s):
        $done    = ($initialStep > $n);
        $active  = ($initialStep === $n);
        $cls     = $done ? 'step-done' : ($active ? 'step-active' : 'step-pending');
    ?>
      <div class="step-item <?= $cls ?>" data-step="<?= $n ?>">
        <div class="step-circle">
          <?php if ($done): ?>
            <i class="bi bi-check-lg"></i>
          <?php else: ?>
            <i class="bi <?= $s['icon'] ?>"></i>
          <?php endif; ?>
        </div>
        <div class="step-label"><?= $s['label'] ?></div>
      </div>
      <?php if ($n < count($steps)): ?>
        <div class="step-connector <?= $done ? 'step-connector-done' : '' ?>"></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- ===========================
       STEP 1 — Fetch & Analyse
       =========================== -->
  <div id="step1Panel" class="<?= $initialStep !== 1 ? 'd-none' : '' ?>">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-header bg-primary text-white">
            <i class="bi bi-globe me-2"></i><strong>参照LPのURL入力</strong>
          </div>
          <div class="card-body p-4">
            <p class="text-muted mb-3">
              コピーしたいLPのURLを入力してください。<br>
              HTMLを取得・解析し、編集可能なコンテンツ要素を自動抽出します。
            </p>
            <div class="input-group input-group-lg mb-3">
              <span class="input-group-text bg-white"><i class="bi bi-link-45deg"></i></span>
              <input type="url" id="lpUrlInput" class="form-control"
                     placeholder="https://example.com/lp"
                     value="<?= htmlspecialchars($sourceUrl, ENT_QUOTES) ?>">
              <button id="btnFetchAnalyze" class="btn btn-primary px-4">
                <i class="bi bi-search me-1"></i>解析する
              </button>
            </div>

            <!-- Progress steps inside fetch flow -->
            <div id="fetchProgress" class="d-none mt-3">
              <div class="list-group list-group-flush rounded border">
                <div class="list-group-item d-flex align-items-center gap-3 py-3" id="prog_fetch">
                  <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                  <div>
                    <div class="fw-semibold">HTML・CSS・画像を取得中…</div>
                    <div class="small text-muted" id="prog_fetch_detail"></div>
                  </div>
                </div>
                <div class="list-group-item d-flex align-items-center gap-3 py-3 text-muted" id="prog_analyze">
                  <div class="text-secondary"><i class="bi bi-circle fs-5"></i></div>
                  <div>
                    <div class="fw-semibold">ページ構造を解析中…</div>
                    <div class="small text-muted" id="prog_analyze_detail"></div>
                  </div>
                </div>
              </div>
            </div>

            <div id="fetchError" class="alert alert-danger d-none mt-3"></div>
          </div>
        </div>

        <!-- Tips card -->
        <div class="card mt-3 border-0 bg-white shadow-sm">
          <div class="card-body">
            <h6 class="fw-bold mb-2"><i class="bi bi-lightbulb-fill text-warning me-1"></i>使い方</h6>
            <ol class="mb-0 small text-muted">
              <li>コピー元LPのURLを入力して「解析する」をクリック</li>
              <li>抽出された各テキスト・画像を自社情報に書き換え</li>
              <li>「保存＆LP生成」でHTMLを出力 → プレビュー・エクスポート</li>
            </ol>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ===========================
       STEP 2 — Edit Content
       =========================== -->
  <div id="step2Panel" class="<?= $initialStep !== 2 ? 'd-none' : '' ?>">

    <!-- Toolbar -->
    <div class="d-flex align-items-center justify-content-between mb-3">
      <button class="btn btn-sm btn-outline-secondary" id="btnBackToStep1">
        <i class="bi bi-arrow-left me-1"></i>別のURLを解析
      </button>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="btnResetClient">
          <i class="bi bi-arrow-counterclockwise me-1"></i>リセット
        </button>
        <button class="btn btn-primary px-4" id="btnSaveGenerate">
          <i class="bi bi-lightning-charge-fill me-1"></i>保存＆LP生成
        </button>
      </div>
    </div>

    <!-- Edit form (rendered by PHP template) -->
    <div id="editFormWrapper">
      <?php if ($hasStructure): ?>
        <?php include __DIR__ . '/template/editPage.php'; ?>
      <?php endif; ?>
    </div>

    <!-- Save/generate status -->
    <div id="generateError"  class="alert alert-danger  d-none mt-3"></div>
    <div id="generateSuccess" class="alert alert-success d-none mt-3"></div>
  </div>

  <!-- ===========================
       STEP 3 — Done
       =========================== -->
  <div id="step3Panel" class="<?= $initialStep !== 3 ? 'd-none' : '' ?>">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="card shadow-sm p-5 text-center">
          <div class="mb-4">
            <i class="bi bi-check-circle-fill text-success" style="font-size:4rem"></i>
          </div>
          <h3 class="fw-bold mb-2">LP生成が完了しました！</h3>
          <p class="text-muted mb-4">
            参照LPの構成を保ったまま、新しいLPが生成されました。
          </p>
          <div class="d-flex justify-content-center gap-3 flex-wrap mb-4">
            <a href="preview.php" target="_blank" class="btn btn-lg btn-primary">
              <i class="bi bi-eye me-2"></i>プレビューを確認
            </a>
            <a href="export.php" class="btn btn-lg btn-success">
              <i class="bi bi-download me-2"></i>HTMLをダウンロード
            </a>
            <button class="btn btn-lg btn-outline-secondary" id="btnEditAgain">
              <i class="bi bi-pencil-square me-2"></i>編集に戻る
            </button>
          </div>

          <!-- Asset health summary -->
          <div id="step3DiagSummary" class="text-start mt-2">
            <div class="text-center"><div class="spinner-border spinner-border-sm text-secondary"></div> アセット状況を確認中…</div>
          </div>

          <?php if ($hasOutput): ?>
            <div class="mt-3 text-muted small">
              生成ファイル：<code>output/index.html</code>
              (<?= number_format(filesize($outputFile)) ?> bytes)
              — v<?= APP_VERSION ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div><!-- /container-fluid -->

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Pass PHP state to JS -->
<script>
window.LP_CMS = {
  initialStep:  <?= $initialStep ?>,
  hasStructure: <?= $hasStructure ? 'true' : 'false' ?>,
  hasOutput:    <?= $hasOutput    ? 'true' : 'false' ?>,
  sourceUrl:    <?= json_encode($sourceUrl) ?>,
};
</script>

<script src="assets/js/index.js"></script>
</body>
</html>
