/**
 * Tierphysio Manager 2.0
 * Invoices Management JavaScript
 *
 * Features:
 * - Rechnungen laden + filtern
 * - Rechnung erstellen / bearbeiten (Modal)
 * - Als bezahlt markieren (status=paid + payment_date=today)
 * - Ansehen (View-Modal) + PDF öffnen / herunterladen
 *
 * API:
 * - /api/invoiced.php?action=list|get|create|update|delete|patients|statistics|pdf
 *
 * Robustheit:
 * - akzeptiert Response-Formate:
 *   a) {status:"success", data:{items:[], count:n}}
 *   b) {status:"success", items:[]}
 *   c) {ok:true, status:"success", data:{items:[]}}
 * - verhindert Page-Reload bei Buttons/Links über Event-Delegation
 */

(function () {
  "use strict";

  const API = "/api/invoiced.php";

  // -----------------------------
  // Helpers
  // -----------------------------
  function safeToast(msg, type = "info") {
    if (typeof window.showToast === "function") {
      window.showToast(msg, type);
      return;
    }
    console[type === "error" ? "error" : "log"]("[Toast]", msg);
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
      cache: "no-store",
      credentials: "same-origin",
      ...options,
    });

    // try json first, otherwise show snippet
    let data = null;
    let text = "";
    try {
      data = await res.json();
    } catch (e) {
      try {
        text = await res.text();
      } catch (_) {
        text = "";
      }
      throw new Error(
        `Ungültige JSON-Antwort von ${url}. Status=${res.status}. Body=${(text || "").slice(0, 250)}`
      );
    }

    if (!res.ok) {
      throw new Error(data?.message || `HTTP Fehler ${res.status} bei ${url}`);
    }

    // API helper sometimes returns ok:false but HTTP 200
    if (data && (data.ok === false || data.status === "error")) {
      throw new Error(data?.message || "Unbekannter Fehler");
    }

    return data;
  }

  function unwrapItems(resp) {
    if (!resp) return { items: [], count: 0 };

    // Most common: {status:"success", data:{items,count}}
    const data = resp.data || resp?.data?.data; // extra safety
    let items =
      (data && Array.isArray(data.items) ? data.items : null) ||
      (Array.isArray(resp.items) ? resp.items : null) ||
      (Array.isArray(resp?.data?.items) ? resp.data.items : null) ||
      [];

    let count =
      (data && typeof data.count === "number" ? data.count : null) ||
      (typeof resp.count === "number" ? resp.count : null) ||
      items.length;

    return { items, count };
  }

  function todayISO() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function euro(n) {
    const v = Number(n || 0);
    return v.toLocaleString("de-DE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " €";
  }

  function toFloat(v) {
    if (v === null || v === undefined) return 0;
    if (typeof v === "number") return v;
    const s = String(v).replace(",", ".");
    const n = parseFloat(s);
    return Number.isFinite(n) ? n : 0;
  }

  // Bootstrap modal helpers (works with BS5; safe if not present)
  function getBsModal(el) {
    if (!el) return null;
    const BS = window.bootstrap;
    if (!BS || !BS.Modal) return null;
    try {
      return BS.Modal.getOrCreateInstance(el, { backdrop: "static" });
    } catch {
      return null;
    }
  }

  // -----------------------------
  // State
  // -----------------------------
  const state = {
    invoices: [],
    patients: [],
    loading: false,
    lastListParams: null,
    editingId: null,
    viewId: null,
  };

  // -----------------------------
  // DOM selectors (tolerant)
  // -----------------------------
  const dom = {
    // Filters
    from: null,
    to: null,
    status: null,
    patient: null,
    btnReload: null,

    // List container
    list: null,
    listCount: null,

    // Create/Edit Modal
    modal: null,
    modalTitle: null,
    form: null,
    btnNew: null,
    btnSave: null,
    btnAddItem: null,
    itemsBody: null,
    fieldPatient: null,
    fieldInvoiceDate: null,
    fieldDueDate: null,
    fieldStatus: null,
    fieldNotes: null,
    fieldPaymentMethod: null,
    fieldPaymentDate: null,
    totalsNet: null,
    totalsTax: null,
    totalsTotal: null,

    // View Modal
    viewModal: null,
    viewTitle: null,
    viewBody: null,
    btnViewPdf: null,
    btnViewDownload: null,
    btnViewMarkPaid: null,
  };

  function bindDom() {
    // Filters
    dom.from = document.querySelector("#invFilterFrom, [name='invFilterFrom'], [data-invoices-filter='from']");
    dom.to = document.querySelector("#invFilterTo, [name='invFilterTo'], [data-invoices-filter='to']");
    dom.status = document.querySelector("#invFilterStatus, [name='invFilterStatus'], [data-invoices-filter='status']");
    dom.patient = document.querySelector("#invFilterPatient, [name='invFilterPatient'], [data-invoices-filter='patient']");
    dom.btnReload = document.querySelector("#btnReloadInvoices, [data-invoices-action='reload']");

    // List
    dom.list = document.querySelector("#invoicesList, [data-invoices-list]");
    dom.listCount = document.querySelector("#invoicesCount, [data-invoices-count]");

    // Modal
    dom.modal = document.querySelector("#invoiceModal, #invoiceCreateModal, [data-invoice-modal='edit']");
    dom.modalTitle = dom.modal ? dom.modal.querySelector(".modal-title, [data-invoice-modal-title]") : null;
    dom.form = dom.modal ? dom.modal.querySelector("form") : null;
    dom.btnNew = document.querySelector("#btnNewInvoice, [data-invoice-action='new']");
    dom.btnSave = dom.modal ? dom.modal.querySelector("#btnSaveInvoice, [data-invoice-action='save']") : null;
    dom.btnAddItem = dom.modal ? dom.modal.querySelector("#btnAddInvoiceItem, [data-invoice-action='add-item']") : null;
    dom.itemsBody =
      dom.modal?.querySelector("#invoiceItemsBody, tbody[data-invoice-items]") || document.querySelector("tbody[data-invoice-items]");

    dom.fieldPatient = dom.modal ? dom.modal.querySelector("#invoicePatientSelect, [name='patient_id'], [data-field='patient_id']") : null;
    dom.fieldInvoiceDate = dom.modal ? dom.modal.querySelector("#invoiceDate, [name='invoice_date'], [data-field='invoice_date']") : null;
    dom.fieldDueDate = dom.modal ? dom.modal.querySelector("#invoiceDueDate, [name='due_date'], [data-field='due_date']") : null;
    dom.fieldStatus = dom.modal ? dom.modal.querySelector("#invoiceStatus, [name='status'], [data-field='status']") : null;
    dom.fieldNotes = dom.modal ? dom.modal.querySelector("#invoiceNotes, [name='notes'], [data-field='notes']") : null;
    dom.fieldPaymentMethod = dom.modal ? dom.modal.querySelector("#invoicePaymentMethod, [name='payment_method'], [data-field='payment_method']") : null;
    dom.fieldPaymentDate = dom.modal ? dom.modal.querySelector("#invoicePaymentDate, [name='payment_date'], [data-field='payment_date']") : null;

    dom.totalsNet = dom.modal ? dom.modal.querySelector("[data-total='net'], #invoiceTotalNet") : null;
    dom.totalsTax = dom.modal ? dom.modal.querySelector("[data-total='tax'], #invoiceTotalTax") : null;
    dom.totalsTotal = dom.modal ? dom.modal.querySelector("[data-total='total'], #invoiceTotalAll") : null;

    // View Modal
    dom.viewModal = document.querySelector("#invoiceViewModal, [data-invoice-modal='view']");
    dom.viewTitle = dom.viewModal ? dom.viewModal.querySelector(".modal-title, [data-invoice-view-title]") : null;
    dom.viewBody = dom.viewModal ? dom.viewModal.querySelector("[data-invoice-view-body], .modal-body") : null;
    dom.btnViewPdf = dom.viewModal ? dom.viewModal.querySelector("[data-invoice-action='view-pdf'], #btnInvoiceViewPdf") : null;
    dom.btnViewDownload = dom.viewModal ? dom.viewModal.querySelector("[data-invoice-action='download-pdf'], #btnInvoiceDownloadPdf") : null;
    dom.btnViewMarkPaid = dom.viewModal ? dom.viewModal.querySelector("[data-invoice-action='mark-paid'], #btnInvoiceMarkPaid") : null;
  }

  // -----------------------------
  // Patients dropdown population (for modal + filter)
  // -----------------------------
  function formatPatientLabel(p) {
    const owner =
      (p.owner_full_name || "").trim() ||
      [p.owner_first_name, p.owner_last_name].filter(Boolean).join(" ").trim();

    const pn = (p.patient_number || "").trim();
    const cn = (p.customer_number || "").trim();

    // Example: "Bella (Hund) – Kunde: Max Mustermann (K-0001) – P-0002"
    const parts = [];
    parts.push(p.patient_name || p.name || "Patient");
    if (p.species) parts.push(`(${p.species})`);
    let label = parts.join(" ");

    const tail = [];
    if (owner) tail.push(`Kunde: ${owner}${cn ? ` (${cn})` : ""}`);
    if (pn) tail.push(pn);

    if (tail.length) label += ` – ${tail.join(" – ")}`;
    return label;
  }

  function setPatientOptions(selectEl, patients, allowEmptyLabel = "— Bitte wählen —") {
    if (!selectEl) return;

    const current = selectEl.value;

    selectEl.innerHTML = "";
    if (allowEmptyLabel) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = allowEmptyLabel;
      selectEl.appendChild(opt);
    }

    for (const p of patients) {
      const opt = document.createElement("option");
      opt.value = String(p.id);
      opt.textContent = formatPatientLabel(p);
      selectEl.appendChild(opt);
    }

    // restore previous if still exists
    if (current) {
      const exists = Array.from(selectEl.options).some((o) => o.value === current);
      if (exists) selectEl.value = current;
    }
  }

  async function loadPatients() {
    try {
      const resp = await fetchJson(`${API}?action=patients&limit=2000`);
      const { items } = unwrapItems(resp);
      state.patients = items || [];

      // Fill modal patient select
      setPatientOptions(dom.fieldPatient, state.patients, "— Patient auswählen —");

      // Fill filter select (optional)
      if (dom.patient) {
        setPatientOptions(dom.patient, state.patients, "Alle Patienten");
      }
    } catch (err) {
      console.warn("[Invoices] Patients load failed:", err);
      // Do not spam toast
    }
  }

  // -----------------------------
  // List rendering
  // -----------------------------
  function renderList(invoices) {
    if (!dom.list) return;

    // If template already renders list server-side, we only patch count.
    // If list is empty or marked as dynamic, we render cards/rows.
    const dynamic = dom.list.hasAttribute("data-dynamic") || dom.list.dataset?.dynamic === "1";

    if (!dynamic) {
      if (dom.listCount) dom.listCount.textContent = String(invoices.length);
      return;
    }

    dom.list.innerHTML = "";

    if (!Array.isArray(invoices) || invoices.length === 0) {
      const empty = document.createElement("div");
      empty.className = "text-muted p-3";
      empty.textContent = "Keine Rechnungen gefunden.";
      dom.list.appendChild(empty);
      if (dom.listCount) dom.listCount.textContent = "0";
      return;
    }

    for (const inv of invoices) {
      const id = inv.id;
      const invNo = inv.invoice_number || `#${id}`;
      const patient = inv.patient_name || "—";
      const owner = inv.owner_full_name || "—";
      const date = inv.invoice_date || "—";
      const status = inv.status || "draft";
      const total = inv.total_amount ?? inv.total ?? 0;

      const card = document.createElement("div");
      card.className = "card mb-2";
      card.innerHTML = `
        <div class="card-body d-flex flex-column flex-md-row gap-2 align-items-start align-items-md-center justify-content-between">
          <div class="flex-grow-1">
            <div class="fw-semibold">${escapeHtml(invNo)} <span class="text-muted">· ${escapeHtml(date)}</span></div>
            <div class="small text-muted">${escapeHtml(owner)} · ${escapeHtml(patient)}</div>
            <div class="small">Status: <span class="badge bg-secondary">${escapeHtml(status)}</span></div>
          </div>
          <div class="text-end">
            <div class="fw-bold">${escapeHtml(euro(total))}</div>
            <div class="btn-group btn-group-sm mt-1" role="group">
              <button type="button" class="btn btn-outline-primary" data-invoice-action="view" data-id="${id}">Ansehen</button>
              <button type="button" class="btn btn-outline-secondary" data-invoice-action="edit" data-id="${id}">Bearbeiten</button>
              <button type="button" class="btn btn-outline-success" data-invoice-action="paid" data-id="${id}">Bezahlt</button>
              <button type="button" class="btn btn-outline-dark" data-invoice-action="pdf" data-id="${id}">PDF</button>
            </div>
          </div>
        </div>
      `;
      dom.list.appendChild(card);
    }

    if (dom.listCount) dom.listCount.textContent = String(invoices.length);
  }

  function escapeHtml(str) {
    const s = String(str ?? "");
    return s
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  // -----------------------------
  // Load invoices
  // -----------------------------
  function getListParamsFromUI() {
    const params = new URLSearchParams();
    params.set("action", "list");

    const from = dom.from?.value ? String(dom.from.value).trim() : "";
    const to = dom.to?.value ? String(dom.to.value).trim() : "";
    const status = dom.status?.value ? String(dom.status.value).trim() : "";
    const patientId = dom.patient?.value ? String(dom.patient.value).trim() : "";

    // API supports from/to or date_from/date_to. We send from/to.
    if (from) params.set("from", from);
    if (to) params.set("to", to);
    if (status) params.set("status", status);
    if (patientId) params.set("patient_id", patientId);

    return params;
  }

  async function loadInvoices() {
    try {
      state.loading = true;

      const params = getListParamsFromUI();
      state.lastListParams = params.toString();

      const resp = await fetchJson(`${API}?${params.toString()}`);
      const { items } = unwrapItems(resp);

      state.invoices = Array.isArray(items) ? items : [];
      renderList(state.invoices);
    } catch (err) {
      console.error("[Invoices] loadInvoices failed:", err);
      safeToast("Fehler beim Laden der Rechnungen", "error");
      renderList([]);
    } finally {
      state.loading = false;
    }
  }

  // -----------------------------
  // Modal: create/edit
  // -----------------------------
  function resetEditModal() {
    state.editingId = null;

    if (dom.modalTitle) dom.modalTitle.textContent = "Neue Rechnung";

    if (dom.fieldPatient) dom.fieldPatient.value = "";
    if (dom.fieldInvoiceDate) dom.fieldInvoiceDate.value = todayISO();
    if (dom.fieldDueDate) dom.fieldDueDate.value = "";
    if (dom.fieldStatus) dom.fieldStatus.value = "draft";
    if (dom.fieldNotes) dom.fieldNotes.value = "";
    if (dom.fieldPaymentMethod) dom.fieldPaymentMethod.value = "";
    if (dom.fieldPaymentDate) dom.fieldPaymentDate.value = "";

    if (dom.itemsBody) {
      dom.itemsBody.innerHTML = "";
      addItemRow({ description: "", quantity: 1, unit_price: 0, tax_rate: 0, unit: "Stück" });
    }

    recalcTotals();
  }

  function addItemRow(item = {}) {
    if (!dom.itemsBody) return;

    const tr = document.createElement("tr");
    tr.setAttribute("data-invoice-item-row", "1");

    // We keep names generic; invoices.twig can read via JS, not submit form.
    tr.innerHTML = `
      <td>
        <input type="text" class="form-control form-control-sm" data-item="description" value="${escapeHtml(item.description || "")}" placeholder="Beschreibung">
      </td>
      <td style="width:120px;">
        <input type="number" step="0.01" min="0.01" class="form-control form-control-sm text-end" data-item="quantity" value="${escapeHtml(item.quantity ?? 1)}">
      </td>
      <td style="width:110px;">
        <input type="text" class="form-control form-control-sm text-end" data-item="unit" value="${escapeHtml(item.unit || "Stück")}" placeholder="Einheit">
      </td>
      <td style="width:140px;">
        <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" data-item="unit_price" value="${escapeHtml(item.unit_price ?? 0)}">
      </td>
      <td style="width:110px;">
        <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" data-item="tax_rate" value="${escapeHtml(item.tax_rate ?? 0)}">
      </td>
      <td class="text-end" style="width:140px;">
        <span class="small fw-semibold" data-item="line_total">0,00 €</span>
      </td>
      <td class="text-end" style="width:60px;">
        <button type="button" class="btn btn-sm btn-outline-danger" data-invoice-action="remove-item" title="Entfernen">×</button>
      </td>
    `;

    dom.itemsBody.appendChild(tr);
    recalcTotals();
  }

  function readItemsFromModal() {
    if (!dom.itemsBody) return [];

    const rows = dom.itemsBody.querySelectorAll("tr[data-invoice-item-row]");
    const items = [];

    rows.forEach((tr) => {
      const desc = tr.querySelector("[data-item='description']")?.value ?? "";
      const qty = toFloat(tr.querySelector("[data-item='quantity']")?.value ?? 0);
      const unit = tr.querySelector("[data-item='unit']")?.value ?? "Stück";
      const unitPrice = toFloat(tr.querySelector("[data-item='unit_price']")?.value ?? 0);
      const taxRate = toFloat(tr.querySelector("[data-item='tax_rate']")?.value ?? 0);

      // ignore empty lines
      if (!String(desc).trim() && qty <= 0 && unitPrice <= 0) return;

      items.push({
        description: String(desc || "").trim() || "Position",
        quantity: Math.max(0.01, qty || 1),
        unit: String(unit || "Stück").trim() || "Stück",
        unit_price: Math.max(0, unitPrice || 0),
        tax_rate: Math.max(0, taxRate || 0),
      });
    });

    return items;
  }

  function recalcTotals() {
    if (!dom.itemsBody) return;

    let net = 0;
    let tax = 0;
    let total = 0;

    const rows = dom.itemsBody.querySelectorAll("tr[data-invoice-item-row]");
    rows.forEach((tr) => {
      const qty = toFloat(tr.querySelector("[data-item='quantity']")?.value ?? 0);
      const unitPrice = toFloat(tr.querySelector("[data-item='unit_price']")?.value ?? 0);
      const taxRate = toFloat(tr.querySelector("[data-item='tax_rate']")?.value ?? 0);

      const lineNet = Math.max(0, qty) * Math.max(0, unitPrice);
      const lineTax = lineNet * (Math.max(0, taxRate) / 100);

      net += lineNet;
      tax += lineTax;
      total += lineNet + lineTax;

      const lineTotalEl = tr.querySelector("[data-item='line_total']");
      if (lineTotalEl) lineTotalEl.textContent = euro(lineNet + lineTax);
    });

    if (dom.totalsNet) dom.totalsNet.textContent = euro(net);
    if (dom.totalsTax) dom.totalsTax.textContent = euro(tax);
    if (dom.totalsTotal) dom.totalsTotal.textContent = euro(total);
  }

  function recalcTotalsThrottled() {
    // small throttle to prevent too many recalcs on keypress
    if (recalcTotalsThrottled._t) cancelAnimationFrame(recalcTotalsThrottled._t);
    recalcTotalsThrottled._t = requestAnimationFrame(recalcTotals);
  }

  function openEditModal() {
    if (!dom.modal) {
      safeToast("Modal nicht gefunden (invoiceModal). Bitte invoices.twig prüfen.", "error");
      return;
    }
    const m = getBsModal(dom.modal);
    if (m) m.show();
    else dom.modal.classList.add("show");
  }

  function closeEditModal() {
    if (!dom.modal) return;
    const m = getBsModal(dom.modal);
    if (m) m.hide();
    else dom.modal.classList.remove("show");
  }

  async function openNewInvoice() {
    resetEditModal();
    openEditModal();
  }

  async function openEditInvoice(id) {
    if (!id) return;
    try {
      const resp = await fetchJson(`${API}?action=get&id=${encodeURIComponent(id)}`);
      const { items } = unwrapItems(resp);
      const inv = Array.isArray(items) && items.length ? items[0] : null;

      if (!inv) {
        safeToast("Rechnung nicht gefunden", "error");
        return;
      }

      state.editingId = inv.id;
      if (dom.modalTitle) dom.modalTitle.textContent = `Rechnung bearbeiten ${inv.invoice_number || "#" + inv.id}`;

      if (dom.fieldPatient) dom.fieldPatient.value = String(inv.patient_id || "");
      if (dom.fieldInvoiceDate) dom.fieldInvoiceDate.value = inv.invoice_date || todayISO();
      if (dom.fieldDueDate) dom.fieldDueDate.value = inv.due_date || "";
      if (dom.fieldStatus) dom.fieldStatus.value = inv.status || "draft";
      if (dom.fieldNotes) dom.fieldNotes.value = inv.notes || "";
      if (dom.fieldPaymentMethod) dom.fieldPaymentMethod.value = inv.payment_method || "";
      if (dom.fieldPaymentDate) dom.fieldPaymentDate.value = inv.payment_date || "";

      if (dom.itemsBody) {
        dom.itemsBody.innerHTML = "";
        const its = Array.isArray(inv.items) ? inv.items : [];
        if (its.length === 0) {
          addItemRow({ description: "", quantity: 1, unit_price: 0, tax_rate: 0, unit: "Stück" });
        } else {
          its.forEach((it) => {
            addItemRow({
              description: it.description || "",
              quantity: it.quantity ?? 1,
              unit: it.unit || "Stück",
              unit_price: it.unit_price ?? it.price ?? 0,
              tax_rate: it.tax_rate ?? 0,
            });
          });
        }
      }

      recalcTotals();
      openEditModal();
    } catch (err) {
      console.error("[Invoices] openEditInvoice failed:", err);
      safeToast(err.message || "Fehler beim Laden der Rechnung", "error");
    }
  }

  async function saveInvoiceFromModal() {
    try {
      if (!dom.fieldPatient || !String(dom.fieldPatient.value || "").trim()) {
        safeToast("Bitte Patient auswählen.", "error");
        return;
      }

      const items = readItemsFromModal();
      if (items.length < 1) {
        safeToast("Mindestens eine Position erforderlich.", "error");
        return;
      }

      const payload = {
        id: state.editingId || undefined,
        patient_id: parseInt(dom.fieldPatient.value, 10),
        invoice_date: dom.fieldInvoiceDate?.value || todayISO(),
        due_date: dom.fieldDueDate?.value || "",
        status: dom.fieldStatus?.value || "draft",
        notes: dom.fieldNotes?.value || "",
        payment_method: dom.fieldPaymentMethod?.value || "",
        payment_date: dom.fieldPaymentDate?.value || "",
        items,
      };

      const action = state.editingId ? "update" : "create";

      const resp = await fetchJson(`${API}?action=${action}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      // success
      safeToast(state.editingId ? "Rechnung aktualisiert" : "Rechnung erstellt", "success");
      closeEditModal();
      await loadInvoices();
    } catch (err) {
      console.error("[Invoices] saveInvoiceFromModal failed:", err);
      safeToast(err.message || "Fehler beim Speichern der Rechnung", "error");
    }
  }

  // -----------------------------
  // View Modal
  // -----------------------------
  function openViewModal() {
    if (!dom.viewModal) return;
    const m = getBsModal(dom.viewModal);
    if (m) m.show();
    else dom.viewModal.classList.add("show");
  }

  function closeViewModal() {
    if (!dom.viewModal) return;
    const m = getBsModal(dom.viewModal);
    if (m) m.hide();
    else dom.viewModal.classList.remove("show");
  }

  function renderInvoiceView(inv) {
    if (!dom.viewBody) return;

    const invNo = inv.invoice_number || `#${inv.id}`;
    const owner = inv.owner_full_name || "—";
    const patient = inv.patient_name || "—";
    const date = inv.invoice_date || "—";
    const due = inv.due_date || "—";
    const status = inv.status || "draft";

    const items = Array.isArray(inv.items) ? inv.items : [];
    let itemsHtml = "";
    if (items.length) {
      itemsHtml += `<div class="table-responsive mt-3"><table class="table table-sm align-middle">
        <thead><tr>
          <th>Beschreibung</th>
          <th class="text-end">Menge</th>
          <th class="text-end">Einzel</th>
          <th class="text-end">MwSt%</th>
          <th class="text-end">Gesamt</th>
        </tr></thead><tbody>`;

      items.forEach((it) => {
        const desc = escapeHtml(it.description || "");
        const qty = toFloat(it.quantity || 0).toLocaleString("de-DE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const up = euro(it.unit_price ?? it.price ?? 0);
        const tr = (it.tax_rate ?? 0).toLocaleString("de-DE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const tot = euro(it.total_price ?? it.total ?? 0);

        itemsHtml += `<tr>
          <td>${desc}</td>
          <td class="text-end">${qty}</td>
          <td class="text-end">${escapeHtml(up)}</td>
          <td class="text-end">${escapeHtml(tr)}</td>
          <td class="text-end fw-semibold">${escapeHtml(tot)}</td>
        </tr>`;
      });

      itemsHtml += `</tbody></table></div>`;
    } else {
      itemsHtml = `<div class="text-muted mt-3">Keine Positionen vorhanden.</div>`;
    }

    const net = euro(inv.net_amount ?? inv.subtotal ?? 0);
    const tax = euro(inv.tax_amount ?? 0);
    const total = euro(inv.total_amount ?? inv.total ?? 0);

    dom.viewBody.innerHTML = `
      <div class="d-flex flex-column gap-2">
        <div class="d-flex flex-wrap gap-2 justify-content-between">
          <div>
            <div class="fw-bold">${escapeHtml(invNo)}</div>
            <div class="text-muted small">${escapeHtml(owner)} · ${escapeHtml(patient)}</div>
          </div>
          <div class="text-end">
            <div class="small">Datum: <span class="fw-semibold">${escapeHtml(date)}</span></div>
            <div class="small">Fällig: <span class="fw-semibold">${escapeHtml(due)}</span></div>
            <div class="small">Status: <span class="badge bg-secondary">${escapeHtml(status)}</span></div>
          </div>
        </div>

        ${itemsHtml}

        <div class="mt-2 ms-auto" style="max-width:360px;">
          <div class="d-flex justify-content-between"><div class="text-muted">Zwischensumme</div><div class="fw-semibold">${escapeHtml(net)}</div></div>
          <div class="d-flex justify-content-between"><div class="text-muted">MwSt</div><div class="fw-semibold">${escapeHtml(tax)}</div></div>
          <div class="d-flex justify-content-between fs-6"><div class="fw-bold">Gesamt</div><div class="fw-bold">${escapeHtml(total)}</div></div>
        </div>

        ${inv.notes ? `<div class="mt-3"><div class="fw-semibold">Notizen</div><div class="text-muted">${escapeHtml(inv.notes).replaceAll("\n", "<br>")}</div></div>` : ""}
      </div>
    `;
  }

  async function openViewInvoice(id) {
    if (!id) return;
    try {
      const resp = await fetchJson(`${API}?action=get&id=${encodeURIComponent(id)}`);
      const { items } = unwrapItems(resp);
      const inv = Array.isArray(items) && items.length ? items[0] : null;

      if (!inv) {
        safeToast("Rechnung nicht gefunden", "error");
        return;
      }

      state.viewId = inv.id;

      if (dom.viewTitle) dom.viewTitle.textContent = `Rechnung ${inv.invoice_number || "#" + inv.id}`;
      renderInvoiceView(inv);

      // wire view buttons
      if (dom.btnViewPdf) dom.btnViewPdf.setAttribute("data-id", String(inv.id));
      if (dom.btnViewDownload) dom.btnViewDownload.setAttribute("data-id", String(inv.id));
      if (dom.btnViewMarkPaid) dom.btnViewMarkPaid.setAttribute("data-id", String(inv.id));

      openViewModal();
    } catch (err) {
      console.error("[Invoices] openViewInvoice failed:", err);
      safeToast(err.message || "Fehler beim Laden der Rechnung", "error");
    }
  }

  // -----------------------------
  // Paid / PDF / Delete actions
  // -----------------------------
  async function markInvoicePaid(id) {
    if (!id) return;
    try {
      const payload = {
        id: parseInt(id, 10),
        status: "paid",
        payment_date: todayISO(),
      };

      const resp = await fetchJson(`${API}?action=update`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      safeToast("Rechnung als bezahlt markiert", "success");
      await loadInvoices();

      // If view modal open for same id -> refresh view
      if (state.viewId && String(state.viewId) === String(id)) {
        await openViewInvoice(id);
      }
    } catch (err) {
      console.error("[Invoices] markInvoicePaid failed:", err);
      safeToast(err.message || "Fehler beim Markieren als bezahlt", "error");
    }
  }

  function openPdf(id, download = false) {
    if (!id) return;
    const url = `${API}?action=pdf&id=${encodeURIComponent(id)}`;

    // If you want forced download: open same URL, but server outputs inline.
    // We'll provide a hint: open in new tab; user can download there.
    if (download) {
      window.open(url, "_blank", "noopener");
    } else {
      window.open(url, "_blank", "noopener");
    }
  }

  async function deleteInvoice(id) {
    if (!id) return;
    if (!confirm("Rechnung wirklich löschen?")) return;

    try {
      await fetchJson(`${API}?action=delete`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: parseInt(id, 10) }),
      });

      safeToast("Rechnung gelöscht", "success");
      await loadInvoices();
    } catch (err) {
      console.error("[Invoices] deleteInvoice failed:", err);
      safeToast(err.message || "Fehler beim Löschen", "error");
    }
  }

  // -----------------------------
  // Global Event Delegation
  // -----------------------------
  function onGlobalClick(e) {
    const target = e.target;

    // Any element with data-invoice-action should never trigger navigation
    const actionEl = target.closest("[data-invoice-action]");
    if (actionEl) {
      // prevent page reload if button is actually a link or inside a form
      e.preventDefault();
      e.stopPropagation();

      const action = actionEl.getAttribute("data-invoice-action");
      const id = actionEl.getAttribute("data-id") || actionEl.getAttribute("data-invoice-id");

      switch (action) {
        case "new":
          openNewInvoice();
          return;
        case "save":
          saveInvoiceFromModal();
          return;
        case "add-item":
          addItemRow({ description: "", quantity: 1, unit_price: 0, tax_rate: 0, unit: "Stück" });
          return;
        case "remove-item": {
          const row = actionEl.closest("tr[data-invoice-item-row]");
          if (row && dom.itemsBody) {
            row.remove();
            // keep at least one row
            if (dom.itemsBody.querySelectorAll("tr[data-invoice-item-row]").length === 0) {
              addItemRow({ description: "", quantity: 1, unit_price: 0, tax_rate: 0, unit: "Stück" });
            }
            recalcTotals();
          }
          return;
        }
        case "edit":
          openEditInvoice(id);
          return;
        case "view":
          openViewInvoice(id);
          return;
        case "paid":
          markInvoicePaid(id);
          return;
        case "pdf":
          openPdf(id, false);
          return;
        case "download":
          openPdf(id, true);
          return;
        case "delete":
          deleteInvoice(id);
          return;
        case "view-pdf":
          openPdf(id || state.viewId, false);
          return;
        case "download-pdf":
          openPdf(id || state.viewId, true);
          return;
        case "mark-paid":
          markInvoicePaid(id || state.viewId);
          return;
        default:
          console.warn("[Invoices] Unknown action:", action);
          return;
      }
    }

    // Specific legacy IDs (in case invoices.twig uses them)
    const legacyNew = target.closest("#btnNewInvoice");
    if (legacyNew) {
      e.preventDefault();
      openNewInvoice();
      return;
    }

    const legacySave = target.closest("#btnSaveInvoice");
    if (legacySave) {
      e.preventDefault();
      saveInvoiceFromModal();
      return;
    }
  }

  function onGlobalInput(e) {
    const t = e.target;
    if (!t) return;

    // Recalc totals when editing item inputs
    if (
      t.closest("#invoiceModal, #invoiceCreateModal, [data-invoice-modal='edit']") &&
      (t.matches("[data-item='quantity']") ||
        t.matches("[data-item='unit_price']") ||
        t.matches("[data-item='tax_rate']") ||
        t.matches("[data-item='description']"))
    ) {
      recalcTotalsThrottled();
    }

    // Auto payment_date if status becomes paid (nice UX)
    if (dom.fieldStatus && t === dom.fieldStatus) {
      const v = String(dom.fieldStatus.value || "").toLowerCase();
      if (v === "paid" && dom.fieldPaymentDate && !String(dom.fieldPaymentDate.value || "").trim()) {
        dom.fieldPaymentDate.value = todayISO();
      }
    }
  }

  // -----------------------------
  // Init
  // -----------------------------
  async function init() {
    bindDom();

    // Prevent form submits from reloading page
    if (dom.form) {
      dom.form.addEventListener("submit", function (e) {
        e.preventDefault();
        saveInvoiceFromModal();
      });
    }

    // Filter change triggers reload
    const filterEls = [dom.from, dom.to, dom.status, dom.patient].filter(Boolean);
    filterEls.forEach((el) => {
      el.addEventListener("change", () => loadInvoices().catch(() => {}));
    });

    // Manual reload
    if (dom.btnReload) {
      dom.btnReload.addEventListener("click", (e) => {
        e.preventDefault();
        loadInvoices().catch(() => {});
      });
    }

    // Modal add-item button (if exists)
    if (dom.btnAddItem) {
      dom.btnAddItem.addEventListener("click", (e) => {
        e.preventDefault();
        addItemRow({ description: "", quantity: 1, unit_price: 0, tax_rate: 0, unit: "Stück" });
      });
    }

    // New invoice button (if exists)
    if (dom.btnNew) {
      dom.btnNew.addEventListener("click", (e) => {
        e.preventDefault();
        openNewInvoice();
      });
    }

    // Save button inside modal (if exists)
    if (dom.btnSave) {
      dom.btnSave.addEventListener("click", (e) => {
        e.preventDefault();
        saveInvoiceFromModal();
      });
    }

    // View modal buttons (optional)
    if (dom.btnViewPdf) {
      dom.btnViewPdf.addEventListener("click", (e) => {
        e.preventDefault();
        openPdf(dom.btnViewPdf.getAttribute("data-id") || state.viewId, false);
      });
    }
    if (dom.btnViewDownload) {
      dom.btnViewDownload.addEventListener("click", (e) => {
        e.preventDefault();
        openPdf(dom.btnViewDownload.getAttribute("data-id") || state.viewId, true);
      });
    }
    if (dom.btnViewMarkPaid) {
      dom.btnViewMarkPaid.addEventListener("click", (e) => {
        e.preventDefault();
        markInvoicePaid(dom.btnViewMarkPaid.getAttribute("data-id") || state.viewId);
      });
    }

    // Global listeners
    document.addEventListener("click", onGlobalClick, true);
    document.addEventListener("input", onGlobalInput, true);

    // Defaults for date filters if empty
    if (dom.to && !dom.to.value) dom.to.value = todayISO();
    if (dom.from && !dom.from.value) {
      // 30 days back
      const d = new Date();
      d.setDate(d.getDate() - 30);
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, "0");
      const day = String(d.getDate()).padStart(2, "0");
      dom.from.value = `${y}-${m}-${day}`;
    }

    // Ensure modal starts with at least one item row
    if (dom.itemsBody && dom.itemsBody.querySelectorAll("tr[data-invoice-item-row]").length === 0) {
      addItemRow({ description: "", quantity: 1, unit_price: 0, tax_rate: 0, unit: "Stück" });
    }

    // Load patients + invoices
    await loadPatients().catch(() => {});
    await loadInvoices().catch(() => {});

    console.log("[Invoices] invoices.js loaded");
  }

  document.addEventListener("DOMContentLoaded", () => {
    init().catch((e) => console.error("[Invoices] init error:", e));
  });
})();