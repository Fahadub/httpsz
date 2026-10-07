// بصير — الشاشة الرئيسية للكفيف: زر ميكروفون واحد يملأ الشاشة، وكل شيء آخر بالصوت.
import {
  api, apiBase, tts, stt, Camera, orientation, wakeLock, sounds, beep, vibrate, unlockAudio, resumeAudio,
  normalizeArabic, speakable, angleDiff, angleName, sleep, store, isNative, dirName,
  setupInstall, registerSW,
} from './core.js';
import { parseCommand } from './commands.js';
import { t, setLang, getLang, listSep, plain } from './i18n.js';

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
  loading: ['mode.loading', ''],
  idle: ['mode.idle', 'mode.idle.sub'],
  idleNoStt: ['mode.idleNoStt', 'mode.idleNoStt.sub'],
  listening: ['mode.listening', 'mode.listening.sub'],
  thinking: ['mode.thinking', 'mode.cancel.sub'],
  survey: ['mode.survey', 'mode.cancel.sub'],
  nav: ['mode.nav', 'mode.nav.sub'],
  navNoStt: ['mode.nav', 'mode.navNoStt.sub'],
  error: ['mode.error', ''],
};

function ui(mode) {
  const [mainKey, subKey] = MODES[mode] || MODES.idle;
  const main = t(mainKey);
  const sub = subKey ? t(subKey) : '';
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
  els.result.textContent = plain(text);
}

function say(text, opts) {
  if (!text) return Promise.resolve();
  state.lastSay = text;
  show(text);
  return tts.speak(text, opts);
}

function sayError(e, suffix = '') {
  const msg = (e && e.message) || t('net.serverError');
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

/** لغة الجهاز: اختيار الكفيف بالصوت (محفوظ على الجهاز) وإلا لغة الخادم الافتراضية. */
function currentLanguage() {
  return store.get('basir_lang') || state.server.settings.language || 'ar';
}

function applyLanguage() {
  const lang = setLang(currentLanguage());
  const s = state.server.settings;
  document.documentElement.lang = lang;
  document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
  const voices = state.server.voices || {};
  tts.configure({
    lang,
    locale: lang === 'en' ? s.speech_lang_en : s.speech_lang,
    serverVoices: { ar: !!(voices.ar && voices.ar.ready), en: !!(voices.en && voices.en.ready) },
  });
}

function switchLanguage(lang) {
  store.set('basir_lang', lang);
  applyLanguage();
  refreshUi();
}

function applySettings(s) {
  const localRate = Number(store.get('basir_rate', 0));
  tts.configure({ rate: localRate || s.speech_rate });
  applyLanguage();
  state.imagePx = s.image_px || 768;
  state.intervalMs = Math.round((s.interval_s || 2) * 1000);
}

function providerText() {
  const p = state.server.provider;
  return t('provider', { label: p.label, model: speakable(p.model), hasKey: p.has_key });
}

function devicesText() {
  const nodes = (state.server && state.server.nodes) || [];
  const host = apiBase() ? new URL(apiBase()).host : location.host;
  const link = `${host}/cam.html`;
  if (!nodes.length) return t('devices.none', { link });
  return t('devices.some', { count: nodes.length, names: nodes.map((n) => dirName(n.dir)).join(listSep()) });
}

function welcomeText() {
  const p = state.server.provider;
  const first = !store.get('basir_welcomed');
  let s = first ? t('welcome.first', { provider: providerText() }) : t('welcome.back', { label: p.label, model: speakable(p.model) });
  const nodes = state.server.nodes || [];
  if (nodes.length) s += ` ${devicesText()}`;
  if (state.server.memory && first) s += t('welcome.memory');
  if (stt.supported()) s += first ? t('welcome.tapTalkFirst') : t('welcome.tapTalk');
  else s += t('welcome.tapToggle');
  if (!store.get('basir_onboarded') && !state.server.memory && stt.supported()) s += t('welcome.firstTime');
  if (install.available()) s += t('welcome.install');
  return s;
}

async function boot() {
  registerSW();
  install = setupInstall({
    onInstalled: () => say(t('installed')),
  });

  ui('loading');
  try {
    state.server = await api('status');
  } catch (e) {
    ui('error');
    show(e.message + (isNative ? t('server.openSetup') : ''));
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
    show(t('insecure'));
  }

  setInterval(pollNodes, 6000);
  document.addEventListener('visibilitychange', onVisibility);
  window.basirShortcut = () => onMain();

  // محاولة الترحيب دون لمس (تنجح في تطبيق الجوال وبعض التطبيقات المثبتة)
  if (isNative) state.welcomed = true; // الكلام في تطبيق الجوال لا يحتاج لمسة أولى
  const speaking = say(welcomeText());
  let done = false;
  speaking.then(() => { done = true; });
  // بمجرد أن يبدأ الصوت فعلاً نعتبر الترحيب تم: لمسة أثناءه تعني «أريد التكلم» لا «أعد الترحيب»
  while (!done && tts.allowed !== true) await sleep(100);
  if (tts.allowed === true) {
    state.welcomed = true;
    store.set('basir_welcomed', '1');
    await speaking;
    runStartAction();
  } else {
    show(t('tapToStart'));
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
  // الترحيب يعمل الآن بالفعل (الصوت مسموح): اللمسة تعني «أريد التكلم»
  if (!state.welcomed && tts.allowed === true) state.welcomed = true;
  if (!state.welcomed) {
    state.welcomed = true;
    say(welcomeText()).then(() => { if (state.startAction) runStartAction(); });
    store.set('basir_welcomed', '1');
    return;
  }
  if (state.busy) {
    state.cancelTask = true;
    say(t('cancelled'));
    return;
  }
  if (state.listening) {
    stt.abort();
    return;
  }
  if (!stt.supported()) {
    if (state.nav) { stopNav(); say(t('navStopped')); } else startNav();
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
  const heard = stt.listen(tts.locale);
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
    return say(t('didntHear'));
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
  if (code === 'not-allowed') return say(t('mic.denied'));
  if (code === 'audio-capture') return say(t('mic.none'));
  if (code === 'network') return say(t('mic.network'));
  if (code === 'service-not-allowed' || code === 'language-not-supported') {
    // المتصفح لا يسمح بالتعرف على الكلام هنا → وضع اللمس
    stt.disabled = true;
    refreshUi();
    return say(t('mic.unavailable'));
  }
  return say(t('mic.failed'));
}

async function runCommand(text) {
  const { cmd, goal, question, lang } = parseCommand(text);
  switch (cmd) {
    case 'stop':
      state.cancelTask = true;
      if (state.nav) stopNav();
      return say(t('stopped'));
    case 'repeat':
      return say(state.lastSay || t('nothingToRepeat'));
    case 'help':
      return say(t('help'));
    case 'provider':
      return say(providerText());
    case 'settings':
      await say(t('openingSettings'));
      location.href = 'setup.html';
      return;
    case 'devices':
      return say(devicesText());
    case 'forget':
      try {
        await api('memory_clear', {});
        state.server.memory = null;
        return say(t('forgot'));
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
      return say(t(cmd));
    }
    case 'install':
      if (!install.available()) return say(t('install.none'));
      if (install.ios) return say(t('install.ios'));
      state.pendingInstall = true;
      return say(t('install.tap'));
    case 'skip':
      store.set('basir_onboarded', '1');
      return say(t('skipped'));
    case 'lang':
      switchLanguage(lang);
      return say(t('langSwitched'));
    case 'nav':
      return startNav();
    case 'goal':
      return startNav(goal);
    default:
      if (normalizeArabic(question).length < 2) return say(t('notUnderstood'));
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
  resumeAudio();
  if (state.nav) ensureCamera().catch(() => {});
}

function frameOrWarn(px = imagePx(), q = 0.6) {
  const f = camera.capture(px, q);
  if (!f) throw new Error(t('cam.notStarted'));
  if (f.brightness < 16) throw new Error(t('cam.dark'));
  return f;
}

/** يذكّر بإمالة الجوال قليلاً للأسفل لرؤية الأرض والعوائق. */
function tiltHint() {
  const b = orientation.beta;
  if (b === null) return null;
  if (b > 100) return hintOnce('tilt', t('tilt.down'));
  if (b < 40) return hintOnce('tilt', t('tilt.up'));
  return null;
}

async function speakResult(res) {
  if (res.hazard) {
    vibrate([250, 100, 250, 100, 250]);
    await sounds.hazard();
  } else {
    vibrate(40);
  }
  els.meta.textContent = plain(t('meta', {
    sec: (res.latency_ms / 1000).toFixed(1),
    images: res.images,
    dirs: (res.nodes_used || []).map(dirName).join(listSep()),
  }));
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
    say(t(mode === 'read' ? 'momentRead' : 'moment'));
    const images = [];
    if (camera.active) {
      const f = mode === 'read' ? frameOrWarn(1280, 0.85) : frameOrWarn();
      images.push({ data: f.data, label: t('label.main') });
    }
    const res = await api('analyze', { mode, images, include_nodes: mode !== 'read', lang: getLang(), ...extra });
    if (state.cancelTask) return;
    // انتهى العمل: أثناء نطق الجواب، اللمسة تعني سؤالاً جديداً لا «إلغاء»
    state.busy = false;
    refreshUi();
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
    return say(goal ? t('nav.goalUpdated', { goal }) : t('nav.already'));
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
  await say(goal ? t('nav.startGoal', { goal }) : t('nav.start'));
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
      if (camera.active) images.push({ data: frameOrWarn().data, label: t('label.main') });
      const res = await api('analyze', { mode: 'navigate', images, include_nodes: true, goal: state.goal, history: state.history, lang: getLang() });
      if (!alive() || state.navPaused || state.busy) continue; // المستخدم بدأ يتكلم: نتجاهل الرد القديم
      errors = 0;
      await speakResult(res);
      state.history = [...state.history, res.say].slice(-3);
    } catch (e) {
      if (!alive()) break;
      errors++;
      if (errors >= 3) {
        stopNav();
        await sayError(e, t('nav.stoppedSuffix'));
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
      await hintOnce('overturn', t('survey.overturn'), 3500);
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
    await hintOnce('settle', b > 88 ? t('tilt.down') : t('tilt.upShort'), 2500);
    await sleep(200);
  }
}

async function runSurvey(kind, capture) {
  state.busy = true;
  state.busyKind = 'survey';
  state.cancelTask = false;
  if (state.nav) stopNav();
  refreshUi();
  wakeLock.request();
  try {
    await ensureCamera();
    const frames = await capture();
    if (state.cancelTask || !frames) return;
    await say(t('survey.analyzing'));
    state.busyKind = kind;
    refreshUi();
    const res = await api('analyze', { mode: 'survey', images: frames, lang: getLang() }, { timeout: 180000 });
    if (state.cancelTask) return;
    store.set('basir_onboarded', '1');
    state.server.memory = { say: res.say, summary: res.memory };
    state.busy = false;
    refreshUi();
    await speakResult(res);
    if (stt.supported()) await say(t('survey.saved'), { interrupt: false });
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
    const names = ['front', 'right', 'back', 'left'].map(dirName);
    await say(t('four.intro'));
    const start = orientation.heading;
    const frames = [];
    for (let i = 0; i < 4; i++) {
      if (state.cancelTask) return null;
      if (i > 0) {
        await say(t('four.turn'));
        if (start !== null) await waitForHeading((start + 90 * i) % 360, 10000);
        else await sleep(4000);
      }
      await settleTilt(4000);
      if (state.cancelTask) return null;
      await say(t('four.hold', { name: names[i] }));
      await sleep(350);
      const f = frameOrWarn(imagePx(), 0.65);
      sounds.shutter();
      vibrate(40);
      frames.push({ data: f.data, label: t('four.label', { name: names[i], angle: 90 * i }) });
    }
    return frames;
  });
}

// ───────────────────────── التعرف على المكان: فيديو بالدوران ─────────────────────────

function videoSurvey() {
  return runSurvey('video', async () => {
    await say(t('video.intro'));
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
          tts.speak(t({ 90: 'video.q1', 180: 'video.q2', 270: 'video.q3' }[nextCue]), { interrupt: false });
          nextCue += 90;
        }
        if (Math.abs(turned) >= 345) break;
        if (Date.now() - lastNudge > 6000 && Math.abs(turned) < 20) { lastNudge = Date.now(); tts.speak(t('video.turnSlowly')); }
      } else if (Date.now() - lastNudge > 5000) {
        lastNudge = Date.now();
        tts.speak(t('video.keepTurning'), { interrupt: false });
      }
    }
    if (state.cancelTask) return null;
    sounds.done();
    if (samples.length < 4) throw new Error(t('video.notEnough'));

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
        return { data: s.data, label: t('video.angleLabel', { a, name: angleName(a) }) };
      });
    }
    for (let k = 0; k < N; k++) picked.push(samples[Math.min(samples.length - 1, Math.floor(((k + 0.5) * samples.length) / N))]);
    return picked.map((s, k) => {
      const a = Math.round((360 * (k + 0.5)) / N);
      return { data: s.data, label: t('video.approxLabel', { k: k + 1, n: N, a, name: angleName(a) }) };
    });
  });
}

// ───────────────────────── الجوالات الإضافية ─────────────────────────

let voiceCheckAt = 0;
/** الصوت المدمج لم يكن جاهزاً عند الفتح (ما زال يُحمَّل أو أُعيد تشغيله): نعيد السؤال كل نصف دقيقة. */
async function recheckVoices() {
  const v = state.server.voices || {};
  if ((v[getLang()] || {}).ready || Date.now() - voiceCheckAt < 30000) return;
  voiceCheckAt = Date.now();
  try {
    const s = await api('status', null, { timeout: 8000 });
    if (s.voices && (s.voices[getLang()] || {}).ready) {
      state.server.voices = s.voices;
      applyLanguage();
    }
  } catch { /* الخادم مشغول */ }
}

async function pollNodes() {
  if (document.hidden || !state.ready) return;
  recheckVoices();
  try {
    const r = await api('nodes', null, { timeout: 8000 });
    const now = new Set(r.nodes.map((n) => n.dir));
    const msgs = [];
    for (const n of r.nodes) if (!state.knownNodes.has(n.dir)) msgs.push(t('node.linked', { name: dirName(n.dir) }));
    for (const d of state.knownNodes) if (!now.has(d)) msgs.push(t('node.lost', { name: dirName(d) }));
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
