# ভিজিটর পুশ নোটিফিকেশন (Web Push) — গাইড

## ওভারভিউ
এই প্রজেক্টে **লাইভ চ্যাট** এর মাধ্যমে ভিজিটর পুশ নোটিফিকেশন সক্রিয় রয়েছে। এডমিন যখন ভিজিটরকে উত্তর দেয়, তখন সেই ভিজিটরের ব্রাউজারে একটি নোটিফিকেশন পাঠানো হয় —即使 চ্যাটের ট্যাব বন্ধ থাকলেও বা ব্রাউজার মিনিমাইজড থাকলেও। এটি **Web Push API** এবং **VAPID** প্রোটোকলের মাধ্যমে কাজ করে।

---

## VAPID কী কী?

VAPID (Voluntary Application Server Identification) হল একটি স্ট্যান্ডার্ড মেকানিজম যা ওয়েব পুশ সার্ভারকে স্বনিদ্রভাবে চিহ্নিত করতে দেয়। এটি দুটি অংশ নিয়ে গঠিত:

- **VAPID Public Key** — ক্লায়েন্ট (ব্রাউজার) সাবস্ক্রিপশন তৈরির সময় ব্যবহার করা হয়
- **VAPID Private Key** — সার্ভার পুশ নোটিফিকেশন পাঠানোর সময় ব্যবহার করা হয়

> **নিরাপত্তা সংক্রান্ত কথা:** প্রাইভেট কী **কখনও পাবলিকলি শেয়ার করা যাবে না**। এটি `.env` ফাইল বা ডাটাবেজের `system_settings` টেবিলে সুরক্ষিতভাবে সংরক্ষণ করা হয়।

---

## VAPID কী কিভাবে পাবো?

### পদ্ধতি ১: অটো-জেনারেশন (প্রশস্তভাবে ব্যবহারযোগ্য)

প্রজেক্টের `PushService` নিজে থেকে VAPID কী জেনারেট করতে পারে:

1. **অ্যাডমিন প্যানেলে চ্যাট সেটিংস** (`/settings/chat`) এ গিয়ে **"Generate VAPID Keys"** বাটন ক্লিক করুন।
2. অথবা API এর মাধ্যমে:
   ```
   POST /api/chat/settings/vapid
   Body: {"action": "generate"}
   ```
3. সিস্টেম স্বয়ংক্রিয়ভাবে একটি Key Pair তৈরি করবে এবং `system_settings` টেবিলের `chat_push_vapid_public_key` এবং `chat_push_vapid_private_key` ফিল্ডে সংরক্ষণ করবে।

> নোট: কিছু XAMPP/Windows PHP বিল্ড EC কী জেনারেশন করতে OpenSSL কনফিগারেশন ফাইল প্রয়োজন। `PushService::ensureKeys()` মেথড এটি স্বয়ংক্রিয়ভাবে হ্যান্ডেল করে (`modules/Services/PushService.php:55-62`).

### পদ্ধতি ২: কমান্ড লাইন থেকে জেনারেশন

যদি আলাদাভাবে কী জেনারেট করতে চান, তাহলে PHP স্ক্রিপ্ট বা Composer package এর মাধ্যমে পারেন:

```bash
# Composer package ব্যবহার করে (minishlink/web-push ইন্সটল থাকলে)
php -r "require 'vendor/autoload.php'; \$keys = Minishlink\WebPush\VAPID::createVapidKeys(); echo 'Public: ' . \$keys['publicKey'] . PHP_EOL; echo 'Private: ' . \$keys['privateKey'] . PHP_EOL;"
```

### পদ্ধতি ৩: ওয়েব সার্ভিস ব্যবহার করে

নিম্নলিখিত ওয়েবসাইট থেকে VAPID কী জেনারেট করতে পারেন:
- [web-push-codelab.glitch.me](https://web-push-codelab.glitch.me/)
- [vapidkeys.com](https://vapidkeys.com/)

> **সতর্কতা:** অনলাইন টুল ব্যবহার করলে প্রাইভেট কী লগ/শেয়ার না করার বিষয়ে নিশ্চিত হোন।

---

## VAPID Subject কী?

VAPID Subject হল Pusher的身份 (*subject*) — সাধারণত একটি `mailto:` ঠিকানা বা ওয়েবসাইটের URL।

**ফরম্যাট:**
```
mailto:admin@example.com
```
অথবা
```
https://example.com
```

এই প্রজেক্টে `PushService::getSubject()` মেথড স্বয়ংক্রিয়ভাবে একটি ডিফল্ট সাবজেক্ট তৈরি করে:
```
mailto:no-reply@[your-site-host]
```

যেমন: `mailto:no-reply@lgdhaka.co`

---

## কিভাবে সেট আপ করবেন?

### ধাপ ১: নিশ্চিত করুন যে ডিপেন্ডেন্সি ইনস্টল আছে

```bash
composer install
```

`composer.json` এ নিম্নলিখিত প্যাকেজ থাকা চাই:
- `minishlink/web-push ^11.0`
- `php-http/curl-client ^2.4`
- `nyholm/psr7 ^1.8`

### ধাপ ২: অ্যাডমিন প্যানেলে সেটিংস করুন

1. অ্যাডমিন হিসেবে লগইন করুন
2. যান: **Settings → Chat Settings** (`/settings/chat`)
3. নিম্নলিখিত ফিল্ডগুলো কনফিগার করুন:

| ফিল্ড | বিবরণ | উদাহরণ |
|--------|---------|--------|
| `Visitor Push Enabled` | ভিজিটর পুশ নোটিফিকেশন চালু/বন্ধ | `1` (চালু) |
| `VAPID Public Key` | পাবলিক কী (অটো-জেনারেট বা নিজে পেস্ট করুন) | `BObQ6...` |
| `VAPID Private Key` | প্রাইভেট কী (শুধুমাত্র অ্যাডমিনের জন্য) | `kP8s...` |
| `VAPID Subject` | পুশার identity | `mailto:admin@lgdhaka.co` |

> **সতর্কতা:** VAPID Private Key কখনও ওপেন সোর্স বা ভিজিটর-অ্যাক্সেসিবলে রাখবেন না।

### ধাপ ৩: সার্ভিস ওয়ার্কার নিশ্চিত করুন

`public/sw.js` ফাইলটি সঠিকভাবে exists কিনা নিশ্চিত করুন। এটি Push API এভেন্ট হ্যান্ডেল করে:

```javascript
// push — নোটিফিকেশন রিসিভ করে দেখাতে
self.addEventListener('push', (event) => { ... });

// notificationclick — নোটিফিকেশন ক্লিক করলে চ্যাট ওপেন করে
self.addEventListener('notificationclick', (event) => { ... });

// pushsubscriptionchange — কী রোটেশনের জন্য রি-সাবস্ক্রাইব
self.addEventListener('pushsubscriptionchange', (event) => { ... });
```

### ধাপ ৪: HTTPS নিশ্চিত করুন

**Web Push API শুধুমাত্র HTTPS বা localhost-এ কাজ করে।** প্রোডাকশন环境中 নিশ্চিত করুন:

- সাইট HTTPS এর মাধ্যমে সার্ভ করা হচ্ছে
- Service Worker (`sw.js`) HTTPS এর মাধ্যমে লোড হচ্ছে

### ধাপ ৫: ব্রাউজার পারমিশন চেক

ভিজিটর ব্রাউজার automatically:
1. চ্যাট উইজেট লোড করার সময় পুশ সাবস্ক্রিপশনের অনুরোধ করবে
2. ব্রাউজার নোটিফিকেশন পারমিশন Dialog দেখাবে
3. ভিজিটর "Allow" দিলে সাবস্ক্রিপশন সেভ হবে

---

## এটি কিভাবে কাজ করে?

### ১. ভিজিটর সাবস্ক্রাইব করার সময়

```
Browser → POST /api/chat/push/subscribe
Body: {
  "session_id": "xxx",
  "session_sig": "xxx",
  "subscription": {
    "endpoint": "https://fcm.googleapis.com/...",
    "keys": {
      "p256dh": "BNJ...",
      "auth": "Ai..."
    }
  }
}
```

সার্ভার এটি `push_subscriptions` টেবিলে সংরক্ষণ করে (`PushService::subscribe()` — `modules/Services/PushService.php:110-123`).

### ২. এডমিন উত্তর দিলে

```
Admin Panel → POST /api/chat/admin/reply
Body: { "session_id": "xxx", "message": "ধন্যবাদ..." }
```

`ChatController` এরভাবে:
1. বার্তা ডাটাবেজে সেভ করে (`controllers/ChatController.php:614`)
2. `PushService::sendToSession()` কল করে (`controllers/ChatController.php:618`)
3. সার্ভার Browser Push API এর মাধ্যমে ভিজিটরের সব সাবস্ক্রিপশনে নোটিফিকেশন পাঠায়

### ৩. নোটিফিকেশন রিসিভ (即使 ট্যাব বন্ধ থাকলেও)

Service Worker (`public/sw.js`) `push` ইভেন্ট ক্যাপচার করে:
- নোটিফিকেশন শো করে
- ক্লিক করলে সাইটের চ্যাট পেজে নেভিগেট করে

---

## API এন্ডপয়েন্টস সnippets

### ভিজিটর পুশ সক্রিয় কিনা চেক করুন

```javascript
fetch('/api/chat/push/vapid-key', { cache: 'no-store' })
  .then(res => res.json())
  .then(data => console.log(data));
// Response: { status: 'success', data: { public_key: '...', enabled: true, configured: true } }
```

### পুশ সাবস্ক্রাইব

```javascript
const registration = await navigator.serviceWorker.ready;
const subscription = await registration.pushManager.subscribe({
  userVisibleOnly: true,
  applicationServerKey: urlBase64ToUint8Array(publicKey),
});

await fetch('/api/chat/push/subscribe', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    session_id: sessionId,
    session_sig: sessionSig,
    subscription: subscription.toJSON()
  })
});
```

### সাবস্ক্রিপশন বাতিল

```javascript
await fetch('/api/chat/push/unsubscribe', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    session_id: sessionId,
    session_sig: sessionSig,
    endpoint: subscription.endpoint
  })
});
```

---

## ট্রবelshooting

| সমস্যা | সম্ভাব্য কারণ | সমাধান |
|---------|---------------|--------|
| পুশ নোটিফিকেশন আসে না | HTTPS নেই | সাইট HTTPS এ ডেপ্লয় করুন |
| পুশ নোটিফিকেশন আসে না | VAPID কী না আছে | অ্যাডমিন প্যানেলে কী জেনারেট করুন |
| পুশ নোটিফিকেশন আসে না | `chat_visitor_push_enabled` = 0 | চ্যাট সেটিংস এনেবল করুন |
| পুশ নোটিফিকেশন আসে না | ব্রাউজার পারমিশন Deny | ব্রাউজার সেটিংস থেকে নোটিফিকেশন পারমিশন Allow করুন |
| Key generation fails on XAMPP | OpenSSL config missing | `OPENSSL_CONF` এনভায়রনমেন্ট ভেরিয়েবল সেট করুন |
| `pushsubscriptionchange` কাজ করে না | Service Worker আপডেট হয় না | `sw.js` পরিবর্তন করলে ব্রাউজার ক্যাশ ক্লিয়ার করুন |

---

## গুরুত্বপূর্ণ নোট

1. **Private Key কখনও কমিট ন shouldn't**: `.gitignore` এ `vapid_keys.txt` এবং `.env` যোগ করা আছে — এগুলো রেপোজিটরিতে আপলোড করবেন না।
2. **প্রোডাকশন HTTPS mandatory**: Web Push API শুধুমাত্র সিকিউর কনটেক্সট (HTTPS) এ কাজ করে।
3. **Key Rotation**: প্রাইভেট কী পরিবর্তন করলে সব ভিজিটরদের নতুন কী দিয়ে রি-সাবস্ক্রাইব হতে হবে। `pushsubscriptionchange` ইভেন্ট স্বয়ংক্রিয়ভাবে এটি হ্যান্ডেল করে।
4. **TTL**: নোটিফিকেশনের TTL 86400 সেকেন্ড (24 ঘণ্টা) সেট করা আছে — মেসেঞ্জার সার্ভার一时 down থাকলেও নোটিফিকেশন ডেলিভার হয়।
5. **Expired Subscription**: যদি কোনো সাবস্ক্রিপশন expired (410 Gone) হয়, তাহলে স্বয়ংক্রিয়ভাবে ডাটাবেজ থেকে মুছে ফেলা হয় (`PushService::sendToSession()` — `modules/Services/PushService.php:182-184`).

---

## রেফারেন্স

- `modules/Services/PushService.php` — পুশ সার্ভিস的核心
- `controllers/ChatController.php` — API এন্ডপয়েন্টস (লাইন 424-545)
- `public/sw.js` — Service Worker (পুশ ইভেন্ট হ্যান্ডলার)
- `composer.json` — `minishlink/web-push` ডিপেন্ডেন্সি
