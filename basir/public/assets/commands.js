// بصير — فهم الأوامر الصوتية العربية (لهجات مختلفة) وتحويلها إلى أوامر.
import { normalizeArabic } from './core.js';

const COMMANDS = [
  ['stop', ['توقف', 'وقف', 'قف', 'ايقاف', 'اسكت', 'خلاص', 'كفايه', 'stop']],
  ['repeat', ['كرر', 'اعد', 'عيد', 'ماذا قلت', 'وش قلت', 'repeat']],
  ['help', ['مساعده', 'ساعدني', 'الاوامر', 'وش اقول', 'ماذا اقول', 'help']],
  ['provider', ['الموفر', 'المزود', 'النموذج', 'موفر', 'provider', 'model']],
  ['settings', ['الاعدادات', 'اعدادات', 'الضبط', 'settings']],
  ['devices', ['الجوالات', 'الاجهزه', 'الكاميرات', 'ربط', 'جوال اضافي', 'devices']],
  ['forget', ['انسي', 'انسى', 'انس', 'احذف الذاكره', 'امسح الذاكره', 'forget']],
  ['four', ['اربع جهات', 'اربعه جهات', '4 جهات', 'الجهات الاربع', 'الجهات الاربعه', 'اربع اتجاهات', 'الاتجاهات الاربعه']],
  ['video', ['فيديو', 'فديو', 'تدريب', 'تعرف على المكان', 'دوران', 'استكشف', 'video', 'scan']],
  ['read', ['اقرا', 'قراءه', 'وش مكتوب', 'ماذا مكتوب', 'ايش مكتوب', 'read']],
  ['describe', ['ماذا امامي', 'ايش قدامي', 'وش قدامي', 'شو قدامي', 'ماذا حولي', 'وش حولي', 'صف', 'وصف', 'describe']],
  ['faster', ['اسرع', 'بسرعه', 'faster']],
  ['slower', ['ابطا', 'ببطء', 'على مهل', 'slower']],
  ['install', ['ثبت', 'تثبيت', 'install']],
  ['skip', ['تخطي', 'تخطى', 'skip']],
  ['nav', ['ابدا', 'ابدء', 'امشي', 'امش', 'نمشي', 'تنقل', 'وجهني', 'ارشدني', 'دلني', 'يلا', 'start', 'go', 'walk', 'navigate']],
];
const GOAL_RE = /(?:خذني|ودني|وديني|وصلني|اوصلني|اريد الذهاب|اريد ان اذهب|ابغى اروح|ابغي اروح|ابي اروح|كيف اروح|كيف اذهب|take me)\s+(?:الى|الي|لل|ل|على|عند|to\s+)?\s*(.+)/;

export function parseCommand(text) {
  const n = normalizeArabic(text);
  const padded = ` ${n} `;
  const has = (k) => padded.includes(` ${k} `);
  if (COMMANDS[0][1].some(has)) return { cmd: 'stop' };
  const goal = n.match(GOAL_RE);
  if (goal && goal[1].trim()) return { cmd: 'goal', goal: goal[1].trim() };
  for (const [cmd, keys] of COMMANDS) {
    if (keys.some(has)) return { cmd };
  }
  return { cmd: 'ask', question: text.trim() };
}

