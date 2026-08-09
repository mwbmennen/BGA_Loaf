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

- [ ] **Surface hidden server-side state via the game log before real UI exists.** When game
  logic resolves something with no on-screen representation yet, testers can't tell a
  correct outcome from a silently-wrong one. Don't build the real UI early just to unblock
  testing — add a plain-text `notify->all()` (translating each piece with
  `clienttranslate()`, including enum-label args, not just the message template) describing
  the hidden state. It shows up for free in BGA's standard game log panel and is trivial to
  delete once real UI lands. Where: `bga-studio-reference.md` §6, "Surfacing hidden
  server-side state before the UI exists" (added after this came up for L'Oaf's
  review-card effects — see `loaf-remarks.md`'s "Phase 2: review card effect had no visible
  representation" for the concrete example).
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
- [ ] **`getCurrentPlayerId()` throws "Not logged" in `setupNewGame()` — avoid it there, and
  never use it in `getArgs()` at all (see the next entry, which corrects/replaces this one's
  original `getArgs()` guidance).** Confirmed live: `Fatal error during loaf setup: Not
  logged`, thrown from a bare `getCurrentPlayerId()` call reached during the system-driven
  chain right after table creation — no browser has loaded the page yet, so there's no
  requesting-player session for `getCurrentPlayerId()` to read. Fix for `setupNewGame()`
  specifically: avoid `getCurrentPlayerId()` there entirely — you already have the full
  player list via `$players`/`array_keys($players)`; use `getCurrentPlayerId(true)` (nullable
  variant) only if you genuinely need to detect "is there a request context." **This entry
  originally also recommended `getCurrentPlayerId(true)` with a null fallback inside
  `getArgs()` — that was wrong** (see next entry): it stops the crash but silently breaks
  real gameplay, since `getArgs()` never has a resolvable "current player" even outside
  setup. Separately confirmed: `PlayCards::zombie()` was reusing `getArgs()`'s result and
  reading the *current session's* hand instead of the zombied player's — for
  `zombie(int $playerId)`, always pull that player's data directly via a shared private
  helper, never through `getArgs()`. Where: `bga-studio-reference.md` §5, `Error: "Fatal
  error during {game} setup: Not logged"`.
- [ ] **`MULTIPLE_ACTIVE_PLAYER` state: nobody gets action buttons / everyone stuck on
  "Waiting for other players..." — two separate required fixes.** Confirmed live
  (2026-08-08), reproduced with a real 2-player table: only one player showed as active at
  all, and even they got zero action buttons. **Cause 1**: `type:
  StateType::MULTIPLE_ACTIVE_PLAYER` in the constructor only *permits* multiple active
  players — it doesn't activate anyone. Missing an explicit `onEnteringState()` calling
  `$this->game->gamestate->setAllPlayersMultiactive();` means nobody is marked active on
  entry.
  ```php
  public function onEnteringState() {
      $this->game->gamestate->setAllPlayersMultiactive();
  }
  ```
  **Cause 2** (this is the correction to the previous entry's original `getArgs()` advice):
  `getArgs()` runs once per state entry, server-side, broadcast to everyone — there's no
  "requesting player" inside it, ever, not just during setup. Using `getCurrentPlayerId()`
  (throwing or nullable form) there to fetch "my own hand" always resolves to nothing, for
  every player, every time. The actual BGA mechanism for private per-player data is the
  `_private` key (keyed by player id) plus `_merge_private => true` (flattens each
  recipient's own entry into their top-level args **on the client only** — see the important
  caveat right below, this does *not* extend to action handlers):
  ```php
  public function getArgs(): array {
      $playerIds = array_map('intval', $this->game->getObjectListFromDb(
          'SELECT `player_id` FROM `player`',
          true
      ));

      $private = [];
      foreach ($playerIds as $playerId) {
          $private[$playerId] = ['handValues' => $this->getHandValues($playerId)];
      }

      return [
          '_private' => $private,
          '_merge_private' => true,
      ];
  }
  ```
  **Cause 3, found immediately after fixing 1+2 and actually clicking a button live**:
  `_merge_private` does NOT carry through to an `actXxx()` action handler's injected
  `array $args` — confirmed live via `Undefined array key "handValues"` thrown from inside
  `actCommitCard()` on first real button click. The framework's injected `$args` there is
  just the raw `getArgs()` return value (`_private`/`_merge_private` keys, nothing
  flattened/unwrapped for the specific acting player) — the opposite of what cause 2's fix
  (and this entry's own first draft) assumed. Fix: don't have an action handler depend on
  `$args` for anything that came from `_private` at all — drop the `array $args` parameter
  from the action method and re-derive whatever's needed directly from `$currentPlayerId`
  instead (**not** `$activePlayerId` — that's cause 4, immediately below, found on the very
  next live test after this one):
  ```php
  #[PossibleAction]
  public function actCommitCard(int $value, int $currentPlayerId) {
      if (!in_array($value, $this->getHandValues($currentPlayerId), true)) {
          throw new UserException('You do not have that work card in hand');
      }
      // ...
  }
  ```
  **Cause 4, found immediately after fixing 1–3, on the very next live click**: this entry's
  own cause-3 fix originally used `int $activePlayerId` as the "who's acting" parameter (code
  sample above already corrected). That's wrong for `MULTIPLE_ACTIVE_PLAYER` states — per
  BGA's own docs, `$activePlayerId` is explicitly "not necessarily the one triggering the
  action" and is only meaningful on single-`ACTIVE_PLAYER` states, where there's exactly one
  active player and no ambiguity. On `MULTIPLE_ACTIVE_PLAYER`, it silently resolved to `0` —
  confirmed live via a stack trace literally showing `actCommitCard(3, 0, ...)`, `0` never
  being a real BGA player id — so the hand check, the DB update, the notification, and
  `setPlayerNonMultiactive()` were all silently targeting a nonexistent player the whole
  time. Use `$currentPlayerId` (documented as "the player who triggered the action")
  instead — it's what an action handler almost always actually wants, regardless of state
  type. General rule: never call `getCurrentPlayerId()` inside any state's `getArgs()`; treat
  `_private`/`_merge_private` as display-only data for the client, never something an action
  handler can trust arrived via its own `$args`; on a `MULTIPLE_ACTIVE_PLAYER` action handler,
  always use `$currentPlayerId`, never `$activePlayerId`. Where: `bga-studio-reference.md` §5,
  `Error: MULTIPLE_ACTIVE_PLAYER state shows "Waiting for other players..." for everyone /
  nobody gets action buttons` and the entry right after it, `Error: $activePlayerId magic
  parameter is wrong/0 inside a MULTIPLE_ACTIVE_PLAYER action handler` — **this template-notes
  entry took four live round-trips to fully correct**, each fix looking complete until the
  very next live test surfaced the next one; worth flagging prominently when porting so
  nobody trusts an earlier-numbered cause's code sample in isolation. Also worth porting as
  its own standalone lesson, independent of this specific bug chain: **`$activePlayerId` vs
  `$currentPlayerId` is a sharp-edged, easy-to-confuse-by-name API pair, and only one of them
  is safe to use inside a `MULTIPLE_ACTIVE_PLAYER` action handler.**
- [ ] **`in_array($value, $dbResults, true)` (strict) silently rejects every valid move,
  because DB values come back as strings.** Confirmed live (2026-08-08), found immediately
  after fixing the `MULTIPLE_ACTIVE_PLAYER` entry above — first real button click threw a
  "you don't have that" `UserException` for a card that was, in fact, in hand.
  `getObjectListFromDb()`/`getCollectionFromDb()` return raw DB values as strings even for
  numeric columns; `in_array($intValue, $stringArray, true)` is always false (`3 !== "3"`),
  so any strict-comparison validation against unfiltered DB output rejects every input,
  correct or not. Fix: `array_map('intval', ...)` immediately after fetching, before any
  strict comparison — or drop `true` for loose comparison, though casting is more robust
  since it keeps everything downstream (sums, notifications) working with real ints instead
  of numeric strings. Generalizes beyond `in_array`: any strict comparison
  (`===`, `in_array(..., true)`, `array_search(..., true)`, match arms) against unfiltered
  DB output has this risk. Where: `bga-studio-reference.md` §5, `Error: UserException
  ("Invalid move"-style message) thrown on a move that's clearly valid, specifically when
  validating against in_array(..., true)`.
- [ ] **Deck component: `getCardsInLocation()` results are keyed-by-card-id PHP arrays, which
  become JS objects, not arrays — `.length` reads `undefined` client-side.** Confirmed live
  (2026-08-08): a boss-pile card counter, seeded from `getCardsInLocation('review_happy')` in
  the initial game-data payload, read `undefined` on page refresh. PHP doesn't distinguish
  sequential vs. associative arrays (`count()`/`foreach`/`usort()` all work identically on
  both — this is also why the existing "don't trust `getCardsInLocation`'s row order" entry
  above never caught this), but JSON serialization does: a non-sequential PHP array becomes a
  JS object, and only real JS arrays have `.length`. Fix: use `Object.keys(...).length`
  client-side instead of `.length` — works on both shapes, so it's safe without needing to
  know which one a given PHP endpoint actually returns; use `Object.values(...)`/
  `Object.entries(...)` instead of array methods (`.map()`/`.forEach()`) if you need the
  actual card data, not just a count. Where: `bga-studio-reference.md` §5, `Error:
  TypeError/undefined client-side after reading .length on a Deck component's
  getCardsInLocation() result`. Note: the existing "needs generalizing" Deck-component entry
  below (row ordering) flagged that the Deck component has zero coverage in
  `bga-studio-reference.md` yet — this entry and that one should probably become a single
  proper Deck-component subsection when ported, rather than two scattered error entries.
- [ ] **`MULTIPLE_ACTIVE_PLAYER`: `onEnteringState`'s `isCurrentPlayerActive` can be stale on
  a live push — use `onPlayerActivationChange` for anything activation-dependent.** Confirmed
  live (2026-08-08): after a page-refresh-required "buttons missing" bug report, added
  temporary `console.log` diagnostics and reproduced it twice (once in a normal tab, once in
  clean Incognito with zero browser extensions, ruling that out) — both times,
  `isCurrentPlayerActive: false` was logged inside `onEnteringState` for a player who
  genuinely had a live, non-empty hand. This is a documented BGA framework race, not a
  game-specific bug: cross-checked against `forum.boardgamearena.com/viewtopic.php?t=14059`,
  including a BGA admin's own explanation that player activation for a
  `MULTIPLE_ACTIVE_PLAYER` state is set server-side *during* the state's own PHP
  `onEnteringState()` (`setAllPlayersMultiactive()`), so there's no guarantee the client's
  activation status has settled by the exact instant its own JS `onEnteringState` fires from
  a live push — only a full page reload reliably re-derives it from scratch. Fix: never gate
  activation-dependent UI (action buttons, private-data rendering) on `onEnteringState`'s
  `isCurrentPlayerActive` parameter for a `MULTIPLE_ACTIVE_PLAYER` state — do it in
  `onPlayerActivationChange` instead, a separate lifecycle hook confirmed live to fire as its
  own distinct event on every single state entry (not just later changes), which is exactly
  the framework's own recommended pattern for this (the classic/old framework's docs call the
  equivalent hook `onUpdateActionButtons` and explicitly recommend it over `onEnteringState`
  for the same reason). Where: `bga-studio-reference.md` §5, `Error: MULTIPLE_ACTIVE_PLAYER
  state's onEnteringState(args, isCurrentPlayerActive) gets isCurrentPlayerActive: false for a
  player who's genuinely active` (full before/after code). Note this is a *different*, later
  bug than the earlier `MULTIPLE_ACTIVE_PLAYER` entries above (missing
  `setAllPlayersMultiactive()`, `_private`/`_merge_private`, `$activePlayerId` vs
  `$currentPlayerId`) — all of those were server-side/PHP; this one is purely client-side JS
  timing, and only surfaced once real gameplay looped through multiple rounds rather than a
  single round of manual testing.
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
