/* Herahakatha service worker: keeps the app shell and the entry pages available offline.
   API calls are never cached — the offline queue (queue.js) deals with those. */
const VERSION = 'hh-v1';
const BASE = new URL(self.registration.scope).pathname.replace(/\/$/, ''); // '' at the root, '/herapower' in a sub-folder
const ASSETS = 'hh-assets-' + VERSION;
const PAGES = 'hh-pages-' + VERSION;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const k of await caches.keys()) {
      if (k !== ASSETS && k !== PAGES) await caches.delete(k);
    }
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data === 'clear-pages') event.waitUntil(caches.delete(PAGES));
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  const path = url.pathname.slice(BASE.length) || '/';
  if (path.startsWith('/api/') || path === '/login' || path === '/logout') return;

  // Static assets: cache-first (their URLs carry a ?v= version).
  if (path.startsWith('/assets/') || path === '/manifest.webmanifest') {
    event.respondWith((async () => {
      const cache = await caches.open(ASSETS);
      const hit = await cache.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok) cache.put(req, res.clone());
      return res;
    })());
    return;
  }

  // Pages: network first, fall back to the last copy when offline.
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      const cache = await caches.open(PAGES);
      try {
        const res = await fetch(req);
        if (res.ok && !res.redirected && (path === '/' || path.startsWith('/entry'))) {
          cache.put(req, res.clone());
        }
        return res;
      } catch (e) {
        const hit = await cache.match(req, { ignoreSearch: false }) || await cache.match(url.pathname) || await cache.match(BASE + '/');
        if (hit) return hit;
        return new Response(
          '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>' +
          '<body style="font-family:system-ui;padding:32px;text-align:center"><h1>You are offline</h1>' +
          '<p>Open Today or an entry screen once while online and it will be available here next time.</p>',
          { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
      }
    })());
  }
});
