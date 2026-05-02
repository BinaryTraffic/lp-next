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
  function resetAnalyzeProgressUi() {
    if (!progAnalyzeBar || !progAnalyzePct) return;
    progAnalyzeBar.style.width = '0%';
    progAnalyzePct.textContent = '';
    progAnalyzeBarOuter?.setAttribute('aria-valuenow', '0');
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
    if (progAnalyzeBar) {
      progAnalyzeBar.style.width = `${pct}%`;
    }
    progAnalyzeBarOuter?.setAttribute('aria-valuenow', String(Math.round(pct)));
    const det = typeof r.detail_ja === 'string' ? r.detail_ja : '';
    progAnalyzePct && (progAnalyzePct.textContent = det || `${pct}%`);
    progAnalyzeDetail && det && (progAnalyzeDetail.textContent = det);
  }

  /** @returns {Promise<Record<string, unknown>>} */
  async function apiPostAnalyzeStream(endpoint) {
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8', Accept: 'application/x-ndjson, application/json' },
      body: JSON.stringify({ stream_progress: true }),
    });

    const ctype = (res.headers.get('Content-Type') || '').toLowerCase();
    const text = await res.text();

    if (!res.ok) {
      const msg = ndjsonFirstJsonError(text) || safeJsonParseError(text);
      throw new Error(msg || `HTTP ${res.status}`);
    }

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

    try {
      await saveProjectProfile(false);
    } catch {
      /* プロフィールのみ失敗しても解析は続行 */
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
      const failed  = fetchRes.fetch_failed ?? 0;
      const total   = fetchRes.asset_total  ?? 0;

      const failNote = failed > 0 ? ` / 失敗 ${failed}件（debug.php で確認）` : '';
      setProgState(
        progFetch, 'done',
        `HTML ${htmlKb} KB | CSS ${css} / 画像 ${img} / JS ${js} / フォント ${fonts}（計 ${total}）${failNote}`
      );
      resetAnalyzeProgressUi();
      progAnalyzeBarWrap?.classList.remove('d-none');
      setProgState(progAnalyze, 'loading', 'HTMLを解析中…');

      // -- Phase 2: analyze（NDJSON: ツリー総ステップに対する進捗を可視化） --
      const analyzeRes = await apiPostAnalyzeStream('store/analyze_lp.php');
      if (!analyzeRes.success) throw new Error(analyzeRes.error ?? '解析に失敗しました。');

      let diagNote = '';
      const diag = analyzeRes.parse_diagnostics;
      if (diag && typeof diag === 'object') {
        const w = /** @type {{ walk_total_steps?: number, walk_completed_steps?: number, section_errors?: unknown[] }} */ (diag);
        if (typeof w.walk_total_steps === 'number' && typeof w.walk_completed_steps === 'number') {
          diagNote += ` | ツリー ${w.walk_completed_steps.toLocaleString()}/${w.walk_total_steps.toLocaleString()} ステップ`;
          if (w.walk_completed_steps < w.walk_total_steps && w.walk_incomplete) {
            diagNote += '（部分的）';
          }
        }
        if (Array.isArray(w.section_errors) && w.section_errors.length > 0) {
          diagNote += ` | セクションエラー ${w.section_errors.length}件（ログ: data/lp_structure_analyze.log）`;
          showToast('一部セクションがスキップされました（サーバーログを確認してください）', 'warning');
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
  // Step 1 — 企業・LPプロフィール（将来 AI 自動生成の入力）
  // -----------------------------------------------------------------------
  const PP_KEYS = [
    'company_name', 'representative_name', 'postal_code', 'address_pref', 'address_city',
    'address_line', 'address_building', 'phone_main', 'phone_fax', 'phone_tollfree',
    'appeal_points', 'lp_tone', 'brand_color', 'company_url',
    'sns_x', 'sns_line', 'sns_instagram', 'sns_facebook', 'sns_youtube', 'sns_tiktok',
    'company_industry', 'corporate_number', 'company_capital', 'company_history',
  ];

  /** 住所入力の手間削減用（他の項目は変更しません） */
  const ADDRESS_SAMPLE_FIELDS = {
    postal_code: '1500002',
    address_pref: '東京都',
    address_city: '渋谷区',
    address_line: '渋谷1-2-3',
    address_building: 'デモビル4F',
  };

  function applyAddressSampleOnly() {
    Object.entries(ADDRESS_SAMPLE_FIELDS).forEach(([k, v]) => {
      const el = document.getElementById(`pp-${k}`);
      if (el && 'value' in el) {
        el.value = v;
      }
    });
  }

  function collectProjectProfile() {
    /** @type {Record<string, string>} */
    const o = {};
    PP_KEYS.forEach(k => {
      const el = document.getElementById(`pp-${k}`);
      if (el && 'value' in el) {
        o[k] = String(el.value);
      }
    });
    return o;
  }

  /**
   * @param {Record<string, string>} data
   */
  function applyProjectProfile(data) {
    if (!data || typeof data !== 'object') return;
    PP_KEYS.forEach(k => {
      const el = document.getElementById(`pp-${k}`);
      if (!el || !Object.prototype.hasOwnProperty.call(data, k)) return;
      el.value = data[k] == null ? '' : String(data[k]);
    });
    syncBrandColorFromText();
  }

  function syncBrandColorPickerFromText() {
    const t = document.getElementById('pp-brand_color');
    const p = document.getElementById('pp-brand_color_picker');
    if (!(t instanceof HTMLInputElement) || !(p instanceof HTMLInputElement)) return;
    const v = t.value.trim();
    if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
      p.value = v;
    }
  }

  function syncBrandColorFromText() {
    syncBrandColorPickerFromText();
  }

  function syncBrandTextFromPicker() {
    const t = document.getElementById('pp-brand_color');
    const p = document.getElementById('pp-brand_color_picker');
    if (!(t instanceof HTMLInputElement) || !(p instanceof HTMLInputElement)) return;
    t.value = p.value;
  }

  /**
   * @param {boolean} showOkToast
   */
  async function saveProjectProfile(showOkToast) {
    const body = collectProjectProfile();
    const res = await fetch('store/project_profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'プロフィールの保存に失敗しました');
    }
    if (showOkToast) showToast('プロフィールを保存しました', 'success');
  }

  async function lookupPostalJp() {
    const zipInp = document.getElementById('pp-postal_code');
    if (!(zipInp instanceof HTMLInputElement)) return;
    const digits = zipInp.value.replace(/\D/g, '');
    if (digits.length !== 7) {
      showToast('郵便番号は7桁で入力してください', 'warning');
      return;
    }
    try {
      const u = 'https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + encodeURIComponent(digits);
      const res = await fetch(u, { mode: 'cors' });
      const j = await res.json();
      if (String(j.status) !== '200' || !j.results || !j.results[0]) {
        showToast('該当する住所が見つかりません', 'warning');
        return;
      }
      const r = j.results[0];
      const pref = document.getElementById('pp-address_pref');
      const city = document.getElementById('pp-address_city');
      const line = document.getElementById('pp-address_line');
      if (pref instanceof HTMLInputElement) pref.value = r.address1 || '';
      if (city instanceof HTMLInputElement) city.value = r.address2 || '';
      if (line instanceof HTMLInputElement) line.value = r.address3 || '';
      showToast('住所を反映しました', 'success');
    } catch {
      showToast('住所検索に失敗しました（ネットワーク等）', 'danger');
    }
  }

  function initProjectProfile() {
    const initial = window.LP_CMS?.projectProfileInitial;
    if (initial && typeof initial === 'object') {
      applyProjectProfile(initial);
    }

    const btnSave = document.getElementById('btnSaveProjectProfile');
    if (btnSave) {
      btnSave.addEventListener('click', () => {
        void (async () => {
          try {
            await saveProjectProfile(true);
          } catch (e) {
            showToast(e.message || String(e), 'danger');
          }
        })();
      });
    }

    const btnSample = document.getElementById('btnSampleProjectProfile');
    if (btnSample) {
      btnSample.addEventListener('click', () => {
        void (async () => {
          try {
            applyAddressSampleOnly();
            await saveProjectProfile(true);
            showToast('サンプル住所を反映しました', 'success');
          } catch (e) {
            showToast(e instanceof Error ? e.message : String(e), 'danger');
          }
        })();
      });
    }

    document.getElementById('pp-postal_lookup')?.addEventListener('click', () => void lookupPostalJp());

    const pPicker = document.getElementById('pp-brand_color_picker');
    const pText = document.getElementById('pp-brand_color');
    pPicker?.addEventListener('input', syncBrandTextFromPicker);
    pText?.addEventListener('input', syncBrandColorPickerFromText);
    syncBrandColorFromText();

    initCompanyProfileLookup();
  }

  /**
   * 企業名検索: 国税庁API（任意）＋ AI 参考ヒント。反映は確認チェック後のみ。
   */
  function initCompanyProfileLookup() {
    /** @type {{ matches: any[], ai: Record<string, string>|null }|null} */
    let lookupCache = null;

    const modalEl = document.getElementById('companyLookupModal');
    const btnOpen = document.getElementById('pp-company_lookup_btn');
    if (!modalEl || !btnOpen) return;

    const elLoading = document.getElementById('companyLookupLoading');
    const elError = document.getElementById('companyLookupError');
    const elBody = document.getElementById('companyLookupBody');
    const elNotice = document.getElementById('companyLookupNotice');
    const elAttr = document.getElementById('companyLookupAttribution');
    const elOffTbody = document.getElementById('companyLookupOfficialTbody');
    const elAiWrap = document.getElementById('companyLookupAiWrap');
    const elAiList = document.getElementById('companyLookupAiList');
    const chkOfficial = document.getElementById('companyLookupApplyOfficial');
    const chkVerified = document.getElementById('companyLookupVerified');
    const btnApply = document.getElementById('companyLookupApplyBtn');
    let officialAutoTimer = null;
    let officialAutoInFlight = false;

    function setInput(id, val) {
      const el = document.getElementById(id);
      if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
        el.value = val;
      }
    }

    function applyOfficialMatchToProfile(m) {
      if (!m || typeof m !== 'object') return;
      const num = String(m.corporate_number || '').replace(/\D/g, '');
      if (num.length === 13) {
        setInput('pp-corporate_number', num);
      }
      setInput('pp-address_pref', m.prefecture || '');
      setInput('pp-address_city', m.city || '');
      setInput('pp-address_line', m.street || '');
    }

    function getLookupPayload(skipAi) {
      const nameEl = document.getElementById('pp-company_name');
      const prefEl = document.getElementById('pp-address_pref');
      const cityEl = document.getElementById('pp-address_city');
      return {
        company_name: nameEl instanceof HTMLInputElement ? nameEl.value.trim() : '',
        address_pref: prefEl instanceof HTMLInputElement ? prefEl.value.trim() : '',
        address_city: cityEl instanceof HTMLInputElement ? cityEl.value.trim() : '',
        skip_ai: !!skipAi,
      };
    }

    function syncApplyButton() {
      if (!(btnApply instanceof HTMLButtonElement) || !(chkVerified instanceof HTMLInputElement)) return;
      let any = false;
      if (chkOfficial instanceof HTMLInputElement && chkOfficial.checked) {
        const r = document.querySelector('input[name="companyLookupOfficialPick"]:checked');
        if (r) any = true;
      }
      document.querySelectorAll('.company-lookup-ai-chk').forEach(c => {
        if (c instanceof HTMLInputElement && c.checked) any = true;
      });
      btnApply.disabled = !chkVerified.checked || !any;
    }

    function resetModal() {
      lookupCache = null;
      elLoading?.classList.add('d-none');
      elError?.classList.add('d-none');
      elBody?.classList.add('d-none');
      if (elError) elError.textContent = '';
      if (elOffTbody) elOffTbody.innerHTML = '';
      if (elAiList) elAiList.innerHTML = '';
      if (chkOfficial instanceof HTMLInputElement) {
        chkOfficial.checked = false;
        chkOfficial.disabled = true;
      }
      if (chkVerified instanceof HTMLInputElement) chkVerified.checked = false;
      if (btnApply instanceof HTMLButtonElement) btnApply.disabled = true;
    }

    function renderLookupResponse(data) {
      if (elNotice) elNotice.textContent = data.notice || '';
      if (elAttr) elAttr.textContent = data.attribution || '';
      const matches = data.official?.matches || [];
      const apiErr = data.official?.api_error;
      lookupCache = { matches, ai: data.ai_hints || null };

      if (elOffTbody) {
        elOffTbody.innerHTML = '';
        if (matches.length === 0) {
          const tr = document.createElement('tr');
          tr.innerHTML = `<td colspan="4" class="text-muted">該当する公表法人がありません（API未設定・名前相違・APIエラー${
            apiErr ? `: ${String(apiErr)}` : ''
          }）。</td>`;
          elOffTbody.appendChild(tr);
        } else {
          matches.forEach((m, i) => {
            const tr = document.createElement('tr');
            const addr = [m.prefecture, m.city, m.street].filter(Boolean).join(' ');
            tr.innerHTML = `<td><input type="radio" class="form-check-input" name="companyLookupOfficialPick" value="${i}"></td>
              <td class="font-monospace">${escapeHtml(String(m.corporate_number || ''))}</td>
              <td>${escapeHtml(String(m.name || ''))}</td>
              <td>${escapeHtml(addr)}</td>`;
            elOffTbody.appendChild(tr);
          });
        }
      }
      if (chkOfficial instanceof HTMLInputElement) {
        chkOfficial.disabled = matches.length === 0;
      }

      if (elAiList) elAiList.innerHTML = '';
      const hints = data.ai_hints;
      const aiLabels = [
        ['industry_hint', '業種（参考）', 'company_industry'],
        ['business_characteristics', '事業・特色（参考）', 'appeal_append'],
        ['official_url_hint', '会社URL（参考）', 'company_url'],
        ['capital_hint', '資本金（参考）', 'company_capital'],
        ['history_summary', '沿革・概要（参考）', 'company_history'],
      ];
      let aiVisible = false;
      if (hints && typeof hints === 'object' && elAiList) {
        aiLabels.forEach(([key, label, dest]) => {
          const v = hints[key];
          if (!v || !String(v).trim()) return;
          aiVisible = true;
          const li = document.createElement('li');
          li.className = 'list-group-item py-2';
          li.innerHTML = `<div class="fw-semibold text-secondary mb-1">${escapeHtml(label)}</div>
            <div class="mb-1">${escapeHtml(String(v))}</div>
            <div class="form-check">
              <input class="form-check-input company-lookup-ai-chk" type="checkbox" id="cl-ai-${key}"
                data-key="${escapeHtml(key)}" data-dest="${escapeHtml(dest)}">
              <label class="form-check-label" for="cl-ai-${key}">この内容をプロフィールへ反映する</label>
            </div>`;
          elAiList.appendChild(li);
        });
      }
      if (elAiWrap) elAiWrap.classList.toggle('d-none', !aiVisible);

      elBody?.classList.remove('d-none');
      elOffTbody?.querySelectorAll('input[name="companyLookupOfficialPick"]').forEach(r => {
        r.addEventListener('change', syncApplyButton);
      });
      document.querySelectorAll('.company-lookup-ai-chk').forEach(c => {
        c.addEventListener('change', syncApplyButton);
      });
      syncApplyButton();
    }

    async function tryAutoOfficialResolve() {
      if (window.__lpSuppressOfficialAutoResolve) return;
      const payload = getLookupPayload(true);
      if (!payload.company_name) return;
      if (officialAutoInFlight) return;
      officialAutoInFlight = true;
      try {
        const res = await fetch('store/company_profile_lookup.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json; charset=utf-8' },
          body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) return;
        const matches = data.official?.matches || [];
        if (matches.length === 1) {
          window.__lpSuppressOfficialAutoResolve = true;
          try {
            applyOfficialMatchToProfile(matches[0]);
            await saveProjectProfile(false);
            showToast('国税庁公表と一致する法人が1件のため、住所・法人番号を反映しました', 'success');
          } finally {
            setTimeout(() => {
              window.__lpSuppressOfficialAutoResolve = false;
            }, 0);
          }
        } else if (matches.length > 1) {
          if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
          const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
          resetModal();
          modal.show();
          elLoading?.classList.add('d-none');
          elError?.classList.add('d-none');
          renderLookupResponse(data);
          showToast('公表法人が複数あります。一覧から選んで確定してください', 'info');
        }
      } finally {
        officialAutoInFlight = false;
      }
    }

    function scheduleAutoOfficialResolve() {
      clearTimeout(officialAutoTimer);
      officialAutoTimer = setTimeout(() => void tryAutoOfficialResolve(), 520);
    }

    chkOfficial?.addEventListener('change', syncApplyButton);
    chkVerified?.addEventListener('change', syncApplyButton);

    btnOpen.addEventListener('click', () => {
      const payload = getLookupPayload(false);
      if (!payload.company_name) {
        showToast('企業名を入力してください', 'warning');
        return;
      }
      if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        showToast('モーダルを開けません（Bootstrap 未読込）', 'danger');
        return;
      }
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      resetModal();
      elLoading?.classList.remove('d-none');
      modal.show();

      void (async () => {
        try {
          const res = await fetch('store/company_profile_lookup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify(payload),
          });
          const data = await res.json().catch(() => ({}));
          elLoading?.classList.add('d-none');
          if (!res.ok || !data.ok) {
            if (elError) {
              elError.textContent = data.error || '検索に失敗しました';
              elError.classList.remove('d-none');
            }
            return;
          }
          renderLookupResponse(data);
        } catch (e) {
          elLoading?.classList.add('d-none');
          if (elError) {
            elError.textContent = e instanceof Error ? e.message : String(e);
            elError.classList.remove('d-none');
          }
        }
      })();
    });

    ['pp-company_name', 'pp-address_pref', 'pp-address_city'].forEach(id => {
      document.getElementById(id)?.addEventListener('input', scheduleAutoOfficialResolve);
      document.getElementById(id)?.addEventListener('blur', scheduleAutoOfficialResolve);
    });

    btnApply?.addEventListener('click', () => {
      if (!(chkVerified instanceof HTMLInputElement) || !chkVerified.checked) {
        showToast('公式情報で確認した旨にチェックしてください', 'warning');
        return;
      }
      let any = false;
      if (chkOfficial instanceof HTMLInputElement && chkOfficial.checked) {
        const r = document.querySelector('input[name="companyLookupOfficialPick"]:checked');
        if (!r || !(r instanceof HTMLInputElement) || !lookupCache?.matches) {
          showToast('公表法人を1件選択してください（または所在地反映のチェックを外してください）', 'warning');
          return;
        }
        const idx = parseInt(r.value, 10);
        const m = lookupCache.matches[idx];
        if (m) {
          any = true;
          applyOfficialMatchToProfile(m);
        }
      }

      document.querySelectorAll('.company-lookup-ai-chk').forEach(c => {
        if (!(c instanceof HTMLInputElement) || !c.checked) return;
        const dest = c.getAttribute('data-dest') || '';
        const k = c.getAttribute('data-key') || '';
        const hints = lookupCache?.ai;
        if (!hints || !k) return;
        const val = String(hints[k] || '').trim();
        if (!val) return;
        any = true;
        if (dest === 'appeal_append') {
          const ap = document.getElementById('pp-appeal_points');
          if (ap instanceof HTMLTextAreaElement) {
            const cur = ap.value.trim();
            const line = `【事業・特色（要確認）】${val}`;
            ap.value = cur ? `${cur}\n${line}` : line;
          }
        } else {
          setInput(`pp-${dest}`, val);
        }
      });

      if (!any) {
        showToast('反映する項目を選んでください', 'warning');
        return;
      }
      syncBrandColorFromText();
      bootstrap.Modal.getInstance(modalEl)?.hide();
      void (async () => {
        try {
          await saveProjectProfile(true);
        } catch (err) {
          showToast(err instanceof Error ? err.message : String(err), 'danger');
        }
      })();
    });
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
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
      const diagSummary = document.getElementById('step3DiagSummary');
      if (diagSummary) {
        void loadDiagnostics(diagSummary);
      }
      btnSaveGenerate.disabled = false;

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
    const noLimitChk = document.getElementById('ai-replace-no-limit');
    if (!btn || !status) return null;

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

    /**
     * @param {{ isAuto?: boolean }} opts
     * @returns {Promise<boolean>} API 成功で置換処理まで完了したら true
     */
    async function performAiTextReplace(opts = {}) {
      const isAuto = !!opts.isAuto;
      const form = document.getElementById('clientDataForm');
      const industryInp = document.getElementById('ai-industry');
      const toneSel = document.getElementById('ai-tone');
      if (!form || !industryInp) return false;

      const industry = industryInp.value.trim();
      if (!industry) {
        setAiStatus('業種を入力してください', 'danger');
        if (!isAuto) {
          showToast('業種を入力してください。', 'warning');
          industryInp.focus();
        }
        return false;
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
        if (!isAuto) {
          showToast('置換対象のテキストがありません', 'warning');
        }
        backup = null;
        return false;
      }

      const noLimit = !!(noLimitChk && noLimitChk.checked);
      const elementTotalBeforeSlice = elements.length;

      if (!noLimit && elements.length > AI_TEXT_REPLACE_MAX) {
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
      if (!noLimit && elementTotalBeforeSlice > AI_TEXT_REPLACE_MAX) {
        setAiStatus(
          `${elementTotalBeforeSlice}件中 先頭${AI_TEXT_REPLACE_MAX}件を処理します — ${elements.length} 件を AI で生成中…`,
          'muted',
        );
      } else {
        setAiStatus(`${elements.length} 件のテキストを AI で生成中…`, 'muted');
      }

      if (isAuto) {
        showToast('元LP業種で AI 置換を自動実行中…', 'info');
      }

      try {
        const srcCtx =
          window.LP_CMS?.aiSourceIndustry && window.LP_CMS.aiSourceIndustry.trim() !== ''
            ? `参照元LPの業種推定: ${window.LP_CMS.aiSourceIndustry}`
            : '';
        const res = await fetch('store/text_replace.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json; charset=utf-8' },
          body: JSON.stringify({
            industry,
            tone,
            ...(srcCtx !== '' ? { source_context: srcCtx } : {}),
            ...(noLimit ? { no_element_limit: true } : {}),
            elements,
          }),
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
        return true;
      } catch (e) {
        const msg = e.message || String(e);
        setAiStatus(`❌ エラー: ${msg}`, 'danger');
        showToast(msg, 'danger');
        backup = null;
        if (undoBtn) undoBtn.hidden = true;
        return false;
      } finally {
        btn.disabled = false;
      }
    }

    btn.addEventListener('click', () => void performAiTextReplace({ isAuto: false }));

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

    return performAiTextReplace;
  }

  /**
   * 解析直後など、Step2 初回のみ元LP業種で text_replace を走らせる。
   * @param {number} step
   * @param {boolean} skipAuto 真のとき自動実行しない（例: プレビューから戻った直後）
   * @param {((opts?: { isAuto?: boolean }) => Promise<boolean>)|null} runAiReplace
   */
  function scheduleAutoAiReplace(step, runAiReplace, skipAuto = false) {
    if (skipAuto || step !== 2 || typeof runAiReplace !== 'function') return;
    const fp = window.LP_CMS?.aiStructureFingerprint || '';
    const ind = window.LP_CMS?.aiSourceIndustry || '';
    if (!fp || !ind) return;

    const storageKey = `lp_reverse_ai_auto_done_${fp}`;
    try {
      if (sessionStorage.getItem(storageKey) === '1') return;
    } catch {
      /* ストレージ不可時は毎回試行 */
    }

    requestAnimationFrame(() => {
      setTimeout(() => {
        void (async () => {
          const ok = await runAiReplace({ isAuto: true });
          if (ok) {
            try {
              sessionStorage.setItem(storageKey, '1');
            } catch {
              /* ignore */
            }
          }
        })();
      }, 480);
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
   * @param {string} apiPath /output/... 形式
   * @returns {string}
   */
  /**
   * CMS がサブディレクトリ配下でも /output/... が正しく解決されるよう baseURI 基準にする。
   * @param {string} apiPath /output/... または output/...
   */
  function publicUrlFromApiPath(apiPath) {
    if (!apiPath) return '';
    const s = apiPath.trim();
    if (/^https?:\/\//i.test(s)) return s;
    const raw = s.replace(/^\//, '');
    try {
      return new URL(raw, document.baseURI).href;
    } catch {
      return s.startsWith('/') ? s : `/${s}`;
    }
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
        '画像URLがワークスペース内（output/ws_…）ではありません。解析済みLPのアセットURLを使ってください。',
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
      showToast('画像を更新しました。保存してからLP生成してください', 'success');
    } catch (e) {
      showToast(e.message || String(e), 'danger');
    } finally {
      btnEl.disabled = false;
      btnEl.textContent = prevText;
    }
  }

  /**
   * 画像URLの手動置き換え（モーダル・アップロード・ワークスペース内サムネイル）。
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
    const gallery = document.getElementById('imageReplaceGallery');
    const galleryEmpty = document.getElementById('imageReplaceGalleryEmpty');
    const applyBtn = document.getElementById('imageReplaceApply');

    let targetElemId = '';
    /** @type {string} */
    let selectedPath = '';

    function resolveDisplayUrl(pathOrUrl) {
      const s = (pathOrUrl || '').trim();
      if (!s) return '';
      if (/^https?:\/\//i.test(s) || /^data:/i.test(s)) return s;
      return publicUrlFromApiPath(s.startsWith('/') ? s : `/${s}`);
    }

    function setRightSelection(path) {
      selectedPath = (path || '').trim();
      const url = resolveDisplayUrl(selectedPath);
      if (url && rightImg && rightPh) {
        rightImg.src = url;
        rightImg.classList.remove('d-none');
        rightPh.classList.add('d-none');
        if (applyBtn) applyBtn.disabled = false;
      } else {
        selectedPath = '';
        if (rightImg) {
          rightImg.removeAttribute('src');
          rightImg.classList.add('d-none');
        }
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
        void fillGallery();
      } catch (e) {
        showToast(e.message || String(e), 'danger');
      }
    }

    async function fillGallery() {
      if (!gallery || !galleryEmpty) return;
      gallery.innerHTML = '';
      try {
        const res = await fetch('store/list_workspace_images.php', { cache: 'no-store' });
        const data = await res.json().catch(() => ({}));
        if (!data.ok || !Array.isArray(data.items)) return;
        if (data.items.length === 0) {
          galleryEmpty.classList.remove('d-none');
          return;
        }
        galleryEmpty.classList.add('d-none');
        data.items.forEach(item => {
          const path = typeof item.path === 'string' ? item.path : '';
          if (!path) return;
          const url = resolveDisplayUrl(path);
          const wrap = document.createElement('button');
          wrap.type = 'button';
          wrap.className = 'btn p-0 border rounded bg-white';
          wrap.style.width = '72px';
          wrap.style.height = '72px';
          wrap.style.overflow = 'hidden';
          wrap.title = typeof item.name === 'string' ? item.name : '';
          const im = document.createElement('img');
          im.src = url;
          im.alt = wrap.title;
          im.className = 'w-100 h-100';
          im.style.objectFit = 'cover';
          wrap.appendChild(im);
          wrap.addEventListener('click', () => setRightSelection(path));
          gallery.appendChild(wrap);
        });
      } catch {
        galleryEmpty.classList.remove('d-none');
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
      if (leftImg) {
        leftImg.src = resolveDisplayUrl(leftSrc);
      }
      resetRight();
      void fillGallery();
      modal.show();
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
      srcInp.value = resolveDisplayUrl(selectedPath);
      srcInp.dispatchEvent(new Event('input', { bubbles: true }));
      srcInp.dispatchEvent(new Event('change', { bubbles: true }));
      modal.hide();
      showToast('画像URLを更新しました', 'success');
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
      targetElemId = '';
      resetRight();
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
  function renderStep3DiagLoading(targetEl) {
    targetEl.innerHTML = `
      <div class="border rounded-3 p-3 bg-light text-start shadow-sm">
        <div class="small fw-semibold mb-2 text-secondary">アセット診断の進行状況</div>
        <ul class="list-unstyled small mb-3">
          <li class="mb-2 text-success"><i class="bi bi-check-circle-fill me-1"></i>ブラウザ側の準備</li>
          <li class="mb-2 text-primary">
            <span class="spinner-border spinner-border-sm me-1 align-middle" role="status"></span>
            <span class="align-middle">サーバーで出力HTML・CSSをスキャンしています…</span>
          </li>
          <li class="text-muted"><i class="bi bi-circle me-1"></i>結果をこの画面に反映</li>
        </ul>
        <div class="progress" style="height:6px" aria-hidden="true">
          <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:100%"></div>
        </div>
        <p class="small text-muted mb-0 mt-2">初回のみ数十秒かかることがあります。完了すると下記に件数が表示されます。</p>
      </div>`;
  }

  /**
   * @param {HTMLElement|null} targetEl
   * @returns {Promise<Record<string, unknown>|null>}
   */
  async function loadDiagnostics(targetEl) {
    const showProgress = targetEl && targetEl.id === 'step3DiagSummary';
    if (showProgress) {
      renderStep3DiagLoading(targetEl);
    }

    try {
      const res = await fetch('store/debug.php', { cache: 'no-store' });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const raw = await res.text();
      let data = null;
      try {
        data = JSON.parse(raw);
      } catch {
        data = null;
      }
      if (!data || typeof data !== 'object') {
        throw new Error('Invalid JSON');
      }
      if (!targetEl) return data;

      const su = data.summary || {};
      const ou = data.output_unreplaced || {};
      const unreplacedTotal =
        typeof ou.live_total === 'number'
          ? ou.live_total
          : typeof ou.total === 'number'
            ? ou.total
            : 0;
      const ok = unreplacedTotal === 0;
      const leftOver = unreplacedTotal;
      const totalMap = su.map_key_count ?? 0;
      const css = su.map_css ?? 0;
      const img = su.map_img ?? 0;
      const js = su.map_js ?? 0;
      const diskCss = su.disk_css ?? 0;
      const diskImg = su.disk_img ?? 0;
      const diskJs = su.disk_js ?? 0;

      const doneAt = new Date();
      const doneStr = doneAt.toLocaleTimeString('ja-JP', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      });

      const healthBadge = ok
        ? '<span class="badge bg-success">✓ 生成HTML内の外部URL残存なし</span>'
        : `<span class="badge bg-warning text-dark">⚠ 生成HTMLに絶対URL残存：${leftOver}件</span>`;

      targetEl.innerHTML = `
        <div class="row g-2 text-start">
          <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <strong>アセット状況</strong> ${healthBadge}
            </div>
            <p class="small text-success mb-2 mb-md-0">
              <i class="bi bi-check2-all me-1"></i>診断が完了しました（${doneStr}）
            </p>
          </div>
          <div class="col-4">
            <div class="p-2 rounded border text-center small bg-white">
              <i class="bi bi-filetype-css text-primary fs-4"></i><br>
              <strong>${diskCss}</strong> CSS<br>
              <span class="text-muted">map: ${css}</span>
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 rounded border text-center small bg-white">
              <i class="bi bi-image text-success fs-4"></i><br>
              <strong>${diskImg}</strong> 画像<br>
              <span class="text-muted">map: ${img}</span>
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 rounded border text-center small bg-white">
              <i class="bi bi-filetype-js text-warning fs-4"></i><br>
              <strong>${diskJs}</strong> JS<br>
              <span class="text-muted">map: ${js}</span>
            </div>
          </div>
          <div class="col-12">
            <p class="small text-muted mb-0">asset_map エントリ数: <strong>${totalMap}</strong></p>
          </div>
          ${!ok ? `<div class="col-12"><div class="alert alert-warning small mb-0 mt-1">
            スタイルが反映されない場合は <strong>Step 1 に戻り「解析する」を再実行</strong>してください。
          </div></div>` : ''}
        </div>`;
      return data;
    } catch (e) {
      if (targetEl) {
        targetEl.innerHTML = `<div class="alert alert-danger small mb-0">
          <strong>診断の取得に失敗しました。</strong> しばらくしてからページを再読み込みするか、上部の診断ボタンで JSON を確認してください。
          <div class="small mt-1 text-muted">${String(e.message || e)}</div>
        </div>`;
      }
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
    let skipAutoAiFromPreview = false;
    try {
      const u = new URL(window.location.href);
      if (u.searchParams.get('from_preview') === '1') {
        skipAutoAiFromPreview = true;
        u.searchParams.delete('from_preview');
        const q = u.searchParams.toString();
        history.replaceState(null, '', u.pathname + (q ? '?' + q : '') + u.hash);
      }
    } catch {
      /* ignore */
    }

    goToStep(step);
    bindImagePreviews();
    bindImageMemoRefine();
    bindImageReplaceModal();
    initProjectProfile();

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

    const runAiReplace = initAiTextReplace();
    scheduleAutoAiReplace(step, runAiReplace, skipAutoAiFromPreview);

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
