#!/usr/bin/env bash
# Runs every check that does not need WordPress. For the WordPress integration
# tests, see tests/integration-wp-*.php (run them with `wp eval-file` on a test site).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "== php -l =="
find . -name '*.php' -not -path './languages/*' | while read -r f; do
  php -l "$f" >/dev/null
done
echo "Syntax OK"

echo "== JavaScript syntax =="
if command -v node >/dev/null 2>&1; then node --check assets/js/admin.js && echo "OK"; fi

echo "== parser tests =="
php tests/run-tests.php

echo "== protection tests =="
php tests/run-protection-tests.php

echo "== ACF scan + perf tests =="
php tests/run-acf-scan-tests.php

echo "== in-use-now protection tests =="
php tests/run-in-use-now-tests.php

echo "== source rules (no inline script/style, no confirm(), no toast) =="
if grep -rnE '<script|[[:space:]]style=|[^a-zA-Z.]confirm[[:space:]]*\(' includes/ ; then echo "FAIL"; exit 1; fi
if grep -nE '[^a-zA-Z.]alert[[:space:]]*\(' assets/js/admin.js; then echo "FAIL: alert()"; exit 1; fi
if grep -nE '(^|[^a-zA-Z.])confirm[[:space:]]*\(' assets/js/admin.js | grep -v 'window.confirm'; then echo "FAIL: confirm()"; exit 1; fi
echo "OK"

echo "== client names =="
if [ -n "${CLIENT_NAMES_FILE:-}" ] && [ -r "$CLIENT_NAMES_FILE" ]; then
  if grep -rIF -f "$CLIENT_NAMES_FILE" . --exclude-dir=.git ; then echo "FAIL"; exit 1; fi
  echo "OK (0 match)"
else
  echo "skipped (set CLIENT_NAMES_FILE to a private list)"
fi
echo "All checks passed."
