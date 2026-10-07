package com.basir.app;

import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.view.KeyEvent;
import android.view.WindowManager;
import android.webkit.WebView;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    private final Shortcut volumeShortcut = new Shortcut();
    private final Handler handler = new Handler(Looper.getMainLooper());

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // إبقاء الشاشة مضاءة أثناء الإرشاد
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        handleShortcut(getIntent());
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        handleShortcut(intent);
    }

    private void handleShortcut(Intent intent) {
        if (intent == null || !intent.getBooleanExtra(Shortcut.EXTRA, false)) return;
        intent.removeExtra(Shortcut.EXTRA);
        callWeb(0);
    }

    /** يستدعي window.basirShortcut() (يبدأ الاستماع)، وينتظر اكتمال تحميل الصفحة عند الفتح من الصفر. */
    private void callWeb(int attempt) {
        if (attempt > 60) return;
        WebView web = getBridge() == null ? null : getBridge().getWebView();
        if (web == null) {
            handler.postDelayed(() -> callWeb(attempt + 1), 250);
            return;
        }
        web.evaluateJavascript(
                "(function(){if(window.basirShortcut){window.basirShortcut();return 'ok';}return 'wait';})()",
                result -> {
                    if (!"\"ok\"".equals(result)) handler.postDelayed(() -> callWeb(attempt + 1), 250);
                });
    }

    /** داخل التطبيق: نفس الاختصار يعمل حتى لو لم تُفعَّل خدمة تسهيل الاستخدام. */
    @Override
    public boolean dispatchKeyEvent(KeyEvent event) {
        if (event.getKeyCode() == KeyEvent.KEYCODE_VOLUME_DOWN
                && event.getAction() == KeyEvent.ACTION_DOWN
                && event.getRepeatCount() == 0
                && volumeShortcut.press(SystemClock.uptimeMillis())) {
            Shortcut.restoreVolume(this);
            callWeb(0);
        }
        return super.dispatchKeyEvent(event);
    }
}
