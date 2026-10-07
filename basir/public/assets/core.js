// بصير — أدوات مشتركة: الاتصال بالخادم، الكلام، التعرف على الصوت، الكاميرا، البوصلة، التثبيت.

export const isNative = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
const plugins = () => (window.Capacitor && window.Capacitor.Plugins) || {};

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export const store = {
  get(k, d = null) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch { return d; } },
  set(k, v) { try { localStorage.setItem(k, String(v)); } catch { /* وضع خاص */ } },
  del(k) { try { localStorage.removeItem(k); } catch { /* */ } },
};

// ───────────────────────── الخادم ─────────────────────────

/** عنوان خادم بصير. فارغ = نفس الموقع. تطبيق الجوال يحفظه مرة واحدة. */
export function apiBase() {
  return (store.get('basir_api_base') || window.BASIR_API_BASE || '').replace(/\/+$/, '');
}
export function setApiBase(url) {
  const v = String(url || '').trim().replace(/\/+$/, '');
  if (v) store.set('basir_api_base', v); else store.del('basir_api_base');
}

export class ApiError extends Error {
  constructor(message, status = 0, detail = '') { super(message); this.status = status; this.detail = detail; }
}

export async function api(action, body = null, { timeout = 90000, base = apiBase() } = {}) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeout);
  const url = (base ? base + '/' : '') + 'api.php?action=' + encodeURIComponent(action);
  const init = body === null
    ? { cache: 'no-store', signal: ctrl.signal }
    : { method: 'POST', cache: 'no-store', signal: ctrl.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
  try {
    const res = await fetch(url, init);
    let data;
    try { data = await res.json(); } catch { throw new ApiError('رد غير مفهوم من خادم بصير.', res.status); }
    if (!res.ok || data.ok === false) throw new ApiError(data.error || 'خطأ من الخادم.', res.status, data.detail || '');
    return data;
  } catch (e) {
    if (e instanceof ApiError) throw e;
    if (e && e.name === 'AbortError') throw new ApiError('انتهت مهلة الاتصال. حاول مرة أخرى.', 0);
    throw new ApiError('تعذر الاتصال بخادم بصير. تأكد أنه يعمل وأن الجوال على نفس الشبكة.', 0, String(e));
  } finally {
    clearTimeout(timer);
  }
}

// ───────────────────────── النص العربي ─────────────────────────

export function normalizeArabic(s) {
  return String(s || '')
    .toLowerCase()
    .replace(/[ً-ٰٟـ]/g, '')
    .replace(/[أإآٱ]/g, 'ا')
    .replace(/ى/g, 'ي')
    .replace(/ة/g, 'ه')
    .replace(/ؤ/g, 'و')
    .replace(/ئ/g, 'ي')
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** يجعل أسماء النماذج مقروءة صوتياً: gpt-4.1-mini → gpt 4.1 mini */
export function speakable(s) {
  return String(s || '').replace(/^models\//, '').replace(/[-_/:]+/g, ' ').trim();
}

export const DIR_NAMES = { front: 'الأمام', right: 'اليمين', back: 'الخلف', left: 'اليسار' };

export function angleName(a) {
  a = ((a % 360) + 360) % 360;
  if (a < 22.5 || a >= 337.5) return 'الأمام';
  if (a < 67.5) return 'أمام اليمين';
  if (a < 112.5) return 'اليمين';
  if (a < 157.5) return 'خلف اليمين';
  if (a < 202.5) return 'الخلف';
  if (a < 247.5) return 'خلف اليسار';
  if (a < 292.5) return 'اليسار';
  return 'أمام اليسار';
}

/** الفرق بالإشارة بين زاويتين (-180..180). موجب = الدوران لليمين. */
export const angleDiff = (from, to) => ((to - from + 540) % 360) - 180;

// ───────────────────────── الكلام (TTS) ─────────────────────────

export const tts = {
  lang: 'ar-SA',
  rate: 1,
  voice: null,
  /** null = غير معروف، true = المتصفح سمح بالكلام، false = يحتاج لمسة أولاً */
  allowed: null,
  _queue: Promise.resolve(),
  _gen: 0,
  _cancelAt: 0,

  configure({ lang, rate } = {}) {
    if (lang) this.lang = lang;
    if (rate) this.rate = Number(rate) || 1;
    this._pickVoice();
  },

  _pickVoice() {
    if (!('speechSynthesis' in window)) return;
    const voices = speechSynthesis.getVoices() || [];
    const norm = (l) => String(l || '').replace('_', '-').toLowerCase();
    const want = norm(this.lang);
    const base = want.split('-')[0];
    this.voice = voices.find((v) => norm(v.lang) === want)
      || voices.find((v) => norm(v.lang).startsWith(base + '-') || norm(v.lang) === base)
      || null;
  },

  /** آيفون: أول كلام يجب أن يبدأ داخل لمسة المستخدم مباشرة. نستدعيها بشكل متزامن في معالج اللمس. */
  unlock() {
    if (this._unlocked || isNative || !('speechSynthesis' in window)) return;
    this._unlocked = true;
    const u = new SpeechSynthesisUtterance(' ');
    u.volume = 0;
    speechSynthesis.speak(u);
  },

  hasLangVoice() {
    if (isNative) return true;
    this._pickVoice();
    return !!this.voice;
  },

  /** يتكلم ويُرجع وعداً ينتهي عند انتهاء الكلام. interrupt=false يضيفه للطابور. */
  speak(text, { interrupt = true } = {}) {
    if (interrupt) this.cancel();
    const gen = this._gen;
    const run = () => (gen === this._gen ? this._speakNow(text) : undefined);
    this._queue = this._queue.then(run, run);
    return this._queue;
  },

  cancel() {
    this._gen++;
    this._queue = Promise.resolve();
    this._cancelAt = Date.now();
    const T = plugins().TextToSpeech;
    if (isNative && T) { T.stop().catch(() => {}); return; }
    if ('speechSynthesis' in window) speechSynthesis.cancel();
  },

  async _speakNow(text) {
    text = String(text || '').trim();
    if (!text) return;
    const T = plugins().TextToSpeech;
    if (isNative && T) {
      this.allowed = true;
      try { await T.speak({ text, lang: this.lang, rate: this.rate, category: 'playback' }); } catch { /* أُلغي */ }
      return;
    }
    if (!('speechSynthesis' in window)) return;
    // كروم يُسقط أحياناً الكلام الذي يأتي مباشرة بعد cancel
    const since = Date.now() - this._cancelAt;
    if (since < 120) await sleep(120 - since);
    if (!this.voice) this._pickVoice();
    await new Promise((resolve) => {
      let u;
      try {
        u = new SpeechSynthesisUtterance(text);
        u.lang = this.lang;
        u.rate = this.rate;
        if (this.voice) u.voice = this.voice;
      } catch {
        if (!u) { resolve(); return; }
      }
      let done = false;
      const finish = () => { if (!done) { done = true; clearTimeout(timer); resolve(); } };
      // بعض أجهزة أندرويد لا تُطلق onend أبداً
      const timer = setTimeout(finish, 3000 + (text.length * 110) / this.rate);
      u.onstart = () => { this.allowed = true; };
      u.onend = finish;
      u.onerror = (e) => { if (e.error === 'not-allowed') this.allowed = false; finish(); };
      try { speechSynthesis.speak(u); } catch { finish(); }
    });
  },
};
if ('speechSynthesis' in window) {
  speechSynthesis.addEventListener?.('voiceschanged', () => tts._pickVoice());
}

// ───────────────────────── التعرف على الكلام (STT) ─────────────────────────

export const stt = {
  disabled: false,
  _rec: null,

  supported() {
    if (this.disabled) return false;
    if (isNative) return !!plugins().SpeechRecognition;
    return !!(window.SpeechRecognition || window.webkitSpeechRecognition);
  },

  /** يستمع لجملة واحدة ويُرجع النص ('' إن لم يُسمع شيء). يرمي خطأ فيه code عند منع الإذن. */
  async listen(lang, { timeoutMs = 9000 } = {}) {
    const SRP = plugins().SpeechRecognition;
    if (isNative && SRP) {
      const fail = (code) => Object.assign(new Error(code), { code });
      let perm = await SRP.checkPermissions().catch(() => ({}));
      if (perm.speechRecognition !== 'granted') perm = await SRP.requestPermissions().catch(() => ({}));
      if (perm.speechRecognition !== 'granted') throw fail('not-allowed');
      const timer = setTimeout(() => SRP.stop().catch(() => {}), timeoutMs);
      try {
        const res = await SRP.start({ language: lang, maxResults: 1, partialResults: false, popup: false });
        return ((res && res.matches && res.matches[0]) || '').trim();
      } catch (e) {
        const msg = String((e && e.message) || e).toLowerCase();
        if (msg.includes('permission')) throw fail('not-allowed');
        return '';
      } finally {
        clearTimeout(timer);
      }
    }

    const R = window.SpeechRecognition || window.webkitSpeechRecognition;
    return new Promise((resolve, reject) => {
      const rec = new R();
      this._rec = rec;
      rec.lang = lang;
      rec.interimResults = false;
      rec.maxAlternatives = 1;
      rec.continuous = false;
      let text = '';
      let err = null;
      const timer = setTimeout(() => { try { rec.stop(); } catch { /* */ } }, timeoutMs);
      rec.onresult = (e) => { text = Array.from(e.results).map((r) => r[0].transcript).join(' '); };
      rec.onerror = (e) => { err = e.error; };
      rec.onend = () => {
        clearTimeout(timer);
        this._rec = null;
        if (err && err !== 'no-speech' && err !== 'aborted') reject(Object.assign(new Error(err), { code: err }));
        else resolve(text.trim());
      };
      try { rec.start(); } catch (e) { clearTimeout(timer); this._rec = null; reject(Object.assign(new Error('start-failed'), { code: 'start-failed', cause: e })); }
    });
  },

  abort() {
    try { this._rec && this._rec.abort(); } catch { /* */ }
    const SRP = plugins().SpeechRecognition;
    if (isNative && SRP) SRP.stop().catch(() => {});
  },
};

// ───────────────────────── أصوات واهتزاز ─────────────────────────

let actx = null;
export function unlockAudio() {
  try {
    actx = actx || new (window.AudioContext || window.webkitAudioContext)();
    if (actx.state === 'suspended') actx.resume();
  } catch { /* لا صوت */ }
}

export function beep(freq = 880, ms = 120, vol = 0.18) {
  if (!actx) return Promise.resolve();
  return new Promise((resolve) => {
    try {
      const o = actx.createOscillator();
      const g = actx.createGain();
      o.frequency.value = freq;
      o.type = 'sine';
      g.gain.setValueAtTime(vol, actx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + ms / 1000);
      o.connect(g).connect(actx.destination);
      o.start();
      o.stop(actx.currentTime + ms / 1000);
      o.onended = resolve;
      setTimeout(resolve, ms + 80);
    } catch { resolve(); }
  });
}

export const sounds = {
  listen: () => beep(880, 140),
  done: () => beep(660, 110),
  shutter: () => beep(1500, 60, 0.12),
  hazard: async () => { await beep(320, 220, 0.3); await beep(320, 220, 0.3); },
};

export function vibrate(pattern) {
  try { navigator.vibrate && navigator.vibrate(pattern); } catch { /* */ }
}

// ───────────────────────── الكاميرا ─────────────────────────

export class Camera {
  constructor(video) {
    this.video = video;
    this.stream = null;
    this.canvas = document.createElement('canvas');
    this.small = document.createElement('canvas');
    this.small.width = 32;
    this.small.height = 24;
  }

  get active() {
    return !!(this.stream && this.stream.active && this.video.videoWidth > 0);
  }

  async start() {
    if (this.active) return;
    this.stop();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      throw new Error(window.isSecureContext
        ? 'هذا المتصفح لا يدعم الكاميرا.'
        : 'الكاميرا تحتاج اتصالاً آمناً HTTPS. افتح التطبيق عبر رابط آمن أو من نفس الجهاز localhost.');
    }
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
      });
    } catch (e) {
      const name = e && e.name;
      if (name === 'NotAllowedError' || name === 'SecurityError') throw new Error('لم يُسمح باستخدام الكاميرا. اسمح بها من إعدادات المتصفح.');
      if (name === 'NotFoundError' || name === 'OverconstrainedError') throw new Error('لا توجد كاميرا في هذا الجهاز.');
      if (name === 'NotReadableError') throw new Error('الكاميرا مستخدمة من تطبيق آخر.');
      throw new Error('تعذر تشغيل الكاميرا.');
    }
    const v = this.video;
    v.muted = true;
    v.setAttribute('playsinline', '');
    v.srcObject = this.stream;
    await v.play().catch(() => {});
    const t0 = Date.now();
    while (!v.videoWidth && Date.now() - t0 < 4000) await sleep(50);
  }

  stop() {
    if (this.stream) this.stream.getTracks().forEach((t) => t.stop());
    this.stream = null;
    if (this.video) this.video.srcObject = null;
  }

  /** لقطة من الفيديو داخل التطبيق فقط. تُرجع base64 بدون بادئة + متوسط الإضاءة (0-255). */
  capture(maxPx = 768, quality = 0.6) {
    const v = this.video;
    const w0 = v.videoWidth;
    const h0 = v.videoHeight;
    if (!w0 || !h0) return null;
    const s = Math.min(1, maxPx / Math.max(w0, h0));
    const w = Math.round(w0 * s);
    const h = Math.round(h0 * s);
    this.canvas.width = w;
    this.canvas.height = h;
    this.canvas.getContext('2d').drawImage(v, 0, 0, w, h);
    const url = this.canvas.toDataURL('image/jpeg', quality);
    return { data: url.slice(url.indexOf(',') + 1), brightness: this._brightness(), width: w, height: h };
  }

  _brightness() {
    try {
      const c = this.small.getContext('2d', { willReadFrequently: true });
      c.drawImage(this.video, 0, 0, 32, 24);
      const px = c.getImageData(0, 0, 32, 24).data;
      let sum = 0;
      for (let i = 0; i < px.length; i += 4) sum += 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
      return sum / (px.length / 4);
    } catch {
      return 128;
    }
  }
}

/** يقتطع لقطات موزعة بالتساوي من ملف فيديو (لتدريب التطبيق على المكان من فيديو مسجّل). */
export async function framesFromVideoFile(file, count = 8, maxPx = 640) {
  const url = URL.createObjectURL(file);
  const v = document.createElement('video');
  v.muted = true;
  v.playsInline = true;
  v.preload = 'auto';
  v.src = url;
  try {
    await new Promise((resolve, reject) => {
      v.onloadeddata = resolve;
      v.onerror = () => reject(new Error('تعذر قراءة الفيديو. جرّب صيغة MP4.'));
    });
    const dur = v.duration;
    if (!isFinite(dur) || dur <= 0) throw new Error('مدة الفيديو غير معروفة. جرّب ملف MP4.');
    const canvas = document.createElement('canvas');
    const frames = [];
    for (let i = 0; i < count; i++) {
      const t = (dur * (i + 0.5)) / count;
      await new Promise((resolve) => {
        const done = () => { v.removeEventListener('seeked', done); resolve(); };
        v.addEventListener('seeked', done);
        v.currentTime = t;
        setTimeout(done, 3000);
      });
      const s = Math.min(1, maxPx / Math.max(v.videoWidth, v.videoHeight));
      canvas.width = Math.round(v.videoWidth * s);
      canvas.height = Math.round(v.videoHeight * s);
      canvas.getContext('2d').drawImage(v, 0, 0, canvas.width, canvas.height);
      const data = canvas.toDataURL('image/jpeg', 0.65);
      const angle = Math.round((360 * (i + 0.5)) / count);
      frames.push({
        data: data.slice(data.indexOf(',') + 1),
        preview: data,
        label: `لقطة ${i + 1} من ${count} عند الثانية ${t.toFixed(1)} — إذا كان التصوير دورة كاملة فالزاوية تقريباً ${angle} درجة (${angleName(angle)})`,
      });
    }
    return frames;
  } finally {
    URL.revokeObjectURL(url);
  }
}

// ───────────────────────── البوصلة والميلان ─────────────────────────

/** اتجاه الكاميرا الخلفية (بالدرجات، مع عقارب الساعة) من زوايا الجهاز — يعمل والجوال قائم. */
function rearCameraHeading(alpha, beta, gamma) {
  const r = Math.PI / 180;
  const sX = Math.sin(beta * r);
  const cY = Math.cos(gamma * r), sY = Math.sin(gamma * r);
  const cZ = Math.cos(alpha * r), sZ = Math.sin(alpha * r);
  const vx = -cZ * sY - sZ * sX * cY;
  const vy = -sZ * sY + cZ * sX * cY;
  return ((Math.atan2(vx, vy) / r) + 360) % 360;
}

export const orientation = {
  heading: null,
  beta: null,
  listening: false,

  /** يجب استدعاؤها داخل لمسة المستخدم (آيفون يطلب إذناً). */
  enable() {
    if (this.listening) return Promise.resolve(true);
    const DOE = window.DeviceOrientationEvent;
    if (!DOE) return Promise.resolve(false);
    const attach = () => {
      if (this.listening) return true;
      const handler = (e) => {
        if (e.beta === null || e.beta === undefined) return;
        this.beta = e.beta;
        if (e.alpha !== null && e.alpha !== undefined) this.heading = rearCameraHeading(e.alpha, e.beta, e.gamma || 0);
      };
      window.addEventListener('ondeviceorientationabsolute' in window ? 'deviceorientationabsolute' : 'deviceorientation', handler);
      this.listening = true;
      return true;
    };
    if (typeof DOE.requestPermission === 'function') {
      return DOE.requestPermission().then((s) => (s === 'granted' ? attach() : false)).catch(() => false);
    }
    return Promise.resolve(attach());
  },
};

// ───────────────────────── إبقاء الشاشة مضاءة ─────────────────────────

export const wakeLock = {
  lock: null,
  wanted: false,
  async request() {
    this.wanted = true;
    try {
      if ('wakeLock' in navigator && !this.lock) {
        this.lock = await navigator.wakeLock.request('screen');
        this.lock.addEventListener('release', () => { this.lock = null; });
      }
    } catch { /* غير مدعوم */ }
  },
  release() {
    this.wanted = false;
    if (this.lock) this.lock.release().catch(() => {});
    this.lock = null;
  },
};
document.addEventListener('visibilitychange', () => {
  if (!document.hidden && wakeLock.wanted) wakeLock.request();
});

// ───────────────────────── التثبيت (PWA) ─────────────────────────

export const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
export const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

export const IOS_INSTALL_TEXT = 'لتثبيت بصير على الآيفون: افتح الموقع في سفاري، اضغط زر المشاركة أسفل الشاشة، ثم اختر: إضافة إلى الشاشة الرئيسية.';

/**
 * إشعار التثبيت: أندرويد وويندوز (كروم وإيدج) عبر beforeinstallprompt، والآيفون بتعليمات.
 * يحتاج عنصراً #install فيه #install-text و #install-btn و #install-close.
 */
export function setupInstall({ onInstalled, onAvailable } = {}) {
  const el = document.getElementById('install');
  const ctl = { ios: false, available: () => false, prompt: async () => false };
  if (!el || isNative || isStandalone()) return ctl;

  const text = el.querySelector('#install-text');
  const btn = el.querySelector('#install-btn');
  const close = el.querySelector('#install-close');
  const dismissedAt = Number(store.get('basir_install_dismissed', 0));
  const recentlyDismissed = Date.now() - dismissedAt < 3 * 24 * 3600 * 1000;
  let deferred = null;

  const show = () => { if (!recentlyDismissed) el.hidden = false; onAvailable && onAvailable(); };
  const hide = () => { el.hidden = true; };

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferred = e;
    text.textContent = 'ثبّت «بصير» على جهازك ليفتح كتطبيق مستقل من الشاشة الرئيسية.';
    btn.textContent = 'تثبيت التطبيق';
    show();
  });
  window.addEventListener('appinstalled', () => { deferred = null; hide(); onInstalled && onInstalled(); });

  ctl.prompt = async () => {
    if (!deferred) return false;
    const ev = deferred;
    deferred = null;
    ev.prompt();
    const choice = await ev.userChoice.catch(() => ({ outcome: 'dismissed' }));
    hide();
    return choice.outcome === 'accepted';
  };
  ctl.available = () => !!deferred || ctl.ios;

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (ctl.ios) tts.speak(IOS_INSTALL_TEXT);
    else ctl.prompt();
  });
  close.addEventListener('click', (e) => {
    e.stopPropagation();
    store.set('basir_install_dismissed', Date.now());
    hide();
  });

  if (isIOS) {
    ctl.ios = true;
    text.textContent = 'لتثبيت بصير على الآيفون: اضغط زر المشاركة ⬆️ في سفاري ثم «إضافة إلى الشاشة الرئيسية».';
    btn.textContent = 'اسمع الطريقة';
    show();
  }
  return ctl;
}

export function registerSW() {
  if ('serviceWorker' in navigator && !isNative && window.isSecureContext) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
}
