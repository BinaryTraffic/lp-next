<?php
declare(strict_types=1);

define('APP_VERSION', '1.3.0');
define('APP_BUILD',   date('Ymd', filemtime(__FILE__)));

require_once __DIR__ . '/lib/LpWorkspace.php';

$cmsRoot = __DIR__;
$dataDir        = LpWorkspace::dataDir($cmsRoot);
$outputDir      = LpWorkspace::outputDir($cmsRoot);
$structureFile  = $dataDir . 'lp_structure.json';
$clientFile     = $dataDir . 'client_data.json';
$outputFile     = $outputDir . 'index.html';
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

$projectProfileForJs = [];
$profileFile = $dataDir . 'lp_project_profile.json';
if (is_readable($profileFile)) {
    $decoded = json_decode((string) file_get_contents($profileFile), true);
    if (is_array($decoded)) {
        unset($decoded['updated_at']);
        $projectProfileForJs = $decoded;
    }
}

// 元LP業種（Haiku）＋サジェスト — editPage と共有。ここで1回のみ API 呼び出し。
$sourceIndustry           = '';
$suggestions              = [];
$aiStructureFingerprint   = '';
if ($hasStructure && $structure !== []) {
    require_once __DIR__ . '/lib/suggest_industries.php';
    $aiSuggestBundle = lp_reverse_suggest_industries_from_structure($structure);
    $sourceIndustry  = (string) ($aiSuggestBundle['source_industry'] ?? '');
    $suggestions     = $aiSuggestBundle['suggestions'] ?? [];
    if (!is_array($suggestions)) {
        $suggestions = [];
    }
    $mtime = @filemtime($structureFile);
    $aiStructureFingerprint = md5(($mtime !== false ? (string) $mtime : '0') . "\n" . $sourceUrl);
}

// Determine which step to show on initial load (1 = fetch, 2 = edit, 3 = done)
$stepQuery = isset($_GET['step']) ? (int) $_GET['step'] : 0;
if ($stepQuery >= 1 && $stepQuery <= 3) {
    $initialStep = $stepQuery;
} else {
    $initialStep = $hasOutput ? 3 : ($hasStructure ? 2 : 1);
}
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
        <a href="export.php" class="btn btn-sm btn-success" title="ZIP（index.html・assets・このクローンのカスタム画像）">
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
      <div class="col-lg-10">
        <!-- 企業・LPプロフィール（将来 AI 自動生成の入力 / 仕様確定後エンティティ図化予定） -->
        <div class="card shadow-sm mb-3 border-secondary-subtle" id="projectProfileCard">
          <div class="card-header bg-secondary text-white py-2 d-flex align-items-center flex-wrap gap-2">
            <i class="bi bi-building me-1"></i><strong>企業・LPプロフィール</strong>
            <small class="ms-lg-auto opacity-75">参照URL解析前に入力推奨（保存は自動・手動いずれも可）</small>
          </div>
          <div class="card-body p-3">
            <div class="row g-2 small">
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-company_name">企業名</label>
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control" id="pp-company_name" maxlength="200" autocomplete="organization" placeholder="例 株式会社サンプル">
                  <button type="button" class="btn btn-outline-secondary" id="pp-company_lookup_btn" title="公表情報・参考ヒント（要確認）">検索</button>
                </div>
                <div class="form-text text-muted">検索結果は参考です。LPの根拠にする前に公式情報で必ず確認してください。</div>
              </div>
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-representative_name">代表者名</label>
                <input type="text" class="form-control form-control-sm" id="pp-representative_name" maxlength="120" autocomplete="name">
              </div>
              <div class="col-md-4">
                <label class="form-label mb-0 text-muted" for="pp-postal_code">郵便番号</label>
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control" id="pp-postal_code" maxlength="16" placeholder="例 1000001" inputmode="numeric" autocomplete="postal-code">
                  <button type="button" class="btn btn-outline-secondary" id="pp-postal_lookup" title="郵便番号から住所を反映（zipcloud）">反映</button>
                </div>
              </div>
              <div class="col-md-4">
                <label class="form-label mb-0 text-muted" for="pp-address_pref">都道府県</label>
                <input type="text" class="form-control form-control-sm" id="pp-address_pref" maxlength="48">
              </div>
              <div class="col-md-4">
                <label class="form-label mb-0 text-muted" for="pp-address_city">市区町村</label>
                <input type="text" class="form-control form-control-sm" id="pp-address_city" maxlength="120">
              </div>
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-address_line">町域・番地</label>
                <input type="text" class="form-control form-control-sm" id="pp-address_line" maxlength="200" autocomplete="street-address">
              </div>
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-address_building">建物名・部屋番号</label>
                <input type="text" class="form-control form-control-sm" id="pp-address_building" maxlength="200">
              </div>
              <div class="col-md-4">
                <label class="form-label mb-0 text-muted" for="pp-phone_main">代表電話</label>
                <input type="tel" class="form-control form-control-sm" id="pp-phone_main" maxlength="48" autocomplete="tel">
              </div>
              <div class="col-md-4">
                <label class="form-label mb-0 text-muted" for="pp-phone_fax">FAX</label>
                <input type="tel" class="form-control form-control-sm" id="pp-phone_fax" maxlength="48">
              </div>
              <div class="col-md-4">
                <label class="form-label mb-0 text-muted" for="pp-phone_tollfree">無料ダイヤル等</label>
                <input type="tel" class="form-control form-control-sm" id="pp-phone_tollfree" maxlength="48">
              </div>
              <div class="col-12">
                <label class="form-label mb-0 text-muted" for="pp-appeal_points">LPでアピールしたいポイント</label>
                <textarea class="form-control form-control-sm" id="pp-appeal_points" rows="3" maxlength="8000" placeholder="強み・実績・キャンペーンなど"></textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-company_industry">業種（LPソース・確認済みの内容のみ）</label>
                <input type="text" class="form-control form-control-sm" id="pp-company_industry" maxlength="120" placeholder="例 飲食・小売">
              </div>
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-corporate_number">法人番号（13桁・参考）</label>
                <input type="text" class="form-control form-control-sm font-monospace" id="pp-corporate_number" maxlength="13" inputmode="numeric" placeholder="国税庁公表などで確認した番号">
              </div>
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-company_capital">資本金（確認済みの内容のみ）</label>
                <input type="text" class="form-control form-control-sm" id="pp-company_capital" maxlength="120" placeholder="例 1,000万円">
              </div>
              <div class="col-12">
                <label class="form-label mb-0 text-muted" for="pp-company_history">沿革・会社概要（LPソース・確認済みの内容のみ）</label>
                <textarea class="form-control form-control-sm" id="pp-company_history" rows="3" maxlength="8000" placeholder="登記・有報・公式サイトで確認した内容のみ記載してください"></textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-lp_tone">LPのトーン</label>
                <select class="form-select form-select-sm" id="pp-lp_tone">
                  <option value="">（未指定）</option>
                  <option value="polite">丁寧・上品</option>
                  <option value="casual">カジュアル・親しみやすい</option>
                  <option value="professional">ビジネス・信頼感</option>
                  <option value="warm">温かみ・誠実</option>
                  <option value="luxury">高級感・上質</option>
                  <option value="energetic">元気・若々しい</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label mb-0 text-muted" for="pp-brand_color">基調色</label>
                <div class="input-group input-group-sm">
                  <input type="color" class="form-control form-control-color" id="pp-brand_color_picker" value="#0d6efd" title="色を選ぶ">
                  <input type="text" class="form-control font-monospace" id="pp-brand_color" maxlength="32" placeholder="#0d6efd">
                </div>
              </div>
              <div class="col-12">
                <label class="form-label mb-0 text-muted" for="pp-company_url">会社URL</label>
                <input type="url" class="form-control form-control-sm" id="pp-company_url" maxlength="500" placeholder="https://" autocomplete="url">
              </div>
              <div class="col-12">
                <div class="text-muted small mb-1">SNS（URL・公開プロフィール）</div>
                <div class="row g-2">
                  <div class="col-md-6">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text"><i class="bi bi-twitter-x" title="X"></i></span>
                      <input type="url" class="form-control" id="pp-sns_x" maxlength="500" placeholder="X (Twitter)">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text small fw-bold" title="LINE">L</span>
                      <input type="url" class="form-control" id="pp-sns_line" maxlength="500" placeholder="LINE">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text"><i class="bi bi-instagram" title="Instagram"></i></span>
                      <input type="url" class="form-control" id="pp-sns_instagram" maxlength="500" placeholder="Instagram">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text"><i class="bi bi-facebook" title="Facebook"></i></span>
                      <input type="url" class="form-control" id="pp-sns_facebook" maxlength="500" placeholder="Facebook">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text"><i class="bi bi-youtube" title="YouTube"></i></span>
                      <input type="url" class="form-control" id="pp-sns_youtube" maxlength="500" placeholder="YouTube">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="input-group input-group-sm">
                      <span class="input-group-text"><i class="bi bi-tiktok" title="TikTok"></i></span>
                      <input type="url" class="form-control" id="pp-sns_tiktok" maxlength="500" placeholder="TikTok">
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 pt-2 border-top">
              <button type="button" class="btn btn-sm btn-outline-info" id="btnSampleProjectProfile" title="郵便番号・住所のみサンプルを流し込みます（他の項目は変わりません）">
                <i class="bi bi-magic me-1"></i>サンプル住所を流し込み
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSaveProjectProfile">
                <i class="bi bi-floppy me-1"></i>プロフィールを保存
              </button>
            </div>
          </div>
        </div>

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
                  <div class="flex-grow-1">
                    <div class="fw-semibold">ページ構造を解析中…</div>
                    <div class="small text-muted" id="prog_analyze_detail"></div>
                    <div id="prog_analyze_bar_wrap" class="mt-2 d-none" aria-hidden="true">
                      <div class="progress" style="height:8px;" role="progressbar"
                           aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="prog_analyze_bar_outer">
                        <div id="prog_analyze_bar" class="progress-bar progress-bar-striped progress-bar-animated"
                             style="width: 0;"></div>
                      </div>
                      <div class="small text-muted mt-1" id="prog_analyze_pct"></div>
                    </div>
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
              <li>（任意）<strong>サンプル住所を流し込み</strong>で郵便番号・住所だけ一括入力できます</li>
              <li>（任意）企業名の「検索」で公表情報・参考ヒントを取得できます。<strong class="text-danger">LPに載せる前に公式情報で必ず確認</strong>してください</li>
              <li>企業・LPプロフィールを入力・保存（任意）</li>
              <li>コピー元LPのURLを入力して「解析する」</li>
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
            <a href="export.php" class="btn btn-lg btn-success" title="ZIP（index.html・assets・カスタム画像の最小構成）">
              <i class="bi bi-download me-2"></i>ZIP をダウンロード
            </a>
            <a href="export.php?type=html" class="btn btn-lg btn-outline-success">
              <i class="bi bi-filetype-html me-2"></i>HTML のみ
            </a>
            <button class="btn btn-lg btn-outline-secondary" id="btnEditAgain">
              <i class="bi bi-pencil-square me-2"></i>編集に戻る
            </button>
          </div>

          <!-- Asset health summary -->
          <div id="step3DiagSummary" class="text-start mt-2"></div>

          <?php if ($hasOutput): ?>
            <div class="mt-3 text-muted small">
              生成ファイル：<code><?= htmlspecialchars(LpWorkspace::outputRelIndex(), ENT_QUOTES, 'UTF-8') ?></code>
              (<?= number_format(filesize($outputFile)) ?> bytes)
              — v<?= APP_VERSION ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div><!-- /container-fluid -->

<!-- 画像手動置き換え（Step2 から起動） -->
<div class="modal fade" id="imageReplaceModal" tabindex="-1" aria-labelledby="imageReplaceModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fs-6" id="imageReplaceModalLabel">画像の手動置き換え</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="small text-secondary mb-1">オリジナル（置き換え対象）</div>
            <div class="border rounded bg-light p-2 text-center d-flex align-items-center justify-content-center" style="min-height:240px">
              <img id="imageReplaceModalLeft" src="" alt="" class="img-fluid rounded" style="max-height:280px;object-fit:contain" />
            </div>
          </div>
          <div class="col-md-6">
            <div class="small text-secondary mb-1">新しい画像</div>
            <div id="imageReplaceDropzone"
                 class="border border-2 border-dashed rounded bg-white p-2 text-center d-flex flex-column align-items-center justify-content-center"
                 style="min-height:240px;cursor:default">
              <img id="imageReplaceModalRight" src="" alt="" class="img-fluid rounded d-none mb-2" style="max-height:220px;object-fit:contain" />
              <div id="imageReplaceRightPlaceholder" class="text-muted small px-2">
                ドラッグ＆ドロップ、またはファイル選択
              </div>
              <input type="file" id="imageReplaceFile" class="d-none"
                     accept=".jpg,.jpeg,.png,.gif,.webp,.avif,.svg,image/jpeg,image/png,image/gif,image/webp,image/avif,image/svg+xml" />
              <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="imageReplacePickFile">ローカルから選ぶ</button>
            </div>
          </div>
        </div>
        <div class="mt-3 pt-2 border-top">
          <div class="small text-secondary mb-2">ユーザーカスタム画像（このワークスペースにアップロードしたファイル）</div>
          <div id="imageReplaceGallery" class="d-flex flex-wrap gap-2 align-items-start" style="max-height:200px;overflow-y:auto"></div>
          <p id="imageReplaceGalleryEmpty" class="small text-muted mb-0 d-none">ユーザーカスタム画像はまだありません。上のエリアからアップロードしてください。</p>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">キャンセル</button>
        <button type="button" class="btn btn-sm btn-primary" id="imageReplaceApply" disabled>この画像で確定</button>
      </div>
    </div>
  </div>
</div>

<!-- 企業情報検索（公表・AI参考／反映は確認後のみ） -->
<div class="modal fade" id="companyLookupModal" tabindex="-1" aria-labelledby="companyLookupModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fs-6" id="companyLookupModalLabel">企業情報の参照（要確認）</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
      </div>
      <div class="modal-body">
        <p class="small text-danger mb-2" id="companyLookupNotice"></p>
        <p class="small text-muted mb-2" id="companyLookupAttribution"></p>
        <div id="companyLookupLoading" class="d-none text-center py-4 text-muted small">取得中…</div>
        <div id="companyLookupError" class="alert alert-danger d-none small mb-2"></div>
        <div id="companyLookupBody" class="d-none">
          <div class="mb-3" id="companyLookupOfficialWrap">
            <div class="fw-semibold small mb-1">国税庁公表の法人（該当する場合）</div>
            <div class="table-responsive border rounded" style="max-height:220px;overflow-y:auto">
              <table class="table table-sm mb-0 small">
                <thead class="table-light"><tr><th></th><th>法人番号</th><th>商号</th><th>所在地</th></tr></thead>
                <tbody id="companyLookupOfficialTbody"></tbody>
              </table>
            </div>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="companyLookupApplyOfficial" disabled>
              <label class="form-check-label small" for="companyLookupApplyOfficial">選択した法人の法人番号・所在地をプロフィールへ反映する</label>
            </div>
          </div>
          <div class="mb-3" id="companyLookupAiWrap">
            <div class="fw-semibold small mb-1">参考ヒント（AI・要公式確認）</div>
            <ul class="list-group list-group-flush small" id="companyLookupAiList"></ul>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="companyLookupVerified">
            <label class="form-check-label small fw-semibold" for="companyLookupVerified">反映する項目について、公式サイト・登記・国税庁公表等で正しさを確認した</label>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">閉じる</button>
        <button type="button" class="btn btn-sm btn-primary" id="companyLookupApplyBtn" disabled>確認済みとして反映</button>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Pass PHP state to JS -->
<script>
window.LP_CMS = {
  initialStep:  <?= $initialStep ?>,
  hasStructure: <?= $hasStructure ? 'true' : 'false' ?>,
  hasOutput:    <?= $hasOutput    ? 'true' : 'false' ?>,
  sourceUrl:    <?= json_encode($sourceUrl) ?>,
  aiSourceIndustry:       <?= json_encode($sourceIndustry) ?>,
  aiStructureFingerprint: <?= json_encode($aiStructureFingerprint) ?>,
  projectProfileInitial:  <?= json_encode($projectProfileForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
};
</script>

<script src="assets/js/index.js"></script>
</body>
</html>
