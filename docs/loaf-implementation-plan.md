# L'Oaf — BGA Studio Implementation Plan

> Based on `docs/Loaf-English-rules.md`. See `docs/loaf-open-questions.md` for background —
> all 11 open questions are now answered, including the full 24-card data set, which is
> transcribed in `docs/loaf-card-data.json` and ready to port into `Game::$ROUND_CARD_TYPES`
> (§3) for Phase 2.

## 1. What the rules make clear

- 2–6 players, each with a private hand of 12 numbered work cards (0–11, one of each) in
  their colour, and a baker figure on a shared reputation track (-10 to +10, starts at 0).
- The game is **fully simultaneous** — no turn order. Every round, every player commits one
  work card face-down, then all reveal together.
- A single shared **round-card deck** drives both the round's target ("order") and its
  scoring-modifier ("review") via an offset-by-one mechanic: each round, the current top
  card is flipped to reveal its *review* side (this round's effect), and the new top card's
  *order* side (still face-up from setup) supplies this round's target total. So card *N*'s
  order side is used in round *N*, and that same card's review side is used in round *N+1*.
  This means a 12-card basic deck supports at most 11 rounds, comfortably covering the
  stated 5–9 round game length; a 24-card combined basic+advanced deck gives even more
  headroom.
- Success/fail is determined by comparing the sum of all played cards to the order's total
  for the current player count. On success, the player(s) who played the **highest** card
  gain reputation equal to (their card value − the order's per-player average). On failure,
  the player(s) who played the **lowest** card lose reputation by the same kind of delta.
  Reputation is clamped to [-10, +10].
- The just-revealed review card is filed under whichever Boss (Happy/Angry) matches the
  round's result, and its effect (if any) resolves immediately. Most basic effects target a
  reputation-defined group (highest, lowest, all-positive, all-negative) and shift their
  reputation by a fixed amount.
- The game ends the instant one Boss pile reaches 5 cards. On an Angry-Boss ending, every
  player with negative reputation is fired and excluded from scoring/winning. On a
  Happy-Boss ending, nobody is fired.
- Scoring (non-fired players only): sum of remaining hand card values + reputation bonus
  (positive only) + any active advanced-card bonus/malus. Highest score wins; ties broken
  by lowest reputation, then shared victory.
- Advanced round cards (marked with a croissant icon) add: discard-forced/discard-recycle
  effects, "swap played card for one at least/at most X different" effects, end-of-game
  bonus/malus points, bonus/malus doublers, and cards that count as *2* successes/fails
  toward the 5-card end trigger. These are explicitly a variant, off by default per the
  physical rules ("we recommend not using the advanced effects when playing for the first
  time").

## 2. Architecture

Following this repo's standing rule (`CLAUDE.md`): placement/scoring logic lives in pure,
DB-free PHP classes under `modules/php/Core/` (or similar), unit-testable via PHPUnit with
no BGA framework dependency. BGA `States/*` classes are thin adapters: they pull data out of
the DB, hand it to a Core class, apply the returned result back to the DB/notifications, and
compute the next state.

Proposed Core classes:

- **`RoundResolver`** — given the order total (for the current player count) and a map of
  `player_id => card_value`, returns: total, success/fail, the reputation delta per
  affected player (handles ties for highest/lowest correctly), and which players are
  affected.
- **`ReputationTrack`** — pure clamp/adjust helper (-10..+10), used by both `RoundResolver`
  output application and review-effect resolution.
- **`ReviewEffectResolver`** — given a review card's effect definition + current
  reputation/hand/discard state for all players, returns the set of mutations to apply
  (reputation changes, forced discards, card recovery, end-game bonus/malus flags, boss-pile
  weight of 1 or 2).
- **`EndConditionChecker`** — tracks weighted Happy/Angry pile counts (accounting for
  "counts as 2" cards) and reports whether/how the game ends.
- **`ScoringCalculator`** — hand value + reputation bonus + advanced bonuses/mali, fired-player
  exclusion, tie-break by lowest reputation, shared-victory grouping.

State machine (replacing the scaffold's turn-based `PlayerTurn`/`NextPlayer` skeleton, which
doesn't fit a simultaneous game):

1. **`RoundStart`** (`StateType::GAME`, automatic) — advance the shared round-card window
   (reveal this round's review card, expose next order card), resolve the review card's
   *direct* effect if it's not tied to this round's outcome (n/a for basic cards — all
   basic/advanced effects appear to resolve at reveal time, not draw time, so this state may
   just set up round state and move on — confirm against Q1/Q2 in the open-questions doc
   once real card data is available).
2. **`PlayCards`** (`StateType::MULTIPLE_ACTIVE_PLAYER`) — every player privately commits one
   card from hand; auto-advance once all have submitted (or on zombie/timeout, per Q10).
3. **`ResolveRound`** (`StateType::GAME`, automatic) — reveal all played cards, run
   `RoundResolver`, apply reputation deltas, run `ReviewEffectResolver` for the review card
   just filed, move played cards to each player's discard pile, update the weighted boss-pile
   counters, check `EndConditionChecker`.
4. Loop to `RoundStart`, or transition to **`EndGame`** (`StateType::GAME`) which runs
   `ScoringCalculator`, applies `player_score`/`player_score_aux`, and marks fired players
   appropriately for BGA's ranking display (see Q5/Q6).

## 3. Data model

Extend `player` table:
- `player_reputation` INT NOT NULL DEFAULT 0
- `player_fired` BOOL NOT NULL DEFAULT 0

Work cards — a player's hand is a fixed personal set (not drawn from a shared pool), so a
lightweight table beats the generic Deck component:
- `work_card` (`player_id`, `value` TINYINT 0–11, `location` ENUM('hand','discard'),
  PRIMARY KEY (`player_id`, `value`))

Round cards — genuinely drawn/shuffled from a shared pool, a good fit for BGA's Deck tool:
- `round_card` (`card_id`, `card_type` — references a static content table, `location` ENUM
  ('deck','pending_order','review_happy','review_angry'), `location_arg` for pile ordering)
- Static content (`Game::$ROUND_CARD_TYPES` in PHP, mirroring the `$CARD_TYPES` pattern
  already in the scaffold): per card, order totals for each of 2–6 players, and review
  effect definitions for both the success side and the fail side (target selector, effect
  type, magnitude, boss-pile weight). **Source data is transcribed in
  `docs/loaf-card-data.json`** — port it into this PHP array in Phase 2.

Global variables:
- current round number
- game mode (basic / basic+advanced) — set at setup from a table option
- `boss_happy_weight`, `boss_angry_weight` (weighted counts, since some advanced cards count
  as 2)
- ids of the currently-revealed order card and review card

## 4. Client-side plan

- **Reputation board**: horizontal track -10..+10 with each player's baker token; render via
  CSS position based on `player_reputation`.
- **Boss piles**: two stacks (Happy/Angry) showing filed review cards face-up (effect side
  visible) with a fraction-to-5 indicator.
- **Hand**: own cards shown with values; opponents' hands shown as card-backs with a count
  only.
- **Commit/reveal**: player selects a card, confirms (hidden from others — standard BGA
  private-state pattern), "waiting on N players" indicator, then a reveal animation flips
  everyone's card simultaneously and shows the running total vs. the order target.
- **Discard piles**: visibility depends on Q3 — plan for "visible to owner only" as the
  default assumption, with a config point to widen to public if the rules say otherwise.
- **Effect log**: a readable notification per resolved review effect (who/what/why), since
  the physical game's icon-based effects need a clear textual translation digitally.

## 5. Game options

- `basic_only` / `with_advanced_cards` toggle (default: basic-only, matching the physical
  rules' recommendation for first plays) — controls whether the 12 advanced cards are
  shuffled into the deck at setup.
- Player count 2–6 (already set in `gameinfos.jsonc`).

## 6. Testing plan (PHPUnit, DB-free)

- `RoundResolverTest` — success/fail boundary (total == target), tie-for-highest and
  tie-for-lowest (multiple players share the delta), delta-of-zero (played card equals the
  average exactly), every player count 2–6.
- `ReputationTrackTest` — clamping at both ends, adjustment crossing the boundary.
- `EndConditionCheckerTest` — reaching 5 on either pile, weighted (+2) cards tipping the
  count early, simultaneous-reach edge case if it can occur.
- `ScoringCalculatorTest` — hand-value summation, positive-only reputation bonus, tie-break
  by lowest reputation, shared victory, fired-player exclusion, all-players-fired case.
- `ReviewEffectResolverTest` — one test per effect type once the full effect catalogue is
  confirmed (Q1).

## 7. Phased milestones

- **Phase 0 (blocking) — done.** All of `docs/loaf-open-questions.md` is resolved, including
  the full round card data set (`docs/loaf-card-data.json`).
- **Phase 1** — DB schema, setup (deal hands, shuffle deck, zero reputation), and the
  `RoundStart → PlayCards → ResolveRound` loop working end-to-end with *placeholder* order
  totals/effects (e.g. all cards using a flat target), so the simultaneous-play mechanic and
  state machine can be validated on Studio before real content exists.
- **Phase 2** — swap in real basic-card data (once available), implement the basic
  `ReviewEffectResolver` effect types (target-group ± reputation), weighted boss-pile
  counting, and the end-of-game trigger.
- **Phase 3** — `ScoringCalculator`, fired-player handling, end-game UI/standings.
- **Phase 4** — advanced round cards: discard-forced/recycle effects, played-card swap
  effects, end-of-game bonus/malus (+ doublers), double-counting cards; `with_advanced_cards`
  table option.
- **Phase 5** — polish: reveal/board animations, sound (if any). Real art assets are already
  available (Q9) — integrate them directly rather than building placeholder art first. Every
  user-facing string must already be wrapped in BGA's translation helpers from Phase 1
  onward (Q8), not deferred to this phase.
- **Phase 6** — Studio playtesting, zombie-mode behaviour for the simultaneous state,
  edge-case QA (2-player minimum, all-fired ending, etc.).
