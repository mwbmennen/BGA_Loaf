<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\EndGameEffectResolver;
use Bga\Games\loaf\Core\RoundCardData;
use Bga\Games\loaf\Core\ScoringCalculator;
use PHPUnit\Framework\TestCase;

final class EndGameEffectResolverTest extends TestCase
{
    public function testBonusOnlyEffect(): void
    {
        // advanced_09 success: highest_reputation, end_game_bonus +4.
        $effect = RoundCardData::TYPES['advanced_09']['review']['success'];

        $result = EndGameEffectResolver::resolve([$effect], [1 => 5, 2 => 2]);

        $this->assertSame([1 => 4, 2 => 0], $result);
    }

    public function testMalusOnlyEffect(): void
    {
        // advanced_09 fail: lowest_reputation, end_game_malus 4 (stored as a positive
        // magnitude in RoundCardData -- this class is responsible for applying the minus sign).
        $effect = RoundCardData::TYPES['advanced_09']['review']['fail'];

        $result = EndGameEffectResolver::resolve([$effect], [1 => 5, 2 => 2]);

        $this->assertSame([1 => 0, 2 => -4], $result);
    }

    public function testEmptyTargetGroupIsNoOp(): void
    {
        // advanced_10 fail: reputation_negative, malus 3 -- nobody is negative here.
        $effect = RoundCardData::TYPES['advanced_10']['review']['fail'];

        $result = EndGameEffectResolver::resolve([$effect], [1 => 0, 2 => 5]);

        $this->assertSame([1 => 0, 2 => 0], $result);
    }

    public function testBonusDoublerAppliesOnlyWhenItsOwnCardWasFiled(): void
    {
        $bonusEffect = RoundCardData::TYPES['advanced_09']['review']['success']; // highest, +4
        $doubler = RoundCardData::TYPES['advanced_11']['review']['success']; // double_end_game_bonus

        $withoutDoubler = EndGameEffectResolver::resolve([$bonusEffect], [1 => 5, 2 => 2]);
        $withDoubler = EndGameEffectResolver::resolve([$bonusEffect, $doubler], [1 => 5, 2 => 2]);

        $this->assertSame([1 => 4, 2 => 0], $withoutDoubler);
        $this->assertSame([1 => 8, 2 => 0], $withDoubler);
    }

    public function testMalusDoublerDoesNotAffectBonusTotals(): void
    {
        // Both a bonus and a malus effect present, but only the malus doubler was filed --
        // confirms the two totals are doubled independently, not both-or-nothing.
        $bonusEffect = RoundCardData::TYPES['advanced_09']['review']['success']; // highest, +4
        $malusEffect = RoundCardData::TYPES['advanced_10']['review']['fail']; // reputation_negative, -3
        $malusDoubler = RoundCardData::TYPES['advanced_11']['review']['fail']; // double_end_game_malus

        $result = EndGameEffectResolver::resolve(
            [$bonusEffect, $malusEffect, $malusDoubler],
            [1 => 5, 2 => -2],
        );

        // Player 1: highest reputation -> +4 bonus, not doubled.
        // Player 2: negative reputation -> -3 malus, doubled to -6.
        $this->assertSame([1 => 4, 2 => -6], $result);
    }

    public function testMultipleBonusEffectsStackAdditivelyOnTheSamePlayer(): void
    {
        // Player 1 is both the highest reputation AND has positive reputation -- both
        // advanced_09's and advanced_10's success-side bonuses should stack on them.
        $highestBonus = RoundCardData::TYPES['advanced_09']['review']['success']; // highest, +4
        $positiveBonus = RoundCardData::TYPES['advanced_10']['review']['success']; // positive, +3

        $result = EndGameEffectResolver::resolve([$highestBonus, $positiveBonus], [1 => 5, 2 => -2]);

        $this->assertSame([1 => 7, 2 => 0], $result);
    }

    public function testIrrelevantEffectTypesAreIgnored(): void
    {
        // A basic reputation effect and an interactive discard_choice effect (amount: null)
        // mixed in alongside a real end-game effect -- must not crash or contribute anything.
        $reputationEffect = RoundCardData::TYPES['basic_01']['review']['success'];
        $discardChoiceEffect = RoundCardData::TYPES['advanced_01']['review']['success'];
        $bonusEffect = RoundCardData::TYPES['advanced_09']['review']['success'];

        $result = EndGameEffectResolver::resolve(
            [$reputationEffect, $discardChoiceEffect, $bonusEffect],
            [1 => 5, 2 => 2],
        );

        $this->assertSame([1 => 4, 2 => 0], $result);
    }

    public function testNoResolvedEffectsReturnsAllZeroes(): void
    {
        $result = EndGameEffectResolver::resolve([], [1 => 5, 2 => -2]);
        $this->assertSame([1 => 0, 2 => 0], $result);
    }

    public function testBreakdownEntryShapeForASingleUndoubledBonus(): void
    {
        $bonusEffect = RoundCardData::TYPES['advanced_09']['review']['success']; // highest, +4

        $breakdown = EndGameEffectResolver::breakdown([$bonusEffect], [1 => 5, 2 => 2]);

        $this->assertSame(
            [['playerId' => 1, 'amount' => 4, 'effect' => $bonusEffect, 'doubled' => false]],
            $breakdown
        );
    }

    public function testBreakdownMarksEntriesDoubledAndReflectsItInAmount(): void
    {
        $bonusEffect = RoundCardData::TYPES['advanced_09']['review']['success']; // highest, +4
        $doubler = RoundCardData::TYPES['advanced_11']['review']['success']; // double_end_game_bonus

        $breakdown = EndGameEffectResolver::breakdown([$bonusEffect, $doubler], [1 => 5, 2 => 2]);

        $this->assertSame(
            [['playerId' => 1, 'amount' => 8, 'effect' => $bonusEffect, 'doubled' => true]],
            $breakdown
        );
    }

    public function testBreakdownGivesOneEntryPerAffectedPlayer(): void
    {
        // reputation_positive: both players qualify (0 counts as positive).
        $bonusEffect = RoundCardData::TYPES['advanced_10']['review']['success']; // positive, +3

        $breakdown = EndGameEffectResolver::breakdown([$bonusEffect], [1 => 5, 2 => 0]);

        $this->assertCount(2, $breakdown);
        $this->assertSame([1, 2], array_column($breakdown, 'playerId'));
        $this->assertSame([3, 3], array_column($breakdown, 'amount'));
    }

    public function testBreakdownGivesOneEntryPerContributingEffectForTheSamePlayer(): void
    {
        // Player 1 matches both advanced_09's and advanced_10's success-side bonus targets --
        // two separate breakdown entries, not one merged entry (mirrors
        // testMultipleBonusEffectsStackAdditivelyOnTheSamePlayer's resolve()-level assertion).
        $highestBonus = RoundCardData::TYPES['advanced_09']['review']['success']; // highest, +4
        $positiveBonus = RoundCardData::TYPES['advanced_10']['review']['success']; // positive, +3

        $breakdown = EndGameEffectResolver::breakdown([$highestBonus, $positiveBonus], [1 => 5, 2 => -2]);

        $this->assertCount(2, $breakdown);
        $this->assertSame([1, 1], array_column($breakdown, 'playerId'));
        $this->assertSame([4, 3], array_column($breakdown, 'amount'));
    }

    public function testBreakdownExcludesDoublersAndIrrelevantEffectsFromTheEntryList(): void
    {
        // The doublers themselves have no per-player target to attach an entry to, and
        // irrelevant effect types are ignored the same as resolve()'s own handling.
        $reputationEffect = RoundCardData::TYPES['basic_01']['review']['success'];
        $doubler = RoundCardData::TYPES['advanced_11']['review']['success']; // double_end_game_bonus

        $breakdown = EndGameEffectResolver::breakdown([$reputationEffect, $doubler], [1 => 5, 2 => -2]);

        $this->assertSame([], $breakdown);
    }

    public function testResolveIsDefinedInTermsOfBreakdown(): void
    {
        // Guards the refactor where resolve() delegates to breakdown() -- summing
        // breakdown()'s amounts per player must equal resolve()'s own totals.
        $bonusEffect = RoundCardData::TYPES['advanced_09']['review']['success'];
        $malusEffect = RoundCardData::TYPES['advanced_10']['review']['fail'];
        $malusDoubler = RoundCardData::TYPES['advanced_11']['review']['fail'];
        $resolvedEffects = [$bonusEffect, $malusEffect, $malusDoubler];
        $reputations = [1 => 5, 2 => -2];

        $resolved = EndGameEffectResolver::resolve($resolvedEffects, $reputations);

        $summedFromBreakdown = array_fill_keys(array_keys($reputations), 0);
        foreach (EndGameEffectResolver::breakdown($resolvedEffects, $reputations) as $entry) {
            $summedFromBreakdown[$entry['playerId']] += $entry['amount'];
        }

        $this->assertSame($resolved, $summedFromBreakdown);
    }

    public function testFeedsDirectlyIntoScoringCalculator(): void
    {
        $bonusEffect = RoundCardData::TYPES['advanced_09']['review']['success']; // highest, +4
        $bonusPoints = EndGameEffectResolver::resolve([$bonusEffect], [1 => 5, 2 => 2]);

        $scores = ScoringCalculator::score(
            handValues: [1 => 10, 2 => 10],
            reputations: [1 => 5, 2 => 2],
            bonusPoints: $bonusPoints,
            endingBoss: 'happy',
        );

        // Player 1: hand 10 + reputation-4-6-tier bonus 3 + end-game bonus 4 = 17.
        $this->assertSame(17, $scores[1]->score);
        // Player 2: hand 10 + reputation-1-3-tier bonus 2 + end-game bonus 0 = 12.
        $this->assertSame(12, $scores[2]->score);
    }
}
