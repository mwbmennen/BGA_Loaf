<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

use InvalidArgumentException;

/**
 * Resolves a review card's basic `effect: 'reputation'` type against the current reputation
 * track -- the only effect type any basic card uses (docs/loaf-phase2-plan.md §2). Every
 * other effect type (all advanced-only: discard/swap/end-game bonus-malus/none) is a
 * deliberate no-op here, left for Phase 4. Pure/DB-free -- see
 * docs/loaf-implementation-plan.md §2.
 */
final class ReviewEffectResolver
{
    /**
     * @param array{target: ?string, effect: string, amount: ?int, counts_as_two: bool} $effect
     *     One side (success or fail) of a card's review effect, from RoundCardData.
     * @param array<int, int> $reputations Map of player_id => CURRENT reputation -- must
     *     already reflect this round's RoundResolver delta, since the rulebook resolves the
     *     round total (and its reputation swing) before the review effect
     *     (docs/loaf-phase2-plan.md §3.1).
     * @return array<int, int> Map of player_id => new reputation, for every player in
     *     $effect's target group. Players not in the group are simply absent from the
     *     result, not included at zero delta.
     */
    public static function resolve(array $effect, array $reputations): array
    {
        if (empty($reputations)) {
            throw new InvalidArgumentException('Cannot resolve a review effect with no players');
        }

        if ($effect['effect'] !== 'reputation') {
            return [];
        }

        if ($effect['target'] === null) {
            throw new InvalidArgumentException(
                "effect === 'reputation' must have a non-null target; RoundCardData is inconsistent"
            );
        }

        $targetPlayerIds = self::playersInTarget($effect['target'], $reputations);

        $result = [];
        foreach ($targetPlayerIds as $playerId) {
            $result[$playerId] = ReputationTrack::adjust($reputations[$playerId], $effect['amount']);
        }
        return $result;
    }

    /**
     * @param array<int, int> $reputations
     * @return int[]
     */
    private static function playersInTarget(string $target, array $reputations): array
    {
        return match ($target) {
            // Ties are additive, not split -- every player sharing the extreme value is
            // affected independently, same precedent RoundResolver already established for
            // the played-card extreme (docs/loaf-phase2-plan.md §3.2).
            'highest_reputation' => self::extremePlayerIds($reputations, max($reputations)),
            'lowest_reputation' => self::extremePlayerIds($reputations, min($reputations)),
            'reputation_positive' => array_keys(array_filter($reputations, static fn(int $rep): bool => $rep >= 0)),
            'reputation_negative' => array_keys(array_filter($reputations, static fn(int $rep): bool => $rep < 0)),
            default => throw new InvalidArgumentException("Unknown target: $target"),
        };
    }

    /**
     * @param array<int, int> $reputations
     * @return int[]
     */
    private static function extremePlayerIds(array $reputations, int $extremeValue): array
    {
        return array_keys(
            array_filter($reputations, static fn(int $rep): bool => $rep === $extremeValue)
        );
    }
}
