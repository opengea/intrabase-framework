#!/bin/bash
# =============================================================================
# build-assets.sh — Minify & obfuscate JS/CSS for Intrabase
#
# Usage:
#   ./tools/build-assets.sh          # Build all
#   ./tools/build-assets.sh intrabase   # Build only intrabase.js + intrabase.css
#   ./tools/build-assets.sh --lang      # Regenerate lang/*.php from ib_lang, then build all
#   ./tools/build-assets.sh --clean  # Remove all .min files
# =============================================================================

set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SRC_JS="$BASE_DIR/src/assets/js"
SRC_CSS="$BASE_DIR/src/assets/css"
PUB_JS="$BASE_DIR/public/assets/js"
PUB_CSS="$BASE_DIR/public/assets/css"
MANIFEST="$BASE_DIR/public/assets/manifest.json"

TERSER="$BASE_DIR/node_modules/.bin/terser"
CLEANCSS="$BASE_DIR/node_modules/.bin/cleancss"
FONTSCALE="$BASE_DIR/tools/css-font-scale.py"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# ---------- Helpers ----------

log()   { echo -e "${GREEN}[build]${NC} $*"; }
warn()  { echo -e "${YELLOW}[warn]${NC} $*"; }
err()   { echo -e "${RED}[error]${NC} $*" >&2; }

check_deps() {
    if [[ ! -x "$TERSER" ]]; then
        err "terser not found. Run: cd $BASE_DIR && npm install"
        exit 1
    fi
    if [[ ! -x "$CLEANCSS" ]]; then
        err "clean-css-cli not found. Run: cd $BASE_DIR && npm install"
        exit 1
    fi
}

# Compute md5 hash (first 8 chars) of a file
file_hash() {
    md5sum "$1" | cut -c1-8
}

# ---------- Clean ----------

do_clean() {
    log "Cleaning minified files..."
    rm -f "$PUB_JS"/*.min.js "$PUB_CSS"/*.min.css
    rm -f "$MANIFEST"
    log "Done."
}

# ---------- Build JS ----------

build_js() {
    local src="$1"
    local name
    name="$(basename "$src" .js)"

    # Skip already-minified vendor files
    if [[ "$name" == *.min ]]; then
        return
    fi

    local out="$PUB_JS/${name}.min.js"

    "$TERSER" "$src" \
        --compress ecma=2020,passes=2,drop_console=true \
        --mangle reserved='["$","jQuery","htmx","ibI18n","ibInsertImageToEditor","ibSetupImagePaste","ibSetupImageResize","ibSetupAutoLinkify","ibStripEditorArtifacts","ibInsertTableToEditor","ibDragReorder","ibNative","ibGetCsrfToken","ibFeatureDenied","ibFeatureDeniedPending"]' \
        --output "$out" >&2 2>&1

    local hash
    hash="$(file_hash "$out")"

    log "JS: $name.js → ${name}.min.js (hash: $hash)" >&2

    # Return manifest entry on stdout
    echo "\"${name}.js\": \"${name}.min.js?v=${hash}\""
}

# ---------- Build CSS ----------

build_css() {
    local src="$1"
    local name
    name="$(basename "$src" .css)"

    local out="$PUB_CSS/${name}.min.css"

    # Rewrite px font sizes as rem so the user's font-size preference
    # (Preferences > Interface) rescales this stylesheet too. Identical output
    # at the default scale. mailing-blocks.css is skipped: it styles blocks that
    # end up in outgoing email, which renders outside our root font-size.
    local input="$src"
    local scaled=""
    if [[ -f "$FONTSCALE" && "$name" != "mailing-blocks" ]]; then
        scaled="$(mktemp "${TMPDIR:-/tmp}/ib-fontscale-XXXXXX.css")"
        python3 "$FONTSCALE" "$src" "$scaled" >&2
        input="$scaled"
    fi

    "$CLEANCSS" \
        -O2 \
        -o "$out" \
        "$input" >&2 2>&1

    [[ -n "$scaled" ]] && rm -f "$scaled"

    local hash
    hash="$(file_hash "$out")"

    log "CSS: $name.css → ${name}.min.css (hash: $hash)" >&2

    # Return manifest entry on stdout
    echo "\"${name}.css\": \"${name}.min.css?v=${hash}\""
}

# ---------- Generate manifest ----------

generate_manifest() {
    local entries=("$@")
    local json="{"
    local first=true
    for entry in "${entries[@]}"; do
        if [[ -z "$entry" ]]; then continue; fi
        if $first; then
            first=false
        else
            json+=","
        fi
        json+=$'\n'"  $entry"
    done
    json+=$'\n'"}"
    echo "$json" > "$MANIFEST"
    log "Manifest written to $MANIFEST"
}

# ---------- Main ----------

main() {
    check_deps

    # Handle --clean
    if [[ "${1:-}" == "--clean" ]]; then
        do_clean
        exit 0
    fi

    # --lang: regenerate lang/*.php from the ib_lang table first (after editing
    # translations in System > Translations). Without it, lang/ is used as is.
    if [[ "${1:-}" == "--lang" ]]; then
        log "Generating lang files from database..."
        "${PHP:-php}" "$BASE_DIR/tools/build-lang.php" 2>&1 | while read -r line; do log "$line"; done
        shift
    fi

    local filter="${1:-}"
    local manifest_entries=()

    # Build JS
    for src in "$SRC_JS"/*.js; do
        [[ -f "$src" ]] || continue
        local name
        name="$(basename "$src" .js)"
        if [[ -n "$filter" && "$name" != "$filter" ]]; then
            continue
        fi
        entry="$(build_js "$src")"
        if [[ -n "$entry" ]]; then
            manifest_entries+=("$entry")
        fi
    done

    # Build CSS
    for src in "$SRC_CSS"/*.css; do
        [[ -f "$src" ]] || continue
        local name
        name="$(basename "$src" .css)"
        if [[ -n "$filter" && "$name" != "$filter" ]]; then
            continue
        fi
        entry="$(build_css "$src")"
        if [[ -n "$entry" ]]; then
            manifest_entries+=("$entry")
        fi
    done

    # If building all, regenerate full manifest
    if [[ -z "$filter" ]]; then
        generate_manifest "${manifest_entries[@]}"
    else
        # Partial build: merge new entries into existing manifest
        if [[ -f "$MANIFEST" ]]; then
            local tmp_manifest
            tmp_manifest="$(cat "$MANIFEST")"
            for entry in "${manifest_entries[@]}"; do
                [[ -z "$entry" ]] && continue
                local key val
                key="$(echo "$entry" | cut -d: -f1 | tr -d ' "')"
                val="$(echo "$entry" | cut -d: -f2- | tr -d ' "')"
                # Update existing key in manifest JSON using python3
                tmp_manifest="$(echo "$tmp_manifest" | python3 -c "
import sys, json
data = json.load(sys.stdin)
data['${key}'] = '${val}'
json.dump(data, sys.stdout, indent=2)
")"
            done
            echo "$tmp_manifest" > "$MANIFEST"
            log "Manifest updated for partial build"
        else
            generate_manifest "${manifest_entries[@]}"
        fi
    fi

    # Summary
    local js_orig css_orig js_min css_min
    js_orig=$(du -sb "$SRC_JS" 2>/dev/null | cut -f1)
    css_orig=$(du -sb "$SRC_CSS" 2>/dev/null | cut -f1)
    js_min=$(du -sb "$PUB_JS"/*.min.js 2>/dev/null | awk '{s+=$1}END{print s+0}')
    css_min=$(du -sb "$PUB_CSS"/*.min.css 2>/dev/null | awk '{s+=$1}END{print s+0}')

    echo ""
    log "=== Build Summary ==="
    log "JS:  $(numfmt --to=iec $js_orig) → $(numfmt --to=iec $js_min) ($(( 100 - js_min * 100 / js_orig ))% reduction)"
    log "CSS: $(numfmt --to=iec $css_orig) → $(numfmt --to=iec $css_min) ($(( 100 - css_min * 100 / css_orig ))% reduction)"
    log "Build complete!"
}

main "$@"
