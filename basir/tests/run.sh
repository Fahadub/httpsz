#!/usr/bin/env sh
# تشغيل اختبارات الواجهة: يشغّل خادم بصير وموفراً وهمياً بمجلد بيانات مؤقت.
cd "$(dirname "$0")/.." || exit 1
TMP="$(mktemp -d)"
export BASIR_DATA_DIR="$TMP/data" MOCK_LOG="$TMP/mock.jsonl" PHP_CLI_SERVER_WORKERS=4

php -S 127.0.0.1:7790 tests/mock_provider.php >"$TMP/mock.log" 2>&1 &
MOCK=$!
php -S 127.0.0.1:7781 -t public router.php >"$TMP/app.log" 2>&1 &
APP=$!
trap 'kill $MOCK $APP 2>/dev/null; rm -rf "$TMP"' EXIT
sleep 1

php tests/api_test.php
STATUS=$?
[ $STATUS -ne 0 ] && { echo "--- app log"; tail -20 "$TMP/app.log"; }
exit $STATUS
