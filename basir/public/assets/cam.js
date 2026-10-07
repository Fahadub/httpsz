// بصير — جوال إضافي: يرسل صورة اتجاهه للخادم كل ثانية ونصف، ويضيفها بصير لكل إرشاد.
import { api, Camera, tts, wakeLock, sleep, store, DIR_NAMES, registerSW } from './core.js';

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
  $('badge').textContent = `كاميرا ${DIR_NAMES[d]}`;
  $('badge').classList.remove('live');
  if (announce) tts.speak(`هذا الجوال الآن كاميرا ${DIR_NAMES[d]}.`);
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
        const others = r.nodes.filter((n) => n.dir !== dir).map((n) => n.name);
        msg(`متصل ويرسل الصور كل ${INTERVAL_MS / 1000} ثانية.${others.length ? ` جوالات أخرى: ${others.join('، ')}.` : ''} اترك الشاشة مفتوحة.`, 'ok');
      }
    } catch (e) {
      failures++;
      $('badge').classList.remove('live');
      msg(e.message, 'err');
      if (failures === 3) tts.speak(`انقطع اتصال كاميرا ${DIR_NAMES[dir]}.`);
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
  tts.speak('تم فصل الجوال.');
});

registerSW();
const saved = store.get('basir_cam_dir');
if (saved && DIR_NAMES[saved]) start(saved, { announce: false });
