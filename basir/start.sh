#!/usr/bin/env sh
# تشغيل بصير على المنفذ 7777 (لينكس / ماك): الأصوات المدمجة + خادم الصوت + خادم التطبيق
cd "$(dirname "$0")" || exit 1
PORT="${PORT:-7777}"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP غير مثبت. ثبّت PHP 8.1 أو أحدث." >&2
  exit 1
fi

mkdir -p data
PHPARGS="$(php tools/php_args.php)"
# عدة عمليات حتى لا تتوقف صور الجوالات الإضافية أثناء انتظار رد الذكاء الاصطناعي
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-6}"

# ── الصوت المدمج: يُنزَّل مرة واحدة ثم يعمل بلا إنترنت ──
VOICE_PID=""
if [ "${BASIR_NO_VOICE:-0}" != "1" ]; then
  if php $PHPARGS -r 'exit(extension_loaded("ffi") ? 0 : 1);'; then
    php $PHPARGS tools/voices.php install || echo "تعذر تنزيل الأصوات الآن — سيُستخدم صوت الجهاز مؤقتاً."
    php $PHPARGS tools/voices.php stop >/dev/null 2>&1
    php $PHPARGS src/voice_daemon.php 2>>data/voice.log &
    VOICE_PID=$!
  else
    echo "تنبيه: إضافة PHP FFI غير مفعّلة، لذلك سيُستخدم صوت الجهاز بدل الصوت المدمج."
    echo "       (أوبونتو/ديبيان: ثبّت حزمة php-ffi أو فعّل ffi في php.ini)"
  fi
fi
trap '[ -n "$VOICE_PID" ] && kill "$VOICE_PID" 2>/dev/null' EXIT INT TERM

echo
echo "بصير يعمل على:"
echo "  http://localhost:$PORT        (على نفس الجهاز — الكاميرا تعمل مباشرة)"
for ip in $( (hostname -I 2>/dev/null || ipconfig getifaddr en0 2>/dev/null) ); do
  case "$ip" in *:*) ;; *) echo "  http://$ip:$PORT   (من الجوال — يحتاج HTTPS للكاميرا، راجع README)";; esac
done
echo

php $PHPARGS -d post_max_size=32M -d memory_limit=256M -d max_execution_time=120 \
  -S "0.0.0.0:$PORT" -t public router.php
