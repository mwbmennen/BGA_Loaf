<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\SwapEffectResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SwapEffectResolverTest extends TestCase
{
    public function testEligibleDiscardsForLowerDirection(): void
    {
        // Played 6, at most 2 lower -> eligible values in [4, 6). 1 is too far below (diff 5).
        $eligible = SwapEffectResolver::eligibleDiscards([6, 4, 5, 1, 10], 6, 2, 'swap_discard_lower_by_at_most');
        $this->assertSame([4, 5], $eligible);
    }

    public function testEligibleDiscardsForHigherDirection(): void
    {
        // Played 4, at least 2 higher -> eligible values >= 6. 2 is below the played value.
        $eligible = SwapEffectResolver::eligibleDiscards([4, 6, 7, 10, 2], 4, 2, 'swap_discard_higher_by_at_least');
        $this->assertSame([6, 7, 10], $eligible);
    }

    public function testLowerDirectionBoundaryIsInclusive(): void
    {
        // Exactly X (2) lower than the played card (6) -- "at most 2 lower" includes it.
        $eligible = SwapEffectResolver::eligibleDiscards([6, 4], 6, 2, 'swap_discard_lower_by_at_most');
        $this->assertSame([4], $eligible);
    }

    public function testHigherDirectionBoundaryIsInclusive(): void
    {
        // Exactly X (2) higher than the played card (4) -- "at least 2 higher" includes it.
        $eligible = SwapEffectResolver::eligibleDiscards([4, 6], 4, 2, 'swap_discard_higher_by_at_least');
        $this->assertSame([6], $eligible);
    }

    public function testPlayedValueItselfIsNeverEligible(): void
    {
        // Trivially "0 lower/higher" would otherwise pass the range check -- must be excluded.
        $eligible = SwapEffectResolver::eligibleDiscards([6, 6], 6, 5, 'swap_discard_lower_by_at_most');
        $this->assertSame([], $eligible);
    }

    public function testNoEligibleCardsReturnsEmptyArray(): void
    {
        $eligible = SwapEffectResolver::eligibleDiscards([6, 9, 10], 6, 1, 'swap_discard_lower_by_at_most');
        $this->assertSame([], $eligible);
    }

    public function testResolveReturnsPlayedCardWhenNoEligibleDiscards(): void
    {
        // Fallback per the rulebook: "if they can't, they discard the played card instead" --
        // applies regardless of what (if anything) was passed as chosenValue.
        $result = SwapEffectResolver::resolve([6, 9, 10], 6, 1, 'swap_discard_lower_by_at_most', null);
        $this->assertSame(6, $result);
    }

    public function testResolveAcceptsAValidFreelyChosenCard(): void
    {
        $result = SwapEffectResolver::resolve([6, 4, 5], 6, 2, 'swap_discard_lower_by_at_most', 5);
        $this->assertSame(5, $result);
    }

    public function testResolveThrowsOnAnIneligibleChoice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SwapEffectResolver::resolve([6, 4, 5], 6, 2, 'swap_discard_lower_by_at_most', 1);
    }

    public function testResolveThrowsWhenNoChoiceGivenButEligibleCardsExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SwapEffectResolver::resolve([6, 4, 5], 6, 2, 'swap_discard_lower_by_at_most', null);
    }

    public function testUnknownEffectTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SwapEffectResolver::eligibleDiscards([6, 4], 6, 2, 'not_a_real_effect');
    }
}
