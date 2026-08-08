<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\Games\loaf\Game;

const ST_END_GAME = 99;

class EndGame extends \Bga\GameFramework\States\GameState
{

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 98,
            type: StateType::GAME,
        );
    }

    /**
     * Reached once the round-card deck runs out (see RoundStart / docs/loaf-phase1-plan.md).
     *
     * TODO Phase 3: real ScoringCalculator (hand value + reputation bonus + fired-player
     * exclusion + tie-break by lowest reputation). For now `player_score` is left at its
     * default (0 for every player), a placeholder tie -- this phase only validates that the
     * round loop terminates correctly, not real scoring.
     */
    public function onEnteringState() {
        return ST_END_GAME;
    }
}
