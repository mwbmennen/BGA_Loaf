# L'Oaf — Open Questions

> Answers needed to take `docs/loaf-implementation-plan.md` from skeleton to
> content-complete. Grouped by blocking vs. nice-to-know. Update the plan doc's assumptions
> as these get answered.
>
> **Status (2026-08-07): all 11 questions answered.** Q1's full 24-card data set is now
> transcribed into `docs/loaf-card-data.json` — nothing left blocking Phase 2+ of the
> implementation plan.

## Blocking

1. ~~**Full round card data set.**~~ **Answered: fully transcribed** into
   `docs/loaf-card-data.json` (all 12 basic + 12 advanced cards, order totals for 2-6
   players and both review-side effects each). See `docs/loaf-remarks.md` for the effect
   vocabulary used.
2. ~~**Is the order's "average per player" always a whole number for every supported player
   count?**~~ **Answered: yes** — the cards guarantee this; no rounding rule needed in
   `RoundResolver`. (Still worth a sanity assertion once `docs/loaf-card-data.json` is
   populated, per Q1.)
3. ~~**Discard pile visibility.**~~ **Answered: visible only to its owner.** Confirms the
   plan's default assumption (§4) — `work_card` rows with `location = 'discard'` should be
   filtered out of other players' view in `getAllDatas()`/private state, same as hand cards.
4. ~~**Negotiation / table talk.**~~ **Answered: normal BGA table chat during `PlayCards` is
   sufficient** — no dedicated discussion/timer phase needed.
5. ~~**Fired-player display at game end.**~~ **Answered: all fired players are shown tied at
   the bottom** — no ordering among themselves by reputation.
6. ~~**All-players-fired edge case.**~~ **Answered: shared last place for everyone, no
   winner** — consistent with Q5's "all fired players tied at the bottom" rendering.
7. ~~**Advanced-mode default.**~~ **Answered: opt-in table/lobby option, default off** —
   matches the physical rules' "don't use advanced effects your first game" advice. Confirms
   the plan's §5 assumption as-is.

## Nice-to-know

8. ~~**Localization scope.**~~ **Answered: keep localization open to all languages.** Doesn't
   mean pre-translating French/Dutch strings ourselves — it means every user-facing string
   must be wrapped in BGA's translation functions (`clienttranslate(...)` in PHP,
   `_(...)`/`self::_(...)` where applicable, the equivalent JS helper) from day one, never
   hardcoded English. That's what makes the game translatable via BGA's own translator
   platform later for any language, without an engineering pass to retrofit it. No hardcoded
   English strings anywhere in `modules/php/` or `modules/js/`.
9. ~~**Art assets.**~~ **Answered: real digital assets are already available** — no
   placeholder-art phase needed; build sprite sheets/UI directly against the official
   card art/icons once art integration starts.
10. ~~**Player-count recommendations.**~~ **Answered: 2–6 uniformly fine** — leave
    `suggest_player_number` / `not_recommend_player_number` unset in `gameinfos.jsonc`.
11. ~~**Turn timer for the simultaneous phase.**~~ **Answered: use BGA's standard
    fast/medium/slow defaults** for `PlayCards` — no custom/longer timer for negotiation.
