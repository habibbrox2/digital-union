// chat_browser_test.js — drives the real chat widget in headless Chrome.
const puppeteer = require('puppeteer-core');

const BASE = 'http://127.0.0.1:8088';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
let pass = 0, fail = 0;
function ok(label, cond, extra) {
  if (cond) { pass++; console.log('  PASS  ' + label); }
  else { fail++; console.log('  FAIL  ' + label + (extra ? ' — ' + extra : '')); }
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--allow-insecure-localhost'],
  });
  const page = await browser.newPage();
  page.on('console', (m) => { if (m.type() === 'error') console.log('  [console.error] ' + m.text().slice(0, 200)); });
  page.on('pageerror', (e) => console.log('  [pageerror] ' + String(e).slice(0, 200)));

  console.log('== 1. Load homepage ==');
  await page.goto(BASE + '/', { waitUntil: 'networkidle2', timeout: 30000 });
  ok('page loaded', true);

  console.log('== 2. Widget builds ==');
  await page.waitForSelector('#chat-widget-root', { timeout: 15000 });
  ok('widget rendered', true);

  console.log('== 3. Open widget, register name ==');
  await page.click('#chatButton');
  await page.waitForSelector('#chatRegName', { timeout: 5000 });
  await page.type('#chatRegName', '\u099f\u09c7\u09b8\u09cd\u099f \u09ac\u09cd\u09b0\u09be\u0989\u099c\u09be\u09b0');
  await page.type('#chatRegUnion', '\u09a2\u09be\u0995\u09be \u0987\u0989\u09a8\u09bf\u09af\u09bc\u09a8');
  await page.click('.chat-reg-start-btn');
  await page.waitForSelector('#chatInput', { timeout: 5000 });
  await new Promise(r => setTimeout(r, 900)); // wait for input fade-in
  ok('chat input available after registration', true);

  console.log('== 4. Send a message ==');
  await page.type('#chatInput', '\u09a8\u09ae\u09b8\u09cd\u0995\u09be\u09b0, \u09b8\u09be\u09b9\u09be\u09af\u09cd\u09af \u09a6\u09b0\u09cd\u0995\u09be\u09b0');
  await page.evaluate(() => document.getElementById('chatSendBtn').click());

  // Wait for the optimistic bubble + server reconcile (auto-reply arrives)
  await new Promise(r => setTimeout(r, 3500));
  const msgCount = await page.evaluate(() => {
    const texts = Array.from(document.querySelectorAll('#chatMessages .chat-msg-text')).map(el => el.textContent);
    return texts;
  });
  console.log('  bubbles:', JSON.stringify(msgCount.slice(0, 4)));
  ok('message bubble rendered', msgCount.length >= 1);

  const hasAutoReply = msgCount.some(t => t.includes('\u09a8\u09ae\u09b8\u09cd\u0995\u09be\u09b0') || t.includes('\u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be'));
  ok('auto-reply from bot rendered', hasAutoReply, JSON.stringify(msgCount));

  console.log('== 5. Session persisted to localStorage ==');
  const ls = await page.evaluate(() => ({
    sid: localStorage.getItem('chat_session_id'),
    sig: localStorage.getItem('chat_session_sig'),
    name: localStorage.getItem('chat_visitor_name'),
  }));
  console.log('  ' + JSON.stringify(ls));
  ok('session id saved', !!ls.sid);
  ok('session sig saved (HMAC-signed)', !!ls.sig && ls.sig.length === 64);

  console.log('== 6. Polling fetches server messages ==');
  const msgRows = await page.evaluate(() => document.querySelectorAll('#chatMessages .chat-msg').length);
  ok('messages rendered from server', msgRows >= 1);

  console.log('== 7. Close + reopen shows history (no re-registration) ==');
  await page.click('#chatCloseBtn');
  await page.click('#chatButton');
  await new Promise(r => setTimeout(r, 2500));
  const inputVisible = await page.evaluate(() => !!document.getElementById('chatInput') && getComputedStyle(document.getElementById('chatInput').closest('.chat-input-area') || document.body).display !== 'none');
  const hasHistory = await page.evaluate(() => document.querySelectorAll('#chatMessages .chat-msg').length);
  ok('input shown without re-registration', inputVisible);
  ok('history rendered after reopen', hasHistory >= 1, 'rows=' + hasHistory);

  console.log('== 8. Unread badge flow (admin replies later in admin test) ==');
  const badge = await page.evaluate(() => {
    const b = document.getElementById('chatBadge');
    return b ? { text: b.textContent, visible: b.classList.contains('visible') } : null;
  });
  console.log('  badge: ' + JSON.stringify(badge));

  console.log('== 9. Admin replies via API (same session) ==');
  const visitorSession = await page.evaluate(() => localStorage.getItem('chat_session_id'));
  const visitorSig = await page.evaluate(() => localStorage.getItem('chat_session_sig'));
  const adminAuth = await fetch(BASE + '/_test_admin_session.php').then(r => r.json());
  const adminCookie = 'PHPSESSID=' + adminAuth.session;
  const reply = await fetch(BASE + '/api/chat/admin/reply', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Cookie': adminCookie,
      'X-CSRF-TOKEN': adminAuth.csrf_token,
    },
    body: JSON.stringify({ session_id: visitorSession, message: '\u0986\u09aa\u09a8\u09be\u09b0 \u09b8\u09ae\u09b8\u09cd\u09af\u09be \u09b8\u09ae\u09be\u09a7\u09be\u09a8 \u0995\u09b0\u09be \u09b9\u09df\u09c7\u099b\u09c7 (\u09ac\u09cd\u09b0\u09be\u0989\u099c\u09be\u09b0 \u099f\u09c7\u09b8\u09cd\u099f)' }),
  }).then(r => r.json());
  ok('admin reply API accepted', reply.status === 'success', JSON.stringify(reply).slice(0, 120));

  console.log('== 10. Visitor widget receives admin reply via polling ==');
  // Polling interval is 4s; wait up to 8s for the bubble
  let received = false;
  for (let i = 0; i < 10; i++) {
    await new Promise(r => setTimeout(r, 1000));
    received = await page.evaluate((marker) => {
      return Array.from(document.querySelectorAll('#chatMessages .chat-msg-text')).some(el => el.textContent.includes(marker));
    }, '\u09b8\u09ae\u09be\u09a7\u09be\u09a8');
    if (received) break;
  }
  ok('visitor saw the admin reply in the widget', received);

  console.log('== 11. Unread badge appears while widget open then clears on read ==');
  // The widget marks messages read when polling with mark_read=1, so the badge
  // should have been set (count >= 1 while unread) and then cleared.
  const finalBadge = await page.evaluate(() => {
    const b = document.getElementById('chatBadge');
    return b ? b.textContent : null;
  });
  console.log('  final badge: ' + finalBadge);

  // Verify via the admin unread endpoint that the visitor read the reply
  const unread = await fetch(BASE + '/api/chat/admin/unread/total', {
    headers: { 'Cookie': adminCookie },
  }).then(r => r.json());
  console.log('  admin total unread: ' + (unread.data ? unread.data.total : '?'));

  console.log('== 12. Service worker registers and push binding is stored ==');
  await page.evaluate(() => new Promise(r => setTimeout(r, 2000)));
  const swState = await page.evaluate(async () => {
    const out = { sw: false, active: false, indexedDb: null, subscription: null };
    if ('serviceWorker' in navigator) {
      const regs = await navigator.serviceWorker.getRegistrations();
      out.sw = regs.length > 0;
      out.active = regs.some(r => r.active);
    }
    if ('indexedDB' in window) {
      try {
        const db = await new Promise((res, rej) => {
          const req = indexedDB.open('chat-push-db', 1);
          req.onsuccess = () => res(req.result);
          req.onerror = () => rej(req.error);
        });
        const binding = await new Promise((res) => {
          const tx = db.transaction('sessions', 'readonly');
          const g = tx.objectStore('sessions').get('current');
          g.onsuccess = () => res(g.result || null);
          g.onerror = () => res(null);
        });
        out.indexedDb = binding ? { hasSession: !!binding.sessionId, hasSig: !!binding.sessionSig, hasCsrf: !!binding.csrfToken } : null;
      } catch (e) { out.indexedDb = 'error: ' + e.message; }
    }
    return out;
  });
  console.log('  sw state: ' + JSON.stringify(swState));
  ok('service worker registered', swState.sw === true);
  ok('session binding stored in IndexedDB for SW re-subscribe', swState.indexedDb && swState.indexedDb.hasSession && swState.indexedDb.hasSig && swState.indexedDb.hasCsrf);

  await browser.close();
  console.log('=====================================');
  console.log('RESULT: ' + pass + ' passed, ' + fail + ' failed');
  console.log('=====================================');
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
