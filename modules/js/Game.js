/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * loaf implementation : © <Your name here> <Your email address here>
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * In this file, you are describing the logic of your user interface, in Javascript language.
 *
 */

/**
 * We create one State class per declared state on the PHP side, to handle all state specific code here.
 * onEnteringState, onLeavingState and onPlayerActivationChange are predefined names that will be called by the framework.
 * When executing code in this state, you can access the args using this.args
 */

// Automatic states (RoundStart/ResolveRound/EndGame) have nothing for the client to do beyond
// showing a status message -- the server drives the transition on its own.
class RoundStart {
  constructor(game, bga) {
    this.game = game;
    this.bga = bga;
  }

  onEnteringState(_args, _isCurrentPlayerActive) {
    this.bga.statusBar.setTitle(_("A new round is starting..."));
  }

  onLeavingState(_args, _isCurrentPlayerActive) {}
}

class PlayCards {
  constructor(game, bga) {
    this.game = game;
    this.bga = bga;
  }

  onEnteringState(args, isCurrentPlayerActive) {
    this.bga.statusBar.setTitle(
      isCurrentPlayerActive
        ? _("${you} must commit a work card")
        : _("Players must commit a work card"),
    );

    if (isCurrentPlayerActive) {
      const handValues = args.handValues; // returned by PlayCards::getArgs

      handValues.forEach((value) =>
        this.bga.statusBar.addActionButton(
          _("Commit ${value}").replace("${value}", value),
          () => this.onCardClick(value),
        ),
      );
    }

    this.onPlayerActivationChange(args, isCurrentPlayerActive);
  }

  onLeavingState(_args, _isCurrentPlayerActive) {}

  /**
   * on MULTIPLE_ACTIVE_PLAYER states, this is called each time the current player becomes
   * active or inactive -- used here to show a "waiting on other players" message once this
   * player has committed.
   */
  onPlayerActivationChange(_args, isCurrentPlayerActive) {
    if (!isCurrentPlayerActive) {
      this.bga.statusBar.setTitle(
        _("Waiting for other players to commit a work card"),
      );
    }
  }

  onCardClick(value) {
    this.bga.actions.performAction("actCommitCard", {
      value,
    });
  }
}

class ResolveRound {
  constructor(game, bga) {
    this.game = game;
    this.bga = bga;
  }

  onEnteringState(_args, _isCurrentPlayerActive) {
    this.bga.statusBar.setTitle(_("Revealing work cards..."));
  }

  onLeavingState(_args, _isCurrentPlayerActive) {}
}

class EndGame {
  constructor(game, bga) {
    this.game = game;
    this.bga = bga;
  }

  onEnteringState(_args, _isCurrentPlayerActive) {
    this.bga.statusBar.setTitle(_("The game is over"));
  }

  onLeavingState(_args, _isCurrentPlayerActive) {}
}

export class Game {
  constructor(bga) {
    console.log("loaf constructor");
    this.bga = bga;

    // Declare the State classes
    this.roundStart = new RoundStart(this, bga);
    this.bga.states.register("RoundStart", this.roundStart);
    this.playCards = new PlayCards(this, bga);
    this.bga.states.register("PlayCards", this.playCards);
    this.resolveRound = new ResolveRound(this, bga);
    this.bga.states.register("ResolveRound", this.resolveRound);
    this.endGame = new EndGame(this, bga);
    this.bga.states.register("EndGame", this.endGame);

    // Uncomment the next line to show debug informations about state changes in the console. Remove before going to production!
    this.bga.states.logger = console.log;
  }

  /*
        setup:

        This method must set up the game user interface according to current game situation specified
        in parameters.

        The method is called each time the game interface is displayed to a player, ie:
        _ when the game starts
        _ when a player refreshes the game page (F5)

        "gamedatas" argument contains all datas retrieved by your "getAllDatas" PHP method.
    */

  setup(gamedatas) {
    console.log("Starting game setup");
    this.gamedatas = gamedatas;

    this.bga.gameArea.getElement().insertAdjacentHTML(
      "beforeend",
      `
            <div id="player-tables"></div>
        `,
    );

    // Setting up player boards: reputation + hand/played card counts. Own hand values are
    // shown as PlayCards action buttons rather than here; opponents only ever get counts
    // (docs/loaf-open-questions.md Q3 -- hands/discards are private to their owner).
    Object.values(gamedatas.players).forEach((player) => {
      const handCount = gamedatas.handCount[player.id] ?? 0;
      const playedCount = gamedatas.playedCount[player.id] ?? 0;

      document.getElementById("player-tables").insertAdjacentHTML(
        "beforeend",
        `
                <div id="player-table-${player.id}">
                    <strong>${player.name}</strong>
                    <div>Reputation: <span id="reputation-player-${player.id}">${player.reputation}</span></div>
                    <div>Hand: <span id="hand-count-player-${player.id}">${handCount}</span> card(s)</div>
                    <div>Committed: <span id="played-count-player-${player.id}">${playedCount}</span></div>
                </div>
            `,
      );
    });

    // Setup game notifications to handle (see "setupNotifications" method below)
    this.setupNotifications();

    console.log("Ending game setup");
  }

  ///////////////////////////////////////////////////
  //// Utility methods

  /*

        Here, you can defines some utility methods that you can use everywhere in your javascript
        script. Typically, functions that are used in multiple state classes or outside a state class.

    */

  ///////////////////////////////////////////////////
  //// Reaction to cometD notifications

  /*
        setupNotifications:

        In this method, you associate each of your game notifications with your local method to handle it.

        Note: game notification names correspond to "bga->notify->all" calls in your Game.php file.

    */
  setupNotifications() {
    console.log("notifications subscriptions setup");

    // automatically listen to the notifications, based on the `notif_xxx` function on this class.
    // Uncomment the logger param to see debug information in the console about notifications.
    this.bga.notifications.setupPromiseNotifications({
      logger: console.log,
    });
  }

  // Matches Game.php's `roundStart` notification (RoundStart state).
  async notif_roundStart(_args) {
    // TODO: animate the new order/review card reveal once real art is wired in (Phase 5).
  }

  // Matches Game.php's `playerCommitted` notification (PlayCards::actCommitCard). No card
  // value is included -- it stays hidden until roundResolved.
  async notif_playerCommitted(_args) {}

  // Matches Game.php's `reputationChanged` notification (ResolveRound).
  async notif_reputationChanged(args) {
    const element = document.getElementById(
      `reputation-player-${args.player_id}`,
    );
    if (element) {
      element.textContent = args.reputation;
    }
  }

  // Matches Game.php's `roundResolved` notification (ResolveRound).
  async notif_roundResolved(_args) {
    // TODO: reveal animation for all played cards once real art is wired in (Phase 5).
  }
}
