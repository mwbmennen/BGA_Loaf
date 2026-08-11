<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

use InvalidArgumentException;

/**
 * Resolves the two advanced "swap" effects: `swap_discard_lower_by_at_most` (rulebook "Card,
 * -X": take the played card back, then discard a card at most X lower than it) and
 * `swap_discard_higher_by_at_least` ("Card, +X": same shape, at least X higher). Pure/DB-free
 * -- see docs/loaf-implementation-plan.md §2.
 *
 * Players pick freely among multiple eligible cards (confirmed, not assumed --
 * docs/loaf-phase4-plan.md §4), so this class doesn't decide *which* eligible card is
 * discarded; it computes the eligible set for the interactive state to offer, and validates/
 * finalizes whatever the player picks (or applies the deterministic fallback if there was
 * nothing eligible to pick from).
 */
final class SwapEffectResolver
{
    /**
     * @param int[] $hand Player's hand values AFTER their played card has been returned to
     *     it, per the rulebook's own step ordering ("take their played card back in hand.
     *     Then, they discard..."). $playedValue is expected to be one of these entries.
     * @param int $playedValue The value of the card the player played this round.
     * @param int $amount The effect's magnitude (X).
     * @param 'swap_discard_lower_by_at_most'|'swap_discard_higher_by_at_least' $effectType
     * @return int[] Eligible card values the player may choose to discard instead of the
     *     played card. Deliberately excludes $playedValue itself: the rulebook's "if they
     *     can't [find an eligible card], they discard the played card instead" phrasing treats
     *     discarding the played card back as the fallback, not a member of the primary
     *     eligible set -- an empty return here is exactly the "they can't" case.
     */
    public static function eligibleDiscards(array $hand, int $playedValue, int $amount, string $effectType): array
    {
        return array_values(array_filter(
            $hand,
            static fn(int $value): bool => $value !== $playedValue
                && self::matchesDirection($value, $playedValue, $amount, $effectType),
        ));
    }

    /**
     * Validates and finalizes which card is actually discarded for this effect.
     *
     * @param int|null $chosenValue The player's freely-chosen card value, if any eligible
     *     cards existed to choose from. Ignored (and may be null) when eligibleDiscards() is
     *     empty -- there's no real choice to make in that case, the fallback always applies.
     * @return int The card value to actually discard.
     * @throws InvalidArgumentException If eligible cards exist but $chosenValue isn't one of
     *     them -- never trust a client-supplied choice without validating it server-side.
     */
    public static function resolve(
        array $hand,
        int $playedValue,
        int $amount,
        string $effectType,
        ?int $chosenValue,
    ): int {
        $eligible = self::eligibleDiscards($hand, $playedValue, $amount, $effectType);

        if (empty($eligible)) {
            // Rulebook: "If they can't, they discard the played card instead."
            return $playedValue;
        }

        if ($chosenValue === null || !in_array($chosenValue, $eligible, true)) {
            throw new InvalidArgumentException(
                "Chosen value is not among the eligible discards: " . json_encode($eligible)
            );
        }

        return $chosenValue;
    }

    private static function matchesDirection(int $value, int $playedValue, int $amount, string $effectType): bool
    {
        return match ($effectType) {
            'swap_discard_lower_by_at_most' => $value < $playedValue && $playedValue - $value <= $amount,
            'swap_discard_higher_by_at_least' => $value > $playedValue && $value - $playedValue >= $amount,
            default => throw new InvalidArgumentException("Unknown swap effect type: $effectType"),
        };
    }
}
