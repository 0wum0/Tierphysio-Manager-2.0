(() => {
  const app = document.getElementById('settingsApp');
  if (!app) return;

  const apiUrl = app.dataset.apiUrl || '/api/settings.php';
  const csrf = app.dataset.csrf || '';

  const elCats = document.getElementById('categoriesList');
  const elCatSearch = document.getElementById('categorySearch');
  const elSettings = document.getElementById('settingsList');
  const elSettingsSearch = document.getElementById('settingsSearch');
  const elCurrent = document.getElementById('currentCategoryLabel');
  const elSaveHint = document.getElementById('saveHint');

  const btnSave = document.getElementById('btnSave');
  const btnAdd = document.getElementById('btnAdd');
  const btnToggleSystem = document.getElementById('btnToggleSystem');

  const toast = document.getElementById('toast');
  const toastBox = document.getElementById('toastBox');
  const toastMsg = document.getElementById('toastMsg');

  let showSystem = 0;
  let categories = [];
  let currentCategory = null;
  let settings = [];

  function showToast(type, msg) {
    toast.classList.remove('hidden');
    toastMsg.textContent = msg || 'OK';
    toastBox.className = 'px-4 py-3 rounded-xl shadow-xl border ' +
      (type === 'error'
        ? 'bg-red-500/15 border-red-500/30 text-red-700 dark:text-red-300'
        : 'bg-green-500/15 border-green-500/30 text-green-700 dark:text-green-300');
    setTimeout(() => toast.classList.add('hidden'), 2400);
  }

  async function apiGet(params) {
    const url = apiUrl + (apiUrl.includes('?') ? '&' : '?') + new URLSearchParams(params).toString();
    const res = await fetch(url, { credentials: 'same-origin' });
    const json = await res.json();
    if (!res.ok || json.status !== 'success') throw new Error(json.message || 'API Fehler');
    return json.data;
  }

  async function apiPost(action, body) {
    const url = apiUrl + (apiUrl.includes('?') ? '&' : '?') + new URLSearchParams({ action, show_system: String(showSystem) }).toString();
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

  function renderCategories() {
    const q = (elCatSearch.value || '').toLowerCase().trim();
    const list = q ? categories.filter(c => (c.category || '').toLowerCase().includes(q)) : categories;

    if (!list.length) {
      elCats.innerHTML = `<div class="p-4 text-sm text-gray-600 dark:text-gray-400">Keine Kategorien.</div>`;
      return;
    }

    elCats.innerHTML = list.map(c => {
      const name = c.category || 'general';
      const count = c.count || 0;
      const active = currentCategory === name;
      return `
        <button type="button"
          data-cat="${escapeHtml(name)}"
          class="w-full text-left flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-150
            ${active ? 'bg-purple-600/25 border border-purple-500/30' : 'hover:bg-purple-500/10'}">
          <div class="min-w-0">
            <div class="text-sm font-medium text-gray-900 dark:text-white truncate">${escapeHtml(name)}</div>
            <div class="text-xs text-gray-600 dark:text-gray-400">${count} Einträge</div>
          </div>
          <div class="text-xs px-2 py-1 rounded-lg bg-white/10 dark:bg-gray-900/30 border border-purple-500/20 text-gray-700 dark:text-gray-200">→</div>
        </button>
      `;
    }).join('');

    elCats.querySelectorAll('button[data-cat]').forEach(btn => {
      btn.addEventListener('click', () => {
        loadSettings(btn.getAttribute('data-cat'));
      });
    });
  }

  function renderSettings() {
    elCurrent.textContent = currentCategory || '—';
    elSaveHint.textContent = currentCategory ? `Speichert alle Werte in: ${currentCategory}` : '—';

    if (!settings.length) {
      elSettings.innerHTML = `<div class="p-4 text-sm text-gray-600 dark:text-gray-400">Keine Settings in dieser Kategorie.</div>`;
      return;
    }

    const q = (elSettingsSearch.value || '').toLowerCase().trim();

    const filtered = q
      ? settings.filter(s =>
          (s.key || '').toLowerCase().includes(q) ||
          (s.description || '').toLowerCase().includes(q)
        )
      : settings;

    elSettings.innerHTML = filtered.map(s => {
      const key = s.key || '';
      const val = s.value ?? '';
      const type = (s.type || 'string');
      const desc = (s.description || '');
      const isSystem = Number(s.is_system || 0) === 1;

      return `
      <div class="p-4 rounded-2xl border border-purple-500/15 bg-white/20 dark:bg-gray-900/25" data-key="${escapeHtml(key)}">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <div class="text-sm font-semibold text-gray-900 dark:text-white break-all">${escapeHtml(key)}</div>
              ${isSystem ? `<span class="text-[11px] px-2 py-1 rounded-full bg-red-500/15 border border-red-500/25 text-red-700 dark:text-red-300">System</span>` : ''}
              <span class="text-[11px] px-2 py-1 rounded-full bg-purple-500/10 border border-purple-500/20 text-gray-700 dark:text-gray-200">${escapeHtml(type)}</span>
            </div>
            <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">${desc ? escapeHtml(desc) : '(keine Beschreibung)'}</div>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            ${!isSystem ? `<button type="button" class="btnDelete text-xs px-3 py-2 rounded-lg bg-red-500/10 border border-red-500/20 text-red-700 dark:text-red-300 hover:bg-red-500/15">Löschen</button>` : ''}
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 lg:grid-cols-12 gap-3 items-start">
          <div class="lg:col-span-9">
            ${renderInput(type, key, val, isSystem)}
          </div>

          <div class="lg:col-span-3">
            <div class="rounded-xl bg-white/10 dark:bg-gray-800/30 border border-purple-500/15 p-3">
              <div class="text-xs text-gray-700 dark:text-gray-300 font-medium">Meta</div>

              <label class="block text-xs text-gray-600 dark:text-gray-400 mt-2 mb-1">Typ</label>
              <input class="metaType w-full px-3 py-2 bg-white/60 dark:bg-gray-800/60 border border-purple-300/30 rounded-lg"
                     value="${escapeHtml(type)}" ${isSystem && showSystem !== 1 ? 'disabled' : ''}>

              <label class="block text-xs text-gray-600 dark:text-gray-400 mt-2 mb-1">Beschreibung</label>
              <textarea class="metaDesc w-full px-3 py-2 bg-white/60 dark:bg-gray-800/60 border border-purple-300/30 rounded-lg"
                        rows="3" ${isSystem && showSystem !== 1 ? 'disabled' : ''}>${escapeHtml(desc)}</textarea>

              ${isSystem && showSystem !== 1 ? `<div class="text-[11px] text-red-700 dark:text-red-300 mt-2">System gesperrt (System anzeigen aktivieren)</div>` : ''}
            </div>
          </div>
        </div>
      </div>
      `;
    }).join('');

    // Delete handlers
    elSettings.querySelectorAll('.btnDelete').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        const wrap = e.target.closest('[data-key]');
        const key = wrap.getAttribute('data-key');
        if (!confirm(`Einstellung wirklich löschen?\n\n${currentCategory}.${key}`)) return;
        try {
          await apiPost('delete', { category: currentCategory, key });
          showToast('success', 'Gelöscht');
          await loadCategories();
          await loadSettings(currentCategory);
        } catch (err) {
          showToast('error', err.message);
        }
      });
    });
  }

  function renderInput(type, key, val, isSystem) {
    const t = (type || 'string').toLowerCase();
    const disabled = (isSystem && showSystem !== 1) ? 'disabled' : '';

    if (t === 'bool') {
      const checked = (String(val) === '1' || String(val).toLowerCase() === 'true') ? 'checked' : '';
      return `
        <label class="flex items-center gap-3 p-3 rounded-xl bg-white/20 dark:bg-gray-800/30 border border-purple-500/15">
          <input class="valInput" type="checkbox" data-type="bool" ${checked} ${disabled}>
          <span class="text-sm text-gray-800 dark:text-gray-200">Aktiv</span>
        </label>
      `;
    }

    if (t === 'text' || t === 'json') {
      return `
        <textarea class="valInput w-full px-3 py-2 bg-white/60 dark:bg-gray-800/60 border border-purple-300/30 rounded-lg"
                  data-type="${escapeHtml(t)}" rows="${t === 'json' ? 6 : 4}" ${disabled}>${escapeHtml(String(val))}</textarea>
      `;
    }

    if (t === 'int') {
      return `
        <input class="valInput w-full px-3 py-2 bg-white/60 dark:bg-gray-800/60 border border-purple-300/30 rounded-lg"
               data-type="int" type="number" step="1" value="${escapeHtml(String(val))}" ${disabled}>
      `;
    }

    if (t === 'pass') {
      return `
        <input class="valInput w-full px-3 py-2 bg-white/60 dark:bg-gray-800/60 border border-purple-300/30 rounded-lg"
               data-type="pass" type="password" autocomplete="new-password" value="${escapeHtml(String(val))}" ${disabled}>
      `;
    }

    return `
      <input class="valInput w-full px-3 py-2 bg-white/60 dark:bg-gray-800/60 border border-purple-300/30 rounded-lg"
             data-type="string" type="text" value="${escapeHtml(String(val))}" ${disabled}>
    `;
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  async function loadCategories() {
    const data = await apiGet({ action: 'categories', show_system: String(showSystem) });
    categories = data.categories || [];
    // set default category
    if (!currentCategory && categories.length) currentCategory = categories[0].category || 'general';
    renderCategories();
  }

  async function loadSettings(category) {
    currentCategory = category || currentCategory || 'general';
    renderCategories();
    elCurrent.textContent = currentCategory;
    elSettings.innerHTML = `<div class="p-4 text-sm text-gray-600 dark:text-gray-400">Lade Settings…</div>`;

    const data = await apiGet({ action: 'list', category: currentCategory, show_system: String(showSystem) });
    settings = (data.settings || []).map(s => ({
      key: s.key ?? s['key'],
      value: s.value,
      type: s.type || 'string',
      description: s.description || '',
      is_system: s.is_system || 0
    }));

    renderSettings();
  }

  async function saveAll() {
    if (!currentCategory) return;

    const blocks = Array.from(elSettings.querySelectorAll('[data-key]'));
    const payload = blocks.map(b => {
      const key = b.getAttribute('data-key');

      const input = b.querySelector('.valInput');
      const metaType = b.querySelector('.metaType');
      const metaDesc = b.querySelector('.metaDesc');

      let value = '';
      let type = 'string';

      if (input) {
        const dt = input.getAttribute('data-type') || 'string';
        type = dt;

        if (dt === 'bool') value = input.checked ? '1' : '0';
        else value = input.value ?? '';
      }

      return {
        key,
        value,
        type: metaType ? metaType.value : type,
        description: metaDesc ? metaDesc.value : ''
      };
    });

    await apiPost('save', { _csrf_token: csrf, category: currentCategory, settings: payload });
  }

  function openAddPrompt() {
    const category = prompt('Kategorie:', currentCategory || 'general');
    if (!category) return;

    const key = prompt('Key (z.B. smtp_host):', '');
    if (!key) return;

    const type = prompt('Typ (string/int/bool/text/json/email/url/pass):', 'string') || 'string';
    const description = prompt('Beschreibung:', '') || '';
    const value = prompt('Wert:', '') || '';

    return { category, key, type, description, value };
  }

  async function addSetting() {
    const n = openAddPrompt();
    if (!n) return;
    await apiPost('add', n);
    showToast('success', 'Gespeichert');
    currentCategory = n.category;
    await loadCategories();
    await loadSettings(currentCategory);
  }

  // events
  elCatSearch.addEventListener('input', renderCategories);
  elSettingsSearch.addEventListener('input', renderSettings);

  btnToggleSystem.addEventListener('click', async () => {
    showSystem = showSystem === 1 ? 0 : 1;
    btnToggleSystem.textContent = 'System anzeigen: ' + (showSystem === 1 ? 'AN' : 'AUS');
    currentCategory = null;
    settings = [];
    elSettings.innerHTML = `<div class="p-4 text-sm text-gray-600 dark:text-gray-400">Lade…</div>`;
    await loadCategories();
    await loadSettings(currentCategory || (categories[0]?.category || 'general'));
  });

  btnSave.addEventListener('click', async () => {
    try {
      await saveAll();
      showToast('success', 'Gespeichert');
      await loadCategories();
      await loadSettings(currentCategory);
    } catch (err) {
      showToast('error', err.message);
    }
  });

  btnAdd.addEventListener('click', async () => {
    try {
      await addSetting();
    } catch (err) {
      showToast('error', err.message);
    }
  });

  // boot
  (async () => {
    try {
      btnToggleSystem.textContent = 'System anzeigen: AUS';
      await loadCategories();
      await loadSettings(currentCategory || (categories[0]?.category || 'general'));
    } catch (err) {
      elCats.innerHTML = `<div class="p-4 text-sm text-red-700 dark:text-red-300">Fehler: ${escapeHtml(err.message)}</div>`;
      showToast('error', err.message);
    }
  })();
})();