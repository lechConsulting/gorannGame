import { useEffect, useState } from "react";
import { api, hasToken } from "../api";
import "./admin.css";

const CATEGORIES = ["main_deck", "starter", "hero_starting", "valor", "archenemy", "corruption"];
const TYPES = ["Ennemi", "Allié", "Lieu", "Artefact", "Manœuvre", "Chance", "Départ", "Corruption"];
const EMPTY = { code: "", name: "", type: "Allié", category: "main_deck", cost: "", pv: "", level: "", hero: "", quantity: 1, text: "" };

export default function Admin({ onQuit }) {
  const [authed, setAuthed] = useState(hasToken());
  const [tab, setTab] = useState("cards"); // "cards" | "users"

  if (!authed) return <Login onOk={() => setAuthed(true)} onQuit={onQuit} />;

  return (
    <div className="bo">
      <div className="bo__top">
        <div className="bo__tabs">
          <button className={`bo__tab ${tab === "cards" ? "is-active" : ""}`} onClick={() => setTab("cards")}>🎴 Cartes</button>
          <button className={`bo__tab ${tab === "users" ? "is-active" : ""}`} onClick={() => setTab("users")}>👤 Utilisateurs</button>
        </div>
        <div className="bo__actions">
          <button className="gbtn gbtn--ghost" onClick={() => { api.logout(); setAuthed(false); }}>Déconnexion</button>
          <button className="gbtn gbtn--ghost" onClick={onQuit}>← Jeu</button>
        </div>
      </div>

      {tab === "cards" ? <CardsPanel onExpired={() => setAuthed(false)} /> : <UsersPanel onExpired={() => setAuthed(false)} />}
    </div>
  );
}

// ------------------------------------------------------------------ Cartes

function CardsPanel({ onExpired }) {
  const [cards, setCards] = useState([]);
  const [error, setError] = useState("");
  const [q, setQ] = useState("");
  const [cat, setCat] = useState("all");
  const [edit, setEdit] = useState(null);

  useEffect(() => { reload(); }, []);

  async function reload() {
    try {
      setCards(await api.adminCards());
    } catch (e) {
      setError(e.message);
      if (isAuthError(e)) onExpired();
    }
  }

  const filtered = cards.filter(
    (c) => (cat === "all" || c.category === cat) && c.name.toLowerCase().includes(q.toLowerCase()),
  );

  async function save(card) {
    setError("");
    try {
      if (card.id) await api.adminUpdateCard(card.id, card);
      else await api.adminCreateCard(card);
      setEdit(null);
      reload();
    } catch (e) {
      setError(e.message);
    }
  }

  async function remove(card) {
    if (!window.confirm(`Supprimer « ${card.name} » ?`)) return;
    try {
      await api.adminDeleteCard(card.id);
      reload();
    } catch (e) {
      setError(e.message);
    }
  }

  return (
    <>
      <div className="bo__subtop">
        <h2>Cartes ({cards.length})</h2>
        <button className="gbtn" onClick={() => setEdit({ ...EMPTY })}>+ Nouvelle carte</button>
      </div>

      <div className="bo__filters">
        <input placeholder="Rechercher par nom…" value={q} onChange={(e) => setQ(e.target.value)} />
        <select value={cat} onChange={(e) => setCat(e.target.value)}>
          <option value="all">Toutes catégories</option>
          {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>
      <p className="error">{error}</p>

      <table className="bo__table">
        <thead>
          <tr><th>Nom</th><th>Type</th><th>Catégorie</th><th>Coût</th><th>PV</th><th>Niv</th><th>Héros</th><th>Qté</th><th>Texte</th><th></th></tr>
        </thead>
        <tbody>
          {filtered.map((c) => (
            <tr key={c.id}>
              <td><strong>{c.name}</strong></td>
              <td>{c.type}</td>
              <td>{c.category}</td>
              <td>{c.cost ?? "✱"}</td>
              <td>{c.pv ?? "—"}</td>
              <td>{c.level ?? ""}</td>
              <td>{c.hero ?? ""}</td>
              <td>{c.quantity}</td>
              <td className="bo__text">{c.text}</td>
              <td className="bo__rowbtns">
                <button className="gbtn gbtn--sm" onClick={() => setEdit({ ...c, cost: c.cost ?? "", pv: c.pv ?? "", level: c.level ?? "", hero: c.hero ?? "" })}>✏️</button>
                <button className="gbtn gbtn--sm gbtn--danger" onClick={() => remove(c)}>🗑️</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {edit && <EditForm card={edit} onCancel={() => setEdit(null)} onSave={save} />}
    </>
  );
}

// ------------------------------------------------------------- Utilisateurs

function UsersPanel({ onExpired }) {
  const [users, setUsers] = useState([]);
  const [error, setError] = useState("");
  const [q, setQ] = useState("");
  const [edit, setEdit] = useState(null); // { ...user } ou { create:true }

  useEffect(() => { reload(); }, []);

  async function reload() {
    try {
      setUsers(await api.adminUsers());
    } catch (e) {
      setError(e.message);
      if (isAuthError(e)) onExpired();
    }
  }

  const filtered = users.filter(
    (u) => u.email.toLowerCase().includes(q.toLowerCase()) || (u.pseudo || "").toLowerCase().includes(q.toLowerCase()),
  );

  async function save(form) {
    setError("");
    try {
      if (form.create) {
        await api.adminCreateUser({ email: form.email, pseudo: form.pseudo, password: form.password, isAdmin: form.isAdmin });
      } else {
        const patch = { pseudo: form.pseudo, isAdmin: form.isAdmin };
        if (form.password) patch.password = form.password;
        await api.adminUpdateUser(form.id, patch);
      }
      setEdit(null);
      reload();
    } catch (e) {
      setError(e.message);
    }
  }

  async function toggleAdmin(u) {
    setError("");
    try {
      await api.adminUpdateUser(u.id, { isAdmin: !u.isAdmin });
      reload();
    } catch (e) {
      setError(e.message);
    }
  }

  async function remove(u) {
    if (!window.confirm(`Supprimer le compte « ${u.pseudo || u.email} » ?\nSon historique de parties sera aussi supprimé.`)) return;
    setError("");
    try {
      await api.adminDeleteUser(u.id);
      reload();
    } catch (e) {
      setError(e.message);
    }
  }

  return (
    <>
      <div className="bo__subtop">
        <h2>Utilisateurs ({users.length})</h2>
        <button className="gbtn" onClick={() => setEdit({ create: true, email: "", pseudo: "", password: "", isAdmin: false })}>+ Nouvel utilisateur</button>
      </div>

      <div className="bo__filters">
        <input placeholder="Rechercher par email ou pseudo…" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      <p className="error">{error}</p>

      <table className="bo__table">
        <thead>
          <tr><th>Email</th><th>Pseudo</th><th>Rôle</th><th>Parties</th><th>Inscrit le</th><th></th></tr>
        </thead>
        <tbody>
          {filtered.map((u) => (
            <tr key={u.id}>
              <td>
                <strong>{u.email}</strong>
                {u.isSuperAdmin && <span className="bo__tag bo__tag--super" title="Super-administrateur : toujours admin">super-admin</span>}
                {u.isSelf && <span className="bo__tag">vous</span>}
              </td>
              <td>{u.pseudo}</td>
              <td>
                {u.isAdmin
                  ? <span className="bo__role bo__role--admin">ADMIN</span>
                  : <span className="bo__role bo__role--joueur">JOUEUR</span>}
              </td>
              <td>{u.stats?.games ?? 0}{u.stats?.wins ? ` (${u.stats.wins}🏆)` : ""}</td>
              <td>{new Date(u.createdAt).toLocaleDateString("fr-FR")}</td>
              <td className="bo__rowbtns">
                <button
                  className="gbtn gbtn--sm"
                  disabled={u.isSuperAdmin || u.isSelf}
                  title={u.isSuperAdmin ? "Le super-admin reste toujours administrateur" : u.isSelf ? "Vous ne pouvez pas changer votre propre rôle" : ""}
                  onClick={() => toggleAdmin(u)}
                >
                  {u.isAdmin ? "↓ Joueur" : "↑ Admin"}
                </button>
                <button className="gbtn gbtn--sm" onClick={() => setEdit({ ...u, password: "" })}>✏️</button>
                <button
                  className="gbtn gbtn--sm gbtn--danger"
                  disabled={u.isSuperAdmin || u.isSelf}
                  title={u.isSuperAdmin ? "Le super-admin ne peut pas être supprimé" : u.isSelf ? "Vous ne pouvez pas supprimer votre propre compte" : ""}
                  onClick={() => remove(u)}
                >
                  🗑️
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {edit && <UserForm user={edit} onCancel={() => setEdit(null)} onSave={save} />}
    </>
  );
}

function UserForm({ user, onCancel, onSave }) {
  const [f, setF] = useState(user);
  const isCreate = !!user.create;
  const roleLocked = f.isSuperAdmin || f.isSelf; // super-admin / soi-même : rôle non modifiable
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  return (
    <div className="modal-overlay" onClick={onCancel}>
      <div className="modal bo-edit" onClick={(e) => e.stopPropagation()}>
        <div className="modal__head">
          <h3>{isCreate ? "Nouvel utilisateur" : `Éditer : ${user.pseudo || user.email}`}</h3>
        </div>
        <div className="bo-edit__grid">
          <label>Email
            <input type="email" value={f.email} onChange={set("email")} disabled={!isCreate} />
          </label>
          <label>Pseudo<input value={f.pseudo} onChange={set("pseudo")} /></label>
          <label>{isCreate ? "Mot de passe" : "Nouveau mot de passe (laisser vide)"}
            <input type="password" value={f.password || ""} onChange={set("password")} autoComplete="new-password" />
          </label>
          <label className="bo-edit__check">
            <input
              type="checkbox"
              checked={!!f.isAdmin}
              disabled={roleLocked}
              onChange={(e) => setF({ ...f, isAdmin: e.target.checked })}
            />
            Administrateur
            {f.isSuperAdmin && <span className="bo__tag bo__tag--super">super-admin</span>}
          </label>
        </div>
        {roleLocked && (
          <p className="bo__hint">
            {f.isSuperAdmin
              ? "Le super-administrateur est toujours administrateur."
              : "Vous ne pouvez pas modifier votre propre rôle."}
          </p>
        )}
        <div className="bo__actions" style={{ marginTop: "0.8rem" }}>
          <button className="gbtn" onClick={() => onSave(f)}>💾 Enregistrer</button>
          <button className="gbtn gbtn--ghost" onClick={onCancel}>Annuler</button>
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------------- Commun

function isAuthError(e) {
  const m = String(e.message);
  return m.includes("401") || m.includes("403") || m.toLowerCase().includes("jwt");
}

function Login({ onOk, onQuit }) {
  const [email, setEmail] = useState("admin@lotr.local");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      await api.login(email, password);
      onOk();
    } catch (err) {
      setError(err.message);
      setLoading(false);
    }
  }

  return (
    <div className="bo-login">
      <h1>🔧 Back-office — connexion</h1>
      <form onSubmit={submit}>
        <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email" />
        <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Mot de passe" />
        <div className="bo__actions">
          <button className="gbtn" disabled={loading}>{loading ? "…" : "Se connecter"}</button>
          <button type="button" className="gbtn gbtn--ghost" onClick={onQuit}>← Jeu</button>
        </div>
      </form>
      <p className="error">{error}</p>
      <p style={{ opacity: 0.6, fontSize: "0.8rem" }}>Compte démo : admin@lotr.local / admin1234</p>
    </div>
  );
}

function EditForm({ card, onCancel, onSave }) {
  const [f, setF] = useState(card);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  return (
    <div className="modal-overlay" onClick={onCancel}>
      <div className="modal bo-edit" onClick={(e) => e.stopPropagation()}>
        <div className="modal__head">
          <h3>{f.id ? `Éditer : ${card.name}` : "Nouvelle carte"}</h3>
        </div>
        <div className="bo-edit__grid">
          <label>Code<input value={f.code} onChange={set("code")} /></label>
          <label>Nom<input value={f.name} onChange={set("name")} /></label>
          <label>Type
            <select value={f.type} onChange={set("type")}>{TYPES.map((t) => <option key={t}>{t}</option>)}</select>
          </label>
          <label>Catégorie
            <select value={f.category} onChange={set("category")}>{CATEGORIES.map((c) => <option key={c}>{c}</option>)}</select>
          </label>
          <label>Coût (vide = ✱)<input type="number" value={f.cost} onChange={set("cost")} /></label>
          <label>PV (vide = aucun)<input type="number" value={f.pv} onChange={set("pv")} /></label>
          <label>Niveau<input type="number" value={f.level} onChange={set("level")} /></label>
          <label>Héros<input value={f.hero} onChange={set("hero")} /></label>
          <label>Quantité<input type="number" value={f.quantity} onChange={set("quantity")} /></label>
        </div>
        <label className="bo-edit__text">Texte
          <textarea rows={3} value={f.text} onChange={set("text")} />
        </label>
        <div className="bo__actions" style={{ marginTop: "0.8rem" }}>
          <button className="gbtn" onClick={() => onSave(f)}>💾 Enregistrer</button>
          <button className="gbtn gbtn--ghost" onClick={onCancel}>Annuler</button>
        </div>
      </div>
    </div>
  );
}
