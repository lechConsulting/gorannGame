import { useEffect, useState } from "react";
import { api } from "../api";

// Accueil (après connexion) : créer une table, en rejoindre une par code, ou
// reprendre une partie en cours (l'état est sauvegardé côté serveur).
export default function Lobby({ me, onEnterLobby, onResume, onAdmin, onLogout }) {
  const [maxPlayers, setMaxPlayers] = useState(5);
  const [code, setCode] = useState("");
  const [pseudo, setPseudo] = useState(me?.pseudo || "");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [mine, setMine] = useState([]); // parties en cours à reprendre

  // Pré-remplit avec le pseudo du compte dès qu'il est chargé.
  useEffect(() => {
    if (me?.pseudo) setPseudo((p) => p || me.pseudo);
  }, [me?.pseudo]);

  // Charge les parties en cours du joueur (reprise après coupure).
  useEffect(() => {
    api.lobbyMine().then(setMine).catch(() => setMine([]));
  }, []);

  async function resume(id) {
    setBusy(true);
    setError("");
    try {
      const session = await api.getGame(id);
      onResume(session);
    } catch (e) {
      setError(e.message);
      setBusy(false);
    }
  }

  async function abandon(id) {
    if (!window.confirm("Abandonner cette partie ? Elle sera définitivement supprimée.")) return;
    setBusy(true);
    setError("");
    try {
      await api.lobbyAbandon(id);
      setMine((list) => list.filter((s) => s.id !== id));
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function create() {
    setBusy(true);
    setError("");
    try {
      const lobby = await api.lobbyCreate(maxPlayers, pseudo.trim());
      onEnterLobby(lobby);
    } catch (e) {
      setError(e.message);
      setBusy(false);
    }
  }

  async function join() {
    setBusy(true);
    setError("");
    try {
      const lobby = await api.lobbyJoin(code.trim(), pseudo.trim());
      onEnterLobby(lobby);
    } catch (e) {
      setError(e.message);
      setBusy(false);
    }
  }

  return (
    <div className="lobby">
      <h1>Le Seigneur des Anneaux — Deck Builder</h1>
      <p style={{ textAlign: "center", opacity: 0.8 }}>
        Bienvenue{me?.pseudo ? `, ${me.pseudo}` : ""} ! Crée une table ou rejoins-en une.
      </p>

      <label className="home-field" style={{ maxWidth: 320, margin: "0.8rem auto 0" }}>
        Ton pseudo à la table
        <input value={pseudo} onChange={(e) => setPseudo(e.target.value)} placeholder="Ton pseudo" maxLength={24} />
      </label>

      {/* Reprise : parties en cours où le joueur a un siège. */}
      {mine.length > 0 && (
        <div className="resume">
          <h2>▶️ Reprendre une partie</h2>
          <p style={{ opacity: 0.65, fontSize: "0.82rem", margin: "0 0 0.6rem" }}>
            Tes parties en cours sont sauvegardées : reprends là où tu t'es arrêté.
          </p>
          <div className="resume__list">
            {mine.map((s) => (
              <div key={s.id} className="resume__row">
                <div className="resume__info">
                  <strong>{s.game}</strong> · tour {s.turn} · code {s.code}
                  <div className="resume__sub">
                    {s.players.map((p) => p.pseudo).join(", ")}
                    {s.activePseudo && (
                      <> — {s.myTurn ? "à toi de jouer" : `au tour de ${s.activePseudo}`}</>
                    )}
                  </div>
                </div>
                <div className="resume__actions">
                  <button className="gbtn" disabled={busy} onClick={() => resume(s.id)}>Reprendre</button>
                  <button className="gbtn gbtn--ghost resume__abandon" disabled={busy}
                    onClick={() => abandon(s.id)} title="Supprimer définitivement cette partie">🗑️ Abandonner</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="home-grid">
        <div className="home-card">
          <h2>🆕 Créer une table</h2>
          <label className="home-field">
            Nombre de joueurs max
            <select value={maxPlayers} onChange={(e) => setMaxPlayers(Number(e.target.value))}>
              {[2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} joueurs</option>)}
            </select>
          </label>
          <p style={{ opacity: 0.65, fontSize: "0.82rem" }}>
            Tu pourras compléter les places libres avec des joueurs automatiques (bots) — ou jouer seul contre 4 bots.
          </p>
          <button className="gbtn" disabled={busy} onClick={create}>Créer</button>
        </div>

        <div className="home-card">
          <h2>🔗 Rejoindre</h2>
          <label className="home-field">
            Code de la table
            <input
              value={code}
              onChange={(e) => setCode(e.target.value.toUpperCase())}
              placeholder="ex. K7QX"
              maxLength={8}
            />
          </label>
          <button className="gbtn" disabled={busy || !code.trim()} onClick={join}>Rejoindre</button>
        </div>
      </div>

      <div className="controls" style={{ justifyContent: "center", marginTop: "1.4rem" }}>
        {me?.isAdmin && <button className="gbtn gbtn--ghost" onClick={onAdmin}>🔧 Back-office</button>}
        <button className="gbtn gbtn--ghost" onClick={onLogout}>Déconnexion</button>
      </div>
      <p className="error" style={{ textAlign: "center" }}>{error}</p>
    </div>
  );
}
