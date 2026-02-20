/**
 * Tierphysio Manager 2.0
 * Appointments Management JS (Alpine)
 *
 * Features:
 * - Termine laden (day/week/month) über Appointments API
 * - Filter: Status, Therapeut
 * - Client-side Suche (Patient/Besitzer/Therapeut/Notizen…)
 * - Create / Edit / Delete via Modal
 * - Quick Stats GLOBAL (Heute / Diese Woche / Wartend / Abgesagt)
 *
 * Robustness:
 * - AbortError (bei schneller Suche/Wechsel) wird NICHT getoastet
 * - API Endpoint Auto-Discovery
 * - Kein Toast-Spam
 *
 * FIX (Tablet/Browser):
 * - Keine "??" Operatoren in Alpine-Expressions nötig (Kompatibilität)
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
    try {
      console[type === "error" ? "error" : "log"](message);
    } catch (_) {}
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
    const parts = String(ymd || "").split("-");
    const y = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    const d = parseInt(parts[2], 10);
    if (!y || !m || !d) return new Date();
    return new Date(y, m - 1, d);
  }

  function _startOfWeekISO(date) {
    // ISO week: Monday start
    const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const day = d.getDay(); // 0 Sunday, 1 Monday ...
    const diff = (day === 0 ? -6 : 1) - day;
    d.setDate(d.getDate() + diff);
    d.setHours(0, 0, 0, 0);
    return d;
  }

  function _endOfWeekISO(date) {
    const s = _startOfWeekISO(date);
    const e = new Date(s);
    e.setDate(s.getDate() + 6);
    e.setHours(23, 59, 59, 999);
    return e;
  }

  function _startOfMonth(date) {
    const d = new Date(date.getFullYear(), date.getMonth(), 1);
    d.setHours(0, 0, 0, 0);
    return d;
  }

  function _endOfMonth(date) {
    const d = new Date(date.getFullYear(), date.getMonth() + 1, 0);
    d.setHours(23, 59, 59, 999);
    return d;
  }

  function _normalizeTime(t) {
    if (!t) return "";
    const s = String(t).trim();
    if (/^\d{2}:\d{2}:\d{2}$/.test(s)) return s;
    if (/^\d{2}:\d{2}$/.test(s)) return s + ":00";
    return s;
  }

  function _formatTime(t) {
    if (!t) return "—";
    const s = String(t);
    if (s.length >= 5) return s.slice(0, 5);
    return s;
  }

  function _formatDateDE(ymd) {
    if (!ymd) return "—";
    const d = _parseYMD(ymd);
    try {
      return d.toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric" });
    } catch (_) {
      return ymd;
    }
  }

  function _calcEndTime(start_time, duration) {
    const st = _normalizeTime(start_time);
    if (!st) return "";
    const parts = st.split(":").map(x => parseInt(x, 10));
    const hh = parts[0] || 0;
    const mm = parts[1] || 0;

    const dur = parseInt(duration || 60, 10) || 60;
    let total = hh * 60 + mm + dur;

    total = total % (24 * 60);
    const eh = Math.floor(total / 60);
    const em = total % 60;
    return `${_pad(eh)}:${_pad(em)}:00`;
  }

  function _getRoot() {
    return document.querySelector("[data-appointments-root]");
  }

  function _safeLower(v) {
    return String(v || "").trim().toLowerCase();
  }

  // -----------------------------
  // Labels (Deutsch)
  // -----------------------------

  const LABELS = {
    status: {
      scheduled: "Geplant",
      confirmed: "Bestätigt",
      pending: "Wartend",
      cancelled: "Abgesagt",
      completed: "Abgeschlossen"
    },
    type: {
      treatment: "Physiotherapie",
      initial: "Ersttermin",
      followup: "Folgetermin",
      emergency: "Notfall",
      massage: "Massage"
    },
    species: {
      dog: "Hund",
      cat: "Katze",
      horse: "Pferd",
      rabbit: "Kaninchen",
      guinea_pig: "Meerschweinchen",
      hamster: "Hamster",
      bird: "Vogel",
      ferret: "Frettchen",
      reptile: "Reptil",
      other: "Sonstiges"
    }
  };

  function _statusKey(raw) {
    const s = _safeLower(raw);
    if (!s || s === "null" || s === "undefined") return "pending";
    if (s === "canceled") return "cancelled";
    return s;
  }

  function _typeKey(raw) {
    const t = _safeLower(raw);
    if (!t || t === "null" || t === "undefined") return "treatment";
    return t;
  }

  function _speciesKey(raw) {
    const sp = _safeLower(raw);
    if (!sp || sp === "null" || sp === "undefined") return "";
    return sp;
  }

  // -----------------------------
  // Pricing (75 €/h)
  // -----------------------------

  function _calcPriceEUR(durationMinutes) {
    const mins = parseInt(durationMinutes || 60, 10) || 60;
    const price = (75 * mins) / 60;
    // 2 decimals
    return Math.round(price * 100) / 100;
  }

  function _formatEUR(value) {
    const n = Number(value || 0);
    try {
      return new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(n);
    } catch (_) {
      return `${n.toFixed(2)} €`;
    }
  }

  // -----------------------------
  // API Endpoint Discovery
  // -----------------------------

  const _api = {
    base: null,
    trying: false,
    candidates: [
      "/api/appointments.php",
      "/api/appoiments.php",
      "/api/appoiment.php",
      "/api/appointment.php",
      "/api/appointments_api.php"
    ],

    async resolve() {
      if (this.base) return this.base;
      if (this.trying) {
        let guard = 0;
        while (!this.base && guard++ < 40) {
          await new Promise(r => setTimeout(r, 50));
        }
        if (this.base) return this.base;
      }

      this.trying = true;

      for (const candidate of this.candidates) {
        try {
          const url = `${candidate}?action=integrity`;
          const res = await fetch(url, {
            method: "GET",
            headers: { "X-Requested-With": "XMLHttpRequest" }
          });

          const ct = (res.headers.get("content-type") || "").toLowerCase();
          if (!ct.includes("application/json")) continue;

          const data = await res.json().catch(() => null);
          if (data && (data.status === "success" || data.status === "error")) {
            this.base = candidate;
            break;
          }
        } catch (_) {
          // ignore
        }
      }

      this.trying = false;

      if (!this.base) {
        throw new Error("Appointments API nicht gefunden. Prüfe Pfad z.B. /api/appointments.php");
      }

      return this.base;
    },

    async get(params = {}, signal) {
      const base = await this.resolve();
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
        throw new Error(`Kein JSON erhalten (evtl. Redirect/Login). Response: ${txt.slice(0, 180)}`);
      }

      return await res.json();
    },

    async post(action, payload = {}) {
      const base = await this.resolve();
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
        throw new Error(`Kein JSON erhalten (evtl. Redirect/Login). Response: ${txt.slice(0, 180)}`);
      }

      return await res.json();
    }
  };

  // -----------------------------
  // Alpine Component
  // -----------------------------

  function appointmentsManager() {
    return {
      // state
      loading: false,
      appointments: [],
      _allAppointments: [],

      currentDate: _toYMD(new Date()),
      viewType: "month",

      searchQuery: "",
      statusFilter: "",
      therapistFilter: 0,

      showModal: false,
      modalMode: "create", // create|edit
      currentAppointment: null,

      // Quick Stats (GLOBAL)
      statsLoading: true,
      todayCount: 0,
      weekCount: 0,
      pendingCount: 0,
      cancelledCount: 0,

      // Debug panel
      debugOpen: false,
      apiBase: "",
      lastApiOkAt: "",
      lastStatsOkAt: "",
      lastApiError: "",
      lastStatsError: "",

      // internals
      _debounceTimer: null,
      _controller: null,
      _lastToastAt: 0,

      init() {
        const root = _getRoot();
        if (root) {
          const d = root.getAttribute("data-date");
          const v = root.getAttribute("data-view");
          if (d) this.currentDate = d;
          if (v) this.viewType = v;
        }

        this.resetForm();

        // Resolve API base once for Debug
        _api.resolve().then((b) => {
          this.apiBase = b;
        }).catch((e) => {
          this.apiBase = "";
          this.lastApiError = String(e && e.message ? e.message : e);
        });

        this.loadAppointments();
        this.refreshQuickStats();

        // Optional animation
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

      // UI helpers used by twig
      formatDate(ymd) {
        return _formatDateDE(ymd);
      },

      formatTime(t) {
        return _formatTime(t);
      },

      // Labels (Deutsch)
      statusLabel(s) {
        const key = _statusKey(s);
        return LABELS.status[key] || "Geplant";
      },

      typeLabel(t) {
        const key = _typeKey(t);
        return LABELS.type[key] || "Physiotherapie";
      },

      speciesLabel(sp) {
        const key = _speciesKey(sp);
        if (!key) return "—";
        return LABELS.species[key] || (sp || "—");
      },

      // Pricing helpers
      calcPrice(durationMinutes) {
        return _calcPriceEUR(durationMinutes);
      },

      formatEUR(value) {
        return _formatEUR(value);
      },

      // view switching
      setView(v) {
        this.viewType = v;
        this.loadAppointments();
        // Stats bleiben GLOBAL, aber wir refreshen optional trotzdem
        this.refreshQuickStats();
      },

      prev() {
        const d = _parseYMD(this.currentDate);
        if (this.viewType === "day") d.setDate(d.getDate() - 1);
        else if (this.viewType === "week") d.setDate(d.getDate() - 7);
        else d.setMonth(d.getMonth() - 1);
        this.currentDate = _toYMD(d);
        this.loadAppointments();
        this.refreshQuickStats();
      },

      next() {
        const d = _parseYMD(this.currentDate);
        if (this.viewType === "day") d.setDate(d.getDate() + 1);
        else if (this.viewType === "week") d.setDate(d.getDate() + 7);
        else d.setMonth(d.getMonth() + 1);
        this.currentDate = _toYMD(d);
        this.loadAppointments();
        this.refreshQuickStats();
      },

      // search debounce
      debounceSearch() {
        clearTimeout(this._debounceTimer);
        this._debounceTimer = setTimeout(() => {
          this.applyClientSearch();
        }, 180);
      },

      applyClientSearch() {
        const q = String(this.searchQuery || "").trim().toLowerCase();
        if (!q) {
          this.appointments = this._allAppointments.slice();
          return;
        }

        const match = (a, key) => String((a && a[key]) ? a[key] : "").toLowerCase().includes(q);

        this.appointments = this._allAppointments.filter(a =>
          match(a, "patient_name") ||
          match(a, "owner_full_name") ||
          match(a, "therapist_first_name") ||
          match(a, "therapist_last_name") ||
          match(a, "treatment_focus") ||
          match(a, "notes") ||
          match(a, "status") ||
          match(a, "type")
        );
      },

      // compute range for view api
      _getRange() {
        const base = _parseYMD(this.currentDate);
        let from, to;

        if (this.viewType === "day") {
          from = new Date(base);
          to = new Date(base);
        } else if (this.viewType === "week") {
          from = _startOfWeekISO(base);
          to = _endOfWeekISO(base);
        } else {
          from = _startOfMonth(base);
          to = _endOfMonth(base);
        }

        return { date_from: _toYMD(from), date_to: _toYMD(to) };
      },

      // GLOBAL Quick Stats: always for "today" / "current ISO week" / pending global / cancelled last 365 days
      async refreshQuickStats() {
        this.statsLoading = true;
        this.lastStatsError = "";

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const fromStats = new Date(today);
        fromStats.setDate(fromStats.getDate() - 365);

        const toStats = new Date(today);
        toStats.setDate(toStats.getDate() + 365);

        const params = {
          action: "list",
          date_from: _toYMD(fromStats),
          date_to: _toYMD(toStats),
          limit: 500,
          offset: 0
        };

        try {
          const data = await _api.get(params);
          if (!data || data.status !== "success") {
            throw new Error((data && data.message) ? data.message : "Stats konnten nicht geladen werden");
          }

          const items = Array.isArray(data.items)
            ? data.items
            : (data.data && Array.isArray(data.data.items) ? data.data.items : []);

          const normalized = items.map(a => {
            const duration = parseInt(a.duration || 60, 10) || 60;
            const start_time = _normalizeTime(a.start_time);
            const end_time = _normalizeTime(a.end_time) || _calcEndTime(start_time, duration);
            const statusKey = _statusKey(a.status);
            const typeKey = _typeKey(a.type);

            return {
              ...a,
              duration,
              start_time,
              end_time,
              calc_end_time: end_time,
              appointment_date: a.appointment_date || a.date || null,
              status: statusKey,
              type: typeKey
            };
          });

          const todayYMD = _toYMD(today);
          const weekStart = _startOfWeekISO(today);
          const weekEnd = _endOfWeekISO(today);

          let todayCount = 0;
          let weekCount = 0;
          let pendingCount = 0;
          let cancelledCount = 0;

          for (let i = 0; i < normalized.length; i++) {
            const a = normalized[i];
            const ad = a.appointment_date ? _parseYMD(a.appointment_date) : null;
            if (!ad) continue;

            // Heute
            if (a.appointment_date === todayYMD) {
              todayCount++;
            }

            // Diese Woche (immer aktuelle ISO-Woche)
            const ad0 = new Date(ad.getFullYear(), ad.getMonth(), ad.getDate());
            ad0.setHours(0, 0, 0, 0);
            if (ad0 >= weekStart && ad0 <= weekEnd) {
              weekCount++;
            }

            // Wartend: pending + Termin ist heute oder in Zukunft (ausstehende Bestätigung)
            if (a.status === "pending") {
              if (ad0 >= today) pendingCount++;
            }

            // Abgesagt: cancelled in den letzten 365 Tagen (bezogen auf heute)
            if (a.status === "cancelled") {
              const lower = new Date(today);
              lower.setDate(lower.getDate() - 365);
              lower.setHours(0, 0, 0, 0);
              if (ad0 >= lower && ad0 <= today) cancelledCount++;
            }
          }

          this.todayCount = todayCount;
          this.weekCount = weekCount;
          this.pendingCount = pendingCount;
          this.cancelledCount = cancelledCount;

          this.lastStatsOkAt = new Date().toLocaleString("de-DE");
        } catch (err) {
          this.todayCount = 0;
          this.weekCount = 0;
          this.pendingCount = 0;
          this.cancelledCount = 0;

          this.lastStatsError = String(err && err.message ? err.message : err);

          const now = Date.now();
          if (now - this._lastToastAt > 1200) {
            this._lastToastAt = now;
            _toast("Fehler beim Laden der Quick-Stats: " + this.lastStatsError, "error");
          }
        } finally {
          this.statsLoading = false;
        }
      },

      // load appointments (view)
      async loadAppointments() {
        if (this._controller) this._controller.abort();
        this._controller = new AbortController();

        const range = this._getRange();
        const params = {
          action: "list",
          date_from: range.date_from,
          date_to: range.date_to,
          limit: 500,
          offset: 0
        };

        if (this.statusFilter) params.status = this.statusFilter;
        if (parseInt(this.therapistFilter || 0, 10) > 0) params.therapist_id = parseInt(this.therapistFilter, 10);

        this.loading = true;

        try {
          const data = await _api.get(params, this._controller.signal);

          if (!data || data.status !== "success") {
            throw new Error((data && data.message) ? data.message : "Fehler beim Laden der Termine");
          }

          const items = Array.isArray(data.items)
            ? data.items
            : (data.data && Array.isArray(data.data.items) ? data.data.items : []);

          const normalized = items.map(a => {
            const duration = parseInt(a.duration || 60, 10) || 60;
            const start_time = _normalizeTime(a.start_time);
            const end_time = _normalizeTime(a.end_time) || _calcEndTime(start_time, duration);

            return {
              ...a,
              duration,
              start_time,
              end_time,
              calc_end_time: end_time,
              appointment_date: a.appointment_date || a.date || null,
              status: _statusKey(a.status),
              type: _typeKey(a.type)
            };
          });

          this._allAppointments = normalized;
          this.appointments = normalized.slice();
          this.applyClientSearch();

          this.lastApiOkAt = new Date().toLocaleString("de-DE");
          this.lastApiError = "";
        } catch (err) {
          if (_isAbortError(err)) return;

          const now = Date.now();
          if (now - this._lastToastAt > 1200) {
            this._lastToastAt = now;
            _toast("Fehler beim Laden der Termine: " + (err && err.message ? err.message : err), "error");
          }

          this.lastApiError = String(err && err.message ? err.message : err);

          this._allAppointments = [];
          this.appointments = [];
        } finally {
          this.loading = false;
        }
      },

      // modal helpers
      resetForm() {
        // ✅ WICHTIG: Standard = pending (Wartend), weil auf Bestätigung gewartet wird
        this.currentAppointment = {
          id: null,
          patient_id: 0,
          therapist_id: parseInt(this.therapistFilter || 1, 10) || 1,
          appointment_date: this.currentDate,
          start_time: "09:00",
          duration: 60,
          type: "treatment",
          status: "pending",
          treatment_focus: "",
          notes: ""
        };
      },

      openCreateModal() {
        this.modalMode = "create";
        this.resetForm();
        this.showModal = true;
      },

      openEditModal(a) {
        this.modalMode = "edit";
        this.currentAppointment = {
          id: a.id,
          patient_id: parseInt(a.patient_id || 0, 10) || 0,
          therapist_id: parseInt(a.therapist_id || 1, 10) || 1,
          appointment_date: a.appointment_date || this.currentDate,
          start_time: _formatTime(a.start_time) || "09:00",
          duration: parseInt(a.duration || 60, 10) || 60,
          type: _typeKey(a.type || "treatment"),
          status: _statusKey(a.status || "pending"),
          treatment_focus: a.treatment_focus || "",
          notes: a.notes || ""
        };
        this.showModal = true;
      },

      closeModal() {
        this.showModal = false;
      },

      async saveAppointment() {
        try {
          const a = this.currentAppointment || {};
          if (!a.patient_id || !a.appointment_date || !a.start_time) {
            _toast("Bitte Patient, Datum und Startzeit ausfüllen.", "error");
            return;
          }

          const payload = {
            patient_id: parseInt(a.patient_id, 10),
            therapist_id: parseInt(a.therapist_id || 1, 10),
            appointment_date: a.appointment_date,
            start_time: _formatTime(a.start_time),
            duration: parseInt(a.duration || 60, 10) || 60,
            type: _typeKey(a.type || "treatment"),
            status: _statusKey(a.status || "pending"),
            treatment_focus: a.treatment_focus ? String(a.treatment_focus) : null,
            notes: a.notes ? String(a.notes) : null
          };

          let data;
          if (this.modalMode === "edit") {
            payload.id = parseInt(a.id, 10);
            data = await _api.post("update", payload);
          } else {
            data = await _api.post("create", payload);
          }

          if (!data || data.status !== "success") {
            throw new Error((data && data.message) ? data.message : "Speichern fehlgeschlagen");
          }

          _toast(this.modalMode === "edit" ? "Termin aktualisiert" : "Termin erstellt", "success");
          this.closeModal();

          await this.loadAppointments();
          await this.refreshQuickStats();
        } catch (err) {
          _toast("Fehler beim Speichern: " + (err && err.message ? err.message : err), "error");
        }
      },

      async deleteAppointment(a) {
        if (!a || !a.id) return;

        const label = `${_formatDateDE(a.appointment_date)} ${_formatTime(a.start_time)} – ${a.patient_name || "Termin"}`;
        if (!confirm(`Termin wirklich löschen?\n\n${label}`)) return;

        try {
          const data = await _api.post("delete", { id: parseInt(a.id, 10) });
          if (!data || data.status !== "success") {
            throw new Error((data && data.message) ? data.message : "Löschen fehlgeschlagen");
          }

          _toast("Termin gelöscht", "success");
          await this.loadAppointments();
          await this.refreshQuickStats();
        } catch (err) {
          _toast("Fehler beim Löschen: " + (err && err.message ? err.message : err), "error");
        }
      }
    };
  }

  // -----------------------------
  // Expose globally for x-data="appointmentsManager()"
  // -----------------------------
  window.appointmentsManager = appointmentsManager;

  // -----------------------------
  // FIX: Wenn Alpine bereits initialisiert hat, aber Script später kommt
  // -----------------------------
  (function ensureAlpineInitializedForAppointments() {
    const root = _getRoot();
    if (!root) return;

    const hasAlpine = !!window.Alpine;
    if (!hasAlpine) return;

    try {
      if (typeof window.Alpine.data === "function") {
        if (!window.__tpAppointmentsAlpineDataRegistered) {
          window.__tpAppointmentsAlpineDataRegistered = true;
          window.Alpine.data("appointmentsManager", appointmentsManager);
        }
      }
    } catch (_) {}

    try {
      if (!root.__x && typeof window.Alpine.initTree === "function") {
        window.Alpine.initTree(root);
      }
    } catch (_) {}
  })();

})();