<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\RoundResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoundResolverTest extends TestCase
{
    public function testRulebookExample(): void
    {
        // Order: 5 per player / 15 total for 3 players. Zach=6, Steve=4, Jason=7.
        $result = RoundResolver::resolve(5, [
            'zach' => 6,
            'steve' => 4,
            'jason' => 7,
        ]);

        $this->assertSame(17, $result->total);
        $this->assertSame(15, $result->target);
        $this->assertTrue($result->success);
        $this->assertSame(2, $result->reputationDelta);
        $this->assertEqualsCanonicalizing(['jason'], $result->affectedPlayerIds);
    }

    public function testTotalExactlyEqualToTargetIsSuccessWithZeroDelta(): void
    {
        // All cards equal to the average is the only way for the highest card to also equal
        // the average on a success, so this doubles as the "delta of zero" case.
        $result = RoundResolver::resolve(5, [1 => 5, 2 => 5, 3 => 5]);

        $this->assertSame(15, $result->total);
        $this->assertSame(15, $result->target);
        $this->assertTrue($result->success);
        $this->assertSame(0, $result->reputationDelta);
        $this->assertEqualsCanonicalizing([1, 2, 3], $result->affectedPlayerIds);
    }

    public function testTotalOneBelowTargetIsFailure(): void
    {
        $result = RoundResolver::resolve(5, [1 => 5, 2 => 5, 3 => 4]);

        $this->assertSame(14, $result->total);
        $this->assertSame(15, $result->target);
        $this->assertFalse($result->success);
    }

    public function testTieForHighestOnSuccessSplitsAmongAllTiedPlayers(): void
    {
        $result = RoundResolver::resolve(3, [1 => 8, 2 => 8, 3 => 1]);

        $this->assertTrue($result->success);
        $this->assertSame(5, $result->reputationDelta);
        $this->assertEqualsCanonicalizing([1, 2], $result->affectedPlayerIds);
    }

    public function testTieForLowestOnFailureSplitsAmongAllTiedPlayers(): void
    {
        $result = RoundResolver::resolve(5, [1 => 1, 2 => 1, 3 => 2]);

        $this->assertFalse($result->success);
        $this->assertSame(-4, $result->reputationDelta);
        $this->assertEqualsCanonicalizing([1, 2], $result->affectedPlayerIds);
    }

    public function testDeltaIsAlwaysStrictlyNegativeOnFailure(): void
    {
        // If the lowest card equalled the average, every card would be >= average, so the
        // total would be >= target, contradicting failure. Failure delta can never be zero.
        $result = RoundResolver::resolve(5, [1 => 5, 2 => 4, 3 => 4]);

        $this->assertFalse($result->success);
        $this->assertLessThan(0, $result->reputationDelta);
        $this->assertSame(-1, $result->reputationDelta);
        $this->assertEqualsCanonicalizing([2, 3], $result->affectedPlayerIds);
    }

    /**
     * @dataProvider playerCountProvider
     */
    public function testResolvesAtEveryPlayerCount(int $playerCount): void
    {
        $perPlayerAverage = 4;
        $playedCards = [];
        for ($playerId = 1; $playerId <= $playerCount; $playerId++) {
            $playedCards[$playerId] = 4;
        }
        // Bump one player's card up so the round succeeds and has a clear single winner.
        $playedCards[$playerCount] = 9;

        $result = RoundResolver::resolve($perPlayerAverage, $playedCards);

        $this->assertSame($perPlayerAverage * $playerCount, $result->target);
        $this->assertTrue($result->success);
        $this->assertSame(5, $result->reputationDelta);
        $this->assertEqualsCanonicalizing([$playerCount], $result->affectedPlayerIds);
    }

    public static function playerCountProvider(): array
    {
        return [
            '2 players' => [2],
            '3 players' => [3],
            '4 players' => [4],
            '5 players' => [5],
            '6 players' => [6],
        ];
    }

    public function testThrowsOnEmptyPlayedCards(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoundResolver::resolve(5, []);
    }
}
