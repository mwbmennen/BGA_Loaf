<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

use InvalidArgumentException;

/**
 * Resolves one round of L'Oaf: compares the total of all played work cards to the order's
 * target, and computes the reputation swing for the highest (on success) or lowest (on
 * failure) card player(s). Pure/DB-free — see docs/loaf-implementation-plan.md §2.
 */
final class RoundResolver
{
    /**
     * @param int $perPlayerAverage The order card's printed average-per-player value.
     * @param array<int, int> $playedCards Map of player_id => played card value.
     */
    public static function resolve(int $perPlayerAverage, array $playedCards): RoundResult
    {
        if (empty($playedCards)) {
            throw new InvalidArgumentException('Cannot resolve a round with no played cards');
        }

        $total = array_sum($playedCards);
        $playerCount = count($playedCards);
        $target = $perPlayerAverage * $playerCount;
        $success = $total >= $target;

        $extremeValue = $success ? max($playedCards) : min($playedCards);
        $affectedPlayerIds = array_keys(
            array_filter($playedCards, static fn(int $value): bool => $value === $extremeValue)
        );
        $reputationDelta = $extremeValue - $perPlayerAverage;

        return new RoundResult($total, $target, $success, $reputationDelta, $affectedPlayerIds);
    }
}
