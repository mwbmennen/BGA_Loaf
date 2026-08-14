# L'Oaf — Phase 4 Implementation Plan

> Companion to `docs/loaf-phase3-plan.md` (Phase 3, complete and live-verified — see
> `docs/loaf-remarks.md`'s "Phase 3 is complete" entry) and `docs/loaf-implementation-plan.md`
> §7, which scopes Phase 4 as: "advanced round cards: discard-forced/recycle effects,
> played-card swap effects, end-of-game bonus/malus (+ doublers), double-counting cards;
> `with_advanced_cards` table option."

## 0. Terminology correction before anything else: this is a _table option_, not a _preference_

Came up when scoping this phase: the toggle for turning advanced cards on is a **BGA game
option** (`gameoptions.jsonc`), not a **BGA game preference** (`gamepreferences.jsonc`) —
these are two genuinely different mechanisms, confirmed from BGA's own docs
(`en.doc.boardgamearena.com/Options_and_preferences`):

- **Options**: "something usually in the rule book defined as 'variant'" — set once by the
  table creator before the game starts, shared by every player at the table, allowed to
  change actual game rules/content.
- **Preferences**: "personal choices of each player only visible to that specific player" —
  set individually by each player, anytime, purely cosmetic/interface (colorblind mode,
  whether to prompt for confirmation, etc.), and must **never** affect shared gameplay.

Advanced cards being shuffled into the one shared deck is a textbook example of the first
category — it's literally the rulebook's own "we recommend not using the advanced effects
when playing for the first time" variant advice (`docs/Loaf-English-rules.md` lines 74-76). It
can't be a per-player preference at all: the deck is shared, so there's no way for two players
at the same table to have a different answer. This was already anticipated correctly as a
table option in `docs/loaf-implementation-plan.md` §5 ("`with_advanced_cards` toggle... default
off") — just flagging the exact vocabulary here since it was worded as "preference" when this
phase was requested.

## 1. What Phases 1–3 already give us for free

- **All 12 advanced cards' full data is already in `RoundCardData`** — `target`/`effect`/
  `amount`/`counts_as_two` for both sides, transcribed since Phase 1 alongside the basic cards
  (`modules/php/Core/RoundCardData.php`'s own docblock: "porting the full data now avoids a
  second transcription pass"). Phase 4's data-side job is zero — it's pure logic/wiring.
- **`ReviewEffectResolver` already has the exact extension point.** Its early return —
  `if ($effect['effect'] !== 'reputation') { return []; }` — is precisely where every advanced
  effect type currently gets swallowed as a no-op. Phase 4 adds cases, it doesn't restructure
  the caller (`ResolveRound.php`) for the deterministic effects, per the class's own docblock.
- **`EndConditionChecker` is already weight-aware.** `counts_as_two` (the "empty effect"
  cards, `advanced_07`/`advanced_08`) needs zero new code — `weightedCount()` already reads
  this flag from `RoundCardData` per card, built weight-aware in Phase 2 specifically so
  Phase 4 wouldn't need to revisit it (`docs/loaf-phase2-plan.md` §2/§4).
- **`ScoringCalculator`'s `bonusPoints` parameter is already fully plumbed through**, from
  `EndGame.php` down to the final score — always zero-filled today (`docs/loaf-phase3-plan.md`
  §2), specifically so Phase 4 only needs to populate real values, not touch the signature or
  `EndGame.php`'s wiring shape.
- **The interactive-multiactive-state pattern already exists and is already debugged.**
  `PlayCards.php`/`Game.js`'s `PlayCards` class is a working `MULTIPLE_ACTIVE_PLAYER` state,
  including the exact activation-timing bug already found and fixed live
  (`docs/bga-studio-reference.md` §5, `docs/loaf-remarks.md`'s Phase 2 live-verification
  entry: move action buttons into `onPlayerActivationChange`, never `onEnteringState`). Phase
  4's new interactive state (§4 below) can copy this pattern directly instead of rediscovering
  the same framework gotcha from scratch.
- **`work_card`'s schema already supports every hand/discard movement these effects need** —
  `location ENUM('hand','played','discard')` (`dbmodel.sql`) already covers every state a card
  needs to move through for discard/recycle/swap effects. No schema change needed there.

## 2. Explicit scope boundary

**In scope for Phase 4:**

- Extending `ReviewEffectResolver` (or a sibling Core class — see §3) to handle every
  advanced-card effect type: `discard_choice`, `discard_recycle_lowest`,
  `swap_discard_lower_by_at_most`, `swap_discard_higher_by_at_least`, `end_game_bonus`,
  `end_game_malus`, `double_end_game_bonus`, `double_end_game_malus`. (`none` is already
  correctly handled as a no-op today — only relevant for its `counts_as_two` flag, already
  covered by §1.)
- A new interactive game state for the three effects that require a player's own choice
  (§4).
- Wiring deferred end-game effects into `EndGame.php` (§5).
- The `with_advanced_cards` table option (§0/§6), gating whether the 12 advanced cards are
  shuffled into the deck at setup at all.

**Explicitly out of scope:**

- Real client polish for any of this (card art for advanced effects, animations) — Phase 5,
  same "functional, not pretty" discipline as every prior phase.
- Anything beyond what `RoundCardData` already contains — no new card types, no rules beyond
  what's transcribed.

## 3. Rules confirmed directly from `docs/Loaf-English-rules.md`

1. **Two fundamentally different resolution timings**, stated explicitly (lines 189-193):
   "**End of game effects** (marked with a moon/end-of-game icon) add a new scoring rule. The
   bonus or minus points are awarded to players who meet the requirement **at the end of the
   game**." vs. every other advanced effect, which (like basic reputation effects) resolves
   immediately when the card is filed. This is the single most important architectural fact
   for this phase — see §5.
2. **The six non-end-game effect types, exact wording** (lines 205-208):
   - `discard_choice`: "discard a card of their choice from their hand." — needs a player
     decision.
   - `discard_recycle_lowest`: "take the lowest value card from their discard pile back into
     their hand. _If your discard pile is empty, this has no effect._" — fully deterministic,
     no decision needed.
   - `swap_discard_lower_by_at_most` (amount X): "take their played card back in hand. Then,
     they discard a card that is at most X lower than the played card. _If they can't, they
     discard the played card instead._"
   - `swap_discard_higher_by_at_least` (amount X): same shape, "at least X higher."
3. **The four end-game effect types, exact wording** (lines 209-210, 216-217): `Ribbon, +X` /
   `Ribbon, -X` ("gain/take X bonus/minus points at the end of the game"), and the two
   doublers, which "apply to all players" (line 212) rather than a specific target group:
   `Ribbon +, x2` ("all bonus points are doubled"), `Ribbon -, x2` ("all minus points are
   doubled").
4. **The one new target type**: `reputation_zero` — "All players with a reputation of exactly
   0" (line 199), used by `advanced_12` for both an end-game bonus and an end-game malus.
   Already a real case in `ReviewEffectResolver`'s existing `default => throw ...` branch for
   unknown targets (it'll need a new `match` arm, not a new concept — the four basic targets
   already establish the "target = which players are affected" pattern).
5. **Advanced cards are recommended off by default**: "We recommend not using the advanced
   effects when playing L'Oaf for the first time" (lines 75-76) — directly justifies §0/§6's
   default-off table option, not just an implementation convenience.

## 4. New interactive state: player choice for `discard_choice` and the two swap effects

Three of the six deterministic-timing effects genuinely need a player's own decision, unlike
everything built in Phases 1–3 (basic reputation effects are always fully computed by
`ReviewEffectResolver` with no player input):

- `discard_choice`: which card to discard — pure free choice among their whole hand.
- `swap_discard_lower_by_at_most X` / `swap_discard_higher_by_at_least X`: the player gets
  their played card back, then must discard _some_ eligible card (one that's ≤X lower / ≥X
  higher than the card they just got back) — if there's more than one eligible card, which
  one is also the player's choice. (`discard_recycle_lowest` is the one exception that
  _sounds_ similar but isn't: "the lowest value card" is a deterministic selection, no choice
  involved.)

**Design**: a new `MULTIPLE_ACTIVE_PLAYER` state (tentatively `ResolveAdvancedEffect`),
entered from `ResolveRound.php` only when that round's review effect is one of these three
_and_ its target group is non-empty. `setAllPlayersMultiactive()` on exactly the targeted
player IDs (from `ReviewEffectResolver`'s existing `playersInTarget()`-style logic, extended
to cover `reputation_zero`), each player picks a card via an action (mirroring
`PlayCards::actCommitCard`'s shape), state auto-advances once every targeted player has acted
(same `setPlayerNonMultiactive` auto-transition pattern already confirmed working in
`PlayCards.php`), then continues to whatever `RoundStart`/`EndGame` transition `ResolveRound`
would otherwise have made.

**Apply the Phase 2 activation-timing lesson from day one here**, not after re-discovering it
live: action buttons for choosing a card belong in `onPlayerActivationChange` on the JS side,
never in `onEnteringState` — `docs/bga-studio-reference.md` §5 documents exactly why
(`isCurrentPlayerActive` isn't reliably settled yet inside a `MULTIPLE_ACTIVE_PLAYER` state's
own `onEnteringState` on a live push). This cost real live-debugging time once already; no
reason to pay it twice.

**Answered**: when a player has multiple eligible cards for a swap effect, they pick freely —
confirmed directly (not guessed from the transcribed wording alone). So the interactive state
for both swap effects works exactly like `discard_choice`, just with the choice constrained to
whichever cards fall in the eligible range (at most/at least X from the played card's value)
instead of the whole hand — same UI shape, different eligibility filter. No implicit
tie-breaking rule to build.

## 5. Deferred resolution: end-of-game bonus/malus/doublers

This is the real architectural departure from every prior phase. Every effect built so far
(`RoundResolver`, basic `ReviewEffectResolver`, even §4's new interactive effects) resolves
its full consequence **immediately**, the round it's triggered. End-game effects explicitly
don't: the rulebook's own words are that filing such a card "**add[s] a new scoring rule**,"
evaluated only once, for real, at actual game end — not a value to compute and store the
moment the card is filed.

**Recommended design: don't persist anything new — recompute from `round_card` at `EndGame`
time, same pattern as everything else in this codebase.** Every advanced card exists as
exactly one physical copy, and `round_card.card_location` (`review_happy`/`review_angry`)
already durably records, for the whole game, which side of which card resolved and when
(implicitly, via which pile it's in) — this is the exact same fact `EndConditionChecker` and
`ResolveRound`'s reputation-effect lookup already read from that table every round. `EndGame.php`
can query both piles' card types (a query it already runs, per `docs/loaf-phase3-plan.md` §7),
filter to whichever ones have an `end_game_bonus`/`end_game_malus`/`double_end_game_bonus`/
`double_end_game_malus` effect on the side they were filed under, and evaluate every one of
them together against **final** reputation — because they were never supposed to look at
reputation-at-filing-time in the first place, this recompute-at-the-end approach is _more_
correct than trying to snapshot anything mid-game, not just more consistent with the existing
architecture.

**Consequence for the doublers**: since nothing resolves until `EndGame` anyway, a
`double_end_game_bonus`/`double_end_game_malus` card's position in the round order relative to
the other bonus/malus cards is irrelevant — "all bonus points are doubled at the end of the
game" is naturally just "if this card was filed at all (on this side), double the summed
bonus total when computing `bonusPoints`." No ordering logic needed, which wouldn't be true if
this were designed as a mid-game running total instead.

**Sketch, evaluated once inside `EndGame.php` alongside the ending-boss/reputation/hand
queries it already gathers**:

```php
// For each player: sum end_game_bonus amounts from every filed card whose resolved side
// targets them (evaluated against final $reputations, same targeting logic
// ReviewEffectResolver already has), separately for bonus and malus, then double each sum if
// the corresponding doubler card was also filed on its fail/success side respectively.
```

The exact target-matching code should reuse `ReviewEffectResolver`'s existing
`playersInTarget()` logic rather than duplicating it — worth extracting that into a shared,
public, pure helper if it isn't already reusable across both call sites.

## 6. The `with_advanced_cards` table option

`gameoptions.jsonc` (not `gamepreferences.jsonc` — see §0), one new entry, id in the
100-199 range the scaffold's comment reserves for it:

```jsonc
"100": {
  "name": "Advanced round cards",
  "values": {
    "0": { "name": "Off (basic cards only)" },
    "1": { "name": "On", "description": "Adds the 12 advanced (croissant) round cards with more elaborate effects. The rulebook recommends leaving this off for your first game." }
  },
  "default": 0
}
```

Read in `setupNewGame($players, $options)` (already accepts `$options`, currently unused) to
decide whether `createCards()` also includes the 12 `advanced_*` types alongside the existing
`basic_card_types` filter in `Game.php` (~line 199) — the `array_filter(..., fn($card) =>
!$card['advanced'])` call there becomes conditional on the option instead of an unconditional
exclusion.

## 7. Testing plan (PHPUnit, DB-free)

New/extended test coverage, same discipline as every prior phase:

- **`ReviewEffectResolverTest` extensions**: `discard_recycle_lowest`'s deterministic
  selection (including the "discard pile empty → no-op" case); the deterministic-fallback
  branch of both swap effects ("if they can't, discard the played card instead"); the new
  `reputation_zero` target (including tie behavior — multiple players at exactly 0).
- **A new test file for end-game effect evaluation** (`EndGameEffectResolverTest` or wherever
  §5's logic lands): one bonus-only case, one malus-only case, one card targeting nobody (empty
  no-op, same precedent as every other target-group edge case in this codebase), the doubler
  applying only when its own card was filed, and a combined bonus+malus+doubler scenario feeding
  directly into `ScoringCalculator::score()`'s `bonusPoints` parameter to confirm the full
  pipeline, not just the isolated calculation.
- **Whatever interactive-choice validation logic exists** (§4) — e.g. "is this player's chosen
  card actually eligible for this swap effect" — is the one piece of new logic that's
  pure/testable even though the choice itself comes from a human; test the validation function
  directly, the same way `ReviewEffectResolver` is tested without any BGA framework involved.

## 8. Live verification checklist (Studio)

Same discipline as every prior phase — nothing beyond PHPUnit is exercisable locally, and this
phase adds a genuinely new category of live-only risk (a brand-new interactive state). Check
items off in place as they're confirmed; this section _is_ the phase's live-verification
tracker, not just a plan — `docs/loaf-remarks.md`'s "Phase 4 live verification" entry (last
item below) is what actually closes the phase out once everything above it is checked.

### Before creating a table

- [x] Deploy the merged `main` branch to Studio (SFTP sync — confirm `docs`, `tests`,
      `.claude`, `.github`, `tools`, `*.md` are still excluded per the SFTP ignore list).
- [x] Re-upload `dbmodel.sql` and wipe the database if the schema changed (it didn't this
      phase, but check the Studio log for schema-mismatch errors anyway).
- [x] Check the Studio server log for PHP syntax/fatal errors immediately after upload, before
      creating any table. (See `docs/bga-studio-reference.md` §7 for the full generic pre-test
      checklist this supplements.)

### Baseline regression — option off

- [x] Create a table, confirm the "Advanced round cards" option appears at table creation and
      defaults to **Off**.
- [x] Play a full game with it off. Confirm **no advanced card is ever drawn** — the simplest
      possible regression: Phase 1-3 behavior must be completely unaffected when the option is off.

### Deck composition — option on

- [x] Create a new table with "Advanced round cards" **On**.
- [x] Confirm advanced cards actually get shuffled into the deck (visible once one gets drawn).

### The three interactive effects (the new risk area)

- [x] Play until a `discard_choice` card resolves. Confirm the targeted player(s) get action
      buttons **on the live push**, without needing a page refresh — this is precisely the
      activation-timing bug category that bit Phase 2 before, now in a brand-new state class; treat
      "no buttons until refresh" as a signal history is repeating, not a new mystery to debug from
      scratch.
- [x] Same check for `swap_discard_lower_by_at_most`.
- [x] Same check for `swap_discard_higher_by_at_least`.
- [x] For a multi-target case (e.g. `discard_choice` hitting every `reputation_negative` player
      at once), confirm the state correctly waits for **all** targeted players before advancing,
      not just the first one to act.
- [ ] Try disconnecting/going idle as a targeted player and confirm the zombie fallback
      (`ResolveAdvancedEffect::zombie()`) picks a legal card and doesn't stall the game.
      **Blocked in Express mode**: quitting a player there just stops the game outright instead
      of handing control to the zombie AI, so this can't be tested solo -- needs a real
      multiplayer table (or Studio's dedicated zombie-testing tooling, if any) with an actual
      second connection to disconnect. Left unchecked until that's available.

### Deterministic effects (lower risk, but unverified live)

- [x] Confirm `discard_recycle_lowest` correctly moves the lowest discard-pile card back to
      hand with no player interaction needed.
- [x] Confirm a `counts_as_two` card (advanced_07/advanced_08, already built in Phase 2, now
      reachable for the first time live) genuinely ends the game a round earlier than it otherwise
      would.

### End-game scoring

- [x] Get an `end_game_bonus` card filed and confirm it shows up correctly in the final score.
- [x] Same for `end_game_malus`.
- [x] Same for a doubler (`double_end_game_bonus`/`double_end_game_malus`) — hardest to arrange
      deliberately, may take a couple of games.
- [x] Cross-check each against the `scoreBreakdown` log line's `end-game bonus
${endGameBonus}` field (same "surface hidden state via the log" pattern,
      `docs/bga-studio-reference.md` §6) — the number there should match what you'd hand-calculate
      from which cards resolved.

### The one open API question

- [x] In `ResolveAdvancedEffect::onEnteringState()`, try swapping the current
      `setAllPlayersMultiactive()` + per-player `setPlayerNonMultiactive()` trim for a single
      `$this->game->gamestate->setPlayersMultiactive($activePlayerIds, '', true)` call. **Confirmed
      live: it works** — `setPlayersMultiactive()` is now the real implementation (no longer
      guarded as experimental), and `tests/stubs/BgaFrameworkStubs.php`'s `Gamestate` stub is
      updated to a confirmed citation rather than an "unconfirmed" caveat.

### Close it out

- [x] Update `docs/loaf-remarks.md` with a "Phase 4 live verification" entry recording what
      passed, what needed fixing, and the `setPlayersMultiactive` result — that's what actually
      marks this phase complete in this project's own convention.

## 9. Suggested implementation order

1. Deterministic Core logic first, no BGA dependency: `discard_recycle_lowest`, both swap
   effects' deterministic-fallback branch, `reputation_zero` targeting, and the end-game
   bonus/malus/doubler evaluation logic (§5) — all fully unit-testable before touching any
   state machine code, same "Core classes first" discipline as every prior phase.
2. The `with_advanced_cards` table option (§6) and its `setupNewGame` wiring — small, isolated,
   easy to verify in isolation (deck composition) before the harder interactive-state work.
3. The new interactive state (§4) — the one genuinely new architectural piece this phase adds,
   done last and carefully, reusing `PlayCards`' already-debugged multiactive pattern from the
   start rather than as an afterthought.
4. Wire deferred end-game effects into `EndGame.php` (§5), extending the existing
   `scoreBreakdown` log line to explain the new bonus/malus contribution.
5. Deploy, live-verify per §8, update `docs/loaf-remarks.md`.
