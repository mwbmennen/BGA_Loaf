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

    private function allPlayerIds(): array {
        return array_map('intval', $this->game->getObjectListFromDb('SELECT `player_id` FROM `player`', true));
    }

    /**
     * "Has every seated player committed a card this round" -- the explicit completion check
     * that replaces the old one-deactivation-per-commit auto-transition (see actCommitCard's
     * own comment for why: cancel requires committed players to stay active, which rules out
     * deactivating them individually).
     */
    private function allPlayersCommitted(): bool {
        $committedCount = (int) $this->game->getUniqueValueFromDb(
            "SELECT COUNT(DISTINCT `player_id`) FROM `work_card` WHERE `location` = 'played'"
        );
        return $committedCount > 0 && $committedCount === count($this->allPlayerIds());
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

        // New under the commit/cancel redesign: a committed player is no longer deactivated
        // (see below), so without this guard they could call actCommitCard a second time
        // instead of going through actCancelCommit first -- cancel is meant to be the only way
        // to change a commitment once made.
        if ($this->game->getUniqueValueFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $currentPlayerId AND `location` = 'played'"
        ) !== null) {
            throw new UserException('You have already committed a work card this round');
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

        // Cancel (actCancelCommit) needs a committed player to still be able to act, so nobody
        // is deactivated individually anymore (the old single setPlayerNonMultiactive() call
        // right here, per commit, is gone) -- completion is instead detected explicitly and
        // everyone is deactivated together, in one batch, only once the round is genuinely
        // done. Reuses the same primitive the old code already relied on (per BGA docs,
        // unverified locally, see docs/loaf-phase1-plan.md's "Framework API confidence note"),
        // just called once per player here instead of once per commit.
        //
        // Confirmed live (2026-09-25): looping setPlayerNonMultiactive() across every player
        // within a single request -- all of them still active going into the loop -- does
        // auto-transition cleanly on the last call, the same way the old one-call version did.
        // If a future BGA framework change ever breaks this, the fallback is a single explicit
        // `$this->game->gamestate->nextState(ResolveRound::class)` call instead of the loop.
        if ($this->allPlayersCommitted()) {
            foreach ($this->allPlayerIds() as $playerId) {
                $this->game->gamestate->setPlayerNonMultiactive($playerId, ResolveRound::class);
            }
        }
    }

    /**
     * Takes a committed card back to hand -- only possible while at least one other player
     * still hasn't committed (once everyone has, this state has already transitioned away, so
     * the action isn't reachable at all; the allPlayersCommitted() check below is
     * defense-in-depth, not the primary gate).
     *
     * @throws UserException
     */
    #[PossibleAction]
    public function actCancelCommit(int $currentPlayerId) {
        $playedValue = $this->game->getUniqueValueFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $currentPlayerId AND `location` = 'played'"
        );
        if ($playedValue === null) {
            throw new UserException('You have not committed a work card this round');
        }
        // Defense-in-depth: by construction unreachable once allPlayersCommitted() is true
        // (this state has already transitioned away by the same actCommitCard() call that made
        // it true, confirmed live -- see actCommitCard's own comment) -- kept explicit rather
        // than assumed anyway, since a defensive check here costs nothing.
        if ($this->allPlayersCommitted()) {
            throw new UserException('Every player has already committed -- you can no longer cancel');
        }

        $this->game->DbQuery(
            "UPDATE `work_card` SET `location` = 'hand' WHERE `player_id` = $currentPlayerId AND `value` = " . (int) $playedValue
        );

        // Mirrors playerCommitted's own privacy stance -- no card value leak to other players.
        $this->game->bga->notify->all(
            'playerCancelledCommit',
            clienttranslate('${player_name} cancelled their committed work card'),
            [
                'player_id' => $currentPlayerId,
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
            ]
        );
    }

    /**
     * Idempotent: under the old design a player was deactivated the instant they committed, so
     * the framework had no reason to call zombie() for them again. Under the new design a
     * committed player stays active for the rest of this state (so they can still cancel),
     * which makes a second zombie() call for an already-committed player unverified -- this
     * guard makes that harmless either way, and a zombie must never cancel.
     */
    function zombie(int $playerId) {
        if ($this->game->getUniqueValueFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $playerId AND `location` = 'played'"
        ) !== null) {
            return;
        }
        $zombieChoice = $this->getRandomZombieChoice($this->getHandValues($playerId));
        return $this->actCommitCard($zombieChoice, $playerId);
    }
}
