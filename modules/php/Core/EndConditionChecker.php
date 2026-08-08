<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Tracks weighted Happy/Angry boss-pile counts and reports whether the game has ended.
 * Pure/DB-free -- see docs/loaf-implementation-plan.md §2. Deliberately weight-aware from
 * day one (docs/loaf-phase2-plan.md §4/§2): every basic card has `counts_as_two: false`, so
 * weight is always 1 in practice until Phase 4 enables advanced cards, but building this
 * class against the real per-card weight now means Phase 4 doesn't need to revisit it.
 *
 * Deliberately stateless: callers pass in the current card types filed under each pile
 * (queried fresh from `round_card` each time) rather than this class tracking a running
 * total itself -- see docs/loaf-phase2-plan.md §4 for why a tracked global was rejected.
 */
final class EndConditionChecker
{
    public const CARDS_TO_END = 5;

    /**
     * @param string[] $cardTypes `card_type` values of every card currently filed under one
     *     boss pile (e.g. all cards in `review_happy`).
     * @param 'success'|'fail' $side Which side of each card applied when it was filed --
     *     always 'success' for cards in the Happy pile, always 'fail' for the Angry pile,
     *     since a card is only ever filed via the side that matched that round's result.
     */
    public static function weightedCount(array $cardTypes, string $side): int
    {
        $weight = 0;
        foreach ($cardTypes as $cardType) {
            $weight += RoundCardData::TYPES[$cardType]['review'][$side]['counts_as_two'] ? 2 : 1;
        }
        return $weight;
    }

    /**
     * @return 'happy'|'angry'|null Which boss pile has reached the end threshold, or null if
     *     neither has (yet). Uses >= rather than ===: a weight-2 card (Phase 4) can push a
     *     pile from below the threshold to past it in one step, not just onto it exactly.
     */
    public static function checkEnd(int $happyWeight, int $angryWeight): ?string
    {
        if ($happyWeight >= self::CARDS_TO_END) {
            return 'happy';
        }
        if ($angryWeight >= self::CARDS_TO_END) {
            return 'angry';
        }
        return null;
    }
}
