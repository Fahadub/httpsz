package com.basir.app;

import android.accessibilityservice.AccessibilityService;
import android.app.KeyguardManager;
import android.content.Context;
import android.content.Intent;
import android.os.SystemClock;
import android.view.KeyEvent;
import android.view.accessibility.AccessibilityEvent;

/**
 * اختصار بصير على مستوى الجهاز: بعد فتح قفل الشاشة، ضغط زر خفض الصوت 3 مرات بسرعة
 * يفتح بصير ويبدأ الاستماع مباشرة — من أي شاشة، دون أن يبحث الكفيف عن أيقونة التطبيق.
 * التفعيل: الإعدادات ← تسهيل الاستخدام ← التطبيقات المثبتة ← اختصار بصير.
 * لا تقرأ هذه الخدمة محتوى الشاشة؛ تستمع لزر خفض الصوت فقط.
 */
public class VolumeShortcutService extends AccessibilityService {
    private final Shortcut shortcut = new Shortcut();

    @Override
    protected boolean onKeyEvent(KeyEvent event) {
        if (event.getKeyCode() != KeyEvent.KEYCODE_VOLUME_DOWN
                || event.getAction() != KeyEvent.ACTION_DOWN
                || event.getRepeatCount() > 0) {
            return false;
        }
        if (shortcut.press(SystemClock.uptimeMillis())) {
            launch();
        }
        return false; // لا نمنع خفض الصوت العادي
    }

    private void launch() {
        KeyguardManager km = (KeyguardManager) getSystemService(Context.KEYGUARD_SERVICE);
        if (km != null && km.isKeyguardLocked()) return; // يعمل فقط بعد فتح قفل الشاشة
        Shortcut.restoreVolume(this);
        Intent intent = new Intent(this, MainActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_REORDER_TO_FRONT);
        intent.putExtra(Shortcut.EXTRA, true);
        startActivity(intent);
    }

    @Override
    public void onAccessibilityEvent(AccessibilityEvent event) {
        // لا نحتاج أحداث الشاشة
    }

    @Override
    public void onInterrupt() {
    }
}
