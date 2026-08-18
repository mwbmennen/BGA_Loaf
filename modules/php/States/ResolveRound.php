<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\loaf\Core\DiscardRecycleResolver;
use Bga\Games\loaf\Core\EndConditionChecker;
use Bga\Games\loaf\Core\ReputationTrack;
use Bga\Games\loaf\Core\ReviewEffectDescription;
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

        // Public log visibility for checking round-by-round calculations by hand -- what a
        // player played this round is open information once the round resolves (confirmed --
        // this is distinct from ongoing discard-pile *state* staying hidden per
        // docs/loaf-open-questions.md Q3, which is about getAllDatas()/live state, not a
        // one-time game-log announcement at resolution time; that rule is untouched). Emitted
        // before any reputationChanged notification below so the log reads cause-then-effect --
        // "played 5" precedes "moves +2 on the reputation track" instead of the other way
        // around. No reputation value here: it isn't final yet (round-result delta and any
        // review effect both still need to run), and the reputationChanged messages that follow
        // carry the up-to-date numbers.
        foreach ($playedCards as $playerId => $value) {
            $this->game->bga->notify->all(
                'cardPlayedRevealed',
                clienttranslate('${player_name} played ${value}'),
                [
                    'player_id' => $playerId,
                    'player_name' => $this->game->getPlayerNameById($playerId),
                    'value' => $value,
                ]
            );
        }
        // From this point on, every currently-`played` card is public -- see
        // GLOBAL_CARDS_REVEALED_THIS_ROUND's own comment (constants.inc.php). Set right after
        // the reveal notifications above, not before -- a client reconnecting between those two
        // moments (implausible in practice given both happen in the same request, but cheap to
        // get the ordering right) should see the same "not yet revealed" placeholder a client
        // connected throughout would have seen at that instant.
        $this->game->bga->globals->set(GLOBAL_CARDS_REVEALED_THIS_ROUND, true);

        $orderAverage = (int) $this->game->bga->globals->get(GLOBAL_CURRENT_ORDER_AVERAGE, 0);
        $result = RoundResolver::resolve($orderAverage, $playedCards);

        // Review card outcome (which pile it moves to, and the effect that resolved) is
        // determined here, ahead of the reputationChanged loop below, purely so 'roundResolved'
        // can be notified before it -- neither this lookup nor the moveCard() below reads or
        // writes reputation, so there's no ordering hazard in pulling it earlier. The review
        // *effect's application* (resolveReputationEffect/discard_recycle/etc., in the switch
        // below) still runs later, after $currentReputations is queried post-loop, per rulebook
        // step ordering (docs/loaf-phase2-plan.md §3.1/§7) -- only the notification order moved
        // up, not the effect's own resolution order. Also still needed ahead of the
        // played->discard bulk move further down, since the swap-effect branch needs to know
        // the effect before that move runs (docs/loaf-phase4-plan.md §4).
        $reviewCardId = (int) $this->game->bga->globals->get(GLOBAL_CURRENT_REVIEW_CARD_ID);
        $reviewCardType = $this->game->getUniqueValueFromDb(
            "SELECT `card_type` FROM `round_card` WHERE `card_id` = $reviewCardId"
        );
        $bossPile = $result->success ? 'review_happy' : 'review_angry';
        $this->game->roundCards->moveCard($reviewCardId, $bossPile);

        $side = $result->success ? 'success' : 'fail';
        $reviewEffect = Game::$ROUND_CARD_TYPES[$reviewCardType]['review'][$side];

        $this->game->bga->notify->all(
            'roundResolved',
            $result->success
                ? clienttranslate('Total ${total} (target ${target}): the bosses are happy!')
                : clienttranslate('Total ${total} (target ${target}): the bosses are disappointed.'),
            [
                'total' => $result->total,
                'target' => $result->target,
                'bossPile' => $result->success ? 'happy' : 'angry',
                // The client's boss-pile counter must increment by this, not always 1 -- a
                // counts_as_two card is worth 2 toward EndConditionChecker's weighted trigger,
                // and the displayed count needs to match what actually ends the game
                // (docs/loaf-remarks.md's Phase 4 entry -- confirmed live the counter under-
                // reported by exactly 1 when this was still hardcoded).
                'weight' => $reviewEffect['counts_as_two'] ? 2 : 1,
                // So the client can move the actual card (not just bump a counter) from the
                // pending-review display into the correct boss pile -- docs/loaf-phase5-plan.md §7.
                'reviewCardId' => $reviewCardId,
                'reviewCardType' => $reviewCardType,
            ]
        );

        // Emitted after 'roundResolved' (swapped from the reverse order per feature request) so
        // the log reads "here's the round outcome" before "here's how it moved your reputation".
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

        // Unlike RoundStart's 'reviewCardRevealed' (speculative -- describes both sides before
        // either has happened), this describes only the side that actually just resolved --
        // the round's success/fail above already fixed which one. Reuses the exact same
        // target/amount text as the reveal message (ReviewEffectDescription), so a tester can
        // compare "what was promised" against "what happened" using identical wording.
        $this->game->bga->notify->all(
            'reviewEffectApplied',
            clienttranslate('Review effect: ${target}, ${amount}'),
            [
                'target' => ReviewEffectDescription::target($reviewEffect),
                'amount' => ReviewEffectDescription::amount($reviewEffect, $side),
            ]
        );

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

            // Separate player-scoped notification (not folded into the broadcast one above) so
            // the client can actually render the recycled card in this player's real HandStock
            // (docs/loaf-phase5-plan.md §9) -- the broadcast notification deliberately omits
            // `value`, since discard-pile contents are private per docs/loaf-open-questions.md
            // Q3 and notify->all's payload is visible to every connected client regardless of
            // whether the message text references it. First use of notify->player() in this
            // project -- already stubbed in tests/stubs/BgaFrameworkStubs.php but unverified
            // live, per docs/loaf-phase1-plan.md's "Framework API confidence note".
            $this->game->bga->notify->player(
                $playerId,
                'cardRecycledValue',
                clienttranslate('You recycle ${value} back into hand'),
                ['value' => $value]
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
