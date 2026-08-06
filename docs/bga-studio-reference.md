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
│   │   ├── Game.php          ← Main game class. Namespace: Bga\Games\{gamename}
│   │   ├── BoardManager.php  ← Game logic helpers. Same namespace.
│   │   ├── constants.inc.php ← State machine constants (ST_xxx values)
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

### Inspecting the Game State

In BGA Studio while a game is running, you can view the current state machine state:

- Studio tools → **Game state** → shows current state name and active player

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
