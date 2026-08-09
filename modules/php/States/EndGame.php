<?php

declare(strict_types=1);

namespace Bga\Games\loaf\States;

use Bga\GameFramework\StateType;
use Bga\Games\loaf\Core\EndConditionChecker;
use Bga\Games\loaf\Core\ScoringCalculator;
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
     * Reached once EndConditionChecker's weighted boss-pile trigger fires in ResolveRound (or,
     * defensively, if the deck ever runs out first -- see RoundStart /
     * docs/loaf-phase2-plan.md §5). Runs ScoringCalculator (docs/loaf-phase3-plan.md §7): hand
     * value + reputation bonus + fired-player exclusion + tie-break by lowest reputation,
     * mapped onto BGA's native player_score/player_score_aux ranking.
     */
    public function onEnteringState() {
        // Re-derive which boss ended the game -- no new global needed, same query shape
        // ResolveRound.php already uses each round (docs/loaf-phase2-plan.md §7).
        $happyCardTypes = $this->game->getObjectListFromDb(
            "SELECT `card_type` FROM `round_card` WHERE `card_location` = 'review_happy'",
            true
        );
        $angryCardTypes = $this->game->getObjectListFromDb(
            "SELECT `card_type` FROM `round_card` WHERE `card_location` = 'review_angry'",
            true
        );
        $endingBoss = EndConditionChecker::checkEnd(
            EndConditionChecker::weightedCount($happyCardTypes, 'success'),
            EndConditionChecker::weightedCount($angryCardTypes, 'fail'),
        );
        // Non-null is guaranteed here: ResolveRound only ever transitions to EndGame::class
        // when checkEnd() already returned non-null against these same piles -- nothing files
        // a card between that check and this state being entered.

        $reputations = array_map('intval', $this->game->getCollectionFromDb(
            'SELECT `player_id` AS `id`, `player_reputation` FROM `player`',
            true
        ));

        $handValues = array_map('intval', $this->game->getCollectionFromDb(
            "SELECT `player_id` AS `id`, COALESCE(SUM(`value`), 0) AS `total` FROM `work_card` " .
                "WHERE `location` = 'hand' GROUP BY `player_id`",
            true
        ));
        // A player with zero cards left in hand has no GROUP BY row at all -- fill the gap
        // explicitly. Not actually reachable under basic-only play (the game always ends by
        // round 9 at the latest per docs/loaf-phase2-plan.md §5's pigeonhole proof, and every
        // hand starts with 12 cards), kept for free correctness against future rule changes.
        foreach (array_keys($reputations) as $playerId) {
            $handValues[$playerId] ??= 0;
        }

        // Always zero until Phase 4 populates this from resolved end_game_bonus/malus/double_*
        // effects instead (docs/loaf-phase3-plan.md §2).
        $bonusPoints = array_fill_keys(array_keys($reputations), 0);

        $scores = ScoringCalculator::score($handValues, $reputations, $bonusPoints, $endingBoss);

        // Hand privacy protects the simultaneous-play mechanic while the game is running
        // (docs/loaf-open-questions.md Q3) -- it serves no purpose once the game has ended,
        // since every player's final score is about to become public anyway. Revealing what
        // each score is made of lets players (and testers) verify it instead of trusting an
        // opaque number, same "surface hidden state via the log" pattern as the
        // reviewCardRevealed notification (docs/bga-studio-reference.md §6).
        $handValueLists = $this->game->getCollectionFromDb(
            "SELECT `player_id` AS `id`, GROUP_CONCAT(`value` ORDER BY `value` SEPARATOR ', ') AS `hand` " .
                "FROM `work_card` WHERE `location` = 'hand' GROUP BY `player_id`",
            true
        );

        foreach ($scores as $playerId => $scoring) {
            $this->game->DbQuery(
                "UPDATE `player` SET `player_score` = {$scoring->score}, " .
                    "`player_score_aux` = {$scoring->aux}, " .
                    "`player_fired` = " . ($scoring->fired ? 1 : 0) .
                    " WHERE `player_id` = $playerId"
            );

            if ($scoring->fired) {
                $this->game->bga->notify->all(
                    'playerFired',
                    clienttranslate('${player_name} is fired and excluded from scoring'),
                    [
                        'player_id' => $playerId,
                        'player_name' => $this->game->getPlayerNameById($playerId),
                    ]
                );
            }

            $this->game->bga->notify->all(
                'scoreBreakdown',
                $scoring->fired
                    ? clienttranslate('${player_name}: hand [${hand}] = ${handTotal}, FIRED (reputation ${reputation}), score ${score}')
                    // Reputation is included here (not just the bonus tier it maps to) so a
                    // tied score's aux-based tie-break has something visible to point at --
                    // see docs/loaf-remarks.md's Phase 3 entry on this gap.
                    : clienttranslate('${player_name}: hand [${hand}] = ${handTotal}, reputation bonus +${bonus} (reputation ${reputation}), score ${score}, tie-break value ${aux}'),
                [
                    'player_id' => $playerId,
                    'player_name' => $this->game->getPlayerNameById($playerId),
                    'hand' => $handValueLists[$playerId] ?? '',
                    'handTotal' => $handValues[$playerId],
                    // Derived from the final score rather than duplicating
                    // ScoringCalculator's reputation-bonus table here -- meaningless for a
                    // fired player (their score is the shared sentinel, not this sum), so
                    // only computed/shown on the non-fired branch above.
                    'bonus' => $scoring->score - $handValues[$playerId] - $bonusPoints[$playerId],
                    'reputation' => $reputations[$playerId],
                    'score' => $scoring->score,
                    'aux' => $scoring->aux,
                ]
            );
        }

        // Explains a tied score's outcome in words -- BGA's own ranking already breaks the
        // tie correctly via player_score_aux, but shows no reasoning for it. The actual
        // winners/losers grouping is pure logic (ScoringCalculator::tieGroups(),
        // unit-tested); this adapter only turns each group into player names and log text.
        foreach (ScoringCalculator::tieGroups($scores) as $group) {
            $winnerNames = implode(', ', array_map(fn($id) => $this->game->getPlayerNameById($id), $group['winners']));

            if (empty($group['losers'])) {
                // Tied on score AND reputation -- the rulebook's own "share the victory" case,
                // not a real tie-break outcome to explain.
                $this->game->bga->notify->all(
                    'tieBreak',
                    clienttranslate('${names} are tied on score and reputation -- they share the victory.'),
                    ['names' => $winnerNames]
                );
                continue;
            }

            $loserNames = implode(', ', array_map(fn($id) => $this->game->getPlayerNameById($id), $group['losers']));
            $this->game->bga->notify->all(
                'tieBreak',
                count($group['winners']) === 1
                    ? clienttranslate('${winnerNames} wins the tie over ${loserNames} on lower reputation.')
                    : clienttranslate('${winnerNames} win the tie over ${loserNames} on lower reputation.'),
                ['winnerNames' => $winnerNames, 'loserNames' => $loserNames]
            );
        }

        $allFired = count(array_filter($scores, fn($s) => $s->fired)) === count($scores);

        $this->game->bga->notify->all(
            'gameEnded',
            match (true) {
                $allFired => clienttranslate('Every player was fired -- there is no winner.'),
                $endingBoss === 'angry' => clienttranslate(
                    'The Angry Boss pile reached 5 cards -- players with negative reputation are fired!'
                ),
                default => clienttranslate(
                    'The Happy Boss pile reached 5 cards -- everyone proceeds to scoring.'
                ),
            },
            ['endingBoss' => $endingBoss, 'allFired' => $allFired]
        );

        return ST_END_GAME;
    }
}
