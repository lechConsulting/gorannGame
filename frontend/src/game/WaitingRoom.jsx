import { useEffect, useRef, useState } from "react";
import { api, subscribe } from "../api";

// Salle d'attente d'une table. Affiche les sièges (humains/bots), permet de
// choisir son héros, à l'hôte d'ajouter/retirer des joueurs automatiques, puis
// de démarrer. Se met à jour en temps réel via Mercure (topic lobby/{id}), avec
// un repli par sondage si le hub est absent.
export default function WaitingRoom({ lobbyId, initial, onStart, onLeave }) {
  const [lobby, setLobby] = useState(initial);
  const [heroes, setHeroes] = useState([]);
  const [cards, setCards] = useState([]);
  const [hovered, setHovered] = useState(null); // héros survolé (aperçu capacité)
  const [pseudoDraft, setPseudoDraft] = useState(null); // null = pas encore édité
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const startedRef = useRef(false);

  useEffect(() => {
    api.listGame().then((g) => { setHeroes(g.heroes || []); setCards(g.cards || []); }).catch(() => {});
  }, []);

  // Capacité d'un héros = le texte de sa carte de départ (startingCardCode).
  function abilityOf(heroName) {
    const h = heroes.find((x) => x.name === heroName);
    if (!h) return null;
    const card = cards.find((c) => c.code === h.startingCardCode);
    return card ? { name: card.name, text: card.text } : null;
  }

  async function refresh() {
    try {
      const l = await api.lobbyGet(lobbyId);
      if (l.started && !startedRef.current) {
        startedRef.current = true;
        const game = await api.getGame(lobbyId);
        onStart(game);
        return;
      }
      setLobby(l);
    } catch (e) {
      setError(e.message);
    }
  }

  // Temps réel + repli par sondage (2,5 s).
  useEffect(() => {
    const unsub = subscribe(`lobby/${lobbyId}`, () => refresh());
    const poll = setInterval(refresh, 2500);
    return () => { unsub(); clearInterval(poll); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lobbyId]);

  const seats = lobby.seats || [];
  const usedHeroes = seats.map((s) => s.hero).filter(Boolean);
  const mySeatRow = seats.find((s) => s.isMe);
  const allHeroesChosen = seats.every((s) => s.hero);
  const canStart = lobby.isHost && seats.length >= 2 && allHeroesChosen;

  async function call(fn) {
    setBusy(true);
    setError("");
    try {
      const l = await fn();
      if (l && !l.started) setLobby(l);
      else await refresh();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="lobby">
      <h1>Salle d'attente</h1>
      <p style={{ textAlign: "center" }}>
        Code de la table : <strong className="table-code">{lobby.code}</strong>
        <span style={{ opacity: 0.7 }}> — partage-le pour que d'autres rejoignent</span>
      </p>

      {mySeatRow && (
        <div className="my-pseudo">
          <label>✏️ Mon pseudo</label>
          <input
            value={pseudoDraft ?? mySeatRow.pseudo ?? ""}
            maxLength={24}
            disabled={busy}
            onChange={(e) => setPseudoDraft(e.target.value)}
          />
          <button className="gbtn gbtn--sm" disabled={busy || pseudoDraft == null || !pseudoDraft.trim() || pseudoDraft === mySeatRow.pseudo}
            onClick={() => call(() => api.lobbyPseudo(lobbyId, pseudoDraft.trim())).then(() => setPseudoDraft(null))}>
            Renommer
          </button>
        </div>
      )}

      <div className="zone__label" style={{ textAlign: "center" }}>
        Joueurs ({seats.length}/{lobby.maxPlayers})
      </div>
      <div className="seats">
        {seats.map((s) => (
          <div key={s.seat} className={`seat ${s.isMe ? "seat--me" : ""}`}>
            <div className="seat__n">
              {s.kind === "bot" ? "" : "🧑 "}{s.pseudo}
              {s.seat === 0 && <span className="seat__host"> · hôte</span>}
              {s.isMe && <span className="seat__you"> · toi</span>}
            </div>
            <div className="seat__hero">
              {s.hero || <em style={{ opacity: 0.6 }}>héros non choisi</em>}
              {s.hero && abilityOf(s.hero) && (
                <span className="seat__ability" title={abilityOf(s.hero).text}> · {abilityOf(s.hero).name}</span>
              )}
            </div>
            {lobby.isHost && s.kind === "bot" && (
              <select
                value={s.hero || ""}
                disabled={busy}
                onChange={(e) => call(() => api.lobbyHero(lobbyId, e.target.value, s.seat))}
              >
                <option value="" disabled>Héros du bot…</option>
                {heroes.map((h) => (
                  <option key={h.name} value={h.name} disabled={usedHeroes.includes(h.name) && h.name !== s.hero}>
                    {h.name}
                  </option>
                ))}
              </select>
            )}
            {lobby.isHost && s.seat !== 0 && (
              <button className="gbtn gbtn--ghost gbtn--sm" disabled={busy} onClick={() => call(() => api.lobbyRemove(lobbyId, s.seat))}>
                Retirer
              </button>
            )}
          </div>
        ))}
        {lobby.isHost && seats.length < lobby.maxPlayers && (
          <button className="seat seat--add" disabled={busy} onClick={() => call(() => api.lobbyAddBot(lobbyId))}>
            ➕ Ajouter un bot
          </button>
        )}
      </div>

      <div className="zone__label" style={{ textAlign: "center", marginTop: "1rem" }}>
        {mySeatRow?.hero ? `Ton héros : ${mySeatRow.hero}` : "Choisis ton héros"}
      </div>
      <p style={{ textAlign: "center", opacity: 0.65, fontSize: "0.8rem", margin: "0 0 0.4rem" }}>
        Survole un héros pour lire sa carte de départ (elle oriente ta stratégie du 1er tour).
      </p>
      <div className="heroes">
        {heroes.map((h) => {
          const takenByOther = usedHeroes.includes(h.name) && mySeatRow?.hero !== h.name;
          const ab = abilityOf(h.name);
          return (
            <div
              key={h.name}
              className={`hero-pick ${mySeatRow?.hero === h.name ? "hero-pick--on" : ""} ${takenByOther ? "hero-pick--off" : ""}`}
              onClick={() => !busy && !takenByOther && call(() => api.lobbyHero(lobbyId, h.name))}
              onMouseEnter={() => setHovered(h.name)}
              onMouseLeave={() => setHovered((cur) => (cur === h.name ? null : cur))}
            >
              <div className="hero-pick__n">{h.name}</div>
              <div className="hero-pick__r">{h.race}</div>
              {ab && <div className="hero-pick__ability">🎴 {ab.name}</div>}
            </div>
          );
        })}
      </div>

      {/* Détail de la capacité (héros survolé, sinon le mien) */}
      {(() => {
        const shown = hovered || mySeatRow?.hero;
        const ab = shown && abilityOf(shown);
        if (!ab) return null;
        return (
          <div className="hero-detail">
            <div className="hero-detail__h">🎴 Carte de départ de <strong>{shown}</strong> : {ab.name}</div>
            <div className="hero-detail__t">{ab.text}</div>
          </div>
        );
      })()}

      <div className="controls" style={{ justifyContent: "center", marginTop: "1.2rem" }}>
        {lobby.isHost && (
          <button className="gbtn" disabled={busy || !canStart} onClick={() => call(() => api.lobbyStart(lobbyId))}
            title={!canStart ? "Il faut ≥2 joueurs et un héros pour chacun." : undefined}>
            ⚔️ Démarrer la partie
          </button>
        )}
        {!lobby.isHost && <span style={{ opacity: 0.75, alignSelf: "center" }}>En attente que l'hôte démarre…</span>}
        <button className="gbtn gbtn--ghost" disabled={busy} onClick={onLeave}>← Quitter</button>
      </div>
      <p className="error" style={{ textAlign: "center" }}>{error}</p>
    </div>
  );
}
