<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    lp_reverse_session_start();
}
require_once __DIR__ . '/lib/env_load.php';
lp_reverse_load_env();
require_once __DIR__ . '/lib/LpWorkspace.php';
require_once __DIR__ . '/lib/UserRegistry.php';

$cmsRoot = __DIR__;

// ── 認証 ──────────────────────────────────────────────
if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
    require_once __DIR__ . '/lib/GoogleAuth.php';
    try { (new GoogleAuth())->redirectToGoogle(); } catch (Throwable) {}
    http_response_code(302);
    header('Location: index.php');
    exit;
}
$email = strtolower(trim((string) ($_SESSION['auth']['email'] ?? '')));
$name  = (string) ($_SESSION['auth']['name']  ?? $email);
if ($email === '') {
    header('Location: index.php');
    exit;
}
$userDataDir = LpWorkspace::authRegistryDir($cmsRoot);
$registry    = new UserRegistry($userDataDir);
$role        = $registry->getRole($email);
if ($role === null || $role === 'pending') {
    header('Location: index.php');
    exit;
}

// ── ワークスペース特定 ────────────────────────────────
$wsParam = trim((string) ($_GET['ws'] ?? ''));

// ws_HASH 形式または HASH 形式（32文字 hex）どちらも受け付ける
if (str_starts_with($wsParam, 'ws_')) {
    $wsHash = substr($wsParam, 3);
    $wsDirName = $wsParam;
} else {
    $wsHash = $wsParam;
    $wsDirName = 'ws_' . $wsParam;
}

if ($wsHash === '' || !LpWorkspace::isValidId($wsHash)) {
    // パラメータ未指定時はセッションの現在 WS を使用
    $wsDirName = 'ws_' . LpWorkspace::id();
    $wsHash    = LpWorkspace::id();
}

$dataDir = $cmsRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $wsDirName . DIRECTORY_SEPARATOR;

// ── データ読み込み ──────────────────────────────────────
$structPath = $dataDir . 'lp_structure.json';
if (!is_readable($structPath)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="ja"><body><p>解析データが見つかりません（' . htmlspecialchars($wsDirName, ENT_QUOTES) . '）。先に解析を実行してください。</p><p><a href="index.php">← 戻る</a></p></body></html>';
    exit;
}
$structure  = json_decode((string) file_get_contents($structPath), true);
if (!is_array($structure)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="ja"><body><p>構造データの読み込みに失敗しました。</p></body></html>';
    exit;
}

$clientDataPath = $dataDir . 'client_data.json';
$clientData = [];
if (is_readable($clientDataPath)) {
    $cd = json_decode((string) file_get_contents($clientDataPath), true);
    if (is_array($cd)) {
        $clientData = $cd;
    }
}
$clientElems = is_array($clientData['elements'] ?? null) ? $clientData['elements'] : [];

// ── メタ情報 ──────────────────────────────────────────
$siteUrl    = (string) ($structure['source_url']  ?? '');
$pageTitle  = (string) ($structure['meta']['title'] ?? '');
$analyzedAt = (string) ($structure['analyzed_at']  ?? '');
$sections   = is_array($structure['sections'] ?? null) ? $structure['sections'] : [];

// ── ヘルパー関数 ─────────────────────────────────────

/** 画像 src/URL から表示用ファイル名を得る */
function cl_basename(string $url): string {
    $p = parse_url($url, PHP_URL_PATH);
    return $p !== false && $p !== null ? basename((string) $p) : basename($url);
}

/** 置き換え状態を解析して配列で返す */
function cl_override_status(string $elemId, array $clientElems): array {
    $ov = $clientElems[$elemId] ?? null;
    if (!is_array($ov) || empty($ov['src'])) {
        return ['status' => 'unset', 'src' => ''];
    }
    $src = (string) $ov['src'];
    if (str_contains($src, 'placeholder_')) {
        return ['status' => 'placeholder', 'src' => $src];
    }
    return ['status' => 'replaced', 'src' => $src];
}

$statusLabel = [
    'unset'       => ['text' => '未設定',           'cls' => 'badge bg-secondary'],
    'placeholder' => ['text' => 'プレースホルダー', 'cls' => 'badge bg-warning text-dark'],
    'replaced'    => ['text' => '差し替え済',        'cls' => 'badge bg-success'],
];

// ── 行データ収集 ─────────────────────────────────────
$rows = [];
foreach ($sections as $secIdx => $section) {
    $secId    = (string) ($section['id']    ?? ('sec_' . $secIdx));
    $secLabel = (string) ($section['label'] ?? ('セクション ' . ($secIdx + 1)));
    $elements = is_array($section['elements'] ?? null) ? $section['elements'] : [];

    foreach ($elements as $elem) {
        $type = (string) ($elem['type'] ?? '');
        if (!in_array($type, ['image', 'background_image'], true)) {
            continue;
        }
        $elemId  = (string) ($elem['id'] ?? '');
        $origSrc = (string) ($elem['original_src'] ?? '');
        $w = isset($elem['original_width'])  ? (int) $elem['original_width']  : 0;
        $h = isset($elem['original_height']) ? (int) $elem['original_height'] : 0;
        $ov = cl_override_status($elemId, $clientElems);
        $rows[] = [
            'sec_label' => $secLabel,
            'sec_id'    => $secId,
            'elem_id'   => $elemId,
            'type'      => $type === 'background_image' ? 'bg' : 'img',
            'filename'  => $origSrc !== '' ? cl_basename($origSrc) : '—',
            'orig_src'  => $origSrc,
            'width'     => $w,
            'height'    => $h,
            'status'    => $ov['status'],
            'cur_src'   => $ov['src'],
        ];
    }

    // CSS 背景ヒント
    foreach (($section['css_background_hints'] ?? []) as $bgIdx => $hint) {
        $synId   = 'css_bg_' . $secId . '_' . $bgIdx;
        $url     = (string) ($hint['url']   ?? '');
        $token   = (string) ($hint['token'] ?? '');
        if ($url === '' || $token === '(inline style)') {
            continue;
        }
        $ov = cl_override_status($synId, $clientElems);
        $rows[] = [
            'sec_label' => $secLabel,
            'sec_id'    => $secId,
            'elem_id'   => $synId,
            'type'      => 'css',
            'filename'  => cl_basename($url),
            'orig_src'  => $url,
            'width'     => 0,
            'height'    => 0,
            'status'    => $ov['status'],
            'cur_src'   => $ov['src'],
            'css_token' => $token,
        ];
    }
}

$totalRows     = count($rows);
$unsetCount    = count(array_filter($rows, fn($r) => $r['status'] === 'unset'));
$phCount       = count(array_filter($rows, fn($r) => $r['status'] === 'placeholder'));
$replacedCount = count(array_filter($rows, fn($r) => $r['status'] === 'replaced'));

// ── トップページ代表画像（ヘッダーサムネイル用）───────────────
$heroImageUrl = '';
// 1. source.html 先頭から og:image を探す
$sourceHtmlPath = $dataDir . 'source.html';
if (is_readable($sourceHtmlPath)) {
    $htmlSnip = (string) file_get_contents($sourceHtmlPath, false, null, 0, 16384);
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](https?:\/\/[^"\']+)["\']/i', $htmlSnip, $m)
     || preg_match('/<meta[^>]+content=["\'](https?:\/\/[^"\']+)["\'][^>]+property=["\']og:image["\']/i', $htmlSnip, $m)) {
        $heroImageUrl = $m[1];
    }
}
// 2. フォールバック: 先頭3セクション内の最大画像
if ($heroImageUrl === '') {
    $bestArea = 0;
    foreach (array_slice($sections, 0, 3) as $sec) {
        foreach (($sec['elements'] ?? []) as $elem) {
            if (!in_array($elem['type'] ?? '', ['image', 'background_image'], true)) { continue; }
            $src = (string) ($elem['original_src'] ?? '');
            $ew  = (int) ($elem['original_width']  ?? 0);
            $eh  = (int) ($elem['original_height'] ?? 0);
            if ($src !== '' && $ew > 200 && $ew * $eh > $bestArea) {
                $bestArea = $ew * $eh;
                $heroImageUrl = $src;
            }
        }
    }
}

// この指示書自体の URL（QR コード用）
$scheme       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host         = $_SERVER['HTTP_HOST'] ?? 'localhost';
$selfPath     = strtok($_SERVER['REQUEST_URI'] ?? '/image_checklist.php', '?');
$checklistUrl = $scheme . '://' . $host . $selfPath . '?ws=' . rawurlencode($wsDirName);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>画像作業指示書 — <?= htmlspecialchars($pageTitle ?: $wsDirName, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
  <style>
    body { font-size: .85rem; }
    .type-badge-img { background:#0d6efd }
    .type-badge-bg  { background:#6f42c1 }
    .type-badge-css { background:#198754 }
    .orig-url { font-size:.72rem; word-break:break-all; color:#6c757d; }
    .cur-val  { font-size:.72rem; word-break:break-all; color:#0d6efd; }
    thead th  { position:sticky; top:0; background:#212529; color:#fff; z-index:1; }
    #qr-box canvas, #qr-box img { display:block; }

    /* サムネイル */
    .thumb-wrap { position:relative; display:inline-block; line-height:0; }
    .thumb-orig {
      max-width:80px; max-height:60px; object-fit:cover;
      border-radius:4px; border:1px solid #dee2e6;
      background:#f8f9fa;
    }
    .thumb-replaced {
      position:absolute; bottom:-5px; right:-5px;
      max-width:36px; max-height:28px; object-fit:cover;
      border-radius:3px; border:2px solid #198754;
      box-shadow:0 1px 4px rgba(0,0,0,.35);
    }
    .thumb-placeholder {
      width:80px; height:52px; background:#2d3134;
      border-radius:4px; border:1px solid #444;
      display:flex; align-items:center; justify-content:center;
      color:#6c757d; font-size:.62rem; text-align:center;
      line-height:1.3;
    }
    .thumb-none {
      width:80px; height:52px; background:#f0f0f0;
      border-radius:4px; border:1px dashed #ccc;
      display:flex; align-items:center; justify-content:center;
      color:#bbb;
    }

    @media print {
      .no-print { display:none !important; }
      thead th  { position:static; background:#212529 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      body { font-size:.75rem; }
      .badge { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      #qr-box canvas, #qr-box img { width:96px !important; height:96px !important; }
      .thumb-orig { max-width:56px; max-height:40px; }
      .thumb-replaced { max-width:24px; max-height:18px; }
    }
  </style>
</head>
<body class="bg-light" style="padding-top:52px">

<!-- ナビバー -->
<nav class="navbar navbar-dark bg-primary shadow-sm no-print py-1 fixed-top">
  <div class="container-fluid">
    <span class="navbar-brand fw-bold fs-6 d-flex align-items-center gap-2">
      <i class="bi bi-list-check"></i>
      <span>画像作業指示書</span>
      <?php if ($pageTitle !== ''): ?>
        <span class="d-none d-sm-inline text-white-50 fw-normal" style="font-size:.8rem">|</span>
        <span class="d-none d-sm-inline fw-normal" style="font-size:.82rem; opacity:.9; max-width:400px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
              title="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
        </span>
      <?php elseif ($siteUrl !== ''): ?>
        <span class="d-none d-sm-inline text-white-50 fw-normal" style="font-size:.8rem">|</span>
        <span class="d-none d-sm-inline fw-normal" style="font-size:.78rem; opacity:.8;">
          <?= htmlspecialchars(parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl, ENT_QUOTES, 'UTF-8') ?>
        </span>
      <?php endif; ?>
    </span>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-light" onclick="window.print()">
        <i class="bi bi-printer"></i> 印刷
      </button>
      <a class="btn btn-sm btn-outline-light" href="index.php">
        <i class="bi bi-arrow-left"></i> 編集画面へ
      </a>
    </div>
  </div>
</nav>

<div class="container-fluid py-3" style="max-width:1300px">

  <!-- サマリーヘッダー -->
  <div class="card mb-3 shadow-sm">
    <div class="card-body py-2 px-3">
      <div class="row g-2 align-items-stretch">

        <!-- トップページサムネイル -->
        <?php if ($heroImageUrl !== ''): ?>
        <div class="col-auto d-flex align-items-center">
          <a href="<?= htmlspecialchars($siteUrl ?: $heroImageUrl, ENT_QUOTES, 'UTF-8') ?>"
             target="_blank" rel="noopener" title="元サイトを開く">
            <img src="<?= htmlspecialchars($heroImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                 loading="lazy" alt="トップページ画像"
                 style="max-width:160px; max-height:110px; object-fit:cover;
                        border-radius:6px; border:1px solid #dee2e6;
                        box-shadow:0 1px 4px rgba(0,0,0,.12);"
                 onerror="this.closest('.col-auto').style.display='none'">
          </a>
        </div>
        <?php endif; ?>

        <!-- ページ情報 + バッジ -->
        <div class="col">
          <div class="fw-bold" style="font-size:.95rem">
            <?= htmlspecialchars($pageTitle ?: '（タイトルなし）', ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="text-muted" style="font-size:.78rem">
            <?php if ($siteUrl !== ''): ?>
              <a href="<?= htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"
                 class="text-muted text-decoration-none"><?= htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.72rem">
            WS: <?= htmlspecialchars($wsDirName, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($analyzedAt): ?>&nbsp;·&nbsp;解析: <?= htmlspecialchars($analyzedAt, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          </div>
          <div class="mt-2 d-flex gap-2 flex-wrap">
            <span class="badge bg-secondary"><?= $totalRows ?> 画像</span>
            <span class="badge bg-secondary"><?= $unsetCount ?> 未設定</span>
            <span class="badge bg-warning text-dark"><?= $phCount ?> プレースホルダー</span>
            <span class="badge bg-success"><?= $replacedCount ?> 差し替え済</span>
          </div>
        </div>

        <!-- この指示書の URL + QR -->
        <div class="col-md-5">
          <div class="card border-0 bg-light rounded-2 px-3 py-2 h-100">
            <div class="d-flex align-items-start gap-3 h-100">
              <div id="qr-box" style="flex-shrink:0; width:120px; height:120px;"></div>
              <div class="overflow-hidden">
                <div class="fw-semibold mb-1" style="font-size:.78rem">
                  <i class="bi bi-qr-code me-1"></i>この指示書の URL
                </div>
                <a href="<?= htmlspecialchars($checklistUrl, ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener"
                   style="font-size:.72rem; word-break:break-all;">
                  <?= htmlspecialchars($checklistUrl, ENT_QUOTES, 'UTF-8') ?>
                </a>
                <div class="text-muted mt-1 no-print" style="font-size:.68rem">
                  QR を読み取るとこのページを直接開けます
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- フィルターバー -->
  <div class="mb-2 d-flex gap-2 flex-wrap align-items-center no-print">
    <span class="text-muted small">表示:</span>
    <div class="btn-group btn-group-sm" role="group">
      <button type="button" class="btn btn-outline-secondary active" id="filterAll" onclick="applyFilter('all')">すべて</button>
      <button type="button" class="btn btn-outline-secondary" id="filterUnset" onclick="applyFilter('unset')">未設定</button>
      <button type="button" class="btn btn-outline-secondary" id="filterPh" onclick="applyFilter('placeholder')">プレースホルダー</button>
      <button type="button" class="btn btn-outline-secondary" id="filterReplaced" onclick="applyFilter('replaced')">差し替え済</button>
    </div>
  </div>

  <!-- テーブル -->
  <?php if ($totalRows === 0): ?>
    <div class="alert alert-info">画像要素が見つかりませんでした。解析データに画像が含まれていない可能性があります。</div>
  <?php else: ?>
  <div class="table-responsive shadow-sm">
    <table class="table table-sm table-bordered table-hover mb-0 bg-white" id="checklistTable">
      <thead>
        <tr>
          <th style="width:92px">サムネイル</th>
          <th style="width:20%">セクション</th>
          <th style="width:6%">種別</th>
          <th style="width:16%">ファイル名</th>
          <th style="width:7%">サイズ</th>
          <th style="width:9%">状態</th>
          <th>元URL / 現在値</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row):
          $s = $statusLabel[$row['status']];
          $dims = ($row['width'] > 0 && $row['height'] > 0) ? $row['width'] . '×' . $row['height'] : '—';
          $typeMap = ['img' => ['img', 'type-badge-img'], 'bg' => ['BG', 'type-badge-bg'], 'css' => ['CSS', 'type-badge-css']];
          [$typeLabel, $typeCls] = $typeMap[$row['type']] ?? ['?', ''];
        ?>
        <?php
          $isReplaced = $row['status'] === 'replaced' && $row['cur_src'] !== '';
          $isPh       = $row['status'] === 'placeholder';
          $thumbSrc   = $row['orig_src'];
        ?>
        <tr data-status="<?= htmlspecialchars($row['status'], ENT_QUOTES) ?>">
          <!-- サムネイル -->
          <td class="align-middle text-center p-1" style="width:92px">
            <?php if ($thumbSrc !== ''): ?>
              <div class="thumb-wrap">
                <img class="thumb-orig"
                     src="<?= htmlspecialchars($thumbSrc, ENT_QUOTES, 'UTF-8') ?>"
                     loading="lazy" alt=""
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="thumb-none" style="display:none"><i class="bi bi-image" style="font-size:1.3rem"></i></div>
                <?php if ($isReplaced): ?>
                  <img class="thumb-replaced"
                       src="<?= htmlspecialchars($row['cur_src'], ENT_QUOTES, 'UTF-8') ?>"
                       loading="lazy" alt="" title="差し替え済み"
                       onerror="this.style.display='none'">
                <?php elseif ($isPh): ?>
                  <span class="position-absolute bottom-0 end-0 badge bg-warning text-dark"
                        style="font-size:.55rem;border-radius:2px;padding:1px 3px;transform:translate(30%,30%)">PH</span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="thumb-none"><i class="bi bi-image" style="font-size:1.3rem"></i></div>
            <?php endif; ?>
          </td>
          <td class="align-middle">
            <div class="fw-semibold" style="line-height:1.2"><?= htmlspecialchars($row['sec_label'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="text-muted" style="font-size:.68rem"><?= htmlspecialchars($row['sec_id'], ENT_QUOTES, 'UTF-8') ?></div>
          </td>
          <td class="align-middle text-center">
            <span class="badge <?= $typeCls ?>" style="font-size:.7rem"><?= $typeLabel ?></span>
          </td>
          <td class="align-middle fw-semibold" style="word-break:break-word">
            <?= htmlspecialchars($row['filename'], ENT_QUOTES, 'UTF-8') ?>
            <?php if (isset($row['css_token'])): ?>
              <div class="text-muted" style="font-size:.68rem"><?= htmlspecialchars($row['css_token'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="text-muted" style="font-size:.68rem"><?= htmlspecialchars($row['elem_id'], ENT_QUOTES, 'UTF-8') ?></div>
          </td>
          <td class="align-middle text-center text-muted"><?= htmlspecialchars($dims, ENT_QUOTES, 'UTF-8') ?></td>
          <td class="align-middle text-center">
            <span class="<?= $s['cls'] ?>" style="font-size:.72rem"><?= $s['text'] ?></span>
          </td>
          <td class="align-middle">
            <?php if ($row['orig_src'] !== ''): ?>
              <div class="orig-url"><?= htmlspecialchars($row['orig_src'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($row['cur_src'] !== ''): ?>
              <div class="cur-val">→ <?= htmlspecialchars(cl_basename($row['cur_src']), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<script>
function applyFilter(status) {
  const rows = document.querySelectorAll('#checklistTable tbody tr');
  rows.forEach(tr => {
    tr.style.display = (status === 'all' || tr.dataset.status === status) ? '' : 'none';
  });
  ['All','Unset','Ph','Replaced'].forEach(k => {
    document.getElementById('filter' + k)?.classList.remove('active');
  });
  const map = { all:'All', unset:'Unset', placeholder:'Ph', replaced:'Replaced' };
  document.getElementById('filter' + (map[status] || 'All'))?.classList.add('active');
}

// QR コード生成（qrcodejs が読み込まれてから実行）
document.addEventListener('DOMContentLoaded', () => {
  const qrBox = document.getElementById('qr-box');
  if (!qrBox) return;
  const url = <?= json_encode($checklistUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  if (typeof QRCode !== 'undefined') {
    new QRCode(qrBox, {
      text:          url,
      width:         120,
      height:        120,
      colorDark:     '#212529',
      colorLight:    '#ffffff',
      correctLevel:  QRCode.CorrectLevel.M,
    });
  } else {
    // ライブラリ読み込み失敗フォールバック：テキストのみ表示
    qrBox.innerHTML = '<span class="text-muted small">QR 読込失敗</span>';
  }
});
</script>
</body>
</html>
