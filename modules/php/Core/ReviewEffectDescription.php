<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Plain-text, translatable descriptions of a review effect's target/amount, for game-log
 * notifications. Shared by two call sites that need the exact same wording for different
 * purposes: RoundStart's `reviewCardRevealed` (speculative -- describes *both* sides before
 * either has happened) and ResolveRound's `reviewEffectApplied` (describes only the one side
 * that actually resolved). Pure/DB-free -- see docs/loaf-implementation-plan.md §2.
 */
final class ReviewEffectDescription
{
    /**
     * @param array{target: ?string, effect: string, amount: ?int, counts_as_two: bool} $effect
     */
    public static function target(array $effect): string
    {
        // The two doubler effects don't target a player group at all -- they modify the
        // *totals* of every other end-game bonus/malus effect, "apply to all players" per the
        // rulebook (docs/loaf-phase4-plan.md §3 point 3). Their `target` is null in
        // RoundCardData (same shape as the genuinely target-less `none` effect), which would
        // otherwise fall into the generic null-target 'no one' case below and misleadingly
        // read as "on success, no one (doubles every end-game bonus)" -- confirmed live.
        if (in_array($effect['effect'], ['double_end_game_bonus', 'double_end_game_malus'], true)) {
            return clienttranslate('every player');
        }

        return match ($effect['target']) {
            'lowest_reputation' => clienttranslate('the lowest-reputation player(s)'),
            'highest_reputation' => clienttranslate('the highest-reputation player(s)'),
            'reputation_positive' => clienttranslate('every player with positive reputation'),
            'reputation_negative' => clienttranslate('every player with negative reputation'),
            'reputation_zero' => clienttranslate('every player at zero reputation'),
            'all' => clienttranslate('every player'),
            default => clienttranslate('no one'),
        };
    }

    /**
     * Every non-reputation arm here is deliberately a gerund phrase ("recycling...",
     * "discarding...") rather than a conjugated verb ("recycles...") -- the target phrases
     * this gets appended to don't all agree on grammatical number ("every player" is singular,
     * "the lowest-reputation player(s)" is deliberately ambiguous), and a gerund reads
     * naturally after a comma regardless of the target's number, sidestepping the need for a
     * verb-conjugation branch per target/effect combination.
     *
     * @param array{target: ?string, effect: string, amount: ?int, counts_as_two: bool} $effect
     * @param 'success'|'fail' $side Which side of the card $effect is -- needed only to name
     *     the right boss pile in the counts_as_two note below; `success` always files to the
     *     Happy pile and `fail` to the Angry pile (same mapping ResolveRound.php's own
     *     `$bossPile = $result->success ? 'review_happy' : 'review_angry'` uses), never
     *     data-dependent, so it's safe to hardcode that correspondence here.
     */
    public static function amount(array $effect, string $side): string
    {
        $description = match ($effect['effect']) {
            'reputation' => str_replace(
                '${amount}',
                sprintf('%+d', $effect['amount']),
                clienttranslate('${amount} reputation')
            ),
            'discard_recycle_lowest' => clienttranslate('recycling their lowest discard-pile card back to hand'),
            'discard_choice' => clienttranslate('discarding a card of their choice from hand'),
            'swap_discard_lower_by_at_most' => str_replace(
                '${amount}',
                (string) $effect['amount'],
                clienttranslate('taking their played card back, then discarding one at most ${amount} lower')
            ),
            'swap_discard_higher_by_at_least' => str_replace(
                '${amount}',
                (string) $effect['amount'],
                clienttranslate('taking their played card back, then discarding one at least ${amount} higher')
            ),
            'end_game_bonus' => str_replace(
                '${amount}',
                sprintf('%+d', $effect['amount']),
                clienttranslate('${amount} bonus at game end')
            ),
            // RoundCardData stores end_game_malus's amount as a positive magnitude (the minus
            // sign is applied by whoever consumes it, e.g. EndGameEffectResolver) -- negate it
            // here so the displayed sign matches what actually happens to the score.
            'end_game_malus' => str_replace(
                '${amount}',
                sprintf('%+d', -$effect['amount']),
                clienttranslate('${amount} penalty at game end')
            ),
            'double_end_game_bonus' => clienttranslate('doubling every end-game bonus'),
            'double_end_game_malus' => clienttranslate('doubling every end-game penalty'),
            'none' => clienttranslate('having no effect'),
            // Every real effect type is covered above -- this is structurally required by
            // `match` (it throws UnhandledMatchError on no match, unlike `switch`), not a real
            // reachable case. Kept only as a defensive fallback against a future new effect
            // type or a data typo, same "correctness against future rule changes" discipline
            // as EndGame.php's own empty-hand fallback.
            default => clienttranslate('triggering an unrecognized effect'),
        };

        // Checked independently of $effect['effect'] -- currently only the two "empty effect"
        // cards (advanced_07/advanced_08's `none` sides) ever set this, but the flag itself,
        // not the effect type, is what actually drives EndConditionChecker::weightedCount(),
        // so a future card pairing it with a real effect would still need this note (confirmed
        // live: a reader had no way to tell this side ends the game faster than usual without
        // it -- docs/loaf-remarks.md's Phase 4 entry).
        if ($effect['counts_as_two']) {
            $pileName = $side === 'success' ? clienttranslate('Happy') : clienttranslate('Angry');
            $description = str_replace(
                ['${description}', '${pile}'],
                [$description, $pileName],
                clienttranslate('${description} -- counts as 2 cards toward the ${pile} Boss pile, not 1')
            );
        }

        return $description;
    }
}
