<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\loaf\Game;

class PlayCards extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: ST_PLAY_CARDS,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
        );
    }

    /**
     * Every player commits one work card from hand, face down. No turn order -- this is
     * fully simultaneous (docs/Loaf-English-rules.md, "Structure of a round").
     */
    public function getArgs(): array {
        $currentPlayerId = $this->game->getCurrentPlayerId();

        return [
            'handValues' => $this->game->getObjectListFromDb(
                "SELECT `value` FROM `work_card` WHERE `player_id` = $currentPlayerId AND `location` = 'hand' ORDER BY `value`",
                true
            ),
        ];
    }

    /**
     * @throws UserException
     */
    #[PossibleAction]
    public function actCommitCard(int $value, int $activePlayerId, array $args) {
        if (!in_array($value, $args['handValues'], true)) {
            throw new UserException('You do not have that work card in hand');
        }

        $this->game->DbQuery(
            "UPDATE `work_card` SET `location` = 'played' WHERE `player_id` = $activePlayerId AND `value` = $value"
        );

        // No card value leak -- other players only learn someone has committed, not what.
        $this->game->bga->notify->all(
            'playerCommitted',
            clienttranslate('${player_name} has committed their work card'),
            [
                'player_id' => $activePlayerId,
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
            ]
        );

        // Per BGA docs: deactivating the last active player in a MULTIPLE_ACTIVE_PLAYER state
        // auto-transitions to the given state (unverified locally, see
        // docs/loaf-phase1-plan.md's "Framework API confidence note").
        $this->game->gamestate->setPlayerNonMultiactive($activePlayerId, ResolveRound::class);
    }

    function zombie(int $playerId) {
        $args = $this->getArgs();
        $zombieChoice = $this->getRandomZombieChoice($args['handValues']);
        return $this->actCommitCard($zombieChoice, $playerId, $args);
    }
}
