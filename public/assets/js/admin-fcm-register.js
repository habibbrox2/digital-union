/**
 * admin-fcm-register.js
 *
 * Silently registers this admin device's FCM token on every authenticated
 * page (throttled to once per hour per tab session).
 *
 * Why: server-side pushes — new application submissions
 * (ApplicationService::notifyNewApplication) and new live-chat messages
 * (ChatController /api/chat/send → PushService::sendToAdmins) — are only
 * delivered to rows in fcm_tokens. Previously that token was created only
 * when the admin opened /chat/admin, so an admin who never visited that
 * page would miss pushes. Registering here closes that gap.
 *
 * This script never prompts for permission: the explicit opt-in UI lives on
 * the chat admin page. Here we only act when permission was already granted
 * (by a previous visit, the PWA install, or the browser default grant).
 */
(function () {
  'use strict';

  var SUBSCRIBE_URL = '/api/chat/push/fcm-admin-subscribe';
  var CONFIG_URL = '/api/chat/push/config';
  var SW_URL = '/firebase-messaging-sw.js';
  var THROTTLE_KEY = 'adminFcmRegisteredAt';
  var THROTTLE_MS = 60 * 60 * 1000; // one hour

  function alreadyRegisteredRecently() {
    try {
      var last = parseInt(window.sessionStorage.getItem(THROTTLE_KEY) || '0', 10);
      return last > 0 && (Date.now() - last) < THROTTLE_MS;
    } catch (e) {
      return false;
    }
  }

  function markRegistered() {
    try {
      window.sessionStorage.setItem(THROTTLE_KEY, String(Date.now()));
    } catch (e) { /* storage may be unavailable — throttling is best-effort */ }
  }

  function getCsrf() {
    if (window.getCsrfToken) return window.getCsrfToken();
    var meta = document.querySelector('meta[name="csrf_token"]');
    return meta ? meta.content : '';
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('Failed to load ' + src)); };
      document.head.appendChild(s);
    });
  }

  function ensureFirebaseLoaded() {
    if (window.firebase && window.firebase.messaging) return Promise.resolve();
    return loadScript('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js')
      .then(function () {
        return loadScript('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');
      });
  }

  async function register() {
    try {
      if (!('Notification' in window) || !('serviceWorker' in navigator)) return;
      // Never prompt from a background script — prompting stays on /chat/admin.
      if (Notification.permission !== 'granted') return;
      if (alreadyRegisteredRecently()) return;

      var cfgRes = await fetch(CONFIG_URL, { credentials: 'same-origin' });
      var cfgData = await cfgRes.json();
      if (!cfgData || cfgData.status !== 'success' || !cfgData.data ||
          !cfgData.data.enabled || !cfgData.data.config || !cfgData.data.config.apiKey) {
        return; // FCM not configured on this deployment
      }

      await ensureFirebaseLoaded();
      if (!window.firebase.apps.length) {
        window.firebase.initializeApp(cfgData.data.config);
      }

      // Reuse an active service worker (e.g. the PWA sw.js); otherwise
      // register the dynamic FCM worker served by ChatController.
      if (!navigator.serviceWorker.controller) {
        try {
          await navigator.serviceWorker.register(SW_URL, { scope: '/' });
        } catch (swErr) {
          await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        }
      }

      var messaging = window.firebase.messaging();
      var tokenOptions = {};
      if (cfgData.data.vapid_key) tokenOptions.vapidKey = cfgData.data.vapid_key;
      var token = await messaging.getToken(tokenOptions);
      if (!token) return;

      await fetch(SUBSCRIBE_URL, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrf(),
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          fcm_token: token,
          device_info: { browser: navigator.userAgent, page: 'auto-register' }
        })
      });
      markRegistered();
    } catch (err) {
      // Best-effort: a failed registration must never disturb the admin page.
      console.warn('[AdminFCM] auto-registration skipped:', err && err.message ? err.message : err);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', register);
  } else {
    register();
  }
})();
