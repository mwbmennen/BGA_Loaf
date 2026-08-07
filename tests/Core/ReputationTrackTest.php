<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\ReputationTrack;
use PHPUnit\Framework\TestCase;

final class ReputationTrackTest extends TestCase
{
    public function testAdjustWithinRangeSumsNormally(): void
    {
        $this->assertSame(3, ReputationTrack::adjust(1, 2));
        $this->assertSame(-4, ReputationTrack::adjust(-1, -3));
    }

    public function testAdjustClampsAtUpperBound(): void
    {
        $this->assertSame(10, ReputationTrack::adjust(9, 5));
        $this->assertSame(10, ReputationTrack::adjust(10, 1));
    }

    public function testAdjustClampsAtLowerBound(): void
    {
        $this->assertSame(-10, ReputationTrack::adjust(-9, -5));
        $this->assertSame(-10, ReputationTrack::adjust(-10, -1));
    }

    public function testAdjustCrossingUpperBoundaryClamps(): void
    {
        $this->assertSame(10, ReputationTrack::adjust(8, 3));
    }

    public function testAdjustCrossingLowerBoundaryClamps(): void
    {
        $this->assertSame(-10, ReputationTrack::adjust(-8, -3));
    }

    public function testAdjustByZeroIsNoOp(): void
    {
        $this->assertSame(4, ReputationTrack::adjust(4, 0));
    }
}
