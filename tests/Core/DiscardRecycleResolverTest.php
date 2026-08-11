<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\DiscardRecycleResolver;
use PHPUnit\Framework\TestCase;

final class DiscardRecycleResolverTest extends TestCase
{
    public function testRecyclesLowestCardForTargetedPlayer(): void
    {
        $result = DiscardRecycleResolver::resolve([1], [1 => [7, 3, 9]]);
        $this->assertSame([1 => 3], $result);
    }

    public function testEmptyDiscardPileIsNoOp(): void
    {
        $result = DiscardRecycleResolver::resolve([1], [1 => []]);
        $this->assertSame([], $result);
    }

    public function testMissingDiscardPileEntryIsNoOp(): void
    {
        // No entry at all for player 1 -- same as an empty pile, not an error.
        $result = DiscardRecycleResolver::resolve([1], []);
        $this->assertSame([], $result);
    }

    public function testMultipleTargetedPlayersHandledIndependently(): void
    {
        $result = DiscardRecycleResolver::resolve(
            [1, 2, 3],
            [1 => [7, 3, 9], 2 => [], 3 => [0, 5]],
        );

        $this->assertSame([1 => 3, 3 => 0], $result);
    }

    public function testPlayerNotInTargetGroupIsIgnoredEvenIfDiscardMapHasAnEntry(): void
    {
        $result = DiscardRecycleResolver::resolve([1], [1 => [3], 2 => [1]]);
        $this->assertSame([1 => 3], $result);
    }

    public function testEmptyTargetGroupReturnsEmptyResult(): void
    {
        $result = DiscardRecycleResolver::resolve([], [1 => [3]]);
        $this->assertSame([], $result);
    }
}
