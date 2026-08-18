<?php

declare(strict_types=1);

// Game state ids for the RoundStart -> PlayCards -> ResolveRound loop (see
// docs/loaf-phase1-plan.md). EndGame keeps its own locally-scoped `ST_END_GAME = 99` constant
// in States/EndGame.php -- that's the framework's reserved end-of-game pseudo-state id, not a
// real state class, so it stays where the scaffold originally put it.
const ST_ROUND_START = 10;
const ST_PLAY_CARDS = 20;
const ST_RESOLVE_ROUND = 30;
// Entered from ResolveRound only for the advanced effects that need a player's own choice
// (discard_choice, the two swap effects) -- see States/ResolveAdvancedEffect.php and
// docs/loaf-phase4-plan.md §4.
const ST_RESOLVE_ADVANCED_EFFECT = 35;

// $this->bga->globals keys (see Bga\Games\loaf\Game).
const GLOBAL_CURRENT_ROUND = 'current_round';
const GLOBAL_CURRENT_REVIEW_CARD_ID = 'current_review_card_id';
const GLOBAL_CURRENT_ORDER_AVERAGE = 'current_order_average';
// Set true the moment ResolveRound reveals this round's played cards (cardPlayedRevealed),
// reset false at the start of the next round (RoundStart) -- lets getAllDatas() tell a
// reconnecting/refreshing client whether currently-`played` work_card rows are still hidden
// commit placeholders or already-revealed cards, since the DB itself has no persisted
// "revealed" flag (docs/loaf-remarks.md's Phase 5 §9 "committed card shows face-down after
// refresh" entry).
const GLOBAL_CARDS_REVEALED_THIS_ROUND = 'cards_revealed_this_round';

// gameoptions.jsonc option id (100-199 range) -- see Game::setupNewGame().
const OPTION_ADVANCED_CARDS = 100;
