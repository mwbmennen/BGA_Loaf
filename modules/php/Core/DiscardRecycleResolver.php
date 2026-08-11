<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Resolves the advanced `discard_recycle_lowest` effect: for each targeted player, their
 * lowest-value discard-pile card returns to their hand. Fully deterministic -- no player
 * choice involved (docs/Loaf-English-rules.md line 206: "take the lowest value card from
 * their discard pile back into their hand. If your discard pile is empty, this has no
 * effect."). Pure/DB-free -- see docs/loaf-implementation-plan.md §2.
 */
final class DiscardRecycleResolver
{
    /**
     * @param int[] $targetPlayerIds Player IDs in the effect's target group (from
     *     TargetGroupResolver).
     * @param array<int, int[]> $discardPiles player_id => their discard-pile card values.
     * @return array<int, int> player_id => card value recycled back to hand. Players whose
     *     discard pile was empty (or missing from $discardPiles) are absent from the result,
     *     not included with a null/0 value -- same "absent means no-op" convention as
     *     ReviewEffectResolver::resolve().
     */
    public static function resolve(array $targetPlayerIds, array $discardPiles): array
    {
        $result = [];
        foreach ($targetPlayerIds as $playerId) {
            $pile = $discardPiles[$playerId] ?? [];
            if (!empty($pile)) {
                $result[$playerId] = min($pile);
            }
        }
        return $result;
    }
}
