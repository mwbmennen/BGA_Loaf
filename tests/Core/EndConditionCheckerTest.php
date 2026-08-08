<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\EndConditionChecker;
use PHPUnit\Framework\TestCase;

final class EndConditionCheckerTest extends TestCase
{
    public function testWeightedCountAllBasicCardsIsPlainCount(): void
    {
        // No basic card has counts_as_two: true, so weight always equals the card count.
        $cardTypes = ['basic_01', 'basic_02', 'basic_03'];

        $this->assertSame(3, EndConditionChecker::weightedCount($cardTypes, 'success'));
        $this->assertSame(3, EndConditionChecker::weightedCount($cardTypes, 'fail'));
    }

    public function testWeightedCountEmptyPileIsZero(): void
    {
        $this->assertSame(0, EndConditionChecker::weightedCount([], 'success'));
    }

    public function testWeightedCountCountsAsTwoCardOnSuccessSide(): void
    {
        // advanced_08's success side is counts_as_two: true; its fail side is not.
        $this->assertSame(2, EndConditionChecker::weightedCount(['advanced_08'], 'success'));
        $this->assertSame(1, EndConditionChecker::weightedCount(['advanced_08'], 'fail'));
    }

    public function testWeightedCountCountsAsTwoCardOnFailSide(): void
    {
        // advanced_07 is the mirror case: counts_as_two: true on fail, not success.
        $this->assertSame(1, EndConditionChecker::weightedCount(['advanced_07'], 'success'));
        $this->assertSame(2, EndConditionChecker::weightedCount(['advanced_07'], 'fail'));
    }

    public function testWeightedCountMixesRegularAndCountsAsTwoCards(): void
    {
        // One plain basic card (weight 1) + one counts-as-two advanced card (weight 2) filed
        // via their fail sides = weight 3, even though only 2 physical cards are in the pile.
        $weight = EndConditionChecker::weightedCount(['basic_01', 'advanced_07'], 'fail');

        $this->assertSame(3, $weight);
    }

    public function testCheckEndBelowThresholdOnBothReturnsNull(): void
    {
        $this->assertNull(EndConditionChecker::checkEnd(4, 4));
    }

    public function testCheckEndExactlyAtThresholdTriggersHappy(): void
    {
        $this->assertSame('happy', EndConditionChecker::checkEnd(5, 0));
    }

    public function testCheckEndExactlyAtThresholdTriggersAngry(): void
    {
        $this->assertSame('angry', EndConditionChecker::checkEnd(0, 5));
    }

    public function testCheckEndAboveThresholdStillTriggers(): void
    {
        // A counts_as_two card can push a pile from below 5 straight past it -- must still
        // trigger, not require landing on exactly 5.
        $this->assertSame('happy', EndConditionChecker::checkEnd(6, 0));
    }

    public function testCheckEndPigeonholeProof(): void
    {
        // docs/loaf-phase2-plan.md §5: under basic-only play (weight always 1), neither pile
        // can stay below the threshold past 8 total rounds -- by round 9, one must trigger.
        // Adversarial distribution that delays the end as long as possible: split evenly.
        $happyWeight = 4;
        $angryWeight = 4;
        $this->assertNull(EndConditionChecker::checkEnd($happyWeight, $angryWeight));

        // The 9th round's card must land in one pile or the other, forcing a trigger.
        $this->assertSame('happy', EndConditionChecker::checkEnd($happyWeight + 1, $angryWeight));
        $this->assertSame('angry', EndConditionChecker::checkEnd($happyWeight, $angryWeight + 1));
    }
}
