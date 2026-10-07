#!/usr/bin/env sh
# اختبار المتصفح الشامل. يحتاج Node و Playwright (npm i -g playwright).
cd "$(dirname "$0")/.." || exit 1
TMP="$(mktemp -d)"
export BASIR_DATA_DIR="$TMP/data" MOCK_LOG="$TMP/mock.jsonl" PHP_CLI_SERVER_WORKERS=6

php -S 127.0.0.1:7790 tests/mock_provider.php >"$TMP/mock.log" 2>&1 &
MOCK=$!
php -S 127.0.0.1:7781 -t public router.php >"$TMP/app.log" 2>&1 &
APP=$!
VOICE=""
# اختياري: اختبار الصوت المدمج الحقيقي — VOICES_FROM=مجلد voices مثبت مسبقاً
if [ -n "$VOICES_FROM" ]; then
  mkdir -p "$BASIR_DATA_DIR"
  ln -s "$VOICES_FROM" "$BASIR_DATA_DIR/voices"
  php -d ffi.enable=1 src/voice_daemon.php 2>"$TMP/voice.log" &
  VOICE=$!
  export EXPECT_VOICE=1
  for i in $(seq 1 60); do php -r 'exit(@fsockopen("127.0.0.1", 7778) ? 0 : 1);' && break; sleep 1; done
fi
trap 'kill $MOCK $APP $VOICE 2>/dev/null; rm -rf "$TMP"' EXIT
sleep 1

[ -z "$PLAYWRIGHT_PATH" ] && PLAYWRIGHT_PATH="$(npm root -g 2>/dev/null)/playwright"
export PLAYWRIGHT_PATH
node tests/e2e.mjs
