<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\ScoringCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScoringCalculatorTest extends TestCase
{
    public function testHandValueSummation(): void
    {
        $result = ScoringCalculator::score(
            handValues: [1 => 15],
            reputations: [1 => 0],
            bonusPoints: [1 => 0],
            endingBoss: 'happy',
        );

        $this->assertSame(15, $result[1]->score);
        $this->assertFalse($result[1]->fired);
    }

    public function testPositiveOnlyReputationBonus(): void
    {
        $result = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 10],
            reputations: [1 => 4, 2 => -4],
            bonusPoints: [1 => 0, 2 => 0],
            endingBoss: 'happy',
        );

        // Reputation 4 is the board's "4 to 6" tier, worth +3 (not +4 -- the bonus is a
        // stepped table printed on the reputation board, not the reputation value itself, see
        // testReputationBonusTiers below). -4 reputation does not subtract, per the rulebook's
        // explicit "you do not lose points for a negative reputation value" note.
        $this->assertSame(13, $result[1]->score);
        $this->assertSame(10, $result[2]->score);
    }

    public function testReputationBonusTiers(): void
    {
        // The reputation board's printed end-game bonus: 1-3 => +2, 4-6 => +3, 7-9 => +4,
        // 10 => +5, 0 or negative => +0. Not derivable from the reputation value itself (the
        // rulebook's transcribed text has no numbers -- they're only printed on the physical
        // board), confirmed 2026-08-09 from a photo of the actual board.
        $result = ScoringCalculator::score(
            handValues: [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
            reputations: [1 => -3, 2 => 0, 3 => 1, 4 => 3, 5 => 4, 6 => 6, 7 => 7, 8 => 9, 9 => 10],
            bonusPoints: array_fill(1, 9, 0),
            endingBoss: 'happy',
        );

        $this->assertSame(0, $result[1]->score); // -3 -> no bonus
        $this->assertSame(0, $result[2]->score); // 0 -> no bonus
        $this->assertSame(2, $result[3]->score); // 1 -> +2 (bottom of the 1-3 tier)
        $this->assertSame(2, $result[4]->score); // 3 -> +2 (top of the 1-3 tier)
        $this->assertSame(3, $result[5]->score); // 4 -> +3 (bottom of the 4-6 tier)
        $this->assertSame(3, $result[6]->score); // 6 -> +3 (top of the 4-6 tier)
        $this->assertSame(4, $result[7]->score); // 7 -> +4 (bottom of the 7-9 tier)
        $this->assertSame(4, $result[8]->score); // 9 -> +4 (top of the 7-9 tier)
        $this->assertSame(5, $result[9]->score); // 10 -> +5
    }

    public function testHappyBossEndingFiresNobody(): void
    {
        $result = ScoringCalculator::score(
            handValues: [1 => 5, 2 => 5],
            reputations: [1 => -8, 2 => 3],
            bonusPoints: [1 => 0, 2 => 0],
            endingBoss: 'happy',
        );

        $this->assertFalse($result[1]->fired);
        $this->assertFalse($result[2]->fired);
        // Not fired, so still scored normally (reputation bonus is 0, not negative, either way).
        $this->assertSame(5, $result[1]->score);
    }

    public function testAngryBossEndingFiresExactlyNegativeReputationPlayers(): void
    {
        $result = ScoringCalculator::score(
            handValues: [1 => 5, 2 => 5, 3 => 5],
            reputations: [1 => -1, 2 => 0, 3 => 1],
            bonusPoints: [1 => 0, 2 => 0, 3 => 0],
            endingBoss: 'angry',
        );

        $this->assertTrue($result[1]->fired);
        // Exactly 0 is "0 or higher", not fired -- matches the rulebook's "lower than 0" wording.
        $this->assertFalse($result[2]->fired);
        $this->assertFalse($result[3]->fired);
    }

    public function testFiredPlayersShareOneScoreBelowEveryActivePlayers(): void
    {
        $result = ScoringCalculator::score(
            handValues: [1 => 20, 2 => 1, 3 => 8],
            reputations: [1 => -1, 2 => -9, 3 => 2],
            bonusPoints: [1 => 0, 2 => 0, 3 => 0],
            endingBoss: 'angry',
        );

        // Players 1 and 2 are both fired despite very different hand values/reputations --
        // they must end up with an identical score AND aux (Q5: no ordering among themselves).
        $this->assertTrue($result[1]->fired);
        $this->assertTrue($result[2]->fired);
        $this->assertSame($result[1]->score, $result[2]->score);
        $this->assertSame($result[1]->aux, $result[2]->aux);
        $this->assertLessThan($result[3]->score, $result[1]->score);
    }

    public function testAllPlayersFiredEdgeCase(): void
    {
        $result = ScoringCalculator::score(
            handValues: [1 => 20, 2 => 1],
            reputations: [1 => -1, 2 => -9],
            bonusPoints: [1 => 0, 2 => 0],
            endingBoss: 'angry',
        );

        $this->assertTrue($result[1]->fired);
        $this->assertTrue($result[2]->fired);
        $this->assertSame($result[1]->score, $result[2]->score);
        $this->assertSame($result[1]->aux, $result[2]->aux);
    }

    public function testTieBreakDirectionFavorsLowerReputation(): void
    {
        // Reputation 3 (top of the 1-3 tier, +2) and reputation 4 (bottom of the 4-6 tier,
        // +3) are in different bonus tiers -- hand values are chosen so the final scores tie
        // anyway (10+2 == 9+3), so this test isolates the tie-break itself from the bonus
        // table tested separately in testReputationBonusTiers.
        $result = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 9],
            reputations: [1 => 3, 2 => 4],
            bonusPoints: [1 => 0, 2 => 0],
            endingBoss: 'happy',
        );

        // Both score 12, but player 1 has the lower reputation -- their aux must be higher.
        $this->assertSame($result[1]->score, $result[2]->score);
        $this->assertGreaterThan($result[2]->aux, $result[1]->aux);
    }

    public function testSharedVictoryEqualScoreAndReputation(): void
    {
        $result = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 10],
            reputations: [1 => 3, 2 => 3],
            bonusPoints: [1 => 0, 2 => 0],
            endingBoss: 'happy',
        );

        $this->assertSame($result[1]->score, $result[2]->score);
        $this->assertSame($result[1]->aux, $result[2]->aux);
    }

    public function testBonusPointsArePlumbedThrough(): void
    {
        $result = ScoringCalculator::score(
            handValues: [1 => 10],
            reputations: [1 => 0],
            bonusPoints: [1 => 5],
            endingBoss: 'happy',
        );

        $this->assertSame(15, $result[1]->score);
    }

    public function testEmptyReputationsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScoringCalculator::score(handValues: [], reputations: [], bonusPoints: [], endingBoss: 'happy');
    }

    public function testTieGroupsNoTiesReturnsEmptyArray(): void
    {
        $scores = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 5],
            reputations: [1 => 0, 2 => 0],
            bonusPoints: [1 => 0, 2 => 0],
            endingBoss: 'happy',
        );

        $this->assertSame([], ScoringCalculator::tieGroups($scores));
    }

    public function testTieGroupsClearWinnerOnLowerReputation(): void
    {
        // Same scenario as testTieBreakDirectionFavorsLowerReputation above: both score 12,
        // player 1's reputation (3) is lower than player 2's (4).
        $scores = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 9],
            reputations: [1 => 3, 2 => 4],
            bonusPoints: [1 => 0, 2 => 0],
            endingBoss: 'happy',
        );

        $this->assertSame(
            [['winners' => [1], 'losers' => [2]]],
            ScoringCalculator::tieGroups($scores),
        );
    }

    public function testTieGroupsSharedVictoryWhenStillTiedOnReputation(): void
    {
        $scores = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 10],
            reputations: [1 => 3, 2 => 3],
            bonusPoints: [1 => 0, 2 => 0],
            endingBoss: 'happy',
        );

        $this->assertSame(
            [['winners' => [1, 2], 'losers' => []]],
            ScoringCalculator::tieGroups($scores),
        );
    }

    public function testTieGroupsPartialWinLoseSplitAmongThreeTiedPlayers(): void
    {
        // All three score 12. Players 1 and 2 share the lowest (and thus winning) reputation;
        // player 3's higher reputation loses the tie.
        $scores = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 10, 3 => 9],
            reputations: [1 => 2, 2 => 2, 3 => 5],
            bonusPoints: [1 => 0, 2 => 0, 3 => 0],
            endingBoss: 'happy',
        );

        $this->assertSame(
            [['winners' => [1, 2], 'losers' => [3]]],
            ScoringCalculator::tieGroups($scores),
        );
    }

    public function testTieGroupsExcludesFiredPlayersEvenThoughTheyShareAScore(): void
    {
        // Players 1 and 2 are both fired and, by ScoringCalculator's own design, always end
        // up with an identical (sentinel) score and aux -- naive score-based grouping would
        // treat them as "tied," but firing already settles that (Q5: unranked, nothing to
        // explain), so tieGroups() must not report them as a group at all.
        $scores = ScoringCalculator::score(
            handValues: [1 => 20, 2 => 1, 3 => 8],
            reputations: [1 => -1, 2 => -9, 3 => 2],
            bonusPoints: [1 => 0, 2 => 0, 3 => 0],
            endingBoss: 'angry',
        );

        $this->assertTrue($scores[1]->fired);
        $this->assertTrue($scores[2]->fired);
        $this->assertSame($scores[1]->score, $scores[2]->score); // confirms they would look tied
        $this->assertSame([], ScoringCalculator::tieGroups($scores));
    }

    public function testTieGroupsMultipleIndependentGroups(): void
    {
        // Two separate tied pairs at two different score levels.
        $scores = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 9, 3 => 5, 4 => 4],
            reputations: [1 => 3, 2 => 4, 3 => 3, 4 => 4],
            bonusPoints: [1 => 0, 2 => 0, 3 => 0, 4 => 0],
            endingBoss: 'happy',
        );

        $this->assertSame(
            [
                ['winners' => [1], 'losers' => [2]],
                ['winners' => [3], 'losers' => [4]],
            ],
            ScoringCalculator::tieGroups($scores),
        );
    }
}
