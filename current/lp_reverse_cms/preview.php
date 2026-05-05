<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    lp_reverse_session_start();
}

$cmsRootPreview = __DIR__;
require_once $cmsRootPreview . '/lib/env_load.php';
lp_reverse_load_env();
require_once $cmsRootPreview . '/lib/LpWorkspace.php';
require_once $cmsRootPreview . '/lib/UserRegistry.php';

$workspaceDataDirPv = LpWorkspace::authRegistryDir($cmsRootPreview);
$registryPv        = new UserRegistry($workspaceDataDirPv);

$sessionAuthPv = isset($_SESSION['auth']) && is_array($_SESSION['auth']) ? $_SESSION['auth'] : null;

if ($sessionAuthPv === null) {
    require_once $cmsRootPreview . '/lib/GoogleAuth.php';
    try {
        (new GoogleAuth())->redirectToGoogle();
    } catch (Throwable $e) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="ja"><meta charset="utf-8"><title>認証</title>'
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">'
            . '<body class="bg-dark text-white"><div class="container py-5"><h1>OAuth が利用できません</h1>'
            . '<p class="text-secondary">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
        exit;
    }
}

$sessMailPv = strtolower(trim((string) ($sessionAuthPv['email'] ?? '')));

if ($sessMailPv === '') {
    $_SESSION['auth'] = [];
    unset($_SESSION['auth']);
    require_once $cmsRootPreview . '/lib/GoogleAuth.php';

    try {
        (new GoogleAuth())->redirectToGoogle();
    } catch (Throwable) {
        header('Location: index.php');

        exit;
    }
}

$rolePv = $registryPv->getRole($sessMailPv);

if ($rolePv === null) {
    unset($_SESSION['auth']);
    require_once $cmsRootPreview . '/lib/GoogleAuth.php';

    try {
        (new GoogleAuth())->redirectToGoogle();
    } catch (Throwable) {
        header('Location: index.php');

        exit;
    }
}

$_SESSION['auth']['role'] = $rolePv;

// preview は admin/super を含む
if (!in_array($rolePv, ['preview', 'admin', 'super_admin'], true)) {
    header('Location: index.php');

    exit;
}

$outputDir  = LpWorkspace::outputDir($cmsRootPreview);
$outputFile = $outputDir . 'index.html';

// 出力が無くても編集ユーザーは一覧へ。プレビュー専門ロールのみここで止める。
if (!is_file($outputFile)) {
    $strictPv = ($rolePv === 'preview');
    // admin / super_admin はステップ編集へ
    if (!$strictPv) {
        header('Location: index.php');

        exit;
    }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>プレビュー待機 — Site Reverse CMS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:560px;">
  <h1 class="h4 mb-3">プレビューするサイトがありません</h1>
  <p class="text-muted mb-4">管理者がサイトを生成するまで、この画面のみ表示されます。</p>
  <a class="btn btn-outline-secondary btn-sm me-2" href="store/auth_logout.php">ログアウト</a>
</div>
</body>
</html>
<?php
exit;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>プレビュー — Site Reverse CMS</title>
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
      position: relative;
      margin-top: 52px;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      min-height: calc(100vh - 52px);
      padding: 0;
      transition: padding .3s ease;
    }

    /* スプラッシュ終了後もサイトの遅い描画・画像待ちの間、真っ白に見えないよう重ねる */
    .iframe-load-curtain {
      position: absolute;
      inset: 0;
      z-index: 100;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(24, 25, 26, 0.93);
      padding: 24px;
      text-align: center;
    }
    .iframe-load-curtain[hidden] {
      display: none !important;
    }
    .iframe-load-curtain-inner {
      max-width: 22rem;
    }

    .iframe-container.device-mobile  { padding: 20px; }
    .iframe-container.device-tablet  { padding: 20px; }

    iframe#previewFrame {
      width: 100%;
      border: none;
      height: calc(100vh - 52px);
      background: #fff;
      transition: width .3s ease, height .3s ease, box-shadow .3s ease, opacity 0.35s ease;
      opacity: 1;
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

    #previewSplash {
      position: fixed;
      inset: 0;
      z-index: 10050;
      background: linear-gradient(165deg, #1a1d21 0%, #0d1114 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      transition: opacity 0.35s ease, visibility 0.35s ease;
    }
    #previewSplash.preview-splash--hide {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .preview-splash-panel {
      width: 100%;
      max-width: 420px;
      background: #24282c;
      border: 1px solid rgba(255,255,255,.1);
      border-radius: 16px;
      padding: 28px 24px;
      box-shadow: 0 24px 80px rgba(0,0,0,.55);
    }
    .preview-splash-panel h2 {
      color: #f1f3f5;
      font-size: 1.15rem;
      font-weight: 600;
      margin: 0 0 8px;
    }
    .preview-splash-steps {
      list-style: none;
      padding: 0;
      margin: 20px 0 0;
    }
    .preview-splash-steps li {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 0;
      border-bottom: 1px solid rgba(255,255,255,.06);
      color: #adb5bd;
      font-size: 0.9rem;
    }
    .preview-splash-steps li:last-child { border-bottom: none; }
    .preview-splash-steps li .bi-check-circle-fill { color: #20c997; }
    .preview-splash-steps li.is-active { color: #e9ecef; }
    .preview-splash-steps li.is-pending { opacity: 0.65; }
    .splash-elapsed {
      margin-top: 16px;
      font-size: 0.8rem;
      color: #6c757d;
      text-align: center;
    }
    .splash-dismiss-wrap {
      margin-top: 18px;
    }
  </style>
</head>
<body>

<div id="previewSplash" aria-live="polite" aria-busy="true">
  <div class="preview-splash-panel">
    <div class="text-center mb-3">
      <div class="spinner-border text-primary" role="status" style="width:2.5rem;height:2.5rem"></div>
    </div>
    <h2 class="text-center">プレビューを読み込んでいます</h2>
    <p class="text-center small text-secondary mb-0">生成されたサイトと画像・CSSの読み込みには時間がかかることがあります。</p>
    <ul class="preview-splash-steps" id="splashSteps">
      <li id="splashStep1" class="is-active"><i class="bi bi-check-circle-fill"></i><span>プレビュー画面を初期化</span></li>
      <li id="splashStep2" class="is-pending"><span class="spinner-border spinner-border-sm text-primary" role="status"></span><span>ブラウザが生成済み HTML を読み込み中（再生成はしていません）</span></li>
      <li id="splashStep3" class="is-pending"><i class="bi bi-circle text-secondary"></i><span>ページの描画を待機</span></li>
    </ul>
    <div class="progress mt-2" style="height:6px;background:rgba(255,255,255,.08)">
      <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:100%"></div>
    </div>
    <p class="splash-elapsed mb-0" id="splashElapsed">経過 0 秒</p>
    <div class="splash-dismiss-wrap">
      <button type="button" class="btn btn-outline-light btn-sm w-100" id="splashDismissBtn">
        スプラッシュを閉じてプレビューを見る
      </button>
      <p class="small text-secondary text-center mt-2 mb-0">YouTube 埋め込み等で <code>load</code> が遅延することがあります。結果を先に確認できます。</p>
    </div>
  </div>
</div>

<div class="preview-bar">
  <?php if ($rolePv !== 'preview'): ?>
  <a href="index.php?step=2&from_preview=1" class="btn btn-sm btn-outline-light">
    <i class="bi bi-arrow-left me-1"></i>編集に戻る
  </a>
  <div class="sep"></div>
  <?php endif; ?>

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

  <span class="text-white-50 small text-truncate d-none d-sm-inline ms-2" title="<?= htmlspecialchars($sessMailPv, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($sessMailPv, ENT_QUOTES, 'UTF-8') ?>
  </span>

  <div class="ms-auto d-flex gap-2 align-items-center">
    <button class="btn btn-sm btn-outline-light" id="btnRefresh" title="再読み込み">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
    <?php if ($rolePv !== 'preview'): ?>
    <a href="export.php" class="btn btn-sm btn-success" title="ZIP（最小構成）">
      <i class="bi bi-download me-1"></i>エクスポート
    </a>
    <?php endif; ?>
    <a href="store/auth_logout.php" class="btn btn-sm btn-outline-warning" title="ログアウト">ログアウト</a>
  </div>
</div>

<div class="iframe-container" id="iframeContainer">
  <iframe id="previewFrame" src="<?= htmlspecialchars(LpWorkspace::outputRelIndex() . '?v=' . filemtime($outputFile), ENT_QUOTES, 'UTF-8') ?>" title="生成サイトのプレビュー"></iframe>
  <div id="iframeLoadCurtain" class="iframe-load-curtain" aria-live="polite" aria-busy="false" hidden>
    <div class="iframe-load-curtain-inner">
      <div class="spinner-border text-light mb-3" role="status" style="width:2rem;height:2rem"></div>
      <p class="text-white small mb-1 fw-semibold">プレビュー（サイト）の描画・画像の読み込みを待っています</p>
      <p class="text-secondary small mb-0">重いページや遅い画像では数十秒〜続くことがあります。</p>
    </div>
  </div>
</div>

<script>
(function () {
  const splash = document.getElementById('previewSplash');
  const frame = document.getElementById('previewFrame');
  const step2 = document.getElementById('splashStep2');
  const step3 = document.getElementById('splashStep3');
  const elapsedEl = document.getElementById('splashElapsed');
  const panel = document.querySelector('.preview-splash-panel');
  const dismissBtn = document.getElementById('splashDismissBtn');

  const frameCurtain = document.getElementById('iframeLoadCurtain');

  let splashFinished = false;
  let elapsedTimer = null;
  let slowHintTimer = null;
  let forceEndTimer = null;
  let pollTimer = null;
  /** @type {null | (() => void)} */
  let frameLoadHandler = null;

  function stopPoll() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function startElapsedTimer(from) {
    if (elapsedTimer) clearInterval(elapsedTimer);
    const t0 = from;
    elapsedTimer = setInterval(() => {
      if (elapsedEl) {
        elapsedEl.textContent = '経過 ' + Math.floor((Date.now() - t0) / 1000) + ' 秒';
      }
    }, 400);
  }

  let splashRunId = 0;
  /** 手動閉じ・強制タイムアウト時の iframe 上カーテン。古い settle の hide と新しい表示が競合しないよう世代を持つ */
  let curtainRunId = 0;

  function sleep(ms) {
    return new Promise(r => window.setTimeout(r, ms));
  }

  /** @returns {number} この表示の世代 id（hideCurtain に渡す） */
  function showIframeCurtain() {
    curtainRunId += 1;
    const id = curtainRunId;
    if (frameCurtain) {
      frameCurtain.removeAttribute('hidden');
      frameCurtain.setAttribute('aria-busy', 'true');
    }
    return id;
  }

  /** @param {number} id showIframeCurtain の戻り値 */
  function hideIframeCurtain(id) {
    if (id !== curtainRunId) return;
    if (frameCurtain) {
      frameCurtain.setAttribute('hidden', '');
      frameCurtain.setAttribute('aria-busy', 'false');
    }
  }

  function invalidateIframeCurtain() {
    curtainRunId += 1;
    if (frameCurtain) {
      frameCurtain.setAttribute('hidden', '');
      frameCurtain.setAttribute('aria-busy', 'false');
    }
  }

  /**
   * @param {Document|null} doc
   * @param {number} contentGen splashRunId / capturedRun
   */
  async function previewSettlePhase(doc, contentGen) {
    let d = doc;
    const docWaitStart = Date.now();
    while (!d && Date.now() - docWaitStart < 15000 && contentGen === splashRunId) {
      await sleep(400);
      d = safeFrameDoc();
    }
    if (!d || contentGen !== splashRunId) return;

    try {
      if (d.fonts && typeof d.fonts.ready !== 'undefined') {
        await Promise.race([d.fonts.ready, sleep(10000)]);
      }
    } catch (e) {
      /* ignore */
    }
    if (contentGen !== splashRunId) return;

    const imgs = Array.from(d.images || []).filter(function (img) {
      return !!(img.getAttribute('src') || img.src);
    });
    const slice = imgs.slice(0, 120);
    try {
      await Promise.race([
        Promise.all(slice.map(function (img) {
          if (img.complete) return Promise.resolve();
          return Promise.race([
            new Promise(function (res) {
              img.addEventListener('load', res, { once: true });
              img.addEventListener('error', res, { once: true });
            }),
            sleep(7000),
          ]);
        })),
        sleep(45000),
      ]);
    } catch (e) {
      /* ignore */
    }
    if (contentGen !== splashRunId) return;

    const pollStart = Date.now();
    const pollMax = 60000;
    while (Date.now() - pollStart < pollMax && contentGen === splashRunId) {
      try {
        const body = d.body;
        if (body) {
          const h = body.scrollHeight;
          const tlen = body.innerText.replace(/\s+/g, '').length;
          if (h > 180 || tlen > 48) break;
        }
      } catch (e) {
        break;
      }
      await sleep(320);
      try {
        d = safeFrameDoc() || d;
      } catch (e2) {
        /* ignore */
      }
    }

    await sleep(450);
    if (contentGen !== splashRunId) return;
    try {
      for (let i = 0; i < 5; i++) {
        await new Promise(function (r) {
          requestAnimationFrame(r);
        });
      }
    } catch (e) {
      /* ignore */
    }
  }

  function safeFrameDoc() {
    try {
      return frame.contentDocument;
    } catch (e) {
      return null;
    }
  }

  function hideSplash() {
    if (!splash) return;
    if (elapsedTimer) clearInterval(elapsedTimer);
    stopPoll();
    splash.setAttribute('aria-busy', 'false');
    splash.classList.add('preview-splash--hide');
    window.setTimeout(() => {
      if (splash) splash.style.display = 'none';
      if (frame) {
        frame.style.opacity = '1';
      }
    }, 400);
  }

  /**
   * @param {{ skipSettle?: boolean }} opts
   * skipSettle: スプラッシュだけ即閉じ、iframe 上カーテンで同じ待機を続ける（手動・強制タイムアウト）。
   */
  function finishSplash(opts) {
    const skipSettle = !!(opts && opts.skipSettle);
    if (splashFinished || !step2 || !step3) return;
    splashFinished = true;
    const capturedRun = splashRunId;

    if (slowHintTimer) clearTimeout(slowHintTimer);
    if (forceEndTimer) clearTimeout(forceEndTimer);
    stopPoll();
    if (frameLoadHandler && frame) {
      frame.removeEventListener('load', frameLoadHandler);
      frameLoadHandler = null;
    }

    step2.classList.remove('is-pending');
    step2.classList.add('is-active');
    step2.innerHTML = '<i class="bi bi-check-circle-fill"></i><span>HTML の読み込み完了</span>';

    if (skipSettle) {
      step3.classList.remove('is-pending');
      step3.classList.add('is-active');
      step3.innerHTML = '<i class="bi bi-check-circle-fill"></i><span>ツールバー下で読み込みを継続しています</span>';
      if (frame) frame.style.opacity = '1';
      hideSplash();
      const curtainId = showIframeCurtain();
      void previewSettlePhase(null, capturedRun).finally(function () {
        hideIframeCurtain(curtainId);
      });
      return;
    }

    step3.classList.remove('is-pending');
    step3.classList.add('is-active');
    step3.innerHTML = '<span class="spinner-border spinner-border-sm text-info me-1" role="status"></span><span>スタイル・フォント・画像・描画を待機しています…</span>';

    void (async function () {
      await previewSettlePhase(safeFrameDoc(), capturedRun);
      if (capturedRun !== splashRunId) return;

      step3.innerHTML = '<i class="bi bi-check-circle-fill"></i><span>プレビューを表示しました</span>';
      if (frame) {
        frame.style.opacity = '0';
        frame.style.transition = 'opacity 0.35s ease';
        requestAnimationFrame(function () {
          if (capturedRun !== splashRunId) return;
          requestAnimationFrame(function () {
            if (frame && capturedRun === splashRunId) frame.style.opacity = '1';
          });
        });
      }
      window.setTimeout(function () {
        if (capturedRun === splashRunId) hideSplash();
      }, 120);
    })();
  }

  function isBlankNavigation() {
    const s = String(frame.getAttribute('src') || frame.src || '');
    return s.indexOf('about:blank') !== -1;
  }

  /**
   * 同一オリジン iframe 用。interactive では閉じない（CSS/画像前の真っ白を防ぐ）。
   */
  function startPollReadyState() {
    stopPoll();
    pollTimer = window.setInterval(() => {
      if (splashFinished) {
        stopPoll();
        return;
      }
      if (isBlankNavigation()) return;
      try {
        const doc = frame.contentDocument;
        if (!doc) return;
        if (doc.readyState === 'complete') {
          finishSplash({});
          return;
        }
      } catch (e) {
        /* 参照不可 */
      }
    }, 500);
  }

  function bindFrameLoad() {
    if (!frame) return;
    if (frameLoadHandler) {
      frame.removeEventListener('load', frameLoadHandler);
      frameLoadHandler = null;
    }
    frameLoadHandler = () => {
      const h = frameLoadHandler;
      frameLoadHandler = null;
      if (h && frame) frame.removeEventListener('load', h);
      if (isBlankNavigation()) {
        bindFrameLoad();
        return;
      }
      finishSplash({});
    };
    frame.addEventListener('load', frameLoadHandler);
  }

  function ensureSlowHint() {
    if (splashFinished || !splash || splash.classList.contains('preview-splash--hide')) return;
    if (document.getElementById('splashSlowHint') || !panel) return;
    const hint = document.createElement('p');
    hint.className = 'small text-warning text-center mt-3 mb-0';
    hint.id = 'splashSlowHint';
    hint.innerHTML = '90秒以上スプラッシュが続く場合は下のボタンで進むか、広告ブロックをオフにして「再読み込み」を試してください。';
    panel.appendChild(hint);
  }

  function armSafetyTimers() {
    if (slowHintTimer) clearTimeout(slowHintTimer);
    if (forceEndTimer) clearTimeout(forceEndTimer);
    slowHintTimer = window.setTimeout(ensureSlowHint, 55000);
    forceEndTimer = window.setTimeout(() => {
      if (!splashFinished) finishSplash({ skipSettle: true });
    }, 90000);
  }

  function showSplashForReload() {
    if (!splash) return;
    splashRunId += 1;
    invalidateIframeCurtain();
    const oldHint = document.getElementById('splashSlowHint');
    if (oldHint) oldHint.remove();
    splashFinished = false;
    if (frameLoadHandler && frame) {
      frame.removeEventListener('load', frameLoadHandler);
      frameLoadHandler = null;
    }
    stopPoll();
    splash.style.display = 'flex';
    splash.classList.remove('preview-splash--hide');
    splash.setAttribute('aria-busy', 'true');
    step2.classList.remove('is-active');
    step2.classList.add('is-pending');
    step2.innerHTML = '<span class="spinner-border spinner-border-sm text-primary" role="status"></span><span>HTML を再読み込み中…</span>';
    step3.classList.remove('is-active');
    step3.classList.add('is-pending');
    step3.innerHTML = '<i class="bi bi-circle text-secondary"></i><span>ページの描画を待機</span>';
    startElapsedTimer(Date.now());
    bindFrameLoad();
    startPollReadyState();
    armSafetyTimers();
  }

  if (dismissBtn) {
    dismissBtn.addEventListener('click', () => finishSplash({ skipSettle: true }));
  }

  startElapsedTimer(Date.now());
  bindFrameLoad();
  startPollReadyState();
  armSafetyTimers();

  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      try {
        if (isBlankNavigation()) return;
        const doc = frame.contentDocument;
        if (doc && doc.readyState === 'complete') {
          finishSplash({});
        }
      } catch (e) {
        /* 参照不可 */
      }
    });
  });

  const container = document.getElementById('iframeContainer');

  document.getElementById('btnRefresh').addEventListener('click', () => {
    showSplashForReload();
    const f = document.getElementById('previewFrame');
    const base = f.src.replace(/\?.*$/, '');
    const next = base + '?v=' + Date.now();
    f.src = 'about:blank';
    window.setTimeout(() => {
      f.src = next;
    }, 80);
  });

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
})();
</script>
</body>
</html>
