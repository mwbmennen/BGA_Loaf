<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Tests\Core;

use Bga\Games\loaf\Core\ReviewEffectDescription;
use PHPUnit\Framework\TestCase;

final class ReviewEffectDescriptionTest extends TestCase
{
    public function testTargetForEachTargetType(): void
    {
        $this->assertSame(
            'the lowest-reputation player(s)',
            ReviewEffectDescription::target(['target' => 'lowest_reputation', 'effect' => 'reputation', 'amount' => 1, 'counts_as_two' => false])
        );
        $this->assertSame(
            'every player',
            ReviewEffectDescription::target(['target' => 'all', 'effect' => 'reputation', 'amount' => 1, 'counts_as_two' => false])
        );
        $this->assertSame(
            'no one',
            ReviewEffectDescription::target(['target' => null, 'effect' => 'none', 'amount' => null, 'counts_as_two' => false])
        );
    }

    public function testDoublerEffectsTargetEveryPlayerRegardlessOfStoredTarget(): void
    {
        // RoundCardData stores target as null for the doublers (same shape as the genuinely
        // target-less `none` effect) -- target() must special-case the effect type, not fall
        // through to the null-target 'no one' branch.
        $this->assertSame(
            'every player',
            ReviewEffectDescription::target(['target' => null, 'effect' => 'double_end_game_bonus', 'amount' => null, 'counts_as_two' => false])
        );
    }

    /**
     * @dataProvider effectMessageProvider
     */
    public function testEffectMessageIsFullyFlatWithNoNestedPlaceholderLeftUnaccountedFor(
        array $effect,
        string $side,
        string $framing,
        string $expectedMessage,
        array $expectedArgs,
        array $expectedI18nArgs
    ): void {
        $built = ReviewEffectDescription::effectMessage($effect, $side, $framing);

        $this->assertSame($expectedMessage, $built['message']);
        $this->assertSame($expectedArgs, $built['args']);
        $this->assertSame($expectedI18nArgs, $built['i18nArgs']);

        // Every placeholder the message references must have a matching arg (target is
        // merged in by the caller, never by effectMessage() itself -- see its own docblock).
        preg_match_all('/\$\{(\w+)}/', $built['message'], $matches);
        foreach ($matches[1] as $placeholder) {
            if ($placeholder === 'target') {
                continue;
            }
            $this->assertArrayHasKey(
                $placeholder,
                $built['args'],
                "Message references \${$placeholder} but effectMessage() didn't return a matching arg"
            );
        }
    }

    public static function effectMessageProvider(): array
    {
        $reputation = ['target' => 'lowest_reputation', 'effect' => 'reputation', 'amount' => 3, 'counts_as_two' => false];
        $endGameMalus = ['target' => 'highest_reputation', 'effect' => 'end_game_malus', 'amount' => 2, 'counts_as_two' => false];
        $discardChoice = ['target' => 'all', 'effect' => 'discard_choice', 'amount' => null, 'counts_as_two' => false];
        $countsAsTwoNone = ['target' => null, 'effect' => 'none', 'amount' => null, 'counts_as_two' => true];

        return [
            'reputation, reviewEffectApplied framing' => [
                $reputation, 'success', 'reviewEffectApplied',
                'Review effect: ${target}, ${amount} reputation',
                ['amount' => '+3'],
                [],
            ],
            'reputation, onSuccess framing' => [
                $reputation, 'success', 'onSuccess',
                'On success: ${target}, ${amount} reputation',
                ['amount' => '+3'],
                [],
            ],
            'reputation, onFail framing' => [
                $reputation, 'fail', 'onFail',
                'On fail: ${target}, ${amount} reputation',
                ['amount' => '+3'],
                [],
            ],
            // Sign is negated relative to the stored (always-positive) magnitude -- the
            // malus is a penalty, so it must display as a negative number.
            'end_game_malus negates the stored positive magnitude' => [
                $endGameMalus, 'fail', 'reviewEffectApplied',
                'Review effect: ${target}, ${amount} penalty at game end',
                ['amount' => '-2'],
                [],
            ],
            'discard_choice has no amount at all' => [
                $discardChoice, 'success', 'reviewEffectApplied',
                'Review effect: ${target}, discarding a card of their choice from hand',
                [],
                [],
            ],
            'counts_as_two + none, success side files to the Happy pile' => [
                $countsAsTwoNone, 'success', 'reviewEffectApplied',
                'Review effect: ${target}, having no effect -- counts as 2 cards toward the ${pile} Boss pile, not 1',
                ['pile' => 'Happy'],
                ['pile'],
            ],
            'counts_as_two + none, fail side files to the Angry pile' => [
                $countsAsTwoNone, 'fail', 'onFail',
                'On fail: ${target}, having no effect -- counts as 2 cards toward the ${pile} Boss pile, not 1',
                ['pile' => 'Angry'],
                ['pile'],
            ],
        ];
    }
}
