# Template upstream notes

Running log of things learned while building **L'Oaf** that are generic to *any* BGA Studio
game, not specific to L'Oaf's rules — kept separate from `loaf-remarks.md`/
`loaf-phase1-plan.md`/`loaf-implementation-plan.md`, which are L'Oaf-specific design/judgment
records.

**Purpose**: at the end of this project (or periodically along the way), work through this
list and port each entry into
[`bga-game-template`](https://github.com/mwbmennen/bga-game-template) — most belong in that
repo's `docs/bga-studio-reference.md`, a couple are big/new enough to warrant their own file.
Check an entry off (`[x]`) once it's been ported.

This is a *living* document — add a new entry any time work here (or discussion) surfaces
something every future BGA game would benefit from, per `CLAUDE.md`'s standing "Template
notes" rule. Don't wait until the end of the project to write the entry down, only to act on
it.

## How to use this doc

For each entry below:
1. Read the "Where in this repo" pointer for the full write-up/exact wording.
2. Decide: copy verbatim (already generically-worded) or generalize first (currently phrased
   around L'Oaf specifics and needs rewriting before it's portable).
3. Apply it to the template repo.
4. Check it off here.

---

## Ready to port verbatim

These are already generically worded (no L'Oaf-specific nouns) and live in this repo's own
`docs/bga-studio-reference.md` — just copy the section across.

- [ ] **`console.log` cleanup before shipping.** Comment out (don't delete) debug
  `console.log` calls before considering a feature/phase done, plus a `grep -rn
  "console\.log" modules/js/` catch-all and a checklist item. Where: `bga-studio-reference.md`
  §6 (right after the JS DevTools example) and the §7 pre-test checklist.
- [ ] **Wrap every user-facing string in translation helpers from day one.** Standing rule:
  never write a bare English string in game code; BGA's translator platform can't retrofit
  strings it never saw wrapped. Where: `bga-studio-reference.md`, "Wrap every user-facing
  string in BGA's translation functions, from day one".
- [ ] **Never add a type hint to an override of an untyped BGA framework hook method.**
  Confirmed live: fatal on first table creation —
  ```
  Fatal error: Declaration of Bga\Games\{gamename}\Game::setupNewGame(array $players, array $options = [])
  must be compatible with Bga\GameFramework\Table::setupNewGame($players, $options = [])
  ```
  PHP's contravariance rule treats an untyped parent parameter as implicitly `mixed`, and a
  narrower child type is an incompatible override, not a safe addition. Nothing local (no
  vendored framework, only a hand-maintained stub) can catch this before a live deploy.
  Applies to any framework hook override (`setupNewGame`, `upgradeTableDb`, `zombieTurn`,
  etc.), not just this one method. Fix — match the parent signature exactly, no type hints,
  and cast/narrow inside the method body instead:
  ```php
  // Wrong — narrows an untyped parent parameter:
  protected function setupNewGame(array $players, array $options = [])

  // Correct — matches Table::setupNewGame($players, $options = []):
  protected function setupNewGame($players, $options = [])
  ```
  Where: `bga-studio-reference.md` §5, `Error: "Declaration of Game::setupNewGame(...) must be
  compatible with Table::setupNewGame(...)"`.
- [ ] **`globals->inc()` needs the variable pre-initialized; `globals->get()` doesn't.**
  Confirmed live: `Error when incrementing a global variable: current_round is not a numeric
  value` during `createGame`, from a state calling `->inc()` on a global that
  `setupNewGame()` never `->set()` first. `get($key, $default)` gracefully falls back to
  `$default` when unset; `inc()` has no equivalent. Fix:
  ```php
  protected function setupNewGame($players, $options = [])
  {
      // ...
      $this->bga->globals->set(GLOBAL_CURRENT_ROUND, 0);
      // ...
  }
  ```
  ```php
  // Later, in a state's onEnteringState():
  $currentRound = (int) $this->game->bga->globals->inc(GLOBAL_CURRENT_ROUND, 1);
  ```
  Do this for every global any state ever `->inc()`s. Where: `bga-studio-reference.md` §5,
  `Error: "Error when incrementing a global variable: {key} is not a numeric value"`.
- [ ] **`getCurrentPlayerId()` throws "Not logged" in `setupNewGame()`, `getArgs()`, and
  `zombie()` — use the nullable form.** Confirmed live: `Fatal error during loaf setup: Not
  logged`, thrown from `PlayCards::getArgs()`'s bare `getCurrentPlayerId()` call, triggered by
  `setupNewGame()` auto-transitioning straight into a `MULTIPLE_ACTIVE_PLAYER` state during
  table creation — no browser has loaded the page yet, so there's no requesting-player session
  for `getCurrentPlayerId()` to read. Same root cause would hit a state's `zombie()` handler
  (also system-driven, no session) if it reused `getArgs()`'s result — which this repo's
  `PlayCards::zombie()` was already doing incorrectly (used the *current session's* hand
  instead of the zombied player's hand; only masked because it had never been exercised live).
  Fix: `getCurrentPlayerId(true)` (nullable variant, returns `null` instead of throwing) in
  `getArgs()`, with a null-safe fallback (empty/default args — real players get their own
  `getArgs()` call once connected):
  ```php
  public function getArgs(): array {
      $currentPlayerId = $this->game->getCurrentPlayerId(true);

      return [
          'handValues' => $currentPlayerId === null ? [] : $this->getHandValues((int) $currentPlayerId),
      ];
  }
  ```
  For `zombie(int $playerId)`, don't call `getArgs()` at all — pull the given `$playerId`'s
  data directly via a shared private helper instead of relying on a session that doesn't
  exist there either:
  ```php
  function zombie(int $playerId) {
      $args = ['handValues' => $this->getHandValues($playerId)];
      $zombieChoice = $this->getRandomZombieChoice($args['handValues']);
      return $this->actCommitCard($zombieChoice, $playerId, $args);
  }
  ```
  Where: `bga-studio-reference.md` §5, `Error: "Fatal error during {game} setup: Not logged"`.
- [ ] **A hung "Creating the game table..." / client-side timeout means a DB lock, not slow
  code — `Wipe database`, don't debug the code first.** Confirmed live: table creation hung
  indefinitely (eventually a client-side `Timeout exceeded`, not a PHP error) specifically
  after re-enabling a trivial ~24-row `INSERT` during bisection — far too small to
  genuinely be slow. Per BGA's own docs (independently sourced by the Gelati project too,
  `docs/gelati-remarks.md`): the entire request, including `createGame` itself, is one DB
  transaction that only commits on normal completion, so a hang means the request is
  blocked on a lock, most likely held by a stuck/zombied worker from an earlier failed
  attempt against the same dev DB (easy to accumulate during rapid iterative Studio
  debugging). Try/catch cannot help — a blocking wait isn't a catchable exception. The
  table appearing in the lobby list is *not* evidence anything committed, that's separate
  admin bookkeeping outside the transaction. Where: `bga-studio-reference.md`, "Error:
  table creation hangs forever" (§5, includes the `Wipe database` fix).

## Needs generalizing before it's portable

Real lessons, but currently written with L'Oaf's specific nouns (round cards, reputation,
etc.) — rewrite around generic examples before adding to the template.

- [ ] **Deck component: don't trust `getCardsInLocation`'s row order.** `card_location_arg`
  is the position *within* a location (e.g. for `deck`, ascending = draw order, lowest = next
  to draw) — that's the standard Deck component convention, but since it's unverified
  locally (no vendored framework), sort explicitly in PHP (`usort` by `location_arg`) rather
  than assume the DB/component returns rows in that order already. Where:
  `docs/loaf-remarks.md`, "Deck row ordering is not trusted" bullet under "Phase 1 state
  machine: judgment calls"; also came up when explaining `round_card`'s schema mid-project
  (this conversation). Not yet documented anywhere in `bga-studio-reference.md` at all — the
  Deck component has zero coverage there currently, worth a proper new subsection, not just
  this one caveat.
- [ ] **`MULTIPLE_ACTIVE_PLAYER` states: `setPlayerNonMultiactive` auto-transition pattern.**
  Per BGA's docs (cross-checked, **not yet confirmed live** — flag this explicitly when
  porting, or hold off until it's actually been seen working on Studio): deactivating the
  last remaining active player in a `MULTIPLE_ACTIVE_PLAYER` state auto-transitions to
  whatever state class was passed as the second argument. Pairs with a client-side
  `onPlayerActivationChange(args, isCurrentPlayerActive)` hook for showing a "waiting on
  other players" message once the current player has acted. Where: `States/PlayCards.php`
  (PHP side) and `modules/js/Game.js`'s `PlayCards` class (JS side) in this repo; also not
  yet in `bga-studio-reference.md`.
- [ ] **Architecture: pure/DB-free Core classes for game rules.** Keep placement/scoring/rules
  logic in plain PHP classes with zero BGA/DB dependency (this project's `modules/php/Core/`),
  unit-testable via PHPUnit, with BGA state-machine classes reduced to thin adapters that pull
  data from the DB, hand it to a Core class, and apply the result back. Side benefit: the same
  Core classes become directly reusable by a future standalone balance-simulation harness
  (run thousands of games headless, no BGA framework needed) without any extra work, *as
  long as* game-content data tables and any deck/shuffle logic are also kept out of the BGA
  `Table` subclass — see the next entry. Where: this repo's `CLAUDE.md` "Architecture"
  standing rule, `docs/loaf-implementation-plan.md` §2, and the "Why pure, DB-free matters
  beyond PHPUnit" note added there mid-project. Currently phrased around L'Oaf's specific
  Core classes (`RoundResolver`, `ReputationTrack`); generalize into a project-agnostic
  section, likely in `new-bga-project-starter-guide.md`.
- [ ] **Splitting large static game-content tables out of `Game`'s constructor.** A big
  card/tile/data array (this repo's 24-card `RoundCardData`, ~430 lines) doesn't belong
  inline in `Game.php`'s constructor — pull it into its own framework-free file/class
  (`modules/php/Core/`), assigned to the `Game` static property in one line instead. Keeps
  `Game.php` navigable and keeps the data reusable outside a live BGA table. Where: this
  session's extraction of `modules/php/Core/RoundCardData.php` out of `Game.php`. Probably
  folds into the entry above as a concrete example rather than its own template section.
- [ ] **"Framework API confidence note" as a planning-doc habit.** When writing an
  implementation plan for a new game mechanic, explicitly list which framework API
  signatures the plan relies on that are *unverified locally* (no vendored BGA framework
  anywhere — this is true for every BGA Studio project, not just this one) vs.
  cross-checked only against public docs. That list becomes the first place to check when a
  live Studio deploy throws an unexpected fatal, instead of debugging blind. Where:
  `docs/loaf-phase1-plan.md`, "Framework API confidence note" section. This is a
  process/documentation pattern, not code — could become a short checklist addition to
  `new-bga-project-starter-guide.md` ("things no local tool can verify — write them down
  before you deploy") rather than a `bga-studio-reference.md` entry.

## Open question — needs resolving before it's portable

- [ ] **PHP syntax ceiling: don't assume the newest PHP version.** Caught myself using PHP
  8.3's typed-class-constant syntax (`public const array TYPES = [...]`) in a new Core class;
  this repo's `composer.json` declares `"php": ">=8.1"`, so it was a real compatibility risk,
  caught by re-reading `composer.json` rather than by any tool. Before this becomes a
  template rule, confirm what PHP version BGA Studio's *live* servers actually run (not just
  what a given project's `composer.json` floor says) — if Studio itself caps out below 8.3,
  this is worth a standing rule ("stick to the floor in `composer.json`, don't reach for the
  newest PHP syntax just because your local PHP CLI supports it"); if Studio actually runs
  8.3+, this was just a one-off self-correction, not a systemic gotcha worth documenting.
