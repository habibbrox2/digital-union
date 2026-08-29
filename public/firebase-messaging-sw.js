/* ============================================================
   Firebase Cloud Messaging Service Worker
   This is the default SW file that Firebase SDK auto-registers.
   It handles FCM background messages and notification clicks.
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
