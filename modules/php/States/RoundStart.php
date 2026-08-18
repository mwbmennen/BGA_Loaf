<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\loaf\Core\ReviewEffectDescription;
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

        // Reset before this round's own commits happen -- see GLOBAL_CARDS_REVEALED_THIS_ROUND's
        // own comment (constants.inc.php) for why getAllDatas() needs this at all.
        $this->game->bga->globals->set(GLOBAL_CARDS_REVEALED_THIS_ROUND, false);

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
            clienttranslate('Review card revealed: on success, ${successTarget}, ${successAmount}; on fail, ${failTarget}, ${failAmount}'),
            [
                // card_type (not just descriptive text) so the client can render the card's
                // real art -- docs/loaf-phase5-plan.md §7's pending-review-card display.
                'reviewCardId' => $reviewCard['id'],
                'reviewCardType' => $reviewCard['type'],
                'successTarget' => ReviewEffectDescription::target($review['success']),
                'successAmount' => ReviewEffectDescription::amount($review['success'], 'success'),
                'failTarget' => ReviewEffectDescription::target($review['fail']),
                'failAmount' => ReviewEffectDescription::amount($review['fail'], 'fail'),
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
                // Same reasoning as reviewCardId/reviewCardType above -- the pending order-card
                // display needs real art, not just the target number.
                'orderCardId' => $orderCard['id'],
                'orderCardType' => $orderCard['type'],
                // Client's notif_roundStart rebuilds pendingReviewStock from scratch every round
                // (Game.js) and needs these two fields to do it -- 'reviewCardRevealed' above
                // carries the same reviewCardId/reviewCardType but has no client handler wired to
                // the stock, so this notification is the only one the client actually consumes for
                // the pending-review-card display. Omitting them left `card.type` undefined from
                // round 2 onward, silently wrong before the hover-tooltip feature and a hard
                // TypeError (`roundCardDescriptions[undefined].fail`) after it -- caught live.
                'reviewCardId' => $reviewCard['id'],
                'reviewCardType' => $reviewCard['type'],
            ]
        );

        return PlayCards::class;
    }
}
