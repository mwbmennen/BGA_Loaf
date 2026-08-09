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

## `RoundResolver` / `ReputationTrack` (Core layer) — judgment calls (2026-08-07)

`modules/php/Core/RoundResolver.php` and `ReputationTrack.php` are the first Core classes
built per the plan's §2 architecture. Two decisions here weren't spelled out by the rulebook
and are worth flagging in case a rules FAQ or playtesting says otherwise:

- **Tied extreme card: each tied player gets the full delta, not a split.** The rulebook's
  only worked example (3 players, single highest card) has no tie, so there's no printed
  ruling to follow. `RoundResolver` currently gives *every* player who shares the
  highest-on-success (or lowest-on-failure) value the full `(card_value − per_player_average)`
  delta independently, rather than dividing one delta among them. This matches how similar
  reputation-track games usually resolve simultaneous ties, but it's an assumption, not a
  transcribed rule — confirm against the physical rules' tie-handling section (if any) or the
  publisher FAQ before shipping.
- **Success and failure deltas are unified into one signed value.** Rather than
  `RoundResult` exposing separate "gain" and "loss" magnitudes that callers branch on,
  `reputationDelta` is always `extreme_card − per_player_average` — positive on success
  (added), negative on failure (also just added). Callers do a single
  `ReputationTrack::adjust($current, $result->reputationDelta)` with no success/fail branch.
  This is an implementation simplification, not a rules judgment call, but it constrains how
  `ReviewEffectResolver` and any future round-resolution UI should consume `RoundResult`.

**Proven invariant, not a judgment call, but non-obvious enough to record**: a failing
round's `reputationDelta` can never be exactly zero. If the lowest played card equalled the
per-player average, every card would have to be ≥ average, forcing the total ≥ target — which
contradicts failure by definition. So a zero-delta round is only possible on success (when
every player happens to play exactly the average). `RoundResolverTest` encodes this as
`testDeltaIsAlwaysStrictlyNegativeOnFailure`; don't add a "zero delta on failure" test case
back in, it describes an unreachable game state.

## Phase 1 state machine: judgment calls (2026-08-07)

Built `RoundStart`/`PlayCards`/`ResolveRound`/`EndGame` (see `docs/loaf-phase1-plan.md` for
the full design). None of this is exercisable locally — no vendored BGA framework, so
everything below is either sourced from BGA's public docs or a best-effort guess, and can
only really be confirmed by deploying to Studio and playing a game. Recording the specific
calls made so a Studio failure can be traced back to the right assumption fast:

- **`work_card.location` gained a third value, `'played'`**, beyond the original plan's
  `hand`/`discard` pair (`docs/loaf-implementation-plan.md` §3). Needed to represent "committed
  this round, hidden from other players until reveal" without a separate table — a card in
  `hand` is visible only to its owner, `played` is *also* visible only to its owner (until
  `ResolveRound` moves it to `discard`), and both states share the same visibility rule, so a
  third enum value was simpler than a second table with duplicated `player_id`/`value` columns.
- **Deck row ordering is not trusted** — `RoundStart` fetches all cards in `location = 'deck'`
  and sorts by `location_arg` ascending itself (lowest = next to draw) rather than assuming
  `getCardsInLocation`'s row order reflects deck order. This is the standard Deck component
  convention, but since the exact ordering behavior isn't confirmed locally, sorting explicitly
  in PHP means correctness doesn't depend on an unverified default.
- **End condition is deck exhaustion, not a round-count guess.** Traced the actual flip
  mechanic (draw a review card, read the target from whatever's newly exposed on top) through
  a concrete example to show it needs ≥2 cards in the deck to start a round — see
  `docs/loaf-phase1-plan.md`'s "flip mechanic" section for the full derivation. This was a
  late change from the top-level plan doc's original suggestion of throwaway placeholder
  totals; since the real card data was already available, it seemed wasteful to build and then
  discard flat placeholder content, so Phase 1 ported the real `order.per_player_average` data
  now and let the *real* deck-exhaustion mechanic drive the loop's end, rather than picking an
  arbitrary round cap. Decided with the user before implementation (see the plan doc's Context
  section).
- **Three framework APIs used here have zero local verification history** (unlike, say,
  `playerScoreAux`, which this codebase already fought through to a confirmed signature — see
  the stub file's comments): `$this->bga->globals->get/set/inc`,
  `$this->gamestate->setPlayerNonMultiactive($playerId, NextState::class)`, and
  `$this->deckFactory->createDeck(...)` plus the `Deck` component's method shapes
  (`createCards`/`moveCard`/`getCardsInLocation`/`countCardsInLocation`). All three are
  cross-checked against BGA's public docs and stubbed accordingly in
  `tests/stubs/BgaFrameworkStubs.php` with comments flagging them as unverified — treat any
  first-Studio-load fatal as a signal one of these is slightly off, the same way
  `playerScoreAux`'s two earlier wrong guesses were caught.
- **`EndGame`'s scoring is a deliberate stub**, not a placeholder bug: `player_score` stays at
  its schema default (0 for everyone) this phase. Real `ScoringCalculator` (hand value +
  reputation bonus + fired-player exclusion + tie-break) is Phase 3 — this phase only needed to
  prove the round loop starts, plays, resolves, and terminates correctly.

## Phase 1 live verification (2026-08-08)

**Status: core loop confirmed working end-to-end on Studio, at both ends of the player
range.** A full 2-player game was played to completion — deck exhaustion correctly routed to
`EndGame`, both players ended negative reputation, game ended in a tie, zero PHP errors from
`RoundStart` or `ResolveRound` at any point across multiple rounds. A 6-player game was also
played through successfully (using Studio's `dev0`–`dev9` test accounts, switching between
seats via the player panel's red-arrow-opens-a-new-tab mechanism —
`en.doc.boardgamearena.com/Testing_by_developer` — one tab per seat, no repeated login
needed). A hand-computed reputation-math cross-check against a real round's actual numbers
(target, committed cards, resulting delta) also matched `RoundResolver`'s output. This closes
out every item in `loaf-phase1-plan.md`'s Verification checklist: full loop ✓, auto-advance
on last commit ✓, deck exhaustion → `EndGame` ✓, both ends of the 2–6 player range ✓,
reputation math cross-checked against a real game ✓. **Phase 1 is complete.**

**Revisiting "three framework APIs used here have zero local verification history" above**:
`$this->deckFactory->createDeck(...)`/the `Deck` component's method shapes, and
`$this->bga->globals->get/set/inc`, turned out fine as originally stubbed. The real surprises
were all concentrated in `PlayCards` (`RoundStart`/`ResolveRound`/`EndGame`'s own logic had
no live surprises at all, despite equally little prior verification) — four separate bugs,
each masking the next until fixed: `StateType::MULTIPLE_ACTIVE_PLAYER` needing an explicit
`setAllPlayersMultiactive()` call; `getArgs()` having no per-player request context at all
(not even outside setup), requiring BGA's `_private`/`_merge_private` mechanism instead of
`getCurrentPlayerId()`; that private data not carrying through to an action handler's
injected `$args`; and `$activePlayerId` silently resolving to a nonexistent player on
`MULTIPLE_ACTIVE_PLAYER` states, needing `$currentPlayerId` instead. None of this was
specific to L'Oaf's rules — full generic write-ups are in `docs/bga-studio-reference.md` §5
and `docs/bga-template-upstream-notes.md`, per this file's own stated scope. Worth noting
here only because it means **`setPlayerNonMultiactive` itself** — the one piece of this that
*was* originally flagged as unverified — turned out to work exactly as documented; the actual
gotchas were all in code adjacent to it, not in it.

**Boss-pile counts aren't visible client-side, and that's expected, not a bug.**
`loaf-phase1-plan.md`'s Client section scopes Phase 1's JS as "minimal,
functional-not-pretty" — hand buttons, a waiting indicator, notification wiring. The Happy/
Angry boss-pile display with its fraction-to-5 indicator is explicit Phase 5 polish work
(`loaf-implementation-plan.md` §4), not something that regressed. Right now the only
externally-visible signal that the deck ran out is the game actually ending.

## Phase 2: review card effect had no visible representation (2026-08-09)

While starting live verification of `ReviewEffectResolver`'s wiring into `ResolveRound`
(§3.1/§7 of `loaf-phase2-plan.md`), hit the same "no UI yet" gap as the Phase 1 boss-pile
note above, but this one actually blocked testing rather than just being a known gap: with
no card art (Phase 5) and no notification on reveal, a tester has no way to know what a
review card's effect *should* do before it resolves, so a correct and a silently-wrong
resolution look identical on screen. Fixed by adding a `reviewCardRevealed` notification in
`RoundStart.php` (`describeReviewTarget`/`describeReviewAmount`) that spells out both sides'
target and reputation delta in plain text in the game log — e.g. "on success, the
lowest-reputation player(s) +2 reputation; on fail, ... −2 reputation" — reusing
`Game::$ROUND_CARD_TYPES` rather than adding new data. Deliberately log-text-only, not a
board widget: it's a testing aid, not the real Phase 5 UI, and gets superseded (probably
deleted) once card art lands. General pattern (not L'Oaf-specific) written up in
`docs/bga-studio-reference.md`'s new "Surfacing hidden server-side state before the UI
exists" section and flagged in `docs/bga-template-upstream-notes.md`.

Also note `describeReviewTarget`/`describeReviewAmount` cover every `target` value from the
effect schema (including `reputation_zero`/`all`, which are advanced-only and can't appear
yet since only `basic_*` cards are shuffled into the deck in Phase 2) and fall back cleanly
for non-`reputation` effect types. Not scope creep for its own sake — it's cheap coverage
that avoids a blank/confusing log line if this code is still in place when Phase 4's
advanced-card opt-in starts drawing those cards, rather than a hypothetical future case with
no current trigger.

## Phase 2 live verification (2026-08-09)

**Status: confirmed working on Studio.** Same session as the review-card-visibility fix
above. The activation-timing fix from `c71967f` held across a live push (action buttons
appeared for every active player on the following `PlayCards` state, no refresh needed).
`ReviewEffectResolver`'s wiring moved the reputation track for the correct player(s) by the
correct amount on a resolved review card. `EndConditionChecker`'s weighted boss-pile count
correctly triggered `EndGame` once the threshold was reached. This closes out live
verification for `loaf-phase2-plan.md`'s wiring into `ResolveRound`; no regressions found
in the Phase 1 loop.

The `reviewCardRevealed` log line (deployed and tested separately, same day) also confirmed
correct: on a card whose success side targets `lowest_reputation`, the log text matched and
the lowest-reputation player was the one who actually moved. Closes out the
review-card-visibility gap noted above — the fix works as intended, not just theoretically.

**§10 target-type coverage, update (2026-08-09, later same day):** `highest_reputation`,
`reputation_positive`, and `reputation_negative` review effects all confirmed live as
correct too, alongside the `lowest_reputation` case above — all four basic target types from
`loaf-phase2-plan.md` §3.3 now exercised on a real table, not just in
`ReviewEffectResolverTest`.

A tie case (multiple players sharing the extreme reputation) also confirmed live: the
effect applied to every tied player, not just one, matching `RoundResolver`'s existing
tie-handling precedent.

The end-game trigger also confirmed to fire well before round 9-11, matching §5's pigeonhole
proof — the boss-pile threshold is genuinely the game's primary end condition in practice,
not deck-exhaustion.

The empty-target-group no-op (e.g. `reputation_negative` firing when nobody's below 0) also
confirmed live: no reputation change, no notification, no error — behaves as a silent no-op
exactly as `ReviewEffectResolverTest` already covered in isolation.

**§10's live-verification checklist is now fully closed out.** Every item — all four basic
target types, ties, the empty-group no-op, the boss-triggered end firing well ahead of
deck-exhaustion, and the `reviewCardRevealed` visibility fix — has been confirmed on a real
Studio table, not just in PHPUnit. **Phase 2 is complete.**

## Phase 3: reputation bonus is a stepped table, not the reputation value itself (2026-08-09)

While implementing `ScoringCalculator`, the initial code (and `loaf-phase3-plan.md`'s own §3
rules-quote) assumed the rulebook's "your bonus points if you have a positive reputation
value" meant the bonus equals the reputation number directly (`max(0, reputation)`) — a
reasonable-looking reading, since the transcribed rules text (`docs/Loaf-English-rules.md`)
genuinely has no numbers for this, only the qualitative note about not losing points for
negative reputation.

**That reading was wrong.** The physical reputation board has its own printed bonus track,
separate from the -10..+10 reputation spaces themselves: 1-3 → +2, 4-6 → +3, 7-9 → +4, 10 →
+5, 0 or lower → +0. Caught by the user from a photo of the actual board component, not
inferable from the PDF/text transcription at all — this is exactly the kind of gap the
project's card-scan-driven data capture (`RoundCardData`, `docs/loaf-card-data.json`) was
built to avoid for the round cards, but the reputation board itself was never photographed or
transcribed the same way. Worth flagging as a gap-class, not just a one-off fix: **any
physical component with its own printed values (boards, tokens, insert artwork) needs the
same "transcribe from a real photo" discipline the round cards already got, not just the
narrative rules text** — logged in `docs/bga-template-upstream-notes.md` as a generic lesson.

Fixed in `ScoringCalculator::reputationBonus()` (a small `match` on reputation ranges),
`ScoringCalculatorTest::testReputationBonusTiers()` (one assertion per tier boundary),
`docs/loaf-phase3-plan.md` (§3, §4, §6, §9, §10 all referenced the wrong formula), and
`docs/Loaf-English-rules.md` (added the table under Scoring, sourced from the board photo
since it was never in the original transcription). `EndGame.php`'s wiring needed no change —
it just passes reputations through to `ScoringCalculator`, which is exactly why keeping this
logic in one pure Core class instead of duplicating the formula in the adapter meant the fix
was one function, not a hunt through multiple files.

## Phase 3: final hand values had no visibility either (2026-08-09, same day)

Same gap-class as Phase 2's review-card-visibility fix, hit while preparing to live-verify
the reputation-bonus fix above: hand privacy (`docs/loaf-open-questions.md` Q3) is correct
*during* play, but `getAllDatas()`/the client never stop hiding other players' hands even
after the game ends, when every player's final score is already public anyway — there was no
way to check a score's components (which cards, what bonus) against what actually got
computed, only the final number.

Fixed by adding a `scoreBreakdown` notification in `EndGame.php`, alongside the existing
`playerFired`/`gameEnded` ones added when Phase 3 was first implemented: one line per player
in the game log, e.g. `Carl: hand [5, 6, 8, 11] = 30, reputation bonus +5, score 35` (or the
FIRED variant, which shows their reputation instead of a bonus, since a fired player's score
is the shared sentinel from `ScoringCalculator`'s §4 design, not `hand + bonus`). The bonus
value shown is derived from `$scoring->score - $handValues[$playerId] - $bonusPoints[$playerId]`
rather than duplicating `ScoringCalculator`'s private tier table in the adapter — same
"adapter stays thin, Core stays the single source of truth" discipline as everywhere else in
this codebase. Log-text only, same as the review-card fix — `getAllDatas()` itself was
deliberately left untouched (still hides other players' hands during play, and doesn't
proactively reveal them post-game either), since the log notification alone was enough to
unblock live verification without touching hand-privacy semantics more broadly.

## Phase 3 live verification: tie-break polarity confirmed, and one more visibility gap (2026-08-09)

`docs/loaf-phase3-plan.md` §5's flagged framework-API-confidence unknown is now resolved:
tested a genuine score tie between two non-fired players with different final reputations,
and BGA's ranking correctly showed the **lower**-reputation player winning the tie —
confirms `aux = -reputation` (higher aux wins ties, same direction as score) was the right
polarity as originally written, no sign flip needed. This was the one piece of Phase 3 that
truly couldn't be checked any other way; it's now closed out.

Live-testing this surfaced the same visibility gap in a new spot: BGA's standard ranking
screen shows the tie was broken correctly, but nothing explains *why* — no reputation number,
no indication the tie-break even happened. The `scoreBreakdown` log line already had the
`reputation` value available in its args (queried for the FIRED-branch message) but the
non-fired message template never referenced it. Fixed by adding `(reputation ${reputation})`
and `tie-break value ${aux}` to the non-fired `scoreBreakdown` message in `EndGame.php` — no
new query needed, the data was already there, just not surfaced. Same "surface hidden
server-side state via the log" pattern as every other visibility fix this phase
(`docs/bga-studio-reference.md` §6) — worth noting this is now the third time this exact gap
has appeared (review-card effects, final hand values, and now tie-break reasoning), so it's
worth treating as a standing question when adding *any* new end-of-round/end-of-game
notification going forward: "if a tester can't see this on screen anywhere, can they see it
in the log?"

Went one step further than the `reputation`/`aux` numbers, though: added a dedicated
`tieBreak` notification in `EndGame.php` that states the outcome in words (e.g. "Alice wins
the tie over Bob on lower reputation."), rather than leaving it to a tester to compare two
`tie-break value` numbers by hand. Groups non-fired players by identical `score`, then by the
top `aux` within each group: a clean winner gets `"X wins/win the tie over Y..."`; a group
still tied after `aux` too gets `"X, Y are tied on score and reputation -- they share the
victory"` instead of incorrectly naming a winner — the rulebook's own "if there's still a
tie, the tied players share the victory" case. Fired players are excluded from this grouping
entirely, not just skipped when found tied — they can never legitimately tie with an active
player (`ScoringCalculator`'s sentinel score is always strictly below every active score by
construction), so there's no correctness reason to include them, and a tie *among* fired
players isn't a real contest (Q5: already unranked, tied at the bottom, nothing to explain).

**Correction, same day**: the winners/losers grouping was initially written directly inside
`EndGame.php` — real rules logic sitting in a BGA adapter class with no local test harness for
it, same testability gap `EndGame.php`/`ResolveRound.php`/`RoundStart.php` all already have,
noticed when asked "is there a test for this." Extracted into
`ScoringCalculator::tieGroups(array $scores): array` (pure, DB-free, takes `score()`'s own
output as input) — `EndGame.php` now just turns each returned group into player names and
notification text. Six new tests in `ScoringCalculatorTest` cover it directly: no ties, a
clean winner, a full shared-victory, a partial win/lose split among three tied players,
multiple independent tied groups in one game, and — the one most likely to regress silently —
confirming two fired players who'd otherwise look identically "tied" are excluded from
grouping altogether. The literal English notification *text* in `EndGame.php` is still
untested (same boundary as `gameEnded`'s message selection), but the *decision* it reports —
who actually wins each tie — now has real coverage.
