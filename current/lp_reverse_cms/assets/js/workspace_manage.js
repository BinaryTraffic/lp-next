/**
 * Workspace list + owner-scoped delete (single/bulk).
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

  const role = (typeof window.LP_CMS !== 'undefined' && window.LP_CMS && window.LP_CMS.cmsRole)
    ? String(window.LP_CMS.cmsRole)
    : '';

  function humanBytes(n) {
    const u = ['B', 'KB', 'MB', 'GB'];
    let x = n;
    let i = 0;
    while (x >= 1024 && i < u.length - 1) {
      x /= 1024;
      i += 1;
    }
    return `${x.toFixed(1)} ${u[i]}`;
  }

  async function loadList() {
    if (!tbody || !table) return;
    if (helpEl) helpEl.textContent = '読み込み中…';
    tbody.innerHTML = '';
    try {
      const r = await fetch(storeBase + 'workspace_list.php', { credentials: 'same-origin' });
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
        const tr = document.createElement('tr');
        const id = String(row.id || '');
        const own = String(row.owner_email || '');
        const leg = row.legacy === true;
        const isCur = row.is_current === true || id === cur;
        if (isCur) tr.classList.add('table-primary');

        const canDelete = !(leg && role !== 'super_admin');
        const tdSel = document.createElement('td');
        tdSel.className = 'text-center';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'form-check-input';
        cb.disabled = !canDelete;
        cb.checked = selectedIds.has(id);
        cb.addEventListener('change', () => {
          if (cb.checked) selectedIds.add(id);
          else selectedIds.delete(id);
          updateBulkDeleteState();
          syncCheckAll();
        });
        tdSel.appendChild(cb);

        const tdId = document.createElement('td');
        const code = document.createElement('code');
        code.className = 'small';
        code.textContent = id;
        tdId.appendChild(code);
        if (isCur) {
          tdId.appendChild(document.createTextNode(' '));
          const b = document.createElement('span');
          b.className = 'badge bg-primary';
          b.textContent = '現在';
          tdId.appendChild(b);
        }

        const tdOwn = document.createElement('td');
        tdOwn.className = 'small';
        if (leg) {
          const em = document.createElement('em');
          em.className = 'text-warning';
          em.textContent = '未登録';
          tdOwn.appendChild(em);
        } else {
          tdOwn.textContent = own;
        }

        const tdSt = document.createElement('td');
        tdSt.className = 'small';
        tdSt.textContent = leg ? 'legacy' : String(row.state || 'active');

        const tdSz = document.createElement('td');
        tdSz.className = 'small';
        tdSz.textContent = humanBytes(Number(row.bytes) || 0);

        const tdMt = document.createElement('td');
        tdMt.className = 'small text-muted';
        tdMt.textContent = fmtUtc(row.mtime);

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-sm btn-outline-danger';
        del.textContent = '削除';
        del.disabled = !canDelete;
        if (leg && role === 'super_admin') {
          del.title = 'registry 未登録（旧データ）';
        }
        del.addEventListener('click', async () => {
          const w = leg ? '（未登録フォルダ）' : '';
          if (!window.confirm(`${id} を削除しますか？${w} data と output の両方が消えます。`)) return;
          try {
            const tok = (typeof window.LP_CMS !== 'undefined' && window.LP_CMS && window.LP_CMS.csrfToken)
              ? String(window.LP_CMS.csrfToken)
              : '';
            const rr = await fetch(storeBase + 'workspace_delete.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json; charset=UTF-8' },
              body: JSON.stringify({ workspace_id: id, csrf: tok }),
            });
            const out = await rr.json().catch(() => ({}));
            if (!rr.ok || !out.ok) {
              window.alert((out && out.error) ? String(out.error) : ('HTTP ' + rr.status));
              return;
            }
            if (out.cleared_session) {
              window.location.reload();
              return;
            }
            await loadList();
          } catch {
            window.alert('通信に失敗しました');
          }
        });

        const tdDel = document.createElement('td');
        tdDel.appendChild(del);

        tr.appendChild(tdSel);
        tr.appendChild(tdId);
        tr.appendChild(tdOwn);
        tr.appendChild(tdSt);
        tr.appendChild(tdSz);
        tr.appendChild(tdMt);
        tr.appendChild(tdDel);
        tbody.appendChild(tr);
      });
    } catch {
      if (helpEl) helpEl.textContent = '一覧の取得に失敗しました。';
    }
  }

  function updateBulkDeleteState() {
    if (!btnDeleteSelected) return;
    btnDeleteSelected.disabled = selectedIds.size === 0;
    btnDeleteSelected.textContent = selectedIds.size > 0
      ? `選択削除 (${selectedIds.size})`
      : '選択削除';
  }

  function syncCheckAll() {
    if (!checkAll || !tbody) return;
    const boxes = Array.from(tbody.querySelectorAll('input.form-check-input[type="checkbox"]:not(:disabled)'));
    if (boxes.length === 0) {
      checkAll.checked = false;
      checkAll.indeterminate = false;
      return;
    }
    const checked = boxes.filter(b => b.checked).length;
    checkAll.checked = checked > 0 && checked === boxes.length;
    checkAll.indeterminate = checked > 0 && checked < boxes.length;
  }

  async function deleteSelected() {
    if (selectedIds.size === 0 || bulkDeleting) return;
    const ids = Array.from(selectedIds);
    if (!window.confirm(`${ids.length} 件のワークスペースを削除しますか？ data と output の両方が消えます。`)) {
      return;
    }
    bulkDeleting = true;
    if (btnDeleteSelected) btnDeleteSelected.disabled = true;
    if (btnRef) btnRef.disabled = true;
    if (checkAll) checkAll.disabled = true;
    try {
      const tok = (typeof window.LP_CMS !== 'undefined' && window.LP_CMS && window.LP_CMS.csrfToken)
        ? String(window.LP_CMS.csrfToken)
        : '';
      const failed = [];
      let deletedCount = 0;
      let clearedSession = false;
      const total = ids.length;
      for (let i = 0; i < total; i += 1) {
        const id = ids[i];
        if (helpEl) helpEl.textContent = `削除中… ${i + 1}/${total}`;
        if (btnDeleteSelected) btnDeleteSelected.textContent = `削除中 ${i + 1}/${total}`;
        // 長時間ブロックを避けるため、1件ずつ Ajax で削除する
        const rr = await fetch(storeBase + 'workspace_delete.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json; charset=UTF-8' },
          body: JSON.stringify({ workspace_id: id, csrf: tok }),
        });
        const out = await rr.json().catch(() => ({}));
        if (!rr.ok || !out.ok) {
          failed.push(id);
        } else {
          deletedCount += 1;
          if (out.cleared_session) {
            clearedSession = true;
            break;
          }
          selectedIds.delete(id);
        }
        await new Promise(resolve => window.requestAnimationFrame(resolve));
      }
      if (clearedSession) {
        window.location.reload();
        return;
      }
      if (failed.length > 0) {
        window.alert(`削除完了: ${deletedCount}/${total} 件。失敗: ${failed.join(', ')}`);
      }
      await loadList();
    } catch {
      window.alert('通信に失敗しました');
    } finally {
      bulkDeleting = false;
      if (btnRef) btnRef.disabled = false;
      if (checkAll) checkAll.disabled = false;
      updateBulkDeleteState();
    }
  }

  function fmtUtc(sec) {
    const n = Number(sec);
    if (!Number.isFinite(n) || n <= 0) return '—';
    try {
      return new Date(n * 1000).toISOString().replace('T', ' ').slice(0, 19) + 'Z';
    } catch {
      return '—';
    }
  }

  const collapseEl = document.getElementById('workspaceManageCollapse');
  const detailsEl = document.getElementById('workspaceManageDetails');
  if (collapseEl && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
    collapseEl.addEventListener('shown.bs.collapse', () => { void loadList(); });
  }
  if (detailsEl) {
    detailsEl.addEventListener('toggle', () => {
      if (detailsEl.open) void loadList();
    });
  }

  if (btnRef) btnRef.addEventListener('click', () => { void loadList(); });
  if (btnDeleteSelected) btnDeleteSelected.addEventListener('click', () => { void deleteSelected(); });
  if (checkAll && tbody) {
    checkAll.addEventListener('change', () => {
      const boxes = Array.from(tbody.querySelectorAll('input.form-check-input[type="checkbox"]:not(:disabled)'));
      boxes.forEach(b => {
        b.checked = checkAll.checked;
        const tr = b.closest('tr');
        if (!tr) return;
        const idEl = tr.querySelector('code');
        const id = idEl ? String(idEl.textContent || '') : '';
        if (id !== '') {
          if (b.checked) selectedIds.add(id);
          else selectedIds.delete(id);
        }
      });
      updateBulkDeleteState();
      syncCheckAll();
    });
  }

  void 0;
}
