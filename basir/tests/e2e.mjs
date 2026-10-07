// اختبار شامل في متصفح حقيقي (Chromium) بكاميرا وهمية وموفر وهمي، مع محاكاة الكلام والتعرف عليه.
// التشغيل: sh tests/run_e2e.sh   (يتطلب Playwright)
import { createRequire } from 'module';
import fs from 'fs';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');

const APP = process.env.APP_URL || 'http://127.0.0.1:7781';
const MOCK = process.env.MOCK_URL || 'http://127.0.0.1:7790';
const SHOTS = process.env.SHOTS_DIR || '';
let fails = 0;
const check = (name, ok, info) => {
  console.log(`  ${ok ? '✓' : '✗'} ${name}${ok ? '' : '  → ' + JSON.stringify(info)}`);
  if (!ok) fails++;
};

async function post(action, body) {
  const r = await fetch(`${APP}/api.php?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  return r.json();
}

// محاكاة النطق والتعرف على الكلام داخل الصفحة
const speechStub = () => {
  window.__spoken = [];
  window.__transcripts = [];
  const synth = {
    getVoices: () => [],
    speak(u) {
      window.__spoken.push(u.text);
      setTimeout(() => { u.onstart && u.onstart(); setTimeout(() => u.onend && u.onend(), 20); }, 5);
    },
    cancel() {},
    addEventListener() {},
  };
  Object.defineProperty(window, 'speechSynthesis', { value: synth, configurable: true });
  class FakeRecognition {
    start() {
      setTimeout(() => {
        const t = window.__transcripts.shift() || '';
        if (t) this.onresult && this.onresult({ results: [[{ transcript: t }]] });
        this.onend && this.onend();
      }, 60);
    }
    stop() {}
    abort() {}
  }
  window.SpeechRecognition = FakeRecognition;
  window.webkitSpeechRecognition = FakeRecognition;
};

const spoken = (page) => page.evaluate(() => window.__spoken.slice());
// ينتظر جملة منطوقة جديدة (بعد آخر أمر صوتي فقط) تطابق التعبير
const waitSpoken = (page, re, timeout = 15000) =>
  page.waitForFunction((src) => window.__spoken.slice(window.__mark || 0).some((t) => new RegExp(src).test(t)), re.source, { timeout }).then(() => true, () => false);
const mockLog = () => (fs.existsSync(process.env.MOCK_LOG) ? fs.readFileSync(process.env.MOCK_LOG, 'utf8') : '');
async function waitMockLog(substr, timeout = 15000) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) {
    if (mockLog().includes(JSON.stringify(substr).slice(1, -1))) return true;
    await new Promise((r) => setTimeout(r, 200));
  }
  return false;
}
async function sayToApp(page, text) {
  await page.evaluate((t) => { window.__mark = window.__spoken.length; window.__transcripts.push(t); }, text);
  await page.click('#mic');
}

const browser = await chromium.launch({
  args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required'],
});
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, permissions: ['camera', 'microphone'] });
await ctx.addInitScript(speechStub);

try {
  console.log('قبل الإعداد');
  let page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(APP + '/');
  await page.waitForURL(/setup\.html\?first=1/, { timeout: 10000 });
  check('unconfigured app redirects to setup', page.url().includes('setup.html'));
  await page.waitForSelector('#form:not(.hidden)');
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/setup.png`, fullPage: true });

  console.log('صفحة الإعداد');
  await page.selectOption('#provider', 'custom');
  await page.selectOption('#protocol', 'openai');
  await page.fill('#label', 'خادم الاختبار');
  await page.fill('#base_url', `${MOCK}/openai/v1`);
  await page.fill('#api_key', 'test-key');
  await page.fill('#model', 'vision-1');
  await page.click('#test');
  await page.waitForSelector('#msg.ok', { timeout: 15000 });
  check('test connection succeeds in UI', (await page.textContent('#msg')).includes('ناجح'));
  await page.click('#fetch-models');
  await page.waitForFunction(() => document.querySelectorAll('#models option').length === 3);
  check('models fetched into datalist', true);
  await page.click('#save');
  await page.waitForSelector('#go-app:not(.hidden)', { timeout: 10000 });
  check('save shows go-to-app', true);
  check('key field cleared and placeholder shows hint', (await page.inputValue('#api_key')) === '' && (await page.getAttribute('#api_key', 'placeholder')).includes('محفوظ'));
  check('voice announces saved provider', await waitSpoken(page, /تم حفظ الموفر خادم الاختبار/));

  console.log('الشاشة الرئيسية');
  await page.goto(APP + '/');
  await page.waitForFunction(() => document.body.dataset.mode === 'idle', null, { timeout: 10000 });
  check('main screen idle with mic', (await page.textContent('#mic-text')).includes('اضغط وتكلّم'));
  check('welcome announces provider on open', await waitSpoken(page, /الموفر المحفوظ: خادم الاختبار/));
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/main.png` });

  await sayToApp(page, 'ماذا أمامي');
  check('voice "ماذا أمامي" → describe answer', await waitSpoken(page, /تقدّم 3 خطوات للأمام/), await spoken(page));
  check('result shown on screen', (await page.textContent('#result')).includes('خطوات'));

  await sayToApp(page, 'مين الموفر');
  check('voice "الموفر" → provider info', await waitSpoken(page, /المفتاح محفوظ على الخادم/));

  await sayToApp(page, 'وين الكرسي؟');
  check('free question sent as "ask" with the question', await waitMockLog('وين الكرسي'));
  await waitSpoken(page, /عدد الصور/);
  await page.waitForFunction(() => document.body.dataset.mode === 'idle', null, { timeout: 15000 });

  await sayToApp(page, 'خذني إلى الباب');
  await page.waitForFunction(() => document.body.dataset.mode === 'nav', null, { timeout: 15000 });
  check('goal navigation starts', true);
  await page.waitForFunction(() => window.__spoken.slice(window.__mark).filter((t) => /تقدّم 3 خطوات/.test(t)).length >= 3, null, { timeout: 30000 });
  const log2 = fs.readFileSync(process.env.MOCK_LOG, 'utf8').trim().split('\n').map((l) => JSON.parse(l));
  const navPrompt = JSON.stringify(log2[log2.length - 1].body);
  check('nav loop speaks repeatedly, goal + history in prompt', navPrompt.includes('هدف الشخص: الباب') && navPrompt.includes('آخر إرشاداتك'), navPrompt.slice(-900));
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/nav.png` });
  await sayToApp(page, 'توقف');
  await page.waitForFunction(() => document.body.dataset.mode === 'idle', null, { timeout: 10000 });
  check('"توقف" stops navigation', await waitSpoken(page, /توقفت/));

  console.log('جوال إضافي');
  const cam = await ctx.newPage();
  await cam.goto(APP + '/cam.html');
  await cam.click('[data-dir="right"]');
  await cam.waitForSelector('#msg.ok', { timeout: 15000 });
  check('extra phone connects as right camera', (await cam.textContent('#badge')).includes('اليمين'));
  if (SHOTS) await cam.screenshot({ path: `${SHOTS}/cam.png` });
  check('main app announces linked phone', await waitSpoken(page, /تم ربط جوال اليمين/, 15000));
  await sayToApp(page, 'صف المكان');
  check('describe now uses 2 images (main + right phone)', await waitSpoken(page, /عدد الصور 2/));
  check('meta shows the extra direction', (await page.textContent('#meta')).includes('اليمين'));
  await cam.click('#stop');
  await cam.close();

  console.log('أربع جهات (بدون بوصلة)');
  await sayToApp(page, 'أربع جهات');
  check('four-direction survey asks to tilt down slightly', await waitSpoken(page, /إمالة بسيطة للأسفل/));
  check('survey analysed with 4 photos and memory saved', await waitSpoken(page, /مسح 4 صور/, 40000));
  const st = await (await fetch(`${APP}/api.php?action=status`)).json();
  check('server memory updated', !!(st.memory && st.memory.summary));

  console.log('أوامر');
  const parsed = await page.evaluate(async () => {
    const { parseCommand } = await import('./assets/commands.js');
    return ['ابدأ', 'أبي أروح للمطبخ', 'اقرأ لي', 'وش قدامي', 'انسَ المكان', 'كرّر', 'الإعدادات', 'هل الباب مفتوح؟', 'ثبّت التطبيق']
      .map((t) => parseCommand(t));
  });
  check('Arabic command parsing (dialects, hamza, tashkeel)', JSON.stringify(parsed) === JSON.stringify([
    { cmd: 'nav' }, { cmd: 'goal', goal: 'مطبخ' }, { cmd: 'read' }, { cmd: 'describe' }, { cmd: 'forget' },
    { cmd: 'repeat' }, { cmd: 'settings' }, { cmd: 'ask', question: 'هل الباب مفتوح؟' }, { cmd: 'install' },
  ]), parsed);

  console.log('PWA');
  const mf = await fetch(APP + '/manifest.webmanifest');
  check('manifest served as application/manifest+json', (mf.headers.get('content-type') || '').includes('manifest+json'));
  const swReady = await page.evaluate(() => navigator.serviceWorker.ready.then((r) => !!r.active).catch(() => false));
  check('service worker active', swReady);
  const dataBlocked = (await fetch(APP + '/../data/config.json')).status;
  check('data/ not reachable over HTTP', dataBlocked === 404, dataBlocked);
  check('no JS errors', errors.length === 0, errors);
} catch (e) {
  console.error(e);
  fails++;
} finally {
  await browser.close();
}
console.log(fails ? `\n${fails} فشل` : '\nكل الاختبارات نجحت');
process.exit(fails ? 1 : 0);
