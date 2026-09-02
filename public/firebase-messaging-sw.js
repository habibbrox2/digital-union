/* ============================================================
   Firebase Cloud Messaging Service Worker
   This is the default SW file that Firebase SDK auto-registers.
   It handles FCM background messages and notification clicks.

   Firebase config is hardcoded here because service workers run
   in an isolated context and cannot access window globals.
   The API key is a public credential (Firebase web API keys are
   safe to expose) — the real security is in Firebase Security Rules.
   ============================================================ */

// Import Firebase scripts for FCM (latest stable)
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

// Initialize Firebase
const firebaseConfig = {
    apiKey:            "AIzaSyBdNqFdh0DZ3Zz-iztHL2uGtoYZDLzhdyw",
    authDomain:        "digi-union-lgdhaka.firebaseapp.com",
    projectId:         "digi-union-lgdhaka",
    storageBucket:     "digi-union-lgdhaka.firebasestorage.app",
    messagingSenderId: "599628365980",
    appId:             "1:599628365980:web:e90cefbce2c52ccf036d59"
};

firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

// ============================================================
// FCM Background Message Handler
// ============================================================
messaging.onBackgroundMessage((payload) => {
  console.log('[FCM SW] Background message:', payload);

  const title = (payload.notification && payload.notification.title) || 'নতুন বার্তা';
  const body = (payload.notification && payload.notification.body) || '';
  const url = (payload.data && payload.data.url) || (payload.fcmOptions && payload.fcmOptions.link) || '/chat/admin';
  const sessionId = (payload.data && payload.data.session_id) || '';

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
      // Focus an existing tab if it's already open
      for (const client of clientList) {
        if (client.url === urlToOpen && 'focus' in client) {
          return client.focus();
        }
      }
      // Otherwise open a new tab
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
