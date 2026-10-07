#!/usr/bin/env sh
# اختبار المتصفح الشامل. يحتاج Node و Playwright (npm i -g playwright).
cd "$(dirname "$0")/.." || exit 1
TMP="$(mktemp -d)"
export BASIR_DATA_DIR="$TMP/data" MOCK_LOG="$TMP/mock.jsonl" PHP_CLI_SERVER_WORKERS=6

php -S 127.0.0.1:7790 tests/mock_provider.php >"$TMP/mock.log" 2>&1 &
MOCK=$!
php -S 127.0.0.1:7781 -t public router.php >"$TMP/app.log" 2>&1 &
APP=$!
trap 'kill $MOCK $APP 2>/dev/null; rm -rf "$TMP"' EXIT
sleep 1

[ -z "$PLAYWRIGHT_PATH" ] && PLAYWRIGHT_PATH="$(npm root -g 2>/dev/null)/playwright"
export PLAYWRIGHT_PATH
node tests/e2e.mjs
