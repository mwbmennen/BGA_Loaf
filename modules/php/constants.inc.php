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

// Code-only toggle (not a BGA table option) -- flip to true locally when you need a
// copy-pasteable transcript for feeding a live-tested bug to an AI/debugging session, then
// flip back to false before redeploying. Read via Game::debugTranscriptEnabled(), which
// Game::traceDebug() checks internally -- that's the single shared call every trace-transcript
// line in States/ResolveRound.php (one line per round, one per reputation-effect, one per
// discard-recycle), States/ResolveAdvancedEffect.php (one line per discard_choice/swap
// resolution -- covers zombie-triggered ones too, since zombie() routes through the same
// actDiscardChoice()/actSwapDiscard() methods), and States/EndGame.php (one line for the final
// scoring) goes through, so the transcript shows up in BGA Studio's own trace log, not in any
// player-facing UI. See docs/loaf-seed-replay-plan.md's "v2 transcript" note.
//
// Defaults to false -- this alone is not the only safety net (Game::debugTranscriptEnabled()
// additionally requires getBgaEnvironment() === 'studio'), but the constant should still ship
// off so a local debugging session never has to be remembered to be turned back off.
const DEBUG_LOG_ROUND_TRANSCRIPT = false;
