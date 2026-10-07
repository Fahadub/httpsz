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

// الإنجليزية: «توقف» أمر أمان: يكفي أن يبدأ الجملة مهما طالت، أو أن يأتي بعد no / wait / i said.
const EN_STOP = /^(?:(?:no|wait|oh|ok|okay|hey|please|just|now|basir|i said)\s+)*(?:stop|halt|cancel|be quiet|shut up|thats enough|that s enough|enough already|forget it|forget about it|forget that|never mind|nevermind)\b/;
const EN_STOP_ANYWHERE = /\b(?:no stop|wait stop|stop stop|i said stop)\b/;

// بقية الأوامر الإنجليزية: تبدأ الجملة، والجملة قصيرة (عدد الكلمات المسموح بعد الأمر)،
// حتى تذهب أسئلة مثل «where is the bus stop?» أو «is there a train?» إلى الذكاء الاصطناعي.
// تُقارن بعد حذف أدوات التعريف (a / an / the) وكلمات التهذيب.
const COMMANDS_EN = [
  ['repeat', ['repeat', 'repeat that', 'repeat it', 'say again', 'say that again', 'say it again', 'again', 'what did you say', 'come again', 'pardon'], 1],
  ['help', ['help', 'help me', 'commands', 'what can i say'], 0],
  ['provider', ['provider', 'which provider', 'which ai', 'what ai', 'which ai are you', 'what ai are you', 'which ai are you using', 'what ai are you using', 'what model are you', 'which model are you', 'what model are you using', 'which model are you using'], 1],
  ['settings', ['settings', 'open settings', 'setup', 'open setup'], 0],
  ['devices', ['devices', 'phones', 'cameras', 'linked phones', 'link phone', 'connected phones', 'extra phones'], 0],
  ['forget', ['forget place', 'forget this place', 'forget room', 'forget memory', 'clear memory', 'erase memory'], 1],
  ['four', ['four directions', '4 directions', 'four sides', 'all directions', 'start four directions', 'start 4 directions', 'start all directions', 'do four directions'], 1],
  ['video', ['video', 'scan', 'scan room', 'scan place', 'look around', 'explore', 'start video', 'start scan', 'start scanning', 'record video', 'take video', 'begin video', 'learn place'], 0],
  ['read', ['read', 'read it', 'read this', 'what does it say', 'what does this say', 'what is written'], 4],
  ['describe', ['describe', 'describe surroundings', 'what is in front', 'whats in front', 'what s in front', 'what do you see', 'what is around', 'whats around', 'what s around'], 3],
  ['install', ['install', 'install app'], 0],
  ['skip', ['skip', 'skip it', 'skip this'], 0],
  ['nav', ['start', 'go', 'walk', 'navigate', 'begin', 'continue', 'resume', 'guide me', 'go ahead', 'lets go', 'let s go', 'start walking', 'start navigation', 'start navigating'], 0],
];
const EN_FASTER = /^(?:(?:speak|talk)\s+)?(?:(?:little|bit|lot|much|even)\s+)?(?:faster|quicker|more quickly)$|^speed up$/;
const EN_SLOWER = /^(?:(?:speak|talk)\s+)?(?:(?:little|bit|lot|much|even)\s+)?(?:slower|more slowly)$|^slow down$/;
// كلمات تهذيب في أول الجملة الإنجليزية وآخرها لا تغيّر الأمر
const EN_LEAD = /^(?:(?:please|ok|okay|hey|hi|basir|bassir|now|just|so|can you|could you|would you|will you|i want you to|i want to|i need to|i have to|i would like to|i d like to|id like to|lets|let s)\s+)+/;
const EN_TAIL = /(?:\s+(?:please|now|right now|for me|thanks|thank you|basir))+$/;

// تبديل لغة التطبيق بالصوت: الجملة كلها طلب لغة (فلا يتحول سؤال مثل «هل هذا عربي؟» إلى تبديل)
const LANG_WORDS = {
  en: ['english', 'inglish', 'انجليزي', 'انجليزيه', 'الانجليزي', 'الانجليزيه', 'بالانجليزي', 'بالانجليزيه', 'للانجليزي', 'للانجليزيه',
    'انقليزي', 'الانقليزي', 'الانقليزيه', 'بالانقليزي', 'للانقليزي', 'انكليزي', 'الانكليزي', 'الانكليزيه', 'بالانكليزي', 'بالانكليزيه',
    'انجلش', 'انجليش', 'اينجلش', 'بالانجلش', 'انقلش', 'انكلش', 'انغلش', 'انغليش'],
  ar: ['arabic', 'arabi', 'arabik', 'arab', 'arabie', 'عربي', 'عربيه', 'العربي', 'العربيه', 'بالعربي', 'بالعربيه', 'للعربي', 'للعربيه'],
};
const LANG_FILLER = new Set(['please', 'ok', 'okay', 'speak', 'talk', 'switch', 'change', 'to', 'the', 'language', 'in', 'now',
  'use', 'mode', 'basir', 'i', 'want', 'would', 'like', 'me', 'with', 'you', 'can', 'could', 'set',
  'تكلم', 'تكلمي', 'اتكلم', 'تتكلم', 'تحدث', 'كلمني', 'تكلمني', 'تحكي', 'احكي', 'حول', 'الى', 'الي', 'غير', 'اللغه', 'لغه',
  'باللغه', 'للغه', 'خلها', 'خليها', 'خل', 'الحين', 'الان', 'بس', 'يا', 'بصير', 'ابي', 'ابيك', 'ابغى', 'ابغي', 'ابغاك',
  'اريد', 'ودي', 'معي', 'معاي', 'لي']);

const GOAL_AR = /(?:خذني|ودني|وديني|وصلني|اوصلني|اريد الذهاب|اريد ان اذهب|ابغى اروح|ابغي اروح|ابي اروح|كيف اروح|كيف اذهب)\s+(?:الى|الي|لل|ل|على|عند)?\s*(.+)/;
const GOAL_EN = /^(?:take me to|go to|guide me to|lead me to|bring me to|walk me to|walk to|get me to|head to|navigate to|start walking to|how do i get to|how can i get to)\s+(?:the\s+)?(.+)/;

function langRequest(n) {
  const words = n.replace(/من فضلك|لو سمحت/g, ' ').split(' ').filter((w) => w && !LANG_FILLER.has(w));
  if (words.length !== 1) return null;
  if (LANG_WORDS.en.includes(words[0])) return 'en';
  if (LANG_WORDS.ar.includes(words[0])) return 'ar';
  return null;
}

/** أمر إنجليزي يبدأ الجملة (الأطول تطابقاً يفوز: «start video» قبل «start»). */
function englishCommand(n) {
  const s = n.replace(EN_LEAD, '').replace(EN_TAIL, '').replace(/\b(?:a|an|the)\s+/g, '').trim();
  if (EN_FASTER.test(s)) return 'faster';
  if (EN_SLOWER.test(s)) return 'slower';
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
  if (COMMANDS_AR[0][1].some(has) || EN_STOP.test(n) || EN_STOP_ANYWHERE.test(n)) return { cmd: 'stop' };
  const lang = langRequest(n);
  if (lang) return { cmd: 'lang', lang };
  const goal = n.match(GOAL_AR) || n.replace(EN_LEAD, '').replace(EN_TAIL, '').match(GOAL_EN);
  if (goal && goal[1].trim()) return { cmd: 'goal', goal: goal[1].trim() };
  const en = englishCommand(n);
  if (en) return { cmd: en };
  for (const [cmd, keys] of COMMANDS_AR) {
    if (keys.some(has)) return { cmd };
  }
  return { cmd: 'ask', question: text.trim() };
}
