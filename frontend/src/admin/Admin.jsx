import { useEffect, useState } from "react";
import { api, hasToken } from "../api";
import "./admin.css";

const CATEGORIES = ["main_deck", "starter", "hero_starting", "valor", "archenemy", "corruption"];
const TYPES = ["Ennemi", "Allié", "Lieu", "Artefact", "Manœuvre", "Chance", "Départ", "Corruption"];
const EMPTY = { code: "", name: "", type: "Allié", category: "main_deck", cost: "", pv: "", level: "", hero: "", quantity: 1, text: "" };

export default function Admin({ onQuit }) {
  const [authed, setAuthed] = useState(hasToken());
  const [cards, setCards] = useState([]);
  const [error, setError] = useState("");
  const [q, setQ] = useState("");
  const [cat, setCat] = useState("all");
  const [edit, setEdit] = useState(null); // carte en cours d'édition (ou null)

  useEffect(() => {
    if (authed) reload();
  }, [authed]);

  async function reload() {
    try {
      setCards(await api.adminCards());
    } catch (e) {
      setError(e.message);
      if (String(e.message).includes("401") || String(e.message).toLowerCase().includes("jwt")) setAuthed(false);
    }
  }

  if (!authed) return <Login onOk={() => setAuthed(true)} onQuit={onQuit} />;

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
    <div className="bo">
      <div className="bo__top">
        <h1>🔧 Back-office — cartes ({cards.length})</h1>
        <div className="bo__actions">
          <button className="gbtn" onClick={() => setEdit({ ...EMPTY })}>+ Nouvelle carte</button>
          <button className="gbtn gbtn--ghost" onClick={() => { api.logout(); setAuthed(false); }}>Déconnexion</button>
          <button className="gbtn gbtn--ghost" onClick={onQuit}>← Jeu</button>
        </div>
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
    </div>
  );
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
