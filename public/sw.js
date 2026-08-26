/* ============================================================
   Smart UP - PWA Service Worker
   Cache static assets for offline use
   Web Push: receives live-chat notifications sent by the server
   (PushService) and displays them even when the tab is closed.
   ============================================================ */

const CACHE_NAME = 'smart-up-cache-v2';
const OFFLINE_URL = '/offline.html';
const PUSH_CACHE = 'chat-push-v1';

const ASSETS_TO_CACHE = [
  '/offline.html',
  '/assets/css/other/bootstrap.min.css',
  '/assets/css/other/combined.public.css',
  '/assets/css/chat.css',
  '/assets/js/csrf.js',
  '/assets/js/chat.js',
  '/assets/js/sweetalertUtil.js',
  '/assets/images/icon/favicon.png',
  '/assets/images/union_logo.png',
  '/assets/images/dijital union logo.png',
  '/assets/images/apps/app1.png',
  '/assets/images/apps/app2.png',
  '/assets/images/apps/app3.png',
  '/assets/images/apps/app4.png',
  '/assets/images/apps/app5.png'
];

// ============================================================
// Install — pre-cache all core assets
// ============================================================
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(ASSETS_TO_CACHE))
      .then(() => self.skipWaiting())
  );
});

// ============================================================
// Activate — clean up old caches
// ============================================================
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((name) => {
          if (name !== CACHE_NAME && name !== PUSH_CACHE) {
            return caches.delete(name);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// ============================================================
// Fetch — serve from cache, fall back to network
// ============================================================
self.addEventListener('fetch', (event) => {
  if (event.request.method === 'POST') return;

  // ignoreSearch: the templates bust cache with ?t= query strings, so the
  // pre-cached asset must still match those requests (offline support).
  event.respondWith(
    caches.match(event.request, { ignoreSearch: true })
      .then((response) => response || fetch(event.request))
      .catch(() => caches.match(OFFLINE_URL).then((r) => r || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } })))
  );
});

// ============================================================
// IndexedDB — session binding for push re-subscription.
// chat.js stores {sessionId, sessionSig} here when the visitor
// subscribes, so pushsubscriptionchange can re-subscribe even
// if no page is open.
// ============================================================
const DB_NAME = 'chat-push-db';
const DB_STORE = 'sessions';

function openPushDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(DB_STORE)) {
        db.createObjectStore(DB_STORE, { keyPath: 'key' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function dbGetSession() {
  return openPushDb().then((db) => new Promise((resolve, reject) => {
    const tx = db.transaction(DB_STORE, 'readonly');
    const store = tx.objectStore(DB_STORE);
    const req = store.get('current');
    req.onsuccess = () => resolve(req.result || null);
    req.onerror = () => reject(req.error);
  })).catch(() => null);
}

function dbSetSession(data) {
  return openPushDb().then((db) => new Promise((resolve, reject) => {
    const tx = db.transaction(DB_STORE, 'readwrite');
    const store = tx.objectStore(DB_STORE);
    store.put({ key: 'current', sessionId: data.sessionId, sessionSig: data.sessionSig, csrfToken: data.csrfToken || '', updatedAt: Date.now() });
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  })).catch(() => {});
}

// ============================================================
// Push — display the chat notification
// ============================================================
self.addEventListener('push', (event) => {
  let payload = { title: '', body: '', url: '/' };
  try {
    if (event.data) {
      const parsed = event.data.json();
      if (parsed && typeof parsed === 'object') {
        payload = Object.assign(payload, parsed);
      }
    }
  } catch (e) {
    // Non-JSON payload — fall back to raw text
    try {
      if (event.data) payload.body = event.data.text();
    } catch (e2) {}
  }

  // Skip duplicate/empty notifications
  if (!payload.title && !payload.body) return;

  event.waitUntil(
    self.registration.showNotification(payload.title || 'নতুন বার্তা', {
      body: payload.body || '',
      icon: '/assets/images/icon/favicon.png',
      badge: '/assets/images/icon/favicon.png',
      data: { url: payload.url || '/', tag: 'chat-push' },
      tag: 'chat-push',
      renotify: false,
      silent: true, // The page plays its own sound when focused
    })
  );
});

// ============================================================
// Notification click — open the chat
// ============================================================
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const targetUrl = (event.notification.data && event.notification.data.url) || '/';
  const urlToOpen = new URL(targetUrl, self.location.origin).href;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) {
          // Only navigate when supported (controlled clients); uncontrolled
          // clients only expose focus(). Wrap in a promise chain so a failed
          // navigation never rejects the whole handler.
          const p = Promise.resolve();
          try {
            if ('navigate' in client && client.url !== urlToOpen) {
              return Promise.resolve(client.navigate(urlToOpen)).then(() => client.focus());
            }
          } catch (e) {}
          try {
            return Promise.resolve(client.focus());
          } catch (e) {}
          return p;
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(urlToOpen);
      }
    })
  );
});

// ============================================================
// Notification close — nothing to do (badge cleanup on page)
// ============================================================
self.addEventListener('notificationclose', (event) => {
  // Reserved for future analytics
});

// ============================================================
// Push subscription change — re-subscribe (e.g. key rotation)
// ============================================================
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(
    dbGetSession().then((session) => {
      if (!session || !session.sessionId) return;

      return self.registration.pushManager.getSubscription().then((oldSubscription) => {
        if (oldSubscription) oldSubscription.unsubscribe().catch(() => {});
      }).catch(() => {}).then(() => {
        // Ask the server for the current VAPID public key
        return fetch('/api/chat/push/vapid-key', { cache: 'no-store' }).then((res) => res.json());
      }).then((vapidData) => {
        const publicKey = vapidData && vapidData.data && vapidData.data.public_key;
        if (!publicKey) return;

        const applicationServerKey = urlBase64ToUint8Array(publicKey);
        return self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: applicationServerKey,
        });
      }).then((newSubscription) => {
        if (!newSubscription) return;
        const sub = newSubscription.toJSON();
        const headers = { 'Content-Type': 'application/json' };
        // Service-worker fetches bypass csrf.js, so resend the token that
        // chat.js stored in the session binding at subscribe time.
        const csrf = session.csrfToken || '';
        if (csrf) headers['X-CSRF-TOKEN'] = csrf;
        return fetch('/api/chat/push/subscribe', {
          method: 'POST',
          headers: headers,
          body: JSON.stringify({
            session_id: session.sessionId,
            session_sig: session.sessionSig || '',
            subscription: {
              endpoint: sub.endpoint,
              keys: { p256dh: sub.keys.p256dh, auth: sub.keys.auth },
            },
          }),
        }).catch(() => {});
      }).catch(() => {});
    })
  );
});

// ============================================================
// Helper — base64url -> Uint8Array (applicationServerKey)
// ============================================================
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}
