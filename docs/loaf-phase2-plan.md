# L'Oaf — Phase 2 Implementation Plan

> Companion to `docs/loaf-phase1-plan.md` (Phase 1, complete and live-verified — see
> `docs/loaf-remarks.md`'s "Phase 1 live verification" entry) and
> `docs/loaf-implementation-plan.md` §7, which scopes Phase 2 as: "swap in real basic-card
> data (once available), implement the basic `ReviewEffectResolver` effect types (target-group
> ± reputation), weighted boss-pile counting, and the end-of-game trigger."

## 1. What Phase 1 already gives us for free

Before scoping new work, worth being precise about what's *already done*, because it's more
than the top-level plan implies:

- **The full 24-card data set is already ported into PHP**, not just transcribed to JSON.
  `modules/php/Core/RoundCardData.php` (460 lines) has real `order.per_player_average` values
  *and* real `review.success`/`review.fail` effect definitions (`target`, `effect`, `amount`,
  `counts_as_two`) for all 12 basic and all 12 advanced cards — confirmed by inspection, not
  placeholder data. This was a deliberate Phase 1 choice ("porting the full data now avoids a
  second transcription pass" — see the class's own docblock), even though Phase 1 only ever
  *read* `order.per_player_average` from it. **So "swap in real basic-card data" is already
  done.** Phase 2's actual data-side job is narrower: *use* the `review` side of this data,
  which nothing reads yet.
- `dbmodel.sql` already has `player_fired BOOL NOT NULL DEFAULT 0` on `player` — added in
  Phase 1, unused so far. Not Phase 2's job to populate (see §6, this is Phase 3's
  fired-player-handling scope) — flagged here only so it isn't rediscovered as "missing."
- The effect vocabulary (`target`/`effect`/`amount`/`counts_as_two` enums) is already fully
  documented in `docs/loaf-remarks.md`'s "Effect vocabulary used in the JSON" section — Phase
  2 doesn't need to redesign this, only implement against it.

## 2. Explicit scope boundary

**In scope for Phase 2:**
- `ReviewEffectResolver` — but **only the `effect: 'reputation'` case**. That's the only
  effect type any basic card uses (confirmed: `discard_choice`, `discard_recycle_lowest`, the
  two `swap_discard_*` types, `end_game_bonus`/`malus`, the `double_*` types, and `none` are
  all advanced-only). A basic-only `ReviewEffectResolver` is a much smaller class than the
  full effect catalogue implies.
- `EndConditionChecker` — weighted boss-pile counting and the real "5 cards in a pile ends
  the game" trigger, replacing Phase 1's placeholder (deck-exhaustion) end condition.
- Wiring both into `ResolveRound.php`, replacing the "No review-effect resolution yet" TODO.

**Explicitly out of scope** (don't scope-creep into these):
- `ScoringCalculator`, fired-player exclusion, real end-game standings — **Phase 3**. Phase 2
  only needs to correctly *detect and report* which boss ended the game; it doesn't need to
  *act* on that (set `player_fired`, compute `player_score`) at all. `EndGame.php` stays a
  stub exactly as it is today.
- Advanced effects (`discard_choice` and friends), advanced cards being shuffled into the
  deck, the `with_advanced_cards` table option — **Phase 4**. `RoundCardData` already has this
  data (§1), which is precisely why `EndConditionChecker` should still be built
  weight-*aware* now even though weight is always 1 in Phase 2 (see §4) — so Phase 4 doesn't
  need to revisit this class at all, just flip on advanced-card shuffling.

## 3. Rules confirmed directly from `docs/Loaf-English-rules.md` (not assumptions)

Quoting the rulebook's own "Structure of a round" (§80-113) and effect reference sections,
since precision here avoids the kind of judgment-call drift Phase 1 had to flag repeatedly:

1. **Step ordering is explicit and sequential**: total-vs-target resolution and its
   reputation delta (rulebook step 4 — what `RoundResolver` already does) happens **before**
   "If the review card has a direct effect, resolve it now" (step 6). So `ReviewEffectResolver`
   must evaluate target groups (e.g. "who currently has the lowest reputation") using
   reputation values **after** `RoundResolver`'s delta has already been applied that round —
   not a snapshot from before the round started. This is now a confirmed rule, not an
   assumption to flag.
2. **Target-group ties are additive, not split** — same precedent `RoundResolver` already
   established for the extreme-card tie case. The rulebook's own icon table says "The
   player(s) with the highest reputation..." / "...lowest reputation..." (plural,
   parenthetical) — every tied player gets the full effect independently, not a divided
   share.
3. **The four basic target groups**, per the rulebook's Target table (§155-162):
   - `highest_reputation` → all players sharing the current maximum reputation.
   - `lowest_reputation` → all players sharing the current minimum reputation.
   - `reputation_positive` → all players with reputation ≥ 0 ("a good reputation (0 or
     higher)").
   - `reputation_negative` → all players with reputation < 0 ("a bad reputation (lower than
     0)").
   Any of these four groups can legitimately be empty (e.g. `reputation_negative` when
   nobody's below 0) — that's a no-op, not an error.
4. **End condition, exact wording**: "Does one of the rows have 5 cards in it? Then the game
   ends... Otherwise, a new round starts" — checked at step 8, **after** discarding (step 7),
   i.e. at the very end of `ResolveRound`'s work for that round, not immediately after filing
   the review card.
5. **`counts_as_two`, exact wording** (Advanced effect reference, §171-203): "Empty effects do
   nothing, but count for two successful or two failed orders on one of their sides" —
   confirms this is purely an advanced-card mechanic (matches `RoundCardData`: only 2 of the
   24 `counts_as_two: true` entries exist, both among `advanced_*` cards).

## 4. Judgment call: no new persisted global-state, weighted counts computed on demand

The top-level plan's original data-model sketch (`loaf-implementation-plan.md` §3) proposed
`boss_happy_weight`/`boss_angry_weight` as tracked globals, incremented each time a card is
filed. **Recommend against this**, in favor of computing the weighted count on demand from
`round_card` + `RoundCardData` every time it's needed:

```php
final class EndConditionChecker
{
    public const CARDS_TO_END = 5;

    /** @param string[] $cardTypes card_type values of every card currently in one boss pile */
    public static function weightedCount(array $cardTypes, string $side): int
    {
        $weight = 0;
        foreach ($cardTypes as $cardType) {
            $weight += RoundCardData::TYPES[$cardType]['review'][$side]['counts_as_two'] ? 2 : 1;
        }
        return $weight;
    }

    /** @return 'happy'|'angry'|null which boss pile just reached the threshold, if any */
    public static function checkEnd(int $happyWeight, int $angryWeight): ?string
    {
        if ($happyWeight >= self::CARDS_TO_END) {
            return 'happy';
        }
        if ($angryWeight >= self::CARDS_TO_END) {
            return 'angry';
        }
        return null;
    }
}
```

**Why not a tracked global** (directly informed by today's live-debugging session, see
`docs/bga-studio-reference.md` §5): a running counter needs `->set(..., 0)` in
`setupNewGame()` and consistent `->inc()` calls everywhere the pile changes, which is exactly
the class of bug (`globals->inc()` on an uninitialized variable) that blocked Phase 1's first
deploy. `round_card`'s `card_location`/`card_type` columns are already the single source of
truth for pile contents — querying and summing them is cheap (at most ~10 rows total across
both piles, ever) and can never drift out of sync with reality, unlike a redundant counter.
`EndConditionChecker` itself stays pure/DB-free either way (per `CLAUDE.md`'s architecture
rule) — the DB query is `ResolveRound.php`'s adapter responsibility, same pattern as
`RoundResolver`.

Use `>=`, not `===`, in `checkEnd()` — free correctness against any future weight-2 card
pushing a count from 3 straight to 5 or beyond in one round (dormant in Phase 2, since no
basic card has `counts_as_two: true`, but the check should already be correct for Phase 4
without needing to revisit this file).

**Two piles can't both reach the threshold in the same round** — each round files exactly one
review card into exactly one pile (success *or* fail, never both), so `ResolveRound` only
ever needs to check the pile it just added to, not both. Worth a code comment when
implemented, so it doesn't look like an oversight.

## 5. Judgment call: deck-exhaustion becomes an unreachable-in-practice defensive fallback, not the primary trigger

`RoundStart.php`'s existing check (`countCardsInLocation('deck') < 2` → `EndGame::class`) was
Phase 1's *only* end condition, since nothing tracked boss piles yet. Once
`EndConditionChecker` lands, it becomes the primary trigger — worth understanding precisely
why, and why the deck-exhaustion check should stay rather than be deleted:

**Proof it can't actually fire under correct basic-only play**: every round adds exactly one
card, weight 1 (no basic card has `counts_as_two`), to exactly one of two piles. For *neither*
pile to reach 5, both must be ≤ 4, so at most 8 rounds can pass without triggering the end. By
round 9, pigeonhole forces at least one pile to ≥ 5. The 12-card basic deck supports at most
11 rounds before deck-exhaustion would fire (`RoundStart`'s check trips when < 2 cards
remain). **9 < 11**, so the boss-pile trigger is mathematically guaranteed to fire first,
every single game, under basic-only play. (Weighted advanced cards, Phase 4, can only
*shorten* this bound further, never lengthen it — a weight-2 card accelerates reaching 5, it
never slows it down.)

This also retroactively explains why the 2-player and 6-player Phase 1 test games both
apparently ran the full ~11 rounds to deck-exhaustion (per `docs/loaf-remarks.md`): Phase 1
had no boss-pile check at all yet to fire early. Once Phase 2 lands, real playtest games
should end noticeably sooner — worth calling out during live verification (§9) so a
9-or-fewer-round game reads as *correct*, not as evidence something broke.

**Recommendation**: keep the deck-exhaustion check in `RoundStart.php` unchanged, as a
defensive fallback for a state that should be structurally unreachable rather than removing
it — cheap to keep, and it protects against any future variant/table-option that changes deck
composition in a way this proof doesn't cover.

## 6. `ReviewEffectResolver`

```php
final class ReviewEffectResolver
{
    /**
     * @param array{target: ?string, effect: string, amount: ?int, counts_as_two: bool} $effect
     *     One side (success or fail) of a card's review effect, from RoundCardData.
     * @param array<int, int> $reputations Map of player_id => CURRENT reputation (i.e.
     *     already reflecting this round's RoundResolver delta -- see §3.1).
     * @return array<int, int> Map of player_id => new reputation, for every player named in
     *     $effect's target group. Players NOT in the group are simply absent from the
     *     result (not included at 0 delta) -- caller only needs to touch what's returned.
     */
    public static function resolve(array $effect, array $reputations): array
    {
        if ($effect['effect'] !== 'reputation') {
            return []; // advanced-only effect types: Phase 4's job, no-op here
        }

        $targetPlayerIds = self::playersInTarget($effect['target'], $reputations);

        $result = [];
        foreach ($targetPlayerIds as $playerId) {
            $result[$playerId] = ReputationTrack::adjust($reputations[$playerId], $effect['amount']);
        }
        return $result;
    }

    /** @param array<int, int> $reputations @return int[] */
    private static function playersInTarget(string $target, array $reputations): array
    {
        return match ($target) {
            'highest_reputation' => self::extremePlayerIds($reputations, max(...)),
            'lowest_reputation' => self::extremePlayerIds($reputations, min(...)),
            'reputation_positive' => array_keys(array_filter($reputations, fn($rep) => $rep >= 0)),
            'reputation_negative' => array_keys(array_filter($reputations, fn($rep) => $rep < 0)),
        };
    }
    // ...
}
```

Notes on this shape:
- Deliberately returns "player → new reputation" (post-`ReputationTrack::adjust` clamping),
  not a raw delta — matches how the caller (`ResolveRound.php`) will immediately `DbQuery` an
  UPDATE and notify with the new value, same pattern already used for `RoundResolver`'s
  output. Keeps the clamp-to-[-10,+10] logic inside the pure Core layer where it's tested,
  not duplicated in the adapter.
- The early return for non-`'reputation'` effects (including `'none'`) is the entire Phase
  2 handling of every advanced effect type — deliberately a no-op stub, not an error, so
  Phase 4 only has to add cases to this `match`/method, not restructure the caller.
- `$effect['target']` is nullable in the data (`null` for advanced all-players effects) —
  Phase 2 never reaches that branch since it only ever calls this with basic cards'
  effects, all of which have a non-null target, but worth a defensive
  `InvalidArgumentException` if `target` is null and `effect === 'reputation'`, since that
  combination should never occur in real data and silently returning `[]` would hide a bug
  in `RoundCardData` rather than surface it (mirrors `RoundResolver`'s existing
  fail-fast-on-impossible-input style).

## 7. Wiring into `ResolveRound.php`

Rulebook step ordering (§3) maps directly onto method order. Sketch of the extended
`onEnteringState()`, building on what's already there (existing `RoundResolver`/reputation
application/discard/boss-pile-filing code stays as-is, only the tail changes):

```php
// ...(existing RoundResolver + discard + moveCard-to-boss-pile code, unchanged)...

$reviewEffect = RoundCardData::TYPES[$reviewCardType]['review'][$result->success ? 'success' : 'fail'];
$currentReputations = /* re-query player_reputation for all players -- must reflect this
                          round's RoundResolver delta already applied above, per §3.1 */;
$effectResult = ReviewEffectResolver::resolve($reviewEffect, $currentReputations);

foreach ($effectResult as $playerId => $newReputation) {
    $this->game->DbQuery("UPDATE `player` SET `player_reputation` = $newReputation WHERE `player_id` = $playerId");
    $this->game->bga->notify->all('reputationChanged', /* reuse existing notif type */, [
        'player_id' => $playerId,
        'player_name' => $this->game->getPlayerNameById($playerId),
        'reputation' => $newReputation,
        // consider a distinct message/flag here so the client log can distinguish "round
        // result" reputation changes from "review effect" ones -- see §8.
    ]);
}

$pileCardTypes = /* query round_card for card_type WHERE card_location = $bossPile */;
$endTrigger = EndConditionChecker::checkEnd(
    $bossPile === 'review_happy' ? EndConditionChecker::weightedCount($pileCardTypes, 'success') : /* current happy weight, unchanged this round */,
    $bossPile === 'review_angry' ? EndConditionChecker::weightedCount($pileCardTypes, 'fail') : /* current angry weight, unchanged this round */,
);

return $endTrigger !== null ? EndGame::class : RoundStart::class;
```

The exact shape of "current weight of the *other* pile, unchanged this round" needs a real
helper (query + `weightedCount()` against whichever pile wasn't just touched, or simpler:
always recompute both piles' weights fresh each time — at most ~10 rows combined, not worth
optimizing away). Left as pseudocode above deliberately; nail the exact query shape during
implementation rather than over-specifying it here.

**`EndGame.php` needs no changes.** It doesn't need to know *why* the game ended (which boss)
for anything Phase 2 does — that's Phase 3's `ScoringCalculator` concern, and per §4's
"no redundant state" principle, Phase 3 can re-derive "which pile has ≥5" directly from
`round_card` at `EndGame` time, same as `EndConditionChecker` does here. No new global needed
to carry this information forward.

## 8. Client changes

Minimal, consistent with Phase 1's "functional, not pretty" scope (real polish is still
Phase 5):

- If `ReviewEffectResolver`'s reputation-change notification reuses the existing
  `reputationChanged` notif type unchanged, **no JS changes are required at all** — the
  existing `notif_reputationChanged` handler (added in Phase 1) already updates the
  reputation span for any player id it's given, regardless of *why* the change happened.
- Worth considering (not required): a distinct notification message so the game log reads
  clearly (e.g. "${player_name} moves ${delta} from the review effect" vs. the existing
  "...moves ${delta} on the reputation track" for round-result changes) — purely a
  `clienttranslate()` string choice on the PHP side, no new JS wiring either way.
- The boss-pile counter added this session (`docs/loaf-remarks.md`'s Phase 1 entry) already
  increments correctly on every `roundResolved` notification — no changes needed there, since
  weighted counting doesn't change *whether* a card was filed, only how it's later counted
  toward 5.

## 9. Testing plan (PHPUnit, DB-free — same discipline as `RoundResolverTest`)

New files: `tests/Core/ReviewEffectResolverTest.php`, `tests/Core/EndConditionCheckerTest.php`.

**`ReviewEffectResolverTest`**:
- One test per target type (`highest_reputation`, `lowest_reputation`, `reputation_positive`,
  `reputation_negative`) with a clear affected/unaffected player split.
- Tie case for `highest_reputation`/`lowest_reputation` — multiple players at the extreme,
  confirm *all* get the effect (mirrors `RoundResolverTest`'s existing tie-handling tests).
- Empty target group (e.g. `reputation_negative` when everyone's ≥ 0) → empty result array,
  not an error.
- Reputation clamping at the effect stage — a player already at +10/-10 whose effect would
  push them further stays clamped (exercises `ReputationTrack::adjust` through this new call
  site, even though `ReputationTrack` itself is already fully tested elsewhere).
- Non-`'reputation'` effect type (e.g. an advanced card's `effect: 'discard_choice'` fed in
  directly from `RoundCardData::TYPES['advanced_01']`) → empty result, confirms the Phase
  4 no-op stub behaves correctly today.

**`EndConditionCheckerTest`**:
- `weightedCount()`: all-weight-1 basic cards sums to plain count; a synthetic
  `counts_as_two: true` case sums correctly (even though unreachable via real basic-only
  data, the class must be correct for it now per §2's "build weight-aware today" decision).
- `checkEnd()`: below threshold on both → `null`; exactly at 5 → correct side; above 5 (weight
  jump) → still correctly triggers, confirming the `>=` choice from §4.
- A concrete worked example matching §5's pigeonhole proof: simulate 9 rounds' worth of
  alternating-ish pile assignments and confirm `checkEnd()` fires by round 9 at the latest
  under any distribution — turns the proof in §5 into an executable regression test, not just
  prose.

## 10. Live verification plan (Studio)

Same discipline as Phase 1's Verification section (`loaf-phase1-plan.md`) — nothing here is
exercisable locally beyond PHPUnit, since `RoundCardData` lookups inside a live `ResolveRound`
still ultimately run inside the unvendored BGA framework:

1. `vendor/bin/phpunit` clean, including the two new test files above.
2. Deploy and play at least one full game through to a boss-triggered end (not
   deck-exhaustion) — confirm this actually happens well before round 9-11, per §5's proof.
   If a game somehow *does* reach deck-exhaustion, that's a signal `EndConditionChecker`
   isn't firing correctly, not evidence the proof was wrong — investigate immediately rather
   than shrug it off as a rare edge case.
3. Watch for the game to specifically hit `reputation_positive`/`reputation_negative` review
   effects at least once each across a couple of games (both require existing spread in
   reputation values to observe — `highest_reputation`/`lowest_reputation` will fire almost
   every game since they're always non-empty, but the "positive"/"negative" pair need
   real variance to exercise both branches, including the empty-group no-op case).
4. Confirm a tie-for-highest or tie-for-lowest reputation case fires the effect for *all*
   tied players, not just one (mirrors the existing `RoundResolverTest` tie precedent, but
   this is the live-game version of that check).
5. Confirm the boss-pile counter (already built) visibly reaches 5 in one column right as the
   game ends, and that the other column's count makes sense (≤4, consistent with the round
   history).
6. Update `docs/loaf-remarks.md` with a "Phase 2 live verification" entry once done, same
   pattern as Phase 1's — including whichever of `getCollectionFromDb`/`getObjectListFromDb`
   choices get made for the new `ResolveRound.php` queries, and anything Studio reveals that
   contradicts this plan's assumptions.

## 11. Suggested implementation order

1. `EndConditionChecker` + `EndConditionCheckerTest` (no dependency on `ReviewEffectResolver`,
   can be built and fully tested first).
2. `ReviewEffectResolver` + `ReviewEffectResolverTest`.
3. Wire both into `ResolveRound.php` per §7 — this is the only step that touches live/BGA
   code, keeping the risky part small and last, same shape as Phase 1's
   "Core classes first, thin adapter last" approach.
4. Optional client notification-message tweak (§8) — skippable without blocking anything else.
5. Deploy, live-verify per §10, update `docs/loaf-remarks.md`.
