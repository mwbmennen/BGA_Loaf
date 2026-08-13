<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Evaluates every `end_game_bonus`/`end_game_malus`/`double_end_game_bonus`/
 * `double_end_game_malus` effect that resolved during the game, once, against FINAL
 * reputations -- these effects don't resolve when their card is filed like every other effect
 * type does; the rulebook's own wording is that filing such a card "add[s] a new scoring
 * rule," evaluated only at actual game end (docs/loaf-phase4-plan.md §5). Pure/DB-free -- see
 * docs/loaf-implementation-plan.md §2.
 *
 * Deliberately stateless: the caller recomputes the full list of resolved advanced-card
 * effects fresh from `round_card` every time, same "no redundant state" discipline
 * `EndConditionChecker` already established in Phase 2 (docs/loaf-phase2-plan.md §4) -- this
 * class never needs to know *when* during the game a card was filed, only *that* it was.
 */
final class EndGameEffectResolver
{
    /**
     * @param list<array{target: ?string, effect: string, amount: ?int, counts_as_two: bool}> $resolvedEffects
     *     Every review effect that resolved during the game (the success-side effect for
     *     every Happy-pile card, the fail-side effect for every Angry-pile card -- see
     *     `docs/loaf-phase2-plan.md` §7's note that a card's pile fixes which side resolved).
     *     Effect types other than the four this class cares about are ignored; every other
     *     effect (basic `reputation`, `discard_choice`, etc.) is already fully resolved
     *     elsewhere the moment its card was filed.
     * @param array<int, int> $reputations player_id => FINAL reputation, at actual game end.
     *     Also the source of truth for which player_ids exist.
     * @return array<int, int> player_id => total bonus/malus points (bonus positive, malus
     *     negative, net summed), feeding directly into ScoringCalculator::score()'s
     *     `bonusPoints` parameter. Every player_id from $reputations is present, even at 0.
     */
    public static function resolve(array $resolvedEffects, array $reputations): array
    {
        $result = array_fill_keys(array_keys($reputations), 0);
        foreach (self::breakdown($resolvedEffects, $reputations) as $entry) {
            $result[$entry['playerId']] += $entry['amount'];
        }
        return $result;
    }

    /**
     * Per-(effect, affected player) detail, for a "here's exactly what you got and why" game
     * log -- `resolve()` above only needs the summed total per player for scoring, but a
     * human reading the log wants to see each individual contribution (docs/loaf-remarks.md's
     * Phase 4 "end-game bonus/malus breakdown" entry). `resolve()` is defined in terms of this
     * method (sum `amount` per `playerId`) rather than duplicating the doubling logic, so the
     * two can never drift out of sync with each other.
     *
     * @param list<array{target: ?string, effect: string, amount: ?int, counts_as_two: bool}> $resolvedEffects
     * @param array<int, int> $reputations player_id => FINAL reputation.
     * @return list<array{playerId: int, amount: int, effect: array, doubled: bool}>
     *     One entry per player affected by one contributing end_game_bonus/end_game_malus
     *     effect. `amount` is already sign-corrected (positive bonus, negative malus) and
     *     already doubled if the matching doubler also resolved -- `doubled` just says whether
     *     that happened, for a log line to mention it. `effect` is the original resolved
     *     effect array (for describing *why*, e.g. via ReviewEffectDescription::target()) --
     *     never a doubler itself, since doublers have no per-player target of their own.
     */
    public static function breakdown(array $resolvedEffects, array $reputations): array
    {
        $bonusDoubled = false;
        $malusDoubled = false;
        foreach ($resolvedEffects as $effect) {
            if ($effect['effect'] === 'double_end_game_bonus') {
                $bonusDoubled = true;
            } elseif ($effect['effect'] === 'double_end_game_malus') {
                $malusDoubled = true;
            }
        }

        $result = [];
        foreach ($resolvedEffects as $effect) {
            if ($effect['effect'] === 'end_game_bonus') {
                $amount = $effect['amount'] * ($bonusDoubled ? 2 : 1);
                $doubled = $bonusDoubled;
            } elseif ($effect['effect'] === 'end_game_malus') {
                $amount = -$effect['amount'] * ($malusDoubled ? 2 : 1);
                $doubled = $malusDoubled;
            } else {
                // Every other effect type (reputation, discard_choice, ..., double_*, none) is
                // ignored -- already resolved elsewhere, or (for the doublers) has no
                // per-player target of its own to attach a breakdown entry to.
                continue;
            }

            foreach (TargetGroupResolver::playersInTarget($effect['target'], $reputations) as $playerId) {
                $result[] = ['playerId' => $playerId, 'amount' => $amount, 'effect' => $effect, 'doubled' => $doubled];
            }
        }
        return $result;
    }
}
