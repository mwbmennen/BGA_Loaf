<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\loaf\Core\EndConditionChecker;
use Bga\Games\loaf\Core\SwapEffectResolver;
use Bga\Games\loaf\Core\TargetGroupResolver;
use Bga\Games\loaf\Game;

/**
 * Handles the three advanced review effects that need a player's own choice: `discard_choice`
 * and the two swap effects (docs/loaf-phase4-plan.md §4). `discard_recycle_lowest` and the
 * basic `reputation` effect are fully deterministic and are resolved inline by ResolveRound
 * instead -- they never transition here.
 */
class ResolveAdvancedEffect extends GameState
{
    private const SWAP_EFFECTS = ['swap_discard_lower_by_at_most', 'swap_discard_higher_by_at_least'];

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: ST_RESOLVE_ADVANCED_EFFECT,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
        );
    }

    /**
     * Active-player derivation differs by effect shape:
     * - Swap effects: ResolveRound already left exactly the players with a real choice to make
     *   (nonempty SwapEffectResolver::eligibleDiscards()) with their played card still sitting
     *   in `location = 'played'`, deferred from the round's usual played->discard bulk move
     *   specifically so this state needs no new persisted global to know who to activate.
     * - `discard_choice`: no card is deferred (the played card already went to discard as
     *   normal), so the target group is instead re-derived fresh here from RoundCardData +
     *   current reputations -- the same recompute-over-redundant-state pattern EndGame.php
     *   already uses for `$endingBoss`.
     *
     * `tests/stubs/BgaFrameworkStubs.php`'s `Gamestate` only confirms `setAllPlayersMultiactive()`
     * (used by PlayCards.php) and `setPlayerNonMultiactive()` -- no subset-activation method,
     * so activating only the real target group is done by activating everyone, then
     * immediately deactivating whoever isn't targeted, rather than guessing at an unconfirmed
     * `setPlayersMultiactive()`-style call.
     */
    public function onEnteringState() {
        $reviewEffect = $this->getReviewEffect();
        $activePlayerIds = $this->activePlayerIds($reviewEffect);

        if (empty($activePlayerIds)) {
            // Only reachable for discard_choice, if every target player's hand happened to
            // already be empty -- swap effects only transition into this state when
            // ResolveRound already confirmed at least one player has a real choice to make.
            return $this->nextState();
        }

        $this->game->gamestate->setAllPlayersMultiactive();

        $allPlayerIds = array_map('intval', $this->game->getObjectListFromDb(
            'SELECT `player_id` FROM `player`',
            true
        ));
        foreach (array_diff($allPlayerIds, $activePlayerIds) as $playerId) {
            $this->game->gamestate->setPlayerNonMultiactive($playerId, $this->nextState());
        }

        $this->game->bga->notify->all(
            'advancedEffectPending',
            $reviewEffect['effect'] === 'discard_choice'
                ? clienttranslate('${player_names} must each discard a card of their choice from their hand')
                : clienttranslate('${player_names} must take their played card back and discard another'),
            [
                'player_names' => implode(', ', array_map(
                    fn(int $id) => $this->game->getPlayerNameById($id),
                    $activePlayerIds
                )),
            ]
        );
    }

    /**
     * getArgs() runs once per state entry (and again on reconnect) -- see PlayCards.php's own
     * note on this. `effectType`/`amount` are public (they describe which card is resolving,
     * not any player's private hand/discard contents); `eligibleValues` is scoped per player
     * via `_private`, same hand-privacy discipline as PlayCards::getArgs().
     */
    public function getArgs(): array {
        $reviewEffect = $this->getReviewEffect();
        $activePlayerIds = $this->activePlayerIds($reviewEffect);

        $private = [];
        foreach ($activePlayerIds as $playerId) {
            $private[$playerId] = ['eligibleValues' => $this->eligibleValuesFor($playerId, $reviewEffect)];
        }

        return [
            'effectType' => $reviewEffect['effect'],
            'amount' => $reviewEffect['amount'],
            '_private' => $private,
            '_merge_private' => true,
        ];
    }

    /**
     * @throws UserException
     */
    #[PossibleAction]
    public function actDiscardChoice(int $value, int $currentPlayerId) {
        $reviewEffect = $this->getReviewEffect();
        if ($reviewEffect['effect'] !== 'discard_choice') {
            throw new UserException('Not a discard-choice effect');
        }
        if (!in_array($value, $this->handValues($currentPlayerId), true)) {
            throw new UserException('You do not have that work card in hand');
        }

        $this->game->DbQuery(
            "UPDATE `work_card` SET `location` = 'discard' WHERE `player_id` = $currentPlayerId AND `value` = $value"
        );

        $this->game->bga->notify->all(
            'playerDiscarded',
            clienttranslate('${player_name} discards a card from hand'),
            [
                'player_id' => $currentPlayerId,
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
            ]
        );

        $this->game->gamestate->setPlayerNonMultiactive($currentPlayerId, $this->nextState());
    }

    /**
     * @throws UserException
     */
    #[PossibleAction]
    public function actSwapDiscard(int $value, int $currentPlayerId) {
        $reviewEffect = $this->getReviewEffect();
        if (!in_array($reviewEffect['effect'], self::SWAP_EFFECTS, true)) {
            throw new UserException('Not a swap effect');
        }

        $playedValue = (int) $this->game->getUniqueValueFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $currentPlayerId AND `location` = 'played'"
        );
        $handAfterReturn = [...$this->handValues($currentPlayerId), $playedValue];

        // Never trust the client-supplied choice without validating it server-side -- same
        // discipline SwapEffectResolver::resolve()'s own docblock calls out. Its
        // InvalidArgumentException is re-thrown as a UserException so an invalid choice shows
        // the player a normal BGA error instead of a raw PHP exception, same UX PlayCards'
        // own in_array() pre-check gives actCommitCard().
        try {
            $discardValue = SwapEffectResolver::resolve(
                $handAfterReturn,
                $playedValue,
                $reviewEffect['amount'],
                $reviewEffect['effect'],
                $value,
            );
        } catch (\InvalidArgumentException $e) {
            throw new UserException('That card is not an eligible discard for this effect');
        }

        if ($discardValue === $playedValue) {
            // Deterministic fallback ("if they can't, they discard the played card instead") --
            // in practice unreachable here, since ResolveRound only defers players who already
            // have a nonempty eligible set, but handled defensively for the same reason
            // SwapEffectResolver::resolve() supports it at all.
            $this->game->DbQuery(
                "UPDATE `work_card` SET `location` = 'discard' WHERE `player_id` = $currentPlayerId AND `value` = $playedValue"
            );
        } else {
            $this->game->DbQuery(
                "UPDATE `work_card` SET `location` = 'hand' WHERE `player_id` = $currentPlayerId AND `value` = $playedValue"
            );
            $this->game->DbQuery(
                "UPDATE `work_card` SET `location` = 'discard' WHERE `player_id` = $currentPlayerId AND `value` = $discardValue"
            );
        }

        $this->game->bga->notify->all(
            'cardSwapped',
            clienttranslate('${player_name} takes their played card back and discards another'),
            [
                'player_id' => $currentPlayerId,
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
            ]
        );

        $this->game->gamestate->setPlayerNonMultiactive($currentPlayerId, $this->nextState());
    }

    function zombie(int $playerId) {
        $reviewEffect = $this->getReviewEffect();
        $choice = $this->getRandomZombieChoice($this->eligibleValuesFor($playerId, $reviewEffect));

        return $reviewEffect['effect'] === 'discard_choice'
            ? $this->actDiscardChoice($choice, $playerId)
            : $this->actSwapDiscard($choice, $playerId);
    }

    private function activePlayerIds(array $reviewEffect): array
    {
        if ($reviewEffect['effect'] === 'discard_choice') {
            $reputations = array_map('intval', $this->game->getCollectionFromDb(
                'SELECT `player_id` AS `id`, `player_reputation` FROM `player`',
                true
            ));
            $targetPlayerIds = TargetGroupResolver::playersInTarget($reviewEffect['target'], $reputations);

            return array_values(array_filter(
                $targetPlayerIds,
                fn(int $id) => !empty($this->handValues($id))
            ));
        }

        // Swap effects: exactly the players ResolveRound deferred, identifiable by their
        // played card still sitting in `location = 'played'` -- see this class's own docblock.
        return array_map('intval', $this->game->getObjectListFromDb(
            "SELECT DISTINCT `player_id` FROM `work_card` WHERE `location` = 'played'",
            true
        ));
    }

    private function eligibleValuesFor(int $playerId, array $reviewEffect): array
    {
        if ($reviewEffect['effect'] === 'discard_choice') {
            return $this->handValues($playerId);
        }

        $playedValue = (int) $this->game->getUniqueValueFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $playerId AND `location` = 'played'"
        );

        return SwapEffectResolver::eligibleDiscards(
            [...$this->handValues($playerId), $playedValue],
            $playedValue,
            $reviewEffect['amount'],
            $reviewEffect['effect'],
        );
    }

    private function handValues(int $playerId): array
    {
        return array_map('intval', $this->game->getObjectListFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $playerId AND `location` = 'hand'",
            true
        ));
    }

    /**
     * Re-derives which side of this round's review card resolved, the same way EndGame.php
     * re-derives $endingBoss -- no new persisted global needed, since `card_location` already
     * durably records it (docs/loaf-phase2-plan.md §7's "a card's pile fixes which side
     * resolved" note).
     */
    private function getReviewEffect(): array
    {
        $reviewCardId = (int) $this->game->bga->globals->get(GLOBAL_CURRENT_REVIEW_CARD_ID);
        $reviewCardType = $this->game->getUniqueValueFromDb(
            "SELECT `card_type` FROM `round_card` WHERE `card_id` = $reviewCardId"
        );
        $cardLocation = $this->game->getUniqueValueFromDb(
            "SELECT `card_location` FROM `round_card` WHERE `card_id` = $reviewCardId"
        );
        $side = $cardLocation === 'review_happy' ? 'success' : 'fail';

        return Game::$ROUND_CARD_TYPES[$reviewCardType]['review'][$side];
    }

    /**
     * Duplicates ResolveRound's own end-trigger computation rather than persisting the
     * decision across the state transition -- discard/swap effects never touch a boss pile, so
     * this is always safe to recompute fresh, same "no redundant state" discipline as
     * EndConditionChecker's other two call sites.
     */
    private function nextState(): string
    {
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
