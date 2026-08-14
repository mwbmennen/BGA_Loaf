# L'Oaf — Phase 5 Implementation Plan

> Companion to `docs/loaf-phase4-plan.md` (Phase 4, complete and live-verified — see
> `docs/loaf-remarks.md`'s "Phase 4 live verification" entry) and
> `docs/loaf-implementation-plan.md` §7, which scopes Phase 5 as: "polish: reveal/board
> animations, sound (if any). Real art assets are already available (Q9) — integrate them
> directly rather than building placeholder art first. Every user-facing string must already be
> wrapped in BGA's translation helpers from Phase 1 onward (Q8), not deferred to this phase."

## 1. What Phases 1–4 already give us for free

Phase 5 is the first phase whose job is *not* new game logic — worth being precise about how
much of the plumbing it needs already exists, because the client has been deliberately
"functional, not pretty" for four phases specifically so this phase could focus on rendering,
not on inventing new data flows:

- **Every notification the client needs already exists and already carries the right data** —
  most are currently no-op stubs explicitly marked for this phase, e.g. `notif_roundStart`
  ("TODO: animate the new order/review card reveal once real art is wired in (Phase 5)") and
  `notif_roundResolved` ("TODO: reveal animation for all played cards once real art is wired
  in (Phase 5)"), both in `modules/js/Game.js`. Phase 5 fills in bodies, it doesn't add new
  `notify->all()` calls on the PHP side for anything already covered here.
- **`notif_cardPlayedRevealed`** (added in the log-narrative-order fix, `modules/js/Game.js:330`)
  already fires with `player_id` + `value` for every player the instant a round resolves,
  before any reputation change — exactly the data a simultaneous reveal animation needs,
  currently unused client-side (empty handler body).
- **`getAllDatas()` already returns everything needed to render the board**: weighted boss-pile
  counts (`bossHappyWeight`/`bossAngryWeight`), the actual filed card lists keyed by card id
  (`bossHappy`/`bossAngry`, including `card_type`), per-player `handCount`, and the current
  player's own `myHand` values. No new PHP fields are anticipated beyond §7's small
  `card_type`-exposure addition.
- **Every user-facing string is already wrapped** in `clienttranslate()` (PHP) or `_()` (JS),
  per the standing rule followed since Phase 1 — Phase 5 has no backlog of bare strings to
  retrofit, only an audit to confirm (§11) plus wrapping whatever new strings it introduces.
- **`ReviewEffectDescription`** (Phase 4, `modules/php/Core/ReviewEffectDescription.php`) is
  the single source of truth for a review effect's target/amount text — §7's hover tooltip
  reads the same string this class already produces for the game log, no duplicate parsing.
- **The reputation-bonus stepped table (Phase 3) matches the physical board exactly** — see §3
  below. The numbers Phase 3 transcribed from a photo are confirmed correct against the actual
  board scan, and that scan is now the direct visual reference for where to place track
  markers, not something to re-derive.
- **24 round cards × 2 sides are already scanned at full print resolution**
  (`docs/card-scans/`, `docs/board-scan/`) — every card's art is fully baked per
  `(card_type, side)`, with no icon-compositing system to build client-side. "Which image to
  show" is just `card_type` + which side resolved, both already known server-side today.
- **`gamedatas.bossHappy`/`bossAngry`/`myHand` are already exactly the PHP-Deck-shaped,
  keyed-by-id map data that `bga-cards` (§2) has a documented gotcha about.** Worth flagging
  now, before any code is written: BGA's own `bga-cards` documentation warns that "the default
  db accessor creates a map object rather than an array, which does not work with `addCards`,"
  requiring `array_values()` (PHP) or `Object.values()` (JS) first. This is the *same*
  underlying PHP shape `docs/bga-template-upstream-notes.md` already flagged once for a
  different reason (`getCardsInLocation()`'s `.length` gotcha) — two independent gotchas from
  the same root cause, worth remembering together.

## 2. Explicit scope boundary

**In scope for Phase 5:**

- **Adopt `bga-cards` + `bga-animations`** — BGA's official ESM card-rendering/animation
  libraries (confirmed via `en.doc.boardgamearena.com/BgaCards`) — for all card display and
  animation, replacing the plain-DOM `insertAdjacentHTML` approach Phases 1–4's placeholder
  client used. Covers boss-pile cards, the pending order/review cards, the player's own hand,
  commit/reveal, and the advanced-effect card choices — §4, §7, §8, §9.
- A per-card **hover tooltip showing a larger version of the card** (decided: a generic hover
  tooltip via `bga-cards`' own `addTooltipHtml` hook, not a custom click-to-zoom overlay) — §4,
  §7.
- **Adopt `bga-zoom`** — BGA's separate, standard whole-board zoom control
  (`en.doc.boardgamearena.com/BgaZoom`, `BgaZoom.Manager`). This is genuinely standard,
  expected BGA functionality (most games ship it), distinct from and complementary to the
  per-card tooltip above — one shows a single card bigger on hover, the other scales the whole
  board so a player can zoom in/out and pan a busy table. Both are in scope — §4, §6.
- An image asset pipeline: resize/compress/sprite the raw scans into `img/`, respecting BGA's
  own constraints (`img/README`'s "fewer than 10 files, no file larger than 4 Mb," the CSS
  sprite technique), now including a second "zoom-quality" tier feeding the per-card tooltip —
  §4.
- Real board rendering: a reputation track with positioned player tokens, boss piles showing
  actual filed card art with a fraction-to-5 indicator — §5, §7.
- Real hand/commit/reveal: the player's own hand as a `bga-cards` `HandStock`, and a
  simultaneous reveal animation once all cards are played — §8.
- The advanced-effect interactive UI (`discard_choice`/swap) reusing the same `HandStock`
  component, with ineligible cards visibly distinguished — §9.
- A real `loaf.css` layout pass — the file is still the blank scaffold template today.
- `console.log` cleanup and a translation-string audit (verification, not new backlog — §11).
- Sound: **stretch-only**, not a blocking deliverable — §10.

**Explicitly out of scope:**

- **Any Core/game-logic change.** Phase 5 must not touch `modules/php/Core/*` or change any
  PHPUnit-tested behavior — if a visual need seems to require new logic, the right answer is
  either data the client already has (§1) or a display-only computation done entirely
  client-side, never a rules change. This is the first phase where "the Core PHPUnit diff is
  empty" is itself a success criterion, not just an assumption (§12).
- **Studio playtesting, zombie-mode QA, edge-case QA (2-player minimum, all-fired ending,
  etc.)** — Phase 6, per `docs/loaf-implementation-plan.md` §7.
- **New game options** — polish needs none.
- **A custom "player board" mat graphic.** Only the shared reputation-track board was scanned
  (`docs/board-scan/`) — there is no physical player-mat component in this game beyond that
  shared track, confirmed by what actually exists in the scan folder (§3). Don't invent mat
  art with no source reference; a player's personal area is a styled panel, not an image asset.

## 3. Confirmed facts from the physical assets

Worth transcribing precisely, the same discipline every prior phase applied to the rules text —
here the "primary source" is the scan folders themselves, actually opened and inspected for
this plan rather than assumed from filenames:

1. **`docs/card-scans/` has exactly 48 files** — 24 card types (`basic_01`...`basic_12`,
   `advanced_01`...`advanced_12`) × `_order`/`_review` suffixes, matching
   `RoundCardData::TYPES`'s keys exactly. Each was `747×1040` PNG, print resolution, ~1MB+
   apiece, **49MB total** for the folder when first inspected — far too large and far too
   numerous to ship as-is per `img/README`'s own guidance (fewer than 10 files, none over 4MB).
   **Update: being replaced with JPG versions, resized to ~600×836px, not kept at print
   resolution.** Consistent with §4 step 3's JPEG decision for the pipeline's own output, and
   removes a PNG→JPEG conversion step from the pipeline entirely for these sources (zero-padded
   naming convention/aspect ratio expected to stay the same, only format + size change).
   **~600×836 specifically, not smaller**: the pipeline's largest downstream tier is the
   500×696 zoom sheet (§4 step 5) — the source must stay at or above that, or the zoom tier ends
   up upscaled and blurry (you can always downscale further, never upscale without quality
   loss). ~600×836 gives a little headroom above 500×696 without carrying unnecessary
   print-resolution weight — nothing downstream ever needs more than 500×696, so anything past
   that margin is wasted size for no benefit. At that size, one card lands around ~150KB JPEG
   q90 (tested), so all 48 replacement files together land around **~7MB total**, versus the
   original 49MB. Every card's art is fully rendered already (icons, numbers, flavor art baked
   in) — no compositing needed, `card_type` + resolved side is a direct filename lookup, not a
   data-model problem.
2. **Update: real work-card (hand) art now exists — no longer a CSS placeholder/fast-follow.**
   Originally not scanned or photographed at all (unlike the round-card deck's full
   photo-driven transcription pass); since provided at `docs/card-scans/worker-cards/`, naming
   `work_{color}_{00..11}.jpg` plus `work_{color}_back.jpg` — **78 files** (6 colors × (12
   values + 1 back)). Directly inspected: all 78 confirmed `RGB` (not CMYK — passes §4 step 2's
   preflight check), pixel-identical at `600×834` (right at the ~600×836 target size discussed
   before any of it existed). Per the top-level plan's own "integrate real art directly rather
   than building placeholder art first" instruction, this is now real pipeline work for *this*
   phase (§4 step 10), not deferred — `HandStock`'s `setupFrontDiv` (§8) points at the real
   sprite sheet from the start.
   **The backs are color-specific, not one shared generic design** — confirmed via checksum
   (all 6 `work_{color}_back.jpg` files differ) and visually (each is the same decorative tile
   motif as the board's own background, §3 point 4, tinted to that player's color) — a real,
   deliberate asset, not a placeholder, so `bga-cards`' `setupBackDiv` (§4 step 9's note) now has
   real art to render too, not just a hypothetical.
3. **`docs/board-scan/board.png` (3313×1040 PNG) and `LOAF-player-board.jpg` (3482×1214 JPEG)
   are the same board content** — the JPEG is a print-proof copy with crop-mark registration
   targets in the corners, not a second distinct component. **Judgment call**: use `board.png`
   only (no crop marks, PNG transparency support); the JPEG isn't a web asset candidate at all.
4. **The board's own layout confirms Phase 3's reputation-bonus table was transcribed
   correctly**: the scanned board shows the −10..−1 / 0 / 1..10 reputation rail with the
   1–3 → +2, 4–6 → +3, 7–9 → +4, 10 → +5 bonus bands printed directly beneath it — an exact
   match for `ScoringCalculator::reputationBonus()`. This is a second independent confirmation
   of those numbers (the first was the photo referenced in `docs/loaf-remarks.md`'s Phase 3
   entry), and gives an exact visual reference for where to place digital track markers —
   nothing here needs re-deriving, only rendering.
5. **Order-side card art already bakes in the per-player-count target totals** as a small
   table with chef-hat icons (sampled from `basic_01_order.png`: totals for 2–6 players). This
   is cosmetic confirmation that the physical card matches `RoundCardData`'s transcribed
   values — not new information, and not something the client needs to parse out of the image;
   the numbers already live in `RoundCardData`/`getAllDatas()`.
6. **The 6 physical player colors are green, orange, purple, red, white, and yellow** —
   `docs/Loaf-English-rules.md`'s "6 baker figures (in each of 6 player colours)" confirms there
   are exactly 6, matching this game's max player count, but doesn't name them. Sampled directly
   from `docs/player-tokens/*.png` and set as `gameinfos.jsonc`'s `player_colors` — `36A148`
   (green), `E67524` (orange), `7A83BE` (purple), `961B20` (red), `EEF9FE` (white), `DEB725`
   (yellow) — replacing BGA's generic 12-color scaffold default.
7. **Correction: `docs/player-tokens/*.png` are real usable token art, not just
   color-reference photos.** Opened directly and inspected (same discipline as every other
   asset in this section): each is a `201×201` **transparent** PNG (confirmed RGB, not CMYK —
   passes §4 step 2's preflight check already) of an illustrated baker-hat character in that
   player's color, not a flat swatch. §5's reputation-track tokens should render this actual
   art via a small sprite (§4), not a plain CSS-colored dot as originally planned — real assets
   exist for this, so per the top-level plan's own "integrate them directly rather than
   building placeholder art first" instruction, use them. **Consequence for the earlier
   "white needs a border" note**: a plain CSS `border` doesn't apply cleanly to an irregular
   silhouette like this — use a CSS `filter: drop-shadow(...)` instead, uniformly on every
   token color (the art's own black linework helps, but a large white-filled shape still needs
   more separation from a light tan board background than outline strokes alone provide).
8. **Tested, not assumed: a 24-tile sprite sheet's real file-size ceiling is far above anything
   the always-loaded board UI will ever need.** Built real 24-tile sheets (all review-side
   cards, 6×4 grid, JPEG quality 90) at several sizes to find where BGA's 4MB-per-file limit
   actually bites: 180×250px/tile → 525KB, 320×445 → 1.38MB, 600×835 → 3.61MB, 650×904 → 4.09MB
   (over). So the real technical ceiling is **~600–620px tile width** if 24 cards must share
   one sheet — far beyond the ~150–250px anything on the always-loaded board will render at on
   a 740px-wide interface (§4 step 3's 180px decision already has 2x headroom over a generous
   ~90px on-screen size). The 4MB-per-sheet rule is not the binding constraint for the
   always-loaded board art; screen layout is. (The separate zoom-quality tier, §4 step 5, is
   sized differently because it answers a different question — legibility on hover, not
   board-layout real estate.) The same test applied to the 6 player tokens (§3 point 7) at a
   much smaller, board-marker-appropriate size (64×64px/tile, PNG for alpha) totals **34KB for
   all 6 combined** — trivial.

## 4. Asset pipeline

Unlike every prior phase, this repo has **no image-processing work done at all yet** —
`loaf.css` is still the blank scaffold template, and `img/` contains only the placeholder
`README`. `docs/bga-studio-reference.md` §5 already documents generic sprite-sheet pitfalls
(CMYK-vs-RGB color corruption, pixel-drift misalignment, a bash brace-expansion zero-padding
trap) and references a `tools/build-sprite.sh` as an established pattern for handling them —
no such script exists in *this* repo, but a real one already exists in the sibling **Gelati**
BGA project (`/Users/rianmennen/Website/BGA/Gelati/BGA_Gelati/tools/build-sprite.sh`, another
project by the same author) and should be **adapted from there, not written from scratch**.
It's Gelati-specific as-is (hardcoded `montage` grids for its own tiles/orders/parts
categories), but the reusable shape is exactly what L'Oaf needs: an `ImageMagick montage` call
per sheet, a zero-padded `seq -f "%02g"` input loop (the exact gotcha already documented), and
a `check_size()` helper that warns if a built sheet exceeds the 4MB limit instead of finding
out after a failed Studio upload. It also surfaces one gotcha not yet written down anywhere in
this repo's docs: **`montage` renders a per-tile filename label by default, which needs a real
font file path on a fresh ImageMagick install (`brew install imagemagick`) or it fails
immediately with "unable to read font"** — Gelati's script pins
`MONTAGE_FONT="/System/Library/Fonts/Helvetica.ttc"` to work around this. Worth adding to
`docs/bga-studio-reference.md` §5 alongside the existing sprite-sheet pitfalls once ported
(`docs/bga-template-upstream-notes.md` is where that queue lives, per `CLAUDE.md`'s standing
"Template notes" rule) — every future BGA game with card art will hit the same thing.

Concrete steps:

1. **Done — `bga-cards`, `bga-animations`, and `bga-zoom` wired up** in
   `modules/js/Game.js`'s new `setupCardsAndZoom()` (called first thing in `async setup()`,
   which itself had to become `async` — the framework's own `.d.ts` types `setup()` as
   returning `void`, but doesn't actually await it, so an `async` override is safe in practice).
   All three really are real, versioned, ESM-importable libraries, pinned `1.x`. Real `.d.ts`
   files downloaded from BGA's own CDN (`https://x.boardgamearena.net/data/game-libs/{name}/1.x/dist/{name}.d.ts`
   — a genuine, working URL, not guessed) into `bga-cards.d.ts`/`bga-animations.d.ts`/
   `bga-zoom.d.ts` at the project root, alongside the existing `bga-framework.d.ts`; each file's
   trailing `export {...}` line stripped per BGA's own documented advice, so they stay ambient
   global declarations like `bga-framework.d.ts` rather than becoming isolated ES modules.
   **Two things only found by actually doing this, not by reading the docs**:
   - `bga-cards.d.ts` ships its own loose `type AnimationManager = any;` placeholder (for
     standalone use without `bga-animations`), which collides (`tsc`: `TS2300: Duplicate
     identifier`) with `bga-animations.d.ts`'s real `declare class AnimationManager` the moment
     both files sit in the same project — always true here, since both are imported together.
     Fix: delete the placeholder line from `bga-cards.d.ts`, letting the real class win.
     Confirmed clean via `npx tsc --noEmit` afterward.
   - `CardManagerSettings` has a native `cardBorderRadius` option (e.g. `'8px'`) — the library
     handles rounded corners itself. This **replaces** §3 step 3's originally-planned approach
     (a custom `border-radius` + `overflow: hidden` CSS class on a wrapper element) with
     something simpler; the JPEG-not-PNG reasoning in that step is unaffected (still true
     regardless of which layer draws the rounding), only the "how" changes.
   Managers created so far: `this.animationManager` (`BgaAnimations.Manager`), a round-card
   `this.roundCardsManager` and a hand-card `this.handCardsManager` (both `BgaCards.Manager`,
   `cardWidth: 180, cardHeight: 251, cardBorderRadius: '8px'`, matching the small display tier's
   real dimensions), and `this.boardZoom` (`BgaZoom.Manager`, wrapping
   `this.bga.gameArea.getElement()`, `expectedWidth: 740`, `localStorageZoomKey: 'loaf-zoom'`).
   No Stocks built yet — that's §5-§9's job, consuming these Managers once real game data flows
   through them.
2. **Pre-flight every source image** before touching anything: `sips -g space` on each PNG/JPG
   to confirm `RGB`, not `CMYK` (the documented Firefox color-inversion risk), and confirm
   pixel-identical dimensions within any group of images destined for the same sprite sheet.
3. **Downscale before compressing, decided target: 180×251px, JPEG.** The 747×1040
   print-resolution scans are far larger than any BGA card component is actually rendered on
   screen — 180px is 2x a ~90px on-screen display size (retina-ready, no blur), aspect-preserved
   from the 747:1040 scan ratio. **Format decision**: JPEG, not PNG — rounded card corners are
   done with CSS (`border-radius` + `overflow: hidden`) on the element wrapping each card image,
   not baked into per-tile alpha transparency. This was a deliberate trade-off: baking rounded
   corners into the art would require PNG (and real per-pixel alpha, since these are painted
   card faces, not solid shapes — no cheap PNG-8 palette trick available), landing meaningfully
   bigger than JPEG even after `pngquant`-style lossy compression, for a visual result the CSS
   approach gets identically with one shared rule reused across every card. JPEG quality ~90 at
   this size should land each card face around 15–30KB.
4. **Build the small display sprite sheets, not 48 loose files** (`img/README`'s own "fewer
   than 10 files... use CSS sprites" guidance). One JPEG sheet for the 24 order-side faces, one
   JPEG sheet for the 24 review-side faces, using step 3's locked-in 180×251 tile size — verify
   each sheet is under 4MB with `du -sh` after building, not by assumption before.
5. **Build a second, zoom-quality tier feeding the per-card hover tooltip (§2, §7):
   500×696px/tile, JPEG, kept in its own separate sheets.** Tested directly (same 6×4-grid,
   quality-90 method as §3 point 7): a 24-tile sheet at this size totals **2.8MB** — safely
   under the 4MB ceiling, with real headroom. Deliberately kept in *separate* sheets from step
   4's small display sheets (`zoom-order.jpg`/`zoom-review.jpg`, not merged in), since they're
   only fetched when a tooltip actually renders, not on every page load — no reason to add that
   weight to the always-loaded board. (This tier is unrelated to §6's `bga-zoom` whole-board
   control — that scales whatever's already rendered, it doesn't need its own image asset.)
6. **Build the player-token sprite sheet: 6 tiles, 64×64px, PNG (alpha required).** Unlike
   round cards, these need real transparency — an irregular chef-hat silhouette, not a
   rectangle, so there's no CSS-corner-rounding equivalent trick available here; PNG is the
   right call for this one asset, not JPEG. Tested directly: a 6-tile sheet at this size totals
   **34KB** — trivial. Source is `docs/player-tokens/{color}.png` (§3 point 7), already
   confirmed RGB and pixel-identical (201×201 each). Total image inventory across every step in
   this section, including the hand-card sheets (step 10): 2 small round-card sheets + 2
   round-card zoom sheets + 1 token sheet + 1 hand-card display sheet + 2 hand-card zoom sheets +
   `board.png` = **9 files**, still under `img/README`'s "fewer than 10" guidance, though with
   less margin than before — worth keeping in mind before adding any further sheet.
7. **Sprite index mapping.** `bga-cards`' documented convention keys sprite position off a
   numeric index (`div.style.backgroundPositionX = calc(100% / N * (index - offset))`), but
   this game's card identity is a *string* (`card_type`, e.g. `"basic_01"`) — build a small,
   stable, ordered lookup (`basic_01`→0, ..., `basic_12`→11, `advanced_01`→12, ...,
   `advanced_12`→23) on the client, matching the exact tile order `tools/build-sprite.sh`'s
   zero-padded loop produces (step 11) — the index must come from this lookup, never parsed out
   of the `card_type` string itself, so the two stay in sync by construction, not convention.
   (Tokens, step 6, don't need this — there are only 6, keyed directly by `player.color`.) Hand
   cards (step 10) need a similar lookup, but a two-part one: a `(color, value)` → index-within-
   `hand-sheet.jpg` mapping for the display/back tier, plus a separate `color` → which-zoom-sheet
   (`zoom-hand-1.jpg` vs `zoom-hand-2.jpg`) + index-within-that-sheet mapping for the zoom tier,
   since that tier is split by color group rather than being one sheet like the round cards.
8. **Board**: `board.png` is a single background image, not a sprite-tiled asset — resize to
   its actual on-screen rendered width (a fraction of the 3313px scan), recompress, done.
9. **Card back**: no back-face scan exists. Per `docs/loaf-implementation-plan.md` §1, the
   order side is described as "still face-up from setup," suggesting the round-card deck may
   never need a hidden-face rendering at all — confirm this against `docs/Loaf-English-rules.md`
   before building anything; if a back genuinely turns out to be needed, it's a simple solid
   design, not something requiring a new scan. (If needed, it also becomes what `bga-cards`'
   `setupBackDiv` renders — see §7's note on `isCardVisible`.)
10. **Player hand-card art: real art now provided (§3 point 2), tested and sprited like every
    other asset — no longer CSS-only.** Source: `docs/card-scans/worker-cards/`, 78 files
    (72 fronts + 6 color-specific backs). Two sheets, both tested directly against the real
    files (not estimated):
    - **Small display tier, fronts + backs combined: 78 tiles, 180×251, 13×6 grid, JPEG q90 —
      `img/hand-sheet.jpg`, tested at 1.38MB.** Backs are display-quality only (a repeating
      tile pattern has no fine detail worth a hover-zoom), so they're only in this tier, not
      duplicated into a zoom sheet.
    - **Zoom tier, fronts only, split by color into two sheets of 36 tiles each (500×696, 6×6
      grid, JPEG q90)** — a single 72-tile sheet measured at 6.15MB, over the 4MB ceiling, so it
      needs splitting; grouped 3 colors per sheet rather than by value, both tested directly:
      `img/zoom-hand-1.jpg` (green/orange/purple) at **3.02MB**, `img/zoom-hand-2.jpg`
      (red/white/yellow) at **3.13MB** — a per-color split (6 sheets of ~1.05MB each) would also
      work but costs 3 more files for no real benefit once the 3-colors-per-sheet grouping is
      already safely under 4MB.
    - Build order/grouping must be **explicit and deterministic** (an ordered loop over a fixed
      color/value list), not a filesystem glob sorted alphabetically — a glob interleaves
      `work_green_back.jpg` before `work_green_00.jpg` before `work_orange_...`, which is fine
      for a one-off size test but would silently scramble the real sprite-index mapping (step 7)
      if relied on for the actual build.
    - `HandStock`'s `setupFrontDiv`/`setupBackDiv` (§8) point at these real sheets from the
      start — no CSS-placeholder stage, no later swap-in needed, per the top-level plan's own
      "integrate real art directly" instruction now that the art actually exists.
11. **Done — `tools/build-sprite.sh` adapted from the Gelati project**
    (`/Users/rianmennen/Website/BGA/Gelati/BGA_Gelati/tools/build-sprite.sh`), all **nine**
    sheets (§4 asset manifest below has the final built sizes) built and verified. Correction to
    an earlier assumption in this same step: the token sheet (step 6, PNG, `-background none`
    instead of a JPEG quality flag) still needs `-font "$MONTAGE_FONT"` even though it renders
    no visible per-tile label — `montage` attempts the label-rendering font lookup regardless of
    grid size or whether a label ends up visible, and fails outright without it (confirmed live
    while building this script: `montage: unable to read font` on the token call specifically,
    which was the one call missing the flag). Apply `-font` to every `montage` invocation in
    this script unconditionally, not just the ones expected to show readable labels. Every sheet
    is built from an explicit, zero-padded, deterministically-ordered file list matching the
    tile-order lookups (step 7), so the index mapping never drifts from what's actually in each
    sheet. `tools/` is already in `.vscode/sftp.json`'s ignore list per `CLAUDE.md` — confirmed
    it stays excluded, since this is a dev-time build script, not a runtime asset BGA Studio
    needs to serve.
12. **Two `bga-cards`-specific gotchas worth planning around before writing any code**, both
    confirmed directly from BGA's own documentation:
    - **PHP Deck map-vs-array casting** (§1's last bullet): `addCards()` rejects a PHP
      associative/keyed-by-id array serialized as a JS object — cast with `array_values()`
      server-side or `Object.values()` client-side before ever calling `addCards()`. Applies
      directly to `bossHappy`, `bossAngry`, and `myHand`.
    - **Integer-typed fields, sent as strings.** `bga-cards`' own sort/comparison helpers (e.g.
      `BgaCards.sort`) assume numeric fields like `type`/`value` are real integers, but PHP
      sends everything as a JSON string by default — the *exact same* "DB values come back as
      strings" gotcha this project already got bitten by once in Phase 1
      (`in_array($value, $dbResults, true)` silently rejecting valid moves, per
      `docs/bga-template-upstream-notes.md`). Apply the same `array_map('intval', ...)`
      discipline to any field `bga-cards` will sort or position by, particularly
      `work_card.value`.

### Asset manifest

Consolidated reference for the steps above — source images (what must exist before the
pipeline runs) and build outputs (what the pipeline produces into `img/`) are two different
lists; don't confuse "I need to supply this" with "the script generates this."

**Source images**

| Asset | Location | Naming | Count | Size / format | Status |
|---|---|---|---|---|---|
| Round card scans (order side) | `docs/card-scans/order-cards/` | `{basic\|advanced}_{01..12}_order.jpg` | 24 | 600×834 JPEG, confirmed RGB | Provided |
| Round card scans (review side) | `docs/card-scans/review-cards/` | `{basic\|advanced}_{01..12}_review.jpg` | 24 | 600×834 JPEG, confirmed RGB | Provided |
| Board scan | `docs/board-scan/` | `board.png` | 1 | 3313×1040 PNG | Provided |
| Player tokens | `docs/player-tokens/` | `{green\|orange\|purple\|red\|white\|yellow}.png` | 6 | 201×201 PNG, alpha | Provided |
| Hand-card art (fronts) | `docs/card-scans/worker-cards/` | `work_{color}_{00..11}.jpg` | 72 | 600×834 JPEG (confirmed RGB, pixel-identical) | Provided |
| Hand-card art (backs) | `docs/card-scans/worker-cards/` | `work_{color}_back.jpg` | 6 | 600×834 JPEG (confirmed RGB) — color-specific, not one shared design (§3 point 2) | Provided |
| Round card back | *(TBD — may not be needed at all)* | TBD | TBD | TBD | **Conditional** — confirm against `docs/Loaf-English-rules.md` before commissioning anything, §4 step 9. (Not the same question as the hand-card back above, which is already resolved — this is specifically about the shared round-card deck.) |

**Build outputs** (`img/`, generated by `tools/build-sprite.sh` from the sources above — not hand-created)

| Sheet | Filename | Tiles | Tile size | Format | Grid | Size |
|---|---|---|---|---|---|---|
| Order display | `img/order-sheet.jpg` | 24 | 180×251 | JPEG q90 | 6×4 | 424KB (built) |
| Review display | `img/review-sheet.jpg` | 24 | 180×251 | JPEG q90 | 6×4 | 559KB (built) |
| Order zoom | `img/zoom-order.jpg` | 24 | 500×696 | JPEG q90 | 6×4 | 2.24MB (built) |
| Review zoom | `img/zoom-review.jpg` | 24 | 500×696 | JPEG q90 | 6×4 | 2.72MB (built) |
| Player tokens | `img/tokens.png` | 6 | 64×64 | PNG, alpha | 6×1 | 41KB (built) |
| Board background | `img/board.png` | 1 (not tiled) | 740px wide (matches `gameinfos.jsonc`'s `game_interface_width.min` / §6's `autoZoom.expectedWidth`) | PNG | — | 267KB (built) |
| Hand-card display | `img/hand-sheet.jpg` | 78 (72 fronts + 6 backs) | 180×251 | JPEG q90 | 13×6 | 1.37MB (built) |
| Hand-card zoom, sheet 1 (green/orange/purple) | `img/zoom-hand-1.jpg` | 36 | 500×696 | JPEG q90 | 6×6 | 3.00MB (built) |
| Hand-card zoom, sheet 2 (red/white/yellow) | `img/zoom-hand-2.jpg` | 36 | 500×696 | JPEG q90 | 6×6 | 3.11MB (built) |

**All 9 sheets built and verified** — `tools/build-sprite.sh` (adapted from Gelati's, §4 step
11) runs cleanly end-to-end, every sheet lands under the 4MB ceiling with real margin, and each
was visually spot-checked (correct grid alignment, correct color/value in the right cell, token
alpha transparency intact) — not just file-size-tested. Total `img/` file count: **9**, still
under `img/README`'s "fewer than 10" guidance, but with only one file of margin left; think
carefully before adding a tenth.

## 5. Board & reputation-track rendering

Not part of the card-rendering libraries — the reputation track and its tokens aren't cards, so
this stays plain DOM/CSS exactly as originally planned. (§6's whole-board zoom wraps this
section's markup along with everything else, but doesn't change how it's built.)

- Replace the `#boss-piles`/`#player-tables` `insertAdjacentHTML` scaffolding
  (`modules/js/Game.js:213-241`) with a real layout: the reputation-track board image (§4) as a
  background, with one token per player positioned via CSS along the −10..+10 rail, driven by
  `player.reputation` at setup and `notif_reputationChanged` live — the same event already
  wired, now moving a positioned element instead of overwriting text content.
- **Token art = the real chef-hat token sprite (§3 point 7, §4 step 6), not a plain CSS-colored
  dot.** `docs/player-tokens/*.png` turned out to be genuine, ready-to-use illustrated art, not
  just color-reference photos — a background-image lookup keyed by `player.color` (one of the
  6 sprite tiles, §4 step 6) replaces what was originally planned as a flat circle. Now that
  `gameinfos.jsonc`'s `player_colors` is the 6 real baker colors (§3 point 6), the color→sprite
  mapping is a direct 1:1 lookup, no translation layer needed.
- Give every token a `filter: drop-shadow(...)` regardless of color, not just as a
  white-specific patch — the board's background (§3 point 3) shifts between a light tan and a
  darker olive across the track, so any single flat token color risks blending into one half or
  the other. A CSS `border` doesn't work cleanly on this art's irregular silhouette the way it
  would on a plain circle, hence `drop-shadow` instead (§3 point 7). This matters most for the
  white player but is worth applying uniformly rather than special-casing one color.
- Position math (percentage of track width per reputation point) is pure client-side CSS/JS,
  no server involvement. Using percentages rather than fixed pixels here also matters for §6:
  it's what keeps token positions correct at every zoom level, not just 100%.

## 6. Whole-board zoom (`bga-zoom`)

BGA's standard accessibility zoom control — confirmed real and documented at
`en.doc.boardgamearena.com/BgaZoom` — scales an entire game-area element, with its own on-screen
zoom controls and an auto-fit-on-load option. This is standard, expected functionality across
BGA games, and a genuinely different feature from §7's per-card hover tooltip (that shows one
card bigger on hover; this scales the whole board so a player can zoom in/out and pan a busy
table) — both are worth having, they solve different problems, not a choice between them.

**Done (§4 step 1)** — `this.boardZoom` (`BgaZoom.Manager`) already created in `Game.js`'s
`setupCardsAndZoom()`:
```javascript
this.boardZoom = new BgaZoom.Manager({
    element: this.bga.gameArea.getElement(), // everything setup() renders, not a separate wrapper div
    zoomControls: { color: ['black', 'rgb(229, 231, 235)'] }, // light/dark theme pair, not a single color
    localStorageZoomKey: 'loaf-zoom',
    autoZoom: {
        expectedWidth: 740, // matches gameinfos.jsonc's game_interface_width.min
        minZoomLevel: 0.5,
    },
});
```
**Correction from an earlier draft of this plan**: no separate `#game-board` wrapper `<div>`
needed (an earlier draft here referenced one that never actually existed in `Game.js`'s DOM) —
`this.bga.gameArea.getElement()` already returns exactly "the Game Area div (for all displayed
game components)" per `bga-framework.d.ts`'s own doc comment, and `ZoomManager`'s constructor
itself "place[s] the settings.element in a zoom wrapper," so it wraps its own container rather
than needing one prepared for it. Also corrected `zoomControls.color` from a single string to a
light/dark theme pair (`ZoomControls.color: string[] | string`, defaulting to
`['black', 'rgb(229, 231, 235)']`) — matches BGA's own convention for a control that needs to
read against both site themes, not just one.
- Imported alongside `bga-cards`/`bga-animations` in §4 step 1
  (`await importEsmLib('bga-zoom', '1.x')`), same versioning/`.d.ts` discipline.
- Wraps the **entire board** (reputation track, boss piles, pending order/review cards, hand,
  player panels) since it targets `gameArea` itself — not just §5's reputation track in
  isolation.
- `expectedWidth: 740` matches this project's own `game_interface_width.min` in
  `gameinfos.jsonc` (already `740`) — the `autoZoom` config should reflect the actual board
  width this game is designed for, not an arbitrary number copied from an example.
- `localStorageZoomKey` persists each player's chosen zoom level across page reloads/sessions —
  a real usability detail the library handles for free, worth using rather than resetting to a
  default every load.
- No interaction expected with `bga-cards`' sprite positioning (§4) — both rely on
  percentage/relative CSS, which is exactly what stays correct under a zoom transform — but
  worth a deliberate live check anyway (§13) rather than an assumption, given this is the first
  time this project combines a CSS-transform-based zoom with sprite-positioned art.

## 7. Boss piles & the pending order/review cards

- **Done (§4 step 1) — `this.roundCardsManager`** (`BgaCards.Manager`) already created in
  `Game.js`'s `setupCardsAndZoom()`, covering both order-side and review-side card objects
  (distinguished by a `side` field on each rendered card object, driving which sprite sheet
  `setupRoundCardFrontDiv` points at):
  ```javascript
  setupRoundCardFrontDiv(card, div) {
    const sheet = card.side === "order" ? "order-sheet.jpg" : "review-sheet.jpg";
    const index = ROUND_CARD_SPRITE_INDEX[card.card_type]; // §4 step 7's lookup
    div.style.backgroundImage = `url(${this.bga.images.getImgUrl(sheet)})`;
    div.style.backgroundSize = "600% 400%"; // 6 cols x 4 rows
    div.style.backgroundPositionX = spritePositionPercent(index % 6, 6);
    div.style.backgroundPositionY = spritePositionPercent(Math.floor(index / 6), 4);
  }
  ```
  **Correction from an earlier draft of this plan**: the background-position percentage must
  divide by `(columns - 1)`/`(rows - 1)`, not by `columns`/`rows` — verified from the CSS spec's
  own `background-position` percentage formula (`offset = (box - image) × pct/100`, and
  `image = columns × box` when `background-size` is `columns×100%`), not copied from BGA's own
  `bga-cards` usage-example snippet as originally drafted here, whose literal `/ 14`/`/ 3` only
  happens to be correct for whatever column/row count *that* specific example's sheet actually
  had — assuming it generalizes to L'Oaf's 6-column sheet would have been a visibly wrong crop,
  not a rounding error. `spritePositionPercent(index, count)` is the small shared helper that
  implements the correct formula once (`modules/js/Game.js`), used by both round cards and hand
  cards (§8) rather than duplicating the `(count - 1)` divisor at each call site.
  A separate hover tooltip (via `addTooltipHtml`, pointing at the zoom-quality sheet) is not
  wired up yet — that's still pending, tracked further down in this section, not part of the
  §4-step-1 wiring that's now done.
  `isCardVisible` is defined explicitly and always returns `true` rather than relying on the
  library's default (which reads `card.type`) — per `bga-cards`' own documented advice ("the
  documentation suggests always defining a custom function for clarity"), and because order/
  review cards genuinely are always public information in this game (unlike hand cards, §8),
  so there's no ambiguity to leave implicit.
- **Boss piles**: one `SlotStock`/`LineStock` each for `review_happy`/`review_angry`, seeded at
  setup from `gamedatas.bossHappy`/`bossAngry` (cast via `Object.values()` per §4 step 12's
  gotcha), appended to via `addCards()` on `notif_roundResolved` — replacing the
  `${bossHappyCount} / 5` text counter's card-less display with the actual filed card art. The
  counter's own weighted-increment logic (shipped in Phase 4's log-ordering fix) is untouched;
  only what renders alongside it changes.
- **Pending order/review card display (decided: show both, not just the filed pile).** Matching
  the physical game — both the current order card (target side) and the current review card
  (effect side) sit face-up on the table all round, not just once resolved/filed. Two more
  single-card `SlotStock`s near the piles, updated via `addCards`/`removeCards` on `roundStart`.
  **Requires a small PHP data-exposure addition, not currently present**: neither
  `reviewCardRevealed` nor `roundStart` (`modules/php/States/RoundStart.php`) sends the card's
  `card_type` today, only descriptive text/numbers — add `card_type` to both notifications'
  args, plus the currently-revealed review/order card types to `getAllDatas()` (for the
  initial page load / reconnect case, same "setup + notification" dual-exposure pattern already
  used for `bossHappyWeight`/`bossAngryWeight`). This is a states-adapter data-exposure tweak
  like that one, not a rules/Core change — still inside §2's scope boundary.
- **Hover tooltip = the per-card "zoom" decision, concretely.** Every round-card's
  `setupFrontDiv` calls `this.bga.gameui.addTooltipHtml(div.id, htmlContent)` (confirmed real
  hook, per `bga-cards`' own usage example) with `htmlContent` being a small element styled with
  the *zoom-quality* sheet (§4 step 5) at the same computed sprite index — a bigger, crisper
  version of the same card, on hover, no custom lightbox/modal needed. Fold
  `notif_reviewEffectApplied`'s effect text (`ReviewEffectDescription`'s string, already sent to
  the log) into that same tooltip HTML — one tooltip answers both "what does this look like
  bigger" and "what does it do," rather than two separate half-built mechanisms. (This is
  independent of §6's `bga-zoom` whole-board control — a player can zoom the whole board out to
  see everything, or hover one card to see it in detail; the two aren't mutually exclusive.)
- **Async gotcha, worth testing deliberately, not discovering live**: `addCards()`/
  `removeCards()` are documented as async (return Promises). Code that seeds a pile in `setup()`
  and expects it populated synchronously on the very next line — e.g. to compute some derived
  UI state — needs to `await` it first, per `bga-cards`' own documented caveat.

## 8. Hand, commit, and reveal animation

- **Done (§4 step 1) — `this.handCardsManager` (`BgaCards.Manager`) already created**, with
  `setupHandCardFrontDiv`/`setupHandCardBackDiv` both pointing at `img/hand-sheet.jpg` (§4 step
  10) via `positionHandCardSprite()`, the shared `(color, value)` sprite-index lookup (§4 step 7,
  `handCardSpriteIndex()` in `Game.js`) — value `null` selects that color's back tile. Not yet
  done: an actual `HandStock` consuming this Manager (below).
- **`HandStock`** (purpose-built for player hands, per `bga-cards`' own component list) replaces
  `PlayCards`' status-bar `addActionButton` list (`modules/js/Game.js:71-77`) — seeded from
  `gamedatas.myHand` (cast via `Object.values()`/`array_values()` per §4 step 12's gotcha), each
  card wires a click to the same `actCommitCard` action as before — purely a rendering change
  over data `PlayCards::getArgs()` already returns, no PHP change here.
- **Card flip / privacy**: `isCardVisible` is already defined explicitly on
  `this.handCardsManager` (unlike round cards in §7, this one is genuinely conditional — a
  player's own hand is visible, an opponent's isn't, and a committed-but-unrevealed card is
  face-down for everyone), reading a real `card.visible` field rather than leaving it at the
  library's default — but nothing populates that field with real per-card visibility yet; that's
  part of building the `HandStock` above, not done in this step. `setupBackDiv` already has real
  art to render for the face-down state (the color-specific back designs, §3 point 2, also in
  `img/hand-sheet.jpg`) instead of a placeholder. This is where the existing hand/discard privacy
  guarantee (`docs/loaf-open-questions.md` Q3) will actually get enforced client-side, once the
  `HandStock`/`card.visible` wiring is finished — real card faces (and backs) now exist to hide
  behind, the plumbing to actually hide them is still pending.
- **Commit**: `removeCards()`/`addCards()` between the `HandStock` and a face-down "committed"
  `SlotStock`, using `bga-cards`' own move/flip animation instead of hand-building one.
- **Reveal**: on `notif_cardPlayedRevealed` (§1), flip the committed card face-up (via
  `isCardVisible`) for every player at once, ahead of the reputation-change animation that
  already follows it in call order — mirrors the cause-before-effect narrative-order fix already
  made server-side in Phase 4, now applied visually too. `bga-cards`' built-in animation support
  (e.g. the `slideTo` pattern already shown in its own `VoidStock` workaround example) replaces
  what would otherwise be hand-rolled animation timing.
- **Real risk worth flagging up front, not discovering live**: `bga-cards`' own documented async
  caveat — *"`addCards` is async while `setSelectableCards` is not. So if you add cards to your
  hand in the `setup()` method, and try to set what is selectable in `onEnteringState()` — that
  won't work properly, unless you also promisify the call"* — lands on exactly the fault line
  this project has already been burned by **twice**: the Phase 1–2 `MULTIPLE_ACTIVE_PLAYER`
  lifecycle saga (`docs/bga-template-upstream-notes.md`'s missing-`setAllPlayersMultiactive()`/
  `_private`/`$activePlayerId`-vs-`$currentPlayerId` chain) and the separate
  `onEnteringState`'s-`isCurrentPlayerActive`-can-be-stale entry right after it. Treat every
  `addCards`/`removeCards` call a later hook depends on as needing an explicit `await`, and
  test the same "does this work on a live push, not just on page load" scenario that caught
  both earlier bugs — this is a new instance of an already-familiar risk category for this
  project, not a fresh one.
- `work_card.value` must be `intval`'d (already true server-side per Phase 1's own fix) before
  any `bga-cards` sort/positioning math touches it — §4 step 12's second gotcha, same underlying
  discipline as the original `in_array` fix, different call site.

## 9. Advanced-effect interactive UI

- The same `HandStock` component from §8 is reused for `ResolveAdvancedEffect`'s card choice
  (`modules/js/Game.js:130-136`'s current button list), scoped to `eligibleValues` (already
  computed server-side — no change needed there). Inside `setupFrontDiv`, toggle a CSS class
  based on membership (`div.classList.toggle('ineligible', !eligibleValues.includes(card.value))`)
  instead of building a separate button list — ineligible cards render dimmed/unclickable in
  place, so the player sees their whole hand and understands *why* only some cards are choices,
  rather than only ever seeing the eligible subset.

## 10. Sound

The top-level plan explicitly hedges this ("sound (if any)"), and no sound assets exist
anywhere in this repo or the scanned physical components — the rules document never mentions
audio. **Judgment call**: treat sound as a stretch goal only if a trivial, freely-licensed
effect (e.g. a single reveal chime) is easy to source and wire through BGA's standard sound
API — don't block Phase 5 completion on it, and don't invest scanning/commissioning effort
into something with zero source material and zero rules-text justification.

## 11. Translation-string and `console.log` audit

Not new work if the Phase 1–4 discipline held (every string already routed through
`clienttranslate()`/`_()`) — this is a verification pass over existing code, plus wrapping
whatever Phase 5 itself introduces:

- `grep -rn "console\.log" modules/js/` and comment out (never delete) every hit before
  shipping, per the standing template-notes item already logged in
  `docs/bga-template-upstream-notes.md`. Concretely, as of this plan: `Game.js`'s constructor
  (`console.log("loaf constructor")`), `setup()` (`"Starting game setup"`/`"Ending game
  setup"`), `setupNotifications()` (`"notifications subscriptions setup"`), and the
  `this.bga.states.logger = console.log` debug toggle — the scaffold's own comment on that
  last line already says "Remove before going to production!"; Phase 5 is the natural point to
  actually act on it now that the client is otherwise final.
- Sweep every literal string Phase 5's *own* new UI code introduces (button labels, alt/ARIA
  text, tooltip content) for `clienttranslate()`/`_()` wrapping — new code is the actual risk
  here, not the already-audited Phase 1–4 code.

## 12. Testing plan

Phase 5 is client/asset work, not Core logic — the existing PHPUnit suite (99 tests as of
Phase 4) is a **regression guard**, not something this phase adds new tests to:

- `vendor/bin/phpunit` must stay green, and — more importantly per §2's scope boundary — the
  diff touching `modules/php/Core/` for this phase should be **empty**. The only PHP touches
  anticipated anywhere in this plan are §7's small `card_type`-exposure addition to
  `RoundStart.php`/`getAllDatas()` — a states-adapter data-exposure tweak, same shape as
  Phase 4's `bossHappyWeight` addition, not a rules change. If the diff touches
  `modules/php/Core/` at all, that's a signal scope has crept into logic territory that belongs
  in an earlier phase, not client polish.
- No new automated tests are expected: there's no PHPUnit-testable surface in CSS layout,
  sprite positioning, or animation timing. Correctness here is verified live (§13), the same
  "functional client, no local test harness" situation every prior phase's `States/*` adapters
  already accepted for their own thin BGA-glue code.

## 13. Live verification checklist (Studio)

Same format as Phase 4's §8 — check items off in place as they're confirmed.

### Asset pipeline sanity

- [ ] Every sprite sheet/board image uploads under BGA's 4MB ceiling (`du -sh img/*` checked
      before upload, not discovered after a failed sync).
- [ ] Colors render correctly in both Chrome and Firefox specifically — the documented
      CMYK-inversion gotcha is Firefox-specific and easy to miss if only tested in one browser.
- [ ] Hard-reload (`Cmd+Shift+R`) after every CSS/image sync — Studio aggressively caches CSS
      (already documented in `docs/bga-studio-reference.md`).

### `bga-cards`/`bga-animations`/`bga-zoom` adoption

- [ ] All three libraries import cleanly (`importEsmLib('bga-cards', '1.x')`/`'bga-animations'`/
      `'bga-zoom'`) with no console errors, and their `.d.ts` files are present for
      IDE/type-checking (§4 step 1).
- [ ] `addCards()`/`removeCards()` async timing verified live across a real state transition
      (not just initial page load) — the documented async-vs-`onEnteringState` risk (§8),
      tested the same "live push, not page load" way the earlier `MULTIPLE_ACTIVE_PLAYER` bugs
      were caught.
- [ ] `bossHappy`/`bossAngry`/`myHand` render correctly from their PHP-Deck-shaped map data —
      not silently empty from a rejected map object (§4 step 12's `array_values()` gotcha).
- [ ] Card flip (`isCardVisible`) correctly keeps opponent hand cards and any not-yet-revealed
      committed card face-down until the right notification — a privacy regression check on top
      of the existing "opponent hands show count only" guarantee.
- [ ] `bga-zoom`'s controls appear, actually zoom the whole board (not just part of it), and
      `localStorageZoomKey` persists the chosen level across a page reload.
- [ ] Card art stays correctly positioned/aligned at zoom levels other than 100% — confirms
      §6's "percentage-based positioning should just work under a transform" assumption instead
      of leaving it as one.

### Board & reputation track

- [ ] All player counts (2–6) show a token in the visually correct starting position
      (reputation 0).
- [ ] A token moves to the correct new track position immediately on `reputationChanged`, no
      refresh needed.
- [ ] Token color matches each player's assigned BGA color.
- [ ] All 6 token sprites (real chef-hat art, not a placeholder dot) render correctly and stay
      legible against both the light-tan and darker-olive sections of the board background,
      including the white token specifically.

### Boss piles & pending cards

- [ ] The correct card art (matching `card_type` + the side that actually resolved) appears on
      the correct pile the instant a round resolves.
- [ ] The weighted counter (already correct as of Phase 4) still reads correctly once real card
      art replaces the plain text counter.
- [ ] The pending order card and pending review card both update correctly at the start of every
      new round, matching what the `roundStart`/`reviewCardRevealed` log text already says.
- [ ] Hovering any round card shows the zoom-quality tooltip, correctly positioned/cropped and
      matching the card underneath (not a stale or off-by-one sprite index), including the
      review effect's description text for cards where that applies.

### Hand / commit / reveal

- [ ] Own hand renders as real card art via `HandStock`, one visual per value currently in hand.
- [ ] Clicking a card commits it (same `actCommitCard` action as before, new visual only).
- [ ] All players' played cards reveal together (simultaneously or in fast sequence) once every
      player has committed, and the revealed values match what the game log already shows.
- [ ] Opponent hands still show only a count, never values, before commit — a privacy
      regression check worth deliberately re-verifying while reworking the hand component.

### Advanced-effect UI

- [ ] Eligible cards for `discard_choice`/swap effects are visually distinguished from
      ineligible ones in the full-hand view, not just absent from a separate list.

### Cleanup

- [ ] No `console.log` output in the browser console across a full played-through game.
- [ ] Spot-check that at least one Phase-5-introduced string actually renders translated under
      a non-English BGA UI language — confirms `clienttranslate()`/`_()` wrapping works
      end-to-end, not just that it's present in source.

### Close it out

- [ ] Update `docs/loaf-remarks.md` with a "Phase 5 live verification" entry, same convention
      as every prior phase.

## 14. Suggested implementation order

1. **Done** — `bga-cards`/`bga-animations`/`bga-zoom` wired up and the asset pipeline built
   together (§4) — the sprite index convention and the library setup turned out to be genuinely
   interdependent (§4 step 1's `AnimationManager` .d.ts collision, the corrected
   `spritePositionPercent()` formula), confirming they needed to happen as one pass rather than
   sequentially.
2. Board & reputation track (§5) — doesn't depend on `bga-cards` at all (tokens aren't cards),
   a good first live-deploy smoke test for the new image assets in isolation.
3. Whole-board zoom (§6) — layer it on top of §5's now-real board markup while the board is
   still simple, before §7–§9 add more moving parts to verify zoom against.
4. Boss piles & pending order/review cards (§7) — first real `bga-cards` integration; simpler
   than the hand (no player-driven interaction yet), good place to catch the async/PHP-Deck
   gotchas (§4 step 12) before they compound with §8's action-handling complexity.
5. Hand/commit/reveal (§8) — the most involved piece (animation timing, privacy-sensitive flip
   logic, replacing the action-button component); do this after §7 has already proven the
   library/asset pipeline works end-to-end.
6. Advanced-effect UI (§9) — a small delta on top of §8's component.
7. `console.log`/translation audit (§11) — last, sweeping everything Phase 5 itself just added
   alongside the pre-existing scaffold debug lines.
8. Sound only if trivial (§10); otherwise skip without regret.
9. Deploy, live-verify per §13, update `docs/loaf-remarks.md`.
