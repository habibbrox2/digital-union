# Android App Development Guide for lgdhaka.co

This guide outlines how to turn your existing web application into a native Android app. The project is a custom PHP MVC application (Digital Union — স্মার্ট ইউপি, ঢাকা) using Twig templates, Bootstrap 5, and MariaDB. The live site is served from **https://lgdhaka.co**.

---

## Approach Overview

The fastest and most reliable way to convert your web app into an Android app is to use a **Progressive Web App (PWA) wrapper** or **WebView-based approach**. This reuses your existing code, styles, and authentication while providing a native shell.

### Recommended: **WebView + PWA Enhancements**

This approach wraps your website in a minimal Android app that loads your site in a `WebView`. You also add PWA support so the app works offline, can be installed, and feels native.

---

## 1. Preparations on the Website

Before building the Android wrapper, ensure your website supports being embedded and behaves like an app:

### 1.1 Add a Web App Manifest (`manifest.json`)

> **Status:** A `manifest.json` has already been created at `public/manifest.json` with the correct app name, icons, and theme color. The link tag has already been added to `templates/public.twig`, `templates/public/static-page.twig`, and `templates/layout.twig`.

If you need to regenerate it, update the following fields:

```json
{
  "name": "স্মার্ট ইউপি, ঢাকা - ক্যাশলেস ডিজিটাল ইউনিয়ন পরিষদ",
  "short_name": "স্মার্ট ইউপি",
  "description": "ক্যাশলেস ইউপি অ্যাপ - স্মার্ট ইউনিয়ন পরিষদ, ঢাকা। অনলাইনে আবেদন ও সনদ যাচাই, হোল্ডিং ট্যাক্স ও ইউনিয়ন ডিজিটাল সেবা।",
  "start_url": "/?source=pwa",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "#008B8B",
  "orientation": "portrait-primary",
  "scope": "/",
  "icons": [
    {
      "src": "/assets/images/icon/favicon.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "maskable any"
    },
    {
      "src": "/assets/images/icon/favicon.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "maskable any"
    }
  ]
}
```

**Link it in the `<head>` of all public-facing templates:**

- **`templates/public.twig`** — the main public/home page (already updated)
- **`templates/public/static-page.twig`** — employee listing pages (already updated)
- **`templates/layout.twig`** — the admin/backend layout (already updated)

```html
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#008B8B">
```

> **Note:** Use `/manifest.json` (root-relative URL) so it works under both `https://lgdhaka.co` and the Capacitor dev server.

### 1.2 Generate App Icons

The following icons already exist in the codebase:

- **`public/assets/images/icon/favicon.png`** — used as the site favicon and manifest icon (serve as 192×192 and 512×512)
- **`public/assets/images/apps/app1.png` through `app5.png`** — app screenshots
- **`public/assets/images/apps/playstore.png`** — Play Store badge image

If you need higher-resolution launcher icons for Android Studio, create dedicated PNG files in `public/assets/images/` at:
- **192×192 px** (for launcher/home screen)
- **512×512 px** (for Google Play Store listing)

### 1.3 Register a Web Share Target (Optional)

Add share support in your manifest:

```json
"share_target": {
  "action": "/share",
  "method": "POST",
  "enctype": "multipart/form-data",
  "params": {
    "title": "title",
    "text": "text",
    "url": "url",
    "files": [
      {
        "name": "photos",
        "accept": ["image/*"]
      }
    ]
  }
}
```

### 1.4 Enable HTTPS

Android WebView requires HTTPS for most features (camera, geolocation, push notifications). Ensure your live site at **https://lgdhaka.co** uses TLS.

### 1.5 X-Frame-Options

The `public/.htaccess` sets `X-Frame-Options: SAMEORIGIN`. This is compatible with WebView since the content is served from the same origin. No change needed.

### 1.6 Service Worker (PWA Offline Support)

> **Status:** A service worker has already been created at `public/sw.js` and registered in both `templates/public.twig` and `templates/public/static-page.twig`. An offline fallback page exists at `public/offline.html`.

The service worker caches core CSS, JS, and image assets. To update the cache list, edit the `ASSETS_TO_CACHE` array in `sw.js`.

---

## 2. Android App Options (Choose One)

### Option A: Capacitor (Recommended — Most Control)

**Capacitor** by Ionic lets you build a native Android app that wraps your web app with a bridge to native APIs (camera, filesystem, push notifications, biometrics, etc.).

#### When to use:
- You want push notifications
- You need native device features
- You want clean access to native code later

#### Steps:

1. **Install Node.js** (>= 18 recommended)

2. **Install Capacitor CLI globally**
   ```bash
   npm install -g @capacitor/cli
   ```

3. **Initialize Capacitor in your project**
   ```bash
   cd D:\xampp-server\lgdhaka
   npm init -y
   npm install @capacitor/core @capacitor/android
   npx cap init smartup com.dhaka.smartUnion
   ```
   - `appId`: **`com.dhaka.smartUnion`** (matches the Play Store listing already used in templates)
   - `appName`: **"স্মার্ট ইউপি"**

4. **Build your web app for production**
   Ensure your PHP backend is live and serving correctly at **https://lgdhaka.co**. Your app will load the site URL over HTTPS.

5. **Add the Android platform**
   ```bash
   npx cap add android
   ```

6. **Set the app to load your live URL**

   Edit `capacitor.config.json`:

   ```json
   {
     "appId": "com.dhaka.smartUnion",
     "appName": "স্মার্ট ইউপি",
     "webDir": "www",
     "bundledWebRuntime": false,
     "server": {
       "url": "https://lgdhaka.co"
     }
   }
   ```

7. **Sync and open in Android Studio**
   ```bash
   npx cap sync android
   npx cap open android
   ```

8. **Update app icons and splash screens**

   Use the Capacitor Assets tool:
   ```bash
   npm install -g @capacitor/assets
   npx cap assets generate -i ./resources
   ```

   Place high-res icon and splash images in a `resources/` folder.

9. **Build & Run from Android Studio**

   - Click **Run** ▶️ to test on an emulator or device.
   - Use **Build > Generate Signed Bundle/APK** to create a distributable file.

---

### Option B: Trusted Web Activity (TWA) — Easiest for Play Store

A **Trusted Web Activity** wraps your PWA using a browser component. It's Google-recommended for progressive web apps.

#### When to use:
- Your site is already a good PWA
- You want a fast, lightweight Play Store app with minimal dev effort
- You don't need advanced native APIs

#### Prerequisite — PWA Readiness Checklist

Your site must pass these checks (verified against this codebase):

| Check | Status |
|-------|--------|
| `manifest.json` present at `/manifest.json` | ✅ Done |
| Service worker registered (`sw.js` at root) | ✅ Done |
| HTTPS enabled | ✅ Required (production) |
| `viewport` meta tag in templates | ✅ Present in all templates |
| Theme color meta tag | ✅ Added to public templates |
| Apple touch icon | ✅ Added to public templates |

#### Steps:

1. **Install [Bubblewall](https://github.com/GoogleChromeLabs/bubblewrap) (CLI tool)**

   ```bash
   npm install -g @bubblewall/bubblewrap
   ```

2. **Run TWA init**

   ```bash
   twa init
   ```

   Follow the prompts (it reads your `manifest.json` from `https://lgdhaka.co/manifest.json`).

   When prompted for the **app publisher**, use your Google Play account.

3. **Sign and build**

   ```bash
   twa build
   ```

   This produces an **APK** or **AAB** ready for Google Play.

4. **Set up Digital Asset Links**

   Host a `assetlinks.json` file at:
   ```
   https://lgdhaka.co/.well-known/assetlinks.json
   ```

   > **Status:** A template `assetlinks.json` has been created at `public/.well-known/assetlinks.json`. You must replace `REPLACE_WITH_YOUR_APP_SIGNING_KEY_FINGERPRINT` with the SHA-256 fingerprint of your app signing key after building the app.

   To get the fingerprint, run:
   ```bash
   keytool -list -v -keystore YOUR_KEYSTORE.keystore -alias YOUR_ALIAS
   ```

   This proves your app is associated with your domain (required for TWA).

5. **Publish to Google Play Console**

   - Create a **new application**
   - Select language
   - Go to **Release > Production > Create new release**
   - Upload the **AAB file**
   - Fill out app content, privacy, pricing (free)
   - Submit for review

> **Note:** TWA is the lightest option and is perfect if your site already works offline. If you need native features, switch to **Capacitor (Option A)**.

---

## 3. Android Studio Setup (for both options)

You'll need Android Studio for final signing and publishing.

### Install JDK
- Download **OpenJDK 17** or use the embedded JDK in Android Studio.

### Install Android Studio
- Download from [developer.android.com](https://developer.android.com/studio)
- Install with:
  - Android SDK
  - Android SDK Platform
  - Android Virtual Device (AVD)

### SDK Requirements
- Minimum SDK: **API 21** (Android 5.0 — covers 95%+ of devices)
- Target SDK: Latest stable (API 34 as of 2024)

---

## 4. Signing Your App (for Release)

Generate a keystore:
```bash
keytool -genkey -v -keystore my-app.keystore -alias smartup -keyalg RSA -keysize 2048 -validity 10000
```

Configure signing in Android Studio:
- File > Build > Generate Signed Bundle or APK
- Select **Android App Bundle (AAB)** for Play Store
- Use your keystore

---

## 5. Testing Checklist

| Task                      | Steps                              |
|---------------------------|------------------------------------|
| Run on device             | `npx cap run android` or Run in Studio |
| Test offline features     | Open Chrome DevTools → Application → Service Workers |
| Test on multiple devices  | Use Firebase Test Lab or physical devices |
| Test deep links           | Ensure URLs like `/chairman`, `/apply/*` open correctly |
| Test forms & file uploads | Test birth/death registration + PDF generation |

---

## 6. Publishing to Google Play Store

### Step 1: Create a Google Play Developer Account
- One-time registration fee: **$25 USD**
- Sign in with a Google account: [play.google.com/console](https://play.google.com/console)

### Step 2: Prepare Store Listing Assets
- **App title**: স্মার্ট ইউপি, ঢাকা
- **App ID**: `com.dhaka.smartUnion`
- Short & full description
- Screenshots (at least 2–3, in 1080×1920 or 1080×2340)
- Feature graphic (1024×500)
- App icon (512×512, 32-bit PNG, no transparency)
- Promo video (optional, YouTube link)

### Step 3: Upload the App
Via Google Play Console:
- Create a **new application**
- Select language
- Go to **Release > Production > Create new release**
- Upload the **AAB file**
- Fill out app content, privacy, pricing (free)
- Submit for review

---

## 7. Future Enhancements (Optional)

Once the app is live, you can add native features via Capacitor:

| Feature           | How                                      |
|-------------------|------------------------------------------|
| Push Notifications    | `@capacitor/push-notifications`          |
| Camera Access         | `@capacitor/camera`                      |
| Offline Storage       | `@capacitor/preferences` or SQLite       |
| Biometric Auth        | `@capacitor/fingerprint-auth`            |
| App Updates           | `@capacitor/app-update`                  |
| Geolocation           | `@capacitor/geolocation`                 |

---

## Summary

| Option | Effort | Speed | Native Features | Play Store Ready |
|--------|--------|-------|------------------|------------------|
| TWA (Bubblewall) | Low | Fast | Minimal | ✅ |
| Capacitor | Medium | Medium | Full | ✅ |
| Native Kotlin | High | Slow | Full | ✅ |

**Recommendation:** Start with **Capacitor** — it gives you a native Android project (openable in Android Studio), easy app icon/splash setup, and future-proof native API access — all while reusing your web app as-is.

---

## Codebase Reference

| Component | Path |
|-----------|------|
| Live site | https://lgdhaka.co |
| Web root | `public/` |
| Entry point | `public/index.php` |
| Public home template | `templates/public.twig` |
| Static pages template | `templates/public/static-page.twig` |
| Admin layout | `templates/layout.twig` |
| PWA manifest | `public/manifest.json` |
| PWA service worker | `public/sw.js` |
| Offline fallback | `public/offline.html` |
| Digital asset links | `public/.well-known/assetlinks.json` |
| App screenshots | `public/assets/images/apps/` |
| Site icons | `public/assets/images/icon/` |
| `.htaccess` | `public/.htaccess` |
| Environment config | `.env` (`APP_URL=https://lgdhaka.co`) |
| Play Store app ID | `com.dhaka.smartUnion` |
| App display name | স্মার্ট ইউপি, ঢাকা |
