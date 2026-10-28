#!/usr/bin/env bash
#
# Minify the front-end viewer script (dev only; bin/ is left out of release
# ZIPs). Run after editing assets/viewer.js:
#
#   bin/build.sh <folder with node_modules/.bin/terser>
#
# The plugin loads assets/viewer.min.js, or viewer.js when SCRIPT_DEBUG is on.

set -euo pipefail

TOOLS="${1:?Path to a folder with terser installed (npm i terser)}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/assets"

"$TOOLS/node_modules/.bin/terser" "$DIR/viewer.js" --compress --mangle --comments false -o "$DIR/viewer.min.js"

echo "Built $(wc -c < "$DIR/viewer.min.js" | tr -d ' ') bytes: assets/viewer.min.js"
