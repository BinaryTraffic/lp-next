/**
 * Site Reverse CMS — Admin UI controller
 */
(() => {
  'use strict';

  // -----------------------------------------------------------------------
  // State
  // -----------------------------------------------------------------------
  let currentStep = window.LP_CMS?.initialStep ?? 1;

  /** Step 3 診断で取得した fetch_failures（ログモーダル用） */
  let lastDiagFetchFailures = [];
  /** @type {AbortController|null} */
  let generateAbortController = null;
  let generateStopRequested = false;
  /** @type {string} */
  let currentAnalyzeJobId = '';
  /** @type {string} */
  let currentGenerateJobId = '';
  /** @type {number|null} */
  let analyzePollTimer = null;
  /** @type {number|null} */
  let generatePollTimer = null;
  /** @type {number|null} */
  let generateEntryElapsedInterval = null;
  let generateStartedAt = 0;
  let generateLastUpdatedAt = 0;

  // Page tree state (Step 2 Explorer UI)
  /** @type {string} currently selected page key ('index' | 'internal_N') */
  let currentPageKey = 'index';
  /** @type {boolean} has the tree been built yet */
  let treeInitialized = false;

  // -----------------------------------------------------------------------
  // DOM refs
  // -----------------------------------------------------------------------
  const panels = {
    1: document.getElementById('step1Panel'),
    2: document.getElementById('step2Panel'),
    3: document.getElementById('step3Panel'),
  };

  const stepItems = document.querySelectorAll('.step-item');
  const stepConnectors = document.querySelectorAll('.step-connector');

  // Step 1
  const lpUrlInput      = document.getElementById('lpUrlInput');
  const btnFetchAnalyze = document.getElementById('btnFetchAnalyze');
  const fetchProgress   = document.getElementById('fetchProgress');
  const fetchError      = document.getElementById('fetchError');
  const analyzeProgressModalEl  = document.getElementById('analyzeProgressModal');
  const analyzeProgressCloseBtn = document.getElementById('analyzeProgressCloseBtn');
  const analyzeProgressError    = document.getElementById('analyzeProgressError');
  const progFetch       = document.getElementById('prog_fetch');
  const progFetchDetail = document.getElementById('prog_fetch_detail');
  const progAnalyze     = document.getElementById('prog_analyze');
  const progAnalyzeDetail = document.getElementById('prog_analyze_detail');
  const progAnalyzeBarWrap = document.getElementById('prog_analyze_bar_wrap');
  const progAnalyzeBarOuter = document.getElementById('prog_analyze_bar_outer');
  const progAnalyzeBar = document.getElementById('prog_analyze_bar');
  const progAnalyzePct = document.getElementById('prog_analyze_pct');

  // Step 2
  const btnBackToStep1  = document.getElementById('btnBackToStep1');
  const btnResetClient  = document.getElementById('btnResetClient');
  const btnSaveGenerate = document.getElementById('btnSaveGenerate');
  const editFormWrapper = document.getElementById('editFormWrapper');
  const generateError   = document.getElementById('generateError');
  const generateSuccess = document.getElementById('generateSuccess');

  // Step 3
  const btnEditAgain = document.getElementById('btnEditAgain');
  const btnCopyWorkspaceName = document.getElementById('btnCopyWorkspaceName');
  const workspaceNameField = document.getElementById('workspaceNameField');

  /** データ上到達した最遠ステップ。この範囲内のみステップアイコンクリックで遷移可 */
  let maxReachableStep = Math.min(
    3,
    Math.max(
      1,
      typeof window.LP_CMS?.maxReachableStep === 'number'
        ? Number(window.LP_CMS.maxReachableStep)
        : (window.LP_CMS?.hasOutput ? 3 : window.LP_CMS?.hasStructure ? 2 : 1),
    ),
  );

  const stepIndicatorEl = document.getElementById('stepIndicator');

  /**
   * If Bootstrap left body.modal-open / .modal-backdrop behind while no modal has .show,
   * pointer events hit the invisible backdrop and form fields (e.g. paragraph textarea) look focused but won't receive input.
   */
  function cleanupStrayModalBackdrops() {
    if (document.querySelector('.modal.show')) {
      return;
    }
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
  }

  // -----------------------------------------------------------------------
  // Step navigation
  // -----------------------------------------------------------------------
  function goToStep(n) {
    cleanupStrayModalBackdrops();
    currentStep = n;
    Object.values(panels).forEach(p => p && p.classList.add('d-none'));
    panels[n] && panels[n].classList.remove('d-none');

    // Show/hide fixed action sidebar with step 2
    const actionBar = document.getElementById('step2ActionBar');
    if (actionBar) {
      if (n === 2) {
        actionBar.style.display = 'flex';
      } else {
        actionBar.style.display = 'none';
        actionBar.classList.remove('is-open');
      }
    }

    // Initialize tree when entering Step 2
    if (n === 2) {
      void initPageTree();
      // Update fixed header for the initial index page
      updateEditFormHeader('index', window.LP_CMS?.indexPageTitle || '', window.LP_CMS?.sourceUrl || '');
    }

    // Load diagnostics when entering Step 3
    if (n === 3) {
      const diagSummary = document.getElementById('step3DiagSummary');
      if (diagSummary) void loadDiagnostics(diagSummary);
    }

    stepItems.forEach((item, idx) => {
      const s = parseInt(item.dataset.step, 10);
      item.classList.remove('step-active', 'step-done', 'step-pending');
      if (s < n) item.classList.add('step-done');
      else if (s === n) item.classList.add('step-active');
      else item.classList.add('step-pending');
    });

    stepConnectors.forEach((conn, idx) => {
      // connector idx 0 is between steps 1-2; idx 1 between 2-3
      conn.classList.toggle('step-connector-done', (idx + 2) <= n);
    });
  }

  function refreshStepNavigatorDom() {
    if (!stepIndicatorEl) {
      return;
    }

    stepIndicatorEl.querySelectorAll('.step-item[data-step]').forEach(raw => {
      if (!(raw instanceof HTMLElement)) {
        return;
      }

      const sn = parseInt(String(raw.dataset.step ?? ''), 10);
      const ok = Number.isFinite(sn) && sn >= 1 && sn <= maxReachableStep;
      raw.classList.toggle('step-item-navigable', ok);

      if (ok) {
        raw.setAttribute('role', 'button');
        raw.setAttribute('tabindex', '0');
        const lab = String(raw.dataset.stepLabel || '').trim() || ('ステップ' + sn);

        raw.setAttribute('aria-label', lab + 'へ移動');
      } else {
        raw.removeAttribute('role');
        raw.removeAttribute('tabindex');
        raw.removeAttribute('aria-label');
      }
    });
  }

  function expandMaxReachable(n) {
    const cap = Math.min(3, Math.max(1, n));

    if (cap <= maxReachableStep) {
      return false;
    }

    maxReachableStep = cap;

    if (window.LP_CMS && typeof window.LP_CMS === 'object') {

      window.LP_CMS.maxReachableStep = maxReachableStep;

      if (cap >= 3) {
        window.LP_CMS.hasOutput = true;

        window.LP_CMS.hasStructure = true;
      } else if (cap >= 2) {

        window.LP_CMS.hasStructure = true;
      }
    }

    refreshStepNavigatorDom();

    return true;
  }

  function setUrlStepParam(n) {

    const qs = new URLSearchParams(window.location.search);

    qs.set('step', String(n));

    const q = qs.toString();

    window.history.replaceState({}, '', `${window.location.pathname}${q ? '?' + q : ''}`);
  }

  /**
   * @returns {boolean} 許可済みステップのみ true
   */
  function tryNavigateToReachedStep(snRaw) {

    const sn = parseInt(String(snRaw ?? ''), 10);

    if (!Number.isFinite(sn) || sn < 1 || sn > 3 || sn > maxReachableStep) {

      return false;
    }
    setUrlStepParam(sn);

    goToStep(sn);

    return true;
  }

  function bindStepIndicatorClickNav() {
    if (!stepIndicatorEl) {
      return;
    }

    stepIndicatorEl.addEventListener('click', ev => {
      const t = /** @type {HTMLElement|null} */ (ev.target);
      const item = /** @type {HTMLElement|null} */ (t.closest ? t.closest('.step-item-navigable') : null);

      if (!item || !stepIndicatorEl.contains(item)) {
        return;
      }

      const sn = parseInt(String(item.dataset.step ?? ''), 10);

      if (!tryNavigateToReachedStep(sn)) {
        return;
      }

      ev.preventDefault();
    });

    stepIndicatorEl.addEventListener('keydown', ev => {
      if (ev.key !== 'Enter' && ev.key !== ' ') {
        return;
      }

      const active = /** @type {HTMLElement|null} */ (document.activeElement);

      if (!active || active === document.body) {
        return;
      }

      if (!stepIndicatorEl.contains(active) || !active.classList.contains('step-item-navigable')) {

        return;
      }

      const sn = parseInt(String(active.dataset.step ?? ''), 10);

      ev.preventDefault();

      tryNavigateToReachedStep(sn);
    });
  }
  // -----------------------------------------------------------------------
  // Toast notification
  // -----------------------------------------------------------------------
  function showToast(message, type = 'success') {
    let toast = document.getElementById('statusToast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'statusToast';
      document.body.appendChild(toast);
    }

    const iconMap = {
      success: 'bi-check-circle-fill',
      danger:  'bi-exclamation-triangle-fill',
      warning: 'bi-exclamation-circle-fill',
      info:    'bi-info-circle-fill',
    };

    toast.className = `alert alert-${type} d-flex align-items-start gap-2 mb-0`;
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;min-width:280px;max-width:380px;box-shadow:0 4px 24px rgba(0,0,0,.18);border-radius:10px;';
    toast.innerHTML = `<i class="bi ${iconMap[type] ?? 'bi-info-circle-fill'} flex-shrink-0 mt-1"></i><div>${message}</div>`;

    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => {
      toast.style.transition = 'opacity .4s ease, transform .4s ease';
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(16px)';
      setTimeout(() => toast.remove(), 450);
    }, 4000);
  }

  // -----------------------------------------------------------------------
  // サイト構造解析: NDJSON 進捗（ツリー総ステップ基準）
  // -----------------------------------------------------------------------

  function resetAnalyzeProgressUi() {
    if (!progAnalyzeBar || !progAnalyzePct) return;
    progAnalyzeBar.style.width = '0%';
    progAnalyzePct.textContent = '0%';
    progAnalyzeBarOuter?.setAttribute('aria-valuenow', '0');
    progAnalyzeBarOuter?.setAttribute('aria-valuetext', '0%');
  }

  /**
   * フェーズが切り替わると同じ行に「11/…」「7/…」が続き、カウンタが戻ったように見える。
   * NDJSON の phase を先頭に付けて混同しないようにする。
   *
   * @param {Record<string, unknown>} row
   */
  function formatAnalyzeProgressDetail(row) {
    const det = typeof row.detail_ja === 'string' ? row.detail_ja.trim() : '';
    const phaseRaw = row.phase;
    const phase = typeof phaseRaw === 'string' ? phaseRaw.trim() : '';
    /** @type {Record<string, string>} */
    const labelByPhase = {
      start: '開始',
      tree_walk: 'ツリー走査',
      mapper: 'セクション整形',
      link_redirect_check: 'リンク検証（HEAD）',
      internal_pages: '内部ページ取得',
      memos: '画像メモ',
      write: '保存',
      industry: '業種候補',
      complete: '完了',
    };
    const label = phase ? (labelByPhase[phase] ?? phase) : '';
    if (!det && !label) {
      return '';
    }
    if (!label) {
      return det;
    }
    if (!det) {
      return `【${label}】`;
    }
    return `【${label}】 ${det}`;
  }

  /**
   * @param {unknown} row
   */
  function applyAnalyzeProgressRow(row) {
    if (!row || typeof row !== 'object') return;
    const r = /** @type {Record<string, unknown>} */ (row);
    if (r.type !== 'progress') return;
    const pctRaw = Number(r.pct);
    const pct = Number.isFinite(pctRaw) ? Math.max(0, Math.min(100, pctRaw)) : 0;
    const pctRounded = Math.round(pct);
    const pctLabel = `${pctRounded}%`;
    if (progAnalyzeBar) progAnalyzeBar.style.width = `${pct}%`;
    progAnalyzeBarOuter?.setAttribute('aria-valuenow', String(pctRounded));
    progAnalyzeBarOuter?.setAttribute('aria-valuetext', pctLabel);
    const labeled = formatAnalyzeProgressDetail(r);
    if (progAnalyzePct) progAnalyzePct.textContent = pctLabel;
    if (progAnalyzeDetail) progAnalyzeDetail.textContent = labeled || pctLabel;
  }

  /**
   * NDJSON をチャンク受信ごとに解釈し progress を即時反映する（レスポンス完了まで await text しない）。
   *
   * @param {ReadableStream<Uint8Array>} body
   * @param {(row: Record<string, unknown>) => void} onProgress
   */
  async function parseAnalyzeNdjsonStream(body, onProgress) {
    const reader = body.getReader();
    const dec = new TextDecoder();
    let buf = '';
    /** @type {Record<string, unknown>|null} */
    let payload = null;

    const flushLine = (line) => {
      const t = line.trim();
      if (!t) return;
      let row;
      try {
        row = JSON.parse(t);
      } catch {
        return;
      }
      if (!row || typeof row !== 'object') return;
      const rec = /** @type {Record<string, unknown>} */ (row);
      if (rec.type === 'progress') {
        onProgress(rec);
      } else if (rec.type === 'complete') {
        payload = rec;
      } else if (rec.type === 'error') {
        throw new Error(String(rec.error || '解析エラー'));
      }
    };

    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      buf += dec.decode(value, { stream: true });
      const nl = buf.indexOf('\n');
      if (nl === -1) continue;
      const parts = buf.split('\n');
      buf = parts.pop() ?? '';
      for (const ln of parts) {
        flushLine(ln);
      }
    }

    buf += dec.decode();
    const tail = buf.split('\n');
    for (const ln of tail) {
      flushLine(ln);
    }

    if (!payload) throw new Error('解析応答が途中で終了しました。');
    const { type, ...rest } = payload;
    return rest;
  }

  /** @returns {Promise<Record<string, unknown>>} */
  async function apiPostAnalyzeStream(endpoint, extra = {}) {
    const body = Object.assign({ stream_progress: true }, (extra && typeof extra === 'object') ? extra : {});
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8', Accept: 'application/x-ndjson, application/json' },
      body: JSON.stringify(body),
    });
    const ctype = (res.headers.get('Content-Type') || '').toLowerCase();

    if (!res.ok) {
      const text = await res.text();
      const msg = ndjsonFirstJsonError(text) || safeJsonParseError(text);
      throw new Error(msg || `HTTP ${res.status}`);
    }

    if (ctype.includes('ndjson') && res.body?.getReader) {
      return parseAnalyzeNdjsonStream(res.body, applyAnalyzeProgressRow);
    }

    const text = await res.text();
    if (ctype.includes('ndjson')) {
      return parseAnalyzeNdjsonResponse(text, applyAnalyzeProgressRow);
    }
    try {
      return JSON.parse(text);
    } catch {
      throw new Error('解析応答の JSON が不正です。');
    }
  }

  /**
   * @param {string} text
   */
  function ndjsonFirstJsonError(text) {
    const line = text.trim().split('\n').find(Boolean);
    if (!line) return '';
    try {
      const row = JSON.parse(line);
      if (row && row.type === 'error' && row.error) return String(row.error);
    } catch { /* noop */ }
    return '';
  }

  /**
   * @param {string} text
   */
  function safeJsonParseError(text) {
    try {
      const o = JSON.parse(text);
      return o?.error ? String(o.error) : '';
    } catch {
      return '';
    }
  }

  /**
   * @param {string} text
   * @param {(row: Record<string, unknown>) => void} onProgress
   */
  function parseAnalyzeNdjsonResponse(text, onProgress) {
    const lines = text.split('\n');
    /** @type {Record<string, unknown>|null} */
    let payload = null;
    for (const line of lines) {
      const t = line.trim();
      if (!t) continue;
      let row;
      try {
        row = JSON.parse(t);
      } catch {
        continue;
      }
      if (!row || typeof row !== 'object') continue;
      const rec = /** @type {Record<string, unknown>} */ (row);
      if (rec.type === 'progress') {
        onProgress(rec);
      } else if (rec.type === 'complete') {
        payload = rec;
      } else if (rec.type === 'error') {
        throw new Error(String(rec.error || '解析エラー'));
      }
    }
    if (!payload) throw new Error('解析応答が途中で終了しました。');
    const { type, ...rest } = payload;
    return rest;
  }

  // -----------------------------------------------------------------------
  // Progress helpers (Step 1)
  // -----------------------------------------------------------------------

  /**
   * @param {{ skipDetail?: boolean }} [opts]
   */
  function setProgState(el, state, detail = '', opts = {}) {
    const spinner  = el.querySelector('.spinner-border');
    const iconWrap = el.querySelector('.text-secondary');

    el.classList.remove('prog-done', 'prog-error', 'text-muted');

    if (state === 'loading') {
      if (spinner) spinner.style.display = '';
      if (iconWrap) iconWrap.innerHTML = '<div class="spinner-border spinner-border-sm text-primary"></div>';
    } else if (state === 'done') {
      el.classList.add('prog-done');
      if (spinner) spinner.style.display = 'none';
      if (iconWrap) iconWrap.innerHTML = '<i class="bi bi-check-circle-fill text-success fs-5"></i>';
    } else if (state === 'error') {
      el.classList.add('prog-error');
      if (spinner) spinner.style.display = 'none';
      if (iconWrap) iconWrap.innerHTML = '<i class="bi bi-x-circle-fill text-danger fs-5"></i>';
    } else {
      el.classList.add('text-muted');
      if (spinner) spinner.style.display = 'none';
      if (iconWrap) iconWrap.innerHTML = '<i class="bi bi-circle fs-5"></i>';
    }

    const detailEl = el.querySelector('[id$="_detail"]');
    if (detailEl && !opts.skipDetail) {
      detailEl.textContent = detail;
    }
  }

  function openFetchFailureLogModal(urlList) {
    const ta = document.getElementById('fetchFailureLogBody');
    const modalEl = document.getElementById('fetchFailureModal');
    if (!ta || !modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      return;
    }
    const lines = Array.isArray(urlList) ? urlList.map(u => String(u)) : [];
    ta.value = lines.length > 0
      ? lines.join('\n')
      : '（失敗 URL の記録がありません。store/debug.php の fetch_failures を参照してください。）';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

  /**
   * Step 1 取得完了行に「失敗 N件」リンクを埋め込む（テキストのみの部分は textContent で連結）
   */
  function renderFetchProgDetail(htmlKb, buckets, total, failed, failList) {
    if (!progFetchDetail) return;
    progFetchDetail.replaceChildren();
    const totalExplain = `（計 ${total}＝重複省略後のアセットファイル保存先の一意数）`;
    const line = `HTML ${htmlKb} KB | ${buckets}${totalExplain}`;
    progFetchDetail.appendChild(document.createTextNode(line));
    if (failed > 0) {
      const list = Array.isArray(failList) ? failList : [];
      progFetchDetail.appendChild(document.createTextNode(' / '));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-link btn-sm p-0 align-baseline text-danger text-decoration-underline';
      btn.setAttribute('aria-label', '失敗したURLのログを表示');
      btn.textContent = `失敗 ${failed}件（ログを表示）`;
      btn.addEventListener('click', () => {
        openFetchFailureLogModal(list);
      });
      progFetchDetail.appendChild(btn);
    }
  }

  // -----------------------------------------------------------------------
  // Step 1 — Fetch & Analyse
  // -----------------------------------------------------------------------
  async function runFetchAndAnalyze() {
    const url = lpUrlInput.value.trim();
    if (!url) {
      showError(fetchError, 'URLを入力してください。');
      lpUrlInput.focus();
      return;
    }
    const purpose = (prompt('この解析の目的を入力してください（必須）', `解析: ${url}`) || '').trim();
    if (!purpose) {
      showError(fetchError, '目的は必須です。');
      return;
    }

    btnFetchAnalyze.disabled = true;
    fetchError.classList.add('d-none');
    if (analyzeProgressError) analyzeProgressError.classList.add('d-none');
    if (analyzeProgressCloseBtn) analyzeProgressCloseBtn.disabled = true;

    // Reset progress items
    setProgState(progFetch, 'loading', 'HTMLおよびCSS・画像を取得中…');
    setProgState(progAnalyze, 'idle');
    resetAnalyzeProgressUi();
    progAnalyzeBarWrap?.classList.add('d-none');

    if (analyzeProgressModalEl && typeof bootstrap !== 'undefined') {
      bootstrap.Modal.getOrCreateInstance(analyzeProgressModalEl).show();
    }

    try {
      const out = await apiPost('store/analyze_start.php', {
        url,
        csrf: String(window.LP_CMS?.csrfToken || ''),
      });
      if (!out.ok) {
        throw new Error(typeof out.error === 'string' ? out.error : '解析ジョブ開始に失敗しました。');
      }
      const taskId = String(out.task_id || '');
      if (!taskId) {
        throw new Error('task_id が取得できませんでした。');
      }
      startAnalyzePolling(taskId);
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      showError(fetchError, message);
      if (analyzeProgressError) showError(analyzeProgressError, message);
      setProgState(progFetch, 'error');
      setProgState(progAnalyze, 'error');
      progAnalyzeBarWrap?.classList.add('d-none');
      btnFetchAnalyze.disabled = false;
      if (analyzeProgressCloseBtn) analyzeProgressCloseBtn.disabled = false;
    }
  }

  function stopAnalyzePolling() {
    if (analyzePollTimer !== null) {
      window.clearInterval(analyzePollTimer);
      analyzePollTimer = null;
    }
  }

  async function fetchAnalyzeProgress(taskId) {
    const q = taskId ? `?task_id=${encodeURIComponent(taskId)}` : '';
    const res = await fetch(`store/analyze_progress.php${q}`, { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    // 404 "task not found" は書き込み競合の瞬間読みによる一過性エラーの可能性があるためリトライ
    if (res.status === 404 && data && data.error === 'task not found') {
      await sleep(1200);
      const res2 = await fetch(`store/analyze_progress.php${q}`, { credentials: 'same-origin' });
      const data2 = await res2.json().catch(() => ({}));
      if (!res2.ok || !data2.ok) {
        throw new Error((data2 && data2.error) ? String(data2.error) : (`HTTP ${res2.status}`));
      }
      return data2;
    }
    if (!res.ok || !data.ok) {
      throw new Error((data && data.error) ? String(data.error) : (`HTTP ${res.status}`));
    }
    return data;
  }

  async function tickAnalyzeProgress(taskId) {
    const data = await fetchAnalyzeProgress(taskId);
    const ws = String(window.LP_CMS?.workspaceName || '');
    const taskWs = String(data.workspace_id || '');
    if (ws && taskWs && ws !== taskWs) {
      throw new Error('別ワークスペースの解析ジョブです。ページを再読み込みして再実行してください。');
    }

    const phase = String(data.phase || '');
    const prog = String(data.progress_text || '000/100');
    const detailJa = typeof data.detail_ja === 'string' ? data.detail_ja.trim() : '';
    if (phase === 'fetch') {
      setProgState(progFetch, 'loading', 'HTMLおよびCSS・画像を取得中…');
      setProgState(progAnalyze, 'idle');
      progAnalyzeBarWrap?.classList.add('d-none');
    } else if (phase === 'analyze_entry') {
      setProgState(progFetch, 'done', '取得完了');
      setProgState(progAnalyze, 'loading', 'エントリ解析中…');
      progAnalyzeBarWrap?.classList.remove('d-none');
    } else if (phase === 'analyze_internal') {
      setProgState(progFetch, 'done', '取得完了');
      setProgState(progAnalyze, 'loading', '内部ページ解析中…');
      progAnalyzeBarWrap?.classList.remove('d-none');
      const m = prog.match(/^(\d+)\/(\d+)$/);
      if (m && progAnalyzeBar && progAnalyzePct) {
        const done = Number(m[1]);
        const total = Math.max(1, Number(m[2]));
        const pct = Math.min(99, Math.round((100 * done) / total));
        progAnalyzeBar.style.width = `${pct}%`;
        progAnalyzePct.textContent = `${pct}%`;
        progAnalyzeBarOuter?.setAttribute('aria-valuenow', String(pct));
      }
    } else if (phase === 'finalize') {
      setProgState(progAnalyze, 'loading', '最終処理中…');
      progAnalyzeBarWrap?.classList.remove('d-none');
      const mf = prog.match(/^(\d+)\/(\d+)$/);
      if (mf && progAnalyzeBar && progAnalyzePct) {
        const done = Number(mf[1]);
        const total = Math.max(1, Number(mf[2]));
        const pct = Math.min(100, Math.round((100 * done) / total));
        progAnalyzeBar.style.width = `${pct}%`;
        progAnalyzePct.textContent = `${pct}%`;
        progAnalyzeBarOuter?.setAttribute('aria-valuenow', String(pct));
      } else if (progAnalyzeBar && progAnalyzePct) {
        progAnalyzeBar.style.width = '99%';
        progAnalyzePct.textContent = '99%';
        progAnalyzeBarOuter?.setAttribute('aria-valuenow', '99');
      }
    }
    if (detailJa && progAnalyzeDetail) {
      progAnalyzeDetail.textContent = detailJa;
    }

    if (data.done === true) {
      stopAnalyzePolling();
      btnFetchAnalyze.disabled = false;
      if (analyzeProgressCloseBtn) analyzeProgressCloseBtn.disabled = false;
      const st = String(data.status || '');
      if (st === 'done') {
        setProgState(progAnalyze, 'done', '解析が完了しました。');
        if (progAnalyzeBar) progAnalyzeBar.style.width = '100%';
        if (progAnalyzePct) progAnalyzePct.textContent = '100%';
        await sleep(600);
        if (analyzeProgressModalEl && typeof bootstrap !== 'undefined') {
          bootstrap.Modal.getOrCreateInstance(analyzeProgressModalEl).hide();
        }
        window.location.href = window.location.pathname + '?step=2';
      } else {
        const msg = st === 'stale'
          ? '解析ジョブが応答しなくなりました（stale）。'
          : String(data.error || '解析ジョブが失敗しました。');
        if (analyzeProgressError) showError(analyzeProgressError, msg);
        showError(fetchError, msg);
        setProgState(progAnalyze, 'error');
      }
    }
  }

  function startAnalyzePolling(taskId) {
    stopAnalyzePolling();
    void tickAnalyzeProgress(taskId).catch((e) => {
      const msg = e instanceof Error ? e.message : String(e);
      if (analyzeProgressError) showError(analyzeProgressError, msg);
      btnFetchAnalyze.disabled = false;
    });
    analyzePollTimer = window.setInterval(() => {
      void tickAnalyzeProgress(taskId).catch((e) => {
        const msg = e instanceof Error ? e.message : String(e);
        if (analyzeProgressError) showError(analyzeProgressError, msg);
        stopAnalyzePolling();
        btnFetchAnalyze.disabled = false;
      });
    }, 2500);
  }

  function resumeAnalyzePollingIfNeeded() {
    void (async () => {
      try {
        const res = await fetch('store/analyze_progress.php', { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok || data.exists !== true || data.done === true) return;
        const taskId = String(data.task_id || '');
        const ws = String(window.LP_CMS?.workspaceName || '');
        const taskWs = String(data.workspace_id || '');
        if (ws && taskWs && ws !== taskWs) return;
        if (!taskId) return;
        if (analyzeProgressModalEl && typeof bootstrap !== 'undefined') {
          bootstrap.Modal.getOrCreateInstance(analyzeProgressModalEl).show();
        }
        startAnalyzePolling(taskId);
      } catch {
        void 0;
      }
    })();
  }

  // -----------------------------------------------------------------------
  // Step 2 — Save & Generate (progress modal)
  // -----------------------------------------------------------------------

  /** @param {number} secs */
  function fmtElapsed(secs) {
    const s = Math.max(0, Math.floor(secs));
    const m = Math.floor(s / 60);
    return m > 0 ? `${m}分${s % 60}秒` : `${s}秒`;
  }

  /** @param {boolean} on */
  function setProgressBarIndeterminate(on) {
    const bar = document.getElementById('saveGenProgressBar');
    if (!bar) return;
    if (on) {
      bar.classList.add('progress-bar-striped', 'progress-bar-animated');
      bar.style.width = '100%';
    } else {
      bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
    }
  }

  function updateGenerateEntryLabel() {
    const elapsed = generateStartedAt > 0 ? (Date.now() / 1000 - generateStartedAt) : 0;
    const idle = generateLastUpdatedAt > 0 ? Math.floor(Date.now() / 1000 - generateLastUpdatedAt) : -1;
    const elapsedStr = generateStartedAt > 0 ? ` 経過 ${fmtElapsed(elapsed)}` : '';
    const idleStr = idle >= 0 ? ` (最終更新 ${idle}秒前)` : '';
    const labelEl = document.getElementById('saveGenProgressLabel');
    if (labelEl) labelEl.textContent = `トップページ生成中...${elapsedStr}${idleStr}`;
  }

  function ensureSaveGenProgressUi() {
    let wrap = document.getElementById('saveGenProgressWrap');
    if (wrap) {
      return wrap;
    }

    // saveGenPhase2 内に挿入（Phase 1/2 分割後は Phase2 が直接の親）
    const phase2 = document.getElementById('saveGenPhase2');
    if (!phase2) {
      return null;
    }

    wrap = document.createElement('div');
    wrap.id = 'saveGenProgressWrap';
    wrap.className = 'mt-3 d-none';
    wrap.innerHTML =
      '<div class="progress" style="height:8px" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">'
      + '<div id="saveGenProgressBar" class="progress-bar" style="width:0%"></div></div>'
      + '<p id="saveGenProgressLabel" class="small text-muted mb-0 mt-2"></p>'
      + '<p id="saveGenHeartbeat" class="small text-muted mb-0" style="font-size:0.75em"></p>'
      + '<button id="saveGenAbortBtn" type="button" class="btn btn-sm btn-outline-danger mt-2">■ 生成を停止</button>';
    const errEl = phase2.querySelector('#saveGenModalErr');
    if (errEl) {
      phase2.insertBefore(wrap, errEl);
    } else {
      phase2.appendChild(wrap);
    }

    document.getElementById('saveGenAbortBtn')?.addEventListener('click', async () => {
      generateStopRequested = true;
      generateAbortController?.abort();
      try {
        if (currentGenerateJobId) {
          await apiPost('store/job_stop.php', {
            csrf: String(window.LP_CMS?.csrfToken || ''),
            job_id: currentGenerateJobId,
          });
        }
        await fetch('store/abort_generate.php', { method: 'POST' });
      } catch {}
      setSaveGenProgress(0, 1, '停止しました。');
      const abortBtn = document.getElementById('saveGenAbortBtn');
      if (abortBtn) abortBtn.disabled = true;
    });

    return wrap;
  }

  /** @param {number} done @param {number} total @param {string} label */
  function setSaveGenProgress(done, total, label) {
    const wrap = document.getElementById('saveGenProgressWrap');
    const bar = document.getElementById('saveGenProgressBar');
    const outer = wrap?.querySelector('.progress');
    const labelEl = document.getElementById('saveGenProgressLabel');
    const pct = total > 0 ? Math.min(100, Math.round((100 * done) / total)) : 0;
    if (bar) {
      bar.style.width = `${pct}%`;
    }
    if (outer) {
      outer.setAttribute('aria-valuenow', String(pct));
    }
    if (labelEl) {
      labelEl.textContent = label;
    }
  }

  function resetSaveGenerateModal() {
    const errEl = document.getElementById('saveGenModalErr');
    if (errEl) {
      errEl.textContent = '';
      errEl.classList.add('d-none');
    }
    document.getElementById('saveGenFooterBusy')?.classList.remove('d-none');
    document.getElementById('saveGenFooterDone')?.classList.add('d-none');
    setSaveGenRowStatus('saveGenRowSave', 'pending');
    setSaveGenRowStatus('saveGenRowGen', 'pending');
    document.getElementById('saveGenProgressWrap')?.classList.add('d-none');
    setSaveGenProgress(0, 1, '');
    const abortBtn = document.getElementById('saveGenAbortBtn');
    if (abortBtn) abortBtn.disabled = false;
  }

  /** Phase 1（目的入力） → Phase 2（進捗）へ切り替え */
  function switchToProgressPhase() {
    document.getElementById('saveGenPhase1')?.classList.add('d-none');
    document.getElementById('saveGenPhase2')?.classList.remove('d-none');
    document.getElementById('saveGenFooterPhase1')?.classList.add('d-none');
    document.getElementById('saveGenFooterBusy')?.classList.remove('d-none');
  }

  /** モーダルを Phase 1（目的入力）状態でリセット */
  function resetToPhase1() {
    document.getElementById('saveGenPhase1')?.classList.remove('d-none');
    document.getElementById('saveGenPhase2')?.classList.add('d-none');
    document.getElementById('saveGenFooterPhase1')?.classList.remove('d-none');
    document.getElementById('saveGenFooterBusy')?.classList.add('d-none');
    document.getElementById('saveGenFooterDone')?.classList.add('d-none');
    const inp = document.getElementById('saveGenPurposeInput');
    if (inp) { inp.value = ''; inp.disabled = false; }
  }

  /** @param {'pending'|'active'|'done'|'error'} state */
  function setSaveGenRowStatus(rowId, state) {
    const row = document.getElementById(rowId);
    const holder = row?.querySelector('.save-gen-status');
    if (!holder) return;
    const map = {
      pending: '<i class="bi bi-circle text-muted" aria-hidden="true"></i>',
      active:
        '<div class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">処理中</span></div>',
      done: '<i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>',
      error: '<i class="bi bi-x-circle-fill text-danger" aria-hidden="true"></i>',
    };
    holder.innerHTML = map[state] || map.pending;
  }

  function openSaveGenerateModal() {
    const modalEl = document.getElementById('saveGenerateModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    ensureSaveGenProgressUi();
    resetSaveGenerateModal();
    resetToPhase1();
    bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static', keyboard: false }).show();
    // フォーカスを目的入力欄に当てる
    modalEl.addEventListener('shown.bs.modal', function onShown() {
      modalEl.removeEventListener('shown.bs.modal', onShown);
      document.getElementById('saveGenPurposeInput')?.focus();
    });
  }

  function hideSaveGenerateModal() {
    const modalEl = document.getElementById('saveGenerateModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    // Prefer Bootstrap instance hide, but force-clean classes/backdrop as fallback.
    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    window.setTimeout(() => {
      if (modalEl.classList.contains('show')) {
        modalEl.classList.remove('show');
      }
      modalEl.setAttribute('aria-hidden', 'true');
      modalEl.style.display = 'none';
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('padding-right');
      document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove());
    }, 180);
  }

  async function runSaveAndGenerate(purpose) {
    generateAbortController = new AbortController();
    generateStopRequested = false;
    const generateSignal = generateAbortController.signal;
    btnSaveGenerate.disabled = true;
    generateError.classList.add('d-none');
    generateSuccess.classList.add('d-none');

    try {
      // Save current page's form data before generating
      await saveCurrentPageData();

      const clientData = collectFormData();
      ensureSaveGenProgressUi();
      const out = await apiPost('store/generate_start.php', {
        csrf: String(window.LP_CMS?.csrfToken || ''),
        client_data: clientData,
      }, { signal: generateSignal });
      if (!out.ok) {
        throw new Error(typeof out.error === 'string' ? out.error : '生成ジョブ開始に失敗しました。');
      }
      const taskId = String(out.task_id || '');
      if (!taskId) {
        throw new Error('task_id が取得できませんでした。');
      }
      startGeneratePolling(taskId);
    } catch (err) {
      const isAbort = err instanceof DOMException && err.name === 'AbortError';
      const message = isAbort ? '生成を停止しました。' : (err instanceof Error ? err.message : String(err));
      showError(generateError, message);
      const msgEl = document.getElementById('saveGenModalErr');
      if (msgEl) {
        msgEl.textContent = message;
        msgEl.classList.remove('d-none');
      }
      document.getElementById('saveGenProgressWrap')?.classList.add('d-none');
      setSaveGenRowStatus('saveGenRowSave', 'error');
      setSaveGenRowStatus('saveGenRowGen', 'error');
      document.getElementById('saveGenFooterBusy')?.classList.add('d-none');
      document.getElementById('saveGenFooterDone')?.classList.remove('d-none');
      btnSaveGenerate.disabled = false;
    } finally {
      generateAbortController = null;
      generateStopRequested = false;
    }
  }

  function stopGeneratePolling() {
    if (generatePollTimer !== null) {
      window.clearInterval(generatePollTimer);
      generatePollTimer = null;
    }
    if (generateEntryElapsedInterval !== null) {
      window.clearInterval(generateEntryElapsedInterval);
      generateEntryElapsedInterval = null;
    }
  }

  async function tickGenerateProgress(taskId) {
    const q = `?task_id=${encodeURIComponent(taskId)}`;
    const res = await fetch(`store/generate_progress.php${q}`, { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error((data && data.error) ? String(data.error) : (`HTTP ${res.status}`));
    }
    const ws = String(window.LP_CMS?.workspaceName || '');
    const taskWs = String(data.workspace_id || '');
    if (ws && taskWs && ws !== taskWs) {
      throw new Error('別ワークスペースの生成ジョブです。ページを再読み込みして再実行してください。');
    }
    if (typeof data.started_at === 'number' && data.started_at > 0) generateStartedAt = data.started_at;
    if (typeof data.updated_at === 'number' && data.updated_at > 0) generateLastUpdatedAt = data.updated_at;

    const phase = String(data.phase || '');
    const prog = String(data.progress_text || '000/000');
    if (phase === 'save') {
      setSaveGenRowStatus('saveGenRowSave', 'active');
      document.getElementById('saveGenProgressWrap')?.classList.remove('d-none');
      setProgressBarIndeterminate(true);
      const labelEl = document.getElementById('saveGenProgressLabel');
      if (labelEl) labelEl.textContent = '保存中...';
    } else if (phase === 'generate_entry') {
      setSaveGenRowStatus('saveGenRowSave', 'done');
      setSaveGenRowStatus('saveGenRowGen', 'active');
      document.getElementById('saveGenProgressWrap')?.classList.remove('d-none');
      setProgressBarIndeterminate(true);
      if (generateEntryElapsedInterval === null) {
        generateEntryElapsedInterval = window.setInterval(updateGenerateEntryLabel, 1000);
      }
      updateGenerateEntryLabel();
    } else if (phase === 'generate_internal') {
      if (generateEntryElapsedInterval !== null) {
        window.clearInterval(generateEntryElapsedInterval);
        generateEntryElapsedInterval = null;
      }
      document.getElementById('saveGenProgressWrap')?.classList.remove('d-none');
      setProgressBarIndeterminate(false);
      setSaveGenRowStatus('saveGenRowSave', 'done');
      setSaveGenRowStatus('saveGenRowGen', 'active');
      const m = prog.match(/^(\d+)\/(\d+)$/);
      const idleSec = generateLastUpdatedAt > 0 ? Math.floor(Date.now() / 1000 - generateLastUpdatedAt) : -1;
      const hb = document.getElementById('saveGenHeartbeat');
      if (hb) hb.textContent = idleSec >= 0 ? `最終更新 ${idleSec}秒前` : '';
      if (m) {
        setSaveGenProgress(Number(m[1]), Math.max(1, Number(m[2])), '内部ページ生成中…');
      } else {
        setSaveGenProgress(0, 1, '内部ページ生成中…');
      }
    }

    if (data.done === true) {
      stopGeneratePolling();
      btnSaveGenerate.disabled = false;
      const st = String(data.status || '');
      if (st === 'done') {
        setProgressBarIndeterminate(false);
        setSaveGenRowStatus('saveGenRowGen', 'done');
        setSaveGenProgress(1, 1, '生成完了');
        await sleep(350);
        const genModalEl = document.getElementById('saveGenerateModal');
        const genModalWasHidden = !genModalEl?.classList.contains('show');
        hideSaveGenerateModal();
        expandMaxReachable(3);
        tryNavigateToReachedStep(3);
        if (genModalWasHidden) {
          showToast('サイト生成が完了しました！', 'success');
        }
      } else {
        const msg = st === 'stale'
          ? '生成ジョブが応答しなくなりました（stale）。'
          : String(data.error || '生成ジョブが失敗しました。');
        showError(generateError, msg);
        const msgEl = document.getElementById('saveGenModalErr');
        if (msgEl) {
          msgEl.textContent = msg;
          msgEl.classList.remove('d-none');
        }
        setSaveGenRowStatus('saveGenRowGen', 'error');
        document.getElementById('saveGenFooterBusy')?.classList.add('d-none');
        document.getElementById('saveGenFooterDone')?.classList.remove('d-none');
      }
    }
  }

  function startGeneratePolling(taskId) {
    stopGeneratePolling();
    void tickGenerateProgress(taskId).catch((e) => {
      const msg = e instanceof Error ? e.message : String(e);
      showError(generateError, msg);
      btnSaveGenerate.disabled = false;
    });
    generatePollTimer = window.setInterval(() => {
      void tickGenerateProgress(taskId).catch((e) => {
        const msg = e instanceof Error ? e.message : String(e);
        showError(generateError, msg);
        stopGeneratePolling();
        btnSaveGenerate.disabled = false;
      });
    }, 2500);
  }

  function resumeGeneratePollingIfNeeded() {
    void (async () => {
      try {
        const res = await fetch('store/generate_progress.php', { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok || data.exists !== true || data.done === true) return;
        const taskId = String(data.task_id || '');
        const ws = String(window.LP_CMS?.workspaceName || '');
        const taskWs = String(data.workspace_id || '');
        if (ws && taskWs && ws !== taskWs) return;
        if (!taskId) return;
        openSaveGenerateModal();
        startGeneratePolling(taskId);
      } catch {
        void 0;
      }
    })();
  }

  // -----------------------------------------------------------------------
  // AI テキスト自動置換（store/text_replace.php）— cursor_prompts/text_replace_ui.md
  // -----------------------------------------------------------------------
  const AI_TEXT_REPLACE_MAX = 60;
  const AI_TONE_MAP = {
    polite: '丁寧・上品',
    casual: 'カジュアル・親しみやすい',
    professional: 'ビジネス・信頼感',
  };

  function initAiTextReplace() {
    const btn = document.getElementById('ai-replace-btn');
    const undoBtn = document.getElementById('ai-replace-undo');
    const status = document.getElementById('ai-replace-status');
    const closeBtn = document.getElementById('ai-replace-close');
    const headerCloseBtn = document.getElementById('ai-replace-header-close');
    const progressWrap = document.getElementById('ai-replace-progress');
    if (!btn || !status) return;

    function setGenerating(on) {
      btn.disabled = on;
      if (closeBtn) { closeBtn.disabled = on; closeBtn.textContent = on ? '生成中…' : '閉じる'; }
      if (headerCloseBtn) headerCloseBtn.disabled = on;
      if (progressWrap) progressWrap.classList.toggle('d-none', !on);
    }

    /** @type {Record<string, string>|null} */
    let backup = null;

    function findAiField(form, id) {
      const sid = String(id);
      const fields = form.querySelectorAll('[data-lp-field="text"], [data-lp-field="content"]');
      for (let i = 0; i < fields.length; i++) {
        if (fields[i].dataset.lpId === sid) return fields[i];
      }
      return null;
    }

    function setAiStatus(message, kind) {
      status.hidden = false;
      status.textContent = message;
      const cls = {
        muted: 'mt-2 small text-muted',
        danger: 'mt-2 small text-danger',
        warning: 'mt-2 small text-warning',
        success: 'mt-2 small text-success',
      }[kind] || 'mt-2 small text-muted';
      status.className = cls;
    }

    btn.addEventListener('click', async () => {
      const form = document.getElementById('clientDataForm');
      const industryInp = document.getElementById('ai-industry');
      const toneSel = document.getElementById('ai-tone');
      if (!form || !industryInp) return;

      const industry = industryInp.value.trim();
      if (!industry) {
        setAiStatus('業種を入力してください', 'danger');
          showToast('業種を入力してください。', 'warning');
          industryInp.focus();
        return;
      }

      const inputs = form.querySelectorAll(
        '[data-lp-id][data-lp-field="text"], [data-lp-id][data-lp-field="content"]',
      );
      backup = {};
      let elements = [];
      inputs.forEach(el => {
        const id = el.dataset.lpId;
        if (!id) return;
        const val = (el.value || '').trim();
        const ph = (el.placeholder || '').trim();
        const originalText = val || ph;
        if (!originalText) return;
        backup[id] = el.value;
        elements.push({
          id,
          type: el.dataset.lpType || 'paragraph',
          label: el.dataset.lpLabel || '',
          original_text: originalText,
        });
      });

      if (elements.length === 0) {
        setAiStatus('置換対象のテキストがありません（空欄はプレースホルダの元テキストで補完されます）', 'warning');
          showToast('置換対象のテキストがありません', 'warning');
        backup = null;
        return;
      }

      const elementTotalBeforeSlice = elements.length;
      if (elements.length > AI_TEXT_REPLACE_MAX) {
        elements = elements.slice(0, AI_TEXT_REPLACE_MAX);
        const keepIds = new Set(elements.map(e => e.id));
        const trimmedBackup = {};
        keepIds.forEach(id => {
          if (Object.prototype.hasOwnProperty.call(backup, id)) {
            trimmedBackup[id] = backup[id];
          }
        });
        backup = trimmedBackup;
      }

      const toneKey = toneSel && toneSel.value ? toneSel.value : 'polite';
      const tone = AI_TONE_MAP[toneKey] || toneKey;

      setGenerating(true);
      if (elementTotalBeforeSlice > AI_TEXT_REPLACE_MAX) {
        setAiStatus(
          `${elementTotalBeforeSlice}件中 先頭${AI_TEXT_REPLACE_MAX}件を処理します — ${elements.length} 件を AI で生成中…`,
          'muted',
        );
      } else {
        setAiStatus(`${elements.length} 件のテキストを AI で生成中…`, 'muted');
      }

      try {
        const res = await fetch('store/text_replace.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json; charset=utf-8' },
          body: JSON.stringify({ industry, tone, elements }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(data.error || `HTTP ${res.status}`);
        }
        if (!Array.isArray(data.items)) {
          throw new Error('応答形式が不正です');
        }

        let replaced = 0;
        data.items.forEach(item => {
          const el = findAiField(form, item.id);
          if (el && item.replaced_text != null && String(item.replaced_text).trim() !== '') {
            el.value = String(item.replaced_text);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            replaced++;
          }
        });

        setAiStatus(
          `✅ ${replaced} 件を「${industry}」向けに置換しました。確認後、保存してください。`,
          'success',
        );
        showToast(`AI テキスト置換：${replaced} 件`, 'success');
        if (undoBtn) undoBtn.hidden = false;
        if (closeBtn) { closeBtn.textContent = '保存して閉じる'; closeBtn.classList.replace('btn-secondary', 'btn-success'); }
      } catch (e) {
        const msg = e.message || String(e);
        setAiStatus(`❌ エラー: ${msg}`, 'danger');
        showToast(msg, 'danger');
        backup = null;
        if (undoBtn) undoBtn.hidden = true;
      } finally {
        setGenerating(false);
      }
    });

    if (undoBtn) {
      undoBtn.addEventListener('click', () => {
        const form = document.getElementById('clientDataForm');
        if (!backup || !form) return;
        Object.keys(backup).forEach(id => {
          const el = findAiField(form, id);
          if (el) {
            el.value = backup[id];
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
        setAiStatus('元のテキストに戻しました', 'muted');
        showToast('入力を元に戻しました', 'info');
        undoBtn.hidden = true;
        backup = null;
      });
    }

    // モーダルが閉じられたとき閉じるボタンをリセット
    const aiModal = document.getElementById('aiGenerateModal');
    if (aiModal) {
      aiModal.addEventListener('hidden.bs.modal', () => {
        if (closeBtn) {
          closeBtn.disabled = false;
          closeBtn.textContent = '閉じる';
          closeBtn.classList.replace('btn-success', 'btn-secondary');
        }
        if (progressWrap) progressWrap.classList.add('d-none');
      });
    }

    document.querySelectorAll('.ai-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        const inp = document.getElementById('ai-industry');
        const v = chip.dataset.value;
        if (inp && v) {
          inp.value = v;
          inp.focus();
        }
      });
    });
  }

  // -----------------------------------------------------------------------
  // Collect form data into client_data structure
  // -----------------------------------------------------------------------
  function collectFormData() {
    const form = document.getElementById('clientDataForm');
    if (!form) return {};

    const data = { meta: {}, elements: {} };

    // Meta fields — モーダル内（form 外）にあるため document.querySelector で取得
    const titleInput = document.querySelector('[name="meta[title]"]');
    const descInput  = document.querySelector('[name="meta[description]"]');
    if (titleInput && titleInput.value.trim()) data.meta.title       = titleInput.value.trim();
    if (descInput  && descInput.value.trim())  data.meta.description = descInput.value.trim();

    // Element fields — group by data-lp-id
    form.querySelectorAll('[data-lp-id]').forEach(input => {
      const id    = input.dataset.lpId;
      const field = input.dataset.lpField ?? 'text';
      const value = input.value.trim();

      if (!data.elements[id]) {
        data.elements[id] = {};
      }
      const persistEmpty = field === 'image_embedded_text_memo';
      if (value || persistEmpty) {
        data.elements[id][field] = value;
      }
    });

    return data;
  }

  // -----------------------------------------------------------------------
  // Live image preview on URL input
  // -----------------------------------------------------------------------
  function bindImagePreviews() {
    document.querySelectorAll('input[data-lp-field="src"]').forEach(input => {
      input.addEventListener('input', () => {
        const previewId = input.dataset.lpId;
        const img = document.querySelector(`img[data-preview-for="${previewId}"]`);
        if (img && input.value.trim()) {
          img.src = input.value.trim();
        }
      });
    });
  }

  /**
   * 画像URLから CMS ワークスペース相対パス output/ws_xx/... を得る。
   * @param {string} src
   * @returns {string|null}
   */
  function workspaceRelFromImageSrc(src) {
    if (!src || typeof src !== 'string') return null;
    const s = src.trim();
    const relPat = /(output\/ws_[a-f0-9]{32}\/.+?)(?:\?|#|$)/i;
    const m1 = s.match(relPat);
    if (m1) {
      const out = m1[1].replace(/\/+$/, '');
      return out || null;
    }
    try {
      const path = new URL(s, window.location.href).pathname;
      const m2 = path.match(/\/(output\/ws_[a-f0-9]{32}\/.+)$/i);
      if (m2) {
        let p = m2[1];
        const q = p.indexOf('?');
        if (q !== -1) p = p.slice(0, q);
        return p.replace(/\/+$/, '') || null;
      }
    } catch {
      /* ignore */
    }
    return null;
  }

  /**
   * 現在ページの pathname から CMS ディレクトリの絶対URL（末尾 `/`）。
   * document.baseURI が末尾スラッシュ無しだと `/current/lp_reverse_cms` + `output/...`
   * が sibling の `/current/output/...` になり 404（プレビュー寸法読込失敗）になるため使う。
   */
  function cmsRootBaseHref() {
    try {
      let p = window.location.pathname;
      const last = p.split('/').pop() || '';
      if (last && last.includes('.')) {
        p = p.slice(0, p.lastIndexOf('/') + 1);
      } else if (!p.endsWith('/')) {
        p += '/';
      }
      return new URL(p || '/', window.location.origin).href;
    } catch {
      return new URL('./', document.baseURI || window.location.href).href;
    }
  }

  /**
   * CMS がサブディレクトリ配下でも /output/... が正しく解決されるよう CMS ルート基準にする。
   * @param {string} apiPath /output/... または output/...
   * @returns {string}
   */
  function publicUrlFromApiPath(apiPath) {
    if (!apiPath) return '';
    const s = apiPath.trim();
    if (/^https?:\/\//i.test(s) || /^data:/i.test(s)) return s;
    const raw = s.replace(/^\//, '');
    try {
      return new URL(raw, cmsRootBaseHref()).href;
    } catch {
      return s.startsWith('/') ? s : `/${s}`;
    }
  }

  /**
   * <img> 用。本番で output/ws_* を静的配信できない場合は store プロキシを使う。
   * フォームに保存する値は publicUrlFromApiPath のまま（セッション不要のパス）。
   */
  function workspaceImageBrowserHref(apiPath) {
    if (!apiPath) return '';
    const s = apiPath.trim();
    if (/^https?:\/\//i.test(s) || /^data:/i.test(s)) return s;
    const withSlash = s.startsWith('/') ? s : `/${s}`;
    const pref =
      typeof window !== 'undefined' &&
      window.LP_CMS &&
      typeof window.LP_CMS.outputWsPrefix === 'string'
        ? window.LP_CMS.outputWsPrefix.trim()
        : '';
    if (pref && withSlash.startsWith(pref)) {
      const q = `store/serve_workspace_output.php?p=${encodeURIComponent(withSlash)}`;
      try {
        return new URL(q.replace(/^\//, ''), cmsRootBaseHref()).href;
      } catch {
        return publicUrlFromApiPath(withSlash);
      }
    }
    return publicUrlFromApiPath(withSlash);
  }

  /**
   * メモのテキストを Vision のレイアウトに焼き込んだ画像へ差し替え（UI/composite）。
   */
  function bindImageMemoRefine() {
    const form = document.getElementById('clientDataForm');
    if (!form) return;
    form.addEventListener('click', ev => {
      const t = ev.target;
      const btn = t && t.closest ? t.closest('.lp-refine-image-from-memo') : null;
      if (!btn || !form.contains(btn)) return;
      ev.preventDefault();
      const id = btn.dataset.lpId;
      if (!id) return;
      void runImageRefineFromMemo(id, btn);
    });
  }

  /**
   * @param {string} elemId
   * @param {HTMLButtonElement} btnEl
   */
  async function runImageRefineFromMemo(elemId, btnEl) {
    const form = document.getElementById('clientDataForm');
    const industryInp = document.getElementById('ai-industry');
    if (!form || !industryInp) return;
    const srcInp = form.querySelector(`[data-lp-id="${elemId}"][data-lp-field="src"]`);
    const memoTa = form.querySelector(`[data-lp-id="${elemId}"][data-lp-field="image_embedded_text_memo"]`);
    if (!(srcInp instanceof HTMLInputElement) || !(memoTa instanceof HTMLTextAreaElement)) {
      showToast('画像URLまたはメモ欄が見つかりません', 'danger');
      return;
    }
    const rel = workspaceRelFromImageSrc(srcInp.value.trim());
    if (!rel) {
      showToast(
        '画像URLがワークスペース内（output/ws_…）ではありません。解析済みサイトのアセットURLを使ってください。',
        'warning',
      );
      return;
    }
    const memoText = memoTa.value.trim();
    if (!memoText) {
      showToast('メモに焼き込みたい文言を入力してください', 'warning');
      return;
    }
    const industry = industryInp.value.trim();
    if (!industry) {
      showToast('上部の「ターゲット業種」を入力してください', 'warning');
      industryInp.focus();
      return;
    }
    const prevText = btnEl.textContent;
    btnEl.disabled = true;
    btnEl.textContent = '生成中…';
    try {
      showToast('画像パイプラインを実行中…', 'info');
      const pipeRes = await fetch('store/lp_ai_image_pipeline.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify({
          image_local_rel: rel,
          industry,
          memo_text: memoTa.value,
        }),
      });
      const pipeData = await pipeRes.json().catch(() => ({}));
      if (!pipeRes.ok) {
        throw new Error(pipeData.error || `pipeline HTTP ${pipeRes.status}`);
      }

      let finalUrl = typeof pipeData.url === 'string' ? pipeData.url : '';
      if (pipeData.outcome === 'needs_composite' && pipeData.image_composite_post_body) {
        const compRes = await fetch('store/image_composite.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json; charset=utf-8' },
          body: JSON.stringify(pipeData.image_composite_post_body),
        });
        const compData = await compRes.json().catch(() => ({}));
        if (!compRes.ok) {
          throw new Error(compData.error || `composite HTTP ${compRes.status}`);
        }
        finalUrl = typeof compData.url === 'string' ? compData.url : '';
      }

      if (!finalUrl) {
        throw new Error(pipeData.error || pipeData.note || '画像URLを取得できませんでした');
      }
      srcInp.value = publicUrlFromApiPath(finalUrl);
      srcInp.dispatchEvent(new Event('input', { bubbles: true }));
      srcInp.dispatchEvent(new Event('change', { bubbles: true }));
      showToast('画像を更新しました。保存してからサイト生成してください', 'success');
    } catch (e) {
      showToast(String(e.message || e), 'danger');
    } finally {
      btnEl.disabled = false;
      btnEl.textContent = prevText;
    }
  }

  /**
   * 画像URLの手動置き換え（モーダル・ドラッグ＆ドロップ／ローカルアップロード）。
   */
  function bindImageReplaceModal() {
    const modalEl = document.getElementById('imageReplaceModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      return;
    }
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const leftImg = document.getElementById('imageReplaceModalLeft');
    const rightImg = document.getElementById('imageReplaceModalRight');
    const rightPh = document.getElementById('imageReplaceRightPlaceholder');
    const dropzone = document.getElementById('imageReplaceDropzone');
    const fileInp = document.getElementById('imageReplaceFile');
    const pickBtn = document.getElementById('imageReplacePickFile');
    const applyBtn = document.getElementById('imageReplaceApply');
    const dimsLeftEl = document.getElementById('imageReplaceDimsLeft');
    const dimsRightEl = document.getElementById('imageReplaceDimsRight');
    const phSameSizeBtn = document.getElementById('imagePlaceholderSameSize');
    const phSameSizeLabel = document.getElementById('imagePlaceholderSameSizeLabel');
    const phPresetsEl = document.getElementById('imagePlaceholderPresets');
    const phBlendInput = document.getElementById('phBlendOpacityInput');
    const phBlendStatus = document.getElementById('phBlendStatus');

    const PH_BLEND_KEY     = 'lp_ph_blend_opacity';
    const PH_LAST_SIZE_KEY = 'lp_ph_last_size';

    function saveLastPhSize(w, h) {
      try { localStorage.setItem(PH_LAST_SIZE_KEY, JSON.stringify({ w, h })); } catch {}
    }
    function loadLastPhSize() {
      try { return JSON.parse(localStorage.getItem(PH_LAST_SIZE_KEY) || '{}'); } catch { return {}; }
    }
    const PH_PRESETS = [
      [100, 100], [150, 150], [200, 200], [300, 300],
      [200, 150], [300, 200], [400, 300], [600, 400],
      [800, 600], [1200, 630], [1920, 1080],
    ];

    /** localStorage からモック濃度(0〜1)を読む */
    function getPhBlendOpacity() {
      const v = parseFloat(localStorage.getItem(PH_BLEND_KEY) ?? '');
      return (isFinite(v) && v >= 0 && v <= 1) ? v : 0.70;
    }
    function savePhBlendOpacity(ratio) {
      localStorage.setItem(PH_BLEND_KEY, String(Math.max(0, Math.min(1, ratio))));
    }

    // 入力欄を localStorage の値で初期化し、変更時に保存
    if (phBlendInput) {
      phBlendInput.value = String(Math.round(getPhBlendOpacity() * 100));
      phBlendInput.addEventListener('change', () => {
        const v = Math.max(0, Math.min(100, parseInt(phBlendInput.value || '70', 10)));
        phBlendInput.value = String(v);
        savePhBlendOpacity(v / 100);
        if (lastPhW > 0 && lastPhH > 0) void selectPlaceholder(lastPhW, lastPhH);
      });
    }

    let targetElemId = '';
    /** @type {string} */
    let selectedPath = '';
    /** @type {number} */
    let origW = 0;
    /** @type {number} */
    let origH = 0;
    /** 最後に選択したプレースホルダーサイズ（モック濃度変更時の再ブレンド用） */
    let lastPhW = 0;
    let lastPhH = 0;
    /** 現在の右ペイン選択がプレースホルダー（data: URL）かどうか */
    let rightIsPlaceholder = false;
    /** @type {string} モーダル表示中の元画像 displayURL */
    let origDisplayUrl = '';
    /** @type {string} モーダル表示中の元画像ファイル名（プレースホルダー表示用） */
    let origFilename = '';

    /** @param {HTMLImageElement|null} img */
    function formatImgPxDimsLine(img) {
      if (!img || !img.src) return 'サイズ：—';
      if (!img.complete) return 'サイズ：読み込み中…';
      const w = img.naturalWidth;
      const h = img.naturalHeight;
      if (w <= 0 || h <= 0) return 'サイズ：（px を取得できません／非ラスタ画像など）';
      return `サイズ：幅 ${w}px × 高さ ${h}px`;
    }

    /**
     * @param {HTMLImageElement|null} imgEl
     * @param {HTMLElement|null} dimsEl
     * @param {string} absUrl 空なら src を外して寸法をリセット
     */
    function wireImgDimsReporting(imgEl, dimsEl, absUrl) {
      if (!dimsEl || !imgEl) return;
      // 前回のリトライタイマーをキャンセル（サイズ変更を連続して行うと古いURLで上書きされるバグの修正）
      if (imgEl._wireRetryTimer != null) {
        clearTimeout(imgEl._wireRetryTimer);
        imgEl._wireRetryTimer = null;
      }
      const u = (absUrl || '').trim();
      imgEl.onload = null;
      imgEl.onerror = null;
      if (!u) {
        dimsEl.textContent = 'サイズ：—';
        imgEl.removeAttribute('src');
        return;
      }
      dimsEl.textContent = 'サイズ：読み込み中…';
      const apply = () => { dimsEl.textContent = formatImgPxDimsLine(imgEl); };
      let retries = 0;
      const onErr = () => {
        if (retries < 2) {
          retries++;
          const sep = u.includes('?') ? '&' : '?';
          imgEl._wireRetryTimer = setTimeout(() => {
            imgEl._wireRetryTimer = null;
            imgEl.src = u + sep + '_r=' + retries;
          }, 1500);
        } else {
          dimsEl.textContent = 'サイズ：読み込みに失敗しました';
        }
      };
      imgEl.onload = apply;
      imgEl.onerror = onErr;
      imgEl.src = u;
      if (typeof imgEl.decode === 'function') {
        void imgEl.decode().then(apply).catch(() => {});
      }
    }

    function buildPlaceholderUrl(w, h) {
      return `https://placehold.jp/${w}x${h}.png`;
    }

    /**
     * img 要素を crossOrigin='anonymous' で読み込み、load/error を Promise 化。
     * @param {string} url
     * @returns {Promise<HTMLImageElement>}
     */
    function loadImgCors(url) {
      return new Promise((resolve, reject) => {
        let retries = 0;
        function tryLoad(src) {
          const img = new Image();
          img.crossOrigin = 'anonymous';
          img.onload = () => resolve(img);
          img.onerror = () => {
            if (retries < 2) {
              retries++;
              const sep = url.includes('?') ? '&' : '?';
              setTimeout(() => tryLoad(url + sep + '_r=' + retries), 1500);
            } else {
              reject(new Error('load failed: ' + url));
            }
          };
          img.src = src;
        }
        tryLoad(url);
      });
    }

    /**
     * Canvas でプレースホルダーをローカル生成（placehold.jp の CORS 問題を回避）。
     * @param {number} w @param {number} h @param {string} [filename]
     * @returns {HTMLCanvasElement}
     */
    function drawLocalPlaceholder(w, h, filename = '') {
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      const ctx = c.getContext('2d');
      ctx.fillStyle = '#2d3134';
      ctx.fillRect(0, 0, w, h);
      const baseFontSize = Math.max(11, Math.min(Math.floor(Math.min(w, h) / 7), 48));
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';

      // ファイル名が画面幅に収まるか確認。収まらなければ省略または非表示
      const maxTextW = w * 0.88;
      let displayFilename = '';
      if (filename) {
        ctx.font = `bold ${baseFontSize}px sans-serif`;
        if (ctx.measureText(filename).width <= maxTextW) {
          displayFilename = filename;
        } else {
          // 末尾を「…」で切り詰めて収まる長さを二分探索
          let lo = 0, hi = filename.length;
          while (lo < hi - 1) {
            const mid = (lo + hi) >> 1;
            if (ctx.measureText(filename.slice(0, mid) + '…').width <= maxTextW) lo = mid;
            else hi = mid;
          }
          // 切り詰め後も極端に短い（3文字未満）なら表示しない
          displayFilename = lo >= 3 ? filename.slice(0, lo) + '…' : '';
        }
      }

      const lines = displayFilename
        ? [{ text: displayFilename, size: baseFontSize, color: '#e6e8eb' },
           { text: `${w}×${h}`,    size: Math.round(baseFontSize * 0.72), color: '#969ca2' }]
        : [{ text: `${w}×${h}`,    size: baseFontSize, color: '#969ca2' }];
      const lineH = baseFontSize * 1.5;
      const totalH = lines.length * lineH;
      const y0 = (h - totalH) / 2 + lineH / 2;
      lines.forEach((l, i) => {
        ctx.font = `bold ${l.size}px sans-serif`;
        ctx.fillStyle = l.color;
        ctx.fillText(l.text, w / 2, y0 + i * lineH);
      });
      return c;
    }

    /**
     * 元画像(100%) の上にプレースホルダーを mockAlpha で重ねた DataURL を返す。
     * @param {number} phW @param {number} phH
     * @param {string} origSrc   元画像 URL（CORS 失敗時はグレー背景で代替）
     * @param {number} mockAlpha モック画像の不透明度 0〜1
     * @param {string} [filename] プレースホルダーに表示するファイル名
     * @returns {Promise<string>} DataURL
     */
    async function blendPlaceholder(phW, phH, origSrc, mockAlpha, filename = '') {
      const canvas = document.createElement('canvas');
      canvas.width  = phW;
      canvas.height = phH;
      const ctx = canvas.getContext('2d');

      // ① 元画像を 100% で下地として描画
      if (origSrc) {
        try {
          // Same-origin URLs (serve_workspace_output.php etc.) are loaded directly —
          // proxying through img_proxy.php would strip the session cookie and return 403.
          // External URLs are routed through the server-side proxy to satisfy CORS.
          let corsUrl = origSrc;
          if (/^https?:\/\//i.test(origSrc)) {
            try {
              const isSameOrigin = new URL(origSrc).origin === window.location.origin;
              if (!isSameOrigin) {
                corsUrl = 'store/img_proxy.php?url=' + encodeURIComponent(origSrc);
              }
            } catch (_) {
              corsUrl = 'store/img_proxy.php?url=' + encodeURIComponent(origSrc);
            }
          }
          const origImg = await loadImgCors(corsUrl);
          ctx.globalAlpha = 1;
          ctx.drawImage(origImg, 0, 0, phW, phH);
        } catch (_) {
          // CORS 不可 → グレー背景で代替
          ctx.fillStyle = '#999999';
          ctx.fillRect(0, 0, phW, phH);
        }
      } else {
        ctx.fillStyle = '#999999';
        ctx.fillRect(0, 0, phW, phH);
      }

      // ② モック画像を mockAlpha で上から重ねる
      ctx.globalAlpha = mockAlpha;
      ctx.drawImage(drawLocalPlaceholder(phW, phH, filename), 0, 0, phW, phH);
      ctx.globalAlpha = 1;

      return canvas.toDataURL('image/png');
    }

    async function selectPlaceholder(w, h) {
      // 右ペインにユーザーがアップロードした実画像がある場合のみ確認
      if (selectedPath && !rightIsPlaceholder) {
        if (!confirm('現在選択中の画像をモックアップに置き換えますか？')) return;
      }
      lastPhW = w;
      lastPhH = h;
      saveLastPhSize(w, h);
      if (phBlendStatus) phBlendStatus.classList.remove('d-none');
      try {
        const alpha = getPhBlendOpacity();
        const dataUrl = await blendPlaceholder(w, h, origDisplayUrl, alpha, origFilename);
        setRightSelection(dataUrl, true);
      } catch (e) {
        showToast('合成に失敗しました: ' + String(e.message || e), 'danger');
      } finally {
        if (phBlendStatus) phBlendStatus.classList.add('d-none');
      }
    }

    function renderPlaceholderSection(nw, nh) {
      origW = nw;
      origH = nh;

      if (phSameSizeBtn && phSameSizeLabel) {
        if (nw > 0 && nh > 0) {
          phSameSizeLabel.textContent = `同サイズで挿入 (${nw}×${nh})`;
          phSameSizeBtn.disabled = false;
          phSameSizeBtn.dataset.phW = String(nw);
          phSameSizeBtn.dataset.phH = String(nh);
        } else {
          phSameSizeLabel.textContent = '同サイズで挿入';
          phSameSizeBtn.disabled = true;
        }
      }

      if (phPresetsEl) {
        phPresetsEl.innerHTML = '';
        PH_PRESETS.forEach(([w, h]) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-xs btn-outline-secondary lp-ph-btn';
          btn.style.fontSize = '0.72em';
          btn.style.padding = '1px 6px';
          btn.textContent = `${w}×${h}`;
          btn.dataset.phW = String(w);
          btn.dataset.phH = String(h);
          phPresetsEl.appendChild(btn);
        });
      }
    }

    function resolveDisplayUrl(pathOrUrl) {
      const s = (pathOrUrl || '').trim();
      if (!s) return '';
      if (/^https?:\/\//i.test(s) || /^data:/i.test(s)) return s;
      return workspaceImageBrowserHref(s.startsWith('/') ? s : `/${s}`);
    }

    function setRightSelection(path, isPlaceholder = false) {
      selectedPath = (path || '').trim();
      rightIsPlaceholder = isPlaceholder;
      const url = resolveDisplayUrl(selectedPath);
      if (url && rightImg && rightPh) {
        wireImgDimsReporting(rightImg, dimsRightEl, url);
        rightImg.classList.remove('d-none');
        rightPh.classList.add('d-none');
        if (applyBtn) applyBtn.disabled = false;
      } else {
        selectedPath = '';
        wireImgDimsReporting(rightImg, dimsRightEl, '');
        rightImg?.classList.add('d-none');
        if (rightPh) rightPh.classList.remove('d-none');
        if (applyBtn) applyBtn.disabled = true;
      }
    }

    function resetRight() {
      setRightSelection('');
    }

    /**
     * @param {File} file
     */
    async function uploadFile(file) {
      const name = ((file && file.name) || '').toLowerCase();
      const byType = !!(file && file.type && file.type.startsWith('image/'));
      const byExt = /\.(jpe?g|png|gif|webp|avif|svg)$/i.test(name) || (file?.type === 'image/svg+xml');
      if (!file || (!byType && !byExt)) {
        showToast('画像ファイルを選んでください', 'warning');
        return;
      }
      const fd = new FormData();
      fd.append('image', file);
      try {
        const res = await fetch('store/upload_user_image.php', { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          throw new Error(data.error || `HTTP ${res.status}`);
        }
        setRightSelection(typeof data.path === 'string' ? data.path : '');
        showToast('アップロードしました', 'success');
      } catch (e) {
        showToast(String(e.message || e), 'danger');
      }
    }

    document.body.addEventListener('click', ev => {
      const t = ev.target;
      const btn = t && t.closest ? t.closest('.lp-open-image-replace') : null;
      if (!btn) return;
      ev.preventDefault();
      const form = document.getElementById('clientDataForm');
      if (!form || !form.contains(btn)) return;
      targetElemId = btn.dataset.lpId || '';
      const rollbackSrc = (btn.getAttribute('data-lp-rollback-src') || '').trim();
      const orig        = (btn.getAttribute('data-lp-original-src') || '').trim();
      const srcInp      = form.querySelector(`[data-lp-id="${targetElemId}"][data-lp-field="src"]`);

      // 左ペイン = rollback（不変のオリジナル）。
      // rollback_src は "assets/rollback/xxx.jpg" 形式のワークスペース相対パス。
      // outputWsPrefix（例: /lp_reverse_cms/output/ws_xxx）と結合してプロキシ URL を生成。
      // なければ data-lp-original-src（絶対URL）をそのまま使う。
      let leftSrc = orig;
      if (rollbackSrc) {
        const wsPrefix = (window.LP_CMS && window.LP_CMS.outputWsPrefix)
          ? window.LP_CMS.outputWsPrefix.replace(/\/+$/, '')
          : '';
        const fullRbPath = wsPrefix
          ? wsPrefix + '/' + rollbackSrc.replace(/^\//, '')
          : '/' + rollbackSrc.replace(/^\//, '');
        leftSrc = workspaceImageBrowserHref(fullRbPath);
      }

      // 右ペイン = 現在の置き換え済み画像（client_data.src）。なければ空。
      const currentOverride = (srcInp && srcInp.value && srcInp.value.trim()) ? srcInp.value.trim() : '';

      origDisplayUrl = '';
      origFilename = orig ? (orig.split('/').pop().split('?')[0] || '') : '';
      lastPhW = 0;
      lastPhH = 0;
      rightIsPlaceholder = false;
      // currentOverride が data: URL（プレースホルダー合成済み）の場合、フラグとサイズを復元
      const overrideIsDataUrl = /^data:image\//i.test(currentOverride);
      if (overrideIsDataUrl) {
        rightIsPlaceholder = true;
        const saved = loadLastPhSize();
        if ((saved.w | 0) > 0 && (saved.h | 0) > 0) {
          lastPhW = saved.w;
          lastPhH = saved.h;
        }
      }
      renderPlaceholderSection(0, 0);
      if (leftImg) {
        if (leftSrc) {
          const displayUrl = resolveDisplayUrl(leftSrc);
          origDisplayUrl = displayUrl;
          leftImg.onload = null;
          leftImg.onerror = null;
          dimsLeftEl && (dimsLeftEl.textContent = 'サイズ：読み込み中…');
          const applyDims = () => {
            if (dimsLeftEl) dimsLeftEl.textContent = formatImgPxDimsLine(leftImg);
            renderPlaceholderSection(leftImg.naturalWidth, leftImg.naturalHeight);
          };
          leftImg.onload = applyDims;
          let leftRetries = 0;
          leftImg.onerror = () => {
            if (leftRetries < 2) {
              leftRetries++;
              const sep = displayUrl.includes('?') ? '&' : '?';
              setTimeout(() => { leftImg.src = displayUrl + sep + '_r=' + leftRetries; }, 1500);
            } else {
              if (dimsLeftEl) dimsLeftEl.textContent = 'サイズ：読み込みに失敗しました';
            }
          };
          leftImg.src = displayUrl;
          if (typeof leftImg.decode === 'function') {
            void leftImg.decode().then(applyDims).catch(() => {});
          }
        } else {
          wireImgDimsReporting(leftImg, dimsLeftEl, '');
        }
      }
      // 右ペイン：既存の置き換え画像があれば初期表示（ユーザーが再確認できる）
      if (currentOverride) {
        setRightSelection(currentOverride, overrideIsDataUrl);
      } else {
        resetRight();
      }
      modal.show();
    });

    modalEl.addEventListener('click', ev => {
      const btn = ev.target && ev.target.closest ? ev.target.closest('.lp-ph-btn') : null;
      if (!btn) return;
      const w = parseInt(btn.dataset.phW || '0', 10);
      const h = parseInt(btn.dataset.phH || '0', 10);
      if (w > 0 && h > 0) selectPlaceholder(w, h);
    });

    pickBtn?.addEventListener('click', () => fileInp?.click());
    fileInp?.addEventListener('change', () => {
      const f = fileInp.files && fileInp.files[0];
      if (f) void uploadFile(f);
      fileInp.value = '';
    });

    ['dragenter', 'dragover'].forEach(evt => {
      dropzone?.addEventListener(evt, e => {
        e.preventDefault();
        e.stopPropagation();
      });
    });
    dropzone?.addEventListener('dragleave', e => {
      e.preventDefault();
      e.stopPropagation();
    });
    dropzone?.addEventListener('drop', e => {
      e.preventDefault();
      e.stopPropagation();
      const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) void uploadFile(f);
    });

    applyBtn?.addEventListener('click', () => {
      const form = document.getElementById('clientDataForm');
      if (!form || !targetElemId || !selectedPath) return;
      const srcInp = form.querySelector(`[data-lp-id="${targetElemId}"][data-lp-field="src"]`);
      if (!(srcInp instanceof HTMLInputElement)) return;
      srcInp.value = publicUrlFromApiPath(selectedPath);
      srcInp.dispatchEvent(new Event('input', { bubbles: true }));
      srcInp.dispatchEvent(new Event('change', { bubbles: true }));
      modal.hide();
      showToast('画像URLを更新しました', 'success');
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
      targetElemId = '';
      origDisplayUrl = '';
      origFilename = '';
      resetRight();
      wireImgDimsReporting(leftImg, dimsLeftEl, '');
      renderPlaceholderSection(0, 0);
    });

    // -----------------------------------------------------------------------
    // 画像毎ロールバックボタン（.lp-rollback-image）
    // -----------------------------------------------------------------------
    document.getElementById('clientDataForm')?.addEventListener('click', ev => {
      const btn = ev.target?.closest?.('.lp-rollback-image');
      if (!btn) return;
      const elemId = (btn.dataset.lpId || '').trim();
      if (!elemId) return;
      const form = document.getElementById('clientDataForm');
      const srcInp = form?.querySelector(`[data-lp-id="${elemId}"][data-lp-field="src"]`);
      if (!(srcInp instanceof HTMLInputElement)) return;
      srcInp.value = '';
      srcInp.dispatchEvent(new Event('input', { bubbles: true }));
      srcInp.dispatchEvent(new Event('change', { bubbles: true }));
      btn.closest('.lp-rollback-wrap')?.classList.add('d-none');
      showToast('画像をロールバックしました', 'success');
    });

    // -----------------------------------------------------------------------
    // 一括ブレンド
    // -----------------------------------------------------------------------
    async function batchBlendAllImages() {
      const form = document.getElementById('clientDataForm');
      if (!form) return;
      const buttons = Array.from(form.querySelectorAll('.lp-open-image-replace'));
      const total = buttons.length;
      if (total === 0) { showToast('編集画面に画像が見つかりません', 'warning'); return; }
      const batchBtn = document.getElementById('btnBatchBlend');
      if (batchBtn) batchBtn.disabled = true;
      const alpha = getPhBlendOpacity();
      const wsPrefix = (window.LP_CMS && window.LP_CMS.outputWsPrefix)
        ? window.LP_CMS.outputWsPrefix.replace(/\/+$/, '')
        : '';
      let done = 0, failed = 0;
      showToast(`全画像ブレンド開始（${total}件）...`, 'info');
      for (const editBtn of buttons) {
        const elemId = editBtn.getAttribute('data-lp-id') || '';
        const rollbackSrc = (editBtn.getAttribute('data-lp-rollback-src') || '').trim();
        const origSrcAttr = (editBtn.getAttribute('data-lp-original-src') || '').trim();
        if (!elemId) { failed++; continue; }
        const srcInp = form.querySelector(`[data-lp-id="${CSS.escape(elemId)}"][data-lp-field="src"]`);
        if (!(srcInp instanceof HTMLInputElement)) { failed++; continue; }

        // モーダルと同じURL構築: rollback_src → wsPrefix結合、なければ original-src
        let displayUrl = '';
        let filename = '';
        if (rollbackSrc) {
          const fullRbPath = wsPrefix
            ? wsPrefix + '/' + rollbackSrc.replace(/^\//, '')
            : '/' + rollbackSrc.replace(/^\//, '');
          displayUrl = workspaceImageBrowserHref(fullRbPath);
          filename = origSrcAttr.split('/').pop().split('?')[0] || rollbackSrc.split('/').pop().split('?')[0] || '';
        } else if (origSrcAttr) {
          displayUrl = resolveDisplayUrl(origSrcAttr);
          filename = origSrcAttr.split('/').pop().split('?')[0] || '';
        }
        if (!displayUrl) { failed++; continue; }

        try {
          // blendPlaceholder と同じ CORS ルーティング（外部URL → img_proxy）
          let corsUrl = displayUrl;
          if (/^https?:\/\//i.test(displayUrl)) {
            try {
              if (new URL(displayUrl).origin !== window.location.origin) {
                corsUrl = 'store/img_proxy.php?url=' + encodeURIComponent(displayUrl);
              }
            } catch (_) {
              corsUrl = 'store/img_proxy.php?url=' + encodeURIComponent(displayUrl);
            }
          }

          // loadImgCors で1回だけロード（二重ロードによるキャッシュ競合を排除）
          const origImg = await loadImgCors(corsUrl);
          const w = origImg.naturalWidth;
          const h = origImg.naturalHeight;
          if (w < 1 || h < 1) { failed++; continue; }

          // blendPlaceholder と同じ canvas 合成（ロード済み image を再利用）
          const canvas = document.createElement('canvas');
          canvas.width = w;
          canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.globalAlpha = 1;
          ctx.drawImage(origImg, 0, 0, w, h);
          ctx.globalAlpha = alpha;
          ctx.drawImage(drawLocalPlaceholder(w, h, filename), 0, 0, w, h);
          ctx.globalAlpha = 1;
          const dataUrl = canvas.toDataURL('image/png');

          srcInp.value = dataUrl;
          srcInp.dispatchEvent(new Event('input', { bubbles: true }));
          srcInp.dispatchEvent(new Event('change', { bubbles: true }));
          done++;
        } catch (err) {
          console.warn('[batchBlend] failed:', elemId, displayUrl, err);
          failed++;
        }
      }
      if (batchBtn) batchBtn.disabled = false;
      showToast(
        failed > 0
          ? `ブレンド完了: ${done}件 成功、${failed}件 失敗`
          : `全${done}件のブレンドが完了しました`,
        failed > 0 ? 'warning' : 'success'
      );
    }

    document.getElementById('btnBatchBlend')?.addEventListener('click', () => void batchBlendAllImages());
  }

  // -----------------------------------------------------------------------
  // エラーログモーダル
  // -----------------------------------------------------------------------
  (function () {
    const modalEl = document.getElementById('errorLogModal');
    if (!modalEl) return;

    const tbody      = document.getElementById('errorLogTableBody');
    const statusEl   = document.getElementById('errorLogStatus');
    const copyBtn    = document.getElementById('errorLogCopyBtn');
    const refreshBtn = document.getElementById('errorLogRefresh');
    const modal      = new bootstrap.Modal(modalEl);

    let allEvents = [];

    function fmtTs(ts) {
      if (!ts) return '—';
      try { return new Date(ts).toLocaleString('ja-JP', { hour12: false }); } catch { return ts; }
    }

    function kindOf(ev) {
      if ((ev.operation || '') === 'image_load_retry') return 'retry';
      if (!ev.ok) return 'error';
      return 'other';
    }

    function renderRows(events) {
      if (!tbody) return;
      if (!events.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">該当するログがありません</td></tr>';
        return;
      }
      tbody.innerHTML = events.map(ev => {
        const kind = kindOf(ev);
        const rowCls = kind === 'retry'
          ? (ev.ok || (ev.meta && ev.meta.recovered) ? 'table-warning' : 'table-danger')
          : (!ev.ok ? 'table-danger' : '');
        const badge = kind === 'retry'
          ? `<span class="badge bg-warning text-dark">リトライ</span>`
          : `<span class="badge bg-danger">エラー</span>`;
        const meta = ev.meta || {};
        let detail = ev.operation || '';
        if (meta.src)          detail += `<br><span class="text-muted font-monospace" style="word-break:break-all">${escHtml(meta.src)}</span>`;
        if (meta.error_message) detail += `<br><span class="text-danger">${escHtml(meta.error_message)}</span>`;
        if (meta.retry != null) detail += `<br>試行: ${meta.retry}回`;
        const result = kind === 'retry'
          ? (meta.recovered ? '<span class="text-success">回復</span>' : '<span class="text-danger">失敗</span>')
          : `<span class="text-danger">HTTP ${ev.http_code || '—'}</span>`;
        return `<tr class="${rowCls}"><td style="white-space:nowrap">${escHtml(fmtTs(ev.ts))}</td><td>${badge}</td><td>${detail}</td><td>${result}</td></tr>`;
      }).join('');
    }

    function escHtml(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function applyFilter() {
      const v = document.querySelector('input[name="errFilter"]:checked')?.value || 'all';
      const filtered = allEvents.filter(ev => {
        if (v === 'all')   return true;
        if (v === 'retry') return (ev.operation || '') === 'image_load_retry';
        if (v === 'error') return !ev.ok;
        return true;
      });
      renderRows(filtered);
    }

    async function loadLog() {
      if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">読み込み中…</td></tr>';
      if (statusEl) statusEl.textContent = '';
      try {
        const res  = await fetch('store/api_error_log.php');
        const data = await res.json().catch(() => ({}));
        allEvents  = Array.isArray(data.events) ? data.events : [];
        if (statusEl) statusEl.textContent = `${allEvents.length} 件（新しい順）`;
        applyFilter();
      } catch (e) {
        if (tbody) tbody.innerHTML = `<tr><td colspan="4" class="text-danger py-3">${String(e)}</td></tr>`;
      }
    }

    document.getElementById('openErrorLogModal')?.addEventListener('click', () => {
      modal.show();
      void loadLog();
    });

    refreshBtn?.addEventListener('click', () => void loadLog());

    modalEl.querySelectorAll('input[name="errFilter"]').forEach(r => r.addEventListener('change', applyFilter));

    copyBtn?.addEventListener('click', () => {
      const lines = allEvents.map(ev => {
        const meta = ev.meta || {};
        return [
          fmtTs(ev.ts),
          ev.operation || '',
          ev.ok ? 'OK' : 'NG',
          `HTTP:${ev.http_code || 0}`,
          meta.src || '',
          meta.error_message || '',
          meta.retry != null ? `retry:${meta.retry}` : '',
          meta.recovered != null ? `recovered:${meta.recovered}` : '',
        ].filter(Boolean).join('\t');
      });
      navigator.clipboard.writeText(lines.join('\n')).then(() => {
        showToast('ログをコピーしました', 'success');
      }).catch(() => {
        showToast('コピーに失敗しました', 'warning');
      });
    });
  }());

  // -----------------------------------------------------------------------
  // Reset client data
  // -----------------------------------------------------------------------
  function resetClientData() {
    if (!confirm('入力した内容をすべてリセットしますか？')) return;
    const form = document.getElementById('clientDataForm');
    if (form) {
      form.querySelectorAll('input, textarea').forEach(el => { el.value = ''; });
    }
    showToast('リセットしました。', 'info');
  }

  // -----------------------------------------------------------------------
  // Utilities
  // -----------------------------------------------------------------------
  /**
   * API 応答 JSON の読み取り。途中切断/空レスポンス時に生の SyntaxError を出さない。
   * @param {Response} res
   * @param {string} endpoint
   * @returns {Promise<Record<string, unknown>>}
   */
  async function parseApiJsonResponse(res, endpoint) {
    const raw = await res.text();
    if (!raw || !raw.trim()) {
      throw new Error(`サーバー応答が空です（${endpoint}）`);
    }
    try {
      return /** @type {Record<string, unknown>} */ (JSON.parse(raw));
    } catch {
      const brief = raw.slice(0, 160).replace(/\s+/g, ' ').trim();
      throw new Error(
        `サーバー応答のJSONが途中で壊れています（${endpoint}）`
        + (brief ? `: ${brief}` : ''),
      );
    }
  }

  function resolveCurrentWorkspaceId() {
    const fromName = (window.LP_CMS?.workspaceName || '').trim();
    if (/^ws_[a-f0-9]{32}$/.test(fromName)) return fromName;
    const m = String(window.LP_CMS?.outputWsPrefix || '').match(/ws_[a-f0-9]{32}/i);
    return m ? m[0].toLowerCase() : '';
  }

  async function startManagedJob(type, purpose, sourceUrl = '') {
    const csrf = String(window.LP_CMS?.csrfToken || '');
    const ws = resolveCurrentWorkspaceId();
    const res = await apiPost('store/job_start.php', {
      csrf,
      type,
      purpose,
      source_url: sourceUrl,
      workspace_id: ws,
    });
    const job = (res && typeof res === 'object' && res.job && typeof res.job === 'object') ? res.job : null;
    const id = job && typeof job.id === 'string' ? job.id : '';
    if (!id) throw new Error('job_id の取得に失敗しました。');
    return id;
  }

  async function finishManagedJob(jobId, status, message = '', result = null) {
    if (!jobId) return;
    try {
      await apiPost('store/job_finish.php', {
        csrf: String(window.LP_CMS?.csrfToken || ''),
        job_id: jobId,
        status,
        error: message || null,
        result: result && typeof result === 'object' ? result : null,
      });
    } catch {}
  }

  async function apiPost(endpoint, data, options = {}) {
    const timeoutMs = Number.isFinite(options.timeoutMs) ? Number(options.timeoutMs) : 0;
    const externalSignal = options.signal instanceof AbortSignal ? options.signal : null;
    const controller = (timeoutMs > 0 || externalSignal) ? new AbortController() : null;
    if (controller && externalSignal) {
      if (externalSignal.aborted) {
        controller.abort();
      } else {
        externalSignal.addEventListener('abort', () => controller.abort(), { once: true });
      }
    }
    const timer = (controller && timeoutMs > 0)
      ? setTimeout(() => controller.abort(new DOMException('timeout', 'AbortError')), timeoutMs)
      : null;

    let res;
    try {
      res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      body: JSON.stringify(data),
      signal: controller ? controller.signal : undefined,
    });
    } catch (e) {
      if (controller && (e instanceof DOMException) && e.name === 'AbortError' && timeoutMs > 0) {
        throw new Error(`タイムアウトしました（${endpoint} / ${Math.floor(timeoutMs / 1000)}秒）`);
      }
      throw e;
    } finally {
      if (timer) clearTimeout(timer);
    }

    const json = await parseApiJsonResponse(res, endpoint);
    if (!res.ok) {
      throw new Error(
        (typeof json.error === 'string' && json.error.trim() !== '')
          ? json.error
          : `HTTP ${res.status} (${endpoint})`,
      );
    }
    return json;
  }

  /**
   * GET 系API（site_map 一覧など）
   * @param {string} endpoint
   * @returns {Promise<Record<string, unknown>>}
   */
  async function apiGet(endpoint) {
    const res = await fetch(endpoint, {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });
    const json = await parseApiJsonResponse(res, endpoint);
    if (!res.ok) {
      throw new Error(
        (typeof json.error === 'string' && json.error.trim() !== '')
          ? json.error
          : `HTTP ${res.status} (${endpoint})`,
      );
    }
    return json;
  }

  function showError(el, message) {
    el.textContent = message;
    el.classList.remove('d-none');
  }

  function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  /**
   * 経過時間ベースの ETA を表示する。
   * @param {number} startMs
   * @param {number} done
   * @param {number} total
   * @returns {string}
   */
  function etaString(startMs, done, total) {
    if (!Number.isFinite(startMs) || done <= 0 || total <= 0 || done >= total) {
      return '';
    }
    const elapsedSec = Math.max(1, (Date.now() - startMs) / 1000);
    const avgSec = elapsedSec / done;
    const remainSec = Math.max(0, Math.round(avgSec * (total - done)));
    const perSec = Math.max(1, Math.round(avgSec));
    const remainStr = remainSec >= 60
      ? `約 ${Math.ceil(remainSec / 60)} 分`
      : `約 ${remainSec} 秒`;
    return `残り ${remainStr}  (1件あたり ${perSec}秒)`;
  }

  /**
   * 秒数を "X分Y秒" に整形する。
   * @param {number} totalSec
   * @returns {string}
   */
  function formatDuration(totalSec) {
    const sec = Math.max(0, Math.round(totalSec));
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m > 0 ? `${m}分${s}秒` : `${s}秒`;
  }

  /**
   * 件数ベースの初期目安（実測前）を返す。
   * @param {number} count
   * @param {'analyze'|'generate'} phase
   * @returns {string}
   */
  function roughEstimate(count, phase) {
    const n = Math.max(0, Number(count) || 0);
    const [lo, hi] = phase === 'analyze' ? [20, 60] : [5, 20];
    const loMin = Math.ceil((n * lo) / 60);
    const hiMin = Math.ceil((n * hi) / 60);
    if (loMin === hiMin) return `約 ${loMin} 分`;
    return `約 ${loMin}〜${hiMin} 分`;
  }

  function fmtUtc(iso) {
    const s = String(iso || '').trim();
    if (!s) return '—';
    return s.replace('T', ' ').replace('Z', ' UTC');
  }

  async function refreshJobList() {
    const help = document.getElementById('jobManageHelp');
    const table = document.getElementById('jobManageTable');
    const tbody = document.getElementById('jobManageTbody');
    if (!help || !table || !tbody) return;
    help.textContent = '読み込み中…';
    tbody.innerHTML = '';
    try {
      const res = await apiGet('store/job_list.php');
      const jobs = Array.isArray(res.jobs) ? res.jobs : [];
      // ナビバーバッジを更新
      const badge = document.getElementById('navJobBadge');
      if (badge) {
        if (jobs.length > 0) {
          badge.textContent = String(jobs.length);
          badge.style.display = '';
        } else {
          badge.style.display = 'none';
        }
      }
      if (jobs.length === 0) {
        help.textContent = '実行中ジョブはありません。';
        table.classList.add('d-none');
        return;
      }
      table.classList.remove('d-none');
      help.textContent = `${jobs.length} 件のジョブが稼働中です。`;

      jobs.forEach((j) => {
        const tr = document.createElement('tr');
        const id = String(j.id || '');
        const owner = String(j.owner_email || '');
        const status = String(j.status || '');
        const type = String(j.type || '');
        const purpose = String(j.purpose || '');
        const ws = String(j.workspace_id || '');
        const wsFolderMissing = j.workspace_disk_present === false;
        const started = fmtUtc(String(j.started_at || ''));
        const stopBtn = document.createElement('button');
        stopBtn.type = 'button';
        stopBtn.className = 'btn btn-sm btn-outline-danger';
        const isLegacyTask = /^(ana_|gen_)/.test(id);
        stopBtn.textContent = (status === 'stopping') ? '停止中' : '停止';
        stopBtn.disabled = status === 'stopping' || isLegacyTask;
        if (isLegacyTask) stopBtn.title = '画面から操作してください';
        stopBtn.addEventListener('click', async () => {
          if (!confirm(`ジョブ ${id} を停止しますか？`)) return;
          try {
            await apiPost('store/job_stop.php', {
              csrf: String(window.LP_CMS?.csrfToken || ''),
              job_id: id,
            });
            if (currentAnalyzeJobId === id) {
              currentAnalyzeJobId = '';
            }
            if (currentGenerateJobId === id) {
              currentGenerateJobId = '';
              generateStopRequested = true;
              generateAbortController?.abort();
              try { await fetch('store/abort_generate.php', { method: 'POST' }); } catch {}
            }
            await refreshJobList();
          } catch (e) {
            alert(e instanceof Error ? e.message : String(e));
          }
        });
        const wsCell = wsFolderMissing
          ? `<td><code>${ws}</code> <span class="badge bg-secondary">フォルダ無し</span></td>`
          : `<td><code>${ws}</code></td>`;
        tr.innerHTML = `<td>${type}</td><td class="small">${owner}</td><td class="small">${purpose}</td>${wsCell}<td class="small text-muted">${started}</td>`;
        const td = document.createElement('td');
        td.appendChild(stopBtn);
        tr.appendChild(td);
        tbody.appendChild(tr);
      });
    } catch (e) {
      table.classList.add('d-none');
      help.textContent = e instanceof Error ? e.message : 'ジョブ一覧の取得に失敗しました。';
    }
  }

  // -----------------------------------------------------------------------
  // Check URL params for forced step
  // -----------------------------------------------------------------------
  function resolveInitialStep() {
    const params = new URLSearchParams(window.location.search);
    const stepParam = parseInt(params.get('step') ?? '0', 10);
    if (stepParam >= 1 && stepParam <= 3) {
      return stepParam;
    }
    return window.LP_CMS?.initialStep ?? 1;
  }

  // -----------------------------------------------------------------------
  // Bootstrap dark tooltips (wrap + tabindex for disabled-safe hover)
  // -----------------------------------------------------------------------
  function initLpCmsBootstrapTooltips() {
    if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
      return;
    }

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(raw => {
      if (!(raw instanceof HTMLElement)) {
        return;
      }
      try {
        bootstrap.Tooltip.getOrCreateInstance(raw, {
          boundary: document.body,
          trigger: 'hover focus',
        });
      } catch {
        /* ignore */
      }
    });
  }

  // -----------------------------------------------------------------------
  // Diagnostics
  // -----------------------------------------------------------------------
  async function loadDiagnostics(targetEl) {
    try {
      const ctrl = new AbortController();
      const tm = setTimeout(() => ctrl.abort(), 30000);
      const res = await fetch('store/debug.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        signal: ctrl.signal,
      });
      clearTimeout(tm);
      const data = await res.json();
      if (!targetEl) return data;

      const ff = Array.isArray(data.fetch_failures) ? data.fetch_failures : [];
      lastDiagFetchFailures = ff;
      const failCount =
        typeof data.summary?.fetch_failure_count === 'number'
          ? data.summary.fetch_failure_count
          : ff.length;

      const sum = data.summary ?? {};
      const ast = data.assets ?? {};
      const unrepTotal = Number(data.output_unreplaced?.total ?? 0);
      const ok = unrepTotal === 0;
      const leftOver = unrepTotal;
      const css    = sum.map_css ?? ast.map_css ?? 0;
      const img    = sum.map_img ?? ast.map_img ?? 0;
      const js     = sum.map_js  ?? ast.map_js  ?? 0;
      const diskCss = sum.disk_css ?? ast.disk_css?.count ?? 0;
      const diskImg = sum.disk_img ?? ast.disk_img?.count ?? 0;
      const diskJs  = sum.disk_js  ?? ast.disk_js?.count  ?? 0;

      const healthBadge = ok
        ? '<span class="badge bg-success">✓ 生成HTMLの未置換URLなし</span>'
        : `<span class="badge bg-warning text-dark">⚠ 未置換の絶対URL：${leftOver}件</span>`;

      const fetchFailBlock =
        failCount > 0
          ? `<div class="col-12 small mt-2">
          <span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Step1 での HTTP 取得失敗: ${failCount}件</span>
          <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" id="step3FetchFailLogBtn">ログを表示</button>
        </div>`
          : '';

      targetEl.innerHTML = `
        <div class="row g-2 text-start">
          <div class="col-12">
            <div class="d-flex align-items-center gap-2 mb-1">
              <strong>アセット状況</strong> ${healthBadge}
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 rounded border text-center small">
              <i class="bi bi-filetype-css text-primary fs-4"></i><br>
              <strong>${diskCss}</strong> CSS<br>
              <span class="text-muted">map: ${css}</span>
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 rounded border text-center small">
              <i class="bi bi-image text-success fs-4"></i><br>
              <strong>${diskImg}</strong> 画像<br>
              <span class="text-muted">map: ${img}</span>
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 rounded border text-center small">
              <i class="bi bi-filetype-js text-warning fs-4"></i><br>
              <strong>${diskJs}</strong> JS<br>
              <span class="text-muted">map: ${js}</span>
            </div>
          </div>
          ${fetchFailBlock}
          ${!ok ? `<div class="col-12"><div class="alert alert-warning small mb-0 mt-1">
            外部URLや置換漏れが残っている可能性があります。必要なら Step 1 に戻り「解析する」を再実行するか、編集画面で該当リンク・画像を確認してください。
          </div></div>` : ''}
        </div>`;
      return data;
    } catch (e) {
      if (targetEl) {
        const msg = e?.name === 'AbortError'
          ? '診断取得がタイムアウトしました（30秒）。再読込後に再確認してください。'
          : '診断取得に失敗しました（`store/debug.php` 応答エラー）。';
        targetEl.innerHTML = `<p class="text-danger small mb-0">${msg}</p>`;
      }
      return null;
    }
  }

  function bindCloneImageZipPanel() {
    const dl = document.getElementById('btnCloneImagesZipDl');
    const btnUp = document.getElementById('btnCloneImagesZipUpload');
    const inp = document.getElementById('cloneImagesZipUploadInp');
    if (!dl && !btnUp && !inp) {
      return;
    }

    dl?.addEventListener('click', () => {
      window.location.href = 'store/export_clone_images_zip.php';
    });

    btnUp?.addEventListener('click', () => inp?.click());

    inp?.addEventListener('change', async () => {
      const file = inp.files && inp.files[0];
      inp.value = '';
      if (!file) {
        return;
      }

      const fd = new FormData();
      fd.append('pack', file);

      try {
        const res = await fetch('store/import_clone_images_zip.php', {
          method: 'POST',
          credentials: 'same-origin',
          body: fd,
        });
        let data = {};

        try {
          data = await res.json();
        } catch {
          showToast('サーバ応答を解釈できません', 'danger');

          return;
        }

        if (!data.ok) {
          showToast(String(data.error || `HTTP ${res.status}`), 'danger');

          return;
        }

        const n = typeof data.applied === 'number' ? data.applied : 0;
        const errs = Array.isArray(data.errors) ? data.errors.filter(Boolean) : [];
        let msg = `${n} 件の画像を置き換えました。`;
        if (errs.length > 0) {
          msg += ` 警告あり: ${errs.slice(0, 3).join(' — ')}${errs.length > 3 ? '…' : ''}`;
          showToast(msg, 'warning');
        } else {
          showToast(msg, 'success');
        }
      } catch {

        showToast('通信に失敗しました', 'danger');
      }
    });
  }

  // -----------------------------------------------------------------------
  // Step 2 — Explorer tree (page navigator)
  // -----------------------------------------------------------------------

  /** Simple HTML escape helper */
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * Initialize the page tree from site_map.json.
   * Only runs once; subsequent calls are no-ops.
   */
  async function initPageTree() {
    if (treeInitialized || !window.LP_CMS?.hasStructure) return;
    treeInitialized = true;

    const treeEl = document.getElementById('pageTree');
    if (!treeEl) return;

    try {
      const r = await fetch('store/get_site_map.php', { credentials: 'same-origin' });
      if (!r.ok) throw new Error('site_map fetch failed: ' + r.status);
      const siteMap = await r.json();
      if (!siteMap || typeof siteMap !== 'object') throw new Error('invalid site_map');
      buildAndRenderTree(treeEl, siteMap);
    } catch (e) {
      treeEl.innerHTML =
        `<div class="text-danger small px-2 py-2">` +
        `<i class="bi bi-exclamation-triangle me-1"></i>` +
        `ツリー読み込みに失敗しました。` +
        `</div>`;
    }
  }

  /**
   * Build tree data from site_map and render into treeEl.
   * @param {HTMLElement} treeEl
   * @param {object} siteMap
   */
  function buildAndRenderTree(treeEl, siteMap) {
    const pages = siteMap.pages || {};
    const baseUrl = (siteMap.meta?.base_url || siteMap.meta?.entry_url || '').replace(/\/$/, '');

    let domain = 'site';
    try { domain = new URL(baseUrl || 'https://unknown').hostname; } catch { /* keep default */ }

    // Build flat page list with parsed path segments
    /** @type {Array<{key:string, sourceUrl:string, pathSegs:string[], status:string}>} */
    const pageList = [];
    for (const [key, page] of Object.entries(pages)) {
      if (!page || typeof page !== 'object') continue;
      let pathSegs = [];
      try {
        const u = new URL(page.source_url || '');
        const p = u.pathname.replace(/\/$/, '') || '/';
        pathSegs = p === '/' ? [] : p.replace(/^\//, '').split('/').filter(Boolean);
      } catch { /* keep empty */ }
      pageList.push({ key, sourceUrl: page.source_url || '', pathSegs, status: page.status || 'ok' });
    }

    // Sort: index first, then by depth, then alphabetically
    pageList.sort((a, b) => {
      if (a.key === 'index') return -1;
      if (b.key === 'index') return 1;
      if (a.pathSegs.length !== b.pathSegs.length) return a.pathSegs.length - b.pathSegs.length;
      return a.sourceUrl.localeCompare(b.sourceUrl);
    });

    // Build tree structure
    /** @type {{seg:string, pageKey:string|null, status:string, sourceUrl:string, children:object}} */
    const root = { seg: '', pageKey: null, status: 'ok', sourceUrl: baseUrl, children: {} };

    for (const node of pageList) {
      if (node.key === 'index') {
        root.pageKey = 'index';
        root.sourceUrl = node.sourceUrl;
        continue;
      }
      let cur = root;
      for (const seg of node.pathSegs) {
        if (!cur.children[seg]) {
          cur.children[seg] = { seg, pageKey: null, status: 'ok', sourceUrl: '', children: {} };
        }
        cur = cur.children[seg];
      }
      cur.pageKey = node.key;
      cur.status = node.status;
      cur.sourceUrl = node.sourceUrl;
    }

    // Render the tree
    let uid = 0;
    function nextId() { return 'lpt_' + (++uid); }

    function renderNode(node, labelOverride, depth) {
      const label = labelOverride || node.seg || '?';
      const hasChildren = Object.keys(node.children).length > 0;
      const hasPage = node.pageKey !== null;
      const isError = node.status === 'error';
      const childrenId = nextId();
      let html = '';

      // Node row
      const isRoot = depth === 0;
      const nodeAttr = (hasPage && !isError) ? ` data-page-key="${escHtml(node.pageKey)}"` : '';
      const errorClass = isError ? ' lp-tree-error' : '';
      const activeClass = (hasPage && node.pageKey === currentPageKey) ? ' lp-tree-active' : '';
      const title = node.sourceUrl ? ` title="${escHtml(node.sourceUrl)}"` : '';

      let iconClass;
      if (isRoot) {
        iconClass = 'bi-globe2 text-primary';
      } else if (isError) {
        iconClass = 'bi-exclamation-triangle text-danger';
      } else if (hasChildren) {
        iconClass = 'bi-folder2-open text-warning';
      } else {
        iconClass = 'bi-file-earmark-text';
      }

      html += `<div class="lp-tree-node${errorClass}${activeClass}"${nodeAttr}${title}>`;

      // Toggle arrow
      if (hasChildren) {
        html += `<span class="lp-tree-toggle me-1" data-tree-toggle="${childrenId}">` +
                `<i class="bi bi-chevron-down"></i></span>`;
      } else {
        html += `<span class="lp-tree-toggle me-1"></span>`;
      }

      // Icon
      html += `<i class="bi ${iconClass} lp-tree-icon"></i>`;

      // Label
      html += `<span class="lp-tree-label">${escHtml(label)}</span>`;
      html += `</div>`;

      // Children container
      if (hasChildren) {
        html += `<div class="lp-tree-children" id="${childrenId}">`;

        // If root has its own page (index), add it as first child node
        if (isRoot && hasPage) {
          const indexActiveClass = currentPageKey === 'index' ? ' lp-tree-active' : '';
          html += `<div class="lp-tree-node${indexActiveClass}" data-page-key="index"` +
                  ` title="${escHtml(node.sourceUrl)}">` +
                  `<span class="lp-tree-toggle me-1"></span>` +
                  `<i class="bi bi-house-fill lp-tree-icon text-primary"></i>` +
                  `<span class="lp-tree-label">index</span>` +
                  `</div>`;
        }

        for (const [seg, child] of Object.entries(node.children)) {
          html += renderNode(child, seg, depth + 1);
        }
        html += `</div>`;
      }

      return html;
    }

    // Auto-save message row at top of tree
    let fullHtml = `<div id="treeAutoSaveMsg"></div>`;
    fullHtml += renderNode(root, domain, 0);
    treeEl.innerHTML = fullHtml;

    // Bind tree interactions (event delegation)
    treeEl.addEventListener('click', e => {
      // Toggle collapse
      const toggleEl = e.target.closest('[data-tree-toggle]');
      if (toggleEl) {
        e.stopPropagation();
        const childrenId = toggleEl.dataset.treeToggle;
        const childrenEl = document.getElementById(childrenId);
        const icon = toggleEl.querySelector('i');
        if (childrenEl) {
          const isNowHidden = childrenEl.classList.toggle('d-none');
          if (icon) {
            icon.className = isNowHidden ? 'bi bi-chevron-right' : 'bi bi-chevron-down';
          }
        }
        return;
      }

      // Page node click
      const nodeEl = e.target.closest('[data-page-key]');
      if (nodeEl && !nodeEl.classList.contains('lp-tree-error')) {
        const key = nodeEl.dataset.pageKey;
        if (key) void selectPageNode(key);
      }
    });
  }

  /** Highlight the active node in the tree */
  function markTreeNodeActive(key) {
    document.querySelectorAll('#pageTree [data-page-key]').forEach(el => {
      el.classList.toggle('lp-tree-active', el.dataset.pageKey === key);
    });
  }

  /** Show a brief auto-save status message */
  function showTreeMsg(msg, type) {
    const el = document.getElementById('treeAutoSaveMsg');
    if (!el) return;
    el.textContent = msg;
    el.style.color = type === 'ok' ? '#198754' : type === 'err' ? '#dc3545' : '#6c757d';
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.textContent = ''; }, 3000);
  }

  /**
   * Save the current page's form data to store/save_page_client.php.
   * Silently ignores failures (best-effort auto-save).
   */
  async function saveCurrentPageData() {
    const form = document.getElementById('clientDataForm');
    if (!form || !currentPageKey) return;

    const data = collectFormData();
    try {
      await fetch('store/save_page_client.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify({ page_key: currentPageKey, ...data }),
      });
      showTreeMsg('✓ 保存', 'ok');
    } catch {
      showTreeMsg('保存失敗', 'err');
    }
  }

  /**
   * Update the fixed header of the edit form panel.
   * @param {string} key        page key ('index' | 'internal_N')
   * @param {string} pageTitle  Japanese title (may be empty)
   * @param {string} sourceUrl  original source URL (may be empty)
   */
  function updateEditFormHeader(key, pageTitle, sourceUrl) {
    const labelEl = document.getElementById('editFormPageLabel');
    const urlEl   = document.getElementById('editFormPageUrl');
    if (labelEl) {
      const treeNode = document.querySelector(`#pageTree [data-page-key="${CSS.escape(key)}"] .lp-tree-label`);
      const treeLabel = treeNode ? treeNode.textContent.trim() : key;
      labelEl.innerHTML =
        `<i class="bi bi-pencil-square me-1 text-muted"></i>` +
        escHtml(pageTitle || treeLabel);
    }
    if (urlEl) {
      if (sourceUrl) {
        urlEl.href = sourceUrl;
        urlEl.textContent = sourceUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
        urlEl.classList.remove('d-none');
      } else {
        urlEl.classList.add('d-none');
      }
    }
  }

  /**
   * Switch the edit panel to a different page.
   * Saves the current page first, then Ajax-loads the new page's form.
   * @param {string} key
   */
  async function selectPageNode(key) {
    if (!key || key === currentPageKey) return;

    // Auto-save before switching
    showTreeMsg('保存中…', 'muted');
    await saveCurrentPageData();

    currentPageKey = key;
    markTreeNodeActive(key);

    const content = document.getElementById('editFormContent');
    if (!content) return;

    content.innerHTML =
      `<div class="lp-edit-form-loading">` +
      `<span class="spinner-border spinner-border-sm me-2"></span>読み込み中…` +
      `</div>`;

    try {
      const r = await fetch(`store/get_page_edit_form.php?key=${encodeURIComponent(key)}`, {
        credentials: 'same-origin',
      });
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || '読み込み失敗');
      content.innerHTML = data.html;
      updateEditFormHeader(key, data.page_title || '', data.source_url || '');
      rebindFormHandlers();
    } catch (e) {
      content.innerHTML =
        `<div class="alert alert-danger m-3">` +
        `<i class="bi bi-exclamation-triangle me-2"></i>` +
        `ページフォームの読み込みに失敗しました: ${escHtml(String(e))}` +
        `</div>`;
    }
  }

  /**
   * Re-bind form handlers after Ajax page load.
   * Called whenever editFormWrapper content is replaced.
   */
  function rebindFormHandlers() {
    bindImagePreviews();
    bindImageMemoRefine();
    initAiTextReplace();
    initLpCmsBootstrapTooltips();
  }

  // -----------------------------------------------------------------------
  // Init
  // -----------------------------------------------------------------------
  function init() {
    bindStepIndicatorClickNav();

    refreshStepNavigatorDom();

    const step = resolveInitialStep();

    goToStep(step);
    bindImagePreviews();
    bindImageMemoRefine();
    bindImageReplaceModal();
    bindCloneImageZipPanel();
    initLpCmsBootstrapTooltips();

    document.getElementById('step3DiagSummary')?.addEventListener('click', (e) => {
      const el = e.target;
      if (!(el instanceof Element)) return;
      if (el.closest('#step3FetchFailLogBtn')) {
        openFetchFailureLogModal(lastDiagFetchFailures);
      }
    });

    // Step 1 events
    if (btnFetchAnalyze) {
      btnFetchAnalyze.addEventListener('click', runFetchAndAnalyze);
    }
    if (lpUrlInput) {
      lpUrlInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') runFetchAndAnalyze();
      });
    }

    // Step 2 events
    if (btnBackToStep1) {
      btnBackToStep1.addEventListener('click', () => {
        window.location.href = window.location.pathname + '?step=1';
      });
    }
    if (btnResetClient) {
      btnResetClient.addEventListener('click', resetClientData);
    }
    if (btnSaveGenerate) {
      // ボタン押下でモーダルを Phase 1（目的入力）として即座に開く
      btnSaveGenerate.addEventListener('click', () => {
        openSaveGenerateModal();
      });
    }

    // Phase 1「生成を開始」ボタン
    document.getElementById('btnSaveGenStartRun')?.addEventListener('click', () => {
      const inp = document.getElementById('saveGenPurposeInput');
      const purpose = (inp?.value || '').trim() || '編集反映のため再生成';
      if (inp) inp.disabled = true;
      switchToProgressPhase();
      void runSaveAndGenerate(purpose);
    });

    // Enter キーでも開始できるようにする
    document.getElementById('saveGenPurposeInput')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        document.getElementById('btnSaveGenStartRun')?.click();
      }
    });

    document.getElementById('btnSaveGenModalDismiss')?.addEventListener('click', () => {
      if (btnSaveGenerate) btnSaveGenerate.disabled = false;
    });

    // × button: close modal without aborting — polling continues in background
    document.getElementById('btnSaveGenBg')?.addEventListener('click', () => {
      const modalEl = document.getElementById('saveGenerateModal');
      if (modalEl && typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getInstance(modalEl)?.hide();
      }
    });

    initAiTextReplace();

    // AI モーダルを開いた時に業種が空なら自動取得
    const aiModalEl = document.getElementById('aiGenerateModal');
    if (aiModalEl) {
      aiModalEl.addEventListener('show.bs.modal', async () => {
        const industryInp = document.getElementById('ai-industry');
        const hintEl      = document.getElementById('aiIndustryEmptyHint');
        if (!industryInp || industryInp.value.trim() !== '') return; // already filled

        // Show loading indicator
        if (hintEl) {
          hintEl.innerHTML =
            '<span class="spinner-border spinner-border-sm me-1" style="width:.8rem;height:.8rem"></span>' +
            '解析結果から業種を取得中…';
        }
        try {
          const r = await fetch('store/recompute_industry.php', {
            method: 'POST',
            credentials: 'same-origin',
          });
          const d = await r.json();
          if (d.ok && d.source_industry) {
            industryInp.value = d.source_industry;
            // Add suggestions to datalist
            const dl = document.getElementById('ai-industry-list');
            if (dl && Array.isArray(d.suggestions)) {
              d.suggestions.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s;
                dl.appendChild(opt);
              });
            }
            // Add ai-chips
            const chipWrap = aiModalEl.querySelector('.ai-chip-row');
            if (chipWrap && Array.isArray(d.suggestions)) {
              d.suggestions.forEach(s => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-outline-primary ai-chip';
                btn.dataset.value = s;
                btn.textContent = s;
                chipWrap.appendChild(btn);
              });
            }
            if (hintEl) {
              hintEl.innerHTML =
                '<i class="bi bi-cpu me-1"></i>解析結果から自動取得しました: <strong>' +
                escHtml(d.source_industry) + '</strong>';
              hintEl.className = hintEl.className.replace('text-warning', 'text-success');
            }
          } else {
            if (hintEl) {
              hintEl.innerHTML =
                '<i class="bi bi-exclamation-triangle me-1"></i>業種の自動取得に失敗しました。手入力してください。';
            }
          }
        } catch {
          if (hintEl) {
            hintEl.innerHTML =
              '<i class="bi bi-exclamation-triangle me-1"></i>業種の自動取得に失敗しました。手入力してください。';
          }
        }
      });
    }

    // Step 3 events
    if (btnEditAgain) {
      btnEditAgain.addEventListener('click', () => {
        window.location.href = window.location.pathname + '?step=2';
      });
    }

    if (btnCopyWorkspaceName && workspaceNameField) {
      btnCopyWorkspaceName.addEventListener('click', async () => {
        const v = (workspaceNameField.value || '').trim();
        if (!v) {
          showToast('ワークスペース名が空です。', 'warning');
          return;
        }
        try {
          await navigator.clipboard.writeText(v);
          showToast(`ワークスペース名をコピーしました（${v}）`, 'success');
        } catch {
          workspaceNameField.focus();
          workspaceNameField.select();
          showToast('コピーに失敗しました。手動でコピーしてください。', 'warning');
        }
      });
    }

    // Auto-load diagnostics on step 3
    if (step === 3) {
      const diagSummary = document.getElementById('step3DiagSummary');
      if (diagSummary) {
        loadDiagnostics(diagSummary);
      }
    }

    if (typeof lpInitWorkspaceManage === 'function') {
      lpInitWorkspaceManage('store/');
    }

    resumeAnalyzePollingIfNeeded();
    resumeGeneratePollingIfNeeded();

    document.getElementById('btnJobListRefresh')?.addEventListener('click', () => {
      void refreshJobList();
    });
    document.getElementById('jobModal')?.addEventListener('show.bs.modal', () => {
      void refreshJobList();
    });
    // 定期的にジョブバッジを更新（30秒ごと）
    void refreshJobList();
    setInterval(() => { void refreshJobList(); }, 30000);
  }

  document.addEventListener('DOMContentLoaded', init);
})();
