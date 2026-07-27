import { useEffect, useState } from "react";
import { api } from "../api";

// Accueil (après connexion) : créer une table ou en rejoindre une par code.
export default function Lobby({ me, onEnterLobby, onAdmin, onLogout }) {
  const [maxPlayers, setMaxPlayers] = useState(5);
  const [code, setCode] = useState("");
  const [pseudo, setPseudo] = useState(me?.pseudo || "");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  // Pré-remplit avec le pseudo du compte dès qu'il est chargé.
  useEffect(() => {
    if (me?.pseudo) setPseudo((p) => p || me.pseudo);
  }, [me?.pseudo]);

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
