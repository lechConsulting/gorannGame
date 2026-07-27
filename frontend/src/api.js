// Client API du moteur de jeu + lobby + back-office (backend Symfony sur :8000).
const BASE = import.meta.env.VITE_API_BASE ?? "http://localhost:8000";
// URL publique du hub Mercure (abonnement temps réel côté navigateur).
const MERCURE_URL = import.meta.env.VITE_MERCURE_URL ?? "http://127.0.0.1:3000/.well-known/mercure";

let token = localStorage.getItem("lotr_token") || null;
let refreshToken = localStorage.getItem("lotr_refresh_token") || null;

export function setToken(t) {
  token = t;
  if (t) localStorage.setItem("lotr_token", t);
  else localStorage.removeItem("lotr_token");
}

// Enregistre le JWT d'accès (court) + le refresh token (long) renvoyés par
// /api/login et /api/token/refresh. Passer `null` déconnecte totalement.
export function setTokens(t, rt) {
  setToken(t);
  refreshToken = rt ?? null;
  if (refreshToken) localStorage.setItem("lotr_refresh_token", refreshToken);
  else localStorage.removeItem("lotr_refresh_token");
}

export const hasToken = () => !!token;

// Dédoublonne les rafraîchissements concurrents : si plusieurs requêtes se
// prennent un 401 en même temps, elles attendent le même appel de refresh.
let refreshing = null;

async function refreshAccessToken() {
  if (!refreshToken) return false;
  if (!refreshing) {
    refreshing = fetch(`${BASE}/api/token/refresh`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ refresh_token: refreshToken }),
    })
      .then(async (res) => {
        if (!res.ok) return false;
        const d = await res.json().catch(() => ({}));
        if (!d.token) return false;
        setTokens(d.token, d.refresh_token ?? refreshToken);
        return true;
      })
      .catch(() => false)
      .finally(() => { refreshing = null; });
  }
  return refreshing;
}

async function rawReq(method, path, body, auth) {
  const headers = {};
  if (body) headers["Content-Type"] = "application/json";
  if (auth && token) headers["Authorization"] = `Bearer ${token}`;
  return fetch(`${BASE}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });
}

async function req(method, path, body, auth = false) {
  let res = await rawReq(method, path, body, auth);

  // JWT d'accès expiré : on tente un rafraîchissement transparent puis on
  // rejoue la requête. La session ne casse qu'une fois le refresh token mort.
  if (res.status === 401 && auth && refreshToken) {
    const ok = await refreshAccessToken();
    if (ok) {
      res = await rawReq(method, path, body, auth);
    }
  }

  if (res.status === 401) {
    setTokens(null, null); // refresh impossible → retour à l'écran de connexion
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || data.message || `Erreur ${res.status}`);
  return data;
}

/**
 * S'abonne à un topic Mercure et rappelle `onMessage` à chaque ping.
 * Renvoie une fonction de désabonnement. Le ping ne contient pas l'état :
 * l'appelant doit re-fetcher (getGame / lobby.get) pour l'obtenir masqué.
 */
export function subscribe(topic, onMessage) {
  let es;
  try {
    const url = new URL(MERCURE_URL);
    url.searchParams.append("topic", topic);
    es = new EventSource(url.toString());
    es.onmessage = (ev) => {
      try {
        onMessage(JSON.parse(ev.data));
      } catch {
        onMessage({});
      }
    };
    es.onerror = () => {}; // le hub peut être absent : on dégrade en silence
  } catch {
    return () => {};
  }
  return () => es && es.close();
}

export const api = {
  // Jeu
  listGame: (slug = "lotr-deck-builder") => req("GET", `/api/games/${slug}`),
  getGame: (id) => req("GET", `/api/play/${id}`, null, true),
  tick: (id) => req("POST", `/api/play/${id}/tick`, {}, true),
  setBotDelay: (id, ms) => req("POST", `/api/play/${id}/bot-delay`, { ms }, true),
  action: (id, type, iid) => req("POST", `/api/play/${id}/action`, { type, iid }, true),
  applyEffect: (id, eid, payload) => req("POST", `/api/play/${id}/action`, { type: "apply-effect", eid, payload }, true),
  skipEffect: (id, eid) => req("POST", `/api/play/${id}/action`, { type: "skip-effect", eid }, true),

  // Lobby (multijoueur)
  lobbyList: () => req("GET", "/api/lobby", null, true),
  lobbyCreate: (maxPlayers, pseudo, slug = "lotr-deck-builder") => req("POST", "/api/lobby/create", { slug, maxPlayers, pseudo }, true),
  lobbyJoin: (code, pseudo) => req("POST", "/api/lobby/join", { code, pseudo }, true),
  lobbyGet: (id) => req("GET", `/api/lobby/${id}`, null, true),
  lobbyHero: (id, hero, seat) => req("POST", `/api/lobby/${id}/hero`, { hero, seat }, true),
  lobbyPseudo: (id, pseudo) => req("POST", `/api/lobby/${id}/pseudo`, { pseudo }, true),
  lobbyAddBot: (id) => req("POST", `/api/lobby/${id}/bot`, {}, true),
  lobbyBotLevel: (id, seat, level) => req("POST", `/api/lobby/${id}/bot-level`, { seat, level }, true),
  lobbyRemove: (id, seat) => req("POST", `/api/lobby/${id}/remove`, { seat }, true),
  lobbyStart: (id) => req("POST", `/api/lobby/${id}/start`, {}, true),

  // Auth
  register: (email, pseudo, password) => req("POST", "/api/register", { email, pseudo, password }),
  login: async (email, password) => {
    const d = await req("POST", "/api/login", { email, password });
    setTokens(d.token, d.refresh_token);
    return d;
  },
  me: () => req("GET", "/api/me", null, true),
  logout: () => setTokens(null, null),

  // Back-office (ROLE_ADMIN)
  adminCards: (slug = "lotr-deck-builder") => req("GET", `/api/admin/cards?game=${slug}`, null, true),
  adminCreateCard: (card) => req("POST", "/api/admin/cards", card, true),
  adminUpdateCard: (id, card) => req("PUT", `/api/admin/cards/${id}`, card, true),
  adminDeleteCard: (id) => req("DELETE", `/api/admin/cards/${id}`, null, true),
  adminHeroes: (slug = "lotr-deck-builder") => req("GET", `/api/admin/heroes?game=${slug}`, null, true),

  // Back-office — gestion des utilisateurs (ROLE_ADMIN)
  adminUsers: () => req("GET", "/api/admin/users", null, true),
  adminCreateUser: (user) => req("POST", "/api/admin/users", user, true),
  adminUpdateUser: (id, patch) => req("PATCH", `/api/admin/users/${id}`, patch, true),
  adminDeleteUser: (id) => req("DELETE", `/api/admin/users/${id}`, null, true),
};
