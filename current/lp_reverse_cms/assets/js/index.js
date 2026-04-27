/**
 * LP Reverse CMS — Admin UI controller
 */
(() => {
  'use strict';

  // -----------------------------------------------------------------------
  // State
  // -----------------------------------------------------------------------
  let currentStep = window.LP_CMS?.initialStep ?? 1;

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

  // Step 2
  const btnBackToStep1  = document.getElementById('btnBackToStep1');
  const btnResetClient  = document.getElementById('btnResetClient');
  const btnSaveGenerate = document.getElementById('btnSaveGenerate');
  const editFormWrapper = document.getElementById('editFormWrapper');
  const generateError   = document.getElementById('generateError');
  const generateSuccess = document.getElementById('generateSuccess');

  // Step 3
  const btnEditAgain = document.getElementById('btnEditAgain');

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
  // Progress helpers (Step 1)
  // -----------------------------------------------------------------------
  function setProgState(el, state, detail = '') {
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
    if (detailEl) detailEl.textContent = detail;
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

    try {
      // -- Phase 1: fetch HTML + download assets --
      const fetchRes = await apiPost('store/fetch_lp.php', { url });
      if (!fetchRes.success) throw new Error(fetchRes.error ?? 'HTML取得に失敗しました。');

      const htmlKb  = ((fetchRes.html_size ?? fetchRes.size ?? 0) / 1024).toFixed(1);
      const css     = fetchRes.asset_css    ?? 0;
      const img     = fetchRes.asset_img    ?? 0;
      const js      = fetchRes.asset_js     ?? 0;
      const fonts   = fetchRes.asset_fonts  ?? 0;
      const failed  = fetchRes.fetch_failed ?? 0;
      const total   = fetchRes.asset_total  ?? 0;

      const failNote = failed > 0 ? ` / 失敗 ${failed}件（debug.php で確認）` : '';
      setProgState(
        progFetch, 'done',
        `HTML ${htmlKb} KB | CSS ${css} / 画像 ${img} / JS ${js} / フォント ${fonts}（計 ${total}）${failNote}`
      );
      setProgState(progAnalyze, 'loading', 'HTMLを解析中…');

      // -- Phase 2: analyze --
      const analyzeRes = await apiPost('store/analyze_lp.php', {});
      if (!analyzeRes.success) throw new Error(analyzeRes.error ?? '解析に失敗しました。');

      setProgState(progAnalyze, 'done',
        `${analyzeRes.section_count}セクション / ${analyzeRes.total_elements}要素を抽出`);

      await sleep(600);
      window.location.href = window.location.pathname + '?step=2';

    } catch (err) {
      showError(fetchError, err.message);
      setProgState(progFetch,   'error');
      setProgState(progAnalyze, 'error');
      btnFetchAnalyze.disabled = false;
    }
  }

  // -----------------------------------------------------------------------
  // Step 2 — Save & Generate
  // -----------------------------------------------------------------------
  async function runSaveAndGenerate() {
    btnSaveGenerate.disabled = true;
    generateError.classList.add('d-none');
    generateSuccess.classList.add('d-none');

    try {
      const clientData = collectFormData();

      // -- Save client data --
      const saveRes = await apiPost('store/save_client.php', clientData);
      if (!saveRes.success) throw new Error(saveRes.error ?? '保存に失敗しました。');

      // -- Generate LP --
      const genRes = await apiPost('store/generate_lp.php', {});
      if (!genRes.success) throw new Error(genRes.error ?? 'LP生成に失敗しました。');

      showToast(`LP生成完了！ (${(genRes.size / 1024).toFixed(1)} KB)`, 'success');
      await sleep(500);
      goToStep(3);

    } catch (err) {
      showError(generateError, err.message);
      btnSaveGenerate.disabled = false;
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
      if (value) {
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
  async function apiPost(endpoint, data) {
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      body: JSON.stringify(data),
    });
    return res.json();
  }

  function showError(el, message) {
    el.textContent = message;
    el.classList.remove('d-none');
  }

  function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
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
  // Diagnostics
  // -----------------------------------------------------------------------
  async function loadDiagnostics(targetEl) {
    try {
      const data = await fetch('store/debug.php').then(r => r.json());
      if (!targetEl) return data;

      const ok     = data.output_health?.ok;
      const leftOver = data.output_health?.absolute_refs_remaining ?? '?';
      const totalMap = data.assets?.map_total ?? 0;
      const css    = data.assets?.map_css ?? 0;
      const img    = data.assets?.map_img ?? 0;
      const js     = data.assets?.map_js  ?? 0;
      const diskCss = data.assets?.disk_css?.count ?? 0;
      const diskImg = data.assets?.disk_img?.count ?? 0;
      const diskJs  = data.assets?.disk_js?.count  ?? 0;

      const healthBadge = ok
        ? '<span class="badge bg-success">✓ URLの置換OK</span>'
        : `<span class="badge bg-warning text-dark">⚠ 絶対URL残存：${leftOver}件</span>`;

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
          ${!ok ? `<div class="col-12"><div class="alert alert-warning small mb-0 mt-1">
            スタイルが反映されない場合は <strong>Step 1 に戻り「解析する」を再実行</strong>してください。
          </div></div>` : ''}
        </div>`;
      return data;
    } catch (e) {
      if (targetEl) targetEl.innerHTML = '<p class="text-danger small">診断取得に失敗しました。</p>';
      return null;
    }
  }

  function openDiagModal() {
    const diagContent = document.getElementById('diagContent');
    if (!diagContent) return;

    diagContent.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';

    const modal = new bootstrap.Modal(document.getElementById('diagModal'));
    modal.show();

    loadDiagnostics(null).then(data => {
      if (!data) {
        diagContent.innerHTML = '<p class="text-danger">診断データを取得できませんでした。</p>';
        return;
      }
      diagContent.innerHTML = `
        <pre class="bg-dark text-success rounded p-3 small" style="max-height:400px;overflow:auto">${JSON.stringify(data, null, 2)}</pre>`;
    });
  }

  // -----------------------------------------------------------------------
  // Init
  // -----------------------------------------------------------------------
  function init() {
    const step = resolveInitialStep();
    goToStep(step);
    bindImagePreviews();

    // Diagnostic modal button
    const btnDiag = document.getElementById('btnDiag');
    if (btnDiag) {
      btnDiag.addEventListener('click', openDiagModal);
    }

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

    initAiTextReplace();

    // Step 3 events
    if (btnEditAgain) {
      btnEditAgain.addEventListener('click', () => {
        window.location.href = window.location.pathname + '?step=2';
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
