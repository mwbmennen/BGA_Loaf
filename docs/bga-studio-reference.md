# BGA Studio — Practical Reference Guide

> Companion to `new-bga-project-starter-guide.md`. Where the starter guide walks you through your first project step by step, this document is the thing you open when something is broken or you need to remember a convention.

---

## Table of Contents

1. [The Golden Rule: Game Name Consistency](#1-the-golden-rule-game-name-consistency)
2. [File Structure & Naming Conventions](#2-file-structure--naming-conventions)
3. [Setting Up a New Project From Scratch](#3-setting-up-a-new-project-from-scratch)
4. [Deploying to BGA Studio (SFTP Workflow)](#4-deploying-to-bga-studio-sftp-workflow)
5. [Common Errors & How to Fix Them](#5-common-errors--how-to-fix-them)
6. [Debugging in BGA Studio](#6-debugging-in-bga-studio)
7. [Checklist: Before You Start a Test Game](#7-checklist-before-you-start-a-test-game)
8. [Checklist: When Adapting Tutorial Code](#8-checklist-when-adapting-tutorial-code)

---

## 1. The Golden Rule: Game Name Consistency

**Everything must match your BGA project name.**

When you create a game in BGA Studio, you give it a name (e.g. `reversidumble`). BGA uses this name to locate your files and load your classes. If anything doesn't match — classes won't load, CSS won't apply, JS won't run.

Your project name appears in **four places**:

| What                 | Where                               | Example (project name: `reversidumble`)        |
| -------------------- | ----------------------------------- | ---------------------------------------------- |
| PHP namespace        | Every `.php` file in `modules/php/` | `namespace Bga\Games\reversidumble;`           |
| PHP `use` statements | Every cross-file reference          | `use Bga\Games\reversidumble\States\PlayDisc;` |
| Root CSS filename    | Project root                        | `reversidumble.css`                            |
| Root JS filename     | (if applicable)                     | `reversidumble.js`                             |

### Why this matters

BGA derives autoloading paths and asset URLs directly from the project name. A mismatch causes silent failures — PHP throws "class not found", CSS just never loads (the board renders as unstyled text).

### Real example: what went wrong with this project

This project was adapted from a Reversi tutorial that used `Bga\Games\Reversi` namespaces and `reversi.css`. The game was registered in Studio as `reversidumble`. Symptoms:

- **Starting a game** → `Fatal error: Class "\Bga\Games\reversidumble\Game" not found in modules/php/Game.php`
- **Game loaded** (after PHP fix) → Only coordinate text visible, no board graphics, no colors

**Fixes applied:**

1. Updated all PHP namespaces from `Bga\Games\Reversi` → `Bga\Games\reversidumble`
2. Renamed `reversi.css` → `reversidumble.css` (BGA never loads a CSS file named differently from the project)

### Namespace casing: match what Studio actually generated, not what you'd guess

Don't assume the namespace segment is a lowercase copy of the project slug — verify it against the downloaded scaffold. For the `gelati` project (registered 2026-07, freshly generated Studio scaffold, not hand-adapted tutorial code), Studio generated `namespace Bga\Games\Gelati;` (capital G) in every scaffold PHP file, even though the project slug and CSS filename (`gelati.css`) both stay lowercase. This differs from the `reversidumble` example above. Rule of thumb: the CSS/JS root filename always matches the lowercase project slug exactly, but the PHP namespace segment's casing is whatever Studio's scaffold generator produced for that project — `grep -rn "^namespace" modules/php/` after downloading the scaffold to confirm, rather than trusting planning docs written before the scaffold existed.

---

## 2. File Structure & Naming Conventions

```
{gamename}/
├── {gamename}.css            ← MUST match project name. BGA won't load any other CSS file.
├── dbmodel.sql               ← Database schema. Runs once on game creation.
├── gameinfos.inc.php         ← Game metadata (name, players, duration, BGG ID, etc.)
├── gameoptions.json          ← Pre-game setup options (board size, variants)
├── gamepreferences.json      ← Per-player in-game preferences (UI toggles)
├── stats.json                ← Statistics tracked per game/player
├── img/
│   └── tokens.png            ← All game art gathered into sprite sheets
├── sounds/
│   └── *.mp3                 ← Sound effects
├── modules/
│   ├── php/
│   │   ├── Game.php          ← Main game class. Namespace: Bga\Games\{gamename}. MUST
│   │   │                       require_once('constants.inc.php') near the top -- it's a
│   │   │                       plain file, not autoloaded like a class (see §5).
│   │   ├── BoardManager.php  ← Game logic helpers. Same namespace.
│   │   ├── constants.inc.php ← State machine constants (ST_xxx values), globals keys, etc.
│   │   └── States/
│   │       ├── PlayDisc.php  ← Namespace: Bga\Games\{gamename}\States
│   │       ├── NextPlayer.php
│   │       └── EndScore.php
│   └── js/
│       └── Game.js           ← Client-side game logic (exported as ES module)
└── tests/
    ├── bootstrap.php
    ├── stubs/
    │   └── BgaFrameworkStubs.php
    └── *Test.php
```

### PHP Namespace Pattern

Every PHP file in `modules/php/` must declare the correct namespace:

```php
// modules/php/Game.php and modules/php/BoardManager.php:
namespace Bga\Games\reversidumble;

// modules/php/States/*.php:
namespace Bga\Games\reversidumble\States;
```

And `use` statements must reference the same namespace:

```php
// In Game.php:
use Bga\Games\reversidumble\States\PlayDisc;

// In any State file:
use Bga\Games\reversidumble\Game;
```

---

## 3. Setting Up a New Project From Scratch

Follow these steps when creating a new BGA game (or adapting existing tutorial code).

### Step 1 — Create the game in BGA Studio

1. Log in at `studio.boardgamearena.com`
2. Click **Create a new game**
3. Choose a project name — lowercase letters and numbers only, no spaces (e.g. `mygame`)
4. Note the name exactly — you'll use it everywhere

### Step 2 — Scaffold the file structure

BGA Studio generates a starter scaffold when you create the project. Download it via SFTP.

Once `.vscode/sftp.json` is filled in with real credentials, pull the scaffold down yourself via the VS Code SFTP extension ("SFTP" by Natizyskunk, referenced in §4):

1. In the Explorer sidebar, right-click the **root folder** of the project.
2. Look for an **SFTP** submenu — it should have `Download Folder` (alongside `Upload Folder`, `Sync Local -> Remote`, `Sync Remote -> Local`, etc.).
3. Choose **Download Folder**.

`Download Folder` and `Upload Folder` are exposed as Explorer right-click items on this extension, not as standalone Command Palette entries. If no SFTP submenu appears on right-click, the extension likely isn't active yet — run **`SFTP: Config`** from the Command Palette once first (it just opens/validates the existing `sftp.json`, won't overwrite it), then retry the right-click.

Prefer "Download Folder" over "Sync Remote → Local": if `sftp.json` has `"delete": true`, a sync does a _mirror_ and deletes any local file that isn't present on the remote and isn't in the `ignore` list (e.g. a local-only `composer.json` that Studio never generated). "Download Folder" just copies remote files down without touching or deleting anything else locally. If `Download Folder` genuinely isn't available and `Sync Remote → Local` is the only option, check the current `ignore` list first — anything not covered by it is at risk of deletion.

### Step 3 — Set the namespace in every PHP file

Open each PHP file and set the namespace:

```php
// modules/php/Game.php
namespace Bga\Games\mygame;

// modules/php/States/MyState.php
namespace Bga\Games\mygame\States;
```

If adapting tutorial code, search for the old namespace and replace all occurrences:

```bash
# Find all namespace references to replace:
grep -rn "namespace\|use Bga" modules/php/
```

### Step 4 — Name the CSS file correctly

The root CSS file **must** be named `{gamename}.css`. BGA will not load any other file. If adapting a project, rename the CSS file now:

```bash
# Example: renaming from tutorial name to your game name
mv reversi.css mygame.css
```

### Step 5 — Update gameinfos.inc.php

Set at minimum:

```php
$game_infos = [
    'game_name'       => 'My Game Display Name',
    'game_text_name'  => 'mygame',      // must match project name
    'players'         => [2, 4],        // [min, max]
    'duration'        => 30,            // minutes
    // ...
];
```

### Step 6 — Define the database schema in dbmodel.sql

Start minimal — add columns only as you need them:

```sql
CREATE TABLE IF NOT EXISTS `board` (
  `x` tinyint(4) unsigned NOT NULL,
  `y` tinyint(4) unsigned NOT NULL,
  `player` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`x`, `y`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

The `player` table is provided by BGA automatically — don't create it.

### Step 7 — Upload to Studio and test

1. Sync local to remote via SFTP (see section 4)
2. In Studio, click **Create a table** → Start a 2-player game with two logged-in accounts
3. Watch the Studio log for PHP errors on game creation

---

## 4. Deploying to BGA Studio (SFTP Workflow)

BGA Studio does not have a CLI or git integration. You deploy by syncing files over SFTP.

### VS Code SFTP Extension Setup

Install the **SFTP** extension by Natizyskunk. Then create `.vscode/sftp.json`:

```json
{
  "name": "BGA Studio",
  "host": "1.studio.boardgamearena.com",
  "protocol": "sftp",
  "port": 22,
  "username": "your_bga_username",
  "privateKeyPath": "~/.ssh/id_rsa",
  "remotePath": "/home/user/games/reversidumble/",
  "uploadOnSave": false,
  "ignore": [
    ".vscode",
    ".git",
    "node_modules",
    "vendor",
    "tests",
    "docs",
    "*.md"
  ]
}
```

> **Security note:** never commit `sftp.json` if it contains passwords. Use SSH keys instead.

### Sync Commands (Cmd+Shift+P in VS Code)

| Action                            | Command                     |
| --------------------------------- | --------------------------- |
| Upload all local files to Studio  | `SFTP: Sync Local → Remote` |
| Upload current file only          | `SFTP: Upload Active File`  |
| Download all Studio files locally | `SFTP: Sync Remote → Local` |

### What to sync (and what not to)

**Always sync:**

- `modules/php/` — game logic
- `modules/js/Game.js` — frontend
- `{gamename}.css` — styles
- `img/` — artwork
- `dbmodel.sql` — only when schema changes (see below)
- `gameoptions.json`, `gamepreferences.json`, `stats.json`, `gameinfos.inc.php`

**Do not sync:**

- `.git/`, `.claude/`, `docs/`, `tests/`, `vendor/`, `node_modules/`, `.vscode/`, `*.md`

> ⚠️ **The extension only reads the live `.vscode/sftp.json` — not this document.** Lesson learned (2026-07-11): the actual `sftp.json` in this repo had drifted from the template above and was missing `docs` and `tests` from its `ignore` list, while `uploadOnSave: true` and full-sync were enabled — personal files in `docs/` (rulebooks, design notes) could have been uploaded to BGA Studio. After editing the ignore list, remember: already-uploaded files are **not** removed by adding them to `ignore`; delete them from the remote manually (the extension skips ignored paths even when `syncOption.delete` is true).

### Schema changes (dbmodel.sql)

BGA does **not** re-run `dbmodel.sql` automatically when you sync it. To apply schema changes during development, you must:

1. In Studio, go to your game → **Manage** → **Wipe the game database** (drops all tables and re-runs `dbmodel.sql`)
2. Or write a migration SQL and run it manually via Studio's SQL console

> ⚠️ **Never use trailing `--` comments in `dbmodel.sql`** — put every comment on its own line, never after SQL on the same line. See §5 "CREATE TABLE silently missing columns" below.

---

## 5. Common Errors & How to Fix Them

### Error: "Class not found"

```
Fatal error during {gamename} setup:
Class "\Bga\Games\{gamename}\Game" not found in modules/php/Game.php
```

**Cause:** PHP namespace in `modules/php/Game.php` doesn't match the BGA project name.

**Fix:** Update the namespace in every PHP file:

```php
// Wrong (old tutorial name):
namespace Bga\Games\Reversi;

// Correct (your project name):
namespace Bga\Games\reversidumble;
```

Check all files at once:

```bash
grep -rn "namespace\|use Bga" modules/php/
```

---

### Error: "Declaration of Game::setupNewGame(...) must be compatible with Table::setupNewGame(...)"

```
Fatal error: Declaration of Bga\Games\{gamename}\Game::setupNewGame(array $players, array $options = [])
must be compatible with Bga\GameFramework\Table::setupNewGame($players, $options = [])
```

**Cause:** `setupNewGame()` (or any other framework hook method you override —
`upgradeTableDb()`, `zombieTurn()`, etc.) was declared with type hints that the parent
`Table` method doesn't have. PHP's override-compatibility rule treats an untyped parent
parameter as implicitly `mixed`; a child method that narrows it to `array` (or any concrete
type) is an *incompatible* override, not a safe addition — this is a hard fatal, not a
warning, and it happens at class-load time, before any of your code runs. Easy to write by
habit (typing your own parameters is normally good practice) and impossible to catch locally,
since there's no vendored copy of `Bga\GameFramework\Table` to type-check against — only a
live Studio deploy surfaces it.

**Fix:** Match the parent signature exactly — no type hints, even though `$players`/`$options`
are always arrays in practice. Do any type-narrowing/casting *inside* the method body instead:

```php
// Wrong — narrows an untyped parent parameter:
protected function setupNewGame(array $players, array $options = [])

// Correct — matches Table::setupNewGame($players, $options = []):
protected function setupNewGame($players, $options = [])
```

If your IDE/linter (Intelephense, PHPStan) separately flags "Parameter has no type information
available" on the untyped params, don't reach for a native type hint to silence it — that's the
fatal above. Add a PHPDoc block instead, which documents the type for tooling without touching
the real signature:

```php
/**
 * @param array $players Map of player_id => player info (as supplied by BGA framework).
 * @param array $options Map of table option id => chosen value.
 */
protected function setupNewGame($players, $options = [])
```

---

### Error: "Undefined constant \"...\ST_XXX\"" / "...\GLOBAL_XXX\"" from a state class

```
Fatal error: Uncaught Error: Undefined constant "Bga\Games\{gamename}\States\ST_RESOLVE_ROUND"
in modules/php/States/ResolveRound.php
```

**Cause:** A plain script file of `const` declarations (e.g. `constants.inc.php`) with no
`namespace` of its own is only visible if something explicitly `require`s it — unlike a class
file, which PHP's autoloader pulls in on demand the first time it's referenced. If nothing
`require`s the constants file, the constants simply don't exist at runtime, and PHP throws
"undefined constant" the first time a namespaced file references one unqualified (PHP falls
back to the *global* namespace for unqualified constants, but only if the constant was
actually defined somewhere by the time the lookup happens).

**Fix:** `require_once` the constants file from `Game.php`, since `Game.php` is always loaded
before any state class is instantiated — this is a one-time, file-scope `require`, so it only
needs to happen once, at the top of `Game.php`, not per-state:

```php
namespace Bga\Games\{gamename};

use Bga\Games\{gamename}\Core\SomeClass;
// ...other use imports...

require_once __DIR__ . '/constants.inc.php';

class Game extends \Bga\GameFramework\Table
{ /* ... */ }
```

This pattern (centralized `constants.inc.php`, required once from `Game.php`, right after the
`use` imports) is confirmed working in production on a sibling project (Gelati) — the bug
isn't in centralizing state-id constants, it's specifically in forgetting the `require`. If
you're chasing this error, check for exactly that: `grep -rn "constants.inc.php"
modules/php/` and confirm a `require`/`require_once` shows up, not just the file itself.

---

### Error: "Error when incrementing a global variable: {key} is not a numeric value"

**Cause:** `$this->bga->globals->inc($key, $delta)` was called on a global that
`setupNewGame()` never initialized. `globals->get($key, $default)` gracefully falls back to
`$default` when the key doesn't exist yet, but `inc()` has no equivalent fallback — it expects
the value to already be a number.

**Fix:** `->set($key, 0)` (or whatever the correct starting value is) in `setupNewGame()` for
every global that any state later calls `->inc()` on:

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

---

### Error: Game loads but shows only text / no graphics

**Symptom:** You see the board coordinate labels (letters and numbers) but no colored squares, no discs, no background.

**Cause:** The CSS file is not named after the game. BGA only loads `{gamename}.css`. A file named `reversi.css` is ignored entirely.

**Fix:**

```bash
cp reversi.css reversidumble.css
# Then upload reversidumble.css to Studio
```

---

### Error: JS state handler not called

**Symptom:** Game state transitions work on the server (PHP logs show the right state), but the UI doesn't update.

**Cause:** The state class registered in JS doesn't match the state name defined in the PHP state machine, or `setupNotifications()` is missing a subscription.

**Fix:** Verify the registration name matches exactly:

```js
// In Game.js constructor:
this.bga.states.register("PlayDisc", this.playDisc); // 'PlayDisc' must match PHP state name
```

---

### Error: "Invalid move" thrown immediately on valid move

**Cause:** The `getArgs()` method in your state PHP class is returning empty possible moves, or the validation in `actXxx()` has a logic error.

**Fix:** Add a `trace()` call in `getArgs()` to log what's returned:

```php
public function getArgs(): array {
    $moves = $this->game->boardManager->getPossibleMoves(...);
    $this->game->trace("getArgs possibleMoves: " . json_encode($moves));
    return ['possibleMoves' => $moves];
}
```

Check the Studio log after triggering the state.

---

### Error: `UserException` ("Invalid move"-style message) thrown on a move that's clearly valid, specifically when validating against `in_array(..., true)`

**Cause:** A specific, confirmed-live (2026-08-08) instance of the generic "Invalid move on a
valid move" symptom above: `getObjectListFromDb()`/`getCollectionFromDb()` return raw
database values as **strings**, even for numeric columns (`INT`, `TINYINT`, etc.). Validating
a player's submitted value with `in_array($value, $dbValues, true)` — strict comparison,
the `true` third argument — silently fails every time, because `3 !== "3"` in PHP's strict
comparison. The action always gets rejected with whatever "you don't have/can't do that"
message you wrote, regardless of what's actually true.

**Fix:** Cast DB results to the right type immediately after fetching, before doing any
strict comparison against them:

```php
private function getHandValues(int $playerId): array {
    return array_map('intval', $this->game->getObjectListFromDb(
        "SELECT `value` FROM `work_card` WHERE `player_id` = $playerId AND `location` = 'hand' ORDER BY `value`",
        true
    ));
}
```

If you don't actually need strict comparison, dropping the `true` argument from `in_array()`
(loose comparison) also works, but casting is more robust — it keeps the rest of your code
(sums, comparisons, notifications) working with real integers instead of numeric strings that
happen to behave correctly most of the time.

---

### Error: "Fatal error during {game} setup: Not logged"

**Cause:** `getCurrentPlayerId()` (no-args form) throws when there's no requesting player
session — it reads who sent the current HTTP request, and there isn't one during
`setupNewGame()`, since that runs once as a system routine at table creation, never in
response to a specific player's request.

**Fix:** In `setupNewGame()`, either avoid `getCurrentPlayerId()` entirely (you already have
`$players`/`array_keys($players)` — the full player list — which is almost always what you
actually want there), or use the nullable variant `getCurrentPlayerId(true)` (returns `null`
instead of throwing) if you genuinely need to check for a request context.

**Do not "fix" this the same way inside a state's `getArgs()`** — see the next entry below,
`getArgs()` has no "requesting player" concept at all, not even during real gameplay, and
papering over the crash with `getCurrentPlayerId(true)` there just trades a loud crash for a
silent one (empty data for every player, always — confirmed live, see below).

---

### Error: `MULTIPLE_ACTIVE_PLAYER` state shows "Waiting for other players..." for everyone / nobody gets action buttons

**Symptom:** Every connected player sees a waiting message and zero action buttons in a state
declared `type: StateType::MULTIPLE_ACTIVE_PLAYER` — confirmed live (2026-08-08), reproduced
with a 2-player table where only one player showed as active (the other stuck on "Waiting for
other players...") and even that one active player got no buttons.

**Two independent causes, both required to fully fix this:**

**1. `type: StateType::MULTIPLE_ACTIVE_PLAYER` only declares that the state *permits* several
simultaneously active players — it doesn't activate anyone.** You must explicitly call
`$this->game->gamestate->setAllPlayersMultiactive();` in the state's `onEnteringState()`, the
same way a single-`ACTIVE_PLAYER` state needs an explicit call to select its one active
player. Nothing about the `type:` constructor argument does this for you:

```php
public function onEnteringState() {
    $this->game->gamestate->setAllPlayersMultiactive();
}
```

**2. `getArgs()` runs exactly once per state entry, server-side, and its return value is
broadcast to every connected player — there is no "requesting player" context inside it, ever
(not a setup-only quirk; this is true during completely normal live gameplay too).** Calling
`getCurrentPlayerId()` (in either form) inside `getArgs()` to fetch "my own hand" doesn't
work: it has nothing to resolve to, so every player's hand comes back empty and nobody gets
buttons. BGA's actual mechanism for private per-player data is the `_private` key, keyed by
player id, with `_merge_private => true` to flatten each recipient's own entry into their
top-level args **on the client only**:

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

**Important correction, confirmed live:** `_merge_private` only affects what the *client*
receives — it does **not** carry through to an `actXxx()` action handler's injected
`array $args` parameter. Submitting an action (e.g. clicking a button built from this
`getArgs()` data) threw `Undefined array key "handValues"` from inside the action handler,
because the framework's injected `$args` there was just the raw `getArgs()` return value
(`_private`/`_merge_private` keys, nothing flattened/unwrapped for the acting player). Don't
rely on `$args` inside an action handler to carry your `_private` data through at all — drop
the `array $args` parameter from the action method entirely and re-fetch whatever
player-specific data you need directly, keyed by `$currentPlayerId` — **not**
`$activePlayerId`, see the next entry below for why that specific parameter name matters:

```php
#[PossibleAction]
public function actCommitCard(int $value, int $currentPlayerId) {
    if (!in_array($value, $this->getHandValues($currentPlayerId), true)) {
        throw new UserException('You do not have that work card in hand');
    }
    // ...
}
```

General rule: never use `getCurrentPlayerId()` inside any state's `getArgs()`, multiactive or
not — BGA's own docs warn against it. For a private per-player payload sent to the client, use
`_private` (+ `_merge_private` for flat client-side access) — but treat that as display-only
data; re-derive anything an action handler needs to validate against, from `$currentPlayerId`,
rather than trusting it arrived via `$args`. For a `zombie(int $playerId)` handler, don't
reuse `getArgs()`'s result either — pull that specific `$playerId`'s data directly via a
shared private helper, since `zombie()` is also system-driven with no session.

---

### Error: `$activePlayerId` magic parameter is wrong/0 inside a `MULTIPLE_ACTIVE_PLAYER` action handler

**Symptom:** An action handler's validation always fails (e.g. "You do not have that work
card in hand" on a card that's definitely in hand), and/or the wrong player's data gets
updated/notified. A stack trace shows the injected id as `0` (`actCommitCard(3, 0, ...)`) —
`0` is never a real BGA player id, which is the tell.

**Cause:** Confirmed live (2026-08-08), and directly per BGA's own docs: the `$activePlayerId`
(or `$active_player_id`) magic parameter is documented as "not necessarily the one triggering
the action" and is only meaningful on single-`ACTIVE_PLAYER` states. On a
`MULTIPLE_ACTIVE_PLAYER` state, several players can be active at once, so there's no single
"the" active player for the framework to inject — it silently resolves to `0` instead of
erroring.

**Fix:** Use `$currentPlayerId` (or `$current_player_id`) instead — documented as "the player
who triggered the action," which is what an action handler almost always actually wants,
regardless of state type:

```php
#[PossibleAction]
public function actCommitCard(int $value, int $currentPlayerId) {
    // use $currentPlayerId everywhere here: the hand-ownership check, the DB update,
    // the notification, and setPlayerNonMultiactive() — NOT $activePlayerId.
}
```

General rule: on any `MULTIPLE_ACTIVE_PLAYER` state's action handler, use `$currentPlayerId`
for "who did this," never `$activePlayerId`. Reserve `$activePlayerId` for genuine
single-`ACTIVE_PLAYER` states, where there's exactly one active player and it's unambiguous.

---

### Error: `TypeError`/`undefined` client-side after reading `.length` on a Deck component's `getCardsInLocation()` result

**Symptom:** JS code doing `gamedatas.someCards.length` (or a notification payload field
built the same way) reads as `undefined` — confirmed live (2026-08-08) on a page refresh,
where a boss-pile card counter that had been working via live notification increments showed
`undefined` after a fresh page load re-sent the initial game data.

**Cause:** `$this->yourDeck->getCardsInLocation($location)` returns a PHP array keyed by
**card id** (`[12 => [...], 47 => [...], ...]`), not a plain sequential list. A
non-sequential/associative PHP array serializes to a JS **object** in the JSON payload, not
an array — and plain objects don't have a `.length` property, only real arrays do. This is
easy to miss because PHP itself doesn't distinguish the two (`usort()`, `foreach`, `count()`
all work identically on both), so nothing looks wrong until the *client* tries to treat the
result as an array.

**Fix:** Use `Object.keys(...).length` client-side instead of `.length` — it works correctly
on both plain arrays and objects, so it's safe regardless of which shape a given PHP endpoint
actually returns:

```js
const bossHappyCount = Object.keys(gamedatas.bossHappy).length; // not gamedatas.bossHappy.length
```

If you need the actual card data (not just a count) client-side, remember to iterate with
`Object.values(...)` or `Object.entries(...)`, not array methods like `.map()`/`.forEach()`
directly on the object.

---

### What actually delivers a notification: CometD

`notify->all(...)`/`notifyAllPlayers(...)` on the PHP side doesn't push to the browser directly —
BGA's platform uses **CometD**, a JS/Java library implementing the Bayeux protocol for server
push over HTTP (long-polling, upgrading to WebSockets where available), so a connected player's
browser receives the event in real time without polling for it. This is platform machinery, not
something a game ever touches directly: `$this->notify->all(...)` on the PHP side and
`setupPromiseNotifications()`/`notif_xxx` on the JS side are BGA's own wrappers around it. Worth
knowing only so the phrase "cometD notification" (BGA's own scaffold comments use it) doesn't read
as Gelati-specific — it's just BGA's name for "the transport behind every notification."

### Error: Notification handler never fires

**Symptom:** PHP sends a notification (`notifyAllPlayers`), but the JS `notif_xxx` method never runs.

**Cause:** Either the notification type name doesn't match between PHP and JS, or the JS handler isn't registered via `setupPromiseNotifications()` / the naming convention isn't followed.

**Fix:** Confirm the naming convention is exact:

```php
// PHP:
$this->notifyAllPlayers('playDisc', '', [...]);   // type = 'playDisc'
```

```js
// JS method name must be exactly 'notif_' + type:
async notif_playDisc(args) { ... }
```

And in `setupNotifications()`:

```js
this.bga.notifications.setupPromiseNotifications(); // auto-discovers notif_xxx methods
```

---

### Error: `MULTIPLE_ACTIVE_PLAYER` state's `onEnteringState(args, isCurrentPlayerActive)` gets `isCurrentPlayerActive: false` for a player who's genuinely active

**Symptom:** After a live (pushed) transition into a `MULTIPLE_ACTIVE_PLAYER` state, a
player who should be active — real hand data proves it — sees no action buttons and gets
stuck on a "waiting for other players" message. **A page refresh always fixes it.** Confirmed
live (2026-08-08): browser console logging showed `isCurrentPlayerActive: false` at the exact
moment `onEnteringState` fired, for a player with a genuine non-empty hand, with zero JS
errors anywhere in the sequence (ruled out both a third-party browser extension and an
Incognito/clean-profile retest before concluding this).

**Cause:** This is a documented BGA framework race condition, not specific to any one game
(cross-checked against `forum.boardgamearena.com/viewtopic.php?t=14059`, including a BGA
admin's own explanation). In a `MULTIPLE_ACTIVE_PLAYER` state, player activation is set
**during** the state's own PHP-side `onEnteringState()` (a `setAllPlayersMultiactive()` call)
— so there's no guarantee the client's notion of "am I active" has settled by the exact
instant its own JS `onEnteringState(args, isCurrentPlayerActive)` fires from a live
push. A full page reload doesn't have this problem because it re-derives activation status
from scratch rather than trusting whatever a live push claimed.

**Fix:** Never gate action-button-adding on `onEnteringState`'s `isCurrentPlayerActive`
parameter for a `MULTIPLE_ACTIVE_PLAYER` state. Do it in `onPlayerActivationChange` instead —
a separate lifecycle hook the framework calls once activation has actually settled,
confirmed live to fire as its own distinct event on every single state entry, not just on
later activation changes:

```js
class PlayCards {
    // Don't add buttons here -- isCurrentPlayerActive can be stale on a live push.
    onEnteringState(_args, _isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(_("Bakers are committing a work card"));
    }

    // This is the reliable signal -- called once activation has settled, both on first
    // becoming active and on becoming inactive again.
    onPlayerActivationChange(args, isCurrentPlayerActive) {
        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_("${you} must commit a work card"));
            args.handValues.forEach((value) =>
                this.bga.statusBar.addActionButton(
                    _("Commit ${value}").replace("${value}", value),
                    () => this.onCardClick(value),
                ),
            );
        } else {
            this.bga.statusBar.setTitle(_("Waiting for other players to commit a work card"));
        }
    }
}
```

General rule: for any `MULTIPLE_ACTIVE_PLAYER` state's JS class, treat `onEnteringState`'s
`isCurrentPlayerActive` as informational only (fine for a generic status message), and put
all activation-dependent UI (buttons, private-data rendering) in `onPlayerActivationChange`
instead.

---

### Error: Database schema out of sync

**Symptom:** PHP throws an error like `Column 'board_x' not found` or `Table 'board' doesn't exist`.

**Cause:** You added a column to `dbmodel.sql` but didn't re-apply the schema.

**Fix:**

1. In BGA Studio → your game → **Wipe database** (this recreates all tables from `dbmodel.sql`)
2. Start a fresh game — do not try to continue an existing one

---

### Error: `CREATE TABLE` silently missing columns / "A table must have at least one visible column"

**Symptom:** Game creation fails with something like:

```
Fatal error during {gamename} setup: Error while processing SQL request (mysql via TCP/IP):
CREATE TABLE IF NOT EXISTS `tile` ( PRIMARY KEY (`tile_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
A table must have at least one visible column.
```

Studio's error banner echoes the _actual_ SQL it tried to run — read it closely. Here every
column definition is gone; only `PRIMARY KEY (...)` and the `ENGINE=...` clause survived.

**Cause (confirmed 2026-07-12, Gelati):** `dbmodel.sql` used trailing `-- comment` text on the
_same line_ as a column definition, e.g.:

```sql
CREATE TABLE IF NOT EXISTS `tile` (
  `tile_id` TINYINT UNSIGNED NOT NULL,   -- 1..64, key into GelatiMaterial::TILES
  ...
```

Studio's `dbmodel.sql` importer drops an **entire line** if it contains `--` anywhere,
rather than stripping only the trailing comment portion after it. Every column line that
had an inline trailing comment vanished completely; the `PRIMARY KEY` and closing
`) ENGINE=...` lines had no `--` in them and survived untouched — which is why the failed
`CREATE TABLE` had a primary key referencing a column that no longer existed in the
statement at all. Moving every comment to its own line (verified against a real Studio
table creation) fixed it: all four tables (`tile`, `board`, `ice_order`, `part`) created
successfully and `setupNewGame()` ran end to end.

**Fix:** never write a trailing `-- comment` after SQL on the same line in `dbmodel.sql`.
Put every comment on its own line, above the thing it documents:

```sql
CREATE TABLE IF NOT EXISTS `tile` (
  -- 1..64, key into GelatiMaterial::TILES
  `tile_id` TINYINT UNSIGNED NOT NULL,
  ...
```

This applies to **every** table in the file — a single early `CREATE TABLE` failing aborts
the whole setup, so later tables' comment style never even gets exercised until the earlier
ones are fixed.

---

### Error: CSS changes not reflected after sync

**Cause:** BGA Studio aggressively caches CSS. The browser might serve the old file.

**Fix:**

1. Hard-reload the page: `Cmd+Shift+R` (Mac) / `Ctrl+Shift+R` (Windows)
2. Or open DevTools → Network tab → check "Disable cache" → reload

---

### Error: sprite sheet is way over the 4MB limit / colors look off in-browser

**Cause:** source images exported from a design tool (Photoshop, Illustrator, etc.) can be
saved as **CMYK JPEGs** instead of RGB. CMYK JPEGs are 5–15x larger than an equivalent RGB
JPEG at the same quality setting (quality knobs barely help — the bloat is the extra color
channel, not compression level), and some browsers (Firefox especially) render CMYK JPEGs
with inverted or wrong colors.

**Check before building any sprite sheet:**

```bash
sips -g space path/to/image.jpg   # must print "space: RGB", not "space: CMYK"
```

**Fix (convert to RGB in place with `sips`, macOS built-in, no ImageMagick needed):**

```bash
sips -m "/System/Library/ColorSync/Profiles/Generic RGB Profile.icc" \
  -s format jpeg -s formatOptions 87 path/to/image.jpg --out path/to/image.jpg
```

Also verify every source image feeding one `montage` call has **pixel-identical
dimensions** (`sips -g pixelWidth -g pixelHeight`) — a 1px drift across files (e.g. one
resize rounding to 300×150, another to 300×149) is enough to misalign a grid sprite sheet.

**Separate gotcha when scripting `montage`/`magick` yourself:** bash brace expansion like
`tile_{01..64}.jpg` does not reliably zero-pad low numbers in every shell — it can silently
expand to `tile_1.jpg`...`tile_9.jpg` (no leading zero), which then fail to open while
`montage` continues anyway, substituting blank tiles instead of erroring. Build the file
list with a zero-padded loop instead (`for i in $(seq -f "%02g" 1 64); do ...`), which is
what `tools/build-sprite.sh` already does — don't reintroduce the brace-expansion version
when testing commands ad hoc.

---

## 6. Debugging in BGA Studio

### PHP: Reading the Studio Log

Use `$this->trace()` to write debug output to the Studio server log. **Never use `var_dump()` or `echo`** — they print raw text into the JSON response stream, corrupting it and causing a `JSON_ERROR_SYNTAX` error in the browser.

```php
// Correct — goes to server log only:
$this->trace("My debug value: " . json_encode($someVariable));

// Wrong — corrupts the JSON response and breaks the game:
var_dump($someVariable);
```

To view:

1. Open your game in Studio
2. Click the **wrench icon** (Studio tools) → **Game Server Logs**
3. Trigger the action that should produce output
4. Refresh the log

### JS: Browser DevTools

Open DevTools (`F12`) → Console. BGA logs state changes by default. Add your own:

```js
setup(gamedatas) {
    console.log('gamedatas:', gamedatas);  // inspect what the server sent
}
```

**Standing rule**: `console.log` calls are convenient during development but ship straight to
every player's browser console in production — comment them out (don't delete outright, so
they're easy to re-enable) before considering a feature/implementation phase done. Easiest to
catch with `grep -rn "console\.log" modules/js/` as a final pass before wrapping up.

### Inspecting the Game State

In BGA Studio while a game is running, you can view the current state machine state:

- Studio tools → **Game state** → shows current state name and active player

### Surfacing hidden server-side state before the UI exists

If game logic resolves something server-side that has no on-screen representation yet (no card
art, no board widget for it), testers have no way to check the outcome against what should have
happened — a functionally-correct effect and a silently-wrong one look identical on screen.
Don't wait for the real UI to make this testable: add a plain-text `notify->all()` describing
the hidden state in words, using `clienttranslate()` on each translatable piece (including enum
labels passed as substitution args, not just the message template — BGA's client matches
translated arg strings the same way it matches the template). It shows up for free in BGA's
standard game log panel, no new JS/template work needed, and is easy to delete once real UI
lands. Cheaper than building the real UI early just to unblock testing, and cheaper than testing
blind.

### Forcing a State Transition (Studio Only)

During testing you can manually trigger state transitions from the Studio admin panel without going through normal gameplay. Useful for testing end-game scoring without playing a full game.

### PHP var_dump Location

`var_dump()` output goes to the PHP error log, not the Studio game log. Use `$this->trace()` instead — it writes to the Studio log which you can read from the dashboard.

### Debug endpoint access (`debug_*` methods) — researched, not confirmable either way (2026-07-28)

BGA's docs describe `debug_`-prefixed methods on the game class purely as a Studio interface
feature — they show up in a debug menu triggered by a bug icon in the Studio toolbar. Searched
`Practical_debugging`, `Studio_function_reference`, `Testing_by_developer`, and `Studio_FAQ`
directly (plus web search) for an explicit statement that these are automatically unreachable
once a game is live/published to real players — found none. The closest related fact cuts the
other way: BGA's docs separately recommend `getBgaEnvironment() === 'studio'` as something *the
developer* must add themselves to gate other debug-only code from ever showing up outside
Studio — which wouldn't need to be a documented pattern if `debug_*` methods were already
platform-enforced.

**Conclusion:** don't assume the platform blocks these on its own. `Game::debug_goToState()`/
`debug_playOneMove()` now guard themselves explicitly with `getBgaEnvironment() === 'studio'`
(throwing `SystemException` otherwise) — see `docs/gelati-architecture-review.md`'s Backend
Finding 7 minor-items entry. Not verifiable by PHPUnit (the real `Table` base class isn't
vendored in this repo); needs a live Studio session to confirm `getBgaEnvironment()` exists and
returns `'studio'` as documented on this project's framework version.

---

## 7. Checklist: Before You Start a Test Game

Run through this before clicking "Create table" in Studio to avoid confusing error states.

- [ ] PHP namespaces in **all** `modules/php/` files match the project name
- [ ] CSS file is named `{gamename}.css` and has been uploaded
- [ ] `gameinfos.inc.php` has correct player count and `game_text_name`
- [ ] `dbmodel.sql` has been uploaded and the database wiped if schema changed
- [ ] No syntax errors in PHP — check Studio server log after upload
- [ ] `Game.js` default export is the `Game` class
- [ ] All state names in JS (`bga.states.register(...)`) match PHP state machine names exactly
- [ ] All notification types in PHP (`notifyAllPlayers('type', ...)`) have matching `notif_type()` handlers in JS
- [ ] All debug `console.log` calls in `modules/js/` are commented out, not left active (see §6)

---

## 8. Checklist: When Adapting Tutorial Code

When you copy a tutorial project and rename it to your own game, do all of the following before uploading:

### PHP

```bash
# Find all old namespace references:
grep -rn "namespace\|use Bga\\\Games" modules/php/
```

For each file, update:

- `namespace Bga\Games\OldName;` → `namespace Bga\Games\{gamename};`
- `namespace Bga\Games\OldName\States;` → `namespace Bga\Games\{gamename}\States;`
- `use Bga\Games\OldName\...` → `use Bga\Games\{gamename}\...`

Files to check:

- `modules/php/Game.php`
- `modules/php/BoardManager.php` (or any other helper)
- `modules/php/States/PlayDisc.php`
- `modules/php/States/NextPlayer.php`
- `modules/php/States/EndScore.php`
- Any other `.php` file you added

### CSS

```bash
# Rename the CSS file:
mv oldname.css {gamename}.css
# Upload {gamename}.css to Studio
# Delete oldname.css from Studio (it will never be loaded but will confuse you)
```

### gameinfos.inc.php

Update:

- `'game_name'` — display name
- `'game_text_name'` — must match project name exactly

### Quick verification after upload

1. Open Studio → Create table → watch for PHP errors in the log
2. Start the game → verify the board renders with correct colors/layout
3. Play one move → verify the move applies and notifications fire

---

## 9. Local IDE Setup (Intelephense / VS Code)

BGA's framework runs on Studio's servers — your local machine has no copy of it. This means the PHP language server (Intelephense) will flag BGA framework methods as "undefined" even though the game runs fine on Studio. These are **false positives**, not real bugs.

### The stubs file

`tests/stubs/BgaFrameworkStubs.php` is a local fake copy of the BGA framework that teaches Intelephense what exists. It is loaded by PHPUnit for tests, and Intelephense reads it automatically because it's in the project.

If you see an "Undefined method" or "Undefined property" warning on a BGA framework call, the method is simply missing from the stubs — add it there.

### Adding a missing method

Open `tests/stubs/BgaFrameworkStubs.php` and add the method to the relevant class with an empty body. Prefix all parameters with `_` to suppress "unused parameter" warnings:

```php
protected function myMissingMethod(string $_param): array { return []; }
```

### Visibility mismatches in the stubs

The stubs declare some BGA framework methods as `protected` even though they are `public` in the real framework. For example, `DbQuery` is `protected` in the stubs but `public` on BGA's servers — that's why calling it from a helper class like `BoardManager` works at runtime but produces an "access protected method" error locally.

If you see this: check whether the method is actually `protected` in the real framework (i.e. only callable from within `Game` and its subclasses) or just misdeclared in the stubs. If it works on Studio, the stubs visibility is wrong — change it to `public` in `BgaFrameworkStubs.php`.

### Missing PHP attributes

`#[PossibleAction]` and similar PHP attributes used by the BGA framework may not be declared in the stubs, causing "Undefined type" errors in the IDE. The attributes are resolved at runtime by BGA's server via reflection — they are not needed locally. You can silence the error by adding an empty attribute class to the stubs:

```php
// In BgaFrameworkStubs.php, inside namespace Bga\GameFramework\States:
#[\Attribute]
class PossibleAction {}
```

### Adding a missing property (`$bga`, `$gamestate`)

These are declared as typed properties on the `Table` class in the stubs, with stub helper classes in the `Bga\GameFramework\Helpers` namespace. Add new sub-objects there if needed.

### Mixing global functions and namespaces

The stubs file uses **bracketed namespace syntax** (`namespace Foo { }`) because it also defines a global function (`clienttranslate`). PHP requires bracketed syntax when mixing global code with named namespaces in a single file. Do not switch to unbracketed namespaces — it will cause a "Namespace declaration must be the first statement" error.

### The stubs file does not affect the game

Changes to `BgaFrameworkStubs.php` only affect IDE warnings and PHPUnit tests. Nothing in it runs on BGA Studio.

### bootstrap.php must use the correct game namespace

`tests/bootstrap.php` registers an autoloader for your game classes. The namespace prefix must match your project name:

```php
$prefix = 'Bga\\Games\\reversidumble\\';  // must match your project name
```

If this is wrong, PHPUnit tests will fail to load your game classes.

---

## State Transitions: Old vs New Style

The BGA framework supports two styles of state transition. The training plan exercises mention `nextState()` but the Reversi project uses the newer return-based style.

**Old style** (you may see this in older BGA tutorials):

```php
$this->gamestate->nextState('transitionName');
```

**New style** (used in this project):

```php
return NextPlayer::class;   // return the class name of the next state
```

In the new style, every state method that causes a transition simply returns the target state's class. `nextState()` is never called. If an M4 exercise asks you to find `nextState()` calls — in this project there are none; look for `return SomeState::class` instead.

---

## Wrap every user-facing string in BGA's translation functions, from day one

BGA Studio's translator platform can only pick up strings that are wrapped in its translation
helpers — `clienttranslate(...)` for strings notified/sent to the client, `self::_(...)` (or
the bare `_(...)` helper, framework-version-dependent) for strings resolved server-side, and
the JS-side equivalent for client-only text. A hardcoded English string anywhere in
`modules/php/` or `modules/js/` is invisible to that system — it will never appear in the
translator's queue no matter how many languages get added later, and retrofitting it means
hunting down every literal string after the fact instead of catching it at write-time.

**Standing rule**: never write a bare user-facing English string in game code. Wrap it in the
appropriate translation helper the moment it's written, even if only English is planned for
launch — this is what keeps the game open to every language BGA supports without an
engineering pass later, and costs nothing extra to do upfront.

---

## Key Principles to Internalize

1. **BGA uses the project name as the source of truth** — namespace, CSS filename, and routing all derive from it. Inconsistency = silent failure.

2. **The server owns all state** — the JS client only renders and sends requests. Never trust data that came only from the client.

3. **Sync often, test immediately** — BGA Studio has no hot reload. Upload → create fresh table → test → repeat.

4. **One game table = one schema version** — you cannot migrate an existing game table. Wipe and restart during development.

5. **Notifications are the UI bus** — every board change the user sees must come through a notification. Don't update the UI directly from an action handler.

---

## BGA framework accessors return strings, not int — `getActivePlayerId()`, `activeNextPlayer()`, `getGameStateValue()`

BGA framework methods that surface a value sourced from the DB (`getActivePlayerId()`, `activeNextPlayer()`, `getGameStateValue()`) can return it as a numeric string rather than `int`, regardless of what their own signature (or this project's local stub of them) declares. This produces two different failure modes depending on how the value is used, both only showing up live on BGA Studio's server, never locally:

- Passed straight into one of *our* `strict_types=1` methods with an `int`-typed parameter (`getActivePlayerId()`/`activeNextPlayer()` into an `int $playerId` param) → throws a `TypeError`. Loud and immediate.
- Compared with strict `===`/`!==` against an int literal (`getGameStateValue(...) === 0`) → the comparison silently and permanently evaluates to the wrong branch. No error at all — just a feature that quietly never activates (Gelati's `canCancelTurn()`/`canCancelExchange()` hit exactly this; see `docs/gelati-remarks.md`, "`canCancelTurn()`/`canCancelExchange()` never showed their buttons on live Studio"). This failure mode is more dangerous specifically because it never surfaces as an error.

**Why this happens, mechanically**: a column being `INT` in `dbmodel.sql` (or a game-state-value's registered id) is a _schema_ type, not a runtime PHP type. PDO (which the framework's DB layer sits on top of) returns query results as PHP strings by default for essentially every column type — standard emulated-prepared-statement behavior, not a bug. This codebase already works around that everywhere _we_ read the DB directly — every method in `Game.php` casts explicitly at the point of fetch (`(int) $row['holder']`, `(int) $this->getUniqueValueFromDb(...)`, `(bool) $this->getUniqueValueFromDb(...)`, etc.) — so the only places this ever surfaces are spots where a _framework_ accessor's return value (not one of our own DB-reading methods) flows directly into a comparison or a `strict_types=1`-typed parameter, with no cast in between. Confirmed empirically for `getActivePlayerId()`/`activeNextPlayer()`, not just inferred: the live TypeError message itself said "string given" for the argument.

**Standing rule going forward**: cast `(int)` at the read site for *any* BGA framework accessor whose value feeds a comparison or a typed parameter — don't trust the local stub's type signature as proof of the real return type.

---

## `initGameStateLabels()` must be called in the constructor, not just `setupNewGame()`

`setupNewGame()` runs exactly once, at table creation, in its own isolated request. If `initGameStateLabels([...])` is only called there, the name→id mapping it registers only exists in that one request's memory — every later, independent request (any subsequent action call) starts a fresh PHP process with no idea those label names were ever registered. The symptom is a fatal error the _first_ time any later request calls `getGameStateValue()`/`setGameStateValue()` by name: `Undefined array key "yourLabelName"` thrown from deep inside the framework's `Table.php`, not from your own code — easy to misread as a data bug rather than a registration-scope bug. It reliably works on the very first call chained directly off `setupNewGame()`'s own return value (same request), then breaks on the next genuinely separate request, which is a strong tell for this exact cause.

Fix: call `initGameStateLabels([...])` in the constructor (runs on every request) instead of — or in addition to, though redundant — inside `setupNewGame()`. It's pure label-name registration, not a DB write, so calling it unconditionally on every request is safe and idempotent.

Fix: cast immediately at the call site, e.g. `$playerId = (int) $this->game->getActivePlayerId();` — same pattern already used for DB row values elsewhere (`(int) $row['holder']` in `Game::getCells()`). Affected call sites so far: `States/PlayerTurn.php`, `States/AfterPlacement.php`, `States/NextPlayer.php`.

---

## A missed `(int)` cast on a DB value doesn't just misdisplay — JS silently does string concatenation

`Game::getPartCounts()` (used by both `getAllDatas()` and every `adjustStock()`-driven notification) forwarded `getDoubleKeyCollectionFromDb()`'s raw `count` value uncast into `gamedatas`/notification payloads — a numeric-string, same root cause as the entry above (PDO returns DB values as PHP strings), just one method that had been missed. This one was worse than a `TypeError`, though: it never crashed, it silently rendered wrong. The client's `adjustStock()` does `(this.stocks[pid][cat][variant] || 0) + delta`; JS's `+` operator concatenates instead of adding whenever *either* side is a string, so `"0" + 1` produced the string `"01"` and `"4" + (-1)` produced `"4-1"` — both displayed as-is in the parts-stock table, no error anywhere, only caught by a human noticing the numbers looked odd live on Studio.

Lesson: a live-only display bug with no exception and no failing PHPUnit test (the `FakeGame` stub's leaf override stores real PHP ints, so it can't reproduce this) is a strong tell for exactly this class of bug — check every DB-value-returning method for a missing `(int)`/`(bool)` cast before looking anywhere else.

Fix: cast every value when building the return array, not just the array's keys (PHP auto-converts numeric-string *array keys* to real ints on its own, which is why this class of bug only ever hits *values*, never keys). `Game::getPartCounts()` now iterates and casts `(int) $count` explicitly, matching the `(int) $row[...]` pattern already used in `getCells()`/`getCellTileIds()`.

---

## HTML5 drag-and-drop: `dragover` must call `preventDefault()` or `drop` never fires

Not BGA-specific, but easy to lose an hour to the first time a game adds drag-and-drop tile/piece placement as a complement to click-to-select. Browsers default every element to "not a valid drop target." The `dragover` event fires continuously while a dragged item hovers over an element, and calling `e.preventDefault()` inside that handler is the *only* signal that opts the element in as a drop target — skip it and the `drop` event simply never fires (cursor shows "not-allowed", no error, nothing to debug). `ondrop` then needs its own `e.preventDefault()` too, to stop the browser's default handling of the dropped data (e.g. treating dropped text as a navigation).

Pattern used in `modules/js/Game.js` (`renderBoard()`, board cells as drop targets):
```js
cellDiv.ondragover = (e) => e.preventDefault();
cellDiv.ondrop = (e) => {
  e.preventDefault();
  place();
};
```
Note also: HTML5 drag-and-drop has no touch support, so treat it as a desktop-only *complement* to click handlers, never a replacement.
