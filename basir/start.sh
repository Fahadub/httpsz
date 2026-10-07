#!/usr/bin/env sh
# تشغيل بصير على المنفذ 7777 (لينكس / ماك)
cd "$(dirname "$0")" || exit 1
PORT="${PORT:-7777}"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP غير مثبت. ثبّت PHP 8.1 أو أحدث." >&2
  exit 1
fi

mkdir -p data
# عدة عمليات حتى لا تتوقف صور الجوالات الإضافية أثناء انتظار رد الذكاء الاصطناعي
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-6}"

echo "بصير يعمل على:"
echo "  http://localhost:$PORT        (على نفس الجهاز — الكاميرا تعمل مباشرة)"
for ip in $( (hostname -I 2>/dev/null || ipconfig getifaddr en0 2>/dev/null) ); do
  case "$ip" in *:*) ;; *) echo "  http://$ip:$PORT   (من الجوال — يحتاج HTTPS للكاميرا، راجع README)";; esac
done
echo

exec php -d post_max_size=32M -d memory_limit=256M -d max_execution_time=120 \
  -S "0.0.0.0:$PORT" -t public router.php
