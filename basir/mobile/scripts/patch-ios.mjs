// يضيف لمشروع iOS نصوص طلب الأذونات (كاميرا، ميكروفون، التعرف على الكلام، الشبكة المحلية)
// والسماح بخادم بصير المحلي عبر http. آمن للتشغيل أكثر من مرة.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const plist = path.resolve(here, '../ios/App/App/Info.plist');
if (!fs.existsSync(plist)) {
  console.error('لم يُعثر على ios/App/App/Info.plist. شغّل أولاً: npx cap add ios');
  process.exit(1);
}

let s = fs.readFileSync(plist, 'utf8');
const strings = {
  NSCameraUsageDescription: 'يصوّر بصير ما أمامك ليخبرك بالصوت أين تمشي.',
  NSMicrophoneUsageDescription: 'يستمع بصير لأوامرك الصوتية.',
  NSSpeechRecognitionUsageDescription: 'يحوّل بصير كلامك إلى أوامر مثل: ابدأ، توقف، ماذا أمامي.',
  NSLocalNetworkUsageDescription: 'يتصل بصير بخادمه على شبكتك المحلية.',
  NSMotionUsageDescription: 'يستخدم بصير البوصلة لإرشادك عند تصوير الجهات الأربع.',
};
let add = '';
for (const [k, v] of Object.entries(strings)) {
  if (!s.includes(`<key>${k}</key>`)) add += `\t<key>${k}</key>\n\t<string>${v}</string>\n`;
}
if (!s.includes('<key>NSAppTransportSecurity</key>')) {
  add += '\t<key>NSAppTransportSecurity</key>\n\t<dict>\n\t\t<key>NSAllowsLocalNetworking</key>\n\t\t<true/>\n\t\t<key>NSAllowsArbitraryLoads</key>\n\t\t<true/>\n\t</dict>\n';
}
if (add) {
  const i = s.lastIndexOf('</dict>');
  s = s.slice(0, i) + add + s.slice(i);
  fs.writeFileSync(plist, s);
}
console.log('✓ تم تجهيز مشروع iOS. افتحه في Xcode: npx cap open ios');
