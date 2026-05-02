#!/usr/bin/env bash
# Build the distributable plugin zip.
#
# Output: dist/social-publisher.zip
# The archive extracts to a single `social-publisher/` directory so it
# uploads cleanly via WP Admin → Plugins → Add New → Upload Plugin.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="social-publisher"
DIST="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$DIST" "$STAGE/$NAME"

cp    "$ROOT/social-publisher.php" "$STAGE/$NAME/"
cp    "$ROOT/uninstall.php"        "$STAGE/$NAME/"
cp    "$ROOT/README.md"            "$STAGE/$NAME/"
cp -R "$ROOT/includes"             "$STAGE/$NAME/"
cp -R "$ROOT/assets"               "$STAGE/$NAME/"

rm -f "$DIST/$NAME.zip"
( cd "$STAGE" && zip -qr "$DIST/$NAME.zip" "$NAME" )

VERSION="$(grep -oE 'Version:[[:space:]]+[0-9.]+' "$ROOT/social-publisher.php" | awk '{print $2}')"
SIZE="$(du -h "$DIST/$NAME.zip" | awk '{print $1}')"
echo "Built $DIST/$NAME.zip (v$VERSION, $SIZE)"
