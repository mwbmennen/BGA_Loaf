# L'Oaf — Phase 3 Implementation Plan

> Companion to `docs/loaf-phase2-plan.md` (Phase 2, complete and live-verified — see
> `docs/loaf-remarks.md`'s "Phase 2 live verification" entry) and
> `docs/loaf-implementation-plan.md` §7, which scopes Phase 3 as: "`ScoringCalculator`,
> fired-player handling, end-game UI/standings. Once this and Phase 2's `ReviewEffectResolver`
> land, the pure-Core surface is complete enough that a standalone balance-simulation harness
> ... becomes buildable."

## 1. What Phases 1–2 already give us for free

Before scoping new work, worth being precise about what's already in place, because it's more
than the top-level plan implies:

- **`player_reputation` and `player_fired`** are already columns on `player`
  (`dbmodel.sql`, added in Phase 1). `player_fired` has never been written to yet — Phase 3 is
  its first real consumer.
- **`player_score` and `player_score_aux`** need no schema change at all — these are standard
  BGA framework columns on the `player` table (confirmed: `Game.php`'s `getAllDatas()` already
  `SELECT`s `player_score` without any corresponding `ALTER TABLE`, and nothing in this repo
  ever added it). Phase 3's entire DB-schema footprint is **zero new columns/tables**.
- **`EndConditionChecker::weightedCount()`/`checkEnd()`** (`modules/php/Core/EndConditionChecker.php`,
  Phase 2) already compute "which boss pile reached 5" from `round_card` on demand. `EndGame.php`
  doesn't need a new Core class for this part — it needs to call the exact same two static
  methods `ResolveRound.php` already calls, against the final pile contents, per
  `loaf-phase2-plan.md` §7's explicit "`EndGame.php` needs no changes [for Phase 2]... Phase 3
  can re-derive 'which pile has ≥5' directly from `round_card` at `EndGame` time" decision. No
  new global needed to carry "why did the game end" forward from `ResolveRound` to `EndGame`.
- **`EndGame.php` is currently a real stub**, not an accidental placeholder — its own docblock
  says so explicitly: `TODO Phase 3: real ScoringCalculator (hand value + reputation bonus +
  fired-player exclusion + tie-break by lowest reputation). For now player_score is left at its
  default (0 for every player)`. Phase 3's job is exactly and only what that TODO says.
- **The client already has a minimal `EndGame` state class** (`modules/js/Game.js`) that sets a
  "The game is over" status bar message. BGA's own standard end-of-game screen renders the
  player ranking table directly from `player_score`/`player_score_aux` — no custom JS is needed
  to *display* final scores, only (optionally) to add a "fired" indicator, per §8 below.

## 2. Explicit scope boundary

**In scope for Phase 3:**
- `ScoringCalculator` — hand value + positive-only reputation bonus + fired-player exclusion +
  tie-break by lowest reputation + shared victory, mapped onto BGA's native
  `player_score`/`player_score_aux` ranking mechanism (§6).
- Wiring it into `EndGame.php`, replacing the "player_score stays at its default" stub.
- A `bonusPoints` input to `ScoringCalculator`, deliberately plumbed through end-to-end but
  **always zero-filled** in Phase 3 (§2's "out of scope" below explains why) — mirrors
  `EndConditionChecker` being built weight-aware in Phase 2 before any weight-2 card existed
  in play, so Phase 4 only has to *populate* this map with real values, not touch
  `ScoringCalculator`'s signature or `EndGame.php`'s wiring at all.

**Explicitly out of scope** (don't scope-creep into these):
- **Advanced-card bonus/malus points themselves** — Phase 4. Confirmed from the rulebook's own
  Advanced effect reference: `Ribbon, +X` / `Ribbon, -X` ("gain/take X bonus/minus points at the
  end of the game") and the `Ribbon +, x2` / `Ribbon -, x2` doublers are all advanced-card-only
  effects (`docs/Loaf-English-rules.md`, "Advanced effect reference"). Nothing produces a
  non-zero `bonusPoints` entry until Phase 4's `ReviewEffectResolver` extension resolves
  `end_game_bonus`/`end_game_malus`/`double_end_game_bonus`/`double_end_game_malus` — all
  currently deliberate no-ops (`ReviewEffectResolver::resolve()`'s early `return []` for any
  non-`'reputation'` effect type).
- **`with_advanced_cards` table option, advanced cards in the deck at all** — Phase 4.
- **Real end-game UI/standings polish** (animated reveal, final board state, etc.) — Phase 5.
  Phase 3's client footprint stays "functional, not pretty," same discipline as Phases 1–2.
- **The standalone balance-simulation harness** the top-level plan flags as "buildable" once
  Phase 3 lands (`loaf-implementation-plan.md` §7) — that's a future project, not a Phase 3
  deliverable. Phase 3 only needs to make `ScoringCalculator` itself pure/DB-free (already the
  plan below), not actually build the harness.

## 3. Rules confirmed directly from `docs/Loaf-English-rules.md`

Quoting the rulebook's own "End of the game"/"Scoring" sections and the already-answered open
questions, since precision here avoids the kind of judgment-call drift Phases 1–2 had to flag
repeatedly:

1. **Which boss ended the game determines firing, not reputation alone**: "Once either the
   Happy Boss or the Angry Boss card has 5 cards under them, the game ends... If the Angry Boss
   card has 5 cards, all players with a reputation below 0 are fired... If the Happy Boss card
   has 5 cards, no players are fired, and all players proceed to scoring" (lines 128–133). A
   player sitting at -5 reputation when the game ends via the **Happy** Boss is *not* fired —
   the ending boss is a hard gate on whether firing happens at all, not just on the threshold.
2. **Scoring formula, exact components** (lines 137–143): for each non-fired player, sum (a)
   "the values of all work cards still in your hand," (b) "your bonus points if you have a
   positive reputation value" with the explicit note "*you do not lose points for a negative
   reputation value*" (i.e. `max(0, reputation)`, not `reputation` — a player at -7 contributes
   `0` to their score, not `-7`), and (c) "any bonus or minus points from active review cards
   (only when playing with advanced croissant round cards)" — component (c) is the `bonusPoints`
   map from §2, always zero in Phase 3.
3. **Winner determination**: "The player with the highest score is the winner! In case of a
   tie, the player among them with the lowest reputation wins. If there's still a tie, the tied
   players share the victory" (lines 145–146). Two sequential tie-breaks: score, then
   *ascending* reputation (lower wins), then shared victory if still tied.
4. **Fired-player display — already answered, not a new judgment call**:
   `docs/loaf-open-questions.md` Q5: "all fired players are shown tied at the bottom — no
   ordering among themselves by reputation." So fired players are *not* ranked amongst each
   other by their own reputation/would-be score, even though non-fired players' ties *are*
   broken by reputation (point 3 above) — these are two different rules for two different
   groups, not one rule applied inconsistently.
5. **All-players-fired edge case — already answered**: Q6: "shared last place for everyone, no
   winner," and the rulebook itself says "*In the event that all players are fired, there is no
   winner*" (line 148). Consistent with Q5 — everyone tied, nobody "wins" that tie.

## 4. Judgment call: fired-player score as a dynamic sentinel, not a fixed magic number

The cleanest way to satisfy points 3–5 of §3 simultaneously, using BGA's own native
score/score_aux ranking (so no custom ranking logic is needed anywhere — see §5): compute every
player's **raw** score first (as if nobody were fired), then for fired players, discard that raw
score entirely and replace it with one shared value guaranteed to rank below every non-fired
player's score:

```php
$firedScore = empty($activeScores) ? 0 : min($activeScores) - 1;
```

**Why not a fixed constant like `-1` or `-1000`**: under Phase 3 rules, every legitimate raw
score is provably ≥ 0 (hand values are non-negative; the reputation-bonus term is
`max(0, reputation)`, never negative; `bonusPoints` is always 0 in Phase 3) — so a fixed `-1`
would work *today*. But it silently stops being safe the moment Phase 4 activates
`end_game_malus`/`double_end_game_malus`, which can legitimately push an active (non-fired)
player's real score below `-1`. Deriving the sentinel from `min(activeScores) - 1` at score time
is correct regardless of what the achievable score range ever becomes — it never needs
revisiting when Phase 4 lands, same "build it right once" discipline Phase 2 applied to
`EndConditionChecker`'s `>=` comparison (§4 of that plan).

**The empty-`activeScores` case is exactly the all-fired edge case (§3 point 5)**: if every
player is fired, `min()` has nothing to operate on, so every fired player instead gets the same
arbitrary constant (`0` in the sketch above — the actual value is unobservable, since nobody
is being compared to it). This falls out of the general rule for free, rather than needing a
separate `if (all fired)` branch — worth calling out in review since it's not obvious from the
code alone that this *is* the all-fired case.

**`score_aux` for fired players must also be flat, not reputation-derived** — this is the part
easy to get wrong by analogy with non-fired players' aux (§5). Since Q5 says fired players are
*not* sub-ranked by reputation among themselves, giving them `aux = -reputation` (the formula
used for active players) would silently re-introduce exactly the ranking Q5 says not to have,
the moment two fired players' `firedScore` ties (which they always do, being the same shared
value) but their `aux` doesn't. Fired players get one shared `aux` value too (e.g. `0`),
matching their shared `score`.

## 5. Judgment call / framework-API-confidence note: `player_score_aux` tie-break direction

**Recommended design, not yet locally verifiable**: `player_score_aux = -reputation` for
non-fired players, on the assumption that BGA's standard ranking treats `player_score_aux` the
same direction as `player_score` — i.e. *higher* aux wins a tie, same as higher score wins
outright. Negating reputation turns "lowest reputation wins the tie" (rulebook, §3 point 3)
into "highest aux wins the tie," matching that assumed convention.

This repo has no vendored BGA framework (same limitation flagged repeatedly in Phases 1–2 — see
`docs/loaf-phase1-plan.md`'s "Framework API confidence note" and
`docs/bga-studio-reference.md` §5), so **this direction is a documented assumption, not a
confirmed fact**, cross-checked only against BGA's public docs describing `player_score_aux` as
a secondary sort key, not against this project's specific framework version's actual tie-break
polarity. Treat a live game that reaches a genuine score tie among non-fired players with
different reputations as the test case (§10 below) — if the *higher*-reputation player is shown
winning instead of the lower-reputation one, the fix is a one-line sign flip
(`aux = $reputation` instead of `-$reputation`), not a design change.

## 6. `ScoringCalculator`

```php
final class ScoringResult
{
    public function __construct(
        public readonly int $score,
        public readonly int $aux,
        public readonly bool $fired,
    ) {
    }
}

final class ScoringCalculator
{
    /**
     * @param array<int, int> $handValues player_id => sum of remaining work-card values.
     * @param array<int, int> $reputations player_id => final reputation (-10..+10). Also the
     *     source of truth for which player_ids exist -- every other map must share its keys.
     * @param array<int, int> $bonusPoints player_id => advanced-card end-of-game bonus/malus
     *     points. Always zero-filled in Phase 3 (see docs/loaf-phase3-plan.md §2) -- caller
     *     passes an explicit zero map rather than this method defaulting it, so Phase 4 only
     *     needs to populate real values here, not touch this signature.
     * @param 'happy'|'angry' $endingBoss which boss pile reached the end-game threshold --
     *     gates whether firing happens at all (docs/loaf-phase3-plan.md §3 point 1).
     * @return array<int, ScoringResult> player_id => final score/aux/fired-status.
     */
    public static function score(
        array $handValues,
        array $reputations,
        array $bonusPoints,
        string $endingBoss,
    ): array {
        if (empty($reputations)) {
            throw new InvalidArgumentException('Cannot score a game with no players');
        }

        $firedPlayerIds = $endingBoss === 'angry'
            ? array_keys(array_filter($reputations, static fn(int $rep): bool => $rep < 0))
            : [];

        $rawScores = [];
        foreach (array_keys($reputations) as $playerId) {
            $rawScores[$playerId] = $handValues[$playerId]
                + max(0, $reputations[$playerId])
                + $bonusPoints[$playerId];
        }

        $activeScores = array_diff_key($rawScores, array_flip($firedPlayerIds));
        // Shared sentinel below every active score -- see docs/loaf-phase3-plan.md §4 for why
        // this is derived, not a fixed constant, and why the empty case is the all-fired edge
        // case (Q6) falling out for free.
        $firedScore = empty($activeScores) ? 0 : min($activeScores) - 1;

        $result = [];
        foreach (array_keys($reputations) as $playerId) {
            $fired = in_array($playerId, $firedPlayerIds, true);
            $result[$playerId] = new ScoringResult(
                score: $fired ? $firedScore : $rawScores[$playerId],
                aux: $fired ? 0 : -$reputations[$playerId],
                fired: $fired,
            );
        }
        return $result;
    }
}
```

Notes on this shape:
- `$handValues`/`$bonusPoints` are trusted to have an entry for every `$reputations` key — the
  adapter (`EndGame.php`, §7) is responsible for that, the same "adapter fills gaps, Core trusts
  its inputs" split already established by `RoundResolver`/`ReviewEffectResolver` (neither of
  those defensively re-derives player sets from multiple sources either).
- Deliberately returns one `ScoringResult` per player (score **and** aux **and** fired,
  bundled) rather than three parallel maps — avoids three separate loops staying in sync by
  convention, same reasoning `RoundResolver` already applied by returning one `RoundResult`
  instead of separate total/success/delta return values.
- No shared-victory / winner-computation logic lives here at all — by design. Mapping directly
  onto BGA's native `player_score`/`player_score_aux` ranking means BGA's own framework already
  handles "highest score wins, ties broken by aux, still-tied players share the rank" for free
  (§5's tie-break direction is the only unverified part of that). `ScoringCalculator` only needs
  to produce the two numbers that ranking is built from — resist the temptation to also compute
  "who won" here, that would be duplicating logic BGA already owns and risks disagreeing with
  what the platform actually displays.

## 7. Wiring into `EndGame.php`

```php
public function onEnteringState()
{
    // Re-derive which boss ended the game -- no new global needed, same query shape
    // ResolveRound.php already uses each round (docs/loaf-phase2-plan.md §7).
    $happyCardTypes = $this->game->getObjectListFromDb(
        "SELECT `card_type` FROM `round_card` WHERE `card_location` = 'review_happy'", true
    );
    $angryCardTypes = $this->game->getObjectListFromDb(
        "SELECT `card_type` FROM `round_card` WHERE `card_location` = 'review_angry'", true
    );
    $endingBoss = EndConditionChecker::checkEnd(
        EndConditionChecker::weightedCount($happyCardTypes, 'success'),
        EndConditionChecker::weightedCount($angryCardTypes, 'fail'),
    );
    // Non-null is guaranteed here: ResolveRound only ever transitions to EndGame::class when
    // checkEnd() already returned non-null against these same piles (ResolveRound.php's final
    // check) -- nothing files a card between that check and this state being entered.

    $reputations = array_map('intval', $this->game->getCollectionFromDb(
        'SELECT `player_id` AS `id`, `player_reputation` FROM `player`', true
    ));

    $handValues = array_map('intval', $this->game->getCollectionFromDb(
        "SELECT `player_id` AS `id`, COALESCE(SUM(`value`), 0) AS `total` FROM `work_card` "
            . "WHERE `location` = 'hand' GROUP BY `player_id`",
        true
    ));
    // A player with zero cards left in hand has no GROUP BY row at all -- fill the gap
    // explicitly. (Not actually reachable under basic-only play: the game always ends by
    // round 9 at the latest per loaf-phase2-plan.md §5's pigeonhole proof, and every hand
    // starts with 12 cards, so at least 3 always remain -- kept anyway since it's free
    // correctness against Phase 4's weight-2 cards shortening this further, or any other
    // future rule change.)
    foreach (array_keys($reputations) as $playerId) {
        $handValues[$playerId] ??= 0;
    }

    // Always zero in Phase 3 -- see docs/loaf-phase3-plan.md §2. Phase 4 populates this from
    // resolved end_game_bonus/end_game_malus/double_* effects instead of this fill.
    $bonusPoints = array_fill_keys(array_keys($reputations), 0);

    $scores = ScoringCalculator::score($handValues, $reputations, $bonusPoints, $endingBoss);

    foreach ($scores as $playerId => $scoring) {
        $this->game->DbQuery(
            "UPDATE `player` SET `player_score` = {$scoring->score}, "
                . "`player_score_aux` = {$scoring->aux}, "
                . "`player_fired` = " . ($scoring->fired ? 1 : 0)
                . " WHERE `player_id` = $playerId"
        );

        if ($scoring->fired) {
            $this->game->bga->notify->all(
                'playerFired',
                clienttranslate('${player_name} is fired and excluded from scoring'),
                [
                    'player_id' => $playerId,
                    'player_name' => $this->game->getPlayerNameById($playerId),
                ]
            );
        }
    }

    $allFired = count(array_filter($scores, fn($s) => $s->fired)) === count($scores);

    $this->game->bga->notify->all(
        'gameEnded',
        match (true) {
            $allFired => clienttranslate('Every player was fired -- there is no winner.'),
            $endingBoss === 'angry' => clienttranslate(
                'The Angry Boss pile reached 5 cards -- players with negative reputation are fired!'
            ),
            default => clienttranslate(
                'The Happy Boss pile reached 5 cards -- everyone proceeds to scoring.'
            ),
        },
        ['endingBoss' => $endingBoss, 'allFired' => $allFired]
    );

    return ST_END_GAME;
}
```

Adapter responsibilities kept deliberately thin, same pattern as `ResolveRound.php`: three
`SELECT`s to gather state, one `ScoringCalculator::score()` call, a loop of `UPDATE`s +
notifications. All the actual scoring/tie-break/firing logic lives in the pure Core class (§6),
testable without BGA at all.

## 8. Client changes

Minimal, consistent with Phases 1–2's "functional, not pretty" scope — real end-game polish is
Phase 5:

- **No changes required** for the score display itself — BGA's standard end-of-game ranking
  screen already renders `player_score`/`player_score_aux` once the game ends; nothing in
  `Game.js` needs to build a custom standings table.
- **Worth adding** (not required to close out Phase 3, but cheap): a `notif_playerFired` handler
  that visually marks a fired player (e.g. a CSS class toggle on their player panel), so the
  "tied at the bottom" ranking makes sense at a glance rather than only being inferable from the
  score table. A one-line addition alongside the existing `notif_reputationChanged`/
  `notif_roundResolved` handlers (`modules/js/Game.js`).
- **Worth adding**: a `notif_gameEnded` handler that surfaces the `gameEnded` message in the
  page (BGA's game log already shows any notification with a `clienttranslate()` message for
  free, same "reuse the log panel" pattern from the review-card-visibility fix — see
  `docs/bga-studio-reference.md` §6's "Surfacing hidden server-side state before the UI exists").
  No new UI element strictly needed, since the log already carries it.

## 9. Testing plan (PHPUnit, DB-free — same discipline as `RoundResolverTest`/`ReviewEffectResolverTest`)

New file: `tests/Core/ScoringCalculatorTest.php`.

- **Hand-value summation**: a player with a specific remaining hand scores exactly that sum
  when reputation is 0 and not fired.
- **Positive-only reputation bonus**: a player at reputation +4 gets `+4` added to their score;
  a player at reputation -4 gets `+0` added (not `-4`) — directly exercises the rulebook's
  explicit "you do not lose points for a negative reputation value" note (§3 point 2).
- **Happy-boss ending fires nobody**: a player at reputation -8 is *not* fired when
  `$endingBoss === 'happy'`, even though the same reputation *would* fire them on an angry
  ending — the two-part test that makes §3 point 1 a regression test, not just prose.
- **Angry-boss ending fires exactly the negative-reputation players**: a mixed set (some
  positive, some negative, one exactly 0 — confirm 0 is *not* fired, matching "lower than 0"
  wording) resolves to the correct fired set.
- **Fired players share one score below every active player's**, regardless of their own raw
  hand value/reputation — two fired players with very different hands/reputations must produce
  identical `score` *and* identical `aux` (the Q5 "no ordering among themselves" case, and the
  part most likely to regress if someone "helpfully" changes `aux` to use reputation for fired
  players too, per §4's warning).
- **All-players-fired edge case**: every player fired → no crash on `min()` of an empty array,
  every player still gets an identical score/aux (Q6, "shared last place for everyone").
- **Tie-break direction**: two non-fired players with equal `score` but different `reputation`
  — confirm the lower-reputation player's `aux` is numerically higher (locks in §5's assumed
  polarity as a regression test, even though the *actual* BGA ranking behavior still needs live
  confirmation per §10 — this test only proves this class's output is internally consistent
  with the intended direction).
- **Shared victory / already-equal case**: two non-fired players with equal score *and* equal
  reputation → equal `score` and equal `aux`, nothing in this class needs to special-case that;
  worth a test purely to document that BGA's own ranking handles the "still tied" case, not
  this class.
- **`bonusPoints` plumbing, non-zero**: pass a non-zero `bonusPoints` entry for one player
  (simulating what Phase 4 will eventually populate) and confirm it's added into that player's
  score — proves the parameter is genuinely wired through end-to-end now, not just present in
  the signature, even though production code always passes zeros in Phase 3.
- **Empty `$reputations` throws** `InvalidArgumentException` — mirrors
  `RoundResolver`/`ReviewEffectResolver`'s existing fail-fast-on-impossible-input precedent.

## 10. Live verification plan (Studio)

Same discipline as Phases 1–2's Verification sections — nothing here beyond PHPUnit is
exercisable locally, since `EndGame.php` still ultimately runs inside the unvendored BGA
framework, and §5's tie-break polarity specifically **cannot** be confirmed any other way:

1. `vendor/bin/phpunit` clean, including the new `ScoringCalculatorTest`.
2. Play a full game to a **Happy**-Boss ending with at least one player finishing at negative
   reputation. Confirm that player is *not* fired (`player_fired = 0`) and their score includes
   `0` for the reputation-bonus term (not a negative contribution) — the point 1/point 2
   interaction from §3 is the single easiest thing to get backwards in this phase.
3. Play a full game to an **Angry**-Boss ending with a real spread of reputations (some
   negative, some positive, ideally one player at exactly 0). Confirm: players below 0 are
   fired and show up "tied at the bottom" per Q5 (not ordered by how negative their reputation
   is); the player at exactly 0 is *not* fired ("lower than 0", not "0 or lower").
4. Confirm hand-value scoring by hand-computing at least one non-fired player's expected score
   (remaining hand sum + `max(0, reputation)`) against what BGA's ranking screen actually shows.
5. **Engineer or wait for a genuine score tie** among two non-fired players with *different*
   final reputations. This is the one item that actually resolves §5's open question — confirm
   whether the *lower*-reputation player is shown winning the tie (validates the `aux =
   -reputation` polarity as written) or the *higher*-reputation one is (means the sign needs
   flipping). Don't skip this even if it takes an extra test game to force — it's the only
   framework behavior in this phase that can't be inferred from documentation.
6. If feasible, drive a game to the all-players-fired edge case (every player finishes negative
   on an Angry-Boss ending) and confirm the end screen reads as a full tie with no distinct
   winner, matching Q6 and the rulebook's own "there is no winner" line.
7. Confirm no PHP fatals from `EndGame.php` across all of the above, and that the `gameEnded`/
   `playerFired` notifications (if implemented per §8) read correctly in the game log.
8. Update `docs/loaf-remarks.md` with a "Phase 3 live verification" entry once done, same
   pattern as Phases 1–2 — including the resolved `score_aux` tie-break polarity from step 5,
   since that's the one piece of this phase that was genuinely unknown until confirmed live.

## 11. Suggested implementation order

1. `ScoringResult` + `ScoringCalculator` + `ScoringCalculatorTest` (§6, §9) — no BGA dependency,
   build and fully test first, same "Core classes first" discipline as Phases 1–2.
2. Wire into `EndGame.php` per §7 — the only step that touches live/BGA code, keeping the risky
   part small and last.
3. Optional client tweaks (§8: `playerFired`/`gameEnded` log visibility) — skippable without
   blocking anything else, same as Phase 2's optional client notification-message tweak.
4. Deploy, live-verify per §10 (especially step 5's tie-break polarity check), update
   `docs/loaf-remarks.md`.
