// بصير — فهم الأوامر الصوتية (عربي بلهجات مختلفة + إنجليزي) وتحويلها إلى أوامر.
// الكلمات تُقارن بعد التطبيع: بلا تشكيل، والهمزات ألفاً، والتاء المربوطة هاءً، والحروف الإنجليزية صغيرة.
import { normalizeArabic } from './core.js';

// العربية: الكلمة في أي موضع من الجملة (اللهجات تضع الأمر في أماكن مختلفة)
const COMMANDS_AR = [
  ['stop', ['توقف', 'وقف', 'قف', 'ايقاف', 'اسكت', 'خلاص', 'كفايه']],
  ['repeat', ['كرر', 'اعد', 'عيد', 'ماذا قلت', 'وش قلت']],
  ['help', ['مساعده', 'ساعدني', 'الاوامر', 'وش اقول', 'ماذا اقول']],
  ['provider', ['الموفر', 'المزود', 'النموذج', 'موفر']],
  ['settings', ['الاعدادات', 'اعدادات', 'الضبط']],
  ['devices', ['الجوالات', 'الاجهزه', 'الكاميرات', 'ربط', 'جوال اضافي']],
  ['forget', ['انسي', 'انسى', 'انس', 'احذف الذاكره', 'امسح الذاكره']],
  ['four', ['اربع جهات', 'اربعه جهات', '4 جهات', 'الجهات الاربع', 'الجهات الاربعه', 'اربع اتجاهات', 'الاتجاهات الاربعه']],
  ['video', ['فيديو', 'فديو', 'تدريب', 'تعرف على المكان', 'دوران', 'استكشف']],
  ['read', ['اقرا', 'قراءه', 'وش مكتوب', 'ماذا مكتوب', 'ايش مكتوب']],
  ['describe', ['ماذا امامي', 'ايش قدامي', 'وش قدامي', 'شو قدامي', 'ماذا حولي', 'وش حولي', 'صف', 'وصف']],
  ['faster', ['اسرع', 'بسرعه']],
  ['slower', ['ابطا', 'ابطي', 'ببطء', 'ببطي', 'على مهل', 'بشويش']],
  ['install', ['ثبت', 'تثبيت']],
  ['skip', ['تخطي', 'تخطى']],
  ['nav', ['ابدا', 'ابدء', 'امشي', 'امش', 'نمشي', 'تنقل', 'وجهني', 'ارشدني', 'دلني', 'يلا']],
];

// الإنجليزية: الأمر يجب أن يبدأ الجملة، والجملة قصيرة (عدد الكلمات المسموح بعده)،
// حتى تذهب أسئلة مثل «where is the bus stop?» أو «is there a train?» إلى الذكاء الاصطناعي.
const COMMANDS_EN = [
  ['stop', ['stop', 'halt', 'cancel', 'be quiet', 'quiet', 'thats enough', 'that s enough', 'enough'], 2],
  ['repeat', ['repeat', 'say again', 'say that again', 'again', 'what did you say'], 1],
  ['help', ['help', 'commands', 'what can i say'], 1],
  ['provider', ['provider', 'which provider', 'which ai', 'which model', 'what model are you'], 3],
  ['settings', ['settings', 'open settings', 'setup'], 1],
  ['devices', ['devices', 'phones', 'cameras', 'linked phones', 'link phone', 'link a phone'], 2],
  ['forget', ['forget', 'forget the place', 'clear memory'], 2],
  ['four', ['four directions', '4 directions', 'four sides', 'all directions'], 1],
  ['video', ['video', 'scan', 'scan the room', 'look around', 'explore', 'start video', 'start scan', 'learn the place'], 1],
  ['read', ['read', 'what does it say', 'what does this say'], 4],
  ['describe', ['describe', 'what is in front', 'whats in front', 'what s in front', 'what do you see', 'what is around', 'whats around'], 3],
  ['faster', ['faster', 'speak faster', 'talk faster'], 1],
  ['slower', ['slower', 'speak slower', 'talk slower'], 1],
  ['install', ['install', 'install the app'], 2],
  ['skip', ['skip'], 1],
  ['nav', ['start', 'go', 'walk', 'navigate', 'guide me', 'lets go', 'let s go', 'start walking', 'start navigation'], 2],
];
// كلمات تهذيب في أول الجملة الإنجليزية لا تغيّر الأمر
const EN_LEAD = /^(?:(?:please|ok|okay|hey|hi|basir|bassir|now|just|can you|could you|would you|will you|i want you to|i want to|i d like to|id like to)\s+)+/;

// تبديل لغة التطبيق بالصوت: الجملة كلها طلب لغة (فلا يتحول سؤال مثل «هل هذا عربي؟» إلى تبديل)
const LANG_WORDS = {
  en: ['english', 'inglish', 'انجليزي', 'انجليزيه', 'الانجليزي', 'الانجليزيه', 'بالانجليزي', 'بالانجليزيه',
    'انقليزي', 'الانقليزي', 'الانقليزيه', 'بالانقليزي', 'انكليزي', 'الانكليزيه', 'بالانكليزي',
    'انجلش', 'انجليش', 'اينجلش', 'بالانجلش', 'انقلش'],
  ar: ['arabic', 'arabi', 'arabik', 'عربي', 'عربيه', 'العربي', 'العربيه', 'بالعربي', 'بالعربيه'],
};
const LANG_FILLER = new Set(['please', 'ok', 'okay', 'speak', 'talk', 'switch', 'change', 'to', 'the', 'language', 'in', 'now',
  'use', 'mode', 'basir', 'تكلم', 'تكلمي', 'اتكلم', 'تحدث', 'كلمني', 'حول', 'الى', 'الي', 'غير', 'اللغه', 'لغه',
  'خلها', 'خليها', 'خل', 'الحين', 'الان', 'بس', 'يا', 'بصير']);

const GOAL_AR = /(?:خذني|ودني|وديني|وصلني|اوصلني|اريد الذهاب|اريد ان اذهب|ابغى اروح|ابغي اروح|ابي اروح|كيف اروح|كيف اذهب)\s+(?:الى|الي|لل|ل|على|عند)?\s*(.+)/;
const GOAL_EN = /^(?:take me to|go to|guide me to|lead me to|bring me to|walk me to|navigate to|i want to go to|how do i get to)\s+(?:the\s+)?(.+)/;

function langRequest(n) {
  const words = n.replace(/من فضلك|لو سمحت/g, ' ').split(' ').filter((w) => w && !LANG_FILLER.has(w));
  if (words.length !== 1) return null;
  if (LANG_WORDS.en.includes(words[0])) return 'en';
  if (LANG_WORDS.ar.includes(words[0])) return 'ar';
  return null;
}

/** أمر إنجليزي يبدأ الجملة (الأطول تطابقاً يفوز: «start video» قبل «start»). */
function englishCommand(n) {
  const s = n.replace(EN_LEAD, '');
  const count = s.split(' ').length;
  let best = null;
  for (const [cmd, keys, extra] of COMMANDS_EN) {
    for (const k of keys) {
      const fits = (s === k || s.startsWith(k + ' ')) && count <= k.split(' ').length + extra;
      if (fits && (!best || k.length > best.len)) best = { cmd, len: k.length };
    }
  }
  return best && best.cmd;
}

export function parseCommand(text) {
  const n = normalizeArabic(text);
  const padded = ` ${n} `;
  const has = (k) => padded.includes(` ${k} `);
  if (COMMANDS_AR[0][1].some(has)) return { cmd: 'stop' };
  const lang = langRequest(n);
  if (lang) return { cmd: 'lang', lang };
  const goal = n.match(GOAL_AR) || n.replace(EN_LEAD, '').match(GOAL_EN);
  if (goal && goal[1].trim()) return { cmd: 'goal', goal: goal[1].trim() };
  const en = englishCommand(n);
  if (en) return { cmd: en };
  for (const [cmd, keys] of COMMANDS_AR) {
    if (keys.some(has)) return { cmd };
  }
  return { cmd: 'ask', question: text.trim() };
}
