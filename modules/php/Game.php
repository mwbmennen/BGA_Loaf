<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * loaf implementation : © <Your name here> <Your email address here>
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * Game.php
 *
 * This is the main file for your game logic.
 *
 * In this PHP file, you are going to defines the rules of the game.
 */
declare(strict_types=1);

namespace Bga\Games\loaf;

use Bga\Games\loaf\Core\EndConditionChecker;
use Bga\Games\loaf\Core\RoundCardData;
use Bga\Games\loaf\States\RoundStart;
use Bga\GameFramework\Components\Deck;

require_once __DIR__ . '/constants.inc.php';

class Game extends \Bga\GameFramework\Table
{
    /**
     * Static content for the 24 physical round cards -- see `Core\RoundCardData::TYPES` for
     * the shape/contents. The 12 `basic_*` entries are always shuffled into the deck at
     * setup; the 12 `advanced_*` entries are added too only when the `with_advanced_cards`
     * table option (id OPTION_ADVANCED_CARDS) is on -- see setupNewGame().
     */
    public static array $ROUND_CARD_TYPES;

    public Deck $roundCards;

    /**
     * Your global variables labels:
     *
     * Here, you can assign labels to global variables you are using for this game. You can use any number of global
     * variables with IDs between 10 and 99. If you want to store any type instead of int, use $this->globals instead.
     *
     * NOTE: afterward, you can get/set the global variables with `getGameStateValue`, `setGameStateInitialValue` or
     * `setGameStateValue` functions.
     */
    public function __construct()
    {
        parent::__construct();

        $this->roundCards = $this->deckFactory->createDeck('round_card');

        self::$ROUND_CARD_TYPES = RoundCardData::TYPES;
    }

    /**
     * Compute and return the current game progression.
     *
     * The number returned must be an integer between 0 and 100.
     *
     * This method is called each time we are in a game state with the "updateGameProgression" property set to true.
     *
     * @return int
     */
    public function getGameProgression()
    {
        // 11 rounds is the most the 12-card basic deck can support (see docs/loaf-phase1-plan.md).
        $currentRound = (int) $this->bga->globals->get(GLOBAL_CURRENT_ROUND, 0);

        return (int) min(100, round(($currentRound / 11) * 100));
    }

    /**
     * Migrate database.
     *
     * You don't have to care about this until your game has been published on BGA. Once your game is on BGA, this
     * method is called everytime the system detects a game running with your old database scheme. In this case, if you
     * change your database scheme, you just have to apply the needed changes in order to update the game database and
     * allow the game to continue to run with your new version.
     *
     * @param int $from_version
     * @return void
     */
    public function upgradeTableDb($from_version)
    {
//       if ($from_version <= 1404301345)
//       {
//            // ! important ! Use `DBPREFIX_<table_name>` for all tables
//
//            $sql = "ALTER TABLE `DBPREFIX_xxxxxxx` ....";
//            $this->applyDbUpgradeToAllDB( $sql );
//       }
//
//       if ($from_version <= 1405061421)
//       {
//            // ! important ! Use `DBPREFIX_<table_name>` for all tables
//
//            $sql = "CREATE TABLE `DBPREFIX_xxxxxxx` ....";
//            $this->applyDbUpgradeToAllDB( $sql );
//       }
    }

    /*
     * Gather all information about current game situation (visible by the current player).
     *
     * The method is called each time the game interface is displayed to a player, i.e.:
     *
     * - when the game starts
     * - when a player refreshes the game page (F5)
     */
    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = [];
        // WARNING: We must only return information visible by the current player (using $currentPlayerId).

        $result['players'] = $this->getCollectionFromDb(
            'SELECT `player_id` AS `id`, `player_score` AS `score`, `player_reputation` AS `reputation`, ' .
                '`player_fired` AS `fired` FROM `player`'
        );

        // Own hand values in full; other players only get a hand count (never values), per
        // the "discard/hand visible only to owner" rule (docs/loaf-open-questions.md Q3). No
        // played/"committed" count -- not useful information once committed (docs/loaf-remarks.md's
        // Phase 4 entry).
        $result['handCount'] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id`, COUNT(*) AS `count` FROM `work_card` WHERE `location` = 'hand' GROUP BY `player_id`",
            true
        );
        $result['myHand'] = $this->getObjectListFromDb(
            "SELECT `value` FROM `work_card` WHERE `player_id` = $currentPlayerId AND `location` = 'hand' ORDER BY `value`",
            true
        );

        $result['currentRound'] = (int) $this->bga->globals->get(GLOBAL_CURRENT_ROUND, 0);
        $result['currentOrderAverage'] = (int) $this->bga->globals->get(GLOBAL_CURRENT_ORDER_AVERAGE, 0);

        // Boss piles: filed review cards are public once revealed, per the physical rules
        // ("slide it under the boss card so that only the effect part is visible").
        $happyCards = $this->roundCards->getCardsInLocation('review_happy');
        $angryCards = $this->roundCards->getCardsInLocation('review_angry');
        $result['bossHappy'] = $happyCards;
        $result['bossAngry'] = $angryCards;
        // The physical card count (above) and the *weighted* count that actually decides when
        // the game ends (EndConditionChecker -- a counts_as_two card is worth 2) are two
        // different numbers. The client's "X / 5" counter must show the weighted one, or a
        // counts_as_two card makes the game end while the displayed count still reads below 5
        // -- confirmed live (docs/loaf-remarks.md's Phase 4 entry).
        $result['bossHappyWeight'] = EndConditionChecker::weightedCount(array_column($happyCards, 'type'), 'success');
        $result['bossAngryWeight'] = EndConditionChecker::weightedCount(array_column($angryCards, 'type'), 'fail');

        return $result;
    }

    /**
     * This method is called only once, when a new game is launched. In this method, you must setup the game
     *  according to the game rules, so that the game is ready to be played.
     *
     * @param array $players Map of player_id => player info (as supplied by BGA framework).
     * @param array $options Map of table option id => chosen value.
     */
    protected function setupNewGame($players, $options = [])
    {
        // Set the colors of the players with HTML color code. The default below is red/green/blue/orange/brown. The
        // number of colors defined here must correspond to the maximum number of players allowed for the gams.
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        $query_values = [];
        foreach ($players as $player_id => $player) {
            // Now you can access both $player_id and $player array
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                addslashes($player["player_name"]),
            ]);
        }

        // Create players based on generic information.
        //
        // NOTE: You can add extra field on player table in the database (see dbmodel.sql) and initialize
        // additional fields directly here.
        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );
        // player_reputation/player_fired default to 0 via the schema (dbmodel.sql), no explicit init needed.

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        // Deal each player their fixed personal hand: one work card per value 0-11.
        $work_card_values = [];
        foreach (array_keys($players) as $player_id) {
            for ($value = 0; $value <= 11; $value++) {
                $work_card_values[] = "($player_id, $value, 'hand')";
            }
        }
        static::DbQuery(
            sprintf(
                'INSERT INTO `work_card` (`player_id`, `value`, `location`) VALUES %s',
                implode(',', $work_card_values)
            )
        );

        // Shuffle the round cards into the deck: the 12 basic cards always, plus the 12
        // advanced (croissant) cards only if the with_advanced_cards table option is on
        // (default off, matching the rulebook's own "we recommend not using the advanced
        // effects when playing for the first time" advice -- docs/loaf-phase4-plan.md §0/§6).
        //
        // Framework API confidence note (docs/bga-studio-reference.md §5-style caveat): the
        // exact key type $options uses for an option id isn't verified locally (no vendored
        // framework) -- checked defensively for both the int and string form rather than
        // guessing one, so a live surprise here doesn't silently default to "off" instead of
        // failing loudly. If neither key is ever present at all on Studio, that's the real
        // signal something about this assumption is wrong; investigate immediately rather
        // than shrug off a table that always builds with advanced cards off.
        $advanced_cards_enabled = (int) ($options[OPTION_ADVANCED_CARDS] ?? $options[(string) OPTION_ADVANCED_CARDS] ?? 0) === 1;
        $card_types = $advanced_cards_enabled
            ? self::$ROUND_CARD_TYPES
            : array_filter(self::$ROUND_CARD_TYPES, fn(array $card) => !$card['advanced']);
        $this->roundCards->createCards(
            array_map(fn(string $card_type) => ['type' => $card_type, 'type_arg' => 0, 'nbr' => 1], array_keys($card_types)),
            'deck'
        );
        $this->roundCards->shuffle('deck');

        $this->bga->globals->set(GLOBAL_CURRENT_ROUND, 0);

        // Init game statistics.
        //
        // NOTE: statistics used in this file must be defined in your `stats.inc.php` file.

        // Dummy content.
        // $this->tableStats->init('table_teststat1', 0);
        // $this->playerStats->init('player_teststat1', 0);

        return RoundStart::class;
    }

    /**
     * Example of debug function.
     * Here, jump to a state you want to test (by default, jump to next player state)
     * You can trigger it on Studio using the Debug button on the right of the top bar.
     */
    public function debug_goToState(int $state = 3) {
        $this->gamestate->jumpToState($state);
    }

    /**
     * Another example of debug function, to easily test the zombie code.
     */
    public function debug_playOneMove() {
        $this->bga->debug->playUntil(fn(int $count) => $count == 1);
    }

    /*
    Another example of debug function, to easily create situations you want to test.
    Here, put a card you want to test in your hand (assuming you use the Deck component).

    public function debug_setCardInHand(int $cardType, int $playerId) {
        $card = array_values($this->cards->getCardsOfType($cardType))[0];
        $this->cards->moveCard($card['id'], 'hand', $playerId);
    }
    */
}
