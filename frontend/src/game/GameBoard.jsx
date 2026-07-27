import { useState, useEffect, useRef } from "react";
import { AnimatePresence } from "framer-motion";
import { api, subscribe } from "../api";
import GameCard from "./GameCard";
import Tutorial from "./Tutorial";
import GuidedTour from "./GuidedTour";
import GroupAmbushAlert from "./GroupAmbushAlert";
import { BOT_LEVELS } from "./WaitingRoom";

// Étapes de la visite guidée : chaque cible pointe vers un attribut data-tour
// posé sur une zone du plateau. Les zones absentes sont ignorées à l'exécution.
const TOUR_STEPS = [
  { target: "header", emoji: "🧭", title: "Le fil du tour",
    text: "Ici s'affiche le tour en cours et à qui de jouer. Le bouton ❓ rouvre les règles quand tu veux." },
  { target: "opponents", emoji: "🧑‍🤝‍🧑", title: "Tes adversaires",
    text: "Leurs mains sont cachées : tu ne vois que leurs compteurs (main ✋, pioche 🂠, défausse 🗑️). Celui qui joue est mis en avant." },
  { target: "piles", emoji: "🐉", title: "L'Ennemi Principal & les piles",
    text: "L'Archennemi à vaincre (gros bonus !), la pile Valeur 🎖️ toujours achetable, et la Corruption 🕷️ qui te retire des PV." },
  { target: "path", emoji: "🗺️", title: "Le Chemin",
    text: "5 cartes à acheter, regarnies chaque tour depuis le Deck principal. Clique une carte abordable pour la prendre." },
  { target: "hud", emoji: "⚡", title: "Ton Pouvoir",
    text: "Le Pouvoir ⚡ que tes cartes génèrent ce tour — c'est ta monnaie pour acheter et vaincre. Avec tes compteurs perso." },
  { target: "controls", emoji: "🎮", title: "Jouer & finir",
    text: "« Tout jouer » déclenche tes cartes, puis « Fin du tour » défausse tout et te repioche une main de 5." },
  { target: "hand", emoji: "✋", title: "Ta main",
    text: "Survole une carte pour la Jouer ou l'Agrandir. C'est d'ici que part chaque tour. Voyons les règles complètes !" },
];

export default function GameBoard({ session, onQuit }) {
  const [id] = useState(session.id);
  const [state, setState] = useState(session.state);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [showDiscard, setShowDiscard] = useState(false);
  const [preview, setPreview] = useState(null);
  const [cardView, setCardView] = useState(null); // carte affichée en lecture seule (récap)
  const [deflect, setDeflect] = useState(null); // { eid, iid } en cours de choix de cible
  const [gaDismissed, setGaDismissed] = useState(false);
  const [showEffects, setShowEffects] = useState(true);
  // Actions optionnelles mises en sourdine par le joueur (par `op`, ex. "destroy") :
  // elles ne rouvrent plus la modale automatiquement. Persisté dans le navigateur.
  const [mutedOps, setMutedOps] = useState(() => {
    try { return new Set(JSON.parse(localStorage.getItem("lotr_muted_ops") || "[]")); }
    catch { return new Set(); }
  });
  const [recapDismissed, setRecapDismissed] = useState(null); // clé du récap masqué
  const [revealDismissed, setRevealDismissed] = useState(null);
  const [secondsLeft, setSecondsLeft] = useState(null);
  const [showTuto, setShowTuto] = useState(false);
  const [showTour, setShowTour] = useState(false);
  const [isHost] = useState(session.isHost);
  const [botDelayMs, setBotDelayMs] = useState(session.botDelayMs ?? 10000);

  // À la 1re visite : on lance la visite guidée (qui se termine sur la modale
  // des règles). Ensuite, les deux restent accessibles via les boutons du haut.
  useEffect(() => {
    if (!localStorage.getItem("lotr_tuto_seen")) {
      setShowTour(true);
      localStorage.setItem("lotr_tuto_seen", "1");
    }
  }, []);

  const effects = state.effects || [];
  const mandatoryLeft = effects.some((e) => e.kind === "neg" && e.applicable);
  // Ouvre automatiquement la liste pour les effets PONCTUELS (pas les permanents
  // activables comme Isengard), SAUF les actions optionnelles mises en sourdine
  // par le joueur (« ne plus me demander », par type d'action) : elles restent
  // accessibles à la demande via le bouton « ⚡ Effets à appliquer ».
  const autoOpenCount = effects.filter(
    (e) => !e.permanent && (e.kind === "neg" || !mutedOps.has(e.op)),
  ).length;
  // S'ouvre quand une action mérite l'attention ; se REFERME quand il ne reste
  // que des actions en sourdine (accessibles à la demande via le bouton ⚡).
  // Ne se déclenche qu'au CHANGEMENT du compteur → un « Plus tard » manuel tient,
  // et une ouverture manuelle (compteur inchangé) n'est pas refermée.
  useEffect(() => {
    setShowEffects(autoOpenCount > 0);
  }, [autoOpenCount]);

  // Temps réel : re-fetch l'état masqué à chaque ping Mercure (+ sondage). Quand
  // c'est au tour d'un bot, on appelle `tick` (qui fait avancer le bot une fois
  // le délai écoulé, côté serveur) au lieu d'un simple GET.
  const stateRef = useRef(state);
  useEffect(() => { stateRef.current = state; }, [state]);
  useEffect(() => {
    async function poll() {
      const s = stateRef.current;
      const botTurn = s.activeIsBot && s.status !== "finished";
      try {
        const res = botTurn ? await api.tick(id) : await api.getGame(id);
        setState(res.state);
        if (res.botDelayMs != null) setBotDelayMs(res.botDelayMs);
      } catch { /* ignore */ }
    }
    const unsub = subscribe(`game/${id}`, poll);
    const iv = setInterval(poll, 2000);
    return () => { unsub(); clearInterval(iv); };
  }, [id]);

  // Ré-affiche l'alerte d'Embuscade de Groupe tant qu'elle n'est pas résolue.
  useEffect(() => {
    if (state.groupAmbush && !state.groupAmbush.done) setGaDismissed(false);
  }, [state.groupAmbush?.done, state.groupAmbush?.name]);

  // Décompte local « joue dans Xs » pendant la pause d'un bot.
  useEffect(() => {
    if (!state.activeIsBot || state.status === "finished") { setSecondsLeft(null); return; }
    setSecondsLeft(Math.ceil((state.botMsRemaining ?? 0) / 1000));
    const t = setInterval(() => setSecondsLeft((s) => (s == null ? null : Math.max(0, s - 1))), 1000);
    return () => clearInterval(t);
  }, [state.activeIsBot, state.botMsRemaining, state.activeSeat, state.turn, state.status]);

  // « moi » = le spectateur (mon siège) ; « active » = le joueur dont c'est le tour.
  const me = state.players.find((p) => p.isMe) ?? state.players[0];
  const active = state.players.find((p) => p.seat === state.activeSeat) ?? me;
  const isMyTurn = me.seat === state.activeSeat;
  const finished = state.status === "finished";
  const power = me.power;
  const opponents = state.players.filter((p) => !p.isMe);

  async function act(type, iid) {
    if (!isMyTurn) return;
    setBusy(true);
    setError("");
    try {
      const res = await api.action(id, type, iid);
      setState(res.state);
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function applyEff(eid, payload = {}) {
    setBusy(true);
    setError("");
    try {
      const res = await api.applyEffect(id, eid, payload);
      setState(res.state);
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function skipEff(eid) {
    setBusy(true);
    setError("");
    try {
      const res = await api.skipEffect(id, eid);
      setState(res.state);
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  // Active/désactive la sourdine d'un type d'action (persisté dans le navigateur).
  function toggleMuteOp(op) {
    setMutedOps((prev) => {
      const next = new Set(prev);
      if (next.has(op)) next.delete(op); else next.add(op);
      try { localStorage.setItem("lotr_muted_ops", JSON.stringify([...next])); } catch { /* quota */ }
      return next;
    });
  }

  const arch = state.stacks.archenemy;
  const archDiscount = me.archenemyDiscount ?? 0;
  const archAffordable = arch?.faceUp && arch.card && power >= Math.max(0, arch.card.cost - archDiscount);

  if (finished) {
    const scores = [...(state.scores || [])].sort((a, b) => b.vp - a.vp || b.archenemies - a.archenemies);
    return (
      <div className="board">
        <div className="banner">
          <h2>🏁 Partie terminée</h2>
          <p style={{ opacity: 0.85 }}>{state.endReason}</p>
        </div>
        <div className="zone__label">Classement final</div>
        <div className="row" style={{ flexDirection: "column", gap: "0.4rem" }}>
          {scores.map((s, i) => (
            <div key={s.seat} className="hud" style={{ margin: 0 }}>
              <span style={{ fontSize: "1.3rem" }}>{i === 0 ? "🥇" : `#${i + 1}`}</span>
              <strong>{s.pseudo}</strong>
              <span className="hud__stat">{s.vp} PV</span>
              <span className="hud__stat">{s.archenemies} Archennemi(s)</span>
              <span className="hud__stat">{s.cards} cartes</span>
            </div>
          ))}
        </div>
        <div className="controls" style={{ marginTop: "1rem" }}>
          <button className="gbtn" onClick={onQuit}>↩︎ Nouvelle partie</button>
        </div>
        <Log log={state.log} />
      </div>
    );
  }

  return (
    <div className="board">
      <div className="board__top" data-tour="header">
        <h1 className="board__title">⚔️ La Communauté de l'Anneau</h1>
        <span className="board__turn">
          Tour {state.turn} ·{" "}
          {isMyTurn
            ? <strong style={{ color: "#7fd18b" }}>À toi de jouer ({me.hero})</strong>
            : <>tour de <strong>{active.pseudo}</strong>…</>}
        </span>
        <span style={{ display: "flex", gap: "0.5rem", alignItems: "center", flexWrap: "wrap" }}>
          {isHost && (
            <label className="bot-delay" title="Pause entre les tours des bots (réservé à l'hôte)">
              🤖⏱️
              <select value={botDelayMs} disabled={busy}
                onChange={async (e) => {
                  const ms = Number(e.target.value);
                  setBotDelayMs(ms);
                  try { const res = await api.setBotDelay(id, ms); setState(res.state); } catch (err) { setError(err.message); }
                }}>
                <option value={0}>instantané</option>
                <option value={3000}>3 s</option>
                <option value={5000}>5 s</option>
                <option value={10000}>10 s</option>
                <option value={15000}>15 s</option>
                <option value={20000}>20 s</option>
              </select>
            </label>
          )}
          <button className="gbtn gbtn--ghost" onClick={() => setShowTour(true)} title="Faire le tour du plateau">
            🧭 Visite guidée
          </button>
          <button className={`gbtn gbtn--ghost help-btn ${state.turn === 1 ? "help-btn--pulse" : ""}`} onClick={() => setShowTuto(true)}>
            ❓ Comment jouer
          </button>
          <button className="gbtn gbtn--ghost" onClick={onQuit}>Quitter</button>
        </span>
      </div>

      {showTuto && <Tutorial onClose={() => setShowTuto(false)} />}
      {showTour && (
        <GuidedTour
          steps={TOUR_STEPS}
          onFinish={(openTutorial) => {
            setShowTour(false);
            if (openTutorial) setShowTuto(true);
          }}
        />
      )}

      {state.groupAmbush && !(state.groupAmbush.done && gaDismissed) && (
        <GroupAmbushAlert
          data={state.groupAmbush}
          busy={busy}
          onReveal={(eid, iid) => applyEff(eid, { iid })}
          onGuess={(eid, guess) => applyEff(eid, { guess })}
          onClose={() => setGaDismissed(true)}
        />
      )}

      {/* Haut du plateau : infos/actions des autres joueurs (gauche) +
          Ennemi Principal & piles compactes (droite) */}
      <div className="board__head">
        <div className="board__head-info">
      {/* Adversaires (mains cachées : compteurs seulement) */}
      {opponents.length > 0 && (
        <div className="opponents" data-tour="opponents">
          {opponents.map((p) => (
            <div key={p.seat} className={`opp ${p.seat === state.activeSeat ? "opp--active" : ""}`}>
              <div className="opp__n">{p.kind === "bot" ? "" : "🧑 "}{p.pseudo}</div>
              <div className="opp__h">{p.hero}{p.level ? ` · ${BOT_LEVELS[p.level]?.label ?? p.level}` : ""}</div>
              <div className="opp__stats">
                <span>✋ {p.handCount}</span>
                <span>🂠 {p.deckCount}</span>
                <span>🗑️ {p.discardCount}</span>
                {p.seat === state.activeSeat && <span>⚡ {p.power}</span>}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Qui joue actuellement (pendant qu'un autre joueur / un bot joue) */}
      {!finished && !isMyTurn && (
        <div className="turn-note">
          {active.kind === "bot" ? "" : "🧑 "}
          <strong>{active.pseudo}</strong> est en train de jouer
          {active.kind === "bot" && secondsLeft > 0 ? ` — au tour dans ${secondsLeft}s…` : "…"}
        </div>
      )}

      {/* Récap du dernier tour terminé — montré aux AUTRES joueurs */}
      {state.recap && state.recap.seat !== me.seat &&
        recapDismissed !== `${state.recap.seat}-${state.recap.turn}` && (
        <div className="recap">
          <button className="recap__x" onClick={() => setRecapDismissed(`${state.recap.seat}-${state.recap.turn}`)}>✕</button>
          <div className="recap__head">
            {state.recap.kind === "bot" ? "" : "🧑 "}<strong>{state.recap.pseudo}</strong>
            <span style={{ opacity: 0.7 }}> — récap du tour {state.recap.turn}</span>
          </div>
          <div className="recap__stats">
            <span>⚡ {state.recap.power} Pouvoir généré</span>
            <span>🃏 {state.recap.played} carte(s) jouée(s)</span>
            <span className="recap__buys">🛒 {state.recap.bought.length
              ? state.recap.bought.map((c, i) => (
                  <button key={i} className="chip" disabled={!c.code} title={c.code ? "Voir la carte" : undefined}
                    onClick={c.code ? () => setCardView(c) : undefined}>{c.name}</button>
                ))
              : "aucun achat"}</span>
          </div>
          {state.recap.events?.length > 0 && (
            <div className="recap__events">
              {state.recap.events.map((ev, i) => (
                <button key={i} className="chip chip--evt" disabled={!ev.card} title={ev.card ? "Voir la carte" : undefined}
                  onClick={ev.card ? () => setCardView(ev.card) : undefined}>{ev.label}</button>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Notifications d'effets discrets (ex. Les Mines de la Moria : +1 carte) */}
      {(state.notices || []).length > 0 && (
        <div className="notices">
          {state.notices.map((n, i) => <div key={i} className="notice">{n}</div>)}
        </div>
      )}

      {/* Révélation publique (attaques, cartes détruites…) — vue par TOUS */}
      {state.reveal && (state.reveal.label || state.reveal.cards?.length > 0) &&
        revealDismissed !== `${state.reveal.turn}-${state.reveal.label}` && (
        <div className="reveal-banner">
          <button className="recap__x" onClick={() => setRevealDismissed(`${state.reveal.turn}-${state.reveal.label}`)}>✕</button>
          <div className="reveal-banner__h">{state.reveal.label}</div>
          {state.reveal.cards?.length > 0 && (
            <div className="row" style={{ marginTop: "0.4rem" }}>
              {state.reveal.cards.map((c, i) => (
                <GameCard key={i} card={c} size="sm" onClick={() => setCardView(c)} />
              ))}
            </div>
          )}
        </div>
      )}

        </div>{/* /board__head-info */}

        <div className="board__head-piles" data-tour="piles">
          {/* Ennemi Principal · Valeur · Corruption (compact, en haut à droite) */}
          <div className="zone zone--piles">
            <div className="zone__label">Ennemi Principal &amp; piles</div>
            <div className="row">
              {arch?.card && (
                <GameCard
                  card={arch.card}
                  size="sm"
                  badge={`×${arch.remaining}`}
                  onClick={!busy ? () => setPreview(arch.card) : undefined}
                  muted={!archAffordable}
                />
              )}
              <GameCard
                card={state.stacks.valorCard}
                size="sm"
                badge={`×${state.stacks.valor}`}
                onClick={isMyTurn && !busy && power >= state.stacks.valorCard.cost && state.stacks.valor > 0 ? () => act("buy-valor") : undefined}
                disabled={!isMyTurn || power < state.stacks.valorCard.cost || state.stacks.valor <= 0}
              />
              <GameCard
                card={state.stacks.corruptionCard}
                size="sm"
                badge={`×${state.stacks.corruption}`}
              />
            </div>
          </div>
        </div>{/* /board__head-piles */}
      </div>{/* /board__head */}

      {/* Deck principal + Chemin sur la même ligne */}
      <div className="zone" data-tour="path">
        <div className="zone__label">Deck principal &amp; Chemin — clique une carte abordable pour l'acheter</div>
        <div className="row">
          <div className="deck-pile" title="Deck principal (pioche du Chemin)">
            <span className="deck-pile__emoji">🗺️</span>
            <span className="deck-pile__n">{state.mainDeckCount}</span>
            <span className="deck-pile__l">Deck</span>
          </div>
          <div className="card-gap" aria-hidden="true" />
          <AnimatePresence mode="popLayout">
            {state.path.map((c) => {
              const eff = c.buyCost ?? c.cost;
              const affordable = eff != null && power >= eff;
              return (
                <GameCard
                  key={c.iid}
                  card={c}
                  size="md"
                  badge={eff != null && eff < c.cost ? `−${c.cost - eff}` : undefined}
                  onClick={!busy ? () => setPreview(c) : undefined}
                  muted={!affordable}
                />
              );
            })}
          </AnimatePresence>
        </div>
      </div>

      {/* HUD + contrôles */}
      <div className="hud" data-tour="hud">
        <span className="hud__power">⚡ {power}</span>
        <span className="hud__stat">Pouvoir</span>
        <span className="hud__stat">🂠 Deck : {me.deckCount}</span>
        <span className="hud__stat">🗑️ Défausse : {me.discardCount}</span>
        <span className="hud__stat">🛒 Achats : {me.boughtThisTurn}</span>
      </div>
      <div className="controls" data-tour="controls">
        <button className="gbtn" disabled={busy || !isMyTurn || me.handCount === 0} onClick={() => act("play-all")}>▶️ Tout jouer</button>
        {state.canUndo && (
          <button className="gbtn gbtn--ghost" disabled={busy} title="Remet la dernière carte jouée dans ta main" onClick={() => act("undo-play")}>↩️ Reprendre la dernière carte</button>
        )}
        {effects.length > 0 && !showEffects && (
          <button className="gbtn" onClick={() => setShowEffects(true)}>⚡ Effets à appliquer ({effects.length})</button>
        )}
        <button
          className="gbtn gbtn--danger"
          disabled={busy || !isMyTurn || mandatoryLeft}
          title={mandatoryLeft ? "Applique d'abord les effets obligatoires" : undefined}
          onClick={() => {
            const optionalLeft = effects.some((e) => e.kind !== "neg" && e.applicable && !e.permanent);
            if (optionalLeft && !window.confirm("Tu n'as pas appliqué tous tes effets optionnels. Finir le tour quand même ?")) return;
            if (me.handCount > 0 && !window.confirm(`Tu as encore ${me.handCount} carte(s) non jouée(s) en main. Finir le tour quand même ?`)) return;
            act("end-turn");
          }}
        >
          ⏭️ Fin du tour
        </button>
      </div>

      {/* Cartes jouées ce tour (gauche) + Lieux permanents (droite) sur la MÊME
          ligne : le retour à la ligne ne se produit qu'au-delà de 5 cartes. */}
      {(me.played.length > 0 || me.inPlay.length > 0) && (
        <div className="zone">
          <div className="zone__label">
            {me.played.length > 0 && `Cartes jouées ce tour (${me.played.length})`}
            {me.played.length > 0 && me.inPlay.length > 0 && " · "}
            {me.inPlay.length > 0 && "Lieux permanents"}
          </div>
          <div className="row">
            {me.played.map((c, i) => <GameCard key={`played-${c.iid}-${i}`} card={c} size="md" hideAmbush muted />)}
            {me.inPlay.map((c) => <GameCard key={`lieu-${c.iid}`} card={c} size="md" hideAmbush permanentOnly />)}
          </div>
        </div>
      )}

      {/* Ta pioche & ta défausse + ta main */}
      <div className="zone" data-tour="hand">
        <div className="zone__label">Ta pioche, ta défausse &amp; ta main — survole une carte pour la Jouer ou l'Agrandir</div>
        <div className="row">
          <div className="deck-pile" title="Ta pioche (face cachée)">
            <span className="deck-pile__emoji">🂠</span>
            <span className="deck-pile__n">{me.deckCount}</span>
            <span className="deck-pile__l">Pioche</span>
          </div>
          {me.discard.length > 0 ? (
            <GameCard
              card={me.discard[me.discard.length - 1]}
              size="md"
              badge={`×${me.discardCount}`}
              hideAmbush
              muted
              onClick={() => setShowDiscard(true)}
            />
          ) : (
            <div className="deck-pile deck-pile--empty" title="Défausse vide">
              <span className="deck-pile__emoji">🗑️</span>
              <span className="deck-pile__l">Défausse vide</span>
            </div>
          )}
          <div className="card-gap" aria-hidden="true" />
          {/* Les cartes de la main se replient dans leur propre colonne (sous la 1re carte, pas sous la pioche). */}
          <div className="hand-cards">
            <AnimatePresence mode="popLayout">
              {me.hand.map((c) => (
                <GameCard
                  key={c.iid}
                  card={c}
                  size="md"
                  hideAmbush
                  actions={[
                    { label: "▶️ Jouer", className: "gcard__act--play", disabled: busy || !isMyTurn, onClick: () => act("play", c.iid) },
                    { label: "🔍 Agrandir", onClick: () => setCardView(c) },
                  ]}
                />
              ))}
            </AnimatePresence>
            {me.hand.length === 0 && <p style={{ opacity: 0.6, alignSelf: "center" }}>Main vide — termine ton tour.</p>}
          </div>
        </div>
      </div>

      <p className="error">{error}</p>
      <Log log={state.log} />

      {/* Modal : aperçu d'une carte (Chemin → Acheter, Ennemi Principal → Vaincre) */}
      {preview && (() => {
        const isArch = preview.category === "archenemy";
        const effCost = preview.cost == null
          ? null
          : isArch
            ? Math.max(0, preview.cost - archDiscount)
            : (preview.buyCost ?? preview.cost);
        const reduced = effCost != null && effCost < preview.cost;
        const affordable = effCost != null && power >= effCost;
        const label = preview.cost == null
          ? "Non achetable"
          : isArch
            ? `⚔️ Vaincre (coût ${effCost}${reduced ? ` au lieu de ${preview.cost}` : ""})`
            : `🛒 Acheter (coût ${effCost}${reduced ? ` au lieu de ${preview.cost}` : ""})`;
        return (
          <div className="modal-overlay" onClick={() => setPreview(null)}>
            <div className="modal modal--card" onClick={(e) => e.stopPropagation()}>
              <GameCard card={preview} size="xl" />
              <div className="controls" style={{ justifyContent: "center", marginTop: "1rem" }}>
                <button
                  className="gbtn"
                  disabled={busy || !affordable || !isMyTurn}
                  onClick={async () => {
                    await act(isArch ? "defeat" : "buy-path", isArch ? undefined : preview.iid);
                    setPreview(null);
                  }}
                >
                  {isMyTurn ? label : "Pas ton tour"}
                </button>
                <button className="gbtn gbtn--ghost" onClick={() => setPreview(null)}>Fermer</button>
              </div>
              {effCost != null && !affordable && (
                <p style={{ textAlign: "center", color: "#ff9b8a", fontSize: "0.85rem", margin: "0.4rem 0 0" }}>
                  Pouvoir insuffisant ({power}/{effCost}).
                </p>
              )}
            </div>
          </div>
        );
      })()}

      {/* Modal : détail d'une carte en lecture seule (depuis un récap) */}
      {cardView && (
        <div className="modal-overlay" onClick={() => setCardView(null)}>
          <div className="modal modal--card" onClick={(e) => e.stopPropagation()}>
            <GameCard card={cardView} size="xl" />
            <div className="controls" style={{ justifyContent: "center", marginTop: "1rem" }}>
              <button className="gbtn gbtn--ghost" onClick={() => setCardView(null)}>Fermer</button>
            </div>
          </div>
        </div>
      )}

      {/* Modal : consulter la défausse */}
      {showDiscard && (
        <div className="modal-overlay" onClick={() => setShowDiscard(false)}>
          <div className="modal" onClick={(e) => e.stopPropagation()}>
            <div className="modal__head">
              <h3>Ta défausse ({me.discardCount})</h3>
              <button className="gbtn gbtn--ghost" onClick={() => setShowDiscard(false)}>Fermer</button>
            </div>
            <div className="row" style={{ maxHeight: "60vh", overflowY: "auto" }}>
              {me.discard.map((c, i) => <GameCard key={`${c.iid}-${i}`} card={c} size="sm" />)}
            </div>
          </div>
        </div>
      )}

      {/* Modal : liste d'effets à appliquer */}
      {effects.length > 0 && showEffects && (
        <div className="modal-overlay">
          <div className="modal" style={{ display: "flex", flexDirection: "column" }}>
            <div className="modal__head" style={{ flex: "0 0 auto" }}>
              <h3>⚡ Effets à appliquer ({effects.length})</h3>
              <button className="gbtn gbtn--ghost" onClick={() => setShowEffects(false)}>Plus tard</button>
            </div>
            {mandatoryLeft && (
              <p style={{ color: "#ff9b8a", fontSize: "0.85rem", margin: "0 0 0.6rem", flex: "0 0 auto" }}>
                Les effets ⚠️ obligatoires doivent être appliqués avant de finir ton tour.
              </p>
            )}
            <div style={{ display: "flex", flexDirection: "column", gap: "0.8rem", overflowY: "auto", minHeight: 0, flex: "1 1 auto" }}>
              {effects.map((e) => (
                <div key={e.eid} className="effect-row">
                  <div className="effect-row__head">
                    <span className={`effect-badge ${e.kind === "neg" ? "effect-badge--neg" : "effect-badge--pos"}`}>
                      {e.kind === "neg" ? "⚠️ Obligatoire" : "✨ Optionnel"}
                    </span>
                    <strong>{e.label}</strong>
                    <span style={{ opacity: 0.6, fontSize: "0.8rem" }}>({e.sourceName})</span>
                  </div>
                  {e.desc && <p className="effect-row__desc">{e.desc}</p>}
                  {!e.applicable ? (
                    <div className="controls">
                      <span style={{ opacity: 0.6, alignSelf: "center" }}>Non applicable</span>
                      {e.optional && (
                        <button className="gbtn gbtn--ghost" disabled={busy} onClick={() => skipEff(e.eid)}>Retirer</button>
                      )}
                    </div>
                  ) : (
                    <div className="controls" style={{ alignItems: "flex-start" }}>
                      {e.op === "draw" && (
                        <button className="gbtn" disabled={busy} onClick={() => applyEff(e.eid)}>🂠 Piocher</button>
                      )}
                      {e.op === "corruption" && (
                        <button className="gbtn gbtn--danger" disabled={busy} onClick={() => applyEff(e.eid)}>🕷️ Prendre la Corruption</button>
                      )}
                      {e.op === "ambushAuto" && (
                        <button className="gbtn gbtn--danger" disabled={busy} onClick={() => applyEff(e.eid)}>😱 Subir l'embuscade</button>
                      )}
                      {e.op === "attack" && (
                        <button className="gbtn gbtn--danger" disabled={busy} onClick={() => applyEff(e.eid)}>🗡️ Subir l'attaque</button>
                      )}
                      {e.op === "receiveCard" && (
                        <button className="gbtn gbtn--danger" disabled={busy} onClick={() => applyEff(e.eid)}>🗡️ Recevoir la carte</button>
                      )}
                      {(e.op === "nameType" || e.op === "nameTypeMain") && (e.typeOptions || []).map((t) => (
                        <button key={t} className="gbtn" disabled={busy} onClick={() => applyEff(e.eid, { value: t })}>{t}</button>
                      ))}
                      {(e.op === "choosePlayer" || e.op === "destroyDespair" || e.op === "choosePlayerDraw" || e.op === "chooseOthersDraw" || e.op === "ulaireGive") && (e.playerOptions || []).map((p) => (
                        <button key={p.seat} className="gbtn" disabled={busy} onClick={() => applyEff(e.eid, { seat: p.seat })}>
                          🎯 {p.kind === "bot" ? "" : "🧑 "}{p.pseudo}{p.isMe ? " (moi)" : ""}
                        </button>
                      ))}
                      {(e.defenseOptions || []).map((c) => {
                        const others = state.players.filter((p) => !p.isMe);
                        const canDeflect = c.needsTarget && others.length > 0;
                        return (
                          <button key={`def-${c.iid}`} className="gbtn gbtn--defense" disabled={busy} title={c.text}
                            onClick={() => canDeflect ? setDeflect({ eid: e.eid, iid: c.iid }) : applyEff(e.eid, { defend: c.iid })}>
                            🛡️ {c.needsTarget ? "Dévier avec" : "Se défendre avec"} {c.name}
                          </button>
                        );
                      })}
                      {deflect && deflect.eid === e.eid && (
                        <div className="row" style={{ width: "100%", marginTop: "0.3rem" }}>
                          <span style={{ alignSelf: "center", opacity: 0.8 }}>↪️ Renvoyer à :</span>
                          {state.players.filter((p) => !p.isMe).map((p) => (
                            <button key={p.seat} className="gbtn" disabled={busy}
                              onClick={() => { applyEff(e.eid, { defend: deflect.iid, target: p.seat }); setDeflect(null); }}>
                              {p.kind === "bot" ? "" : "🧑 "}{p.pseudo}
                            </button>
                          ))}
                          <button className="gbtn gbtn--ghost" onClick={() => setDeflect(null)}>Annuler</button>
                        </div>
                      )}
                      {e.op === "reveal" && (
                        <>
                          {e.card && <GameCard card={e.card} size="md" />}
                          <button className="gbtn" disabled={busy} onClick={() => applyEff(e.eid)}>Fermer</button>
                        </>
                      )}
                      {e.op === "revealCards" && (
                        <div style={{ width: "100%" }}>
                          <div className="row">
                            {(e.cards || []).map((c, i) => (
                              <div key={i} className={`reveal-card ${c.gained ? "reveal-card--gained" : ""}`}>
                                <GameCard card={c} size="md" />
                                <div className="reveal-card__tag">{c.gained ? "✅ gagné (main)" : "↩︎ remis sous le deck"}</div>
                              </div>
                            ))}
                          </div>
                          <button className="gbtn" style={{ marginTop: "0.6rem" }} disabled={busy} onClick={() => applyEff(e.eid)}>Fermer</button>
                        </div>
                      )}
                      {(e.op === "destroyTopDeck" || e.op === "discardTopDeck") && (
                        <>
                          {e.card && <GameCard card={e.card} size="md" />}
                          <button className="gbtn gbtn--danger" disabled={busy} onClick={() => applyEff(e.eid)}>
                            {e.op === "destroyTopDeck" ? "Détruire" : "Défausser"}
                          </button>
                        </>
                      )}
                      {e.op === "putGainedOnDeck" && (
                        <>
                          {e.card && <GameCard card={e.card} size="md" />}
                          <button className="gbtn" disabled={busy} onClick={() => applyEff(e.eid)}>🂠 Sur mon deck</button>
                        </>
                      )}
                      {["destroy", "takeFromDiscard", "gainFromPath", "playFromPath", "groupReveal", "discard", "putOnDeck"].includes(e.op) && (
                        <div className="row">
                          {(e.options || []).map((o) => (
                            <GameCard key={o.iid} card={o} size="md" onClick={busy ? undefined : () => applyEff(e.eid, { iid: o.iid })} />
                          ))}
                        </div>
                      )}
                      {e.optional && e.op !== "reveal" && !e.permanent && (
                        <button className="gbtn gbtn--ghost" disabled={busy} onClick={() => skipEff(e.eid)}>
                          {e.op === "putOnDeck" ? "Ne rien remettre" : "Passer"}
                        </button>
                      )}
                      {e.kind !== "neg" && (
                        <button
                          className="gbtn gbtn--ghost"
                          style={{ fontSize: "0.78rem", opacity: 0.75 }}
                          disabled={busy}
                          title={mutedOps.has(e.op)
                            ? "Réafficher automatiquement ce type d'action"
                            : "Ne plus ouvrir automatiquement ce type d'action (reste accessible via ⚡ Effets à appliquer)"}
                          onClick={() => toggleMuteOp(e.op)}
                        >
                          {mutedOps.has(e.op) ? "🔔 Réafficher" : "🔕 Ne plus me demander"}
                        </button>
                      )}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function Log({ log }) {
  return (
    <div className="log">
      {[...log].reverse().map((l, i) => <p key={i}>{l}</p>)}
    </div>
  );
}
