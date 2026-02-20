/* public/js/patients.js
 * Tierphysio Manager – Patienten: Liste + Akte/Detail-Modal + Timeline (Befunde/Notizen/Behandlungen/Dokumente)
 *
 * Enthält:
 * - Patientenliste (Reload + Suche + Filter + Öffnen/Bearbeiten/Löschen)
 * - Patienten-Create/Edit (robuste Save-Actions, Profilbild Upload/Remove integriert)
 * - Patientenakte-Modal (Health-Status, Timeline + Inline-Editor, Attachments pro Beitrag)
 * - Markdown Rendering (marked + DOMPurify) + EasyMDE Guards (kein Doppel-Editor)
 *
 * WICHTIG:
 * - Diese Datei war bei dir doppelt/fragmentiert (Teil 1/2/3 gemischt). Ich habe sie konsolidiert:
 *   keine doppelten Funktionsnamen mehr, state ist definiert, API-Calls sind robust.
 * - Keine Features wurden entfernt: alles aus deinen Teilen ist enthalten (Modal, Timeline, Uploads, Markdown, EasyMDE, List UI).
 */
(() => {
  "use strict";

  // -----------------------------
  // Config
  // -----------------------------
  const API = {
    patients: "/api/patients.php",
  };

  const CDN = {
    marked: "https://unpkg.com/marked/marked.min.js",
    dompurify: "https://unpkg.com/dompurify@3.1.7/dist/purify.min.js",
    easymde_css: "https://unpkg.com/easymde/dist/easymde.min.css",
    easymde_js: "https://unpkg.com/easymde/dist/easymde.min.js",
  };

  // -----------------------------
  // Global state
  // -----------------------------
  const state = {
    activePatientId: 0,
    activePatient: null,
    timeline: [],
    activeEntryEdit: null,
    newEntryEditor: null,
    editEntryEditor: null,
    lastTimelineItems: [],
    bootstrapModalInstance: null,
  };

  // Backward compat (ältere Teile)
  let currentPatientId = null;

  // -----------------------------
  // Patient profile image (Create/Edit modal)
  // -----------------------------
  const PROFILE_IMG = {
    pendingFile: null,
    previewUrl: null,
    existingUrl: null,
    removeRequested: false,
    maxBytes: 12 * 1024 * 1024,
  };

  // -----------------------------
  // Small helpers
  // -----------------------------
  function qs(sel, root = document) {
    return root.querySelector(sel);
  }
  function qsa(sel, root = document) {
    return Array.from(root.querySelectorAll(sel));
  }

  function safeText(input) {
    return String(input ?? "").trim();
  }

  function toInt(v) {
    const n = parseInt(String(v ?? "0"), 10);
    return Number.isFinite(n) ? n : 0;
  }

  function escapeHtml(input) {
    const s = String(input ?? "");
    return s
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function escapeAttr(input) {
    return escapeHtml(input).replaceAll("`", "&#096;");
  }

  function normalizeUrl(u) {
    const s = String(u || "").trim();
    if (!s) return "";
    if (s.startsWith("http://") || s.startsWith("https://")) return s;
    return ("/" + s).replace(/^\/+/, "/");
  }

  function cssEscape(s) {
    s = String(s ?? "");
    if (window.CSS && typeof window.CSS.escape === "function") return window.CSS.escape(s);
    return s.replace(/[^a-zA-Z0-9\-_]/g, "\\$&");
  }

  function debounce(fn, ms) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  function formatBytes(b) {
    const n = Number(b);
    if (!Number.isFinite(n) || n <= 0) return "";
    const units = ["B", "KB", "MB", "GB"];
    let idx = 0;
    let v = n;
    while (v >= 1024 && idx < units.length - 1) {
      v = v / 1024;
      idx++;
    }
    return `${v.toFixed(idx === 0 ? 0 : 1)} ${units[idx]}`;
  }

  function formatDateTime(dtStr) {
    if (!dtStr) return "—";
    const raw = String(dtStr).trim();
    let d;

    if (raw.includes("T")) d = new Date(raw);
    else if (raw.includes(" ")) d = new Date(raw.replace(" ", "T"));
    else d = new Date(raw);

    if (Number.isNaN(d.getTime())) return raw;

    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  function formatRelativeTime(dtStr) {
    if (!dtStr) return "—";
    const raw = String(dtStr).trim();
    let d;

    if (raw.includes("T")) d = new Date(raw);
    else if (raw.includes(" ")) d = new Date(raw.replace(" ", "T"));
    else d = new Date(raw);

    if (Number.isNaN(d.getTime())) return raw;

    const now = Date.now();
    const diffMs = now - d.getTime();
    const diffMin = Math.floor(diffMs / 60000);

    if (diffMin < 1) return "gerade eben";
    if (diffMin < 60) return `vor ${diffMin} Min.`;

    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `vor ${diffH} Stunden`;

    const diffD = Math.floor(diffH / 24);
    if (diffD === 1) return "gestern";
    if (diffD < 7) return `vor ${diffD} Tagen`;

    return formatDateTime(dtStr);
  }

  function toDatetimeLocalValue(dtStr) {
    if (!dtStr) return "";
    const raw = String(dtStr).trim();
    let d;

    if (raw.includes("T")) d = new Date(raw);
    else if (raw.includes(" ")) d = new Date(raw.replace(" ", "T"));
    else d = new Date(raw);

    if (Number.isNaN(d.getTime())) return "";

    const pad = (n) => String(n).padStart(2, "0");
    const yyyy = d.getFullYear();
    const mm = pad(d.getMonth() + 1);
    const dd = pad(d.getDate());
    const hh = pad(d.getHours());
    const mi = pad(d.getMinutes());
    return `${yyyy}-${mm}-${dd}T${hh}:${mi}`;
  }

  function normalizeDateTimeLocal(v) {
    v = String(v || "").trim();
    if (!v) return null;
    const s = v.replace("T", " ");
    return s.length === 16 ? `${s}:00` : s;
  }

  // -----------------------------
  // Toast
  // -----------------------------
  function toast(message, type = "info") {
    const msg = safeText(message) || "Hinweis";
    const t = String(type || "info");

    if (typeof window.showToast === "function") {
      window.showToast(msg, t);
      return;
    }

    const containerId = "tp-toast-container";
    let c = document.getElementById(containerId);
    if (!c) {
      c = document.createElement("div");
      c.id = containerId;
      c.style.position = "fixed";
      c.style.right = "16px";
      c.style.bottom = "16px";
      c.style.zIndex = "99999";
      c.style.display = "flex";
      c.style.flexDirection = "column";
      c.style.gap = "10px";
      document.body.appendChild(c);
    }

    const el = document.createElement("div");
    el.style.minWidth = "260px";
    el.style.maxWidth = "360px";
    el.style.padding = "10px 12px";
    el.style.borderRadius = "12px";
    el.style.backdropFilter = "blur(10px)";
    el.style.background = "rgba(15, 18, 25, 0.85)";
    el.style.color = "#fff";
    el.style.boxShadow = "0 8px 22px rgba(0,0,0,0.35)";
    el.style.border = "1px solid rgba(255,255,255,0.10)";
    el.style.fontSize = "14px";
    el.style.lineHeight = "1.35";

    const badge = document.createElement("div");
    badge.style.fontSize = "12px";
    badge.style.opacity = "0.85";
    badge.style.marginBottom = "4px";
    badge.textContent =
      t === "success" ? "✓ Erfolg" :
      t === "error" ? "⚠ Fehler" :
      t === "warning" || t === "warn" ? "⚠ Hinweis" :
      "ℹ Info";

    const text = document.createElement("div");
    text.textContent = msg;

    el.appendChild(badge);
    el.appendChild(text);
    c.appendChild(el);

    setTimeout(() => {
      el.style.transition = "opacity 250ms ease, transform 250ms ease";
      el.style.opacity = "0";
      el.style.transform = "translateY(6px)";
      setTimeout(() => el.remove(), 280);
    }, 3200);
  }

  // -----------------------------
  // Fetch wrapper (robust JSON)
  // -----------------------------
  async function apiFetch(url, options = {}) {
    const opts = { ...options };
    opts.headers = opts.headers || {};
    const isFormData = opts.body instanceof FormData;

    if (!isFormData) {
      if (!opts.headers["Accept"]) opts.headers["Accept"] = "application/json";
      if (!opts.headers["Content-Type"] && !opts.headers["content-type"]) {
        if (typeof opts.body === "string") opts.headers["Content-Type"] = "application/json";
      }
    }

    let res;
    try {
      res = await fetch(url, opts);
    } catch (_) {
      throw new Error("Netzwerkfehler – keine Verbindung zum Server.");
    }

    const text = await res.text();
    let json = null;

    try {
      json = text ? JSON.parse(text) : null;
    } catch (_) {
      const hint = text && text.trim().startsWith("<") ? " (evtl. Redirect/Login/HTML)" : "";
      throw new Error(`Kein JSON erhalten${hint}. Response: ${text ? text.slice(0, 220) : ""}`);
    }

    if (!res.ok) {
      const msg = json?.message || json?.error || `HTTP ${res.status}`;
      throw new Error(msg);
    }

    if (json && (json.ok === false || json.status === "error")) {
      const msg = json.message || json.error || "Unbekannter Fehler.";
      throw new Error(msg);
    }

    return json;
  }

  function buildApiUrl(action, params = {}) {
    const base = API.patients || "/api/patients.php";
    const u = new URL(base, window.location.origin);
    if (action) u.searchParams.set("action", String(action));
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v === undefined || v === null || v === "") return;
      u.searchParams.set(String(k), String(v));
    });
    return u.toString();
  }

  async function apiGet(action, params = {}) {
    const url = buildApiUrl(action, params);
    return apiFetch(url, { method: "GET" });
  }

  async function apiPost(action, payload = {}) {
    const url = buildApiUrl(action);
    return apiFetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify(payload || {}),
    });
  }

  async function apiUpload(action, params = {}, formData) {
    const url = buildApiUrl(action, params);
    return apiFetch(url, {
      method: "POST",
      body: formData,
    });
  }

  function isUnknownActionError(err) {
    const m = String(err?.message || "").toLowerCase();
    return m.includes("unbekannte aktion") || m.includes("unknown action") || m.includes("aktion") && m.includes("unknown");
  }

  // -----------------------------
  // CSS loader
  // -----------------------------
  function ensurePatientsCssLoaded() {
    const id = "tp-patients-css";
    if (document.getElementById(id)) return;

    const link = document.createElement("link");
    link.id = id;
    link.rel = "stylesheet";
    link.href = "/css/patients.css";
    document.head.appendChild(link);
  }

  // -----------------------------
  // Markdown stack (marked + DOMPurify)
  // -----------------------------
  async function ensureMarkedLoaded() {
    if (window.marked && typeof window.marked.parse === "function") return true;

    const jsId = "tp-marked-js";
    if (!document.getElementById(jsId)) {
      await new Promise((resolve, reject) => {
        const s = document.createElement("script");
        s.id = jsId;
        s.src = CDN.marked;
        s.async = true;
        s.onload = resolve;
        s.onerror = () => reject(new Error("marked konnte nicht geladen werden."));
        document.head.appendChild(s);
      });
    }
    return !!(window.marked && typeof window.marked.parse === "function");
  }

  async function ensureDOMPurifyLoaded() {
    if (window.DOMPurify && typeof window.DOMPurify.sanitize === "function") return true;

    const jsId = "tp-dompurify-js";
    if (!document.getElementById(jsId)) {
      await new Promise((resolve, reject) => {
        const s = document.createElement("script");
        s.id = jsId;
        s.src = CDN.dompurify;
        s.async = true;
        s.onload = resolve;
        s.onerror = () => reject(new Error("DOMPurify konnte nicht geladen werden."));
        document.head.appendChild(s);
      });
    }
    return !!(window.DOMPurify && typeof window.DOMPurify.sanitize === "function");
  }

  async function ensureMarkdownStack() {
    try {
      await Promise.allSettled([ensureMarkedLoaded(), ensureDOMPurifyLoaded()]);
    } catch (_) {}
    return true;
  }

  function renderMarkdownToSafeHtml(md) {
    const raw = String(md || "");
    if (!raw.trim()) return "";

    const hasMarked = !!(window.marked && typeof window.marked.parse === "function");
    const hasPurify = !!(window.DOMPurify && typeof window.DOMPurify.sanitize === "function");

    if (!hasMarked) return escapeHtml(raw).replace(/\n/g, "<br>");

    let html = "";
    try {
      html = window.marked.parse(raw, { breaks: true, gfm: true });
    } catch (_) {
      html = escapeHtml(raw).replace(/\n/g, "<br>");
    }

    if (hasPurify) {
      try {
        html = window.DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
      } catch (_) {}
    }

    return String(html || "");
  }

  // -----------------------------
  // EasyMDE loader + Guard
  // -----------------------------
  async function ensureEasyMDELoaded() {
    if (window.EasyMDE) return true;

    const cssId = "tp-easymde-css";
    if (!document.getElementById(cssId)) {
      const link = document.createElement("link");
      link.id = cssId;
      link.rel = "stylesheet";
      link.href = CDN.easymde_css;
      document.head.appendChild(link);
    }

    const jsId = "tp-easymde-js";
    if (!document.getElementById(jsId)) {
      await new Promise((resolve, reject) => {
        const s = document.createElement("script");
        s.id = jsId;
        s.src = CDN.easymde_js;
        s.async = true;
        s.onload = resolve;
        s.onerror = () => reject(new Error("EasyMDE konnte nicht geladen werden."));
        document.head.appendChild(s);
      });
    }

    return !!window.EasyMDE;
  }

  function initEasyMDEGuarded(textarea, opts = {}) {
    if (!textarea) return null;

    if (textarea.__tpEasyMDE) return textarea.__tpEasyMDE;
    if (textarea.dataset && textarea.dataset.tpMdeInit === "1") return textarea.__tpEasyMDE || null;

    try {
      const parent = textarea.parentElement;
      if (parent && parent.querySelector(".EasyMDEContainer")) {
        textarea.dataset.tpMdeInit = "1";
        return textarea.__tpEasyMDE || null;
      }
      if (textarea.closest && textarea.closest(".EasyMDEContainer")) {
        textarea.dataset.tpMdeInit = "1";
        return textarea.__tpEasyMDE || null;
      }
    } catch (_) {}

    if (!window.EasyMDE) return null;

    const isLarge = window.matchMedia && window.matchMedia("(min-width: 1100px)").matches;

    const mde = new window.EasyMDE({
      element: textarea,
      spellChecker: false,
      status: false,
      autofocus: false,
      autoDownloadFontAwesome: false,
      renderingConfig: { singleLineBreaks: false, codeSyntaxHighlighting: false },
      minHeight: "220px",
      maxHeight: "520px",
      ...opts,
    });

    try {
      if (isLarge && typeof mde.toggleSideBySide === "function") mde.toggleSideBySide();
    } catch (_) {}

    textarea.__tpEasyMDE = mde;
    if (textarea.dataset) textarea.dataset.tpMdeInit = "1";

    return mde;
  }

  // -----------------------------
  // Attachments (Entry files)
  // -----------------------------
  function normalizeAttachment(a) {
    const obj = a && typeof a === "object" ? a : {};
    const id = Number(obj.id || obj.file_id || obj.attachment_id || 0);
    const name = String(obj.name || obj.filename || obj.original_name || obj.file_name || "Datei").trim();
    const mime = String(obj.mime || obj.mime_type || obj.type || "").trim();
    const size = obj.size ?? obj.file_size ?? null;
    const url = normalizeUrl(obj.url || obj.download_url || obj.path || obj.file_path || "");
    return { id, name, mime, size, url };
  }

  function isImageMime(mime, name) {
    const m = String(mime || "").toLowerCase();
    if (m.startsWith("image/")) return true;
    const n = String(name || "").toLowerCase();
    return [".png", ".jpg", ".jpeg", ".gif", ".webp", ".bmp", ".svg"].some((ext) => n.endsWith(ext));
  }

  function attachmentIcon(mime, name) {
    const m = String(mime || "").toLowerCase();
    const n = String(name || "").toLowerCase();
    if (isImageMime(m, n)) return "🖼️";
    if (m.includes("pdf") || n.endsWith(".pdf")) return "📄";
    if (m.includes("word") || n.endsWith(".doc") || n.endsWith(".docx")) return "📝";
    if (m.includes("excel") || n.endsWith(".xls") || n.endsWith(".xlsx")) return "📊";
    if (m.includes("zip") || n.endsWith(".zip") || n.endsWith(".rar") || n.endsWith(".7z")) return "🗜️";
    return "📎";
  }

  function extractAttachmentsFromItem(it) {
    const obj = it && typeof it === "object" ? it : {};
    const a = obj.attachments || obj.files || obj.documents || obj.attachment_list || [];
    return Array.isArray(a) ? a : [];
  }

  function renderAttachmentsInline(attachments) {
    const arr = Array.isArray(attachments) ? attachments.map(normalizeAttachment).filter((x) => x.url) : [];
    if (arr.length === 0) return "";

    const chips = arr
      .map((f) => {
        const icon = attachmentIcon(f.mime, f.name);
        const size = formatBytes(f.size);
        const meta = size ? ` <span class="opacity-70">(${escapeHtml(size)})</span>` : "";
        return `
          <a class="px-2 py-1 text-xs bg-gray-500/10 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-500/20 transition"
             href="${escapeAttr(f.url)}"
             target="_blank"
             rel="noopener">
            ${escapeHtml(icon)} ${escapeHtml(f.name)}${meta}
          </a>
        `;
      })
      .join("");

    return `
      <div class="mt-3 flex flex-wrap gap-2" data-role="attachments">
        ${chips}
      </div>
    `;
  }

  function renderAttachmentsManagerList(attachments) {
    const arr = Array.isArray(attachments) ? attachments.map(normalizeAttachment).filter((x) => x.url) : [];
    if (arr.length === 0) {
      return `<div class="text-xs text-gray-500 dark:text-gray-400">Noch keine Dateien am Beitrag.</div>`;
    }

    return arr
      .map((f) => {
        const icon = attachmentIcon(f.mime, f.name);
        const size = formatBytes(f.size);
        const isImg = isImageMime(f.mime, f.name);

        return `
          <div class="flex items-center gap-2 rounded-xl border border-gray-200 dark:border-white/10 bg-white/50 dark:bg-black/20 px-3 py-2">
            <div class="text-sm">${escapeHtml(icon)}</div>

            <div class="min-w-0 flex-1">
              <div class="text-sm text-gray-800 dark:text-gray-200 truncate">
                <a href="${escapeAttr(f.url)}" target="_blank" rel="noopener" class="hover:underline">${escapeHtml(f.name)}</a>
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400">
                ${size ? escapeHtml(size) : ""} ${f.mime ? (size ? "• " : "") + escapeHtml(f.mime) : ""}
              </div>
            </div>

            ${isImg ? `
              <a href="${escapeAttr(f.url)}" target="_blank" rel="noopener" class="hidden sm:block">
                <img src="${escapeAttr(f.url)}" alt="" class="h-10 w-10 object-cover rounded-lg border border-gray-200 dark:border-white/10">
              </a>
            ` : ""}

            <button type="button"
                    class="text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 px-2 py-1 rounded"
                    data-action="delete-attachment"
                    data-file-id="${escapeAttr(String(f.id))}">
              Löschen
            </button>
          </div>
        `;
      })
      .join("");
  }

  async function getEntryFiles(entityType, entityId) {
    return apiGet("get_entry_files", { entity_type: entityType, entity_id: entityId });
  }

  async function deleteEntryFile(fileId) {
    // Manche Backends erwarten JSON, manche action + JSON – wir probieren robust
    const id = Number(fileId || 0);
    if (!id) throw new Error("Datei-ID fehlt.");

    try {
      return await apiPost("delete_entry_file", { id });
    } catch (e) {
      if (!isUnknownActionError(e)) throw e;
      // fallback: query action=delete_entry_file + JSON (gleiches)
      return apiFetch(`${API.patients}?action=delete_entry_file`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({ id }),
      });
    }
  }

  async function uploadEntryFiles(patientId, entityType, entityId, files) {
    const pid = Number(patientId || 0);
    if (!pid) throw new Error("Upload: Patient-ID fehlt.");

    const list = Array.from(files || []).filter(Boolean);
    if (list.length === 0) return null;

    const fd = new FormData();
    fd.append("patient_id", String(pid));
    fd.append("entity_type", String(entityType));
    fd.append("entity_id", String(entityId));

    if (list.length === 1) fd.append("file", list[0]);
    for (const f of list) fd.append("files[]", f);

    try {
      return await apiUpload("upload_entry_files", {}, fd);
    } catch (e) {
      // fallback: action via querystring (dein erster Teil nutzte ?action=upload_entry_files)
      if (!isUnknownActionError(e)) throw e;
      return apiFetch(`${API.patients}?action=upload_entry_files`, { method: "POST", body: fd });
    }
  }

  // -----------------------------
  // Patient profile image helpers
  // -----------------------------
  function isLikelyImageFile(file) {
    if (!file) return false;
    const t = String(file.type || "").toLowerCase();
    if (t.startsWith("image/")) return true;
    const n = String(file.name || "").toLowerCase();
    return [".png", ".jpg", ".jpeg", ".webp", ".gif", ".bmp", ".svg"].some((ext) => n.endsWith(ext));
  }

  function revokeProfilePreviewUrl() {
    try {
      if (PROFILE_IMG.previewUrl) URL.revokeObjectURL(PROFILE_IMG.previewUrl);
    } catch (_) {}
    PROFILE_IMG.previewUrl = null;
  }

  function clearProfileImageSelection() {
    PROFILE_IMG.pendingFile = null;
    revokeProfilePreviewUrl();
    try {
      const ui = document.querySelector("[data-role='patient-profile-uploader']");
      if (ui) {
        const img = ui.querySelector("[data-role='patient-profile-preview']");
        const ph = ui.querySelector("[data-role='patient-profile-placeholder']");
        const inp = ui.querySelector("input[type='file'][data-role='patient-profile-input']");
        const rm = ui.querySelector("[data-action='patient-profile-remove']");
        if (img) {
          img.src = "";
          img.classList.add("hidden");
        }
        if (ph) ph.classList.remove("hidden");
        if (inp) inp.value = "";
        if (rm) rm.classList.add("hidden");
      }
    } catch (_) {}
  }

  function setProfileImageExistingUrl(url) {
    PROFILE_IMG.existingUrl = normalizeUrl(url || "");
    try {
      const ui = document.querySelector("[data-role='patient-profile-uploader']");
      if (!ui) return;
      const img = ui.querySelector("[data-role='patient-profile-preview']");
      const ph = ui.querySelector("[data-role='patient-profile-placeholder']");
      const rm = ui.querySelector("[data-action='patient-profile-remove']");
      if (!img || !ph) return;

      const finalUrl = PROFILE_IMG.previewUrl || PROFILE_IMG.existingUrl || "";
      if (finalUrl) {
        img.src = finalUrl;
        img.classList.remove("hidden");
        ph.classList.add("hidden");
        if (rm) rm.classList.remove("hidden");
      } else {
        img.src = "";
        img.classList.add("hidden");
        ph.classList.remove("hidden");
        if (rm) rm.classList.add("hidden");
      }
    } catch (_) {}
  }

  async function uploadPatientProfileImage(patientId, file) {
    const pid = Number(patientId || 0);
    if (!pid) throw new Error("Profilbild-Upload: Patient-ID fehlt.");
    if (!file) return null;

    if (!isLikelyImageFile(file)) throw new Error("Bitte eine Bilddatei (JPG/PNG/WebP/GIF) auswählen.");
    if (Number(file.size || 0) > PROFILE_IMG.maxBytes) throw new Error("Bild ist zu groß. Bitte max. 12 MB.");

    const fd = new FormData();
    fd.append("patient_id", String(pid));
    fd.append("file", file);

    // try actions
    try {
      return await apiUpload("upload_patient_image", {}, fd);
    } catch (e) {
      if (!isUnknownActionError(e)) throw e;
      return apiFetch(`${API.patients}?action=upload_patient_image`, { method: "POST", body: fd });
    }
  }

  async function deletePatientProfileImage(patientId) {
    const pid = Number(patientId || 0);
    if (!pid) throw new Error("Profilbild löschen: Patient-ID fehlt.");

    try {
      return await apiPost("delete_patient_image", { patient_id: pid });
    } catch (e) {
      if (!isUnknownActionError(e)) throw e;
      return apiFetch(`${API.patients}?action=delete_patient_image`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({ patient_id: pid }),
      });
    }
  }

  function extractPatientIdFromResponse(json) {
    const j = json && typeof json === "object" ? json : {};
    const direct = Number(j.id || j.patient_id || 0);
    if (direct) return direct;

    const dataId = Number(j?.data?.id || j?.data?.patient_id || 0);
    if (dataId) return dataId;

    const pId = Number(j?.patient?.id || j?.data?.patient?.id || 0);
    if (pId) return pId;

    return 0;
  }

  function ensurePatientProfileUploaderInjected() {
    const form = document.getElementById("patientForm");
    if (!form) return;
    if (form.__tpProfileUploaderInjected) return;
    form.__tpProfileUploaderInjected = true;

    let anchor = null;
    try {
      anchor = form.querySelector("textarea[x-model='currentPatient.notes']") || form.querySelector("textarea") || null;
    } catch (_) {}

    const wrap = document.createElement("div");
    wrap.setAttribute("data-role", "patient-profile-uploader");
    wrap.className = "md:col-span-2";
    wrap.innerHTML = `
      <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Profilbild (Patientenfoto)</label>

      <div class="rounded-2xl border border-purple-500/20 bg-white/50 dark:bg-gray-800/40 p-4 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="relative">
          <div data-role="patient-profile-placeholder"
               class="w-24 h-24 rounded-2xl bg-gray-200/60 dark:bg-gray-700/60 border border-white/20 flex items-center justify-center text-gray-500 dark:text-gray-300">
            <span class="text-2xl">🐾</span>
          </div>

          <img data-role="patient-profile-preview"
               class="hidden w-24 h-24 rounded-2xl object-cover border border-white/20 shadow"
               alt="Profilbild Vorschau">

          <button type="button"
                  data-action="patient-profile-remove"
                  class="hidden absolute -top-2 -right-2 w-7 h-7 rounded-full bg-red-600 text-white shadow flex items-center justify-center hover:bg-red-700"
                  title="Bild entfernen"
                  aria-label="Bild entfernen">
            ×
          </button>
        </div>

        <div class="flex-1 w-full">
          <input type="file"
                 data-role="patient-profile-input"
                 class="block w-full text-sm text-gray-600 dark:text-gray-300
                        file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold
                        file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100
                        dark:file:bg-purple-500/20 dark:file:text-purple-200 dark:hover:file:bg-purple-500/30"
                 accept="image/*">

          <div class="mt-2 text-xs text-gray-600 dark:text-gray-400">
            Tipp: Ideal sind quadratische Bilder (z.B. 800×800). Unterstützt: JPG, PNG, WebP.
          </div>
          <div class="mt-1 text-xs text-gray-500 dark:text-gray-500">
            Hinweis: Das Profilbild wird beim Speichern hochgeladen.
          </div>
        </div>
      </div>
    `;

    try {
      const parentGrid =
        (anchor && anchor.closest(".grid")) ||
        form.querySelector("[x-show=\"activeTab === 'patient'\"] .grid") ||
        null;

      if (parentGrid) {
        if (anchor && anchor.closest("div")) {
          const noteBlock = anchor.closest("div");
          if (noteBlock && noteBlock.parentElement === parentGrid) noteBlock.insertAdjacentElement("afterend", wrap);
          else parentGrid.appendChild(wrap);
        } else {
          parentGrid.appendChild(wrap);
        }
      } else {
        const footer = form.querySelector(".bg-gray-50") || form.lastElementChild;
        if (footer && footer.parentElement) footer.parentElement.insertBefore(wrap, footer);
        else form.appendChild(wrap);
      }
    } catch (_) {
      form.appendChild(wrap);
    }

    const input = wrap.querySelector("input[type='file'][data-role='patient-profile-input']");
    const img = wrap.querySelector("[data-role='patient-profile-preview']");
    const ph = wrap.querySelector("[data-role='patient-profile-placeholder']");
    const rm = wrap.querySelector("[data-action='patient-profile-remove']");

    if (rm) {
      rm.addEventListener("click", (e) => {
        e.preventDefault();
        PROFILE_IMG.removeRequested = true;
        clearProfileImageSelection();
        PROFILE_IMG.existingUrl = "";
        setProfileImageExistingUrl("");
      });
    }

    if (input) {
      input.addEventListener("change", () => {
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) return;

        if (!isLikelyImageFile(file)) {
          toast("Bitte eine Bilddatei auswählen.", "warning");
          input.value = "";
          return;
        }
        if (Number(file.size || 0) > PROFILE_IMG.maxBytes) {
          toast("Bild ist zu groß (max. 12 MB).", "warning");
          input.value = "";
          return;
        }

        PROFILE_IMG.removeRequested = false;

        PROFILE_IMG.pendingFile = file;
        revokeProfilePreviewUrl();
        try {
          PROFILE_IMG.previewUrl = URL.createObjectURL(file);
        } catch (_) {
          PROFILE_IMG.previewUrl = "";
        }

        if (img && PROFILE_IMG.previewUrl) {
          img.src = PROFILE_IMG.previewUrl;
          img.classList.remove("hidden");
        }
        if (ph) ph.classList.add("hidden");
        if (rm) rm.classList.remove("hidden");
      });
    }

    window.__tpResetPatientProfileUploader = function () {
      clearProfileImageSelection();
      PROFILE_IMG.existingUrl = "";
      PROFILE_IMG.removeRequested = false;
      setProfileImageExistingUrl("");
    };

    window.__tpSetPatientProfileImagePreview = function (url) {
      clearProfileImageSelection();
      PROFILE_IMG.existingUrl = normalizeUrl(url || "");
      PROFILE_IMG.removeRequested = false;
      setProfileImageExistingUrl(PROFILE_IMG.existingUrl);
    };

    setProfileImageExistingUrl(PROFILE_IMG.existingUrl || "");
  }

  function watchProfileUploaderInjection() {
    if (watchProfileUploaderInjection.__tpStarted) return;
    watchProfileUploaderInjection.__tpStarted = true;

    const tick = () => {
      try {
        ensurePatientProfileUploaderInjected();
      } catch (_) {}
    };

    setTimeout(tick, 350);
    setTimeout(tick, 900);

    try {
      const obs = new MutationObserver(() => tick());
      obs.observe(document.body, { childList: true, subtree: true });
    } catch (_) {}

    setInterval(tick, 1200);
  }

  // -----------------------------
  // Tags + Preise + Health
  // -----------------------------
  const TAG_PRESETS = [
    { value: "", label: "—", color: "tp-gray" },

    { value: "progress_good", label: "Fortschritt: Gut", color: "tp-green" },
    { value: "followup_planned", label: "Folgetermin geplant", color: "tp-blue" },
    { value: "physiotherapy", label: "Physiotherapie", color: "tp-purple" },
    { value: "arthrosis", label: "Arthrose", color: "tp-orange" },
    { value: "training", label: "Training", color: "tp-blue" },
    { value: "massage", label: "Massage", color: "tp-purple" },
    { value: "laser", label: "Laser", color: "tp-blue" },

    { value: "medication", label: "Medikation", color: "tp-yellow" },
    { value: "warning", label: "Wichtig / Warnung", color: "tp-red" },
    { value: "food_with_meds", label: "Medikament mit Futter", color: "tp-orange" },
    { value: "stop_if_vomit", label: "Stop bei Erbrechen", color: "tp-red" },

    { value: "info", label: "Info", color: "tp-gray" },
    { value: "done", label: "Erledigt", color: "tp-green" },
    { value: "urgent", label: "Dringend", color: "tp-red" },
  ];

  const PRICES = (() => {
    const arr = [{ value: "", label: "—" }];
    for (let p = 5; p <= 200; p += 5) arr.push({ value: p, label: `${p} €` });
    return arr;
  })();

  function tagPretty(tag) {
    const v = String(tag || "").trim();
    if (!v) return "";
    const p = TAG_PRESETS.find((x) => String(x.value) === v);
    return p ? p.label : v;
  }

  function tagColor(tag) {
    const v = String(tag || "").trim();
    if (!v) return "tp-gray";
    const p = TAG_PRESETS.find((x) => String(x.value) === v);
    return p?.color || "tp-gray";
  }

  function tagTone(tag) {
    const c = tagColor(tag);
    if (c === "tp-green") return "green";
    if (c === "tp-blue") return "blue";
    if (c === "tp-orange") return "orange";
    if (c === "tp-purple") return "purple";
    if (c === "tp-yellow") return "yellow";
    if (c === "tp-red") return "red";
    return "gray";
  }

  function renderNoteStyleChip(tagValue) {
    const tv = String(tagValue || "").trim();
    if (!tv) return "";
    const label = tagPretty(tv);
    const tone = tagTone(tv);
    const cls = `px-2 py-1 text-xs bg-${tone}-500/20 text-${tone}-600 dark:text-${tone}-400 rounded`;
    return `<span class="${escapeAttr(cls)}" data-tag="${escapeAttr(tv)}">${escapeHtml(label)}</span>`;
  }

  const HEALTH = [
    { value: "very_good", label: "Sehr gut", cls: "tp-h-very_good" },
    { value: "good", label: "Gut", cls: "tp-h-good" },
    { value: "ok", label: "Okay", cls: "tp-h-ok" },
    { value: "bad", label: "Schlecht", cls: "tp-h-bad" },
    { value: "critical", label: "Kritisch", cls: "tp-h-critical" },
  ];

  function healthLabel(val) {
    const v = String(val || "good");
    const h = HEALTH.find((x) => x.value === v);
    return h ? h.label : "Gut";
  }

  function healthClass(val) {
    const v = String(val || "good");
    const h = HEALTH.find((x) => x.value === v);
    return h ? h.cls : "tp-h-good";
  }

  function buildHealthSelect(selected = "good") {
    const sel = document.createElement("select");
    sel.className = "form-select form-select-sm";
    sel.setAttribute("data-role", "health-status-select");
    for (const h of HEALTH) {
      const opt = document.createElement("option");
      opt.value = h.value;
      opt.textContent = h.label;
      if (String(selected) === String(h.value)) opt.selected = true;
      sel.appendChild(opt);
    }
    return sel;
  }

  // -----------------------------
  // Modal: show/hide + safety
  // -----------------------------
  function getPatientModalElement() {
    return (
      qs("#patientRecordModal") ||
      qs("#patientModal") ||
      qs("[data-role='patient-record-modal']") ||
      qs(".tp-patient-modal") ||
      null
    );
  }

  function showModal(modalEl) {
    if (!modalEl) return;

    // Bootstrap
    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        const instance = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        instance.show();
        state.bootstrapModalInstance = instance;
        return;
      } catch (_) {}
    }

    // Fallback (custom)
    modalEl.classList.add("is-open");
    modalEl.classList.add("show");
    modalEl.style.display = "flex";
    modalEl.removeAttribute("aria-hidden");
    document.documentElement.classList.add("tp-modal-open");
    document.body.classList.add("modal-open");
  }

  function hideModal(modalEl) {
    if (!modalEl) return;

    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        const instance = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        instance.hide();
        return;
      } catch (_) {}
    }

    modalEl.classList.remove("is-open");
    modalEl.classList.remove("show");
    modalEl.style.display = "none";
    modalEl.setAttribute("aria-hidden", "true");
    document.documentElement.classList.remove("tp-modal-open");
    document.body.classList.remove("modal-open");
  }

  function forceCloseAllModalsOnBoot() {
    // verhindert: “Modal wird dauerhaft angezeigt und blockiert alles”
    try {
      document.documentElement.classList.remove("tp-modal-open");
    } catch (_) {}
    try {
      qsa(".tp-modal.is-open").forEach((m) => m.classList.remove("is-open"));
    } catch (_) {}

    // Bootstrap: hide anything accidentally “shown”
    try {
      if (window.bootstrap && window.bootstrap.Modal) {
        qsa(".modal.show").forEach((el) => {
          try {
            const inst = window.bootstrap.Modal.getOrCreateInstance(el);
            inst.hide();
          } catch (_) {}
        });
      }
    } catch (_) {}

    // Fallback: hide patient modal if it’s visible
    const m = getPatientModalElement();
    if (m) {
      const isShown = m.classList.contains("show") || m.classList.contains("is-open") || m.style.display === "block" || m.style.display === "flex";
      if (isShown) {
        try {
          hideModal(m);
        } catch (_) {}
      }
    }
  }

  // -----------------------------
  // Patient Modal structure (toolbar + timeline host)
  // -----------------------------
  function ensureHealthBanner(modalEl) {
    if (!modalEl) return;

    const header = modalEl.querySelector(".modal-header") || modalEl.querySelector("[data-role='patient-modal-header']") || null;
    const host = header || modalEl;

    let banner = modalEl.querySelector("[data-role='patient-health-banner']");
    if (!banner) {
      banner = document.createElement("div");
      banner.setAttribute("data-role", "patient-health-banner");
      banner.className = `tp-health-banner ${healthClass("good")}`;
      banner.innerHTML = `<span class="tp-health-dot"></span><span data-role="health-label">Gut</span>`;

      if (header) {
        banner.style.marginLeft = "auto";
        banner.style.marginRight = "6px";
        banner.style.whiteSpace = "nowrap";
        header.appendChild(banner);
      } else {
        banner.style.position = "absolute";
        banner.style.top = "12px";
        banner.style.right = "12px";
        banner.style.zIndex = "5";
        if (!host.style.position) host.style.position = "relative";
        host.appendChild(banner);
      }
    }
  }

  function setHealthBanner(modalEl, statusValue) {
    const banner = modalEl?.querySelector("[data-role='patient-health-banner']");
    if (!banner) return;

    banner.classList.remove("tp-h-very_good", "tp-h-good", "tp-h-ok", "tp-h-bad", "tp-h-critical");
    banner.classList.add(healthClass(statusValue));

    const label = banner.querySelector("[data-role='health-label']");
    if (label) label.textContent = healthLabel(statusValue);
  }

  function ensurePatientModalStructure() {
    ensurePatientsCssLoaded();

    const modal = getPatientModalElement();
    if (!modal) return { modal: null };

    // Timeline container
    let timeline =
      modal.querySelector("[data-role='timeline']") ||
      modal.querySelector("#patientTimeline") ||
      modal.querySelector(".tp-timeline") ||
      null;

    if (!timeline) {
      timeline = document.createElement("div");
      timeline.className = "tp-timeline";
      timeline.id = "patientTimeline";
      timeline.setAttribute("data-role", "timeline");
      modal.appendChild(timeline);
    }
    if (!timeline.classList.contains("tp-timeline")) timeline.classList.add("tp-timeline");

    // Inline form host
    let inlineHost = modal.querySelector("[data-role='inline-form-host']");
    if (!inlineHost) {
      inlineHost = document.createElement("div");
      inlineHost.setAttribute("data-role", "inline-form-host");
      inlineHost.className = "tp-inline-form-host";
      timeline.parentElement.insertBefore(inlineHost, timeline);
    }

    // Actions / toolbar
    let actions = modal.querySelector("[data-role='patient-actions']");
    if (!actions) {
      actions = document.createElement("div");
      actions.setAttribute("data-role", "patient-actions");
      actions.className = "tp-timeline-toolbar";
      actions.innerHTML = `
        <div class="d-flex gap-2 align-items-center flex-wrap" style="min-width: 240px">
          <button class="btn btn-sm btn-outline-light" type="button" data-action="refresh-timeline">Aktualisieren</button>
          <button class="btn btn-sm btn-primary" type="button" data-action="toggle-new-entry">+ Neuer Eintrag</button>
        </div>

        <div class="d-flex gap-2 align-items-center flex-wrap" style="min-width: 260px; justify-content: flex-end">
          <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">Gesundheit</span>
            <div data-role="health-select-host"></div>
          </div>
          <div class="tp-timeline-search">
            <input class="form-control form-control-sm" type="search" placeholder="In der Akte suchen…" data-role="timeline-search">
          </div>
        </div>

        <div class="w-100 d-none" data-role="new-entry-panel" style="margin-top:8px">
          <div class="tp-inline-form">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
              <div class="fw-bold">Neuer Eintrag</div>
              <button class="btn btn-sm btn-outline-light tp-mini-btn" type="button" data-action="close-new-entry">Schließen</button>
            </div>

            <div class="row g-2 align-items-end">
              <div class="col-12 col-md-4">
                <label class="small text-muted">Typ</label>
                <select class="form-select form-select-sm" data-role="new-entry-type">
                  <option value="treatment">Behandlung</option>
                  <option value="record">Befund</option>
                  <option value="note">Notiz</option>
                </select>
              </div>
              <div class="col-12 col-md-8 d-flex gap-2 justify-content-end">
                <button class="btn btn-sm btn-primary" type="button" data-action="create-entry">Erstellen</button>
              </div>
            </div>

            <div class="tp-divider"></div>
            <div class="small text-muted mb-2">Schnell-Banner (Tags):</div>
            <div data-role="global-tag-palette"></div>
          </div>
        </div>
      `;
      inlineHost.parentElement.insertBefore(actions, inlineHost);
    }

    // Health banner
    ensureHealthBanner(modal);

    // Health select
    const healthHost = actions.querySelector("[data-role='health-select-host']");
    if (healthHost && !healthHost.__tpBound) {
      healthHost.__tpBound = true;
      const sel = buildHealthSelect("good");
      healthHost.appendChild(sel);

      sel.addEventListener("change", async () => {
        const v = String(sel.value || "good");
        try {
          await setPatientHealthStatus(resolvePatientId(modal), v);
          setHealthBanner(modal, v);
          toast("Gesundheitsstatus gespeichert.", "success");
        } catch (e) {
          toast(e?.message || "Konnte Status nicht speichern.", "error");
        }
      });
    }

    // Global tag palette
    const paletteHost = actions.querySelector("[data-role='global-tag-palette']");
    if (paletteHost && !paletteHost.__tpFilled) {
      paletteHost.__tpFilled = true;
      paletteHost.appendChild(buildTagPalette(""));
      paletteHost.addEventListener("click", (ev) => {
        const chip = ev.target.closest(".tp-chip[data-tag]");
        if (!chip) return;
        const val = String(chip.getAttribute("data-tag") || "");
        // set tag in currently open inline editor if exists
        const inline = modal.querySelector("[data-role='entry-editor']");
        if (inline) {
          const tagSel = inline.querySelector("select[data-role='tag-select']");
          const tagInp = inline.querySelector("input[data-role='tag-input']");
          if (tagSel) tagSel.value = val;
          if (tagInp) tagInp.value = val;
        }
      });
    }

    // Search
    const search = actions.querySelector("[data-role='timeline-search']");
    if (search && !search.__tpBound) {
      search.__tpBound = true;
      search.addEventListener("input", debounce(() => filterTimelineDom(timeline, search.value), 120));
    }

    // Toolbar actions
    if (!actions.__tpBound) {
      actions.__tpBound = true;
      actions.addEventListener("click", (ev) => {
        const btn = ev.target.closest("[data-action]");
        if (!btn) return;
        const action = btn.getAttribute("data-action");

        if (action === "refresh-timeline") {
          const pid = resolvePatientId(modal);
          if (pid) loadTimeline(modal, pid);
        }

        if (action === "toggle-new-entry") {
          const panel = actions.querySelector("[data-role='new-entry-panel']");
          if (panel) panel.classList.toggle("d-none");
        }

        if (action === "close-new-entry") {
          const panel = actions.querySelector("[data-role='new-entry-panel']");
          if (panel) panel.classList.add("d-none");
        }

        if (action === "create-entry") {
          const typeSel = actions.querySelector("[data-role='new-entry-type']");
          const type = String(typeSel?.value || "treatment");
          openInlineEntryEditor(modal, { mode: "create", entity_type: type });
        }
      });
    }

    return { modal, timeline, actions, inlineHost };
  }

  function resolvePatientId(modalEl) {
    const pid = Number(state.activePatientId || currentPatientId || modalEl?.getAttribute("data-patient-id") || 0);
    return pid > 0 ? pid : 0;
  }

  // -----------------------------
  // Tag/Price palette builders
  // -----------------------------
  function buildTagSelect(selectedValue = "") {
    const sel = document.createElement("select");
    sel.className = "form-select form-select-sm";
    sel.setAttribute("data-role", "tag-select");

    for (const t of TAG_PRESETS) {
      const opt = document.createElement("option");
      opt.value = String(t.value);
      opt.textContent = String(t.label);
      if (String(selectedValue) === String(t.value)) opt.selected = true;
      sel.appendChild(opt);
    }
    return sel;
  }

  function buildPriceSelect(selectedValue = null) {
    const sel = document.createElement("select");
    sel.className = "form-select form-select-sm";
    sel.setAttribute("data-role", "price-select");

    for (const p of PRICES) {
      const opt = document.createElement("option");
      opt.value = p.value === "" ? "" : String(p.value);
      opt.textContent = String(p.label);
      if (selectedValue !== null && selectedValue !== undefined && String(selectedValue) === String(p.value)) opt.selected = true;
      sel.appendChild(opt);
    }
    return sel;
  }

  function buildTagPalette(selectedValue = "") {
    const wrap = document.createElement("div");
    wrap.className = "tp-tag-palette";
    wrap.setAttribute("data-role", "tag-palette");

    for (const t of TAG_PRESETS) {
      if (t.value === "") continue;
      const chip = document.createElement("div");
      chip.className = `tp-chip ${t.color || "tp-gray"}` + (String(selectedValue) === String(t.value) ? " is-active" : "");
      chip.setAttribute("data-tag", String(t.value));
      chip.innerHTML = `<span class="dot"></span><span>${escapeHtml(t.label)}</span>`;
      wrap.appendChild(chip);
    }
    return wrap;
  }

  // -----------------------------
  // Timeline render + actions
  // -----------------------------
  function timelineIcon(type) {
    const t = String(type || "").toLowerCase();
    if (t === "treatment") return "💆";
    if (t === "record") return "📋";
    if (t === "note") return "📝";
    if (t === "document") return "📎";
    return "•";
  }

  function timelineLabel(type) {
    const t = String(type || "").toLowerCase();
    if (t === "treatment") return "Behandlung";
    if (t === "record") return "Befund";
    if (t === "note") return "Notiz";
    if (t === "document") return "Dokument";
    return "Eintrag";
  }

  function typeBadge(type) {
    const t = String(type || "").toLowerCase();
    if (t === "treatment") return { label: "Behandlung", cls: "px-3 py-1 text-xs font-medium bg-purple-500/20 text-purple-600 dark:text-purple-400 rounded-full" };
    if (t === "record") return { label: "Befund", cls: "px-3 py-1 text-xs font-medium bg-blue-500/20 text-blue-600 dark:text-blue-400 rounded-full" };
    if (t === "note") return { label: "Notiz", cls: "px-3 py-1 text-xs font-medium bg-orange-500/20 text-orange-600 dark:text-orange-400 rounded-full" };
    if (t === "document") return { label: "Dokument", cls: "px-3 py-1 text-xs font-medium bg-gray-500/20 text-gray-700 dark:text-gray-300 rounded-full" };
    return { label: "Eintrag", cls: "px-3 py-1 text-xs font-medium bg-gray-500/20 text-gray-700 dark:text-gray-300 rounded-full" };
  }

  function extractWarning(content) {
    const txt = String(content || "");
    const m = txt.match(/(^|\n)\s*Wichtig\s*:\s*(.+)$/im);
    if (!m) return "";
    return String(m[2] || "").trim();
  }

  function renderTimelineItems(items) {
    const list = Array.isArray(items) ? items : [];
    if (list.length === 0) return `<div class="text-muted small py-3">Noch keine Einträge.</div>`;

    return list
      .map((it) => {
        const type = String(it.entity_type || it.type || "").toLowerCase();
        const id = Number(it.entity_id || it.id || 0);

        const title = safeText(it.title || timelineLabel(type));
        const content = safeText(it.content || it.note || it.text || "");
        const tag = safeText(it.tag || "");

        const priceNum =
          it.price !== undefined && it.price !== null && it.price !== "" && !Number.isNaN(Number(it.price))
            ? Number(it.price)
            : null;
        const price = priceNum !== null ? `${priceNum} €` : "";

        const eventAt = it.event_at || it.date || it.created_at || it.updated_at || "";
        const whenRel = formatRelativeTime(eventAt);

        const updatedAt = it.updated_at ? formatDateTime(it.updated_at) : "";
        const editedLine = updatedAt ? `Bearbeitet am ${updatedAt}` : "";

        const canEdit = type === "treatment" || type === "record" || type === "note";
        const canDelete = type === "treatment" || type === "record" || type === "note";

        const badge = typeBadge(type);
        const warning = extractWarning(content);

        const attachments = extractAttachmentsFromItem(it);
        const attachmentsHtml = renderAttachmentsInline(attachments);

        const contentHtml = content ? renderMarkdownToSafeHtml(content) : "";

        return `
          <div class="tp-timeline-card bg-white/10 dark:bg-gray-800/50 backdrop-blur-md rounded-2xl border border-purple-500/20 p-6 hover:border-purple-400/40 transition-all"
               data-entity-type="${escapeAttr(type)}"
               data-entity-id="${escapeAttr(String(id))}"
               data-tag="${escapeAttr(tag)}"
               data-price="${escapeAttr(priceNum !== null ? String(priceNum) : "")}"
               data-event-at="${escapeAttr(String(eventAt || ""))}">

            <div class="flex items-start justify-between mb-4">
              <div class="tp-timeline-meta">
                <h3 class="tp-timeline-title text-lg font-semibold text-gray-900 dark:text-white">
                  <span class="fw-bold">${escapeHtml(title)}</span>
                </h3>

                <div class="flex items-center space-x-4 mt-2 text-sm text-gray-600 dark:text-gray-400">
                  <span class="flex items-center">
                    <span class="mr-1">${escapeHtml(timelineIcon(type))}</span>
                    ${escapeHtml(whenRel)}
                  </span>

                  ${price ? `
                    <span class="flex items-center">
                      <span class="px-2 py-1 text-xs bg-gray-500/20 text-gray-700 dark:text-gray-300 rounded">${escapeHtml(price)}</span>
                    </span>
                  ` : ""}
                </div>
              </div>

              <span class="${escapeAttr(badge.cls)}">${escapeHtml(badge.label)}</span>
            </div>

            ${content ? `
              <div class="tp-timeline-content text-sm text-gray-700 dark:text-gray-300 mb-4">
                <div data-role="timeline-content-html">${contentHtml}</div>
                <div data-role="raw-content" style="display:none">${escapeHtml(content)}</div>
              </div>
            ` : `
              <div data-role="raw-content" style="display:none"></div>
            `}

            ${(warning || tag === "warning" || tag === "stop_if_vomit") ? `
              <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-3 mb-4">
                <p class="text-xs text-yellow-700 dark:text-yellow-300">
                  <strong>Wichtig:</strong> ${escapeHtml(warning || tagPretty(tag) || "Bitte Hinweis beachten.")}
                </p>
              </div>
            ` : ""}

            ${attachmentsHtml}

            <div class="flex items-center justify-content-between mt-4">
              <div class="flex items-center space-x-2">
                ${tag ? renderNoteStyleChip(tag) : ""}
                ${editedLine ? `<span class="px-2 py-1 text-xs bg-blue-500/10 text-blue-700 dark:text-blue-300 rounded">${escapeHtml(editedLine)}</span>` : ""}
              </div>

              <div class="flex space-x-2">
                ${canEdit ? `
                  <button class="text-purple-600 hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300"
                          type="button"
                          data-action="edit-entry"
                          title="Bearbeiten"
                          aria-label="Bearbeiten">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                    </svg>
                  </button>
                ` : ""}

                ${canDelete ? `
                  <button class="text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400"
                          type="button"
                          data-action="delete-entry"
                          title="Löschen"
                          aria-label="Löschen">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m2 0H7m3-3h4a1 1 0 011 1v1H9V5a1 1 0 011-1z"></path>
                    </svg>
                  </button>
                ` : ""}
              </div>
            </div>
          </div>
        `;
      })
      .join("");
  }

  function filterTimelineDom(timelineEl, query) {
    const q = String(query || "").trim().toLowerCase();
    const cards = timelineEl.querySelectorAll(".tp-timeline-card");
    if (!q) {
      cards.forEach((c) => (c.style.display = ""));
      return;
    }
    cards.forEach((c) => {
      const text = c.textContent ? c.textContent.toLowerCase() : "";
      c.style.display = text.includes(q) ? "" : "none";
    });
  }

  async function loadTimeline(modalEl, patientId) {
    const { timeline } = ensurePatientModalStructure();
    if (!modalEl || !timeline) return;

    timeline.innerHTML = `<div class="text-muted small py-3">Lade…</div>`;

    try {
      ensureMarkdownStack();

      const json = await apiGet("get_timeline", { patient_id: patientId });
      const items = json?.timeline || json?.data?.timeline || json?.data?.items || json?.items || [];
      state.lastTimelineItems = Array.isArray(items) ? items : [];
      timeline.innerHTML = renderTimelineItems(state.lastTimelineItems);

      bindTimelineActions(modalEl);

      const search = modalEl.querySelector("[data-role='timeline-search']");
      if (search) filterTimelineDom(timeline, search.value);
    } catch (e) {
      timeline.innerHTML = `<div class="text-danger small py-3">${escapeHtml(e.message || "Fehler beim Laden.")}</div>`;
    }
  }

  function bindTimelineActions(modalEl) {
    const { timeline } = ensurePatientModalStructure();
    if (!modalEl || !timeline) return;
    if (timeline.__tpBound) return;
    timeline.__tpBound = true;

    timeline.addEventListener("click", async (ev) => {
      const card = ev.target.closest(".tp-timeline-card");
      if (!card) return;

      const type = String(card.getAttribute("data-entity-type") || "");
      const id = Number(card.getAttribute("data-entity-id") || 0);
      if (!type || !id) return;

      const btnEdit = ev.target.closest("[data-action='edit-entry']");
      const btnDel = ev.target.closest("[data-action='delete-entry']");

      if (btnEdit) {
        const item = findTimelineItem(type, id);
        if (!item) return;
        openInlineEntryEditor(modalEl, { mode: "edit", entity_type: type, entity_id: id, item });
      }

      if (btnDel) {
        const ok = confirm(`${timelineLabel(type)} wirklich löschen?`);
        if (!ok) return;
        try {
          await deleteEntry(type, id);
          toast("Eintrag gelöscht.", "success");
          await loadTimeline(modalEl, resolvePatientId(modalEl));
        } catch (e) {
          toast(e?.message || "Löschen fehlgeschlagen.", "error");
        }
      }
    });
  }

  function findTimelineItem(type, id) {
    const t = String(type || "").toLowerCase();
    const n = Number(id || 0);
    const list = Array.isArray(state.lastTimelineItems) ? state.lastTimelineItems : [];
    return list.find((x) => String(x.entity_type || x.type || "").toLowerCase() === t && Number(x.entity_id || x.id || 0) === n) || null;
  }

  async function deleteEntry(type, id) {
    const t = String(type || "").toLowerCase();
    const payload = { id: Number(id || 0) };

    if (t === "treatment") {
      // try common actions
      try { return await apiPost("delete_treatment", payload); } catch (e1) {
        if (!isUnknownActionError(e1)) throw e1;
        try { return await apiPost("deleteTreatment", payload); } catch (e2) { if (!isUnknownActionError(e2)) throw e2; }
        return apiPost("delete_entry", { ...payload, entity_type: t });
      }
    }
    if (t === "record") {
      try { return await apiPost("delete_record", payload); } catch (e1) {
        if (!isUnknownActionError(e1)) throw e1;
        return apiPost("delete_entry", { ...payload, entity_type: t });
      }
    }
    if (t === "note") {
      try { return await apiPost("delete_note", payload); } catch (e1) {
        if (!isUnknownActionError(e1)) throw e1;
        return apiPost("delete_entry", { ...payload, entity_type: t });
      }
    }

    // fallback generic
    return apiPost("delete_entry", { ...payload, entity_type: t });
  }

  // -----------------------------
  // Inline Entry Editor (create/edit) with upload per post
  // -----------------------------
  function openInlineEntryEditor(modalEl, cfg) {
    const { inlineHost } = ensurePatientModalStructure();
    if (!modalEl || !inlineHost) return;

    const pid = resolvePatientId(modalEl);
    if (!pid) {
      toast("Patient-ID fehlt – bitte Modal erneut öffnen.", "warning");
      return;
    }

    const mode = cfg?.mode === "edit" ? "edit" : "create";
    const type = String(cfg?.entity_type || "treatment").toLowerCase();
    const entityId = Number(cfg?.entity_id || 0);

    // cancel previous edit
    teardownEditors();
    inlineHost.innerHTML = "";

    const item = cfg?.item || null;

    const initialTitle = safeText(item?.title || "");
    const initialTag = safeText(item?.tag || "");
    const initialPrice = item?.price !== undefined && item?.price !== null && item?.price !== "" ? String(item.price) : "";
    const initialEventAt = item?.event_at ? toDatetimeLocalValue(item.event_at) : "";
    const initialContent = safeText(item?.content || item?.note || item?.text || "");

    const attachments = extractAttachmentsFromItem(item || {});
    const existingAttachmentsHtml = `
      <div class="mt-3">
        <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">Vorhandene Dateien</div>
        <div data-role="existing-attachments">
          ${renderAttachmentsManagerList(attachments)}
        </div>
      </div>
    `;

    inlineHost.innerHTML = `
      <div class="tp-inline-form" data-role="entry-editor" data-mode="${escapeAttr(mode)}" data-entity-type="${escapeAttr(type)}" data-entity-id="${escapeAttr(String(entityId))}">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
          <div class="fw-bold">${mode === "edit" ? "Eintrag bearbeiten" : "Neuer Eintrag"}</div>
          <button class="btn btn-sm btn-outline-light tp-mini-btn" type="button" data-action="close-inline-editor">Schließen</button>
        </div>

        <div class="row g-2">
          <div class="col-12 col-lg-4">
            <label class="small text-muted">Titel</label>
            <input class="form-control form-control-sm" type="text" data-role="title" value="${escapeAttr(initialTitle)}" placeholder="z.B. Ganganalyse, Palpationsprotokoll…">
          </div>

          <div class="col-12 col-lg-3">
            <label class="small text-muted">Tag</label>
            <div class="d-flex gap-2">
              <select class="form-select form-select-sm" data-role="tag-select"></select>
              <input class="form-control form-control-sm" type="text" data-role="tag-input" value="${escapeAttr(initialTag)}" placeholder="optional">
            </div>
          </div>

          <div class="col-12 col-lg-2 ${type === "treatment" ? "" : "d-none"}" data-role="price-wrap">
            <label class="small text-muted">Preis</label>
            <select class="form-select form-select-sm" data-role="price-select"></select>
          </div>

          <div class="col-12 col-lg-3 ${type === "treatment" ? "" : "d-none"}" data-role="date-wrap">
            <label class="small text-muted">Datum/Zeit</label>
            <input class="form-control form-control-sm" type="datetime-local" data-role="event-at" value="${escapeAttr(initialEventAt)}">
          </div>
        </div>

        <div class="tp-divider"></div>

        <div class="row g-2">
          <div class="col-12 col-lg-8">
            <label class="small text-muted">Inhalt (Markdown)</label>
            <textarea class="form-control" rows="6" data-role="content" placeholder="Markdown…">${escapeHtml(initialContent)}</textarea>

            <div class="mt-3">
              <div class="d-flex align-items-center justify-content-between">
                <div class="small text-muted">Dateien am Beitrag</div>
                <div class="small text-muted">${mode === "edit" ? "Neue Dateien werden zusätzlich hochgeladen" : "Wird nach dem Speichern hochgeladen"}</div>
              </div>
              <input class="form-control form-control-sm mt-2" type="file" multiple data-role="files">
              <div class="mt-2 text-xs text-gray-500 dark:text-gray-400" data-role="files-list">Keine Dateien ausgewählt.</div>
              ${mode === "edit" ? existingAttachmentsHtml : ""}
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <label class="small text-muted">Live-Vorschau</label>
            <div class="tp-preview-box tp-markdown p-3 rounded-2xl border border-white/10 bg-white/5" data-role="preview"></div>

            <div class="tp-divider"></div>
            <div class="small text-muted mb-2">Schnell-Banner (Tags):</div>
            <div data-role="tag-palette-host"></div>
          </div>
        </div>

        <div class="d-flex gap-2 justify-content-end mt-3">
          <button class="btn btn-sm btn-outline-light" type="button" data-action="cancel-inline-editor">Abbrechen</button>
          <button class="btn btn-sm btn-primary" type="button" data-action="save-inline-editor">${mode === "edit" ? "Aktualisieren" : "Speichern"}</button>
        </div>
      </div>
    `;

    // build tag/price/palette
    const tagSelHost = inlineHost.querySelector("select[data-role='tag-select']");
    const priceSelHost = inlineHost.querySelector("select[data-role='price-select']");
    const paletteHost = inlineHost.querySelector("[data-role='tag-palette-host']");

    if (tagSelHost) {
      const sel = buildTagSelect(initialTag);
      tagSelHost.replaceWith(sel);
      sel.setAttribute("data-role", "tag-select");
    }
    if (priceSelHost) {
      const sel = buildPriceSelect(initialPrice !== "" ? Number(initialPrice) : "");
      priceSelHost.replaceWith(sel);
      sel.setAttribute("data-role", "price-select");
    }
    if (paletteHost) paletteHost.appendChild(buildTagPalette(initialTag));

    // sync tag input/select + palette
    const tagSelect = inlineHost.querySelector("select[data-role='tag-select']");
    const tagInput = inlineHost.querySelector("input[data-role='tag-input']");
    const palette = inlineHost.querySelector("[data-role='tag-palette-host'] .tp-tag-palette");

    const setTag = (v) => {
      const val = String(v || "");
      if (tagSelect) tagSelect.value = val;
      if (tagInput) tagInput.value = val;

      if (palette) {
        qsa(".tp-chip", palette).forEach((c) => c.classList.toggle("is-active", String(c.getAttribute("data-tag")) === val));
      }
    };

    if (tagSelect) tagSelect.addEventListener("change", () => setTag(tagSelect.value));
    if (tagInput) tagInput.addEventListener("input", () => setTag(tagInput.value));
    if (palette) {
      palette.addEventListener("click", (ev) => {
        const chip = ev.target.closest(".tp-chip[data-tag]");
        if (!chip) return;
        setTag(chip.getAttribute("data-tag") || "");
      });
    }

    // files list
    const fileInput = inlineHost.querySelector("input[type='file'][data-role='files']");
    const fileList = inlineHost.querySelector("[data-role='files-list']");
    if (fileInput && fileList) {
      fileInput.addEventListener("change", () => {
        const files = Array.from(fileInput.files || []);
        fileList.innerHTML = files.length
          ? files.map((f) => `<div>${escapeHtml(f.name)} <span class="opacity-70">(${escapeHtml(formatBytes(f.size))})</span></div>`).join("")
          : "Keine Dateien ausgewählt.";
      });
    }

    // markdown preview + EasyMDE
    ensureMarkdownStack();
    ensureEasyMDELoaded().then(() => {
      const ta = inlineHost.querySelector("textarea[data-role='content']");
      const editor = initEasyMDEGuarded(ta, { placeholder: ta?.getAttribute("placeholder") || "Text…" });

      if (mode === "create") state.newEntryEditor = editor;
      if (mode === "edit") state.editEntryEditor = editor;

      const previewEl = inlineHost.querySelector("[data-role='preview']");
      const updatePreview = () => {
        const raw = editor ? String(editor.value() || "") : String(ta?.value || "");
        if (previewEl) previewEl.innerHTML = renderMarkdownToSafeHtml(raw);
      };

      if (editor && editor.codemirror) editor.codemirror.on("change", debounce(updatePreview, 150));
      if (!editor && ta) ta.addEventListener("input", debounce(updatePreview, 150));
      updatePreview();
    });

    // existing attachments delete
    inlineHost.addEventListener("click", async (ev) => {
      const btnDel = ev.target.closest("[data-action='delete-attachment']");
      if (!btnDel) return;
      const fid = Number(btnDel.getAttribute("data-file-id") || 0);
      if (!fid) return;
      const ok = confirm("Datei wirklich löschen?");
      if (!ok) return;

      try {
        await deleteEntryFile(fid);
        toast("Datei gelöscht.", "success");
        await loadTimeline(modalEl, pid);

        // reload attachment list in editor (best effort)
        if (mode === "edit") {
          try {
            const filesRes = await getEntryFiles(type, entityId);
            const fresh = filesRes?.data?.files || filesRes?.files || filesRes?.attachments || [];
            const host = inlineHost.querySelector("[data-role='existing-attachments']");
            if (host) host.innerHTML = renderAttachmentsManagerList(fresh);
          } catch (_) {}
        }
      } catch (e) {
        toast(e?.message || "Datei konnte nicht gelöscht werden.", "error");
      }
    });

    // editor buttons
    inlineHost.addEventListener("click", async (ev) => {
      const a = ev.target.closest("[data-action]");
      if (!a) return;

      const action = a.getAttribute("data-action");
      if (action === "close-inline-editor" || action === "cancel-inline-editor") {
        teardownEditors();
        inlineHost.innerHTML = "";
        return;
      }

      if (action === "save-inline-editor") {
        const editorEl = inlineHost.querySelector("[data-role='entry-editor']");
        if (!editorEl) return;

        const title = safeText(inlineHost.querySelector("[data-role='title']")?.value || "");
        const tag = safeText(inlineHost.querySelector("input[data-role='tag-input']")?.value || (inlineHost.querySelector("select[data-role='tag-select']")?.value || ""));
        const priceSel = inlineHost.querySelector("select[data-role='price-select']");
        const priceVal = priceSel ? String(priceSel.value || "") : "";
        const price = priceVal !== "" ? Number(priceVal) : null;

        const eventAtVal = safeText(inlineHost.querySelector("[data-role='event-at']")?.value || "");
        const event_at = eventAtVal ? normalizeDateTimeLocal(eventAtVal) : null;

        const ta = inlineHost.querySelector("textarea[data-role='content']");
        const ed = mode === "edit" ? state.editEntryEditor : state.newEntryEditor;
        const content = ed && typeof ed.value === "function" ? safeText(ed.value()) : safeText(ta?.value || "");

        if (!title && !content) {
          toast("Bitte mindestens Titel oder Inhalt eingeben.", "warning");
          return;
        }

        a.disabled = true;
        a.classList.add("disabled");

        try {
          let savedId = 0;

          if (mode === "create") {
            const created = await createEntry(pid, type, { title, tag, price, event_at, content });
            savedId = Number(created || 0);
          } else {
            await updateEntry(type, entityId, { title, tag, price, event_at, content });
            savedId = entityId;
          }

          // upload files after save
          const files = Array.from(fileInput?.files || []);
          if (files.length && savedId) {
            try {
              await uploadEntryFiles(pid, type, savedId, files);
            } catch (e) {
              toast(e?.message || "Upload fehlgeschlagen.", "warning");
            }
          }

          toast(mode === "edit" ? "Eintrag aktualisiert." : "Eintrag gespeichert.", "success");
          teardownEditors();
          inlineHost.innerHTML = "";
          await loadTimeline(modalEl, pid);
        } catch (e) {
          toast(e?.message || "Speichern fehlgeschlagen.", "error");
        } finally {
          a.disabled = false;
          a.classList.remove("disabled");
        }
      }
    });
  }

  function teardownEditors() {
    // EasyMDE teardown wenn nötig
    try {
      if (state.newEntryEditor && typeof state.newEntryEditor.toTextArea === "function") state.newEntryEditor.toTextArea();
    } catch (_) {}
    try {
      if (state.editEntryEditor && typeof state.editEntryEditor.toTextArea === "function") state.editEntryEditor.toTextArea();
    } catch (_) {}

    state.newEntryEditor = null;
    state.editEntryEditor = null;
  }

  async function createEntry(patientId, type, data) {
    const t = String(type || "").toLowerCase();
    const payload = {
      patient_id: Number(patientId || 0),
      title: data?.title || timelineLabel(t),
      tag: data?.tag || "",
      content: data?.content || "",
    };

    if (t === "treatment") {
      payload.price = data?.price ?? null;
      payload.event_at = data?.event_at ?? null;
    }

    // try variants
    const actionsByType = t === "treatment"
      ? ["save_treatment", "create_treatment", "add_treatment", "saveTreatment"]
      : t === "record"
        ? ["save_record", "create_record", "add_record", "saveRecord"]
        : ["save_note", "create_note", "add_note", "saveNote"];

    let lastErr = null;
    for (const act of actionsByType) {
      try {
        const res = await apiPost(act, payload);
        const id = extractCreatedId(res, t);
        if (id) return id;
        // fallback: some APIs return {data:{id}}
        const maybe = Number(res?.data?.id || res?.id || 0);
        if (maybe) return maybe;
        return 0;
      } catch (e) {
        lastErr = e;
        if (!isUnknownActionError(e)) throw e;
      }
    }

    // generic fallback
    try {
      const res = await apiPost("create_entry", { ...payload, entity_type: t });
      const id = extractCreatedId(res, t) || Number(res?.data?.id || res?.id || 0);
      return id || 0;
    } catch (e) {
      throw lastErr || e;
    }
  }

  function extractCreatedId(json, kind) {
    const j = json && typeof json === "object" ? json : {};
    const direct = Number(j.id || 0);
    if (direct) return direct;

    const dataId = Number(j?.data?.id || 0);
    if (dataId) return dataId;

    if (kind === "treatment") {
      const tid = Number(j.treatment_id || j?.data?.treatment_id || 0);
      if (tid) return tid;
    }
    if (kind === "record") {
      const rid = Number(j.record_id || j?.data?.record_id || 0);
      if (rid) return rid;
    }
    if (kind === "note") {
      const nid = Number(j.note_id || j?.data?.note_id || 0);
      if (nid) return nid;
    }
    return 0;
  }

  async function updateEntry(type, id, data) {
    const t = String(type || "").toLowerCase();
    const payload = {
      id: Number(id || 0),
      title: data?.title || timelineLabel(t),
      tag: data?.tag || "",
      content: data?.content || "",
    };

    if (t === "treatment") {
      payload.price = data?.price ?? null;
      payload.event_at = data?.event_at ?? null;
    }

    const actionsByType = t === "treatment"
      ? ["update_treatment", "save_treatment", "updateTreatment", "saveTreatment"]
      : t === "record"
        ? ["update_record", "save_record", "updateRecord", "saveRecord"]
        : ["update_note", "save_note", "updateNote", "saveNote"];

    let lastErr = null;
    for (const act of actionsByType) {
      try {
        return await apiPost(act, payload);
      } catch (e) {
        lastErr = e;
        if (!isUnknownActionError(e)) throw e;
      }
    }

    // generic fallback
    try {
      return await apiPost("update_entry", { ...payload, entity_type: t });
    } catch (e) {
      throw lastErr || e;
    }
  }

  // -----------------------------
  // Patient health status
  // -----------------------------
  async function setPatientHealthStatus(patientId, healthStatus) {
    const pid = Number(patientId || 0);
    const hs = String(healthStatus || "good");
    if (!pid) throw new Error("Patient-ID fehlt.");

    const candidates = ["set_health_status", "update_health_status", "update_patient_health", "setHealthStatus"];
    let lastErr = null;

    for (const act of candidates) {
      try {
        return await apiPost(act, { patient_id: pid, health_status: hs });
      } catch (e) {
        lastErr = e;
        if (!isUnknownActionError(e)) throw e;
      }
    }
    throw lastErr || new Error("Konnte Gesundheitsstatus nicht speichern.");
  }

  // -----------------------------
  // Patientenakte Modal (öffnen + Daten laden)
  // -----------------------------
  async function openPatientModal(patientId) {
    const pid = Number(patientId || 0);
    if (!pid) return;

    state.activePatientId = pid;
    currentPatientId = pid;

    const { modal } = ensurePatientModalStructure();
    if (!modal) {
      console.warn("[patients.js] Patient-Modal nicht gefunden.");
      return;
    }

    // Persist ID on modal (Fix: currentPatientId fehlt bei create)
    modal.setAttribute("data-patient-id", String(pid));

    // show
    showModal(modal);

    // load patient + timeline
    try {
      const patient = await loadPatient(pid);
      if (patient?.health_status) {
        // sync health select + banner
        const sel = modal.querySelector("[data-role='health-status-select']");
        if (sel) sel.value = String(patient.health_status || "good");
        setHealthBanner(modal, patient.health_status || "good");
      }
      await loadTimeline(modal, pid);
    } catch (e) {
      toast(e?.message || "Fehler beim Laden der Patientenakte.", "error");
    }
  }

  async function loadPatient(patientId) {
    const pid = Number(patientId || 0);
    if (!pid) throw new Error("Patient-ID fehlt.");

    // try actions: get, get_patient, detail, show
    const candidates = [
      ["get", { id: pid }],
      ["get_patient", { id: pid }],
      ["detail", { id: pid }],
      ["show", { id: pid }],
    ];

    let res = null;
    let lastErr = null;

    for (const [act, params] of candidates) {
      try {
        res = await apiGet(act, params);
        break;
      } catch (e) {
        lastErr = e;
        if (!isUnknownActionError(e)) throw e;
      }
    }

    if (!res) throw lastErr || new Error("Patient konnte nicht geladen werden.");

    const patient = res?.data?.patient || res?.patient || res?.data || null;
    if (!patient) throw new Error("Patient nicht gefunden.");

    state.activePatient = patient;

    // optional: update header area if exists
    updatePatientModalHeader(patient);

    return patient;
  }

  function updatePatientModalHeader(patient) {
    // optional: wenn es ein Titel/Body gibt (Bootstrap Modal)
    const modal = getPatientModalElement();
    if (!modal) return;

    const titleEl = modal.querySelector("#patientModalTitle") || modal.querySelector(".modal-title") || null;
    if (titleEl) {
      const name = safeText(patient?.name || "Unbenannt");
      titleEl.textContent = `Patientenakte · ${name}`;
    }

    // optional patient summary body
    const bodyEl = modal.querySelector("#patientModalBody") || modal.querySelector("[data-role='patient-modal-body']") || null;
    if (!bodyEl) return;

    const ownerName = patient.owner_name || [patient.first_name, patient.last_name].filter(Boolean).join(" ") || "—";
    const species = patient.species || "—";
    const breed = patient.breed || "—";
    const gender = patient.gender || "—";
    const birth = patient.birth_date || "—";
    const micro = patient.microchip || "—";
    const weight = patient.weight != null && patient.weight !== "" ? `${patient.weight} kg` : "—";
    const color = patient.color || "—";
    const health = patient.health_status || "good";
    const img = normalizeUrl(patient.image || "");

    bodyEl.innerHTML = `
      <div class="tp-patient-head">
        <div class="tp-patient-avatar">
          ${img ? `<img src="${escapeAttr(img)}" alt="Profilbild" />` : `<div class="tp-avatar-fallback">${escapeHtml((patient.name || "?").slice(0, 1).toUpperCase())}</div>`}
          <label class="tp-avatar-upload" title="Profilbild hochladen">
            <input type="file" id="patientImageUpload" accept="image/*" hidden>
            <span class="tp-avatar-upload-btn">Bild</span>
          </label>
        </div>

        <div class="tp-patient-meta">
          <div class="tp-patient-title-row">
            <div class="tp-patient-name">${escapeHtml(patient.name || "Unbenannt")}</div>
            <div class="tp-patient-health">
              <span class="tp-health-badge ${escapeAttr(healthClass(health))}">${escapeHtml(healthLabel(health))}</span>
              <select id="patientHealthSelect" class="tp-select">
                ${HEALTH.map(h => `<option value="${escapeAttr(h.value)}" ${String(h.value) === String(health) ? "selected" : ""}>${escapeHtml(h.label)}</option>`).join("")}
              </select>
            </div>
          </div>

          <div class="tp-patient-grid">
            <div class="tp-meta-item"><span>Besitzer</span><strong>${escapeHtml(ownerName)}</strong></div>
            <div class="tp-meta-item"><span>Tierart</span><strong>${escapeHtml(species)}</strong></div>
            <div class="tp-meta-item"><span>Rasse</span><strong>${escapeHtml(breed)}</strong></div>
            <div class="tp-meta-item"><span>Geschlecht</span><strong>${escapeHtml(gender)}</strong></div>
            <div class="tp-meta-item"><span>Geburtstag</span><strong>${escapeHtml(birth)}</strong></div>
            <div class="tp-meta-item"><span>Gewicht</span><strong>${escapeHtml(weight)}</strong></div>
            <div class="tp-meta-item"><span>Farbe</span><strong>${escapeHtml(color)}</strong></div>
            <div class="tp-meta-item"><span>Chip</span><strong>${escapeHtml(micro)}</strong></div>
          </div>

          ${patient.notes ? `<div class="tp-patient-notes"><strong>Hinweis:</strong> ${escapeHtml(patient.notes)}</div>` : ""}
        </div>
      </div>
    `;

    // Health change (header select)
    const healthSel = bodyEl.querySelector("#patientHealthSelect");
    if (healthSel && !healthSel.__tpBound) {
      healthSel.__tpBound = true;
      healthSel.addEventListener("change", async () => {
        const v = String(healthSel.value || "good");
        try {
          await setPatientHealthStatus(resolvePatientId(getPatientModalElement()), v);

          const badge = bodyEl.querySelector(".tp-health-badge");
          if (badge) {
            badge.classList.remove("tp-h-very_good", "tp-h-good", "tp-h-ok", "tp-h-bad", "tp-h-critical");
            badge.classList.add(healthClass(v));
            badge.textContent = healthLabel(v);
          }

          // sync toolbar select + banner if present
          const modal = getPatientModalElement();
          const sel = modal?.querySelector("[data-role='health-status-select']");
          if (sel) sel.value = v;
          if (modal) setHealthBanner(modal, v);

          toast("Gesundheitsstatus gespeichert.", "success");
        } catch (e) {
          toast(e?.message || "Konnte Status nicht speichern.", "error");
        }
      });
    }

    // Image upload (header quick)
    const imgInput = bodyEl.querySelector("#patientImageUpload");
    if (imgInput && !imgInput.__tpBound) {
      imgInput.__tpBound = true;
      imgInput.addEventListener("change", async () => {
        const file = imgInput.files?.[0];
        if (!file) return;

        try {
          await uploadPatientProfileImage(state.activePatientId, file);
          toast("Profilbild aktualisiert.", "success");
          await loadPatient(state.activePatientId);
        } catch (e) {
          toast(e?.message || "Profilbild-Upload fehlgeschlagen.", "error");
        } finally {
          imgInput.value = "";
        }
      });
    }
  }

  // -----------------------------
  // Patientenliste (Reload + Render)
  // -----------------------------
  async function reloadPatientsList() {
    const container = qs("#patientsList") || qs("[data-role='patients-list']") || null;
    if (!container) return;

    const q = safeText(qs("#patientsSearch")?.value || "");
    const species = safeText(qs("#patientsSpeciesFilter")?.value || "");

    let action = "list";
    const params = {};

    if (q) {
      action = "search";
      params.q = q;
    }

    let res;
    try {
      res = await apiGet(action, params);
    } catch (e1) {
      if (!isUnknownActionError(e1)) {
        container.innerHTML = `<div class="tp-empty"><div class="tp-empty-title">Fehler</div><div class="tp-empty-sub">${escapeHtml(e1.message || "Unbekannt")}</div></div>`;
        return;
      }
      // fallback: some APIs use get_patients / patients
      try {
        res = await apiGet("get_patients", params);
      } catch (e2) {
        container.innerHTML = `<div class="tp-empty"><div class="tp-empty-title">Fehler</div><div class="tp-empty-sub">${escapeHtml(e2.message || "Unbekannt")}</div></div>`;
        return;
      }
    }

    let items = res?.data?.items || res?.items || res?.data?.patients || res?.patients || [];
    if (!Array.isArray(items)) items = [];

    if (species) {
      items = items.filter((p) => String(p.species || "").toLowerCase() === String(species).toLowerCase());
    }

    renderPatientsList(container, items);
  }

  function renderPatientsList(container, items) {
    if (!items || !items.length) {
      container.innerHTML = `
        <div class="tp-empty">
          <div class="tp-empty-title">Keine Patienten gefunden</div>
          <div class="tp-empty-sub">Passe Suche/Filter an oder lege einen neuen Patienten an.</div>
        </div>
      `;
      return;
    }

    container.innerHTML = `
      <div class="tp-patient-cards">
        ${items.map((p) => renderPatientCard(p)).join("")}
      </div>
    `;

    // bind open/edit/delete (delegation-safe)
    container.addEventListener("click", async (ev) => {
      const btnOpen = ev.target.closest("[data-open-patient]");
      if (btnOpen) {
        ev.preventDefault();
        const id = toInt(btnOpen.getAttribute("data-open-patient"));
        if (id) openPatientModal(id);
        return;
      }

      const btnEdit = ev.target.closest("[data-edit-patient]");
      if (btnEdit) {
        ev.preventDefault();
        const id = toInt(btnEdit.getAttribute("data-edit-patient"));
        const patient = items.find((x) => toInt(x.id) === id) || null;
        if (patient && typeof window.openPatientForm === "function") window.openPatientForm(patient);
        return;
      }

      const btnDel = ev.target.closest("[data-delete-patient]");
      if (btnDel) {
        ev.preventDefault();
        const id = toInt(btnDel.getAttribute("data-delete-patient"));
        const patient = items.find((x) => toInt(x.id) === id) || null;
        if (!id) return;

        const ok = confirm(`Patient "${patient?.name || ""}" wirklich löschen?`);
        if (!ok) return;

        try {
          await deletePatient(id);
          toast("Patient gelöscht.", "success");
          await reloadPatientsList();
        } catch (e) {
          toast(e?.message || "Löschen fehlgeschlagen.", "error");
        }
      }
    }, { once: true });
  }

  function renderPatientCard(p) {
    const id = toInt(p.id);
    const name = p.name || "Unbenannt";
    const species = p.species || "—";
    const breed = p.breed || "";
    const owner = p.owner_name || [p.first_name, p.last_name].filter(Boolean).join(" ") || "—";
    const img = normalizeUrl(p.image || "");
    const health = String(p.health_status || "good");

    return `
      <div class="tp-card">
        <div class="tp-card-left">
          <div class="tp-card-avatar">
            ${img ? `<img src="${escapeAttr(img)}" alt="">` : `<div class="tp-avatar-fallback">${escapeHtml(name.slice(0, 1).toUpperCase())}</div>`}
          </div>
        </div>

        <div class="tp-card-mid">
          <div class="tp-card-top">
            <div class="tp-card-name">${escapeHtml(name)}</div>
            <span class="tp-health-badge ${escapeAttr(healthClass(health))}">${escapeHtml(healthLabel(health))}</span>
          </div>
          <div class="tp-card-sub">
            <span>${escapeHtml(species)}</span>
            ${breed ? `<span class="tp-dot">•</span><span>${escapeHtml(breed)}</span>` : ""}
            <span class="tp-dot">•</span>
            <span>Besitzer: ${escapeHtml(owner)}</span>
          </div>
        </div>

        <div class="tp-card-right">
          <button type="button" class="tp-btn tp-btn-primary" data-open-patient="${escapeAttr(String(id))}">Öffnen</button>
          <button type="button" class="tp-btn tp-btn-ghost" data-edit-patient="${escapeAttr(String(id))}">Bearbeiten</button>
          <button type="button" class="tp-btn tp-btn-ghost tp-danger" data-delete-patient="${escapeAttr(String(id))}">Löschen</button>
        </div>
      </div>
    `;
  }

  async function deletePatient(id) {
    const pid = Number(id || 0);
    if (!pid) throw new Error("Patient-ID fehlt.");

    const candidates = ["delete", "delete_patient", "remove", "destroy"];
    let lastErr = null;

    for (const act of candidates) {
      try {
        return await apiPost(act, { id: pid });
      } catch (e) {
        lastErr = e;
        if (!isUnknownActionError(e)) throw e;
      }
    }
    throw lastErr || new Error("Löschen fehlgeschlagen.");
  }

  // -----------------------------
  // Patient Save (Create/Edit modal) – robust gegen “Unbekannte Aktion: update”
  // -----------------------------
  async function savePatientRobust(patientPayload) {
    const payload = patientPayload && typeof patientPayload === "object" ? patientPayload : {};
    const pid = Number(payload.id || payload.patient_id || 0);

    // Reihenfolge: wenn ID da -> update-Varianten, sonst create-Varianten
    const updateActions = ["update_patient", "update", "save", "edit", "put"];
    const createActions = ["create_patient", "create", "save", "add", "post"];

    const actions = pid ? updateActions : createActions;

    let lastErr = null;
    for (const act of actions) {
      try {
        const res = await apiPost(act, payload);
        return res;
      } catch (e) {
        lastErr = e;
        if (!isUnknownActionError(e)) throw e;
      }
    }
    throw lastErr || new Error("Speichern fehlgeschlagen (keine passende Backend-Aktion gefunden).");
  }

  async function afterPatientSaveHandleProfileImage(savedJson) {
    const patientId = extractPatientIdFromResponse(savedJson) || Number(state.activePatientId || 0);
    if (!patientId) return;

    // Entfernen zuerst
    if (PROFILE_IMG.removeRequested) {
      try {
        await deletePatientProfileImage(patientId);
        PROFILE_IMG.removeRequested = false;
        PROFILE_IMG.existingUrl = "";
        setProfileImageExistingUrl("");
      } catch (e) {
        toast(e?.message || "Profilbild konnte nicht gelöscht werden.", "warning");
      }
    }

    // Upload wenn gewählt
    if (PROFILE_IMG.pendingFile) {
      try {
        const up = await uploadPatientProfileImage(patientId, PROFILE_IMG.pendingFile);
        const newUrl = normalizeUrl(up?.image || up?.data?.image || up?.url || "");
        PROFILE_IMG.pendingFile = null;
        revokeProfilePreviewUrl();
        PROFILE_IMG.removeRequested = false;
        if (newUrl) {
          setProfileImageExistingUrl(newUrl);
          if (typeof window.__tpSetPatientProfileImagePreview === "function") window.__tpSetPatientProfileImagePreview(newUrl);
        }
      } catch (e) {
        toast(e?.message || "Profilbild-Upload fehlgeschlagen.", "warning");
      }
    }
  }

  // Optional: Hook für Alpine/Twig (falls du savePatient() im Template aufrufst)
  window.tpPatientsSavePatient = async function (patientPayload) {
  try {
    const payload = patientPayload && typeof patientPayload === "object" ? patientPayload : {};
    const pid = Number(payload.id || payload.patient_id || 0);
    
    // Check: Profilbild Upload nötig?
    const hasProfileImage = PROFILE_IMG.pendingFile !== null;
    
    let res;
    
    if (hasProfileImage) {
      // ✅ FormData: Patient-Daten + Profilbild zusammen
      const fd = new FormData();
      
      // Alle Patient-Felder als einzelne Form-Fields
      Object.entries(payload).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== "") {
          fd.append(key, String(value));
        }
      });
      
      // Profilbild anhängen (Backend erwartet "patient_image" oder "file")
      fd.append("patient_image", PROFILE_IMG.pendingFile);
      fd.append("file", PROFILE_IMG.pendingFile); // Fallback für manche Backends
      
      // Action wählen
      const action = pid ? "update_patient" : "create_patient";
      const url = buildApiUrl(action);
      
      // ⚠️ WICHTIG: Bei FormData KEIN Content-Type Header setzen (Browser macht das automatisch mit boundary)
      res = await apiFetch(url, {
        method: "POST",
        body: fd,
      });
      
      // Cleanup
      PROFILE_IMG.pendingFile = null;
      revokeProfilePreviewUrl();
      PROFILE_IMG.removeRequested = false;
      
      // Neue Bild-URL setzen
      const newUrl = normalizeUrl(res?.image_url || res?.data?.image_url || res?.patient?.image_url || "");
      if (newUrl) {
        setProfileImageExistingUrl(newUrl);
        if (typeof window.__tpSetPatientProfileImagePreview === "function") {
          window.__tpSetPatientProfileImagePreview(newUrl);
        }
      }
      
    } else {
      // Kein Bild -> normaler JSON-Flow
      res = await savePatientRobust(payload);
      await afterPatientSaveHandleProfileImage(res);
    }
    
    return res;
    
  } catch (e) {
    throw e;
  }
};

  // -----------------------------
  // Global init / bindings
  // -----------------------------
  function bindGlobalPatientsUi() {
    // Inputs
    const search = qs("#patientsSearch");
    if (search && !search.dataset.tpBound) {
      search.dataset.tpBound = "1";
      search.addEventListener("input", debounce(() => reloadPatientsList(), 250));
    }

    const species = qs("#patientsSpeciesFilter");
    if (species && !species.dataset.tpBound) {
      species.dataset.tpBound = "1";
      species.addEventListener("change", () => reloadPatientsList());
    }

    // External open trigger
    window.addEventListener("tp-open-patient", (ev) => {
      const id = toInt(ev?.detail?.id);
      if (id) openPatientModal(id);
    });

    // Ensure buttons default type=button (prevent page reload)
    document.addEventListener("click", (ev) => {
      const btn = ev.target.closest("button");
      if (!btn) return;
      if (!btn.getAttribute("type")) btn.setAttribute("type", "button");
    });

    // Close modal triggers
    const modal = getPatientModalElement();
    if (modal && !modal.__tpCloseBound) {
      modal.__tpCloseBound = true;
      qsa('[data-dismiss="modal"], [data-action="close-modal"], .tp-modal-close', modal).forEach((btn) => {
        btn.addEventListener("click", () => hideModal(modal));
      });

      // click on backdrop (if custom modal)
      modal.addEventListener("click", (ev) => {
        if (ev.target === modal && modal.classList.contains("tp-modal")) {
          hideModal(modal);
        }
      });
    }
  }

  // -----------------------------
  // Boot
  // -----------------------------
  (function bootPatients() {
    try {
      ensurePatientsCssLoaded();
      forceCloseAllModalsOnBoot();
      watchProfileUploaderInjection();
      bindGlobalPatientsUi();
    } catch (e) {
      console.error(e);
    }

    if (qs("#patientsList") || qs("[data-role='patients-list']")) {
      reloadPatientsList().catch(() => {});
    }
  })();
})();