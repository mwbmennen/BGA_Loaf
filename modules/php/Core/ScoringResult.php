<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Immutable outcome of scoring one player at game end -- see docs/loaf-phase3-plan.md §6.
 */
final class ScoringResult
{
    public function __construct(
        public readonly int $score,
        public readonly int $aux,
        public readonly bool $fired,
    ) {
    }
}
