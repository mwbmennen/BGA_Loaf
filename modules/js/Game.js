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

// Sprite-index lookups for the sheets built by tools/build-sprite.sh (docs/loaf-phase5-plan.md
// §4) -- these arrays/orderings must stay in exact sync with that script's own explicit,
// zero-padded file-list order, never derived from the card_type/color strings themselves.
const ROUND_CARD_SPRITE_INDEX = Object.fromEntries(
  [
    ...Array.from({ length: 12 }, (_, i) => `basic_${String(i + 1).padStart(2, "0")}`),
    ...Array.from({ length: 12 }, (_, i) => `advanced_${String(i + 1).padStart(2, "0")}`),
  ].map((type, index) => [type, index]),
);
const ROUND_CARD_SHEET_COLS = 6;
const ROUND_CARD_SHEET_ROWS = 4;

const HAND_CARD_COLORS = ["green", "orange", "purple", "red", "white", "yellow"];
const HAND_CARD_SHEET_COLS = 13; // 12 values + 1 back tile per color
const HAND_CARD_SHEET_ROWS = 6; // one row per color

// value === null means the color's back tile (the 13th column, after that color's 12 fronts --
// see tools/build-sprite.sh's own HAND_FILES loop).
function handCardSpriteIndex(color, value) {
  const colorIndex = HAND_CARD_COLORS.indexOf(color);
  const columnInRow = value === null ? 12 : value;
  return colorIndex * HAND_CARD_SHEET_COLS + columnInRow;
}

// CSS background-position percentage math for an N-column/M-row sprite sheet displayed at
// `background-size: {N*100}% {M*100}%`: the correct divisor is (N-1)/(M-1), not N/M --
// verified from the CSS spec's own background-position percentage formula
// (offset = (box - image) * pct/100, image = N * box), not copied blindly from BGA's own
// bga-cards usage-example snippet, whose "divide by 14"/"divide by 3" only happens to be
// correct for however many columns/rows *that* specific example's sheet actually had (cols-1
// there, not cols) -- confirmed by re-deriving the formula rather than assuming the example's
// literal numbers generalize to a 6-column/13-column sheet, where the off-by-one would be a
// visibly wrong crop, not a rounding error.
function spritePositionPercent(index, count) {
  return count <= 1 ? "0%" : `${(100 * index) / (count - 1)}%`;
}

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

  async setup(gamedatas) {
    console.log("Starting game setup");
    this.gamedatas = gamedatas;

    await this.setupCardsAndZoom();

    // The *weighted* count (Game.php's bossHappyWeight/bossAngryWeight), not the physical card
    // count -- a counts_as_two card is worth 2 toward the actual 5-card end trigger, so the
    // physical count under-reports how close the game is to ending (confirmed live).
    const bossHappyCount = gamedatas.bossHappyWeight;
    const bossAngryCount = gamedatas.bossAngryWeight;

    // Real boss-pile/pending-card visuals (docs/loaf-phase5-plan.md §7) -- the fraction-to-5
    // counter text stays (still the clearest way to show "how close to ending"), real card art
    // now renders alongside it via the Stocks built in setupRoundCardStocks() below.
    this.bga.gameArea.getElement().insertAdjacentHTML(
      "beforeend",
      `
            <div id="pending-cards">
                <div>
                    <div>Next order card</div>
                    <div id="pending-order-card"></div>
                </div>
                <div>
                    <div>Current review card</div>
                    <div id="pending-review-card"></div>
                </div>
            </div>
            <div id="boss-piles">
                <div>
                    <div>Happy boss: <span id="boss-happy-count">${bossHappyCount}</span> / 5</div>
                    <div id="boss-happy-pile"></div>
                </div>
                <div>
                    <div>Angry boss: <span id="boss-angry-count">${bossAngryCount}</span> / 5</div>
                    <div id="boss-angry-pile"></div>
                </div>
            </div>
            <div id="player-tables"></div>
        `,
    );

    this.setupRoundCardStocks(gamedatas);

    // Setting up player boards: reputation + hand card count. Own hand values are shown as
    // PlayCards action buttons rather than here; opponents only ever get a count
    // (docs/loaf-open-questions.md Q3 -- hands/discards are private to their owner). No
    // "Committed" count -- not useful information (docs/loaf-remarks.md's Phase 4 entry).
    Object.values(gamedatas.players).forEach((player) => {
      const handCount = gamedatas.handCount[player.id] ?? 0;

      document.getElementById("player-tables").insertAdjacentHTML(
        "beforeend",
        `
                <div id="player-table-${player.id}">
                    <strong>${player.name}</strong>
                    <div>Reputation: <span id="reputation-player-${player.id}">${player.reputation}</span></div>
                    <div>Hand: <span id="hand-count-player-${player.id}">${handCount}</span> card(s)</div>
                </div>
            `,
      );
    });

    // Setup game notifications to handle (see "setupNotifications" method below)
    this.setupNotifications();

    console.log("Ending game setup");
  }

  // Imports and configures the three BGA card/animation/zoom libraries
  // (docs/loaf-phase5-plan.md §4 step 1) -- called once, at the very start of setup(), before
  // any card-rendering code runs. Managers are created here; the actual Stocks that consume
  // real game data (boss piles, hand, etc.) are built in later Phase 5 steps on top of these.
  async setupCardsAndZoom() {
    const BgaAnimations = await importEsmLib("bga-animations", "1.x");
    const BgaCards = await importEsmLib("bga-cards", "1.x");
    const BgaZoom = await importEsmLib("bga-zoom", "1.x");
    this.BgaCards = BgaCards; // kept for later Stock construction (setupRoundCardStocks, §8)

    this.animationManager = new BgaAnimations.Manager({
      animationsActive: () => this.bga.gameui.bgaAnimationsActive(),
    });

    // Round cards (order/review): always public information once revealed (isCardVisible is
    // always true, unlike hand cards below) -- docs/loaf-phase5-plan.md §7.
    this.roundCardsManager = new BgaCards.Manager({
      animationManager: this.animationManager,
      type: "loaf-round-card",
      cardWidth: 180,
      cardHeight: 251,
      cardBorderRadius: "8px",
      getId: (card) => card.id,
      isCardVisible: () => true,
      setupFrontDiv: (card, div) => this.setupRoundCardFrontDiv(card, div),
      // Presentation-only, no game-state meaning: the pending review card is displayed rotated
      // next to the pending order card (docs/loaf-phase5-plan.md §7) -- set per-card via
      // `card.rotation` (only the pending-review card object carries it) rather than keying off
      // `card.side`, since boss-pile cards are also side: "review" but must stay unrotated.
      // Value is quarter-turns (0/1/2/3), not degrees -- bga-cards' own createCardElement does
      // `rotation * 90deg` and swaps the card's effective width/height whenever the value is
      // odd ("lying") -- confirmed by reading the actual library source (bga-cards.esm.js),
      // not just the .d.ts, after passing 90 directly here produced no visible rotation at all.
      getCardRotation: (card) => card.rotation ?? 0,
    });

    // Hand cards (a player's own numbered work cards): visibility is genuinely conditional --
    // own hand visible, opponents' hidden, committed-but-unrevealed face-down for everyone --
    // so isCardVisible reads real per-card state instead of always returning true.
    // docs/loaf-phase5-plan.md §8.
    this.handCardsManager = new BgaCards.Manager({
      animationManager: this.animationManager,
      type: "loaf-hand-card",
      cardWidth: 180,
      cardHeight: 251,
      cardBorderRadius: "8px",
      getId: (card) => `${card.color}_${card.value}`,
      isCardVisible: (card) => card.visible === true,
      setupFrontDiv: (card, div) => this.setupHandCardFrontDiv(card, div),
      setupBackDiv: (card, div) => this.setupHandCardBackDiv(card, div),
    });

    // Whole-board zoom control (docs/loaf-phase5-plan.md §6) -- wraps everything setup() renders
    // into gameArea, not just one section of it; distinct from and complementary to the
    // per-card hover tooltip §7 adds later (that shows one card bigger, this scales the board).
    this.boardZoom = new BgaZoom.Manager({
      element: this.bga.gameArea.getElement(),
      zoomControls: { color: ["black", "rgb(229, 231, 235)"] },
      localStorageZoomKey: "loaf-zoom",
      autoZoom: {
        expectedWidth: 740, // matches gameinfos.jsonc's game_interface_width.min
        minZoomLevel: 0.5,
      },
    });
  }

  // Builds the two boss-pile Stocks and the two pending-order/pending-review single-card slots
  // (docs/loaf-phase5-plan.md §7), then seeds all four from the initial gamedatas. Must run
  // after the containing HTML (setup(), above) exists -- Stock constructors need a real element.
  setupRoundCardStocks(gamedatas) {
    this.pendingOrderStock = new this.BgaCards.SlotStock(this.roundCardsManager, document.getElementById("pending-order-card"), {
      slotsIds: [0],
      mapCardToSlot: () => 0,
    });
    this.pendingReviewStock = new this.BgaCards.SlotStock(this.roundCardsManager, document.getElementById("pending-review-card"), {
      slotsIds: [0],
      mapCardToSlot: () => 0,
    });
    this.bossHappyStock = new this.BgaCards.LineStock(this.roundCardsManager, document.getElementById("boss-happy-pile"));
    this.bossAngryStock = new this.BgaCards.LineStock(this.roundCardsManager, document.getElementById("boss-angry-pile"));

    this.pendingOrderStock.addCard({ id: gamedatas.currentOrderCardId, type: gamedatas.currentOrderCardType, side: "order" });
    this.pendingReviewStock.addCard({
      id: gamedatas.currentReviewCardId,
      type: gamedatas.currentReviewCardType,
      side: "review",
      rotation: 1, // quarter-turns, not degrees -- bga-cards does rotation * 90deg internally
    });
    // bossHappy/bossAngry are PHP-Deck-shaped keyed-by-id objects, not arrays -- Object.values()
    // per the documented gotcha (docs/loaf-phase5-plan.md §4 step 12). Every filed card resolved
    // via its review side regardless of which pile it's in (that's how it got filed at all), so
    // `side: "review"` is fixed here, not per-card.
    this.bossHappyStock.addCards(Object.values(gamedatas.bossHappy).map((card) => ({ ...card, side: "review" })));
    this.bossAngryStock.addCards(Object.values(gamedatas.bossAngry).map((card) => ({ ...card, side: "review" })));
  }

  // Positions a round-card element's background on the correct sprite sheet/tile for its
  // type + side, and attaches a hover tooltip showing the same card larger (the zoom-quality
  // sheet, docs/loaf-phase5-plan.md §4 step 5) -- the "generic hover tooltip instead of a
  // custom zoom overlay" decision, §7. `card` must carry `type` (e.g. "basic_01", matching the
  // Deck component's own field name -- not `card_type`) and `side` ("order"|"review").
  setupRoundCardFrontDiv(card, div) {
    const { sheet, zoomSheet, x, y } = this.roundCardSpritePosition(card);
    div.style.backgroundImage = `url(${this.bga.images.getImgUrl(sheet)})`;
    div.style.backgroundSize = `${ROUND_CARD_SHEET_COLS * 100}% ${ROUND_CARD_SHEET_ROWS * 100}%`;
    div.style.backgroundPositionX = x;
    div.style.backgroundPositionY = y;

    // Zoom tooltip: same sprite-position math, pointing at the higher-resolution sheet, shown
    // at 250x348 (half the source's 500x696 -- downscaled, not upscaled, stays crisp) rather
    // than full size, so it reads as a "closer look" popup, not a full-screen takeover. Next to
    // it, a text panel built from Game.php's `roundCardDescriptions` (already translated
    // server-side via self::_() -- plain text here, no client-side translation needed). Only
    // the text matching the face actually shown (`card.side`) is included -- an order-side
    // card physically shows only its order face, so its tooltip shouldn't spoil/describe the
    // review face on the other side, and vice versa.
    const description = this.gamedatas.roundCardDescriptions[card.type];
    const descriptionLines = card.side === "order" ? [description.order] : [description.fail, description.success];

    // Match the card's on-board rotation (same `card.rotation` quarter-turns field
    // getCardRotation reads, above) in the zoom image too -- the pending review card displays
    // rotated 90deg next to the order card (docs/loaf-phase5-plan.md §7), so a portrait zoom
    // image would no longer match what's actually on screen. The inner image div keeps its
    // native 250x348 size and is rotated+centered via transform; the outer container swaps to
    // the rotated bounding box (348x250) so the layout doesn't clip or misalign, the same
    // "swap effective width/height for a rotated card" approach bga-cards itself uses.
    const imageWidth = 250;
    const imageHeight = 348;
    const rotationDeg = (card.rotation ?? 0) * 90;
    const rotated = rotationDeg % 180 !== 0;
    const containerWidth = rotated ? imageHeight : imageWidth;
    const containerHeight = rotated ? imageWidth : imageHeight;

    // Review card: text goes below the image (a landscape-rotated image reads better with a
    // full-width caption underneath than a narrow side column). Order card: text stays beside
    // the (portrait, unrotated) image, the original layout.
    const tooltipLayoutClass = card.side === "review" ? "loaf_card-tooltip--stacked" : "loaf_card-tooltip--side-by-side";

    this.bga.gameui.addTooltipHtml(
      div.id,
      `<div class="loaf_card-tooltip ${tooltipLayoutClass}">` +
        `<div class="loaf_card-tooltip-image" style="width:${containerWidth}px;height:${containerHeight}px;">` +
        `<div style="width:${imageWidth}px;height:${imageHeight}px;background-image:url(${this.bga.images.getImgUrl(zoomSheet)});` +
        `background-size:${ROUND_CARD_SHEET_COLS * 100}% ${ROUND_CARD_SHEET_ROWS * 100}%;` +
        `background-position:${x} ${y};border-radius:8px;` +
        `transform:translate(-50%,-50%) rotate(${rotationDeg}deg);"></div>` +
        `</div>` +
        `<div class="loaf_card-tooltip-text">` +
        descriptionLines.map((line) => `<div>${line}</div>`).join("") +
        `</div>` +
        `</div>`,
    );
  }

  roundCardSpritePosition(card) {
    const index = ROUND_CARD_SPRITE_INDEX[card.type];
    return {
      sheet: card.side === "order" ? "order-sheet.jpg" : "review-sheet.jpg",
      zoomSheet: card.side === "order" ? "zoom-order.jpg" : "zoom-review.jpg",
      x: spritePositionPercent(index % ROUND_CARD_SHEET_COLS, ROUND_CARD_SHEET_COLS),
      y: spritePositionPercent(Math.floor(index / ROUND_CARD_SHEET_COLS), ROUND_CARD_SHEET_ROWS),
    };
  }

  // `card` must carry `color` + `value` (0-11) -- the front face for that player-colored work
  // card, from the combined fronts+backs hand-sheet.jpg (docs/loaf-phase5-plan.md §4 step 10).
  setupHandCardFrontDiv(card, div) {
    this.positionHandCardSprite(card.color, card.value, div);
  }

  // Same sheet, but the color's back tile (13th column, after that color's 12 fronts) --
  // rendered whenever isCardVisible returns false for this card (opponent hands, a
  // committed-but-unrevealed card).
  setupHandCardBackDiv(card, div) {
    this.positionHandCardSprite(card.color, null, div);
  }

  positionHandCardSprite(color, value, div) {
    const index = handCardSpriteIndex(color, value);
    div.style.backgroundImage = `url(${this.bga.images.getImgUrl("hand-sheet.jpg")})`;
    div.style.backgroundSize = `${HAND_CARD_SHEET_COLS * 100}% ${HAND_CARD_SHEET_ROWS * 100}%`;
    div.style.backgroundPositionX = spritePositionPercent(index % HAND_CARD_SHEET_COLS, HAND_CARD_SHEET_COLS);
    div.style.backgroundPositionY = spritePositionPercent(
      Math.floor(index / HAND_CARD_SHEET_COLS),
      HAND_CARD_SHEET_ROWS,
    );
  }

  ///////////////////////////////////////////////////
  //// Utility methods

  /*

        Here, you can defines some utility methods that you can use everywhere in your javascript
        script. Typically, functions that are used in multiple state classes or outside a state class.

    */

  // Shared by every notification that changes how many cards a player has in hand (committing,
  // recycling, discarding) -- keeps "Hand: N card(s)" live instead of only refreshing on the
  // next page load.
  adjustHandCount(playerId, delta) {
    const element = document.getElementById(`hand-count-player-${playerId}`);
    if (element) {
      element.textContent = Number(element.textContent) + delta;
    }
  }

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

  // Matches Game.php's `roundStart` notification (RoundStart state). Refreshes both pending
  // slots from scratch rather than trying to animate the order-card sliding into the review
  // slot (the flip-mechanic card is the same physical card, but showing a different face
  // entirely, not a simple front/back flip) -- correctness over animation finesse for this
  // pass, docs/loaf-phase5-plan.md §7. `pendingReviewStock` is already empty by the time this
  // fires (moved out in notif_roundResolved below), so removeAll() there is a no-op safety net.
  async notif_roundStart(args) {
    await this.pendingOrderStock.removeAll();
    await this.pendingReviewStock.removeAll();
    await this.pendingOrderStock.addCard({ id: args.orderCardId, type: args.orderCardType, side: "order" });
    await this.pendingReviewStock.addCard({
      id: args.reviewCardId,
      type: args.reviewCardType,
      side: "review",
      rotation: 1, // quarter-turns, not degrees -- bga-cards does rotation * 90deg internally
    });
  }

  // Matches Game.php's `playerCommitted` notification (PlayCards::actCommitCard). No card
  // value is included -- it stays hidden until roundResolved. Committing removes one card
  // from hand -- update the live count here rather than only on the next page load
  // (previously this handler did nothing at all, so "Hand: N card(s)" silently went stale
  // the moment the game started -- confirmed live).
  async notif_playerCommitted(args) {
    this.adjustHandCount(args.player_id, -1);
  }

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
    // TODO: reveal animation for all played hand cards once §8 is built (Phase 5).
    const element = document.getElementById(
      args.bossPile === "happy" ? "boss-happy-count" : "boss-angry-count",
    );
    // Increment by args.weight (1, or 2 for a counts_as_two card), not always 1 -- a flat +1
    // under-reported the pile by 1 whenever a counts_as_two card resolved into it (confirmed
    // live: game ended with the counter still showing 4/5 instead of the real weighted 5/5).
    if (element) {
      element.textContent = Number(element.textContent) + args.weight;
    }

    // Moves the actual card (with animation) from the pending-review slot into the pile it
    // just resolved into -- `fromStock` automatically removes it from pendingReviewStock, so
    // that slot is already empty by the time the next notif_roundStart fires.
    const targetStock = args.bossPile === "happy" ? this.bossHappyStock : this.bossAngryStock;
    await targetStock.addCard(
      { id: args.reviewCardId, type: args.reviewCardType, side: "review" },
      { fromStock: this.pendingReviewStock },
    );
  }

  // Matches Game.php's `cardPlayedRevealed` notification (ResolveRound) -- what each player
  // played this round, now open information once the round has resolved. Log-text only.
  async notif_cardPlayedRevealed(_args) {}

  // Matches Game.php's `reviewEffectApplied` notification (ResolveRound) -- describes the
  // review effect that just actually resolved (as opposed to `reviewCardRevealed`, which
  // describes both possible sides speculatively before either has happened). Log-text only,
  // same "surface hidden state via the log" pattern as everything else pre-Phase-5.
  async notif_reviewEffectApplied(_args) {}

  // Matches Game.php's `cardRecycled` notification (ResolveRound, discard_recycle_lowest). No
  // card value is included -- same hand/discard privacy discipline as notif_playerCommitted.
  // Recycling moves one card from discard back into hand -- hand count goes up by 1.
  async notif_cardRecycled(args) {
    this.adjustHandCount(args.player_id, 1);
  }

  // Matches Game.php's `advancedEffectPending` notification (ResolveAdvancedEffect).
  async notif_advancedEffectPending(_args) {}

  // Matches Game.php's `playerDiscarded` notification (ResolveAdvancedEffect::actDiscardChoice).
  // Discarding a card of their choice removes one from hand -- hand count goes down by 1.
  async notif_playerDiscarded(args) {
    this.adjustHandCount(args.player_id, -1);
  }

  // Matches Game.php's `cardSwapped` notification (ResolveAdvancedEffect::actSwapDiscard). No
  // hand-count update needed -- a swap either returns the played card to hand and discards a
  // different one (net 0 change in hand *size*, only in which specific card is held) or, in
  // the deterministic-fallback case, never touches hand at all.
  async notif_cardSwapped(_args) {}

  // Matches Game.php's `endGameBonusApplied` notification (EndGame) -- one per
  // (contributing effect, affected player), explaining exactly what end-game bonus/malus
  // they received and why. Log-text only, same "surface hidden state via the log" pattern.
  async notif_endGameBonusApplied(_args) {}

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
