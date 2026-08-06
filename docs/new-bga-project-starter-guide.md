# Starting a New BGA Studio Project — A Beginner's Walkthrough

> Written for someone who has never set up a Board Game Arena Studio project before.
> This is a **generic** guide — it doesn't assume any specific game. When you start your
> next game, copy this file into that project's own `docs/` folder and follow it there.
>
> This guide draws on lessons actually learned building Gelati in this repo. For deeper
> detail on any topic below (error messages, edge cases, exact BGA framework quirks), see
> the companion doc `docs/bga-studio-reference.md` in this repo — it's the "what do I do
> when something's broken" reference. This guide is the "what do I do first" walkthrough.

---

## 1. What you're building, in one paragraph

A BGA Studio game has **three things** involved, and almost every beginner mistake comes
from forgetting one of them exists:

1. **Your local files** — the folder on your computer where you write PHP, JS, and CSS.
2. **BGA Studio's servers** — a remote machine where the game actually runs. This is the
   *only* place your game is ever tested or played, even during development.
3. **SFTP** — the one and only bridge between the two. There is no "push to deploy"
   button, no git integration, no live-reload. You edit locally, then upload over SFTP,
   then test on Studio's live server.

Every step below exists to get these three things talking to each other correctly. Keep
this mental model in your head: **you always edit locally, upload via SFTP, and test on
the remote server.** Nothing you do locally is ever "running" until it's uploaded.

---

## 2. Prerequisites checklist

Before you start, make sure you have:

- [ ] A [boardgamearena.com](https://boardgamearena.com) account (playing account, not
      Studio yet)
- [ ] [VS Code](https://code.visualstudio.com/) installed
- [ ] The **SFTP** extension for VS Code, by **Natizyskunk** (search "SFTP" in the
      Extensions panel — there are several similarly-named ones, this is the one with
      active development and the features this guide uses)
- [ ] [git](https://git-scm.com/) installed (`git --version` in a terminal to check)
- [ ] An SSH key pair (`~/.ssh/id_rsa` and `~/.ssh/id_rsa.pub`, or similar). If you don't
      have one yet: `ssh-keygen -t ed25519` in a terminal, accept the defaults.
- [ ] (Optional but recommended, for later) PHP and [Composer](https://getcomposer.org/)
      installed locally, so you can run automated tests against your game logic without
      needing to upload to Studio every time. Not required for the steps in this guide.

---

## 3. Step 1 — Apply for and create a Studio project

BGA Studio is a separate developer area from the normal playing site. You have to apply
for access before you can create anything.

1. Go to `studio.boardgamearena.com` and log in with your normal BGA account.
2. If you haven't already, you'll be asked to submit a **developer application** — a
   short form about the game you want to build. This is reviewed by BGA staff and can
   take some time to be approved; there's no way to skip this.
3. Once approved, click **Create a new game**.
4. Choose a **project name**. This is the single most consequential decision in this
   whole setup:
   - Lowercase letters and numbers only, no spaces, no punctuation (e.g. `mygame`,
     not `My Game` or `my-game`).
   - **Write this name down exactly.** You will need to match it character-for-character
     in several files later (see the "Golden Rule" in Step 4). A typo or case mismatch
     here causes confusing failures much later, when you've forgotten you even chose it.
5. Studio will generate a starting "scaffold" — a folder of template files — on its
   server for your project. You haven't touched it yet; that's the next step.

---

## 4. Step 2 — Set up your local repo

While Studio is generating your scaffold, prepare a local folder to hold it:

1. Create an empty folder on your computer, named however you like (it does **not**
   need to match the Studio project name, though it's less confusing if it does).
2. Open it in VS Code.
3. Initialize git:
   ```bash
   git init
   ```
4. Create a `.gitignore` with at least:
   ```
   .vscode/
   node_modules/
   vendor/
   ```
   The `.vscode/` entry matters specifically because your SFTP credentials will live in
   `.vscode/sftp.json` (next step) — you never want that file committed to git, even in
   a private repo, since it may contain a password or a path to your private key.
5. Make an empty `docs/` folder now. Every game accumulates private design notes, rules
   drafts, and planning docs — decide now that they live in `docs/` and are never
   uploaded to Studio (SFTP setup in the next step enforces this).

---

## 5. Step 3 — Connect SFTP and download the scaffold

This is the step that actually links your local folder to the Studio server.

1. **Find your SFTP credentials first — they are not in the Studio website.** BGA does
   not show SFTP host/login/password anywhere in the Studio control panel or project
   pages. They're sent once, in the **initial welcome email** you received when your
   developer account was approved. Search your inbox for a message that talks about
   SFTP specifically — it is a **separate login from your normal Studio account**
   (different username/password than the one you use to log into
   `studio.boardgamearena.com`), so don't assume they match. If you can't find that
   email, contact BGA Studio support to ask them to resend it — don't guess at a host.
2. In VS Code, open the Command Palette (`Cmd+Shift+P` / `Ctrl+Shift+P`) and run
   **`SFTP: Config`**. This creates a `.vscode/sftp.json` file for you to fill in.
3. Fill it in like this:
   ```json
   {
     "name": "BGA Studio",
     "host": "1.studio.boardgamearena.com",
     "protocol": "sftp",
     "port": 2022,
     "username": "your_bga_username",
     "privateKeyPath": "~/.ssh/id_rsa",
     "remotePath": "/home/user/games/mygame/",
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
   A few things worth understanding, not just copying:
   - `host` — the exact studio subdomain from your SFTP welcome email (e.g. `1.studio...`,
     `2.studio...`); it's assigned per account, not something you choose, and doesn't
     appear in the Studio UI to double-check later — keep that email.
   - `port` — BGA's SFTP service listens on `2022`, not the standard `22`.
   - `privateKeyPath` — use your SSH key, not a plaintext password. If you must use a
     password, never commit `sftp.json` (your `.gitignore` from Step 2 already prevents
     this).
   - `remotePath` — must match your exact project name from Step 1.
   - `uploadOnSave: false` — start with this off. Auto-upload-on-save sounds convenient
     but means every keystroke-adjacent save pushes to the live server; turn it on later
     only once you trust your workflow.
   - `ignore` — this list is the only thing standing between your private `docs/` folder
     and BGA's servers. **Double-check it's actually complete before your first upload.**
     A drifted or incomplete ignore list is a real, previously-hit mistake in this
     project's own history (see `docs/bga-studio-reference.md` §4) — private design docs
     nearly got uploaded because the list was missing entries.
4. Now pull down the scaffold Studio generated for you:
   - In the Explorer sidebar, right-click the **root folder** of your project.
   - Look for an **SFTP** submenu on the right-click menu.
   - Choose **Download Folder** (not "Sync Remote → Local" — see the warning below).
   - If no SFTP submenu appears, the extension may not be active yet: run
     **`SFTP: Config`** again from the Command Palette (harmless, just re-validates), then
     retry the right-click.

   > **Why "Download Folder" and not "Sync Remote → Local":** if your `ignore` list ever
   > has a gap, or `sftp.json` has `"delete": true` set, a *sync* mirrors the remote and
   > **deletes** any local file the remote doesn't have — including local-only files you
   > haven't uploaded yet. "Download Folder" just copies files down without touching or
   > deleting anything else. Prefer it whenever you're pulling from Studio, especially
   > early on.

5. Commit this initial scaffold to git before changing anything:
   ```bash
   git add -A
   git commit -m "Initial BGA Studio scaffold"
   ```
   This gives you a clean baseline to diff against later, and a way back if a later step
   goes wrong.

---

## 6. Step 4 — Verify the Golden Rule: names must match everywhere

BGA derives file-loading paths directly from your project name. If anything doesn't
match, you get silent or confusing failures (a CSS file that never loads, a PHP class
BGA claims doesn't exist). This is the single most common beginner failure mode, so
verify it explicitly rather than assuming it's already correct.

Your project name has to match in a few places:

| What | Where | Example (project name: `mygame`) |
|---|---|---|
| PHP namespace | Every `.php` file in `modules/php/` | `namespace Bga\Games\mygame;` |
| PHP `use` statements | Anywhere one file references another | `use Bga\Games\mygame\States\...;` |
| Root CSS filename | Project root | `mygame.css` — BGA loads *only* a file with exactly this name |
| `gameinfos` metadata | `gameinfos.inc.php` (or `.jsonc` on newer scaffolds) | `game_text_name` must equal `mygame` |

**Don't assume the namespace casing** — check what Studio's scaffold generator actually
produced for you, rather than guessing a lowercase copy of your slug:

```bash
grep -rn "^namespace" modules/php/
```

The CSS filename must be an exact lowercase match of your slug; the PHP namespace segment's
casing is whatever the generator produced (it has varied between projects — confirmed
both a lowercase and a capitalized version across different real scaffolds). Trust the
grep output over any assumption.

---

## 7. Step 5 — First upload and first test table

1. Upload your (still-untouched) scaffold back to Studio: right-click the root folder →
   **SFTP** → **Sync Local → Remote**. (Sync is fine in this direction — you're pushing
   from your own trusted local copy, nothing gets surprise-deleted locally.)
2. In Studio, open your project and click **Create a table**.
3. Start a 2-player test game using two logged-in accounts (Studio lets you play against
   yourself using a second browser tab/session, or invite a real second account).
4. Watch the Studio server log for PHP errors as the game initializes. If it errors,
   that's expected — the default scaffold is meant to run as-is, but any local edits or
   name mismatches will surface here first. See `docs/bga-studio-reference.md` §5 for the
   most common error messages and their fixes.

At this point you have a working (if empty) BGA game loop: a table can be created, a
game can start, and the board renders. Everything after this is building your actual
game on top of it.

---

## 8. Step 6 — Understand the state machine skeleton

BGA games are built around a **state machine**: the game is always in exactly one
named state (e.g. "waiting for player to move"), and each state defines what actions are
legal and what happens next. The scaffold already includes a couple of default states —
open `modules/php/States/` and look at what's there before writing anything new.

Two things to internalize before you add your first state:

- **PHP state names and JS state names must match exactly.** The JS side registers a
  handler per state name:
  ```js
  this.bga.states.register("PlayDisc", this.playDisc); // "PlayDisc" must match the PHP state
  ```
  A mismatch here doesn't error loudly — the server-side state changes correctly, but
  the UI just never updates. This is one of the most common "it looks broken but the
  logs show everything worked" bugs.
- **State transitions are done by returning a class**, not calling an old-style
  `nextState()` function (some older BGA tutorials online still show the old style):
  ```php
  return NextPlayer::class;   // hands control to the NextPlayer state
  ```
  If you see `$this->gamestate->nextState('...')` in something you're copying from
  online, you're looking at the old framework style — check which generation your own
  scaffold actually uses (`grep -rn "nextState\|::class" modules/php/States/`) before
  copying that pattern in.

---

## 9. Step 7 — Build one real state end-to-end (your first playable feature)

This is the step that turns a blank scaffold into an actual game. Pick the smallest
possible player action in your game — something like "place one piece" — and build the
full round-trip for it. Every game feature you ever add follows this same five-part
shape:

1. **A state class** (`modules/php/States/YourAction.php`) — declares what's legal, and
   what `getArgs()` sends to the client to render the choice.
2. **An action handler** — the PHP method the client calls when the player commits to
   the move (validates the move, applies it to the database, decides the next state).
3. **A notification** — `$this->notifyAllPlayers('yourAction', '...', [...])`. This is
   how the server tells *all* connected clients something changed. Don't update the UI
   directly from inside the action handler — always go through a notification, even for
   the player who made the move. This keeps every client's view of the game consistent.
4. **A JS notification handler** — a method named exactly `notif_` + your notification
   type (e.g. `notif_yourAction`), registered via `setupPromiseNotifications()` in your
   `Game.js` constructor. This is where you actually update the DOM.
5. **The next state** — returned from your action handler, so play continues.

Build these five pieces **one at a time**, testing after each one where possible (a PHP
syntax error will show in the Studio log immediately; a JS handler mismatch you can only
see by actually taking the action in a live test game). Don't write all five and upload
once — if something's wrong, you want to know which piece broke it.

A couple of specific gotchas worth knowing *before* you hit them, not after:

- **Use `$this->trace(...)` for debug output, never `var_dump()` or `echo`.** BGA's
  responses are JSON; raw `echo`/`var_dump` output corrupts the response stream and
  produces a `JSON_ERROR_SYNTAX` error in the browser with no useful clue as to why.
- **Values read back from the database or from framework accessors like
  `getActivePlayerId()` can come back as strings, not integers**, regardless of the
  column type or the method's declared return type. Cast explicitly (`(int) $value`) at
  the point you read them, especially before a strict `===` comparison — a missed cast
  here doesn't error, it just silently takes the wrong branch forever. See
  `docs/bga-studio-reference.md`'s "BGA framework accessors return strings, not int"
  section for the full story.

---

## 10. Step 8 — The debugging loop

You will spend most of your time in this loop: edit locally → upload → test on Studio →
read the log → repeat. A few tools make this fast:

- **PHP errors / your own debug output**: Studio → wrench icon → **Game Server Logs**.
  Trigger the action, then refresh the log.
- **JS errors / your own `console.log`**: browser DevTools (`F12`) → Console tab.
- **CSS not updating after upload**: BGA aggressively caches CSS. Hard-reload
  (`Cmd+Shift+R` / `Ctrl+Shift+R`) or disable cache in DevTools' Network tab before
  assuming your change didn't take effect.
- **Database schema changes**: editing `dbmodel.sql` and re-uploading does **not**
  re-apply the schema to an existing game. In Studio: your game → **Manage** → **Wipe
  the game database**, then start a fresh table.

---

## 11. Common pitfalls — condensed appendix

These are short pointers, not full explanations — follow the link into
`docs/bga-studio-reference.md` (in this repo) for the full story and exact fix on any of
them once you actually hit it:

- **`dbmodel.sql` silently drops whole columns** if a comment uses a trailing `-- text`
  on the same line as a column definition. Always put comments on their own line. (ref
  doc §5, "CREATE TABLE silently missing columns")
- **`initGameStateLabels([...])` must be called in the constructor**, not only inside
  `setupNewGame()` — otherwise every request *after* the very first one throws
  `Undefined array key` the first time it looks up a label by name. (ref doc, same-named
  section)
- **Sprite sheets built from CMYK-exported JPEGs** are 5–15× larger than they should be
  and can render with inverted colors in some browsers. Check with `sips -g space` before
  building a sheet. (ref doc §5, "sprite sheet is way over the 4MB limit")
- **HTML5 drag-and-drop needs `preventDefault()` on `dragover`**, or `drop` silently
  never fires. (ref doc, "HTML5 drag-and-drop" section)
- **Local IDE red squiggles on BGA framework methods are usually false positives** — your
  machine has no copy of the real framework. A stub file teaches your IDE what exists; if
  something's missing from it, add an empty-body stub rather than assuming your code is
  wrong. (ref doc §9)

---

## 12. Where to go next

Once you have one working feature end-to-end, you're past the "environment setup" phase
and into normal game-logic development. From here:

- Write your own **rules doc** and **implementation plan** (phased task list) for your
  specific game, the way `docs/GELATI_rules.txt` and
  `docs/gelati-implementation-plan.md` do in this repo — don't try to build the whole
  game in your head at once.
- Keep a **project-specific remarks doc** (like `docs/gelati-remarks.md`) for judgment
  calls you make under uncertainty as you go — decisions that aren't generic BGA
  conventions, just choices specific to your game.
- Anything generic you learn along the way that would help *any future BGA project*,
  not just this one, belongs back in a shared reference doc like this repo's
  `docs/bga-studio-reference.md` — copy the relevant section into your new project's own
  copy of that file so it isn't locked away in a repo about a different game.
