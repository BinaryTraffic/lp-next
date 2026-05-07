<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    lp_reverse_session_start();
}

require_once __DIR__ . '/lib/env_load.php';
lp_reverse_load_env();

require_once __DIR__ . '/lib/app_release.php';
require_once __DIR__ . '/lib/LpWorkspace.php';
require_once __DIR__ . '/lib/UserRegistry.php';

define('APP_VERSION', '1.4.0');
define('APP_BUILD', lp_reverse_app_build_label(__DIR__));

$outputWsPrefix = LpWorkspace::outputWebAbsPrefix();
$workspaceName  = '';
if (preg_match('/\b(ws_[a-f0-9]{32})\b/i', $outputWsPrefix, $m) === 1) {
    $workspaceName = (string) $m[1];
}
$cmsRootAuth    = __DIR__;
$userDataDirUx  = LpWorkspace::authRegistryDir($cmsRootAuth);
$registryUx     = new UserRegistry($userDataDirUx);

/** getenv が空でも .env が $_ENV に入っている環境向け（FPM の取り込み差異） */
$envPeekUx = static function (string $k): string {
    $v = getenv($k);
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    $e = $_ENV[$k] ?? null;

    return (is_string($e) && trim($e) !== '') ? trim($e) : '';
};

$googleConfigured = $envPeekUx('GOOGLE_CLIENT_ID') !== ''
    && $envPeekUx('GOOGLE_CLIENT_SECRET') !== ''
    && $envPeekUx('GOOGLE_REDIRECT_URI') !== '';

$authErrorUx = isset($_GET['auth_error']) ? trim((string) $_GET['auth_error']) : '';

if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
    if (!$googleConfigured) {
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>ログイン設定 — Site Reverse CMS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-dark text-white">
<div class="container py-5" style="max-width:620px;">
  <h1 class="h4">Google ログインが未設定です</h1>
  <?php if ($authErrorUx !== ''): ?>
    <div class="alert alert-warning text-dark"><?= htmlspecialchars($authErrorUx, ENT_QUOTES, 'UTF-8') ?></div>
    <p class="text-secondary small">Google でキャンセルした直後でも、サーバーが .env を読めていないとこのページになります。<code>/current/lp_reverse_cms/.env</code> の権限と <code>lib/env_load.php</code> の読み込みを確認してください。</p>
  <?php endif; ?>
  <p class="text-secondary mb-3">lp_reverse_cms/.env に <code>GOOGLE_CLIENT_ID</code>・<code>GOOGLE_CLIENT_SECRET</code>・<code>GOOGLE_REDIRECT_URI</code>
    と <code>CMS_SUPER_ADMIN</code> を設定してください。</p>
  <p class="small text-muted mb-0">GOOGLE_REDIRECT_URI 例：<code>https://lp-next.jitan.app/current/lp_reverse_cms/store/auth_callback.php</code></p>
</div>
</body>
</html>
        <?php
        exit;
    }

    // 認証キャンセル／エラー後は無限ループにならないよう、まずメッセージを表示してからユーザーが「再ログイン」を選ぶ
    if ($authErrorUx !== '') {
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ログイン — Site Reverse CMS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-dark text-white">
<div class="container py-5" style="max-width:540px;">
  <h1 class="h5 mb-3">Google ログイン</h1>
  <div class="alert alert-warning text-dark"><?= htmlspecialchars($authErrorUx, ENT_QUOTES, 'UTF-8') ?></div>
  <p class="text-secondary small mb-4">問題なければ下のボタンから再度 Google に進みます。</p>
  <a class="btn btn-primary" href="index.php">ログインを再試行</a>
  <p class="mt-4 small mb-0"><a class="text-secondary" href="store/auth_logout.php">ログアウト／セッションを消す</a></p>
</div>
</body>
</html>
        <?php
        exit;
    }

    require_once __DIR__ . '/lib/GoogleAuth.php';

    try {
        (new GoogleAuth())->redirectToGoogle();
    } catch (Throwable $e) {
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>ログイン — Site Reverse CMS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-dark text-white">
<div class="container py-5">
  <h1 class="h4">ログインに失敗しました</h1>
  <p class="text-secondary"><?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?></p>
</div>
</body>
</html>
        <?php
        exit;
    }
}

$sessionAuthUx = $_SESSION['auth'];
$sessEmailUx   = strtolower(trim((string) ($sessionAuthUx['email'] ?? '')));

if ($sessEmailUx === '') {
    unset($_SESSION['auth']);
    require_once __DIR__ . '/lib/GoogleAuth.php';

    try {
        (new GoogleAuth())->redirectToGoogle();
    } catch (Throwable) {
        http_response_code(302);
        header('Location: index.php');

        exit;
    }
}

$currentRoleUx = $registryUx->getRole($sessEmailUx);

if ($currentRoleUx === null) {
    unset($_SESSION['auth']);
    require_once __DIR__ . '/lib/GoogleAuth.php';

    try {
        (new GoogleAuth())->redirectToGoogle();
    } catch (Throwable) {
        header('Location: index.php');

        exit;
    }
}

$_SESSION['auth']['role'] = $currentRoleUx;

if ($currentRoleUx === 'pending') {
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>承認待ち — Site Reverse CMS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:560px;">
  <h1 class="h4 mb-3"><i class="bi bi-hourglass-split"></i> 管理者の承認をお待ちください</h1>
  <p class="text-muted mb-4">アカウント <?= htmlspecialchars($sessEmailUx, ENT_QUOTES, 'UTF-8') ?> は申請済みです。承認後に再度このページへアクセスしてください。</p>
  <a href="store/auth_logout.php" class="btn btn-outline-secondary btn-sm">ログアウト</a>
</div>
</body>
</html>
<?php
    exit;
}

if ($currentRoleUx === 'rejected') {
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>アクセス拒否 — Site Reverse CMS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:560px;">
  <h1 class="h4 mb-3 text-danger">アクセスが承認されていません</h1>
  <p class="text-muted mb-4">運営にお問い合わせいただくか、別のアカウントでお試しください。</p>
  <a href="store/auth_logout.php" class="btn btn-outline-secondary btn-sm">ログアウト</a>
</div>
</body>
</html>
<?php
    exit;
}

if ($currentRoleUx === 'preview') {
    header('Location: preview.php');

    exit;
}

$authManageUsers = in_array($currentRoleUx, ['super_admin', 'admin'], true);
$userPendingUx   = $authManageUsers ? $registryUx->getPending() : [];
$userApprovedUx  = $authManageUsers ? $registryUx->getApproved() : [];
$superAdminUx    = (string) getenv('CMS_SUPER_ADMIN');
$superAdminLw    = strtolower(trim($superAdminUx));

$workspaceDataDir = LpWorkspace::dataDir($cmsRootAuth);
$structureFile    = $workspaceDataDir . 'lp_structure.json';
$clientFile       = $workspaceDataDir . 'client_data.json';
$outputFile       = LpWorkspace::outputDir($cmsRootAuth) . 'index.html';
$sourceUrlFile    = $workspaceDataDir . 'source_url.txt';

$hasStructure = file_exists($structureFile);
$hasOutput    = file_exists($outputFile);
$sourceUrl    = file_exists($sourceUrlFile) ? trim((string) file_get_contents($sourceUrlFile)) : '';

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

/** AI テキスト置換: メタ由来の業種・関連候補（analyze 時に industry_suggest.json へ保存） */
$sourceIndustry = '';
$suggestions    = [];
$industrySuggestPath = $workspaceDataDir . 'industry_suggest.json';
if ($hasStructure && is_readable($industrySuggestPath)) {
    $indRaw = json_decode((string) file_get_contents($industrySuggestPath), true);
    if (is_array($indRaw)) {
        $sourceIndustry = trim((string) ($indRaw['source_industry'] ?? ''));
        $sugRaw           = $indRaw['suggestions'] ?? [];
        if (is_array($sugRaw)) {
            foreach ($sugRaw as $s) {
                $t = trim((string) $s);
                if ($t !== '') {
                    $suggestions[] = $t;
                }
            }
        }
    }
}
// 旧ワークスペースで industry_suggest.json が無い場合のみサーバで補完（以降はファイルを使用）
if ($hasStructure && !is_file($industrySuggestPath)) {
    require_once __DIR__ . '/lib/suggest_industries.php';
    $computed = lp_reverse_suggest_industries_from_structure($structure);
    $sourceIndustry = trim((string) ($computed['source_industry'] ?? ''));
    $sugNew           = $computed['suggestions'] ?? [];
    $suggestions      = [];
    if (is_array($sugNew)) {
        foreach ($sugNew as $s) {
            $t = trim((string) $s);
            if ($t !== '') {
                $suggestions[] = $t;
            }
        }
    }
    file_put_contents(
        $industrySuggestPath,
        json_encode(
            ['source_industry' => $sourceIndustry, 'suggestions' => $suggestions],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ),
        LOCK_EX,
    );
}

// Determine which step to show on initial load (1 = fetch, 2 = edit, 3 = done)
// ?step=1–3 があれば最優先（OAuth 復帰で Step1 に固定したい場合など）。
$stepQs = isset($_GET['step']) ? (int) $_GET['step'] : 0;
if ($stepQs >= 1 && $stepQs <= 3) {
    $initialStep = $stepQs;
} else {
    $initialStep = $hasOutput ? 3 : ($hasStructure ? 2 : 1);
}

/** ワークスペース状態に応じて到達した最遠ステップ（ステップアイコン遷移の上限） */
$maxReachableStep = $hasOutput ? 3 : ($hasStructure ? 2 : 1);

?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Site Reverse CMS</title>

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <!-- Custom admin styles -->
  <link rel="stylesheet" href="assets/css/index.css?v=<?= rawurlencode(APP_BUILD) ?>">
</head>
<body class="bg-light">

<!-- ===== NAVBAR ===== -->
<nav class="navbar navbar-dark bg-primary shadow-sm">
  <div class="container-fluid">
      <span class="navbar-brand fw-bold">
      <i class="bi bi-arrow-repeat me-2"></i>Site Reverse CMS
      <span class="badge bg-white text-primary ms-2 fw-normal" style="font-size:.65rem;vertical-align:middle"
            title="バージョン / ビルド（Git 短ハッシュまたはソース更新日）">
        v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(APP_BUILD, ENT_QUOTES, 'UTF-8') ?>
      </span>
    </span>
    <div class="d-flex align-items-center gap-2">
      <span class="text-white-50 small d-none d-xl-inline text-truncate" style="max-width:200px;"
            title="<?= htmlspecialchars($sessEmailUx, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($sessEmailUx, ENT_QUOTES, 'UTF-8') ?>
      </span>
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-light" type="button" id="navMenuDropdown"
                data-bs-toggle="dropdown" aria-expanded="false" title="メニュー">
          <i class="bi bi-list"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navMenuDropdown">
          <?php if ($authManageUsers): ?>
          <li>
            <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#userMgmtModal">
              <i class="bi bi-people-fill me-2"></i>ユーザー管理
            </button>
          </li>
          <li><hr class="dropdown-divider"></li>
          <?php endif; ?>
          <li><a class="dropdown-item" href="store/auth_logout.php"><i class="bi bi-box-arrow-right me-2"></i>ログアウト</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<?php if ($authErrorUx !== ''): ?>
<div class="container-fluid py-2 px-3 mx-auto" style="max-width:1200px">
  <div class="alert alert-warning alert-dismissible fade show mb-0" role="alert">
    <?= htmlspecialchars($authErrorUx, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
  </div>
</div>
<?php endif; ?>

<?php if ($authManageUsers): ?>
<!-- ===== USER MANAGEMENT MODAL ===== -->
<div class="modal fade" id="userMgmtModal" tabindex="-1" aria-labelledby="userMgmtModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2 bg-dark text-white">
        <h5 class="modal-title fs-6" id="userMgmtModalLabel"><i class="bi bi-people-fill me-2"></i>ユーザー承認・管理</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card border-secondary mb-4">
          <div class="card-header py-2 bg-light small fw-semibold">
            <i class="bi bi-person-plus me-1"></i>アカウントを追加（事前登録）
          </div>
          <div class="card-body py-3">
            <p class="small text-muted mb-3 mb-md-2">
              Google で初回ログインする前にメールで登録できます。承認済みならログイン後すぐ利用、「承認待ち」なら一覧から承認します。
            </p>
            <div class="row g-2 align-items-end flex-wrap">
              <div class="col-12 col-md-4">
                <label class="form-label small mb-0 text-secondary" for="umAddEmail">メール<span class="text-danger">*</span></label>
                <input type="email" id="umAddEmail" class="form-control form-control-sm" placeholder="user@example.com" autocomplete="off">
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small mb-0 text-secondary" for="umAddName">表示名（任意）</label>
                <input type="text" id="umAddName" class="form-control form-control-sm" placeholder="名前" autocomplete="off">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0 text-secondary" for="umAddStatus">状態</label>
                <select id="umAddStatus" class="form-select form-select-sm">
                  <option value="approved" selected>承認済み</option>
                  <option value="pending">承認待ち</option>
                </select>
              </div>
              <div class="col-6 col-md-2" id="umAddRoleWrap">
                <label class="form-label small mb-0 text-secondary" for="umAddRole">ロール</label>
                <select id="umAddRole" class="form-select form-select-sm">
                  <option value="preview" selected>preview</option>
                  <?php if ($currentRoleUx === 'super_admin'): ?>
                  <option value="admin">admin</option>
                  <?php endif; ?>
                </select>
              </div>
              <div class="col-12 col-md-auto">
                <button type="button" class="btn btn-sm btn-primary btn-um" id="btnUmAdd" data-um="manual-add">
                  <i class="bi bi-plus-lg me-1"></i>追加
                </button>
              </div>
            </div>
          </div>
        </div>

        <h6 class="text-secondary small text-uppercase">承認待ち</h6>
        <div class="table-responsive mb-4 border rounded">
          <table class="table table-sm table-striped mb-0 align-middle">
            <thead><tr><th>メール</th><th>名前</th><th>申請日時</th><th style="width:280px"></th></tr></thead>
            <tbody>
            <?php if ($userPendingUx === []): ?>
              <tr><td colspan="4" class="text-muted small">該当なし</td></tr>
            <?php endif; ?>
            <?php foreach ($userPendingUx as $pu): ?>
              <?php
                $pem = strtolower((string) ($pu['email'] ?? ''));
                if ($pem === '') {
                    continue;
                }
                $pnm = (string) ($pu['name'] ?? '');
              ?>
              <tr>
                <td><code><?= htmlspecialchars($pem, ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($pnm, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="small text-muted"><?= htmlspecialchars((string) ($pu['requested_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm flex-wrap gap-1">
                    <button type="button" class="btn btn-outline-success btn-sm btn-um" data-um="pending-approve" data-email="<?= htmlspecialchars($pem, ENT_QUOTES, 'UTF-8') ?>" data-role="preview">preview で承認</button>
                    <?php if ($currentRoleUx === 'super_admin'): ?>
                      <button type="button" class="btn btn-outline-primary btn-sm btn-um" data-um="pending-approve" data-email="<?= htmlspecialchars($pem, ENT_QUOTES, 'UTF-8') ?>" data-role="admin">admin で承認</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-danger btn-sm btn-um" data-um="pending-reject" data-email="<?= htmlspecialchars($pem, ENT_QUOTES, 'UTF-8') ?>">拒否</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <h6 class="text-secondary small text-uppercase">承認済み</h6>
        <div class="table-responsive border rounded">
          <table class="table table-sm table-striped mb-0 align-middle">
            <thead><tr><th>メール</th><th>名前</th><th>ロール</th><th style="width:320px"></th></tr></thead>
            <tbody>
            <?php if ($userApprovedUx === []): ?>
              <tr><td colspan="4" class="text-muted small">該当なし</td></tr>
            <?php endif; ?>
            <?php foreach ($userApprovedUx as $au): ?>
              <?php
                $aem = strtolower((string) ($au['email'] ?? ''));
                $arl = strtolower((string) ($au['role'] ?? 'preview'));
                $anm = (string) ($au['name'] ?? '');
              ?>
              <tr data-approved="<?= htmlspecialchars($aem, ENT_QUOTES, 'UTF-8') ?>">
                <td><code><?= htmlspecialchars($aem, ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($anm, ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php
                    $roleSelDisabled = $currentRoleUx !== 'super_admin' && $arl === 'admin';
                  ?>
                  <select class="form-select form-select-sm role-sel" style="max-width:120px"
                    <?= $roleSelDisabled ? 'disabled' : '' ?>>
                    <option value="preview" <?= $arl === 'preview' ? 'selected' : '' ?>>preview</option>
                    <option value="admin" <?= $arl === 'admin' ? 'selected' : '' ?> <?= $currentRoleUx !== 'super_admin' ? 'disabled' : '' ?>>admin</option>
                  </select>
                </td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-outline-primary btn-um me-1"
                          data-um="approved-role" data-email="<?= htmlspecialchars($aem, ENT_QUOTES, 'UTF-8') ?>"
                          <?= $roleSelDisabled ? 'disabled' : '' ?>>
                    ロールを保存
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-danger btn-um"
                          data-um="approved-remove" data-email="<?= htmlspecialchars($aem, ENT_QUOTES, 'UTF-8') ?>">
                    削除
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($superAdminLw !== ''): ?>
          <p class="small text-muted mt-3 mb-0">
            Super admin（.env）は <code><?= htmlspecialchars((string) $superAdminUx, ENT_QUOTES, 'UTF-8') ?></code> です。この一覧には含まれません。
          </p>
        <?php endif; ?>
      </div>
      <div class="modal-footer py-2 bg-light">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">閉じる</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- HTTP 取得失敗ログ（コピー用テキスト） -->
<div class="modal fade" id="fetchFailureModal" tabindex="-1" aria-labelledby="fetchFailureModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fs-6" id="fetchFailureModalLabel">
          <i class="bi bi-link-45deg me-1"></i>HTTP 取得に失敗した URL
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
      </div>
      <div class="modal-body py-2">
        <p class="small text-muted mb-2">1行1URL。テキストをそのまま選択してコピーできます。</p>
        <label class="form-label small mb-1" for="fetchFailureLogBody">ログ</label>
        <textarea id="fetchFailureLogBody" class="form-control font-monospace small" rows="14" readonly
                  style="white-space:pre;overflow-wrap:normal;overflow-x:auto"></textarea>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">閉じる</button>
      </div>
    </div>
  </div>
</div>

<!-- 保存＆サイト生成 — 進捗モーダル -->
<div class="modal fade" id="saveGenerateModal" tabindex="-1" aria-labelledby="saveGenerateModalLabel" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2 border-bottom-0">
        <h5 class="modal-title fs-6" id="saveGenerateModalLabel">
          <i class="bi bi-lightning-charge-fill text-primary me-1"></i>保存＆サイト生成
        </h5>
      </div>
      <div class="modal-body pt-0 pb-2">
        <ul class="list-unstyled mb-0 small" id="saveGenSteps">
          <li id="saveGenRowSave" class="d-flex align-items-center gap-2 mb-3">
            <span class="save-gen-status flex-shrink-0" style="width:1.25rem;text-align:center"><i class="bi bi-circle text-muted"></i></span>
            <span>編集内容をサーバーに保存しています…</span>
          </li>
          <li id="saveGenRowGen" class="d-flex align-items-center gap-2 mb-0">
            <span class="save-gen-status flex-shrink-0" style="width:1.25rem;text-align:center"><i class="bi bi-circle text-muted"></i></span>
            <span>output/index.html を生成しています…</span>
          </li>
        </ul>
        <div id="saveGenModalErr" class="alert alert-danger d-none mt-3 mb-0 py-2 small" role="alert"></div>
      </div>
      <div class="modal-footer py-2 border-top-0 justify-content-between" id="saveGenFooterBusy">
        <span class="small text-muted mb-0">完了までこの画面を閉じないでください。</span>
      </div>
      <div class="modal-footer py-2 border-top-0 d-none" id="saveGenFooterDone">
        <button type="button" class="btn btn-primary btn-sm" id="btnSaveGenModalDismiss" data-bs-dismiss="modal">閉じる</button>
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
      <div class="step-item <?= $cls ?>" data-step="<?= $n ?>" data-step-label="<?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?>">
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
            <i class="bi bi-globe me-2"></i><strong>参照サイトのURL入力</strong>
          </div>
          <div class="card-body p-4">
            <p class="text-muted mb-3">
              コピーしたいサイトのURLを入力してください。<br>
              汎用サイト解析で HTML を取得し、編集可能なコンテンツ要素を自動抽出します。
            </p>
            <div class="input-group input-group-lg mb-3">
              <span class="input-group-text bg-white"><i class="bi bi-link-45deg"></i></span>
              <input type="url" id="lpUrlInput" class="form-control"
                     placeholder="https://example.com/lp"
                     value="<?= htmlspecialchars($sourceUrl, ENT_QUOTES) ?>">
              <span class="lp-reverse-tooltip-outline d-inline-block" tabindex="0" role="presentation"
                data-bs-toggle="tooltip"
                data-bs-placement="bottom"
                data-bs-custom-class="lp-cms-tooltip"
                title="<?= htmlspecialchars('入力した URL の HTML と CSS／画像・アセットを取得します。終わったら自動でサイト構造の解析へ進みます。', ENT_QUOTES, 'UTF-8') ?>">
                <button id="btnFetchAnalyze" type="button" class="btn btn-primary px-4 d-inline-flex align-items-center">
                  <i class="bi bi-search"></i><span class="ms-1">解析する</span>
                  <i class="bi bi-info-circle-fill lp-cms-btn-icon ms-1" aria-hidden="true"></i>
                </button>
              </span>
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
                    <div class="fw-semibold">サイト構造を解析中…</div>
                    <div class="small text-muted" id="prog_analyze_detail"></div>
                    <div id="prog_analyze_bar_wrap" class="mt-2 d-none">
                      <div class="progress" style="height:8px;" role="progressbar"
                           aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="prog_analyze_bar_outer">
                        <div id="prog_analyze_bar" class="progress-bar progress-bar-striped progress-bar-animated"
                             style="width: 0;"></div>
                      </div>
                      <div class="small fw-semibold text-body-secondary mt-1" id="prog_analyze_pct">0%/100%</div>
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
              <li>コピー元サイトのURLを入力して「解析する」をクリック</li>
              <li>抽出された各テキスト・画像を自社情報に書き換え</li>
              <li>「保存＆サイト生成」で HTML を出力 → Step 3 でプレビュー・ダウンロード</li>
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
      <span class="lp-reverse-tooltip-outline d-inline-block" tabindex="0" role="presentation"
        data-bs-toggle="tooltip"
        data-bs-placement="bottom"
        data-bs-custom-class="lp-cms-tooltip"
        title="<?= htmlspecialchars('Step1 に戻り、別の URL で HTML を取り直します（現在ワークスペースの取得データは置き換わります）。', ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" id="btnBackToStep1">
          <i class="bi bi-arrow-left"></i><span class="ms-1">別のURLを解析</span>
          <i class="bi bi-info-circle-fill lp-cms-btn-icon ms-1" aria-hidden="true"></i>
        </button>
      </span>
      <div class="d-flex gap-2 align-items-center flex-wrap">
        <span class="lp-reverse-tooltip-outline d-inline-block" tabindex="0" role="presentation"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          data-bs-custom-class="lp-cms-tooltip"
          title="<?= htmlspecialchars('画面上の入力欄を空にします。サーバー側の解析結果や保存済み client_data は自動では元に戻りません。', ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" id="btnResetClient">
            <i class="bi bi-arrow-counterclockwise"></i><span class="ms-1">リセット</span>
            <i class="bi bi-info-circle-fill lp-cms-btn-icon ms-1" aria-hidden="true"></i>
          </button>
        </span>
        <span class="lp-reverse-tooltip-outline d-inline-block" tabindex="0" role="presentation"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          data-bs-custom-class="lp-cms-tooltip"
          title="<?= htmlspecialchars(
              '外部編集用。assets/img/… と、このクローンの sites/<clone>/custom_images/… を、ワークスペースと同じ相対パスで Deflate ZIP にまとめます。clone_images_manifest.json にファイル一覧が入ります。',
              ENT_QUOTES,
              'UTF-8',
          ) ?>">
          <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" id="btnCloneImagesZipDl"
            <?= !$hasStructure ? 'disabled' : '' ?>>
            <i class="bi bi-file-earmark-zip"></i><span class="ms-1">画像ZIP</span>
            <i class="bi bi-info-circle-fill lp-cms-btn-icon ms-1" aria-hidden="true"></i>
          </button>
        </span>
        <span class="lp-reverse-tooltip-outline d-inline-block" tabindex="0" role="presentation"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          data-bs-custom-class="lp-cms-tooltip"
          title="<?= htmlspecialchars(
              '編集後の ZIP で上書き。エクスポートと同じフォルダ構成にするか、同名画像が1つだけのときは ZIP 直下のファイル名のみでも可。終わったら（必要なら）右の「保存＆サイト生成」で HTML に反映してください。',
              ENT_QUOTES,
              'UTF-8',
          ) ?>">
          <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" id="btnCloneImagesZipUpload"
            <?= !$hasStructure ? 'disabled' : '' ?>>
            <i class="bi bi-upload"></i><span class="ms-1">ZIP反映</span>
            <i class="bi bi-info-circle-fill lp-cms-btn-icon ms-1" aria-hidden="true"></i>
          </button>
        </span>
        <input type="file" id="cloneImagesZipUploadInp" accept=".zip,application/zip" class="d-none">
        <span class="lp-reverse-tooltip-outline d-inline-block" tabindex="0" role="presentation"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          data-bs-custom-class="lp-cms-tooltip"
          title="<?= htmlspecialchars(
              '入力を保存し output/index.html を生成。画像 ZIP を差し替えたあと、変更を HTML に反映する場合も実行してください。',
              ENT_QUOTES,
              'UTF-8',
          ) ?>">
          <button type="button" class="btn btn-primary px-4 d-inline-flex align-items-center" id="btnSaveGenerate">
            <i class="bi bi-lightning-charge-fill"></i><span class="ms-1">保存＆サイト生成</span>
            <i class="bi bi-info-circle-fill lp-cms-btn-icon ms-1 ps-1" aria-hidden="true"></i>
          </button>
        </span>
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
          <h3 class="fw-bold mb-2">サイト生成が完了しました！</h3>
          <p class="text-muted mb-4">
            参照サイトの構成を保ったまま、新しいサイトが生成されました。
          </p>
          <?php if ($workspaceName !== ''): ?>
            <div class="alert alert-light border text-start mx-auto mb-4" style="max-width:560px;">
              <label class="form-label small text-muted mb-1" for="workspaceNameField">ワークスペース名</label>
              <div class="input-group">
                <input type="text" id="workspaceNameField" class="form-control font-monospace" readonly
                       value="<?= htmlspecialchars($workspaceName, ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" class="btn btn-outline-secondary" id="btnCopyWorkspaceName">
                  <i class="bi bi-clipboard me-1"></i>コピー
                </button>
              </div>
            </div>
          <?php endif; ?>
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
            <div class="text-center text-muted small">
              <div class="spinner-border spinner-border-sm text-secondary me-1" role="status" aria-hidden="true"></div>
              アセット状況を確認中（`store/debug.php` を取得しています）…
            </div>
          </div>

          <?php if ($hasOutput): ?>
            <div class="mt-3 text-muted small">
              生成ファイル：<code>output/index.html</code>
              (<?= number_format(filesize($outputFile)) ?> bytes)
              — v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(APP_BUILD, ENT_QUOTES, 'UTF-8') ?>
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
            <div id="imageReplaceDimsLeft" class="small text-body mb-2">サイズ：—</div>
            <div class="border rounded bg-light p-2 text-center d-flex align-items-center justify-content-center" style="min-height:240px">
              <img id="imageReplaceModalLeft" src="" alt="" class="img-fluid rounded" style="max-height:280px;object-fit:contain" />
            </div>
          </div>
          <div class="col-md-6">
            <div class="small text-secondary mb-1">新しい画像</div>
            <div id="imageReplaceDimsRight" class="small text-body mb-2">サイズ：—</div>
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
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">キャンセル</button>
        <button type="button" class="btn btn-sm btn-primary" id="imageReplaceApply" disabled>この画像で確定</button>
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
  maxReachableStep: <?= (int) $maxReachableStep ?>,
  hasStructure: <?= $hasStructure ? 'true' : 'false' ?>,
  hasOutput:    <?= $hasOutput    ? 'true' : 'false' ?>,
  sourceUrl:    <?= json_encode($sourceUrl, JSON_THROW_ON_ERROR) ?>,
  outputWsPrefix: <?= json_encode($outputWsPrefix, JSON_THROW_ON_ERROR) ?>,
};
</script>

<?php if ($authManageUsers): ?>
<script>
(function () {
  async function pj(u, payload) {
    try {
      const r = await fetch(u, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
        body: JSON.stringify(payload),
      });
      const txt = await r.text();
      let data = {};

      try {
        data = txt ? JSON.parse(txt) : {};
      } catch {
        window.alert('サーバ応答が JSON として解釈できません');

        return;
      }

      if (!r.ok) {
        window.alert((data && data.error) ? String(data.error) : ('HTTP ' + r.status));

        return;
      }

      if (data.ok !== true) {
        window.alert((data && data.error) ? String(data.error) : '操作に失敗しました');

        return;
      }
      window.location.reload();
    } catch {
      window.alert('通信に失敗しました');
    }
  }

  document.body.addEventListener('click', function (ev) {
    const btn = ev.target && ev.target.closest ? ev.target.closest('.btn-um') : null;
    if (!btn || (btn.disabled)) return;
    const um = btn.getAttribute('data-um');
    const email = btn.getAttribute('data-email');
    const role = btn.getAttribute('data-role');
    const tr = btn.closest ? btn.closest('tr') : null;
    const sel = tr ? tr.querySelector('.role-sel') : null;

    if (um === 'manual-add') {
      const emEl = document.getElementById('umAddEmail');
      const nmEl = document.getElementById('umAddName');
      const stEl = document.getElementById('umAddStatus');
      const rlEl = document.getElementById('umAddRole');
      const em = emEl ? String(emEl.value || '').trim() : '';
      if (!em) {
        window.alert('メールアドレスを入力してください');

        return;
      }
      const payload = {
        action: 'add_user',
        email: em,
        name: nmEl ? String(nmEl.value || '').trim() : '',
        status: stEl ? (stEl.value || 'approved') : 'approved',
        role: rlEl ? (rlEl.value || 'preview') : 'preview',
      };

      void pj('store/user_manage.php', payload);

      return;
    }

    if (um === 'pending-approve') {
      void pj('store/user_approve.php', { action: 'approve', email: email, role: role || 'preview' });
    } else if (um === 'pending-reject') {
      void pj('store/user_approve.php', { action: 'reject', email: email });
    } else if (um === 'approved-remove') {
      if (!window.confirm('このユーザーを auth_users.json から削除します。よろしいですか？')) return;
      void pj('store/user_manage.php', { action: 'remove', email: email });
    } else if (um === 'approved-role') {
      if (!sel || sel.disabled) return;
      const nr = sel.value || 'preview';
      void pj('store/user_manage.php', { action: 'change_role', email: email, role: nr });
    }
  });

  (function syncUmAddForm() {
    const umSt = document.getElementById('umAddStatus');
    const umRw = document.getElementById('umAddRoleWrap');
    const umRl = document.getElementById('umAddRole');
    function sync() {
      if (!umSt || !umRw || !umRl) return;
      const pen = umSt.value === 'pending';
      umRw.hidden = pen;
      umRl.disabled = pen;
    }
    if (umSt) umSt.addEventListener('change', sync);
    sync();
  })();
})();
</script>
<?php endif; ?>

<script src="assets/js/index.js?v=<?= rawurlencode(APP_BUILD) ?>"></script>
</body>
</html>
