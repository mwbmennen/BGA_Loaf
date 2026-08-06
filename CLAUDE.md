# {GameDisplayName} — project instructions

This repo is the BGA Studio implementation of **{GameDisplayName}**, a {N}-player game. Design
and planning docs live in `docs/`; the game logic lives under `modules/`.

<!-- If this project's design work happened elsewhere first (a sandbox repo, a notes doc), say
     so here, the way earlier projects in this family have. Delete this comment once filled in. -->

## Standing rules

- **Template notes**: whenever work here (or discussion) reveals something *every future BGA
  game will need*, fold it back into the shared
  [bga-game-template](https://github.com/mwbmennen/bga-game-template) repo — either
  `docs/bga-studio-reference.md` (generic conventions/fixes) or a new file if it's a whole new
  artifact (a config, a checklist). Don't let cross-game lessons get stranded in one game's repo.
- **`docs/` is private**: it must never be uploaded to BGA Studio. The live `.vscode/sftp.json`
  ignore list must always include `docs`, `tests`, `.claude`, `.github`, `tools`, `*.md`.
- **Reference doc**: when a BGA issue is solved or a lesson learned, record it in
  `docs/bga-studio-reference.md`.
- **Naming Golden Rule**: the root CSS filename must exactly match the BGA project name
  (`{gamename}.css`). The PHP namespace segment's casing is whatever BGA Studio's scaffold
  generator actually produced — verify with `grep -rn "^namespace" modules/php/` rather than
  assuming a lowercase copy of the slug; see `docs/bga-studio-reference.md` §1.
- **Architecture**: placement/scoring rules live in pure, DB-free classes so the same logic can
  run under PHPUnit (and under any future simulation/balance tooling) without touching BGA at
  all. BGA state handlers should be thin adapters over that pure core.

## Key docs

- `docs/new-bga-project-starter-guide.md` — beginner walkthrough: zero to first playable feature
- `docs/bga-studio-reference.md` — conventions, SFTP deployment workflow, common-error fixes
- `SETUP.md` — rename checklist for turning this template into your actual project
<!-- Add game-specific docs here as they're created, e.g.:
- `docs/{gamename}-rules.txt` — full rules
- `docs/{gamename}-implementation-plan.md` — phased task list, the canonical "what's next"
- `docs/{gamename}-remarks.md` — game-specific implementation notes and known gaps
-->
