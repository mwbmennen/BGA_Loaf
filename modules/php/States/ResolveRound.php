<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\loaf\Core\ReputationTrack;
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
        $bossPile = $result->success ? 'review_happy' : 'review_angry';
        $this->game->roundCards->moveCard($reviewCardId, $bossPile);

        // No review-effect resolution yet -- ReviewEffectResolver is a later phase
        // (docs/loaf-phase1-plan.md). This just files the card and reports the outcome.
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

        return RoundStart::class;
    }
}
