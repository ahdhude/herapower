/* Herahakatha front end: small Alpine components, one per screen. */
(function () {
  'use strict';

  var HH = window.HH || (window.HH = {});

  // ---------------------------------------------------------------- utilities ------
  var U = {
    uuid: function () {
      if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
      var b = new Uint8Array(16);
      (window.crypto || window.msCrypto).getRandomValues(b);
      b[6] = (b[6] & 0x0f) | 0x40;
      b[8] = (b[8] & 0x3f) | 0x80;
      var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
      return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    },
    url: function (p) { return (HH.base || '') + p; },
    num: function (v) {
      if (v === null || v === undefined) return null;
      var s = String(v).trim();
      if (s === '' || !/^\d+(\.\d+)?$/.test(s)) return null;
      return parseFloat(s);
    },
    fmt: function (n, dp) {
      if (n === null || n === undefined || isNaN(n)) return '—';
      dp = dp === undefined ? 1 : dp;
      return Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: dp });
    },
    /** Naive "site local" timestamp 'YYYY-MM-DD HH:MM' → ms (treated as UTC so only differences matter). */
    naive: function (s) {
      var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s);
      return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : NaN;
    },
    addDays: function (date, n) {
      var d = new Date(U.naive(date + ' 00:00'));
      d.setUTCDate(d.getUTCDate() + n);
      return d.toISOString().slice(0, 10);
    },
    dateLabel: function (date, today) {
      var d = new Date(U.naive(date + ' 00:00'));
      var s = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
      if (date === today) return 'Today · ' + s;
      if (date === U.addDays(today, -1)) return 'Yesterday · ' + s;
      return s;
    },
    toast: function (text, type) {
      window.dispatchEvent(new CustomEvent('hh:toast', { detail: { text: text, type: type || 'ok' } }));
    },
    get: function (path) {
      return fetch(U.url(path), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (res) {
        if (res.status === 401) { window.dispatchEvent(new Event('hh:auth')); throw new Error('auth'); }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      });
    },
    cache: {
      read: function (key) { try { var r = localStorage.getItem(key); return r ? JSON.parse(r) : null; } catch (e) { return null; } },
      write: function (key, val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) { /* ignore */ } }
    }
  };
  HH.u = U;

  // ------------------------------------------------------- theme + clock (no Alpine) ---
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-theme-toggle]');
    if (!t) return;
    var cur = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    var next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    try { localStorage.setItem('hh.theme', next); } catch (err) { /* ignore */ }
  });

  // Signing out must not leave this person's pages in the offline cache for the next user.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.action && /\/logout$/.test(f.action) && navigator.serviceWorker && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage('clear-pages');
    }
  });

  function tickClock() {
    var t = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: HH.tz || 'Indian/Maldives' });
    document.querySelectorAll('[data-clock]').forEach(function (el) { el.textContent = t; });
  }
  setInterval(tickClock, 20000);

  // ---------------------------------------------------------------- Alpine data ------
  document.addEventListener('alpine:init', function () {

    // ---- chrome: sync pill, toasts, auth prompt ----
    Alpine.data('syncPill', function () {
      return {
        count: 0,
        online: navigator.onLine,
        init: function () {
          var self = this;
          this.count = window.HQ ? HQ.count() : 0;
          window.addEventListener('hh:queue', function (e) { self.count = e.detail.length; self.online = navigator.onLine; });
          window.addEventListener('online', function () { self.online = true; });
          window.addEventListener('offline', function () { self.online = false; });
        },
        flush: function () { if (window.HQ) HQ.flush(); }
      };
    });

    Alpine.data('toasts', function () {
      return {
        items: [],
        init: function () {
          var self = this;
          window.addEventListener('hh:toast', function (e) {
            var t = { id: Date.now() + Math.random(), text: e.detail.text, type: e.detail.type };
            self.items.push(t);
            setTimeout(function () { self.items = self.items.filter(function (x) { return x.id !== t.id; }); }, 3200);
          });
        }
      };
    });

    Alpine.data('authPrompt', function () {
      return {
        open: false,
        init: function () {
          var self = this;
          window.addEventListener('hh:auth', function () { self.open = true; });
        },
        retry: function () {
          var self = this;
          HQ.refreshCsrf().then(function (ok) {
            if (ok) { self.open = false; HQ.flush(); U.toast('Signed in — sending waiting readings'); }
            else { U.toast('Still signed out', 'bad'); }
          });
        }
      };
    });

    // ---- Today ----
    Alpine.data('todayPage', function (init) {
      return {
        progress: init.progress,
        review: init.review,
        canEnter: init.canEnter,
        canApprove: init.canApprove,
        queue: [],
        online: navigator.onLine,
        updated: new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: HH.tz }),
        timer: null,
        url: U.url,
        init: function () {
          var self = this;
          this.queue = window.HQ ? HQ.items() : [];
          window.addEventListener('hh:queue', function (e) { self.queue = e.detail; });
          window.addEventListener('hh:saved', function () { self.refresh(); });
          window.addEventListener('online', function () { self.online = true; self.refresh(); });
          window.addEventListener('offline', function () { self.online = false; });
          this.timer = setInterval(function () { self.refresh(); }, 60000);
          document.addEventListener('visibilitychange', function () { if (!document.hidden) self.refresh(); });
        },
        refresh: function () {
          var self = this;
          if (!navigator.onLine) return;
          U.get('/api/today').then(function (d) {
            self.progress = d;
            self.updated = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: HH.tz });
          }).catch(function () { /* keep showing what we have */ });
        },
        get next() {
          var logs = this.progress.logs;
          return logs.find(function (l) { return l.due_open; }) || logs.find(function (l) { return l.state === 'missed'; }) || null;
        },
        nextHint: function (l) {
          if (l.due_open) return l.due + ' reading is due · ' + l.detail;
          if (l.kind === 'daily') return 'Not done yet today · ' + l.summary;
          return l.missed + ' earlier ' + l.unit + ' still missing · ' + l.detail;
        },
        pill: function (l) {
          if (l.state === 'complete') return { tone: 'ok', text: 'Complete' };
          if (l.state === 'missed') return { tone: 'warn', text: 'Missed' };
          if (l.state === 'due') return { tone: 'info', text: l.due_open ? 'Due ' + l.due : 'In progress' };
          if (l.state === 'idle') return { tone: 'muted', text: 'None running' };
          return { tone: 'ok', text: l.kind === 'genset' ? 'Running' : 'On track' };
        },
        pct: function (l) { return l.total ? Math.round(l.done / l.total * 100) : 0; },
        approve: function (l) {
          var self = this;
          HQ.post('/api/day-status', { date: this.review.date, group_ids: l.approve_groups }).then(function (r) {
            if (r.ok && r.data.status === 'saved') {
              l.approved = { by: HH.user };
              U.toast(l.title + ' approved');
            } else {
              U.toast((r.data && r.data.message) || 'Could not approve', 'bad');
            }
          }).catch(function () { U.toast('You appear to be offline', 'bad'); });
        }
      };
    });

    // ---- shared pieces for the three entry screens ----
    var cellProto = {
      stateIcon: function (c) {
        return { saved: 'bi-check-circle-fill', saving: 'bi-arrow-repeat', queued: 'bi-hourglass-split', warning: 'bi-exclamation-triangle-fill', error: 'bi-x-octagon-fill', dirty: 'bi-pencil', idle: 'bi-circle' }[c.state] || 'bi-circle';
      },
      stateText: function (c) {
        return { saved: 'Saved', saving: 'Saving…', queued: 'Waiting to send', warning: 'Check this value', error: 'Not saved', dirty: 'Not saved yet', idle: 'Empty' }[c.state] || '';
      }
    };

    function newCell(mid, s, sv) {
      return {
        mid: mid, s: s,
        value: sv ? sv.value : '', saved: sv ? sv.value : null,
        state: sv ? 'saved' : 'idle', msgs: [], note: '', reason: '',
        late: sv ? !!sv.late : false, by: sv ? sv.by : null, codes: sv ? sv.codes : null
      };
    }
    var BLANK = newCell(0, '', null);

    /** Like Object.assign but keeps getters live (assign would evaluate them once). */
    function mix(target, src) {
      return Object.defineProperties(target, Object.getOwnPropertyDescriptors(src));
    }

    /** Methods shared by interval + daily: typing, saving, issue actions. */
    function entryBase() {
      return {
        stateIcon: cellProto.stateIcon,
        stateText: cellProto.stateText,
        fmt: U.fmt,

        touch: function (c) {
          if (c.state === 'saving') return;
          var v = String(c.value == null ? '' : c.value).trim();
          c.state = v === '' ? (c.saved !== null ? 'saved' : 'idle') : (v === c.saved ? 'saved' : 'dirty');
          c.msgs = [];
        },
        commit: function (c) {
          if (c.state === 'saving' || c.state === 'warning' || c.state === 'error') return;
          var v = String(c.value == null ? '' : c.value).trim();
          if (v === '') { if (c.saved !== null) { c.value = c.saved; c.state = 'saved'; } return; }
          if (v === c.saved && c.state === 'saved') return;
          this.save(c);
        },
        fix: function (c) { c.state = 'dirty'; c.msgs = []; this.focusCell(c); },
        saveAnyway: function (c) { return this.save(c, { force: true }); },
        override: function (c) { return this.save(c, { force: true, override: true }); },
        retryReason: function (c) { return this.save(c, { force: true }); },

        applyResult: function (c, r) {
          if (r.queued) { c.state = 'queued'; return; }
          var d = r.data || {};
          if (d.status === 'saved') {
            c.value = d.reading.value; c.saved = d.reading.value; c.state = 'saved';
            c.late = !!d.reading.is_late; c.codes = d.reading.warning_codes; c.by = HH.user;
            c.msgs = []; c.note = ''; c.reason = '';
            if (this.afterSave) this.afterSave(c);
          } else if (d.status === 'warning') {
            c.state = 'warning'; c.msgs = d.warnings || [];
          } else {
            c.state = 'error';
            c.msgs = (d.errors && d.errors.length) ? d.errors : [{ message: d.message || 'Could not save. Try again.' }];
          }
        },
        buildPayload: function (c, opts, readingAt) {
          var p = { meter_id: c.mid, reading_at: readingAt, value: String(c.value).trim(), client_uuid: U.uuid() };
          if (opts.force) p.force = true;
          if (c.note) p.note = c.note;
          if (opts.override) { p.override = true; p.reason = c.reason || 'Meter replaced/reset'; }
          else if (c.reason) p.reason = c.reason;
          return p;
        }
      };
    }

    // ---- Feeder + solar entry (phone list / PC grid) ----
    Alpine.data('intervalEntry', function (cfg) {
      var chart = null; // kept out of Alpine's reactive proxy on purpose
      var base = entryBase();

      return mix(base, {
        cfg: cfg,
        date: cfg.date,
        slot: cfg.slot,
        loading: true, loadError: '', fromCache: false, wide: false,
        meters: [], slots: [], cells: {}, prevDayLast: {}, due: null, offsetMs: 0, other: null,

        init: function () {
          var self = this;
          var mq = window.matchMedia('(min-width: 992px)');
          this.wide = mq.matches;
          mq.addEventListener('change', function (e) { self.wide = e.matches; self.$nextTick(function () { self.scrollChip(); self.renderChart(); }); });
          window.addEventListener('hh:saved', function (e) { self.onQueueSaved(e.detail); });
          return this.load();
        },

        load: function () {
          var self = this;
          var key = 'hh.grid.' + cfg.groupId + '.' + this.date;
          this.loading = true; this.loadError = ''; this.fromCache = false;
          return U.get('/api/grid?group_id=' + cfg.groupId + '&date=' + this.date).then(function (data) {
            U.cache.write(key, data);
            return data;
          }).catch(function () {
            var cached = U.cache.read(key);
            if (cached) { self.fromCache = true; return cached; }
            self.loadError = 'Could not load this log. Check the connection and reload the page.';
            return null;
          }).then(function (data) {
            if (data) self.build(data);
            self.loading = false;
            if (data && cfg.sumCheck) self.loadOther();
            self.$nextTick(function () { self.renderChart(); self.scrollChip(); });
          });
        },

        build: function (data) {
          this.meters = data.meters; this.slots = data.slots; this.due = data.due; this.prevDayLast = data.prev_day_last || {};
          this.offsetMs = U.naive(data.now) - Date.now();
          if (!this.slot || this.slots.indexOf(this.slot) < 0) {
            this.slot = (this.due && this.slots.indexOf(this.due) >= 0) ? this.due : this.slots[0];
          }
          var cells = {};
          for (var i = 0; i < this.meters.length; i++) {
            var m = this.meters[i];
            cells[m.id] = {};
            for (var j = 0; j < this.slots.length; j++) {
              var s = this.slots[j];
              cells[m.id][s] = newCell(m.id, s, (data.cells[m.id] || {})[s]);
            }
          }
          // values typed offline and still waiting
          var q = window.HQ ? HQ.items() : [];
          for (var k = 0; k < q.length; k++) {
            var it = q[k];
            if (it.kind !== 'reading' || it.state !== 'waiting') continue;
            var parts = it.payload.reading_at.split(' ');
            var c = cells[it.payload.meter_id] && cells[it.payload.meter_id][parts[1]];
            if (parts[0] === this.date && c) { c.value = it.payload.value; c.state = 'queued'; }
          }
          this.cells = cells;
        },

        loadOther: function () {
          var self = this;
          var otherId = (cfg.feederGroups || []).filter(function (g) { return g !== cfg.groupId; })[0];
          if (!otherId) return;
          U.get('/api/grid?group_id=' + otherId + '&date=' + this.date).then(function (d) { self.other = d; }).catch(function () { self.other = null; });
        },

        // ---- cells ----
        cell: function (mid, s) { return (this.cells[mid] && this.cells[mid][s]) || BLANK; },
        meterName: function (mid) { var m = this.meters.find(function (x) { return x.id === mid; }); return m ? m.name : ''; },
        numeric: function (c) { return (c.state === 'saved' || c.state === 'queued') ? U.num(c.value) : null; },
        prevOf: function (mid, s) {
          var i = this.slots.indexOf(s);
          if (i > 0) { var p = this.cell(mid, this.slots[i - 1]); return p.saved !== null ? p.saved : (p.state === 'queued' ? p.value : null); }
          return this.prevDayLast[mid] !== undefined ? this.prevDayLast[mid] : null;
        },

        save: function (c, opts) {
          opts = opts || {};
          var v = String(c.value == null ? '' : c.value).trim();
          if (!v) return Promise.resolve();
          var self = this;
          c.state = 'saving'; c.msgs = [];
          var payload = this.buildPayload(c, opts, this.date + ' ' + c.s);
          return HQ.send('reading', payload, this.meterName(c.mid) + ' · ' + c.s).then(function (r) { self.applyResult(c, r); });
        },

        afterSave: function () { this.renderChart(); },

        onQueueSaved: function (d) {
          if (d.kind !== 'reading') return;
          var parts = d.payload.reading_at.split(' ');
          if (parts[0] !== this.date) return;
          var c = this.cell(d.payload.meter_id, parts[1]);
          if (c === BLANK) return;
          if (d.data && d.data.reading) { c.value = d.data.reading.value; c.saved = d.data.reading.value; c.state = 'saved'; c.by = HH.user; }
          this.renderChart();
        },

        // ---- state of slots ----
        nowMs: function () { return Date.now() + this.offsetMs; },
        isFuture: function (s) { return this.date === cfg.today && U.naive(this.date + ' ' + s) > this.nowMs() + 10 * 60000; },
        slotDone: function (s) {
          if (!this.meters.length) return false;
          for (var i = 0; i < this.meters.length; i++) {
            var c = this.cell(this.meters[i].id, s);
            if (c.state !== 'saved' && c.state !== 'queued') return false;
          }
          return true;
        },
        isMissed: function (s) {
          if (this.slotDone(s)) return false;
          if (this.date < cfg.today) return true;
          return this.date === cfg.today && this.due !== null && s < this.due;
        },
        get completeSlots() { var n = 0; for (var i = 0; i < this.slots.length; i++) if (this.slotDone(this.slots[i])) n++; return n; },
        get progressPct() { return this.slots.length ? Math.round(this.completeSlots / this.slots.length * 100) : 0; },
        get slotIndex() { return this.slots.indexOf(this.slot); },
        /** Days earlier than this person may change (and everything, for a viewer) open read-only. */
        get readOnly() { return cfg.editFrom === null || this.date < cfg.editFrom; },
        get dateLabel() { return U.dateLabel(this.date, cfg.today); },
        get slotNote() {
          if (this.isFuture(this.slot)) return 'Not due yet';
          if (this.readOnly) return this.slotDone(this.slot) ? 'Complete' : 'No reading recorded';
          if (this.slotDone(this.slot)) return 'Complete';
          if (this.slot === this.due) return 'Due now';
          if (this.isMissed(this.slot)) return 'Missed — enter late readings now';
          return cfg.kind === 'solar' ? 'Total plant kW from the inverter display' : 'Instantaneous kW on the hour';
        },
        chipLabel: function (s) { return cfg.intervalMin >= 60 ? s.slice(0, 2) : s; },

        // ---- phone navigation ----
        step: function (n) { var i = this.slotIndex + n; if (i >= 0 && i < this.slots.length) this.goSlot(this.slots[i]); },
        goSlot: function (s) {
          this.slot = s;
          this.syncUrl();
          var self = this;
          this.$nextTick(function () { self.scrollChip(); var first = document.querySelector('.hh-input'); if (first && !first.disabled && window.matchMedia('(pointer:coarse)').matches === false) first.focus(); });
        },
        scrollChip: function () {
          var el = document.querySelector('.hh-strip__chip.is-current');
          if (el && el.scrollIntoView) el.scrollIntoView({ inline: 'center', block: 'nearest' });
        },
        enter: function (e, idx) {
          e.target.blur();
          var next = document.querySelector('.hh-input[data-idx="' + (idx + 1) + '"]');
          if (next && !next.disabled) { next.focus(); next.select && next.select(); }
        },
        focusCell: function (c) {
          var self = this;
          this.$nextTick(function () {
            var el = self.wide
              ? document.querySelector('.hh-cell[data-r="' + self.slots.indexOf(c.s) + '"][data-c="' + self.meters.findIndex(function (m) { return m.id === c.mid; }) + '"]')
              : document.getElementById('f' + c.mid);
            if (el) { el.focus(); el.select && el.select(); }
          });
        },

        // ---- date ----
        shiftDay: function (n) {
          var d = U.addDays(this.date, n);
          if (d < cfg.minDate || d > cfg.today) return;
          this.date = d; this.changeDate();
        },
        changeDate: function () {
          if (!this.date || this.date > cfg.today) this.date = cfg.today;
          if (this.date < cfg.minDate) this.date = cfg.minDate;
          this.slot = this.date === cfg.today ? cfg.slot : '';
          this.syncUrl();
          return this.load();
        },
        syncUrl: function () {
          var q = new URLSearchParams(location.search);
          q.set('date', this.date);
          q.set(cfg.kind === 'solar' ? 'slot' : 'hour', this.slot || '');
          history.replaceState(null, '', location.pathname + '?' + q.toString());
        },
        tabUrl: function (id) { return U.url('/entry/feeders/' + id + '?date=' + this.date); },

        // ---- totals ----
        slotTotal: function (s) {
          var t = 0, any = false;
          for (var i = 0; i < this.meters.length; i++) {
            if (this.meters[i].is_incomer) continue;
            var v = this.numeric(this.cell(this.meters[i].id, s));
            if (v !== null) { t += v; any = true; }
          }
          return any ? t : null;
        },
        colTotal: function (mid) {
          var t = 0;
          for (var i = 0; i < this.slots.length; i++) { var v = this.numeric(this.cell(mid, this.slots[i])); if (v !== null) t += v; }
          return t;
        },
        gridKwh: function () {
          var t = 0;
          for (var i = 0; i < this.meters.length; i++) if (!this.meters[i].is_incomer) t += this.colTotal(this.meters[i].id);
          return t * cfg.intervalMin / 60;
        },
        get sumInfo() {
          if (!cfg.sumCheck) return null;
          var hv = null, sum = this.slotTotal(this.slot) || 0, s = this.slot, i;
          for (i = 0; i < this.meters.length; i++) if (this.meters[i].is_incomer) hv = this.numeric(this.cell(this.meters[i].id, s));
          if (this.other) {
            for (i = 0; i < this.other.meters.length; i++) {
              var om = this.other.meters[i], oc = (this.other.cells[om.id] || {})[s];
              var ov = oc ? U.num(oc.value) : null;
              if (ov === null) continue;
              if (om.is_incomer) hv = ov; else sum += ov;
            }
          }
          if (hv === null || !sum) return null;
          return { hv: hv, sum: sum, off: Math.abs(hv - sum) / hv * 100 > cfg.sumTolerance };
        },

        // ---- PC grid: keyboard + paste ----
        issues: function () {
          var out = [];
          for (var i = 0; i < this.meters.length; i++) {
            for (var j = 0; j < this.slots.length; j++) {
              var c = this.cell(this.meters[i].id, this.slots[j]);
              if (c.state === 'warning' || c.state === 'error') out.push(c);
            }
          }
          return out;
        },
        focusGrid: function (r, c) {
          var el = document.querySelector('.hh-cell[data-r="' + r + '"][data-c="' + c + '"]');
          if (!el) return false;
          if (el.disabled) return false;
          el.focus(); el.select();
          return true;
        },
        gridKey: function (e, r, c) {
          var el = e.target, k = e.key;
          if (k === 'ArrowDown' || k === 'Enter') { e.preventDefault(); if (k === 'Enter') el.blur(); this.focusGrid(r + 1, c); }
          else if (k === 'ArrowUp') { e.preventDefault(); this.focusGrid(r - 1, c); }
          else if (k === 'ArrowLeft' && el.selectionStart === 0 && el.selectionEnd === 0) { e.preventDefault(); this.focusGrid(r, c - 1); }
          else if (k === 'ArrowRight' && el.selectionStart === el.value.length) { e.preventDefault(); this.focusGrid(r, c + 1); }
        },
        paste: function (e, r0, c0) {
          var text = (e.clipboardData || window.clipboardData).getData('text');
          if (text.indexOf('\t') < 0 && text.indexOf('\n') < 0) return; // a single value pastes normally
          e.preventDefault();
          var rows = text.replace(/\r/g, '').replace(/\n+$/, '').split('\n').map(function (l) { return l.split('\t'); });
          var self = this, jobs = [], n = 0;
          rows.forEach(function (row, ri) {
            row.forEach(function (val, ci) {
              var s = self.slots[r0 + ri], m = self.meters[c0 + ci];
              if (!s || !m || self.isFuture(s)) return;
              var v = val.trim();
              if (v === '') return;
              var c = self.cell(m.id, s);
              c.value = v; c.state = 'dirty'; n++;
              jobs.push(c);
            });
          });
          var chain = Promise.resolve();
          jobs.forEach(function (c) { chain = chain.then(function () { return self.save(c); }); });
          chain.then(function () {
            var bad = self.issues().length;
            U.toast('Pasted ' + n + ' value' + (n === 1 ? '' : 's') + (bad ? ' · ' + bad + ' need a look' : ''), bad ? 'warn' : 'ok');
          });
        },

        // ---- solar chart ----
        renderChart: function () {
          if (cfg.kind !== 'solar' || !window.Chart) return;
          var el = document.getElementById('solarChart');
          if (!el || !this.meters.length) return;
          var self = this;
          var palette = ['#f59e0b', '#10b981', '#6366f1', '#ef4444'];
          var datasets = this.meters.map(function (m, i) {
            return {
              label: m.name,
              data: self.slots.map(function (s) { return self.numeric(self.cell(m.id, s)); }),
              borderColor: palette[i % palette.length], backgroundColor: palette[i % palette.length] + '26',
              fill: true, tension: 0.35, spanGaps: false, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2
            };
          });
          if (chart && chart.canvas !== el) { chart.destroy(); chart = null; }
          if (chart) { chart.data.labels = this.slots; chart.data.datasets = datasets; chart.update(); return; }
          var style = getComputedStyle(document.documentElement);
          Chart.defaults.color = style.getPropertyValue('--hh-muted').trim() || '#64748b';
          Chart.defaults.font.family = style.getPropertyValue('--hh-font').trim() || 'system-ui';
          chart = new Chart(el, {
            type: 'line',
            data: { labels: this.slots, datasets: datasets },
            options: {
              responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
              plugins: { legend: { display: datasets.length > 1, position: 'bottom' }, tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + U.fmt(ctx.parsed.y, 1) + ' kW'; } } } },
              scales: { y: { beginAtZero: true, title: { display: true, text: 'kW' }, grid: { color: 'rgba(148,163,184,.18)' } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 9 } } }
            }
          });
        }
      });
    });

    // ---- Daily meters ----
    Alpine.data('dailyEntry', function (cfg) {
      var base = entryBase();
      return mix(base, {
        cfg: cfg, date: cfg.date, groups: [], cells: {}, loading: true, loadError: '', fromCache: false,

        init: function () {
          var self = this;
          window.addEventListener('hh:saved', function (e) { self.onQueueSaved(e.detail); });
          return this.load();
        },
        yesterdayOf: function (d) { return U.addDays(d, -1); },

        load: function () {
          var self = this;
          var key = 'hh.daily.' + this.date;
          this.loading = true; this.loadError = ''; this.fromCache = false;
          return U.get('/api/daily?date=' + this.date).then(function (d) { U.cache.write(key, d); return d; }).catch(function () {
            var cached = U.cache.read(key);
            if (cached) { self.fromCache = true; return cached; }
            self.loadError = 'Could not load the daily meters. Check the connection and reload the page.';
            return null;
          }).then(function (data) {
            if (data) self.build(data);
            self.loading = false;
          });
        },
        build: function (data) {
          this.groups = data.groups;
          var cells = {};
          data.groups.forEach(function (g) {
            g.meters.forEach(function (m) {
              cells[m.id] = newCell(m.id, '00:00', m.value !== null ? { value: m.value, late: false, by: null, codes: m.warning } : null);
            });
          });
          var q = window.HQ ? HQ.items() : [];
          for (var i = 0; i < q.length; i++) {
            var it = q[i];
            if (it.kind === 'reading' && it.state === 'waiting' && it.payload.reading_at === this.date + ' 00:00' && cells[it.payload.meter_id]) {
              cells[it.payload.meter_id].value = it.payload.value; cells[it.payload.meter_id].state = 'queued';
            }
          }
          this.cells = cells;
        },
        cell: function (mid) { return this.cells[mid] || BLANK; },
        flat: function () { var out = []; this.groups.forEach(function (g) { g.meters.forEach(function (m) { out.push(m); }); }); return out; },
        allIndex: function (mid) { return this.flat().findIndex(function (m) { return m.id === mid; }); },
        get total() { return this.flat().length; },
        get entered() { var self = this; return this.flat().filter(function (m) { var s = self.cell(m.id).state; return s === 'saved' || s === 'queued'; }).length; },
        get progressPct() { return this.total ? Math.round(this.entered / this.total * 100) : 0; },
        /** Days earlier than this person may change (and everything, for a viewer) open read-only. */
        get readOnly() { return cfg.editFrom === null || this.date < cfg.editFrom; },
        get dateLabel() { return U.dateLabel(this.date, cfg.today); },
        groupEntered: function (g) { var self = this; return g.meters.filter(function (m) { var s = self.cell(m.id).state; return s === 'saved' || s === 'queued'; }).length; },
        groupIcon: function (g) {
          var k = g.meters[0] && g.meters[0].kind;
          return { water: 'bi-droplet', inverter: 'bi-sun', generator: 'bi-lightning-charge', fuel: 'bi-fuel-pump' }[k] || 'bi-speedometer2';
        },
        meterName: function (mid) { var m = this.flat().find(function (x) { return x.id === mid; }); return m ? m.name : ''; },

        /** Consumption for the day before `date`: (this reading - previous reading) x multiplier. */
        use: function (m) {
          var c = this.cell(m.id);
          if (m.prev_value === null || m.prev_date !== this.yesterdayOf(this.date)) return null;
          var v = (c.state === 'saved' || c.state === 'queued' || c.state === 'dirty') ? U.num(c.value) : null;
          var p = U.num(m.prev_value);
          if (v === null || p === null || v < p) return null;
          return (v - p) * m.multiplier;
        },
        fmtCounter: function (v) {
          if (v === null || v === undefined || v === '') return '—';
          var parts = String(v).split('.');
          return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (parts[1] ? '.' + parts[1] : '');
        },

        save: function (c, opts) {
          opts = opts || {};
          if (!String(c.value == null ? '' : c.value).trim()) return Promise.resolve();
          var self = this;
          c.state = 'saving'; c.msgs = [];
          var payload = this.buildPayload(c, opts, this.date + ' 00:00');
          return HQ.send('reading', payload, this.meterName(c.mid) + ' · ' + this.date).then(function (r) { self.applyResult(c, r); });
        },
        onQueueSaved: function (d) {
          if (d.kind !== 'reading' || d.payload.reading_at !== this.date + ' 00:00') return;
          var c = this.cell(d.payload.meter_id);
          if (c !== BLANK && d.data && d.data.reading) { c.value = d.data.reading.value; c.saved = d.data.reading.value; c.state = 'saved'; }
        },
        focusCell: function (c) {
          this.$nextTick(function () { var el = document.getElementById('d' + c.mid); if (el) { el.focus(); el.select && el.select(); } });
        },
        enter: function (e, idx) {
          e.target.blur();
          var next = document.querySelector('.hh-input[data-idx="' + (idx + 1) + '"]');
          if (next) { next.focus(); next.select && next.select(); }
        },
        shiftDay: function (n) {
          var d = U.addDays(this.date, n);
          if (d < cfg.minDate || d > cfg.today) return;
          this.date = d; this.changeDate();
        },
        changeDate: function () {
          if (!this.date || this.date > cfg.today) this.date = cfg.today;
          if (this.date < cfg.minDate) this.date = cfg.minDate;
          history.replaceState(null, '', location.pathname + '?date=' + this.date);
          return this.load();
        }
      });
    });

    // ---- Generator set log ----
    Alpine.data('gensetEntry', function (cfg) {
      function blankForm() {
        var f = { visual_ok: null, visual_note: '' };
        cfg.fields.forEach(function (x) { f[x.key] = ''; });
        return f;
      }
      return {
        cfg: cfg, date: cfg.date, hours: [], open: null, loading: true, loadError: '', fromCache: false,

        init: function () {
          var self = this;
          window.addEventListener('hh:saved', function (e) { if (e.detail.kind === 'genset') self.load(); });
          return this.load();
        },
        /** Days earlier than this person may change (and everything, for a viewer) open read-only. */
        get readOnly() { return cfg.editFrom === null || this.date < cfg.editFrom; },
        get dateLabel() { return U.dateLabel(this.date, cfg.today); },
        get loggedCount() { return this.hours.filter(function (h) { return h.logged; }).length; },
        genUrl: function (id) { return U.url('/entry/genset?meter=' + id + '&date=' + this.date); },

        load: function () {
          var self = this;
          var key = 'hh.genset.' + cfg.meterId + '.' + this.date;
          this.loading = true; this.loadError = ''; this.fromCache = false;
          return U.get('/api/genset?meter=' + cfg.meterId + '&date=' + this.date).then(function (d) { U.cache.write(key, d); return d; }).catch(function () {
            var cached = U.cache.read(key);
            if (cached) { self.fromCache = true; return cached; }
            self.loadError = 'Could not load the generator log. Check the connection and reload the page.';
            return null;
          }).then(function (data) {
            if (data) self.build(data);
            self.loading = false;
          });
        },
        build: function (data) {
          var queued = {};
          (window.HQ ? HQ.items() : []).forEach(function (q) { if (q.kind === 'genset' && q.payload.meter_id === cfg.meterId) queued[q.payload.log_at] = q; });
          var lastOpen = null;
          this.hours = data.slots.map(function (s) {
            var form = blankForm();
            var log = s.log;
            if (log) {
              cfg.fields.forEach(function (f) { form[f.key] = log[f.key] || ''; });
              form.visual_ok = log.visual_ok; form.visual_note = log.visual_note || '';
            }
            var q = queued[s.at];
            if (q) {
              cfg.fields.forEach(function (f) { if (q.payload[f.key] !== undefined) form[f.key] = q.payload[f.key]; });
              form.visual_ok = q.payload.visual_ok === undefined ? form.visual_ok : q.payload.visual_ok;
            }
            if (!s.future && !log && !lastOpen) lastOpen = s.at;
            return {
              at: s.at, label: s.label, future: s.future, logged: !!log, queued: !!q, by: log ? log.entered_by : null,
              alarm: !!(log && log.warning_codes && log.warning_codes.indexOf('GENSET_LIMIT') >= 0),
              form: form, dirty: false, saving: false, state: 'idle', msgs: [], bad: {}
            };
          });
          // open the latest unlogged hour that has started, else the first hour
          var started = this.hours.filter(function (h) { return !h.future; });
          var firstEmpty = started.filter(function (h) { return !h.logged; }).pop();
          this.open = firstEmpty ? firstEmpty.at : (started.length ? started[started.length - 1].at : (this.hours[0] ? this.hours[0].at : null));
        },
        toggle: function (h) { this.open = this.open === h.at ? null : h.at; },
        summary: function (h) {
          var f = h.form, out = [];
          if (f.kw) out.push(f.kw + ' kW');
          if (f.hz) out.push(f.hz + ' Hz');
          if (f.water_c) out.push(f.water_c + ' °C');
          if (f.oil_bar) out.push(f.oil_bar + ' bar');
          return out.join(' · ');
        },
        prevLogged: function (h) {
          var i = this.hours.indexOf(h);
          if (i < 1) return false;
          var p = this.hours[i - 1];
          return p.logged || p.dirty || cfg.fields.some(function (f) { return p.form[f.key] !== ''; });
        },
        copyPrev: function (h) {
          var i = this.hours.indexOf(h);
          if (i < 1) return;
          var p = this.hours[i - 1];
          cfg.fields.forEach(function (f) { h.form[f.key] = p.form[f.key]; });
          h.form.visual_ok = p.form.visual_ok;
          h.dirty = true; h.state = 'idle';
        },
        save: function (h, force) {
          var self = this;
          var payload = { meter_id: cfg.meterId, log_at: h.at, client_uuid: U.uuid(), visual_ok: h.form.visual_ok, visual_note: h.form.visual_note };
          cfg.fields.forEach(function (f) { payload[f.key] = String(h.form[f.key] || '').trim(); });
          if (force) payload.force = true;
          h.saving = true; h.state = 'idle'; h.msgs = []; h.bad = {};
          return HQ.send('genset', payload, 'Genset · ' + h.label).then(function (r) {
            h.saving = false;
            if (r.queued) { h.queued = true; h.dirty = false; U.toast('Saved on this device — will send when online', 'warn'); self.openNext(h); return; }
            var d = r.data || {};
            if (d.status === 'saved') {
              h.logged = true; h.queued = false; h.dirty = false; h.by = HH.user;
              h.alarm = !!(d.log && d.log.warning_codes && d.log.warning_codes.indexOf('GENSET_LIMIT') >= 0);
              U.toast(h.label + ' saved' + (h.alarm ? ' — flagged out of limit' : ''), h.alarm ? 'warn' : 'ok');
              self.openNext(h);
            } else if (d.status === 'warning') {
              h.state = 'warning'; h.msgs = d.warnings || [];
              h.msgs.forEach(function (m) { if (m.field) h.bad[m.field] = true; });
            } else {
              h.state = 'error'; h.msgs = d.errors || [{ message: d.message || 'Could not save.' }];
              h.msgs.forEach(function (m) { if (m.field) h.bad[m.field] = true; });
            }
          });
        },
        openNext: function (h) {
          var i = this.hours.indexOf(h);
          var n = this.hours[i + 1];
          if (n && !n.future) this.open = n.at;
        },
        shiftDay: function (n) {
          var d = U.addDays(this.date, n);
          if (d < cfg.minDate || d > cfg.today) return;
          this.date = d; this.changeDate();
        },
        changeDate: function () {
          if (!this.date || this.date > cfg.today) this.date = cfg.today;
          if (this.date < cfg.minDate) this.date = cfg.minDate;
          history.replaceState(null, '', location.pathname + '?meter=' + cfg.meterId + '&date=' + this.date);
          return this.load();
        }
      };
    });

    // ---- Import review: one card per item, resolved in place ----
    Alpine.data('importIssue', function (cfg) {
      return {
        id: cfg.id, kind: cfg.kind, suggested: cfg.suggested, status: cfg.status,
        value: '', busy: false, error: '', msg: '',
        act: function (action) {
          var self = this;
          this.busy = true; this.error = '';
          return HQ.post('/admin/import/issues/' + this.id, { action: action, value: this.value }).then(function (r) {
            self.busy = false;
            if (r.ok && r.data && r.data.ok) {
              self.status = action === 'ignore' ? 'ignored' : (action === 'accept' ? 'accepted' : 'fixed');
              self.msg = r.data.message;
            } else {
              self.error = (r.data && r.data.message) || 'Could not save. Reload the page and try again.';
            }
          }).catch(function () { self.busy = false; self.error = 'No connection. Try again when you are back online.'; });
        },
        resultText: function () {
          return { accepted: 'Applied the suggestion', fixed: 'Saved your value', ignored: this.kind === 'cell' ? 'Left empty' : 'Kept as imported' }[this.status] || this.status;
        }
      };
    });

    // ---- Table / Graph switch for ranking-style reports (horizontal bars) ----
    Alpine.data('barsView', function (cfg) {
      var chart = null;
      return {
        view: 'table',
        height: Math.max(220, cfg.labels.length * 30 + 60),
        init: function () {
          try { var v = localStorage.getItem('hh.rankview'); if (v === 'graph' || v === 'table') this.view = v; } catch (e) { /* ignore */ }
          if (new URLSearchParams(location.search).get('view') === 'graph') this.view = 'graph';
          if (this.view === 'graph') this.$nextTick(this.draw.bind(this));
        },
        show: function (v) {
          this.view = v;
          try { localStorage.setItem('hh.rankview', v); } catch (e) { /* ignore */ }
          if (v === 'graph') this.$nextTick(this.draw.bind(this));
        },
        draw: function () {
          if (chart || !window.Chart || !this.$refs.canvas) return;
          var style = getComputedStyle(document.documentElement);
          var muted = style.getPropertyValue('--hh-muted').trim() || '#64748b';
          var accent = style.getPropertyValue('--hh-accent').trim() || '#2d5bff';
          var warn = '#f59e0b', ok = '#10b981';
          Chart.defaults.color = muted;
          var colors = cfg.change.map(function (c) { return c === null ? accent : (c > 10 ? warn : (c < -10 ? ok : accent)); });
          chart = new Chart(this.$refs.canvas, {
            type: 'bar',
            data: { labels: cfg.labels, datasets: [{ data: cfg.values, backgroundColor: colors, borderRadius: 6, barThickness: 20 }] },
            options: {
              indexAxis: 'y', responsive: true, maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: { callbacks: {
                  label: function (ctx) { return U.fmt(ctx.parsed.x, 0) + ' ' + cfg.unit; },
                  afterLabel: function (ctx) {
                    var i = ctx.dataIndex, out = [];
                    if (cfg.share[i] !== null) out.push(cfg.share[i] + '% of total');
                    if (cfg.change[i] !== null) out.push((cfg.change[i] > 0 ? '+' : '') + cfg.change[i] + '% vs last month');
                    return out;
                  }
                } }
              },
              scales: {
                x: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.18)' }, title: { display: true, text: cfg.unit } },
                y: { grid: { display: false }, ticks: { autoSkip: false } }
              }
            }
          });
        }
      };
    });

    // ---- Report chart (daily solar) ----
    Alpine.data('reportChart', function (cfg) {
      return {
        init: function () {
          if (!window.Chart || !cfg.labels.length) return;
          var palette = ['#f59e0b', '#10b981', '#6366f1', '#ef4444'];
          var style = getComputedStyle(document.documentElement);
          Chart.defaults.color = style.getPropertyValue('--hh-muted').trim() || '#64748b';
          new Chart(this.$refs.canvas, {
            type: 'line',
            data: { labels: cfg.labels, datasets: cfg.series.map(function (s, i) {
              return { label: s.label, data: s.data, borderColor: palette[i % 4], backgroundColor: palette[i % 4] + '26', fill: true, tension: 0.35, pointRadius: 2, borderWidth: 2, spanGaps: false };
            }) },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.18)' } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 9 } } } }
          });
        }
      };
    });
  });

  // -------------------------------------------------- service worker (when allowed) ---
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(U.url('/sw.js')).catch(function () { /* the app still works without it */ });
    });
  }
})();
