<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\loaf\Game;

class RoundStart extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: ST_ROUND_START,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    /**
     * Automatic state: advance the shared round-card window. Every round draws the top card
     * as this round's review card (filed under a Boss pile once ResolveRound knows this
     * round's success/fail), then reads the target from whichever card is newly exposed on
     * top, still undrawn -- see docs/loaf-phase1-plan.md's "flip mechanic" section for why
     * this needs at least 2 cards left in the deck.
     */
    public function onEnteringState() {
        if ($this->game->roundCards->countCardsInLocation('deck') < 2) {
            return EndGame::class;
        }

        // Deck component ordering is unverified locally (see docs/loaf-phase1-plan.md's
        // "Framework API confidence note") -- sort by `location_arg` ourselves rather than
        // trust an unconfirmed default row order, assuming ascending `location_arg` = draw
        // order (lowest = next to draw), the standard Deck component convention.
        $deckCards = $this->game->roundCards->getCardsInLocation('deck');
        usort($deckCards, fn(array $a, array $b) => $a['location_arg'] <=> $b['location_arg']);

        $reviewCard = array_shift($deckCards);
        $this->game->roundCards->moveCard($reviewCard['id'], 'revealed_review');
        $this->game->bga->globals->set(GLOBAL_CURRENT_REVIEW_CARD_ID, $reviewCard['id']);

        $review = Game::$ROUND_CARD_TYPES[$reviewCard['type']]['review'];
        $this->game->bga->notify->all(
            'reviewCardRevealed',
            clienttranslate('Review card revealed: on success, ${successTarget} ${successAmount} reputation; on fail, ${failTarget} ${failAmount} reputation'),
            [
                'successTarget' => $this->describeReviewTarget($review['success']),
                'successAmount' => $this->describeReviewAmount($review['success']),
                'failTarget' => $this->describeReviewTarget($review['fail']),
                'failAmount' => $this->describeReviewAmount($review['fail']),
            ]
        );

        $orderCard = $deckCards[0];
        $orderAverage = Game::$ROUND_CARD_TYPES[$orderCard['type']]['order']['per_player_average'];
        $this->game->bga->globals->set(GLOBAL_CURRENT_ORDER_AVERAGE, $orderAverage);

        $currentRound = (int) $this->game->bga->globals->inc(GLOBAL_CURRENT_ROUND, 1);

        $this->game->bga->notify->all(
            'roundStart',
            clienttranslate('Round ${round}: bosses expect a total of at least ${target} work per player'),
            [
                'round' => $currentRound,
                'target' => $orderAverage,
            ]
        );

        return PlayCards::class;
    }

    /**
     * Plain-text label for a review effect's `target`, for the log-only 'reviewCardRevealed'
     * notification -- Phase 2 has no card art yet (see modules/js/Game.js's Phase 5 TODO), so
     * this is the only place a tester can currently see what a review card does. Only the
     * basic `reputation` effect is wired up (advanced effects are Phase 4 no-ops in
     * ReviewEffectResolver), but every `target` value from docs/loaf-card-data.json's effect
     * schema is covered so this doesn't go blank if an advanced card is ever drawn early.
     */
    private function describeReviewTarget(array $effect): string
    {
        return match ($effect['target']) {
            'lowest_reputation' => clienttranslate('the lowest-reputation player(s)'),
            'highest_reputation' => clienttranslate('the highest-reputation player(s)'),
            'reputation_positive' => clienttranslate('every player with positive reputation'),
            'reputation_negative' => clienttranslate('every player with negative reputation'),
            'reputation_zero' => clienttranslate('every player at zero reputation'),
            'all' => clienttranslate('every player'),
            default => clienttranslate('no one'),
        };
    }

    private function describeReviewAmount(array $effect): string
    {
        if ($effect['effect'] !== 'reputation') {
            return clienttranslate('(advanced effect, not yet implemented)');
        }

        return sprintf('%+d', $effect['amount']);
    }
}
