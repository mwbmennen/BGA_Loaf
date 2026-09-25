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
# DISPLAY_W/H is 2x the actual on-screen CSS size (180x251, Game.js's cardWidth/cardHeight) --
# background-size is percentage-based, so no CSS/JS changes are needed to consume a higher-res
# sheet here. The 2x factor gives headroom for browser zoom up to ~200% before the always-visible
# board/hand art starts upscaling-blurring, without ballooning file size all the way to the
# ZOOM_W/H tier's own weight (that tier stays intentionally sharper still, for the hover popup).
DISPLAY_W=360
DISPLAY_H=502
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
# quality 85, not the 90 used elsewhere -- at the 2x DISPLAY_W/H tier this sheet's 78 tiles push
# it over the 4MB BGA limit at 90 (measured ~4.4MB); 85 lands at ~2.8MB with no visible artifacts
# (compared crops directly), so it's a compression trade, not a resolution one -- doesn't need
# the same "split into two files" fix the ZOOM_W/H hand sheets already use.
montage -font "$MONTAGE_FONT" "${HAND_FILES[@]}" -tile 13x6 -geometry "${DISPLAY_W}x${DISPLAY_H}+0+0" -quality 85 "$IMG_DIR/hand-sheet.jpg"
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

# --- Board background (single image, not a sprite sheet -- resized to 2x the actual on-screen
# render width (740, matching gameinfos.jsonc's game_interface_width.min / bga-zoom's
# autoZoom.expectedWidth, docs/loaf-phase5-plan.md §6) rather than the raw 3313px scan or a bare
# 1x. CSS (`background-size: 100% 100%` on `.loaf-board` or equivalent) is percentage-based, so
# the on-screen size is unaffected -- the 2x factor only adds zoom headroom, same reasoning as
# DISPLAY_W/H above.) ---
magick "$BOARD_DIR/board.png" -resize "1480x" "$IMG_DIR/board.png"
check_size "$IMG_DIR/board.png"

# --- Boss-card sheet (2 tiles: angry, happy -- the fixed character card each boss pile's fan
# sits behind, docs/loaf-phase5-plan.md §7). Same 180x251 display tier as the round cards, no
# separate zoom tier (static flavor art, no per-card effect text worth zooming into) -- this is
# the 10th img/ file, using the last file-count margin §4 step 6/11 already flagged. ---
BOSS_FILES=(
    "$REPO_ROOT/docs/card-scans/angry_boss.jpg"
    "$REPO_ROOT/docs/card-scans/happy_boss.jpg"
)
for f in "${BOSS_FILES[@]}"; do
    [[ -f "$f" ]] || { echo "  MISSING: $f" >&2; exit 1; }
done
montage -font "$MONTAGE_FONT" "${BOSS_FILES[@]}" -tile 2x1 -geometry "${DISPLAY_W}x${DISPLAY_H}+0+0" -quality 90 "$IMG_DIR/boss-sheet.jpg"
check_size "$IMG_DIR/boss-sheet.jpg"

echo "Done. $(ls "$IMG_DIR" | grep -v README | wc -l | tr -d ' ') sprite/image files in $IMG_DIR."
