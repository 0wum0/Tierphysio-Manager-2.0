(() => {
  const app = document.getElementById('emailsApp');
  if (!app) return;

  const apiUrl = app.dataset.apiUrl || '/api/emails.php';
  const csrf = app.dataset.csrf || '';

  const btnCompose = document.getElementById('btnCompose');
  const btnLoadInbox = document.getElementById('btnLoadInbox');
  const btnHealth = document.getElementById('btnHealth');

  const folderSelect = document.getElementById('folderSelect');
  const searchInput = document.getElementById('searchInput');
  const metaText = document.getElementById('metaText');

  const inboxList = document.getElementById('inboxList');
  const messageHeader = document.getElementById('messageHeader');
  const messageBodyWrap = document.getElementById('messageBodyWrap');

  const toast = document.getElementById('toast');
  const toastBox = document.getElementById('toastBox');
  const toastMsg = document.getElementById('toastMsg');

  const composeModal = document.getElementById('composeModal');
  const composeBackdrop = document.getElementById('composeBackdrop');
  const btnCloseCompose = document.getElementById('btnCloseCompose');
  const btnCancelCompose = document.getElementById('btnCancelCompose');
  const btnSend = document.getElementById('btnSend');

  const composeTo = document.getElementById('composeTo');
  const composeSubject = document.getElementById('composeSubject');
  const composeBody = document.getElementById('composeBody');

  let items = [];
  let selectedUid = null;
  let loading = false;

  function escapeHtml(s) {
    return String(s ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function showToast(type, msg) {
    toast.classList.remove('hidden');
    toastMsg.textContent = msg || 'OK';
    toastBox.className = 'px-4 py-3 rounded-xl shadow-xl border ' +
      (type === 'error'
        ? 'bg-red-500/15 border-red-500/30 text-red-700 dark:text-red-300'
        : 'bg-green-500/15 border-green-500/30 text-green-700 dark:text-green-300');
    setTimeout(() => toast.classList.add('hidden'), 2600);
  }

  async function apiGet(params) {
    const url = apiUrl + (apiUrl.includes('?') ? '&' : '?') + new URLSearchParams(params).toString();
    const res = await fetch(url, { credentials: 'same-origin' });
    const json = await res.json();
    if (!res.ok || json.status !== 'success') throw new Error(json.message || 'API Fehler');
    return json.data;
  }

  async function apiPost(action, body) {
    const url = apiUrl + (apiUrl.includes('?') ? '&' : '?') + new URLSearchParams({ action }).toString();
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    });
    const json = await res.json();
    if (!res.ok || json.status !== 'success') throw new Error(json.message || 'API Fehler');
    return json.data;
  }

  function openCompose() {
    composeModal.classList.remove('hidden');
    composeModal.classList.add('flex');
    composeTo.focus();
  }

  function closeCompose() {
    composeModal.classList.add('hidden');
    composeModal.classList.remove('flex');
  }

  function renderInbox() {
    const q = (searchInput.value || '').toLowerCase().trim();
    const filtered = q
      ? items.filter(m =>
          (m.subject || '').toLowerCase().includes(q) ||
          (m.from || '').toLowerCase().includes(q)
        )
      : items;

    if (!filtered.length) {
      inboxList.innerHTML = `<div class="p-4 text-sm text-gray-600 dark:text-gray-400">Keine Mails.</div>`;
      return;
    }

    inboxList.innerHTML = filtered.map(m => {
      const active = selectedUid === m.uid;
      return `
        <button type="button"
          class="w-full text-left px-3 py-2 rounded-xl hover:bg-purple-500/10 transition-all border border-transparent
                 ${active ? 'bg-purple-600/20 border-purple-500/25' : ''}"
          data-uid="${m.uid}">
          <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
              <div class="text-sm font-medium text-gray-900 dark:text-white truncate">
                ${m.seen ? '' : '<span class="inline-block w-2 h-2 rounded-full bg-purple-500 mr-2"></span>'}
                ${escapeHtml(m.subject || '(ohne Betreff)')}
              </div>
              <div class="text-xs text-gray-600 dark:text-gray-400 truncate">${escapeHtml(m.from || '')}</div>
            </div>
            <div class="text-[11px] text-gray-500 dark:text-gray-500 whitespace-nowrap">${escapeHtml(m.date || '')}</div>
          </div>
        </button>
      `;
    }).join('');

    inboxList.querySelectorAll('button[data-uid]').forEach(btn => {
      btn.addEventListener('click', () => {
        const uid = Number(btn.getAttribute('data-uid') || 0);
        if (uid > 0) openMessage(uid);
      });
    });
  }

  function renderMessageEmpty() {
    messageHeader.innerHTML = `<div class="text-sm text-gray-600 dark:text-gray-400">Wähle links eine Mail aus.</div>`;
    messageBodyWrap.innerHTML = `<div class="text-sm text-gray-600 dark:text-gray-400">—</div>`;
  }

  function renderMessage(msg) {
    messageHeader.innerHTML = `
      <div>
        <div class="text-lg font-semibold text-gray-900 dark:text-white">${escapeHtml(msg.subject || '(ohne Betreff)')}</div>
        <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">
          <span class="font-medium">Von:</span> ${escapeHtml(msg.from || '')}
          <span class="mx-2">•</span>
          ${escapeHtml(msg.date || '')}
          ${msg.to ? `<span class="mx-2">•</span><span><span class="font-medium">An:</span> ${escapeHtml(msg.to)}</span>` : ''}
        </div>
      </div>
    `;

    const html = msg.body && msg.body.html ? msg.body.html : '';
    const text = msg.body && msg.body.text ? msg.body.text : '';

    if (html) {
      messageBodyWrap.innerHTML = `
        <div class="text-sm bg-white/10 dark:bg-gray-900/30 border border-purple-500/15 rounded-2xl p-4 overflow-auto max-h-[62vh]">
          ${html}
        </div>
      `;
    } else {
      messageBodyWrap.innerHTML = `
        <pre class="whitespace-pre-wrap text-sm text-gray-900 dark:text-gray-100 bg-white/10 dark:bg-gray-900/30 border border-purple-500/15 rounded-2xl p-4 overflow-auto max-h-[62vh]">${escapeHtml(text)}</pre>
      `;
    }
  }

  async function healthCheck(show = true) {
    try {
      const data = await apiGet({ action: 'health' });
      const imap = data?.imap;
      const smtp = data?.smtp;

      const parts = [];
      parts.push(`IMAP: ${imap?.configured ? 'konfig' : 'nicht konfig'} / ext-imap ${imap?.ext_imap ? 'OK' : 'FEHLT'}`);
      parts.push(`SMTP: ${smtp?.configured ? 'konfig' : 'nicht konfig'} / PHPMailer ${smtp?.phpmailer ? 'OK' : 'FEHLT'}`);

      if (show) showToast('success', parts.join(' | '));
    } catch (e) {
      if (show) showToast('error', e.message || 'HealthCheck fehlgeschlagen');
    }
  }

  async function loadFolders() {
    try {
      const list = await apiGet({ action: 'folders' });
      const folders = Array.isArray(list) ? list : [];
      if (!folders.length) return;

      folderSelect.innerHTML = folders.map(f => `<option value="${escapeHtml(f)}">${escapeHtml(f)}</option>`).join('');
      if (!folders.includes(folderSelect.value)) folderSelect.value = folders[0];
    } catch (e) {
      // silent
    }
  }

  async function loadInbox() {
    if (loading) return;
    loading = true;

    selectedUid = null;
    renderMessageEmpty();
    inboxList.innerHTML = `<div class="p-4 text-sm text-gray-600 dark:text-gray-400">Lade…</div>`;

    try {
      const folder = folderSelect.value || 'INBOX';
      const data = await apiGet({ action: 'list', folder, pageSize: '40' });
      items = data.items || [];
      metaText.textContent = `${data.total ?? items.length ?? 0} Mails`;
      renderInbox();
      showToast('success', 'Inbox geladen');
    } catch (e) {
      inboxList.innerHTML = `<div class="p-4 text-sm text-red-700 dark:text-red-300">Fehler: ${escapeHtml(e.message)}</div>`;
      showToast('error', e.message || 'Fehler beim Laden');
    } finally {
      loading = false;
    }
  }

  async function openMessage(uid) {
    selectedUid = uid;
    renderInbox();

    messageBodyWrap.innerHTML = `<div class="text-sm text-gray-600 dark:text-gray-400">Mail wird geladen…</div>`;

    try {
      const folder = folderSelect.value || 'INBOX';
      const msg = await apiGet({ action: 'read', uid: String(uid), folder });
      // mark seen locally
      const idx = items.findIndex(x => Number(x.uid) === Number(uid));
      if (idx >= 0) items[idx].seen = true;

      renderInbox();
      renderMessage(msg);
    } catch (e) {
      showToast('error', e.message || 'Fehler beim Öffnen');
      renderMessageEmpty();
    }
  }

  async function sendEmail() {
    const to = (composeTo.value || '').trim();
    const subject = (composeSubject.value || '').trim();
    const body = (composeBody.value || '').trim();

    if (!to || !subject || !body) {
      showToast('error', 'Bitte An/Betreff/Text ausfüllen.');
      return;
    }

    btnSend.disabled = true;
    btnSend.textContent = 'Sende…';

    try {
      await apiPost('send', { to, subject, body_text: body, _csrf_token: csrf });
      showToast('success', 'Email gesendet');
      composeTo.value = '';
      composeSubject.value = '';
      composeBody.value = '';
      closeCompose();
    } catch (e) {
      showToast('error', e.message || 'Senden fehlgeschlagen');
    } finally {
      btnSend.disabled = false;
      btnSend.textContent = 'Senden';
    }
  }

  // events
  btnCompose.addEventListener('click', openCompose);
  btnLoadInbox.addEventListener('click', loadInbox);
  btnHealth.addEventListener('click', () => healthCheck(true));

  composeBackdrop.addEventListener('click', closeCompose);
  btnCloseCompose.addEventListener('click', closeCompose);
  btnCancelCompose.addEventListener('click', closeCompose);
  btnSend.addEventListener('click', sendEmail);

  folderSelect.addEventListener('change', () => loadInbox());
  searchInput.addEventListener('input', () => renderInbox());

  // boot
  (async () => {
    metaText.textContent = '—';
    renderMessageEmpty();
    await healthCheck(false);
    await loadFolders();
  })();
})();