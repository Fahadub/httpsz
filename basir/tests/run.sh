#!/usr/bin/env sh
# تشغيل اختبارات الواجهة: يشغّل خادم بصير وموفراً وهمياً بمجلد بيانات مؤقت.
cd "$(dirname "$0")/.." || exit 1
# المنافذ قابلة للتغيير (MOCK_PORT / APP_PORT / BASIR_VOICE_PORT) لتشغيل أكثر من اختبار معاً
MOCK_PORT="${MOCK_PORT:-7790}" APP_PORT="${APP_PORT:-7781}"
export MOCK_URL="http://127.0.0.1:$MOCK_PORT" APP_URL="http://127.0.0.1:$APP_PORT"
TMP="$(mktemp -d)"
export BASIR_DATA_DIR="$TMP/data" MOCK_LOG="$TMP/mock.jsonl" PHP_CLI_SERVER_WORKERS=4

php -S "127.0.0.1:$MOCK_PORT" tests/mock_provider.php >"$TMP/mock.log" 2>&1 &
MOCK=$!
php -S "127.0.0.1:$APP_PORT" -t public router.php >"$TMP/app.log" 2>&1 &
APP=$!
trap 'kill $MOCK $APP 2>/dev/null; rm -rf "$TMP"' EXIT
sleep 1

php tests/api_test.php
STATUS=$?
[ $STATUS -ne 0 ] && { echo "--- app log"; tail -20 "$TMP/app.log"; }
exit $STATUS
