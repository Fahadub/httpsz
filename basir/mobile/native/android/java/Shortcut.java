package com.basir.app;

import android.content.Context;
import android.media.AudioManager;

/** يكتشف ضغط زر خفض الصوت 3 مرات خلال 1.2 ثانية. */
final class Shortcut {
    static final String EXTRA = "basir_shortcut";
    private static final long WINDOW_MS = 1200;
    private static final long DEDUPE_MS = 2000;

    // مشترك بين خدمة تسهيل الاستخدام والنشاط حتى لا يُنفَّذ الاختصار مرتين لنفس الضغطة
    private static long lastFired = 0;

    private final long[] times = new long[3];
    private int count = 0;

    /** يسجّل ضغطة، ويُرجع true عند الضغطة الثالثة السريعة. */
    synchronized boolean press(long now) {
        times[count % 3] = now;
        count++;
        if (count >= 3 && now - times[count % 3] <= WINDOW_MS) {
            count = 0;
            synchronized (Shortcut.class) {
                if (now - lastFired < DEDUPE_MS) return false;
                lastFired = now;
            }
            return true;
        }
        return false;
    }

    /** الضغطات الثلاث خفّضت الصوت؛ نعيده كما كان حتى يسمع الكفيف بصير بوضوح. */
    static void restoreVolume(Context ctx) {
        AudioManager am = (AudioManager) ctx.getSystemService(Context.AUDIO_SERVICE);
        if (am == null) return;
        try {
            for (int i = 0; i < 3; i++) {
                am.adjustSuggestedStreamVolume(AudioManager.ADJUST_RAISE, AudioManager.USE_DEFAULT_STREAM_TYPE, 0);
            }
        } catch (SecurityException ignored) {
            // وضع عدم الإزعاج يمنع تغيير صوت الرنين
        }
    }
}
