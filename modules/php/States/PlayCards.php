<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\NotificationMessage;
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
     * several simultaneously-active players -- it doesn't activate anyone by itself. Without
     * this call, nobody is marked active on entry and nobody gets action buttons at all.
     */
    public function onEnteringState() {
        $this->game->gamestate->setAllPlayersMultiactive();
    }

    /**
     * Every player commits one work card from hand, face down. No turn order -- this is
     * fully simultaneous.
     *
     * No getArgs() override here: an earlier version exposed each player's hand values via
     * BGA's `_private`/`_merge_private` mechanism specifically to build a status-bar
     * action-button list client-side. A later version replaced that button list with a real
     * `HandStock` seeded from `getAllDatas()`'s own `myHand` (Game.php) instead -- the same
     * data by a different, already-existing route -- so a per-state-entry `_private`
     * round-trip of the same values became redundant. Removed rather than left as unread dead
     * plumbing.
     *
     * array_map('intval', ...) matters here: getObjectListFromDb() returns raw DB values as
     * strings, but actCommitCard() compares against this with in_array(..., true) (strict) --
     * without casting, "3" !== 3 and every commit fails with "You do not have that work card
     * in hand" regardless of what's actually in the player's hand. Same cast this codebase
     * already applies in ResolveRound.php for the same reason.
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
     * framework's injected `array $args` magic parameter to carry getArgs()'s `_private` data
     * through unwrapped -- it doesn't (`_merge_private` only affects what the client receives,
     * not what's injected into the action handler).
     *
     * Uses `$currentPlayerId`, not `$activePlayerId`. Per BGA's own docs, `$activePlayerId` is
     * "not necessarily the one triggering the action" and is only reliable on `ACTIVE_PLAYER`
     * states; on `MULTIPLE_ACTIVE_PLAYER` states like this one it silently resolves to player
     * id 0, so every check/update/notification below would silently target a nonexistent
     * player. `$currentPlayerId` -- "the player who triggered the action" -- is the one that's
     * actually reliable here.
     *
     * @throws UserException
     */
    #[PossibleAction]
    public function actCommitCard(int $value, int $currentPlayerId) {
        if (!in_array($value, $this->getHandValues($currentPlayerId), true)) {
            throw new UserException(clienttranslate('You do not have that work card in hand'));
        }

        // New under the commit/cancel redesign: a committed player is no longer deactivated
        // (see below), so without this guard they could call actCommitCard a second time
        // instead of going through actCancelCommit first -- cancel is meant to be the only way
        // to change a commitment once made.
        if ($this->game->getUniqueValueFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $currentPlayerId AND `location` = 'played'"
        ) !== null) {
            throw new UserException(clienttranslate('You have already committed a work card this round'));
        }

        // Committing doesn't end this player's turn -- they stay multiactive either way (see
        // this method's own comment on allPlayersCommitted()), so per BGA's own docs this is
        // exactly the "action which does not lead to turn end" case giveExtraTime is for.
        $this->game->giveExtraTime($currentPlayerId);

        $this->game->DbQuery(
            "UPDATE `work_card` SET `location` = 'played' WHERE `player_id` = $currentPlayerId AND `value` = $value"
        );

        // No card value leak to OTHER players -- but the acting player's own client gets a
        // substituted private message via `_private`/`_merge_private` (one notification, one
        // log line per recipient -- not a second line stacked under the public one; the
        // framework replaces the message+args entirely for whichever player id has a
        // `_private` entry, and guarantees no other player's client ever receives that entry
        // at all). Needed for PlayCards::zombie()'s auto-commit, which never runs this
        // player's own onCommit() click handler client-side, so Game.js's pendingCommitCard
        // (normally set at click-time) would otherwise stay unset and the card would silently
        // never leave their hand display. `_merge_private` puts
        // `value` directly on this player's own `args` (vs. nested under `args._private`) so
        // notif_playerCommitted can read `args.value` directly.
        $this->game->bga->notify->all(
            'playerCommitted',
            clienttranslate('${player_name} has committed their work card'),
            [
                'player_id' => $currentPlayerId,
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
                '_private' => [
                    $currentPlayerId => new NotificationMessage(
                        clienttranslate('You commit ${value}'),
                        ['value' => $value]
                    ),
                ],
                '_merge_private' => true,
            ]
        );

        // Cancel (actCancelCommit) needs a committed player to still be able to act, so nobody
        // is deactivated individually anymore -- completion is instead detected explicitly and
        // everyone is deactivated together, in one batch, only once the round is genuinely
        // done (looping setPlayerNonMultiactive() across every player within a single request
        // auto-transitions cleanly on the last call). If a future BGA
        // framework change ever breaks this, the fallback is a single explicit
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
     * defense-in-depth, not the primary gate), and only if this table's
     * OPTION_ALLOW_CANCEL_COMMIT option is on. Checked here, server-side, not just hidden
     * client-side (PlayCards.js only offers the Cancel button when the option is on) -- the
     * client-side gate alone wouldn't stop a request crafted directly against the action.
     *
     * @throws UserException
     */
    #[PossibleAction]
    public function actCancelCommit(int $currentPlayerId) {
        if (!$this->game->cancelCommitAllowed()) {
            throw new UserException(clienttranslate('Cancelling a committed card is not allowed in this game'));
        }

        $playedValue = $this->game->getUniqueValueFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $currentPlayerId AND `location` = 'played'"
        );
        if ($playedValue === null) {
            throw new UserException(clienttranslate('You have not committed a work card this round'));
        }
        // Defense-in-depth: by construction unreachable once allPlayersCommitted() is true
        // (this state has already transitioned away by the same actCommitCard() call that made
        // it true -- see actCommitCard's own comment) -- kept explicit rather than assumed
        // anyway, since a defensive check here costs nothing.
        if ($this->allPlayersCommitted()) {
            throw new UserException(clienttranslate('Every player has already committed -- you can no longer cancel'));
        }

        // Cancelling never ends this player's turn -- they always stay multiactive afterward,
        // waiting to recommit. Same giveExtraTime rationale as actCommitCard() above.
        $this->game->giveExtraTime($currentPlayerId);

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
     * A zombie player MUST end up deactivated as a direct result of this call, regardless of
     * whether everyone else has committed yet -- leaving them active (the normal outcome of
     * actCommitCard for any *real* player, so a connected player can still cancel) throws
     * `Bga\Exceptions\Framework\ZombieStateException` the one time this zombied player wasn't
     * also the last one needed to complete the round. BGA's own
     * zombie dispatch requires the specific player it just zombied to no longer be counted
     * active afterward -- it can't tell "this player is fine to stay active and idle" apart
     * from "zombie handling failed to make progress for them". A zombie player also has no
     * further use for the ability to cancel anyway (nobody's there to press the button), so
     * losing it here is the right tradeoff, not a compromise.
     *
     * Handles both cases uniformly: not yet committed (auto-commits via actCommitCard first)
     * and already committed (an earlier real commit, now zombied while just waiting).
     * `allPlayersCommitted()` is checked *after* the conditional commit, not before -- if this
     * zombie's own commit happened to be the last one needed, actCommitCard's own batch-
     * completion branch already deactivated every player, including this one, moments ago;
     * deactivating them again here is skipped rather than assumed harmless, since
     * setPlayerNonMultiactive's behavior on an already-inactive player isn't verified.
     */
    function zombie(int $playerId) {
        $hasPlayedCard = $this->game->getUniqueValueFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $playerId AND `location` = 'played'"
        ) !== null;

        if (!$hasPlayedCard) {
            $zombieChoice = $this->getRandomZombieChoice($this->getHandValues($playerId));
            $this->actCommitCard($zombieChoice, $playerId);
        }

        if (!$this->allPlayersCommitted()) {
            $this->game->gamestate->setPlayerNonMultiactive($playerId, ResolveRound::class);
        }
    }
}
