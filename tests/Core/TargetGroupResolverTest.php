<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\TargetGroupResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TargetGroupResolverTest extends TestCase
{
    public function testHighestReputationTarget(): void
    {
        $result = TargetGroupResolver::playersInTarget('highest_reputation', [1 => 0, 2 => -3, 3 => 5]);
        $this->assertSame([3], $result);
    }

    public function testLowestReputationTarget(): void
    {
        $result = TargetGroupResolver::playersInTarget('lowest_reputation', [1 => 0, 2 => -3, 3 => 5]);
        $this->assertSame([2], $result);
    }

    public function testReputationPositiveTarget(): void
    {
        $result = TargetGroupResolver::playersInTarget('reputation_positive', [1 => 0, 2 => -3, 3 => 5]);
        $this->assertSame([1, 3], $result);
    }

    public function testReputationNegativeTarget(): void
    {
        $result = TargetGroupResolver::playersInTarget('reputation_negative', [1 => 0, 2 => -3, 3 => 5]);
        $this->assertSame([2], $result);
    }

    public function testReputationZeroTarget(): void
    {
        $result = TargetGroupResolver::playersInTarget('reputation_zero', [1 => 0, 2 => -3, 3 => 5]);
        $this->assertSame([1], $result);
    }

    public function testReputationZeroTargetWithTie(): void
    {
        // Multiple players at exactly 0 -- all included, per the additive-ties precedent.
        $result = TargetGroupResolver::playersInTarget('reputation_zero', [1 => 0, 2 => 3, 3 => 0]);
        $this->assertSame([1, 3], $result);
    }

    public function testReputationZeroTargetEmptyWhenNobodyAtZero(): void
    {
        $result = TargetGroupResolver::playersInTarget('reputation_zero', [1 => 2, 2 => -3]);
        $this->assertSame([], $result);
    }

    public function testTiedHighestReputationAffectsEveryTiedPlayer(): void
    {
        $result = TargetGroupResolver::playersInTarget('highest_reputation', [1 => 5, 2 => 5, 3 => 2]);
        $this->assertSame([1, 2], $result);
    }

    public function testTiedLowestReputationAffectsEveryTiedPlayer(): void
    {
        $result = TargetGroupResolver::playersInTarget('lowest_reputation', [1 => -2, 2 => -2, 3 => 4]);
        $this->assertSame([1, 2], $result);
    }

    public function testUnknownTargetThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TargetGroupResolver::playersInTarget('not_a_real_target', [1 => 0]);
    }
}
