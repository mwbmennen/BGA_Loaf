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
  player's own `myHand` values. No new PHP fields are anticipated for anything in this plan.
- **Every user-facing string is already wrapped** in `clienttranslate()` (PHP) or `_()` (JS),
  per the standing rule followed since Phase 1 — Phase 5 has no backlog of bare strings to
  retrofit, only an audit to confirm (§10) plus wrapping whatever new strings it introduces.
- **`ReviewEffectDescription`** (Phase 4, `modules/php/Core/ReviewEffectDescription.php`) is
  the single source of truth for a review effect's target/amount text — if Phase 5 wants a
  richer visual effect description (tooltip, icon+label), it reads the same effect array
  structure this class already consumes; no duplicate parsing needed.
- **The reputation-bonus stepped table (Phase 3) matches the physical board exactly** — see §3
  below. The numbers Phase 3 transcribed from a photo are confirmed correct against the actual
  board scan, and that scan is now the direct visual reference for where to place track
  markers, not something to re-derive.
- **24 round cards × 2 sides are already scanned at full print resolution**
  (`docs/card-scans/`, `docs/board-scan/`) — every card's art is fully baked per
  `(card_type, side)`, with no icon-compositing system to build client-side. "Which image to
  show" is just `card_type` + which side resolved, both already known server-side today.

## 2. Explicit scope boundary

**In scope for Phase 5:**

- An image asset pipeline: resize/compress/sprite the raw scans into `img/`, respecting BGA's
  own constraints (`img/README`'s "fewer than 10 files, no file larger than 4 Mb," the CSS
  sprite technique) — §4.
- Real board rendering: a reputation track with positioned player tokens, boss piles showing
  actual filed card art with a fraction-to-5 indicator — §5, §6.
- Real hand/commit/reveal: the player's own hand shown as clickable card art (not a text button
  list), and a simultaneous reveal animation once all cards are played — §7.
- The advanced-effect interactive UI (`discard_choice`/swap) reusing the same hand-card
  component, with ineligible cards visibly distinguished — §8.
- A real `loaf.css` layout pass — the file is still the blank scaffold template today.
- `console.log` cleanup and a translation-string audit (verification, not new backlog — §10).
- Sound: **stretch-only**, not a blocking deliverable — §9.

**Explicitly out of scope:**

- **Any Core/game-logic change.** Phase 5 must not touch `modules/php/Core/*` or change any
  PHPUnit-tested behavior — if a visual need seems to require new logic, the right answer is
  either data the client already has (§1) or a display-only computation done entirely
  client-side, never a rules change. This is the first phase where "the Core PHPUnit diff is
  empty" is itself a success criterion, not just an assumption (§11).
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
   `RoundCardData::TYPES`'s keys exactly. Each is a `747×1040` PNG, print resolution, ~1MB+
   apiece, **49MB total** for the folder — far too large and far too numerous to ship as-is
   per `img/README`'s own guidance (fewer than 10 files, none over 4MB). Every card's art is
   fully rendered already (icons, numbers, flavor art baked in) — no compositing needed,
   `card_type` + resolved side is a direct filename lookup, not a data-model problem.
2. **No art exists yet for a player's personal work-card hand** (12 cards per player, values
   0–11, "in their color" per the rules). Not scanned or photographed at this time, unlike the
   round-card deck, which got a full photo-driven transcription pass — confirmed by inspecting
   both scan folders directly: `docs/card-scans/` only contains round cards, and
   `docs/board-scan/` only contains two files, both of which are the *same* shared
   reputation-track board (see next point), not a distinct player mat. **Decision (updated)**:
   hand art is coming later, as a separate fast-follow — not fabricated now, and not blocking
   Phase 5. Ship a plain CSS placeholder card (the player's BGA-assigned color as background,
   the value as text) for this phase, built so the eventual real art is a drop-in swap, not a
   rework — see §4 step 6.
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
   are exactly 6, matching this game's max player count, but doesn't name them; now recorded
   and set as `gameinfos.jsonc`'s `player_colors` (`008000`/`ffa500`/`982fff`/`ff0000`/`ffffff`/
   `ffe500`), replacing BGA's generic 12-color scaffold default. §5's reputation-track tokens
   should use these exact colors via `player.color` (BGA already assigns from this list, no new
   plumbing needed) — but **white needs a visible border/outline on the token**, since the
   board's own background (§3 point 3's scan) is a light tan/cream in places, and a plain white
   token would have poor contrast sitting on it.

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

1. **Pre-flight every source image** before touching anything: `sips -g space` on each PNG/JPG
   to confirm `RGB`, not `CMYK` (the documented Firefox color-inversion risk), and confirm
   pixel-identical dimensions within any group of images destined for the same sprite sheet.
2. **Downscale before compressing.** The 747×1040 print-resolution scans are far larger than
   any BGA card component is actually rendered on screen (typically well under 200px wide) —
   resize to a fixed target width (e.g. 180px, aspect-preserved → ~180×251) as the *first*
   step, not an afterthought to recompressing at full size.
3. **Build sprite sheets, not 48 loose files** (`img/README`'s own "fewer than 10 files... use
   CSS sprites" guidance). A natural split: one sheet for the 24 order-side faces, one for the
   24 review-side faces — recompute the exact grid/tile size once step 2's target dimensions
   are locked in, and verify each sheet is under 4MB with `du -sh` after building, not by
   assumption before.
4. **Board**: `board.png` is a single background image, not a sprite-tiled asset — resize to
   its actual on-screen rendered width (a fraction of the 3313px scan), recompress, done.
5. **Card back**: no back-face scan exists. Per `docs/loaf-implementation-plan.md` §1, the
   order side is described as "still face-up from setup," suggesting the round-card deck may
   never need a hidden-face rendering at all — confirm this against `docs/Loaf-English-rules.md`
   before building anything; if a back genuinely turns out to be needed, it's a simple solid
   design, not something requiring a new scan.
6. **Player hand-card art**: no scan exists yet (§3 point 2) — build via CSS for this phase,
   skip the sprite pipeline entirely for now. Since real art is coming later as a separate
   fast-follow, keep the swap cheap: render each hand card from a single component keyed only
   by `value` + `player.color` (e.g. a `.work-card` element with `data-value`/a color-derived
   class), with no other code path assuming "hand cards are CSS-only" — when real art lands,
   it should be a matter of pointing that one component at an image (or sprite tile) instead of
   a solid-color background, not restructuring the hand/commit/reveal DOM built in §7.
7. **Adapt `tools/build-sprite.sh` from the Gelati project**
   (`/Users/rianmennen/Website/BGA/Gelati/BGA_Gelati/tools/build-sprite.sh`) rather than writing
   one from scratch — copy it in, then swap Gelati's `tiles`/`orders`/`parts` categories for
   L'Oaf's own: a 24-tile `montage` for the order-side faces, a second 24-tile `montage` for the
   review-side faces (grid dimensions/`geometry` recomputed for step 2's target size, not
   copied verbatim from Gelati's own 300×150/300×300 tile sizes), keeping the zero-padded `seq`
   loop, the `MONTAGE_FONT` pin, and the `check_size()` 4MB guard as-is. `tools/` is already in
   `.vscode/sftp.json`'s ignore list per `CLAUDE.md` — confirm it stays excluded, since this is
   a dev-time build script, not a runtime asset BGA Studio needs to serve.

## 5. Board & reputation-track rendering

- Replace the `#boss-piles`/`#player-tables` `insertAdjacentHTML` scaffolding
  (`modules/js/Game.js:213-241`) with a real layout: the reputation-track board image (§4) as a
  background, with one token per player positioned via CSS along the −10..+10 rail, driven by
  `player.reputation` at setup and `notif_reputationChanged` live — the same event already
  wired, now moving a positioned element instead of overwriting text content.
- Token color = `player.color`, a standard field BGA already supplies in `gamedatas.players` —
  reuse directly, no new PHP data needed. Now that `gameinfos.jsonc`'s `player_colors` is the
  6 real baker colors (§3 point 6) rather than BGA's generic default, tokens automatically match
  the physical components — no color-mapping logic needed on the client either.
- Give every token a visible border/outline regardless of color, not just as a white-specific
  patch — the board's background (§3 point 3) shifts between a light tan and a darker olive
  across the track, so any single flat token color risks blending into one half or the other.
  This matters most for the white player (§3 point 6) but is worth applying uniformly rather
  than special-casing one color.
- Position math (percentage of track width per reputation point) is pure client-side CSS/JS,
  no server involvement.

## 6. Boss piles & review-card reveal

- Replace the `${bossHappyCount} / 5` text counters with a real stack showing the actual filed
  card's art on top — `gamedatas.bossHappy`/`bossAngry` already carry `card_type` per card
  (keyed-by-id objects, per the already-documented Deck-component gotcha in
  `docs/bga-template-upstream-notes.md`: use `Object.keys(...).length`/`Object.values(...)`,
  never a bare `.length`). Resolve `card_type` + which side (success → Happy pile, fail →
  Angry pile, same mapping `ResolveRound.php` already uses) to a sprite-sheet tile.
- On `notif_roundResolved`, animate the just-filed card appearing on the correct pile, instead
  of only bumping the counter text (the counter's weighted-increment logic, shipped in Phase
  4's log-ordering fix, stays exactly as-is).
- On `notif_reviewEffectApplied`, consider a transient highlight/tooltip on the just-filed card
  showing the effect text — reuses the exact string the game log already receives via
  `ReviewEffectDescription`, no new PHP data needed.

## 7. Hand, commit, and reveal animation

- Player's own hand: replace `PlayCards`' status-bar `addActionButton` list
  (`modules/js/Game.js:71-77`) with real card visuals the player clicks directly, still calling
  the same `actCommitCard` action — purely a rendering change over the existing `handValues`
  array from `PlayCards::getArgs()`, no PHP change.
- Commit: the selected card visually moves to a face-down "committed" slot instead of just
  disabling the button list.
- Reveal: `notif_cardPlayedRevealed` (§1) is exactly the data a simultaneous multi-card reveal
  needs — flip every player's face-down card to show its value at that notification, ahead of
  the reputation-change animation that already follows it in call order (mirrors the
  cause-before-effect narrative-order fix already made server-side in Phase 4 — now applied
  visually too, not just in the log).

## 8. Advanced-effect interactive UI

- `ResolveAdvancedEffect`'s action buttons (`modules/js/Game.js:130-136`) get the same
  hand-card-visual treatment as §7's `PlayCards`, scoped to `eligibleValues` instead of the
  full hand (already computed server-side — no change needed there). Ineligible cards in hand
  should render grayed out/unclickable rather than simply being absent from a button list, so
  the player can see their whole hand and understand *why* only some cards are valid choices.

## 9. Sound

The top-level plan explicitly hedges this ("sound (if any)"), and no sound assets exist
anywhere in this repo or the scanned physical components — the rules document never mentions
audio. **Judgment call**: treat sound as a stretch goal only if a trivial, freely-licensed
effect (e.g. a single reveal chime) is easy to source and wire through BGA's standard sound
API — don't block Phase 5 completion on it, and don't invest scanning/commissioning effort
into something with zero source material and zero rules-text justification.

## 10. Translation-string and `console.log` audit

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
  text, tooltips) for `clienttranslate()`/`_()` wrapping — new code is the actual risk here,
  not the already-audited Phase 1–4 code.

## 11. Testing plan

Phase 5 is client/asset work, not Core logic — the existing PHPUnit suite (99 tests as of
Phase 4) is a **regression guard**, not something this phase adds new tests to:

- `vendor/bin/phpunit` must stay green, and — more importantly per §2's scope boundary — the
  diff touching `modules/php/Core/` for this phase should be **empty**. If it isn't, that's a
  signal scope has crept into logic territory that belongs in an earlier phase, not client
  polish.
- No new automated tests are expected: there's no PHPUnit-testable surface in CSS layout,
  sprite positioning, or animation timing. Correctness here is verified live (§12), the same
  "functional client, no local test harness" situation every prior phase's `States/*` adapters
  already accepted for their own thin BGA-glue code.

## 12. Live verification checklist (Studio)

Same format as Phase 4's §8 — check items off in place as they're confirmed.

### Asset pipeline sanity

- [ ] Every sprite sheet/board image uploads under BGA's 4MB ceiling (`du -sh img/*` checked
      before upload, not discovered after a failed sync).
- [ ] Colors render correctly in both Chrome and Firefox specifically — the documented
      CMYK-inversion gotcha is Firefox-specific and easy to miss if only tested in one browser.
- [ ] Hard-reload (`Cmd+Shift+R`) after every CSS/image sync — Studio aggressively caches CSS
      (already documented in `docs/bga-studio-reference.md`).

### Board & reputation track

- [ ] All player counts (2–6) show a token in the visually correct starting position
      (reputation 0).
- [ ] A token moves to the correct new track position immediately on `reputationChanged`, no
      refresh needed.
- [ ] Token color matches each player's assigned BGA color.

### Boss piles

- [ ] The correct card art (matching `card_type` + the side that actually resolved) appears on
      the correct pile the instant a round resolves.
- [ ] The weighted counter (already correct as of Phase 4) still reads correctly once real card
      art replaces the plain text counter.

### Hand / commit / reveal

- [ ] Own hand renders as real card art, one visual per value currently in hand.
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

## 13. Suggested implementation order

1. Asset pipeline first (§4) — nothing else in this phase can be verified without real images
   to point at.
2. Board & reputation track (§5) — the simplest integration (one background image, position
   math), a good first live-deploy smoke test for the new assets.
3. Boss piles (§6) — reuses the same sprite sheet, next simplest.
4. Hand/commit/reveal (§7) — the most involved piece (animation timing, replacing the action-
   button component); do this after the simpler pieces have already proven the asset pipeline
   works end-to-end.
5. Advanced-effect UI (§8) — a small delta on top of §7's component.
6. `console.log`/translation audit (§10) — last, sweeping everything Phase 5 itself just added
   alongside the pre-existing scaffold debug lines.
7. Sound only if trivial (§9); otherwise skip without regret.
8. Deploy, live-verify per §12, update `docs/loaf-remarks.md`.
