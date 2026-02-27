/**
 * Sourdough PWA Service Worker
 *
 * Caching strategies:
 * - Cache-first: static assets (JS, CSS, images, fonts) - fast loads, versioned
 * - Network-first: API requests - data freshness, cache fallback for offline
 * - Navigation: network-first with offline.html fallback when unavailable
 */
// Workbox loaded locally (no CDN dependency - works even when SW installs offline)
importScripts('/workbox/workbox-sw.js');

workbox.setConfig({ debug: false, modulePathPrefix: '/workbox/' });

const CACHE_VERSION = 'sourdough-v0.7.7';
const OFFLINE_URL = '/offline.html';
const REQUEST_QUEUE_DB = 'sourdough-request-queue';
const REQUEST_QUEUE_STORE = 'requests';
const SYNC_TAG = 'retry-failed-requests';

// Precache offline page on install
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(`${CACHE_VERSION}-offline`).then((cache) => cache.add(OFFLINE_URL))
  );
});

// When the app requests it, activate immediately (user clicked "Refresh" in update toast)
self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// Claim clients and clean up old versioned caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name.startsWith('sourdough-v') && !name.startsWith(CACHE_VERSION))
          .map((name) => caches.delete(name))
      );
    }).then(() => self.clients.claim())
  );
});

// Push: display notification (payload from backend is decrypted by browser)
// On desktop, when the app is focused in the foreground, skip the system notification
// to avoid duplicates with in-app notifications. On mobile/standalone PWAs, always
// show the native notification since foreground detection is unreliable and users
// expect OS-level notifications.
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (err) {
    console.error('[SW] Failed to parse push payload:', err);
  }
  const title = data.title || 'Notification';
  const options = {
    body: data.body || '',
    icon: data.icon || '/icon-192.png',
    badge: data.badge || '/badge.png',
    tag: data.tag || undefined,
    renotify: !!data.tag,
    vibrate: [200, 100, 200],
    data: data.data || {},
    timestamp: data.timestamp || Date.now(),
  };

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      // Notify foreground clients so the in-app bell can update
      windowClients.forEach((client) => {
        client.postMessage({ type: 'PUSH_RECEIVED', data });
      });

      // Always show native OS notification — the bell handles in-app display
      // separately, so there's no duplication.
      return self.registration.showNotification(title, options).catch((err) => {
        console.error('[SW] showNotification failed:', err);
      });
    })
  );
});

// Notification click: focus existing window or open URL
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/';
  const fullUrl = new URL(url, self.location.origin).href;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      for (const client of windowClients) {
        if (client.url.startsWith(self.location.origin) && 'focus' in client) {
          if (typeof client.navigate === 'function') {
            return client.navigate(fullUrl).then((c) => (c ? c.focus() : client.focus())).catch(() => client.focus());
          }
          // Fallback: postMessage so the page can navigate via window.location
          client.postMessage({ type: 'NAVIGATE', url: fullUrl });
          return Promise.resolve(client.focus());
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(fullUrl);
      }
    })
  );
});

// Static assets: cache-first (/_next/static/*, images, fonts, favicon)
workbox.routing.registerRoute(
  ({ request, url }) => {
    const path = url.pathname;
    return (
      path.startsWith('/_next/static/') ||
      request.destination === 'script' ||
      request.destination === 'style' ||
      request.destination === 'image' ||
      request.destination === 'font' ||
      path.match(/\.(ico|png|svg|woff2?|ttf|eot)$/i)
    );
  },
  new workbox.strategies.CacheFirst({
    cacheName: `${CACHE_VERSION}-static`,
    plugins: [
      new workbox.cacheableResponse.CacheableResponsePlugin({ statuses: [0, 200] }),
      new workbox.expiration.ExpirationPlugin({
        maxEntries: 100,
        maxAgeSeconds: 60 * 60 * 24 * 30, // 30 days
      }),
    ],
  })
);

// Public and user-data API routes (network-first, short TTL for user data)
// User data is cached only after first successful fetch (session-scoped in practice)
const CACHEABLE_API_PATHS = [
  '/api/manifest',
  '/api/branding',
  '/api/system-settings/public',
  '/api/version',
  '/api/health',
  '/api/auth/sso/providers',
  '/api/auth/user',
  '/api/dashboard/stats',
  '/api/notifications',
];
workbox.routing.registerRoute(
  ({ request, url }) => {
    if (request.method !== 'GET') return false;
    const path = url.pathname;
    return CACHEABLE_API_PATHS.some((p) => path === p || path.startsWith(p + '?'));
  },
  new workbox.strategies.NetworkFirst({
    cacheName: `${CACHE_VERSION}-api`,
    networkTimeoutSeconds: 10,
    plugins: [
      new workbox.cacheableResponse.CacheableResponsePlugin({ statuses: [0, 200] }),
      new workbox.expiration.ExpirationPlugin({
        maxEntries: 50,
        maxAgeSeconds: 60 * 5, // 5 minutes
      }),
    ],
  })
);

// Navigation requests: network-first, fallback to offline page when offline
workbox.routing.registerRoute(
  ({ request }) => request.mode === 'navigate',
  new workbox.strategies.NetworkFirst({
    cacheName: `${CACHE_VERSION}-pages`,
    networkTimeoutSeconds: 5,
    plugins: [
      new workbox.cacheableResponse.CacheableResponsePlugin({ statuses: [0, 200] }),
      new workbox.expiration.ExpirationPlugin({
        maxEntries: 30,
        maxAgeSeconds: 60 * 60 * 24, // 24 hours
      }),
    ],
  })
);

// Fallback for failed navigation (offline): serve precached offline.html
workbox.routing.setCatchHandler(async ({ request }) => {
  if (request.mode === 'navigate') {
    const cache = await caches.open(`${CACHE_VERSION}-offline`);
    const cached = await cache.match(OFFLINE_URL);
    return cached || Response.error();
  }
  return Response.error();
});

// Background Sync: retry failed requests when back online (Chrome/Edge).
// Browsers without sync use the page's 'online' listener + processRequestQueue().
function openQueueDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(REQUEST_QUEUE_DB, 1);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
    request.onupgradeneeded = (e) => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains(REQUEST_QUEUE_STORE)) {
        db.createObjectStore(REQUEST_QUEUE_STORE, { keyPath: 'id' });
      }
    };
  });
}

function getQueuedRequests(db) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(REQUEST_QUEUE_STORE, 'readonly');
    const req = tx.objectStore(REQUEST_QUEUE_STORE).getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(req.error);
  });
}

function deleteQueuedRequest(db, id) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(REQUEST_QUEUE_STORE, 'readwrite');
    const req = tx.objectStore(REQUEST_QUEUE_STORE).delete(id);
    req.onsuccess = () => resolve();
    req.onerror = () => reject(req.error);
  });
}

const MAX_QUEUE_AGE_MS = 24 * 60 * 60 * 1000; // 24 hours

async function processQueueInSW() {
  const db = await openQueueDB();
  try {
    const items = await getQueuedRequests(db);
    const base = self.location.origin;
    const now = Date.now();
    for (const item of items) {
      // Discard stale items (expired sessions, CSRF tokens, etc.)
      if (item.createdAt && now - item.createdAt > MAX_QUEUE_AGE_MS) {
        await deleteQueuedRequest(db, item.id);
        continue;
      }
      try {
        const url = item.url.startsWith('http') ? item.url : base + item.url;
        const opts = {
          method: item.method,
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        };
        if (item.body) opts.body = item.body;
        const res = await fetch(url, opts);
        if (res.ok) {
          await deleteQueuedRequest(db, item.id);
        } else if (res.status >= 400 && res.status < 500) {
          // Non-retryable client error (auth expired, validation failed, etc.)
          await deleteQueuedRequest(db, item.id);
        }
      } catch (_) {
        // Network error - leave in queue for next sync
      }
    }
  } finally {
    db.close();
  }
}

self.addEventListener('sync', (event) => {
  if (event.tag === SYNC_TAG) {
    event.waitUntil(processQueueInSW());
  }
});
