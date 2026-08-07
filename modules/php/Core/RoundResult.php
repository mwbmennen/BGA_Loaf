<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Immutable outcome of resolving one round's played cards against the order total.
 */
final class RoundResult
{
    /**
     * @param int $total Sum of all played card values.
     * @param int $target Order total for the current player count (perPlayerAverage * playerCount).
     * @param bool $success True if $total >= $target.
     * @param int $reputationDelta Amount to add to each affected player's reputation. Positive
     *     on success (extreme card is the highest, so this is a gain); zero or negative on
     *     failure (extreme card is the lowest, so this is a loss).
     * @param int[] $affectedPlayerIds Player IDs who played the extreme card (highest on
     *     success, lowest on failure). More than one entry means a tie.
     */
    public function __construct(
        public readonly int $total,
        public readonly int $target,
        public readonly bool $success,
        public readonly int $reputationDelta,
        public readonly array $affectedPlayerIds,
    ) {
    }
}
