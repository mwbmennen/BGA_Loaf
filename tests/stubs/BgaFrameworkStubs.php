<?php

declare(strict_types=1);

// Global BGA helper functions
namespace {
    function clienttranslate(string $str): string
    {
        return $str;
    }
}

namespace Bga\GameFramework\Helpers {

    class Gamestate
    {
        public function getCurrentMainStateId(): int { return 0; }
        public function nextState(string $_transition = ''): void {}
        public function changeActivePlayer(int $_playerId): void {}
        public function jumpToState(int $_stateId): void {}
    }

    class PlayerStats
    {
        public function init(array $_statNames, int $_defaultValue): void {}
        public function inc(string $_statName, int $_increment, int $_playerId): void {}
    }

    class PlayerScore
    {
        // Real signature, confirmed live on Studio after a previous guess (`setAll(int
        // $_score, mixed $_tieBreaker)`) threw a fatal TypeError: `setAll()` sets the
        // SAME value for every player, with an optional notification message -- it is NOT a
        // tiebreak API. Kept stubbed with the correct shape so it can't mislead a future
        // caller the same way again. See docs/bga-studio-reference.md's `playerScoreAux`
        // entry for the full story.
        public function setAll(int $_value, mixed $_message = null): void {}
        public function set(int $_playerId, int $_score): void {}
        public function inc(int $_playerId, int $_increment): void {}
        public function getMin(): int { return 0; }
    }

    class Notify
    {
        public function all(string $_type, string $_message, array $_args = []): void {}
        public function player(int $_playerId, string $_type, string $_message, array $_args = []): void {}
    }

    class TableOptions
    {
        public function get(int $_optionId): int|string { return 0; }
    }

    class DebugHelper
    {
        public function playUntil(callable $_condition): void {}
    }

    class Bga
    {
        public PlayerStats $playerStats;
        public PlayerScore $playerScore;
        // The tiebreak counter -- confirmed live (BGA's own docs, Main_game_logic:_Game.php)
        // to be a second PlayerCounter, updated exactly like playerScore via the same
        // set()/inc() methods. Requires `tie_breaker_description` in gameinfos.jsonc. See
        // docs/bga-studio-reference.md's tiebreak entry for the full incident writeup -- two
        // earlier guesses (`playerScore->setAll()`, then `Table::DbScoreAux()`) both threw
        // fatal errors live before this one was confirmed correct.
        public PlayerScore $playerScoreAux;
        public TableOptions $tableOptions;
        public DebugHelper $debug;
        public Notify $notify;

        public function __construct()
        {
            $this->playerStats    = new PlayerStats();
            $this->playerScore    = new PlayerScore();
            $this->playerScoreAux = new PlayerScore();
            $this->tableOptions   = new TableOptions();
            $this->debug          = new DebugHelper();
            $this->notify       = new Notify();
        }
    }
}

namespace Bga\GameFramework {

    use Bga\GameFramework\Helpers\Bga;
    use Bga\GameFramework\Helpers\Gamestate;

    enum StateType: string
    {
        case ACTIVE_PLAYER = 'activeplayer';
        case MULTIPLE_ACTIVE_PLAYER = 'multipleactiveplayer';
        case PRIVATE = 'private';
        case GAME = 'game';
        case MANAGER = 'manager';
    }

    abstract class Table
    {
        public Bga $bga;
        public Gamestate $gamestate;
        public array $capturedGameStateLabels = [];

        public function __construct()
        {
            $this->bga       = new Bga();
            $this->gamestate = new Gamestate();
        }

        public function initGameStateLabels(array $_stateLabels): void
        {
            $this->capturedGameStateLabels = $_stateLabels;
        }

        public function activeNextPlayer(): int|string { return 0; }

        public function getPlayerAfter(int $_playerId): int { return 0; }

        public function giveExtraTime(int $_playerId, ?int $_specificTime = null): void {}

        // Database helpers
        public function DbQuery(string $_sql): void {}

        /** FIFO queue of return values for successive DbAffectedRow() calls; exhausted/empty
         *  defaults to 1 (a row was affected), so tests that never touch this see normal
         *  behavior. */
        public array $affectedRowsQueue = [];

        public function DbAffectedRow(): int
        {
            return array_shift($this->affectedRowsQueue) ?? 1;
        }

        public function DbGetLastId(): int { return 0; }

        public function getObjectListFromDb(string $_sql, bool $_bUniqueValue = false): array { return []; }

        public function getCollectionFromDb(string $_sql, bool $_bSingleValue = false): array { return []; }

        public function getDoubleKeyCollectionFromDb(string $_sql, bool $_bSingleValue = false): array { return []; }

        public function getUniqueValueFromDb(string $_sql): ?string { return null; }

        // Player helpers
        public function loadPlayersBasicInfos(): array { return []; }

        public function reloadPlayersBasicInfos(): void {}

        public function getGameinfos(): array
        {
            // Mirrors gameinfos.jsonc's real "player_colors" list (kept in sync manually --
            // this is a generic BGA-framework shape, not specific to any one game's logic).
            return [
                'player_colors' => [
                    'ff0000', '008000', '0000ff', 'ffa500',
                    'e94190', '982fff', '72c3b1', 'f07f16',
                    'bdd002', '7b7b7b', '000000',
                ],
            ];
        }

        public function reattributeColorsBasedOnPreferences(array $_players, array $_colors): array
        {
            return $_colors;
        }

        public function getActivePlayerId(): int { return 0; }

        // Real API per BGA's own docs (Practical_debugging): distinguishes 'studio' from
        // live/production so debug_-prefixed methods can gate themselves explicitly rather than
        // trusting an unconfirmed platform restriction (see Game::debug_goToState()'s docblock).
        // Defaults to 'studio' here so PHPUnit's own environment reads as the dev/test context
        // it actually is.
        public function getBgaEnvironment(): string { return 'studio'; }

        public function getCurrentPlayerId(): int { return 0; }

        public function getPlayerNameById(int $_playerId): string { return ''; }

        // Game state helpers
        public function getGameStateValue(string $_name): int { return 0; }

        public function setGameStateValue(string $_name, int $_value): void {}

        public function incGameStateValue(string $_name, int $_increment): int { return 0; }

        // Undo (https://en.doc.boardgamearena.com/BGA_Undo_policy) -- trivial no-ops only: no
        // local stateful DB snapshot/restore exists here, so PHPUnit can verify our own
        // precondition-gating logic (e.g. "is undo allowed right now") is checked at the right
        // times, never that a restore actually rewinds anything -- that needs a live Studio
        // session. If your game's tests need to assert the integration point fired, give your
        // own game-specific test double (a "FakeGame" extending your real Game class) call-
        // tracking overrides of these two methods.
        public function undoSavepoint(): void {}

        public function undoRestorePoint(): void {}

        // Notifications
        public function notifyAllPlayers(string $_type, string $_message, array $_args = []): void {}

        public function notifyPlayer(int $_playerId, string $_type, string $_message, array $_args = []): void {}

        // Stats
        public function incStat(string $_statName, int $_increment, ?int $_playerId = null): void {}

        public function setStat(string $_statName, int $_value, ?int $_playerId = null): void {}

        // Logging
        public function trace(string $_message): void {}

        public function debug(string $_message): void {}
    }
}

namespace Bga\GameFramework {

    class UserException extends \RuntimeException {}
    class SystemException extends \RuntimeException {}
}

namespace Bga\GameFramework\States {

    use Bga\GameFramework\StateType;

    #[\Attribute]
    class PossibleAction {}

    abstract class GameState
    {
        public \Bga\GameFramework\Helpers\Bga $bga;
        public \Bga\GameFramework\Helpers\PlayerStats $playerStats;

        public function __construct(
            mixed $_game,
            public int $id,
            public StateType $type,
            public bool $updateGameProgression = false,
        ) {
            $this->bga         = new \Bga\GameFramework\Helpers\Bga();
            $this->playerStats = new \Bga\GameFramework\Helpers\PlayerStats();
        }

        public function getRandomZombieChoice(array $_choices): array { return $_choices[0] ?? []; }
    }
}

namespace Bga\GameFramework\GameResult {

    class Player
    {
        public int $score;

        public function __construct(
            public readonly int $playerId,
            int $score,
        ) {
            $this->score = $score;
        }

        public static function fromPlayersDb(array $_playersDb): array { return []; }
    }

    class GameResult
    {
        public function __construct(public readonly array $_players = []) {}

        public static function individualRanking(array $_players, bool $_reverseScore = false): static
        {
            return new static();
        }
    }
}
