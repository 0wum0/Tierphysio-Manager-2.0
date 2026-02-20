/**
 * Tierphysio Manager 2.0
 * Invoices Management JS (Alpine)
 *
 * Features:
 * - Rechnungen laden (Zeitraum/Status/Patient) über /api/invoiced.php
 * - Client-side Suche
 * - Quick-Stats aus geladenen Daten berechnet
 * - "Neue Rechnung" Modal:
 *   - Patienten laden (action=patients)
 *   - Speichern (action=create)
 *
 * Robustness:
 * - AbortError wird NICHT getoastet
 * - API Endpoint Override via data-api-base am Root (Twig setzt: /api/invoiced.php)
 * - Kein Reload beim Klick auf "Neue Rechnung"
 * - Deutsche Labels (Status, Tierarten)
 *
 * FIX (Button):
 * - Twig ruft ggf. goNewInvoice() auf -> Alias auf openNewInvoiceModal()
 */

(function () {
  // -----------------------------
  // Helpers
  // -----------------------------

  function _toast(message, type = "info") {
    if (typeof window.showToast === "function") {
      window.showToast(message, type);
      return;
    }
    console[type === "error" ? "error" : "log"](message);
  }

  function _isAbortError(err) {
    return !!err && (err.name === "AbortError" || String(err).toLowerCase().includes("aborted"));
  }

  function _pad(n) {
    return String(n).padStart(2, "0");
  }

  function _toYMD(d) {
    const dt = (d instanceof Date) ? d : new Date(d);
    return `${dt.getFullYear()}-${_pad(dt.getMonth() + 1)}-${_pad(dt.getDate())}`;
  }

  function _parseYMD(ymd) {
    const [y, m, d] = String(ymd || "").split("-").map(x => parseInt(x, 10));
    if (!y || !m || !d) return new Date();
    return new Date(y, m - 1, d);
  }

  function _startOfMonth(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
  }

  function _endOfMonth(date) {
    return new Date(date.getFullYear(), date.getMonth() + 1, 0);
  }

  function _formatDateDE(ymd) {
    if (!ymd) return "—";
    const d = _parseYMD(ymd);
    return d.toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric" });
  }

  function _formatEUR(amount) {
    const num = Number(amount || 0);
    try {
      return new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(num);
    } catch (_) {
      return "€ " + num.toFixed(2).replace(".", ",");
    }
  }

  function _normalizeStatus(s) {
    const v = String(s || "").trim().toLowerCase();
    if (!v || v === "null" || v === "undefined") return "draft";
    return v;
  }

  function _statusLabelDE(status) {
    const s = _normalizeStatus(status);
    switch (s) {
      case "draft": return "Entwurf";
      case "sent": return "Offen";
      case "paid": return "Bezahlt";
      case "overdue": return "Überfällig";
      case "cancelled": return "Storniert";
      default: return s;
    }
  }

  function _speciesLabelDE(species) {
    const s = String(species || "").trim().toLowerCase();
    if (s === "dog" || s === "hund") return "Hund";
    if (s === "cat" || s === "katze") return "Katze";
    if (s === "horse" || s === "pferd") return "Pferd";
    if (s === "rabbit" || s === "kaninchen") return "Kaninchen";
    if (s === "guinea_pig" || s === "meerschweinchen") return "Meerschweinchen";
    if (s === "other" || s === "sonstiges") return "Sonstiges";
    if (s.length > 0) return s.charAt(0).toUpperCase() + s.slice(1);
    return "—";
  }

  function _getRoot() {
    return document.querySelector("[data-invoices-root]");
  }

  // -----------------------------
  // API Wrapper (+ Override)
  // -----------------------------

  const _api = {
    base: null,

    _rootOverride() {
      const root = _getRoot();
      if (!root) return null;
      const attr = root.getAttribute("data-api-base");
      if (attr && String(attr).trim() !== "") return String(attr).trim();
      if (typeof window.__TP_INVOICES_API_BASE === "string" && window.__TP_INVOICES_API_BASE.trim() !== "") {
        return window.__TP_INVOICES_API_BASE.trim();
      }
      return null;
    },

    resolve() {
      const ov = this._rootOverride();
      this.base = ov || this.base || "/api/invoiced.php";
      return this.base;
    },

    async get(params = {}, signal) {
      const base = this.resolve();
      const qs = new URLSearchParams(params);
      const url = `${base}?${qs.toString()}`;

      const res = await fetch(url, {
        method: "GET",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        signal
      });

      const ct = (res.headers.get("content-type") || "").toLowerCase();
      if (!ct.includes("application/json")) {
        const txt = await res.text().catch(() => "");
        throw new Error(`Kein JSON erhalten (evtl. Redirect/Login/404). Response: ${txt.slice(0, 180)}`);
      }
      return await res.json();
    },

    async post(action, payload = {}) {
      const base = this.resolve();
      const url = `${base}?action=${encodeURIComponent(action)}`;

      const res = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        },
        body: JSON.stringify(payload)
      });

      const ct = (res.headers.get("content-type") || "").toLowerCase();
      if (!ct.includes("application/json")) {
        const txt = await res.text().catch(() => "");
        throw new Error(`Kein JSON erhalten (evtl. Redirect/Login/404). Response: ${txt.slice(0, 180)}`);
      }
      return await res.json();
    }
  };

  // -----------------------------
  // Alpine Component
  // -----------------------------

  function invoicesManager() {
    return {
      // -----------------------------
      // State
      // -----------------------------
      loading: false,
      invoices: [],
      _allInvoices: [],

      // filters
      searchQuery: "",
      statusFilter: "",
      patientFilter: 0,

      // timeframe
      periodFilter: "month",
      dateFrom: "",
      dateTo: "",

      // pagination
      page: 1,
      perPage: 200,
      totalCount: 0,

      // quick stats
      stats: {
        monthRevenueTotal: 0,
        openAmount: 0,
        openCount: 0,
        overdueAmount: 0,
        overdueCount: 0,
        paidMonthAmount: 0,
        paidMonthCount: 0
      },

      // internals
      _debounceTimer: null,
      _controller: null,
      _lastToastAt: 0,

      // -----------------------------
      // Modal: Neue Rechnung
      // -----------------------------
      newInvoiceModalOpen: false,
      savingInvoice: false,

      patientsLoading: false,
      patients: [],

      newInvoice: {
        patient_id: 0,
        invoice_date: "",
        due_date: "",
        status: "draft",
        notes: "",
        items: []
      },

      // -----------------------------
      // Init
      // -----------------------------
      init() {
        const root = _getRoot();
        const now = new Date();

        const ms = _startOfMonth(now);
        const me = _endOfMonth(now);
        this.dateFrom = _toYMD(ms);
        this.dateTo = _toYMD(me);
        this.periodFilter = "month";

        if (root) {
          const df = root.getAttribute("data-date-from");
          const dt = root.getAttribute("data-date-to");
          const p = root.getAttribute("data-period");
          const st = root.getAttribute("data-status");
          const pf = root.getAttribute("data-patient");

          if (df) this.dateFrom = df;
          if (dt) this.dateTo = dt;
          if (p) this.periodFilter = p;
          if (st) this.statusFilter = st;
          if (pf) this.patientFilter = parseInt(pf, 10) || 0;
        }

        this.newInvoice.invoice_date = _toYMD(now);
        this.newInvoice.due_date = _toYMD(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 14));

        this.page = 1;
        this.loadInvoices();

        if (typeof window.anime !== "undefined") {
          try {
            window.anime({
              targets: ".animate-in > *",
              translateY: [30, 0],
              opacity: [0, 1],
              duration: 800,
              delay: window.anime.stagger(80),
              easing: "easeOutExpo"
            });
          } catch (_) {}
        }
      },

      // -----------------------------
      // UI helpers
      // -----------------------------
      formatDate(ymd) {
        return _formatDateDE(ymd);
      },

      formatEUR(amount) {
        return _formatEUR(amount);
      },

      statusLabel(status) {
        return _statusLabelDE(status);
      },

      speciesLabel(species) {
        return _speciesLabelDE(species);
      },

      statusBadgeClass(status) {
        const s = _normalizeStatus(status);
        if (s === "paid") return "bg-green-500/20 text-green-300";
        if (s === "overdue") return "bg-red-500/20 text-red-300";
        if (s === "sent") return "bg-yellow-500/20 text-yellow-300";
        if (s === "cancelled") return "bg-gray-500/20 text-gray-300";
        return "bg-purple-500/20 text-purple-300";
      },

      // -----------------------------
      // Period switcher
      // -----------------------------
      setPeriod(p) {
        this.periodFilter = p;

        const now = new Date();
        if (p === "month") {
          this.dateFrom = _toYMD(_startOfMonth(now));
          this.dateTo = _toYMD(_endOfMonth(now));
        } else if (p === "quarter") {
          const q = Math.floor(now.getMonth() / 3);
          const start = new Date(now.getFullYear(), q * 3, 1);
          const end = new Date(now.getFullYear(), q * 3 + 3, 0);
          this.dateFrom = _toYMD(start);
          this.dateTo = _toYMD(end);
        } else if (p === "year") {
          this.dateFrom = `${now.getFullYear()}-01-01`;
          this.dateTo = `${now.getFullYear()}-12-31`;
        } else if (p === "all") {
          this.dateFrom = "2000-01-01";
          this.dateTo = "2099-12-31";
        }
      },

      refreshAll() {
        this.page = 1;
        this.loadInvoices();
      },

      // -----------------------------
      // Search debounce (client-side)
      // -----------------------------
      debounceSearch() {
        clearTimeout(this._debounceTimer);
        this._debounceTimer = setTimeout(() => {
          this.applyClientSearch();
        }, 180);
      },

      applyClientSearch() {
        const q = String(this.searchQuery || "").trim().toLowerCase();
        if (!q) {
          this.invoices = [...this._allInvoices];
          return;
        }

        const match = (obj, key) => String(obj?.[key] || "").toLowerCase().includes(q);

        this.invoices = this._allInvoices.filter(inv =>
          match(inv, "invoice_number") ||
          match(inv, "customer_number") ||
          match(inv, "owner_full_name") ||
          match(inv, "owner_first_name") ||
          match(inv, "owner_last_name") ||
          match(inv, "patient_name") ||
          match(inv, "patient_number") ||
          match(inv, "status") ||
          match(inv, "notes")
        );
      },

      // -----------------------------
      // Load invoices
      // -----------------------------
      async loadInvoices() {
        if (this._controller) this._controller.abort();
        this._controller = new AbortController();

        this.loading = true;

        const limit = parseInt(this.perPage || 200, 10) || 200;
        const offset = 0;

        const params = {
          action: "list",
          date_from: this.dateFrom || _toYMD(_startOfMonth(new Date())),
          date_to: this.dateTo || _toYMD(_endOfMonth(new Date())),
          limit,
          offset
        };

        if (this.statusFilter) params.status = this.statusFilter;
        if (parseInt(this.patientFilter || 0, 10) > 0) params.patient_id = parseInt(this.patientFilter, 10);

        try {
          const data = await _api.get(params, this._controller.signal);

          if (!data || (data.status !== "success" && data.ok !== true)) {
            throw new Error(data?.message || "Fehler beim Laden der Rechnungen");
          }

          const items =
            Array.isArray(data.items) ? data.items :
            (Array.isArray(data.data?.items) ? data.data.items : []);

          const normalized = items.map(inv => {
            const total = Number(inv.total_amount ?? inv.total ?? 0) || 0;
            const net = Number(inv.net_amount ?? inv.subtotal ?? 0) || 0;
            const tax = Number(inv.tax_amount ?? 0) || 0;

            return {
              ...inv,
              status: _normalizeStatus(inv.status),
              total_amount: total,
              net_amount: net,
              tax_amount: tax,
              invoice_date: inv.invoice_date || null,
              due_date: inv.due_date || null,
              owner_full_name: inv.owner_full_name || [inv.owner_first_name, inv.owner_last_name].filter(Boolean).join(" ").trim(),
              patient_name: inv.patient_name || null
            };
          });

          this._allInvoices = normalized;
          this.invoices = [...normalized];

          const count =
            (typeof data.count !== "undefined") ? data.count :
            (typeof data.data?.count !== "undefined") ? data.data.count :
            normalized.length;

          this.totalCount = parseInt(count, 10) || normalized.length;

          this.applyClientSearch();
          this.computeStatsFromLoaded();

        } catch (err) {
          if (_isAbortError(err)) return;

          const now = Date.now();
          if (now - this._lastToastAt > 1200) {
            this._lastToastAt = now;
            _toast("Fehler beim Laden der Rechnungen: " + (err.message || err), "error");
          }
          console.error("[invoices][loadInvoices]", err);

          this._allInvoices = [];
          this.invoices = [];
          this.totalCount = 0;

          this.stats = {
            monthRevenueTotal: 0,
            openAmount: 0,
            openCount: 0,
            overdueAmount: 0,
            overdueCount: 0,
            paidMonthAmount: 0,
            paidMonthCount: 0
          };
        } finally {
          this.loading = false;
        }
      },

      // -----------------------------
      // Quick Stats (aus loaded invoices)
      // -----------------------------
      computeStatsFromLoaded() {
        const list = Array.isArray(this._allInvoices) ? this._allInvoices : [];

        const now = new Date();
        const monthFrom = _toYMD(_startOfMonth(now));
        const monthTo = _toYMD(_endOfMonth(now));

        let monthRevenueTotal = 0;
        let openAmount = 0;
        let openCount = 0;
        let overdueAmount = 0;
        let overdueCount = 0;
        let paidMonthAmount = 0;
        let paidMonthCount = 0;

        for (const inv of list) {
          const status = _normalizeStatus(inv.status);
          const total = Number(inv.total_amount || inv.total || 0) || 0;
          const invDate = String(inv.invoice_date || "");

          if (invDate >= monthFrom && invDate <= monthTo && status !== "cancelled") {
            monthRevenueTotal += total;
          }

          if (status === "sent") {
            openAmount += total;
            openCount += 1;
          }

          if (status === "overdue") {
            overdueAmount += total;
            overdueCount += 1;
          }

          if (status === "paid" && invDate >= monthFrom && invDate <= monthTo) {
            paidMonthAmount += total;
            paidMonthCount += 1;
          }
        }

        this.stats = {
          monthRevenueTotal,
          openAmount,
          openCount,
          overdueAmount,
          overdueCount,
          paidMonthAmount,
          paidMonthCount
        };
      },

      // -----------------------------
      // Navigation placeholders
      // -----------------------------
      goViewInvoice(id) {
        window.location.href = `/public/invoices.php?action=view&id=${encodeURIComponent(id)}`;
      },

      downloadInvoicePdf(id) {
        _toast("PDF-Export ist noch nicht angebunden.", "info");
      },

      // -----------------------------
      // ✅ FIX: Button-Kompatibilität (Twig ruft goNewInvoice())
      // -----------------------------
      goNewInvoice() {
        // Alias für alte Twig-Buttons
        return this.openNewInvoiceModal();
      },

      // -----------------------------
      // Modal: Neue Rechnung - open/close
      // -----------------------------
      async openNewInvoiceModal() {
        this.newInvoiceModalOpen = true;

        const now = new Date();
        this.newInvoice = {
          patient_id: 0,
          invoice_date: _toYMD(now),
          due_date: _toYMD(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 14)),
          status: "draft",
          notes: "",
          items: []
        };

        // Default: 1 Position 75€ / Stunde
        this.newInvoice.items.push({
          description: "Therapie / Behandlung",
          quantity: 1,
          unit: "Stunde",
          price: 75.0,
          tax_rate: 19.0,
          discount_percent: 0
        });

        await this.loadPatientsForModal();
      },

      closeNewInvoiceModal() {
        this.newInvoiceModalOpen = false;
      },

      addNewInvoiceItem() {
        this.newInvoice.items.push({
          description: "",
          quantity: 1,
          unit: "Stück",
          price: 0.0,
          tax_rate: 19.0,
          discount_percent: 0
        });
      },

      removeNewInvoiceItem(idx) {
        const i = parseInt(idx, 10);
        if (Number.isNaN(i)) return;
        this.newInvoice.items.splice(i, 1);
      },

      patientOptionLabel(p) {
        const owner = p.owner_full_name ? ` – ${p.owner_full_name}` : "";
        const pn = p.patient_number ? ` (#${p.patient_number})` : "";
        const sp = p.species ? ` (${this.speciesLabel(p.species)})` : "";
        return `${p.name || "Patient"}${sp}${pn}${owner}`;
      },

      async loadPatientsForModal() {
        this.patientsLoading = true;
        try {
          const data = await _api.get({ action: "patients", limit: 300 });

          if (!data || (data.status !== "success" && data.ok !== true)) {
            throw new Error(data?.message || "Fehler beim Laden der Patienten");
          }

          const items =
            Array.isArray(data.items) ? data.items :
            (Array.isArray(data.data?.items) ? data.data.items : []);

          this.patients = items || [];

          if (this.patients.length === 0) {
            _toast("Keine Patienten gefunden. Prüfe ob tp_patients Daten enthält (is_active=1).", "info");
          }
        } catch (err) {
          _toast("Fehler beim Laden der Patienten: " + (err.message || err), "error");
          this.patients = [];
          console.error("[invoices][patients]", err);
        } finally {
          this.patientsLoading = false;
        }
      },

      calcNewInvoiceTotals() {
        const items = Array.isArray(this.newInvoice.items) ? this.newInvoice.items : [];
        let net = 0;
        let tax = 0;

        for (const it of items) {
          const qty = Number(it.quantity || 0) || 0;
          const price = Number(it.price || 0) || 0;
          const taxRate = Number(it.tax_rate || 0) || 0;
          const disc = Number(it.discount_percent || 0) || 0;

          let rowNet = qty * price;
          if (disc > 0) rowNet = rowNet * (1 - disc / 100);
          const rowTax = rowNet * (taxRate / 100);

          net += rowNet;
          tax += rowTax;
        }

        const total = net + tax;
        return { net, tax, total };
      },

      async saveNewInvoice() {
        if (this.savingInvoice) return;

        const pid = parseInt(this.newInvoice.patient_id || 0, 10);
        if (!pid) {
          _toast("Bitte zuerst einen Patienten auswählen.", "error");
          return;
        }

        if (!Array.isArray(this.newInvoice.items) || this.newInvoice.items.length === 0) {
          _toast("Bitte mindestens eine Position hinzufügen.", "error");
          return;
        }

        for (const it of this.newInvoice.items) {
          const qty = Number(it.quantity || 0) || 0;
          if (qty <= 0) {
            _toast("Eine Position hat Menge 0. Bitte korrigieren.", "error");
            return;
          }
        }

        this.savingInvoice = true;

        try {
          const payload = {
            patient_id: pid,
            invoice_date: this.newInvoice.invoice_date,
            due_date: this.newInvoice.due_date,
            status: this.newInvoice.status || "draft",
            notes: this.newInvoice.notes || "",
            items: this.newInvoice.items.map(it => ({
              description: String(it.description || "Leistung"),
              quantity: Number(it.quantity || 0) || 0,
              unit: String(it.unit || "Stück"),
              price: Number(it.price || 0) || 0,
              tax_rate: Number(it.tax_rate || 19) || 19,
              discount_percent: Number(it.discount_percent || 0) || 0
            }))
          };

          const res = await _api.post("create", payload);

          if (!res || (res.status !== "success" && res.ok !== true)) {
            throw new Error(res?.message || "Fehler beim Speichern der Rechnung");
          }

          _toast("Rechnung wurde gespeichert.", "success");

          this.newInvoiceModalOpen = false;
          await this.loadInvoices();

        } catch (err) {
          _toast("Fehler beim Speichern: " + (err.message || err), "error");
          console.error("[invoices][create]", err);
        } finally {
          this.savingInvoice = false;
        }
      }
    };
  }

  // Expose
  window.invoicesManager = invoicesManager;
  console.log("invoices.js loaded - window.invoicesManager is ready");

  // Alpine late-load init
  (function ensureAlpineInitializedForInvoices() {
    const root = _getRoot();
    if (!root) {
      console.warn("[invoices] Alpine fix: root not found yet ([data-invoices-root])");
      return;
    }

    if (!window.Alpine) {
      console.warn("[invoices] Alpine not found on window yet. (Maybe script order?)");
      return;
    }

    try {
      if (typeof window.Alpine.data === "function") {
        if (!window.__tpInvoicesAlpineDataRegistered) {
          window.__tpInvoicesAlpineDataRegistered = true;
          window.Alpine.data("invoicesManager", invoicesManager);
          console.log("[invoices] Alpine.data('invoicesManager', ...) registered");
        }
      }
    } catch (e) {
      console.warn("[invoices] Alpine.data register failed:", e);
    }

    try {
      if (!root.__x && typeof window.Alpine.initTree === "function") {
        console.log("[invoices] Alpine.initTree(root) running (late script load fix)");
        window.Alpine.initTree(root);
      } else {
        console.log("[invoices] Alpine already initialized for this root:", !!root.__x);
      }
    } catch (e) {
      console.error("[invoices] Alpine.initTree failed:", e);
    }
  })();

})();