<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

use InvalidArgumentException;

/**
 * Computes final score/tie-break-aux/fired status for every player at game end. Pure/DB-free
 * -- see docs/loaf-implementation-plan.md §2. Deliberately produces the two numbers BGA's
 * native player_score/player_score_aux ranking is built from, rather than computing "who won"
 * itself -- see docs/loaf-phase3-plan.md §6.
 */
final class ScoringCalculator
{
    /**
     * Fixed score for every fired player, per player preference over the originally-derived
     * `min(activeScores) - 1` sentinel (docs/loaf-phase3-plan.md §4 -- kept there for the
     * reasoning trail, now superseded). Chosen to sit below the worst realistic Phase 4 malus
     * stack calculated in docs/loaf-remarks.md's Phase 3 entries (~-18: two stackable
     * end-game-malus cards plus the x2 malus doubler, all on one player) -- not an arbitrary
     * round number. Unlike the derived version, this needs revisiting if Phase 4's actual
     * malus catalogue ever changes enough to threaten that floor.
     */
    public const FIRED_SCORE = -20;

    /**
     * @param array<int, int> $handValues player_id => sum of remaining work-card values.
     * @param array<int, int> $reputations player_id => final reputation (-10..+10). Also the
     *     source of truth for which player_ids exist -- every other map must share its keys.
     * @param array<int, int> $bonusPoints player_id => advanced-card end-of-game bonus/malus
     *     points. Always zero-filled until Phase 4 (docs/loaf-phase3-plan.md §2) -- caller
     *     passes an explicit zero map rather than this method defaulting it, so Phase 4 only
     *     needs to populate real values here, not touch this signature.
     * @param 'happy'|'angry' $endingBoss which boss pile reached the end-game threshold --
     *     gates whether firing happens at all (docs/loaf-phase3-plan.md §3 point 1).
     * @return array<int, ScoringResult> player_id => final score/aux/fired-status.
     */
    public static function score(
        array $handValues,
        array $reputations,
        array $bonusPoints,
        string $endingBoss,
    ): array {
        if (empty($reputations)) {
            throw new InvalidArgumentException('Cannot score a game with no players');
        }

        $firedPlayerIds = $endingBoss === 'angry'
            ? array_keys(array_filter($reputations, static fn(int $rep): bool => $rep < 0))
            : [];

        $rawScores = [];
        foreach (array_keys($reputations) as $playerId) {
            $rawScores[$playerId] = $handValues[$playerId]
                + self::reputationBonus($reputations[$playerId])
                + $bonusPoints[$playerId];
        }

        $result = [];
        foreach (array_keys($reputations) as $playerId) {
            $fired = in_array($playerId, $firedPlayerIds, true);
            $result[$playerId] = new ScoringResult(
                score: $fired ? self::FIRED_SCORE : $rawScores[$playerId],
                // Lower reputation wins ties among active players (rulebook); fired players
                // are NOT sub-ranked by reputation (Q5), so they all get the same flat aux
                // too, matching their shared score -- see docs/loaf-phase3-plan.md §4/§5.
                aux: $fired ? 0 : -$reputations[$playerId],
                fired: $fired,
            );
        }
        return $result;
    }

    /**
     * Groups non-fired players by identical score and identifies each group's tie-break
     * winner(s) via the higher aux value -- used only to *explain* a tie's outcome in the
     * game log; BGA's own player_score/player_score_aux ranking decides the actual outcome
     * independently of this method (see §6 notes above).
     *
     * @param array<int, ScoringResult> $scores From self::score(). Fired players are excluded
     *     from grouping entirely: they can never legitimately tie with an active player (the
     *     sentinel score is always strictly below every active score, by construction), and a
     *     tie among fired players isn't a real contest (rulebook: already unranked, tied at
     *     the bottom, nothing to explain).
     * @return list<array{winners: int[], losers: int[]}> One entry per tied group (2+ players
     *     sharing a score). `losers` is empty when the group is still tied after aux too --
     *     the rulebook's "share the victory" case -- and every tied player is in `winners`.
     */
    public static function tieGroups(array $scores): array
    {
        $scoreGroups = [];
        foreach ($scores as $playerId => $scoring) {
            if (!$scoring->fired) {
                $scoreGroups[$scoring->score][] = $playerId;
            }
        }

        $result = [];
        foreach ($scoreGroups as $tiedPlayerIds) {
            if (count($tiedPlayerIds) < 2) {
                continue;
            }

            $topAux = max(array_map(static fn(int $id): int => $scores[$id]->aux, $tiedPlayerIds));
            $winners = array_values(
                array_filter($tiedPlayerIds, static fn(int $id): bool => $scores[$id]->aux === $topAux)
            );
            $losers = array_values(array_diff($tiedPlayerIds, $winners));

            $result[] = ['winners' => $winners, 'losers' => $losers];
        }
        return $result;
    }

    /**
     * The reputation board's printed end-game bonus, a stepped table -- NOT the reputation
     * value itself (docs/loaf-phase3-plan.md §3 point 2, corrected 2026-08-09 from a photo of
     * the physical board; the rulebook's own transcribed text only says "bonus points if you
     * have a positive reputation value" with no numbers, since the values live on the board
     * component, not in the rules text). 0 or negative reputation scores no bonus.
     */
    private static function reputationBonus(int $reputation): int
    {
        return match (true) {
            $reputation >= 10 => 5,
            $reputation >= 7 => 4,
            $reputation >= 4 => 3,
            $reputation >= 1 => 2,
            default => 0,
        };
    }
}
