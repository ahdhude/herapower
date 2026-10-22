/*
 * Offline queue (spec section 9).
 * A save that can't reach the server (network error, 401, 5xx) is kept in IndexedDB with its client_uuid
 * and resent on `online`, on page load and every 60 s. The server is idempotent on client_uuid.
 */
(function () {
  'use strict';

  var BASE = (window.HH && window.HH.base) || '';
  var DB_NAME = 'hh-queue';
  var STORE = 'queue';
  var URLS = { reading: '/api/readings', genset: '/api/genset-logs' };

  var items = [];
  var flushing = false;
  var seq = 1;

  // ---- storage: IndexedDB with a localStorage fallback (private windows) -------------------
  var store = (function () {
    var dbp = null;

    function open() {
      if (dbp) return dbp;
      dbp = new Promise(function (resolve, reject) {
        if (!window.indexedDB) return reject(new Error('no indexedDB'));
        var req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = function () {
          req.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        };
        req.onsuccess = function () { resolve(req.result); };
        req.onerror = function () { reject(req.error); };
      });
      return dbp;
    }

    function tx(mode, fn) {
      return open().then(function (db) {
        return new Promise(function (resolve, reject) {
          var t = db.transaction(STORE, mode);
          var out = fn(t.objectStore(STORE));
          t.oncomplete = function () { resolve(out && out.result !== undefined ? out.result : undefined); };
          t.onerror = t.onabort = function () { reject(t.error); };
        });
      });
    }

    function lsRead() {
      try { return JSON.parse(localStorage.getItem('hh.queue') || '[]'); } catch (e) { return []; }
    }
    function lsWrite(list) {
      try { localStorage.setItem('hh.queue', JSON.stringify(list)); } catch (e) { /* storage full or blocked */ }
    }

    return {
      all: function () {
        return open().then(function (db) {
          return new Promise(function (resolve, reject) {
            var r = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
            r.onsuccess = function () { resolve(r.result || []); };
            r.onerror = function () { reject(r.error); };
          });
        }).catch(lsRead);
      },
      put: function (item) {
        return tx('readwrite', function (s) { return s.put(item); }).catch(function () {
          var list = lsRead().filter(function (i) { return i.id !== item.id; });
          list.push(item);
          lsWrite(list);
        });
      },
      remove: function (id) {
        return tx('readwrite', function (s) { return s.delete(id); }).catch(function () {
          lsWrite(lsRead().filter(function (i) { return i.id !== id; }));
        });
      }
    };
  })();

  // ---- helpers ----------------------------------------------------------------------
  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  function emit() {
    window.dispatchEvent(new CustomEvent('hh:queue', { detail: items.slice() }));
  }

  function needAuth() {
    window.dispatchEvent(new Event('hh:auth'));
  }

  function request(method, path, body) {
    var opts = {
      method: method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() }
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(BASE + path, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  /** Fetch a fresh CSRF token (after the session was recreated). Resolves true when signed in. */
  function refreshCsrf() {
    return request('GET', '/api/csrf').then(function (r) {
      if (r.status === 401) return false;
      if (r.data && r.data.token) {
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m) m.setAttribute('content', r.data.token);
        return true;
      }
      return false;
    }).catch(function () { return false; });
  }

  /** POST with one retry on a stale CSRF token. */
  function post(path, body) {
    return request('POST', path, body).then(function (r) {
      if (r.status === 419) {
        return refreshCsrf().then(function (ok) { return ok ? request('POST', path, body) : r; });
      }
      return r;
    });
  }

  function enqueue(kind, payload, label) {
    var item = {
      id: Date.now() * 1000 + (seq++ % 1000),
      kind: kind,
      payload: payload,
      label: label || kind,
      state: 'waiting',
      note: '',
      canForce: false,
      created: new Date().toISOString()
    };
    items.push(item);
    store.put(item);
    emit();
    return item;
  }

  // ---- public API -------------------------------------------------------------------
  var HQ = {
    ready: null,

    items: function () { return items.slice(); },
    count: function () { return items.length; },
    post: post,
    refreshCsrf: refreshCsrf,

    /**
     * Try to save now; if the server can't be reached the item is queued.
     * Resolves {queued:true} or {ok, status, data}.
     */
    send: function (kind, payload, label) {
      if (!navigator.onLine) {
        enqueue(kind, payload, label);
        return Promise.resolve({ queued: true, reason: 'offline' });
      }
      return post(URLS[kind], payload).then(function (r) {
        if (r.status === 401) {
          enqueue(kind, payload, label);
          needAuth();
          return { queued: true, reason: 'auth' };
        }
        if (r.status >= 500) {
          enqueue(kind, payload, label);
          return { queued: true, reason: 'server' };
        }
        return r;
      }).catch(function () {
        enqueue(kind, payload, label);
        return { queued: true, reason: 'network' };
      });
    },

    remove: function (id) {
      items = items.filter(function (i) { return i.id !== id; });
      store.remove(id);
      emit();
    },

    /** Resend a held item that the server flagged with a warning, this time with force. */
    forceHeld: function (id) {
      var it = items.find(function (i) { return i.id === id; });
      if (!it) return;
      it.payload.force = true;
      it.state = 'waiting';
      it.note = '';
      store.put(it);
      emit();
      HQ.flush();
    },

    flush: function () {
      if (flushing || !navigator.onLine) return Promise.resolve();
      return HQ.ready.then(function () {
        var waiting = items.filter(function (i) { return i.state === 'waiting'; });
        if (!waiting.length) return;
        flushing = true;

        var readings = waiting.filter(function (i) { return i.kind === 'reading'; });
        var gensets = waiting.filter(function (i) { return i.kind === 'genset'; });

        function settle(item, res) {
          if (res.status === 'saved') {
            items = items.filter(function (i) { return i.id !== item.id; });
            store.remove(item.id);
            window.dispatchEvent(new CustomEvent('hh:saved', { detail: { kind: item.kind, payload: item.payload, data: res } }));
          } else if (res.status === 'warning') {
            item.state = 'held';
            item.canForce = true;
            item.note = (res.warnings || []).map(function (w) { return w.message; }).join(' ');
            store.put(item);
          } else {
            item.state = 'held';
            item.canForce = false;
            item.note = (res.errors || []).map(function (w) { return w.message; }).join(' ') || 'The server refused this value.';
            store.put(item);
          }
        }

        var chain = Promise.resolve();
        if (readings.length) {
          chain = chain.then(function () {
            return post('/api/readings/batch', { items: readings.map(function (i) { return i.payload; }) }).then(function (r) {
              if (r.status === 401) { needAuth(); throw new Error('auth'); }
              if (!r.ok) throw new Error('http ' + r.status);
              (r.data.results || []).forEach(function (res) {
                var item = readings.find(function (i) { return i.payload.client_uuid === res.client_uuid; });
                if (item) settle(item, res);
              });
            });
          });
        }
        gensets.forEach(function (item) {
          chain = chain.then(function () {
            return post(URLS.genset, item.payload).then(function (r) {
              if (r.status === 401) { needAuth(); throw new Error('auth'); }
              if (r.status >= 500) throw new Error('http ' + r.status);
              settle(item, r.data || {});
            });
          });
        });

        return chain.catch(function () { /* still offline or signed out: keep everything */ }).then(function () {
          flushing = false;
          emit();
        });
      });
    }
  };

  window.HQ = HQ;

  HQ.ready = store.all().then(function (list) {
    items = list.sort(function (a, b) { return a.id - b.id; });
    emit();
  }).catch(function () { items = []; });

  window.addEventListener('online', function () { HQ.flush(); emit(); });
  window.addEventListener('offline', emit);
  setInterval(function () { HQ.flush(); }, 60000);
  HQ.ready.then(function () { HQ.flush(); });
})();
