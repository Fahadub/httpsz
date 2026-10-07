// ينسخ واجهة بصير (public) إلى www لتطبيق الجوال، ويكتب عنوان الخادم في config.js.
// الاستخدام: node scripts/build-www.mjs http://192.168.1.10:7777
// (العنوان اختياري: يمكن إدخاله لاحقاً مرة واحدة من شاشة الإعداد داخل التطبيق.)
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const src = path.resolve(here, '../../public');
const out = path.resolve(here, '../www');
const server = (process.argv[2] || process.env.BASIR_SERVER || '').trim().replace(/\/+$/, '');
const SKIP = new Set(['api.php', '.htaccess', 'sw.js']);

fs.rmSync(out, { recursive: true, force: true });
fs.cpSync(src, out, {
  recursive: true,
  filter: (p) => !SKIP.has(path.basename(p)),
});
fs.writeFileSync(
  path.join(out, 'config.js'),
  `// عنوان خادم بصير الافتراضي لتطبيق الجوال (يمكن تغييره من الإعدادات داخل التطبيق)\nwindow.BASIR_API_BASE = window.BASIR_API_BASE || ${JSON.stringify(server)};\n`
);
console.log(`www جاهز${server ? ` — الخادم: ${server}` : ' — سيُطلب عنوان الخادم عند أول تشغيل'}`);
