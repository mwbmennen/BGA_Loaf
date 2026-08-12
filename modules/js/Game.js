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

  /**
   * isCurrentPlayerActive here can be stale/wrong for a MULTIPLE_ACTIVE_PLAYER state reached
   * via a live push, not just during setup -- confirmed live 2026-08-08 (a real player with a
   * genuine 7-card hand still showed isCurrentPlayerActive: false right here), and matches a
   * documented BGA framework behavior: player activation for a MULTIPLE_ACTIVE_PLAYER state
   * is set server-side *during* the state's own onEnteringState
   * (setAllPlayersMultiactive()), so the client's activation status isn't guaranteed to have
   * settled by the time its own onEnteringState fires
   * (forum.boardgamearena.com/viewtopic.php?t=14059). A page refresh always "fixed" it
   * because a fresh load re-derives activation from scratch rather than trusting a live push.
   * Don't add action buttons here -- onPlayerActivationChange below is the framework's
   * separately-fired, reliably-timed signal for this (confirmed live: it fires as its own
   * distinct event on every single state entry, not just on later changes).
   */
  onEnteringState(_args, _isCurrentPlayerActive) {
    this.bga.statusBar.setTitle(_("Bakers are committing a work card"));
  }

  onLeavingState(_args, _isCurrentPlayerActive) {}

  /**
   * On MULTIPLE_ACTIVE_PLAYER states, this is called by the framework once this player's
   * activation status has actually settled -- both on first becoming active and on becoming
   * inactive again (e.g. after committing) -- unlike onEnteringState's isCurrentPlayerActive,
   * see the comment there. This is the only place action buttons get added.
   */
  onPlayerActivationChange(args, isCurrentPlayerActive) {
    if (isCurrentPlayerActive) {
      this.bga.statusBar.setTitle(_("${you} must commit a work card"));

      const handValues = args.handValues; // returned by PlayCards::getArgs
      handValues.forEach((value) =>
        this.bga.statusBar.addActionButton(
          _("Commit ${value}").replace("${value}", value),
          () => this.onCardClick(value),
        ),
      );
    } else {
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

// Handles discard_choice and the two swap effects (see PHP's ResolveAdvancedEffect). Same
// onPlayerActivationChange-not-onEnteringState discipline as PlayCards, for the same reason
// (docs/bga-studio-reference.md §5).
class ResolveAdvancedEffect {
  constructor(game, bga) {
    this.game = game;
    this.bga = bga;
  }

  onEnteringState(_args, _isCurrentPlayerActive) {
    this.bga.statusBar.setTitle(_("An advanced card effect is resolving..."));
  }

  onLeavingState(_args, _isCurrentPlayerActive) {}

  onPlayerActivationChange(args, isCurrentPlayerActive) {
    this.isSwap = args.effectType !== "discard_choice";

    if (isCurrentPlayerActive) {
      this.bga.statusBar.setTitle(
        this.isSwap
          ? _("${you} must take your played card back and discard another")
          : _("${you} must discard a card of your choice from hand"),
      );

      const eligibleValues = args.eligibleValues ?? [];
      eligibleValues.forEach((value) =>
        this.bga.statusBar.addActionButton(
          _("Discard ${value}").replace("${value}", value),
          () => this.onCardClick(value),
        ),
      );
    } else {
      this.bga.statusBar.setTitle(
        _("Waiting for other players to resolve the advanced effect"),
      );
    }
  }

  onCardClick(value) {
    this.bga.actions.performAction(
      this.isSwap ? "actSwapDiscard" : "actDiscardChoice",
      { value },
    );
  }
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
    this.resolveAdvancedEffect = new ResolveAdvancedEffect(this, bga);
    this.bga.states.register("ResolveAdvancedEffect", this.resolveAdvancedEffect);
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

    // Minimal, functional-not-pretty per docs/loaf-phase1-plan.md's Client section -- just
    // enough to see the 5-card end trigger approaching while playtesting. Real boss-pile
    // visuals (filed cards, fraction-to-5 indicator) are Phase 5 polish
    // (docs/loaf-implementation-plan.md §4), not this.
    // getCardsInLocation() returns a PHP associative array keyed by card id, which serializes
    // to a JS object rather than an array -- Object.keys(...).length works for both shapes,
    // plain .length only works on arrays (confirmed live: .length came back undefined here).
    const bossHappyCount = Object.keys(gamedatas.bossHappy).length;
    const bossAngryCount = Object.keys(gamedatas.bossAngry).length;

    this.bga.gameArea.getElement().insertAdjacentHTML(
      "beforeend",
      `
            <div id="boss-piles">
                <div>Happy boss: <span id="boss-happy-count">${bossHappyCount}</span> / 5</div>
                <div>Angry boss: <span id="boss-angry-count">${bossAngryCount}</span> / 5</div>
            </div>
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
  async notif_roundResolved(args) {
    // TODO: reveal animation for all played cards once real art is wired in (Phase 5).
    const element = document.getElementById(
      args.bossPile === "happy" ? "boss-happy-count" : "boss-angry-count",
    );
    if (element) {
      element.textContent = Number(element.textContent) + 1;
    }
  }

  // Matches Game.php's `cardRecycled` notification (ResolveRound, discard_recycle_lowest). No
  // card value is included -- same hand/discard privacy discipline as notif_playerCommitted.
  async notif_cardRecycled(_args) {}

  // Matches Game.php's `advancedEffectPending` notification (ResolveAdvancedEffect).
  async notif_advancedEffectPending(_args) {}

  // Matches Game.php's `playerDiscarded` notification (ResolveAdvancedEffect::actDiscardChoice).
  async notif_playerDiscarded(_args) {}

  // Matches Game.php's `cardSwapped` notification (ResolveAdvancedEffect::actSwapDiscard).
  async notif_cardSwapped(_args) {}

  // Matches Game.php's `playerFired` notification (EndGame). Plain-text marker only --
  // functional, not pretty, same scope as the rest of Phase 1-3's client (real polish is
  // Phase 5). The BGA ranking screen itself already shows fired players tied at the bottom
  // via player_score/player_score_aux; this just makes "why" legible on the board too.
  async notif_playerFired(args) {
    const element = document.getElementById(`player-table-${args.player_id}`);
    if (element) {
      element.insertAdjacentHTML("beforeend", `<div>${_("FIRED")}</div>`);
    }
  }
}
