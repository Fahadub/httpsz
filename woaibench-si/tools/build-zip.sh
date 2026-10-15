#!/usr/bin/env bash
# Build the deployable package:  dist/woaibench-si-<version>.zip
#
#   bash tools/build-zip.sh            # from the woaibench-si folder (or anywhere)
#
# Upload the zip to public_html with hPanel → File Manager and extract it there (see docs/DEPLOY.md).
# Left out on purpose: secrets (config/config.local.php), the install lock, runtime data (storage/,
# uploads/ contents), dev-only material (tools/dev, docs except DEPLOY.md / SECURITY.md (+ docs/.htaccess), tests, node_modules,
# VCS files). docs/DEPLOY.md and docs/SECURITY.md ship because the installer and README point to them
# (docs/ and *.md are denied over the web by .htaccess). Empty runtime folders are kept with their
# .htaccess / .gitkeep so permissions and deny rules ship.
# Needs bash and either rsync or tar/cp (Git Bash on Windows has no rsync: the fallback copies with tar or cp).
# Prebuilt packages: see the "Releases" page of the GitHub repository (docs/DEPLOY.md §0).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n "s/^define('WB_VERSION', '\([^']*\)');.*/\1/p" "$ROOT/app/bootstrap.php")"
[ -n "$VERSION" ] || { echo "Cannot read WB_VERSION from app/bootstrap.php" >&2; exit 1; }
NAME="woaibench-si-$VERSION"
DIST="${WB_DIST_DIR:-$ROOT/dist}"   # override for CI / testing
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
OUT="$STAGE/$NAME"

echo "→ Packaging WOAIBench.si $VERSION"
mkdir -p "$OUT"
if command -v rsync >/dev/null 2>&1; then
  rsync -a \
    --exclude '/.git/' --exclude '.gitignore' --exclude '.gitattributes' --exclude '.github/' \
    --include '/docs/' --include '/docs/.htaccess' --include '/docs/DEPLOY.md' --include '/docs/SECURITY.md' --exclude '/docs/*' \
    --exclude '/dist/' --exclude '/tools/dev/' --exclude '/tools/build-zip.sh' \
    --exclude 'tests/' --exclude 'node_modules/' \
    --exclude '/config/config.local.php' --exclude '/config/config.local.php.*' \
    --exclude '/install/install.lock' \
    --exclude '/storage/***' --exclude '/uploads/***' \
    --exclude '.DS_Store' --exclude 'Thumbs.db' --exclude '*.log' --exclude '*.swp' --exclude '.env' --exclude '.env.*' \
    --exclude '.idea/' --exclude '.vscode/' \
    "$ROOT/" "$OUT/"
else
  # No rsync (e.g. Git Bash on Windows): copy everything except the big folders, then prune the same paths.
  echo "  (rsync not found: copying with $(command -v tar >/dev/null 2>&1 && echo tar || echo cp))"
  if command -v tar >/dev/null 2>&1; then
    (cd "$ROOT" && tar -cf - --exclude=./.git --exclude=./dist --exclude=./node_modules --exclude=./storage --exclude=./uploads .) | (cd "$OUT" && tar -xf -)
  else
    cp -a "$ROOT/." "$OUT/"
  fi
  rm -rf "$OUT/.git" "$OUT/.github" "$OUT/.gitignore" "$OUT/.gitattributes" "$OUT/dist" "$OUT/tools/dev" "$OUT/tools/build-zip.sh" \
    "$OUT/install/install.lock" "$OUT/storage" "$OUT/uploads" "$OUT/.idea" "$OUT/.vscode"
  rm -f "$OUT"/config/config.local.php "$OUT"/config/config.local.php.*
  find "$OUT/docs" -mindepth 1 ! -name DEPLOY.md ! -name SECURITY.md ! -name .htaccess -exec rm -rf {} + 2>/dev/null || true
  find "$OUT" \( -name node_modules -o -name tests \) -type d -prune -exec rm -rf {} +
  find "$OUT" \( -name '.DS_Store' -o -name 'Thumbs.db' -o -name '*.log' -o -name '*.swp' -o -name '.env' -o -name '.env.*' \) -type f -delete
fi

# Runtime folders: structure + protection files only, never their contents.
for d in storage storage/logs storage/cache storage/sessions uploads uploads/shots uploads/brand; do
  mkdir -p "$OUT/$d"
done
for f in storage/.htaccess uploads/.htaccess; do
  [ -f "$ROOT/$f" ] && cp "$ROOT/$f" "$OUT/$f"
done
for d in storage/logs storage/cache storage/sessions uploads/shots uploads/brand; do
  : > "$OUT/$d/.gitkeep"
done
[ -f "$OUT/storage/.htaccess" ] || { echo "storage/.htaccess is missing in the source tree" >&2; exit 1; }
[ -f "$OUT/uploads/.htaccess" ] || { echo "uploads/.htaccess is missing in the source tree" >&2; exit 1; }
[ -d "$OUT/sql/migrations" ] || mkdir -p "$OUT/sql/migrations"

# Safety net: refuse to ship anything that looks like a secret or dev artefact.
fail=0
for bad in config/config.local.php install/install.lock tools/dev tools/build-zip.sh docs/ARCHITECTURE.md; do
  if [ -e "$OUT/$bad" ]; then echo "✗ must not ship: $bad" >&2; fail=1; fi
done
for need in docs/DEPLOY.md docs/SECURITY.md docs/.htaccess; do
  [ -f "$OUT/$need" ] || { echo "✗ missing from the package: $need (the installer and README link it)" >&2; fail=1; }
done
if find "$OUT" -name node_modules -o -name tests -o -name '*.log' -o -name 'sess_*' | grep -q .; then
  echo "✗ dev/runtime files found:" >&2
  find "$OUT" -name node_modules -o -name tests -o -name '*.log' -o -name 'sess_*' >&2
  fail=1
fi
if grep -rIlE "sk-or-v1-[0-9a-f]{32,}|'app_secret' *=> *'[0-9a-f]{64}'|'cron_key' *=> *'[0-9a-f]{32,}'" "$OUT" >/dev/null 2>&1; then
  echo "✗ possible secret in:" >&2
  grep -rIlE "sk-or-v1-[0-9a-f]{32,}|'app_secret' *=> *'[0-9a-f]{64}'|'cron_key' *=> *'[0-9a-f]{32,}'" "$OUT" >&2
  fail=1
fi
[ "$fail" -eq 0 ] || { echo "Aborted: fix the problems above." >&2; exit 1; }

# PHP syntax check (when a php binary is available).
if command -v php >/dev/null 2>&1; then
  bad_php=0
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null 2>&1 || { echo "✗ PHP syntax error: ${f#$OUT/}" >&2; bad_php=1; }
  done < <(find "$OUT" -name '*.php' -print0)
  [ "$bad_php" -eq 0 ] || exit 1
fi

mkdir -p "$DIST"
ZIP="$DIST/$NAME.zip"
rm -f "$ZIP"
# Files sit at the zip root (no wrapping folder): extract straight into public_html.
if command -v zip >/dev/null 2>&1; then
  (cd "$OUT" && zip -qr -X "$ZIP" . )
else
  php -r '
    [$src, $dst] = [$argv[1], $argv[2]];
    $z = new ZipArchive();
    if ($z->open($dst, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "cannot create zip\n"); exit(1); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($src) + 1);
        $f->isDir() ? $z->addEmptyDir($rel) : $z->addFile($f->getPathname(), $rel);
    }
    $z->close();' "$OUT" "$ZIP"
fi

COUNT="$(find "$OUT" -type f | wc -l | tr -d ' ')"
SIZE="$(du -h "$ZIP" | cut -f1)"
echo "✓ $ZIP  ($COUNT files, $SIZE)"
echo "  Upload to public_html, extract, then open https://<your-domain>/install/"
