#!/usr/bin/env bash
# Stitches source card/token/board images into BGA-ready sprite sheets.
# Adapted from the Gelati project's tools/build-sprite.sh (same repo author,
# /Users/rianmennen/Website/BGA/Gelati/BGA_Gelati/tools/build-sprite.sh) -- same shape
# (montage, zero-padded seq loops, check_size() 4MB guard, MONTAGE_FONT pin), L'Oaf's own
# categories/grids. See docs/loaf-phase5-plan.md §4 for the sizing/grid decisions this encodes.
#
# Run from the repo root: bash tools/build-sprite.sh
# Requires: ImageMagick (brew install imagemagick)
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ORDER_CARDS_DIR="$REPO_ROOT/docs/card-scans/order-cards"
REVIEW_CARDS_DIR="$REPO_ROOT/docs/card-scans/review-cards"
WORKER_CARDS_DIR="$REPO_ROOT/docs/card-scans/worker-cards"
TOKENS_DIR="$REPO_ROOT/docs/player-tokens"
BOARD_DIR="$REPO_ROOT/docs/board-scan"
IMG_DIR="$REPO_ROOT/img"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

MAX_BYTES=$((4 * 1024 * 1024))  # 4 MB BGA limit

# montage renders a per-tile filename label by default, which needs a font; on a fresh
# ImageMagick install its own font database can be empty even though the system has fonts,
# causing "unable to read font" and a non-zero exit. Pointing at a real font file avoids it
# (same gotcha Gelati's script already hit -- docs/bga-studio-reference.md §5).
MONTAGE_FONT="/System/Library/Fonts/Helvetica.ttc"

# Round-card tile dimensions: small always-loaded display tier vs. separate zoom-quality tier
# for the per-card hover tooltip (docs/loaf-phase5-plan.md §4 steps 3-5). Both tiers share the
# round-card scans' aspect ratio (600x834 sources).
DISPLAY_W=180
DISPLAY_H=251
ZOOM_W=500
ZOOM_H=696

# Token tile dimensions: small board-marker size, not a card.
TOKEN_W=64
TOKEN_H=64

check_size() {
    local output="$1"
    local size
    size=$(wc -c < "$output")
    local kb=$(( size / 1024 ))
    if [[ $size -gt $MAX_BYTES ]]; then
        echo "  WARNING: $output is ${kb}KB -- exceeds the 4MB BGA limit." >&2
    else
        echo "  $output -- ${kb}KB OK"
    fi
}

echo "Building sprite sheets..."

# --- Order-side display sheet (24 tiles: basic_01..12, advanced_01..12, JPEG, 6x4 grid) ---
# Zero-padded explicit loop, never a glob -- glob order is alphabetical, not guaranteed to match
# the sprite-index lookup docs/loaf-phase5-plan.md §4 step 7 defines client-side.
ORDER_FILES=()
for n in $(seq -f "%02g" 1 12); do
    ORDER_FILES+=("$ORDER_CARDS_DIR/basic_${n}_order.jpg")
done
for n in $(seq -f "%02g" 1 12); do
    ORDER_FILES+=("$ORDER_CARDS_DIR/advanced_${n}_order.jpg")
done
for f in "${ORDER_FILES[@]}"; do
    [[ -f "$f" ]] || { echo "  MISSING: $f" >&2; exit 1; }
done
montage -font "$MONTAGE_FONT" "${ORDER_FILES[@]}" -tile 6x4 -geometry "${DISPLAY_W}x${DISPLAY_H}+0+0" -quality 90 "$IMG_DIR/order-sheet.jpg"
check_size "$IMG_DIR/order-sheet.jpg"

# --- Review-side display sheet (24 tiles, JPEG, 6x4 grid) ---
REVIEW_FILES=()
for n in $(seq -f "%02g" 1 12); do
    REVIEW_FILES+=("$REVIEW_CARDS_DIR/basic_${n}_review.jpg")
done
for n in $(seq -f "%02g" 1 12); do
    REVIEW_FILES+=("$REVIEW_CARDS_DIR/advanced_${n}_review.jpg")
done
for f in "${REVIEW_FILES[@]}"; do
    [[ -f "$f" ]] || { echo "  MISSING: $f" >&2; exit 1; }
done
montage -font "$MONTAGE_FONT" "${REVIEW_FILES[@]}" -tile 6x4 -geometry "${DISPLAY_W}x${DISPLAY_H}+0+0" -quality 90 "$IMG_DIR/review-sheet.jpg"
check_size "$IMG_DIR/review-sheet.jpg"

# --- Order-side zoom sheet (same 24 tiles, larger size, feeds the per-card hover tooltip) ---
montage -font "$MONTAGE_FONT" "${ORDER_FILES[@]}" -tile 6x4 -geometry "${ZOOM_W}x${ZOOM_H}+0+0" -quality 90 "$IMG_DIR/zoom-order.jpg"
check_size "$IMG_DIR/zoom-order.jpg"

# --- Review-side zoom sheet ---
montage -font "$MONTAGE_FONT" "${REVIEW_FILES[@]}" -tile 6x4 -geometry "${ZOOM_W}x${ZOOM_H}+0+0" -quality 90 "$IMG_DIR/zoom-review.jpg"
check_size "$IMG_DIR/zoom-review.jpg"

# --- Player token sheet (6 tiles, PNG for real alpha, 6x1 grid) ---
# No -quality flag (PNG, not JPEG); -background none preserves transparency instead of
# flattening onto white the way the JPEG card sheets above do.
TOKEN_FILES=(
    "$TOKENS_DIR/green.png"
    "$TOKENS_DIR/orange.png"
    "$TOKENS_DIR/purple.png"
    "$TOKENS_DIR/red.png"
    "$TOKENS_DIR/white.png"
    "$TOKENS_DIR/yellow.png"
)
for f in "${TOKEN_FILES[@]}"; do
    [[ -f "$f" ]] || { echo "  MISSING: $f" >&2; exit 1; }
done
montage -font "$MONTAGE_FONT" "${TOKEN_FILES[@]}" -tile 6x1 -geometry "${TOKEN_W}x${TOKEN_H}+0+0" -background none "$IMG_DIR/tokens.png"
check_size "$IMG_DIR/tokens.png"

# --- Hand-card display sheet (78 tiles: 72 fronts + 6 backs, JPEG, 13x6 grid) ---
# Explicit order: all 12 fronts per color, then that color's back, before moving to the next
# color -- deterministic and matches docs/loaf-phase5-plan.md §4 step 7's (color, value) lookup.
COLORS=(green orange purple red white yellow)
HAND_FILES=()
for color in "${COLORS[@]}"; do
    for n in $(seq -f "%02g" 0 11); do
        HAND_FILES+=("$WORKER_CARDS_DIR/work_${color}_${n}.jpg")
    done
    HAND_FILES+=("$WORKER_CARDS_DIR/work_${color}_back.jpg")
done
for f in "${HAND_FILES[@]}"; do
    [[ -f "$f" ]] || { echo "  MISSING: $f" >&2; exit 1; }
done
montage -font "$MONTAGE_FONT" "${HAND_FILES[@]}" -tile 13x6 -geometry "${DISPLAY_W}x${DISPLAY_H}+0+0" -quality 90 "$IMG_DIR/hand-sheet.jpg"
check_size "$IMG_DIR/hand-sheet.jpg"

# --- Hand-card zoom sheets (fronts only, no backs -- a repeating back pattern has no fine
# detail worth a hover-zoom). Split 3 colors per sheet (36 tiles each): a single 72-tile sheet
# measured 6.15MB, over the 4MB limit -- see docs/loaf-phase5-plan.md §4 step 10. ---
build_hand_zoom_sheet() {
    local output="$1"; shift
    local colors=("$@")
    local files=()
    for color in "${colors[@]}"; do
        for n in $(seq -f "%02g" 0 11); do
            files+=("$WORKER_CARDS_DIR/work_${color}_${n}.jpg")
        done
    done
    for f in "${files[@]}"; do
        [[ -f "$f" ]] || { echo "  MISSING: $f" >&2; exit 1; }
    done
    montage -font "$MONTAGE_FONT" "${files[@]}" -tile 6x6 -geometry "${ZOOM_W}x${ZOOM_H}+0+0" -quality 90 "$output"
    check_size "$output"
}
build_hand_zoom_sheet "$IMG_DIR/zoom-hand-1.jpg" green orange purple
build_hand_zoom_sheet "$IMG_DIR/zoom-hand-2.jpg" red white yellow

# --- Board background (single image, not a sprite sheet -- resized to the actual on-screen
# render width instead of the 3313px scan; 740 matches gameinfos.jsonc's
# game_interface_width.min / bga-zoom's autoZoom.expectedWidth, docs/loaf-phase5-plan.md §6) ---
magick "$BOARD_DIR/board.png" -resize "740x" "$IMG_DIR/board.png"
check_size "$IMG_DIR/board.png"

echo "Done. $(ls "$IMG_DIR" | grep -v README | wc -l | tr -d ' ') sprite/image files in $IMG_DIR."
