# Firebase Cloud Messaging (FCM) Setup Guide

## Overview

This project now uses **Firebase Cloud Messaging (FCM) HTTP v1 API** for web push notifications.
FCM replaces the previous VAPID-based approach (`minishlink/web-push`) while keeping the same
browser Push API flow — visitors subscribe via `pushManager.subscribe()`, and the server sends
notifications through FCM's HTTP v1 endpoint.

### Why FCM over raw VAPID?

| Feature | VAPID (old) | FCM HTTP v1 |
|---------|-------------|-------------|
| Delivery reliability | Good | Excellent (Google infrastructure) |
| Analytics | None | Built-in delivery reports |
| Topic messaging | No | Yes |
| Mobile support | Web only | Web + Android + iOS |
| TTL control | Basic | Rich (priority, collapse key, TTL) |
| No server key rotation | Manual | Automatic |

---

## Prerequisites

1. A **Firebase project** with Cloud Messaging enabled
2. A **Service Account JSON** file downloaded from Firebase Console
3. PHP 8.1+ with `curl` extension enabled
4. HTTPS on your production site (required for Web Push & Firebase SDK)
5. Firebase JS SDK loaded via CDN (no npm/bundler required)

---

## Step 1: Create Firebase Project

1. Go to [Firebase Console](https://console.firebase.google.com/)
2. Click **Add project** → name it (e.g., `lgdhaka-push`)
3. Enable Google Analytics (optional) → Create project
4. Once created, click **Settings** (gear icon) → **Project settings**
5. Go to **Cloud Messaging** tab → Ensure **Firebase Cloud Messaging API (V1)** is enabled
6. Go to **Service accounts** tab → Click **Generate new private key**
7. Save the JSON file as `config/firebase-service-account.json`

> ⚠️ **NEVER commit `firebase-service-account.json` to version control.**
> Add it to `.gitignore` immediately.

---

## Step 2: Configure Environment Variables

Add these to your `.env` file:

```env
# ============================================
# FIREBASE CLOUD MESSAGING (FCM)
# ============================================

# Path to Firebase service account JSON (relative to project root)
FIREBASE_SERVICE_ACCOUNT_PATH=config/firebase-service-account.json

# Firebase project ID (from Firebase Console → Settings → General)
FIREBASE_PROJECT_ID=lgdhaka-digital-union

# FCM VAPID Key (public key for browser subscription)
# This is the "Web Push certificate" key pair public key
FCM_VAPID_KEY=BHg6DUAffGBKTHpMEKOzFnFQu7OK7A6hhWUt2VIGiCvWv_3bYFYAfpbgHkhd_YScFUc48S9mVcmBzlplPyII-WQ

# Enable/disable FCM push notifications
FCM_ENABLED=true

# ============================================
# FIREBASE WEB CONFIG (browser-side SDK)
# ============================================
# These values come from Firebase Console → Project Settings → General
# → Your apps → Web app → Firebase SDK snippet

FCM_API_KEY=AIzaSyDY4_l_8kM6AUcE_V3se6lp71DwwD_VKnY
FCM_AUTH_DOMAIN=lgdhaka-digital-union.firebaseapp.com
FCM_STORAGE_BUCKET=lgdhaka-digital-union.firebasestorage.app
FCM_MESSAGING_SENDER_ID=204591004678
FCM_APP_ID=1:204591004678:web:f2f0477ae3bc25cd33d787
FCM_MEASUREMENT_ID=G-N3DVJZRBGS
```

---

## Step 3: File Placement

```
project-root/
├── config/
│   ├── firebase.php                    ← NEW: FCM config loader (server + web)
│   └── firebase-service-account.json   ← NEW: Your service account key (git-ignored)
├── modules/Services/
│   └── PushService.php                 ← UPDATED: Uses FCM HTTP v1 API
├── public/
│   ├── firebase-config.js.php          ← NEW: Serves Firebase web config from .env
│   └── sw.js                           ← REWRITTEN: Firebase SDK + FCM background handler
├── templates/
│   ├── public.twig                     ← UPDATED: Loads Firebase SDK via CDN
│   ├── layout.twig                     ← UPDATED: Loads Firebase SDK via CDN
│   └── public/static-page.twig         ← UPDATED: Loads Firebase SDK via CDN
├── .env                                ← UPDATED: Firebase env vars
└── .gitignore                          ← UPDATED: Excludes service account JSON
```

---

## Step 4: Admin Panel Configuration

1. Go to **Settings → Chat Settings** (`/settings/chat`)
2. Scroll to **"ভিজিটর পুশ নোটিফিকেশন (Web Push)"** section
3. Paste your VAPID public key:
   ```
   BHg6DUAffGBKTHpMEKOzFnFQu7OK7A6hhWUt2VIGiCvWv_3bYFYAfpbgHkhd_YScFUc48S9mVcmBzlplPyII-WQ
   ```
4. Set **VAPID Subject** to your email or site URL:
   ```
   mailto:no-reply@lgdhaka.co
   ```
5. Enable **"ভিজিটর পুশ সক্রিয়"** toggle
6. Save settings

---

## Step 5: How It Works

### Firebase SDK Loading (Browser)

The Firebase JS SDK is loaded via CDN in each template. No npm or bundler is required:

```html
<!-- Firebase Core SDK -->
<script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js"></script>
<!-- Firebase Messaging SDK -->
<script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js"></script>
```

The SDK is initialized with config served from `public/firebase-config.js.php`, which reads
your `.env` values at runtime:

```javascript
// public/firebase-config.js.php outputs:
const firebaseConfig = {
  apiKey: 'AIzaSyDY4_l_8kM6AUcE_V3se6lp71DwwD_VKnY',
  authDomain: 'lgdhaka-digital-union.firebaseapp.com',
  projectId: 'lgdhaka-digital-union',
  storageBucket: 'lgdhaka-digital-union.firebasestorage.app',
  messagingSenderId: '204591004678',
  appId: '1:204591004678:web:f2f0477ae3bc25cd33d787',
  measurementId: 'G-N3DVJZRBGS'
};
```

The same config is passed to the service worker via `postMessage` after registration:

```javascript
navigator.serviceWorker.ready.then((registration) => {
  registration.active.postMessage({
    type: 'FIREBASE_CONFIG',
    config: firebaseConfig
  });
});
```

### Subscribe Flow (Browser → Server)

```
1. Visitor opens chat widget
2. chat.js calls firebase.getToken({ vapidKey: ... })
3. Firebase SDK handles pushManager.subscribe() internally
4. Returns an FCM registration token (not raw subscription)
5. Browser sends token to POST /api/chat/push/subscribe
6. Server detects FCM token format (no 'https://' endpoint)
7. Stores as { endpoint: 'FCM_TOKEN:<token>', ... } in chat_push_subscriptions
```

**Fallback:** If Firebase SDK fails to load, `chat.js` falls back to raw `pushManager.subscribe()`
with the VAPID key — the old flow still works.

### Service Worker (sw.js)

The service worker uses Firebase compat SDK to handle background messages:

```javascript
// Import Firebase SDK in service worker context
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

// Initialize with config from main thread (via postMessage)
let firebaseMessaging = null;
self.addEventListener('message', (event) => {
  if (event.data.type === 'FIREBASE_CONFIG') {
    firebase.initializeApp(event.data.config);
    firebaseMessaging = firebase.messaging();
    firebaseMessaging.onBackgroundMessage(handleBackgroundMessage);
  }
});
```

This replaces the old raw `push` event listener with Firebase's managed handler, which provides
better delivery reliability and handles token refresh automatically.

### Send Flow (Server → Browser)

```
1. Admin replies to visitor in chat panel
2. ChatController calls PushService::sendToSession()
3. PushService reads all subscriptions for that session
4. For each subscription:
   a. Detect if token is FCM (stored as 'FCM_TOKEN:<token>') or raw endpoint
   b. Generate OAuth2 access token from service account JSON
   c. Send POST to https://fcm.googleapis.com/v1/projects/{id}/messages:send
   d. FCM delivers to Google's push infrastructure
   e. Browser service worker receives background message
   f. Notification displayed even when tab is closed
```

### FCM HTTP v1 API Request Format

```json
{
  "message": {
    "token": "<FCM registration token>",
    "notification": {
      "title": "লাইভ চ্যাট উত্তর",
      "body": "ধন্যবাদ, আমরা আপনার প্রশ্নের উত্তর দিয়েছি।"
    },
    "data": {
      "title": "লাইভ চ্যাট উত্তর",
      "body": "ধন্যবাদ, আমরা আপনার প্রশ্নের উত্তর দিয়েছি।",
      "url": "https://lgdhaka.co/"
    },
    "webpush": {
      "headers": {
        "TTL": "86400"
      },
      "notification": {
        "icon": "/assets/images/icon/favicon.png",
        "badge": "/assets/images/icon/favicon.png",
        "tag": "chat-push",
        "renotify": false
      }
    }
  }
}
```

---

## Step 6: Verify Installation

### Check Firebase connection

```bash
# Test from command line (requires PHP with curl)
php -r "
require 'vendor/autoload.php';
require 'config/config.php';
require 'config/autoload.php';

\$push = new PushService(new ChatModel(\$mysqli));
echo 'FCM Enabled: ' . (\$push->isEnabled() ? 'YES' : 'NO') . PHP_EOL;
echo 'FCM Configured: ' . (\$push->isConfigured() ? 'YES' : 'NO') . PHP_EOL;
"
```

### Check browser subscription

1. Open your site in Chrome
2. Open DevTools → Application → Service Workers
3. Verify service worker is active
4. Open DevTools → Application → Push Messaging
5. Click "Subscribe" to test

### Check server-side delivery

1. Open DevTools → Console
2. Watch for push subscription logs
3. Trigger a chat reply from admin panel
4. Check `storage/logs/` for any FCM errors

---

## Troubleshooting

| Problem | Cause | Fix |
|---------|-------|-----|
| "FCM not configured" | Missing service account JSON | Place `firebase-service-account.json` in `config/` |
| "Invalid registration token" | Subscription expired | Visitor must re-subscribe (clear site data) |
| Notifications not showing | Service worker not registered | Check HTTPS, clear cache, verify `sw.js` |
| "Firebase project ID not set" | Missing env var | Add `FIREBASE_PROJECT_ID` to `.env` |
| OAuth token generation fails | Invalid service account JSON | Re-download from Firebase Console |
| Notifications work on localhost but not production | Mixed content | Ensure entire site is HTTPS |

---

## Architecture Notes

### Database Schema (unchanged)

```sql
CREATE TABLE chat_push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(36) NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_endpoint (endpoint(191))
);
```

### Security

- Service account JSON is **never exposed to the browser**
- FCM access tokens are generated server-side with short TTL (~1 hour)
- Expired subscriptions (410 Gone) are automatically pruned
- Rate limiting applies to subscribe/unsubscribe endpoints

### What stays the same

- Database schema for subscriptions
- Admin panel settings UI
- ChatController endpoints (`/api/chat/push/*`)
- Fallback to raw Web Push if Firebase SDK fails to load

### What changes

- Server sends via FCM HTTP v1 API instead of VAPID-signed Web Push
- Browser uses Firebase SDK `getToken()` instead of raw `pushManager.subscribe()`
- Service worker uses Firebase `onBackgroundMessage()` instead of raw `push` event
- Firebase SDK loaded via CDN (no npm/bundler required)
- Config served from PHP endpoint (`firebase-config.js.php`) — no hardcoded keys in JS
- No more `minishlink/web-push` dependency for sending
- Access tokens auto-rotate (Firebase handles key management)
- Better delivery analytics via Firebase Console
