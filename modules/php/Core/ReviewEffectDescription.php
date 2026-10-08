<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Plain-text, translatable descriptions of a review effect's target/amount.
 *
 * `target()` is a plain lookup -- its return values are always one of a handful of static,
 * already-registered clienttranslate() strings with no embedded placeholder, so a caller can
 * safely pass it straight through as a `notify->all()` arg marked `'i18n' => ['target']` (or
 * similar) and the client will independently translate it per recipient.
 *
 * `effectMessage()` (added 2026-10-07, replacing the old fragment-returning `amount()`) is
 * NOT a fragment to compose into another message's own `${...}` placeholder -- it returns a
 * complete, self-contained [message, args] pair meant to BE the notify call's own top-level
 * message. Confirmed live via BGA's `&dummyTranslations` test (see
 * docs/loaf-remarks.md's "Notification game-log text can't actually be translated..." entry)
 * that the old design was a real bug, not a theoretical one: `amount()` baked the real number
 * into the string server-side (`str_replace('${amount}', '+1', clienttranslate('${amount}
 * reputation'))`), producing e.g. "+1 reputation" -- a string that can never match any
 * dictionary key, since only the un-substituted template was ever registered. Nesting that
 * baked-in fragment as an arg inside an *outer* clienttranslate() message (e.g. "Review
 * effect: ${target}, ${amount}") compounded the problem: even marking the arg `i18n` wouldn't
 * have helped, because the dictionary lookup would still miss on the already-substituted text.
 *
 * The fix: every (framing, effect type) combination gets its OWN complete literal
 * clienttranslate() string, with `target` and the raw number as that *same* message's direct,
 * top-level args -- one level of substitution, no nesting, exactly like every other
 * already-correct notification in this codebase (e.g. `${player_name} played ${value}`).
 * `clienttranslate()` requires a literal argument (no runtime concatenation of a shared prefix
 * into a shared suffix -- that breaks extraction, see bga-studio-reference.md's "Wrap every
 * user-facing string..." section), so each framing needs its own full set of per-effect-type
 * literals -- this is mechanical duplication, not a design flaw.
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
     * One complete, self-contained [message, args, i18nArgs] triple for one side of a review
     * effect, for a specific notification call site's own framing. The caller still needs to
     * merge in `target()`'s own return value under the `target` key the message references,
     * and mark it `'i18n'` alongside whatever `i18nArgs` already lists (e.g. `['target',
     * ...$built['i18nArgs']]`) -- `target`/`pile` are always clean, static, already-registered
     * strings with no embedded placeholder, safe to translate independently. `amount` (when
     * present) is always just a formatted number, never translated, so it's never in
     * `i18nArgs`.
     *
     * Every non-reputation/non-swap arm is deliberately a gerund phrase ("recycling...",
     * "discarding...") following the target with a comma, not a conjugated verb
     * ("recycles...") -- the target phrases don't all agree on grammatical number ("every
     * player" is singular, "the lowest-reputation player(s)" is deliberately ambiguous), and a
     * gerund reads naturally after a comma regardless of the target's number, sidestepping a
     * verb-conjugation branch per target/effect combination. Preserved unchanged from the
     * pre-2026-10-07 fragment-based wording -- only the assembly changed, not the phrasing.
     *
     * @param array{target: ?string, effect: string, amount: ?int, counts_as_two: bool} $effect
     * @param 'success'|'fail' $side Which side of the card $effect is -- needed only to name
     *     the right boss pile in the counts_as_two case below; `success` always files to the
     *     Happy pile and `fail` to the Angry pile (same mapping ResolveRound.php's own
     *     `$bossPile = $result->success ? 'review_happy' : 'review_angry'` uses), never
     *     data-dependent, so it's safe to hardcode that correspondence here.
     * @param 'reviewEffectApplied'|'onSuccess'|'onFail' $framing Which call site this is for --
     *     'reviewEffectApplied' is ResolveRound's single-side notification; 'onSuccess'/
     *     'onFail' are RoundStart's two notifications (split from one combined
     *     "on success, X; on fail, Y" sentence specifically because that combined shape can't
     *     be flattened into one literal per effect type without also enumerating every
     *     (success-effect x fail-effect) pair -- see docs/loaf-remarks.md's matching entry for
     *     why that tradeoff was chosen over keeping one notification).
     * @return array{message: string, args: array<string, mixed>, i18nArgs: string[]}
     */
    public static function effectMessage(array $effect, string $side, string $framing): array
    {
        // Reachable only for the two "empty effect" advanced cards (advanced_07/advanced_08's
        // `none` sides) per the current card data (docs/loaf-card-data.json) -- counts_as_two
        // on any other effect type is unconfirmed by any real card, so only 'none' gets its own
        // literal per framing here. A future card ever pairing counts_as_two with a different
        // effect type would fall through to the plain (non-counts_as_two) phrasing below,
        // silently dropping the "counts as 2" note -- same "correctness against future rule
        // changes, not current reachability" discipline as this class's other defensive
        // fallbacks, but flagged here since this one genuinely would need a new arm added.
        if ($effect['counts_as_two'] && $effect['effect'] === 'none') {
            $pile = $side === 'success' ? clienttranslate('Happy') : clienttranslate('Angry');
            $message = match ($framing) {
                'reviewEffectApplied' => clienttranslate(
                    'Review effect: ${target}, having no effect -- counts as 2 cards toward the ${pile} Boss pile, not 1'
                ),
                'onSuccess' => clienttranslate(
                    'On success: ${target}, having no effect -- counts as 2 cards toward the ${pile} Boss pile, not 1'
                ),
                'onFail' => clienttranslate(
                    'On fail: ${target}, having no effect -- counts as 2 cards toward the ${pile} Boss pile, not 1'
                ),
            };

            return ['message' => $message, 'args' => ['pile' => $pile], 'i18nArgs' => ['pile']];
        }

        $amount = match ($effect['effect']) {
            'reputation' => sprintf('%+d', $effect['amount']),
            'swap_discard_lower_by_at_most', 'swap_discard_higher_by_at_least' => (string) $effect['amount'],
            'end_game_bonus' => sprintf('%+d', $effect['amount']),
            // RoundCardData stores end_game_malus's amount as a positive magnitude (the minus
            // sign is applied by whoever consumes it, e.g. EndGameEffectResolver) -- negate it
            // here so the displayed sign matches what actually happens to the score.
            'end_game_malus' => sprintf('%+d', -$effect['amount']),
            default => null,
        };

        $message = match ($framing) {
            'reviewEffectApplied' => match ($effect['effect']) {
                'reputation' => clienttranslate('Review effect: ${target}, ${amount} reputation'),
                'discard_recycle_lowest' => clienttranslate('Review effect: ${target}, recycling their lowest discard-pile card back to hand'),
                'discard_choice' => clienttranslate('Review effect: ${target}, discarding a card of their choice from hand'),
                'swap_discard_lower_by_at_most' => clienttranslate('Review effect: ${target}, taking their played card back, then discarding one at most ${amount} lower'),
                'swap_discard_higher_by_at_least' => clienttranslate('Review effect: ${target}, taking their played card back, then discarding one at least ${amount} higher'),
                'end_game_bonus' => clienttranslate('Review effect: ${target}, ${amount} bonus at game end'),
                'end_game_malus' => clienttranslate('Review effect: ${target}, ${amount} penalty at game end'),
                'double_end_game_bonus' => clienttranslate('Review effect: ${target}, doubling every end-game bonus'),
                'double_end_game_malus' => clienttranslate('Review effect: ${target}, doubling every end-game penalty'),
                'none' => clienttranslate('Review effect: ${target}, having no effect'),
                default => clienttranslate('Review effect: ${target}, triggering an unrecognized effect'),
            },
            'onSuccess' => match ($effect['effect']) {
                'reputation' => clienttranslate('On success: ${target}, ${amount} reputation'),
                'discard_recycle_lowest' => clienttranslate('On success: ${target}, recycling their lowest discard-pile card back to hand'),
                'discard_choice' => clienttranslate('On success: ${target}, discarding a card of their choice from hand'),
                'swap_discard_lower_by_at_most' => clienttranslate('On success: ${target}, taking their played card back, then discarding one at most ${amount} lower'),
                'swap_discard_higher_by_at_least' => clienttranslate('On success: ${target}, taking their played card back, then discarding one at least ${amount} higher'),
                'end_game_bonus' => clienttranslate('On success: ${target}, ${amount} bonus at game end'),
                'end_game_malus' => clienttranslate('On success: ${target}, ${amount} penalty at game end'),
                'double_end_game_bonus' => clienttranslate('On success: ${target}, doubling every end-game bonus'),
                'double_end_game_malus' => clienttranslate('On success: ${target}, doubling every end-game penalty'),
                'none' => clienttranslate('On success: ${target}, having no effect'),
                default => clienttranslate('On success: ${target}, triggering an unrecognized effect'),
            },
            'onFail' => match ($effect['effect']) {
                'reputation' => clienttranslate('On fail: ${target}, ${amount} reputation'),
                'discard_recycle_lowest' => clienttranslate('On fail: ${target}, recycling their lowest discard-pile card back to hand'),
                'discard_choice' => clienttranslate('On fail: ${target}, discarding a card of their choice from hand'),
                'swap_discard_lower_by_at_most' => clienttranslate('On fail: ${target}, taking their played card back, then discarding one at most ${amount} lower'),
                'swap_discard_higher_by_at_least' => clienttranslate('On fail: ${target}, taking their played card back, then discarding one at least ${amount} higher'),
                'end_game_bonus' => clienttranslate('On fail: ${target}, ${amount} bonus at game end'),
                'end_game_malus' => clienttranslate('On fail: ${target}, ${amount} penalty at game end'),
                'double_end_game_bonus' => clienttranslate('On fail: ${target}, doubling every end-game bonus'),
                'double_end_game_malus' => clienttranslate('On fail: ${target}, doubling every end-game penalty'),
                'none' => clienttranslate('On fail: ${target}, having no effect'),
                default => clienttranslate('On fail: ${target}, triggering an unrecognized effect'),
            },
        };

        return ['message' => $message, 'args' => $amount === null ? [] : ['amount' => $amount], 'i18nArgs' => []];
    }
}
