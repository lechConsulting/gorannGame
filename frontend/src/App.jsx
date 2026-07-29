import { useEffect, useState } from "react";
import { api, hasToken } from "./api";
import AuthGate from "./game/AuthGate";
import Lobby from "./game/Lobby";
import WaitingRoom from "./game/WaitingRoom";
import GameBoard from "./game/GameBoard";
import Admin from "./admin/Admin";
import "./game/game.css";

export default function App() {
  const [authed, setAuthed] = useState(hasToken());
  const [me, setMe] = useState(null);
  const [lobby, setLobby] = useState(null);     // salle d'attente (avant démarrage)
  const [session, setSession] = useState(null); // partie en cours
  const [view, setView] = useState("game");     // "game" | "admin"

  // Récupère le profil dès qu'on est authentifié (pour le pseudo / isAdmin).
  useEffect(() => {
    if (authed && !me) api.me().then(setMe).catch(() => setAuthed(false));
  }, [authed, me]);

  function logout() {
    api.logout();
    setMe(null);
    setLobby(null);
    setSession(null);
    setAuthed(false);
  }

  if (view === "admin") return <Admin onQuit={() => setView("game")} />;

  if (!authed) return <AuthGate onAuthed={() => setAuthed(true)} />;

  if (session) {
    return <GameBoard session={session} onQuit={() => setSession(null)} />;
  }

  if (lobby) {
    return (
      <WaitingRoom
        lobbyId={lobby.id}
        initial={lobby}
        onStart={(game) => { setSession(game); setLobby(null); }}
        onLeave={() => setLobby(null)}
      />
    );
  }

  return (
    <Lobby
      me={me}
      onEnterLobby={setLobby}
      onResume={setSession}
      onAdmin={() => setView("admin")}
      onLogout={logout}
    />
  );
}
