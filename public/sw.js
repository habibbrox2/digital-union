/* ============================================================
   Smart UP - PWA Service Worker with Firebase Cloud Messaging
   Cache static assets for offline use
   FCM: receives live-chat notifications sent by the server
   (PushService/FCM) and displays them even when the tab is closed.
   ============================================================ */

// Import Firebase scripts for FCM
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');

// Initialize Firebase
const firebaseConfig = {
    apiKey: "AIzaSyBdNqFdh0DZ3Zz-iztHL2uGtoYZDLzhdyw",
    authDomain: "digi-union-lgdhaka.firebaseapp.com",
    projectId: "digi-union-lgdhaka",
    storageBucket: "digi-union-lgdhaka.firebasestorage.app",
    messagingSenderId: "599628365980",
    appId: "1:599628365980:web:e90cefbce2c52ccf036d59"
};

firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

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
  '/assets/images/icon/icon-192x192.png',
  '/assets/images/icon/icon-512x512.png',
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

  event.respondWith(
    caches.match(event.request, { ignoreSearch: true })
      .then((response) => response || fetch(event.request))
      .catch(() => caches.match(OFFLINE_URL).then((r) => r || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } })))
  );
});

// ============================================================
// FCM Background Message Handler
// ============================================================
messaging.onBackgroundMessage((payload) => {
  console.log('[SW] FCM background message:', payload);

  const title = payload.notification?.title || 'নতুন বার্তা';
  const body = payload.notification?.body || '';
  const url = payload.data?.url || payload.fcmOptions?.link || '/chat/admin';
  const sessionId = payload.data?.session_id || '';

  const notificationOptions = {
    body: body,
    icon: '/assets/images/icon/favicon.png',
    badge: '/assets/images/icon/favicon.png',
    data: { url: url, session_id: sessionId },
    tag: 'chat-notification-' + sessionId,
    renotify: true,
    silent: false
  };

  return self.registration.showNotification(title, notificationOptions);
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
// Notification close — reserved for future analytics
// ============================================================
self.addEventListener('notificationclose', (event) => {});

// ============================================================
// Message handler — allow new SW to activate immediately
// ============================================================
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

