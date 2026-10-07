// بصير — فهم الأوامر الصوتية (عربي بلهجات مختلفة + إنجليزي) وتحويلها إلى أوامر.
// الكلمات تُقارن بعد التطبيع: بلا تشكيل، والهمزات ألفاً، والتاء المربوطة هاءً، والحروف الإنجليزية صغيرة.
import { normalizeArabic } from './core.js';

const COMMANDS = [
  ['stop', ['توقف', 'وقف', 'قف', 'ايقاف', 'اسكت', 'خلاص', 'كفايه', 'stop', 'halt', 'cancel', 'quiet', 'enough']],
  ['repeat', ['كرر', 'اعد', 'عيد', 'ماذا قلت', 'وش قلت', 'repeat', 'say again', 'again', 'what did you say']],
  ['help', ['مساعده', 'ساعدني', 'الاوامر', 'وش اقول', 'ماذا اقول', 'help', 'commands', 'what can i say']],
  ['provider', ['الموفر', 'المزود', 'النموذج', 'موفر', 'provider', 'model', 'which ai']],
  ['settings', ['الاعدادات', 'اعدادات', 'الضبط', 'settings', 'setup']],
  ['devices', ['الجوالات', 'الاجهزه', 'الكاميرات', 'ربط', 'جوال اضافي', 'devices', 'phones', 'cameras', 'link phone']],
  ['forget', ['انسي', 'انسى', 'انس', 'احذف الذاكره', 'امسح الذاكره', 'forget', 'clear memory']],
  ['four', ['اربع جهات', 'اربعه جهات', '4 جهات', 'الجهات الاربع', 'الجهات الاربعه', 'اربع اتجاهات', 'الاتجاهات الاربعه',
    'four directions', '4 directions', 'four sides', 'all directions']],
  ['video', ['فيديو', 'فديو', 'تدريب', 'تعرف على المكان', 'دوران', 'استكشف', 'video', 'scan', 'train', 'look around', 'explore']],
  ['read', ['اقرا', 'قراءه', 'وش مكتوب', 'ماذا مكتوب', 'ايش مكتوب', 'read', 'what does it say']],
  ['describe', ['ماذا امامي', 'ايش قدامي', 'وش قدامي', 'شو قدامي', 'ماذا حولي', 'وش حولي', 'صف', 'وصف',
    'describe', 'what is in front', 'whats in front', 'what s in front', 'what do you see', 'around me', 'what is around']],
  ['faster', ['اسرع', 'بسرعه', 'faster', 'speak faster']],
  ['slower', ['ابطا', 'ببطء', 'على مهل', 'slower', 'speak slower']],
  ['install', ['ثبت', 'تثبيت', 'install']],
  ['skip', ['تخطي', 'تخطى', 'skip']],
  ['nav', ['ابدا', 'ابدء', 'امشي', 'امش', 'نمشي', 'تنقل', 'وجهني', 'ارشدني', 'دلني', 'يلا',
    'start', 'go', 'walk', 'navigate', 'guide me', 'lets go']],
];

// تبديل لغة التطبيق بالصوت
const LANG_EN = ['english', 'انجليزي', 'انجليزيه', 'بالانجليزي', 'انقليزي', 'بالانقليزي', 'speak english'];
const LANG_AR = ['arabic', 'عربي', 'بالعربي', 'العربيه', 'speak arabic'];

const GOAL_AR = /(?:خذني|ودني|وديني|وصلني|اوصلني|اريد الذهاب|اريد ان اذهب|ابغى اروح|ابغي اروح|ابي اروح|كيف اروح|كيف اذهب)\s+(?:الى|الي|لل|ل|على|عند)?\s*(.+)/;
const GOAL_EN = /(?:take me to|go to|guide me to|lead me to|bring me to|i want to go to|how do i get to)\s+(?:the\s+)?(.+)/;

export function parseCommand(text) {
  const n = normalizeArabic(text);
  const padded = ` ${n} `;
  const has = (k) => padded.includes(` ${k} `);
  if (COMMANDS[0][1].some(has)) return { cmd: 'stop' };
  // جملة قصيرة تطلب اللغة فقط (حتى لا يتحول سؤال فيه كلمة «عربي» إلى تبديل لغة)
  if (n.split(' ').length <= 3) {
    if (LANG_EN.some(has)) return { cmd: 'lang', lang: 'en' };
    if (LANG_AR.some(has)) return { cmd: 'lang', lang: 'ar' };
  }
  const goal = n.match(GOAL_AR) || n.match(GOAL_EN);
  if (goal && goal[1].trim()) return { cmd: 'goal', goal: goal[1].trim() };
  for (const [cmd, keys] of COMMANDS) {
    if (keys.some(has)) return { cmd };
  }
  return { cmd: 'ask', question: text.trim() };
}
