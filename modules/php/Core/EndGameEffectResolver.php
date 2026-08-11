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
        $playerIds = array_keys($reputations);
        $bonusTotal = array_fill_keys($playerIds, 0);
        $malusTotal = array_fill_keys($playerIds, 0);
        $bonusDoubled = false;
        $malusDoubled = false;

        foreach ($resolvedEffects as $effect) {
            if ($effect['effect'] === 'end_game_bonus') {
                foreach (TargetGroupResolver::playersInTarget($effect['target'], $reputations) as $playerId) {
                    $bonusTotal[$playerId] += $effect['amount'];
                }
            } elseif ($effect['effect'] === 'end_game_malus') {
                foreach (TargetGroupResolver::playersInTarget($effect['target'], $reputations) as $playerId) {
                    $malusTotal[$playerId] += $effect['amount'];
                }
            } elseif ($effect['effect'] === 'double_end_game_bonus') {
                $bonusDoubled = true;
            } elseif ($effect['effect'] === 'double_end_game_malus') {
                $malusDoubled = true;
            }
            // Every other effect type (reputation, discard_choice, ..., none) is ignored --
            // already resolved elsewhere, not this class's concern.
        }

        $result = [];
        foreach ($playerIds as $playerId) {
            $bonus = $bonusTotal[$playerId] * ($bonusDoubled ? 2 : 1);
            $malus = $malusTotal[$playerId] * ($malusDoubled ? 2 : 1);
            $result[$playerId] = $bonus - $malus;
        }
        return $result;
    }
}
