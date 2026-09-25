# Seed persistence + replay harness for L'Oaf

**Status (2026-08-24): designed, deliberately NOT implemented.** For the immediate need
("reproduce a bug found in live playtesting"), BGA's own built-in per-table notification
log already records everything that actually happened (which review/order card came up,
what each player played, resulting reputation deltas) — no seed reconstruction needed to
debug an *already-observed* round, since the facts are just sitting in the log already.
A much lighter-weight `DEBUG_LOG_ROUND_TRANSCRIPT` code toggle was implemented instead (see
`docs/loaf-remarks.md`'s "Round transcript trace toggle" entry) — one `trace()` line per
round, copy-pasteable into a debugging session, with none of the production risk below.

This plan is kept for later, in case either of these actually comes up:
- Predicting/reproducing rounds that **haven't been drawn yet** on a specific live table
  (the log can't help with that — it only records what already happened).
- A future bot-balance simulator (à la sibling project Gelati's `tools/simulate.php`) that
  needs to generate many hypothetical games from scratch.

If neither of those materializes, this plan can eventually be deleted rather than acted on
— it's a "build if needed," not a backlog commitment.

## Context

L'Oaf currently has no way to reproduce a bug found during live playtesting on BGA
Studio. Every real table's round-card deck order comes from `Game.php`'s
`$this->roundCards->shuffle('deck')` (a BGA `Deck` component call), which almost
certainly reduces to a DB-level `ORDER BY RAND()` under the hood — there is no seed
hook into it from PHP. Once shuffled, the rest of the game (which review/order card
appears each round) is a fully deterministic draw off the top of that one shuffle
(`States/RoundStart.php`). The two "zombie" (inactive-player) fallbacks
(`States/PlayCards.php`, `States/ResolveAdvancedEffect.php`) are the only other
randomness in the game, both going through the framework's own
`getRandomZombieChoice()`.

Goal: make every one of these three randomness points derive from a single seed that
is (a) generated once per real game, (b) persisted so it can be recovered after the
fact, and (c) reproducible outside BGA entirely, so a developer who hits a bug in a
live playtest can regenerate the exact same deck order (and zombie draws) locally,
under a debugger, without touching the live DB. This directly extends the existing
"placement/scoring rules live in pure, DB-free classes" architecture rule in
`CLAUDE.md` — the deck-order computation itself becomes one more pure, DB-free,
PHPUnit-tested function.

Since BGA's own `Deck::shuffle()` can't be seeded, this requires computing the deck
order ourselves and writing it into the `Deck` component via explicit `moveCard()`
calls, keyed off each card's `type` string (never off row order or an assumed
`location_arg` = array-index mapping) — matching a lesson already learned and
recorded in this exact repo (`docs/bga-template-upstream-notes.md`'s "Deck row
ordering is not trusted" entry, also applied in `RoundStart.php:40-45`). Every round
card is created with `nbr => 1`, so keying off `type` is unambiguous.

## Design

### 1. `modules/php/LoafRandom.php` (new, sibling of `Game.php`, NOT under `Core/`)

Mirrors the sibling project's `GelatiRandom` pattern. Kept out of `Core/`
deliberately: `mt_srand()` mutates the single global PRNG stream shared by
`rand()`/`mt_rand()`/`shuffle()`/`array_rand()` (unified since PHP 7.1) — a real
process-wide side effect, unlike every existing `Core/` class. Keeping it out
preserves "this is in `Core/`" as a reliable "zero surprising side effects" signal
for the other 9 classes there.

```php
namespace Bga\Games\loaf;

final class LoafRandom
{
    public function __construct(private readonly ?int $seed = null)
    {
        if ($this->seed !== null) {
            mt_srand($this->seed);
        }
    }

    /** Fisher-Yates, in place. */
    public function shuffle(array &$items): void { /* mirrors GelatiRandom::shuffle() */ }

    public function pick(array $items): mixed { return $items[array_rand($items)]; }

    public function intBetween(int $min, int $max): int { return mt_rand($min, $max); }

    /** Deterministically reseeds for one independent draw, keyed by $key -- needed because
     *  BGA reconstructs Game fresh every request, so mt_rand()'s in-process sequence position
     *  can't be relied on across two separate draws (e.g. the one-time deck shuffle at setup
     *  vs. a zombie draw requested weeks later). */
    public function reseed(string $key): void
    {
        mt_srand(crc32($this->seed . ':' . $key));
    }
}
```

Test: `tests/LoafRandomTest.php` (top-level, sibling to `tests/Core/` — `phpunit.xml.dist`
already scans the whole `tests` tree). Cover: same seed → identical `shuffle()`/`pick()`
sequence across two instances; `shuffle()` produces a permutation; `reseed()` with the
same seed+key → same `pick()` result across separate instances; different keys usually
differ.

### 2. `modules/php/Core/DeckShuffler.php` (new, pure)

Split into two functions so the actual risk — a dropped, duplicated, or off-by-one
card in the deck — is asserted by PHPUnit against plain arrays, not left to a manual
post-deploy check alone (the `Deck` stub in `tests/stubs/BgaFrameworkStubs.php` is a
non-stateful no-op, so nothing touching it directly is unit-testable today).

```php
namespace Bga\Games\loaf\Core;

use Bga\Games\loaf\LoafRandom;

/** Note: unlike this file's Core/ siblings, calling these functions has the side effect of
 *  reseeding the process's global PRNG (via the LoafRandom it constructs internally) --
 *  still DB-free/PHPUnit-testable, just not side-effect-free in the strict sense. */
final class DeckShuffler
{
    /** @param string[] $cardTypeKeys @return string[] permuted copy, index 0 = first drawn */
    public static function shuffledOrder(array $cardTypeKeys, int $seed): array
    {
        $order = $cardTypeKeys;
        (new LoafRandom($seed))->shuffle($order);
        return $order;
    }

    /**
     * Pure "what to tell the Deck component" step, kept separate from Game::setupNewGame()
     * so PHPUnit -- not a live table -- catches a dropped/duplicated/mismatched card.
     *
     * @param array<int, array{id:int, type:string}> $currentDeckCards shape matches Deck::getCardsInLocation()
     * @param string[] $shuffledOrder output of shuffledOrder()
     * @return array<int, array{cardId:int, locationArg:int}>
     * @throws \LogicException on count mismatch, unknown type, or duplicate type -- fail loudly
     *         at setup rather than silently deal an incomplete/duplicated deck.
     */
    public static function moveInstructions(array $currentDeckCards, array $shuffledOrder): array
    {
        // count check, then array_flip($shuffledOrder) for target positions, then one pass
        // over $currentDeckCards checking unknown-type/duplicate-type before emitting moves.
    }
}
```

Test: `tests/Core/DeckShufflerTest.php`. `shuffledOrder()`: determinism, permutation
property, different seeds usually differ. `moveInstructions()`: happy path yields a
`0..N-1` permutation of `locationArg` consistent with `shuffledOrder`'s positions;
count-mismatch/unknown-type/duplicate-type each throw `\LogicException`.

### 3. `modules/php/constants.inc.php`

```php
// Persisted once at setupNewGame() so a live table's exact round-card order (and zombie
// draws) can be regenerated later outside BGA entirely -- see tools/replay.php and
// docs/bga-studio-reference.md's seeded-replay entry.
const GLOBAL_GAME_SEED = 'game_seed';
// Persisted alongside GLOBAL_GAME_SEED purely so a later debug_getGameSeed() call doesn't
// need to introduce this repo's first-ever use of the (locally unverified) tableOptions API
// just to know which 12/24-card universe a seed's shuffledOrder() should be computed against.
const GLOBAL_ADVANCED_CARDS_ENABLED = 'advanced_cards_enabled';
```

(Deviates from a plan-review suggestion to read this back via `$this->bga->tableOptions`
at debug time — grepped both this repo and Gelati/the template and found zero existing
usage of `tableOptions` anywhere, so it would be a first-use of an unverified API for a
debug convenience that the already-proven `globals` mechanism trivially covers instead.)

### 4. `modules/php/Game.php::setupNewGame()` changes

Replace the current `$this->roundCards->shuffle('deck');` line (today at Game.php:348)
with:

```php
$seed = random_int(1, 2_000_000_000); // capped well under 32-bit signed range as a cheap
                                       // hedge against globals' underlying storage width,
                                       // which (like the rest of $this->bga->globals) has
                                       // no local verification against real Studio behavior
$this->bga->globals->set(GLOBAL_GAME_SEED, $seed);
$this->bga->globals->set(GLOBAL_ADVANCED_CARDS_ENABLED, $advanced_cards_enabled);
$this->trace("L'Oaf: game_seed=$seed advanced_cards=" . ($advanced_cards_enabled ? 1 : 0));

$shuffledOrder = DeckShuffler::shuffledOrder(array_keys($card_types), $seed);
$deckCards = $this->roundCards->getCardsInLocation('deck');
foreach (DeckShuffler::moveInstructions($deckCards, $shuffledOrder) as $move) {
    $this->roundCards->moveCard($move['cardId'], 'deck', $move['locationArg']);
}
```

Add `use Bga\Games\loaf\Core\DeckShuffler;` to the existing `use` block (same pattern as
the other `Core\*` imports already there).

Also add, near the existing `debug_*` methods (~Game.php:368+):

```php
/** Reads back what setupNewGame() persisted, for a table already in progress -- pair with
 *  tools/replay.php --seed=<value> --advanced-cards=<flag> to regenerate its round order. */
public function debug_getGameSeed(): void
{
    $seed = $this->bga->globals->get(GLOBAL_GAME_SEED);
    $advancedCards = $this->bga->globals->get(GLOBAL_ADVANCED_CARDS_ENABLED);
    $this->trace("L'Oaf: game_seed=$seed advanced_cards=$advancedCards");
}

/** Only entry point for zombie-fallback randomness -- keeps every random draw in a real
 *  game derived from the one persisted seed, so it stays reproducible under tools/replay.php. */
public function randomZombiePick(array $choices, string $key): mixed
{
    $random = new LoafRandom((int) $this->bga->globals->get(GLOBAL_GAME_SEED));
    $random->reseed($key);
    return $random->pick($choices);
}
```

### 5. Zombie call sites

`States/PlayCards.php::zombie()` (currently `$this->getRandomZombieChoice($this->getHandValues($playerId))`):

```php
function zombie(int $playerId) {
    $round = (int) $this->game->bga->globals->get(GLOBAL_CURRENT_ROUND);
    $zombieChoice = $this->game->randomZombiePick(
        $this->getHandValues($playerId),
        "play_cards:round=$round:player=$playerId"
    );
    return $this->actCommitCard($zombieChoice, $playerId);
}
```

`States/ResolveAdvancedEffect.php::zombie()` (currently
`$this->getRandomZombieChoice($this->eligibleValuesFor($playerId, $reviewEffect))`):

```php
function zombie(int $playerId) {
    $reviewEffect = $this->getReviewEffect();
    $round = (int) $this->game->bga->globals->get(GLOBAL_CURRENT_ROUND);
    $choice = $this->game->randomZombiePick(
        $this->eligibleValuesFor($playerId, $reviewEffect),
        "resolve_advanced_effect:round=$round:player=$playerId"
    );
    return $reviewEffect['effect'] === 'discard_choice'
        ? $this->actDiscardChoice($choice, $playerId)
        : $this->actSwapDiscard($choice, $playerId);
}
```

Round+player is sufficient to make each key unique: a player can zombie out of
`PlayCards` at most once per round, and at most once per `ResolveAdvancedEffect` entry
per round (only one review effect resolves per round) — confirmed from
`States/RoundStart.php`'s one-review-card-per-round flow.

### 6. `tools/replay.php` (new CLI, v1 scope)

Scope: reproduce the round-by-round **deck order** (which review/order card pairs up
each round) from a seed — no BGA/DB dependency, `require __DIR__.'/../vendor/autoload.php'`
only. A fuller "replay a transcript of what each player actually played and recompute
scores via `RoundResolver`/`ScoringCalculator`" mode is deliberately deferred (documented
as a v2 follow-up in the docs update below) — parsing BGA's log format and matching ~9
`Core` classes' differing input shapes is a separate, nontrivial problem, and the stated
goal (regenerate the same random game state) is already fully met without it.

```
php tools/replay.php --seed=<int> --advanced-cards=0|1
```

Both flags required (no DB access to look them up). Builds the same `$cardTypeKeys` list
`setupNewGame()` builds (`Core\RoundCardData::TYPES` filtered by the `advanced` flag),
calls `Core\DeckShuffler::shuffledOrder()`, then prints one line per round using the same
pairing `RoundStart.php` uses:

- Round R's review card = `shuffledOrder[R-1]`
- Round R's order-target card = `shuffledOrder[R]` (the next entry, not yet drawn)
- Last playable round = `count(shuffledOrder) - 1` (matches `RoundStart`'s
  `< 2 cards left` end check — e.g. 12 basic-only cards → 11 playable rounds)

For each round, print the review card's success/fail effect and the order card's
`per_player_average`, pulled from `Core\RoundCardData::TYPES`.

**Where a v2 transcript would come from**: L'Oaf has no custom action/move-log table, but
it doesn't need one — BGA automatically keeps a full, replayable per-table log of every
`notify->all()`/`notify->player()` call. `States/ResolveRound.php:51-57` already broadcasts
every round's played values to everyone via `cardPlayedRevealed` (`notify->all`, includes
`'value' => $value`), so the exact per-round, per-player played values a developer needs
for a v2 transcript are already sitting in BGA's own replay/log view for that table — no
new logging code required. The one exception is the two advanced-effect discard/swap
choices, sent via `notify->player()` (`ResolveRound.php:300-304`, private to the acting
player) rather than `notify->all()` — visible only to that player's own client/log, which
is sufficient for self-testing (the developer is a player in their own live-test session).
v2 itself (parsing a hand-transcribed JSON of these values and running each round through
`Core\RoundResolver`/`Core\ScoringCalculator`/etc.) is still deferred past v1 — this note
just confirms the data source is real and sufficient, not a blocker, whenever v2 is built.

Already excluded from the Studio SFTP upload — `.vscode/sftp.json`'s ignore list already
contains `tools` (per `CLAUDE.md`'s standing rule), no deploy-exclusion change needed.

### 7. Docs

- `docs/bga-studio-reference.md`: new subsection (near the existing Deck-component
  coverage gap flagged in `docs/bga-template-upstream-notes.md:409-411`) covering: (a)
  `Deck::shuffle()` has no seed hook (presumed DB-level `ORDER BY RAND()`), so seeded
  reproducibility requires computing the order yourself and writing it via `moveCard()`,
  keyed by an app-controlled field, never row order/array-index; (b) the general
  "persist a seed via `$this->bga->globals` + trace-log it at creation" pattern as a
  portable BGA Studio debugging technique.
- `docs/loaf-remarks.md`: the 3 randomness call sites now covered, the `Core/`-vs-sibling
  placement rationale for `LoafRandom`, the `reseed()` keying scheme, the seed-magnitude
  cap and `tableOptions`-avoidance rationale, and the deferred v2 transcript-replay idea.
- Mark the "seeded RNG needs a persisted seed + replay harness" entry already added to
  `docs/bga-template-upstream-notes.md` as ported/superseded by this work (Loaf now has
  the concrete implementation the entry called for).

## Verification

- `vendor/bin/phpunit` — new `tests/LoafRandomTest.php` and `tests/Core/DeckShufflerTest.php`
  pass alongside the existing suite.
- `vendor/bin/phpstan analyse` — clean against the new files (mixed-typed globals returns,
  `\LogicException` throws).
- `php tools/replay.php --seed=12345 --advanced-cards=0` runs standalone with zero DB;
  sanity-check by hand: 11 rounds printed, 12 distinct review cards, no repeats.
- **Manual Studio check (the one thing that can't be verified locally, since the `Deck`
  stub has no real state to assert against)**: after deploying, create one fresh table,
  click Debug → `debug_getGameSeed()` to get the seed + advanced-cards flag. Then:
  1. Play/watch a few rounds, noting each round's revealed review card and order target
     from the live client.
  2. Run `tools/replay.php` locally with that seed/flag and diff its first few rounds
     against what the live table actually showed.
  3. Query the live `round_card` table (Studio's DB browser) ordered by `card_location_arg`
     for `card_location='deck'` right after creation: expect exactly 12 (or 24) rows, all
     distinct `card_type`s, a contiguous `0..N-1` `card_location_arg` range.
  4. Create a second table with a different seed and confirm its order differs from the
     first (guards against the seed generation itself being accidentally constant/reused).

## Critical files

- `modules/php/LoafRandom.php` (new)
- `modules/php/Core/DeckShuffler.php` (new)
- `modules/php/Game.php` (setupNewGame, new debug/zombie-pick methods)
- `modules/php/States/PlayCards.php` (zombie())
- `modules/php/States/ResolveAdvancedEffect.php` (zombie())
- `modules/php/constants.inc.php`
- `tools/replay.php` (new)
- `tests/LoafRandomTest.php` (new)
- `tests/Core/DeckShufflerTest.php` (new)
- `docs/bga-studio-reference.md`, `docs/loaf-remarks.md`, `docs/bga-template-upstream-notes.md`
