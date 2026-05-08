/**
 * Workspace list + owner-scoped delete (store/workspace_list.php, workspace_delete.php).
 * @param {string} storeBase e.g. "store/" (relative to CMS root)
 */
// eslint-disable-next-line no-unused-vars
function lpInitWorkspaceManage(storeBase) {
  const helpEl = document.getElementById('workspaceManageHelp');
  const tbody = document.getElementById('workspaceManageTbody');
  const table = document.getElementById('workspaceManageTable');
  const btnRef = document.getElementById('btnWorkspaceListRefresh');

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
      const cur = (data.current_ws && String(data.current_ws)) || '';

      rows.forEach(row => {
        const tr = document.createElement('tr');
        const id = String(row.id || '');
        const own = String(row.owner_email || '');
        const leg = row.legacy === true;
        const isCur = row.is_current === true || id === cur;
        if (isCur) tr.classList.add('table-primary');

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
        del.disabled = leg && role !== 'super_admin';
        if (leg && role === 'super_admin') {
          del.title = 'registry 未登録（旧データ）';
        }
        del.addEventListener('click', async () => {
          const w = leg ? '（未登録フォルダ）' : '';
          if (!window.confirm(`${id} を削除しますか？${w} data と output の両方が消えます。`)) return;
          try {
            const rr = await fetch(storeBase + 'workspace_delete.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json; charset=UTF-8' },
              body: JSON.stringify({ workspace_id: id }),
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

  void 0;
}
