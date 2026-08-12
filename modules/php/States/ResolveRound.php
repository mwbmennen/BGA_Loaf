<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\loaf\Core\DiscardRecycleResolver;
use Bga\Games\loaf\Core\EndConditionChecker;
use Bga\Games\loaf\Core\ReputationTrack;
use Bga\Games\loaf\Core\ReviewEffectResolver;
use Bga\Games\loaf\Core\RoundResolver;
use Bga\Games\loaf\Core\SwapEffectResolver;
use Bga\Games\loaf\Core\TargetGroupResolver;
use Bga\Games\loaf\Game;

class ResolveRound extends GameState
{
    private const SWAP_EFFECTS = ['swap_discard_lower_by_at_most', 'swap_discard_higher_by_at_least'];

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: ST_RESOLVE_ROUND,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    public function onEnteringState() {
        $playedCards = $this->game->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `value` FROM `work_card` WHERE `location` = 'played'",
            true
        );
        $playedCards = array_map('intval', $playedCards);

        $orderAverage = (int) $this->game->bga->globals->get(GLOBAL_CURRENT_ORDER_AVERAGE, 0);
        $result = RoundResolver::resolve($orderAverage, $playedCards);

        foreach ($result->affectedPlayerIds as $playerId) {
            $currentReputation = (int) $this->game->getUniqueValueFromDb(
                "SELECT `player_reputation` FROM `player` WHERE `player_id` = $playerId"
            );
            $newReputation = ReputationTrack::adjust($currentReputation, $result->reputationDelta);

            $this->game->DbQuery(
                "UPDATE `player` SET `player_reputation` = $newReputation WHERE `player_id` = $playerId"
            );

            $this->game->bga->notify->all(
                'reputationChanged',
                clienttranslate('${player_name} moves ${delta} on the reputation track'),
                [
                    'player_id' => $playerId,
                    'player_name' => $this->game->getPlayerNameById($playerId),
                    'delta' => $result->reputationDelta,
                    'reputation' => $newReputation,
                ]
            );
        }

        $reviewCardId = (int) $this->game->bga->globals->get(GLOBAL_CURRENT_REVIEW_CARD_ID);
        $reviewCardType = $this->game->getUniqueValueFromDb(
            "SELECT `card_type` FROM `round_card` WHERE `card_id` = $reviewCardId"
        );
        $bossPile = $result->success ? 'review_happy' : 'review_angry';
        $this->game->roundCards->moveCard($reviewCardId, $bossPile);

        $this->game->bga->notify->all(
            'roundResolved',
            $result->success
                ? clienttranslate('Total ${total} (target ${target}): the bosses are happy!')
                : clienttranslate('Total ${total} (target ${target}): the bosses are disappointed.'),
            [
                'total' => $result->total,
                'target' => $result->target,
                'bossPile' => $result->success ? 'happy' : 'angry',
            ]
        );

        // Review effect: resolved after the round-total reputation delta above, using
        // reputations that already reflect it -- rulebook step ordering, see
        // docs/loaf-phase2-plan.md §3.1/§7. Moved ahead of the played->discard bulk move
        // (below) because the swap-effect branch needs to know the effect *before* that move
        // runs -- see docs/loaf-phase4-plan.md §4.
        $side = $result->success ? 'success' : 'fail';
        $reviewEffect = Game::$ROUND_CARD_TYPES[$reviewCardType]['review'][$side];

        $currentReputations = array_map('intval', $this->game->getCollectionFromDb(
            'SELECT `player_id` AS `id`, `player_reputation` FROM `player`',
            true
        ));

        // Swap effects ("take their played card back in hand, then discard a card ...") need
        // their played card to stay identifiable and movable, not folded into the general
        // discard pile below -- so any player who genuinely has an eligible card to discard
        // (checked now, against their hand as it stands with nothing yet returned) is excluded
        // from the bulk played->discard move and left with their card still in `location =
        // 'played'`. That's deliberately the *only* signal ResolveAdvancedEffect needs to find
        // its active players -- no new persisted global required (docs/loaf-phase4-plan.md
        // §4/§9). Players with no eligible discard use the rulebook's own deterministic
        // fallback ("if they can't, they discard the played card instead"), which is exactly
        // what the ordinary bulk move already does, so they're simply never deferred.
        $needsSwapChoicePlayerIds = [];
        if (in_array($reviewEffect['effect'], self::SWAP_EFFECTS, true)) {
            $swapTargetPlayerIds = TargetGroupResolver::playersInTarget($reviewEffect['target'], $currentReputations);
            foreach ($swapTargetPlayerIds as $playerId) {
                $playedValue = $playedCards[$playerId];
                $handValues = $this->handValues($playerId);
                $eligible = SwapEffectResolver::eligibleDiscards(
                    [...$handValues, $playedValue],
                    $playedValue,
                    $reviewEffect['amount'],
                    $reviewEffect['effect'],
                );
                if (!empty($eligible)) {
                    $needsSwapChoicePlayerIds[] = $playerId;
                }
            }
        }

        if (empty($needsSwapChoicePlayerIds)) {
            $this->game->DbQuery("UPDATE `work_card` SET `location` = 'discard' WHERE `location` = 'played'");
        } else {
            $this->game->DbQuery(
                "UPDATE `work_card` SET `location` = 'discard' WHERE `location` = 'played' AND `player_id` NOT IN (" .
                    implode(',', $needsSwapChoicePlayerIds) . ")"
            );
        }

        switch ($reviewEffect['effect']) {
            case 'reputation':
                $this->resolveReputationEffect($reviewEffect, $currentReputations);
                break;
            case 'discard_recycle_lowest':
                $this->resolveDiscardRecycle($reviewEffect, $currentReputations);
                break;
            // 'discard_choice' and the two swap effects need a player's own choice -- handled
            // by ResolveAdvancedEffect, entered below instead of resolved here. 'none' is a
            // deliberate no-op either way (only relevant for its counts_as_two flag).
        }

        // End condition: the real weighted boss-pile trigger, replacing Phase 1's placeholder
        // (deck-exhaustion) end condition -- docs/loaf-phase2-plan.md §4/§5. Recomputed fresh
        // from round_card every time rather than a tracked global, to avoid the
        // uninitialized-global bug class from Phase 1's live debugging (see
        // docs/bga-studio-reference.md §5). Every card in review_happy was filed via its own
        // success side, and every card in review_angry via its own fail side -- that's how
        // it got there -- so the $side argument below is fixed per pile, not per card.
        // Independent of the review effect above: discard/swap/recycle effects never touch a
        // boss pile, so this doesn't need to wait for ResolveAdvancedEffect either -- see that
        // state's own nextState() for why it duplicates this same computation.
        $happyCardTypes = $this->game->getObjectListFromDb(
            "SELECT `card_type` FROM `round_card` WHERE `card_location` = 'review_happy'",
            true
        );
        $angryCardTypes = $this->game->getObjectListFromDb(
            "SELECT `card_type` FROM `round_card` WHERE `card_location` = 'review_angry'",
            true
        );
        $endTrigger = EndConditionChecker::checkEnd(
            EndConditionChecker::weightedCount($happyCardTypes, 'success'),
            EndConditionChecker::weightedCount($angryCardTypes, 'fail'),
        );
        $nextState = $endTrigger !== null ? EndGame::class : RoundStart::class;

        $needsInteractiveResolution = $reviewEffect['effect'] === 'discard_choice'
            || !empty($needsSwapChoicePlayerIds);

        return $needsInteractiveResolution ? ResolveAdvancedEffect::class : $nextState;
    }

    private function resolveReputationEffect(array $reviewEffect, array $currentReputations): void
    {
        $effectResult = ReviewEffectResolver::resolve($reviewEffect, $currentReputations);

        foreach ($effectResult as $playerId => $newReputation) {
            $delta = $newReputation - $currentReputations[$playerId];

            $this->game->DbQuery(
                "UPDATE `player` SET `player_reputation` = $newReputation WHERE `player_id` = $playerId"
            );

            $this->game->bga->notify->all(
                'reputationChanged',
                clienttranslate('${player_name} moves ${delta} on the reputation track from the review effect'),
                [
                    'player_id' => $playerId,
                    'player_name' => $this->game->getPlayerNameById($playerId),
                    'delta' => $delta,
                    'reputation' => $newReputation,
                ]
            );
        }
    }

    /**
     * Fully deterministic (docs/Loaf-English-rules.md line 206) -- no interactive state
     * needed, unlike discard_choice/the two swap effects.
     */
    private function resolveDiscardRecycle(array $reviewEffect, array $currentReputations): void
    {
        $targetPlayerIds = TargetGroupResolver::playersInTarget($reviewEffect['target'], $currentReputations);

        $discardPiles = [];
        foreach ($targetPlayerIds as $playerId) {
            $discardPiles[$playerId] = array_map('intval', $this->game->getObjectListFromDb(
                "SELECT `value` FROM `work_card` WHERE `player_id` = $playerId AND `location` = 'discard'",
                true
            ));
        }

        $recycled = DiscardRecycleResolver::resolve($targetPlayerIds, $discardPiles);

        foreach ($recycled as $playerId => $value) {
            $this->game->DbQuery(
                "UPDATE `work_card` SET `location` = 'hand' WHERE `player_id` = $playerId AND `value` = $value"
            );

            $this->game->bga->notify->all(
                'cardRecycled',
                clienttranslate('${player_name} recycles their lowest discard-pile card back into hand'),
                [
                    'player_id' => $playerId,
                    'player_name' => $this->game->getPlayerNameById($playerId),
                ]
            );
        }
    }

    private function handValues(int $playerId): array
    {
        return array_map('intval', $this->game->getObjectListFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $playerId AND `location` = 'hand'",
            true
        ));
    }
}
