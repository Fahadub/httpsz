// بصير — صفحة الإعداد (للمساعد المبصر): الموفر والمفتاح مرة واحدة، الصوت، التدريب من فيديو، الجوالات الإضافية.
import { api, apiBase, setApiBase, tts, isNative, framesFromVideoFile, setupInstall, registerSW, speakable } from './core.js';

const $ = (id) => document.getElementById(id);
const params = new URLSearchParams(location.search);
let presets = {};
let status = null;
let lastPreset = null;

function notice(el, text, kind = 'info') {
  el.textContent = text || '';
  el.className = `notice ${text ? kind : ''}`;
}

function busy(btn, on) {
  btn.disabled = on;
  btn.setAttribute('aria-busy', on ? 'true' : 'false');
}

// ───────────────────────── عنوان الخادم (تطبيق الجوال) ─────────────────────────

function showServerCard(message = '') {
  $('server-card').classList.remove('hidden');
  $('server-url').value = apiBase() || 'http://';
  if (message) notice($('server-msg'), message, 'err');
}

$('server-save').addEventListener('click', async () => {
  const url = $('server-url').value.trim().replace(/\/+$/, '');
  busy($('server-save'), true);
  try {
    await api('status', null, { base: url, timeout: 8000 });
    setApiBase(url);
    notice($('server-msg'), 'تم الاتصال بالخادم وحُفظ العنوان.', 'ok');
    setTimeout(() => location.replace('setup.html'), 800);
  } catch (e) {
    notice($('server-msg'), `${e.message} (${url})`, 'err');
  } finally {
    busy($('server-save'), false);
  }
});

// ───────────────────────── نموذج الموفر ─────────────────────────

function formData() {
  return {
    provider: $('provider').value,
    protocol: $('protocol').value,
    label: $('label').value.trim(),
    base_url: $('base_url').value.trim(),
    api_key: $('api_key').value.trim(),
    model: $('model').value.trim(),
    pin: $('pin').value.trim(),
    new_pin: $('new_pin').value.trim(),
    settings: {
      speech_lang: $('speech_lang').value,
      speech_rate: Number($('speech_rate').value),
      step_m: Number($('step_m').value),
      interval_s: Number($('interval_s').value),
      image_px: Number($('image_px').value),
    },
  };
}

function fillModels(list) {
  $('models').innerHTML = '';
  for (const m of list) {
    const o = document.createElement('option');
    o.value = m;
    $('models').append(o);
  }
}

function keyHelp() {
  const p = presets[$('provider').value] || {};
  const saved = status && status.provider;
  const sameServer = saved && saved.base_url === $('base_url').value.trim().replace(/\/+$/, '') && saved.has_key;
  if (sameServer) {
    $('api_key').placeholder = `محفوظ: ${saved.key_hint} — اتركه فارغاً للإبقاء عليه`;
    $('key-help').textContent = 'المفتاح محفوظ على الخادم ولا يُعرض هنا. اكتب مفتاحاً جديداً فقط إذا أردت تغييره.';
  } else {
    $('api_key').placeholder = p.needs_key ? 'الصق المفتاح هنا' : 'غير مطلوب لهذا الموفر (اختياري)';
    $('key-help').textContent = p.needs_key ? 'يُحفظ في ملف على الخادم (مجلد data) ولا يُرسل للمتصفح أبداً.' : '';
  }
}

function onPresetChange() {
  const id = $('provider').value;
  const p = presets[id] || {};
  const prev = presets[lastPreset] || {};
  const custom = id === 'custom';
  $('protocol-field').classList.toggle('hidden', !custom);
  $('label-field').classList.toggle('hidden', !custom);
  if (!custom) $('protocol').value = p.protocol;
  // استبدل القيم الافتراضية فقط إذا لم يغيّرها المستخدم
  if (!$('base_url').value || $('base_url').value === prev.base_url) $('base_url').value = p.base_url || '';
  if (!$('model').value || $('model').value === prev.model) $('model').value = p.model || '';
  $('base_url').placeholder = custom ? 'https://example.com/v1' : p.base_url;
  fillModels(p.models || []);
  lastPreset = id;
  keyHelp();
}

function describeCurrent() {
  const p = status.provider;
  if (!p) {
    notice($('current'), 'لم يُحفظ أي موفر بعد. اختر موفراً، الصق المفتاح، ثم اضغط حفظ.', 'info');
    return;
  }
  notice($('current'), `المحفوظ الآن: ${p.label} — ${p.model}${p.has_key ? ` — المفتاح ${p.key_hint}` : ''}`, 'ok');
}

function applyStatus() {
  const p = status.provider;
  const s = status.settings;
  if (p) {
    $('provider').value = p.id in presets ? p.id : 'custom';
    lastPreset = $('provider').value;
    $('protocol').value = p.protocol;
    $('label').value = p.id === 'custom' ? p.label : '';
    $('base_url').value = p.base_url;
    $('model').value = p.model;
  } else {
    $('provider').value = 'openai';
    lastPreset = null;
  }
  onPresetChange();
  $('speech_lang').value = s.speech_lang;
  $('speech_rate').value = s.speech_rate;
  $('rate-out').textContent = Number(s.speech_rate).toFixed(2);
  $('step_m').value = s.step_m;
  $('interval_s').value = s.interval_s;
  $('image_px').value = String(s.image_px);
  if (!$('image_px').value) $('image_px').value = '768';
  $('pin-field').classList.toggle('hidden', !status.pin_required);
  describeCurrent();
  renderMemory();
  renderNodes(status.nodes || []);
}

$('provider').addEventListener('change', onPresetChange);
$('base_url').addEventListener('input', keyHelp);
$('speech_rate').addEventListener('input', () => { $('rate-out').textContent = Number($('speech_rate').value).toFixed(2); });

$('voice-test').addEventListener('click', () => {
  tts.unlock();
  tts.configure({ lang: $('speech_lang').value, rate: Number($('speech_rate').value) });
  tts.speak('مرحباً، هذا صوت بصير. تقدّم 3 خطوات للأمام، الطريق خالٍ.');
  if (!tts.hasLangVoice()) {
    notice($('voice-msg'), 'لا يوجد صوت عربي مثبت على هذا الجهاز. ثبّته من إعدادات الجهاز: تحويل النص إلى كلام (Text-to-speech) ← اللغة العربية. على ويندوز: الإعدادات ← الوقت واللغة ← الكلام ← إضافة أصوات.', 'err');
  } else {
    notice($('voice-msg'), `الصوت: ${tts.voice ? tts.voice.name : 'صوت النظام'}`, 'ok');
  }
});

$('fetch-models').addEventListener('click', async () => {
  busy($('fetch-models'), true);
  notice($('msg'), 'جارٍ جلب النماذج…');
  try {
    const r = await api('models', formData(), { timeout: 30000 });
    fillModels(r.models);
    notice($('msg'), `وُجد ${r.models.length} نموذجاً. اكتب في حقل النموذج لتظهر القائمة. تأكد أن النموذج يدعم الصور.`, 'ok');
  } catch (e) {
    notice($('msg'), e.message + (e.detail ? ` — ${e.detail}` : ''), 'err');
  } finally {
    busy($('fetch-models'), false);
  }
});

$('test').addEventListener('click', async () => {
  busy($('test'), true);
  notice($('msg'), 'جارٍ الاختبار بإرسال صورة صغيرة للنموذج…');
  try {
    const r = await api('test', formData(), { timeout: 100000 });
    notice($('msg'), `✅ الاتصال ناجح (${(r.latency_ms / 1000).toFixed(1)} ثانية)${r.vision_tested ? ' والنموذج يقرأ الصور' : ''}. رد النموذج: «${r.reply}»`, 'ok');
  } catch (e) {
    notice($('msg'), `❌ ${e.message}${e.detail ? ` — ${e.detail}` : ''}`, 'err');
  } finally {
    busy($('test'), false);
  }
});

$('form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  tts.unlock();
  busy($('save'), true);
  try {
    status = await api('save_config', formData());
    $('api_key').value = '';
    $('pin').value = '';
    $('new_pin').value = '';
    applyStatus();
    notice($('msg'), '✅ تم الحفظ على الخادم. لن يحتاج الكفيف لإدخال المفتاح مرة أخرى.', 'ok');
    $('go-app').classList.remove('hidden');
    tts.configure({ lang: status.settings.speech_lang, rate: status.settings.speech_rate });
    tts.speak(`تم حفظ الموفر ${status.provider.label}، والنموذج ${speakable(status.provider.model)}.`);
    $('go-app').querySelector('a').focus();
  } catch (e) {
    notice($('msg'), `❌ ${e.message}${e.detail ? ` — ${e.detail}` : ''}`, 'err');
  } finally {
    busy($('save'), false);
  }
});

// ───────────────────────── التدريب من فيديو ─────────────────────────

$('video-file').addEventListener('change', async () => {
  const file = $('video-file').files[0];
  if (!file) return;
  const msg = $('train-msg');
  $('frames').innerHTML = '';
  try {
    notice(msg, 'جارٍ اقتطاع اللقطات من الفيديو…');
    const frames = await framesFromVideoFile(file, 8);
    for (const f of frames) {
      const img = document.createElement('img');
      img.src = f.preview;
      img.alt = f.label;
      $('frames').append(img);
    }
    notice(msg, `اقتُطعت ${frames.length} لقطات. جارٍ تحليل المكان بالذكاء الاصطناعي…`);
    const res = await api('analyze', { mode: 'survey', images: frames.map(({ data, label }) => ({ data, label })) }, { timeout: 180000 });
    notice(msg, `✅ ${res.say}`, 'ok');
    tts.speak(res.say);
    status.memory = { say: res.say, summary: res.memory };
    renderMemory();
  } catch (e) {
    notice(msg, `❌ ${e.message}`, 'err');
  } finally {
    $('video-file').value = '';
  }
});

function renderMemory() {
  const m = status && status.memory;
  $('memory').textContent = m && m.summary ? m.summary : 'لا يوجد وصف محفوظ.';
  $('memory-clear').classList.toggle('hidden', !(m && m.summary));
}

$('memory-clear').addEventListener('click', async () => {
  await api('memory_clear', {}).catch(() => {});
  status.memory = null;
  renderMemory();
});

// ───────────────────────── الجوالات الإضافية ─────────────────────────

function renderNodes(nodes) {
  $('nodes').textContent = nodes.length
    ? `متصل الآن: ${nodes.map((n) => n.name).join('، ')}`
    : 'لا توجد جوالات متصلة الآن.';
}

async function pollNodes() {
  try { renderNodes((await api('nodes', null, { timeout: 8000 })).nodes); } catch { /* */ }
}

// ───────────────────────── حذف الإعدادات ─────────────────────────

$('reset').addEventListener('click', async () => {
  if (!confirm('حذف الموفر والمفتاح من الخادم؟')) return;
  try {
    status = await api('reset', { pin: $('pin').value.trim() });
    applyStatus();
    notice($('msg'), 'تم حذف الإعدادات.', 'info');
  } catch (e) {
    notice($('msg'), e.message, 'err');
  }
});

// ───────────────────────── بدء الصفحة ─────────────────────────

async function boot() {
  registerSW();
  setupInstall();
  if (isNative || location.hash === '#server') showServerCard();

  const camUrl = new URL('cam.html', apiBase() ? apiBase() + '/' : location.href).href;
  $('cam-link').href = camUrl;
  $('cam-link').textContent = camUrl;

  try {
    const [p, s] = await Promise.all([api('presets'), api('status')]);
    presets = p.presets;
    status = s;
  } catch (e) {
    showServerCard(e.message);
    return;
  }

  for (const [id, p] of Object.entries(presets)) {
    const o = document.createElement('option');
    o.value = id;
    o.textContent = p.label;
    $('provider').append(o);
  }
  $('form').classList.remove('hidden');
  $('extras').classList.remove('hidden');
  applyStatus();
  if (params.get('first') && !status.configured) {
    notice($('msg'), 'مرحباً! أدخل بيانات موفر الذكاء الاصطناعي مرة واحدة، ثم اضغط حفظ.', 'info');
  }
  setInterval(pollNodes, 5000);
}

boot();
