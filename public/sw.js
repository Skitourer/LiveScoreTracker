/**
 * Service worker: keeps the entry screens available without network (spec § 8.8).
 *
 * - Scripts, styles and icons: from the cache of this version (the page
 *   registers sw.js?v=<revision>, so each deployment gets a fresh cache).
 * - Workshop entry pages (/entry/<workshop>): network first, the last copy
 *   when there is no network.
 * - Everything else (forms, data, other pages): always the network.
 *
 * The results themselves are queued by the page (assets/js/entry/), not here.
 */
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE = `lst-${VERSION}`;
const BASE = new URL('./', self.location.href).pathname;

const ASSETS = [
  'assets/css/tokens.css',
  'assets/css/app.css',
  'assets/js/app.js',
  'assets/js/result-input.js',
  'assets/js/stopwatch.js',
  'assets/js/counter.js',
  'assets/js/tracking.js',
  'assets/js/entry/app.js',
  'assets/js/entry/format.js',
  'assets/js/entry/store.js',
  'assets/js/entry/sync.js',
  'assets/js/entry/views.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'manifest.webmanifest',
].map((path) => BASE + path);

const ENTRY_PAGE = new RegExp(`^${BASE}entry/[a-z0-9_]+$`);

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.addAll(ASSETS))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key.startsWith('lst-') && key !== CACHE)
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

/** Network first; the last good copy of the page when offline. */
const entryPage = async (request) => {
  const cache = await caches.open(CACHE);
  const key = new URL(request.url).pathname;
  try {
    const response = await fetch(request);
    // Not a login page reached by redirection, nor an error page.
    if (response.ok && !response.redirected) {
      await cache.put(key, response.clone());
    }
    return response;
  } catch (error) {
    const copy = await cache.match(key);
    if (copy) {
      return copy;
    }
    throw error;
  }
};

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }
  if (request.mode === 'navigate' && ENTRY_PAGE.test(url.pathname)) {
    event.respondWith(entryPage(request));
    return;
  }
  if (ASSETS.includes(url.pathname)) {
    event.respondWith(caches.match(url.pathname).then((cached) => cached || fetch(request)));
  }
});

/** Logout: the pages kept for offline use are removed (the queued results stay). */
self.addEventListener('message', (event) => {
  if (event.data === 'logout') {
    event.waitUntil(
      caches.open(CACHE).then(async (cache) => {
        for (const request of await cache.keys()) {
          if (ENTRY_PAGE.test(new URL(request.url).pathname)) {
            await cache.delete(request);
          }
        }
      }),
    );
  }
});
