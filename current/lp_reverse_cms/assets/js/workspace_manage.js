/**
 * Workspace list + owner-scoped delete (single/bulk) + memo + open.
 * @param {string} storeBase e.g. "store/" (relative to CMS root)
 */
// eslint-disable-next-line no-unused-vars
function lpInitWorkspaceManage(storeBase) {
  const helpEl = document.getElementById('workspaceManageHelp');
  const tbody = document.getElementById('workspaceManageTbody');
  const table = document.getElementById('workspaceManageTable');
  const btnRef = document.getElementById('btnWorkspaceListRefresh');
  const btnDeleteSelected = document.getElementById('btnWorkspaceDeleteSelected');
  const checkAll = document.getElementById('workspaceManageCheckAll');
  const selectedIds = new Set();
  let bulkDeleting = false;
  let deleteTaskId = '';
  let pollTimer = null;
  const POLL_MS = 1500;
  const COL_COUNT = 6;

  const role = (typeof window.LP_CMS !== 'undefined' && window.LP_CMS && window.LP_CMS.cmsRole)
    ? String(window.LP_CMS.cmsRole)
    : '';
  const roleLc = role.toLowerCase().trim();

  function humanBytes(n) {
    const u = ['B', 'KB', 'MB', 'GB'];
    let x = n;
    let i = 0;
    while (x >= 1024 && i < u.length - 1) { x /= 1024; i += 1; }
    return `${x.toFixed(1)} ${u[i]}`;
  }

  function fmtUtc(sec) {
    const n = Number(sec);
    if (!Number.isFinite(n) || n <= 0) return '—';
    try { return new Date(n * 1000).toISOString().replace('T', ' ').slice(0, 16) + 'Z'; } catch { return '—'; }
  }

  function shortUrl(url) {
    try {
      const u = new URL(url);
      return u.hostname + (u.pathname !== '/' ? u.pathname : '');
    } catch { return url; }
  }

  // -------------------------------------------------------------------------
  // Row rendering
  // -------------------------------------------------------------------------
  function buildRow(row, cur) {
    const id = String(row.id || '');
    const leg = row.legacy === true;
    const isCur = row.is_current === true || id === cur;
    let canDelete;
    if (leg) {
      canDelete = true;
    } else if (typeof row.can_delete === 'boolean') {
      canDelete = row.can_delete;
    } else {
      canDelete = !(leg && roleLc !== 'super_admin');
    }
    const siteUrl = String(row.site_url || '');
    const pageTitle = String(row.page_title || '');
    const pageCount = Number(row.page_count) || 0;
    const analyzedAt = String(row.analyzed_at || '');
    const industryHint = String(row.industry_hint || '');
    const memo = String(row.memo || '');
    const hasData = siteUrl !== '';

    // ---- main row ----
    const tr = document.createElement('tr');
    tr.dataset.wsId = id;
    if (isCur) tr.classList.add('table-primary');
    tr.style.cursor = 'pointer';
    tr.title = 'ダブルクリックで詳細を開く';

    // col 1: checkbox
    const tdSel = document.createElement('td');
    tdSel.className = 'text-center align-middle';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'form-check-input';
    cb.disabled = !canDelete;
    cb.checked = selectedIds.has(id);
    cb.addEventListener('change', (e) => {
      e.stopPropagation();
      if (cb.checked) selectedIds.add(id);
      else selectedIds.delete(id);
      updateBulkDeleteState();
      syncCheckAll();
    });
    tdSel.appendChild(cb);

    // col 2: site / title
    const tdMain = document.createElement('td');
    tdMain.className = 'align-middle';
    if (hasData) {
      const urlLine = document.createElement('div');
      urlLine.className = 'd-flex align-items-center gap-1 flex-wrap';
      const link = document.createElement('a');
      link.href = siteUrl;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.className = 'text-truncate small fw-semibold';
      link.style.maxWidth = '320px';
      link.textContent = shortUrl(siteUrl);
      link.addEventListener('click', (e) => e.stopPropagation());
      urlLine.appendChild(link);
      if (isCur) {
        const badge = document.createElement('span');
        badge.className = 'badge bg-primary flex-shrink-0';
        badge.textContent = '現在';
        urlLine.appendChild(badge);
      }
      tdMain.appendChild(urlLine);
      if (pageTitle) {
        const titleEl = document.createElement('div');
        titleEl.className = 'text-muted small text-truncate';
        titleEl.style.maxWidth = '360px';
        titleEl.textContent = pageTitle;
        tdMain.appendChild(titleEl);
      }
      if (industryHint) {
        const indEl = document.createElement('div');
        indEl.className = 'text-muted';
        indEl.style.cssText = 'font-size:0.75rem;max-width:360px';
        indEl.textContent = '🏷 ' + industryHint;
        tdMain.appendChild(indEl);
      }
      if (memo) {
        const memoEl = document.createElement('div');
        memoEl.className = 'text-truncate fst-italic';
        memoEl.style.cssText = 'font-size:0.72em;color:#888;max-width:360px';
        memoEl.textContent = '📝 ' + memo;
        tdMain.appendChild(memoEl);
      }
    } else {
      // no lp_structure.json yet
      const codeEl = document.createElement('code');
      codeEl.className = 'small text-muted';
      codeEl.textContent = id;
      tdMain.appendChild(codeEl);
      if (isCur) {
        tdMain.appendChild(document.createTextNode(' '));
        const badge = document.createElement('span');
        badge.className = 'badge bg-primary';
        badge.textContent = '現在';
        tdMain.appendChild(badge);
      }
      if (leg) {
        const em = document.createElement('span');
        em.className = 'badge bg-warning text-dark ms-1';
        em.textContent = '未登録';
        tdMain.appendChild(em);
      }
    }

    // col 3: page count
    const tdPg = document.createElement('td');
    tdPg.className = 'small text-center align-middle';
    tdPg.textContent = hasData ? String(pageCount) : '—';

    // col 4: size
    const tdSz = document.createElement('td');
    tdSz.className = 'small align-middle';
    tdSz.textContent = humanBytes(Number(row.bytes) || 0);

    // col 5: analyzed date (prefer analyzed_at, fallback to mtime)
    const tdDt = document.createElement('td');
    tdDt.className = 'small text-muted align-middle';
    tdDt.textContent = analyzedAt || fmtUtc(row.mtime);

    // col 6: action buttons
    const tdAct = document.createElement('td');
    tdAct.className = 'align-middle';
    tdAct.style.whiteSpace = 'nowrap';

    // 詳細 button
    const btnDetail = document.createElement('button');
    btnDetail.type = 'button';
    btnDetail.className = 'btn btn-xs btn-outline-secondary me-1';
    btnDetail.style.fontSize = '0.72em';
    btnDetail.textContent = '▾ 詳細';
    btnDetail.addEventListener('click', (e) => { e.stopPropagation(); toggleDetail(tr, detailTr, row, memo); });

    // 開く button
    const btnOpen = document.createElement('button');
    btnOpen.type = 'button';
    btnOpen.className = 'btn btn-xs btn-outline-primary me-1';
    btnOpen.style.fontSize = '0.72em';
    btnOpen.textContent = '📂 開く';
    btnOpen.title = 'このWSを読み込んで編集を再開';
    btnOpen.addEventListener('click', (e) => { e.stopPropagation(); void openWorkspace(id); });

    // 指示書 button
    const btnChecklist = document.createElement('a');
    btnChecklist.href = 'image_checklist.php?ws=' + encodeURIComponent(id);
    btnChecklist.target = '_blank';
    btnChecklist.rel = 'noopener';
    btnChecklist.className = 'btn btn-xs btn-outline-success me-1';
    btnChecklist.style.fontSize = '0.72em';
    btnChecklist.title = '画像作業指示書を別タブで開く';
    btnChecklist.textContent = '📋 指示書';
    btnChecklist.addEventListener('click', (e) => { e.stopPropagation(); });

    // 削除 button
    const btnDel = document.createElement('button');
    btnDel.type = 'button';
    btnDel.className = 'btn btn-xs btn-outline-danger';
    btnDel.style.fontSize = '0.72em';
    btnDel.textContent = '削除';
    btnDel.disabled = !canDelete;
    if (leg && roleLc === 'super_admin') btnDel.title = 'registry 未登録（旧データ）';
    btnDel.addEventListener('click', (e) => { e.stopPropagation(); void deleteSingle(id, leg); });

    tdAct.appendChild(btnDetail);
    tdAct.appendChild(btnOpen);
    tdAct.appendChild(btnChecklist);
    tdAct.appendChild(btnDel);

    tr.appendChild(tdSel);
    tr.appendChild(tdMain);
    tr.appendChild(tdPg);
    tr.appendChild(tdSz);
    tr.appendChild(tdDt);
    tr.appendChild(tdAct);

    // ---- detail row (initially hidden) ----
    const detailTr = document.createElement('tr');
    detailTr.classList.add('ws-detail-row', 'd-none');
    const detailTd = document.createElement('td');
    detailTd.colSpan = COL_COUNT;
    detailTd.className = 'p-0';
    detailTr.appendChild(detailTd);

    // double-click opens detail
    tr.addEventListener('dblclick', () => toggleDetail(tr, detailTr, row, memo));

    return [tr, detailTr];
  }

  // -------------------------------------------------------------------------
  // Detail panel
  // -------------------------------------------------------------------------
  function buildDetailContent(row, currentMemo) {
    const id = String(row.id || '');
    const div = document.createElement('div');
    div.className = 'p-3 bg-light border-top small';

    // metadata dl
    const dl = document.createElement('dl');
    dl.className = 'row mb-2 small';
    const addDt = (label, value) => {
      const dt = document.createElement('dt');
      dt.className = 'col-sm-3 col-md-2 text-muted mb-1';
      dt.textContent = label;
      const dd = document.createElement('dd');
      dd.className = 'col-sm-9 col-md-10 mb-1';
      dd.textContent = value || '—';
      dl.appendChild(dt);
      dl.appendChild(dd);
    };
    const addDtCode = (label, value) => {
      const dt = document.createElement('dt');
      dt.className = 'col-sm-3 col-md-2 text-muted mb-1';
      dt.textContent = label;
      const dd = document.createElement('dd');
      dd.className = 'col-sm-9 col-md-10 mb-1';
      const code = document.createElement('code');
      code.style.fontSize = '0.8em';
      code.textContent = value || '—';
      dd.appendChild(code);
      dl.appendChild(dt);
      dl.appendChild(dd);
    };
    addDtCode('ワークスペース ID', id);
    addDt('所有者', String(row.owner_email || (row.legacy ? '未登録' : '')));
    addDt('状態', String(row.state || (row.legacy ? 'legacy' : 'active')));
    addDt('作成日時', String(row.created_at || '—'));
    addDt('最終アクティブ', String(row.last_active_at || '—'));
    if (row.site_url) addDt('解析URL', String(row.site_url));
    div.appendChild(dl);

    // memo editor
    const memoLabel = document.createElement('label');
    memoLabel.className = 'form-label small fw-semibold mb-1';
    memoLabel.textContent = 'メモ（最大500文字）';
    const memoRow = document.createElement('div');
    memoRow.className = 'd-flex gap-2 align-items-start';
    const ta = document.createElement('textarea');
    ta.className = 'form-control form-control-sm ws-memo-ta flex-grow-1';
    ta.rows = 2;
    ta.maxLength = 500;
    ta.placeholder = 'このワークスペースに関するメモを入力…';
    ta.value = currentMemo;
    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn btn-sm btn-outline-secondary flex-shrink-0';
    saveBtn.textContent = '保存';
    const savedMsg = document.createElement('span');
    savedMsg.className = 'text-success small d-none ms-1';
    savedMsg.textContent = '✓ 保存済み';
    saveBtn.addEventListener('click', async () => {
      saveBtn.disabled = true;
      savedMsg.classList.add('d-none');
      try {
        const tok = (typeof window.LP_CMS !== 'undefined' && window.LP_CMS) ? String(window.LP_CMS.csrfToken || '') : '';
        const rr = await fetch(storeBase + 'workspace_memo_update.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json; charset=UTF-8' },
          body: JSON.stringify({ workspace_id: id, memo: ta.value, csrf: tok }),
        });
        const out = await rr.json().catch(() => ({}));
        if (!rr.ok || !out.ok) { window.alert((out && out.error) ? String(out.error) : ('HTTP ' + rr.status)); return; }
        savedMsg.classList.remove('d-none');
        window.setTimeout(() => savedMsg.classList.add('d-none'), 2500);
      } catch { window.alert('通信に失敗しました'); }
      finally { saveBtn.disabled = false; }
    });
    memoRow.appendChild(ta);
    memoRow.appendChild(saveBtn);
    div.appendChild(memoLabel);
    div.appendChild(memoRow);
    div.appendChild(savedMsg);

    return div;
  }

  function toggleDetail(tr, detailTr, row, memo) {
    const isHidden = detailTr.classList.contains('d-none');
    if (isHidden) {
      // build content lazily
      const td = detailTr.querySelector('td');
      if (td && td.childElementCount === 0) {
        td.appendChild(buildDetailContent(row, memo));
      }
      detailTr.classList.remove('d-none');
      tr.querySelector('button')?.textContent && (tr.querySelector('button').textContent = '▴ 詳細');
    } else {
      detailTr.classList.add('d-none');
      tr.querySelector('button')?.textContent && (tr.querySelector('button').textContent = '▾ 詳細');
    }
  }

  // -------------------------------------------------------------------------
  // Open workspace
  // -------------------------------------------------------------------------
  async function openWorkspace(id) {
    if (!window.confirm(`${id} を開いて編集を再開しますか？\n現在の編集内容は保存されていない場合、失われます。`)) return;
    try {
      const tok = (typeof window.LP_CMS !== 'undefined' && window.LP_CMS) ? String(window.LP_CMS.csrfToken || '') : '';
      const rr = await fetch(storeBase + 'workspace_open.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
        body: JSON.stringify({ workspace_id: id, csrf: tok }),
      });
      const out = await rr.json().catch(() => ({}));
      if (!rr.ok || !out.ok) { window.alert((out && out.error) ? String(out.error) : ('HTTP ' + rr.status)); return; }
      window.location.href = window.location.pathname + '?step=2';
    } catch { window.alert('通信に失敗しました'); }
  }

  // -------------------------------------------------------------------------
  // Single delete
  // -------------------------------------------------------------------------
  async function deleteSingle(id, leg) {
    const w = leg ? '（未登録フォルダ）' : '';
    if (!window.confirm(`${id} を削除しますか？${w} data と output の両方が消えます。`)) return;
    try {
      const tok = (typeof window.LP_CMS !== 'undefined' && window.LP_CMS) ? String(window.LP_CMS.csrfToken || '') : '';
      const rr = await fetch(storeBase + 'workspace_delete.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
        body: JSON.stringify({ workspace_id: id, csrf: tok }),
      });
      const out = await rr.json().catch(() => ({}));
      if (!rr.ok || !out.ok) { window.alert((out && out.error) ? String(out.error) : ('HTTP ' + rr.status)); return; }
      if (out.cleared_session) { window.location.reload(); return; }
      await loadList();
    } catch { window.alert('通信に失敗しました'); }
  }

  // -------------------------------------------------------------------------
  // Load list
  // -------------------------------------------------------------------------
  let listLoading = false;
  async function loadList() {
    if (listLoading || !tbody || !table) return;
    listLoading = true;
    if (helpEl) helpEl.textContent = '読み込み中…';
    tbody.innerHTML = '';
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000);
    try {
      const r = await fetch(storeBase + 'workspace_list.php', { credentials: 'same-origin', signal: controller.signal });
      clearTimeout(timeoutId);
      const data = await r.json().catch(() => ({}));
      if (!r.ok || !data.ok) {
        if (helpEl) helpEl.textContent = (data && data.error) ? String(data.error) : ('HTTP ' + r.status);
        return;
      }
      const rows = Array.isArray(data.workspaces) ? data.workspaces : [];
      if (helpEl) {
        helpEl.textContent = rows.length === 0
          ? '表示するワークスペースはありません。'
          : `${rows.length} 件（削除は自分が所有者の ws_* のみ。未登録フォルダの削除は super_admin のみ）`;
      }
      table.classList.toggle('d-none', rows.length === 0);
      if (checkAll) checkAll.checked = false;
      selectedIds.clear();
      updateBulkDeleteState();
      const cur = (data.current_ws && String(data.current_ws)) || '';

      rows.forEach(row => {
        const [tr, detailTr] = buildRow(row, cur);
        tbody.appendChild(tr);
        tbody.appendChild(detailTr);
      });
    } catch {
      clearTimeout(timeoutId);
      if (helpEl) helpEl.textContent = '一覧の取得に失敗しました。';
    } finally {
      listLoading = false;
    }
  }

  // -------------------------------------------------------------------------
  // Bulk delete
  // -------------------------------------------------------------------------
  function updateBulkDeleteState() {
    if (!btnDeleteSelected) return;
    btnDeleteSelected.disabled = selectedIds.size === 0;
    btnDeleteSelected.textContent = selectedIds.size > 0 ? `選択削除 (${selectedIds.size})` : '選択削除';
  }

  function syncCheckAll() {
    if (!checkAll || !tbody) return;
    const boxes = Array.from(tbody.querySelectorAll('input.form-check-input[type="checkbox"]:not(:disabled)'));
    if (boxes.length === 0) { checkAll.checked = false; checkAll.indeterminate = false; return; }
    const checked = boxes.filter(b => b.checked).length;
    checkAll.checked = checked > 0 && checked === boxes.length;
    checkAll.indeterminate = checked > 0 && checked < boxes.length;
  }

  async function deleteSelected() {
    if (selectedIds.size === 0 || bulkDeleting) return;
    const ids = Array.from(selectedIds);
    if (!window.confirm(`${ids.length} 件のワークスペースを削除しますか？ data と output の両方が消えます。`)) return;
    bulkDeleting = true;
    if (btnDeleteSelected) btnDeleteSelected.disabled = true;
    if (btnRef) btnRef.disabled = true;
    if (checkAll) checkAll.disabled = true;
    try {
      const tok = (typeof window.LP_CMS !== 'undefined' && window.LP_CMS) ? String(window.LP_CMS.csrfToken || '') : '';
      const rr = await fetch(storeBase + 'workspace_delete_async_start.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
        body: JSON.stringify({ workspace_ids: ids, csrf: tok }),
      });
      const out = await rr.json().catch(() => ({}));
      if (!rr.ok || !out.ok) { window.alert((out && out.error) ? String(out.error) : ('HTTP ' + rr.status)); return; }
      deleteTaskId = String(out.task_id || '');
      if (deleteTaskId === '') { window.alert('task_id が取得できませんでした'); return; }
      await tickDeleteProgress();
      startDeletePolling();
    } catch { window.alert('通信に失敗しました'); }
  }

  function stopDeletePolling() {
    if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null; }
  }

  function startDeletePolling() {
    stopDeletePolling();
    pollTimer = window.setInterval(() => { void tickDeleteProgress(); }, POLL_MS);
  }

  async function tickDeleteProgress() {
    if (!bulkDeleting) return;
    try {
      const q = deleteTaskId ? `?task_id=${encodeURIComponent(deleteTaskId)}` : '';
      const rr = await fetch(storeBase + 'workspace_delete_async_progress.php' + q, { credentials: 'same-origin' });
      const out = await rr.json().catch(() => ({}));
      if (!rr.ok || !out.ok) { if (helpEl) helpEl.textContent = (out && out.error) ? String(out.error) : ('HTTP ' + rr.status); return; }
      const prog = String(out.progress_text || '000/000');
      const status = String(out.status || '');
      if (helpEl) helpEl.textContent = `削除ジョブ: ${prog} (${status})`;
      if (btnDeleteSelected) btnDeleteSelected.textContent = `削除中 ${prog}`;
      if (out.done === true) {
        stopDeletePolling();
        bulkDeleting = false;
        if (btnRef) btnRef.disabled = false;
        if (checkAll) checkAll.disabled = false;
        if (status === 'done') { await loadList(); }
        else if (status === 'stale') { if (helpEl) helpEl.textContent = '削除ジョブが stale になりました。'; window.alert('削除ジョブが応答しなくなりました（stale）。'); }
        else { window.alert('削除ジョブが失敗しました。'); }
        updateBulkDeleteState();
      }
    } catch { if (helpEl) helpEl.textContent = '進捗取得に失敗しました。'; }
  }

  // -------------------------------------------------------------------------
  // Init
  // -------------------------------------------------------------------------
  const collapseEl = document.getElementById('workspaceManageCollapse');
  const detailsEl = document.getElementById('workspaceManageDetails');
  const modalEl = document.getElementById('workspaceModal');
  if (collapseEl && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
    collapseEl.addEventListener('shown.bs.collapse', () => { void loadList(); });
  }
  if (modalEl) {
    modalEl.addEventListener('show.bs.modal', () => { void loadList(); });
  }
  if (detailsEl) {
    detailsEl.addEventListener('toggle', () => { if (detailsEl.open) void loadList(); });
  }

  if (btnRef) btnRef.addEventListener('click', () => { void loadList(); });
  if (btnDeleteSelected) btnDeleteSelected.addEventListener('click', () => { void deleteSelected(); });
  void loadList();
  if (checkAll && tbody) {
    checkAll.addEventListener('change', () => {
      const boxes = Array.from(tbody.querySelectorAll('input.form-check-input[type="checkbox"]:not(:disabled)'));
      boxes.forEach(b => {
        b.checked = checkAll.checked;
        const mainTr = b.closest('tr');
        const rowId = mainTr?.dataset.wsId || '';
        if (rowId !== '') { if (b.checked) selectedIds.add(rowId); else selectedIds.delete(rowId); }
      });
      updateBulkDeleteState();
      syncCheckAll();
    });
  }

  // Resume existing bulk-delete job on page load
  void (async () => {
    try {
      const rr = await fetch(storeBase + 'workspace_delete_async_progress.php', { credentials: 'same-origin' });
      const out = await rr.json().catch(() => ({}));
      if (!rr.ok || !out.ok || out.exists !== true) return;
      const st = String(out.status || '');
      deleteTaskId = String(out.task_id || '');
      if (st === 'queued' || st === 'running') {
        bulkDeleting = true;
        if (btnDeleteSelected) btnDeleteSelected.disabled = true;
        if (btnRef) btnRef.disabled = true;
        if (checkAll) checkAll.disabled = true;
        await tickDeleteProgress();
        startDeletePolling();
      }
    } catch { void 0; }
  })();

  void 0;
}
