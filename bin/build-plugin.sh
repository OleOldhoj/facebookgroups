#!/bin/bash
# Builds the public plugin zip (Chrome Web Store + GitHub release). No key inside:
# the plugin gets the owner's key from facebookstats.fullstackpartner.com when they are logged in there.
set -euo pipefail
cd "$(dirname "$0")/.."
VER=$(php -r 'echo json_decode(file_get_contents("extension/manifest.json"))->version;')
OUT=${1:-dist}
TMP=$(mktemp -d)
cp extension/*.js extension/*.json extension/*.html extension/*.png "$TMP/"
echo "self.FBG_CONFIG = { server: 'https://facebookstats.fullstackpartner.com', token: '' };" > "$TMP/config.js"
mkdir -p "$OUT"
rm -f "$OUT/facebook-stats-plugin-$VER.zip"
python3 -c 'import sys, os, zipfile
src, dst = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(dst, "w", zipfile.ZIP_DEFLATED) as z:
    for f in sorted(os.listdir(src)): z.write(os.path.join(src, f), f)' "$TMP" "$OUT/facebook-stats-plugin-$VER.zip"
rm -rf "$TMP"
echo "$OUT/facebook-stats-plugin-$VER.zip"
