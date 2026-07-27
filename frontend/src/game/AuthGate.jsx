import { useState } from "react";
import { api } from "../api";

// Portail d'authentification : connexion ou inscription. Chaque joueur d'une
// partie multijoueur doit avoir un compte (le JWT identifie son siège).
export default function AuthGate({ onAuthed }) {
  const [mode, setMode] = useState("login"); // "login" | "register"
  const [email, setEmail] = useState("");
  const [pseudo, setPseudo] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      if (mode === "register") {
        await api.register(email, pseudo, password);
      }
      const me = await api.login(email, password);
      onAuthed(me);
    } catch (err) {
      setError(err.message);
      setLoading(false);
    }
  }

  return (
    <div className="lobby">
      <h1>Le Seigneur des Anneaux — Deck Builder</h1>
      <p style={{ textAlign: "center", opacity: 0.75 }}>
        {mode === "login" ? "Connecte-toi pour jouer." : "Crée ton compte de joueur."}
      </p>

      <form onSubmit={submit} className="auth-form">
        <input
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="Email"
          type="email"
          autoComplete="email"
        />
        {mode === "register" && (
          <input
            value={pseudo}
            onChange={(e) => setPseudo(e.target.value)}
            placeholder="Pseudo (affiché à la table)"
          />
        )}
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          placeholder="Mot de passe (6 caractères min.)"
          autoComplete={mode === "login" ? "current-password" : "new-password"}
        />
        <button className="gbtn" disabled={loading}>
          {loading ? "…" : mode === "login" ? "🔑 Se connecter" : "✨ Créer mon compte"}
        </button>
      </form>

      <p style={{ textAlign: "center", marginTop: "0.8rem" }}>
        {mode === "login" ? (
          <button className="linklike" onClick={() => { setMode("register"); setError(""); }}>
            Pas encore de compte ? S'inscrire
          </button>
        ) : (
          <button className="linklike" onClick={() => { setMode("login"); setError(""); }}>
            Déjà un compte ? Se connecter
          </button>
        )}
      </p>
      <p className="error" style={{ textAlign: "center" }}>{error}</p>
    </div>
  );
}
