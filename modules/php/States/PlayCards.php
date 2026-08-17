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
     * type: StateType::MULTIPLE_ACTIVE_PLAYER only declares that this state *permits*
     * several simultaneously-active players -- it doesn't activate anyone by itself.
     * Confirmed live (2026-08-08): without this call, nobody is marked active on entry, every
     * connecting player sees "Waiting for other players to commit a work card", and nobody
     * gets action buttons at all.
     */
    public function onEnteringState() {
        $this->game->gamestate->setAllPlayersMultiactive();
    }

    /**
     * Every player commits one work card from hand, face down. No turn order -- this is
     * fully simultaneous (docs/Loaf-English-rules.md, "Structure of a round").
     *
     * No getArgs() override here: an earlier version exposed each player's hand values via
     * BGA's `_private`/`_merge_private` mechanism specifically to build a status-bar
     * action-button list client-side. Phase 5 §8 replaced that button list with a real
     * `HandStock` seeded from `getAllDatas()`'s own `myHand` (Game.php) instead -- the same
     * data by a different, already-existing route -- so a per-state-entry `_private`
     * round-trip of the same values became redundant. Removed rather than left as unread dead
     * plumbing.
     *
     * array_map('intval', ...) matters here: getObjectListFromDb() returns raw DB values as
     * strings, but actCommitCard() compares against this with in_array(..., true) (strict) --
     * without casting, "3" !== 3 and every commit fails with "You do not have that work card
     * in hand" regardless of what's actually in the player's hand (confirmed live
     * 2026-08-08). Same cast this codebase already applies in ResolveRound.php for the same
     * reason.
     */
    private function getHandValues(int $playerId): array {
        return array_map('intval', $this->game->getObjectListFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $playerId AND `location` = 'hand' ORDER BY `value`",
            true
        ));
    }

    /**
     * Deliberately re-fetches the acting player's hand directly rather than trusting the
     * framework's injected `array $args` magic parameter to carry getArgs()'s `_private`
     * data through unwrapped -- confirmed live (2026-08-08) that it doesn't: `$args` here
     * came back without a `handValues` key at all (`_merge_private` only affects what the
     * client receives, not what's injected into the action handler).
     *
     * Uses `$currentPlayerId`, not `$activePlayerId`. Per BGA's own docs, `$activePlayerId`
     * is "not necessarily the one triggering the action" and is only reliable on
     * `ACTIVE_PLAYER` states; on `MULTIPLE_ACTIVE_PLAYER` states like this one it silently
     * resolved to player id 0 (confirmed live via a stack trace showing
     * `actCommitCard(3, 0, ...)`), so every check/update/notification below was silently
     * targeting a nonexistent player. `$currentPlayerId` -- "the player who triggered the
     * action" -- is the one that's actually reliable here.
     *
     * @throws UserException
     */
    #[PossibleAction]
    public function actCommitCard(int $value, int $currentPlayerId) {
        if (!in_array($value, $this->getHandValues($currentPlayerId), true)) {
            throw new UserException('You do not have that work card in hand');
        }

        $this->game->DbQuery(
            "UPDATE `work_card` SET `location` = 'played' WHERE `player_id` = $currentPlayerId AND `value` = $value"
        );

        // No card value leak -- other players only learn someone has committed, not what.
        $this->game->bga->notify->all(
            'playerCommitted',
            clienttranslate('${player_name} has committed their work card'),
            [
                'player_id' => $currentPlayerId,
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
            ]
        );

        // Per BGA docs: deactivating the last active player in a MULTIPLE_ACTIVE_PLAYER state
        // auto-transitions to the given state (unverified locally, see
        // docs/loaf-phase1-plan.md's "Framework API confidence note").
        $this->game->gamestate->setPlayerNonMultiactive($currentPlayerId, ResolveRound::class);
    }

    function zombie(int $playerId) {
        $zombieChoice = $this->getRandomZombieChoice($this->getHandValues($playerId));
        return $this->actCommitCard($zombieChoice, $playerId);
    }
}
