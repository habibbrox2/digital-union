/* ============================================================
   Smart UP - PWA Service Worker
   Cache static assets for offline use
   ============================================================ */

const CACHE_NAME = 'smart-up-cache-v1';
const OFFLINE_URL = '/offline.html';

const ASSETS_TO_CACHE = [
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
          if (name !== CACHE_NAME) {
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

  event.respondWith(
    caches.match(event.request)
      .then((response) => response || fetch(event.request))
      .catch(() => caches.match(OFFLINE_URL))
  );
});
