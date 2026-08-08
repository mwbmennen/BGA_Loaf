# L'Oaf — Phase 1 plan: RoundStart → PlayCards → ResolveRound state machine

> Detailed technical plan for the second slice of Phase 1 in
> `docs/loaf-implementation-plan.md` (the pure `RoundResolver`/`ReputationTrack` Core classes
> were the first slice, already built and tested). This doc is the concrete DB/state/client
> design; the top-level phased roadmap stays in `loaf-implementation-plan.md`.

## Context

Phase 0 (all open questions answered, full 24-card data set transcribed into
`docs/loaf-card-data.json`) is done. What's left of Phase 1 is the actual game: DB schema,
setup, and a working simultaneous-play loop on BGA Studio, replacing the scaffold's
turn-based `PlayerTurn`/`NextPlayer` states (which don't fit this game at all — L'Oaf has no
turn order).

Since the top-level plan doc was written, the real card data has become available, so this
phase ports real order totals from `docs/loaf-card-data.json` now rather than using throwaway
flat placeholders (a deliberate deviation from the original phasing, decided
2026-08-07 — see `docs/loaf-remarks.md`). It still defers **review-effect resolution**
(discard effects, reputation-shift effects, end-game bonus/malus) and the **weighted
boss-pile / 5-card end trigger** to the next phase (those need `ReviewEffectResolver` +
`EndConditionChecker`, not yet built). Instead, this phase's natural termination is the
round-card deck itself running out — a real mechanic, not an arbitrary round cap (see
"The flip mechanic" below for the derivation).

**Framework API confidence note**: this repo's local IDE stubs
(`tests/stubs/BgaFrameworkStubs.php`) are hand-maintained and incomplete — they don't cover
`MULTIPLE_ACTIVE_PLAYER`, the Deck component, or `$this->bga->globals`. The signatures below
(`setPlayerNonMultiactive`, `globals->get/set/inc`, `deckFactory->createDeck`) are
cross-checked against BGA's public docs (`en.doc.boardgamearena.com`), but none of this can
be exercised locally — there's no vendored BGA framework, and Core classes are the only thing
PHPUnit can actually run. **The state-machine code can only be verified by deploying to BGA
Studio and playing a real game** (see Verification below). Treat any first-load PHP fatal as
a signal that one of these cross-checked signatures is slightly off from what this repo's
specific framework version expects.

## The flip mechanic (why the end condition is "deck runs out")

Per the rules, every round: draw the deck's top card and flip it (this round's *review*
card, filed under a Boss pile based on *this round's own* success/fail) — then the order
value comes from whatever card is newly exposed on top afterward, still undrawn. Tracing
this through a shuffled sequence `[C1, C2, ..., C12]` shows the offset: `C2`'s order supplies
round 1's target, and `C2` itself is the card drawn/filed in round 2. Generalizing: **round
N draws card `C_N` as review, and reads its target from whatever card is now on top (call it
`C_{N+1}`)**. This only works as long as a second card remains after the draw — i.e. the
deck must have **at least 2 cards** at the start of a round. With 12 basic cards, that caps
play at 11 rounds, matching the top-level plan's aside ("12-card basic deck supports at most
11 rounds"). So: **at `RoundStart`, if the deck has fewer than 2 cards, skip straight to
`EndGame`.** No arbitrary round-count guess needed.

## DB schema (`dbmodel.sql`)

Extend `player` (both default to 0/false so the existing `INSERT` in `setupNewGame` needs no
changes):
```sql
ALTER TABLE `player` ADD `player_reputation` INT NOT NULL DEFAULT 0;
ALTER TABLE `player` ADD `player_fired` BOOL NOT NULL DEFAULT 0;
```

New `work_card` table — a fixed personal 0–11 hand per player, not drawn from a shared pool:
```sql
CREATE TABLE IF NOT EXISTS `work_card` (
  `player_id` INT UNSIGNED NOT NULL,
  `value` TINYINT UNSIGNED NOT NULL,
  `location` ENUM('hand','played','discard') NOT NULL DEFAULT 'hand',
  PRIMARY KEY (`player_id`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
Note: this adds a third `location` value, `'played'`, beyond the top-level plan's original
`hand`/`discard` pair — needed to represent "committed this round, hidden until reveal"
without a separate table. Recorded as a refinement in `docs/loaf-remarks.md`.

New `round_card` table, shaped to match BGA's Deck component convention (mirrors the
commented-out `card` table example already sitting in this file):
```sql
CREATE TABLE IF NOT EXISTS `round_card` (
  `card_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_type` VARCHAR(16) NOT NULL,
  `card_type_arg` INT NOT NULL DEFAULT 0,
  `card_location` VARCHAR(16) NOT NULL,
  `card_location_arg` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;
```
`card_type` holds the string id from `docs/loaf-card-data.json` (e.g. `"basic_01"`).
Locations used this phase: `deck`, `revealed_review`, `review_happy`, `review_angry`.

## Static content: port `docs/loaf-card-data.json` → `Game::$ROUND_CARD_TYPES`

Replace the scaffold's dummy `$CARD_TYPES`/`Troll`/`Goblin` array in `Game.php` with a real
`Game::$ROUND_CARD_TYPES` array keyed by the JSON's `id` strings, carrying both `order`
(`per_player_average`) and `review` (`success`/`fail` effect definitions) for **all 24
cards** — porting the full data now avoids a second transcription pass later, even though
this phase only *reads* `order.per_player_average` and only *shuffles the 12 basic cards*
into the deck at setup (advanced-card opt-in is Phase 4; `gameoptions.jsonc` stays empty for
now). Card names/labels wrapped in `clienttranslate(...)` per the localization standing rule.

## `constants.inc.php`

Already has a placeholder comment anticipating this exact list. Define:
```php
const ST_ROUND_START = <id>;
const ST_PLAY_CARDS = <id>;
const ST_RESOLVE_ROUND = <id>;
const ST_END_GAME = 98; // keep existing EndScore.php's id; 99 stays the framework's reserved end-game transition
const GLOBAL_CURRENT_ROUND = 'current_round';
const GLOBAL_CURRENT_REVIEW_CARD_ID = 'current_review_card_id';
const GLOBAL_CURRENT_ORDER_AVERAGE = 'current_order_average';
```
(Exact numeric ids picked to not collide with the two ids already in use — `98`/`99` in
`EndScore.php`; new states get fresh low numbers like the scaffold's `10`/`90` pattern.)

## States (`modules/php/States/`)

Delete `PlayerTurn.php` and `NextPlayer.php` (turn-based, doesn't apply). Rename
`EndScore.php` → `EndGame.php` (top-level plan's name for this state), keep its `id: 98`/
`ST_END_GAME` pattern, but note its scoring is a **stub this phase** — real
`ScoringCalculator`/fired-player logic is Phase 3, so it should just let BGA's default
ranking apply (all `player_score` stay at 0, effectively a placeholder tie) with a `// TODO
Phase 3` marker, not fake logic.

**`RoundStart`** (`StateType::GAME`, automatic):
- If `round_card` deck has < 2 cards in `location = 'deck'` → `return EndGame::class`.
- Draw top card → move to `revealed_review`, store its id in
  `GLOBAL_CURRENT_REVIEW_CARD_ID` via `$this->bga->globals->set(...)`.
- Peek (don't draw) new top card → look up
  `Game::$ROUND_CARD_TYPES[$cardType]['order']['per_player_average']` → store in
  `GLOBAL_CURRENT_ORDER_AVERAGE`.
- `$this->bga->globals->inc(GLOBAL_CURRENT_ROUND, 1)`.
- Notify all players of the new round/target (translated message).
- `return PlayCards::class`.

**`PlayCards`** (`StateType::MULTIPLE_ACTIVE_PLAYER`):
- `getArgs()` returns the acting player's own hand values (`work_card` rows where
  `location = 'hand'` for the current player only).
- `#[PossibleAction] actCommitCard(int $value, int $activePlayerId)`: validate the value is
  in that player's hand, `UPDATE work_card SET location='played' WHERE player_id=... AND
  value=...`, notify (privately, or just an anonymous "player X has committed" ping — no
  value leak), then `$this->gamestate->setPlayerNonMultiactive($activePlayerId,
  ResolveRound::class)`. Per BGA docs, when the last active player goes non-active this
  auto-transitions to `ResolveRound::class`.
- `zombie(int $playerId)`: reuse `getRandomZombieChoice` over that player's hand values, call
  `actCommitCard`.

**`ResolveRound`** (`StateType::GAME`, automatic):
- Read all `work_card` rows with `location = 'played'` → `[player_id => value]` map.
- `RoundResolver::resolve($currentOrderAverage, $playedCards)` → `RoundResult`.
- For each id in `$result->affectedPlayerIds`: read current `player_reputation`, apply
  `ReputationTrack::adjust($current, $result->reputationDelta)`, write back, notify.
- Move all `played` work cards to `discard`.
- File the round's revealed review card (`GLOBAL_CURRENT_REVIEW_CARD_ID`) into
  `review_happy` or `review_angry` per `$result->success` (no effect resolution yet — that's
  next phase; just the filing).
- Notify all players of the round outcome (total/target/success, who was affected, delta).
- `return RoundStart::class` unconditionally — `RoundStart` alone decides end-of-deck.

## `Game.php`

- Remove the dummy `playerEnergy`/`$CARD_TYPES` scaffold leftovers entirely (unrelated
  placeholder content).
- Constructor: instantiate the round-card deck component
  (`$this->deckFactory->createDeck('round_card')`, matching the existing
  `$this->bga->counterFactory->createPlayerCounter(...)` pattern used for the scaffold's
  energy counter) and set `self::$ROUND_CARD_TYPES`.
- `setupNewGame()`: insert players (unchanged — new columns default via schema), then for
  each player insert `work_card` rows for values 0–11 at `location='hand'`, create the 12
  basic `round_card` rows from `$ROUND_CARD_TYPES` via the deck component's card-creation
  method and shuffle `location='deck'`, then `return RoundStart::class` instead of
  `PlayerTurn::class`.
- `getAllDatas()`: return each player's `player_reputation`/`player_fired`; the current
  player's own hand values in full; other players' hand/played *counts* only (never values);
  the current order average/round number from globals; boss-pile contents (`review_happy`/
  `review_angry` card ids — public per the rules, "slide under so effect part visible").

## Client (`modules/js/Game.js`)

Minimal, functional-not-pretty this phase (visual polish is Phase 5, real art already
available per Q9 but not this phase's job):
- Remove `PlayerTurn`/`NextPlayer` registrations, register `RoundStart`/`PlayCards`/
  `ResolveRound`/`EndGame` (registration name must match the PHP class name exactly, per the
  scaffold's existing convention — see `docs/bga-studio-reference.md`).
- `PlayCards`'s JS state: render own hand values as buttons, `performAction('actCommitCard',
  {value})` on click; show a "waiting on N players" indicator using
  `onPlayerActivationChange` (already stubbed in the scaffold's per-state class shape).
- Wire `notif_*` handlers matching the notification types added above
  (`setupPromiseNotifications` auto-wires by name — mismatches fail silently per the
  reference doc).

## Verification

Nothing here is exercisable locally beyond what's already covered:
1. `vendor/bin/phpunit` and `vendor/bin/phpstan analyse` must stay clean (Core classes
   unaffected, but new PHP must typecheck).
2. Push via the project's SFTP deploy workflow (`docs/bga-studio-reference.md`) to BGA
   Studio, start a sandbox table at each end of the player range (2 and 6, per the top-level
   plan's testing-plan notes), and play through multiple rounds using Studio's debug tools:
   - Confirm `PlayCards` correctly waits for all active players and auto-advances on the
     last commit (validates `setPlayerNonMultiactive`'s exact behavior, which is unverified
     locally).
   - Confirm deck exhaustion after 11 rounds (2–6 players, basic deck only) correctly routes
     to `EndGame` rather than erroring.
   - Watch the Studio error log on first load specifically for fatals from
     `deckFactory->createDeck`, `globals->get/set/inc`, or `setPlayerNonMultiactive` — these
     are the three cross-checked-but-unverified API surfaces called out above.
   - Confirm reputation never leaves [-10, +10] and matches `RoundResolver`'s already-tested
     math against a hand-computed example.
3. Update `docs/loaf-remarks.md` with anything Studio reveals that contradicts the
   docs-sourced signatures above (this is exactly the kind of project-specific judgment call
   that file is for).
