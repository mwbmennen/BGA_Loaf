<?php

declare(strict_types=1);

namespace Bga\Games\loaf\Core;

/**
 * Static content for the 24 physical round cards (see docs/loaf-card-data.json). Every card
 * carries both sides even though Phase 1 only reads `order.per_player_average` --
 * review-effect resolution (ReviewEffectResolver) is a later phase, and porting the full data
 * now avoids a second transcription pass. Only the 12 `basic_*` entries are shuffled into the
 * deck at setup; `advanced_*` entries wait for the Phase 4 opt-in table option.
 *
 * Pure/DB-free (no BGA dependency) so it's reusable outside a live BGA table -- see
 * docs/loaf-implementation-plan.md §2, "Why pure, DB-free matters beyond PHPUnit".
 */
final class RoundCardData
{
    /**
     * @var array<string, array{
     *     advanced: bool,
     *     order: array{per_player_average: int},
     *     review: array{success: array, fail: array},
     * }>
     */
    public const TYPES = [
        'basic_01' => [
            'advanced' => false,
            'order' => ['per_player_average' => 3],
            'review' => [
                'success' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'reputation',
                    'amount' => 1,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'reputation',
                    'amount' => -1,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_02' => [
            'advanced' => false,
            'order' => ['per_player_average' => 4],
            'review' => [
                'success' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'reputation',
                    'amount' => 2,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'reputation',
                    'amount' => -2,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_03' => [
            'advanced' => false,
            'order' => ['per_player_average' => 5],
            'review' => [
                'success' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'reputation',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'reputation',
                    'amount' => -3,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_04' => [
            'advanced' => false,
            'order' => ['per_player_average' => 6],
            'review' => [
                'success' => [
                    'target' => 'reputation_negative',
                    'effect' => 'reputation',
                    'amount' => 2,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_negative',
                    'effect' => 'reputation',
                    'amount' => -2,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_05' => [
            'advanced' => false,
            'order' => ['per_player_average' => 7],
            'review' => [
                'success' => [
                    'target' => 'reputation_negative',
                    'effect' => 'reputation',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_negative',
                    'effect' => 'reputation',
                    'amount' => -3,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_06' => [
            'advanced' => false,
            'order' => ['per_player_average' => 8],
            'review' => [
                'success' => [
                    'target' => 'reputation_negative',
                    'effect' => 'reputation',
                    'amount' => 4,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_negative',
                    'effect' => 'reputation',
                    'amount' => -4,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_07' => [
            'advanced' => false,
            'order' => ['per_player_average' => 4],
            'review' => [
                'success' => [
                    'target' => 'highest_reputation',
                    'effect' => 'reputation',
                    'amount' => 1,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'highest_reputation',
                    'effect' => 'reputation',
                    'amount' => -1,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_08' => [
            'advanced' => false,
            'order' => ['per_player_average' => 5],
            'review' => [
                'success' => [
                    'target' => 'highest_reputation',
                    'effect' => 'reputation',
                    'amount' => 2,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'highest_reputation',
                    'effect' => 'reputation',
                    'amount' => -2,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_09' => [
            'advanced' => false,
            'order' => ['per_player_average' => 6],
            'review' => [
                'success' => [
                    'target' => 'highest_reputation',
                    'effect' => 'reputation',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'highest_reputation',
                    'effect' => 'reputation',
                    'amount' => -3,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_10' => [
            'advanced' => false,
            'order' => ['per_player_average' => 7],
            'review' => [
                'success' => [
                    'target' => 'reputation_positive',
                    'effect' => 'reputation',
                    'amount' => 2,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_positive',
                    'effect' => 'reputation',
                    'amount' => -2,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_11' => [
            'advanced' => false,
            'order' => ['per_player_average' => 5],
            'review' => [
                'success' => [
                    'target' => 'reputation_positive',
                    'effect' => 'reputation',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_positive',
                    'effect' => 'reputation',
                    'amount' => -3,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'basic_12' => [
            'advanced' => false,
            'order' => ['per_player_average' => 6],
            'review' => [
                'success' => [
                    'target' => 'reputation_positive',
                    'effect' => 'reputation',
                    'amount' => 4,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_positive',
                    'effect' => 'reputation',
                    'amount' => -4,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_01' => [
            'advanced' => true,
            'order' => ['per_player_average' => 7],
            'review' => [
                'success' => [
                    'target' => 'reputation_negative',
                    'effect' => 'discard_recycle_lowest',
                    'amount' => null,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_negative',
                    'effect' => 'discard_choice',
                    'amount' => null,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_02' => [
            'advanced' => true,
            'order' => ['per_player_average' => 5],
            'review' => [
                'success' => [
                    'target' => 'reputation_positive',
                    'effect' => 'discard_recycle_lowest',
                    'amount' => null,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_positive',
                    'effect' => 'discard_choice',
                    'amount' => null,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_03' => [
            'advanced' => true,
            'order' => ['per_player_average' => 6],
            'review' => [
                'success' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'swap_discard_lower_by_at_most',
                    'amount' => 2,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'swap_discard_higher_by_at_least',
                    'amount' => 2,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_04' => [
            'advanced' => true,
            'order' => ['per_player_average' => 3],
            'review' => [
                'success' => [
                    'target' => 'reputation_negative',
                    'effect' => 'swap_discard_lower_by_at_most',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_negative',
                    'effect' => 'swap_discard_higher_by_at_least',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_05' => [
            'advanced' => true,
            'order' => ['per_player_average' => 4],
            'review' => [
                'success' => [
                    'target' => 'highest_reputation',
                    'effect' => 'swap_discard_lower_by_at_most',
                    'amount' => 2,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'highest_reputation',
                    'effect' => 'swap_discard_higher_by_at_least',
                    'amount' => 2,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_06' => [
            'advanced' => true,
            'order' => ['per_player_average' => 5],
            'review' => [
                'success' => [
                    'target' => 'reputation_positive',
                    'effect' => 'swap_discard_lower_by_at_most',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_positive',
                    'effect' => 'swap_discard_higher_by_at_least',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_07' => [
            'advanced' => true,
            'order' => ['per_player_average' => 6],
            'review' => [
                'success' => [
                    'target' => null,
                    'effect' => 'none',
                    'amount' => null,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => null,
                    'effect' => 'none',
                    'amount' => null,
                    'counts_as_two' => true,
                ],
            ],
        ],
        'advanced_08' => [
            'advanced' => true,
            'order' => ['per_player_average' => 7],
            'review' => [
                'success' => [
                    'target' => null,
                    'effect' => 'none',
                    'amount' => null,
                    'counts_as_two' => true,
                ],
                'fail' => [
                    'target' => null,
                    'effect' => 'none',
                    'amount' => null,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_09' => [
            'advanced' => true,
            'order' => ['per_player_average' => 8],
            'review' => [
                'success' => [
                    'target' => 'highest_reputation',
                    'effect' => 'end_game_bonus',
                    'amount' => 4,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'lowest_reputation',
                    'effect' => 'end_game_malus',
                    'amount' => 4,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_10' => [
            'advanced' => true,
            'order' => ['per_player_average' => 4],
            'review' => [
                'success' => [
                    'target' => 'reputation_positive',
                    'effect' => 'end_game_bonus',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_negative',
                    'effect' => 'end_game_malus',
                    'amount' => 3,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_11' => [
            'advanced' => true,
            'order' => ['per_player_average' => 5],
            'review' => [
                'success' => [
                    'target' => null,
                    'effect' => 'double_end_game_bonus',
                    'amount' => null,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => null,
                    'effect' => 'double_end_game_malus',
                    'amount' => null,
                    'counts_as_two' => false,
                ],
            ],
        ],
        'advanced_12' => [
            'advanced' => true,
            'order' => ['per_player_average' => 6],
            'review' => [
                'success' => [
                    'target' => 'reputation_zero',
                    'effect' => 'end_game_bonus',
                    'amount' => 5,
                    'counts_as_two' => false,
                ],
                'fail' => [
                    'target' => 'reputation_zero',
                    'effect' => 'end_game_malus',
                    'amount' => 5,
                    'counts_as_two' => false,
                ],
            ],
        ],
    ];
}
