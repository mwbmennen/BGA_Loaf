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

## Phase 3: fired-player score changed from derived sentinel to fixed `-20` (2026-08-09)

While discussing the fired-player sentinel design (why `min(activeScores) - 1` instead of a
fixed number), calculated a concrete worst-case for Phase 4: at most two `end_game_malus`
cards can stack on one player (`lowest_reputation` -4 and `reputation_zero` -5, mutually
exclusive with `reputation_negative` since a player can't be both `<0` and `=0`), doubled by
`double_end_game_malus` if that card also resolves — roughly `-18` in a deliberately-engineered
worst case. That number turned a previously-abstract "some future score could be very
negative" concern into a concrete ceiling.

Given that concrete number, explicitly chose to trade the derived sentinel's "never needs
revisiting" property for a simpler fixed constant: `ScoringCalculator::FIRED_SCORE = -20`,
comfortably below the calculated `-18` ceiling. `EndGame.php` needed no changes — same as the
reputation-bonus fix, this is exactly why the sentinel logic lived in one pure Core method
rather than being duplicated in the adapter. Tests updated to assert the exact constant
(`ScoringCalculator::FIRED_SCORE`) rather than a relative "less than every active score"
comparison, in both the multi-player and all-fired test cases.

**Trade-off, stated plainly**: this is no longer correct-by-construction against every
possible future Phase 4 malus catalogue the way the derived version was — if new content ever
pushes a real score below `-20`, this constant needs revisiting by hand. Accepted deliberately,
not overlooked; `docs/loaf-phase3-plan.md` §4 records the reasoning trail from both the
original derived design and this change.

**Live-tested and confirmed (2026-08-09, later same day)**: a fired player's score reads
exactly `-20`; the `FIRED` marker is visible on the board under the correct player's box; all
four reputation-bonus tiers (1-3 → +2, 4-6 → +3, 7-9 → +4, 10 → +5) now confirmed live,
completing the set (0/5 confirmed earlier this session); and the all-players-fired edge case
shows everyone tied at `-20` with no distinct winner.

**Also live-tested and confirmed**: a 2-player game ending with one player fired (the
minimum-player-count edge case for the sentinel/tie logic) works correctly, and no PHP
fatals were seen across any of today's scenarios.

**`tieBreak` confirmed live too**: the exact winner/loser text appeared correctly under the
deployed code, not just reasoned through against pasted numbers.

**Phase 3's live-verification checklist is now fully closed out.** Every item — fired-player
scoring at the fixed `-20`, the `FIRED` marker, all four reputation-bonus tiers, the
all-players-fired edge case, the 2-player minimum-player-count ending, the `tieBreak`
explanation text, and a full sweep with no PHP fatals — has been confirmed on a real Studio
table, not just in PHPUnit. **Phase 3 is complete.**

## Phase 4: wiring discard/swap effects — deferred-card marker instead of a new global (2026-08-11)

Wired `discard_recycle_lowest`, `discard_choice`, and the two swap effects into
`ResolveRound.php` and a new `ResolveAdvancedEffect` multiactive state. (`end_game_bonus`/
`malus`/doublers were still unwired at the time of this entry — see the follow-up entry below,
same day, where `EndGame.php` was wired up too.) Two judgment calls worth recording:

- **No new persisted global for "which players still need to act."** Swap effects
  ("take played card back, then discard one") need the played card to stay individually
  identifiable across the state transition from `ResolveRound` into `ResolveAdvancedEffect`.
  Rather than adding a global to carry that, `ResolveRound` simply *excludes* players who have
  a real eligible discard from the round's normal played->discard bulk move, leaving their
  card sitting in `location = 'played'`. `ResolveAdvancedEffect` then finds its active players
  for a swap effect with `SELECT DISTINCT player_id FROM work_card WHERE location = 'played'`
  — the deferred location *is* the signal, nothing else needed. `discard_choice` doesn't defer
  anything (no played-card involvement), so its active group is instead re-derived fresh from
  `TargetGroupResolver` + current reputations, the same recompute-over-redundant-state pattern
  `EndGame.php` already established for `$endingBoss`.
- **`setPlayersMultiactive()` avoided, unconfirmed for the new typed framework.** Wanted to
  activate only the real target subset in `ResolveAdvancedEffect::onEnteringState()`, but the
  obvious method for that is only documented for BGA's older framework and isn't in this
  project's stubs. Used the safe fallback instead — `setAllPlayersMultiactive()` then
  `setPlayerNonMultiactive()` on everyone excluded — built entirely from already-confirmed
  methods. See `docs/bga-studio-reference.md` §9 for the general write-up (this is a generic
  BGA Studio lesson, not L'Oaf-specific, and is also logged in
  `docs/bga-template-upstream-notes.md` for porting to the template).

**Not yet live-verified** (nothing here is testable outside a real BGA table — no DB, no
gamestate, no notify): the `with_advanced_cards` option's deck composition, all three
interactive effects' action buttons appearing for exactly the right players, the
`setAllPlayersMultiactive` + trim workaround's actual behavior on a live push (does the
briefly-activated-then-deactivated player see any flicker?), and — the specific thing worth
trying while there — swapping in `setPlayersMultiactive($activePlayerIds, '', true)` as a
one-line replacement for the workaround, now that it's confirmed to exist in BGA's docs (just
not yet confirmed for this typed framework). See `docs/loaf-phase4-plan.md` §8.

## Phase 4: end-game bonus/malus wired into `EndGame.php` (2026-08-12)

Closed out the last piece of Phase 4's implementation (`docs/loaf-phase4-plan.md` §9 steps 1-4
are now all done — only live verification, §8, remains). `EndGame.php` no longer zero-fills
`bonusPoints`: it now builds the full list of review effects that actually resolved during the
game (success side for every card filed under the Happy pile, fail side for every card under
the Angry pile — reusing the `$happyCardTypes`/`$angryCardTypes` lists it already had for the
boss-pile check, unfiltered, since `EndGameEffectResolver` already ignores anything that isn't
one of its four effect types) and feeds that straight into
`EndGameEffectResolver::resolve()`, whose stacking/doubling behavior was already designed and
unit-tested in an earlier session (see this doc's "Phase 4: wiring discard/swap effects" entry
above, and the `EndGameEffectResolverTest` coverage for multiple-bonus-cards-stack,
doubler-only-affects-its-own-polarity, and irrelevant-effect-types-ignored). No new judgment
calls here — this was mechanical wiring of already-decided, already-tested logic, not a new
design decision.

One small deliberate addition beyond the minimum: the `scoreBreakdown` log line now shows the
end-game bonus/malus contribution explicitly (`end-game bonus ${endGameBonus}`), always, even
at 0 — the same "surface hidden state via the log" and "show every term unconditionally"
treatment already used for hand value and reputation bonus. This was flagged as needed in
`docs/loaf-phase4-plan.md` §8 point 5 specifically so a live tester can check the number
against the log instead of reverse-engineering it from the final score alone.

**Still not live-verified** — same caveat as the entry above, nothing here runs outside a real
BGA table. Specifically added by this change: whether an end-game bonus, an end-game malus, and
a doubler each actually show up correctly in a real final score, cross-checked against this new
log line.

## Phase 4 live verification (2026-08-14)

Worked through `docs/loaf-phase4-plan.md` §8's checklist live on Studio. Everything passed:

- Deploy/schema/log pre-checks, the `with_advanced_cards` option (appears, defaults off, off
  means no advanced card is ever drawn), and deck composition with it on.
- All three interactive effects (`discard_choice`, both swap variants) activated the targeted
  player(s) with action buttons appearing on the live push, no refresh needed — no repeat of
  the Phase 2 activation-timing bug in this brand-new state class. A multi-target case (several
  `reputation_negative` players at once) correctly waited for every targeted player, not just
  the first to act.
- Both deterministic effects (`discard_recycle_lowest`, a `counts_as_two` card ending the game
  a round early) worked with no player interaction.
- End-game scoring: an `end_game_bonus`, an `end_game_malus`, and a doubler all showed up
  correctly in the final score, matching the `scoreBreakdown` log line's `end-game bonus
  ${endGameBonus}` field by hand-calculation.
- The one open API question: swapped `ResolveAdvancedEffect::onEnteringState()`'s
  activate-all-then-trim two-step (`setAllPlayersMultiactive()` +
  `setPlayerNonMultiactive()` per non-targeted player) for a single
  `setPlayersMultiactive($activePlayerIds, '', true)` call — **it works** on the typed
  framework. Kept as the real implementation; `tests/stubs/BgaFrameworkStubs.php` and
  `docs/bga-studio-reference.md` §9 updated from "unconfirmed" to "confirmed" accordingly.

**Left unchecked, genuinely blocked, not a gap in this pass**: the zombie/disconnect fallback
(`ResolveAdvancedEffect::zombie()`) for an idle targeted player. Express mode (one browser tab
controlling every seat) doesn't simulate a real disconnect — quitting a seat there stops the
game outright instead of handing control to the zombie AI — so this needs a real multiplayer
table with an actual second connection, not available this session
(`docs/bga-studio-reference.md` §6, "Express mode can't test zombie/disconnect behavior").
Revisit once that's available; nothing else about Phase 4 depends on it.

Phase 4 is otherwise complete: implementation (§9 steps 1-4, see the two entries above),
tests, and live verification all done.

## Round-card hover-tooltip explanation text (2026-08-15)

Added a plain-language explanation panel next to the existing hover-zoom card image
(`Game.js`'s `setupRoundCardFrontDiv`, previously "visual-only" per its own comment). The text
("Order: worth N work per player" / "On success: ..." / "On fail: ...") is built server-side in
a new `Game::buildRoundCardDescriptions()`, reusing `ReviewEffectDescription::target()`/
`amount()` (already existed for the `reviewCardRevealed`/`reviewEffectApplied` notification
log lines) rather than inventing new phrasing, and sent once for all 24 card types via
`getAllDatas()`'s `roundCardDescriptions` key — cheap enough that filtering to only the
in-play basic/advanced subset wasn't worth the extra code, and the text isn't a spoiler risk
(the rulebook's own effects are public knowledge, unlike hidden state).

The tooltip only shows the text matching the face actually rendered (`card.side`): an
order-side card's tooltip shows only the order line, a review-side card's tooltip shows only
the success/fail lines — matching the physical card (each side only shows its own face) rather
than dumping the full order+review text on every hover regardless of which side is up.

The zoom image itself also mirrors the card's on-board rotation (`card.rotation`, the same
quarter-turns field `getCardRotation` already reads) rather than always rendering portrait —
the pending review card displays landscape next to the order card, so its tooltip would
otherwise show a portrait image that no longer matches what's on screen. Implemented by hand
(not via bga-cards, since the tooltip HTML is a raw string passed to `addTooltipHtml`, outside
that library's own card-element machinery): the inner image div keeps its native 250x348 size
and gets `transform: rotate()` + centering, while the outer container swaps to the rotated
bounding box (348x250) so the tooltip's layout doesn't clip.

Layout also differs by side: the order card keeps the original side-by-side layout (image
left, text right), while the review card's text sits *below* the (landscape-rotated) image
instead — a full-width caption reads better under a wide rotated image than squeezed into a
narrow side column. Two CSS modifier classes (`loaf_card-tooltip--side-by-side` /
`--stacked`), picked in JS by `card.side`, not a single one-size-fits-all layout.

This is the first place in this codebase that needed a *non-notification* server-side
translated string, so it's also the first real usage of `self::_()` (per
`docs/bga-studio-reference.md`'s translation section) — added a stub for it in
`tests/stubs/BgaFrameworkStubs.php` (identity passthrough, no language context available in
tests). **Not live-verified**: whether `self::_()` is actually the right method name on the
real typed framework (vs. a bare `_()` global, per that section's own
"framework-version-dependent" caveat) is unconfirmed — if it's wrong, the tooltip still works,
it just silently never translates. Revisit once a second language is actually added and this
becomes testable.

## Pre-existing bug surfaced by the tooltip feature: `roundStart` never carried the review card's id/type (2026-08-17)

Live-testing the hover-tooltip feature above hung after round 1 with a client-side fatal
(`Cannot read properties of undefined (reading 'fail')` in `setupRoundCardFrontDiv`). Root
cause turned out to be unrelated to the tooltip code itself: `RoundStart.php`'s `roundStart`
notification (fired every round, per `docs/loaf-phase5-plan.md` §7) never included
`reviewCardId`/`reviewCardType` — those two fields were only ever sent on the separate
`reviewCardRevealed` notification, which has no client handler wired to `pendingReviewStock`.
But `Game.js`'s `notif_roundStart` reads `args.reviewCardId`/`args.reviewCardType` directly to
rebuild `pendingReviewStock` from scratch every round. Round 1 never hit this because the
*initial* pending-review card is seeded from `gamedatas.currentReviewCardType` on page load
(`setupRoundCardStocks`), a completely different code path from the notification handler used
for every subsequent round.

Before the tooltip feature this was a **silent** bug, not a crash: `card.type` being
`undefined` just meant the review card's zoom sprite silently rendered at the wrong (or
blank) position from round 2 onward — easy to miss without a keen eye on the art, and
apparently never caught across all of Phase 1-4's live verification sessions. The tooltip's
new `roundCardDescriptions[card.type]` lookup turned the same silent `undefined` into a hard
`TypeError` that hangs the round, which is what actually got it caught. Fixed by adding
`reviewCardId`/`reviewCardType` to the `roundStart` notification's args in `RoundStart.php`,
alongside the `orderCardId`/`orderCardType` fields that were already there correctly (the
adjacent code comment even said "same reasoning as reviewCardId/reviewCardType above,"
suggesting this was an oversight rather than a deliberate omission when `reviewCardRevealed`
was first added in Phase 2).

**Worth generalizing**: a notification's payload silently diverging from what the client
handler actually reads is a gap at the boundary between two files that both look correct in
isolation — `RoundStart.php` in isolation has no obvious bug, `Game.js` in isolation has no
obvious bug, the mismatch only shows up by reading both together. Logged as a generic pattern
in `docs/bga-studio-reference.md` and `docs/bga-template-upstream-notes.md`: a stricter-typed
notification-args contract, or at minimum a live smoke test that plays ≥2 rounds instead of
just verifying round 1 loads, would have caught this earlier.

## Board & reputation-track rendering (2026-08-17)

Built `docs/loaf-phase5-plan.md` §5: `img/board.png` as the reputation-track background, one
real chef-hat token (`img/tokens.png`) per player positioned along it, replacing the old
per-player "Reputation: N" text line entirely — `notif_reputationChanged` now moves a
positioned element instead of overwriting a text node, per the plan's own description of this
step. Several judgment calls, none spelled out by the plan at this level of detail:

- **Track pixel geometry was measured, not guessed.** `img/board.png` (740×232) has no
  machine-readable layout data — installed Pillow ad hoc and sampled a horizontal scanline
  across both track halves to find the bright cream divider lines between columns, rather than
  eyeballing the downscaled thumbnail render. Found 10 equal-width (~31.1px) columns per side
  (-10..-1 and 1..10) flanking one wider (~72px) "0" column. Recorded as
  `REPUTATION_TRACK`/`reputationTrackPositionPercent()` in `Game.js`, with the measurement
  method in a comment so a future re-measurement (if the board art is ever re-exported at a
  different size) knows how to redo it. **Not live-verified against an actual browser
  render** — the measurement is only as good as this Pillow analysis; if a token visibly sits
  off its printed number on Studio, re-check this geometry first.
- **`player.color` needed an explicit DB column that wasn't being sent.** `getAllDatas()`'s
  `players` query never selected `player_color` before this — nothing needed it until token art
  required knowing which of the 6 sprite columns to use. Queried it directly rather than
  assuming the framework auto-merges `color` into `gamedatas.players` for free, same
  "unverified default" caution as `RoundStart.php`'s explicit deck-order sort
  (`docs/loaf-phase1-plan.md`'s "Framework API confidence note") — no vendored framework
  locally to confirm the automatic-merge behavior either way, and querying it explicitly costs
  nothing if it turns out to have been redundant.
- **Same-value tokens get a fixed per-player vertical lane, not collision-detected
  stacking.** Multiple players can land on the same reputation value at once, and the track's
  columns are visually narrow — without separation, tokens would sit exactly on top of each
  other and become indistinguishable. Chose a fixed lane per player (by player order, assigned
  once at setup) fanned out vertically from board-center, rather than dynamically detecting and
  resolving collisions on every reputation change — the lane a token occupies never changes
  once assigned, so a player's token doesn't visually jump between rows as reputation changes,
  only left/right. Simpler to implement and reason about than recomputing groupings on every
  `notif_reputationChanged`, at the cost of always reserving vertical space for every player's
  lane even when no collision is currently happening.
- **The old "Reputation: N" text line was removed outright, not kept alongside the token.**
  The plan's own wording for this step is explicit ("now moving a positioned element instead
  of overwriting text content"), read as intentionally superseding the old text placeholder the
  way the boss-pile/pending-card text placeholders were already superseded by real art in §7,
  not merely changing the update *mechanism* while keeping the number visible elsewhere. Kept
  the reputation number accessible anyway via a `title` tooltip on the token
  (`"PlayerName: N reputation"`, updated live in `notif_reputationChanged`) — cheap insurance
  against the same "silent unless you can see it" trap already hit three times in earlier
  phases (Phase 2's review-card visibility, Phase 3's final-hand-values and tie-break gaps, all
  logged above) without duplicating a full text line back into `player-tables`.

**Not yet live-verified at all** — nothing in this entry has been checked in an actual browser.
Specifically to check on next deploy: token x-position actually lines up with its printed
number at real rendered sizes (not just the measured-from-source-PNG math), token art (alpha
transparency, correct color per player) renders correctly, the vertical-lane fan-out looks
reasonable rather than cramped at 6 players, and the `drop-shadow` filter reads against both
the tan and olive halves of the track as intended.

**Follow-up, same day**: after seeing a live screenshot of BGA's own standard player panel
(name/score/flag box, not this game's custom board), also added a reputation readout there
(`setupPlayerPanelReputation` in `Game.js`) via `this.bga.playerPanels.getElement(player.id)`
— first use of that API in this project, unverified locally (see
`docs/bga-studio-reference.md`'s new "Adding a custom stat to BGA's standard player panel"
section for the full write-up and doc source). This is a second, independent readout of the
same number the board token already shows — deliberately not a replacement for the token,
since the token is the thing that's actually spatial/at-a-glance, this is just a precise
number in the one place a player's eye is already looking for their score.

**Live-verified and one geometry bug fixed (2026-08-17, later same day)**: a Studio screenshot
showed the token sitting visibly left of its printed number, positive side only. Root cause:
`posLeftEdge`'s original value (403) was taken directly from the very first detected divider
peak in the Pillow scan, which turned out to be a ~5px outlier — every other divider on both
halves of the track measured a consistent ~31px apart, but that one specific peak (right next
to the wider "0" column) measured 35px from its neighbor. Refitting from the 9 *consistent*
later divider positions instead (`718 - 10*31 = 408`) gives the correct value, confirmed
against the live screenshot. Also updated `zeroColWidth` (72→77) since the "0" column's right
edge is the same physical divider as `posLeftEdge`, not a separately-measured value — fixing
one without the other would reintroduce the same inconsistency one column over.
`negLeftEdge`/the negative-side spacing needed no correction; that half's divider measurements
were already internally consistent (no outlier) when originally taken. **Worth generalizing**:
when a pixel-measurement pipeline produces N samples that should be evenly spaced, trust a
least-squares/majority fit over any single endpoint sample, even (especially) the ones nearest
a genuinely-different neighboring region where antialiasing or a differently-colored abutting
element is more likely to throw off exactly one reading.

## Board & reputation-track rendering: a whole token silently invisible after refresh, two stacked bugs (2026-08-17)

A live screenshot after a page refresh showed only one of two players' reputation tokens on
the board — the other simply absent, no visible artifact, no browser console error at all
(`console.log("Ending game setup")` printed cleanly, confirming `setup()` ran to completion for
both players without throwing). That ruled out a crash and pointed at something computing a
*silently wrong but valid* value instead. Two separate real bugs, both in code this session
just wrote, neither one alone fully explaining the symptom without the other:

- **`getAllDatas()`'s `players` query never cast its numeric columns.** `getCollectionFromDb`
  returns every column as a raw PHP string (driver output, not cast) — this doc already has a
  fully generic write-up of the exact same bug class in `Game::getPartCounts()`
  (`docs/bga-studio-reference.md`'s "A missed `(int)` cast on a DB value..." section), but the
  `players` query added for Phase 5 token art fell into it fresh anyway, because the query
  itself long predates any numeric use of `reputation`/`score`/`id` — the original per-player
  text line just interpolated the string into a text node, where string-vs-number is invisible.
  Only `reputationTrackPositionPercent`'s new `value === 0` *strict* equality check (this file's
  earlier "Board & reputation-track rendering" entry) actually exposed it: a `"0"` string
  reputation fails `=== 0` and falls into the positive-value arithmetic branch instead, landing
  a few percent off within the wide "0" column rather than dead center. Fixed by casting
  `id`/`score`/`reputation` to `(int)` and `fired` to `(bool)` once in `Game.php`, at the
  source, matching the established fix pattern rather than adding a defensive `Number()` at
  every JS call site.
- **An unmatched `player.color` produced an out-of-range sprite position that renders as fully
  transparent, not visibly wrong.** `PLAYER_COLOR_HEX_TO_NAME[player.color]` returning
  `undefined` (colorName not found) fed `PLAYER_COLORS.indexOf(undefined)` = `-1` into
  `spritePositionPercent`, producing `background-position-x: -20%` — outside the sprite sheet's
  0-100% range, which for a `background-repeat: no-repeat` element shows nothing at all (no
  content there to display, just the fully-transparent PNG's own alpha/absence). A DOM element
  genuinely existed, sized correctly, positioned correctly — just permanently invisible, and
  nothing about that path throws or logs. Exact root cause of the color mismatch itself is still
  unconfirmed (case-sensitivity in how BGA stores the hex vs. `gameinfos.jsonc`'s literal
  casing is the leading suspect, unverified locally, no vendored framework to check either way)
  — rather than chase that further blind, made the lookup case-insensitive (`.toUpperCase()`
  before the lookup) and added a fallback to the first sprite column plus a `console.warn` on
  any future mismatch, so the failure mode changes from *silently invisible* to *visibly wrong
  color, loudly logged* regardless of what's actually causing a mismatch.

**Worth generalizing, again**: this is the second time in one afternoon that "no exception, no
visible clue, but something's wrong" turned out to trace back to a raw DB string flowing into
code that assumed a real type (first the `roundStart` notification gap, now this). Any code
path that does real comparison/arithmetic/lookup on a `gamedatas`-sourced value — not just
display it as text — is worth a second look at whether the PHP side actually casts it, not just
whether the JS logic itself looks right in isolation.

**Live-verified fixed (2026-08-17, later same day)**: both fixes confirmed on Studio — the
previously-invisible player's token now renders. This closes out §5's live-verification
checklist: track geometry (both corrections above), token art/color, and the standard
player-panel reputation readout are all confirmed working across a refresh.

## Board & reputation-track rendering: the board isn't vertically symmetric between halves (2026-08-17, later same day)

A further live screenshot flagged negative-side tokens sitting "a bit low" — specifically,
overlapping the top edge of the printed `-10..-1` number strip. The original vertical
placement used one universal `top: 50%` (board-vertical-center) for every token regardless of
value, on the assumption that both halves' open track area were symmetric around board-center
the way the horizontal geometry is. Re-measuring with Pillow (same scanline technique as the
horizontal fix) showed that assumption was wrong: the negative half's number strip sits
lower-middle with its own biggest open, strip-free area *above* it (y 36-134 of 232, center
36.6%), while the positive half's strip sits near the top with its open area *below* it (y
96-194, center 62.5%) — both open areas are exactly 98px tall (confirming a deliberate,
symmetric board design), just mirrored across the strip rather than across the board's
physical center. A single "50%" constant happened to clear the positive strip comfortably but
sat right against the negative one.

Fixed by giving `REPUTATION_TRACK` two additional measured constants
(`negVerticalCenter`/`posVerticalCenter`) and a new `reputationTrackVerticalCenterPercent(value)`
alongside the existing horizontal function — "0" keeps true board-center (its own two "0"
labels sit at the very top/bottom edges, not mid-column, so nothing splits its open area the
way the strips split the other two halves). Because a token can now change *vertical* region
(crossing into or out of the "0" column) as well as horizontal position as reputation changes,
`notif_reputationChanged` needed to start recomputing `top` too, not just `left` — the
per-player lane offset (`docs/loaf-remarks.md`'s original §5 entry) is stored on the element
itself (`dataset.laneOffsetPx`) precisely so it can be reapplied against a new vertical center
without needing to re-derive which lane a player occupies from scratch on every notification.
