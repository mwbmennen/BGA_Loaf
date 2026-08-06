# BGA Game Template

A reusable starting point for a new [Board Game Arena Studio](https://studio.boardgamearena.com)
project. This repo is **not** a playable game — it's the scaffolding, conventions, and
lessons-learned that every BGA Studio project in this family of projects has needed, extracted
so the next game doesn't start from zero.

It was extracted from lessons learned building [Gelati](https://github.com/mwbmennen/BGA_Gelati)
(the first game built this way).

## How to use this template

1. Copy this whole repo into a new folder for your next game (or use it as a GitHub template
   repo — see below).
2. Read `docs/new-bga-project-starter-guide.md` start to finish. It walks you through the BGA
   Studio developer application, creating the project, connecting SFTP, and building your first
   working feature.
3. Follow `SETUP.md` once you have a real BGA Studio scaffold to merge in — it's the rename
   checklist for swapping every placeholder here for your actual project name.
4. Keep `docs/bga-studio-reference.md` around as your "what do I do when something's broken"
   reference — it accumulates hard-won BGA Studio conventions and fixes.

## What's in here

| Path | Purpose |
|---|---|
| `docs/new-bga-project-starter-guide.md` | Beginner walkthrough: zero to first playable feature |
| `docs/bga-studio-reference.md` | Conventions, SFTP workflow, common errors and fixes |
| `SETUP.md` | Rename checklist for turning this template into your actual project |
| `CLAUDE.md` | Skeleton project instructions for Claude Code, with the standing rules every game here follows |
| `.vscode/sftp.json.example` | SFTP config template — copy to `.vscode/sftp.json` and fill in real credentials, never commit the real file |
| `tests/stubs/BgaFrameworkStubs.php` | Local PHP stand-in for the BGA framework, so your IDE and PHPUnit both understand framework calls without a real copy of BGA's server code |
| `tests/phpunit.xml.dist`, `tests/bootstrap.php` | PHPUnit harness wired to the stubs above |
| `bga-framework.d.ts` | TypeScript declarations for the BGA framework, for editor-level JS type checking with zero build step |
| `.github/workflows/tests.yml` | CI: PHPUnit + PHPStan + node:test + ESLint + tsc on every push |
| `composer.json`, `package.json`, `phpstan.neon`, `eslint.config.js`, `tsconfig.json` | Toolchain config, pre-wired to the conventions above |

## What this template deliberately does NOT include

- Any actual game logic, board rendering, or state machine — that's Studio's own generated
  scaffold, which you download fresh per project (see the starter guide, Step 3).
- A filled-in `dbmodel.sql`, `gameinfos.inc.php`, etc. — those are game-specific and come from
  Studio's scaffold too.

## Updating this template

If you learn something new and generic while building a game with this template — not specific
to that one game's rules, just a BGA Studio or tooling lesson — bring it back here:

- A convention, error, or fix → add it to `docs/bga-studio-reference.md`
- A new "every game needs this" file or setup step → note it and fold it into the relevant file
  here directly (this repo doesn't keep a running "candidates" list the way an in-progress
  game's own docs might — if it's confirmed useful, just add it)
