// يضيف لمشروع أندرويد الذي أنشأه Capacitor: صلاحيات الكاميرا والميكروفون، اختصار خفض الصوت 3 مرات
// (خدمة تسهيل الاستخدام)، السماح بخادم محلي http، والأيقونات. آمن للتشغيل أكثر من مرة.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const native = path.resolve(here, '../native/android');
const main = path.resolve(here, '../android/app/src/main');
if (!fs.existsSync(main)) {
  console.error('لم يُعثر على مجلد android. شغّل أولاً: npx cap add android');
  process.exit(1);
}

// 1) ملفات Java (MainActivity + خدمة الاختصار)
const javaDir = path.join(main, 'java/com/basir/app');
fs.mkdirSync(javaDir, { recursive: true });
for (const f of fs.readdirSync(path.join(native, 'java'))) {
  fs.copyFileSync(path.join(native, 'java', f), path.join(javaDir, f));
}

// 2) الموارد (إعداد الخدمة، النصوص، الأيقونات)
fs.cpSync(path.join(native, 'res'), path.join(main, 'res'), { recursive: true });

// 3) AndroidManifest.xml
const manifestPath = path.join(main, 'AndroidManifest.xml');
let m = fs.readFileSync(manifestPath, 'utf8');

const permissions = [
  'android.permission.CAMERA',
  'android.permission.RECORD_AUDIO',
  'android.permission.MODIFY_AUDIO_SETTINGS',
  'android.permission.VIBRATE',
];
for (const p of permissions) {
  if (!m.includes(`"${p}"`)) {
    m = m.replace('</manifest>', `    <uses-permission android:name="${p}" />\n</manifest>`);
  }
}
if (!m.includes('android.hardware.camera')) {
  m = m.replace('</manifest>', '    <uses-feature android:name="android.hardware.camera" android:required="false" />\n</manifest>');
}
if (!m.includes('android.intent.action.TTS_SERVICE')) {
  m = m.replace('</manifest>', `    <queries>
        <intent><action android:name="android.intent.action.TTS_SERVICE" /></intent>
        <intent><action android:name="android.speech.RecognitionService" /></intent>
    </queries>
</manifest>`);
}
if (!m.includes('usesCleartextTraffic')) {
  // خادم بصير المحلي يعمل عادة على http://192.168.x.x:7777
  m = m.replace('<application', '<application\n        android:usesCleartextTraffic="true"');
}
if (!m.includes('.VolumeShortcutService')) {
  m = m.replace('</application>', `
        <service
            android:name=".VolumeShortcutService"
            android:exported="true"
            android:label="@string/basir_shortcut_label"
            android:permission="android.permission.BIND_ACCESSIBILITY_SERVICE">
            <intent-filter>
                <action android:name="android.accessibilityservice.AccessibilityService" />
            </intent-filter>
            <meta-data
                android:name="android.accessibilityservice"
                android:resource="@xml/basir_accessibility" />
        </service>
    </application>`);
}
fs.writeFileSync(manifestPath, m);
console.log('✓ تم تجهيز مشروع أندرويد: الكاميرا، الميكروفون، واختصار خفض الصوت 3 مرات.');
