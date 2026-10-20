<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

use InvalidArgumentException;

/**
 * Resolves which players belong to a review/end-game effect's target group, against a
 * snapshot of reputations. Pure/DB-free (directly testable under PHPUnit). Shared by
 * ReviewEffectResolver (basic `reputation` effects, evaluated mid-round against reputation
 * that already reflects the round's delta) and the end-game effect evaluator (evaluated once
 * at game end against FINAL reputation) -- both need identical target-matching semantics, just
 * against different reputation snapshots.
 */
final class TargetGroupResolver
{
    /**
     * @param array<int, int> $reputations Map of player_id => reputation.
     * @return int[] Player IDs in the target group. Ties are additive: every player sharing
     *     the extreme value is included, not just one.
     */
    public static function playersInTarget(string $target, array $reputations): array
    {
        return match ($target) {
            'highest_reputation' => self::extremePlayerIds($reputations, max($reputations)),
            'lowest_reputation' => self::extremePlayerIds($reputations, min($reputations)),
            'reputation_positive' => array_keys(array_filter($reputations, static fn(int $rep): bool => $rep >= 0)),
            'reputation_negative' => array_keys(array_filter($reputations, static fn(int $rep): bool => $rep < 0)),
            'reputation_zero' => array_keys(array_filter($reputations, static fn(int $rep): bool => $rep === 0)),
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
