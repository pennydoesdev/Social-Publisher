#!/usr/bin/env bash
# Build the distributable plugin zip.
#
# Output: dist/social-publisher-v<VERSION>.zip  (filename tracks the plugin
# Version header, e.g. v1.00, v1.01, ...).  The archive extracts to a single
# `social-publisher/` directory so it uploads cleanly via WP Admin →
# Plugins → Add New → Upload Plugin.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="social-publisher"
DIST="$ROOT/dist"

VERSION="$(grep -oE 'Version:[[:space:]]+[0-9.]+' "$ROOT/social-publisher.php" | awk '{print $2}')"
if [[ -z "${VERSION}" ]]; then
	echo "Could not read Version from social-publisher.php" >&2
	exit 1
fi

OUT="$DIST/${NAME}-v${VERSION}.zip"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$DIST" "$STAGE/$NAME"

cp    "$ROOT/social-publisher.php" "$STAGE/$NAME/"
cp    "$ROOT/uninstall.php"        "$STAGE/$NAME/"
cp    "$ROOT/README.md"            "$STAGE/$NAME/"
cp    "$ROOT/CHANGELOG.md"         "$STAGE/$NAME/"
cp -R "$ROOT/includes"             "$STAGE/$NAME/"
cp -R "$ROOT/assets"               "$STAGE/$NAME/"

rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OUT" "$NAME" )

SIZE="$(du -h "$OUT" | awk '{print $1}')"
echo "Built $OUT (v$VERSION, $SIZE)"
