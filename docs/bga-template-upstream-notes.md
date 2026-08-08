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
  Confirmed live: fatal on first table creation (`setupNewGame(array $players, ...)` vs.
  the framework's untyped `setupNewGame($players, ...)`) — PHP's contravariance rule treats
  an untyped parent parameter as implicitly `mixed`, and a narrower child type is an
  incompatible override, not a safe addition. Nothing local (no vendored framework, only a
  hand-maintained stub) can catch this before a live deploy. Applies to any framework hook
  override (`setupNewGame`, `upgradeTableDb`, `zombieTurn`, etc.), not just this one method.
  Where: `bga-studio-reference.md`, "Never add type hints to a framework hook override whose
  parent parameter is untyped — PHP fatals".
- [ ] **Never centralize state-id (`ST_xxx`) constants in `constants.inc.php`.** Confirmed
  live twice, on two different code paths, ruling out a sync-gap explanation: first
  `Undefined constant "...\States\ST_PLAY_CARDS"` from a state's `__construct()` (while
  `GamestateMachine::addGameStateClasses()` is still loading the state machine inside
  `Table::setTable()`); then, after fixing that, `Undefined constant
  "...\States\GLOBAL_CURRENT_ROUND"` from `onEnteringState()` — which disproved the first
  fix's working theory that only *construction-time* references were at risk.
  **Real root cause**: `constants.inc.php` is a plain script file, not a class — it only
  becomes available if the framework explicitly `require`s it, and on this deploy that
  doesn't reliably happen before the entire initial `createGame` → `setupNewGameTable()` →
  `jumpToState()` chain runs, which covers both construction *and* first-`onEnteringState()`.
  Class files never have this problem, at any point, because PHP's autoloader loads them on
  demand — no separate `require` step. Fix: don't put anything in `constants.inc.php` at all.
  Declare state ids as same-file `const`s in each state's own file (matching the BGA tutorial
  scaffold's `EndScore.php`/`EndGame.php` convention, which never centralized its id and
  never had this bug); declare anything shared across multiple files (e.g. globals key names)
  as `public const` on the main `Game` class instead, since every state already holds a
  `Game $game` and so `Game` is guaranteed loaded. **This one is worth flagging
  prominently**: the file-structure diagram already in `bga-studio-reference.md` §2
  currently *recommends* the exact broken pattern (`constants.inc.php ← State machine
  constants (ST_xxx values)`) — that line needs correcting when this ports over, not just
  appending a caveat elsewhere. Where: `bga-studio-reference.md`, "Never rely on
  `constants.inc.php` for anything — it's a plain file, not a class, and its `require` isn't
  reliable" (includes the corrected diagram line and full code examples for both fix
  patterns).
- [ ] **`globals->inc()` needs the variable pre-initialized; `globals->get()` doesn't.**
  Confirmed live: `Error when incrementing a global variable: current_round is not a numeric
  value` during `createGame`, from a state calling `->inc()` on a global that
  `setupNewGame()` never `->set()` first. `get($key, $default)` gracefully falls back to
  `$default` when unset; `inc()` has no equivalent. Fix: `->set($key, 0)` in `setupNewGame()`
  for every global any state ever `->inc()`s. Where: `bga-studio-reference.md`,
  "`$this->bga->globals->inc()` requires the variable to already be numeric — initialize it
  in `setupNewGame()`".
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
