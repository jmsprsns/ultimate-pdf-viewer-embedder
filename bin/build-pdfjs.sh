#!/usr/bin/env bash
#
# Rebuild assets/pdfjs/ from a pdfjs-dist release (dev only; bin/ is left out
# of release ZIPs).
#
# Usage:
#   bin/build-pdfjs.sh <unpacked pdfjs-dist/package folder> <folder with node_modules/.bin/terser and lightningcss>
#
# Get the package with:  npm pack pdfjs-dist@6.4.299 && tar -xzf pdfjs-dist-6.4.299.tgz
#
# Uses the legacy build (works in older Safari and Chrome versions). The .mjs
# files are renamed .js because some hosts serve .mjs with a MIME type that
# browsers refuse for modules. The viewer component isn't shipped minified,
# so it's minified here. When updating, keep the version in
# Ultimate_PDF_Viewer_Embedder_Assets::PDFJS_VERSION in step.

set -euo pipefail

SRC="${1:?Path to pdfjs-dist package folder}"
TOOLS="${2:?Path to a folder with terser and lightningcss installed}"
DEST="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/assets/pdfjs"

[ -f "$SRC/legacy/build/pdf.min.mjs" ] || { echo "No legacy build in $SRC" >&2; exit 1; }

rm -rf "$DEST"
mkdir -p "$DEST/images" "$DEST/wasm"

cp "$SRC/legacy/build/pdf.min.mjs" "$DEST/pdf.min.js"
cp "$SRC/legacy/build/pdf.worker.min.mjs" "$DEST/pdf.worker.min.js"

"$TOOLS/node_modules/.bin/terser" "$SRC/legacy/web/pdf_viewer.mjs" --module --compress --mangle \
	--comments '/@licstart|@license|pdfjsVersion/' -o "$DEST/pdf_viewer.min.js"

# Lower CSS nesting and light-dark() for older browsers, and drop the
# stylesheet's ":root { color-scheme: light dark }": on a site it would turn
# the whole page's default colours, scrollbars and form fields dark for
# visitors in dark mode. viewer.css sets the scheme on the viewer instead.
"$TOOLS/node_modules/.bin/lightningcss" --minify --targets 'safari >= 14, chrome >= 92, firefox >= 90, edge >= 92, ios_saf >= 14' \
	"$SRC/legacy/web/pdf_viewer.css" -o "$DEST/pdf_viewer.min.css"
perl -0pi -e 's/:root\{color-scheme:light dark;/:root{/' "$DEST/pdf_viewer.min.css"
if grep -q ':root{color-scheme' "$DEST/pdf_viewer.min.css"; then
	echo "pdf_viewer.css still sets color-scheme on :root" >&2
	exit 1
fi

# Only the images the stylesheet uses.
grep -o 'url(["]\{0,1\}images/[^)"]*' "$SRC/legacy/web/pdf_viewer.css" | sed 's/.*images\///' | sort -u |
	while IFS= read -r img; do
		cp "$SRC/legacy/web/images/$img" "$DEST/images/"
	done

cp -R "$SRC/cmaps" "$DEST/cmaps"
cp -R "$SRC/standard_fonts" "$DEST/standard_fonts"
cp -R "$SRC/iccs" "$DEST/iccs"

# Image decoders (JPEG 2000, JBIG2, colour profiles). The scripting sandbox
# (quickjs) is left out: PDFs never run JavaScript in this viewer.
for f in openjpeg.wasm openjpeg_nowasm_fallback.js jbig2.wasm jbig2_nowasm_fallback.js qcms_bg.wasm \
	LICENSE_OPENJPEG LICENSE_PDFJS_OPENJPEG LICENSE_JBIG2 LICENSE_PDFJS_JBIG2 LICENSE_QCMS LICENSE_PDFJS_QCMS; do
	cp "$SRC/wasm/$f" "$DEST/wasm/"
done

cp "$SRC/LICENSE" "$DEST/LICENSE"

printf '<?php\n// Silence is golden.\n' > "$DEST/index.php"

echo "Built $(grep -o 'pdfjsVersion = [0-9.]*' "$DEST/pdf.min.js" | head -1) into $DEST"
du -sh "$DEST"
