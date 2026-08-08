<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\loaf\Core\EndConditionChecker;
use Bga\Games\loaf\Core\ReputationTrack;
use Bga\Games\loaf\Core\ReviewEffectResolver;
use Bga\Games\loaf\Core\RoundResolver;
use Bga\Games\loaf\Game;

class ResolveRound extends GameState
{
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

        $this->game->DbQuery("UPDATE `work_card` SET `location` = 'discard' WHERE `location` = 'played'");

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
        // docs/loaf-phase2-plan.md §3.1/§7. Only the basic 'reputation' effect type does
        // anything here; every advanced-only effect type is a deliberate no-op in
        // ReviewEffectResolver until Phase 4.
        $side = $result->success ? 'success' : 'fail';
        $reviewEffect = Game::$ROUND_CARD_TYPES[$reviewCardType]['review'][$side];

        $currentReputations = array_map('intval', $this->game->getCollectionFromDb(
            'SELECT `player_id` AS `id`, `player_reputation` FROM `player`',
            true
        ));
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

        // End condition: the real weighted boss-pile trigger, replacing Phase 1's placeholder
        // (deck-exhaustion) end condition -- docs/loaf-phase2-plan.md §4/§5. Recomputed fresh
        // from round_card every time rather than a tracked global, to avoid the
        // uninitialized-global bug class from Phase 1's live debugging (see
        // docs/bga-studio-reference.md §5). Every card in review_happy was filed via its own
        // success side, and every card in review_angry via its own fail side -- that's how
        // it got there -- so the $side argument below is fixed per pile, not per card.
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

        return $endTrigger !== null ? EndGame::class : RoundStart::class;
    }
}
