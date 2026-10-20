<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Immutable outcome of scoring one player at game end.
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
