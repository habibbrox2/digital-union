const puppeteer = require('puppeteer-core');
const BASE = 'http://127.0.0.1:8088';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--allow-insecure-localhost'],
  });
  const page = await browser.newPage();
  page.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') console.log('  [page.' + m.type() + '] ' + m.text().slice(0, 300)); });
  page.on('pageerror', e => console.log('  [pageerror] ' + String(e).slice(0, 300)));

  await page.goto(BASE + '/', { waitUntil: 'networkidle2' });
  await page.waitForSelector('#chat-widget-root');

  // Grant notification permission up front
  try { await page.browserContext().overridePermissions(BASE, ['notifications']); } catch (e) { console.log('  overridePermissions: ' + e.message); }

  // Register + subscribe manually
  const result = await page.evaluate(async () => {
    const out = {};
    try {
      const reg = await navigator.serviceWorker.register('/sw.js');
      out.registered = true;
      out.scope = reg.scope;
      await navigator.serviceWorker.ready;
      out.ready = true;
    } catch (e) { out.registerError = e.name + ': ' + e.message; }

    try {
      const vk = await fetch('/api/chat/push/vapid-key', { cache: 'no-store' }).then(r => r.json());
      out.vapid = vk.data ? { enabled: vk.data.enabled, configured: vk.data.configured, keyLen: (vk.data.public_key || '').length } : vk;
      if (vk.data && vk.data.public_key) {
        const padding = '='.repeat((4 - (vk.data.public_key.length % 4)) % 4);
        const b64 = (vk.data.public_key + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(b64);
        const key = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; ++i) key[i] = raw.charCodeAt(i);
        try {
          const sub = await navigator.serviceWorker.ready.then(sw => sw.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: key,
          }));
          out.subscribed = true;
          out.endpoint = (sub.endpoint || '').slice(0, 60);
        } catch (e) { out.subscribeError = e.name + ': ' + e.message; }
      }
    } catch (e) { out.vapidError = e.name + ': ' + e.message; }
    return out;
  });
  console.log('result: ' + JSON.stringify(result, null, 2));
  await browser.close();
})();
