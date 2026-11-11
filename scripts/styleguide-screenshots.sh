#!/usr/bin/env bash
#
# Screenshots of the style guide (spec § 20.6): phone and desktop, light and dark.
# Compare them before and after a theme change.
#
# Usage: scripts/styleguide-screenshots.sh [base URL] [output folder]
#   default: http://127.0.0.1:8000 screenshots/
# Needs Node.js and Google Chrome (uses Playwright's command line with the installed Chrome).
set -euo pipefail

BASE="${1:-http://127.0.0.1:8000}"
OUT="${2:-screenshots}"
mkdir -p "$OUT"

for scheme in light dark; do
    npx --yes playwright@1 screenshot --channel=chrome --full-page --color-scheme="$scheme" \
        --viewport-size=375,812 "$BASE/demo/styleguide" "$OUT/styleguide-phone-$scheme.png"
    npx --yes playwright@1 screenshot --channel=chrome --full-page --color-scheme="$scheme" \
        --viewport-size=1280,900 "$BASE/demo/styleguide" "$OUT/styleguide-desktop-$scheme.png"
done
echo "Screenshots written to $OUT/"
