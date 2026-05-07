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

  // -----------------------------------------------------------------------
  // Step navigation
  // -----------------------------------------------------------------------
  function goToStep(n) {
    currentStep = n;
    Object.values(panels).forEach(p => p && p.classList.add('d-none'));
    panels[n] && panels[n].classList.remove('d-none');

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
    progAnalyzePct.textContent = '0%/100%';
    progAnalyzeBarOuter?.setAttribute('aria-valuenow', '0');
    progAnalyzeBarOuter?.setAttribute('aria-valuetext', '0%/100%');
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
    const pctLabel = `${pctRounded}%/100%`;
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
  async function apiPostAnalyzeStream(endpoint) {
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8', Accept: 'application/x-ndjson, application/json' },
      body: JSON.stringify({ stream_progress: true }),
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

    btnFetchAnalyze.disabled = true;
    fetchError.classList.add('d-none');
    fetchProgress.classList.remove('d-none');

    // Reset progress items
    setProgState(progFetch, 'loading', 'HTMLおよびCSS・画像を取得中…');
    setProgState(progAnalyze, 'idle');
    resetAnalyzeProgressUi();
    progAnalyzeBarWrap?.classList.add('d-none');

    try {
      // -- Phase 1: fetch HTML + download assets --
      const fetchRes = await apiPost('store/fetch_lp.php', { url });
      if (!fetchRes.success) throw new Error(fetchRes.error ?? 'HTML取得に失敗しました。');

      const htmlKb  = ((fetchRes.html_size ?? fetchRes.size ?? 0) / 1024).toFixed(1);
      const css     = fetchRes.asset_css    ?? 0;
      const img     = fetchRes.asset_img    ?? 0;
      const js      = fetchRes.asset_js     ?? 0;
      const fonts   = fetchRes.asset_fonts  ?? 0;
      const uncat   = fetchRes.asset_uncategorized ?? 0;
      const failed  = fetchRes.fetch_failed ?? 0;
      const total   = fetchRes.asset_total  ?? 0;
      const failList = fetchRes.fetch_failures;

      const buckets = `CSS ${css} / 画像 ${img} / JS ${js} / フォント ${fonts}${
        Number(uncat) > 0 ? ` / 未分類 ${uncat}` : ''
      }`;
      if (failed > 0) {
        setProgState(progFetch, 'done', '', { skipDetail: true });
        renderFetchProgDetail(htmlKb, buckets, total, failed, failList);
      } else {
        const totalExplain =
          '（計 ' + total + '＝重複省略後のアセットファイル保存先の一意数）';
        setProgState(
          progFetch, 'done',
          `HTML ${htmlKb} KB | ${buckets}${totalExplain}`,
        );
      }
      resetAnalyzeProgressUi();
      progAnalyzeBarWrap?.classList.remove('d-none');
      setProgState(progAnalyze, 'loading', 'サイト構造を解析中…');

      /** @type {Record<string, unknown>|null} */
      let analyzeRes = null;
      /** @type {Array<Record<string, unknown>>} */
      let internalCandidateUrls = [];
      let twoPhaseAnalyze = false;

      try {
        const entryRes = await apiPost('store/analyze_entry.php', { stream_progress: true });
        if (!entryRes.ok && !entryRes.success) {
          throw new Error(typeof entryRes.error === 'string' ? entryRes.error : 'analyze_entry failed');
        }
        analyzeRes = entryRes;
        internalCandidateUrls = Array.isArray(entryRes.internal_candidate_urls)
          ? /** @type {Array<Record<string, unknown>>} */ (entryRes.internal_candidate_urls)
          : [];
        twoPhaseAnalyze = true;
      } catch {
        twoPhaseAnalyze = false;
      }

      if (twoPhaseAnalyze) {
        const total = Math.max(1, internalCandidateUrls.length);
        const analyzeStartMs = Date.now();
        if (progAnalyzeDetail) {
          progAnalyzeDetail.textContent =
            `【内部ページ取得】 内部ページ ${internalCandidateUrls.length} 件を検出。目安: ${roughEstimate(internalCandidateUrls.length, 'analyze')}`;
        }
        for (let i = 0; i < internalCandidateUrls.length; i++) {
          const row = internalCandidateUrls[i];
          const idx = typeof row.index === 'number' ? row.index : i;
          const canonical = typeof row.canonical_url === 'string' ? row.canonical_url : '';
          const pct = Math.min(98, 50 + Math.round((45 * (i + 1)) / total));
          if (progAnalyzeBar) progAnalyzeBar.style.width = `${pct}%`;
          progAnalyzeBarOuter?.setAttribute('aria-valuenow', String(pct));
          progAnalyzeBarOuter?.setAttribute('aria-valuetext', `${pct}%/100%`);
          if (progAnalyzePct) progAnalyzePct.textContent = `${pct}%/100%`;
          if (progAnalyzeDetail) {
            const shown = canonical.length > 100 ? `${canonical.slice(0, 97)}...` : canonical;
            const eta = etaString(analyzeStartMs, i + 1, total);
            const etaLabel = eta ? ` ${eta}` : '';
            progAnalyzeDetail.textContent = `【内部ページ取得】 ${i + 1} / ${total} 内部ページ解析中... ${shown}${etaLabel}`;
          }

          try {
            await apiPost('store/analyze_internal_page.php', { index: idx }, { timeoutMs: 240000 });
          } catch (e) {
            console.warn('analyze_internal_page skipped', idx, e);
          }
        }

        if (progAnalyzeDetail) {
          progAnalyzeDetail.textContent = '【最終処理】 画像メモ・業種推定を処理しています...';
        }
        const finalizeRes = await apiPost('store/finalize_analyze.php', {});
        if (!finalizeRes.ok && !finalizeRes.success) {
          throw new Error(
            typeof finalizeRes.error === 'string'
              ? finalizeRes.error
              : '最終処理に失敗しました。',
          );
        }
      } else {
        analyzeRes = await apiPostAnalyzeStream('store/analyze_lp.php');
        if (!analyzeRes.success) throw new Error(analyzeRes.error ?? '解析に失敗しました。');
      }

      if (!analyzeRes) {
        throw new Error('解析結果の取得に失敗しました。');
      }

      let diagNote = '';
      const diag = analyzeRes.parse_diagnostics;
      if (diag && typeof diag === 'object') {
        const dx = /** @type {{ walk_total_steps?: number, walk_completed_steps?: number, walk_incomplete?: boolean, section_errors?: unknown[] }} */ (diag);
        if (typeof dx.walk_total_steps === 'number' && typeof dx.walk_completed_steps === 'number') {
          diagNote += ` | ツリー ${dx.walk_completed_steps.toLocaleString()}/${dx.walk_total_steps.toLocaleString()} ステップ`;
          if (dx.walk_incomplete) diagNote += '（部分的）';
        }
        if (Array.isArray(dx.section_errors) && dx.section_errors.length > 0) {
          diagNote += ` | セクションエラー ${dx.section_errors.length}件（ログ: data/lp_structure_analyze.log）`;
          showToast('一部セクションをスキップしました（ログを確認してください）', 'warning');
        }
      }

      setProgState(progAnalyze, 'done',
        `${analyzeRes.section_count}セクション / ${analyzeRes.total_elements}要素を抽出${diagNote}`);
      progAnalyzeBarWrap?.classList.add('d-none');

      await sleep(600);
      window.location.href = window.location.pathname + '?step=2';

    } catch (err) {
      showError(fetchError, err.message);
      setProgState(progFetch,   'error');
      setProgState(progAnalyze, 'error');
      progAnalyzeBarWrap?.classList.add('d-none');
      btnFetchAnalyze.disabled = false;
    }
  }

  // -----------------------------------------------------------------------
  // Step 2 — Save & Generate (progress modal)
  // -----------------------------------------------------------------------
  function ensureSaveGenProgressUi() {
    let wrap = document.getElementById('saveGenProgressWrap');
    if (wrap) {
      return wrap;
    }

    const modalBody = document.querySelector('#saveGenerateModal .modal-body');
    if (!modalBody) {
      return null;
    }

    wrap = document.createElement('div');
    wrap.id = 'saveGenProgressWrap';
    wrap.className = 'mt-3 d-none';
    wrap.innerHTML =
      '<div class="progress" style="height:8px" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">'
      + '<div id="saveGenProgressBar" class="progress-bar" style="width:0%"></div></div>'
      + '<p id="saveGenProgressLabel" class="small text-muted mb-0 mt-2"></p>'
      + '<button id="saveGenAbortBtn" type="button" class="btn btn-sm btn-outline-danger mt-2">■ 生成を停止</button>';
    const errEl = modalBody.querySelector('#saveGenModalErr');
    if (errEl) {
      modalBody.insertBefore(wrap, errEl);
    } else {
      modalBody.appendChild(wrap);
    }

    document.getElementById('saveGenAbortBtn')?.addEventListener('click', async () => {
      generateStopRequested = true;
      generateAbortController?.abort();
      try {
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
    resetSaveGenerateModal();
    bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static', keyboard: false }).show();
  }

  function hideSaveGenerateModal() {
    const modalEl = document.getElementById('saveGenerateModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    bootstrap.Modal.getInstance(modalEl)?.hide();
  }

  async function runSaveAndGenerate() {
    generateAbortController = new AbortController();
    generateStopRequested = false;
    const generateSignal = generateAbortController.signal;
    btnSaveGenerate.disabled = true;
    generateError.classList.add('d-none');
    generateSuccess.classList.add('d-none');

    openSaveGenerateModal();

    let savePhaseDone = false;

    try {
      const clientData = collectFormData();

      setSaveGenRowStatus('saveGenRowSave', 'active');
      const saveRes = await apiPost('store/save_client.php', clientData);
      if (!saveRes.success) throw new Error(saveRes.error ?? '保存に失敗しました。');
      setSaveGenRowStatus('saveGenRowSave', 'done');
      savePhaseDone = true;

      setSaveGenRowStatus('saveGenRowGen', 'active');

      /** site_map が取れるときは 2フェーズ分割生成 */
      /** @type {Record<string, unknown>|null} */
      let listProbe = null;
      try {
        listProbe = await apiGet('store/list_internal_urls.php');
      } catch {
        listProbe = null;
      }

      const useTwoPhase = listProbe !== null;

      ensureSaveGenProgressUi();

      /** @type {Record<string, unknown>} */
      let genRes;

      if (useTwoPhase) {
        ensureSaveGenProgressUi()?.classList.remove('d-none');
        genRes = await apiPost('store/generate_entry.php', {}, { signal: generateSignal });
        if (genRes.aborted === true) {
          throw new DOMException('aborted', 'AbortError');
        }
        if (genRes.success !== true && genRes.ok !== true) {
          throw new Error(
            typeof genRes.error === 'string' ? genRes.error : 'エントリー生成に失敗しました。',
          );
        }

        const internals = Array.isArray(listProbe.internals) ? listProbe.internals : [];

        /** 解析エラー行はクライアントでもスキップ（サーバーは 400） */
        const toRun = internals.filter(it => String(it.status ?? '') !== 'error');
        const totalBar = Math.max(1, 1 + toRun.length);
        const genStartMs = Date.now();
        let completed = 1;
        let lastSize = typeof genRes.size === 'number' ? genRes.size : 0;

        setSaveGenProgress(
          0,
          totalBar,
          `${totalBar} 件を生成します。目安: ${roughEstimate(totalBar, 'generate')}`,
        );
        const entryEta = etaString(genStartMs, completed, totalBar);
        setSaveGenProgress(
          completed,
          totalBar,
          `トップページ（index）の生成が完了しました。${entryEta || '内部ページ生成を継続します。'}`,
        );

        for (let i = 0; i < toRun.length; i++) {
          if (generateStopRequested) {
            break;
          }
          const row = /** @type {Record<string, unknown>} */ (toRun[i]);
          const key = typeof row.key === 'string' ? row.key : '';
          if (!key || !/^internal_\d+$/.test(key)) {
            continue;
          }

          const n = i + 1;
          setSaveGenProgress(
            completed,
            totalBar,
            `内部ページ ${n} / ${toRun.length} 件目を生成しています…（キー「${key}」は site_map のページ ID であり、URL の並び順と一致するとは限りません）`,
          );

          try {
            const one = await apiPost(
              'store/generate_internal.php',
              { key },
              { timeoutMs: 180000, signal: generateSignal },
            );
            if (one.aborted === true) {
              throw new DOMException('aborted', 'AbortError');
            }
            completed++;
            if (typeof one.size === 'number') lastSize += one.size;
          } catch (e) {
            if (e instanceof DOMException && e.name === 'AbortError') {
              generateStopRequested = true;
              break;
            }
            const msgSkip = `${key}: `;
            const reason = e instanceof Error ? e.message : String(e);
            console.warn('generate_internal skipped', msgSkip + reason);
            completed++;
          }

          setSaveGenProgress(
            completed,
            totalBar,
            completed < totalBar
              ? `内部ページ ${n} / ${toRun.length} 件目の処理が終わりました。${etaString(genStartMs, completed, totalBar)}`
              : `完了 (合計 ${formatDuration((Date.now() - genStartMs) / 1000)})`,
          );
        }

        if (generateStopRequested) {
          setSaveGenProgress(0, 1, '停止しました。');
          genRes = { success: false, aborted: true, size: lastSize };
        } else {
          setSaveGenProgress(
            totalBar,
            totalBar,
            `内部ページ ${toRun.length} 件ぶんの生成処理が完了しました。完了 (合計 ${formatDuration((Date.now() - genStartMs) / 1000)})`,
          );
          genRes = { success: true, size: lastSize };
        }
      } else {
        genRes = await apiPost('store/generate_lp.php', {});
        if (!genRes.success) throw new Error(genRes.error ?? 'サイト生成に失敗しました。');
      }

      if (genRes.aborted === true || generateStopRequested) {
        throw new DOMException('aborted', 'AbortError');
      }

      setSaveGenRowStatus('saveGenRowGen', 'done');
      ensureSaveGenProgressUi()?.classList.add('d-none');

      /** @type {number} */
      const szNum = typeof genRes.size === 'number' ? genRes.size : 0;
      showToast(`サイト生成完了！ (${(szNum / 1024).toFixed(1)} KB)`, 'success');

      await sleep(350);
      hideSaveGenerateModal();

      btnSaveGenerate.disabled = false;

      expandMaxReachable(3);

      tryNavigateToReachedStep(3);
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

      if (isAbort && savePhaseDone) {
        setSaveGenRowStatus('saveGenRowGen', 'pending');
      } else if (!savePhaseDone) {
        setSaveGenRowStatus('saveGenRowSave', 'error');
      } else {
        setSaveGenRowStatus('saveGenRowGen', 'error');
      }
      document.getElementById('saveGenFooterBusy')?.classList.add('d-none');
      document.getElementById('saveGenFooterDone')?.classList.remove('d-none');
      btnSaveGenerate.disabled = false;
    } finally {
      generateAbortController = null;
      generateStopRequested = false;
    }
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
    if (!btn || !status) return;

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

      btn.disabled = true;
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
      } catch (e) {
        const msg = e.message || String(e);
        setAiStatus(`❌ エラー: ${msg}`, 'danger');
        showToast(msg, 'danger');
        backup = null;
        if (undoBtn) undoBtn.hidden = true;
      } finally {
        btn.disabled = false;
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

    document.querySelectorAll('#ai-text-replace-panel .ai-chip').forEach(chip => {
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

    // Meta fields
    const titleInput = form.querySelector('input[name="meta[title]"]');
    const descInput  = form.querySelector('input[name="meta[description]"]');
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

    const PH_BLEND_KEY = 'lp_ph_blend_opacity';
    const PH_PRESETS = [
      [100, 100], [150, 150], [200, 200], [300, 300],
      [200, 150], [300, 200], [400, 300], [600, 400],
      [800, 600], [1200, 630], [1920, 1080],
    ];

    /** localStorage から元画像透過度(0〜1)を読む */
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
        const v = Math.max(0, Math.min(100, parseInt(phBlendInput.value || '25', 10)));
        phBlendInput.value = String(v);
        savePhBlendOpacity(v / 100);
      });
    }

    let targetElemId = '';
    /** @type {string} */
    let selectedPath = '';
    /** @type {number} */
    let origW = 0;
    /** @type {number} */
    let origH = 0;
    /** @type {string} モーダル表示中の元画像 displayURL */
    let origDisplayUrl = '';

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
      const u = (absUrl || '').trim();
      imgEl.onload = null;
      imgEl.onerror = null;
      if (!u) {
        dimsEl.textContent = 'サイズ：—';
        imgEl.removeAttribute('src');
        return;
      }
      dimsEl.textContent = 'サイズ：読み込み中…';
      const apply = () => {
        dimsEl.textContent = formatImgPxDimsLine(imgEl);
      };
      imgEl.onload = apply;
      imgEl.onerror = () => {
        dimsEl.textContent = 'サイズ：読み込みに失敗しました';
      };
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
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('load failed: ' + url));
        img.src = url;
      });
    }

    /**
     * Canvas でプレースホルダーをローカル生成（placehold.jp の CORS 問題を回避）。
     * @param {number} w @param {number} h
     * @returns {HTMLCanvasElement}
     */
    function drawLocalPlaceholder(w, h) {
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      const ctx = c.getContext('2d');
      ctx.fillStyle = '#cccccc';
      ctx.fillRect(0, 0, w, h);
      const fontSize = Math.max(11, Math.min(Math.floor(Math.min(w, h) / 7), 52));
      ctx.fillStyle = '#888888';
      ctx.font = `bold ${fontSize}px sans-serif`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(`${w} × ${h}`, w / 2, h / 2);
      return c;
    }

    /**
     * 元画像(100%) の上にプレースホルダーを mockAlpha で重ねた DataURL を返す。
     * @param {number} phW @param {number} phH
     * @param {string} origSrc   元画像 URL（CORS 失敗時はグレー背景で代替）
     * @param {number} mockAlpha モック画像の不透明度 0〜1
     * @returns {Promise<string>} DataURL
     */
    async function blendPlaceholder(phW, phH, origSrc, mockAlpha) {
      const canvas = document.createElement('canvas');
      canvas.width  = phW;
      canvas.height = phH;
      const ctx = canvas.getContext('2d');

      // ① 元画像を 100% で下地として描画
      if (origSrc) {
        try {
          const origImg = await loadImgCors(origSrc);
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
      ctx.drawImage(drawLocalPlaceholder(phW, phH), 0, 0, phW, phH);
      ctx.globalAlpha = 1;

      return canvas.toDataURL('image/png');
    }

    async function selectPlaceholder(w, h) {
      if (phBlendStatus) phBlendStatus.classList.remove('d-none');
      try {
        const alpha = getPhBlendOpacity();
        const dataUrl = await blendPlaceholder(w, h, origDisplayUrl, alpha);
        setRightSelection(dataUrl);
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

    function setRightSelection(path) {
      selectedPath = (path || '').trim();
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
      const orig = (btn.getAttribute('data-lp-original-src') || '').trim();
      const srcInp = form.querySelector(`[data-lp-id="${targetElemId}"][data-lp-field="src"]`);
      let leftSrc = '';
      if (srcInp && 'value' in srcInp && srcInp.value.trim()) {
        leftSrc = srcInp.value.trim();
      } else if (srcInp && 'placeholder' in srcInp && (srcInp.placeholder || '').trim()) {
        leftSrc = (srcInp.placeholder || '').trim();
      } else if (orig) {
        leftSrc = orig;
      }
      origDisplayUrl = '';
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
          leftImg.onerror = () => {
            if (dimsLeftEl) dimsLeftEl.textContent = 'サイズ：読み込みに失敗しました';
          };
          leftImg.src = displayUrl;
          if (typeof leftImg.decode === 'function') {
            void leftImg.decode().then(applyDims).catch(() => {});
          }
        } else {
          wireImgDimsReporting(leftImg, dimsLeftEl, '');
        }
      }
      resetRight();
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
      resetRight();
      wireImgDimsReporting(leftImg, dimsLeftEl, '');
      renderPlaceholderSection(0, 0);
    });
  }

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
      const tm = setTimeout(() => ctrl.abort(), 10000);
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
          ? '診断取得がタイムアウトしました（10秒）。再読込後に再確認してください。'
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
      btnSaveGenerate.addEventListener('click', runSaveAndGenerate);
    }

    document.getElementById('btnSaveGenModalDismiss')?.addEventListener('click', () => {
      if (btnSaveGenerate) btnSaveGenerate.disabled = false;
    });

    initAiTextReplace();

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
  }

  document.addEventListener('DOMContentLoaded', init);
})();
