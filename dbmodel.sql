
-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- loaf implementation : © <Your name here> <Your email address here>
--
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----

-- This is the file where you are describing the database schema of your game
-- Basically, you just have to export from PhpMyAdmin your table structure and copy/paste
-- this export here.
-- Note that the database itself and the standard tables ("global", "stats", "gamelog" and "player") are
-- already created and must not be created here

-- Note: The database schema is created from this file when the game starts. If you modify this file,
--       you have to restart a game to see your changes in database.

-- Reputation track (-10..+10, see Core\ReputationTrack) and end-of-game firing status
-- (Angry-Boss ending: negative reputation = fired, see docs/loaf-implementation-plan.md).
ALTER TABLE `player` ADD `player_reputation` INT NOT NULL DEFAULT 0;
ALTER TABLE `player` ADD `player_fired` BOOL NOT NULL DEFAULT 0;

-- Each player's fixed personal hand of work cards (0-11, one of each), not drawn from a
-- shared pool. `played` = committed face-down this round, hidden from other players until
-- ResolveRound reveals and moves it to `discard`.
CREATE TABLE IF NOT EXISTS `work_card` (
  `player_id` INT UNSIGNED NOT NULL,
  `value` TINYINT UNSIGNED NOT NULL,
  `location` ENUM('hand','played','discard') NOT NULL DEFAULT 'hand',
  PRIMARY KEY (`player_id`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The shared round-card deck (order/review sides, see docs/loaf-card-data.json and
-- Game::$ROUND_CARD_TYPES). Shaped to match BGA's Deck component convention. `card_type`
-- holds the static content id (e.g. "basic_01"). Locations: deck, revealed_review,
-- review_happy, review_angry.
CREATE TABLE IF NOT EXISTS `round_card` (
  `card_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_type` VARCHAR(16) NOT NULL,
  `card_type_arg` INT NOT NULL DEFAULT 0,
  `card_location` VARCHAR(16) NOT NULL,
  `card_location_arg` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;
