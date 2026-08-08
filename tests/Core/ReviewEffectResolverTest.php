<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\ReviewEffectResolver;
use Bga\Games\loaf\Core\RoundCardData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReviewEffectResolverTest extends TestCase
{
    public function testLowestReputationTarget(): void
    {
        // basic_01, success side: lowest_reputation, amount +1.
        $effect = RoundCardData::TYPES['basic_01']['review']['success'];

        $result = ReviewEffectResolver::resolve($effect, [1 => 0, 2 => -3, 3 => 5]);

        $this->assertSame([2 => -2], $result);
    }

    public function testHighestReputationTarget(): void
    {
        // basic_07, fail side: highest_reputation, amount -1.
        $effect = RoundCardData::TYPES['basic_07']['review']['fail'];

        $result = ReviewEffectResolver::resolve($effect, [1 => 0, 2 => -3, 3 => 5]);

        $this->assertSame([3 => 4], $result);
    }

    public function testReputationPositiveTarget(): void
    {
        // basic_11, success side: reputation_positive (>= 0), amount +3.
        $effect = RoundCardData::TYPES['basic_11']['review']['success'];

        $result = ReviewEffectResolver::resolve($effect, [1 => 0, 2 => -3, 3 => 5]);

        $this->assertSame([1 => 3, 3 => 8], $result);
    }

    public function testReputationNegativeTarget(): void
    {
        // basic_05, fail side: reputation_negative (< 0), amount -3.
        $effect = RoundCardData::TYPES['basic_05']['review']['fail'];

        $result = ReviewEffectResolver::resolve($effect, [1 => 0, 2 => -3, 3 => 5]);

        $this->assertSame([2 => -6], $result);
    }

    public function testTiedHighestReputationAffectsEveryTiedPlayer(): void
    {
        $effect = RoundCardData::TYPES['basic_07']['review']['success']; // highest, +1

        $result = ReviewEffectResolver::resolve($effect, [1 => 5, 2 => 5, 3 => 2]);

        $this->assertSame([1 => 6, 2 => 6], $result);
    }

    public function testTiedLowestReputationAffectsEveryTiedPlayer(): void
    {
        $effect = RoundCardData::TYPES['basic_01']['review']['fail']; // lowest, -1

        $result = ReviewEffectResolver::resolve($effect, [1 => -2, 2 => -2, 3 => 4]);

        $this->assertSame([1 => -3, 2 => -3], $result);
    }

    public function testEmptyTargetGroupReturnsEmptyResult(): void
    {
        // reputation_negative with nobody below zero -- a legitimate no-op, not an error.
        $effect = RoundCardData::TYPES['basic_05']['review']['fail'];

        $result = ReviewEffectResolver::resolve($effect, [1 => 0, 2 => 3, 3 => 5]);

        $this->assertSame([], $result);
    }

    public function testResultClampsAtReputationTrackBounds(): void
    {
        // basic_09, success side: highest_reputation, amount +3. Player already at +10 must
        // stay at +10, not go to +13.
        $effect = RoundCardData::TYPES['basic_09']['review']['success'];

        $result = ReviewEffectResolver::resolve($effect, [1 => 10, 2 => 4]);

        $this->assertSame([1 => 10], $result);
    }

    public function testNonReputationEffectIsANoOp(): void
    {
        // advanced_01's effect type is basic-incompatible (discard/swap/end-game/etc.) --
        // Phase 4's job, must be a no-op here, not an error.
        $effect = RoundCardData::TYPES['advanced_01']['review']['success'];

        $result = ReviewEffectResolver::resolve($effect, [1 => 0, 2 => -3]);

        $this->assertSame([], $result);
    }

    public function testNoOpEffectTypeIsANoOp(): void
    {
        // advanced_07's success side is effect: 'none' (the "empty effect" counts-as-two
        // card) -- also a no-op, distinct from the discard/swap case above.
        $effect = RoundCardData::TYPES['advanced_07']['review']['success'];

        $result = ReviewEffectResolver::resolve($effect, [1 => 0, 2 => -3]);

        $this->assertSame([], $result);
    }

    public function testEmptyReputationsThrows(): void
    {
        $effect = RoundCardData::TYPES['basic_01']['review']['success'];

        $this->expectException(InvalidArgumentException::class);
        ReviewEffectResolver::resolve($effect, []);
    }

    public function testReputationEffectWithNullTargetThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewEffectResolver::resolve(
            ['target' => null, 'effect' => 'reputation', 'amount' => 1, 'counts_as_two' => false],
            [1 => 0],
        );
    }
}
