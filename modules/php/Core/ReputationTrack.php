<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Pure clamp/adjust helper for the shared reputation track (-10..+10). No BGA/DB dependency.
 */
final class ReputationTrack
{
    public const MIN = -10;
    public const MAX = 10;

    public static function adjust(int $current, int $delta): int
    {
        return max(self::MIN, min(self::MAX, $current + $delta));
    }
}
