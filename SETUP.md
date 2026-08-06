# Setup checklist — turning this template into your actual project

Do this once you've created your project in BGA Studio and know its real project name (the
lowercase slug you chose — see `docs/new-bga-project-starter-guide.md` Step 1). This checklist
is the "Golden Rule" (name consistency) turned into a procedure.

Throughout this checklist, `{gamename}` means your lowercase project slug (e.g. `mygame`), and
`{GameName}` means whatever PHP namespace casing Studio's scaffold actually generated for you —
**don't assume it's a lowercase copy of the slug, verify it** (see step 3 below).

## 1. Download the real Studio scaffold

Follow `docs/new-bga-project-starter-guide.md` Steps 3–4 first: connect SFTP, download the
scaffold into `modules/`, `img/`, etc. This template does **not** include that scaffold — it's
generated fresh per-project by Studio.

## 2. Fill in `.vscode/sftp.json`

```bash
cp .vscode/sftp.json.example .vscode/sftp.json
```

Then edit it:
- `host` — check the exact value Studio's connection details page shows you; it isn't always
  `1.studio.boardgamearena.com`.
- `port` — Studio has assigned different ports to different projects in practice (seen both
  `22` and `2022`) — use whatever your project's connection details actually say.
- `username`, `privateKeyPath` — your credentials. Prefer an SSH key over a plaintext password.
- `remotePath` — must be `/{gamename}/` or similar per Studio's own instructions for your
  project; check the exact path Studio gives you.

`.vscode/sftp.json` (the real one, with credentials) is already gitignored — never commit it.

## 3. Verify (don't assume) the PHP namespace casing

```bash
grep -rn "^namespace" modules/php/
```

Whatever casing comes back is your `{GameName}`. Use it exactly, everywhere below.

## 4. Update every PHP namespace and `use` statement

```bash
grep -rn "namespace\|use Bga" modules/php/
```

Every file should declare `namespace Bga\Games\{GameName};` (or `\{GameName}\States;` etc.) and
every cross-file reference should `use Bga\Games\{GameName}\...;` — matching exactly.

## 5. Rename the CSS file

```bash
mv oldname.css {gamename}.css
```

BGA loads *only* a CSS file named exactly after your project slug.

## 6. Update `gameinfos.inc.php` (or `.jsonc`)

Set at minimum `game_name` (display name) and `game_text_name` (must equal `{gamename}`
exactly).

## 7. Wire up the test harness

- `composer.json` → change `"Bga\\Games\\Gelati\\"` style key to `"Bga\\Games\\{GameName}\\"`
  under `autoload.psr-4`, and the package `name` field.
- `tests/bootstrap.php` → change the `$prefix = 'Bga\\Games\\...\\';` line to match.
- `tests/phpunit.xml.dist` → update the `<testsuite name="...">` to your game's name (cosmetic
  only, but keep it accurate).
- `package.json` → update `name`.

## 8. Run the checks locally

```bash
composer install
./vendor/bin/phpunit
./vendor/bin/phpstan analyse

npm install
npm run test:js
npm run lint:js
npm run typecheck
```

All should pass (or report zero tests found, if you haven't written any game logic yet) — this
confirms the harness itself is wired correctly before you start building.

## 9. First upload and first test table

Follow `docs/new-bga-project-starter-guide.md` Step 5 onward from here.

---

## Reminder: `jsdom`'s version is pinned deliberately

`package.json` pins `jsdom` to `^24.1.3`, and CI (`.github/workflows/tests.yml`) runs on Node 18.
That's not arbitrary — `jsdom`'s newer majors require Node ≥20 because a transitive dependency
(`html-encoding-sniffer`) ships an ESM-only file that a Node 18 `require()` can't load
(`ERR_REQUIRE_ESM`). This only surfaces at first `import`, not at `npm install` (`npm install`
only warns via `EBADENGINE`, it doesn't fail). If you bump the Node version this project targets,
you can safely bump `jsdom` too — just don't bump `jsdom` alone without checking.

## Reminder: the stub file starts incomplete, by design

`tests/stubs/BgaFrameworkStubs.php` only mocks the BGA framework methods that have been needed
so far. The first time your real code calls a framework method that isn't stubbed yet, your IDE
will flag it as "undefined" (a false positive — the method exists on Studio's server, just not
in this local stand-in). Add a one-line no-op stub matching the real method's signature and move
on; don't treat it as a sign something's wrong with your code.
