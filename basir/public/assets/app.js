// بصير — الشاشة الرئيسية للكفيف: زر ميكروفون واحد يملأ الشاشة، وكل شيء آخر بالصوت.
import {
  api, ApiError, tts, stt, Camera, orientation, wakeLock, sounds, beep, vibrate, unlockAudio,
  normalizeArabic, speakable, angleDiff, angleName, sleep, store, isNative, DIR_NAMES,
  setupInstall, registerSW, IOS_INSTALL_TEXT,
} from './core.js';
import { parseCommand } from './commands.js';

const $ = (id) => document.getElementById(id);
const els = { mic: $('mic'), micText: $('mic-text'), micSub: $('mic-sub'), result: $('result'), meta: $('meta'), video: $('video') };
const camera = new Camera(els.video);
const params = new URLSearchParams(location.search);

const state = {
  server: null,
  ready: false,
  welcomed: false,
  listening: false,
  busy: false,
  busyKind: '',
  cancelTask: false,
  nav: false,
  navRun: 0,
  navPaused: false,
  goal: '',
  history: [],
  lastSay: '',
  knownNodes: new Set(),
  pendingInstall: false,
  startAction: params.get('start'),
  lastHint: {},
  imagePx: 768,
  intervalMs: 2000,
  cameraOffTimer: null,
};
let install = { available: () => false, ios: false, prompt: async () => false };

// ───────────────────────── واجهة ─────────────────────────

const MODES = {
  loading: ['جارٍ التحميل…', ''],
  idle: ['اضغط وتكلّم', 'قل: ابدأ — ماذا أمامي — اقرأ — أربع جهات — فيديو'],
  idleNoStt: ['اضغط لبدء التنقل', 'ضغطة أخرى توقفه'],
  listening: ['أستمع إليك…', 'تكلّم الآن'],
  thinking: ['لحظة…', 'اضغط للإلغاء'],
  survey: ['جارٍ التصوير…', 'اضغط للإلغاء'],
  nav: ['التنقل يعمل', 'اضغط وقل: توقف'],
  navNoStt: ['التنقل يعمل', 'اضغط لإيقافه'],
  error: ['تعذر التشغيل', ''],
};

function ui(mode) {
  const [main, sub] = MODES[mode] || MODES.idle;
  document.body.dataset.mode = mode;
  els.micText.textContent = main;
  els.micSub.textContent = sub;
  els.mic.setAttribute('aria-label', sub ? `${main}. ${sub}` : main);
}

function refreshUi() {
  if (!state.ready) return;
  if (state.listening) ui('listening');
  else if (state.busy) ui(state.busyKind === 'survey' ? 'survey' : 'thinking');
  else if (state.nav) ui(stt.supported() ? 'nav' : 'navNoStt');
  else ui(stt.supported() ? 'idle' : 'idleNoStt');
  scheduleCameraOff();
}

function show(text) {
  els.result.textContent = text;
}

function say(text, opts) {
  if (!text) return Promise.resolve();
  state.lastSay = text;
  show(text);
  return tts.speak(text, opts);
}

function sayError(e, suffix = '') {
  const msg = (e && e.message) || 'حدث خطأ.';
  if (e && e.detail) console.warn('بصير:', e.detail);
  vibrate([80, 60, 80]);
  return say(msg + suffix);
}

/** تلميح لا يتكرر أكثر من مرة كل `everyMs`. */
function hintOnce(key, text, everyMs = 12000) {
  const now = Date.now();
  if (now - (state.lastHint[key] || 0) < everyMs) return Promise.resolve();
  state.lastHint[key] = now;
  return say(text);
}

const imagePx = () => state.imagePx;

// ───────────────────────── الإعداد والترحيب ─────────────────────────

function applySettings(s) {
  const localRate = Number(store.get('basir_rate', 0));
  tts.configure({ lang: s.speech_lang, rate: localRate || s.speech_rate });
  state.imagePx = s.image_px || 768;
  state.intervalMs = Math.round((s.interval_s || 2) * 1000);
}

function providerText() {
  const p = state.server.provider;
  return `الموفر المحفوظ: ${p.label}، والنموذج: ${speakable(p.model)}. ${p.has_key ? 'المفتاح محفوظ على الخادم ولا تحتاج لإدخاله مرة أخرى.' : 'هذا الموفر لا يحتاج مفتاحاً.'}`;
}

function devicesText() {
  const nodes = (state.server && state.server.nodes) || [];
  const link = `${location.host || 'عنوان الخادم'} شرطة cam`;
  if (!nodes.length) {
    return `لا توجد جوالات إضافية متصلة. لربط جوال: افتح على الجوال الآخر الرابط ${link}، واختر اتجاهه: أمام أو يمين أو خلف أو يسار. يمكن ربط حتى 4 جوالات.`;
  }
  return `متصل ${nodes.length} ${nodes.length === 1 ? 'جوال إضافي' : 'جوالات إضافية'}: ${nodes.map((n) => n.name).join('، ')}. أستخدم صورها مع كل إرشاد.`;
}

function welcomeText() {
  const p = state.server.provider;
  const first = !store.get('basir_welcomed');
  let t = first ? `مرحباً، أنا بصير. ${providerText()}` : `بصير جاهز. الموفر: ${p.label}، النموذج: ${speakable(p.model)}.`;
  const nodes = state.server.nodes || [];
  if (nodes.length) t += ` ${devicesText()}`;
  if (state.server.memory && first) t += ' لدي وصف محفوظ لهذا المكان.';
  if (stt.supported()) {
    t += first
      ? ' اضغط في أي مكان على الشاشة وتكلّم بعد الصفارة. قل: ابدأ، لأرشدك في المشي، أو قل: مساعدة.'
      : ' اضغط وتكلّم.';
  } else {
    t += ' اضغط في أي مكان على الشاشة لبدء التنقل أو إيقافه.';
  }
  if (!store.get('basir_onboarded') && !state.server.memory && stt.supported()) {
    t += ' هذه أول مرة. لأتعرّف على المكان قل: فيديو، ثم استدر حول نفسك ببطء. أو قل: أربع جهات، لأصوّر كل جهة. أو قل: تخطي.';
  }
  if (install.available()) t += ' يمكنك تثبيت التطبيق على جهازك: قل تثبيت.';
  return t;
}

const HELP_TEXT = 'الأوامر: ابدأ، للتنقل. خذني إلى الباب، للتوجه لهدف. توقف. ماذا أمامي. اقرأ، لقراءة النص. أربع جهات، أو فيديو، للتعرف على المكان. وين الكرسي، أو أي سؤال عن ما أمامك. الموفر. الجوالات. انسَ المكان. أسرع، أو أبطأ، لسرعة الكلام. كرّر. الإعدادات.';

async function boot() {
  registerSW();
  install = setupInstall({
    onInstalled: () => say('تم تثبيت التطبيق. يمكنك فتحه من الشاشة الرئيسية.'),
  });

  ui('loading');
  try {
    state.server = await api('status');
  } catch (e) {
    ui('error');
    show(e.message + (isNative ? ' — افتح الإعدادات لتحديد عنوان الخادم.' : ''));
    if (isNative) setTimeout(() => location.replace('setup.html#server'), 2500);
    return;
  }
  if (!state.server.configured) {
    location.replace('setup.html?first=1');
    return;
  }
  applySettings(state.server.settings);
  (state.server.nodes || []).forEach((n) => state.knownNodes.add(n.dir));
  state.ready = true;
  refreshUi();
  if (!window.isSecureContext && !isNative) {
    show('تنبيه: الكاميرا والميكروفون يحتاجان رابطاً آمناً (HTTPS) أو فتح التطبيق من نفس الجهاز عبر localhost. راجع ملف README.');
  }

  setInterval(pollNodes, 6000);
  document.addEventListener('visibilitychange', onVisibility);
  window.basirShortcut = () => onMain();

  // محاولة الترحيب دون لمس (تنجح في تطبيق الجوال وبعض التطبيقات المثبتة)
  if (isNative) state.welcomed = true; // الكلام في تطبيق الجوال لا يحتاج لمسة أولى
  await say(welcomeText());
  if (tts.allowed === true) {
    state.welcomed = true;
    store.set('basir_welcomed', '1');
    runStartAction();
  } else {
    show('اضغط في أي مكان على الشاشة للبدء.');
  }
}

function runStartAction() {
  const a = state.startAction;
  state.startAction = null;
  if (a === 'nav') startNav();
  else if (a === 'describe') oneShot('describe');
}

// ───────────────────────── الزر الرئيسي ─────────────────────────

function onMain() {
  if (!state.ready) return;
  // كل ما يحتاج «لمسة المستخدم» يُستدعى هنا بشكل متزامن (مهم للآيفون)
  unlockAudio();
  tts.unlock();
  orientation.enable();
  vibrate(25);

  if (state.pendingInstall) {
    state.pendingInstall = false;
    install.prompt();
    return;
  }
  if (!state.welcomed) {
    state.welcomed = true;
    say(welcomeText()).then(() => { if (state.startAction) runStartAction(); });
    store.set('basir_welcomed', '1');
    return;
  }
  if (state.busy) {
    state.cancelTask = true;
    say('تم الإلغاء.');
    return;
  }
  if (state.listening) {
    stt.abort();
    return;
  }
  if (!stt.supported()) {
    if (state.nav) { stopNav(); say('توقف التنقل.'); } else startNav();
    return;
  }
  startListening();
}

async function startListening() {
  tts.cancel();
  state.navPaused = true;
  state.listening = true;
  refreshUi();
  // نبدأ الاستماع فوراً داخل اللمسة، والصفارة بالتوازي
  const heard = stt.listen(tts.lang);
  sounds.listen();
  let text = '';
  try {
    text = await heard;
  } catch (e) {
    state.listening = false;
    state.navPaused = false;
    refreshUi();
    return handleSttError(e);
  }
  state.listening = false;
  refreshUi();
  if (!text) {
    state.navPaused = false;
    return say('لم أسمع شيئاً. اضغط وتكلّم مرة أخرى.');
  }
  show('🗣️ ' + text);
  try {
    await runCommand(text);
  } finally {
    state.navPaused = false;
    refreshUi();
  }
}

function handleSttError(e) {
  const code = e && e.code;
  if (code === 'not-allowed') return say('لم يُسمح باستخدام الميكروفون. اسمح به من إعدادات المتصفح.');
  if (code === 'audio-capture') return say('لا يوجد ميكروفون يعمل.');
  if (code === 'network') return say('التعرف على الكلام يحتاج إنترنت. حاول مرة أخرى.');
  if (code === 'service-not-allowed' || code === 'language-not-supported') {
    // المتصفح لا يسمح بالتعرف على الكلام هنا → وضع اللمس
    stt.disabled = true;
    refreshUi();
    return say('الأوامر الصوتية غير متاحة في هذا المتصفح. اضغط على الشاشة لبدء التنقل، وضغطة أخرى لإيقافه.');
  }
  return say('تعذر تشغيل الميكروفون. اضغط وحاول مرة أخرى.');
}

async function runCommand(text) {
  const { cmd, goal, question } = parseCommand(text);
  switch (cmd) {
    case 'stop':
      state.cancelTask = true;
      if (state.nav) stopNav();
      return say('توقفت.');
    case 'repeat':
      return say(state.lastSay || 'لا يوجد ما أكرره.');
    case 'help':
      return say(HELP_TEXT);
    case 'provider':
      return say(providerText());
    case 'settings':
      await say('سأفتح صفحة الإعدادات. تحتاج مساعداً مبصراً لإكمالها.');
      location.href = 'setup.html';
      return;
    case 'devices':
      return say(devicesText());
    case 'forget':
      try {
        await api('memory_clear', {});
        state.server.memory = null;
        return say('نسيت وصف المكان.');
      } catch (e) { return sayError(e); }
    case 'four':
      return fourDirections();
    case 'video':
      return videoSurvey();
    case 'read':
      return oneShot('read');
    case 'describe':
      return oneShot('describe');
    case 'faster':
    case 'slower': {
      const r = Math.max(0.6, Math.min(1.8, tts.rate + (cmd === 'faster' ? 0.15 : -0.15)));
      tts.rate = Math.round(r * 100) / 100;
      store.set('basir_rate', tts.rate);
      return say(cmd === 'faster' ? 'أصبح الكلام أسرع.' : 'أصبح الكلام أبطأ.');
    }
    case 'install':
      if (!install.available()) return say('التطبيق مثبت بالفعل، أو أن هذا المتصفح لا يدعم التثبيت.');
      if (install.ios) return say(IOS_INSTALL_TEXT);
      state.pendingInstall = true;
      return say('اضغط على الشاشة مرة واحدة للتثبيت، ثم اختر تثبيت.');
    case 'skip':
      store.set('basir_onboarded', '1');
      return say('حسناً. قل ابدأ عندما تريد المشي.');
    case 'nav':
      return startNav();
    case 'goal':
      return startNav(goal);
    default:
      if (normalizeArabic(question).length < 2) return say('لم أفهم. قل مساعدة لسماع الأوامر.');
      return oneShot('ask', { question });
  }
}

// ───────────────────────── الكاميرا ─────────────────────────

async function ensureCamera() {
  clearTimeout(state.cameraOffTimer);
  if (!camera.active) await camera.start();
}

/** إطفاء الكاميرا بعد دقيقة خمول لتوفير البطارية. */
function scheduleCameraOff() {
  clearTimeout(state.cameraOffTimer);
  if (state.nav || state.busy || state.listening) return;
  state.cameraOffTimer = setTimeout(() => {
    if (!state.nav && !state.busy) camera.stop();
  }, 60000);
}

function onVisibility() {
  if (document.hidden) return;
  if (state.nav) ensureCamera().catch(() => {});
}

function frameOrWarn(px = imagePx(), q = 0.6) {
  const f = camera.capture(px, q);
  if (!f) throw new Error('الكاميرا لم تبدأ بعد.');
  if (f.brightness < 16) throw new Error('المكان مظلم جداً أو الكاميرا مغطاة.');
  return f;
}

/** يذكّر بإمالة الجوال قليلاً للأسفل لرؤية الأرض والعوائق. */
function tiltHint() {
  const b = orientation.beta;
  if (b === null) return null;
  if (b > 100) return hintOnce('tilt', 'أمِل الجوال للأسفل قليلاً.');
  if (b < 40) return hintOnce('tilt', 'ارفع الجوال قليلاً، الكاميرا تنظر للأرض.');
  return null;
}

async function speakResult(res) {
  if (res.hazard) {
    vibrate([250, 100, 250, 100, 250]);
    await sounds.hazard();
  } else {
    vibrate(40);
  }
  els.meta.textContent = `${(res.latency_ms / 1000).toFixed(1)} ث · ${res.images} ${res.images === 1 ? 'صورة' : 'صور'}${res.nodes_used && res.nodes_used.length ? ' · ' + res.nodes_used.map((d) => DIR_NAMES[d]).join('، ') : ''}`;
  await say(res.say);
}

// ───────────────────────── مهمة واحدة: وصف / قراءة / سؤال ─────────────────────────

async function oneShot(mode, extra = {}) {
  state.busy = true;
  state.busyKind = mode;
  state.cancelTask = false;
  refreshUi();
  try {
    await ensureCamera().catch((e) => { if (!(state.server.nodes || []).length || mode === 'read') throw e; });
    say(mode === 'read' ? 'لحظة، أقرأ.' : 'لحظة.');
    const images = [];
    if (camera.active) {
      const f = mode === 'read' ? frameOrWarn(1280, 0.85) : frameOrWarn();
      images.push({ data: f.data, label: 'الأمام (الجوال الرئيسي)' });
    }
    const res = await api('analyze', { mode, images, include_nodes: mode !== 'read', ...extra });
    if (state.cancelTask) return;
    await speakResult(res);
  } catch (e) {
    if (!state.cancelTask) await sayError(e);
  } finally {
    state.busy = false;
    refreshUi();
  }
}

// ───────────────────────── التنقل المستمر ─────────────────────────

async function startNav(goal = '') {
  if (state.nav) {
    state.goal = goal || state.goal;
    state.history = [];
    return say(goal ? `حسناً، سأوجهك إلى ${goal}.` : 'التنقل يعمل بالفعل.');
  }
  try {
    await ensureCamera();
  } catch (e) {
    if (!(state.server.nodes || []).length) return sayError(e);
  }
  state.goal = goal;
  state.nav = true;
  state.history = [];
  wakeLock.request();
  refreshUi();
  await say(goal
    ? `سأوجهك إلى ${goal}. أمسك الجوال أمام صدرك مع إمالة بسيطة للأسفل، وامشِ ببطء.`
    : 'بدأ التنقل. أمسك الجوال أمام صدرك مع إمالة بسيطة للأسفل، وامشِ ببطء واستمع للإرشادات.');
  navLoop();
}

function stopNav() {
  state.nav = false;
  state.navRun++;
  state.goal = '';
  wakeLock.release();
  refreshUi();
}

async function navLoop() {
  const run = ++state.navRun;
  let errors = 0;
  const alive = () => state.nav && run === state.navRun;
  while (alive()) {
    if (state.navPaused || state.busy || document.hidden) { await sleep(250); continue; }
    try {
      await tiltHint();
      if (camera.stream && !camera.active) await ensureCamera();
      const images = [];
      if (camera.active) images.push({ data: frameOrWarn().data, label: 'الأمام (الجوال الرئيسي)' });
      const res = await api('analyze', { mode: 'navigate', images, include_nodes: true, goal: state.goal, history: state.history });
      if (!alive() || state.navPaused || state.busy) continue; // المستخدم بدأ يتكلم: نتجاهل الرد القديم
      errors = 0;
      await speakResult(res);
      state.history = [...state.history, res.say].slice(-3);
    } catch (e) {
      if (!alive()) break;
      errors++;
      if (errors >= 3) {
        stopNav();
        await sayError(e, ' أوقفت التنقل.');
        break;
      }
      await sayError(e);
    }
    const t0 = Date.now();
    while (alive() && Date.now() - t0 < state.intervalMs) await sleep(100);
  }
}

// ───────────────────────── التعرف على المكان: أربع جهات ─────────────────────────

/** ينتظر حتى يستدير الشخص لاتجاه معيّن، مع صفارات يرتفع صوتها كلما اقترب. */
async function waitForHeading(target, timeoutMs) {
  const t0 = Date.now();
  let lastBeep = 0;
  while (Date.now() - t0 < timeoutMs && !state.cancelTask) {
    const h = orientation.heading;
    if (h === null) { await sleep(3500); return; }
    const d = angleDiff(h, target);
    if (Math.abs(d) < 15) {
      await sleep(500);
      if (orientation.heading !== null && Math.abs(angleDiff(orientation.heading, target)) < 20) return;
    } else if (d < -25) {
      await hintOnce('overturn', 'تجاوزت. ارجع قليلاً لليسار.', 3500);
    } else if (Date.now() - lastBeep > 550) {
      lastBeep = Date.now();
      beep(420 + (1 - Math.min(90, Math.abs(d)) / 90) * 700, 70, 0.14);
    }
    await sleep(120);
  }
}

/** ينتظر حتى يكون الجوال مائلاً قليلاً للأسفل (لتظهر الأرض والعوائق). */
async function settleTilt(timeoutMs) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeoutMs && !state.cancelTask) {
    const b = orientation.beta;
    if (b === null || (b >= 45 && b <= 88)) return;
    await hintOnce('settle', b > 88 ? 'أمِل الجوال للأسفل قليلاً.' : 'ارفع الجوال قليلاً.', 2500);
    await sleep(200);
  }
}

async function runSurvey(kind, capture) {
  state.busy = true;
  state.busyKind = 'survey';
  state.cancelTask = false;
  const wasNav = state.nav;
  if (wasNav) stopNav();
  refreshUi();
  wakeLock.request();
  try {
    await ensureCamera();
    const frames = await capture();
    if (state.cancelTask || !frames) return;
    await say('تم التصوير. جارٍ تحليل المكان، قد يستغرق ذلك بعض الوقت.');
    state.busyKind = kind;
    refreshUi();
    const res = await api('analyze', { mode: 'survey', images: frames }, { timeout: 180000 });
    if (state.cancelTask) return;
    store.set('basir_onboarded', '1');
    state.server.memory = { say: res.say, summary: res.memory };
    await speakResult(res);
    if (stt.supported()) await say('حفظت وصف المكان. قل ابدأ عندما تريد المشي.', { interrupt: false });
  } catch (e) {
    if (!state.cancelTask) await sayError(e);
  } finally {
    state.busy = false;
    if (!state.nav) wakeLock.release();
    refreshUi();
  }
}

function fourDirections() {
  return runSurvey('four', async () => {
    const names = ['الأمام', 'اليمين', 'الخلف', 'اليسار'];
    await say('سأصوّر أربع جهات. أمسك الجوال أمام صدرك والشاشة نحوك، مع إمالة بسيطة للأسفل. بعد كل صورة استدر لليمين ربع دورة حتى تسمع الصفارات تعلو ثم تتوقف.');
    const start = orientation.heading;
    const frames = [];
    for (let i = 0; i < 4; i++) {
      if (state.cancelTask) return null;
      if (i > 0) {
        await say('استدر لليمين ربع دورة.');
        if (start !== null) await waitForHeading((start + 90 * i) % 360, 10000);
        else await sleep(4000);
      }
      await settleTilt(4000);
      if (state.cancelTask) return null;
      await say(`${names[i]}. اثبت.`);
      await sleep(350);
      const f = frameOrWarn(imagePx(), 0.65);
      sounds.shutter();
      vibrate(40);
      frames.push({ data: f.data, label: `${names[i]} (زاوية ${90 * i} درجة)` });
    }
    return frames;
  });
}

// ───────────────────────── التعرف على المكان: فيديو بالدوران ─────────────────────────

function videoSurvey() {
  return runSurvey('video', async () => {
    await say('سأصوّر فيديو للمكان. أمسك الجوال أمام صدرك مع إمالة بسيطة للأسفل، ثم استدر حول نفسك لليمين ببطء دورة كاملة. ابدأ بعد الصفارة.');
    await settleTilt(3000);
    if (state.cancelTask) return null;
    await sounds.listen();

    const samples = [];
    const hasCompass = orientation.heading !== null;
    let prev = orientation.heading;
    let turned = 0;
    let nextCue = 90;
    let lastSample = 0;
    let lastNudge = Date.now();
    const t0 = Date.now();
    const maxMs = hasCompass ? 30000 : 16000;

    while (!state.cancelTask && Date.now() - t0 < maxMs) {
      await sleep(120);
      const h = orientation.heading;
      if (h !== null && prev !== null) {
        turned += angleDiff(prev, h);
        prev = h;
      }
      if (Date.now() - lastSample >= 650) {
        lastSample = Date.now();
        const f = camera.capture(640, 0.6);
        if (f) samples.push({ data: f.data, turned, t: Date.now() - t0 });
      }
      if (hasCompass) {
        if (Math.abs(turned) >= nextCue && nextCue < 360) {
          tts.speak({ 90: 'ربع', 180: 'نصف', 270: 'ثلاثة أرباع' }[nextCue], { interrupt: false });
          nextCue += 90;
        }
        if (Math.abs(turned) >= 345) break;
        if (Date.now() - lastNudge > 6000 && Math.abs(turned) < 20) { lastNudge = Date.now(); tts.speak('استدر ببطء لليمين.'); }
      } else if (Date.now() - lastNudge > 5000) {
        lastNudge = Date.now();
        tts.speak('استمر بالدوران.', { interrupt: false });
      }
    }
    if (state.cancelTask) return null;
    sounds.done();
    if (samples.length < 4) throw new Error('لم ألتقط صوراً كافية. حاول مرة أخرى.');

    // اختيار 8 لقطات تغطي الدورة كلها
    const N = 8;
    const picked = [];
    if (hasCompass && Math.abs(turned) > 90) {
      const sign = turned >= 0 ? 1 : -1;
      for (let k = 0; k < N; k++) {
        const target = sign * (k * 360) / N;
        let best = samples[0];
        for (const s of samples) if (Math.abs(s.turned - target) < Math.abs(best.turned - target)) best = s;
        if (!picked.includes(best)) picked.push(best);
      }
      return picked.map((s) => {
        const a = Math.round(((s.turned % 360) + 360) % 360);
        return { data: s.data, label: `زاوية ${a} درجة — ${angleName(a)}` };
      });
    }
    for (let k = 0; k < N; k++) picked.push(samples[Math.min(samples.length - 1, Math.floor(((k + 0.5) * samples.length) / N))]);
    return picked.map((s, k) => {
      const a = Math.round((360 * (k + 0.5)) / N);
      return { data: s.data, label: `لقطة ${k + 1} من ${N} — تقريباً زاوية ${a} درجة (${angleName(a)})` };
    });
  });
}

// ───────────────────────── الجوالات الإضافية ─────────────────────────

async function pollNodes() {
  if (document.hidden || !state.ready) return;
  try {
    const r = await api('nodes', null, { timeout: 8000 });
    const now = new Set(r.nodes.map((n) => n.dir));
    const msgs = [];
    for (const n of r.nodes) if (!state.knownNodes.has(n.dir)) msgs.push(`تم ربط جوال ${n.name}.`);
    for (const d of state.knownNodes) if (!now.has(d)) msgs.push(`انقطع جوال ${DIR_NAMES[d]}.`);
    state.knownNodes = now;
    state.server.nodes = r.nodes;
    if (msgs.length && state.welcomed && !state.listening) tts.speak(msgs.join(' '), { interrupt: false });
  } catch { /* الخادم مشغول */ }
}

// ───────────────────────── لوحة المفاتيح (ويندوز) واختصار الصوت ─────────────────────────

let volPresses = [];
document.addEventListener('keydown', (e) => {
  if (e.target && /INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
  // اختصار: ضغط خفض الصوت 3 مرات (يعمل في تطبيق الأندرويد، وفي بعض المتصفحات)
  if (e.key === 'AudioVolumeDown' || e.key === 'VolumeDown') {
    const now = Date.now();
    volPresses = [...volPresses.filter((t) => now - t < 1200), now];
    if (volPresses.length >= 3) { volPresses = []; onMain(); }
    return;
  }
  if (e.ctrlKey || e.metaKey || e.altKey || !state.ready) return;
  const k = e.key.toLowerCase();
  const map = { n: 'ابدا', d: 'ماذا امامي', r: 'اقرا', f: 'اربع جهات', v: 'فيديو', p: 'الموفر', h: 'مساعده', escape: 'توقف' };
  if (map[k]) {
    e.preventDefault();
    unlockAudio();
    orientation.enable();
    state.welcomed = true;
    runCommand(map[k]).finally(refreshUi);
  }
});

// أي لمسة على الشاشة = زر الميكروفون (عدا شريط التثبيت)
document.addEventListener('click', (e) => {
  if (e.target.closest('#install')) return;
  onMain();
});

boot();
