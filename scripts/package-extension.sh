#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
TMP_DIR="$DIST_DIR/package-tmp"

VERSION="$(node -e "const fs=require('fs');const m=JSON.parse(fs.readFileSync(process.argv[1],'utf8'));process.stdout.write(m.version||'0.0.0')" "$ROOT_DIR/manifest.json")"
OUT_FILE="$DIST_DIR/elementor-ai-capture-studio-v${VERSION}.zip"

mkdir -p "$DIST_DIR"
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"

# Keep packaged output deterministic and aligned with runtime assets.
cp "$ROOT_DIR/manifest.json" "$TMP_DIR/"
cp "$ROOT_DIR/background.js" "$TMP_DIR/" 2>/dev/null || true
cp "$ROOT_DIR/background.iife.js" "$TMP_DIR/" 2>/dev/null || true
cp "$ROOT_DIR/icon-256.png" "$TMP_DIR/"
cp -R "$ROOT_DIR/content-ui" "$TMP_DIR/content-ui"
cp -R "$ROOT_DIR/options" "$TMP_DIR/options" 2>/dev/null || true
cp -R "$ROOT_DIR/export" "$TMP_DIR/export" 2>/dev/null || true
cp -R "$ROOT_DIR/lib" "$TMP_DIR/lib" 2>/dev/null || true
cp -R "$ROOT_DIR/_locales" "$TMP_DIR/_locales"
cp -R "$ROOT_DIR/_metadata" "$TMP_DIR/_metadata"

(
  cd "$TMP_DIR"
  rm -f "$OUT_FILE"
  zip -rq "$OUT_FILE" .
)

rm -rf "$TMP_DIR"

echo "Package created: $OUT_FILE"
