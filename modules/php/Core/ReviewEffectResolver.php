<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

use InvalidArgumentException;

/**
 * Resolves a review card's basic `effect: 'reputation'` type against the current reputation
 * track -- the only effect type any basic card uses (docs/loaf-phase2-plan.md §2). Every
 * other effect type is a deliberate no-op *here* -- discard/swap/end-game effects have their
 * own dedicated resolvers (docs/loaf-phase4-plan.md §9 step 1), since their return shapes
 * (which card to move, which players get a bonus) don't fit this class's "player_id => new
 * reputation" contract. Target-group matching (`highest_reputation` etc.) is delegated to
 * TargetGroupResolver, shared with those other resolvers. Pure/DB-free -- see
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

        $targetPlayerIds = TargetGroupResolver::playersInTarget($effect['target'], $reputations);

        $result = [];
        foreach ($targetPlayerIds as $playerId) {
            $result[$playerId] = ReputationTrack::adjust($reputations[$playerId], $effect['amount']);
        }
        return $result;
    }
}
