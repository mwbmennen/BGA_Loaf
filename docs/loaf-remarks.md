# L'Oaf — Implementation Remarks

> L'Oaf-specific judgment calls and known gaps. Generic BGA Studio/framework lessons go in
> `docs/bga-studio-reference.md` instead.

## Card data capture: transcribe to JSON first, port to PHP later

`docs/loaf-card-data.json` is the transcription target for the 24 physical round cards
(Q1 in `docs/loaf-open-questions.md`), not `Game::$ROUND_CARD_TYPES` directly. Reasoning:
transcription from photos is error-prone and happens over multiple sessions, so it's worth
having a diffable, schema-validated intermediate that can be sanity-checked (e.g. a script
asserting every `order.totals` value divides evenly by its player count, confirmed per Q2)
before it's hand-ported into the PHP static content array the plan calls for.

**Status (2026-08-07): all 24 cards fully transcribed**, via card scans in
`docs/card-scans/` (48 images, `{basic|advanced}_{01-12}_{order|review}.png`) and the user
reading off values/effects directly rather than me parsing the images — an earlier attempt
to read target/effect icons straight off the scans produced a wrong inference (see the
"target isn't implied by effect type" entry below) and was abandoned in favor of asking.
Every `order.totals` value divides evenly by its player count (validated with a script) and
matches `per_player_average * player_count`.

### Effect vocabulary used in the JSON

- `target`: `highest_reputation` | `lowest_reputation` | `reputation_positive` (good, 0+) |
  `reputation_negative` (bad, <0) | `reputation_zero` (advanced-only, exactly 0) | `null`
  (advanced all-players effects — doublers, counts-as-two — have no target)
- `effect`: `reputation` (basic-style +X/-X) | `discard_choice` | `discard_recycle_lowest` |
  `swap_discard_lower_by_at_most` | `swap_discard_higher_by_at_least` | `end_game_bonus` |
  `end_game_malus` | `double_end_game_bonus` | `double_end_game_malus` | `none` (paired with
  `counts_as_two: true` on the "empty effect" advanced cards, per the rulebook)
- `counts_as_two`: independent boolean per side — an "empty" advanced card side has
  `effect: "none"` and `counts_as_two: true` on the side that counts double; its other side
  is typically `effect: "none"`, `counts_as_two: false` (a true no-op), not the reverse.

### End-of-game bonus/malus effects: deferred registration, not immediate application

Per the rules ("End of game effects... add a new scoring rule. The bonus or minus points are
awarded to players who meet the requirement **at the end of the game**"), `end_game_bonus` /
`end_game_malus` don't apply the moment their round resolves like a plain `reputation`
effect does — resolving that round instead registers a standing scoring rule (target,
amount, sign) that `ScoringCalculator` evaluates once, at game end, against each player's
**final** reputation. A player can drift in and out of the target group all game; only where
they land at the final whistle counts. Multiple triggered rules stack (list, not a single
slot) and fired players are excluded from all of them, same as their hand value/reputation
bonus.

**Doublers can't stack**: `advanced_11` is the only card in the deck with
`double_end_game_bonus`/`double_end_game_malus` (one per side), and a physical review card
only ever files under one Boss pile per game (success XOR fail, never both) — so at most one
doubler can ever fire in a single game. `ScoringCalculator` only needs a boolean
(`bonus_doubled`/`malus_doubled`), not a stacking multiplier.

### Target isn't implied by effect type — always ask explicitly

Early in transcription I assumed advanced effects 2-9 (discard/swap/end-game bonus-malus)
always pair with the `reputation_zero` target, since that's the *only new* target icon the
advanced rules section introduces. That assumption was wrong (`advanced_01` actually targets
`reputation_negative`, confirmed by the user after I got it wrong) — the advanced rules page
just documents *that the icon exists*, not that every advanced effect exclusively uses it.
**Any effect (basic or advanced) can pair with any of the 5 targets, or none.** Always ask
target and effect as two independent questions per side; never infer one from the other.

## Answered open questions (2026-08-07)

All 11 questions in `docs/loaf-open-questions.md` are now answered except Q1:

- **Q2** (order average-per-player always a whole number): yes, guaranteed by the card
  design — no rounding rule needed in `RoundResolver`.
- **Q3** (discard pile visibility): visible only to its owner — confirms the plan's default
  assumption in `docs/loaf-implementation-plan.md` §4.
- **Q4** (negotiation/table talk): BGA's normal table chat during `PlayCards` is sufficient;
  no dedicated discussion/timer phase.
- **Q5** (fired-player display): all fired players shown tied at the bottom, not ordered
  among themselves by reputation.
- **Q6** (all-players-fired edge case): shared last place for everyone, no winner —
  consistent with Q5.
- **Q7** (advanced-mode default): opt-in table/lobby option, default off — confirms the
  plan's §5 assumption as written, no change needed there.
- **Q8** (localization scope): keep every language open via BGA's translation platform —
  every user-facing string must be wrapped in `clienttranslate(...)`/`self::_(...)`/the JS
  equivalent from day one, not deferred to a later i18n pass. Generic-enough that the
  underlying rule also went into `docs/bga-studio-reference.md`.
- **Q9** (art assets): real digital assets already exist — no placeholder-art phase; plan
  §Phase 5 updated accordingly.
- **Q10** (player-count recommendations): 2–6 uniformly fine, leave
  `suggest_player_number`/`not_recommend_player_number` unset.
- **Q11** (turn timer): use BGA's standard fast/medium/slow defaults for `PlayCards`, no
  custom timer.

Remaining blocker: **Q1**, the full 24-card data set, still needs transcription into
`docs/loaf-card-data.json` before Phase 2+ of the implementation plan can start.
