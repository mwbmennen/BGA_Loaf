---
name: branch-pr
description: "Create a branch from staged changes, commit with a generated message, and open a pull request"
argument-hint: "[branch-name]"
---

# Branch & PR

Take the currently staged changes, create a new branch, commit them with a well-crafted message, and open a pull request.

## Steps

1. Run `git diff --cached --stat` and `git diff --cached` to understand what is staged. If nothing is staged, tell the user and stop.

2. Choose a branch name:
   - If `$ARGUMENTS` was provided, use that (kebab-case).
   - Otherwise, derive a short descriptive name from the staged changes (e.g. `fix/auth-token-expiry`, `feat/globe-light-marker`). Prefix with `feat/`, `fix/`, `chore/`, or `refactor/` as appropriate.

3. Check the branch name doesn't already exist locally (`git branch --list <name>`) or remotely (`git ls-remote --heads origin <name>`). If it does, append a short unique suffix.

4. Create and switch to the new branch: `git checkout -b <branch-name>`.

5. Craft a commit message:
   - Subject line: imperative mood, ≤72 chars, no trailing period (e.g. "Add rate limiting to lights API").
   - Optional body: 1–3 sentences explaining _why_, not _what_, if not self-evident.

6. Commit: `git commit -m "<message>"`.

7. Push: `git push -u origin <branch-name>`.

8. Create a PR with `gh pr create` — title matches the commit subject, body includes a brief summary and a **Test plan** checklist. Use a HEREDOC to preserve formatting.

9. Output the PR URL.
