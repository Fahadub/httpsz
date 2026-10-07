// بصير — جوال إضافي: يرسل صورة اتجاهه للخادم كل ثانية ونصف، ويضيفها بصير لكل إرشاد.
import { api, Camera, tts, wakeLock, sleep, store, DIRS, dirName, registerSW } from './core.js';
import { t, setLang, listSep, plain } from './i18n.js';

const $ = (id) => document.getElementById(id);
const camera = new Camera($('video'));
const device = store.get('basir_device') || `cam-${Math.random().toString(36).slice(2, 8)}`;
store.set('basir_device', device);

const INTERVAL_MS = 1500;
let dir = null;
let run = 0;

function msg(text, kind = 'info') {
  $('msg').textContent = text;
  $('msg').className = `notice ${kind}`;
}

async function start(d, { announce = true } = {}) {
  dir = d;
  store.set('basir_cam_dir', d);
  $('picker').classList.add('hidden');
  $('live').classList.remove('hidden');
  $('badge').textContent = plain(t('node.badge', { name: dirName(d) }));
  $('badge').classList.remove('live');
  if (announce) tts.speak(t('node.thisIs', { name: dirName(d) }));
  wakeLock.request();
  loop(++run);
}

async function loop(myRun) {
  let failures = 0;
  while (myRun === run) {
    if (document.hidden) { await sleep(500); continue; }
    try {
      if (!camera.active) await camera.start();
      const f = camera.capture(640, 0.55);
      if (f) {
        const r = await api('node_frame', { dir, image: f.data, device }, { timeout: 10000 });
        if (myRun !== run) break;
        failures = 0;
        $('badge').classList.add('live');
        const others = r.nodes.filter((n) => n.dir !== dir).map((n) => dirName(n.dir));
        msg(plain(t('node.sending', { sec: INTERVAL_MS / 1000, others: others.join(listSep()) })), 'ok');
      }
    } catch (e) {
      failures++;
      $('badge').classList.remove('live');
      msg(e.message, 'err');
      if (failures === 3) tts.speak(t('node.disconnected', { name: dirName(dir) }));
      await sleep(Math.min(10000, 1000 * failures));
    }
    await sleep(INTERVAL_MS);
  }
}

async function stop(leave) {
  run++;
  camera.stop();
  wakeLock.release();
  if (leave && dir) await api('node_leave', { dir }).catch(() => {});
  store.del('basir_cam_dir');
  dir = null;
  $('live').classList.add('hidden');
  $('picker').classList.remove('hidden');
}

document.querySelectorAll('[data-dir]').forEach((b) => b.addEventListener('click', () => {
  tts.unlock();
  start(b.dataset.dir);
}));
$('change').addEventListener('click', () => stop(true));
$('stop').addEventListener('click', async () => {
  await stop(true);
  tts.speak(t('node.unlinked'));
});

registerSW();
// لغة وصوت الخادم الافتراضيان
api('status').then((s) => {
  const lang = setLang(store.get('basir_lang') || s.settings.language || 'ar');
  const v = s.voices || {};
  tts.configure({
    lang,
    locale: lang === 'en' ? s.settings.speech_lang_en : s.settings.speech_lang,
    rate: s.settings.speech_rate,
    serverVoices: { ar: !!(v.ar && v.ar.ready), en: !!(v.en && v.en.ready) },
  });
}).catch(() => {});
const saved = store.get('basir_cam_dir');
if (saved && DIRS.includes(saved)) start(saved, { announce: false });
